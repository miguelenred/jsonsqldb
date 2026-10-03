<?php
declare(strict_types=1);

/**
 * Exportar e importar una base mayor que la memoria de PHP, y las copias
 * programadas. Ejecutar: php tests/f20_memoria.php [--directa]
 *
 * Con --directa, el panel usa la conexión directa: el motor trabaja dentro
 * del mismo proceso y con la misma memoria.
 *
 * El panel y la API corren con memory_limit = 32M y la base ocupa varias
 * veces eso como array de PHP: si algo la cargara entera, la petición
 * moriría y faltarían filas en el fichero.
 *
 * https://miguelenred.es/jsonsqldb
 */
$raizProyecto = dirname(__DIR__);
$raizDatos    = sys_get_temp_dir() . '/jsonsqldb_test_memoria';
const FILAS   = 120000;
const MEMORIA = '32M';
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
        is_dir($r) ? borrarArbol($r) : @unlink($r);
    }
    @rmdir($dir);
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

function puertosLibres(): array {
    for ($p = 9531; $p < 9900; $p += 2) {
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

borrarArbol($raizDatos);
@mkdir($raizDatos . '/admin', 0775, true);
[$puertoPanel, $puertoApi] = puertosLibres();
$url     = "http://127.0.0.1:$puertoPanel/jsonsqldbadmin/index.php";
$cookies = $raizDatos . '/cookies.txt';

$prepend = $raizDatos . '/_prepend.php';
file_put_contents($prepend, "<?php\n"
    . "define('JSONSQLDB_DATA_PATH', " . var_export($raizDatos, true) . ");\n"
    . "define('JSONSQLDB_LOG_PATH', "  . var_export($raizDatos . '/logs', true) . ");\n"
    . "define('API_ESTADO_PATH', "     . var_export($raizDatos . '/api', true) . ");\n"
    . "define('ADMIN_DATA_PATH', "     . var_export($raizDatos . '/admin', true) . ");\n"
    . "define('ADMIN_API_URL', "       . var_export("http://127.0.0.1:$puertoApi/api/jsonsqldb_api.php", true) . ");\n"
    . "define('ADMIN_SSL_CA', '');\n"
    . "define('ADMIN_TIMEOUT', 300);\n"
    . "define('EXIGIR_HTTPS', false);\n"
    . "define('ADMIN_EXIGIR_HTTPS', false);\n"
    . "define('RATE_LIMIT_ACTIVO', false);\n"
    . "define('ANTI_REPLAY_ACTIVO', false);\n"
    . "define('DEVOLVER_ERRORES', true);\n"
    . "define('ADMIN_RUTA_DATOS_MOTOR', " . var_export($raizDatos, true) . ");\n"
    . (in_array('--directa', $argv, true)
        ? "define('ADMIN_CONEXION', 'directa');\ndefine('ADMIN_MOTOR_RUTA', " . var_export($raizProyecto, true) . ");\n" : ''));

$servidores = [];
foreach ([$puertoPanel, $puertoApi] as $p) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . MEMORIA . ' -d max_execution_time=0'
         . ' -d auto_prepend_file=' . escapeshellarg($prepend) . " -S 127.0.0.1:$p -t " . escapeshellarg($raizProyecto);
    $proc = proc_open($cmd, [1 => ['file', $raizDatos . '/server.log', 'a'], 2 => ['file', $raizDatos . '/server.log', 'a']], $t);
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
foreach ([$puertoPanel, $puertoApi] as $p) {
    for ($i = 0; $i < 60; $i++) {
        usleep(150000);
        if (@fsockopen('127.0.0.1', $p, $e, $s, 0.3)) { break; }
    }
}

/** Petición al panel; con $fichero, la respuesta va a ese fichero en vez de a memoria. */
function peticion(string $query, ?array $post, ?string $fichero = null): string {
    global $url, $cookies;
    $ch = curl_init($url . ($query === '' ? '' : '?' . $query));
    $f  = $fichero === null ? null : fopen($fichero, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        CURLOPT_HTTPHEADER     => ['Accept-Language: es'],
        CURLOPT_TIMEOUT        => 600,
    ] + ($f === null ? [CURLOPT_RETURNTRANSFER => true] : [CURLOPT_FILE => $f]));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array_filter($post, 'is_object') !== [] ? $post : http_build_query($post));
    }
    $r = curl_exec($ch);
    if ($r === false) { throw new RuntimeException('cURL: ' . curl_error($ch)); }
    curl_close($ch);
    if ($f !== null) { fclose($f); }
    return is_string($r) ? $r : '';
}

