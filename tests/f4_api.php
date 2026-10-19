<?php
declare(strict_types=1);

/**
 * Prueba de la API. Ejecutar: php tests/f4_api.php
 *
 * Cada petición se lanza en un proceso PHP aparte (tests/_peticion.php) para
 * reproducir el ciclo real: superglobales, cabeceras y exit del endpoint.
 *
 * https://miguelenred.es/jsonsqldb
 */
$raizProyecto = dirname(__DIR__);
$raizDatos    = sys_get_temp_dir() . '/jsonsqldb_test_f4';
$base         = 'apibase';
$ok = 0; $ko = 0;

// Las claves salen de la configuración real, no se repiten aquí: así la prueba
// sigue valiendo después de cambiarlas, que es justo lo que hay que hacer.
$API_KEYS = [];
require __DIR__ . '/_config_api.php';

$CLAVE_LECTURA = 'CLAVE_DE_PRUEBA_SOLO_LECTURA_0000000000000000000000000000000000';
$CLAVE_ADMIN   = '';
$CLAVE_APP     = '';
foreach ($API_KEYS as $cuenta) {
    $clave = (string)($cuenta['key'] ?? '');
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
function peticion(array $post, bool $conLimites = false): array
{
    global $raizDatos;
    // Sin shell: con el locale C (el de PHP 8.0 en algunas instalaciones),
    // escapeshellarg() se come los caracteres que no son ASCII y la petición
    // llegaba con «Logroo» en vez de «Logroño»
    $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/_peticion.php', (string)json_encode($post, JSON_UNESCAPED_UNICODE), $raizDatos],
                               $conLimites ? ['limites'] : []),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
    $salida = stream_get_contents($tub[1]);
    fclose($tub[1]);
    fclose($tub[2]);
    proc_close($p);
    $datos  = json_decode((string)$salida, true);
    return is_array($datos) ? $datos : ['error' => 'respuesta no válida: ' . substr((string)$salida, 0, 300)];
}

/** Cuenta que corresponde a una API key, buscada por su campo 'key'. */
function cuentaDe(string $clave): ?array
{
    foreach ($GLOBALS['API_KEYS'] as $nombre => $cuenta) {
        if ((string)($cuenta['key'] ?? '') === $clave) {
            return ['nombre' => (string)$nombre] + $cuenta;
        }
    }
    return null;
}

/** Secreto con el que firma una clave. */
function secretoDe(string $clave): string
{
    return (string)(cuentaDe($clave)['hmac_secret'] ?? '');
}

