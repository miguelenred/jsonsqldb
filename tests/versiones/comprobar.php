<?php
/**
 * Uso interno de tests/f24_versiones_anteriores.php: con el motor actual,
 * abre (copiada) la base que creó una versión anterior y comprueba que lee lo
 * mismo que leía aquella, que escribe bien, que el autoincremento sigue donde
 * iba, que restricciones y triggers funcionan, que índices, ficheros y claves
 * cuadran, que un índice de textos que son números se rehace solo con las
 * claves de la 2.10, y que VACUUM rehace los índices con su filtro sin
 * cambiar nada más.
 * Imprime «bien» o los fallos. Uso: php comprobar.php <motor> <datos>
 */
[$_, $motor, $orig] = $argv;
$datos = $orig . '_29';
exec('rm -rf ' . escapeshellarg($datos) . ' && cp -r ' . escapeshellarg($orig) . ' ' . escapeshellarg($datos));
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_LOG_ACTIVO', false);
require "$motor/engine/bootstrap.php";
use JsonSQLDB\Database;
$e = json_decode((string)file_get_contents("$datos/esperado.json"), true);
$bd = fn() => new Database('vieja', $datos);
$fallos = [];
// 1. Lo mismo que el motor viejo
foreach ($e['esperado'] as $q => $esperado) {
    if (isset($esperado['ERROR'])) { continue; }
    try { $r = $bd()->consultar($q); } catch (Throwable $x) { $fallos[] = "lee: $q -> " . $x->getMessage(); continue; }
    // los números se comparan como números (2.9 puede devolver 999.99 donde la vieja daba '999.99')
    if (json_encode($r) !== json_encode($esperado) && json_encode(json_decode(json_encode($r), true)) != json_encode($esperado)) {
        $a = json_decode(json_encode($r), true); $b = $esperado;
        if ($a != $b) { $fallos[] = "distinto: $q\n      2.9:  " . substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 200) . "\n      vieja: " . substr(json_encode($esperado, JSON_UNESCAPED_UNICODE), 0, 200); }
    }
}
// 2. Escribir con 2.9
$maxAntes = (int)($e['esperado']['SELECT MAX(id) AS m FROM clientes'][0]['m'] ?? 0);
try {
    $r = $bd()->consultar("INSERT INTO clientes (nombre, ciudad, saldo) VALUES ('Con la 2.9', 'C3', 1.5) RETURNING id");
    $nuevo = (int)($r[0]['id'] ?? 0);
    // el autoincremento sigue: ni repite un id borrado (12000 se borró en la vieja) ni el máximo
    if ($nuevo <= 12001 || $nuevo <= $maxAntes) { $fallos[] = "autoincremento: el nuevo id es $nuevo (máximo antes $maxAntes; se habían usado hasta 12001)"; }
} catch (Throwable $x) { $fallos[] = 'INSERT: ' . $x->getMessage(); }
foreach (["UPDATE clientes SET saldo = saldo + 1 WHERE id = 7777", 'DELETE FROM clientes WHERE id = 100'] as $w) {
    try { $bd()->consultar($w); } catch (Throwable $x) { $fallos[] = "escribe: $w -> " . $x->getMessage(); }
}
// 3. Restricciones y trigger siguen funcionando
$err = static function (string $sql) use ($bd): ?string { try { $bd()->consultar($sql); return null; } catch (Throwable $x) { return $x->getMessage(); } };
if ($err("INSERT INTO clientes (nombre) VALUES ('Cliente 10')") === null) { $fallos[] = 'UNIQUE no se respeta'; }
if ($err('INSERT INTO pedidos VALUES (999, 99999, 1)') === null) { $fallos[] = 'la clave foránea no se respeta'; }
$aud = $bd()->consultar('SELECT COUNT(*) AS n FROM auditoria')[0]['n'];
$audEsp = ($e['esperado']['SELECT COUNT(*) AS n FROM auditoria'][0]['n'] ?? -1) + 1;
if ($aud !== $audEsp) { $fallos[] = "trigger: auditoria tiene $aud filas, se esperaban $audEsp"; }
// 4. Todo cuadra: recuento, índice frente a recorrido, ficheros y claves
$n = $bd()->consultar('SELECT COUNT(*) AS n FROM clientes')[0]['n'];
$nEsp = $e['esperado']['SELECT COUNT(*) AS n, SUM(id) AS s FROM clientes'][0]['n'];   // +1 insertado -1 borrado
if ($n !== $nEsp) { $fallos[] = "recuento: $n filas, se esperaban $nEsp"; }
$porIndice = $bd()->consultar("SELECT COUNT(*) AS n FROM clientes WHERE ciudad = 'C3'")[0]['n'];
$porRecorrido = $bd()->consultar("SELECT COUNT(*) AS n FROM clientes WHERE ciudad || '' = 'C3'")[0]['n'];
if ($porIndice !== $porRecorrido) { $fallos[] = "índice: $porIndice frente a $porRecorrido recorriendo"; }
$porClave = $bd()->consultar("SELECT nombre FROM clientes WHERE id = 11000");
if ($porClave !== [['nombre' => 'Cliente 11000']]) { $fallos[] = 'por clave: ' . json_encode($porClave); }
$ct = $bd()->consultar('CHECK TABLES'); if ($ct !== []) { $fallos[] = 'CHECK TABLES: ' . json_encode($ct, JSON_UNESCAPED_UNICODE); }
$ck = $bd()->consultar('CHECK KEYS'); if ($ck !== []) { $fallos[] = 'CHECK KEYS: ' . json_encode($ck, JSON_UNESCAPED_UNICODE); }
// 4b. Textos que son números, como textos (2.10): el índice de antes, que
// juntaba '7' y '007' en una clave, no se usa; se rehace solo al acabar la
// primera consulta que lo encuentra, y después sí se usa
if (in_array("INSERT INTO codigos VALUES (1, '7'), (2, '007'), (3, ' 7'), (4, '10'), (5, '9'), (6, 'x')", $e['hechas'], true)) {
    // CHECK INDEXES lo dice, sin rehacerlo
    $ci = array_column($bd()->consultar('CHECK INDEXES FROM codigos'), 'problema', 'indice');
    if (in_array('CREATE INDEX ix_codigo ON codigos (c)', $e['hechas'], true) && !str_contains($ci['ix_codigo'] ?? '', 'de antes de la 2.10')) {
        $fallos[] = 'codigos: CHECK INDEXES no ve las claves de antes: ' . json_encode($ci, JSON_UNESCAPED_UNICODE);
    }
    // Primero dos escrituras, que no pueden aprovechar los trozos de antes:
    // la primera rehace el índice entero aunque no toque su columna
    foreach (['UPDATE codigos SET id = id + 0 WHERE id = 6', "INSERT INTO codigos VALUES (7, '8')"] as $w) {
        try { $bd()->consultar($w); } catch (Throwable $x) { $fallos[] = "codigos: $w -> " . $x->getMessage(); }
    }
    $ids = fn(string $q): array => array_column($bd()->consultar($q), 'id');
    foreach (["SELECT id FROM codigos WHERE c = '7'" => [1], "SELECT id FROM codigos WHERE c = '007'" => [2], 'SELECT id FROM codigos WHERE c = 7' => [1],
              "SELECT id FROM codigos WHERE c IN ('9', ' 7') ORDER BY id" => [3, 5], "SELECT id FROM codigos WHERE c > '1' ORDER BY id" => [1, 4, 5, 6, 7],
              'SELECT id FROM codigos ORDER BY c, id' => [3, 2, 4, 1, 7, 5, 6]] as $q => $esp) {
        if (($r = $ids($q)) !== $esp) { $fallos[] = "codigos: $q -> " . json_encode($r); }
    }
    $via = (string)($bd()->consultar("EXPLAIN SELECT id FROM codigos WHERE c = '10'")[0]['acceso'] ?? '');
    if (in_array('CREATE INDEX ix_codigo ON codigos (c)', $e['hechas'], true) && !str_starts_with($via, 'índice')) {
        $fallos[] = "codigos: tras la primera consulta, su índice sigue sin usarse ($via)";
    }
}
// 5. VACUUM rehace los índices con el filtro de la 2.9 y nada más cambia
$sumas = fn() => [$bd()->consultar('SELECT COUNT(*) AS n, SUM(id) AS s FROM clientes'), $bd()->consultar('SELECT COUNT(*) AS n, SUM(id) AS s FROM cola')];
$antes = $sumas();
try { $bd()->consultar('VACUUM'); } catch (Throwable $x) { $fallos[] = 'VACUUM: ' . $x->getMessage(); }
$sinFiltro = array_filter(glob("$datos/vieja/*.idx.*.json"), static fn($f) => !str_contains((string)file_get_contents($f), '"filtro":"'));
if ($sinFiltro !== []) { $fallos[] = 'VACUUM dejó ' . count($sinFiltro) . ' trozo(s) de índice sin filtro'; }
if ($sumas() !== $antes) { $fallos[] = 'VACUUM cambió los datos: ' . json_encode([$antes, $sumas()]); }
$porTexto = $bd()->consultar("SELECT id FROM clientes WHERE nombre = 'Cliente 10'");
if ($porTexto !== [['id' => 10]]) { $fallos[] = 'por texto tras VACUUM: ' . json_encode($porTexto); }
$ct = $bd()->consultar('CHECK TABLES'); if ($ct !== []) { $fallos[] = 'CHECK TABLES tras VACUUM: ' . json_encode($ct, JSON_UNESCAPED_UNICODE); }
echo $fallos === [] ? "bien\n" : count($fallos) . " fallo(s):\n  " . implode("\n  ", $fallos) . "\n";
exec('rm -rf ' . escapeshellarg($datos));
