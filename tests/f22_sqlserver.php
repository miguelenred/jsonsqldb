<?php
declare(strict_types=1);

/**
 * El script de PowerShell de SQL Server (2.9). Ejecutar: php tests/f22_sqlserver.php
 *
 * Aquí no hay Windows ni SQL Server, así que se prueba todo lo que no es hablar
 * con el servidor por OLE DB:
 *  - Con PowerShell (pwsh, que traen los ejecutores de GitHub Actions): que
 *    jsonsqldbadmin/herramientas/sqlserver-to-jsonsqldb.ps1 no tiene errores de
 *    sintaxis, y que sus funciones escriben, para la base descrita en
 *    tests/sqlserver/volcado_de_prueba.ps1, exactamente
 *    tests/volcados/sqlserver-script.sql: tablas de dbo y de otro esquema
 *    (esquema_tabla, también en la vista y el trigger), identidad, decimal,
 *    money, fechas de los tipos nuevos como texto, binario, NULL, comillas,
 *    saltos de línea y emojis, clave única, índice, clave foránea con CASCADE.
 *  - Que ese volcado se importa en jsonSQLDB y hace lo mismo: la vista, el
 *    trigger, la cascada, la clave única y los valores por defecto.
 *
 * https://miguelenred.es/jsonsqldb
 */
use JsonSQLDB\Database;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f22_' . getmypid();
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_DATA_PATH', $raiz);
define('JSONSQLDB_LOG_PATH', JSONSQLDB_DATA_PATH . '/logs');
define('ADMIN_CONEXION', 'directa');
require dirname(__DIR__) . '/engine/bootstrap.php';
require dirname(__DIR__) . '/jsonsqldbadmin/config.dist.php';
require dirname(__DIR__) . '/jsonsqldbadmin/lib/util.php';
foreach (['Idioma', 'Api', 'Traductor', 'TraductorRutinas', 'Importar', 'Exportar', 'JsonFlujo'] as $clase) {
    require_once dirname(__DIR__) . "/jsonsqldbadmin/lib/$clase.php";
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
borrarArbol($raiz);
mkdir($raiz, 0775, true);

$script = dirname(__DIR__) . '/jsonsqldbadmin/herramientas/sqlserver-to-jsonsqldb.ps1';
$fijo = __DIR__ . '/volcados/sqlserver-script.sql';
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
    return preg_match('/[^\x00-\x7F]/', $texto) === 0 && str_contains($texto, 'Run with PowerShell') && str_contains($texto, 'SQLOLEDB')
        ?: 'tiene caracteres que no son ASCII';
});
chk('sus funciones escriben exactamente el volcado de prueba, y separan sus lotes y sus tablas', function () use ($pwsh, $fijo, $raiz) {
    if ($pwsh === '') { return null; }
    $salida = "$raiz/generado.sqlserver.sql";
    $r = trim((string)shell_exec(escapeshellarg($pwsh) . ' -NoProfile -File ' . escapeshellarg(__DIR__ . '/sqlserver/volcado_de_prueba.ps1')
        . ' ' . escapeshellarg($salida) . ' 2>&1'));
    if (!is_file($salida)) { return "no se generó: $r"; }
    return (string)file_get_contents($salida) === (string)file_get_contents($fijo)
        && $r === 'lotes=9 tablas=clientes,ventas_pedidos,ventas_registro filas=5' ?: "el volcado que escribe el script ha cambiado ($r)";
});

echo "\n== Importar ==\n";
chk('el volcado se reconoce como de SQL Server y se importa entero; solo avisa del binario, que llega en hexadecimal', function () use ($fijo) {
    Database::crear('ss');
    $resumen = Importar::sql($fijo, 'ss');
    $bd = new Database('ss');
    $tablas = array_column($bd->consultar('SHOW TABLES'), 'tabla');
    sort($tablas);
    return Traductor::detectar((string)file_get_contents($fijo)) === 'sqlserver'
        && $tablas === ['clientes', 'ventas_pedidos', 'ventas_registro'] && str_contains($resumen, 'SQL Server') && !str_contains($resumen, 'sin importar')
        && str_contains($resumen, 'hexadecimal')
        ?: json_encode([$tablas, $resumen], JSON_UNESCAPED_UNICODE);
});
chk('los datos llegan iguales: textos con saltos, comillas y emojis, decimales, fechas, binario y NULL', function () {
    $bd = new Database('ss');
    $c = $bd->consultar('SELECT * FROM clientes ORDER BY id');
    $p = $bd->consultar('SELECT id, total, creado, ratio FROM ventas_pedidos ORDER BY id');
    return $c[0]['notas'] === "Primera\r\nlínea con 'comillas'" && $c[1]['nombre'] === 'Bea Ñúñez 😀' && (float)$c[0]['saldo'] === 10.5
        && $c[0]['alta'] === '2026-01-05 10:00:00' && $c[0]['nacido'] === '1990-03-01' && $c[1]['email'] === null && $c[0]['activo'] == 1
        && $c[0]['foto'] === '\\xcafe'
        && $p[0]['creado'] === '2026-03-01 09:00:00.123' && (float)$p[1]['total'] === 120.5 && $p[1]['ratio'] === null && count($p) === 3
        ?: json_encode([$c, $p], JSON_UNESCAPED_UNICODE);
});
chk('la vista, el trigger, la cascada, la clave única y los valores por defecto hacen lo mismo que en SQL Server', function () {
    $bd = new Database('ss');
    $v = $bd->consultar('SELECT nombre, total, pedidos FROM v_totales ORDER BY nombre');
    $bd->consultar("INSERT INTO clientes (nombre, email) VALUES ('Carla', 'c@e.es')");
    $nuevo = $bd->consultar("SELECT id, activo, saldo FROM clientes WHERE nombre = 'Carla'")[0];
    $bd->consultar('INSERT INTO ventas_pedidos (cliente, total) VALUES (' . $nuevo['id'] . ', 5)');
    $registro = $bd->consultar('SELECT pedido, cuando FROM ventas_registro');
    try { $bd->consultar("INSERT INTO clientes (nombre, email) VALUES ('Otra', 'c@e.es')"); $unica = 'pasó'; } catch (Throwable) { $unica = 'bien'; }
    $bd->consultar('DELETE FROM clientes WHERE id = 1');
    $quedan = $bd->consultar('SELECT COUNT(*) AS n FROM ventas_pedidos WHERE cliente = 1')[0]['n'];
    return count($v) === 2 && (float)$v[0]['total'] === 160.5 && (int)$v[0]['pedidos'] === 2 && $nuevo['id'] === 3 && (int)$nuevo['activo'] === 1
        && (float)$nuevo['saldo'] === 0.0 && count($registro) === 1 && $registro[0]['pedido'] === 4 && $registro[0]['cuando'] !== null
        && $unica === 'bien' && $quedan === 0 ?: json_encode([$v, $nuevo, $registro, $unica, $quedan], JSON_UNESCAPED_UNICODE);
});

borrarArbol($raiz);
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko > 0 ? 1 : 0);
