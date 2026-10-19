<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las expresiones compiladas en clausuras, para cuando una consulta las
 * evalúa sobre muchas filas (ver compilar()). Métodos de Evaluator. Van
 * aparte para que una escritura no tenga que compilarlos (2.9): sin OPcache,
 * PHP compila en cada petición lo que carga. Hereda de Evaluator solo para
 * llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class EvaluatorCompilado extends Evaluator
{
    private function __construct()
    {
    }

    /**
     * Compila una expresión en una función PHP que la evalúa sobre una fila,
     * con el mismo resultado que evaluar(). Recorrer el árbol con evaluar()
     * cuesta un switch y varias llamadas por nodo y fila; compilada, cada
     * nodo es una llamada a una clausura ya especializada. En un WHERE sobre
     * cien mil filas es varias veces más rápido.
     *
     * Devuelve null si algún nodo no se compila (funciones, subconsultas,
     * columnas de la consulta exterior...): entonces se evalúa como siempre.
     *
     * @return (\Closure(array): mixed)|null
     */
    public static function compilar(array $n, ?callable $conjunto = null): ?\Closure
    {
        switch ($n['k']) {
            case 'lit':
                $v = $n['v'];
                return static fn() => $v;

            case 'col':
                $c = Evaluator::claveDirecta($n);
                return $c === null ? null : static fn(array $f) => $f[$c] ?? null;

            case 'bin':
                return self::compilarBinaria($n, $conjunto);

            case 'in':
                // IN con una lista de literales, ya preparada como conjunto en
                // resolver(), o (2.9) con una subconsulta, con el conjunto que
                // da $conjunto (el de Select, que guarda el de una subconsulta
                // que no depende de la fila): lo mismo que EvaluatorIn::in(), sin pasar
                // por evaluar() en cada fila
                $e = self::compilar($n['e'], $conjunto);
                if ($e === null) {
                    return null;
                }
                $no = (bool)$n['not'];
                if (isset($n['conjunto'])) {
                    $preparado = $n['conjunto'];
                    return static fn(array $f) => EvaluatorConjunto::enConjunto($e($f), $preparado, $no);
                }
                if (($n['select'] ?? null) === null || $conjunto === null) {
                    return null;
                }
                $sel = $n['select'];
                $sid = $n['sid'];
                $col = $n['colacionIn'] ?? null;
                $afi = $n['afinidadIn'] ?? null;
                return static function (array $f) use ($e, $conjunto, $sel, $sid, $col, $afi, $no) {
                    $p = $conjunto($sel, $sid, $f, $col, $afi);
                    return EvaluatorConjunto::enConjunto(isset($p[3]) ? Functions::plegar($e($f), $p[3]) : $e($f), $p, $no);
                };

            case 'un':
                $e = self::compilar($n['e'], $conjunto);
                if ($e === null) {
                    return null;
                }
                if ($n['op'] === 'NOT') {
                    return static function (array $f) use ($e) {
                        $v = Valor::verdadero($e($f));
                        return $v === null ? null : ($v ? 0 : 1);
                    };
                }
                // ~x, el complemento de bits, como en evaluar() (2.9: compilado era -x)
                if ($n['op'] === '~') {
                    return static function (array $f) use ($e) {
                        $v = $e($f);
                        return $v === null ? null : ~(\is_int($v) ? $v : Valor::entero(Valor::aNumero($v)));
                    };
                }
                return static function (array $f) use ($e) {
                    $v = $e($f);
                    return $v === null ? null : -Valor::aNumero($v);
                };

            case 'null':
                $e = self::compilar($n['e'], $conjunto);
                if ($e === null) {
                    return null;
                }
                $not = $n['not'];
                return static fn(array $f) => ($e($f) === null) !== $not ? 1 : 0;

            case 'between':
                $e   = self::compilar($n['e'], $conjunto);
                $min = self::compilar($n['min'], $conjunto);
                $max = self::compilar($n['max'], $conjunto);
                if ($e === null || $min === null || $max === null) {
                    return null;
                }
                $not = $n['not'];
                $tMin = !empty($n['textoMin']);           // x como texto para ese extremo (2.10, ver Evaluator::resolver())
                $tMax = !empty($n['textoMax']);
                return static function (array $f) use ($e, $min, $max, $not, $tMin, $tMax) {
                    $v = $e($f);
                    if ($v === null) { return null; }
                    $a = Valor::comparar($tMin ? Valor::conAfinidad($v, 'T') : $v, $min($f));
                    if ($a !== null && $a < 0) { return $not ? 1 : 0; }
                    $b = Valor::comparar($tMax ? Valor::conAfinidad($v, 'T') : $v, $max($f));
                    if ($b !== null && $b > 0) { return $not ? 1 : 0; }
                    return $a === null || $b === null ? null : ($not ? 0 : 1);
                };

            case 'cast':
                // CAST(x AS TEXT), que es como pasa a texto lo que se compara con
                // una columna de texto (2.10, ver Evaluator::afinar()); los demás, como siempre
                $e = self::compilar($n['e'], $conjunto);
                if ($e === null || $n['tipo'] !== 'TEXT') {
                    return null;
                }
                return static function (array $f) use ($e) {
                    $v = $e($f);
                    return $v === null || \is_string($v) ? $v : Valor::aTexto($v);
                };

            case 'like':
                // Solo con patrón (y escape) literales: la expresión regular se
                // calcula una vez, no una por fila
                $e = self::compilar($n['e'], $conjunto);
                if ($e === null || $n['patron']['k'] !== 'lit' || $n['patron']['v'] === null
                    || ($n['escape'] !== null && $n['escape']['k'] !== 'lit')) {
                    return null;
                }
                if ($n['escape'] !== null && $n['escape']['v'] === null) {
                    return static fn(array $f) => null;   // ESCAPE NULL: NULL, como SQLite (2.10)
                }
                $escape = $n['escape'] === null ? null : Valor::aTexto($n['escape']['v']);
                $regex  = empty($n['glob']) ? EvaluatorPatrones::patronALike(Valor::aTexto($n['patron']['v']), $escape)
                    : EvaluatorPatrones::patronAGlob(Valor::aTexto($n['patron']['v']));
                $not    = $n['not'];
                return static function (array $f) use ($e, $regex, $not) {
                    $v = $e($f);
                    if ($v === null) { return null; }
                    $t = \is_string($v) ? $v : Valor::aTexto($v);
                    $r = preg_match($regex, $t);
                    return (($r === false ? EvaluatorPatrones::casaSinRetroceso($regex, $t) : $r === 1) !== $not) ? 1 : 0;
                };
        }
        return null;
    }

    private static function compilarBinaria(array $n, ?callable $conjunto): ?\Closure
    {
        $directa = self::compararConLiteral($n);
        if ($directa !== null) {
            return $directa;
        }
        $i = self::compilar($n['i'], $conjunto);
        $d = self::compilar($n['d'], $conjunto);
        if ($i === null || $d === null) {
            return null;
        }
        switch ($n['op']) {
            case 'AND':
                // Una comparación devuelve 0, 1 o NULL: esos se miran sin
                // llamar a Valor::verdadero()
                return static function (array $f) use ($i, $d) {
                    $a = $i($f);
                    $a = $a === 0 ? false : ($a === 1 ? true : Valor::verdadero($a));
                    if ($a === false) { return 0; }
                    $b = $d($f);
                    $b = $b === 0 ? false : ($b === 1 ? true : Valor::verdadero($b));
                    if ($b === false) { return 0; }
                    return ($a === null || $b === null) ? null : 1;
                };
            case 'OR':
                return static function (array $f) use ($i, $d) {
                    $a = Valor::verdadero($i($f));
                    if ($a === true) { return 1; }
                    $b = Valor::verdadero($d($f));
                    if ($b === true) { return 1; }
                    return ($a === null || $b === null) ? null : 0;
                };
            case '=':
                return static function (array $f) use ($i, $d) {
                    $c = Valor::comparar($i($f), $d($f));
                    return $c === null ? null : ($c === 0 ? 1 : 0);
                };
            case '<>':
                return static function (array $f) use ($i, $d) {
                    $c = Valor::comparar($i($f), $d($f));
                    return $c === null ? null : ($c !== 0 ? 1 : 0);
                };
            case '<':
                return static function (array $f) use ($i, $d) {
                    $c = Valor::comparar($i($f), $d($f));
                    return $c === null ? null : ($c < 0 ? 1 : 0);
                };
            case '<=':
                return static function (array $f) use ($i, $d) {
                    $c = Valor::comparar($i($f), $d($f));
                    return $c === null ? null : ($c <= 0 ? 1 : 0);
                };
            case '>':
                return static function (array $f) use ($i, $d) {
                    $c = Valor::comparar($i($f), $d($f));
                    return $c === null ? null : ($c > 0 ? 1 : 0);
                };
            case '>=':
                return static function (array $f) use ($i, $d) {
                    $c = Valor::comparar($i($f), $d($f));
                    return $c === null ? null : ($c >= 0 ? 1 : 0);
                };
            case '||':
                return static function (array $f) use ($i, $d) {
                    $a = $i($f);
                    $b = $d($f);
                    return ($a === null || $b === null) ? null : Valor::aTexto($a) . Valor::aTexto($b);
                };
            case '+':
            case '-':
            case '*':
            case '/':
            case '%':
                $op = $n['op'];
                return static function (array $f) use ($i, $d, $op) {
                    $a = $i($f);
                    $b = $d($f);
                    if ($a === null || $b === null) { return null; }
                    $x = Valor::aNumero($a);
                    $y = Valor::aNumero($b);
                    switch ($op) {                  // NaN, NULL: ver Evaluator::binaria()
                        case '+': return ($r = $x + $y) === $r ? $r : null;
                        case '-': return ($r = $x - $y) === $r ? $r : null;
                        case '*': return ($r = $x * $y) === $r ? $r : null;
                        case '/': return $y == 0 ? null : (($r = $x / $y) === $r ? $r : null);
                    }
                    $di = Valor::entero($y);
                    return $di === 0 ? null : Valor::entero($x) % $di;
                };
        }
        return null;
    }

    /**
     * columna OP literal (o literal OP columna), que es casi todo WHERE: la
     * columna se lee de la fila y el literal va dentro de la función, sin una
     * llamada para cada lado, y con dos números o dos textos se compara
     * directamente (2.10: dos textos, siempre byte a byte). Es el mismo
     * resultado que Valor::comparar(), que es quien decide en cualquier otro
     * caso. Null si no es esa forma.
     */
    private static function compararConLiteral(array $n): ?\Closure
    {
        $invertido = ['=' => '=', '<>' => '<>', '<' => '>', '<=' => '>=', '>' => '<', '>=' => '<='];
        $op = $n['op'];
        if (!isset($invertido[$op])) {
            return null;
        }
        [$col, $lit] = [$n['i'], $n['d']];
        if ($col['k'] === 'lit' && $lit['k'] === 'col') {
            [$col, $lit, $op] = [$lit, $col, $invertido[$op]];
        }
        $c = Evaluator::claveDirecta($col);
        if ($c === null || $lit['k'] !== 'lit' || $lit['v'] === null) {
            return null;
        }
        $v = $lit['v'];
        // Pasado 2^53, por Valor::comparar(), exacto entre un entero y un decimal (2.10)
        $numero = (\is_int($v) || \is_float($v)) && abs($v) < 9007199254740992;
        $texto  = \is_string($v);
        return static function (array $f) use ($c, $v, $op, $numero, $texto) {
            $x = $f[$c] ?? null;
            if ($x === null) {
                return null;
            }
            if ($numero && (\is_int($x) || \is_float($x))) {
                $r = $x <=> $v;
            } elseif ($texto && \is_string($x)) {
                $r = strcmp($x, $v) <=> 0;
            } else {
                $r = Valor::comparar($x, $v);
                if ($r === null) {
                    return null;
                }
            }
            switch ($op) {
                case '=':  return $r === 0 ? 1 : 0;
                case '<>': return $r !== 0 ? 1 : 0;
                case '<':  return $r < 0 ? 1 : 0;
                case '<=': return $r <= 0 ? 1 : 0;
                case '>':  return $r > 0 ? 1 : 0;
                default:   return $r >= 0 ? 1 : 0;
            }
        };
    }
}
