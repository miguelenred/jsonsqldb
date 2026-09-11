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
    private array $anexo      = [];   // tabla => filas añadidas al final sin leer la tabla
    private array $cambios    = [];   // tabla => posición => fila cambiada sin leer la tabla
    private array $borradas   = [];   // tabla => posición => true, borrada sin leer la tabla
    private array $metas      = [];   // tabla => estructura en memoria
    private array $sucioDatos = [];
    private array $sucioMeta  = [];
    private array $astCache   = [];   // sql de trigger => árbol ya analizado
    private array $idxPadre   = [];   // tabla|cols => definición de índice, o claves recogidas
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

        // El DML abre su escritura en volcar(), con el ámbito del bloqueo que
        // tiene. Todo lo demás lleva siempre el exclusivo de la base y puede
        // tocar varias tablas: una sola escritura de base para todo ello.
        if (in_array($ast['k'], ['insert', 'update', 'delete'], true)) {
            return $this->despachar($ast);
        }
        $st = $this->cat->storage();
        $st->txIniciar(Database::operacion($ast));
        $r = $this->despachar($ast);
        $st->txConfirmar();
        return $r;
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
        if (!isset($this->datos[$tabla])) {
            // Lo cambiado sin leer la tabla pasa a estar en ella, como si se
            // hubiera hecho de la forma normal
            $this->datos[$tabla] = $this->cat->storage()->leerFilas($tabla);
            foreach ($this->cambios[$tabla] ?? [] as $pos => $fila) {
                $this->datos[$tabla][$pos] = $fila;
                $this->marcarSuelta($tabla, $pos);
            }
            foreach ($this->anexo[$tabla] ?? [] as $fila) {
                $this->marcarDesde($tabla, count($this->datos[$tabla]));
                $this->datos[$tabla][] = $fila;
            }
            foreach (array_keys($this->borradas[$tabla] ?? []) as $pos) {
                unset($this->datos[$tabla][$pos]);
                $this->marcarDesde($tabla, $pos);
            }
            if (isset($this->borradas[$tabla])) {
                $this->compactar($tabla);
            }
            unset($this->cambios[$tabla], $this->anexo[$tabla], $this->borradas[$tabla]);
        }
        return $this->datos[$tabla];
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
     * Añade una fila al final. Nada de lo anterior se mueve, así que solo
     * cambia la última parte. Si la tabla no está en memoria y se puede, la
     * fila se apunta aparte y la tabla no se lee.
     */
    private function anadirFila(string $tabla, array $fila, bool $sinLeer): void
    {
        if ($sinLeer && !isset($this->datos[$tabla])) {
            $this->anexo[$tabla][] = $fila;
        } else {
            $this->filas($tabla);
            $this->marcarDesde($tabla, count($this->datos[$tabla]));
            $this->datos[$tabla][] = $fila;
        }
        $this->sucioDatos[$tabla] = true;
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
     * Vuelca a disco todo lo modificado, en una sola escritura: cada tabla
     * puede ocupar varios ficheros y un corte entre dos no debe dejarla a
     * medias. El ámbito de la escritura es el bloqueo que se tiene: si es el
     * exclusivo de TODAS las tablas tocadas, basta con el de una de ellas,
     * que es lo que permite que escrituras en tablas distintas vayan a la vez;
     * si no, se entró con el exclusivo de la base y la escritura es de base.
     */
    private function volcar(): void
    {
        $st     = $this->cat->storage();
        $tablas = array_keys($this->sucioDatos + $this->sucioMeta);
        if ($tablas === []) {
            return;
        }
        $ambito = null;
        if (!$st->txAbierta()) {
            sort($tablas, SORT_STRING);
            $ambito = (string)$tablas[0];
            foreach ($tablas as $t) {
                if (!$st->tieneExclusivoDe((string)$t)) {
                    $ambito = null;
                    break;
                }
            }
            $st->txIniciar('ESCRITURA', $ambito);
        }

        foreach ($tablas as $tabla) {
            $meta = isset($this->sucioMeta[$tabla]) ? Catalog::compactar($this->metas[$tabla]) : null;
            $defs = Indexes::definiciones($this->metas[$tabla] ?? $this->cat->meta($tabla));
            if (isset($this->anexo[$tabla])) {
                $st->anadirFilas($tabla, $this->anexo[$tabla], $meta, $defs);
                continue;
            }
            if (isset($this->cambios[$tabla]) || isset($this->borradas[$tabla])) {
                $st->modificarFilas($tabla, $this->cambios[$tabla] ?? [],
                    array_keys($this->borradas[$tabla] ?? []), $meta, $defs);
                continue;
            }
            $st->guardarTabla(
                $tabla,
                isset($this->sucioDatos[$tabla]) ? $this->datos[$tabla] : null,
                $meta,
                $defs,
                $this->desde[$tabla] ?? null,
                array_keys($this->sueltas[$tabla] ?? []),
                ($this->sabe[$tabla] ?? false) === true
            );
        }
        $st->txConfirmar();

        $this->anexo      = [];
        $this->cambios    = [];
        $this->borradas   = [];
        $this->sucioDatos = [];
        $this->sucioMeta  = [];
        $this->desde      = [];
        $this->sueltas    = [];
        $this->sabe       = [];
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

        // Si la tabla no está en memoria y nada obliga a leerla, las filas
        // nuevas se apuntan aparte y se añaden al final al volcar: la unicidad
        // se comprueba contra los índices de disco y una tabla de cien mil
        // filas no pasa por la memoria para insertar una.
        $anexar  = $this->sinLeer($tabla, $meta);
        $indices = $this->indicesUnicos($tabla, $meta);
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
            $this->anadirAIndices($meta, $nueva, $indices);

            $this->anadirFila($tabla, $nueva, $anexar);
            $puestas++;

            $this->lanzarTriggers($tabla, 'AFTER', 'INSERT', $nueva, null);
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

        // Con un WHERE que resuelve un índice y nada que obligue a leer la
        // tabla, se leen solo las partes de las filas candidatas y al volcar se
        // reescriben solo esas partes
        $parcial = $this->sinLeer($tabla, $meta) ? $this->porIndice($tabla, $where) : null;
        $indices = $this->indicesUnicos($tabla, $meta);
        $sub     = fn(array $s, int $sid): array => $this->seleccionar($s);
        $tocadas = 0;

        foreach ($parcial ?? $this->candidatas($tabla, $where) as $pos0 => $vieja) {
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

            if ($parcial !== null && !isset($this->datos[$tabla])) {
                $this->cambios[$tabla][$pos0] = $nueva;
                $this->sucioDatos[$tabla]     = true;
            } else {
                // Sin variable local con la tabla: una segunda referencia viva
                // obligaría a PHP a copiarla entera en cada fila
                $pos = self::posicionEn($this->datos[$tabla], $vieja, $pos0);
                if ($pos === null) {
                    continue;                    // un trigger ya la había borrado
                }
                $this->ponerFilaEn($tabla, $pos, $nueva);
            }
            $this->quitarDeIndices($meta, $vieja, $indices);
            $this->anadirAIndices($meta, $nueva, $indices);
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
        $parcial  = $this->sinLeer($tabla, $meta) ? $this->porIndice($tabla, $where) : null;
        $objetivo = [];
        foreach ($parcial ?? $this->candidatas($tabla, $where) as $pos => $fila) {
            if ($where === null
                || self::cumple($where, $simple, $fila, ['fila' => $fila, 'sub' => $sub])) {
                $objetivo[$pos] = $fila;
            }
        }

        $quitadas = 0;
        foreach ($objetivo as $pos0 => $vieja) {
            $this->lanzarTriggers($tabla, 'BEFORE', 'DELETE', null, $vieja);
            $this->propagarHijos($tabla, $meta, $vieja, null);

            if ($parcial !== null && !isset($this->datos[$tabla])) {
                $this->borradas[$tabla][$pos0] = true;
                $this->sucioDatos[$tabla]      = true;
            } else {
                $pos = self::posicionEn($this->datos[$tabla], $vieja, $pos0);
                if ($pos === null) {
                    continue;                    // ya la había borrado una cascada o un trigger
                }
                $this->quitarFilaEn($tabla, $pos);
            }
            $quitadas++;

            $this->lanzarTriggers($tabla, 'AFTER', 'DELETE', null, $vieja);
        }
        if (isset($this->datos[$tabla])) {
            $this->compactar($tabla);
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

    /**
     * ¿Se puede insertar en esta tabla sin leerla? Hace falta que no esté ya
     * en memoria, que no tenga triggers —que podrían consultarla—, que no se
     * referencie a sí misma, y que cada conjunto único tenga índice en disco
     * para comprobar la unicidad contra él.
     */
    private function sinLeer(string $tabla, array $meta): bool
    {
        if (isset($this->datos[$tabla]) || $meta['triggers'] !== []) {
            return false;
        }
        foreach ($meta['foreign_keys'] as $fk) {
            if (strcasecmp($fk['table'], $tabla) === 0) {
                return false;
            }
        }
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            if ($this->indiceDe($tabla, $meta, $uq['columns']) === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Definición del índice de disco de la tabla sobre esas columnas, si lo
     * hay y está al día: exige que la tabla no tenga cambios en memoria,
     * porque el índice no los conoce.
     *
     * @return array{name: string, columns: list<string>, auto: bool}|null
     */
    private function indiceDe(string $tabla, array $meta, array $cols): ?array
    {
        if (isset($this->sucioDatos[$tabla])) {
            return null;
        }
        foreach (Indexes::definiciones($meta) as $def) {
            if ($def['columns'] === $cols) {
                return $this->cat->storage()->indiceValido($tabla, $def) ? $def : null;
            }
        }
        return null;
    }

    /**
     * Filas que pueden cumplir un WHERE de igualdad sobre una columna
     * indexada, leídas de sus partes y sin pasar por la tabla entera. Null si
     * no hay índice que sirva.
     *
     * @return array<int,array>|null posición => fila
     */
    private function porIndice(string $tabla, ?array $where): ?array
    {
        if ($where === null) {
            return null;
        }
        $predicados = Indexes::predicados($where, strtolower($tabla));
        $elegido    = Indexes::elegir($this->cat->indicesDe($tabla), $predicados[strtolower($tabla)] ?? []);
        if ($elegido === null) {
            return null;
        }
        if (isset($this->sucioDatos[$tabla])) {
            return null;                             // el índice no conoce los cambios en memoria
        }
        $st         = $this->cat->storage();
        $posiciones = $st->posicionesPorIndice($tabla, $elegido['def'], $elegido['claves'], $elegido['prefijo']);
        return $posiciones === null ? null : $st->filasEnPosiciones($tabla, $posiciones);
    }

    /**
     * Las filas sobre las que evaluar un WHERE: las que acota un índice, o la
     * tabla entera.
     *
     * @return array<int,array> posición => fila
     */
    private function candidatas(string $tabla, ?array $where): array
    {
        $porIndice = isset($this->datos[$tabla]) ? null : $this->porIndice($tabla, $where);
        $filas     = $this->filas($tabla);
        if ($porIndice === null) {
            return $filas;
        }
        $out = [];
        foreach (array_keys($porIndice) as $p) {
            if (isset($filas[$p])) {
                $out[$p] = $filas[$p];
            }
        }
        return $out;
    }

    /**
     * Estado de cada conjunto único de la tabla durante la sentencia. Las
     * claves que ya están en la tabla se consultan al índice de disco, trozo
     * a trozo, si lo hay y sirve; si no, se recorre la tabla una vez y se
     * guardan en `mapa`. Lo que la sentencia añade y quita va aparte, en
     * `nuevas` y `quitadas`, porque el índice de disco no lo sabe.
     *
     * @return array<string,array{def: ?array, mapa: ?array<string,true>, nuevas: array<string,true>, quitadas: array<string,true>}>
     */
    private function indicesUnicos(string $tabla, array $meta): array
    {
        $indices = [];
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $def  = $this->indiceDe($tabla, $meta, $uq['columns']);
            $mapa = null;
            if ($def === null) {
                $mapa = [];
                foreach ($this->filas($tabla) as $fila) {
                    $clave = self::claveDe($fila, $uq['columns']);
                    if ($clave !== null) {
                        $mapa[$clave] = true;
                    }
                }
            }
            $indices[$uq['name']] = ['def' => $def, 'mapa' => $mapa, 'nuevas' => [], 'quitadas' => []];
        }
        return $indices;
    }

    /** ¿Hay una fila con esta clave en un conjunto único, contando lo hecho en esta sentencia? */
    private function claveOcupada(string $tabla, array $indice, string $clave): bool
    {
        if (isset($indice['nuevas'][$clave])) {
            return true;
        }
        if (isset($indice['quitadas'][$clave])) {
            return false;
        }
        if ($indice['def'] !== null) {
            return $this->cat->storage()->claveEnIndice($tabla, $indice['def'], $clave) === true;
        }
        return isset($indice['mapa'][$clave]);
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
            if ($this->claveOcupada($tabla, $indices[$uq['name']], $clave)) {
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
                $indices[$uq['name']]['nuevas'][$clave] = true;
                unset($indices[$uq['name']]['quitadas'][$clave]);
            }
        }
    }

    private function quitarDeIndices(array $meta, array $fila, array &$indices): void
    {
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $clave = self::claveDe($fila, $uq['columns']);
            if ($clave !== null) {
                $indices[$uq['name']]['quitadas'][$clave] = true;
                unset($indices[$uq['name']]['nuevas'][$clave]);
            }
        }
    }

    /**
     * Clave compuesta de una fila; null si alguna columna es NULL. Es la misma
     * clave que usan los índices de disco, para poder comprobar contra ellos.
     */
    private static function claveDe(array $fila, array $cols): ?string
    {
        $valores = [];
        foreach ($cols as $c) {
            $valores[] = $fila[$c] ?? null;
        }
        return Indexes::clave($valores);
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
            if (!$this->padreTiene($fk['table'], $fk['references'], $clave)) {
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

    /**
     * ¿Existe esta clave en una tabla padre? Contra su índice de disco si lo
     * hay; si no, sus claves se recogen una vez recorriéndola.
     */
    private function padreTiene(string $tabla, array $cols, string $clave): bool
    {
        $id = $tabla . '|' . implode(',', $cols);
        if (!isset($this->idxPadre[$id])) {
            $def = $this->indiceDe($tabla, $this->meta($tabla), $cols);
            if ($def !== null) {
                $this->idxPadre[$id] = $def;
            } else {
                $idx = [];
                foreach ($this->filas($tabla) as $fila) {
                    $k = self::claveDe($fila, $cols);
                    if ($k !== null) {
                        $idx[$k] = true;
                    }
                }
                $this->idxPadre[$id] = $idx;
            }
        }
        $idx = $this->idxPadre[$id];
        if (isset($idx['name'])) {
            return $this->cat->storage()->claveEnIndice($tabla, $idx, $clave) === true;
        }
        return isset($idx[$clave]);
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
