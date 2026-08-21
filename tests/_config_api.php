<?php
// Configuración de API usada solo por las pruebas: la real más una clave de solo
// lectura restringida a una única base de datos.
//
// Las pruebas corren por HTTP en localhost y lanzan cientos de peticiones desde
// la misma IP, así que relajan tres protecciones. Se declaran aquí, antes de
// cargar la configuración real, porque el patrón defined()||define() hace que
// mande la primera definición.
define('EXIGIR_HTTPS',       false);   // el servidor de pruebas es http://
define('RATE_LIMIT_ACTIVO',  false);   // cientos de peticiones desde una IP
define('ANTI_REPLAY_ACTIVO', false);   // se repiten peticiones a propósito
define('DEVOLVER_ERRORES',   true);    // las pruebas comprueban los mensajes

require __DIR__ . '/../api/jsonsqldb_api_config.php';

$API_KEYS['Panel de solo lectura'] = [
    'key'     => 'CLAVE_DE_PRUEBA_SOLO_LECTURA_0000000000000000000000000000000000',
    'permiso' => 'lectura',
    'bases'   => ['apibase'],
    'hmac_secret' => 'SECRETO_DE_PRUEBA_SOLO_LECTURA_000000000000000000000000000000',
];

// Clave sin 'hmac_secret': tiene que rechazarse con un mensaje claro
$API_KEYS['Clave sin secreto'] = [
    'key'     => 'CLAVE_DE_PRUEBA_SIN_SECRETO_00000000000000000000000000000000',
    'permiso' => 'lectura',
    'bases'   => ['*'],
];
