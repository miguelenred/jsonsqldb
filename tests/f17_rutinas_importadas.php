<?php
declare(strict_types=1);

/**
 * Vistas y triggers importados de otros motores. Ejecutar:
 *   php tests/f17_rutinas_importadas.php
 *
 * Con un servidor MySQL / MariaDB (JSONSQLDB_TEST_MYSQL, como en
 * tests/f14_volcados.php), se crea en él tests/volcados/rutinas_mysql.sql
 * —tablas, vistas y triggers escritos como se escriben en MySQL: IF(),
 * CONCAT_WS, DATE_ADD … INTERVAL, DATE_FORMAT, LIMIT a, b, GROUP_CONCAT …
 * SEPARATOR, SIGNAL, SET NEW, INSERT … SET—, se vuelca con mysqldump y se
 * importa aquí. Después se hacen las mismas escrituras allí y aquí, también
 * las que un trigger rechaza: tablas y vistas tienen que quedar iguales.
 *
 * https://miguelenred.es/jsonsqldb
 */
use JsonSQLDB\Database;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f17_' . getmypid();
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_DATA_PATH', $raiz);
define('ADMIN_CONEXION', 'directa');
require dirname(__DIR__) . '/engine/bootstrap.php';
require dirname(__DIR__) . '/jsonsqldbadmin/config.dist.php';
require dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php';
foreach (['Idioma', 'Api', 'Traductor', 'Importar', 'Exportar'] as $clase) {
    require dirname(__DIR__) . "/jsonsqldbadmin/lib/$clase.php";
}
Idioma::elegir('es');

$ok = 0; $ko = 0;
function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
    } catch (Throwable $e) {
        $r = get_class($e) . ': ' . $e->getMessage();
    }
    if ($r === null) { echo "  --   $titulo (sin servidor)\n"; return; }
    if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
    else { $ko++; echo "  FALLO $titulo -> " . (is_string($r) ? $r : var_export($r, true)) . "\n"; }
}
function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
}
/** Ejecuta SQL de MySQL como su cliente: entendiendo DELIMITER. */
function cargarMysql(mysqli $m, string $sql): ?string {
    $delim = ';';
    $actual = '';
    foreach (preg_split('/\R/', $sql) as $linea) {
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/', $linea, $d)) { $delim = $d[1]; continue; }
        if ($actual === '' && preg_match('/^\s*(--.*)?$/', $linea)) { continue; }
        $actual .= $linea . "\n";
        if (str_ends_with(rtrim($linea), $delim)) {
            $s = substr(rtrim($actual), 0, -strlen($delim));
            if (!$m->query($s)) { return $m->error . ' en: ' . substr($s, 0, 200); }
            $actual = '';
        }
    }
    return null;
}
/** Valores comparables: números a 4 decimales, fechas sin milisegundos, nombres en minúsculas. */
function comparable(array $filas, bool $ordenar): array {
    $o = [];
    foreach ($filas as $f) {
        $x = [];
        foreach ($f as $c => $v) {
            if (is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2})( \d{2}:\d{2}:\d{2})?(\.\d+)?$/', $v, $m)) {
                $v = $m[1] . ($m[2] ?? '');
            } elseif (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v))) {
                $v = round((float)$v, 4);
            }
            $x[strtolower((string)$c)] = $v;
        }
        $o[] = json_encode($x, JSON_UNESCAPED_UNICODE);
    }
    if ($ordenar) { sort($o); }
    return $o;
}
/**
 * Las diferencias entre lo de allí y lo de aquí.
 *
 * @param list<string> $nombres tablas y vistas
 * @param list<string> $conOrden las que tienen ORDER BY … LIMIT
 */
function diferencias(array $nombres, array $conOrden, callable $alli, callable $aqui): array {
    $mal = [];
    foreach ($nombres as $n) {
        $a = comparable($alli($n), !in_array($n, $conOrden, true));
        $b = comparable($aqui($n), !in_array($n, $conOrden, true));
        if ($a !== $b) {
            $mal[] = "$n:\n      allí: " . implode(' ', array_slice($a, 0, 4)) . "\n      aquí: " . implode(' ', array_slice($b, 0, 4));
        }
    }
    return $mal;
}

