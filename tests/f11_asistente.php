<?php
declare(strict_types=1);

/**
 * Prueba del asistente de instalación de jsonSQLDBadmin y de su conexión
 * directa al motor. Ejecutar: php tests/f11_asistente.php
 *
 * Levanta el servidor propio de PHP con el panel SIN configurar (su config.php
 * apunta a una carpeta temporal vacía), recorre el asistente como un usuario
 * y comprueba que el panel queda funcionando por conexión directa: que
 * escribe en la carpeta de datos del motor, que el motor aplica el rol de
 * cada usuario, y que el asistente no vuelve a aparecer una vez instalado.
 * No toca nada del proyecto: todo lo que escribe va a la carpeta temporal.
 *
 * https://miguelenred.es/jsonsqldb
 */
$raizProyecto = dirname(__DIR__);
$tmp          = sys_get_temp_dir() . '/jsonsqldb_test_asistente';
$config       = $tmp . '/panel/config.php';
$cookies      = $tmp . '/cookies.txt';
$ok = 0; $ko = 0;

if (!function_exists('curl_init')) {
    echo "Esta prueba necesita la extensión cURL.\n";
    exit(1);
}

function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach ((array)scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $r = "$dir/$f";
        is_dir($r) && !is_link($r) ? borrarArbol($r) : @unlink($r);
    }
    @rmdir($dir);
}
borrarArbol($tmp);
foreach (['panel', 'admin', 'datos', 'logs'] as $d) {
    @mkdir("$tmp/$d", 0775, true);
}

// Un puerto libre
$puerto = 0;
for ($p = 8931; $p < 9400; $p++) {
    $s = @stream_socket_server("tcp://127.0.0.1:$p");
    if ($s !== false) { fclose($s); $puerto = $p; break; }
}
$url = "http://127.0.0.1:$puerto/jsonsqldbadmin/index.php";

$prepend = "$tmp/_prepend.php";
file_put_contents($prepend, "<?php\n"
    . "putenv('JSONSQLDBADMIN_CONFIG=" . $config . "');\n"
    . "define('JSONSQLDB_DATA_PATH', " . var_export("$tmp/datos", true) . ");\n"
    . "define('JSONSQLDB_LOG_PATH', " . var_export("$tmp/logs", true) . ");\n"
    . "define('ADMIN_DATA_PATH', " . var_export("$tmp/admin", true) . ");\n");

$cmd  = escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file=' . escapeshellarg($prepend)
      . " -S 127.0.0.1:$puerto -t " . escapeshellarg($raizProyecto);
