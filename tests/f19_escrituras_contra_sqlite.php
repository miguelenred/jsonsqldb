<?php
declare(strict_types=1);

/**
 * Escrituras y consultas al azar contra SQLite, sobre tablas de muchas partes.
 *
 *   php tests/f19_escrituras_contra_sqlite.php                # 6 semillas
 *   php tests/f19_escrituras_contra_sqlite.php --semillas=50  # más
 *
 * f13 compara consultas sobre tablas que caben en una parte. Esta trocea las
 * tablas en partes de 12 filas y, en cada vuelta, hace una escritura al azar
 * (UPDATE y DELETE por un texto sin índice, por IN, por clave, cambios de clave
 * y borrados en cascada; y desde la 2.9, UPDATE … FROM, UPDATE … JOIN, DELETE
 * … USING y DELETE … JOIN, con su equivalente en SQLite cuando allí no
 * existe) y después una docena de consultas: igualdades de
 * texto, IN, COUNT(*) por índice, ORDER BY con LIMIT de una y dos columnas,
 * GROUP BY, AND de comparaciones y JOIN. Las semillas pares crean índices en
 * la clave foránea y en el texto, así que se prueban los dos caminos. Lo que
 * cubre es lo que cambió en 2.7.4 y 2.7.5: saltarse partes por su texto,
 * escribir sin cargar la tabla, el mapa de las cascadas y los atajos por fila.
 * Y desde la 2.9, upserts y cascadas de varios niveles con triggers, UNIQUE
 * y SET DEFAULT (ver upserts() y cascadas()).
 *
 * Los empates de un ORDER BY no tienen orden fijo ni en SQLite ni aquí: las
 * consultas con LIMIT sobre una columna con empates comparan solo esa columna.
 *
 * https://miguelenred.es/jsonsqldb
 */

define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_DATA_PATH', sys_get_temp_dir() . '/jsonsqldb_test_f19');
define('JSONSQLDB_LOG_PATH', JSONSQLDB_DATA_PATH . '/logs');
define('JSONSQLDB_FILAS_POR_PARTE', 12);
require __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;

$ok = 0;
$ko = 0;
function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
    } catch (Throwable $e) {
        $r = get_class($e) . ': ' . $e->getMessage();
    }
    if ($r === null) {
        echo "  --   $titulo (sin SQLite3)\n";
        return;
    }
    if ($r === true) {
        $ok++;
        echo "  OK   $titulo\n";
    } else {
        $ko++;
        echo "  FALLO $titulo -> " . (is_string($r) ? $r : var_export($r, true)) . "\n";
    }
}

function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $r = "$dir/$f";
        is_dir($r) ? borrarArbol($r) : @unlink($r);
    }
    @rmdir($dir);
}

