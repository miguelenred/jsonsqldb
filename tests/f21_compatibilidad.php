<?php
declare(strict_types=1);

/**
 * Compatibilidad con SQLite y MySQL/MariaDB (2.9): las funciones, la sintaxis
 * y los tipos habituales de quien viene de ellas.
 * Ejecutar: php tests/f21_compatibilidad.php
 *
 * Lo que existe en SQLite se compara con el SQLite de PHP (pdo_sqlite); lo
 * que es de MySQL, con el resultado que da MySQL según su documentación.
 *
 * https://miguelenred.es/jsonsqldb
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_CACHE_RESULTADOS', 0);
require_once dirname(__DIR__) . '/engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_compat';
$ok = 0; $ko = 0;

function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach ((array)scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        is_dir("$dir/$f") ? borrarArbol("$dir/$f") : @unlink("$dir/$f");
    }
    @rmdir($dir);
}

function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
        if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
        elseif ($r === null) { echo "  --   $titulo (se salta con este PHP: le falta lo que necesita, como un locale de coma)\n"; }
        else { $ko++; echo "  FALLO $titulo -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        $ko++;
        echo "  FALLO $titulo -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

borrarArbol($raiz);
@mkdir($raiz, 0775, true);
Database::crear('c', $raiz);
$bd = new Database('c', $raiz);

/**
 * SQLite en memoria para comparar. PDO de SQLite da todo como texto antes de
 * PHP 8.1, y aquí se comparan también los tipos: en 8.0 cada valor se lee con
 * el que SQLite le da en su fila. La clase SQLite3 no sirve: ejecuta dos
 * veces un INSERT … RETURNING
 */
function sqliteParaComparar(): PDO {
    $l = new PDO('sqlite::memory:');
    $l->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (PHP_VERSION_ID < 80100) {
        $l->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FilasConTipo::class]);
    }
    return $l;
}

/** Ver sqliteParaComparar(): getColumnMeta() da el tipo de la fila recién leída */
final class FilasConTipo extends PDOStatement {
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        $f = parent::fetch(PDO::FETCH_NUM);
        if ($f === false) {
            return false;
        }
        $nombres = [];
        foreach ($f as $i => $v) {
            $meta = $this->getColumnMeta($i);
            $nombres[] = $meta['name'];
            $f[$i] = $v === null ? null : match ($meta['native_type']) { 'integer' => (int)$v, 'double' => (float)$v, default => $v };
        }
        return match ($mode) { PDO::FETCH_NUM => $f, PDO::FETCH_COLUMN => $f[0], default => array_combine($nombres, $f) };
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $filas = [];
        while (($f = $this->fetch($mode)) !== false) {
            $filas[] = $f;
        }
        return $filas;
    }
}

$sqlite = sqliteParaComparar();

/** El valor de una expresión en jsonSQLDB */
function valor(string $expr) {
    global $bd;
    return $bd->consultar("SELECT $expr AS v")[0]['v'];
}

/** Igual que en SQLite: mismo valor, y mismo tipo salvo entero/decimal con el mismo número */
function comoSqlite(array $exprs): bool|string {
    global $sqlite;
    foreach ($exprs as $e) {
        $a = valor($e);
        $b = $sqlite->query("SELECT $e AS v")->fetch(PDO::FETCH_ASSOC)['v'];
        if (is_float($a) && is_float($b) ? abs($a - $b) > 1e-9 * max(1, abs($b)) : $a !== $b) {
            return "$e: jsonSQLDB " . var_export($a, true) . ', SQLite ' . var_export($b, true);
        }
    }
    return true;
}

/** Igual que lo que da MySQL (documentado): [expresión => valor esperado] */
function comoMysql(array $casos): bool|string {
    foreach ($casos as $e => $esperado) {
        $a = valor($e);
        if (is_float($esperado) ? !is_float($a) && !is_int($a) || abs((float)$a - $esperado) > 1e-9 : $a !== $esperado) {
            return "$e: " . var_export($a, true) . ', se esperaba ' . var_export($esperado, true);
        }
    }
    return true;
}

/** Da error, con ese texto en el mensaje */
function error(string $sql, string $texto): bool|string {
    global $bd;
    try {
        $bd->consultar($sql);
    } catch (JsonSqlDbError $e) {
        return str_contains($e->getMessage(), $texto) ?: $e->getMessage();
    }
    return "$sql: no dio error";
}

echo "\n== Funciones de números ==\n";
chk('CEIL, FLOOR, TRUNC, SIGN, POW, SQRT, EXP, LN, LOG10, LOG2, LOG(b, x), PI, MOD como SQLite', fn() => comoSqlite([
    'ceil(1.2)', 'ceil(3)', 'ceil(-1.5)', 'floor(-1.5)', 'floor(7)', 'trunc(1.7)', 'trunc(-1.7)', 'sign(-3)', 'sign(2.5)', 'sign(0)',
    "sign('a')", 'sign(NULL)', 'pow(2, 3)', 'power(2, 0.5)', 'sqrt(16)', 'sqrt(-1)', 'exp(1)', 'ln(10)', 'ln(0)', 'log10(100)', 'log2(8)',
    'log(2, 8)', 'log(10, 1000)', 'pi()', 'mod(10.5, 3)', 'mod(-7.5, 2)',
]));
chk('CEILING, MOD, TRUNCATE, GREATEST, LEAST y FORMAT como MySQL', fn() => comoMysql([
    'CEILING(1.2)' => 2.0, 'MOD(10, 3)' => 1, 'MOD(10, 0)' => null, 'MOD(-10, 3)' => -1, 'TRUNCATE(1.999, 1)' => 1.9,
    'TRUNCATE(-1.999, 1)' => -1.9, 'TRUNCATE(122, -2)' => 100, 'TRUNCATE(5, 2)' => 5, 'GREATEST(1, 5, 3)' => 5, 'LEAST(4, 2, 9)' => 2,
    "LEAST('b', 'a')" => 'a', 'GREATEST(1, NULL)' => null, 'FORMAT(1234.5, 2)' => '1,234.50', 'FORMAT(1234567.891, 0)' => '1,234,568',
    'FORMAT(-0.5, 1)' => '-0.5', 'FORMAT(NULL, 2)' => null,
]));
chk('PRINTF y FORMAT con un texto con %, como SQLite', fn() => comoSqlite([
    "printf('%05.2f', 3.14159)", "printf('%d-%s', 5, 'x')", "format('%d-%s', 5, 'x')", "printf('%5s|%-5s|', 'ab', 'cd')",
    "printf('%x %X %o', 255, 255, 8)", "printf('%q', 'it''s')", "printf('%Q', 'a')", "printf('%Q', NULL)", "printf('%.3e', 1234.5)",
    "printf('%%')", "printf('%c', 'hola')", "printf('%+d', 5)", "printf('%g', 1234.5)", "printf('%g', 0.0001)", "printf('%g', 123456789)",
    "printf('%.3g', 3.14159)", "printf('%G', 1e-10)", "printf('%e', 0)", "printf('%e', -0.000123)", "printf('%10.2e', 12345)",
    "printf('%-10.1e|', 5)", "printf('%010.2e', -3.5)", "printf('%g', 100000)", "printf('%g', 1000000)",
]));
chk('RAND() da un decimal entre 0 y 1, y con semilla la misma serie', function () {
    $a = valor('RAND()');
    $b = valor('RAND(7)');
    $c = valor('RAND(7)');
    return is_float($a) && $a >= 0 && $a < 1 && $b === $c ?: [$a, $b, $c];
});
chk('LOG(x) con un argumento da error y explica LN o LOG10', fn() => error('SELECT LOG(100)', 'LOG10(x) o LN(x)'));

echo "\n== Funciones de texto ==\n";
chk('CHAR, UNICODE, HEX de un texto, CONCAT_WS, TYPEOF, IIF, TOTAL, GROUP_CONCAT como SQLite', fn() => comoSqlite([
    'char(65, 66)', "char(193, 241)", "unicode('Á')", "unicode('')", "hex('a')", "hex('ñ')", "concat_ws(',', 'a', NULL, 'b')", "concat_ws(NULL, 'a')",
    'typeof(1)', 'typeof(1.5)', "typeof('a')", 'typeof(NULL)', "iif(1, 'a', 'b')", "iif(0, 'a', 'b')", "iif(NULL, 'a', 'b')",
]));
chk('LEFT, RIGHT, LPAD, RPAD, REVERSE, REPEAT, SPACE, LOCATE, SUBSTRING_INDEX, MID, UCASE, LCASE, CHAR_LENGTH, ASCII, FIELD, ELT como MySQL', fn() => comoMysql([
    "LEFT('Ana', 2)" => 'An', "RIGHT('Luis', 2)" => 'is', "LEFT('María', 4)" => 'Marí', "RIGHT('ab', 5)" => 'ab', "LEFT('ab', -1)" => '',
    "LPAD('5', 3, '0')" => '005', "RPAD('ab', 5, '.')" => 'ab...', "LPAD('hello', 3, 'x')" => 'hel', "LPAD('ñ', 4, 'ab')" => 'abañ',
    "REVERSE('María')" => 'aíraM', "REPEAT('ab', 3)" => 'ababab', "REPEAT('ab', 0)" => '', 'SPACE(3)' => '   ',
    "LOCATE('n', 'Ana')" => 2, "LOCATE('a', 'banana', 3)" => 4, "LOCATE('z', 'banana')" => 0, "LOCATE('í', 'María')" => 4,
    "SUBSTRING_INDEX('a,b,c', ',', 2)" => 'a,b', "SUBSTRING_INDEX('a,b,c', ',', -1)" => 'c', "SUBSTRING_INDEX('a,b,c', ',', 0)" => '',
    "SUBSTRING_INDEX('a,b,c', ',', 9)" => 'a,b,c', "MID('hola', 2, 2)" => 'ol', "UCASE('ñu')" => 'ÑU', "LCASE('ÁB')" => 'áb',
    "CHAR_LENGTH('María')" => 5, "CHARACTER_LENGTH('ab')" => 2, "ASCII('A')" => 65, "ASCII('')" => 0, "FIELD('b', 'a', 'b')" => 2,
    "FIELD('z', 'a', 'b')" => 0, 'FIELD(NULL, 1)' => 0, "ELT(2, 'a', 'b')" => 'b', "ELT(3, 'a', 'b')" => null, "ELT(0, 'a')" => null,
    "IF(1 > 0, 'si', 'no')" => 'si', "IF(NULL, 'si', 'no')" => 'no', 'ISNULL(NULL)' => 1, 'ISNULL(0)' => 0,
]));
chk('HEX de un número da error (SQLite y MySQL dan cosas distintas)', fn() => error('SELECT HEX(255)', 'PRINTF'));

echo "\n== Funciones de fechas ==\n";
chk('JULIANDAY y UNIXEPOCH como SQLite (la fecha en UTC), con modificadores', fn() => comoSqlite([
    "julianday('2026-03-01')", "julianday('2026-03-01 12:00:00')", "unixepoch('2026-03-01')", "unixepoch('2026-03-01', '+1 day')",
    "unixepoch('1970-01-01 00:00:10')",
]));
chk("STRFTIME('%s') da lo mismo que UNIXEPOCH(): la fecha en UTC, y 'now' el momento de ahora (2.9: la tomaba en la zona horaria de PHP)", function () {
    $zona = date_default_timezone_get();
    date_default_timezone_set('Europe/Madrid');               // la de config.php: con UTC no se nota
    try {
        $r = comoSqlite(["strftime('%s', '2026-03-01 10:00:00')", "strftime('%s', '2026-07-01', '+1 day')", "strftime('%s', 1700000000, 'unixepoch')",
                         "strftime('%Y %s', '1970-01-01 00:00:10')"]);
        $ahora = (int)valor("STRFTIME('%s', 'now')");
        $hora = valor("STRFTIME('%H', 'now')");
    } finally {
        date_default_timezone_set($zona);
    }
    // Lo demás de 'now', como DATETIME('now'): la hora de la zona de PHP
    return $r === true && abs($ahora - time()) <= 2 && $hora === (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('H')
        ?: [$r, $ahora, time(), $hora];
});
chk('UNIXEPOCH() y UNIX_TIMESTAMP() sin argumentos son el momento de ahora', function () {
    $a = valor('UNIXEPOCH()');
    $b = valor('UNIX_TIMESTAMP()');
    return abs($a - time()) <= 2 && abs($b - time()) <= 2 ?: [$a, $b, time()];
});
chk('YEAR, MONTH, DAY, HOUR, MINUTE, SECOND, DAYOFWEEK, WEEKDAY, DAYOFYEAR, QUARTER, DAYNAME, MONTHNAME, LAST_DAY, DATEDIFF, DATE_FORMAT como MySQL', fn() => comoMysql([
    "YEAR('2026-03-15 10:20:30')" => 2026, "MONTH('2026-03-15')" => 3, "DAY('2026-03-15')" => 15, "DAYOFMONTH('2026-03-05')" => 5,
    "HOUR('2026-03-15 10:20:30')" => 10, "MINUTE('2026-03-15 10:20:30')" => 20, "SECOND('2026-03-15 10:20:30')" => 30,
    "HOUR('08:05:00')" => 8, "MINUTE('08:05')" => 5, "DAYOFWEEK('2026-03-15')" => 1, "WEEKDAY('2026-03-15')" => 6,
    "DAYOFYEAR('2026-03-15')" => 74, "QUARTER('2026-11-02')" => 4, "DAYNAME('2026-03-15')" => 'Sunday', "MONTHNAME('2026-03-15')" => 'March',
    "LAST_DAY('2026-02-10')" => '2026-02-28', "LAST_DAY('2024-02-10 08:00:00')" => '2024-02-29', "DATEDIFF('2026-03-10', '2026-03-01')" => 9,
    "DATEDIFF('2026-03-01 23:59:59', '2026-03-02 00:00:01')" => -1, "DATEDIFF('2026-01-01', '2025-01-01')" => 365,
    "DATE_FORMAT('2026-03-05 14:07:09', '%d/%m/%Y %H:%i:%s')" => '05/03/2026 14:07:09',
    "DATE_FORMAT('2026-03-05 14:07:09', '%W %e %M %Y, %D')" => 'Thursday 5 March 2026, 5th',
    "DATE_FORMAT('2026-03-05 14:07:09', '%h:%i %p %a %b %j %T %y %c %k %l')" => '02:07 PM Thu Mar 064 14:07:09 26 3 14 2',
    "YEAR('no es fecha')" => null, 'YEAR(NULL)' => null,
]));
chk('FROM_UNIXTIME(UNIX_TIMESTAMP(x)) vuelve a dar x, en la zona horaria del servidor', fn() => comoMysql([
    "FROM_UNIXTIME(UNIX_TIMESTAMP('2026-07-01 10:20:30'))" => '2026-07-01 10:20:30',
    "FROM_UNIXTIME(UNIX_TIMESTAMP('2026-12-31'))" => '2026-12-31 00:00:00',
]));
chk('NOW(), CURRENT_TIMESTAMP(), LOCALTIME, CURDATE(), CURTIME() dan la fecha y hora de ahora', function () {
    $r = $GLOBALS['bd']->consultar('SELECT NOW() AS a, CURRENT_TIMESTAMP() AS b, LOCALTIME AS c, LOCALTIMESTAMP AS d, SYSDATE() AS e, CURDATE() AS f, CURRENT_DATE() AS g, CURTIME() AS h, CURRENT_TIME() AS i')[0];
    foreach (['a', 'b', 'c', 'd', 'e'] as $k) { if (!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string)$r[$k])) { return $r; } }
    return preg_match('/^\d{4}-\d\d-\d\d$/', (string)$r['f']) && $r['f'] === $r['g'] && preg_match('/^\d\d:\d\d:\d\d$/', (string)$r['h']) ?: $r;
});
chk('NOW(3) da error en vez de ignorar el argumento', fn() => error('SELECT NOW(3)', 'sin argumentos'));
chk('UTC_DATE, UTC_TIME y UTC_TIMESTAMP, con paréntesis o sin ellos como en MySQL, en UTC (2.9: sin ellos eran una columna)', function () {
    $r = $GLOBALS['bd']->consultar('SELECT UTC_DATE AS a, UTC_DATE() AS b, UTC_TIME AS c, UTC_TIMESTAMP AS d, UTC_TIMESTAMP() AS e')[0];
    $d = strtotime($r['d'] . ' UTC');
    return $r['a'] === $r['b'] && $r['a'] === substr($r['d'], 0, 10) && preg_match('/^\d\d:\d\d:\d\d$/', $r['c']) === 1
        && abs($d - time()) <= 2 && abs(strtotime($r['e'] . ' UTC') - time()) <= 2 ?: $r;
});
chk('una consulta con NOW() o RAND() no se sirve de la caché de resultados', function () use ($raiz) {
    $dir = "$raiz/cache";
    @mkdir($dir, 0775, true);
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 5000);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' JsonSQLDB\Database::crear("q", ' . var_export($dir, true) . '); $b = new JsonSQLDB\Database("q", ' . var_export($dir, true) . ');'
        . ' $b->consultar("CREATE TABLE t (x INTEGER)"); $v = []; for ($i = 0; $i < 20000; $i++) { $v[] = "($i)"; } $b->consultar("INSERT INTO t VALUES " . implode(",", $v));'
        // Sobre 20.000 filas: cuesta más de 1 ms, así que sin la excepción de lo que cambia en cada llamada se guardaría (2.9)
        . ' $a = $b->consultar("SELECT RAND() AS r, COUNT(*) AS c FROM t")[0]["r"]; $c = $b->consultar("SELECT RAND() AS r, COUNT(*) AS c FROM t")[0]["r"];'
        . ' $n1 = $b->consultar("SELECT NOW() AS n, CURTIME() AS t, COUNT(*) AS c FROM t")[0]; sleep(1); $n2 = $b->consultar("SELECT NOW() AS n, CURTIME() AS t, COUNT(*) AS c FROM t")[0];'
        . ' echo $a !== $c && $n1["n"] !== $n2["n"] && $n1["t"] !== $n2["t"] ? "bien" : "mal";';
    $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    borrarArbol($dir);
    return $r === 'bien' ?: $r;
});

echo "\n== Agregados ==\n";
chk('TOTAL como SQLite: siempre decimal, y 0.0 sin filas; GROUP_CONCAT … SEPARATOR como MySQL', function () use ($bd, $sqlite) {
    $bd->consultar('CREATE TABLE ag (g INTEGER, v INTEGER, s TEXT)');
    $sqlite->exec('CREATE TABLE ag (g INTEGER, v INTEGER, s TEXT)');
    foreach (["(1, 2, 'a')", "(1, 3, 'b')", '(2, NULL, NULL)'] as $f) {
        $bd->consultar("INSERT INTO ag VALUES $f");
        $sqlite->exec("INSERT INTO ag VALUES $f");
    }
    $q = 'SELECT g, total(v) AS t, total(DISTINCT v) AS d FROM ag GROUP BY g ORDER BY g';
    $a = $bd->consultar($q);
    $b = $sqlite->query($q)->fetchAll(PDO::FETCH_ASSOC);
    $sep = $bd->consultar("SELECT GROUP_CONCAT(s SEPARATOR '; ') AS x, GROUP_CONCAT(DISTINCT s ORDER BY s DESC SEPARATOR '|') AS y FROM ag")[0];
    $vacia = $bd->consultar('SELECT total(v) AS t FROM ag WHERE g = 9')[0]['t'];
    return $a === $b && $sep === ['x' => 'a; b', 'y' => 'b|a'] && $vacia === 0.0 ?: json_encode([$a, $b, $sep, $vacia]);
});

echo "\n== Lo que depende de la conexión ==\n";
chk('LAST_INSERT_ID(), last_insert_rowid() y CHANGES() dan error y explican RETURNING', function () {
    foreach (['LAST_INSERT_ID()', 'last_insert_rowid()', 'changes()', 'ROW_COUNT()'] as $f) {
        $r = error("SELECT $f", 'RETURNING');
        if ($r !== true) { return $r; }
    }
    return true;
});

echo "\n== Sintaxis de MySQL: INSERT ==\n";
chk('INSERT IGNORE, INSERT … SET, DEFAULT VALUES y ON DUPLICATE KEY UPDATE con VALUES()', function () use ($bd) {
    $bd->consultar("CREATE TABLE v (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, n INTEGER DEFAULT 0, nota TEXT DEFAULT 'x')");
    $bd->consultar("INSERT INTO v SET email = 'a@x', n = 5");
    $bd->consultar("INSERT IGNORE INTO v (email, n) VALUES ('a@x', 9), ('b@x', 1)");
    $bd->consultar('INSERT INTO v DEFAULT VALUES');
    $bd->consultar("INSERT INTO v (email, n) VALUES ('a@x', 3) ON DUPLICATE KEY UPDATE n = n + VALUES(n), nota = 'dup'");
    $r = $bd->consultar('SELECT email, n, nota FROM v ORDER BY id');
    return $r === [['email' => 'a@x', 'n' => 8, 'nota' => 'dup'], ['email' => 'b@x', 'n' => 1, 'nota' => 'x'], ['email' => null, 'n' => 0, 'nota' => 'x']]
        ?: json_encode($r);
});

