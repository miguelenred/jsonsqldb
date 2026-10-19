<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Semántica de valores SQL. Un valor es null, int, float o string.
 *
 * Comparación (2.10, como en SQLite):
 *   - dos números, como números; dos textos, como textos, byte a byte
 *     ('7' y '007' son distintos, y '10' va antes que '9')
 *   - la columna decide antes: con una de texto, un número del otro lado
 *     (un literal, un parámetro, una expresión) se compara como texto; con
 *     una numérica, un texto que es un número, como número (lo aplica
 *     Evaluator::resolver() con afinidadDe(), y conAfinidad() en los índices)
 *   - un número y un texto sin columna que decida: si el texto es un número,
 *     como números; si no, el número va antes. SQLite los daría por distintos
 *     ('7' = 7 es 0), y aquí siguen siendo iguales, como antes de la 2.10:
 *     un parámetro llega como texto desde un formulario
 *   - las fechas se guardan como 'yyyy-MM-dd...', así que el orden de texto
 *     coincide con el cronológico y no hace falta convertirlas
 *   - cualquier comparación con NULL da NULL (desconocido)
 *
 * Para ordenar (compararOrden(), compararClase()), sin conversiones: los
 * números antes que los textos, como en SQLite.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Valor
{
    public static function esNumerico($v): bool
    {
        return \is_int($v) || \is_float($v) || (\is_string($v) && is_numeric(trim($v)));
    }

    /** @return int|float */
    public static function aNumero($v)
    {
        if (\is_int($v) || \is_float($v)) {
            return $v;
        }
        if (\is_string($v)) {
            $t = trim($v);
            if (is_numeric($t)) {
                return $t + 0;
            }
            // Prefijo numérico, como hace SQLite: '12abc' -> 12
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $t, $m)) {
                // Conversión explícita: '12' da int y '1.5' o '1e3' dan float,
                // igual que haría "+ 0", pero sin depender de la coerción
                return (strpbrk($m[0], '.eE') === false) ? (int)$m[0] : (float)$m[0];
            }
        }
        return \is_bool($v) ? (int)$v : 0;        // un booleano es 1 o 0 (2.10: era 0)
    }

    /**
     * Un número como entero, truncando hacia cero. Lo que no cabe en 64 bits
     * se queda en el máximo o el mínimo (como CAST en SQLite) y NaN es 0. Sin
     * comprobarlo, (int) daba un valor que dependía de la plataforma, y PHP 8.5
     * además avisa de que no se puede representar.
     *
     * @param int|float $n
     */
    public static function entero($n): int
    {
        if (\is_int($n)) {
            return $n;
        }
        if (is_nan($n)) {
            return 0;
        }
        if ($n >= 9.2233720368547758E18) {
            return PHP_INT_MAX;
        }
        if ($n <= -9.2233720368547758E18) {
            return PHP_INT_MIN;
        }
        return (int)$n;
    }

    public static function aTexto($v): string
    {
        if ($v === null)    { return ''; }
        if (\is_bool($v))    { return $v ? '1' : '0'; }
        if (\is_float($v))   { return self::floatATexto($v); }
        return (string)$v;
    }

    /**
     * Un decimal como texto, como SQLite (2.10): 15 cifras, con punto
     * (10.0 -> '10.0') y el exponente con signo y dos cifras ('1.0e+100',
     * '1.0e-05'). Antes, las 14 de PHP: 123456789.123456789 quedaba
     * '123456789.12346' en una columna TEXT o sin tipo (SQLite,
     * '123456789.123457'). De 200.000 decimales al azar de 17 cifras, 278
     * acaban en otra cifra que en SQLite: 269 son empates en la cifra 16, que
     * aquí van a la par y allí hacia fuera, y 9 los redondea mal SQLite. No
     * depende del locale ni de 'precision' en php.ini.
     */
    private static function floatATexto(float $v): string
    {
        // Lo que escribe PHP, si con ello vuelve a ser el mismo número, no
        // lleva exponente y tiene 15 caracteres o menos (así, 15 cifras o
        // menos, con cualquier 'precision' de php.ini): son las cifras de 15,
        // con la mitad de trabajo (un importe: 2.568 instrucciones, contra
        // 5.009 con sprintf y 2.060 en la 2.9). Si no, sprintf (un decimal de
        // 17 cifras: 7.166; en la 2.9, 2.272)
        $s = (string)$v;
        if ((float)$s !== $v || \strlen($s) > 15 || str_contains($s, 'E')) {
            if (!is_finite($v)) {
                return is_nan($v) ? '' : ($v > 0 ? 'Inf' : '-Inf');      // como SQLite (2.10: '')
            }
            $s = \sprintf('%.15h', $v);           // h: g sin el locale (con es_ES, %g escribe '0,3')
            if (str_contains($s, 'e')) {
                return self::exponente($s);
            }
        }
        return str_contains($s, '.') ? $s : ($s === '-0' ? '0.0' : $s . '.0');      // -0.0 también, como allí
    }

    /** El exponente como SQLite: '1e+100' -> '1.0e+100', '1.5e-5' -> '1.5e-05' */
    public static function exponente(string $s): string
    {
        [$m, $x] = explode('e', $s);
        return (str_contains($m, '.') ? $m : $m . '.0') . 'e' . $x[0] . str_pad(substr($x, 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Compara dos valores para un WHERE (2.10, ver arriba): dos textos byte a
     * byte, aunque sean números ('7' <> '007'), y un número con un texto que
     * es un número, como números. Antes de la 2.10 dos textos que eran números
     * se comparaban como números.
     *
     * @return int|null -1, 0, 1 — o null si alguno es NULL
     */
    public static function comparar($a, $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }
        // Dos números, el caso corriente: primero, como en la 2.9 (la 2.10 los
        // miraba tras los textos y los booleanos, y se notaba en un JOIN)
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return $a <=> $b ?: (\is_int($a) === \is_int($b) ? 0 : self::mezclados($a, $b));   // ver numeros()
        }
        // Dos textos, lo que compara cualquier WHERE sobre una columna de texto
        if (\is_string($a)) {
            if (\is_string($b)) {
                return strcmp($a, $b) <=> 0;
            }
            return self::esNumerico($a) ? self::numeros(trim($a) + 0, \is_bool($b) ? (int)$b : $b) : 1;
        }
        // Un booleano (de un JSON editado a mano) es 1 o 0
        if (\is_bool($a)) {
            $a = (int)$a;
        }
        if (\is_string($b)) {
            return self::esNumerico($b) ? self::numeros($a, trim($b) + 0) : -1;
        }
        return self::numeros($a, \is_bool($b) ? (int)$b : $b);
    }

    /**
     * Dos números, exactos también un entero y un decimal (2.10, como en
     * SQLite): PHP pasa el entero a decimal, que pasado 2^53 se redondea, y
     * 9007199254740993 salía igual a 9007199254740992.0. Lo que <=> da
     * distinto de 0 ya es exacto (redondear no cambia el orden), así que solo
     * se mira de nuevo un empate entre un entero y un decimal.
     *
     * @param int|float $a
     * @param int|float $b
     */
    public static function numeros($a, $b): int
    {
        return $a <=> $b ?: (\is_int($a) === \is_int($b) ? 0 : self::mezclados($a, $b));
    }

    /**
     * Un entero y un decimal que <=> da por iguales: el decimal es un entero
     * (o 2^63, más que cualquier entero de PHP), y se comparan como enteros.
     *
     * @param int|float $a
     * @param int|float $b
     */
    public static function mezclados($a, $b): int
    {
        if (\is_int($a)) {
            return $b >= 9.2233720368547758E18 ? -1 : $a <=> (int)$b;
        }
        return $a >= 9.2233720368547758E18 ? 1 : (int)$a <=> $b;
    }

    /**
     * Orden total por clase, sin conversiones (2.10): los números antes que
     * los textos, y dos textos byte a byte. Es el de MIN, MAX, GREATEST y
     * LEAST, como en SQLite: MAX(10, '9') es '9'.
     *
     * @return int|null -1, 0, 1 — o null si alguno es NULL
     */
    public static function compararClase($a, $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }
        if (\is_string($a)) {
            return \is_string($b) ? strcmp($a, $b) <=> 0 : 1;
        }
        if (\is_string($b)) {
            return -1;
        }
        // Dos números (un booleano es 1 o 0): ver numeros()
        return (\is_bool($a) ? (int)$a : $a) <=> (\is_bool($b) ? (int)$b : $b)
            ?: (\is_int($a) === \is_int($b) ? 0 : self::numeros(\is_bool($a) ? (int)$a : $a, \is_bool($b) ? (int)$b : $b));
    }

    /**
     * Un valor con la afinidad de una columna (2.10, como en SQLite): con
     * 'N' (numérica), un texto que es un número pasa a número; con 'T'
     * (texto), un número pasa a texto. Lo demás, igual. Es lo que se busca en
     * el índice de esa columna, y lo que de una clave foránea se compara con
     * la columna a la que apunta.
     *
     * @param mixed $v
     * @return mixed
     */
    public static function conAfinidad($v, ?string $afinidad)
    {
        if ($afinidad === 'T') {
            // Un booleano (un parámetro true) es 1, como en SQLite
            return \is_int($v) || \is_float($v) || \is_bool($v) ? self::aTexto(\is_bool($v) ? (int)$v : $v) : $v;
        }
        if ($afinidad === 'N' && \is_string($v) && self::esNumerico($v)) {
            return trim($v) + 0;
        }
        return $v;
    }

    /**
     * Si el WHERE es  columna <op> literal , devuelve las piezas para comparar
     * directamente (con compara()). Si no, null y se usa el evaluador general.
     * Lo usan Select al filtrar y Writer al buscar las filas de un UPDATE o un
     * DELETE.
     *
     * @return array{clave: string, op: string, valor: mixed}|null
     */
    public static function comparacionSimple(array $n): ?array
    {
        if ($n['k'] !== 'bin' || !\in_array($n['op'], ['=', '<>', '!=', '<', '<=', '>', '>='], true)) {
            return null;
        }
        // La columna a un lado y un literal al otro, en cualquier orden
        [$col, $lit, $op] = [$n['i'], $n['d'], $n['op']];
        if ($col['k'] !== 'col' || $lit['k'] !== 'lit') {
            [$col, $lit] = [$n['d'], $n['i']];
            if ($col['k'] !== 'col' || $lit['k'] !== 'lit') {
                return null;
            }
            $op = ['<' => '>', '>' => '<', '<=' => '>=', '>=' => '<='][$op] ?? $op;
        }
        if (!isset($col['clave']) || $lit['v'] === null) {
            return null;                            // alias o NULL: al camino general
        }
        return ['clave' => $col['clave'], 'op' => $op, 'valor' => $lit['v']];
    }

    /** @param mixed $a @param mixed $b */
    public static function compara(string $op, $a, $b): bool
    {
        $c = self::comparar($a, $b);
        if ($c === null) {
            return false;                           // incomparables: no cumple
        }
        switch ($op) {
            case '=':  return $c === 0;
            case '<>':
            case '!=': return $c !== 0;
            case '<':  return $c < 0;
            case '<=': return $c <= 0;
            case '>':  return $c > 0;
            case '>=': return $c >= 0;
        }
        return false;
    }

    /**
     * Comparación total para ORDER BY: NULL primero, después los números y
     * después los textos, como en SQLite (2.10: un texto que era un número iba
     * entre los números).
     *
     * Entre dos textos se usa la colación configurada (ver Collation): por
     * defecto no distingue mayúsculas ni acentos, y desempata byte a byte
     * para que el resultado sea siempre el mismo. Con $binaria (un ORDER BY …
     * COLLATE BINARY), byte a byte sin más.
     */
    public static function compararOrden($a, $b, bool $binaria = false): int
    {
        if ($a === null || $b === null) {
            return $a === null ? ($b === null ? 0 : -1) : 1;
        }
        if (\is_string($a)) {
            if (!\is_string($b)) {
                return 1;
            }
            if (!$binaria && Collation::activa()) {
                $c = strcmp(Collation::clave($a), Collation::clave($b)) <=> 0;
                if ($c !== 0) {
                    return $c;
                }
            }
            return strcmp($a, $b) <=> 0;
        }
        if (\is_string($b)) {
            return -1;
        }
        // Dos números (un booleano es 1 o 0): ver numeros()
        return (\is_bool($a) ? (int)$a : $a) <=> (\is_bool($b) ? (int)$b : $b)
            ?: (\is_int($a) === \is_int($b) ? 0 : self::numeros(\is_bool($a) ? (int)$a : $a, \is_bool($b) ? (int)$b : $b));
    }

    /**
     * Valor de verdad de una condición.
     * @return bool|null null = desconocido (propaga NULL)
     */
    public static function verdadero($v): ?bool
    {
        if ($v === null) {
            return null;
        }
        if (\is_bool($v)) {
            return $v;
        }
        if (\is_int($v) || \is_float($v)) {
            return $v != 0;
        }
        $t = trim((string)$v);
        return is_numeric($t) ? ($t + 0) != 0 : false;
    }

    /**
     * Clave estable para agrupar y para DISTINCT (distingue 1 de '1').
     *
     * Como en SQLite, 1 y 1.0 son el mismo valor. Un decimal va con sus ocho
     * bytes: (string) redondea a 14 cifras y juntaba 0.3 con
     * 0.30000000000000004 (y es más lento). La clave no sale de la memoria.
     */
    public static function clave($v): string
    {
        if ($v === null)  { return 'n'; }
        if (\is_int($v))   { return 'i' . $v; }
        if (\is_float($v)) {
            // Todo el rango de los enteros, de -2^63 (que es uno) hasta 2^63 (que
            // no): 2.10, antes hasta 9e18, y -2^63.0 no era el entero -2^63
            return $v >= -9.2233720368547758E18 && $v < 9.2233720368547758E18 && (float)(int)$v === $v ? 'i' . (int)$v : 'f' . pack('e', $v);
        }
        if (\is_string($v)) {
            return 's' . $v;
        }
        // De un JSON editado a mano: un booleano es 1 o 0 (2.10: el texto '1'
        // o ''), y una lista o un objeto va aparte, con su JSON
        return \is_bool($v) ? 'i' . (int)$v : 'j' . json_encode($v);
    }
}
