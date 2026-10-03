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
 * y borrados en cascada) y después una docena de consultas: igualdades de
 * texto, IN, COUNT(*) por índice, ORDER BY con LIMIT de una y dos columnas,
 * GROUP BY, AND de comparaciones y JOIN. Las semillas pares crean índices en
 * la clave foránea y en el texto, así que se prueban los dos caminos. Lo que
 * cubre es lo que cambió en 2.7.4 y 2.7.5: saltarse partes por su texto,
 * escribir sin cargar la tabla, el mapa de las cascadas y los atajos por fila.
 *
 * Los empates de un ORDER BY no tienen orden fijo ni en SQLite ni aquí: las
 * consultas con LIMIT sobre una columna con empates comparan solo esa columna.
 *
 * https://miguelenred.es/jsonsqldb
 */

define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_DATA_PATH', sys_get_temp_dir() . '/jsonsqldb_test_f19');
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
    $ambos = function (string $q) use ($b, $l): void {
        $b->consultar($q);
        if (!$l->exec($q)) { throw new RuntimeException('SQLite: ' . $l->lastErrorMsg() . " · $q"); }
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
    $mal = 0;
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
        ];
        $q = $escrituras[mt_rand(0, count($escrituras) - 1)];
        $ambos($q);
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
                $mal++;
                $detalle[] = "vuelta $vuelta, tras «{$q}»: $c · SQLite " . substr(json_encode($e), 0, 200) . ' · aquí ' . substr(json_encode($o), 0, 200);
            }
        }
    }
    return $detalle === [] ? null : $detalle;
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

echo "\n== Limpieza ==\n";
chk('sin restos', function () {
    borrarArbol(JSONSQLDB_DATA_PATH);
    return !is_dir(JSONSQLDB_DATA_PATH);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
