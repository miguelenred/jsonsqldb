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
 *
 * https://miguelenred.es/jsonsqldb
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_FILAS_POR_PARTE', 50);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\Indexes;
use JsonSQLDB\IndexesBusqueda;
use JsonSQLDB\Storage;
use JsonSQLDB\StorageEscritura;
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
$ciudades = ['Madrid', 'Elche', 'Zaragoza', 'Malaga'];
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
chk('IN con muchas claves repartidas da lo mismo por el índice que recorriendo (10, 100, 600 y 3.000 claves)', function () use ($bd) {
    // Las numéricas se buscan solo en el trozo que las contiene y las filas se
    // leen por líneas; con demasiadas, se renuncia antes de buscar
    $total = (int)$bd->consultar('SELECT MAX(id) AS m FROM gente')[0]['m'];
    foreach ([10, 100, 600, 3000] as $n) {
        $ids = [];
        for ($i = 0; $i < $n; $i++) { $ids[] = 1 + ($i * 7919) % ($total + 50); }   // algunas no existen
        $lista = implode(',', $ids);
        if (!igual($bd, "SELECT * FROM gente WHERE id IN ($lista) ORDER BY id", "SELECT * FROM gente WHERE id IN ($lista) OR 1 = 0 ORDER BY id")) {
            return "distinto con $n claves";
        }
    }
    return true;
});
chk('col = texto sin índice salta las partes que no lo contienen y da lo mismo que sin saltar (2.7.5)', function () use ($bd) {
    // mote no tiene índice: se recorre la tabla, pero una parte cuyo fichero
    // no contiene el texto buscado no se decodifica. Comparado con la misma
    // condición escrita para que no se pueda aprovechar (mote || '')
    $bd->consultar("UPDATE gente SET mote = 'raro/ñ\"x' WHERE id = 400");      // con caracteres que no se buscan
    $bd->consultar("UPDATE gente SET mote = '00123' WHERE id = 401");           // parece un número
    $casos = ["mote = 'm599'", "mote = 'm1'", "mote = 'no está'", "mote IN ('m3', 'm598', 'nada')",
              "mote = 'raro/ñ\"x'", "mote = '00123'", "mote = 'm599' AND edad > 0", "mote = 'Madrid'",
              "ciudad = 'Zaragoza' AND mote = 'm2'", "mote = 'm' || '599'"];
    foreach ($casos as $c) {
        $sin = str_replace('mote ', "mote || '' ", $c);
        if (!igual($bd, "SELECT * FROM gente WHERE $c ORDER BY id", "SELECT * FROM gente WHERE $sin ORDER BY id")) {
            return "distinto: $c";
        }
    }
    return true;
});
chk('UPDATE y DELETE con col = texto sin índice tocan solo esas filas y dejan las demás igual (2.7.5)', function () use ($bd) {
    // Sin índice sobre mote, se leen solo las partes que contienen el texto,
    // sin cargar la tabla: el resto tiene que quedar exactamente como estaba.
    // Sobre una copia, para no cambiar los datos de las demás pruebas
    $bd->consultar('CREATE TABLE gente_tx (id INTEGER PRIMARY KEY, dni VARCHAR(12) UNIQUE, ciudad VARCHAR(20),
                                           edad INTEGER, mote VARCHAR(20))');
    $bd->consultar('INSERT INTO gente_tx SELECT id, dni, ciudad, edad, mote FROM gente ORDER BY id');
    $antes = $bd->consultar('SELECT * FROM gente_tx ORDER BY id');
    $bd->consultar("UPDATE gente_tx SET edad = edad + 1000 WHERE mote = 'm599'");
    $bd->consultar("DELETE FROM gente_tx WHERE mote IN ('m598', 'm3', 'no está')");
    $bd->consultar("UPDATE gente_tx SET edad = 1 WHERE mote = 'nada'");
    $esperado = [];
    foreach ($antes as $f) {
        if (in_array($f['mote'], ['m598', 'm3'], true)) { continue; }
        if ($f['mote'] === 'm599') { $f['edad'] += 1000; }
        $esperado[] = $f;
    }
    $despues = $bd->consultar('SELECT * FROM gente_tx ORDER BY id');
    // Y el índice de dni sigue cuadrando con los datos tras el borrado
    $porIndice = $bd->consultar("SELECT id FROM gente_tx WHERE dni = 'D0597'");
    $bd->consultar('DROP TABLE gente_tx');
    return count($antes) > 500 && $despues === $esperado && array_column($porIndice, 'id') === [597] ?: 'distinto tras escribir';
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
esperaError('CREATE UNIQUE INDEX sobre datos repetidos no se crea (2.9: es una clave única)', 'CONSTRAINT',
    fn() => $bd->consultar('CREATE UNIQUE INDEX u ON gente (edad)'));
esperaError('columna repetida en el índice', 'SCHEMA',
    fn() => $bd->consultar('CREATE INDEX idx_rep ON gente (edad, edad)'));

echo "\n== Índices compuestos ==\n";
chk('CREATE INDEX sobre dos columnas', function () use ($bd) {
    $bd->consultar('CREATE INDEX idx_ciu_edad ON gente (ciudad, edad)');
    return true;
});
chk('COUNT(*) con igualdades que cubre un índice cuenta lo mismo que recorriendo (2.7.5)', function () use ($bd) {
    // Se cuentan las posiciones del índice sin leer filas; con números u
    // otras condiciones va por el camino normal. Todas contra el recorrido
    $casos = ["ciudad = 'Elche'", "ciudad = 'Nadie'", "ciudad IN ('Elche', 'Zaragoza', 'x')", "dni = 'D0007'",
              "dni IN ('D0001', 'D0001', 'D9999')", "ciudad = 'Elche' AND edad > 30", "edad = 30", "ciudad = 'Elche' AND ciudad = 'Zaragoza'"];
    foreach ($casos as $c) {
        $sin = preg_replace("/\\b(ciudad|dni|edad) /", "\$1 || '' ", $c);
        $a = $bd->consultar("SELECT COUNT(*) AS n FROM gente WHERE $c");
        $b = $bd->consultar("SELECT COUNT(*) AS n FROM gente WHERE $sin");
        if ($a !== $b) { return "$c: " . json_encode($a) . ' / ' . json_encode($b); }
    }
    return true;
});
chk('las dos columnas igual que el escaneo', fn() => igual($bd,
    "SELECT id FROM gente WHERE ciudad = 'Zaragoza' AND edad = 30 ORDER BY id",
    "SELECT id FROM gente WHERE ciudad LIKE 'Zaragoza' AND edad + 0 = 30 ORDER BY id"));
chk('solo la primera columna: se usa por prefijo', fn() => igual($bd,
    "SELECT id FROM gente WHERE ciudad = 'Malaga' ORDER BY id",
    "SELECT id FROM gente WHERE ciudad LIKE 'Malaga' ORDER BY id"));
chk('solo la segunda columna: no sirve, y el resultado es el mismo', fn() => igual($bd,
    'SELECT id FROM gente WHERE edad = 40 ORDER BY id',
    'SELECT id FROM gente WHERE edad + 0 = 40 ORDER BY id'));
chk('combinación con IN en las dos', fn() => igual($bd,
    "SELECT id FROM gente WHERE ciudad IN ('Elche','Madrid') AND edad IN (25,26) ORDER BY id",
    "SELECT id FROM gente WHERE ciudad + 0 <> 1 AND (ciudad LIKE 'Elche' OR ciudad LIKE 'Madrid')
                            AND edad + 0 IN (25,26) ORDER BY id"));

chk('LIMIT con un WHERE que el índice cubre entero da las mismas filas, en el mismo orden, que recorriendo, y deja de mirar trozos en cuanto las tiene (2.9)', function () use ($bd) {
    // Con LIMIT y OFFSET, una clave, IN, las dos columnas del índice, y una
    // condición de más (que no cubre: el WHERE la aplica después, sin cortar)
    $casos = ["ciudad = 'Elche' LIMIT 5", "ciudad = 'Elche' LIMIT 7 OFFSET 20", "ciudad IN ('Elche', 'Zaragoza') LIMIT 50",
              "ciudad = 'Madrid' AND edad = 30 LIMIT 3", "ciudad = 'Elche' AND edad > 60 LIMIT 4", "dni IN ('D0500', 'D0003', 'D0100') LIMIT 2",
              "ciudad = 'Elche' AND ciudad = 'Elche' LIMIT 3", "ciudad = 'Nadie' LIMIT 3", "ciudad = 'Elche' LIMIT 0", "ciudad = 'Elche' LIMIT 1000"];
    foreach ($casos as $c) {
        $sin = preg_replace("/\\b(ciudad|dni|edad) /", "\$1 || '' ", $c);
        $a = $bd->consultar("SELECT id FROM gente WHERE $c");
        $b = $bd->consultar("SELECT id FROM gente WHERE $sin");
        if ($a !== $b) { return "$c: " . json_encode(array_column($a, 'id')) . ' / ' . json_encode(array_column($b, 'id')); }
    }
    // 600 filas en partes de 100: Elche está en todas; con LIMIT 5 solo hay que leer el primer trozo y 5 filas
    $e = $bd->consultar("EXPLAIN SELECT id FROM gente WHERE ciudad = 'Elche' LIMIT 5")[0];
    return (int)$e['filas'] <= 25 && $e['indice'] !== null ?: $e;
});

echo "\n== Igualdad del motor, no la de PHP ==\n";
chk('buscar un número encuentra la fila que guarda el texto', function () use ($bd) {
    $bd->consultar("UPDATE gente SET ciudad = '42' WHERE id = 7");
    $a = $bd->consultar('SELECT id FROM gente WHERE ciudad = 42');
    $b = $bd->consultar('SELECT id FROM gente WHERE ciudad + 0 = 42 AND ciudad LIKE \'42\'');
    return $a === $b && count($a) === 1;
});
chk('5 y 5.0 son la misma clave; un texto, aunque sea un número, va con la suya (2.10: \'5.0\' y \'05\' no son \'5\')', fn() =>
    Indexes::clave([5]) === Indexes::clave([5.0])
    && Indexes::clave([5]) !== Indexes::clave(['5']) && Indexes::clave(['05']) !== Indexes::clave(['5'])
    && Indexes::clave(['5.0']) !== Indexes::clave(['5']));
chk('un número y un texto no numérico no colisionan', fn() =>
    Indexes::clave([5]) !== Indexes::clave(['cinco']));
chk('las claves compuestas no son ambiguas', fn() =>
    Indexes::clave(['a', 'bc']) !== Indexes::clave(['ab', 'c']));
chk('un NULL no se indexa', fn() => Indexes::clave(['x', null]) === null);
chk('un booleano y su número son la misma clave', fn() =>
    Indexes::clave([true]) === Indexes::clave([1])
    && Indexes::clave([false]) === Indexes::clave([0]));
chk('ningún valor igual para el motor cae en dos claves distintas, buscado con la afinidad de su columna (2.10)', function () {
    // La clave tiene que respetar Valor::comparar(). Si dos valores que el
    // motor da por iguales acabaran en claves distintas, el índice devolvería
    // de menos, que es el fallo que no se nota. Al revés —de más— lo arregla
    // el WHERE, que se aplica igual después. Lo que hay en la columna es de
    // su tipo (en una de texto, textos; en una numérica, números, booleanos
    // de un JSON editado a mano, o textos que no son números, como una fecha),
    // y lo que se busca va con su afinidad (ver Valor::conAfinidad())
    $vals = [true, false, 1, 0, '1', '0', 1.0, '1.0', 5, '5', '5.0', '05', ' 5 ', '', 'abc', 'ABC', -3, '-3.00'];
    foreach (['N', 'T'] as $afin) {
        foreach ($vals as $x) {
            $guardado = Valor::conAfinidad($x, $afin);
            if ($afin === 'T' && !is_string($guardado)) {
                continue;                           // en una columna de texto solo hay textos
            }
            foreach ($vals as $buscado) {
                $b = Valor::conAfinidad($buscado, $afin);
                if (Valor::comparar($guardado, $b) === 0 && Indexes::clave([$guardado]) !== Indexes::clave([$b])) {
                    return "falso negativo ($afin): " . var_export($guardado, true) . ' vs ' . var_export($b, true);
                }
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
    // Cada índice va en trozos, uno por parte de la tabla: tantos ficheros
    // como partes de datos, y ninguno de un índice que no exista
    $partes  = count(glob("$raiz/idx/gente.json")) + count(glob("$raiz/idx/gente.part*.json"));
    $enDisco = [];
    foreach (glob("$raiz/idx/gente.idx.*.json") as $f) {
        $nombre = preg_replace('/\\.part\\d+$/', '', substr(basename($f, '.json'), strlen('gente.idx.')));
        $enDisco[$nombre] = ($enDisco[$nombre] ?? 0) + 1;
    }
    $declarados = array_column($bd->consultar('SHOW INDEXES FROM gente'), 'indice');
    sort($declarados);
    $nombres = array_keys($enDisco);
    sort($nombres);
    return $nombres === $declarados && array_values(array_unique($enDisco)) === [$partes]
        ?: 'ficheros por índice: ' . json_encode($enDisco) . " con $partes partes";
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

echo "\n== Escrituras que no leen la tabla entera ==\n";

chk('UPDATE y DELETE por índice dan lo mismo que recorriendo la tabla', function () use ($raiz) {
    // Con clave primaria y UNIQUE, un UPDATE o DELETE cuyo WHERE resuelve un
    // índice lee y reescribe solo las partes afectadas. Sin índices se recorre
    // y reescribe la tabla entera. Las dos tablas tienen que quedar iguales
    // después de cada sentencia, y el índice de acuerdo con los datos.
    $dir = $raiz . '/parcial2';
    @mkdir($dir, 0775, true);
    Database::crear('p', $dir);
    $bd = new Database('p', $dir);
    $bd->consultar('CREATE TABLE ci (id INTEGER PRIMARY KEY, u VARCHAR(20) UNIQUE, v VARCHAR(10))');
    $bd->consultar('CREATE TABLE si (id INTEGER, u VARCHAR(20), v VARCHAR(10))');
    $vals = [];
    for ($i = 1; $i <= 280; $i++) { $vals[] = "($i,'u$i','c" . ($i % 5) . "')"; }
    foreach (['ci', 'si'] as $t) {
        $bd->consultar("INSERT INTO $t (id,u,v) VALUES " . implode(',', $vals));
    }
    $ops = [
        "UPDATE %s SET v = 'x' WHERE id = 150",
        "UPDATE %s SET u = 'cambiada' WHERE id = 150",
        "UPDATE %s SET v = 'z' WHERE id IN (1, 120, 279)",
        "UPDATE %s SET v = 'w' WHERE id = 3 AND v = 'no existe'",
        "UPDATE %s SET id = 999 WHERE id = 5",
        "DELETE FROM %s WHERE id IN (2, 140, 278)",
        "DELETE FROM %s WHERE id = 999",
        "DELETE FROM %s WHERE u = 'u200'",
        "INSERT INTO %s (id,u,v) VALUES (5,'u5','c0'), (2,'u2','c2')",
        "UPDATE %s SET v = 'q' WHERE id = 2",
        "DELETE FROM %s WHERE id = 1",
        "DELETE FROM %s WHERE id = 280",
    ];
    foreach ($ops as $op) {
        foreach (['ci', 'si'] as $t) {
            $bd->consultar(sprintf($op, $t));
        }
        $a = $bd->consultar('SELECT * FROM ci ORDER BY id');
        $b = $bd->consultar('SELECT * FROM si ORDER BY id');
        if ($a !== $b) {
            return "tras '" . sprintf($op, 'ci') . "' las tablas difieren (" . count($a) . ' y ' . count($b) . ' filas)';
        }
        // Índice contra recorrido, y caché contra disco
        foreach (['x', 'z', 'q', 'c1'] as $v) {
            $i = (int)$bd->consultar("SELECT COUNT(*) AS n FROM ci WHERE u = 'cambiada' OR v = '$v'")[0]['n'];
            $e = (int)$bd->consultar("SELECT COUNT(*) AS n FROM si WHERE u = 'cambiada' OR v = '$v'")[0]['n'];
            if ($i !== $e) { return "tras '$op' el índice dice $i y la tabla $e para v = '$v'"; }
        }
        $r1 = $bd->consultar('SELECT id FROM ci WHERE id = 150');
        $r2 = $bd->consultar('SELECT id FROM ci WHERE id + 0 = 150');
        if ($r1 !== $r2) { return "tras '$op' la clave primaria indexada y el escaneo no coinciden"; }
        $st = new Storage($dir, 'p');
        $st->bloquear(false);
        $conCache = StorageEscritura::leerFilas($st, 'ci');
        $sinCache = StorageEscritura::leerFilas($st, 'ci', true);
        $st->desbloquear();
        if ($conCache !== $sinCache) { return "tras '$op' la caché no coincide con el disco"; }
    }
    unset($bd);
    Database::borrar('p', $dir);
    @rmdir($dir);
    return true;
});

chk('el rango numérico de cada trozo acota la búsqueda sin perder claves movidas', function () use ($raiz) {
    // Cada trozo de índice anota el mínimo y el máximo numérico de sus claves
    // en rev.json, y una búsqueda por id abre solo los trozos que pueden
    // contenerla. Al cambiar un id a un valor de otro tramo, o al borrar y
    // volver a insertar, los rangos tienen que seguir diciendo la verdad.
    $dir = $raiz . '/rangos';
    @mkdir($dir, 0775, true);
    Database::crear('r', $dir);
    $bd = new Database('r', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER, s VARCHAR(10), f DOUBLE)');
    $bd->consultar('CREATE INDEX ix_n ON t (n)');
    $bd->consultar('CREATE INDEX ix_s ON t (s)');
    $bd->consultar('CREATE INDEX ix_nf ON t (n, s)');
    $bd->consultar('CREATE INDEX ix_f ON t (f)');
    $vals = [];
    for ($i = 1; $i <= 260; $i++) { $vals[] = "($i, " . ($i % 7) . ", 's" . ($i % 3) . "', " . ($i * 1.5 - 100) . ')'; }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $vals));

    $rev = json_decode((string)file_get_contents("$dir/r/t.rev.json"), true);
    $r   = $rev['rangos'] ?? null;
    if (!is_array($r)) { return 'rev.json no lleva los rangos'; }
    if (($r['auto_id'] ?? null) !== [[1, 50], [51, 100], [101, 150], [151, 200], [201, 250], [251, 260]]) {
        return 'el rango de la clave primaria no es el esperado: ' . json_encode($r['auto_id'] ?? null);
    }
    if (($r['ix_s'] ?? null) !== [null, null, null, null, null, null]) { return 'un índice de texto no debe tener rango'; }
    if (($r['ix_nf'][0] ?? null) !== [0, 6]) { return 'el índice compuesto acota por su primera columna'; }
    if (($r['ix_f'][0] ?? null) !== [-98.5, -25]) { return 'el rango de flotantes no es el esperado: ' . json_encode($r['ix_f'][0] ?? null); }

    // Claves que cambian de tramo: un UPDATE del id, un borrado y una
    // reinserción con un id de otro tramo, y una fila que se mueve al borrar
    $ops = [
        'UPDATE t SET id = 9000 WHERE id = 5',
        'UPDATE t SET id = 5 WHERE id = 9000',
        'DELETE FROM t WHERE id = 120',
        'INSERT INTO t VALUES (120, 3, \'s0\', 80)',
        'DELETE FROM t WHERE id IN (1, 2, 4)',
        'UPDATE t SET n = 99 WHERE id = 200',
        'INSERT INTO t VALUES (-5, 1, \'s1\', 0.5)',
    ];
    foreach ($ops as $op) {
        $bd->consultar($op);
        foreach ([3, 5, 9000, 120, 1, 2, 4, 200, -5, 260, 51, 100] as $id) {
            $a = $bd->consultar('SELECT id FROM t WHERE id = ?', [$id]);
            $b = $bd->consultar('SELECT id FROM t WHERE id + 0 = ?', [$id]);
            if ($a !== $b) { return "tras '$op', id = $id: el índice da " . count($a) . ' y el recorrido ' . count($b); }
        }
        foreach ([[99, 'n'], [3, 'n'], [0, 'n']] as [$v, $c]) {
            $a = $bd->consultar("SELECT id FROM t WHERE $c = ? ORDER BY id", [$v]);
            $b = $bd->consultar("SELECT id FROM t WHERE $c + 0 = ? ORDER BY id", [$v]);
            if ($a !== $b) { return "tras '$op', $c = $v: el índice da " . count($a) . ' y el recorrido ' . count($b); }
        }
        $a = $bd->consultar('SELECT id FROM t WHERE n = 3 AND s = ? ORDER BY id', ['s0']);
        $b = $bd->consultar('SELECT id FROM t WHERE n + 0 = 3 AND s = ? ORDER BY id', ['s0']);
        if ($a !== $b) { return "tras '$op', el índice compuesto no cuadra"; }
        // Rangos: con el índice se leen solo las partes cuyo tramo cruza el
        // rango; con `id + 0` se recorre la tabla. Tienen que coincidir.
        foreach (['id BETWEEN 60 AND 70', 'id > 240', 'id >= 251 AND id < 253', 'id < 3', 'id BETWEEN -10 AND 1',
                  'id BETWEEN 8990 AND 9010', 'id > 100 AND id <= 105 AND n = 3', '3 < id AND id < 6', 'f BETWEEN -50 AND -49',
                  'f > 90', 'id BETWEEN 10 AND 200'] as $cond) {
            $a = $bd->consultar("SELECT id FROM t WHERE $cond ORDER BY id");
            $b = $bd->consultar('SELECT id FROM t WHERE ' . str_replace(['id ', 'f ', ' id', ' f '], ['id + 0 ', 'f + 0 ', ' id + 0', ' f + 0 '], $cond) . ' ORDER BY id');
            if ($a !== $b) { return "tras '$op', el rango '$cond' da " . count($a) . ' y el recorrido ' . count($b); }
        }
    }
    // El primer trozo tuvo el 9000 mientras duró; al deshacerlo, el rango se
    // recalculó al reescribir el trozo y ya no lo cubre
    $rev = json_decode((string)file_get_contents("$dir/r/t.rev.json"), true);
    $primero = $rev['rangos']['auto_id'][0] ?? null;
    if (!is_array($primero) || count($primero) !== 2 || $primero[1] >= 9000 || $primero[0] !== 3) {
        return 'el rango del primer trozo no se ha recalculado: ' . json_encode($primero);
    }
    unset($bd);
    Database::borrar('r', $dir);
    @rmdir($dir);
    return true;
});

chk('una clave se encuentra en su trozo de índice esté donde esté (en orden, desordenada, con huecos, repetida, negativa, con decimales) y no se confunde con otra que la lleva en su texto (2.9)', function () use ($raiz) {
    // Una clave numérica se busca desde donde caería si el trozo va en orden
    // (ver Storage::buscarClave()); en uno desordenado, o lejos de donde
    // caería, tiene que encontrarse igual, y no confundirse con una que la
    // contiene
    $dir = $raiz . '/donde';
    @mkdir($dir, 0775, true);
    Database::crear('d', $dir);
    $bd = new Database('d', $dir);
    $bd->consultar('CREATE TABLE desord (id INTEGER PRIMARY KEY, n INTEGER, f DOUBLE, s VARCHAR(30))');
    $bd->consultar('CREATE TABLE enorden (id INTEGER PRIMARY KEY, n INTEGER, f DOUBLE, s VARCHAR(30))');
    foreach (['desord', 'enorden'] as $t) {
        $bd->consultar("CREATE INDEX ix_{$t}_n ON $t (n)");
        $bd->consultar("CREATE INDEX ix_{$t}_f ON $t (f)");
        $bd->consultar("CREATE INDEX ix_{$t}_ns ON $t (n, s)");
        $bd->consultar("CREATE INDEX ix_{$t}_s ON $t (s)");
    }
    mt_srand(29);
    $ids = range(1, 400);
    shuffle($ids);
    // En orden, con huecos: de 3 en 3, un salto grande y negativos al final
    $orden = array_merge(range(3, 900, 3), range(100000, 100060), range(-40, -1));
    // En el primer trozo, filas cuyo texto lleva dentro la clave de otra: en
    // el índice (n, s), «"n1:3t1:y» queda justo antes del final de su clave,
    // "n1:7t10:z\"n1:3t1:y":, y lo mismo «"t1:w» en el índice de s. Unas
    // antes de donde empieza la búsqueda y otras después, delante de la buena
    $trampas = [5 => [3, 'y'], 7 => [7, 'z"n1:3t1:y'], 30 => [7, 'z"n1:1t1:v'], 40 => [1, 'v'], 25 => [7, 'q"t1:w'], 45 => [7, 'w']];
    $filas = static function (array $ids) use ($trampas): array {
        $v = [];
        foreach ($ids as $k => $id) {
            [$n, $s] = $trampas[$k] ?? [$id % 13, 's' . ($k % 5)];
            $v[] = sprintf("(%d, %d, %s, '%s')", $id, $n, $id * 0.37 - 50, str_replace("'", "''", $s));
        }
        return $v;
    };
    $bd->consultar('INSERT INTO desord VALUES ' . implode(',', $filas($ids)));
    $bd->consultar('INSERT INTO enorden VALUES ' . implode(',', $filas($orden)));
    $mal = [];
    foreach (['desord' => $ids, 'enorden' => $orden] as $t => $lista) {
        $buscar = array_merge($lista, [0, 2, 4, 401, 99999, 100061, -41, 1000000]);
        foreach ($buscar as $id) {
            if ($bd->consultar("SELECT id FROM $t WHERE id = ?", [$id]) !== $bd->consultar("SELECT id FROM $t WHERE id + 0 = ?", [$id])) {
                $mal[] = "$t id = $id";
            }
        }
        foreach (range(0, 12) as $n) {
            if ($bd->consultar("SELECT id FROM $t WHERE n = ? ORDER BY id", [$n]) !== $bd->consultar("SELECT id FROM $t WHERE n + 0 = ? ORDER BY id", [$n])) {
                $mal[] = "$t n = $n";
            }
        }
        foreach (array_slice($lista, 0, 60) as $id) {
            $f = $id * 0.37 - 50;
            if ($bd->consultar("SELECT id FROM $t WHERE f = ?", [$f]) !== $bd->consultar("SELECT id FROM $t WHERE f + 0 = ?", [$f])) {
                $mal[] = "$t f = $f";
            }
        }
        for ($i = 0; $i < 20; $i++) {
            $en = implode(',', array_map(static fn() => $lista[mt_rand(0, count($lista) - 1)] + mt_rand(0, 1), range(1, 30)));
            if ($bd->consultar("SELECT id FROM $t WHERE id IN ($en) ORDER BY id") !== $bd->consultar("SELECT id FROM $t WHERE id + 0 IN ($en) ORDER BY id")) {
                $mal[] = "$t id IN ($en)";
            }
        }
        foreach (["n = 3 AND s = 'y'", "n = 1 AND s = 'v'", "s = 'w'"] as $cond) {
            $a = $bd->consultar("SELECT id FROM $t WHERE $cond");
            if ($a !== $bd->consultar("SELECT id FROM $t WHERE " . str_replace(['n =', 's ='], ['n + 0 =', "s || '' ="], $cond)) || count($a) !== 1) {
                $mal[] = "$t $cond: " . json_encode($a);
            }
        }
    }
    unset($bd);
    Database::borrar('d', $dir);
    @rmdir($dir);
    return $mal === [] ?: implode(' | ', array_slice($mal, 0, 5)) . ' (' . count($mal) . ')';
});

chk('COUNT(*) de una igualdad sobre un índice cuenta las posiciones sin juntarlas y da lo que contar las filas: listas, claves sueltas, IN con repetidas y filas borradas (2.9)', function () use ($raiz) {
    $dir = $raiz . '/cuenta';
    @mkdir($dir, 0775, true);
    Database::crear('k', $dir);
    $bd = new Database('k', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, ciudad VARCHAR(20), cod VARCHAR(10))');
    $bd->consultar('CREATE INDEX ix_ciudad ON t (ciudad)');
    $bd->consultar('CREATE INDEX ix_cod ON t (cod)');
    $v = [];
    for ($i = 1; $i <= 400; $i++) {
        // Ciudades en todos los trozos (listas largas), una en uno solo y un código distinto por fila (claves sueltas)
        $v[] = sprintf("(%d, '%s', 'k%d')", $i, $i === 77 ? 'Única' : ['Elche', 'Murcia', 'Alicante', 'Orihuela'][$i % 4], $i);
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    $bd->consultar('DELETE FROM t WHERE id IN (4, 8, 120, 333, 399)');      // huecos: ni están ni se cuentan
    $mal = [];
    foreach (["ciudad = 'Elche'", "ciudad = 'Murcia'", "ciudad = 'Única'", "ciudad = 'Nadie'", "cod = 'k5'", "cod = 'k8'",
              "ciudad IN ('Elche', 'Murcia', 'Elche')", "cod IN ('k1', 'k2', 'k1', 'k400')", "ciudad IN ('Única', 'Nadie')"] as $cond) {
        $a = $bd->consultar("SELECT COUNT(*) AS n FROM t WHERE $cond")[0]['n'];
        $b = $bd->consultar('SELECT COUNT(*) AS n FROM t WHERE ' . preg_replace('/^(\w+)/', "$1 || ''", $cond))[0]['n'];
        if ($a !== $b) { $mal[] = "$cond: $a / $b"; }
    }
    unset($bd);
    Database::borrar('k', $dir);
    @rmdir($dir);
    return $mal === [] ?: implode(' | ', $mal);
});

chk('COUNT(*) de una igualdad numérica sobre columnas INTEGER, DOUBLE y DECIMAL con índice cuenta en el índice y da lo que contar las filas: 5 y 5.0, textos numéricos, IN, borradas y cambiadas (2.10); y en una de texto, cada texto con su clave (2.10)', function () use ($raiz) {
    $dir = $raiz . '/cuentan';
    @mkdir($dir, 0775, true);
    Database::crear('k', $dir);
    $bd = new Database('k', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, e INTEGER, d DOUBLE, p DECIMAL(8,2), x TEXT)');
    foreach (['e', 'd', 'p', 'x'] as $c) {
        $bd->consultar("CREATE INDEX i_$c ON t ($c)");
    }
    $v = [];
    for ($i = 1; $i <= 600; $i++) {
        $v[] = sprintf("(%d, %s, %s, %s, '%s')", $i, $i % 9 ? $i % 7 : 'NULL', ($i % 5) / 2, $i % 11 ? ($i % 6) * 1.25 : 'NULL', $i % 3 ? $i % 4 : '0' . $i % 4);
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    $bd->consultar('DELETE FROM t WHERE id % 50 = 0');
    $bd->consultar('UPDATE t SET e = 3, d = 1.5 WHERE id % 37 = 0');
    $mal = [];
    $conds = ['e = 3', 'e = 3.0', "e = '3'", "e = ' 3 '", 'e = 3.5', 'e IN (1, 2, 2, 6)', 'e = 99', 'd = 1.5', 'd = 2', "d = '0.5'",
              'p = 2.5', 'p = 3.75', "p = '1.25'", 'p IN (0, 6.25)', "x = '3'", "x = 3", "x = '03'", "x IN ('1', '01', 2)"];
    foreach ($conds as $cond) {
        $a = $bd->consultar("SELECT COUNT(*) AS n FROM t WHERE $cond")[0]['n'];
        // Lo mismo sin índice: x + 0 compara como la columna numérica, y
        // CAST(x AS TEXT) como la de texto ('03' no es 3, 2.10)
        $sin = $cond[0] === 'x' ? preg_replace('/^(\w+)/', 'CAST($1 AS TEXT)', $cond) : preg_replace('/^(\w+)/', '$1 + 0', $cond);
        $b = $bd->consultar("SELECT COUNT(*) AS n FROM t WHERE $sin")[0]['n'];
        $via = $bd->consultar("EXPLAIN SELECT COUNT(*) AS n FROM t WHERE $cond")[0]['acceso'];
        if ($a !== $b || ($via !== 'recuento en el índice, sin leer filas' && !str_starts_with($via, 'recorrido'))) { $mal[] = "$cond: $a / $b ($via)"; }
    }
    $conBool = $bd->consultar('SELECT COUNT(*) AS n FROM t WHERE e = ?', [true])[0]['n'];
    $sinBool = $bd->consultar('SELECT COUNT(*) AS n FROM t WHERE e + 0 = ?', [true])[0]['n'];
    unset($bd);
    Database::borrar('k', $dir);
    @rmdir($dir);
    return $mal === [] && $conBool === $sinBool ?: implode(' | ', $mal) . " bool $conBool/$sinBool";
});

esperaError('UPDATE por índice que viola la clave primaria', 'CONSTRAINT', function () use ($raiz) {
    $dir = $raiz . '/parcial3';
    @mkdir($dir, 0775, true);
    Database::crear('p', $dir);
    $bd = new Database('p', $dir);
    $bd->consultar('CREATE TABLE ci (id INTEGER PRIMARY KEY, v VARCHAR(10))');
    $bd->consultar("INSERT INTO ci VALUES (1,'a'),(2,'b')");
    try {
        $bd->consultar('UPDATE ci SET id = 2 WHERE id = 1');
    } finally {
        $intacta = $bd->consultar('SELECT id FROM ci ORDER BY id') === [['id' => 1], ['id' => 2]];
        unset($bd);
        Database::borrar('p', $dir);
        @rmdir($dir);
        if (!$intacta) { throw new RuntimeException('la tabla cambió'); }
    }
});

echo "\n== Limpieza ==\n";
chk('borrar la base de pruebas', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

echo "\n== Elegir índice (2.7.4) ==\n";

chk('con dos índices que cubren una columna cada uno, gana el único: «id = ? AND ciudad = ?» va por la clave primaria', function () {
    // Antes ganaba el primero definido, y los creados a mano van delante de los
    // automáticos: se leían todas las filas de esa ciudad en vez de una
    $defs = [
        ['name' => 'ix_ciudad', 'columns' => ['ciudad'], 'auto' => false, 'unico' => false],
        ['name' => 'auto_id', 'columns' => ['id'], 'auto' => true, 'unico' => true],
        ['name' => 'ix_ciudad_edad', 'columns' => ['ciudad', 'edad'], 'auto' => false, 'unico' => false, 'minimo' => 1],
    ];
    $a = IndexesBusqueda::candidatos($defs, ['id' => [5], 'ciudad' => ['Oslo']]);
    $b = IndexesBusqueda::candidatos($defs, ['ciudad' => ['Oslo'], 'edad' => [30]]);
    // 600 claves numéricas sí (cada una se busca en un solo trozo); 5.000, no
    $c = IndexesBusqueda::candidatos($defs, ['id' => range(1, 5000), 'ciudad' => ['Oslo']]);
    $d = IndexesBusqueda::candidatos($defs, ['id' => range(1, 600), 'ciudad' => ['Oslo']]);
    // Si edad admite NULL, (ciudad, edad) no sirve para buscar solo por ciudad
    // en toda la tabla: va el último, solo para las partes sin NULL (2.10)
    $defs[2]['minimo'] = 2;
    $e = IndexesBusqueda::candidatos($defs, ['id' => [5], 'ciudad' => ['Oslo']]);
    $nombres = static fn($l) => implode(',', array_map(static fn($x) => $x['def']['name'] . ($x['parcial'] ? '*' : ''), $l));
    return $nombres($a) === 'auto_id,ix_ciudad,ix_ciudad_edad' && $nombres($b) === 'ix_ciudad_edad,ix_ciudad'
        && $nombres($c) === 'ix_ciudad,ix_ciudad_edad' && $nombres($d) === 'auto_id,ix_ciudad,ix_ciudad_edad' && $nombres($e) === 'auto_id,ix_ciudad,ix_ciudad_edad*'
        ?: $nombres($a) . ' | ' . $nombres($b) . ' | ' . $nombres($c) . ' | ' . $nombres($d) . ' | ' . $nombres($e);
});

echo "\n== MIN y MAX por índice (2.9) ==\n";

chk('MIN y MAX de columnas con índice salen de los rangos del índice y coinciden con recorrer la tabla, escritura tras escritura', function () use ($raiz) {
    $dir = "$raiz/mm";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('m', $dir);
    $bd = new Database('m', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, e INTEGER, d DOUBLE, p DECIMAL(10,2), s TEXT NOT NULL)');
    $bd->consultar('CREATE INDEX ie ON t (e)');
    $bd->consultar('CREATE INDEX idd ON t (d, s)');      // la primera columna es la que cuenta, con s NOT NULL (2.10)
    $bd->consultar('CREATE INDEX ip ON t (p)');
    mt_srand(7);
    $consulta = 'SELECT MIN(e) AS a, MAX(e) AS b, MIN(d) AS c, MAX(d), MIN(p) AS f, MAX(p) AS g, MAX(id) AS h, MIN(id) FROM t';
    for ($ronda = 0; $ronda < 30; $ronda++) {
        $v = [];
        for ($i = 0; $i < 20; $i++) {
            $v[] = '(' . (mt_rand(0, 5) ? mt_rand(-1000, 1000) : 'NULL') . ', ' . (mt_rand(0, 5) ? mt_rand(-99999, 99999) / 7 : 'NULL')
                 . ', ' . (mt_rand(0, 5) ? mt_rand(-50000, 50000) / 100 : 'NULL') . ", 'x')";
        }
        $bd->consultar('INSERT INTO t (e, d, p, s) VALUES ' . implode(', ', $v));
        // Borrar el máximo y el mínimo cambia los extremos: los rangos tienen que seguirlo
        $bd->consultar('DELETE FROM t WHERE e = (SELECT MAX(e) FROM t WHERE 1 = 1) OR d = (SELECT MIN(d) FROM t WHERE 1 = 1)');
        $bd->consultar('UPDATE t SET p = ' . mt_rand(-900, 900) . ' WHERE id % 13 = ' . mt_rand(0, 12));
        $rapida  = $bd->consultar($consulta);
        $lenta   = $bd->consultar($consulta . ' WHERE 1 = 1');    // con WHERE no hay atajo: recorre la tabla
        if ($rapida !== $lenta) {
            return "ronda $ronda: " . json_encode($rapida) . ' / ' . json_encode($lenta);
        }
    }
    // Y de verdad no lee la tabla: con sus ficheros de datos ilegibles, sigue
    // contestando (recorrerla daría «Datos ilegibles»)
    $copia = "$raiz/mm2";
    borrarArbol($copia);
    @mkdir("$copia/m", 0775, true);
    foreach (glob("$dir/m/*") as $f) {
        file_put_contents("$copia/m/" . basename($f), preg_match('/^t(\.part\d+)?\.json$/', basename($f)) ? 'roto' : file_get_contents($f));
    }
    $sinDatos = (new Database('m', $copia))->consultar($consulta);
    $vacia = (new Database('m', $dir));
    $vacia->consultar('DELETE FROM t');
    $nulos = $vacia->consultar('SELECT MIN(e) AS a, MAX(p) AS b FROM t');
    borrarArbol($dir);
    borrarArbol($copia);
    return $sinDatos === $lenta && $nulos === [['a' => null, 'b' => null]] ?: json_encode([$sinDatos, $nulos]);
});

echo "\n== Rangos y comparaciones por índice (2.9) ==\n";

chk('COUNT(*) y SELECT con rangos, BETWEEN e igualdades sobre fechas, textos y números dan lo mismo que sin índice', function () use ($raiz) {
    $dir = "$raiz/rg";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('r', $dir);
    $bd = new Database('r', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, f DATETIME, s TEXT, x TEXT, n INTEGER, d DOUBLE)');
    foreach (['f', 's', 'x', 'n', 'd'] as $c) {
        $bd->consultar("CREATE INDEX i_$c ON t ($c)");
    }
    mt_srand(11);
    $pal = ['ana', 'Ávila', 'bea', 'Bea', 'zoe', 'z', 'm-1', 'm.0', '', 'ñu'];
    // x mezcla textos con textos que parecen números: el índice guarda estos como número
    $raros = ['05', ' 5 ', '5.0', 'abc', '-3', '1e2', 'x9'];
    for ($ronda = 0; $ronda < 6; $ronda++) {
        $v = [];
        for ($i = 0; $i < 60; $i++) {
            // Las fechas crecen con la inserción (como un alta) y a veces se repiten o faltan
            $f = mt_rand(0, 9) ? "'" . date('Y-m-d H:i:s', 1767225600 + ($ronda * 60 + $i) * 3600 * mt_rand(1, 3)) . "'" : 'NULL';
            $v[] = "($f, " . (mt_rand(0, 9) ? "'" . $pal[mt_rand(0, 9)] . "'" : 'NULL') . ", '" . $raros[mt_rand(0, 6)] . "', "
                 . (mt_rand(0, 9) ? mt_rand(-50, 50) : 'NULL') . ', ' . (mt_rand(0, 9) ? mt_rand(-5000, 5000) / 100 : 'NULL') . ')';
        }
        $bd->consultar('INSERT INTO t (f, s, x, n, d) VALUES ' . implode(', ', $v));
        $bd->consultar('DELETE FROM t WHERE id % 17 = ' . mt_rand(0, 16));
    }
    $condiciones = [
        "f BETWEEN '2026-01-03' AND '2026-01-09'", "f >= '2026-01-20 10:00:00'", "f < '2026-01-02'", "'2026-01-05' < f AND f <= '2026-01-06T12:00'",
        "s > 'b'", "s <= 'Bea'", "s BETWEEN 'a' AND 'z'", "s = 'zoe'", "s > 'm-'", "s < 'm.1'",
        "x > 'a'", "x < '5'", "x = '05'", "x BETWEEN '0' AND '9'", "x > '0x'", "x < '0x'", "x > '!'",
        'n < 10', "f < '2026-01-01 05:00:00'", "f > '2026-01-01 05:00:00'",
        'n > 10', 'n BETWEEN -5 AND 5', 'n = 0', '-10 >= n', 'n > 2.5', "n > '3'",
        'd < 0', 'd BETWEEN 1.5 AND 20', 'd >= -0.5 AND d < 0.5', 'n >= 0 AND n <= 3',
    ];
    foreach ($condiciones as $c) {
        // El mismo WHERE con cada columna envuelta en un CASE: igual valor, sin índice
        $sinIndice = preg_replace('/\b([fsxnd])\b/', 'CASE WHEN 1 THEN $1 END', $c);
        foreach (['SELECT COUNT(*) AS c FROM t WHERE ', 'SELECT * FROM t WHERE '] as $q) {
            $a = $bd->consultar($q . $c);
            $b = $bd->consultar($q . $sinIndice);
            if ($a !== $b) {
                return "$q$c: " . json_encode(array_slice($a, 0, 3)) . ' / ' . json_encode(array_slice($b, 0, 3)) . ' (' . count($a) . '/' . count($b) . ')';
            }
        }
    }
    borrarArbol($dir);
    return true;
});

echo "\n== Filtro de las claves de texto en cada trozo (2.9) ==\n";

chk('con el filtro de cada trozo, una clave de texto se encuentra siempre, UNIQUE se respeta y solo se lee su trozo', function () use ($raiz) {
    $dir = "$raiz/flt";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('f', $dir);
    $bd = new Database('f', $dir);
    $bd->consultar('CREATE TABLE u (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(40) UNIQUE, n INTEGER)');
    mt_srand(5);
    $vivos = [];
    for ($ronda = 0; $ronda < 12; $ronda++) {
        $v = [];
        for ($i = 0; $i < 60; $i++) {
            $e = 'p' . $ronda . '_' . $i . '@x.es';
            $v[] = "('$e', $i)";
            $vivos[$e] = true;
        }
        $bd->consultar('INSERT INTO u (email, n) VALUES ' . implode(', ', $v));
        // Cambiar y borrar claves en trozos ya escritos
        $todas = array_keys($vivos);
        $viejo = $todas[mt_rand(0, count($todas) - 1)];
        $bd->consultar('UPDATE u SET email = ? WHERE email = ?', ["cambiado$ronda@x.es", $viejo]);
        unset($vivos[$viejo]);
        $vivos["cambiado$ronda@x.es"] = true;
        $borrado = $todas[mt_rand(0, count($todas) - 1)];
        if (isset($vivos[$borrado])) {
            $bd->consultar('DELETE FROM u WHERE email = ?', [$borrado]);
            unset($vivos[$borrado]);
        }
        foreach (array_rand($vivos, 20) as $e) {
            if (count($bd->consultar('SELECT id FROM u WHERE email = ?', [$e])) !== 1) { return "ronda $ronda: no se encuentra $e"; }
        }
        if ($bd->consultar('SELECT id FROM u WHERE email = ?', ["nadie$ronda@x.es"]) !== []) { return 'encuentra una que no está'; }
        try {
            $bd->consultar('INSERT INTO u (email, n) VALUES (?, 0)', [array_rand($vivos)]);
            return "ronda $ronda: dejó repetir una clave única";
        } catch (JsonSQLDB\JsonSqlDbError $e) {
            if ($e->sqlState !== 'CONSTRAINT') { return $e->getMessage(); }
        }
    }
    // Solo se lee el trozo de la fila: con las claves de todos los demás
    // estropeadas (la cabecera y el filtro intactos) y las demás partes de
    // datos ilegibles, la búsqueda contesta. Sin filtro iría a leer esos
    // trozos, los vería rotos y recorrería la tabla: «Datos ilegibles»
    // Una tabla escrita solo con INSERT, para que todos sus trozos tengan filtro
    // (un DELETE en medio los reescribe sin él, y entonces se leen enteros)
    $bd->consultar('CREATE TABLE v (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(40) UNIQUE, n INTEGER)');
    $bd->consultar('INSERT INTO v (email, n) SELECT email, n FROM u');
    $e = array_keys($vivos)[300];
    $pos = array_search($e, array_column($bd->consultar('SELECT email FROM v'), 'email'), true);
    $suya = intdiv((int)$pos, 50) + 1;
    $copia = "$raiz/flt2";
    borrarArbol($copia);
    @mkdir("$copia/f", 0775, true);
    foreach (glob("$dir/f/*") as $f) {
        $b = basename($f);
        $texto = (string)file_get_contents($f);
        $esParte = preg_match('/^v(?:\.part(\d+))?\.json$/', $b, $m) && (int)($m[1] ?? 1) !== $suya;
        // Se estropean los trozos cuyo filtro dice que la clave no está (los
        // demás pueden dar un falso positivo, ~1 %, y entonces se leen enteros)
        $esTrozo = preg_match('/^v\.idx\.auto_email(?:\.part(\d+))?\.json$/', $b, $t) && (int)($t[1] ?? 1) !== $suya
            && preg_match('/,"filtro":"([^"]*)"/', $texto, $fl)
            && !JsonSQLDB\IndexesBusqueda::puedeEstar((string)JsonSQLDB\Indexes::filtro(['filtro' => $fl[1]]), (string)JsonSQLDB\Indexes::clave([$e]));
        if ($esParte) {
            $texto = "roto \"$e\"";                      // con la clave dentro: buscar su texto obligaría a decodificarla
        } elseif ($esTrozo) {
            $texto = substr($texto, 0, (int)strpos($texto, '"keys":{')) . '"keys":{ roto';
        }
        file_put_contents("$copia/f/$b", $texto);
    }
    $r = (new Database('f', $copia))->consultar('SELECT email FROM v WHERE email = ?', [$e]);
    borrarArbol($dir);
    borrarArbol($copia);
    return $r === [['email' => $e]] ?: json_encode($r);
});

chk('un índice con números repartidos por toda la tabla lleva filtro de números («cubre»: «todo»), que encuentra cada clave leyendo solo sus trozos (2.9)', function () use ($raiz) {
    $dir = "$raiz/flt4";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('f', $dir);
    $bd = new Database('f', $dir);
    $bd->consultar('CREATE TABLE p (id INTEGER PRIMARY KEY AUTOINCREMENT, cid INTEGER)');
    $bd->consultar('CREATE INDEX ix_cid ON p (cid)');
    mt_srand(7);
    $v = $veces = [];
    for ($i = 0; $i < 600; $i++) {
        $c = mt_rand(1, 100000);                        // el rango de cada trozo abarca toda la tabla
        $v[] = "($c)";
        $veces[$c] = ($veces[$c] ?? 0) + 1;
    }
    $bd->consultar('INSERT INTO p (cid) VALUES ' . implode(', ', $v));
    $trozos = glob("$dir/f/p.idx.ix_cid*.json");
    if (count($trozos) < 4) { return 'pocos trozos: ' . count($trozos); }
    foreach ($trozos as $f) {
        if (!str_contains((string)file_get_contents($f), '"filtro":"') || !str_contains((string)file_get_contents($f), ',"cubre":"todo"')) { return basename($f) . ' no cubre los números'; }
    }
    // Una clave que está una sola vez: su fila va en una parte. Como en la
    // prueba del filtro de texto, con las claves de los demás trozos y las
    // demás partes ilegibles, la búsqueda contesta igual; sin filtro de
    // números leería esos trozos y, rotos, recorrería la tabla: «Datos ilegibles»
    $cid   = (int)array_search(1, $veces, true);
    $pos   = array_search($cid, array_column($bd->consultar('SELECT cid FROM p'), 'cid'), true);
    $suya  = intdiv((int)$pos, 50) + 1;
    $clave = (string)JsonSQLDB\Indexes::clave([$cid]);
    $copia = "$raiz/flt5";
    borrarArbol($copia);
    @mkdir("$copia/f", 0775, true);
    foreach (glob("$dir/f/*") as $f) {
        $b = basename($f);
        $texto = (string)file_get_contents($f);
        if (preg_match('/^p(?:\.part(\d+))?\.json$/', $b, $m) && (int)($m[1] ?? 1) !== $suya) {
            $texto = "roto $cid";
        } elseif (preg_match('/^p\.idx\.ix_cid(?:\.part(\d+))?\.json$/', $b, $t) && (int)($t[1] ?? 1) !== $suya
            && preg_match('/,"filtro":"([^"]*)","cubre":"([a-z]+)"/', $texto, $fl)
            && !JsonSQLDB\IndexesBusqueda::puedeEstar((string)JsonSQLDB\Indexes::filtro(['filtro' => $fl[1], 'cubre' => $fl[2]]), $clave)) {
            $texto = substr($texto, 0, (int)strpos($texto, '"keys":{')) . '"keys":{ roto';
        }
        file_put_contents("$copia/f/$b", $texto);
    }
    $r = (new Database('f', $copia))->consultar('SELECT cid FROM p WHERE cid = ?', [$cid]);
    $ninguna = $bd->consultar('SELECT cid FROM p WHERE cid = ?', [100001]);
    borrarArbol($dir);
    borrarArbol($copia);
    return $r === [['cid' => $cid]] && $ninguna === [] ?: json_encode([$r, $ninguna]);
});

chk('un trozo sin filtro (escrito por una versión anterior) se sigue leyendo entero y bien', function () use ($raiz) {
    $dir = "$raiz/flt3";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('f', $dir);
    $bd = new Database('f', $dir);
    $bd->consultar('CREATE TABLE u (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(40) UNIQUE)');
    $v = [];
    for ($i = 0; $i < 200; $i++) { $v[] = "('c$i@x')"; }
    $bd->consultar('INSERT INTO u (email) VALUES ' . implode(', ', $v));
    // Se quita el filtro de los trozos, como los escribe la 2.8
    foreach (glob("$dir/f/u.idx.auto_email*.json") as $f) {
        file_put_contents($f, preg_replace('/,"filtro":"[^"]*"(,"cubre":"[a-z]+")?/', '', (string)file_get_contents($f)));
    }
    $otra = new Database('f', $dir);
    $r = $otra->consultar("SELECT email FROM u WHERE email = 'c150@x'");
    try {
        $otra->consultar("INSERT INTO u (email) VALUES ('c42@x')");
        $repetida = true;
    } catch (JsonSQLDB\JsonSqlDbError) {
        $repetida = false;
    }
    $otra->consultar("INSERT INTO u (email) VALUES ('nueva@x')");
    $n = $otra->consultar("SELECT COUNT(*) AS n FROM u WHERE email = 'nueva@x'");
    borrarArbol($dir);
    return $r === [['email' => 'c150@x']] && !$repetida && $n === [['n' => 1]] ?: json_encode([$r, $repetida, $n]);
});

chk('VACUUM rehace con filtro los índices escritos por una versión anterior, sin tocar las partes, y una segunda vez no toca nada (2.9)', function () use ($raiz) {
    $dir = "$raiz/flt4";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('f', $dir);
    $bd = new Database('f', $dir);
    $bd->consultar('CREATE TABLE u (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(40) UNIQUE)');
    $v = [];
    for ($i = 0; $i < 3000; $i++) { $v[] = "('c$i@x')"; }
    $bd->consultar('INSERT INTO u (email) VALUES ' . implode(', ', $v));
    // Los trozos como los escribe la 2.8: sin filtro, ni menor ni mayor texto
    $trozos = glob("$dir/f/u.idx.*.json");
    foreach ($trozos as $f) {
        file_put_contents($f, preg_replace('/,"filtro":"[^"]*"(,"cubre":"[a-z]+")?(,"min":"[^"]*")?(,"max":"[^"]*")?/', '', (string)file_get_contents($f)));
    }
    $sinFiltro = static fn(): int => count(array_filter(glob("$dir/f/u.idx.*.json"), static fn($f) => !str_contains((string)file_get_contents($f), '"filtro":"')));
    $partes = static fn(): array => array_map('md5_file', glob("$dir/f/u.part*.json") ?: glob("$dir/f/u.json"));
    $antes  = [$sinFiltro(), $partes()];
    $otra   = new Database('f', $dir);
    $r1     = $otra->consultar('VACUUM');
    $tras   = [$sinFiltro(), $partes()];
    $r2     = $otra->consultar('VACUUM');
    $busca  = $otra->consultar("SELECT id FROM u WHERE email = 'c2500@x'");
    borrarArbol($dir);
    return count($trozos) > 1 && $antes[0] === count($trozos) && $tras[0] === 0 && $tras[1] === $antes[1]
        && str_contains($r1['mensaje'], 'índices de 1 tabla') && str_contains($r2['mensaje'], 'no había')
        && $busca === [['id' => 2501]] ?: json_encode([count($trozos), $antes[0], $tras[0], $r1['mensaje'], $r2['mensaje'], $busca]);
});
chk('el barrido de la caché de cada 32 revisiones conserva el recuento de las partes y, de cada índice, el veredicto, los filtros y el menor y el mayor de sus trozos (2.9)', function () use ($raiz) {
    // Llevan dentro la revisión de cada parte o trozo. Antes se barrían, y la
    // búsqueda siguiente de un texto volvía a abrir la cabecera de cada trozo
    $dir = "$raiz/flt5";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('f', $dir);
    $bd = new Database('f', $dir);
    $bd->consultar('CREATE TABLE u (id INTEGER PRIMARY KEY, email VARCHAR(40), n INTEGER)');
    $bd->consultar('CREATE INDEX ix_email ON u (email)');
    $v = [];
    for ($i = 1; $i <= 200; $i++) { $v[] = "($i, 'c$i@x', 0)"; }
    $bd->consultar('INSERT INTO u VALUES ' . implode(', ', $v));
    foreach (glob("$dir/f/u*.json") ?: [] as $f) { touch($f, time() - 60); }      // el recuento no guarda una parte de este mismo segundo
    $rev = static fn(): int => json_decode((string)file_get_contents("$dir/f/u.rev.json"), true)['rev'];
    while ($rev() % 32 !== 31) { $bd->consultar('UPDATE u SET n = n + 1 WHERE id = 1'); }
    $bd->consultar('SELECT COUNT(*) FROM u');                                       // el recuento
    $bd->consultar("SELECT id FROM u WHERE email = 'c150@x'");                      // los filtros
    $bd->consultar("SELECT COUNT(*) FROM u WHERE email > 'c5'");                    // el menor y el mayor
    $hay = static fn(): array => array_map(static fn(string $t): int => count(glob("$dir/f/.cache/*.$t-0.cache") ?: []), ['n', 'fix_email', 'vix_email', 'lix_email']);
    $antes = $hay();
    $bd->consultar('UPDATE u SET n = n + 1 WHERE id = 1');                          // la revisión 32: barre
    $tras = [$rev(), $hay()];
    borrarArbol($dir);
    return $antes === [1, 1, 1, 1] && $tras === [32, [1, 1, 1, 1]] ?: json_encode([$antes, $tras]);
});

echo "\n== ORDER BY … LIMIT por índice (2.9) ==\n";

chk('dos claves únicas con el mismo índice automático ((a, b) y (a_b)): no se pueden crear, y en una tabla así de antes las consultas y la unicidad van bien (2.9)', function () use ($raiz) {
    Database::crear('choque', $raiz);
    $b = new Database('choque', $raiz);
    try { $b->consultar('CREATE TABLE t (a INTEGER, b INTEGER, a_b INTEGER, UNIQUE (a, b), UNIQUE (a_b))'); $crear = 'pasó'; } catch (\JsonSQLDB\JsonSqlDbError $e) { $crear = $e->getMessage(); }
    // Igual con una coma en un nombre: ("x y,z") y ("x y", z) se tomaban por la misma lista (2.9)
    try { $b->consultar('CREATE TABLE w (id INTEGER PRIMARY KEY, "x y,z" TEXT, "x y" TEXT, z TEXT, UNIQUE ("x y,z"), UNIQUE ("x y", z))'); $coma = 'pasó'; } catch (\JsonSQLDB\JsonSqlDbError $e) { $coma = $e->getMessage(); }
    // Una tabla así, hecha por una versión anterior: x pasa a llamarse a_b en los ficheros
    $b->consultar('CREATE TABLE v (a INTEGER, b INTEGER, x INTEGER, UNIQUE (a, b), UNIQUE (x))');
    $b->consultar('INSERT INTO v VALUES (1, 2, 10), (1, 3, 11)');
    unset($b);
    $d = $raiz . '/choque';
    foreach (['v.meta.json', 'v.json'] as $f) {
        file_put_contents("$d/$f", str_replace('"x"', '"a_b"', (string)file_get_contents("$d/$f")));
    }
    @unlink("$d/v.idx.auto_x.json");
    $b = new Database('choque', $raiz);
    $b->consultar('INSERT INTO v VALUES (7, 7, 77)');            // una escritura: escribe sus índices
    $fila = array_merge($b->consultar('SELECT a_b FROM v WHERE a = 1 AND b = 2'), $b->consultar('SELECT b FROM v WHERE a_b = 11'),
                        $b->consultar('SELECT a FROM v WHERE a_b = 77'));
    $dup = [];
    foreach (['INSERT INTO v VALUES (1, 2, 12)', 'INSERT INTO v VALUES (5, 5, 10)'] as $q) {
        try { $b->consultar($q); $dup[] = 'pasó'; } catch (\JsonSQLDB\JsonSqlDbError $e) { $dup[] = $e->sqlState; }
    }
    unset($b);
    Database::borrar('choque', $raiz);
    return str_contains($crear, "mismo índice, 'auto_a_b'") && $fila === [['a_b' => 10], ['b' => 3], ['a' => 7]] && $dup === ['CONSTRAINT', 'CONSTRAINT']
        && str_contains($coma, 'mismo índice') ?: json_encode([$crear, $fila, $dup, $coma], JSON_UNESCAPED_UNICODE);
});
chk('ORDER BY col [DESC] LIMIT n con índice da lo mismo que ordenar la tabla entera, con empates, NULL y OFFSET', function () use ($raiz) {
    $dir = "$raiz/ord";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('o', $dir);
    $bd = new Database('o', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, a INTEGER, d DOUBLE, nn INTEGER NOT NULL, s TEXT)');
    foreach (['a', 'd', 'nn'] as $c) {
        $bd->consultar("CREATE INDEX i_$c ON t ($c)");
    }
    mt_srand(9);
    $consultas = [
        'SELECT * FROM t ORDER BY a DESC LIMIT 7', 'SELECT id, a FROM t ORDER BY a DESC LIMIT 5 OFFSET 13',
        'SELECT * FROM t ORDER BY d DESC LIMIT 20', 'SELECT * FROM t ORDER BY nn LIMIT 9', 'SELECT * FROM t ORDER BY nn DESC LIMIT 30',
        'SELECT * FROM t ORDER BY id DESC LIMIT 3', 'SELECT s, nn AS x FROM t ORDER BY nn ASC LIMIT 4 OFFSET 2',
        'SELECT * FROM t ORDER BY a LIMIT 5',                 // con NULL: va por el camino de siempre
        'SELECT nn * -1 AS nn FROM t ORDER BY nn LIMIT 5',    // el alias manda (2.9, como SQLite): tampoco
    ];
    for ($ronda = 0; $ronda < 8; $ronda++) {
        $v = [];
        for ($i = 0; $i < 50; $i++) {
            // Pocos valores distintos: muchos empates en el corte
            $v[] = '(' . (mt_rand(0, 6) ? mt_rand(-20, 20) : 'NULL') . ', ' . (mt_rand(0, 6) ? mt_rand(-400, 400) / 8 : 'NULL')
                 . ', ' . mt_rand(0, 15) . ", 'x$i')";
        }
        $bd->consultar('INSERT INTO t (a, d, nn, s) VALUES ' . implode(', ', $v));
        $bd->consultar('DELETE FROM t WHERE a = (SELECT MAX(a) FROM t WHERE 1 = 1)');
        $bd->consultar('UPDATE t SET nn = ' . mt_rand(0, 15) . ' WHERE id % 11 = ' . mt_rand(0, 10));
        foreach ($consultas as $q) {
            // El mismo orden envuelto en un CASE: igual valor, sin índice (con el
            // alias, su expresión: dentro de una expresión, nn es la columna)
            $sinIndice = str_contains($q, ' AS nn ') ? str_replace('ORDER BY nn', 'ORDER BY -nn', $q)
                : preg_replace('/ORDER BY (\w+)/', 'ORDER BY CASE WHEN 1 THEN $1 END', $q);
            $a = $bd->consultar($q);
            $b = $bd->consultar($sinIndice);
            if ($a !== $b) {
                return "ronda $ronda, $q: " . json_encode($a) . ' / ' . json_encode($b);
            }
        }
    }
    borrarArbol($dir);
    return true;
});

chk('WHERE col > n ORDER BY col LIMIT k (y DESC con <) lee el índice desde el límite y da lo mismo que ordenar la tabla, con varias partes, empates, NULL, borradas y >=, también escrito n < col, y con pocas filas detrás del límite, por el rango, que cuesta menos (2.10: leía todo el rango)', function () use ($raiz) {
    // Es lo que hace quien recorre una tabla por su clave, lote a lote
    $dir = "$raiz/ordlim";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('o', $dir);
    $bd = new Database('o', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, nn INTEGER NOT NULL, d DOUBLE, s TEXT)');
    $bd->consultar('CREATE INDEX i_nn ON t (nn)');
    $bd->consultar('CREATE INDEX i_d ON t (d)');
    mt_srand(17);
    $v = [];
    for ($i = 1; $i <= 4500; $i++) {
        $v[] = '(' . ($i * 3) . ', ' . mt_rand(0, 300) . ', ' . (mt_rand(0, 9) ? mt_rand(-800, 800) / 4 : 'NULL') . ", 'x$i')";
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(', ', $v));
    $bd->consultar('DELETE FROM t WHERE id % 7 = 0');
    $consultas = [];
    foreach ([0, 2999, 3000, 6001, 13499, 13500, 20000] as $x) {
        $consultas[] = "SELECT * FROM t WHERE id > $x ORDER BY id LIMIT 50";
        $consultas[] = "SELECT id FROM t WHERE id >= $x ORDER BY id LIMIT 7";
        $consultas[] = "SELECT * FROM t WHERE id < $x ORDER BY id DESC LIMIT 40";
        $consultas[] = "SELECT id, s FROM t WHERE $x < id ORDER BY id LIMIT 20 OFFSET 3";
        $consultas[] = "SELECT id FROM t WHERE id < $x ORDER BY id LIMIT 10";           // el límite al otro lado: como siempre
        $consultas[] = "SELECT id FROM t WHERE id > $x ORDER BY id DESC LIMIT 10";
    }
    foreach ([-1, 0, 150, 299, 300] as $x) {
        $consultas[] = "SELECT * FROM t WHERE nn > $x ORDER BY nn LIMIT 30";
        $consultas[] = "SELECT * FROM t WHERE nn <= $x ORDER BY nn DESC LIMIT 25";
        $consultas[] = "SELECT * FROM t WHERE d > " . ($x - 150) . ".5 ORDER BY d LIMIT 15";
        $consultas[] = "SELECT * FROM t WHERE d < " . ($x - 150) . " ORDER BY d DESC LIMIT 15";
    }
    $mal = [];
    foreach ($consultas as $q) {
        $sinIndice = (string)preg_replace('/ORDER BY (\w+)/', 'ORDER BY CASE WHEN 1 THEN $1 END', $q);
        if ($bd->consultar($q) !== $bd->consultar($sinIndice)) { $mal[] = $q; }
    }
    $via = $bd->consultar('EXPLAIN SELECT * FROM t WHERE id > 6000 ORDER BY id LIMIT 50')[0]['acceso'] ?? '';
    // Con pocas filas detrás del límite, por el rango, que cuesta menos
    $cerca = $bd->consultar('EXPLAIN SELECT * FROM t WHERE id > 13000 ORDER BY id LIMIT 50')[0]['acceso'] ?? '';
    // Un ORDER BY que no es una columna no pasa por aquí, sin avisos de PHP
    $avisos = [];
    set_error_handler(static function (int $n, string $m) use (&$avisos): bool { $avisos[] = $m; return true; });
    try {
        foreach (['SELECT id FROM t WHERE id > 5 ORDER BY id + 0 LIMIT 3', 'SELECT id FROM t WHERE id > 5 ORDER BY 1 LIMIT 3'] as $q) {
            if ($bd->consultar($q) !== $bd->consultar(str_replace(' LIMIT 3', '', $q) . ' LIMIT 3 OFFSET 0')) { $mal[] = $q; }
        }
    } finally {
        restore_error_handler();
    }
    if ($avisos !== []) { $mal[] = $avisos; }
    borrarArbol($dir);
    return $mal === [] && str_contains($via, 'desde el límite del WHERE') && $cerca === 'índice, rango'
        ?: json_encode([$mal, $via, $cerca], JSON_UNESCAPED_UNICODE);
});

chk('ORDER BY col [DESC] LIMIT n de una columna de texto con índice da lo mismo que ordenar la tabla entera, con la colación general y con la binaria, con empates, mayúsculas y acentos (2.10: se leía la tabla)', function () use ($raiz) {
    $dir = "$raiz/ordtxt";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('o', $dir);
    $bd = new Database('o', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, tn TEXT NOT NULL, tx TEXT, tc TEXT COLLATE NOCASE NOT NULL)');
    foreach (['tn', 'tx', 'tc'] as $c) {
        $bd->consultar("CREATE INDEX i_$c ON t ($c)");
    }
    mt_srand(11);
    $textos = ['ana', 'Ana', 'ÁNA', 'ána', 'bea', 'Bea', 'beá', 'zoe', 'Zoe', '10', '9', '007', 'ñu', 'Ñu', 'nu', 'a b', 'a-b'];
    $v = [];
    for ($i = 1; $i <= 2600; $i++) {
        $t = $textos[mt_rand(0, count($textos) - 1)] . (mt_rand(0, 3) ? '' : (string)mt_rand(0, 40));
        $v[] = "($i, '$t', " . (mt_rand(0, 5) ? "'" . $textos[mt_rand(0, count($textos) - 1)] . "'" : 'NULL') . ", '$t')";
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(', ', $v));
    $bd->consultar('DELETE FROM t WHERE id % 97 = 0');
    unset($bd);
    // Con índice las primeras cinco; las otras, por el camino de siempre (NULL en
    // orden ascendente, COLLATE en la columna o en el ORDER BY)
    $consultas = ['SELECT * FROM t ORDER BY tn LIMIT 9', 'SELECT id, tn FROM t ORDER BY tn DESC LIMIT 12 OFFSET 5',
                  'SELECT * FROM t ORDER BY tn COLLATE BINARY LIMIT 7', 'SELECT * FROM t ORDER BY tn COLLATE BINARY DESC LIMIT 40',
                  'SELECT id, tx FROM t ORDER BY tx DESC LIMIT 6', 'SELECT * FROM t ORDER BY tx LIMIT 5',
                  'SELECT * FROM t ORDER BY tc LIMIT 5', 'SELECT * FROM t ORDER BY tn COLLATE NOCASE LIMIT 5'];
    $mal = [];
    foreach (['general', 'binaria'] as $colacion) {
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0); define("JSONSQLDB_COLACION", "' . $colacion . '");'
            . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . ' $bd = new JsonSQLDB\Database("o", ' . var_export($dir, true) . '); $r = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $q) {'
            . '   $sin = preg_replace("/ORDER BY (\\\\w+)/", "ORDER BY CASE WHEN 1 THEN $1 END", $q);'
            . '   $r[] = [$bd->consultar("EXPLAIN $q")[0]["acceso"], $bd->consultar($q) === $bd->consultar($sin)];'
            . ' } echo json_encode($r, JSON_UNESCAPED_UNICODE);';
        $r = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'), true);
        foreach ($consultas as $i => $q) {
            $porIndice = str_starts_with((string)($r[$i][0] ?? ''), 'índice, en orden');
            if (!is_array($r) || $r[$i][1] !== true || $porIndice !== $i < 5) {
                $mal[] = "$colacion, $q: " . json_encode($r[$i] ?? $r, JSON_UNESCAPED_UNICODE);
            }
        }
    }
    borrarArbol($dir);
    return $mal === [] ?: implode(' · ', $mal);
});
chk('un índice de varias columnas con NULL en las de detrás no se usa para buscar solo por las primeras: =, IN, COUNT, rangos, MIN/MAX, ORDER BY … LIMIT, JOIN, UPDATE y DELETE dan lo mismo que recorriendo (2.10: perdían esas filas UPDATE y DELETE desde la 2.5.0, y SELECT y COUNT desde la 2.7.4); con las de detrás NOT NULL sí se usa', function () use ($raiz) {
    $dir = "$raiz/compuesto";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('c', $dir);
    $bd = new Database('c', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER NOT NULL, b TEXT, c TEXT NOT NULL, d INTEGER, x DOUBLE, e TEXT NOT NULL, UNIQUE (a, b), UNIQUE (e, id))');
    $bd->consultar('CREATE INDEX icd ON t (c, d)');
    $bd->consultar('CREATE INDEX ixd ON t (x, d)');
    $bd->consultar('CREATE TABLE s (k INTEGER PRIMARY KEY, a INTEGER, c TEXT)');
    $v = $w = [];
    for ($i = 1; $i <= 600; $i++) {
        $v[] = "($i, " . ($i % 97) . ', ' . ($i % 3 === 0 ? 'NULL' : "'b$i'") . ", 'c" . ($i % 41) . "', " . ($i % 4 === 0 ? 'NULL' : $i) . ', ' . ($i % 61) . ".5, 'e$i')";
        $i <= 60 && $w[] = "($i, " . ($i * 7 % 97) . ", 'c" . ($i * 3 % 41) . "')";
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(', ', $v));
    $bd->consultar('INSERT INTO s VALUES ' . implode(', ', $w));
    // Con «+ 0» y «|| ''» la misma condición no puede ir por el índice
    $sin = static fn(string $q): string => preg_replace(['/\bt\.a\b(?! \+)/', '/(?<![.\w])a (=|>|<|IN|BETWEEN)/', "/(?<![.\\w])c (=|>|IN|BETWEEN)/", '/(?<![.\w])x (=|<)/', '/\bt\.c\b/', '/ORDER BY (a|x)( DESC)? LIMIT/', '/(MIN|MAX)\((a|x)\)/'],
        ['(t.a + 0)', '(a + 0) $1', "(c || '') $1", '(x + 0) $1', "(t.c || '')", 'ORDER BY CASE WHEN 1 THEN $1 END$2 LIMIT', '$1($2 + 0)'], $q);
    $consultas = ['SELECT id FROM t WHERE a = 7', 'SELECT id FROM t WHERE a IN (7, 8, 90)', 'SELECT COUNT(*) FROM t WHERE a = 7',
        "SELECT id FROM t WHERE c = 'c5'", "SELECT COUNT(*) FROM t WHERE c IN ('c5', 'c40')", 'SELECT COUNT(*) FROM t WHERE x = 7.5',
        'SELECT id FROM t WHERE a > 92', 'SELECT COUNT(*) FROM t WHERE a > 92', 'SELECT COUNT(*) FROM t WHERE a BETWEEN 50 AND 52',
        "SELECT id FROM t WHERE c > 'c38'", "SELECT COUNT(*) FROM t WHERE c BETWEEN 'c1' AND 'c19'", 'SELECT COUNT(*) FROM t WHERE x < 2',
        'SELECT a FROM t ORDER BY a DESC LIMIT 5', 'SELECT a FROM t ORDER BY a LIMIT 7', 'SELECT x FROM t ORDER BY x DESC LIMIT 4',
        'SELECT MIN(a), MAX(a), MIN(x), MAX(x) FROM t', 'SELECT id FROM t WHERE a = 7 AND b IS NULL',
        'SELECT s.k, t.id FROM s JOIN t ON t.a = s.a ORDER BY s.k, t.id', 'SELECT s.k, t.id FROM s JOIN t ON t.c = s.c ORDER BY s.k, t.id'];
    $comparar = static function () use ($bd, $consultas, $sin): ?string {
        foreach ($consultas as $q) {
            $a = array_map('array_values', $bd->consultar($q));
            $b = array_map('array_values', $bd->consultar($sin($q)));
            if ($sin($q) === $q || $a !== $b) {
                return "$q: " . json_encode($a) . ' / ' . $sin($q) . ': ' . json_encode($b);
            }
        }
        return null;
    };
    if (($r = $comparar()) !== null) {
        return $r;
    }
    $escrituras = ['UPDATE t SET d = 1 WHERE a = 9' => 'a + 0 = 9', "DELETE FROM t WHERE c = 'c10'" => "c || '' = 'c10'",
                   'DELETE FROM t WHERE a IN (10, 11)' => 'a + 0 IN (10, 11)', 'UPDATE t SET d = 3 WHERE a > 95' => 'a + 0 > 95'];
    foreach ($escrituras as $q => $condicion) {
        $antes = (int)$bd->consultar("SELECT COUNT(*) AS n FROM t WHERE $condicion")[0]['n'];
        $hechas = $bd->consultar($q)['filas'];
        if ($antes === 0 || $hechas !== $antes) {
            return "$q: cambió $hechas de $antes";
        }
    }
    if (($r = $comparar()) !== null) {
        return "tras las escrituras, $r";
    }
    $via = static fn(string $q): string => (string)($bd->consultar("EXPLAIN $q")[0]['indice'] ?? '');
    $usados = [$via('SELECT id FROM t WHERE a = 7'), $via("SELECT id FROM t WHERE e = 'e7'")];
    borrarArbol($dir);
    return $usados === ['', 'auto_e_id'] ?: json_encode($usados);
});

chk('un índice de varias columnas con NULL en las de detrás se usa en las partes donde no falta ninguna fila, y de las demás se leen todas: =, IN, UPDATE y DELETE dan lo mismo que recorriendo, también las filas con NULL (2.10)', function () use ($raiz) {
    $dir = "$raiz/porpartes";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('p', $dir);
    $bd = new Database('p', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER NOT NULL, b INTEGER, s TEXT, UNIQUE (a, b))');
    // Cinco partes: NULL en b solo en la cuarta, y la quinta sin llenar
    $v = [];
    for ($i = 1; $i <= 4600; $i++) {
        $v[] = "($i, " . ($i % 50) . ', ' . ($i > 3000 && $i <= 4000 && $i % 7 === 0 ? 'NULL' : $i) . ", 's$i')";
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(', ', $v));
    $sin = static fn(string $q): string => preg_replace('/\ba (=|IN)/', '(a + 0) $1', $q);
    $consultas = ['SELECT * FROM t WHERE a = 7', 'SELECT id, b FROM t WHERE a IN (7, 14, 49)', 'SELECT id FROM t WHERE a = 7 AND b IS NULL',
                  'SELECT COUNT(*) AS n FROM t WHERE a = 21'];
    $comparar = static function () use ($bd, $consultas, $sin): ?string {
        foreach ($consultas as $q) {
            if ($bd->consultar($q) !== $bd->consultar($sin($q))) {
                return "$q: " . json_encode($bd->consultar($q)) . ' / ' . json_encode($bd->consultar($sin($q)));
            }
        }
        return null;
    };
    $via = static fn(): string => (string)$bd->consultar('EXPLAIN SELECT * FROM t WHERE a = 7')[0]['acceso'];
    if (($r = $comparar()) !== null || !str_contains($via(), 'partes sin NULL')) {
        return $r ?? $via();
    }
    // Las filas con NULL de la cuarta parte también se cambian y se borran
    foreach (['UPDATE t SET s = ? WHERE a = 7' => 'a + 0 = 7', 'DELETE FROM t WHERE a IN (14, 28)' => 'a + 0 IN (14, 28)'] as $q => $condicion) {
        $antes = (int)$bd->consultar("SELECT COUNT(*) AS n FROM t WHERE $condicion")[0]['n'];
        $hechas = $bd->consultar($q, str_contains($q, '?') ? ['cambiada'] : [])['filas'];
        if ($antes === 0 || $hechas !== $antes) {
            return "$q: cambió $hechas de $antes";
        }
    }
    if (($r = $comparar()) !== null || (int)$bd->consultar("SELECT COUNT(*) AS n FROM t WHERE a = 7 AND s = 'cambiada'")[0]['n'] !== 92) {
        return $r ?? 'el UPDATE no llegó a todas';
    }
    // Con NULL en casi todas las partes, se recorre la tabla
    $bd->consultar('UPDATE t SET b = NULL WHERE id IN (5, 1005, 2005)');
    $recorre = $via() === 'recorrido';
    $r = $comparar();
    borrarArbol($dir);
    return $r === null && $recorre ?: ($r ?? 'no recorre');
});

echo "\n== DISTINCT sin juntar la tabla (2.9) ==\n";

chk('SELECT DISTINCT descarta las repetidas según llegan: con 16 MB de memoria contesta sobre una tabla que no cabe', function () use ($raiz) {
    $dir = "$raiz/dist";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('d', $dir);
    $bd = new Database('d', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, c TEXT, relleno TEXT)');
    $relleno = str_repeat('x', 200);
    for ($k = 0; $k < 30; $k++) {
        $v = [];
        for ($i = 0; $i < 1000; $i++) { $v[] = "('c" . ($i % 7) . "', '$relleno')"; }
        $bd->consultar('INSERT INTO t (c, relleno) VALUES ' . implode(', ', $v));
    }
    unset($bd);
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
            . ' define("JSONSQLDB_FILAS_POR_PARTE", 50);'
            . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . ' $bd = new JsonSQLDB\Database("d", ' . var_export($dir, true) . ');'
            . ' echo json_encode(array_column($bd->consultar("SELECT DISTINCT c FROM t ORDER BY c"), "c"));';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=16M -r ' . escapeshellarg($codigo) . ' 2>&1'));
    borrarArbol($dir);
    return $salida === '["c0","c1","c2","c3","c4","c5","c6"]' ?: substr($salida, 0, 300);
});

echo "\n== ORDER BY en disco cuando no cabe (2.9) ==\n";

chk('con 16 a 30 filas por parte una igualdad va por el índice, y ORDER BY … LIMIT con empates lee solo las filas que salen; lo mismo que recorriendo (2.10: se recorría la tabla, de 9 a 13 veces más lento, y se leían todas las del valor del corte)', function () use ($raiz) {
    $dir = "$raiz/coste";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('c', $dir);
    $bd = new Database('c', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER NOT NULL, s TEXT)');
    $bd->consultar('CREATE INDEX iv ON t (v)');
    $v = [];
    for ($i = 1; $i <= 2000; $i++) {
        $v[] = "($i, " . ($i % 25) . ", 's$i')";            // 2 filas de cada valor en cada parte de 50
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    $mal = [];
    foreach (['SELECT id, s FROM t WHERE v IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 10)' => 'índice (IN)', 'SELECT id, v FROM t ORDER BY v DESC LIMIT 7' => 20] as $q => $esperado) {
        $e = $bd->consultar("EXPLAIN $q")[0];
        $sin = str_replace(['WHERE v IN', 'ORDER BY v'], ['WHERE v + 0 IN', 'ORDER BY v + 0'], $q);
        if ($bd->consultar($q) !== $bd->consultar($sin) || (is_string($esperado) ? $e['acceso'] !== $esperado : $e['filas'] > 7)) {
            $mal[] = "$q: " . json_encode($e, JSON_UNESCAPED_UNICODE);
        }
    }
    borrarArbol($dir);
    return $mal === [] ?: implode(' | ', $mal);
});

chk('un ORDER BY sin LIMIT proyecta cada fila al leerla: con filas anchas y una columna en la salida, menos de la mitad de memoria y el mismo resultado (2.10: juntaba la tabla entera antes de ordenar)', function () use ($raiz) {
    $dir = "$raiz/proy";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('p', $dir);
    $bd = new Database('p', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER, r TEXT)');
    for ($k = 0; $k < 20; $k++) {
        $v = [];
        for ($i = $k * 1000 + 1; $i <= ($k + 1) * 1000; $i++) {
            $v[] = "($i, " . (($i * 7919) % 500) . ", '" . str_repeat('r', 500) . "')";
        }
        $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    }
    unset($bd);
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
            . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . ' $bd = new JsonSQLDB\Database("p", ' . var_export($dir, true) . '); $bd->consultar("SELECT id FROM t ORDER BY n DESC");'
            . ' $b = memory_get_usage(); $r = $bd->consultar("SELECT id FROM t ORDER BY n DESC");'
            . ' echo json_encode([round((memory_get_peak_usage() - $b) / 1048576), md5(json_encode($r))]);';
    $r = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'), true);
    // Lo esperado, ordenado aquí: n de mayor a menor y, en un empate, el orden de la tabla
    $ids = range(1, 20000);
    usort($ids, static fn(int $a, int $b): int => [($b * 7919) % 500, $a] <=> [($a * 7919) % 500, $b]);
    borrarArbol($dir);
    return is_array($r) && $r[0] < 16 && $r[1] === md5(json_encode(array_map(static fn(int $i): array => ['id' => $i], $ids))) ?: json_encode($r);
});

chk('una función de ventana guarda de cada fila solo las columnas que usa: con filas anchas, menos de la mitad de memoria y el mismo resultado (2.10: la fila entera, y su contexto)', function () use ($raiz) {
    $dir = "$raiz/vent";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('p', $dir);
    $bd = new Database('p', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER, r TEXT)');
    for ($k = 0; $k < 20; $k++) {
        $v = [];
        for ($i = $k * 1000 + 1; $i <= ($k + 1) * 1000; $i++) {
            $v[] = "($i, " . (($i * 7919) % 500) . ", '" . str_repeat('r', 500) . "')";
        }
        $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    }
    unset($bd);
    $q = 'SELECT id, ROW_NUMBER() OVER (ORDER BY n DESC) AS k FROM t';
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
            . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . ' $bd = new JsonSQLDB\Database("p", ' . var_export($dir, true) . '); $bd->consultar("' . $q . '");'
            . ' $b = memory_get_usage(); $r = $bd->consultar("' . $q . '");'
            . ' echo json_encode([round((memory_get_peak_usage() - $b) / 1048576), md5(json_encode($r))]);';
    $r = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'), true);
    // Lo esperado: por n de mayor a menor y, en un empate, en el orden de la tabla, numeradas
    $ids = range(1, 20000);
    usort($ids, static fn(int $a, int $b): int => [($b * 7919) % 500, $a] <=> [($a * 7919) % 500, $b]);
    borrarArbol($dir);
    return is_array($r) && $r[0] < 16 && $r[1] === md5(json_encode(array_map(static fn(int $i, int $k): array => ['id' => $i, 'k' => $k + 1], $ids, array_keys($ids))))
        ?: json_encode($r);
});

chk('un ORDER BY que no cabe en 24 MB se ordena en disco en pocos tramos y sale igual que con memoria de sobra, sin dejar temporales', function () use ($raiz) {
    $dir = "$raiz/ext";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('e', $dir);
    $bd = new Database('e', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, n INTEGER, d DOUBLE, s TEXT, relleno TEXT)');
    mt_srand(3);
    $pal = ['ana', 'Ávila', 'bea', 'Bea', 'zoe', 'ñu', 'm', ''];
    for ($k = 0; $k < 40; $k++) {
        $v = [];
        for ($i = 0; $i < 1000; $i++) {
            $v[] = '(' . (mt_rand(0, 9) ? mt_rand(-50, 50) : 'NULL') . ', ' . (mt_rand(0, 9) ? mt_rand(-999, 999) / 4 : 'NULL') . ", '"
                 . $pal[mt_rand(0, 7)] . "', '" . str_repeat('r', 120) . "')";
        }
        $bd->consultar('INSERT INTO t (n, d, s, relleno) VALUES ' . implode(', ', $v));
    }
    unset($bd);
    $consultas = [
        'SELECT id, n, s FROM t ORDER BY n DESC, s, id',          // empates, NULL, colación
        'SELECT id, d * 2 AS x FROM t ORDER BY x',                // por expresión
        'SELECT id, s FROM t ORDER BY s DESC LIMIT 50 OFFSET 30000', // un listado por páginas, hacia el final
        'SELECT * FROM t ORDER BY d',
    ];
    $correr = static function (string $memoria, string $q) use ($dir): string {
        // Las filas salen por el sumidero y se resumen en un md5: el resultado
        // no tiene que caber, solo la ordenación
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0); define("JSONSQLDB_CACHE_ACTIVA", false);'
                . ' define("JSONSQLDB_FILAS_POR_PARTE", 1000);'
                . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
                . ' $bd = new JsonSQLDB\Database("e", ' . var_export($dir, true) . '); $h = hash_init("md5"); $n = 0; $tramos = 0;'
                // Cuántos tramos había al salir la primera fila, cuando ya se han escrito todos
                . ' $r = $bd->consultarPorFilas(' . var_export($q, true) . ', [], null, function (array $f) use ($h, &$n, &$tramos) {'
                . ' $tramos = $n === 0 ? count(glob(' . var_export("$dir/e/.orden/*.tramo", true) . ') ?: []) : $tramos; hash_update($h, serialize($f)); $n++; });'
                . ' foreach (is_array($r) ? $r : [] as $f) { hash_update($h, serialize($f)); $n++; }'   // lo que no salió por el sumidero
                . ' echo $n, ":", hash_final($h), ":", count(glob(' . var_export("$dir/e/.orden/*", true) . ') ?: []), " ", $tramos;';
        return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$memoria -r " . escapeshellarg($codigo) . ' 2>&1'));
    };
    foreach ($consultas as $q) {
        $mucha = explode(' ', $correr('1G', $q))[0];
        [$poca, $tramos] = explode(' ', $correr('24M', $q)) + [1 => ''];
        // Pocos tramos: hasta la 2.9 cada 2 MB era uno (19 con SELECT *), porque
        // contaba la memoria reservada, que no baja, y la mezcla se arrastraba
        if ($mucha !== $poca || !preg_match('/^\d+:[0-9a-f]{32}:0$/', $poca) || (int)$tramos > 8) {
            borrarArbol($dir);
            return "$q: con memoria $mucha / con 24 MB $poca, $tramos tramos";
        }
    }
    borrarArbol($dir);
    return true;
});

/** Un disco que, como el de Windows, no deja borrar un fichero abierto (2.9; para la prueba de abajo) */
final class DiscoComoWindows
{
    /** @var array<string, string> */
    public static array $ficheros = [];
    /** @var array<string, int> */
    private static array $abiertos = [];
    /** @var resource|null */
    public $context;
    private string $ruta = '';
    private int $pos = 0;

    public function stream_open(string $ruta, string $modo, int $opciones, ?string &$abierta): bool
    {
        if ($modo[0] === 'w') {
            self::$ficheros[$ruta] = '';
        } elseif (!isset(self::$ficheros[$ruta])) {
            return false;
        }
        $this->ruta = $ruta;
        self::$abiertos[$ruta] = (self::$abiertos[$ruta] ?? 0) + 1;
        return true;
    }
    public function stream_write(string $datos): int { self::$ficheros[$this->ruta] .= $datos; return strlen($datos); }
    public function stream_read(int $n): string
    {
        $trozo = (string)substr(self::$ficheros[$this->ruta], $this->pos, $n);
        $this->pos += strlen($trozo);
        return $trozo;
    }
    public function stream_eof(): bool { return $this->pos >= strlen(self::$ficheros[$this->ruta]); }
    public function stream_close(): void
    {
        if (--self::$abiertos[$this->ruta] === 0) {
            unset(self::$abiertos[$this->ruta]);
        }
    }
    /** @return array<string, int>|false */
    public function url_stat(string $ruta, int $flags)
    {
        return isset(self::$ficheros[$ruta]) ? ['mode' => 0100644, 'size' => strlen(self::$ficheros[$ruta])]
            : (strpos(substr($ruta, 14), '/') === false ? ['mode' => 040755] : false);   // la carpeta
    }
    public function unlink(string $ruta): bool
    {
        if (isset(self::$abiertos[$ruta])) {
            return false;
        }
        unset(self::$ficheros[$ruta]);
        return true;
    }
}

chk('una ordenación en disco cortada por un LIMIT cierra sus tramos antes de borrarlos: en Windows, abiertos, no se podían borrar y se quedaban una hora (2.9)', function () {
    stream_wrapper_register('comowindows', DiscoComoWindows::class);
    $o = new JsonSQLDB\OrdenExterno([['dir' => 'ASC']], 'comowindows://orden');
    foreach ([[3, 1, 5], [6, 2, 4]] as $tramo) {
        foreach ($tramo as $v) {
            $o->anadir([$v], ['v' => $v]);
        }
        $o->volcar();                                   // cada lista, un tramo
    }
    $tramos = count(DiscoComoWindows::$ficheros);
    $primeras = array_column(iterator_to_array($o->filas(0, 2), false), 'v');
    $quedan = array_keys(DiscoComoWindows::$ficheros);
    stream_wrapper_unregister('comowindows');
    return $tramos === 2 && $primeras === [1, 2] && $quedan === [] ?: json_encode([$tramos, $primeras, $quedan]);
});

echo "\n== Lo demás que no cabe, por el disco (2.9) ==\n";

chk('JOIN, GROUP BY, subconsultas del FROM, UNION, IN, EXISTS, WITH, ventanas y COUNT(DISTINCT) que no caben en 24 MB salen por el disco igual que con memoria de sobra, sin dejar temporales; lo que ni así cabe, con un error y no con el fatal', function () use ($raiz) {
    $dir = "$raiz/disco";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('d', $dir);
    $bd = new Database('d', $dir);
    $bd->consultar('CREATE TABLE a (id INTEGER PRIMARY KEY, bid INTEGER, g INTEGER, t TEXT, v DOUBLE)');
    $bd->consultar('CREATE TABLE b (id INTEGER PRIMARY KEY, k INTEGER, n TEXT)');
    mt_srand(7);
    for ($lote = 0; $lote < 60; $lote++) {
        $va = $vb = [];
        for ($i = 1; $i <= 1000; $i++) {
            $id   = $lote * 1000 + $i;
            $va[] = "($id, " . (mt_rand(0, 20) === 0 ? 'NULL' : mt_rand(1, 70000)) . ', ' . mt_rand(1, 40000) . ", 't" . mt_rand(1, 50000) . "', " . (mt_rand(0, 10000) / 100) . ')';
            $vb[] = "($id, " . mt_rand(1, 9) . ", 'n" . ($id % 30000) . "')";
        }
        $bd->consultar('INSERT INTO a VALUES ' . implode(', ', $va));
        $bd->consultar('INSERT INTO b VALUES ' . implode(', ', $vb));
    }
    unset($bd);
    $consultas = [
        'SELECT a.id, b.n FROM a JOIN b ON b.id = a.bid',
        'SELECT a.id, b.k FROM a LEFT JOIN b ON b.id = a.bid ORDER BY a.id',
        'SELECT g, COUNT(*) AS c, SUM(v) AS s FROM a GROUP BY g ORDER BY g',
        'SELECT COUNT(*) AS n FROM (SELECT DISTINCT t FROM a)',
        'SELECT COUNT(*) AS n FROM (SELECT t FROM a UNION SELECT n FROM b)',
        'SELECT COUNT(*) AS n, SUM(x) AS s FROM (SELECT bid AS x FROM a UNION ALL SELECT id FROM b)',
        'SELECT t FROM a UNION SELECT n FROM b',
        'SELECT COUNT(*) AS n FROM a WHERE bid IN (SELECT id FROM b WHERE k > 3)',
        'SELECT COUNT(*) AS n FROM a WHERE EXISTS (SELECT 1 FROM b WHERE b.id = a.bid AND b.k > 3)',
        'SELECT id, ROW_NUMBER() OVER (ORDER BY v DESC, id) AS r FROM a ORDER BY id',
        'SELECT id, LAG(t) OVER (PARTITION BY g % 10 ORDER BY id) AS l FROM a ORDER BY id',
        'WITH x AS (SELECT bid, COUNT(*) AS c FROM a GROUP BY bid) SELECT COUNT(*) AS n, MAX(c) AS m FROM x',
        'SELECT COUNT(DISTINCT t) AS n, COUNT(DISTINCT bid) AS m, SUM(DISTINCT g) AS s, COUNT(DISTINCT t || bid) AS x FROM a',
    ];
    $correr = static function (string $memoria, string $q) use ($dir): string {
        // Las filas salen por el sumidero y se resumen: en orden si hay ORDER
        // BY; si no, con una suma que no depende del orden
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0); define("JSONSQLDB_CACHE_ACTIVA", false);'
                . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
                . ' $bd = new JsonSQLDB\Database("d", ' . var_export($dir, true) . '); $h = hash_init("md5"); $s = 0; $n = 0;'
                . ' $enOrden = ' . var_export(stripos($q, 'ORDER BY') !== false, true) . ';'
                . ' $anotar = function (array $f) use ($h, &$s, &$n, $enOrden) {'
                . ' $l = serialize(array_map(fn($v) => is_float($v) ? round($v, 6) : $v, array_values($f)));'
                . ' if ($enOrden) { hash_update($h, $l); } else { $s = ($s + hexdec(substr(md5($l), 0, 14))) % (1 << 60); } $n++; };'
                . ' try { $r = $bd->consultarPorFilas(' . var_export($q, true) . ', [], null, $anotar);'
                . ' foreach (is_array($r) ? $r : [] as $f) { $anotar($f); }'   // lo que no salió por el sumidero
                . ' $res = $n . ":" . ($enOrden ? hash_final($h) : $s); } catch (JsonSQLDB\\JsonSqlDbError $e) { $res = $e->sqlState; }'
                . ' echo $res, ":", count(glob(' . var_export("$dir/d/.orden/*", true) . ') ?: []);';
        return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$memoria -r " . escapeshellarg($codigo) . ' 2>&1'));
    };
    // Y lo que ni así cabe se corta con un error, no con el fatal de PHP: lo
    // que el intento en memoria dejó pedido se devuelve antes de repetir
    $justas = [
        'SELECT id, ROW_NUMBER() OVER (ORDER BY v DESC, id) AS r, LAG(t) OVER (PARTITION BY g % 10 ORDER BY id) AS l FROM a ORDER BY id',
        'SELECT id, FIRST_VALUE(t) OVER (PARTITION BY g % 3 ORDER BY v DESC, id) AS f, CUME_DIST() OVER (ORDER BY g) AS c FROM a ORDER BY id',
    ];
    foreach (array_merge($consultas, $justas) as $q) {
        // Antes de PHP 8.2 una lista de enteros ocupa el doble: con 60.000
        // filas, las ventanas necesitan allí 32 MB
        $limite = PHP_VERSION_ID < 80200 && str_contains($q, ' OVER ') ? '32M' : '24M';
        $mucha = $correr('1G', $q);
        $poca  = $correr($limite, $q);
        if (!preg_match('/^\d+:\w+:0$/', $mucha) || ($poca !== $mucha && !(in_array($q, $justas, true) && $poca === 'MEMORIA:0'))) {
            borrarArbol($dir);
            return "$q: con memoria $mucha / con $limite " . substr($poca, 0, 300);
        }
    }
    borrarArbol($dir);
    return true;
});

chk("un rango sobre una columna de texto con textos que son números ('00012') va por su índice como texto, también con un número escrito (5990 es '5990'), y da lo mismo que recorriendo (2.10)", function () use ($raiz) {
    $dir = "$raiz/rangotexto";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('r', $dir);
    $bd = new Database('r', $dir);
    $bd->consultar('CREATE TABLE g (id INTEGER PRIMARY KEY, s TEXT)');
    $v = [];
    for ($i = 1; $i <= 6000; $i++) {
        $v[] = "($i, '" . sprintf('%05d', $i) . "')";
    }
    $bd->consultar('INSERT INTO g VALUES ' . implode(',', $v));
    $bd->consultar('CREATE INDEX g_s ON g (s)');
    $mal = [];
    foreach (["SELECT id FROM g WHERE s > '05990'" => 10, 'SELECT id FROM g WHERE s >= 5990' => 0, "SELECT id FROM g WHERE s BETWEEN '00010' AND '00020'" => 11, 'SELECT id FROM g WHERE s BETWEEN 10 AND 20' => 0,
              "SELECT id FROM g WHERE s < '00005'" => 4] as $q => $n) {
        $r   = array_column($bd->consultar($q), 'id');
        $sin = array_column($bd->consultar(str_replace('WHERE s ', 'WHERE CAST(s AS TEXT) ', $q)), 'id');
        $via = $bd->consultar("EXPLAIN $q")[0]['acceso'];
        if (count($r) !== $n || $r !== $sin || $via !== 'índice, comparación') { $mal[] = "$q: " . count($r) . " filas, $via"; }
    }
    borrarArbol($dir);
    return $mal === [] ?: implode(' | ', $mal);
});

chk('un índice de texto con las claves de antes de la 2.10 no se usa hasta rehacerlo: sin «textos» en el estado (una base de la 2.9) o con un trozo reescrito después por una versión anterior, la consulta da sus filas leyendo la tabla, CHECK INDEXES lo dice y queda rehecho; «textos» (la revisión hasta la que están las claves de la 2.10) va solo en los índices con una columna de texto, y una escritura que no toca sus trozos lo deja válido (2.10)', function () use ($raiz) {
    $dir = "$raiz/huella";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('h', $dir);
    $abrir = static fn(): Database => new Database('h', $dir);
    $bd    = $abrir();
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, c TEXT, n INTEGER)');
    $bd->consultar('CREATE INDEX ix_c ON t (c)');
    $bd->consultar('CREATE INDEX ix_n ON t (n)');
    $v = [];
    for ($i = 1; $i <= 150; $i++) {
        $v[] = "($i, '" . ['007', '7', '7.0', 'abc'][$i % 4] . "', $i)";
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    $estado = static fn(): array => json_decode((string)file_get_contents("$dir/h/t.rev.json"), true);
    $mal    = [];
    // «textos» dice hasta qué revisión están las claves como en la 2.10: vale si
    // no pasa de la de la tabla y ningún trozo es de después
    $vale = static fn(array $e): bool => is_int($e['textos']['ix_c'] ?? null) && $e['textos']['ix_c'] <= $e['rev']
        && $e['textos']['ix_c'] >= max($e['indexes']['ix_c']);
    $e = $estado();
    if (array_keys($e['textos'] ?? []) !== ['ix_c'] || $e['textos']['ix_c'] !== $e['rev'] || !$vale($e)) { $mal[] = 'la marca al escribir: ' . json_encode($e['textos'] ?? null); }
    $bd->consultar('UPDATE t SET n = n + 1000 WHERE id = 5');
    $f = $estado();
    if ($f['indexes']['ix_c'] !== $e['indexes']['ix_c'] || !$vale($f)) { $mal[] = 'la marca tras un UPDATE que no toca ix_c'; }
    $bien = array_column($bd->consultar("SELECT id FROM t WHERE c = '007' ORDER BY id"), 'id');
    unset($bd);
    // Las claves de antes: un texto que es un número, a la clave de ese número
    $aLaAntigua = static function () use ($dir): void {
        foreach ((array)glob("$dir/h/t.idx.ix_c*.json") as $fichero) {
            $trozo = json_decode((string)file_get_contents($fichero), true);
            $keys  = [];
            foreach ($trozo['keys'] as $k => $pos) {
                $valor = substr((string)$k, strpos((string)$k, ':') + 1);
                $k     = is_numeric($valor) ? 'n' . strlen((string)($valor + 0)) . ':' . ($valor + 0) : $k;
                $keys[$k] = array_merge((array)($keys[$k] ?? []), (array)$pos);
            }
            $trozo['keys'] = $keys;
            file_put_contents($fichero, json_encode($trozo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    };
    $probar = static function (string $caso) use ($abrir, $bien, $estado, $vale, &$mal): void {
        $bd = $abrir();
        $antes = array_column($bd->consultar('CHECK INDEXES FROM t'), 'problema');
        // Con un comentario propio, para que no la sirva la caché de
        // resultados: la consulta tiene que correr para que el índice se rehaga
        $filas = array_column($bd->consultar("SELECT id FROM t WHERE c = '007' ORDER BY id -- $caso"), 'id');
        $tras  = $bd->consultar('CHECK INDEXES FROM t');
        $via   = $bd->consultar("EXPLAIN SELECT id FROM t WHERE c = '007'")[0]['acceso'];
        $e     = $estado();
        if (count($antes) !== 1 || !str_contains($antes[0], 'antes de la 2.10') || $filas !== $bien || $tras !== [] || !str_starts_with($via, 'índice')
            || !$vale($e)) {
            $mal[] = "$caso: " . json_encode([$antes, count($filas), $tras, $via], JSON_UNESCAPED_UNICODE);
        }
    };
    // Una base de la 2.9: sin «textos»
    $aLaAntigua();
    $e = $estado();
    unset($e['textos']);
    file_put_contents("$dir/h/t.rev.json", json_encode($e));
    $probar('sin textos');
    // Con «textos», y un trozo reescrito por la 2.9 (otra revisión, y «textos» sin tocar)
    $aLaAntigua();
    $e = $estado();
    $r = (int)$e['rev'] + 1;
    $e['rev'] = $e['indexes']['ix_c'][1] = $r;
    $trozo = json_decode((string)file_get_contents("$dir/h/t.idx.ix_c.part2.json"), true);
    $trozo['rev'] = $r;
    file_put_contents("$dir/h/t.idx.ix_c.part2.json", json_encode($trozo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    file_put_contents("$dir/h/t.rev.json", json_encode($e));
    $probar('un trozo de otra versión');
    borrarArbol($dir);
    return $mal === [] ?: implode(' | ', $mal);
});

chk('un índice con una columna decimal y las claves de antes de la 2.10 (un decimal entero de 9·10^18 a 2^63, con clave de decimal) no se usa hasta rehacerlo: sin «textos» en el estado, la consulta da sus filas leyendo la tabla, CHECK INDEXES lo dice y queda rehecho; uno sobre una columna INTEGER no lleva «textos» y vale (2.10)', function () use ($raiz) {
    $dir = "$raiz/huellad";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('h', $dir);
    $bd = new Database('h', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, r REAL, n INTEGER)');
    $bd->consultar('CREATE INDEX ix_r ON t (r)');
    $bd->consultar('CREATE INDEX ix_n ON t (n)');
    $v = [];
    for ($i = 1; $i <= 150; $i++) {
        $v[] = "($i, " . ($i % 3 === 0 ? '9.1e18' : "$i.5") . ", $i)";
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    unset($bd);
    // Las claves de la 2.9: ese decimal, a una de decimal
    foreach ((array)glob("$dir/h/t.idx.ix_r*.json") as $fichero) {
        $trozo = json_decode((string)file_get_contents($fichero), true);
        $keys  = [];
        foreach ($trozo['keys'] as $k => $pos) {
            $keys[$k === 'n19:9100000000000000000' ? 'n7:9.1E+18' : $k] = $pos;
        }
        $trozo['keys'] = $keys;
        file_put_contents($fichero, json_encode($trozo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $e      = json_decode((string)file_get_contents("$dir/h/t.rev.json"), true);
    $textos = array_keys($e['textos'] ?? []);
    unset($e['textos']);
    file_put_contents("$dir/h/t.rev.json", json_encode($e));
    $bd    = new Database('h', $dir);
    $antes = array_column($bd->consultar('CHECK INDEXES FROM t'), 'indice');
    $filas = count($bd->consultar('SELECT id FROM t WHERE r = 9100000000000000000'));
    $tras  = $bd->consultar('CHECK INDEXES FROM t');
    $via   = $bd->consultar('EXPLAIN SELECT id FROM t WHERE r = 9.1e18')[0]['acceso'];
    $otra  = count($bd->consultar('SELECT id FROM t WHERE r = 9.1e18'));
    unset($bd);
    borrarArbol($dir);
    return $textos === ['ix_r'] && $antes === ['ix_r'] && $filas === 50 && $tras === [] && str_starts_with($via, 'índice') && $otra === 50
        ?: json_encode([$textos, $antes, $filas, $tras, $via, $otra], JSON_UNESCAPED_UNICODE);
});

chk('una búsqueda numérica por un índice lee los trozos de rango desconocido, y uno vacío (solo NULL) no da avisos de PHP (2.10: el rango de cada trozo se mira sin llamar a cabeEnRango())', function () use ($raiz) {
    $dir = "$raiz/rangos";
    borrarArbol($dir);
    @mkdir($dir, 0775, true);
    Database::crear('r', $dir);
    $bd = new Database('r', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER)');
    // La parte 2 toda NULL: su trozo no tiene claves, y su rango es []
    $filas = [];
    for ($i = 1; $i <= 200; $i++) {
        $filas[] = '(' . $i . ', ' . ($i > 50 && $i <= 100 ? 'NULL' : $i) . ')';
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $filas));
    $bd->consultar('CREATE INDEX i_v ON t (v)');
    unset($bd);
    // El rango del trozo 1, desconocido (null): hay que leerlo
    $e = json_decode((string)file_get_contents("$dir/r/t.rev.json"), true);
    $vacio = $e['rangos']['i_v'][1] ?? null;
    $e['rangos']['i_v'][0] = null;
    file_put_contents("$dir/r/t.rev.json", json_encode($e));
    $bd = new Database('r', $dir);                 // sin el índice ya en memoria
    $avisos = [];
    set_error_handler(static function (int $n, string $m) use (&$avisos): bool { $avisos[] = $m; return true; });
    try {
        $uno  = $bd->consultar('SELECT id FROM t WHERE v = 5');
        $tres = $bd->consultar('SELECT id FROM t WHERE v IN (180, 5, 50) ORDER BY id');
        $via  = $bd->consultar('EXPLAIN SELECT id FROM t WHERE v IN (180, 5, 50)')[0]['acceso'] ?? '';
    } finally {
        restore_error_handler();
    }
    unset($bd);
    borrarArbol($dir);
    return $vacio === [] && $uno === [['id' => 5]] && $tres === [['id' => 5], ['id' => 50], ['id' => 180]] && str_starts_with($via, 'índice') && $avisos === []
        ?: json_encode([$vacio, $uno, $tres, $via, $avisos], JSON_UNESCAPED_UNICODE);
});

// Sin dejar nada en la carpeta temporal del sistema
borrarArbol($raiz);

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