$proc = proc_open($cmd, [1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']], $t);
register_shutdown_function(static function () use ($proc, $tmp) {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    borrarArbol($tmp);
});
for ($i = 0; $i < 60; $i++) {
    usleep(150000);
    if (@fsockopen('127.0.0.1', $puerto, $e, $m, 0.3)) { break; }
}

function peticion(string $query, ?array $campos = null): string {
    global $url, $cookies;
    $ch = curl_init($url . ($query !== '' ? '?' . $query : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        // Las pruebas miran los textos en español: se piden en español, salvo
        // las del idioma, que cambian $idiomaPrueba
        CURLOPT_HTTPHEADER     => ['Accept-Language: ' . ($GLOBALS['idiomaPrueba'] ?? 'es')],
    ] + ($campos === null ? [] : [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($campos)]));
    $r = curl_exec($ch);
    curl_close($ch);
    return is_string($r) ? $r : '';
}
function csrf(string $query = ''): string {
    return preg_match('/name="csrf" value="([a-f0-9]{64})"/', peticion($query), $m) ? $m[1] : '';
}
function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
        if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
        else { $ko++; echo "  FALLO $titulo -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        $ko++;
        echo "  FALLO $titulo -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

$admin = ['usuario' => 'jefa', 'clave' => 'clave-muy-larga-1', 'clave2' => 'clave-muy-larga-1'];

echo "\n== Asistente ==\n";
chk('sin config.php se abre el asistente, no el login', function () {
    $html = peticion('');
    return str_contains($html, 'Asistente de configuración inicial') && str_contains($html, 'Conexión directa')
        && !str_contains($html, 'Iniciar sesión');
});
chk('ninguna otra página se sirve mientras no está instalado', fn() =>
    str_contains(peticion('p=bases'), 'Asistente de configuración inicial'));
chk('sin token CSRF no instala nada', function () use ($admin, $config) {
    $html = peticion('', ['conexion' => 'directa'] + $admin);
    return str_contains($html, 'Formulario caducado') && !is_file($config);
});
/** El código de instalación que ha dejado el panel en su carpeta de datos. */
function codigo(): string {
    global $tmp;
    return trim((string)@file_get_contents("$tmp/admin/codigo-instalacion.txt"));
}
chk('sin el código de instalación, que está en el servidor, no instala nada', function () use ($admin, $config) {
    // Antes, el primero que llegaba al panel recién publicado lo instalaba
    $html = peticion('', ['csrf' => csrf(), 'codigo' => 'abcd-abcd-abcd-abcd', 'conexion' => 'directa', 'motor' => '', 'http' => '1'] + $admin);
    return str_contains($html, 'código de instalación no es correcto') && !is_file($config) && codigo() !== '' ?: 'instaló o no avisó';
});
chk('una carpeta que no es jsonSQLDB se rechaza sin escribir nada', function () use ($admin, $config) {
    $html = peticion('', ['csrf' => csrf(), 'codigo' => codigo(), 'conexion' => 'directa', 'motor' => '/no/existe'] + $admin);
    return str_contains($html, 'no está jsonSQLDB') && !is_file($config) ?: 'o escribió, o no avisó';
});
chk('una API que no responde se rechaza sin escribir nada', function () use ($admin, $config) {
    $html = peticion('', ['csrf' => csrf(), 'codigo' => codigo(), 'conexion' => 'api', 'api_url' => 'http://127.0.0.1:1/api.php',
                          'api_key' => 'x', 'api_secret' => 'y'] + $admin);
    return str_contains($html, 'alert-danger') && !is_file($config) ?: 'o escribió, o no avisó';
});
chk('las dos contraseñas tienen que coincidir', fn() =>
    str_contains(peticion('', ['csrf' => csrf(), 'codigo' => codigo(), 'conexion' => 'directa', 'clave2' => 'otra-distinta-1'] + $admin),
                 'no coinciden'));
chk('con conexión directa, prueba el motor y lo instala', function () use ($admin, $config) {
    $html = peticion('', ['csrf' => csrf(), 'codigo' => codigo(), 'conexion' => 'directa', 'motor' => '', 'http' => '1'] + $admin);
    if (!str_contains($html, 'jsonSQLDBadmin está instalado')) { return 'no terminó'; }
    if (codigo() !== '') { return 'el código de instalación sigue ahí'; }
    $texto = (string)@file_get_contents($config);
    if (!str_contains($texto, "define('ADMIN_CONEXION', 'directa')")) { return 'config.php sin la conexión directa'; }
    if (str_contains($texto, "'CHANGE_ME")) { return 'quedan valores CHANGE_ME en config.php'; }
    if (DIRECTORY_SEPARATOR === '/' && (fileperms($config) & 0077) !== 0) { return 'config.php legible por otros'; }
    return true;
});
chk('instalado, el asistente ya no aparece: pide entrar', function () {
    $html = peticion('');
    return str_contains($html, 'Iniciar sesión') && !str_contains($html, 'Asistente');
});

echo "\n== Panel por conexión directa ==\n";
chk('entra y dice que va por conexión directa', function () use ($admin) {
    $html = peticion('', ['csrf' => csrf(), 'usuario' => $admin['usuario'], 'clave' => $admin['clave']]);
    return str_contains($html, 'Bases de datos') && str_contains($html, '>Directa<');
});
chk('crea una base, y está en la carpeta de datos del motor', function () use ($tmp) {
    $html = peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'crear_base', 'nombre' => 'tienda']);
    return str_contains($html, 'tienda&#039; creada') && is_dir("$tmp/datos/tienda");
});
chk('SQL por la consola, sin API', function () {
    peticion('p=sql&db=tienda', ['csrf' => csrf('p=sql&db=tienda'), 'sql' =>
        'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, n VARCHAR(10))']);
    peticion('p=sql&db=tienda', ['csrf' => csrf('p=sql&db=tienda'), 'sql' => "INSERT INTO t (n) VALUES ('uno'), ('dos')"]);
    $html = peticion('p=datos&db=tienda&tabla=t');
    return str_contains($html, 'uno') && str_contains($html, 'dos');
});
chk('el log del motor lleva quién lo hizo desde el panel', function () use ($tmp) {
    $todo = '';
    foreach ((array)glob("$tmp/logs/*") as $f) { $todo .= (string)file_get_contents((string)$f); }
    return str_contains($todo, 'jsonSQLDBadmin (jefa)') ?: 'el log no nombra al usuario del panel';
});
chk('la página de configuración describe la conexión', function () {
    $html = peticion('p=configuracion');
    return str_contains($html, 'Directa al motor') && str_contains($html, 'Respuesta del motor');
});
chk('una página que no existe no rompe el panel', fn() =>
    str_contains(peticion('p=fila&db=tienda'), 'Bases de datos'));

chk('ninguna página muestra avisos ni errores de PHP', function () use ($tmp) {
    $paginas = ['p=bases', 'p=tablas&db=tienda', 'p=datos&db=tienda&tabla=t', 'p=estructura&db=tienda&tabla=t',
                'p=sql&db=tienda', 'p=vistas&db=tienda', 'p=integridad&db=tienda', 'p=crear_tabla&db=tienda',
                'p=auditoria', 'p=usuarios', 'p=configuracion'];
    foreach ($paginas as $q) {
        $html = peticion($q);
        if (preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|(Warning|Notice|Deprecated|Fatal error): /', $html)) {
            return "aviso de PHP en $q";
        }
        if (!str_contains($html, '</html>')) {
            return "la página $q no termina";
        }
    }
    $log = (string)@file_get_contents("$tmp/server.log");
    return !preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log) ?: 'el servidor registró: '
        . substr((string)preg_replace('/.*?(PHP (Warning|Notice|Deprecated|Fatal)[^\n]*).*/s', '$1', $log), 0, 200);
});

