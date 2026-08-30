<?php
declare(strict_types=1);

/**
 * jsonSQLDBadmin — panel de administración de jsonSQLDB.
 *
 * Todo pasa por la API (lib/Api.php): el panel nunca toca el motor ni los
 * ficheros de datos. Un único punto de entrada; las páginas están en vistas/.
 */

require_once (string)(getenv('JSONSQLDBADMIN_CONFIG') ?: __DIR__ . '/config.php');
require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Audit.php';
require_once __DIR__ . '/lib/Api.php';
require_once __DIR__ . '/lib/Exportar.php';
require_once __DIR__ . '/lib/Importar.php';
require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/acciones.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// Todo lo que carga el panel es local: nada de CDNs ni de recursos externos
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
     . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
     . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

// --- Quién y por dónde ---
if (!util_ip_permitida(util_ip(), (array)ADMIN_IPS_PERMITIDAS)) {
    http_response_code(403);
    exit('Acceso no permitido desde esta IP.');
}
if (strpos(ADMIN_API_KEY, 'CHANGE_ME') === 0 || strpos(ADMIN_HMAC_SECRET, 'CHANGE_ME') === 0) {
    http_response_code(500);
    exit(
        "El panel está sin configurar.\n\n"
        . "jsonsqldbadmin/config.php todavía tiene valores CHANGE_ME_. Esas claves\n"
        . "vienen en el repositorio, o sea que son públicas: con ellas cualquiera\n"
        . "que llegue al panel entra. Por eso no arranca hasta que las cambies.\n\n"
        . "Genera cada valor con:  php -r \"echo bin2hex(random_bytes(32));\"\n"
        . "ADMIN_API_KEY y ADMIN_HMAC_SECRET tienen que valer lo mismo que 'key' y\n"
        . "'hmac_secret' de la cuenta jsonSQLDBadmin en api/jsonsqldb_api_config.php."
    );
}

if (ADMIN_EXIGIR_HTTPS && !util_https()) {
    // El mensaje dice qué hacer, no solo qué pasa: en una máquina local esto es
    // lo primero con lo que se choca al instalar, y sin la indicación hay que
    // buscar la constante por el código
    http_response_code(403);
    exit(
        "Este panel solo admite conexiones HTTPS y esta petición ha llegado por HTTP.\n\n"
        . "En un servidor de verdad: pon un certificado (Let's Encrypt es gratis).\n"
        . "En tu máquina, para probar: cambia ADMIN_EXIGIR_HTTPS a false en\n"
        . "jsonsqldbadmin/config.php, y haz lo mismo con EXIGIR_HTTPS en\n"
        . "api/jsonsqldb_api_config.php. Vuelve a ponerlas a true antes de publicar."
    );
}

Auth::iniciarSesion();

$pagina = get('p', 'bases');
$base   = get('db');
$tabla  = get('tabla');

// ----------------------------------------------------------------------
// Primer arranque: no hay ningún usuario todavía
// ----------------------------------------------------------------------
if (!Auth::hayUsuarios()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $nombre = Auth::crear(post('usuario'), (string)($_POST['clave'] ?? ''), 'admin');
            Audit::registrar('instalar', $nombre);
            flash('success', "Administrador '$nombre' creado. Ya puedes entrar.");
            redirigir();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    vista('instalar', ['error' => $error ?? null]);
    exit;
}

// ----------------------------------------------------------------------
// Acceso
// ----------------------------------------------------------------------
if ($pagina === 'salir') {
    Audit::registrar('salir');
    Auth::cerrar();
    redirigir();
}

if (!Auth::identificado()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $usuario = post('usuario');
        try {
            Auth::entrar($usuario, (string)($_POST['clave'] ?? ''), util_ip());
            Audit::registrar('entrar');
            redirigir(['p' => 'bases']);
        } catch (Throwable $e) {
            Audit::registrar('acceso_fallido', $usuario);
            $error = $e->getMessage();
        }
    }
    vista('login', ['error' => $error ?? null]);
    exit;
}

// ----------------------------------------------------------------------
// Acciones (POST)
// ----------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && post('accion') !== '') {
    try {
        Auth::comprobarCsrf();
        ejecutarAccion(post('accion'));            // cada acción redirige
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        redirigir(array_filter([
            'p'     => post('volver', $pagina),
            'db'    => post('db'),
            'tabla' => post('tabla'),
        ]));
    }
}

// ----------------------------------------------------------------------
// Páginas
// ----------------------------------------------------------------------
$paginas = ['bases', 'tablas', 'vistas', 'integridad', 'crear_tabla', 'estructura', 'datos',
            'fila', 'sql', 'auditoria', 'usuarios'];
if (!in_array($pagina, $paginas, true)) {
    $pagina = 'bases';
}
if (in_array($pagina, ['tablas', 'vistas', 'integridad', 'crear_tabla', 'estructura', 'datos',
                       'fila', 'sql'], true) && $base === '') {
    flash('warning', 'Elige primero una base de datos.');
    redirigir(['p' => 'bases']);
}

try {
    vista($pagina, ['base' => $base, 'tabla' => $tabla]);
} catch (Throwable $e) {
    vista('error', ['base' => $base, 'tabla' => $tabla, 'mensaje' => $e->getMessage()]);
}

/** Pinta una vista dentro del layout. */
function vista(string $nombre, array $datos = []): void
{
    extract($datos, EXTR_SKIP);
    $vistaActual = $nombre;
    require __DIR__ . '/vistas/layout.php';
}
