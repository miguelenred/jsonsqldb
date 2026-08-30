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

/**
 * Lo que cuesta levantar un proceso PHP y cargar el motor, sin hacer nada más.
 *
 * Hay que descontarlo: los tiempos de abajo se comparan entre sí para ver qué se
 * solapa y qué espera, y el arranque es un sumando fijo que va en TODAS las
 * medidas. En esta máquina son unas decenas de milisegundos; en una lenta pueden
 * ser 150, y entonces dos procesos que se serializan de verdad quedaban por
 * debajo del margen y la prueba fallaba sin que nada estuviera mal.
 */
function soloArranque(string $raiz): float
{
    $codigo = 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . 'new JsonSQLDB\\Storage(' . var_export($raiz, true) . ', "conc");';
    $mejor = null;
    for ($i = 0; $i < 3; $i++) {
        $t0 = microtime(true);
        $p  = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
        if (!is_resource($p)) { return 0.0; }
        foreach ($tub as $t) { stream_get_contents($t); fclose($t); }
        proc_close($p);
        $ms = (microtime(true) - $t0) * 1000;
        $mejor = $mejor === null ? $ms : min($mejor, $ms);
    }
    return (float)$mejor;
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

// Un solo proceso marca la referencia, descontando lo que cuesta arrancarlo
$arranque = soloArranque($raiz);
$sola     = alavez([[false, null]], $raiz);
$retencion = max(1.0, $sola - $arranque);   // lo que de verdad se retiene el bloqueo

echo '       arranque de un proceso: ' . round($arranque) . " ms\n";
echo '       un proceso solo: ' . round($sola) . ' ms (retención ' . round($retencion) . " ms)\n";

// Por debajo = fueron a la vez. El umbral se calcula sobre la retención, no
// sobre el total, para que el arranque no se lo coma en una máquina lenta.
$juntos = $arranque + $retencion * 1.7;
echo '       umbral: ' . round($juntos) . " ms\n";

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
    // Se recoge lo que digan los hijos: si uno falla, hay que verlo. Sin esto,
    // un proceso que muriera con un error se traducía en «faltan cinco filas»
    // sin ninguna pista de por qué.
    $errores = [];
    foreach ($procs as [$p, $tub]) {
        $salida = '';
        foreach ($tub as $t) { $salida .= stream_get_contents($t); fclose($t); }
        $codigo = proc_close($p);
        if ($codigo !== 0 || trim($salida) !== '') {
            $errores[] = "salida $codigo: " . trim(substr($salida, 0, 200));
        }
    }
    if ($errores !== []) {
        return 'algún proceso falló -> ' . implode(' | ', array_slice($errores, 0, 2));
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

echo "\n== Lectura con tablas repartidas en partes ==\n";
chk('una lectura no ve nunca media escritura de una tabla partida', function () use ($raiz) {
    // Con la tabla en varias partes, la escritura reemplaza los ficheros uno a
    // uno. Sin el compartido de tabla, una lectura simultánea podía coger la
    // primera parte ya nueva y la segunda todavía vieja: filas de dos
    // versiones distintas mezcladas, sin ningún corte de luz de por medio.
    $dir = $raiz . '/conc2';
    if (is_dir($dir)) {
        foreach ((array)glob("$dir/*/*") as $f) { @unlink($f); }
        foreach ((array)glob("$dir/*") as $f) { @rmdir($f); }
        @rmdir($dir);
    }
    @mkdir($dir, 0775, true);

    $prep = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
          . 'define("JSONSQLDB_FILAS_POR_PARTE", 25);'
          . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';';

    shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        $prep
        . 'JsonSQLDB\\Database::crear("p", ' . var_export($dir, true) . ');'
        . '$bd = new JsonSQLDB\\Database("p", ' . var_export($dir, true) . ');'
        . '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, v VARCHAR(10))");'
        . '$v = []; for ($i=1;$i<=400;$i++) { $v[] = "($i, \'A\')"; }'
        . '$bd->consultar("INSERT INTO t (id,v) VALUES " . implode(",", $v));'
    ) . ' 2>&1');

    // Escritor: alterna todas las filas entre A y B, muchas veces
    $escritor = $prep
        . '$bd = new JsonSQLDB\\Database("p", ' . var_export($dir, true) . ');'
        . 'for ($i=0;$i<25;$i++) { $bd->consultar("UPDATE t SET v = ?", [$i % 2 ? "A" : "B"]); }';

    // Lector: cada lectura tiene que ver un solo valor, nunca A y B a la vez
    $lector = $prep
        . '$bd = new JsonSQLDB\\Database("p", ' . var_export($dir, true) . ');'
        . '$mal = 0; $n = 0;'
        . 'for ($i=0;$i<60;$i++) {'
        . '  $f = $bd->consultar("SELECT DISTINCT v FROM t");'
        . '  $c = (int)$bd->consultar("SELECT COUNT(*) AS n FROM t")[0]["n"];'
        . '  if (count($f) !== 1 || $c !== 400) { $mal++; }'
        . '  $n++;'
        . '}'
        . 'echo "$mal/$n";';

    $cmdE = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($escritor) . ' > /dev/null 2>&1';
    $cmdL = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($lector) . ' 2>&1';

    $pE = proc_open($cmdE, [1 => ['pipe','w'], 2 => ['pipe','w']], $tE);
    $pL = proc_open($cmdL, [1 => ['pipe','w'], 2 => ['pipe','w']], $tL);
    if (!is_resource($pE) || !is_resource($pL)) { return 'no se pudieron lanzar los procesos'; }

    $salida = stream_get_contents($tL[1]);
    foreach ($tL as $t) { @fclose($t); }
    foreach ($tE as $t) { @fclose($t); }
    proc_close($pL);
    proc_close($pE);

    foreach ((array)glob("$dir/*/*") as $f) { @unlink($f); }
    foreach ((array)glob("$dir/*") as $f) { @rmdir($f); }
    @rmdir($dir);

    // 0 lecturas mezcladas de las que haya hecho
    return preg_match('#^0/\d+$#', trim($salida)) === 1 ?: trim($salida);
});

