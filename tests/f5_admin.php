<?php
declare(strict_types=1);

/**
 * Prueba de jsonSQLDBadmin. Ejecutar: php tests/f5_admin.php
 *
 * Levanta el servidor propio de PHP sobre la raíz del proyecto y navega el
 * panel como lo haría un usuario: cookies de sesión, tokens CSRF y
 * formularios reales. El panel habla con la API, y la API con el motor.
 *
 * https://miguelenred.es/jsonsqldb
 */
$raizProyecto = dirname(__DIR__);
$raizDatos    = sys_get_temp_dir() . '/jsonsqldb_test_admin';
// Dos puertos libres consecutivos. Elegirlos a partir del PID hacía que dos
// ejecuciones seguidas pudieran chocar con un puerto aún en TIME_WAIT.
[$puertoPanel, $puertoApi] = puertosLibres();

/**
 * Busca dos puertos consecutivos que se puedan abrir ahora mismo.
 *
 * @return array{0:int,1:int}
 */
function puertosLibres(): array
{
    for ($p = 8731; $p < 9500; $p += 2) {
        $a = @stream_socket_server("tcp://127.0.0.1:$p", $e, $m);
        if ($a === false) { continue; }
        $b = @stream_socket_server('tcp://127.0.0.1:' . ($p + 1), $e, $m);
        fclose($a);
        if ($b === false) { continue; }
        fclose($b);
        return [$p, $p + 1];
    }
    echo "No hay puertos libres para levantar los servidores de prueba.\n";
    exit(1);
}
$url          = "http://127.0.0.1:$puertoPanel";
$cookies      = $raizDatos . '/cookies.txt';
$ok = 0; $ko = 0;

if (!function_exists('curl_init')) {
    echo "Esta prueba necesita la extensión cURL.\n";
    exit(1);
}

// --- Entorno limpio ---
borrarArbol($raizDatos);
@mkdir($raizDatos . '/subidas_php', 0775, true);
@mkdir($raizDatos . '/admin', 0775, true);

// Constantes de prueba, inyectadas en cada petición del servidor
$prepend = $raizDatos . '/_prepend.php';
file_put_contents($prepend, "<?php\n"
    . "define('JSONSQLDB_DATA_PATH', " . var_export($raizDatos, true) . ");\n"
    . "define('JSONSQLDB_LOG_PATH', "  . var_export($raizDatos . '/logs', true) . ");\n"
    . "define('API_ESTADO_PATH', "     . var_export($raizDatos . '/api', true) . ");\n"
    . "define('ADMIN_DATA_PATH', "     . var_export($raizDatos . '/admin', true) . ");\n"
    . "define('ADMIN_API_URL', "       . var_export("http://127.0.0.1:$puertoApi/api/jsonsqldb_api.php", true) . ");\n"
    . "define('ADMIN_SSL_CA', '');\n"
    // Las pruebas corren por HTTP en localhost y repiten peticiones desde la
    // misma IP: se relajan las protecciones que eso dispara.
    . "define('EXIGIR_HTTPS', false);\n"
    . "define('ADMIN_EXIGIR_HTTPS', false);\n"
    . "define('RATE_LIMIT_ACTIVO', false);\n"
    . "define('ANTI_REPLAY_ACTIVO', false);\n"
    . "define('DEVOLVER_ERRORES', true);\n"
    // La copia en ZIP lee los ficheros del motor: hay que decirle dónde están,
    // igual que en una instalación donde el panel y el motor comparten máquina.
    . "define('ADMIN_RUTA_DATOS_MOTOR', " . var_export($raizDatos, true) . ");\n"
    // Un panel que no tiene el motor a mano (instalado aparte): importa por la
    // API, sin transacción. Lo pide la cabecera X-Prueba de una petición
    . "if ((\$_SERVER['HTTP_X_PRUEBA'] ?? '') === 'sin-motor') { define('ADMIN_MOTOR_RUTA', '/no/existe'); }\n");

// --- Servidores ---
// Dos procesos: uno sirve el panel y otro la API. El servidor propio de PHP
// atiende una petición cada vez, y el panel llama a la API dentro de su propia
// petición: con un solo proceso se quedaría esperándose a sí mismo.
$servidores = [];
foreach ([$puertoPanel, $puertoApi] as $p) {
    // Con --open-basedir, como en un hosting: solo el proyecto y sus datos, las
    // subidas a una carpeta propia, y cualquier aviso de PHP en la respuesta
    $limitado = in_array('--open-basedir', $argv, true)
        ? ' -d open_basedir=' . escapeshellarg($raizProyecto . PATH_SEPARATOR . $raizDatos)
          . ' -d upload_tmp_dir=' . escapeshellarg($raizDatos . '/subidas_php') . ' -d error_reporting=-1 -d display_errors=1'
        : '';
    // Con --php-puro (2.9), sin mbstring ni cURL, como en un hosting que no
    // los tiene: el panel tiene que funcionar igual (la API, con los flujos de PHP)
    if (in_array('--php-puro', $argv, true)) {
        $limitado .= ' -d disable_functions=' . implode(',', array_merge(
            array_filter(get_defined_functions()['internal'], static fn(string $f): bool => str_starts_with($f, 'mb_') || str_starts_with($f, 'curl_'))
        ));
    }
    // Con serialize_precision a 10, como un php.ini que lo baja: el panel y la
    // API tienen que dar igual los decimales enteros (2.10)
    $cmd = escapeshellarg(PHP_BINARY) . $limitado . ' -d serialize_precision=10 -d auto_prepend_file=' . escapeshellarg($prepend)
         . " -S 127.0.0.1:$p -t " . escapeshellarg($raizProyecto);
    // Con exec, el shell se convierte en el servidor: si no, proc_terminate()
    // mata el shell y el servidor se queda funcionando, huérfano
    $proc = proc_open((PHP_OS_FAMILY === 'Windows' ? '' : 'exec ') . $cmd, [1 => ['file', $raizDatos . '/server.log', 'a'],
                             2 => ['file', $raizDatos . '/server.log', 'a']], $tuberias);
    if (!is_resource($proc)) {
        echo "No se pudo arrancar el servidor de pruebas en el puerto $p.\n";
        exit(1);
    }
    $servidores[] = $proc;
}
register_shutdown_function(static function () use ($servidores, $raizDatos) {
    foreach ($servidores as $proc) {
        if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    }
    borrarArbol($raizDatos);
});

// Esperar a que respondan los dos
foreach ([$puertoPanel, $puertoApi] as $p) {
    for ($i = 0; $i < 60; $i++) {
        usleep(150000);
        if (@fsockopen('127.0.0.1', $p, $e, $s, 0.3)) { break; }
    }
}

// ----------------------------------------------------------------------
// Utilidades
// ----------------------------------------------------------------------

function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
        if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
        else { $ko++; echo "  FALLO $titulo -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        global $ko; $ko++;
        echo "  FALLO $titulo -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach ((array)scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $r = "$dir/$f";
        is_dir($r) ? borrarArbol($r) : @unlink($r);
    }
    @rmdir($dir);
}

/** GET del panel. Devuelve el HTML. */
/**
 * Saca de una página el mensaje de aviso o de error.
 *
 * Sin esto, un fallo se informaba con los primeros 160 caracteres de la página
 * ya sin etiquetas, que son la hoja de estilos: en la CI se veía
 * «FALLO ... -> 'jsonSQLDBadmin body { font-size: .925rem; }'», que no dice
 * absolutamente nada de lo que pasó.
 */
function mensajeDe(string $html): string {
    if (preg_match_all('#<div class="alert[^"]*"[^>]*>(.*?)</div>#s', $html, $m) === 0) {
        return 'sin mensaje en la página';
    }
    $textos = [];
    foreach ($m[1] as $bloque) {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags($bloque)));
        if ($t !== '') { $textos[] = $t; }
    }
    return $textos === [] ? 'sin mensaje en la página' : implode(' | ', $textos);
}

function pedir(string $query = ''): string {
    return peticion($query, null);
}

/**
 * POST del panel con el CSRF ya incorporado.
 * $origen indica de qué página se lee el token cuando la de destino no tiene
 * formularios (por ejemplo, un usuario de solo lectura en el listado de bases).
 */
function enviar(string $query, array $campos, bool $conCsrf = true, ?string $origen = null): string {
    if ($conCsrf) {
        $campos['csrf'] = csrfActual($origen ?? $query);
    }
    return peticion($query, $campos);
}

/** POST que devuelve un fichero (CSV, ZIP...). Igual que enviar(), sin tocar el cuerpo. */
function descargar(string $query, array $campos): string {
    $campos['csrf'] = csrfActual($query);
    return peticion($query, $campos);
}

/** POST con un fichero adjunto, como haría un formulario con enctype multipart. */
function subir(string $query, array $campos, string $campoFichero, string $ruta): string {
    global $url, $cookies;
    $campos['csrf'] = csrfActual($query);
    $campos[$campoFichero] = new CURLFile($ruta, 'application/zip', basename($ruta));

    $ch = curl_init($url . '/jsonsqldbadmin/index.php?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $campos,      // array => multipart/form-data
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        // Las pruebas miran los textos en español: se piden en español
        CURLOPT_HTTPHEADER     => array_merge(['Accept-Language: ' . ($GLOBALS['idiomaPrueba'] ?? 'es')], $GLOBALS['cabecerasPrueba'] ?? []),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $r = curl_exec($ch);
    return is_string($r) ? $r : '';
}

/** Token CSRF leído de la página indicada. */
function csrfActual(string $query): string {
    if (preg_match('/name="csrf" value="([a-f0-9]{64})"/', pedir($query), $m)) {
        return $m[1];
    }
    throw new RuntimeException("No se encontró el token CSRF en '$query'");
}

/** Número de filas de una tabla, leído del propio panel. */
function filasDe(string $tabla): int {
    $html = enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM ' . $tabla]);
    return preg_match('/<td>(\d+)<\/td>/', $html, $m) ? (int)$m[1] : -1;
}

function peticion(string $query, ?array $post): string {
    global $url, $cookies;
    $ch = curl_init($url . '/jsonsqldbadmin/index.php' . ($query === '' ? '' : '?' . $query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        // Las pruebas miran los textos en español: se piden en español
        CURLOPT_HTTPHEADER     => ['Accept-Language: ' . ($GLOBALS['idiomaPrueba'] ?? 'es')],
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $r = curl_exec($ch);
    if ($r === false) { throw new RuntimeException('cURL: ' . curl_error($ch)); }
    return (string)$r;
}

// ----------------------------------------------------------------------

echo "\n== Instalación y acceso ==\n";
chk('sin usuarios pide crear el administrador', fn() =>
    str_contains(pedir(), 'Crea el administrador'));
chk('crear el administrador sin token CSRF se rechaza', fn() =>
    str_contains(peticion('', ['usuario' => 'jefe', 'clave' => 'clave-muy-larga-1', 'clave2' => 'clave-muy-larga-1']),
                 'Formulario caducado'));
/** El código de instalación que ha dejado el panel en su carpeta de datos. */
function codigo(): string {
    global $raizDatos;
    return trim((string)@file_get_contents($raizDatos . '/admin/codigo-instalacion.txt'));
}
chk('sin el código de instalación (que está en el servidor) no se crea el administrador', fn() =>
    str_contains(peticion('', ['csrf' => csrfActual(''), 'codigo' => '0000-0000-0000-0000', 'usuario' => 'jefe',
                               'clave' => 'clave-muy-larga-1', 'clave2' => 'clave-muy-larga-1']), 'código de instalación no es correcto')
    && codigo() !== '');
chk('la contraseña corta se rechaza', fn() =>
    str_contains(peticion('', ['csrf' => csrfActual(''), 'codigo' => codigo(), 'usuario' => 'jefe', 'clave' => 'corta', 'clave2' => 'corta']),
                 'al menos 10 caracteres'));
chk('las dos contraseñas tienen que coincidir', fn() =>
    str_contains(peticion('', ['csrf' => csrfActual(''), 'codigo' => codigo(), 'usuario' => 'jefe', 'clave' => 'clave-muy-larga-1',
                               'clave2' => 'clave-muy-larga-2']), 'no coinciden'));
chk('se crea el administrador', fn() =>
    str_contains(peticion('', ['csrf' => csrfActual(''), 'codigo' => codigo(), 'usuario' => 'jefe', 'clave' => 'clave-muy-larga-1',
                               'clave2' => 'clave-muy-larga-1']), 'Ya puedes entrar') && codigo() === '');
chk('ahora pide usuario y contraseña', fn() =>
    str_contains(pedir(), 'name="clave"') && !str_contains(pedir(), 'Crea el administrador'));
chk('entrar sin token CSRF se rechaza', fn() =>
    str_contains(peticion('', ['usuario' => 'jefe', 'clave' => 'clave-muy-larga-1']), 'Formulario caducado'));
chk('contraseña incorrecta', fn() =>
    str_contains(peticion('', ['csrf' => csrfActual(''), 'usuario' => 'jefe', 'clave' => 'no-es-esta-clave']), 'incorrectos'));
chk('acceso correcto', fn() =>
    str_contains(peticion('', ['csrf' => csrfActual(''), 'usuario' => 'jefe', 'clave' => 'clave-muy-larga-1']), 'Bases de datos'));
chk('sin token CSRF la acción se rechaza', fn() =>
    str_contains(enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'sinCsrf'], false), 'Formulario caducado'));

echo "\n== Bases de datos ==\n";
chk('crear una base', fn() =>
    str_contains(enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'tienda']), 'tienda&#039; creada'));
chk('la base aparece en el listado', fn() =>
    str_contains(pedir('p=bases'), '>tienda</a>') || str_contains(pedir('p=bases'), 'tienda'));
chk('el administrador ve en la portada el estado de la carpeta de datos (2.10)', fn() =>
    str_contains(pedir('p=bases'), 'Carpeta de datos') ?: 'no está');
chk('nombre de base no válido', fn() =>
    str_contains(enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'con espacio']), 'no válido'));

