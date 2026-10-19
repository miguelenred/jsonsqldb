<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Evaluación de expresiones.
 *
 * Las filas circulan como array plano con claves "alias.columna", de modo que
 * evaluar una columna es un acceso directo al array. Antes de recorrer las filas
 * se llama a resolver(), que deja escrita en cada nodo 'col' la clave exacta:
 * así el trabajo de resolver nombres y ambigüedades se hace una vez por consulta
 * y no una vez por fila.
 *
 * Contexto de evaluación:
 *   ['fila' => array, 'agregados' => ?array de resultados por agid, 'sub' => callable(AST): filas]
 *
 * Las expresiones compiladas en clausuras están en EvaluatorCompilado; LIKE,
 * GLOB y REGEXP, en EvaluatorPatrones, e IN fila a fila, en EvaluatorIn.
 *
 * https://miguelenred.es/jsonsqldb
 */
class Evaluator
{
    /** Las funciones de agregación (ver SelectAgrupada::agregado()) */
    private const AGREGADOS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT', 'TOTAL',
        'ANY_VALUE', 'JSON_GROUP_ARRAY', 'JSON_ARRAYAGG', 'JSON_GROUP_OBJECT', 'JSON_OBJECTAGG', 'STD', 'STDDEV',
        'STDDEV_POP', 'STDDEV_SAMP', 'VARIANCE', 'VAR_POP', 'VAR_SAMP', 'BIT_AND', 'BIT_OR', 'BIT_XOR'];

    /** Identificador único por subconsulta, para poder cachear su resultado */
    private static int $sid = 0;

    /**
     * Se pone a true cuando una columna se resuelve contra la consulta exterior.
     * Es lo que permite saber si una subconsulta está correlacionada y, por
     * tanto, si su resultado se puede cachear o hay que recalcularlo por fila.
     */
    public static bool $correlacionada = false;

    /**
     * Las columnas de fuera que se han resuelto (su clave), junto con la marca
     * de arriba: una subconsulta que mira dos niveles más afuera hace
     * correlacionada también a la del medio (ver Select::correr()).
     *
     * @var array<string, true>
     */
    public static array $externas = [];

    /**
     * Fija la clave real de cada referencia a columna.
     *
     * @param array $mapa  'alias.col' => 'alias.col' y 'col' => 'alias.col' | false si es ambigua;
     *                     "\0c:alias.col" => la colación de esa columna, si la tiene (2.9);
     *                     "\0a:alias.col" => su afinidad, 'N' o 'T', si es de una tabla (2.10)
     * @param array $alias nombres de salida del SELECT admitidos (ORDER BY / GROUP BY por alias)
     */
    public static function resolver(array $n, array $mapa, array $alias = [], array $externo = []): array
    {
        $n = self::resolverNodo($n, $mapa, $alias, $externo);
        // Con su colación escrita ya calculada (ver Parser::colacionEscrita()),
        // como las comparaciones desde el analizador (2.9): NULLIF, MIN, MAX…
        // la buscan en cada argumento, y anidados se recorría cada vez todo lo
        // de dentro. 2.000 NULLIF anidados, 2 s; así, cada nodo solo mira sus
        // hijos, que ya la tienen
        if (!isset($n['escrita']) && \in_array($n['k'], ['fn', 'bin', 'un', 'case', 'cast', 'in', 'between', 'like', 'regexp', 'null'], true)) {
            $n['escrita'] = Parser::colacionEscrita($n) ?? '';
        }
        return $n;
    }

    /** Ver resolver() */
    private static function resolverNodo(array $n, array $mapa, array $alias, array $externo): array
    {
        switch ($n['k']) {
            case 'intervalo':
                throw JsonSqlDbError::syntax('INTERVAL solo vale sumado o restado a una fecha (fecha + INTERVAL 1 DAY) o dentro de DATE_ADD y DATE_SUB');
            case 'col':
                $buscado = $n['tabla'] === null
                    ? strtolower($n['nombre'])
                    : strtolower($n['tabla'] . '.' . $n['nombre']);

                if (\array_key_exists($buscado, $mapa)) {
                    if ($mapa[$buscado] === false) {
                        throw JsonSqlDbError::schema("La columna '{$n['nombre']}' es ambigua: indica la tabla");
                    }
                    $n['clave'] = $mapa[$buscado];
                    if (isset($mapa["\0c:" . $n['clave']])) {
                        $n['colacion'] = $mapa["\0c:" . $n['clave']];    // COLLATE de la columna (2.9)
                    }
                    if (isset($mapa["\0a:" . $n['clave']])) {
                        $n['afinidad'] = $mapa["\0a:" . $n['clave']];    // y su afinidad (2.10)
                    }
                    return $n;
                }
                if ($n['tabla'] === null && isset($alias[$buscado])) {
                    $n['alias'] = $alias[$buscado];      // se resolverá con la fila ya proyectada
                    return $n;
                }
                // Subconsulta correlacionada: la columna es de la consulta de
                // fuera. Se marca para leerla de la fila exterior al evaluar.
                if (isset($externo[$buscado]) && $externo[$buscado] !== false) {
                    $n['externa']       = true;
                    $n['claveExterna']  = $externo[$buscado];
                    self::$externas[$n['claveExterna']] = true;
                    if (isset($externo["\0c:" . $n['claveExterna']])) {
                        $n['colacion'] = $externo["\0c:" . $n['claveExterna']];
                    }
                    if (isset($externo["\0a:" . $n['claveExterna']])) {
                        $n['afinidad'] = $externo["\0a:" . $n['claveExterna']];
                    }
                    self::$correlacionada = true;
                    return $n;
                }
                $completo = $n['tabla'] === null ? $n['nombre'] : $n['tabla'] . '.' . $n['nombre'];
                throw JsonSqlDbError::schema("Columna desconocida: '$completo'");

            case 'bin':
                $n['i'] = self::resolver($n['i'], $mapa, $alias, $externo);
                $n['d'] = self::resolver($n['d'], $mapa, $alias, $externo);
                if (isset(self::COMPARACIONES[$n['op']])) {
                    [$n['i'], $n['d']] = self::afinar($n['i'], $n['d']);
                    if (($c = self::colacionDe($n['i'], $n['d'])) !== null) {
                        [$n['i'], $n['d']] = [self::plegada($n['i'], $c), self::plegada($n['d'], $c)];
                    }
                }
                return $n;

            case 'un':
                $n['e'] = self::resolver($n['e'], $mapa, $alias, $externo);
                return $n;

            case 'fn':
                foreach ($n['args'] as $i => $a) {
                    $n['args'][$i] = self::resolver($a, $mapa, $alias, $externo);
                }
                foreach ($n['orden'] ?? [] as $i => $o) {
                    $n['orden'][$i]['expr']    = self::resolver($o['expr'], $mapa, $alias, $externo);
                    $n['orden'][$i]['binaria'] = Parser::colacionEscrita($n['orden'][$i]['expr']) === 'BINARY';   // 2.10
                }
                if (isset($n['filtro'])) {
                    $n['filtro'] = self::resolver($n['filtro'], $mapa, $alias, $externo);
                }
                // TRIM, LTRIM y RTRIM con un conjunto que puede tener caracteres
                // de más de un byte o '..' van con '*', carácter a carácter (ver
                // Functions::escalar()). Uno escrito se mira aquí, una vez: el
                // de siempre, en ASCII, sigue el camino de siempre (2.9)
                if (isset($n['args'][1], self::RECORTES[$n['nombre']])
                    && ($n['args'][1]['k'] !== 'lit' || preg_match(Functions::POR_CARACTERES, Valor::aTexto($n['args'][1]['v'])))) {
                    $n['nombre'] .= '*';
                }
                // NULLIF, y MIN y MAX de varios valores, comparan con la
                // colación del primer argumento que la tiene, como en SQLite (2.9)
                if (isset(self::COMPARAN[$n['nombre']]) && \count($n['args']) > ($n['nombre'] === 'NULLIF' ? 0 : 1)) {
                    $c = null;
                    foreach ($n['args'] as $a) {
                        $c ??= self::colacionSalida($a);
                    }
                    if ($c !== null && $c !== 'BINARY') {
                        $n['colacion'] = $c;
                    }
                }
                return $n;

            case 'ventana':                       // f(…) OVER (…)
                return SelectAvanzada::resolverVentana($n, $mapa, $externo);

            case 'case':
                if ($n['base'] !== null) {
                    $n['base'] = self::resolver($n['base'], $mapa, $alias, $externo);
                }
                foreach ($n['when'] as $i => [$cond, $res]) {
                    $n['when'][$i] = [self::resolver($cond, $mapa, $alias, $externo), self::resolver($res, $mapa, $alias, $externo)];
                }
                if ($n['else'] !== null) {
                    $n['else'] = self::resolver($n['else'], $mapa, $alias, $externo);
                }
                // CASE x WHEN y …: cada x = y con su afinidad (2.10); si es
                // x la que pasa a texto, solo para ese WHEN ('texto' en él)
                if ($n['base'] !== null) {
                    foreach ($n['when'] as $i => [$cond, $res]) {
                        $n['when'][$i] = self::pasaATexto($n['base'], $cond) ? [$cond, $res, true]
                            : [self::afinar($n['base'], $cond)[1], $res];
                    }
                }
                // CASE col WHEN …: con la colación de la columna, como col = …,
                // o la escrita con COLLATE, que aquí no aplica el analizador
                if ($n['base'] !== null) {
                    $ops = [$n['base'], ...array_column($n['when'], 0)];
                    $c   = null;
                    foreach ($ops as $o) {
                        $c ??= Parser::colacionEscrita($o);
                    }
                    $c = $c === 'BINARY' ? null : ($c ?? self::colacionDe(...$ops));
                    if ($c !== null) {
                        $n['base'] = self::plegada($n['base'], $c);
                        foreach ($n['when'] as $i => [$cond]) {
                            $n['when'][$i][0] = self::plegada($cond, $c);
                        }
                    }
                }
                return $n;

            case 'in':
                $n['e'] = self::resolver($n['e'], $mapa, $alias, $externo);
                $ae     = self::afinidadDe($n['e']);
                unset($n['textoEn']);
                if ($n['lista'] !== null) {
                    // Cada x = valor con su afinidad (2.10, ver afinar()); si es
                    // x la que pasa a texto, solo para ese valor ('textoEn')
                    // Lo de afinar() con lo que ya se sabe de x, sin volver a
                    // mirarlo en cada valor, y un literal sin resolver (un IN
                    // de cien claves hacía un 1,5 % más que en la 2.9)
                    foreach ($n['lista'] as $i => $a) {
                        $lit = $a['k'] === 'lit';
                        if (!$lit) {
                            $a = self::resolver($a, $mapa, $alias, $externo);
                        }
                        $aa = $lit ? '' : self::afinidadDe($a);
                        if ($aa !== $ae && $aa !== null && $ae !== null) {
                            if ($ae === '' && $aa === 'T') {
                                $n['textoEn'][$i] = true;
                            } elseif ($ae === 'T' && $aa === '') {
                                $a = self::comoTexto($a);
                            } elseif ($ae === 'N' && $lit && \is_string($a['v'])) {
                                $a = self::comoNumero($a);
                            }
                        }
                        $n['lista'][$i] = $a;
                    }
                }
                // Con una subconsulta, con la afinidad de x y la de lo que
                // devuelve, que se sabe al ejecutarla (ver IndexesBusqueda::conjuntoDeIn())
                $n['afinidadIn'] = $ae;
                // x IN (…) con la colación de x si es una columna (2.9). Con una
                // subconsulta, si x no la decide (ni es una columna ni lleva
                // COLLATE), la de lo que devuelve, como en SQLite: eso se sabe
                // al ejecutarla, y es entonces cuando se pliega (ver EvaluatorIn::in())
                if ($n['lista'] !== null) {
                    if (($c = self::colacionDe($n['e'])) !== null) {
                        $n['e'] = self::plegada($n['e'], $c);
                        $n['lista'] = array_map(static fn(array $a): array => self::plegada($a, $c), $n['lista']);
                    }
                } else {
                    $c = self::colacionSalida($n['e']) ?? true;
                    unset($n['colacionIn']);
                    if ($c !== 'BINARY') {
                        $n['colacionIn'] = $c;
                    }
                }
                if ($n['select'] !== null && !isset($n['sid'])) {
                    $n['sid'] = ++self::$sid;
                }
                // Una lista de literales vale igual para todas las filas: se
                // agrupa aquí una vez en lugar de recorrerla en cada una
                if ($n['lista'] !== null && $n['lista'] !== []) {
                    $valores = [];
                    foreach ($n['lista'] as $a) {
                        if (($a['k'] ?? '') !== 'lit') { return $n; }
                        $valores[] = $a['v'];
                    }
                    $n['conjunto'] = IndexesBusqueda::conjunto($valores, $ae === 'T');
                }
                return $n;                       // la subconsulta se resuelve al ejecutarse

            case 'between':
                $n['e']   = self::resolver($n['e'], $mapa, $alias, $externo);
                $n['min'] = self::resolver($n['min'], $mapa, $alias, $externo);
                $n['max'] = self::resolver($n['max'], $mapa, $alias, $externo);
                // x >= a y x <= b, cada una con su afinidad (2.10); si es x
                // la que pasa a texto, solo para esa ('textoMin', 'textoMax')
                $n['textoMin'] = self::pasaATexto($n['e'], $n['min']);
                $n['textoMax'] = self::pasaATexto($n['e'], $n['max']);
                $n['min']      = self::afinar($n['e'], $n['min'])[1];
                $n['max']      = self::afinar($n['e'], $n['max'])[1];
                if (($c = self::colacionDe($n['e'], $n['min'], $n['max'])) !== null) {
                    [$n['e'], $n['min'], $n['max']] = [self::plegada($n['e'], $c), self::plegada($n['min'], $c), self::plegada($n['max'], $c)];
                }
                return $n;

            case 'like':
                $n['e']      = self::resolver($n['e'], $mapa, $alias, $externo);
                $n['patron'] = self::resolver($n['patron'], $mapa, $alias, $externo);
                if ($n['escape'] !== null) {
                    $n['escape'] = self::resolver($n['escape'], $mapa, $alias, $externo);
                }
                return $n;

            case 'regexp':
                $n['e']      = self::resolver($n['e'], $mapa, $alias, $externo);
                $n['patron'] = self::resolver($n['patron'], $mapa, $alias, $externo);
                return $n;

            case 'cast':
                $n['e'] = self::resolver($n['e'], $mapa, $alias, $externo);
                return $n;

            case 'null':
                $n['e'] = self::resolver($n['e'], $mapa, $alias, $externo);
                return $n;
        }

        if (($n['k'] === 'sub' || $n['k'] === 'exists') && !isset($n['sid'])) {
            $n['sid'] = ++self::$sid;
        }
        return $n;                                // 'lit' no necesita resolución
    }

    /** Los operadores que comparan con la colación de una columna (2.9) */
    private const COMPARACIONES = ['=' => true, '<>' => true, '<' => true, '<=' => true, '>' => true, '>=' => true, '<=>' => true];
    /** Las funciones que quitan caracteres de los extremos (ver resolver()) */
    private const RECORTES = ['TRIM' => true, 'LTRIM' => true, 'RTRIM' => true];
    /** Las funciones que comparan sus argumentos con su colación, como en SQLite (2.9) */
    private const COMPARAN = ['NULLIF' => true, 'MIN' => true, 'MAX' => true, 'GREATEST' => true, 'LEAST' => true];

    /**
     * La afinidad de una expresión (2.10, como en SQLite): la de la columna
     * que es ('N' o 'T', la de su tipo), la del tipo de un CAST, la de lo que
     * lleva un COLLATE; '' si es otra cosa (un literal, un parámetro, una
     * expresión, una columna de una subconsulta que no viene de una tabla);
     * null si no se sabe (una subconsulta, un alias de la salida): entonces
     * se compara como antes de la 2.10.
     */
    public static function afinidadDe(array $n): ?string
    {
        switch ($n['k'] ?? '') {
            case 'col':
                return isset($n['clave']) || isset($n['externa']) ? ($n['afinidad'] ?? '') : null;
            case 'cast':
                return self::afinidadDeclarada((string)$n['tipo']);
            case 'fn':
                return $n['nombre'] === 'COLLATE' || $n['nombre'] === 'PLEGAR' ? self::afinidadDe($n['args'][0]) : '';
            case 'sub':
                return null;
        }
        return '';
    }

    /**
     * La afinidad de un CAST(x AS tipo) (2.10): la del tipo escrito, o '' si
     * no se reconoce. Aquí, su único uso: en Types lo compilaba cada INSERT
     */
    private static function afinidadDeclarada(string $declarado): string
    {
        if (strtoupper(trim($declarado)) === 'UNSIGNED') {
            return 'N';
        }
        try {
            return Types::afinidad(Types::parse($declarado)['type']);
        } catch (JsonSqlDbError) {
            return '';
        }
    }

    /**
     * Los dos lados de una comparación, con la afinidad que manda (2.10,
     * como en SQLite): si uno es de texto y el otro no tiene, el otro pasa a
     * texto (un número, a su texto: col_texto = 7 busca '7'); si uno es
     * numérico y el otro no, un literal que es un número pasa a número. Lo
     * demás lo decide Valor::comparar() al comparar.
     *
     * @return array{0: array, 1: array}
     */
    private static function afinar(array $i, array $d): array
    {
        // Un literal no tiene afinidad, y comoNumero() solo cambia un texto
        // literal: casi todas las comparaciones llevan uno, y se ve aquí sin
        // llamarlas (2.10, antes de publicarla: un JOIN por clave hacía más que en la 2.9)
        $ai = ($i['k'] ?? '') === 'lit' ? '' : self::afinidadDe($i);
        $ad = ($d['k'] ?? '') === 'lit' ? '' : self::afinidadDe($d);
        if ($ai === $ad || $ai === null || $ad === null) {
            return [$i, $d];
        }
        if ($ai === 'T' && $ad === '') {
            return [$i, self::comoTexto($d)];
        }
        if ($ad === 'T' && $ai === '') {
            return [self::comoTexto($i), $d];
        }
        if ($ai === 'N') {
            return [$i, ($d['k'] ?? '') === 'lit' && \is_string($d['v']) ? self::comoNumero($d) : $d];
        }
        return $ad === 'N' && ($i['k'] ?? '') === 'lit' && \is_string($i['v']) ? [self::comoNumero($i), $d] : [$i, $d];
    }

    /** ¿Pasa $x a texto para compararse con $otro (2.10, ver afinar())? */
    private static function pasaATexto(array $x, array $otro): bool
    {
        return self::afinidadDe($x) === '' && self::afinidadDe($otro) === 'T';
    }

    /** La expresión con afinidad de texto (2.10): un número escrito, ya en texto; lo demás, con CAST(… AS TEXT) */
    private static function comoTexto(array $n): array
    {
        if ($n['k'] === 'lit') {
            return ['k' => 'lit', 'v' => Valor::conAfinidad($n['v'], 'T')];
        }
        if ($n['k'] === 'fn' && $n['nombre'] === 'PLEGAR' && $n['args'][0]['k'] === 'lit') {
            $n['args'][0] = self::comoTexto($n['args'][0]);
            return $n;
        }
        return ['k' => 'cast', 'e' => $n, 'tipo' => 'TEXT'];
    }

    /** Un texto escrito que es un número, ya como número (2.10); lo demás lo decide Valor::comparar() */
    private static function comoNumero(array $n): array
    {
        return $n['k'] === 'lit' && \is_string($n['v']) && Valor::esNumerico($n['v'])
            ? ['k' => 'lit', 'v' => trim($n['v']) + 0] : $n;
    }

    /**
     * La colación con la que se compara (2.9), como en SQLite: la escrita
     * con COLLATE (que ya aplica el analizador: entonces aquí nada); si no,
     * la de la columna de la izquierda —aunque sea la binaria— y, si a la
     * izquierda no hay una columna, la de la derecha. Null: la binaria.
     */
    private static function colacionDe(array ...$operandos): ?string
    {
        foreach ($operandos as $o) {
            if (Parser::colacionEscrita($o) !== null) {
                return null;
            }
        }
        foreach ($operandos as $o) {
            if (($col = self::columnaDe($o)) !== null) {
                return $col['colacion'] ?? null;
            }
        }
        return null;
    }

    /**
     * La clave en la fila de una expresión que es una columna de la consulta
     * (no de la exterior), para leerla sin más; null si no lo es.
     */
    public static function claveDirecta(array $n): ?string
    {
        return $n['k'] === 'col' && !isset($n['externa']) ? $n['clave'] ?? null : null;
    }

    /** La columna que es la expresión, aunque vaya dentro de un CAST (como en SQLite), o null */
    private static function columnaDe(array $n): ?array
    {
        while (($n['k'] ?? '') === 'cast') {
            $n = $n['e'];
        }
        return ($n['k'] ?? '') === 'col' ? $n : null;
    }

    /**
     * La colación de una expresión como la ve SQLite (2.9), la que hereda una
     * columna de una vista o una subconsulta: la escrita con COLLATE; si no,
     * la de la columna que es (BINARY si no tiene); null si no es ninguna de
     * las dos cosas.
     */
    public static function colacionSalida(array $n): ?string
    {
        if (($c = Parser::colacionEscrita($n)) !== null) {
            return $c;
        }
        $col = self::columnaDe($n);
        return $col === null ? null : ($col['colacion'] ?? 'BINARY');
    }

    /** La expresión con lo que se compara en esa colación; un literal, ya plegado */
    private static function plegada(array $n, string $colacion): array
    {
        if ($n['k'] === 'lit') {
            return ['k' => 'lit', 'v' => Functions::plegar($n['v'], $colacion)];
        }
        return ['k' => 'fn', 'nombre' => 'PLEGAR', 'args' => [$n, ['k' => 'lit', 'v' => $colacion]], 'star' => false, 'distinct' => false];
    }

    /**
     * Para ORDER BY, GROUP BY y PARTITION BY (2.9): plegada, si tiene
     * colación (ver colacionSalida()) y no la ha plegado ya el analizador; lo
     * demás, igual.
     */
    public static function conColacion(array $n): array
    {
        if (($n['k'] ?? '') === 'fn' && $n['nombre'] === 'PLEGAR') {
            return $n;
        }
        $c = self::colacionSalida($n);
        return $c === null || $c === 'BINARY' ? $n : self::plegada($n, $c);
    }

    /** ¿La expresión contiene alguna función de agregación? */
    public static function tieneAgregado(array $n): bool
    {
        if ($n['k'] === 'fn' && self::esAgregado($n)) {
            return true;
        }
        foreach (self::hijos($n) as $h) {
            if (self::tieneAgregado($h)) {
                return true;
            }
        }
        return false;
    }

    /** MIN/MAX son de agregación con 1 argumento y escalares con 2 o más. */
    public static function esAgregado(array $n): bool
    {
        if (!\in_array($n['nombre'], self::AGREGADOS, true)) {
            return false;
        }
        // GROUP_CONCAT admite un segundo argumento con el separador
        $maximo = $n['nombre'] === 'GROUP_CONCAT' ? 2 : 1;
        return $n['star'] || (\count($n['args']) >= 1 && \count($n['args']) <= $maximo);
    }

    /** @return array<int,array> subexpresiones directas (sin entrar en subconsultas) */
    private static function hijos(array $n): array
    {
        switch ($n['k']) {
            case 'bin':     return [$n['i'], $n['d']];
            case 'un':
            case 'null':    return [$n['e']];
            case 'fn':      return array_merge($n['args'], array_column($n['orden'] ?? [], 'expr'), isset($n['filtro']) ? [$n['filtro']] : []);
            case 'ventana': return array_merge($n['args'], $n['filtro'] === null ? [] : [$n['filtro']], $n['def']['particion'], array_column($n['def']['orden'], 'expr'));
            case 'between': return [$n['e'], $n['min'], $n['max']];
            case 'like':    return $n['escape'] === null ? [$n['e'], $n['patron']] : [$n['e'], $n['patron'], $n['escape']];
            case 'regexp':  return [$n['e'], $n['patron']];
            case 'cast':    return [$n['e']];
            case 'in':      return array_merge([$n['e']], $n['lista'] ?? []);
            case 'case':
                $h = $n['base'] === null ? [] : [$n['base']];
                foreach ($n['when'] as [$c, $r]) { $h[] = $c; $h[] = $r; }
                if ($n['else'] !== null) { $h[] = $n['else']; }
                return $h;
        }
        return [];
    }

    // ==================================================================
    // Evaluación
    // ==================================================================

    public static function evaluar(array $n, array $ctx)
    {
        switch ($n['k']) {

            case 'lit':
                return $n['v'];

            case 'col':
                if (isset($n['externa'])) {
                    return $ctx['filaExterna'][$n['claveExterna']] ?? null;
                }
                if (isset($n['clave'])) {
                    return $ctx['fila'][$n['clave']] ?? null;
                }
                // Un alias de la salida (ORDER BY), de la fila ya proyectada (2.9:
                // iba junto a la de origen, y una columna con su nombre lo tapaba)
                return $ctx['salida'][$n['alias']] ?? null;

            case 'bin':
                return self::binaria($n, $ctx);

            case 'un':
                if ($n['op'] === 'NOT') {
                    $v = Valor::verdadero(self::evaluar($n['e'], $ctx));
                    return $v === null ? null : ($v ? 0 : 1);
                }
                $v = self::evaluar($n['e'], $ctx);
                if ($n['op'] === '~') {
                    return $v === null ? null : ~Valor::entero(Valor::aNumero($v));
                }
                return $v === null ? null : -Valor::aNumero($v);

            case 'fn':
                return self::funcion($n, $ctx);

            case 'case':
                $base = $n['base'] === null ? null : self::evaluar($n['base'], $ctx);
                foreach ($n['when'] as $w) {
                    [$cond, $res] = $w;
                    // Con el WHEN de una columna de texto, la base como texto (2.10, ver resolver())
                    $cumple = $n['base'] === null
                        ? Valor::verdadero(self::evaluar($cond, $ctx)) === true
                        : Valor::comparar(isset($w[2]) ? Valor::conAfinidad($base, 'T') : $base, self::evaluar($cond, $ctx)) === 0;
                    if ($cumple) {
                        return self::evaluar($res, $ctx);
                    }
                }
                return $n['else'] === null ? null : self::evaluar($n['else'], $ctx);

            case 'null':
                $v = self::evaluar($n['e'], $ctx);
                return ($v === null) !== $n['not'] ? 1 : 0;

            case 'between':
                // x >= a AND x <= b, con la lógica de tres valores (2.9: con un
                // extremo NULL era NULL aunque el otro ya lo dejara fuera, y
                // 15 BETWEEN NULL AND 10 es 0, como en SQLite)
                $v = self::evaluar($n['e'], $ctx);
                if ($v === null) { return null; }
                $a = Valor::comparar(empty($n['textoMin']) ? $v : Valor::conAfinidad($v, 'T'), self::evaluar($n['min'], $ctx));
                if ($a !== null && $a < 0) { return $n['not'] ? 1 : 0; }
                $b = Valor::comparar(empty($n['textoMax']) ? $v : Valor::conAfinidad($v, 'T'), self::evaluar($n['max'], $ctx));
                if ($b !== null && $b > 0) { return $n['not'] ? 1 : 0; }
                return $a === null || $b === null ? null : ($n['not'] ? 0 : 1);

            case 'like':
                return EvaluatorPatrones::like($n, $ctx);

            case 'regexp':
                return EvaluatorPatrones::regexp($n, $ctx);

            case 'cast':
                return TypesCast::convertirCast(self::evaluar($n['e'], $ctx), $n['tipo']);

            case 'in':
                return EvaluatorIn::in($n, $ctx);

            case 'raise':
                throw JsonSqlDbError::constraint($n['mensaje'] ?? 'Operación abortada por un trigger');

            case 'exists':
                // No hace falta el valor: basta con saber si hay alguna fila (y
                // Select puede saberlo sin ejecutarla, ver SelectJoin::semiJoin())
                return ($ctx['sub'])($n['select'], $n['sid'], $ctx['fila'] ?? [], true) === [] ? 0 : 1;

            case 'sub':
                $filas = ($ctx['sub'])($n['select'], $n['sid'], $ctx['fila'] ?? []);
                if ($filas === []) {
                    return null;
                }
                $primera = reset($filas);
                return reset($primera);

            case 'ventana':                       // ya calculada (ver SelectAvanzada::ventanas())
                if (!isset($n['i'], $ctx['ventanas'])) {
                    throw JsonSqlDbError::syntax("{$n['nombre']}() OVER solo puede ir en las columnas de un SELECT o en su ORDER BY");
                }
                return $ctx['ventanas'][$n['i']];
        }

        throw JsonSqlDbError::syntax("Expresión no evaluable: {$n['k']}");
    }

    private static function binaria(array $n, array $ctx)
    {
        $op = $n['op'];

        // Lógica de tres valores: NULL = desconocido
        if ($op === 'AND' || $op === 'OR') {
            $i = Valor::verdadero(self::evaluar($n['i'], $ctx));
            if ($op === 'AND' && $i === false) { return 0; }
            if ($op === 'OR'  && $i === true)  { return 1; }
            $d = Valor::verdadero(self::evaluar($n['d'], $ctx));
            if ($op === 'AND') {
                if ($d === false) { return 0; }
                return ($i === null || $d === null) ? null : 1;
            }
            if ($d === true) { return 1; }
            return ($i === null || $d === null) ? null : 0;
        }

        $i = self::evaluar($n['i'], $ctx);
        $d = self::evaluar($n['d'], $ctx);

        if ($op === '||') {
            return ($i === null || $d === null) ? null : Valor::aTexto($i) . Valor::aTexto($d);
        }

        if ($op === '=' || $op === '<>' || $op === '<' || $op === '<=' || $op === '>' || $op === '>=') {
            $c = Valor::comparar($i, $d);
            if ($c === null) { return null; }
            switch ($op) {
                case '=':  return $c === 0 ? 1 : 0;
                case '<>': return $c !== 0 ? 1 : 0;
                case '<':  return $c <  0 ? 1 : 0;
                case '<=': return $c <= 0 ? 1 : 0;
                case '>':  return $c >  0 ? 1 : 0;
                default:   return $c >= 0 ? 1 : 0;
            }
        }

        // Detrás de las comparaciones, que son las de casi cada fila (2.9):
        // a <=> b (MySQL), a IS b (SQLite): igual, con NULL como un valor más
        if ($op === '<=>') {
            if ($i === null || $d === null) {
                return $i === null && $d === null ? 1 : 0;
            }
            return Valor::comparar($i, $d) === 0 ? 1 : 0;
        }
        if ($op === 'XOR') {
            $a = Valor::verdadero($i);
            $b = Valor::verdadero($d);
            return $a === null || $b === null ? null : ($a !== $b ? 1 : 0);
        }


        if ($i === null || $d === null) {
            return null;
        }
        $x = Valor::aNumero($i);
        $y = Valor::aNumero($d);
        // NaN (INF - INF, INF * 0, INF / INF) es NULL, como en SQLite (2.10: un
        // NaN pasaba un WHERE x > 2 y se ordenaba en cualquier sitio)
        switch ($op) {
            case '+': return ($r = $x + $y) === $r ? $r : null;
            case '-': return ($r = $x - $y) === $r ? $r : null;
            case '*': return ($r = $x * $y) === $r ? $r : null;
            case '/': return $y == 0 ? null : (($r = $x / $y) === $r ? $r : null);      // división por cero -> NULL, como SQLite
            case '%':
                // El módulo trabaja con enteros, como en SQLite. Comprobar el
                // cero ANTES de convertir dejaba pasar 0.4, que al convertirse
                // se vuelve 0 y provocaba un DivisionByZeroError de PHP.
                $di = Valor::entero($y);
                return $di === 0 ? null : Valor::entero($x) % $di;
            case 'DIV':
                // División entera de MySQL: sin decimales, hacia el cero
                if ($y == 0) { return null; }
                $q = $x / $y;
                return Valor::entero($q < 0 ? ceil($q) : floor($q));
            case '&':  return Valor::entero($x) & Valor::entero($y);
            case '|':  return Valor::entero($x) | Valor::entero($y);
            case '<<': return Valor::entero($y) >= 64 || Valor::entero($y) <= -64 ? 0 : (Valor::entero($y) >= 0 ? Valor::entero($x) << Valor::entero($y) : Valor::entero($x) >> -Valor::entero($y));
            case '>>': return Valor::entero($y) >= 64 || Valor::entero($y) <= -64 ? (Valor::entero($x) < 0 ? -1 : 0) : (Valor::entero($y) >= 0 ? Valor::entero($x) >> Valor::entero($y) : Valor::entero($x) << -Valor::entero($y));
        }

        throw JsonSqlDbError::syntax("Operador no soportado: $op");
    }

    private static function funcion(array $n, array $ctx)
    {
        if (isset($n['agid']) && isset($ctx['agregados'])) {
            return $ctx['agregados'][$n['agid']];   // ya acumulado (ver SelectAgrupada::marcarAgregados())
        }
        if (self::esAgregado($n)) {
            throw JsonSqlDbError::syntax("{$n['nombre']}() solo puede usarse en el SELECT, HAVING u ORDER BY");
        }
        if ($n['star']) {
            throw JsonSqlDbError::syntax("{$n['nombre']}(*) no es válido");
        }

        $args = [];
        foreach ($n['args'] as $a) {
            $args[] = self::evaluar($a, $ctx);
        }
        return isset($n['colacion']) ? Functions::conColacion($n['nombre'], $args, $n['colacion'])
                                     : Functions::escalar($n['nombre'], $args);
    }
}
