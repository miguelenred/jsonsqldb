<?php
/**
 * Fase 24 (2.9): el motor actual copiado encima de cualquier versión publicada.
 *
 * Para cada etiqueta vX.Y.Z del repositorio: saca esa versión con git, crea una
 * base con su motor (dos tablas, una con más de 12.000 filas en varias partes,
 * autoincremento, UNIQUE, índice, clave foránea, vista y trigger, según lo que
 * admitiera, con borrados y cambios) y la abre con el motor actual: tiene que
 * leer lo mismo que leía aquella, escribir bien y que todo cuadre (ver
 * tests/versiones/comprobar.php). No hace falta lo contrario: una base escrita
 * por esta versión no tiene por qué funcionar con un motor anterior.
 *
 * Necesita git, tar y las etiquetas (en GitHub Actions, fetch-depth: 0); sin
 * ellas se salta con un aviso. Uso: php tests/f24_versiones_anteriores.php [vX.Y.Z]
 */
declare(strict_types=1);

$repo  = dirname(__DIR__);
$solo  = $argv[1] ?? null;
$tags  = [];
exec('git -C ' . escapeshellarg($repo) . ' tag 2>/dev/null', $tags, $cod);
$tags = array_values(array_filter($tags, static fn(string $t): bool => (bool)preg_match('/^v\d+\.\d+\.\d+$/', $t)
    && ($solo === null || $t === $solo)));
usort($tags, static fn(string $a, string $b): int => version_compare(substr($a, 1), substr($b, 1)));
if ($cod !== 0 || $tags === []) {
    echo "  --    sin git o sin etiquetas de versión: se salta (en CI, checkout con fetch-depth: 0)\n";
    echo "\n---------------------------------------\nOK: 0   FALLOS: 0\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jsonsqldb_test_f24';
$ok  = 0;
$ko  = 0;
foreach ($tags as $tag) {
    exec('rm -rf ' . escapeshellarg($tmp) . ' && mkdir -p ' . escapeshellarg("$tmp/motor"));
    exec('git -C ' . escapeshellarg($repo) . ' archive ' . escapeshellarg($tag) . ' | tar -x -C ' . escapeshellarg("$tmp/motor"), $o, $c);
    $creada = $c === 0 ? (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/versiones/generar.php')
        . ' ' . escapeshellarg("$tmp/motor") . ' ' . escapeshellarg("$tmp/datos") . ' 2>&1') : 'no se pudo sacar con git';
    if (!preg_match('/(\d+) sentencias bien/', $creada)) {
        $ko++;
        echo "  FALLO $tag: no se pudo crear la base con su motor -> " . trim(substr($creada, -300)) . "\n";
        continue;
    }
    $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/versiones/comprobar.php')
        . ' ' . escapeshellarg($repo) . ' ' . escapeshellarg("$tmp/datos") . ' 2>&1'));
    if ($r === 'bien') {
        $ok++;
        echo "  ok    $tag: la base que creó la lee, la escribe y todo cuadra\n";
    } else {
        $ko++;
        echo "  FALLO $tag -> $r\n";
    }
}
exec('rm -rf ' . escapeshellarg($tmp));
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
