<?php
declare(strict_types=1);

/**
 * Comparación con SQLite: las mismas consultas y escrituras, con los mismos
 * datos, en jsonSQLDB y en SQLite, y los resultados tienen que coincidir.
 * Ejecutar: php tests/f12_contra_sqlite.php
 *
 * Se quedan fuera, a propósito, las diferencias documentadas en
 * docs/02-queries.md: `7 / 2` da 3.5 aquí y 3 en SQLite; '5' = 5 es cierto
 * aquí; ROUND redondea como PHP; ORDER BY usa el cotejamiento configurado.
 * Cada consulta se ejecuta dos veces en jsonSQLDB, con índices y sin ellos.
 * Usa la extensión sqlite3 y no PDO: PDO con SQLite devuelve los números
 * como texto hasta PHP 8.1, y la comparación saldría distinta en 8.0 por eso.
 * Sin la extensión, se salta y lo dice.
 *
 * https://miguelenred.es/jsonsqldb
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_CACHE_RESULTADOS', 0);
require dirname(__DIR__) . '/engine/bootstrap.php';

use JsonSQLDB\Database;

if (!class_exists('SQLite3')) {
    echo "Sin la extensión sqlite3: esta prueba se salta.\nOK: 0   FALLOS: 0\n";
    exit(0);
}

/** Una sentencia en SQLite, con parámetros de su tipo. */
function enSqlite(SQLite3 $s, string $q, array $params = []): array
{
    $st = $s->prepare($q);
    foreach ($params as $i => $v) {
        $st->bindValue($i + 1, $v, $v === null ? SQLITE3_NULL
            : (is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : SQLITE3_TEXT)));
    }
    $r = $st->execute();
    $filas = [];
    // Solo si devuelve columnas: pedir filas a un CREATE o un INSERT lo
    // vuelve a ejecutar (así funciona fetchArray() de SQLite3)
    while ($r !== false && $r->numColumns() > 0 && ($f = $r->fetchArray(SQLITE3_ASSOC)) !== false) {
        $filas[] = $f;
    }
    $st->close();
    return $filas;
}

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f12';
$ok = 0; $ko = 0;

