<?php
declare(strict_types=1);

/**
 * Prueba de los índices de búsqueda. Ejecutar:  php tests/f8_indices.php
 *
 * Lo que hay que demostrar de un índice no es que sea rápido: es que da
 * EXACTAMENTE el mismo resultado que recorrer la tabla. Un índice que devuelve
 * de más se nota enseguida; uno que devuelve de menos pasa desapercibido
 * durante meses. Por eso casi toda esta prueba compara la consulta indexada con
 * la misma consulta escrita para que el índice no pueda usarse.
 *
 * Las partes se dejan pequeñas a propósito: con una sola parte el motor ni
 * siquiera intenta usar el índice, porque leer un fichero de más no ahorra nada.
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_FILAS_POR_PARTE', 50);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\Indexes;
use JsonSQLDB\Storage;
use JsonSQLDB\Valor;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_indices';
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
function esperaError(string $titulo, string $estado, callable $fn): void {
    global $ok, $ko;
    try { $fn(); $ko++; echo "  FALLO $titulo -> no lanzó error\n"; }
    catch (JsonSQLDB\JsonSqlDbError $e) {
        if ($e->sqlState === $estado) { $ok++; echo "  OK   $titulo\n"; }
        else { $ko++; echo "  FALLO $titulo -> $e->sqlState: {$e->getMessage()}\n"; }
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

borrarArbol($raiz);
@mkdir($raiz, 0775, true);
Database::crear('idx', $raiz);
$bd = new Database('idx', $raiz);

$bd->consultar('CREATE TABLE gente (id INTEGER PRIMARY KEY AUTOINCREMENT,
                                    dni VARCHAR(12) UNIQUE, ciudad VARCHAR(20),
                                    edad INTEGER, mote VARCHAR(20))');
$vals = [];
$ciudades = ['Madrid', 'Elche', 'Rojales', 'Torrevieja'];
for ($i = 1; $i <= 600; $i++) {
    $vals[] = sprintf("(%d,'D%04d','%s',%d,%s)",
        $i, $i, $ciudades[$i % 4], 18 + ($i % 60),
        $i % 7 === 0 ? 'NULL' : "'m$i'");
}
$bd->consultar('INSERT INTO gente (id, dni, ciudad, edad, mote) VALUES ' . implode(',', $vals));

/**
 * Compara la consulta tal cual (que puede usar índice) con la misma condición
 * escrita de forma que el motor no pueda aprovecharlo. El resultado tiene que
 * ser idéntico, fila a fila y en el mismo orden.
 */
function igual(Database $bd, string $indexada, string $escaneo): bool {
    $a = $bd->consultar($indexada);
    $b = $bd->consultar($escaneo);
    return $a === $b;
}

echo "\n== Índices automáticos de PRIMARY KEY y UNIQUE ==\n";
chk('la tabla ocupa varias partes', fn() => count(glob("$raiz/idx/gente*.json")) > 5);
chk('se crean solos, sin pedirlos', function () use ($bd) {
    $n = array_column($bd->consultar('SHOW INDEXES FROM gente'), 'columnas', 'indice');
    return isset($n['auto_id'], $n['auto_dni']) && $n['auto_id'] === 'id';
});
chk('búsqueda por PK igual que el escaneo', fn() => igual($bd,
    'SELECT * FROM gente WHERE id = 431',
    'SELECT * FROM gente WHERE id + 0 = 431'));
chk('búsqueda por UNIQUE igual que el escaneo', fn() => igual($bd,
    "SELECT * FROM gente WHERE dni = 'D0431'",
    "SELECT * FROM gente WHERE dni LIKE 'D0431'"));
chk('una PK que no existe da vacío', fn() => $bd->consultar('SELECT * FROM gente WHERE id = 99999') === []);

echo "\n== Índices creados a mano ==\n";
chk('CREATE INDEX sobre una columna', function () use ($bd) {
    $bd->consultar('CREATE INDEX idx_ciudad ON gente (ciudad)');
    return count($bd->consultar('SHOW INDEXES FROM gente')) === 3;
});
chk('igualdad indexada igual que el escaneo', fn() => igual($bd,
    "SELECT id FROM gente WHERE ciudad = 'Elche' ORDER BY id",
    "SELECT id FROM gente WHERE ciudad LIKE 'Elche' ORDER BY id"));
