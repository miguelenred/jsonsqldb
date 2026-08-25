<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Funciones SQL. Nombres y comportamiento iguales a los de SQLite.
 *
 * Texto:     UPPER LOWER LENGTH SUBSTR/SUBSTRING TRIM LTRIM RTRIM REPLACE INSTR
 * Números:   ABS ROUND RANDOM
 * Fecha:     DATE TIME DATETIME STRFTIME  (acepta 'now')
 * Nulos:     COALESCE NULLIF IFNULL
 * Varios:    MIN MAX con 2 o más argumentos (con 1 son de agregación)
 * Agregados: COUNT SUM AVG MIN MAX GROUP_CONCAT  (admiten DISTINCT)
 */
final class Functions
{
    public const AGREGADOS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT'];

    /** Ejecuta una función escalar con los argumentos ya evaluados. */
    public static function escalar(string $nombre, array $args)
    {
        switch ($nombre) {

            // ---------- Texto ----------
            case 'CONCAT':
                // No existe en SQLite, donde se usa ||. Se admite por comodidad
                // para quien viene de MySQL, y con su misma semántica: si algún
                // argumento es NULL, el resultado es NULL.
                if (count($args) < 2) {
                    throw JsonSqlDbError::syntax('CONCAT() necesita al menos 2 argumentos');
                }
                $partes = '';
                foreach ($args as $a) {
                    if ($a === null) { return null; }
                    $partes .= Valor::aTexto($a);
                }
                return $partes;

            case 'UPPER':
                self::exige($nombre, $args, 1);
                return $args[0] === null ? null : self::mayus(Valor::aTexto($args[0]));
            case 'LOWER':
                self::exige($nombre, $args, 1);
                return $args[0] === null ? null : self::minus(Valor::aTexto($args[0]));
            case 'LENGTH':
                self::exige($nombre, $args, 1);
                return $args[0] === null ? null : Types::longitud(Valor::aTexto($args[0]));
            case 'TRIM':
            case 'LTRIM':
            case 'RTRIM':
                self::exige($nombre, $args, 1, 2);
                if ($args[0] === null) { return null; }
                $s = Valor::aTexto($args[0]);
                $c = isset($args[1]) ? Valor::aTexto($args[1]) : " \t\n\r\0\x0B";
                if ($c === '') { return $s; }
                return $nombre === 'TRIM' ? trim($s, $c) : ($nombre === 'LTRIM' ? ltrim($s, $c) : rtrim($s, $c));
            case 'REPLACE':
                self::exige($nombre, $args, 3);
                if ($args[0] === null || $args[1] === null || $args[2] === null) { return null; }
                $buscar = Valor::aTexto($args[1]);
                return $buscar === ''
                    ? Valor::aTexto($args[0])
                    : str_replace($buscar, Valor::aTexto($args[2]), Valor::aTexto($args[0]));
            case 'SUBSTR':
            case 'SUBSTRING':
                return self::substr($args);
            case 'INSTR':
                self::exige($nombre, $args, 2);
                if ($args[0] === null || $args[1] === null) { return null; }
                $aguja = Valor::aTexto($args[1]);
                if ($aguja === '') { return 1; }
                $pos = strpos(Valor::aTexto($args[0]), $aguja);
                return $pos === false ? 0 : Types::longitud(substr(Valor::aTexto($args[0]), 0, $pos)) + 1;

            // ---------- Números ----------
            case 'ABS':
                self::exige($nombre, $args, 1);
                return $args[0] === null ? null : abs(Valor::aNumero($args[0]));
            case 'ROUND':
                self::exige($nombre, $args, 1, 2);
                if ($args[0] === null) { return null; }
                $dec = isset($args[1]) ? (int)Valor::aNumero($args[1]) : 0;
                return round((float)Valor::aNumero($args[0]), $dec);
            case 'RANDOM':
                self::exige($nombre, $args, 0);
                // Entero de 64 bits con signo, como el random() de SQLite. Antes
                // se devolvía un rango de 32 bits, que no es lo mismo.
                return random_int(PHP_INT_MIN, PHP_INT_MAX);

            // ---------- Nulos ----------
            case 'COALESCE':
                if (count($args) < 2) {
                    throw JsonSqlDbError::syntax('COALESCE necesita al menos 2 argumentos');
                }
                foreach ($args as $a) {
                    if ($a !== null) { return $a; }
                }
                return null;
            case 'IFNULL':
                self::exige($nombre, $args, 2);
                return $args[0] ?? $args[1];
            case 'NULLIF':
                self::exige($nombre, $args, 2);
                return Valor::comparar($args[0], $args[1]) === 0 ? null : $args[0];

            // ---------- MIN/MAX escalares ----------
            case 'MIN':
            case 'MAX':
                $res = null;
                foreach ($args as $a) {
                    if ($a === null) { return null; }
                    if ($res === null) { $res = $a; continue; }
                    $c = Valor::comparar($a, $res);
                    if ($c !== null && (($nombre === 'MIN' && $c < 0) || ($nombre === 'MAX' && $c > 0))) {
                        $res = $a;
                    }
                }
                return $res;

            // ---------- Fecha y hora ----------
            case 'DATE':
                return self::fecha($args, 'Y-m-d');
            case 'TIME':
                return self::fecha($args, 'H:i:s');
            case 'DATETIME':
                return self::fecha($args, 'Y-m-d H:i:s');
            case 'STRFTIME':
                self::exige($nombre, $args, 2);
                $d = self::aFecha($args[1]);
                return $d === null ? null : self::strftime(Valor::aTexto($args[0]), $d);
        }

        throw JsonSqlDbError::syntax("Función no soportada: $nombre()");
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
        string $separador = ','
    ) {
        if ($nombre === 'COUNT' && $valores === null) {
            return $filas;                       // COUNT(*)
        }
        $valores = $valores ?? [];

        // Los agregados ignoran los NULL
        $limpios = [];
        foreach ($valores as $v) {
            if ($v !== null) { $limpios[] = $v; }
        }
        if ($distinct) {
            $vistos = [];
            $unicos = [];
            foreach ($limpios as $v) {
                $k = Valor::clave($v);
                if (!isset($vistos[$k])) { $vistos[$k] = true; $unicos[] = $v; }
            }
            $limpios = $unicos;
        }

        switch ($nombre) {
            case 'COUNT':
                return count($limpios);
            case 'SUM':
                if ($limpios === []) { return null; }
                $s = 0;
                foreach ($limpios as $v) { $s += Valor::aNumero($v); }
                return $s;
            case 'AVG':
                if ($limpios === []) { return null; }
                $s = 0;
                foreach ($limpios as $v) { $s += Valor::aNumero($v); }
                return $s / count($limpios);
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
                    $c = Valor::comparar($v, $res);
                    if ($c !== null && (($nombre === 'MIN' && $c < 0) || ($nombre === 'MAX' && $c > 0))) {
                        $res = $v;
                    }
                }
                return $res;
        }

