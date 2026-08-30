<?php
declare(strict_types=1);

/**
 * Prueba de recuperación ante cortes. Ejecutar: php tests/f6_cortes.php
 *
 * Las demás pruebas del journal montan el .tx a mano y comprueban que deshacer
 * funciona. Eso verifica el mecanismo, pero no lo que de verdad importa: que una
 * operación real abra el journal ANTES de tocar ningún fichero.
 *
 * Aquí se mata el proceso de verdad, con SIGKILL, mientras ejecuta un DELETE en
 * cascada. SIGKILL no se puede capturar: no hay destructores, ni funciones de
 * cierre, ni oportunidad de limpiar. Es lo más parecido a un corte de luz que se
 * puede provocar desde dentro.
 *
 * Después se abre la base y se exige que esté en uno de los dos estados válidos:
 * el borrado entero o nada de él. Cualquier estado intermedio es corrupción.
 *
 * Muchas de las muertes caerán fuera de la ventana crítica, y eso está bien: la
 * prueba solo puede dar falsos negativos (no llegar a probar el caso), nunca
 * falsos positivos. Al final informa de cuántas cayeron dentro.
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);
// Partes pequeñas: así una tabla de mil filas ocupa varios ficheros y las
// escrituras que se van a interrumpir tienen de verdad más de un rename que
// hacer. Con el valor por defecto todo cabría en uno y no se probaría nada.
define('JSONSQLDB_FILAS_POR_PARTE', 100);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\Storage;

$raiz    = sys_get_temp_dir() . '/jsonsqldb_test_cortes';
$INTENTOS = 12;          // muertes que se provocan
$HIJAS    = 1200;        // filas de la tabla hija, para que volcar() tarde algo
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

/** Deja la base recién creada con su cascada y sus datos. */
function prepararBase(string $raiz, int $hijas): void {
    borrarArbol($raiz);
    clearstatcache();
    if (is_dir($raiz)) {
        // Un hijo muerto puede haber dejado un fichero recién creado
        borrarArbol($raiz);
    }
    @mkdir($raiz, 0775, true);

    Database::crear('cortes', $raiz);
    $bd = new Database('cortes', $raiz);
    $bd->consultar('CREATE TABLE padres (id INTEGER PRIMARY KEY AUTOINCREMENT, n VARCHAR(20))');
    $bd->consultar('CREATE TABLE hijas  (id INTEGER PRIMARY KEY AUTOINCREMENT,
                                         padre_id INTEGER, t VARCHAR(40))');
    $bd->consultar('ALTER TABLE hijas ADD CONSTRAINT fk_c FOREIGN KEY (padre_id)
                    REFERENCES padres(id) ON DELETE CASCADE');

    // Los ids se fijan a mano: el autoincremento empieza en 1, pero conviene
    // no depender de eso para que la cascada apunte a los padres correctos
    $bd->consultar("INSERT INTO padres (id, n) VALUES (1, 'uno'), (2, 'dos')");
    for ($i = 0; $i < $hijas; $i++) {
        $bd->consultar('INSERT INTO hijas (padre_id, t) VALUES (?, ?)',
                       [($i % 2) + 1, 'fila número ' . $i]);
    }
}

// ----------------------------------------------------------------------

echo "\n== Recuperación tras matar el proceso a mitad de una cascada ==\n";

if (!function_exists('proc_open')) {
    echo "  OMITIDA: proc_open no está disponible\n";
    echo "\n---------------------------------------\nOK: 0   FALLOS: 0\n";
    exit(0);
}

// El hijo borra el padre 1, lo que arrastra la mitad de las hijas
$hijo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
      . 'define("JSONSQLDB_FILAS_POR_PARTE", 100);'
      . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
      . '$bd = new JsonSQLDB\\Database("cortes", ' . var_export($raiz, true) . ');'
      . '$bd->consultar("DELETE FROM padres WHERE id = 1");'
      . 'echo "terminado";';

$dentro     = 0;   // muertes dentro de la ventana: quedó un journal que deshacer
$deshechas  = 0;   // las que se recuperaron correctamente
$aplicadas  = 0;   // el borrado ya había terminado
$corruptas  = [];

prepararBase($raiz, $HIJAS);
$bd     = new Database('cortes', $raiz);
$PADRES = (int)$bd->consultar('SELECT COUNT(*) AS n FROM padres')[0]['n'];
$HIJAS_TOTAL = (int)$bd->consultar('SELECT COUNT(*) AS n FROM hijas')[0]['n'];
unset($bd);

for ($i = 1; $i <= $INTENTOS; $i++) {
    prepararBase($raiz, $HIJAS);

    // El comando va como array, no como cadena: con cadena PHP lanza un shell
    // intermedio y proc_terminate mataría al shell, dejando vivo el PHP hijo.
    $proc = proc_open(
        [PHP_BINARY, '-r', $hijo],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $tuberias
    );
    if (!is_resource($proc)) {
        echo "  FALLO no se pudo lanzar el proceso hijo\n";
        $ko++;
        break;
    }

    // El hijo completo tarda unos 40 ms: arrancar PHP, cargar el motor, leer las
    // tablas y escribir. La escritura es el último tramo, así que se apunta ahí.
    // Muchas caerán antes o después, y no pasa nada.
    usleep(random_int(20000, 45000));
    proc_terminate($proc, 9);          // SIGKILL: no se puede capturar
    foreach ($tuberias as $t) { @fclose($t); }
    proc_close($proc);

    $huboJournal = is_dir($raiz . '/cortes/.tx');
    if ($huboJournal) { $dentro++; }

    // Se abre la base: aquí es donde debe actuar la recuperación
    $bd2    = new Database('cortes', $raiz);
    $padres = (int)$bd2->consultar('SELECT COUNT(*) AS n FROM padres')[0]['n'];
    $hijas  = (int)$bd2->consultar('SELECT COUNT(*) AS n FROM hijas')[0]['n'];
    $quedaTx = is_dir($raiz . '/cortes/.tx');
    unset($bd2);

    $sinTocar  = $padres === $PADRES     && $hijas === $HIJAS_TOTAL;
    $completo  = $padres === $PADRES - 1 && $hijas === intdiv($HIJAS_TOTAL, 2);

    if ($sinTocar)      { $deshechas++; }
    elseif ($completo)  { $aplicadas++; }
    else {
        $corruptas[] = "intento $i: padres=$padres hijas=$hijas (esperado $PADRES/$HIJAS_TOTAL o "
                     . ($PADRES - 1) . '/' . intdiv($HIJAS_TOTAL, 2) . ')';
    }
    if ($quedaTx) {
        $corruptas[] = "intento $i: el journal seguía ahí después de abrir la base";
    }
}

chk("$INTENTOS muertes con SIGKILL y ninguna base a medias", function () use (&$corruptas) {
    return $corruptas === [] ?: implode(' | ', array_slice($corruptas, 0, 3));
});

chk('alguna muerte cayó dentro de la ventana crítica', function () use ($dentro, $INTENTOS) {
    if ($dentro === 0) {
        // No es un fallo del motor: es que las muertes cayeron fuera. Se avisa
        // para que nadie crea que la prueba ha demostrado más de lo que ha visto.
        echo "       (ninguna de las $INTENTOS cayó dentro; la prueba no ha ejercitado la recuperación)\n";
    }
    return true;
});

chk('la recuperación deja la base utilizable', function () use ($raiz) {
    $bd = new Database('cortes', $raiz);
    $bd->consultar("INSERT INTO padres (n) VALUES ('después del corte')");
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM padres')[0]['n'];
    return $n >= 1;
});

chk('las claves foráneas quedan íntegras', function () use ($raiz) {
    $bd = new Database('cortes', $raiz);
    return $bd->consultar('CHECK KEYS') === [];
});

echo "\n  Resumen: $dentro de $INTENTOS muertes dentro de la ventana"
   . "  ·  $deshechas deshechas  ·  $aplicadas ya aplicadas\n";

// ======================================================================
// Segundo bloque: una sola tabla, repartida en varias partes y con índices
//
// Es el caso que el journal no cubría. Una tabla de mil filas con partes de
// cien ocupa diez ficheros de datos, más el de estructura, el de revisión y uno
// por índice: catorce renames que no son un solo acto. Si el proceso muere
// entre dos de ellos y no hay journal, quedan partes nuevas junto a partes
// viejas. Y como el reparto en partes es por POSICIÓN, eso no pierde «unas
// filas»: descuadra la tabla entera a partir del corte.
//
// Aquí se comprueba lo único que vale: al reabrir, todas las filas tienen que
// ser las de antes o las de después, nunca una mezcla.
// ======================================================================

echo "\n== Recuperación tras matar el proceso escribiendo una tabla partida ==\n";

$FILAS_P = 2500;

function prepararPartida(string $raiz, int $filas): void {
    borrarArbol($raiz);
    clearstatcache();
    @mkdir($raiz, 0775, true);

    Database::crear('cortes', $raiz);
    $bd = new Database('cortes', $raiz);
    $bd->consultar('CREATE TABLE particionada (id INTEGER PRIMARY KEY AUTOINCREMENT,
                                               codigo VARCHAR(20) UNIQUE, v VARCHAR(40))');
    $bd->consultar('CREATE INDEX idx_v ON particionada (v)');

    $vals = [];
    for ($i = 1; $i <= $filas; $i++) {
        $vals[] = "($i, 'c$i', 'viejo')";
    }
    $bd->consultar('INSERT INTO particionada (id, codigo, v) VALUES ' . implode(',', $vals));
}

// El hijo reescribe TODAS las filas: todas las partes y todos los índices
$hijoP = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
       . 'define("JSONSQLDB_FILAS_POR_PARTE", 100);'
       . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
       . '$bd = new JsonSQLDB\\Database("cortes", ' . var_export($raiz, true) . ');'
       . '$bd->consultar("UPDATE particionada SET v = \'nuevo\'");'
       . 'echo "terminado";';

$dentroP = 0; $mezcladas = []; $problemas = [];

for ($i = 1; $i <= $INTENTOS; $i++) {
    prepararPartida($raiz, $FILAS_P);

    $proc = proc_open([PHP_BINARY, '-r', $hijoP],
                      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    if (!is_resource($proc)) { break; }

    usleep(random_int(20000, 110000));
    proc_terminate($proc, 9);
    foreach ($tuberias as $t) { @fclose($t); }
    proc_close($proc);

    if (is_dir($raiz . '/cortes/.tx')) { $dentroP++; }

    $bd2 = new Database('cortes', $raiz);

    // 1. Ni una fila mezclada: o todas viejas o todas nuevas
    $viejas = (int)$bd2->consultar("SELECT COUNT(*) AS n FROM particionada WHERE v = 'viejo'")[0]['n'];
    $nuevas = (int)$bd2->consultar("SELECT COUNT(*) AS n FROM particionada WHERE v = 'nuevo'")[0]['n'];
    $total  = (int)$bd2->consultar('SELECT COUNT(*) AS n FROM particionada')[0]['n'];

    if ($total !== $FILAS_P) {
        $problemas[] = "intento $i: quedaron $total filas de $FILAS_P";
    }
    if ($viejas !== $FILAS_P && $nuevas !== $FILAS_P) {
        $mezcladas[] = "intento $i: $viejas viejas y $nuevas nuevas";
    }

    // 2. El índice tiene que decir lo mismo que la tabla. Se compara la
    //    búsqueda por igualdad —que usa el índice— con el recorrido completo,
    //    que no lo usa porque el LIKE no es una igualdad.
    $porIndice = (int)$bd2->consultar("SELECT COUNT(*) AS n FROM particionada WHERE v = 'viejo'")[0]['n'];
    $porEscaneo = (int)$bd2->consultar("SELECT COUNT(*) AS n FROM particionada WHERE v LIKE 'viejo'")[0]['n'];
    if ($porIndice !== $porEscaneo) {
        $problemas[] = "intento $i: el índice dice $porIndice y la tabla $porEscaneo";
    }

    // 3. Y la clave primaria, que también tiene índice automático
    $unaPorIndice = $bd2->consultar('SELECT v FROM particionada WHERE id = 750');
    $unaPorEscaneo = $bd2->consultar('SELECT v FROM particionada WHERE id + 0 = 750');
    if (($unaPorIndice[0]['v'] ?? null) !== ($unaPorEscaneo[0]['v'] ?? null)) {
        $problemas[] = "intento $i: la PK indexada y el escaneo no coinciden";
    }
    unset($bd2);

    // 4. La caché no puede quedarse con lo de antes del corte: se compara lo
    //    que devuelve el motor con lo que hay de verdad en los ficheros.
    $st = new Storage($raiz, 'cortes');
    $st->bloquear(false);
    $conCache = $st->leerFilas('particionada');
    $sinCache = $st->leerFilas('particionada', true);
    $st->desbloquear();
    unset($st);
    if ($conCache !== $sinCache) {
        $problemas[] = "intento $i: la caché no coincide con el disco tras el corte";
    }

    if (is_dir($raiz . '/cortes/.tx')) {
        $problemas[] = "intento $i: quedó journal sin deshacer";
    }
    // Un proceso matado con SIGKILL no ejecuta ningún finally, así que puede
    // dejar su temporal en disco: eso no se puede evitar desde dentro. Lo que
    // sí se puede es que no se acumulen. La siguiente escritura de la tabla
    // tiene su bloqueo exclusivo, sabe que nadie más la está escribiendo, y
    // barre los temporales ajenos que encuentre.
    $bd3 = new Database('cortes', $raiz);
    $bd3->consultar("UPDATE particionada SET v = v WHERE id = 1");
    unset($bd3);
    $restos = glob($raiz . '/cortes/*.tmp');
    if ($restos !== []) {
        // Con el nombre: sin él, un fallo aquí no dice de qué fichero es el
        // temporal, que es justo lo que hace falta para saber por qué no se barrió
        $problemas[] = "intento $i: quedaron temporales sin barrer -> "
                     . implode(', ', array_map('basename', $restos));
    }
}

chk("$INTENTOS muertes escribiendo una tabla partida y ninguna fila mezclada",
    fn() => $mezcladas === [] ?: implode(' | ', array_slice($mezcladas, 0, 3)));

chk('índices, caché y ficheros coherentes tras cada corte',
    fn() => $problemas === [] ?: implode(' | ', array_slice($problemas, 0, 3)));

chk('alguna muerte cayó dentro de la ventana de la tabla partida', function () use ($dentroP, $INTENTOS) {
    if ($dentroP === 0) {
        echo "       (ninguna de las $INTENTOS cayó dentro; la prueba no ha ejercitado la recuperación)\n";
    }
    return true;
});

chk('la tabla partida sigue siendo utilizable y sus índices sirven', function () use ($raiz) {
    $bd = new Database('cortes', $raiz);
    $bd->consultar("INSERT INTO particionada (codigo, v) VALUES ('nuevo_tras_corte', 'z')");
    $r = $bd->consultar("SELECT id FROM particionada WHERE codigo = 'nuevo_tras_corte'");
    return count($r) === 1;
});

echo "\n  Resumen: $dentroP de $INTENTOS muertes dentro de la ventana de la tabla partida\n";

// ======================================================================
// Tercer bloque: matar el proceso en cada tipo de operación
//
// Los dos bloques anteriores aprietan una operación concreta muchas veces. Este
// pasa por todas: cada una toca un juego distinto de ficheros y se rompe de una
// forma distinta. Después de cada muerte se exige lo mismo — la base abre, el
// estado es uno de los dos válidos, y las claves foráneas cuadran.
// ======================================================================

echo "\n== Muerte durante cada tipo de operación ==\n";

/** Prepara una base con lo justo para poder romper cualquier cosa. */
function baseVariada(string $raiz): void {
    borrarArbol($raiz);
    clearstatcache();
    @mkdir($raiz, 0775, true);
    Database::crear('cortes', $raiz);
    $bd = new Database('cortes', $raiz);
    $bd->consultar('CREATE TABLE art (id INTEGER PRIMARY KEY AUTOINCREMENT,
                                      ref VARCHAR(20) UNIQUE, cat VARCHAR(20), v VARCHAR(40))');
    $vals = [];
    for ($i = 1; $i <= 300; $i++) { $vals[] = "($i,'R$i','c" . ($i % 5) . "','viejo')"; }
    $bd->consultar('INSERT INTO art (id, ref, cat, v) VALUES ' . implode(',', $vals));
    $bd->consultar('CREATE INDEX idx_cat ON art (cat)');
}

/**
 * Lanza una sentencia en otro proceso, lo mata a mitad, reabre la base y
 * comprueba que está en un estado sano. Devuelve [dentro, problema|null].
 */
function matarDurante(string $raiz, string $sql, callable $sano): array {
    baseVariada($raiz);

    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'define("JSONSQLDB_FILAS_POR_PARTE", 100);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$bd = new JsonSQLDB\\Database("cortes", ' . var_export($raiz, true) . ');'
            . '$bd->consultar(' . var_export($sql, true) . ');';

    $proc = proc_open([PHP_BINARY, '-r', $codigo],
                      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    if (!is_resource($proc)) { return [false, 'no se pudo lanzar el proceso']; }

    usleep(random_int(15000, 55000));
    proc_terminate($proc, 9);
    foreach ($tuberias as $t) { @fclose($t); }
    proc_close($proc);

    $dentro = is_dir($raiz . '/cortes/.tx');

    try {
        $bd = new Database('cortes', $raiz);
        // La recuperación actúa al coger el bloqueo, que es cosa de la primera
        // consulta y no de construir el objeto
        $bd->consultar('SHOW TABLES');
    } catch (Throwable $e) {
        return [$dentro, 'la base no abre: ' . $e->getMessage()];
    }

    if (is_dir($raiz . '/cortes/.tx')) {
        return [$dentro, 'quedó journal sin deshacer'];
    }
    if (glob($raiz . '/cortes/*.tmp') !== []) {
        // Un SIGKILL puede dejar el temporal; lo que no puede es acumularse.
        // Se provoca una escritura que no dependa de qué tablas o columnas hayan
        // sobrevivido a la operación interrumpida.
        $bd->consultar('CREATE TABLE _barrido (id INTEGER PRIMARY KEY)');
        $bd->consultar('DROP TABLE _barrido');
        if (glob($raiz . '/cortes/*.tmp') !== []) {
            return [$dentro, 'los temporales no se barrieron'];
        }
    }
    if ($bd->consultar('CHECK KEYS') !== []) {
        return [$dentro, 'las claves foráneas no cuadran'];
    }

    $r = $sano($bd);
    return [$dentro, $r === true ? null : (string)$r];
}

/** Todas las filas tienen el mismo valor: no hay mezcla de dos versiones. */
function sinMezcla(Database $bd, string $col, int $total): bool|string {
    $vals = $bd->consultar("SELECT $col AS c, COUNT(*) AS n FROM art GROUP BY $col");
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
    if ($n !== $total) { return "quedaron $n filas de $total"; }
    return count($vals) === 1 ?: 'valores mezclados: ' . json_encode(array_column($vals, 'n', 'c'));
}

/** El índice y el recorrido completo tienen que decir lo mismo. */
function indiceCuadra(Database $bd): bool|string {
    foreach (['c0', 'c1', 'c2', 'c3', 'c4'] as $c) {
        $i = (int)$bd->consultar("SELECT COUNT(*) AS n FROM art WHERE cat = '$c'")[0]['n'];
        $e = (int)$bd->consultar("SELECT COUNT(*) AS n FROM art WHERE cat LIKE '$c'")[0]['n'];
        if ($i !== $e) { return "índice dice $i y la tabla $e para $c"; }
    }
    $a = $bd->consultar('SELECT ref FROM art WHERE id = 200');
    $b = $bd->consultar('SELECT ref FROM art WHERE id + 0 = 200');
    return $a === $b ?: 'la PK indexada y el escaneo no coinciden';
}

$casos = [
    'UPDATE de todas las filas (todas las partes y los índices)' => [
        "UPDATE art SET v = 'nuevo'",
        fn(Database $bd) => sinMezcla($bd, 'v', 300) === true ? indiceCuadra($bd) : sinMezcla($bd, 'v', 300),
    ],
    'UPDATE de la columna indexada' => [
        "UPDATE art SET cat = 'z'",
        fn(Database $bd) => indiceCuadra($bd),
    ],
    'INSERT que hace crecer la tabla una parte más' => [
        "INSERT INTO art (ref, cat, v) SELECT ref || '_b', cat, v FROM art",
        function (Database $bd) {
            $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
            if ($n !== 300 && $n !== 600) { return "quedaron $n filas (esperado 300 o 600)"; }
            return indiceCuadra($bd);
        },
    ],
    'DELETE que hace encoger la tabla y sobrar partes' => [
        'DELETE FROM art WHERE id > 50',
        function (Database $bd) use ($raiz) {
            $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
            if ($n !== 300 && $n !== 50) { return "quedaron $n filas (esperado 300 o 50)"; }
            // Si encogió, no puede quedar ninguna parte de más con filas fantasma
            $partes = count(glob("$raiz/cortes/art.json")) + count(glob("$raiz/cortes/art.part*.json"));
            $esperadas = (int)ceil($n / 100);
            if ($partes !== $esperadas) { return "$partes ficheros de datos para $n filas"; }
            return indiceCuadra($bd);
        },
    ],
    'CREATE INDEX' => [
        'CREATE INDEX idx_v ON art (v)',
        function (Database $bd) use ($raiz) {
            $decl = array_column($bd->consultar('SHOW INDEXES FROM art'), 'indice');
            $disco = [];
            foreach (glob("$raiz/cortes/art.idx.*.json") as $f) {
                $disco[] = substr(basename($f, '.json'), strlen('art.idx.'));
            }
            sort($decl); sort($disco);
            if ($decl !== $disco) {
                return 'los índices en disco no son los declarados: '
                     . implode(',', $disco) . ' vs ' . implode(',', $decl);
            }
            return indiceCuadra($bd);
        },
    ],
    'DROP INDEX' => [
        'DROP INDEX idx_cat',
        fn(Database $bd) => indiceCuadra($bd),
    ],
    'ALTER TABLE ADD COLUMN' => [
        "ALTER TABLE art ADD COLUMN extra VARCHAR(10) DEFAULT 'x'",
        function (Database $bd) {
            $cols = array_column($bd->consultar('SHOW SCHEMA art'), 'columna');
            $hay = in_array('extra', $cols, true);
            $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
            if ($n !== 300) { return "quedaron $n filas de 300"; }
            // Si la columna está declarada, tiene que estar en todas las filas
            if ($hay) {
                $con = (int)$bd->consultar("SELECT COUNT(*) AS n FROM art WHERE extra = 'x'")[0]['n'];
                if ($con !== 300) { return "la columna existe pero solo en $con filas"; }
            }
            return indiceCuadra($bd);
        },
    ],
    'ALTER TABLE DROP COLUMN' => [
        'ALTER TABLE art DROP COLUMN v',
        function (Database $bd) {
            $cols = array_column($bd->consultar('SHOW SCHEMA art'), 'columna');
            $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
            if ($n !== 300) { return "quedaron $n filas de 300"; }
            // O está en la estructura y en los datos, o en ninguno de los dos
            if (in_array('v', $cols, true)) {
                $enDatos = (int)$bd->consultar(
                    'SELECT COUNT(*) AS n FROM art WHERE v IS NOT NULL')[0]['n'];
                if ($enDatos !== 300) {
                    return 'la columna sigue declarada pero ya no está en las filas';
                }
            }
            return indiceCuadra($bd);
        },
    ],
    'ALTER TABLE RENAME TO' => [
        'ALTER TABLE art RENAME TO articulos',
        function (Database $bd) use ($raiz) {
            $tablas = array_column($bd->consultar('SHOW TABLES'), 'tabla');
            $viejo = in_array('art', $tablas, true);
            $nuevo = in_array('articulos', $tablas, true);
            if ($viejo === $nuevo) { return 'la tabla existe con los dos nombres o con ninguno'; }
            $t = $viejo ? 'art' : 'articulos';
            $n = (int)$bd->consultar("SELECT COUNT(*) AS n FROM $t")[0]['n'];
            if ($n !== 300) { return "quedaron $n filas de 300 en $t"; }
            // No pueden quedar ficheros del nombre que ya no existe
            $huerfanos = glob("$raiz/cortes/" . ($viejo ? 'articulos' : 'art') . '.*');
            return $huerfanos === [] ?: 'quedaron ficheros del otro nombre';
        },
    ],
    'ALTER TABLE ADD UNIQUE (crea índice automático)' => [
        'ALTER TABLE art ADD CONSTRAINT uq_v UNIQUE (cat, ref)',
        function (Database $bd) use ($raiz) {
            $decl = array_column($bd->consultar('SHOW INDEXES FROM art'), 'indice');
            $disco = [];
            foreach (glob("$raiz/cortes/art.idx.*.json") as $f) {
                $disco[] = substr(basename($f, '.json'), strlen('art.idx.'));
            }
            sort($decl); sort($disco);
            return $decl === $disco ?: 'índices en disco distintos de los declarados';
        },
    ],
    'DROP TABLE' => [
        'DROP TABLE art',
        function (Database $bd) use ($raiz) {
            $tablas = array_column($bd->consultar('SHOW TABLES'), 'tabla');
            if (in_array('art', $tablas, true)) {
                $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
                return $n === 300 ?: "la tabla sigue pero con $n filas";
            }
            // Si se borró, no puede quedar ni un fichero suyo
            return glob("$raiz/cortes/art.*") === [] ?: 'la tabla no existe pero quedan ficheros suyos';
        },
    ],
    'CREATE TABLE' => [
        'CREATE TABLE nueva (id INTEGER PRIMARY KEY, x VARCHAR(10))',
        function (Database $bd) use ($raiz) {
            $tablas = array_column($bd->consultar('SHOW TABLES'), 'tabla');
            if (in_array('nueva', $tablas, true)) {
                $bd->consultar("INSERT INTO nueva VALUES (1, 'a')");
                return count($bd->consultar('SELECT * FROM nueva')) === 1 ?: 'la tabla nueva no sirve';
            }
            return glob("$raiz/cortes/nueva.*") === [] ?: 'la tabla no existe pero quedan ficheros suyos';
        },
    ],
];

$dentroTotal = 0; $casosProbados = 0;
foreach ($casos as $titulo => [$sql, $sano]) {
    $fallos = [];
    $dentro = 0;
    for ($v = 0; $v < 4; $v++) {                  // varias muertes por caso
        [$d, $problema] = matarDurante($raiz, $sql, $sano);
        if ($d) { $dentro++; }
        if ($problema !== null) { $fallos[] = "vuelta $v: $problema"; }
    }
    $dentroTotal += $dentro;
    $casosProbados += 4;
    chk("$titulo ($dentro/4 dentro)", fn() => $fallos === [] ?: implode(' | ', $fallos));
}

echo "\n  Resumen: $dentroTotal de $casosProbados muertes cayeron dentro de la ventana\n";

// ======================================================================
// Cuarto bloque: journals dañados montados a mano
//
// Un corte real deja el journal en un estado concreto, y provocarlo matando
// procesos es cuestión de suerte. Aquí se montan a mano los estados que
// importan, incluidos los que un SIGKILL no puede producir pero un corte de
// corriente sí, y se comprueba qué hace el motor con cada uno.
// ======================================================================

echo "\n== Journals en mal estado, montados a mano ==\n";

/** Deja la base con la tabla 'art' y devuelve el contenido bueno de sus datos. */
function baseConJournal(string $raiz, ?string $ambito): array {
    baseVariada($raiz);
    $st = new Storage($raiz, 'cortes');
    // Exclusivo siempre: de la base si el journal es de base, de la tabla si es suyo
    $st->bloquear(true, $ambito);
    $st->txIniciar('PRUEBA', ['art'], $ambito);
    $st->desbloquear();
    $dir = $raiz . '/cortes/.tx/' . ($ambito ?? '_base');
    return [$dir, (string)file_get_contents($raiz . '/cortes/art.json')];
}

chk('sin manifiesto no se restaura nada: las copias a medias se tiran', function () use ($raiz) {
    [$dir, $bueno] = baseConJournal($raiz, null);

    // Un corte mientras se copiaba: hay copias, una a medias, y ningún
    // manifiesto porque nunca se llegó a escribir
    @unlink("$dir/manifiesto.json");
    file_put_contents("$dir/art.json", '{"table":"art","rows":[{"id":1,');

    $bd = new Database('cortes', $raiz);
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
    return $n === 300
        && (string)file_get_contents("$raiz/cortes/art.json") === $bueno
        && !is_dir("$raiz/cortes/.tx")
        ?: "quedaron $n filas";
});

chk('una copia truncada con manifiesto válido se detecta y no se restaura', function () use ($raiz) {
    // Esto es lo que pasaba antes de forzar las copias a disco: la luz se iba,
    // el manifiesto sobrevivía y la copia se quedaba a medias. Ahora el tamaño
    // anotado no cuadra y el motor se planta en vez de destruir los datos.
    [$dir, $bueno] = baseConJournal($raiz, null);
    file_put_contents("$dir/art.json", 'roto');

    $error = null;
    try {
        // La recuperación actúa al coger el bloqueo, con la primera consulta
        (new Database('cortes', $raiz))->consultar('SHOW TABLES');
    } catch (JsonSQLDB\JsonSqlDbError $e) { $error = $e; }

    $intactos = (string)file_get_contents("$raiz/cortes/art.json") === $bueno;
    // Y la carpeta sigue ahí, que es la única copia que queda de lo anterior
    $conservado = is_dir($dir);

    // Se limpia a mano para que sigan las demás pruebas
    foreach ((array)glob("$dir/*") as $f) { @unlink($f); }
    @rmdir($dir); @rmdir("$raiz/cortes/.tx");

    if ($error === null) { return 'no avisó de que el journal estaba dañado'; }
    if (!$intactos)      { return 'los datos buenos se sobrescribieron con la copia rota'; }
    return $conservado ?: 'borró el journal dañado en vez de conservarlo';
});

chk('un manifiesto COMMITTED no deshace nada', function () use ($raiz) {
    [$dir, $bueno] = baseConJournal($raiz, null);
    // La operación terminó y solo faltaba limpiar
    file_put_contents("$raiz/cortes/art.json", $bueno);
    file_put_contents("$dir/manifiesto.json", '{"estado":"COMMITTED"}');
    $bd = new Database('cortes', $raiz);
    return (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'] === 300
        && !is_dir("$raiz/cortes/.tx");
});

chk('un journal de tabla se deshace sin el exclusivo de la base', function () use ($raiz) {
    [$dir, $bueno] = baseConJournal($raiz, 'art');
    // Se destroza la tabla como si la escritura hubiera quedado a medias
    file_put_contents("$raiz/cortes/art.json", '{"table":"art","rows":[]}' . "\n");

    $bd = new Database('cortes', $raiz);           // una lectura basta
    return (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'] === 300
        && !is_dir("$raiz/cortes/.tx");
});

chk('la recuperación es idempotente: repetirla no rompe nada', function () use ($raiz) {
    [$dir, $bueno] = baseConJournal($raiz, 'art');
    file_put_contents("$raiz/cortes/art.json", '{"table":"art","rows":[]}' . "\n");

    // Tres aperturas seguidas; solo la primera tiene algo que deshacer
    for ($i = 0; $i < 3; $i++) {
        $bd = new Database('cortes', $raiz);
        $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
        unset($bd);
        if ($n !== 300) { return "en la vuelta $i quedaron $n filas"; }
    }
    return true;
});

chk('journals de dos tablas a la vez se deshacen los dos', function () use ($raiz) {
    baseVariada($raiz);
    $bd = new Database('cortes', $raiz);
    $bd->consultar('CREATE TABLE otra (id INTEGER PRIMARY KEY, x VARCHAR(10))');
    $bd->consultar("INSERT INTO otra VALUES (1,'a'),(2,'b')");
    unset($bd);

    foreach (['art', 'otra'] as $t) {
        $st = new Storage($raiz, 'cortes');
        $st->bloquear(true, $t);
        $st->txIniciar('PRUEBA', [$t], $t);
        file_put_contents("$raiz/cortes/$t.json", '{"table":"' . $t . '","rows":[]}' . "\n");
        $st->desbloquear();
        unset($st);
    }

    $bd = new Database('cortes', $raiz);
    return (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'] === 300
        && (int)$bd->consultar('SELECT COUNT(*) AS n FROM otra')[0]['n'] === 2
        && !is_dir("$raiz/cortes/.tx");
});

chk('la revisión sube antes que los datos: un corte solo cuesta caché', function () use ($raiz) {
    // Se simula el orden que usa el motor: revisión nueva y datos viejos. La
    // lectura tiene que ver los datos viejos, no una entrada de caché vacía.
    baseVariada($raiz);
    $bd = new Database('cortes', $raiz);
    $bd->consultar('SELECT COUNT(*) FROM art');     // deja la caché caliente
    unset($bd);

    $rev = json_decode((string)file_get_contents("$raiz/cortes/art.rev.json"), true);
    file_put_contents("$raiz/cortes/art.rev.json",
        json_encode(['rev' => (int)$rev['rev'] + 5]) . "\n");

    $bd = new Database('cortes', $raiz);
    return (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'] === 300;
});

chk('un fichero de revisión perdido no pierde datos', function () use ($raiz) {
    baseVariada($raiz);
    @unlink("$raiz/cortes/art.rev.json");
    $bd = new Database('cortes', $raiz);
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM art')[0]['n'];
    $bd->consultar("UPDATE art SET v = 'x' WHERE id = 1");
    return $n === 300
        && $bd->consultar('SELECT v FROM art WHERE id = 1')[0]['v'] === 'x';
});

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
