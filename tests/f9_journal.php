<?php
declare(strict_types=1);

/**
 * Prueba exhaustiva del journal. Ejecutar:  php tests/f9_journal.php
 *
 * `f6_cortes.php` mata procesos de verdad, que es la prueba más realista, pero
 * también la menos completa: el momento exacto de la muerte es cuestión de
 * suerte y muchas ejecuciones no llegan a caer donde importa.
 *
 * Aquí se hace lo contrario. Se abre un journal real y luego se construye A MANO
 * cada estado intermedio por el que la escritura habría pasado: la primera parte
 * ya reemplazada y el resto no, las dos primeras, las tres... hasta todas, más
 * las variantes con partes borradas o añadidas. Para cada uno se abre la base y
 * se exige que TODOS los ficheros vuelvan a su contenido exacto, byte a byte.
 *
 * No es una muestra: es la lista completa de estados por los que se puede
 * quedar una escritura a medias sobre una tabla repartida en varios ficheros.
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_FILAS_POR_PARTE', 40);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\Indexes;
use JsonSQLDB\Storage;
use JsonSQLDB\Catalog;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_journal';
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

/** Contenido de todos los ficheros de la base, por nombre. */
function huella(string $dir): array {
    $out = [];
    foreach ((array)glob("$dir/*.json") as $f) {
        $out[basename((string)$f)] = md5_file((string)$f);
    }
    ksort($out);
    return $out;
}

/** Deja la base con una tabla de varias partes y dos índices. */
function preparar(string $raiz, int $filas = 200): array {
    borrarArbol($raiz);
    clearstatcache();
    @mkdir($raiz, 0775, true);
    Database::crear('j', $raiz);
    $bd = new Database('j', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT,
                                    ref VARCHAR(20) UNIQUE, cat VARCHAR(10), v VARCHAR(30))');
    $vals = [];
    for ($i = 1; $i <= $filas; $i++) { $vals[] = "($i,'R$i','c" . ($i % 4) . "','viejo')"; }
    $bd->consultar('INSERT INTO t (id, ref, cat, v) VALUES ' . implode(',', $vals));
    $bd->consultar('CREATE INDEX idx_cat ON t (cat)');
    unset($bd);
    return huella("$raiz/j");
}

/** Ficheros de la tabla, en el mismo orden en que los escribe el motor. */
function ficherosDeT(string $dir): array {
    $out = [];
    foreach ((array)glob("$dir/t.rev.json") as $f)     { $out[] = (string)$f; }
    foreach ((array)glob("$dir/t.json") as $f)         { $out[] = (string)$f; }
    $n = 2;
    while (is_file("$dir/t.part$n.json")) { $out[] = "$dir/t.part$n.json"; $n++; }
    foreach ((array)glob("$dir/t.meta.json") as $f)    { $out[] = (string)$f; }
    foreach ((array)glob("$dir/t.idx.*.json") as $f)   { $out[] = (string)$f; }
    return $out;
}

echo "\n== Preparación ==\n";
preparar($raiz);
$dir = "$raiz/j";
chk('la tabla ocupa varios ficheros', function () use ($dir) {
    $n = count(ficherosDeT($dir));
    return $n >= 8 ?: "solo $n ficheros, la prueba no valdría";
});

// ----------------------------------------------------------------------
// Cada estado intermedio, uno por uno
// ----------------------------------------------------------------------

echo "\n== Todos los estados intermedios de una escritura de tabla ==\n";

/** Contenido de todos los ficheros de datos de la base, por nombre. */
function instantanea(string $dir): array {
    $out = [];
    foreach ((array)glob("$dir/*.json") as $f) {
        $out[basename((string)$f)] = (string)file_get_contents((string)$f);
    }
    return $out;
}

/** Deja la carpeta de datos exactamente con estos ficheros. */
function reponer(string $dir, array $ficheros): void {
    foreach ((array)glob("$dir/*.json") as $f) { @unlink((string)$f); }
    foreach ((array)glob("$dir/*.tmp") as $f) { @unlink((string)$f); }
    foreach ($ficheros as $n => $c) { file_put_contents("$dir/$n", $c); }
    borrarArbol("$dir/.cache");
    borrarArbol("$dir/.tx");
}