echo "\n== Configuración desde el panel ==\n";
$guardar = static function (array $campos): string {
    // El formulario tal como viene de la página, con los cambios encima
    $html = peticion('p=configuracion');
    $base = ['csrf' => csrf('p=configuracion'), 'accion' => 'guardar_configuracion', 'conexion' => 'directa'];
    foreach (['timeout', 'sesion_minutos', 'login_max_fallos', 'bloqueo_min', 'bcrypt', 'audit_dias',
              'filas_pagina', 'celda_max', 'export_max'] as $c) {
        if (preg_match('/name="' . $c . '"[^>]*value="(\d+)"/', $html, $m)) { $base[$c] = $m[1]; }
    }
    $base['csv_separador'] = ';';
    return peticion('p=configuracion', $campos + $base);
};
chk('un config.php de una versión anterior, sin las opciones nuevas, funciona con sus valores por defecto', function () use ($config) {
    // Como uno hecho por php configurar.php en la 2.6: sin la carpeta del
    // motor, la clave de lectura ni el tiempo máximo
    $antes = (string)file_get_contents($config);
    $viejo = (string)preg_replace("/^.*define\\('(ADMIN_MOTOR_RUTA|ADMIN_API_KEY_LECTURA|ADMIN_HMAC_SECRET_LECTURA|ADMIN_TIMEOUT)'.*\\n/m", '', $antes);
    if ($viejo === $antes) { return 'no se pudo simular el fichero antiguo'; }
    file_put_contents($config, $viejo);
    $html = peticion('p=configuracion');
    file_put_contents($config, $antes);
    if (str_contains($html, 'Undefined constant')) { return 'falta una constante: ' . (preg_match('/Undefined constant[^<]*/', $html, $m) ? $m[0] : ''); }
    return str_contains($html, 'Guardar la configuración') ?: 'la página no salió';
});
chk('si una página falla a mitad, sale solo la página de error', function () {
    $html = peticion('p=datos&db=tienda&tabla=noexiste');
    return substr_count($html, '<html') === 1 && str_contains($html, 'No se ha podido completar')
        ?: 'salen ' . substr_count($html, '<html') . ' páginas';
});
chk('la página de configuración es un formulario', fn() =>
    str_contains(peticion('p=configuracion'), 'Guardar la configuración'));
