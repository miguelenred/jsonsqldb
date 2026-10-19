<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * El JOIN que busca cada fila de la izquierda en un índice de la tabla de la
 * derecha, y FROM a, b WHERE … como un JOIN (ver comasAJoin()) (2.10, aparte
 * de Select para que una consulta sin JOIN no tenga que compilarlo; el JOIN
 * por tabla hash está en SelectJoin).
 *
 * Métodos de Select que reciben la consulta en $sel. Hereda de Select solo
 * para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SelectJoinIndice extends Select
{
    /**
     * Cruce por índice (ver unirPorIndice()): hasta cuántas filas del lado
     * izquierdo se leen para decidirlo, y cuántas filas de la tabla de la
     * derecha vale una búsqueda por clave frente a leerla entera: una
     * búsqueda cuesta como decodificar unas 150 filas.
     */
    private const CRUCE_POR_INDICE_MAX    = 1000;
    private const CRUCE_POR_INDICE_FACTOR = 150;

    /**
     * FROM a, b WHERE b.x = a.y (2.9): las igualdades del WHERE entre una
     * tabla de la coma y las que van antes se ponen también en su ON, y se
     * cruzan por hash como un JOIN … ON. Antes se cruzaba cada fila con todas
     * y luego se filtraba: con 20.000 y 10.000 filas, 76 s frente a 40 ms. El
     * WHERE sigue entero (volver a comprobarlas no cambia nada). Solo entre
     * columnas con su tabla delante, y no si hay un RIGHT o un FULL JOIN, por
     * lo mismo que en Select::empujarWhere(). Quien llama ya ha visto que hay WHERE
     * y más de un origen: aquí, y no en Select, para que una consulta sin
     * JOIN no lo compile (2.10).
     */
    public static function comasAJoin(array $from, array $where): array
    {
        $alias = [];
        foreach ($from as $o) {
            if (\in_array($o['join'] ?? '', ['RIGHT', 'FULL'], true)) {
                return $from;
            }
            $alias[] = strtolower((string)($o['alias'] ?? $o['nombre']));
        }
        $iguales = array_values(array_filter(IndexesBusqueda::conjunciones($where), static fn(array $c): bool =>
            $c['k'] === 'bin' && $c['op'] === '=' && $c['i']['k'] === 'col' && $c['d']['k'] === 'col'
            && $c['i']['tabla'] !== null && $c['d']['tabla'] !== null));
        for ($i = 1, $n = \count($from); $i < $n; $i++) {
            if (($from[$i]['join'] ?? null) !== 'CROSS' || $from[$i]['on'] !== null) {
                continue;
            }
            $antes = array_flip(\array_slice($alias, 0, $i));
            $on    = null;
            foreach ($iguales as $c) {
                $ti = strtolower((string)$c['i']['tabla']);
                $td = strtolower((string)$c['d']['tabla']);
                if (($ti === $alias[$i] && isset($antes[$td])) || ($td === $alias[$i] && isset($antes[$ti]))) {
                    $on = $on === null ? $c : ['k' => 'bin', 'op' => 'AND', 'i' => $on, 'd' => $c];
                }
            }
            if ($on !== null) {
                $from[$i]['join'] = 'INNER';
                $from[$i]['on']   = $on;
            }
        }
        return $from;
    }

    /**
     * Cruce por índice: cuando el lado izquierdo tiene pocas filas —un JOIN
     * detrás de un WHERE que deja una o unas decenas— y la tabla de la
     * derecha tiene un índice sobre las columnas que iguala el ON, cargarla
     * entera para casar unas pocas filas es lo más caro de la consulta. En su
     * lugar se busca cada fila de la izquierda por su clave en el índice.
     * Vale para INNER y LEFT. Devuelve null si no se dan las condiciones, y
     * entonces el cruce es el de siempre; el lado izquierdo, que se ha
     * empezado a leer para contarlo, se devuelve entero en $izq.
     *
     * @param iterable<mixed> $izq
     * @return array{0: \Generator, 1: list<array{0: string, 1: string, 2: string}>}|null
     */
    public static function unirPorIndice(Select $sel, iterable &$izq, array $fuentesIzq, array $o, ?array $usadas, ?int $izqConocida = null): ?array
    {
        $tipo = $o['join'] ?? 'CROSS';
        if (!$sel->indexable || ($tipo !== 'INNER' && $tipo !== 'LEFT') || $o['on'] === null || !empty($o['posicion'])
            || $o['tipo'] !== 'tabla' || isset($sel->con[strtolower($o['nombre'])])
            || $sel->cat->esVista($o['nombre'])
            || !$sel->cat->existe($o['nombre'])) {
            return null;
        }
        $st = $sel->cat->storage();
        if (!$st->indicesActivos()) {
            return null;
        }
        $nombre = $o['nombre'];
        $alias  = $o['alias'] ?? $nombre;
        [$cols, $fuentesDer] = $sel->fuentesDeTabla($nombre, $alias, $usadas);
        $clavesDer = array_column($fuentesDer, 2);

        $mapa = $sel->mapaColumnas(array_merge($fuentesIzq, $fuentesDer));
        $on   = Evaluator::resolver($o['on'], $mapa, [], $sel->externo['mapa'] ?? []);
        [$pares, $resto] = $sel->igualdades($on, array_flip($clavesDer));
        if ($pares === []) {
            return null;
        }
        // columna interna => clave externa con la que se iguala, y su colación.
        // Una de texto que se iguala con números (2.10: modo 'N', ver
        // igualdades()) no se busca en su índice: '7' y '007' son el 7 y
        // tienen claves distintas. Va con las demás, fila a fila
        $porColumna = [];
        $colacion   = [];
        $afinidades = Catalog::afinidades($sel->cat->meta($nombre));
        foreach ($pares as [$claveExt, $claveInt, $c, $m]) {
            $col = substr($claveInt, \strlen($alias) + 1);
            if (!isset($porColumna[$col]) && !($m !== 'T' && ($afinidades[strtolower($col)] ?? null) === 'T')) {
                [$porColumna[$col], $colacion[$col]] = [$claveExt, $c];
            }
        }
        // El índice que más columnas del ON cubre, por la izquierda. Una
        // igualdad con colación (2.9) solo la cubre un índice con la misma: en
        // otro no estarían 'Ana' y 'ANA' bajo la misma clave. Una binaria, uno
        // con colación también: da de más, y se comprueba fila a fila
        $def      = null;
        $externas = [];
        foreach ($sel->cat->indicesDe($nombre) as $d) {
            $cubre = [];
            foreach ($d['columns'] as $k => $c) {
                if (!isset($porColumna[$c]) || ($colacion[$c] !== null && $colacion[$c] !== ($d['plegar'][$k] ?? null))) {
                    break;
                }
                $cubre[] = $porColumna[$c];
            }
            if (\count($cubre) > \count($externas) && Indexes::sirve($d, \count($cubre))) {
                $def      = $d;
                $externas = $cubre;
            }
        }
        if ($def === null) {
            return null;
        }
        // Las igualdades del ON que el índice no cubre se comprueban en cada
        // candidata, como el resto de la condición
        $otrasIgualdades = [];
        $plegar = $def['plegar'] ?? [];
        foreach ($pares as [$claveExt, $claveInt, $c, $m]) {
            // Con COLLATE en el índice (2.9), sus claves van plegadas: lo que
            // da es de más, y todas las igualdades se comprueban fila a fila
            if ($plegar !== [] || !\in_array($claveExt, $externas, true)
                || ($porColumna[substr($claveInt, \strlen($alias) + 1)] ?? null) !== $claveExt) {
                $otrasIgualdades[] = [$claveExt, $claveInt, $c, $m];
            }
        }

        // ¿Cuántas filas hay a la izquierda? Compensa buscar mientras sean
        // pocas para lo grande que es la tabla: se leen como mucho esas, y si
        // hay más, se sigue por el camino de siempre sin haber guardado nada más
        $internas = StorageLectura::filasSegunRevision($st, $nombre);
        $tope     = $internas === null ? 0 : min(self::CRUCE_POR_INDICE_MAX, intdiv($internas, self::CRUCE_POR_INDICE_FACTOR));
        if ($tope < 1 || ($izqConocida !== null && $izqConocida > $tope)) {
            return null;
        }
        $it = $izq instanceof \Iterator ? $izq : new \ArrayIterator(\is_array($izq) ? $izq : iterator_to_array($izq, false));
        $it->rewind();
        $filasIzq = [];
        $agotado  = true;
        while ($it->valid()) {
            $filasIzq[] = $it->current();
            $it->next();
            if (\count($filasIzq) > $tope) {
                $agotado = false;
                break;
            }
        }
        if (!$agotado) {
            // No compensa: el lado izquierdo sigue por el camino de siempre,
            // con lo ya leído por delante y lo que quede detrás
            $izq = (static function () use ($filasIzq, $it) {
                yield from $filasIzq;
                while ($it->valid()) {
                    yield $it->current();
                    $it->next();
                }
            })();
            return null;
        }

        $prefijo   = \count($externas) < \count($def['columns']);
        $nulos     = array_fill_keys($clavesDer, null);
        $condicion = $resto === null ? null : (EvaluatorCompilado::compilar($resto) ?? $resto);
        [$sub, $conjunto] = $sel->subconsultasDelOn();
        $lector = $sel->lector;
        $paso = $st->traza !== null ? SelectAvanzada::apuntar($sel, $nombre, 'JOIN, buscando cada fila en el índice', $def['name'], 0) : null;
        $gen = (function () use ($sel, $filasIzq, $externas, $otrasIgualdades, $def, $nombre, $alias, $cols, $prefijo, $tipo, $nulos, $condicion, $st, $sub, $conjunto, $lector, $paso, $plegar) {
            foreach ($filasIzq as $ext) {
                $valores = [];
                foreach ($externas as $i => $claveExt) {
                    // Con la afinidad de la columna del índice (2.10): 7 en una de texto se busca como '7'
                    $v = Valor::conAfinidad($ext[$claveExt] ?? null, $def['afin'][$i] ?? null);
                    $valores[] = isset($plegar[$i]) ? Functions::plegar($v, $plegar[$i]) : $v;
                }
                $clave = Indexes::clave($valores);            // null si algún valor es NULL: no iguala nada
                $candidatas = $clave === null ? [] : StorageLectura::filasPorIndice($st, $nombre, $def, [$clave], $prefijo);
                $leidas     = \count($candidatas ?? []);
                if ($candidatas === null) {
                    // El índice no sirve, o la clave está en demasiadas partes:
                    // para esta fila se recorre la tabla
                    $candidatas = [];
                    foreach ($lector($nombre) as $fila) {
                        $leidas++;
                        $suya = Indexes::claveFila($fila, $def['columns'], $plegar);
                        if ($suya !== null && ($prefijo ? strncmp($suya, $clave, \strlen($clave)) === 0 : $suya === $clave)) {
                            $candidatas[] = $fila;
                        }
                    }
                }
                if ($paso !== null) {
                    $st->traza[$paso]['filas'] += $leidas;
                }
                $encontrada = false;
                foreach ($candidatas as $int) {
                    $plana = [];
                    foreach ($cols as $c) {
                        $plana[$alias . '.' . $c] = $int[$c] ?? null;
                    }
                    $fila = $ext + $plana;
                    foreach ($otrasIgualdades as [$ke, $ki, $c, $m]) {
                        if ($fila[$ke] === null || $fila[$ki] === null) {
                            continue 2;
                        }
                        // Como textos si iguala así (2.10, ver igualdades())
                        [$x, $y] = $m === 'T' ? [Valor::conAfinidad($fila[$ke], 'T'), Valor::conAfinidad($fila[$ki], 'T')] : [$fila[$ke], $fila[$ki]];
                        if (Valor::comparar($c === null ? $x : Functions::plegar($x, $c), $c === null ? $y : Functions::plegar($y, $c)) !== 0) {
                            continue 2;
                        }
                    }
                    if ($condicion !== null) {
                        $vale = $condicion instanceof \Closure
                            ? Valor::verdadero($condicion($fila))
                            : Valor::verdadero(Evaluator::evaluar($condicion, ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto,
                                                                                 'filaExterna' => $sel->externo['fila'] ?? []]));
                        if ($vale !== true) {
                            continue;
                        }
                    }
                    Memoria::comprobar('el JOIN');
                    yield $fila;
                    $encontrada = true;
                }
                if (!$encontrada && $tipo === 'LEFT') {
                    yield $ext + $nulos;
                }
            }
        })();
        return [$gen, $fuentesDer];
    }
}