/**
 * Ejecuta una escritura de verdad y se queda con los ficheros de antes y de
 * después. Después reconstruye, uno a uno, cada estado por el que pasa la
 * confirmación: los k primeros temporales ya en su sitio (o ficheros ya
 * borrados) y el resto todavía pendientes, con el manifiesto que los señala.
 * Para cada uno se abre la base y se exige que quede EXACTAMENTE como después
 * de la escritura, byte a byte, y que la tabla siga cuadrando con sus índices.
 */
function estadosIntermedios(string $raiz, ?string $ambito, callable $escritura, int $filasEsperadas): bool|string {
    $dir = "$raiz/j";
    preparar($raiz);
    $antes = instantanea($dir);
    $escritura(new Database('j', $raiz));
    $despues = instantanea($dir);

    // Qué hizo la confirmación: qué ficheros cambiaron o nacieron, y cuáles sobran
    $pasos = [];
    foreach ($despues as $n => $c) {
        if (($antes[$n] ?? null) !== $c) { $pasos[] = ['renombrar', $n]; }
    }
    foreach ($antes as $n => $c) {
        if (!isset($despues[$n])) { $pasos[] = ['borrar', $n]; }
    }
    if (count($pasos) < 4) { return 'la escritura tocó ' . count($pasos) . ' ficheros: la prueba no valdría'; }

    for ($k = 0; $k <= count($pasos); $k++) {
        reponer($dir, $antes);
        $renombrar = [];
        $borrar    = [];
        foreach ($pasos as $i => [$que, $n]) {
            if ($que === 'renombrar') {
                $renombrar["$n.999999.tmp"] = $n;
                if ($i < $k) {
                    file_put_contents("$dir/$n", $despues[$n]);       // ya renombrado
                } else {
                    file_put_contents("$dir/$n.999999.tmp", $despues[$n]);
                }
            } else {
                $borrar[] = $n;
                if ($i < $k) { @unlink("$dir/$n"); }
            }
        }
        $jd = "$dir/.tx/" . ($ambito ?? '_base');
        @mkdir($jd, 0775, true);
        file_put_contents("$jd/manifiesto.json", json_encode([
            'tipo' => 'redo', 'operacion' => 'PRUEBA', 'ambito' => $ambito, 'tablas' => ['t'],
            'renombrar' => $renombrar, 'borrar' => $borrar,
        ]));

        $bd = new Database('j', $raiz);
        $bd->consultar('SHOW TABLES');
        $ahora = instantanea($dir);
        if ($ahora !== $despues) {
            $dif = [];
            foreach ($despues as $f => $c) {
                if (($ahora[$f] ?? null) !== $c) { $dif[] = $f . (isset($ahora[$f]) ? ' distinto' : ' falta'); }
            }
            foreach ($ahora as $f => $c) {
                if (!isset($despues[$f])) { $dif[] = "$f sobra"; }
            }
            return "k=$k: " . implode(', ', array_slice($dif, 0, 4));
        }
        if (is_dir("$dir/.tx")) { return "k=$k: quedó journal sin aplicar"; }
        if (glob("$dir/*.tmp") !== []) { return "k=$k: quedaron temporales"; }

        $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
        if ($n !== $filasEsperadas) { return "k=$k: quedaron $n filas de $filasEsperadas"; }
        $porIndice  = (int)$bd->consultar("SELECT COUNT(*) AS n FROM t WHERE cat = 'c1'")[0]['n'];
        $porEscaneo = (int)$bd->consultar("SELECT COUNT(*) AS n FROM t WHERE cat = 'c1' OR 1 = 0")[0]['n'];
        if ($porIndice !== $porEscaneo) { return "k=$k: el índice dice $porIndice y la tabla $porEscaneo"; }
        unset($bd);
    }
    return true;
}

