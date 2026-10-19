<?php
declare(strict_types=1);

/**
 * Lanza una petición contra el endpoint de la API simulando el entorno web.
 * Uso interno de tests/f4_api.php:  php _peticion.php '<json del POST>' '<raiz de datos>' [limites]
 * Con «limites», con el anti-replay y el límite por IP activados, como en producción.
 *
 * https://miguelenred.es/jsonsqldb
 */
$post = json_decode($argv[1] ?? '[]', true) ?: [];
$raiz = $argv[2] ?? sys_get_temp_dir();

define('JSONSQLDB_DATA_PATH', $raiz);
define('JSONSQLDB_LOG_PATH',  $raiz . '/logs');
define('JSONSQLDB_LOG_PARAMS', true);      // la prueba comprueba el log
define('JSONSQLDB_API_CONFIG', __DIR__ . '/_config_api.php');
if (($argv[3] ?? '') === 'limites') {
    // Con «__cupo», ese RATE_LIMIT_MAX, con su propio estado
    define('RATE_LIMIT_ACTIVO', true);
    define('ANTI_REPLAY_ACTIVO', true);
    define('RATE_LIMIT_MAX', (int)($post['__cupo'] ?? 1000));
    define('API_ESTADO_PATH', $raiz . (isset($post['__cupo']) ? '/api-cupo' : '/api-limites'));
}
defined('API_ESTADO_PATH') || define('API_ESTADO_PATH', $raiz . '/api');

// Con «__proxy», detrás de un proxy de confianza que solo deja a 198.51.100.0/24
// y manda esa cabecera X-Forwarded-For
if (isset($post['__proxy'])) {
    define('CONFIAR_EN_PROXY', true);
    define('IPS_PERMITIDAS', ['198.51.100.0/24']);
    $_SERVER['HTTP_X_FORWARDED_FOR'] = (string)$post['__proxy'];
}
// Con «__tiempo», ese TIME_LIMIT en segundos
if (isset($post['__tiempo'])) {
    define('TIME_LIMIT', (int)$post['__tiempo']);
}
// Con «__apagada», la API desactivada en config.php
if (isset($post['__apagada'])) {
    define('JSONSQLDB_API_ACTIVA', false);
}
// Con «__clases», al acabar, las clases que ha cargado, en clases.json de la raíz de datos
if (isset($post['__clases'])) {
    register_shutdown_function(static function () use ($raiz): void {
        file_put_contents("$raiz/clases.json", json_encode(array_map(static fn(string $f): string => basename($f, '.php'), get_included_files())));
    });
}
$_SERVER['REQUEST_METHOD']  = $post['__metodo'] ?? 'POST';
$_SERVER['REMOTE_ADDR']     = '10.0.0.7';
$_SERVER['HTTP_USER_AGENT'] = 'pruebas-jsonsqldb';
unset($post['__metodo'], $post['__proxy'], $post['__tiempo'], $post['__apagada'], $post['__clases']);

$_POST = $post;
$_SERVER['CONTENT_LENGTH'] = (string)strlen(http_build_query($post));

require __DIR__ . '/../api/jsonsqldb_api.php';
