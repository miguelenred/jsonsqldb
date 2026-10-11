<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * La búsqueda por índices: qué condiciones de un WHERE (igualdades, IN,
 * rangos y conjunciones) puede resolver un índice, con cuál, y si un valor
 * puede estar en un trozo según su filtro o su rango. Métodos de Indexes.
 * Van aparte para que una escritura que no busca filas, como un INSERT, no
 * tenga que compilarlos (2.9): sin OPcache, PHP compila en cada petición lo
 * que carga. Hereda de Indexes solo para llegar a lo protegido; no se
 * instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class IndexesBusqueda extends Indexes
{
    private function __construct()
    {
    }

    /**
     * Saca del WHERE los predicados de igualdad que un índice puede resolver.
     *
     * Solo se miran las conjunciones de primer nivel (`a = 1 AND b IN (2,3)`) y
     * solo `=` e `IN` contra literales. Queda fuera a propósito:
     *
     *   - el OR de primer nivel, que no permite descartar ninguna fila;
     *   - todo lo que cuelgue de un NOT, donde la condición está negada;
     *   - `IS NULL`, porque los NULL no están en el índice y filtrar por él
     *     dejaría fuera las filas que un LEFT JOIN rellena con NULL;
     *   - `NOT IN` y los rangos, que no son igualdades.
     *
     * Filtrar la tabla antes del JOIN es seguro porque el WHERE se aplica
     * después de cruzar: una fila rellenada con NULL nunca supera `col = valor`,
     * así que el resultado final es el mismo tanto si se descartó antes como si
     * se descartó después.
     *
     * El resultado es  tabla o alias en minúsculas => columna => valores. Las
     * columnas sin cualificar solo se aceptan cuando el FROM tiene un único
     * origen: con varios no se sabe de cuál son sin resolver el mapa de
     * columnas, y eso exige haber leído ya las tablas.
     *
     * @return array<string, array<string, list<mixed>>>
     */
    public static function predicados(?array $where, ?string $aliasUnico): array
    {
        if ($where === null) {
            return [];
        }
        $out = [];
        foreach (self::conjunciones($where) as $n) {
            [$col, $valores] = self::igualdad($n);
            if ($col === null) {
                continue;
            }
            $alias = $col['tabla'] === null ? $aliasUnico : strtolower((string)$col['tabla']);
            if ($alias === null) {
                continue;
            }
            $nombre = strtolower((string)$col['nombre']);
            // Dos condiciones sobre la misma columna: se queda la primera, que
            // basta para acotar. La otra la aplica el WHERE como siempre.
            $out[$alias][$nombre] ??= $valores;
        }
        return $out;
    }

    /**
     * Si el WHERE es solo igualdades de columnas distintas de la tabla (col =
     * literal, col IN (literales), unidas por AND), sus columnas en minúsculas;
     * si no, null (2.9). Entonces un índice que las cubra todas da exactamente
     * las filas que cumplen el WHERE, y un LIMIT se puede aplicar ya al índice.
     *
     * @return list<string>|null
     */
    public static function soloIgualdades(?array $where, ?string $aliasUnico): ?array
    {
        if ($where === null || $aliasUnico === null) {
            return null;
        }
        $cols = [];
        foreach (self::conjunciones($where) as $n) {
            [$col] = self::igualdad($n);
            if ($col === null || ($col['tabla'] !== null && strtolower((string)$col['tabla']) !== $aliasUnico)) {
                return null;
            }
            $nombre = strtolower((string)$col['nombre']);
            if (isset($cols[$nombre])) {
                return null;                        // dos condiciones sobre la misma columna: una la aplica el WHERE
            }
            $cols[$nombre] = true;
        }
        return array_keys($cols);
    }

    /**
     * Rangos numéricos del WHERE por alias y columna: `col > 5`, `col <= 9`,
     * `col BETWEEN 1 AND 3` y sus combinaciones en la cadena de AND de
     * primer nivel, con literales numéricos. Cada rango es [min, max], y un
     * extremo null es abierto. Sirven para saltar las partes de la tabla
     * cuyo trozo de índice no puede contener ningún valor del rango (ver
     * Storage::filasPorRango()); el WHERE se aplica igual a lo que se lee.
     *
     * @return array<string, array<string, array{0: int|float|null, 1: int|float|null}>>
     */
    public static function rangos(?array $where, ?string $aliasUnico): array
    {
        if ($where === null) {
            return [];
        }
        $out = [];
        foreach (self::conjunciones($where) as $n) {
            foreach (self::acotaciones($n) as [$col, $min, $max]) {
                $alias = $col['tabla'] === null ? $aliasUnico : strtolower((string)$col['tabla']);
                if ($alias === null) {
                    continue;
                }
                $nombre = strtolower((string)$col['nombre']);
                $r = $out[$alias][$nombre] ?? [null, null];
                if ($min !== null && ($r[0] === null || $min > $r[0])) { $r[0] = $min; }
                if ($max !== null && ($r[1] === null || $max < $r[1])) { $r[1] = $max; }
                $out[$alias][$nombre] = $r;
            }
        }
        return $out;
    }

    /**
     * Las comparaciones de una columna con un literal que hay en las
     * conjunciones del WHERE: col < 5, 'a' <= col, col = 'x', col BETWEEN x
     * AND y. Devuelve [alias][columna] => [[operador, valor], …] y cuántas de
     * las conjunciones son comparaciones así (si son todas, el WHERE no pide
     * nada más: ver el COUNT(*) por índice).
     *
     * @return array{0: array<string, array<string, list<array{0: string, 1: int|float|string}>>>, 1: int}
     */
    public static function comparaciones(?array $where, ?string $aliasUnico): array
    {
        $out = [];
        $cubiertas = 0;
        foreach ($where === null ? [] : self::conjunciones($where) as $n) {
            $lista = [];
            if (($n['k'] ?? '') === 'between' && empty($n['not']) && ($n['e']['k'] ?? '') === 'col') {
                $a = self::literalComparable($n['min']);
                $b = self::literalComparable($n['max']);
                if ($a !== null && $b !== null) {
                    $lista = [[$n['e'], '>=', $a], [$n['e'], '<=', $b]];
                }
            } elseif (($n['k'] ?? '') === 'bin' && in_array($n['op'] ?? '', ['=', '<', '<=', '>', '>='], true)) {
                [$col, $lit, $op] = [$n['i'], $n['d'], $n['op']];
                if (($col['k'] ?? '') !== 'col') {
                    [$col, $lit, $op] = [$n['d'], $n['i'], strtr($op, ['<' => '>', '>' => '<'])];
                }
                $v = ($col['k'] ?? '') === 'col' ? self::literalComparable($lit) : null;
                if ($v !== null) {
                    $lista = [[$col, $op, $v]];
                }
            }
            if ($lista === []) {
                continue;
            }
            $alias = $lista[0][0]['tabla'] === null ? $aliasUnico : strtolower((string)$lista[0][0]['tabla']);
            if ($alias === null) {
                continue;
            }
            $cubiertas++;
            foreach ($lista as [$col, $op, $v]) {
                $out[$alias][strtolower((string)$col['nombre'])][] = [$op, $v];
            }
        }
        return [$out, $cubiertas];
    }

    /** @return int|float|string|null un literal con el que comparar: número o texto */
    private static function literalComparable(array $n)
    {
        if (($n['k'] ?? '') !== 'lit' || !(is_int($n['v']) || is_float($n['v']) || is_string($n['v']))) {
            return null;
        }
        return $n['v'];
    }

    /**
     * Las acotaciones numéricas de un nodo: [columna, min, max].
     *
     * @return list<array{0: array, 1: int|float|null, 2: int|float|null}>
     */
    private static function acotaciones(array $n): array
    {
        $k = $n['k'] ?? '';
        if ($k === 'between' && empty($n['not']) && ($n['e']['k'] ?? '') === 'col') {
            $a = self::literalNumerico($n['min']);
            $b = self::literalNumerico($n['max']);
            return $a === null || $b === null ? [] : [[$n['e'], $a, $b]];
        }
        if ($k !== 'bin' || !in_array($n['op'] ?? '', ['<', '<=', '>', '>='], true)) {
            return [];
        }
        $op = $n['op'];
        [$col, $lit] = [$n['i'], $n['d']];
        if (($col['k'] ?? '') !== 'col') {
            [$col, $lit] = [$n['d'], $n['i']];
            $op = strtr($op, ['<' => '>', '>' => '<']);      // 5 < col  es  col > 5
        }
        if (($col['k'] ?? '') !== 'col') {
            return [];
        }
        $v = self::literalNumerico($lit);
        if ($v === null) {
            return [];
        }
        // Los extremos abiertos (< y >) se tratan como cerrados: acotar de más
        // solo lee una parte de más, y el WHERE deja fuera el igual
        return $op === '<' || $op === '<=' ? [[$col, null, $v]] : [[$col, $v, null]];
    }

    /** @return int|float|null */
    private static function literalNumerico(array $n)
    {
        if (($n['k'] ?? '') !== 'lit' || $n['v'] === null || is_bool($n['v'])) {
            return null;
        }
        return is_int($n['v']) || is_float($n['v']) ? $n['v'] : (Valor::esNumerico($n['v']) ? Valor::aNumero($n['v']) : null);
    }

    /** @return list<array> conjunciones de primer nivel */
    public static function conjunciones(array $n): array
    {
        if (($n['k'] ?? '') === 'bin' && ($n['op'] ?? '') === 'AND') {
            return array_merge(self::conjunciones($n['i']), self::conjunciones($n['d']));
        }
        return [$n];
    }

    /**
     * Si el nodo es `col = literal` o `col IN (literales)`, devuelve la columna
     * y los valores; si no, [null, []].
     *
     * @return array{0: array|null, 1: list<mixed>}
     */
    private static function igualdad(array $n): array
    {
        $k = $n['k'] ?? '';

        if ($k === 'bin' && ($n['op'] ?? '') === '=') {
            [$col, $lit] = [$n['i'], $n['d']];
            if (($col['k'] ?? '') !== 'col' || ($lit['k'] ?? '') !== 'lit') {
                [$col, $lit] = [$n['d'], $n['i']];
            }
            if (($col['k'] ?? '') === 'col' && ($lit['k'] ?? '') === 'lit' && $lit['v'] !== null) {
                return [$col, [$lit['v']]];
            }
            return [null, []];
        }

        if ($k === 'in' && empty($n['not']) && ($n['select'] ?? null) === null
            && is_array($n['lista'] ?? null) && $n['lista'] !== []
            && ($n['e']['k'] ?? '') === 'col') {
            $valores = [];
            foreach ($n['lista'] as $e) {
                if (($e['k'] ?? '') !== 'lit' || $e['v'] === null) {
                    return [null, []];          // un solo elemento no literal lo invalida
                }
                $valores[] = $e['v'];
            }
            return [$n['e'], $valores];
        }

        return [null, []];
    }

    /**
     * Los índices que sirven para unos predicados de igualdad, del mejor al
     * peor: primero uno único cubierto entero (una fila o ninguna por clave),
     * después el que cubre más columnas por la izquierda, y a igualdad, el
     * orden de definición. Antes, a igualdad de columnas ganaba el primero
     * definido, y los creados a mano van antes que los de la clave primaria:
     * con un índice sobre «ciudad», «WHERE id = ? AND ciudad = ?» leía todas
     * las filas de esa ciudad en vez de una. Quien busca prueba el siguiente
     * si uno no compensa.
     *
     * @return list<array{def: array, claves: list<string>, prefijo: bool}>
     */
    public static function candidatos(array $defs, array $predicados): array
    {
        $lista = [];
        foreach (array_values($defs) as $orden => $def) {
            $n = 0;
            foreach ($def['columns'] as $col) {
                if (!isset($predicados[strtolower($col)])) {
                    break;
                }
                $n++;
            }
            if ($n === 0) {
                continue;
            }
            // Producto de los valores de cada columna cubierta: un IN aporta varios
            $claves = [''];
            $tope = self::MAX_CLAVES;
            if ($n === 1) {
                $numericas = true;
                foreach ($predicados[strtolower($def['columns'][0])] as $v) {
                    $numericas = $numericas && (is_int($v) || is_float($v));
                }
                $tope = $numericas ? self::MAX_CLAVES_NUMERICAS : self::MAX_CLAVES;
            }
            for ($i = 0; $i < $n && $claves !== null; $i++) {
                $valores = $predicados[strtolower($def['columns'][$i])];
                if (count($claves) * count($valores) > $tope) {
                    $claves = null;                 // demasiadas: con este índice sale más barato recorrer
                    break;
                }
                $nuevas = [];
                $c = $def['plegar'][$i] ?? null;    // la colación de la columna (2.9): las claves van con ella
                foreach ($claves as $base) {
                    foreach ($valores as $v) {
                        if ($v !== null) {          // NULL nunca es igual a nada
                            $nuevas[] = $base . self::trozo($c === null ? $v : Functions::plegar($v, $c));
                        }
                    }
                }
                $claves = $nuevas;
            }
            if ($claves === null) {
                continue;
            }
            $claves = array_values(array_unique($claves));
            if ($claves === []) {
                return [];                          // ninguna fila puede cumplirlo
            }
            $lista[] = ['def' => $def, 'claves' => $claves, 'prefijo' => $n < count($def['columns']),
                        'unico' => !empty($def['unico']) && $n === count($def['columns']), 'n' => $n, 'orden' => $orden];
        }
        usort($lista, static fn($a, $b) => [$b['unico'], $b['n'], $a['orden']] <=> [$a['unico'], $a['n'], $b['orden']]);
        return array_map(static fn($c) => ['def' => $c['def'], 'claves' => $c['claves'], 'prefijo' => $c['prefijo'], 'n' => $c['n']], $lista);
    }

    /**
     * De las igualdades del WHERE (col = 'texto' o col IN ('a', 'b')), los
     * textos que una parte tiene que contener, entre comillas como van en el
     * fichero, para que alguna de sus filas pueda cumplirlas (ver
     * Storage::parte()). Sirven a la lectura y a UPDATE y DELETE. Solo textos que no son números (un número se compara
     * por su valor, '5' = 5.0) y de caracteres que ningún JSON escribe de otra
     * manera: letras, cifras, espacios y signos corrientes, sin comillas,
     * barras ni < > & '. Con cualquier otro valor, esa columna no aporta nada.
     *
     * @param array<string, list<mixed>> $predicados columna => valores
     * @param array<string, mixed>       $colaciones las columnas (en minúsculas) con COLLATE, que no sirven
     * @return list<list<string>>
     */
    public static function agujas(array $predicados, array $colaciones = []): array
    {
        $out = [];
        foreach ($predicados as $col => $valores) {
            if (isset($colaciones[$col])) {
                continue;                           // con COLLATE, 'Ana' cumple col = 'ANA' (2.9)
            }
            $lista = [];
            foreach ($valores as $v) {
                if (!is_string($v) || $v === '' || is_numeric(trim($v)) || !preg_match('#^[\x20-\x7E]+$#', $v)
                    || strpbrk($v, "\"\\/<>&'") !== false) {
                    continue 2;
                }
                $lista[] = '"' . $v . '"';
            }
            if ($lista !== []) {
                $out[] = $lista;
            }
        }
        return $out;
    }

    /**
     * ¿La clave de este valor es de fiar para decidir una igualdad?
     *
     * Casi siempre sí. La excepción son los números tan grandes que ya no caben
     * exactos en un entero: ahí un mismo valor puede escribirse de dos formas
     * que el motor considera iguales y que dan claves distintas. Son valores por
     * encima de 9·10^18, que en la práctica no aparecen, pero cuando el conjunto
     * se usa para responder directamente —como en un IN— no hay un WHERE detrás
     * que corrija el fallo, así que esos se comparan uno a uno.
     *
     * @param mixed $v
     */
    public static function claveFiable($v): bool
    {
        if (is_bool($v) || $v === null || !Valor::esNumerico($v)) {
            return true;
        }
        $n = Valor::aNumero($v);
        return is_int($n) ? abs($n) < 9.0E+18 : (is_finite($n) && abs($n) < 9.0E+18);
    }

    /**
     * El conjunto de un IN con subconsulta (2.9), con la colación con la que
     * compara: la de x ($colacion, ver Evaluator::resolver()) o, si x no la
     * decide (true), la de lo que devuelve la subconsulta ($deLaSub). Con
     * una, los valores van plegados y el conjunto la lleva en [3], para
     * plegar x igual.
     *
     * @param list<mixed> $valores
     */
    public static function conjuntoDeIn(array $valores, string|bool|null $colacion, ?string $deLaSub): array
    {
        $c = $colacion === true ? $deLaSub : $colacion;
        if (!is_string($c) || $c === 'BINARY') {
            return self::conjunto($valores);
        }
        $out    = self::conjunto(array_map(static fn($v) => Functions::plegar($v, $c), $valores));
        $out[3] = $c;
        return $out;
    }

    /**
     * Agrupa unos valores por su clave, para poder resolver un IN sin recorrer
     * la lista entera en cada fila.
     *
     * Devuelve [conjunto, dudosos, hayNulo]. En el conjunto, cada clave lleva
     * el valor original que le corresponde, o la lista de ellos si son varios:
     * la clave acota los candidatos pero la igualdad la sigue decidiendo
     * Valor::comparar(), porque dos valores distintos pueden compartir clave.
     * Uno solo va tal cual (2.9): con un millón de valores, una lista de uno
     * por clave eran más de 150 MB.
     *
     * @param list<mixed> $valores
     * @return array{0: array<string, mixed>, 1: list<mixed>, 2: bool}
     */
    public static function conjunto(array $valores): array
    {
        $conjunto = [];
        $dudosos  = [];
        $hayNulo  = false;

        foreach ($valores as $x) {
            if ($x === null) { $hayNulo = true; continue; }
            if (!self::claveFiable($x)) { $dudosos[] = $x; continue; }
            $k = (string)self::clave([$x]);
            if (!isset($conjunto[$k])) {
                $conjunto[$k] = $x;
            } elseif (is_array($conjunto[$k])) {
                $conjunto[$k][] = $x;
            } else {
                $conjunto[$k] = [$conjunto[$k], $x];
            }
        }
        return [$conjunto, $dudosos, $hayNulo];
    }

    /**
     * ¿Puede estar alguna clave con estos valores numéricos en un trozo con
     * este rango? Un valor null es una clave de texto, que puede estar en
     * cualquiera. Con rango desconocido (null) sí; con un trozo vacío ([]) no.
     *
     * @param list<int|float|null> $valores los de valorNumerico() de cada clave
     */
    public static function cabeEnRango(array $valores, ?array $rango): bool
    {
        if ($rango === null) {
            return true;
        }
        if ($rango === []) {
            return false;
        }
        foreach ($valores as $v) {
            if ($v === null || ($v >= $rango[0] && $v <= $rango[1])) {
                return true;
            }
        }
        return false;
    }
}