$escrituras = [
    'UPDATE de todas las filas (todas las partes y los índices)' =>
        [fn(Database $bd) => $bd->consultar("UPDATE t SET v = 'nuevo'"), 200],
    'DELETE que deja partes de sobra' =>
        [fn(Database $bd) => $bd->consultar('DELETE FROM t WHERE id > 60'), 60],
    'INSERT que añade partes nuevas' =>
        [function (Database $bd) {
            $v = [];
            for ($i = 1000; $i < 1100; $i++) { $v[] = "($i,'N$i','c1','x')"; }
            $bd->consultar('INSERT INTO t (id, ref, cat, v) VALUES ' . implode(',', $v));
        }, 300],
];
foreach ($escrituras as $titulo => [$fn, $filas]) {
    foreach ([null, 't'] as $ambito) {
        $et = $ambito === null ? 'journal de base' : 'journal de tabla';
        chk("$titulo, $et", fn() => estadosIntermedios($raiz, $ambito, $fn, $filas));
    }
}

// ----------------------------------------------------------------------
// El journal a medio construir
// ----------------------------------------------------------------------

echo "\n== La escritura murió antes del manifiesto ==\n";

chk('sin manifiesto no se toca nada, sea cual sea el estado de los temporales', function () use ($raiz) {
    $dir = "$raiz/j";
    // El manifiesto se escribe el último, cuando todos los temporales están en
    // el disco. Si no está, los datos no se han tocado: los temporales sobran.
    foreach (['ninguno', 'algunos', 'rotos'] as $caso) {
        $original = preparar($raiz);
        @mkdir("$dir/.tx/_base", 0775, true);
        if ($caso === 'algunos') {
            file_put_contents("$dir/t.json.999999.tmp", '{"table":"t","rows":[]}');
            file_put_contents("$dir/t.rev.json.999999.tmp", '{"rev":99}');
        } elseif ($caso === 'rotos') {
            file_put_contents("$dir/t.json.999999.tmp", '{"table":"t","rows":[{"id":1,');
            file_put_contents("$dir/.tx/_base/manifiesto.json.999999.tmp", 'a medias');
        }

        $bd = new Database('j', $raiz);
        $bd->consultar('SHOW TABLES');
        if (huella($dir) !== $original) { return "caso '$caso': los datos cambiaron"; }
        if (is_dir("$dir/.tx")) { return "caso '$caso': no se limpió la carpeta"; }
        // La siguiente escritura barre los temporales ajenos
        $bd->consultar("UPDATE t SET v = v WHERE id = 1");
        if (glob("$dir/*.tmp") !== []) { return "caso '$caso': quedaron temporales"; }
        unset($bd);
    }
    return true;
});

chk('un manifiesto que señala un temporal perdido detiene la recuperación', function () use ($raiz) {
    $dir = "$raiz/j";
    $original = preparar($raiz);
    // Un temporal que no está y cuyo destino tampoco: no se puede rehacer y
    // lo único seguro es pararse y conservar el journal para revisarlo a mano
    @unlink("$dir/t.part2.json");
    @mkdir("$dir/.tx/_base", 0775, true);
    file_put_contents("$dir/.tx/_base/manifiesto.json", json_encode([
        'tipo' => 'redo', 'tablas' => ['t'],
        'renombrar' => ['t.part2.json.999999.tmp' => 't.part2.json'],
    ]));

    $error = null;
    try {
        (new Database('j', $raiz))->consultar('SHOW TABLES');
    } catch (JsonSQLDB\JsonSqlDbError $e) { $error = $e; }

    if ($error === null) { return 'no avisó de que el journal estaba dañado'; }
    if (!is_dir("$dir/.tx/_base")) { return 'borró el journal dañado'; }
    return true;
});