chk('IN indexado igual que el escaneo', fn() => igual($bd,
    'SELECT id FROM gente WHERE id IN (1, 250, 600) ORDER BY id',
    'SELECT id FROM gente WHERE id + 0 IN (1, 250, 600) ORDER BY id'));
chk('IF NOT EXISTS no se queja', function () use ($bd) {
    $r = $bd->consultar('CREATE INDEX IF NOT EXISTS idx_ciudad ON gente (ciudad)');
    return $r['success'] === true;
});
esperaError('crear dos veces el mismo índice', 'SCHEMA',
    fn() => $bd->consultar('CREATE INDEX idx_ciudad ON gente (ciudad)'));
esperaError('índice sobre columna inexistente', 'SCHEMA',
    fn() => $bd->consultar('CREATE INDEX idx_no ON gente (no_existe)'));
esperaError('el prefijo auto_ está reservado', 'SCHEMA',
    fn() => $bd->consultar('CREATE INDEX auto_cosa ON gente (edad)'));
esperaError('CREATE UNIQUE INDEX se rechaza con explicación', 'SYNTAX',
    fn() => $bd->consultar('CREATE UNIQUE INDEX u ON gente (edad)'));
esperaError('columna repetida en el índice', 'SCHEMA',
    fn() => $bd->consultar('CREATE INDEX idx_rep ON gente (edad, edad)'));

echo "\n== Índices compuestos ==\n";
chk('CREATE INDEX sobre dos columnas', function () use ($bd) {
    $bd->consultar('CREATE INDEX idx_ciu_edad ON gente (ciudad, edad)');
    return true;
});
chk('las dos columnas igual que el escaneo', fn() => igual($bd,
    "SELECT id FROM gente WHERE ciudad = 'Rojales' AND edad = 30 ORDER BY id",
    "SELECT id FROM gente WHERE ciudad LIKE 'Rojales' AND edad + 0 = 30 ORDER BY id"));
chk('solo la primera columna: se usa por prefijo', fn() => igual($bd,
    "SELECT id FROM gente WHERE ciudad = 'Torrevieja' ORDER BY id",
    "SELECT id FROM gente WHERE ciudad LIKE 'Torrevieja' ORDER BY id"));
chk('solo la segunda columna: no sirve, y el resultado es el mismo', fn() => igual($bd,
    'SELECT id FROM gente WHERE edad = 40 ORDER BY id',
    'SELECT id FROM gente WHERE edad + 0 = 40 ORDER BY id'));
