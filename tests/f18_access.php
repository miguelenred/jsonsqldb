<?php
declare(strict_types=1);

/**
 * Microsoft Access. Ejecutar: php tests/f18_access.php
 *
 * Aquí no hay Windows ni Access, así que se prueba todo lo que no es leer el
 * fichero con OLEDB:
 *  - Con PowerShell (pwsh, que traen los ejecutores de GitHub Actions): que
 *    el script jsonsqldbadmin/herramientas/access-to-jsonsqldb.ps1 no tiene
 *    errores de sintaxis, y que sus propias funciones escriben, para la base
 *    descrita en tests/access/volcado_de_prueba.ps1, exactamente
 *    tests/volcados/access.sql.
 *  - Que ese volcado se importa: tablas con autonumérico, clave compuesta,
 *    texto largo, moneda, fechas, sí/no y valores con comillas y emojis,
 *    índices, relaciones con CASCADE, y 8 consultas escritas como las guarda
 *    Access (comillas dobles, #fechas#, & , IIf, Nz, Mid, Format, DateAdd,
 *    DateDiff, comodines * y #, Tabla!Campo, JOIN entre paréntesis, TOP…),
 *    que tienen que dar lo que darían en Access.
 *  - Que lo que el panel exporta para Access se vuelve a importar igual.
 *
 * https://miguelenred.es/jsonsqldb
 */
use JsonSQLDB\Database;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f18_' . getmypid();
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_DATA_PATH', $raiz);
define('ADMIN_CONEXION', 'directa');
require dirname(__DIR__) . '/engine/bootstrap.php';
require dirname(__DIR__) . '/jsonsqldbadmin/config.dist.php';
require dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php';
foreach (['Idioma', 'Api', 'Traductor', 'Importar', 'Exportar'] as $clase) {
    require dirname(__DIR__) . "/jsonsqldbadmin/lib/$clase.php";
}
Idioma::elegir('es');

$ok = 0; $ko = 0;
function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
    } catch (Throwable $e) {
        $r = get_class($e) . ': ' . $e->getMessage();
    }
    if ($r === null) { echo "  --   $titulo (sin PowerShell)\n"; return; }
    if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
    else { $ko++; echo "  FALLO $titulo -> " . (is_string($r) ? $r : var_export($r, true)) . "\n"; }
}
function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
}
/** Tablas y vistas de una base, comparables. */
function estado(Database $bd): array {
    $r = [];
    foreach (array_merge(array_column($bd->consultar('SHOW TABLES'), 'tabla'), array_column($bd->consultar('SHOW VIEWS'), 'vista')) as $n) {
        $r[$n] = $bd->consultar("SELECT * FROM \"$n\"");
    }
    ksort($r);
    return $r;
}
borrarArbol($raiz);
mkdir($raiz, 0775, true);

$script = dirname(__DIR__) . '/jsonsqldbadmin/herramientas/access-to-jsonsqldb.ps1';
$fijo = __DIR__ . '/volcados/access.sql';
$pwsh = trim((string)shell_exec('command -v pwsh 2>/dev/null')) ?: (is_file('/opt/pwsh/pwsh') ? '/opt/pwsh/pwsh' : '');

echo "\n== El script de PowerShell ==\n";
chk('no tiene errores de sintaxis', function () use ($pwsh, $script) {
    if ($pwsh === '') { return getenv('JSONSQLDB_TEST_PWSH_EXIGIR') ? 'no hay pwsh y se exige' : null; }
    $orden = '$e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile(' . "'" . $script . "'"
           . ', [ref]$null, [ref]$e); if ($e) { $e | ForEach-Object { $_.ToString() } } else { "bien" }';
    $r = trim((string)shell_exec(escapeshellarg($pwsh) . ' -NoProfile -Command ' . escapeshellarg($orden) . ' 2>&1'));
    return $r === 'bien' ?: $r;
});
chk('su texto está todo en inglés y en ASCII (PowerShell 5.1 lee un .ps1 sin BOM como ANSI)', function () use ($script) {
    $texto = (string)file_get_contents($script);
    return preg_match('/[^\x00-\x7F]/', $texto) === 0 && str_contains($texto, 'Run with PowerShell')
        && str_contains($texto, 'https://www.microsoft.com/en-us/download/details.aspx?id=54920') ?: 'tiene caracteres que no son ASCII o le falta el enlace';
});
chk('sus funciones escriben exactamente el volcado de prueba (tests/volcados/access.sql)', function () use ($pwsh, $fijo, $raiz) {
    if ($pwsh === '') { return null; }
    $salida = "$raiz/generado.access.sql";
    $r = (string)shell_exec(escapeshellarg($pwsh) . ' -NoProfile -File ' . escapeshellarg(__DIR__ . '/access/volcado_de_prueba.ps1')
        . ' ' . escapeshellarg($salida) . ' 2>&1');
    if (!is_file($salida)) { return "no se generó: $r"; }
    $norm = static fn(string $s) => str_replace("\r\n", "\n", $s);
    return $norm((string)file_get_contents($salida)) === $norm((string)file_get_contents($fijo)) ?: 'el volcado que escribe el script ha cambiado';
});

