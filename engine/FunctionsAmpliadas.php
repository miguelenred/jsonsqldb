<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las funciones que no son las de siempre de SQLite (ver Functions): las
 * demás de SQLite y las de MySQL/MariaDB (2.9), con lo que solo ellas usan
 * (JSON, fechas de MySQL, printf). Van aparte para que una consulta con
 * UPPER() o COUNT() no tenga que compilarlas: sin OPcache, PHP compila en
 * cada petición lo que carga. Hereda de Functions solo para llegar a lo
 * protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class FunctionsAmpliadas extends Functions
{
    /** Unidades de INTERVAL que van por meses, y cuántos son */
    private const MESES = ['MONTH' => 1, 'QUARTER' => 3, 'YEAR' => 12];
    /** Las demás, en microsegundos */
    private const MICROSEGUNDOS = ['MICROSECOND' => 1, 'SECOND' => 1000000, 'MINUTE' => 60000000, 'HOUR' => 3600000000,
                                   'DAY' => 86400000000, 'WEEK' => 604800000000];

    /** Especificadores de DATE_FORMAT() de MySQL (ver Functions::formatearFecha() y FECHA_SQLITE) */
    private const FECHA_MYSQL = ['Y' => 'Y', 'y' => 'y', 'm' => 'm', 'c' => 'n', 'd' => 'd', 'e' => 'j', 'D' => 'jS',
                                 'H' => 'H', 'k' => 'G', 'h' => 'h', 'I' => 'h', 'l' => 'g', 'i' => 'i', 's' => 's', 'S' => 's',
                                 'f' => 'u', 'p' => 'A', 'T' => 'H:i:s', 'r' => 'h:i:s A', 'W' => 'l', 'a' => 'D', 'M' => 'F',
                                 'b' => 'M', 'j' => '#j', 'w' => 'w'];

    private function __construct()
    {
    }

    /**
     * Funciones de SQLite y de MySQL/MariaDB para quien viene de ellas (2.9).
     * Donde las dos coinciden, como en las dos; donde dan cosas distintas con
     * los mismos argumentos (LOG(x), HEX(número)), un error que lo explica en
     * vez de un resultado que una de las dos no esperaría.
     */
    protected static function compatibles(string $nombre, array $args)
    {
        switch ($nombre) {
            // ---------- Números ----------
            case 'CEIL':
            case 'CEILING':
            case 'FLOOR':
            case 'TRUNC':
                // Como en SQLite: un entero sigue entero; un decimal, decimal
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                if ($n === null || \is_int($n)) {
                    return $n;
                }
                return $nombre === 'FLOOR' ? floor($n) : ($nombre === 'TRUNC' ? (float)($n < 0 ? ceil($n) : floor($n)) : ceil($n));
            case 'TRUNCATE':
                // TRUNCATE(x, d) de MySQL: corta a d decimales, sin redondear
                self::exige($nombre, $args, 2);
                $n = self::numero($args[0]);
                $d = self::numero($args[1]);
                if ($n === null || $d === null) { return null; }
                $d = Valor::entero($d);
                if (\is_int($n) && $d >= 0) { return $n; }
                $f = 10 ** $d;
                $x = $n * $f;
                // Con muchos decimales no hay nada que cortar; con muchas cifras
                // de menos, 10 ** d es 0 y no queda nada: cero (2.9: dividía por
                // cero, o daba NAN)
                $r = !is_finite($x) ? $n : ($f == 0 ? 0 : ($x < 0 ? ceil($x) : floor($x)) / $f);
                return \is_int($n) ? Valor::entero($r) : (float)$r;
            case 'SIGN':
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                return $n === null ? null : ($n > 0 ? 1 : ($n < 0 ? -1 : 0));
            case 'MOD':
                // Con dos enteros, como el operador %; con decimales, el resto real
                self::exige($nombre, $args, 2);
                $a = self::numero($args[0]);
                $b = self::numero($args[1]);
                if ($a === null || $b === null || $b == 0) { return null; }
                // fmod(INF, 2) es NaN: NULL, como en SQLite (2.10)
                return \is_int($a) && \is_int($b) ? $a % $b : (($r = fmod((float)$a, (float)$b)) === $r ? $r : null);
            case 'POW':
            case 'POWER':
                self::exige($nombre, $args, 2);
                $a = self::numero($args[0]);
                $b = self::numero($args[1]);
                return $a === null || $b === null ? null : self::real(((float)$a) ** ((float)$b));
            case 'SQRT':
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                return $n === null || $n < 0 ? null : sqrt((float)$n);
            case 'EXP':
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                return $n === null ? null : self::real(exp((float)$n));
            case 'LN':
            case 'LOG10':
            case 'LOG2':
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                if ($n === null || $n <= 0) { return null; }
                return $nombre === 'LN' ? log((float)$n) : ($nombre === 'LOG10' ? log10((float)$n) : log((float)$n, 2));
            case 'LOG':
                if (\count($args) === 1) {
                    throw JsonSqlDbError::syntax('LOG(x) con un solo argumento es en base 10 en SQLite y el logaritmo natural en MySQL: usa LOG10(x) o LN(x), o LOG(base, x)');
                }
                self::exige($nombre, $args, 2);
                $b = self::numero($args[0]);
                $n = self::numero($args[1]);
                if ($b === null || $n === null || $b <= 0 || $b == 1 || $n <= 0) { return null; }
                return ($r = log((float)$n) / log((float)$b)) === $r ? $r : null;      // LOG(INF, INF) es NaN: NULL (2.10)
            case 'PI':
                self::exige($nombre, $args, 0);
                return M_PI;
            case 'GREATEST':
            case 'LEAST':
                // Como MIN/MAX con varios argumentos: NULL si alguno es NULL
                if (\count($args) < 2) {
                    throw JsonSqlDbError::syntax("$nombre() necesita al menos 2 argumentos");
                }
                return self::escalar($nombre === 'GREATEST' ? 'MAX' : 'MIN', $args);
            case 'RAND':
                // Decimal entre 0 y 1, como en MySQL. Con semilla, la misma serie
                // cada vez, pero no la misma que en MySQL
                self::exige($nombre, $args, 0, 1);
                if ($args !== [] && $args[0] !== null) {
                    mt_srand(Valor::entero(Valor::aNumero($args[0])));
                }
                return mt_rand() / (mt_getrandmax() + 1);
            case 'FORMAT':
            case 'PRINTF':
                return self::formato($nombre, $args);

            // ---------- Texto ----------
            case 'UCASE':
                return self::escalar('UPPER', $args);
            case 'LCASE':
                return self::escalar('LOWER', $args);
            case 'CHAR_LENGTH':
            case 'CHARACTER_LENGTH':
                return self::escalar('LENGTH', $args);
            case 'MID':
                return self::substr($args);
            case 'LEFT':
            case 'RIGHT':
                self::exige($nombre, $args, 2);
                if ($args[0] === null || $args[1] === null) { return null; }
                $s = Valor::aTexto($args[0]);
                $n = max(0, Valor::entero(Valor::aNumero($args[1])));
                $largo = Types::longitud($s);
                return $nombre === 'LEFT' ? self::corte($s, 0, $n) : self::corte($s, max(0, $largo - $n), null);
            case 'LPAD':
            case 'RPAD':
                // Como MySQL: si el texto ya es más largo, se corta a esa longitud
                self::exige($nombre, $args, 3);
                if ($args[0] === null || $args[1] === null || $args[2] === null) { return null; }
                $s = Valor::aTexto($args[0]);
                $n = Valor::entero(Valor::aNumero($args[1]));
                $relleno = Valor::aTexto($args[2]);
                if ($n < 0) { return null; }
                self::exigirTope($nombre, $n);
                $largo = Types::longitud($s);
                if ($largo >= $n) { return self::corte($s, 0, $n); }
                if ($relleno === '') { return null; }
                $falta = $n - $largo;
                $pieza = self::corte(str_repeat($relleno, (int)ceil($falta / max(1, Types::longitud($relleno)))), 0, $falta);
                return $nombre === 'LPAD' ? $pieza . $s : $s . $pieza;
            case 'REVERSE':
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                return implode('', array_reverse((array)preg_split('//u', Valor::aTexto($args[0]), -1, PREG_SPLIT_NO_EMPTY)));
            case 'REPEAT':
            case 'SPACE':
                self::exige($nombre, $args, $nombre === 'SPACE' ? 1 : 2);
                $s = $nombre === 'SPACE' ? ' ' : ($args[0] === null ? null : Valor::aTexto($args[0]));
                $v = $args[$nombre === 'SPACE' ? 0 : 1];
                if ($s === null || $v === null) { return null; }
                $n = max(0, Valor::entero(Valor::aNumero($v)));
                self::exigirTope($nombre, \strlen($s) * $n);   // puede pasar de 64 bits: un decimal (2.9: TypeError)
                return str_repeat($s, $n);
            case 'CONCAT_WS':
                // Separador y después los valores, saltándose los NULL (los dos igual)
                if (\count($args) < 2) {
                    throw JsonSqlDbError::syntax('CONCAT_WS() necesita el separador y al menos un valor');
                }
                if ($args[0] === null) { return null; }
                $partes = [];
                foreach (\array_slice($args, 1) as $a) {
                    if ($a !== null) { $partes[] = Valor::aTexto($a); }
                }
                return implode(Valor::aTexto($args[0]), $partes);
            case 'LOCATE':
                // LOCATE(buscado, texto [, desde]) de MySQL: INSTR con los
                // argumentos al revés, y distinguiendo mayúsculas como él
                self::exige($nombre, $args, 2, 3);
                if ($args[0] === null || $args[1] === null || (isset($args[2]) && $args[2] === null)) { return null; }
                $desde = isset($args[2]) ? Valor::entero(Valor::aNumero($args[2])) : 1;
                if ($desde < 1) { return 0; }
                $texto = self::corte(Valor::aTexto($args[1]), $desde - 1, null);
                $pos = self::escalar('INSTR', [$texto, $args[0]]);
                return $pos === 0 ? 0 : $pos + $desde - 1;
            case 'SUBSTRING_INDEX':
                self::exige($nombre, $args, 3);
                if ($args[0] === null || $args[1] === null || $args[2] === null) { return null; }
                $s = Valor::aTexto($args[0]);
                $sep = Valor::aTexto($args[1]);
                $n = Valor::entero(Valor::aNumero($args[2]));
                if ($sep === '' || $n === 0) { return ''; }
                $trozos = explode($sep, $s);
                return implode($sep, $n > 0 ? \array_slice($trozos, 0, $n) : \array_slice($trozos, max(0, \count($trozos) + $n)));
            case 'CHAR':
                // Como SQLite: los caracteres de esos códigos Unicode
                $s = '';
                foreach ($args as $a) {
                    if ($a !== null) { $s .= self::caracter(Valor::entero(Valor::aNumero($a))); }
                }
                return $s;
            case 'UNICODE':
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                $s = Valor::aTexto($args[0]);
                if ($s === '') { return null; }
                $c = (array)preg_split('//u', $s, 2, PREG_SPLIT_NO_EMPTY);
                return self::codigo((string)$c[0]);
            case 'ASCII':
                // Como MySQL: el valor del primer byte
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                $s = Valor::aTexto($args[0]);
                return $s === '' ? 0 : \ord($s[0]);
            case 'HEX':
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                if (\is_int($args[0]) || \is_float($args[0])) {
                    throw JsonSqlDbError::syntax('HEX() de un número da cosas distintas en SQLite (los bytes del texto) y en MySQL (el número en hexadecimal): usa PRINTF(\'%X\', n) o HEX(CAST(n AS TEXT))');
                }
                return strtoupper(bin2hex(Valor::aTexto($args[0])));
            case 'FIELD':
                // FIELD(x, a, b, …) de MySQL: la posición de x en la lista, o 0
                if (\count($args) < 2) {
                    throw JsonSqlDbError::syntax('FIELD() necesita el valor y al menos uno con el que compararlo');
                }
                if ($args[0] === null) { return 0; }
                foreach (\array_slice($args, 1) as $i => $a) {
                    if ($a !== null && Valor::comparar($args[0], $a) === 0) { return $i + 1; }
                }
                return 0;
            case 'ELT':
                if (\count($args) < 2) {
                    throw JsonSqlDbError::syntax('ELT() necesita el número y al menos un valor');
                }
                $n = $args[0] === null ? 0 : Valor::entero(Valor::aNumero($args[0]));
                return $n >= 1 && $n < \count($args) ? $args[$n] : null;

            // ---------- Condiciones ----------
            case 'IF':
            case 'IIF':
                self::exige($nombre, $args, 3);
                return Valor::verdadero($args[0]) === true ? $args[1] : $args[2];
            case 'ISNULL':
                self::exige($nombre, $args, 1);
                return $args[0] === null ? 1 : 0;
            case 'TYPEOF':
                self::exige($nombre, $args, 1);
                $v = $args[0];
                return $v === null ? 'null' : (\is_int($v) || \is_bool($v) ? 'integer' : (\is_float($v) ? 'real' : 'text'));

            // ---------- Fechas ----------
            case 'YEAR':
            case 'MONTH':
            case 'DAY':
            case 'DAYOFMONTH':
            case 'DAYOFWEEK':
            case 'WEEKDAY':
            case 'DAYOFYEAR':
            case 'QUARTER':
            case 'DAYNAME':
            case 'MONTHNAME':
            case 'LAST_DAY':
                self::exige($nombre, $args, 1);
                $d = self::aFecha($args[0]);
                if ($d === null) { return null; }
                switch ($nombre) {
                    case 'YEAR':       return (int)$d->format('Y');
                    case 'MONTH':      return (int)$d->format('n');
                    case 'DAY':
                    case 'DAYOFMONTH': return (int)$d->format('j');
                    case 'DAYOFWEEK':  return (int)$d->format('w') + 1;        // 1 = domingo
                    case 'WEEKDAY':    return (int)$d->format('N') - 1;        // 0 = lunes
                    case 'DAYOFYEAR':  return (int)$d->format('z') + 1;
                    case 'QUARTER':    return intdiv((int)$d->format('n') - 1, 3) + 1;
                    case 'DAYNAME':    return $d->format('l');                 // en inglés, como MySQL
                    case 'MONTHNAME':  return $d->format('F');
                    default:           return $d->format('Y-m-t');             // LAST_DAY
                }
            case 'HOUR':
            case 'MINUTE':
            case 'SECOND':
                // También de una hora sola ('10:30:00'), como en MySQL
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                $t = trim(Valor::aTexto($args[0]));
                if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?$/', $t, $m)) {
                    return (int)($nombre === 'HOUR' ? $m[1] : ($nombre === 'MINUTE' ? $m[2] : ($m[3] ?? 0)));
                }
                $d = self::aFecha($args[0]);
                return $d === null ? null : (int)$d->format($nombre === 'HOUR' ? 'G' : ($nombre === 'MINUTE' ? 'i' : 's'));
            case 'DATEDIFF':
                // Días entre dos fechas (la primera menos la segunda), sin la hora
                self::exige($nombre, $args, 2);
                $a = self::aFecha($args[0]);
                $b = self::aFecha($args[1]);
                if ($a === null || $b === null) { return null; }
                return (int)(new \DateTimeImmutable($a->format('Y-m-d'), new \DateTimeZone('UTC')))
                    ->diff(new \DateTimeImmutable($b->format('Y-m-d'), new \DateTimeZone('UTC')))->format('%r%a') * -1;
            case 'UNIX_TIMESTAMP':
                // Como MySQL: la fecha en la zona horaria del servidor
                self::exige($nombre, $args, 0, 1);
                if ($args === []) { return time(); }
                $d = self::aFecha($args[0]);
                return $d === null ? null : $d->getTimestamp();
            case 'FROM_UNIXTIME':
                self::exige($nombre, $args, 1);
                if ($args[0] === null || !is_numeric($args[0])) { return null; }
                return date('Y-m-d H:i:s', Valor::entero(floor((float)$args[0])));
            case 'UNIXEPOCH':
            case 'JULIANDAY':
                // Como SQLite: la fecha se toma como UTC, y admite modificadores
                $v = $args === [] ? 'now' : $args[0];
                // 'now' es el momento de ahora en UTC; una fecha escrita, sus cifras en UTC
                $d = \is_string($v) && strcasecmp(trim($v), 'now') === 0
                    ? self::modificar(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), \array_slice($args, 1))
                    : self::conModificadores($v, \array_slice($args, 1));
                // Pasada del año 9999, NULL, como SQLite (2.9: se volvía a leer el
                // texto de la fecha, y uno de cinco cifras era una excepción de PHP)
                if ($d === null || (int)$d->format('Y') > 9999) { return null; }
                // Sus cifras como UTC: el instante más lo que su zona se adelanta a UTC
                $seg = $d->getTimestamp() + $d->getOffset();
                return $nombre === 'UNIXEPOCH' ? $seg : ($seg + (int)$d->format('u') / 1e6) / 86400 + 2440587.5;
            case 'JSON_EXTRACT':
                // json_extract(json, ruta, …) de SQLite: con una ruta, el valor
                // (un objeto o una lista, como texto JSON; true y false, 1 y 0);
                // con varias, una lista JSON con cada uno
                if (\count($args) < 2) {
                    self::exige($nombre, $args, 2);
                }
                if ($args[0] === null) { return null; }
                $doc = json_decode(Valor::aTexto($args[0]), false);
                if ($doc === null && strtolower(trim(Valor::aTexto($args[0]))) !== 'null') {
                    throw JsonSqlDbError::type('json_extract: JSON mal formado');
                }
                $valores = [];
                foreach (\array_slice($args, 1) as $ruta) {
                    $valores[] = $ruta === null ? null : self::rutaJson($doc, Valor::aTexto($ruta));
                }
                if (\count($valores) > 1) {
                    return json_encode($valores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                }
                $v = $valores[0];
                return \is_bool($v) ? (int)$v : (\is_array($v) || \is_object($v)
                    ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) : $v);
            case 'COLLATE':
                // x COLLATE … : el valor es el mismo (ver Parser::plegar())
                return $args[0];
            case 'PLEGAR':
                return self::plegar($args[0], (string)$args[1]);
            case 'DATE_ADD':
            case 'DATE_SUB':
                // DATE_ADD(f, n, unidad), de f + INTERVAL n unidad (ver Parser):
                // como MySQL, sumar meses no se pasa de fin de mes (31 de enero
                // + 1 mes = 28 de febrero), y una fecha sin hora sigue sin hora
                // si la unidad es de días o más
                self::exige($nombre, $args, 3);
                if ($args[0] === null || $args[1] === null) { return null; }
                $d = self::aFecha($args[0]);
                if ($d === null) { return null; }
                $unidad = (string)$args[2];
                $n = Valor::aNumero($args[1]);
                $n = $nombre === 'DATE_SUB' ? -$n : $n;
                $soloFecha = !preg_match('/\d:\d/', Valor::aTexto($args[0]))
                    && \in_array($unidad, ['DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR'], true);
                if (isset(self::MESES[$unidad])) {
                    $meses = Valor::entero($n) * self::MESES[$unidad];
                    $total = (int)$d->format('Y') * 12 + (int)$d->format('n') - 1 + $meses;
                    // Fuera de los años 0 a 9999, NULL, como MySQL (2.9: la fecha
                    // del día 1 se escribía y se volvía a leer, y un año de más de
                    // cuatro cifras era una excepción de PHP)
                    if ($total < 0 || $total >= 120000) { return null; }
                    $anio  = intdiv($total, 12);
                    $mes   = $total % 12 + 1;
                    $dia   = min((int)$d->format('j'), (int)$d->setDate($anio, $mes, 1)->format('t'));
                    $d = $d->setDate($anio, $mes, $dia);
                } else {
                    if (!isset(self::MICROSEGUNDOS[$unidad])) { return null; }
                    // En días y semanas, por el calendario: un día de cambio de hora tiene 23 o 25 horas
                    if ($unidad === 'DAY' || $unidad === 'WEEK') {
                        $d = $d->modify(\sprintf('%+d days', Valor::entero($n) * ($unidad === 'WEEK' ? 7 : 1)));
                    } else {
                        $micro = Valor::entero(round($n * self::MICROSEGUNDOS[$unidad]));
                        $d = $d->modify(\sprintf('%+d seconds', intdiv($micro, 1000000)))->modify(\sprintf('%+d microseconds', $micro % 1000000));
                    }
                }
                // Con milisegundos si los hay, como guarda las fechas el motor
                // (2.9: con seis cifras, que ni él mismo leía después). Un año
                // que no son cuatro cifras (pasado el 9999, o antes del 0), NULL
                $r = $d->format($soloFecha ? 'Y-m-d' : ($d->format('v') !== '000' ? 'Y-m-d H:i:s.v' : 'Y-m-d H:i:s'));
                return $r[4] === '-' ? $r : null;
            case 'TIMESTAMPDIFF':
                // TIMESTAMPDIFF(unidad, a, b): b - a en esa unidad, sin decimales
                self::exige($nombre, $args, 3);
                $a = self::aFecha($args[1]);
                $b = self::aFecha($args[2]);
                if ($a === null || $b === null) { return null; }
                $unidad = (string)$args[0];
                if (isset(self::MESES[$unidad])) {
                    $meses = ((int)$b->format('Y') - (int)$a->format('Y')) * 12 + (int)$b->format('n') - (int)$a->format('n');
                    $restoA = $a->format('dHis.u');
                    $restoB = $b->format('dHis.u');
                    if ($meses > 0 && $restoB < $restoA) { $meses--; }
                    if ($meses < 0 && $restoB > $restoA) { $meses++; }
                    return intdiv($meses, self::MESES[$unidad]);
                }
                $us = ((int)$b->format('U') - (int)$a->format('U')) * 1000000 + (int)$b->format('u') - (int)$a->format('u');
                return isset(self::MICROSEGUNDOS[$unidad]) ? intdiv($us, self::MICROSEGUNDOS[$unidad]) : null;
            case 'STR_TO_DATE':
                self::exige($nombre, $args, 2);
                return $args[0] === null || $args[1] === null ? null : self::leerFecha(Valor::aTexto($args[0]), Valor::aTexto($args[1]));
            case 'DATE_FORMAT':
                self::exige($nombre, $args, 2);
                $d = self::aFecha($args[0]);
                return $d === null || $args[1] === null ? null : self::formatearFecha(Valor::aTexto($args[1]), $d, self::FECHA_MYSQL, 'DATE_FORMAT');

            // ---------- Lo que depende de la conexión ----------
            case 'LAST_INSERT_ID':
            case 'LAST_INSERT_ROWID':
            case 'CHANGES':
            case 'ROW_COUNT':
                throw JsonSqlDbError::syntax("$nombre() no existe aquí: por la API cada petición es independiente. "
                    . 'Para saber lo que se ha insertado o cambiado, usa RETURNING (INSERT … RETURNING id)');
        }

        return self::masCompatibles($nombre, $args);
    }

    /**
     * Más funciones habituales de SQLite y de MySQL (2.9, segunda tanda). Donde
     * las dos chocan, TIMEDIFF va como en MySQL (decisión del proyecto);
     * RANDOMBLOB y ZEROBLOB dan bytes, que valen dentro de una expresión
     * (HEX(RANDOMBLOB(16))) pero no se pueden guardar en una tabla.
     */
    private static function masCompatibles(string $nombre, array $args)
    {
        switch ($nombre) {
            // ---------- Matemáticas ----------
            case 'ACOS': case 'ASIN': case 'COS': case 'SIN': case 'TAN': case 'COT':
            case 'DEGREES': case 'RADIANS':
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                if ($n === null) { return null; }
                $f = (float)$n;
                return self::real(match ($nombre) {
                    'ACOS' => acos($f), 'ASIN' => asin($f), 'COS' => cos($f), 'SIN' => sin($f), 'TAN' => tan($f),
                    'COT' => $f == 0 ? NAN : 1 / tan($f), 'DEGREES' => rad2deg($f), default => deg2rad($f),
                });
            case 'ATAN':
            case 'ATAN2':
                // ATAN(x), y ATAN(y, x) de MySQL, que es ATAN2(y, x)
                self::exige($nombre, $args, $nombre === 'ATAN2' ? 2 : 1, 2);
                $y = self::numero($args[0]);
                $x = isset($args[1]) || \count($args) === 2 ? self::numero($args[1] ?? null) : null;
                if ($y === null || (\count($args) === 2 && $x === null)) { return null; }
                return self::real(\count($args) === 2 ? atan2((float)$y, (float)$x) : atan((float)$y));
            case 'BIT_COUNT':
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                return $n === null ? null : substr_count(decbin(Valor::entero($n)), '1');
            case 'BIN':
            case 'OCT':
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                if ($n === null) { return null; }
                return $nombre === 'BIN' ? decbin(Valor::entero($n)) : decoct(Valor::entero($n));
            case 'CONV':
                // CONV(n, de, a) de MySQL: entre bases de 2 a 36
                self::exige($nombre, $args, 3);
                if (\in_array(null, $args, true)) { return null; }
                $de = abs(Valor::entero(Valor::aNumero($args[1])));
                $a  = abs(Valor::entero(Valor::aNumero($args[2])));
                if ($de < 2 || $de > 36 || $a < 2 || $a > 36) { return null; }
                return strtoupper(base_convert(strtolower(Valor::aTexto($args[0])), $de, $a));

            // ---------- Textos ----------
            case 'OCTET_LENGTH':
            case 'BIT_LENGTH':
                self::exige($nombre, $args, 1);
                return $args[0] === null ? null : \strlen(Valor::aTexto($args[0])) * ($nombre === 'BIT_LENGTH' ? 8 : 1);
            case 'QUOTE':
                // Como SQLite: un texto entre comillas simples, un número tal cual, NULL como texto
                self::exige($nombre, $args, 1);
                $v = $args[0];
                if ($v === null) { return 'NULL'; }
                if (\is_float($v)) { return self::decimalExacto($v); }      // sin perder cifras, como SQLite (2.10)
                return \is_int($v) ? (string)$v : "'" . str_replace("'", "''", Valor::aTexto($v)) . "'";
            case 'SOUNDEX':
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                $t = Valor::aTexto($args[0]);
                return preg_match('/[A-Za-z]/', $t) ? soundex($t) : '?000';     // sin letras, como SQLite
            case 'UNHEX':
                self::exige($nombre, $args, 1, 2);
                if ($args[0] === null) { return null; }
                $h = Valor::aTexto($args[0]);
                if (isset($args[1]) && $args[1] !== null) {
                    $h = str_replace(str_split(Valor::aTexto($args[1])), '', $h);    // los caracteres que se ignoran
                }
                return \strlen($h) % 2 === 0 && ctype_xdigit($h) || $h === '' ? (string)hex2bin($h) : null;
            case 'RANDOMBLOB':
            case 'ZEROBLOB':
                self::exige($nombre, $args, 1);
                $n = max(1, Valor::entero(Valor::aNumero($args[0] ?? 1)));
                self::exigirTope($nombre, $n);
                return $nombre === 'ZEROBLOB' ? str_repeat("\0", $n) : random_bytes($n);
            case 'LIKE':
            case 'GLOB':
                // like(patrón, texto[, escape]) y glob(patrón, texto): como los operadores
                self::exige($nombre, $args, 2, $nombre === 'LIKE' ? 3 : 2);
                if ($args[0] === null || $args[1] === null || \array_key_exists(2, $args) && $args[2] === null) { return null; }
                $regex = $nombre === 'LIKE'
                    ? EvaluatorPatrones::patronALike(Valor::aTexto($args[0]), isset($args[2]) ? Valor::aTexto($args[2]) : null)
                    : EvaluatorPatrones::patronAGlob(Valor::aTexto($args[0]));
                $r = preg_match($regex, $t = Valor::aTexto($args[1]));
                return ($r === false ? EvaluatorPatrones::casaSinRetroceso($regex, $t) : $r === 1) ? 1 : 0;
            case 'LIKELY':
            case 'UNLIKELY':
            case 'LIKELIHOOD':
                // Pistas para el planificador de SQLite: devuelven su argumento
                self::exige($nombre, $args, 1, $nombre === 'LIKELIHOOD' ? 2 : 1);
                return $args[0];
            case 'FIND_IN_SET':
                self::exige($nombre, $args, 2);
                if ($args[0] === null || $args[1] === null) { return null; }
                $i = array_search(Valor::aTexto($args[0]), explode(',', Valor::aTexto($args[1])), true);
                return $i === false ? 0 : $i + 1;
            case 'INSERT':
                // INSERT(texto, pos, largo, nuevo) de MySQL
                self::exige($nombre, $args, 4);
                if (\in_array(null, $args, true)) { return null; }
                $t   = (array)preg_split('//u', Valor::aTexto($args[0]), -1, PREG_SPLIT_NO_EMPTY);
                $pos = Valor::entero(Valor::aNumero($args[1]));
                $len = Valor::entero(Valor::aNumero($args[2]));
                if ($pos < 1 || $pos > \count($t)) { return Valor::aTexto($args[0]); }
                array_splice($t, $pos - 1, $len < 0 ? \count($t) : $len, [Valor::aTexto($args[3])]);
                return implode('', $t);
            case 'STRCMP':
                self::exige($nombre, $args, 2);
                // Como textos, byte a byte (2.9: dos textos numéricos se comparaban
                // como números, y STRCMP('10', '9') daba 1; en MySQL, -1)
                return $args[0] === null || $args[1] === null ? null : strcmp(Valor::aTexto($args[0]), Valor::aTexto($args[1])) <=> 0;
            case 'MD5':
            case 'SHA1':
            case 'CRC32':
            case 'TO_BASE64':
            case 'FROM_BASE64':
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                $t = Valor::aTexto($args[0]);
                if ($nombre === 'FROM_BASE64') {
                    $d = base64_decode($t, true);
                    return $d === false ? null : $d;
                }
                return match ($nombre) { 'MD5' => md5($t), 'SHA1' => sha1($t), 'CRC32' => crc32($t), default => base64_encode($t) };
            case 'SHA2':
                self::exige($nombre, $args, 2);
                if ($args[0] === null || $args[1] === null) { return null; }
                $bits = Valor::entero(Valor::aNumero($args[1]));
                $bits = $bits === 0 ? 256 : $bits;
                return \in_array($bits, [224, 256, 384, 512], true) ? hash('sha' . $bits, Valor::aTexto($args[0])) : null;
            case 'UUID':
                // Al azar (versión 4): el de MySQL es de la versión 1, con la hora y el equipo
                self::exige($nombre, $args, 0, 0);
                $b = random_bytes(16);
                $b[6] = \chr(\ord($b[6]) & 0x0f | 0x40);
                $b[8] = \chr(\ord($b[8]) & 0x3f | 0x80);
                return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));

            // ---------- Fechas y horas ----------
            case 'UTC_DATE':
            case 'UTC_TIME':
            case 'UTC_TIMESTAMP':
                self::exige($nombre, $args, 0, 0);
                return gmdate(['UTC_DATE' => 'Y-m-d', 'UTC_TIME' => 'H:i:s'][$nombre] ?? 'Y-m-d H:i:s');
            case 'TIMESTAMP':
                // TIMESTAMP(fecha[, hora]) de MySQL
                self::exige($nombre, $args, 1, 2);
                $d = self::conModificadores($args[0], []);
                if ($d === null) { return null; }
                if (isset($args[1])) {
                    $seg = self::segundos($args[1]);
                    if ($seg === null) { return null; }
                    $d = $d->modify(($seg < 0 ? '-' : '+') . abs($seg) . ' seconds');
                }
                return $d->format('Y-m-d H:i:s');
            case 'TO_DAYS':
                self::exige($nombre, $args, 1);
                $d = self::conModificadores($args[0], []);
                return $d === null ? null : self::diaMysql((int)$d->format('Y'), (int)$d->format('n'), (int)$d->format('j'));
            case 'MAKEDATE':
                self::exige($nombre, $args, 2);
                if ($args[0] === null || $args[1] === null) { return null; }
                $dia  = Valor::entero(Valor::aNumero($args[1]));
                $anio = Valor::entero(Valor::aNumero($args[0]));
                // Un año fuera de 0 a 9999, o un resultado que se sale, NULL como
                // MySQL (2.9: la fecha se escribía y se volvía a leer, y un año
                // negativo era una excepción de PHP)
                if ($dia < 1 || $anio < 0 || $anio > 9999) { return null; }
                $d = (new \DateTimeImmutable('2000-01-01'))->setDate($anio, 1, 1)->modify('+' . ($dia - 1) . ' days');
                return (int)$d->format('Y') > 9999 ? null : $d->format('Y-m-d');
            case 'PERIOD_DIFF':
                // Meses entre dos periodos AAMM o AAAAMM
                self::exige($nombre, $args, 2);
                if ($args[0] === null || $args[1] === null) { return null; }
                return self::mesesPeriodo(Valor::entero(Valor::aNumero($args[0]))) - self::mesesPeriodo(Valor::entero(Valor::aNumero($args[1])));
            case 'SEC_TO_TIME':
                self::exige($nombre, $args, 1);
                $n = self::numero($args[0]);
                return $n === null ? null : self::hora(Valor::entero($n));
            case 'TIME_TO_SEC':
                self::exige($nombre, $args, 1);
                return $args[0] === null ? null : self::segundos($args[0]);
            case 'TIMEDIFF':
                // Como en MySQL (decisión del proyecto): a − b como hora, 'HH:MM:SS'
                self::exige($nombre, $args, 2);
                if ($args[0] === null || $args[1] === null || self::esHora($args[0]) !== self::esHora($args[1])) {
                    return null;                          // una hora con una fecha: NULL, como en MySQL
                }
                $a = self::instante($args[0]);
                $b = self::instante($args[1]);
                if ($a === null || $b === null) { return null; }
                $us = (int)round(($a - $b) * 1e6);            // en microsegundos: sin errores de coma flotante
                $h  = self::hora(intdiv($us, 1000000));
                if ($us < 0 && $h[0] !== '-') { $h = '-' . $h; }
                return $us % 1000000 === 0 ? $h : $h . \sprintf('.%06d', abs($us % 1000000));
            case 'TIME_FORMAT':
                self::exige($nombre, $args, 2);
                if ($args[0] === null || $args[1] === null) { return null; }
                $seg = self::segundos($args[0]);
                if ($seg === null) { return null; }
                $d = (new \DateTimeImmutable('2000-01-01 00:00:00'))->modify('+' . ($seg % 86400 + 86400) % 86400 . ' seconds');
                return self::formatearFecha(Valor::aTexto($args[1]), $d, self::FECHA_MYSQL, 'TIME_FORMAT');
            case 'WEEK':
            case 'YEARWEEK':
                self::exige($nombre, $args, 1, 2);
                $d = self::conModificadores($args[0], []);
                if ($d === null) { return null; }
                $modo = isset($args[1]) && $args[1] !== null ? Valor::entero(Valor::aNumero($args[1])) & 7 : 0;
                [$semana, $anio] = self::semanaMysql($d, $modo, $nombre === 'YEARWEEK');
                return $nombre === 'YEARWEEK' ? $anio * 100 + $semana : $semana;
            case 'WEEKOFYEAR':
                self::exige($nombre, $args, 1);
                $d = self::conModificadores($args[0], []);
                return $d === null ? null : (int)$d->format('W');

            // ---------- JSON ----------
            case '->':
            case '->>':
                // x -> ruta: el trozo como JSON; x ->> ruta: como valor SQL. La ruta
                // puede ir abreviada, como en SQLite: 'a' es '$.a' y 2 es '$[2]'
                // Un número negativo o con decimales no señala nada: NULL, como
                // en SQLite (2.10: un error de ruta no válida)
                if ($args[0] === null || $args[1] === null || \is_float($args[1]) || \is_int($args[1]) && $args[1] < 0) { return null; }
                $ruta = \is_int($args[1]) ? '$[' . $args[1] . ']' : Valor::aTexto($args[1]);
                $v = self::rutaJson(self::docJson($args[0], 'json'), $ruta[0] === '$' ? $ruta : '$.' . $ruta, $existe);
                if (!$existe) { return null; }
                if ($nombre === '->') { return self::aJson($v); }
                return \is_bool($v) ? (int)$v : (\is_array($v) || $v instanceof \stdClass ? self::aJson($v) : $v);
            case 'JSON':
            case 'JSON_VALID':
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                $doc = json_decode(Valor::aTexto($args[0]), false);
                $valido = $doc !== null || strtolower(trim(Valor::aTexto($args[0]))) === 'null';
                if ($nombre === 'JSON_VALID') { return $valido ? 1 : 0; }
                if (!$valido) { throw JsonSqlDbError::type('json: JSON mal formado'); }
                return self::aJson($doc);
            case 'JSON_ARRAY':
                return self::aJson(array_map(static fn($v) => $v, $args));
            case 'JSON_OBJECT':
                if (\count($args) % 2 !== 0) {
                    throw JsonSqlDbError::syntax('json_object() necesita parejas de clave y valor');
                }
                $o = new \stdClass();
                for ($i = 0; $i < \count($args); $i += 2) {
                    if ($args[$i] === null) { throw JsonSqlDbError::type('json_object(): una clave no puede ser NULL'); }
                    $o->{Valor::aTexto($args[$i])} = $args[$i + 1];
                }
                return self::aJson($o);
            case 'JSON_TYPE':
            case 'JSON_ARRAY_LENGTH':
            case 'JSON_LENGTH':
            case 'JSON_KEYS':
                self::exige($nombre, $args, 1, 2);
                if ($args[0] === null) { return null; }
                $doc = self::docJson($args[0], $nombre);
                if (isset($args[1])) {
                    $doc = self::rutaJson($doc, Valor::aTexto($args[1]), $existe);
                    if (!$existe) { return null; }
                }
                return match ($nombre) {
                    'JSON_TYPE'         => match (true) {
                        $doc === null => 'null', $doc === true => 'true', $doc === false => 'false', \is_int($doc) => 'integer',
                        \is_float($doc) => 'real', \is_string($doc) => 'text', \is_array($doc) => 'array', default => 'object' },
                    'JSON_ARRAY_LENGTH' => \is_array($doc) ? \count($doc) : 0,
                    'JSON_LENGTH'       => \is_array($doc) ? \count($doc) : ($doc instanceof \stdClass ? \count(get_object_vars($doc)) : 1),
                    default             => $doc instanceof \stdClass ? self::aJson(array_map('strval', array_keys(get_object_vars($doc)))) : null,
                };
            case 'JSON_UNQUOTE':
                self::exige($nombre, $args, 1);
                if ($args[0] === null) { return null; }
                $t = Valor::aTexto($args[0]);
                $d = \strlen($t) >= 2 && $t[0] === '"' ? json_decode($t) : null;
                return \is_string($d) ? $d : $t;
            case 'JSON_CONTAINS':
                self::exige($nombre, $args, 2, 3);
                if ($args[0] === null || $args[1] === null) { return null; }
                $doc = self::docJson($args[0], $nombre);
                if (isset($args[2])) {
                    $doc = self::rutaJson($doc, Valor::aTexto($args[2]), $existe);
                    if (!$existe) { return null; }
                }
                return self::contieneJson($doc, self::docJson($args[1], $nombre)) ? 1 : 0;
            case 'JSON_SET':
            case 'JSON_INSERT':
            case 'JSON_REPLACE':
            case 'JSON_REMOVE':
                if (\count($args) < 2 || ($nombre !== 'JSON_REMOVE' && \count($args) % 2 === 0)) {
                    throw JsonSqlDbError::syntax(strtolower($nombre) . '() necesita el JSON y ' . ($nombre === 'JSON_REMOVE' ? 'una o más rutas' : 'parejas de ruta y valor'));
                }
                if ($args[0] === null) { return null; }
                $doc = self::docJson($args[0], $nombre);
                for ($i = 1; $i < \count($args); $i += $nombre === 'JSON_REMOVE' ? 1 : 2) {
                    $doc = self::ponerJson($doc, self::pasosJson(Valor::aTexto($args[$i])), $args[$i + 1] ?? null, $nombre);
                }
                return self::aJson($doc);
        }

        throw JsonSqlDbError::syntax("Función no soportada: $nombre()");
    }

    /**
     * Los pasos de una ruta JSON de SQLite y MySQL ($.a.b, $."con espacios",
     * $[2], $[#-1] para el último): ['k', clave] o ['i', índice, desde el final].
     *
     * @return list<array>
     */
    private static function pasosJson(string $ruta): array
    {
        // $[#] es la posición tras el último, para añadir (SQLite)
        if (!preg_match('/^\$((?:\.(?:"[^"]*"|[^.\[]+)|\[(?:#(?:-\d+)?|\d+)\])*)$/', $ruta, $m)) {
            throw JsonSqlDbError::type("La ruta de JSON no es válida: $ruta");
        }
        preg_match_all('/\.(?:"([^"]*)"|([^.\[]+))|\[(#)?(-?\d*)\]/', $m[1], $pasos, PREG_SET_ORDER);
        $out = [];
        foreach ($pasos as $p) {
            $out[] = ($p[3] ?? '') !== '' || ($p[4] ?? '') !== ''
                ? ['i', (int)($p[4] ?? 0), ($p[3] ?? '') === '#'] : ['k', ($p[1] ?? '') !== '' ? $p[1] : $p[2]];
        }
        return $out;
    }

    /**
     * El valor en una ruta de un JSON ya decodificado, o null si no está
     * ($existe lo distingue de un null que sí está).
     *
     * @param mixed $doc lo de json_decode() sin arrays asociativos: un objeto es stdClass
     * @return mixed
     */
    private static function rutaJson($doc, string $ruta, ?bool &$existe = null)
    {
        $existe = false;
        foreach (self::pasosJson($ruta) as $p) {
            if ($p[0] === 'i') {
                if (!\is_array($doc)) { return null; }
                $i = $p[1] + ($p[2] ? \count($doc) : 0);
                if ($i < 0 || !\array_key_exists($i, $doc)) { return null; }
                $doc = $doc[$i];
                continue;
            }
            if (!$doc instanceof \stdClass || !property_exists($doc, $p[1])) { return null; }
            $doc = $doc->{$p[1]};
        }
        $existe = true;
        return $doc;
    }

    /**
     * JSON_SET, JSON_INSERT, JSON_REPLACE y JSON_REMOVE en una ruta: SET pone
     * siempre, INSERT solo si no estaba, REPLACE solo si estaba, REMOVE quita.
     * Un índice más allá del final de una lista añade al final.
     *
     * @param mixed $doc
     * @param list<array> $pasos
     * @param mixed $valor
     * @return mixed
     */
    private static function ponerJson($doc, array $pasos, $valor, string $modo)
    {
        if ($pasos === []) {
            return \in_array($modo, ['JSON_SET', 'JSON_REPLACE'], true) ? $valor : $doc;
        }
        $p = array_shift($pasos);
        if ($p[0] === 'k') {
            if (!$doc instanceof \stdClass) { return $doc; }
            $doc = clone $doc;
            $hay = property_exists($doc, $p[1]);
            if ($pasos !== []) {
                if ($hay) { $doc->{$p[1]} = self::ponerJson($doc->{$p[1]}, $pasos, $valor, $modo); }
            } elseif ($modo === 'JSON_REMOVE') {
                unset($doc->{$p[1]});
            } elseif ($hay ? $modo !== 'JSON_INSERT' : $modo !== 'JSON_REPLACE') {
                $doc->{$p[1]} = $valor;
            }
            return $doc;
        }
        if (!\is_array($doc)) { return $doc; }
        $i   = $p[1] + ($p[2] ? \count($doc) : 0);
        $hay = $i >= 0 && $i < \count($doc);
        if ($pasos !== []) {
            if ($hay) { $doc[$i] = self::ponerJson($doc[$i], $pasos, $valor, $modo); }
        } elseif ($modo === 'JSON_REMOVE') {
            if ($hay) { array_splice($doc, $i, 1); }
        } elseif ($hay) {
            if ($modo !== 'JSON_INSERT') { $doc[$i] = $valor; }
        } elseif ($modo !== 'JSON_REPLACE' && $i >= \count($doc)) {
            $doc[] = $valor;
        }
        return $doc;
    }

    /** ¿Contiene $a a $b? Como JSON_CONTAINS de MySQL */
    private static function contieneJson($a, $b): bool
    {
        if (\is_array($a)) {
            if (\is_array($b)) {
                foreach ($b as $x) {
                    if (!self::contieneJson($a, $x)) { return false; }
                }
                return true;
            }
            foreach ($a as $x) {
                if (self::contieneJson($x, $b)) { return true; }
            }
            return false;
        }
        if ($a instanceof \stdClass) {
            if (!$b instanceof \stdClass) { return false; }
            foreach (get_object_vars($b) as $k => $v) {
                if (!property_exists($a, (string)$k) || !self::contieneJson($a->{$k}, $v)) { return false; }
            }
            return true;
        }
        if (\is_array($b) || $b instanceof \stdClass) { return false; }
        return (\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b)) ? $a == $b : $a === $b;
    }

    /** Un texto JSON decodificado (objetos como stdClass), o el error de $funcion */
    private static function docJson($v, string $funcion)
    {
        $doc = json_decode(Valor::aTexto($v), false);
        if ($doc === null && strtolower(trim(Valor::aTexto($v))) !== 'null') {
            throw JsonSqlDbError::type(strtolower($funcion) . ': JSON mal formado');
        }
        return $doc;
    }

    /** Una hora 'HH:MM:SS' de MySQL a segundos (también la hora de una fecha); null si no lo es */
    private static function segundos($v): ?int
    {
        $t = trim(Valor::aTexto($v));
        if (preg_match('/^(-)?(\d+):(\d{1,2})(?::(\d{1,2}))?(?:\.\d+)?$/', $t, $m)) {
            $s = (int)$m[2] * 3600 + (int)$m[3] * 60 + (int)($m[4] ?? 0);
            return $m[1] === '-' ? -$s : $s;
        }
        $d = self::conModificadores($v, []);
        return $d === null ? null : (int)$d->format('G') * 3600 + (int)$d->format('i') * 60 + (int)$d->format('s');
    }

    /** ¿Es una hora sola ('HH:MM[:SS]'), y no una fecha? */
    private static function esHora($v): bool
    {
        return (bool)preg_match('/^-?\d+:\d{1,2}(?::\d{1,2})?(?:\.\d+)?$/', trim(Valor::aTexto($v)));
    }

    /** Para TIMEDIFF: los segundos (con sus microsegundos) de una hora, o de una fecha desde 1970 */
    private static function instante($v): ?float
    {
        $t = trim(Valor::aTexto($v));
        $fraccion = preg_match('/\.(\d{1,6})$/', $t, $m) ? (float)('0.' . $m[1]) : 0.0;
        if (self::esHora($t)) {
            $s = self::segundos($t);
            return $s === null ? null : $s + ($s < 0 || $t[0] === '-' ? -$fraccion : $fraccion);
        }
        $d = self::conModificadores(preg_replace('/\.\d+$/', '', $t), []);
        return $d === null ? null : $d->getTimestamp() + $fraccion;
    }

    /** Segundos como hora de MySQL: '-HH:MM:SS', con horas de más de 24 */
    private static function hora(int $s): string
    {
        $a = abs($s);
        return ($s < 0 ? '-' : '') . \sprintf('%02d:%02d:%02d', intdiv($a, 3600), intdiv($a % 3600, 60), $a % 60);
    }

    /** Días desde el año 0, como TO_DAYS de MySQL (su calc_daynr) */
    private static function diaMysql(int $y, int $m, int $d): int
    {
        if ($y === 0 && $m === 0) { return 0; }
        $suma = 365 * $y + 31 * ($m - 1) + $d;
        if ($m <= 2) {
            $y--;
        } else {
            $suma -= intdiv($m * 4 + 23, 10);
        }
        return $suma + intdiv($y, 4) - intdiv((intdiv($y, 100) + 1) * 3, 4);
    }

    /** Un periodo AAMM o AAAAMM de MySQL en meses desde el año 0 */
    private static function mesesPeriodo(int $p): int
    {
        $y = intdiv($p, 100);
        if ($y < 100) {
            $y += $y < 70 ? 2000 : 1900;
        }
        return $y * 12 + $p % 100 - 1;
    }

    /**
     * La semana de una fecha según el modo de WEEK() de MySQL (0 a 7), y su
     * año (YEARWEEK): el cálculo de MySQL (calc_week), paso a paso.
     *
     * @return array{0: int, 1: int}
     */
    private static function semanaMysql(\DateTimeImmutable $d, int $modo, bool $conAnio): array
    {
        $modo = ($modo & 1) === 0 ? $modo ^ 4 : $modo;           // week_mode()
        if ($conAnio) {
            $modo |= 2;
        }
        [$lunes, $deAnio, $primerDia] = [($modo & 1) !== 0, ($modo & 2) !== 0, ($modo & 4) !== 0];
        $anio  = (int)$d->format('Y');
        $mes   = (int)$d->format('n');
        $dia   = (int)$d->format('j');
        $hoy   = self::diaMysql($anio, $mes, $dia);
        $uno   = self::diaMysql($anio, 1, 1);
        $diasDe = static fn(int $y): int => ($y & 3) === 0 && ($y % 100 !== 0 || $y % 400 === 0) ? 366 : 365;
        $semDia = ($uno + 5 + ($lunes ? 0 : 1)) % 7;
        if ($mes === 1 && $dia <= 7 - $semDia) {
            if (!$deAnio && (($primerDia && $semDia !== 0) || (!$primerDia && $semDia >= 4))) {
                return [0, $anio];
            }
            $deAnio = true;
            $anio--;
            $dias   = $diasDe($anio);
            $uno   -= $dias;
            $semDia = ($semDia + 53 * 7 - $dias) % 7;
        }
        $dias = ($primerDia && $semDia !== 0) || (!$primerDia && $semDia >= 4) ? $hoy - ($uno + (7 - $semDia)) : $hoy - ($uno - $semDia);
        if ($deAnio && $dias >= 52 * 7) {
            $semDia = ($semDia + $diasDe($anio)) % 7;
            if ((!$primerDia && $semDia < 4) || ($primerDia && $semDia === 0)) {
                return [1, $anio + 1];
            }
        }
        return [intdiv($dias, 7) + 1, $anio];
    }

    /** Un argumento como número, o null; un texto que no es número, null (como SQLite) */
    private static function numero($v)
    {
        if ($v === null || (\is_string($v) && !is_numeric(trim($v)))) {
            return null;
        }
        return Valor::aNumero($v);
    }

    /** Un decimal que no es un número (infinito, NaN), null */
    private static function real(float $v): ?float
    {
        return is_finite($v) ? $v : null;
    }

    /** El carácter UTF-8 de un código Unicode */
    private static function caracter(int $c): string
    {
        if ($c < 0 || $c > 0x10FFFF) { return ''; }
        if ($c < 0x80) { return \chr($c); }
        if ($c < 0x800) { return \chr(0xC0 | ($c >> 6)) . \chr(0x80 | ($c & 0x3F)); }
        if ($c < 0x10000) { return \chr(0xE0 | ($c >> 12)) . \chr(0x80 | (($c >> 6) & 0x3F)) . \chr(0x80 | ($c & 0x3F)); }
        return \chr(0xF0 | ($c >> 18)) . \chr(0x80 | (($c >> 12) & 0x3F)) . \chr(0x80 | (($c >> 6) & 0x3F)) . \chr(0x80 | ($c & 0x3F));
    }

    /** El código Unicode de un carácter UTF-8 */
    private static function codigo(string $c): int
    {
        $b = array_values((array)unpack('C*', $c));
        $n = \count($b);
        if ($n === 1) { return $b[0]; }
        if ($n === 2) { return (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F); }
        if ($n === 3) { return (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F); }
        return (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F);
    }

    /**
     * FORMAT()/PRINTF(). Con un texto que lleva % delante, el printf de
     * SQLite; con un número, el FORMAT(número, decimales) de MySQL, con comas
     * de miles y punto decimal: FORMAT(1234.5, 2) = '1,234.50'.
     */
    private static function formato(string $nombre, array $args)
    {
        if ($args === []) {
            throw JsonSqlDbError::syntax("$nombre() necesita argumentos");
        }
        if ($nombre === 'PRINTF' || (\is_string($args[0]) && strpos($args[0], '%') !== false)) {
            return self::printf(Valor::aTexto($args[0]), \array_slice($args, 1));
        }
        self::exige('FORMAT', $args, 2, 3);
        if (isset($args[2]) && !\in_array(strtolower((string)$args[2]), ['en_us', 'en-us'], true)) {
            throw JsonSqlDbError::syntax("FORMAT(número, decimales, '{$args[2]}'): solo se admite el formato en_US (1,234.50)");
        }
        $n = self::numero($args[0]);
        $d = self::numero($args[1]);
        if ($n === null || $d === null) { return null; }
        $d = max(0, min(30, Valor::entero($d)));
        return number_format(round((float)$n, $d), $d, '.', ',');
    }

    /**
     * Lo que una función puede crear de una vez: más es un error que dice por
     * qué, no una petición que agota la memoria de PHP (2.9; antes solo en
     * REPEAT y SPACE).
     */
    private static function exigirTope(string $nombre, int|float $bytes): void
    {
        if ($bytes > 16777216) {
            throw JsonSqlDbError::syntax("$nombre(): el resultado pasaría de 16 MB");
        }
    }

    /** El printf de SQLite: %d %i %u %f %e %E %g %G %x %X %o %c %s %q %Q %w %%, con banderas, ancho y precisión */
    private static function printf(string $formato, array $args): string
    {
        $i = 0;
        return (string)preg_replace_callback('/%([-+ 0#,!]*)(\d+|\*)?(?:\.(\d+|\*))?([a-zA-Z%])/', static function (array $m) use (&$i, $args): string {
            [, $banderas, $ancho, $prec, $tipo] = $m + [4 => ''];
            if ($tipo === '%') { return '%'; }
            if ($ancho === '*') { $ancho = (string)Valor::entero(Valor::aNumero($args[$i++] ?? 0)); }
            if ($prec === '*') { $prec = (string)Valor::entero(Valor::aNumero($args[$i++] ?? 0)); }
            self::exigirTope('PRINTF', max((int)$ancho, (int)$prec));
            $v = $args[$i++] ?? null;
            if (strpos($banderas, ',') !== false && strpos('diufF', $tipo) !== false) {
                return self::conMiles($banderas, (int)$ancho, $prec, $v, $tipo === 'f' || $tipo === 'F');
            }
            $banderas = str_replace([',', '!', '#'], '', $banderas);
            $especif = '%' . $banderas . $ancho . ($prec !== '' ? '.' . $prec : '');
            switch ($tipo) {
                case 'd': case 'i': case 'u':
                    return \sprintf($especif . 'd', $v === null ? 0 : Valor::entero(Valor::aNumero($v)));
                case 'f': case 'F':
                    // F y no f: la f de PHP usa la coma decimal del idioma del servidor
                    return \sprintf($especif . 'F', $v === null ? 0.0 : (float)Valor::aNumero($v));
                case 'e': case 'E': case 'g': case 'G':
                    // Como el printf de C y de SQLite (el de PHP escribe e+3 y no
                    // e+03, y redondea los empates de otra forma)
                    $n = $v === null ? 0.0 : (float)Valor::aNumero($v);
                    $p = $prec === '' ? 6 : (int)$prec;
                    $t = strtolower($tipo) === 'e' ? self::cientifico($n, $p) : self::general($n, $p);
                    $t = ctype_upper($tipo) ? strtoupper($t) : $t;
                    $t = ($n >= 0 && strpos($banderas, '+') !== false ? '+' : ($n >= 0 && strpos($banderas, ' ') !== false ? ' ' : '')) . $t;
                    $ancho = (int)$ancho;
                    if (\strlen($t) >= $ancho) { return $t; }
                    if (strpos($banderas, '-') !== false) { return str_pad($t, $ancho); }
                    if (strpos($banderas, '0') !== false) {
                        $signo = strspn($t, '+- ');
                        return substr($t, 0, $signo) . str_repeat('0', $ancho - \strlen($t)) . substr($t, $signo);
                    }
                    return str_pad($t, $ancho, ' ', STR_PAD_LEFT);
                case 'x': case 'X': case 'o':
                    return \sprintf($especif . $tipo, $v === null ? 0 : Valor::entero(Valor::aNumero($v)));
                case 'c':
                    return $v === null ? '' : self::corte(Valor::aTexto($v), 0, 1);
                case 's':
                    return \sprintf($especif . 's', $v === null ? '' : Valor::aTexto($v));
                case 'q':
                    return $v === null ? '(NULL)' : str_replace("'", "''", Valor::aTexto($v));
                case 'Q':
                    return $v === null ? 'NULL' : "'" . str_replace("'", "''", Valor::aTexto($v)) . "'";
                case 'w':
                    return $v === null ? '(NULL)' : str_replace('"', '""', Valor::aTexto($v));
            }
            throw JsonSqlDbError::syntax("PRINTF: especificador no soportado: %$tipo");
        }, $formato);
    }

    /**
     * %d, %i, %u y %f con la bandera ',' de SQLite, separador de miles (2.9: se
     * ignoraba). Como SQLite: con '0', en un entero los ceros de relleno van
     * agrupados también, y en un decimal detrás del signo, sin agrupar.
     *
     * @param mixed $v
     */
    private static function conMiles(string $banderas, int $ancho, string $prec, $v, bool $decimal): string
    {
        $n = $v === null ? 0 : ($decimal ? (float)Valor::aNumero($v) : Valor::entero(Valor::aNumero($v)));
        $signo = $n < 0 ? '-' : (strpos($banderas, '+') !== false ? '+' : (strpos($banderas, ' ') !== false ? ' ' : ''));
        $izquierda = strpos($banderas, '-') !== false;
        $ceros = !$izquierda && strpos($banderas, '0') !== false;
        $dec = null;
        if ($decimal) {
            [$ent, $dec] = explode('.', \sprintf('%.' . ($prec === '' ? 6 : (int)$prec) . 'F', abs($n))) + [1 => null];
        } else {
            // El texto, sin el signo: el valor absoluto del menor entero no cabe en uno
            $ent = str_pad(ltrim((string)$n, '-'), max((int)$prec, $ceros ? $ancho - \strlen($signo) : 0), '0', STR_PAD_LEFT);
        }
        $t = $signo . strrev(implode(',', str_split(strrev($ent), 3))) . ($dec === null ? '' : '.' . $dec);
        if ($decimal && $ceros && \strlen($t) < $ancho) {
            $t = $signo . str_repeat('0', $ancho - \strlen($t)) . substr($t, \strlen($signo));
        }
        return str_pad($t, $ancho, ' ', $izquierda ? STR_PAD_RIGHT : STR_PAD_LEFT);
    }

    /** %e de C: d.ddde±XX, con al menos dos cifras de exponente */
    private static function cientifico(float $n, int $p): string
    {
        [$m, $x] = self::mantisa($n, $p);
        return \sprintf('%.' . $p . 'F', $m) . 'e' . ($x < 0 ? '-' : '+') . str_pad((string)abs($x), 2, '0', STR_PAD_LEFT);
    }

    /** %g de C: %e o %f según el exponente, sin los ceros del final */
    private static function general(float $n, int $p): string
    {
        $p = max(1, $p);
        [, $x] = self::mantisa($n, $p - 1);
        $t = $x < -4 || $x >= $p ? self::cientifico($n, $p - 1) : \sprintf('%.' . max(0, $p - 1 - $x) . 'F', round($n, $p - 1 - $x));
        if (strpos($t, '.') === false) {
            return $t;
        }
        $e = strpos($t, 'e');
        $num = $e === false ? $t : substr($t, 0, $e);
        return rtrim(rtrim($num, '0'), '.') . ($e === false ? '' : substr($t, $e));
    }

    /** @return array{0: float, 1: int} mantisa redondeada a $p decimales (entre 1 y 10) y su exponente */
    private static function mantisa(float $n, int $p): array
    {
        if ($n == 0.0 || !is_finite($n)) {
            return [$n, 0];
        }
        $x = (int)floor(log10(abs($n)));
        $m = round($n / (10 ** $x), $p);
        if (abs($m) >= 10) {
            $m /= 10;
            $x++;
        }
        return [$m, $x];
    }

    /**
     * STR_TO_DATE de MySQL: un texto con ese formato, a 'AAAA-MM-DD', 'HH:MM:SS'
     * o los dos, según lo que traiga el formato. NULL si no encaja o la fecha
     * no existe.
     */
    private static function leerFecha(string $texto, string $formato): ?string
    {
        $meses = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        $partes = [];
        $patron = '';
        $largo = \strlen($formato);
        for ($i = 0; $i < $largo; $i++) {
            if ($formato[$i] !== '%' || $i + 1 >= $largo) {
                $patron .= preg_quote($formato[$i], '/');
                continue;
            }
            $c = $formato[++$i];
            $grupos = ['Y' => '(\d{4})', 'y' => '(\d{2})', 'm' => '(\d{1,2})', 'c' => '(\d{1,2})', 'd' => '(\d{1,2})', 'e' => '(\d{1,2})',
                       'H' => '(\d{1,2})', 'k' => '(\d{1,2})', 'h' => '(\d{1,2})', 'I' => '(\d{1,2})', 'l' => '(\d{1,2})', 'i' => '(\d{1,2})',
                       's' => '(\d{1,2})', 'S' => '(\d{1,2})', 'f' => '(\d{1,6})', 'p' => '(AM|PM|am|pm)', 'M' => '([A-Za-z]+)', 'b' => '([A-Za-z]{3})',
                       'T' => '(\d{1,2}:\d{2}:\d{2})'];
            if ($c === '%') { $patron .= '%'; continue; }
            if (!isset($grupos[$c])) {
                throw JsonSqlDbError::syntax("STR_TO_DATE: especificador no soportado: %$c");
            }
            $patron .= $grupos[$c];
            $partes[] = $c;
        }
        if (!preg_match('/^\s*' . $patron . '\s*$/', $texto, $m)) {
            return null;
        }
        $v = ['Y' => null, 'm' => null, 'd' => null, 'H' => null, 'i' => 0, 's' => 0, 'f' => 0, 'pm' => null];
        foreach ($partes as $k => $c) {
            $x = $m[$k + 1];
            switch ($c) {
                case 'Y': $v['Y'] = (int)$x; break;
                case 'y': $v['Y'] = (int)$x < 70 ? 2000 + (int)$x : 1900 + (int)$x; break;
                case 'm': case 'c': $v['m'] = (int)$x; break;
                case 'd': case 'e': $v['d'] = (int)$x; break;
                case 'H': case 'k': case 'h': case 'I': case 'l': $v['H'] = (int)$x; break;
                case 'i': $v['i'] = (int)$x; break;
                case 's': case 'S': $v['s'] = (int)$x; break;
                case 'f': $v['f'] = (int)substr(str_pad($x, 3, '0'), 0, 3); break;   // al milisegundo, como guarda las horas el motor
                case 'p': $v['pm'] = strtolower($x) === 'pm'; break;
                case 'T': [$v['H'], $v['i'], $v['s']] = array_map('intval', explode(':', $x)); break;
                case 'M': case 'b':
                    $n = null;
                    foreach ($meses as $j => $nombre) {
                        if ($c === 'M' ? strcasecmp($x, $nombre) === 0 : strcasecmp($x, substr($nombre, 0, 3)) === 0) { $n = $j + 1; }
                    }
                    if ($n === null) { return null; }
                    $v['m'] = $n;
                    break;
            }
        }
        if ($v['pm'] !== null && $v['H'] !== null) {
            if ($v['H'] < 1 || $v['H'] > 12) { return null; }
            $v['H'] = $v['H'] % 12 + ($v['pm'] ? 12 : 0);
        }
        $hayFecha = $v['Y'] !== null || $v['m'] !== null || $v['d'] !== null;
        $hayHora  = $v['H'] !== null;
        if ($hayFecha && ($v['Y'] === null || $v['m'] === null || $v['d'] === null || !checkdate($v['m'], $v['d'], $v['Y']))) {
            return null;
        }
        if ($hayHora && ($v['H'] > 23 || $v['i'] > 59 || $v['s'] > 59)) {
            return null;
        }
        $hora = \sprintf('%02d:%02d:%02d', (int)$v['H'], $v['i'], $v['s']) . ($v['f'] ? \sprintf('.%03d', $v['f']) : '');
        if (!$hayFecha) {
            return $hayHora ? $hora : null;
        }
        $fecha = \sprintf('%04d-%02d-%02d', $v['Y'], $v['m'], $v['d']);
        return $hayHora ? "$fecha $hora" : $fecha;
    }

    /**
     * Un decimal como lo escribe QUOTE de SQLite (2.10): como Valor::aTexto()
     * si así vuelve a ser el mismo número; si no, con las cifras que hacen
     * falta (16 o 17) y exponente: 0.30000000000000004 ->
     * '3.0000000000000004e-01'. SQLite pone a veces alguna cifra más
     * ('3.00000000000000044e-01'), que da el mismo número. Antes de la 2.10,
     * 14 cifras: no volvía a ser el mismo. Aquí desde la 2.10, que solo lo
     * usa QUOTE: en Valor lo compilaba cada petición
     */
    private static function decimalExacto(float $v): string
    {
        $t = Valor::aTexto($v);
        if (!is_finite($v) || (float)$t === $v) {
            return $t;
        }
        $s = \sprintf('%.15e', $v);
        return Valor::exponente((float)$s === $v ? $s : \sprintf('%.16e', $v));
    }
}