echo "\n== Tablas y estructura ==\n";
chk('crear una tabla con autoincremento y decimal', function () {
    $html = enviar('p=crear_tabla&db=tienda', [
        'accion' => 'crear_tabla', 'db' => 'tienda', 'nombre' => 'clientes',
        'columnas' => [
            ['nombre' => 'id',     'tipo' => 'INTEGER', 'pk' => '1', 'auto' => '1'],
            ['nombre' => 'cod',    'tipo' => 'TEXT', 'longitud' => '10', 'notnull' => '1'],
            ['nombre' => 'nombre', 'tipo' => 'TEXT', 'longitud' => '50'],
            ['nombre' => 'saldo',  'tipo' => 'DECIMAL', 'escala' => '2', 'defecto' => '0'],
        ],
    ]);
    return str_contains($html, 'clientes&#039; creada');
});
chk('crear la tabla hija', function () {
    $html = enviar('p=crear_tabla&db=tienda', [
        'accion' => 'crear_tabla', 'db' => 'tienda', 'nombre' => 'pedidos',
        'columnas' => [
            ['nombre' => 'id',         'tipo' => 'INTEGER', 'pk' => '1', 'auto' => '1'],
            ['nombre' => 'cliente_id', 'tipo' => 'INTEGER'],
            ['nombre' => 'ref',        'tipo' => 'TEXT', 'longitud' => '20'],
            ['nombre' => 'total',      'tipo' => 'DECIMAL', 'escala' => '2'],
        ],
    ]);
    return str_contains($html, 'pedidos&#039; creada');
});
chk('clave primaria compuesta', function () {
    $html = enviar('p=crear_tabla&db=tienda', [
        'accion' => 'crear_tabla', 'db' => 'tienda', 'nombre' => 'lineas',
        'columnas' => [
            ['nombre' => 'pedido_id', 'tipo' => 'INTEGER', 'pk' => '1'],
            ['nombre' => 'linea',     'tipo' => 'INTEGER', 'pk' => '1'],
            ['nombre' => 'concepto',  'tipo' => 'TEXT', 'longitud' => '60'],
        ],
    ]);
    if (!str_contains($html, 'lineas&#039; creada')) {
        return 'no se creó la tabla';
    }
    $pagina = pedir('p=estructura&db=tienda&tabla=lineas');
    return substr_count($pagina, 'text-bg-primary">PK</span>') === 2 ?: 'no hay dos columnas PK';
});
chk('la estructura muestra tipos y claves', function () {
    $html = pedir('p=estructura&db=tienda&tabla=clientes');
    return str_contains($html, 'DECIMAL') && str_contains($html, '>PK<') && str_contains($html, 'auto');
});
chk('añadir columna', function () {
    enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'anadir_columna', 'db' => 'tienda', 'tabla' => 'clientes',
        'nombre' => 'ciudad', 'tipo' => 'TEXT', 'longitud' => '60', 'defecto' => 'Malaga',
    ]);
    return str_contains(pedir('p=estructura&db=tienda&tabla=clientes'), 'ciudad');
});
chk('renombrar columna', function () {
    enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'clientes',
        'columna' => 'ciudad', 'nombre' => 'poblacion', 'tipo' => 'TEXT', 'longitud' => '60',
        'defecto' => 'Malaga',
    ]);
    $html = pedir('p=estructura&db=tienda&tabla=clientes');
    return str_contains($html, 'poblacion') && !str_contains($html, '<strong>ciudad</strong>');
});
chk('Estructura muestra ENUM y CHECK, y editar una columna ENUM no pierde su lista (2.9)', function () {
    enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE estados (id INTEGER PRIMARY KEY, estado ENUM('nuevo', 'pagado') NOT NULL, n INTEGER, CONSTRAINT positivo CHECK (n > 0))"]);
    $crudo = pedir('p=estructura&db=tienda&tabla=estados');
    $html  = html_entity_decode($crudo, ENT_QUOTES);
    $valores = preg_match('/name="valores" value="([^"]*)"/', $crudo, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    enviar('p=estructura&db=tienda&tabla=estados', [
        'accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'estados',
        'columna' => 'estado', 'nombre' => 'estado', 'tipo' => 'TEXT', 'notnull' => '1', 'defecto' => 'pagado', 'valores' => (string)$valores,
    ]);
    $despues = html_entity_decode(enviar('p=sql&db=tienda', ['sql' => 'SHOW CREATE TABLE estados']), ENT_QUOTES);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE estados']);
    return str_contains($html, "ENUM('nuevo', 'pagado')") && str_contains($html, 'n &gt; 0') === false && str_contains($html, 'n > 0')
        && $valores === '["nuevo","pagado"]' && str_contains($despues, "ENUM('nuevo', 'pagado') NOT NULL DEFAULT 'pagado'")
        ?: json_encode([$valores, substr($despues, 0, 300)]);
});
chk('Estructura muestra COLLATE y UNSIGNED, y editar la columna no los pierde (2.9)', function () {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE cuentas (id INTEGER PRIMARY KEY, email TEXT COLLATE NOCASE, saldo INTEGER UNSIGNED)']);
    $crudo = pedir('p=estructura&db=tienda&tabla=cuentas');
    $ocultos = [];
    foreach (['colacion', 'sin_signo'] as $campo) {
        $ocultos[$campo] = preg_match('/name="' . $campo . '" value="([^"]*)"/', $crudo, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }
    enviar('p=estructura&db=tienda&tabla=cuentas', ['accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'cuentas',
        'columna' => 'email', 'nombre' => 'correo', 'tipo' => 'TEXT', 'colacion' => (string)$ocultos['colacion']]);
    enviar('p=estructura&db=tienda&tabla=cuentas', ['accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'cuentas',
        'columna' => 'saldo', 'nombre' => 'saldo', 'tipo' => 'INTEGER', 'defecto' => '0', 'sin_signo' => (string)$ocultos['sin_signo']]);
    $despues = html_entity_decode(enviar('p=sql&db=tienda', ['sql' => 'SHOW CREATE TABLE cuentas']), ENT_QUOTES);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE cuentas']);
    return str_contains($crudo, 'COLLATE NOCASE') && str_contains($crudo, 'INTEGER UNSIGNED') && $ocultos === ['colacion' => 'NOCASE', 'sin_signo' => '1']
        && str_contains($despues, 'correo TEXT COLLATE NOCASE') && str_contains($despues, 'saldo INTEGER UNSIGNED DEFAULT 0')
        ?: json_encode([$ocultos, substr($despues, 0, 300)]);
});
chk('Estructura muestra el DEFAULT que se calcula al insertar, y editar la columna lo conserva; un texto entre paréntesis sigue siendo un texto (2.9)', function () {
    enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE altas (id INTEGER PRIMARY KEY, alta DATETIME DEFAULT CURRENT_TIMESTAMP, u TEXT DEFAULT (upper('x')), n TEXT DEFAULT '(ninguno)')"]);
    $crudo = pedir('p=estructura&db=tienda&tabla=altas');
    // Lo que el formulario de cada columna manda tal cual se abre
    $campos = static function (string $col) use ($crudo): array {
        $i = strpos($crudo, 'name="columna" value="' . $col . '"');
        $form = substr($crudo, (int)$i, (int)strpos($crudo, '</form>', (int)$i) - (int)$i);
        $out = ['defecto' => preg_match('/name="defecto"\s+value="([^"]*)"/', $form, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null];
        if (preg_match('/name="defecto_calculado" value="1"[^>]*checked/', $form)) { $out['defecto_calculado'] = '1'; }
        return $out;
    };
    $enviados = [];
    foreach (['alta' => 'DATETIME', 'u' => 'TEXT', 'n' => 'TEXT'] as $col => $tipo) {
        $enviados[$col] = $campos($col);
        enviar('p=estructura&db=tienda&tabla=altas', ['accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'altas',
            'columna' => $col, 'nombre' => $col, 'tipo' => $tipo] + $enviados[$col]);
    }
    // Escrita ya entre paréntesis, no lleva otros
    enviar('p=estructura&db=tienda&tabla=altas', ['accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'altas',
        'columna' => 'u', 'nombre' => 'u', 'tipo' => 'TEXT', 'defecto' => "(upper('x'))", 'defecto_calculado' => '1']);
    $despues = html_entity_decode(enviar('p=sql&db=tienda', ['sql' => 'SHOW CREATE TABLE altas']), ENT_QUOTES);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE altas']);
    return str_contains($crudo, '<code>CURRENT_TIMESTAMP</code>') && str_contains(html_entity_decode($crudo, ENT_QUOTES), "<code>(upper('x'))</code>")
        && $enviados['alta'] === ['defecto' => 'CURRENT_TIMESTAMP', 'defecto_calculado' => '1'] && $enviados['n'] === ['defecto' => '(ninguno)']
        && str_contains($despues, 'alta DATETIME DEFAULT CURRENT_TIMESTAMP') && str_contains($despues, "u TEXT DEFAULT (upper('x'))")
        && str_contains($despues, "n TEXT DEFAULT '(ninguno)'")
        ?: json_encode([$enviados, substr($despues, 0, 400)], JSON_UNESCAPED_UNICODE);
});
chk('cambiar el tipo de una columna', function () {
    enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'clientes',
        'columna' => 'poblacion', 'nombre' => 'poblacion', 'tipo' => 'TEXT', 'longitud' => '80',
        'notnull' => '1', 'defecto' => 'Malaga',
    ]);
    $r = enviar('p=sql&db=tienda', ['sql' => 'SHOW SCHEMA clientes']);
    return str_contains($r, '<td>80</td>');
});
chk('el formulario ofrece todo, no solo el nombre', function () {
    $html = pedir('p=estructura&db=tienda&tabla=clientes');
    return str_contains($html, 'value="editar_columna"') && str_contains($html, 'name="tipo"')
        && str_contains($html, 'name="escala"') && str_contains($html, 'name="notnull"')
        && str_contains($html, 'name="unico"') && str_contains($html, 'name="defecto"');
});
chk('un cambio de tipo imposible no rompe la tabla', function () {
    // En una tabla aparte, para no tocar los datos de las demás comprobaciones
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE conversion (cod VARCHAR(10))']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO conversion (cod) VALUES ('ZZ')"]);

    $html = enviar('p=estructura&db=tienda&tabla=conversion', [
        'accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'conversion',
        'columna' => 'cod', 'nombre' => 'cod', 'tipo' => 'INTEGER',
    ]);
    $r = enviar('p=sql&db=tienda', ['sql' => 'SHOW SCHEMA conversion']);
    $intacta = str_contains($r, 'TEXT');
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE conversion']);

    return (str_contains($html, 'no se puede convertir') && $intacta) ?: 'mensaje o tipo inesperados';
});
chk('la clave primaria avisa de que no se toca', function () {
    $html = pedir('p=estructura&db=tienda&tabla=clientes');
    return str_contains($html, 'hay que recrear la tabla');
});
chk('borrar columna', function () {
    enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'borrar_columna', 'db' => 'tienda', 'tabla' => 'clientes', 'columna' => 'poblacion',
    ]);
    return !str_contains(pedir('p=estructura&db=tienda&tabla=clientes'), 'poblacion');
});

chk('el formulario permite añadir más columnas', function () {
    $html = pedir('p=crear_tabla&db=tienda');
    return str_contains($html, 'id="anadirFila"')
        && str_contains($html, 'id="plantillaColumna"')
        && substr_count($html, 'name="columnas[__I__][nombre]"') === 1;
});
chk('se pueden crear más de seis columnas', function () {
    $cols = [];
    for ($i = 0; $i < 10; $i++) {
        $cols[] = ['nombre' => 'c' . $i, 'tipo' => 'TEXT', 'longitud' => '20'];
    }
    $html = enviar('p=crear_tabla&db=tienda', [
        'accion' => 'crear_tabla', 'db' => 'tienda', 'nombre' => 'anchas', 'columnas' => $cols,
    ]);
    return str_contains($html, 'anchas&#039; creada')
        && substr_count(pedir('p=estructura&db=tienda&tabla=anchas'), '<strong>c') === 10;
});

echo "\n== Claves y triggers desde el panel ==\n";
chk('añadir clave única', function () {
    $html = enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'anadir_unica', 'db' => 'tienda', 'tabla' => 'clientes',
        'columnas' => ['cod'], 'nombre' => 'uq_clientes_cod',
    ]);
    return str_contains($html, 'Clave única añadida') && str_contains($html, 'uq_clientes_cod');
});
chk('añadir clave foránea con ON DELETE CASCADE', function () {
    $html = enviar('p=estructura&db=tienda&tabla=pedidos', [
        'accion' => 'anadir_fk', 'db' => 'tienda', 'tabla' => 'pedidos',
        'columnas' => ['cliente_id'], 'tabla_destino' => 'clientes', 'referencias' => ['id'],
        'on_delete' => 'CASCADE', 'on_update' => 'NO ACTION', 'nombre' => 'fk_pedidos_cliente',
    ]);
    return str_contains($html, 'Clave foránea añadida')
        && str_contains($html, 'fk_pedidos_cliente')
        && str_contains($html, 'DEL CASCADE');
});
chk('crear trigger desde el asistente', function () {
    $html = enviar('p=estructura&db=tienda&tabla=pedidos', [
        'accion' => 'crear_trigger', 'db' => 'tienda', 'tabla' => 'pedidos',
        'nombre' => 'trg_pedidos_ins', 'timing' => 'AFTER', 'evento' => 'INSERT',
        'cuando' => 'NEW.total > 0',
        'cuerpo' => 'UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id',
    ]);
    return str_contains($html, 'trg_pedidos_ins&#039; creado')
        && str_contains($html, 'AFTER INSERT');
});
chk('el asistente tiene los campos, no una SQL suelta', function () {
    $html = pedir('p=estructura&db=tienda&tabla=pedidos');
    return str_contains($html, 'id="trgTiming"') && str_contains($html, 'id="trgEvento"')
        && str_contains($html, 'id="trgVista"') && str_contains($html, 'name="cuerpo"');
});
chk('un evento inventado se rechaza', fn() =>
    str_contains(enviar('p=estructura&db=tienda&tabla=pedidos', [
        'accion' => 'crear_trigger', 'db' => 'tienda', 'tabla' => 'pedidos',
        'nombre' => 'trg_malo', 'timing' => 'AFTER', 'evento' => 'TRUNCATE',
        'cuerpo' => 'UPDATE clientes SET saldo = 0',
    ]), 'INSERT, UPDATE o DELETE'));
chk('un trigger sin cuerpo se rechaza', fn() =>
    str_contains(enviar('p=estructura&db=tienda&tabla=pedidos', [
        'accion' => 'crear_trigger', 'db' => 'tienda', 'tabla' => 'pedidos',
        'nombre' => 'trg_vacio', 'timing' => 'AFTER', 'evento' => 'INSERT', 'cuerpo' => '',
    ]), 'al menos una sentencia'));

echo "\n== Integridad desde el panel ==\n";
chk('la pantalla dice que todo está bien', function () {
    $html = pedir('p=integridad&db=tienda');
    return str_contains($html, 'Todo correcto');
});
chk('detecta y corrige una fila huérfana', function () use ($raizDatos) {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE padres_i (id INTEGER PRIMARY KEY AUTOINCREMENT)']);
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE hijas_i (id INTEGER PRIMARY KEY AUTOINCREMENT, padre_id INTEGER)']);
    enviar('p=sql&db=tienda', ['sql' => 'ALTER TABLE hijas_i ADD CONSTRAINT fk_i FOREIGN KEY (padre_id) REFERENCES padres_i(id)']);
    enviar('p=sql&db=tienda', ['sql' => 'INSERT INTO padres_i (id) VALUES (1)']);
    enviar('p=sql&db=tienda', ['sql' => 'INSERT INTO hijas_i (padre_id) VALUES (1)']);

    $f = $raizDatos . '/tienda/hijas_i.json';
    file_put_contents($f, str_replace('"padre_id":1', '"padre_id":404', (string)file_get_contents($f)));

    $html = pedir('p=integridad&db=tienda');
    if (!str_contains($html, 'huérfana') || !str_contains($html, '404')) {
        return 'no detectó la fila huérfana';
    }
    $tras = enviar('p=integridad&db=tienda', ['corregir' => '1']);
    return str_contains($tras, 'fila(s) corregida(s)') && str_contains($tras, 'Todo correcto');
});
chk('un fichero JSON roto a mano sale en Integridad con su línea, y el botón lo repara apartando la fila (2.9)', function () use ($raizDatos) {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE rota_i (id INTEGER PRIMARY KEY, x TEXT)']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO rota_i VALUES (1, 'uno'), (2, 'dos'), (3, 'tres')"]);
    $f = $raizDatos . '/tienda/rota_i.json';
    file_put_contents($f, str_replace('{"id":2,"x":"dos"}', '{"id":2,"x":"dos}', (string)file_get_contents($f)));
    $html = pedir('p=integridad&db=tienda');
    if (!str_contains($html, 'rota_i.json') || !str_contains($html, 'línea 5')) {
        return 'no detectó el fichero roto: ' . mensajeDe($html);
    }
    $tras = enviar('p=integridad&db=tienda', ['reparar_ficheros' => '1']);
    $quedan = enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM rota_i']);
    $copias = glob($raizDatos . '/tienda/.reparados/rota_i.json.*') ?: [];
    return str_contains($tras, 'reparado(s)') && str_contains($tras, 'Filas apartadas pendientes')
        && str_contains($tras, 'Filas apartadas') && str_contains($tras, '{&quot;id&quot;:2,&quot;x&quot;:&quot;dos}')
        && str_contains($quedan, '<td>2</td>') && count($copias) === 1 ?: [mensajeDe($tras), $copias];
});
chk('la fila apartada se corrige en el panel con una fila real de ejemplo; lo que no vale da error y sigue pendiente (2.9)', function () {
    $pagina = pedir('p=integridad&db=tienda');
    if (!str_contains($pagina, 'Ejemplo de una fila correcta de rota_i') || !str_contains($pagina, '{&quot;id&quot;:1,&quot;x&quot;:&quot;uno&quot;}')) {
        return 'no muestra una fila real de ejemplo';
    }
    $guardar = static fn(string $fila): string => enviar('p=integridad&db=tienda', ['apartada' => '1', 'hacer' => 'guardar', 'fila' => $fila]);
    $noJson   = $guardar('{"id":2,"x":"dos}');
    $columna  = $guardar('{"id":2,"y":"dos"}');
    $repetida = $guardar('{"id":1,"x":"dos"}');
    $siguePendiente = str_contains($repetida, 'Filas apartadas pendientes') && str_contains($repetida, '{&quot;id&quot;:1,&quot;x&quot;:&quot;dos&quot;}');
    $bien = $guardar('{"id":2,"x":"dos"}');
    $filas = enviar('p=sql&db=tienda', ['sql' => 'SELECT x FROM rota_i ORDER BY id']);
    return str_contains($noJson, 'No es una fila') && str_contains($columna, 'y no es una columna de rota_i') && $siguePendiente
        && str_contains($bien, 'Fila corregida y de vuelta en rota_i') && !str_contains($bien, 'Filas apartadas pendientes')
        && str_contains($bien, 'Todos los ficheros de las tablas se leen bien') && str_contains($filas, '<td>dos</td>')
        ?: [mensajeDe($noJson), mensajeDe($columna), $siguePendiente, mensajeDe($bien)];
});
chk('con la tabla vacía el ejemplo es inventado, y una fila apartada se puede descartar (2.9)', function () use ($raizDatos) {
    enviar('p=sql&db=tienda', ['sql' => 'DELETE FROM rota_i']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO rota_i VALUES (7, 'siete')"]);
    $f = $raizDatos . '/tienda/rota_i.json';
    file_put_contents($f, str_replace('{"id":7,"x":"siete"}', '{"id":7,"x":"siete}', (string)file_get_contents($f)));
    enviar('p=integridad&db=tienda', ['reparar_ficheros' => '1']);
    $pagina = pedir('p=integridad&db=tienda');
    $inventada = str_contains($pagina, '{&quot;id&quot;:1,&quot;x&quot;:&quot;x 1&quot;}');
    $tras = enviar('p=integridad&db=tienda', ['apartada' => '2', 'hacer' => 'descartar']);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE rota_i']);
    array_map('unlink', glob($raizDatos . '/tienda/.reparados/*') ?: []);
    @rmdir($raizDatos . '/tienda/.reparados');
    return $inventada && str_contains($tras, 'olvidada') && !str_contains($tras, 'Filas apartadas pendientes') ?: [$inventada, mensajeDe($tras)];
});
chk('un índice que no cuadra con sus filas sale en Integridad, y el botón lo rehace (2.10)', function () use ($raizDatos) {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE idx_i (id INTEGER PRIMARY KEY, x TEXT)']);
    enviar('p=sql&db=tienda', ['sql' => 'CREATE INDEX ix_x_i ON idx_i (x)']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO idx_i VALUES (1, 'uno'), (2, 'dos'), (3, 'tres')"]);
    // Editado a mano más tarde que su escritura, como de verdad: con el mismo
    // tamaño y en el mismo segundo, su sello no cambiaría y valdría su caché
    $f = $raizDatos . '/tienda/idx_i.idx.ix_x_i.json';
    touch($f, time() - 10);
    $bien = pedir('p=integridad&db=tienda');
    file_put_contents($f, str_replace('"t3:dos"', '"t3:doz"', (string)file_get_contents($f)));
    $html = pedir('p=integridad&db=tienda');
    $tras = enviar('p=integridad&db=tienda', ['reparar_indices' => '1']);
    $fila = enviar('p=sql&db=tienda', ['sql' => "SELECT id FROM idx_i WHERE x = 'dos'"]);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE idx_i']);
    return str_contains($bien, 'Cada índice cuadra con las filas de su tabla') && str_contains($html, 'problema(s) en los índices')
        && str_contains($html, 'ix_x_i') && str_contains($tras, 'los índices de 1 tabla(s), rehechos con sus filas')
        && str_contains($tras, 'Cada índice cuadra con las filas de su tabla') && str_contains($fila, '<td>2</td>')
        ?: [mensajeDe($html), mensajeDe($tras)];
});
chk('limpiar las tablas de integridad', function () {
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE hijas_i']);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE padres_i']);
    return true;
});

echo "\n== Vistas desde el panel ==\n";
chk('la pantalla de vistas está en el menú y vacía al principio', function () {
    $html = pedir('p=vistas&db=tienda');
    return str_contains($html, 'no tiene vistas') && str_contains($html, 'value="crear_vista"');
});
chk('la lista de tablas del formulario no se escapa de más', function () {
    $html = pedir('p=vistas&db=tienda');
    // El separador entre nombres tiene que ser HTML real, no texto visible
    return str_contains($html, '<code>clientes</code>')
        && !str_contains($html, '&lt;code&gt;')
        && !str_contains($html, '&lt;/code&gt;');
});
chk('crear una vista', function () {
    $html = enviar('p=vistas&db=tienda', [
        'accion' => 'crear_vista', 'db' => 'tienda', 'nombre' => 'v_con_saldo',
        'sql' => 'SELECT cod, nombre, saldo FROM clientes WHERE saldo > 0',
    ]);
    return str_contains($html, 'v_con_saldo&#039; creada') && str_contains($html, 'v_con_saldo');
});
chk('la vista se consulta desde el editor SQL', function () {
    $r = enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM v_con_saldo']);
    return str_contains($r, 'fila(s)') && !str_contains($r, 'Error en la consulta');
});
chk('una vista sobre otra vista', function () {
    $html = enviar('p=vistas&db=tienda', [
        'accion' => 'crear_vista', 'db' => 'tienda', 'nombre' => 'v_encadenada',
        'sql' => 'SELECT cod FROM v_con_saldo',
    ]);
    return str_contains($html, 'v_encadenada&#039; creada');
});
chk('una vista que no es SELECT se rechaza', fn() =>
    str_contains(enviar('p=vistas&db=tienda', [
        'accion' => 'crear_vista', 'db' => 'tienda', 'nombre' => 'v_mala',
        'sql' => 'DELETE FROM clientes',
    ]), 'tiene que ser un SELECT'));
