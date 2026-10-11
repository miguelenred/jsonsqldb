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

require_once __DIR__ . '/lib/arranque.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// Todo lo que carga el panel es local: nada de CDNs ni de recursos externos
// Los scripts, solo los del panel y los de la propia página con su nonce: un
// script inyectado no se ejecutaría. Los estilos siguen admitiendo style="…"
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
     . "style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-" . nonce() . "'; "
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

// Un formulario más grande que post_max_size llega vacío: PHP lo descarta
// entero, y lo que se veía era «formulario caducado» o nada. Se dice qué ha
// pasado y se vuelve a la página (2.9)
$enviado = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $_POST === [] && $_FILES === []
    && bytesIni('post_max_size') > 0 && $enviado > bytesIni('post_max_size')) {
    flash('danger', t('Lo enviado ocupa {n} KB y el servidor solo acepta {m} KB por petición (post_max_size de PHP): no se ha hecho nada. Envía menos de una vez, o sube ese límite.',
        ['n' => intdiv($enviado, 1024), 'm' => intdiv(bytesIni('post_max_size'), 1024)]));
    redirigir($_GET);
}

// Selector de idioma: ?idioma=en. Solo cambia el idioma y vuelve a la misma
// página sin el parámetro. En la sesión, siempre; en el usuario que ha entrado,
// solo con el token de los enlaces del selector (2.9): sin él, un enlace o una
// imagen en otra página cambiaba el idioma guardado de quien la abriera
if (isset($_GET['idioma'])) {
    Idioma::elegir(get('idioma'), hash_equals(Auth::tokenIdioma(), get('token')));
    $params = $_GET;
    unset($params['idioma'], $params['token']);
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
            Instalador::comprobarCodigo(post('codigo'));
            if ($asistente) {
                $datos['resumen'] = Instalador::instalar($_POST);
                Instalador::borrarCodigo();
                Audit::registrar('instalar', post('usuario'));
            } else {
                $nombre = Instalador::crearAdmin(post('usuario'), textoDe($_POST, 'clave'), textoDe($_POST, 'clave2'));
                Instalador::borrarCodigo();
                Audit::registrar('instalar', $nombre);
                flash('success', t('Administrador \'{nombre}\' creado. Ya puedes entrar.', ['nombre' => $nombre]));
                redirigir();
            }
        } catch (Throwable $e) {
            $datos['error'] = $e->getMessage();
        }
    }
    if (!isset($datos['resumen'])) {
        try {
            Instalador::codigo();                   // que el fichero exista para poder leerlo
        } catch (Throwable $e) {
            $datos['error'] ??= $e->getMessage();
        }
    }
    vista('instalar', $datos);
    exit;
}

// ----------------------------------------------------------------------
// Acceso
// ----------------------------------------------------------------------
// Cerrar sesión, solo por POST y con su token: un enlace o una imagen en otra
// página no pueden cerrar la sesión de nadie
if ($pagina === 'salir' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        Auth::comprobarCsrf();
    } catch (RuntimeException $e) {
        // Sin token válido no se cierra nada: se avisa, en vez de un error fatal
        flash('danger', $e->getMessage());
        redirigir(['p' => 'bases']);
    }
    Audit::registrar('salir');
    Auth::cerrar();
    redirigir();
}
// Un enlace viejo a ?p=salir (GET): a la portada, sin cerrar nada
if ($pagina === 'salir') {
    redirigir(['p' => 'bases']);
}

if (!Auth::identificado()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $usuario = post('usuario');
        $intento = false;                     // ¿se ha probado de verdad una contraseña?
        try {
            // También el acceso lleva su token: sin él, otra página podría hacer
            // entrar al navegador de alguien con una cuenta elegida por ella
            Auth::comprobarCsrf();
            $intento = Auth::bloqueoRestante(util_ip()) === 0;
            Auth::entrar($usuario, textoDe($_POST, 'clave'), util_ip());
            Audit::registrar('entrar');
            redirigir(['p' => 'bases']);
        } catch (Throwable $e) {
            // Solo los intentos de verdad, que el bloqueo por IP limita, y el
            // nombre recortado (2.9): sin token, o con la IP ya bloqueada, cada
            // petición apuntaba lo que trajera, y cualquiera podía llenar el disco
            if ($intento) {
                Audit::registrar('acceso_fallido', substr((string)preg_replace('/[^\x20-\x7E]/', '?', $usuario), 0, 64));
            }
            $error = $e->getMessage();
        }
    }
    vista('login', ['error' => $error ?? null]);
    exit;
}

// Los scripts de PowerShell que vuelcan una base de Access o de SQL Server
// para importarla aquí, y cargan allí lo que se exporta para ellas
$scripts = ['script_access' => 'access-to-jsonsqldb.ps1', 'script_sqlserver' => 'sqlserver-to-jsonsqldb.ps1'];
if (isset($scripts[$pagina])) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $scripts[$pagina] . '"');
    header('X-Content-Type-Options: nosniff');
    readfile(__DIR__ . '/herramientas/' . $scripts[$pagina]);
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
            'sql', 'auditoria', 'usuarios', 'configuracion', 'copias'];
if ($pagina === 'copias' && !Auth::esAdmin()) {
    $pagina = 'bases';
}
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
