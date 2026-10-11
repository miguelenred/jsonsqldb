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
//   nonce      16 a 64 cifras hexadecimales al azar, distintas en cada petición (2.9)
//   token      hash_hmac('sha256', "+".$apiKey."|".$db."|".$timestamp."|".$nonce."|".$sql.$params."¿", $secretoDeLaKey)
//
// Sin nonce se admite la firma de antes ("+".$apiKey."|".$db."|".$timestamp."|".$sql.$params."¿"),
// pero dos peticiones idénticas en el mismo segundo dan el mismo token y la segunda se rechaza.
//
// Transacción (2.9): en lugar de sql y params, el campo lote con una lista JSON de
// sentencias, cada una un texto SQL o {"sql": "...", "params": [...]}. Se ejecutan
// todas o ninguna. En la firma, lote ocupa el lugar de $sql.$params.
//
// Respuesta:
//   SELECT  → [ {...}, {...} ]
//   resto   → { "success": true, "filas": n, "mensaje": "..." }
//   lote    → [ resultado de la 1.ª sentencia, de la 2.ª, … ]
//   error   → { "error": "..." }
//
// https://miguelenred.es/jsonsqldb
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

// Apagada en config.php (2.9): no se atiende nada, ni se mira la clave ni se
// anota la petición
if (!JsonSQLDB\Config::apiActiva()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit(json_encode(['error' => 'La API está desactivada'], JSON_UNESCAPED_UNICODE));
}

use JsonSQLDB\ApiStore;
use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Logger;

ini_set('memory_limit', MEMORY_LIMIT);
set_time_limit(TIME_LIMIT);

// --- Cabeceras de seguridad ---
header('Content-Type: application/json; charset=utf-8');
// Qué versión responde. Sirve para diagnosticar sin entrar por FTP, y no
// descubre nada: el proyecto es público y la versión está en el repositorio.
header('X-JsonSQLDB-Version: ' . JsonSQLDB\Config::version());
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

// Si la consulta llega a agotar la memoria o el tiempo, PHP aborta sin
// excepción. La red de seguridad del motor convierte ese final en una
// respuesta JSON como cualquier otra, en vez de dejar al cliente con un cuerpo
// vacío o a medias, y la petición queda en el registro (2.9)
JsonSQLDB\Memoria::alAgotarse(static function (string $mensaje, bool $memoria): void {
    if (!headers_sent()) {
        http_response_code(500);
    }
    $publico = $memoria ? 'Consulta demasiado grande' : 'Consulta demasiado larga';
    registrarPeticion(null, $publico . ': ' . $mensaje);
    echo cuerpoError(DEVOLVER_ERRORES ? $mensaje : $publico);
});

$store  = new ApiStore(API_ESTADO_PATH);
$ip     = ipCliente();
$inicio = microtime(true);
// De quién es la petición, una vez comprobada su firma, y qué sentencia: con
// eso se anota también una que falla (2.9: sus errores se anotaban sin ello)
$autenticado = '';
$operacion   = '';

/** Anota la petición en el registro de la API. */
function registrarPeticion(?int $filas, ?string $error): void
{
    global $store, $ip, $inicio, $autenticado, $operacion;
    $store->registrar([
        'ts'     => date('Y-m-d H:i:s'),
        'ip'     => $ip,
        'ua'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        // Recortado: llega de quien sea, antes de comprobar nada
        'db'     => substr(trim((string)($_POST['db'] ?? '')), 0, 64),
        'origen' => $autenticado,
        'op'     => $operacion,
        'filas'  => $filas,
        'ms'     => round((microtime(true) - $inicio) * 1000, 2),
        'error'  => $error === null ? null : substr($error, 0, 1000),
    ]);
}

/**
 * IP real del cliente. Solo mira las cabeceras del proxy si se ha declarado
 * que hay uno de confianza: de lo contrario cualquiera podría falsearla.
 */
