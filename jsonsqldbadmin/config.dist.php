<?php
// ============================================================
// PLANTILLA. Copia este fichero a config.php. ADMIN_API_KEY y ADMIN_HMAC_SECRET
// son la clave admin y SU 'secreto' de api/jsonsqldb_api_config.php.
// ============================================================
// ============================================================
// jsonSQLDBadmin - Configuración del panel
// Este fichero NO debe ser accesible desde el navegador.
// El .htaccess / web.config de esta carpeta ya lo bloquea.
// ============================================================

// ------------------------------------------------------------
// CONEXIÓN CON LA API
// ------------------------------------------------------------
// El panel nunca toca el motor: todo pasa por la API.
// Déjalo vacío y se calcula solo (../api/jsonsqldb_api.php).
defined('ADMIN_API_URL') || define('ADMIN_API_URL', (string)getenv('ADMIN_API_URL'));

// Clave de administración creada en api/jsonsqldb_api_config.php.
// Cámbiala allí y aquí a la vez.
defined('ADMIN_API_KEY') || define('ADMIN_API_KEY', 'CHANGE_ME_ADMIN_API_KEY');

// El 'secreto' de esa misma clave en api/jsonsqldb_api_config.php. Si allí la
// clave no tiene secreto propio, aquí va HMAC_SECRET.
defined('ADMIN_HMAC_SECRET') || define('ADMIN_HMAC_SECRET', 'CHANGE_ME_ADMIN_SECRET');

// --- Certificado del servidor de la API ---
// Si la API va por HTTPS con un certificado propio (autofirmado o de una CA
// interna), tienes dos formas de que el panel lo acepte:
//
//  1. RECOMENDADA: indica aquí la ruta del .crt / .pem del certificado o de la
//     CA que lo firma. Se sigue verificando, pero contra ese fichero.
//     El nombre del servidor de ADMIN_API_URL tiene que coincidir con el del
//     certificado (si el certificado es para 'midominio', usa https://midominio:...).
//     Ejemplo Windows: 'C:/xampp/apache/conf/ssl.crt/server.crt'
//     Ejemplo Linux:   '/etc/ssl/certs/mi-ca.crt'
defined('ADMIN_SSL_CA') || define('ADMIN_SSL_CA', (string)getenv('ADMIN_SSL_CA'));

//  2. ATAJO: aceptar el certificado sin comprobarlo. Vale para una red interna
//     de confianza, pero deja de protegerte frente a un intermediario.
//     Solo se aplica si ADMIN_SSL_CA está vacío.
defined('ADMIN_SSL_AUTOFIRMADO') || define('ADMIN_SSL_AUTOFIRMADO', false);

// Segundos de espera de la llamada a la API.
defined('ADMIN_TIMEOUT') || define('ADMIN_TIMEOUT', 60);

// ------------------------------------------------------------
// DATOS DEL PROPIO PANEL (usuarios y auditoría, en JSON)
// ------------------------------------------------------------
defined('ADMIN_DATA_PATH') || define('ADMIN_DATA_PATH',
    (string)(getenv('ADMIN_DATA_PATH') ?: __DIR__ . '/datos'));

// ------------------------------------------------------------
// QUIÉN PUEDE ABRIR EL PANEL
// ------------------------------------------------------------
// IPs que pueden entrar. Vacío = cualquiera.
// Admite IP suelta ('10.0.0.7') y rango CIDR ('192.168.1.0/24').
// Si el panel solo lo usas tú desde la oficina o por VPN, esto vale más que
// cualquier otra medida: quien no esté en la lista ni siquiera ve el login.
defined('ADMIN_IPS_PERMITIDAS') || define('ADMIN_IPS_PERMITIDAS', []);

// Exigir HTTPS para entrar al panel. En producción, a true: por aquí viajan
// contraseñas y datos.
defined('ADMIN_EXIGIR_HTTPS') || define('ADMIN_EXIGIR_HTTPS', true);

// Confiar en X-Forwarded-For y X-Forwarded-Proto. Solo si delante hay un proxy
// o balanceador de confianza; si no, cualquiera puede falsear su IP.
defined('ADMIN_CONFIAR_EN_PROXY') || define('ADMIN_CONFIAR_EN_PROXY', false);

// ------------------------------------------------------------
// SESIÓN Y ACCESO
// ------------------------------------------------------------
defined('ADMIN_SESION_NOMBRE')  || define('ADMIN_SESION_NOMBRE', 'jsonsqldbadmin');
defined('ADMIN_SESION_MINUTOS') || define('ADMIN_SESION_MINUTOS', 60);   // inactividad máxima

// Bloqueo tras varios intentos de acceso fallidos, por IP.
defined('ADMIN_LOGIN_MAX_FALLOS')  || define('ADMIN_LOGIN_MAX_FALLOS', 5);
defined('ADMIN_LOGIN_BLOQUEO_MIN') || define('ADMIN_LOGIN_BLOQUEO_MIN', 15);

// Coste del bcrypt de las contraseñas (10-12 es lo razonable).
defined('ADMIN_BCRYPT_COSTE') || define('ADMIN_BCRYPT_COSTE', 11);

// ------------------------------------------------------------
// AUDITORÍA
// ------------------------------------------------------------
// Días que se conservan los ficheros de auditoría. 0 = para siempre.
defined('ADMIN_AUDIT_DIAS') || define('ADMIN_AUDIT_DIAS', 90);

// ------------------------------------------------------------
// INTERFAZ
// ------------------------------------------------------------
// Filas por página al navegar por los datos de una tabla.
defined('ADMIN_FILAS_PAGINA') || define('ADMIN_FILAS_PAGINA', 50);

// Caracteres máximos de una celda antes de recortarla en el listado.
defined('ADMIN_CELDA_MAX') || define('ADMIN_CELDA_MAX', 120);

// ------------------------------------------------------------
// EXPORTACIÓN
// ------------------------------------------------------------
// Separador del CSV. El Excel en español espera ';'; usa ',' si vas a abrir
// el fichero con otras herramientas.
defined('ADMIN_CSV_SEPARADOR') || define('ADMIN_CSV_SEPARADOR', ';');

// Tope de filas por exportación, para no agotar la memoria de PHP.
defined('ADMIN_EXPORT_MAX') || define('ADMIN_EXPORT_MAX', 100000);

// Copia en ZIP: ruta de la carpeta 'data' del motor, la que contiene una
// subcarpeta por base de datos.
//
// Es lo ÚNICO del panel que lee los ficheros del motor directamente, y solo en
// lectura: un ZIP fiel necesita los .json tal cual están en disco, y la API
// devuelve datos, no ficheros. Si el panel está en otra máquina, déjalo vacío
// y la copia en ZIP quedará desactivada; el volcado en SQL sigue funcionando.
//
// Vacío = se busca en ../data, que es donde está en la instalación normal.
defined('ADMIN_RUTA_DATOS_MOTOR') || define('ADMIN_RUTA_DATOS_MOTOR',
    (string)getenv('ADMIN_RUTA_DATOS_MOTOR'));
