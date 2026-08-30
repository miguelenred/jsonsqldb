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

/**
 * Abre un journal de ámbito $ambito, rompe los $k primeros ficheros de la
 * tabla del modo que diga $como, y comprueba que la recuperación devuelve
 * exactamente el contenido original.
 */
function probarEstado(string $raiz, ?string $ambito, int $k, string $como): bool|string {
    $dir = "$raiz/j";
    // La referencia se toma AQUÍ, sobre esta base concreta: cada preparación
    // escribe sus propias marcas de tiempo, así que una huella de otra vuelta
    // no serviría para comparar.
    $original = preparar($raiz);

    $st = new Storage($raiz, 'j');
    $st->bloquear(true, $ambito);
    $st->txIniciar('PRUEBA', ['t'], $ambito);
    $st->desbloquear();
    unset($st);

    // Simular que la escritura llegó hasta el fichero k-ésimo
    $ficheros = ficherosDeT($dir);
    for ($i = 0; $i < $k && $i < count($ficheros); $i++) {
        if ($como === 'reemplazado') {
            file_put_contents($ficheros[$i], '{"table":"t","rows":[]}' . "\n");
        } elseif ($como === 'truncado') {
            file_put_contents($ficheros[$i], '{"table":"t","rows":[{"id":1,');
        } elseif ($como === 'borrado') {
            @unlink($ficheros[$i]);
        }
    }
    // Y que además había dejado partes de más, como haría una tabla que crece
    if ($como === 'reemplazado' && $k > 0) {
        file_put_contents("$dir/t.part99.json", '{"table":"t","rows":[]}' . "\n");
    }

    // Abrir la base: aquí es donde tiene que actuar la recuperación
    $bd = new Database('j', $raiz);
    $bd->consultar('SHOW TABLES');

    $ahora = huella($dir);
    if ($ahora !== $original) {
        $dif = [];
        foreach ($original as $f => $md5) {
            if (($ahora[$f] ?? null) !== $md5) { $dif[] = $f . (isset($ahora[$f]) ? ' distinto' : ' falta'); }
        }
        foreach ($ahora as $f => $md5) {
            if (!isset($original[$f])) { $dif[] = $f . ' sobra'; }
        }
        return "k=$k ($como, $ambito): " . implode(', ', array_slice($dif, 0, 4));
    }
    if (is_dir("$dir/.tx")) { return "k=$k ($como): quedó journal sin deshacer"; }

    // Y que la tabla siga siendo usable y coherente con sus índices
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
    if ($n !== 200) { return "k=$k ($como): quedaron $n filas de 200"; }
    $viejas = (int)$bd->consultar("SELECT COUNT(*) AS n FROM t WHERE v = 'viejo'")[0]['n'];
    if ($viejas !== 200) { return "k=$k ($como): solo $viejas filas con el valor original"; }
    $porIndice  = (int)$bd->consultar("SELECT COUNT(*) AS n FROM t WHERE cat = 'c1'")[0]['n'];
    $porEscaneo = (int)$bd->consultar("SELECT COUNT(*) AS n FROM t WHERE cat = 'c1' OR 1 = 0")[0]['n'];
    if ($porIndice !== $porEscaneo) {
        return "k=$k ($como): el índice dice $porIndice y la tabla $porEscaneo";
    }
    return true;
}

$total = count(ficherosDeT($dir));
foreach (['reemplazado', 'truncado', 'borrado'] as $como) {
    foreach ([null, 't'] as $ambito) {
        $fallos = [];
        for ($k = 0; $k <= $total; $k++) {
            $r = probarEstado($raiz, $ambito, $k, $como);
            if ($r !== true) { $fallos[] = (string)$r; }
        }
        $et = $ambito === null ? 'journal de base' : 'journal de tabla';
        chk(($total + 1) . " estados con ficheros $como, $et",
            fn() => $fallos === [] ?: implode(' | ', array_slice($fallos, 0, 3)));
    }
}

// ----------------------------------------------------------------------
// El journal a medio construir
// ----------------------------------------------------------------------

echo "\n== La escritura murió mientras se hacían las copias ==\n";