/** Una semilla entera: null si todo coincide, o la lista de diferencias. */
function semilla(int $semilla): ?array
{
    mt_srand($semilla);
    $base = "s$semilla";
    Database::crear($base);
    $b = new Database($base);
    $l = new SQLite3(':memory:');
    $l->exec('PRAGMA foreign_keys = ON');
    $ambos = function (string $q, ?string $enSqlite = null) use ($b, $l): void {
        $b->consultar($q);
        if (!$l->exec($enSqlite ?? $q)) { throw new RuntimeException('SQLite: ' . $l->lastErrorMsg() . " · $q"); }
    };
    $detalle = [];
    $txt = ['ana', 'Bob', 'luis', 'Zoe', 'éva', 'x y', 'a-b', '12', 'nadie'];
    $ambos('CREATE TABLE p (id INTEGER PRIMARY KEY, n TEXT, k INTEGER, v REAL)');
    $ambos('CREATE TABLE h (id INTEGER PRIMARY KEY, pid INTEGER, t TEXT, q INTEGER, FOREIGN KEY (pid) REFERENCES p (id) ON DELETE CASCADE ON UPDATE CASCADE)');
    if ($semilla % 2 === 0) {
        $ambos('CREATE INDEX ix_h_pid ON h (pid)');
        $ambos('CREATE INDEX ix_p_n ON p (n)');
    }
    $lit = static fn($v) => $v === null ? 'NULL' : (is_string($v) ? "'" . str_replace("'", "''", $v) . "'" : (string)$v);
    for ($i = 1; $i <= 150; $i++) {
        $ambos('INSERT INTO p VALUES (' . $i . ', ' . $lit(mt_rand(0, 9) === 0 ? null : $txt[mt_rand(0, 8)]) . ', ' . (mt_rand(0, 6) === 0 ? 'NULL' : mt_rand(0, 20)) . ', ' . (mt_rand(0, 100) / 4) . ')');
    }
    for ($i = 1; $i <= 300; $i++) {
        $ambos('INSERT INTO h VALUES (' . $i . ', ' . mt_rand(1, 150) . ', ' . $lit($txt[mt_rand(0, 8)]) . ', ' . mt_rand(0, 5) . ')');
    }
    $normal = static function (array $filas): array {
        foreach ($filas as &$f) { foreach ($f as &$v) { if (is_float($v) && floor($v) == $v && abs($v) < 1e15) { $v = (int)$v; } } }
        return $filas;
    };
    for ($vuelta = 0; $vuelta < 40; $vuelta++) {
        $t1 = $txt[mt_rand(0, 8)];
        $t2 = $txt[mt_rand(0, 8)];
        $escrituras = [
            "UPDATE p SET k = k + 1 WHERE n = " . $lit($t1),
            "UPDATE h SET q = " . mt_rand(0, 5) . " WHERE t IN (" . $lit($t1) . ', ' . $lit($t2) . ')',
            "DELETE FROM h WHERE t = " . $lit($t1) . ' AND q = ' . mt_rand(0, 5),
            "DELETE FROM p WHERE id = " . mt_rand(1, 160),
            "UPDATE p SET id = id + 1000 WHERE id = " . mt_rand(1, 160),
            "DELETE FROM p WHERE n = " . $lit($t2) . ' AND k > ' . mt_rand(10, 20),
            "INSERT INTO p VALUES (" . (2000 + $vuelta) . ', ' . $lit($t1) . ', ' . mt_rand(0, 20) . ', 1.5)',
            // Con otras tablas (2.9); cada fila con una sola pareja, que en SQLite cuál gana no está dicho
            "UPDATE p SET k = c.n FROM (SELECT pid, COUNT(*) AS n FROM h GROUP BY pid) c WHERE c.pid = p.id AND p.n = " . $lit($t1),
            "UPDATE h SET q = p.k % 6 FROM p WHERE p.id = h.pid AND p.n IN (" . $lit($t1) . ', ' . $lit($t2) . ')',
            ["UPDATE h JOIN p ON p.id = h.pid SET h.t = p.n WHERE p.k < " . ($k = mt_rand(0, 20)) . ' AND p.n IS NOT NULL',
             "UPDATE h SET t = p.n FROM p WHERE p.id = h.pid AND p.k < $k AND p.n IS NOT NULL"],
            ["DELETE FROM h USING p WHERE p.id = h.pid AND p.k > " . ($k = mt_rand(10, 20)), "DELETE FROM h WHERE pid IN (SELECT id FROM p WHERE k > $k)"],
            ["DELETE p FROM p JOIN h ON h.pid = p.id WHERE h.q = 5 AND h.t = " . $lit($t1), "DELETE FROM p WHERE id IN (SELECT pid FROM h WHERE q = 5 AND t = " . $lit($t1) . ')'],
        ];
        $e = $escrituras[mt_rand(0, count($escrituras) - 1)];
        [$q, $qSqlite] = is_array($e) ? $e : [$e, null];
        $ambos($q, $qSqlite);
        $consultas = [
            "SELECT * FROM p WHERE n = " . $lit($t1) . ' ORDER BY id',
            "SELECT id FROM h WHERE t IN (" . $lit($t1) . ', ' . $lit($t2) . ') ORDER BY id',
            "SELECT COUNT(*) AS c FROM p WHERE n = " . $lit($t1),
            "SELECT COUNT(*) AS c FROM h WHERE pid = " . mt_rand(1, 160),
            "SELECT id, n FROM p ORDER BY n, id LIMIT " . mt_rand(1, 30),
            "SELECT id, v FROM p ORDER BY v DESC, id LIMIT " . mt_rand(1, 30) . ' OFFSET ' . mt_rand(0, 5),
            "SELECT k FROM p ORDER BY k LIMIT " . mt_rand(1, 20), "SELECT v FROM p ORDER BY v DESC LIMIT " . mt_rand(1, 20), 
            "SELECT k, COUNT(*) AS c, SUM(v) AS s, MAX(n) AS m FROM p GROUP BY k ORDER BY k",
            "SELECT id FROM p WHERE k >= " . mt_rand(0, 10) . ' AND k <= ' . mt_rand(5, 20) . ' AND n <> ' . $lit($t2) . ' ORDER BY id',
            "SELECT id FROM p WHERE k IN (1, 3, " . mt_rand(0, 20) . ") ORDER BY id",
            "SELECT p.id, COUNT(h.id) AS c FROM p LEFT JOIN h ON h.pid = p.id WHERE p.n = " . $lit($t1) . ' GROUP BY p.id ORDER BY p.id',
            "SELECT id FROM h WHERE " . mt_rand(0, 5) . " < q AND t = " . $lit($t2) . ' ORDER BY id',
        ];
        foreach ($consultas as $c) {
            // SQLite ordena los textos byte a byte; aquí, sin mayúsculas ni acentos:
            // los ORDER BY por texto se comparan como conjuntos
            $r = $l->query($c);
            $esperado = [];
            while ($f = $r->fetchArray(SQLITE3_ASSOC)) { $esperado[] = $f; }
            $obtenido = $b->consultar($c);
            [$e, $o] = [$normal($esperado), $normal($obtenido)];
            if (str_contains($c, 'ORDER BY n')) {
                // Solo el conjunto, con LIMIT no se puede comparar: se mira que tenga las mismas filas sin LIMIT
                if (str_contains($c, 'LIMIT')) { continue; }
                sort($e); sort($o);
            }
            if ($e !== $o) {
                $detalle[] = "vuelta $vuelta, tras «{$q}»: $c · SQLite " . substr(json_encode($e), 0, 200) . ' · aquí ' . substr(json_encode($o), 0, 200);
            }
        }
    }
    return $detalle === [] ? null : $detalle;
}

