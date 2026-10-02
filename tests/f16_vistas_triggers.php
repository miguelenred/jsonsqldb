<?php
declare(strict_types=1);

/**
 * Vistas y triggers exportados a otros motores. Ejecutar:
 *   php tests/f16_vistas_triggers.php
 *
 * Una base con vistas de todo tipo (JOIN, agregados, textos, cálculos, CASE,
 * LIKE, subconsultas, UNION, WITH, fechas, una vista sobre otra) y triggers
 * de todo tipo (BEFORE con IF, SET NEW y RAISE; AFTER que escriben en otras
 * tablas; uno que actualiza su propia fila; WHEN) se exporta para MySQL,
 * PostgreSQL, SQL Server y Access.
 *
 * Con un servidor MySQL o PostgreSQL (las mismas variables de entorno que
 * tests/f14_volcados.php), el volcado se carga y se hacen las mismas
 * escrituras allí y aquí, también las que un trigger rechaza: las tablas y
 * las vistas tienen que quedar iguales. Es decir, no solo que se creen: que
 * hagan lo mismo.
 *
 * Sin servidores se comprueba que todo se traduce y la forma de lo traducido.
 *
 * https://miguelenred.es/jsonsqldb
 */
use JsonSQLDB\Database;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f16_' . getmypid();
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_DATA_PATH', $raiz);
require dirname(__DIR__) . '/engine/bootstrap.php';
require dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php';
foreach (['Idioma', 'Exportar'] as $clase) {
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

borrarArbol($raiz);
mkdir($raiz, 0775, true);
Database::crear('vt', $raiz);
$bd = new Database('vt', $raiz);

$esquema = [
    'CREATE TABLE clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre VARCHAR(60) NOT NULL, email VARCHAR(100),
        saldo DECIMAL(10,2) DEFAULT 0, alta DATETIME)',
    'CREATE TABLE pedidos (id INTEGER PRIMARY KEY AUTOINCREMENT, cliente_id INTEGER NOT NULL, total DECIMAL(10,2),
        estado VARCHAR(20), creado DATETIME, FOREIGN KEY (cliente_id) REFERENCES clientes (id))',
    'CREATE TABLE registro (id INTEGER PRIMARY KEY AUTOINCREMENT, msg VARCHAR(200))',
    'CREATE INDEX ix_pedidos_estado ON pedidos (estado)',
];
$vistas = [
    'v_resumen'  => "SELECT c.nombre, COUNT(p.id) AS n, SUM(p.total) AS total, AVG(p.total) AS media
                     FROM clientes c LEFT JOIN pedidos p ON p.cliente_id = c.id GROUP BY c.id, c.nombre",
    'v_textos'   => "SELECT id, UPPER(nombre) AS may, LOWER(nombre) AS min, LENGTH(nombre) AS largo, SUBSTR(nombre, 2, 3) AS trozo,
                     nombre || ' <' || email || '>' AS contacto, COALESCE(email, 'sin email') AS correo, IFNULL(email, '-') AS correo2,
                     INSTR(nombre, 'a') AS pos, REPLACE(nombre, 'a', 'A') AS rep, TRIM('  x  ') AS t, CONCAT(nombre, '/', email) AS c2
                     FROM clientes",
    'v_calculos' => "SELECT id, total / 3 AS tercio, total % 7 AS resto, ROUND(total * 1.21, 2) AS iva, ABS(total - 100) AS dist,
                     CASE WHEN total > 100 THEN 'alto' WHEN total > 50 THEN 'medio' ELSE 'bajo' END AS tramo,
                     total > 100 AS grande, CAST(total AS INTEGER) AS entero, MAX(total, 100) AS tope, MIN(total, 100) AS suelo,
                     NULLIF(estado, 'nuevo') AS e, CASE estado WHEN 'nuevo' THEN 1 ELSE 0 END AS es_nuevo, -total AS negativo
                     FROM pedidos",
    'v_filtro'   => "SELECT nombre FROM clientes WHERE (nombre LIKE 'a%' OR email IS NULL) AND id BETWEEN 1 AND 100
                     AND nombre NOT LIKE '%z%' ORDER BY nombre LIMIT 10",
    'v_sub'      => "SELECT nombre, (SELECT COUNT(*) FROM pedidos p WHERE p.cliente_id = c.id) AS pedidos FROM clientes c
                     WHERE EXISTS (SELECT 1 FROM pedidos p WHERE p.cliente_id = c.id AND p.total > 50)
                     AND id IN (SELECT cliente_id FROM pedidos) AND id NOT IN (999)",
    'v_union'    => "SELECT nombre AS n FROM clientes UNION SELECT estado FROM pedidos WHERE estado IS NOT NULL",
    'v_with'     => "WITH grandes AS (SELECT * FROM pedidos WHERE total > 50) SELECT cliente_id, COUNT(*) AS n FROM grandes GROUP BY cliente_id",
    'v_fechas'   => "SELECT id, DATE(creado) AS dia, STRFTIME('%Y-%m', creado) AS mes, DATE(creado, '+1 day') AS manana,
                     DATETIME(creado, '+2 hours') AS luego, DATE(creado, 'start of month') AS inicio FROM pedidos WHERE creado IS NOT NULL",
    'v_de_vista' => "SELECT * FROM v_resumen WHERE n > 0",
    'v_grupos'   => "SELECT cliente_id, GROUP_CONCAT(estado, ';') AS estados FROM pedidos GROUP BY cliente_id HAVING COUNT(*) > 0",
    'v_offset'   => "SELECT id FROM pedidos ORDER BY id LIMIT 2 OFFSET 1",
];
$triggers = [
    // BEFORE: rechaza, y completa la fila
    "CREATE TRIGGER pedidos_bi BEFORE INSERT ON pedidos BEGIN
        IF NEW.total < 0 THEN SELECT RAISE(ABORT, 'total negativo');
        ELSEIF NEW.total > 1000 THEN SET NEW.estado = 'revisar';
        END IF;
        SET NEW.estado = COALESCE(NEW.estado, 'nuevo');
     END",
    // AFTER: escribe en otras tablas
    "CREATE TRIGGER pedidos_ai AFTER INSERT ON pedidos BEGIN
        UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id;
        INSERT INTO registro (msg) VALUES ('alta ' || NEW.id || ' de ' || NEW.cliente_id);
     END",
    "CREATE TRIGGER pedidos_au AFTER UPDATE ON pedidos WHEN OLD.total <> NEW.total BEGIN
        UPDATE clientes SET saldo = saldo - OLD.total + NEW.total WHERE id = NEW.cliente_id;
     END",
    // BEFORE DELETE con un RAISE condicionado, a la manera de SQLite
    "CREATE TRIGGER pedidos_bd BEFORE DELETE ON pedidos BEGIN
        SELECT RAISE(ABORT, 'no se borra un pedido cerrado') WHERE OLD.estado = 'cerrado';
     END",
    "CREATE TRIGGER pedidos_ad AFTER DELETE ON pedidos BEGIN
        UPDATE clientes SET saldo = saldo - OLD.total WHERE id = OLD.cliente_id;
        INSERT INTO registro (msg) VALUES ('baja ' || OLD.id);
     END",
    // AFTER que actualiza su propia fila: en MySQL pasa a BEFORE con SET NEW
    "CREATE TRIGGER clientes_ai AFTER INSERT ON clientes BEGIN
        UPDATE clientes SET email = LOWER(email) WHERE id = NEW.id;
     END",
    "CREATE TRIGGER clientes_bu BEFORE UPDATE ON clientes BEGIN
        SELECT CASE WHEN NEW.saldo < -1000 THEN RAISE(ABORT, 'saldo demasiado negativo') END;
     END",
];
foreach (array_merge($esquema, $triggers) as $q) {
    $bd->consultar($q);
}
foreach ($vistas as $n => $sql) {
    $bd->consultar("CREATE VIEW $n AS $sql");
}
// Unos datos de partida (los triggers ya actúan)
foreach ([["Ana", 'ANA@E.ES', '2026-01-05 10:00:00'], ['alberto', null, '2026-02-10 08:30:00'], ['Bea', 'Bea@E.es', null]] as $c) {
    $bd->consultar('INSERT INTO clientes (nombre, email, alta) VALUES (?, ?, ?)', $c);
}
foreach ([[1, 40, null, '2026-03-01 09:00:00'], [1, 120, 'pagado', '2026-03-15 18:45:00'], [2, 75.5, null, null]] as $p) {
    $bd->consultar('INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (?, ?, ?, ?)', $p);
}

