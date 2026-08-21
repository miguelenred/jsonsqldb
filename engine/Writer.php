<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Ejecutor de escrituras y de DDL.
 *
 * Todo lo que se modifica se acumula en memoria y se vuelca a disco de una vez
 * al final: si algo falla a mitad (una restricción, un trigger con RAISE), no se
 * escribe nada y la base queda como estaba.
 *
 * Comprueba NOT NULL, tipos, clave primaria, UNIQUE y claves foráneas (con
 * ON DELETE / ON UPDATE), y ejecuta los triggers BEFORE y AFTER con NEW y OLD.
 */
final class Writer
{
    /** Anidamiento máximo de triggers, para cortar recursiones infinitas */
    private const MAX_ANIDAMIENTO = 8;

    private Catalog $cat;
    private array $datos      = [];   // tabla => filas en memoria
    private array $metas      = [];   // tabla => estructura en memoria
    private array $sucioDatos = [];
    private array $sucioMeta  = [];
    private array $astCache   = [];   // sql de trigger => árbol ya analizado
    private array $idxPadre   = [];   // índice de claves de las tablas padre
    private int   $anidamiento = 0;

    public function __construct(Catalog $cat)
    {
        $this->cat = $cat;
    }

    /**
     * Ejecuta una sentencia de escritura o de definición.
     * @return array ['filas' => int, 'mensaje' => string]
     */
    public function ejecutar(array $ast): array
    {
        // Las vistas son de solo lectura: no se escribe ni se altera sobre ellas
        // CREATE TABLE se comprueba aparte, con su propio mensaje
        $destino = $ast['k'] === 'create_table' ? null : ($ast['tabla'] ?? null);
        if (is_string($destino) && $this->cat->esVista($destino)) {
            $operacion = [
                'insert'      => 'INSERT', 'update' => 'UPDATE', 'delete' => 'DELETE',
                'alter_table' => 'ALTER TABLE', 'drop_table' => 'DROP TABLE',
            ][$ast['k']] ?? $ast['k'];
            $extra = $ast['k'] === 'drop_table' ? " Usa DROP VIEW \"$destino\"." : '';
            throw JsonSqlDbError::schema(
                "'$destino' es una vista, no una tabla: no admite $operacion.$extra"
            );
        }

        // Las operaciones de estructura tocan varios ficheros: van con journal,
        // para que un corte a mitad no deje la base a medias.
        $tablasTx = self::tablasDelDdl($ast);
        if ($tablasTx !== []) {
            $st = $this->cat->storage();
            $st->txIniciar(Database::operacion($ast), $tablasTx);
            try {
                $r = $this->despachar($ast);
            } catch (\Throwable $e) {
                throw $e;                 // el journal se deshará al abrir la base
            }
            $st->txConfirmar();
            return $r;
        }
        return $this->despachar($ast);
    }

    /**
     * Tablas que toca una operación de estructura, o [] si no lo es.
     *
     * @return string[]
     */
    private static function tablasDelDdl(array $ast): array
    {
        $tabla = (string)($ast['tabla'] ?? '');
        if ($tabla === '') {
            return [];
        }
        switch ($ast['k']) {
            case 'create_table':
            case 'drop_table':
                return [$tabla];
            case 'alter_table':
                // El renombrado escribe con el nombre nuevo: hay que copiar los dos
                return $ast['accion'] === 'rename' ? [$tabla, (string)$ast['nuevo']] : [$tabla];
        }
        return [];
    }

