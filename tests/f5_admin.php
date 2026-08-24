<?php
declare(strict_types=1);

/**
 * Prueba de jsonSQLDBadmin. Ejecutar: php tests/f5_admin.php
 *
 * Levanta el servidor propio de PHP sobre la raíz del proyecto y navega el
 * panel como lo haría un usuario: cookies de sesión, tokens CSRF y
 * formularios reales. El panel habla con la API, y la API con el motor.
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
    . "define('ADMIN_RUTA_DATOS_MOTOR', " . var_export($raizDatos, true) . ");\n");

// --- Servidores ---
// Dos procesos: uno sirve el panel y otro la API. El servidor propio de PHP
// atiende una petición cada vez, y el panel llama a la API dentro de su propia
// petición: con un solo proceso se quedaría esperándose a sí mismo.
$servidores = [];
foreach ([$puertoPanel, $puertoApi] as $p) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file=' . escapeshellarg($prepend)
         . " -S 127.0.0.1:$p -t " . escapeshellarg($raizProyecto);
    $proc = proc_open($cmd, [1 => ['file', $raizDatos . '/server.log', 'a'],
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
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $r = curl_exec($ch);
    if ($r === false) { throw new RuntimeException('cURL: ' . curl_error($ch)); }
    curl_close($ch);
    return (string)$r;
}

// ----------------------------------------------------------------------

echo "\n== Instalación y acceso ==\n";
chk('sin usuarios pide crear el administrador', fn() =>
    str_contains(pedir(), 'Crea el administrador'));
chk('la contraseña corta se rechaza', fn() =>
    str_contains(peticion('', ['usuario' => 'jefe', 'clave' => 'corta']), 'al menos 10 caracteres'));
chk('se crea el administrador', fn() =>
    str_contains(peticion('', ['usuario' => 'jefe', 'clave' => 'clave-muy-larga-1']), 'Ya puedes entrar'));
chk('ahora pide usuario y contraseña', fn() =>
    str_contains(pedir(), 'name="clave"') && !str_contains(pedir(), 'Crea el administrador'));
chk('contraseña incorrecta', fn() =>
    str_contains(peticion('', ['usuario' => 'jefe', 'clave' => 'no-es-esta-clave']), 'incorrectos'));
chk('acceso correcto', fn() =>
    str_contains(peticion('', ['usuario' => 'jefe', 'clave' => 'clave-muy-larga-1']), 'Bases de datos'));
chk('sin token CSRF la acción se rechaza', fn() =>
    str_contains(enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'sinCsrf'], false), 'Formulario caducado'));

echo "\n== Bases de datos ==\n";
chk('crear una base', fn() =>
    str_contains(enviar('p=bases', ['accion' => 'crear_base', 'nombre' => 'tienda']), 'tienda&#039; creada'));
chk('la base aparece en el listado', fn() =>
    str_contains(pedir('p=bases'), '>tienda</a>') || str_contains(pedir('p=bases'), 'tienda'));
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
        'nombre' => 'ciudad', 'tipo' => 'TEXT', 'longitud' => '60', 'defecto' => 'Torrevieja',
    ]);
    return str_contains(pedir('p=estructura&db=tienda&tabla=clientes'), 'ciudad');
});
chk('renombrar columna', function () {
    enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'clientes',
        'columna' => 'ciudad', 'nombre' => 'poblacion', 'tipo' => 'TEXT', 'longitud' => '60',
        'defecto' => 'Torrevieja',
    ]);
    $html = pedir('p=estructura&db=tienda&tabla=clientes');
    return str_contains($html, 'poblacion') && !str_contains($html, '<strong>ciudad</strong>');
});
chk('cambiar el tipo de una columna', function () {
    enviar('p=estructura&db=tienda&tabla=clientes', [
        'accion' => 'editar_columna', 'db' => 'tienda', 'tabla' => 'clientes',
        'columna' => 'poblacion', 'nombre' => 'poblacion', 'tipo' => 'TEXT', 'longitud' => '80',
        'notnull' => '1', 'defecto' => 'Torrevieja',
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
        'valor' => ['id' => '', 'cod' => 'A1', 'nombre' => "O'Donnell", 'saldo' => '10.55'],
        'tipo'  => ['id' => 'INTEGER', 'cod' => 'TEXT', 'nombre' => 'TEXT', 'saldo' => 'DECIMAL'],
        'auto'  => ['id' => '1'],
    ]);
    return str_contains($html, 'Fila insertada') && str_contains($html, 'O&#039;Donnell');
});
chk('el autoincremento ha puesto el id', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes'), '<td>1</td>'));
chk('una columna numérica vacía toma su valor por defecto', function () {
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => ['id' => '', 'cod' => 'B2', 'nombre' => 'Sin saldo', 'saldo' => ''],
        'tipo'  => ['id' => 'INTEGER', 'cod' => 'TEXT', 'nombre' => 'TEXT', 'saldo' => 'DECIMAL'],
        'auto'  => ['id' => '1'],
    ]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT saldo FROM clientes WHERE cod = 'B2'"]);
    return str_contains($r, '<td>0</td>');
});
chk('un texto vacío sí se guarda como cadena vacía', function () {
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => ['id' => '', 'cod' => 'C3', 'nombre' => ''],
        'tipo'  => ['id' => 'INTEGER', 'cod' => 'TEXT', 'nombre' => 'TEXT'],
        'auto'  => ['id' => '1'],
    ]);
    $r = enviar('p=sql&db=tienda', ['sql' => "SELECT COUNT(*) AS n FROM clientes WHERE nombre = ''"]);
    return str_contains($r, '<td>1</td>');
});
chk('el decimal se guarda con punto y dos decimales', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes'), '10.55'));
chk('la clave única se respeta al insertar', function () {
    $html = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => ['cod' => 'A1', 'nombre' => 'Repetido'], 'tipo' => ['cod' => 'TEXT', 'nombre' => 'TEXT'],
    ]);
    return str_contains($html, 'CONSTRAINT') || str_contains($html, 'repetid')
        || str_contains($html, 'Error en la consulta');
});
chk('editar fila', function () {
    $html = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'actualizar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'pk' => ['id' => '1'], 'valor' => ['cod' => 'A1', 'nombre' => 'Ana', 'saldo' => '20.10'],
    ]);
    return str_contains($html, 'Fila guardada') && str_contains($html, 'Ana');
});
chk('un valor con SQL dentro no altera la consulta', function () {
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => ['cod' => 'X9', 'nombre' => "x'); DROP TABLE clientes; --"],
    ]);
    $html = pedir('p=datos&db=tienda&tabla=clientes');
    return str_contains($html, 'DROP TABLE clientes') && str_contains($html, 'Ana');
});
chk('el trigger se dispara al insertar un pedido', function () {
    enviar('p=datos&db=tienda&tabla=pedidos', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'pedidos',
        'valor' => ['cliente_id' => '1', 'ref' => 'R1', 'total' => '5.00'],
    ]);
    return str_contains(pedir('p=datos&db=tienda&tabla=clientes'), '25.1');
});
chk('la clave foránea rechaza un cliente inexistente', function () {
    $html = enviar('p=datos&db=tienda&tabla=pedidos', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'pedidos',
        'valor' => ['cliente_id' => '999', 'ref' => 'R9'],
    ]);
    return str_contains($html, 'no existe') || str_contains($html, 'Error en la consulta');
});
chk('borrar fila arrastra los hijos por CASCADE', function () {
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'borrar_fila', 'db' => 'tienda', 'tabla' => 'clientes', 'pk' => ['id' => '1'],
    ]);
    return !str_contains(pedir('p=datos&db=tienda&tabla=pedidos'), 'R1');
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
    return !str_contains($html, 'name="nulo[cod]"')
        && str_contains($html, 'name="nn[cod]"')
        && str_contains($html, 'obligatorio')
        && str_contains($html, 'sin nulos');
});
chk('marcar NULL en una obligatoria se rechaza', function () {
    $html = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => ['cod' => '', 'nombre' => 'Sin cod'],
        'nulo'  => ['cod' => '1'], 'nn' => ['cod' => '1'],
        'tipo'  => ['cod' => 'TEXT', 'nombre' => 'TEXT'],
    ]);
    return str_contains($html, 'no admite nulos');
});
chk('una columna que sí admite nulos mantiene la casilla', fn() =>
    str_contains(pedir('p=datos&db=tienda&tabla=clientes'), 'name="nulo[nombre]"'));
chk('la columna automática no ofrece la casilla NULL', function () {
    $html = pedir('p=datos&db=tienda&tabla=clientes');
    return !str_contains($html, 'name="nulo[id]"') && str_contains($html, 'lo pone la base');
});
chk('marcar NULL en la automática no cuela', function () {
    // Aunque se envíe a mano, la columna automática se ignora
    enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'insertar_fila', 'db' => 'tienda', 'tabla' => 'clientes',
        'valor' => ['id' => '', 'cod' => 'F6', 'nombre' => 'Con NULL'],
        'nulo'  => ['id' => '1'], 'auto' => ['id' => '1'],
        'tipo'  => ['id' => 'INTEGER', 'cod' => 'TEXT', 'nombre' => 'TEXT'],
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
        && str_contains($html, '1 fila(s) filtradas');
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
chk('la exportación respeta el filtro', function () {
    $csv = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda', 'tabla' => 'clientes',
        'q' => 'Sin saldo',
    ]);
    return count(explode("\n", trim($csv))) === 2 && str_contains($csv, 'Sin saldo');
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
chk('el CSV entrecomilla lo que lleva el separador', function () {
    enviar('p=sql&db=tienda', ['sql' => "INSERT INTO clientes (cod, nombre) VALUES ('D4', 'Uno; dos')"]);
    $csv = enviar('p=datos&db=tienda&tabla=clientes', [
        'accion' => 'exportar', 'formato' => 'csv', 'db' => 'tienda', 'tabla' => 'clientes',
    ]);
    return str_contains($csv, '"Uno; dos"');
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
        && str_contains($sql, 'ALTER TABLE "pedidos" ADD CONSTRAINT "fk_pedidos_cliente"')
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
chk('con la API en otra máquina, el ZIP se desactiva y se explica', function () use ($raizDatos, $puertoApi) {
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
        . "var_export(mismoHostQueLaApi());"
        . "try { rutaDeLaBase('tienda'); echo '|sin aviso'; }"
        . "catch (Throwable \$e) { echo '|', \$e->getMessage(); }"
    ) . ' 2>&1');
    @unlink($conf);

    return str_starts_with($salida, 'false')
        && str_contains($salida, 'misma máquina')
        && str_contains($salida, 'otra-maquina.example') ?: $salida;
});
chk('con el mismo host sí está disponible', function () use ($raizDatos, $puertoApi) {
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "\$_SERVER['HTTP_HOST'] = '127.0.0.1:$puertoApi';"
        . "define('ADMIN_API_URL', 'http://127.0.0.1:$puertoApi/api/jsonsqldb_api.php');"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/config.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/Api.php', true) . ";"
        . "require " . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php', true) . ";"
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
    return in_array('tienda/_database.json', $nombres, true)
        && in_array('tienda/clientes.json', $nombres, true)
        && in_array('tienda/clientes.meta.json', $nombres, true) ?: $nombres;
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
    $nuevos = [];
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

echo "\n== Usuarios y permisos ==\n";
chk('crear un usuario de solo lectura', fn() =>
    str_contains(enviar('p=usuarios', [
        'accion' => 'crear_usuario', 'usuario' => 'consulta',
        'clave' => 'otra-clave-larga-2', 'rol' => 'lectura',
    ]), 'consulta&#039; creado'));
chk('el de lectura entra', function () {
    pedir('p=salir');
    return str_contains(peticion('', ['usuario' => 'consulta', 'clave' => 'otra-clave-larga-2']),
                        'Bases de datos');
});
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
chk('el de lectura sí puede lanzar un SELECT', fn() =>
    str_contains(enviar('p=sql&db=tienda', ['sql' => 'SELECT COUNT(*) AS n FROM clientes']), 'fila(s)'));

echo "\n== Auditoría ==\n";
chk('la auditoría recoge quién hizo qué', function () {
    pedir('p=salir');
    peticion('', ['usuario' => 'jefe', 'clave' => 'clave-muy-larga-1']);
    $html = pedir('p=auditoria');
    return str_contains($html, 'crear_tabla') && str_contains($html, 'jefe')
        && str_contains($html, 'anadir_fk');
});
chk('el acceso fallido queda registrado', fn() =>
    str_contains(pedir('p=auditoria'), 'acceso_fallido'));

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

echo "\n== Sin restos ==\n";
chk('el panel no deja ficheros temporales', function () use ($raizDatos) {
    $restos = array_merge((array)glob("$raizDatos/admin/*.tmp"), (array)glob("$raizDatos/tienda/*.tmp"));
    return array_filter($restos) === [];
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
