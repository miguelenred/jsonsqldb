<?php
declare(strict_types=1);

/**
 * Benchmark reproducible del motor.
 *
 *   php tests/benchmark.php              20.000 filas (por defecto)
 *   php tests/benchmark.php 50000        el tamaño que quieras
 *   php tests/benchmark.php 20000 csv    salida en CSV, para comparar versiones
 *
 * No es un test: no falla ni aprueba nada, solo mide. Está aquí para que
 * cualquiera pueda repetir los números que cita el README en su propia máquina,
 * en vez de creérselos. Los tiempos dependen mucho del disco y de la CPU, así
 * que compara siempre contra una medida tuya, no contra la de otro.
 *
 * Mide la MEDIANA de varias repeticiones, no la media: una pausa del recolector
 * de basura o del disco dispara la media y deja de representar el caso normal.
 *
 * Usa la conexión directa al motor, sin HTTP: lo que se mide es el motor. El
 * coste de la API se suma aparte y se documenta en docs/04-api.md.
 */

define('JSONSQLDB_CONEXION_DIRECTA', true);
require __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;

$filas  = max(100, (int)($argv[1] ?? 20000));
$csv    = ($argv[2] ?? '') === 'csv';
$raiz   = sys_get_temp_dir() . '/jsonsqldb_bench_' . getmypid();
$version = trim((string)@file_get_contents(__DIR__ . '/../VERSION')) ?: 'desconocida';

/** Mediana de $n repeticiones, en milisegundos. */
function medir(callable $fn, int $n = 7): float
{
    $tiempos = [];
    for ($i = 0; $i < $n; $i++) {
        $t0 = microtime(true);
        $fn();
        $tiempos[] = (microtime(true) - $t0) * 1000;
    }
    sort($tiempos);
    return $tiempos[intdiv($n, 2)];
}

function limpiar(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach ((array)scandir($dir) as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $r = "$dir/$e";
        is_dir($r) ? limpiar($r) : @unlink($r);
    }
    @rmdir($dir);
}

limpiar($raiz);
@mkdir($raiz, 0775, true);

// ----------------------------------------------------------------------
// Datos
// ----------------------------------------------------------------------

Database::crear('bench', $raiz);
$bd = new Database('bench', $raiz);
$bd->consultar('CREATE TABLE clientes (id INTEGER PRIMARY KEY, email VARCHAR(60) UNIQUE,
                nombre VARCHAR(60), ciudad VARCHAR(20), edad INTEGER, saldo DECIMAL(10,2))');
$bd->consultar('CREATE TABLE pedidos (id INTEGER PRIMARY KEY, cid INTEGER,
                total DECIMAL(10,2), fecha DATE)');

// Semilla fija: dos ejecuciones comparan los mismos datos
mt_srand(20260831);
$ciudades = ['Madrid', 'Barcelona', 'Valencia', 'Sevilla', 'Elche',
             'Rojales', 'Torrevieja', 'Alicante', 'Murcia', 'Bilbao'];

$lote = [];
for ($i = 1; $i <= $filas; $i++) {
    $lote[] = [$i, "c$i@ejemplo.es", "Nombre Apellido $i",
               $ciudades[mt_rand(0, 9)], mt_rand(18, 85), mt_rand(0, 500000) / 100];
}
$t0 = microtime(true);
foreach (array_chunk($lote, 2000) as $bloque) {
    $marcas = implode(',', array_fill(0, count($bloque), '(?,?,?,?,?,?)'));
    $bd->consultar("INSERT INTO clientes VALUES $marcas", array_merge(...$bloque));
}
$cargaClientes = (microtime(true) - $t0) * 1000;

$lote = [];
$pedidos = (int)($filas * 1.5);
for ($i = 1; $i <= $pedidos; $i++) {
    $lote[] = [$i, mt_rand(1, $filas), mt_rand(100, 100000) / 100, '2026-06-01'];
}
foreach (array_chunk($lote, 2000) as $bloque) {
    $marcas = implode(',', array_fill(0, count($bloque), '(?,?,?,?)'));
    $bd->consultar("INSERT INTO pedidos VALUES $marcas", array_merge(...$bloque));
}
unset($lote);

$bd->consultar('CREATE INDEX idx_ciudad ON clientes (ciudad)');

// ----------------------------------------------------------------------
// Medidas
// ----------------------------------------------------------------------

$medio  = intdiv($filas, 2);
$ultimo = $filas;
$nuevo  = $filas;

/** @var array<string, array{0: float, 1: float}> etiqueta => [ms, MB] */
$resultados = [];

$correr = static function (string $etiqueta, callable $fn, int $n = 7) use (&$resultados): void {
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();                 // PHP 8.2+
    }
    $ms = medir($fn, $n);
    $resultados[$etiqueta] = [$ms, memory_get_peak_usage() / 1048576];
};

$correr('Carga inicial (por lotes de 2.000)', static function () {}, 1);
$resultados['Carga inicial (por lotes de 2.000)'] = [$cargaClientes, memory_get_peak_usage() / 1048576];

