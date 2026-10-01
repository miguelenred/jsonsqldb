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
chk('el resumen dice lo que no ha llegado igual', function () use ($volcados) {
    Database::crear('imp_avisos', JSONSQLDB_DATA_PATH);
    $r = Importar::sql("$volcados/mysql.sql", 'imp_avisos');
    return str_contains($r, 'ENUM') && str_contains($r, 'CHECK') && str_contains($r, 'CURRENT_TIMESTAMP') ?: $r;
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

unset($bd);
borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