chk('un journal a medias de otra tabla no estorba', function () use ($raiz) {
    $dir = "$raiz/j";
    preparar($raiz);
    $bd = new Database('j', $raiz);
    $bd->consultar('CREATE TABLE otra (id INTEGER PRIMARY KEY, x VARCHAR(10))');
    $bd->consultar("INSERT INTO otra VALUES (1,'a'),(2,'b')");
    unset($bd);

    // Journal de 'otra' a medio aplicar, con 't' intacta
    file_put_contents("$dir/otra.json.999999.tmp", "{\n  \"table\": \"otra\",\n  \"rows\": [\n    {\"id\":1,\"x\":\"a\"}\n  ]\n}\n");
    @mkdir("$dir/.tx/otra", 0775, true);
    file_put_contents("$dir/.tx/otra/manifiesto.json", json_encode([
        'tipo' => 'redo', 'ambito' => 'otra', 'tablas' => ['otra'],
        'renombrar' => ['otra.json.999999.tmp' => 'otra.json'],
    ]));

    $bd = new Database('j', $raiz);
    return (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'] === 200
        && (int)$bd->consultar('SELECT COUNT(*) AS n FROM otra')[0]['n'] === 1
        && !is_dir("$dir/.tx");
});

chk('repetir la recuperación muchas veces no degrada nada', function () use ($raiz) {
    $dir = "$raiz/j";
    preparar($raiz);
    $bd = new Database('j', $raiz);
    $bd->consultar("UPDATE t SET v = 'nuevo'");
    unset($bd);
    $despues = huella($dir);
    // El mismo journal, ya aplicado, se encuentra cinco veces seguidas
    for ($v = 0; $v < 5; $v++) {
        @mkdir("$dir/.tx/t", 0775, true);
        file_put_contents("$dir/.tx/t/manifiesto.json", json_encode([
            'tipo' => 'redo', 'ambito' => 't', 'tablas' => ['t'],
            'renombrar' => ['t.json.999999.tmp' => 't.json', 't.part2.json.999999.tmp' => 't.part2.json'],
        ]));
        $bd = new Database('j', $raiz);
        $bd->consultar('SHOW TABLES');
        if (huella($dir) !== $despues) { return "vuelta $v: no quedó como estaba"; }
        if (is_dir("$dir/.tx")) { return "vuelta $v: no se limpió el journal"; }
        unset($bd);
    }
    return true;
});

// ----------------------------------------------------------------------
// La invariante de fondo
// ----------------------------------------------------------------------

echo "\n== Toda escritura de varios ficheros pasa por el journal ==\n";

/**
 * Si una escritura cambiara un fichero fuera del journal, un corte podría
 * dejarlo nuevo junto a otros viejos. Para comprobar que no lo hace sin tener
 * que adivinar, se deja un FICHERO llamado `.tx` donde iría la carpeta del
 * journal: confirmar es imposible. Entonces la escritura tiene que fallar y,
 * sobre todo, no haber tocado NADA: ni un fichero cambiado, ni un temporal
 * suelto.
 *
 * Las escrituras que tocan un solo fichero no pasan por el journal: el rename
 * atómico basta. Esas tienen que pasar aunque `.tx` esté bloqueado, y
 * cambiar exactamente un fichero.
 */
function caminoDeEscritura(string $raiz, string $esperado, callable $preparaBase, callable $escritura): bool|string {
    $dir = "$raiz/j";
    borrarArbol($raiz);
    clearstatcache();
    @mkdir($raiz, 0775, true);
    Database::crear('j', $raiz);
    $preparaBase(new Database('j', $raiz));

    $antes = instantanea($dir);

    file_put_contents("$dir/.tx", '');
    $journalizo = false;
    try {
        $escritura(new Database('j', $raiz));
    } catch (JsonSQLDB\JsonSqlDbError $e) {
        if (!str_contains($e->getMessage(), 'carpeta del journal')) {
            @unlink("$dir/.tx");
            return 'error inesperado: ' . $e->getMessage();
        }
        $journalizo = true;
    }
    @unlink("$dir/.tx");

    if ($journalizo !== ($esperado === 'con')) {
        return $journalizo
            ? 'journalizó y no hacía falta'
            : 'NO journalizó, y esta escritura toca varios ficheros';
    }
    if (glob("$dir/*.tmp") !== []) { return 'quedaron temporales sueltos'; }

    $despues   = instantanea($dir);
    $cambiados = [];
    foreach ($antes as $f => $c) {
        if (($despues[$f] ?? null) !== $c) { $cambiados[] = $f; }
    }
    foreach ($despues as $f => $c) {
        if (!isset($antes[$f])) { $cambiados[] = "$f (nuevo)"; }
    }
    if ($journalizo) {
        return $cambiados === [] ?: 'sin poder journalizar cambió: ' . implode(', ', $cambiados);
    }
    return count($cambiados) === 1 ?: 'sin journal cambiaron ' . count($cambiados) . ' ficheros: ' . implode(', ', $cambiados);
}

$caminos = [
    'tabla con clave primaria (tiene índice)' => ['con',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (id INTEGER PRIMARY KEY, a VARCHAR(10))');
            $bd->consultar("INSERT INTO s VALUES (1, 'x')");
        },
        fn(Database $bd) => $bd->consultar("INSERT INTO s VALUES (2, 'y')"),
    ],
    'tabla sin clave primaria: datos y revisión' => ['con',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (a VARCHAR(10), b INTEGER)');
            $bd->consultar("INSERT INTO s VALUES ('x', 1)");
        },
        fn(Database $bd) => $bd->consultar("INSERT INTO s VALUES ('y', 2)"),
    ],
    'tabla que pasa a dos partes' => ['con',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (a VARCHAR(10))');
            $v = [];
            for ($i = 0; $i < 39; $i++) { $v[] = "('x$i')"; }
            $bd->consultar('INSERT INTO s VALUES ' . implode(',', $v));
        },
        function (Database $bd) {
            $v = [];
            for ($i = 0; $i < 10; $i++) { $v[] = "('z$i')"; }
            $bd->consultar('INSERT INTO s VALUES ' . implode(',', $v));
        },
    ],
    'tabla que encoge de dos partes a una' => ['con',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (a VARCHAR(10), n INTEGER)');
            $v = [];
            for ($i = 0; $i < 60; $i++) { $v[] = "('x$i', $i)"; }
            $bd->consultar('INSERT INTO s VALUES ' . implode(',', $v));
        },
        fn(Database $bd) => $bd->consultar('DELETE FROM s WHERE n > 10'),
    ],
    'CREATE TABLE' => ['con',
        fn(Database $bd) => null,
        fn(Database $bd) => $bd->consultar('CREATE TABLE s (id INTEGER PRIMARY KEY)'),
    ],
    'ALTER TABLE RENAME TO' => ['con',
        fn(Database $bd) => $bd->consultar('CREATE TABLE s (a VARCHAR(10))'),
        fn(Database $bd) => $bd->consultar('ALTER TABLE s RENAME TO s2'),
    ],
    'DROP TABLE' => ['con',
        fn(Database $bd) => $bd->consultar('CREATE TABLE s (a VARCHAR(10))'),
        fn(Database $bd) => $bd->consultar('DROP TABLE s'),
    ],
    'CREATE INDEX' => ['con',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (a VARCHAR(10))');
            $bd->consultar("INSERT INTO s VALUES ('x')");
        },
        fn(Database $bd) => $bd->consultar('CREATE INDEX ix ON s (a)'),
    ],
    'REPAIR KEYS con una huérfana' => ['con',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE p (id INTEGER PRIMARY KEY)');
            $bd->consultar('CREATE TABLE h (id INTEGER PRIMARY KEY, pid INTEGER)');
            $bd->consultar('INSERT INTO p VALUES (1), (7)');
            $bd->consultar('INSERT INTO h VALUES (1, 1), (2, 7)');
            $bd->consultar('ALTER TABLE h ADD CONSTRAINT fk FOREIGN KEY (pid) REFERENCES p(id) ON DELETE SET NULL');
            // La huérfana se mete por detrás, como haría una restauración a medias
            $dir = sys_get_temp_dir() . '/jsonsqldb_test_journal/j';
            file_put_contents("$dir/p.json", "{\n  \"table\": \"p\",\n  \"rows\": [\n    {\"id\":1}\n  ]\n}\n");
            borrarArbol("$dir/.cache");
        },
        fn(Database $bd) => $bd->consultar('REPAIR KEYS'),
    ],
    'CREATE VIEW (un solo fichero)' => ['sin',
        fn(Database $bd) => $bd->consultar('CREATE TABLE s (a VARCHAR(10))'),
        fn(Database $bd) => $bd->consultar('CREATE VIEW v AS SELECT a FROM s'),
    ],
];

