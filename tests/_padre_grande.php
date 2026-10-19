<?php
/**
 * Uso interno de tests/f3_escrituras.php (2.10): en la carpeta <raíz>, una
 * tabla padre de 60.000 filas que no cabe en 40 MB y una hija con índice en
 * la clave foránea. Un UPDATE de todas sus claves con ON UPDATE CASCADE hacia
 * la hija (que tiene un trigger, y por eso se vuelve a mirar su padre) y un
 * REPLACE de una fila padre no la cargan entera. Escribe en JSON qué pasó.
 */
declare(strict_types=1);
define('JSONSQLDB_CONEXION_DIRECTA', true);
require __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;

$dir = $argv[1];
@mkdir($dir, 0775, true);
Database::crear('b', $dir);
$bd = new Database('b', $dir);
$bd->consultar('CREATE TABLE p (id INTEGER PRIMARY KEY, v TEXT)');
$bd->consultar('CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON UPDATE CASCADE)');
$bd->consultar('CREATE INDEX ih ON h (p)');
$bd->consultar('CREATE TABLE lg (x INTEGER)');
$texto = str_repeat('x', 150);
for ($i = 1; $i <= 60000; $i += 5000) {
    $bd->consultar('INSERT INTO p VALUES ' . implode(',', array_map(static fn(int $j): string => "($j, '$texto')", range($i, $i + 4999))));
}
$bd->consultar('INSERT INTO h VALUES ' . implode(',', array_map(static fn(int $i): string => '(' . $i . ', ' . ($i * 150) . ')', range(1, 300))));
$bd->consultar('CREATE TRIGGER th AFTER UPDATE ON h WHEN NEW.id < 0 BEGIN INSERT INTO lg VALUES (NEW.id); END');
unset($bd);
$bd = new Database('b', $dir);
ini_set('memory_limit', '40M');
$r = [];
foreach (["REPLACE INTO p VALUES (5, 'y')", 'UPDATE p SET id = id + 1000000'] as $q) {
    try {
        $bd->consultar($q);
        $r[] = 'ok';
    } catch (JsonSQLDB\JsonSqlDbError $e) {
        $r[] = $e->getMessage();
    }
}
$r[] = $bd->consultar('SELECT COUNT(*) AS n FROM h WHERE p > 1000000')[0]['n'];
unset($bd);
Database::borrar('b', $dir);
@rmdir($dir);
echo json_encode($r, JSON_UNESCAPED_UNICODE);
