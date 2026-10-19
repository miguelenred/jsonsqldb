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
define('JSONSQLDB_LOG_PATH', JSONSQLDB_DATA_PATH . '/logs');
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
chk('una función con TG_OP para varios eventos: cada trigger lleva solo lo de su evento, y OLD o NEW donde no hay fila valen NULL, como allí (2.9)', function () {
    // Sin servidor: el volcado tal como lo da pg_dump. La rama de DELETE usa
    // OLD, la de UPDATE y el ELSE cambian NEW, y la última sentencia es de los tres
    $volcado = "--\n-- PostgreSQL database dump\n--\n"
        . "CREATE FUNCTION public.cuentas_fn() RETURNS trigger\n    LANGUAGE plpgsql\n    AS \$\$\nBEGIN\n"
        . "  IF (TG_OP = 'DELETE') THEN\n    INSERT INTO registro VALUES ('baja ' || OLD.id);\n"
        . "  ELSIF TG_OP = 'UPDATE' AND OLD.saldo <> NEW.saldo THEN\n    NEW.cambiado := 'saldo';\n"
        . "  ELSE\n    NEW.cambiado := 'no';\n  END IF;\n"
        . "  INSERT INTO registro VALUES (TG_OP || ' ' || COALESCE(NEW.id, OLD.id) || ' ' || CASE TG_OP WHEN 'DELETE' THEN OLD.saldo ELSE NEW.saldo END);\n"
        . "  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;\n  RETURN NEW;\nEND\n\$\$;\n"
        . "CREATE TABLE public.cuentas (\n    id integer NOT NULL,\n    saldo integer,\n    cambiado text\n);\n"
        . "CREATE TABLE public.registro (\n    msg text\n);\n"
        . "CREATE TRIGGER cuentas_t BEFORE INSERT OR DELETE OR UPDATE ON public.cuentas FOR EACH ROW EXECUTE FUNCTION public.cuentas_fn();\n";
    $f = JSONSQLDB_DATA_PATH . '/tg_op.pg.sql';
    file_put_contents($f, $volcado);
    Database::crear('tg_op');
    $resumen = Importar::sql($f, 'tg_op');
    @unlink($f);
    $bd = new Database('tg_op');
    foreach (['INSERT INTO cuentas (id, saldo) VALUES (1, 10), (2, 5)', 'UPDATE cuentas SET saldo = 20 WHERE id = 1',
              'UPDATE cuentas SET saldo = 5 WHERE id = 2', 'DELETE FROM cuentas WHERE id = 2'] as $sql) {
        $bd->consultar($sql);
    }
    // Lo que deja PostgreSQL 16 con las mismas escrituras
    $cuentas = $bd->consultar('SELECT * FROM cuentas');
    $registro = array_column($bd->consultar('SELECT msg FROM registro'), 'msg');
    sort($registro, SORT_STRING);
    return $cuentas === [['id' => 1, 'saldo' => 20, 'cambiado' => 'saldo']]
        && $registro === ['DELETE 2 5', 'INSERT 1 10', 'INSERT 2 5', 'UPDATE 1 20', 'UPDATE 2 5', 'baja 2']
        ?: json_encode([$cuentas, $registro]) . " · $resumen";
});