foreach ($caminos as $titulo => [$esperado, $prep, $esc]) {
    $et = $esperado === 'con' ? 'journaliza' : 'un solo fichero';
    chk("$et: $titulo", fn() => caminoDeEscritura($raiz, $esperado, $prep, $esc));
}

// ----------------------------------------------------------------------
// Bases creadas con versiones anteriores
// ----------------------------------------------------------------------

echo "\n== Actualizar desde una versión anterior ==\n";

/** Deja la base con el formato de antes de la 2.0: _revs.json y sin índices. */
function baseVieja(string $raiz, bool $conJournalPlano): array {
    $dir = "$raiz/j";
    preparar($raiz, 120);

    // Volver al formato antiguo: un _revs.json común y ningún fichero de índice
    $revs = [];
    foreach ((array)glob("$dir/*.rev.json") as $f) {
        $t = basename((string)$f, '.rev.json');
        $j = json_decode((string)file_get_contents((string)$f), true);
        $revs[$t] = (int)($j['rev'] ?? 0);
        @unlink((string)$f);
    }
    file_put_contents("$dir/_revs.json", json_encode($revs, JSON_PRETTY_PRINT) . "\n");
    foreach ((array)glob("$dir/*.idx.*.json") as $f) { @unlink((string)$f); }
    borrarArbol("$dir/.cache");

    if ($conJournalPlano) {
        // Un journal de los de entonces: copias sueltas en .tx/, sin ámbito
        @mkdir("$dir/.tx", 0775, true);
        foreach ((array)glob("$dir/t.*") as $f) {
            copy((string)$f, "$dir/.tx/" . basename((string)$f));
        }
        copy("$dir/_revs.json", "$dir/.tx/_revs.json");
        file_put_contents("$dir/.tx/manifiesto.json", json_encode([
            'estado' => 'ACTIVA', 'operacion' => 'ALTER TABLE',
            'tablas' => ['t'], 'ficheros' => ['t.json'],   // lista, sin tamaños
        ]) . "\n");
        // Y la escritura que quedó a medias
        file_put_contents("$dir/t.json", '{"table":"t","rows":[]}' . "\n");
    }
    return $revs;
}