chk('no se puede escribir en una vista', fn() =>
    str_contains(enviar('p=sql&db=tienda', ['sql' => "DELETE FROM v_con_saldo"]),
                 'es una vista'));
chk('borrar una vista no toca los datos', function () {
    $antes = enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM clientes']);
    enviar('p=vistas&db=tienda', ['accion' => 'borrar_vista', 'db' => 'tienda',
                                  'nombre' => 'v_encadenada']);
    $despues = enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM clientes']);
    preg_match('/<td>(\d+)<\/td>/', $antes, $a);
    preg_match('/<td>(\d+)<\/td>/', $despues, $d);
    return ($a[1] ?? 'x') === ($d[1] ?? 'y')
        && !str_contains(pedir('p=vistas&db=tienda'), 'v_encadenada');
});
chk('limpiar la vista de prueba', function () {
    enviar('p=vistas&db=tienda', ['accion' => 'borrar_vista', 'db' => 'tienda',
                                  'nombre' => 'v_con_saldo']);
    return str_contains(pedir('p=vistas&db=tienda'), 'no tiene vistas');
});

echo "\n== Clave primaria desde el panel ==\n";
chk('una tabla sin clave primaria ofrece crearla', function () {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE notas (ref VARCHAR(10), linea INTEGER, texto VARCHAR(50))']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO notas (ref, linea, texto) VALUES ('R1',1,'a'),('R1',2,'b')"]);
    $html = pedir('p=estructura&db=tienda&tabla=notas');
    return str_contains($html, 'value="anadir_pk"') && str_contains($html, 'Clave primaria');
});
chk('crear una clave primaria de dos columnas', function () {
    $html = enviar('p=estructura&db=tienda&tabla=notas', [
        'accion' => 'anadir_pk', 'db' => 'tienda', 'tabla' => 'notas',
        'columnas' => ['ref', 'linea'],
    ]);
    return str_contains($html, 'Clave primaria creada')
        && substr_count($html, 'text-bg-primary">PK</span>') === 2;
});
chk('ya no ofrece crear otra', fn() =>
    !str_contains(pedir('p=estructura&db=tienda&tabla=notas'), 'value="anadir_pk"'));
chk('la clave nueva se aplica al insertar', fn() =>
    str_contains(enviar('p=sql&db=tienda',
        ['sql' => "INSERT INTO notas (ref, linea, texto) VALUES ('R1',1,'repe')"]),
        'Error en la consulta'));
chk('ahora se pueden editar y borrar filas', fn() =>
    !str_contains(pedir('p=datos&db=tienda&tabla=notas'), 'no tiene clave primaria'));
chk('con datos repetidos no deja crearla', function () {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE repes (a INTEGER, b VARCHAR(10))']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO repes (a,b) VALUES (1,'x'),(1,'x')"]);
    $html = enviar('p=estructura&db=tienda&tabla=repes', [
        'accion' => 'anadir_pk', 'db' => 'tienda', 'tabla' => 'repes', 'columnas' => ['a'],
    ]);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE repes']);
    return str_contains($html, 'repiten el valor');
});
chk('quitar la clave primaria', function () {
    $html = enviar('p=estructura&db=tienda&tabla=notas', [
        'accion' => 'borrar_pk', 'db' => 'tienda', 'tabla' => 'notas',
    ]);
    return str_contains($html, 'Clave primaria eliminada')
        && str_contains($html, 'value="anadir_pk"');
});
chk('la clave AUTOINCREMENT no ofrece quitarse', function () {
    $html = pedir('p=estructura&db=tienda&tabla=clientes');
    return str_contains($html, 'Es AUTOINCREMENT') && !str_contains($html, 'value="borrar_pk"');
});
chk('limpiar la tabla de notas', function () {
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE notas']);
    return true;
});

echo "\n== Datos ==\n";
chk('insertar fila dejando vacía la columna automática', function () {
    // Tal cual lo manda el formulario: id vacío, sin marcar NULL
    $html = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => hx(['id' => '', 'cod' => 'A1', 'nombre' => "O'Donnell", 'saldo' => '10.55']),
        'tipo'  => hx(['id' => 'INTEGER', 'cod' => 'TEXT', 'nombre' => 'TEXT', 'saldo' => 'DECIMAL']),
        'auto'  => hx(['id' => '1']),
    ]);
    return str_contains($html, 'Fila insertada') && str_contains($html, 'O&#039;Donnell');
});
chk('el autoincremento ha puesto el id', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes'), '<td>1</td>'));
chk('una columna numérica vacía toma su valor por defecto', function () {
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => hx(['id' => '', 'cod' => 'B2', 'nombre' => 'Sin saldo', 'saldo' => '']),
        'tipo'  => hx(['id' => 'INTEGER', 'cod' => 'TEXT', 'nombre' => 'TEXT', 'saldo' => 'DECIMAL']),
        'auto'  => hx(['id' => '1']),
    ]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT saldo FROM clientes WHERE cod = 'B2'"]);
    return str_contains($r, '<td>0</td>');
});
chk('un texto vacío sí se guarda como cadena vacía', function () {
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => hx(['id' => '', 'cod' => 'C3', 'nombre' => '']),
        'tipo'  => hx(['id' => 'INTEGER', 'cod' => 'TEXT', 'nombre' => 'TEXT']),
        'auto'  => hx(['id' => '1']),
    ]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT COUNT(*) AS n FROM clientes WHERE nombre = ''"]);
    return str_contains($r, '<td>1</td>');
});
chk('el decimal se guarda con punto y dos decimales', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes'), '10.55'));
chk('la clave única se respeta al insertar', function () {
    $html = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => hx(['cod' => 'A1', 'nombre' => 'Repetido']), 'tipo' => hx(['cod' => 'TEXT', 'nombre' => 'TEXT']),
    ]);
    return str_contains($html, 'CONSTRAINT') || str_contains($html, 'repetid')
        || str_contains($html, 'Error en la consulta');
});
chk('editar fila', function () {
    $html = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'actualizar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'pk' => hx(['id' => '1']), 'valor' => hx(['cod' => 'A1', 'nombre' => 'Ana', 'saldo' => '20.10']),
    ]);
    return str_contains($html, 'Fila guardada') && str_contains($html, 'Ana');
});
chk('un valor con SQL dentro no altera la consulta', function () {
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => hx(['cod' => 'X9', 'nombre' => "x'); DROP TABLE clientes; --"]),
    ]);
    $html = pedir('p=datos&db=tienda&tabla=clientes');
    return str_contains($html, 'DROP TABLE clientes') && str_contains($html, 'Ana');
});
chk('el trigger se dispara al insertar un pedido', function () {
    enviar('p=datos&db=tienda&tabla=pedidos', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'pedidos',
        'valor' => hx(['cliente_id' => '1', 'ref' => 'R1', 'total' => '5.00']),
    ]);
    return str_contains(pedir('p=datos&db=tienda&tabla=clientes'), '25.1');
});
chk('la clave foránea rechaza un cliente inexistente', function () {
    $html = enviar('p=datos&db=tienda&tabla=pedidos', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'pedidos',
        'valor' => hx(['cliente_id' => '999', 'ref' => 'R9']),
    ]);
    return str_contains($html, 'no existe') || str_contains($html, 'Error en la consulta');
});
chk('borrar fila arrastra los hijos por CASCADE', function () {
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'borrar_fila', 'db' => 'tienda', 'tabla' => 'clientes', 'pk' => hx(['id' => '1']),
    ]);
    // Se cuenta con SQL (2.10): buscar «R1» en la página fallaba de vez en
    // cuando, cuando el nonce aleatorio de la página (base64) lo contenía
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT (SELECT COUNT(*) FROM pedidos WHERE ref = 'R1') || '/' || (SELECT COUNT(*) FROM clientes WHERE id = 1) AS n"]);
    return str_contains($r, '<td>0/0</td>') ?: mensajeDe($r);
});

chk('con «]» o «,» en el nombre de una columna, editar y borrar desde Datos tocan la fila que es (2.9: tocaban otras)', function () {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE raros ("id]x" INTEGER PRIMARY KEY, "id" INTEGER, "a,b" TEXT)']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO raros VALUES (1, 5, 'p'), (5, 7, 'q'), (9, 5, 'r')"]);
    $html = pedir('p=datos&db=tienda&tabla=raros');
    $campos = str_contains($html, 'name="pk[' . bin2hex('id]x') . ']"') && str_contains($html, 'name="valor[' . bin2hex('a,b') . ']"');
    enviar('p=datos&db=tienda&tabla=raros', ['accion' => 'actualizar_fila', 'db' => 'tienda', 'tabla' => 'raros',
        'pk' => hx(['id]x' => '9']), 'valor' => hx(['id' => '5', 'a,b' => 'cambiada'])]);
    enviar('p=datos&db=tienda&tabla=raros', ['accion' => 'borrar_fila', 'db' => 'tienda', 'tabla' => 'raros', 'pk' => hx(['id]x' => '5'])]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT \"id]x\" || '/' || \"id\" || '/' || \"a,b\" AS f FROM raros ORDER BY 1"]);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE raros']);
    return $campos && str_contains($r, '<td>1/5/p</td>') && str_contains($r, '<td>9/5/cambiada</td>') && !str_contains($r, '5/7/q')
        ?: [$campos, mensajeDe($r)];
});
chk('una tabla sin clave primaria también se edita y se borra fila a fila, por todos sus valores: con NULL, un decimal con todas sus cifras y de dos filas iguales solo una (2.10: no se podía)', function () {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE sinpk (a INTEGER, b TEXT, c DOUBLE)']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO sinpk VALUES (1, 'x', 0.1 + 0.2), (1, 'x', 0.1 + 0.2), (2, NULL, 1.5), (3, 'y', NULL)"]);
    $html = pedir('p=datos&db=tienda&tabla=sinpk');
    $filas = preg_match_all('/name="fila" value="([^"]*)"/', $html, $m)
        ? array_map(static fn(string $v): string => html_entity_decode($v, ENT_QUOTES, 'UTF-8'), $m[1]) : [];
    $aviso = !str_contains($html, 'Usa la pestaña SQL');
    if (count($filas) !== 8) {
        return [count($filas), $aviso];
    }
    enviar('p=datos&db=tienda&tabla=sinpk', ['accion' => 'actualizar_fila', 'db' => 'tienda', 'tabla' => 'sinpk', 'fila' => $filas[2],
        'valor' => hx(['a' => '2', 'b' => 'z', 'c' => '1.5'])]);
    enviar('p=datos&db=tienda&tabla=sinpk', ['accion' => 'actualizar_fila', 'db' => 'tienda', 'tabla' => 'sinpk', 'fila' => $filas[3],
        'valor' => hx(['a' => '3', 'b' => 'w'])]);
    $borrar = enviar('p=datos&db=tienda&tabla=sinpk', ['accion' => 'borrar_fila', 'db' => 'tienda', 'tabla' => 'sinpk', 'fila' => $filas[0]]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT a || '/' || IFNULL(b, 'N') || '/' || IFNULL(c, 'N') AS f FROM sinpk ORDER BY 1"]);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE sinpk']);
    return $aviso && str_contains($borrar, '1 fila(s) borrada(s)') && str_contains($r, '<td>1/x/0.3</td>')
        && substr_count($r, '<td>1/x/') === 1 && str_contains($r, '<td>2/z/1.5</td>') && str_contains($r, '<td>3/w/N</td>')
        ?: [$aviso, mensajeDe($borrar), preg_match_all('/<td>([^<]*)<\/td>/', $r, $mm) ? $mm[1] : $r];
});
chk('un texto con saltos de línea se edita en un área de texto y se guarda igual, con los saltos que tenía (2.9: el campo de una línea los quitaba)', function () {
    enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE notas (id INTEGER PRIMARY KEY, t TEXT, w TEXT, d TEXT DEFAULT 'uno\ndos')"]);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO notas (id, t, w) VALUES (1, 'a\nb', 'c\r\nd')"]);
    $html = pedir('p=datos&db=tienda&tabla=notas');
    $areas = preg_match_all('/<textarea[^>]*name="valor\[([0-9a-f]+)\]"/', $html, $m) ? array_map('hex2bin', $m[1]) : [];
    $lf = preg_match_all('/name="lf\[([0-9a-f]+)\]"/', $html, $m) ? array_map('hex2bin', $m[1]) : [];
    sort($areas);
    sort($lf);
    // Lo que manda un navegador: los saltos de un área de texto, siempre como \r\n
    enviar('p=datos&db=tienda&tabla=notas', ['accion' => 'actualizar_fila', 'db' => 'tienda', 'tabla' => 'notas', 'pk' => hx(['id' => '1']),
        'valor' => hx(['t' => "a\r\nb", 'w' => "c\r\nd", 'd' => "uno\r\ndos"]), 'lf' => hx(['t' => '1', 'd' => '1'])]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT HEX(t) || '/' || HEX(w) || '/' || HEX(d) AS f FROM notas"]);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE notas']);
    // t y w de la fila, d en la fila y en la nueva (su valor por defecto)
    return $areas === ['d', 'd', 't', 'w'] && $lf === ['d', 'd', 't'] && str_contains($r, '<td>610A62/630D0A64/756E6F0A646F73</td>')
        ?: [$areas, $lf, mensajeDe($r)];
});

echo "\n== Editor SQL ==\n";
chk('SELECT muestra las filas', function () {
    $html = enviar('p=sql&db=tienda', ['sql' => 'SELECT cod, nombre FROM clientes ORDER BY cod']);
    return str_contains($html, 'X9') && str_contains($html, 'fila(s)');
});
chk('una sentencia con error se explica', fn() =>
    str_contains(enviar('p=sql&db=tienda', ['sql' => 'SELECT * FROM fantasma']), 'Error en la consulta'));
chk('DDL desde el editor', function () {
    $html = enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE temporal (id INTEGER PRIMARY KEY)']);
    return str_contains($html, 'Tabla') && str_contains(pedir('p=tablas&db=tienda'), 'temporal');
});
chk('CREATE DATABASE desde el editor', function () {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE DATABASE almacen']);
    return str_contains(pedir('p=bases'), 'almacen');
});

echo "\n== Filtro y columnas automáticas ==\n";
chk('una columna obligatoria no ofrece la casilla NULL', function () {
    // 'cod' es NOT NULL en clientes
    $html = pedir('p=datos&db=tienda&tabla=clientes');
    return !str_contains($html, 'name="nulo[' . bin2hex('cod') . ']"')
        && str_contains($html, 'name="nn[' . bin2hex('cod') . ']"')
        && str_contains($html, 'obligatorio')
        && str_contains($html, 'sin nulos');
});
chk('marcar NULL en una obligatoria se rechaza', function () {
    $html = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => hx(['cod' => '', 'nombre' => 'Sin cod']),
        'nulo'  => hx(['cod' => '1']), 'nn' => hx(['cod' => '1']),
        'tipo'  => hx(['cod' => 'TEXT', 'nombre' => 'TEXT']),
    ]);
    return str_contains($html, 'no admite nulos');
});
chk('una columna que sí admite nulos mantiene la casilla', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes'), 'name="nulo[' . bin2hex('nombre') . ']"'));
chk('la columna automática no ofrece la casilla NULL', function () {
    $html = pedir('p=datos&db=tienda&tabla=clientes');
    return !str_contains($html, 'name="nulo[' . bin2hex('id') . ']"') && str_contains($html, 'lo pone la base');
});
chk('marcar NULL en la automática no cuela', function () {
    // Aunque se envíe a mano, la columna automática se ignora
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => hx(['id' => '', 'cod' => 'F6', 'nombre' => 'Con NULL']),
        'nulo'  => hx(['id' => '1']), 'auto' => hx(['id' => '1']),
        'tipo'  => hx(['id' => 'INTEGER', 'cod' => 'TEXT', 'nombre' => 'TEXT']),
    ]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT id FROM clientes WHERE cod = 'F6'"]);
    return !str_contains($r, '<td></td>') && str_contains($r, 'fila(s)');
});
chk('AUTOINCREMENT en una columna que no es INTEGER se rechaza', function () {
    $html = enviar('p=crear_tabla&db=tienda', [
        'accion' => 'crear_tabla', 'db' => 'tienda', 'nombre' => 'mala',
        'columnas' => [['nombre' => 'cod', 'tipo' => 'TEXT', 'pk' => '1', 'auto' => '1']],
    ]);
    return str_contains($html, 'solo vale en columnas INTEGER');
});
chk('las casillas se habilitan según el tipo', function () {
    $html = pedir('p=crear_tabla&db=tienda');
    return str_contains($html, 'long-col') && str_contains($html, 'esc-col')
        && str_contains($html, 'auto-col') && str_contains($html, 'Decimales')
        && substr_count($html, 'disabled') >= 3;
});
chk('el alta de columna también pide los decimales', function () {
    $html = pedir('p=estructura&db=tienda&tabla=clientes');
    return str_contains($html, 'name="escala"') && str_contains($html, 'Decimales');
});
chk('añadir una columna DECIMAL con 3 decimales', function () {
    enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'anadir_columna', 'db' => 'tienda', 'tabla' => 'clientes',
        'nombre' => 'peso', 'tipo' => 'DECIMAL', 'escala' => '3',
    ]);
    $r = enviar('p=sql&db=tienda', ['sql' => 'SHOW SCHEMA clientes']);
    return str_contains($r, 'peso') && str_contains($r, '<td>3</td>');
});
chk('un DECIMAL sin decimales indicados usa 2', function () {
    enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'anadir_columna', 'db' => 'tienda', 'tabla' => 'clientes',
        'nombre' => 'coste', 'tipo' => 'DECIMAL',
    ]);
    enviar('p=sql&db=tienda', ['sql' => "UPDATE clientes SET coste = 1.2345 WHERE cod = 'B2'"]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT coste FROM clientes WHERE cod = 'B2'"]);
    return str_contains($r, '<td>1.23</td>');
});
chk('el listado ofrece el filtro', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes'), 'name="q"'));
chk('el filtro busca en todas las columnas', function () {
    $html = pedir('p=datos&db=tienda&tabla=clientes&q=Sin+saldo');
    return str_contains($html, 'Sin saldo') && !str_contains($html, '>F6<')
        && str_contains(strip_tags($html), '1 fila(s) filtradas');
});
chk('el filtro también encuentra por una columna numérica', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes&q=0.00'), 'fila(s) filtradas'));
chk('un filtro sin coincidencias lo dice', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes&q=noexisteesto'),
                 'Ninguna fila coincide con el filtro'));
chk('el filtro no se puede usar para inyectar', function () {
    $html = pedir("p=datos&db=tienda&tabla=clientes&q=" . rawurlencode("' OR 1=1 --"));
    return str_contains($html, 'Ninguna fila coincide con el filtro');
});
chk('el filtro busca % y _ tal cual, no como comodines (2.10)', function () {
    // «%» devolvía la tabla entera y «_» cualquier carácter
    $todo  = pedir('p=datos&db=tienda&tabla=clientes&q=' . rawurlencode('%'));
    $guion = pedir('p=datos&db=tienda&tabla=clientes&q=' . rawurlencode('Sin_saldo'));
    return str_contains($todo, 'Ninguna fila coincide con el filtro')
        && str_contains($guion, 'Ninguna fila coincide con el filtro') ?: 'la tabla entera, o «Sin saldo» con _';
});
chk('la exportación respeta el filtro', function () {
    $csv = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda', 'tabla' => 'clientes',
        'q' => 'Sin saldo',
    ]);
    return count(explode("\n", trim($csv))) === 2 && str_contains($csv, 'Sin saldo');
});