chk('sin manifiesto no se toca nada, sea cual sea el estado de las copias', function () use ($raiz) {
    $dir = "$raiz/j";
    // El manifiesto se escribe el último. Si no está, es que las copias no
    // terminaron; y como se copia ANTES de modificar, no se modificó nada.
    foreach (['ninguna', 'algunas', 'todas_rotas'] as $caso) {
        $original = preparar($raiz);
        $st = new Storage($raiz, 'j');
        $st->bloquear(true, null);
        $st->txIniciar('PRUEBA', ['t'], null);
        $st->desbloquear();
        unset($st);

        $jd = "$dir/.tx/_base";
        @unlink("$jd/manifiesto.json");
        if ($caso === 'ninguna') {
            foreach ((array)glob("$jd/*") as $f) { @unlink((string)$f); }
        } elseif ($caso === 'algunas') {
            $copias = (array)glob("$jd/*");
            for ($i = 0; $i < intdiv(count($copias), 2); $i++) { @unlink((string)$copias[$i]); }
        } else {
            foreach ((array)glob("$jd/*") as $f) { file_put_contents((string)$f, 'basura'); }
        }

        $bd = new Database('j', $raiz);
        $bd->consultar('SHOW TABLES');
        if (huella($dir) !== $original) { return "caso '$caso': los datos cambiaron"; }
        if (is_dir("$dir/.tx")) { return "caso '$caso': no se limpió la carpeta"; }
        unset($bd);
    }
    return true;
});

chk('una copia truncada con manifiesto válido detiene la recuperación', function () use ($raiz) {
    $dir = "$raiz/j";
    $original = preparar($raiz);
    $st = new Storage($raiz, 'j');
    $st->bloquear(true, null);
    $st->txIniciar('PRUEBA', ['t'], null);
    $st->desbloquear();
    unset($st);

    // Esto es lo que pasaba antes de forzar las copias a disco con fsync: el
    // manifiesto sobrevivía al corte y la copia se quedaba a medias
    file_put_contents("$dir/.tx/_base/t.json", 'roto');
    file_put_contents("$dir/t.json", '{"table":"t","rows":[]}' . "\n");

    $error = null;
    try {
        (new Database('j', $raiz))->consultar('SHOW TABLES');
    } catch (JsonSQLDB\JsonSqlDbError $e) { $error = $e; }

    if ($error === null) { return 'no avisó de que el journal estaba dañado'; }
    // Lo importante: no ha tocado NADA. Ni restaurado con basura, ni borrado la
    // única copia que queda de lo anterior.
    if (!is_dir("$dir/.tx/_base")) { return 'borró el journal dañado'; }
    if (md5_file("$dir/.tx/_base/t.part2.json") !== ($original['t.part2.json'] ?? '')) {
        return 'las copias buenas del journal no se conservaron intactas';
    }
    return true;
});