    private function despachar(array $ast): array
    {
        switch ($ast['k']) {
            case 'insert':
                $n = $this->insertar($ast);
                $this->volcar();
                return ['filas' => $n, 'mensaje' => "$n fila(s) insertada(s)"];

            case 'update':
                $n = $this->actualizar($ast);
                $this->volcar();
                return ['filas' => $n, 'mensaje' => "$n fila(s) actualizada(s)"];

            case 'delete':
                $n = $this->borrar($ast);
                $this->volcar();
                return ['filas' => $n, 'mensaje' => "$n fila(s) eliminada(s)"];

            case 'create_table':
                return $this->crearTabla($ast);
            case 'drop_table':
                return $this->borrarTabla($ast);
            case 'alter_table':
                return $this->alterarTabla($ast);
            case 'create_trigger':
                return $this->crearTrigger($ast);
            case 'drop_trigger':
                return $this->borrarTrigger($ast);

            case 'repair_keys':
                $problemas = (new Integrity($this->cat))->claves($ast['tabla'], true);
                $arreglados = 0;
                foreach ($problemas as $p) {
                    if ($p['accion'] === 'puesta a NULL') { $arreglados++; }
                }
                $quedan = count($problemas) - $arreglados;
                return ['filas' => $arreglados, 'mensaje' =>
                    $problemas === []
                        ? 'Las claves foráneas están bien: no hay filas huérfanas'
                        : "$arreglados fila(s) corregida(s)" .
                          ($quedan > 0 ? ", $quedan sin corregir (la columna no admite NULL)" : '')];

            case 'create_view':
                $creada = $this->cat->crearVista($ast['nombre'], $ast['sql'], $ast['si_no_existe']);
                return ['filas' => 0, 'mensaje' => $creada
                    ? "Vista '{$ast['nombre']}' creada"
                    : "La vista '{$ast['nombre']}' ya existía"];

            case 'drop_view':
                $borrada = $this->cat->borrarVista($ast['nombre'], $ast['si_existe']);
                return ['filas' => 0, 'mensaje' => $borrada
                    ? "Vista '{$ast['nombre']}' borrada"
                    : "La vista '{$ast['nombre']}' no existía"];
        }
        throw JsonSqlDbError::syntax("Sentencia no ejecutable: {$ast['k']}");
    }

    // ==================================================================
    // Estado en memoria
    // ==================================================================

    public function filas(string $tabla): array
    {
        return $this->datos[$tabla] ??= $this->cat->storage()->leerFilas($tabla);
    }

    private function ponerFilas(string $tabla, array $filas): void
    {
        $this->datos[$tabla]      = $filas;
        $this->sucioDatos[$tabla] = true;
        foreach (array_keys($this->idxPadre) as $clave) {
            if (strncmp($clave, $tabla . '|', strlen($tabla) + 1) === 0) {
                unset($this->idxPadre[$clave]);
            }
        }
    }

    /** Posición actual de una fila concreta dentro de la tabla. */
    private static function posicionDe(array $filas, array $fila): ?int
    {
        foreach ($filas as $pos => $f) {
            if ($f === $fila) {
                return $pos;
            }
        }
        return null;
    }

    private function meta(string $tabla): array
    {
        if (!isset($this->metas[$tabla])) {
            if (!$this->cat->existe($tabla)) {
                throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
            }
            $this->metas[$tabla] = $this->cat->meta($tabla);
        }
        return $this->metas[$tabla];
    }

    private function ponerMeta(string $tabla, array $meta): void
    {
        $this->metas[$tabla]     = $meta;
        $this->sucioMeta[$tabla] = true;
    }

    /**
     * Vuelca a disco todo lo modificado.
     *
     * Cuando la escritura toca más de una tabla —un DELETE con ON DELETE
     * CASCADE, un trigger que escribe en otra tabla— cada fichero se guarda de
     * forma atómica, pero el conjunto no: un corte entre dos deja el cambio a
     * medias. En ese caso se abre el journal, igual que en las operaciones de
     * estructura.
     *
     * Con una sola tabla no se journaliza: sería copiar el fichero de datos
     * entero en cada INSERT, y el coste no compensa. Ahí basta con el rename
     * atómico de cada escritura.
     */
    private function volcar(): void
    {
        $st     = $this->cat->storage();
        $tablas = array_keys($this->sucioDatos + $this->sucioMeta);

        // txAbierta(): si venimos de una operación de estructura, el journal ya
        // está abierto y no hay que anidar otro
        $conJournal = count($tablas) > 1 && Config::journalDatos() && !$st->txAbierta();
        if ($conJournal) {
            $st->txIniciar('ESCRITURA', $tablas);
        }

        foreach (array_keys($this->sucioDatos) as $tabla) {
            $st->guardarFilas($tabla, $this->datos[$tabla]);
        }
        foreach (array_keys($this->sucioMeta) as $tabla) {
            $st->guardarMeta($tabla, Catalog::compactar($this->metas[$tabla]));
        }

        if ($conJournal) {
            $st->txConfirmar();
        }

        $this->sucioDatos = [];
        $this->sucioMeta  = [];
        $this->cat->olvidar();
    }

