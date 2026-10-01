<?php
declare(strict_types=1);

/**
 * jsonSQLDBadmin — panel de administración de jsonSQLDB.
 *
 * Habla con el motor por la API o por conexión directa (lib/Api.php); no toca
 * los ficheros de datos salvo para la copia en ZIP. Un único punto de entrada;
 * las páginas están en vistas/. Mientras no está configurado, lo que se
 * muestra es el asistente de instalación (lib/Instalador.php).
 *
 * https://miguelenred.es/jsonsqldb
 */

// Primero config.php, si existe; después la plantilla, que solo define lo que
// falte. Así un config.php de una versión anterior, sin las opciones nuevas,
// sigue funcionando con sus valores por defecto, y sin config.php se arranca
// con los de la plantilla, lo justo para que el asistente pueda pintarse
$rutaConfig = (string)(getenv('JSONSQLDBADMIN_CONFIG') ?: __DIR__ . '/config.php');
if (is_file($rutaConfig)) {
    require_once $rutaConfig;
}
require_once __DIR__ . '/config.dist.php';
require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Audit.php';
require_once __DIR__ . '/lib/Api.php';
require_once __DIR__ . '/lib/Exportar.php';
require_once __DIR__ . '/lib/Traductor.php';
require_once __DIR__ . '/lib/Importar.php';
require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/acciones.php';
require_once __DIR__ . '/lib/iconos.php';
require_once __DIR__ . '/lib/Instalador.php';
require_once __DIR__ . '/lib/Idioma.php';

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
    exit(t('Acceso no permitido desde esta IP.'));
}
// Sin configurar (sin config.php, o con las claves CHANGE_ME_ de la plantilla,
// que están publicadas en el repositorio): lo único que se sirve es el
// asistente, que no deja entrar a nada más hasta que termina
$asistente = Instalador::faltaConfig();

if (!$asistente && ADMIN_EXIGIR_HTTPS && !util_https()) {
    // El mensaje dice qué hacer, no solo qué pasa: en una máquina local esto es
    // lo primero con lo que se choca al instalar, y sin la indicación hay que
    // buscar la constante por el código
    http_response_code(403);
    exit(t("Este panel solo admite conexiones HTTPS y esta petición ha llegado por HTTP.\n\nEn un servidor de verdad: pon un certificado (Let's Encrypt es gratis).\nEn tu máquina, para probar: cambia ADMIN_EXIGIR_HTTPS a false en jsonsqldbadmin/config.php, y haz lo mismo con EXIGIR_HTTPS en api/jsonsqldb_api_config.php. Vuelve a ponerlas a true antes de publicar."));
}

Auth::iniciarSesion();
if (!Auth::revalidar()) {
    Auth::iniciarSesion();
    flash('warning', t('La sesión se ha cerrado: tu usuario ya no existe o su contraseña ha cambiado.'));
}

// Selector de idioma: ?idioma=en. Solo cambia el idioma (en la sesión y, si
// alguien ha entrado, en su usuario) y vuelve a la misma página sin el parámetro
if (isset($_GET['idioma']) && is_string($_GET['idioma'])) {
    Idioma::elegir($_GET['idioma']);
    $params = $_GET;
    unset($params['idioma']);
    redirigir($params);
}

$pagina = get('p', 'bases');
$base   = get('db');
$tabla  = get('tabla');

// ----------------------------------------------------------------------
// Primer arranque: asistente de instalación, o solo el administrador si la
// configuración ya está hecha (por ejemplo, con php configurar.php)
// ----------------------------------------------------------------------
if ($asistente || !Auth::hayUsuarios()) {
    $datos = ['completo' => $asistente];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            Auth::comprobarCsrf();
            if ($asistente) {
                $datos['resumen'] = Instalador::instalar($_POST);
                Audit::registrar('instalar', post('usuario'));
            } else {
                $nombre = Instalador::crearAdmin(post('usuario'), (string)($_POST['clave'] ?? ''),
                                                 (string)($_POST['clave2'] ?? ''));
                Audit::registrar('instalar', $nombre);
                flash('success', t('Administrador \'{nombre}\' creado. Ya puedes entrar.', ['nombre' => $nombre]));
                redirigir();
            }
        } catch (Throwable $e) {
            $datos['error'] = $e->getMessage();
        }
    }
    vista('instalar', $datos);
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
            'sql', 'auditoria', 'usuarios', 'configuracion'];
if (!in_array($pagina, $paginas, true)) {
    $pagina = 'bases';
}
if (in_array($pagina, ['tablas', 'vistas', 'integridad', 'crear_tabla', 'estructura', 'datos',
                       'sql'], true) && $base === '') {
    flash('warning', t('Elige primero una base de datos.'));
    redirigir(['p' => 'bases']);
}

// La página se prepara entera antes de enviarla: si algo falla a mitad, lo
// pintado se descarta y sale solo la página de error, no media página con el
// error metido dentro
ob_start();
try {
    vista($pagina, ['base' => $base, 'tabla' => $tabla]);
    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean();
    vista('error', ['base' => $base, 'tabla' => $tabla, 'mensaje' => $e->getMessage()]);
}

/** Pinta una vista dentro del layout. */
function vista(string $nombre, array $datos = []): void
{
    extract($datos, EXTR_SKIP);
    $vistaActual = $nombre;
    // El layout pinta los avisos; el login y el asistente los pintan ellos
    $mensajes = in_array($nombre, ['login', 'instalar'], true) ? flashes() : [];
    require __DIR__ . '/vistas/layout.php';
}