/**
 * Upserts al azar (2.9): INSERT OR IGNORE, OR REPLACE, REPLACE y ON CONFLICT …
 * DO NOTHING / DO UPDATE de varias filas, sobre una tabla con clave primaria y
 * UNIQUE de texto, con índice en las dos, y una hija con ON DELETE CASCADE.
 * Así se escriben sin cargar la tabla: cada choque se busca en el índice, y una
 * misma sentencia cambia, borra y añade filas. Tras cada una, las dos tablas
 * enteras y una búsqueda por la clave de texto. Null si todo coincide.
 */
function upserts(int $semilla): ?string
{
    mt_srand($semilla);
    $base = "u$semilla";
    Database::crear($base);
    $b = new Database($base);
    $l = new SQLite3(':memory:');
    $l->exec('PRAGMA foreign_keys = ON');
    // Un error tiene que darse en las dos, o en ninguna
    $ambos = static function (string $q) use ($b, $l): ?string {
        try { $b->consultar($q); $aqui = 'bien'; } catch (JsonSQLDB\JsonSqlDbError $e) { $aqui = 'error'; }
        $alli = @$l->exec($q) ? 'bien' : 'error';
        return $aqui === $alli ? null : "aquí $aqui, SQLite $alli: $q";
    };
    foreach (['CREATE TABLE u (id INTEGER PRIMARY KEY, k TEXT UNIQUE, v INTEGER)',
              'CREATE TABLE h (id INTEGER PRIMARY KEY, uid INTEGER, FOREIGN KEY (uid) REFERENCES u (id) ON DELETE CASCADE)',
              'CREATE INDEX ix_uid ON h (uid)'] as $q) {
        $ambos($q);
    }
    $fila = static fn(): string => '(' . mt_rand(1, 300) . ", 'k" . mt_rand(1, 300) . "', " . mt_rand(0, 9) . ')';
    for ($i = 1; $i <= 200; $i++) {
        $ambos('INSERT OR IGNORE INTO u VALUES ' . $fila());
    }
    for ($i = 1; $i <= 300; $i++) {
        $ambos("INSERT INTO h SELECT $i, id FROM u WHERE id = " . mt_rand(1, 300));
    }
    for ($vuelta = 0; $vuelta < 120; $vuelta++) {
        $varias = implode(', ', array_map(static fn() => $fila(), range(1, mt_rand(1, 6))));
        $q = [
            "INSERT OR IGNORE INTO u VALUES $varias",
            "INSERT OR REPLACE INTO u VALUES $varias",
            "REPLACE INTO u VALUES $varias",
            "INSERT INTO u VALUES $varias ON CONFLICT (k) DO UPDATE SET v = v + excluded.v",
            "INSERT INTO u VALUES $varias ON CONFLICT (id) DO UPDATE SET k = excluded.k || 'x', v = excluded.v",
            "INSERT INTO u VALUES $varias ON CONFLICT DO NOTHING",
            "INSERT INTO u VALUES $varias ON CONFLICT (k) DO UPDATE SET v = excluded.v WHERE excluded.v > v",
        ][mt_rand(0, 6)];
        $r = $ambos($q);
        if ($r !== null) {
            return "vuelta $vuelta: $r";
        }
        foreach (['SELECT * FROM u ORDER BY id', 'SELECT * FROM h ORDER BY id', "SELECT id FROM u WHERE k = 'k" . mt_rand(1, 300) . "'"] as $c) {
            $esperado = [];
            $res = $l->query($c);
            while ($f = $res->fetchArray(SQLITE3_ASSOC)) { $esperado[] = $f; }
            if ($b->consultar($c) !== $esperado) {
                return "vuelta $vuelta, tras «{$q}»: $c no da lo mismo";
            }
        }
    }
    return null;
}