    // ==================================================================
    // INSERT
    // ==================================================================

    private function insertar(array $ast): int
    {
        $tabla = $ast['tabla'];
        $meta  = $this->meta($tabla);

        $nombres = [];
        foreach ($meta['columns'] as $c) {
            $nombres[] = $c['name'];
        }
        $cols = $ast['cols'] ?? $nombres;

        foreach ($cols as $i => $c) {
            $col = Catalog::columna($meta, $c);
            if ($col === null) {
                throw JsonSqlDbError::schema("La columna '$c' no existe en '$tabla'");
            }
            $cols[$i] = $col['name'];
        }

        // Valores de origen: VALUES(...) o SELECT
        $origen = [];
        if ($ast['select'] !== null) {
            foreach ($this->seleccionar($ast['select']) as $fila) {
                $origen[] = array_values($fila);
            }
        } else {
            $ctx = ['fila' => [], 'sub' => fn(array $s, int $sid): array => $this->seleccionar($s)];
            foreach ($ast['filas'] as $fila) {
                $valores = [];
                foreach ($fila as $e) {
                    $valores[] = $e['k'] === 'default'
                        ? ['__default__']
                        : Evaluator::evaluar(Evaluator::resolver($e, []), $ctx);
                }
                $origen[] = $valores;
            }
        }

        $filas   = $this->filas($tabla);
        $indices = $this->indicesUnicos($meta, $filas);
        $puestas = 0;

        foreach ($origen as $valores) {
            if (count($valores) !== count($cols)) {
                throw JsonSqlDbError::constraint(
                    'El número de valores (' . count($valores) . ') no coincide con el de columnas (' . count($cols) . ')'
                );
            }

            $nueva = [];
            foreach ($meta['columns'] as $c) {
                $nueva[$c['name']] = $c['default'];
            }
            foreach ($cols as $i => $c) {
                if (!(is_array($valores[$i]) && ($valores[$i][0] ?? null) === '__default__')) {
                    $nueva[$c] = $valores[$i];
                }
            }

            $nueva = $this->prepararFila($tabla, $meta, $nueva, true);

            $this->lanzarTriggers($tabla, 'BEFORE', 'INSERT', $nueva, null);
            $this->comprobarUnicos($tabla, $meta, $nueva, $indices, null);
            $this->comprobarForaneas($tabla, $meta, $nueva);

            $filas[] = $nueva;
            $this->anadirAIndices($meta, $nueva, $indices);
            $this->ponerFilas($tabla, $filas);
            $puestas++;

            $this->lanzarTriggers($tabla, 'AFTER', 'INSERT', $nueva, null);
            $filas = $this->filas($tabla);          // un trigger puede haber tocado la tabla
        }


        return $puestas;
    }

    // ==================================================================
    // UPDATE
    // ==================================================================