chk('combinación con IN en las dos', fn() => igual($bd,
    "SELECT id FROM gente WHERE ciudad IN ('Elche','Madrid') AND edad IN (25,26) ORDER BY id",
    "SELECT id FROM gente WHERE ciudad + 0 <> 1 AND (ciudad LIKE 'Elche' OR ciudad LIKE 'Madrid')
                            AND edad + 0 IN (25,26) ORDER BY id"));

echo "\n== Igualdad del motor, no la de PHP ==\n";
chk('buscar un número encuentra la fila que guarda el texto', function () use ($bd) {
    $bd->consultar("UPDATE gente SET ciudad = '42' WHERE id = 7");
    $a = $bd->consultar('SELECT id FROM gente WHERE ciudad = 42');
    $b = $bd->consultar('SELECT id FROM gente WHERE ciudad + 0 = 42 AND ciudad LIKE \'42\'');
    return $a === $b && count($a) === 1;
});
chk('5 y 5.0 son la misma clave', fn() =>
    Indexes::clave([5]) === Indexes::clave([5.0])
    && Indexes::clave([5]) === Indexes::clave(['5.0']));
chk('un número y un texto no numérico no colisionan', fn() =>
    Indexes::clave([5]) !== Indexes::clave(['cinco']));
chk('las claves compuestas no son ambiguas', fn() =>
    Indexes::clave(['a', 'bc']) !== Indexes::clave(['ab', 'c']));
chk('un NULL no se indexa', fn() => Indexes::clave(['x', null]) === null);
chk('un booleano y su número son la misma clave', fn() =>
    Indexes::clave([true]) === Indexes::clave([1])
    && Indexes::clave([false]) === Indexes::clave([0]));
chk('ningún valor igual para el motor cae en dos claves distintas', function () {
    // La clave tiene que respetar Valor::comparar(). Si dos valores que el
    // motor da por iguales acabaran en claves distintas, el índice devolvería
    // de menos, que es el fallo que no se nota. Al revés —de más— lo arregla
    // el WHERE, que se aplica igual después.
    $vals = [true, false, 1, 0, '1', '0', 1.0, '1.0', 5, '5', '5.0', '', 'abc', 'ABC', -3, '-3.00'];
    foreach ($vals as $a) {
        foreach ($vals as $b) {
            if (Valor::comparar($a, $b) === 0 && Indexes::clave([$a]) !== Indexes::clave([$b])) {
                return 'falso negativo: ' . var_export($a, true) . ' vs ' . var_export($b, true);
            }
        }
    }
    return true;
});
chk('booleanos, fechas y decimales indexados dan lo mismo que el escaneo', function () use ($bd) {
    $bd->consultar('CREATE TABLE tipos (id INTEGER PRIMARY KEY, activo BOOLEAN, f DATE, d DECIMAL(10,2))');
    $filas = [];
    for ($i = 1; $i <= 200; $i++) {
        $filas[] = sprintf("(%d, %s, '2026-0%d-15', %s)",
            $i, $i % 2 === 0 ? 'true' : 'false', 1 + $i % 9, $i / 4);
    }
    $bd->consultar('INSERT INTO tipos (id, activo, f, d) VALUES ' . implode(',', $filas));
    $bd->consultar('CREATE INDEX i_act ON tipos (activo)');
    $bd->consultar('CREATE INDEX i_f ON tipos (f)');
    $bd->consultar('CREATE INDEX i_d ON tipos (d)');

    $casos = [
        ['SELECT COUNT(*) AS n FROM tipos WHERE activo = true', 'SELECT COUNT(*) AS n FROM tipos WHERE activo + 0 = 1'],
        ['SELECT COUNT(*) AS n FROM tipos WHERE activo = 1',    'SELECT COUNT(*) AS n FROM tipos WHERE activo + 0 = 1'],
        ['SELECT COUNT(*) AS n FROM tipos WHERE activo = false', 'SELECT COUNT(*) AS n FROM tipos WHERE activo + 0 = 0'],
        ["SELECT COUNT(*) AS n FROM tipos WHERE f = '2026-03-15'", "SELECT COUNT(*) AS n FROM tipos WHERE f LIKE '2026-03-15'"],
        ['SELECT COUNT(*) AS n FROM tipos WHERE d = 12.5',      'SELECT COUNT(*) AS n FROM tipos WHERE d + 0 = 12.5'],
        ["SELECT COUNT(*) AS n FROM tipos WHERE d = '12.50'",   'SELECT COUNT(*) AS n FROM tipos WHERE d + 0 = 12.5'],
    ];
    foreach ($casos as [$conIndice, $conEscaneo]) {
        if ($bd->consultar($conIndice) !== $bd->consultar($conEscaneo)) {
            return 'discrepancia en: ' . $conIndice;
        }
    }
    return true;
});
chk('IS NULL da lo mismo con índice que sin él', fn() => igual($bd,
    'SELECT id FROM gente WHERE mote IS NULL ORDER BY id',
    'SELECT id FROM gente WHERE mote IS NULL ORDER BY id LIMIT 999'));

chk('valores raros: comillas, saltos, emoji y claves con pinta de clave', function () use ($bd) {
    $bd->consultar('CREATE TABLE raros (id INTEGER PRIMARY KEY, s VARCHAR(80))');
    // Entre ellos van cadenas con la forma de una clave de índice ('n1:5',
    // 't3:abc'): si el formato fuera ambiguo, se colarían unas por otras
    $valores = ['', ' ', '  espacios  ', "con'comilla", 'con"dobles', "salto\nlinea",
                "tab\there", 'acentós ñÑ', 'emoji 🙂', 'n1:5', 't3:abc', '0', '00',
                '-0', '1e3', '%', '_', '\\', 'NULL'];
    $filas = [];
    $i = 0;
    foreach ($valores as $v) { $filas[] = [++$i, $v]; }
    for (; $i < 200; $i++) { $filas[] = [$i + 1, 'relleno' . $i]; }
    foreach (array_chunk($filas, 50) as $bloque) {
        $ph = implode(',', array_fill(0, count($bloque), '(?,?)'));
        $bd->consultar("INSERT INTO raros (id, s) VALUES $ph", array_merge(...$bloque));
    }
    $bd->consultar('CREATE INDEX i_raros ON raros (s)');

    foreach ($valores as $v) {
        // El OR de primer nivel impide usar el índice, y '1 = 0' no cambia qué
        // filas salen: es el mismo WHERE resuelto recorriendo la tabla
        $conIndice  = $bd->consultar('SELECT id FROM raros WHERE s = ?', [$v]);
        $conEscaneo = $bd->consultar('SELECT id FROM raros WHERE s = ? OR 1 = 0', [$v]);
        if ($conIndice !== $conEscaneo) {
            return 'discrepancia con ' . json_encode($v);
        }
    }
    return true;
});
chk('una clave compuesta no se confunde con otra al partir distinto', function () use ($bd) {
    // ('x','yz') y ('xy','z') concatenan lo mismo si no se marca dónde acaba
    // cada trozo. Por eso la clave lleva la longitud por delante.
    $bd->consultar('CREATE TABLE amb (id INTEGER PRIMARY KEY, a VARCHAR(20), b VARCHAR(20))');
    $bd->consultar("INSERT INTO amb VALUES (1,'x','yz'),(2,'xy','z'),(3,'xyz',''),(4,'','xyz')");
    $bd->consultar('CREATE INDEX i_amb ON amb (a, b)');

    foreach ([['x', 'yz'], ['xy', 'z'], ['xyz', ''], ['', 'xyz']] as [$a, $b]) {
        $r = $bd->consultar('SELECT id FROM amb WHERE a = ? AND b = ?', [$a, $b]);
        $e = $bd->consultar('SELECT id FROM amb WHERE (a = ? AND b = ?) OR 1 = 0', [$a, $b]);
        if ($r !== $e || count($r) !== 1) {
            return 'compuesta ambigua en ' . json_encode([$a, $b]);
        }
    }
    // Y lo mismo usándola solo por el prefijo
    foreach (['x', 'xy', 'xyz', ''] as $a) {
        $r = $bd->consultar('SELECT id FROM amb WHERE a = ?', [$a]);
        $e = $bd->consultar('SELECT id FROM amb WHERE a = ? OR 1 = 0', [$a]);
        if ($r !== $e) { return 'prefijo ambiguo en ' . json_encode($a); }
    }
    return true;
});

echo "\n== El índice no cambia el resultado en casos con trampa ==\n";
chk('OR de primer nivel no se puede empujar', fn() => igual($bd,
    "SELECT id FROM gente WHERE ciudad = 'Elche' OR edad = 99 ORDER BY id",
    "SELECT id FROM gente WHERE ciudad LIKE 'Elche' OR edad + 0 = 99 ORDER BY id"));
chk('NOT sobre una igualdad no se empuja', fn() => igual($bd,
    "SELECT COUNT(*) AS n FROM gente WHERE NOT (ciudad = 'Elche')",
    "SELECT COUNT(*) AS n FROM gente WHERE NOT (ciudad LIKE 'Elche')"));
chk('NOT IN no se empuja', fn() => igual($bd,
    'SELECT COUNT(*) AS n FROM gente WHERE id NOT IN (1,2,3)',
    'SELECT COUNT(*) AS n FROM gente WHERE id + 0 NOT IN (1,2,3)'));
chk('LEFT JOIN con IS NULL sigue siendo un anti-join', function () use ($bd) {
    $bd->consultar('CREATE TABLE compras (id INTEGER PRIMARY KEY AUTOINCREMENT, gid INTEGER)');
    $bd->consultar('INSERT INTO compras (gid) VALUES (1),(2),(1)');
    $a = $bd->consultar('SELECT COUNT(*) AS n FROM gente g LEFT JOIN compras c ON c.gid = g.id
                         WHERE c.id IS NULL')[0]['n'];
    return $a === 598;
});
chk('LEFT JOIN filtrando la tabla anulable', fn() => igual($bd,
    'SELECT g.id FROM gente g LEFT JOIN compras c ON c.gid = g.id WHERE c.gid = 1 ORDER BY g.id',
    'SELECT g.id FROM gente g LEFT JOIN compras c ON c.gid = g.id WHERE c.gid + 0 = 1 ORDER BY g.id'));
chk('la misma tabla dos veces con alias distintos', fn() => igual($bd,
    'SELECT a.id FROM gente a JOIN gente b ON b.id = a.id WHERE a.id = 100 AND b.id = 100',
    'SELECT a.id FROM gente a JOIN gente b ON b.id = a.id WHERE a.id + 0 = 100 AND b.id + 0 = 100'));
chk('subconsulta correlacionada', fn() => igual($bd,
    'SELECT id FROM gente g WHERE EXISTS (SELECT 1 FROM compras c WHERE c.gid = g.id) ORDER BY id',
    'SELECT id FROM gente g WHERE EXISTS (SELECT 1 FROM compras c WHERE c.gid + 0 = g.id) ORDER BY id'));
chk('parámetros ? también aprovechan el índice', function () use ($bd) {
    $a = $bd->consultar('SELECT dni FROM gente WHERE id = ?', [321]);
    return count($a) === 1 && $a[0]['dni'] === 'D0321';
});

echo "\n== El índice sigue a los datos ==\n";
chk('tras un UPDATE el índice ve el valor nuevo', function () use ($bd) {
    $bd->consultar("UPDATE gente SET ciudad = 'Villena' WHERE id = 500");
    return igual($bd, "SELECT id FROM gente WHERE ciudad = 'Villena'",
                      "SELECT id FROM gente WHERE ciudad LIKE 'Villena'");
});
chk('tras un DELETE las posiciones se recolocan bien', function () use ($bd) {
    // Borrar por delante desplaza todas las filas siguientes de posición y de
    // parte: si el índice no se reconstruyera, apuntaría a filas equivocadas
    $bd->consultar('DELETE FROM gente WHERE id < 100');
    return igual($bd, 'SELECT dni FROM gente WHERE id = 555',
                      'SELECT dni FROM gente WHERE id + 0 = 555')
        && $bd->consultar('SELECT dni FROM gente WHERE id = 555')[0]['dni'] === 'D0555';
});
chk('tras un INSERT que añade una parte nueva', function () use ($bd) {
    $vals = [];
    for ($i = 2000; $i < 2200; $i++) { $vals[] = "($i,'X$i','Nueva',20,'n')"; }
    $bd->consultar('INSERT INTO gente (id, dni, ciudad, edad, mote) VALUES ' . implode(',', $vals));
    return igual($bd, "SELECT COUNT(*) AS n FROM gente WHERE ciudad = 'Nueva'",
                      "SELECT COUNT(*) AS n FROM gente WHERE ciudad LIKE 'Nueva'");
});
chk('DROP INDEX y el resultado no cambia', function () use ($bd) {
    $antes = $bd->consultar("SELECT id FROM gente WHERE ciudad = 'Elche' ORDER BY id");
    $bd->consultar('DROP INDEX idx_ciudad');
    $bd->consultar('DROP INDEX idx_ciu_edad ON gente');
    return $bd->consultar("SELECT id FROM gente WHERE ciudad = 'Elche' ORDER BY id") === $antes
        && count($bd->consultar('SHOW INDEXES FROM gente')) === 2;
});
esperaError('no se puede borrar un índice automático', 'SCHEMA',
    fn() => $bd->consultar('DROP INDEX auto_id'));
chk('DROP INDEX IF EXISTS de uno que no hay', fn() =>
    $bd->consultar('DROP INDEX IF EXISTS ni_idea')['success'] === true);

echo "\n== ALTER TABLE y los índices ==\n";
chk('renombrar una columna arrastra su índice', function () use ($bd) {
    $bd->consultar('CREATE INDEX idx_edad ON gente (edad)');
    $bd->consultar('ALTER TABLE gente RENAME COLUMN edad TO anios');
    $cols = array_column($bd->consultar('SHOW INDEXES FROM gente'), 'columnas', 'indice');
    return ($cols['idx_edad'] ?? null) === 'anios'
        && igual($bd, 'SELECT id FROM gente WHERE anios = 30 ORDER BY id',
                      'SELECT id FROM gente WHERE anios + 0 = 30 ORDER BY id');
});
chk('borrar una columna se lleva su índice', function () use ($bd) {
    $bd->consultar('ALTER TABLE gente DROP COLUMN anios');
    return !in_array('idx_edad', array_column($bd->consultar('SHOW INDEXES FROM gente'), 'indice'), true);
});
chk('añadir un UNIQUE compuesto crea su índice automático', function () use ($bd) {
    $bd->consultar('ALTER TABLE gente ADD CONSTRAINT uq_ciu_dni UNIQUE (ciudad, dni)');
    $n = array_column($bd->consultar('SHOW INDEXES FROM gente'), 'automatico', 'indice');
    return ($n['auto_ciudad_dni'] ?? null) === 1
        && igual($bd, "SELECT id FROM gente WHERE ciudad = 'Madrid' AND dni = 'D0104'",
                      "SELECT id FROM gente WHERE ciudad LIKE 'Madrid' AND dni LIKE 'D0104'");
});
chk('los ficheros de índice en disco son los que dice SHOW INDEXES', function () use ($bd, $raiz) {
    $enDisco = [];
    foreach (glob("$raiz/idx/gente.idx.*.json") as $f) {
        $enDisco[] = substr(basename($f, '.json'), strlen('gente.idx.'));
    }
    $declarados = array_column($bd->consultar('SHOW INDEXES FROM gente'), 'indice');
    sort($enDisco); sort($declarados);
    return $enDisco === $declarados;
});
chk('un índice manipulado a mano no da resultados falsos', function () use ($bd, $raiz) {
    // Se ensucia el fichero con una revisión que no es la de la tabla: el motor
    // tiene que desconfiar y recorrer la tabla, no creerse lo que ponga
    $f = "$raiz/idx/gente.idx.auto_id.json";
    $j = json_decode((string)file_get_contents($f), true);
    $j['rev'] = 99999;
    $j['keys'] = [];
    file_put_contents($f, json_encode($j));
    return count($bd->consultar('SELECT id FROM gente WHERE id = 555')) === 1;
});

chk('REPAIR KEYS no se lleva por delante los índices', function () use ($bd, $raiz) {
    // Reparar unas claves reescribe las filas de la tabla. Si esa escritura no
    // declara los índices, se borran los ficheros de todos ellos: los
    // resultados seguirían siendo correctos, porque sin índice se recorre la
    // tabla, pero reparar claves no tiene por qué dejarla más lenta.
    $bd->consultar('CREATE TABLE hijas (id INTEGER PRIMARY KEY AUTOINCREMENT, gid INTEGER, t VARCHAR(10))');
    $bd->consultar("INSERT INTO hijas (gid, t) VALUES (150, 'a'), (151, 'b')");
    $bd->consultar('ALTER TABLE hijas ADD CONSTRAINT fk_h FOREIGN KEY (gid) REFERENCES gente(id) ON DELETE SET NULL');
    $bd->consultar('CREATE INDEX idx_t ON hijas (t)');

    // Una fila huérfana metida por debajo del motor: es lo que REPAIR KEYS
    // arregla, y lo que obliga a reescribir la tabla
    $f = "$raiz/idx/hijas.json";
    $j = json_decode((string)file_get_contents($f), true);
    $j['rows'][] = ['id' => 999, 'gid' => 99999, 't' => 'c'];
    file_put_contents($f, json_encode($j));
    $r = json_decode((string)file_get_contents("$raiz/idx/hijas.rev.json"), true);
    file_put_contents("$raiz/idx/hijas.rev.json", json_encode(['rev' => (int)$r['rev'] + 1]));

    $antes = count(glob("$raiz/idx/hijas.idx.*.json"));
    $bd->consultar('REPAIR KEYS');
    $despues = count(glob("$raiz/idx/hijas.idx.*.json"));

    $declarados = count($bd->consultar('SHOW INDEXES FROM hijas'));
    if ($antes !== $despues) {
        return "había $antes ficheros de índice y quedaron $despues";
    }
    if ($despues !== $declarados) {
        return "$despues ficheros para $declarados índices declarados";
    }
    return igual($bd, "SELECT id FROM hijas WHERE t = 'a'", "SELECT id FROM hijas WHERE t LIKE 'a'");
});

echo "\n== IN resuelto por conjunto ==\n";

chk('IN con subconsulta da lo mismo que el recorrido lineal', function () use ($bd) {
    // Los valores del IN se agrupan por clave para no recorrer la lista en cada
    // fila. La clave solo acota candidatos: la igualdad la sigue decidiendo
    // Valor::comparar(), así que el resultado tiene que ser idéntico.
    $bd->consultar('CREATE TABLE ped (id INTEGER PRIMARY KEY, gid INTEGER, t VARCHAR(10))');
    $filas = [];
    for ($i = 1; $i <= 400; $i++) { $filas[] = "($i, " . (100 + ($i % 250)) . ", 'x')"; }
    $bd->consultar('INSERT INTO ped (id, gid, t) VALUES ' . implode(',', $filas));

    $a = $bd->consultar('SELECT COUNT(*) AS n FROM ped WHERE gid IN (SELECT id FROM gente WHERE ciudad = ?)', ['Madrid']);
    // El OR de primer nivel obliga a evaluar fila a fila sin conjunto preparado
    $b = $bd->consultar('SELECT COUNT(*) AS n FROM ped WHERE gid IN (SELECT id FROM gente WHERE ciudad = ?) OR 1 = 0', ['Madrid']);
    return $a === $b ?: 'conjunto ' . json_encode($a) . ' vs lineal ' . json_encode($b);
});
chk('NOT IN con subconsulta también coincide', function () use ($bd) {
    $a = $bd->consultar("SELECT COUNT(*) AS n FROM ped WHERE gid NOT IN (SELECT id FROM gente WHERE ciudad = 'Madrid')");
    $b = $bd->consultar("SELECT COUNT(*) AS n FROM ped WHERE gid NOT IN (SELECT id FROM gente WHERE ciudad = 'Madrid') OR 1 = 0");
    return $a === $b;
});
chk('un NULL en la lista sigue haciendo desconocido el resultado', function () use ($bd) {
    // NULL en un IN no es "no está": es "no se sabe". Con NOT IN eso hace que
    // ninguna fila pueda afirmarse, y el conjunto tiene que respetarlo.
    $bd->consultar('CREATE TABLE nn (id INTEGER PRIMARY KEY, v INTEGER)');
    $bd->consultar('INSERT INTO nn VALUES (1, 5), (2, NULL), (3, 7)');
    $conNulo = $bd->consultar('SELECT COUNT(*) AS n FROM nn WHERE id NOT IN (SELECT v FROM nn)');
    $sinNulo = $bd->consultar('SELECT COUNT(*) AS n FROM nn WHERE id NOT IN (SELECT v FROM nn WHERE v IS NOT NULL)');
    return (int)$conNulo[0]['n'] === 0 && (int)$sinNulo[0]['n'] === 3
        ?: 'con NULL ' . json_encode($conNulo) . ', sin NULL ' . json_encode($sinNulo);
});
chk('la subconsulta correlacionada no reutiliza el conjunto de otra fila', function () use ($bd) {
    // Si el conjunto se guardara sin mirar si la subconsulta depende de la fila,
    // todas las filas responderían con los valores de la primera
    $a = $bd->consultar('SELECT COUNT(*) AS n FROM ped p
                         WHERE p.gid IN (SELECT g.id FROM gente g WHERE g.id = p.gid)');
    $b = $bd->consultar('SELECT COUNT(*) AS n FROM ped p
                         WHERE p.gid IN (SELECT g.id FROM gente g WHERE g.id = p.gid) OR 1 = 0');
    return $a === $b ?: 'correlacionada: ' . json_encode($a) . ' vs ' . json_encode($b);
});
chk('IN con literales mezclando tipos', function () use ($bd) {
    // 5, '5' y '5.0' son el mismo valor para el motor, y true vale 1
    $bd->consultar('CREATE TABLE mix (id INTEGER PRIMARY KEY, v VARCHAR(10))');
    $bd->consultar("INSERT INTO mix VALUES (1,'5'), (2,'5.0'), (3,'abc'), (4,'0'), (5,'')");
    foreach (["v IN (5)", "v IN ('5')", "v IN (5.0)", "v IN ('abc', 5)", "v IN (0)", "v IN ('')"] as $cond) {
        $a = $bd->consultar("SELECT id FROM mix WHERE $cond ORDER BY id");
        $b = $bd->consultar("SELECT id FROM mix WHERE ($cond) OR 1 = 0 ORDER BY id");
        if ($a !== $b) { return "discrepancia en $cond"; }
    }
    return true;
});

echo "\n== Con los índices desactivados ==\n";
chk('JSONSQLDB_INDICES a false da los mismos resultados', function () use ($raiz) {
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'define("JSONSQLDB_FILAS_POR_PARTE", 50);'
            . 'define("JSONSQLDB_INDICES", false);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$bd = new JsonSQLDB\\Database("idx", ' . var_export($raiz, true) . ');'
            . 'echo count($bd->consultar("SELECT id FROM gente WHERE id = 555"));'
            . '$bd->consultar("UPDATE gente SET mote = mote WHERE id = 555");'
            . 'echo "|", count(glob(' . var_export("$raiz/idx/gente.idx.*.json", true) . '));';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    // Un resultado correcto, y los ficheros de índice borrados al reescribir
    return $salida === '1|0' ?: $salida;
});

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
