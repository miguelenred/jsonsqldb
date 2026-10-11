<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que solo usan algunas consultas y no tiene clase propia: las funciones
 * de ventana, EXPLAIN y la columna POSICION (UPDATE y DELETE con JOIN). El
 * ORDER BY sin índice está en SelectOrden; GROUP BY, en SelectAgrupada; UNION
 * y WITH, en SelectCompuesta, y el JOIN por tabla hash, en SelectJoin.
 * Métodos de Select que reciben la consulta en $sel. Van aparte para que una
 * búsqueda sencilla no tenga que compilarlos (2.9): sin OPcache, PHP compila
 * en cada petición lo que carga. Hereda de Select solo para llegar a lo
 * protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SelectAvanzada extends Select
{
    private function __construct()
    {
    }

    // ==================================================================
    // EXPLAIN y la columna POSICION
    // ==================================================================

    /**
     * EXPLAIN (2.9): apunta cómo se leyó una tabla y devuelve el número de su
     * paso (ver DatabaseAvanzada::explicar()).
     */
    public static function apuntar(Select $sel, string $tabla, string $acceso, ?string $indice, ?int $filas): int
    {
        $st = $sel->cat->storage();
        $st->traza[] = ['paso' => count($st->traza) + 1, 'tabla' => $tabla, 'acceso' => $acceso, 'indice' => $indice, 'filas' => $filas];
        return count($st->traza) - 1;
    }

    /** EXPLAIN: las filas de una lectura, contándolas al pasar (las que se usan, no más) */
    public static function contando(Select $sel, int $paso, iterable $filas): \Generator
    {
        $st = $sel->cat->storage();
        foreach ($filas as $k => $f) {
            $st->traza[$paso]['filas']++;
            yield $k => $f;
        }
    }

    /**
     * Las filas de una tabla con su posición en la columna POSICION. Solo por
     * los caminos que la conocen: el índice que acote las posiciones, o la
     * tabla entera.
     *
     * @param list<array> $predicados los del WHERE sobre esta tabla
     */
    public static function conPosicion(Select $sel, string $nombre, array $predicados): \Generator
    {
        $filas = null;
        $st    = $sel->cat->storage();
        if ($sel->indexable && $predicados !== [] && $st->indicesActivos()) {
            foreach (IndexesBusqueda::candidatos($sel->cat->indicesDe($nombre), $predicados) as $c) {
                $posiciones = $st->posicionesPorIndice($nombre, $c['def'], $c['claves'], $c['prefijo']);
                if ($posiciones !== null) {
                    $filas = StorageCambios::filasEnPosiciones($st, $nombre, $posiciones);
                    break;
                }
            }
        }
        foreach ($filas ?? ($sel->lector)($nombre) as $p => $fila) {
            $fila[self::POSICION] = $p;
            yield $fila;
        }
    }

    // ==================================================================
    // Funciones de ventana (2.9)
    // ==================================================================

    /**
     * Saca las funciones de ventana de una expresión a $ventanas. En su sitio
     * queda la misma con su número ('i'), y Evaluator lee su valor del
     * contexto. La misma escrita dos veces se calcula una vez. Las de una
     * subconsulta son suyas.
     *
     * @param array<string, array> $ventanas por su firma
     */
    public static function apartarVentanas(array $n, array &$ventanas): array
    {
        if (($n['k'] ?? null) === 'ventana') {
            $firma = md5(serialize($n));
            $ventanas[$firma] ??= $n + ['i' => count($ventanas)];
            return $ventanas[$firma];
        }
        foreach ($n as $k => $v) {
            if (is_array($v) && $k !== 'select') {
                $n[$k] = self::apartarVentanas($v, $ventanas);
            }
        }
        return $n;
    }

    /**
     * Las funciones de ventana de lo que no cupo en memoria (2.9; antes, con
     * un millón de filas, no cabían en 256 MB). Cada fila base —o grupo— va a
     * un temporal, y en memoria solo quedan, por columnas, los valores de las
     * expresiones que usan las ventanas. Después salen del temporal en el
     * orden de la primera ventana (ordenadas en disco por su posición en ella,
     * ver OrdenExterno), cada una con su valor de cada función.
     *
     * Un error de tipo al evaluar una expresión se guarda como su valor y se
     * lanza si la ventana lo usa, como en memoria, que solo evalúa lo que usa.
     *
     * @param iterable<array>      $contextos
     * @param array<string, array> $ventanas ver apartarVentanas()
     * @return \Generator<int, array> los contextos, con sus ventanas
     */
    public static function ventanasEnDisco(Select $sel, iterable $contextos, array $ventanas): \Generator
    {
        // Cada expresión que usan, con el nombre de su columna de valores. Las
        // de PARTITION BY y ORDER BY se evalúan siempre en todas las filas
        $exprs    = [];
        $terminos = [];
        $nombrar  = static function (array $e, bool $termino = false) use (&$exprs, &$terminos): array {
            $id         = md5(serialize($e));
            $exprs[$id] = $e;
            if ($termino) {
                $terminos[$id] = true;
            }
            return ['pre' => $id] + $e;
        };
        foreach ($ventanas as $f => $w) {
            foreach ($w['def']['particion'] as $j => $e) {
                $w['def']['particion'][$j] = $nombrar($e, true);
            }
            foreach ($w['def']['orden'] as $j => $o) {
                $w['def']['orden'][$j]['expr'] = $nombrar($o['expr'], true);
            }
            $w['args']    = array_map($nombrar, $w['args']);
            $w['filtro']  = $w['filtro'] === null ? null : $nombrar($w['filtro']);
            $ventanas[$f] = $w;
        }
        $columnas = array_fill_keys(array_keys($exprs), []);
        $carpeta  = $sel->cat->storage()->carpetaOrden();
        $volcado  = new Volcado($carpeta);
        $externo  = null;
        $comun    = [];                             // sub, conjunto, filaExterna: los mismos en todas
        $n        = 0;
        try {
            foreach ($contextos as $ctx) {
                Memoria::comprobar('las funciones de ventana');
                foreach ($exprs as $id => $e) {
                    try {
                        $columnas[$id][] = Evaluator::evaluar($e, $ctx);
                    } catch (JsonSqlDbError $x) {
                        if ($x->sqlState !== 'TYPE' || isset($terminos[$id])) {
                            throw $x;
                        }
                        $columnas[$id][] = $x;
                    }
                }
                $volcado->anadir([$ctx['fila'], $ctx['agregados'] ?? null]);
                if ($n++ === 0) {
                    $comun = array_diff_key($ctx, ['fila' => 0, 'agregados' => 0]);
                }
            }
            [$secuencia, $valores] = self::ventanas($ventanas, $n,
                static fn(array $e): array => $columnas[$e['pre']],
                static function (array $e, int $k) use (&$columnas) {
                    $v = $columnas[$e['pre']][$k];
                    if ($v instanceof JsonSqlDbError) {
                        throw $v;
                    }
                    return $v;
                });
            unset($columnas);
            // La posición de cada fila en la secuencia, si no es la de llegada
            $posicion = [];
            if ($secuencia !== array_keys($secuencia)) {
                $posicion = array_fill(0, $n, 0);
                foreach ($secuencia as $p => $k) {
                    $posicion[$k] = $p;
                }
                $externo = new OrdenExterno([['dir' => 'ASC']], $carpeta);
            }
            unset($secuencia);
            $contexto = static function (array $fila, ?array $agregados, array $v) use ($comun): array {
                return ['fila' => $fila, 'ventanas' => $v] + ($agregados === null ? [] : ['agregados' => $agregados]) + $comun;
            };
            $k = 0;
            foreach ($volcado->leer() as [$fila, $agregados]) {
                $v = [];
                foreach ($valores as $i => $lista) {
                    $v[$i] = $lista[$k];
                }
                if ($externo === null) {
                    yield $contexto($fila, $agregados, $v);
                } else {
                    $externo->anadir([$posicion[$k]], [$fila, $agregados, $v]);
                }
                $k++;
            }
            unset($valores, $posicion);
            foreach ($externo === null ? [] : $externo->filas() as [$fila, $agregados, $v]) {
                yield $contexto($fila, $agregados, $v);
            }
        } finally {
            $volcado->borrar();
            $externo?->borrar();
        }
    }

    /**
     * El valor de cada función de ventana en cada fila base (su contexto: la
     * fila, o el grupo con sus agregados). Como en SQLite, se calculan de la
     * última a la primera y cada una deja las filas ordenadas por su
     * PARTITION BY y su ORDER BY, sin mover las que empatan: sin ORDER BY en
     * la consulta, salen en el orden de la primera.
     *
     * Las filas base son 0..$n-1: $columna da los valores de una expresión
     * en todas, y $valor el de una, evaluándolos en memoria o ya calculados
     * (ver ventanasEnDisco()). Los valores van por columnas, una lista por
     * función (2.9: un array por fila, que con un millón de filas eran
     * cientos de MB).
     *
     * @param array<string, array> $ventanas ver apartarVentanas()
     * @param \Closure(array): list<mixed> $columna
     * @param \Closure(array, int): mixed  $valor
     * @return array{0: list<int>, 1: array<int, list<mixed>>} el orden de las filas y, por función, su valor en cada fila
     */
    public static function ventanas(array $ventanas, int $n, \Closure $columna, \Closure $valor): array
    {
        $secuencia = $n > 0 ? range(0, $n - 1) : [];
        $ordenada  = false;                         // si ya no es la de llegada
        $valores   = [];
        foreach (array_reverse($ventanas) as $w) {
            $terminos = [];
            foreach ($w['def']['particion'] as $e) {
                $terminos[] = ['expr' => $e, 'dir' => 'ASC'];
            }
            $terminos = array_merge($terminos, $w['def']['orden']);
            $claves   = [];                       // por término y fila
            foreach ($terminos as $t => $o) {
                $claves[$t] = $columna($o['expr']);
            }
            if ($terminos !== []) {
                // Por posición en la secuencia: el desempate es el orden que ya traen
                $porPosicion = $claves;
                if ($ordenada) {
                    foreach (array_keys($terminos) as $t) {
                        $porPosicion[$t] = [];
                        foreach ($secuencia as $k) {
                            $porPosicion[$t][] = $claves[$t][$k];
                        }
                    }
                }
                $posiciones = self::ordenarAcotado($terminos, $porPosicion, $n);
                unset($porPosicion);
                foreach ($posiciones as $i => $p) {
                    $posiciones[$i] = $secuencia[$p];
                }
                $secuencia = $posiciones;
                unset($posiciones);
                $ordenada  = true;
            }
            // Las particiones, por el valor exacto (como GROUP BY), en ese orden
            $np = count($w['def']['particion']);
            $particiones = $np === 0 ? [$secuencia] : [];
            foreach ($np === 0 ? [] : $secuencia as $k) {
                $pk = '';
                for ($t = 0; $t < $np; $t++) {
                    $pk .= Valor::clave($claves[$t][$k]) . "\0";
                }
                $particiones[$pk][] = $k;
            }
            $valores[$w['i']] = $n > 0 ? array_fill(0, $n, null) : [];
            foreach ($particiones as $filas) {
                self::ventanaEn($w, $filas, $claves, $np, $valor, $valores[$w['i']]);
            }
        }
        return [$secuencia, $valores];
    }

    /**
     * Las posiciones 0..$n-1 ordenadas por $terminos (estable), sin agotar la
     * memoria (2.9): array_multisort() pide de golpe unos 32 bytes por fila y
     * array, y con un millón de filas era el fatal. Si no cabe en la memoria
     * que PHP aún puede pedir, se ordena por trozos que caben y se mezclan; a
     * igualdad, la posición.
     *
     * @param array<int, list<mixed>> $columnas por término
     * @return list<int>
     */
    private static function ordenarAcotado(array $terminos, array $columnas, int $n): array
    {
        $limite  = Memoria::limiteEnBytes();
        // Hasta tres arrays por término (NULL, colación, valores), sus copias y las posiciones
        $porFila = 64 * (3 * count($terminos) + 2);
        $libre   = $limite - memory_get_usage(true) - 2 * 1048576;
        $trozo   = $limite > 0 ? max(1024, intdiv($libre, 2 * $porFila)) : $n;
        if ($trozo >= $n) {
            $todas = $n > 0 ? range(0, $n - 1) : [];
            return SelectOrden::ordenNativo($terminos, $columnas, $todas)
                ?? SelectOrden::todasOrdenadas($todas, SelectOrden::comparadorDe($terminos, $columnas));
        }
        $comparar = SelectOrden::comparadorDe($terminos, $columnas);
        // Cada trozo ordenado, y en el montón la cabeza de cada uno: arriba la menor
        $tramos = [];
        $monton = SelectOrden::monton(static fn(array $a, array $b): int => $comparar($b[0], $a[0]));
        for ($desde = 0; $desde < $n; $desde += $trozo) {
            if (Memoria::cada() !== PHP_INT_MAX) {
                Memoria::revisar('las funciones de ventana');
            }
            $parte = [];
            foreach ($columnas as $t => $col) {
                $parte[$t] = array_slice($col, $desde, $trozo);
            }
            $todas = range(0, count($parte[0]) - 1);
            $orden = SelectOrden::ordenNativo($terminos, $parte, $todas)
                ?? SelectOrden::todasOrdenadas($todas, SelectOrden::comparadorDe($terminos, $parte));
            unset($parte);
            foreach ($orden as $i => $p) {
                $orden[$i] = $p + $desde;
            }
            $monton->insert([$orden[0], count($tramos), 0]);
            $tramos[] = $orden;
        }
        $salida = [];
        while (!$monton->isEmpty()) {
            Memoria::comprobar('las funciones de ventana');
            [$p, $t, $i] = $monton->extract();
            $salida[] = $p;
            if (isset($tramos[$t][++$i])) {
                $monton->insert([$tramos[$t][$i], $t, $i]);
            }
        }
        return $salida;
    }

    /**
     * Una función de ventana en una partición, con sus filas ya en el orden
     * de la ventana. Deja en $out el valor de cada una.
     *
     * @param list<int> $filas
     * @param array<int, array<int, mixed>> $claves por término (PARTITION BY y ORDER BY) y fila
     * @param \Closure(array, int): mixed $valor ver ventanas()
     * @param array<int, mixed> $out por fila
     */
    private static function ventanaEn(array $w, array $filas, array $claves, int $np, \Closure $valor, array &$out): void
    {
        $n   = count($filas);
        $def = $w['def'];
        // Los pares: filas seguidas con el mismo ORDER BY (sin él, todas).
        // Las que solo cuentan filas no los usan
        $grupo = $ini = $fin = [];
        $g = -1;
        $pares = in_array($w['nombre'], ['ROW_NUMBER', 'NTILE', 'LAG', 'LEAD'], true) ? 0 : $n;
        for ($p = 0; $p < $pares; $p++) {
            Memoria::comprobar('las funciones de ventana');
            $nuevo = $p === 0;
            for ($t = $np, $nt = count($claves); !$nuevo && $t < $nt; $t++) {
                $nuevo = Valor::compararOrden($claves[$t][$filas[$p]], $claves[$t][$filas[$p - 1]]) !== 0;
            }
            if ($nuevo) {
                $ini[++$g] = $p;
            }
            $grupo[$p] = $g;
            $fin[$g]   = $p + 1;
        }
        $arg = static fn(int $j, int $p) => $valor($w['args'][$j], $filas[$p]);
        switch ($w['nombre']) {
            case 'ROW_NUMBER':
                foreach ($filas as $p => $k) { $out[$k] = $p + 1; }
                return;
            case 'RANK':
                foreach ($filas as $p => $k) { $out[$k] = $ini[$grupo[$p]] + 1; }
                return;
            case 'DENSE_RANK':
                foreach ($filas as $p => $k) { $out[$k] = $grupo[$p] + 1; }
                return;
            case 'PERCENT_RANK':
                foreach ($filas as $p => $k) { $out[$k] = $n > 1 ? $ini[$grupo[$p]] / ($n - 1) : 0.0; }
                return;
            case 'CUME_DIST':
                foreach ($filas as $p => $k) { $out[$k] = $fin[$grupo[$p]] / $n; }
                return;
            case 'NTILE':
                // El reparto de SQLite: los primeros grupos llevan una fila más
                $v = $arg(0, 0);
                $partes = Valor::esNumerico($v) ? Valor::entero(Valor::aNumero($v)) : 0;
                if ($partes < 1) {
                    throw JsonSqlDbError::type('El argumento de NTILE tiene que ser un entero positivo');
                }
                $tam     = intdiv($n, $partes);
                $grandes = $n - $partes * $tam;
                $ultima  = $grandes * ($tam + 1);
                foreach ($filas as $p => $k) {
                    $out[$k] = $tam === 0 ? $p + 1 : ($p < $ultima ? intdiv($p, $tam + 1) + 1 : intdiv($p - $ultima, $tam) + 1 + $grandes);
                }
                return;
            case 'LAG':
            case 'LEAD':
                // Un desplazamiento que no es un entero da NULL, como en SQLite
                foreach ($filas as $p => $k) {
                    $d = count($w['args']) > 1 ? $arg(1, $p) : 1;
                    $d = Valor::esNumerico($d) ? Valor::aNumero($d) : null;
                    if ($d === null || (is_float($d) && floor($d) !== $d)) {
                        $out[$k] = null;
                        continue;
                    }
                    $j = $w['nombre'] === 'LAG' ? $p - Valor::entero($d) : $p + Valor::entero($d);
                    $out[$k] = $j >= 0 && $j < $n ? $arg(0, $j) : (count($w['args']) > 2 ? $arg(2, $p) : null);
                }
                return;
        }

        // Las demás van por el marco
        $marco = $def['marco'] ?? ['tipo' => 'RANGE', 'inicio' => ['k' => 'UNBOUNDED PRECEDING'], 'fin' => ['k' => 'CURRENT ROW'], 'excluir' => 'NO OTHERS'];
        $t = null;
        if ($marco['tipo'] === 'RANGE' && (isset($marco['inicio']['n']) || isset($marco['fin']['n']))) {
            // El valor de la expresión del ORDER BY, con el signo de su sentido;
            // NULL, o un texto, no tiene distancia: su marco son sus pares
            foreach ($def['orden'] as $j => $o) {
                if (empty($o['nulos'])) {
                    [$termino, $signo] = [$np + $j, $o['dir'] === 'DESC' ? -1 : 1];
                }
            }
            foreach ($filas as $p => $k) {
                $v     = $claves[$termino][$k];
                $t[$p] = Valor::esNumerico($v) ? $signo * Valor::aNumero($v) : null;
            }
        }
        $limites = static fn(int $p): array => self::limitesMarco($marco, $p, $n, $grupo, $ini, $fin, $t);
        $excluir = $marco['excluir'];
        // Las posiciones del marco de la fila $p, sin las que quita EXCLUDE
        $enMarco = static function (int $p) use ($limites, $excluir, $grupo, $ini, $fin): array {
            [$a, $b] = $limites($p);
            [$xa, $xb] = $excluir === 'NO OTHERS' ? [0, 0] : ($excluir === 'CURRENT ROW' ? [$p, $p + 1] : [$ini[$grupo[$p]], $fin[$grupo[$p]]]);
            $r = [];
            for ($q = $a; $q < $b; $q++) {
                if ($q < $xa || $q >= $xb || ($excluir === 'TIES' && $q === $p)) {
                    $r[] = $q;
                }
            }
            return $r;
        };

        if (in_array($w['nombre'], ['FIRST_VALUE', 'LAST_VALUE', 'NTH_VALUE'], true)) {
            foreach ($filas as $p => $k) {
                if ($w['nombre'] === 'NTH_VALUE') {
                    $nv = $arg(1, $p);
                    $nv = Valor::esNumerico($nv) ? Valor::aNumero($nv) : 0;
                    if ($nv < 1 || (is_float($nv) && floor($nv) !== $nv)) {
                        throw JsonSqlDbError::type('El segundo argumento de NTH_VALUE tiene que ser un entero positivo');
                    }
                }
                if ($excluir === 'NO OTHERS') {
                    [$a, $b] = $limites($p);
                    $q = $w['nombre'] === 'FIRST_VALUE' ? $a : ($w['nombre'] === 'LAST_VALUE' ? $b - 1 : $a + Valor::entero($nv) - 1);
                    $out[$k] = $q >= $a && $q < $b ? $arg(0, $q) : null;
                } else {
                    $r = $enMarco($p);
                    $q = $w['nombre'] === 'FIRST_VALUE' ? ($r[0] ?? null) : ($w['nombre'] === 'LAST_VALUE' ? ($r[count($r) - 1] ?? null) : ($r[Valor::entero($nv) - 1] ?? null));
                    $out[$k] = $q === null ? null : $arg(0, $q);
                }
            }
            return;
        }

        // Un agregado: sus valores y su FILTER, una vez por fila
        $vals = $pasa = [];
        foreach ($filas as $p => $k) {
            Memoria::comprobar('las funciones de ventana');
            $pasa[$p] = $w['filtro'] === null || Valor::verdadero($valor($w['filtro'], $k)) === true;
            $vals[$p] = $w['star'] ? null : $valor($w['args'][0], $k);
        }
        // Con el marco pegado a un extremo de la partición (lo de siempre: «lo
        // que va hasta aquí»), los corrientes se acumulan según avanza el
        // marco, sin volver a recorrerlo en cada fila
        $inicioFijo = $marco['inicio']['k'] === 'UNBOUNDED PRECEDING';
        $colacion   = SelectAgrupada::colacionDelArgumento($w);   // MIN y MAX de una columna con COLLATE, o con él escrito (2.9)
        if ($excluir === 'NO OTHERS' && ($inicioFijo || $marco['fin']['k'] === 'UNBOUNDED FOLLOWING')
            && in_array($w['nombre'], ['COUNT', 'SUM', 'TOTAL', 'AVG', 'MIN', 'MAX'], true)) {
            $acum = ['n' => 0, 'c' => 0, 's' => 0, 'm' => null];
            $hecho = $inicioFijo ? 0 : $n;              // hasta dónde se ha acumulado
            for ($i = 0; $i < $n; $i++) {
                $p = $inicioFijo ? $i : $n - 1 - $i;
                [$a, $b] = $limites($p);
                while ($inicioFijo ? $hecho < $b : $hecho > $a) {
                    $q = $inicioFijo ? $hecho++ : --$hecho;
                    self::acumular($acum, $w['nombre'], $pasa[$q], $vals[$q], $colacion);
                }
                $out[$filas[$p]] = self::acumulado($acum, $w['nombre'], $w['star']);
            }
            return;
        }
        foreach ($filas as $p => $k) {
            $lista = [];
            $cuantas = 0;
            foreach ($enMarco($p) as $q) {
                if ($pasa[$q]) {
                    $lista[] = $vals[$q];
                    $cuantas++;
                }
            }
            $sep = ',';
            if ($w['nombre'] === 'GROUP_CONCAT' && isset($w['args'][1])) {
                $s   = $arg(1, $p);
                $sep = $s === null ? '' : Valor::aTexto($s);
            }
            $out[$k] = SelectAgrupada::agregado($w['nombre'], $w['star'] ? null : $lista, $cuantas, false, $sep, $colacion);
        }
    }

    /**
     * Las posiciones [desde, hasta) del marco de la fila $p en su partición:
     * ROWS cuenta filas, GROUPS grupos de pares, y RANGE la distancia en el
     * valor de su ORDER BY ($t, ya con signo; null si no la tiene).
     *
     * @param list<int> $grupo el grupo de pares de cada posición
     * @param list<int> $ini   la primera posición de cada grupo
     * @param list<int> $fin   la siguiente a la última de cada grupo
     * @param array<int, int|float|null>|null $t
     * @return array{0: int, 1: int}
     */
    private static function limitesMarco(array $marco, int $p, int $n, array $grupo, array $ini, array $fin, ?array $t): array
    {
        $g  = $grupo[$p];
        $ng = count($ini);
        $limite = static function (array $l, bool $esInicio) use ($marco, $p, $n, $g, $ng, $ini, $fin, $t): int {
            if ($l['k'] === 'UNBOUNDED PRECEDING') {
                return 0;
            }
            if ($l['k'] === 'UNBOUNDED FOLLOWING') {
                return $n;
            }
            $d = $l['k'] === 'PRECEDING' ? -($l['n']) : ($l['k'] === 'FOLLOWING' ? $l['n'] : 0);
            switch ($marco['tipo']) {
                case 'ROWS':
                    return max(0, min($n, $p + $d + ($esInicio ? 0 : 1)));
                case 'GROUPS':
                    $h = $g + $d;
                    if ($esInicio) {
                        return $h < 0 ? 0 : ($h >= $ng ? $n : $ini[$h]);
                    }
                    return $h < 0 ? 0 : ($h >= $ng ? $n : $fin[$h]);
            }
            // RANGE: sin distancia (CURRENT ROW, NULL, un texto), los pares
            if ($l['k'] === 'CURRENT ROW' || $t === null || $t[$p] === null) {
                return $esInicio ? $ini[$g] : $fin[$g];
            }
            $x = $t[$p] + $d;
            $q = $p;
            if ($esInicio) {                            // la primera con valor >= x
                if ($t[$p] >= $x) {
                    while ($q > 0 && $t[$q - 1] !== null && $t[$q - 1] >= $x) { $q--; }
                } else {
                    while ($q < $n && ($t[$q] === null || $t[$q] < $x)) { $q++; }
                }
                return $q;
            }
            if ($t[$p] <= $x) {                         // la primera con valor > x
                while ($q < $n && $t[$q] !== null && $t[$q] <= $x) { $q++; }
            } else {
                while ($q > 0 && ($t[$q - 1] === null || $t[$q - 1] > $x)) { $q--; }
            }
            return $q;
        };
        return [$limite($marco['inicio'], true), $limite($marco['fin'], false)];
    }

    /** Un valor más en un agregado corriente de una ventana (ver ventanaEn()), como en agrupar() */
    private static function acumular(array &$acum, string $nombre, bool $pasa, $v, ?string $colacion): void
    {
        if (!$pasa) {
            return;
        }
        $acum['n']++;
        if ($v === null) {
            return;
        }
        $acum['c']++;
        if ($nombre === 'MIN' || $nombre === 'MAX') {
            $c = $acum['m'] === null ? null
                : ($colacion === null ? Valor::comparar($v, $acum['m']) : Valor::comparar(Functions::plegar($v, $colacion), Functions::plegar($acum['m'], $colacion)));
            if ($acum['m'] === null || ($c !== null && ($nombre === 'MIN' ? $c < 0 : $c > 0))) {
                $acum['m'] = $v;
            }
        } else {
            $acum['s'] += is_int($v) || is_float($v) ? $v : Valor::aNumero($v);
        }
    }

    /** El resultado de un agregado corriente, como en agrupar() */
    private static function acumulado(array $acum, string $nombre, bool $star)
    {
        switch ($nombre) {
            case 'COUNT': return $star ? $acum['n'] : $acum['c'];
            case 'SUM':   return $acum['c'] === 0 ? null : $acum['s'];
            case 'TOTAL': return (float)$acum['s'];
            case 'AVG':   return $acum['c'] === 0 ? null : (float)($acum['s'] / $acum['c']);
        }
        return $acum['m'];
    }
}