chk('una base de la versión anterior se lee sin tocar nada', function () use ($raiz) {
    $dir = "$raiz/j";
    baseVieja($raiz, false);
    $antes = huella($dir);

    $bd = new Database('j', $raiz);
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
    $porPk = $bd->consultar('SELECT ref FROM t WHERE id = 77');

    if ($n !== 120) { return "leyó $n filas de 120"; }
    if (($porPk[0]['ref'] ?? null) !== 'R77') { return 'la búsqueda por clave primaria falla'; }
    // Leer no debe escribir: una base en producción no cambia por consultarla
    return huella($dir) === $antes ?: 'leer modificó ficheros';
});

chk('la primera escritura crea el formato nuevo sin reutilizar revisiones', function () use ($raiz) {
    $dir = "$raiz/j";
    $revs = baseVieja($raiz, false);

    $bd = new Database('j', $raiz);
    $bd->consultar("UPDATE t SET v = 'nuevo' WHERE id = 1");

    if (!is_file("$dir/t.rev.json")) { return 'no se creó el fichero de revisión de la tabla'; }
    $nueva = (int)json_decode((string)file_get_contents("$dir/t.rev.json"), true)['rev'];
    // Tiene que seguir por donde iba el _revs.json: si volviera a empezar,
    // una entrada de caché vieja podría darse por buena
    if ($nueva <= $revs['t']) { return "revisión $nueva, y la anterior era {$revs['t']}"; }

    $idx = glob("$dir/t.idx.*.json");
    if ($idx === []) { return 'no se crearon los índices automáticos'; }

    $conIndice  = $bd->consultar("SELECT id FROM t WHERE cat = 'c1' ORDER BY id");
    $conEscaneo = $bd->consultar("SELECT id FROM t WHERE cat = 'c1' OR 1 = 0 ORDER BY id");
    return $conIndice === $conEscaneo ?: 'el índice recién creado no coincide con el escaneo';
});

chk('un journal pendiente de la versión anterior se deshace igual', function () use ($raiz) {
    $dir = "$raiz/j";
    // Este es el caso de actualizar el código sobre un servidor que se quedó a
    // medias: el journal de entonces no tenía carpeta de ámbito, y la
    // recuperación de ahora busca subcarpetas. Sin recogerlo, la operación
    // interrumpida no se deshacía nunca.
    baseVieja($raiz, true);

    $bd = new Database('j', $raiz);
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];

    if ($n !== 120) { return "quedaron $n filas de 120: no se deshizo el journal antiguo"; }
    if (is_dir("$dir/.tx")) { return 'no se limpió la carpeta del journal'; }
    return true;
});