chk('un journal a medias de otra tabla no estorba', function () use ($raiz) {
    $dir = "$raiz/j";
    preparar($raiz);
    $bd = new Database('j', $raiz);
    $bd->consultar('CREATE TABLE otra (id INTEGER PRIMARY KEY, x VARCHAR(10))');
    $bd->consultar("INSERT INTO otra VALUES (1,'a'),(2,'b')");
    unset($bd);

    // Journal huérfano de 'otra', con 't' intacta
    $st = new Storage($raiz, 'j');
    $st->bloquear(true, 'otra');
    $st->txIniciar('PRUEBA', ['otra'], 'otra');
    file_put_contents("$dir/otra.json", '{"table":"otra","rows":[]}' . "\n");
    $st->desbloquear();
    unset($st);

    $bd = new Database('j', $raiz);
    return (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'] === 200
        && (int)$bd->consultar('SELECT COUNT(*) AS n FROM otra')[0]['n'] === 2
        && !is_dir("$dir/.tx");
});

// ----------------------------------------------------------------------
// Recuperación interrumpida a su vez
// ----------------------------------------------------------------------

echo "\n== La recuperación interrumpida se repite entera ==\n";

chk('un corte a mitad de restaurar se arregla en la siguiente apertura', function () use ($raiz) {
    $dir = "$raiz/j";
    // Se simula: journal válido, algunos ficheros ya devueltos y otros no,
    // porque la luz se fue también durante la recuperación
    for ($corte = 1; $corte <= 4; $corte++) {
        $original = preparar($raiz);
        $st = new Storage($raiz, 'j');
        $st->bloquear(true, null);
        $st->txIniciar('PRUEBA', ['t'], null);
        $st->desbloquear();
        unset($st);

        $ficheros = ficherosDeT($dir);
        // Todos rotos (la escritura interrumpida) y luego unos pocos ya
        // restaurados a mano (la recuperación interrumpida)
        foreach ($ficheros as $f) { file_put_contents($f, 'roto'); }
        for ($i = 0; $i < $corte && $i < count($ficheros); $i++) {
            $copia = "$dir/.tx/_base/" . basename($ficheros[$i]);
            if (is_file($copia)) { copy($copia, $ficheros[$i]); }
        }

        $bd = new Database('j', $raiz);
        $bd->consultar('SHOW TABLES');
        if (huella($dir) !== $original) { return "corte $corte: no quedó como estaba"; }
        if ((int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'] !== 200) {
            return "corte $corte: faltan filas";
        }
        unset($bd);
    }
    return true;
});

chk('repetir la recuperación muchas veces no degrada nada', function () use ($raiz) {
    $dir = "$raiz/j";
    $original = preparar($raiz);
    for ($v = 0; $v < 5; $v++) {
        $st = new Storage($raiz, 'j');
        $st->bloquear(true, 't');
        $st->txIniciar('PRUEBA', ['t'], 't');
        file_put_contents("$dir/t.json", 'roto');
        $st->desbloquear();
        unset($st);

        $bd = new Database('j', $raiz);
        $bd->consultar('SHOW TABLES');
        if (huella($dir) !== $original) { return "vuelta $v: no quedó como estaba"; }
        unset($bd);
    }
    return true;
});

// ----------------------------------------------------------------------
// La invariante de fondo
// ----------------------------------------------------------------------

echo "\n== El journal copia todo lo que la escritura toca ==\n";

/**
 * Si una escritura modifica o borra un fichero que el journal no copió, la
 * recuperación no puede devolverlo: eso sería perder datos sin que nada avise.
 *
 * Aquí se abre un journal igual que lo abre el motor, se apunta qué copió, se
 * ejecuta la escritura de verdad, y se comprueba que todo lo que cambió estaba
 * en esa lista. Los ficheros NUEVOS no hacen falta: al deshacer se borra lo que
 * haya y luego se restauran las copias, así que sobran solos.
 */
function copiaCompleta(string $raiz, array $tablas, callable $escritura): bool|string {
    $dir = "$raiz/j";

    // Qué habría copiado el journal, con la base exactamente en este estado
    $st = new Storage($raiz, 'j');
    $st->bloquear(true, null);
    $st->txIniciar('SONDEO', $tablas, null);
    $copiados = [];
    foreach ((array)glob("$dir/.tx/_base/*") as $f) {
        $copiados[basename((string)$f)] = true;
    }
    $st->txConfirmar();
    $st->desbloquear();
    unset($st);
    unset($copiados['manifiesto.json']);

    $antes = [];
    foreach ((array)glob("$dir/*.json") as $f) { $antes[basename((string)$f)] = md5_file((string)$f); }

    $escritura(new Database('j', $raiz));

    $despues = [];
    foreach ((array)glob("$dir/*.json") as $f) { $despues[basename((string)$f)] = md5_file((string)$f); }

    foreach ($antes as $f => $md5) {
        $cambiado = !isset($despues[$f]) || $despues[$f] !== $md5;
        if ($cambiado && !isset($copiados[$f])) {
            return "'$f' cambió y el journal no lo había copiado";
        }
    }
    return true;
}

$escenarios = [
    'INSERT que añade partes nuevas' => function (Database $bd) {
        $v = [];
        for ($i = 1000; $i < 1300; $i++) { $v[] = "($i,'N$i','c1','x')"; }
        $bd->consultar('INSERT INTO t (id, ref, cat, v) VALUES ' . implode(',', $v));
    },
    'DELETE que deja partes de sobra' => function (Database $bd) {
        $bd->consultar('DELETE FROM t WHERE id > 60');
    },
    'UPDATE de todas las filas' => function (Database $bd) {
        $bd->consultar("UPDATE t SET v = 'nuevo'");
    },
    'INSERT de una fila con AUTOINCREMENT' => function (Database $bd) {
        $bd->consultar("INSERT INTO t (ref, cat, v) VALUES ('Z1','c2','y')");
    },
    'CREATE INDEX' => function (Database $bd) {
        $bd->consultar('CREATE INDEX idx_v ON t (v)');
    },
    'DROP INDEX' => function (Database $bd) {
        $bd->consultar('DROP INDEX idx_cat');
    },
    'ALTER TABLE ADD COLUMN' => function (Database $bd) {
        $bd->consultar("ALTER TABLE t ADD COLUMN extra VARCHAR(5) DEFAULT 'x'");
    },
    'ALTER TABLE DROP COLUMN' => function (Database $bd) {
        $bd->consultar('ALTER TABLE t DROP COLUMN v');
    },
    'ALTER TABLE ADD UNIQUE (índice automático nuevo)' => function (Database $bd) {
        $bd->consultar('ALTER TABLE t ADD CONSTRAINT uq_cv UNIQUE (cat, ref)');
    },
    'ALTER TABLE RENAME COLUMN' => function (Database $bd) {
        $bd->consultar('ALTER TABLE t RENAME COLUMN cat TO categoria');
    },
    'REPAIR KEYS tras meter una huérfana' => function (Database $bd) {
        $bd->consultar('CREATE TABLE h (id INTEGER PRIMARY KEY, tid INTEGER)');
        $bd->consultar('INSERT INTO h VALUES (1, 5)');
        $bd->consultar('ALTER TABLE h ADD CONSTRAINT fk FOREIGN KEY (tid) REFERENCES t(id) ON DELETE SET NULL');
        $bd->consultar('DELETE FROM t WHERE id = 5');
        $bd->consultar('REPAIR KEYS');
    },
];

foreach ($escenarios as $titulo => $fn) {
    chk("copia completa: $titulo", function () use ($raiz, $fn) {
        preparar($raiz);
        return copiaCompleta($raiz, ['t', 'h'], $fn);
    });
}

// ----------------------------------------------------------------------
// El camino SIN journal
// ----------------------------------------------------------------------

echo "\n== Las escrituras que no journalizan tocan un solo fichero ==\n";

/**
 * No toda escritura abre journal: cuando toca un fichero solo, el `rename`
 * atómico basta y copiar sería tirar el tiempo. Pero esa excepción es segura
 * únicamente si de verdad es un fichero.
 *
 * Para saber si una escritura journaliza sin adivinarlo, se deja un FICHERO
 * llamado `.tx` donde iría la carpeta: cualquier intento de journalizar falla
 * al no poder crearla. Si la escritura pasa, es que no lo intentó.
 *
 * Y el de revisión no cuenta, porque se escribe ANTES que los datos: un corte
 * entre los dos deja una revisión nueva sobre datos viejos, que nadie tiene
 * cacheada, así que la siguiente lectura va al fichero y ve lo correcto.
 */
function caminoDeEscritura(string $raiz, string $esperado, callable $preparaBase, callable $escritura): bool|string {
    $dir = "$raiz/j";
    borrarArbol($raiz);
    clearstatcache();
    @mkdir($raiz, 0775, true);
    Database::crear('j', $raiz);
    $preparaBase(new Database('j', $raiz));

    $antes = [];
    foreach ((array)glob("$dir/*.json") as $f) { $antes[basename((string)$f)] = md5_file((string)$f); }

    // Un fichero donde debería ir la carpeta del journal: si lo intenta, falla
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
    if ($journalizo) {
        return true;                       // journalizó: la seguridad es cosa del journal
    }

    $despues = [];
    foreach ((array)glob("$dir/*.json") as $f) { $despues[basename((string)$f)] = md5_file((string)$f); }

    $cambiados = [];
    foreach ($antes as $f => $md5) {
        if (!isset($despues[$f]) || $despues[$f] !== $md5) { $cambiados[] = $f; }
    }
    foreach ($despues as $f => $md5) {
        if (!isset($antes[$f])) { $cambiados[] = $f . ' (nuevo)'; }
    }
    $sinRev = array_values(array_filter($cambiados, fn($f) => !str_ends_with($f, '.rev.json')));

    return count($sinRev) <= 1
        ?: 'sin journal cambiaron ' . count($sinRev) . ' ficheros: ' . implode(', ', $sinRev);
}

$caminos = [
    'tabla sin clave primaria, una parte' => ['sin',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (a VARCHAR(10), b INTEGER)');
            $bd->consultar("INSERT INTO s VALUES ('x', 1)");
        },
        fn(Database $bd) => $bd->consultar("INSERT INTO s VALUES ('y', 2)"),
    ],
    'UPDATE en tabla sin clave primaria' => ['sin',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (a VARCHAR(10), b INTEGER)');
            $bd->consultar("INSERT INTO s VALUES ('x', 1), ('y', 2)");
        },
        fn(Database $bd) => $bd->consultar("UPDATE s SET a = 'z'"),
    ],
    'DELETE en tabla sin clave primaria' => ['sin',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (a VARCHAR(10), b INTEGER)');
            $bd->consultar("INSERT INTO s VALUES ('x', 1), ('y', 2)");
        },
        fn(Database $bd) => $bd->consultar('DELETE FROM s WHERE b = 1'),
    ],
    'tabla con clave primaria (tiene índice)' => ['con',
        function (Database $bd) {
            $bd->consultar('CREATE TABLE s (id INTEGER PRIMARY KEY, a VARCHAR(10))');
            $bd->consultar("INSERT INTO s VALUES (1, 'x')");
        },
        fn(Database $bd) => $bd->consultar("INSERT INTO s VALUES (2, 'y')"),
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
];

foreach ($caminos as $titulo => [$esperado, $prep, $esc]) {
    $et = $esperado === 'con' ? 'journaliza' : 'un solo fichero';
    chk("$et: $titulo",
        fn() => caminoDeEscritura($raiz, $esperado, $prep, $esc));
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

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