echo "\n== Sintaxis de MySQL: tablas e índices ==\n";
chk('CREATE TABLE de un volcado de MySQL: UNSIGNED, COMMENT, CHARACTER SET, KEY, UNIQUE KEY, ENGINE, CHARSET, AUTO_INCREMENT=n', function () use ($bd) {
    $bd->consultar("CREATE TABLE `m` (`id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'clave', `email` varchar(80) CHARACTER SET utf8mb4 NOT NULL,"
        . " `ciudad` varchar(30) DEFAULT NULL, `saldo` decimal(10,2) unsigned DEFAULT '0.00', `nota` longtext, `datos` json, `activo` tinyint(1) NOT NULL DEFAULT 1,"
        . " `bits` bit(1), `anio` year, PRIMARY KEY (`id`), UNIQUE KEY `ux_email` (`email`), KEY `ix_ciudad` (`ciudad`), KEY (`activo`, `ciudad`(10)))"
        . " ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='prueba'");
    $bd->consultar("INSERT INTO m (email, ciudad, saldo) VALUES ('a@x', 'Elche', 3)");
    $id = $bd->consultar('SELECT id FROM m')[0]['id'];
    $indices = array_column($bd->consultar('SHOW INDEXES FROM m'), 'indice');
    $sinSigno = array_column($bd->consultar('SHOW COLUMNS FROM m'), 'sin_signo', 'columna');
    try { $bd->consultar("INSERT INTO m (email, saldo) VALUES ('b@x', -1)"); $neg = 'pasó'; } catch (JsonSqlDbError $e) { $neg = $e->sqlState; }
    try { $bd->consultar("INSERT INTO m (email) VALUES ('a@x')"); $rep = 'pasó'; } catch (JsonSqlDbError $e) { $rep = $e->sqlState; }
    return $id === 100 && in_array('ix_ciudad', $indices, true) && in_array('activo', $indices, true) && $sinSigno['id'] === 1
        && $sinSigno['saldo'] === 1 && $sinSigno['email'] === 0 && $neg === 'TYPE' && $rep === 'CONSTRAINT' ?: json_encode([$id, $indices, $sinSigno, $neg, $rep]);
});
chk('ALTER TABLE … AUTO_INCREMENT = n, de MySQL: sube el contador y nunca lo baja, también tras abrir otra vez la base (2.10)', function () use ($bd, $raiz) {
    $bd->consultar('CREATE TABLE ai (id INTEGER PRIMARY KEY AUTOINCREMENT, n TEXT)');
    $bd->consultar("INSERT INTO ai (n) VALUES ('a'), ('b'), ('c')");
    $bd->consultar('DELETE FROM ai WHERE id = 3');
    $r = [$bd->consultar('ALTER TABLE ai AUTO_INCREMENT = 50')['mensaje'] ?? '', $bd->consultar('ALTER TABLE ai AUTO_INCREMENT=10')['mensaje'] ?? ''];
    $bd->consultar("INSERT INTO ai (n) VALUES ('d')");
    $otra = new Database('c', $raiz);
    $otra->consultar("INSERT INTO ai (n) VALUES ('e')");
    $r[] = array_column($otra->consultar('SELECT id FROM ai'), 'id');
    $r[] = error('ALTER TABLE m2x AUTO_INCREMENT = 5', 'no existe');
    $bd->consultar('CREATE TABLE sinai (id INTEGER PRIMARY KEY)');
    $r[] = error('ALTER TABLE sinai AUTO_INCREMENT = 5', 'no tiene columna AUTO_INCREMENT');
    $r[] = error('ALTER TABLE ai AUTO_INCREMENT = -1', 'número entero positivo');
    return $r === ["El siguiente AUTO_INCREMENT de 'ai' es 50", "El siguiente AUTO_INCREMENT de 'ai' es 50", [1, 2, 50, 51], true, true, true]
        ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('opciones que no existen, ZEROFILL y ON UPDATE dan error en vez de ignorarse', function () {
    foreach (['CREATE TABLE z (n INT) ENGINE=InnoDB FOO=1' => 'Opción de tabla', 'CREATE TABLE z (n INT ZEROFILL)' => 'ZEROFILL',
              'CREATE TABLE z (t TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)' => 'ON UPDATE', 'CREATE TABLE z (n INT) AUTO_INCREMENT=5' => 'AUTO_INCREMENT',
              'CREATE TABLE z (n TEXT UNSIGNED)' => 'UNSIGNED'] as $sql => $texto) {
        $r = error($sql, $texto);
        if ($r !== true) { return "$sql: $r"; }
    }
    return true;
});
chk('CREATE UNIQUE INDEX impone unicidad y DROP INDEX lo quita; ALTER TABLE ADD/DROP INDEX y KEY; índice con DESC', function () use ($bd) {
    $bd->consultar('CREATE TABLE u (id INTEGER PRIMARY KEY, e TEXT, n INTEGER)');
    $bd->consultar('CREATE UNIQUE INDEX ux_e ON u (e DESC)');
    $bd->consultar('CREATE UNIQUE INDEX IF NOT EXISTS ux_e ON u (e)');
    $bd->consultar("INSERT INTO u VALUES (1, 'a', 1)");
    $r1 = error("INSERT INTO u VALUES (2, 'a', 2)", 'UNIQUE');
    $bd->consultar('DROP INDEX ux_e');
    $bd->consultar("INSERT INTO u VALUES (2, 'a', 2)");
    $bd->consultar('ALTER TABLE u ADD INDEX ix_n (n)');
    $bd->consultar('ALTER TABLE u ADD KEY (e)');
    $antes = array_column($bd->consultar('SHOW INDEXES FROM u'), 'indice');
    $bd->consultar('ALTER TABLE u DROP INDEX ix_n');
    $bd->consultar('ALTER TABLE u DROP KEY e');
    $despues = array_column($bd->consultar('SHOW INDEXES FROM u'), 'indice');
    return $r1 === true && in_array('ix_n', $antes, true) && in_array('e', $antes, true) && !in_array('ix_n', $despues, true)
        && !in_array('e', $despues, true) ?: json_encode([$r1, $antes, $despues]);
});
chk('ALTER TABLE … CHANGE renombra y cambia el tipo; CREATE TABLE … LIKE; DESCRIBE y DESC', function () use ($bd) {
    $bd->consultar("CREATE TABLE ch (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, n INTEGER)");
    $bd->consultar('CREATE INDEX ix_nombre ON ch (nombre)');
    $bd->consultar("INSERT INTO ch (nombre, n) VALUES ('ana', 1)");
    $bd->consultar("ALTER TABLE ch CHANGE nombre nombre2 VARCHAR(60) NOT NULL DEFAULT 'z'");
    $r = $bd->consultar('SELECT nombre2 FROM ch');
    $ix = $bd->consultar('SHOW INDEXES FROM ch');
    $bd->consultar('ALTER TABLE ch ADD CONSTRAINT ck_n CHECK (n < 10)');
    $bd->consultar('CREATE TABLE ch2 LIKE ch');
    $bd->consultar("INSERT INTO ch2 (n) VALUES (5)");
    // Como MySQL, la copia lleva los CHECK (2.9: se perdían)
    try { $bd->consultar('INSERT INTO ch2 (n) VALUES (50)'); $check = 'pasó'; } catch (JsonSqlDbError $e) { $check = $e->sqlState; }
    $copia = $bd->consultar('SELECT id, nombre2, n FROM ch2');
    $desc = array_column($bd->consultar('DESCRIBE ch2'), 'columna');
    return $r === [['nombre2' => 'ana']] && in_array('nombre2', array_column($ix, 'columnas'), true) && $check === 'CONSTRAINT'
        && $copia === [['id' => 1, 'nombre2' => 'z', 'n' => 5]] && $desc === array_column($bd->consultar('DESC ch'), 'columna')
        && $desc === ['id', 'nombre2', 'n'] ?: json_encode([$r, $ix, $copia, $desc, $check]);
});
chk('TRUNCATE TABLE vacía la tabla sin triggers, pone el autoincremento en 1 y no deja si otra tabla la referencia', function () use ($bd) {
    $bd->consultar('CREATE TABLE tr (id INTEGER PRIMARY KEY AUTOINCREMENT, x TEXT)');
    $bd->consultar('CREATE TABLE tr_log (n INTEGER)');
    $bd->consultar('CREATE TRIGGER tr_borra AFTER DELETE ON tr FOR EACH ROW BEGIN INSERT INTO tr_log VALUES (1); END');
    $bd->consultar("INSERT INTO tr (x) VALUES ('a'), ('b'), ('c')");
    $bd->consultar('TRUNCATE TABLE tr');
    $bd->consultar("INSERT INTO tr (x) VALUES ('nuevo')");
    $r = $bd->consultar('SELECT id, x FROM tr');
    $log = $bd->consultar('SELECT COUNT(*) AS n FROM tr_log')[0]['n'];
    $bd->consultar('CREATE TABLE padre (id INTEGER PRIMARY KEY)');
    $bd->consultar('CREATE TABLE hija (id INTEGER PRIMARY KEY, p INTEGER REFERENCES padre(id))');
    return $r === [['id' => 1, 'x' => 'nuevo']] && $log === 0 && error('TRUNCATE padre', 'clave foránea') === true ?: json_encode([$r, $log]);
});

echo "\n== Operadores y expresiones ==\n";
chk('& | ~ << >> IS IS NOT IS DISTINCT FROM == como SQLite', fn() => comoSqlite([
    '7 & 3', '7 | 8', '1 << 2', '8 >> 1', '~5', '-16 >> 2', '5 IS 5', '5 IS NULL', 'NULL IS NULL', '5 IS NOT 6', "'a' IS 'a'", 'NULL IS NOT 1',
    '1 == 1', '3 IS DISTINCT FROM NULL', 'NULL IS NOT DISTINCT FROM NULL', '1 + 2 & 3', '6 & 3 = 2',
]));
chk('<=>, DIV, MOD, XOR, TRIM … FROM, POSITION … IN, SUBSTRING … FROM … FOR, EXTRACT, CONVERT y CAST AS SIGNED como MySQL', fn() => comoMysql([
    '5 <=> 5' => 1, 'NULL <=> NULL' => 1, '1 <=> NULL' => 0, '7 DIV 2' => 3, '-7 DIV 2' => -3, '7 DIV 0' => null, '7 MOD 3' => 1,
    '1 XOR 0' => 1, '1 XOR 1' => 0, 'NULL XOR 1' => null, "TRIM('x' FROM 'xxhixx')" => 'hi', "TRIM(LEADING 'x' FROM 'xxhixx')" => 'hixx',
    "TRIM(TRAILING FROM '  hi  ')" => '  hi', "TRIM(BOTH 'ab' FROM 'abhiab')" => 'hi', "POSITION('n' IN 'Ana')" => 2, "SUBSTRING('hola' FROM 2 FOR 2)" => 'ol',
    "SUBSTRING('hola' FROM 3)" => 'la', "EXTRACT(YEAR FROM '2026-03-05')" => 2026, "EXTRACT(MONTH FROM '2026-03-05 10:00:00')" => 3,
    "CONVERT('5', SIGNED)" => 5, "CONVERT('x' USING utf8mb4)" => 'x', "CAST('12abc' AS SIGNED)" => 12, 'CAST(7 AS UNSIGNED)' => 7,
]));
chk('CAST(-1 AS UNSIGNED) y CONVERT(… USING latin1) dan error', function () {
    return error('SELECT CAST(-1 AS UNSIGNED)', 'negativo') === true && error("SELECT CONVERT('x' USING latin1)", 'UTF-8') === true;
});
chk('ORDER BY … NULLS FIRST / NULLS LAST como SQLite, y LIMIT ALL', function () use ($bd, $sqlite) {
    $ddl = 'CREATE TABLE nn (id INTEGER PRIMARY KEY, n INTEGER)';
    $bd->consultar($ddl); $sqlite->exec($ddl);
    foreach (['(1, 5)', '(2, NULL)', '(3, 2)', '(4, NULL)'] as $f) { $bd->consultar("INSERT INTO nn VALUES $f"); $sqlite->exec("INSERT INTO nn VALUES $f"); }
    foreach (['n NULLS LAST, id', 'n DESC NULLS FIRST, id', 'n NULLS FIRST, id', 'n DESC NULLS LAST, id'] as $o) {
        $a = array_column($bd->consultar("SELECT id FROM nn ORDER BY $o"), 'id');
        $b = array_map('intval', $sqlite->query("SELECT id FROM nn ORDER BY $o")->fetchAll(PDO::FETCH_COLUMN));
        if ($a !== $b) { return "$o: " . json_encode([$a, $b]); }
    }
    $todas = array_column($bd->consultar('SELECT id FROM nn ORDER BY id LIMIT ALL'), 'id');
    return $todas === [1, 2, 3, 4] && error('SELECT id FROM nn ORDER BY 2 NULLS LAST', 'columna') === true ?: json_encode($todas);
});

echo "\n== Fechas de MySQL ==\n";
chk('DATE_ADD, DATE_SUB, + INTERVAL, ADDDATE, SUBDATE, TIMESTAMPDIFF, TIMESTAMPADD y STR_TO_DATE como MySQL', fn() => comoMysql([
    "DATE_ADD('2026-01-31', INTERVAL 1 MONTH)" => '2026-02-28', "DATE_ADD('2024-01-31', INTERVAL 1 MONTH)" => '2024-02-29',
    "DATE_SUB('2026-03-31', INTERVAL 1 MONTH)" => '2026-02-28', "'2026-03-05' + INTERVAL 10 DAY" => '2026-03-15', "INTERVAL 1 DAY + '2026-12-31'" => '2027-01-01',
    "'2026-03-05 10:00:00' - INTERVAL 90 MINUTE" => '2026-03-05 08:30:00', "DATE_ADD('2026-03-05', INTERVAL 2 HOUR)" => '2026-03-05 02:00:00',
    "DATE_ADD('2024-02-29', INTERVAL 1 YEAR)" => '2025-02-28', "DATE_ADD('2026-03-05', INTERVAL 1 QUARTER)" => '2026-06-05',
    "DATE_ADD('2026-03-05', INTERVAL 2 WEEK)" => '2026-03-19', "ADDDATE('2026-03-05', 3)" => '2026-03-08', "SUBDATE('2026-03-05', 5)" => '2026-02-28',
    "DATE_ADD('2026-03-05 10:00:00', INTERVAL -1 DAY)" => '2026-03-04 10:00:00', "TIMESTAMPDIFF(DAY, '2026-03-01', '2026-03-10')" => 9,
    "TIMESTAMPDIFF(MONTH, '2026-01-31', '2026-02-28')" => 0, "TIMESTAMPDIFF(MONTH, '2026-01-15', '2026-03-15')" => 2,
    "TIMESTAMPDIFF(YEAR, '2000-06-01', '2026-05-31')" => 25, "TIMESTAMPDIFF(HOUR, '2026-03-01 10:00', '2026-03-01 08:30')" => -1,
    "TIMESTAMPADD(DAY, 2, '2026-03-05')" => '2026-03-07', "DATE_ADD('nada', INTERVAL 1 DAY)" => null,
    "STR_TO_DATE('01/03/2026', '%d/%m/%Y')" => '2026-03-01', "STR_TO_DATE('31/02/2026', '%d/%m/%Y')" => null,
    "STR_TO_DATE('5 March 2026 3:07 PM', '%e %M %Y %l:%i %p')" => '2026-03-05 15:07:00', "STR_TO_DATE('10:20:30', '%H:%i:%s')" => '10:20:30',
    "STR_TO_DATE('12 AM', '%h %p')" => '00:00:00',
]));
chk('STR_TO_DATE con %f guarda la fracción al milisegundo, como DATE_ADD y como lee las horas el motor (2.9)', fn() => comoMysql([
    "STR_TO_DATE('10:20:30.5', '%H:%i:%s.%f')" => '10:20:30.500', "STR_TO_DATE('2026-03-05 10:20:30.123456', '%Y-%m-%d %H:%i:%s.%f')" => '2026-03-05 10:20:30.123',
    "STR_TO_DATE('10:20:30.0009', '%H:%i:%s.%f')" => '10:20:30',
    "STR_TO_DATE('2026-03-05 10:20:30.25', '%Y-%m-%d %H:%i:%s.%f') = DATE_ADD('2026-03-05 10:20:30', INTERVAL 250000 MICROSECOND)" => 1,
]));
chk('INTERVAL suelto y DATE_ADD sin INTERVAL dan error', fn() =>
    error('SELECT INTERVAL 1 DAY', 'INTERVAL') === true && error("SELECT DATE_ADD('2026-01-01', 5)", 'INTERVAL') === true);

echo "\n== UPDATE y DELETE con ORDER BY y LIMIT ==\n";
chk('UPDATE … ORDER BY … LIMIT y DELETE … ORDER BY … LIMIT [OFFSET] cambian solo esas filas, en ese orden, como MySQL y SQLite', function () use ($bd) {
    $bd->consultar('CREATE TABLE ul (id INTEGER PRIMARY KEY, n INTEGER, s TEXT)');
    $bd->consultar("INSERT INTO ul VALUES (1, 5, 'a'), (2, NULL, 'b'), (3, 2, 'c'), (4, 9, 'd'), (5, 2, 'e')");
    $bd->consultar("UPDATE ul SET s = 'X' ORDER BY n DESC LIMIT 2");
    $r1 = array_column($bd->consultar("SELECT id FROM ul WHERE s = 'X' ORDER BY id"), 'id');
    $bd->consultar('DELETE FROM ul WHERE n IS NOT NULL ORDER BY n, id DESC LIMIT 2');
    $r2 = array_column($bd->consultar('SELECT id FROM ul ORDER BY id'), 'id');
    $r3 = $bd->consultar('UPDATE ul SET n = 0 RETURNING id ORDER BY id LIMIT 1 OFFSET 1');
    $r4 = $bd->consultar('DELETE FROM ul ORDER BY id DESC LIMIT 1 RETURNING id');
    $bd->consultar('UPDATE ul SET n = 7 LIMIT 0');
    $r5 = $bd->consultar('SELECT COUNT(*) AS c FROM ul WHERE n = 7')[0]['c'];
    $bd->consultar('DELETE FROM ul LIMIT -1');
    $r6 = $bd->consultar('SELECT COUNT(*) AS c FROM ul')[0]['c'];
    return $r1 === [1, 4] && $r2 === [1, 2, 4] && $r3 === [['id' => 2]] && $r4 === [['id' => 4]] && $r5 === 0 && $r6 === 0
        ?: json_encode([$r1, $r2, $r3, $r4, $r5, $r6]);
});

echo "\n== COLLATE y json_extract ==\n";
chk('x COLLATE NOCASE | RTRIM | BINARY al comparar, en IN, BETWEEN, ORDER BY y GROUP BY, como SQLite', function () use ($bd, $sqlite) {
    foreach (['CREATE TABLE co (id INTEGER PRIMARY KEY, s TEXT)', "INSERT INTO co VALUES (1, 'b'), (2, 'B'), (3, 'a'), (4, 'C'), (5, 'a  '), (6, NULL), (7, 'Ñ'), (8, 'ñ')"] as $q) {
        $bd->consultar($q); $sqlite->exec($q);
    }
    foreach (["SELECT id FROM co WHERE s = 'B' COLLATE NOCASE ORDER BY id", "SELECT id FROM co WHERE s COLLATE NOCASE = 'B' ORDER BY id",
              'SELECT id FROM co ORDER BY s COLLATE NOCASE, id', 'SELECT id FROM co ORDER BY s COLLATE NOCASE DESC, id',
              "SELECT id FROM co WHERE s = 'a' COLLATE RTRIM ORDER BY id", "SELECT id FROM co WHERE s COLLATE NOCASE IN ('A', 'c') ORDER BY id",
              "SELECT id FROM co WHERE s COLLATE NOCASE BETWEEN 'a' AND 'b' ORDER BY id", 'SELECT COUNT(*) AS n FROM co GROUP BY s COLLATE NOCASE ORDER BY 1',
              'SELECT s COLLATE NOCASE AS x FROM co WHERE id = 2', "SELECT id FROM co WHERE s = 'ñ' COLLATE NOCASE ORDER BY id",
              "SELECT id FROM co WHERE s = 'B' COLLATE BINARY ORDER BY id", "SELECT 'A' < 'b' COLLATE NOCASE AS x"] as $q) {
        $a = array_map('array_values', $bd->consultar($q));
        $b = $sqlite->query($q)->fetchAll(PDO::FETCH_NUM);
        if ($a !== $b) { return "$q: " . json_encode([$a, $b]); }
    }
    return error('CREATE TABLE cz (s INTEGER COLLATE NOCASE)', 'columnas de texto') === true && error('CREATE TABLE cz (s TEXT COLLATE FOO)', 'NOCASE') === true
        && error('SELECT 1 COLLATE FOO', 'NOCASE') === true;
});
chk('json_extract como SQLite: claves, listas, [#-1], rutas que no están, varias rutas y JSON mal formado', function () use ($bd, $sqlite) {
    $j = '{"a":1,"b":"x","c":[10,20,{"d":true}],"e":null,"f":{"g h":2.5,"i":[]},"k":false}';
    $q = [];
    foreach (['$.a', '$.b', '$.c', '$.c[1]', '$.c[2].d', '$.c[#-1]', '$.c[#-1].d', '$.e', '$.f', '$."f"."g h"', '$.f.i', '$.k', '$.nada', '$.c[9]', '$', '$.a.b'] as $p) {
        $q[] = "SELECT json_extract('$j', '$p') AS v";
    }
    $q[] = "SELECT json_extract('$j', '$.a', '$.b', '$.nada') AS v";
    $q[] = "SELECT json_extract(NULL, '$.a') AS v";
    foreach ($q as $sql) {
        $a = array_map('array_values', $bd->consultar($sql));
        $b = $sqlite->query($sql)->fetchAll(PDO::FETCH_NUM);
        if ($a !== $b) { return "$sql: " . json_encode([$a, $b]); }
    }
    return error("SELECT json_extract('{mal', '$.a')", 'mal formado') === true && error("SELECT json_extract('{}', 'a')", 'ruta') === true;
});

echo "\n== JOIN … USING y NATURAL JOIN ==\n";
chk('USING y NATURAL JOIN como SQLite: la columna común una vez en SELECT *, sin ambigüedad sin tabla delante', function () use ($bd, $sqlite) {
    foreach (['CREATE TABLE ja (id INTEGER PRIMARY KEY, x TEXT, k INTEGER)', 'CREATE TABLE jb (id INTEGER PRIMARY KEY, y TEXT, k INTEGER)',
              'CREATE TABLE jc (k INTEGER, z TEXT)', "INSERT INTO ja VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', NULL)",
              "INSERT INTO jb VALUES (1, 'b1', 10), (2, 'b2', 99), (4, 'b4', 20)", "INSERT INTO jc VALUES (10, 'c10'), (20, 'c20')"] as $q) {
        $bd->consultar($q); $sqlite->exec($q);
    }
    foreach (['SELECT * FROM ja JOIN jb USING (id) ORDER BY id', 'SELECT id, x, y FROM ja LEFT JOIN jb USING (id) ORDER BY id',
              'SELECT * FROM ja NATURAL JOIN jb ORDER BY id', 'SELECT ja.id, jb.id, k FROM ja JOIN jb USING (k) ORDER BY ja.id',
              'SELECT * FROM ja NATURAL JOIN jc ORDER BY id', 'SELECT * FROM ja JOIN jb USING (id, k) ORDER BY id',
              'SELECT ja.*, y FROM ja JOIN jb USING (id) ORDER BY id', 'SELECT COUNT(*) AS n FROM ja JOIN jb USING (id) WHERE id > 1',
              'SELECT * FROM ja JOIN jb USING (id) JOIN jc USING (k) ORDER BY id', 'SELECT * FROM ja NATURAL LEFT JOIN jc ORDER BY id'] as $q) {
        $a = array_map('array_values', $bd->consultar($q));
        $b = $sqlite->query($q)->fetchAll(PDO::FETCH_NUM);
        if ($a !== $b) { return "$q: " . json_encode([$a, $b]); }
    }
    return error('SELECT * FROM ja JOIN jb USING (nada)', 'dos lados') === true ?: 'USING con una columna que no existe';
});

echo "\n== Funciones habituales: segunda tanda (2.9) ==\n";
chk('las de SQLite dan lo mismo que SQLite 3.45 (valores fijos: el SQLite de cada máquina puede no tenerlas)', fn() => comoMysql(array (
  'glob(\'A*\',\'Ana\')' => 1,
  'glob(\'a*\',\'Ana\')' => 0,
  'like(\'a%\',\'Ana\')' => 1,
  'likelihood(5,0.5)' => 5,
  'unlikely(3)' => 3,
  'octet_length(\'ñandú\')' => 7,
  'quote(\'a\'\'b\')' => '\'a\'\'b\'',
  'quote(12)' => '12',
  'quote(NULL)' => 'NULL',
  'soundex(\'Robert\')' => 'R163',
  'soundex(\'Tymczak\')' => 'T522',
  'soundex(\'\')' => '?000',
  'hex(unhex(\'00ff\'))' => '00FF',
  'unhex(\'4G\')' => NULL,
  'acos(0.5)' => 1.047197551197,
  'asin(0.5)' => 0.523598775598,
  'atan(1)' => 0.785398163397,
  'atan2(1,2)' => 0.463647609001,
  'cos(1)' => 0.540302305868,
  'sin(1)' => 0.841470984808,
  'tan(1)' => 1.557407724655,
  'degrees(1)' => 57.295779513082,
  'radians(180)' => 3.14159265359,
  'json(\'{"a" : [1, 2.0, "x/y"]}\')' => '{"a":[1,2.0,"x/y"]}',
  'json_array(1, \'a\', NULL, 2.5)' => '[1,"a",null,2.5]',
  'json_object(\'a\', 1, \'b\', \'x\')' => '{"a":1,"b":"x"}',
  'json_array_length(\'[1,2,3]\')' => 3,
  'json_array_length(\'{"a":[1,2]}\',\'$.a\')' => 2,
  'json_type(\'{"a":1.5}\',\'$.a\')' => 'real',
  'json_type(\'[1]\')' => 'array',
  'json_valid(\'{x\')' => 0,
  'json_set(\'{"a":1}\',\'$.a\',2,\'$.b\',3)' => '{"a":2,"b":3}',
  'json_insert(\'{"a":1}\',\'$.a\',2,\'$.b\',3)' => '{"a":1,"b":3}',
  'json_replace(\'{"a":1}\',\'$.a\',2,\'$.b\',3)' => '{"a":2}',
  'json_remove(\'{"a":1,"b":2}\',\'$.a\')' => '{"b":2}',
  'json_set(\'[1,2]\',\'$[#]\',3)' => '[1,2,3]',
  'json_remove(\'[1,2,3]\',\'$[1]\')' => '[1,3]',
)));
chk('las de MySQL dan lo que dice su documentación', fn() => comoMysql([
    "bin(12)" => '1100', "oct(12)" => '14', "conv('a',16,2)" => '1010', "conv('6E',18,8)" => '172', "bit_count(29)" => 4,
    "bit_length('text')" => 32, "find_in_set('b','a,b,c,d')" => 2, "find_in_set('z','a,b')" => 0,
    "insert('Quadratic', 3, 4, 'What')" => 'QuWhattic', "insert('Quadratic', -1, 4, 'What')" => 'Quadratic',
    "insert('Quadratic', 3, 100, 'What')" => 'QuWhat', "strcmp('text','text2')" => -1, "strcmp('text','text')" => 0,
    "md5('testing')" => 'ae2b1fca515949e5d54fb22b8ed95575', "sha1('abc')" => 'a9993e364706816aba3e25717850c26c9cd0d89d',
    "sha2('abc',224)" => '23097d223405d8228642a477bda255b32aadbce4bda0b3f7e36c9da7', "crc32('MySQL')" => 3259397556,
    "to_base64('abc')" => 'YWJj', "from_base64(to_base64('abc'))" => 'abc', "makedate(2011,31)" => '2011-01-31',
    "makedate(2011,32)" => '2011-02-01', "makedate(2011,0)" => null, "period_diff(200802,200703)" => 11,
    "sec_to_time(2378)" => '00:39:38', "time_to_sec('22:23:00')" => 80580,
    "timediff('2000-01-01 00:00:00','2000-01-01 00:00:00.000001')" => '-00:00:00.000001',
    "timediff('2008-12-31 23:59:59','2008-12-30 01:01:01')" => '46:58:58', "timediff('10:00:00','2008-12-30 01:01:01')" => null,
    "timediff('09:00:00','10:30:15')" => '-01:30:15', "time_format('100:00:00', '%H %k %h %I %l')" => '04 4 04 04 4',
    "timestamp('2003-12-31')" => '2003-12-31 00:00:00', "timestamp('2003-12-31 12:00:00','12:00:00')" => '2004-01-01 00:00:00',
    "to_days('2007-10-07')" => 733321, "week('2008-02-20')" => 7, "week('2008-02-20',1)" => 8, "week('2008-12-31',1)" => 53,
    "week('2000-01-01',0)" => 0, "week('2000-01-01',2)" => 52, "yearweek('1987-01-01')" => 198652, "weekofyear('2008-02-20')" => 8,
    "json_unquote('\"abc\"')" => 'abc', "json_length('[1, 2, {\"a\": 3}]')" => 3,
    "json_length('{\"a\": 1, \"b\": {\"c\": 30}}', '$.b')" => 1,
    "json_contains('{\"a\": 1, \"c\": {\"d\": 4}}', '{\"d\": 4}', '$.c')" => 1,
    "json_contains('{\"a\": 1, \"c\": {\"d\": 4}}', '{\"d\": 4}', '$.a')" => 0,
    "json_keys('{\"a\": 1, \"b\": {\"c\": 30}}')" => '["a","b"]', "length(uuid())" => 36,
]));
chk('RANDOMBLOB y ZEROBLOB valen dentro de HEX(), pero guardarlos tal cual da error siempre, sean cuales sean los bytes', function () {
    global $bd;
    $bd->consultar('CREATE TABLE blobs (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar('INSERT INTO blobs VALUES (1, hex(randomblob(16)))');
    $errores = 0;
    foreach (['INSERT INTO blobs VALUES (2, randomblob(4))', 'INSERT INTO blobs SELECT 3, zeroblob(2)',
              'UPDATE blobs SET x = coalesce(randomblob(3), 1)'] as $q) {
        for ($i = 0; $i < 20; $i++) {                    // con bytes al azar distintos cada vez
            try { $bd->consultar($q); } catch (JsonSqlDbError $e) { $errores += str_contains($e->getMessage(), 'HEX(') ? 1 : 0; }
        }
    }
    $hex = $bd->consultar('SELECT length(x) AS n FROM blobs')[0]['n'];
    $bd->consultar('DROP TABLE blobs');
    return $errores === 60 && $hex === 32 ?: "errores $errores de 60, largo $hex";
});
chk('ZEROBLOB, RANDOMBLOB, LPAD, RPAD, REPEAT y PRINTF no crean más de 16 MB de una vez: es un error que lo dice (2.9)', function () {
    global $bd;
    $mal = [];
    foreach (['zeroblob(20000000)', 'hex(randomblob(20000000))', "lpad('a', 20000000, 'b')", "rpad('a', 20000000, 'b')",
              "repeat('ab', 9000000)", "printf('%20000000d', 1)", "printf('%.20000000f', 1)", "printf('%*d', 20000000, 1)"] as $e) {
        try {
            $bd->consultar("SELECT length($e) AS n");
            $mal[] = "$e pasó";
        } catch (JsonSqlDbError $x) {
            if (!str_contains($x->getMessage(), '16 MB')) { $mal[] = "$e: " . $x->getMessage(); }
        }
    }
    $bien = valor("length(zeroblob(1000)) + length(lpad('a', 1000, 'b')) + length(printf('%1000d', 1))");
    return $mal === [] && $bien === 3000 ?: implode(' | ', $mal) . " / $bien";
});
chk('UUID() no se repite aunque la consulta sea la misma (no se guarda en la caché de resultados)', fn() =>
    valor('uuid()') !== valor('uuid()') && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', valor('uuid()')) === 1);

chk('agregados nuevos: STRING_AGG y los JSON como SQLite 3.44; varianzas, bits y ANY_VALUE como MySQL', function () {
    global $bd;
    $bd->consultar('CREATE TABLE agn (id INTEGER PRIMARY KEY, g TEXT, x TEXT, n INTEGER)');
    $bd->consultar("INSERT INTO agn VALUES (1,'a','uno',3),(2,'a',NULL,5),(3,'b','tres',NULL),(4,'b','cuatro',8),(5,'b','cinco',8)");
    $q = static fn(string $sql) => $bd->consultar($sql);
    $casos = [
        [$q("SELECT g, string_agg(x, '-') AS r FROM agn GROUP BY g"), [['g' => 'a', 'r' => 'uno'], ['g' => 'b', 'r' => 'tres-cuatro-cinco']]],
        [$q('SELECT g, json_group_array(x) AS r FROM agn GROUP BY g'), [['g' => 'a', 'r' => '["uno",null]'], ['g' => 'b', 'r' => '["tres","cuatro","cinco"]']]],
        [$q('SELECT json_group_object(x, n) AS r FROM agn WHERE x IS NOT NULL'), [['r' => '{"uno":3,"tres":null,"cuatro":8,"cinco":8}']]],
        [$q('SELECT json_group_array(n) AS r FROM agn WHERE id > 99'), [['r' => '[]']]],
        [$q('SELECT variance(n) AS v, var_samp(n) AS vs, std(n) AS s, stddev_samp(n) AS ss FROM agn'), [['v' => 4.5, 'vs' => 6.0, 's' => sqrt(4.5), 'ss' => sqrt(6)]]],
        [$q('SELECT var_samp(n) AS r FROM agn WHERE id = 1'), [['r' => null]]],
        [$q('SELECT bit_or(n) AS o, bit_and(n) AS a, bit_xor(n) AS x FROM agn'), [['o' => 15, 'a' => 0, 'x' => 6]]],
        [$q("SELECT any_value(x) AS r FROM agn WHERE g = 'b'"), [['r' => 'tres']]],
        [$q("SELECT json_arrayagg(n) AS r FROM agn WHERE g = 'a'"), [['r' => '[3,5]']]],
        [$q('SELECT g, variance(DISTINCT n) AS r FROM agn GROUP BY g'), [['g' => 'a', 'r' => 1.0], ['g' => 'b', 'r' => 0.0]]],
    ];
    $bd->consultar('DROP TABLE agn');
    foreach ($casos as $i => [$r, $esperado]) {
        if ($r != $esperado) { return "caso $i: " . json_encode($r); }
    }
    return true;
});

chk('la precedencia de || , -> y ->> es la de SQLite: por encima de * / % y + -, con COLLATE y los operadores de bits (2.9: 1 + 2 || 3 daba \'33\' y no 24)', function () {
    return comoSqlite(["1 + 2 || 3", "2 * 3 || 4", "'a' || 1 + 1", "2 - 1 || 0", "1 || 2 * 3", "7 % 4 || 2", "1 || 2 + 3 || 4", "- 1 || 2 * 3",
        "5 % 3 || 2 * 2", "-2 || 3", "~1 || 2", "1 || -2", "1 << 2 || 1", "1 | 2 || 0", "1 || 2 << 1", "1 || 2 & 7", "2 || 3 > 22", "1 || 2 IN ('12')",
        "'a' || 'b' LIKE 'AB'", "1 + 1 IS 2", "1 || 1 IS '11'", "'x' || NULL || 'y'", "NULL || 1 + 1", "2 BETWEEN 1 AND 3 || 0",
        "'A' || 'b' COLLATE NOCASE = 'ab'", "'a' COLLATE NOCASE || 'B' = 'AB'", "'{\"a\":5}' -> '$.a' || 'x'", "'{\"a\":5}' ->> '$.a' * 2",
        "2 * '{\"a\":5}' ->> '$.a'", "1 + '{\"a\":5}' ->> '$.a'", "'{\"a\":[1,2]}' -> '$.a' ->> 1", "'[3,4]' ->> 0 * 2 || 1", "'[3,4]' -> 1 || 'x'",
        "- '{\"a\":5}' ->> '$.a'", "'{\"a\":5}' COLLATE NOCASE ->> '$.a'", "1 + 2 * 3 - 4 / 2 || ''", "10 - 2 - 3 || 1 << 1"]);
});

chk('GLOB, -> y ->> como SQLite 3.45 (también NOT GLOB, rutas abreviadas, y que 5 - 3 sigue siendo una resta)', function () {
    global $bd;
    $bd->consultar('CREATE TABLE fl (id INTEGER PRIMARY KEY, j TEXT, s TEXT)');
    $bd->consultar("INSERT INTO fl VALUES (1, '{\"a\":\"t\",\"b\":[1,2,{\"c\":true}],\"n\":null,\"x\":1.5}', 'Ana'), (2, '[10,20,30]', 'bea'), (3, NULL, '[a]x')");
    $mal = null;
    foreach (array (
  'SELECT j -> \'$.a\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => '"t"',
    ),
  ),
  'SELECT j ->> \'$.a\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => 't',
    ),
  ),
  'SELECT j -> \'a\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => '"t"',
    ),
  ),
  'SELECT j ->> \'b\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => '[1,2,{"c":true}]',
    ),
  ),
  'SELECT j -> \'$.b[2].c\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => 'true',
    ),
  ),
  'SELECT j ->> \'$.b[2].c\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => 1,
    ),
  ),
  'SELECT j -> \'$.n\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => 'null',
    ),
  ),
  'SELECT j ->> \'$.n\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => NULL,
    ),
  ),
  'SELECT j ->> \'$.nada\' AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => NULL,
    ),
  ),
  'SELECT j ->> 1 AS r FROM t WHERE id = 2' => 
  array (
    0 => 
    array (
      'r' => 20,
    ),
  ),
  'SELECT j -> \'$[#-1]\' AS r FROM t WHERE id = 2' => 
  array (
    0 => 
    array (
      'r' => '30',
    ),
  ),
  'SELECT j ->> \'$.x\' + 1 AS r FROM t WHERE id = 1' => 
  array (
    0 => 
    array (
      'r' => 2.5,
    ),
  ),
  'SELECT id FROM t WHERE j ->> \'$.a\' = \'t\'' => 
  array (
    0 => 
    array (
      'id' => 1,
    ),
  ),
  'SELECT j -> \'$.a\' AS r FROM t WHERE id = 3' => 
  array (
    0 => 
    array (
      'r' => NULL,
    ),
  ),
  'SELECT s FROM t WHERE s GLOB \'A*\'' => 
  array (
    0 => 
    array (
      's' => 'Ana',
    ),
  ),
  'SELECT s FROM t WHERE s GLOB \'a*\'' => 
  array (
  ),
  'SELECT s FROM t WHERE s NOT GLOB \'*a\' ORDER BY id' => 
  array (
    0 => 
    array (
      's' => '[a]x',
    ),
  ),
  'SELECT s FROM t WHERE s GLOB \'[[]a]*\'' => 
  array (
    0 => 
    array (
      's' => '[a]x',
    ),
  ),
  'SELECT s FROM t WHERE s GLOB \'?ea\'' => 
  array (
    0 => 
    array (
      's' => 'bea',
    ),
  ),
  'SELECT count(*) AS r FROM t WHERE s GLOB \'[^A]*\'' => 
  array (
    0 => 
    array (
      'r' => 2,
    ),
  ),
  'SELECT 5 - 3 AS r' => 
  array (
    0 => 
    array (
      'r' => 2,
    ),
  ),
  'SELECT 5->3 AS r' => 
  array (
    0 => 
    array (
      'r' => NULL,
    ),
  ),
) as $q => $esperado) {
        $r = $bd->consultar(str_replace(' FROM t', ' FROM fl', $q));
        if ($r !== $esperado) { $mal = "$q: " . json_encode($r) . ', SQLite ' . json_encode($esperado); break; }
    }
    $bd->consultar('DROP TABLE fl');
    return $mal ?? true;
});

echo "\n== Lo que aquí no tiene efecto y las transacciones en un lote (2.9) ==\n";
chk('VACUUM, ANALYZE, USE de esta base y FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE no fallan ni cambian nada', function () {
    global $bd;
    $bd->consultar('CREATE TABLE ne (id INTEGER PRIMARY KEY AUTOINCREMENT, x TEXT)');
    $bd->consultar("INSERT INTO ne (x) VALUES ('a')");
    foreach (['VACUUM', 'VACUUM main', 'ANALYZE', 'ANALYZE ne', 'ANALYZE TABLE ne', 'USE c'] as $q) {
        if ($bd->consultar($q)['filas'] !== 0) { return $q; }
    }
    foreach (['SELECT x FROM ne FOR UPDATE', 'SELECT x FROM ne WHERE id = 1 FOR SHARE NOWAIT',
              'SELECT x FROM ne FOR UPDATE OF ne SKIP LOCKED', 'SELECT x FROM ne LOCK IN SHARE MODE', "SELECT x GLOB 'a' AS g FROM ne"] as $q) {
        $r = $bd->consultar($q);
        if ($r !== [['x' => 'a']] && $r !== [['g' => 1]]) { return "$q: " . json_encode($r); }
    }
    return error('USE otra', 'no cambia de base') === true && error("VACUUM INTO 'x.db'", 'VACUUM INTO') === true;
});
chk('BEGIN/START TRANSACTION y COMMIT/END dentro de un lote; ROLLBACK lo deshace entero; en otro sitio, error', function () {
    global $bd;
    $bd->lote(['BEGIN', "INSERT INTO ne (x) VALUES ('b')", 'COMMIT']);
    $r = $bd->lote(['BEGIN TRANSACTION', "INSERT INTO ne (x) VALUES ('c')", 'ROLLBACK']);
    $bd->lote(['START TRANSACTION', "INSERT INTO ne (x) VALUES ('d')", 'END']);
    $mal = [];
    foreach ([[["INSERT INTO ne (x) VALUES ('e')", 'BEGIN'], 'primera'], [['COMMIT', "INSERT INTO ne (x) VALUES ('e')"], 'última']] as [$lote, $txt]) {
        try { $bd->lote($lote); $mal[] = 'sin error'; } catch (JsonSqlDbError $e) { if (!str_contains($e->getMessage(), $txt)) { $mal[] = $e->getMessage(); } }
    }
    $filas = array_column($bd->consultar('SELECT x FROM ne ORDER BY id'), 'x');
    $bd->consultar('DROP TABLE ne');
    return $filas === ['a', 'b', 'd'] && $mal === [] && str_contains($r[2]['mensaje'], 'deshecha') && error('BEGIN', 'un lote') === true
        ?: json_encode([$filas, $mal]);
});