chk('ningún script en línea sin su nonce, ni código en atributos (la CSP ya no admite unsafe-inline)', function () {
    $mal = [];
    foreach (['p=bases', 'p=tablas&db=tienda', 'p=estructura&db=tienda&tabla=clientes', 'p=crear_tabla&db=tienda', 'p=auditoria'] as $q) {
        $html = pedir($q);
        if (preg_match('/<script(?![^>]*\b(src|nonce)=)[^>]*>/', $html) || preg_match('/\son(change|click|submit|load|input)=/i', $html)) {
            $mal[] = $q;
        }
    }
    return $mal === [] ?: implode(', ', $mal);
});
chk('los CSS y JS del panel llevan su fecha en la dirección, para que el navegador no use los de antes', function () {
    $html = pedir('p=bases');
    return preg_match('/src="assets\/panel\.js\?v=\d+"/', $html) && preg_match('/href="assets\/panel\.css\?v=\d+"/', $html)
        && preg_match('/src="assets\/bootstrap\.bundle\.min\.js\?v=\d+"/', $html) ?: 'sin ?v= en algún recurso';
});
chk('la guía de volcados se ve en el propio panel, en una ventana con una pestaña por motor y las limitaciones de Access', function () {
    $html = pedir('p=tablas&db=tienda');
    $pestanas = ['sqlite', 'mysql', 'postgresql', 'sqlserver', 'access'];
    foreach ($pestanas as $p) {
        if (!str_contains($html, 'id="guia-' . $p . '"')) { return "falta la pestaña $p"; }
    }
    return str_contains($html, 'id="guiaVolcados"') && str_contains($html, 'data-bs-target="#guiaVolcados" data-pestana="access"')
        && !str_contains($html, 'github.com/miguelenred/jsonsqldb/blob') && str_contains($html, 'Limitaciones de Access')
        && str_contains($html, 'Una sentencia cada vez') && str_contains($html, 'Load an SQL file into Access')
        && str_contains($html, 'mysqldump --default-character-set=utf8mb4') ?: 'falta la ventana, el botón o un texto, o sigue el enlace a GitHub';
});
chk('la página de importar ofrece el script de Access y explica cómo ejecutarlo; el panel lo descarga', function () {
    $html = pedir('p=tablas&db=tienda');
    $script = pedir('p=script_access');
    $ss = pedir('p=script_sqlserver');
    if (!str_contains($html, 'p=script_sqlserver') || !str_contains($ss, 'function Export-SqlServerDatabase')) {
        return 'falta el script de SQL Server';
    }
    return str_contains($html, 'p=script_access') && str_contains($html, 'Ejecutar con PowerShell') && str_contains($html, 'Windows')
        && str_contains($html, 'value="access"') && str_starts_with(ltrim($script), '<#') && str_contains($script, 'function Export-AccessDatabase')
        ?: 'falta el enlace, la explicación o el script';
});
chk('el CSV neutraliza los textos que Excel tomaría por fórmulas', function () {
    // =HYPERLINK(…), +, -, @: con un apóstrofo delante son texto. Un número
    // negativo no se toca: no es un texto
    enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE formulas (id INTEGER PRIMARY KEY, t VARCHAR(60), n INTEGER)"]);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO formulas VALUES (1, '=HYPERLINK(\"http://x\")', -5), (2, '+34 600', 1), (3, '@SUM(A1)', 2), (4, 'normal', 3)"]);
    $csv = descargar('p=datos&db=tienda&tabla=formulas', ['accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda', 'tabla' => 'formulas']);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE formulas']);
    return str_contains($csv, "'=HYPERLINK") && str_contains($csv, "'+34 600") && str_contains($csv, "'@SUM")
        && str_contains($csv, ';-5') && !str_contains($csv, "'normal") ?: $csv;
});

chk('exportar a CSV y volver a importarlo da los mismos textos: el apóstrofo anti-fórmula se quita al importar (2.9)', function () use ($raizDatos) {
    enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE ida (id INTEGER PRIMARY KEY, t VARCHAR(60))"]);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO ida VALUES (1, '=SUMA(A1:A3)'), (2, '+34 600 123 456'), (3, '-5'), (4, '@usuario'), (5, ' =a'), (6, 'normal'), (7, 'l''apóstrofo')"]);
    $csv = descargar('p=datos&db=tienda&tabla=ida', ['accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda', 'tabla' => 'ida']);
    $f = $raizDatos . '/ida.csv';
    file_put_contents($f, $csv);
    enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE vuelta (id INTEGER PRIMARY KEY, t VARCHAR(60))"]);
    $html = subir('p=tablas&db=tienda', ['accion' => 'importar_csv', 'db' => 'tienda', 'tabla' => 'vuelta'], 'fichero', $f);
    @unlink($f);
    $ida = enviar('p=sql&db=tienda', ['sql' => 'SELECT * FROM ida ORDER BY id']);
    $vuelta = enviar('p=sql&db=tienda', ['sql' => 'SELECT * FROM vuelta ORDER BY id']);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE ida']);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE vuelta']);
    $tabla = static fn(string $h): string => (string)preg_replace('/\s+/', ' ', strip_tags((string)preg_replace('/.*?(<table.*?<\/table>).*/s', '$1', $h)));
    return str_contains($html, 'fila(s) cargadas') && $tabla($ida) === $tabla($vuelta) && str_contains($tabla($vuelta), '=SUMA')
        && !str_contains($tabla($vuelta), "'=") ?: [$tabla($ida), $tabla($vuelta), mensajeDe($html)];
});

chk('exportar una tabla a JSON y a Excel, y volver a importarlos en otra, da las mismas filas (2.9)', function () use ($raizDatos) {
    enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE ida2 (id INTEGER PRIMARY KEY, nombre TEXT, precio DECIMAL(10,2), alta DATETIME, dia DATETIME, cod TEXT, grande INTEGER, nota TEXT)"]);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO ida2 VALUES (1, 'Ana \"la\" ñ', 10.5, '2026-03-05 14:07:09', '2026-03-05', '00123', 1234567890123456789, NULL),"
        . " (2, '=SUMA(1)', -3, NULL, '1999-12-31', '2026-01-01', 7, 'línea\nsalto')"]);
    $original = enviar('p=sql&db=tienda', ['sql' => 'SELECT * FROM ida2 ORDER BY id']);
    $tabla = static fn(string $h): string => (string)preg_replace('/\s+/', ' ', strip_tags((string)preg_replace('/.*?(<table.*?<\/table>).*/s', '$1', $h)));
    $r = [];
    foreach (['json' => 'importar_json', 'xlsx' => 'importar_xlsx'] as $formato => $accion) {
        $bin = descargar('p=datos&db=tienda&tabla=ida2', ['accion' => 'exportar', 'formato' => $formato, 'db' => 'tienda', 'tabla' => 'ida2']);
        $f = $raizDatos . "/ida2.$formato";
        file_put_contents($f, $bin);
        enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE vuelta_$formato (id INTEGER PRIMARY KEY, nombre TEXT, precio DECIMAL(10,2), alta DATETIME, dia DATETIME, cod TEXT, grande INTEGER, nota TEXT)"]);
        $html = subir('p=tablas&db=tienda', ['accion' => $accion, 'db' => 'tienda', 'tabla' => "vuelta_$formato"], 'fichero', $f);
        @unlink($f);
        $r[$formato] = [$tabla(enviar('p=sql&db=tienda', ['sql' => "SELECT * FROM vuelta_$formato ORDER BY id"])), mensajeDe($html), substr($bin, 0, 40)];
        enviar('p=sql&db=tienda', ['sql' => "DROP TABLE vuelta_$formato"]);
    }
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE ida2']);
    foreach ($r as $formato => [$vuelta, $mensaje, $inicio]) {
        if ($vuelta !== $tabla($original)) {
            return "$formato: " . json_encode([$tabla($original), $vuelta, $mensaje, $inicio], JSON_UNESCAPED_UNICODE);
        }
    }
    return true;
});
chk('el JSON de la importación en una tabla: filas sin alguna columna (NULL), una columna que no existe (error) y algo que no es una lista', function () use ($raizDatos) {
    enviar('p=sql&db=tienda', ['sql' => "CREATE TABLE jimp (id INTEGER PRIMARY KEY, a TEXT, b INTEGER)"]);
    $casos = ['[{"id": 1, "a": "x", "b": 2}, {"id": 2, "a": "y"}, {"id": 3, "b": true, "a": ["l", 1]}]' => 'fila(s) cargadas',
              '[{"id": 9, "a": "x"}, {"id": 10, "zeta": 1}]' => 'zeta', '{"id": 1}' => 'lista', '[1, 2]' => 'no es un objeto', '[]' => 'ninguna fila'];
    $f = $raizDatos . '/jimp.json';
    $mal = [];
    foreach ($casos as $json => $esperado) {
        file_put_contents($f, $json);
        $html = subir('p=tablas&db=tienda', ['accion' => 'importar_json', 'db' => 'tienda', 'tabla' => 'jimp'], 'fichero', $f);
        if (!str_contains(html_entity_decode(mensajeDe($html)), $esperado)) { $mal[] = "$json: " . mensajeDe($html); }
    }
    @unlink($f);
    $filas = enviar('p=sql&db=tienda', ['sql' => 'SELECT id, a, b FROM jimp ORDER BY id']);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE jimp']);
    return $mal === [] && str_contains($filas, '<td>y</td>') && str_contains(html_entity_decode($filas), '["l",1]') ?: $mal;
});
chk('la ventana de ejemplos usa las columnas de la tabla, y cada plantilla (CSV, Excel, JSON) se descarga y se vuelve a cargar (2.9)', function () use ($raizDatos) {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE plan (id INTEGER PRIMARY KEY, nombre VARCHAR(40), precio DECIMAL(8,2), alta DATETIME)']);
    $html = pedir('p=tablas&db=tienda&ejemplos=1&tabla=plan');
    if (!str_contains($html, 'data-abrir') || !str_contains($html, 'nombre 1') || !str_contains($html, '2026-02-28 13:45:00')
        || !str_contains($html, 'value="importar_json"') || substr_count($html, 'value="plantilla_importar"') !== 3) {
        return 'la ventana no está o no trae las columnas';
    }
    $mal = [];
    foreach (['csv' => 'importar_csv', 'json' => 'importar_json', 'xlsx' => 'importar_xlsx'] as $formato => $accion) {
        $bin = descargar('p=tablas&db=tienda', ['accion' => 'plantilla_importar', 'db' => 'tienda', 'tabla' => 'plan', 'formato' => $formato]);
        $f = $raizDatos . "/plan.$formato";
        file_put_contents($f, $bin);
        enviar('p=sql&db=tienda', ['sql' => 'DELETE FROM plan']);
        $r = subir('p=tablas&db=tienda', ['accion' => $accion, 'db' => 'tienda', 'tabla' => 'plan'], 'fichero', $f);
        @unlink($f);
        $filas = enviar('p=sql&db=tienda', ['sql' => "SELECT COUNT(*) AS n, SUM(precio) AS s, MAX(alta) AS a FROM plan"]);
        if (!str_contains($filas, '<td>2</td>') || !str_contains($filas, '31') || !str_contains($filas, '2026-02-28 13:45:00')) {
            $mal[] = "$formato: " . mensajeDe($r) . ' ' . substr(strip_tags($filas), 0, 200);
        }
    }
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE plan']);
    return $mal === [] ?: $mal;
});
chk('la base entera en JSON se importa en otra y queda igual: tablas, filas, índices, vistas y triggers (2.9)', function () use ($raizDatos) {
    // Con una vista, un trigger, un índice y una tabla que se apunta a sí misma
    foreach (['CREATE VIEW v_json AS SELECT COUNT(*) AS n FROM clientes', 'CREATE TABLE arbol (id INTEGER PRIMARY KEY, padre INTEGER REFERENCES arbol(id))',
              'INSERT INTO arbol VALUES (2, NULL), (1, 2), (3, 1)', 'CREATE INDEX ix_arbol ON arbol (padre)', 'CREATE TABLE eco (n INTEGER)',
              'CREATE TRIGGER t_json AFTER INSERT ON arbol FOR EACH ROW BEGIN INSERT INTO eco VALUES (NEW.id); END'] as $q) {
        $r0 = enviar('p=sql&db=tienda', ['sql' => $q]);
        if (str_contains($r0, 'Error en la consulta')) { return "$q: " . mensajeDe($r0); }
    }
    $json = descargar('p=bases', ['accion' => 'exportar_base', 'formato' => 'json', 'nombre' => 'tienda']);
    foreach (['DROP TRIGGER t_json', 'DROP VIEW v_json', 'DROP TABLE eco', 'DROP TABLE arbol'] as $q) {
        enviar('p=sql&db=tienda', ['sql' => $q]);
    }
    $doc = json_decode($json, true);
    if (!is_array($doc) || !isset($doc['estructura'], $doc['tablas'], $doc['despues'])) {
        return 'no es un JSON con estructura, tablas y despues: ' . substr($json, 0, 200);
    }
    enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'tiendajson']);
    $f = $raizDatos . '/tienda.json';
    file_put_contents($f, $json);
    $r = subir('p=tablas&db=tiendajson', ['accion' => 'importar_sql', 'db' => 'tiendajson', 'formato' => 'auto'], 'fichero', $f);
    @unlink($f);
    if (!str_contains($r, 'volcado JSON de jsonSQLDB')) {
        return mensajeDe($r);
    }
    $tabla = static fn(string $h): string => preg_match('/<table.*?<\/table>/s', $h, $m) ? (string)preg_replace('/\s+/', ' ', strip_tags($m[0])) : '';
    $mirar = ['SHOW TABLES', 'SHOW VIEWS', 'SHOW TRIGGERS', 'SHOW INDEXES'];
    foreach ($doc['tablas'] as $t) {
        $mirar[] = 'SELECT * FROM "' . $t['nombre'] . '" ORDER BY 1';
    }
    // Las de prueba ya no están en tienda: se miran en la copia
    $copia = $tabla(enviar('p=sql&db=tiendajson', ['sql' => 'SELECT (SELECT COUNT(*) FROM arbol) AS a, (SELECT COUNT(*) FROM eco) AS e, (SELECT n FROM v_json) AS v']));
    $trg = $tabla(enviar('p=sql&db=tiendajson', ['sql' => 'SHOW TRIGGERS']));
    foreach (['DROP TRIGGER t_json', 'DROP VIEW v_json', 'DROP TABLE eco', 'DROP TABLE arbol'] as $q) {
        enviar('p=sql&db=tiendajson', ['sql' => $q]);
    }
    if (!preg_match('/a e v 30\d/', $copia) || !str_contains($trg, 't_json')) {
        return "copia: $copia / $trg";
    }
    foreach ($mirar as $q) {
        // SHOW TABLES dice cuándo se creó cada tabla: eso sí cambia
        $sinFecha = static fn(string $x): string => $q === 'SHOW TABLES' ? (string)preg_replace('/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/', '', $x) : $x;
        $a = $sinFecha($tabla(enviar('p=sql&db=tienda', ['sql' => $q])));
        $b = $sinFecha($tabla(enviar('p=sql&db=tiendajson', ['sql' => $q])));
        if ($a !== $b) {
            return "$q: " . substr($a, 0, 300) . ' ≠ ' . substr($b, 0, 300);
        }
    }
    enviar('p=bases', ['accion' => 'borrar_base', 'nombre' => 'tiendajson', 'confirmacion' => 'tiendajson']);
    return count($doc['tablas']) >= 3 ?: 'pocas tablas';
});
chk('en la consola, varias sentencias van en una transacción: todas o ninguna (2.9)', function () {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE consola_tx (id INTEGER PRIMARY KEY, n INTEGER UNIQUE)']);
    $bien = enviar('p=sql&db=tienda', ['sql' => "INSERT INTO consola_tx VALUES (1, 10);\nINSERT INTO consola_tx VALUES (2, 20);\nSELECT COUNT(*) AS c FROM consola_tx"]);
    $mal  = enviar('p=sql&db=tienda', ['sql' => "UPDATE consola_tx SET n = n + 5; INSERT INTO consola_tx VALUES (3, 15)"]);
    $fin  = enviar('p=sql&db=tienda', ['sql' => 'SELECT SUM(n) AS s FROM consola_tx']);
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE consola_tx']);
    return str_contains($bien, 'se han ejecutado todas') && str_contains($bien, '<td>2</td>') && str_contains($mal, 'No se ha ejecutado ninguna')
        && str_contains($fin, '<td>30</td>') ?: [mensajeDe($bien), mensajeDe($mal), substr(strip_tags($fin), -200)];
});
echo "\n== Exportación ==\n";
chk('la pantalla de datos ofrece CSV e INSERT', function () {
    $html = pedir('p=datos&db=tienda&tabla=clientes');
    return str_contains($html, 'value="exportar"') && str_contains($html, 'value="csv"')
        && str_contains($html, 'value="sql"');
});
chk('exportar la tabla a CSV', function () {
    $csv = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda', 'tabla' => 'clientes',
    ]);
    $lineas = explode("\n", trim($csv));
    return str_starts_with($csv, "\xEF\xBB\xBF")            // BOM para Excel
        && str_contains($lineas[0], 'id;cod;nombre;saldo')
        && count($lineas) === 1 + filasDe('clientes')        // cabecera + filas
        && str_contains($csv, 'X9');
});
chk('un formulario mayor que post_max_size se explica, en vez de «formulario caducado» (2.9)', function () {
    // El servidor de pruebas arranca con el post_max_size por defecto de PHP (8M)
    $limite = 8 * 1048576;
    $r = enviar('p=sql&db=tienda', ['sql' => 'SELECT ' . str_repeat('1', $limite + 1024)]);
    return str_contains($r, 'post_max_size') && !str_contains($r, 'Formulario caducado') ?: substr(strip_tags($r), 0, 200);
});
chk('exportar una consulta sin filas lo dice en el panel, sin descargar un fichero roto (2.9)', function () {
    $r = [];
    foreach (['csv', 'sql'] as $formato) {
        $r[$formato] = enviar('p=sql&db=tienda', ['accion' => 'exportar', 'formato' => $formato, 'db' => 'tienda',
            'sql' => 'SELECT * FROM clientes WHERE 1 = 0']);
    }
    foreach ($r as $f => $html) {
        if (!str_contains($html, 'no ha devuelto ninguna fila') || str_contains($html, 'closed generator')) {
            return "$f: " . substr(strip_tags($html), 0, 200);
        }
    }
    return true;
});
chk('el CSV entrecomilla lo que lleva el separador', function () {
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO clientes (cod, nombre) VALUES ('D4', 'Uno; dos')"]);
    $csv = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda', 'tabla' => 'clientes',
    ]);
    return str_contains($csv, '"Uno; dos"');
});
chk('con serialize_precision a 10 en php.ini, el panel exporta los decimales con las cifras justas: CSV, JSON y sentencias INSERT (2.10: 0.30000000000000004 salía 0.3)', function () {
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE dsp (id INTEGER PRIMARY KEY, x REAL)']);
    enviar('p=sql&db=tienda', ['sql' => 'INSERT INTO dsp VALUES (1, 0.1 + 0.2)']);
    $r = [];
    foreach (['csv', 'json', 'sql'] as $formato) {
        $r[$formato] = descargar('p=datos&db=tienda&tabla=dsp', ['accion' => 'exportar', 'formato' => $formato, 'db' => 'tienda', 'tabla' => 'dsp']);
    }
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE dsp']);
    $mal = array_keys(array_filter($r, static fn(string $s): bool => !str_contains($s, '0.30000000000000004')));
    return $mal === [] ?: implode(', ', $mal) . ': ' . json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('exportar la tabla a sentencias INSERT', function () {
    $sql = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'exportar', 'formato' => 'sql', 'db' => 'tienda', 'tabla' => 'clientes',
    ]);
    return str_contains($sql, 'INSERT INTO "clientes" ("id", "cod", "nombre", "saldo"')
        && substr_count($sql, 'INSERT INTO') === filasDe('clientes');
});
chk('el INSERT exportado escapa las comillas y respeta NULL', function () {
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO clientes (cod, nombre) VALUES ('E5', NULL)"]);
    $sql = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'exportar', 'formato' => 'sql', 'db' => 'tienda', 'tabla' => 'clientes',
    ]);
    // La fila X9 guarda un valor con comilla simple dentro
    return str_contains($sql, "'x''); DROP TABLE clientes; --'")
        && str_contains($sql, "'E5', NULL");
});
chk('exportar el resultado de una consulta del editor', function () {
    $csv = enviar('p=sql&db=tienda', [
        'accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda',
        'sql' => "SELECT cod, nombre FROM clientes WHERE cod = 'B2'",
    ]);
    $lineas = explode("\n", trim($csv));
    return str_contains($lineas[0], 'cod;nombre') && count($lineas) === 2
        && str_contains($csv, 'Sin saldo');
});
chk('lo exportado se puede volver a insertar', function () {
    $sql = enviar('p=sql&db=tienda', [
        'accion' => 'exportar', 'formato' => 'sql', 'db' => 'tienda',
        'sql' => "SELECT cod, nombre FROM clientes WHERE cod = 'B2'",
    ]);
    // La sentencia generada, ejecutada tal cual en otra tabla
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE copia (cod TEXT, nombre TEXT)']);
    $linea = '';
    foreach (explode("\n", $sql) as $l) {
        if (str_starts_with($l, 'INSERT INTO')) { $linea = rtrim($l, ';'); break; }
    }
    $linea = str_replace('"clientes"', '"copia"', $linea);
    enviar('p=sql&db=tienda', ['sql' => $linea]);
    $r = enviar('p=sql&db=tienda', ['sql' => 'SELECT nombre FROM copia']);
    return str_contains($r, 'Sin saldo');
});
chk('una sentencia sin filas no se puede exportar', fn() =>
    str_contains(enviar('p=sql&db=tienda', [
        'accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda',
        'sql' => 'DELETE FROM copia',
    ]), 'SELECT y SHOW'));

