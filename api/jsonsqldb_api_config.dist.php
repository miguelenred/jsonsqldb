<?php
// ============================================================
// PLANTILLA. Copia este fichero a jsonsqldb_api_config.php y sustituye todos
// los valores CHANGE_ME_ por valores propios, uno distinto cada uno:
//     php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// ============================================================
// ============================================================
// jsonSQLDB - Configuración de la API
// Este fichero NO debe ser accesible desde el navegador.
// El .htaccess / web.config de esta carpeta ya lo bloquea.
// ============================================================

// ------------------------------------------------------------
// SECRETO HMAC
// ------------------------------------------------------------
// Cámbialo por uno propio antes de usarlo en producción.
// Genera uno con: php -r "echo bin2hex(random_bytes(32));"
// No reutilices el mismo secreto en otros hostings.
// ------------------------------------------------------------
// API KEYS
// ------------------------------------------------------------
// Cada clave define:
//   nombre  → etiqueta que aparecerá en el log
//   permiso → 'lectura'   : solo SELECT
//             'escritura' : SELECT, INSERT, UPDATE, DELETE
//             'admin'     : todo, incluido CREATE / ALTER / DROP / TRIGGER
//   bases   → bases de datos a las que puede acceder. ['*'] = todas
//   secreto → secreto HMAC con el que firma esta clave. OBLIGATORIO.
//
// SOBRE 'secreto': uno distinto por clave, siempre. Si varias claves compartieran
// secreto, cualquier aplicación que lo tuviera podría firmar peticiones
// haciéndose pasar por otra clave, incluida la de administración, y los permisos
// por clave dejarían de servir de nada.
//
// Genera cada uno con:
//     php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// Si una aplicación se ve comprometida, revocas su clave y su secreto sin tocar
// las demás.
$API_KEYS = [

    // Clave de jsonSQLDBadmin (el panel de administración usa la API con ella)
    'CHANGE_ME_ADMIN_API_KEY' => [
        'nombre'  => 'jsonSQLDBadmin',
        'permiso' => 'admin',
        'bases'   => ['*'],
        // El mismo valor tiene que estar en ADMIN_HMAC_SECRET del panel
        'secreto' => 'CHANGE_ME_ADMIN_SECRET',
    ],

    // Ejemplo de clave de aplicación
    'CHANGE_ME_APP_API_KEY' => [
        'nombre'  => 'Mi aplicación',
        'permiso' => 'escritura',
        'bases'   => ['*'],
        'secreto' => 'CHANGE_ME_APP_SECRET',
    ],

    // Clave de los clientes de ejemplo (cliente_ejemplo.php y cliente_ejemplo.ps1)
    'CHANGE_ME_EXAMPLE_API_KEY' => [
        'nombre'  => 'Clientes de ejemplo',
        'permiso' => 'escritura',
        'bases'   => ['pruebas'],
        'secreto' => 'CHANGE_ME_EXAMPLE_SECRET',
    ],

    // 'NUEVA_KEY_AQUI' => ['nombre' => '...', 'permiso' => 'lectura',
    //                      'bases' => ['mibase'], 'secreto' => '...'],
];

// ------------------------------------------------------------
// SEGURIDAD DE LAS PETICIONES
// ------------------------------------------------------------
// Exigir HTTPS. La firma HMAC evita que manipulen la consulta, pero no impide
// que alguien la lea por el camino: en producción, esto a true.
// Detrás de un balanceador o proxy, mira también CONFIAR_EN_PROXY.
// En desarrollo local por http://localhost, ponlo a false.
defined('EXIGIR_HTTPS') || define('EXIGIR_HTTPS', true);

// Cabecera HSTS: obliga al navegador a usar siempre HTTPS con este dominio.
// Solo con un certificado válido de una CA reconocida; con uno autofirmado
// dejarías el dominio inaccesible durante el tiempo indicado.
defined('HSTS_ACTIVO') || define('HSTS_ACTIVO', false);

// IPs que pueden usar la API. Vacío = cualquiera.
// Admite IP suelta ('10.0.0.7') y rango CIDR ('10.0.0.0/24', '2001:db8::/32').
// Es la protección más efectiva cuando quien consume la API son servidores
// tuyos con IP fija.
defined('IPS_PERMITIDAS') || define('IPS_PERMITIDAS', []);

// Confiar en las cabeceras del proxy (X-Forwarded-For, X-Forwarded-Proto) para
// saber la IP real del cliente y si la petición venía por HTTPS.
// ACTÍVALO SOLO si delante hay un proxy o balanceador de confianza: si no,
// cualquiera puede falsear su IP y saltarse IPS_PERMITIDAS y el rate limit.
defined('CONFIAR_EN_PROXY') || define('CONFIAR_EN_PROXY', false);

// Anti-replay: impide que el mismo token se use más de una vez
defined('ANTI_REPLAY_ACTIVO') || define('ANTI_REPLAY_ACTIVO', true);

// Rate limiting por IP
defined('RATE_LIMIT_ACTIVO') || define('RATE_LIMIT_ACTIVO',     true);   // false para deshabilitarlo
defined('RATE_LIMIT_MAX') || define('RATE_LIMIT_MAX',        150);    // máximo de peticiones por IP en la ventana
defined('RATE_LIMIT_SECONDS') || define('RATE_LIMIT_SECONDS',    86400);  // ventana de tiempo (24 horas)
defined('RATE_TIMESTAMP_DIFF') || define('RATE_TIMESTAMP_DIFF',   300);    // desfase máximo del timestamp (5 min)
defined('RATE_LIMIT_GLOBAL_MAX') || define('RATE_LIMIT_GLOBAL_MAX', 30);     // fallos de autenticación admitidos en la ventana

// Tamaño máximo del cuerpo de la petición (bytes)
defined('MAX_POST_SIZE') || define('MAX_POST_SIZE', 200000);

// Longitud máxima de la SQL recibida (caracteres)
defined('MAX_SQL_LENGTH') || define('MAX_SQL_LENGTH', 100000);

// Parámetros ligados: número máximo de ? y tamaño máximo del JSON (bytes)
defined('MAX_PARAMS') || define('MAX_PARAMS', 1000);
defined('MAX_PARAMS_LENGTH') || define('MAX_PARAMS_LENGTH', 100000);

// ------------------------------------------------------------
// LÍMITES DE EJECUCIÓN
// ------------------------------------------------------------
defined('MEMORY_LIMIT') || define('MEMORY_LIMIT', '256M');
defined('TIME_LIMIT') || define('TIME_LIMIT',   60);     // segundos
// Una consulta cara ocupa un worker de PHP todo ese tiempo, y con unos pocos
// workers eso es una denegación de servicio hecha con peticiones legítimas.

// ------------------------------------------------------------
// ERRORES
// ------------------------------------------------------------
// true  = devuelve el detalle del error (útil mientras desarrollas)
// false = devuelve solo un mensaje genérico (recomendado en producción)
// A true, el cliente recibe el error del motor: cómodo mientras desarrollas y
// demasiado hablador después. Local: true.
defined('DEVOLVER_ERRORES') || define('DEVOLVER_ERRORES', false);

// ------------------------------------------------------------
// ALMACÉN DE LA API (peticiones, fallos y nonces) — en JSON
// ------------------------------------------------------------
defined('API_ESTADO_PATH') || define('API_ESTADO_PATH', __DIR__ . '/../logs/api');
