<?php
declare(strict_types=1);

/**
 * Prueba de concurrencia. Ejecutar: php tests/f7_concurrencia.php
 *
 * Levanta procesos de verdad, a la vez, y mide qué se solapa y qué espera. No
 * comprueba tiempos exactos —dependen de la máquina— sino la relación entre
 * ellos: si dos operaciones que deberían ir en paralelo tardan lo mismo que una
 * sola, van en paralelo; si una espera a la otra, tarda el doble.
 *
 * Lo que se espera, según el diseño de bloqueos de dos niveles:
 *
 *   - Dos lecturas de cualquier tabla: en paralelo.
 *   - Escrituras en tablas distintas, sin claves ni triggers: en paralelo.
 *   - Escritura y lectura de tablas distintas: en paralelo.
 *   - Escritura en una tabla con claves foráneas: bloquea toda la base.
 *   - DDL: bloquea toda la base.
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_conc';
$ok = 0; $ko = 0;

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

function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach ((array)scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $r = "$dir/$f";
        is_dir($r) ? borrarArbol($r) : @unlink($r);
    }
    @rmdir($dir);
}

/**
 * Lanza varios procesos a la vez, cada uno reteniendo su bloqueo un cuarto de
 * segundo, y devuelve cuánto ha tardado el conjunto.
 *
 * Se mide sobre el bloqueo directamente y no ejecutando SQL, porque una consulta
 * suelta el bloqueo en cuanto termina y no daría tiempo a que se solapen.
 *
 * @param array<int,array{0:bool,1:?string}> $bloqueos [exclusivo, tabla] por proceso
 */
function alavez(array $bloqueos, string $raiz): float
{
    $procs = [];
    $t0 = microtime(true);
    foreach ($bloqueos as [$exclusivo, $tabla]) {
        $codigo = 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
                . '$st = new JsonSQLDB\\Storage(' . var_export($raiz, true) . ', "conc");'
                . '$st->bloquear(' . var_export($exclusivo, true) . ', ' . var_export($tabla, true) . ');'
                . 'usleep(250000);'
                . '$st->desbloquear();';
        $p = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
        if (is_resource($p)) { $procs[] = [$p, $tub]; }
        usleep(30000);                      // arranque escalonado, para que el orden sea claro
    }
    foreach ($procs as [$p, $tub]) {
        foreach ($tub as $t) { stream_get_contents($t); fclose($t); }
        proc_close($p);
    }
    return (microtime(true) - $t0) * 1000;
}

// ----------------------------------------------------------------------

echo "\n== Preparación ==\n";

borrarArbol($raiz);
@mkdir($raiz, 0775, true);
Database::crear('conc', $raiz);
$bd = new Database('conc', $raiz);

// Dos tablas sueltas, sin relación entre ellas
$bd->consultar('CREATE TABLE libres_a (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(20))');
$bd->consultar('CREATE TABLE libres_b (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(20))');

// Dos tablas relacionadas por una clave foránea
$bd->consultar('CREATE TABLE padres (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(20))');
$bd->consultar('CREATE TABLE hijas  (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER)');
$bd->consultar('ALTER TABLE hijas ADD CONSTRAINT fk_c FOREIGN KEY (pid) REFERENCES padres(id)');
$bd->consultar("INSERT INTO padres (v) VALUES ('uno')");
unset($bd);

chk('la base queda lista', fn() => is_dir($raiz . '/conc'));

echo "\n== Qué va en paralelo y qué espera ==\n";

// Un solo proceso marca la referencia: todo lo demás se compara con esto
$sola = alavez([[false, null]], $raiz);
echo '       un proceso solo: ' . round($sola) . " ms\n";
$juntos = $sola * 1.7;                      // por debajo = fueron a la vez

chk('dos lecturas van a la vez', function () use ($raiz, $juntos) {
    $t = alavez([[false, null], [false, null]], $raiz);
    echo '       dos lecturas: ' . round($t) . " ms\n";
    return $t < $juntos ?: round($t) . ' ms';
});

chk('dos escrituras en tablas distintas van a la vez', function () use ($raiz, $juntos) {
    $t = alavez([[true, 'libres_a'], [true, 'libres_b']], $raiz);
    echo '       dos escrituras de tabla: ' . round($t) . " ms\n";
    return $t < $juntos ?: round($t) . ' ms';
});

chk('una escritura no bloquea la lectura de otra tabla', function () use ($raiz, $juntos) {
    $t = alavez([[true, 'libres_a'], [false, null]], $raiz);
    echo '       escritura + lectura: ' . round($t) . " ms\n";
    return $t < $juntos ?: round($t) . ' ms';
});

