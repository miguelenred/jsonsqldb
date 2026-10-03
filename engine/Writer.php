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
 *
 * https://miguelenred.es/jsonsqldb
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
    /** @var array<string,array> resultados de subconsultas que no miran fuera, por su texto */
    private array $subsFijas = [];
    /** @var array<string,array> sus conjuntos de valores, para IN */
    private array $conjuntosFijos = [];
    /** @var array<string,int> siguiente autoincremento por tabla, movido en esta sentencia */
    private array $autoinc    = [];
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
            case 'update':
            case 'delete':
                $this->devueltas = [];
                $n = $ast['k'] === 'insert' ? $this->insertar($ast) : ($ast['k'] === 'update' ? $this->actualizar($ast) : $this->borrar($ast));
                $this->volcar();
                $r = ['filas' => $n, 'mensaje' => "$n fila(s) " . ['insert' => 'insertada(s)', 'update' => 'actualizada(s)', 'delete' => 'eliminada(s)'][$ast['k']]];
                if (($ast['returning'] ?? null) !== null) {
                    $r['devueltas'] = $this->devolver($ast['tabla'], $ast['returning']);
                }
                $this->devueltas = [];
                return $r;

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
            $this->olvidarIndices($tabla);
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
    /** @var array<string,array{cols: list<string>, mapa: array<string,array<int,true>>}> lado hijo de las claves foráneas (hijasDe()) */
    private array $idxHijo = [];
    /** @var array<string,int> cuántas veces se han pedido las hijas de cada clave foránea en la sentencia */
    private array $pedidasHijo = [];
    /** @var array<string,true> tablas con huecos de un borrado en cascada, por compactar al terminar la sentencia */
    private array $porCompactar = [];
    /** @var list<array> filas escritas por la sentencia, para su RETURNING */
    private array $devueltas = [];
    /** @var array<string,array<string,int>> por clave única, clave → posición (ON CONFLICT, OR IGNORE, OR REPLACE) */
    private array $unicas = [];
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
        $this->olvidarPadre($tabla);
        if (isset($this->datos[$tabla])) {
            $this->apuntarHija($tabla, (int)array_key_last($this->datos[$tabla]), $fila);
        }
    }

    /**
     * Escribe una fila en su sitio sin copiar la tabla entera.
     *
     * El patrón de antes era: sacar el array con filas(), tocar una posición y
     * devolverlo entero a $this->datos. Con dos referencias vivas al mismo array,
     * PHP separa la copia en cuanto se escribe, así que cada fila afectada
     * copiaba la tabla completa. Aquí se escribe directamente en $this->datos,
     * sin variable intermedia que lo referencie.
     */
    private function ponerFilaEn(string $tabla, int $pos, array $fila): void
    {
        $this->datos[$tabla][$pos] = $fila;
        $this->sucioDatos[$tabla]  = true;
        $this->marcarSuelta($tabla, $pos);        // no mueve a las demás
        $this->olvidarPadre($tabla);
        $this->apuntarHija($tabla, $pos, $fila);  // su clave puede ser otra
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
        $this->olvidarPadre($tabla);              // en el lado hijo, la posición vacía se salta al usarla
    }

    /** Cierra los huecos que hayan dejado los borrados. */
    private function compactar(string $tabla): void
    {
        if (isset($this->datos[$tabla])) {
            $this->datos[$tabla] = array_values($this->datos[$tabla]);
            $this->olvidarIndices($tabla);        // las posiciones han cambiado
        }
    }

    /** Lo que se sabía de la tabla como padre y como hija: ya no vale. */
    private function olvidarIndices(string $tabla): void
    {
        $this->olvidarPadre($tabla);
        foreach (array_keys($this->idxHijo) as $id) {
            if (strncmp($id, $tabla . '|', strlen($tabla) + 1) === 0) {
                unset($this->idxHijo[$id]);
            }
        }
    }

    private function olvidarPadre(string $tabla): void
    {
        foreach (array_keys($this->idxPadre) as $clave) {
            if (strncmp($clave, $tabla . '|', strlen($tabla) + 1) === 0) {
                unset($this->idxPadre[$clave]);
            }
        }
    }

    /**
     * Lado hijo de una clave foránea: las filas de $hija cuyas columnas $cols
     * valen $clave. El mapa clave → posiciones se arma una vez y se va
     * manteniendo al escribir, así que borrar o cambiar N padres ya no recorre
     * la tabla hija N veces (era cuadrático: 1.000 padres con 25.000 hijos,
     * 11 s). Una posición que se ha vaciado o cuya clave ha cambiado se salta.
     *
     * @return array<int,array> posición → fila, en orden de posición
     */
    private function hijasDe(string $hija, array $cols, string $clave): array
    {
        $this->filas($hija);                      // cargada: si se acaba de leer, sin mapas viejos
        $id = $hija . '|' . implode(',', $cols);
        // Con un solo padre (borrar o cambiar una fila), recorrer la tabla una
        // vez cuesta lo mismo que armar el mapa y no ocupa memoria: el mapa se
        // arma a partir del segundo padre de la sentencia
        if (!isset($this->idxHijo[$id]) && ($this->pedidasHijo[$id] = ($this->pedidasHijo[$id] ?? 0) + 1) === 1) {
            $out = [];
            foreach ($this->datos[$hija] as $pos => $f) {
                if (self::claveDe($f, $cols) === $clave) {
                    $out[$pos] = $f;
                }
            }
            return $out;
        }
        if (!isset($this->idxHijo[$id])) {
            $mapa = [];
            foreach ($this->filas($hija) as $pos => $f) {
                $k = self::claveDe($f, $cols);
                if ($k !== null) {
                    $mapa[$k][$pos] = true;
                }
            }
            $this->idxHijo[$id] = ['cols' => $cols, 'mapa' => $mapa];
        }
        $out = [];
        foreach (array_keys($this->idxHijo[$id]['mapa'][$clave] ?? []) as $pos) {
            $f = $this->datos[$hija][$pos] ?? null;
            if ($f !== null && self::claveDe($f, $cols) === $clave) {
                $out[$pos] = $f;
            }
        }
        ksort($out);
        return $out;
    }

    /** Una fila escrita en $pos: entra en los mapas de hijas de su tabla con su clave de ahora. */
    private function apuntarHija(string $tabla, int $pos, array $fila): void
    {
        foreach ($this->idxHijo as $id => $i) {
            if (strncmp($id, $tabla . '|', strlen($tabla) + 1) === 0) {
                $k = self::claveDe($fila, $i['cols']);
                if ($k !== null) {
                    $this->idxHijo[$id]['mapa'][$k][$pos] = true;
                }
            }
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
            if (isset($this->autoinc[$tabla])) {
                $st->ponerAutoincremento($tabla, $this->autoinc[$tabla]);
            }
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
        // filas no pasa por la memoria para insertar una. Con ON CONFLICT, OR
        // IGNORE u OR REPLACE sí se carga: hay que saber con qué fila choca
        $conflicto = $ast['conflicto'] ?? null;
        $devolver  = ($ast['returning'] ?? null) !== null;
        $objetivo  = null;
        if ($conflicto !== null) {
            $this->filas($tabla);
            $this->unicas = [];
            $objetivo = $conflicto['cols'] === null ? null : $this->claveObjetivo($tabla, $meta, $conflicto['cols']);
        }
        $anexar  = $conflicto === null && $this->sinLeer($tabla, $meta);
        $indices = $this->indicesUnicos($tabla, $meta);
        $puestas = 0;
        $borradasPorReemplazo = false;

        foreach ($origen as $valores) {
            if (count($valores) !== count($cols)) {
                throw JsonSqlDbError::constraint(
                    'El número de valores (' . count($valores) . ') no coincide con el de columnas (' . count($cols) . ')'
                );
            }

            $nueva = [];
            foreach ($meta['columns'] as $c) {
                $nueva[$c['name']] = $c['default_expr'] === null ? $c['default'] : self::porDefecto($c);
            }
            foreach ($cols as $i => $c) {
                if (!(is_array($valores[$i]) && ($valores[$i][0] ?? null) === '__default__')) {
                    $nueva[$c] = $valores[$i];
                }
            }

            $nueva = $this->prepararFila($tabla, $meta, $nueva, true);

            $preparada = $nueva;
            $nueva = $this->lanzarTriggers($tabla, 'BEFORE', 'INSERT', $nueva, null);
            if ($nueva !== $preparada) {
                // Un SET NEW la ha cambiado: otra vez tipos y NOT NULL
                $nueva = $this->prepararFila($tabla, $meta, $nueva, true);
            }
            if ($conflicto !== null) {
                $choca = $this->chocaCon($tabla, $meta, $nueva, $objetivo);
                if ($choca !== null && $conflicto['modo'] === 'nada') {
                    continue;                           // OR IGNORE, DO NOTHING
                }
                if ($choca !== null && $conflicto['modo'] === 'actualizar') {
                    if ($this->actualizarConflicto($tabla, $meta, $choca, $nueva, $conflicto, $indices, $devolver)) {
                        $puestas++;
                    }
                    continue;
                }
                if ($conflicto['modo'] === 'reemplazar') {
                    // Las filas con las que choca, en cualquier clave única, se
                    // borran (con sus acciones de clave foránea, sin triggers:
                    // como SQLite con recursive_triggers desactivado)
                    while (($choca = $this->chocaCon($tabla, $meta, $nueva, null)) !== null) {
                        $vieja = $this->datos[$tabla][$choca];
                        $this->propagarHijos($tabla, $meta, $vieja, null);
                        $this->quitarFilaEn($tabla, $choca);
                        $this->quitarDeIndices($meta, $vieja, $indices);
                        $this->apuntarUnicas($tabla, $meta, $choca, $vieja, null);
                        $borradasPorReemplazo = true;
                    }
                }
            }
            $this->comprobarUnicos($tabla, $meta, $nueva, $indices, null);
            $this->comprobarForaneas($tabla, $meta, $nueva);
            $this->anadirAIndices($meta, $nueva, $indices);

            $this->anadirFila($tabla, $nueva, $anexar);
            $puestas++;
            if ($conflicto !== null) {
                $this->apuntarUnicas($tabla, $meta, (int)array_key_last($this->datos[$tabla]), null, $nueva);
            }
            if ($devolver) {
                $this->devueltas[] = $nueva;
            }

            $this->lanzarTriggers($tabla, 'AFTER', 'INSERT', $nueva, null);
        }


        if ($borradasPorReemplazo) {
            $this->compactar($tabla);
        }
        $this->unicas = [];
        return $puestas;
    }

    /** El conjunto único que nombra un ON CONFLICT (cols); error si no hay ninguno con esas columnas, como en SQLite. */
    private function claveObjetivo(string $tabla, array $meta, array $cols): string
    {
        $buscadas = array_map('strtolower', $cols);
        sort($buscadas);
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $suyas = array_map('strtolower', $uq['columns']);
            sort($suyas);
            if ($suyas === $buscadas) {
                return $uq['name'];
            }
        }
        throw JsonSqlDbError::constraint("ON CONFLICT (" . implode(', ', $cols) . ") no corresponde a ninguna clave primaria ni restricción UNIQUE de '$tabla'");
    }

    /**
     * Posición de la fila con la que choca $nueva en la clave única $soloEsa,
     * o en cualquiera si es null; null si no choca. Los mapas clave →
     * posición se arman la primera vez y se mantienen con apuntarUnicas().
     */
    private function chocaCon(string $tabla, array $meta, array $nueva, ?string $soloEsa): ?int
    {
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            if ($soloEsa !== null && $uq['name'] !== $soloEsa) {
                continue;
            }
            $clave = self::claveDe($nueva, $uq['columns']);
            if ($clave === null) {
                continue;                               // con algún NULL no hay choque
            }
            if (!isset($this->unicas[$uq['name']])) {
                $mapa = [];
                foreach ($this->datos[$tabla] as $pos => $f) {
                    $k = self::claveDe($f, $uq['columns']);
                    if ($k !== null) {
                        $mapa[$k] = $pos;
                    }
                }
                $this->unicas[$uq['name']] = $mapa;
            }
            if (isset($this->unicas[$uq['name']][$clave])) {
                return $this->unicas[$uq['name']][$clave];
            }
        }
        return null;
    }

    /** Una fila que entra, cambia o sale: los mapas de chocaCon() al día. */
    private function apuntarUnicas(string $tabla, array $meta, int $pos, ?array $vieja, ?array $nueva): void
    {
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            if (!isset($this->unicas[$uq['name']])) {
                continue;                               // se armará leyendo la tabla, ya al día
            }
            if ($vieja !== null && ($k = self::claveDe($vieja, $uq['columns'])) !== null
                && ($this->unicas[$uq['name']][$k] ?? null) === $pos) {
                unset($this->unicas[$uq['name']][$k]);
            }
            if ($nueva !== null && ($k = self::claveDe($nueva, $uq['columns'])) !== null) {
                $this->unicas[$uq['name']][$k] = $pos;
            }
        }
    }

    /**
     * ON CONFLICT … DO UPDATE: cambia la fila con la que choca. En SET y en
     * WHERE, las columnas son las de esa fila y excluded.col las de la que se
     * quería insertar. Pasa por todo lo de un UPDATE (tipos, triggers,
     * claves únicas y foráneas). False si el WHERE la deja como está.
     */
    private function actualizarConflicto(string $tabla, array $meta, int $pos, array $propuesta, array $conflicto, array &$indices, bool $devolver): bool
    {
        $mapa = $this->mapaColumnas($tabla, $meta);
        foreach ($meta['columns'] as $c) {
            $mapa['excluded.' . strtolower($c['name'])] = 'excluded.' . $c['name'];
        }
        $vieja = $this->datos[$tabla][$pos];
        $fila  = $vieja;
        foreach ($meta['columns'] as $c) {
            $fila['excluded.' . $c['name']] = $propuesta[$c['name']] ?? null;
        }
        $sub = $this->subconsultas($mapa);
        $ctx = ['fila' => $fila, 'sub' => $sub, 'conjunto' => $this->conjuntos($sub)];
        if ($conflicto['where'] !== null
            && Valor::verdadero(Evaluator::evaluar(Evaluator::resolver($conflicto['where'], $mapa), $ctx)) !== true) {
            return false;
        }
        $nueva = $vieja;
        foreach ($conflicto['set'] as $s) {
            $col = Catalog::columna($meta, $s['col']);
            if ($col === null) {
                throw JsonSqlDbError::schema("La columna '{$s['col']}' no existe en '$tabla'");
            }
            $nueva[$col['name']] = Evaluator::evaluar(Evaluator::resolver($s['expr'], $mapa), $ctx);
        }
        $nueva = $this->prepararFila($tabla, $meta, $nueva, false);
        $preparada = $nueva;
        $nueva = $this->lanzarTriggers($tabla, 'BEFORE', 'UPDATE', $nueva, $vieja);
        if ($nueva !== $preparada) {
            $nueva = $this->prepararFila($tabla, $meta, $nueva, false);
        }
        $this->comprobarUnicos($tabla, $meta, $nueva, $indices, $vieja);
        $this->comprobarForaneas($tabla, $meta, $nueva);
        $this->propagarHijos($tabla, $meta, $vieja, $nueva);
        $pos = self::posicionEn($this->datos[$tabla], $vieja, $pos) ?? $pos;
        $this->ponerFilaEn($tabla, $pos, $nueva);
        $this->quitarDeIndices($meta, $vieja, $indices);
        $this->anadirAIndices($meta, $nueva, $indices);
        $this->apuntarUnicas($tabla, $meta, $pos, $vieja, $nueva);
        if ($devolver) {
            $this->devueltas[] = $nueva;
        }
        $this->lanzarTriggers($tabla, 'AFTER', 'UPDATE', $nueva, $vieja);
        return true;
    }

    /**
     * RETURNING: las filas escritas, proyectadas como las columnas de un
     * SELECT sobre la tabla (* son todas, en su orden).
     *
     * @return list<array>
     */
    private function devolver(string $tabla, array $columnas): array
    {
        $meta = $this->meta($tabla);
        $mapa = $this->mapaColumnas($tabla, $meta);
        $out  = [];
        foreach ($this->devueltas as $fila) {
            $r = [];
            foreach ($columnas as $c) {
                if ($c['star']) {
                    foreach ($meta['columns'] as $col) {
                        $r[$col['name']] = $fila[$col['name']] ?? null;
                    }
                    continue;
                }
                $r[$c['alias'] ?? Select::etiqueta($c['expr'])] = Evaluator::evaluar(Evaluator::resolver($c['expr'], $mapa), ['fila' => $fila]);
            }
            $out[] = $r;
        }
        return $out;
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
        $parcial = $this->sinLeer($tabla, $meta) ? $this->porIndice($tabla, $where, true) : null;
        $indices = $this->indicesUnicos($tabla, $meta);
        $sub     = $this->subconsultas($mapa);
        $conj    = $this->conjuntos($sub);       // una vez, no en cada fila
        $tocadas = 0;

        foreach ($parcial ?? $this->candidatas($tabla, $where) as $pos0 => $vieja) {
            $ctx = ['fila' => $vieja, 'sub' => $sub, 'conjunto' => $conj];
            if ($where !== null && !self::cumple($where, $simple, $vieja, $ctx)) {
                continue;
            }

            $nueva = $vieja;
            foreach ($sets as $s) {
                $nueva[$s['col']['name']] = $s['expr'] === null
                    ? self::porDefecto($s['col'])
                    : Evaluator::evaluar($s['expr'], $ctx);
            }
            $nueva = $this->prepararFila($tabla, $meta, $nueva, false);

            $preparada = $nueva;
            $nueva = $this->lanzarTriggers($tabla, 'BEFORE', 'UPDATE', $nueva, $vieja);
            if ($nueva !== $preparada) {
                $nueva = $this->prepararFila($tabla, $meta, $nueva, false);
            }
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
            if (($ast['returning'] ?? null) !== null) {
                $this->devueltas[] = $nueva;
            }

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
        $sub   = $this->subconsultas($mapa);
        $conj  = $this->conjuntos($sub);

        // Se guarda la posición de cada fila: casi siempre sigue ahí, y
        // encontrarla otra vez recorriendo la tabla era lo que volvía cuadrático
        // un borrado masivo
        $parcial  = $this->sinLeer($tabla, $meta) ? $this->porIndice($tabla, $where, true) : null;
        $objetivo = [];
        foreach ($parcial ?? $this->candidatas($tabla, $where) as $pos => $fila) {
            if ($where === null
                || self::cumple($where, $simple, $fila, ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conj])) {
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
            if (($ast['returning'] ?? null) !== null) {
                $this->devueltas[] = $vieja;
            }

            $this->lanzarTriggers($tabla, 'AFTER', 'DELETE', null, $vieja);
        }
        if (isset($this->datos[$tabla])) {
            $this->compactar($tabla);
        }
        foreach (array_keys($this->porCompactar) as $t) {
            $this->compactar((string)$t);         // las hijas borradas en cascada
        }
        $this->porCompactar = [];

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

    /**
     * El contador de autoincremento no ensucia la estructura: se lleva aparte
     * y al volcar va a rev.json (ver Storage::ponerAutoincremento()).
     */
    private function siguienteAutoincremento(string $tabla): int
    {
        $n = $this->autoinc[$tabla] ?? (int)$this->meta($tabla)['autoincrement']['next'];
        $this->autoinc[$tabla] = $n + 1;
        return $n;
    }

    private function ajustarAutoincremento(string $tabla, int $valor): void
    {
        $meta = $this->meta($tabla);
        if ($meta['autoincrement'] === null) {
            return;
        }
        $n = $this->autoinc[$tabla] ?? (int)$meta['autoincrement']['next'];
        if ($valor >= $n) {
            $this->autoinc[$tabla] = $valor + 1;
        }
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
    /** @return iterable<int,array>|null posición => fila */
    private function porIndice(string $tabla, ?array $where, bool $conTexto = false): ?iterable
    {
        if ($where === null) {
            return null;
        }
        if (isset($this->sucioDatos[$tabla])) {
            return null;                             // el índice no conoce los cambios en memoria
        }
        $predicados = Indexes::predicados($where, strtolower($tabla));
        $st         = $this->cat->storage();
        // Del mejor al peor: si uno no compensa, el siguiente
        foreach (Indexes::candidatos($this->cat->indicesDe($tabla), $predicados[strtolower($tabla)] ?? []) as $c) {
            $posiciones = $st->posicionesPorIndice($tabla, $c['def'], $c['claves'], $c['prefijo']);
            if ($posiciones !== null) {
                return $st->filasEnPosiciones($tabla, $posiciones);
            }
        }
        // Sin índice que sirva, un col = 'texto' todavía deja saltar las
        // partes que no lo contienen: un UPDATE o DELETE de unas pocas filas
        // ya no carga la tabla entera. Solo cuando lo que se devuelve evita
        // cargarla ($conTexto): si después se carga igual, leer las partes
        // antes sería leerlas dos veces
        if (!$conTexto) {
            return null;
        }
        // Y si no hay texto que buscar, la tabla se recorre parte a parte en
        // vez de cargarla entera: en memoria quedan solo las filas que cumplen
        return $st->filasConTexto($tabla, Indexes::agujas($predicados[strtolower($tabla)] ?? []))
            ?? $st->filasConPosicion($tabla);
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
    /**
     * El valor por defecto de una columna: el fijo, o el que se calcula ahora
     * (CURRENT_TIMESTAMP, (expresión)). La expresión se analiza una vez.
     *
     * @return mixed
     */
    private static function porDefecto(array $col)
    {
        $expr = $col['default_expr'] ?? null;
        if ($expr === null) {
            return $col['default'];
        }
        static $analizadas = [];
        $analizadas[$expr] ??= Evaluator::resolver(Parser::analizar('SELECT ' . $expr)['cols'][0]['expr'], []);
        return Evaluator::evaluar($analizadas[$expr], ['fila' => []]);
    }

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
                // Con un índice sobre la clave foránea de la hija, sus filas se
                // leen por él y la tabla hija no se carga (ver hijasSinCargar())
                $sinCargar = $this->hijasSinCargar($hija, $metaHija, $fk['columns'], $claveVieja);
                $hijas     = $sinCargar ?? $this->hijasDe($hija, $fk['columns'], $claveVieja);
                if ($hijas === []) {
                    continue;
                }

                if ($accion === 'NO ACTION' || $accion === 'RESTRICT') {
                    throw JsonSqlDbError::constraint(
                        "'$hija' tiene " . count($hijas) . " fila(s) que dependen de esta (clave foránea '{$fk['name']}')"
                    );
                }

                if ($accion === 'CASCADE' && $nueva === null) {
                    // Con sus posiciones de verdad: renumeradas (array_values) no
                    // coincidían, y cada hija se buscaba recorriendo la tabla
                    $sinCargar !== null ? $this->borrarHijasSinCargar($hija, $hijas) : $this->borrarHijas($hija, $hijas);
                    continue;
                }

                // Cada hija en su sitio: copiar la tabla, tocarla y volver a
                // ponerla entera hacía que se reescribiera completa al guardar
                $metaHijaActual = $this->meta($hija);
                foreach ($hijas as $pos => $f) {
                    foreach ($fk['columns'] as $i => $c) {
                        if ($accion === 'CASCADE') {
                            $f[$c] = $nueva[$fk['references'][$i]];
                        } elseif ($accion === 'SET NULL') {
                            $f[$c] = null;
                        } else {                 // SET DEFAULT
                            $col   = Catalog::columna($metaHijaActual, $c);
                            $f[$c] = self::porDefecto($col);
                        }
                    }
                    $f = $this->prepararFila($hija, $metaHijaActual, $f, false);
                    if ($sinCargar !== null) {
                        $this->cambios[$hija][$pos] = $f;    // como un UPDATE que no carga la tabla
                        $this->sucioDatos[$hija]     = true;
                    } else {
                        $this->ponerFilaEn($hija, $pos, $f);
                    }
                }
            }
        }
    }

    /**
     * Las filas hijas de una clave, leídas por un índice sobre las columnas de
     * la clave foránea y sin cargar la tabla hija: borrar un cliente ya no
     * lee la tabla de pedidos entera para encontrar los suyos. Solo si la
     * tabla hija está tal cual en disco y se puede escribir sin cargarla (sin
     * triggers ni cambios pendientes: ver sinLeer()); si no, null, y se busca
     * como siempre. La tabla no crea sola ese índice, como en SQLite: hay que
     * crearlo (CREATE INDEX … ON pedidos (cliente_id)).
     *
     * @return array<int,array>|null posición => fila
     */
    private function hijasSinCargar(string $hija, array $metaHija, array $cols, string $clave): ?array
    {
        if (isset($this->datos[$hija]) || !$this->sinLeer($hija, $metaHija)) {
            return null;
        }
        $def = $this->indiceDe($hija, $metaHija, $cols);
        if ($def === null) {
            return null;
        }
        $st         = $this->cat->storage();
        $posiciones = $st->posicionesPorIndice($hija, $def, [$clave], false);
        if ($posiciones === null) {
            return null;
        }
        $out = [];
        foreach ($st->filasEnPosiciones($hija, $posiciones) as $pos => $fila) {
            if (self::claveDe($fila, $cols) === $clave) {
                $out[$pos] = $fila;
            }
        }
        return $out;
    }

    /**
     * Borra en cascada filas hijas leídas sin cargar su tabla: se apuntan como
     * borradas, igual que un DELETE que no la carga, y se propaga a sus
     * propias hijas. Sin triggers que lanzar: sinLeer() exige que no los haya.
     */
    private function borrarHijasSinCargar(string $tabla, array $hijas): void
    {
        $meta = $this->meta($tabla);
        foreach ($hijas as $pos => $fila) {
            $this->propagarHijos($tabla, $meta, $fila, null);
            $this->borradas[$tabla][$pos] = true;
            $this->sucioDatos[$tabla]      = true;
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
        // Se compacta al terminar la sentencia: hacerlo aquí, tras cada padre,
        // movía las posiciones y obligaba a rehacer el mapa de hijas
        $this->porCompactar[$tabla] = true;
    }

    // ==================================================================
    // Triggers
    // ==================================================================

    /**
     * Ejecuta los triggers de una tabla para un momento y un evento. Devuelve
     * NEW tal como lo dejan: un trigger BEFORE INSERT o BEFORE UPDATE puede
     * cambiarlo con SET NEW.col = expr, y lo que se escribe es esa fila.
     */
    private function lanzarTriggers(string $tabla, string $timing, string $evento, ?array $new, ?array $old): ?array
    {
        $meta = $this->meta($tabla);
        if ($meta['triggers'] === []) {
            return $new;
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
                if ($trg['when'] !== null && !$this->cumpleCondicion($trg['when'], $new, $old)) {
                    continue;
                }
                $new = $this->ejecutarCuerpo($trg, $trg['body'], $meta, $new, $old);
            } finally {
                $this->anidamiento--;
            }
        }
        return $new;
    }

    /** ¿Se cumple una condición de trigger (WHEN, o la de un IF) con estos NEW y OLD? */
    private function cumpleCondicion(string $cond, ?array $new, ?array $old): bool
    {
        $ast = $this->sustituir($this->analizarExpr($cond), $new, $old);
        $ctx = ['fila' => [], 'sub' => fn(array $s, int $sid): array => $this->seleccionar($s)];
        return Valor::verdadero(Evaluator::evaluar(Evaluator::resolver($ast, []), $ctx)) === true;
    }

    /**
     * Las sentencias de un trigger, en orden: texto de una sentencia, SET
     * NEW.col = expr, o un bloque IF … ELSEIF … ELSE … END IF.
     *
     * @param list<string|array> $cuerpo
     */
    private function ejecutarCuerpo(array $trg, array $cuerpo, array $meta, ?array $new, ?array $old): ?array
    {
        foreach ($cuerpo as $paso) {
            if (is_array($paso)) {
                $hecho = false;
                foreach ($paso['si'] as [$cond, $sentencias]) {
                    if ($this->cumpleCondicion($cond, $new, $old)) {
                        $new = $this->ejecutarCuerpo($trg, $sentencias, $meta, $new, $old);
                        $hecho = true;
                        break;
                    }
                }
                if (!$hecho && $paso['sino'] !== null) {
                    $new = $this->ejecutarCuerpo($trg, $paso['sino'], $meta, $new, $old);
                }
                continue;
            }
            if (strncasecmp(ltrim($paso), 'SET', 3) === 0) {
                if ($trg['timing'] !== 'BEFORE' || $trg['event'] === 'DELETE' || $new === null) {
                    throw JsonSqlDbError::syntax("SET NEW solo vale en un trigger BEFORE INSERT o BEFORE UPDATE ('{$trg['name']}')");
                }
                $set = $this->astCache[$paso] ??= Parser::analizarSetNew($paso);
                // De izquierda a derecha, y cada una ve lo que dejaron las
                // anteriores: como el SET de MySQL y las asignaciones de PostgreSQL
                foreach ($set['asig'] as [$col, $expr]) {
                    $def = null;
                    foreach ($meta['columns'] as $c) {
                        if (strcasecmp($c['name'], $col) === 0) { $def = $c; }
                    }
                    if ($def === null) {
                        throw JsonSqlDbError::schema("NEW.$col no es una columna de la tabla");
                    }
                    $ctx = ['fila' => [], 'sub' => fn(array $s, int $sid): array => $this->seleccionar($s)];
                    $valor = Evaluator::evaluar(Evaluator::resolver($this->sustituir($expr, $new, $old), []), $ctx);
                    $new[$def['name']] = Types::cast($valor, $def);
                }
                continue;
            }
            $ast = $this->sustituir($this->analizar($paso), $new, $old);
            if ($ast['k'] === 'select' || $ast['k'] === 'union') {
                $this->seleccionar($ast);
            } else {
                $this->ejecutarSinVolcar($ast, $trg['name']);
            }
        }
        return $new;
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

        if ($ast['def'] === null) {
            return $this->crearTablaDeConsulta($tabla, $ast['select']);
        }
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

    /**
     * CREATE TABLE t AS SELECT: las columnas son las del resultado, sin claves,
     * NOT NULL ni valores por defecto, como en SQLite. Una columna que sale tal
     * cual de una tabla conserva su tipo; las demás lo toman de sus valores
     * (enteros, números o texto; TEXT si son todos NULL o se mezclan).
     */
    private function crearTablaDeConsulta(string $tabla, array $select): array
    {
        $r = (new Select($this->cat, fn(string $t): array => $this->filas($t)))->ejecutarConColumnas($select);
        $tipos = $this->tiposDeOrigen($select, count($r['cols']));
        $def = ['columns' => [], 'unique' => [], 'foreign_keys' => []];
        foreach ($r['cols'] as $i => $nombre) {
            $def['columns'][] = ['name' => (string)$nombre, 'notnull' => false, 'default' => null, 'pk' => false,
                                 'autoincrement' => false, 'unique' => false]
                              + ($tipos[$i] ?? self::tipoDeValores(array_column($r['filas'], $nombre)));
        }
        $n = count($r['filas']);
        $this->cat->crearTabla($tabla, $def, $r['filas']);
        return ['filas' => $n, 'mensaje' => "Tabla '$tabla' creada con $n fila(s)"];
    }

    /**
     * Tipo de cada columna de salida que es una columna de una tabla del FROM,
     * por posición; null si la consulta no es un SELECT sencillo sobre tablas.
     *
     * @return array<int,array{type:string,length:?int,scale:?int}>
     */
    private function tiposDeOrigen(array $select, int $cuantas): array
    {
        if ($select['k'] !== 'select' || isset($select['with'])) {
            return [];
        }
        $porAlias = [];
        foreach ($select['from'] ?? [] as $o) {
            $nombre = $o['nombre'] ?? null;
            $porAlias[strtolower((string)($o['alias'] ?? $nombre))] = ($o['tipo'] ?? '') === 'tabla' && is_string($nombre)
                && $this->cat->existe($nombre) && !$this->cat->esVista($nombre) ? $this->meta($nombre) : null;
        }
        $tipo = static fn(array $c): array => ['type' => $c['type'], 'length' => $c['length'] ?? null, 'scale' => $c['scale'] ?? null];
        $tipos = [];
        foreach ($select['cols'] as $c) {
            if (!empty($c['star'])) {
                foreach ($porAlias as $alias => $meta) {
                    if ($c['tabla'] !== null && strcasecmp($alias, $c['tabla']) !== 0) {
                        continue;
                    }
                    if ($meta === null) {
                        return [];                     // un * sobre una subconsulta o vista: no se sabe cuántas
                    }
                    foreach ($meta['columns'] as $col) {
                        $tipos[] = $tipo($col);
                    }
                }
                continue;
            }
            $e = $c['expr'];
            $col = null;
            if ($e['k'] === 'col') {
                foreach ($porAlias as $alias => $meta) {
                    if ($meta === null || ($e['tabla'] !== null && strcasecmp($alias, $e['tabla']) !== 0)) {
                        continue;
                    }
                    $hallada = Catalog::columna($meta, $e['nombre']);
                    if ($hallada !== null) {
                        if ($col !== null) {
                            $col = null;               // ambigua: que decidan los valores
                            break;
                        }
                        $col = $hallada;
                    }
                }
            }
            $tipos[] = $col === null ? null : $tipo($col);
        }
        return count($tipos) === $cuantas ? array_filter($tipos) : [];
    }

    /** @return array{type:string,length:null,scale:null} */
    private static function tipoDeValores(array $valores): array
    {
        $tipo = null;
        foreach ($valores as $v) {
            $t = $v === null ? null : (is_int($v) || is_bool($v) ? Types::INTEGER : (is_float($v) ? Types::DOUBLE : Types::TEXT));
            if ($t === null || $t === $tipo) {
                continue;
            }
            if ($tipo === null) {
                $tipo = $t;
            } elseif ($t !== Types::TEXT && $tipo !== Types::TEXT) {
                $tipo = Types::DOUBLE;                // enteros y decimales
            } else {
                $tipo = Types::TEXT;
                break;
            }
        }
        return ['type' => $tipo ?? Types::TEXT, 'length' => null, 'scale' => null];
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

        // Comprobar que el cuerpo es analizable antes de guardarlo, y que un
        // SET NEW está donde puede estar
        $this->comprobarCuerpo($ast['trg'], $ast['trg']['body']);
        $this->cat->crearTrigger($ast['tabla'], $ast['trg']);
        unset($this->metas[$ast['tabla']]);
        return ['filas' => 0, 'mensaje' => "Trigger '$nombre' creado"];
    }

    /** @param list<string|array> $cuerpo */
    private function comprobarCuerpo(array $trg, array $cuerpo): void
    {
        foreach ($cuerpo as $paso) {
            if (is_array($paso)) {
                foreach ($paso['si'] as [$cond, $sentencias]) {
                    $this->analizarExpr($cond);
                    $this->comprobarCuerpo($trg, $sentencias);
                }
                $this->comprobarCuerpo($trg, $paso['sino'] ?? []);
            } elseif (strncasecmp(ltrim($paso), 'SET', 3) === 0) {
                if ($trg['timing'] !== 'BEFORE' || $trg['event'] === 'DELETE') {
                    throw JsonSqlDbError::syntax("SET NEW solo vale en un trigger BEFORE INSERT o BEFORE UPDATE ('{$trg['name']}')");
                }
                Parser::analizarSetNew($paso);
            } else {
                Parser::analizar($paso);
            }
        }
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

    /**
     * Las subconsultas de un UPDATE o un DELETE. Pueden mirar la fila que se
     * está escribiendo (`WHERE u.tid = t.id`), y entonces se ejecutan con ella;
     * las que no, se ejecutan una vez para toda la sentencia. Antes no podían
     * mirar fuera («Columna desconocida») y las demás se repetían para cada
     * fila. La memoria va por el texto de la subconsulta, no por su número:
     * los triggers ejecutan otras sentencias con este mismo Writer y sus
     * números podrían repetirse.
     *
     * @param array<string,string> $mapa columnas de la tabla que se escribe
     */
    private function subconsultas(array $mapa): callable
    {
        return function (array $sel, int $sid, array $filaExterna = []) use ($mapa): array {
            $clave = md5(serialize($sel));
            if (isset($this->subsFijas[$clave])) {
                return $this->subsFijas[$clave];
            }
            $marca = Evaluator::$correlacionada;
            Evaluator::$correlacionada = false;
            try {
                $filas = (new Select($this->cat, fn(string $t): array => $this->filas($t)))
                    ->ejecutarExterna($sel, $mapa, $filaExterna);
                $mira = Evaluator::$correlacionada;
            } finally {
                Evaluator::$correlacionada = $marca;
            }
            if (!$mira) {
                $this->subsFijas[$clave] = $filas;
            }
            return $filas;
        };
    }

    /** El conjunto de valores de una subconsulta con IN, para no recorrerla en cada fila. */
    private function conjuntos(callable $sub): callable
    {
        return function (array $sel, int $sid, array $filaExterna = []) use ($sub): array {
            $filas = $sub($sel, $sid, $filaExterna);
            $clave = md5(serialize($sel));
            if (isset($this->subsFijas[$clave]) && isset($this->conjuntosFijos[$clave])) {
                return $this->conjuntosFijos[$clave];
            }
            $valores = [];
            foreach ($filas as $fila) {
                $valores[] = reset($fila);
            }
            $c = Indexes::conjunto($valores);
            if (isset($this->subsFijas[$clave])) {
                $this->conjuntosFijos[$clave] = $c;
            }
            return $c;
        };
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
