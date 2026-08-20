<?php
declare(strict_types=1);

/**
 * Prueba de la API. Ejecutar: php tests/f4_api.php
 *
 * Cada petición se lanza en un proceso PHP aparte (tests/_peticion.php) para
 * reproducir el ciclo real: superglobales, cabeceras y exit del endpoint.
 */
$raizProyecto = dirname(__DIR__);
$raizDatos    = sys_get_temp_dir() . '/jsonsqldb_test_f4';
$base         = 'apibase';
$ok = 0; $ko = 0;

// Las claves salen de la configuración real, no se repiten aquí: así la prueba
// sigue valiendo después de cambiarlas, que es justo lo que hay que hacer.
$API_KEYS = [];
require __DIR__ . '/_config_api.php';

$HMAC          = HMAC_SECRET;
$CLAVE_LECTURA = 'CLAVE_DE_PRUEBA_SOLO_LECTURA_0000000000000000000000000000000000';
$CLAVE_ADMIN   = '';
$CLAVE_APP     = '';
foreach ($API_KEYS as $clave => $cuenta) {
    if ($CLAVE_ADMIN === '' && ($cuenta['permiso'] ?? '') === 'admin')     { $CLAVE_ADMIN = $clave; }
    if ($CLAVE_APP   === '' && ($cuenta['permiso'] ?? '') === 'escritura'
        && ($cuenta['bases'] ?? []) === ['*'])                             { $CLAVE_APP   = $clave; }
}
if ($CLAVE_ADMIN === '' || $CLAVE_APP === '') {
    echo "Faltan claves en api/jsonsqldb_api_config.php: hace falta una 'admin' y una\n"
       . "'escritura' con acceso a todas las bases ('bases' => ['*']).\n";
    exit(1);
}

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

/** Lanza una petición contra el endpoint y devuelve la respuesta decodificada. */
function peticion(array $post): array
{
    global $raizProyecto, $raizDatos;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_peticion.php')
         . ' ' . escapeshellarg(json_encode($post, JSON_UNESCAPED_UNICODE))
         . ' ' . escapeshellarg($raizDatos);
    $salida = shell_exec($cmd);
    $datos  = json_decode((string)$salida, true);
    return is_array($datos) ? $datos : ['error' => 'respuesta no válida: ' . substr((string)$salida, 0, 300)];
}

/** Petición firmada correctamente. */
function firmada(string $sql, string $clave, string $bd = null, ?string $ts = null, array $params = []): array
{
    global $HMAC, $base;
    $bd ??= $base;
    $ts ??= (string)time();
    $pr = $params === [] ? '' : (string)json_encode($params, JSON_UNESCAPED_UNICODE);
    return peticion([
        'api_key'   => $clave,
        'db'        => $bd,
        'sql'       => $sql,
        'params'    => $pr,
        'timestamp' => $ts,
        'token'     => hash_hmac('sha256', '+' . $clave . '|' . $ts . '|' . $sql . $pr . '¿', $HMAC),
    ]);
}

// --- Preparar entorno ---
if (is_dir("$raizDatos/$base")) {
    require_once $raizProyecto . '/engine/bootstrap.php';
    JsonSQLDB\Storage::borrarBase($raizDatos, $base);
}
@mkdir($raizDatos, 0775, true);
require_once $raizProyecto . '/engine/bootstrap.php';
JsonSQLDB\Storage::crearBase($raizDatos, $base);

