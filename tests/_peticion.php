<?php
declare(strict_types=1);

/**
 * Lanza una petición contra el endpoint de la API simulando el entorno web.
 * Uso interno de tests/f4_api.php:  php _peticion.php '<json del POST>' '<raiz de datos>'
 */
$post = json_decode($argv[1] ?? '[]', true) ?: [];
$raiz = $argv[2] ?? sys_get_temp_dir();

define('JSONSQLDB_DATA_PATH', $raiz);
define('JSONSQLDB_LOG_PATH',  $raiz . '/logs');
define('API_ESTADO_PATH',     $raiz . '/api');
define('JSONSQLDB_API_CONFIG', __DIR__ . '/_config_api.php');

$_SERVER['REQUEST_METHOD']  = $post['__metodo'] ?? 'POST';
$_SERVER['REMOTE_ADDR']     = '10.0.0.7';
$_SERVER['HTTP_USER_AGENT'] = 'pruebas-jsonsqldb';
unset($post['__metodo']);

$_POST = $post;
$_SERVER['CONTENT_LENGTH'] = (string)strlen(http_build_query($post));

require __DIR__ . '/../api/jsonsqldb_api.php';