/** Lo que reúne el panel para exportar. */
function reunir(Database $bd): array {
    $tablas = [];
    foreach ($bd->consultar('SHOW TABLES') as $t) {
        $n = (string)$t['tabla'];
        $tablas[] = ['tabla' => $n, 'columnas' => $bd->consultar("SHOW SCHEMA $n"), 'claves' => $bd->consultar("SHOW KEYS FROM $n"),
                     'filas' => $bd->consultar("SELECT * FROM $n")];
    }
    return [$tablas, $bd->consultar('SHOW TRIGGERS'), $bd->consultar('SHOW VIEWS'), $bd->consultar('SHOW INDEXES')];
}
[$tablas, $trgs, $vs, $ixs] = reunir($bd);

// Las mismas escrituras aquí y en el otro motor; las que fallan, fallan en los dos
$escrituras = [
    ["INSERT INTO clientes (nombre, email, alta) VALUES ('Zoe', 'ZOE@Mail.COM', '2026-04-01 12:00:00')", true],
    ["INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (4, 30, NULL, '2026-04-02 10:00:00')", true],
    ["INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (4, 2000, NULL, '2026-04-03 10:00:00')", true],
    // Con decimales: CAST(total AS INTEGER) trunca aquí; MySQL y PostgreSQL
    // redondearían. Antes de las que fallan: en PostgreSQL un INSERT rechazado
    // gasta un número de la secuencia, y los siguientes ya no coincidirían
    ["INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (2, 12.75, 'pagado', '2026-04-04 10:00:00')", true],
    ["INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (3, -5, NULL, NULL)", false],
    ["UPDATE pedidos SET total = 90 WHERE id = 1", true],
    ["UPDATE pedidos SET estado = 'cerrado' WHERE id = 2", true],
    ["DELETE FROM pedidos WHERE id = 2", false],
    ["DELETE FROM pedidos WHERE id = 3", true],
    ["UPDATE clientes SET saldo = -5000 WHERE id = 1", false],
    ["INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES (2, -7.5, NULL, NULL)", false],
];
$errores = [];
foreach ($escrituras as $i => [$sql, $vale]) {
    try {
        $bd->consultar($sql);
        $errores[$i] = false;
    } catch (Throwable $e) {
        $errores[$i] = true;
    }
}
$aqui = [];
foreach (['clientes', 'pedidos', 'registro'] as $t) {
    $aqui[$t] = $bd->consultar("SELECT * FROM $t ORDER BY id");
}
foreach (array_keys($vistas) as $v) {
    $aqui[$v] = $bd->consultar("SELECT * FROM $v");
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
    if ($ordenar) {
        sort($o);
    }
    return $o;
}
/** Las diferencias entre lo de aquí y lo de allí, tabla a tabla y vista a vista. */
function diferencias(array $aqui, callable $consultar): array {
    global $vistas;
    $mal = [];
    foreach ($aqui as $nombre => $filas) {
        $alli = $consultar($nombre);
        $orden = in_array($nombre, ['v_filtro', 'v_offset'], true);
        $a = comparable($filas, !$orden);
        $b = comparable($alli, !$orden);
        if ($nombre === 'v_grupos') {
            // El orden dentro de GROUP_CONCAT no está definido en ningún motor
            $a = array_map(static function ($j) { $f = json_decode($j, true); $p = explode(';', (string)$f['estados']); sort($p); $f['estados'] = implode(';', $p); return json_encode($f); }, $a);
            $b = array_map(static function ($j) { $f = json_decode($j, true); $p = explode(';', (string)$f['estados']); sort($p); $f['estados'] = implode(';', $p); return json_encode($f); }, $b);
            sort($a);
            sort($b);
        }
        if ($a !== $b) {
            // Solo las filas que no están en los dos lados (o el orden, si son las mismas)
            $soloAqui = array_values(array_diff($a, $b));
            $soloAlli = array_values(array_diff($b, $a));
            $mal[] = "$nombre:\n      aquí: " . implode(' ', array_slice($soloAqui ?: $a, 0, 4)) . "\n      allí: " . implode(' ', array_slice($soloAlli ?: $b, 0, 4));
        }
    }
    return $mal;
}