chk('tras deshacerlo la base sigue siendo utilizable', function () use ($raiz) {
    baseVieja($raiz, true);
    $bd = new Database('j', $raiz);
    $bd->consultar('SHOW TABLES');
    $bd->consultar("INSERT INTO t (ref, cat, v) VALUES ('nuevo', 'c9', 'z')");
    return (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'] === 121
        && count($bd->consultar("SELECT id FROM t WHERE ref = 'nuevo'")) === 1;
});

// ----------------------------------------------------------------------
// El fichero de revisión, que ahora decide qué partes se reescriben
// ----------------------------------------------------------------------

echo "\n== Cuando el fichero de revisión no es de fiar ==\n";

/**
 * Desde la 2.2 una escritura solo rehace las partes que pudieron cambiar, y para
 * saber si las demás siguen valiendo mira cómo quedó la tabla la última vez:
 * cuántas filas y con qué tamaño de parte, anotado en `<tabla>.rev.json`.
 *
 * Eso es información nueva de la que depende la integridad, así que hay que
 * comprobar qué pasa cuando no cuadra. Un corte de luz, una copia de seguridad
 * restaurada a medias o un cambio de JSONSQLDB_FILAS_POR_PARTE pueden dejarla
 * mintiendo. La regla es que ante la duda se reescriba todo.
 */
function conRevisionTocada(string $raiz, callable $tocar): bool|string
{
    $dir = "$raiz/j";
    preparar($raiz, 200);                          // varias partes: partes de 40

    $bd = new Database('j', $raiz);
    $antes = $bd->consultar('SELECT * FROM t ORDER BY id');
    unset($bd);

    // Se estropea la anotación de cómo quedó la tabla
    $fichero = "$dir/t.rev.json";
    $json = json_decode((string)file_get_contents($fichero), true);
    $json = $tocar($json);
    file_put_contents($fichero, json_encode($json) . "\n");

    // Una escritura cualquiera: la que tiene que decidir qué partes rehacer
    $bd = new Database('j', $raiz);
    $bd->consultar("UPDATE t SET v = 'tocada' WHERE id = 5");
    $despues = $bd->consultar('SELECT * FROM t ORDER BY id');
    unset($bd);

    if (count($despues) !== count($antes)) {
        return 'quedaron ' . count($despues) . ' filas de ' . count($antes);
    }
    // Solo la fila 5 puede haber cambiado
    foreach ($antes as $i => $fila) {
        $esperada = (int)$fila['id'] === 5 ? 'tocada' : $fila['v'];
        if ($despues[$i]['id'] !== $fila['id'] || $despues[$i]['v'] !== $esperada) {
            return "la fila {$fila['id']} cambió cuando no debía";
        }
    }

    // Y los ficheros tienen que ser los mismos que si se reescribiera todo: si
    // se saltó una parte que sí cambiaba, aquí se ve
    $ficheros = static function (string $dir): array {
        $out = [];
        foreach ((array)glob("$dir/t*.json") as $f) {
            $n = basename((string)$f);
            if (str_ends_with($n, '.rev.json') || str_contains($n, '.idx.')) {
                continue;                          // llevan dentro la revisión
            }
            $out[$n] = md5_file((string)$f);
        }
        ksort($out);
        return $out;
    };
    $parcial = $ficheros("$raiz/j");

    $st = new Storage($raiz, 'j');
    $st->bloquear(true);
    $cat  = new Catalog($st);
    $meta = $cat->meta('t');
    $st->guardarTabla('t', $st->leerFilas('t', true), null, Indexes::definiciones($meta));
    $st->desbloquear();
    unset($st, $cat);

    $completa = $ficheros("$raiz/j");
    if ($parcial !== $completa) {
        $dif = [];
        foreach ($completa as $f => $md5) {
            if (($parcial[$f] ?? null) !== $md5) { $dif[] = $f; }
        }
        return 'difieren de una reescritura completa: ' . implode(', ', $dif);
    }
    return true;
}

chk('cambiar el tamaño de parte con datos ya escritos no corrompe la tabla', function () use ($raiz) {
    // Una escritura solo rehace las partes que pudieron cambiar. Eso vale
    // mientras los límites de las partes sean los mismos, y dejan de serlo si
    // se cambia JSONSQLDB_FILAS_POR_PARTE con datos ya en disco: con partes de
    // 40 la segunda tiene las filas 40-79, y con partes de 80 las 80-159.
    // Saltársela dejaría ahí las filas de antes.
    //
    // Hace falta cambiar el ajuste ENTRE PROCESOS: es una constante.
    //
    // Y hay que leer sin caché. La escritura deja las filas correctas cacheadas
    // bajo la revisión nueva, así que leer del modo normal devuelve lo correcto
    // aunque el disco esté mal: la primera versión de esta prueba daba por bueno
    // justo el caso que tenía que cazar.
    $dir = "$raiz/cambiaparte";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);

    $correr = static function (int $parte, string $codigo): string {
        $c = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
           . 'define("JSONSQLDB_FILAS_POR_PARTE", ' . $parte . ');'
           . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
           . $codigo;
        return trim((string)shell_exec(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($c) . ' 2>&1'));
    };
    $d = var_export($dir, true);

    // Cinco partes de 40
    $correr(40,
        'JsonSQLDB\\Database::crear("g", ' . $d . ');'
      . '$bd = new JsonSQLDB\\Database("g", ' . $d . ');'
      . '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, v VARCHAR(20))");'
      . '$v = []; for ($i = 1; $i <= 200; $i++) { $v[] = "($i, " . chr(39) . "f$i" . chr(39) . ")"; }'
      . '$bd->consultar("INSERT INTO t VALUES " . implode(",", $v));');

    $partes = count((array)glob("$dir/g/t.part*.json")) + 1;
    if ($partes !== 5) {
        borrarArbol($dir);
        return "se esperaban 5 partes y hay $partes";
    }

    // Ahora con partes de 80: la tabla pasa a necesitar tres
    $correr(80, '$bd = new JsonSQLDB\\Database("g", ' . $d . ');'
              . '$bd->consultar("UPDATE t SET v = " . chr(39) . "X" . chr(39) . " WHERE id = 5");');

    borrarArbol("$dir/g/.cache");                  // que responda el disco, no la caché

    $salida = $correr(80,
        '$bd = new JsonSQLDB\\Database("g", ' . $d . ');'
      . '$f = $bd->consultar("SELECT id, v FROM t ORDER BY id");'
      . '$mal = count($f) !== 200 ? ("quedan " . count($f) . " filas de 200") : "";'
      . 'if ($mal === "") { foreach ($f as $x) {'
      . '  $e = (int)$x["id"] === 5 ? "X" : "f" . $x["id"];'
      . '  if ($x["v"] !== $e) { $mal = "la fila " . $x["id"] . " vale " . $x["v"]; break; } } }'
      . 'echo $mal === "" ? "ok" : $mal;');

    borrarArbol($dir);
    return $salida === 'ok' ?: $salida;
});

chk('un recuento de filas que no cuadra no rompe la tabla', function () use ($raiz) {
    return conRevisionTocada($raiz, static function (array $j): array {
        $j['rows'] = 999999;
        return $j;
    });
});

chk('un fichero de revisión de la 2.1, sin recuento, se acepta y reescribe todo', function () use ($raiz) {
    // Las bases escritas con 2.1.x no tienen 'rows' ni 'chunk'
    return conRevisionTocada($raiz, static function (array $j): array {
        return ['rev' => $j['rev']];
    });
});

chk('un fichero de revisión de la 2.4, sin estado de las partes, se acepta', function () use ($raiz) {
    // Las bases escritas con 2.2 a 2.4 tienen 'chunk' pero ni 'rows' ni 'parts'
    return conRevisionTocada($raiz, static function (array $j): array {
        return ['rev' => $j['rev'], 'chunk' => $j['chunk']];
    });
});

chk('un estado de las partes que no cuadra con los ficheros no rompe la tabla', function () use ($raiz) {
    return conRevisionTocada($raiz, static function (array $j): array {
        $j['parts'] = [1];                              // dice una parte y hay cinco
        return $j;
    });
});

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