echo "\n== Exportar la base entera ==\n";
chk('la pantalla de bases ofrece SQL y ZIP', function () {
    $html = pedir('p=bases');
    return str_contains($html, 'value="exportar_base"') && str_contains($html, 'value="zip"')
        && str_contains($html, 'Copia ZIP');
});
chk('volcado en SQL con estructura, datos, claves y triggers', function () {
    $sql = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'tienda']);
    return str_contains($sql, 'CREATE TABLE "clientes"')
        && str_contains($sql, '"id" INTEGER PRIMARY KEY AUTOINCREMENT')
        && str_contains($sql, 'INSERT INTO "clientes"')
        && str_contains($sql, 'CONSTRAINT "fk_pedidos_cliente" FOREIGN KEY')
        && !str_contains($sql, 'ALTER TABLE')
        && str_contains($sql, 'CREATE TRIGGER "trg_pedidos_ins"');
});
chk('el volcado recrea la base tal cual', function () {
    $sql = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'tienda']);
    enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'restaurada']);
    foreach (explode(";\n", $sql) as $sentencia) {
        $sentencia = trim(preg_replace('/^\s*--.*$/m', '', $sentencia));
        if ($sentencia === '') { continue; }
        enviar('p=sql&db=restaurada', ['sql' => $sentencia]);
    }
    $a = enviar('p=sql&db=tienda',     ['sql' => 'SELECT COUNT(*) AS n FROM clientes']);
    $b = enviar('p=sql&db=restaurada', ['sql' => 'SELECT COUNT(*) AS n FROM clientes']);
    preg_match('/<td>(\d+)<\/td>/', $a, $ma);
    preg_match('/<td>(\d+)<\/td>/', $b, $mb);
    return ($ma[1] ?? 'a') === ($mb[1] ?? 'b') ?: ($ma[1] ?? '?') . ' vs ' . ($mb[1] ?? '?');
});
chk('importar el volcado SQL desde el panel recrea la base, con claves y triggers', function () use ($raizDatos) {
    // El volcado lleva triggers con BEGIN … END y puntos y coma dentro, cadenas
    // con comillas y comentarios: todo lo que un separador ingenuo rompería
    $sql = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'tienda']);
    $fichero = $raizDatos . '/volcado.sql';
    file_put_contents($fichero, $sql);
    enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'importada']);
    $html = subir('p=tablas&db=importada', ['accion' => 'importar_sql', 'db' => 'importada'], 'fichero', $fichero);
    @unlink($fichero);
    if (!str_contains($html, 'sentencia(s) ejecutadas')) {
        return 'no terminó: ' . (preg_match('/alert-danger[^>]*>(.*?)</s', $html, $m) ? trim(strip_tags($m[1])) : '?');
    }
    foreach (['SELECT COUNT(*) AS n FROM clientes', 'SELECT COUNT(*) AS n FROM pedidos'] as $q) {
        preg_match('/<td>([\d.]+)<\/td>/', enviar('p=sql&db=tienda', ['sql' => $q]), $a);
        preg_match('/<td>([\d.]+)<\/td>/', enviar('p=sql&db=importada', ['sql' => $q]), $b);
        if (($a[1] ?? 'a') !== ($b[1] ?? 'b')) { return "$q: " . ($a[1] ?? '?') . ' frente a ' . ($b[1] ?? '?'); }
    }
    $trg = pedir('p=estructura&db=importada&tabla=pedidos');
    return str_contains($trg, 'trg_pedidos_ins') ?: 'no se recreó el trigger';
});
chk('cargar un CSV: separador deducido, comillas, campos vacíos como NULL y en lotes', function () use ($raizDatos) {
    enviar('p=sql&db=importada', ['sql' => 'CREATE TABLE gente (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre VARCHAR(40), edad INTEGER)']);
    $csv = "\xEF\xBB\xBFnombre;edad\n";
    for ($i = 1; $i <= 450; $i++) {
        $csv .= ($i === 7 ? '"Pérez; ""el grande"", Juan"' : "persona $i") . ';' . ($i % 50 === 0 ? '' : (string)($i % 90)) . "\n";
    }
    $fichero = $raizDatos . '/gente.csv';
    file_put_contents($fichero, $csv);
    $html = subir('p=tablas&db=importada', ['accion' => 'importar_csv', 'db' => 'importada', 'tabla' => 'gente'], 'fichero', $fichero);
    @unlink($fichero);
    if (!str_contains($html, '450 fila(s) cargadas')) { return 'no cargó las 450 filas'; }
    $nulos = enviar('p=sql&db=importada', ['sql' => 'SELECT COUNT(*) AS n FROM gente WHERE edad IS NULL']);
    $raro  = enviar('p=sql&db=importada', ['sql' => 'SELECT nombre FROM gente WHERE id = 7']);
    return (str_contains($nulos, '<td>9</td>') && str_contains($raro, 'Pérez; &quot;el grande&quot;, Juan'))
        ?: 'los vacíos no son NULL o las comillas no se respetaron';
});
chk('exportar e importar conserva textos de varias líneas y decimales exactos', function () use ($raizDatos) {
    // Un valor con saltos de línea sale en el volcado tal cual, dentro de su
    // cadena, y al importarlo no puede ganar ni perder ninguno; un decimal
    // como 0.1 + 0.2 tiene que volver con todas sus cifras
    enviar('p=sql&db=importada', ['sql' => 'CREATE TABLE raros (id INTEGER PRIMARY KEY, t VARCHAR(80), d DOUBLE)']);
    enviar('p=sql&db=importada', ['sql' => "INSERT INTO raros VALUES (1, 'uno\ndos\r\ntres -- no es comentario; ni esto', 0.30000000000000004)"]);
    $sql = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'importada']);
    $fichero = $raizDatos . '/volcado2.sql';
    file_put_contents($fichero, $sql);
    enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'importada2']);
    $html = subir('p=tablas&db=importada2', ['accion' => 'importar_sql', 'db' => 'importada2'], 'fichero', $fichero);
    @unlink($fichero);
    if (!str_contains($html, 'sentencia(s) ejecutadas')) { return 'la importación falló'; }
    $q = "SELECT COUNT(*) AS n FROM raros WHERE t = 'uno\ndos\r\ntres -- no es comentario; ni esto' AND d = 0.30000000000000004";
    return str_contains(enviar('p=sql&db=importada2', ['sql' => $q]), '<td>1</td>') ?: 'el texto o el decimal no volvieron iguales';
});
chk('el volcado de una base se carga tal cual en SQLite y en jsonSQLDB', function () use ($raizDatos) {
    // La puerta de salida: el mismo volcado vale para SQLite. Con una tabla
    // que se apunta a sí misma cuyas filas están guardadas hijo antes que
    // padre, y una vista: todo tiene que llegar a los dos sitios
    enviar('p=sql&db=importada2', ['sql' => 'CREATE TABLE emp (id INTEGER PRIMARY KEY, jefe INTEGER, n VARCHAR(10), FOREIGN KEY (jefe) REFERENCES emp (id))']);
    enviar('p=sql&db=importada2', ['sql' => "INSERT INTO emp VALUES (1, NULL, 'a'), (2, 1, 'b'), (3, 1, 'c')"]);
    enviar('p=sql&db=importada2', ['sql' => 'UPDATE emp SET jefe = 3 WHERE id = 2']);
    enviar('p=sql&db=importada2', ['sql' => 'CREATE TABLE p2 (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER, UNIQUE (a, b))']);
    enviar('p=sql&db=importada2', ['sql' => 'INSERT INTO p2 VALUES (1, 1, 1), (2, 1, 2)']);
    enviar('p=vistas&db=importada2', ['accion' => 'crear_vista', 'db' => 'importada2', 'nombre' => 'jefes',
                                      'sql' => 'SELECT id, n FROM emp WHERE jefe IS NULL']);
    $sql = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'importada2']);
    if (!str_contains($sql, 'CREATE TABLE')) { return 'no hay volcado'; }
    // En jsonSQLDB
    $fichero = $raizDatos . '/salida.sql';
    file_put_contents($fichero, $sql);
    enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'importada3']);
    $html = subir('p=tablas&db=importada3', ['accion' => 'importar_sql', 'db' => 'importada3'], 'fichero', $fichero);
    @unlink($fichero);
    if (!str_contains($html, 'sentencia(s) ejecutadas')) {
        return 'jsonSQLDB no lo cargó: ' . (preg_match('/alert-danger[^>]*>(.*?)</s', $html, $m) ? trim(strip_tags($m[1])) : '?');
    }
    if (!str_contains(enviar('p=sql&db=importada3', ['sql' => 'SELECT COUNT(*) AS n FROM jefes']), '<td>1</td>')) { return 'la vista no llegó a jsonSQLDB'; }
    // En SQLite
    if (!class_exists('SQLite3')) { return true; }
    $s = new SQLite3(':memory:');
    $s->enableExceptions(true);
    try { $s->exec($sql); } catch (Throwable $e) { return 'SQLite no lo cargó: ' . $e->getMessage(); }
    foreach (['emp' => 3, 'p2' => 2, 'raros' => 1, 'jefes' => 1] as $t => $n) {
        if ((int)$s->querySingle("SELECT COUNT(*) FROM \"$t\"") !== $n) { return "en SQLite, $t no tiene $n filas"; }
    }
    return $s->querySingle('SELECT jefe FROM emp WHERE id = 2') === 3 ?: 'en SQLite, los datos no son los mismos';
});
chk('el volcado para MySQL sale con su dialecto: comillas invertidas, InnoDB, BIGINT', function () {
    $sql = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'mysql', 'nombre' => 'tienda']);
    return str_contains($sql, 'CREATE TABLE `clientes`') && str_contains($sql, 'ENGINE=InnoDB')
        && str_contains($sql, 'BIGINT') && str_contains($sql, 'SET FOREIGN_KEY_CHECKS = 1')
        && !str_contains($sql, 'CREATE TABLE "') ?: 'no es el volcado para MySQL';
});
chk('importar desde el panel un volcado de SQLite y otro de MySQL, detectando el formato', function () {
    foreach (['sqlite' => 'SQLite', 'mysql' => 'MySQL / MariaDB'] as $f => $nombre) {
        enviar('p=bases', ['accion' => 'crear_base', 'nombre' => "desde_$f"]);
        $html = subir("p=tablas&db=desde_$f", ['accion' => 'importar_sql', 'db' => "desde_$f", 'formato' => 'auto'],
                      'fichero', dirname(__DIR__) . "/tests/volcados/$f.sql");
        if (!str_contains($html, "volcado de $nombre")) {
            return "$f: " . (preg_match('/alert-[a-z]+[^>]*>(.*?)</s', $html, $m) ? trim(strip_tags($m[1])) : '?');
        }
    }
    return str_contains(enviar('p=sql&db=desde_mysql', ['sql' => 'SELECT COUNT(*) AS n FROM z_clientes']), '<td>4</td>')
        ?: 'los datos de MySQL no llegaron';
});
chk('importar un fichero que crea o borra bases se rechaza', function () use ($raizDatos) {
    $fichero = $raizDatos . '/peligro.sql';
    file_put_contents($fichero, "CREATE TABLE ok1 (id INTEGER PRIMARY KEY);\nDROP DATABASE tienda;\n");
    $html = subir('p=tablas&db=importada2', ['accion' => 'importar_sql', 'db' => 'importada2'], 'fichero', $fichero);
    // Con un comentario en medio, que el motor se saltaría: el separador lo quita antes (2.9)
    file_put_contents($fichero, "DROP /* x */\n-- y\nDATABASE tienda;\n");
    $html2 = subir('p=tablas&db=importada2', ['accion' => 'importar_sql', 'db' => 'importada2'], 'fichero', $fichero);
    @unlink($fichero);
    return str_contains($html, 'crear o borrar una base') && str_contains($html2, 'crear o borrar una base')
        && str_contains(pedir('p=bases'), 'tienda') ?: 'no lo rechazó';
});
chk('un CSV con un dato que no encaja dice dónde paró y no deja nada a medias (el panel alcanza la carpeta)', function () use ($raizDatos) {
    // Con la carpeta de la base en la misma máquina, la importación es todo o
    // nada: antes quedaban cargadas las 200 filas anteriores al fallo
    $contar = static fn(): ?int => preg_match('/<td>(\d+)<\/td>/', enviar('p=sql&db=importada', ['sql' => 'SELECT COUNT(*) AS n FROM gente']), $m) ? (int)$m[1] : null;
    $antes = $contar();
    $csv = "nombre,edad\n";
    for ($i = 1; $i <= 300; $i++) { $csv .= "otra $i," . ($i === 250 ? 'abc' : '30') . "\n"; }
    $fichero = $raizDatos . '/malo.csv';
    file_put_contents($fichero, $csv);
    $html = subir('p=tablas&db=importada', ['accion' => 'importar_csv', 'db' => 'importada', 'tabla' => 'gente'], 'fichero', $fichero);
    @unlink($fichero);
    $despues = $contar();
    return str_contains($html, 'línea 301') && str_contains($html, 'en las 100 anteriores') && str_contains($html, 'No se ha cargado nada') && $antes !== null && $antes === $despues
        ?: 'no explicó el fallo o dejó filas: ' . json_encode([$antes, $despues]) . ' ' . (preg_match('/alert-danger[^>]*>(.*?)</s', $html, $m) ? trim(strip_tags($m[1])) : '?');
});
/**
 * Un .xlsx mínimo, escrito a mano como lo guarda Excel: textos compartidos y
 * en línea, números, una fecha con formato de Excel (14), otra con formato
 * propio con hora, un booleano, una celda vacía en medio y una con formato y
 * sin dato al final.
 */