chk('guardar cambia config.php y se ve al momento', function () use ($guardar, $config) {
    $html = $guardar(['filas_pagina' => '20', 'celda_max' => '200', 'csv_separador' => ',']);
    $texto = (string)file_get_contents($config);
    return str_contains($html, 'Configuración guardada') && str_contains($texto, "define('ADMIN_FILAS_PAGINA', 20)")
        && str_contains($texto, "define('ADMIN_CSV_SEPARADOR', ',')")
        && preg_match('/name="filas_pagina"[^>]*value="20"/', $html) === 1 ?: 'no se guardó o no se ve';
});
chk('un valor fuera de rango no se guarda', function () use ($guardar, $config) {
    $antes = (string)file_get_contents($config);
    $html = $guardar(['filas_pagina' => '5']);
    return str_contains($html, 'entre 10 y 1000') && file_get_contents($config) === $antes ?: 'lo aceptó';
});
chk('una lista de IPs sin la propia no se acepta: dejaría fuera', function () use ($guardar, $config) {
    $antes = (string)file_get_contents($config);
    $html = $guardar(['ips' => "10.9.9.9\n192.168.50.0/24"]);
    return str_contains($html, 'te quedarías fuera') && file_get_contents($config) === $antes ?: 'la aceptó';
});
chk('exigir HTTPS entrando por HTTP no se acepta', function () use ($guardar, $config) {
    $antes = (string)file_get_contents($config);
    $html = $guardar(['exigir_https' => '1']);
    return str_contains($html, 'te dejaría fuera') && file_get_contents($config) === $antes ?: 'lo aceptó';
});
chk('una conexión nueva que no responde no se guarda', function () use ($guardar, $config) {
    $antes = (string)file_get_contents($config);
    $html = $guardar(['conexion' => 'api', 'api_url' => 'http://127.0.0.1:1/api.php', 'api_key' => 'k', 'api_secret' => 's']);
    return str_contains($html, 'alert-danger') && file_get_contents($config) === $antes ?: 'la guardó';
});
chk('las claves no se muestran y la auditoría no guarda valores', function () use ($guardar, $config, $tmp) {
    $guardar(['lectura_key' => 'CLAVE-LECTURA-SECRETA-123', 'lectura_secret' => 'SECRETO-LECTURA-456']);
    $texto = (string)file_get_contents($config);
    $html = peticion('p=configuracion');
    $audit = '';
    foreach ((array)glob("$tmp/admin/auditoria-*.json") as $f) { $audit .= (string)file_get_contents((string)$f); }
    if (!str_contains($texto, 'SECRETO-LECTURA-456')) { return 'no guardó la clave de lectura'; }
    if (str_contains($html, 'SECRETO-LECTURA-456') || str_contains($html, 'CLAVE-LECTURA-SECRETA-123')) { return 'la página muestra la clave'; }
    if (str_contains($audit, 'SECRETO-LECTURA-456')) { return 'la auditoría guarda el secreto'; }
    // Quitarla deja las dos vacías
    $guardar(['quitar_lectura' => '1']);
    return !str_contains((string)file_get_contents($config), 'SECRETO-LECTURA-456') ?: 'no la quitó';
});