        throw JsonSqlDbError::syntax("Función de agregación no soportada: $nombre()");
    }

    // ------------------------------------------------------------------
    // Auxiliares
    // ------------------------------------------------------------------

    private static function substr(array $args)
    {
        self::exige('SUBSTR', $args, 2, 3);
        if ($args[0] === null || $args[1] === null) {
            return null;
        }
        $s     = Valor::aTexto($args[0]);
        $largo = Types::longitud($s);
        $ini   = (int)Valor::aNumero($args[1]);

        if ($ini < 0) {
            $ini = max(0, $largo + $ini);
        } elseif ($ini > 0) {
            $ini--;                       // SQL empieza en 1
        }
        if (!isset($args[2])) {
            return self::corte($s, $ini, null);
        }
        $len = (int)Valor::aNumero($args[2]);
        if ($len < 0) {
            $nuevoIni = max(0, $ini + $len);
            $len      = $ini - $nuevoIni;
            $ini      = $nuevoIni;
        }
        return self::corte($s, $ini, $len);
    }

    private static function corte(string $s, int $ini, ?int $len): string
    {
        if (function_exists('mb_substr')) {
            return $len === null ? mb_substr($s, $ini, null, 'UTF-8') : mb_substr($s, $ini, $len, 'UTF-8');
        }
        $car = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tro = $len === null ? array_slice($car, $ini) : array_slice($car, $ini, $len);
        return implode('', $tro);
    }

    /** Acentos y eñes, para poder cambiar de caja sin la extensión mbstring */
    private const ACENTOS_MIN = 'áéíóúüàèìòùâêîôûäëïöñçãõåæøœß';
    private const ACENTOS_MAY = 'ÁÉÍÓÚÜÀÈÌÒÙÂÊÎÔÛÄËÏÖÑÇÃÕÅÆØŒSS';

    private static function mayus(string $s): string
    {
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($s, 'UTF-8');
        }
        return strtoupper(strtr($s, self::tabla(self::ACENTOS_MIN, self::ACENTOS_MAY)));
    }

    private static function minus(string $s): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($s, 'UTF-8');
        }
        return strtolower(strtr($s, self::tabla(self::ACENTOS_MAY, self::ACENTOS_MIN)));
    }

    /** @return array<string,string> equivalencias carácter a carácter */
    private static function tabla(string $desde, string $hasta): array
    {
        static $memo = [];
        $clave = $desde;
        if (isset($memo[$clave])) {
            return $memo[$clave];
        }
        $a = preg_split('//u', $desde, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', $hasta, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $t = [];
        foreach ($a as $i => $c) {
            if (isset($b[$i])) { $t[$c] = $b[$i]; }
        }
        return $memo[$clave] = $t;
    }

    /** DATE/TIME/DATETIME: sin argumentos equivale a 'now'. */
    private static function fecha(array $args, string $formato): ?string
    {
        // Sin argumentos, la fecha de ahora. CON argumento NULL, el resultado es
        // NULL: un ?? aquí confundía las dos cosas y DATE(NULL) devolvía hoy.
        $v = $args === [] ? 'now' : $args[0];
        $d = self::aFecha($v);
        return $d === null ? null : $d->format($formato);
    }

    /** Convierte un valor a fecha. Acepta 'now' y el formato propio del motor. */
    public static function aFecha($v): ?\DateTimeImmutable
    {
        if ($v === null) {
            return null;
        }
        $s = trim(Valor::aTexto($v));
        if ($s === '') {
            return null;
        }
        if (strcasecmp($s, 'now') === 0) {
            return new \DateTimeImmutable('now');
        }
        if (!Types::esFecha($s)) {
            return null;
        }
        $s = str_replace('T', ' ', $s);
        foreach (['Y-m-d H:i:s.v', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $s);
            if ($d !== false) {
                return $d;
            }
        }
        return null;
    }

    /** strftime con los especificadores más habituales de SQLite. */
    private static function strftime(string $formato, \DateTimeImmutable $d): string
    {
        $mapa = [
            '%Y' => $d->format('Y'),
            '%m' => $d->format('m'),
            '%d' => $d->format('d'),
            '%H' => $d->format('H'),
            '%M' => $d->format('i'),
            '%S' => $d->format('s'),
            '%f' => $d->format('s.v'),
            '%j' => str_pad((string)((int)$d->format('z') + 1), 3, '0', STR_PAD_LEFT),
            '%w' => $d->format('w'),
            '%W' => $d->format('W'),
            '%s' => (string)$d->getTimestamp(),
            '%%' => '%',
        ];
        return strtr($formato, $mapa);
    }

    private static function exige(string $nombre, array $args, int $min, ?int $max = null): void
    {
        $n = count($args);
        $max ??= $min;
        if ($n < $min || $n > $max) {
            $esperado = $min === $max ? "$min" : "entre $min y $max";
            throw JsonSqlDbError::syntax("$nombre() espera $esperado argumento(s) y ha recibido $n");
        }
    }
}