/**
 * Cascadas al azar (2.9): ON UPDATE CASCADE en dos niveles (la clave de la
 * hija a la que apuntan las nietas cambia con la del padre), SET NULL que
 * cambia la clave de las nietas, y SET DEFAULT hacia un UNIQUE, que puede
 * chocar con otra fila o con otra hija de la misma sentencia. La hija tiene
 * triggers BEFORE y AFTER UPDATE; en las semillas impares las nietas
 * también, y en las pares las nietas y la hija del SET DEFAULT tienen índice
 * en la clave foránea, así que se leen y se cambian sin cargar su tabla. Un
 * error tiene que darse en las dos o en ninguna, y tras cada sentencia todas
 * las tablas tienen que quedar iguales. Null si todo coincide.
 */
function cascadas(int $semilla): ?string
{
    mt_srand($semilla);
    $base = "c$semilla";
    Database::crear($base);
    $b = new Database($base);
    $l = new SQLite3(':memory:');
    $l->exec('PRAGMA foreign_keys = ON');
    $ambos = static function (string $q) use ($b, $l): ?string {
        try { $b->consultar($q); $aqui = 'bien'; } catch (JsonSQLDB\JsonSqlDbError $e) { $aqui = 'error'; }
        $alli = @$l->exec($q) ? 'bien' : 'error';
        return $aqui === $alli ? null : "aquí $aqui, SQLite $alli: $q";
    };
    $ddl = ['CREATE TABLE p (id INTEGER PRIMARY KEY, n INTEGER)',
            'CREATE TABLE h (id INTEGER PRIMARY KEY, k INTEGER UNIQUE REFERENCES p (id) ON UPDATE CASCADE ON DELETE SET NULL, x INTEGER)',
            'CREATE TABLE g (id INTEGER PRIMARY KEY, hk INTEGER REFERENCES h (k) ON UPDATE CASCADE ON DELETE CASCADE, c INTEGER, UNIQUE (hk, c))',
            'CREATE TABLE d (id INTEGER PRIMARY KEY, pid INTEGER DEFAULT 1 REFERENCES p (id) ON UPDATE SET DEFAULT ON DELETE SET DEFAULT, x INTEGER, UNIQUE (pid, x))',
            'CREATE TABLE lg (n INTEGER PRIMARY KEY, t TEXT)',
            "CREATE TRIGGER h_bu BEFORE UPDATE ON h BEGIN INSERT INTO lg (t) VALUES ('h antes ' || OLD.id || ' ' || COALESCE(OLD.k, '-') || '>' || COALESCE(NEW.k, '-')); END",
            "CREATE TRIGGER h_au AFTER UPDATE ON h BEGIN INSERT INTO lg (t) VALUES ('h después ' || OLD.id || ' ' || COALESCE(OLD.k, '-') || '>' || COALESCE(NEW.k, '-') || ' ' || OLD.x || '>' || NEW.x); END"];
    $ddl = array_merge($ddl, $semilla % 2 === 0 ? ['CREATE INDEX ix_g_hk ON g (hk)', 'CREATE INDEX ix_d_pid ON d (pid)']
        : ["CREATE TRIGGER g_au AFTER UPDATE ON g BEGIN INSERT INTO lg (t) VALUES ('g ' || OLD.id || ' ' || COALESCE(OLD.hk, '-') || '>' || COALESCE(NEW.hk, '-')); END"]);
    foreach ($ddl as $q) {
        if (($r = $ambos($q)) !== null) { return $r; }
    }
    $ambos('INSERT INTO p VALUES ' . implode(', ', array_map(static fn(int $i): string => "($i, " . mt_rand(0, 5) . ')', range(1, 40))));
    $ks = range(1, 40);
    shuffle($ks);
    $ambos('INSERT INTO h VALUES ' . implode(', ', array_map(static fn(int $i): string => "($i, " . ($i % 7 === 0 ? 'NULL' : $ks[$i]) . ', ' . mt_rand(0, 3) . ')', range(1, 30))));
    for ($i = 1; $i <= 60; $i++) {
        $ambos("INSERT INTO g VALUES ($i, " . $ks[mt_rand(1, 30)] . ', ' . mt_rand(0, 9) . ')');
    }
    for ($i = 1; $i <= 50; $i++) {
        $ambos("INSERT INTO d VALUES ($i, " . mt_rand(1, 40) . ', ' . mt_rand(0, 12) . ')');
    }
    for ($vuelta = 0; $vuelta < 60; $vuelta++) {
        $r = mt_rand(1, 45);
        $q = [
            "UPDATE p SET id = id + 1000 WHERE id = $r",
            'UPDATE p SET id = ' . mt_rand(1, 45) . " WHERE id = $r",
            "DELETE FROM p WHERE id = $r",
            'DELETE FROM p WHERE n = ' . mt_rand(0, 5) . " AND id > $r",
            'UPDATE p SET id = id + 2000 WHERE id BETWEEN ' . $r . ' AND ' . ($r + mt_rand(0, 4)),
            'UPDATE h SET k = ' . mt_rand(1, 45) . ' WHERE id = ' . mt_rand(1, 30),
            'UPDATE h SET x = x + 1 WHERE k = ' . $r,
            'DELETE FROM h WHERE x = ' . mt_rand(0, 4) . ' AND id > ' . mt_rand(0, 30),
            "INSERT INTO p VALUES ($r, 0)",
            'INSERT INTO g VALUES (' . (100 + $vuelta) . ', ' . $r . ', ' . mt_rand(0, 9) . ')',
            'INSERT INTO d VALUES (' . (100 + $vuelta) . ', ' . $r . ', ' . mt_rand(0, 12) . ')',
        ][mt_rand(0, 10)];
        if (($dif = $ambos($q)) !== null) {
            return "vuelta $vuelta: $dif";
        }
        foreach (['p', 'h', 'g', 'd', 'lg'] as $t) {
            $res = $l->query($t === 'lg' ? 'SELECT t FROM lg ORDER BY t' : "SELECT * FROM $t ORDER BY id");
            $esperado = [];
            while ($f = $res->fetchArray(SQLITE3_ASSOC)) { $esperado[] = $f; }
            // El orden de los triggers de varias nietas de la misma fila no está dicho: el registro se compara sin él
            $obtenido = $t === 'lg' ? $b->consultar('SELECT t FROM lg') : $b->consultar("SELECT * FROM $t ORDER BY id");
            if ($t === 'lg') {
                sort($obtenido);
                sort($esperado);
            }
            if ($obtenido !== $esperado) {
                return "vuelta $vuelta, tras «{$q}»: $t no da lo mismo: SQLite " . substr(json_encode($esperado), 0, 300) . ' · aquí ' . substr(json_encode($obtenido), 0, 300);
            }
        }
    }
    return null;
}

