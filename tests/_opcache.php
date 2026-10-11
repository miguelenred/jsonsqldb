<?php
/**
 * Uso interno de tests/f3_escrituras.php (2.9): el modo OPcache de la caché
 * de partes, en un proceso con OPcache activo (php -d opcache.enable_cli=1).
 * Imprime un JSON con lo que pasa; con «sin» como segundo argumento, con
 * JSONSQLDB_CACHE_OPCACHE = false.
 */
declare(strict_types=1);
[$_, $raiz] = $argv;
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_CACHE_RESULTADOS', 0);
if (($argv[2] ?? '') === 'sin') {
    define('JSONSQLDB_CACHE_OPCACHE', false);
}
require __DIR__ . '/../engine/bootstrap.php';
$bd = new JsonSQLDB\Database('opc', $raiz);
// Los .php de las partes; el del estado de la tabla (e.*.php, 2.9) se cuenta aparte
// (por su prefijo «e.»: el nombre de las partes empieza por un md5, que también puede empezar por «e»)
$php = static fn(): array => array_filter(array_map('basename', (array)glob("$raiz/opc/.cache/*.php")), static fn($f) => !str_starts_with($f, 'e.'));
$antes = $bd->consultar('SELECT * FROM t ORDER BY id');                 // lee las partes: ninguna lleva 2 s
$recien = count($php());
foreach ((array)glob("$raiz/opc/t*.json") as $f) {
    touch((string)$f, time() - 60);                                     // sus datos ya son estables
}
$bd = new JsonSQLDB\Database('opc', $raiz);
$bd->consultar('SELECT COUNT(*) FROM t WHERE x <> \'nada\'');           // lee las 5 partes: 4 pasan a .php
$tras = count($php());
$estado = count((array)glob("$raiz/opc/.cache/e.*.php"));               // su rev.json también es estable
$bd = new JsonSQLDB\Database('opc', $raiz);
$con = $bd->consultar('SELECT * FROM t ORDER BY id');                    // ya con OPcache
$bd->consultar("UPDATE t SET x = 'cambiada' WHERE id = 1");             // reescribe la parte 1
$quedan = $php();
echo json_encode(['recien' => $recien, 'tras' => $tras, 'estado' => $estado, 'iguales' => $antes === $con, 'quedan' => count($quedan),
                  'tmp' => count((array)glob("$raiz/opc/.cache/*.tmp"))]);