    private function actualizar(array $ast): int
    {
        $tabla = $ast['tabla'];
        $meta  = $this->meta($tabla);
        $mapa  = $this->mapaColumnas($tabla, $meta);

        $where = $ast['where'] === null ? null : Evaluator::resolver($ast['where'], $mapa);
        $sets  = [];
        foreach ($ast['set'] as $s) {
            $col = Catalog::columna($meta, $s['col']);
            if ($col === null) {
                throw JsonSqlDbError::schema("La columna '{$s['col']}' no existe en '$tabla'");
            }
            $sets[] = ['col' => $col, 'expr' => $s['expr']['k'] === 'default'
                ? null
                : Evaluator::resolver($s['expr'], $mapa)];
        }

        $filas   = $this->filas($tabla);
        $indices = $this->indicesUnicos($meta, $filas);
        $sub     = fn(array $s, int $sid): array => $this->seleccionar($s);
        $tocadas = 0;

        foreach ($filas as $vieja) {
            $ctx = ['fila' => $vieja, 'sub' => $sub];
            if ($where !== null && Valor::verdadero(Evaluator::evaluar($where, $ctx)) !== true) {
                continue;
            }

            $nueva = $vieja;
            foreach ($sets as $s) {
                $nueva[$s['col']['name']] = $s['expr'] === null
                    ? $s['col']['default']
                    : Evaluator::evaluar($s['expr'], $ctx);
            }
            $nueva = $this->prepararFila($tabla, $meta, $nueva, false);

            $this->lanzarTriggers($tabla, 'BEFORE', 'UPDATE', $nueva, $vieja);
            $this->comprobarUnicos($tabla, $meta, $nueva, $indices, $vieja);
            $this->comprobarForaneas($tabla, $meta, $nueva);
            $this->propagarHijos($tabla, $meta, $vieja, $nueva);

            $actuales = $this->filas($tabla);
            $pos      = self::posicionDe($actuales, $vieja);
            if ($pos === null) {
                continue;                        // un trigger ya la había borrado
            }
            $actuales[$pos] = $nueva;
            $this->quitarDeIndices($meta, $vieja, $indices);
            $this->anadirAIndices($meta, $nueva, $indices);
            $this->ponerFilas($tabla, $actuales);
            $tocadas++;

            $this->lanzarTriggers($tabla, 'AFTER', 'UPDATE', $nueva, $vieja);
        }

        return $tocadas;
    }

    // ==================================================================
    // DELETE
    // ==================================================================

    private function borrar(array $ast): int
    {
        $tabla = $ast['tabla'];
        $meta  = $this->meta($tabla);
        $mapa  = $this->mapaColumnas($tabla, $meta);
        $where = $ast['where'] === null ? null : Evaluator::resolver($ast['where'], $mapa);
        $sub   = fn(array $s, int $sid): array => $this->seleccionar($s);

        $objetivo = [];
        foreach ($this->filas($tabla) as $fila) {
            if ($where === null
                || Valor::verdadero(Evaluator::evaluar($where, ['fila' => $fila, 'sub' => $sub])) === true) {
                $objetivo[] = $fila;
            }
        }

        $quitadas = 0;
        foreach ($objetivo as $vieja) {
            $this->lanzarTriggers($tabla, 'BEFORE', 'DELETE', null, $vieja);
            $this->propagarHijos($tabla, $meta, $vieja, null);

            $actuales = $this->filas($tabla);
            $pos      = self::posicionDe($actuales, $vieja);
            if ($pos === null) {
                continue;                        // ya la había borrado una cascada o un trigger
            }
            unset($actuales[$pos]);
            $this->ponerFilas($tabla, array_values($actuales));
            $quitadas++;

            $this->lanzarTriggers($tabla, 'AFTER', 'DELETE', null, $vieja);
        }

        return $quitadas;
    }

    // ==================================================================
    // Validación de filas
    // ==================================================================

    /** Aplica autoincremento, convierte tipos y comprueba NOT NULL. */
    private function prepararFila(string $tabla, array $meta, array $fila, bool $esInsert): array
    {
        $auto = Catalog::columnaAutoincremento($meta);

        foreach ($meta['columns'] as $c) {
            $nombre = $c['name'];
            $valor  = $fila[$nombre] ?? null;

            if ($valor === null && $esInsert && $auto !== null && $nombre === $auto) {
                $valor = $this->siguienteAutoincremento($tabla);
            }
            if ($valor !== null) {
                $valor = Types::cast($valor, $c);
                if ($auto !== null && $nombre === $auto && $esInsert) {
                    $this->ajustarAutoincremento($tabla, (int)$valor);
                }
            }
            if ($valor === null && $c['notnull']) {
                throw JsonSqlDbError::constraint("La columna '$tabla.$nombre' no admite NULL");
            }
            $fila[$nombre] = $valor;
        }

        // Descartar claves que no son columnas de la tabla
        $limpia = [];
        foreach ($meta['columns'] as $c) {
            $limpia[$c['name']] = $fila[$c['name']];
        }
        return $limpia;
    }

    private function siguienteAutoincremento(string $tabla): int
    {
        $meta = $this->meta($tabla);
        $n    = (int)$meta['autoincrement']['next'];
        $meta['autoincrement']['next'] = $n + 1;
        $this->ponerMeta($tabla, $meta);
        return $n;
    }