echo "\n== Autenticación ==\n";
chk('token correcto ejecuta la consulta', function () {
    $r = firmada('SELECT 1 AS uno', $GLOBALS['CLAVE_ADMIN']);
    return isset($r[0]['uno']) && $r[0]['uno'] === 1;
});
chk('API key desconocida', function () {
    $r = firmada('SELECT 1', 'clave_que_no_existe');
    return str_contains($r['error'] ?? '', 'API key no válida');
});
chk('token manipulado', function () {
    global $CLAVE_ADMIN, $base;
    $r = peticion(['api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => 'SELECT 1',
                   'timestamp' => (string)time(), 'token' => str_repeat('a', 64)]);
    return str_contains($r['error'] ?? '', 'Token inválido');
});
chk('la SQL no puede cambiarse sin rehacer la firma', function () {
    global $CLAVE_ADMIN, $HMAC, $base;
    $ts  = (string)time();
    $tok = hash_hmac('sha256', '+' . $CLAVE_ADMIN . '|' . $ts . '|SELECT 1¿', $HMAC);
    $r = peticion(['api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => 'DROP TABLE clientes',
                   'timestamp' => $ts, 'token' => $tok]);
    return str_contains($r['error'] ?? '', 'Token inválido');
});
chk('timestamp caducado', function () {
    $r = firmada('SELECT 1', $GLOBALS['CLAVE_ADMIN'], null, (string)(time() - 4000));
    return str_contains($r['error'] ?? '', 'Timestamp fuera de rango');
});
chk('formato de token no hexadecimal', function () {
    global $CLAVE_ADMIN, $base;
    $r = peticion(['api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => 'SELECT 1',
                   'timestamp' => (string)time(), 'token' => 'no-es-un-token']);
    return str_contains($r['error'] ?? '', 'Token inválido');
});
chk('solo se admite POST', function () {
    $r = peticion(['__metodo' => 'GET', 'sql' => 'SELECT 1']);
    return str_contains($r['error'] ?? '', 'Método no permitido');
});

echo "\n== Parámetros ==\n";
chk('sin db solo valen las sentencias globales', function () {
    $r = firmada('SELECT 1', $GLOBALS['CLAVE_ADMIN'], '');
    return str_contains($r['error'] ?? '', 'necesita indicar una base de datos');
});
chk('SHOW DATABASES sin db', function () {
    $r = firmada('SHOW DATABASES', $GLOBALS['CLAVE_ADMIN'], '');
    return isset($r[0]['base']);
});
chk('una API key limitada a bases concretas exige db', function () {
    $r = firmada('SHOW DATABASES', $GLOBALS['CLAVE_LECTURA'], '');
    return str_contains($r['error'] ?? '', 'indica el parámetro db');
});
chk('nombre de base no válido', function () {
    $r = firmada('SELECT 1', $GLOBALS['CLAVE_ADMIN'], '../../etc');
    return str_contains($r['error'] ?? '', 'no válido');
});
chk('base inexistente', function () {
    $r = firmada('SELECT 1', $GLOBALS['CLAVE_ADMIN'], 'nohay');
    return str_contains($r['error'] ?? '', 'no existe');
});
chk('SQL vacía', function () {
    $r = firmada('', $GLOBALS['CLAVE_ADMIN']);
    return str_contains($r['error'] ?? '', 'SQL vacía');
});

echo "\n== Permisos ==\n";
chk('admin puede crear tablas', function () {
    $r = firmada("CREATE TABLE clientes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nombre VARCHAR(50) NOT NULL,
                    ciudad TEXT)", $GLOBALS['CLAVE_ADMIN']);
    return ($r['success'] ?? false) === true;
});
chk('escritura puede insertar', function () {
    $r = firmada("INSERT INTO clientes (nombre, ciudad) VALUES ('Ana', 'Madrid'), ('Luis', 'Valencia')",
                 $GLOBALS['CLAVE_APP']);
    return ($r['filas'] ?? 0) === 2;
});
chk('escritura puede actualizar y borrar', function () {
    $r1 = firmada("UPDATE clientes SET ciudad = 'Elche' WHERE nombre = 'Ana'", $GLOBALS['CLAVE_APP']);
    $r2 = firmada("DELETE FROM clientes WHERE nombre = 'Luis'", $GLOBALS['CLAVE_APP']);
    return ($r1['filas'] ?? 0) === 1 && ($r2['filas'] ?? 0) === 1;
});
chk('escritura NO puede crear tablas', function () {
    $r = firmada('CREATE TABLE prohibida (id INTEGER)', $GLOBALS['CLAVE_APP']);
    return str_contains($r['error'] ?? '', 'no tiene permiso para modificar la estructura');
});
chk('escritura NO puede borrar tablas', function () {
    $r = firmada('DROP TABLE clientes', $GLOBALS['CLAVE_APP']);
    return str_contains($r['error'] ?? '', 'PERMISSION');
});
chk('escritura NO puede crear triggers', function () {
    $r = firmada("CREATE TRIGGER t1 AFTER INSERT ON clientes BEGIN DELETE FROM clientes; END",
                 $GLOBALS['CLAVE_APP']);
    return str_contains($r['error'] ?? '', 'PERMISSION');
});
chk('lectura solo puede consultar', function () {
    $r1 = firmada('SELECT COUNT(*) AS n FROM clientes', $GLOBALS['CLAVE_LECTURA']);
    $r2 = firmada("INSERT INTO clientes (nombre) VALUES ('X')", $GLOBALS['CLAVE_LECTURA']);
    $r3 = firmada("UPDATE clientes SET ciudad = 'Y'", $GLOBALS['CLAVE_LECTURA']);
    $r4 = firmada('DELETE FROM clientes', $GLOBALS['CLAVE_LECTURA']);
    return ($r1[0]['n'] ?? null) === 1
        && str_contains($r2['error'] ?? '', 'solo tiene permiso de lectura')
        && str_contains($r3['error'] ?? '', 'solo tiene permiso de lectura')
        && str_contains($r4['error'] ?? '', 'solo tiene permiso de lectura');
});
chk('una consulta denegada no modifica nada', function () {
    $r = firmada('SELECT COUNT(*) AS n FROM clientes', $GLOBALS['CLAVE_ADMIN']);
    return ($r[0]['n'] ?? null) === 1;
});
chk('la clave restringida no accede a otra base', function () {
    JsonSQLDB\Storage::crearBase($GLOBALS['raizDatos'], 'otrabase');
    $r = firmada('SELECT 1', $GLOBALS['CLAVE_LECTURA'], 'otrabase');
    return str_contains($r['error'] ?? '', 'no tiene acceso a la base de datos');
});