echo "\n== INTEGER PRIMARY KEY sin AUTOINCREMENT (2.9) ==\n";
chk('una clave primaria entera sin AUTOINCREMENT se numera como en SQLite: la mayor + 1, también en varias filas y tras borrar la mayor', function () {
    global $bd, $sqlite;
    $pasos = [
        'CREATE TABLE ipk (id INTEGER PRIMARY KEY, x TEXT)',
        "INSERT INTO ipk (x) VALUES ('a')",
        "INSERT INTO ipk VALUES (NULL, 'b')",
        "INSERT INTO ipk VALUES (10, 'c')",
        "INSERT INTO ipk (x) VALUES ('d'), ('e')",
        'DELETE FROM ipk WHERE id = 12',
        "INSERT INTO ipk (x) VALUES ('f')",
        "INSERT INTO ipk VALUES (NULL, 'g'), (20, 'h'), (NULL, 'i')",
    ];
    foreach ($pasos as $q) { $bd->consultar($q); $sqlite->exec($q); }
    $esperado = $sqlite->query('SELECT id, x FROM ipk ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $r = $bd->consultar('SELECT id, x FROM ipk ORDER BY id');
    // Con muchas filas la mayor sale del índice; sin índices, recorriendo la tabla
    $v = [];
    for ($i = 1; $i <= 3000; $i++) { $v[] = "($i, 'v')"; }
    $bd->consultar('CREATE TABLE ipk2 (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar('INSERT INTO ipk2 VALUES ' . implode(',', $v));
    $bd->consultar("INSERT INTO ipk2 (x) VALUES ('nueva')");
    $nueva = $bd->consultar("SELECT id FROM ipk2 WHERE x = 'nueva'");
    $bd->consultar('DROP TABLE ipk');
    $bd->consultar('DROP TABLE ipk2');
    $sqlite->exec('DROP TABLE ipk');
    return $r === $esperado && $nueva === [['id' => 3001]] ?: json_encode([$r, $esperado, $nueva]);
});
chk('sin índices, la siguiente sale recorriendo la tabla y da lo mismo (2.9)', function () use ($raiz) {
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_INDICES", false); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' $d = ' . var_export("$raiz/sinidx", true) . '; @mkdir($d, 0775, true); JsonSQLDB\Database::crear("s", $d); $b = new JsonSQLDB\Database("s", $d);'
        . ' $b->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT)"); $b->consultar("INSERT INTO t VALUES (7, \'a\'), (3, \'b\')");'
        . ' $b->consultar("INSERT INTO t (x) VALUES (\'c\')"); echo json_encode($b->consultar("SELECT id FROM t WHERE x = \'c\'"));';
    $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    borrarArbol("$raiz/sinidx");
    return $r === '[{"id":8}]' ?: $r;
});

echo "\n== SHOW CREATE TABLE y SHOW CREATE VIEW (2.9) ==\n";
chk('lo que da SHOW CREATE, ejecutado en otra base, crea la misma estructura (claves, valores por defecto, índices, vista)', function () use ($raiz) {
    $a = "$raiz/sca"; $b = "$raiz/scb";
    foreach ([$a, $b] as $d) { @mkdir($d, 0775, true); if (is_dir("$d/x")) { Database::borrar('x', $d); } Database::crear('x', $d); }
    $o = new Database('x', $a);
    $o->consultar("CREATE TABLE p (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre VARCHAR(40) NOT NULL UNIQUE, precio DECIMAL(10,2) UNSIGNED DEFAULT 0 CHECK (precio < 1000), nota TEXT DEFAULT 'it''s', estado ENUM('a', 'b''c') NOT NULL, tasa DECIMAL(10,4), CONSTRAINT nom CHECK (length(nombre) > 1))");
    $o->consultar("CREATE TABLE linea (a INTEGER, b TEXT DEFAULT 'x', c DATETIME DEFAULT CURRENT_TIMESTAMP, d DOUBLE DEFAULT (1+2), p_id INTEGER REFERENCES p(id) ON DELETE CASCADE ON UPDATE SET NULL, PRIMARY KEY (a, b), UNIQUE (c, d))");
    $o->consultar('CREATE INDEX ix_d ON linea (d, a)');
    $o->consultar('CREATE VIEW v AS SELECT id, nombre FROM p WHERE id > 1');
    $n = new Database('x', $b);
    foreach (['SHOW CREATE TABLE p', 'SHOW CREATE TABLE linea', 'SHOW CREATE VIEW v'] as $q) {
        foreach (explode(";\n", $o->consultar($q)[0]['sql']) as $sentencia) { $n->consultar($sentencia); }
    }
    $mal = [];
    foreach (['SHOW COLUMNS FROM p', 'SHOW COLUMNS FROM linea', 'SHOW KEYS FROM linea', 'SHOW INDEXES FROM linea', 'SHOW INDEXES FROM p'] as $q) {
        if ($o->consultar($q) !== $n->consultar($q)) { $mal[] = $q; }
    }
    $vista = array_map(static fn($f) => [$f['vista'], $f['sql']], $n->consultar('SHOW VIEWS')) === array_map(static fn($f) => [$f['vista'], $f['sql']], $o->consultar('SHOW VIEWS'));
    foreach (['SHOW CREATE TABLE p', 'SHOW CREATE TABLE linea'] as $q) {
        if ($o->consultar($q) !== $n->consultar($q)) { $mal[] = $q; }      // con su CHECK y su ENUM
    }
    unset($o, $n);
    Database::borrar('x', $a); Database::borrar('x', $b);
    return $mal === [] && $vista ?: json_encode([$mal, $vista]);
});

echo "\n== CHECK y ENUM (2.9) ==\n";
chk('CHECK de columna y de tabla: acepta y rechaza lo mismo que SQLite, también en UPDATE, y NULL pasa', function () {
    global $bd, $sqlite;
    $pasos = [
        'CREATE TABLE ck (id INTEGER PRIMARY KEY, n INTEGER CHECK (n >= 0), a INTEGER, b INTEGER, CONSTRAINT orden CHECK (a < b OR a IS NULL))',
        'INSERT INTO ck VALUES (1, 5, 1, 2)', 'INSERT INTO ck VALUES (2, -1, 1, 2)', 'INSERT INTO ck VALUES (3, NULL, 1, 2)',
        'INSERT INTO ck VALUES (4, 1, 5, 2)', 'INSERT INTO ck VALUES (5, 0, NULL, 9)', 'UPDATE ck SET n = -5 WHERE id = 1',
        'UPDATE ck SET a = 9 WHERE id = 1', 'UPDATE ck SET n = n + 1', 'INSERT INTO ck (id, n) VALUES (6, 0)',
    ];
    $mal = [];
    foreach ($pasos as $q) {
        try { $bd->consultar($q); $j = 'bien'; } catch (JsonSqlDbError) { $j = 'error'; }
        try { $sqlite->exec($q); $l = 'bien'; } catch (PDOException) { $l = 'error'; }
        if ($j !== $l) { $mal[] = "$q: aquí $j, SQLite $l"; }
    }
    $iguales = $bd->consultar('SELECT * FROM ck ORDER BY id') === $sqlite->query('SELECT * FROM ck ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $bd->consultar('DROP TABLE ck'); $sqlite->exec('DROP TABLE ck');
    return $mal === [] && $iguales ?: json_encode([$mal, $iguales]);
});
chk('ALTER TABLE ADD CONSTRAINT … CHECK se aplica de verdad: antes mira las filas que hay (también con huecos), y DROP CHECK lo quita', function () {
    global $bd;
    $bd->consultar('CREATE TABLE ck3 (id INTEGER PRIMARY KEY, n INTEGER)');
    $v = [];
    for ($i = 1; $i <= 40; $i++) { $v[] = "($i, " . ($i === 7 ? -3 : $i) . ')'; }
    $bd->consultar('INSERT INTO ck3 VALUES ' . implode(',', $v));
    $r = [error('ALTER TABLE ck3 ADD CONSTRAINT pos CHECK (n > 0)', 'no cumplen')];
    $bd->consultar('DELETE FROM ck3 WHERE id = 7');                      // deja un hueco
    $bd->consultar('ALTER TABLE ck3 ADD CONSTRAINT pos CHECK (n > 0)');
    $r[] = error('INSERT INTO ck3 VALUES (100, -1)', "CHECK 'pos'");
    $r[] = error('ALTER TABLE ck3 ADD CONSTRAINT pos CHECK (n < 999)', "Ya existe una restricción llamada 'pos'");
    $bd->consultar('ALTER TABLE ck3 DROP CHECK pos');
    $bd->consultar('INSERT INTO ck3 VALUES (100, -1)');
    $r[] = $bd->consultar('SELECT COUNT(*) AS n FROM ck3');
    $bd->consultar('DROP TABLE ck3');
    return $r === [true, true, true, [['n' => 40]]] ?: json_encode($r);
});
chk('no se puede borrar ni renombrar una columna que usa un CHECK', function () {
    global $bd;
    $bd->consultar('CREATE TABLE ck2 (id INTEGER PRIMARY KEY, n INTEGER CHECK (n >= 0), m INTEGER)');
    $r = error('ALTER TABLE ck2 DROP COLUMN n', 'CHECK') === true && error('ALTER TABLE ck2 RENAME COLUMN n TO x', 'CHECK') === true;
    $bd->consultar('ALTER TABLE ck2 DROP COLUMN m');                 // la que no lo usa, sí
    $bd->consultar('DROP TABLE ck2');
    return $r;
});
chk('ENUM como MySQL: solo sus valores, sin distinguir mayúsculas, y con NOT NULL el primero por defecto', function () {
    global $bd;
    $bd->consultar("CREATE TABLE en (id INTEGER PRIMARY KEY, estado ENUM('nuevo', 'pagado') NOT NULL, talla ENUM('S', 'M') DEFAULT 'M')");
    $bd->consultar('INSERT INTO en (id) VALUES (1)');
    $bd->consultar("INSERT INTO en VALUES (2, 'PAGADO', 's')");
    $r = [error("INSERT INTO en VALUES (3, 'otro', NULL)", 'no es un valor permitido'), error("UPDATE en SET talla = 'XL' WHERE id = 1", 'no es un valor permitido'),
          $bd->consultar('SELECT * FROM en ORDER BY id')];
    $bd->consultar('DROP TABLE en');
    return $r === [true, true, [['id' => 1, 'estado' => 'nuevo', 'talla' => 'M'], ['id' => 2, 'estado' => 'pagado', 'talla' => 'S']]] ?: json_encode($r);
});

echo "\n== EXPLAIN (2.9) ==\n";
chk('EXPLAIN dice cómo leyó cada tabla: por índice, en orden, recuento, menor y mayor, recorrido y JOIN, con sus filas', function () use ($raiz) {
    $dir = "$raiz/ex"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $b->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER, s TEXT)');
    $v = [];
    for ($i = 1; $i <= 3000; $i++) { $v[] = "($i, " . ($i % 50) . ", 's$i')"; }
    $b->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    $b->consultar('CREATE INDEX ix_n ON t (n)');
    $ver = static function (string $q) use ($b): array {
        return array_map(static fn($r) => [$r['tabla'], $r['acceso'], $r['indice'], $r['filas']], $b->consultar("EXPLAIN $q"));
    };
    $r = [
        $ver('SELECT * FROM t WHERE id = 7'),
        $ver('SELECT * FROM t WHERE id IN (7, 8, 99999)'),
        $ver('SELECT id FROM t ORDER BY id DESC LIMIT 2')[0][1] ?? null,
        $ver('SELECT COUNT(*) FROM t'),
        $ver('SELECT MAX(id) FROM t'),
        $ver("SELECT id FROM t WHERE s = 's5'"),
        $ver('SELECT t.id, u.id FROM t JOIN t u ON u.id = t.n WHERE t.id = 120'),
    ];
    $esperado = [
        [['t', 'índice (=)', 'auto_id', 1]],
        [['t', 'índice (IN)', 'auto_id', 2]],
        'índice, en orden descendente',
        [['t', 'recuento, sin leer filas', null, 3000]],
        [['t', 'menor y mayor del índice, sin leer filas', 'auto_id', null]],
        [['t', 'recorrido, sin decodificar las partes que no contienen el texto buscado', null, 3000]],
        [['t', 'índice (=)', 'auto_id', 1], ['t', 'JOIN, buscando cada fila en el índice', 'auto_id', 1]],
    ];
    // Sin la caché de resultados, ni para leer ni para guardar; y luego todo sigue igual
    $sinCache = glob("$dir/x/.cache/q.*.cache") ?: [];
    $despues  = $b->consultar('SELECT s FROM t WHERE id = 7');
    $noSelect = false;
    try { $b->consultar("EXPLAIN DELETE FROM t"); } catch (JsonSqlDbError $e) { $noSelect = str_contains($e->getMessage(), 'EXPLAIN solo admite SELECT'); }
    $intacta = $b->consultar('SELECT COUNT(*) AS n FROM t');
    unset($b);
    Database::borrar('x', $dir);
    return $r === $esperado && $sinCache === [] && $despues === [['s' => 's7']] && $noSelect && $intacta === [['n' => 3000]] ?: json_encode([$r, $sinCache, $despues, $noSelect]);
});
chk('un EXISTS correlacionado por una igualdad se resuelve una vez, como semi-join, y da lo mismo (2.9: se ejecutaba por cada fila de fuera)', function () use ($raiz) {
    $dir = "$raiz/ex3"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $b->consultar('CREATE TABLE c (id INTEGER PRIMARY KEY, n TEXT)');
    $b->consultar('CREATE TABLE p (id INTEGER PRIMARY KEY, cid INTEGER, t INTEGER)');
    $v = $w = [];
    for ($i = 1; $i <= 300; $i++) { $v[] = "($i, 'c$i')"; $w[] = '(' . $i . ', ' . ($i % 7 === 0 ? 'NULL' : $i % 150) . ', ' . ($i % 10) . ')'; }
    $b->consultar('INSERT INTO c VALUES ' . implode(',', $v));
    $b->consultar('INSERT INTO p VALUES ' . implode(',', $w));
    $q = 'SELECT COUNT(*) AS n FROM c WHERE EXISTS (SELECT 1 FROM p WHERE p.cid = c.id AND p.t > 4)';
    $n = $b->consultar($q)[0]['n'];
    $no = $b->consultar(str_replace('WHERE EXISTS', 'WHERE NOT EXISTS', $q))[0]['n'];
    $pasos = array_column($b->consultar("EXPLAIN $q"), 'acceso');
    unset($b);
    Database::borrar('x', $dir);
    // Los de p con t > 4 y cid no NULL: cid = i % 150 para i de 1 a 300, sin los múltiplos de 7
    $esperado = count(array_unique(array_filter(array_map(static fn($i) => $i % 10 > 4 && $i % 7 !== 0 && $i % 150 !== 0 ? $i % 150 : null, range(1, 300)))));
    return $n === $esperado && $no === 300 - $esperado && in_array('EXISTS, una vez para todas las filas de fuera (semi-join)', $pasos, true)
        ?: json_encode([$n, $no, $esperado, $pasos]);
});
chk('EXPLAIN cuenta las filas leídas en un JOIN por índice aunque la clave obligue a recorrer la tabla', function () use ($raiz) {
    $dir = "$raiz/ex2"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $b->consultar('CREATE TABLE l (id INTEGER PRIMARY KEY, i INTEGER)');
    $b->consultar('CREATE TABLE r (id INTEGER PRIMARY KEY, i INTEGER)');
    $b->consultar('INSERT INTO l VALUES (1, 1), (2, 2)');
    $v = [];
    for ($k = 1; $k <= 1600; $k++) { $v[] = "($k, " . ($k % 4) . ')'; }
    $b->consultar('INSERT INTO r VALUES ' . implode(',', $v));
    $b->consultar('CREATE INDEX r_i ON r (i)');
    $q = 'SELECT l.id, r.id FROM l JOIN r ON r.i = l.i';
    $n = count($b->consultar($q));
    $paso = $b->consultar("EXPLAIN $q")[1];
    unset($b);
    Database::borrar('x', $dir);
    return $n === 800 && $paso['indice'] === 'r_i' && $paso['filas'] >= $n ?: json_encode([$n, $paso]);
});

echo "\n== JOIN entre tipos distintos, comas y DISTINCT con decimales (2.9) ==\n";
// Antes un JOIN … ON entre un entero y un decimal o un texto numérico no
// devolvía nada (también en la 2.8), aunque el mismo = en el WHERE sí casaba
foreach (["CREATE TABLE tja (id INTEGER PRIMARY KEY, i INTEGER, f DOUBLE, t TEXT)", "CREATE TABLE tjb (id INTEGER PRIMARY KEY, i INTEGER, f DOUBLE, t TEXT)",
          "INSERT INTO tja VALUES (1, 1, 1.0, '1'), (2, 2, 2.5, 'x'), (3, NULL, NULL, 'X'), (4, 3, 0.30000000000000004, '2.5')",
          "INSERT INTO tjb VALUES (1, 1, 1.0, '1'), (2, 2, 2.0, 'X'), (3, 3, 2.5, '2'), (4, 4, 0.3, '3')"] as $q) {
    $bd->consultar($q);
    $sqlite->exec($q);
}
chk('JOIN … ON entre entero, decimal y texto numérico casa como SQLite (y como el mismo = en el WHERE)', function () use ($bd, $sqlite) {
    $mal = [];
    foreach (['tja.i = tjb.f', 'tja.f = tjb.i', 'tja.i = tjb.t', 'tja.t = tjb.i', 'tja.f = tjb.t', 'tja.t = tjb.f', 'tja.t = tjb.t', 'tja.f = tjb.f'] as $c) {
        $q   = "SELECT tja.id AS x, tjb.id AS y FROM tja JOIN tjb ON $c ORDER BY 1, 2";
        $sq  = $sqlite->query($q)->fetchAll(PDO::FETCH_NUM);
        $on  = array_map('array_values', $bd->consultar($q));
        $whe = array_map('array_values', $bd->consultar("SELECT tja.id AS x, tjb.id AS y FROM tja CROSS JOIN tjb WHERE $c OR 0 ORDER BY 1, 2"));
        if ($on !== $sq || $whe !== $sq) { $mal[$c] = [$on, $whe, $sq]; }
    }
    return $mal === [] ?: json_encode($mal);
});
chk('FROM a, b WHERE a.x = b.y se cruza por hash: el mismo resultado que JOIN … ON, sin probar cada par', function () use ($raiz) {
    $dir = "$raiz/comas"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $b->consultar('CREATE TABLE a (id INTEGER PRIMARY KEY, k INTEGER)');
    $b->consultar('CREATE TABLE c (id INTEGER PRIMARY KEY, k DOUBLE)');
    foreach (['a', 'c'] as $t) {
        $v = [];
        for ($k = 1; $k <= 3000; $k++) { $v[] = "($k, " . ($k % 1000) . ')'; }
        $b->consultar("INSERT INTO $t VALUES " . implode(',', $v));
    }
    $t0 = hrtime(true);
    $comas = $b->consultar('SELECT COUNT(*) AS n, SUM(a.id) AS s FROM a, c WHERE c.k = a.k AND a.id < 2000');
    $ms = (hrtime(true) - $t0) / 1e6;
    $join = $b->consultar('SELECT COUNT(*) AS n, SUM(a.id) AS s FROM a JOIN c ON c.k = a.k WHERE a.id < 2000');
    unset($b);
    Database::borrar('x', $dir);
    // Probando los 9 millones de pares tardaba segundos; por hash, milisegundos
    return $comas === $join && $comas[0]['n'] === 5997 && $ms < 1000 ?: json_encode([$comas, $join, $ms]);
});
chk('DISTINCT, GROUP BY, UNION e INTERSECT: 1 y 1.0 son el mismo valor, y 0.3 no es 0.30000000000000004 (como SQLite)', function () use ($bd, $sqlite) {
    // PDO de SQLite en PHP 8.0 da los decimales escritos con 15 cifras: los dos llegan como 0.3
    if (PHP_VERSION_ID < 80100) { return null; }
    $mal = [];
    foreach (['SELECT DISTINCT f FROM tja UNION ALL SELECT DISTINCT f FROM tjb ORDER BY 1', 'SELECT f FROM tja UNION SELECT f FROM tjb ORDER BY 1',
              'SELECT f, COUNT(*) FROM (SELECT f FROM tja UNION ALL SELECT f FROM tjb) u GROUP BY f ORDER BY 1',
              'SELECT i FROM tja INTERSECT SELECT f FROM tjb ORDER BY 1', 'SELECT f FROM tjb EXCEPT SELECT i FROM tja ORDER BY 1',
              'SELECT COUNT(DISTINCT f) FROM (SELECT f FROM tja UNION ALL SELECT i FROM tjb) u'] as $q) {
        $sq = $sqlite->query($q)->fetchAll(PDO::FETCH_NUM);
        $mo = array_map('array_values', $bd->consultar($q));
        if (json_encode($mo) !== json_encode($sq)) { $mal[$q] = [$mo, $sq]; }
    }
    return $mal === [] ?: json_encode($mal);
});

echo "\n== UPDATE y DELETE con otras tablas (2.9) ==\n";
/**
 * Cada sentencia sobre unas tablas recién hechas, aquí y en SQLite (o lo que
 * allí da lo mismo, si no tiene esa sintaxis): mismas filas devueltas o
 * cambiadas, o error en los dos, y las mismas tablas al terminar.
 *
 * @param list<array{0: string, 1: ?string}> $casos
 */
function escriturasComoSqlite(string $dir, array $inicio, array $casos): bool|string {
    $mal = [];
    foreach ($casos as [$q, $enSqlite]) {
        if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
        Database::crear('x', $dir);
        $b = new Database('x', $dir);
        $l = sqliteParaComparar();
        $l->exec('PRAGMA foreign_keys = ON');
        foreach ($inicio as $i) { $b->consultar($i); $l->exec($i); }
        try { $r = $b->consultar($q); $n = $r['filas'] ?? $r; } catch (JsonSqlDbError) { $n = 'error'; }   // con RETURNING, las filas
        try { $st = $l->query($enSqlite ?? $q); $m = $st->columnCount() > 0 ? $st->fetchAll(PDO::FETCH_ASSOC) : $st->rowCount(); } catch (PDOException) { $m = 'error'; }
        if (is_array($m)) { usort($m, fn($a, $c) => $a['id'] <=> $c['id']); usort($n, fn($a, $c) => $a['id'] <=> $c['id']); }
        $x = $y = [];
        foreach (array_unique(array_map(fn($i) => preg_match('/^CREATE TABLE (\w+)/', $i, $t) ? $t[1] : '', $inicio)) as $t) {
            if ($t !== '') { $x[] = $b->consultar("SELECT * FROM $t ORDER BY id"); $y[] = $l->query("SELECT * FROM $t ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); }
        }
        unset($b);
        if (json_encode([$n, $x]) !== json_encode([$m, $y])) { $mal[] = [$q, $n, $m, $x, $y]; }
    }
    Database::borrar('x', $dir);
    return $mal === [] ?: substr(json_encode($mal[0], JSON_UNESCAPED_UNICODE), 0, 900) . ' (' . count($mal) . ' distintas)';
}
$ujInicio = ["CREATE TABLE t (id INTEGER PRIMARY KEY, x INTEGER DEFAULT 7 CHECK (x IS NULL OR x < 1000), y TEXT UNIQUE)",
             "CREATE TABLE s (id INTEGER PRIMARY KEY, tid INTEGER, v INTEGER, w TEXT)",
             "CREATE TABLE h (id INTEGER PRIMARY KEY, tid INTEGER REFERENCES t(id) ON DELETE CASCADE, z TEXT)",
             "CREATE TABLE log (id INTEGER PRIMARY KEY, que TEXT)",
             "INSERT INTO t VALUES (1, 10, 'a'), (2, 20, 'b'), (3, 30, 'c'), (4, NULL, 'd')",
             "INSERT INTO s VALUES (1, 1, 100, 'x'), (2, 1, 200, 'y'), (3, 3, 300, 'z'), (4, 9, 900, 'w'), (5, 2, 2000, 'q')",
             "INSERT INTO h VALUES (1, 1, 'h1'), (2, 3, 'h3'), (3, 2, 'h2')"];
// Con varias parejas para una fila, SQLite no dice cuál gana; aquí, la primera
// del cruce. Lo que se compara en SQLite es eso dicho de otra forma
$primera = "UPDATE t SET x = (SELECT v FROM s WHERE s.tid = t.id ORDER BY s.id LIMIT 1) WHERE id IN (SELECT tid FROM s WHERE v < 1000)";
chk('UPDATE … FROM y UPDATE … JOIN como SQLite: alias, subconsultas, varias tablas, LEFT y RIGHT JOIN, y con varias parejas, la primera', function () use ($raiz, $ujInicio, $primera) {
    @mkdir("$raiz/uj", 0775, true);
    return escriturasComoSqlite("$raiz/uj", $ujInicio, [
        ["UPDATE t SET x = s.v FROM s WHERE s.tid = t.id AND s.v < 1000", $primera],
        ["UPDATE t AS a SET x = s.v FROM s WHERE s.tid = a.id AND s.v < 1000", $primera],
        ["UPDATE t JOIN s ON s.tid = t.id SET t.x = s.v WHERE s.v < 1000", $primera],
        ["UPDATE t SET x = s.v + t.x, y = s.w FROM s WHERE s.tid = t.id AND s.v BETWEEN 150 AND 999", null],
        ["UPDATE t SET x = 0 FROM s JOIN s AS s2 ON s2.tid = s.tid WHERE s.tid = t.id AND s2.id <> s.id", null],
        ["UPDATE t SET y = 'h' FROM (SELECT tid FROM s WHERE v >= 300) q WHERE q.tid = t.id", null],
        ["UPDATE t LEFT JOIN s ON s.tid = t.id AND s.v < 1000 SET t.x = s.v", "UPDATE t SET x = (SELECT v FROM s WHERE s.tid = t.id AND s.v < 1000 ORDER BY s.id LIMIT 1)"],
        ["UPDATE s JOIN t ON t.id = s.tid SET s.w = t.y", "UPDATE s SET w = t.y FROM t WHERE t.id = s.tid"],
        ["UPDATE t, s SET t.y = s.w WHERE t.id = s.tid AND s.v = 300", "UPDATE t SET y = s.w FROM s WHERE t.id = s.tid AND s.v = 300"],
        ["UPDATE t SET x = s.v FROM s RIGHT JOIN t AS t2 ON t2.id = s.tid AND s.v < 1000 WHERE t2.id = t.id",
         "UPDATE t SET x = (SELECT v FROM s WHERE s.tid = t.id AND s.v < 1000 ORDER BY s.id LIMIT 1)"],
        ["UPDATE t AS a SET x = b.x FROM t AS b WHERE b.id = a.id + 1", null],
        ["UPDATE t SET x = (SELECT COUNT(*) FROM h WHERE h.tid = s.tid) FROM s WHERE s.tid = t.id AND s.id > 1", null],
    ]);
});
chk('DELETE … USING y DELETE … JOIN como SQLite, también con LEFT JOIN y borrado en cascada', function () use ($raiz, $ujInicio) {
    @mkdir("$raiz/uj", 0775, true);
    return escriturasComoSqlite("$raiz/uj", $ujInicio, [
        ["DELETE FROM t USING s WHERE s.tid = t.id", "DELETE FROM t WHERE id IN (SELECT tid FROM s)"],
        ["DELETE FROM t AS a USING s WHERE s.tid = a.id AND s.v > 250", "DELETE FROM t WHERE id IN (SELECT tid FROM s WHERE v > 250)"],
        ["DELETE t FROM t JOIN s ON s.tid = t.id WHERE s.v > 250", "DELETE FROM t WHERE id IN (SELECT tid FROM s WHERE v > 250)"],
        ["DELETE FROM t USING t JOIN s ON s.tid = t.id", "DELETE FROM t WHERE id IN (SELECT tid FROM s)"],
        ["DELETE a.* FROM t a LEFT JOIN s ON s.tid = a.id WHERE s.id IS NULL", "DELETE FROM t WHERE id NOT IN (SELECT tid FROM s)"],
        ["DELETE FROM s USING t WHERE t.id = s.tid AND t.y IN ('a', 'c')", "DELETE FROM s WHERE tid IN (SELECT id FROM t WHERE y IN ('a', 'c'))"],
        ["DELETE FROM t AS a WHERE a.id > 2", null],
    ]);
});
chk('Con otras tablas, como siempre: CHECK, UNIQUE, DEFAULT, RETURNING y triggers (también uno que hace un UPDATE … FROM)', function () use ($raiz, $ujInicio) {
    @mkdir("$raiz/uj", 0775, true);
    $sinTriggers = escriturasComoSqlite("$raiz/uj", $ujInicio, [
        ["UPDATE t SET x = s.v FROM s WHERE s.tid = t.id AND s.id <> 2 RETURNING id, x", "UPDATE t SET x = s.v FROM s WHERE s.tid = t.id AND s.id <> 2 RETURNING id, x"],
        ["UPDATE t SET x = s.v FROM s WHERE s.tid = t.id", null],
        ["UPDATE t SET y = 'a' FROM s WHERE s.tid = t.id AND s.id = 3", null],
        ["UPDATE t SET x = DEFAULT, y = s.w || t.y FROM s WHERE s.tid = t.id AND s.v BETWEEN 150 AND 999",
         "UPDATE t SET x = 7, y = s.w || t.y FROM s WHERE s.tid = t.id AND s.v BETWEEN 150 AND 999"],
        ["DELETE FROM t USING s WHERE s.tid = t.id AND s.v > 200 RETURNING *", "DELETE FROM t WHERE id IN (SELECT tid FROM s WHERE v > 200) RETURNING *"],
    ]);
    $triggers = ["CREATE TRIGGER tu AFTER UPDATE ON t BEGIN INSERT INTO log (que) VALUES ('u' || OLD.id || ':' || COALESCE(OLD.x, 'n') || '>' || COALESCE(NEW.x, 'n')); END",
                 "CREATE TRIGGER td BEFORE DELETE ON t BEGIN INSERT INTO log (que) VALUES ('d' || OLD.id); END",
                 "CREATE TRIGGER sa AFTER INSERT ON s BEGIN UPDATE t SET y = y || '+' FROM s AS s2 WHERE s2.id = NEW.id AND s2.tid = t.id; END"];
    $conTriggers = escriturasComoSqlite("$raiz/uj", array_merge($ujInicio, $triggers), [
        ["UPDATE t SET x = s.v FROM s WHERE s.tid = t.id AND s.id <> 2 AND s.v < 1000", null],
        ["DELETE FROM t USING s WHERE s.tid = t.id AND s.v > 200", "DELETE FROM t WHERE id IN (SELECT tid FROM s WHERE v > 200)"],
        ["INSERT INTO s VALUES (6, 1, 0, 'n')", null],
        ["UPDATE t AS a SET x = b.x FROM t AS b WHERE b.id = a.id + 1", null],
    ]);
    return $sinTriggers === true && $conTriggers === true ?: json_encode([$sinTriggers, $conTriggers], JSON_UNESCAPED_UNICODE);
});
chk('Con otras tablas, cada fila se escribe en su sitio: con huecos de filas borradas, por índice y con la tabla destino a la derecha del JOIN', function () use ($raiz) {
    @mkdir("$raiz/uj", 0775, true);
    $v = $w = [];
    for ($i = 1; $i <= 3000; $i++) { $v[] = "($i, $i, 'y$i')"; }
    foreach ([5, 7, 8, 1500, 2999] as $k => $tid) { $w[] = '(' . ($k + 1) . ", $tid, " . (($k + 1) * 10) . ", 'w$k')"; }
    $inicio = ['CREATE TABLE t (id INTEGER PRIMARY KEY, x INTEGER, y TEXT)', 'CREATE TABLE s (id INTEGER PRIMARY KEY, tid INTEGER, v INTEGER, w TEXT)',
               'INSERT INTO t VALUES ' . implode(',', $v), 'INSERT INTO s VALUES ' . implode(',', $w), 'DELETE FROM t WHERE id % 3 = 0 OR id < 5'];
    return escriturasComoSqlite("$raiz/uj", $inicio, [
        ['UPDATE t SET x = s.v FROM s WHERE s.tid = t.id', null],
        ['UPDATE t SET y = s.w FROM s WHERE t.id IN (5, 7, 8, 2999) AND s.tid = t.id', null],
        ['UPDATE s JOIN t ON t.id = s.tid SET t.x = t.x + s.v', 'UPDATE t SET x = t.x + s.v FROM s WHERE t.id = s.tid'],
        ['DELETE FROM t USING s WHERE s.tid = t.id', 'DELETE FROM t WHERE id IN (SELECT tid FROM s)'],
    ]);
});
chk('Con otras tablas, una sola tabla destino y sin ORDER BY ni LIMIT: un error que lo explica', function () use ($bd) {
    $mal = [];
    foreach (['UPDATE tja JOIN tjb ON tjb.id = tja.id SET tja.i = 1, tjb.i = 2' => 'solo puede cambiar una tabla',
              'DELETE tja, tjb FROM tja JOIN tjb ON tjb.id = tja.id' => 'solo puede borrar de una tabla',
              'DELETE FROM tja, tjb USING tja JOIN tjb ON tjb.id = tja.id' => 'solo puede borrar de una tabla',
              'UPDATE tja JOIN tjb ON tjb.id = tja.id SET tja.i = 1 ORDER BY tja.id LIMIT 1' => 'ORDER BY y LIMIT no se admiten',
              'DELETE q FROM tja JOIN (SELECT 1 AS id) q ON q.id = tja.id' => 'es una subconsulta',
              'UPDATE tja JOIN tja ON 1 SET i = 1' => 'está dos veces',
              'UPDATE tja JOIN tjb ON 1 SET i = 1 FROM tjb' => 'no en los dos',
              'UPDATE tja SET x.i = 1' => "'x' no está en la sentencia"] as $q => $msg) {
        try { $bd->consultar($q); $mal[] = "$q: sin error"; }
        catch (JsonSqlDbError $e) { if (!str_contains($e->getMessage(), $msg)) { $mal[] = "$q: " . $e->getMessage(); } }
    }
    return $mal === [] ?: implode(' | ', $mal);
});

echo "\n== WITH RECURSIVE (2.9) ==\n";
foreach (["CREATE TABLE rt (x INTEGER)", "INSERT INTO rt VALUES (1),(2),(3)",
          "CREATE TABLE remp (id INTEGER PRIMARY KEY, jefe INTEGER, nombre TEXT)",
          "INSERT INTO remp VALUES (1, NULL, 'Ana'), (2, 1, 'Bea'), (3, 1, 'Carlos'), (4, 2, 'Dani'), (5, 4, 'Eva'), (6, 3, 'Fede')",
          "CREATE TABLE rar (a TEXT, b TEXT)", "INSERT INTO rar VALUES ('a','b'),('b','c'),('c','a'),('c','d')"] as $q) {
    $bd->consultar($q);
    $sqlite->exec($q);
}
chk('WITH RECURSIVE como SQLite: contar, LIMIT y OFFSET, UNION que corta ciclos, árboles, caminos, varias partes, columnas con nombre y sin RECURSIVE', function () use ($bd, $sqlite) {
    $mal = [];
    foreach (["WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n < 10) SELECT * FROM r",
              "WITH r AS (SELECT 1 AS n UNION ALL SELECT n+1 FROM r WHERE n < 3) SELECT * FROM r",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n < 10 LIMIT 4 OFFSET 1) SELECT * FROM r",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r LIMIT 5) SELECT * FROM r",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION SELECT n % 3 + 1 FROM r) SELECT * FROM r",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n < 3 UNION ALL SELECT n*10 FROM r WHERE n < 3) SELECT * FROM r",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT n+10 FROM r WHERE n < 10) SELECT * FROM r",
              "WITH RECURSIVE sub(id, nombre, nivel) AS (SELECT id, nombre, 0 FROM remp WHERE jefe IS NULL UNION ALL SELECT e.id, e.nombre, s.nivel + 1 FROM remp e JOIN sub s ON e.jefe = s.id) SELECT * FROM sub ORDER BY id",
              "WITH RECURSIVE cad(id, ruta) AS (SELECT id, nombre FROM remp WHERE id = 5 UNION ALL SELECT e.jefe, (SELECT nombre FROM remp x WHERE x.id = e.jefe) || '/' || c.ruta FROM cad c JOIN remp e ON e.id = c.id WHERE e.jefe IS NOT NULL) SELECT ruta FROM cad ORDER BY length(ruta) DESC LIMIT 1",
              "WITH RECURSIVE alc(n) AS (SELECT 'a' UNION SELECT rar.b FROM rar JOIN alc ON rar.a = alc.n) SELECT n FROM alc ORDER BY n",
              "WITH RECURSIVE f(n, a, b) AS (SELECT 1, 0, 1 UNION ALL SELECT n + 1, b, a + b FROM f WHERE n < 30) SELECT n, a FROM f WHERE n IN (10, 20, 30)",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 5) SELECT a.n, b.n FROM r a JOIN r b ON b.n = a.n * 2 ORDER BY 1",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 100) SELECT COUNT(*), SUM(n), MAX(n) FROM r",
              "WITH RECURSIVE Cnt(N) AS (SELECT 1 UNION ALL SELECT n + 1 FROM cnt WHERE N < 3) SELECT n FROM CNT",
              "WITH RECURSIVE r(n) AS (SELECT x FROM rt UNION ALL SELECT n + 10 FROM r WHERE n < 20) SELECT * FROM r",
              "SELECT x, (WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < rt.x) SELECT SUM(n) FROM r) AS s FROM rt",
              "WITH a(v) AS (SELECT 5), r(n) AS (SELECT v FROM a UNION ALL SELECT n - 1 FROM r WHERE n > 1) SELECT * FROM r",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n IN (SELECT x FROM rt)) SELECT * FROM r",
              "WITH RECURSIVE r(n, s) AS (SELECT 1, 'x' UNION ALL SELECT n + 1, s || 'x' FROM r WHERE n < 4) SELECT * FROM r",
              "WITH r(a, b) AS (SELECT 1, 2) SELECT b, a FROM r",
              "SELECT * FROM rt WHERE x IN (WITH RECURSIVE r(n) AS (SELECT 2 UNION ALL SELECT n + 1 FROM r WHERE n < 3) SELECT n FROM r)",
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n < 3) SELECT * FROM r UNION ALL SELECT * FROM r"] as $q) {
        $a = array_map('array_values', $bd->consultar($q));
        $b = $sqlite->query($q)->fetchAll(PDO::FETCH_NUM);
        // Sin ORDER BY, el orden no está dicho: por tandas puede no ser el de SQLite
        if (!str_contains($q, 'ORDER BY')) { sort($a); sort($b); }
        if ($a !== $b) { $mal[] = "$q: " . json_encode([$a, $b]); }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('WITH RECURSIVE: lo que por tandas daría otra cosa que en SQLite, o no termina, es un error que lo explica', function () {
    $mal = [];
    foreach (["WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT count(*) FROM r WHERE n < 3) SELECT * FROM r" => 'no admite agregados',
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT DISTINCT n+1 FROM r WHERE n < 3) SELECT * FROM r" => 'no admite agregados, GROUP BY ni DISTINCT',
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n IN (SELECT n FROM r) AND n < 3) SELECT * FROM r" => 'una sola vez, en su FROM',
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r JOIN r AS r2 ON 1 WHERE r.n < 3) SELECT * FROM r" => 'una sola vez, en su FROM',
              "WITH RECURSIVE r(a, b) AS (SELECT 1) SELECT * FROM r" => 'nombra 2 columnas y su consulta da 1',
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n < 3 UNION ALL SELECT 7) SELECT * FROM r" => 'van antes que las que sí',
              "WITH RECURSIVE r(n) AS (SELECT 1 INTERSECT SELECT n+1 FROM r WHERE n < 3) SELECT * FROM r" => 'solo vale UNION o UNION ALL',
              "WITH rt AS (SELECT * FROM rt WHERE x > 1) SELECT * FROM rt" => 'necesita antes una parte que no lo haga',
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM r WHERE n < 3 ORDER BY 1 DESC) SELECT * FROM r" => 'ORDER BY dentro',
              "WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT r.n+1 FROM rt LEFT JOIN r ON r.n = rt.x WHERE r.n < 3) SELECT * FROM r" => 'se rellena con NULL',
              "WITH a AS (SELECT * FROM b), b AS (SELECT * FROM a) SELECT * FROM a" => 'en círculo'] as $q => $texto) {
        if (($r = error($q, $texto)) !== true) { $mal[] = "$q: " . var_export($r, true); }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('WITH RECURSIVE en una vista, y 10.000 vueltas en menos de un segundo', function () use ($bd) {
    $bd->consultar('CREATE VIEW rv AS WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 10000) SELECT n FROM r');
    $t = hrtime(true);
    $r = $bd->consultar('SELECT COUNT(*) AS c, SUM(n) AS s FROM rv');
    $ms = (hrtime(true) - $t) / 1e6;
    $bd->consultar('DROP VIEW rv');
    return $r === [['c' => 10000, 's' => 50005000]] && $ms < 1000 ?: json_encode([$r, $ms]);
});

echo "\n== Funciones de ventana y FILTER (2.9) ==\n";
foreach (["CREATE TABLE vw (id INTEGER PRIMARY KEY, g TEXT, x INTEGER, f REAL)",
          "INSERT INTO vw VALUES (1,'a',10,1.5),(2,'a',NULL,2.5),(3,'a',20,NULL),(4,'b',10,0.5),(5,'b',10,1.0),(6,'b',30,2.0),(7,'c',NULL,NULL),(8,'a',20,4.0),(9,'b',5,0.25),(10,'c',7,3.5)",
          "CREATE TABLE vv (id INTEGER PRIMARY KEY, dia TEXT, importe INTEGER)",
          "INSERT INTO vv VALUES (1,'2026-01-01',100),(2,'2026-01-01',50),(3,'2026-01-02',70),(4,'2026-01-03',30),(5,'2026-01-03',20),(6,'2026-01-05',90)"] as $q) {
    $bd->consultar($q);
    $sqlite->exec($q);
}
/** Las mismas filas que SQLite, en el mismo orden; los decimales, a 9 cifras */
function filasComoSqlite(array $consultas): bool|string {
    global $bd, $sqlite;
    // Los decimales enormes, con 14 cifras: en PHP 8.0, PDO trae los de SQLite con 15
    $norma = static fn(array $r): string => json_encode(array_map(static fn(array $f): array => array_map(static fn($v) => is_float($v)
        ? (abs($v) >= 1e15 ? (float)sprintf('%.13e', $v) : round($v, 9)) : $v, array_values($f)), $r));
    $mal = [];
    foreach ($consultas as $q) {
        try { $a = $norma($bd->consultar($q)); } catch (Throwable $e) { $a = 'error: ' . $e->getMessage(); }
        $b = $norma($sqlite->query($q)->fetchAll(PDO::FETCH_ASSOC));
        if ($a !== $b) { $mal[] = "$q: $a / $b"; }
    }
    return $mal === [] ?: count($mal) . ' distintas; ' . substr(implode(' | ', $mal), 0, 1500);
}
chk('Cada función de ventana en cada clase de ventana, como SQLite: 23 funciones por 20 ventanas (particiones, ROWS, RANGE con y sin distancia, GROUPS, EXCLUDE, NULLS LAST)', function () {
    $fns = ['row_number()', 'rank()', 'dense_rank()', 'percent_rank()', 'cume_dist()', 'ntile(3)', 'lag(x)', 'lead(x, 2, -1)', 'lag(x, -1)', 'first_value(x)', 'last_value(x)', 'nth_value(x, 2)',
            'count(*)', 'count(x)', 'sum(x)', 'total(x)', 'avg(f)', 'min(x)', 'max(f)', 'group_concat(id)', "group_concat(id, '-')", 'sum(x) FILTER (WHERE f > 1)', 'count(*) FILTER (WHERE x IS NULL)'];
    $ventanas = ['()', '(ORDER BY id)', '(PARTITION BY g)', '(PARTITION BY g ORDER BY x)', '(PARTITION BY g ORDER BY x DESC, id)', '(ORDER BY x NULLS LAST, id)',
                 '(ORDER BY id ROWS BETWEEN 1 PRECEDING AND 1 FOLLOWING)', '(ORDER BY id ROWS 2 PRECEDING)', '(ORDER BY x ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)',
                 '(ORDER BY x RANGE BETWEEN 5 PRECEDING AND 5 FOLLOWING)', '(ORDER BY x DESC RANGE BETWEEN 5 PRECEDING AND CURRENT ROW)', '(ORDER BY x RANGE BETWEEN UNBOUNDED PRECEDING AND 0 FOLLOWING)',
                 '(ORDER BY x RANGE BETWEEN 1 FOLLOWING AND 10 FOLLOWING)', '(PARTITION BY g ORDER BY x GROUPS BETWEEN 1 PRECEDING AND CURRENT ROW)', '(ORDER BY x GROUPS BETWEEN 1 PRECEDING AND 1 FOLLOWING EXCLUDE TIES)',
                 '(ORDER BY id ROWS BETWEEN 2 PRECEDING AND 2 FOLLOWING EXCLUDE CURRENT ROW)', '(ORDER BY x ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING EXCLUDE GROUP)',
                 '(ORDER BY id ROWS BETWEEN 3 PRECEDING AND 1 PRECEDING)', '(ORDER BY id ROWS BETWEEN 1 FOLLOWING AND 3 FOLLOWING)', '(ORDER BY g RANGE CURRENT ROW)'];
    $q = [];
    foreach ($ventanas as $v) {
        foreach ($fns as $f) {
            $q[] = "SELECT id, $f OVER $v AS r FROM vw ORDER BY id";
        }
    }
    return filasComoSqlite($q);
});
chk('Ventanas con GROUP BY y HAVING, WINDOW con nombre, en el ORDER BY, con DISTINCT y LIMIT, en subconsultas, y sin ORDER BY en el orden de la primera, como SQLite', function () {
    return filasComoSqlite([
        "SELECT id, row_number() OVER (ORDER BY x) FROM vw",
        "SELECT id, row_number() OVER (ORDER BY x), rank() OVER (ORDER BY g) FROM vw",
        "SELECT id, rank() OVER (ORDER BY g), row_number() OVER (ORDER BY x) FROM vw",
        "SELECT id, sum(x) OVER (PARTITION BY g) FROM vw",
        "SELECT g, sum(x), rank() OVER (ORDER BY sum(x) DESC) FROM vw GROUP BY g ORDER BY g",
        "SELECT g, count(*) AS n, sum(count(*)) OVER () AS total FROM vw GROUP BY g ORDER BY g",
        "SELECT g, sum(x) AS s, sum(sum(x)) OVER (ORDER BY g) AS acumulado FROM vw GROUP BY g HAVING count(*) > 1 ORDER BY g",
        "SELECT id, rank() OVER w2 FROM vw WINDOW w2 AS (ORDER BY x)",
        "SELECT id, sum(x) OVER (w2 ROWS 1 PRECEDING) FROM vw WINDOW w2 AS (ORDER BY id)",
        "SELECT id, sum(x) OVER w3, avg(x) OVER w3 FROM vw WINDOW w2 AS (PARTITION BY g), w3 AS (w2 ORDER BY id) ORDER BY id",
        "SELECT id FROM vw ORDER BY row_number() OVER (ORDER BY x DESC)",
        "SELECT id, x FROM vw ORDER BY sum(x) OVER (PARTITION BY g) DESC, id",
        "SELECT DISTINCT g, count(*) OVER (PARTITION BY g) FROM vw ORDER BY g",
        "SELECT id, row_number() OVER (ORDER BY id) AS rn FROM vw ORDER BY rn DESC LIMIT 3",
        "SELECT id, count(*) OVER () FROM vw LIMIT 2",
        "SELECT * FROM (SELECT id, row_number() OVER (PARTITION BY g ORDER BY x DESC) AS rn FROM vw) t WHERE rn = 1 ORDER BY id",
        "SELECT dia, sum(importe) AS d, sum(sum(importe)) OVER (ORDER BY dia ROWS BETWEEN 1 PRECEDING AND CURRENT ROW) AS m2 FROM vv GROUP BY dia ORDER BY dia",
        "SELECT id, importe, importe - lag(importe, 1, 0) OVER (ORDER BY id) AS dif FROM vv ORDER BY id",
        "SELECT id, round(100.0 * importe / sum(importe) OVER (), 2) AS pct FROM vv ORDER BY id",
        "SELECT id, ntile(2) OVER (PARTITION BY g ORDER BY id), ntile(4) OVER (ORDER BY id) FROM vw ORDER BY id",
        "SELECT id, sum(x) OVER (PARTITION BY g ORDER BY id) + 1 AS s, CASE WHEN row_number() OVER (ORDER BY id) % 2 = 0 THEN 'par' ELSE 'impar' END AS t FROM vw ORDER BY id",
        "SELECT g, json_group_array(id) OVER (PARTITION BY g ORDER BY id) FROM vw ORDER BY id",
    ]);
});
chk('FILTER (WHERE …) en los agregados de siempre, también con GROUP BY y en el HAVING, como SQLite', function () {
    return filasComoSqlite([
        "SELECT count(*) FILTER (WHERE x > 10), sum(x) FILTER (WHERE g = 'b'), count(*) FROM vw",
        "SELECT count(*) FILTER (WHERE x > 10) FROM vw",
        "SELECT g, count(*) FILTER (WHERE x >= 10) AS n, sum(f) FILTER (WHERE f > 1) AS s FROM vw GROUP BY g ORDER BY g",
        "SELECT g, count(*) FROM vw GROUP BY g HAVING count(*) FILTER (WHERE x > 5) > 1 ORDER BY g",
        "SELECT g, group_concat(id) FILTER (WHERE x IS NOT NULL) FROM vw GROUP BY g ORDER BY g",
    ]);
});
chk('Funciones de ventana: lo que SQLite no admite, o no está soportado aquí, es un error que lo explica', function () {
    $mal = [];
    foreach (["SELECT id FROM vw WHERE row_number() OVER () > 1" => 'solo puede ir',
              "SELECT id FROM vw WHERE 0 AND row_number() OVER () > 1" => 'solo puede ir',
              "SELECT g FROM vw GROUP BY row_number() OVER ()" => 'solo puede ir',
              "UPDATE vw SET x = row_number() OVER ()" => 'solo puede ir',
              "SELECT count(DISTINCT x) OVER () FROM vw" => 'DISTINCT',
              "SELECT upper(g) OVER () FROM vw" => 'no admite OVER',
              "SELECT row_number() FILTER (WHERE x > 1) OVER () FROM vw" => 'FILTER solo vale',
              "SELECT upper(g) FILTER (WHERE x > 1) FROM vw" => 'FILTER solo vale',
              "SELECT sum(x) OVER (ROWS -1 PRECEDING) FROM vw" => 'no negativo',
              "SELECT sum(x) OVER (ROWS 1.5 PRECEDING) FROM vw" => 'un entero',
              "SELECT sum(x) OVER (ORDER BY id ROWS 9e999 PRECEDING) FROM vw" => 'un entero',
              "SELECT sum(x) OVER (ORDER BY id ROWS BETWEEN 1e19 PRECEDING AND CURRENT ROW) FROM vw" => 'un entero',
              "SELECT sum(x) OVER (ORDER BY x GROUPS 9e999 PRECEDING) FROM vw" => 'un entero',
              "SELECT sum(x) OVER (RANGE 1 PRECEDING) FROM vw" => 'ORDER BY de una sola expresión',
              "SELECT sum(x) OVER (ORDER BY x, id RANGE 1 PRECEDING) FROM vw" => 'ORDER BY de una sola expresión',
              "SELECT sum(x) OVER (ORDER BY id ROWS BETWEEN 1 FOLLOWING AND 1 PRECEDING) FROM vw" => 'no soportado',
              "SELECT sum(x) OVER (w2 ORDER BY id) FROM vw WINDOW w2 AS (PARTITION BY g ORDER BY x)" => 'no puede cambiar su ORDER BY',
              "SELECT sum(x) OVER (w2 PARTITION BY g) FROM vw WINDOW w2 AS (ORDER BY x)" => 'no puede cambiar su PARTITION BY',
              "SELECT sum(x) OVER (w2 ORDER BY id) FROM vw WINDOW w2 AS (ROWS 1 PRECEDING)" => 'ya tiene marco',
              "SELECT sum(x) OVER nada FROM vw" => "No hay ninguna ventana 'nada'",
              "SELECT ntile(0) OVER (ORDER BY id) FROM vw" => 'entero positivo',
              "SELECT nth_value(x, 0) OVER (ORDER BY id) FROM vw" => 'entero positivo',
              "SELECT row_number() FROM vw" => 'necesita OVER',
              "SELECT sum(row_number() OVER ()) OVER () FROM vw" => 'dentro de',
              "SELECT ntile() OVER () FROM vw" => 'lleva 1 argumento'] as $q => $texto) {
        if (($r = error($q, $texto)) !== true) { $mal[] = "$q: " . var_export($r, true); }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('Una suma corriente, un ranking por partición y una media móvil sobre 20.000 filas, en menos de 3 segundos', function () use ($raiz) {
    $dir = "$raiz/ven"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $b->consultar('CREATE TABLE v (id INTEGER PRIMARY KEY, cli INTEGER, importe INTEGER)');
    for ($i = 0; $i < 20000; $i += 2000) {
        $v = [];
        for ($j = $i + 1; $j <= $i + 2000; $j++) { $v[] = "($j, " . ($j % 200) . ', ' . ($j * 37 % 1000) . ')'; }
        $b->consultar('INSERT INTO v VALUES ' . implode(',', $v));
    }
    $t = hrtime(true);
    $r = $b->consultar('SELECT MAX(s) AS s, SUM(CASE WHEN r = 1 THEN 1 ELSE 0 END) AS primeros, COUNT(m) AS m FROM (SELECT SUM(importe) OVER (ORDER BY id) AS s,
        RANK() OVER (PARTITION BY cli ORDER BY importe DESC) AS r, AVG(importe) OVER (ORDER BY id ROWS 6 PRECEDING) AS m FROM v) t');
    $ms = (hrtime(true) - $t) / 1e6;
    $suma = 0;
    for ($j = 1; $j <= 20000; $j++) { $suma += $j * 37 % 1000; }
    unset($b);
    Database::borrar('x', $dir);
    return $r[0]['s'] === $suma && $r[0]['m'] === 20000 && $r[0]['primeros'] >= 200 && $ms < 3000 ?: json_encode([$r, $suma, $ms]);
});

echo "\n== COLLATE en las columnas (2.9) ==\n";
foreach (["CREATE TABLE cn (id INTEGER PRIMARY KEY, n TEXT COLLATE NOCASE, r TEXT COLLATE RTRIM, b TEXT)",
          "INSERT INTO cn VALUES (1,'Ana','a  ','Ana'),(2,'ANA','a','ana'),(3,'bob','A ','BOB'),(4,'Bob','b','bob'),(5,NULL,NULL,NULL),(6,'carla','c   ','Carla'),(7,'Dan','d ','DAN'),(8,'ana ','a','x'),(9,'zz','z ','ZZ'),(10,'Zz','z','zZ')",
          "CREATE TABLE cm (id INTEGER PRIMARY KEY, k TEXT COLLATE NOCASE, v INTEGER)",
          "INSERT INTO cm VALUES (1,'ANA',1),(2,'BOB',2),(3,'zed',3),(4,'ana ',4)",
          "CREATE INDEX cn_n ON cn (n)", "CREATE INDEX cn_r ON cn (r)", "CREATE VIEW vcn AS SELECT id, n, b, n || '' AS c FROM cn"] as $q) {
    $bd->consultar($q);
    $sqlite->exec($q);
}
chk('Con COLLATE NOCASE y RTRIM en la columna, como SQLite: comparaciones, IN con lista y con subconsulta, BETWEEN, CASE, ORDER BY, GROUP BY, DISTINCT, MIN y MAX, ventanas, JOIN, UNION, vistas, subconsultas y CTE que la heredan, NULLIF, CAST y COLLATE escrito encima', function () {
    return filasComoSqlite([
        "SELECT id FROM vcn WHERE n = 'ANA' ORDER BY id",
        "SELECT id FROM cm WHERE k = 'Ana ' ORDER BY id",
        "SELECT id FROM cm WHERE k IN ('bob', 'ZED') ORDER BY id",
        "SELECT id FROM cn WHERE 'BOB' BETWEEN n AND n ORDER BY id",
        "SELECT cn.id AS a, cm.id AS b FROM cn JOIN cm ON cm.k = cn.n AND cn.n IN (SELECT k FROM cm WHERE v < 3) ORDER BY 1, 2",
        "SELECT id FROM vcn WHERE c = 'ANA' ORDER BY id",
        "SELECT id FROM cn WHERE n = 'ANA' ORDER BY id",
        "SELECT id FROM cn WHERE 'ANA' = n ORDER BY id",
        "SELECT id FROM cn WHERE n <> 'ana' ORDER BY id",
        "SELECT id FROM cn WHERE n < 'B' ORDER BY id",
        "SELECT id FROM cn WHERE n >= 'BOB' ORDER BY id",
        "SELECT id FROM cn WHERE n BETWEEN 'a' AND 'BOB' ORDER BY id",
        "SELECT id FROM cn WHERE n IN ('ANA', 'CARLA') ORDER BY id",
        "SELECT id FROM cn WHERE n NOT IN ('ANA', 'CARLA') ORDER BY id",
        "SELECT id FROM cn WHERE n IN (SELECT k FROM cm) ORDER BY id",
        "SELECT id FROM cn WHERE b IN (SELECT k FROM cm) ORDER BY id",
        "SELECT id FROM cn WHERE n = b ORDER BY id",
        "SELECT id FROM cn WHERE b = n ORDER BY id",
        "SELECT id FROM cn WHERE b = 'ANA' ORDER BY id",
        "SELECT id FROM cn WHERE n = 'ANA' COLLATE BINARY ORDER BY id",
        "SELECT id FROM cn WHERE n COLLATE BINARY = 'ANA' ORDER BY id",
        "SELECT id FROM cn WHERE b = 'ANA' COLLATE NOCASE ORDER BY id",
        "SELECT id FROM cn WHERE r = 'a' ORDER BY id",
        "SELECT id FROM cn WHERE r = 'A' ORDER BY id",
        "SELECT id FROM cn WHERE r = 'c' ORDER BY id",
        "SELECT id FROM cn WHERE r IN ('a', 'ñ') ORDER BY id",
        "SELECT id FROM cn WHERE r > 'a' ORDER BY id",
        "SELECT id FROM cn WHERE n = 10 ORDER BY id",
        "SELECT id FROM cn WHERE n = '10' ORDER BY id",
        "SELECT id, CASE n WHEN 'ANA' THEN 1 WHEN 'BOB' THEN 2 ELSE 0 END FROM cn ORDER BY id",
        "SELECT id, n = 'ANA', b = 'ANA', n < 'b' FROM cn ORDER BY id",
        "SELECT id FROM cn ORDER BY n, id",
        "SELECT id FROM cn ORDER BY n DESC, id",
        "SELECT id FROM cn ORDER BY r, id",
        "SELECT id, n AS x FROM cn ORDER BY x, id",
        "SELECT count(*) FROM cn GROUP BY r ORDER BY 1",
        "SELECT count(DISTINCT n), count(DISTINCT r), count(DISTINCT b) FROM cn",
        "SELECT DISTINCT n FROM cn ORDER BY 1",
        "SELECT min(n), max(n), min(r), max(r), min(b), max(b) FROM cn",
        "SELECT id, rank() OVER (ORDER BY n) FROM cn ORDER BY id",
        "SELECT id, count(*) OVER (PARTITION BY n) FROM cn ORDER BY id",
        "SELECT count(*) FROM cn WHERE n = 'ana'",
        "SELECT count(*) FROM cn WHERE r = 'a'",
        "SELECT count(*) FROM cn WHERE n > 'b'",
        "SELECT id FROM cn WHERE n LIKE 'an%' ORDER BY id",
        "SELECT id FROM cn WHERE EXISTS (SELECT 1 FROM cm WHERE cm.k = cn.b) ORDER BY id",
        "SELECT id FROM cn WHERE (SELECT count(*) FROM cm WHERE cn.n = cm.k) > 0 ORDER BY id",
        "SELECT n FROM cn INTERSECT SELECT k FROM cm ORDER BY 1",
        "SELECT id FROM cn WHERE n IN (SELECT k FROM cm UNION SELECT 'carla') ORDER BY id",
        "SELECT id FROM cn WHERE n = 'ANA' AND id > 1 ORDER BY id",
        "SELECT id FROM cn WHERE n IS 'ANA' ORDER BY id",
        "SELECT id FROM cn WHERE nullif(n, 'ANA') IS NULL ORDER BY id",
        "SELECT id FROM cn WHERE n IN ('ana') ORDER BY id",
        "SELECT group_concat(DISTINCT n) FROM (SELECT n FROM cn ORDER BY id) s",
        "SELECT cn.id AS a, cm.id AS b FROM cn JOIN cm ON cn.n = cm.k ORDER BY 1, 2",
        "SELECT cn.id AS a, cm.id AS b FROM cn JOIN cm ON cm.k = cn.b ORDER BY 1, 2",
        "SELECT cn.id AS a, cm.id AS b FROM cn JOIN cm ON cn.b = cm.k ORDER BY 1, 2",
        "SELECT cn.id AS a, cm.id AS b FROM cn, cm WHERE cn.n = cm.k ORDER BY 1, 2",
        "SELECT cn.id AS a, cm.id AS b FROM cn LEFT JOIN cm ON cn.n = cm.k ORDER BY 1, 2",
        "SELECT cn.id AS a, cm.id AS b FROM cm JOIN cn ON cm.k = cn.r ORDER BY 1, 2",
        "SELECT x.id AS a, y.id AS b FROM cn x JOIN cn y ON x.n = y.b ORDER BY 1, 2",
        "SELECT x.id AS a, y.id AS b FROM cn x JOIN cn y ON x.b = y.n ORDER BY 1, 2",
        "SELECT lower(n), count(*) FROM cn WHERE id <> 7 GROUP BY n ORDER BY 1",
        "SELECT lower(n) FROM (SELECT n FROM cn WHERE id <> 7 UNION SELECT k FROM cm) s ORDER BY 1",
        "SELECT count(*) FROM (SELECT b FROM cn UNION SELECT k FROM cm) s",
        "SELECT count(*) FROM (SELECT 'ana' UNION SELECT k FROM cm) s",
        "SELECT lower(x) FROM (SELECT n AS x FROM cn INTERSECT SELECT k FROM cm) s ORDER BY 1",
        "SELECT lower(x) FROM (SELECT n AS x FROM cn WHERE id <> 7 EXCEPT SELECT k FROM cm) s ORDER BY 1",
        "SELECT count(*) FROM (SELECT b FROM cn EXCEPT SELECT k FROM cm) s",
        "SELECT k FROM cm UNION ALL SELECT n FROM cn ORDER BY 1, 1",
        "SELECT 'ANA' IN (SELECT k FROM cm), 'ana' IN (SELECT b FROM cn), 'ANA' IN (SELECT k FROM cm UNION SELECT 'x')",
        "SELECT id FROM cn WHERE upper(b) IN (SELECT k FROM cm) ORDER BY id",
        "SELECT id FROM cn WHERE b IN (SELECT k FROM cm) ORDER BY id",
        "SELECT id FROM cn WHERE b COLLATE NOCASE IN (SELECT k FROM cm) ORDER BY id",
        "SELECT id FROM cn WHERE n COLLATE BINARY IN (SELECT k FROM cm) ORDER BY id",
        "SELECT id FROM cn WHERE n IN (SELECT * FROM (SELECT k FROM cm) s) ORDER BY id",
        "SELECT id FROM cn WHERE CAST(n AS TEXT) = 'ANA' ORDER BY id",
        "SELECT id FROM cn WHERE b || 'x' COLLATE NOCASE = 'ANAX' ORDER BY id",
        "SELECT id FROM (SELECT id, n AS z FROM cn) s WHERE z = 'ANA' ORDER BY id",
        "WITH w AS (SELECT n FROM cn) SELECT count(*) FROM w WHERE n = 'ana'",
        "WITH w(z) AS (SELECT n FROM cn) SELECT count(*) FROM w WHERE z = 'ana'",
        "SELECT nullif(n, 'ANA'), max(n, 'B'), min('B', n), max(b, 'B'), min(n, 'ANA'), max(n, 'ANA') FROM cn ORDER BY id",
        "SELECT (SELECT k FROM cm WHERE id = 1) = 'ana'",
        "SELECT id FROM cn WHERE n = (SELECT 'ana') ORDER BY id",
        "SELECT id FROM cn WHERE substr(n, 1) = 'ANA' ORDER BY id",
        "SELECT DISTINCT b COLLATE NOCASE FROM cn ORDER BY 1",
        "SELECT b COLLATE NOCASE AS x FROM cn ORDER BY x, id",
        "SELECT b COLLATE NOCASE AS x FROM cn ORDER BY 1, id",
        "SELECT count(*) FROM cn GROUP BY b COLLATE NOCASE ORDER BY 1",
        "SELECT id FROM cn WHERE coalesce(n, '') = 'ANA' ORDER BY id",
        "SELECT id, CASE b COLLATE NOCASE WHEN 'ANA' THEN 1 ELSE 0 END FROM cn ORDER BY id",
        "SELECT id, CASE 'ANA' WHEN n THEN 1 ELSE 0 END FROM cn ORDER BY id",
        "SELECT id FROM cn WHERE 'b' BETWEEN n AND n ORDER BY id",
        "WITH RECURSIVE r(x) AS (SELECT k FROM cm UNION SELECT upper(x) FROM r) SELECT count(*) FROM r",
        "SELECT n COLLATE NOCASE, n = 'x' COLLATE NOCASE FROM cn WHERE id = 1",
        "SELECT id FROM cn WHERE n IN (SELECT k FROM cm WHERE cm.v = cn.id) ORDER BY id",
        "SELECT id FROM cn WHERE b IN (SELECT n FROM cn WHERE id = 2) ORDER BY id",
        "SELECT id, count(*) OVER (PARTITION BY b COLLATE NOCASE) FROM cn ORDER BY id",
        "SELECT id, row_number() OVER (ORDER BY n, id) FROM cn ORDER BY id",
        "SELECT 'Ñu' = 'ñu' COLLATE NOCASE, 'Ñu' = 'ÑU' COLLATE NOCASE, 'a ' = 'a' COLLATE RTRIM, ' a' = 'a' COLLATE RTRIM"
    ]);
});
chk('Por un índice con COLLATE, como SQLite: ORDER BY con LIMIT, MIN y MAX, COUNT con =, <, BETWEEN e IN, y con COLLATE BINARY encima', function () use ($raiz) {
    $dir = "$raiz/colo"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $l = sqliteParaComparar();
    foreach (['CREATE TABLE g (id INTEGER PRIMARY KEY, k TEXT COLLATE NOCASE, r TEXT COLLATE RTRIM, b TEXT)', 'CREATE INDEX g_k ON g (k)', 'CREATE INDEX g_r ON g (r)'] as $q) {
        $b->consultar($q); $l->exec($q);
    }
    $v = [];
    $w = ['b', 'A', 'c', 'B', 'a', 'D', 'C', 'e', 'E', 'd'];
    for ($j = 1; $j <= 3000; $j++) {
        $x = $w[$j % 10] . ($j % 7);
        $v[] = "($j, '$x', '" . strtolower($x) . str_repeat(' ', $j % 3) . "', '$x')";
    }
    $q = 'INSERT INTO g VALUES ' . implode(',', $v); $b->consultar($q); $l->exec($q);
    $mal = [];
    foreach (['SELECT id, k FROM g ORDER BY k, id LIMIT 7', 'SELECT id FROM g ORDER BY k DESC, id LIMIT 7', 'SELECT id FROM g ORDER BY r, id LIMIT 5',
              'SELECT lower(min(k)), lower(max(k)), min(r), max(r) FROM g', "SELECT count(*) FROM g WHERE k = 'A3'", "SELECT count(*) FROM g WHERE k > 'd'",
              "SELECT count(*) FROM g WHERE k BETWEEN 'b' AND 'C'", "SELECT count(*) FROM g WHERE r = 'a3'", "SELECT count(*) FROM g WHERE r < 'b'",
              "SELECT id FROM g WHERE k = 'A3' ORDER BY id LIMIT 3", "SELECT id FROM g WHERE k IN ('a3', 'E1') ORDER BY id LIMIT 5",
              'SELECT count(DISTINCT k), count(DISTINCT r), count(DISTINCT b) FROM g', 'SELECT lower(k), count(*) FROM g GROUP BY k ORDER BY 1 LIMIT 5',
              "SELECT id FROM g WHERE k >= 'e' ORDER BY k, id LIMIT 4", "SELECT count(*) FROM g WHERE k = 'A3' COLLATE BINARY"] as $q) {
        $x = json_encode(array_map('array_values', $b->consultar($q)));
        $y = json_encode($l->query($q)->fetchAll(PDO::FETCH_NUM));
        if ($x !== $y) { $mal[] = "$q: $x / $y"; }
    }
    unset($b);
    Database::borrar('x', $dir);
    return $mal === [] ?: implode(' | ', $mal);
});
chk('UNIQUE, PRIMARY KEY y claves foráneas con COLLATE, y UPDATE y DELETE por sus índices, como SQLite (y los índices siguen encontrando las filas)', function () use ($raiz) {
    $dir = "$raiz/colw"; @mkdir($dir, 0775, true);
    $inicio = ["CREATE TABLE p (id INTEGER PRIMARY KEY, code TEXT COLLATE NOCASE UNIQUE, r TEXT COLLATE RTRIM UNIQUE, n TEXT, t TEXT COLLATE NOCASE)",
               "CREATE TABLE h (id INTEGER PRIMARY KEY, pc TEXT REFERENCES p(code) ON UPDATE CASCADE ON DELETE CASCADE, z TEXT)",
               "CREATE TABLE k (c TEXT COLLATE NOCASE PRIMARY KEY, v INTEGER)",
               "CREATE TABLE m (id INTEGER PRIMARY KEY, a TEXT COLLATE NOCASE, b TEXT, UNIQUE (a, b))",
               "CREATE INDEX m_a ON m (a)",
               "INSERT INTO p VALUES (1, 'AbC', 'x', 'uno', 'alfa'), (2, 'def', 'y  ', 'dos', 'Beta'), (3, 'GHI', 'z', 'tres', 'GAMMA')",
               "INSERT INTO h VALUES (1, 'abc', 'h1'), (2, 'DEF', 'h2'), (3, 'ghi', 'h3'), (4, NULL, 'h4')",
               "INSERT INTO k VALUES ('Uno', 1), ('dos', 2)",
               "INSERT INTO m VALUES (1, 'Ana', 'x'), (2, 'BOB', 'x'), (3, 'ana', 'y')"];
    $casos = ["INSERT INTO p VALUES (4, 'ABC', 'w', 'cuatro', NULL)", "INSERT INTO p VALUES (4, 'abd', 'x ', 'cuatro', NULL)", "INSERT INTO p VALUES (4, 'abd', 'X', 'cuatro', NULL)",
              "UPDATE p SET n = 'Y' WHERE t = 'ALFA'", "DELETE FROM p WHERE t IN ('beta', 'gamma')",
              "INSERT INTO h VALUES (5, 'GhI', 'h5')", "INSERT INTO h VALUES (5, 'ghj', 'h5')", "INSERT INTO k VALUES ('UNO', 3)", "INSERT INTO k VALUES ('tres', 3)",
              "INSERT INTO m VALUES (4, 'ANA', 'x')", "INSERT INTO m VALUES (4, 'ANA', 'X')", "INSERT OR REPLACE INTO k VALUES ('DOS', 22)",
              "INSERT INTO k VALUES ('DOS', 9) ON CONFLICT (c) DO UPDATE SET v = excluded.v + 100", "INSERT OR IGNORE INTO p VALUES (4, 'def', 'q', 'cuatro', NULL)",
              "UPDATE p SET n = 'X' WHERE code = 'ABC'", "UPDATE p SET n = 'X' WHERE code IN ('ABC', 'GHI')", "UPDATE p SET n = 'X' WHERE r = 'y'",
              "UPDATE p SET code = 'abc' WHERE id = 1", "UPDATE p SET code = 'Def' WHERE id = 1", "UPDATE p SET code = 'XYZ' WHERE code = 'abc'",
              "DELETE FROM p WHERE code = 'DEF'", "DELETE FROM p WHERE code > 'B'", "DELETE FROM m WHERE a = 'ANA'", "UPDATE m SET b = 'z' WHERE a = 'ana' AND b = 'x'",
              "UPDATE m SET a = 'ANA' WHERE id = 2", "UPDATE k SET v = v + 1 WHERE c = 'uno'", "DELETE FROM k WHERE c IN (SELECT pc FROM h)",
              "DELETE FROM p WHERE 'ABC' IN (SELECT code FROM p)", "UPDATE m AS q SET b = 'w' WHERE q.a = 'BOB'"];
    $despues = ["SELECT id FROM p WHERE code = 'abc' OR r = 'x  ' ORDER BY id", "SELECT id FROM p WHERE code = 'DEF'", "SELECT count(*) FROM m WHERE a = 'ANA'", "SELECT c FROM k WHERE c = 'DOS'"];
    $mal = [];
    foreach ($casos as $q) {
        if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
        Database::crear('x', $dir);
        $b = new Database('x', $dir);
        $l = sqliteParaComparar();
        $l->exec('PRAGMA foreign_keys = ON');
        foreach ($inicio as $i) { $b->consultar($i); $l->exec($i); }
        try { $b->consultar($q); $x = ['ok']; } catch (JsonSqlDbError) { $x = ['error']; }
        try { $l->exec($q); $y = ['ok']; } catch (PDOException) { $y = ['error']; }
        foreach (['p' => 'id', 'h' => 'id', 'k' => 'c', 'm' => 'id'] as $t => $o) {
            $x[] = array_map('array_values', $b->consultar("SELECT * FROM $t ORDER BY $o"));
            $y[] = $l->query("SELECT * FROM $t ORDER BY $o")->fetchAll(PDO::FETCH_NUM);
        }
        foreach ($despues as $d) {
            $x[] = array_map('array_values', $b->consultar($d));
            $y[] = $l->query($d)->fetchAll(PDO::FETCH_NUM);
        }
        unset($b);
        if (json_encode($x) !== json_encode($y)) { $mal[] = "$q: " . json_encode($x) . ' / ' . json_encode($y); }
    }
    Database::borrar('x', $dir);
    return $mal === [] ?: count($mal) . ' distintos; ' . substr(implode(' | ', $mal), 0, 1500);
});
chk('Un JOIN por índice con COLLATE, como SQLite: el índice sirve si tiene la colación con la que se compara, o si se compara sin ella; si no, va por hash', function () use ($raiz) {
    $dir = "$raiz/colj"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $l = sqliteParaComparar();
    foreach (['CREATE TABLE g (id INTEGER PRIMARY KEY, k TEXT COLLATE NOCASE, kb TEXT, r TEXT COLLATE RTRIM)', 'CREATE INDEX g_k ON g (k)', 'CREATE INDEX g_kb ON g (kb)',
              'CREATE INDEX g_r ON g (r)', 'CREATE TABLE s (id INTEGER PRIMARY KEY, k TEXT COLLATE NOCASE, kb TEXT)',
              "INSERT INTO s VALUES (1, 'k7', 'K7'), (2, 'K8', 'k8'), (3, 'zz', 'ZZ'), (4, 'K9 ', 'K9 ')"] as $q) {
        $b->consultar($q); $l->exec($q);
    }
    for ($i = 0; $i < 6000; $i += 2000) {
        $v = [];
        for ($j = $i + 1; $j <= $i + 2000; $j++) {
            $v[] = "($j, '" . ($j % 2 ? 'K' : 'k') . ($j % 1000) . "', '" . ($j % 3 ? 'K' : 'k') . ($j % 1000) . "', '" . ($j % 1000) . str_repeat(' ', $j % 3) . "')";
        }
        $q = 'INSERT INTO g VALUES ' . implode(',', $v); $b->consultar($q); $l->exec($q);
    }
    $mal = [];
    // consulta => el índice que tiene que usar (null: ninguno)
    foreach (['SELECT s.id, g.id FROM s JOIN g ON g.k = s.k ORDER BY 1, 2' => 'g_k', 'SELECT s.id, g.id FROM s JOIN g ON s.kb = g.k ORDER BY 1, 2' => 'g_k',
              'SELECT s.id, g.id FROM s JOIN g ON s.k = g.kb ORDER BY 1, 2' => null, 'SELECT s.id, g.id FROM s JOIN g ON g.kb = s.k ORDER BY 1, 2' => 'g_kb',
              'SELECT s.id, g.id FROM s LEFT JOIN g ON s.k = g.kb ORDER BY 1, 2' => null, 'SELECT s.id, g.id FROM s JOIN g ON s.k = g.r ORDER BY 1, 2' => null,
              'SELECT s.id, g.id FROM s JOIN g ON g.r = substr(s.k, 2) ORDER BY 1, 2' => null, 'SELECT s.id, g.id FROM s JOIN g ON g.kb = s.kb COLLATE NOCASE ORDER BY 1, 2' => null,
              'SELECT s.id, g.id FROM s JOIN g ON g.k = s.k AND g.kb = s.kb ORDER BY 1, 2' => 'g_k', 'SELECT s.id, g.id FROM s, g WHERE g.k = s.k ORDER BY 1, 2' => 'g_k'] as $q => $indice) {
        $x = json_encode(array_map('array_values', $b->consultar($q)));
        $y = json_encode($l->query($q)->fetchAll(PDO::FETCH_NUM));
        $usado = $b->consultar("EXPLAIN $q")[1]['indice'] ?? null;
        if ($x !== $y || $usado !== $indice) { $mal[] = "$q: $x / $y, índice " . var_export($usado, true); }
    }
    unset($b);
    Database::borrar('x', $dir);
    return $mal === [] ?: implode(' | ', $mal);
});
chk('Un JOIN por una columna NOCASE de 20.000 por 10.000 filas, por hash, en menos de 2 segundos (sin él, 467 s)', function () use ($raiz) {
    $dir = "$raiz/colh"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $b->consultar('CREATE TABLE a (id INTEGER PRIMARY KEY, k TEXT COLLATE NOCASE)');
    $b->consultar('CREATE TABLE c (id INTEGER PRIMARY KEY, k TEXT COLLATE NOCASE)');
    foreach (['a' => [20000, 'K'], 'c' => [10000, 'k']] as $t => [$n, $l]) {
        for ($i = 0; $i < $n; $i += 2000) {
            $v = [];
            for ($j = $i + 1; $j <= $i + 2000; $j++) { $v[] = "($j, '$l" . ($j % 5000) . "')"; }
            $b->consultar("INSERT INTO $t VALUES " . implode(',', $v));
        }
    }
    $t = hrtime(true);
    $r = $b->consultar('SELECT count(*) AS n FROM a JOIN c ON a.k = c.k');
    $ms = (hrtime(true) - $t) / 1e6;
    unset($b);
    Database::borrar('x', $dir);
    return $r === [['n' => 40000]] && $ms < 2000 ?: json_encode([$r, $ms]);
});
chk('ALTER TABLE con COLLATE: MODIFY rehace los índices con ella y no deja repetidos en un UNIQUE ni hijas sin su fila; ADD UNIQUE, PRIMARY KEY y FOREIGN KEY, CHECK KEYS y SHOW la tienen en cuenta', function () use ($raiz) {
    $dir = "$raiz/cola"; @mkdir($dir, 0775, true);
    if (is_dir("$dir/x")) { Database::borrar('x', $dir); }
    Database::crear('x', $dir);
    $b = new Database('x', $dir);
    $r = static function (string $q) use ($b) {
        try { return $b->consultar($q); } catch (JsonSqlDbError $e) { return 'error: ' . $e->getMessage(); }
    };
    foreach (["CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT, b TEXT)", "INSERT INTO t VALUES (1, 'a', 'x'), (2, 'A', 'y'), (3, 'b', 'x')", "CREATE INDEX t_n ON t (n)",
              "CREATE TABLE p (c TEXT COLLATE NOCASE PRIMARY KEY)", "INSERT INTO p VALUES ('ABC'), ('Def')", "CREATE TABLE h (id INTEGER PRIMARY KEY, pc TEXT)",
              "INSERT INTO h VALUES (1, 'abc'), (2, 'DEF')", "CREATE TABLE q (id INTEGER, c TEXT COLLATE NOCASE)", "INSERT INTO q VALUES (1, 'x'), (2, 'X')",
              "CREATE TABLE cp (a INTEGER, b TEXT, PRIMARY KEY (a, b))", "INSERT INTO cp VALUES (1, 'x'), (2, 'x')"] as $q) {
        $b->consultar($q);
    }
    $esperado = [
        'ALTER TABLE t MODIFY n TEXT COLLATE NOCASE' => 'ok',
        "SELECT id FROM t WHERE n = 'A' ORDER BY id" => [['id' => 1], ['id' => 2]],
        "SELECT count(*) AS c FROM t WHERE n = 'a'" => [['c' => 2]],
        "EXPLAIN SELECT id FROM t WHERE n = 'A'" => 't_n',
        'ALTER TABLE t ADD UNIQUE (n)' => 'error: Los datos de \'t\' repiten',
        'ALTER TABLE t ADD UNIQUE (n, b)' => 'ok',
        'ALTER TABLE t MODIFY n TEXT COLLATE NOCASE UNIQUE' => "error: 't.n' tiene valores repetidos",
        'ALTER TABLE t MODIFY n TEXT' => 'ok',
        "SELECT id FROM t WHERE n = 'A' ORDER BY id" . ' ' => [['id' => 2]],
        "UPDATE t SET b = 'x' WHERE id = 2" => 'ok',
        'ALTER TABLE t MODIFY n TEXT COLLATE NOCASE' . ' ' => 'error: Los datos de \'t\' repiten',
        'ALTER TABLE h ADD FOREIGN KEY (pc) REFERENCES p (c)' => 'ok',
        'CHECK KEYS' => [],
        'ALTER TABLE p MODIFY c TEXT' => "error: Los datos de 'h' apuntan",
        'ALTER TABLE p MODIFY c TEXT COLLATE RTRIM' => "error: Los datos de 'h' apuntan",
        'ALTER TABLE q ADD PRIMARY KEY (c)' => "error: Los datos de 'q' repiten",
        'ALTER TABLE q ADD PRIMARY KEY (id, c)' => 'ok',
        // Antes de 2.9, una columna de una clave primaria compuesta se miraba sola, y aquí 'x' se repite
        'ALTER TABLE cp MODIFY b VARCHAR(5)' => 'ok',
        'SHOW CREATE TABLE p' => "CREATE TABLE p (\n  c TEXT COLLATE NOCASE PRIMARY KEY\n)",
        'CREATE TABLE e (n VARCHAR(10) COLLATE nocase NOT NULL, m TEXT COLLATE BINARY, o TEXT COLLATE RTRIM)' => 'ok',
        'SHOW COLUMNS FROM e' => ['NOCASE', null, 'RTRIM'],
        'CREATE TABLE e2 (n INTEGER COLLATE NOCASE)' => 'error: COLLATE solo vale en columnas de texto',
    ];
    $mal = [];
    foreach ($esperado as $q => $e) {
        $x = $r(trim($q));
        $x = match (true) {
            is_string($x) => $x,
            str_starts_with(trim($q), 'EXPLAIN') => $x[0]['indice'],
            str_starts_with(trim($q), 'SHOW CREATE') => $x[0]['sql'],
            str_starts_with(trim($q), 'SHOW COLUMNS') => array_column($x, 'colacion'),
            isset($x['success']) => 'ok',
            default => $x,
        };
        if (is_string($e) && str_starts_with($e, 'error: ') ? !is_string($x) || !str_starts_with($x, $e) : $x !== $e) {
            $mal[] = "$q: " . json_encode($x, JSON_UNESCAPED_UNICODE);
        }
    }
    unset($b);
    Database::borrar('x', $dir);
    return $mal === [] ?: implode(' | ', $mal);
});

echo "\n== Arreglos de la auditoría de la 2.9 (consultas) ==\n";
// Las mismas tablas en jsonSQLDB y en SQLite
$preparar = static function (array $sentencias) use ($bd, $sqlite): void {
    foreach ($sentencias as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
};
chk('NATURAL JOIN y JOIN … USING con las columnas escritas: el ON ve las comunes aunque la consulta no las nombre, como SQLite (2.9)', function () use ($preparar) {
    $preparar(['CREATE TABLE au_a (id INTEGER PRIMARY KEY, nombre TEXT, k TEXT)', 'CREATE TABLE au_b (id INTEGER PRIMARY KEY, aid INTEGER, v INTEGER, k TEXT)',
        'CREATE TABLE au_c (cid INTEGER PRIMARY KEY, k TEXT, w TEXT)', "INSERT INTO au_a VALUES (1, 'n1', 'k1'), (2, 'n2', 'k2'), (3, 'n3', 'k0')",
        "INSERT INTO au_b VALUES (1, 1, 10, 'k1'), (2, 1, 20, 'k9'), (3, 2, 30, 'k2')", "INSERT INTO au_c VALUES (1, 'k2', 'w2'), (2, 'k1', 'w1')"]);
    return filasComoSqlite([
        'SELECT au_a.id, au_b.id AS bid, au_b.v FROM au_a NATURAL JOIN au_b ORDER BY 1, 2', 'SELECT au_a.id, au_b.v FROM au_a NATURAL JOIN au_b ORDER BY 1, 2',
        'SELECT au_a.id, au_b.v FROM au_a JOIN au_b USING (k) ORDER BY 1, 2', 'SELECT au_a.id, au_b.v FROM au_a JOIN au_b USING (id, k) ORDER BY 1, 2',
        'SELECT au_a.nombre FROM au_a LEFT JOIN au_b USING (k) ORDER BY 1', 'SELECT au_c.w FROM au_a JOIN au_b USING (k) JOIN au_c USING (k) ORDER BY 1',
        'SELECT x.nombre FROM (SELECT * FROM au_a) x NATURAL JOIN au_b ORDER BY 1',
    ]);
});
chk('ORDER BY un alias escrito con AS que se llama como una columna ordena por el alias en todos los caminos (LIMIT, índice, DISTINCT, GROUP BY, JOIN, COLLATE), y t.col y col + 0 por la columna, como SQLite (2.9)', function () use ($preparar) {
    $preparar(['CREATE TABLE au_s (id INTEGER PRIMARY KEY, saldo INTEGER NOT NULL)', 'CREATE INDEX au_s_saldo ON au_s (saldo)',
        'INSERT INTO au_s VALUES (1, 10), (2, 20), (3, 30), (4, 25)', 'CREATE TABLE au_t (id INTEGER PRIMARY KEY, g TEXT)',
        "INSERT INTO au_t VALUES (1, 'b'), (2, 'A'), (3, 'c'), (4, 'a')"]);
    return filasComoSqlite([
        'SELECT id, -saldo AS saldo FROM au_s ORDER BY saldo', 'SELECT id, -saldo AS saldo FROM au_s ORDER BY saldo DESC',
        'SELECT id, -saldo AS saldo FROM au_s ORDER BY saldo LIMIT 2', 'SELECT id, -saldo AS saldo FROM au_s ORDER BY saldo DESC LIMIT 2 OFFSET 1',
        'SELECT id, -saldo AS saldo FROM au_s WHERE id > 1 ORDER BY saldo', 'SELECT DISTINCT -saldo AS saldo FROM au_s ORDER BY saldo',
        'SELECT -saldo AS saldo, COUNT(*) FROM au_s GROUP BY saldo ORDER BY saldo', 'SELECT saldo, -id AS id FROM au_s ORDER BY id LIMIT 3',
        'SELECT id, -saldo AS saldo FROM au_s ORDER BY au_s.saldo', 'SELECT id, -saldo AS saldo FROM au_s ORDER BY saldo + 0',
        'SELECT au_s.id, -au_s.saldo AS saldo FROM au_s JOIN au_t ON au_t.id = au_s.id ORDER BY saldo',
        'SELECT id, g AS saldo FROM au_t ORDER BY saldo COLLATE NOCASE, id', 'SELECT id, UPPER(g) AS g FROM au_t ORDER BY g DESC, id',
        'SELECT id, -saldo AS saldo, ROW_NUMBER() OVER (ORDER BY id) AS r FROM au_s ORDER BY saldo',
        'SELECT saldo AS id, id AS saldo FROM au_s ORDER BY id DESC LIMIT 2', 'SELECT id AS saldo FROM au_s ORDER BY saldo DESC LIMIT 1',
    ]);
});
chk('EXISTS con un agregado, OFFSET o una función de ventana en la subconsulta no va como semi-join, y da lo mismo que SQLite (2.9)', function () use ($preparar) {
    $preparar(['CREATE TABLE au_x (id INTEGER PRIMARY KEY)', 'CREATE TABLE au_y (id INTEGER PRIMARY KEY, xid INTEGER)',
        'INSERT INTO au_x VALUES (1), (2), (3)', 'INSERT INTO au_y VALUES (1, 1), (2, 1), (3, 2)']);
    return filasComoSqlite([
        'SELECT au_x.id FROM au_x WHERE EXISTS (SELECT COUNT(*) FROM au_y WHERE au_y.xid = au_x.id) ORDER BY 1',
        'SELECT au_x.id FROM au_x WHERE EXISTS (SELECT MAX(id) FROM au_y WHERE au_y.xid = au_x.id AND au_y.id > 5) ORDER BY 1',
        'SELECT au_x.id FROM au_x WHERE EXISTS (SELECT 1 FROM au_y WHERE au_y.xid = au_x.id LIMIT -1 OFFSET 1) ORDER BY 1',
        'SELECT au_x.id FROM au_x WHERE NOT EXISTS (SELECT 1 FROM au_y WHERE au_y.xid = au_x.id LIMIT -1 OFFSET 1) ORDER BY 1',
        'SELECT au_x.id FROM au_x WHERE EXISTS (SELECT 1 FROM au_y WHERE au_y.xid = au_x.id ORDER BY COUNT(*)) ORDER BY 1',
        'SELECT au_x.id FROM au_x WHERE EXISTS (SELECT ROW_NUMBER() OVER () FROM au_y WHERE au_y.xid = au_x.id) ORDER BY 1',
        'SELECT au_x.id FROM au_x WHERE EXISTS (SELECT 1 FROM au_y WHERE au_y.xid = au_x.id) ORDER BY 1',
    ]);
});
chk('~x compilado (en el WHERE, en SUM y en GROUP BY) es el complemento de bits, no -x, como SQLite (2.9)', function () use ($preparar) {
    $preparar(['CREATE TABLE au_n (id INTEGER PRIMARY KEY, x INTEGER)', 'INSERT INTO au_n VALUES (1, 5), (2, 6), (3, 7), (4, NULL)']);
    return filasComoSqlite([
        'SELECT id FROM au_n WHERE ~x = -6', 'SELECT id FROM au_n WHERE ~x = -7 OR id = 99', 'SELECT SUM(~x) FROM au_n',
        'SELECT ~x AS g, COUNT(*) FROM au_n GROUP BY ~x ORDER BY g', 'SELECT id FROM au_n WHERE ~x < -6 AND x > 0 ORDER BY id',
    ]);
});
chk('COLLATE escrito dentro de un agregado o una ventana cuenta: COUNT(DISTINCT), MIN, MAX y GROUP_CONCAT(DISTINCT), como SQLite (2.9)', function () use ($preparar) {
    $preparar(['CREATE TABLE au_g (id INTEGER PRIMARY KEY, g TEXT, v INTEGER)',
        "INSERT INTO au_g VALUES (1, 'a', 1), (2, 'a', 1), (3, 'b', 2), (4, NULL, 3), (5, 'c', NULL), (6, 'c', NULL), (7, 'B', 2)"]);
    return filasComoSqlite([
        'SELECT COUNT(DISTINCT g COLLATE NOCASE), MIN(g COLLATE NOCASE), MAX(g COLLATE RTRIM), GROUP_CONCAT(DISTINCT g COLLATE NOCASE) FROM au_g',
        'SELECT v, COUNT(DISTINCT g COLLATE NOCASE), MIN(g COLLATE NOCASE) FROM au_g GROUP BY v ORDER BY v',
        'SELECT id, MIN(g COLLATE NOCASE) OVER (ORDER BY id) FROM au_g ORDER BY id', 'SELECT COUNT(DISTINCT g), GROUP_CONCAT(DISTINCT g COLLATE BINARY) FROM au_g',
    ]);
});
chk('GLOB con [^]], []…], rangos, - al principio y al final, y un [ sin cerrar, como SQLite y sin avisos de PHP (2.9)', function () {
    $avisos = [];
    set_error_handler(static function (int $n, string $m) use (&$avisos): bool { $avisos[] = $m; return true; });
    try {
        $r = comoSqlite(["'a' GLOB '[^]]'", "']' GLOB '[^]]'", "']' GLOB '[]]'", "'a' GLOB 'a['", "'a' GLOB '['", "'a' GLOB 'x[y'", "'abc' GLOB 'ab['",
            "'z' GLOB '[z-a]'", "'m' GLOB '[z-a]'", "'-' GLOB '[a-]'", "'-' GLOB '[-a]'", "'-' GLOB '[a-c-e]'", "'d' GLOB '[a-c-e]'", "'é' GLOB '[á-ú]'",
            "']' GLOB '[]-a]'", "'^' GLOB '[]^]'", "'a.c' GLOB 'a[.*]c'", "'abc' GLOB 'a[^]b]c'", "'a[c' GLOB 'a[[]c'", "glob('*[^]]', 'ab')"]);
    } finally {
        restore_error_handler();
    }
    return $r === true && $avisos === [] ?: [$r, $avisos];
});
chk('Un patrón de LIKE o GLOB que PCRE no puede compilar (de cientos de miles de caracteres) es un error del motor, también la segunda vez: no se guarda (2.9: no casaba con nada, sin avisar)', function () {
    foreach (["SELECT 'ab' GLOB '" . str_repeat('[ab]?', 40000) . "'", "SELECT 'ab' LIKE '" . str_repeat('a_', 60000) . "'"] as $q) {
        for ($k = 0; $k < 2; $k++) {
            if (($e = error($q, 'Patrón de LIKE o GLOB no válido')) !== true) {
                return substr((string)$e, 0, 200);
            }
        }
    }
    return true;
});
chk('BETWEEN es x >= a AND x <= b con la lógica de tres valores: un extremo NULL no decide si el otro ya lo hace, como SQLite (2.9)', function () {
    $r = comoSqlite(['15 BETWEEN NULL AND 10', '15 NOT BETWEEN NULL AND 10', '5 BETWEEN NULL AND 10', '5 BETWEEN 10 AND NULL',
        '5 NOT BETWEEN 10 AND NULL', '15 BETWEEN 10 AND NULL', 'NULL BETWEEN 1 AND 2', '2 BETWEEN 1 AND 3']);
    return $r === true ? filasComoSqlite(['SELECT id FROM au_n WHERE x BETWEEN NULL AND 5 ORDER BY id', 'SELECT id FROM au_n WHERE x NOT BETWEEN NULL AND 5 ORDER BY id',
        'SELECT id FROM au_n WHERE x + 0 NOT BETWEEN 6 AND NULL ORDER BY id', 'SELECT id FROM au_n WHERE ABS(x) NOT BETWEEN NULL AND 5 ORDER BY id']) : $r;
});
chk('TRIM, LTRIM y RTRIM quitan caracteres, no bytes, y \'..\' no es un rango, también con el conjunto en una columna, como SQLite (2.9)', function () use ($preparar) {
    $r = comoSqlite(["TRIM('ñaña', 'ñ')", "TRIM('áxá', 'ñ')", "LENGTH(TRIM('áxá', 'ñ'))", "TRIM('abc', 'a..c')", "LTRIM('..a..', '.')",
        "RTRIM('ñandúú', 'úd')", "TRIM('€x€€', '€')", "TRIM(' x ')", "LTRIM('xxay', 'x')", "RTRIM('hola€€', '€')", "TRIM('a.b', '..')", "TRIM('ñxñ', 'ñ' || '')"]);
    if ($r !== true) { return $r; }
    $preparar(['CREATE TABLE au_tr (id INTEGER PRIMARY KEY, s TEXT, c TEXT)', "INSERT INTO au_tr VALUES (1, 'ñxñ', 'ñ'), (2, 'abc', 'a..c'), (3, 'xax', 'x'), (4, 'áxá', 'ñ')"]);
    return filasComoSqlite(['SELECT id, TRIM(s, c), LTRIM(s, c), RTRIM(s, c) FROM au_tr ORDER BY id']);
});
chk('STRFTIME(\'%W\') es la semana de SQLite (la 01 empieza el primer lunes), en todos los días de 2020 a 2031 (2.9)', function () use ($bd, $sqlite) {
    $v = [];
    for ($t = gmmktime(0, 0, 0, 1, 1, 2020); $t < gmmktime(0, 0, 0, 1, 1, 2032); $t += 86400) {
        $v[] = "('" . gmdate('Y-m-d', $t) . "')";
    }
    $bd->consultar('CREATE TABLE au_d (d TEXT)');
    $sqlite->exec('CREATE TABLE au_d (d TEXT)');
    foreach (array_chunk($v, 500) as $trozo) {
        $bd->consultar('INSERT INTO au_d VALUES ' . implode(',', $trozo));
        $sqlite->exec('INSERT INTO au_d VALUES ' . implode(',', $trozo));
    }
    return count($v) > 4000 ? filasComoSqlite(["SELECT d, STRFTIME('%W', d) FROM au_d ORDER BY d"]) : count($v);
});
chk('Los modificadores con decimales de segundo van al milisegundo, como SQLite: \'+0.5 seconds\' no suma un segundo entero (2.9)', fn() => comoSqlite([
    "DATETIME('2026-01-01 10:00:00', '+0.5 seconds')", "DATETIME('2026-01-01 10:00:00', '+1.6 seconds')", "STRFTIME('%f', '2026-01-01 10:00:00', '+0.5 seconds')",
    "DATETIME('2026-01-01 10:00:00', '-0.5 seconds')", "STRFTIME('%f', '2026-01-01 10:00:00', '+1.0004 seconds')", "STRFTIME('%f', '2026-01-01 10:00:00', '+1.0006 seconds')",
    "DATETIME('2026-03-01 10:00:00', '-1.5 hours')", "DATE('2026-01-31', '+1.5 days')", "STRFTIME('%f', '2026-01-01 10:00:00.250', '-0.75 seconds')",
    "DATETIME('2026-01-01', '+0.0001 days')", "UNIXEPOCH('2026-01-01 10:00:00', '+2.5 minutes')",
]));
chk('PRINTF con la bandera \',\' (miles) en %d, %i, %u y %f, con ancho, signo, - y 0, como SQLite (2.9)', fn() => comoSqlite([
    "PRINTF('%,d', 1234567)", "PRINTF('%,d', -1234567)", "PRINTF('%0,12d', 1234567)", "PRINTF('%-,12d|', 1234567)", "PRINTF('%,u', 1234567)",
    "PRINTF('%,i', 12345678901234)", "PRINTF('%,f', -1234.5)", "PRINTF('%0,12.1f', -1234.5)", "PRINTF('%-,12.1f|', 1234.5)", "PRINTF('%,.0f', 1234567)",
    "PRINTF('%,d', 0)", "PRINTF('%, d', -1234)", "PRINTF('%, d', 1234)", "PRINTF('%,12.1f', 1234.5)", "PRINTF('%,.3d', 5)", "PRINTF('%+,d', 1234567)",
    "PRINTF('%,10d', -12345)", "PRINTF('%,d', -9223372036854775808)", "PRINTF('%,x', 1234567)", "PRINTF('%,d', 999)", "PRINTF('%,d', 1000)",
]));
chk('El ORDER BY de un UNION no distingue mayúsculas en el nombre de la columna, como SQLite (2.9)', fn() => filasComoSqlite([
    'SELECT id FROM au_t UNION SELECT id FROM au_g ORDER BY ID DESC', 'SELECT id AS Nombre, g FROM au_t UNION ALL SELECT id, g FROM au_g ORDER BY nombre DESC, 2',
]));
chk('LIMIT 9223372036854775807 (el «sin límite» de un ORM) con OFFSET da las filas, por cada camino, como SQLite (2.9: TypeError)', fn() => filasComoSqlite([
    'SELECT id FROM au_s LIMIT 9223372036854775807 OFFSET 1', 'SELECT id FROM au_s ORDER BY saldo LIMIT 9223372036854775807 OFFSET 1',
    'SELECT id FROM au_s WHERE id > 0 LIMIT 9223372036854775807 OFFSET 2', 'SELECT id FROM au_s LIMIT 1 OFFSET 9223372036854775807',
    'SELECT id FROM au_s ORDER BY id DESC LIMIT 9223372036854775807 OFFSET 9223372036854775807', 'SELECT DISTINCT saldo FROM au_s ORDER BY saldo LIMIT 9223372036854775807 OFFSET 1',
    'SELECT id FROM au_s ORDER BY saldo DESC LIMIT 9223372036854775806 OFFSET 2',
]));
chk('Lo extremo pero válido da un error del motor o el resultado de SQLite y MySQL, nunca un error de PHP: GLOB, REPEAT, SPACE, TRUNCATE, UNIXEPOCH, JULIANDAY, DATE_ADD y MAKEDATE (2.9)', function () {
    foreach (["SELECT REPEAT('ab', 9223372036854775807)" => 'REPEAT(): el resultado pasaría de 16 MB', 'SELECT SPACE(9223372036854775807)' => 'SPACE(): el resultado'] as $q => $t) {
        if (($e = error($q, $t)) !== true) { return $e; }
    }
    $r = comoSqlite(["UNIXEPOCH('9999-12-31 23:59:59', '+1 second')", "JULIANDAY('9999-12-31', '+1 day')", "UNIXEPOCH('9999-12-31 23:59:59')",
        "UNIXEPOCH('1969-12-31 23:59:59.500')", "JULIANDAY('2026-03-05 10:20:30.250')", "UNIXEPOCH('2023-11-14 22:13:20')", "'abc' GLOB 'ab['"]);
    return $r === true ? comoMysql(["REPEAT('', 9223372036854775807)" => '', 'TRUNCATE(1.5, 400)' => 1.5, 'TRUNCATE(1.5, -400)' => 0.0, 'TRUNCATE(5, -400)' => 0,
        'TRUNCATE(1e300, 100)' => 1e300, "DATE_ADD('2026-01-01', INTERVAL 99999999999999 MONTH)" => null, "DATE_ADD('2026-01-01', INTERVAL 1e30 YEAR)" => null,
        "DATE_ADD('9999-12-31', INTERVAL 1 DAY)" => null, "DATE_ADD('2026-01-01', INTERVAL 1e30 SECOND)" => null, "DATE_ADD('0001-01-01', INTERVAL -13 MONTH)" => null,
        "DATE_SUB('2024-03-31', INTERVAL 1 MONTH)" => '2024-02-29', 'MAKEDATE(-5, 1)' => null, 'MAKEDATE(10000, 1)' => null, 'MAKEDATE(2026, 1e15)' => null,
        'MAKEDATE(2026, 32)' => '2026-02-01']) : $r;
});
chk('DATE_ADD y + INTERVAL dan los milisegundos que el motor lee y guarda en un DATETIME (2.9: seis cifras, y DATE() de eso era NULL)', function () use ($bd) {
    $r = comoMysql(["DATE(DATE_ADD('2026-01-01 10:00:00.500', INTERVAL 1 SECOND))" => '2026-01-01', "DATE_ADD('2026-01-01 10:00:00.500', INTERVAL 1 SECOND)" => '2026-01-01 10:00:01.500',
        "'2026-01-01 10:00:00.250' + INTERVAL 1 HOUR" => '2026-01-01 11:00:00.250', "DATE_ADD('2026-01-01 10:00:00', INTERVAL 1500 MICROSECOND)" => '2026-01-01 10:00:00.001',
        "YEAR(DATE_ADD('2026-12-31 23:59:59.999', INTERVAL 1 MICROSECOND))" => 2026]);
    if ($r !== true) { return $r; }
    $bd->consultar('CREATE TABLE au_f (id INTEGER PRIMARY KEY, f DATETIME)');
    $bd->consultar("INSERT INTO au_f VALUES (1, '2026-01-01 10:00:00.500')");
    $bd->consultar('UPDATE au_f SET f = DATE_ADD(f, INTERVAL 1 SECOND)');
    $bd->consultar('UPDATE au_f SET f = f + INTERVAL 1 DAY');
    $f = $bd->consultar('SELECT f FROM au_f')[0]['f'];
    return $f === '2026-01-02 10:00:01.500' ?: $f;
});
chk('STRCMP compara textos byte a byte, como MySQL: STRCMP(\'10\', \'9\') es -1 (2.9)', fn() => comoMysql([
    "STRCMP('10', '9')" => -1, "STRCMP('9', '10')" => 1, "STRCMP('a', 'b')" => -1, "STRCMP('x', 'x')" => 0, "STRCMP('1.0', '1')" => 1, 'STRCMP(NULL, 1)' => null,
]));
chk('LIKE con muchos % sobre un texto largo da lo mismo que SQLite, compilado, sin compilar y como función (2.9: pasado el límite de PCRE decía que no)', function () use ($bd, $sqlite) {
    $s = str_repeat('a', 30000) . 'c';
    $bd->consultar('CREATE TABLE au_l (id INTEGER PRIMARY KEY, s TEXT)');
    $bd->consultar('INSERT INTO au_l VALUES (1, ?)', [$s]);
    $sqlite->exec("CREATE TABLE au_l (id INTEGER PRIMARY KEY, s TEXT); INSERT INTO au_l VALUES (1, '$s')");
    $c = [];
    foreach ([str_repeat('%a', 30) . '%c', str_repeat('%a', 30) . '%b', str_repeat('%A', 30) . '%', '%' . str_repeat('a%', 30)] as $p) {
        array_push($c, "SELECT s LIKE '$p' FROM au_l", "SELECT UPPER(s) LIKE '$p' FROM au_l", "SELECT like('$p', s) FROM au_l", "SELECT s NOT LIKE '$p' FROM au_l");
    }
    $g = str_repeat('*a', 30) . '*c';
    return filasComoSqlite(array_merge($c, ["SELECT s GLOB '$g' FROM au_l", "SELECT glob('$g', s) FROM au_l"]));
});

echo "\n== Arreglos de la 2.10 ==\n";
chk('subconsultas correlacionadas a dos y tres niveles, con la del medio sin mirar fuera, con IN, con el mismo alias dentro y en UPDATE y DELETE, como SQLite (2.10: «Columna desconocida»)', function () use ($bd, $sqlite) {
    $sql = ['CREATE TABLE cn_a (id INTEGER PRIMARY KEY, x INTEGER)', 'CREATE TABLE cn_b (id INTEGER PRIMARY KEY, a_id INTEGER)',
            'CREATE TABLE cn_c (id INTEGER PRIMARY KEY, b_id INTEGER, x INTEGER)', 'CREATE TABLE cn_d (id INTEGER PRIMARY KEY, a INTEGER, x INTEGER)',
            'INSERT INTO cn_a VALUES (1, 10), (2, 20), (3, 10), (4, 30), (5, NULL)',
            'INSERT INTO cn_b VALUES (1, 1), (2, 1), (3, 2), (4, 3), (5, 3), (6, 4), (7, 9)',
            'INSERT INTO cn_c VALUES (1, 1, 10), (2, 2, 99), (3, 3, 20), (4, 4, 10), (5, 5, 30), (6, 6, 30), (7, 7, 10), (8, 1, 20)',
            'INSERT INTO cn_d VALUES (1, 1, 10), (2, 2, 20), (3, 3, 99), (4, 4, 30)'];
    foreach ($sql as $q) { $bd->consultar($q); $sqlite->exec($q); }
    $r = filasComoSqlite([
        'SELECT cn_a.id, (SELECT COUNT(*) FROM cn_b WHERE cn_b.a_id = cn_a.id AND EXISTS (SELECT 1 FROM cn_c WHERE cn_c.b_id = cn_b.id AND cn_c.x = cn_a.x)) AS n FROM cn_a ORDER BY cn_a.id',
        'SELECT cn_a.id, (SELECT COUNT(*) FROM cn_b WHERE EXISTS (SELECT 1 FROM cn_c WHERE cn_c.b_id = cn_b.id AND cn_c.x = cn_a.x)) AS n FROM cn_a ORDER BY cn_a.id',
        'SELECT cn_a.id, (SELECT COUNT(*) FROM cn_b WHERE cn_b.id IN (SELECT cn_c.b_id FROM cn_c WHERE cn_c.x > cn_a.x)) AS n FROM cn_a ORDER BY cn_a.id',
        'SELECT id FROM cn_a WHERE EXISTS (SELECT 1 FROM cn_b WHERE cn_b.a_id = cn_a.id AND EXISTS (SELECT 1 FROM cn_c WHERE cn_c.b_id = cn_b.id AND cn_c.x IN (SELECT cn_d.x FROM cn_d WHERE cn_d.a = cn_a.id))) ORDER BY id',
        'SELECT id FROM cn_a WHERE NOT EXISTS (SELECT 1 FROM cn_b WHERE NOT EXISTS (SELECT 1 FROM cn_c WHERE cn_c.b_id = cn_b.id AND cn_c.x = cn_a.x)) ORDER BY id',
        'SELECT t.id, (SELECT COUNT(*) FROM cn_b t WHERE EXISTS (SELECT 1 FROM cn_c WHERE cn_c.b_id = t.id AND cn_c.x = 10)) AS n FROM cn_a t ORDER BY t.id',
        'SELECT cn_a.id, (SELECT MAX((SELECT COUNT(*) FROM cn_c WHERE cn_c.b_id = cn_b.id AND cn_c.x >= cn_a.x)) FROM cn_b) AS n FROM cn_a ORDER BY cn_a.id',
    ]);
    if ($r !== true) { return $r; }
    foreach (['UPDATE cn_a SET x = x + (SELECT COUNT(*) FROM cn_b WHERE cn_b.a_id = cn_a.id AND EXISTS (SELECT 1 FROM cn_c WHERE cn_c.b_id = cn_b.id AND cn_c.x = cn_a.x))',
              'DELETE FROM cn_b WHERE EXISTS (SELECT 1 FROM cn_c WHERE cn_c.b_id = cn_b.id AND EXISTS (SELECT 1 FROM cn_a WHERE cn_a.id = cn_b.a_id AND cn_a.x = cn_c.x - 1))'] as $q) {
        $bd->consultar($q); $sqlite->exec($q);
    }
    return filasComoSqlite(['SELECT * FROM cn_a ORDER BY id', 'SELECT * FROM cn_b ORDER BY id']);
});
chk('TIME() de una hora sola, -> y ->> con un índice negativo, la precedencia de < > <= >= sobre = <> IS, lo que sigue a IS como una expresión entera, y COLLATE en IS, como SQLite (2.10: NULL, error, 0 = 1 < 2 daba 1, error, 0)', function () {
    return comoSqlite(["TIME('12:00:00')", "TIME('23:59:59.5')", "'[1,2,3]' -> -1", "'[1,2,3]' ->> -1", "'{\"a\":[1,2]}' -> '$.a' ->> -2",
                       '0 = 1 < 2', '1 = 2 > 1', '0 <> 1 >= 1', '1 = 1 > 0 = 1', '3 > 2 > 1', 'NULL IS 1 < 2', '0 IS 1 > 2', '1 IS NULL + 1', '2 IS 1 + 1', 'NULL IS NOT NULL = 0',
                       "'a' IS 'A' COLLATE NOCASE", "'a' IS NOT 'A' COLLATE NOCASE", "'a' COLLATE NOCASE IS 'A'", "'x' LIKE 'X' = 1", '5 & 3 = 1', '1 | 2 < 3', '1 << 2 < 5']);
});
chk('los nombres de tabla no distinguen mayúsculas, como SQLite: leer, escribir, CREATE IF NOT EXISTS, índices, claves foráneas, JOIN y DROP; dos tablas de antes que solo se distinguen por ellas piden el nombre exacto (2.10: «La tabla no existe»)', function () use ($raiz) {
    $dir = "$raiz/may";
    @mkdir($dir, 0775, true);
    Database::crear('m', $dir);
    $m = new Database('m', $dir);
    $l = sqliteParaComparar();
    $l->exec('PRAGMA foreign_keys = ON');
    $mal = [];
    foreach (['CREATE TABLE Foo (id INTEGER PRIMARY KEY, x TEXT)', "INSERT INTO FOO VALUES (1, 'a')", "INSERT INTO foo (id, x) VALUES (2, 'b')", 'CREATE TABLE foo (id INTEGER)',
              'CREATE TABLE IF NOT EXISTS FOO (id INTEGER)', 'CREATE INDEX ix_f ON FOO (x)', "UPDATE fOO SET x = 'z' WHERE id = 2", 'CREATE TABLE h (id INTEGER PRIMARY KEY, f INTEGER REFERENCES FOO (id))',
              'INSERT INTO h VALUES (1, 1)', 'INSERT INTO h VALUES (2, 9)', 'SELECT foo.x, FOO.id FROM Foo ORDER BY 2', 'SELECT f.x FROM foo f JOIN H ON H.f = f.id',
              'ALTER TABLE FOO ADD COLUMN y INTEGER', 'DELETE FROM FoO WHERE id = 2', 'SELECT * FROM foo', 'DROP TABLE H', 'DROP TABLE FOO', 'CREATE TABLE fOo (k INTEGER)'] as $q) {
        try { $r = $m->consultar($q); $a = isset($r[0]) || $r === [] ? json_encode($r) : 'ok'; } catch (JsonSqlDbError) { $a = 'error'; }
        try { $st = $l->query($q); $b = $st->columnCount() > 0 ? json_encode($st->fetchAll(PDO::FETCH_ASSOC)) : 'ok'; } catch (PDOException) { $b = 'error'; }
        if ($a !== $b) { $mal[] = "$q: $a / $b"; }
    }
    // Dos de antes de la 2.10, «Uno» y «UNO» (en un disco que distingue
    // mayúsculas): escritas tal cual, cada una la suya; si no, error
    $m->consultar('CREATE TABLE Uno (id INTEGER PRIMARY KEY)');
    $m->consultar('CREATE TABLE Dos (id INTEGER PRIMARY KEY)');
    $m->consultar('INSERT INTO Dos VALUES (2)');
    unset($m);
    foreach ((array)glob("$dir/m/Dos.*") as $f) { rename($f, "$dir/m/UNO" . substr(basename($f), 3)); }
    file_put_contents("$dir/m/UNO.meta.json", str_replace('"Dos"', '"UNO"', (string)file_get_contents("$dir/m/UNO.meta.json")));
    $m = new Database('m', $dir);
    if (is_file("$dir/m/uno.meta.json")) { return null; }                   // un disco que no las distingue
    $dos = static function (string $q) use ($m) { try { return $m->consultar($q); } catch (JsonSqlDbError $e) { return $e->getMessage(); } };
    if ($dos('SELECT * FROM Uno') !== [] || $dos('SELECT * FROM UNO') !== [['id' => 2]]
        || !str_starts_with((string)$dos('SELECT * FROM uno'), "Hay más de una tabla que se llama 'uno'")) { $mal[] = 'las dos de antes'; }
    unset($m);
    borrarArbol($dir);
    return $mal === [] ?: implode(' | ', $mal);
});
chk('mensajes que dicen qué falla: lo que sobra al final, information_schema y otro esquema delante de una tabla; main. vale, como en SQLite (2.10: «Solo se admite una sentencia por consulta»)', function () use ($bd) {
    $r = [error('SELECT 1 2', "Sobra lo que va desde '2'"), error('SELECT 1 FROM cn_a b c', "Sobra lo que va desde 'c'"),
          error('SELECT * FROM information_schema.tables', "No hay 'information_schema' en jsonSQLDB"), error('SELECT * FROM otra.cn_a', "'otra.' delante de una tabla"),
          $bd->consultar('SELECT COUNT(*) AS n FROM main.cn_a')[0]['n'] === 5 ?: 'main.'];
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('una coma con ON o USING junta como un JOIN, como SQLite; ORDER BY n COLLATE con n fuera de la consulta da error, como SQLite; y EXPLAIN de un UNION da un paso por SELECT (2.10: error, se aceptaba, y cada paso dos veces)', function () use ($bd) {
    $r = filasComoSqlite(['SELECT cn_a.id, cn_b.id AS b FROM cn_a, cn_b ON cn_b.a_id = cn_a.id ORDER BY 1, 2', 'SELECT x.id, y.id AS b FROM cn_a x, cn_b y ON y.a_id = x.id WHERE y.id > 2 ORDER BY 1, 2',
                          'SELECT id, x, b_id FROM cn_d, cn_c USING (id, x) ORDER BY id', 'SELECT cn_a.id FROM cn_a, cn_d USING (id) ORDER BY 1']);
    if ($r !== true) { return $r; }
    $e = error('SELECT id FROM cn_a ORDER BY 2 COLLATE NOCASE', 'ORDER BY 2');
    $pasos = array_column($bd->consultar('EXPLAIN SELECT id FROM cn_a UNION SELECT id FROM cn_b'), 'tabla');
    return $e === true && $pasos === ['cn_a', 'cn_b'] ?: json_encode([$e, $pasos], JSON_UNESCAPED_UNICODE);
});
chk('INSERT OR IGNORE salta las filas que no cumplen un NOT NULL o un CHECK y guarda las demás, también desde un SELECT; con ON CONFLICT DO NOTHING y OR REPLACE siguen siendo un error; como SQLite (2.10: error y ninguna fila)', function () use ($raiz) {
    return escriturasComoSqlite("$raiz/ig", ['CREATE TABLE ck (id INTEGER PRIMARY KEY, v INTEGER NOT NULL CHECK (v > 0), u TEXT UNIQUE)', "INSERT INTO ck VALUES (1, 5, 'a')",
                                              'CREATE TABLE src (id INTEGER PRIMARY KEY, v INTEGER, u TEXT)', "INSERT INTO src VALUES (2, -1, 'b'), (3, NULL, 'c'), (4, 9, 'a'), (5, 7, 'e')"], [
        ["INSERT OR IGNORE INTO ck VALUES (2, -1, 'b'), (3, NULL, 'c'), (4, 9, 'd'), (1, 3, 'x'), (5, 4, 'a')", null],
        ['INSERT OR IGNORE INTO ck SELECT * FROM src', null],
        ["INSERT OR IGNORE INTO ck (id, u) VALUES (6, 'f')", null],
        ["INSERT INTO ck VALUES (2, -1, 'b') ON CONFLICT DO NOTHING", null],
        ["INSERT OR REPLACE INTO ck VALUES (1, -1, 'z')", null],
    ]);
});
chk('UPDATE OR IGNORE deja como estaba la fila que no cumpliría un NOT NULL, un CHECK o una clave única; UPDATE OR REPLACE e INSERT OR REPLACE borran las filas con que chocan y ponen el valor por defecto de una columna NOT NULL; una fila que no entra no gasta su id sin AUTOINCREMENT; como SQLite (2.10: UPDATE OR era un error de sintaxis, el NULL un error y el id se gastaba)', function () use ($raiz) {
    $r = [escriturasComoSqlite("$raiz/uo", ["CREATE TABLE r (id INTEGER PRIMARY KEY, a TEXT NOT NULL DEFAULT 'd', u INTEGER UNIQUE, c INTEGER CHECK (c < 10))",
                                             "INSERT INTO r VALUES (1, 'x', 1, 1), (2, 'y', 2, 2), (3, 'z', 3, 3)",
                                             'CREATE TABLE hr (id INTEGER PRIMARY KEY, rid INTEGER REFERENCES r(id) ON DELETE CASCADE)',
                                             'INSERT INTO hr VALUES (1, 1), (2, 2), (3, 3)'], [
        ['INSERT OR REPLACE INTO r VALUES (4, NULL, 4, 4)', null],
        ['REPLACE INTO r (id, a, u) VALUES (2, NULL, 9)', null],
        ['UPDATE OR IGNORE r SET u = 3 WHERE id = 1', null],
        ['UPDATE OR IGNORE r SET c = c * 4', null],
        ['UPDATE OR IGNORE r SET a = NULL WHERE id = 2', null],
        ['UPDATE OR REPLACE r SET u = 3 WHERE id = 1', null],
        ['UPDATE OR REPLACE r SET a = NULL', null],
        ['UPDATE OR REPLACE r SET id = 3 WHERE id = 1', null],
        ['UPDATE OR REPLACE r SET c = 20 WHERE id = 1', null],
        ['UPDATE OR ABORT r SET c = 0 WHERE id = 2', null],
        ["INSERT OR IGNORE INTO r (a, u) VALUES ('p', 1), ('q', 5), ('s', 2), ('t', 6)", null],
        ["INSERT INTO r (a, u) VALUES ('p', 1), ('q', 5) ON CONFLICT (u) DO UPDATE SET a = excluded.a", null],
    ]), escriturasComoSqlite("$raiz/uo2", ["CREATE TABLE n (id INTEGER PRIMARY KEY, u TEXT UNIQUE, par TEXT REFERENCES n(u) ON DELETE CASCADE)",
                                             "INSERT INTO n VALUES (1, 'a', NULL), (2, 'b', 'a'), (3, 'c', 'b'), (4, 'd', 'c')"], [
        // La fila que cambia se va con la cascada de la que choca: no vuelve
        ["UPDATE OR REPLACE n SET u = 'b', par = NULL WHERE id = 3", null],
        ["UPDATE OR REPLACE n SET u = 'c' WHERE id = 1", null],
    ]), error('UPDATE OR FAIL r SET c = 0', 'UPDATE OR FAIL no está soportado: usa OR IGNORE u OR REPLACE')];
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('el siguiente id de una clave primaria entera sin AUTOINCREMENT es el mayor de los que quedan + 1, también cuando la misma sentencia borra con OR REPLACE la fila del mayor, con la tabla cargada y sin cargar: 100 sentencias al azar sobre 2.500 filas, como SQLite (2.10: salía de la fila ya borrada)', function () use ($raiz) {
    $mal = [];
    foreach ([false, true] as $cargada) {
        $dir = "$raiz/rowid" . (int)$cargada;
        @mkdir($dir, 0775, true);
        Database::crear('x', $dir);
        $b = new Database('x', $dir);
        $l = sqliteParaComparar();
        $inicio = ['CREATE TABLE t (id INTEGER PRIMARY KEY, u INTEGER UNIQUE, x TEXT)', 'CREATE TABLE lg (id INTEGER PRIMARY KEY, tid INTEGER)'];
        if ($cargada) {
            // Con un trigger, la tabla se carga entera; sin él, se escribe sin cargarla
            $inicio[] = 'CREATE TRIGGER tr AFTER INSERT ON t BEGIN INSERT INTO lg (tid) VALUES (NEW.id); END';
        }
        $inicio[] = 'INSERT INTO t VALUES ' . implode(', ', array_map(static fn(int $i): string => "($i, $i, 'a')", range(1, 2500)));
        foreach ($inicio as $q) { $b->consultar($q); $l->exec($q); }
        $foto = static fn(array $filas): string => implode(' ', array_map(static fn(array $f): string => implode(':', array_values($f)), $filas));
        mt_srand(2100 + (int)$cargada);
        for ($n = 0; $n < 100 && count($mal) < 3; $n++) {
            $max  = (int)$l->query('SELECT MAX(id) FROM t')->fetchColumn();
            $uMax = (int)$l->query("SELECT u FROM t WHERE id = $max")->fetchColumn();
            $nu   = mt_rand(1, 3000);
            $otro = mt_rand(1, $max);
            $q = match (mt_rand(0, 5)) {
                0 => "INSERT OR REPLACE INTO t VALUES (NULL, $nu, 'n'), ($otro, $nu, 'r'), (NULL, " . ($nu + 1) . ", 'n')",
                1 => "INSERT OR REPLACE INTO t VALUES ($otro, $uMax, 'r'), (NULL, $nu, 'n')",
                2 => "UPDATE OR REPLACE t SET u = $uMax WHERE id = $otro",
                3 => "DELETE FROM t WHERE id = $max",
                4 => "INSERT INTO t VALUES ($otro, " . (5000 + $n) . ", 'd'), (NULL, " . (6000 + $n) . ", 'n') ON CONFLICT (id) DO UPDATE SET id = " . ($max + 7),
                default => 'INSERT INTO t (u, x) VALUES (' . (3000 + $n) . ", 'n')",
            };
            $b->consultar($q);
            $l->exec($q);
            $resumen = 'SELECT MAX(id) AS m, COUNT(*) AS c, SUM(id * 7 + u) AS s FROM t';
            $x = $foto($b->consultar($resumen));
            $y = $foto(array_map(static fn(array $f): array => array_map('intval', $f), $l->query($resumen)->fetchAll(PDO::FETCH_ASSOC)));
            if ($x !== $y) { $mal[] = [(int)$cargada, $q, $x, $y]; }
        }
        foreach (['t', 'lg'] as $t) {
            $x = $foto($b->consultar("SELECT * FROM $t ORDER BY id"));
            $y = $foto(array_map(static fn(array $f): array => array_map(static fn($v) => is_numeric($v) ? (int)$v : $v, $f),
                $l->query("SELECT * FROM $t ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)));
            if ($x !== $y) { $mal[] = [(int)$cargada, $t, strlen($x), strlen($y)]; }
        }
        unset($b);
        Database::borrar('x', $dir);
    }
    return $mal === [] ?: json_encode($mal);
});
chk('las claves foráneas NO ACTION y las de una fila hija se miran al acabar la sentencia, como SQLite: INSERT OR REPLACE de un padre con hijas, borrar una tabla que se apunta a sí misma, una hija antes que su padre, un trigger que borra las hijas, una hija que se va y otra que llega, dos claves foráneas con el mismo nombre, el padre nuevo de una cascada que un trigger borra, lo que las cascadas de un OR REPLACE hacen apuntar a la fila que quita; RESTRICT, al momento; lo que sigue sin padre al acabar, error (2.10: error al momento); y las hijas que trae una cascada o un UPDATE sin cargar la tabla (2.10: no las veía, desde antes de la 2.9)', function () use ($raiz) {
    $ph = ['CREATE TABLE p (id INTEGER PRIMARY KEY, u TEXT UNIQUE)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id))',
           "INSERT INTO p VALUES (1, 'a'), (2, 'b')", 'INSERT INTO h VALUES (1, 1), (2, 2)'];
    $arbol = ['CREATE TABLE t (id INTEGER PRIMARY KEY, padre INTEGER REFERENCES t(id))', 'INSERT INTO t VALUES (1, NULL), (2, 1), (3, 2)'];
    $casos = [
        [$ph, "INSERT OR REPLACE INTO p VALUES (1, 'z')"],
        [array_merge($ph, ['CREATE INDEX ih ON h (p)']), "INSERT OR REPLACE INTO p VALUES (2, 'z'), (1, 'y')"],
        [$ph, "INSERT OR REPLACE INTO p VALUES (5, 'a')"],                                           // la hija se queda sin padre: error
        [$ph, 'UPDATE OR REPLACE p SET id = 2 WHERE id = 1'],                                         // la hija de 1, sin padre: error
        [array_merge($ph, ['DELETE FROM h WHERE id = 1']), 'UPDATE OR REPLACE p SET id = 2 WHERE id = 1'],
        [$ph, 'DELETE FROM p WHERE id = 1'],
        [array_merge($ph, ['CREATE TRIGGER tr AFTER DELETE ON p BEGIN DELETE FROM h WHERE p = OLD.id; END']), 'DELETE FROM p WHERE id = 1'],
        // El padre 1 sale dos veces y un trigger borra sus hijas entre medias
        [array_merge($ph, ['CREATE TRIGGER tr AFTER INSERT ON p BEGIN DELETE FROM h WHERE p = 1 AND NEW.id = 7; END']),
         "INSERT OR REPLACE INTO p VALUES (1, 'z'), (7, 'z')"],
        [str_replace('REFERENCES p(id)', 'REFERENCES p(id) ON DELETE RESTRICT', $ph), "INSERT OR REPLACE INTO p VALUES (1, 'z')"],
        [$arbol, 'DELETE FROM t'],
        [$arbol, 'DELETE FROM t WHERE id >= 2'],
        [$arbol, 'DELETE FROM t WHERE id <= 2'],
        [$arbol, 'UPDATE t SET id = id + 10, padre = padre + 10'],
        [$arbol, 'UPDATE t SET id = id + 10'],
        [['CREATE TABLE t (id INTEGER PRIMARY KEY, padre INTEGER REFERENCES t(id))'], 'INSERT INTO t VALUES (2, 3), (3, NULL)'],
        [['CREATE TABLE t (id INTEGER PRIMARY KEY, padre INTEGER REFERENCES t(id))'], 'INSERT INTO t VALUES (2, 3), (4, NULL)'],
        [$ph, 'INSERT OR REPLACE INTO h VALUES (5, 99), (5, 1)'],                                    // la hija sin padre sale con la siguiente
        [$ph, 'INSERT OR REPLACE INTO h VALUES (5, 1), (5, 99)'],
        [str_replace('REFERENCES p(id)', 'REFERENCES p(id) ON DELETE CASCADE', $ph) + [4 => 'CREATE TABLE g (id INTEGER PRIMARY KEY, h INTEGER REFERENCES h(id))',
                           5 => 'INSERT INTO g VALUES (1, 1)'], 'DELETE FROM p WHERE id = 1'],                  // la cascada deja a g sin padre: error
        [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER DEFAULT 9 REFERENCES p(id) ON DELETE SET DEFAULT)',
          'INSERT INTO p VALUES (1)', 'INSERT INTO h VALUES (1, 1)'], 'DELETE FROM p WHERE id = 1'],
        [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER DEFAULT 9 REFERENCES p(id) ON DELETE SET DEFAULT)',
          'INSERT INTO p VALUES (1), (9)', 'INSERT INTO h VALUES (1, 1)'], 'DELETE FROM p WHERE id = 1'],
        // La hija cambia en otra columna y luego se borra: no queda ninguna
        [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id), x TEXT)',
          'INSERT INTO p VALUES (1)', "INSERT INTO h VALUES (1, 1, 'a')",
          "CREATE TRIGGER tr AFTER DELETE ON p BEGIN UPDATE h SET x = 'b' WHERE p = OLD.id; DELETE FROM h WHERE p = OLD.id; END"], 'DELETE FROM p WHERE id = 1'],
        // Salen más hijas de las que se apuntaron, y luego llega una huérfana: error
        [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id))', 'CREATE TABLE cmd (id INTEGER PRIMARY KEY)',
          'INSERT INTO p VALUES (1)', 'INSERT INTO h VALUES (1, 1)',
          'CREATE TRIGGER tr AFTER INSERT ON cmd BEGIN DELETE FROM p WHERE id = 1; INSERT INTO p VALUES (1); INSERT INTO h VALUES (2, 1); DELETE FROM h; DELETE FROM p WHERE id = 1; INSERT INTO h VALUES (3, 1); END'],
         'INSERT INTO cmd VALUES (1)'],
        [['CREATE TABLE t (id INTEGER PRIMARY KEY, padre INTEGER REFERENCES t(id), u TEXT UNIQUE)', "INSERT INTO t VALUES (1, NULL, 'a'), (2, 1, 'b')"],
         "INSERT OR REPLACE INTO t VALUES (1, NULL, 'a'), (3, 1, 'c'), (2, NULL, 'b'), (3, NULL, 'c'), (4, NULL, 'a'), (5, 1, 'e')"],
        // Dos claves foráneas de la misma columna, con el mismo nombre: cada una con su padre
        [['CREATE TABLE p1 (id INTEGER PRIMARY KEY)', 'CREATE TABLE p2 (id INTEGER PRIMARY KEY)',
          'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER, FOREIGN KEY (p) REFERENCES p1(id), FOREIGN KEY (p) REFERENCES p2(id))',
          'INSERT INTO p1 VALUES (1), (2)', 'INSERT INTO p2 VALUES (1), (3)',
          'CREATE TRIGGER tr AFTER INSERT ON h WHEN NEW.id = 5 BEGIN INSERT INTO p2 VALUES (2); END'], 'INSERT INTO h VALUES (6, 2), (5, 3)'],
        // Una cascada ON UPDATE trae una hija al padre 3 sin cargar la tabla hija, y OR REPLACE lo borra: error (desde antes de la 2.9)
        [['CREATE TABLE p (id INTEGER PRIMARY KEY, u TEXT UNIQUE)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON UPDATE CASCADE)',
          "INSERT INTO p VALUES (1, 'a'), (2, 'b')", 'INSERT INTO h VALUES (1, 1)'],
         "UPDATE OR REPLACE p SET id = CASE id WHEN 1 THEN 3 ELSE id END, u = CASE id WHEN 2 THEN 'a' ELSE u END"],
        [['CREATE TABLE p (id INTEGER PRIMARY KEY, u TEXT UNIQUE)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON UPDATE CASCADE)',
          "INSERT INTO p VALUES (1, 'a'), (2, 'b')", 'INSERT INTO h VALUES (1, 1)', 'CREATE INDEX ih ON h (p)'],
         "UPDATE OR REPLACE p SET id = CASE id WHEN 1 THEN 3 ELSE id END, u = CASE id WHEN 2 THEN 'a' ELSE u END"],
        // Cascadas encadenadas por triggers: la hija que trae una la mueve la siguiente
        [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON UPDATE CASCADE)', 'CREATE INDEX ih ON h (p)',
          'INSERT INTO p VALUES (1), (2), (3), (7)', 'INSERT INTO h VALUES (10, 1), (20, 2), (30, 3)',
          'CREATE TRIGGER t1 AFTER UPDATE ON p WHEN OLD.id = 1 BEGIN UPDATE p SET id = 8 WHERE id = 2; END',
          'CREATE TRIGGER t2 AFTER UPDATE ON p WHEN OLD.id = 2 BEGIN UPDATE p SET id = 9 WHERE id = 8; UPDATE h SET p = 7 WHERE id = 30; UPDATE p SET id = 6 WHERE id = 7; END'],
         'UPDATE p SET id = 4 WHERE id = 1'],
        // El UPDATE de h, sin cargarla, le da un padre que una cascada de vuelta cambia después
        [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON UPDATE CASCADE)', 'CREATE INDEX ih ON h (p)',
          'CREATE TABLE g (id INTEGER PRIMARY KEY, h INTEGER REFERENCES h(id) ON UPDATE CASCADE, x INTEGER)',
          'INSERT INTO p VALUES (5), (6)', 'INSERT INTO h VALUES (0, NULL), (1, NULL), (2, NULL), (3, NULL)', 'INSERT INTO g VALUES (1, 1, 5), (3, 3, 6)',
          'CREATE TRIGGER tg AFTER UPDATE ON g BEGIN UPDATE p SET id = NEW.x * 10 WHERE id = NEW.x; END'],
         'UPDATE h SET id = CASE id WHEN 0 THEN 10 WHEN 1 THEN 11 WHEN 3 THEN 13 ELSE id END, p = CASE id WHEN 2 THEN 6 ELSE p END'],
        // ON UPDATE CASCADE: el trigger de la primera hija borra el padre nuevo; la segunda lo toma: error (desde antes de la 2.9)
        [['CREATE TABLE p (id INTEGER PRIMARY KEY)',
          'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON UPDATE CASCADE ON DELETE CASCADE, x INTEGER)',
          'CREATE TRIGGER t AFTER UPDATE ON h WHEN NEW.x = 1 BEGIN DELETE FROM p WHERE id = NEW.p; END',
          'INSERT INTO p VALUES (1)', 'INSERT INTO h VALUES (1, 1, 1), (2, 1, 0)'], 'UPDATE p SET id = 2 WHERE id = 1'],
        [['CREATE TABLE p (id INTEGER PRIMARY KEY)',
          'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON UPDATE CASCADE ON DELETE CASCADE, x INTEGER)', 'CREATE INDEX ih ON h (p)',
          'CREATE TRIGGER t AFTER UPDATE ON h WHEN NEW.x = 1 BEGIN DELETE FROM p WHERE id = NEW.p; END',
          'INSERT INTO p VALUES (1)', 'INSERT INTO h VALUES (1, 1, 1), (2, 1, 0)'], 'UPDATE p SET id = 2 WHERE id = 1'],
        // OR REPLACE quita la fila con la que choca después de sus cascadas: lo que estas hacen apuntar a ella, error (desde antes de la 2.9)
        [['CREATE TABLE p (id INTEGER PRIMARY KEY, u INTEGER UNIQUE)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER DEFAULT 1 REFERENCES p(id) ON DELETE SET DEFAULT)',
          'INSERT INTO p VALUES (1, 10)', 'INSERT INTO h VALUES (1, 1)'], 'INSERT OR REPLACE INTO p VALUES (2, 10)'],
        [['CREATE TABLE p (id INTEGER PRIMARY KEY, u INTEGER UNIQUE)', 'CREATE TABLE a (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id))',
          'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON DELETE CASCADE)',
          'CREATE TRIGGER t AFTER DELETE ON h BEGIN INSERT INTO a VALUES (OLD.id, OLD.p); END',
          'INSERT INTO p VALUES (1, 10), (3, 30)', 'INSERT INTO h VALUES (1, 1)'], 'INSERT OR REPLACE INTO p VALUES (2, 10)'],
        [['CREATE TABLE p (id INTEGER PRIMARY KEY, u INTEGER UNIQUE)', 'CREATE TABLE a (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id))',
          'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON DELETE CASCADE)',
          'CREATE TRIGGER t AFTER DELETE ON h BEGIN INSERT INTO a VALUES (OLD.id, OLD.p); END',
          'INSERT INTO p VALUES (1, 10), (3, 30)', 'INSERT INTO h VALUES (1, 1)'], 'UPDATE OR REPLACE p SET u = 10 WHERE id = 3'],
        [['CREATE TABLE p (id INTEGER PRIMARY KEY, u INTEGER UNIQUE)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON DELETE SET NULL)',
          'INSERT INTO p VALUES (1, 10)', 'INSERT INTO h VALUES (1, 1)'], 'INSERT OR REPLACE INTO p VALUES (1, 20)'],
    ];
    $mal = [];
    foreach ($casos as $i => [$inicio, $q]) {
        $dir = "$raiz/fkfin$i";
        @mkdir($dir, 0775, true);
        Database::crear('x', $dir);
        $b = new Database('x', $dir);
        $l = sqliteParaComparar();
        $l->exec('PRAGMA foreign_keys = ON');
        foreach ($inicio as $x) { $b->consultar($x); $l->exec($x); }
        try { $b->consultar($q); $r = ['ok']; } catch (JsonSqlDbError) { $r = ['error']; }
        try { $l->exec($q); $s = ['ok']; } catch (PDOException) { $s = ['error']; }
        foreach (array_unique(array_filter(array_map(static fn($x) => preg_match('/^CREATE TABLE (\w+)/', $x, $m) ? $m[1] : null, $inicio))) as $t) {
            $r[] = json_encode($b->consultar("SELECT * FROM $t ORDER BY id"));
            $s[] = json_encode($l->query("SELECT * FROM $t ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
        }
        if ($r !== $s) { $mal[] = [$q, $r, $s]; }
        unset($b);
        Database::borrar('x', $dir);
    }
    return $mal === [] ?: json_encode($mal, JSON_UNESCAPED_UNICODE);
});
chk('TRUNCATE TABLE en un trigger cuenta para las claves foráneas que se miran al acabar la sentencia, como un DELETE (2.10: un error falso)', function () use ($bd) {
    foreach (['CREATE TABLE trp (id INTEGER PRIMARY KEY)', 'CREATE TABLE trh (id INTEGER PRIMARY KEY, p INTEGER REFERENCES trp(id))',
              'INSERT INTO trp VALUES (1), (2)', 'INSERT INTO trh VALUES (1, 1), (2, 1)',
              'CREATE TRIGGER trv AFTER DELETE ON trp BEGIN TRUNCATE TABLE trh; END'] as $q) {
        $bd->consultar($q);
    }
    $bd->consultar('DELETE FROM trp WHERE id = 1');
    $r = [$bd->consultar('SELECT id FROM trp'), $bd->consultar('SELECT COUNT(*) AS n FROM trh')];
    return $r === [[['id' => 2]], [['n' => 0]]] ?: json_encode($r);
});
chk('cada sentencia de un trigger calcula sus subconsultas otra vez, también en cada fila que lo dispara, y la de fuera sigue con las suyas; como SQLite (2.10: valía la de la primera vez para toda la sentencia de fuera)', function () use ($raiz) {
    return escriturasComoSqlite("$raiz/subtrg", ['CREATE TABLE a (id INTEGER PRIMARY KEY)', 'CREATE TABLE c (id INTEGER PRIMARY KEY, n INTEGER, m INTEGER)',
        'INSERT INTO c VALUES (1, 0, 0)', 'INSERT INTO a VALUES (1), (2), (3)',
        'CREATE TRIGGER ta AFTER INSERT ON a BEGIN UPDATE c SET n = (SELECT count(*) FROM a) WHERE id = 1; UPDATE c SET m = m + 1 WHERE id IN (SELECT 1 FROM a WHERE id = 2); END',
        'CREATE TRIGGER td AFTER DELETE ON a BEGIN UPDATE c SET n = (SELECT count(*) FROM a) WHERE id = 1; END',
        'CREATE TABLE u (id INTEGER PRIMARY KEY, x INTEGER)', 'CREATE TABLE u2 (id INTEGER PRIMARY KEY)', 'INSERT INTO u VALUES (1, 0), (2, 0), (3, 0)',
        'CREATE TRIGGER tu AFTER UPDATE ON u BEGIN INSERT INTO u2 VALUES (NEW.id); UPDATE c SET m = (SELECT count(*) FROM u2) WHERE id = 1; END'], [
        ['INSERT INTO a VALUES (4), (5), (6)', null],
        // La sentencia de fuera usa la misma subconsulta antes de que el trigger salte
        ['DELETE FROM a WHERE id = 1 AND (SELECT count(*) FROM a) > 0', null],
        // La de fuera la calcula una vez, como SQLite, aunque la del trigger cambie
        ['UPDATE u SET x = (SELECT count(*) FROM u2)', null],
    ]);
});
chk('un trigger no puede usar RETURNING, como SQLite; el de un trigger de antes no devuelve nada en la sentencia de fuera (2.10: sus filas se mezclaban con las de ella)', function () use ($bd, $raiz) {
    $bd->consultar('CREATE TABLE rtt (id INTEGER PRIMARY KEY, v INTEGER)');
    $bd->consultar('CREATE TABLE rtl (id INTEGER PRIMARY KEY, v INTEGER)');
    $r = [error('CREATE TRIGGER rtr AFTER INSERT ON rtt BEGIN INSERT INTO rtl VALUES (NEW.id * 10, NEW.v) RETURNING *; END', 'no puede usar RETURNING')];
    // Uno así de una versión anterior: se le pone el RETURNING a mano en su estructura
    $bd->consultar('CREATE TRIGGER rtr AFTER INSERT ON rtt BEGIN INSERT INTO rtl VALUES (NEW.id * 10, NEW.v); END');
    $f = "$raiz/c/rtt.meta.json";
    file_put_contents($f, str_replace('NEW.v)', 'NEW.v) RETURNING *', (string)file_get_contents($f)));
    $bd2 = new Database('c', $raiz);
    $r[] = $bd2->consultar('INSERT INTO rtt VALUES (2, 7) RETURNING *') === [['id' => 2, 'v' => 7]] ?: 'filas de más';
    $r[] = $bd2->consultar('SELECT * FROM rtl') === [['id' => 20, 'v' => 7]] ?: 'el trigger no se ejecutó';
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('RETURNING se calcula antes de escribir: si da error, la sentencia no deja nada, también sin filas; una subconsulta ve las tablas como las deja la sentencia; como SQLite (2.10: la fila quedaba escrita, y una subconsulta daba un error de PHP)', function () use ($raiz) {
    return escriturasComoSqlite("$raiz/ret", ['CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)', 'INSERT INTO t VALUES (1, 1), (2, 2)'], [
        ['INSERT INTO t VALUES (3, 3) RETURNING nocol', null],
        ['UPDATE t SET v = 5 WHERE id = 9 RETURNING nocol', null],
        ["INSERT INTO t VALUES (3, 3) RETURNING json_extract('x', '$')", null],
        ['UPDATE t SET v = 9 WHERE id = 1 RETURNING id, (SELECT SUM(v) FROM t) AS s', null],
        ['UPDATE t SET v = v + 10 WHERE v < (SELECT max(v) FROM t) RETURNING id, (SELECT max(v) FROM t) AS m', null],
        ['DELETE FROM t WHERE id = 1 RETURNING id, (SELECT COUNT(*) FROM t) AS n, id IN (SELECT id FROM t) AS sigue', null],
    ]);
});
chk('una cascada sobre la misma tabla (una clave foránea hacia sí misma) cuenta para las claves únicas del UPDATE que la lanza: ni repite un UNIQUE ni ve ocupada una clave que ha liberado; como SQLite (2.10: el UNIQUE quedaba repetido, desde antes de la 2.9)', function () use ($raiz) {
    $r = [escriturasComoSqlite("$raiz/cm1", ["CREATE TABLE m (id INTEGER PRIMARY KEY, u TEXT UNIQUE, par TEXT UNIQUE REFERENCES m(u) ON UPDATE CASCADE)",
                                             "INSERT INTO m VALUES (1, 'a', NULL), (2, 'b', 'a'), (3, 'c', NULL)"], [
        ["UPDATE m SET u = CASE id WHEN 1 THEN 'z' ELSE u END, par = CASE id WHEN 3 THEN 'z' ELSE par END WHERE id IN (1, 3)", null],
        ["UPDATE m SET u = CASE id WHEN 1 THEN 'z' ELSE u END, par = CASE id WHEN 3 THEN 'y' ELSE par END WHERE id IN (1, 3)", null],
    ]), escriturasComoSqlite("$raiz/cm2", ["CREATE TABLE m (id INTEGER PRIMARY KEY, u TEXT UNIQUE, par TEXT REFERENCES m(u) ON DELETE CASCADE)",
                                            "INSERT INTO m VALUES (1, 'a', NULL), (2, 'b', 'a'), (3, 'c', 'b'), (4, 'x', NULL)"], [
        ["UPDATE OR REPLACE m SET u = CASE id WHEN 1 THEN 'b' ELSE 'c' END WHERE id IN (1, 4)", null],
    ])];
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('un número fuera de rango (1e400, 1e300 * 1e300) no se guarda: INSERT y UPDATE dan un error que lo dice y la tabla queda como estaba (2.10: «No se puede codificar una fila»)', function () use ($bd) {
    $bd->consultar('CREATE TABLE inf (id INTEGER PRIMARY KEY, x REAL)');
    $bd->consultar('INSERT INTO inf VALUES (1, 1.5)');
    $r = [error('INSERT INTO inf VALUES (2, 1e400)', "La columna 'x' de 'inf' recibe un número fuera de rango"),
          error('UPDATE inf SET x = 1e300 * 1e300', 'fuera de rango (infinito o NaN)'), error('INSERT INTO inf VALUES (3, -1e400)', 'fuera de rango'),
          $bd->consultar('SELECT * FROM inf') === [['id' => 1, 'x' => 1.5]] ?: 'la tabla cambió'];
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('CREATE VIEW da error si usa una tabla o una vista que no existe, también en una subconsulta o la suya propia; con WITH, vistas de vistas y subconsultas en FROM, no; como MySQL y PostgreSQL (2.10: se creaba y fallaba al usarla)', function () use ($bd) {
    $bd->consultar('CREATE TABLE vx (id INTEGER PRIMARY KEY)');
    $r = [error('CREATE VIEW vno AS SELECT id FROM noexiste', "La vista 'vno' usa 'noexiste'"),
          error('CREATE VIEW vno AS SELECT id FROM vx WHERE id IN (SELECT id FROM tampoco)', "usa 'tampoco'"),
          error('CREATE VIEW vno AS SELECT id FROM vno', "usa 'vno'")];
    foreach (['CREATE VIEW v1x AS SELECT id FROM VX', 'CREATE VIEW v2x AS SELECT id FROM v1x',
              'CREATE VIEW v3x AS WITH c AS (SELECT id FROM vx) SELECT id FROM c', 'CREATE VIEW v4x AS SELECT id FROM (SELECT id FROM main.vx) s',
              'CREATE VIEW v5x AS WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 3) SELECT i FROM n',
              'CREATE VIEW IF NOT EXISTS v1x AS SELECT id FROM vx'] as $q) {
        $bd->consultar($q);
    }
    $v = array_values(preg_grep('/^v\\dx$/', array_column($bd->consultar('SHOW VIEWS'), 'vista')));
    $r[] = $v === ['v1x', 'v2x', 'v3x', 'v4x', 'v5x'] && $bd->consultar('SELECT COUNT(*) AS n FROM v5x') === [['n' => 3]] ?: json_encode($v);
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('LIKE solo iguala mayúsculas y minúsculas de la A a la Z, como SQLite, también sin mbstring (2.10: con UTF-8, É LIKE é)', fn() =>
    comoSqlite(["'É' LIKE 'é'", "'á' LIKE 'Á%'", "'ñ' LIKE 'N'", "'Z' LIKE 'z'", "'zÉta' LIKE 'ZÉTA'", "'[' LIKE '{'", "'ß' LIKE 'SS'",
                "like('Á%', 'á')", "like('A%', 'a')"]));
chk('ESCAPE como SQLite: un solo carácter, aunque sea de varios bytes; patrón que acaba en él, no casa; ESCAPE NULL, NULL (2.10)', function () {
    // Con ESCAPE 'ñ' se tomaba su primer byte, que también empieza á o é
    $r = [comoSqlite(["'x%' LIKE 'xñ%' ESCAPE 'ñ'", "'xá' LIKE 'xñ%' ESCAPE 'ñ'", "'xñ' LIKE 'xññ' ESCAPE 'ñ'", "'a_b' LIKE 'a!_b' ESCAPE '!'",
                      "'axb' LIKE 'a!_b' ESCAPE '!'", "'ab\\' LIKE 'ab\\' ESCAPE '\\'", "'x' LIKE 'X' ESCAPE 'X'", "'A' LIKE '!a' ESCAPE '!'", "'ab' LIKE 'ab!' ESCAPE '!'", "'xáb' LIKE 'xá%' ESCAPE 'ñ'",
                      "'a' LIKE 'a' ESCAPE NULL", "like('a', 'a', NULL)"]),
          filasComoSqlite(["SELECT id, n LIKE 'an%' ESCAPE NULL AS a, n LIKE 'AN!%' ESCAPE '!' AS b, n LIKE 'án%' AS c FROM cn ORDER BY id",
                           "SELECT id FROM cn WHERE b LIKE 'an%' ESCAPE NULL IS NULL ORDER BY id", "SELECT id FROM cn WHERE b NOT LIKE 'a%' ESCAPE 'a' ORDER BY id"]),
          error("SELECT 'a' LIKE 'a' ESCAPE 'xy'", 'ESCAPE tiene que ser un solo carácter'),
          error("SELECT 'a' LIKE 'a' ESCAPE ''", 'ESCAPE tiene que ser un solo carácter')];
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('SUM de enteros que no cabe en 64 bits es un error, como en SQLite, por las tres vías y por el disco; TOTAL y AVG suman en decimal (2.10: un decimal aproximado sin avisar)', function () use ($bd, $sqlite, $raiz) {
    $bd->consultar('CREATE TABLE so (g INTEGER, x INTEGER)');
    $bd->consultar('INSERT INTO so VALUES (1, 9223372036854775807), (1, 1), (1, -2), (2, 5), (2, 7)');
    $sqlite->exec('CREATE TABLE so (g INTEGER, x INTEGER); INSERT INTO so VALUES (1, 9223372036854775807), (1, 1), (1, -2), (2, 5), (2, 7)');
    $r = [];
    // Al final, a mitad (vuelve a caber, y aun así), con GROUP BY, con DISTINCT y en una ventana
    // (en una ventana, SQLite corta el resultado en la fila que desborda, y
    // PDO no lo trata como error: se mira solo la primera)
    foreach (['SELECT SUM(x) FROM so', 'SELECT g, SUM(x) FROM so GROUP BY g', 'SELECT SUM(DISTINCT x) FROM so',
              'SELECT SUM(x) OVER () FROM so', 'SELECT SUM(x) OVER (ORDER BY g, x) FROM so', 'SELECT SUM(x) OVER (ORDER BY g, x ROWS BETWEEN 1 PRECEDING AND 1 FOLLOWING) FROM so'] as $i => $q) {
        $r[] = error($q, 'integer overflow');
        try { $sqlite->query($q)->fetchAll(); $r[] = $i < 4 ? "SQLite no dio error: $q" : true; } catch (PDOException) { }
    }
    // Con un NULL, o una fila que FILTER deja fuera, antes del que desborda:
    // no cuentan entre los sumados (2.10: cada grupo cuenta los que deja fuera)
    $son = 'INSERT INTO son VALUES (1, NULL), (1, 9223372036854775807), (1, 1), (2, 5), (2, 9223372036854775807), (2, 1)';
    $bd->consultar('CREATE TABLE son (g INTEGER, x INTEGER)');
    $bd->consultar($son);
    $sqlite->exec("CREATE TABLE son (g INTEGER, x INTEGER); $son");
    foreach (['SELECT SUM(x) FROM son WHERE g = 1', 'SELECT g, SUM(x) FROM son WHERE g = 1 GROUP BY g', 'SELECT SUM(x) FILTER (WHERE x <> 5) FROM son WHERE g = 2'] as $q) {
        $r[] = error($q, 'integer overflow');
        try { $sqlite->query($q)->fetchAll(); $r[] = "SQLite no dio error: $q"; } catch (PDOException) { }
    }
    // Con un valor que no es entero, antes o después de pasarse, en decimal y
    // sin error, como SQLite 3.45 (un texto que no es un número cuenta como
    // decimal); un texto que es un entero desborda como un entero
    $sd = '(SELECT 1.5 AS x UNION ALL SELECT 9223372036854775807 UNION ALL SELECT 1)';
    $se = '(SELECT 9223372036854775807 AS x UNION ALL SELECT 1 UNION ALL SELECT 1.5)';
    $sa = "(SELECT 9223372036854775807 AS x UNION ALL SELECT 1 UNION ALL SELECT 'abc')";
    $bd->consultar('CREATE TABLE st (s TEXT)');
    $bd->consultar("INSERT INTO st VALUES ('9223372036854775807'), (' 1 ')");
    $sqlite->exec("CREATE TABLE st (s TEXT); INSERT INTO st VALUES ('9223372036854775807'), (' 1 ')");
    foreach (['SELECT SUM(s) FROM st', 'SELECT SUM(DISTINCT s) FROM st', 'SELECT SUM(s) OVER () FROM st'] as $i => $q) {
        $r[] = error($q, 'integer overflow');
        try { $sqlite->query($q)->fetchAll(); $r[] = $i < 2 ? "SQLite no dio error: $q" : true; } catch (PDOException) { }
    }
    $r[] = filasComoSqlite(['SELECT g, TOTAL(x), AVG(x), SUM(x + 0.0) FROM so GROUP BY g', 'SELECT g, SUM(x) FROM so WHERE g = 2 GROUP BY g',
                            'SELECT SUM(x) FROM so WHERE x < 9', 'SELECT AVG(DISTINCT x), TOTAL(DISTINCT x) FROM so', "SELECT SUM(x) FROM $sd",
                            "SELECT SUM(x) OVER () FROM $sd", "SELECT SUM(x) FROM $se", "SELECT SUM(x) OVER () FROM $se", "SELECT SUM(x) FROM $sa",
                            "SELECT SUM(DISTINCT x) FROM $se"]);
    // SUM(DISTINCT) por el disco, por particiones (cuando sus valores no caben):
    // con un decimal en una partición y enteros que no caben en otra, en
    // decimal y sin error, como en memoria y en SQLite; todos enteros, el error
    $v = ["('0.5')", "('9223372036854775807')"];
    for ($i = 3; $i <= 300; $i++) { $v[] = "('$i')"; }
    $bd->consultar('CREATE TABLE sp (x TEXT)');
    $bd->consultar('INSERT INTO sp VALUES ' . implode(',', $v));
    $disco = new JsonSQLDB\Select(new JsonSQLDB\Catalog(new JsonSQLDB\Storage($raiz, 'c')));
    $p = new ReflectionProperty(JsonSQLDB\Select::class, 'enDisco');
    PHP_VERSION_ID < 80100 && $p->setAccessible(true);         // desde 8.5 avisa de obsoleta
    $p->setValue($disco, true);
    foreach (['SELECT SUM(DISTINCT x) AS s FROM sp' => 'decimal', "SELECT SUM(DISTINCT x) AS s FROM sp WHERE x <> '0.5'" => 'error'] as $q => $esperado) {
        try {
            $s = $disco->ejecutar(JsonSQLDB\Parser::analizar($q))[0]['s'];
            $obtenido = is_float($s) && $s > 9.2e18 ? 'decimal' : json_encode($s);
        } catch (JsonSqlDbError $e) {
            $obtenido = str_contains($e->getMessage(), 'integer overflow') ? 'error' : $e->getMessage();
        }
        $r[] = $obtenido === $esperado ?: "$q por el disco: $obtenido";
    }
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('enteros pasado 2^53 con decimales: ORDER BY, WHERE, BETWEEN, IN, MIN y MAX, GROUP BY, DISTINCT, UNION, JOIN y ventanas, exactos como en SQLite (2.10: PHP pasaba el entero a decimal y 9007199254740993 salía igual que 9007199254740992.0)', function () use ($bd, $sqlite) {
    $b = 9007199254740992;                               // 2^53
    $filas = [];
    foreach ([$b - 1, $b, $b + 1, $b + 2, $b + 3, -$b - 1, -$b, -$b - 3, 1, 0, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1] as $i => $n) {
        $filas[] = '(' . ($i + 1) . ", $n, NULL)";
    }
    foreach (['9007199254740992.0', '9007199254740994.0', '-9007199254740992.0', '9.2233720368547758e18', '-9.2233720368547758e18', '1.5', '9007199254740996.0'] as $i => $m) {
        $filas[] = '(' . ($i + 20) . ", NULL, $m)";
    }
    foreach (['CREATE TABLE gr (id INTEGER PRIMARY KEY, n INTEGER, m REAL)', 'CREATE INDEX gr_n ON gr (n)', 'CREATE TABLE gh (n INTEGER)',
              'INSERT INTO gr VALUES ' . implode(', ', $filas), 'INSERT INTO gh VALUES (9007199254740993), (9007199254740992), (9007199254740994)'] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    // Enteros y decimales en una misma columna
    $x = '(SELECT id, n AS x FROM gr WHERE n IS NOT NULL UNION ALL SELECT id, m FROM gr WHERE m IS NOT NULL) AS u';
    return filasComoSqlite([
        'SELECT n FROM gr WHERE n IS NOT NULL ORDER BY n', 'SELECT n FROM gr WHERE n IS NOT NULL ORDER BY n DESC LIMIT 4', 'SELECT n FROM gh ORDER BY n',
        "SELECT x FROM $x ORDER BY x, id", "SELECT x FROM $x ORDER BY x DESC LIMIT 5", "SELECT x, RANK() OVER (ORDER BY x) FROM $x ORDER BY id, x",
        'SELECT n FROM gr WHERE n > 9007199254740992.0 ORDER BY n', 'SELECT n FROM gr WHERE 9007199254740992.0 < n ORDER BY n LIMIT 2',
        'SELECT n FROM gr WHERE n = 9007199254740992.0', 'SELECT n FROM gr WHERE n <> 9007199254740992.0 ORDER BY n',
        'SELECT n FROM gr WHERE n >= 9007199254740994.0 ORDER BY n', 'SELECT n FROM gr WHERE n + 0 > 9007199254740992.0 ORDER BY n',
        'SELECT n FROM gr WHERE n BETWEEN 9007199254740992.0 AND 9007199254740994.0 ORDER BY n', 'SELECT n FROM gr WHERE n IN (9007199254740992.0, 9007199254740994.0) ORDER BY n',
        "SELECT x FROM $x WHERE x = 9007199254740993 ORDER BY x", "SELECT x FROM $x WHERE x > 9007199254740993 ORDER BY x",
        "SELECT MIN(x), MAX(x) FROM $x WHERE x > 9e15", "SELECT COUNT(DISTINCT x) FROM $x", "SELECT x, COUNT(*) FROM $x GROUP BY x ORDER BY x",
        "SELECT x FROM $x UNION SELECT n FROM gh ORDER BY 1", 'SELECT gh.n, gr.m FROM gh JOIN gr ON gr.m = gh.n ORDER BY gh.n',
        'SELECT CASE WHEN n > 9007199254740992.0 THEN 1 ELSE 0 END FROM gr ORDER BY id',
        // Pasado 9·10^18, un decimal entero y su entero van por su índice con
        // la misma clave, también en UPDATE y DELETE (ver la prueba de 2^63)
        ...(static function () use ($bd, $sqlite): array {
            foreach (['CREATE TABLE gi (id INTEGER PRIMARY KEY, r REAL, i INTEGER)', 'CREATE INDEX gi_r ON gi (r)', 'CREATE INDEX gi_i ON gi (i)',
                      'INSERT INTO gi VALUES (1, 9.2e18, 9100000000000000000), (2, -9.2233720368547758e18, 5), (3, 1.0, 9223372036854775807)',
                      'UPDATE gi SET i = i - 1 WHERE r = 9200000000000000000', 'DELETE FROM gi WHERE i = 9.2233720368547758e18'] as $q) {
                $bd->consultar($q);
                $sqlite->exec($q);
            }
            return ['SELECT id, i FROM gi WHERE r = 9200000000000000000', 'SELECT id FROM gi WHERE r = -9223372036854775808',
                    'SELECT id FROM gi WHERE i = 9.1e18 - 1', 'SELECT id FROM gi WHERE r IN (9200000000000000000, 1) ORDER BY id', 'SELECT COUNT(*) FROM gi'];
        })()]);
});
chk('pasado 2^53, también exactos los atajos: ORDER BY … LIMIT desde el límite del WHERE, BETWEEN y AND con un literal, el ORDER BY … LIMIT en memoria y el ORDER BY por el disco, como en SQLite (2.10: perdían o colaban filas)', function () use ($bd, $sqlite, $raiz) {
    $v = [];
    for ($i = 1; $i <= 2998; $i++) {
        $v[] = "($i, $i)";
    }
    foreach ([9007199254740991, 9007199254740992, 9007199254740993, 9007199254740995] as $j => $n) {
        $v[] = '(' . (3000 + $j) . ", $n)";
    }
    foreach (['CREATE TABLE bg (id INTEGER PRIMARY KEY, n INTEGER NOT NULL)', 'CREATE INDEX bg_n ON bg (n)', 'INSERT INTO bg VALUES ' . implode(',', $v),
              'CREATE TABLE bgn (n INTEGER)', 'INSERT INTO bgn SELECT n FROM bg WHERE n > 2990'] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    $r = filasComoSqlite(['SELECT n FROM bg WHERE n <= 9007199254740992.0 ORDER BY n DESC LIMIT 2', 'SELECT n FROM bg WHERE n >= 9007199254740992.0 ORDER BY n LIMIT 2',
        'SELECT n FROM bg WHERE n < 9007199254740994.0 ORDER BY n DESC LIMIT 1', 'SELECT COUNT(*) FROM bg WHERE n BETWEEN 0 AND 9007199254740992.0',
        'SELECT id FROM bg WHERE n > 9007199254740992.0 AND id > 0 ORDER BY id', 'SELECT COUNT(*) FROM bgn WHERE n BETWEEN 0 AND 9007199254740992.0',
        'SELECT COUNT(*) FROM bgn WHERE n NOT BETWEEN 9007199254740994.0 AND 9007199254740996.0']);
    if ($r !== true) {
        return $r;
    }
    // Enteros y decimales en la misma columna: en memoria con LIMIT, y por el disco
    $u = '(SELECT n AS x FROM bg WHERE n > 9007199254740992 UNION ALL SELECT 9007199254740992.0 UNION ALL SELECT 9007199254740994.0) AS u';
    $disco = new JsonSQLDB\Select(new JsonSQLDB\Catalog(new JsonSQLDB\Storage($raiz, 'c')));
    $p = new ReflectionProperty(JsonSQLDB\Select::class, 'enDisco');
    PHP_VERSION_ID < 80100 && $p->setAccessible(true);         // desde 8.5 avisa de obsoleta
    $p->setValue($disco, true);
    // y con el listón de un LIMIT: 9007199254740993 llega cuando el tercero es 9007199254740992.0
    $l = '(SELECT 9007199254740992.0 AS x UNION ALL SELECT 9007199254740994.0 UNION ALL SELECT 9007199254740995 UNION ALL SELECT n FROM bg WHERE n < 9e15 UNION ALL SELECT 9007199254740993) AS l';
    $vistos = [array_column($bd->consultar("SELECT x FROM $u ORDER BY x LIMIT 3"), 'x'), array_column($bd->consultar("SELECT x FROM $l ORDER BY x DESC LIMIT 3"), 'x'),
               array_column($disco->ejecutar(JsonSQLDB\Parser::analizar("SELECT x FROM $u ORDER BY x")), 'x')];
    return $vistos === [[9007199254740992.0, 9007199254740993, 9007199254740994.0], [9007199254740995, 9007199254740994.0, 9007199254740993],
                        [9007199254740992.0, 9007199254740993, 9007199254740994.0, 9007199254740995]]
        ?: json_encode($vistos, JSON_PRESERVE_ZERO_FRACTION);
});
chk('Los marcos RANGE con distancia, con la aritmética de SQLite fila a fila: PRECEDING suma la distancia a cada fila, un entero más un decimal se redondea pasado 2^53, un NULL o un texto entra en el marco que llega hasta él, y un texto que parece un número no tiene distancia (2.10: restaba la distancia, se saltaba el NULL y \'5\' contaba como 5)', function () use ($bd, $sqlite) {
    foreach (['CREATE TABLE rg (id INTEGER PRIMARY KEY, i INTEGER, r REAL, s TEXT)',
              "INSERT INTO rg VALUES (1, -4, NULL, NULL), (2, NULL, -3.6, NULL), (3, 9007199254740989, NULL, NULL), (4, NULL, NULL, NULL), (5, NULL, NULL, 'c'),
                                     (6, 9007199254740993, NULL, NULL), (7, 9007199254740990, NULL, NULL), (8, NULL, 1.9, NULL)",
              'CREATE TABLE rd (id INTEGER PRIMARY KEY, x REAL)', 'INSERT INTO rd VALUES (1, 0.3), (2, 0.4), (3, -0.3), (4, -0.4), (5, NULL), (6, 1.0)',
              'CREATE TABLE rb (id INTEGER PRIMARY KEY, n INTEGER)', 'INSERT INTO rb VALUES (1, 9007199254740991), (2, 9007199254740992), (3, 9007199254740993), (4, 9007199254740995)',
              'CREATE TABLE rgh (id INTEGER PRIMARY KEY, i INTEGER, r REAL)', 'INSERT INTO rgh VALUES (1, 0, NULL), (2, 1, NULL), (3, NULL, 9007199254740992.0)',
              'CREATE TABLE rgt (id INTEGER PRIMARY KEY, i INTEGER, r REAL, s TEXT)', "INSERT INTO rgt VALUES (1, 1, NULL, NULL), (2, NULL, 2.5, NULL), (3, NULL, NULL, '5'), (4, NULL, NULL, 'a')",
              'CREATE TABLE rgv (id INTEGER PRIMARY KEY, x REAL)',
              'CREATE TABLE rgp (id INTEGER PRIMARY KEY, i INTEGER, r REAL)', 'INSERT INTO rgp VALUES (1, 9007199254740992, NULL), (2, NULL, 9007199254740992.0), (3, NULL, 9007199254740994.0), (4, 9007199254740993, NULL)'] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    $x = 'COALESCE(i, r, s)';
    $q = [];
    // -3.6 + 9007199254740993 se redondea a ...988 y no llega, pero -4 sí: el
    // marco empieza en la primera que llega; 0.5 FOLLOWING del ...993 lo deja
    // en ...992.0; el NULL y el texto, al principio y al final del orden
    foreach (["ORDER BY $x RANGE BETWEEN 9007199254740993 PRECEDING AND CURRENT ROW", "ORDER BY $x DESC RANGE BETWEEN 0.5 FOLLOWING AND UNBOUNDED FOLLOWING",
              "ORDER BY $x RANGE BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING", "ORDER BY $x DESC RANGE BETWEEN 1.5 FOLLOWING AND UNBOUNDED FOLLOWING",
              "ORDER BY $x RANGE BETWEEN 2 FOLLOWING AND 3 FOLLOWING", "ORDER BY $x NULLS LAST RANGE BETWEEN 1 PRECEDING AND 1.0 FOLLOWING"] as $w) {
        $q[] = "SELECT id, COUNT(*) OVER ($w) AS c, FIRST_VALUE(id) OVER ($w) AS f, LAST_VALUE(id) OVER ($w) AS l, GROUP_CONCAT(id) OVER ($w) AS g FROM rg ORDER BY id";
    }
    // 0.3 + 0.1 llega a 0.4, y -0.4 + 0.1 no llega a -0.3; el NULL va delante
    foreach (['ORDER BY x RANGE BETWEEN 0.1 PRECEDING AND CURRENT ROW', 'ORDER BY x DESC RANGE BETWEEN 0.1 PRECEDING AND CURRENT ROW',
              'ORDER BY x RANGE BETWEEN CURRENT ROW AND 0.1 FOLLOWING', 'ORDER BY x DESC RANGE BETWEEN 0.1 FOLLOWING AND 0.7 FOLLOWING',
              'ORDER BY x RANGE BETWEEN UNBOUNDED PRECEDING AND 0.1 PRECEDING', 'ORDER BY x DESC RANGE BETWEEN 0.1 FOLLOWING AND UNBOUNDED FOLLOWING'] as $w) {
        $q[] = "SELECT id, COUNT(*) OVER ($w) AS c, GROUP_CONCAT(id) OVER ($w) AS g FROM rd ORDER BY id";
    }
    // 9007199254740992 + 1.0 da 9007199254740992.0 y deja fuera el ...993
    foreach (['ORDER BY n RANGE BETWEEN CURRENT ROW AND 1.0 FOLLOWING', 'ORDER BY n RANGE BETWEEN 1.0 PRECEDING AND CURRENT ROW',
              'ORDER BY n DESC RANGE BETWEEN CURRENT ROW AND 1.0 FOLLOWING', 'ORDER BY n RANGE BETWEEN 1 FOLLOWING AND 2.0 FOLLOWING'] as $w) {
        $q[] = "SELECT id, COUNT(*) OVER ($w) AS c FROM rb ORDER BY id";
    }
    // Pasado 2^52 el < de PHP ya no basta: 1 + 9007199254740992 es entero y
    // pasa de 9007199254740992.0; el texto '5' va detrás de los números
    $q[] = 'SELECT id, COUNT(*) OVER (ORDER BY COALESCE(i, r) RANGE BETWEEN UNBOUNDED PRECEDING AND 9007199254740992 PRECEDING) AS c FROM rgh ORDER BY id';
    $q[] = 'SELECT id, COUNT(*) OVER (ORDER BY COALESCE(i, r, s) RANGE BETWEEN 10 PRECEDING AND 10 FOLLOWING) AS c FROM rgt ORDER BY id';
    // Los pares tienen el marco del primero: 9007199254740992 + 1 es ...993,
    // y 9007199254740992.0 + 1 se queda en ...992.0
    foreach (['1 FOLLOWING AND UNBOUNDED FOLLOWING', 'CURRENT ROW AND 1 FOLLOWING'] as $m) {
        $q[] = "SELECT id, COUNT(*) OVER (ORDER BY COALESCE(i, r) RANGE BETWEEN $m) AS c FROM rgp ORDER BY id";
    }
    // Sin filas, y sin avisos de PHP (2.10, antes de publicarla: uno)
    $avisos = [];
    set_error_handler(static function (int $n, string $m) use (&$avisos): bool { $avisos[] = $m; return true; });
    try {
        $vacia = $bd->consultar('SELECT COUNT(*) OVER (ORDER BY x RANGE BETWEEN 1 PRECEDING AND 1.5 FOLLOWING) AS c FROM rgv');
    } finally {
        restore_error_handler();
    }
    if ($vacia !== [] || $avisos !== []) {
        return 'sin filas: ' . json_encode([$vacia, $avisos]);
    }
    $r = filasComoSqlite($q);
    if ($r !== true) {
        return $r;
    }
    // Un marco que encoge: -2 + 9007199254740993 es ...991 y empieza en el
    // ...994, pero -1.7 + 9007199254740993 se redondea a ...990.0 y empieza
    // antes. COUNT, que acumula según avanza el marco, cuenta lo mismo que
    // GROUP_CONCAT. SQLite da a -1.7 el marco de -2: avanza sin volver atrás
    $bd->consultar('CREATE TABLE ra (id INTEGER PRIMARY KEY, i INTEGER, r REAL)');
    $bd->consultar('INSERT INTO ra VALUES (1, 18014398509481992, NULL), (2, 9007199254740998, NULL), (3, -4, NULL), (4, 9007199254740994, NULL), (5, -2, NULL),
                    (6, 9007199254740988, NULL), (7, 9007199254740990, NULL), (8, NULL, -1.7), (9, 9007199254740990, NULL)');
    $w = 'OVER (ORDER BY COALESCE(i, r) RANGE BETWEEN 9007199254740993 FOLLOWING AND UNBOUNDED FOLLOWING)';
    $f = array_map(static fn(array $f): string => $f['c'] . ':' . $f['g'], $bd->consultar("SELECT c, g FROM (SELECT id, COUNT(*) $w AS c, GROUP_CONCAT(id) $w AS g FROM ra) AS v WHERE id IN (3, 5, 8) ORDER BY id"));
    return $f === ['5:7,9,4,2,1', '3:4,2,1', '5:7,9,4,2,1'] ?: 'el marco que encoge: ' . json_encode($f);
});
chk('Los marcos RANGE con una distancia infinita (9e999) y valores infinitos, como SQLite: -INF + INF es NULL y compara como sus NULL, y con los dos bordes PRECEDING el marco no pasa de los pares de la fila (2.10: comparaba un NaN)', function () use ($bd, $sqlite) {
    foreach (['CREATE TABLE rgi (id INTEGER PRIMARY KEY, k INTEGER, r REAL, s TEXT)', "INSERT INTO rgi VALUES (1, 1, NULL, NULL), (2, 2, NULL, NULL), (3, 0, NULL, NULL),
              (4, 0, 2.5, NULL), (5, 0, -1.0, NULL), (6, 0, NULL, NULL), (7, 2, NULL, NULL), (8, 0, NULL, 'b'), (9, 0, NULL, 'a'), (10, 0, NULL, 'c')"] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    $x = 'CASE k WHEN 1 THEN 9e999 WHEN 2 THEN -9e999 ELSE COALESCE(r, s) END';      // una columna no guarda un infinito
    $q = [];
    foreach (['9e999 PRECEDING AND CURRENT ROW', 'CURRENT ROW AND 9e999 FOLLOWING', '9e999 FOLLOWING AND 9e999 FOLLOWING', '0 PRECEDING AND 9e999 PRECEDING',
              '9e999 PRECEDING AND 9e999 FOLLOWING', '1 FOLLOWING AND 9e999 FOLLOWING', '9e999 PRECEDING AND 1 PRECEDING', 'UNBOUNDED PRECEDING AND 9e999 FOLLOWING',
              '9e999 PRECEDING AND UNBOUNDED FOLLOWING'] as $m) {
        foreach (['ASC', 'DESC', 'ASC NULLS LAST', 'DESC NULLS FIRST'] as $dir) {
            // SQLite no vuelve atrás: en DESC el +INF va primero, su marco
            // llega al final con FOLLOWING, y las filas que siguen se quedan
            // con ese final; aquí cada fila tiene el suyo
            if (str_ends_with($m, 'FOLLOWING') && ($dir === 'DESC' || ($dir === 'DESC NULLS FIRST' && str_starts_with($m, '9e999 FOLLOWING')))) {
                continue;
            }
            $w   = "OVER (ORDER BY $x $dir RANGE BETWEEN $m)";
            $q[] = "SELECT id, COUNT(*) $w AS c, FIRST_VALUE(id) $w AS f, LAST_VALUE(id) $w AS l FROM rgi ORDER BY id";
        }
    }
    // Los textos con COLLATE BINARY, comparados como se ordenan
    $bd->consultar('CREATE TABLE rgc (id INTEGER PRIMARY KEY, s TEXT)');
    $bd->consultar("INSERT INTO rgc VALUES (1, 'b'), (2, 'C'), (3, 'a')");
    $sqlite->exec("CREATE TABLE rgc (id INTEGER PRIMARY KEY, s TEXT); INSERT INTO rgc VALUES (1, 'b'), (2, 'C'), (3, 'a')");
    foreach (['9e999 PRECEDING AND CURRENT ROW', 'CURRENT ROW AND 9e999 FOLLOWING'] as $m) {
        $q[] = "SELECT id, COUNT(*) OVER (ORDER BY s COLLATE BINARY RANGE BETWEEN $m) AS c FROM rgc ORDER BY id";
    }
    return \count($q) === 31 ? filasComoSqlite($q) : \count($q) . ' consultas';
});
chk('TOTAL, AVG y SUM con decimales suman compensando el error, como SQLite 3.45: 1e100, 3 y -1e100 dan 3, también por grupos, con DISTINCT y en una ventana (2.10: 0)', function () use ($bd, $sqlite) {
    foreach (['CREATE TABLE kb (id INTEGER PRIMARY KEY, g INTEGER, r REAL, i INTEGER)',
              'INSERT INTO kb VALUES (1, 1, 1e100, 5), (2, 1, 3.0, 9223372036854775807), (3, 1, -1e100, 1), (4, 2, 0.1, 2), (5, 2, 0.2, 3), (6, 2, 0.3, 4), (7, 1, 0.5, -9223372036854775807)'] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    $r = [filasComoSqlite(['SELECT TOTAL(r), AVG(r), SUM(r) FROM kb', 'SELECT g, TOTAL(r), AVG(r), SUM(r) FROM kb GROUP BY g ORDER BY g',
                           'SELECT SUM(DISTINCT r), TOTAL(r + i) FROM kb', 'SELECT id, SUM(r) OVER (ORDER BY id), AVG(r) OVER (PARTITION BY g ORDER BY id) FROM kb ORDER BY id'])];
    // Con un marco que no empieza en la partición, cada uno se suma con sus
    // valores; SQLite resta lo que sale, y ahí puede no dar lo mismo
    $bd->consultar('CREATE TABLE kv (id INTEGER PRIMARY KEY, x REAL)');
    $bd->consultar('INSERT INTO kv VALUES (1, 1e100), (2, -1e100), (3, 1.0), (4, 2.0), (5, 3.0)');
    $v = array_column($bd->consultar('SELECT SUM(x) OVER (ORDER BY id ROWS 1 PRECEDING) AS s FROM kv ORDER BY id'), 's');
    $r[] = $v === [1e100, 0.0, -1e100, 3.0, 5.0] ?: json_encode($v);
    // Bit a bit, lo que da SQLite 3.45 (PDO en PHP 8.0 trae los decimales con
    // 15 cifras: van escritos): los enteros, exactos hasta el primer decimal o
    // hasta que no caben, y desde ahí al par en orden de llegada; AVG pasa la
    // suma a decimal antes de dividir; y por el disco, una suma para todas las
    // particiones
    foreach (['CREATE TABLE ko (id INTEGER PRIMARY KEY, i INTEGER, r REAL)',
              'INSERT INTO ko VALUES (1, -68, NULL), (2, NULL, 1e-16), (3, NULL, 3.0), (4, 380, NULL), (5, NULL, 3.0), (6, NULL, -1e16), (7, 941, NULL), (8, -882, NULL)',
              'CREATE TABLE kx (id INTEGER PRIMARY KEY, i INTEGER)', 'INSERT INTO kx VALUES (1, 9223372036854775807), (2, 1), (3, -9223372036854775807)',
              'CREATE TABLE ka (i INTEGER)', 'INSERT INTO ka VALUES (9007199254740993), (9007199254740993), (9007199254740993)',
              'CREATE TABLE kd (r REAL)', 'INSERT INTO kd VALUES (1e100), (21.0), (-1e100), (0.1), (0.2), (0.3)'] as $q) {
        $bd->consultar($q);
    }
    $disco = new JsonSQLDB\Select(new JsonSQLDB\Catalog(new JsonSQLDB\Storage($GLOBALS['raiz'], 'c')));
    $p = new ReflectionProperty(JsonSQLDB\Select::class, 'enDisco');
    PHP_VERSION_ID < 80100 && $p->setAccessible(true);         // desde 8.5 avisa de obsoleta
    $p->setValue($disco, true);
    foreach (['SELECT SUM(COALESCE(i, r)) FROM ko' => [-9999999999999622.0], 'SELECT TOTAL(i), AVG(i) FROM kx' => [1.0, 0.3333333333333333],
              "SELECT SUM(x) FROM (SELECT i AS x FROM kx UNION ALL SELECT 0.5)" => [1.5], 'SELECT AVG(i) FROM ka' => [9007199254740994.0],
              'SELECT TOTAL(i) OVER (ORDER BY id) FROM kx ORDER BY id' => [9.2233720368547758e18, 9.2233720368547758e18, 1.0]] as $q => $esperado) {
        $v = array_merge(...array_map('array_values', $bd->consultar($q)));
        $r[] = $v === $esperado ?: "$q: " . json_encode($v, JSON_PRESERVE_ZERO_FRACTION);
    }
    foreach (['SELECT SUM(DISTINCT r), TOTAL(DISTINCT r), AVG(DISTINCT r) FROM kd', 'SELECT TOTAL(DISTINCT i), AVG(DISTINCT i) FROM kx'] as $q) {
        $v = array_values($disco->ejecutar(JsonSQLDB\Parser::analizar($q))[0]);
        $m = array_values($bd->consultar($q)[0]);
        $r[] = $v === $m && in_array($m, [[21.6, 21.6, 3.6], [1.0, 0.3333333333333333]], true) ?: "$q: por el disco " . json_encode($v) . ', en memoria ' . json_encode($m);
    }
    return array_filter($r, static fn($x) => $x !== true) === [] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('SUM, TOTAL, AVG, SUM(DISTINCT) y TOTAL en una ventana dan bit a bit lo que SQLite 3.45 en 200 conjuntos al azar: enteros cerca de 2^63 que desbordan, cerca de 2^52, decimales de 1e-20 a 1e100 y medios', function () use ($bd) {
    if (PHP_VERSION_ID < 80100) {
        return null;                    // PDO de PHP 8.0 trae los decimales de SQLite con 15 cifras
    }
    mt_srand(11);
    $uno = static function (): string {
        switch (mt_rand(0, 9)) {
            case 0: return (string)(mt_rand(0, 1) ? PHP_INT_MAX - mt_rand(0, 1000) : PHP_INT_MIN + mt_rand(0, 1000));
            case 1: return (string)mt_rand(-1000, 1000);
            case 2: return sprintf('%.17e', (mt_rand(0, 1) ? 1 : -1) * 10 ** mt_rand(-20, 100));
            case 3: return mt_rand(-1000, 1000) . '.5';
            case 4: return (string)((mt_rand(0, 1) ? 1 : -1) * 4503599627370496 + mt_rand(-5, 5));
            case 5: return '1e16';
            case 6: return '-1e16';
            case 7: return (string)mt_rand(-9000000000000000000, 9000000000000000000);
            case 8: return '0.1';
            default: return (string)mt_rand(-5, 5);
        }
    };
    $mal = [];
    for ($c = 0; $c < 200; $c++) {
        $filas = [];
        for ($i = 1, $n = mt_rand(1, 8); $i <= $n; $i++) {
            $x = $uno();
            $filas[] = "($i, " . (preg_match('/[.e]/', $x) ? "NULL, $x)" : "$x, NULL)");
        }
        $l = sqliteParaComparar();
        foreach (['DROP TABLE IF EXISTS bab', 'CREATE TABLE bab (id INTEGER PRIMARY KEY, i INTEGER, r REAL)', 'INSERT INTO bab VALUES ' . implode(',', $filas)] as $q) {
            $bd->consultar($q);
            $l->exec($q);
        }
        foreach (['SELECT SUM(COALESCE(i, r)), TOTAL(COALESCE(i, r)), AVG(COALESCE(i, r)) FROM bab', 'SELECT SUM(DISTINCT COALESCE(i, r)) FROM bab',
                  'SELECT TOTAL(COALESCE(i, r)) OVER (ORDER BY id) FROM bab ORDER BY id'] as $q) {
            try { $a = json_encode(array_map('array_values', $bd->consultar($q)), JSON_PRESERVE_ZERO_FRACTION); } catch (JsonSqlDbError $e) { $a = 'error'; }
            try { $b = json_encode($l->query($q)->fetchAll(PDO::FETCH_NUM), JSON_PRESERVE_ZERO_FRACTION); } catch (PDOException $e) { $b = 'error'; }
            if ($a !== $b) {
                $mal[] = "$q con " . implode(',', $filas) . ": $a / $b";
            }
        }
    }
    $bd->consultar('DROP TABLE bab');
    return $mal === [] ?: count($mal) . ' distintas; ' . substr(implode(' | ', $mal), 0, 900);
});
chk('SUM, TOTAL y AVG(DISTINCT) por el disco suman en el orden en que aparecen los valores: el mismo redondeo y el mismo error de desbordamiento que en memoria y en SQLite (2.10, antes de publicarla: en el de las particiones)', function () use ($bd, $sqlite, $raiz) {
    mt_srand(7);
    $v = [];
    for ($i = 1; $i <= 300; $i++) {
        $v[] = "($i, " . sprintf('%.17g', (mt_rand() / mt_getrandmax() - 0.5) * 10 ** mt_rand(-5, 17)) . ')';
    }
    foreach (['CREATE TABLE kdo (id INTEGER PRIMARY KEY, r REAL)', 'INSERT INTO kdo VALUES ' . implode(',', $v),
              'CREATE TABLE kdi (id INTEGER PRIMARY KEY, i INTEGER)',
              'INSERT INTO kdi VALUES (1, 9223372036854775807), (2, 2), (3, -2), (4, -9223372036854775808), (5, 1), (6, -1)'] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    $disco = new JsonSQLDB\Select(new JsonSQLDB\Catalog(new JsonSQLDB\Storage($raiz, 'c')));
    $p = new ReflectionProperty(JsonSQLDB\Select::class, 'enDisco');
    PHP_VERSION_ID < 80100 && $p->setAccessible(true);         // desde 8.5 avisa de obsoleta
    $p->setValue($disco, true);
    $dar = static function (callable $f): string {
        try {
            return json_encode(array_values($f()[0]), JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'overflow') ? 'integer overflow' : $e->getMessage();
        }
    };
    $mal = [];
    foreach (['SELECT SUM(DISTINCT r), TOTAL(DISTINCT r), AVG(DISTINCT r) FROM kdo', 'SELECT SUM(DISTINCT i) FROM kdi', 'SELECT TOTAL(DISTINCT i), AVG(DISTINCT i) FROM kdi'] as $q) {
        $en = [$dar(static fn() => $disco->ejecutar(JsonSQLDB\Parser::analizar($q))), $dar(static fn() => $bd->consultar($q)),
               // PDO de PHP 8.0 trae los decimales con 15 cifras: allí, solo memoria y disco
               PHP_VERSION_ID < 80100 ? null : $dar(static fn() => $sqlite->query($q)->fetchAll(PDO::FETCH_ASSOC))];
        if ($en[0] !== $en[1] || ($en[2] !== null && $en[1] !== $en[2])) {
            $mal[] = "$q: disco $en[0], memoria $en[1], SQLite $en[2]";
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('un booleano de un JSON editado a mano es 1 o 0 también al agrupar, en DISTINCT, en SUM y en las cuentas, y una lista no se junta con el 1 (2.10: true iba con el texto \'1\', sumaba 0 y true + 1 daba 1)', function () use ($bd, $raiz) {
    $bd->consultar('CREATE TABLE jbo (id INTEGER PRIMARY KEY, x INTEGER, t TEXT)');
    $bd->consultar("INSERT INTO jbo VALUES (1, 1, '1'), (2, 1, ''), (3, 1, 'a'), (4, 0, 'b'), (5, 0, 'c')");
    // Como lo dejaría quien edita el fichero: true y false en lugar de 1 y 0
    $f = "$raiz/c/jbo.json";
    file_put_contents($f, str_replace(['{"id":1,"x":1', '{"id":2,"x":1', '{"id":4,"x":0'], ['{"id":1,"x":true', '{"id":2,"x":true', '{"id":4,"x":false'], (string)file_get_contents($f)));
    $r = [$bd->consultar('SELECT SUM(x) AS s, TOTAL(x) AS t, AVG(x) AS a, COUNT(DISTINCT x) AS d FROM jbo')[0],
          array_column($bd->consultar('SELECT x, COUNT(*) AS n FROM jbo GROUP BY x ORDER BY x'), 'n'),
          count($bd->consultar('SELECT DISTINCT x FROM jbo')), count($bd->consultar('SELECT x FROM jbo UNION SELECT t FROM jbo WHERE id <= 2')),
          // y en las cuentas (2.10: true + 1 daba 1)
          array_column($bd->consultar('SELECT x + 1 AS y FROM jbo WHERE id IN (1, 4) ORDER BY id'), 'y')];
    // Y una lista donde iba un 1 no se junta con él
    $bd->consultar('CREATE TABLE jba (id INTEGER PRIMARY KEY, x INTEGER)');
    $bd->consultar('INSERT INTO jba VALUES (1, 1), (2, 1), (3, 1)');
    file_put_contents("$raiz/c/jba.json", str_replace('{"id":3,"x":1', '{"id":3,"x":[1,2]', (string)file_get_contents("$raiz/c/jba.json")));
    $r[] = $bd->consultar('SELECT COUNT(DISTINCT x) AS d FROM jba')[0]['d'];
    return $r === [['s' => 3, 't' => 3.0, 'a' => 0.6, 'd' => 2], [2, 3], 2, 4, [2, 1], 2] ?: json_encode($r);
});
chk('SUM, TOTAL y AVG que se desbordan a infinito en el último valor dan NULL, como SQLite 3.45 (su error queda en -INF y la suma más él es NaN); con más valores detrás, infinito, como allí (2.10, antes de publicarla: siempre infinito)', function () use ($bd, $sqlite) {
    foreach (['CREATE TABLE sov (id INTEGER PRIMARY KEY, g INTEGER, x REAL)',
              'INSERT INTO sov VALUES (1, 1, 1e308), (2, 1, 1e308), (3, 2, 1e308), (4, 2, 1e308), (5, 2, 1), (6, 3, -1e308), (7, 3, -1e308),
               (8, 4, 1e308), (9, 4, 1e308), (10, 4, -1e308), (11, 5, 1e308), (12, 5, 0.1), (13, 6, 1e308), (14, 6, -1e308), (15, 7, 1e308), (16, 7, 9e307)'] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    $f = static fn(string $e): string => "$e IS NULL, $e > 1e308, $e < -1e308";
    return filasComoSqlite(['SELECT g, ' . $f('SUM(x)') . ', ' . $f('TOTAL(x)') . ', ' . $f('AVG(x)') . ' FROM sov GROUP BY g ORDER BY g',
        'SELECT g, ' . $f('SUM(x * 10)') . ', ' . $f('TOTAL(x * 10)') . ' FROM sov WHERE g >= 5 GROUP BY g ORDER BY g',
        'SELECT ' . $f('SUM(x)') . ' FROM sov WHERE g = 1', 'SELECT ' . $f('SUM(DISTINCT x)') . ', ' . $f('AVG(DISTINCT x)') . ' FROM sov WHERE g IN (2, 7)',
        'SELECT id, ' . $f('SUM(x) OVER (ORDER BY id ROWS BETWEEN 1 PRECEDING AND CURRENT ROW)') . ' FROM sov WHERE g = 7 ORDER BY id',
        'SELECT id, ' . $f('SUM(x) OVER (ORDER BY id)') . ' FROM sov WHERE g <= 2 ORDER BY id']);
});
chk('NaN (INF - INF, INF * 0, INF / INF, MOD(INF, 2), LOG(INF, INF), SUM de +INF y -INF) es NULL, como en SQLite (2.10: pasaba un WHERE x > 2 y se ordenaba en cualquier sitio)', function () use ($bd, $sqlite) {
    foreach (['CREATE TABLE nanx (id INTEGER PRIMARY KEY, x REAL)', 'INSERT INTO nanx VALUES (1, 1e308), (2, -1e308), (3, 2.0), (4, 3.0)'] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    return filasComoSqlite(['SELECT (x * 10) - (x * 10) IS NULL FROM nanx ORDER BY id', 'SELECT (x * 10) * 0 IS NULL FROM nanx ORDER BY id',
        'SELECT (x * 10) / (x * 10) IS NULL FROM nanx ORDER BY id', 'SELECT id FROM nanx WHERE (x * 10) - (x * 10) > 2 ORDER BY id',
        'SELECT id FROM nanx ORDER BY (x * 10) - (x * 10), id', 'SELECT SUM(x * 10) IS NULL, TOTAL(x * 10) IS NULL, AVG(x * 10) IS NULL FROM nanx',
        'SELECT SUM(x * 10) OVER (ORDER BY id) IS NULL FROM nanx ORDER BY id', 'SELECT MOD(x * 10, 2) IS NULL FROM nanx ORDER BY id',
        'SELECT LOG(x * 10, x * 10) IS NULL FROM nanx WHERE id = 1', 'SELECT id FROM nanx WHERE (x * 10) * 0 IS NULL ORDER BY id',
        'SELECT id FROM nanx WHERE (x * 10) / (x * 10) IS NULL ORDER BY id', 'SELECT id FROM nanx WHERE (x * 10) + (x * -10) IS NULL ORDER BY id',
        'SELECT (x * 10) + (x * -10) IS NULL FROM nanx ORDER BY id']);
});
chk('Un decimal pasado a texto (columna TEXT, CAST, ||) lleva 15 cifras y el exponente como SQLite, y un infinito es Inf (2.10: 14 cifras, 123456789.12346, 1.0E+25, y el infinito, un texto vacío)', function () use ($bd, $sqlite) {
    foreach (['CREATE TABLE dtx (id INTEGER PRIMARY KEY, t TEXT, u)',
              'INSERT INTO dtx VALUES (1, 123456789.123456789, 123456789.123456789), (2, 1e100, 1e-5), (3, 1e15, 1e14), (4, -2.5e-7, 0.1 + 0.2), (5, 9e999, -9e999)'] as $q) {
        $bd->consultar($q);
        $sqlite->exec($q);
    }
    return filasComoSqlite(['SELECT t, typeof(t) FROM dtx ORDER BY id', 'SELECT CAST(u AS TEXT) AS a, u || \'\' AS b FROM dtx ORDER BY id',
        "SELECT id FROM dtx WHERE t = '123456789.123457'", "SELECT 1e25 || '' AS a, CAST(1.0 / 3 AS TEXT) AS b, CAST(-0.0 AS TEXT) AS c, 100.0 || 'x' AS d",
        "SELECT CAST(9e999 AS TEXT) AS a, CAST(-9e999 AS TEXT) AS b, 9e999 || '' AS c", "SELECT id FROM dtx WHERE t = 'Inf'"]);
});
chk('Un decimal entero de 9·10^18 a 2^63 y el mismo entero son el mismo valor también en un índice: clave foránea de INTEGER a REAL y al revés, UNIQUE, =, IN, COUNT y JOIN, como SQLite (2.10: la clave foránea no lo encontraba)', function () use ($raiz) {
    @mkdir("$raiz/fg", 0775, true);
    $r = escriturasComoSqlite("$raiz/fg", ['CREATE TABLE fp (id INTEGER PRIMARY KEY, r REAL UNIQUE, k INTEGER UNIQUE)',
        'CREATE TABLE fh (id INTEGER PRIMARY KEY, p INTEGER REFERENCES fp (r), q REAL REFERENCES fp (k))',
        // -9.2e18, que PDO de PHP 8.0 trae bien con sus 15 cifras; -2^63, abajo
        'INSERT INTO fp VALUES (1, 9.1e18, 9100000000000000000), (2, -9.2e18, -9223372036854775807), (3, 2.5, 3)',
        'INSERT INTO fh VALUES (1, 9100000000000000000, 9.1e18), (2, -9200000000000000000, NULL)'], [
        ['INSERT INTO fh VALUES (3, NULL, -9223372036854775808.0)', null],
        ['INSERT INTO fp VALUES (4, 9100000000000000000, 4)', null],
        ['INSERT INTO fp VALUES (4, 4.5, 9.1e18)', null],
        ['DELETE FROM fp WHERE id = 1', null],
        ['UPDATE fp SET r = 1.5 WHERE id = 2', null],
        ['UPDATE fh SET p = 9.1e18 WHERE id = 2', null],
        ['SELECT id FROM fp WHERE r = 9100000000000000000', null],
        ['SELECT id FROM fp WHERE r IN (9100000000000000000, -9200000000000000000)', null],
        ['SELECT id FROM fp WHERE k = 9.1e18', null],
        ['SELECT COUNT(*) AS id FROM fp WHERE r = 9100000000000000000', null],
        ['SELECT fh.id AS id, fp.id AS pid FROM fh JOIN fp ON fp.r = fh.p', null],
    ]);
    if ($r !== true) {
        return $r;
    }
    // Y la igualdad va por el índice (2.10: pasado 9·10^18, leyendo la tabla)
    Database::crear('x', "$raiz/fg");
    $b = new Database('x', "$raiz/fg");
    $b->consultar('CREATE TABLE fp (id INTEGER PRIMARY KEY, r REAL UNIQUE)');
    $b->consultar('INSERT INTO fp VALUES (1, 9.1e18), (2, 3.5), (3, -9223372036854775808.0)');
    $via = [$b->consultar('EXPLAIN SELECT id FROM fp WHERE r = 9100000000000000000')[0]['acceso'],
            $b->consultar('EXPLAIN SELECT id FROM fp WHERE r IN (9.1e18, -9223372036854775808)')[0]['acceso'],
            array_column($b->consultar('SELECT id FROM fp WHERE r IN (9.1e18, -9223372036854775808) ORDER BY id'), 'id')];
    unset($b);
    Database::borrar('x', "$raiz/fg");
    return str_starts_with($via[0], 'índice') && str_starts_with($via[1], 'índice') && $via[2] === [1, 3] ?: json_encode($via, JSON_UNESCAPED_UNICODE);
});
chk('un decimal pasado a texto lleva 15 cifras también con otro «precision» en php.ini (17, -1 o 5), y aunque cambie a mitad del proceso', function () {
    $antes = ini_get('precision');
    $mal   = [];
    foreach (['17', '-1', '5', $antes] as $p) {
        ini_set('precision', $p);
        $r = array_map([JsonSQLDB\Valor::class, 'aTexto'], [0.1 + 0.2, 1 / 3, 2.5, 1e14, 123456789.123456789, 1234567890123456.0, 0.1]);
        if ($r !== ['0.3', '0.333333333333333', '2.5', '100000000000000.0', '123456789.123457', '1.23456789012346e+15', '0.1']) { $mal[] = "$p: " . implode(' ', $r); }
    }
    return $mal === [] ?: implode(' | ', $mal);
});

chk('con otro serialize_precision (10 o 17, puesto por la aplicación como podría estarlo en php.ini), los decimales se guardan con las cifras justas en los datos, en los índices, en SHOW CREATE TABLE, al deshacer una transacción o un lote y al reparar un fichero cuando acaban, y la aplicación sigue con el suyo, también en lo suyo que llama el motor (2.10: con 10, 0.30000000000000004 se guardaba 0.3; con 17, 0.1 era 0.10000000000000001 y el índice no lo encontraba)', function () use ($bd, $raiz) {
    $antes = ini_get('serialize_precision');
    $mal = [];
    try {
        foreach (['10', '17'] as $sp) {
            ini_set('serialize_precision', $sp);
            $t = "sp$sp";
            $bd->consultar("CREATE TABLE $t (id INTEGER PRIMARY KEY, x REAL, y REAL DEFAULT 0.30000000000000004)");
            $bd->consultar("CREATE INDEX {$t}_x ON $t (x)");
            $bd->transaccion(static function (JsonSQLDB\Database $db) use ($t, $sp, &$mal): void {
                $db->consultar("INSERT INTO $t (id, x) VALUES (1, 0.1 + 0.2), (2, 1.0 / 3), (3, 0.1)");
                if (ini_get('serialize_precision') !== $sp) {
                    $mal[] = "$sp: dentro de la transacción, " . ini_get('serialize_precision');
                }
            });
            // En el fichero de la tabla y en el del índice, y lo que se lee
            $f = file_get_contents("$raiz/c/$t.json") . file_get_contents("$raiz/c/$t.idx.{$t}_x.json");
            foreach (['"x":0.30000000000000004,', '"x":0.3333333333333333,', '"x":0.1,', ':0.30000000000000004"', ':0.1"'] as $s) {
                if (!str_contains($f, $s)) {
                    $mal[] = "$sp: en el disco no está $s";
                }
            }
            $r = [$bd->consultar("SELECT id FROM $t WHERE x = 0.1"), $bd->consultar("SELECT id, x, y FROM $t WHERE x = 0.1 + 0.2"), $bd->consultar("SHOW CREATE TABLE $t")[0]['sql']];
            if ($r[0] !== [['id' => 3]] || $r[1] !== [['id' => 1, 'x' => 0.1 + 0.2, 'y' => 0.1 + 0.2]] || !str_contains($r[2], 'DEFAULT 0.30000000000000004')) {
                $mal[] = "$sp: " . json_encode($r);
            }
            if (ini_get('serialize_precision') !== $sp) {
                $mal[] = "$sp: después, " . ini_get('serialize_precision');
            }
        }
        // Un fichero editado a mano que se lee en una transacción se repara
        // al acabarla, fuera de cada sentencia
        // Deshacer una transacción y un lote reescribe rev.json, con el rango
        // del índice: al leerlo, MIN y un < por el índice
        $bd->consultar('CREATE TABLE spd (id INTEGER PRIMARY KEY, x REAL)');
        $bd->consultar('CREATE INDEX spd_x ON spd (x)');
        $bd->consultar('INSERT INTO spd VALUES (1, 1.0 / 7), (2, 0.5)');
        ini_set('serialize_precision', '10');
        try {
            $bd->transaccion(static function (JsonSQLDB\Database $db): void {
                $db->consultar('INSERT INTO spd VALUES (3, 0.25)');
                ini_set('serialize_precision', '12');          // la aplicación lo cambia: después sigue así
                throw new RuntimeException('deshacer');
            });
        } catch (RuntimeException) {
        }
        if (ini_get('serialize_precision') !== '12') {
            $mal[] = 'el que dejó la aplicación en la transacción: ' . ini_get('serialize_precision');
        }
        ini_set('serialize_precision', '10');
        $bd->lote(['INSERT INTO spd VALUES (4, 0.75)', 'ROLLBACK']);
        $r = [$bd->consultar('SELECT MIN(x) AS m FROM spd'), $bd->consultar('SELECT id FROM spd WHERE x < 0.14285714286'), ini_get('serialize_precision')];
        if ($r !== [[['m' => 1.0 / 7]], [['id' => 1]], '10'] || !str_contains((string)file_get_contents("$raiz/c/spd.rev.json"), '0.14285714285714285')) {
            $mal[] = 'al deshacer: ' . json_encode($r);
        }
        // Un proceso que muere a mitad de una transacción: la siguiente la
        // deshace al bloquear, antes de lo suyo, y reescribe rev.json
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
            . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . ' (new JsonSQLDB\Database("c", ' . var_export($raiz, true) . '))->transaccion(static function ($db): void {'
            . ' $db->consultar("INSERT INTO spd VALUES (5, 0.125)"); exit(0); });';
        shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1');
        $bd->transaccion(static fn(JsonSQLDB\Database $db) => $db->consultar('SELECT 1'));
        $r = [$bd->consultar('SELECT MIN(x) AS m, COUNT(*) AS n FROM spd'), ini_get('serialize_precision')];
        if ($r !== [[['m' => 1.0 / 7, 'n' => 2]], '10'] || !str_contains((string)file_get_contents("$raiz/c/spd.rev.json"), '0.14285714285714285')) {
            $mal[] = 'al deshacer la de un proceso que murió: ' . json_encode($r);
        }
        // Lo de la aplicación que llama el motor, con lo suyo: quien autoriza
        // la sentencia y quien recibe las filas de consultarPorFilas()
        $vistos = [];
        $bd->consultarPorFilas('SELECT id FROM spd', [], static function () use (&$vistos): void { $vistos[] = ini_get('serialize_precision'); },
            static function () use (&$vistos): void { $vistos[] = ini_get('serialize_precision'); });
        if ($vistos !== ['10', '10', '10'] || ini_get('serialize_precision') !== '10') {
            $mal[] = 'lo de la aplicación: ' . json_encode($vistos);
        }
        $bd->consultar('CREATE TABLE spr (id INTEGER PRIMARY KEY, x REAL)');
        $bd->consultar('INSERT INTO spr VALUES (1, 0.1 + 0.2), (2, 0.5)');
        file_put_contents("$raiz/c/spr.json", str_replace('{"id":2,', '{"id": 2,', (string)file_get_contents("$raiz/c/spr.json")));
        ini_set('serialize_precision', '10');
        $bd->transaccion(static fn(JsonSQLDB\Database $db) => $db->consultar('SELECT * FROM spr'));
        $f = (string)file_get_contents("$raiz/c/spr.json");
        if (!str_contains($f, '{"id":2,"x":0.5}') || !str_contains($f, '"x":0.30000000000000004}') || ini_get('serialize_precision') !== '10') {
            $mal[] = 'la reparación al acabar la transacción: ' . ini_get('serialize_precision') . " $f";
        }
    } finally {
        ini_set('serialize_precision', (string)$antes);
    }
    return $mal === [] ?: implode('; ', $mal);
});
chk('un decimal pasado a texto no depende del locale: con uno de coma decimal (setlocale de la aplicación) sigue siendo 0.3 y no 0,3 (2.10, antes de publicarla)', function () use ($bd) {
    $antes = setlocale(LC_ALL, '0');
    if (setlocale(LC_ALL, 'es_ES.UTF-8', 'de_DE.UTF-8', 'fr_FR.UTF-8', 'es_ES', 'de_DE', 'Spanish_Spain', 'German_Germany') === false || localeconv()['decimal_point'] !== ',') {
        setlocale(LC_ALL, (string)$antes);
        return null;                    // sin un locale de coma instalado, no hay nada que ver
    }
    try {
        $bd->consultar('CREATE TABLE loc (id INTEGER PRIMARY KEY, t TEXT)');
        $bd->consultar('INSERT INTO loc VALUES (1, 0.1 + 0.2), (2, 123456789.123456789), (3, 1e20), (4, 2.5e-7)');
        $r = [array_column($bd->consultar('SELECT t FROM loc ORDER BY id'), 't'),
              $bd->consultar("SELECT CAST(1.5 AS TEXT) AS a, 0.25 || '' AS b, QUOTE(0.1 + 0.2) AS c")[0]];
    } finally {
        setlocale(LC_ALL, (string)$antes);
    }
    return $r === [['0.3', '123456789.123457', '1.0e+20', '2.5e-07'], ['a' => '1.5', 'b' => '0.25', 'c' => '3.0000000000000004e-01']] ?: json_encode($r);
});

chk('QUOTE de un decimal vuelve a dar el mismo número, como en SQLite: 15 cifras si bastan y si no, las que hacen falta, con exponente (2.10: 14 cifras, y 0.30000000000000004 quedaba 0.3)', function () use ($bd, $sqlite) {
    $mal = [];
    foreach (['0.30000000000000004', '1.0 / 3', '-2.0 / 3', '123456789.123456789', '9007199254740993.0', '123456789012345678.0', '1.7976931348623157e308', '5e-324',
              '1e100', '0.1', '1.0', '-0.0', '1e-5', '100.5', '9e999', '-9e999'] as $x) {
        $a = $bd->consultar("SELECT QUOTE($x) AS q")[0]['q'];
        $b = $sqlite->query("SELECT QUOTE($x)")->fetchColumn();
        $v = $bd->consultar("SELECT $x AS v")[0]['v'];     // de aquí: PDO de PHP 8.0 lo trae con 15 cifras
        // El mismo texto; o, si SQLite escribe alguna cifra más, el mismo número y la misma forma
        if ($a !== $b && !((float)$a === (float)$b && (float)$a === $v && preg_match('/^-?\d\.\d+e[+-]\d{2,3}$/', $a))) {
            $mal[] = "$x: $a / $b";
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});

chk('SHOW CREATE TABLE escribe el DEFAULT de un decimal con todas sus cifras, y la tabla hecha con ese texto tiene el mismo valor por defecto (2.10: 0.30000000000000004 salía 0.3)', function () use ($bd) {
    $bd->consultar('CREATE TABLE sdf (id INTEGER PRIMARY KEY, r REAL DEFAULT 0.30000000000000004, s REAL DEFAULT 1.5, t REAL DEFAULT 1e100, u REAL DEFAULT -9e999)');
    $sql = $bd->consultar('SHOW CREATE TABLE sdf')[0]['sql'];
    $bd->consultar(str_replace('CREATE TABLE sdf', 'CREATE TABLE sdf2', $sql));
    $bd->consultar('ALTER TABLE sdf2 DROP COLUMN u');           // un INSERT con ese DEFAULT no se puede guardar
    $bd->consultar('INSERT INTO sdf2 (id) VALUES (1)');
    $f = $bd->consultar('SELECT r, s, t FROM sdf2')[0];
    return str_contains($sql, 'DEFAULT 0.30000000000000004') && str_contains($sql, 'DEFAULT 1.5') && $f === ['r' => 0.30000000000000004, 's' => 1.5, 't' => 1e100]
        ?: $sql . ' / ' . json_encode($f);
});

borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
