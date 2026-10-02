<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Funciones SQL. Nombres y comportamiento iguales a los de SQLite.
 *
 * Texto:     UPPER LOWER LENGTH SUBSTR/SUBSTRING TRIM LTRIM RTRIM REPLACE TRANSLATE INSTR
 * Números:   ABS ROUND RANDOM
 * Fecha:     DATE TIME DATETIME STRFTIME  (acepta 'now')
 * Nulos:     COALESCE NULLIF IFNULL
 * Varios:    MIN MAX con 2 o más argumentos (con 1 son de agregación)
 * Agregados: COUNT SUM AVG MIN MAX GROUP_CONCAT  (admiten DISTINCT)
 *
 * https://miguelenred.es/jsonsqldb
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
            case 'TRANSLATE':
                // TRANSLATE(texto, de, a): cada carácter de «de» pasa a ser el que
                // está en su misma posición en «a»; los que sobran en «de», fuera
                // (como en PostgreSQL, Oracle y SQL Server)
                self::exige($nombre, $args, 3);
                if ($args[0] === null || $args[1] === null || $args[2] === null) { return null; }
                $de = (array)preg_split('//u', Valor::aTexto($args[1]), -1, PREG_SPLIT_NO_EMPTY);
                $a  = (array)preg_split('//u', Valor::aTexto($args[2]), -1, PREG_SPLIT_NO_EMPTY);
                $mapa = [];
                foreach ($de as $i => $c) {
                    $mapa[$c] ??= $a[$i] ?? '';
                }
                return strtr(Valor::aTexto($args[0]), $mapa);
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
                self::exige($nombre, $args, 2, 12);
                $d = self::conModificadores($args[1], array_slice($args, 2));
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

        // Índice base 0 interno. En SQL la primera posición es la 1, y la 0 se
        // refiere al hueco anterior al primer carácter: SUBSTR('abcdef',0,3)
        // abarca las posiciones 0, 1 y 2, de las que solo existen dos, así que
        // devuelve 'ab' y no 'abc'. Restar siempre 1 conserva ese desfase.
        $ini = $ini < 0 ? max(0, $largo + $ini) : $ini - 1;

        if (!isset($args[2])) {
            return self::corte($s, max(0, $ini), null);
        }
        $len = (int)Valor::aNumero($args[2]);

        // La ventana pedida es [ini, ini+len) con longitud positiva, y
        // [ini+len, ini) con longitud negativa: en ese caso son los caracteres
        // ANTERIORES a la posición dada. Se recorta a lo que existe de verdad,
        // una sola vez, para no descontar dos veces lo que cae fuera.
        $desde = $len < 0 ? $ini + $len : $ini;
        $hasta = $len < 0 ? $ini        : $ini + $len;

        $desde = max(0, $desde);
        $hasta = max(0, $hasta);

        return self::corte($s, $desde, max(0, $hasta - $desde));
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
        $d = self::conModificadores($v, array_slice($args, 1));
        return $d === null ? null : $d->format($formato);
    }

    /**
     * Una fecha con sus modificadores. 'unixepoch', el primero, dice que el
     * valor son segundos desde 1970 (en UTC, como en SQLite).
     *
     * @param array<int,mixed> $mods
     */
    private static function conModificadores($v, array $mods): ?\DateTimeImmutable
    {
        if ($mods !== [] && is_string($mods[0]) && strcasecmp(trim($mods[0]), 'unixepoch') === 0) {
            if ($v === null || !is_numeric($v)) {
                return null;
            }
            $d = (new \DateTimeImmutable('@' . (int)floor((float)$v)))->modify(sprintf('+%d microseconds', (int)round(fmod((float)$v, 1) * 1e6)));
            return self::modificar($d, array_slice($mods, 1));
        }
        return self::modificar(self::aFecha($v), $mods);
    }

    /**
     * Los modificadores de SQLite que van detrás de la fecha: '+N days' (y
     * seconds, minutes, hours, months, years, con o sin signo y en singular o
     * plural) y 'start of day' / 'start of month' / 'start of year'. Hasta
     * 2.7.3 se ignoraban sin avisar: DATE(x, '+1 day') daba la misma x. Uno que
     * no se reconoce es un error, no un resultado equivocado.
     *
     * Los meses y los años se suman como en SQLite: el 31 de enero más un mes
     * es el 3 de marzo, no el 28 de febrero.
     *
     * @param array<int,mixed> $mods
     */
    private static function modificar(?\DateTimeImmutable $d, array $mods): ?\DateTimeImmutable
    {
        foreach ($mods as $m) {
            if ($d === null || $m === null) {
                return null;
            }
            $texto = strtolower(trim(Valor::aTexto($m)));
            if (preg_match('/^([+-]?)(\d+(?:\.\d+)?)\s*(second|minute|hour|day|month|year)s?$/', $texto, $p)) {
                $n = (float)$p[2] * ($p[1] === '-' ? -1 : 1);
                if ($p[3] === 'month' || $p[3] === 'year') {
                    if ($n != (int)$n) {
                        throw JsonSqlDbError::syntax("Modificador de fecha no soportado: '$texto' (los meses y los años, enteros)");
                    }
                    $d = $d->modify(sprintf('%+d %s', (int)$n, $p[3]));
                } else {
                    // En segundos, para admitir '+1.5 days' como SQLite
                    $seg = (int)round($n * ['second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400][$p[3]]);
                    $d = $d->modify(sprintf('%+d seconds', $seg));
                }
                continue;
            }
            // weekday N: el siguiente día de la semana N (0 = domingo), o el mismo
            if (preg_match('/^weekday\s+([0-6])$/', $texto, $p)) {
                $d = $d->modify('+' . (((int)$p[1] - (int)$d->format('w') + 7) % 7) . ' days');
                continue;
            }
            switch ($texto) {
                // Aquí las fechas no llevan zona horaria: 'localtime' y 'utc' no
                // cambian nada (antes de 2.7.3 ningún modificador lo hacía, y
                // DATETIME('now', 'localtime') es muy común en SQL de SQLite)
                case 'localtime':
                case 'utc':            continue 2;
                case 'start of day':   $d = $d->setTime(0, 0); continue 2;
                case 'start of month': $d = $d->setDate((int)$d->format('Y'), (int)$d->format('m'), 1)->setTime(0, 0); continue 2;
                case 'start of year':  $d = $d->setDate((int)$d->format('Y'), 1, 1)->setTime(0, 0); continue 2;
            }
            throw JsonSqlDbError::syntax("Modificador de fecha no soportado: '$texto'");
        }
        return $d;
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
