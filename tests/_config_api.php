<?php
// Configuración de API usada solo por las pruebas: la real más una clave
// de solo lectura restringida a una única base de datos.
require __DIR__ . '/../api/jsonsqldb_api_config.php';

$API_KEYS['CLAVE_DE_PRUEBA_SOLO_LECTURA_0000000000000000000000000000000000'] = [
    'nombre'  => 'Panel de solo lectura',
    'permiso' => 'lectura',
    'bases'   => ['apibase'],
];
