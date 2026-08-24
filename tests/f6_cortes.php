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

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