$consultas = <<<'SQL'
SELECT id FROM t WHERE a > 5
SELECT id FROM t WHERE a >= 5 AND b = 'ana'
SELECT id FROM t WHERE a < 0 OR d = 2
SELECT id FROM t WHERE NOT (a > 5)
SELECT id FROM t WHERE a IS NULL
SELECT id FROM t WHERE a IS NOT NULL AND c IS NULL
SELECT id FROM t WHERE a BETWEEN 2 AND 7
SELECT id FROM t WHERE a NOT BETWEEN 2 AND 7
SELECT id FROM t WHERE a IN (1, 3, 5)
SELECT id FROM t WHERE a NOT IN (1, 3, 5)
SELECT id FROM t WHERE a NOT IN (1, NULL)
SELECT id FROM t WHERE a IN (1, NULL)
SELECT id FROM t WHERE b LIKE 'a%'
SELECT id FROM t WHERE b LIKE '%a'
SELECT id FROM t WHERE b LIKE '_n_'
SELECT id FROM t WHERE b NOT LIKE 'a%'
SELECT id FROM t WHERE b = ''
SELECT id FROM t WHERE c > 10.5
SELECT id FROM t WHERE c = -3.0
SELECT id FROM t WHERE a = d
SELECT id FROM t WHERE a <> d
SELECT id FROM t WHERE a + d > 10
SELECT id FROM t WHERE a * 2 = d + 4
SELECT id FROM t WHERE -a > 3
SELECT id FROM t WHERE (a > 3 AND d IS NULL) OR (a < 0 AND d IS NOT NULL)
SELECT id FROM t WHERE COALESCE(a, 100) > 50
SELECT id FROM t WHERE CASE WHEN a > 10 THEN 1 ELSE 0 END = 1
SELECT id FROM t WHERE b IN ('ana', 'Bea', NULL)
SELECT id FROM t WHERE b LIKE 'A%'
SELECT id FROM t WHERE b LIKE '%a%' AND b NOT LIKE '%n%'
SELECT id FROM t WHERE b > 'c' AND b < 'f'
SELECT id FROM t WHERE c BETWEEN -10 AND 10.5
SELECT id, a + d AS s FROM t ORDER BY id
SELECT id, a - d AS s FROM t ORDER BY id
SELECT id, a * d AS s FROM t ORDER BY id
SELECT id, c * 2 AS s FROM t ORDER BY id
SELECT id, a % 3 AS m FROM t WHERE a IS NOT NULL ORDER BY id
SELECT id, COALESCE(b, 'nada') AS b FROM t ORDER BY id
SELECT id, UPPER(b) AS u, LOWER(b) AS l FROM t ORDER BY id
SELECT id, LENGTH(b) AS n FROM t ORDER BY id
SELECT id, SUBSTR(b, 2, 2) AS s FROM t ORDER BY id
SELECT id, b || '-' || a AS s FROM t ORDER BY id
SELECT id, ABS(a) AS x FROM t ORDER BY id
SELECT id, ROUND(c) AS r FROM t ORDER BY id
SELECT id, CASE WHEN a IS NULL THEN 'n' WHEN a < 0 THEN 'neg' ELSE 'pos' END AS k FROM t ORDER BY id
SELECT id, NULLIF(a, 3) AS x FROM t ORDER BY id
SELECT 1 / 0 AS a, 1 % 0 AS b
SELECT NULL + 1 AS a, NULL || 'x' AS b, 'x' || NULL AS c
SELECT SUBSTR('abcdef', -2) AS a, SUBSTR('abcdef', 0, 2) AS b, SUBSTR('abcdef', 3) AS c, SUBSTR('abcdef', 2, 100) AS d
SELECT LENGTH(12345) AS a, LENGTH('') AS b, LENGTH(NULL) AS c
SELECT TRIM('  x  ') AS a, LTRIM('  x') AS b, RTRIM('x  ') AS c, REPLACE('abab', 'b', 'c') AS d, INSTR('hola', 'la') AS e
SELECT CASE WHEN 1 = 2 THEN 'x' END AS a, COALESCE(NULL, NULL) AS b, NULLIF(1, 1) AS c
SELECT CAST(c AS INTEGER) AS i, CAST(a AS TEXT) AS s FROM t WHERE id < 6 ORDER BY id
SELECT COUNT(*) AS n FROM t
SELECT COUNT(a) AS n FROM t
SELECT COUNT(DISTINCT a) AS n FROM t
SELECT SUM(a) AS s, MIN(a) AS mi, MAX(a) AS ma FROM t
SELECT AVG(a) AS m FROM t
SELECT SUM(c) AS s FROM t
SELECT SUM(a) AS s FROM t WHERE a > 1000
SELECT COUNT(*) AS n, SUM(a) AS s, AVG(a) AS m FROM t WHERE a > 1000
SELECT MAX(b) AS m, MIN(b) AS mi FROM t
SELECT MAX(a) AS m FROM t WHERE a IS NULL
SELECT AVG(d) AS m FROM t
SELECT d, COUNT(*) AS n FROM t GROUP BY d
SELECT d, SUM(a) AS s FROM t GROUP BY d
SELECT d, AVG(c) AS m FROM t GROUP BY d
SELECT b, COUNT(*) AS n FROM t GROUP BY b
SELECT d, COUNT(*) AS n FROM t GROUP BY d HAVING COUNT(*) > 15
SELECT d, SUM(a) AS s FROM t GROUP BY d HAVING SUM(a) > 20
SELECT d, MAX(a) - MIN(a) AS r FROM t GROUP BY d
SELECT d, COUNT(DISTINCT b) AS n FROM t GROUP BY d
SELECT a, d, COUNT(*) AS n FROM t GROUP BY a, d
SELECT d * 2 AS g, COUNT(*) AS n FROM t GROUP BY d * 2
SELECT a % 3 AS g, COUNT(*) AS n FROM t WHERE a IS NOT NULL GROUP BY a % 3
SELECT COUNT(*) AS n FROM t HAVING COUNT(*) > 5
SELECT d, COUNT(*) AS n FROM t WHERE a > 0 GROUP BY d HAVING n > 3 ORDER BY d
SELECT d, SUM(a) AS s FROM t GROUP BY d HAVING s > 20 ORDER BY d
SELECT d, COUNT(*) AS n FROM t GROUP BY d HAVING n > 3 AND d IS NOT NULL ORDER BY n DESC, d
SELECT d AS grupo, COUNT(*) AS n FROM t GROUP BY d HAVING grupo > 0 ORDER BY grupo
SELECT DISTINCT d FROM t
SELECT DISTINCT a, d FROM t
SELECT DISTINCT b FROM t WHERE b IS NOT NULL
SELECT DISTINCT d FROM t ORDER BY d
SELECT id FROM t ORDER BY a, id
SELECT id FROM t ORDER BY a DESC, id
SELECT id FROM t ORDER BY a, id LIMIT 5
SELECT id FROM t ORDER BY a, id LIMIT 5 OFFSET 3
SELECT id FROM t ORDER BY c DESC, id LIMIT 7
SELECT id FROM t ORDER BY d, a DESC, id
SELECT id, a FROM t WHERE a IS NOT NULL ORDER BY a DESC, id LIMIT 3
SELECT d, COUNT(*) AS n FROM t GROUP BY d ORDER BY n DESC, d
SELECT id FROM t ORDER BY a + d, id LIMIT 10
SELECT id, a AS v FROM t ORDER BY v DESC, id LIMIT 5
SELECT id FROM t ORDER BY 1 DESC LIMIT 3
SELECT d, COUNT(*) AS n FROM t GROUP BY d ORDER BY 2 DESC, 1
SELECT a, b FROM t WHERE a IS NOT NULL ORDER BY 2, 1 LIMIT 8
SELECT a + d AS s, id FROM t ORDER BY 1, 2 LIMIT 6
SELECT id FROM t ORDER BY id LIMIT 0
SELECT id FROM t ORDER BY id LIMIT 5 OFFSET 1000
SELECT t.id AS ti, u.id AS ui FROM t JOIN u ON u.tid = t.id
SELECT t.id AS ti, u.id AS ui FROM t LEFT JOIN u ON u.tid = t.id
SELECT t.id AS ti, u.id AS ui FROM t LEFT JOIN u ON u.tid = t.id WHERE u.id IS NULL
SELECT t.id AS ti, u.id AS ui FROM t JOIN u ON u.tid = t.id AND u.x > 3
SELECT t.id AS ti, u.id AS ui FROM t LEFT JOIN u ON u.tid = t.id AND u.x > 3
SELECT t.id AS ti, u.id AS ui FROM t JOIN u ON u.tid = t.id WHERE t.a > 5
SELECT t.id AS ti, u.id AS ui FROM t LEFT JOIN u ON u.tid = t.id WHERE t.a > 5
SELECT t.id AS ti, u.id AS ui FROM t JOIN u ON u.tid = t.id WHERE t.id = 7
SELECT t.id AS ti, u.id AS ui FROM t LEFT JOIN u ON u.tid = t.id WHERE t.id BETWEEN 3 AND 6
SELECT t.d, COUNT(u.id) AS n FROM t LEFT JOIN u ON u.tid = t.id GROUP BY t.d
SELECT t.d, SUM(u.x) AS s FROM t JOIN u ON u.tid = t.id GROUP BY t.d
SELECT t.id AS ti, u.id AS ui FROM t JOIN u ON u.x = t.d
SELECT t.id AS ti, u.id AS ui FROM t JOIN u ON u.tid = t.id AND u.x = t.d
SELECT u.id AS ui, t.id AS ti FROM u LEFT JOIN t ON t.id = u.tid WHERE t.a IS NULL
SELECT a.id AS ai, b.id AS bi FROM t a JOIN t b ON b.a = a.d
SELECT t.id FROM t, u WHERE u.tid = t.id AND u.y = 'p'
SELECT t.id AS ti, u.id AS ui FROM t CROSS JOIN u WHERE t.id < 3 AND u.id < 3
SELECT COUNT(*) AS n FROM t JOIN u ON u.tid = t.id WHERE u.y IS NULL
SELECT COUNT(*) AS n FROM t LEFT JOIN u ON u.tid = t.id WHERE u.y = 'p' OR u.y IS NULL
SELECT t.d, u.y, COUNT(*) AS n FROM t JOIN u ON u.tid = t.id GROUP BY t.d, u.y
SELECT id FROM t WHERE id IN (SELECT tid FROM u WHERE x > 5)
SELECT id FROM t WHERE id NOT IN (SELECT tid FROM u WHERE tid IS NOT NULL)
SELECT id FROM t WHERE id NOT IN (SELECT tid FROM u)
SELECT id FROM t WHERE EXISTS (SELECT 1 FROM u WHERE u.tid = t.id)
SELECT id FROM t WHERE NOT EXISTS (SELECT 1 FROM u WHERE u.tid = t.id)
SELECT id FROM t WHERE a > (SELECT AVG(a) FROM t)
SELECT id, (SELECT COUNT(*) FROM u WHERE u.tid = t.id) AS n FROM t ORDER BY id
SELECT id FROM t WHERE a = (SELECT MAX(a) FROM t)
SELECT id FROM t WHERE a IN (SELECT x FROM u)
SELECT d, n FROM (SELECT d, COUNT(*) AS n FROM t GROUP BY d) s WHERE n > 10
SELECT t.id AS ti FROM t WHERE (SELECT COUNT(*) FROM u WHERE u.tid = t.id) >= 2 ORDER BY t.id
SELECT t.id AS ti, (SELECT MAX(x) FROM u WHERE u.tid = t.id) AS m FROM t ORDER BY t.id
SELECT id FROM t WHERE id IN (SELECT tid FROM u WHERE x IN (SELECT d FROM t WHERE d IS NOT NULL))
SELECT id FROM t WHERE a IN (SELECT a FROM t WHERE d = 1) AND d <> 1
SELECT id, a FROM t WHERE a = (SELECT MIN(x) FROM u)
SELECT a FROM t WHERE a < 3 UNION SELECT x FROM u WHERE x < 3
SELECT a FROM t WHERE a < 3 UNION ALL SELECT x FROM u WHERE x < 3
SELECT d FROM t UNION SELECT x FROM u
SELECT d FROM t UNION SELECT x FROM u ORDER BY 1
SQL;