$semillas = 6;
foreach ($argv as $a) {
    if (preg_match('/^--semillas=(\d+)$/', $a, $m)) { $semillas = max(1, (int)$m[1]); }
}

borrarArbol(JSONSQLDB_DATA_PATH);
@mkdir(JSONSQLDB_DATA_PATH, 0775, true);

echo "\n== Escrituras y consultas al azar contra SQLite, tablas de muchas partes ==\n";
for ($s = 1; $s <= $semillas; $s++) {
    chk("semilla $s" . ($s % 2 === 0 ? ', con índices' : ', sin índices'), function () use ($s) {
        if (!class_exists('SQLite3')) { return null; }
        $d = semilla($s);
        return $d === null ?: count($d) . ' distintas; la primera: ' . $d[0];
    });
}

echo "\n== Upserts al azar contra SQLite, sin cargar la tabla (2.9) ==\n";
for ($s = 1; $s <= $semillas; $s++) {
    chk("semilla $s", function () use ($s) {
        if (!class_exists('SQLite3')) { return null; }
        return upserts($s) ?? true;
    });
}

echo "\n== Cascadas de dos niveles, SET NULL y SET DEFAULT, con triggers, al azar contra SQLite (2.9) ==\n";
for ($s = 1; $s <= $semillas; $s++) {
    chk("semilla $s" . ($s % 2 === 0 ? ', con índices en las claves foráneas' : ', con triggers en las nietas'), function () use ($s) {
        if (!class_exists('SQLite3')) { return null; }
        return cascadas($s) ?? true;
    });
}

echo "\n== Limpieza ==\n";
chk('sin restos', function () {
    borrarArbol(JSONSQLDB_DATA_PATH);
    return !is_dir(JSONSQLDB_DATA_PATH);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