chk('cada trigger de una función con TG_OP lleva solo las ramas de su evento, sin mirar en cada fila las de los demás (2.10)', function () {
    $volcado = "--\n-- PostgreSQL database dump\n--\n"
        . "CREATE FUNCTION public.saldos_fn() RETURNS trigger\n    LANGUAGE plpgsql\n    AS \$\$\nBEGIN\n"
        . "  IF TG_OP = 'DELETE' THEN\n    INSERT INTO registro VALUES ('baja ' || OLD.id);\n    RETURN OLD;\n"
        . "  ELSIF TG_OP IN ('INSERT', 'UPDATE') AND NEW.saldo BETWEEN 1 AND 5 THEN\n    NEW.cambiado := 'poco';\n"
        . "  ELSIF (TG_OP = 'UPDATE' OR TG_OP = 'INSERT') AND NEW.saldo > 100 THEN\n    NEW.cambiado := 'mucho';\n  END IF;\n"
        . "  IF TG_OP NOT IN ('DELETE') THEN\n"
        . "    INSERT INTO registro VALUES (CASE TG_OP WHEN 'INSERT' THEN 'alta ' ELSE 'cambio ' END || NEW.id);\n  END IF;\n"
        . "  RETURN NEW;\nEND\n\$\$;\n"
        . "CREATE TABLE public.saldos (\n    id integer NOT NULL,\n    saldo integer,\n    cambiado text\n);\n"
        . "CREATE TABLE public.registro (\n    msg text\n);\n"
        . "CREATE TRIGGER saldos_t BEFORE INSERT OR UPDATE OR DELETE ON public.saldos FOR EACH ROW EXECUTE FUNCTION public.saldos_fn();\n";
    $f = JSONSQLDB_DATA_PATH . '/tg_ramas.pg.sql';
    file_put_contents($f, $volcado);
    Database::crear('tg_ramas');
    $resumen = Importar::sql($f, 'tg_ramas');
    @unlink($f);
    $bd = new Database('tg_ramas');
    $sql = array_column($bd->consultar('SHOW TRIGGERS'), 'sql', 'nombre');
    // Ni una comparación entre dos eventos, ni un CASE sobre el evento
    foreach ($sql as $nombre => $s) {
        if (preg_match("/'(INSERT|UPDATE|DELETE)' *(=|<>|IN|NOT)|CASE '/", $s)) {
            return "$nombre: $s";
        }
    }
    if (!str_contains($sql['saldos_t_update'] ?? '', 'BETWEEN 1 AND 5') || str_contains($sql['saldos_t_delete'] ?? '', 'cambiado')) {
        return json_encode($sql, JSON_UNESCAPED_UNICODE) . " · $resumen";
    }
    foreach (['INSERT INTO saldos (id, saldo) VALUES (1, 3), (2, 50), (3, 200)', 'UPDATE saldos SET saldo = 4 WHERE id = 2',
              'DELETE FROM saldos WHERE id = 3'] as $s) {
        $bd->consultar($s);
    }
    // Lo que deja PostgreSQL con las mismas escrituras
    $saldos = $bd->consultar('SELECT * FROM saldos');
    $registro = array_column($bd->consultar('SELECT msg FROM registro'), 'msg');
    sort($registro, SORT_STRING);
    return $saldos === [['id' => 1, 'saldo' => 3, 'cambiado' => 'poco'], ['id' => 2, 'saldo' => 4, 'cambiado' => 'poco']]
        && $registro === ['alta 1', 'alta 2', 'alta 3', 'baja 3', 'cambio 2']
        ?: json_encode([$saldos, $registro], JSON_UNESCAPED_UNICODE);
});

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
        try { $bd->consultar($sql); } catch (Throwable) { $fallan[] = $i; }
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

