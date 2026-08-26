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
 *   ['fila' => array, 'grupo' => ?array de filas, 'sub' => callable(AST): filas]
 */
final class Evaluator
{
    /** Identificador único por subconsulta, para poder cachear su resultado */
    private static int $sid = 0;

    /**
     * Fija la clave real de cada referencia a columna.
     *
     * @param array $mapa  'alias.col' => 'alias.col' y 'col' => 'alias.col' | false si es ambigua
     * @param array $alias nombres de salida del SELECT admitidos (ORDER BY / GROUP BY por alias)
     */
    /**
     * Se pone a true cuando una columna se resuelve contra la consulta exterior.
     * Es lo que permite saber si una subconsulta está correlacionada y, por
     * tanto, si su resultado se puede cachear o hay que recalcularlo por fila.
     */
    public static bool $correlacionada = false;

    public static function resolver(array $n, array $mapa, array $alias = [], array $externo = []): array
    {
        switch ($n['k']) {
            case 'col':
                $buscado = $n['tabla'] === null
                    ? strtolower($n['nombre'])
                    : strtolower($n['tabla'] . '.' . $n['nombre']);

                if (array_key_exists($buscado, $mapa)) {
                    if ($mapa[$buscado] === false) {
                        throw JsonSqlDbError::schema("La columna '{$n['nombre']}' es ambigua: indica la tabla");
                    }
                    $n['clave'] = $mapa[$buscado];
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
                    self::$correlacionada = true;
                    return $n;
                }
                $completo = $n['tabla'] === null ? $n['nombre'] : $n['tabla'] . '.' . $n['nombre'];
                throw JsonSqlDbError::schema("Columna desconocida: '$completo'");

            case 'bin':
                $n['i'] = self::resolver($n['i'], $mapa, $alias, $externo);
                $n['d'] = self::resolver($n['d'], $mapa, $alias, $externo);
                return $n;

            case 'un':
                $n['e'] = self::resolver($n['e'], $mapa, $alias, $externo);
                return $n;

            case 'fn':
                foreach ($n['args'] as $i => $a) {
                    $n['args'][$i] = self::resolver($a, $mapa, $alias, $externo);
                }
                return $n;

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
                return $n;

            case 'in':
                $n['e'] = self::resolver($n['e'], $mapa, $alias, $externo);
                if ($n['lista'] !== null) {
                    foreach ($n['lista'] as $i => $a) {
                        $n['lista'][$i] = self::resolver($a, $mapa, $alias, $externo);
                    }
                }
                if ($n['select'] !== null && !isset($n['sid'])) {
                    $n['sid'] = ++self::$sid;
                }
                return $n;                       // la subconsulta se resuelve al ejecutarse

            case 'between':
                $n['e']   = self::resolver($n['e'], $mapa, $alias, $externo);
                $n['min'] = self::resolver($n['min'], $mapa, $alias, $externo);
                $n['max'] = self::resolver($n['max'], $mapa, $alias, $externo);
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
    private static function esAgregado(array $n): bool
    {
        if (!in_array($n['nombre'], Functions::AGREGADOS, true)) {
            return false;
        }
        // GROUP_CONCAT admite un segundo argumento con el separador
        $maximo = $n['nombre'] === 'GROUP_CONCAT' ? 2 : 1;
        return $n['star'] || (count($n['args']) >= 1 && count($n['args']) <= $maximo);
    }

    /** @return array<int,array> subexpresiones directas (sin entrar en subconsultas) */
    private static function hijos(array $n): array
    {
        switch ($n['k']) {
            case 'bin':     return [$n['i'], $n['d']];
            case 'un':
            case 'null':    return [$n['e']];
            case 'fn':      return $n['args'];
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
                return $ctx['fila'][$n['alias']] ?? null;

            case 'bin':
                return self::binaria($n, $ctx);

            case 'un':
                if ($n['op'] === 'NOT') {
                    $v = Valor::verdadero(self::evaluar($n['e'], $ctx));
                    return $v === null ? null : ($v ? 0 : 1);
                }
                $v = self::evaluar($n['e'], $ctx);
                return $v === null ? null : -Valor::aNumero($v);

            case 'fn':
                return self::funcion($n, $ctx);

            case 'case':
                $base = $n['base'] === null ? null : self::evaluar($n['base'], $ctx);
                foreach ($n['when'] as [$cond, $res]) {
                    $cumple = $n['base'] === null
                        ? Valor::verdadero(self::evaluar($cond, $ctx)) === true
                        : Valor::comparar($base, self::evaluar($cond, $ctx)) === 0;
                    if ($cumple) {
                        return self::evaluar($res, $ctx);
                    }
                }
                return $n['else'] === null ? null : self::evaluar($n['else'], $ctx);

            case 'null':
                $v = self::evaluar($n['e'], $ctx);
                return ($v === null) !== $n['not'] ? 1 : 0;

            case 'between':
                $v = self::evaluar($n['e'], $ctx);
                if ($v === null) { return null; }
                $a = Valor::comparar($v, self::evaluar($n['min'], $ctx));
                $b = Valor::comparar($v, self::evaluar($n['max'], $ctx));
                if ($a === null || $b === null) { return null; }
                $dentro = ($a >= 0 && $b <= 0);
                return ($dentro !== $n['not']) ? 1 : 0;

            case 'like':
                return self::like($n, $ctx);

            case 'regexp':
                return self::regexp($n, $ctx);

            case 'cast':
                return Types::convertirCast(self::evaluar($n['e'], $ctx), $n['tipo']);

            case 'in':
                return self::in($n, $ctx);

            case 'raise':
                throw JsonSqlDbError::constraint($n['mensaje'] ?? 'Operación abortada por un trigger');

            case 'exists':
                // No hace falta el valor: basta con saber si hay alguna fila
                return ($ctx['sub'])($n['select'], $n['sid'], $ctx['fila'] ?? []) === [] ? 0 : 1;

            case 'sub':
                $filas = ($ctx['sub'])($n['select'], $n['sid'], $ctx['fila'] ?? []);
                if ($filas === []) {
                    return null;
                }
                $primera = reset($filas);
                return reset($primera);
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

        if ($i === null || $d === null) {
            return null;
        }
        $x = Valor::aNumero($i);
        $y = Valor::aNumero($d);
        switch ($op) {
            case '+': return $x + $y;
            case '-': return $x - $y;
            case '*': return $x * $y;
            case '/': return $y == 0 ? null : $x / $y;      // división por cero -> NULL, como SQLite
            case '%':
                // El módulo trabaja con enteros, como en SQLite. Comprobar el
                // cero ANTES de convertir dejaba pasar 0.4, que al convertirse
                // se vuelve 0 y provocaba un DivisionByZeroError de PHP.
                $di = (int)$y;
                return $di === 0 ? null : (int)$x % $di;
        }

        throw JsonSqlDbError::syntax("Operador no soportado: $op");
    }

    private static function funcion(array $n, array $ctx)
    {
        if (isset($ctx['grupo']) && self::esAgregado($n)) {
            if ($n['star']) {
                return Functions::agregado($n['nombre'], null, count($ctx['grupo']), false);
            }
            $valores = [];
            $arg     = $n['args'][0];
            foreach ($ctx['grupo'] as $fila) {
                $valores[] = self::evaluar($arg, ['fila' => $fila] + $ctx);
            }

            // El separador de GROUP_CONCAT se evalúa una vez, no por fila
            $separador = ',';
            if (isset($n['args'][1])) {
                $primera   = $ctx['grupo'][array_key_first($ctx['grupo'])] ?? [];
                $sep       = self::evaluar($n['args'][1], ['fila' => $primera] + $ctx);
                $separador = $sep === null ? '' : Valor::aTexto($sep);
            }

            return Functions::agregado($n['nombre'], $valores, count($ctx['grupo']),
                                       $n['distinct'], $separador);
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
        return Functions::escalar($n['nombre'], $args);
    }

    /**
     * x REGEXP patron  (RLIKE es un alias, como en MySQL)
     *
     * El patrón lo escribe quien consulta, así que se valida antes de usarlo y
     * se controla el desbordamiento del motor de expresiones regulares: una
     * expresión mal construida sobre un texto largo puede tardar una eternidad,
     * y es preferible un error claro a un proceso colgado.
     */
    private static function regexp(array $n, array $ctx)
    {
        $v = self::evaluar($n['e'], $ctx);
        $p = self::evaluar($n['patron'], $ctx);
        if ($v === null || $p === null) {
            return null;
        }

        $patron = Valor::aTexto($p);
        $regex  = '/' . str_replace('/', '\\/', $patron) . '/u';

        $anterior = set_error_handler(static fn(): bool => true);   // silencia el aviso de PCRE
        $r = preg_match($regex, Valor::aTexto($v));
        set_error_handler($anterior);

        if ($r === false) {
            $error = preg_last_error();
            if ($error === PREG_BACKTRACK_LIMIT_ERROR || $error === PREG_RECURSION_LIMIT_ERROR) {
                throw JsonSqlDbError::syntax(
                    "La expresión regular '$patron' es demasiado costosa para este texto: "
                    . 'simplifícala o acota antes las filas con un WHERE'
                );
            }
            throw JsonSqlDbError::syntax("Expresión regular no válida: '$patron'");
        }

        return (($r === 1) !== $n['not']) ? 1 : 0;
    }

    private static function like(array $n, array $ctx)
    {
        $v = self::evaluar($n['e'], $ctx);
        $p = self::evaluar($n['patron'], $ctx);
        if ($v === null || $p === null) {
            return null;
        }
        $escape = $n['escape'] === null ? null : Valor::aTexto(self::evaluar($n['escape'], $ctx));
        $regex  = self::patronALike(Valor::aTexto($p), $escape);
        $coincide = (bool)preg_match($regex, Valor::aTexto($v));
        return ($coincide !== $n['not']) ? 1 : 0;
    }

    /** Convierte el patrón LIKE en expresión regular. % = varios, _ = uno. */
    private static function patronALike(string $patron, ?string $escape): string
    {
        static $memo = [];
        $clave = $patron . "\0" . ($escape ?? '');
        if (isset($memo[$clave])) {
            return $memo[$clave];
        }

        $esc = ($escape !== null && $escape !== '') ? $escape[0] : null;
        $out = '';
        $n   = strlen($patron);
        for ($i = 0; $i < $n; $i++) {
            $c = $patron[$i];
            if ($esc !== null && $c === $esc && $i + 1 < $n) {
                $out .= preg_quote($patron[++$i], '/');
                continue;
            }
            if ($c === '%')      { $out .= '.*'; }
            elseif ($c === '_')  { $out .= '.'; }
            else                 { $out .= preg_quote($c, '/'); }
        }

        $regex = '/^' . $out . '$/isu';
        if (@preg_match($regex, '') === false) {
            $regex = '/^' . $out . '$/is';       // texto no UTF-8 válido
        }
        return $memo[$clave] = $regex;
    }

    private static function in(array $n, array $ctx)
    {
        $v = self::evaluar($n['e'], $ctx);

        if ($n['select'] !== null) {
            $valores = [];
            foreach (($ctx['sub'])($n['select'], $n['sid'], $ctx['fila'] ?? []) as $fila) {
                $valores[] = reset($fila);
            }
        } else {
            $valores = [];
            foreach ($n['lista'] as $e) {
                $valores[] = self::evaluar($e, $ctx);
            }
        }

        if ($valores === []) {
            return $n['not'] ? 1 : 0;            // NOT IN () siempre cierto
        }
        if ($v === null) {
            return null;
        }

        $hayNulo = false;
        foreach ($valores as $x) {
            if ($x === null) { $hayNulo = true; continue; }
            if (Valor::comparar($v, $x) === 0) {
                return $n['not'] ? 0 : 1;
            }
        }
        return $hayNulo ? null : ($n['not'] ? 1 : 0);
    }
}
