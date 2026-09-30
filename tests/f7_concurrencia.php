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
 *
 * https://miguelenred.es/jsonsqldb
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

/**
 * ¿Hay algún journal en la base? La carpeta .tx puede existir vacía, y un
 * temporal de manifiesto que dejó un proceso muerto es basura, no un journal:
 * lo barre la siguiente escritura con el exclusivo de la base.
 */
function hayJournal(string $base): bool {
    foreach ((array)glob("$base/.tx/*") as $f) {
        if (substr((string)$f, -4) !== '.tmp') { return true; }
    }
    return false;
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

chk('un escritor entra aunque haya lectores leyendo sin parar', function () use ($raiz) {
    // flock no da preferencia: un exclusivo espera a que no quede ningún
    // compartido, y con lectores que se solapan sin parar podía esperar
    // segundos. El torno (ver Storage::abrirLock) hace que los lectores nuevos
    // se paren mientras un escritor espera. Tres lectores leyendo en bucle
    // durante dos segundos y un escritor insertando en bucle: sin torno el
    // escritor hacía una o dos filas; con él, decenas.
    // Los lectores cogen el bloqueo directamente y lo retienen unos
    // milisegundos, como haría una consulta larga sobre una tabla grande
    $lector = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$st = new JsonSQLDB\\Storage(' . var_export($raiz, true) . ', "conc"); $fin = microtime(true) + 2;'
            . 'while (microtime(true) < $fin) { $st->bloquear(false); $st->leerMeta("libres_a"); usleep(3000); $st->desbloquear(); }';
    $escritor = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$bd = new JsonSQLDB\\Database("conc", ' . var_export($raiz, true) . '); $fin = microtime(true) + 2; $n = 0;'
            . 'while (microtime(true) < $fin) { $bd->consultar("INSERT INTO libres_a (v) VALUES (?)", ["torno"]); $n++; }'
            . 'echo $n;';
    $correr = static function (string $codigo, &$tubs) {
        $tubs = [];
        return proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubs);
    };
    // Primero el escritor solo, para saber a qué ritmo escribe esta máquina
    $pE = $correr($escritor, $tE);
    $solo = (int)trim((string)stream_get_contents($tE[1]));
    fclose($tE[1]); fclose($tE[2]); proc_close($pE);

    // Luego con tres lectores encima. Un ejecutor de CI cargado puede dar una
    // vuelta mala: se repite una vez antes de dar el fallo por bueno
    $conLectores = static function () use ($correr, $lector, $escritor): array {
        $procs = [];
        $tubs  = [];
        for ($i = 0; $i < 3; $i++) {
            $procs[] = $correr($lector, $tubs[$i]);
        }
        usleep(100000);                                 // que los lectores estén ya dentro
        $pE = $correr($escritor, $tE);
        $escritas = (int)trim((string)stream_get_contents($tE[1]));
        $err = trim((string)stream_get_contents($tE[2]));
        fclose($tE[1]); fclose($tE[2]); proc_close($pE);
        foreach ($procs as $i => $p) {
            foreach ($tubs[$i] as $t) { stream_get_contents($t); fclose($t); }
            proc_close($p);
        }
        return [$escritas, $err];
    };
    $vueltas = [];
    for ($k = 0; $k < 2; $k++) {
        [$e, $err] = $conLectores();
        if ($err !== '') { return "el escritor falló: " . substr($err, 0, 200); }
        $vueltas[] = $e;
        if ($e * 100 >= $solo * 15) {
            break;
        }
    }
    $escritas = max($vueltas);
    $bd = new Database('conc', $raiz);
    $enTabla = (int)$bd->consultar("SELECT COUNT(*) AS n FROM libres_a WHERE v = 'torno'")[0]['n'];
    if ($enTabla !== $solo + array_sum($vueltas)) {
        return "los escritores dicen " . ($solo + array_sum($vueltas)) . " filas y hay $enTabla";
    }
    echo "       escritor solo: $solo filas en 2 s; con tres lectores encima: " . implode(' y luego ', $vueltas) . "\n";
    // Sin torno se queda en torno al 7 % de su ritmo en solitario; con él, entre
    // el 25 y el 45 % según la máquina. El límite, en medio y con holgura
    return $escritas * 100 >= $solo * 15
        ?: "con lectores solo pudo insertar $escritas filas frente a $solo en solitario";
});

