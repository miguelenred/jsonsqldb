<?php
declare(strict_types=1);

// ============================================================
// jsonSQLDB - API
//
// Ejecuta SQL contra una base jsonSQLDB mediante POST firmado con HMAC.
//
// Parámetros POST:
//   api_key    clave de la aplicación
//   db         nombre de la base de datos
//   sql        sentencia a ejecutar (puede ser multilínea)
//   timestamp  hora UNIX actual (10 dígitos)
//   token      hash_hmac('sha256', "+".$apiKey."|".$db."|".$timestamp."|".$sql.$params."¿", $secretoDeLaKey)
//
// Respuesta:
//   SELECT  → [ {...}, {...} ]
//   resto   → { "success": true, "filas": n, "mensaje": "..." }
//   error   → { "error": "..." }
// ============================================================

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

// Ambas rutas pueden fijarse antes (por ejemplo para mover la configuración
// fuera del webroot) definiendo JSONSQLDB_API_CONFIG y JSONSQLDB_CONFIG.
require_once defined('JSONSQLDB_API_CONFIG') ? JSONSQLDB_API_CONFIG : __DIR__ . '/jsonsqldb_api_config.php';
require_once defined('JSONSQLDB_CONFIG')     ? JSONSQLDB_CONFIG     : __DIR__ . '/../config.php';
// Marca que el motor se está usando a través de la API. Sin esta constante, el
// motor solo acepta consultas si JSONSQLDB_CONEXION_DIRECTA está activada.
define('JSONSQLDB_VIA_API', true);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\ApiStore;
use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Logger;

ini_set('memory_limit', MEMORY_LIMIT);
set_time_limit(TIME_LIMIT);

// --- Cabeceras de seguridad ---
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'");
if (HSTS_ACTIVO) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Si la consulta llega a agotar la memoria, PHP aborta sin excepción. La red de
// seguridad del motor convierte ese final en una respuesta JSON como cualquier
// otra, en vez de dejar al cliente con un cuerpo vacío o a medias.
JsonSQLDB\Memoria::alAgotarse(static function (string $mensaje): void {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['error' => DEVOLVER_ERRORES ? $mensaje : 'Consulta demasiado grande'],
        JSON_UNESCAPED_UNICODE);
});

$store  = new ApiStore(API_ESTADO_PATH);
$ip     = ipCliente();
$inicio = microtime(true);

/**
 * IP real del cliente. Solo mira las cabeceras del proxy si se ha declarado
 * que hay uno de confianza: de lo contrario cualquiera podría falsearla.
 */
