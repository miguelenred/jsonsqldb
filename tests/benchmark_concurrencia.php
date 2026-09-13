<?php
declare(strict_types=1);

/**
 * Benchmark de concurrencia: lectores y escritores sobre LA MISMA tabla, en
 * procesos de verdad, durante unos segundos.
 *
 *   php tests/benchmark_concurrencia.php [lectores] [escritores] [segundos] [pausa_ms]
 *
 * Los lectores alternan una búsqueda por clave primaria y un recorrido con
 * WHERE; los escritores insertan una fila cada vez, con una pausa opcional
 * entre una y otra (0 = sin parar). Al final se ve cuántas escrituras
 * entraron y cuánto tardaron las lecturas (mediana, percentiles 95 y 99, y
 * máximo).
 *
 * Es lo que mide si un escritor consigue entrar con lectores leyendo sin
 * parar: flock no da preferencia a nadie, y sin el torno de Storage un INSERT
 * podía esperar segundos. Se ejecuta en una carpeta temporal y la borra al
 * terminar. En una máquina de un solo núcleo los procesos se reparten la CPU
 * y los tiempos suben con cada proceso más; lo que importa es la comparación
 * entre dos ejecuciones iguales.
 */

define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_CACHE_RESULTADOS', 0);
require __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;

$lectores   = max(0, (int)($argv[1] ?? 2));
$escritores = max(0, (int)($argv[2] ?? 1));
$segundos   = max(1, (int)($argv[3] ?? 5));
$pausa      = max(0, (int)($argv[4] ?? 0));
$raiz       = sys_get_temp_dir() . '/jsonsqldb_bench_conc_' . getmypid();
$motor      = dirname(__DIR__) . '/engine/bootstrap.php';

@mkdir($raiz, 0775, true);
Database::crear('bench', $raiz);
$bd = new Database('bench', $raiz);
$bd->consultar('CREATE TABLE clientes (id INTEGER PRIMARY KEY, email VARCHAR(60) UNIQUE, nombre VARCHAR(60),
                                       ciudad VARCHAR(20), edad INTEGER, saldo DECIMAL(10,2))');
mt_srand(1);
$ciudades = ['Madrid', 'Barcelona', 'Valencia', 'Sevilla', 'Elche', 'Rojales', 'Torrevieja', 'Alicante', 'Murcia', 'Bilbao'];
$lote = [];
for ($i = 1; $i <= 20000; $i++) {
    $lote[] = [$i, "c$i@ejemplo.es", "Nombre $i", $ciudades[mt_rand(0, 9)], mt_rand(18, 85), mt_rand(0, 500000) / 100];
}
foreach (array_chunk($lote, 2000) as $bloque) {
    $marcas = implode(',', array_fill(0, count($bloque), '(?,?,?,?,?,?)'));
    $bd->consultar("INSERT INTO clientes VALUES $marcas", array_merge(...$bloque));
}
$bd->consultar('SELECT COUNT(*) AS n FROM clientes WHERE edad BETWEEN 30 AND 40');   // calienta la caché
unset($bd, $lote);

$cabecera = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
          . 'require ' . var_export($motor, true) . ';'
          . '$bd = new JsonSQLDB\Database("bench", ' . var_export($raiz, true) . ');'
          . '$fin = microtime(true) + ' . $segundos . ';';
$lector = $cabecera . '$lat = [];'
        . 'while (microtime(true) < $fin) { $t0 = microtime(true);'
        . '  if (mt_rand(0, 3) === 0) { $bd->consultar("SELECT COUNT(*) AS n FROM clientes WHERE edad BETWEEN 30 AND 40"); $k = "scan"; }'
        . '  else { $bd->consultar("SELECT * FROM clientes WHERE id = ?", [mt_rand(1, 20000)]); $k = "pk"; }'
        . '  $lat[$k][] = (microtime(true) - $t0) * 1000; }'
        . 'echo json_encode($lat);';
$escritor = $cabecera . '$n = 0; $id = 1000000 + getmypid() * 100000;'
          . 'while (microtime(true) < $fin) { $id++;'
          . '  $bd->consultar("INSERT INTO clientes VALUES (?,?,?,?,?,?)", [$id, "w$id@e.es", "N", "Madrid", 30, 1.0]); $n++;'
          . '  if (' . $pausa . ' > 0) { usleep(' . ($pausa * 1000) . '); } }'
          . 'echo $n;';

$procs = [];
for ($i = 0; $i < $escritores; $i++) {
    $tubos = [];
    $procs[] = ['w', proc_open([PHP_BINARY, '-r', $escritor], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos), $tubos];
}
for ($i = 0; $i < $lectores; $i++) {
    $tubos = [];
    $procs[] = ['r', proc_open([PHP_BINARY, '-r', $lector], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos), $tubos];
}

$latencias = ['pk' => [], 'scan' => []];
$escritas  = 0;
foreach ($procs as [$tipo, $proc, $tubos]) {
    if (!is_resource($proc)) {
        continue;
    }
    $salida = (string)stream_get_contents($tubos[1]);
    $error  = trim((string)stream_get_contents($tubos[2]));
    fclose($tubos[1]);
    fclose($tubos[2]);
    proc_close($proc);
    if ($error !== '') {
        echo "Un proceso falló: " . substr($error, 0, 200) . "\n";
    }
    if ($tipo === 'w') {
        $escritas += (int)$salida;
    } else {
        foreach ((array)json_decode($salida, true) as $k => $v) {
            $latencias[$k] = array_merge($latencias[$k] ?? [], (array)$v);
        }
    }
}

$percentil = static function (array $a, float $p): float {
    if ($a === []) {
        return 0.0;
    }
    sort($a);
    return (float)$a[(int)floor(($p / 100) * (count($a) - 1))];
};
printf("\njsonSQLDB %s · PHP %s · %d lectores, %d escritores (pausa %d ms), %d s\n",
    trim((string)@file_get_contents(__DIR__ . '/../VERSION')) ?: 'desconocida', PHP_VERSION, $lectores, $escritores, $pausa, $segundos);
printf("  escrituras: %d (%.0f/s)\n", $escritas, $escritas / $segundos);
foreach (['pk' => 'lectura por clave', 'scan' => 'recorrido con WHERE'] as $k => $etiqueta) {
    $a = $latencias[$k];
    printf("  %-20s %6d consultas (%5.0f/s)  p50 %6.1f ms  p95 %6.1f ms  p99 %6.1f ms  max %7.1f ms\n",
        $etiqueta, count($a), count($a) / $segundos, $percentil($a, 50), $percentil($a, 95), $percentil($a, 99), $a === [] ? 0 : max($a));
}
echo "\n";

Database::borrar('bench', $raiz);
@rmdir($raiz);
