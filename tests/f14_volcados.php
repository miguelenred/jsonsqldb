<?php
declare(strict_types=1);

/**
 * Los volcados del panel, cargados de verdad en SQLite y en MySQL/MariaDB.
 * Ejecutar: php tests/f14_volcados.php
 *
 * Crea una base con todo lo que un volcado tiene que llevar bien —cada tipo,
 * claves foráneas con sus acciones, una tabla que se apunta a sí misma
 * guardada hijo antes que padre, un ciclo entre dos tablas, clave primaria y
 * única compuestas, índices (dos con el mismo nombre en tablas distintas),
 * autoincremento, textos con comillas, barras invertidas, saltos de línea,
 * acentos y emojis, enteros de 64 bits, decimales negativos, fechas con
 * milisegundos y NULL— y comprueba que cada volcado se carga sin un error y
 * deja exactamente los mismos datos, con las claves foráneas y los índices.
 *
 * Y al revés: importa en jsonSQLDB volcados reales de SQLite (sqlite3 .dump)
 * y de MariaDB (mariadb-dump), guardados en tests/volcados/, y comprueba que
 * los datos llegan iguales a los de la base de origen; con un servidor MySQL,
 * además crea allí la base de origen, la vuelca con su mysqldump y la importa.
 *
 * SQLite necesita la extensión sqlite3. MySQL o MariaDB necesitan un servidor
 * y la extensión mysqli; se le dice dónde con la variable de entorno
 *   JSONSQLDB_TEST_MYSQL=servidor:usuario:contraseña
 * Sin ellos, cada parte se salta y lo dice.
 *
 * https://miguelenred.es/jsonsqldb
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_CACHE_RESULTADOS', 0);
define('JSONSQLDB_DATA_PATH', sys_get_temp_dir() . '/jsonsqldb_test_f14');
define('JSONSQLDB_LOG_PATH', JSONSQLDB_DATA_PATH . '/logs');      // el registro, con los datos de la prueba
define('ADMIN_CONEXION', 'directa');
require dirname(__DIR__) . '/engine/bootstrap.php';
require dirname(__DIR__) . '/jsonsqldbadmin/config.dist.php';
require dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php';
foreach (['Idioma', 'Exportar', 'Api', 'Traductor', 'Importar'] as $clase) {
    require dirname(__DIR__) . "/jsonsqldbadmin/lib/$clase.php";
}
Idioma::elegir('es');                    // los resúmenes que se comprueban, en español

use JsonSQLDB\Database;

$raiz = JSONSQLDB_DATA_PATH;
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
    try { $r = $fn(); } catch (Throwable $e) { $r = get_class($e) . ': ' . $e->getMessage(); }
    if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
    elseif ($r === null) { echo "  --   $titulo (se salta)\n"; }
    else { $ko++; echo "  FALLO $titulo -> $r\n"; }
}

// ---------------------------------------------------------------------
// La base de partida
// ---------------------------------------------------------------------
borrarArbol($raiz);
mkdir($raiz, 0775, true);
Database::crear('v', $raiz);
$bd = new Database('v', $raiz);
foreach ([
    'CREATE TABLE clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, nombre VARCHAR(80) NOT NULL,
        saldo DECIMAL(10,2) DEFAULT 0, ratio DOUBLE, alta DATETIME, activo BOOLEAN DEFAULT 1, nota TEXT DEFAULT \'—\')',
    'CREATE TABLE pedidos (id INTEGER PRIMARY KEY, cid INTEGER NOT NULL, total DECIMAL(10,2), fecha DATETIME,
        FOREIGN KEY (cid) REFERENCES clientes (id) ON DELETE CASCADE ON UPDATE NO ACTION)',
    'CREATE TABLE emp (id INTEGER PRIMARY KEY, jefe INTEGER, n VARCHAR(20), FOREIGN KEY (jefe) REFERENCES emp (id) ON DELETE SET NULL)',
    'CREATE TABLE lineas (pedido INTEGER, num INTEGER, ref VARCHAR(10), cant INTEGER, PRIMARY KEY (pedido, num), UNIQUE (pedido, ref))',
    'CREATE TABLE ca (id INTEGER PRIMARY KEY, cb INTEGER)',
    'CREATE TABLE cb (id INTEGER PRIMARY KEY, ca INTEGER, FOREIGN KEY (ca) REFERENCES ca (id))',
    'ALTER TABLE ca ADD CONSTRAINT fk_ca_cb FOREIGN KEY (cb) REFERENCES cb (id)',
    'CREATE INDEX ix_fecha ON pedidos (fecha)',
    'CREATE INDEX ix_fecha ON clientes (alta)',
    // 2.8: valores por defecto que se calculan al insertar; cada motor tiene que aceptarlos
    'CREATE TABLE marcas (id INTEGER PRIMARY KEY, creado DATETIME DEFAULT CURRENT_TIMESTAMP, dia DATE DEFAULT CURRENT_DATE,
        codigo TEXT DEFAULT (upper(\'ab\') || \'-1\'))',
    'INSERT INTO marcas (id) VALUES (1), (2)',
    'CREATE INDEX ix_nombre_saldo ON clientes (nombre, saldo)',
] as $q) {
    $bd->consultar($q);
}
$textos = ["O'Brien", 'barra \\ y \\n literal', "dos\nlíneas\r\nCRLF", 'acentos áéíóú ñ Ç', 'emoji 😀 中文', '"comillas"', '', '%_ comodines'];
$clientes = [];
for ($i = 1; $i <= 40; $i++) {
    $clientes[] = [$i, "c$i@e.es", $textos[$i % 8] . " $i", $i % 7 === 0 ? -1234.56 : $i * 10.25,
                   $i % 5 === 0 ? null : $i / 3, $i % 4 === 0 ? null : sprintf('2026-%02d-%02d %02d:%02d:%02d.%03d', $i % 12 + 1, $i % 28 + 1, $i % 24, $i, $i, $i * 7),
                   $i % 2, $i % 9 === 0 ? null : $textos[($i + 3) % 8]];
}
$clientes[] = [41, 'grande@e.es', 'enteros', 99999999.99, 1.0e25, '2026-01-01', 0, 'PHP_INT_MAX'];
foreach ($clientes as $f) {
    $bd->consultar('INSERT INTO clientes VALUES (?,?,?,?,?,?,?,?)', $f);
}
for ($i = 1; $i <= 60; $i++) {
    $bd->consultar('INSERT INTO pedidos VALUES (?,?,?,?)', [$i, $i % 41 + 1, $i * 3.5, $i % 3 === 0 ? null : "2026-03-0" . ($i % 9 + 1)]);
}
$bd->consultar('INSERT INTO pedidos VALUES (?,?,?,?)', [61, 41, PHP_INT_MAX, null]);
$bd->consultar("INSERT INTO emp VALUES (1, NULL, 'a'), (2, 1, 'b'), (3, 1, 'c'), (4, 3, 'd')");
$bd->consultar('UPDATE emp SET jefe = 4 WHERE id = 2');           // la 2 depende de la 4: hijo antes que padre
$bd->consultar("INSERT INTO lineas VALUES (1, 1, 'A', 2), (1, 2, 'B', 1), (2, 1, 'A', 5)");
$bd->consultar('INSERT INTO ca VALUES (1, NULL)');
$bd->consultar('INSERT INTO cb VALUES (1, 1)');
$bd->consultar('UPDATE ca SET cb = 1 WHERE id = 1');

// Lo mismo que reúne el panel para exportar
$tablas = [];
foreach ($bd->consultar('SHOW TABLES') as $t) {
    $n = (string)$t['tabla'];
    $tablas[] = ['tabla' => $n, 'columnas' => $bd->consultar("SHOW SCHEMA $n"), 'claves' => $bd->consultar("SHOW KEYS FROM $n"),
                 'filas' => $bd->consultar("SELECT * FROM $n")];
}
$triggers = $bd->consultar('SHOW TRIGGERS');
$vistas   = $bd->consultar('SHOW VIEWS');
$indices  = $bd->consultar('SHOW INDEXES');
$original = [];
foreach ($tablas as $t) {
    $original[$t['tabla']] = $t['filas'];
}

/** Filas comparables entre motores: números a 6 decimales, fechas completas con milisegundos. */
function normal(array $filas): array {
    $o = [];
    foreach ($filas as $f) {
        $x = [];
        foreach ($f as $c => $v) {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2}(\.\d+)?)?)?$/', $v)) {
                $v = substr($v . ' 00:00:00.000', 0, 10) . ' ' . substr(substr($v, 11) . substr('00:00:00.000', strlen(substr($v, 11))), 0, 12);
            } elseif (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v) && preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $v))) {
                $v = abs((float)$v) >= 1e15 ? sprintf('%.15e', (float)$v) : round((float)$v, 6);
            }
            $x[strtolower((string)$c)] = $v;
        }
        ksort($x);
        $o[] = json_encode($x, JSON_UNESCAPED_UNICODE);
    }
    sort($o);
    return $o;
}

// ---------------------------------------------------------------------
echo "\n== Volcado para SQLite ==\n";
$sqlite = Exportar::volcado('v', $tablas, $triggers, $vistas, $indices, 'sqlite');
chk('se carga en SQLite, con las claves foráneas activas, y deja los mismos datos', function () use ($sqlite, $original) {
    if (!class_exists('SQLite3')) { return null; }
    $s = new SQLite3(':memory:');
    $s->enableExceptions(true);
    $s->exec('PRAGMA foreign_keys = ON');
    // El ALTER del ciclo no lo admite SQLite: se cargan los datos sin esa clave
    $sinCiclo = preg_replace('/^ALTER TABLE .*$/m', '', $sqlite);
    $s->exec((string)$sinCiclo);
    foreach ($original as $t => $filas) {
        $r = $s->query("SELECT * FROM \"$t\"");
        $leidas = [];
        while (($f = $r->fetchArray(SQLITE3_ASSOC)) !== false) { $leidas[] = $f; }
        if (normal($leidas) !== normal($filas)) { return "la tabla $t no tiene los mismos datos"; }
    }
    $idx = (int)$s->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name IN ('pedidos_ix_fecha', 'clientes_ix_fecha', 'ix_nombre_saldo')");
    return $idx === 3 ?: "faltan índices: hay $idx de 3";
});
chk('el ciclo entre dos tablas es lo único que va con ALTER TABLE, y se dice', fn() =>
    substr_count($sqlite, "\nALTER TABLE") === 1 && str_contains($sqlite, 'forman un ciclo') ?: 'ALTER: ' . substr_count($sqlite, "\nALTER TABLE"));

// ---------------------------------------------------------------------
echo "\n== Volcado para MySQL / MariaDB ==\n";
$mysqlSql = Exportar::volcado('v', $tablas, $triggers, $vistas, $indices, 'mysql');
$conexion = (string)getenv('JSONSQLDB_TEST_MYSQL');
$m = null;
if ($conexion !== '' && class_exists('mysqli')) {
    [$host, $usuario, $clave] = array_pad(explode(':', $conexion, 3), 3, '');
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli($host, $usuario, $clave);
    if ($m->connect_errno) {
        echo "  (no se puede conectar a $host: {$m->connect_error})\n";
        $m = null;
    }
}
$nombreBd = 'jsonsqldb_f14_' . getmypid();
// En el CI el servidor tiene que estar: si no se puede conectar, es un fallo,
// no algo que se salta sin que nadie lo vea
chk('hay un servidor MySQL con el que probar', function () use ($m) {
    if ($m !== null) { return true; }
    return getenv('JSONSQLDB_TEST_MYSQL_EXIGIR') ? 'no se pudo conectar y JSONSQLDB_TEST_MYSQL_EXIGIR lo exige' : null;
});
chk('se carga en MySQL / MariaDB sin un solo error', function () use ($m, $mysqlSql, $nombreBd) {
    if ($m === null) { return null; }
    $m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
    $m->query("CREATE DATABASE `$nombreBd`");
    $m->select_db($nombreBd);
    if (!$m->multi_query($mysqlSql)) { return 'error: ' . $m->error; }
    $n = 0;
    do {
        $n++;
        if ($r = $m->store_result()) { $r->free(); }
        if ($m->errno) { return "error en la sentencia $n: " . $m->error; }
    } while ($m->more_results() && $m->next_result());
    return $m->errno === 0 ?: 'error: ' . $m->error;
});
chk('deja exactamente los mismos datos, tabla por tabla', function () use ($m, $original) {
    if ($m === null) { return null; }
    foreach ($original as $t => $filas) {
        $r = $m->query("SELECT * FROM `$t`");
        if ($r === false) { return "no está la tabla $t: " . $m->error; }
        if (normal($r->fetch_all(MYSQLI_ASSOC)) !== normal($filas)) { return "la tabla $t no tiene los mismos datos"; }
    }
    return true;
});
chk('con sus claves foráneas, que se aplican, y sus índices', function () use ($m, $nombreBd) {
    if ($m === null) { return null; }
    $fk = (int)$m->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '$nombreBd'")->fetch_row()[0];
    if ($fk !== 4) { return "hay $fk claves foráneas de 4"; }
    $idx = (int)$m->query("SELECT COUNT(DISTINCT TABLE_NAME, INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$nombreBd' AND INDEX_NAME IN ('ix_fecha', 'ix_nombre_saldo')")->fetch_row()[0];
    if ($idx !== 3) { return "hay $idx índices de 3"; }
    $m->query('INSERT INTO pedidos VALUES (999, 12345, 1, NULL)');
    if ($m->errno === 0) { return 'aceptó un pedido de un cliente que no existe'; }
    $m->query('DELETE FROM clientes WHERE id = 1');
    $quedan = (int)$m->query('SELECT COUNT(*) FROM pedidos WHERE cid = 1')->fetch_row()[0];
    return $quedan === 0 ?: 'el ON DELETE CASCADE no borró los pedidos';
});
chk('la clave única de texto sigue distinguiendo mayúsculas, como en jsonSQLDB', function () use ($m) {
    if ($m === null) { return null; }
    $m->query("INSERT INTO clientes (email, nombre) VALUES ('C2@E.ES', 'mayúsculas')");
    return $m->errno === 0 ?: 'rechazó C2@E.ES como repetido de c2@e.es: ' . $m->error;
});
chk('el autoincremento sigue después del último', function () use ($m) {
    if ($m === null) { return null; }
    $m->query("INSERT INTO clientes (email, nombre) VALUES ('nuevo@e.es', 'nuevo')");
    return (int)$m->insert_id === 43 ?: 'el nuevo id es ' . $m->insert_id;
});
if ($m !== null) {
    $m->query("DROP DATABASE `$nombreBd`");
}

