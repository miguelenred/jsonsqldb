<?php
declare(strict_types=1);

/**
 * f25_ficheros_abiertos.php — ficheros que otro proceso tiene abiertos
 * mientras el motor los reemplaza (2.10)
 *
 * Cada escritura pone sus ficheros en su sitio renombrando un temporal
 * encima. En Windows eso falla mientras otro proceso tiene abierto el
 * destino, aunque solo lo esté leyendo; el motor reintenta durante un
 * segundo, sin borrar nunca el destino (antes lo borraba y volvía a
 * renombrar: en ese momento otro proceso no lo encontraba, y si seguía
 * abierto ni se podía borrar y la escritura fallaba). En Linux y macOS
 * renombrar encima no espera a nadie y estas pruebas pasan igual: la CI las
 * pasa también en Windows.
 *
 * Ejecutar: php tests/f25_ficheros_abiertos.php
 *
 * https://miguelenred.es/jsonsqldb
 */

define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_CACHE_RESULTADOS', 0);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\StorageEscritura;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_abiertos_' . getmypid();
$ok = 0; $ko = 0;

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

/**
 * Otro proceso abre $ficheros para leer y los tiene abiertos $ms
 * milisegundos. Vuelve cuando ya los tiene abiertos.
 */
function abiertos(array $ficheros, int $ms): array {
    $codigo = '$f = []; foreach (' . var_export(array_values($ficheros), true) . ' as $x) { $f[] = fopen($x, "r"); }'
            . ' echo "listo\n"; usleep(' . ($ms * 1000) . '); foreach ($f as $h) { fclose($h); }';
    $p = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
    fgets($tub[1]);
    return [$p, $tub];
}

function cerrar(array $h): void {
    [$p, $tub] = $h;
    stream_get_contents($tub[1]);
    fclose($tub[1]);
    fclose($tub[2]);
    proc_close($p);
}

/** Los ficheros de la base (no las carpetas), para tenerlos abiertos */
function ficherosDe(string $dir): array {
    return array_filter((array)glob("$dir/*"), 'is_file');
}

@mkdir($raiz, 0775, true);
Database::crear('b', $raiz);
$bd  = new Database('b', $raiz);
$dir = "$raiz/b";
$bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)');
$bd->consultar('INSERT INTO t VALUES (1, 1), (2, 2)');

echo "== Reemplazar un fichero ==\n";

chk('si no se puede renombrar encima, el destino no se borra: sigue como estaba (2.10: se borraba y se volvía a intentar)', function () use ($raiz) {
    // Un origen que no existe no se puede renombrar en ningún sistema, y el
    // destino, que nadie tiene abierto, sí se podría borrar. (Una carpeta como
    // origen no vale: en Windows PHP la pone en el sitio del fichero.)
    $destino = "$raiz/destino.json";
    file_put_contents($destino, 'original');
    $r = StorageEscritura::reemplazar("$raiz/no-existe.json", $destino);
    $sigue = @file_get_contents($destino);
    @unlink($destino);
    return $r === false && $sigue === 'original' ?: json_encode([$r, $sigue]);
});

echo "\n== Con los ficheros abiertos por otro proceso ==\n";

chk('un UPDATE con todos los ficheros de la base abiertos 400 ms por otro proceso espera y escribe', function () use ($bd, $dir) {
    $h = abiertos(ficherosDe($dir), 400);
    try {
        $bd->consultar('UPDATE t SET v = 10 WHERE id = 1');
    } finally {
        cerrar($h);
    }
    return $bd->consultar('SELECT v FROM t WHERE id = 1')[0]['v'] === 10 ?: 'no escribió';
});

chk('deshacer una transacción con sus ficheros abiertos por otro proceso devuelve los originales', function () use ($bd, $dir) {
    $h = null;
    try {
        $bd->transaccion(function () use ($bd, $dir, &$h) {
            $bd->consultar('UPDATE t SET v = 99 WHERE id = 2');
            $h = abiertos(ficherosDe($dir), 400);
            throw new RuntimeException('deshacer');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'deshacer') {
            throw $e;
        }
    } finally {
        if ($h !== null) {
            cerrar($h);
        }
    }
    return $bd->consultar('SELECT v FROM t WHERE id = 2')[0]['v'] === 2 ?: 'no se deshizo';
});

chk('CREATE TABLE con _database.json abierto por otro proceso: la tabla nueva está y la base no desaparece', function () use ($bd, $dir, $raiz) {
    $h = abiertos(["$dir/_database.json"], 400);
    try {
        $bd->consultar('CREATE TABLE u (id INTEGER PRIMARY KEY)');
    } finally {
        cerrar($h);
    }
    $tablas = array_column($bd->consultar('SHOW TABLES'), 'tabla');
    return in_array('u', $tablas, true) && in_array('b', Database::bases($raiz), true) ?: json_encode($tablas);
});

echo "\n== Limpieza ==\n";
chk('sin restos', function () use (&$bd, $raiz) {
    $bd = null;
    Database::borrar('b', $raiz);
    @rmdir($raiz);
    clearstatcache();
    return !is_dir($raiz) ?: 'quedan: ' . implode(',', array_diff((array)scandir($raiz), ['.', '..']));
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