echo "\n== Importar ==\n";
chk('el volcado se reconoce como de Access, también el de mdbtools', function () use ($fijo) {
    return Traductor::detectar((string)file_get_contents($fijo)) === 'access'
        && Traductor::detectar("-- ----\n-- MDB Tools - A library for reading MS Access database files\n") === 'access' ?: 'no';
});
$resumen = '';
chk('se importa con sus 4 tablas, sus claves, índices y relaciones y sus 8 consultas como vistas', function () use ($fijo, &$resumen) {
    Database::crear('acc');
    $resumen = Importar::sql($fijo, 'acc');
    $bd = new Database('acc');
    $vistas = array_column($bd->consultar('SHOW VIEWS'), 'vista');
    sort($vistas);
    $claves = array_map(static fn($k) => $k['tipo'] . ':' . $k['columnas'] . ($k['on_delete'] === 'CASCADE' ? ':cascade' : ''),
        array_merge($bd->consultar('SHOW KEYS FROM Pedidos'), $bd->consultar('SHOW KEYS FROM Order_Details')));
    $indices = array_column(array_filter($bd->consultar('SHOW INDEXES'), static fn($i) => (int)$i['automatico'] === 0), 'indice');
    sort($indices);
    return $vistas === ['qryActivos', 'qryCodigos', 'qryEstados', 'qryFechas', 'qryFiltro', 'qryLineas', 'qryResumen', 'qryTextos']
        && $claves === ['PRIMARY:Id', 'FOREIGN:ClienteId:cascade', 'FOREIGN:ProductoId', 'PRIMARY:PedidoId,Linea', 'FOREIGN:PedidoId:cascade']
        && $indices === ['ixEstado'] && !str_contains($resumen, 'sin importar')
        ?: json_encode([$vistas, $claves, $indices, $resumen], JSON_UNESCAPED_UNICODE);
});
chk('los datos llegan tal cual: comillas, saltos de línea, emojis, fechas, sí/no, moneda, decimales y nulos', function () {
    $bd = new Database('acc');
    $c = $bd->consultar('SELECT * FROM Clientes ORDER BY Id');
    $p = $bd->consultar('SELECT * FROM Productos ORDER BY Id');
    return $c[0]['Notas'] === "Primera\r\nlínea con 'comillas' y \"dobles\"" && $c[2]['Notas'] === 'emoji 😀'
        && $c[0]['Activo'] === 1 && $c[1]['Activo'] === 0 && $c[0]['Alta'] === '2026-01-05 10:00:00' && $c[2]['Alta'] === null
        && $c[2]['Nombre'] === 'Bea Ñúñez' && (float)$p[0]['Precio'] === 1.25 && (float)$p[0]['Ratio'] === 0.3333
        && $p[2]['Stock'] === -3 && $p[1]['Ratio'] === null ?: json_encode([$c, $p], JSON_UNESCAPED_UNICODE);
});
chk('el autonumérico sigue detrás del último y la clave única no admite repetidos', function () {
    $bd = new Database('acc');
    $bd->consultar("INSERT INTO Clientes (Nombre, Activo) VALUES ('Zoe', 1)");
    $id = $bd->consultar("SELECT Id FROM Clientes WHERE Nombre = 'Zoe'")[0]['Id'];
    try { $bd->consultar("INSERT INTO Clientes (Nombre, Email, Activo) VALUES ('Otra', 'ana@e.es', 0)"); $rep = 'admitido'; }
    catch (Throwable $e) { $rep = 'rechazado'; }
    $bd->consultar("DELETE FROM Clientes WHERE Nombre = 'Zoe'");
    return $id === 4 && $rep === 'rechazado' ?: "id $id, repetido $rep";
});
chk('cada consulta da lo que da en Access', function () {
    $bd = new Database('acc');
    $q = static fn(string $v) => $bd->consultar("SELECT * FROM \"$v\"");
    $esperado = [
        // Count, Sum y LEFT JOIN: Bea no tiene pedidos
        'qryResumen' => [['Nombre' => 'Ana', 'N' => 2, 'SumaTotal' => 160.5], ['Nombre' => 'alberto', 'N' => 1, 'SumaTotal' => 75.5],
                         ['Nombre' => 'Bea Ñúñez', 'N' => 0, 'SumaTotal' => null]],
        // & con Nz, IIf(IsNull), Mid, Left, Len, y InStr, que en Access no distingue mayúsculas
        'qryTextos' => [['Id' => 1, 'May' => 'ANA', 'Largo' => 3, 'Trozo' => 'na', 'Ini' => 'An', 'Contacto' => 'Ana <ana@e.es>', 'Correo' => 'ana@e.es', 'Pos' => 1],
                        ['Id' => 2, 'May' => 'ALBERTO', 'Largo' => 7, 'Trozo' => 'lbe', 'Ini' => 'al', 'Contacto' => 'alberto <->', 'Correo' => 'sin email', 'Pos' => 1],
                        ['Id' => 3, 'May' => 'BEA ÑÚÑEZ', 'Largo' => 9, 'Trozo' => 'ea ', 'Ini' => 'Be', 'Contacto' => 'Bea Ñúñez <bea@e.es>', 'Correo' => 'bea@e.es', 'Pos' => 3]],
        // Format, DateAdd, Year y DateDiff desde #1/1/2026#
        'qryFechas' => [['Id' => 1, 'Mes' => '2026-03', 'Manana' => '2026-03-02 09:00:00', 'Anio' => 2026, 'Dias' => 59],
                        ['Id' => 2, 'Mes' => '2026-03', 'Manana' => '2026-03-16 18:45:00', 'Anio' => 2026, 'Dias' => 73]],
        // TOP 2 y Like "a*", sin distinguir mayúsculas
        'qryFiltro' => [['Nombre' => 'alberto'], ['Nombre' => 'Ana']],
        'qryEstados' => [['Estado' => 'nuevo'], ['Estado' => 'pagado'], ['Estado' => null]],
        // Tres JOIN anidados entre paréntesis
        'qryLineas' => [['Nombre' => 'Ana', 'Total' => 120.5, 'Producto' => 'Cuaderno A4', 'Cantidad' => 5]],
        // Like "A##*": una A y dos cifras; CCur e Int
        'qryCodigos' => [['Codigo' => 'A12X', 'ConIva' => 1.5125, 'Gramos' => 1], ['Codigo' => 'A99', 'ConIva' => 4.5375, 'Gramos' => 25]],
        // Clientes!Nombre y = True
        'qryActivos' => [['N' => 'Ana'], ['N' => 'Bea Ñúñez']],
    ];
    $mal = [];
    foreach ($esperado as $v => $filas) {
        $r = $q($v);
        $norm = static fn(array $x) => json_encode(array_map(static fn($f) => array_map(static fn($y) => is_float($y) ? round($y, 4) : $y, $f), $x), JSON_UNESCAPED_UNICODE);
        if ($norm($r) !== $norm($filas)) {
            $mal[] = "$v: " . $norm($r);
        }
    }
    return $mal === [] ?: implode(' | ', $mal);
});