$escrituras = [
    'UPDATE t SET a = a + 1, d = a WHERE id < 10',
    'UPDATE t SET a = NULL WHERE d = 3',
    "UPDATE t SET b = UPPER(b) WHERE b LIKE 'a%'",
    'DELETE FROM t WHERE a IN (SELECT a FROM t WHERE d = 1)',
    'INSERT INTO u (id, tid, x, y) SELECT id + 100, id, a, b FROM t WHERE a > 5',
    'UPDATE t SET a = (SELECT MAX(x) FROM u WHERE u.tid = t.id) WHERE id > 30',
    'UPDATE u SET x = x * 2 WHERE tid IN (SELECT id FROM t WHERE d = 0)',
    'DELETE FROM u WHERE NOT EXISTS (SELECT 1 FROM t WHERE t.id = u.tid)',
    'UPDATE t SET c = c / 2, a = a * 2 WHERE c > 0',
    'DELETE FROM t WHERE id % 7 = 0',
    'UPDATE t SET d = CASE WHEN a > 5 THEN 1 ELSE 0 END',
    "INSERT INTO t (id, a, b) VALUES (500, 1, 'x'), (501, NULL, NULL)",
    'UPDATE t SET id = id + 1000 WHERE id > 35',
    'DELETE FROM u WHERE x > (SELECT AVG(x) FROM u)',
    'UPDATE u SET y = (SELECT b FROM t WHERE t.id = u.tid)',
    'DELETE FROM t WHERE b IS NULL AND a IS NULL',
];