/** Tablas y vistas de una base de aquí, comparables. */
function estado(Database $bd, array $nombres, array $conOrden): array {
    $r = [];
    foreach ($nombres as $n) {
        $r[$n] = comparable($bd->consultar("SELECT * FROM \"$n\""), !in_array($n, $conOrden, true));
    }
    return $r;
}
$nombresComunes = ['clientes', 'pedidos', 'registro', 'v_resumen', 'v_textos', 'v_fechas', 'v_calc', 'v_top', 'v_grupos', 'v_de_vista'];
$esperado = __DIR__ . '/volcados/rutinas.esperado.json';

borrarArbol($raiz);
mkdir($raiz, 0775, true);

// ---------------------------------------------------------------------
echo "\n== MySQL / MariaDB ==\n";
$conexion = (string)getenv('JSONSQLDB_TEST_MYSQL');
$m = null;
if ($conexion !== '' && class_exists('mysqli')) {
    [$host, $usuario, $clave] = array_pad(explode(':', $conexion, 3), 3, '');
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli($host, $usuario, $clave);
    if ($m->connect_errno) { $m = null; }
}
$bdMysql = 'jsonsqldb_f17_' . getmypid();
$volcado = $raiz . '/rutinas.mysql.sql';
chk('se crea la base de origen y se vuelca con mysqldump', function () use ($m, $bdMysql, $volcado, $conexion) {
    if ($m === null) { return getenv('JSONSQLDB_TEST_MYSQL_EXIGIR') ? 'no hay servidor MySQL y se exige' : null; }
    $m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
    $m->query("DROP DATABASE IF EXISTS `$bdMysql`");
    $m->query("CREATE DATABASE `$bdMysql` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
    $m->select_db($bdMysql);
    $error = cargarMysql($m, (string)file_get_contents(__DIR__ . '/volcados/rutinas_mysql.sql'));
    if ($error !== null) { return $error; }
    [$host, $usuario, $clave] = array_pad(explode(':', $conexion, 3), 3, '');
    $orden = 'mysqldump --skip-comments --default-character-set=utf8mb4 -h ' . escapeshellarg($host) . ' -u ' . escapeshellarg($usuario)
           . ' ' . escapeshellarg('-p' . $clave) . ' ' . escapeshellarg($bdMysql) . ' > ' . escapeshellarg($volcado)
           . ' 2> ' . escapeshellarg("$volcado.err");
    // Los avisos, aparte: el mysqldump de MySQL 8 avisa de la contraseña en la
    // línea de órdenes, y mezclado con el volcado lo estropeaba
    exec($orden, $nada, $rc);
    return $rc === 0 && filesize($volcado) > 1000 ?: 'mysqldump: ' . (string)@file_get_contents("$volcado.err");
});
$resumen = '';
chk('el volcado se importa con todas sus vistas y sus triggers, sin saltarse ninguno', function () use ($m, $volcado, &$resumen) {
    if ($m === null) { return null; }
    Database::crear('desde_mysql');
    $resumen = Importar::sql($volcado, 'desde_mysql');
    $bd = new Database('desde_mysql');
    $vistas = array_column($bd->consultar('SHOW VIEWS'), 'vista');
    $triggers = array_column($bd->consultar('SHOW TRIGGERS'), 'nombre');
    sort($vistas);
    sort($triggers);
    return $vistas === ['v_bool', 'v_calc', 'v_de_vista', 'v_fechas', 'v_grupos', 'v_resumen', 'v_sin', 'v_textos', 'v_top']
        && $triggers === ['clientes_bi', 'clientes_bu', 'pedidos_ad', 'pedidos_ai', 'pedidos_au', 'pedidos_bd', 'pedidos_bi']
        && !str_contains($resumen, 'sin importar') ?: "vistas: " . implode(',', $vistas) . " · triggers: " . implode(',', $triggers) . " · $resumen";
});
$escrituras = [
    "INSERT INTO clientes (nombre, email, saldo, alta) VALUES ('Zoe', '  ZOE@Mail.COM', 900, '2026-04-01 12:00:00')",
    "INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (4, 30, NULL, '2026-04-02 10:00:00')",
    "INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (4, 2000, NULL, NULL)",
    "INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (3, -5, NULL, NULL)",
    "UPDATE pedidos SET total = 90 WHERE id = 1",
    "UPDATE pedidos SET estado = 'cerrado' WHERE id = 2",
    "DELETE FROM pedidos WHERE id = 2",
    "DELETE FROM pedidos WHERE id = 3",
    "UPDATE clientes SET saldo = -5000 WHERE id = 1",
    "UPDATE clientes SET saldo = saldo + 1 WHERE id = 3",
];
chk('las mismas escrituras dejan las mismas tablas y vistas allí y aquí, y fallan las mismas', function () use ($m, $escrituras) {
    if ($m === null) { return null; }
    $bd = new Database('desde_mysql');
    $mal = [];
    foreach ($escrituras as $sql) {
        $falloAlli = !$m->query($sql);
        try { $bd->consultar($sql); $falloAqui = false; } catch (Throwable $e) { $falloAqui = $e->getMessage(); }
        if ($falloAlli !== ($falloAqui !== false)) {
            $mal[] = "«{$sql}» " . ($falloAlli ? 'falla allí (' . $m->error . ') y aquí no' : "falla aquí ($falloAqui) y allí no");
        }
    }
    $nombres = $GLOBALS['nombresComunes'];
    // El resultado, ya comprobado contra MySQL, es el que tiene que dar el
    // script de SQL Server (que aquí no se puede ejecutar): se guarda con
    // JSONSQLDB_F17_GUARDAR=1
    if (getenv('JSONSQLDB_F17_GUARDAR')) {
        file_put_contents($GLOBALS['esperado'], json_encode(estado($bd, $nombres, ['v_top']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }
    $mal = array_merge($mal, diferencias(array_merge($nombres, ['v_bool', 'v_sin']), ['v_top'],
        static function (string $n) use ($m) {
            $r = $m->query("SELECT * FROM `$n`");
            return $r ? $r->fetch_all(MYSQLI_ASSOC) : [['error' => $m->error]];
        },
        static fn(string $n) => $bd->consultar("SELECT * FROM \"$n\"")));
    return $mal === [] ?: "\n    " . implode("\n    ", $mal);
});
if ($m !== null) { $m->query("DROP DATABASE IF EXISTS `$bdMysql`"); }

// ---------------------------------------------------------------------
echo "\n== PostgreSQL ==\n";
$pgConexion = (string)getenv('JSONSQLDB_TEST_POSTGRESQL');
$pg = null;
$admin = null;
$bdPg = 'jsonsqldb_f17_' . getmypid();
if ($pgConexion !== '' && function_exists('pg_connect')) {
    [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
    $admin = @pg_connect("host=$host user=$usuario password=$clave dbname=postgres");
    if ($admin) {
        @pg_query($admin, "DROP DATABASE IF EXISTS $bdPg");
        pg_query($admin, "CREATE DATABASE $bdPg");
        $pg = pg_connect("host=$host user=$usuario password=$clave dbname=$bdPg");
    }
}
$volcadoPg = $raiz . '/rutinas.pg.sql';
chk('se crea la base de origen y se vuelca con pg_dump', function () use ($pg, $pgConexion, $bdPg, $volcadoPg) {
    if ($pg === null) { return getenv('JSONSQLDB_TEST_POSTGRESQL_EXIGIR') ? 'no hay servidor PostgreSQL y se exige' : null; }
    if (@pg_query($pg, (string)file_get_contents(__DIR__ . '/volcados/rutinas_postgresql.sql')) === false) {
        return pg_last_error($pg);
    }
    [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
    exec('PGPASSWORD=' . escapeshellarg($clave) . ' pg_dump --no-owner -h ' . escapeshellarg($host) . ' -U ' . escapeshellarg($usuario)
        . ' ' . escapeshellarg($bdPg) . ' > ' . escapeshellarg($volcadoPg) . ' 2> ' . escapeshellarg("$volcadoPg.err"), $nada, $rc);
    return $rc === 0 && filesize($volcadoPg) > 1000 ?: 'pg_dump: ' . (string)@file_get_contents("$volcadoPg.err");
});
chk('el volcado se importa con todas sus vistas y sus triggers (uno por evento), sin saltarse ninguno', function () use ($pg, $volcadoPg) {
    if ($pg === null) { return null; }
    Database::crear('desde_pg');
    $resumen = Importar::sql($volcadoPg, 'desde_pg');
    $bd = new Database('desde_pg');
    $vistas = array_column($bd->consultar('SHOW VIEWS'), 'vista');
    $triggers = array_column($bd->consultar('SHOW TRIGGERS'), 'nombre');
    sort($vistas);
    sort($triggers);
    return $vistas === ['v_calc', 'v_de_vista', 'v_fechas', 'v_filtro', 'v_grupos', 'v_in', 'v_resumen', 'v_textos']
        && $triggers === ['clientes_normalizar_insert', 'clientes_normalizar_update', 'pedidos_bd', 'pedidos_bi',
                          'pedidos_cambios_delete', 'pedidos_cambios_insert', 'pedidos_cambios_update', 'pedidos_estado']
        && !str_contains($resumen, 'sin importar') ?: "vistas: " . implode(',', $vistas) . " · triggers: " . implode(',', $triggers) . " · $resumen";
});
chk('las mismas escrituras dejan las mismas tablas y vistas allí y aquí, y fallan las mismas', function () use ($pg, $escrituras) {
    if ($pg === null) { return null; }
    $bd = new Database('desde_pg');
    $mal = [];
    foreach ($escrituras as $sql) {
        $falloAlli = @pg_query($pg, $sql) === false;
        try { $bd->consultar($sql); $falloAqui = false; } catch (Throwable $e) { $falloAqui = $e->getMessage(); }
        if ($falloAlli !== ($falloAqui !== false)) {
            $mal[] = "«{$sql}» " . ($falloAlli ? 'falla allí (' . pg_last_error($pg) . ') y aquí no' : "falla aquí ($falloAqui) y allí no");
        }
    }
    $nombres = ['clientes', 'pedidos', 'registro', 'v_resumen', 'v_textos', 'v_fechas', 'v_calc', 'v_filtro', 'v_grupos', 'v_in', 'v_de_vista'];
    $mal = array_merge($mal, diferencias($nombres, ['v_filtro'],
        static function (string $n) use ($pg) {
            $r = @pg_query($pg, "SELECT * FROM \"$n\"");
            return $r ? (pg_fetch_all($r) ?: []) : [['error' => pg_last_error($pg)]];
        },
        static fn(string $n) => $bd->consultar("SELECT * FROM \"$n\"")));
    return $mal === [] ?: "\n    " . implode("\n    ", $mal);
});
if ($pg !== null) {
    pg_close($pg);
    @pg_query($admin, "DROP DATABASE IF EXISTS $bdPg");
}

// ---------------------------------------------------------------------
echo "\n== SQL Server (sin servidor: contra lo que da la misma base de MySQL) ==\n";
chk('el script de Management Studio se importa con sus vistas y sus triggers, sin saltarse ninguno', function () {
    Database::crear('desde_ss');
    $resumen = Importar::sql(__DIR__ . '/volcados/rutinas_sqlserver.sql', 'desde_ss');
    $bd = new Database('desde_ss');
    $vistas = array_column($bd->consultar('SHOW VIEWS'), 'vista');
    $triggers = array_column($bd->consultar('SHOW TRIGGERS'), 'nombre');
    sort($vistas);
    sort($triggers);
    // Los que actualizan su propia fila pasan a un BEFORE (_antes): en SQL
    // Server un trigger no se vuelve a disparar a sí mismo, aquí sí
    return $vistas === ['v_calc', 'v_de_vista', 'v_fechas', 'v_grupos', 'v_resumen', 'v_textos', 'v_top']
        && $triggers === ['clientes_normalizar_insert', 'clientes_normalizar_insert_antes', 'clientes_normalizar_update',
                          'clientes_normalizar_update_antes', 'pedidos_alta', 'pedidos_alta_antes', 'pedidos_baja', 'pedidos_cambios']
        && !str_contains($resumen, 'sin importar') ?: "vistas: " . implode(',', $vistas) . " · triggers: " . implode(',', $triggers) . " · $resumen";
});
chk('las mismas escrituras dejan las mismas tablas y vistas que en MySQL, y fallan las mismas', function () use ($escrituras, $nombresComunes, $esperado) {
    $bd = new Database('desde_ss');
    $fallan = [];
    foreach ($escrituras as $i => $sql) {
        try { $bd->consultar($sql); } catch (Throwable $e) { $fallan[] = $i; }
    }
    $mal = [];
    if ($fallan !== [3, 6, 8]) {
        $mal[] = 'fallan las escrituras ' . implode(', ', $fallan) . ' y tenían que fallar la 3, la 6 y la 8';
    }
    $aqui = estado($bd, $nombresComunes, ['v_top']);
    foreach (json_decode((string)file_get_contents($esperado), true) as $n => $filas) {
        if (($aqui[$n] ?? null) !== $filas) {
            $mal[] = "$n:\n      esperado: " . implode(' ', array_slice($filas, 0, 4)) . "\n      aquí:     " . implode(' ', array_slice($aqui[$n] ?? [], 0, 4));
        }
    }
    return $mal === [] ?: "\n    " . implode("\n    ", $mal);
});

chk('las 25 formas de vista van al volcado de SQL Server y vuelven dando lo mismo', function () use ($raiz) {
    [$tablasF, $formas] = require __DIR__ . '/volcados/formas_de_vista.php';
    Database::crear('formas_sqlserver');
    $bd = new Database('formas_sqlserver');
    foreach ($tablasF as $q) { $bd->consultar($q); }
    foreach ($formas as $i => $sql) { $bd->consultar("CREATE VIEW f$i AS $sql"); }
    $meta = [];
    foreach (['a', 'b'] as $t) {
        $meta[] = ['tabla' => $t, 'columnas' => $bd->consultar("SHOW SCHEMA $t"), 'claves' => $bd->consultar("SHOW KEYS FROM $t"), 'filas' => $bd->consultar("SELECT * FROM $t")];
    }
    $volcado = Exportar::volcado('formas', $meta, [], $bd->consultar('SHOW VIEWS'), [], 'sqlserver');
    file_put_contents("$raiz/formas.sqlserver.sql", $volcado);
    Database::crear('vuelta_sqlserver');
    Importar::sql("$raiz/formas.sqlserver.sql", 'vuelta_sqlserver');
    $vuelta = new Database('vuelta_sqlserver');
    $sinTraducir = preg_match('/^-- Sin traducir[^:]*: (.*)$/m', $volcado, $m) ? array_map('trim', explode(',', $m[1])) : [];
    $mal = [];
    $norm = static fn(array $x) => array_map(static fn($f) => json_encode(array_map(static fn($y) => is_numeric($y) ? round((float)$y, 4) : $y, array_change_key_case($f))), $x);
    foreach ($formas as $i => $sql) {
        if (in_array("f$i", $sinTraducir, true)) { continue; }      // lo que SQL Server no tiene (dicho en el volcado)
        $orden = stripos($sql, 'ORDER BY') !== false && stripos($sql, 'LIMIT') !== false;
        try { $b = $norm($vuelta->consultar("SELECT * FROM f$i")); } catch (Throwable $e) { $mal[] = "f$i: " . $e->getMessage(); continue; }
        $a = $norm($bd->consultar("SELECT * FROM f$i"));
        if (!$orden) { sort($a); sort($b); }
        if ($a !== $b) { $mal[] = "f$i distinta"; }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
// ---------------------------------------------------------------------
echo "\n== Ida y vuelta: 25 formas de vista por mysqldump y por pg_dump ==\n";
// Las vistas de tests/volcados/formas_de_vista.php se exportan, se crean en el
// servidor, se vuelcan con su herramienta (que las reescribe a su manera:
// MySQL guarda «x + interval -2 hour», PostgreSQL «OFFSET 1 LIMIT 3» y
// «~~ like_escape(…)») y se importan aquí: tienen que dar lo mismo.
[$tablasF, $formas] = require __DIR__ . '/volcados/formas_de_vista.php';
Database::crear('formas');
$bf = new Database('formas');
foreach ($tablasF as $q) { $bf->consultar($q); }
foreach ($formas as $i => $sql) { $bf->consultar("CREATE VIEW f$i AS $sql"); }
$metaF = [];
foreach (['a', 'b'] as $t) {
    $metaF[] = ['tabla' => $t, 'columnas' => $bf->consultar("SHOW SCHEMA $t"), 'claves' => $bf->consultar("SHOW KEYS FROM $t"), 'filas' => $bf->consultar("SELECT * FROM $t")];
}
$vuelta = function (string $volcado, string $base) use ($formas, $bf): string {
    Database::crear($base);
    $resumen = Importar::sql($volcado, $base);
    $bd = new Database($base);
    $mal = [];
    foreach ($formas as $i => $sql) {
        $orden = stripos($sql, 'ORDER BY') !== false && stripos($sql, 'LIMIT') !== false;
        try { $alli = $bd->consultar("SELECT * FROM f$i"); } catch (Throwable $e) { $mal[] = "f$i: " . $e->getMessage(); continue; }
        if (comparable($alli, !$orden) !== comparable($bf->consultar("SELECT * FROM f$i"), !$orden)) { $mal[] = "f$i distinta"; }
    }
    return $mal === [] ? '' : implode(' | ', $mal) . " · $resumen";
};
chk('MySQL: exportadas, volcadas con mysqldump e importadas, dan lo mismo', function () use ($m, $conexion, $metaF, $bf, $raiz, $vuelta) {
    if ($m === null) { return null; }
    $bd = 'jsonsqldb_f17f_' . getmypid();
    $m->query("DROP DATABASE IF EXISTS `$bd`");
    $m->query("CREATE DATABASE `$bd` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
    $m->select_db($bd);
    $error = cargarMysql($m, Exportar::volcado('formas', $metaF, [], $bf->consultar('SHOW VIEWS'), [], 'mysql'));
    if ($error !== null) { return $error; }
    [$host, $usuario, $clave] = array_pad(explode(':', $conexion, 3), 3, '');
    exec('mysqldump --skip-comments -h ' . escapeshellarg($host) . ' -u ' . escapeshellarg($usuario) . ' ' . escapeshellarg('-p' . $clave)
        . ' ' . escapeshellarg($bd) . ' > ' . escapeshellarg("$raiz/formas.mysql.sql") . ' 2>/dev/null');
    $m->query("DROP DATABASE IF EXISTS `$bd`");
    return $vuelta("$raiz/formas.mysql.sql", 'formas_mysql') ?: true;
});
chk('PostgreSQL: exportadas, volcadas con pg_dump e importadas, dan lo mismo', function () use ($pgConexion, $metaF, $bf, $raiz, $vuelta) {
    if ($pgConexion === '' || !function_exists('pg_connect')) { return null; }
    [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
    $admin = @pg_connect("host=$host user=$usuario password=$clave dbname=postgres");
    if (!$admin) { return null; }
    $bd = 'jsonsqldb_f17f_' . getmypid();
    @pg_query($admin, "DROP DATABASE IF EXISTS $bd");
    pg_query($admin, "CREATE DATABASE $bd");
    $c = pg_connect("host=$host user=$usuario password=$clave dbname=$bd");
    if (@pg_query($c, Exportar::volcado('formas', $metaF, [], $bf->consultar('SHOW VIEWS'), [], 'postgresql')) === false) { return pg_last_error($c); }
    pg_close($c);
    exec('PGPASSWORD=' . escapeshellarg($clave) . ' pg_dump --no-owner -h ' . escapeshellarg($host) . ' -U ' . escapeshellarg($usuario)
        . ' ' . escapeshellarg($bd) . ' > ' . escapeshellarg("$raiz/formas.pg.sql") . ' 2>/dev/null');
    @pg_query($admin, "DROP DATABASE IF EXISTS $bd");
    return $vuelta("$raiz/formas.pg.sql", 'formas_pg') ?: true;
});

borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