echo "\n== Exportar a Access y volver ==\n";
$volcado = '';
chk('el volcado para Access tiene los tipos de Access, #fechas#, COUNTER y las consultas como vistas', function () use (&$volcado) {
    $bd = new Database('acc');
    $tablas = [];
    foreach ($bd->consultar('SHOW TABLES') as $t) {
        $n = (string)$t['tabla'];
        $tablas[] = ['tabla' => $n, 'columnas' => $bd->consultar("SHOW SCHEMA \"$n\""), 'claves' => $bd->consultar("SHOW KEYS FROM \"$n\""),
                     'filas' => $bd->consultar("SELECT * FROM \"$n\"")];
    }
    $volcado = Exportar::volcado('acc', $tablas, $bd->consultar('SHOW TRIGGERS'), $bd->consultar('SHOW VIEWS'), $bd->consultar('SHOW INDEXES'), 'access');
    return str_contains($volcado, '-- jsonsqldb-dialecto: access') && str_contains($volcado, '[Id] COUNTER PRIMARY KEY')
        && str_contains($volcado, '[Nombre] TEXT(50) NOT NULL') && str_contains($volcado, '#2026-01-05 10:00:00#')
        && str_contains($volcado, 'CREATE VIEW [qryTextos] AS') && str_contains($volcado, 'ON DELETE CASCADE')
        && !str_contains($volcado, 'Sin traducir') ?: substr($volcado, 0, 1500);
});
chk('y se vuelve a importar con las mismas tablas, los mismos datos y lo mismo en cada vista', function () use (&$volcado, $raiz) {
    $f = "$raiz/vuelta.access.sql";
    file_put_contents($f, $volcado);
    Database::crear('vuelta');
    $resumen = Importar::sql($f, 'vuelta');
    $a = estado(new Database('acc'));
    $b = estado(new Database('vuelta'));
    $mal = [];
    foreach ($a as $n => $filas) {
        if (json_encode($filas) !== json_encode($b[$n] ?? null)) {
            $mal[] = "$n: " . json_encode($b[$n] ?? null, JSON_UNESCAPED_UNICODE);
        }
    }
    return $mal === [] && !str_contains($resumen, 'sin importar') ?: implode(' | ', $mal) . " · $resumen";
});

