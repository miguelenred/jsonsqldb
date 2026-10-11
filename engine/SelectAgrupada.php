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
     * cuadrados (varianzas), 'bits' las operaciones de bits; el resto, 'suma'.
     * Los JSON cuentan también los NULL, como en SQLite y MySQL.
     */
    private const MODO_AGREGADO = ['GROUP_CONCAT' => 'lista', 'JSON_GROUP_ARRAY' => 'lista', 'JSON_ARRAYAGG' => 'lista',
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
        $args    = [];
        $filtros = [];                          // FILTER (WHERE …) de cada agregado (2.9)
        foreach ($defs as $id => $d) {
            $args[$id]    = $d['arg'] === null ? null : (EvaluatorCompilado::compilar($d['arg']) ?? $d['arg']);
            $filtros[$id] = $d['filtro'] === null ? null : (EvaluatorCompilado::compilar($d['filtro']) ?? $d['filtro']);
        }
        $claves = [];
        foreach ($exprs as $e) {
            $claves[] = EvaluatorCompilado::compilar($e) ?? $e;
        }

        // ¿Hace falta el contexto del evaluador? Si todo está compilado, no: se
        // ahorra construir un array por fila
        $conCtx = false;
        foreach (array_merge($claves, array_values($args), array_values($filtros)) as $e) {
            $conCtx = $conCtx || ($e !== null && !($e instanceof \Closure));
        }
        foreach ($defs as $d) {
            $conCtx = $conCtx || ($d['orden'] ?? []) !== [];
        }

        // Por grupo: la primera fila, cuántas filas, y por acumulador la suma
        // y el recuento de no nulos (SUM, AVG, COUNT), el mejor valor visto
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
                $clave .= Valor::clave($e instanceof \Closure ? $e($fila) : Evaluator::evaluar($e, $ctx)) . "\0";
            }
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = ['fila' => $fila, 'n' => 0, 'suma' => [], 'cuenta' => [], 'mejor' => [], 'lista' => []];
                Memoria::comprobar('la agrupación');
            }
            $g = &$grupos[$clave];
            $g['n']++;
            foreach ($defs as $id => $d) {
                $f = $filtros[$id];
                if ($f !== null && Valor::verdadero($f instanceof \Closure ? $f($fila) : Evaluator::evaluar($f, $ctx)) !== true) {
                    continue;
                }
                if ($d['star']) {
                    if ($f !== null) {
                        $g['cuenta'][$id] = ($g['cuenta'][$id] ?? 0) + 1;
                    }
                    continue;                           // COUNT(*): basta con contar filas
                }
                $a = $args[$id];
                $v = $a instanceof \Closure ? $a($fila) : Evaluator::evaluar($a, $ctx);
                if ($v === null && !$d['nulos']) {
                    continue;                           // los agregados ignoran los NULL (los JSON, no)
                }
                $modo = $d['modo'];
                if ($modo === 'suma') {                 // COUNT(x), SUM, AVG, TOTAL
                    $g['suma'][$id]   = ($g['suma'][$id] ?? 0) + (is_int($v) || is_float($v) ? $v : Valor::aNumero($v));
                    $g['cuenta'][$id] = ($g['cuenta'][$id] ?? 0) + 1;
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
                    if (!array_key_exists($id, $g['mejor'])) {
                        $g['mejor'][$id] = $v;
                    } elseif ($d['nombre'] !== 'ANY_VALUE') {
                        $c = $d['colacion'] === null ? Valor::comparar($v, $g['mejor'][$id])
                            : Valor::comparar(Functions::plegar($v, $d['colacion']), Functions::plegar($g['mejor'][$id], $d['colacion']));
                        if ($c !== null && (($d['nombre'] === 'MIN' && $c < 0) || ($d['nombre'] === 'MAX' && $c > 0))) {
                            $g['mejor'][$id] = $v;
                        }
                    }
                } elseif ($modo === 'cuad') {           // varianzas: suma, cuadrados y cuenta
                    $x = is_int($v) || is_float($v) ? $v : Valor::aNumero($v);
                    $g['suma'][$id]   = ($g['suma'][$id] ?? 0) + $x;
                    $g['cuad'][$id]   = ($g['cuad'][$id] ?? 0) + $x * $x;
                    $g['cuenta'][$id] = ($g['cuenta'][$id] ?? 0) + 1;
                } else {                                // BIT_AND, BIT_OR, BIT_XOR
                    $g['mejor'][$id] = self::bits($d['nombre'], $g['mejor'][$id] ?? ($d['nombre'] === 'BIT_AND' ? -1 : 0), $v);
                }
            }
            unset($g);
        }
        if ($exprs === [] && $grupos === []) {
            // Agregación total: una fila aunque no haya datos
            $grupos[''] = ['fila' => [], 'n' => 0, 'suma' => [], 'cuenta' => [], 'mejor' => [], 'lista' => []];
        }

        $out = [];
        foreach ($grupos as $g) {
            $valores = [];
            foreach ($defs as $id => $d) {
                $cuenta = $g['cuenta'][$id] ?? 0;
                if ($d['star']) {
                    $valores[$id] = $d['filtro'] === null ? $g['n'] : $cuenta;
                } elseif ($d['modo'] === 'lista') {
                    $sep = ',';
                    if ($d['sep'] !== null) {
                        $s   = Evaluator::evaluar($d['sep'], ['fila' => $g['fila'], 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa]);
                        $sep = $s === null ? '' : Valor::aTexto($s);
                    }
                    $lista = $g['lista'][$id] ?? [];
                    if ($d['distinct'] && $d['orden'] === null) {
                        $valores[$id] = $d['nombre'] === 'COUNT' ? count($lista)
                            : self::agregado($d['nombre'], array_values($lista), $g['n'], false, $sep, $d['colacion']);
                        continue;
                    }
                    if (($d['orden'] ?? null) !== null && count($lista) > 1) {
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
                } elseif ($d['nombre'] === 'SUM') {
                    $valores[$id] = $cuenta === 0 ? null : $g['suma'][$id];
                } elseif ($d['nombre'] === 'TOTAL') {
                    $valores[$id] = (float)($g['suma'][$id] ?? 0);   // 0.0 sin valores, como SQLite
                } elseif ($d['nombre'] === 'AVG') {
                    // Siempre decimal, como SQLite y MySQL: con / de PHP, una
                    // media exacta salía entera (-10) y otra decimal (-5.33)
                    $valores[$id] = $cuenta === 0 ? null : (float)($g['suma'][$id] / $cuenta);
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
            && in_array($d['nombre'], ['COUNT', 'SUM', 'TOTAL', 'AVG', 'MIN', 'MAX'], true));
    }

    /**
     * COUNT(DISTINCT …) y los demás de distintosEnDisco(), sin GROUP BY, cuando
     * sus valores distintos no cupieron en memoria (2.9; ver Select::correr()):
     * según pasan las filas, cada valor va a una de 64 particiones por su
     * clave, y los demás agregados se acumulan como siempre. Un valor está
     * siempre en la misma partición, así que los distintos de cada una, que
     * caben, son los distintos de todos; al final se suman los de cada una.
     *
     * @return list<array{0: array, 1: array<string,mixed>}>
     */
    private static function distintosPorParticiones(iterable $filas, array $defs, callable $sub,
                                                    callable $conjunto, array $externa, string $carpeta): array
    {
        $distintos = self::distintosEnDisco($defs);
        $volcado   = new Volcado($carpeta);
        try {
            $args = $filtros = [];
            foreach ($distintos as $id => $d) {
                $args[$id]    = EvaluatorCompilado::compilar($d['arg']) ?? $d['arg'];
                $filtros[$id] = $d['filtro'] === null ? null : (EvaluatorCompilado::compilar($d['filtro']) ?? $d['filtro']);
            }
            $repartir = static function () use ($filas, $distintos, $args, $filtros, $sub, $conjunto, $externa, $volcado): \Generator {
                foreach ($filas as $fila) {
                    $ctx = ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa];
                    foreach ($distintos as $id => $d) {
                        $f = $filtros[$id];
                        if ($f !== null && Valor::verdadero($f instanceof \Closure ? $f($fila) : Evaluator::evaluar($f, $ctx)) !== true) {
                            continue;
                        }
                        $v = $args[$id] instanceof \Closure ? $args[$id]($fila) : Evaluator::evaluar($args[$id], $ctx);
                        if ($v !== null) {
                            $k = Valor::clave($d['colacion'] === null ? $v : Functions::plegar($v, $d['colacion']));
                            $volcado->anadir([$id, $k, $v], crc32($k) % self::PARTICIONES);
                        }
                    }
                    yield $fila;
                }
            };
            $grupo = [];
            foreach (self::agrupar($repartir(), [], array_diff_key($defs, $distintos), $sub, $conjunto, $externa) as $g) {
                $grupo = $g;                            // sin GROUP BY, un grupo
            }
            // Los de cada partición, y de ellos, el de todas
            $parciales = [];
            for ($p = 0; $p < self::PARTICIONES; $p++) {
                $vistos = [];
                foreach ($volcado->leer($p) as [$id, $k, $v]) {
                    $vistos[$id][$k] ??= $v;
                }
                foreach ($vistos as $id => $unicos) {
                    $d = $distintos[$id];
                    $parciales[$id]['n'][] = count($unicos);
                    $parciales[$id]['v'][] = self::agregado($d['nombre'] === 'AVG' || $d['nombre'] === 'COUNT' ? 'SUM' : $d['nombre'],
                        $d['nombre'] === 'COUNT' ? [] : array_values($unicos), count($unicos), false, ',', $d['colacion']);
                }
            }
            foreach ($distintos as $id => $d) {
                $n = array_sum($parciales[$id]['n'] ?? []);
                $v = $parciales[$id]['v'] ?? [];
                $grupo[1][$id] = match ($d['nombre']) {
                    'COUNT' => $n,
                    'AVG'   => $n === 0 ? null : (float)(self::agregado('SUM', $v, 0, false) / $n),
                    default => self::agregado($d['nombre'], $v, $n, false, ',', $d['colacion']),
                };
            }
            return [$grupo];
        } finally {
            $volcado->borrar();
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
            if (is_array($v) && $k !== 'select') {
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
                    $n['when'][$i] = [self::marcarAgregados($c, $defs), self::marcarAgregados($r, $defs)];
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
                return count($vistos);
            }
        } else {
            foreach ($valores as $v) {
                if ($v !== null) { $limpios[] = $v; }
            }
        }

        switch ($nombre) {
            case 'COUNT':
                return count($limpios);
            case 'SUM':
                if ($limpios === []) { return null; }
                $s = 0;
                foreach ($limpios as $v) { $s += Valor::aNumero($v); }
                return $s;
            case 'TOTAL':
                // Como SUM, pero siempre decimal y 0.0 sin valores (SQLite)
                $s = 0;
                foreach ($limpios as $v) { $s += Valor::aNumero($v); }
                return (float)$s;
            case 'AVG':
                if ($limpios === []) { return null; }
                $s = 0;
                foreach ($limpios as $v) { $s += Valor::aNumero($v); }
                return (float)($s / count($limpios));        // siempre decimal, como SQLite y MySQL
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
                    $c = $colacion === null ? Valor::comparar($v, $res) : Valor::comparar(Functions::plegar($v, $colacion), Functions::plegar($res, $colacion));
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
                return self::varianza($nombre, count($limpios), $s, $c);
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
        return in_array($nombre, ['VARIANCE', 'VAR_POP', 'VAR_SAMP'], true) ? (float)$var : sqrt($var);
    }

    /** Un paso de BIT_AND, BIT_OR o BIT_XOR, con enteros de 64 bits */
    private static function bits(string $nombre, int $r, $v): int
    {
        $x = Valor::entero(Valor::aNumero($v));
        return $nombre === 'BIT_AND' ? $r & $x : ($nombre === 'BIT_OR' ? $r | $x : $r ^ $x);
    }
}