/** Petición firmada correctamente. */
function firmada(string $sql, string $clave, ?string $bd = null, ?string $ts = null, array $params = [], array $extra = []): array
{
    global $base;
    $bd ??= $base;
    $ts ??= (string)time();
    $pr = $params === [] ? '' : (string)json_encode($params, JSON_UNESCAPED_UNICODE);
    return peticion($extra + [
        'api_key'   => $clave,
        'db'        => $bd,
        'sql'       => $sql,
        'params'    => $pr,
        'timestamp' => $ts,
        'token'     => hash_hmac('sha256',
            '+' . $clave . '|' . $bd . '|' . $ts . '|' . $sql . $pr . '¿', secretoDe($clave)),
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
chk('una SQL con espacios o saltos de línea en los extremos se firma tal cual y vale (2.9: se firmaba sin ellos y daba «Token inválido»)', function () {
    $r = firmada("\n  SELECT 1 AS uno \n", $GLOBALS['CLAVE_ADMIN']);
    return ($r[0]['uno'] ?? null) === 1 ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('detrás de un proxy de confianza cuenta la última IP de X-Forwarded-For, la que pone el proxy (2.9: la primera, que escribe el cliente)', function () {
    global $CLAVE_ADMIN, $base;
    $con = static function (string $xff) use ($CLAVE_ADMIN, $base): array {
        $ts = (string)time();
        return peticion(['__proxy' => $xff, 'api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => 'SELECT 1 AS uno', 'params' => '', 'timestamp' => $ts,
            'token' => hash_hmac('sha256', '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts . '|SELECT 1 AS uno¿', secretoDe($CLAVE_ADMIN))]);
    };
    // Solo vale 198.51.100.0/24: el cliente que escribe una de ahí delante de la suya no pasa
    $falseada = $con('198.51.100.7, 203.0.113.9');
    $buena    = $con('203.0.113.9, 198.51.100.7');
    return str_contains($falseada['error'] ?? '', 'no permitido') && ($buena[0]['uno'] ?? null) === 1 ?: json_encode([$falseada, $buena], JSON_UNESCAPED_UNICODE);
});
chk('una API key desconocida da lo mismo que una firma mala, también con la hora fuera de la ventana (2.9: se distinguían)', function () {
    global $CLAVE_ADMIN, $base;
    $r = firmada('SELECT 1', 'clave_que_no_existe');
    $vieja = time() - 3600;
    $hora = static fn(string $clave): array => peticion(['api_key' => $clave, 'db' => $base, 'sql' => 'SELECT 1',
                   'timestamp' => (string)$vieja, 'token' => str_repeat('a', 64)]);
    return ($r['error'] ?? '') === 'Token inválido' && $hora('clave_que_no_existe') === $hora($CLAVE_ADMIN) ?: json_encode([$r, $hora('clave_que_no_existe'), $hora($CLAVE_ADMIN)], JSON_UNESCAPED_UNICODE);
});
chk('token manipulado', function () {
    global $CLAVE_ADMIN, $base;
    $r = peticion(['api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => 'SELECT 1',
                   'timestamp' => (string)time(), 'token' => str_repeat('a', 64)]);
    return str_contains($r['error'] ?? '', 'Token inválido');
});
chk('la SQL no puede cambiarse sin rehacer la firma', function () {
    global $CLAVE_ADMIN, $base;
    $ts  = (string)time();
    $tok = hash_hmac('sha256',
        '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts . '|SELECT 1¿', secretoDe($CLAVE_ADMIN));
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
chk('una API key limitada solo ve sus bases en SHOW DATABASES', function () {
    // Pidiéndolo desde una base suya, el motor lo contesta para todas: la API
    // tiene que quitar las que no son de la clave
    firmada('CREATE DATABASE otra_ajena', $GLOBALS['CLAVE_ADMIN'], '');
    $r = firmada('SHOW DATABASES', $GLOBALS['CLAVE_LECTURA'], 'apibase');
    firmada('DROP DATABASE otra_ajena', $GLOBALS['CLAVE_ADMIN'], '');
    $bases = array_column(is_array($r) && !isset($r['error']) ? $r : [], 'base');
    return $bases === ['apibase'] ?: 've: ' . json_encode($r);
});
chk('una API key limitada no puede crear ni borrar bases', function () {
    // Aunque tuviera permiso de administración: la limitación es a unas bases,
    // y crear o borrar una no es de ninguna de ellas
    firmada('CREATE DATABASE otra_ajena', $GLOBALS['CLAVE_ADMIN'], '');
    $clave = 'CLAVE_DE_PRUEBA_ADMIN_LIMITADA_000000000000000000000000000000000';
    $r1 = firmada('DROP DATABASE otra_ajena', $clave, 'apibase');
    $r2 = firmada('CREATE DATABASE otra_mas', $clave, 'apibase');
    $sigue = in_array('otra_ajena', array_column((array)firmada('SHOW DATABASES', $GLOBALS['CLAVE_ADMIN'], ''), 'base'), true);
    firmada('DROP DATABASE otra_ajena', $GLOBALS['CLAVE_ADMIN'], '');
    return (str_contains($r1['error'] ?? '', 'limitada') && str_contains($r2['error'] ?? '', 'limitada') && $sigue)
        ?: json_encode([$r1, $r2, $sigue]);
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
chk('escritura NO puede lanzar VACUUM, y el mensaje dice por qué (2.10)', function () {
    $r = firmada('VACUUM', $GLOBALS['CLAVE_APP']);
    return str_contains($r['error'] ?? '', 'VACUUM necesita una API key de administración') ?: json_encode($r, JSON_UNESCAPED_UNICODE);
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
chk('lectura también puede usar EXPLAIN y SHOW CREATE TABLE, que solo leen (2.9)', function () {
    $r1 = firmada('EXPLAIN SELECT nombre FROM clientes', $GLOBALS['CLAVE_LECTURA']);
    $r2 = firmada('SHOW CREATE TABLE clientes', $GLOBALS['CLAVE_LECTURA']);
    return ($r1[0]['acceso'] ?? null) !== null && str_starts_with($r2[0]['sql'] ?? '', 'CREATE TABLE clientes') ?: json_encode([$r1, $r2]);
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
chk('lectura puede usar todos los SHOW, que no modifican nada', function () {
    // La lista de sentencias permitidas a una clave de lectura se mantiene a
    // mano, y es fácil añadir un SHOW nuevo al parser y olvidarla: eso le pasó a
    // SHOW INDEXES, que una clave de solo lectura no podía ejecutar pese a ser
    // estrictamente lectura. Aquí se recorren todos.
    $sentencias = [
        'SHOW TABLES', 'SHOW VIEWS', 'SHOW SCHEMA clientes', 'SHOW COLUMNS FROM clientes',
        'SHOW KEYS FROM clientes', 'SHOW TRIGGERS', 'SHOW INDEXES FROM clientes',
    ];
    foreach ($sentencias as $sql) {
        $r = firmada($sql, $GLOBALS['CLAVE_LECTURA']);
        if (isset($r['error'])) {
            return "$sql -> " . $r['error'];
        }
    }
    return true;
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
    // Se busca por patrón y no por la fecha de hoy: el fichero lo nombra el
    // proceso de la API, que fija la zona horaria de config.php, y cerca de
    // medianoche puede estar ya en el día siguiente respecto a quien comprueba.
    $ficheros = (array)glob($raizDatos . '/api/peticiones-*.json');
    if ($ficheros === []) { return 'no se creó el histórico'; }
    $fichero = (string)end($ficheros);
    $lineas = array_filter(explode("\n", (string)file_get_contents($fichero)));
    $ultima = json_decode((string)end($lineas), true);
    return isset($ultima['ip'], $ultima['ms'], $ultima['filas'], $ultima['op'])
        && $ultima['ip'] === '10.0.0.7' && is_float($ultima['ms']) || is_int($ultima['ms'] ?? null);
});
chk('el log del motor guarda la etiqueta de la API key', function () use ($raizDatos) {
    $ficheros = (array)glob($raizDatos . '/logs/consultas-*.json');
    if ($ficheros === []) { return 'no se creó el log del motor'; }
    $fichero = (string)end($ficheros);
    $lineas = array_filter(explode("\n", (string)file_get_contents($fichero)));
    foreach ($lineas as $linea) {
        $e = json_decode($linea, true);
        if (($e['origen'] ?? '') === 'Mi aplicación' && $e['op'] === 'SELECT' && $e['ip'] === '10.0.0.7') {
            return true;
        }
    }
    return 'no se encontró la entrada esperada';
});

chk('una lectura por la API no carga Logger, que no va a escribir nada; una escritura sí, y queda con la etiqueta de su API key y su IP (2.10: la API lo cargaba en cada petición para decirle quién era)', function () use ($raizDatos) {
    global $CLAVE_APP;
    $cargadas = static function (callable $hacer) use ($raizDatos): array {
        @unlink("$raizDatos/clases.json");
        $r = $hacer();
        return [$r, (array)json_decode((string)@file_get_contents("$raizDatos/clases.json"), true)];
    };
    [$lee, $clasesLee] = $cargadas(static fn() => firmada('SELECT COUNT(*) AS n FROM clientes', $CLAVE_APP, null, null, [], ['__clases' => 1]));
    [$esc, $clasesEsc] = $cargadas(static fn() => firmada("INSERT INTO clientes (nombre, ciudad) VALUES ('Registro', 'Elche')", $CLAVE_APP, null, null, [], ['__clases' => 1]));
    $linea = null;
    foreach ((array)glob($raizDatos . '/logs/consultas-*.json') as $f) {
        foreach (array_filter(explode("\n", (string)file_get_contents((string)$f))) as $l) {
            $e = json_decode($l, true);
            if (($e['op'] ?? '') === 'INSERT' && str_contains((string)($e['sql'] ?? ''), "'Registro'")) {
                $linea = $e;
            }
        }
    }
    $nombre = cuentaDe($CLAVE_APP)['nombre'] ?? '?';
    return isset($lee[0]['n']) && ($esc['success'] ?? false) === true && $clasesLee !== [] && !in_array('Logger', $clasesLee, true)
        && in_array('Logger', $clasesEsc, true) && ($linea['origen'] ?? null) === $nombre && ($linea['ip'] ?? null) === '10.0.0.7'
        ?: json_encode([$lee, $esc, in_array('Logger', $clasesLee, true), in_array('Logger', $clasesEsc, true), $linea, $nombre]);
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
    global $CLAVE_ADMIN, $base;
    $sql = 'SELECT COUNT(*) AS n FROM param WHERE nombre = ?';
    $ts  = (string)time();
    $r = peticion([
        'api_key'   => $CLAVE_ADMIN,
        'db'        => $base,
        'sql'       => $sql,
        'params'    => '["otro"]',                                  // manipulado
        'timestamp' => $ts,
        'token'     => hash_hmac('sha256',
            '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts . '|' . $sql . '["Ana"]¿', secretoDe($CLAVE_ADMIN)),
    ]);
    return str_contains($r['error'] ?? '', 'Token inválido');
});
chk('número de ? distinto al de parámetros', function () {
    global $CLAVE_ADMIN;
    $r = firmada('SELECT * FROM param WHERE nombre = ?', $CLAVE_ADMIN, null, null, ['Ana', 'Luis']);
    return str_contains($r['error'] ?? '', 'Error en la consulta');
});
chk('parámetros que no son una lista JSON', function () {
    global $CLAVE_ADMIN, $base;
    $sql = 'SELECT 1 AS uno';
    $ts  = (string)time();
    $pr  = '{"a":1}';
    $r = peticion([
        'api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => $sql, 'params' => $pr, 'timestamp' => $ts,
        'token'   => hash_hmac('sha256',
            '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts . '|' . $sql . $pr . '¿', secretoDe($CLAVE_ADMIN)),
    ]);
    return str_contains($r['error'] ?? '', 'lista JSON');
});
chk('un parámetro fuera de rango (1e400, que llega como infinito) se rechaza con un mensaje claro, suelto y en un lote (2.9: «Error interno: Inf and NaN cannot be JSON encoded»)', function () {
    global $CLAVE_ADMIN, $base;
    $firmar = static function (array $campos, string $firmado) use ($CLAVE_ADMIN, $base): array {
        $ts = (string)time();
        return peticion(['api_key' => $CLAVE_ADMIN, 'db' => $base, 'timestamp' => $ts,
            'token' => hash_hmac('sha256', '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts . '|' . $firmado . '¿', secretoDe($CLAVE_ADMIN))] + $campos);
    };
    $r = [];
    foreach (['[1e400]', '[-1e400]', '[1, 2e999]'] as $pr) {
        $r[] = $firmar(['sql' => 'SELECT ? AS v', 'params' => $pr], 'SELECT ? AS v' . $pr);
    }
    $lote = '[{"sql":"SELECT ? AS v","params":[1e400]}]';
    $r[] = $firmar(['lote' => $lote], $lote);
    foreach ($r as $x) {
        if (!str_contains($x['error'] ?? '', 'fuera de rango')) { return json_encode($r, JSON_UNESCAPED_UNICODE); }
    }
    // Un decimal grande pero finito sigue valiendo
    $bien = $firmar(['sql' => 'SELECT ? AS v', 'params' => '[1e300]'], 'SELECT ? AS v[1e300]');
    return ($bien[0]['v'] ?? null) === 1.0E+300 ?: json_encode($bien);
});
chk('un resultado infinito (SELECT 1e400, 1e300 * 1e300) da un error que lo dice, suelto y en un lote (2.10: «Error interno»)', function () {
    global $CLAVE_ADMIN, $base;
    $r = [firmada('SELECT 1e400 AS v', $CLAVE_ADMIN), firmada('SELECT 1e300 * 1e300 AS v', $CLAVE_ADMIN)];
    $ts = (string)time();
    $lote = '["SELECT -1e400 AS v"]';
    $r[] = peticion(['api_key' => $CLAVE_ADMIN, 'db' => $base, 'timestamp' => $ts, 'lote' => $lote,
        'token' => hash_hmac('sha256', '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts . '|' . $lote . '¿', secretoDe($CLAVE_ADMIN))]);
    foreach ($r as $x) {
        if (!str_contains($x['error'] ?? '', 'fuera de rango')) { return json_encode($r, JSON_UNESCAPED_UNICODE); }
    }
    return true;
});
chk('sin parámetros la firma de siempre sigue valiendo', function () {
    global $CLAVE_ADMIN;
    $r = firmada('SELECT COUNT(*) AS n FROM param', $CLAVE_ADMIN);
    return ($r[0]['n'] ?? null) === 2;
});
chk('el log guarda los parámetros', function () {
    global $CLAVE_ADMIN, $raizDatos;
    // Una escritura: por defecto (2.9) las lecturas no se registran
    firmada('UPDATE param SET nombre = ? WHERE nombre = ?', $CLAVE_ADMIN, null, null, ['Ana', 'Ana']);
    foreach ((array)glob("$raizDatos/logs/consultas-*.json") as $f) {
        foreach (array_reverse(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $l) {
            $e = json_decode($l, true);
            if (is_array($e) && ($e['params'] ?? null) === ['Ana', 'Ana']) { return true; }
        }
    }
    return 'no se encontró la entrada con params';
});
chk('borrar la tabla de parámetros', function () {
    global $CLAVE_ADMIN;
    return (firmada('DROP TABLE param', $CLAVE_ADMIN)['success'] ?? false) === true;
});

echo "\n== Secreto por clave ==\n";
chk('cada clave firma con el suyo', function () {
    global $CLAVE_ADMIN, $CLAVE_APP;
    return secretoDe($CLAVE_ADMIN) !== '' && secretoDe($CLAVE_APP) !== ''
        && secretoDe($CLAVE_ADMIN) !== secretoDe($CLAVE_APP)
        && ($firmada = firmada('SELECT 1 AS uno', $CLAVE_APP)) && ($firmada[0]['uno'] ?? null) === 1;
});
chk('una clave sin hmac_secret se rechaza con un mensaje claro', function () {
    $clave = 'CLAVE_DE_PRUEBA_SIN_SECRETO_00000000000000000000000000000000';
    $sql   = 'SELECT 1 AS uno';
    $ts    = (string)time();
    $r = peticion([
        'api_key'   => $clave,
        'db'        => $GLOBALS['base'],
        'sql'       => $sql,
        'timestamp' => $ts,
        'token'     => hash_hmac('sha256',
            '+' . $clave . '|' . $GLOBALS['base'] . '|' . $ts . '|' . $sql . '¿', 'lo-que-sea'),
    ]);
    return str_contains($r['error'] ?? '', 'Configuración incompleta');
});
chk('con nonce (2.9), dos peticiones idénticas en el mismo segundo pasan las dos; repetir una se rechaza sin contar como fallo de la IP', function () {
    global $CLAVE_ADMIN, $base;
    $sql = 'SELECT 7 AS siete';
    $ts  = (string)time();
    $firmar = static function (string $nonce) use ($sql, $ts, $CLAVE_ADMIN, $base): array {
        return ['api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => $sql, 'timestamp' => $ts, 'nonce' => $nonce,
                'token' => hash_hmac('sha256', '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts . '|' . $nonce . '|' . $sql . '¿', secretoDe($CLAVE_ADMIN))];
    };
    $a = $firmar(bin2hex(random_bytes(8)));
    $b = $firmar(bin2hex(random_bytes(8)));
    $r1 = peticion($a, true);
    $r2 = peticion($b, true);
    if (($r1[0]['siete'] ?? null) !== 7 || ($r2[0]['siete'] ?? null) !== 7) {
        return 'una de las dos no pasó: ' . json_encode([$r1, $r2]);
    }
    // La misma petición doce veces: repetida, sí; pero la IP no se bloquea
    for ($i = 0; $i < 12; $i++) {
        $r = peticion($a, true);
        if (!str_contains($r['error'] ?? '', 'Token ya utilizado')) { return 'una repetida pasó: ' . json_encode($r); }
    }
    $c = peticion($firmar(bin2hex(random_bytes(8))), true);
    // Y un nonce que no es hexadecimal, o la firma sin él con nonce enviado, no valen
    $mal = peticion(['nonce' => 'no-es-hex'] + $firmar(bin2hex(random_bytes(8))), true);
    $sinFirmar = peticion(['nonce' => bin2hex(random_bytes(8))] + $firmar(''), true);
    return ($c[0]['siete'] ?? null) === 7 && str_contains($mal['error'] ?? '', 'Nonce no válido')
        && str_contains($sinFirmar['error'] ?? '', 'Token inválido') ?: json_encode([$c, $mal, $sinFirmar]);
});
chk('un lote (2.9): todas las sentencias o ninguna, un resultado por sentencia, con permisos y firma', function () {
    global $CLAVE_ADMIN, $CLAVE_LECTURA, $base;
    $lote = static function (array $lista, string $clave) use ($base): array {
        $l = (string)json_encode($lista, JSON_UNESCAPED_UNICODE);
        $ts = (string)time();
        $n = bin2hex(random_bytes(8));
        return peticion(['api_key' => $clave, 'db' => $base, 'lote' => $l, 'timestamp' => $ts, 'nonce' => $n,
            'token' => hash_hmac('sha256', '+' . $clave . '|' . $base . '|' . $ts . '|' . $n . '|' . $l . '¿', secretoDe($clave))]);
    };
    firmada('CREATE TABLE lote_api (id INTEGER PRIMARY KEY, n INTEGER UNIQUE)', $CLAVE_ADMIN);
    $bien = $lote(['INSERT INTO lote_api VALUES (1, 10)', ['sql' => 'INSERT INTO lote_api VALUES (?, ?)', 'params' => [2, 20]],
                   'SELECT COUNT(*) AS c FROM lote_api'], $CLAVE_ADMIN);
    $mal = $lote(['UPDATE lote_api SET n = n + 1', 'INSERT INTO lote_api VALUES (3, 11)'], $CLAVE_ADMIN);   // 11 ya existe tras el UPDATE
    $lectura = $lote(['SELECT 1 AS x', 'DELETE FROM lote_api'], $CLAVE_LECTURA);
    $ambos = peticion(['api_key' => $CLAVE_ADMIN, 'db' => $base, 'sql' => 'SELECT 1', 'lote' => '["SELECT 1"]', 'timestamp' => (string)time(), 'token' => 'x']);
    $final = firmada('SELECT id, n FROM lote_api ORDER BY id', $CLAVE_ADMIN);
    firmada('DROP TABLE lote_api', $CLAVE_ADMIN);
    // Una clave limitada a unas bases no ve las demás con SHOW DATABASES dentro de un lote
    $basesLimitada = $lote(['SHOW DATABASES'], $CLAVE_LECTURA);
    $basesAdmin = $lote(['SHOW DATABASES'], $CLAVE_ADMIN);
    return ($bien[2] ?? null) === [['c' => 2]] && ($bien[0]['filas'] ?? null) === 1 && str_contains($mal['error'] ?? '', 'UNIQUE')
        && str_contains($lectura['error'] ?? '', 'lectura') && str_contains($ambos['error'] ?? '', 'no los dos')
        && str_contains($basesLimitada['error'] ?? '', 'limitada') && in_array($base, array_column($basesAdmin[0] ?? [], 'base'), true)
        && $final === [['id' => 1, 'n' => 10], ['id' => 2, 'n' => 20]]
        ?: json_encode([$bien, $mal, $lectura, $ambos, $final, $basesLimitada, $basesAdmin], JSON_UNESCAPED_UNICODE);
});
chk('la base entra en la firma: no se puede reenviar contra otra', function () {
    global $CLAVE_ADMIN, $base;
    // Se firma para 'apibase' y se manda tal cual contra otra base. Antes de la
    // 2.0 esto colaba: la firma no cubría el campo 'db', así que bastaba con
    // cambiarlo para ejecutar la misma consulta donde no tocaba.
    $sql = 'SELECT 1 AS uno';
    $ts  = (string)time();
    $tok = hash_hmac('sha256',
        '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts . '|' . $sql . '¿', secretoDe($CLAVE_ADMIN));

    $r = peticion(['api_key' => $CLAVE_ADMIN, 'db' => 'otrabase',
                   'sql' => $sql, 'timestamp' => $ts, 'token' => $tok]);
    if (!str_contains($r['error'] ?? '', 'Token inválido')) {
        return 'aceptó la petición con la base cambiada: ' . json_encode($r);
    }
    // Y con la base correcta, la misma firma sí vale
    $ts2 = (string)time();
    $tok2 = hash_hmac('sha256',
        '+' . $CLAVE_ADMIN . '|' . $base . '|' . $ts2 . '|' . $sql . '¿', secretoDe($CLAVE_ADMIN));
    $ok = peticion(['api_key' => $CLAVE_ADMIN, 'db' => $base,
                    'sql' => $sql, 'timestamp' => $ts2, 'token' => $tok2]);
    return ($ok[0]['uno'] ?? null) === 1 ?: 'rechazó la petición legítima';
});
chk('el secreto de una clave no sirve para firmar por otra', function () {
    global $CLAVE_ADMIN;
    $ajeno = secretoDe($GLOBALS['CLAVE_APP']);
    $sql   = 'DROP DATABASE apibase';
    $ts    = (string)time();
    $r = peticion([
        'api_key'   => $CLAVE_ADMIN,
        'db'        => '',
        'sql'       => $sql,
        'timestamp' => $ts,
        'token'     => hash_hmac('sha256',
            '+' . $CLAVE_ADMIN . '|' . '' . '|' . $ts . '|' . $sql . '¿', $ajeno),
    ]);
    return str_contains($r['error'] ?? '', 'Token inválido');
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
chk('un rango mal escrito no deja pasar a nadie, igual en la API que en el panel (2.10)', function () {
    // «10.0.0.0/» y «10.0.0.0/foo» daban 0 bits: todas las IP pasaban
    $fn  = (string)file_get_contents(dirname(__DIR__) . '/api/jsonsqldb_api.php');
    $ini = strpos($fn, 'function ipPermitida');
    $codigo = '<?php ' . substr($fn, $ini, strpos($fn, "\n}", $ini) + 2 - $ini) . '
        require ' . var_export(dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php', true) . ';
        $casos = [["203.0.113.9", "10.0.0.0/"], ["203.0.113.9", "10.0.0.0/foo"], ["203.0.113.9", "10.0.0.0/33"],
                  ["203.0.113.9", "10.0.0.0/-1"], ["2001:db8::1", "2001:db8::/"], ["2001:db8::1", "2001:db8::/129"],
                  ["203.0.113.9", "0.0.0.0/0"], ["10.0.0.200", "10.0.0.128/25"], ["10.0.0.100", "10.0.0.128/25"],
                  ["10.0.0.7", " 10.0.0.7 "], ["2001:db8::1", "2001:db8::/32"], ["203.0.113.9", "no-es-ip"]];
        foreach ($casos as [$ip, $e]) {
            echo (int)ipPermitida($ip, [$e]), (int)util_ip_permitida($ip, [$e]), (int)(util_rango_ip($e) !== null), ",";
        }';
    $tmp = sys_get_temp_dir() . '/ip_' . getmypid() . '.php';
    file_put_contents($tmp, $codigo);
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1'));
    @unlink($tmp);
    return $salida === '000,000,000,000,000,000,111,111,001,111,111,000,' ?: $salida;
});

echo "\n== Clientes de ejemplo ==\n";
chk('su cuenta existe, con escritura sobre pruebas y nada más', function () {
    $c = $GLOBALS['API_KEYS']['Clientes de ejemplo'] ?? null;
    return is_array($c) && $c['permiso'] === 'escritura' && $c['bases'] === ['pruebas'] ?: json_encode($c);
});
chk('ningún cliente lleva una clave escrita: están en api/ y la web podría servirlos', function () {
    // configurar.php ya ha pasado por aquí (el CI lo ejecuta antes): hasta
    // 2.7.2 escribía la clave dentro, y dos de los tres se podían descargar
    foreach (['php', 'py', 'ps1'] as $ext) {
        $texto = (string)file_get_contents(__DIR__ . "/../api/cliente_ejemplo.$ext");
        if (!str_contains($texto, 'CHANGE_ME_EXAMPLE_API_KEY') || !str_contains($texto, 'CHANGE_ME_EXAMPLE_SECRET')) {
            return "cliente_ejemplo.$ext no tiene sus marcadores";
        }
        foreach ($GLOBALS['API_KEYS'] as $cuenta) {
            if (is_array($cuenta) && strlen((string)($cuenta['key'] ?? '')) > 20 && str_contains($texto, (string)$cuenta['key'])) {
                return "cliente_ejemplo.$ext lleva una clave de verdad";
            }
        }
    }
    return true;
});
chk('los tres toman la clave y el secreto de las mismas variables de entorno', function () {
    foreach (['php', 'py', 'ps1'] as $ext) {
        $texto = (string)file_get_contents(__DIR__ . "/../api/cliente_ejemplo.$ext");
        foreach (['JSONSQLDB_API_KEY', 'JSONSQLDB_HMAC_SECRET', 'JSONSQLDB_URL'] as $var) {
            if (!str_contains($texto, $var)) { return "cliente_ejemplo.$ext no lee $var"; }
        }
    }
    require_once __DIR__ . '/../api/cliente_ejemplo.php';
    putenv('JSONSQLDB_API_KEY=clave-del-entorno');
    putenv('JSONSQLDB_HMAC_SECRET=secreto-del-entorno');
    $cli = JsonSqlDbCliente::pruebas();
    putenv('JSONSQLDB_API_KEY');
    putenv('JSONSQLDB_HMAC_SECRET');
    // Las propiedades son privadas: se leen desde dentro de la clase con un
    // cierre ligado a ella. Con Reflection, PHP 8.0 exigía setAccessible(), que
    // en 8.5 avisa de obsoleta; así vale igual en todas las versiones
    $leer = static fn(string $p) => Closure::bind(static fn(JsonSqlDbCliente $c) => $c->$p, null, JsonSqlDbCliente::class)($cli);
    return $leer('apiKey') === 'clave-del-entorno' && $leer('secreto') === 'secreto-del-entorno' ?: 'el cliente PHP no las usa';
});
chk('el atajo del cliente PHP acepta un certificado propio solo en el propio equipo (2.9: también en un servidor de verdad)', function () {
    require_once __DIR__ . '/../api/cliente_ejemplo.php';
    $autofirmado = static function (string $url): bool {
        putenv("JSONSQLDB_URL=$url");
        $cli = JsonSqlDbCliente::pruebas();
        putenv('JSONSQLDB_URL');
        return Closure::bind(static fn(JsonSqlDbCliente $c) => $c->autofirmado, null, JsonSqlDbCliente::class)($cli);
    };
    $r = [$autofirmado('https://localhost/api/jsonsqldb_api.php'), $autofirmado('https://127.0.0.1:8443/x'), $autofirmado('https://api.ejemplo.es/x')];
    return $r === [true, true, false] ?: json_encode($r);
});
chk('la web no sirve los clientes de ejemplo (Apache, IIS, nginx)', function () {
    $raiz = dirname(__DIR__);
    return str_contains((string)file_get_contents("$raiz/api/.htaccess"), 'cliente_ejemplo\\.')
        && str_contains((string)file_get_contents("$raiz/api/web.config"), 'cliente_ejemplo.')
        && str_contains((string)file_get_contents("$raiz/nginx/jsonsqldb.conf"), 'api/cliente_ejemplo')
        ?: 'falta la regla en alguno';
});
chk('el cliente Python firma igual que el de PHP', function () {
    global $base;
    if (trim((string)shell_exec('command -v python3')) === '') {
        echo "       (omitida: no hay python3)\n";
        return true;
    }
    // Se le pide a Python que calcule el token de una petición concreta y se
    // compara con el que calcula PHP: si no coincidieran, la API los rechazaría
    $sql    = "SELECT * FROM clientes WHERE nombre = ?";
    $valor  = "O'Donnell";
    $clave  = 'K';
    $secre  = 'S';
    $ts     = '1700000000';

    $codigo = <<<'PY'
import sys, json, hmac, hashlib
sql, valor, clave, secre, ts, base = sys.argv[1:7]
params = json.dumps([valor], ensure_ascii=False, separators=(",", ":"))
msg = f"+{clave}|{base}|{ts}|{sql}{params}¿"
print(params)
print(hmac.new(secre.encode(), msg.encode(), hashlib.sha256).hexdigest())
PY;
    $tmp = sys_get_temp_dir() . '/firma_' . getmypid() . '.py';
    file_put_contents($tmp, $codigo);
    $salida = (string)shell_exec('python3 ' . escapeshellarg($tmp) . ' '
        . escapeshellarg($sql) . ' ' . escapeshellarg($valor) . ' '
        . escapeshellarg($clave) . ' ' . escapeshellarg($secre) . ' ' . escapeshellarg($ts) . ' '
        . escapeshellarg($base) . ' 2>&1');
    @unlink($tmp);

    [$paramsPy, $tokenPy] = array_pad(explode("\n", trim($salida)), 2, '');
    $tokenPhp = hash_hmac('sha256',
        '+' . $clave . '|' . $base . '|' . $ts . '|' . $sql . $paramsPy . '¿', $secre);

    return $tokenPy === $tokenPhp ?: "python: $tokenPy / php: $tokenPhp";
});
/**
 * La API servida de verdad, por HTTP (php -S), con la configuración de las
 * pruebas y lo que se le pase para php.ini ($ini, de dos en dos: '-d',
 * 'clave=valor'): su URL, y con qué pararla. Cada vez en otro puerto.
 *
 * @return array{0: string, 1: Closure}
 */
function servidorApi(array $ini = []): array
{
    global $raizDatos;
    static $veces = 0;
    $prepend = "$raizDatos/_prepend_servidor.php";
    file_put_contents($prepend, "<?php\ndefine('JSONSQLDB_DATA_PATH', " . var_export($raizDatos, true) . ");\n"
        . "define('JSONSQLDB_LOG_PATH', " . var_export("$raizDatos/logs", true) . ");\n"
        . "define('API_ESTADO_PATH', " . var_export("$raizDatos/api", true) . ");\n"
        . "define('JSONSQLDB_API_CONFIG', " . var_export(__DIR__ . '/_config_api.php', true) . ");\n");
    $puerto = 9600 + (getmypid() + $veces++) % 300;
    $servidor = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, ...$ini, '-S', "127.0.0.1:$puerto", '-t', dirname(__DIR__)],
        [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $tub);
    for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $puerto, $e, $m, 0.3); $i++) { usleep(100000); }
    return ["http://127.0.0.1:$puerto/api/jsonsqldb_api.php", static function () use ($servidor, $prepend): void {
        proc_terminate($servidor);
        proc_close($servidor);
        @unlink($prepend);
    }];
}
chk('los tres clientes de ejemplo hacen un lote contra la API por HTTP: todas las sentencias, o ninguna si una falla (2.9)', function () use ($raizDatos, $base) {
    global $API_KEYS;
    $clave = $GLOBALS['CLAVE_APP'];                     // la de una aplicación: escribe, sin tocar la estructura
    foreach (['lote_php', 'lote_py', 'lote_ps'] as $t) { firmada("CREATE TABLE $t (id INTEGER PRIMARY KEY, v TEXT)", $GLOBALS['CLAVE_ADMIN']); }
    $secreto = '';
    foreach ($API_KEYS as $c) { if (($c['key'] ?? '') === $clave) { $secreto = (string)$c['hmac_secret']; } }
    [$url, $parar] = servidorApi();
    try {
        $mal = [];
        // PHP
        $cli = new JsonSqlDbCliente($url, $clave, $secreto, $base);
        $r = $cli->lote([['INSERT INTO lote_php VALUES (?, ?)', [1, "O'Ñu 😀"]], 'INSERT INTO lote_php VALUES (2, \'b\')', 'SELECT v FROM lote_php ORDER BY id']);
        if (count($r) !== 3 || ($r[0]['filas'] ?? null) !== 1 || $r[2] !== [['v' => "O'Ñu 😀"], ['v' => 'b']]) { $mal[] = 'php: ' . json_encode($r); }
        try { $cli->lote([['INSERT INTO lote_php VALUES (?, ?)', [3, 'c']], ['INSERT INTO lote_php VALUES (?, ?)', [1, 'repetida']]]); $mal[] = 'php: el lote que falla no falló'; }
        catch (RuntimeException) { }
        if ($cli->consultar('SELECT COUNT(*) AS n FROM lote_php') !== [['n' => 2]]) { $mal[] = 'php: el lote que falló dejó algo'; }
        // Python, sin escribir su caché de bytecode junto al cliente
        if (trim((string)shell_exec('command -v python3')) !== '') {
            $py = 'import sys, json; sys.dont_write_bytecode = True; sys.path.insert(0, ' . json_encode(dirname(__DIR__) . '/api', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ')'
                . "\nfrom cliente_ejemplo import JsonSqlDbCliente, JsonSqlDbError"
                . "\ncli = JsonSqlDbCliente(sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4])"
                . "\nr = cli.lote([('INSERT INTO lote_py VALUES (?, ?)', [1, \"O'Ñu\"]), 'INSERT INTO lote_py VALUES (2, \\'b\\')', 'SELECT v FROM lote_py ORDER BY id'])"
                . "\ntry:\n    cli.lote([('INSERT INTO lote_py VALUES (?, ?)', [3, 'c']), ('INSERT INTO lote_py VALUES (?, ?)', [1, 'x'])])\n    fallo = False"
                . "\nexcept JsonSqlDbError:\n    fallo = True"
                . "\nprint(json.dumps([len(r), r[2], fallo, cli.consultar('SELECT COUNT(*) AS n FROM lote_py')], ensure_ascii=False))";
            $f = "$raizDatos/_cliente.py";
            file_put_contents($f, $py);
            $salida = trim((string)shell_exec('python3 -B ' . escapeshellarg($f) . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($clave) . ' '
                . escapeshellarg($secreto) . ' ' . escapeshellarg($base) . ' 2>&1'));
            @unlink($f);
            if ($salida !== '[3, [{"v": "O\'Ñu"}, {"v": "b"}], true, [{"n": 2}]]') { $mal[] = 'python: ' . $salida; }
        } else {
            echo "       (python: omitido, no hay python3)\n";
        }
        // PowerShell (pwsh), si está
        if (trim((string)shell_exec('command -v pwsh')) !== '') {
            // Al cargarse, el cliente lanza su ejemplo (SHOW TABLES de la base
            // 'pruebas', que aquí no existe): su error, fuera
            $ps = str_replace('{CLIENTE}', dirname(__DIR__) . '/api/cliente_ejemplo.ps1', <<<'PS1'
$env:JSONSQLDB_URL = $args[0]; $env:JSONSQLDB_API_KEY = $args[1]; $env:JSONSQLDB_HMAC_SECRET = $args[2]
. '{CLIENTE}' 2>$null | Out-Null
Set-JsonSqlDbConexion -Base $args[3]
$r = API-SQL-Lote @(@{ Sql = "INSERT INTO lote_ps VALUES (?, ?)"; Parametros = @(1, "O'Ñu") }, "INSERT INTO lote_ps VALUES (2, 'b')", "SELECT v FROM lote_ps ORDER BY id")
$falla = API-SQL-Lote @(@{ Sql = "INSERT INTO lote_ps VALUES (?, ?)"; Parametros = @(3, "c") }, @{ Sql = "INSERT INTO lote_ps VALUES (?, ?)"; Parametros = @(1, "x") }) 2>$null
$uno = API-SQL-Lote "SELECT v FROM lote_ps WHERE id = 99"
$vacio = @(API-SQL-JSON "SELECT v FROM lote_ps WHERE id = 99")
$n = API-SQL-JSON "SELECT COUNT(*) AS n FROM lote_ps"
ConvertTo-Json -Compress -Depth 5 -InputObject @($r.Count, $r[2], ($null -eq $falla), $uno.Count, $vacio.Count, $n[0].n)
PS1);
            $f = "$raizDatos/_cliente.ps1";
            file_put_contents($f, "\xEF\xBB\xBF" . $ps);
            $salida = trim((string)shell_exec('pwsh -NoProfile -NonInteractive -File ' . escapeshellarg($f) . ' ' . escapeshellarg($url) . ' '
                . escapeshellarg($clave) . ' ' . escapeshellarg($secreto) . ' ' . escapeshellarg($base) . ' 2>&1'));
            @unlink($f);
            if ($salida !== '[3,[{"v":"O\'Ñu"},{"v":"b"}],true,1,0,2]') { $mal[] = 'powershell: ' . $salida; }
        } else {
            echo "       (powershell: omitido, no hay pwsh)\n";
        }
        return $mal === [] ?: implode(' | ', $mal);
    } finally {
        $parar();
    }
});
chk('por HTTP, un texto que no es UTF-8 sale en la respuesta de error y en el registro con � en su lugar (2.9: respuesta vacía con HTTP 200, y la petición sin anotar)', function () use ($raizDatos, $base) {
    $clave = $GLOBALS['CLAVE_APP'];
    [$url, $parar] = servidorApi();
    try {
        // Bytes que no son UTF-8 en la SQL y en el User-Agent: así llegan, sin pasar por JSON
        $mandar = static function (string $sql, string $agente) use ($url, $clave, $base): array {
            $ts = (string)time();
            $post = ['api_key' => $clave, 'db' => $base, 'sql' => $sql, 'params' => '', 'timestamp' => $ts,
                     'token' => hash_hmac('sha256', '+' . $clave . '|' . $base . '|' . $ts . '|' . $sql . '¿', secretoDe($clave))];
            $cuerpo = (string)@file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: $agente", 'content' => http_build_query($post)]]));
            return [$cuerpo, json_decode($cuerpo, true)];
        };
        [$texto, $error] = $mandar("SELECT * FROM \"no\xffexiste\"", 'pruebas-jsonsqldb');
        [, $uno] = $mandar('SELECT 1 AS uno', "agente\xff");
        $lineas = [];
        foreach ((array)glob($raizDatos . '/api/peticiones-*.json') as $f) {
            foreach (file((string)$f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $lineas[] = json_decode($l, true); }
        }
        $anotado = static fn(string $campo, string $texto): bool => array_filter($lineas, static fn($e): bool => str_contains((string)($e[$campo] ?? ''), $texto)) !== [];
        return str_contains((string)($error['error'] ?? ''), "no\u{FFFD}existe") && ($uno[0]['uno'] ?? null) === 1
            && $anotado('error', "no\u{FFFD}existe") && $anotado('ua', "agente\u{FFFD}")
            ?: json_encode([$texto, $uno, array_slice($lineas, -3)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } finally {
        $parar();
    }
});
echo "\n== Respuesta de un SELECT escrita fila a fila (2.7.4) ==\n";
chk('un SELECT grande sale por la API igual por los dos caminos: tal cual, ordenado, agrupado, con LIMIT, vacío y de la caché', function () {
    // Las filas de un SELECT sin ORDER BY se escriben como JSON según salen;
    // las que llegan enteras (ordenadas, agrupadas, de la caché) se escriben
    // de una vez. Lo que sale por un camino se comprueba con el otro
    $admin = $GLOBALS['CLAVE_ADMIN'];
    firmada('CREATE TABLE fila_a_fila (id INTEGER PRIMARY KEY, t TEXT, n DOUBLE, z INTEGER)', $admin);
    $v = [];
    for ($i = 1; $i <= 3000; $i++) {
        $v[] = "($i, 'ñ \"$i\" 😀', " . ($i % 7 === 0 ? 'NULL' : $i / 4) . ', ' . ($i % 5) . ')';
    }
    foreach (array_chunk($v, 250) as $trozo) {                                       // la API limita el tamaño de la SQL
        firmada('INSERT INTO fila_a_fila VALUES ' . implode(',', $trozo), $admin);
    }
    $ordenadas = firmada('SELECT * FROM fila_a_fila ORDER BY id', $admin);           // de una vez
    $tal       = firmada('SELECT * FROM fila_a_fila', $admin);                       // fila a fila
    $mal = [];
    if (count($tal) !== 3000 || $tal !== $ordenadas) { $mal[] = 'tal cual'; }
    if (($tal[2]['t'] ?? null) !== 'ñ "3" 😀' || !array_key_exists('n', $tal[6] ?? []) || $tal[6]['n'] !== null || ($tal[1]['n'] ?? null) !== 0.5) { $mal[] = 'valores'; }
    $z2 = array_values(array_map(static fn($f) => ['id' => $f['id'], 't' => $f['t']],
        array_filter($ordenadas, static fn($f) => $f['z'] === 2)));
    if (firmada('SELECT id, t FROM fila_a_fila WHERE z = 2', $admin) !== $z2) { $mal[] = 'WHERE'; }
    if (firmada('SELECT * FROM fila_a_fila LIMIT 10 OFFSET 2990', $admin) !== array_slice($ordenadas, 2990, 10)) { $mal[] = 'LIMIT'; }
    if (firmada('SELECT * FROM fila_a_fila WHERE id < 0', $admin) !== []) { $mal[] = 'vacío'; }
    $grupos = firmada('SELECT z, COUNT(*) AS c FROM fila_a_fila GROUP BY z ORDER BY z', $admin);
    if (array_column($grupos, 'c') !== [600, 600, 600, 600, 600]) { $mal[] = 'GROUP BY'; }
    if (firmada('SELECT * FROM fila_a_fila', $admin) !== $tal) { $mal[] = 'de la caché'; }
    firmada('DROP TABLE fila_a_fila', $admin);
    return $mal === [] ?: 'distinto: ' . implode(', ', $mal);
});

echo "\n== Límites de la API ==\n";
/** Ejecuta código contra un ApiStore en otro proceso, con los límites activos, y devuelve lo que imprime. */
function conLimites(string $codigo, string $constantes = ''): string
{
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_limites_' . getmypid();
    @mkdir($dir, 0775, true);
    $limpiar = static function () use ($dir): void {
        // El estado va en la subcarpeta estado/ desde la 2.9
        array_map('unlink', array_filter(array_merge((array)glob("$dir/*/*"), (array)glob("$dir/*")), 'is_file'));
        array_map('rmdir', array_filter((array)glob("$dir/*"), 'is_dir'));
    };
    $limpiar();
    $prog = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("RATE_LIMIT_ACTIVO", true); define("RATE_LIMIT_MAX", 1000);'
          . 'define("RATE_LIMIT_SECONDS", 3600); define("ANTI_REPLAY_ACTIVO", true); define("RATE_TIMESTAMP_DIFF", 300);'
          . $constantes . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
          . '$s = new JsonSQLDB\\ApiStore(' . var_export($dir, true) . ');' . $codigo;
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($prog) . ' 2>&1');
    $limpiar();
    @rmdir($dir);
    return trim($salida);
}
chk('el estado se reparte: cada petición reescribe solo los ficheros pequeños de su nonce y su IP (2.9)', function () {
    $r = conLimites('for ($i = 0; $i < 2000; $i++) { $s->nonceYContar("n$i", "10.0." . ($i % 40) . ".1"); }'
        . '$d = ' . var_export(sys_get_temp_dir() . '/jsonsqldb_test_limites_' . getmypid(), true) . ';'
        . '$max = 0; foreach (glob("$d/estado/n-*.json") as $f) { $max = max($max, count(json_decode(file_get_contents($f), true)["nonces"])); }'
        . 'echo json_encode([count(glob("$d/estado/n-*.json")), $max, $s->nonceYContar("n7", "9.9.9.9"), is_file("$d/estado.json")]);');
    [$ficheros, $max, $repetido, $unico] = json_decode($r, true) ?? [0, 0, null, null];
    // 2.000 nonces en ~256 ficheros: ninguno pasa de unas decenas
    return $ficheros > 200 && $max < 40 && $repetido === 'nonce' && $unico === false ?: $r;
});
chk('el estado se reescribe sin vaciar su fichero: si queda más corto, con espacios detrás, y se sigue leyendo bien (2.10)', function () {
    $d = sys_get_temp_dir() . '/jsonsqldb_test_limites_' . getmypid();
    // Un fichero con muchos nonces caducados: al anotar uno nuevo se purgan y lo que queda es más corto
    $r = conLimites('$f = ' . var_export("$d/estado/n-", true) . ' . substr(md5("nuevo"), 0, 2) . ".json"; @mkdir(dirname($f), 0775, true);'
        . '$viejos = []; for ($i = 0; $i < 200; $i++) { $viejos["v$i"] = time() - 100000; }'
        . 'file_put_contents($f, json_encode(["nonces" => $viejos]) . "\n"); clearstatcache(); $antes = filesize($f);'
        . '$a = $s->nonceYContar("nuevo", "1.1.1.1"); clearstatcache();'
        . '$e = json_decode(file_get_contents($f), true);'
        . 'echo json_encode([$a, filesize($f) === $antes, array_keys($e["nonces"] ?? []), $s->nonceYContar("nuevo", "1.1.1.1")]);');
    return $r === '[true,true,["nuevo"],"nonce"]' ?: $r;
});
chk('un nonce del estado.json de antes de la 2.9 se sigue rechazando unos minutos, y luego ese fichero se borra', function () {
    $d = sys_get_temp_dir() . '/jsonsqldb_test_limites_' . getmypid();
    $r = conLimites('file_put_contents(' . var_export("$d/estado.json", true) . ', json_encode(["nonces" => ["viejo" => time()]]));'
        . '$a = $s->nonceYContar("viejo", "1.1.1.1");'
        . 'touch(' . var_export("$d/estado.json", true) . ', time() - 1000);'
        . 'echo json_encode([$a, $s->nonceYContar("otro", "1.1.1.1"), is_file(' . var_export("$d/estado.json", true) . ')]);');
    return $r === '["nonce",true,false]' ?: $r;
});
chk('los fallos de autenticación bloquean solo a la IP que falla, no a todos', function () {
    // Hasta 2.7.2, 30 fallos de cualquiera cerraban la API a todo el mundo un día
    $r = conLimites('for ($i = 0; $i < 40; $i++) { $s->fallo("6.6.6.6"); }'
        . 'echo json_encode([$s->ipBloqueada("6.6.6.6"), $s->ipBloqueada("7.7.7.7")]);', 'define("RATE_LIMIT_FALLOS_IP", 5);');
    return $r === '[true,false]' ?: $r;
});
chk('una IPv6 cuenta por su red /64: los fallos y las peticiones de sus direcciones van juntos, y otra red no se toca (2.9: cambiando de dirección se saltaba el límite)', function () {
    $estado = var_export(sys_get_temp_dir() . '/jsonsqldb_test_limites_' . getmypid() . '/estado', true);
    $r = conLimites('for ($i = 1; $i <= 5; $i++) { $s->fallo("2001:db8:1:2::$i"); }'
        . '$s->nonceYContar("a", "2001:db8:9:9::1"); $s->nonceYContar("b", "2001:db8:9:9:ffff::2"); $s->nonceYContar("c", "::ffff:6.6.6.6");'
        . '$cuentas = []; foreach (glob(' . $estado . ' . "/i-*.json") as $f) { foreach (json_decode(file_get_contents($f), true)["ips"] ?? [] as $ip => $m) { $cuentas[$ip] = count($m); } } ksort($cuentas);'
        . 'echo json_encode([$s->ipBloqueada("2001:db8:1:2:abcd::99"), $s->ipBloqueada("2001:db8:1:3::1"), $cuentas]);',
        'define("RATE_LIMIT_FALLOS_IP", 5);');
    return $r === '[true,false,{"2001:db8:1:2::\/64":5,"2001:db8:9:9::\/64":2,"6.6.6.6":1}]' ?: $r;
});
chk('con una configuración de antes de 2.7.3, sin las constantes nuevas, se bloquea a los 10 fallos', function () {
    $r = conLimites('for ($i = 0; $i < 9; $i++) { $s->fallo("6.6.6.6"); } $a = $s->ipBloqueada("6.6.6.6");'
        . '$s->fallo("6.6.6.6"); echo json_encode([$a, $s->ipBloqueada("6.6.6.6")]);');
    return $r === '[false,true]' ?: $r;
});
chk('el token de un cliente con el reloj adelantado se recuerda hasta que deja de valer, no desde que llegó (2.9: se podía repetir)', function () {
    $estado = var_export(sys_get_temp_dir() . '/jsonsqldb_test_limites_' . getmypid() . '/estado', true);
    $r = conLimites('$hora = time() + 250; $s->nonceYContar("adelantado", "1.1.1.1", "", $hora);'
        . '$n = 0; foreach (glob(' . $estado . ' . "/n-*.json") as $f) { $n = max($n, (int)(json_decode(file_get_contents($f), true)["nonces"]["adelantado"] ?? 0)); }'
        . 'echo $n >= $hora ? "bien" : "guardado con $n, y el token vale hasta " . ($hora + RATE_TIMESTAMP_DIFF);');
    return $r === 'bien' ?: $r;
});
chk('una clave admin, la del panel, no gasta el cupo de su IP; las demás sí (2.10)', function () {
    // El panel llama desde la IP de su servidor y agotaba las 150 del día
    global $CLAVE_ADMIN, $base;
    $r = [];
    foreach (['admin' => $CLAVE_ADMIN, 'app' => $GLOBALS['CLAVE_APP']] as $quien => $clave) {
        for ($i = 0; $i < 3; $i++) {
            $sql = "SELECT $i AS n";
            $ts  = (string)time();
            $res = peticion(['__cupo' => 2, 'api_key' => $clave, 'db' => $base, 'sql' => $sql, 'params' => '', 'timestamp' => $ts,
                'token' => hash_hmac('sha256', '+' . $clave . '|' . $base . '|' . $ts . '|' . $sql . '¿', secretoDe($clave))], true);
            $r[$quien][] = $res['error'] ?? 'ok';
        }
    }
    return $r === ['admin' => ['ok', 'ok', 'ok'], 'app' => ['ok', 'ok', 'Límite de peticiones superado']] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('si el estado de la API o un registro no se pueden escribir, se cierra el paso y se dice una vez en el registro de errores de PHP (2.10: en silencio)', function () {
    // Un fichero donde debería estar la carpeta del estado: ni root puede crearla
    $d   = sys_get_temp_dir() . '/jsonsqldb_test_limites_' . getmypid();
    $log = sys_get_temp_dir() . '/jsonsqldb_test_errorlog_' . getmypid() . '.txt';
    $r = conLimites('ini_set("error_log", ' . var_export($log, true) . '); file_put_contents(' . var_export("$d/estado", true) . ', "x");'
        . 'echo json_encode([$s->nonceYContar("a", "1.1.1.1"), $s->nonceYContar("a", "1.1.1.1")]);'
        . 'JsonSQLDB\Logger::noSeEscribe("/no/existe.json"); JsonSQLDB\Logger::noSeEscribe("/no/existe.json");');
    $lineas = is_file($log) ? file($log) : [];
    @unlink($log);
    $estado = count(preg_grep('#no se puede escribir .*estado/n-#', $lineas));
    $otro   = count(preg_grep('#/no/existe\.json#', $lineas));
    return $r === '[false,false]' && $estado === 1 && $otro === 1 ?: json_encode([$r, $lineas]);
});
chk('el cupo por API key cuenta las peticiones de esa clave desde cualquier IP', function () {
    $r = conLimites('echo json_encode([$s->nonceYContar("n1", "1.1.1.1", "A"), $s->nonceYContar("n2", "2.2.2.2", "A"),'
        . '$s->nonceYContar("n3", "3.3.3.3", "A"), $s->nonceYContar("n4", "3.3.3.3", "B")]);', 'define("RATE_LIMIT_POR_CLAVE", 2);');
    return $r === '[true,true,"limite",true]' ?: $r;
});
chk('con JSONSQLDB_API_ACTIVA a false no se atiende nada: ni una petición bien firmada, y no se anota ni se escribe (2.9)', function () use ($raizDatos, $base) {
    $peticiones = static fn(): int => count(explode("\n", implode('', array_map('file_get_contents', (array)glob($raizDatos . '/api/peticiones-*.json')))));
    $antes = $peticiones();
    $sql   = "INSERT INTO clientes (nombre, ciudad) VALUES ('Apagada', 'Elche')";
    $ts    = (string)time();
    $clave = $GLOBALS['CLAVE_APP'];
    $r = peticion(['__apagada' => 1, 'api_key' => $clave, 'db' => $base, 'sql' => $sql, 'params' => '', 'timestamp' => $ts,
        'token' => hash_hmac('sha256', '+' . $clave . '|' . $base . '|' . $ts . '|' . $sql . '¿', secretoDe($clave))]);
    $filas = firmada("SELECT COUNT(*) AS n FROM clientes WHERE nombre = 'Apagada'", $clave);
    return ($r['error'] ?? '') === 'La API está desactivada' && $peticiones() === $antes + 1 && ($filas[0]['n'] ?? null) === 0
        ?: json_encode([$r, $antes, $peticiones(), $filas], JSON_UNESCAPED_UNICODE);
});

chk('la API responde los decimales con las cifras justas aunque php.ini tenga otro serialize_precision (2.10: con 10, 0.30000000000000004 salía 0.3)', function () use ($base) {
    global $API_KEYS;
    $clave   = $GLOBALS['CLAVE_APP'];
    $secreto = '';
    foreach ($API_KEYS as $c) { if (($c['key'] ?? '') === $clave) { $secreto = (string)$c['hmac_secret']; } }
    [$url, $parar] = servidorApi(['-d', 'serialize_precision=10']);
    try {
        // Un SELECT sale fila a fila mientras trabaja el motor; un lote, después
        $cli = new JsonSqlDbCliente($url, $clave, $secreto, $base);
        $r   = [$cli->consultar('SELECT 0.1 + 0.2 AS x, 1.0 / 3 AS y'), $cli->lote(['SELECT 0.1 + 0.2 AS x, 1.0 / 3 AS y'])];
    } finally {
        $parar();
    }
    return $r === [[['x' => 0.1 + 0.2, 'y' => 1.0 / 3]], [[['x' => 0.1 + 0.2, 'y' => 1.0 / 3]]]] ?: json_encode($r);
});
chk('una consulta que pasa de TIME_LIMIT responde con un error en JSON, y el registro dice de qué clave y qué operación (2.9: respuesta vacía, y sin anotar)', function () use ($raizDatos, $base) {
    $ultima = static function () use ($raizDatos): array {
        $ficheros = (array)glob($raizDatos . '/api/peticiones-*.json');
        $lineas = array_filter(explode("\n", (string)file_get_contents((string)end($ficheros))));
        return (array)json_decode((string)end($lineas), true);
    };
    $sql = 'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 50000000) SELECT COUNT(*) AS n FROM c';
    $ts  = (string)time();
    $clave = $GLOBALS['CLAVE_APP'];
    $lenta = peticion(['__tiempo' => 1, 'api_key' => $clave, 'db' => $base, 'sql' => $sql, 'params' => '', 'timestamp' => $ts,
        'token' => hash_hmac('sha256', '+' . $clave . '|' . $base . '|' . $ts . '|' . $sql . '¿', secretoDe($clave))]);
    $tiempo = $ultima();
    firmada('SELECT * FROM no_existe', $clave);
    $mala = $ultima();
    // Con la firma mal, la clave no cuenta: sin origen, como las anónimas (el tope diario las deja fuera)
    peticion(['api_key' => $clave, 'db' => $base, 'sql' => 'SELECT 1', 'timestamp' => (string)time(), 'token' => str_repeat('0', 64)]);
    $sinFirma = $ultima();
    $nombre = cuentaDe($clave)['nombre'] ?? '';
    return str_contains($lenta['error'] ?? '', 'tiempo') && ($tiempo['origen'] ?? '') === $nombre && ($tiempo['op'] ?? '') === 'SELECT'
        && str_contains((string)($tiempo['error'] ?? ''), 'tiempo') && ($mala['origen'] ?? '') === $nombre && ($mala['op'] ?? '') === 'SELECT'
        && ($sinFirma['origen'] ?? null) === '' && str_contains((string)($sinFirma['error'] ?? ''), 'Token')
        ?: json_encode([$lenta, $tiempo, $mala, $sinFirma], JSON_UNESCAPED_UNICODE);
});
chk('lo que se anota de una petición rechazada va recortado', function () use ($raizDatos) {
    peticion(['api_key' => 'x', 'db' => str_repeat('a', 5000), 'sql' => 'SELECT 1', 'timestamp' => (string)time(), 'token' => str_repeat('0', 64)]);
    $ficheros = (array)glob($raizDatos . '/api/peticiones-*.json');
    $lineas = array_filter(explode("\n", (string)file_get_contents((string)end($ficheros))));
    $ultima = json_decode((string)end($lineas), true);
    return strlen((string)($ultima['db'] ?? 'x')) <= 64 ?: 'db de ' . strlen((string)$ultima['db']) . ' caracteres';
});
chk('con 20 ficheros llenos en el día, las peticiones sin clave válida ya no se anotan', function () {
    // Ficheros de 50 bytes: cada anotación llena uno. Las de alguien con clave
    // siguen anotándose; las anónimas, que cualquiera puede mandar, no
    $patron = var_export(sys_get_temp_dir() . '/jsonsqldb_test_limites_' . getmypid() . '/peticiones-*.json', true);
    $r = conLimites('for ($i = 0; $i < 25; $i++) { $s->registrar(["origen" => "", "error" => str_repeat("x", 60)]); }'
        . '$antes = count(glob(' . $patron . ') ?: []);'
        . '$s->registrar(["origen" => "cuenta", "error" => str_repeat("y", 60)]);'
        . 'echo $antes, ",", count(glob(' . $patron . ') ?: []);',
        'define("JSONSQLDB_LOG_MAX_SIZE", 50);');
    return $r === '20,21' ?: $r;
});

echo "\n== Reglas del servidor web ==\n";
chk('la regla de nginx de ficheros ocultos cubre también los de la raíz', function () {
    // nginx usa expresiones PCRE, las mismas de PHP: se prueban con las URL
    $conf = (string)file_get_contents(dirname(__DIR__) . '/nginx/jsonsqldb.conf');
    if (!preg_match('/^# --- Ficheros ocultos.*\nlocation ~ (\S+) \{/m', $conf, $m)) { return 'no se encuentra la regla'; }
    foreach (['/jsonsqldb/.env', '/jsonsqldb/.git/config', '/jsonsqldb/.htaccess', '/jsonsqldb/data/x/.rev'] as $uri) {
        if (!preg_match('#' . $m[1] . '#', $uri)) { return "no cubre $uri"; }
    }
    return !preg_match('#' . $m[1] . '#', '/jsonsqldb/api/jsonsqldb_api.php') ?: 'cubre también lo que no debe';
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

// Sin dejar nada en la carpeta temporal del sistema
$borrar = static function (string $dir) use (&$borrar): void {
    foreach (is_dir($dir) ? (array)scandir($dir) : [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? $borrar("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
};
$borrar($raizDatos);

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