function csrf(string $query): string {
    if (preg_match('/name="csrf" value="([a-f0-9]{64})"/', peticion($query, null), $m)) {
        return $m[1];
    }
    throw new RuntimeException("No se encontró el token CSRF en '$query'");
}

/** Cuenta las líneas de un fichero que empiezan por $prefijo, sin cargarlo. */
function lineas(string $fichero, string $prefijo): int {
    $n = 0;
    $f = fopen($fichero, 'rb');
    while (($l = fgets($f)) !== false) {
        if (strncmp($l, $prefijo, strlen($prefijo)) === 0) { $n++; }
    }
    fclose($f);
    return $n;
}

/** Filas de una tabla, contadas con el motor. */
function contar(string $base, string $tabla): int {
    global $raizDatos;
    return (int)(new JsonSQLDB\Database($base, $raizDatos))->consultar("SELECT COUNT(*) AS n FROM $tabla")[0]['n'];
}

// ----------------------------------------------------------------------
// Datos: se escriben con el motor, en este proceso, que no tiene el límite
// ----------------------------------------------------------------------
define('JSONSQLDB_CONEXION_DIRECTA', true);
require_once $raizProyecto . '/engine/bootstrap.php';

echo "\n== Preparación ==\n";
chk('base de ' . FILAS . ' filas, que como array de PHP no cabe en ' . MEMORIA, function () use ($raizDatos) {
    JsonSQLDB\Database::crear('grande', $raizDatos);
    $bd = new JsonSQLDB\Database('grande', $raizDatos);
    $bd->consultar('CREATE TABLE padre (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre VARCHAR(40), jefe INTEGER,
                    FOREIGN KEY (jefe) REFERENCES padre (id))');
    $bd->consultar('CREATE TABLE fila (id INTEGER PRIMARY KEY AUTOINCREMENT, texto TEXT, n INTEGER, d DECIMAL(10,2),
                    f DATETIME, padre_id INTEGER, FOREIGN KEY (padre_id) REFERENCES padre (id))');
    $bd->consultar("INSERT INTO padre (nombre, jefe) VALUES ('raíz', NULL), ('hijo', 1)");
    $relleno = str_repeat('áéíóú ñ «texto» ', 6);
    for ($k = 0; $k < FILAS / 2000; $k++) {
        $v = [];
        for ($i = 0; $i < 2000; $i++) {
            $n = $k * 2000 + $i;
            $v[] = "('$relleno $n', $n, " . ($n % 1000) . ".25, '2026-01-01 10:00:00.123', " . ($n % 2 + 1) . ')';
        }
        $bd->consultar('INSERT INTO fila (texto, n, d, f, padre_id) VALUES ' . implode(',', $v));
    }
    $bd->consultar("INSERT INTO fila (texto, n) VALUES ('comilla '' y
salto de línea', -1)");
    return contar('grande', 'fila') === FILAS + 1;
});

echo "\n== Acceso al panel ==\n";
chk('se crea el administrador y se entra', function () use ($raizDatos) {
    $token  = csrf('');                         // la primera visita deja el código de instalación
    $codigo = trim((string)file_get_contents($raizDatos . '/admin/codigo-instalacion.txt'));
    peticion('', ['csrf' => $token, 'codigo' => $codigo, 'usuario' => 'jefe', 'clave' => 'clave-muy-larga-1', 'clave2' => 'clave-muy-larga-1']);
    return str_contains(peticion('', ['csrf' => csrf(''), 'usuario' => 'jefe', 'clave' => 'clave-muy-larga-1']), 'Bases de datos');
});

echo "\n== Exportar con memory_limit = " . MEMORIA . " ==\n";
$volcado = $raizDatos . '/volcado.sql';
chk('el volcado SQL de la base sale entero', function () use ($volcado) {
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'grande'], $volcado);
    $n = lineas($volcado, 'INSERT INTO "fila"');
    return $n === FILAS + 1 && lineas($volcado, '-- ERROR') === 0 ?: "$n INSERT de " . (FILAS + 1);
});
chk('el volcado MySQL de la base sale entero', function () use ($raizDatos) {
    $f = $raizDatos . '/volcado.mysql.sql';
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'exportar_base', 'formato' => 'mysql', 'nombre' => 'grande'], $f);
    $n = lineas($f, 'INSERT INTO `fila`');
    $ok = $n === FILAS + 1 && lineas($f, '-- ERROR') === 0 ?: "$n INSERT de " . (FILAS + 1);
    @unlink($f);
    return $ok;
});
chk('la tabla en CSV sale entera', function () use ($raizDatos) {
    $f = $raizDatos . '/fila.csv';
    peticion('p=datos&db=grande&tabla=fila', ['csrf' => csrf('p=datos&db=grande&tabla=fila'), 'accion' => 'exportar',
        'formato' => 'csv', 'db' => 'grande', 'tabla' => 'fila'], $f);
    $n = lineas($f, '') - 1;                    // menos la cabecera; el texto con salto ocupa dos líneas
    @unlink($f);
    return $n === FILAS + 2 ?: "$n líneas";
});
chk('la tabla filtrada sale entera, y por lotes con LIMIT, sin rehacer la consulta entera en cada lote', function () use ($raizDatos) {
    $f = $raizDatos . '/filtrada.csv';
    peticion('p=datos&db=grande&tabla=fila', ['csrf' => csrf('p=datos&db=grande&tabla=fila'), 'accion' => 'exportar',
        'formato' => 'csv', 'db' => 'grande', 'tabla' => 'fila', 'q' => '«texto»'], $f);
    $n = lineas($f, '') - 1;
    @unlink($f);
    // Una consulta con parámetros (el filtro) también admite el LIMIT detrás:
    // envolverla en una subconsulta la resolvería entera en cada lote
    $log = implode("\n", array_map('file_get_contents', glob($raizDatos . '/logs/*') ?: []));
    return $n === FILAS && !str_contains($log, "_lote") && str_contains($log, "LIKE") ?: "$n líneas, " . (str_contains($log, "_lote") ? "con subconsulta" : "sin subconsulta");
});
chk('el resultado de una consulta del editor en INSERT sale entero, con su orden', function () use ($raizDatos) {
    // Ordenar necesita el resultado entero en la memoria del motor (con la
    // conexión directa, la misma del panel): aquí, la décima parte de la
    // tabla, que cabe; el panel lo saca por lotes
    $f = $raizDatos . '/consulta.sql';
    peticion('p=sql&db=grande', ['csrf' => csrf('p=sql&db=grande'), 'accion' => 'exportar', 'formato' => 'sql', 'db' => 'grande',
        'sql' => 'SELECT id, n FROM fila WHERE n >= 0 AND n < ' . (FILAS / 10) . ' ORDER BY n DESC'], $f);
    $n = lineas($f, 'INSERT INTO');
    $primera = '';
    foreach (new SplFileObject($f) as $l) {
        if (str_starts_with((string)$l, 'INSERT')) { $primera = (string)$l; break; }
    }
    @unlink($f);
    return $n === FILAS / 10 && str_contains($primera, 'VALUES (' . (FILAS / 10) . ', ' . (FILAS / 10 - 1) . ')') ?: "$n · $primera";
});
// Con conexión directa el motor ordena en la memoria del propio panel
if (in_array('--directa', $argv, true)) chk('una consulta ordenada que no cabe en la memoria del motor lo explica, sin dar el fichero por completo', function () {
    $r = peticion('p=sql&db=grande', ['csrf' => csrf('p=sql&db=grande'), 'accion' => 'exportar', 'formato' => 'sql', 'db' => 'grande',
        'sql' => 'SELECT id, n FROM fila ORDER BY n DESC']);
    // O no empieza (el aviso, en el panel) o acaba con el error en la última línea
    return str_contains($r, 'MEMORIA:') && (!str_contains($r, 'INSERT INTO') || str_contains($r, '-- ERROR:'))
        && !str_contains($r, '-- ' . FILAS . ' fila(s)') ?: substr(strip_tags($r), -300);
});
chk('la copia ZIP sale entera', function () use ($raizDatos) {
    $f = $raizDatos . '/grande.zip';
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => 'grande'], $f);
    $zip = new ZipArchive();
    $bien = $zip->open($f) === true && $zip->locateName('grande/fila.meta.json') !== false;
    $zip->close();
    @unlink($f);
    return $bien;
});

