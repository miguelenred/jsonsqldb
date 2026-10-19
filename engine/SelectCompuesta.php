<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * UNION, INTERSECT y EXCEPT, WITH y WITH RECURSIVE, y las colaciones que
 * hereda quien lee un resultado como una tabla (vistas, WITH y subconsultas
 * del FROM). Métodos de Select que reciben la consulta en $sel. Van aparte
 * para que una búsqueda sencilla no tenga que compilarlos (2.9): sin
 * OPcache, PHP compila en cada petición lo que carga. Hereda de Select solo
 * para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SelectCompuesta extends Select
{
    private function __construct()
    {
    }

    /**
     * Las filas de una consulta con nombre del WITH, con los nombres de su
     * lista de columnas si la tiene. Una que se nombra a sí misma, por tandas
     * (ver recursiva()); y mientras se calcula, ese nombre es la tanda en curso.
     *
     * @return array{cols: list<string>, filas: iterable<array>}
     */
    public static function delWith(Select $sel, string $clave): array
    {
        $ast = $sel->con[$clave];
        if ($ast['k'] === 'tanda') {
            return $ast;
        }
        // Una que acaba llamándose a sí misma a través de otra no termina nunca
        if (isset($sel->enCurso[$clave])) {
            throw JsonSqlDbError::syntax("Las consultas del WITH se nombran unas a otras en círculo ('$clave')");
        }
        $sel->enCurso[$clave] = true;
        try {
            if (isset($ast['recursiva'])) {
                return self::recursiva($sel, $clave, $ast);
            }
            $r = $sel->correrEntera($ast);           // como una subconsulta del FROM, también por el disco (2.9)
            return isset($ast['columnas'])
                ? ['cols' => $ast['columnas'], 'filas' => self::renombrar($r, $ast['columnas'], $clave), 'colaciones' => $r['colaciones'] ?? [],
                   'afinidades' => $r['afinidades'] ?? []]
                : $r;
        } finally {
            unset($sel->enCurso[$clave]);
        }
    }

    /**
     * WITH RECURSIVE (2.9), por tandas como PostgreSQL y MySQL: las partes que
     * no se nombran dan la primera tanda, y cada vuelta pasa a las recursivas
     * la tanda anterior entera como si fuera la tabla, hasta que no sale nada
     * nuevo. Con UNION se descarta lo que ya había salido, con lo que un ciclo
     * termina; con UNION ALL, la recursión acaba cuando lo diga su WHERE o su
     * LIMIT, y si no, la red de memoria (como en SQLite, no hay tope de
     * vueltas). Lo que se salta el OFFSET también sigue la recursión.
     *
     * @return array{cols: list<string>, filas: list<array>}
     */
    private static function recursiva(Select $sel, string $clave, array $ast): array
    {
        $p        = (int)$ast['recursiva'];
        $inicial  = $p === 1 ? $ast['partes'][0]
            : ['k' => 'union', 'partes' => \array_slice($ast['partes'], 0, $p), 'ops' => \array_slice($ast['ops'], 0, $p - 1),
               'order' => [], 'limit' => null, 'offset' => null];
        $distinto = $ast['ops'][$p - 1] === 'UNION';
        $tope     = $ast['limit'] === null ? null : $ast['limit'] + (int)$ast['offset'];
        $r        = $sel->correr($inicial);
        $cols     = $ast['columnas'] ?? $r['cols'];
        // Las columnas tienen la colación de las de la primera tanda (2.9), y su afinidad (2.10)
        $colaciones = $r['colaciones'] ?? [];
        $afinidades = $r['afinidades'] ?? [];
        $plegar   = array_filter($colaciones, static fn(?string $c): bool => $c !== null && $c !== 'BINARY');
        $todas    = [];
        $vistas   = [];
        $tanda    = function (array $r) use ($cols, $clave, $distinto, $tope, $plegar, &$todas, &$vistas): array {
            $nuevas = [];
            foreach (self::renombrar($r, $cols, $clave) as $fila) {
                if ($tope !== null && \count($todas) >= $tope) {
                    break;
                }
                if ($distinto) {
                    $k = self::claveFila($fila, $plegar);
                    if (isset($vistas[$k])) {
                        continue;
                    }
                    $vistas[$k] = true;
                }
                Memoria::comprobar('el WITH RECURSIVE');
                $todas[]  = $fila;
                $nuevas[] = $fila;
            }
            return $nuevas;
        };
        $nuevas = $tanda($r);
        try {
            while ($nuevas !== [] && ($tope === null || \count($todas) < $tope)) {
                $sel->con[$clave] = ['k' => 'tanda', 'cols' => $cols, 'filas' => $nuevas, 'colaciones' => $colaciones, 'afinidades' => $afinidades];
                $nuevas = [];
                foreach (\array_slice($ast['partes'], $p) as $parte) {
                    array_push($nuevas, ...$tanda($sel->correr($parte)));
                }
            }
        } finally {
            $sel->con[$clave] = $ast;
        }
        return ['cols' => $cols, 'filas' => \array_slice($todas, (int)$ast['offset'], $ast['limit']), 'colaciones' => $colaciones,
                'afinidades' => $afinidades];
    }

    /**
     * Las filas de un resultado con otros nombres de columna, por posición.
     *
     * @param array{cols: list<string>, filas: iterable<array>} $r
     * @param list<string> $cols
     */
    private static function renombrar(array $r, array $cols, string $nombre): \Generator
    {
        if (\count($r['cols']) !== \count($cols)) {
            throw JsonSqlDbError::syntax("'$nombre' nombra " . \count($cols) . ' columnas y su consulta da ' . \count($r['cols']));
        }
        foreach ($r['filas'] as $fila) {
            yield array_combine($cols, array_values($fila));
        }
    }

    /**
     * Ejecuta las partes de un UNION y las junta.
     *
     * Las columnas de salida son las de la primera parte: las demás aportan sus
     * valores en el mismo orden, aunque sus columnas se llamen distinto, que es
     * lo que hace SQL. La colación de cada columna es la de la primera parte
     * que la tiene (2.9), como en SQLite.
     *
     * @return array{cols: string[], filas: array, colaciones: array<int, string>}
     */
    public static function correrUnion(Select $sel, array $ast): array
    {
        if ($sel->sumidero !== null && $sel->nivel === $sel->nivelSumidero && $ast['order'] === []
            && array_diff($ast['ops'], ['UNION', 'UNION ALL']) === []) {
            return self::unionHacia($sel, $ast);
        }
        $cols       = [];
        $partes     = [];
        $colaciones = [];
        $afinidades = null;
        // Un UNION del FROM que se junta entero (ver Select::correrEntera()):
        // si una parte no cabe, no se repite ella por el disco, porque el UNION
        // la juntaría igual; se repite la consulta de fuera, que lo lleva a disco
        $juntando = $sel->juntando;

        foreach ($ast['partes'] as $i => $parte) {
            $sel->juntando = $sel->nivel === $juntando ? $juntando + 1 : $juntando;
            try {
                $r = $sel->correr($parte);
            } finally {
                $sel->juntando = $juntando;
            }

            if ($i === 0) {
                $cols = $r['cols'];
            }
            self::mismasColumnas($cols, \count($r['cols']));
            // La colación de cada columna (2.9), como en SQLite: la de la
            // primera parte, empezando por la izquierda, que tiene una
            foreach ($r['colaciones'] ?? [] as $j => $c) {
                $colaciones[$j] ??= $c;
            }
            $afinidades = self::afinidadesUnion($afinidades, $r);

            // Las columnas se toman por posición y se renombran a las de la
            // primera parte, aunque en su SELECT se llamen de otra forma
            $nuevas = [];
            foreach ($r['filas'] as $fila) {
                $valores = array_values($fila);
                $n       = [];
                foreach ($cols as $j => $nombre) {
                    $n[$nombre] = $valores[$j] ?? null;
                }
                $nuevas[] = $n;
            }
            $partes[] = $nuevas;
        }

        // Con ellas se decide qué filas son iguales
        $plegar     = self::plegarUnion($cols, $colaciones);
        $colaciones = array_replace(array_fill(0, \count($cols), null), $colaciones);
        $filas      = array_shift($partes);
        for ($i = 0; $partes !== []; $i++) {
            $nuevas = array_shift($partes);      // y deja de ocupar memoria en cuanto se usa
            switch ($ast['ops'][$i] ?? 'UNION') {
                case 'UNION ALL':                 // se conserva todo, repetidos incluidos
                    $filas = array_merge($filas, $nuevas);
                    break;

                case 'UNION':                     // lo de A o lo de B, sin repetir
                    $filas = self::sinRepetidos(array_merge($filas, $nuevas), $plegar);
                    break;

                case 'INTERSECT':                 // solo lo que está en las dos
                    $deB   = self::indice($nuevas, $plegar);
                    $filas = self::sinRepetidos(array_filter(
                        $filas,
                        static fn(array $f): bool => isset($deB[self::claveFila($f, $plegar)])
                    ), $plegar);
                    break;

                case 'EXCEPT':                    // lo de A que no está en B
                    $deB   = self::indice($nuevas, $plegar);
                    $filas = self::sinRepetidos(array_filter(
                        $filas,
                        static fn(array $f): bool => !isset($deB[self::claveFila($f, $plegar)])
                    ), $plegar);
                    break;
            }
        }

        $filas = self::ordenarUnion($sel, $filas, $cols, $ast['order'], $plegar);

        if ($ast['limit'] !== null || $ast['offset'] !== null) {
            $filas = \array_slice($filas, $ast['offset'] ?? 0, $ast['limit']);
        }

        return ['cols' => $cols, 'filas' => array_values($filas), 'colaciones' => $colaciones, 'afinidades' => $afinidades ?? []];
    }

    /**
     * La afinidad de cada columna de un UNION (2.10), como en SQLite: la de
     * sus partes si todas tienen la misma, y si no, ninguna ('').
     *
     * @param list<string>|null $hasta las de las partes anteriores (null: es la primera)
     * @return list<string>
     */
    private static function afinidadesUnion(?array $hasta, array $r): array
    {
        $suyas = array_values($r['afinidades'] ?? []);
        if ($hasta === null) {
            return $suyas;
        }
        foreach ($hasta as $j => $a) {
            if (($suyas[$j] ?? '') !== $a) {
                $hasta[$j] = '';
            }
        }
        return $hasta;
    }

    /**
     * Un UNION o UNION ALL sin ORDER BY cuyas filas van a un sumidero —el de
     * la consulta de fuera, o el de una subconsulta del FROM que va a disco
     * (ver Select::correrEntera())— (2.9): cada parte sale fila a fila según
     * se lee, sin juntar ninguna; con UNION, las ya vistas se descartan. Antes
     * se juntaban todas, y un UNION de dos tablas de un millón de filas no
     * cabía en 256 MB. Lo que haya hasta la última UNION sale sin repetidas
     * entre sí; lo de detrás de un UNION ALL final, tal cual, como al juntarlas.
     *
     * Para saber qué filas son iguales hace falta la colación de cada columna,
     * que es la de la primera parte que la tiene: con UNION se averigua antes,
     * ejecutando cada parte sin leer filas (LIMIT 0).
     *
     * @return array{cols: string[], filas: array, colaciones: array<int, string>}
     */
    private static function unionHacia(Select $sel, array $ast): array
    {
        $ultima = -1;                               // la última UNION: hasta su parte de la derecha, sin repetidas
        foreach ($ast['ops'] as $i => $op) {
            $ultima = $op === 'UNION' ? $i : $ultima;
        }
        $cols       = null;
        $colaciones = [];
        $afinidades = null;
        if ($ultima >= 0) {
            // Sin apuntarlo en el EXPLAIN (2.10: salía cada tabla dos veces,
            // la primera con 0 filas)
            $st    = $sel->cat->storage();
            $traza = $st->traza;
            $st->traza = null;
            try {
                foreach ($ast['partes'] as $parte) {
                    $r = $sel->correr(['limit' => 0, 'offset' => null] + $parte);
                    self::mismasColumnas($cols ??= $r['cols'], \count($r['cols']));
                    foreach ($r['colaciones'] ?? [] as $j => $c) {
                        $colaciones[$j] ??= $c;
                    }
                }
            } finally {
                $st->traza = $traza;
            }
        }
        $plegar   = self::plegarUnion($cols ?? [], $colaciones);
        $vistos   = [];
        $saltar   = (int)($ast['offset'] ?? 0);
        $quedan   = $ast['limit'] === null ? -1 : (int)$ast['limit'];
        $destino  = $sel->sumidero;
        $entregado = false;
        foreach ($ast['partes'] as $i => $parte) {
            $quitar = $ultima >= 0 && $i <= $ultima + 1;
            $poner  = static function (array $fila) use (&$cols, &$plegar, &$vistos, &$saltar, &$quedan, &$entregado, $destino, $quitar): void {
                if ($quedan === 0) {
                    return;                         // ya están todas (la parte sigue, pero no sale nada más)
                }
                $valores = array_values($fila);
                self::mismasColumnas($cols, \count($valores));
                $n = [];
                foreach ($cols as $j => $nombre) {
                    $n[$nombre] = $valores[$j] ?? null;
                }
                if ($quitar) {
                    $k = self::claveFila($n, $plegar);
                    if (isset($vistos[$k])) {
                        return;
                    }
                    $vistos[$k] = true;
                }
                if ($saltar > 0) {
                    $saltar--;
                    return;
                }
                $destino($n);
                $entregado = true;
                $quedan--;
            };
            $previo = [$sel->sumidero, $sel->nivelSumidero, $sel->entregado];
            $sel->sumidero      = static function (array $fila) use (&$cols, $poner): void {
                $cols ??= array_keys($fila);
                $poner($fila);
            };
            $sel->nivelSumidero = $sel->nivel + 1;
            try {
                $r = $sel->correr($parte);
            } finally {
                [$sel->sumidero, $sel->nivelSumidero, $sel->entregado] = $previo;
            }
            self::mismasColumnas($cols ??= $r['cols'], \count($r['cols']));
            foreach ($r['colaciones'] ?? [] as $j => $c) {
                $colaciones[$j] ??= $c;
            }
            $afinidades = self::afinidadesUnion($afinidades, $r);
            $plegar = self::plegarUnion($cols, $colaciones);
            foreach ($r['filas'] as $fila) {
                $poner($fila);                      // las que no salieron fila a fila
            }
        }
        $sel->entregado = $sel->entregado || $entregado;
        return ['cols' => $cols ?? [], 'filas' => [], 'colaciones' => array_replace(array_fill(0, \count($cols ?? []), null), $colaciones),
                'afinidades' => $afinidades ?? []];
    }

    /** Todas las partes de un UNION, con tantas columnas como la primera */
    private static function mismasColumnas(array $cols, int $n): void
    {
        if ($n !== \count($cols)) {
            throw JsonSqlDbError::syntax(
                'Todas las partes de un UNION tienen que devolver el mismo número de '
                . 'columnas: la primera devuelve ' . \count($cols) . " y otra devuelve $n"
            );
        }
    }

    /**
     * La colación con la que se comparan las filas de un UNION, por columna:
     * las que no son la binaria (ver correrUnion()).
     *
     * @return array<int, string>
     */
    private static function plegarUnion(array $cols, array $colaciones): array
    {
        return array_filter(array_replace(array_fill(0, \count($cols), null), $colaciones),
            static fn(?string $c): bool => $c !== null && $c !== 'BINARY');
    }

    /** @return array<string,true> */
    private static function indice(array $filas, array $plegar = []): array
    {
        $out = [];
        foreach ($filas as $f) {
            $out[self::claveFila($f, $plegar)] = true;
        }
        return $out;
    }

    private static function sinRepetidos(array $filas, array $plegar = []): array
    {
        $vistos = [];
        $out    = [];
        foreach ($filas as $f) {
            $k = self::claveFila($f, $plegar);
            if (isset($vistos[$k])) {
                continue;
            }
            $vistos[$k] = true;
            $out[]      = $f;
        }
        return $out;
    }

    /**
     * ORDER BY de un UNION. Solo admite nombres de columna del resultado o su
     * posición (1, 2...): en ese punto las tablas de origen ya no existen.
     *
     * @param string[] $cols
     * @param array<int, string> $plegar la colación de las columnas que la tienen, por posición (2.9)
     */
    private static function ordenarUnion(Select $sel, array $filas, array $cols, array $orden, array $plegar): array
    {
        if ($orden === [] || $filas === []) {
            return $filas;
        }

        $claves     = [];
        $colaciones = [];
        $binarias   = [];
        foreach ($orden as $o) {
            // Con la colación de la columna (2.9), o la escrita con COLLATE:
            // el analizador la deja como PLEGAR(COLLATE(x)), o COLLATE(x) si es BINARY
            $e = $o['expr'];
            $c = false;
            if ($e['k'] === 'fn' && $e['nombre'] === 'PLEGAR') {
                $e = $e['args'][0];
            }
            if ($e['k'] === 'fn' && $e['nombre'] === 'COLLATE') {
                [$e, $c] = [$e['args'][0], (string)$e['args'][1]['v']];
            }
            // El nombre, sin distinguir mayúsculas, como en un SELECT (2.9)
            $j = $e['k'] === 'col' && $e['tabla'] === null ? array_search(strtolower($e['nombre']), array_map('strtolower', $cols), true)
               : ($e['k'] === 'lit' && \is_int($e['v']) && isset($cols[$e['v'] - 1]) ? $e['v'] - 1 : false);
            if ($j === false) {
                throw JsonSqlDbError::syntax(
                    'El ORDER BY de un UNION solo admite nombres de columna del resultado o su '
                    . 'posición (1, 2...)'
                );
            }
            $binarias[] = $c === 'BINARY';             // escrita: byte a byte, sin la colación configurada (2.10)
            $c = $c === false ? ($plegar[$j] ?? null) : $c;
            $claves[]     = $cols[$j];
            $colaciones[] = $c === 'BINARY' ? null : $c;
        }

        $indices = array_keys($filas);
        usort($indices, static function (int $a, int $b) use ($filas, $claves, $orden, $colaciones, $binarias): int {
            foreach ($claves as $i => $col) {
                $x = $filas[$a][$col] ?? null;
                $y = $filas[$b][$col] ?? null;
                if ($colaciones[$i] !== null) {
                    [$x, $y] = [Functions::plegar($x, $colaciones[$i]), Functions::plegar($y, $colaciones[$i])];
                }
                $c = Valor::compararOrden($x, $y, $binarias[$i]);
                if ($c !== 0) {
                    return $orden[$i]['dir'] === 'DESC' ? -$c : $c;
                }
            }
            return $a <=> $b;                        // orden estable
        });

        $out = [];
        foreach ($indices as $i) {
            $out[] = $filas[$i];
        }
        return $out;
    }

    /**
     * Las columnas (en minúsculas) de un resultado que tienen colación (2.9),
     * con la suya, y las que tienen afinidad (2.10), con la suya: las hereda
     * quien lo lee como una tabla.
     *
     * @param array{cols: list<string>, colaciones?: list<?string>, afinidades?: list<string>} $r
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function heredadas(array $r): array
    {
        $colaciones = [];
        $afinidades = [];
        $cols = array_values($r['cols']);
        foreach ($r['colaciones'] ?? [] as $i => $c) {
            if ($c !== null && $c !== 'BINARY' && isset($cols[$i])) {
                $colaciones[strtolower((string)$cols[$i])] ??= $c;
            }
        }
        foreach ($r['afinidades'] ?? [] as $i => $a) {
            if ($a !== '' && isset($cols[$i])) {
                $afinidades[strtolower((string)$cols[$i])] ??= $a;
            }
        }
        return [$colaciones, $afinidades];
    }
}