function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach ((array)scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        is_dir("$dir/$f") ? borrarArbol("$dir/$f") : @unlink("$dir/$f");
    }
    @rmdir($dir);
}

/** Las dos bases, con los mismos datos. Con $indices, jsonSQLDB lleva índices en las columnas de filtro. */
function preparar(string $raiz, bool $indices, int $filasT, int $filasU): array {
    borrarArbol($raiz);
    mkdir($raiz, 0775, true);
    Database::crear('b', $raiz);
    $j = new Database('b', $raiz);
    $s = new SQLite3(':memory:');
    $s->enableExceptions(true);
    foreach (['CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b VARCHAR(20), c DOUBLE, d INTEGER)',
              'CREATE TABLE u (id INTEGER PRIMARY KEY, tid INTEGER, x INTEGER, y VARCHAR(10))'] as $q) {
        $j->consultar($q);
        enSqlite($s, $q);
    }
    if ($indices) {
        foreach (['CREATE INDEX ia ON t (a)', 'CREATE INDEX ib ON t (b)', 'CREATE INDEX id2 ON t (d)',
                  'CREATE INDEX iut ON u (tid)', 'CREATE INDEX iux ON u (x)'] as $q) {
            $j->consultar($q);
        }
    }
    mt_srand(42);
    $pal = ['ana', 'Bea', 'carl', 'dani', 'Eva', 'fer', '', 'zoe', 'ana b'];
    for ($i = 1; $i <= $filasT; $i++) {
        $f = [$i, mt_rand(0, 9) ? mt_rand(-5, 20) : null, mt_rand(0, 7) ? $pal[mt_rand(0, 8)] : null,
              mt_rand(0, 6) ? mt_rand(-300, 900) / 10 : null, mt_rand(0, 2) ? mt_rand(0, 3) : null];
        $j->consultar('INSERT INTO t VALUES (?,?,?,?,?)', $f);
        enSqlite($s, 'INSERT INTO t VALUES (?,?,?,?,?)', $f);
    }
    for ($i = 1; $i <= $filasU; $i++) {
        $f = [$i, mt_rand(0, 8) ? mt_rand(1, $filasT + 10) : null, mt_rand(-3, 9), mt_rand(0, 4) ? ['p', 'q', 'r'][mt_rand(0, 2)] : null];
        $j->consultar('INSERT INTO u VALUES (?,?,?,?)', $f);
        enSqlite($s, 'INSERT INTO u VALUES (?,?,?,?)', $f);
    }
    return [$j, $s];
}