function ipCliente(): string
{
    if (CONFIAR_EN_PROXY) {
        // La última de la lista, la que pone el proxy (2.9): la primera la
        // escribe el cliente, y con ella se saltaba la lista de IPs
        // permitidas y el bloqueo por intentos, o bloqueaba la IP de otro
        $reenviada = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($reenviada !== '') {
            $lista  = explode(',', $reenviada);
            $ultima = trim((string)end($lista));
            if (filter_var($ultima, FILTER_VALIDATE_IP) !== false) {
                return $ultima;
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
function salirConError(string $publico, string $interno = ''): void
{
    registrarPeticion(null, $publico . ($interno !== '' ? ': ' . $interno : ''));

    $mensaje = DEVOLVER_ERRORES && $interno !== '' ? $publico . ': ' . $interno : $publico;
    echo cuerpoError(DEVOLVER_ERRORES ? $mensaje : $publico);
    exit;
}

/**
 * El JSON de una respuesta de error. Un mensaje con bytes que no son UTF-8
 * (el de SELECT * FROM "no\xffexiste" lleva el nombre tal cual) sale con �
 * en su lugar (2.9: json_encode() daba false y la respuesta llegaba vacía).
 */
function cuerpoError(string $mensaje): string
{
    return json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"error":"Error interno"}';
}

/**
 * Los parámetros de una sentencia: valores simples, y los números, finitos
 * (2.9: 1e400 llega de json_decode() como INF, y acababa en un «Error
 * interno» al escribir la respuesta).
 */
function comprobarParametros(array $lista): void
{
    foreach ($lista as $v) {
        if ($v !== null && !is_scalar($v)) {
            salirConError('Los parámetros solo admiten valores simples');
        }
        if (is_float($v) && !is_finite($v)) {
            salirConError('Los parámetros no admiten números fuera de rango (como 1e400)');
        }
    }
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
// La SQL se firma tal cual llega, como lote y params (2.9): firmada sin los
// espacios de los extremos, la de un cliente que los mandaba (un salto de
// línea al final) no cuadraba nunca, y cada intento contaba como un fallo
$sqlRaw    = (string)($_POST['sql'] ?? '');
$sql       = trim($sqlRaw);
$loteRaw   = (string)($_POST['lote'] ?? '');
$timestamp = trim((string)($_POST['timestamp'] ?? ''));
$token     = trim((string)($_POST['token']     ?? ''));
$nonce     = trim((string)($_POST['nonce']     ?? ''));
// Sin trim: la firma se calcula sobre el texto exacto que envió el cliente.
$paramsRaw = (string)($_POST['params'] ?? '');

if ($apiKey === '')                { salirConError('API key vacía'); }
if (strlen($apiKey) > 128)         { salirConError('API key no válida'); }
// db vacío solo vale para SHOW DATABASES, CREATE DATABASE y DROP DATABASE
if ($baseDatos !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $baseDatos)) {
    salirConError('Nombre de base de datos no válido');
}
if ($sql === '' && $loteRaw === '') { salirConError('SQL vacía'); }
if ($sql !== '' && $loteRaw !== '') { salirConError('O sql o lote, no los dos'); }
if (strlen($sql) > MAX_SQL_LENGTH) { salirConError('SQL demasiado larga'); }
if ($token === '')                 { salirConError('Token vacío'); }
if ($nonce !== '' && !preg_match('/^[0-9a-f]{16,64}$/', $nonce)) { salirConError('Nonce no válido'); }
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
    comprobarParametros($params);
}
if (!ctype_digit($timestamp) || strlen($timestamp) !== 10) { salirConError('Timestamp inválido'); }

// Un lote: cada sentencia, con los mismos límites que una suelta, y como
// mucho 1.000 sentencias
$sentencias = [];
if ($loteRaw !== '') {
    if ($baseDatos === '') {
        salirConError('Un lote necesita una base de datos (db)');
    }
    $lista = json_decode($loteRaw, true);
    if (!is_array($lista) || $lista === [] || array_keys($lista) !== range(0, count($lista) - 1) || count($lista) > 1000) {
        salirConError('El lote debe ser una lista JSON de 1 a 1.000 sentencias');
    }
    foreach ($lista as $item) {
        $uno = is_string($item) ? ['sql' => $item, 'params' => []] : $item;
        $s = is_array($uno) ? trim((string)($uno['sql'] ?? '')) : '';
        $p = is_array($uno) ? ($uno['params'] ?? []) : null;
        if ($s === '' || strlen($s) > MAX_SQL_LENGTH || !is_array($p) || count($p) > MAX_PARAMS
            || ($p !== [] && array_keys($p) !== range(0, count($p) - 1))) {
            salirConError('Cada sentencia del lote: un texto SQL, o {"sql": "...", "params": [...]} con una lista de parámetros');
        }
        comprobarParametros($p);
        $sentencias[] = [$s, $p];
    }
}

// --- Bloqueo por fallos de autenticación desde esta IP ---
// Solo a esa IP: hasta 2.7.2 los fallos de cualquiera bloqueaban a todos
if ($store->ipBloqueada($ip)) {
    salirConError('Demasiados intentos fallidos desde esta IP');
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

// Una clave que no existe tiene la respuesta de una firma mala, y después de
// las mismas comprobaciones (2.9): antes decía «API key no válida», y quien
// tuviera una clave vieja o filtrada podía saber si seguía existiendo sin
// conocer su secreto
if ($cuenta === null) {
    $store->fallo($ip);
    salirConError('Token inválido');
}
$permiso = strtolower((string)($cuenta['permiso'] ?? ''));
if (!in_array($permiso, ['lectura', 'escritura', 'admin'], true)) {
    salirConError('La API key no tiene un permiso válido asignado');
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
//
// El nonce (2.9), al azar en cada petición, entra en la firma: sin él, dos
// peticiones idénticas en el mismo segundo tenían el mismo token y la segunda
// se rechazaba por repetida. Sin nonce se sigue aceptando la firma de antes.
$tokenEsperado = hash_hmac(
    'sha256',
    '+' . $apiKey . '|' . $baseDatos . '|' . $timestamp . '|' . ($nonce === '' ? '' : $nonce . '|') . ($loteRaw !== '' ? $loteRaw : $sqlRaw . $paramsRaw) . '¿',
    $secreto
);
if (!hash_equals($tokenEsperado, $token)) {
    $store->fallo($ip);
    salirConError('Token inválido');
}
$autenticado = $origen;

// --- Anti-replay ---
// El nonce es el propio token, que ya es único por petición. Antes entraba la
// IP, y eso permitía reenviar una petición capturada desde otra dirección: el
// nonce cambiaba, pero la firma seguía siendo válida porque la IP no la cubre.
// El nonce se acorta a 16 hexadecimales. No debilita nada: reenviar el MISMO
// token da el mismo nonce y se detecta igual; lo único que podría pasar es que
// dos tokens distintos coincidieran en 64 bits dentro de la misma ventana de
// minutos y se rechazara uno legítimo, que es despreciable. A cambio, el
// fichero de estado se queda en una cuarta parte por esta vía.
$acceso = $store->nonceYContar(substr(hash('sha256', $token), 0, 16), $ip, $origen, (int)$timestamp);
if ($acceso !== true) {
    // Ni un token repetido ni pasarse del límite cuentan como intento fallido
    // de esa IP (2.9): la firma era buena, así que no es nadie probando
    // claves, y contarlo bloqueaba un día entero la IP de un cliente legítimo
    // (el panel, antes del nonce, al repetir una sentencia en el mismo segundo)
    // false es un fallo de entrada/salida del fichero de estado, no un límite
    // superado. Se sigue cerrando el paso, pero decir «límite» sería mentir y
    // mandaría a quien lo lea a mirar donde no es.
    salirConError(match ($acceso) {
        'nonce'  => 'Token ya utilizado',
        'limite' => 'Límite de peticiones superado',
        default  => 'Servicio temporalmente no disponible',
    });
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

try {
    $limitada = !in_array('*', $permitidas, true);
    $enLote   = $sentencias !== [];
    $autorizar = static function (string $tipo) use ($permiso, $limitada, $enLote, &$operacion): void {
        $operacion = strtoupper(str_replace('_', ' ', $tipo));

        // Una clave limitada a unas bases no gestiona bases: crear o borrar una
        // no es cosa de ninguna base concreta, y podría ser de otra
        if ($limitada && ($tipo === 'create_database' || $tipo === 'drop_database')) {
            throw JsonSqlDbError::permission('Esta API key está limitada a bases concretas: no puede crear ni borrar bases');
        }
        // Y su SHOW DATABASES se filtra abajo, en una petición suya: dentro de
        // un lote, su resultado no se distingue de los demás y salían todas (2.9)
        if ($limitada && $enLote && $tipo === 'show_databases') {
            throw JsonSqlDbError::permission('Esta API key está limitada a bases concretas: SHOW DATABASES va en una petición propia, no en un lote');
        }

        $lectura   = ['select', 'union', 'show_databases', 'show_tables', 'show_views',
                      'show_schema', 'show_keys', 'show_triggers', 'show_indexes', 'show_create',
                      'check_keys', 'check_tables', 'explain'];
        $escritura = array_merge($lectura, ['insert', 'update', 'delete', 'repair_keys', 'repair_tables']);

        if ($permiso === 'lectura' && !in_array($tipo, $lectura, true)) {
            throw JsonSqlDbError::permission('La API key solo tiene permiso de lectura');
        }
        if ($permiso === 'escritura' && !in_array($tipo, $escritura, true)) {
            throw JsonSqlDbError::permission('La API key no tiene permiso para modificar la estructura');
        }
    };

    // Las filas de un SELECT se escriben como JSON según salen del motor, en
    // un texto en memoria: el resultado no llega a estar entero como array de
    // PHP, que ocupa varias veces su JSON. El texto se envía al terminar, ya
    // sin bloqueos, y un error a medio camino sigue saliendo como JSON de
    // error porque todavía no se ha enviado nada. Sin tocar el disco
    $json = '[';
    $res = $sentencias !== []
        ? (new Database($baseDatos))->lote($sentencias, $autorizar)
        : ($baseDatos === ''
        ? Database::consultarGlobal($sql, $params, $autorizar)
        : (new Database($baseDatos))->consultarPorFilas($sql, $params, $autorizar,
            static function (array $fila) use (&$json): void {
                $json .= ($json === '[' ? '' : ',') . json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }));
    if ($sentencias !== []) {
        $operacion = 'LOTE';
    }

    // SHOW DATABASES con una clave limitada: solo las suyas. Sin esto, los
    // nombres de las bases de los demás se verían aunque no se pudiera entrar
    if ($limitada && $operacion === 'SHOW DATABASES' && is_array($res) && !isset($res['success'])) {
        $res = array_values(array_filter($res, static fn($f): bool => in_array($f['base'] ?? null, $permitidas, true)));
    }

    $filas = is_int($res) ? $res : (is_array($res) && isset($res['success']) ? (int)$res['filas'] : count($res));   // en un lote, sus sentencias

    registrarPeticion($filas, null);

    // Un texto que no es UTF-8 válido no se puede escribir en JSON: antes la
    // respuesta salía vacía; ahora sale como error, igual por los dos caminos
    echo is_int($res) ? $json . ']' : json_encode($res, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;

} catch (JsonSqlDbError $e) {
    salirConError('Error en la consulta', $e->sqlState . ': ' . $e->getMessage());
} catch (Throwable $e) {
    salirConError('Error interno', $e->getMessage());
}