chk('dos escrituras en LA MISMA tabla se serializan', function () use ($raiz, $juntos) {
    $t = alavez([[true, 'libres_a'], [true, 'libres_a']], $raiz);
    echo '       dos escrituras, misma tabla: ' . round($t) . " ms\n";
    return $t > $juntos ?: 'no esperó: ' . round($t) . ' ms';
});

chk('una escritura de base entera espera a las de tabla', function () use ($raiz, $juntos) {
    $t = alavez([[true, 'libres_a'], [true, null]], $raiz);
    echo '       tabla + base entera: ' . round($t) . " ms\n";
    return $t > $juntos ?: 'no esperó: ' . round($t) . ' ms';
});

chk('una escritura de base entera bloquea las lecturas', function () use ($raiz, $juntos) {
    $t = alavez([[true, null], [false, null]], $raiz);
    echo '       base entera + lectura: ' . round($t) . " ms\n";
    return $t > $juntos ?: 'no esperó: ' . round($t) . ' ms';
});

echo "\n== Qué sentencias pueden bloquear solo su tabla ==\n";

$decide = function (string $sql) use ($raiz): ?string {
    $bd = new Database('conc', $raiz);
    return $bd->tablaUnica(JsonSQLDB\Parser::analizar($sql));
};

chk('un INSERT en una tabla suelta bloquea solo esa tabla',
    fn() => $decide("INSERT INTO libres_a (v) VALUES ('z')") === 'libres_a');
chk('un UPDATE y un DELETE, igual',
    fn() => $decide("UPDATE libres_a SET v = 'y'") === 'libres_a'
         && $decide('DELETE FROM libres_a') === 'libres_a');
chk('una tabla CON clave foránea bloquea la base',
    fn() => $decide('INSERT INTO hijas (pid) VALUES (1)') === null);
chk('una tabla REFERENCIADA por otra bloquea la base',
    fn() => $decide('DELETE FROM padres') === null);
chk('una tabla con trigger bloquea la base', function () use ($raiz, $decide) {
    $bd = new Database('conc', $raiz);
    $bd->consultar("CREATE TRIGGER t_conc AFTER INSERT ON libres_b
                    BEGIN UPDATE libres_a SET v = 'tocado'; END");
    $r = $decide("INSERT INTO libres_b (v) VALUES ('w')");
    $bd->consultar('DROP TRIGGER t_conc');
    return $r === null ?: $r;
});
chk('INSERT ... SELECT bloquea la base, porque lee de otra tabla',
    fn() => $decide('INSERT INTO libres_a (v) SELECT v FROM libres_b') === null);
chk('el DDL bloquea la base',
    fn() => $decide('CREATE TABLE zz (a INTEGER)') === null
         && $decide('ALTER TABLE libres_a ADD COLUMN zz INTEGER') === null);
chk('REPAIR KEYS bloquea la base', fn() => $decide('REPAIR KEYS') === null);

echo "\n== Los datos siguen íntegros ==\n";

chk('muchas escrituras simultáneas en la misma tabla no pierden ninguna', function () use ($raiz) {
    $procs = [];
    for ($i = 0; $i < 8; $i++) {
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
                . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
                . '$bd = new JsonSQLDB\\Database("conc", ' . var_export($raiz, true) . ');'
                . 'for ($j = 0; $j < 5; $j++) {'
                . '  $bd->consultar("INSERT INTO libres_a (v) VALUES (?)", ["p' . $i . '"]);'
                . '}';
        $p = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
        if (is_resource($p)) { $procs[] = [$p, $tub]; }
    }
    foreach ($procs as [$p, $tub]) {
        foreach ($tub as $t) { stream_get_contents($t); fclose($t); }
        proc_close($p);
    }

    $bd  = new Database('conc', $raiz);
    $n   = (int)$bd->consultar('SELECT COUNT(*) AS n FROM libres_a')[0]['n'];
    $ids = array_column($bd->consultar('SELECT id FROM libres_a'), 'id');

    // 8 procesos x 5 filas, y los ids no se repiten pese a ir a la vez
    return ($n === 40 && count($ids) === count(array_unique($ids)))
        ?: "filas=$n ids únicos=" . count(array_unique($ids));
});

chk('las claves foráneas quedan bien', function () use ($raiz) {
    $bd = new Database('conc', $raiz);
    return $bd->consultar('CHECK KEYS') === [];
});

chk('no quedan journals a medias', fn() => !is_dir($raiz . '/conc/.tx'));

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
