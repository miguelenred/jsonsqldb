<?php
/**
 * Auxiliar de f3_escrituras: el estado de una tabla guardado en OPcache (2.9)
 * no se usa si la tabla cambió, y DROP TABLE lo borra.
 * Uso: php -d opcache.enable_cli=1 _estado_cache.php RAIZ PASO
 */
declare(strict_types=1);

[, $raiz, $paso] = $argv;
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_LOG_ACTIVO', false);
define('JSONSQLDB_CACHE_RESULTADOS', 0);
require __DIR__ . '/../engine/bootstrap.php';
$bd = new JsonSQLDB\Database('est', $raiz);
if ($paso === 'escribir') {
    $bd->consultar("INSERT INTO t (x) VALUES ('nueva')");
}
if ($paso === 'borrar') {
    $bd->consultar('SELECT COUNT(*) FROM t');
    $antes = count((array)glob("$raiz/est/.cache/e.*.php"));
    $bd->consultar('DROP TABLE t');
    echo json_encode(['antes' => $antes, 'estado' => count((array)glob("$raiz/est/.cache/e.*.php"))]);
    exit;
}
echo json_encode(['n' => $bd->consultar('SELECT COUNT(DISTINCT id) AS n FROM t')[0]['n'],
                  'estado' => count((array)glob("$raiz/est/.cache/e.*.php"))]);
