<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Semántica de valores SQL. Un valor es null, int, float o string.
 *
 * Comparación (igual que SQLite en la práctica):
 *   - si los dos son numéricos (o cadenas numéricas) se comparan como números
 *   - si no, se comparan como texto
 *   - las fechas se guardan como 'yyyy-MM-dd...', así que el orden de texto
 *     coincide con el cronológico y no hace falta convertirlas
 *   - cualquier comparación con NULL da NULL (desconocido)
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
                return $m[0] + 0;
            }
        }
        return 0;
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
     * Compara dos valores.
     * @return int|null -1, 0, 1 — o null si alguno es NULL
     */
    public static function comparar($a, $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }
        if (self::esNumerico($a) && self::esNumerico($b)) {
            $x = self::aNumero($a);
            $y = self::aNumero($b);
            return $x <=> $y;
        }
        return strcmp(self::aTexto($a), self::aTexto($b)) <=> 0;
    }

    /**
     * Comparación total para ORDER BY: NULL primero, como en SQLite.
     *
     * Entre dos textos se usa la colación configurada (ver Collation): por
     * defecto no distingue mayúsculas ni acentos, y desempata byte a byte para
     * que el resultado sea siempre el mismo.
     */
    public static function compararOrden($a, $b): int
    {
        if ($a === null && $b === null) { return 0; }
        if ($a === null) { return -1; }
        if ($b === null) { return 1; }

        if (is_string($a) && is_string($b) && !is_numeric(trim($a)) && !is_numeric(trim($b))
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

    /** Clave estable para agrupar y para DISTINCT (distingue 1 de '1'). */
    public static function clave($v): string
    {
        if ($v === null)  { return 'n'; }
        if (is_int($v))   { return 'i' . $v; }
        if (is_float($v)) { return 'f' . $v; }
        return 's' . $v;
    }
}