echo "\n== Consultas ==\n";
chk('SELECT devuelve las filas como objetos', function () {
    firmada("INSERT INTO clientes (nombre, ciudad) VALUES ('Marta', 'Alicante')", $GLOBALS['CLAVE_APP']);
    $r = firmada('SELECT nombre, ciudad FROM clientes ORDER BY nombre', $GLOBALS['CLAVE_APP']);
    return count($r) === 2 && $r[0]['nombre'] === 'Ana' && $r[1]['ciudad'] === 'Alicante';
});
chk('SQL multilínea con comentarios', function () {
    $sql = "-- clientes por ciudad\nSELECT ciudad,\n       COUNT(*) AS n\nFROM   clientes\nGROUP  BY ciudad\nORDER  BY ciudad";
    $r = firmada($sql, $GLOBALS['CLAVE_APP']);
    return count($r) === 2 && $r[0]['ciudad'] === 'Alicante';
});
chk('acentos y comillas en los datos', function () {
    firmada("INSERT INTO clientes (nombre, ciudad) VALUES ('O''Donnell Ñ', 'Logroño')", $GLOBALS['CLAVE_APP']);
    $r = firmada("SELECT nombre FROM clientes WHERE ciudad = 'Logroño'", $GLOBALS['CLAVE_APP']);
    return ($r[0]['nombre'] ?? '') === "O'Donnell Ñ";
});
chk('error de SQL devuelve mensaje claro', function () {
    $r = firmada('SELECT * FROM no_existe', $GLOBALS['CLAVE_APP']);
    return str_contains($r['error'] ?? '', 'SCHEMA') && str_contains($r['error'] ?? '', 'no existe');
});
chk('una restricción devuelve error y no inserta', function () {
    $r1 = firmada("INSERT INTO clientes (nombre) VALUES (NULL)", $GLOBALS['CLAVE_APP']);
    $r2 = firmada('SELECT COUNT(*) AS n FROM clientes', $GLOBALS['CLAVE_APP']);
    return str_contains($r1['error'] ?? '', 'CONSTRAINT') && ($r2[0]['n'] ?? null) === 3;
});

echo "\n== Registro de peticiones ==\n";
chk('el histórico guarda ip, sql, filas y tiempo', function () use ($raizDatos) {
    $fichero = $raizDatos . '/api/peticiones-' . date('Y-m-d') . '.json';
    if (!is_file($fichero)) { return 'no se creó el histórico'; }
    $lineas = array_filter(explode("\n", (string)file_get_contents($fichero)));
    $ultima = json_decode((string)end($lineas), true);
    return isset($ultima['ip'], $ultima['ms'], $ultima['filas'], $ultima['op'])
        && $ultima['ip'] === '10.0.0.7' && is_float($ultima['ms']) || is_int($ultima['ms'] ?? null);
});
chk('el log del motor guarda la etiqueta de la API key', function () use ($raizDatos) {
    $fichero = $raizDatos . '/logs/consultas-' . date('Y-m-d') . '.json';
    if (!is_file($fichero)) { return 'no se creó el log del motor'; }
    $lineas = array_filter(explode("\n", (string)file_get_contents($fichero)));
    foreach ($lineas as $linea) {
        $e = json_decode($linea, true);
        if (($e['origen'] ?? '') === 'Mi aplicación' && $e['op'] === 'SELECT' && $e['ip'] === '10.0.0.7') {
            return true;
        }
    }
    return 'no se encontró la entrada esperada';
});