function xlsxDePrueba(string $fichero): void {
    $zip = new ZipArchive();
    $zip->open($fichero, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Datos" sheetId="1" r:id="rId7"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="worksheets/hoja.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy\ hh:mm"/></numFmts><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>nombre</t></si><si><t>edad</t></si><si><t>alta</t></si><si><t>activo</t></si><si><r><t>Ana </t></r><r><t>Pérez</t></r></si><si/></sst>');
    $zip->addFromString('xl/worksheets/hoja.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
        . '<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2"><v>41</v></c><c r="C2" s="1"><v>46023</v></c><c r="D2" t="b"><v>1</v></c><c r="E2" s="1"/></row>'
        . '<row r="4"><c r="A4" t="inlineStr"><is><t>Luis &amp; Cía</t></is></c><c r="C4" s="2"><v>46023.5</v></c><c r="D4" t="b"><v>0</v></c></row>'
        . '</sheetData></worksheet>');
    $zip->close();
}
chk('el separador del CSV se elige por las columnas, no por cuántas comas hay entre comillas', function () use ($raizDatos) {
    enviar('p=sql&db=importada', ['sql' => 'CREATE TABLE csvsep (id INTEGER PRIMARY KEY AUTOINCREMENT, "nombre, completo" TEXT, edad INTEGER)']);
    $ficheros = [
        'coma'      => "\"nombre, completo\",edad\n\"Pérez, Juan\",41\n\"Gil, Eva\",22\n",
        'punto_y_coma' => "\"nombre, completo\";edad\n\"Ruiz, Ana\";30\n",
        // Sin comillas, la coma de la cabecera ganaba al tabulador
        'tabulador' => "nombre, completo\tedad\nLuis Mas\t50\nRosa Sol\t61\n",
        // Una sola columna: no hay separador que valga, y no falla
        'una'       => "edad\n70\n",
    ];
    foreach ($ficheros as $nombre => $texto) {
        $f = $raizDatos . "/$nombre.csv";
        file_put_contents($f, $texto);
        $html = subir('p=tablas&db=importada', ['accion' => 'importar_csv', 'db' => 'importada', 'tabla' => 'csvsep'], 'fichero', $f);
        @unlink($f);
        if (!str_contains($html, 'fila(s) cargadas')) { return "$nombre: " . mensajeDe($html); }
    }
    $r = enviar('p=sql&db=importada', ['sql' => 'SELECT COUNT(*) AS n, SUM(edad) AS s FROM csvsep WHERE "nombre, completo" LIKE \'%,%\' OR "nombre, completo" LIKE \'% %\' OR "nombre, completo" IS NULL']);
    return str_contains($r, '<td>6</td>') && str_contains($r, '<td>274</td>') ?: strip_tags((string)preg_replace('/.*<table/s', '<table', $r));
});
chk('por la API, un CSV de filas anchas y uno de muchas columnas se cargan: los lotes caben en sus límites por petición (2.9)', function () use ($raizDatos) {
    enviar('p=sql&db=importada', ['sql' => 'CREATE TABLE lote_ancho (id INTEGER PRIMARY KEY, a TEXT, b TEXT, c TEXT, d TEXT, e TEXT, f TEXT)']);
    $f = $raizDatos . '/anchas.csv';
    $texto = "id,a,b,c,d,e,f\n";
    for ($i = 1; $i <= 300; $i++) {
        $texto .= $i . ',' . str_repeat('x', 2000) . ",b,c,d,e,f\n";      // 300 × 2 KB, y 7 columnas: 200 filas serían 1.400 parámetros
    }
    file_put_contents($f, $texto);
    $GLOBALS['cabecerasPrueba'] = ['X-Prueba: sin-motor'];                 // por la API de verdad, sin transacción
    $html = subir('p=tablas&db=importada', ['accion' => 'importar_csv', 'db' => 'importada', 'tabla' => 'lote_ancho'], 'fichero', $f);
    @unlink($f);
    // Y un volcado SQL de 300 INSERT de 2 KB: juntos pasaban de MAX_SQL_LENGTH
    $sql = "CREATE TABLE lote_ancho2 (id INTEGER PRIMARY KEY, a TEXT);\n";
    for ($i = 1; $i <= 300; $i++) {
        $sql .= "INSERT INTO lote_ancho2 VALUES ($i, '" . str_repeat('y', 2000) . "');\n";
    }
    $g = $raizDatos . '/anchas.sql';
    file_put_contents($g, $sql);
    $html2 = subir('p=tablas&db=importada', ['accion' => 'importar_sql', 'db' => 'importada', 'formato' => 'jsonsqldb'], 'fichero', $g);
    $GLOBALS['cabecerasPrueba'] = [];
    @unlink($g);
    $n = enviar('p=sql&db=importada', ['sql' => 'SELECT (SELECT COUNT(*) FROM lote_ancho) + (SELECT COUNT(*) FROM lote_ancho2) AS n']);
    return str_contains($n, '<td>600</td>') ?: mensajeDe($html) . ' / ' . mensajeDe($html2);
});
chk('por la API, un INSERT de mysqldump de 600 KB con muchas filas se carga: va por tandas que caben en la API (2.9)', function () use ($raizDatos) {
    $filas = [];
    for ($i = 1; $i <= 300; $i++) {
        $filas[] = "($i,'" . str_repeat('z', 2000) . "),(\\'$i\\'')";      // con «),(» y comillas dentro del texto
    }
    $g = $raizDatos . '/extendido.sql';
    file_put_contents($g, "-- MySQL dump 10.13\nCREATE TABLE `extendido` (`id` int NOT NULL, `t` text, PRIMARY KEY (`id`));\n"
        . 'INSERT INTO `extendido` VALUES ' . implode(',', $filas) . ";\n");
    $GLOBALS['cabecerasPrueba'] = ['X-Prueba: sin-motor'];                 // por la API de verdad, sin transacción
    $html = subir('p=tablas&db=importada', ['accion' => 'importar_sql', 'db' => 'importada', 'formato' => 'mysql'], 'fichero', $g);
    $GLOBALS['cabecerasPrueba'] = [];
    $tam = filesize($g);
    @unlink($g);
    $n = enviar('p=sql&db=importada', ['sql' => "SELECT COUNT(*) AS n, SUM(LENGTH(t)) AS l FROM extendido WHERE t LIKE '%),(''%'''"]);
    return $tam > 600000 && str_contains($n, '<td>300</td>') && str_contains($n, '<td>' . (300 * 2005 + 9 * 1 + 90 * 2 + 201 * 3) . '</td>') ?: mensajeDe($html) . ' / ' . strip_tags($n);
});
chk('cargar un Excel: textos, números, fechas con y sin hora, booleanos y celdas vacías', function () use ($raizDatos) {
    if (!class_exists('ZipArchive')) { return 'falta la extensión zip'; }
    enviar('p=sql&db=importada', ['sql' => 'CREATE TABLE excel (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre VARCHAR(40), edad INTEGER, alta DATETIME, activo INTEGER)']);
    if (!str_contains(pedir('p=tablas&db=importada'), 'value="importar_xlsx"')) { return 'no está el formulario'; }
    $fichero = $raizDatos . '/datos.xlsx';
    xlsxDePrueba($fichero);
    $html = subir('p=tablas&db=importada', ['accion' => 'importar_xlsx', 'db' => 'importada', 'tabla' => 'excel'], 'fichero', $fichero);
    @unlink($fichero);
    if (!str_contains($html, '2 fila(s) cargadas')) { return mensajeDe($html); }
    $r = enviar('p=sql&db=importada', ['sql' => "SELECT nombre || '|' || COALESCE(edad, 'nulo') || '|' || alta || '|' || activo AS f FROM excel ORDER BY id"]);
    return str_contains($r, 'Ana Pérez|41|2026-01-01|1') && str_contains($r, 'Luis &amp; Cía|nulo|2026-01-01 12:00:00|0')
        ?: strip_tags((string)preg_replace('/.*<table/s', '<table', $r));
});
chk('un Excel dejado en la carpeta de importar con # en el nombre se carga', function () use ($raizDatos) {
    // zip:// toma el # por el separador de la entrada del ZIP
    @mkdir($raizDatos . '/admin/importar', 0775, true);
    $fichero = $raizDatos . '/admin/importar/datos #1.xlsx';
    xlsxDePrueba($fichero);
    $html = enviar('p=tablas&db=importada', ['accion' => 'importar_xlsx', 'db' => 'importada', 'tabla' => 'excel', 'servidor' => 'datos #1.xlsx']);
    @unlink($fichero);
    return str_contains($html, '2 fila(s) cargadas') && glob(sys_get_temp_dir() . '/jsonsqldb_xlsx_*') === [] ?: mensajeDe($html);
});
chk('un fichero que no es un Excel lo dice', function () use ($raizDatos) {
    $fichero = $raizDatos . '/falso.xlsx';
    file_put_contents($fichero, 'no es un zip');
    $html = subir('p=tablas&db=importada', ['accion' => 'importar_xlsx', 'db' => 'importada', 'tabla' => 'excel'], 'fichero', $fichero);
    @unlink($fichero);
    return str_contains($html, 'no es un Excel') ?: mensajeDe($html);
});
chk('con la API en otra máquina, el ZIP se desactiva y se explica', function () use ($raizDatos) {
    // Se pide el listado de bases haciendo creer al panel que la API está fuera
    $conf = $raizDatos . '/_otrohost.php';
    file_put_contents($conf, "<?php\n"
        . "define('ADMIN_API_URL', 'https://otra-maquina.example/api/jsonsqldb_api.php');\n");

    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "\$_SERVER['HTTP_HOST'] = 'panel.example';"
        . "require " . var_export($conf, true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/config.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/Api.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/Idioma.php', true) . ";"
        . "Idioma::elegir('es');"
        . "var_export(mismoHostQueLaApi());"
        . "try { rutaDeLaBase('tienda'); echo '|sin aviso'; }"
        . "catch (Throwable \$e) { echo '|', \$e->getMessage(); }"
    ) . ' 2>&1');
    @unlink($conf);

    return str_starts_with($salida, 'false')
        && str_contains($salida, 'misma máquina')
        && str_contains($salida, 'otra-maquina.example') ?: $salida;
});
chk('con el mismo host sí está disponible', function () use ($puertoApi) {
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "\$_SERVER['HTTP_HOST'] = '127.0.0.1:$puertoApi';"
        . "define('ADMIN_API_URL', 'http://127.0.0.1:$puertoApi/api/jsonsqldb_api.php');"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/config.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/Api.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/Idioma.php', true) . ";"
        . "Idioma::elegir('es');"
        . "var_export(mismoHostQueLaApi());"
    ) . ' 2>&1');
    return trim($salida) === 'true' ?: $salida;
});
chk('la copia en ZIP trae la estructura de carpetas', function () use ($raizDatos) {
    if (!class_exists('ZipArchive')) {
        echo "       (omitida: falta la extensión zip de PHP)\n";
        return true;
    }
    $bin = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => 'tienda']);
    $f   = $raizDatos . '/copia.zip';
    file_put_contents($f, $bin);
    $zip = new ZipArchive();
    if ($zip->open($f) !== true) { return 'no es un ZIP válido'; }
    $nombres = [];
    for ($i = 0; $i < $zip->numFiles; $i++) { $nombres[] = $zip->getNameIndex($i); }
    $zip->close();
    @unlink($f);
    // Ni la caché ni los temporales de una ordenación ni los ficheros de turno
    // de las tablas (2.9): se rehacen solos y la caché puede ocupar mucho
    $sobran = preg_grep('#/\.(cache|orden)/|\.turno$#', $nombres);
    return in_array('tienda/_database.json', $nombres, true)
        && in_array('tienda/clientes.json', $nombres, true)
        && in_array('tienda/clientes.meta.json', $nombres, true) && $sobran === [] ?: $nombres;
});
chk('el ZIP no deja temporales por el camino', function () {
    // Se comparan los nombres, no el número: un temporal de una exportación
    // anterior puede desaparecer por el camino y falsear la cuenta.
    $temporales = static fn(): array => (array)glob(sys_get_temp_dir() . '/jsonsqldb_*');
    $antes = $temporales();

    enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => 'tienda']);

    // cURL puede devolver el cuerpo entero antes de que el proceso del servidor
    // haya terminado su finally, así que se le da un margen: lo que se comprueba
    // es que no queda huérfano, no que desaparezca al instante.
    for ($i = 0; $i < 40; $i++) {
        $nuevos = array_values(array_diff($temporales(), $antes));
        if ($nuevos === []) {
            return true;
        }
        usleep(50000);
    }
    return 'quedan sin borrar: ' . implode(', ', $nuevos);
});
chk('borrar la base restaurada', function () {
    enviar('p=bases', ['accion' => 'borrar_base', 'nombre' => 'restaurada',
                       'confirmacion' => 'restaurada']);
    return !str_contains(pedir('p=bases'), 'db=restaurada');
});