echo "\n== Se traduce todo ==\n";
$volcados = [];
foreach (['mysql', 'postgresql', 'sqlserver', 'access'] as $d) {
    $volcados[$d] = Exportar::volcado('vt', $tablas, $trgs, $vs, $ixs, $d);
}
// Para mirarlos a mano: JSONSQLDB_F16_GUARDAR=carpeta
if (($guardar = (string)getenv('JSONSQLDB_F16_GUARDAR')) !== '') {
    @mkdir($guardar, 0775, true);
    foreach ($volcados as $d => $sql) {
        file_put_contents("$guardar/vt.$d.sql", $sql);
    }
}
chk('cada vista se escribe en MySQL, PostgreSQL y SQL Server sin quedar ninguna comentada', function () use ($vistas) {
    $mal = [];
    foreach (['mysql', 'postgresql', 'sqlserver'] as $d) {
        $g = new GeneradorSql($d);
        foreach ($vistas as $n => $sql) {
            try { $g->vista($n, $sql); } catch (Throwable $e) { $mal[] = "$d/$n: " . $e->getMessage(); }
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('en Access, todas salvo las que usan lo que Access no tiene (GROUP_CONCAT, OFFSET)', function () use ($vistas) {
    $g = new GeneradorSql('access');
    $no = [];
    foreach ($vistas as $n => $sql) {
        try { $g->vista($n, $sql); } catch (NoTraducible $e) { $no[] = $n; }
    }
    return $no === ['v_grupos', 'v_offset'] ?: 'sin traducir: ' . implode(', ', $no);
});
chk('cada trigger se escribe en MySQL, PostgreSQL y SQL Server', function () use ($volcados) {
    foreach (['mysql', 'postgresql', 'sqlserver'] as $d) {
        if (str_contains($volcados[$d], 'Sin traducir')) {
            preg_match('/-- Sin traducir: [^\n]*/', $volcados[$d], $m);
            return "$d: " . ($m[0] ?? '');
        }
    }
    return true;
});
chk('MySQL: el AFTER que actualiza su propia fila pasa a un BEFORE con SET NEW', function () use ($volcados) {
    return str_contains($volcados['mysql'], 'CREATE TRIGGER `clientes_ai_antes` BEFORE INSERT ON `clientes`')
        && str_contains($volcados['mysql'], 'SET NEW.`email` = LOWER(NEW.`email`);')
        && !str_contains($volcados['mysql'], 'CREATE TRIGGER `clientes_ai` AFTER') ?: 'no se reescribió';
});
chk('SQL Server: fila a fila con un cursor, y los BEFORE como INSTEAD OF que hacen la escritura', function () use ($volcados) {
    $s = $volcados['sqlserver'];
    return str_contains($s, 'CREATE TRIGGER [pedidos_bi] ON [pedidos] INSTEAD OF INSERT AS')
        && str_contains($s, 'DECLARE filas CURSOR LOCAL FAST_FORWARD FOR SELECT')
        && str_contains($s, "THROW 50000, N'total negativo', 1;")
        && preg_match('/INSERT INTO \[pedidos\] \(\[cliente_id\], \[total\], \[estado\], \[creado\]\) VALUES \(@n1, @n2, @n3, @n4\)/', $s) === 1
        && str_contains($s, 'CREATE TRIGGER [pedidos_au] ON [pedidos] AFTER UPDATE AS')
        && str_contains($s, 'inserted AS i JOIN deleted AS d ON i.[id] = d.[id]') ?: 'no tiene la forma esperada';
});

// ---------------------------------------------------------------------
echo "\n== MySQL / MariaDB: hacen lo mismo ==\n";
$conexion = (string)getenv('JSONSQLDB_TEST_MYSQL');
$m = null;
if ($conexion !== '' && class_exists('mysqli')) {
    [$host, $usuario, $clave] = array_pad(explode(':', $conexion, 3), 3, '');
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli($host, $usuario, $clave);
    if ($m->connect_errno) { $m = null; }
}
/** Ejecuta un volcado de MySQL como el cliente mysql: entendiendo DELIMITER. */
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
$nombreBd = 'jsonsqldb_f16_' . getmypid();
chk('el volcado, con sus vistas y triggers, se carga sin un solo error', function () use ($m, $volcados, $nombreBd) {
    if ($m === null) { return getenv('JSONSQLDB_TEST_MYSQL_EXIGIR') ? 'no hay servidor MySQL y se exige' : null; }
    $m->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
    $m->query("DROP DATABASE IF EXISTS `$nombreBd`");
    $m->query("CREATE DATABASE `$nombreBd`");
    $m->select_db($nombreBd);
    return cargarMysql($m, $volcados['mysql']) ?? true;
});
chk('las mismas escrituras dejan las mismas tablas y las mismas vistas, y fallan las mismas', function () use ($m, $escrituras, $errores, $aqui) {
    if ($m === null) { return null; }
    $mal = [];
    foreach ($escrituras as $i => [$sql]) {
        $fallo = !$m->query($sql);
        if ($fallo !== $errores[$i]) { $mal[] = "«$sql» " . ($fallo ? 'falla allí: ' . $m->error : 'no falla allí'); }
    }
    $mal = array_merge($mal, diferencias($aqui, static function (string $n) use ($m) {
        $r = $m->query("SELECT * FROM `$n`" . (str_starts_with($n, 'v_') ? '' : ' ORDER BY id'));
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [['error' => $m->error]];
    }));
    return $mal === [] ?: "\n    " . implode("\n    ", $mal);
});
if ($m !== null) { $m->query("DROP DATABASE IF EXISTS `$nombreBd`"); }

// ---------------------------------------------------------------------
echo "\n== PostgreSQL: hacen lo mismo ==\n";
$pgConexion = (string)getenv('JSONSQLDB_TEST_POSTGRESQL');
$pg = null;
$pgBd = 'jsonsqldb_f16_' . getmypid();
if ($pgConexion !== '' && function_exists('pg_connect')) {
    [$host, $usuario, $clave] = array_pad(explode(':', $pgConexion, 3), 3, '');
    $admin = @pg_connect("host=$host user=$usuario password=$clave dbname=postgres");
    if ($admin) {
        @pg_query($admin, "DROP DATABASE IF EXISTS $pgBd");
        pg_query($admin, "CREATE DATABASE $pgBd");
        $pg = pg_connect("host=$host user=$usuario password=$clave dbname=$pgBd");
    }
}
chk('el volcado, con sus vistas y triggers, se carga sin un solo error', function () use ($pg, $volcados) {
    if ($pg === null) { return getenv('JSONSQLDB_TEST_POSTGRESQL_EXIGIR') ? 'no hay servidor PostgreSQL y se exige' : null; }
    return @pg_query($pg, $volcados['postgresql']) !== false ?: pg_last_error($pg);
});
chk('las mismas escrituras dejan las mismas tablas y las mismas vistas, y fallan las mismas', function () use ($pg, $escrituras, $errores, $aqui) {
    if ($pg === null) { return null; }
    $mal = [];
    foreach ($escrituras as $i => [$sql]) {
        $fallo = @pg_query($pg, $sql) === false;
        if ($fallo !== $errores[$i]) { $mal[] = "«$sql» " . ($fallo ? 'falla allí: ' . pg_last_error($pg) : 'no falla allí'); }
    }
    $mal = array_merge($mal, diferencias($aqui, static function (string $n) use ($pg) {
        $r = @pg_query($pg, "SELECT * FROM \"$n\"" . (str_starts_with($n, 'v_') ? '' : ' ORDER BY id'));
        return $r ? (pg_fetch_all($r) ?: []) : [['error' => pg_last_error($pg)]];
    }));
    return $mal === [] ?: "\n    " . implode("\n    ", $mal);
});
if ($pg !== null) {
    pg_close($pg);
    @pg_query($admin, "DROP DATABASE IF EXISTS $pgBd");
}


// ---------------------------------------------------------------------
echo "\n== 25 formas de vista, en MySQL y en PostgreSQL ==\n";
// Cada forma de SELECT que admite el motor, en una base aparte, se traduce y
// se crea allí, y su resultado tiene que ser el de aquí. Así salieron SUBSTR
// con inicio negativo (PostgreSQL daba vacío) y ROUND de un DOUBLE (MySQL
// redondea al par).
Database::crear('formas', $raiz);
$bf = new Database('formas', $raiz);
[$tablasF, $formas] = require __DIR__ . '/volcados/formas_de_vista.php';
foreach ($tablasF as $q) {
    $bf->consultar($q);
}
$colsF = [];
$metaF = [];
foreach (['a', 'b'] as $t) {
    $colsF[$t] = $bf->consultar("SHOW SCHEMA $t");
    $metaF[] = ['tabla' => $t, 'columnas' => $colsF[$t], 'claves' => $bf->consultar("SHOW KEYS FROM $t"), 'filas' => $bf->consultar("SELECT * FROM $t")];
}
foreach ($formas as $i => $sql) {
    $bf->consultar("CREATE VIEW f$i AS $sql");
}
/** Resultados comparables (como comparable(), y los booleanos de PostgreSQL como números). */
function comparableF(array $filas, bool $orden): array {
    $o = [];
    foreach ($filas as $f) {
        $x = [];
        foreach ($f as $c => $v) {
            if (is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2})( \d{2}:\d{2}:\d{2})?(\.\d+)?$/', $v, $mm)) { $v = $mm[1] . ($mm[2] ?? ''); }
            elseif (is_bool($v)) { $v = (int)$v; }
            elseif (is_numeric($v) && !preg_match('/^0\d/', (string)$v)) { $v = round((float)$v, 4); }
            $x[strtolower((string)$c)] = $v;
        }
        $o[] = json_encode($x, JSON_UNESCAPED_UNICODE);
    }
    if (!$orden) { sort($o); }
    return $o;
}
foreach (['mysql' => 'MySQL', 'postgresql' => 'PostgreSQL'] as $d => $motor) {
    chk("$motor: las 25 formas se crean allí y dan lo mismo que aquí", function () use ($d, $formas, $colsF, $metaF, $bf) {
        $conexion = (string)getenv($d === 'mysql' ? 'JSONSQLDB_TEST_MYSQL' : 'JSONSQLDB_TEST_POSTGRESQL');
        if ($conexion === '' || ($d === 'mysql' ? !class_exists('mysqli') : !function_exists('pg_connect'))) { return null; }
        [$host, $usuario, $clave] = array_pad(explode(':', $conexion, 3), 3, '');
        $bdF = 'jsonsqldb_f16f_' . getmypid();
        if ($d === 'mysql') {
            mysqli_report(MYSQLI_REPORT_OFF);
            $c = @new mysqli($host, $usuario, $clave);
            if ($c->connect_errno) { return null; }
            $c->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
            $c->query("DROP DATABASE IF EXISTS `$bdF`");
            $c->query("CREATE DATABASE `$bdF` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
            $c->select_db($bdF);
            $error = cargarMysql($c, Exportar::volcado('formas', $metaF, [], [], [], 'mysql'));
            $crear = static fn(string $q) => $c->query($q) ? null : $c->error;
            $leer = static function (string $q) use ($c) { $r = $c->query($q); return $r ? $r->fetch_all(MYSQLI_ASSOC) : null; };
        } else {
            $admin = @pg_connect("host=$host user=$usuario password=$clave dbname=postgres");
            if (!$admin) { return null; }
            @pg_query($admin, "DROP DATABASE IF EXISTS $bdF");
            pg_query($admin, "CREATE DATABASE $bdF");
            $c = pg_connect("host=$host user=$usuario password=$clave dbname=$bdF");
            $error = @pg_query($c, Exportar::volcado('formas', $metaF, [], [], [], 'postgresql')) === false ? pg_last_error($c) : null;
            $crear = static fn(string $q) => @pg_query($c, $q) === false ? pg_last_error($c) : null;
            $leer = static function (string $q) use ($c) { $r = @pg_query($c, $q); return $r ? (pg_fetch_all($r) ?: []) : null; };
        }
        $mal = $error !== null ? ["tablas: $error"] : [];
        $gen = (new GeneradorSql($d))->conTipos($colsF);
        foreach ($formas as $i => $sql) {
            $e = $crear($gen->vista("f$i", $sql));
            if ($e !== null) { $mal[] = "f$i no se crea: $e"; continue; }
            $orden = stripos($sql, 'ORDER BY') !== false && stripos($sql, 'LIMIT') !== false;
            $alli = $leer("SELECT * FROM f$i");
            if ($alli === null || comparableF($alli, $orden) !== comparableF($bf->consultar("SELECT * FROM f$i"), $orden)) {
                $mal[] = "f$i distinta: $sql";
            }
        }
        if ($d === 'mysql') { $c->query("DROP DATABASE IF EXISTS `$bdF`"); }
        else { pg_close($c); @pg_query($admin, "DROP DATABASE IF EXISTS $bdF"); }
        return $mal === [] ?: implode(' | ', $mal);
    });
}

borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
