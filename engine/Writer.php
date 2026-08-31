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
        // para que un corte a mitad no deje la base a medias. Llevan siempre el
        // bloqueo exclusivo de la base, así que el ámbito del journal es la base.
        $tablasTx = $this->tablasDelDdl($ast);
        if ($tablasTx !== []) {
            $st = $this->cat->storage();
            $st->txIniciar(Database::operacion($ast), $tablasTx, null);
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
    private function tablasDelDdl(array $ast): array
    {
        if ($ast['k'] === 'drop_index') {
            // El nombre de la tabla puede no venir en la sentencia: se busca
            // ahora, que ya se tiene el bloqueo y lo leído no puede cambiar
            $tabla = $this->tablaDelIndice($ast);
            return $tabla === null ? [] : [$tabla];
        }

        $tabla = (string)($ast['tabla'] ?? '');
        if ($tabla === '') {
            return [];
        }
        switch ($ast['k']) {
            case 'create_table':
            case 'drop_table':
            case 'create_index':
                return [$tabla];
            case 'alter_table':
                // El renombrado escribe con el nombre nuevo: hay que copiar los dos
                return $ast['accion'] === 'rename' ? [$tabla, (string)$ast['nuevo']] : [$tabla];
        }
        return [];
    }

    /** Tabla dueña del índice de un DROP INDEX, o null si no se encuentra. */
    private function tablaDelIndice(array $ast): ?string
    {
        $nombre = (string)$ast['nombre'];
        $donde  = $ast['tabla'] === null ? $this->cat->tablas() : [(string)$ast['tabla']];
        foreach ($donde as $t) {
            if (!$this->cat->existe($t)) {
                continue;
            }
            foreach ($this->cat->meta($t)['indexes'] as $idx) {
                if (strcasecmp((string)$idx['name'], $nombre) === 0) {
                    return $t;
                }
            }
        }
        return null;
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

            case 'create_index':
                $creado = $this->cat->crearIndice(
                    $ast['tabla'], $ast['nombre'], $ast['columnas'], $ast['si_no_existe']
                );
                return ['filas' => 0, 'mensaje' => $creado
                    ? "Índice '{$ast['nombre']}' creado sobre '{$ast['tabla']}'"
                    : "El índice '{$ast['nombre']}' ya existía"];

            case 'drop_index':
                $tabla = $this->cat->borrarIndice($ast['tabla'], $ast['nombre'], $ast['si_existe']);
                return ['filas' => 0, 'mensaje' => $tabla !== null
                    ? "Índice '{$ast['nombre']}' borrado de '$tabla'"
                    : "El índice '{$ast['nombre']}' no existía"];

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

    /**
     * Qué posiciones cambió cada tabla, para no reescribirla entera al guardar.
     *
     *   desde[t]   a partir de esa posición las filas se han desplazado
     *   sueltas[t] posiciones que cambiaron sin mover a las demás
     *   sabe[t]    si se puede afirmar lo anterior; si no, se reescribe todo
     *
     * @var array<string,int>            $desde
     * @var array<string,array<int,true>> $sueltas
     * @var array<string,bool>           $sabe
     */
    private array $desde   = [];
    private array $sueltas = [];
    private array $sabe    = [];

    /** Cambió a partir de $pos, desplazando lo que venga detrás. */
    private function marcarDesde(string $tabla, int $pos): void
    {
        $this->desde[$tabla] = min($this->desde[$tabla] ?? PHP_INT_MAX, max(0, $pos));
        $this->sabe[$tabla]  = $this->sabe[$tabla] ?? true;
    }

    /** Cambió UNA fila y las demás siguen donde estaban. */
    private function marcarSuelta(string $tabla, int $pos): void
    {
        $this->sueltas[$tabla][$pos] = true;
        $this->sabe[$tabla] = $this->sabe[$tabla] ?? true;
    }

    /** No se sabe qué cambió: al guardar se reescribe la tabla entera. */
    private function marcarTodo(string $tabla): void
    {
        $this->sabe[$tabla] = false;
    }

    /**
     * @param int|null $desde posición desde la que se desplazan las filas, o
     *                        null si quien llama no lo sabe (se reescribe todo)
     */
    private function ponerFilas(string $tabla, array $filas, ?int $desde = null): void
    {
        $this->datos[$tabla]      = $filas;
        $this->sucioDatos[$tabla] = true;
        if ($desde === null) {
            $this->marcarTodo($tabla);
        } else {
            $this->marcarDesde($tabla, $desde);
        }
        foreach (array_keys($this->idxPadre) as $clave) {
            if (strncmp($clave, $tabla . '|', strlen($tabla) + 1) === 0) {
                unset($this->idxPadre[$clave]);
            }
        }
    }

    /**
     * Escribe una fila en su sitio sin copiar la tabla entera.
     *
     * El patrón de antes era: sacar el array con filas(), tocar una posición y
     * devolverlo con ponerFilas(). Con dos referencias vivas al mismo array,
     * PHP separa la copia en cuanto se escribe, así que cada fila afectada
     * copiaba la tabla completa. Aquí se escribe directamente en $this->datos,
     * sin variable intermedia que lo referencie.
     */
    private function ponerFilaEn(string $tabla, int $pos, array $fila): void
    {
        $this->datos[$tabla][$pos] = $fila;
        $this->sucioDatos[$tabla]  = true;
        $this->marcarSuelta($tabla, $pos);        // no mueve a las demás
        foreach (array_keys($this->idxPadre) as $clave) {
            if (strncmp($clave, $tabla . '|', strlen($tabla) + 1) === 0) {
                unset($this->idxPadre[$clave]);
            }
        }
    }

    /**
     * Quita una fila sin copiar la tabla, y sin recolocar las demás.
     *
     * Compactar con array_values() en cada borrado movía todas las filas
     * siguientes, así que la posición dejaba de valer y había que buscarla otra
     * vez. Dejando el hueco, las posiciones siguen siendo buenas; se compacta
     * una sola vez al terminar, y guardarTabla() lo haría igualmente al escribir.
     */
    private function quitarFilaEn(string $tabla, int $pos): void
    {
        unset($this->datos[$tabla][$pos]);
        $this->sucioDatos[$tabla] = true;
        $this->marcarDesde($tabla, $pos);         // al compactar se mueve lo de detrás
        foreach (array_keys($this->idxPadre) as $clave) {
            if (strncmp($clave, $tabla . '|', strlen($tabla) + 1) === 0) {
                unset($this->idxPadre[$clave]);
            }
        }
    }

    /** Cierra los huecos que hayan dejado los borrados. */
    private function compactar(string $tabla): void
    {
        if (isset($this->datos[$tabla])) {
            $this->datos[$tabla] = array_values($this->datos[$tabla]);
        }
    }

    /**
     * Dónde está ahora una fila, sabiendo dónde estaba.
     *
     * Casi siempre sigue en su sitio, y comprobarlo cuesta un isset. Solo si un
     * trigger ha movido cosas hace falta el recorrido de antes, que es O(n) y
     * era lo que volvía cuadrático un UPDATE masivo.
     */
    private static function posicionEn(array $filas, array $fila, int $antes): ?int
    {
        if (isset($filas[$antes]) && $filas[$antes] === $fila) {
            return $antes;
        }
        return self::posicionDe($filas, $fila);
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
     * Cada fichero se guarda de forma atómica, pero el conjunto no: un corte
     * entre dos deja el cambio a medias. Y una escritura casi nunca toca un solo
     * fichero, ni siquiera cuando toca una sola tabla:
     *
     *   - una tabla de más de JSONSQLDB_FILAS_POR_PARTE filas vive repartida en
     *     varias partes, y todas se reescriben;
     *   - una tabla con índices reescribe además el fichero de cada uno;
     *   - un INSERT en una tabla con AUTOINCREMENT reescribe también el fichero
     *     de estructura, para guardar el siguiente valor;
     *   - un DELETE con ON DELETE CASCADE o un trigger que escribe en otra
     *     tabla tocan varias tablas.
     *
     * Antes se miraba solo lo último y se contaban tablas, no ficheros. Con una
     * tabla partida en dos, un corte de corriente entre el rename de la primera
     * parte y el de la segunda dejaba media tabla nueva y media vieja; como el
     * reparto en partes es por posición, no se perdían «unas filas», se
     * descuadraba todo a partir del corte.
     *
     * Así que se journaliza en cuanto haya más de un fichero en juego. El ámbito
     * del journal es el bloqueo que se tiene: con una sola tabla se tiene su
     * exclusivo y basta con el suyo, que es lo que permite que dos escrituras en
     * tablas distintas sigan yendo a la vez.
     */
    private function volcar(): void
    {
        $st     = $this->cat->storage();
        $tablas = array_keys($this->sucioDatos + $this->sucioMeta);

        // txAbierta(): si venimos de una operación de estructura, el journal ya
        // está abierto y no hay que anidar otro
        $conJournal = $tablas !== [] && Config::journalDatos() && !$st->txAbierta()
                   && (count($tablas) > 1 || $this->variosFicheros((string)$tablas[0]));

        if ($conJournal) {
            // El ámbito del journal es el bloqueo que se tiene, y dice cuál hará
            // falta para deshacerlo. Que la escritura acabe tocando una sola
            // tabla no basta: si se entró con el exclusivo de la base —porque la
            // tabla tiene claves foráneas, triggers, o alguien la referencia— el
            // journal es de base, no suyo.
            // El ámbito es una tabla concreta solo si se tiene el exclusivo de
            // TODAS las que toca la escritura: al deshacer harán falta todos.
            // Si alguna no está bloqueada, se entró con el exclusivo de la base
            // y el journal es de base.
            $conBloqueo = true;
            foreach ($tablas as $t) {
                if (!$st->tieneExclusivoDe((string)$t)) {
                    $conBloqueo = false;
                    break;
                }
            }
            $ordenadas = $tablas;
            sort($ordenadas, SORT_STRING);
            $ambito = $conBloqueo ? (string)$ordenadas[0] : null;
            $st->txIniciar('ESCRITURA', $tablas, $ambito);
        }

        foreach ($tablas as $tabla) {
            $st->guardarTabla(
                $tabla,
                isset($this->sucioDatos[$tabla]) ? $this->datos[$tabla] : null,
                isset($this->sucioMeta[$tabla]) ? Catalog::compactar($this->metas[$tabla]) : null,
                Indexes::definiciones($this->metas[$tabla] ?? $this->cat->meta($tabla)),
                $this->desde[$tabla] ?? null,
                array_keys($this->sueltas[$tabla] ?? []),
                ($this->sabe[$tabla] ?? false) === true
            );
        }

        if ($conJournal) {
            $st->txConfirmar();
        }

        $this->sucioDatos = [];
        $this->sucioMeta  = [];
        $this->desde      = [];
        $this->sueltas    = [];
        $this->sabe       = [];
        $this->cat->olvidar();
    }

    /**
     * ¿La escritura de esta tabla va a tocar más de un fichero?
     *
     * Basta con que tenga índices, con que cambien datos y estructura a la vez,
     * o con que las filas no quepan en una sola parte. Se mira sobre las filas
     * que se van a escribir, no sobre las que hay: una tabla que ahora ocupa una
     * parte y va a ocupar dos también necesita journal.
     */
    private function variosFicheros(string $tabla): bool
    {
        if (isset($this->sucioDatos[$tabla]) && isset($this->sucioMeta[$tabla])) {
            return true;
        }
        $meta = $this->metas[$tabla] ?? $this->cat->meta($tabla);
        if ($this->cat->storage()->indicesActivos()
            && (Indexes::definiciones($meta) !== [] || $this->cat->storage()->tieneIndices($tabla))) {
            return true;
        }
        $st = $this->cat->storage();
        if ($st->partes($tabla) > 1) {
            return true;                  // ya está repartida: se reescribe entera
        }
        if (!isset($this->sucioDatos[$tabla])) {
            return false;                 // solo cambia la estructura: un fichero
        }
        return count($this->datos[$tabla]) > Config::filasPorParte();
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

            // Se añade al final: nada de lo anterior se mueve, así que solo
            // cambia la última parte
            $posNueva = count($filas);
            $filas[] = $nueva;
            $this->anadirAIndices($meta, $nueva, $indices);
            $this->ponerFilas($tabla, $filas, $posNueva);
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

        $where  = $ast['where'] === null ? null : Evaluator::resolver($ast['where'], $mapa);
        $simple = $where === null ? null : Select::comparacionSimple($where);
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

        foreach ($filas as $pos0 => $vieja) {
            $ctx = ['fila' => $vieja, 'sub' => $sub];
            if ($where !== null && !self::cumple($where, $simple, $vieja, $ctx)) {
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

            // Sin variable local con la tabla: una segunda referencia viva
            // obligaría a PHP a copiarla entera en cada fila
            $pos = self::posicionEn($this->datos[$tabla], $vieja, $pos0);
            if ($pos === null) {
                continue;                        // un trigger ya la había borrado
            }
            $this->quitarDeIndices($meta, $vieja, $indices);
            $this->anadirAIndices($meta, $nueva, $indices);
            $this->ponerFilaEn($tabla, $pos, $nueva);
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
        $where  = $ast['where'] === null ? null : Evaluator::resolver($ast['where'], $mapa);
        $simple = $where === null ? null : Select::comparacionSimple($where);
        $sub   = fn(array $s, int $sid): array => $this->seleccionar($s);

        // Se guarda la posición de cada fila: casi siempre sigue ahí, y
        // encontrarla otra vez recorriendo la tabla era lo que volvía cuadrático
        // un borrado masivo
        $objetivo = [];
        foreach ($this->filas($tabla) as $pos => $fila) {
            if ($where === null
                || self::cumple($where, $simple, $fila, ['fila' => $fila, 'sub' => $sub])) {
                $objetivo[$pos] = $fila;
            }
        }

        $quitadas = 0;
        foreach ($objetivo as $pos0 => $vieja) {
            $this->lanzarTriggers($tabla, 'BEFORE', 'DELETE', null, $vieja);
            $this->propagarHijos($tabla, $meta, $vieja, null);

            $pos = self::posicionEn($this->datos[$tabla], $vieja, $pos0);
            if ($pos === null) {
                continue;                        // ya la había borrado una cascada o un trigger
            }
            $this->quitarFilaEn($tabla, $pos);
            $quitadas++;

            $this->lanzarTriggers($tabla, 'AFTER', 'DELETE', null, $vieja);
        }
        $this->compactar($tabla);

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
        foreach ($hijas as $pos0 => $fila) {
            $this->lanzarTriggers($tabla, 'BEFORE', 'DELETE', null, $fila);
            $this->propagarHijos($tabla, $meta, $fila, null);

            $pos = self::posicionEn($this->datos[$tabla], $fila, (int)$pos0);
            if ($pos === null) {
                continue;
            }
            $this->quitarFilaEn($tabla, $pos);

            $this->lanzarTriggers($tabla, 'AFTER', 'DELETE', null, $fila);
        }
        $this->compactar($tabla);
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
                    if ($ast['k'] === 'select' || $ast['k'] === 'union') {
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
        // El $tope se declara porque lo exige la firma del lector, y se ignora a
        // propósito: aquí las filas salen de lo que el Writer lleva en memoria,
        // no de un fichero, así que no hay lectura que cortar antes de tiempo.
        return (new Select(
            $this->cat,
            fn(string $t, ?int $tope = null): array => $this->filas($t)
        ))->ejecutar($ast);
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

    /**
     * ¿La fila cumple el WHERE? Con una comparación simple se resuelve sin pasar
     * por el evaluador general, que es lo que domina el coste en tablas grandes.
     *
     * @param array{clave: string, op: string, valor: mixed}|null $simple
     */
    private static function cumple(array $where, ?array $simple, array $fila, array $ctx): bool
    {
        if ($simple !== null) {
            $v = $fila[$simple['clave']] ?? null;
            return $v !== null && Select::compara($simple['op'], $v, $simple['valor']);
        }
        return Valor::verdadero(Evaluator::evaluar($where, $ctx)) === true;
    }
}