echo "\n== Parámetros ligados ==\n";
chk('preparar tabla de parámetros', function () {
    global $CLAVE_ADMIN;
    $r = firmada('CREATE TABLE param (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre VARCHAR(60), saldo DECIMAL(10,2))', $CLAVE_ADMIN);
    return ($r['success'] ?? false) === true;
});
chk('INSERT con parámetros', function () {
    global $CLAVE_ADMIN;
    $r = firmada('INSERT INTO param (nombre, saldo) VALUES (?, ?), (?, ?)', $CLAVE_ADMIN, null, null,
                 ['Ana', 10.5, "O'Donnell", 0]);
    return ($r['filas'] ?? 0) === 2;
});
chk('SELECT con parámetro', function () {
    global $CLAVE_ADMIN;
    $r = firmada('SELECT nombre FROM param WHERE saldo > ?', $CLAVE_ADMIN, null, null, [1]);
    return count($r) === 1 && $r[0]['nombre'] === 'Ana';
});
chk('la comilla del valor no rompe la consulta', function () {
    global $CLAVE_ADMIN;
    $r = firmada('SELECT COUNT(*) AS n FROM param WHERE nombre = ?', $CLAVE_ADMIN, null, null, ["O'Donnell"]);
    return ($r[0]['n'] ?? null) === 1;
});
chk('un valor con SQL dentro no inyecta', function () {
    global $CLAVE_ADMIN;
    $r = firmada('SELECT COUNT(*) AS n FROM param WHERE nombre = ?', $CLAVE_ADMIN, null, null,
                 ["x' OR 1=1; DROP TABLE param; --"]);
    if (($r[0]['n'] ?? null) !== 0) { return 'devolvió filas: ' . json_encode($r); }
    $r2 = firmada('SELECT COUNT(*) AS n FROM param', $CLAVE_ADMIN);
    return ($r2[0]['n'] ?? null) === 2 ?: 'la tabla ha cambiado';
});
chk('cambiar los parámetros invalida la firma', function () {
    global $CLAVE_ADMIN, $HMAC, $base;
    $sql = 'SELECT COUNT(*) AS n FROM param WHERE nombre = ?';
    $ts  = (string)time();
    $r = peticion([
        'api_key'   => $CLAVE_ADMIN,
        'db'        => $base,
        'sql'       => $sql,
        'params'    => '["otro"]',                                  // manipulado
        'timestamp' => $ts,
        'token'     => hash_hmac('sha256', '+' . $CLAVE_ADMIN . '|' . $ts . '|' . $sql . '["Ana"]¿', $HMAC),
    ]);
    return str_contains($r['error'] ?? '', 'Token inválido');
});
chk('número de ? distinto al de parámetros', function () {
    global $CLAVE_ADMIN;
    $r = firmada('SELECT * FROM param WHERE nombre = ?', $CLAVE_ADMIN, null, null, ['Ana', 'Luis']);
    return str_contains($r['error'] ?? '', 'Error en la consulta');
});
chk('parámetros que no son una lista JSON', function () {
    global $CLAVE_ADMIN, $HMAC, $base;
    $sql = 'SELECT 1 AS uno';
    $ts  = (string)time();
    $pr  = '{"a":1}';
    $r = peticion([
        'api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => $sql, 'params' => $pr, 'timestamp' => $ts,
        'token'   => hash_hmac('sha256', '+' . $CLAVE_ADMIN . '|' . $ts . '|' . $sql . $pr . '¿', $HMAC),
    ]);
    return str_contains($r['error'] ?? '', 'lista JSON');
});
chk('sin parámetros la firma de siempre sigue valiendo', function () {
    global $CLAVE_ADMIN;
    $r = firmada('SELECT COUNT(*) AS n FROM param', $CLAVE_ADMIN);
    return ($r[0]['n'] ?? null) === 2;
});
chk('el log guarda los parámetros', function () {
    global $CLAVE_ADMIN, $raizDatos;
    firmada('SELECT nombre FROM param WHERE nombre = ?', $CLAVE_ADMIN, null, null, ['Ana']);
    foreach ((array)glob("$raizDatos/logs/consultas-*.json") as $f) {
        foreach (array_reverse(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $l) {
            $e = json_decode($l, true);
            if (is_array($e) && ($e['params'] ?? null) === ['Ana']) { return true; }
        }
    }
    return 'no se encontró la entrada con params';
});
chk('borrar la tabla de parámetros', function () {
    global $CLAVE_ADMIN;
    return (firmada('DROP TABLE param', $CLAVE_ADMIN)['success'] ?? false) === true;
});

echo "\n== Filtro por IP ==\n";
chk('IP suelta, rango CIDR, IPv6 y lista vacía', function () {
    // ipPermitida() vive en el endpoint; se comprueba en un proceso aparte
    // El endpoint termina la petición al incluirlo, así que se extrae la función
    $fn = (string)file_get_contents(dirname(__DIR__) . '/api/jsonsqldb_api.php');
    $ini = strpos($fn, 'function ipPermitida');
    $fin = strpos($fn, "\n}", $ini) + 2;
    $codigo = '<?php ' . substr($fn, $ini, $fin - $ini) . '
        $r = [
            ipPermitida("10.0.0.7",  ["10.0.0.7"]),
            ipPermitida("10.0.0.8",  ["10.0.0.7"]),
            ipPermitida("10.0.0.99", ["10.0.0.0/24"]),
            ipPermitida("10.0.1.1",  ["10.0.0.0/24"]),
            ipPermitida("192.168.5.20", ["10.0.0.0/8", "192.168.5.0/28", "192.168.5.16/28"]),
            ipPermitida("2001:db8::1", ["2001:db8::/32"]),
            ipPermitida("2001:dba::1", ["2001:db8::/32"]),
            ipPermitida("cualquiera", []),
            ipPermitida("no-es-una-ip", ["10.0.0.0/24"]),
        ];
        echo implode(",", array_map(fn($x) => $x ? "1" : "0", $r));';
    $tmp = sys_get_temp_dir() . '/ip_' . getmypid() . '.php';
    file_put_contents($tmp, $codigo);
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1'));
    @unlink($tmp);
    return $salida === '1,0,1,0,1,1,0,1,0' ?: $salida;
});

echo "\n== Clave de los clientes de ejemplo ==\n";
chk('está registrada con escritura sobre pruebas', function () {
    require_once __DIR__ . '/../api/cliente_ejemplo.php';
    $API_KEYS = [];
    require __DIR__ . '/../api/jsonsqldb_api_config.php';
    $c = $API_KEYS[JsonSqlDbCliente::EJEMPLO_API_KEY] ?? null;
    return is_array($c) && $c['permiso'] === 'escritura' && $c['bases'] === ['pruebas'];
});
chk('el .ps1 usa exactamente la misma clave', function () {
    $ps1 = (string)file_get_contents(__DIR__ . '/../api/cliente_ejemplo.ps1');
    return str_contains($ps1, "ApiKey      = '" . JsonSqlDbCliente::EJEMPLO_API_KEY . "'");
});
chk('los dos clientes comparten el secreto HMAC', function () {
    $ps1 = (string)file_get_contents(__DIR__ . '/../api/cliente_ejemplo.ps1');
    return str_contains($ps1, "HmacSecret  = '" . JsonSqlDbCliente::EJEMPLO_SECRETO . "'")
        && JsonSqlDbCliente::EJEMPLO_SECRETO === HMAC_SECRET;
});

echo "\n== Limpieza ==\n";
chk('borrar bases de prueba', function () use ($raizDatos, $base) {
    JsonSQLDB\Storage::borrarBase($raizDatos, $base);
    JsonSQLDB\Storage::borrarBase($raizDatos, 'otrabase');
    foreach (['api', 'logs'] as $d) {
        foreach ((array)glob("$raizDatos/$d/*") as $f) { @unlink($f); }
        @rmdir("$raizDatos/$d");
    }
    @rmdir($raizDatos);
    return !is_dir("$raizDatos/$base");
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