echo "\n== Importar con memory_limit = " . MEMORIA . " ==\n";
/** POST multipart (con un trozo de fichero) que devuelve JSON. */
function trozo(string $id, int $desde, ?string $datos): array {
    $campos = ['csrf' => csrf('p=bases'), 'accion' => 'subir_trozo', 'id' => $id, 'desde' => (string)$desde];
    if ($datos !== null) {
        $tmp = tempnam(sys_get_temp_dir(), 'trozo');
        file_put_contents($tmp, $datos);
        $campos['trozo'] = new CURLFile($tmp, 'application/octet-stream', 'trozo');
    }
    $r = json_decode(peticion('p=bases', $campos), true);
    if (isset($tmp)) { @unlink($tmp); }
    return is_array($r) ? $r : ['error' => 'sin JSON'];
}
$id = str_repeat('ab', 16);
chk('el volcado se sube por trozos; uno que no empieza donde va no se escribe, y se sigue desde lo recibido', function () use ($volcado, $id) {
    $f = fopen($volcado, 'rb');
    $desde = 0;
    while (!feof($f)) {
        $datos = (string)fread($f, 1048576);
        if ($datos === '') { break; }
        if ($desde === 1048576) {
            // Un reintento del trozo anterior, ya recibido: se ignora
            $r = trozo($id, 0, 'repetido');
            if (($r['recibido'] ?? null) !== $desde) { return $r; }
        }
        $r = trozo($id, $desde, $datos);
        if (($r['recibido'] ?? null) !== $desde + strlen($datos)) { return $r; }
        $desde += strlen($datos);
    }
    fclose($f);
    return trozo($id, -1, null) === ['recibido' => filesize($volcado)];
});
chk('se importa el volcado subido por trozos, entero, y la subida se borra', function () use ($volcado, $id, $raizDatos) {
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'crear_base', 'nombre' => 'copia']);
    $html = peticion('p=tablas&db=copia', ['csrf' => csrf('p=tablas&db=copia'), 'accion' => 'importar_sql', 'db' => 'copia',
        'formato' => 'auto', 'subida' => $id, 'subida_tamano' => (string)filesize($volcado), 'subida_nombre' => 'volcado.sql']);
    if (!str_contains($html, 'sentencia(s) ejecutadas')) { return strip_tags(substr($html, 0, 300)); }
    return contar('copia', 'fila') === FILAS + 1 && contar('copia', 'padre') === 2
        && !is_file($raizDatos . "/admin/importar/.subidas/$id.part") ?: 'faltan filas o queda la subida';
});
chk('una subida incompleta no se importa', function () use ($id) {
    trozo($id, 0, 'SELECT 1;');
    $html = peticion('p=tablas&db=copia', ['csrf' => csrf('p=tablas&db=copia'), 'accion' => 'importar_sql', 'db' => 'copia',
        'formato' => 'auto', 'subida' => $id, 'subida_tamano' => '999', 'subida_nombre' => 'x.sql']);
    return str_contains($html, 'no ha llegado entero');
});
chk('un fichero dejado en la carpeta de importar se ofrece y se importa sin subirlo', function () use ($volcado, $raizDatos) {
    @mkdir($raizDatos . '/admin/importar', 0775, true);
    rename($volcado, $raizDatos . '/admin/importar/volcado.sql');
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'crear_base', 'nombre' => 'copia2']);
    if (!str_contains(peticion('p=tablas&db=copia2', null), '<option value="volcado.sql">')) { return 'no se ofrece'; }
    peticion('p=tablas&db=copia2', ['csrf' => csrf('p=tablas&db=copia2'), 'accion' => 'importar_sql', 'db' => 'copia2',
        'formato' => 'auto', 'servidor' => 'volcado.sql']);
    return contar('copia2', 'fila') === FILAS + 1 && is_file($raizDatos . '/admin/importar/volcado.sql') ?: 'faltan filas';
});
chk('no se puede salir de la carpeta de importar', function () {
    $html = peticion('p=tablas&db=copia2', ['csrf' => csrf('p=tablas&db=copia2'), 'accion' => 'importar_sql', 'db' => 'copia2',
        'formato' => 'auto', 'servidor' => '../usuarios.json']);
    return str_contains($html, 'no está en la carpeta de importar');
});

