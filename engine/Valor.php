<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Semántica de valores SQL. Un valor es null, int, float o string.
 *
 * Comparación (parecida a la de SQLite, no idéntica: aquí no hay afinidad de
 * tipos por columna, así que un texto se compara con un número convirtiendo el
 * texto, no según el tipo declarado de la columna):
 *   - si los dos son numéricos (o cadenas numéricas) se comparan como números
 *   - si no, se comparan como texto
 *   - las fechas se guardan como 'yyyy-MM-dd...', así que el orden de texto
 *     coincide con el cronológico y no hace falta convertirlas
 *   - cualquier comparación con NULL da NULL (desconocido)
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Valor
{
    public static function esNumerico($v): bool
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)));
    }

    /** @return int|float */
    public static function aNumero($v)
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v)) {
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
        return 0;
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
        if (is_int($n)) {
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
        if (is_bool($v))    { return $v ? '1' : '0'; }
        if (is_float($v))   { return self::floatATexto($v); }
        return (string)$v;
    }

    /** Evita notaciones raras al concatenar: 10.0 -> '10.0', 1.0E+25 -> '1.0e25' */
    private static function floatATexto(float $v): string
    {
        if (is_nan($v) || is_infinite($v)) {
            return '';
        }
        $s = (string)$v;
        return str_contains($s, '.') || str_contains($s, 'e') || str_contains($s, 'E') ? $s : $s . '.0';
    }

    /**
     * Los bytes con los que puede empezar un texto que es un número (ver
     * esNumerico()): espacios, signo, punto o cifra. Uno que empieza por otra
     * cosa no lo es, y se sabe sin llamar a is_numeric() (2.9).
     */
    private const CABEZA_NUMERO = [' ' => true, "\t" => true, "\n" => true, "\r" => true, "\v" => true, "\f" => true, "\0" => true,
        '+' => true, '-' => true, '.' => true, '0' => true, '1' => true, '2' => true, '3' => true, '4' => true,
        '5' => true, '6' => true, '7' => true, '8' => true, '9' => true];

    /**
     * Compara dos valores, con un orden total (2.9): los números y los textos
     * que son números, como números; los textos que no lo son, como texto y
     * detrás de todos aquellos, como en SQLite los números van antes que los
     * textos. Antes un texto numérico y uno que no lo era se comparaban como
     * texto, y el orden daba vueltas ('9' < '10' < '1a' < '9'): ORDER BY, LIMIT,
     * MIN y MAX podían no estar de acuerdo entre sí.
     *
     * @return int|null -1, 0, 1 — o null si alguno es NULL
     */
    public static function comparar($a, $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }
        // Atajo para dos números, que es el caso corriente: esNumerico() diría
        // que sí y aNumero() los devolvería tal cual, así que el resultado es
        // idéntico ahorrando cuatro llamadas. En un ORDER BY sobre 20.000 filas
        // esto se ejecuta unas trescientas mil veces.
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a <=> $b;
        }
        // Y otro para dos textos, que es lo que compara cualquier WHERE sobre
        // una columna de texto. El trim() solo hace falta si empieza como un
        // número, y así casi nunca se copia la cadena
        if (is_string($a) && is_string($b)) {
            if (is_numeric($a)) {
                return is_numeric($b) || (isset(self::CABEZA_NUMERO[$b[0] ?? '']) && is_numeric(trim($b)))
                    ? (trim($a) + 0) <=> (trim($b) + 0) : -1;
            }
            // Un texto que empieza después del '9' no es un número, y byte a
            // byte va detrás de cualquiera que lo sea: lo de siempre, sin mirar $b
            if ($a >= ':') {
                return strcmp($a, $b) <=> 0;
            }
            $na = isset(self::CABEZA_NUMERO[$a[0] ?? '']) && is_numeric(trim($a));
            $nb = is_numeric($b) || (isset(self::CABEZA_NUMERO[$b[0] ?? '']) && is_numeric(trim($b)));
            if ($na && $nb) {
                return (trim($a) + 0) <=> (trim($b) + 0);
            }
            return $na === $nb ? strcmp($a, $b) <=> 0 : ($na ? -1 : 1);
        }
        $na = self::esNumerico($a);
        $nb = self::esNumerico($b);
        if ($na && $nb) {
            return self::aNumero($a) <=> self::aNumero($b);
        }
        // Un número (o un texto que lo es) antes que un texto que no lo es
        if ($na && is_string($b)) {
            return -1;
        }
        if ($nb && is_string($a)) {
            return 1;
        }
        return strcmp(self::aTexto($a), self::aTexto($b)) <=> 0;
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
        if ($n['k'] !== 'bin' || !in_array($n['op'], ['=', '<>', '!=', '<', '<=', '>', '>='], true)) {
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
     * Comparación total para ORDER BY: NULL primero, como en SQLite.
     *
     * Entre dos textos que no son números se usa la colación configurada (ver
     * Collation): por defecto no distingue mayúsculas ni acentos, y desempata
     * byte a byte para que el resultado sea siempre el mismo. Lo demás, como
     * comparar(): con su mismo orden total (2.9).
     */
    public static function compararOrden($a, $b): int
    {
        if ($a === null && $b === null) { return 0; }
        if ($a === null) { return -1; }
        if ($b === null) { return 1; }

        // Dos textos que no son números (ver comparar()): con la colación
        if (is_string($a) && is_string($b)
            && ($a >= ':' || !(is_numeric($a) || (isset(self::CABEZA_NUMERO[$a[0] ?? '']) && is_numeric(trim($a)))))
            && ($b >= ':' || !(is_numeric($b) || (isset(self::CABEZA_NUMERO[$b[0] ?? '']) && is_numeric(trim($b)))))
            && Collation::activa()) {
            $c = strcmp(Collation::clave($a), Collation::clave($b)) <=> 0;
            return $c !== 0 ? $c : (strcmp($a, $b) <=> 0);
        }
        return self::comparar($a, $b) ?? 0;
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
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
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
        if (is_int($v))   { return 'i' . $v; }
        if (is_float($v)) {
            return is_finite($v) && abs($v) < 9.0E+18 && (float)(int)$v === $v ? 'i' . (int)$v : 'f' . pack('e', $v);
        }
        return 's' . $v;
    }
}