echo "\n== Restaurar desde ZIP ==\n";
chk('el host se compara bien, también en IPv6', function () {
    // util.php es del panel y aquí no está cargado: se carga en un proceso
    // aparte para no arrastrar su configuración a esta prueba
    $codigo = 'require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php', true) . ';'
            . '$c = ["[::1]:8080"=>"::1","::1"=>"::1","127.0.0.1:8080"=>"127.0.0.1",'
            . '"localhost:8080"=>"localhost","LOCALHOST"=>"localhost",'
            . '"[2001:db8::1]:443"=>"2001:db8::1","ejemplo.es"=>"ejemplo.es",""=>""];'
            . 'foreach ($c as $e => $s) { $r = self_soloHost((string)$e);'
            . '  if ($r !== $s) { echo "\"$e\" dio \"$r\" y no \"$s\""; exit; } }'
            . 'echo "ok";';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    return $salida === 'ok' ?: $salida;
});
chk('exportar y volver a restaurar deja la base igual', function () {
    if (!class_exists('ZipArchive')) {
        echo "       (omitida: falta la extensión zip)\n";
        return true;
    }
    enviar('p=sql&db=tienda', ['sql' => 'CREATE TABLE restaurable (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(20))']);
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO restaurable (v) VALUES ('uno'),('dos')"]);

    $zip = descargar('p=bases', ['accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => 'tienda']);
    $f   = sys_get_temp_dir() . '/prueba_restaurar_' . getmypid() . '.zip';
    file_put_contents($f, $zip);

    // Se destroza la tabla y se restaura
    enviar('p=sql&db=tienda', ['sql' => 'DELETE FROM restaurable']);
    $html = subir('p=bases', ['accion' => 'importar_zip', 'nombre' => 'tienda'], 'zip', $f);
    @unlink($f);

    if (!str_contains($html, 'restaurada')) {
        return 'no restauró -> ' . mensajeDe($html);
    }
    $r = enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM restaurable']);
    return str_contains($r, '<td>2</td>') ?: 'las filas no volvieron';
});
chk('un ZIP que trae la carpeta .cache no deja en ella nada: ni un .php que el motor pudiera cargar como código (2.9)', function () use ($raizDatos) {
    if (!class_exists('ZipArchive')) { return true; }
    $zip = descargar('p=bases', ['accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => 'tienda']);
    $f = sys_get_temp_dir() . '/con_cache_' . getmypid() . '.zip';
    file_put_contents($f, $zip);
    $z = new ZipArchive();
    $z->open($f);
    $z->addFromString('tienda/.cache/0123456789abcdef0123456789abcdef.p11.php', '<?php return ["malo"];');
    $z->addFromString('tienda/.cache/0123456789abcdef0123456789abcdef.p11.json', '{}');
    $z->close();
    $html = subir('p=bases', ['accion' => 'importar_zip', 'nombre' => 'tienda'], 'zip', $f);
    @unlink($f);
    $puestos = (array)glob($raizDatos . '/tienda/.cache/0123456789abcdef0123456789abcdef.*');
    return str_contains($html, 'restaurada') && $puestos === [] ?: [mensajeDe($html), $puestos];
});
chk('un ZIP no pone nada en .tx/ ni en .lote/, que el motor tomaría por una escritura a medias, y sí trae .reparados/ (2.9)', function () use ($raizDatos) {
    if (!class_exists('ZipArchive')) { return true; }
    $zip = descargar('p=bases', ['accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => 'tienda']);
    $f = sys_get_temp_dir() . '/con_diario_' . getmypid() . '.zip';
    file_put_contents($f, $zip);
    $z = new ZipArchive();
    $z->open($f);
    $z->addFromString('tienda/.tx/_base.json', json_encode(['rows' => [], 'tipo' => 'redo', 'ambito' => null, 'tablas' => [],
        'renombrar' => [], 'borrar' => ['.htaccess', 'web.config'], 'regenerables' => []]));
    $z->addFromString('tienda/.lote/manifiesto.lote', json_encode(['rows' => [], 'guardados' => [], 'nuevos' => ['.htaccess', 'web.config']]));
    $z->addFromString('tienda/.reparados/apartadas.json', json_encode(['siguiente' => 2, 'filas' => []]));
    $z->addFromString('tienda/.reparados/clientes.json.20261006-120000', '{"rows":[ roto');
    $z->addFromString('tienda/.otra/x.json', '{"rows":[]}');
    $z->close();
    $html = subir('p=bases', ['accion' => 'importar_zip', 'nombre' => 'tienda'], 'zip', $f);
    @unlink($f);
    pedir('p=datos&db=tienda&tabla=clientes');            // el motor abre la base
    $b = $raizDatos . '/tienda';
    $r = [str_contains($html, 'restaurada'), is_file("$b/.htaccess"), is_file("$b/web.config"), is_file("$b/.tx/_base.json"),
          is_file("$b/.lote/manifiesto.lote"), is_file("$b/.reparados/apartadas.json"), is_file("$b/.reparados/clientes.json.20261006-120000"),
          is_dir("$b/.otra")];
    return $r === [true, true, true, false, false, true, true, false] ?: json_encode([mensajeDe($html), $r]);
});
chk('una base con «-» en el nombre, o que empieza por un número, se restaura desde su ZIP (2.9: el nombre se rechazaba)', function () {
    if (!class_exists('ZipArchive')) { return true; }
    enviar('p=bases', ['accion' => 'crear_base', 'nombre' => '2-copia']);
    enviar('p=sql&db=2-copia', ['sql' => 'CREATE TABLE t (id INTEGER PRIMARY KEY)']);
    $zip = descargar('p=bases', ['accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => '2-copia']);
    $f = sys_get_temp_dir() . '/con_guion_' . getmypid() . '.zip';
    file_put_contents($f, $zip);
    $html = subir('p=bases', ['accion' => 'importar_zip', 'nombre' => '2-copia'], 'zip', $f);
    @unlink($f);
    enviar('p=bases', ['accion' => 'borrar_base', 'nombre' => '2-copia', 'confirmacion' => '2-copia']);
    return str_contains($html, 'restaurada') ?: mensajeDe($html);
});
chk('un ZIP con rutas hacia arriba se rechaza sin tocar nada', function () use ($raizDatos) {
    if (!class_exists('ZipArchive')) { return true; }
    $f = sys_get_temp_dir() . '/malicioso_' . getmypid() . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('tienda/../../fuera.json', '{"rows":[]}');
    $z->close();

    $html = subir('p=bases', ['accion' => 'importar_zip', 'nombre' => 'tienda'], 'zip', $f);
    @unlink($f);
    return str_contains($html, 'sale de la carpeta') && !is_file(dirname($raizDatos) . '/fuera.json')
        ?: mensajeDe($html);
});
chk('un ZIP con un .json que no es del motor se rechaza', function () {
    if (!class_exists('ZipArchive')) { return true; }
    $f = sys_get_temp_dir() . '/roto_' . getmypid() . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('tienda/clientes.json', 'esto no es JSON');
    $z->close();

    $html = subir('p=bases', ['accion' => 'importar_zip', 'nombre' => 'tienda'], 'zip', $f);
    @unlink($f);
    return str_contains($html, 'no es un JSON válido') ?: mensajeDe($html);
});
chk('un ZIP sin tablas se rechaza', function () {
    if (!class_exists('ZipArchive')) { return true; }
    $f = sys_get_temp_dir() . '/vacio_' . getmypid() . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('tienda/leeme.txt', 'nada');
    $z->close();

    $html = subir('p=bases', ['accion' => 'importar_zip', 'nombre' => 'tienda'], 'zip', $f);
    @unlink($f);
    return str_contains($html, 'ninguna tabla') ?: mensajeDe($html);
});
chk('limpiar la tabla restaurable', function () {
    enviar('p=sql&db=tienda', ['sql' => 'DROP TABLE restaurable']);
    return true;
});

echo "\n== Usuarios y permisos ==\n";
chk('cambiar la propia contraseña pide la actual (2.9: una sesión abierta un momento bastaba para quedarse con la cuenta)', function () {
    $cambiar = static fn(array $campos): string => mensajeDe(enviar('p=usuarios', ['accion' => 'cambiar_clave', 'usuario' => 'jefe'] + $campos));
    $sin   = $cambiar(['clave' => 'otra-clave-del-jefe-2']);
    $mala  = $cambiar(['clave' => 'otra-clave-del-jefe-2', 'actual' => 'no-es-la-suya']);
    $buena = $cambiar(['clave' => 'otra-clave-del-jefe-2', 'actual' => 'clave-muy-larga-1']);
    $vuelta = $cambiar(['clave' => 'clave-muy-larga-1', 'actual' => 'otra-clave-del-jefe-2']);
    return str_contains($sin, 'actual no es correcta') && str_contains($mala, 'actual no es correcta')
        && str_contains($buena, 'Contraseña cambiada') && str_contains($vuelta, 'Contraseña cambiada') ?: json_encode([$sin, $mala, $buena, $vuelta], JSON_UNESCAPED_UNICODE);
});
chk('crear un usuario de solo lectura', fn() =>
    str_contains(enviar('p=usuarios', [
        'accion' => 'crear_usuario', 'usuario' => 'consulta',
        'clave' => 'otra-clave-larga-2', 'rol' => 'lectura',
    ]), 'consulta&#039; creado'));
chk('un campo enviado como lista (campo[]=x) da un error limpio, sin avisos de PHP ni el texto «Array» como valor (2.9)', function () use ($raizDatos) {
    $log = $raizDatos . '/server.log';
    clearstatcache();
    $desde = (int)@filesize($log);
    $estructura = 'p=estructura&db=tienda&tabla=clientes';
    $r = [
        'copia'   => enviar('p=copias', ['accion' => 'programar_copia', 'base' => ['tienda'], 'frecuencia' => 'diaria', 'formato' => 'sql', 'horas' => ['2']]),
        'indice'  => enviar($estructura, ['accion' => 'crear_indice', 'db' => 'tienda', 'tabla' => 'clientes', 'nombre' => 'ix_lista', 'columnas' => [['id']]]),
        'columna' => enviar($estructura, ['accion' => 'anadir_columna', 'db' => 'tienda', 'tabla' => 'clientes', 'nombre' => ['c'], 'tipo' => ['TEXT']]),
        'tabla'   => enviar('p=crear_tabla&db=tienda', ['accion' => 'crear_tabla', 'db' => 'tienda', 'nombre' => 't_lista', 'columnas' => [['nombre' => ['a'], 'tipo' => 'TEXT']]]),
        'usuario' => enviar('p=usuarios', ['accion' => 'crear_usuario', 'usuario' => 'lista', 'clave' => ['otra-clave-larga-3'], 'rol' => 'lectura']),
        'csrf'    => peticion('p=bases', ['accion' => 'crear_base', 'nombre' => 'lista', 'csrf' => ['x']]),
        'sql'     => enviar('p=sql&db=tienda', ['sql' => ['SELECT 1']]) . pedir('p=sql&db=tienda&sql[]=x'),
    ];
    $esperado = ['copia' => 'no válido', 'indice' => 'no válido', 'columna' => 'no válido', 'tabla' => 'al menos una columna',
                 'usuario' => 'al menos 10 caracteres', 'csrf' => 'Formulario caducado', 'sql' => 'Consola SQL'];
    clearstatcache();
    $nuevo = (string)@file_get_contents($log, false, null, $desde);
    foreach ($esperado as $k => $texto) {
        if (!str_contains($r[$k], $texto)) { return "$k: " . mensajeDe($r[$k]); }
        if (preg_match('/Array to string conversion|<b>(Warning|Notice)<\/b>/', $r[$k])) { return "$k: aviso de PHP en la página"; }
    }
    if (str_contains((string)@file_get_contents("$raizDatos/admin/copias.json"), '"Array"')) { return 'copia programada para la base «Array»'; }
    return !preg_match('/Array to string conversion|PHP (Warning|Notice)/', $nuevo) ?: 'el servidor registró: ' . substr($nuevo, 0, 200);
});
chk('cerrar sesión con un enlace (GET) ya no la cierra: hace falta POST y su token', function () {
    pedir('p=salir');
    $sigue = str_contains(pedir('p=bases'), 'Bases de datos');
    $r = peticion('p=salir', []);                   // POST sin token: se avisa, sin error fatal
    $sigue2 = str_contains(pedir('p=bases'), 'Bases de datos');
    return $sigue && $sigue2 && str_contains($r, 'Formulario caducado') && !str_contains($r, 'Uncaught')
        ?: 'se cerró sin POST con token, o salió un error fatal';
});
/** Las claves de los campos de la pestaña Datos van en hexadecimal (2.9: ver campo() del panel) */
function hx(array $campos): array
{
    $out = [];
    foreach ($campos as $columna => $v) {
        $out[bin2hex((string)$columna)] = $v;
    }
    return $out;
}

/** Ejecuta código con las clases del panel en otro proceso, con sus datos en $dir. */
function conPanel(string $codigo, string $dir, string $antes = ''): string
{
    $lib = dirname(__DIR__) . '/jsonsqldbadmin';
    $prog = 'define("ADMIN_DATA_PATH", ' . var_export($dir, true) . ');' . $antes
          . 'require ' . var_export("$lib/config.dist.php", true) . ';'
          . 'foreach (["util", "Idioma", "Store", "Auth"] as $c) { require ' . var_export("$lib/lib/", true) . ' . $c . ".php"; }'
          . $codigo;
    return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($prog) . ' 2>&1'));
}
chk('detrás de un proxy de confianza con HTTPS, la cookie de sesión lleva Secure', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_proxy_' . getmypid();
    @mkdir($dir, 0775, true);
    $con = conPanel('$_SERVER["HTTP_X_FORWARDED_PROTO"] = "https"; Auth::iniciarSesion(); echo json_encode(session_get_cookie_params()["secure"]);',
                    $dir, 'define("ADMIN_CONFIAR_EN_PROXY", true);');
    $sin = conPanel('$_SERVER["HTTP_X_FORWARDED_PROTO"] = "https"; Auth::iniciarSesion(); echo json_encode(session_get_cookie_params()["secure"]);',
                    $dir, 'define("ADMIN_CONFIAR_EN_PROXY", false);');
    array_map('unlink', (array)glob("$dir/*"));
    @rmdir($dir);
    // Sin confiar en el proxy, su cabecera no cuenta: cualquiera podría mandarla
    return $con === 'true' && $sin === 'false' ?: "con proxy: $con, sin confiar en él: $sin";
});
chk('detrás de un proxy de confianza, la IP es la última de X-Forwarded-For, la que pone el proxy (2.9: la primera, que escribe el cliente)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_xff_' . getmypid();
    @mkdir($dir, 0775, true);
    $ip = 'echo util_ip();';
    $env = '$_SERVER["REMOTE_ADDR"] = "10.0.0.1"; $_SERVER["HTTP_X_FORWARDED_FOR"] = "198.51.100.7, 203.0.113.9";';
    $con = conPanel($env . $ip, $dir, 'define("ADMIN_CONFIAR_EN_PROXY", true);');
    $sin = conPanel($env . $ip, $dir, 'define("ADMIN_CONFIAR_EN_PROXY", false);');
    array_map('unlink', (array)glob("$dir/*"));
    @rmdir($dir);
    return $con === '203.0.113.9' && $sin === '10.0.0.1' ?: "con proxy: $con, sin confiar en él: $sin";
});
chk('un fichero del panel dañado no se pisa al cambiarlo: se avisa y se deja como está (2.9: dar de alta un usuario borraba los demás)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_store_' . getmypid();
    @mkdir($dir, 0775, true);
    file_put_contents("$dir/usuarios.json", '{"ana": {"hash": "x"}, roto');
    $r = conPanel('try { Store::actualizar("usuarios.json", static fn(array $u): array => $u + ["bea" => []]); echo "cambiado"; } catch (RuntimeException $e) { echo $e->getMessage(); }', $dir);
    $queda = (string)file_get_contents("$dir/usuarios.json");
    array_map('unlink', (array)glob("$dir/*"));
    @rmdir($dir);
    return (str_contains($r, 'dañado') || str_contains($r, 'damaged')) && $queda === '{"ana": {"hash": "x"}, roto' ?: [$r, $queda];
});
chk('el panel usa la zona horaria del motor, también si la de php.ini es otra (2.9: la auditoría de un mismo día quedaba en dos ficheros)', function () {
    $codigo = 'putenv("JSONSQLDBADMIN_CONFIG=/no/existe"); require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/arranque.php', true) . '; echo date_default_timezone_get();';
    $zona = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d date.timezone=UTC -r ' . escapeshellarg($codigo) . ' 2>&1'));
    return $zona === 'Europe/Madrid' ?: $zona;
});
chk('con la conexión directa, el navegador comprueba la carpeta de datos pidiendo un fichero real de una base, en la dirección que le corresponde junto al panel (2.10)', function () {
    $t = sys_get_temp_dir() . '/jsonsqldb_test_web_' . getmypid();
    foreach (['www/jsonsqldbadmin', 'www/data/zeta', 'www/data/alfa', 'www/sub dir/jsonsqldbadmin', 'www/sub dir/datos ñ/b1',
              'fuera/b1', 'www/solo', 'www/vacia'] as $d) {
        @mkdir("$t/$d", 0775, true);
    }
    foreach (['www/data/zeta', 'www/data/alfa', 'www/sub dir/datos ñ/b1', 'fuera/b1'] as $d) {
        file_put_contents("$t/$d/_database.json", '{}');
    }
    // Con bases, el fichero de una, aunque también esté el web.config de la carpeta
    file_put_contents("$t/www/data/web.config", '<configuration/>');
    file_put_contents("$t/www/solo/web.config", '<configuration/>');
    $probar = static function (string $datos, string $panel, string $script) use ($t): ?array {
        $codigo = 'putenv("JSONSQLDBADMIN_CONFIG=/no/existe"); define("ADMIN_DATA_PATH", ' . var_export("$t/admin", true) . ');'
            . 'define("ADMIN_CONEXION", "directa"); define("ADMIN_MOTOR_RUTA", ' . var_export(dirname(__DIR__), true) . ');'
            . 'define("JSONSQLDB_DATA_PATH", ' . var_export("$t/$datos", true) . ');'
            . '$_SERVER["SCRIPT_FILENAME"] = ' . var_export("$t/$panel/index.php", true) . '; $_SERVER["SCRIPT_NAME"] = ' . var_export($script, true) . ';'
            . 'require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/arranque.php', true) . ';'
            . 'echo json_encode(Instalador::estadoDatos(), JSON_UNESCAPED_UNICODE);';
        // Sin pasar por la shell: en PHP 8.0, escapeshellarg() quita la «ñ»
        // con la configuración regional C, y la ruta ya no existía
        $proc = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos);
        $salida = (string)stream_get_contents($tubos[1]);
        fclose($tubos[1]);
        proc_close($proc);
        return json_decode($salida, true);
    };
    $casos = [
        // datos, carpeta del panel, su dirección => la que se comprueba (null: ninguna) y el estado sin comprobar
        ['www/data', 'www/jsonsqldbadmin', '/jsonsqldbadmin/index.php', '/data/alfa/_database.json', null],
        ['www/data', 'www/jsonsqldbadmin', '/~ana/jsonsqldbadmin/index.php', '/~ana/data/alfa/_database.json', null],
        ['www/sub dir/datos ñ', 'www/sub dir/jsonsqldbadmin', '/sub dir/jsonsqldbadmin/index.php', '/sub%20dir/datos%20%C3%B1/b1/_database.json', null],
        ['www/data', 'www/sub dir/jsonsqldbadmin', '/sub dir/jsonsqldbadmin/index.php', '/data/alfa/_database.json', null],
        ['fuera', 'www/jsonsqldbadmin', '/jsonsqldbadmin/index.php', null, true],
        ['www/data', 'www/jsonsqldbadmin', '/index.php', null, true],    // el panel es la raíz del sitio: lo de arriba no se sirve
        ['www/solo', 'www/jsonsqldbadmin', '/jsonsqldbadmin/index.php', '/solo/web.config', null],
        ['www/vacia', 'www/jsonsqldbadmin', '/jsonsqldbadmin/index.php', null, null],
    ];
    $mal = [];
    foreach ($casos as [$datos, $panel, $script, $url, $estado]) {
        $r = $probar($datos, $panel, $script);
        if (!is_array($r) || $r[2] !== $url || $r[0] !== $estado) {
            $mal[] = "$datos con $script: " . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    borrarArbol($t);
    return $mal === [] ?: implode(' · ', $mal);
});
chk('por la API, la portada comprueba la carpeta de datos una vez por sesión; Configuración, cada vez (2.10)', function () {
    $oyente = stream_socket_server('tcp://127.0.0.1:0');
    $puerto = (int)substr((string)strrchr((string)stream_socket_get_name($oyente, false), ':'), 1);
    $t = sys_get_temp_dir() . '/jsonsqldb_test_sesion_' . getmypid();
    @mkdir($t, 0775, true);
    $codigo = 'putenv("JSONSQLDBADMIN_CONFIG=/no/existe"); define("ADMIN_DATA_PATH", ' . var_export($t, true) . ');'
        . 'define("ADMIN_API_URL", "http://127.0.0.1:' . $puerto . '/api/jsonsqldb_api.php");'
        . 'require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/arranque.php', true) . ';'
        . '$_SESSION = []; echo json_encode([Instalador::estadoDatos(), Instalador::estadoDatos(), Instalador::estadoDatos(true)], JSON_UNESCAPED_UNICODE);';
    $proc = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
    // Contesta 403 a la primera petición y 404 a la segunda
    foreach ([403, 404] as $codigoHttp) {
        $cliente = @stream_socket_accept($oyente, 10);
        if ($cliente !== false) {
            fread($cliente, 4096);
            fwrite($cliente, "HTTP/1.1 $codigoHttp No\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            fclose($cliente);
        }
    }
    $r = json_decode((string)stream_get_contents($tubos[1]), true);
    proc_close($proc);
    $otra = @stream_socket_accept($oyente, 0);
    fclose($oyente);
    borrarArbol($t);
    return is_array($r) && $r[0] === $r[1] && str_contains((string)$r[0][1], '403') && str_contains((string)$r[2][1], '404') && $otra === false
        ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('con open_basedir solo en la carpeta del panel, arrancar no da avisos al no poder leer el config.php del motor (2.9)', function () {
    $panel = dirname(__DIR__) . '/jsonsqldbadmin';
    $codigo = 'putenv("JSONSQLDBADMIN_CONFIG=' . sys_get_temp_dir() . '/no-existe.php"); require ' . var_export("$panel/lib/arranque.php", true) . '; echo date_default_timezone_get();';
    $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d date.timezone=UTC -d display_errors=1 -d open_basedir='
        . escapeshellarg($panel . PATH_SEPARATOR . sys_get_temp_dir()) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    return $r === 'UTC' ?: $r;
});
chk('los intentos de entrar a la vez ni se pierden ni pasan del límite (2.9: antes pasaban todos los que miraban a la vez)', function () {
    // Ocho procesos con 25 intentos cada uno a la vez: sin bloqueo, dos que
    // leen el mismo número guardan el mismo número y se pierde uno; mirando
    // sin bloquear, varios pasan con el último hueco
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_intentos_' . getmypid();
    @mkdir($dir, 0775, true);
    $inicio = microtime(true) + 0.5;
    $procs = [];
    $tubos = [];
    for ($p = 0; $p < 8; $p++) {
        $lib = dirname(__DIR__) . '/jsonsqldbadmin';
        $prog = 'define("ADMIN_DATA_PATH", ' . var_export($dir, true) . '); require ' . var_export("$lib/config.dist.php", true) . ';'
              . 'foreach (["util", "Idioma", "Store", "Auth"] as $c) { require ' . var_export("$lib/lib/", true) . ' . $c . ".php"; }'
              . 'time_sleep_until(' . $inicio . '); $f = Closure::bind(static fn(string $ip) => Auth::apuntarIntento($ip, "x"), null, Auth::class);'
              . '$pasan = 0; for ($i = 0; $i < 25; $i++) { $pasan += $f("9.9.9.9") === 0 ? 1 : 0; } echo $pasan;';
        $procs[] = proc_open([PHP_BINARY, '-r', $prog], [1 => ['pipe', 'w']], $t);
        $tubos[] = $t[1];
    }
    $pasan = 0;
    foreach ($procs as $i => $pr) { $pasan += (int)stream_get_contents($tubos[$i]); fclose($tubos[$i]); proc_close($pr); }
    $intentos = json_decode((string)@file_get_contents("$dir/intentos.json"), true);
    array_map('unlink', (array)glob("$dir/*"));
    @rmdir($dir);
    $n = (int)($intentos['9.9.9.9']['fallos'] ?? -1);
    return $n === 5 && $pasan === 5 ?: "pasaron $pasan y se contaron $n, con el límite de serie, 5";
});
chk('los intentos fallidos caducan pasado el tiempo del bloqueo sin fallar más; los recientes, también los de la 2.8, siguen contando (2.9: no caducaban)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_intentos3_' . getmypid();
    @mkdir($dir, 0775, true);
    file_put_contents("$dir/intentos.json", json_encode([
        '8.8.8.8' => ['fallos' => 4, 'ultimo' => time() - 16 * 60, 'usuarios' => ['x' => 4]],     // de hace 16 minutos
        '6.6.6.6' => ['fallos' => 4, 'ultimo' => time() - 60],                                     // de la 2.8, de hace un minuto
    ]));
    $lib = dirname(__DIR__) . '/jsonsqldbadmin';
    $prog = 'define("ADMIN_DATA_PATH", ' . var_export($dir, true) . '); require ' . var_export("$lib/config.dist.php", true) . ';'
          . 'foreach (["util", "Idioma", "Store", "Auth"] as $c) { require ' . var_export("$lib/lib/", true) . ' . $c . ".php"; }'
          . '$f = Closure::bind(static fn(string $ip) => Auth::apuntarIntento($ip, "x"), null, Auth::class);'
          . 'echo json_encode([$f("8.8.8.8"), $f("6.6.6.6"), $f("6.6.6.6") > 0]);';
    $r = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($prog) . ' 2>&1');
    $intentos = json_decode((string)@file_get_contents("$dir/intentos.json"), true);
    array_map('unlink', (array)glob("$dir/*"));
    @rmdir($dir);
    return $r === '[0,0,true]' && ($intentos['8.8.8.8']['fallos'] ?? null) === 1 ?: $r . ' ' . json_encode($intentos);
});
chk('intentos.json guarda como mucho 1.000 IPs: con 1.500 distintas fallando, las bloqueadas y las más recientes (2.10: crecía sin tope)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_intentos4_' . getmypid();
    @mkdir($dir, 0775, true);
    $antes = ['7.7.7.7' => ['fallos' => 5, 'ultimo' => time() - 600, 'usuarios' => ['x' => 5]]];     // bloqueada, de hace 10 minutos
    for ($i = 0; $i < 1500; $i++) {
        $antes['10.0.' . intdiv($i, 250) . '.' . ($i % 250)] = ['fallos' => 1, 'ultimo' => time() - 1500 + $i, 'usuarios' => ['x' => 1]];
    }
    file_put_contents("$dir/intentos.json", json_encode($antes));
    $lib = dirname(__DIR__) . '/jsonsqldbadmin';
    $prog = 'define("ADMIN_DATA_PATH", ' . var_export($dir, true) . '); require ' . var_export("$lib/config.dist.php", true) . ';'
          . 'foreach (["util", "Idioma", "Store", "Auth"] as $c) { require ' . var_export("$lib/lib/", true) . ' . $c . ".php"; }'
          . '$f = Closure::bind(static fn(string $ip) => Auth::apuntarIntento($ip, "x"), null, Auth::class);'
          . 'echo json_encode([$f("1.2.3.4"), $f("7.7.7.7") > 0]);';
    $r = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($prog) . ' 2>&1');
    $intentos = json_decode((string)@file_get_contents("$dir/intentos.json"), true);
    array_map('unlink', (array)glob("$dir/*"));
    @rmdir($dir);
    return $r === '[0,true]' && count($intentos) === 1000 && isset($intentos['7.7.7.7'], $intentos['1.2.3.4'], $intentos['10.0.5.249'])
        && !isset($intentos['10.0.0.0']) ?: $r . ' ' . count((array)$intentos);
});
chk('entrar con una cuenta no quita los intentos fallidos de otra desde la misma IP (2.9: los quitaba todos)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_intentos2_' . getmypid();
    @mkdir($dir, 0775, true);
    $r = conPanel('session_save_path(' . var_export($dir, true) . '); session_start();'
        . 'Auth::crear("jefe", "clave-del-jefe-1", "admin"); Auth::crear("mira", "clave-de-mira-1", "lectura");'
        . '$p = static function (string $u, string $c) { try { Auth::entrar($u, $c, "7.7.7.7"); return "dentro"; } catch (Throwable $e) { return $e->getMessage(); } };'
        . '$out = [];'
        . 'for ($i = 0; $i < 4; $i++) { $out[] = $p("jefe", "mala$i"); }'
        . '$out[] = $p("mira", "clave-de-mira-1");'
        . '$out[] = $p("jefe", "mala5");'
        . '$out[] = $p("jefe", "clave-del-jefe-1");'
        . '$out[] = t("Demasiados intentos fallidos. Prueba dentro de {espera} minuto(s).", ["espera" => 15]);'
        . 'echo json_encode($out, JSON_UNESCAPED_UNICODE);', $dir);
    array_map('unlink', (array)glob("$dir/*"));
    @rmdir($dir);
    $out = json_decode($r, true);
    // Cuatro fallos de jefe, entra mira, el quinto de jefe llega al límite y
    // el siguiente, aunque sea la buena, ya no se comprueba
    return is_array($out) && $out[4] === 'dentro' && $out[5] === $out[0] && $out[6] === $out[7] ?: $r;
});
chk('un nombre que no puede ser de ningún usuario, aunque ocupe MB, no agranda intentos.json, cuenta para el bloqueo de la IP y se contesta como una contraseña equivocada (2.9: se guardaba entero)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_intentos4_' . getmypid();
    @mkdir($dir, 0775, true);
    $r = conPanel('session_save_path(' . var_export($dir, true) . '); session_start(); Auth::crear("jefe", "clave-del-jefe-1", "admin");'
        . '$p = static function (string $u, string $c) { try { Auth::entrar($u, $c, "5.5.5.5"); return "dentro"; } catch (Throwable $e) { return $e->getMessage(); } };'
        . '$out = [$p("jefe", "mala")];'
        . 'foreach (["ab", "jefe con espacio", str_repeat("A", 2097152) . "1", str_repeat("B", 2097152)] as $u) { $out[] = $p($u, "x"); }'
        . 'clearstatcache(); $out[] = filesize(' . var_export("$dir/intentos.json", true) . ');'
        . '$out[] = $p("jefe", "clave-del-jefe-1");'
        . '$out[] = t("Demasiados intentos fallidos. Prueba dentro de {espera} minuto(s).", ["espera" => ADMIN_LOGIN_BLOQUEO_MIN]);'
        . 'echo json_encode($out, JSON_UNESCAPED_UNICODE);', $dir);
    array_map('unlink', (array)glob("$dir/*"));
    @rmdir($dir);
    $out = json_decode($r, true);
    // Los cuatro nombres imposibles, la misma respuesta que la contraseña
    // equivocada; el fichero, unos cientos de bytes; y con esos cinco fallos
    // la IP queda bloqueada: la contraseña buena ya no se comprueba
    return is_array($out) && count(array_unique(array_slice($out, 0, 5))) === 1 && $out[5] < 1024 && $out[6] === $out[7]
        ?: substr($r, 0, 300);
});
chk('el de lectura entra', function () {
    enviar('p=salir', [], true, 'p=bases');
    return str_contains(peticion('', ['csrf' => csrfActual(''), 'usuario' => 'consulta', 'clave' => 'otra-clave-larga-2']),
                        'Bases de datos');
});
chk('el de lectura no ve el estado de la carpeta de datos en la portada: arreglarlo es cosa de un administrador (2.10)', fn() =>
    !str_contains(pedir('p=bases'), 'Carpeta de datos') ?: 'lo ve');
