<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Funciones SQL. Nombres y comportamiento iguales a los de SQLite.
 *
 * Texto:     CONCAT UPPER LOWER LENGTH SUBSTR/SUBSTRING TRIM LTRIM RTRIM REPLACE TRANSLATE INSTR
 * Números:   ABS ROUND RANDOM
 * Fecha:     DATE TIME DATETIME STRFTIME  (acepta 'now')
 * Nulos:     COALESCE NULLIF IFNULL
 * Varios:    MIN MAX con 2 o más argumentos (con 1 son de agregación)
 *
 * Las demás de SQLite y las de MySQL, en FunctionsAmpliadas, y los agregados
 * (los de Evaluator::AGREGADOS, que admiten DISTINCT), en SelectAgrupada.
 *
 * https://miguelenred.es/jsonsqldb
 */
class Functions
{
    /** Un conjunto de TRIM con el que trim() de PHP no vale: caracteres de más de un byte o '..' (ver 'TRIM*') */
    public const POR_CARACTERES = '/[\x80-\xff]|\.\./';

    /** Ejecuta una función escalar con los argumentos ya evaluados. */
    public static function escalar(string $nombre, array $args)
    {
        switch ($nombre) {

            // ---------- Texto ----------
            case 'CONCAT':
                // No existe en SQLite, donde se usa ||. Se admite por comodidad
                // para quien viene de MySQL, y con su misma semántica: si algún
                // argumento es NULL, el resultado es NULL.
                if (\count($args) < 2) {
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
            case 'TRIM*':
            case 'LTRIM*':
            case 'RTRIM*':
                // Con caracteres de más de un byte en el conjunto, o '..' (que
                // para trim() de PHP es un rango), carácter a carácter, como
                // SQLite (2.9: quitaba bytes sueltos, y TRIM('áxá', 'ñ') dejaba
                // un texto UTF-8 roto). Con '*' llega uno que puede tenerlos
                // (ver Evaluator::resolver()); si no los tiene, el de siempre
                self::exige(substr($nombre, 0, -1), $args, 1, 2);
                $c = $args[1] === null ? null : Valor::aTexto($args[1]);
                if ($c === null || !preg_match(self::POR_CARACTERES, $c)) {
                    return self::escalar(substr($nombre, 0, -1), $args);
                }
                return $args[0] === null ? null : self::recortar(Valor::aTexto($args[0]), $c, $nombre !== 'RTRIM*', $nombre !== 'LTRIM*');
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
                $dec = isset($args[1]) ? Valor::entero(Valor::aNumero($args[1])) : 0;
                return round((float)Valor::aNumero($args[0]), $dec);
            case 'RANDOM':
                self::exige($nombre, $args, 0);
                // Entero de 64 bits con signo, como el random() de SQLite. Antes
                // se devolvía un rango de 32 bits, que no es lo mismo.
                return random_int(PHP_INT_MIN, PHP_INT_MAX);

            // ---------- Nulos ----------
            case 'COALESCE':
                if (\count($args) < 2) {
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
                    $c = Valor::compararClase($a, $res);       // por clase, como en SQLite: MAX(10, '9') es '9' (2.10)
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
                $d = self::conModificadores($args[1], \array_slice($args, 2));
                // %s, los segundos de UNIXEPOCH() con la misma fecha (2.9: la
                // tomaba en la zona horaria de PHP, y no en UTC como SQLite)
                return $d === null ? null : self::formatearFecha(Valor::aTexto($args[0]), $d, self::FECHA_SQLITE, null,
                    static fn(): ?int => FunctionsAmpliadas::compatibles('UNIXEPOCH', \array_slice($args, 1)));
        }
        return FunctionsAmpliadas::compatibles($nombre, $args);
    }

    /**
     * El valor que se compara con una colación: NOCASE pasa a minúsculas solo
     * de la A a la Z, como SQLite; RTRIM quita los espacios del final. Lo que
     * no es texto, igual.
     *
     * @param mixed $v
     * @return mixed
     */
    public static function plegar($v, string $colacion)
    {
        if (!\is_string($v)) {
            return $v;
        }
        return $colacion === 'NOCASE' ? strtr($v, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : rtrim($v, ' ');
    }

    /**
     * NULLIF, y MIN, MAX, GREATEST y LEAST de varios valores, con una
     * colación (2.9): comparan los valores plegados y devuelven el de
     * verdad. Como en SQLite, con dos iguales MIN da el último y MAX el
     * primero.
     */
    public static function conColacion(string $nombre, array $args, string $colacion)
    {
        if ($nombre === 'NULLIF') {
            self::exige($nombre, $args, 2);
            return Valor::comparar(self::plegar($args[0], $colacion), self::plegar($args[1], $colacion)) === 0 ? null : $args[0];
        }
        if (\count($args) < 2) {
            return self::escalar($nombre, $args);    // el error de siempre
        }
        $min   = $nombre === 'MIN' || $nombre === 'LEAST';
        $mejor = 0;
        foreach ($args as $i => $v) {
            if ($v === null) {
                return null;
            }
            $c = $i === 0 ? null : Valor::compararClase(self::plegar($args[$mejor], $colacion), self::plegar($v, $colacion));
            if ($c !== null && ($min ? $c >= 0 : $c < 0)) {
                $mejor = $i;
            }
        }
        return $args[$mejor];
    }

    // ------------------------------------------------------------------
    // Auxiliares
    // ------------------------------------------------------------------

    protected static function substr(array $args)
    {
        self::exige('SUBSTR', $args, 2, 3);
        if ($args[0] === null || $args[1] === null) {
            return null;
        }
        $s     = Valor::aTexto($args[0]);
        $largo = Types::longitud($s);
        $ini   = Valor::entero(Valor::aNumero($args[1]));

        // Índice base 0 interno. En SQL la primera posición es la 1, y la 0 se
        // refiere al hueco anterior al primer carácter: SUBSTR('abcdef',0,3)
        // abarca las posiciones 0, 1 y 2, de las que solo existen dos, así que
        // devuelve 'ab' y no 'abc'. Restar siempre 1 conserva ese desfase.
        $ini = $ini < 0 ? max(0, $largo + $ini) : $ini - 1;

        if (!isset($args[2])) {
            return self::corte($s, max(0, $ini), null);
        }
        $len = Valor::entero(Valor::aNumero($args[2]));

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

    protected static function corte(string $s, int $ini, ?int $len): string
    {
        if (function_exists('mb_substr')) {
            return $len === null ? mb_substr($s, $ini, null, 'UTF-8') : mb_substr($s, $ini, $len, 'UTF-8');
        }
        $car = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tro = $len === null ? \array_slice($car, $ini) : \array_slice($car, $ini, $len);
        return implode('', $tro);
    }

    /** TRIM, LTRIM y RTRIM carácter a carácter (2.9): quita de los extremos los caracteres UTF-8 de $quitar, sin partir ninguno */
    private static function recortar(string $s, string $quitar, bool $izquierda, bool $derecha): string
    {
        $lista = preg_split('//u', $quitar, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($quitar);
        do {
            $antes = $s;
            foreach ($lista as $c) {
                if ($izquierda && str_starts_with($s, $c)) {
                    $s = substr($s, \strlen($c));
                }
                if ($derecha && str_ends_with($s, $c)) {
                    $s = substr($s, 0, -\strlen($c));
                }
            }
        } while ($s !== $antes);
        return $s;
    }

    /**
     * UPPER y LOWER, con acentos y eñes, como MySQL, PostgreSQL y Access (en
     * SQLite, solo de la A a la Z). Sin mbstring, las letras de esta tabla: la
     * de antes de la 2.10 tenía la ß de más en las mayúsculas, y LOWER('S')
     * daba 'ß'. strtr() para la A a la Z: no depende del locale, al revés que
     * strtoupper() en PHP 8.0 y 8.1.
     */
    private const MAYUSCULAS = ['á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ü' => 'Ü', 'à' => 'À', 'è' => 'È',
        'ì' => 'Ì', 'ò' => 'Ò', 'ù' => 'Ù', 'â' => 'Â', 'ê' => 'Ê', 'î' => 'Î', 'ô' => 'Ô', 'û' => 'Û', 'ä' => 'Ä', 'ë' => 'Ë',
        'ï' => 'Ï', 'ö' => 'Ö', 'ñ' => 'Ñ', 'ç' => 'Ç', 'ã' => 'Ã', 'õ' => 'Õ', 'å' => 'Å', 'æ' => 'Æ', 'ø' => 'Ø', 'œ' => 'Œ'];

    private static function mayus(string $s): string
    {
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($s, 'UTF-8');
        }
        return strtr(strtr($s, self::MAYUSCULAS + ['ß' => 'SS']), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    private static function minus(string $s): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($s, 'UTF-8');
        }
        static $minusculas = null;
        return strtr(strtr($s, $minusculas ??= array_flip(self::MAYUSCULAS)), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /** DATE/TIME/DATETIME: sin argumentos equivale a 'now'. */
    private static function fecha(array $args, string $formato): ?string
    {
        // Sin argumentos, la fecha de ahora. CON argumento NULL, el resultado es
        // NULL: un ?? aquí confundía las dos cosas y DATE(NULL) devolvía hoy.
        $v = $args === [] ? 'now' : $args[0];
        $d = self::conModificadores($v, \array_slice($args, 1));
        return $d === null ? null : $d->format($formato);
    }

    /**
     * Una fecha con sus modificadores. 'unixepoch', el primero, dice que el
     * valor son segundos desde 1970 (en UTC, como en SQLite).
     *
     * @param array<int,mixed> $mods
     */
    protected static function conModificadores($v, array $mods): ?\DateTimeImmutable
    {
        if ($mods !== [] && \is_string($mods[0]) && strcasecmp(trim($mods[0]), 'unixepoch') === 0) {
            if ($v === null || !is_numeric($v)) {
                return null;
            }
            $d = (new \DateTimeImmutable('@' . Valor::entero(floor((float)$v))))->modify(\sprintf('+%d microseconds', (int)round(fmod((float)$v, 1) * 1e6)));
            return self::modificar($d, \array_slice($mods, 1));
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
    protected static function modificar(?\DateTimeImmutable $d, array $mods): ?\DateTimeImmutable
    {
        foreach ($mods as $m) {
            if ($d === null || $m === null) {
                return null;
            }
            $texto = strtolower(trim(Valor::aTexto($m)));
            if (preg_match('/^([+-]?)(\d+(?:\.\d+)?)\s*(second|minute|hour|day|month|year)s?$/', $texto, $p)) {
                $n = (float)$p[2] * ($p[1] === '-' ? -1 : 1);
                if ($p[3] === 'month' || $p[3] === 'year') {
                    if ($n != Valor::entero($n)) {
                        throw JsonSqlDbError::syntax("Modificador de fecha no soportado: '$texto' (los meses y los años, enteros)");
                    }
                    $d = $d->modify(\sprintf('%+d %s', Valor::entero($n), $p[3]));
                } else {
                    // En milisegundos, como SQLite, para admitir '+1.5 days' (2.9:
                    // en segundos, y '+0.5 seconds' sumaba uno entero)
                    $ms = Valor::entero(round($n * ['second' => 1000, 'minute' => 60000, 'hour' => 3600000, 'day' => 86400000][$p[3]]));
                    $d  = $d->modify(\sprintf('%+d seconds', intdiv($ms, 1000)));
                    if ($ms % 1000 !== 0) {
                        $d = $d->modify(\sprintf('%+d microseconds', $ms % 1000 * 1000));
                    }
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
        // Una hora sola es del 1 de enero de 2000, como en SQLite (2.10:
        // TIME('12:00:00') daba NULL)
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/', $s, $m)) {
            if ((int)$m[1] > 23 || (int)$m[2] > 59 || (int)($m[3] ?? 0) > 59) {
                return null;
            }
            $ms = str_pad(substr($m[4] ?? '', 0, 3), 3, '0');
            return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', "2000-01-01 $m[1]:$m[2]:" . ($m[3] ?? '00') . ".$ms") ?: null;
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

    /** Un valor como texto JSON, compacto como el de SQLite y MySQL */
    public static function aJson($v): string
    {
        $t = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($t === false) {
            throw JsonSqlDbError::type('No se puede escribir como JSON: tiene bytes que no son texto (RANDOMBLOB, ZEROBLOB…)');
        }
        return $t;
    }

    /**
     * Especificadores de fecha de strftime() de SQLite, como el formato de
     * DateTime de PHP que les toca; '#j' es el día del año en tres cifras, '#s'
     * los segundos desde 1970 y '#W' la semana del año de SQLite (ver
     * formatearFecha(); los de MySQL, en FunctionsAmpliadas::FECHA_MYSQL).
     */
    private const FECHA_SQLITE = ['Y' => 'Y', 'm' => 'm', 'd' => 'd', 'H' => 'H', 'M' => 'i', 'S' => 's', 'f' => 's.v',
                                  'j' => '#j', 'w' => 'w', 'W' => '#W', 's' => '#s'];

    /**
     * Una fecha con un formato de especificadores %x. Uno que no está en el
     * mapa se deja tal cual (strftime de SQLite) o, con $funcion, es un error
     * (DATE_FORMAT de MySQL); los nombres de días y meses, en inglés.
     *
     * @param array<string, string> $mapa ver FECHA_SQLITE y FunctionsAmpliadas::FECHA_MYSQL
     */
    protected static function formatearFecha(string $formato, \DateTimeImmutable $d, array $mapa, ?string $funcion = null,
                                           ?callable $segundos = null): string
    {
        return (string)preg_replace_callback('/%(.)/s', static function (array $m) use ($d, $mapa, $funcion, $segundos): string {
            $codigo = $m[1] === '%' ? '%' : ($mapa[$m[1]] ?? null);
            if ($codigo === null) {
                if ($funcion !== null) {
                    throw JsonSqlDbError::syntax("$funcion: especificador no soportado: %{$m[1]}");
                }
                return $m[0];
            }
            if ($codigo === '%') { return '%'; }
            if ($codigo === '#j') { return str_pad((string)((int)$d->format('z') + 1), 3, '0', STR_PAD_LEFT); }
            // %W de SQLite: la semana 01 empieza el primer lunes del año, y los
            // días de antes son la 00 (2.9: era la semana ISO, otra cosa)
            if ($codigo === '#W') { return \sprintf('%02d', intdiv((int)$d->format('z') + 8 - (int)$d->format('N'), 7)); }
            if ($codigo === '#s') { return (string)($segundos === null ? $d->getTimestamp() : $segundos()); }
            return $d->format($codigo);
        }, $formato);
    }

    protected static function exige(string $nombre, array $args, int $min, ?int $max = null): void
    {
        $n = \count($args);
        $max ??= $min;
        if ($n < $min || $n > $max) {
            $esperado = $min === $max ? "$min" : "entre $min y $max";
            throw JsonSqlDbError::syntax("$nombre() espera $esperado argumento(s) y ha recibido $n");
        }
    }
}
