<?php
/**
 * Uso interno de tests/f24_versiones_anteriores.php: con el motor de una
 * versión anterior, crea la base «vieja» en <datos> como la habría creado esa
 * versión (con su propio config.php) y guarda en <datos>/esperado.json lo que
 * dan unas consultas. Uso: php generar.php <motor viejo> <datos>
 */
[$_, $motor, $datos] = $argv;
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_DATA_PATH', $datos);
define('JSONSQLDB_LOG_ACTIVO', false);
if (is_file("$motor/config.php")) { require "$motor/config.php"; }   // su tamaño de parte, como en su día
require "$motor/engine/bootstrap.php";
use JsonSQLDB\Database;
@mkdir($datos, 0777, true);
Database::crear('vieja', $datos);
$bd = new Database('vieja', $datos);
$hechas = [];
$probar = static function (string $sql, array $p = []) use ($bd, &$hechas) {
    try { $bd->consultar($sql, $p); $hechas[] = $sql; return true; } catch (Throwable $e) { $hechas[] = '# ' . substr($e->getMessage(), 0, 80) . ' <- ' . substr($sql, 0, 60); return false; }
};
$probar('CREATE TABLE clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL UNIQUE, ciudad TEXT, saldo DECIMAL(10,2), alta DATE)');
$probar('CREATE TABLE pedidos (id INTEGER PRIMARY KEY, cliente_id INTEGER REFERENCES clientes(id), total DOUBLE)');
$probar('CREATE TABLE auditoria (id INTEGER PRIMARY KEY AUTOINCREMENT, que TEXT)');
for ($i = 1; $i <= 12000; $i += 500) {
    $v = [];
    for ($j = $i; $j < $i + 500; $j++) { $v[] = "('Cliente $j', 'C" . ($j % 7) . "', " . ($j % 1000) . '.25, \'2025-0' . (1 + $j % 9) . '-1' . ($j % 9) . "')"; }
    $probar('INSERT INTO clientes (nombre, ciudad, saldo, alta) VALUES ' . implode(',', $v));
}
$v = []; for ($j = 1; $j <= 300; $j++) { $v[] = "($j, " . ($j * 37 % 12000 + 1) . ", " . ($j * 1.5) . ')'; }
$probar('INSERT INTO pedidos VALUES ' . implode(',', $v));
$probar('CREATE INDEX ix_ciudad ON clientes (ciudad)');
$probar('CREATE VIEW ricos AS SELECT id, nombre, saldo FROM clientes WHERE saldo > 990');
$probar("CREATE TRIGGER t_alta AFTER INSERT ON clientes BEGIN INSERT INTO auditoria (que) VALUES ('alta ' || NEW.nombre); END");
$probar('DELETE FROM clientes WHERE id > 11990');                      // el autoincremento no debe volver atrás
$probar('DELETE FROM clientes WHERE id IN (50, 51, 52)');
$probar("UPDATE clientes SET saldo = 999.99, ciudad = 'C9' WHERE id = 7777");
$probar("INSERT INTO clientes (nombre, ciudad, saldo) VALUES ('Tras el borrado', 'C1', 5)");
// Una tabla que se queda justo en el límite de una parte: la 2.8 dejaba en el
// estado la revisión de la parte que se iba, sin su fichero
$probar('CREATE TABLE cola (id INTEGER PRIMARY KEY, x TEXT)');
$v = []; for ($j = 1; $j <= 2500; $j++) { $v[] = "($j, 'x$j')"; }
$probar('INSERT INTO cola VALUES ' . implode(',', $v));
$probar('DELETE FROM cola WHERE id > 1200');
$probar("INSERT INTO cola VALUES (5000, 'y')");
$probar('DELETE FROM cola WHERE id > 1000');
$consultas = [
    'SELECT COUNT(*) AS n, SUM(id) AS s FROM cola',
    'SELECT COUNT(*) AS n, SUM(id) AS s FROM clientes',
    'SELECT * FROM clientes WHERE id = 7777',
    'SELECT ciudad, COUNT(*) AS n FROM clientes GROUP BY ciudad ORDER BY ciudad',
    "SELECT id, nombre FROM clientes WHERE ciudad = 'C3' ORDER BY id LIMIT 5",
    'SELECT c.nombre, p.total FROM pedidos p JOIN clientes c ON c.id = p.cliente_id WHERE p.id <= 5 ORDER BY p.id',
    'SELECT * FROM ricos ORDER BY id LIMIT 3',
    'SELECT COUNT(*) AS n FROM auditoria',
    'SELECT MAX(id) AS m FROM clientes',
];
$esperado = [];
foreach ($consultas as $q) {
    try { $esperado[$q] = $bd->consultar($q); } catch (Throwable $e) { $esperado[$q] = ['ERROR' => $e->getMessage()]; }
}
file_put_contents("$datos/esperado.json", json_encode(['hechas' => $hechas, 'esperado' => $esperado], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo count(array_filter($hechas, fn($h) => $h[0] !== '#')), ' sentencias bien, ', count(array_filter($hechas, fn($h) => $h[0] === '#')), " no admitidas\n";