chk('el de lectura no ve los botones de administración', fn() =>
    !str_contains(pedir('p=bases'), 'Nueva base de datos'));
chk('el de lectura no puede crear vistas', fn() =>
    !str_contains(pedir('p=vistas&db=tienda'), 'value="crear_vista"'));
chk('el de lectura puede comprobar la integridad pero no corregirla', function () {
    $html = pedir('p=integridad&db=tienda');
    return str_contains($html, 'Integridad') && !str_contains($html, 'name="corregir"');
});
chk('el de lectura no puede crear una base', fn() =>
    str_contains(enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'prohibida'],
                        true, 'p=sql&db=tienda'), 'permiso de administrador'));
chk('el de lectura no puede lanzar un DELETE', fn() =>
    str_contains(enviar('p=sql&db=tienda', ['sql' => 'DELETE FROM clientes']), 'solo se pueden lanzar'));
chk('el de lectura no puede colar una escritura detrás de un SELECT en la consola (2.9)', function () {
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT 1; DELETE FROM clientes"]);
    $n = enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM clientes']);
    return str_contains($r, 'solo se pueden lanzar consultas') && !str_contains($n, '<td>0</td>') ?: substr(strip_tags($r), 0, 200);
});
chk('el de lectura puede lanzar WITH, EXPLAIN, CHECK y DESCRIBE, que el motor le deja (2.10)', function () {
    foreach (['WITH c AS (SELECT 1 AS x) SELECT x FROM c', 'EXPLAIN SELECT * FROM clientes', 'CHECK TABLES', 'DESCRIBE clientes'] as $q) {
        if (str_contains(enviar('p=sql&db=tienda', ['sql' => $q]), 'solo se pueden lanzar')) {
            return "rechazada: $q";
        }
    }
    return true;
});
chk('el de lectura sí puede lanzar un SELECT', fn() =>
    str_contains(enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM clientes']), 'fila(s)'));
chk('el de lectura no puede exportar ni volcar, ni ve los botones (2.9)', function () {
    $tabla = enviar('p=datos&db=tienda&tabla=clientes', ['accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda', 'tabla' => 'clientes'], true, 'p=sql&db=tienda');
    $base  = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'tienda'], true, 'p=sql&db=tienda');
    $zip   = enviar('p=bases', ['accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => 'tienda'], true, 'p=sql&db=tienda');
    $pantallas = pedir('p=bases') . pedir('p=datos&db=tienda&tabla=clientes')
        . enviar('p=sql&db=tienda', ['sql' => 'SELECT * FROM clientes']);
    foreach (['tabla' => $tabla, 'base' => $base, 'zip' => $zip] as $k => $r) {
        if (!str_contains($r, 'permiso de administrador') || str_starts_with($r, "\xEF\xBB\xBF") || str_contains($r, 'CREATE TABLE')) {
            return "$k: " . substr(strip_tags($r), 0, 150);
        }
    }
    return !str_contains($pantallas, 'value="exportar"') && !str_contains($pantallas, 'value="exportar_base"') ?: 'se ven botones de exportar';
});
chk('el de lectura no puede lanzar ninguna acción de administrador, y una acción desconocida o en mayúsculas no se ejecuta (2.10: una función por acción)', function () {
    preg_match_all('/^function accion_([a-z_]+)\(/m', (string)file_get_contents(__DIR__ . '/../jsonsqldbadmin/lib/acciones.php'), $m);
    $propias = ['cambiar_clave', 'ejecutar_copias', 'subir_trozo'];      // las que lo miran ellas mismas
    if (count($m[1]) < 40) {
        return 'no se encuentran las acciones';
    }
    foreach (array_diff($m[1], $propias) as $accion) {
        $r = mensajeDe(enviar('p=bases', ['accion' => $accion, 'db' => 'tienda', 'tabla' => 'clientes', 'nombre' => 'tienda'], true, 'p=sql&db=tienda'));
        if (!str_contains($r, 'permiso de administrador')) {
            return "$accion: $r";
        }
    }
    foreach (['no_existe', 'CREAR_BASE', 'Borrar_Base'] as $accion) {
        $r = mensajeDe(enviar('p=bases', ['accion' => $accion, 'nombre' => 'prohibida'], true, 'p=sql&db=tienda'));
        if (!str_contains($r, 'Acción desconocida')) {
            return "'$accion': $r";
        }
    }
    return true;
});

echo "\n== Auditoría ==\n";
chk('la auditoría recoge quién hizo qué', function () {
    enviar('p=salir', [], true, 'p=bases');
    peticion('', ['csrf' => csrfActual(''), 'usuario' => 'jefe', 'clave' => 'clave-muy-larga-1']);
    $html = pedir('p=auditoria');
    return str_contains($html, 'crear_tabla') && str_contains($html, 'jefe')
        && str_contains($html, 'anadir_fk');
});
chk('el acceso fallido queda registrado', fn() =>
    str_contains(pedir('p=auditoria'), 'acceso_fallido'));
chk('un acceso sin token no se apunta, y el nombre de un fallido va recortado (2.9: cualquiera llenaba el disco)', function () {
    enviar('p=salir', [], true, 'p=bases');
    peticion('', ['usuario' => 'intruso-sin-token', 'clave' => 'x']);
    peticion('', ['csrf' => csrfActual(''), 'usuario' => 'largo' . str_repeat('y', 5000) . 'ñ', 'clave' => 'x']);
    peticion('', ['csrf' => csrfActual(''), 'usuario' => 'jefe', 'clave' => 'clave-muy-larga-1']);
    $html = pedir('p=auditoria');
    return !str_contains($html, 'intruso-sin-token') && str_contains($html, 'largo' . str_repeat('y', 59))
        && !str_contains($html, 'largo' . str_repeat('y', 60)) ?: 'sin token apuntado, o el nombre entero';
});
chk('la auditoría de un día enorme se ve de 500 en 500 leyendo desde el final, y los días viejos se borran con el primer evento de cada día (2.10)', function () {
    // Se leía el día entero (aquí 60.000 eventos, 9 MB) en memoria, y la purga
    // solo pasaba en uno de cada cien eventos, al azar
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_auditoria_' . getmypid();
    @mkdir($dir, 0775, true);
    $hoy = date('Y-m-d');
    $f = fopen("$dir/auditoria-$hoy.json", 'wb');
    for ($i = 1; $i <= 60000; $i++) {
        fwrite($f, json_encode(['ts' => "$hoy 10:00:00", 'usuario' => $i % 1000 === 0 ? 'raro' : 'jefe', 'ip' => '192.0.2.1', 'base' => 'b',
                                'accion' => 'consulta', 'detalle' => "evento $i " . str_repeat('x', 100)]) . "\n");
    }
    fclose($f);
    file_put_contents("$dir/auditoria-2000-01-01.json", "{}\n");
    $codigo = 'define("ADMIN_DATA_PATH", ' . var_export($dir, true) . '); define("ADMIN_AUDIT_DIAS", 30);'
        . 'require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/config.dist.php', true) . ';'
        . 'foreach (["util", "Idioma", "Store", "Auth", "Audit"] as $c) { require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/', true) . ' . "$c.php"; }'
        . 'ini_set("memory_limit", "8M");'
        . '[$a, $masA] = Audit::dia(' . var_export($hoy, true) . ', 500);'
        . '[$b, $masB] = Audit::dia(' . var_export($hoy, true) . ', 500, "", 59800);'
        . '[$c, $masC] = Audit::dia(' . var_export($hoy, true) . ', 500, "raro");'
        . 'Audit::registrar("prueba");'
        . 'unlink(' . var_export("$dir/auditoria-$hoy.json", true) . '); Audit::registrar("primero_del_dia");'
        . 'echo json_encode([count($a), $a[0]["detalle"] ?? "", $masA, count($b), $b[199]["detalle"] ?? "", $masB, count($c), $masC,'
        . ' is_file(' . var_export("$dir/auditoria-2000-01-01.json", true) . '), memory_get_peak_usage() < 6000000]);';
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1');
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
    $r = json_decode($salida, true);
    return is_array($r) && $r[0] === 500 && str_starts_with($r[1], 'evento 60000 ') && $r[2] === true && $r[3] === 200
        && str_starts_with($r[4], 'evento 1 ') && $r[5] === false && $r[6] === 60 && $r[7] === false && $r[8] === false && $r[9] === true
        ?: substr($salida, 0, 300);
});

echo "\n== Borrado ==\n";
chk('borrar la tabla', function () {
    enviar('p=tablas&db=tienda', ['accion' => 'borrar_tabla', 'db' => 'tienda', 'tabla' => 'temporal']);
    return !str_contains(pedir('p=tablas&db=tienda'), 'temporal');
});
chk('borrar la base exige escribir su nombre', fn() =>
    str_contains(enviar('p=bases', ['accion' => 'borrar_base', 'nombre' => 'almacen',
                                    'confirmacion' => 'otra-cosa']), 'nombre exacto'));
chk('borrar la base', function () {
    enviar('p=bases', ['accion' => 'borrar_base', 'nombre' => 'almacen', 'confirmacion' => 'almacen']);
    return !str_contains(pedir('p=bases'), 'almacen');
});

echo "\n== Certificado del servidor de la API ==\n";
chk('ADMIN_SSL_CA con una ruta que no existe se explica', function () use ($raizDatos) {
    // Config aparte: solo para esta comprobación
    $conf = $raizDatos . '/_ssl.php';
    file_put_contents($conf, "<?php\n"
        . "define('ADMIN_SSL_CA', " . var_export($raizDatos . '/no-existe.crt', true) . ");\n"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/config.php', true) . ";\n");
    $salida = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "define('ADMIN_API_URL', 'https://127.0.0.1:1/api/jsonsqldb_api.php');"
        . "require " . var_export($conf, true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/Api.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/Idioma.php', true) . ";"
        . "Idioma::elegir('es');"
        . "try { Api::sql('', 'SHOW DATABASES'); echo 'sin error'; }"
        . "catch (Throwable \$e) { echo \$e->getMessage(); }"
    ) . ' 2>&1');
    return str_contains((string)$salida, 'ADMIN_SSL_CA');
});
chk('en HTTP el certificado se ignora', function () use ($raizDatos, $url) {
    $conf = $raizDatos . '/_ssl.php';
    $salida = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "define('ADMIN_API_URL', '$url/api/jsonsqldb_api.php');"
        . "require " . var_export($conf, true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/Api.php', true) . ";"
        . "try { Api::sql('', 'SHOW DATABASES'); echo 'conectado'; }"
        . "catch (Throwable \$e) { echo \$e->getMessage(); }"
    ) . ' 2>&1');
    return str_contains((string)$salida, 'conectado');
});

chk('con la conexión directa, una lectura del panel no carga Logger, que no va a escribir nada; una escritura sí, y queda con el panel como origen (2.10: lo cargaba en cada consulta para decirle quién era)', function () {
    $t = sys_get_temp_dir() . '/jsonsqldb_test_reg_' . getmypid();
    borrarArbol($t);
    @mkdir("$t/datos", 0775, true);
    $correr = static function (string $base, string $sql) use ($t): string {
        $codigo = 'putenv("JSONSQLDBADMIN_CONFIG=/no/existe"); define("ADMIN_DATA_PATH", ' . var_export("$t/admin", true) . ');'
            . 'define("ADMIN_CONEXION", "directa"); define("ADMIN_MOTOR_RUTA", ' . var_export(dirname(__DIR__), true) . ');'
            . 'define("JSONSQLDB_DATA_PATH", ' . var_export("$t/datos", true) . '); define("JSONSQLDB_LOG_PATH", ' . var_export("$t/logs", true) . ');'
            . 'require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/arranque.php', true) . ';'
            . 'Api::sql(' . var_export($base, true) . ', ' . var_export($sql, true) . ');'
            . 'echo class_exists(' . var_export('JsonSQLDB\\Logger', true) . ', false) ? "con Logger" : "sin Logger";';
        $proc = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos);
        $salida = trim((string)stream_get_contents($tubos[1]));
        fclose($tubos[1]);
        proc_close($proc);
        return $salida;
    };
    $correr('', 'CREATE DATABASE b');
    $correr('b', 'CREATE TABLE x (a INTEGER)');
    $lee = $correr('b', 'SELECT COUNT(*) FROM x');
    $esc = $correr('b', 'INSERT INTO x VALUES (7)');
    $origen = null;
    foreach ((array)glob("$t/logs/consultas-*.json") as $f) {
        foreach (array_filter(explode("\n", (string)file_get_contents((string)$f))) as $l) {
            $e = json_decode($l, true);
            if (($e['op'] ?? '') === 'INSERT') {
                $origen = $e['origen'] ?? null;
            }
        }
    }
    borrarArbol($t);
    return $lee === 'sin Logger' && $esc === 'con Logger' && $origen === 'jsonSQLDBadmin' ?: json_encode([$lee, $esc, $origen]);
});

echo "\n== Sin restos ==\n";
chk('el panel no deja ficheros temporales', function () use ($raizDatos) {
    $restos = array_merge((array)glob("$raizDatos/admin/*.tmp"), (array)glob("$raizDatos/tienda/*.tmp"));
    return array_filter($restos) === [];
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