// ---------------------------------------------------------------------
echo "\n== Volcado para PostgreSQL ==\n";
$pgSql = Exportar::volcado('v', $tablas, $triggers, $vistas, $indices, 'postgresql');
$pgConexion = (string)getenv('JSONSQLDB_TEST_POSTGRESQL');
$pg = null;
if ($pgConexion !== '' && function_exists('pg_connect')) {
    [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
    $pg = @pg_connect("host=$host user=$usuario password=$clave dbname=postgres");
    if ($pg === false) { echo "  (no se puede conectar a PostgreSQL en $host)\n"; $pg = null; }
}
chk('hay un servidor PostgreSQL con el que probar', function () use ($pg) {
    if ($pg !== null) { return true; }
    return getenv('JSONSQLDB_TEST_POSTGRESQL_EXIGIR') ? 'no se pudo conectar y JSONSQLDB_TEST_POSTGRESQL_EXIGIR lo exige' : null;
});
$pgBd = 'jsonsqldb_f14_' . getmypid();
$pgDestino = null;
chk('se carga en PostgreSQL sin un solo error y deja los mismos datos', function () use ($pg, $pgSql, $pgBd, $pgConexion, $original, &$pgDestino) {
    if ($pg === null) { return null; }
    pg_query($pg, "CREATE DATABASE \"$pgBd\"");
    [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
    $pgDestino = pg_connect("host=$host user=$usuario password=$clave dbname=$pgBd");
    if (@pg_query($pgDestino, $pgSql) === false) { return 'error: ' . pg_last_error($pgDestino); }
    foreach ($original as $t => $filas) {
        $r = pg_query($pgDestino, "SELECT * FROM \"$t\"");
        $leidas = [];
        while (($f = pg_fetch_assoc($r)) !== false) { $leidas[] = $f; }
        if (normal($leidas) !== normal($filas)) { return "la tabla $t no tiene los mismos datos"; }
    }
    return true;
});
chk('con sus claves foráneas, que se aplican, sus índices, y el autoincremento detrás del último', function () use (&$pgDestino) {
    if ($pgDestino === null) { return null; }
    $fk = (int)pg_fetch_row(pg_query($pgDestino, "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_type = 'FOREIGN KEY'"))[0];
    if ($fk !== 4) { return "hay $fk claves foráneas de 4"; }
    $idx = (int)pg_fetch_row(pg_query($pgDestino, "SELECT COUNT(*) FROM pg_indexes WHERE indexname IN ('pedidos_ix_fecha', 'clientes_ix_fecha', 'ix_nombre_saldo')"))[0];
    if ($idx !== 3) { return "hay $idx índices de 3"; }
    if (@pg_query($pgDestino, 'INSERT INTO pedidos VALUES (999, 12345, 1, NULL)') !== false) { return 'aceptó un pedido de un cliente que no existe'; }
    $r = pg_query($pgDestino, "INSERT INTO clientes (email, nombre) VALUES ('C2@E.ES', 'mayúsculas') RETURNING id");
    if ($r === false) { return 'rechazó C2@E.ES como repetido de c2@e.es'; }
    $id = (int)pg_fetch_row($r)[0];
    return $id === 42 ?: "el nuevo id es $id, no 42";
});
if ($pgDestino !== null) {
    pg_close($pgDestino);
    pg_query($pg, "DROP DATABASE IF EXISTS \"$pgBd\"");
}

// ---------------------------------------------------------------------
echo "\n== Volcado para SQL Server ==\n";
$ssSql = Exportar::volcado('v', $tablas, $triggers, $vistas, $indices, 'sqlserver');
chk('lleva el dialecto de SQL Server: corchetes, N\'…\', IDENTITY_INSERT, transacción', fn() =>
    str_contains($ssSql, 'CREATE TABLE [clientes]') && str_contains($ssSql, "N'") && str_contains($ssSql, 'SET IDENTITY_INSERT [clientes] ON;')
    && str_contains($ssSql, 'IDENTITY(1,1)') && str_contains($ssSql, 'COMMIT TRANSACTION;') && !str_contains($ssSql, 'AUTOINCREMENT')
    && str_contains($ssSql, 'WHERE [email] IS NOT NULL') ?: 'no es el volcado para SQL Server');

// ---------------------------------------------------------------------
echo "\n== Importar volcados de SQLite, MySQL, PostgreSQL y SQL Server ==\n";
$volcados = __DIR__ . '/volcados';
/** Importa un volcado en una base nueva y lo compara con los datos de la base de origen. */
$importar = static function (string $fichero, string $base, array $esperado, string $dialecto, string $fk) {
    Database::crear($base, JSONSQLDB_DATA_PATH);
    $resumen = Importar::sql($fichero, $base);
    if (!str_contains($resumen, "volcado de $dialecto")) { return "no lo reconoció como $dialecto: $resumen"; }
    foreach ($esperado as $t => $filas) {
        if (normal(Api::sql($base, "SELECT * FROM \"$t\"")) !== normal($filas)) { return "la tabla $t no tiene los mismos datos"; }
    }
    $claves = array_column(Api::sql($base, "SHOW KEYS FROM \"$fk\""), 'tipo');
    return in_array('FOREIGN', $claves, true) ?: "la clave foránea de $fk no llegó";
};
chk('un volcado de sqlite3 .dump llega con los mismos datos y sus claves', function () use ($importar, $volcados) {
    // Con BEGIN TRANSACTION delante, char(10) para los saltos de línea, una
    // clave foránea hacia una tabla que se crea después, COLLATE, CHECK, un
    // índice único y uno parcial, WITHOUT ROWID, una vista y un trigger
    $esperado = json_decode((string)file_get_contents("$volcados/sqlite.esperado.json"), true);
    return $importar("$volcados/sqlite.sql", 'imp_sqlite', $esperado, 'SQLite', 'pedidos');
});
chk('un volcado de mariadb-dump llega con los mismos datos y sus claves', function () use ($importar, $volcados) {
    // Con comillas invertidas, escapes de barra invertida, INT(11) UNSIGNED,
    // ENUM, CURRENT_TIMESTAMP, ON UPDATE, CHECK, KEY con prefijo, una clave
    // foránea hacia una tabla que va detrás por orden alfabético, LOCK TABLES
    // y los /*! … *\/ de mysqldump
    $esperado = json_decode((string)file_get_contents("$volcados/mysql.esperado.json"), true);
    $r = $importar("$volcados/mysql.sql", 'imp_mysql', $esperado, 'MySQL / MariaDB', 'a_pedidos');
    if ($r !== true) { return $r; }
    $idx = array_column(Api::sql('imp_mysql', 'SHOW INDEXES'), 'indice');
    return in_array('ix_nombre', $idx, true) ?: 'el KEY de dentro del CREATE TABLE no llegó como índice';
});
chk('un volcado de pg_dump llega con los mismos datos, su clave primaria y su autoincremento', function () use ($importar, $volcados) {
    // Con COPY … FROM stdin, la clave primaria y el autoincremento declarados
    // después de los datos, booleanos t/f, bytea, fechas con zona, listas,
    // ::tipo, una función con $$…$$ y punto y coma dentro, COMMENT ON y \\restrict
    $esperado = json_decode((string)file_get_contents("$volcados/postgresql.esperado.json"), true);
    $r = $importar("$volcados/postgresql.sql", 'imp_pg', $esperado, 'PostgreSQL', 'pedidos');
    if ($r !== true) { return $r; }
    $id = array_values(array_filter(Api::sql('imp_pg', 'SHOW SCHEMA clientes'), static fn($c) => $c['columna'] === 'id'))[0];
    return (int)$id['pk'] === 1 && (int)$id['auto'] === 1 ?: 'clientes.id no es clave primaria con autoincremento';
});
chk('un script de «Generar scripts» de SQL Server llega con los mismos datos y sus valores por defecto', function () use ($importar, $volcados) {
    $esperado = json_decode((string)file_get_contents("$volcados/sqlserver.esperado.json"), true);
    $r = $importar("$volcados/sqlserver.sql", 'imp_ss', $esperado, 'SQL Server', 'Pedidos');
    if ($r !== true) { return $r; }
    $def = [];
    foreach (Api::sql('imp_ss', 'SHOW SCHEMA Clientes') as $c) { $def[$c['columna']] = [$c['defecto'], (int)$c['auto']]; }
    return $def['Saldo'][0] == 0 && $def['Activo'][0] == 1 && $def['Id'][1] === 1 ?: 'faltan los valores por defecto o el IDENTITY: ' . json_encode($def);
});
chk('el script de SQL Server guardado en UTF-16 («Unicode», lo que propone Management Studio) o con marca de UTF-8 llega igual', function () use ($importar, $volcados) {
    $esperado = json_decode((string)file_get_contents("$volcados/sqlserver.esperado.json"), true);
    $texto = (string)file_get_contents("$volcados/sqlserver.sql");
    // Con trozos de 1 MB por en medio: se repite para que la conversión cruce bordes
    $relleno = str_repeat("-- " . str_repeat('relleno ñ 😀 ', 40) . "\n", 2500);
    // UTF-16 sin mbstring, que la prueba no la exige: cada punto de código a mano
    $utf16 = static function (string $utf8, bool $le): string {
        $out = '';
        foreach (preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            $b  = array_values(unpack('C*', $c));
            $cp = count($b) === 1 ? $b[0]
                : (count($b) === 2 ? (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F)
                : (count($b) === 3 ? (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F)
                : (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F)));
            $u  = $cp > 0xFFFF ? [0xD800 + (($cp - 0x10000) >> 10), 0xDC00 + (($cp - 0x10000) & 0x3FF)] : [$cp];
            foreach ($u as $w) { $out .= pack($le ? 'v' : 'n', $w); }
        }
        return $out;
    };
    foreach (['utf16le' => "\xFF\xFE" . $utf16($texto . $relleno, true),
              'utf16be' => "\xFE\xFF" . $utf16($texto, false),
              'bom8'    => "\xEF\xBB\xBF" . $texto] as $nombre => $bytes) {
        $f = JSONSQLDB_DATA_PATH . "/ss_$nombre.sql";
        file_put_contents($f, $bytes);
        $r = $importar($f, "imp_ss_$nombre", $esperado, 'SQL Server', 'Pedidos');
        @unlink($f);
        if ($r !== true) { return "$nombre: $r"; }
    }
    return true;
});
chk('lo que se exporta para PostgreSQL y para SQL Server se vuelve a importar igual', function () use ($tablas, $triggers, $vistas, $indices, $original) {
    foreach (['postgresql' => 'PostgreSQL', 'sqlserver' => 'SQL Server'] as $d => $nombre) {
        $f = JSONSQLDB_DATA_PATH . "/vuelta.$d.sql";
        file_put_contents($f, Exportar::volcado('v', $tablas, $triggers, $vistas, $indices, $d));
        Database::crear("vuelta_$d", JSONSQLDB_DATA_PATH);
        $r = Importar::sql($f, "vuelta_$d");
        if (!str_contains($r, "volcado de $nombre")) { return "$d: $r"; }
        foreach ($original as $t => $filas) {
            if (normal(Api::sql("vuelta_$d", "SELECT * FROM \"$t\"")) !== normal($filas)) { return "$d: la tabla $t no volvió igual"; }
        }
    }
    return true;
});
chk('Northwind, el script de ejemplo de Microsoft, llega entero', function () {
    // Se descarga de github.com/microsoft/sql-server-samples (licencia MIT)
    // solo si JSONSQLDB_TEST_NORTHWIND lo pide: 1 MB, con IF EXISTS, SET
    // DATEFORMAT mdy, una tabla con espacio en el nombre e imágenes en binario
    if (!getenv('JSONSQLDB_TEST_NORTHWIND')) { return null; }
    $texto = @file_get_contents('https://raw.githubusercontent.com/microsoft/sql-server-samples/master/samples/databases/northwind-pubs/instnwnd.sql');
    if ($texto === false || strlen($texto) < 500000) { return 'no se pudo descargar'; }
    $f = JSONSQLDB_DATA_PATH . '/instnwnd.sql';
    file_put_contents($f, $texto);
    Database::crear('northwind', JSONSQLDB_DATA_PATH);
    Importar::sql($f, 'northwind');
    $n = static fn(string $t): int => (int)Api::sql('northwind', "SELECT COUNT(*) AS n FROM $t")[0]['n'];
    $cuentas = [$n('Customers'), $n('Orders'), $n('Order_Details'), $n('Products'), $n('Employees')];
    if ($cuentas !== [91, 830, 2155, 77, 9]) { return 'filas: ' . json_encode($cuentas); }
    $e = Api::sql('northwind', 'SELECT BirthDate FROM Employees WHERE EmployeeID = 1')[0]['BirthDate'];
    return $e === '1948-12-08' ?: "la fecha mdy salió $e";
});
chk('una base creada en PostgreSQL, volcada con su pg_dump, llega igual', function () use ($pg, $pgConexion, $volcados, $importar) {
    if ($pg === null) { return null; }
    $pgdump = trim((string)shell_exec('command -v pg_dump 2>/dev/null'));
    if ($pgdump === '') { return getenv('JSONSQLDB_TEST_POSTGRESQL_EXIGIR') ? 'no hay pg_dump' : null; }
    [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
    $bd = 'jsonsqldb_f14_origen_' . getmypid();
    pg_query($pg, "CREATE DATABASE \"$bd\"");
    $o = pg_connect("host=$host user=$usuario password=$clave dbname=$bd");
    if (@pg_query($o, (string)file_get_contents("$volcados/fuente_postgresql.sql")) === false) { return 'no se pudo crear la base de origen: ' . pg_last_error($o); }
    $esperado = [];
    foreach (['clientes', 'pedidos'] as $t) {
        $filas = pg_fetch_all(pg_query($o, "SELECT * FROM $t")) ?: [];
        foreach ($filas as &$f) {
            foreach (['activo', 'pagado'] as $b) { if (array_key_exists($b, $f) && $f[$b] !== null) { $f[$b] = $f[$b] === 't' ? 1 : 0; } }
            if (isset($f['foto'])) { $bin = (string)hex2bin(substr($f['foto'], 2)); $f['foto'] = mb_check_encoding($bin, 'UTF-8') ? $bin : $f['foto']; }
            if (isset($f['zona'])) { $f['zona'] = preg_replace('/[+-]\d{2}(:?\d{2})?$/', '', $f['zona']); }
        }
        unset($f);
        $esperado[$t] = $filas;
    }
    pg_close($o);
    $fichero = JSONSQLDB_DATA_PATH . '/origen.pg.sql';
    exec('PGPASSWORD=' . escapeshellarg($clave) . ' ' . escapeshellarg($pgdump) . ' -h ' . escapeshellarg($host) . ' -U '
        . escapeshellarg($usuario) . ' --no-owner ' . escapeshellarg($bd) . ' > ' . escapeshellarg($fichero) . ' 2>/dev/null', $salida, $estado);
    pg_query($pg, "DROP DATABASE IF EXISTS \"$bd\"");
    if ($estado !== 0) { return "pg_dump terminó con $estado"; }
    return $importar($fichero, 'imp_pg_vivo', $esperado, 'PostgreSQL', 'pedidos');
});
chk('el resumen dice lo que no ha llegado igual; CHECK y ENUM ya llegan, y se cumplen (2.9)', function () use ($volcados) {
    Database::crear('imp_avisos', JSONSQLDB_DATA_PATH);
    $r = Importar::sql("$volcados/mysql.sql", 'imp_avisos');
    $sql = (new Database('imp_avisos', JSONSQLDB_DATA_PATH))->consultar('SHOW CREATE TABLE z_clientes')[0]['sql'];
    // PostgreSQL: CHECK ((saldo > ('-100000'::integer)::numeric)), sin las conversiones
    Database::crear('imp_avisos_pg', JSONSQLDB_DATA_PATH);
    Importar::sql("$volcados/postgresql.sql", 'imp_avisos_pg');
    $pg  = new Database('imp_avisos_pg', JSONSQLDB_DATA_PATH);
    $sqlPg = $pg->consultar('SHOW CREATE TABLE clientes')[0]['sql'];
    $rechaza = false;
    try { $pg->consultar("UPDATE clientes SET saldo = -200000 WHERE id = 1"); } catch (\JsonSQLDB\JsonSqlDbError $e) { $rechaza = str_contains($e->getMessage(), 'CHECK'); }
    $pg->consultar('UPDATE clientes SET saldo = 5 WHERE id = 1');
    $bien = $pg->consultar('SELECT saldo FROM clientes WHERE id = 1') === [['saldo' => 5.0]] || $pg->consultar('SELECT saldo FROM clientes WHERE id = 1') === [['saldo' => 5]];
    return str_contains($r, 'CURRENT_TIMESTAMP') && !str_contains($r, 'ENUM') && !str_contains($r, 'CHECK')
        && str_contains($sql, "CONSTRAINT ck_saldo CHECK (saldo > -100000)") && str_contains($sql, "ENUM('particular', 'empresa')")
        && str_contains($sqlPg, 'CHECK (') && !str_contains($sqlPg, '::') && $rechaza && $bien
        ?: json_encode([$r, $sql, $sqlPg, $rechaza, $bien], JSON_UNESCAPED_UNICODE);
});
chk('una base creada en MySQL, volcada con su mysqldump, llega igual', function () use ($m, $volcados, $importar, $conexion) {
    if ($m === null) { return null; }
    $mysqldump = trim((string)shell_exec('command -v mysqldump 2>/dev/null'));
    if ($mysqldump === '') { return getenv('JSONSQLDB_TEST_MYSQL_EXIGIR') ? 'no hay mysqldump' : null; }
    $bd = 'jsonsqldb_f14_origen_' . getmypid();
    $m->query("CREATE DATABASE `$bd` CHARACTER SET utf8mb4");
    $m->select_db($bd);
    $m->set_charset('utf8mb4');
    $m->multi_query((string)file_get_contents("$volcados/fuente_mysql.sql"));
    do { if ($r = $m->store_result()) { $r->free(); } } while ($m->more_results() && $m->next_result());
    if ($m->errno) { return 'no se pudo crear la base de origen: ' . $m->error; }
    $esperado = [];
    foreach (['z_clientes', 'a_pedidos'] as $t) {
        $esperado[$t] = $m->query("SELECT * FROM `$t`")->fetch_all(MYSQLI_ASSOC);
    }
    [$host, $usuario, $clave] = array_pad(explode(':', $conexion, 3), 3, '');
    $fichero = JSONSQLDB_DATA_PATH . '/origen.sql';
    exec(escapeshellarg($mysqldump) . ' -h ' . escapeshellarg($host) . ' -u ' . escapeshellarg($usuario)
        . ' ' . escapeshellarg('-p' . $clave) . ' --default-character-set=utf8mb4 --skip-dump-date ' . escapeshellarg($bd)
        . ' > ' . escapeshellarg($fichero) . ' 2>/dev/null', $salida, $estado);
    $m->query("DROP DATABASE `$bd`");
    if ($estado !== 0) { return "mysqldump terminó con $estado"; }
    return $importar($fichero, 'imp_vivo', $esperado, 'MySQL / MariaDB', 'a_pedidos');
});

echo "\n== COLLATE de las columnas al exportar e importar (2.9) ==\n";
/** Lo que el panel reúne para exportar una base */
function paraExportar(Database $b): array {
    $tablas = [];
    foreach ($b->consultar('SHOW TABLES') as $t) {
        $n = (string)$t['tabla'];
        $tablas[] = ['tabla' => $n, 'columnas' => $b->consultar("SHOW SCHEMA $n"), 'claves' => $b->consultar("SHOW KEYS FROM $n"),
                     'filas' => $b->consultar("SELECT * FROM $n")];
    }
    return [$tablas, $b->consultar('SHOW TRIGGERS'), $b->consultar('SHOW VIEWS'), $b->consultar('SHOW INDEXES')];
}
Database::crear('col', $raiz);
$bc = new Database('col', $raiz);
foreach (["CREATE TABLE u (id INTEGER PRIMARY KEY, email VARCHAR(80) COLLATE NOCASE UNIQUE, cod TEXT COLLATE RTRIM, n TEXT)",
          "INSERT INTO u VALUES (1, 'Ana@E.es', 'x  ', 'Ana'), (2, 'bob@e.es', 'y', 'bob')",
          "CREATE INDEX ix_cod ON u (cod)",
          "CREATE VIEW v_u AS SELECT id FROM u WHERE n = 'ANA' COLLATE NOCASE ORDER BY n COLLATE NOCASE"] as $q) {
    $bc->consultar($q);
}
[$cTablas, $cTriggers, $cVistas, $cIndices] = paraExportar($bc);
chk('el volcado para SQLite lleva COLLATE NOCASE y RTRIM, SQLite compara igual y vuelve a importarse con ellas', function () use ($cTablas, $cTriggers, $cVistas, $cIndices, $bc) {
    $sql = Exportar::volcado('col', $cTablas, $cTriggers, $cVistas, $cIndices, 'sqlite');
    if (!str_contains($sql, 'COLLATE NOCASE') || !str_contains($sql, 'COLLATE RTRIM')) { return 'sin COLLATE'; }
    $f = JSONSQLDB_DATA_PATH . '/col.sql';
    file_put_contents($f, $sql);
    if (class_exists('SQLite3')) {
        $l = new SQLite3(':memory:');
        $l->exec($sql);
        foreach (["SELECT id FROM u WHERE email = 'ANA@e.ES'", "SELECT id FROM u WHERE cod = 'x'", 'SELECT id FROM v_u'] as $q) {
            $r = $l->query($q)->fetchArray(SQLITE3_NUM)[0] ?? null;
            if ($r !== $bc->consultar($q)[0]['id'] ?? null) { return "$q: SQLite da " . var_export($r, true); }
        }
    }
    Database::crear('col_vuelta', JSONSQLDB_DATA_PATH);
    Importar::sql($f, 'col_vuelta');
    @unlink($f);
    $c = Api::sql('col_vuelta', 'SHOW CREATE TABLE u')[0]['sql'];
    return str_contains($c, 'email VARCHAR(80) COLLATE NOCASE UNIQUE') && str_contains($c, 'cod TEXT COLLATE RTRIM')
        && Api::sql('col_vuelta', "SELECT id FROM u WHERE email = 'BOB@E.ES'") === [['id' => 2]] ?: $c;
});
chk('MySQL, PostgreSQL y SQL Server llevan la colación más parecida, y al importarlos vuelve NOCASE', function () use ($cTablas, $cTriggers, $cVistas, $cIndices) {
    $mal = [];
    $esperado = ['mysql' => ['`email` VARCHAR(80) COLLATE utf8mb4_general_ci', "WHERE (`n` = 'ANA' COLLATE utf8mb4_general_ci)"],
                 'postgresql' => ['CREATE EXTENSION IF NOT EXISTS citext;', '"email" CITEXT', '-- "u"."cod": COLLATE RTRIM', "LOWER(\"n\") = LOWER('ANA')"],
                 'sqlserver' => ['[email] NVARCHAR(80) COLLATE Latin1_General_100_CI_AS', "[n] = N'ANA' COLLATE Latin1_General_100_CI_AS"]];
    foreach ($esperado as $d => $trozos) {
        $sql = Exportar::volcado('col', $cTablas, $cTriggers, $cVistas, $cIndices, $d);
        foreach ($trozos as $t) {
            if (!str_contains($sql, $t)) { $mal[] = "$d sin «{$t}»"; }
        }
        $f = JSONSQLDB_DATA_PATH . "/col.$d.sql";
        file_put_contents($f, $sql);
        Database::crear("col_$d", JSONSQLDB_DATA_PATH);
        Importar::sql($f, "col_$d");
        @unlink($f);
        if (!str_contains(Api::sql("col_$d", 'SHOW CREATE TABLE u')[0]['sql'], 'COLLATE NOCASE')) { $mal[] = "$d: no volvió NOCASE"; }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('lo que va comentado en un volcado (un CHECK o una vista que el otro motor no admite, y el motivo) lleva «-- » en cada línea, también tras un \r suelto: nada de ello se ejecuta al cargarlo (2.9)', function () use ($raiz) {
    Database::crear('cmt', $raiz);
    $b = new Database('cmt', $raiz);
    $b->consultar("CREATE TABLE c1 (id INTEGER PRIMARY KEY, s TEXT, CHECK (typeof(s) = 'text' OR s <> 'q\nCREATE TABLE colado1 (x INT);\n-- '))");
    $b->consultar("CREATE VIEW v1 AS SELECT date('now', 'x\rCREATE TABLE colado2 (x INT);') AS d");
    $b->consultar("CREATE VIEW v2 AS SELECT date('now', 'x\nCREATE TABLE colado3 (x INT);') AS d");
    [$t, $tr, $v, $i] = paraExportar($b);
    $mal = [];
    foreach (['mysql', 'postgresql', 'sqlserver', 'access'] as $d) {
        foreach (preg_split('/\r\n|\r|\n/', Exportar::volcado('cmt', $t, $tr, $v, $i, $d)) as $linea) {
            if (str_contains($linea, 'colado') && !str_starts_with(ltrim($linea), '--')) { $mal[] = "$d: $linea"; }
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('al importar, …_ci, …_CI_AS y citext pasan a NOCASE con un aviso, también la colación de la tabla de MySQL o la de su CHARSET; …_bin, "C" y "default", sin nada; otra, quitada con un aviso', function () {
    $f = JSONSQLDB_DATA_PATH . '/colaciones.sql';
    $mal = [];
    $casos = [
        ["CREATE TABLE `a` (`id` int NOT NULL, `e` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL, `b` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin, `n` int, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
         'mysql', ['e VARCHAR(50) COLLATE NOCASE', 'b VARCHAR(5)'], ['b VARCHAR(5) COLLATE'], ['NOCASE', 'CHARACTER SET']],
        // La de la tabla, o la de su CHARSET, en las columnas de texto que no dicen otra; JSON y binary, binarias
        ["CREATE TABLE `a` (`id` int, `t` text, `j` json, `en` enum('x','y'), `cs` varchar(5) CHARACTER SET latin1, `cb` varbinary(5), `cc` varchar(5) CHARACTER SET binary) ENGINE=InnoDB DEFAULT CHARSET=latin1;",
         'mysql', ['t TEXT COLLATE NOCASE', "en ENUM('x', 'y') COLLATE NOCASE", 'cs VARCHAR(5) COLLATE NOCASE', 'j TEXT', 'cc VARCHAR(5)'], ['j TEXT COLLATE', 'cb TEXT COLLATE', 'cc VARCHAR(5) COLLATE'], ['NOCASE']],
        ["CREATE TABLE `a` (`id` int, `t` text) ENGINE=InnoDB DEFAULT COLLATE utf8mb4_bin;", 'mysql', ['t TEXT'], ['COLLATE'], []],
        ["CREATE TABLE `a` (`id` int, `t` text) ENGINE=InnoDB;", 'mysql', ['t TEXT'], ['COLLATE'], []],
        ["CREATE TABLE public.a (\n    id integer NOT NULL,\n    e public.citext,\n    c text COLLATE pg_catalog.\"default\",\n    d text COLLATE \"C\",\n    g text COLLATE \"de_DE\"\n);",
         'postgresql', ['e TEXT COLLATE NOCASE', 'c TEXT', 'd TEXT', 'g TEXT'], ['c TEXT COLLATE', 'd TEXT COLLATE', 'g TEXT COLLATE'], ['NOCASE', 'COLLATE de_DE']],
        ["CREATE TABLE [dbo].[a]([id] [int] NOT NULL, [e] [nvarchar](50) COLLATE SQL_Latin1_General_CP1_CI_AS NULL, [b] [nvarchar](5) COLLATE Latin1_General_100_BIN2 NULL)\nGO",
         'sqlserver', ['e VARCHAR(50) COLLATE NOCASE', 'b VARCHAR(5)'], ['b VARCHAR(5) COLLATE'], ['NOCASE']],
    ];
    foreach ($casos as $i => [$ddl, $d, $si, $no, $avisos]) {
        file_put_contents($f, "-- jsonsqldb-dialecto: $d\n$ddl\n");
        Database::crear("colimp$i", JSONSQLDB_DATA_PATH);
        $r = Importar::sql($f, "colimp$i");
        $c = Api::sql("colimp$i", 'SHOW CREATE TABLE a')[0]['sql'];
        foreach ($si as $t) { if (!str_contains($c, $t)) { $mal[] = "$d: sin «{$t}» en $c"; } }
        foreach ($no as $t) { if (str_contains($c, $t)) { $mal[] = "$d: «{$t}» de más en $c"; } }
        foreach ($avisos as $t) { if (!str_contains($r, $t)) { $mal[] = "$d: el resumen no dice «{$t}»: $r"; } }
        foreach (['COLLATE utf8mb4_bin', 'COLLATE Latin1_General_100_BIN2', 'COLLATE C ', 'COLLATE default', 'pg_catalog'] as $t) {
            if (str_contains($r, $t)) { $mal[] = "$d: avisa de una binaria: $r"; }
        }
    }
    @unlink($f);
    return $mal === [] ?: implode(' | ', $mal);
});
unset($bc);

echo "\n== Volcados difíciles (de las auditorías externas de la 2.7.3) ==\n";
chk('el volcado por trozos (una tabla en memoria a la vez) es el mismo que el entero, en los cinco dialectos', function () {
    // Antes se juntaban las filas de todas las tablas antes de escribir nada
    Database::crear('trozos');
    $bd = new Database('trozos');
    $bd->consultar('CREATE TABLE padre (id INTEGER PRIMARY KEY, nombre VARCHAR(20))');
    $bd->consultar('CREATE TABLE hijo (id INTEGER PRIMARY KEY, padre_id INTEGER, FOREIGN KEY (padre_id) REFERENCES padre (id))');
    $bd->consultar("INSERT INTO padre VALUES (1, 'a'), (2, 'b')");
    $bd->consultar('INSERT INTO hijo VALUES (1, 1), (2, 2), (3, 1)');
    $bd->consultar('CREATE VIEW v AS SELECT p.nombre, COUNT(*) AS n FROM padre p JOIN hijo h ON h.padre_id = p.id GROUP BY p.nombre');
    $meta = [];
    foreach (['hijo', 'padre'] as $t) {
        $meta[] = ['tabla' => $t, 'columnas' => $bd->consultar("SHOW SCHEMA $t"), 'claves' => $bd->consultar("SHOW KEYS FROM $t"), 'n' => 0];
    }
    $cargar = static fn(string $t) => $bd->consultar("SELECT * FROM $t");
    $completas = array_map(static fn($m) => $m + ['filas' => $cargar($m['tabla'])], $meta);
    $sinFecha = static fn(string $s) => (string)preg_replace('/^-- jsonSQLDB · .*\n/', '', $s);
    foreach (['sqlite', 'mysql', 'postgresql', 'sqlserver', 'access'] as $d) {
        $trozos = [];
        Exportar::volcado('trozos', $meta, [], $bd->consultar('SHOW VIEWS'), [], $d, $cargar, static function ($x) use (&$trozos) { $trozos[] = $x; });
        $entero = Exportar::volcado('trozos', $completas, [], $bd->consultar('SHOW VIEWS'), [], $d);
        // n solo es el recuento de la cabecera: con las filas, cuenta las filas
        $entero = str_replace('5 fila(s)', '0 fila(s)', $entero);
        if ($sinFecha(implode('', $trozos)) !== $sinFecha($entero) || count($trozos) < 3) {
            return "$d: distinto o de un solo trozo";
        }
    }
    return true;
});
chk('una columna con una coma en el nombre sale bien en sus claves e índices: SHOW KEYS la pone entre comillas, y el volcado para SQLite las conserva (2.9: se partía en dos)', function () {
    if (!class_exists('SQLite3')) { return null; }
    Database::crear('comas');
    $bd = new Database('comas');
    foreach (['CREATE TABLE p (id INTEGER PRIMARY KEY, "a,b" TEXT UNIQUE, c TEXT, UNIQUE (c, "a,b"))',
              'CREATE TABLE h (id INTEGER PRIMARY KEY, "r,s" TEXT, FOREIGN KEY ("r,s") REFERENCES p ("a,b"))',
              'CREATE INDEX ix_rs ON h ("r,s")', "INSERT INTO p VALUES (1, 'x', 'y')", "INSERT INTO h VALUES (1, 'x')"] as $q) {
        $bd->consultar($q);
    }
    $claves = array_column($bd->consultar('SHOW KEYS FROM h'), 'columnas_destino', 'tipo');
    [$tablas, $triggers, $vistas, $indices] = paraExportar($bd);
    $lite = new SQLite3(':memory:');
    $lite->enableExceptions(true);
    $lite->exec(Exportar::volcado('comas', $tablas, $triggers, $vistas, $indices, 'sqlite'));
    $fk = $lite->query('PRAGMA foreign_key_list(h)')->fetchArray(SQLITE3_ASSOC);
    $ix = $lite->query("PRAGMA index_info('ix_rs')")->fetchArray(SQLITE3_ASSOC);
    $uq = [];
    $r = $lite->query('PRAGMA index_list(p)');
    while ($f = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols = [];
        $i = $lite->query('PRAGMA index_info(' . SQLite3::escapeString((string)$f['name']) . ')');
        while ($c = $i->fetchArray(SQLITE3_ASSOC)) { $cols[] = $c['name']; }
        $uq[] = implode('|', $cols);
    }
    sort($uq);
    Database::borrar('comas');
    return ($claves['FOREIGN'] ?? '') === '"a,b"' && ($fk['from'] ?? '') === 'r,s' && ($fk['to'] ?? '') === 'a,b' && ($ix['name'] ?? '') === 'r,s'
        && $uq === ['a,b', 'c|a,b'] ?: json_encode([$claves, $fk, $ix, $uq]);
});
chk('el volcado para SQLite se carga en SQLite aunque haya un autonumérico que no es la clave primaria', function () {
    // SQLite solo admite AUTOINCREMENT en INTEGER PRIMARY KEY
    if (!class_exists('SQLite3')) { return null; }
    Database::crear('autos');
    $bd = new Database('autos');
    $bd->consultar('CREATE TABLE t (id INTEGER AUTOINCREMENT, x TEXT)');
    $bd->consultar("INSERT INTO t (x) VALUES ('a'), ('b')");
    $tablas = [['tabla' => 't', 'columnas' => $bd->consultar('SHOW SCHEMA t'), 'claves' => $bd->consultar('SHOW KEYS FROM t'),
                'filas' => $bd->consultar('SELECT * FROM t')]];
    $sql = Exportar::volcado('autos', $tablas, [], [], [], 'sqlite');
    $lite = new SQLite3(':memory:');
    $ok = @$lite->exec($sql);
    $n = $ok ? $lite->querySingle('SELECT COUNT(*) FROM t') : null;
    return $ok && $n === 2 ?: 'SQLite: ' . $lite->lastErrorMsg();
});
chk('ENUM y CHECK salen en cada dialecto: SQLite los cumple; MySQL con ENUM y CHAR_LENGTH; SQL Server con LEN; lo que no se puede, como nota (2.9)', function () {
    Database::crear('exck');
    $bd = new Database('exck');
    $bd->consultar("CREATE TABLE p (id INTEGER PRIMARY KEY, estado ENUM('nuevo', 'pagado') NOT NULL, cod TEXT CHECK (length(cod) = 3),
        a TEXT, b TEXT, CONSTRAINT dist CHECK (cod != 'zzz'), CONSTRAINT junto CHECK (a || b <> 'xy'))");
    $bd->consultar("INSERT INTO p VALUES (1, 'pagado', 'abc', 'q', 'r')");
    $tablas = [['tabla' => 'p', 'columnas' => $bd->consultar('SHOW SCHEMA p'), 'claves' => $bd->consultar('SHOW KEYS FROM p'),
                'filas' => $bd->consultar('SELECT * FROM p')]];
    $sql = [];
    foreach (['sqlite', 'mysql', 'postgresql', 'sqlserver', 'access'] as $d) { $sql[$d] = Exportar::volcado('exck', $tablas, [], [], [], $d); }
    $mal = [];
    if (class_exists('SQLite3')) {
        $lite = new SQLite3(':memory:');
        $lite->enableExceptions(true);
        $lite->exec($sql['sqlite']);
        foreach (["INSERT INTO p VALUES (2, 'otro', 'abc', 'q', 'r')", "INSERT INTO p VALUES (3, 'nuevo', 'ab', 'q', 'r')",
                  "INSERT INTO p VALUES (4, 'nuevo', 'zzz', 'q', 'r')", "INSERT INTO p VALUES (5, 'nuevo', 'abc', 'x', 'y')"] as $q) {
            try { $lite->exec($q); $mal[] = "SQLite aceptó: $q"; } catch (Exception) {}
        }
        $lite->exec("INSERT INTO p VALUES (6, 'nuevo', 'xyz', 'x', 'z')");
        if ((int)$lite->querySingle('SELECT COUNT(*) FROM p') !== 2) { $mal[] = 'SQLite: no están las 2 filas'; }
    }
    $hay = static function (string $d, string $texto) use ($sql, &$mal): void { if (!str_contains($sql[$d], $texto)) { $mal[] = "$d sin: $texto"; } };
    $hay('mysql', "`estado` ENUM('nuevo', 'pagado') NOT NULL");
    $hay('mysql', 'CHECK (CHAR_LENGTH(`cod`) = 3)');
    $hay('mysql', "CONSTRAINT `dist` CHECK (`cod` <> 'zzz')");
    $hay('mysql', "-- CONSTRAINT `junto` CHECK (a || b <> 'xy')");
    $hay('postgresql', "CHECK (\"estado\" IN ('nuevo', 'pagado'))");
    $hay('postgresql', "CONSTRAINT \"junto\" CHECK (\"a\" || \"b\" <> 'xy')");
    $hay('sqlserver', 'CHECK (LEN([cod]) = 3)');
    $hay('sqlserver', "-- CONSTRAINT [junto] CHECK (a || b <> 'xy')");
    $hay('access', "-- CHECK ([estado] IN ('nuevo', 'pagado'))");
    $hay('access', "-- CONSTRAINT [dist] CHECK (cod != 'zzz')");
    Database::borrar('exck');
    return $mal === [] ?: implode(' | ', $mal);
});
chk('un volcado de MySQL en Latin-1 se importa: textos con acentos, BIT en crudo y fecha cero', function () {
    // Antes, un solo byte Latin-1 rompía el análisis («Sentencia no soportada: 'I'»)
    Database::crear('lat1');
    $r = Importar::sql(__DIR__ . '/volcados/mysql_latin1.sql', 'lat1');
    $filas = (new Database('lat1'))->consultar('SELECT * FROM personas ORDER BY id');
    return $filas === [['id' => 1, 'nombre' => 'José', 'b' => 170, 'f' => null], ['id' => 2, 'nombre' => 'Ana', 'b' => 1, 'f' => null]]
        && str_contains($r, 'Latin-1') && str_contains($r, '0000-00-00') ?: json_encode([$filas, $r], JSON_UNESCAPED_UNICODE);
});
chk('B1: un BLOB en crudo en un INSERT de varias filas ya no estropea los acentos de las demás, y cada BIT recupera sus bytes (2.9)', function () {
    // mysqldump junta hasta doscientas filas en un INSERT y escribe los BLOB en
    // crudo: un byte que no es UTF-8 hacía leer como Latin-1 la sentencia
    // entera, y el texto bueno de las otras filas quedaba convertido dos veces
    $f = JSONSQLDB_DATA_PATH . '/b1.sql';
    file_put_contents($f, "-- MySQL dump 10.13\nCREATE TABLE `t` (`id` int NOT NULL, `txt` varchar(100), `b` blob, `f` bit(16), PRIMARY KEY (`id`));\n"
        . "INSERT INTO `t` VALUES (1,'primero con acentos áéíóú y emoji 😀',NULL,'\xC3\xA9'),(2,'segundo','\x80\xFF raw','\x80\x01'),"
        . "(3,'tercero también con ñ y ü',NULL,NULL);\n");
    Database::crear('b1');
    $r = Importar::sql($f, 'b1');
    $filas = (new Database('b1'))->consultar('SELECT id, txt, f FROM t ORDER BY id');
    return $filas === [['id' => 1, 'txt' => 'primero con acentos áéíóú y emoji 😀', 'f' => 0xC3A9], ['id' => 2, 'txt' => 'segundo', 'f' => 0x8001],
                       ['id' => 3, 'txt' => 'tercero también con ñ y ü', 'f' => null]]
        && str_contains($r, 'se han dejado como venían') ?: json_encode([$filas, $r], JSON_UNESCAPED_UNICODE);
});
chk('B2: un volcado de PostgreSQL con un esquema que no es public se importa, con su clave primaria y su identidad (2.9)', function () {
    // Antes solo se quitaban dbo. y public.: COPY ventas.pedidos buscaba la tabla «ventas»
    $f = JSONSQLDB_DATA_PATH . '/b2.sql';
    file_put_contents($f, "-- PostgreSQL database dump\nSET standard_conforming_strings = on;\nCREATE SCHEMA ventas;\n"
        . "CREATE TABLE ventas.pedidos (id integer NOT NULL, total numeric(10,2));\n"
        . "ALTER TABLE ventas.pedidos ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (SEQUENCE NAME ventas.pedidos_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1);\n"
        . "CREATE VIEW ventas.grandes AS\n SELECT pedidos.id, pedidos.total\n   FROM ventas.pedidos\n  WHERE (pedidos.total > (10)::numeric);\n"
        . "COPY ventas.pedidos (id, total) FROM stdin;\n1\t5.50\n2\t20.00\n\\.\n"
        . "ALTER TABLE ONLY ventas.pedidos ADD CONSTRAINT pedidos_pkey PRIMARY KEY (id);\n");
    Database::crear('b2');
    $r = Importar::sql($f, 'b2');
    $bd = new Database('b2');
    $bd->consultar('INSERT INTO pedidos (total) VALUES (30)');
    $filas = $bd->consultar('SELECT id, total FROM pedidos ORDER BY id');
    $vista = $bd->consultar('SELECT id FROM grandes ORDER BY id');
    // Y la misma tabla en dos esquemas se dice, no se mezcla
    $g = JSONSQLDB_DATA_PATH . '/b2doble.sql';
    file_put_contents($g, "-- PostgreSQL database dump\nCREATE SCHEMA a;\nCREATE SCHEMA b;\nCREATE TABLE a.t (id integer);\nCREATE TABLE b.t (id integer);\n");
    Database::crear('b2doble');
    try { Importar::sql($g, 'b2doble'); $doble = 'se importó'; } catch (RuntimeException $e) { $doble = $e->getMessage(); }
    return array_column($filas, 'id') === [1, 2, 3] && array_column($vista, 'id') === [2, 3] && str_contains($doble, 'varios esquemas')
        ?: json_encode([$filas, $vista, $doble, $r], JSON_UNESCAPED_UNICODE);
});
chk('B3: un NUL dentro de un texto: SQLite y jsonSQLDB lo conservan, MySQL lo escribe \0, SQL Server NCHAR(0), y PostgreSQL y Access lo quitan y avisan (2.9)', function () use ($raiz) {
    // Antes iba tal cual dentro de la cadena y el volcado no se podía cargar
    Database::crear('nul', $raiz);
    $bd = new Database('nul', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar('INSERT INTO t VALUES (1, ?), (2, ?)', ["antes\0después", "sin nada"]);
    $tablas = [['tabla' => 't', 'columnas' => $bd->consultar('SHOW SCHEMA t'), 'claves' => [], 'filas' => $bd->consultar('SELECT * FROM t')]];
    $v = [];
    foreach (['sqlite', 'mysql', 'postgresql', 'sqlserver', 'access'] as $d) {
        $v[$d] = Exportar::volcado('nul', $tablas, [], [], [], $d);
    }
    // De vuelta a jsonSQLDB y a SQLite, con el NUL
    $f = JSONSQLDB_DATA_PATH . '/nul.sql';
    file_put_contents($f, $v['sqlite']);
    Database::crear('nul2');
    Importar::sql($f, 'nul2');
    $vuelta = (new Database('nul2'))->consultar('SELECT x FROM t WHERE id = 1')[0]['x'];
    $lite = null;
    if (class_exists('SQLite3')) {
        $db = new SQLite3(':memory:');
        $lite = @$db->exec($v['sqlite']) ? $db->querySingle('SELECT hex(x) FROM t WHERE id = 1') : $db->lastErrorMsg();
    }
    $sinNul = static fn(string $s): bool => !str_contains($s, "\0");
    return $vuelta === "antes\0después" && ($lite === null || $lite === strtoupper(bin2hex("antes\0después")))
        && $sinNul($v['sqlite']) && $sinNul($v['mysql']) && str_contains($v['mysql'], "'antes\\0después'")
        && str_contains($v['sqlserver'], "N'antes' + NCHAR(0) + N'después'")
        && $sinNul($v['postgresql']) && str_contains($v['postgresql'], "'antesdespués'") && str_contains($v['postgresql'], 'NUL')
        && $sinNul($v['access']) && str_contains($v['access'], 'NUL')
        ?: json_encode([$vuelta, $lite, substr($v['mysql'], -300)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});
chk('B4, B5 y B6: para MySQL, el texto según sus bytes (TEXT, MEDIUMTEXT), la fila de menos de 65.535 bytes y SET DEFAULT como NO ACTION con su nota; en Access, la nota (2.9)', function () use ($raiz, $m) {
    Database::crear('mysqltam', $raiz);
    $bd = new Database('mysqltam', $raiz);
    $bd->consultar('CREATE TABLE p (id INTEGER PRIMARY KEY)');
    $bd->consultar('CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER DEFAULT 1 REFERENCES p(id) ON DELETE SET DEFAULT, corto TEXT, largo TEXT, emojis TEXT,'
        . ' a VARCHAR(16000), b VARCHAR(16000), c VARCHAR(16000), d VARCHAR(16000))');
    $bd->consultar('INSERT INTO p VALUES (1), (2)');
    $bd->consultar('INSERT INTO h (id, p, corto, largo, emojis, a) VALUES (1, 2, ?, ?, ?, ?)',
        [str_repeat('x', 60000), str_repeat('x', 70000), str_repeat('😀', 20000), 'a']);
    $tablas = [];
    foreach (['p', 'h'] as $n) {
        $tablas[] = ['tabla' => $n, 'columnas' => $bd->consultar("SHOW SCHEMA $n"), 'claves' => $bd->consultar("SHOW KEYS FROM $n"), 'filas' => $bd->consultar("SELECT * FROM $n")];
    }
    $my = Exportar::volcado('mysqltam', $tablas, [], [], [], 'mysql');
    $ac = Exportar::volcado('mysqltam', $tablas, [], [], [], 'access');
    $ddl = preg_match('/CREATE TABLE `h` \((.*?)\n\)/s', $my, $x) ? $x[1] : '';
    $bien = str_contains($ddl, '`corto` TEXT') && str_contains($ddl, '`largo` MEDIUMTEXT') && str_contains($ddl, '`emojis` MEDIUMTEXT')
        && !str_contains($my, 'SET DEFAULT ON') && str_contains($my, 'ON DELETE NO ACTION') && str_contains($my, 'InnoDB')
        && substr_count($ddl, ' TEXT') >= 2 && str_contains($ac, 'SET DEFAULT: ');
    if (!$bien) {
        return $ddl . "\n" . substr($ac, 0, 400);
    }
    // Y con un MySQL de verdad delante, se carga
    if ($m === null) { return true; }
    $bdm = 'jsonsqldb_f14_tam_' . getmypid();
    $m->query("CREATE DATABASE `$bdm`");
    $m->select_db($bdm);
    $error = '';
    if ($m->multi_query($my)) {
        do { if ($r = $m->store_result()) { $r->free(); } if ($m->errno) { $error = $m->error; } } while ($m->more_results() && $m->next_result());
    } else {
        $error = $m->error;
    }
    $largo = $error === '' ? (int)$m->query('SELECT CHAR_LENGTH(largo) FROM h')->fetch_row()[0] : 0;
    $m->query("DROP DATABASE `$bdm`");
    return $error === '' && $largo === 70000 ?: "MySQL: $error ($largo)";
});
chk('B7 y B8: PostgreSQL con una vista materializada (vista normal, sin REFRESH ni sus índices) y una tabla particionada (todo en la madre) (2.9)', function () {
    $f = JSONSQLDB_DATA_PATH . '/b78.sql';
    file_put_contents($f, "-- PostgreSQL database dump\nSET standard_conforming_strings = on;\n"
        . "DROP MATERIALIZED VIEW IF EXISTS public.resumen;\n"
        . "CREATE TABLE public.ventas (id integer NOT NULL, dia date NOT NULL, total numeric(10,2)) PARTITION BY RANGE (dia);\n"
        . "CREATE TABLE public.ventas_2024 PARTITION OF public.ventas FOR VALUES FROM ('2024-01-01') TO ('2025-01-01');\n"
        . "CREATE TABLE public.ventas_2025 (id integer NOT NULL, dia date NOT NULL, total numeric(10,2));\n"
        . "ALTER TABLE ONLY public.ventas ATTACH PARTITION public.ventas_2025 FOR VALUES FROM ('2025-01-01') TO ('2026-01-01');\n"
        . "CREATE MATERIALIZED VIEW public.resumen AS\n SELECT count(*) AS n, sum(ventas.total) AS total\n   FROM public.ventas\n  WITH NO DATA;\n"
        . "COPY public.ventas_2024 (id, dia, total) FROM stdin;\n1\t2024-05-01\t10.00\n2\t2024-06-01\t20.00\n\\.\n"
        . "COPY public.ventas_2025 (id, dia, total) FROM stdin;\n3\t2025-02-01\t5.50\n\\.\n"
        . "ALTER TABLE ONLY public.ventas ADD CONSTRAINT ventas_pkey PRIMARY KEY (id, dia);\n"
        . "ALTER TABLE ONLY public.ventas_2024 ADD CONSTRAINT ventas_2024_pkey PRIMARY KEY (id, dia);\n"
        . "CREATE INDEX ventas_2024_dia_idx ON public.ventas_2024 USING btree (dia);\n"
        . "ALTER INDEX public.ventas_pkey ATTACH PARTITION public.ventas_2024_pkey;\n"
        . "CREATE UNIQUE INDEX resumen_idx ON public.resumen USING btree (n);\n"
        . "REFRESH MATERIALIZED VIEW public.resumen;\n");
    Database::crear('b78');
    $r = Importar::sql($f, 'b78');
    $bd = new Database('b78');
    $tablas = array_column($bd->consultar('SHOW TABLES'), 'tabla');
    $vista = $bd->consultar('SELECT n, total FROM resumen');
    return $tablas === ['ventas'] && $vista === [['n' => 3, 'total' => 35.5]]
        && str_contains($r, 'materializadas') && str_contains($r, 'Particiones') && !str_contains($r, 'Funciones y procedimientos')
        ?: json_encode([$tablas, $vista, $r], JSON_UNESCAPED_UNICODE);
});
chk('B9: un .dump de SQLite con una tabla virtual FTS se importa sin ella ni sus tablas auxiliares (2.9)', function () {
    $f = JSONSQLDB_DATA_PATH . '/b9.sql';
    file_put_contents($f, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCREATE TABLE normal(id INTEGER PRIMARY KEY, x TEXT);\nINSERT INTO normal VALUES(1,'uno');\n"
        . "PRAGMA writable_schema=ON;\nINSERT INTO sqlite_schema(type,name,tbl_name,rootpage,sql)VALUES('table','busca','busca',0,'CREATE VIRTUAL TABLE busca USING fts5(texto)');\n"
        . "CREATE TABLE IF NOT EXISTS 'busca_data'(id INTEGER PRIMARY KEY, block BLOB);\nINSERT INTO busca_data VALUES(1,X'00');\n"
        . "CREATE TABLE IF NOT EXISTS 'busca_idx'(segid, term, pgno, PRIMARY KEY(segid, term)) WITHOUT ROWID;\n"
        . "CREATE TABLE IF NOT EXISTS 'busca_content'(id INTEGER PRIMARY KEY, c0);\nINSERT INTO busca_content VALUES(1,'hola');\n"
        . "CREATE TABLE IF NOT EXISTS 'busca_docsize'(id INTEGER PRIMARY KEY, sz BLOB);\nCREATE TABLE IF NOT EXISTS 'busca_config'(k PRIMARY KEY, v) WITHOUT ROWID;\n"
        . "INSERT INTO busca_config VALUES('version',4);\nPRAGMA writable_schema=OFF;\nCOMMIT;\n");
    Database::crear('b9');
    $r = Importar::sql($f, 'b9');
    $tablas = array_column((new Database('b9'))->consultar('SHOW TABLES'), 'tabla');
    return $tablas === ['normal'] && str_contains($r, 'virtuales') ?: json_encode([$tablas, $r], JSON_UNESCAPED_UNICODE);
});
chk('B10: un volcado solo de datos (mysqldump --no-create-info) en una base sin las tablas dice por qué no se puede (2.9)', function () {
    $f = JSONSQLDB_DATA_PATH . '/b10.sql';
    file_put_contents($f, "-- MySQL dump 10.13\nLOCK TABLES `t` WRITE;\nINSERT INTO `t` VALUES (1,'a'),(2,'b');\nUNLOCK TABLES;\n");
    Database::crear('b10');
    try { Importar::sql($f, 'b10'); return 'se importó'; } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'no trae la estructura') ?: $e->getMessage();
    }
});
chk('dos tablas que acaban en el mismo nombre no se pisan: la segunda recibe un sufijo y se avisa', function () {
    // Antes, `ventas_2024` borraba con su DROP TABLE IF EXISTS la tabla que
    // salía de `ventas-2024`, y se perdían sus filas
    Database::crear('choque');
    $r = Importar::sql(__DIR__ . '/volcados/mysql_nombres_que_chocan.sql', 'choque');
    $bd = new Database('choque');
    $a = $bd->consultar('SELECT * FROM ventas_2024 ORDER BY id');
    $b = $bd->consultar('SELECT * FROM ventas_2024_2');
    return $a === [['id' => 1, 'x' => 'a'], ['id' => 2, 'x' => 'b']] && $b === [['id' => 9, 'y' => 'z']]
        && str_contains($r, "'ventas_2024' cambiado a 'ventas_2024_2'") ?: json_encode([$a, $b, $r]);
});
chk('ALTER … OWNER TO de pg_dump (sin --no-owner) y demás ALTER que no son de tabla se saltan', function () {
    $f = JSONSQLDB_DATA_PATH . '/owner.sql';
    file_put_contents($f, "-- PostgreSQL database dump\nSET standard_conforming_strings = on;\nCREATE TABLE public.t (id integer NOT NULL);\n"
        . "ALTER TABLE public.t OWNER TO postgres;\nCREATE VIEW public.v AS\n SELECT t.id\n   FROM public.t;\nALTER VIEW public.v OWNER TO postgres;\n"
        . "ALTER FUNCTION public.f() OWNER TO postgres;\nALTER TYPE public.estado OWNER TO postgres;\nCOPY public.t (id) FROM stdin;\n1\n2\n\\.\n");
    Database::crear('owner');
    Importar::sql($f, 'owner');
    $bd = new Database('owner');
    return count($bd->consultar('SELECT * FROM v')) === 2 ?: 'no';
});
chk('semanas en las vistas: DATEADD(week, …) de SQL Server, interval week de MySQL, \'2 weeks\' de PostgreSQL y DateAdd(\'ww\') de Access (2.7.5)', function () {
    // DATEADD(week, 2, d) de SQL Server sumaba 2 días, no 14, y PostgreSQL no
    // aceptaba '2 weeks'. Las cuatro, desde el 1 de marzo de 2026
    $volcados = [
        'ss'  => "CREATE TABLE [dbo].[t] ([id] int NOT NULL PRIMARY KEY, [d] datetime NULL)\nGO\nINSERT [dbo].[t] ([id], [d]) VALUES (1, CAST(N'2026-03-01T00:00:00.000' AS DateTime))\nGO\n"
               . "CREATE VIEW [dbo].[v] AS SELECT DATEADD(week, 2, d) AS mas, DATEADD(wk, -1, d) AS menos, DATEADD(week, id, d) AS calc FROM dbo.t\nGO\n",
        'my'  => "-- MySQL dump 10.13\nCREATE TABLE `t` (`id` int NOT NULL, `d` datetime DEFAULT NULL, PRIMARY KEY (`id`));\nINSERT INTO `t` VALUES (1,'2026-03-01 00:00:00');\n"
               . "/*!50001 CREATE ALGORITHM=UNDEFINED */\n/*!50001 VIEW `v` AS select (`t`.`d` + interval 2 week) AS `mas`,(`t`.`d` - interval 1 week) AS `menos`,(`t`.`d` + interval `t`.`id` week) AS `calc` from `t` */;\n",
        'pg'  => "-- PostgreSQL database dump\nCREATE TABLE public.t (id integer NOT NULL, d timestamp without time zone);\nCOPY public.t (id, d) FROM stdin;\n1\t2026-03-01 00:00:00\n\\.\n"
               . "CREATE VIEW public.v AS\n SELECT (t.d + '2 weeks'::interval) AS mas,\n    (t.d - '1 week'::interval) AS menos,\n    (t.d + '7 days'::interval) AS calc\n   FROM public.t;\n",
        'acc' => "-- jsonsqldb-dialecto: access\nCREATE TABLE [t] ([id] LONG CONSTRAINT [PK_t] PRIMARY KEY, [d] DATETIME);\nINSERT INTO [t] ([id], [d]) VALUES (1, #2026-03-01 00:00:00#);\n"
               . "CREATE VIEW [v] AS SELECT DateAdd('ww', 2, [d]) AS mas, DateAdd('ww', -1, [d]) AS menos, DateAdd('ww', [id], [d]) AS calc FROM t;\n",
    ];
    $esperado = [['mas' => '2026-03-15 00:00:00', 'menos' => '2026-02-22 00:00:00', 'calc' => '2026-03-08 00:00:00']];
    $mal = [];
    foreach ($volcados as $n => $sql) {
        $f = JSONSQLDB_DATA_PATH . "/semanas_$n.sql";
        file_put_contents($f, $sql);
        Database::crear("semanas_$n");
        Importar::sql($f, "semanas_$n");
        $r = (new Database("semanas_$n"))->consultar('SELECT mas, menos, calc FROM v');
        if ($r !== $esperado) { $mal[] = "$n: " . json_encode($r); }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('un volcado con saltos de línea de Windows se reconoce por su cabecera: el de Access, con una tabla de nombre numérico, pasa por el traductor (2.9: se tomaba por uno de aquí y paraba en la tabla)', function () {
    $f = JSONSQLDB_DATA_PATH . '/windows.sql';
    file_put_contents($f, str_replace("\n", "\r\n", "-- jsonsqldb-dialecto: access\n-- escrito en Windows\n"
        . "CREATE TABLE [210] ([id] LONG CONSTRAINT [PK_210] PRIMARY KEY, [Nombre] TEXT(20));\nINSERT INTO [210] ([id], [Nombre]) VALUES (1, 'uno');\n"));
    Database::crear('windows');
    Importar::sql($f, 'windows');
    @unlink($f);
    $r = (new Database('windows'))->consultar('SELECT id, Nombre FROM _210');
    return $r === [['id' => 1, 'Nombre' => 'uno']] ?: json_encode($r);
});
chk('al importar, las columnas guardan su nombre con ñ, acentos o espacios, y en el de una tabla la letra con acento pasa a la suya: AÑO a ANO (2.9: un _ por byte, también en las columnas)', function () {
    $f = JSONSQLDB_DATA_PATH . '/nombres.sql';
    file_put_contents($f, "-- jsonsqldb-dialecto: access\n"
        . "CREATE TABLE [Pagos Año] ([Id] LONG CONSTRAINT [PK_Pagos] PRIMARY KEY, [Año] LONG, [Num Domic] TEXT(20));\n"
        . "INSERT INTO [Pagos Año] ([Id], [Año], [Num Domic]) VALUES (1, 2026, 'uno');\n"
        . "CREATE INDEX [Índice Año] ON [Pagos Año] ([Año]);\n"
        . "CREATE VIEW [Vista Año] AS SELECT [Pagos Año].[Año], p.[Num Domic] FROM [Pagos Año], [Pagos Año] AS p WHERE p.[Id] = [Pagos Año].[Id];\n");
    Database::crear('nombres');
    $resumen = Importar::sql($f, 'nombres');
    $bd = new Database('nombres');
    $cols = array_column($bd->consultar('SHOW SCHEMA Pagos_Ano'), 'columna');
    $indices = array_column($bd->consultar('SHOW INDEXES'), 'indice');
    $vista = $bd->consultar('SELECT * FROM Vista_Ano');
    $mal = $cols === ['Id', 'Año', 'Num Domic'] && in_array('Indice_Ano', $indices, true) && $vista === [['Año' => 2026, 'Num Domic' => 'uno']]
        ? [] : [json_encode([$cols, $indices, $vista, $resumen], JSON_UNESCAPED_UNICODE)];
    // Y en los volcados de los demás, que pasan por el mismo traductor
    foreach (['mysql' => "-- MySQL dump 10.13\nCREATE TABLE `Pagos Año` (`Año` int, `Num Domic` varchar(20));\nINSERT INTO `Pagos Año` VALUES (2026,'uno');\n",
              'pg' => "-- PostgreSQL database dump\nCREATE TABLE public.\"Pagos Año\" (\"Año\" integer, \"Num Domic\" text);\nINSERT INTO public.\"Pagos Año\" (\"Año\", \"Num Domic\") VALUES (2026, 'uno');\n",
              'ss' => "CREATE TABLE [dbo].[Pagos Año] ([Año] int, [Num Domic] nvarchar(20))\nGO\nINSERT [dbo].[Pagos Año] ([Año], [Num Domic]) VALUES (2026, N'uno')\nGO\n",
              'sqlite' => "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCREATE TABLE \"Pagos Año\" (\"Año\" INTEGER, \"Num Domic\" TEXT);\nINSERT INTO \"Pagos Año\" VALUES(2026,'uno');\nCOMMIT;\n"] as $d => $sql) {
        file_put_contents($f, $sql);
        Database::crear("nombres_$d");
        Importar::sql($f, "nombres_$d");
        $r = (new Database("nombres_$d"))->consultar('SELECT * FROM Pagos_Ano');
        if ($r !== [['Año' => 2026, 'Num Domic' => 'uno']]) { $mal[] = "$d: " . json_encode($r, JSON_UNESCAPED_UNICODE); }
    }
    @unlink($f);
    return $mal === [] ?: implode(' | ', $mal);
});
chk('una vista de Access con una tabla de otra base de Access se salta diciéndolo (2.9: «falta la condición ON del INNER JOIN»)', function () {
    $f = JSONSQLDB_DATA_PATH . '/otra_base.sql';
    file_put_contents($f, "-- jsonsqldb-dialecto: access\nCREATE TABLE [t] ([Id] LONG);\n"
        . "CREATE VIEW [v] AS SELECT t.[Id] FROM t INNER JOIN [C:\\datos\\otra.mdb].remota AS r ON r.[Id] = t.[Id];\n"
        . "CREATE VIEW [w] AS SELECT [Id] FROM remota IN 'C:\\datos\\otra.accdb';\n");
    Database::crear('otra_base');
    $resumen = Importar::sql($f, 'otra_base');
    @unlink($f);
    return substr_count($resumen, "usa la tabla 'remota' de otra base de Access") === 2 && str_contains($resumen, 'otra.mdb') && str_contains($resumen, 'otra.accdb') ?: $resumen;
});
chk('con la carpeta de la base a mano, una importación que falla deja la base como estaba (todo o nada)', function () {
    Database::crear('todo');
    $bd = new Database('todo');
    $bd->consultar('CREATE TABLE previa (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar("INSERT INTO previa VALUES (1, 'antes')");
    $f = JSONSQLDB_DATA_PATH . '/falla.sql';
    file_put_contents($f, "CREATE TABLE nueva (id INTEGER PRIMARY KEY);\nINSERT INTO nueva VALUES (1);\nUPDATE previa SET x = 'después';\n"
        . "INSERT INTO nueva VALUES (1);\n");                // clave repetida: falla aquí
    try {
        Importar::sql($f, 'todo', 'jsonsqldb', JSONSQLDB_DATA_PATH . '/todo');
        return 'no falló';
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
    }
    $bd = new Database('todo');
    $tablas = array_column($bd->consultar('SHOW TABLES'), 'tabla');
    $x = $bd->consultar('SELECT x FROM previa')[0]['x'];
    // Sin la carpeta (panel en otra máquina), lo de antes del fallo queda hecho, como siempre
    try { Importar::sql($f, 'todo', 'jsonsqldb'); } catch (RuntimeException $e) { $msg2 = $e->getMessage(); }
    $quedo = array_column($bd->consultar('SHOW TABLES'), 'tabla');
    sort($quedo);
    return $tablas === ['previa'] && $x === 'antes' && str_contains($msg, 'ha vuelto a como estaba')
        && !is_dir(JSONSQLDB_DATA_PATH . '/todo.antes-de-importar') && $quedo === ['nueva', 'previa'] && str_contains($msg2 ?? '', 'no va en una transacción')
        ?: json_encode([$tablas, $x, $msg, $quedo]);
});
chk('una importación todo o nada que falla no deshace lo que otro proceso escribe mientras tanto: espera a que acabe (2.9)', function () {
    Database::crear('concurre');
    $bd = new Database('concurre');
    $bd->consultar('CREATE TABLE previa (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar("INSERT INTO previa VALUES (1, 'antes')");
    $f = JSONSQLDB_DATA_PATH . '/concurre.sql';
    $sql = "CREATE TABLE nueva (id INTEGER PRIMARY KEY);\n";
    for ($i = 0; $i < 400; $i++) { $sql .= "UPDATE previa SET x = 'import $i' WHERE id = 1;\n"; }
    file_put_contents($f, $sql . "INSERT INTO nueva VALUES (1);\nINSERT INTO nueva VALUES (1);\n");   // falla al final
    // Otro proceso escribe en la base a la vez: espera a que la importación acabe
    // Espera a que la importación tenga el bloqueo exclusivo de la base (no un
    // tiempo fijo: en una máquina lenta la importación acababa antes de empezar él)
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' $lk = fopen(' . var_export(JSONSQLDB_DATA_PATH . '/concurre/.lock', true) . ', "c"); $h = microtime(true);'
        . ' while (microtime(true) - $h < 10 && flock($lk, LOCK_EX | LOCK_NB)) { flock($lk, LOCK_UN); usleep(2000); } fclose($lk); $t0 = microtime(true);'
        . ' (new JsonSQLDB\\Database("concurre", ' . var_export(JSONSQLDB_DATA_PATH, true) . '))->consultar("INSERT INTO previa VALUES (2, \'otro\')");'
        . ' echo json_encode([$t0, microtime(true)]);';
    $otro = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
    try {
        Importar::sql($f, 'concurre', 'jsonsqldb', JSONSQLDB_DATA_PATH . '/concurre');
        $msg = 'no falló';
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
    }
    $fin = microtime(true);
    $tiempos = json_decode((string)stream_get_contents($tub[1]), true);
    $err = (string)stream_get_contents($tub[2]);
    fclose($tub[1]); fclose($tub[2]); proc_close($otro);
    @unlink($f);
    $bd = new Database('concurre');
    $r = [array_column($bd->consultar('SHOW TABLES'), 'tabla'), $bd->consultar('SELECT id, x FROM previa ORDER BY id')];
    // El otro empezó a escribir durante la importación y acabó después de ella
    $espero = is_array($tiempos) && $tiempos[0] < $fin && $tiempos[1] >= $fin - 0.01;
    return $r === [['previa'], [['id' => 1, 'x' => 'antes'], ['id' => 2, 'x' => 'otro']]] && $espero && str_contains($msg, 'ha vuelto a como estaba')
        ?: json_encode([$r, $tiempos, $fin, $msg, $err]);
});
chk('una importación todo o nada que muere por un error fatal (el tiempo) lo dice, y lo importado se deshace al volver a usar la base (2.9)', function () {
    Database::crear('fatal');
    $bd = new Database('fatal');
    $bd->consultar('CREATE TABLE previa (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar("INSERT INTO previa VALUES (1, 'antes')");
    $f = JSONSQLDB_DATA_PATH . '/fatal.sql';
    $sql = "CREATE TABLE nueva (id INTEGER PRIMARY KEY);\n";
    for ($i = 0; $i < 20000; $i++) { $sql .= "UPDATE previa SET x = 'import $i' WHERE id = 1;\n"; }
    file_put_contents($f, $sql);
    $lib = dirname(__DIR__) . '/jsonsqldbadmin/';
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_DATA_PATH", ' . var_export(JSONSQLDB_DATA_PATH, true) . ');'
        . ' define("JSONSQLDB_LOG_PATH", ' . var_export(JSONSQLDB_LOG_PATH, true) . ');'
        . ' define("ADMIN_CONEXION", "directa"); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' require ' . var_export($lib . 'config.dist.php', true) . '; require ' . var_export($lib . 'lib/util.php', true) . ';'
        . ' foreach (["Idioma", "Exportar", "Api", "Traductor", "Importar"] as $c) { require ' . var_export($lib . 'lib/', true) . ' . "$c.php"; }'
        . ' Idioma::elegir("es"); set_time_limit(1);'
        . ' Importar::sql(' . var_export($f, true) . ', "fatal", "jsonsqldb", ' . var_export(JSONSQLDB_DATA_PATH . '/fatal', true) . ');';
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($codigo) . ' 2>&1');
    @unlink($f);
    $bd = new Database('fatal');
    $r = [array_column($bd->consultar('SHOW TABLES'), 'tabla'), $bd->consultar('SELECT x FROM previa'), is_dir(JSONSQLDB_DATA_PATH . '/fatal/.lote')];
    return $r === [['previa'], [['x' => 'antes']], false] && str_contains($salida, 'La importación se ha parado')
        && str_contains($salida, 'se deshace solo') ?: json_encode([$r, substr($salida, 0, 300)]);
});
chk('al devolver una base a como estaba, se vacía la caché del motor en APCu, también la de la 2.9 (jsq2:)', function () {
    if (!function_exists('apcu_store')) { return null; }
    $codigo = 'define("ADMIN_CONEXION", "directa"); require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/config.dist.php', true) . ';'
        . ' require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php', true) . ';'
        . ' foreach (["Idioma", "Api", "Importar"] as $c) { require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/', true) . ' . "$c.php"; }'
        . ' apcu_store("jsq2:abc:x", 1); apcu_store("jsq:abc:x", 1); apcu_store("otra", 1);'
        . ' $m = new ReflectionMethod("Importar", "olvidarCache"); PHP_VERSION_ID < 80100 && $m->setAccessible(true); $m->invoke(null);'
        . ' echo json_encode([apcu_exists("jsq2:abc:x"), apcu_exists("jsq:abc:x"), apcu_exists("otra")]);';
    $r = shell_exec(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 -r ' . escapeshellarg($codigo) . ' 2>&1');
    return trim((string)$r) === '[false,false,true]' ?: (string)$r;
});

chk('un CSV con una comilla sin cerrar y un fichero de un ZIP que no caben en la memoria se rechazan con un error, sin agotarla, y el CSV con su línea contando las vacías (2.9)', function () {
    Database::crear('topes');
    (new Database('topes'))->consultar('CREATE TABLE p (id INTEGER, t TEXT)');
    $f = JSONSQLDB_DATA_PATH . '/topes.csv';
    $fh = fopen($f, 'wb');
    fwrite($fh, "id;t\n1;uno\n\n2;\"sin cerrar\n");
    for ($i = 0; $i < 40; $i++) { fwrite($fh, str_repeat('x', 1048575) . "\n"); }
    fclose($fh);
    $z = JSONSQLDB_DATA_PATH . '/topes.zip';
    if (class_exists('ZipArchive')) {
        $a = new ZipArchive();
        $a->open($z, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $a->addFromString('topes/p.json', '{"rows":["' . str_repeat('x', 40000000) . '"]}');
        $a->close();
    }
    $lib = dirname(__DIR__) . '/jsonsqldbadmin/';
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_DATA_PATH", ' . var_export(JSONSQLDB_DATA_PATH, true) . ');'
        . ' define("JSONSQLDB_LOG_PATH", ' . var_export(JSONSQLDB_LOG_PATH, true) . ');'
        . ' define("ADMIN_CONEXION", "directa"); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' require ' . var_export($lib . 'config.dist.php', true) . '; require ' . var_export($lib . 'lib/util.php', true) . ';'
        . ' foreach (["Idioma", "Exportar", "Api", "Traductor", "Importar"] as $c) { require ' . var_export($lib . 'lib/', true) . ' . "$c.php"; }'
        . ' Idioma::elegir("es");'
        . ' try { Importar::csv(' . var_export($f, true) . ', "topes", "p"); echo "csv: pasó\n"; } catch (RuntimeException $e) { echo "csv: ", $e->getMessage(), "\n"; }'
        . (class_exists('ZipArchive') ? ' try { Importar::zip(' . var_export($z, true) . ', ' . var_export(JSONSQLDB_DATA_PATH . '/topes', true) . '); echo "zip: pasó\n"; }'
            . ' catch (RuntimeException $e) { echo "zip: ", $e->getMessage(), "\n"; }' : '');
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=48M -r ' . escapeshellarg($codigo) . ' 2>&1');
    @unlink($f);
    @unlink($z);
    $filas = (new Database('topes'))->consultar('SELECT COUNT(*) AS n FROM p');
    return str_contains($salida, 'csv: La línea 4 del CSV ocupa más de') && (!class_exists('ZipArchive') || str_contains($salida, "zip: 'p.json' ocupa más de"))
        && $filas === [['n' => 0]] ?: json_encode([substr($salida, 0, 400), $filas], JSON_UNESCAPED_UNICODE);
});
chk('restaurar un ZIP con una parte de 18 MB y un original de .reparados/ de 100 MB cabe en 96 MB de memoria; un apartadas.json que no cabe se rechaza con un error (2.9: no cabía la parte, y los otros agotaban la memoria)', function () {
    if (!class_exists('ZipArchive')) { return null; }
    Database::crear('zgrande');
    $bd = new Database('zgrande');
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar('INSERT INTO t VALUES (?, ?)', [1, str_repeat('a', 9000000)]);
    $bd->consultar('INSERT INTO t VALUES (?, ?)', [2, str_repeat('b', 9000000)]);
    unset($bd);
    $dir = JSONSQLDB_DATA_PATH . '/zgrande';
    $zip = static function (string $z, bool $apartadas) use ($dir): void {
        $a = new ZipArchive();
        $a->open($z, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ((array)glob("$dir/*.json") as $f) { $a->addFile((string)$f, 'zgrande/' . basename((string)$f)); }
        if (!$apartadas) {
            $a->addFile("$dir.original", 'zgrande/.reparados/t.json.20261009-120000');
            $a->setCompressionName('zgrande/.reparados/t.json.20261009-120000', ZipArchive::CM_STORE);
        }
        if ($apartadas) { $a->addFromString('zgrande/.reparados/apartadas.json', '{"siguiente":2,"filas":[{"texto":"' . str_repeat('x', 40000000) . '"}]}'); }
        $a->close();
    };
    $fh = fopen("$dir.original", 'wb');
    for ($i = 0; $i < 100; $i++) { fwrite($fh, str_repeat('o', 1048576)); }
    fclose($fh);
    $z1 = JSONSQLDB_DATA_PATH . '/zgrande1.zip';
    $z2 = JSONSQLDB_DATA_PATH . '/zgrande2.zip';
    $zip($z1, false);
    $zip($z2, true);
    $lib = dirname(__DIR__) . '/jsonsqldbadmin/';
    $restaurar = static function (string $z) use ($lib, $dir): string {
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_DATA_PATH", ' . var_export(JSONSQLDB_DATA_PATH, true) . ');'
        . ' define("JSONSQLDB_LOG_PATH", ' . var_export(JSONSQLDB_LOG_PATH, true) . ');'
            . ' define("ADMIN_CONEXION", "directa"); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . ' require ' . var_export($lib . 'config.dist.php', true) . '; require ' . var_export($lib . 'lib/util.php', true) . ';'
            . ' foreach (["Idioma", "Exportar", "Api", "Traductor", "Importar"] as $c) { require ' . var_export($lib . 'lib/', true) . ' . "$c.php"; }'
            . ' Idioma::elegir("es");'
            . ' try { Importar::zip(' . var_export($z, true) . ', ' . var_export($dir, true) . '); echo "pasó"; } catch (RuntimeException $e) { echo $e->getMessage(); }';
        // Sin shell: con PHP 8.0, escapeshellarg() quita la ó de «pasó» si el locale es C
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=96M', '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos);
        $salida = (string)stream_get_contents($tubos[1]);
        proc_close($p);
        return trim($salida);
    };
    file_put_contents("$dir/t.json", '');                    // estropeada: la restauración la devuelve
    $grande = $restaurar($z1);
    $filas = (new Database('zgrande'))->consultar('SELECT id, LENGTH(x) AS n FROM t ORDER BY id');
    $original = (int)@filesize("$dir/.reparados/t.json.20261009-120000");
    $sinSitio = $restaurar($z2);
    @unlink($z1);
    @unlink($z2);
    @unlink("$dir.original");
    Database::borrar('zgrande');
    return $grande === 'pasó' && $filas === [['id' => 1, 'n' => 9000000], ['id' => 2, 'n' => 9000000]] && $original === 104857600
        && str_contains($sinSitio, "'apartadas.json' ocupa más de") ?: json_encode([substr($grande, 0, 300), $filas, substr($sinSitio, 0, 300)], JSON_UNESCAPED_UNICODE);
});
chk('un CSV en Latin-1 se carga con sus acentos', function () {
    Database::crear('csvlat');
    $bd = new Database('csvlat');
    $bd->consultar('CREATE TABLE p (id INTEGER, nombre TEXT)');
    $f = JSONSQLDB_DATA_PATH . '/p.csv';
    file_put_contents($f, "id;nombre\n1;Jos\xE9\n2;Mu\xF1oz\n");
    $r = Importar::csv($f, 'csvlat', 'p');
    return array_column($bd->consultar('SELECT nombre FROM p ORDER BY id'), 'nombre') === ['José', 'Muñoz'] && str_contains($r, 'Latin-1') ?: $r;
});

chk('un volcado de MySQL en Latin-1 con un texto de 250 KB se carga entero, con sus acentos (2.9: la sentencia se quedaba vacía)', function () {
    Database::crear('latlargo');
    $bd = new Database('latlargo');
    $largo = str_repeat("a\\'b ", 50000);                 // 250 KB, con escapes de MySQL
    $f = JSONSQLDB_DATA_PATH . '/latlargo.sql';
    file_put_contents($f, "-- MySQL dump 10.13\nCREATE TABLE `p` (`id` int NOT NULL, `t` longtext, PRIMARY KEY (`id`));\n"
        . "INSERT INTO `p` VALUES (1,'Jos\xE9 $largo'),(2,'Mu\xF1oz'),(3,'Ñandú');\n");     // la 3, en UTF-8: se deja como viene
    $r = Importar::sql($f, 'latlargo', 'mysql');
    $filas = $bd->consultar('SELECT id, LENGTH(t) AS n, SUBSTR(t, 1, 5) AS ini FROM p ORDER BY id');
    return $filas === [['id' => 1, 'n' => 5 + 200000, 'ini' => 'José '], ['id' => 2, 'n' => 5, 'ini' => 'Muñoz'], ['id' => 3, 'n' => 5, 'ini' => 'Ñandú']]
        ?: json_encode([$filas, $r], JSON_UNESCAPED_UNICODE);
});

chk('un CSV exportado se vuelve a cargar igual: una barra seguida de comillas, una barra al final, el separador y un salto de línea (2.9)', function () {
    Database::crear('csvida');
    $bd = new Database('csvida');
    $bd->consultar('CREATE TABLE p (id INTEGER, t TEXT)');
    $textos = ['a\\"b', 'c\\', '"entre comillas"', 'x;y,z', "dos\nlíneas"];
    $filas = [];
    foreach ($textos as $i => $t) { $filas[] = ['id' => $i + 1, 't' => $t]; }
    $f = JSONSQLDB_DATA_PATH . '/ida.csv';
    file_put_contents($f, Exportar::textoCsv($filas));
    $r = Importar::csv($f, 'csvida', 'p');
    $vuelta = array_column($bd->consultar('SELECT t FROM p ORDER BY id'), 't');
    return $vuelta === $textos ?: json_encode([$vuelta, $r, file_get_contents($f)], JSON_UNESCAPED_UNICODE);
});

echo "\n== Restaurar desde ZIP: rutas que salen de la carpeta ==\n";
chk('un ZIP con «..», «.» o «//» en una ruta se rechaza entero, y nada se escribe fuera de la base', function () {
    // Dos auditorías externas señalaron las rutas que acaban en «..». La lista
    // de nombres permitidos ya las dejaba sin escribir; ahora, además, el ZIP
    // se rechaza entero y cada destino se comprueba dentro de la base
    if (!class_exists('ZipArchive')) { return null; }
    $dir = JSONSQLDB_DATA_PATH . '/zipslip';
    @mkdir("$dir/db", 0775, true);
    $mal = [];
    foreach (['db/..', 'db/foo/..', 'db/./t.json', 'db//t.json', 'db/sub/../../fuera.json', 'db/..\\fuera.json', '../fuera.json'] as $nombre) {
        $f = "$dir/p.zip";
        @unlink($f);
        $z = new ZipArchive();
        $z->open($f, ZipArchive::CREATE);
        $z->addFromString('db/t.meta.json', '{"columns":[],"pk":[]}');
        $z->addFromString($nombre, '{"columns":[],"pk":[]}');
        $z->close();
        try {
            Importar::zip($f, "$dir/db");
            $mal[] = "$nombre aceptado";
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'sale de la carpeta')) { $mal[] = "$nombre: " . $e->getMessage(); }
        }
    }
    $fuera = array_diff((array)scandir($dir), ['.', '..', 'db', 'p.zip']);
    return $mal === [] && $fuera === [] ?: implode(' | ', $mal) . ' · fuera: ' . implode(',', $fuera);
});

chk('Excel: un texto de más de 32.767 caracteres se corta y la última hoja, «Avisos», lo dice; también si solo hay caracteres de control quitados (2.9)', function () {
    if (!class_exists('ZipArchive')) { return null; }
    require_once dirname(__DIR__) . '/jsonsqldbadmin/lib/EscritorXlsx.php';
    $mal = [];
    foreach (['largo' => str_repeat('a', 40000), 'control' => "a\x01b"] as $caso => $texto) {
        $f = JSONSQLDB_DATA_PATH . "/avisos-$caso.xlsx";
        $x = new EscritorXlsx(['id', 'texto']);
        $x->fila([1, $texto]);
        $x->guardar($f);
        $z = new ZipArchive();
        $z->open($f);
        $libro = (string)$z->getFromName('xl/workbook.xml');
        $hoja1 = (string)$z->getFromName('xl/worksheets/sheet1.xml');
        $avisos = (string)$z->getFromName('xl/worksheets/sheet2.xml');
        $z->close();
        @unlink($f);
        $esperado = $caso === 'largo' ? '32.767 caracteres por celda' : 'caracter(es) de control';
        if (!str_contains($libro, 'name="Hoja 1"') || !str_contains($libro, 'name="Avisos"') || !str_contains($avisos, $esperado)
            || ($caso === 'largo' && (str_contains($hoja1, str_repeat('a', 32768)) || !str_contains($hoja1, str_repeat('a', 32767))))) {
            $mal[] = "$caso: $libro";
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});

chk('restaurar un ZIP no cambia el .htaccess ni el web.config de la base, que son los del motor (2.9: uno a medida abría la carpeta)', function () {
    if (!class_exists('ZipArchive')) { return null; }
    Database::crear('zipprot');
    $ruta = JSONSQLDB_DATA_PATH . '/zipprot';
    $motor = [file_get_contents("$ruta/.htaccess"), file_get_contents("$ruta/web.config")];
    $proteccion = (new ReflectionClassConstant('Importar', 'PROTECCION'))->getValue();
    if ($motor !== [$proteccion['.htaccess'], $proteccion['web.config']]) { return 'el panel y el motor no ponen lo mismo'; }
    $f = JSONSQLDB_DATA_PATH . '/prot.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('zipprot/t.meta.json', '{"columns":[],"pk":[]}');
    $z->addFromString('zipprot/.htaccess', "Require all granted\n");
    $z->addFromString('zipprot/web.config', '<configuration/>');
    $z->addFromString('zipprot/sub/.htaccess', "Require all granted\n");
    $z->close();
    Importar::zip($f, $ruta);
    @unlink($f);
    $despues = [file_get_contents("$ruta/.htaccess"), file_get_contents("$ruta/web.config")];
    return $despues === $motor && !file_exists("$ruta/sub/.htaccess") ?: json_encode($despues);
});

echo "\n== Lo que no volvía a entrar al importar (2.9) ==\n";
/** Importa $texto como volcado en la base nueva $base y devuelve el resumen. */
function importarTexto(string $base, string $texto, string $dialecto = 'auto'): string {
    $f = JSONSQLDB_DATA_PATH . "/$base.sql";
    file_put_contents($f, $texto);
    Database::crear($base);
    try {
        return Importar::sql($f, $base, $dialecto);
    } finally {
        @unlink($f);
    }
}
chk('un trigger con IF … END IF, también con el END y el IF en dos líneas, es una sola sentencia: el volcado SQL y el JSON del propio panel se vuelven a importar, y la consola no lo parte (2.9)', function () {
    Database::crear('endif');
    $bd = new Database('endif');
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER)');
    $bd->consultar('CREATE TRIGGER trg BEFORE INSERT ON t BEGIN IF NEW.a > 0 THEN SET NEW.b = 1; ELSE SET NEW.b = 0; END IF; END');
    $bd->consultar("CREATE TRIGGER trg2 BEFORE UPDATE ON t BEGIN\n  IF NEW.a > 5 THEN\n    SET NEW.b = 2;\n  END\n  IF;\nEND");
    $bd->consultar('INSERT INTO t (id, a) VALUES (1, 5)');
    $tablas = [['tabla' => 't', 'columnas' => $bd->consultar('SHOW SCHEMA t'), 'claves' => [], 'filas' => $bd->consultar('SELECT * FROM t')]];
    $trgs = $bd->consultar('SHOW TRIGGERS');
    require_once dirname(__DIR__) . '/jsonsqldbadmin/lib/JsonFlujo.php';
    $trozos = [];
    $json = new ReflectionMethod(Exportar::class, 'volcadoJson');
    // Solo hace falta en PHP 8.0, y desde 8.5 avisa de obsoleta
    PHP_VERSION_ID < 80100 && $json->setAccessible(true);
    $json->invoke(null, 'endif', [['tabla' => 't', 'columnas' => $tablas[0]['columnas'], 'claves' => [], 'n' => 1]], $trgs, [], [], static fn(): array => $tablas[0]['filas'],
        static function (string $s) use (&$trozos): void { $trozos[] = $s; });
    $mal = [];
    foreach (['sql' => Exportar::volcado('endif', $tablas, $trgs, [], [], 'sqlite'), 'json' => implode('', $trozos)] as $formato => $texto) {
        importarTexto("endif_$formato", $texto);
        $otra = new Database("endif_$formato");
        $otra->consultar('INSERT INTO t (id, a) VALUES (2, -1)');
        $otra->consultar('UPDATE t SET a = 9 WHERE id = 1');
        $r = $otra->consultar('SELECT id, b FROM t ORDER BY id');
        if (count($otra->consultar('SHOW TRIGGERS')) !== 2 || $r !== [['id' => 1, 'b' => 2], ['id' => 2, 'b' => 0]]) {
            $mal[] = "$formato: " . json_encode($r);
        }
    }
    $partes = Importar::partir("CREATE TRIGGER x BEFORE INSERT ON t BEGIN\n IF NEW.a > 0 THEN SET NEW.b = 1;\n END\n IF;\nEND;\nSELECT CASE WHEN 1 THEN 2 END, 3; SELECT 4;");
    return $mal === [] && count($partes) === 3 ?: json_encode([$mal, $partes]);
});
chk('MySQL: DEFAULT \'0000-00-00\' (WordPress, MySQL < 5.7) se quita, la columna NOT NULL admite NULL y sus filas entran, con la fecha cero como NULL (2.9: paraba en el CREATE TABLE)', function () {
    $r = importarTexto('fechacero', "-- MySQL dump 10.13\nCREATE TABLE `t` (\n  `id` int NOT NULL AUTO_INCREMENT,\n"
        . "  `f` date NOT NULL DEFAULT '0000-00-00',\n  `ts` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',\n  PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=latin1;\nINSERT INTO `t` VALUES (1,'0000-00-00','2024-01-01 00:00:00'),(2,'2024-02-03','0000-00-00 00:00:00');\n");
    $bd = new Database('fechacero');
    $bd->consultar('INSERT INTO t (id) VALUES (3)');
    $filas = $bd->consultar('SELECT * FROM t ORDER BY id');
    return $filas === [['id' => 1, 'f' => null, 'ts' => '2024-01-01 00:00:00'], ['id' => 2, 'f' => '2024-02-03', 'ts' => null], ['id' => 3, 'f' => null, 'ts' => null]]
        && str_contains($r, 'Valores por defecto «0000-00-00» de MySQL quitados') ?: json_encode([$filas, $r], JSON_UNESCAPED_UNICODE);
});
chk('índices con COLLATE, NULLS LAST o una clase de operadores de PostgreSQL se crean sobre su columna; los de una expresión (lower(nombre), KEY ((…)) de MySQL 8) se saltan con un aviso (2.9: paraban la importación)', function () {
    $volcados = [
        'idx_sqlite' => "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCREATE TABLE t (id INTEGER PRIMARY KEY, nombre TEXT, fecha TEXT);\n"
            . "INSERT INTO t VALUES(1,'Ana','2024-01-01');\nCREATE INDEX ix_lower ON t(lower(nombre));\nCREATE INDEX ix_nombre ON t(nombre COLLATE NOCASE);\n"
            . "CREATE INDEX ix_fecha ON t(fecha DESC, id);\nCOMMIT;\n",
        'idx_pg' => "--\n-- PostgreSQL database dump\n--\nSET standard_conforming_strings = on;\nCREATE TABLE public.t (\n    id integer NOT NULL,\n    nombre text,\n    fecha date\n);\n"
            . "COPY public.t (id, nombre, fecha) FROM stdin;\n1\tAna\t2024-01-01\n\\.\n"
            . "CREATE INDEX ix_lower ON public.t USING btree (lower((nombre)::text));\nCREATE INDEX ix_nombre ON public.t USING btree (nombre varchar_pattern_ops);\n"
            . "CREATE INDEX ix_fecha ON public.t USING btree (fecha DESC NULLS LAST, id);\nCREATE UNIQUE INDEX ux_lower ON public.t USING btree (lower(nombre));\n",
        'idx_mysql' => "-- MySQL dump 10.13\nCREATE TABLE `t` (\n  `id` int NOT NULL,\n  `nombre` varchar(50) DEFAULT NULL,\n  `fecha` date DEFAULT NULL,\n  PRIMARY KEY (`id`),\n"
            . "  UNIQUE KEY `ux_lower` ((lower(`nombre`))),\n  KEY `ix_lower` ((lower(`nombre`))),\n  KEY `ix_nombre` (`nombre`(10)),\n  KEY `ix_fecha` (`fecha` DESC,`id`)\n"
            . ") ENGINE=InnoDB;\nINSERT INTO `t` VALUES (1,'Ana','2024-01-01');\n",
    ];
    $mal = [];
    foreach ($volcados as $base => $texto) {
        $r = importarTexto($base, $texto);
        $idx = [];
        foreach (Api::sql($base, 'SHOW INDEXES') as $i) {
            if (str_starts_with((string)$i['indice'], 'ix_')) { $idx[(string)$i['indice']] = (string)$i['columnas']; }
        }
        ksort($idx);
        if ($idx !== ['ix_fecha' => 'fecha,id', 'ix_nombre' => 'nombre'] || !str_contains($r, 'Índices sobre expresiones') || Api::sql($base, 'SELECT COUNT(*) AS n FROM t') !== [['n' => 1]]) {
            $mal[] = "$base: " . json_encode([$idx, $r], JSON_UNESCAPED_UNICODE);
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('fechas con zona horaria en un INSERT: datetimeoffset de Management Studio (CAST) y de sqlserver-to-jsonsqldb.ps1, timestamptz de pg_dump --inserts, se importan sin la zona; un texto con esa forma en una columna de texto no se toca (2.9)', function () {
    $mal = [];
    $casos = [
        'tz_ssms' => ["SET ANSI_NULLS ON\nGO\nCREATE TABLE [dbo].[ev](\n\t[id] [int] NOT NULL,\n\t[cuando] [datetimeoffset](7) NULL,\n\t[nota] [nvarchar](50) NULL,\n"
            . " CONSTRAINT [PK_ev] PRIMARY KEY CLUSTERED ([id] ASC)\n) ON [PRIMARY]\nGO\n"
            . "INSERT [dbo].[ev] ([id], [cuando], [nota]) VALUES (1, CAST(N'2024-01-01T10:00:00.0000000+02:00' AS DateTimeOffset), N'2024-01-01 10:00:00+02:00')\nGO\n",
            [['id' => 1, 'cuando' => '2024-01-01 10:00:00.000', 'nota' => '2024-01-01 10:00:00+02:00']], 1],
        'tz_ps1' => ["-- jsonSQLDB: SQL Server database x, dumped with sqlserver-to-jsonsqldb.ps1\nSET NOCOUNT ON;\nGO\nCREATE TABLE [ev] (\n    [id] int NOT NULL,\n"
            . "    [cuando] datetimeoffset(7) NULL,\n    [nota] nvarchar(50) NULL,\n    CONSTRAINT [PK_ev] PRIMARY KEY ([id])\n);\nGO\n"
            . "INSERT INTO [ev] ([id], [cuando], [nota]) VALUES (1, N'2024-01-01 10:00:00.0000000 +02:00', N'2024-01-01 10:00:00 +02:00');\nGO\n",
            [['id' => 1, 'cuando' => '2024-01-01 10:00:00.000', 'nota' => '2024-01-01 10:00:00 +02:00']], 1],
        'tz_pg' => ["--\n-- PostgreSQL database dump\n--\nSET standard_conforming_strings = on;\nCREATE TABLE public.ev (id integer NOT NULL, cuando timestamp with time zone, nota text);\n"
            . "INSERT INTO public.ev VALUES (1, '2024-01-01 10:00:00+02', '2024-01-01 10:00:00+02');\nINSERT INTO public.ev VALUES (2, '2024-01-01 10:00:00.5-03:30', NULL);\n",
            [['id' => 1, 'cuando' => '2024-01-01 10:00:00', 'nota' => '2024-01-01 10:00:00+02'], ['id' => 2, 'cuando' => '2024-01-01 10:00:00.500', 'nota' => null]], 2],
    ];
    foreach ($casos as $base => [$texto, $esperado, $veces]) {
        $r = importarTexto($base, $texto);
        $filas = Api::sql($base, 'SELECT * FROM ev ORDER BY id');
        // Contada una vez por fecha: no también en la primera pasada
        $aviso = '/se ha quitado la zona; la hora queda como venía en el volcado' . ($veces > 1 ? " \\($veces veces\\)" : '') . '[;.]/';
        if ($filas !== $esperado || !preg_match($aviso, $r)) {
            $mal[] = "$base: " . json_encode([$filas, $r], JSON_UNESCAPED_UNICODE);
        }
    }
    // Solo los datos, en una tabla que ya existe: la zona la quita el CAST
    Database::crear('tz_datos');
    (new Database('tz_datos'))->consultar('CREATE TABLE ev (id INTEGER PRIMARY KEY, cuando DATETIME)');
    $f = JSONSQLDB_DATA_PATH . '/tz_datos.sql';
    file_put_contents($f, "INSERT [dbo].[ev] ([id], [cuando]) VALUES (1, CAST(N'2024-01-01T10:00:00.0000000+02:00' AS DateTimeOffset))\nGO\n");
    Importar::sql($f, 'tz_datos', 'sqlserver');
    @unlink($f);
    $datos = Api::sql('tz_datos', 'SELECT cuando FROM ev');
    return $mal === [] && $datos === [['cuando' => '2024-01-01 10:00:00.000']] ?: implode(' | ', $mal) . json_encode($datos);
});
chk('CHAR(n) como tipo (code char(10), CAST(x AS CHAR(10))) se queda como tipo; como función (char(10) de SQLite, NCHAR(0) del volcado para SQL Server) sigue dando su carácter (2.9)', function () {
    $mal = [];
    foreach (['mysql' => "CREATE TABLE `t` (`id` int NOT NULL, `code` char(10) NOT NULL DEFAULT '', `n` nchar(13), PRIMARY KEY (`id`)) ENGINE=InnoDB;",
              'sqlite' => 'CREATE TABLE t (id INTEGER PRIMARY KEY, code CHAR(10) NOT NULL, n NCHAR(13));'] as $d => $sql) {
        $tr = new Traductor($d);
        $t = $tr->traducir($sql)[0] ?? '';
        if (!str_contains($t, '"code" VARCHAR(10) NOT NULL') || !str_contains($t, '"n" VARCHAR(13)') || preg_grep('/Tipo .*importado como TEXT/', $tr->avisos())) {
            $mal[] = "$d: $t " . implode('; ', $tr->avisos());
        }
    }
    $v = (new Traductor('mysql'))->traducir('CREATE VIEW v AS select cast(`t`.`a` as char(10) charset utf8mb4) AS `x` from `t`')[0] ?? '';
    $lite = (new Traductor('sqlite'))->traducir("INSERT INTO t VALUES(1,'a'||char(10)||'b',replace('x\\ny','\\n',char(13,10)));")[0] ?? '';
    $ss = (new Traductor('sqlserver'))->traducir("INSERT [dbo].[t] ([id], [x]) VALUES (1, N'antes' + NCHAR(0) + N'después')")[0] ?? '';
    return $mal === [] && str_contains($v, 'CAST("t" . "a" AS TEXT)') && str_contains($lite, "'a' || '\n' || 'b'") && str_contains($lite, "'\r\n')")
        && str_contains($ss, "(1, 'antes\0después')") ?: json_encode([$mal, $v, $lite, $ss], JSON_UNESCAPED_UNICODE);
});
chk('dos INSERT seguidos con ON CONFLICT, RETURNING o el ON DUPLICATE KEY UPDATE de cada fila de phpMyAdmin no se juntan en uno; los de una fila sin nada detrás, sí, también con paréntesis dentro (2.9)', function () {
    $r1 = importarTexto('juntar', "CREATE TABLE u (id INTEGER PRIMARY KEY, a TEXT);\n"
        . "INSERT INTO u (id, a) VALUES (1, 'x') ON CONFLICT DO NOTHING;\nINSERT INTO u (id, a) VALUES (1, 'y') ON CONFLICT DO NOTHING;\n"
        . "INSERT INTO u (id, a) VALUES (2, 'b') RETURNING (id);\nINSERT INTO u (id, a) VALUES (3, 'c') RETURNING (id);\n"
        . "INSERT INTO u (id, a) VALUES (4, 'Smith (Jr)');\nINSERT INTO u (id, a) VALUES (5, lower('D'));\nINSERT INTO u (id, a) VALUES (6, 'e');\n", 'jsonsqldb');
    $r2 = importarTexto('juntar_my', "-- MySQL dump 10.13\nCREATE TABLE `u` (`id` int NOT NULL, `a` varchar(10), PRIMARY KEY (`id`));\n"
        . "INSERT INTO `u` (`id`, `a`) VALUES (1, 'x') ON DUPLICATE KEY UPDATE `a`=VALUES(`a`);\nINSERT INTO `u` (`id`, `a`) VALUES (1, 'y') ON DUPLICATE KEY UPDATE `a`=VALUES(`a`);\n"
        . "INSERT INTO `u` (`id`, `a`) VALUES (2, 'z') ON DUPLICATE KEY UPDATE `a`=VALUES(`a`);\n");
    $a = array_column(Api::sql('juntar', 'SELECT a FROM u ORDER BY id'), 'a');
    $b = array_column(Api::sql('juntar_my', 'SELECT a FROM u ORDER BY id'), 'a');
    return $a === ['x', 'b', 'c', 'Smith (Jr)', 'd', 'e'] && $b === ['y', 'z'] ?: json_encode([$a, $b, $r1, $r2], JSON_UNESCAPED_UNICODE);
});
chk('CSV: lo que empieza por apóstrofos y sigue con una fórmula (\'-5, \'=SUM(A1), \' y un tabulador) se exporta con otro apóstrofo, y todo vuelve igual al importarlo (2.9: perdía el suyo)', function () {
    Database::crear('csvform');
    $bd = new Database('csvform');
    $bd->consultar('CREATE TABLE p (id INTEGER, t TEXT)');
    $textos = ["'-5", "'=SUM(A1)", "'\tx", "''+1", '-5', '=SUM(A1)', '@x', "\tx", "'normal", "''", "it's"];
    $filas = [];
    foreach ($textos as $i => $t) { $filas[] = ['id' => $i + 1, 't' => $t]; }
    $f = JSONSQLDB_DATA_PATH . '/formulas.csv';
    file_put_contents($f, Exportar::textoCsv($filas));
    Importar::csv($f, 'csvform', 'p');
    $vuelta = array_column($bd->consultar('SELECT t FROM p ORDER BY id'), 't');
    $csv = (string)file_get_contents($f);
    @unlink($f);
    return $vuelta === $textos && str_contains($csv, "''-5") ?: json_encode([$vuelta, $csv], JSON_UNESCAPED_UNICODE);
});
chk('MySQL: el valor por defecto de un texto que sale MEDIUMTEXT o LONGTEXT va entre paréntesis, como el de TEXT (2.9: MySQL 8 lo rechazaba, error 1101)', function () {
    Database::crear('mtexto');
    $bd = new Database('mtexto');
    $bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, nota TEXT DEFAULT 'x', corta TEXT DEFAULT 'y')");
    $bd->consultar('INSERT INTO t (id, nota) VALUES (1, ?)', [str_repeat('a', 70000)]);
    $tablas = [['tabla' => 't', 'columnas' => $bd->consultar('SHOW SCHEMA t'), 'claves' => [], 'filas' => $bd->consultar('SELECT * FROM t')]];
    $sql = Exportar::volcado('mtexto', $tablas, [], [], [], 'mysql');
    return preg_match("/`nota` MEDIUMTEXT[^,]* DEFAULT \\('x'\\)/", $sql) && preg_match("/`corta` TEXT[^,]* DEFAULT \\('y'\\)/", $sql)
        ?: (string)preg_replace('/a{100,}/', 'a…', $sql);
});
chk('lo que escriben los volcados del panel vuelve a entrar: un NUL de SQL Server (N\'a\' + NCHAR(0) + N\'b\') y el INTERVAL +2 HOUR de una vista para MySQL (2.9)', function () use ($raiz) {
    Database::crear('nulss', $raiz);
    $bd = new Database('nulss', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT, f DATETIME)');
    $bd->consultar('INSERT INTO t VALUES (1, ?, ?)', ["antes\0después", '2024-01-01 10:00:00']);
    $bd->consultar("CREATE VIEW v AS SELECT id, DATETIME(f, '+2 hours') AS luego, DATETIME(f, '-3 hours') AS antes FROM t");
    $tablas = [['tabla' => 't', 'columnas' => $bd->consultar('SHOW SCHEMA t'), 'claves' => [], 'filas' => $bd->consultar('SELECT * FROM t')]];
    $vistas = $bd->consultar('SHOW VIEWS');
    $mal = [];
    foreach (['sqlserver' => 'SQL Server', 'mysql' => 'MySQL'] as $d => $nombre) {
        $r = importarTexto("nul_$d", Exportar::volcado('nulss', $tablas, [], $vistas, [], $d));
        $x = Api::sql("nul_$d", 'SELECT x FROM t')[0]['x'] ?? null;
        $v = str_contains($r, 'sin importar') ? $r : Api::sql("nul_$d", 'SELECT luego, antes FROM v');
        if ($x !== "antes\0después" || $v !== [['luego' => '2024-01-01 12:00:00', 'antes' => '2024-01-01 07:00:00']]) {
            $mal[] = "$nombre: " . json_encode([$x, $v, $r], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('Excel: una fecha y hora a medianoche vuelve con su hora, por el formato de la celda; una fecha sin hora, sin ella (2.9: 2024-01-01 00:00:00 volvía 2024-01-01)', function () {
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) { return null; }
    require_once dirname(__DIR__) . '/jsonsqldbadmin/lib/EscritorXlsx.php';
    require_once dirname(__DIR__) . '/jsonsqldbadmin/lib/LectorXlsx.php';
    $f = JSONSQLDB_DATA_PATH . '/medianoche.xlsx';
    $libro = new EscritorXlsx(['id', 'f', 'g'], ['f' => 'DATETIME', 'g' => 'DATETIME']);
    $libro->fila([1, '2024-01-01 00:00:00', '2024-01-01']);
    $libro->fila([2, '1960-06-01 00:00:00', '2024-02-29 12:30:00']);
    $libro->guardar($f);
    $hoja = new LectorXlsx($f);
    $filas = iterator_to_array($hoja->filas());
    $hoja->cerrar();
    @unlink($f);
    return array_values($filas) === [['id', 'f', 'g'], [1, '2024-01-01 00:00:00', '2024-01-01'], [2, '1960-06-01 00:00:00', '2024-02-29 12:30:00']]
        ?: json_encode($filas);
});
chk('nombres y textos del resumen: un nombre de 64 caracteres que empieza por una cifra se queda en 64, y «(n veces)» y el error de campos de un CSV van traducidos (2.9)', function () {
    $largo = (new Traductor('mysql'))->nombreObjeto('1' . str_repeat('a', 63));
    $tr = new Traductor('mysql');
    Idioma::elegir('en');
    try {
        $tr->avisar('x');
        $tr->avisar('x');
        $veces = $tr->avisos();
        Database::crear('csvcampos');
        (new Database('csvcampos'))->consultar('CREATE TABLE p (a INTEGER, b TEXT)');
        $f = JSONSQLDB_DATA_PATH . '/campos.csv';
        file_put_contents($f, "a,b\n1,x\n2,y,z\n");
        try {
            Importar::csv($f, 'csvcampos', 'p');
            $error = 'pasó';
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        @unlink($f);
    } finally {
        Idioma::elegir('es');
    }
    return strlen($largo) === 66 && str_starts_with($largo, '"_1a') && $veces === ['x (2 times)'] && str_contains($error, 'has 3 field(s) and the header 2')
        ?: json_encode([$largo, $veces, $error]);
});
chk('exportar a Excel: si PHP se para a mitad (el tiempo), no quedan ni el fichero ni las hojas temporales (2.9)', function () {
    if (!class_exists('ZipArchive')) { return null; }
    $tmp = JSONSQLDB_DATA_PATH . '/tmp_excel';
    @mkdir($tmp, 0775, true);
    $lib = dirname(__DIR__) . '/jsonsqldbadmin/';
    $codigo = 'define("ADMIN_CONEXION", "directa"); require ' . var_export($lib . 'config.dist.php', true) . ';'
        . ' require ' . var_export($lib . 'lib/util.php', true) . ';'
        . ' foreach (["Idioma", "Exportar", "EscritorXlsx"] as $c) { require ' . var_export($lib . 'lib/', true) . ' . "$c.php"; }'
        . ' Idioma::elegir("es"); set_time_limit(1);'
        . ' $filas = (static function () { for ($i = 0; ; $i++) { yield ["id" => $i]; } })();'
        . ' Exportar::xlsx($filas, "fatal");';
    $salida = (string)shell_exec('TMPDIR=' . escapeshellarg($tmp) . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($codigo) . ' 2>&1');
    $quedan = array_values(array_diff((array)scandir($tmp), ['.', '..']));
    return str_contains($salida, 'Maximum execution time') && $quedan === [] ?: json_encode([substr($salida, 0, 300), $quedan]);
});
chk('SQL Server: una columna rowversion (TIMESTAMP) sin valores en los INSERT, como la escriben Management Studio y sqlserver-to-jsonsqldb.ps1, y un CREATE OR ALTER TRIGGER sin BEGIN se importan (2.9)', function () {
    $r = importarTexto('rowversion', "SET ANSI_NULLS ON\nGO\nCREATE TABLE [dbo].[t](\n\t[id] [int] NOT NULL,\n\t[n] [int] NULL,\n\t[rv] [timestamp] NOT NULL,\n"
        . " CONSTRAINT [PK_t] PRIMARY KEY CLUSTERED ([id] ASC)\n) ON [PRIMARY]\nGO\nCREATE TABLE [dbo].[r](\n\t[id] [int] NULL\n) ON [PRIMARY]\nGO\n"
        . "INSERT [dbo].[t] ([id], [n]) VALUES (1, 5)\nGO\n"
        . "CREATE OR ALTER TRIGGER [dbo].[trg] ON [dbo].[t] AFTER INSERT AS\nINSERT INTO r (id) SELECT id FROM inserted\nGO\n");
    Api::sql('rowversion', 'INSERT INTO t (id, n) VALUES (2, 6)');
    return Api::sql('rowversion', 'SELECT id, rv FROM t ORDER BY id') === [['id' => 1, 'rv' => null], ['id' => 2, 'rv' => null]]
        && Api::sql('rowversion', 'SELECT id FROM r') === [['id' => 2]] && !str_contains($r, 'sin importar') ?: $r;
});

unset($bd);
borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