chk('las 25 formas de vista van al volcado de Access y vuelven dando lo mismo', function () use ($raiz) {
    [$tablasF, $formas] = require __DIR__ . '/volcados/formas_de_vista.php';
    Database::crear('formas_access');
    $bd = new Database('formas_access');
    foreach ($tablasF as $q) { $bd->consultar($q); }
    foreach ($formas as $i => $sql) { $bd->consultar("CREATE VIEW f$i AS $sql"); }
    $meta = [];
    foreach (['a', 'b'] as $t) {
        $meta[] = ['tabla' => $t, 'columnas' => $bd->consultar("SHOW SCHEMA $t"), 'claves' => $bd->consultar("SHOW KEYS FROM $t"), 'filas' => $bd->consultar("SELECT * FROM $t")];
    }
    $volcado = Exportar::volcado('formas', $meta, [], $bd->consultar('SHOW VIEWS'), [], 'access');
    file_put_contents("$raiz/formas.access.sql", $volcado);
    Database::crear('vuelta_access');
    Importar::sql("$raiz/formas.access.sql", 'vuelta_access');
    $vuelta = new Database('vuelta_access');
    $sinTraducir = preg_match('/^-- Sin traducir[^:]*: (.*)$/m', $volcado, $m) ? array_map('trim', explode(',', $m[1])) : [];
    $mal = [];
    $norm = static fn(array $x) => array_map(static fn($f) => json_encode(array_map(static fn($y) => is_numeric($y) ? round((float)$y, 4) : $y, array_change_key_case($f))), $x);
    foreach ($formas as $i => $sql) {
        if (in_array("f$i", $sinTraducir, true)) { continue; }      // lo que Access no tiene (dicho en el volcado)
        $orden = stripos($sql, 'ORDER BY') !== false && stripos($sql, 'LIMIT') !== false;
        try { $b = $norm($vuelta->consultar("SELECT * FROM f$i")); } catch (Throwable $e) { $mal[] = "f$i: " . $e->getMessage(); continue; }
        $a = $norm($bd->consultar("SELECT * FROM f$i"));
        if (!$orden) { sort($a); sort($b); }
        if ($a !== $b) { $mal[] = "f$i distinta"; }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