function ipCliente(): string
{
    if (CONFIAR_EN_PROXY) {
        $reenviada = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($reenviada !== '') {
            $primera = trim(explode(',', $reenviada)[0]);
            if (filter_var($primera, FILTER_VALIDATE_IP) !== false) {
                return $primera;
            }
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'desconocida');
}

/** ¿La petición ha llegado por HTTPS? */
function esHttps(): bool
{
    if (($_SERVER['HTTPS'] ?? 'off') !== 'off' && ($_SERVER['HTTPS'] ?? '') !== '') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    return CONFIAR_EN_PROXY
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * ¿La IP está en la lista? Admite IP suelta y rango CIDR, IPv4 e IPv6.
 * Lista vacía = no se filtra.
 *
 * @param string[] $lista
 */
function ipPermitida(string $ip, array $lista): bool
{
    if ($lista === []) {
        return true;
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($lista as $entrada) {
        $entrada = trim((string)$entrada);
        if ($entrada === '') {
            continue;
        }
        if (strpos($entrada, '/') === false) {
            if (@inet_pton($entrada) === $bin) {
                return true;
            }
            continue;
        }
        [$red, $bits] = explode('/', $entrada, 2);
        $redBin = @inet_pton(trim($red));
        $bits   = (int)$bits;
        if ($redBin === false || strlen($redBin) !== strlen($bin) || $bits < 0) {
            continue;
        }
        $bytes = intdiv($bits, 8);
        $resto = $bits % 8;
        if ($bytes > 0 && strncmp($bin, $redBin, $bytes) !== 0) {
            continue;
        }
        if ($resto === 0) {
            return true;
        }
        $mascara = chr((0xFF << (8 - $resto)) & 0xFF);
        if (isset($bin[$bytes], $redBin[$bytes])
            && (($bin[$bytes] & $mascara) === ($redBin[$bytes] & $mascara))) {
            return true;
        }
    }
    return false;
}

/** Devuelve un error y termina. */
function salirConError(string $publico, string $interno = ''): never
{
    global $store, $ip, $inicio;

    $store->registrar([
        'ts'     => date('Y-m-d H:i:s'),
        'ip'     => $ip,
        'ua'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'db'     => (string)($_POST['db'] ?? ''),
        'origen' => '',
        'op'     => '',
        'filas'  => null,
        'ms'     => round((microtime(true) - $inicio) * 1000, 2),
        'error'  => $publico . ($interno !== '' ? ': ' . $interno : ''),
    ]);

    $mensaje = DEVOLVER_ERRORES && $interno !== '' ? $publico . ': ' . $interno : $publico;
    echo json_encode(['error' => DEVOLVER_ERRORES ? $mensaje : $publico], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Filtros previos: quién y por dónde ---
if (!ipPermitida($ip, (array)IPS_PERMITIDAS)) {
    http_response_code(403);
    salirConError('Acceso no permitido desde esta IP');
}
// --- Configuración sin terminar ---
// Los ficheros .dist traen los mismos marcadores en el panel y en la API, así
// que si no se sustituyen todo FUNCIONA, con una clave y un secreto que están
// publicados en GitHub. Callarse eso sería peor que no arrancar.
if (defined('API_KEYS_SIN_CONFIGURAR') === false && isset($API_KEYS) && is_array($API_KEYS)) {
    foreach ($API_KEYS as $datosCuenta) {
        if (!is_array($datosCuenta)) {
            continue;
        }
        foreach (['key', 'hmac_secret'] as $campo) {
            if (strpos((string)($datosCuenta[$campo] ?? ''), 'CHANGE_ME') === 0) {
                http_response_code(500);
                salirConError(
                    'La API está sin configurar: api/jsonsqldb_api_config.php todavía tiene '
                    . 'valores CHANGE_ME_. Esas claves son públicas —vienen en el repositorio— '
                    . 'así que la API no arranca hasta que las cambies. Genera cada una con: '
                    . 'php -r "echo bin2hex(random_bytes(32));"'
                );
            }
        }
    }
}

if (EXIGIR_HTTPS && !esHttps()) {
    http_response_code(403);
    // La indicación va en el mensaje público a propósito: quien la ve ya sabe
    // que la petición fue por HTTP, así que no descubre nada, y es lo primero
    // con lo que se choca al instalar en una máquina local
    salirConError(
        'Esta API solo admite conexiones HTTPS y esta petición ha llegado por HTTP. '
        . 'En producción, pon un certificado. Para probar en local, cambia '
        . 'EXIGIR_HTTPS a false en api/jsonsqldb_api_config.php y ADMIN_EXIGIR_HTTPS '
        . 'en jsonsqldbadmin/config.php, y vuelve a ponerlas a true antes de publicar.'
    );
}

// --- Solo POST ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    salirConError('Método no permitido');
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_POST_SIZE) {
    salirConError('Petición demasiado grande');
}

// --- Parámetros ---
$apiKey    = trim((string)($_POST['api_key']   ?? ''));
$baseDatos = trim((string)($_POST['db']        ?? ''));
$sql       = trim((string)($_POST['sql']       ?? ''));
$timestamp = trim((string)($_POST['timestamp'] ?? ''));
$token     = trim((string)($_POST['token']     ?? ''));
// Sin trim: la firma se calcula sobre el texto exacto que envió el cliente.
$paramsRaw = (string)($_POST['params'] ?? '');

if ($apiKey === '')                { salirConError('API key vacía'); }
if (strlen($apiKey) > 128)         { salirConError('API key no válida'); }
// db vacío solo vale para SHOW DATABASES, CREATE DATABASE y DROP DATABASE
if ($baseDatos !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $baseDatos)) {
    salirConError('Nombre de base de datos no válido');
}
if ($sql === '')                   { salirConError('SQL vacía'); }
if (strlen($sql) > MAX_SQL_LENGTH) { salirConError('SQL demasiado larga'); }
if ($token === '')                 { salirConError('Token vacío'); }
if (strlen($paramsRaw) > MAX_PARAMS_LENGTH) { salirConError('Parámetros demasiado largos'); }

$params = [];
if ($paramsRaw !== '') {
    $params = json_decode($paramsRaw, true);
    if (!is_array($params) || array_keys($params) !== range(0, count($params) - 1)) {
        salirConError('Los parámetros deben ser una lista JSON');
    }
    if (count($params) > MAX_PARAMS) {
        salirConError('Demasiados parámetros');
    }
    foreach ($params as $v) {
        if ($v !== null && !is_scalar($v)) {
            salirConError('Los parámetros solo admiten valores simples');
        }
    }
}
if (!ctype_digit($timestamp) || strlen($timestamp) !== 10) { salirConError('Timestamp inválido'); }

// --- Bloqueo global por exceso de fallos ---
if ($store->bloqueoGlobal()) {
    salirConError('Servicio temporalmente no disponible');
}

// --- API key ---
// Las cuentas van indexadas por nombre y la clave es el campo 'key', así que se
// busca recorriéndolas. hash_equals compara en tiempo constante: una comparación
// normal permitiría deducir la clave carácter a carácter midiendo tiempos.
if (!isset($API_KEYS) || !is_array($API_KEYS)) {
    salirConError('Configuración incompleta',
        'Falta $API_KEYS en api/jsonsqldb_api_config.php');
}

$cuenta = null;
$origen = 'sin nombre';
foreach ($API_KEYS as $nombreCuenta => $datos) {
    if (is_array($datos) && isset($datos['key']) && hash_equals((string)$datos['key'], $apiKey)) {
        $cuenta = $datos;
        $origen = (string)$nombreCuenta;
        break;
    }
}
if ($cuenta === null) {
    $store->fallo($ip);
    salirConError('API key no válida');
}
$permiso = strtolower((string)($cuenta['permiso'] ?? ''));

if (!in_array($permiso, ['lectura', 'escritura', 'admin'], true)) {
    salirConError('La API key no tiene un permiso válido asignado');
}

// --- Formato del token ---
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    $store->fallo($ip);
    salirConError('Token inválido');
}

// --- Ventana de tiempo ---
if (abs(time() - (int)$timestamp) > RATE_TIMESTAMP_DIFF) {
    $store->fallo($ip);
    salirConError('Timestamp fuera de rango');
}

// --- Firma HMAC ---
// Cada clave firma con SU secreto. Así, una aplicación comprometida no puede
// firmar peticiones haciéndose pasar por otra clave, ni por la de administración.
$secreto = (string)($cuenta['hmac_secret'] ?? '');
if ($secreto === '') {
    salirConError('Configuración incompleta',
        "La API key '$origen' no tiene 'hmac_secret' en api/jsonsqldb_api_config.php");
}

// La firma cubre la clave, la BASE DE DATOS, la hora, la SQL y los parámetros.
//
// La base entró en la fórmula en la 2.0. Antes quedaba fuera, y eso permitía
// coger una petición firmada y reenviarla contra otra base sin más que cambiar
// el campo 'db': la firma seguía siendo válida porque no lo cubría. Una clave
// con permiso sobre varias bases podía así ejecutar en la que no tocaba.
$tokenEsperado = hash_hmac(
    'sha256',
    '+' . $apiKey . '|' . $baseDatos . '|' . $timestamp . '|' . $sql . $paramsRaw . '¿',
    $secreto
);
if (!hash_equals($tokenEsperado, $token)) {
    $store->fallo($ip);
    salirConError('Token inválido');
}

// --- Anti-replay ---
// El nonce es el propio token, que ya es único por petición. Antes entraba la
// IP, y eso permitía reenviar una petición capturada desde otra dirección: el
// nonce cambiaba, pero la firma seguía siendo válida porque la IP no la cubre.
// El nonce se acorta a 16 hexadecimales. No debilita nada: reenviar el MISMO
// token da el mismo nonce y se detecta igual; lo único que podría pasar es que
// dos tokens distintos coincidieran en 64 bits dentro de la misma ventana de
// minutos y se rechazara uno legítimo, que es despreciable. A cambio, el
// fichero de estado se queda en una cuarta parte por esta vía.
$acceso = $store->nonceYContar(substr(hash('sha256', $token), 0, 16), $ip);
if ($acceso !== true) {
    $store->fallo($ip);
    salirConError($acceso === 'nonce' ? 'Token ya utilizado' : 'Límite de peticiones superado');
}

// --- Bases de datos permitidas para esta clave ---
$permitidas = $cuenta['bases'] ?? ['*'];
if (!in_array('*', $permitidas, true)) {
    if ($baseDatos === '') {
        salirConError('Esta API key está limitada a bases concretas: indica el parámetro db');
    }
    if (!in_array($baseDatos, $permitidas, true)) {
        salirConError('Esta API key no tiene acceso a la base de datos indicada');
    }
}

// --- Ejecución ---
Logger::contexto($origen, $ip);
$operacion = '';

try {
    $autorizar = static function (string $tipo) use ($permiso, &$operacion): void {
        $operacion = strtoupper(str_replace('_', ' ', $tipo));

        $lectura   = ['select', 'union', 'show_databases', 'show_tables', 'show_views',
                      'show_schema', 'show_keys', 'show_triggers', 'check_keys'];
        $escritura = array_merge($lectura, ['insert', 'update', 'delete', 'repair_keys']);

        if ($permiso === 'lectura' && !in_array($tipo, $lectura, true)) {
            throw JsonSqlDbError::permission('La API key solo tiene permiso de lectura');
        }
        if ($permiso === 'escritura' && !in_array($tipo, $escritura, true)) {
            throw JsonSqlDbError::permission('La API key no tiene permiso para modificar la estructura');
        }
    };

    $res = $baseDatos === ''
        ? Database::consultarGlobal($sql, $params, $autorizar)
        : (new Database($baseDatos))->consultar($sql, $params, $autorizar);

    $filas = is_array($res) && isset($res['success']) ? (int)$res['filas'] : count($res);

    $store->registrar([
        'ts'     => date('Y-m-d H:i:s'),
        'ip'     => $ip,
        'ua'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'db'     => $baseDatos,
        'origen' => $origen,
        'op'     => $operacion,
        'filas'  => $filas,
        'ms'     => round((microtime(true) - $inicio) * 1000, 2),
        'error'  => null,
    ]);

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;

} catch (JsonSqlDbError $e) {
    salirConError('Error en la consulta', $e->sqlState . ': ' . $e->getMessage());
} catch (Throwable $e) {
    salirConError('Error interno', $e->getMessage());
}