chk('las claves foráneas quedan bien', function () use ($raiz) {
    $bd = new Database('conc', $raiz);
    return $bd->consultar('CHECK KEYS') === [];
});

chk('no quedan journals a medias', fn() => !hayJournal($raiz . '/conc'));

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

echo "\n== Bloqueo por tablas con claves foráneas ==\n";

chk('dos escrituras sobre grupos de tablas sin relación no se esperan', function () use ($raiz) {
    // Una escritura con claves foráneas o triggers puede propagar a otras
    // tablas, y hasta la 2.2 eso obligaba a bloquear la base entera: cualquier
    // otra escritura, aunque fuera a tablas que no tienen nada que ver, tenía
    // que esperar.
    //
    // Ahora se calcula antes el conjunto de tablas alcanzables y se bloquean
    // solo esas. Aquí hay dos grupos padre/hija independientes: escribir en uno
    // no puede hacer esperar al otro.
    //
    // Se mide por solapamiento y no por tiempo total: esta máquina tiene un solo
    // núcleo, así que dos procesos que van a la vez tardan lo mismo que en fila.
    // Lo que se comprueba es si el segundo ENTRA mientras el primero retiene.
    $dir = $raiz . '/fk';
    borrarArbol($dir);
    @mkdir($dir, 0775, true);

    Database::crear('f', $dir);
    $bd = new Database('f', $dir);
    foreach (['a', 'b'] as $g) {
        $bd->consultar("CREATE TABLE {$g}padre (id INTEGER PRIMARY KEY, v INTEGER)");
        $bd->consultar("CREATE TABLE {$g}hija (id INTEGER PRIMARY KEY, pid INTEGER)");
        $bd->consultar("ALTER TABLE {$g}hija ADD CONSTRAINT fk_$g
                        FOREIGN KEY (pid) REFERENCES {$g}padre(id) ON DELETE CASCADE");
        $bd->consultar("INSERT INTO {$g}padre VALUES (1, 0)");
    }
    unset($bd);

    // Cada hijo toma los bloqueos de su grupo, dice cuándo entra y cuándo sale,
    // y los retiene un rato en medio
    $hijo = static function (string $grupo) use ($dir): string {
        return 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
             . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
             . '$st = new JsonSQLDB\\Storage(' . var_export($dir, true) . ', "f");'
             . '$st->bloquear(true, ["' . $grupo . 'hija", "' . $grupo . 'padre"]);'
             . 'echo microtime(true), " ";'
             . 'usleep(250000);'
             . 'echo microtime(true);'
             . '$st->desbloquear();';
    };

    $lanzar = static function (string $codigo) {
        return proc_open([PHP_BINARY, '-r', $codigo],
                         [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub) ?: null;
    };

    $ventanas = [];
    foreach ([['a', 'b'], ['a', 'a']] as $par) {
        $procs = [];
        foreach ($par as $g) {
            $p = proc_open([PHP_BINARY, '-r', $hijo($g)],
                           [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
            if (!is_resource($p)) { borrarArbol($dir); return 'no se pudo lanzar el proceso'; }
            $procs[] = [$p, $tub];
            usleep(30000);                          // que el primero llegue antes
        }
        $tiempos = [];
        foreach ($procs as [$p, $tub]) {
            $salida = '';
            foreach ($tub as $t) { $salida .= stream_get_contents($t); fclose($t); }
            proc_close($p);
            $partes = preg_split('/\s+/', trim($salida));
            if (count($partes) !== 2 || !is_numeric($partes[0])) {
                borrarArbol($dir);
                return 'un proceso no dio sus tiempos: ' . substr(trim($salida), 0, 150);
            }
            $tiempos[] = [(float)$partes[0], (float)$partes[1]];
        }
        // ¿Se solapan las dos ventanas de retención?
        $ventanas[] = $tiempos[0][0] < $tiempos[1][1] && $tiempos[1][0] < $tiempos[0][1];
    }
    borrarArbol($dir);

    if (!$ventanas[0]) {
        return 'dos grupos sin relación se esperaron: el bloqueo sigue siendo de base';
    }
    if ($ventanas[1]) {
        return 'dos escrituras del MISMO grupo se solaparon: no se están excluyendo';
    }
    return true;
});

echo "\n== Borrar una base en uso ==\n";

chk('DROP DATABASE espera a que acabe una lectura larga de esa base', function () use ($raiz) {
    // Un proceso lee la base con su bloqueo cogido durante un segundo; el
    // borrado tiene que esperar a que lo suelte, no borrar los ficheros por
    // debajo de la lectura
    $dir = $raiz . '/borrado';
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('b', $dir);
    $bd = new Database('b', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY)');
    $bd->consultar('INSERT INTO t VALUES (1), (2), (3)');
    unset($bd);
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$st = new JsonSQLDB\\Storage(' . var_export($dir, true) . ', "b"); $st->bloquear(false); echo "dentro\\n"; flush();'
            . 'usleep(1000000); $n = 0; foreach ($st->filas("t") as $f) { $n++; } $st->desbloquear(); echo $n;';
    $p = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub);
    fgets($tub[1]);                                     // el lector ya tiene el bloqueo
    $t = microtime(true);
    Database::borrar('b', $dir);
    $espera = microtime(true) - $t;
    $leidas = trim((string)stream_get_contents($tub[1]));
    $err = trim((string)stream_get_contents($tub[2]));
    fclose($tub[1]); fclose($tub[2]); proc_close($p);
    if ($err !== '') { return 'el lector falló: ' . substr($err, 0, 200); }
    if ($leidas !== '3') { return "el lector vio $leidas filas en vez de 3: se le borró la base por debajo"; }
    if (is_dir("$dir/b")) { return 'la base no se borró'; }
    return $espera > 0.8 ?: sprintf('el borrado no esperó (%.2f s)', $espera);
});

echo "\n== Escritura por partes ==\n";

/**
 * Lanza procesos que ejecutan cada uno su lista de sentencias y espera a que
 * acaben. Devuelve lo que escribió cada uno (las cuentas de escrituras por
 * partes y repetidas) o el error del que falló.
 */
function enParalelo(string $dir, array $listas): array {
    $cabecera = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
              . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
              . '$bd = new JsonSQLDB\\Database("p", ' . var_export($dir, true) . ');';
    $procs = [];
    foreach ($listas as $sentencias) {
        $codigo = $cabecera . 'foreach (' . var_export($sentencias, true) . ' as $s) { $bd->consultar($s); }'
                . 'echo json_encode(JsonSQLDB\\Storage::cuentaPartes());';
        $tub = [];
        $procs[] = [proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tub), $tub];
    }
    $salidas = [];
    foreach ($procs as [$p, $tub]) {
        $out = (string)stream_get_contents($tub[1]);
        $err = trim((string)stream_get_contents($tub[2]));
        fclose($tub[1]); fclose($tub[2]);
        proc_close($p);
        $salidas[] = $err !== '' ? ['error' => substr($err, 0, 300)] : (json_decode($out, true) ?: ['error' => $out]);
    }
    return $salidas;
}

function tablaPorPartes(string $dir, int $filas): Database {
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('p', $dir);
    $bd = new Database('p', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, grupo INTEGER, v INTEGER, nota VARCHAR(20))');
    $bd->consultar('CREATE INDEX ix_grupo ON t (grupo)');
    $vals = [];
    for ($i = 1; $i <= $filas; $i++) { $vals[] = "($i, " . ($i % 7) . ", 0, 'n$i')"; }
    foreach (array_chunk($vals, 1000) as $b) { $bd->consultar('INSERT INTO t VALUES ' . implode(',', $b)); }
    return $bd;
}

chk('cuatro procesos sumando en la misma fila no pierden ninguna suma', function () use ($raiz) {
    // Todos reescriben la misma parte: cada vez, uno confirma y los demás se
    // encuentran la parte cambiada y se repiten con la tabla entera. Si eso
    // no se comprobara, cada uno escribiría su v+1 sobre el mismo v y se
    // perderían sumas. Cuatro procesos por cincuenta sumas: 200 exactas
    $dir = $raiz . '/partes1';
    $bd  = tablaPorPartes($dir, 3000);
    $listas = array_fill(0, 4, array_fill(0, 50, 'UPDATE t SET v = v + 1 WHERE id = 1500'));
    $r = enParalelo($dir, $listas);
    foreach ($r as $x) { if (isset($x['error'])) { return 'un proceso falló: ' . $x['error']; } }
    $v = (int)$bd->consultar('SELECT v FROM t WHERE id = 1500')[0]['v'];
    $partes = array_sum(array_column($r, 0));
    $repetidas = array_sum(array_column($r, 1));
    echo "       por partes: $partes, repetidas con la tabla entera: $repetidas\n";
    if ($v !== 200) { return "se perdieron sumas: v = $v y debía ser 200"; }
    return $partes > 0 ?: 'no se escribió nada por partes';
});

chk('procesos en partes distintas, con un DELETE a la vez, dejan la tabla exacta', function () use ($raiz) {
    // Tres procesos suman en filas de partes distintas y un cuarto borra filas
    // del final. Al acabar, cada suma tiene que estar, las borradas no, los
    // índices tienen que decir lo mismo que recorrer la tabla, y no puede
    // quedar ni un temporal ni un journal
    $dir = $raiz . '/partes2';
    $bd  = tablaPorPartes($dir, 5000);
    $listas = [];
    foreach ([10, 1200, 2400] as $base) {
        $l = [];
        for ($k = 0; $k < 30; $k++) { $l[] = 'UPDATE t SET v = v + 1, nota = \'x\' WHERE id = ' . ($base + $k % 10); }
        $listas[] = $l;
    }
    $borrar = [];
    for ($k = 0; $k < 20; $k++) { $borrar[] = 'DELETE FROM t WHERE id = ' . (4990 - $k); }
    $listas[] = $borrar;
    $r = enParalelo($dir, $listas);
    foreach ($r as $x) { if (isset($x['error'])) { return 'un proceso falló: ' . $x['error']; } }
    foreach ([10, 1200, 2400] as $base) {
        $sum = (int)$bd->consultar('SELECT SUM(v) AS s FROM t WHERE id BETWEEN ? AND ?', [$base, $base + 9])[0]['s'];
        if ($sum !== 30) { return "en las filas desde $base la suma es $sum y debía ser 30"; }
    }
    if ((int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'] !== 4980) { return 'no se borraron 20 filas'; }
    for ($g = 0; $g < 7; $g++) {
        $a = $bd->consultar('SELECT id FROM t WHERE grupo = ? ORDER BY id', [$g]);
        $b = $bd->consultar('SELECT id FROM t WHERE grupo + 0 = ? ORDER BY id', [$g]);
        if ($a !== $b) { return "el índice del grupo $g no cuadra con la tabla"; }
    }
    foreach ([1, 1205, 2403, 4970] as $id) {
        if ($bd->consultar('SELECT id FROM t WHERE id = ?', [$id]) !== $bd->consultar('SELECT id FROM t WHERE id + 0 = ?', [$id])) {
            return "la clave primaria no encuentra $id como el recorrido";
        }
    }
    if (glob("$dir/p/*.tmp") !== [] || (array)glob("$dir/p/.tx/*") !== []) { return 'quedaron temporales o journals'; }
    return array_sum(array_column($r, 0)) > 0 ?: 'no se escribió nada por partes';
});

chk('con una clave única, una columna única no se escribe por partes', function () use ($raiz) {
    // Dos procesos poniendo el mismo valor en una columna UNIQUE de filas de
    // partes distintas: por partes, ninguno vería al otro y los dos
    // confirmarían. Con la tabla entera, el segundo tiene que fallar
    $dir = $raiz . '/partes3';
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('p', $dir);
    $bd = new Database('p', $dir);
    $bd->consultar('CREATE TABLE u (id INTEGER PRIMARY KEY, email VARCHAR(40) UNIQUE)');
    $vals = [];
    for ($i = 1; $i <= 3000; $i++) { $vals[] = "($i, 'e$i')"; }
    $bd->consultar('INSERT INTO u VALUES ' . implode(',', $vals));
    $r = enParalelo($dir, [["UPDATE u SET email = 'mismo' WHERE id = 10"], ["UPDATE u SET email = 'mismo' WHERE id = 2900"]]);
    $n = (int)$bd->consultar("SELECT COUNT(*) AS n FROM u WHERE email = 'mismo'")[0]['n'];
    $fallos = count(array_filter($r, static fn($x) => isset($x['error'])));
    return $n === 1 && $fallos === 1 ?: "quedaron $n filas con el mismo email ($fallos procesos fallaron)";
});

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
