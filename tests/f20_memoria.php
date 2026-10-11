<?php
declare(strict_types=1);

/**
 * Exportar e importar una base mayor que la memoria de PHP, y las copias
 * programadas. Ejecutar: php tests/f20_memoria.php [--directa] [--open-basedir]
 *
 * Con --directa, el panel usa la conexión directa: el motor trabaja dentro
 * del mismo proceso y con la misma memoria. Con --open-basedir, el panel y la
 * API solo pueden tocar el proyecto y sus datos, como en muchos hostings, y
 * cualquier aviso de PHP sale en la respuesta.
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
@mkdir($raizDatos . '/subidas_php', 0775, true);
[$puertoPanel, $puertoApi] = puertosLibres();
$url     = "http://127.0.0.1:$puertoPanel/jsonsqldbadmin/index.php";
$cookies = $raizDatos . '/cookies.txt';

$prepend = $raizDatos . '/_prepend.php';
file_put_contents($prepend, "<?php\n"
    . "define('JSONSQLDB_DATA_PATH', " . var_export($raizDatos, true) . ");\n"
    . "define('JSONSQLDB_LOG_PATH', "  . var_export($raizDatos . '/logs', true) . ");\n"
    // Todo al registro, también las lecturas (por defecto, desde 2.9, solo
    // escrituras y errores): una prueba de abajo mira en él la consulta del panel
    . "define('JSONSQLDB_LOG_NIVEL', 'todo');\n"
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
    // Con --open-basedir, como en un hosting que lo limita al proyecto y a sus
    // datos: la carpeta temporal del sistema queda fuera, y las subidas van a
    // una carpeta propia (upload_tmp_dir), como hay que configurar allí
    $limitado = in_array('--open-basedir', $argv, true)
        ? ' -d open_basedir=' . escapeshellarg($raizProyecto . PATH_SEPARATOR . $raizDatos)
          . ' -d upload_tmp_dir=' . escapeshellarg($raizDatos . '/subidas_php') . ' -d error_reporting=-1 -d display_errors=1'
        : '';
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . MEMORIA . ' -d max_execution_time=0' . $limitado
         . ' -d auto_prepend_file=' . escapeshellarg($prepend) . " -S 127.0.0.1:$p -t " . escapeshellarg($raizProyecto);
    // Con exec, el shell se convierte en el servidor: si no, proc_terminate()
    // mata el shell y el servidor se queda funcionando, huérfano
    $proc = proc_open((PHP_OS_FAMILY === 'Windows' ? '' : 'exec ') . $cmd, [1 => ['file', $raizDatos . '/server.log', 'a'], 2 => ['file', $raizDatos . '/server.log', 'a']], $t);
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
    unset($ch);                                 // suelta el fichero antes de cerrarlo (curl_close(), obsoleta en PHP 8.5)
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
chk('la tabla en JSON y en Excel sale entera, y el JSON se vuelve a cargar en otra tabla (2.9)', function () use ($raizDatos) {
    $j = $raizDatos . '/admin/importar/fila.json';
    @mkdir(dirname($j), 0775, true);
    peticion('p=datos&db=grande&tabla=fila', ['csrf' => csrf('p=datos&db=grande&tabla=fila'), 'accion' => 'exportar',
        'formato' => 'json', 'db' => 'grande', 'tabla' => 'fila'], $j);
    $filasJson = lineas($j, '{"id":');
    $x = $raizDatos . '/fila.xlsx';
    peticion('p=datos&db=grande&tabla=fila', ['csrf' => csrf('p=datos&db=grande&tabla=fila'), 'accion' => 'exportar',
        'formato' => 'xlsx', 'db' => 'grande', 'tabla' => 'fila'], $x);
    $zip = new ZipArchive();
    $hoja = $zip->open($x) === true ? (string)$zip->getFromName('xl/worksheets/sheet1.xml') : '';
    $zip->close();
    @unlink($x);
    $ultima = preg_match_all('/<row r="(\d+)"/', $hoja, $m) ? (int)end($m[1]) : 0;
    // Y de vuelta: el JSON en una tabla nueva, desde la carpeta de importar
    (new JsonSQLDB\Database('grande', $raizDatos))->consultar('CREATE TABLE fila_json AS SELECT * FROM fila WHERE 0');
    $html = peticion('p=tablas&db=grande', ['csrf' => csrf('p=tablas&db=grande'), 'accion' => 'importar_json', 'db' => 'grande',
        'tabla' => 'fila_json', 'servidor' => 'fila.json']);
    @unlink($j);
    $cargadas = contar('grande', 'fila_json');
    (new JsonSQLDB\Database('grande', $raizDatos))->consultar('DROP TABLE fila_json');
    return $filasJson === FILAS + 1 && $ultima === FILAS + 2 && $cargadas === FILAS + 1 ?: "JSON $filasJson, Excel hasta la fila $ultima, cargadas $cargadas: " . substr(strip_tags($html), 0, 200);
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
chk('una consulta ordenada de toda la tabla, que no cabe en la memoria, sale entera ordenando en disco (2.9)', function () use ($raizDatos) {
    $f = $raizDatos . '/ordenada.sql';
    peticion('p=sql&db=grande', ['csrf' => csrf('p=sql&db=grande'), 'accion' => 'exportar', 'formato' => 'sql', 'db' => 'grande',
        'sql' => 'SELECT id, n FROM fila ORDER BY n DESC'], $f);
    $n = lineas($f, 'INSERT INTO');
    $primera = '';
    foreach (new SplFileObject($f) as $l) {
        if (str_starts_with((string)$l, 'INSERT')) { $primera = (string)$l; break; }
    }
    $error = lineas($f, '-- ERROR');
    @unlink($f);
    return $n === FILAS + 1 && $error === 0 && str_contains($primera, 'VALUES (' . FILAS . ', ' . (FILAS - 1) . ')') ?: "$n · $error · $primera";
});
chk('una consulta que acaba en un comentario también sale por lotes (el LIMIT no queda dentro del comentario)', function () use ($raizDatos) {
    $f = $raizDatos . '/comentario.sql';
    peticion('p=sql&db=grande', ['csrf' => csrf('p=sql&db=grande'), 'accion' => 'exportar', 'formato' => 'sql', 'db' => 'grande',
        'sql' => "SELECT id, n FROM fila -- todas las filas"], $f);
    $n = lineas($f, 'INSERT INTO');
    @unlink($f);
    return $n === FILAS + 1 ?: "$n filas";
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
chk('una copia ZIP mayor que ' . MEMORIA . ' se sube por trozos y se restaura entera, con el panel en ' . MEMORIA, function () use ($raizDatos) {
    // Textos al azar en base64, que comprimidos siguen ocupando: el ZIP pasa de la memoria
    JsonSQLDB\Database::crear('zipgrande', $raizDatos);
    $bd = new JsonSQLDB\Database('zipgrande', $raizDatos);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, b TEXT)');
    for ($k = 0; $k < 100; $k++) {
        $v = [];
        for ($i = 1; $i <= 500; $i++) { $v[] = '(' . ($k * 500 + $i) . ", '" . base64_encode(random_bytes(750)) . "')"; }
        $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    }
    $f = $raizDatos . '/zipgrande.zip';
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'exportar_base', 'formato' => 'zip', 'nombre' => 'zipgrande'], $f);
    $tam = (int)filesize($f);
    if ($tam <= 32 * 1048576) { @unlink($f); return "el ZIP ocupa $tam bytes: no pasa de " . MEMORIA; }
    // Se estropea la base y se restaura con el ZIP subido por trozos de 1 MB
    $bd->consultar('DELETE FROM t WHERE id > 100');
    $id = str_repeat('cd', 16);
    $fh = fopen($f, 'rb');
    for ($desde = 0; ($datos = (string)fread($fh, 1048576)) !== ''; $desde += strlen($datos)) {
        $r = trozo($id, $desde, $datos);
        if (($r['recibido'] ?? null) !== $desde + strlen($datos)) { fclose($fh); @unlink($f); return $r; }
    }
    fclose($fh);
    $html = peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'importar_zip', 'nombre' => 'zipgrande',
        'subida' => $id, 'subida_tamano' => (string)$tam, 'subida_nombre' => 'zipgrande.zip']);
    @unlink($f);
    $n = contar('zipgrande', 't');
    $quedan = array_merge((array)glob($raizDatos . "/admin/importar/.subidas/$id*"), (array)glob($raizDatos . '/zipgrande.antes-de-restaurar'));
    return str_contains($html, 'restaurada') && $n === 50000 && $quedan === [] ?: [round($tam / 1048576, 1) . ' MB', strip_tags(substr($html, 0, 300)), $n, $quedan];
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
chk('un volcado de PostgreSQL con un COPY de 120.000 filas se importa: el COPY va por tandas (2.9)', function () use ($raizDatos) {
    $f = $raizDatos . '/admin/importar/pg.sql';
    $h = fopen($f, 'wb');
    fwrite($h, "CREATE TABLE public.datos (id integer NOT NULL, texto text);\nCOPY public.datos (id, texto) FROM stdin;\n");
    for ($i = 1; $i <= FILAS; $i++) {
        fwrite($h, "$i\ttexto de la fila $i, con algo de relleno para que pese\n");
    }
    fwrite($h, "\\.\n");
    fclose($h);
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'crear_base', 'nombre' => 'pgcopia']);
    $html = peticion('p=tablas&db=pgcopia', ['csrf' => csrf('p=tablas&db=pgcopia'), 'accion' => 'importar_sql', 'db' => 'pgcopia',
        'formato' => 'postgresql', 'servidor' => 'pg.sql']);
    @unlink($f);
    return contar('pgcopia', 'datos') === FILAS ?: substr(strip_tags($html), 0, 300);
});
chk('una sentencia que no cabe en la memoria se explica, con su línea, y no se cambia nada (2.9)', function () use ($raizDatos) {
    $f = $raizDatos . '/admin/importar/enorme.sql';
    file_put_contents($f, "CREATE TABLE previa (id INTEGER);\nINSERT INTO previa VALUES (1);\n\nINSERT INTO previa VALUES (2), ('" . str_repeat('z', 20000000) . "');\n");
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'crear_base', 'nombre' => 'enorme']);
    $html = peticion('p=tablas&db=enorme', ['csrf' => csrf('p=tablas&db=enorme'), 'accion' => 'importar_sql', 'db' => 'enorme',
        'formato' => 'auto', 'servidor' => 'enorme.sql']);
    @unlink($f);
    $tablas = peticion('p=tablas&db=enorme', null);
    return str_contains($html, 'empieza en la línea 4') && !str_contains($tablas, 'previa') ?: substr(strip_tags($html), 0, 400);
});
chk('la base entera en JSON sale y se vuelve a cargar en otra, por partes (2.9)', function () use ($raizDatos) {
    $f = $raizDatos . '/admin/importar/grande.json';
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'exportar_base', 'formato' => 'json', 'nombre' => 'grande'], $f);
    $filas = lineas($f, '{"id":');
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'crear_base', 'nombre' => 'grandejson']);
    $html = peticion('p=tablas&db=grandejson', ['csrf' => csrf('p=tablas&db=grandejson'), 'accion' => 'importar_sql', 'db' => 'grandejson',
        'formato' => 'auto', 'servidor' => 'grande.json']);
    @unlink($f);
    return $filas >= FILAS + 1 && contar('grandejson', 'fila') === FILAS + 1 && contar('grandejson', 'padre') === 2
        ?: "$filas filas en el JSON: " . substr(strip_tags($html), 0, 300);
});
chk('no se puede salir de la carpeta de importar', function () {
    $html = peticion('p=tablas&db=copia2', ['csrf' => csrf('p=tablas&db=copia2'), 'accion' => 'importar_sql', 'db' => 'copia2',
        'formato' => 'auto', 'servidor' => '../usuarios.json']);
    return str_contains($html, 'no está en la carpeta de importar');
});

echo "\n== Con open_basedir (hosting) ==\n";
chk('con open_basedir sin la carpeta temporal del sistema: temporales en los datos del panel, ordenar en disco y escribir sin avisos', function () use ($raizDatos, $raizProyecto) {
    $codigo = 'define("ADMIN_DATA_PATH", ' . var_export($raizDatos . '/admin', true) . '); define("ADMIN_CONEXION", "directa");'
        . ' define("ADMIN_MOTOR_RUTA", ' . var_export($raizProyecto, true) . '); define("JSONSQLDB_DATA_PATH", ' . var_export($raizDatos, true) . ');'
        . ' define("JSONSQLDB_LOG_PATH", ' . var_export($raizDatos . '/logs', true) . ');'
        . ' require ' . var_export($raizProyecto . '/jsonsqldbadmin/lib/arranque.php', true) . '; Api::cargarMotor();'
        . ' $t = ficheroTemporal("jsonsqldb_"); echo dirname((string)$t), "|"; @unlink((string)$t);'
        . ' $b = new JsonSQLDB\\Database("grande", ' . var_export($raizDatos, true) . ');'
        . ' $b->consultar("UPDATE padre SET nombre = \'otra\' WHERE id = 2");'
        . ' $n = 0; $r = $b->consultarPorFilas("SELECT id FROM fila ORDER BY texto DESC, id", [], null, function () use (&$n) { $n++; });'
        . ' echo is_array($r) ? count($r) : $n, "|", count(glob(' . var_export($raizDatos . '/grande/.orden/*', true) . ') ?: []);';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . MEMORIA . ' -d error_reporting=-1 -d display_errors=1'
        . ' -d open_basedir=' . escapeshellarg($raizProyecto . PATH_SEPARATOR . $raizDatos) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    return $salida === $raizDatos . '/admin|' . (FILAS + 1) . '|0' ?: $salida;
});

echo "\n== Filas muy anchas, y una tabla que no cabe ==\n";
chk('una tabla de 30 filas de 400 KB se vuelca entera con 32 MB por la API (lotes y bloques medidos en bytes, 2.9); con conexión directa, donde el motor comparte la memoria del panel, se dice que no cabe', function () use ($raizDatos) {
    JsonSQLDB\Database::crear('ancha', $raizDatos);
    $bd = new JsonSQLDB\Database('ancha', $raizDatos);
    $bd->consultar('CREATE TABLE antes (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar("INSERT INTO antes VALUES (1, 'a'), (2, 'b')");
    $bd->consultar('CREATE TABLE monstruos (id INTEGER PRIMARY KEY, x TEXT)');
    for ($i = 1; $i <= 30; $i++) {
        $bd->consultar('INSERT INTO monstruos VALUES (?, ?)', [$i, str_repeat('m', 400000)]);
    }
    $bd->consultar('CREATE TABLE zeta (id INTEGER PRIMARY KEY)');
    unset($bd);
    $f = $raizDatos . '/ancha.sql';
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'ancha'], $f);
    $n = lineas($f, 'INSERT INTO "monstruos"');
    $roto = lineas($f, 'INCOMPLETE_DUMP');
    $tablas = lineas($f, 'CREATE TABLE');
    $html = (string)file_get_contents($f);
    @unlink($f);
    if (in_array('--directa', $GLOBALS['argv'], true)) {
        return str_contains($html, 'No se puede volcar la base') && str_contains($html, 'monstruos') && $tablas === 0
            ?: substr(strip_tags($html), 0, 300);
    }
    return $n === 30 && $roto === 0 && $tablas === 3 ?: "$n filas, $tablas tablas, marca $roto";
});
chk('una base con una tabla que no se puede leer no se vuelca: el panel dice cuál, y no se descarga nada (2.9)', function () use ($raizDatos) {
    // Tres filas de 12 MB: su parte de datos no se puede leer con 32 MB
    JsonSQLDB\Database::crear('rota', $raizDatos);
    $bd = new JsonSQLDB\Database('rota', $raizDatos);
    $bd->consultar('CREATE TABLE antes (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar('CREATE TABLE monstruos (id INTEGER PRIMARY KEY, x TEXT)');
    for ($i = 1; $i <= 3; $i++) {
        $bd->consultar('INSERT INTO monstruos VALUES (?, ?)', [$i, str_repeat('m', 12000000)]);
    }
    unset($bd);
    $f = $raizDatos . '/rota.sql';
    peticion('p=bases', ['csrf' => csrf('p=bases'), 'accion' => 'exportar_base', 'formato' => 'sql', 'nombre' => 'rota'], $f);
    $html = (string)file_get_contents($f);
    @unlink($f);
    return str_contains($html, 'No se puede volcar la base') && str_contains($html, 'monstruos')
        && !str_contains($html, 'CREATE TABLE') ?: substr(strip_tags($html), 0, 300);
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
    // El día del cambio de hora tiene 25 horas: restando 86.400 segundos para
    // ir al día anterior salía una copia de más a medianoche
    $zona = date_default_timezone_get();
    date_default_timezone_set('Europe/Madrid');
    // Ese domingo las 2:00 son dos veces: PHP 8.0 toma la segunda y los
    // siguientes la primera. La copia se hizo a la que toma este PHP
    $dosDelDomingo = date(DATE_ATOM, mktime(2, 0, 0, 10, 25, 2026));
    $dst = [
        Copias::toca(['frecuencia' => 'diaria', 'hora' => 1, 'ultima' => '2026-10-25T01:00:00+02:00'], $t('2026-10-26 00:00:00')),
        Copias::toca(['frecuencia' => 'diaria', 'hora' => 1, 'ultima' => '2026-10-25T01:00:00+02:00'], $t('2026-10-26 01:00:00')),
        Copias::toca(['frecuencia' => 'semanal', 'dia' => 7, 'hora' => 2, 'ultima' => $dosDelDomingo], $t('2026-10-26 00:00:00')),
        Copias::toca(['frecuencia' => 'diaria', 'hora' => 3, 'ultima' => '2026-10-24T03:00:00+02:00'], $t('2026-10-25 00:30:00')),
    ];
    date_default_timezone_set($zona);
    return $dst === [false, true, false, false] ?: json_encode($dst);
});
chk('dos copias del mismo segundo no se pisan: la segunda lleva -2', function () use ($raizDatos) {
    $dir = $raizDatos . '/nombres';
    @mkdir($dir, 0775, true);
    $a = Copias::nombreNuevo($dir, 'b', 'zip');
    touch("$dir/$a");
    $b = Copias::nombreNuevo($dir, 'b', 'zip');
    touch("$dir/$b");
    $c = Copias::nombreNuevo($dir, 'b', 'zip');
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
    return $a !== $b && $b !== $c && preg_match('/^b-\d{8}-\d{6}(-[23])?\.zip$/', $c) === 1 ?: [$a, $b, $c];
});
chk('el campo «N horas» solo se ve con «Cada N horas», y una copia diaria se programa sin él', function () use ($raizDatos) {
    $html = peticion('p=copias', null);
    if (!preg_match('/<div class="col-6" data-visible-si="cp-frec=horas">\s*<label[^>]*for="cp-horas"/', $html)) { return 'falta el atributo'; }
    peticion('p=copias', ['csrf' => csrf('p=copias'), 'accion' => 'programar_copia', 'base' => 'copia', 'frecuencia' => 'diaria',
        'hora' => '4', 'formato' => 'sql', 'conservar' => '3']);
    $prog = array_values(array_filter(json_decode((string)file_get_contents($raizDatos . '/admin/copias.json'), true),
        static fn(array $p): bool => $p['base'] === 'copia'));
    // Se quita: las demás pruebas cuentan las copias de 'grande'
    peticion('p=copias', ['csrf' => csrf('p=copias'), 'accion' => 'borrar_programacion', 'id' => $prog[0]['id'] ?? '', 'volver' => 'copias']);
    return count($prog) === 1 && $prog[0]['frecuencia'] === 'diaria' && $prog[0]['hora'] === 4 ?: $prog;
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