    private function ajustarAutoincremento(string $tabla, int $valor): void
    {
        $meta = $this->meta($tabla);
        if ($meta['autoincrement'] === null || $valor < (int)$meta['autoincrement']['next']) {
            return;
        }
        $meta['autoincrement']['next'] = $valor + 1;
        $this->ponerMeta($tabla, $meta);
    }

    /** @return array<string,array<string,bool>> conjunto único => claves ocupadas */
    private function indicesUnicos(array $meta, array $filas): array
    {
        $indices = [];
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $mapa = [];
            foreach ($filas as $fila) {
                $clave = self::claveDe($fila, $uq['columns']);
                if ($clave !== null) {
                    $mapa[$clave] = true;
                }
            }
            $indices[$uq['name']] = $mapa;
        }
        return $indices;
    }

    private function comprobarUnicos(string $tabla, array $meta, array $nueva, array &$indices, ?array $vieja): void
    {
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $clave = self::claveDe($nueva, $uq['columns']);
            if ($clave === null) {
                continue;                       // con algún NULL no se aplica la unicidad
            }
            if ($vieja !== null && self::claveDe($vieja, $uq['columns']) === $clave) {
                continue;                       // no ha cambiado
            }
            if (isset($indices[$uq['name']][$clave])) {
                $cols = implode(', ', $uq['columns']);
                $etiqueta = $uq['name'] === 'PRIMARY' ? 'clave primaria' : "restricción UNIQUE '{$uq['name']}'";
                throw JsonSqlDbError::constraint("Valor duplicado en $etiqueta de '$tabla' ($cols)");
            }
        }
    }

    private function anadirAIndices(array $meta, array $fila, array &$indices): void
    {
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $clave = self::claveDe($fila, $uq['columns']);
            if ($clave !== null) {
                $indices[$uq['name']][$clave] = true;
            }
        }
    }

    private function quitarDeIndices(array $meta, array $fila, array &$indices): void
    {
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $clave = self::claveDe($fila, $uq['columns']);
            if ($clave !== null) {
                unset($indices[$uq['name']][$clave]);
            }
        }
    }

    /** Clave compuesta de una fila; null si alguna columna es NULL. */
    private static function claveDe(array $fila, array $cols): ?string
    {
        $k = '';
        foreach ($cols as $c) {
            $v = $fila[$c] ?? null;
            if ($v === null) {
                return null;
            }
            $k .= Valor::clave($v) . "\0";
        }
        return $k;
    }

    // ==================================================================
    // Claves foráneas
    // ==================================================================

    /** Lado hijo: el valor insertado o actualizado debe existir en la tabla padre. */
    private function comprobarForaneas(string $tabla, array $meta, array $fila): void
    {
        foreach ($meta['foreign_keys'] as $fk) {
            $clave = self::claveDe($fila, $fk['columns']);
            if ($clave === null) {
                continue;                       // con NULL no se comprueba
            }
            $idx = $this->indicePadre($fk['table'], $fk['references']);
            if (!isset($idx[$clave])) {
                $valores = [];
                foreach ($fk['columns'] as $c) {
                    $valores[] = Valor::aTexto($fila[$c]);
                }
                throw JsonSqlDbError::constraint(
                    "'$tabla." . implode(', ', $fk['columns']) . "' = (" . implode(', ', $valores) .
                    ") no existe en '{$fk['table']}' (clave foránea '{$fk['name']}')"
                );
            }
        }
    }

    /** Claves existentes en una tabla padre, para no recorrerla en cada comprobación. */
    private function indicePadre(string $tabla, array $cols): array
    {
        $clave = $tabla . '|' . implode(',', $cols);
        if (isset($this->idxPadre[$clave])) {
            return $this->idxPadre[$clave];
        }
        $idx = [];
        foreach ($this->filas($tabla) as $fila) {
            $k = self::claveDe($fila, $cols);
            if ($k !== null) {
                $idx[$k] = true;
            }
        }
        return $this->idxPadre[$clave] = $idx;
    }

    /**
     * Lado padre: aplica ON DELETE / ON UPDATE a las filas hijas.
     * $nueva a null significa que la fila padre se está borrando.
     */
    private function propagarHijos(string $tabla, array $meta, array $vieja, ?array $nueva): void
    {
        foreach ($this->cat->tablas() as $hija) {
            $metaHija = $this->meta($hija);
            foreach ($metaHija['foreign_keys'] as $fk) {
                if (strcasecmp($fk['table'], $tabla) !== 0) {
                    continue;
                }
                $claveVieja = self::claveDe($vieja, $fk['references']);
                if ($claveVieja === null) {
                    continue;
                }
                if ($nueva !== null && self::claveDe($nueva, $fk['references']) === $claveVieja) {
                    continue;                   // la clave referenciada no cambia
                }

                $accion = $nueva === null ? $fk['on_delete'] : $fk['on_update'];
                $hijas  = [];
                foreach ($this->filas($hija) as $pos => $f) {
                    if (self::claveDe($f, $fk['columns']) === $claveVieja) {
                        $hijas[$pos] = $f;
                    }
                }
                if ($hijas === []) {
                    continue;
                }

                if ($accion === 'NO ACTION' || $accion === 'RESTRICT') {
                    throw JsonSqlDbError::constraint(
                        "'$hija' tiene " . count($hijas) . " fila(s) que dependen de esta (clave foránea '{$fk['name']}')"
                    );
                }

                if ($accion === 'CASCADE' && $nueva === null) {
                    $this->borrarHijas($hija, array_values($hijas));
                    continue;
                }

                $metaHijaActual = $this->meta($hija);
                $filasHija      = $this->filas($hija);
                foreach ($hijas as $pos => $f) {
                    foreach ($fk['columns'] as $i => $c) {
                        if ($accion === 'CASCADE') {
                            $f[$c] = $nueva[$fk['references'][$i]];
                        } elseif ($accion === 'SET NULL') {
                            $f[$c] = null;
                        } else {                 // SET DEFAULT
                            $col   = Catalog::columna($metaHijaActual, $c);
                            $f[$c] = $col['default'];
                        }
                    }
                    $filasHija[$pos] = $this->prepararFila($hija, $metaHijaActual, $f, false);
                }
                $this->ponerFilas($hija, array_values($filasHija));
            }
        }
    }

    /** Borra filas hijas por posición, disparando sus propios triggers y cascadas. */
    private function borrarHijas(string $tabla, array $hijas): void
    {
        $meta = $this->meta($tabla);
        foreach ($hijas as $fila) {
            $this->lanzarTriggers($tabla, 'BEFORE', 'DELETE', null, $fila);
            $this->propagarHijos($tabla, $meta, $fila, null);

            $actuales = $this->filas($tabla);
            $pos      = self::posicionDe($actuales, $fila);
            if ($pos === null) {
                continue;
            }
            unset($actuales[$pos]);
            $this->ponerFilas($tabla, array_values($actuales));

            $this->lanzarTriggers($tabla, 'AFTER', 'DELETE', null, $fila);
        }
    }

    // ==================================================================
    // Triggers
    // ==================================================================

    private function lanzarTriggers(string $tabla, string $timing, string $evento, ?array $new, ?array $old): void
    {
        $meta = $this->meta($tabla);
        if ($meta['triggers'] === []) {
            return;
        }
        if ($this->anidamiento >= self::MAX_ANIDAMIENTO) {
            throw JsonSqlDbError::constraint('Los triggers se están llamando en cadena demasiadas veces');
        }

        foreach ($meta['triggers'] as $trg) {
            if ($trg['timing'] !== $timing || $trg['event'] !== $evento) {
                continue;
            }

            $this->anidamiento++;
            try {
                if ($trg['when'] !== null) {
                    $cond = $this->sustituir($this->analizarExpr($trg['when']), $new, $old);
                    $ctx  = ['fila' => [], 'sub' => fn(array $s, int $sid): array => $this->seleccionar($s)];
                    if (Valor::verdadero(Evaluator::evaluar(Evaluator::resolver($cond, []), $ctx)) !== true) {
                        continue;
                    }
                }
                foreach ($trg['body'] as $sql) {
                    $ast = $this->sustituir($this->analizar($sql), $new, $old);
                    if ($ast['k'] === 'select') {
                        $this->seleccionar($ast);
                    } else {
                        $this->ejecutarSinVolcar($ast, $trg['name']);
                    }
                }
            } finally {
                $this->anidamiento--;
            }
        }
    }

    private function ejecutarSinVolcar(array $ast, string $trigger): void
    {
        switch ($ast['k']) {
            case 'insert': $this->insertar($ast);   return;
            case 'update': $this->actualizar($ast); return;
            case 'delete': $this->borrar($ast);     return;
        }
        throw JsonSqlDbError::syntax("El trigger '$trigger' solo puede hacer INSERT, UPDATE, DELETE o SELECT");
    }

    /** Sustituye NEW.x y OLD.x por sus valores antes de ejecutar la sentencia. */
    private function sustituir(array $n, ?array $new, ?array $old)
    {
        if (isset($n['k']) && $n['k'] === 'col' && $n['tabla'] !== null) {
            $cual = strtoupper($n['tabla']);
            if ($cual === 'NEW' || $cual === 'OLD') {
                $fila = $cual === 'NEW' ? $new : $old;
                if ($fila === null) {
                    throw JsonSqlDbError::syntax("$cual no está disponible en este trigger");
                }
                if (!array_key_exists($n['nombre'], $fila)) {
                    throw JsonSqlDbError::schema("$cual.{$n['nombre']} no es una columna de la tabla");
                }
                return ['k' => 'lit', 'v' => $fila[$n['nombre']]];
            }
        }
        foreach ($n as $clave => $valor) {
            if (is_array($valor)) {
                $n[$clave] = $this->sustituir($valor, $new, $old);
            }
        }
        return $n;
    }

    private function analizar(string $sql): array
    {
        return $this->astCache[$sql] ??= Parser::analizar($sql);
    }

    /** Analiza una expresión suelta (la condición WHEN de un trigger). */
    private function analizarExpr(string $sql): array
    {
        $ast = $this->analizar('SELECT ' . $sql);
        return $ast['cols'][0]['expr'];
    }

    // ==================================================================
    // DDL
    // ==================================================================

    private function crearTabla(array $ast): array
    {
        $tabla = $ast['tabla'];
        if ($this->cat->existe($tabla)) {
            if ($ast['si_no_existe']) {
                return ['filas' => 0, 'mensaje' => "La tabla '$tabla' ya existía"];
            }
            throw JsonSqlDbError::schema("La tabla '$tabla' ya existe");
        }
        $this->cat->exigirNombreLibreDeVista($tabla);

        $def = $ast['def'];

        // PRIMARY KEY declarada a nivel de tabla
        foreach ($def['pk'] ?? [] as $nombre) {
            $encontrada = false;
            foreach ($def['columns'] as $i => $c) {
                if (strcasecmp($c['name'], $nombre) === 0) {
                    $def['columns'][$i]['pk'] = true;
                    $encontrada = true;
                }
            }
            if (!$encontrada) {
                throw JsonSqlDbError::schema("PRIMARY KEY sobre columna inexistente '$nombre'");
            }
        }
        unset($def['pk']);

        // REFERENCES escrito dentro de una columna
        foreach ($def['columns'] as $i => $c) {
            if (isset($c['references'])) {
                $def['foreign_keys'][] = $c['references'];
                unset($def['columns'][$i]['references']);
            }
        }
        $def['columns'] = array_values($def['columns']);

        $this->cat->crearTabla($tabla, $def);
        return ['filas' => 0, 'mensaje' => "Tabla '$tabla' creada"];
    }

    private function borrarTabla(array $ast): array
    {
        $tabla = $ast['tabla'];
        if (!$this->cat->existe($tabla)) {
            if ($ast['si_existe']) {
                return ['filas' => 0, 'mensaje' => "La tabla '$tabla' no existía"];
            }
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        $this->cat->borrarTabla($tabla);
        unset($this->datos[$tabla], $this->metas[$tabla]);
        return ['filas' => 0, 'mensaje' => "Tabla '$tabla' eliminada"];
    }

    private function alterarTabla(array $ast): array
    {
        $tabla = $ast['tabla'];
        switch ($ast['accion']) {
            case 'add':
                $this->cat->anadirColumna($tabla, $ast['def']);
                $mensaje = "Columna '{$ast['def']['name']}' añadida a '$tabla'";
                break;
            case 'drop':
                $this->cat->borrarColumna($tabla, $ast['col']);
                $mensaje = "Columna '{$ast['col']}' eliminada de '$tabla'";
                break;
            case 'rename':
                $this->cat->renombrarTabla($tabla, $ast['nuevo']);
                $mensaje = "Tabla '$tabla' renombrada a '{$ast['nuevo']}'";
                break;
            case 'add_constraint':
                $nombres = [];
                foreach ($ast['unique'] as $uq) {
                    $nombres[] = $this->cat->anadirUnico($tabla, $uq);
                }
                foreach ($ast['foreign_keys'] as $fk) {
                    $nombres[] = $this->cat->anadirFk($tabla, $fk);
                }
                $mensaje = "Restricción '" . implode("', '", $nombres) . "' añadida a '$tabla'";
                break;
            case 'modify':
                $this->cat->modificarColumna($tabla, $ast['def']);
                $mensaje = "Columna '{$ast['def']['name']}' modificada en '$tabla'";
                break;
            case 'add_pk':
                $this->cat->anadirClavePrimaria($tabla, $ast['columnas']);
                $mensaje = "Clave primaria de '$tabla' creada sobre (" . implode(', ', $ast['columnas']) . ')';
                break;
            case 'drop_pk':
                $this->cat->borrarClavePrimaria($tabla);
                $mensaje = "Clave primaria de '$tabla' eliminada";
                break;
            case 'drop_constraint':
                $this->cat->borrarRestriccion($tabla, $ast['nombre']);
                $mensaje = "Restricción '{$ast['nombre']}' eliminada de '$tabla'";
                break;
            default:
                $this->cat->renombrarColumna($tabla, $ast['col'], $ast['nuevo']);
                $mensaje = "Columna '{$ast['col']}' renombrada a '{$ast['nuevo']}'";
        }
        unset($this->datos[$tabla], $this->metas[$tabla]);
        return ['filas' => 0, 'mensaje' => $mensaje];
    }

    private function crearTrigger(array $ast): array
    {
        $nombre = $ast['trg']['name'];
        foreach ($this->cat->tablas() as $t) {
            foreach ($this->cat->meta($t)['triggers'] as $trg) {
                if (strcasecmp($trg['name'], $nombre) === 0) {
                    if ($ast['si_no_existe']) {
                        return ['filas' => 0, 'mensaje' => "El trigger '$nombre' ya existía"];
                    }
                    throw JsonSqlDbError::schema("El trigger '$nombre' ya existe");
                }
            }
        }

        // Comprobar que el cuerpo es analizable antes de guardarlo
        foreach ($ast['trg']['body'] as $sql) {
            Parser::analizar($sql);
        }
        $this->cat->crearTrigger($ast['tabla'], $ast['trg']);
        unset($this->metas[$ast['tabla']]);
        return ['filas' => 0, 'mensaje' => "Trigger '$nombre' creado"];
    }

    private function borrarTrigger(array $ast): array
    {
        $nombre = $ast['nombre'];
        try {
            $tabla = $this->cat->borrarTrigger($nombre);
        } catch (JsonSqlDbError $e) {
            if ($ast['si_existe'] && $e->sqlState === 'SCHEMA') {
                return ['filas' => 0, 'mensaje' => "El trigger '$nombre' no existía"];
            }
            throw $e;
        }
        unset($this->metas[$tabla]);
        return ['filas' => 0, 'mensaje' => "Trigger '$nombre' eliminado"];
    }

    // ==================================================================
    // Auxiliares
    // ==================================================================

    /** Las consultas internas ven también los cambios todavía en memoria. */
    private function seleccionar(array $ast): array
    {
        return (new Select($this->cat, fn(string $t): array => $this->filas($t)))->ejecutar($ast);
    }

    /** 'col' => 'col' y 'tabla.col' => 'col' */
    private function mapaColumnas(string $tabla, array $meta): array
    {
        $mapa = [];
        foreach ($meta['columns'] as $c) {
            $mapa[strtolower($c['name'])] = $c['name'];
            $mapa[strtolower($tabla . '.' . $c['name'])] = $c['name'];
        }
        return $mapa;
    }
}