// ---------------------------------------------------------------------
echo "\n== Textos con puntuación y cuentas con fechas (2.9) ==\n";
chk('un texto \'(\', \')\', \',\' o \';\' en una vista o un trigger es un texto, no puntuación: CONCAT(a, \' (\', b, \')\'), CONCAT_WS(\',\', …), SEPARATOR \')\' y el \'log (\' || NEW.id || \')\' de un trigger de PostgreSQL (2.9)', function () {
    Database::crear('puntuacion');
    $bd = new Database('puntuacion');
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT, b TEXT, n INTEGER)');
    $bd->consultar("INSERT INTO t VALUES (1, 'x', 'y', 0), (2, 'p', 'q', 0)");
    $my = new Traductor('mysql');
    $vistas = [
        "CREATE VIEW v1 AS select concat(`t`.`a`,' (',`t`.`b`,')') AS `x` from `t`" => [['x' => 'x (y)'], ['x' => 'p (q)']],
        "CREATE VIEW v2 AS select concat_ws(',',`t`.`a`,`t`.`b`) AS `x` from `t`" => [['x' => 'x,y'], ['x' => 'p,q']],
        "CREATE VIEW v3 AS select group_concat(`t`.`a` order by `t`.`id` ASC separator ')') AS `x` from `t`" => [['x' => 'x)p']],
        "CREATE VIEW v4 AS select replace(`t`.`a`,'(',';') AS `x`,if((`t`.`b` = ';'),1,0) AS `y` from `t`" => [['x' => 'x', 'y' => 0], ['x' => 'p', 'y' => 0]],
    ];
    $mal = [];
    foreach ($vistas as $sql => $esperado) {
        foreach ($my->traducir($sql) as $s) { $bd->consultar($s); }
        preg_match('/VIEW (\w+)/', $sql, $v);
        $r = $bd->consultar("SELECT * FROM {$v[1]}");
        if ($r !== $esperado) { $mal[] = "{$v[1]}: " . json_encode($r); }
    }
    // El trigger llama a una función plpgsql con '(' y ')' en sus textos
    $pg = new Traductor('postgresql');
    $pg->traducir("CREATE FUNCTION public.marcar() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.n := length('log (' || NEW.id || ')'); RETURN NEW; END \$\$;");
    $pg->traducir('CREATE TRIGGER t_bi BEFORE INSERT ON public.t FOR EACH ROW EXECUTE FUNCTION public.marcar();');
    foreach ($pg->triggers() as [, $s]) { $bd->consultar($s); }
    $bd->consultar("INSERT INTO t (id, a) VALUES (3, 'z')");
    $n = $bd->consultar('SELECT n FROM t WHERE id = 3');
    return $mal === [] && $n === [['n' => 7]] && $my->avisos() === [] && $pg->avisos() === []
        ?: json_encode([$mal, $n, $my->avisos(), $pg->avisos()], JSON_UNESCAPED_UNICODE);
});
chk('cada lado de un operador llega hasta donde se une antes que él: & (con su signo delante), \ , Mod y Like de Access, y MOD, DIV y <=> de MySQL, también con operadores que son palabras, con un paréntesis detrás (a MOD (b)) y con las comparaciones a la izquierda de <=> pero no el = de un SET; IF(…) y CASE … END a la izquierda; << y >> de MySQL y ->> y -> de PostgreSQL, enteros (2.10: solo el operando de al lado, y [n] + 1 & [a] salía [n] + (1 & [a]))', function () {
    Database::crear('precedencia');
    $bd = new Database('precedencia');
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT, b TEXT, n INTEGER, j TEXT, d DATE)');
    $bd->consultar("INSERT INTO t VALUES (1, 'p', 'q', 7, '{\"k\":1}', '2026-01-01'), (2, NULL, 'r', 3, '{\"k\":2}', '2026-01-01')");
    $ac = new Traductor('access');
    $ac->observar('CREATE TABLE [t] ([id] LONG, [a] TEXT(50), [b] TEXT(50), [n] LONG)');
    $my = new Traductor('mysql');
    $pg = new Traductor('postgresql');
    // Lo que dan Access, MySQL y PostgreSQL
    $vistas = [
        [$ac, 'CREATE VIEW a1 AS SELECT [n] + 1 & [a] AS x FROM [t]', ['8p', '4']],
        [$ac, 'CREATE VIEW a2 AS SELECT [a] & [n] * 2 AS x FROM [t]', ['p14', '6']],
        [$ac, 'CREATE VIEW a3 AS SELECT -[n] & "x" AS x FROM [t]', ['-7x', '-3x']],
        [$ac, 'CREATE VIEW a4 AS SELECT [n] * 7 \ 2 AS x FROM [t]', [24, 10]],
        [$ac, 'CREATE VIEW a5 AS SELECT [n] * 2 Mod 4 AS x FROM [t]', [2, 2]],
        [$ac, 'CREATE VIEW a6 AS SELECT [id] AS x FROM [t] WHERE [n] + 1 Like "8*"', [1]],
        [$ac, 'CREATE VIEW a7 AS SELECT [n] Mod 4 & [a] AS x FROM [t]', ['3p', '3']],
        [$ac, 'CREATE VIEW a8 AS SELECT [a] & [n] Mod 4 AS x FROM [t]', ['p3', '3']],
        [$ac, 'CREATE VIEW a9 AS SELECT [n] Mod 4 * 3 AS x FROM [t]', [7, 3]],
        [$ac, 'CREATE VIEW a10 AS SELECT 2 * 3 Mod 4 * 5 AS x FROM [t]', [6, 6]],
        [$ac, 'CREATE VIEW a11 AS SELECT [n] Mod (3) AS x FROM [t]', [1, 0]],
        [$my, 'CREATE VIEW m1 AS select `t`.`n` * 2 MOD 4 AS `x` from `t`', [2, 2]],
        [$my, 'CREATE VIEW m2 AS select `t`.`n` MOD 4 * 2 AS `x` from `t`', [6, 6]],
        [$my, 'CREATE VIEW m3 AS select `t`.`n` * 3 DIV 2 AS `x` from `t`', [10, 4]],
        [$my, 'CREATE VIEW m4 AS select `t`.`n` + 1 <=> 8 AS `x` from `t`', [1, 0]],
        [$my, 'CREATE VIEW m5 AS select `t`.`n` <=> 3 MOD 4 AS `x` from `t`', [0, 1]],
        [$my, 'CREATE VIEW m6 AS select `t`.`n` <=> 6 DIV 2 AS `x` from `t`', [0, 1]],
        [$my, 'CREATE VIEW m7 AS select `t`.`n` <=> 1 | 2 AS `x` from `t`', [0, 1]],
        [$my, 'CREATE VIEW m8 AS select `t`.`n` = 7 <=> 1 AS `x` from `t`', [1, 0]],
        [$my, 'CREATE VIEW m9 AS select `t`.`n` MOD (3) AS `x` from `t`', [1, 0]],
        [$my, 'CREATE VIEW m10 AS select `t`.`n` << 1 AS `x` from `t`', [14, 6]],
        [$my, 'CREATE VIEW m11 AS select `t`.`n` >> 1 AS `x` from `t`', [3, 1]],
        [$pg, "CREATE VIEW p1 AS SELECT (t.j ->> 'k') AS x FROM t", [1, 2]],
        [$pg, "CREATE VIEW p2 AS SELECT (t.j -> 'k') AS x FROM t", ['1', '2']],
        [$my, 'CREATE VIEW m12 AS select `t`.`n` DIV mod(`t`.`n`, 4) AS `x` from `t`', [2, 1]],
        [$my, 'CREATE VIEW m13 AS select date_add(`t`.`d`, interval mod(`t`.`n`, 4) day) AS `x` from `t`', ['2026-01-04 00:00:00', '2026-01-04 00:00:00']],
        [$pg, 'CREATE VIEW p3 AS SELECT mod(t.n, 2) AS x FROM t', [1, 1]],
        [$my, 'CREATE VIEW m14 AS select if(`t`.`n` > 5, 7, 2) DIV 2 AS `x` from `t`', [3, 1]],
        [$my, 'CREATE VIEW m15 AS select case when `t`.`n` > 5 then 7 else 2 end DIV 2 AS `x` from `t`', [3, 1]],
        [$my, 'CREATE VIEW m16 AS select case when `t`.`n` > 5 then 7 else 2 end MOD (4) AS `x` from `t`', [3, 2]],
    ];
    $mal = [];
    foreach ($vistas as [$tr, $sql, $esperado]) {
        foreach ($tr->traducir($sql) as $x) { $bd->consultar($x); }
        preg_match('/VIEW (\w+)/', $sql, $v);
        $r = array_column($bd->consultar("SELECT * FROM {$v[1]}"), 'x');
        if ($r !== $esperado) { $mal[] = "{$v[1]}: " . json_encode($r); }
    }
    // <=> en el SET de un UPDATE de un trigger: el = es la asignación
    $bd->consultar('CREATE TABLE t2 (id INTEGER PRIMARY KEY, e INTEGER, f INTEGER)');
    $bd->consultar('INSERT INTO t2 VALUES (1, NULL, NULL), (2, NULL, NULL)');
    $my->traducir("CREATE TRIGGER t3 AFTER UPDATE ON t FOR EACH ROW UPDATE t2 SET f = CASE WHEN NEW.n > 1 THEN 1 END, e = NEW.a <=> 'p' WHERE id = NEW.id");
    foreach ($my->triggers() as [, $x]) { $bd->consultar($x); }
    $bd->consultar('UPDATE t SET n = n');
    $e = array_map('array_values', $bd->consultar('SELECT e, f FROM t2 ORDER BY id'));
    if ($e !== [[1, 1], [0, 1]]) { $mal[] = 't3: ' . json_encode($e); }
    Database::borrar('precedencia');
    return $mal === [] && $ac->avisos() === [] && $my->avisos() === [] && $pg->avisos() === [] ?: json_encode([$mal, $ac->avisos(), $my->avisos(), $pg->avisos()], JSON_UNESCAPED_UNICODE);
});
chk('PostgreSQL: date - date son los días entre las dos y date ± n otra fecha, como allí; lo que da un intervalo (timestamp - timestamp) no se traduce y esa vista se salta con el motivo (2.9: se restaban como textos y daban otro número)', function () use ($pgConexion) {
    // Como lo escribe pg_dump; los resultados, los de PostgreSQL 16
    $volcado = "--\n-- PostgreSQL database dump\n--\nSET standard_conforming_strings = on;\n"
        . "CREATE TABLE public.t (\n    id integer NOT NULL,\n    f date,\n    g date,\n    ts timestamp without time zone,\n    n integer\n);\n"
        . "CREATE VIEW public.v1 AS\n SELECT id,\n    ('2026-01-01'::date - f) AS d1,\n    (f - g) AS d2,\n    ((ts)::date - g) AS d3\n   FROM public.t;\n"
        . "CREATE VIEW public.v2 AS\n SELECT t.id,\n    (t.f - 7) AS antes,\n    (t.f + t.n) AS luego,\n    (n + f) AS luego2,\n    (g - (n * 2)) AS doble\n   FROM public.t;\n"
        . "CREATE VIEW public.v3 AS\n SELECT id,\n    (n - 1) AS m,\n    ((n + 2) * 3) AS p\n   FROM public.t;\n"
        . "CREATE VIEW public.v4 AS\n SELECT id,\n    (ts - (g)::timestamp without time zone) AS intervalo\n   FROM public.t;\n"
        . "INSERT INTO public.t VALUES (1, '2023-01-15', '2020-02-29', '2024-03-01 10:00:00', 7);\n"
        . "INSERT INTO public.t VALUES (2, '2024-12-31', '2024-01-01', NULL, 30);\n"
        . "INSERT INTO public.t VALUES (3, NULL, '2001-05-05', '2020-01-01 00:00:00', -3);\n"
        . "ALTER TABLE ONLY public.t ADD CONSTRAINT t_pkey PRIMARY KEY (id);\n";
    $f = JSONSQLDB_DATA_PATH . '/fechas.pg.sql';
    file_put_contents($f, $volcado);
    Database::crear('fechas_pg');
    $resumen = Importar::sql($f, 'fechas_pg');
    @unlink($f);
    $bd = new Database('fechas_pg');
    $aqui = static fn(string $v): array => $bd->consultar("SELECT * FROM $v ORDER BY id");
    $mal = [];
    $esperado = [
        'v1' => [['id' => 1, 'd1' => 1082, 'd2' => 1051, 'd3' => 1462], ['id' => 2, 'd1' => 366, 'd2' => 365, 'd3' => null], ['id' => 3, 'd1' => null, 'd2' => null, 'd3' => 6815]],
        'v2' => [['id' => 1, 'antes' => '2023-01-08', 'luego' => '2023-01-22', 'luego2' => '2023-01-22', 'doble' => '2020-02-15'],
                 ['id' => 2, 'antes' => '2024-12-24', 'luego' => '2025-01-30', 'luego2' => '2025-01-30', 'doble' => '2023-11-02'],
                 ['id' => 3, 'antes' => null, 'luego' => null, 'luego2' => null, 'doble' => '2001-05-11']],
        'v3' => [['id' => 1, 'm' => 6, 'p' => 27], ['id' => 2, 'm' => 29, 'p' => 96], ['id' => 3, 'm' => -4, 'p' => -3]],
    ];
    foreach ($esperado as $v => $filas) {
        if ($aqui($v) !== $filas) { $mal[] = "$v: " . json_encode($aqui($v)); }
    }
    if (!str_contains($resumen, "Vista 'v4' sin importar") || !str_contains($resumen, 'con fechas') || array_column($bd->consultar('SHOW VIEWS'), 'vista') !== ['v1', 'v2', 'v3']) {
        $mal[] = $resumen;
    }
    // Y con un servidor, contra lo que da allí ahora mismo
    if ($pgConexion !== '' && function_exists('pg_connect') && $mal === []) {
        [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
        $admin = @pg_connect("host=$host user=$usuario password=$clave dbname=postgres");
        if ($admin) {
            $nombre = 'jsonsqldb_f17d_' . getmypid();
            @pg_query($admin, "DROP DATABASE IF EXISTS $nombre");
            pg_query($admin, "CREATE DATABASE $nombre");
            $c = pg_connect("host=$host user=$usuario password=$clave dbname=$nombre");
            if (@pg_query($c, $volcado) === false) {
                $mal[] = pg_last_error($c);
            } else {
                $mal = diferencias(['v1', 'v2', 'v3'], ['v1', 'v2', 'v3'],
                    static fn(string $v): array => pg_fetch_all(pg_query($c, "SELECT * FROM $v ORDER BY id")) ?: [], $aqui);
            }
            pg_close($c);
            @pg_query($admin, "DROP DATABASE IF EXISTS $nombre");
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});

chk('|| se une aquí antes que + - * / (como en SQLite), y en PostgreSQL y en el + de SQL Server después: el lado que es una cuenta va entre paréntesis, en las vistas y en los triggers; y el || de un trigger de MySQL es OR salvo con PIPES_AS_CONCAT (2.9)', function () use ($pgConexion) {
    $esperado = [['id' => 1, 'a' => 'A6', 'b' => '10B', 'c' => 'C4D', 'd' => 'x11x', 'f' => '-5x'],
                 ['id' => 2, 'a' => 'A-2', 'b' => '-6B', 'c' => 'C-4D', 'd' => 'y-5y', 'f' => '3x']];     // los de PostgreSQL 16
    $tabla = 'CREATE TABLE public.t (id integer NOT NULL, n integer, s text);';
    $vista = "CREATE VIEW public.v AS SELECT id, 'A' || t.n + 1 AS a, t.n * 2 || 'B' AS b, 'C' || t.n - 1 || 'D' AS c, s || n * 2 + 1 || s AS d, - n || 'x' AS f FROM public.t;";
    $pg = new Traductor('postgresql');
    $pg->observar($tabla);
    $pg->traducir($tabla);
    $v = $pg->traducir($vista)[0] ?? '';
    $pg->traducir("CREATE FUNCTION public.f() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.s := 'A' || NEW.n + 1; RETURN NEW; END \$\$;");
    $pg->traducir('CREATE TRIGGER t_bi BEFORE INSERT ON public.t FOR EACH ROW EXECUTE FUNCTION public.f();');
    $trg = $pg->triggers()[0][1] ?? '';
    $mal = [];
    foreach (["'A' || ( t . n + 1 )", "( t . n * 2 ) || 'B'", "'C' || ( t . n - 1 ) || 'D'", "s || ( n * 2 + 1 ) || s", "- n || 'x'"] as $trozo) {
        if (!str_contains($v, $trozo)) { $mal[] = "falta «{$trozo}» en $v"; }
    }
    if (!str_contains($trg, "SET NEW.\"s\" = 'A' || ( NEW . n + 1 )")) { $mal[] = $trg; }
    // Lo traducido, en SQLite (que ya une || antes que + y *), da lo de PostgreSQL
    if (class_exists('SQLite3') && $mal === []) {
        $lite = new SQLite3(':memory:');
        $lite->exec("CREATE TABLE t (id INTEGER, n INTEGER, s TEXT); INSERT INTO t VALUES (1, 5, 'x'), (2, -3, 'y')");
        $r = $lite->query(substr($v, strpos($v, ' AS SELECT ') + 4) . ' ORDER BY id');
        $filas = [];
        while (($f = $r->fetchArray(SQLITE3_ASSOC)) !== false) { $filas[] = $f; }
        if ($filas !== $esperado) { $mal[] = 'SQLite: ' . json_encode($filas); }
    }
    // Y con un servidor, que esos son sus resultados
    if ($pgConexion !== '' && function_exists('pg_connect') && $mal === []) {
        [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
        $c = @pg_connect("host=$host user=$usuario password=$clave dbname=postgres");
        if ($c) {
            $alli = pg_fetch_all(pg_query($c, "SELECT id, 'A' || t.n + 1 AS a, t.n * 2 || 'B' AS b, 'C' || t.n - 1 || 'D' AS c, s || n * 2 + 1 || s AS d, - n || 'x' AS f"
                . " FROM (VALUES (1, 5, 'x'), (2, -3, 'y')) AS t (id, n, s) ORDER BY id")) ?: [];
            pg_close($c);
            if (array_map(static fn($f) => ['id' => (int)$f['id']] + $f, $alli) !== $esperado) { $mal[] = 'PostgreSQL: ' . json_encode($alli); }
        }
    }
    // SQL Server: el + que concatena, entero entre paréntesis
    $ss = new Traductor('sqlserver');
    $tss = 'CREATE TABLE [dbo].[t]([id] [int] NOT NULL, [n] [int] NULL, [s] [nvarchar](20) NULL)';
    $ss->observar($tss);
    $ss->traducir($tss);
    $vss = $ss->traducir("CREATE VIEW [dbo].[v] AS SELECT id, 'A' + CAST(n AS varchar(10)) + 'B' AS a, 'x' + s + 'y' AS c, n * 2 + 1 AS d FROM dbo.t")[0] ?? '';
    if (!str_contains($vss, "(('A' || CAST(n AS TEXT)) || 'B') AS a") || !str_contains($vss, "(('x' || s) || 'y') AS c") || !str_contains($vss, 'n * 2 + 1 AS d')) {
        $mal[] = $vss;
    }
    // MySQL: el sql_mode que mysqldump pone delante de cada trigger
    $my = new Traductor('mysql');
    foreach (["SET sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES'", 'CREATE TRIGGER t1 BEFORE INSERT ON t FOR EACH ROW SET NEW.n = (NEW.a || NEW.b) + 10',
              "SET sql_mode = 'PIPES_AS_CONCAT,ANSI_QUOTES'", "CREATE TRIGGER t2 BEFORE INSERT ON t FOR EACH ROW SET NEW.s = 'x' || NEW.a",
              "SET sql_mode = 'ANSI_QUOTES'", 'CREATE TRIGGER t3 BEFORE INSERT ON t FOR EACH ROW SET NEW.n = NEW.a || NEW.b'] as $q) {
        $my->traducir($q);
    }
    $trgs = array_column($my->triggers(), 1);
    if (!str_contains($trgs[0] ?? '', '( NEW . a OR NEW . b ) + 10') || !str_contains($trgs[1] ?? '', "'x' || NEW . a") || !str_contains($trgs[2] ?? '', 'NEW . a OR NEW . b')) {
        $mal[] = implode(' · ', $trgs);
    }
    return $mal === [] ?: implode(' | ', $mal);
});

borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
