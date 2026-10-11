<?php
/**
 * Uso interno de tests/f3_escrituras.php (2.9): deshacer una transacción con
 * un ALTER TABLE y escribir después, con la caché en APCu (php -d
 * apc.enable_cli=1). Imprime las columnas que ve una lectura posterior.
 */
declare(strict_types=1);
define('JSONSQLDB_CONEXION_DIRECTA', true);
require __DIR__ . '/../engine/bootstrap.php';
$raiz = $argv[1];
exec('rm -rf ' . escapeshellarg($raiz . '/apcu'));
JsonSQLDB\Database::crear('apcu', $raiz);
$bd = new JsonSQLDB\Database('apcu', $raiz);
$bd->consultar('CREATE TABLE cuentas (id INTEGER PRIMARY KEY, titular TEXT, saldo DOUBLE)');
$bd->consultar("INSERT INTO cuentas VALUES (1, 'Ana', 500)");
try {
    $bd->transaccion(static function ($bd): void {
        $bd->consultar('ALTER TABLE cuentas ADD COLUMN nota TEXT');
        $bd->consultar('SELECT * FROM cuentas');
        throw new RuntimeException('no');
    });
} catch (RuntimeException) {
}
$bd->consultar("INSERT INTO cuentas VALUES (3, 'Eva', 5)");
echo json_encode(array_keys((new JsonSQLDB\Database('apcu', $raiz))->consultar('SELECT * FROM cuentas WHERE id = 3')[0] ?? []));
exec('rm -rf ' . escapeshellarg($raiz . '/apcu'));