echo "\n== El motor aplica el rol del usuario ==\n";
chk('crear un usuario de solo lectura', function () {
    $html = peticion('p=usuarios', ['csrf' => csrf('p=usuarios'), 'accion' => 'crear_usuario',
                                    'usuario' => 'mirona', 'clave' => 'clave-lectura-1', 'rol' => 'lectura']);
    return str_contains($html, 'mirona');
});
chk('con él, un SELECT funciona y un DELETE lo rechaza el motor', function () {
    peticion('p=salir', ['csrf' => csrf('p=bases')]);
    peticion('', ['csrf' => csrf(), 'usuario' => 'mirona', 'clave' => 'clave-lectura-1']);
    $lee = peticion('p=sql&db=tienda', ['csrf' => csrf('p=sql&db=tienda'), 'sql' => 'SELECT COUNT(*) AS n FROM t']);
    $borra = peticion('p=sql&db=tienda', ['csrf' => csrf('p=sql&db=tienda'), 'sql' => 'DELETE FROM t']);
    if (!str_contains($lee, '<td>2</td>')) { return 'el SELECT no devolvió 2'; }
    return (str_contains($borra, 'lectura') && str_contains(peticion('p=datos&db=tienda&tabla=t'), 'uno'))
        ?: 'el DELETE pasó o no avisó';
});

chk('y es el motor quien lo rechaza, no solo el panel', function () use ($prepend, $raizProyecto) {
    // Sin pasar por las páginas (que ya comprueban el rol): la llamada misma
    // que hace el panel, con la sesión de un usuario de lectura
    // -r no aplica auto_prepend_file: el prepend se carga a mano
    $codigo = 'require ' . var_export($prepend, true) . '; $_SERVER["REQUEST_METHOD"] = "GET"; '
            . 'foreach (["config.php" => getenv("JSONSQLDBADMIN_CONFIG"), "lib/Store.php" => 0, "lib/Auth.php" => 0, '
            . '"lib/Audit.php" => 0, "lib/Api.php" => 0, "lib/util.php" => 0, "lib/Idioma.php" => 0] as $f => $r) { '
            . 'require_once $r ?: ' . var_export("$raizProyecto/jsonsqldbadmin/", true) . ' . $f; } '
            . '$_SESSION = ["usuario" => ["usuario" => "mirona", "rol" => "lectura"], "idioma" => "es"]; '
            . 'try { Api::sql("tienda", "DELETE FROM t"); echo "PASO"; } catch (Throwable $e) { echo $e->getMessage(); }';
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1');
    return str_contains($salida, 'solo tiene permiso de lectura') ?: 'salida: ' . substr($salida, 0, 200);
});

chk('un usuario de solo lectura no puede cambiar la configuración', function () use ($config) {
    $antes = (string)file_get_contents($config);
    $html = peticion('p=usuarios', ['csrf' => csrf('p=usuarios'), 'accion' => 'guardar_configuracion', 'filas_pagina' => '30']);
    return str_contains($html, 'permiso de administrador') && file_get_contents($config) === $antes ?: 'pudo';
});

echo "\n== Las sesiones siguen al usuario guardado ==\n";
chk('cambiarle la contraseña a un usuario cierra sus sesiones abiertas', function () use ($admin) {
    global $cookies;
    $suya = $cookies;                                   // mirona tiene aquí su sesión abierta
    $cookies = $suya . '.admin';
    peticion('', ['csrf' => csrf(), 'usuario' => $admin['usuario'], 'clave' => $admin['clave']]);
    peticion('p=usuarios', ['csrf' => csrf('p=usuarios'), 'accion' => 'cambiar_clave',
                            'usuario' => 'mirona', 'clave' => 'otra-clave-lectura-2']);
    $cookies = $suya;
    $html = peticion('p=bases');
    return str_contains($html, 'La sesión se ha cerrado') && str_contains($html, 'Iniciar sesión') ?: 'la sesión seguía abierta';
});
chk('borrar un usuario cierra sus sesiones abiertas', function () use ($admin) {
    global $cookies;
    $suya = $cookies;
    peticion('', ['csrf' => csrf(), 'usuario' => 'mirona', 'clave' => 'otra-clave-lectura-2']);
    if (!str_contains(peticion('p=bases'), 'Bases de datos')) { return 'mirona no pudo entrar'; }
    $cookies = $suya . '.admin';
    peticion('p=usuarios', ['csrf' => csrf('p=usuarios'), 'accion' => 'borrar_usuario', 'usuario' => 'mirona']);
    $cookies = $suya;
    return str_contains(peticion('p=bases'), 'La sesión se ha cerrado') ?: 'la sesión seguía abierta';
});
chk('cambiar la propia contraseña no cierra la propia sesión', function () use ($admin) {
    global $cookies;
    $cookies .= '.admin';
    peticion('p=usuarios', ['csrf' => csrf('p=usuarios'), 'accion' => 'cambiar_clave', 'clave' => 'clave-muy-larga-2']);
    return str_contains(peticion('p=bases'), 'Bases de datos') ?: 'la cerró';
});