echo "\n== Barrido de temporales entre procesos ==\n";

chk('escribir una tabla no borra el temporal de otra que se está escribiendo', function () use ($raiz) {
    // El barrido de temporales huérfanos no mira de qué proceso son, así que la
    // única garantía de no pisar una escritura viva es el bloqueo: solo se
    // borran los de la tabla cuyo exclusivo se tiene. Esto lo comprueba con dos
    // procesos de verdad escribiendo a la vez en tablas distintas.
    $dir = $raiz . '/barrido';
    borrarArbol($dir);
    @mkdir($dir, 0775, true);

    Database::crear('b', $dir);
    $bd = new Database('b', $dir);
    $bd->consultar('CREATE TABLE uno (id INTEGER PRIMARY KEY, v VARCHAR(10))');
    $bd->consultar('CREATE TABLE dos (id INTEGER PRIMARY KEY, v VARCHAR(10))');
    $bd->consultar("INSERT INTO uno VALUES (1,'a')");
    $bd->consultar("INSERT INTO dos VALUES (1,'a')");
    unset($bd);

    // Temporales huérfanos de las dos tablas, como los que deja un proceso
    // matado a mitad de una escritura
    $ajeno  = "$dir/b/dos.json.999999.tmp";
    $propio = "$dir/b/uno.json.999998.tmp";
    file_put_contents($ajeno, 'a medias');
    file_put_contents($propio, 'a medias');

    // Una escritura en 'uno': tiene el exclusivo de 'uno' y solo el compartido
    // de la base, así que no puede saber si alguien está escribiendo 'dos'
    $bd = new Database('b', $dir);
    $bd->consultar("UPDATE uno SET v = 'b' WHERE id = 1");
    unset($bd);

    $sobreviveAjeno = is_file($ajeno);
    $barridoPropio  = !is_file($propio);

    // Y una operación con el exclusivo de la base sí puede con todo, porque
    // excluye a cualquier otro proceso
    $bd = new Database('b', $dir);
    $bd->consultar('CREATE TABLE tres (id INTEGER PRIMARY KEY)');
    unset($bd);
    $barridoTodo = !is_file($ajeno);

    borrarArbol($dir);

    if (!$barridoPropio) { return 'no barrió el temporal de la tabla que estaba escribiendo'; }
    if (!$sobreviveAjeno) { return 'BORRÓ el temporal de otra tabla, que podía estar viva'; }
    return $barridoTodo ?: 'con el exclusivo de la base no barrió el que quedaba';
});

chk('dos procesos escribiendo tablas distintas a la vez no se pisan', function () use ($raiz) {
    // Lo mismo, pero con concurrencia real: uno escribe 'uno' en bucle mientras
    // el otro escribe 'dos', y ninguno puede perder una escritura del otro.
    $dir = $raiz . '/barrido2';
    borrarArbol($dir);
    @mkdir($dir, 0775, true);

    Database::crear('b', $dir);
    $bd = new Database('b', $dir);
    $bd->consultar('CREATE TABLE uno (id INTEGER PRIMARY KEY, v INTEGER)');
    $bd->consultar('CREATE TABLE dos (id INTEGER PRIMARY KEY, v INTEGER)');
    $bd->consultar('INSERT INTO uno VALUES (1,0)');
    $bd->consultar('INSERT INTO dos VALUES (1,0)');
    unset($bd);

    $cabecera = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
              . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
              . '$bd = new JsonSQLDB\\Database("b", ' . var_export($dir, true) . ');';

    $procs = [];
    foreach (['uno', 'dos'] as $tabla) {
        $codigo = $cabecera
                . 'for ($i = 1; $i <= 40; $i++) {'
                . '  $bd->consultar("UPDATE ' . $tabla . ' SET v = ? WHERE id = 1", [$i]);'
                . '}';
        $p = proc_open([PHP_BINARY, '-r', $codigo],
                       [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
        if (!is_resource($p)) { return 'no se pudo lanzar el proceso'; }
        $procs[] = [$p, $tub];
    }
    $errores = [];
    foreach ($procs as [$p, $tub]) {
        $salida = '';
        foreach ($tub as $t) { $salida .= stream_get_contents($t); fclose($t); }
        if (proc_close($p) !== 0 || trim($salida) !== '') {
            $errores[] = trim(substr($salida, 0, 200));
        }
    }
    if ($errores !== []) {
        borrarArbol($dir);
        return 'algún proceso falló -> ' . implode(' | ', $errores);
    }

    $bd = new Database('b', $dir);
    $u = (int)$bd->consultar('SELECT v FROM uno WHERE id = 1')[0]['v'];
    $d = (int)$bd->consultar('SELECT v FROM dos WHERE id = 1')[0]['v'];
    $restos = glob("$dir/b/*.tmp");
    unset($bd);
    borrarArbol($dir);

    if ($u !== 40 || $d !== 40) { return "las escrituras se perdieron: uno=$u dos=$d"; }
    return $restos === [] ?: 'quedaron temporales: ' . implode(', ', array_map('basename', $restos));
});

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
