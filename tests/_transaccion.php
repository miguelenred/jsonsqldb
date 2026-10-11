<?php
/**
 * Uso interno de tests/f3_escrituras.php (2.9): abre una transacción en la
 * base 'tx' de <raíz>, cambia cosas, avisa creando <raíz>/listo y se queda
 * esperando, para que la prueba lo mate con -9 a mitad.
 */
declare(strict_types=1);
define('JSONSQLDB_CONEXION_DIRECTA', true);
require __DIR__ . '/../engine/bootstrap.php';
$raiz = $argv[1];
(new JsonSQLDB\Database('tx', $raiz))->transaccion(static function ($bd) use ($raiz): void {
    $bd->consultar('UPDATE cuentas SET saldo = 0 WHERE id = 1');
    $bd->consultar("INSERT INTO cuentas VALUES (9, 'Intruso', 999)");
    $bd->consultar('CREATE TABLE temporal (x INTEGER)');
    file_put_contents($raiz . '/listo', '1');
    sleep(60);
});