echo "\n== Copias programadas ==\n";
define('ADMIN_DATA_PATH', $raizDatos . '/admin');
require_once $raizProyecto . '/jsonsqldbadmin/lib/Store.php';
require_once $raizProyecto . '/jsonsqldbadmin/lib/Copias.php';
chk('cuándo toca una copia diaria, semanal o cada N horas', function () {
    $t = static fn(string $s): int => (int)strtotime($s);
    $diaria  = ['frecuencia' => 'diaria', 'hora' => 3, 'ultima' => '2026-10-02 03:00:05'];
    $semanal = ['frecuencia' => 'semanal', 'dia' => 1, 'hora' => 3, 'ultima' => '2026-09-28 03:10:00'];  // lunes
    $horas   = ['frecuencia' => 'horas', 'horas' => 6, 'ultima' => '2026-10-03 01:00:00'];
    $casos = [
        [Copias::toca($diaria, $t('2026-10-03 02:59:00')), false], [Copias::toca($diaria, $t('2026-10-03 03:00:00')), true],
        [Copias::toca($semanal, $t('2026-10-04 23:00:00')), false], [Copias::toca($semanal, $t('2026-10-05 03:00:00')), true],
        [Copias::toca($horas, $t('2026-10-03 06:59:00')), false], [Copias::toca($horas, $t('2026-10-03 07:00:00')), true],
        [Copias::toca(['ultima' => null] + $diaria, $t('2026-10-03 01:00:00')), true],
    ];
    foreach ($casos as $i => [$r, $esperado]) {
        if ($r !== $esperado) { return "caso $i"; }
    }
    return true;
});
chk('una copia pendiente hace que la página la pida aparte, y esa petición la hace (SQL, la base entera)', function () use ($raizDatos) {
    peticion('p=copias', ['csrf' => csrf('p=copias'), 'accion' => 'programar_copia', 'base' => 'grande', 'frecuencia' => 'horas',
        'horas' => '24', 'formato' => 'sql', 'conservar' => '2']);
    $html = peticion('p=copias', null);
    if (!preg_match('/<body class="app" data-copias="([a-f0-9]{64})"/', $html, $m)) { return 'la página no avisa'; }
    $r = json_decode(peticion('p=bases', ['csrf' => $m[1], 'accion' => 'ejecutar_copias']), true);
    $copias = glob($raizDatos . '/admin/copias/grande/grande-*.sql');
    if (count($r['hechas'] ?? []) !== 1 || count($copias) !== 1) { return $r; }
    return lineas($copias[0], 'INSERT INTO "fila"') === FILAS + 1
        && !str_contains(peticion('p=copias', null), 'data-copias=') ?: 'copia incompleta o sigue pendiente';
});
chk('«hacer ahora» en ZIP, y solo se conservan las que se piden', function () use ($raizDatos) {
    peticion('p=copias', ['csrf' => csrf('p=copias'), 'accion' => 'programar_copia', 'base' => 'grande', 'frecuencia' => 'diaria',
        'hora' => '3', 'formato' => 'zip', 'conservar' => '2']);
    $id = '';
    foreach (json_decode((string)file_get_contents($raizDatos . '/admin/copias.json'), true) as $p) {
        if ($p['formato'] === 'zip') { $id = $p['id']; }
    }
    for ($i = 0; $i < 3; $i++) {
        $html = peticion('p=copias', ['csrf' => csrf('p=copias'), 'accion' => 'copia_ahora', 'id' => $id, 'volver' => 'copias']);
        if (!str_contains($html, '.zip (')) { return strip_tags(substr($html, 0, 200)); }
        sleep(1);                                   // el nombre lleva los segundos
    }
    $zips = glob($raizDatos . '/admin/copias/grande/*.zip');
    return count($zips) === 2 && count(glob($raizDatos . '/admin/copias/grande/*.sql')) === 1 ?: count($zips) . ' zip';
});
chk('las que sobran se borran por la fecha del fichero, no por el nombre (otra zona horaria)', function () use ($raizDatos) {
    // Una copia vieja cuyo nombre lleva una hora «posterior», como si la hubiera
    // hecho un cron con otra zona horaria
    $vieja = $raizDatos . '/admin/copias/grande/grande-29990101-000000.zip';
    copy(glob($raizDatos . '/admin/copias/grande/*.zip')[0], $vieja);
    touch($vieja, time() - 86400);
    $id = '';
    foreach (json_decode((string)file_get_contents($raizDatos . '/admin/copias.json'), true) as $p) {
        if ($p['formato'] === 'zip') { $id = $p['id']; }
    }
    $antes = glob($raizDatos . '/admin/copias/grande/*.zip');
    sleep(1);
    peticion('p=copias', ['csrf' => csrf('p=copias'), 'accion' => 'copia_ahora', 'id' => $id, 'volver' => 'copias']);
    $despues = glob($raizDatos . '/admin/copias/grande/*.zip');
    return count($despues) === 2 && !is_file($vieja) && count(array_diff($despues, $antes)) === 1 ?: array_map('basename', $despues);
});
chk('una copia se descarga entera, y no se puede pedir un fichero de fuera', function () use ($raizDatos) {
    $zip = basename(glob($raizDatos . '/admin/copias/grande/*.zip')[0]);
    $f = $raizDatos . '/bajada.zip';
    peticion('p=copias', ['csrf' => csrf('p=copias'), 'accion' => 'descargar_copia', 'nombre' => 'grande', 'fichero' => $zip], $f);
    $igual = filesize($f) === filesize($raizDatos . "/admin/copias/grande/$zip");
    @unlink($f);
    $fuera = peticion('p=copias', ['csrf' => csrf('p=copias'), 'accion' => 'descargar_copia', 'nombre' => 'grande', 'fichero' => '../../usuarios.json', 'volver' => 'copias']);
    return $igual && str_contains($fuera, 'Esa copia no existe') ?: 'descarga distinta o deja salir';
});
chk('el script del cron hace las que tocan y solo esas, con el mismo límite de memoria', function () use ($raizDatos, $raizProyecto, $prepend) {
    // Se adelanta la última de la diaria para que toque; la de SQL no
    Store::actualizar('copias.json', static function (array $t): array {
        foreach ($t as $i => $p) { if ($p['formato'] === 'zip') { $t[$i]['ultima'] = '2020-01-01 00:00:00'; } }
        return $t;
    });
    $antes = glob($raizDatos . '/admin/copias/grande/*');
    exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . MEMORIA . ' -d auto_prepend_file=' . escapeshellarg($prepend) . ' '
        . escapeshellarg($raizProyecto . '/jsonsqldbadmin/herramientas/copias-cron.php') . ' 2>&1', $salida, $codigo);
    $despues = glob($raizDatos . '/admin/copias/grande/*');
    $nuevos = array_values(array_diff($despues, $antes));
    return $codigo === 0 && count($nuevos) === 1 && str_ends_with($nuevos[0], '.zip') ?: [$codigo, $salida, $nuevos];
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