/** Filas como texto comparable: números redondeados, el orden solo si la consulta lo fija. */
function comparable(array $filas, bool $ordenadas): array {
    $o = [];
    foreach ($filas as $f) {
        $o[] = json_encode(array_map(static fn($v) => is_int($v) || is_float($v) ? round((float)$v, 6) : $v, array_values($f)));
    }
    if (!$ordenadas) { sort($o); }
    return $o;
}

function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    $r = $fn();
    if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
    else { $ko++; echo "  FALLO $titulo -> $r\n"; }
}

$lista = array_values(array_filter(array_map('trim', explode("\n", $consultas)), static fn($l) => $l !== ''));

foreach ([false, true] as $indices) {
    echo "\n== Consultas " . ($indices ? 'con índices' : 'sin índices') . ' (' . count($lista) . ") ==\n";
    [$j, $s] = preparar($raiz, $indices, 60, 80);
    chk('los resultados coinciden con SQLite', function () use ($j, $s, $lista) {
        $mal = [];
        foreach ($lista as $q) {
            $ordenada = stripos($q, 'ORDER BY') !== false;
            try { $a = comparable($j->consultar($q), $ordenada); } catch (Throwable $e) { $a = ['ERROR ' . $e->getMessage()]; }
            try { $b = comparable(enSqlite($s, $q), $ordenada); } catch (Throwable $e) { $b = ['ERROR ' . $e->getMessage()]; }
            if ($a !== $b) { $mal[] = $q; }
        }
        return $mal === [] ?: count($mal) . ' distinta(s), la primera: ' . $mal[0];
    });
    unset($j);

    echo "\n== Escrituras " . ($indices ? 'con índices' : 'sin índices') . ' (' . count($escrituras) . ") ==\n";
    [$j, $s] = preparar($raiz, $indices, 40, 50);
    chk('después de cada una, las dos tablas son iguales que en SQLite', function () use ($j, $s, $escrituras) {
        foreach ($escrituras as $q) {
            $ej = $es = null;
            try { $j->consultar($q); } catch (Throwable $e) { $ej = $e->getMessage(); }
            try { enSqlite($s, $q); } catch (Throwable $e) { $es = $e->getMessage(); }
            if (($ej === null) !== ($es === null)) { return "$q: jsonSQLDB " . ($ej ?? 'bien') . ', SQLite ' . ($es ?? 'bien'); }
            foreach (['t', 'u'] as $tb) {
                $a = comparable($j->consultar("SELECT * FROM $tb ORDER BY id"), true);
                $b = comparable(enSqlite($s, "SELECT * FROM $tb ORDER BY id"), true);
                if ($a !== $b) { return "tras '$q', la tabla $tb es distinta"; }
            }
        }
        return true;
    });
    unset($j);
}

borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