$correr('Lectura por clave primaria',
    static fn() => $bd->consultar('SELECT * FROM clientes WHERE id = ?', [$medio]));

$correr('Lectura por columna UNIQUE',
    static fn() => $bd->consultar('SELECT id FROM clientes WHERE email = ?', ["c$medio@ejemplo.es"]));

$correr('IN de diez claves primarias',
    static fn() => $bd->consultar('SELECT id FROM clientes WHERE id IN (?,?,?,?,?,?,?,?,?,?)',
        [1, 7, 99, 500, 1000, $medio, $medio + 1, $ultimo - 2, $ultimo - 1, $ultimo]));

$correr('Igualdad sobre columna indexada',
    static fn() => $bd->consultar("SELECT COUNT(*) AS n FROM clientes WHERE ciudad = 'Elche'"));

$correr('Rango numérico (sin índice)',
    static fn() => $bd->consultar('SELECT COUNT(*) AS n FROM clientes WHERE edad BETWEEN 30 AND 40'));

$correr('LIKE por prefijo',
    static fn() => $bd->consultar("SELECT COUNT(*) AS n FROM clientes WHERE nombre LIKE 'Nombre Apellido 1%'"));

$correr('LIMIT 50 sin filtro',
    static fn() => $bd->consultar('SELECT id, email FROM clientes LIMIT 50'));

$correr('COUNT(*) de la tabla entera',
    static fn() => $bd->consultar('SELECT COUNT(*) AS n FROM pedidos'));

$correr('GROUP BY con SUM',
    static fn() => $bd->consultar('SELECT ciudad, COUNT(*) AS n, SUM(saldo) AS s
                                   FROM clientes GROUP BY ciudad ORDER BY ciudad'));

$correr('ORDER BY con LIMIT 20',
    static fn() => $bd->consultar('SELECT id, saldo FROM clientes ORDER BY saldo DESC LIMIT 20'), 5);

$correr('ORDER BY completo',
    static fn() => $bd->consultar('SELECT id FROM clientes ORDER BY saldo DESC'), 5);

$correr('JOIN agregado por ciudad',
    static fn() => $bd->consultar('SELECT c.ciudad, COUNT(*) AS n, SUM(p.total) AS t
                                   FROM pedidos p JOIN clientes c ON c.id = p.cid
                                   GROUP BY c.ciudad ORDER BY c.ciudad'), 5);

$correr('Subconsulta con IN',
    static fn() => $bd->consultar("SELECT COUNT(*) AS n FROM pedidos
                                   WHERE cid IN (SELECT id FROM clientes WHERE ciudad = 'Elche')"), 5);

$correr('INSERT de una fila', static function () use ($bd, &$nuevo) {
    $nuevo++;
    $bd->consultar('INSERT INTO clientes VALUES (?,?,?,?,?,?)',
        [$nuevo, "z$nuevo@ejemplo.es", 'Nueva', 'Madrid', 30, 1.0]);
}, 5);

$correr('UPDATE de una fila',
    static fn() => $bd->consultar('UPDATE clientes SET saldo = ? WHERE id = ?',
        [mt_rand(1, 999), $medio]), 5);

$correr('DELETE de una fila', static function () use ($bd, &$nuevo) {
    $bd->consultar('DELETE FROM clientes WHERE id = ?', [$nuevo]);
    $nuevo++;
    $bd->consultar('INSERT INTO clientes VALUES (?,?,?,?,?,?)',
        [$nuevo, "z$nuevo@ejemplo.es", 'Nueva', 'Madrid', 30, 1.0]);
}, 5);

// ----------------------------------------------------------------------
// Salida
// ----------------------------------------------------------------------

$enDisco = 0;
foreach ((array)glob("$raiz/bench/*.json") as $f) {
    $enDisco += (int)filesize((string)$f);
}

if ($csv) {
    echo "version,filas,operacion,ms,mb\n";
    foreach ($resultados as $etiqueta => [$ms, $mb]) {
        printf("%s,%d,\"%s\",%.2f,%.1f\n", $version, $filas, $etiqueta, $ms, $mb);
    }
} else {
    printf("\njsonSQLDB %s · PHP %s · %s filas de clientes, %s de pedidos · %.1f MB en disco\n",
        $version, PHP_VERSION, number_format($filas), number_format($pedidos), $enDisco / 1048576);
    printf("Mediana de varias repeticiones. Mide el motor, sin HTTP.\n\n");
    printf("  %-38s %10s %9s\n", 'operación', 'ms', 'pico MB');
    echo '  ', str_repeat('-', 58), "\n";
    foreach ($resultados as $etiqueta => [$ms, $mb]) {
        printf("  %-38s %10.2f %9.1f\n", $etiqueta, $ms, $mb);
    }
    printf("\n  Las escrituras empeoran según crece la tabla: cada una reescribe las\n");
    printf("  partes que toca y rehace los índices. Repite con otro tamaño para verlo.\n\n");
}

limpiar($raiz);