echo "\n== Idiomas ==\n";
chk('sin pedir idioma, o pidiendo uno que no hay, el panel sale en inglés', function () {
    global $cookies, $idiomaPrueba;
    $antes = [$cookies, $idiomaPrueba ?? null];
    $cookies = sys_get_temp_dir() . '/f11_idioma_' . getmypid();
    $mal = [];
    foreach (['' => 'en', 'fr-FR,fr;q=0.9' => 'en', 'sv-SE' => 'en', 'fr-FR,es;q=0.8' => 'es', 'es-ES,es;q=0.9' => 'es'] as $cab => $esp) {
        @unlink($cookies);
        $idiomaPrueba = $cab;
        $html = peticion('');
        if (!str_contains($html, '<html lang="' . $esp . '"') || !str_contains($html, $esp === 'en' ? 'Sign in' : 'Iniciar sesión')) {
            $mal[] = "«$cab»";
        }
    }
    @unlink($cookies);
    [$cookies, $idiomaPrueba] = $antes;
    return $mal === [] ?: 'mal con ' . implode(', ', $mal);
});
chk('el selector guarda el idioma en el usuario y le sigue en otra sesión y otro navegador', function () use ($admin) {
    global $cookies;
    $html = peticion('p=bases&idioma=en');
    if (!str_contains($html, '<html lang="en"') || !str_contains($html, 'Databases')) { return 'el selector no cambió el idioma'; }
    $suya = $cookies;
    $cookies = $suya . '.otra';                         // otro navegador, que pide español
    @unlink($cookies);
    peticion('', ['csrf' => csrf(), 'usuario' => $admin['usuario'], 'clave' => 'clave-muy-larga-2']);
    $html = peticion('p=bases');
    @unlink($cookies);
    $cookies = $suya;
    return str_contains($html, '<html lang="en"') && str_contains($html, 'Databases') ?: 'en otra sesión no siguió en inglés';
});
chk('las páginas en inglés no dejan textos en español', function () {
    $paginas = ['p=bases', 'p=tablas&db=tienda', 'p=datos&db=tienda&tabla=t', 'p=estructura&db=tienda&tabla=t', 'p=sql&db=tienda',
                'p=vistas&db=tienda', 'p=integridad&db=tienda', 'p=usuarios', 'p=auditoria', 'p=configuracion', 'p=crear_tabla&db=tienda'];
    foreach ($paginas as $q) {
        $html = peticion($q);
        if (!str_contains($html, '<html lang="en"')) { return "$q no está en inglés"; }
        // Fuera lo que son datos o código: celdas, código, opciones y las entradas de la auditoría
        $texto = (string)preg_replace('#<(script|style|td|code|pre|textarea|option|kbd)\b.*?</\1>#si', ' ', $html);
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8');
        if (preg_match('/\S*[áéíóúñ¿¡]\S*/iu', $texto, $m)) { return "$q: «{$m[0]}»"; }
        foreach (['Guardar', 'Borrar', 'Tablas', 'Bases de datos', 'Cerrar sesión', 'Configuración', 'Exportar', 'Importar', 'Consola',
                  'Usuarios', 'Estructura', 'Insertar', 'Filtrar', 'Contraseña', 'Nueva', 'Nuevo', 'Volver', 'Columnas'] as $palabra) {
            if (preg_match('/\b' . preg_quote($palabra, '/') . '\b/u', $texto)) { return "{$q}: «{$palabra}»"; }
        }
    }
    peticion('p=bases&idioma=es');                     // como estaba, para lo que venga después
    return true;
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
