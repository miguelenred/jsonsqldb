<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * GROUP BY, los agregados y HAVING: los grupos en memoria o, si no caben,
 * por particiones en el disco, y lo mismo con los valores de un
 * COUNT(DISTINCT …). Métodos de Select que reciben la consulta en $sel. Van
 * aparte para que una búsqueda sencilla no tenga que compilarlos (2.9): sin
 * OPcache, PHP compila en cada petición lo que carga. Hereda de Select solo
 * para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SelectAgrupada extends Select
{
    /**
     * Cómo acumula cada agregado al agrupar (ver agrupar()): 'lista' guarda
     * los valores, 'mejor' uno (MIN, MAX, ANY_VALUE), 'cuad' la suma de
     * cuadrados (varianzas), 'bits' las operaciones de bits, 'cuenta' nada
     * (COUNT(x), ver agrupar(); 2.10: sumaba también, sin usarlo); el resto, 'suma'.
     * Los JSON cuentan también los NULL, como en SQLite y MySQL.
     */
    private const MODO_AGREGADO = ['COUNT' => 'cuenta', 'GROUP_CONCAT' => 'lista', 'JSON_GROUP_ARRAY' => 'lista', 'JSON_ARRAYAGG' => 'lista',
        'JSON_GROUP_OBJECT' => 'lista', 'JSON_OBJECTAGG' => 'lista', 'MIN' => 'mejor', 'MAX' => 'mejor', 'ANY_VALUE' => 'mejor',
        'STD' => 'cuad', 'STDDEV' => 'cuad', 'STDDEV_POP' => 'cuad', 'STDDEV_SAMP' => 'cuad', 'VARIANCE' => 'cuad',
        'VAR_POP' => 'cuad', 'VAR_SAMP' => 'cuad', 'BIT_AND' => 'bits', 'BIT_OR' => 'bits', 'BIT_XOR' => 'bits'];
    private const CON_NULOS = ['JSON_GROUP_ARRAY' => true, 'JSON_ARRAYAGG' => true, 'JSON_GROUP_OBJECT' => true, 'JSON_OBJECTAGG' => true];

    private function __construct()
    {
    }

    /**
     * Agrupa las filas acumulando sus agregados, sin guardar las filas: de
     * cada grupo queda su primera fila —contra la que se evalúan las columnas
     * sin agregar— y el resultado de cada acumulador de $defs.
     *
     * COUNT, SUM, AVG, MIN y MAX sin DISTINCT se acumulan sobre la marcha con
     * el mismo cálculo que hace agregado() sobre la lista entera.
     * Con DISTINCT, y en GROUP_CONCAT, se guardan los valores (solo los de esa
     * columna) y se delega en él al final.
     *
     * Con $carpeta (2.9), si los grupos no cupieron en memoria (ver
     * Select::correr()), por particiones: ver agruparPorParticiones().
     *
     * @param iterable<array>      $filas
     * @param array<string,array>  $defs   ver Evaluator::marcarAgregados()
     * @return iterable<array{0: array, 1: array<string,mixed>}> [fila representativa, agregados]
     */
    public static function agrupar(iterable $filas, array $exprs, array $defs, callable $sub,
                                   callable $conjunto, array $externa, ?string $carpeta = null): iterable
    {
        if ($carpeta !== null && $exprs !== []) {
            return self::agruparPorParticiones($filas, $exprs, $defs, $sub, $conjunto, $externa, $carpeta);
        }
        if ($carpeta !== null && self::distintosEnDisco($defs) !== []) {
            return self::distintosPorParticiones($filas, $defs, $sub, $conjunto, $externa, $carpeta);
        }
        // Cada argumento y cada clave de grupo, como se lee de la fila (ver lectura())
        $args    = [];
        $filtros = [];                          // FILTER (WHERE …) de cada agregado (2.9)
        foreach ($defs as $id => $d) {
            $args[$id]    = $d['arg'] === null ? null : self::lectura($d['arg']);
            $filtros[$id] = $d['filtro'] === null ? null : (EvaluatorCompilado::compilar($d['filtro']) ?? $d['filtro']);
        }
        $claves = [];
        foreach ($exprs as $e) {
            $claves[] = self::lectura($e);
        }

        // ¿Hace falta el contexto del evaluador? Si todo está compilado, no: se
        // ahorra construir un array por fila
        $conCtx = false;
        foreach (array_merge($claves, array_values($args), array_values($filtros)) as $e) {
            $conCtx = $conCtx || \is_array($e);
        }
        foreach ($defs as $d) {
            $conCtx = $conCtx || ($d['orden'] ?? []) !== [];
        }

        // Por grupo: la primera fila, cuántas filas, y por acumulador cuántas
        // no entran (FILTER y NULL), la suma (SUM, AVG, TOTAL), el mejor valor visto
        // (MIN, MAX) o la lista de valores (DISTINCT, GROUP_CONCAT). Cada
        // grupo se toca por referencia: escribir $grupos[$clave]['suma'][$id]
        // buscaba el grupo tres veces por fila y acumulador
        $grupos = [];
        $ctx    = null;
        foreach ($filas as $fila) {
            if ($conCtx) {
                $ctx = ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa];
            }
            // La de claveDeGrupo(), aquí mismo: va una vez por fila, y la
            // llamada costaba un 8 % de un GROUP BY (2.9)
            $clave = '';
            foreach ($claves as $e) {
                $clave .= Valor::clave(\is_string($e) ? $fila[$e] ?? null : ($e instanceof \Closure ? $e($fila) : Evaluator::evaluar($e, $ctx))) . "\0";
            }
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = ['fila' => $fila, 'n' => 0, 'suma' => [], 'fuera' => [], 'mejor' => [], 'lista' => []];
                Memoria::comprobar('la agrupación');
            }
            $g = &$grupos[$clave];
            $g['n']++;
            // De cada acumulador se cuentan las filas que no entran, que son
            // pocas (las del FILTER y los NULL): las que entran son las del
            // grupo menos esas (2.10: se contaban las que entraban, una suma
            // más en cada valor de SUM, AVG, TOTAL, COUNT y las varianzas)
            foreach ($defs as $id => $d) {
                $f = $filtros[$id];
                if ($f !== null && Valor::verdadero($f instanceof \Closure ? $f($fila) : Evaluator::evaluar($f, $ctx)) !== true) {
                    $g['fuera'][$id] = ($g['fuera'][$id] ?? 0) + 1;
                    continue;
                }
                if ($d['star']) {
                    continue;                           // COUNT(*): basta con contar filas
                }
                $a = $args[$id];
                $v = \is_string($a) ? $fila[$a] ?? null : ($a instanceof \Closure ? $a($fila) : Evaluator::evaluar($a, $ctx));
                if ($v === null && !$d['nulos']) {
                    $g['fuera'][$id] = ($g['fuera'][$id] ?? 0) + 1;
                    continue;                           // los agregados ignoran los NULL (los JSON, no)
                }
                $modo = $d['modo'];
                if ($modo === 'suma') {                 // SUM, AVG, TOTAL
                    // Como SQLite (ver sumar()): los enteros, exactos mientras
                    // caben; lo demás, al par compensado, que va en el mismo
                    // sitio. Aquí, los dos casos corrientes; el resto, sumar()
                    if (\is_int($v) || (!\is_float($v) && \is_int($v = self::sumando($v)))) {
                        // PHP pasa a decimal la suma de enteros que no cabe
                        // @phpstan-ignore booleanAnd.rightAlwaysTrue
                        if (\is_int($s = $g['suma'][$id] ?? 0) && \is_int($x = $s + $v)) {
                            $g['suma'][$id] = $x;
                        } else {
                            self::sumar($g['suma'][$id], $v, $g['n'] - 1 - ($g['fuera'][$id] ?? 0));
                        }
                    } else {
                        // Por referencia: dos búsquedas en vez de ocho
                        $k = &$g['suma'][$id];
                        if (\is_array($k)) {
                            $sp = $k[0];                // ver sumarDecimal()
                            $t  = $sp + $v;
                            $k[1] += ($sp < 0 ? -$sp : $sp) > ($v < 0 ? -$v : $v) ? ($sp - $t) + $v : ($v - $t) + $sp;
                            $k[0] = $t;
                        } else {
                            self::sumar($k, $v, $g['n'] - 1 - ($g['fuera'][$id] ?? 0));
                        }
                        unset($k);
                    }
                } elseif ($modo === 'cuenta') {         // COUNT(x): nada más
                    continue;
                } elseif ($modo === 'lista') {
                    Memoria::comprobar('la agrupación');    // un grupo puede juntar muchos (2.9)
                    if ($d['distinct'] && $d['orden'] === null) {
                        // Con DISTINCT, cada valor una vez según llega, por su
                        // clave; COUNT, sin el valor (2.9: se juntaban todos y los
                        // repetidos se quitaban al final, y COUNT(DISTINCT) de un
                        // millón de valores distintos llegaba a tenerlos tres veces)
                        if ($v !== null) {
                            $k = Valor::clave($d['colacion'] === null ? $v : Functions::plegar($v, $d['colacion']));
                            if (!isset($g['lista'][$id][$k])) {
                                $g['lista'][$id][$k] = $d['nombre'] === 'COUNT' ? true : $v;
                            }
                        }
                        continue;
                    }
                    $g['lista'][$id][] = $v;
                    // GROUP_CONCAT … ORDER BY: con cada valor, sus claves de orden
                    foreach ($d['orden'] ?? [] as $i => $o) {
                        $g['claves'][$id][$i][] = Evaluator::evaluar($o['expr'], $ctx);
                    }
                } elseif ($modo === 'mejor') {          // MIN, MAX y ANY_VALUE (el primero)
                    if (!\array_key_exists($id, $g['mejor'])) {
                        $g['mejor'][$id] = $v;
                    } elseif ($d['nombre'] !== 'ANY_VALUE') {
                        // Por clase, como en SQLite: los números antes que los textos (2.10)
                        $c = $d['colacion'] === null ? Valor::compararClase($v, $g['mejor'][$id])
                            : Valor::compararClase(Functions::plegar($v, $d['colacion']), Functions::plegar($g['mejor'][$id], $d['colacion']));
                        if ($c !== null && (($d['nombre'] === 'MIN' && $c < 0) || ($d['nombre'] === 'MAX' && $c > 0))) {
                            $g['mejor'][$id] = $v;
                        }
                    }
                } elseif ($modo === 'cuad') {           // varianzas: suma y cuadrados
                    $x = \is_int($v) || \is_float($v) ? $v : Valor::aNumero($v);
                    $g['suma'][$id]   = ($g['suma'][$id] ?? 0) + $x;
                    $g['cuad'][$id]   = ($g['cuad'][$id] ?? 0) + $x * $x;
                } else {                                // BIT_AND, BIT_OR, BIT_XOR
                    $g['mejor'][$id] = self::bits($d['nombre'], $g['mejor'][$id] ?? ($d['nombre'] === 'BIT_AND' ? -1 : 0), $v);
                }
            }
            unset($g);
        }
        if ($exprs === [] && $grupos === []) {
            // Agregación total: una fila aunque no haya datos
            $grupos[''] = ['fila' => [], 'n' => 0, 'suma' => [], 'fuera' => [], 'mejor' => [], 'lista' => []];
        }

        $out = [];
        foreach ($grupos as $g) {
            $valores = [];
            foreach ($defs as $id => $d) {
                $cuenta = $g['n'] - ($g['fuera'][$id] ?? 0);
                if ($d['star']) {
                    $valores[$id] = $cuenta;
                } elseif ($d['modo'] === 'lista') {
                    $sep = ',';
                    if ($d['sep'] !== null) {
                        $s   = Evaluator::evaluar($d['sep'], ['fila' => $g['fila'], 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa]);
                        $sep = $s === null ? '' : Valor::aTexto($s);
                    }
                    $lista = $g['lista'][$id] ?? [];
                    if ($d['distinct'] && $d['orden'] === null) {
                        $valores[$id] = $d['nombre'] === 'COUNT' ? \count($lista)
                            : self::agregado($d['nombre'], array_values($lista), $g['n'], false, $sep, $d['colacion']);
                        continue;
                    }
                    if (($d['orden'] ?? null) !== null && \count($lista) > 1) {
                        // El mismo orden que el ORDER BY de una consulta
                        $claves = $g['claves'][$id];
                        $indices = array_keys($lista);
                        $orden = SelectOrden::ordenNativo($d['orden'], $claves, $indices)
                            ?? SelectOrden::todasOrdenadas($indices, SelectOrden::comparadorDe($d['orden'], $g['claves'][$id]));
                        $lista = array_map(static fn(int $i) => $lista[$i], $orden);
                    }
                    $valores[$id] = self::agregado($d['nombre'], $lista, $g['n'], $d['distinct'], $sep, $d['colacion']);
                } elseif ($d['nombre'] === 'COUNT') {
                    $valores[$id] = $cuenta;
                } elseif ($d['nombre'] === 'SUM' || $d['nombre'] === 'TOTAL' || $d['nombre'] === 'AVG') {
                    $valores[$id] = self::sumaFinal($d['nombre'], $g['suma'][$id] ?? 0, $cuenta);
                } elseif ($d['modo'] === 'cuad') {
                    $valores[$id] = self::varianza($d['nombre'], $cuenta, $g['suma'][$id] ?? 0, $g['cuad'][$id] ?? 0);
                } elseif ($d['modo'] === 'bits') {
                    $valores[$id] = $g['mejor'][$id] ?? ($d['nombre'] === 'BIT_AND' ? -1 : 0);
                } else {
                    $valores[$id] = $g['mejor'][$id] ?? null;   // MIN, MAX, ANY_VALUE
                }
            }
            $out[] = [$g['fila'], $valores];
        }
        return $out;
    }

    /**
     * Cómo agrupar() lee una expresión de cada fila: si es una columna, su
     * clave en la fila, que se lee sin más (2.10: llamar a la función
     * compilada costaba más que lo que la 2.10 añadió a SUM, TOTAL y AVG para
     * sumar como SQLite); si no, compilada, o su árbol para Evaluator.
     *
     * @return string|\Closure|array
     */
    private static function lectura(array $n)
    {
        return Evaluator::claveDirecta($n) ?? EvaluatorCompilado::compilar($n) ?? $n;
    }

    /**
     * Un GROUP BY con más grupos de los que caben en memoria (2.9): las filas
     * se reparten por su clave de grupo en ficheros temporales (ver Volcado),
     * así que todas las de un grupo caen en la misma partición, y cada una se
     * agrupa en memoria como siempre. Cada grupo conserva su primera fila,
     * porque cada partición guarda las suyas en el orden en que llegaron; los
     * grupos salen partición a partición.
     *
     * @param iterable<array> $filas
     * @return \Generator<int, array{0: array, 1: array<string,mixed>}>
     */
    private static function agruparPorParticiones(iterable $filas, array $exprs, array $defs, callable $sub,
                                                  callable $conjunto, array $externa, string $carpeta): \Generator
    {
        $claves = [];
        $conCtx = false;
        foreach ($exprs as $e) {
            $claves[] = EvaluatorCompilado::compilar($e) ?? $e;
            $conCtx   = $conCtx || !(end($claves) instanceof \Closure);
        }
        $volcado = new Volcado($carpeta);
        try {
            foreach ($filas as $fila) {
                $ctx = $conCtx ? ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa] : null;
                $volcado->anadir($fila, crc32(self::claveDeGrupo($claves, $fila, $ctx)) % self::PARTICIONES);
            }
            unset($filas);
            for ($p = 0; $p < self::PARTICIONES; $p++) {
                yield from self::agrupar($volcado->leer($p), $exprs, $defs, $sub, $conjunto, $externa);
            }
        } finally {
            $volcado->borrar();
        }
    }

    /**
     * Los agregados con DISTINCT que, sin GROUP BY, se pueden hacer por
     * particiones (ver distintosPorParticiones()): los que no dependen del
     * orden de los valores.
     *
     * @param array<string,array> $defs
     * @return array<string,array>
     */
    public static function distintosEnDisco(array $defs): array
    {
        return array_filter($defs, static fn(array $d): bool => $d['distinct'] && $d['orden'] === null
            && \in_array($d['nombre'], ['COUNT', 'SUM', 'TOTAL', 'AVG', 'MIN', 'MAX'], true));
    }

    /**
     * COUNT(DISTINCT …) y los demás de distintosEnDisco(), sin GROUP BY, cuando
     * sus valores distintos no cupieron en memoria (2.9; ver Select::correr()):
     * según pasan las filas, cada valor va a una de 64 particiones por su
     * clave, y los demás agregados se acumulan como siempre. Un valor está
     * siempre en la misma partición, así que los distintos de cada una, que
     * caben, son los distintos de todos; al final se suman los de cada una.
     * SUM, TOTAL y AVG suman los distintos en el orden en que aparecieron, como
     * en memoria y en SQLite (2.10, antes de publicarla: en el de las
     * particiones, y el redondeo o el error de desbordamiento podían ser
     * otros): cada partición los escribe, con su posición, en otro temporal
     * repartido por tramos de posiciones, y cada tramo se ordena en memoria.
     *
     * @return list<array{0: array, 1: array<string,mixed>}>
     */
    private static function distintosPorParticiones(iterable $filas, array $defs, callable $sub,
                                                    callable $conjunto, array $externa, string $carpeta): array
    {
        $distintos = self::distintosEnDisco($defs);
        $volcado   = new Volcado($carpeta);
        $enOrden   = null;                      // los distintos de SUM, TOTAL y AVG, con su posición (ver arriba)
        try {
            $args = $filtros = [];
            foreach ($distintos as $id => $d) {
                $args[$id]    = EvaluatorCompilado::compilar($d['arg']) ?? $d['arg'];
                $filtros[$id] = $d['filtro'] === null ? null : (EvaluatorCompilado::compilar($d['filtro']) ?? $d['filtro']);
            }
            // La posición de cada fila, solo si hay SUM, TOTAL o AVG: suman en ese orden
            $sumas = array_filter($distintos, static fn(array $d): bool => \in_array($d['nombre'], ['SUM', 'TOTAL', 'AVG'], true));
            $total = 0;                         // filas, para repartir sus posiciones en tramos
            $repartir = static function () use ($filas, $distintos, $args, $filtros, $sub, $conjunto, $externa, $volcado, $sumas, &$total): \Generator {
                foreach ($filas as $fila) {
                    $total++;
                    $ctx = ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa];
                    foreach ($distintos as $id => $d) {
                        $f = $filtros[$id];
                        if ($f !== null && Valor::verdadero($f instanceof \Closure ? $f($fila) : Evaluator::evaluar($f, $ctx)) !== true) {
                            continue;
                        }
                        $v = $args[$id] instanceof \Closure ? $args[$id]($fila) : Evaluator::evaluar($args[$id], $ctx);
                        if ($v !== null) {
                            $k = Valor::clave($d['colacion'] === null ? $v : Functions::plegar($v, $d['colacion']));
                            $volcado->anadir(isset($sumas[$id]) ? [$id, $k, $v, $total] : [$id, $k, $v], crc32($k) % self::PARTICIONES);
                        }
                    }
                    yield $fila;
                }
            };
            $grupo = [];
            foreach (self::agrupar($repartir(), [], array_diff_key($defs, $distintos), $sub, $conjunto, $externa) as $g) {
                $grupo = $g;                            // sin GROUP BY, un grupo
            }
            // Los de cada partición, y de ellos, el de todas. SUM, TOTAL y AVG,
            // con una suma para todas (2.10: cada partición se pasaba a decimal
            // por su cuenta y se perdía lo que la suma compensada guarda)
            $parciales = $hechos = [];
            /** @var array<string, int|array<int, int|float>> $suma lo sumado de cada SUM, TOTAL y AVG (ver sumar()) */
            $suma = [];
            for ($p = 0; $p < self::PARTICIONES; $p++) {
                $vistos = $posiciones = [];
                foreach ($volcado->leer($p) as $r) {
                    // El primero, y de SUM, TOTAL y AVG la posición en que apareció,
                    // aparte: un par por valor ocupaba cuatro veces más
                    if (!isset($vistos[$r[0]][$r[1]])) {
                        $vistos[$r[0]][$r[1]] = $r[2];
                        if (isset($r[3])) {
                            $posiciones[$r[0]][$r[1]] = $r[3];
                        }
                    }
                }
                foreach ($vistos as $id => $unicos) {
                    $d = $distintos[$id];
                    $parciales[$id]['n'][] = \count($unicos);
                    if (isset($sumas[$id])) {
                        $enOrden ??= new Volcado($carpeta);
                        foreach ($unicos as $k => $v) {
                            $pos = $posiciones[$id][$k];
                            $enOrden->anadir([$pos, $id, $v], intdiv(($pos - 1) * self::PARTICIONES, $total));
                        }
                    } else {
                        $parciales[$id]['v'][] = self::agregado($d['nombre'] === 'COUNT' ? 'SUM' : $d['nombre'],
                            $d['nombre'] === 'COUNT' ? [] : array_values($unicos), \count($unicos), false, ',', $d['colacion']);
                    }
                }
            }
            // Cada tramo de posiciones, en orden, y en él cada agregado por la
            // posición de sus valores (cada uno tiene uno por fila como mucho)
            for ($p = 0; $enOrden !== null && $p < self::PARTICIONES; $p++) {
                $tramo = [];
                foreach ($enOrden->leer($p) as [$pos, $id, $v]) {
                    $tramo[$id][$pos] = $v;
                }
                foreach ($tramo as $id => $deUno) {
                    ksort($deUno);
                    foreach ($deUno as $v) {
                        $suma[$id] ??= 0;
                        $k = &$suma[$id];
                        if (\is_float($v) && \is_array($k)) {      // lo de siempre, como en agrupar()
                            $sp = $k[0];
                            $t  = $sp + $v;
                            $k[1] += ($sp < 0 ? -$sp : $sp) > ($v < 0 ? -$v : $v) ? ($sp - $t) + $v : ($v - $t) + $sp;
                            $k[0] = $t;
                        } else {
                            self::sumar($k, \is_int($v) || \is_float($v) ? $v : self::sumando($v), $hechos[$id] ?? 0);
                        }
                        unset($k);
                        $hechos[$id] = ($hechos[$id] ?? 0) + 1;
                    }
                }
            }
            foreach ($distintos as $id => $d) {
                $n = array_sum($parciales[$id]['n'] ?? []);
                $grupo[1][$id] = match ($d['nombre']) {
                    'COUNT'               => $n,
                    'SUM', 'TOTAL', 'AVG' => self::sumaFinal($d['nombre'], $suma[$id] ?? 0, $n),
                    default               => self::agregado($d['nombre'], $parciales[$id]['v'] ?? [], $n, false, ',', $d['colacion']),
                };
            }
            return [$grupo];
        } finally {
            $volcado->borrar();
            $enOrden?->borrar();
        }
    }

    /** La clave del grupo de una fila: la de cada expresión del GROUP BY, seguidas */
    private static function claveDeGrupo(array $claves, array $fila, ?array $ctx): string
    {
        $clave = '';
        foreach ($claves as $e) {
            $clave .= Valor::clave($e instanceof \Closure ? $e($fila) : Evaluator::evaluar($e, $ctx)) . "\0";
        }
        return $clave;
    }

    /**
     * Cambia en una expresión cada columna sin tabla que es un alias de la
     * salida por la expresión de ese alias. No entra en subconsultas: allí los
     * nombres son de otra consulta.
     *
     * @param array<string, array> $alias alias en minúsculas => expresión
     */
    public static function sustituirAlias(array $n, array $alias): array
    {
        if (($n['k'] ?? '') === 'col' && ($n['tabla'] ?? null) === null && isset($alias[strtolower((string)$n['nombre'])])) {
            return $alias[strtolower((string)$n['nombre'])];
        }
        foreach ($n as $k => $v) {
            if (\is_array($v) && $k !== 'select') {
                $n[$k] = self::sustituirAlias($v, $alias);
            }
        }
        return $n;
    }

    /**
     * Sustituye cada función de agregación del árbol por una referencia a un
     * acumulador —`agid`—, y devuelve en $defs qué acumula cada uno. Así una
     * consulta agrupada no guarda las filas de cada grupo: las recorre una vez
     * alimentando los acumuladores, y las expresiones de salida se evalúan
     * después contra sus resultados (ver agrupar()). El mismo agregado escrito
     * dos veces comparte acumulador.
     *
     * @param array<string,array> $defs por firma: ['nombre', 'arg', 'star', 'distinct', 'sep']
     */
    public static function marcarAgregados(array $n, array &$defs): array
    {
        if ($n['k'] === 'fn' && Evaluator::esAgregado($n)) {
            $firma = md5(serialize($n));
            $defs[$firma] ??= [
                'nombre'   => $n['nombre'],
                'star'     => $n['star'],
                'arg'      => $n['star'] ? null : $n['args'][0],
                'distinct' => $n['distinct'],
                'sep'      => $n['args'][1] ?? null,
                'orden'    => $n['orden'] ?? null,
                // Cómo acumula al agrupar, decidido una vez (ver MODO_AGREGADO)
                'modo'     => $n['distinct'] ? 'lista' : (self::MODO_AGREGADO[$n['nombre']] ?? 'suma'),
                'nulos'    => isset(self::CON_NULOS[$n['nombre']]),
                'filtro'   => $n['filtro'] ?? null,              // FILTER (WHERE …), 2.9
                // MIN, MAX y DISTINCT con la colación de su columna o la escrita
                // con COLLATE dentro, MIN(x COLLATE NOCASE) (2.9: esa se ignoraba)
                'colacion' => self::colacionDelArgumento($n),
            ];
            $n['agid'] = $firma;
            return $n;
        }
        switch ($n['k']) {
            case 'bin':
                $n['i'] = self::marcarAgregados($n['i'], $defs);
                $n['d'] = self::marcarAgregados($n['d'], $defs);
                break;
            case 'un':
            case 'null':
            case 'cast':
                $n['e'] = self::marcarAgregados($n['e'], $defs);
                break;
            case 'fn':
                foreach ($n['args'] as $i => $a) {
                    $n['args'][$i] = self::marcarAgregados($a, $defs);
                }
                break;
            case 'ventana':                       // los agregados de dentro son del GROUP BY
                foreach ($n['args'] as $i => $a) {
                    $n['args'][$i] = self::marcarAgregados($a, $defs);
                }
                if ($n['filtro'] !== null) {
                    $n['filtro'] = self::marcarAgregados($n['filtro'], $defs);
                }
                foreach ($n['def']['particion'] as $i => $e) {
                    $n['def']['particion'][$i] = self::marcarAgregados($e, $defs);
                }
                foreach ($n['def']['orden'] as $i => $o) {
                    $n['def']['orden'][$i]['expr'] = self::marcarAgregados($o['expr'], $defs);
                }
                break;
            case 'between':
                $n['e']   = self::marcarAgregados($n['e'], $defs);
                $n['min'] = self::marcarAgregados($n['min'], $defs);
                $n['max'] = self::marcarAgregados($n['max'], $defs);
                break;
            case 'like':
                $n['e']      = self::marcarAgregados($n['e'], $defs);
                $n['patron'] = self::marcarAgregados($n['patron'], $defs);
                if ($n['escape'] !== null) {
                    $n['escape'] = self::marcarAgregados($n['escape'], $defs);
                }
                break;
            case 'regexp':
                $n['e']      = self::marcarAgregados($n['e'], $defs);
                $n['patron'] = self::marcarAgregados($n['patron'], $defs);
                break;
            case 'in':
                $n['e'] = self::marcarAgregados($n['e'], $defs);
                foreach ($n['lista'] ?? [] as $i => $e) {
                    $n['lista'][$i] = self::marcarAgregados($e, $defs);
                }
                break;
            case 'case':
                if ($n['base'] !== null) {
                    $n['base'] = self::marcarAgregados($n['base'], $defs);
                }
                foreach ($n['when'] as $i => [$c, $r]) {
                    // Con lo que lleve detrás (la marca de afinidad, ver Evaluator::resolver())
                    $n['when'][$i] = [self::marcarAgregados($c, $defs), self::marcarAgregados($r, $defs)] + $n['when'][$i];
                }
                if ($n['else'] !== null) {
                    $n['else'] = self::marcarAgregados($n['else'], $defs);
                }
                break;
        }
        return $n;
    }

    /**
     * La colación con la que un agregado (o una ventana) compara y quita
     * repetidos: la de su argumento (ver colacionSalida()); null si es la
     * binaria o no tiene argumento (COUNT(*)).
     */
    public static function colacionDelArgumento(array $n): ?string
    {
        $c = $n['star'] ? null : Evaluator::colacionSalida($n['args'][0]);
        return $c === 'BINARY' ? null : $c;
    }

    /**
     * SUM, TOTAL o AVG de unos valores (ver sumar()).
     *
     * @param list<mixed> $valores
     * @return int|float|null
     */
    public static function suma(string $nombre, array $valores)
    {
        // Con decimales, el par de sumar() va suelto: la suma en $s y el
        // error en $e, sin escribir en un array a cada valor (2.10, antes de
        // publicarla: un 15 % más en una ventana que se mueve)
        $s = 0;
        $e = null;
        foreach ($valores as $i => $v) {
            if (\is_int($v) || (!\is_float($v) && \is_int($v = self::sumando($v)))) {
                // @phpstan-ignore booleanAnd.rightAlwaysTrue (PHP pasa a decimal la suma que no cabe)
                if ($e === null && \is_int($s) && \is_int($x = $s + $v)) {
                    $s = $x;
                    continue;
                }
            } elseif ($e !== null) {
                $t = $s + $v;                   // ver sumarDecimal()
                $e += ($s < 0 ? -$s : $s) > ($v < 0 ? -$v : $v) ? ($s - $t) + $v : ($v - $t) + $s;
                $s = $t;
                continue;
            } elseif (\is_int($s) && $s > -self::PARTIR && $s < self::PARTIR) {
                // El primer decimal tras unos enteros pequeños, aquí (en una
                // ventana que se mueve, esta suma se hace en cada fila)
                $d = (float)$s;
                $s = $d + $v;
                $e = ($d < 0 ? -$d : $d) > ($v < 0 ? -$v : $v) ? ($d - $s) + $v : ($v - $s) + $d;
                continue;
            }
            if ($e !== null) {
                [$s, $e] = [[$s, $e], null];
            }
            self::sumar($s, $v, $i);
            if (\is_array($s) && !isset($s[2])) {
                [$s, $e] = $s;                  // sin enteros que no caben, vuelve a ir suelto
            }
        }
        return self::sumaFinal($nombre, $e === null ? $s : [$s, $e], \count($valores));
    }

    /** 2^52: desde ahí un entero se suma al par en dos partes, como en SQLite */
    private const PARTIR = 4503599627370496;

    /**
     * Un valor más en una suma, como sumStep() de SQLite 3.45 (2.10): los
     * enteros, exactos, hasta el primer decimal o hasta que dejan de caber en
     * 64 bits; desde ahí, todo en orden de llegada al par [suma, error], que
     * ocupa el sitio de la suma, con el error de cada suma guardado aparte (la
     * suma compensada de Kahan-Babuska-Neumaier). Antes de la 2.10 PHP pasaba
     * la suma a decimal sin más: TOTAL de 1e100, 3 y -1e100 daba 0 (SQLite,
     * 3). SUM da el error de los enteros que no caben si se salió de los 64
     * bits y no llegó un decimal después, como allí: el par apunta entonces
     * cuántos valores iban antes ($antes) y cuántos enteros desde ahí, y así
     * quien suma un decimal no tiene que mirarlo. Quien suma lleva delante los
     * casos corrientes, sin llamar a esta.
     *
     * @param int|array<int, int|float>|null $s
     * @param-out int|array<int, int|float> $s
     * @param int|float $v ya con sumando()
     */
    public static function sumar(&$s, $v, int $antes): void
    {
        if (!\is_array($s)) {
            $s ??= 0;
            // @phpstan-ignore booleanAnd.rightAlwaysTrue (PHP pasa a decimal la suma que no cabe)
            if (\is_int($v) && \is_int($x = $s + $v)) {
                $s = $x;
                return;
            }
            // Un entero que no cabe o el primer decimal: lo de antes, al par
            // (kahanBabuskaNeumaierInit de SQLite)
            $s = $s <= -self::PARTIR || $s >= self::PARTIR ? [(float)($s - $s % 16384), (float)($s % 16384)] : [(float)$s, 0.0];
            if (\is_int($v)) {
                $s[2] = $antes;
                $s[3] = 0;
            }
        }
        if (\is_int($v)) {
            if (isset($s[3])) {
                $s[3]++;
            }
            if ($v <= -self::PARTIR || $v >= self::PARTIR) {
                $b = $v % 16384;                // en dos partes, cada una exacta en un decimal
                self::sumarDecimal($s, (float)($v - $b));
                $v = $b;
            }
            $v = (float)$v;
        }
        self::sumarDecimal($s, $v);
    }

    /**
     * Un decimal más en el par: el error de la suma, exacto, como lo calcula
     * SQLite (kahanBabuskaNeumaierStep(), con los valores absolutos y sin
     * llamar a abs()). Con números, lo mismo que TwoSum, que no compara; al
     * desbordar a infinito no: el error queda en -INF y la suma más el error
     * da NaN, que es NULL, como en SQLite 3.45 (2.10, antes de publicarla:
     * infinito).
     *
     * @param array<int, int|float> $k
     */
    private static function sumarDecimal(array &$k, float $v): void
    {
        $d = $k[0];
        $t = $d + $v;
        $k[1] += ($d < 0 ? -$d : $d) > ($v < 0 ? -$v : $v) ? ($d - $t) + $v : ($v - $t) + $d;
        $k[0] = $t;
    }

    /**
     * Lo que dan SUM, TOTAL y AVG con lo sumado en $s (ver sumar()), de $n
     * valores: como sumFinalize(), totalFinalize() y avgFinalize() de SQLite.
     * Del par, la suma más el error si este es un número; SUM, el error si
     * desde que no cupo un entero todos fueron enteros (ver sumar()).
     *
     * @param int|array<int, int|float> $s
     * @return int|float|null
     */
    public static function sumaFinal(string $nombre, $s, int $n)
    {
        if (\is_array($s)) {
            if ($nombre === 'SUM' && isset($s[2]) && $n - $s[2] === $s[3]) {
                throw self::desbordamiento();
            }
            // El error, si es un número o infinito (ver sumarDecimal()), como sumFinalize() de SQLite 3.45
            $s = is_nan($s[1]) ? $s[0] : $s[0] + $s[1];
            if (is_nan($s)) {
                return null;                    // NaN (+INF y -INF): NULL, como en SQLite (2.10)
            }
        }
        if ($nombre === 'TOTAL') {
            return (float)$s;                   // 0.0 sin valores, como SQLite
        }
        if ($n === 0) {
            return null;
        }
        // AVG, siempre decimal, como SQLite y MySQL: con / de PHP, una media
        // exacta salía entera (-10) y otra decimal (-5.33); y pasando antes la
        // suma a decimal, como allí (2.10: la división de enteros exacta daba
        // otro redondeo)
        return $nombre === 'AVG' ? (float)$s / $n : $s;
    }

    /**
     * Un valor que no es número, como lo suma SUM: un texto que es un entero,
     * como entero, y lo demás como decimal ('1.5', '12abc' o 'abc', que es
     * 0.0), igual que SQLite, para el que solo los enteros pueden desbordar.
     *
     * @return int|float
     */
    public static function sumando($v)
    {
        if (\is_string($v) && is_numeric($t = trim($v))) {
            return $t + 0;
        }
        // Un booleano (de un JSON editado a mano), como el entero 1 o 0 (2.10: 0.0)
        return \is_bool($v) ? (int)$v : (float)Valor::aNumero($v);
    }

    /** El error de SUM cuando una suma de enteros no cabe en 64 bits */
    public static function desbordamiento(): JsonSqlDbError
    {
        return JsonSqlDbError::type('SUM se sale de los enteros de 64 bits (integer overflow, como en SQLite): '
            . 'usa TOTAL(), que suma en decimal');
    }

    /**
     * Función de agregación sobre los valores ya evaluados de un grupo.
     * COUNT(*) llega con $valores = null y $filas = nº de filas del grupo.
     */
    public static function agregado(
        string $nombre,
        ?array $valores,
        int $filas,
        bool $distinct,
        string $separador = ',',
        ?string $colacion = null
    ) {
        if ($nombre === 'COUNT' && $valores === null) {
            return $filas;                       // COUNT(*)
        }
        $valores = $valores ?? [];

        // Los agregados ignoran los NULL
        $limpios = [];
        if ($distinct) {
            // Sin copiar antes la lista, y COUNT sin guardar los valores (2.9:
            // con un millón de valores distintos eran tres copias, y el fatal
            // de PHP podía llegar sin que nada lo mirase)
            $vistos = [];
            foreach ($valores as $v) {
                if ($v === null) {
                    continue;
                }
                Memoria::comprobar('la agrupación');
                $k = Valor::clave($colacion === null ? $v : Functions::plegar($v, $colacion));   // con COLLATE, 'Ana' y 'ana' son uno (2.9)
                if (!isset($vistos[$k])) {
                    $vistos[$k] = true;
                    if ($nombre !== 'COUNT') {
                        $limpios[] = $v;
                    }
                }
            }
            if ($nombre === 'COUNT') {
                return \count($vistos);
            }
        } else {
            foreach ($valores as $v) {
                if ($v !== null) { $limpios[] = $v; }
            }
        }

        switch ($nombre) {
            case 'COUNT':
                return \count($limpios);
            case 'SUM':
            case 'TOTAL':
            case 'AVG':
                return self::suma($nombre, $limpios);
            case 'GROUP_CONCAT':
                if ($limpios === []) { return null; }
                // El orden es el de las filas del grupo. SQLite no lo garantiza;
                // aquí sí, que es más útil y no cuesta nada.
                return implode($separador, array_map(
                    static fn($v): string => Valor::aTexto($v), $limpios));

            case 'MIN':
            case 'MAX':
                $res = null;
                foreach ($limpios as $v) {
                    if ($res === null) { $res = $v; continue; }
                    $c = $colacion === null ? Valor::compararClase($v, $res) : Valor::compararClase(Functions::plegar($v, $colacion), Functions::plegar($res, $colacion));
                    if ($c !== null && (($nombre === 'MIN' && $c < 0) || ($nombre === 'MAX' && $c > 0))) {
                        $res = $v;
                    }
                }
                return $res;
            case 'ANY_VALUE':
                return $limpios[0] ?? null;
            case 'JSON_GROUP_ARRAY':
            case 'JSON_ARRAYAGG':
                return Functions::aJson($valores);                 // con los NULL
            case 'JSON_GROUP_OBJECT':
            case 'JSON_OBJECTAGG':
                // Cada valor es JSON_ARRAY(clave, valor) (ver Parser::llamada())
                $o = new \stdClass();
                foreach ($valores as $par) {
                    [$k, $v] = json_decode((string)$par, false);
                    if ($k === null) { throw JsonSqlDbError::type(strtolower($nombre) . '(): una clave no puede ser NULL'); }
                    $o->{(string)$k} = $v;
                }
                return $valores === [] && $nombre === 'JSON_OBJECTAGG' ? null : Functions::aJson($o);
            case 'STD': case 'STDDEV': case 'STDDEV_POP': case 'STDDEV_SAMP':
            case 'VARIANCE': case 'VAR_POP': case 'VAR_SAMP':
                $s = $c = 0;
                foreach ($limpios as $v) {
                    $x = Valor::aNumero($v);
                    $s += $x;
                    $c += $x * $x;
                }
                return self::varianza($nombre, \count($limpios), $s, $c);
            case 'BIT_AND':
            case 'BIT_OR':
            case 'BIT_XOR':
                $r = $nombre === 'BIT_AND' ? -1 : 0;
                foreach ($limpios as $v) {
                    $r = self::bits($nombre, $r, $v);
                }
                return $r;
        }

        throw JsonSqlDbError::syntax("Función de agregación no soportada: $nombre()");
    }

    /**
     * Varianza o desviación típica con n valores, su suma y la de sus
     * cuadrados: de la población (STD, STDDEV, STDDEV_POP, VARIANCE, VAR_POP)
     * o de la muestra (STDDEV_SAMP, VAR_SAMP), como en MySQL.
     */
    private static function varianza(string $nombre, int $n, $suma, $cuadrados): ?float
    {
        $muestra = $nombre === 'STDDEV_SAMP' || $nombre === 'VAR_SAMP';
        if ($n === 0 || ($muestra && $n === 1)) {
            return null;
        }
        $media = $suma / $n;
        $var   = max(0.0, ($cuadrados - $n * $media * $media) / ($muestra ? $n - 1 : $n));
        return \in_array($nombre, ['VARIANCE', 'VAR_POP', 'VAR_SAMP'], true) ? (float)$var : sqrt($var);
    }

    /** Un paso de BIT_AND, BIT_OR o BIT_XOR, con enteros de 64 bits */
    private static function bits(string $nombre, int $r, $v): int
    {
        $x = Valor::entero(Valor::aNumero($v));
        return $nombre === 'BIT_AND' ? $r & $x : ($nombre === 'BIT_OR' ? $r | $x : $r ^ $x);
    }
}
