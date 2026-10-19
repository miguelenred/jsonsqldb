<?php
declare(strict_types=1);

/**
 * Prueba de escrituras y DDL. Ejecutar: php tests/f3_escrituras.php
 * Crea una base temporal y la borra al terminar.
 *
 * https://miguelenred.es/jsonsqldb
 */
// Las pruebas usan el motor directamente, sin pasar por la API
define('JSONSQLDB_CONEXION_DIRECTA', true);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Storage;
use JsonSQLDB\StorageCambios;
use JsonSQLDB\StorageLectura;
use JsonSQLDB\StorageMantenimiento;
use JsonSQLDB\StorageEntera;
use JsonSQLDB\StorageEscritura;
use JsonSQLDB\Catalog;
use JsonSQLDB\Indexes;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f3';
$base = 'gestion';
$ok = 0; $ko = 0;

function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
        if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
        elseif ($r === null) { echo "  --   $titulo (se salta: falta la extensión)\n"; }
        else { $ko++; echo "  FALLO $titulo -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        global $ko; $ko++;
        echo "  FALLO $titulo -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function error(string $titulo, string $estado, string $sql): void {
    global $bd;
    chk($titulo, static function () use ($estado, $sql, $bd) {
        try { $bd->consultar($sql); } catch (JsonSqlDbError $e) { return $e->sqlState === $estado ?: $e->sqlState . ': ' . $e->getMessage(); }
        return 'no lanzó error';
    });
}

/** Primer valor de la primera fila de una consulta. */
function uno(string $sql) {
    global $bd;
    $f = $bd->consultar($sql);
    return $f === [] ? null : reset($f[0]);
}

exec('rm -rf ' . escapeshellarg($raiz));        // lo que haya dejado una ejecución cortada a medias
@mkdir($raiz, 0775, true);
Database::crear($base, $raiz);
$bd = new Database($base, $raiz);

echo "\n== CREATE TABLE ==\n";
chk('tabla con PK autoincremental, UNIQUE, NOT NULL y DEFAULT', function () use ($bd) {
    $r = $bd->consultar("
        CREATE TABLE clientes (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre  VARCHAR(50)   NOT NULL,
            email   VARCHAR(120)  UNIQUE,
            saldo   DECIMAL(10,2) DEFAULT 0,
            ciudad  TEXT          DEFAULT 'Malaga',
            alta    DATETIME
        )
    ");
    return $r['success'] === true && str_contains($r['mensaje'], 'creada');
});
chk('tabla con FK, ON DELETE CASCADE y UNIQUE compuesto', function () use ($bd) {
    $bd->consultar("
        CREATE TABLE pedidos (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id  INTEGER NOT NULL,
            referencia  VARCHAR(20) NOT NULL,
            total       DECIMAL(10,2) NOT NULL DEFAULT 0,
            fecha       DATETIME,
            CONSTRAINT uq_ref UNIQUE (cliente_id, referencia),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE ON UPDATE CASCADE
        )
    ");
    $meta = $bd->catalogo()->meta('pedidos');
    return $meta['foreign_keys'][0]['on_delete'] === 'CASCADE'
        && $meta['foreign_keys'][0]['on_update'] === 'CASCADE'
        && $meta['unique'][0]['name'] === 'uq_ref'
        && $meta['unique'][0]['columns'] === ['cliente_id','referencia'];
});
chk('REFERENCES en línea y PRIMARY KEY de tabla', function () use ($bd) {
    $bd->consultar("
        CREATE TABLE lineas (
            pedido_id INTEGER REFERENCES pedidos(id) ON DELETE CASCADE,
            linea     INTEGER,
            concepto  TEXT,
            PRIMARY KEY (pedido_id, linea)
        )
    ");
    $meta = $bd->catalogo()->meta('lineas');
    return JsonSQLDB\Catalog::clavePrimaria($meta) === ['pedido_id','linea']
        && $meta['foreign_keys'][0]['table'] === 'pedidos';
});
chk('IF NOT EXISTS', function () use ($bd) {
    $r = $bd->consultar('CREATE TABLE IF NOT EXISTS clientes (id INTEGER)');
    return str_contains($r['mensaje'], 'ya existía');
});
error('tabla duplicada', 'SCHEMA', 'CREATE TABLE clientes (id INTEGER)');
error('CHECK con subconsulta: no (2.9: CHECK sí se admite)', 'SYNTAX', 'CREATE TABLE t (a INTEGER CHECK (a > (SELECT 1)))');
error('tipo desconocido', 'TYPE', 'CREATE TABLE t (a GEOMETRY)');

echo "\n== INSERT ==\n";
chk('INSERT con autoincremento y valores por defecto', function () use ($bd) {
    $r = $bd->consultar("INSERT INTO clientes (nombre, email) VALUES ('Ana', 'ana@x.es')");
    $f = $bd->consultar('SELECT id, nombre, saldo, ciudad FROM clientes');
    return $r['filas'] === 1 && $f[0]['id'] === 1 && $f[0]['saldo'] === 0.0 && $f[0]['ciudad'] === 'Malaga';
});
chk('INSERT múltiple', function () use ($bd) {
    $r = $bd->consultar("INSERT INTO clientes (nombre, email, ciudad) VALUES
        ('Luis', 'luis@x.es', 'Madrid'),
        ('María', 'maria@x.es', 'Valencia'),
        ('Pedro', NULL, 'Madrid')");
    return $r['filas'] === 3 && uno('SELECT COUNT(*) FROM clientes') === 4;
});
chk('INSERT sin lista de columnas', function () use ($bd) {
    $bd->consultar("INSERT INTO clientes VALUES (100, 'Marta', 'marta@x.es', 25.5, 'Alicante', '2026-05-01')");
    return uno("SELECT saldo FROM clientes WHERE id = 100") === 25.5;
});
chk('el autoincremento continúa tras un id explícito mayor', function () use ($bd) {
    $bd->consultar("INSERT INTO clientes (nombre) VALUES ('Óscar')");
    return uno("SELECT id FROM clientes WHERE nombre = 'Óscar'") === 101;
});
chk('un DELETE deja un hueco (null) en su sitio: cada parte queda como la escribiría la escritura completa y los índices cuadran (2.9)', function () use ($raiz) {
    // Al borrar, las filas de detrás no se mueven: la borrada se queda como
    // null en su línea. Cada parte tiene que quedar byte a byte como la
    // escribiría la escritura completa con esas filas (y esos huecos), y cada
    // índice igual que si se rehiciera de las filas
    $dir = "$raiz/bloques";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/e")) { Database::borrar('e', $dir); }
    Database::crear('e', $dir);
    $bd = new Database('e', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, s VARCHAR(80) UNIQUE, d DOUBLE, n INTEGER)');
    $bd->consultar('CREATE INDEX ix_n ON t (n)');
    $raros = ["con \"comillas\"", "barra \\ y /", "salto\nde línea", "acentos áéíóú ñ", "emoji 😀", 'normal'];
    $vals = [];
    for ($i = 0; $i < 2600; $i++) { $vals[] = [$raros[$i % 6] . " $i", $i % 5 === 0 ? null : $i / 3, $i % 7 === 0 ? null : $i % 40]; }
    foreach (array_chunk($vals, 500) as $b) {
        $bd->consultar('INSERT INTO t (s, d, n) VALUES ' . implode(',', array_fill(0, count($b), '(?,?,?)')), array_merge(...$b));
    }
    $quedan = 2600;
    foreach (['DELETE FROM t WHERE id = 3', 'DELETE FROM t WHERE id IN (1200, 1201, 2599)', "DELETE FROM t WHERE s = 'normal 5'",
              'DELETE FROM t WHERE id = 2600', 'DELETE FROM t WHERE id BETWEEN 100 AND 150'] as $q) {
        $quedan -= $bd->consultar($q)['filas'];
    }
    foreach (glob("$dir/e/t*.json") as $f) {
        if (!preg_match('/\/t(\.part\d+)?\.json$/', $f)) { continue; }
        $texto = (string)file_get_contents($f);
        $filas = json_decode($texto, true)['rows'];
        $esperado = "{\n  \"table\": \"t\",\n  \"rows\": [";
        $desf = [];
        $sep  = "\n    ";
        foreach ($filas as $fila) {
            $desf[] = strlen($esperado) + strlen($sep);
            $esperado .= $sep . json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $sep = ",\n    ";
        }
        $esperado .= $filas === [] ? "],\n" : "\n  ],\n";
        $aqui = strlen($esperado);
        $esperado .= '  "offsets": [' . implode(',', $desf) . "],\n  \"offsets_at\": $aqui\n}\n";
        if ($texto !== $esperado) { return basename($f) . ' no es lo que escribiría la escritura completa'; }
    }
    // Cada índice, como si se rehiciera: las mismas filas por el índice que recorriendo
    $todas = $bd->consultar('SELECT * FROM t ORDER BY id');
    foreach ([12, 39, 0] as $n) {
        if ($bd->consultar("SELECT id FROM t WHERE n = $n ORDER BY id") !== $bd->consultar("SELECT id FROM t WHERE n + 0 = $n ORDER BY id")) {
            return "el índice de n no cuadra con $n";
        }
    }
    foreach ([$todas[0], $todas[700], $todas[count($todas) - 1]] as $f) {
        if (($bd->consultar('SELECT id FROM t WHERE s = ?', [$f['s']])[0]['id'] ?? null) !== $f['id']) {
            return "el índice único no encuentra la fila {$f['id']}";
        }
    }
    // De los 57 borrados, 55 son huecos: nada se ha compactado (no llegan al
    // 25 %), y los dos del final (2599 y 2600) no dejan hueco: la tabla se acorta
    $huecos = array_sum((array)(json_decode((string)file_get_contents("$dir/e/t.rev.json"), true)['huecos'] ?? []));
    unset($bd);
    Database::borrar('e', $dir);
    @rmdir($dir);
    if ($huecos !== 55) { return "huecos: $huecos, se esperaban 55"; }
    return count($todas) === $quedan && $quedan === 2543 ?: 'quedan ' . count($todas) . " filas, se esperaban $quedan";
});

chk('ALTER TABLE ADD, DROP y RENAME COLUMN por bloques de texto dejan los mismos datos que fila a fila (2.8)', function () use ($raiz) {
    $dir = "$raiz/alter";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/e")) { Database::borrar('e', $dir); }
    Database::crear('e', $dir);
    $bd = new Database('e', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT UNIQUE, d DOUBLE, n INTEGER)');
    $raros = ["con \"comillas\" y \"s\":1", "barra \\ y /", "salto\nde línea", "emoji 😀 ñ", ''];
    $vals = [];
    for ($i = 1; $i <= 2300; $i++) { $vals[] = [$i, $raros[$i % 5] . " $i", $i % 4 === 0 ? null : $i / 4, $i % 3 === 0 ? null : $i]; }
    foreach (array_chunk($vals, 500) as $b) {
        $bd->consultar('INSERT INTO t VALUES ' . implode(',', array_fill(0, count($b), '(?,?,?,?)')), array_merge(...$b));
    }
    $esperado = [];
    foreach ($vals as [$id, $sv, $dv]) { $esperado[] = ['id' => $id, 'texto' => $sv, 'd' => $dv, 'nuevo' => 'x "y"']; }
    $antes = StorageMantenimiento::cuentaBloques();
    foreach (["ALTER TABLE t ADD COLUMN nuevo TEXT DEFAULT 'x \"y\"'", 'ALTER TABLE t DROP COLUMN n', 'ALTER TABLE t RENAME COLUMN s TO texto'] as $q) {
        $bd->consultar($q);
    }
    $porBloques = StorageMantenimiento::cuentaBloques() - $antes;
    $filas = $bd->consultar('SELECT * FROM t ORDER BY id');
    $porIndice = $bd->consultar('SELECT id FROM t WHERE texto = ?', [$raros[2] . ' 1502'])[0]['id'] ?? null;
    unset($bd);
    Database::borrar('e', $dir);
    @rmdir($dir);
    if ($porBloques !== 3) { return "por bloques: $porBloques de 3"; }
    return $filas === $esperado && $porIndice === 1502 ?: 'los datos no son los esperados: ' . json_encode([$filas[0] ?? null, $porIndice], JSON_UNESCAPED_UNICODE);
});

chk('empalmar filas en una parte deja el mismo fichero que escribirla entera', function () use ($raiz) {
    // Un INSERT añade sus filas al texto de la última parte y un UPDATE cambia
    // sus líneas, sin decodificar ni recodificar las demás. El fichero tiene
    // que quedar byte a byte como si se hubiera escrito entero, con los
    // desfases apuntando a cada fila. Valores con comillas, barras, saltos de
    // línea, acentos, emojis, NULL y decimales
    $dir = "$raiz/empalme";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/e")) { Database::borrar('e', $dir); }   // de una ejecución anterior interrumpida
    Database::crear('e', $dir);
    $bd = new Database('e', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, s VARCHAR(80), d DOUBLE, n INTEGER)');
    $raros = ["con \"comillas\"", "barra \\ y /", "salto\nde línea", "acentos áéíóú ñ", "emoji 😀", '', 'normal'];
    $vals = [];
    for ($i = 0; $i < 1500; $i++) { $vals[] = [$raros[$i % 7], $i % 5 === 0 ? null : $i / 3, $i]; }
    foreach (array_chunk($vals, 500) as $b) {
        $bd->consultar('INSERT INTO t (s, d, n) VALUES ' . implode(',', array_fill(0, count($b), '(?,?,?)')), array_merge(...$b));
    }
    for ($i = 0; $i < 20; $i++) {
        $bd->consultar('INSERT INTO t (s, d, n) VALUES (?, ?, ?)', [$raros[$i % 7] . " $i", $i === 3 ? null : 1.5, $i]);
        $bd->consultar('UPDATE t SET s = ?, d = ? WHERE id = ?', [$raros[($i + 3) % 7], $i % 2 ? null : 0.25, 1 + $i * 71]);
    }
    foreach (glob("$dir/e/t*.json") as $f) {
        if (!preg_match('/\/t(\.part\d+)?\.json$/', $f)) { continue; }
        $texto = (string)file_get_contents($f);
        $filas = json_decode($texto, true)['rows'];
        $esperado = "{\n  \"table\": \"t\",\n  \"rows\": [";
        $desf = [];
        $sep  = "\n    ";
        foreach ($filas as $fila) {
            $desf[] = strlen($esperado) + strlen($sep);
            $esperado .= $sep . json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $sep = ",\n    ";
        }
        $esperado .= $filas === [] ? "],\n" : "\n  ],\n";
        $aqui = strlen($esperado);
        $esperado .= '  "offsets": [' . implode(',', $desf) . "],\n  \"offsets_at\": $aqui\n}\n";
        if ($texto !== $esperado) { return basename($f) . ' no es lo que escribiría la escritura completa'; }
    }
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
    $f71 = $bd->consultar('SELECT s FROM t WHERE id = 72')[0]['s'];
    unset($bd);
    Database::borrar('e', $dir);
    @rmdir($dir);
    return $n === 1520 && $f71 === $raros[4] ?: "filas: $n, id 72: " . var_export($f71, true);
});
chk('cientos de escrituras al azar dejan tabla, índices y partes como un modelo en memoria', function () use ($raiz) {
    // En otro proceso, con partes de 7 y de 50 filas para que las escrituras
    // crucen sus bordes todo el rato (ver tests/_azar_escrituras.php)
    foreach ([[11, 7], [12, 50]] as [$semilla, $parte]) {
        $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_azar_escrituras.php')
            . " $semilla $parte 300 " . escapeshellarg("$raiz/azar") . ' 2>&1');
        if (!str_contains($salida, 'todo coincide')) { return trim($salida); }
    }
    @rmdir("$raiz/azar");
    return true;
});
chk('una subconsulta que no mira fuera se ejecuta una vez por sentencia, no por fila', function () use ($bd) {
    // UPDATE … WHERE x > (SELECT AVG(x) …) sobre 5.000 filas repetía el AVG
    // 5.000 veces: diez segundos. Ahora, una sola: menos de uno
    $bd->consultar('CREATE TABLE sq (id INTEGER PRIMARY KEY, x INTEGER)');
    $v = [];
    for ($i = 1; $i <= 5000; $i++) { $v[] = "($i, " . ($i % 100) . ')'; }
    $bd->consultar('INSERT INTO sq VALUES ' . implode(',', $v));
    $t = microtime(true);
    $n = $bd->consultar('UPDATE sq SET x = x + 1000 WHERE x > (SELECT AVG(x) FROM sq)')['filas'];
    $ms = (microtime(true) - $t) * 1000;
    $bd->consultar('DROP TABLE sq');
    return $n === 2500 && $ms < 3000 ?: sprintf('%d filas en %.0f ms', $n, $ms);
});
chk('un entero fuera de rango se rechaza, no se convierte en otro número', function () use ($bd) {
    // PHP convierte "9223372036854775808" en el máximo entero sin avisar: el
    // motor tiene que dar un error en vez de guardar otro número
    $bd->consultar('CREATE TABLE enteros (id INTEGER PRIMARY KEY, n INTEGER)');
    $bd->consultar('INSERT INTO enteros VALUES (1, ?)', ['9223372036854775807']);
    $bd->consultar('INSERT INTO enteros VALUES (2, ?)', ['-9223372036854775808']);
    foreach (['9223372036854775808', '-9223372036854775809', 1.0e19] as $i => $v) {
        try {
            $bd->consultar('INSERT INTO enteros VALUES (?, ?)', [10 + $i, $v]);
            $bd->consultar('DROP TABLE enteros');
            return 'aceptó ' . var_export($v, true);
        } catch (JsonSqlDbError $e) {
            if (!str_contains($e->getMessage(), 'fuera de rango')) { return $e->getMessage(); }
        }
    }
    $ok = $bd->consultar('SELECT n FROM enteros ORDER BY id') === [['n' => PHP_INT_MAX], ['n' => PHP_INT_MIN]];
    $bd->consultar('DROP TABLE enteros');
    return $ok ?: 'los extremos no se guardaron exactos';
});
chk('el contador de autoincremento vive en rev.json y no reescribe la estructura', function () use ($bd, $raiz, $base) {
    // Desde la 2.7 un INSERT no toca meta.json por mover el contador: va a
    // rev.json, que se escribe de todas formas. meta.json conserva el valor
    // de la creación, y al leer vale el mayor de los dos.
    $meta = (string)file_get_contents("$raiz/$base/clientes.meta.json");
    $bd->consultar("INSERT INTO clientes (nombre) VALUES ('Contador')");
    if ((string)file_get_contents("$raiz/$base/clientes.meta.json") !== $meta) { return 'el INSERT reescribió meta.json'; }
    $rev = json_decode((string)file_get_contents("$raiz/$base/clientes.rev.json"), true);
    if (($rev['autoinc'] ?? null) !== 103) { return 'rev.json no lleva el contador: ' . json_encode($rev['autoinc'] ?? null); }
    // Otra conexión lo ve, y sigue la secuencia
    $otro = new Database($base, $raiz);
    $otro->consultar("INSERT INTO clientes (nombre) VALUES ('Siguiente')");
    return uno("SELECT id FROM clientes WHERE nombre = 'Siguiente'") === 103 ?: 'la secuencia no continuó desde rev.json';
});
chk('INSERT con expresión y DEFAULT', function () use ($bd) {
    $bd->consultar("INSERT INTO clientes (nombre, saldo, ciudad) VALUES ('Sara', 10 * 3 + 0.5, DEFAULT)");
    $f = $bd->consultar("SELECT saldo, ciudad FROM clientes WHERE nombre = 'Sara'");
    return $f[0]['saldo'] === 30.5 && $f[0]['ciudad'] === 'Malaga';
});
chk('INSERT ... SELECT', function () use ($bd) {
    $bd->consultar("CREATE TABLE copia (nombre TEXT, ciudad TEXT)");
    $r = $bd->consultar("INSERT INTO copia (nombre, ciudad) SELECT nombre, ciudad FROM clientes WHERE ciudad = 'Madrid'");
    return $r['filas'] === 2 && uno('SELECT COUNT(*) FROM copia') === 2;
});
chk('conversión de tipos al insertar', function () use ($bd) {
    $bd->consultar("INSERT INTO clientes (nombre, saldo, alta) VALUES ('Tipos', '12,00' + 0, '2026-03-04T10:20:30.5')");
    $f = $bd->consultar("SELECT saldo, alta FROM clientes WHERE nombre = 'Tipos'");
    return $f[0]['saldo'] === 12.0 && $f[0]['alta'] === '2026-03-04 10:20:30.500';
});
error('NOT NULL', 'CONSTRAINT', "INSERT INTO clientes (nombre) VALUES (NULL)");
error('UNIQUE duplicado', 'CONSTRAINT', "INSERT INTO clientes (nombre, email) VALUES ('Otra', 'ana@x.es')");
error('PK duplicada', 'CONSTRAINT', "INSERT INTO clientes (id, nombre) VALUES (1, 'Repe')");
error('fecha inválida', 'TYPE', "INSERT INTO clientes (nombre, alta) VALUES ('X', '2026-02-30')");
error('columna inexistente', 'SCHEMA', "INSERT INTO clientes (nocampo) VALUES (1)");
error('número de valores distinto', 'CONSTRAINT', "INSERT INTO clientes (nombre, email) VALUES ('X')");

echo "\n== Claves foráneas ==\n";
chk('INSERT hijo con padre existente', function () use ($bd) {
    $bd->consultar("INSERT INTO pedidos (cliente_id, referencia, total, fecha)
                    VALUES (1, 'A-001', 120.50, '2026-01-20'),
                           (1, 'A-002',  80.00, '2026-02-11'),
                           (2, 'B-001', 300.25, '2026-02-25')");
    return uno('SELECT COUNT(*) FROM pedidos') === 3;
});
error('FK sin padre', 'CONSTRAINT', "INSERT INTO pedidos (cliente_id, referencia) VALUES (999, 'Z-001')");
error('UNIQUE compuesto duplicado', 'CONSTRAINT', "INSERT INTO pedidos (cliente_id, referencia) VALUES (1, 'A-001')");
chk('ON DELETE CASCADE borra los hijos', function () use ($bd) {
    $bd->consultar("INSERT INTO lineas (pedido_id, linea, concepto) VALUES (3, 1, 'Producto'), (3, 2, 'Envío')");
    $bd->consultar('DELETE FROM clientes WHERE id = 2');
    return uno('SELECT COUNT(*) FROM pedidos WHERE cliente_id = 2') === 0
        && uno('SELECT COUNT(*) FROM lineas') === 0;      // cascada en dos niveles
});
chk('ON UPDATE CASCADE propaga el nuevo valor', function () use ($bd) {
    $bd->consultar('UPDATE clientes SET id = 50 WHERE id = 1');
    return uno('SELECT COUNT(*) FROM pedidos WHERE cliente_id = 50') === 2;
});
chk('SET NULL al borrar', function () use ($bd) {
    $bd->consultar("CREATE TABLE notas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente_id INTEGER REFERENCES clientes(id) ON DELETE SET NULL,
        texto TEXT)");
    $bd->consultar("INSERT INTO clientes (id, nombre) VALUES (300, 'Temporal')");
    $bd->consultar("INSERT INTO notas (cliente_id, texto) VALUES (300, 'una nota')");
    $bd->consultar('DELETE FROM clientes WHERE id = 300');
    return uno('SELECT cliente_id FROM notas') === null && uno('SELECT COUNT(*) FROM notas') === 1;
});
chk('RESTRICT impide borrar el padre', function () use ($bd) {
    $bd->consultar("CREATE TABLE facturas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente_id INTEGER REFERENCES clientes(id) ON DELETE RESTRICT)");
    $bd->consultar("INSERT INTO clientes (id, nombre) VALUES (400, 'Con factura')");
    $bd->consultar('INSERT INTO facturas (cliente_id) VALUES (400)');
    try { $bd->consultar('DELETE FROM clientes WHERE id = 400'); }
    catch (JsonSqlDbError $e) {
        return $e->sqlState === 'CONSTRAINT' && uno('SELECT COUNT(*) FROM clientes WHERE id = 400') === 1;
    }
    return 'no lanzó error';
});

echo "\n== UPDATE ==\n";
chk('UPDATE con WHERE', function () use ($bd) {
    $antes = uno("SELECT COUNT(*) FROM clientes WHERE ciudad = 'Madrid'");
    $r = $bd->consultar("UPDATE clientes SET ciudad = 'Elche' WHERE ciudad = 'Madrid'");
    return $antes > 0 && $r['filas'] === $antes
        && uno("SELECT COUNT(*) FROM clientes WHERE ciudad = 'Elche'") === $antes
        && uno("SELECT COUNT(*) FROM clientes WHERE ciudad = 'Madrid'") === 0;
});
chk('UPDATE con expresión sobre la propia columna', function () use ($bd) {
    $bd->consultar('UPDATE clientes SET saldo = saldo + 100 WHERE id = 100');
    return uno('SELECT saldo FROM clientes WHERE id = 100') === 125.5;
});
chk('UPDATE de varias columnas', function () use ($bd) {
    $bd->consultar("UPDATE clientes SET nombre = UPPER(nombre), saldo = 0 WHERE id = 100");
    $f = $bd->consultar('SELECT nombre, saldo FROM clientes WHERE id = 100');
    return $f[0]['nombre'] === 'MARTA' && $f[0]['saldo'] === 0.0;
});
chk('UPDATE sin WHERE afecta a todo', function () use ($bd) {
    $antes = uno('SELECT COUNT(*) FROM copia');
    $r = $bd->consultar("UPDATE copia SET ciudad = 'X'");
    return $r['filas'] === $antes;
});
error('UPDATE que rompe UNIQUE', 'CONSTRAINT', "UPDATE clientes SET email = 'ana@x.es' WHERE id = 100");
error('UPDATE con tipo incorrecto', 'TYPE', "UPDATE clientes SET saldo = 'no es un número' WHERE id = 100");

echo "\n== DELETE ==\n";
chk('DELETE con WHERE', function () use ($bd) {
    $antes = uno('SELECT COUNT(*) FROM clientes');
    $r = $bd->consultar("DELETE FROM clientes WHERE nombre = 'Tipos'");
    return $r['filas'] === 1 && uno('SELECT COUNT(*) FROM clientes') === $antes - 1;
});
chk('DELETE sin WHERE vacía la tabla', function () use ($bd) {
    $bd->consultar('DELETE FROM copia');
    return uno('SELECT COUNT(*) FROM copia') === 0;
});

echo "\n== Triggers ==\n";
chk('AFTER INSERT actualiza otra tabla', function () use ($bd) {
    $bd->consultar("
        CREATE TRIGGER trg_suma AFTER INSERT ON pedidos
        FOR EACH ROW
        BEGIN
            UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id;
        END
    ");
    $bd->consultar("INSERT INTO clientes (id, nombre) VALUES (500, 'ConTrigger')");
    $bd->consultar("INSERT INTO pedidos (cliente_id, referencia, total) VALUES (500, 'T-001', 40.25)");
    return uno('SELECT saldo FROM clientes WHERE id = 500') === 40.25;
});
chk('AFTER DELETE con OLD', function () use ($bd) {
    $bd->consultar("
        CREATE TRIGGER trg_resta AFTER DELETE ON pedidos
        FOR EACH ROW
        BEGIN
            UPDATE clientes SET saldo = saldo - OLD.total WHERE id = OLD.cliente_id;
        END
    ");
    $bd->consultar("DELETE FROM pedidos WHERE referencia = 'T-001'");
    return uno('SELECT saldo FROM clientes WHERE id = 500') === 0.0;
});
chk('BEFORE INSERT con RAISE aborta y no deja rastro', function () use ($bd) {
    $bd->consultar("
        CREATE TRIGGER trg_valida BEFORE INSERT ON pedidos
        FOR EACH ROW
        WHEN NEW.total < 0
        BEGIN
            SELECT RAISE(ABORT, 'El total no puede ser negativo');
        END
    ");
    $antes = uno('SELECT COUNT(*) FROM pedidos');
    try {
        $bd->consultar("INSERT INTO pedidos (cliente_id, referencia, total) VALUES (500, 'T-002', -5)");
    } catch (JsonSqlDbError $e) {
        return $e->sqlState === 'CONSTRAINT'
            && str_contains($e->getMessage(), 'no puede ser negativo')
            && uno('SELECT COUNT(*) FROM pedidos') === $antes;
    }
    return 'no lanzó error';
});
chk('WHEN falso deja pasar la operación', function () use ($bd) {
    $bd->consultar("INSERT INTO pedidos (cliente_id, referencia, total) VALUES (500, 'T-003', 15)");
    return uno("SELECT COUNT(*) FROM pedidos WHERE referencia = 'T-003'") === 1;
});
chk('AFTER UPDATE con NEW y OLD', function () use ($bd) {
    $bd->consultar("CREATE TABLE auditoria (id INTEGER PRIMARY KEY AUTOINCREMENT, texto TEXT)");
    $bd->consultar("
        CREATE TRIGGER trg_audit AFTER UPDATE ON pedidos
        FOR EACH ROW
        BEGIN
            INSERT INTO auditoria (texto) VALUES ('pedido ' || OLD.referencia || ': ' || OLD.total || ' -> ' || NEW.total);
        END
    ");
    $bd->consultar("UPDATE pedidos SET total = 99 WHERE referencia = 'T-003'");
    return uno('SELECT texto FROM auditoria') === 'pedido T-003: 15.0 -> 99.0';
});
chk('el trigger ve los datos aún no volcados a disco', function () use ($bd) {
    $bd->consultar("
        CREATE TRIGGER trg_cuenta AFTER INSERT ON auditoria
        FOR EACH ROW
        BEGIN
            UPDATE clientes SET ciudad = (SELECT COUNT(*) FROM auditoria) WHERE id = 500;
        END
    ");
    $bd->consultar("UPDATE pedidos SET total = 98 WHERE referencia = 'T-003'");
    return uno('SELECT ciudad FROM clientes WHERE id = 500') === '2';
});
chk('DROP TRIGGER', function () use ($bd) {
    $bd->consultar('DROP TRIGGER trg_cuenta');
    $bd->consultar('DROP TRIGGER IF EXISTS no_existe');
    return array_column($bd->consultar('SHOW TRIGGERS FROM auditoria'), 'nombre') === [];
});
error('trigger duplicado', 'SCHEMA',
    "CREATE TRIGGER trg_suma AFTER INSERT ON pedidos BEGIN DELETE FROM auditoria; END");
error('trigger sin END', 'SYNTAX',
    "CREATE TRIGGER trg_malo AFTER INSERT ON pedidos BEGIN DELETE FROM auditoria;");
chk('recursión infinita cortada', function () use ($bd) {
    $bd->consultar("CREATE TABLE bucle (id INTEGER PRIMARY KEY AUTOINCREMENT, n INTEGER)");
    $bd->consultar("CREATE TRIGGER trg_bucle AFTER INSERT ON bucle
                    FOR EACH ROW BEGIN INSERT INTO bucle (n) VALUES (NEW.n + 1); END");
    try { $bd->consultar('INSERT INTO bucle (n) VALUES (1)'); }
    catch (JsonSqlDbError $e) {
        return $e->sqlState === 'CONSTRAINT' && uno('SELECT COUNT(*) FROM bucle') === 0;
    }
    return 'no lanzó error';
});

echo "\n== ALTER TABLE ==\n";
chk('ADD COLUMN', function () use ($bd) {
    $bd->consultar("ALTER TABLE clientes ADD COLUMN telefono VARCHAR(20) DEFAULT '-'");
    return uno("SELECT telefono FROM clientes WHERE id = 500") === '-';
});
chk('RENAME COLUMN', function () use ($bd) {
    $bd->consultar('ALTER TABLE clientes RENAME COLUMN telefono TO movil');
    return uno("SELECT movil FROM clientes WHERE id = 500") === '-';
});
chk('DROP COLUMN', function () use ($bd) {
    $bd->consultar('ALTER TABLE clientes DROP COLUMN movil');
    return JsonSQLDB\Catalog::columna($bd->catalogo()->meta('clientes'), 'movil') === null;
});
chk('RENAME TO', function () use ($bd) {
    $bd->consultar('ALTER TABLE copia RENAME TO copia_clientes');
    return uno('SELECT COUNT(*) FROM copia_clientes') === 0;
});
error('DROP COLUMN de una PK referenciada', 'CONSTRAINT', 'ALTER TABLE clientes DROP COLUMN id');

echo "\n== DROP TABLE ==\n";
chk('DROP TABLE', function () use ($bd) {
    $bd->consultar('DROP TABLE copia_clientes');
    $r = $bd->consultar('DROP TABLE IF EXISTS no_existe');
    return !$bd->catalogo()->existe('copia_clientes') && str_contains($r['mensaje'], 'no existía');
});
error('DROP de tabla referenciada', 'CONSTRAINT', 'DROP TABLE clientes');
error('DROP de tabla inexistente', 'SCHEMA', 'DROP TABLE no_existe');

echo "\n== Atomicidad ==\n";
chk('un fallo a mitad no deja nada escrito', function () use ($bd) {
    $antes = uno('SELECT COUNT(*) FROM clientes');
    try {
        $bd->consultar("INSERT INTO clientes (nombre, email) VALUES
            ('Bueno1', 'b1@x.es'), ('Bueno2', 'b2@x.es'), ('Malo', 'b1@x.es')");
    } catch (JsonSqlDbError $e) {
        return $e->sqlState === 'CONSTRAINT' && uno('SELECT COUNT(*) FROM clientes') === $antes;
    }
    return 'no lanzó error';
});

echo "\n== Rendimiento ==\n";
chk('5.000 inserciones y actualización masiva', function () use ($bd) {
    $bd->consultar('CREATE TABLE medidas (id INTEGER PRIMARY KEY AUTOINCREMENT, sensor INTEGER, valor DECIMAL(10,2))');

    $valores = [];
    for ($i = 1; $i <= 5000; $i++) {
        $valores[] = '(' . (($i % 10) + 1) . ', ' . ($i / 3) . ')';
    }
    $t0 = microtime(true);
    $r = $bd->consultar('INSERT INTO medidas (sensor, valor) VALUES ' . implode(',', $valores));
    $t1 = (microtime(true) - $t0) * 1000;

    $t0 = microtime(true);
    $r2 = $bd->consultar('UPDATE medidas SET valor = valor * 2 WHERE sensor = 3');
    $t2 = (microtime(true) - $t0) * 1000;

    $t0 = microtime(true);
    $r3 = $bd->consultar('DELETE FROM medidas WHERE sensor > 8');
    $t3 = (microtime(true) - $t0) * 1000;

    printf("       INSERT 5.000 %.0f ms | UPDATE 500 %.0f ms | DELETE 1.500 %.0f ms\n", $t1, $t2, $t3);
    return $r['filas'] === 5000 && $r2['filas'] === 500 && $r3['filas'] === 1000;
});

echo "\n== Coste de las escrituras masivas ==\n";

/**
 * Coste por fila de una escritura masiva, en milisegundos.
 *
 * Se queda con la pasada MÁS RÁPIDA de varias, y descarta la primera. Medir una
 * sola vez no vale: la primera pasada de un proceso paga el calentamiento —
 * carga de clases, primer contacto con la memoria, caché del disco fría— y en
 * una máquina compartida como la de integración continua eso multiplica el
 * tiempo por diez. Con una sola medida por tamaño, el cociente entre dos
 * tamaños es ruido: aquí llegó a dar 12,7 sin que nada estuviera mal.
 *
 * El mínimo, y no la mediana, porque el ruido solo puede AÑADIR tiempo: la
 * pasada más rápida es la que más se acerca al coste real.
 *
 * @param callable(Database):void $preparar  deja la tabla lista, sin cronometrar
 * @param callable(Database):void $escritura la operación que se mide
 */
function costePorFila(string $raiz, string $nombre, int $filas,
                      callable $preparar, callable $escritura): float
{
    $mejor = null;
    for ($intento = 0; $intento < 4; $intento++) {   // la primera se tira
        $dir = "$raiz/$nombre$filas";
        Database::crear('m', $dir);
        $bd = new Database('m', $dir);
        $preparar($bd, $filas);

        $t0 = microtime(true);
        $escritura($bd);
        $ms = (microtime(true) - $t0) * 1000;

        unset($bd);
        Database::borrar('m', $dir);
        @rmdir($dir);

        if ($intento > 0 && ($mejor === null || $ms < $mejor)) {
            $mejor = $ms;
        }
    }
    return (float)$mejor / $filas;
}

chk('un UPDATE masivo crece de forma lineal, no cuadrática', function () use ($raiz) {
    // Tres costes ocultos lo volvían cuadrático: buscar cada fila recorriendo
    // la tabla, listar el directorio una vez por fila para ver quién referencia
    // la tabla, y copiar el array entero en cada iteración por el copy-on-write
    // de PHP. Con 8.000 filas eran 1,2 segundos.
    //
    // Se compara el coste POR FILA al multiplicar por ocho el tamaño: si es
    // cuadrático se multiplica por ocho; si es lineal, se queda parecido o baja,
    // porque los costes fijos se reparten entre más filas. El umbral de 2,5 deja
    // margen para el ruido y aun así delata un O(n²).
    $preparar = static function (Database $bd, int $n): void {
        $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, ciudad VARCHAR(20), v INTEGER)');
        $vals = [];
        for ($i = 1; $i <= $n; $i++) { $vals[] = "($i,'Madrid',0)"; }
        foreach (array_chunk($vals, 2000) as $b) {
            $bd->consultar('INSERT INTO t VALUES ' . implode(',', $b));
        }
    };
    $escribir = static function (Database $bd): void {
        $bd->consultar("UPDATE t SET v = 1 WHERE ciudad = 'Madrid'");
    };

    // Que además haga lo que dice
    $dir = "$raiz/comprobacion";
    Database::crear('m', $dir);
    $bd = new Database('m', $dir);
    $preparar($bd, 500);
    $escribir($bd);
    $tocadas = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t WHERE v = 1')[0]['n'];
    unset($bd);
    Database::borrar('m', $dir);
    @rmdir($dir);
    if ($tocadas !== 500) { return "el UPDATE tocó $tocadas filas de 500"; }

    // 1.000 frente a 8.000: con 4.000 los costes fijos —reescribir las partes,
    // rehacer los índices— tapaban el término cuadrático y la prueba dejaba
    // pasar un O(n²) de verdad. Comprobado quitando el atajo a propósito.
    $c1 = costePorFila($raiz, 'masivo', 1000, $preparar, $escribir);
    $c4 = costePorFila($raiz, 'masivo', 8000, $preparar, $escribir);
    $factor = $c1 > 0 ? $c4 / $c1 : 0;
    return $factor < 2.5
        ?: sprintf('el coste por fila se multiplicó por %.1f al multiplicar por ocho (%.5f -> %.5f ms)',
                   $factor, $c1, $c4);
});

chk('un DELETE masivo también', function () use ($raiz) {
    $preparar = static function (Database $bd, int $n): void {
        $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, ciudad VARCHAR(20))');
        $vals = [];
        for ($i = 1; $i <= $n; $i++) { $vals[] = "($i,'Madrid')"; }
        foreach (array_chunk($vals, 2000) as $b) {
            $bd->consultar('INSERT INTO t VALUES ' . implode(',', $b));
        }
    };
    $escribir = static function (Database $bd): void {
        $bd->consultar("DELETE FROM t WHERE ciudad = 'Madrid'");
    };

    $dir = "$raiz/comprobaciond";
    Database::crear('m', $dir);
    $bd = new Database('m', $dir);
    $preparar($bd, 500);
    $escribir($bd);
    $quedan = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
    unset($bd);
    Database::borrar('m', $dir);
    @rmdir($dir);
    if ($quedan !== 0) { return "el DELETE dejó $quedan filas sin borrar"; }

    $c1 = costePorFila($raiz, 'masivod', 1000, $preparar, $escribir);
    $c4 = costePorFila($raiz, 'masivod', 8000, $preparar, $escribir);
    $factor = $c1 > 0 ? $c4 / $c1 : 0;
    return $factor < 2.5
        ?: sprintf('el coste por fila se multiplicó por %.1f al multiplicar por ocho (%.5f -> %.5f ms)',
                   $factor, $c1, $c4);
});

echo "\n== Escritura parcial de partes ==\n";

chk('reescribir solo las partes que cambian deja lo mismo que reescribirlas todas', function () use ($raiz) {
    // Se hace la operación, se fuerza después una reescritura COMPLETA con las
    // mismas filas —y los mismos huecos de las borradas (2.9)—, y se exige que
    // ningún fichero cambie ni un byte. Si el cálculo de qué partes tocar se
    // equivoca, la que no se reescribió se queda con datos viejos y aquí se ve.
    $ops = [
        "INSERT INTO pp (v, c) VALUES ('nueva', 'z')",
        "INSERT INTO pp (v, c) VALUES ('a','q'),('b','q'),('c','q')",
        "UPDATE pp SET v = 'X' WHERE id = 1",
        "UPDATE pp SET v = 'Y' WHERE id = 260",
        "UPDATE pp SET v = 'Z' WHERE c = 'c1'",
        "UPDATE pp SET c = 'w'",
        "DELETE FROM pp WHERE id = 3",
        "DELETE FROM pp WHERE id > 250",
        "DELETE FROM pp WHERE id < 20",
        "DELETE FROM pp",
    ];
    $huella = static function (string $dir): array {
        $out = [];
        foreach ((array)glob("$dir/pp*.json") as $f) {
            $n = basename((string)$f);
            if (str_ends_with($n, '.rev.json')) { continue; }
            if (str_contains($n, '.idx.')) {
                $j = json_decode((string)file_get_contents((string)$f), true);
                unset($j['rev']);                  // sube en toda escritura
                ksort($j['keys']);                 // un índice corregido no ordena igual que uno rehecho
                $out[$n] = md5((string)json_encode($j));
                continue;
            }
            $out[$n] = md5_file((string)$f);
        }
        ksort($out);
        return $out;
    };

    foreach ($ops as $sql) {
        $dir = $raiz . '/parcial';
        @mkdir($dir, 0775, true);
        Database::crear('p', $dir);
        $bd = new Database('p', $dir);
        $bd->consultar('CREATE TABLE pp (id INTEGER PRIMARY KEY AUTOINCREMENT,
                                         v VARCHAR(20), c VARCHAR(10))');
        $bd->consultar('CREATE INDEX ix ON pp (c)');
        $vals = [];
        for ($i = 1; $i <= 280; $i++) { $vals[] = "($i,'v$i','c" . ($i % 5) . "')"; }
        $bd->consultar('INSERT INTO pp (id,v,c) VALUES ' . implode(',', $vals));
        $bd->consultar($sql);
        unset($bd);

        $parcial = $huella("$dir/p");

        $st = new Storage($dir, 'p');
        $st->bloquear(true);
        $cat  = new Catalog($st);
        $meta = $cat->meta('pp');
        // Sin decirle qué cambió: rehace todas las partes, con las filas tal y
        // como están en disco, huecos incluidos
        $crudas = [];
        for ($i = 1; is_file($f = "$dir/p/pp" . ($i === 1 ? '' : ".part$i") . '.json'); $i++) {
            array_push($crudas, ...json_decode((string)file_get_contents($f), true)['rows']);
        }
        StorageEntera::guardarTabla($st, 'pp', $crudas, null, Indexes::definiciones($meta));
        $st->desbloquear();
        unset($st, $cat);

        $completa = $huella("$dir/p");
        Database::borrar('p', $dir);
        @rmdir($dir);

        if ($parcial !== $completa) {
            $dif = [];
            foreach ($completa as $f => $md5) {
                if (($parcial[$f] ?? null) !== $md5) { $dif[] = $f; }
            }
            return "tras '$sql' difieren: " . implode(', ', $dif);
        }
    }
    return true;
});

echo "\n== Upsert, RETURNING y valores por defecto calculados (2.8) ==\n";
chk('ON CONFLICT, OR IGNORE, OR REPLACE, REPLACE INTO y RETURNING dan lo mismo que SQLite', function () use ($raiz) {
    // Lo esperado salió de SQLite 3.45 (por Python: la extensión SQLite3 de PHP
    // vuelve a ejecutar una sentencia con RETURNING al leer sus filas). Las
    // filas de RETURNING se comparan sin orden: no está definido, tampoco en SQLite
    $nombre = 'upsert' . getmypid();
    Database::crear($nombre, $raiz);
    $bd = new Database($nombre, $raiz);
    $casos = [
        ['CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, n INTEGER DEFAULT 0, nota TEXT)',
         []],
        ['CREATE TABLE h (id INTEGER PRIMARY KEY, tid INTEGER REFERENCES t (id) ON DELETE CASCADE, x TEXT)',
         []],
        ['INSERT INTO t (email, nota) VALUES (\'a@x\', \'uno\'), (\'b@x\', \'dos\') RETURNING id, email',
         [['id' => 1, 'email' => 'a@x'], ['id' => 2, 'email' => 'b@x']]],
        ['INSERT INTO t (email) VALUES (\'c@x\') RETURNING *',
         [['id' => 3, 'email' => 'c@x', 'n' => 0, 'nota' => null]]],
        ['INSERT INTO h VALUES (1, 1, \'h1\'), (2, 2, \'h2\') RETURNING id',
         [['id' => 1], ['id' => 2]]],
        ['INSERT INTO t (email, nota) VALUES (\'a@x\', \'otra\') ON CONFLICT (email) DO NOTHING RETURNING id',
         []],
        ['INSERT INTO t (id, email, nota) VALUES (1, \'a@x\', \'nueva\'), (7, \'d@x\', \'cuatro\') ON CONFLICT (email) DO UPDATE SET n = n + 1, nota = excluded.nota RETURNING id, email, n, nota',
         [['id' => 1, 'email' => 'a@x', 'n' => 1, 'nota' => 'nueva'], ['id' => 7, 'email' => 'd@x', 'n' => 0, 'nota' => 'cuatro']]],
        ['INSERT INTO t (email, nota) VALUES (\'a@x\', \'x\') ON CONFLICT (email) DO UPDATE SET n = n + 10 WHERE excluded.nota = \'no\' RETURNING id, n',
         []],
        ['INSERT INTO t (email, nota) VALUES (\'a@x\', \'y\') ON CONFLICT DO UPDATE SET n = n + 100 RETURNING n',
         [['n' => 101]]],
        ['INSERT OR IGNORE INTO t (id, email) VALUES (1, \'z@x\'), (50, \'b@x\'), (51, \'e@x\') RETURNING id',
         [['id' => 51]]],
        ['INSERT OR REPLACE INTO t (id, email, nota) VALUES (2, \'b2@x\', \'reemplazada\') RETURNING *',
         [['id' => 2, 'email' => 'b2@x', 'n' => 0, 'nota' => 'reemplazada']]],
        ['REPLACE INTO t (id, email, nota) VALUES (60, \'c@x\', \'quita la 3\') RETURNING id, email',
         [['id' => 60, 'email' => 'c@x']]],
        ['SELECT * FROM t ORDER BY id',
         [['id' => 1, 'email' => 'a@x', 'n' => 101, 'nota' => 'nueva'], ['id' => 2, 'email' => 'b2@x', 'n' => 0, 'nota' => 'reemplazada'], ['id' => 7, 'email' => 'd@x', 'n' => 0, 'nota' => 'cuatro'], ['id' => 51, 'email' => 'e@x', 'n' => 0, 'nota' => null], ['id' => 60, 'email' => 'c@x', 'n' => 0, 'nota' => 'quita la 3']]],
        ['SELECT * FROM h ORDER BY id',
         [['id' => 1, 'tid' => 1, 'x' => 'h1']]],
        ['UPDATE t SET n = n + 1 WHERE email LIKE \'%x\' RETURNING id, n * 2 AS doble',
         [['id' => 1, 'doble' => 204], ['id' => 2, 'doble' => 2], ['id' => 7, 'doble' => 2], ['id' => 51, 'doble' => 2], ['id' => 60, 'doble' => 2]]],
        ['DELETE FROM t WHERE id > 50 RETURNING id, email',
         [['id' => 51, 'email' => 'e@x'], ['id' => 60, 'email' => 'c@x']]],
        ['INSERT INTO t (email) VALUES (\'a@x\') ON CONFLICT (nota) DO NOTHING',
         'ERROR'],
        ['INSERT INTO t (email) VALUES (\'a@x\') ON CONFLICT (id) DO NOTHING',
         'ERROR'],
        ['INSERT INTO t (id, email) VALUES (1, \'nuevo@x\') ON CONFLICT (id) DO UPDATE SET email = excluded.email RETURNING id, email',
         [['id' => 1, 'email' => 'nuevo@x']]],
        ['INSERT INTO t (id, email) VALUES (1, \'b2@x\') ON CONFLICT (id) DO UPDATE SET email = excluded.email',
         'ERROR'],
        ['SELECT * FROM t ORDER BY id',
         [['id' => 1, 'email' => 'nuevo@x', 'n' => 102, 'nota' => 'nueva'], ['id' => 2, 'email' => 'b2@x', 'n' => 1, 'nota' => 'reemplazada'], ['id' => 7, 'email' => 'd@x', 'n' => 1, 'nota' => 'cuatro']]],
        ['CREATE TABLE c (a INTEGER, b TEXT, v INTEGER DEFAULT 1, UNIQUE (a, b))',
         []],
        ['CREATE TABLE log (ev TEXT, a INTEGER, v INTEGER)',
         []],
        ['CREATE TRIGGER c_ai AFTER INSERT ON c BEGIN INSERT INTO log VALUES (\'ins\', NEW.a, NEW.v); END',
         []],
        ['CREATE TRIGGER c_au AFTER UPDATE ON c BEGIN INSERT INTO log VALUES (\'upd\', NEW.a, NEW.v); END',
         []],
        ['INSERT INTO c (a, b) VALUES (1, \'x\'), (1, \'y\'), (1, \'x\'), (2, NULL), (2, NULL) ON CONFLICT (a, b) DO UPDATE SET v = v + 10 RETURNING a, b, v',
         [['a' => 1, 'b' => 'x', 'v' => 1], ['a' => 1, 'b' => 'y', 'v' => 1], ['a' => 1, 'b' => 'x', 'v' => 11], ['a' => 2, 'b' => null, 'v' => 1], ['a' => 2, 'b' => null, 'v' => 1]]],
        ['SELECT * FROM c ORDER BY a, b, v',
         [['a' => 1, 'b' => 'x', 'v' => 11], ['a' => 1, 'b' => 'y', 'v' => 1], ['a' => 2, 'b' => null, 'v' => 1], ['a' => 2, 'b' => null, 'v' => 1]]],
        ['SELECT * FROM log ORDER BY ev, a, v',
         [['ev' => 'ins', 'a' => 1, 'v' => 1], ['ev' => 'ins', 'a' => 1, 'v' => 1], ['ev' => 'ins', 'a' => 2, 'v' => 1], ['ev' => 'ins', 'a' => 2, 'v' => 1], ['ev' => 'upd', 'a' => 1, 'v' => 11]]],
        ['INSERT INTO c (a, b, v) VALUES (1, \'y\', 5) ON CONFLICT (b, a) DO UPDATE SET b = \'x\'',
         'ERROR'],
        ['SELECT * FROM c ORDER BY a, b, v',
         [['a' => 1, 'b' => 'x', 'v' => 11], ['a' => 1, 'b' => 'y', 'v' => 1], ['a' => 2, 'b' => null, 'v' => 1], ['a' => 2, 'b' => null, 'v' => 1]]],
        ['INSERT OR IGNORE INTO c (a, b) SELECT a, b FROM c RETURNING a',
         [['a' => 2], ['a' => 2]]],
        ['INSERT INTO h VALUES (10, 1, \'del uno\')',
         []],
        ['INSERT OR REPLACE INTO t (id, email) VALUES (1, \'otro@x\')',
         []],
        ['SELECT * FROM h ORDER BY id',
         []],
        ['DELETE FROM c WHERE v > 5 RETURNING a || \'-\' || b AS k, v',
         [['k' => '1-x', 'v' => 11]]],
        ['INSERT INTO c (a, b) VALUES (9, \'z\') ON CONFLICT DO NOTHING RETURNING *',
         [['a' => 9, 'b' => 'z', 'v' => 1]]],
        ['INSERT INTO c (a, b) VALUES (9, \'z\') ON CONFLICT DO NOTHING RETURNING *',
         []],
    ];
    foreach ($casos as $i => [$sql, $esperado]) {
        try {
            $r = $bd->consultar($sql);
            $obtenido = isset($r['success']) ? [] : $r;
        } catch (JsonSQLDB\JsonSqlDbError) {
            $obtenido = 'ERROR';
        }
        if (is_array($esperado) && is_array($obtenido) && stripos($sql, 'RETURNING') !== false) {
            sort($esperado);
            sort($obtenido);
        }
        if ($obtenido !== $esperado) {
            Database::borrar($nombre, $raiz);
            return "sentencia $i: $sql -> " . json_encode($obtenido, JSON_UNESCAPED_UNICODE);
        }
    }
    Database::borrar($nombre, $raiz);
    return true;
});

chk('DEFAULT CURRENT_TIMESTAMP, CURRENT_DATE y (expresión): se calculan al insertar y con SET col = DEFAULT', function () use ($raiz) {
    $nombre = 'defectos' . getmypid();
    Database::crear($nombre, $raiz);
    $bd = new Database($nombre, $raiz);
    $bd->consultar("CREATE TABLE d (id INTEGER PRIMARY KEY, c DATETIME DEFAULT CURRENT_TIMESTAMP, f DATE DEFAULT CURRENT_DATE,
                    x TEXT DEFAULT (upper('a') || 'b'), k INTEGER DEFAULT (2 * 21), m INTEGER DEFAULT -5, z TEXT DEFAULT ('fijo'))");
    $antes = date('Y-m-d H:i:s');
    $bd->consultar('INSERT INTO d (id) VALUES (1)');
    $bd->consultar("INSERT INTO d (id, x, k) VALUES (2, 'otro', 1)");
    $bd->consultar('UPDATE d SET x = DEFAULT, k = DEFAULT WHERE id = 2');
    $f = $bd->consultar('SELECT * FROM d ORDER BY id');
    $despues = date('Y-m-d H:i:s');
    $esquema = array_column($bd->consultar('SHOW SCHEMA d'), 'defecto_calculado', 'columna');
    $bien = $f[0]['c'] >= $antes && $f[0]['c'] <= $despues && $f[0]['f'] === substr($f[0]['c'], 0, 10)
        && $f[0]['x'] === 'Ab' && $f[0]['k'] === 42 && $f[0]['m'] === -5 && $f[0]['z'] === 'fijo'
        && $f[1]['x'] === 'Ab' && $f[1]['k'] === 42
        && $esquema === ['id' => null, 'c' => 'CURRENT_TIMESTAMP', 'f' => 'CURRENT_DATE', 'x' => "upper('a') || 'b'", 'k' => '2 * 21', 'm' => null, 'z' => null];
    if (!$bien) {
        Database::borrar($nombre, $raiz);
        return json_encode([$f, $esquema]);
    }
    // Lo que SQLite tampoco admite
    $mal = [];
    foreach (['CREATE TABLE e1 (a INTEGER DEFAULT (b + 1), b INTEGER)', 'CREATE TABLE e2 (a INTEGER DEFAULT ((SELECT 1)))',
              'ALTER TABLE d ADD COLUMN w DATETIME DEFAULT CURRENT_TIMESTAMP'] as $q) {
        try { $bd->consultar($q); $mal[] = $q; } catch (JsonSQLDB\JsonSqlDbError) { }
    }
    $ahora = $bd->consultar('SELECT CURRENT_TIMESTAMP AS a, CURRENT_DATE AS b, CURRENT_TIME AS c')[0];
    Database::borrar($nombre, $raiz);
    return $mal === [] && strlen($ahora['a']) === 19 && strlen($ahora['b']) === 10 && strlen($ahora['c']) === 8
        ?: 'admitió: ' . implode(' | ', $mal) . ' · ' . json_encode($ahora);
});

echo "\n== Cascadas, triggers y upsert (2.9) ==\n";
/**
 * Las mismas sentencias aquí y en SQLite, en una base nueva: null si no hay
 * SQLite3; si no, true cuando cada una da error en las dos o en ninguna y las
 * tablas de $tablas quedan iguales (por su primera columna), o lo distinto.
 */
function comoSqlite(string $raiz, array $sentencias, array $tablas): bool|string|null {
    if (!class_exists('SQLite3')) { return null; }
    $nombre = 'lite' . getmypid();
    Database::crear($nombre, $raiz);
    $bd = new Database($nombre, $raiz);
    $l  = new SQLite3(':memory:');
    $l->exec('PRAGMA foreign_keys = ON');
    $mal = [];
    foreach ($sentencias as $q) {
        try { $bd->consultar($q); $aqui = 'bien'; } catch (JsonSqlDbError $e) { $aqui = 'error'; }
        $alli = @$l->exec($q) ? 'bien' : 'error';
        if ($aqui !== $alli) { $mal[] = "$q: aquí $aqui, SQLite $alli"; }
    }
    foreach ($tablas as $t) {
        $r = $l->query("SELECT * FROM $t ORDER BY 1");
        $esperado = [];
        while ($f = $r->fetchArray(SQLITE3_ASSOC)) { $esperado[] = $f; }
        $obtenido = $bd->consultar("SELECT * FROM $t ORDER BY 1");
        if ($obtenido !== $esperado) { $mal[] = "$t: SQLite " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . ' · aquí ' . json_encode($obtenido, JSON_UNESCAPED_UNICODE); }
    }
    Database::borrar($nombre, $raiz);
    return $mal === [] ?: implode(' | ', $mal);
}
foreach (['sin índice en las claves foráneas' => [], 'con índice (las hijas se leen sin cargar su tabla)' => ['CREATE INDEX ix_g_hk ON g (hk)', 'CREATE INDEX ix_h2_pid ON h2 (pid)']] as $variante => $indices) {
    chk("ON UPDATE CASCADE llega a las nietas cuando cambia la clave de la hija a la que apuntan, y la cascada, SET NULL y SET DEFAULT lanzan los triggers UPDATE de las hijas, como en SQLite (2.9), $variante", function () use ($raiz, $indices) {
        // h.k es la clave de h a la que apunta g, y cambia con la cascada desde p
        $ddl = ['CREATE TABLE p (id INTEGER PRIMARY KEY, n TEXT)',
                'CREATE TABLE h (id INTEGER PRIMARY KEY, k INTEGER UNIQUE REFERENCES p (id) ON UPDATE CASCADE ON DELETE SET NULL)',
                'CREATE TABLE g (id INTEGER PRIMARY KEY, hk INTEGER REFERENCES h (k) ON UPDATE CASCADE ON DELETE CASCADE)',
                'CREATE TABLE h2 (id INTEGER PRIMARY KEY, pid INTEGER DEFAULT 1 REFERENCES p (id) ON UPDATE SET DEFAULT ON DELETE SET DEFAULT)'];
        $datos = ["INSERT INTO p VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd')",
                  'INSERT INTO h VALUES (10, 2), (11, 3), (12, NULL), (13, 4)',
                  'INSERT INTO g VALUES (100, 2), (101, 3), (102, 2), (103, 4)',
                  'INSERT INTO h2 VALUES (1, 2), (2, 3), (3, 4)'];
        $escrituras = ['UPDATE p SET id = 20 WHERE id = 2', 'UPDATE p SET id = id + 100 WHERE id IN (3, 4)',
                       'DELETE FROM p WHERE id = 103', "UPDATE p SET n = 'z'", 'DELETE FROM p WHERE id = 20', 'DELETE FROM p WHERE id = 1'];
        $sinTriggers = comoSqlite($raiz, array_merge($ddl, $indices, $datos, $escrituras), ['p', 'h', 'g', 'h2']);
        // Y los triggers de las hijas, con sus filas de antes y de después. Los
        // de h2 aparte: entre dos tablas hijas, el orden de sus triggers no está dicho
        $lg = 'CREATE TABLE lg (n INTEGER PRIMARY KEY, t TEXT)';
        $conTriggers = comoSqlite($raiz, array_merge($ddl, $indices, $datos, [$lg,
            "CREATE TRIGGER h_bu BEFORE UPDATE ON h BEGIN INSERT INTO lg (t) VALUES ('h antes ' || OLD.id || ': ' || COALESCE(OLD.k, '-') || '>' || COALESCE(NEW.k, '-')); END",
            "CREATE TRIGGER h_au AFTER UPDATE ON h BEGIN INSERT INTO lg (t) VALUES ('h después ' || OLD.id || ': ' || COALESCE(OLD.k, '-') || '>' || COALESCE(NEW.k, '-')); END",
            "CREATE TRIGGER g_au AFTER UPDATE ON g BEGIN INSERT INTO lg (t) VALUES ('g ' || OLD.id || ': ' || OLD.hk || '>' || COALESCE(NEW.hk, '-')); END"],
            $escrituras), ['p', 'h', 'g', 'h2', 'lg']);
        $h2 = comoSqlite($raiz, array_merge($ddl, $indices, $datos, [$lg,
            "CREATE TRIGGER h2_au AFTER UPDATE ON h2 BEGIN INSERT INTO lg (t) VALUES ('h2 ' || OLD.id || ': ' || OLD.pid || '>' || NEW.pid); END"],
            $escrituras), ['h2', 'lg']);
        return $sinTriggers === true && $conTriggers === true && $h2 === true ?: json_encode([$sinTriggers, $conTriggers, $h2], JSON_UNESCAPED_UNICODE);
    });
    chk("una hija cambiada por una cascada pasa sus UNIQUE y sus claves foráneas: repetida, también con otra hija de la misma sentencia, o con un DEFAULT que no está en el padre, es un error y no se escribe nada, como en SQLite (2.9), $variante", function () use ($raiz, $indices) {
        $ddl = ['CREATE TABLE p (id INTEGER PRIMARY KEY)',
                'CREATE TABLE h (pid INTEGER DEFAULT 9 REFERENCES p (id) ON UPDATE SET DEFAULT ON DELETE SET DEFAULT, x INTEGER, UNIQUE (pid, x))',
                'CREATE TABLE g (hk INTEGER DEFAULT 999 REFERENCES p (id) ON DELETE SET DEFAULT)',
                'CREATE TABLE c (pid INTEGER UNIQUE REFERENCES p (id) ON UPDATE CASCADE)',
                'INSERT INTO p VALUES (1), (2), (3), (5), (6), (9)',
                'INSERT INTO h VALUES (1, 7), (9, 7), (3, 8), (5, 8)',
                'INSERT INTO g VALUES (2)',
                'INSERT INTO c VALUES (6)'];
        $ixs = $indices === [] ? [] : ['CREATE INDEX ix_h_pid ON h (pid)', 'CREATE INDEX ix_g_hk ON g (hk)', 'CREATE INDEX ix_c_pid ON c (pid)'];
        return comoSqlite($raiz, array_merge($ddl, $ixs, [
            'UPDATE p SET id = 4 WHERE id = 1',        // (1, 7) pasaría a (9, 7), que ya está
            'DELETE FROM p WHERE id = 2',              // g pasaría a 999, que no está en p
            'DELETE FROM p WHERE id IN (3, 5)',        // (3, 8) y (5, 8), las dos a (9, 8) en la misma sentencia
            'UPDATE p SET id = 7 WHERE id = 6',        // c, UNIQUE, a 7: libre
            'DELETE FROM p WHERE id = 5',              // (5, 8) sola, a (9, 8): libre
            'UPDATE p SET id = 10 WHERE id = 3',       // (3, 8) a (9, 8): ya no
        ]), ['p', 'h', 'g', 'c']);
    });
}
chk('una fila nueva que apunta a una fila padre que la sentencia ya ha borrado es un error: el trigger de una hija borrada en cascada ya no la ve, como en SQLite (2.9)', function () use ($raiz) {
    $a = comoSqlite($raiz, [
        'CREATE TABLE p (id INTEGER PRIMARY KEY)',
        'CREATE TABLE h (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER REFERENCES p (id) ON DELETE CASCADE)',
        'CREATE TABLE alog (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER REFERENCES p (id))',
        'CREATE TABLE cnt (n INTEGER)',
        'CREATE TRIGGER t AFTER DELETE ON h BEGIN INSERT INTO cnt SELECT COUNT(*) FROM p; END',
        'INSERT INTO p VALUES (1), (2), (3)', 'INSERT INTO h (pid) VALUES (1), (2), (3)',
        'DELETE FROM p WHERE id = 3',                                       // el trigger ya no cuenta la 3
        'CREATE TRIGGER t2 AFTER DELETE ON h BEGIN INSERT INTO alog (pid) VALUES (OLD.pid); END',
        'DELETE FROM p WHERE id IN (1, 2)',
    ], ['p', 'h', 'alog', 'cnt']);
    // La clave del padre ya se ha buscado en su índice cuando la fila se borra
    // sin cargar la tabla: el índice del disco todavía la tiene
    $b = comoSqlite($raiz, [
        'CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE a1 (pid INTEGER REFERENCES p (id))', 'CREATE TABLE q (x INTEGER, y INTEGER)',
        'CREATE TRIGGER tq AFTER INSERT ON q BEGIN INSERT INTO a1 VALUES (NEW.x); DELETE FROM p WHERE id = NEW.x + 1; INSERT INTO a1 VALUES (NEW.x + NEW.y); END',
        'INSERT INTO p VALUES (1), (2), (3), (4), (5)', 'INSERT INTO q VALUES (1, 2)', 'INSERT INTO q VALUES (3, 1)',
    ], ['p', 'a1', 'q']);
    return $a === true && $b === true ?: json_encode([$a, $b], JSON_UNESCAPED_UNICODE);
});
chk('un upsert no pierde lo que sabe de sus claves cuando su tabla se carga a mitad de sentencia, ni por lo que escriben en ella o en otras los triggers que lanzan sus cascadas, ni por una cascada que borra filas de su propia tabla (2.9: un error de PHP, un «Valor duplicado» de más o una clave repetida)', function () use ($raiz) {
    $base = ['CREATE TABLE p (id INTEGER PRIMARY KEY, nombre TEXT)',
             'CREATE TABLE h (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER REFERENCES p (id) ON DELETE CASCADE)',
             'CREATE TABLE cnt (n INTEGER)',
             "INSERT INTO p VALUES (1, 'a'), (2, 'b')", 'INSERT INTO h (pid) VALUES (2)'];
    $r = [];
    foreach (['UPDATE p SET nombre = nombre WHERE id = -1', 'INSERT INTO cnt VALUES (OLD.pid)', 'INSERT INTO cnt SELECT COUNT(*) FROM h'] as $cuerpo) {
        $r[] = comoSqlite($raiz, array_merge($base, ["CREATE TRIGGER t AFTER DELETE ON h BEGIN $cuerpo; END",
            "INSERT OR REPLACE INTO p VALUES (1, 'A'), (2, 'B'), (1, 'C')", 'INSERT INTO h (pid) VALUES (2)',
            "INSERT INTO p VALUES (2, 'x'), (7, 'y'), (2, 'z') ON CONFLICT (id) DO UPDATE SET nombre = nombre || excluded.nombre"]), ['p', 'h', 'cnt']);
    }
    // Una subconsulta del DO UPDATE carga la tabla a mitad de sentencia: lo que
    // sabía sin cargarla no tiene las filas del disco que no había tocado
    $r[] = comoSqlite($raiz, ['CREATE TABLE p (id INTEGER PRIMARY KEY, nombre TEXT)', "INSERT INTO p VALUES (1, 'a'), (2, 'b')",
        "INSERT INTO p VALUES (5, 'n'), (1, 'x'), (2, 'y') ON CONFLICT (id) DO UPDATE SET nombre = (SELECT COUNT(*) FROM p) || excluded.nombre"], ['p']);
    // Con la tabla del upsert ya cargada (tiene un trigger), el de la hija
    // borrada en cascada le pone a otra fila la clave con la que choca la siguiente
    $r[] = comoSqlite($raiz, ['CREATE TABLE p (id INTEGER PRIMARY KEY, nombre TEXT)',
        'CREATE TABLE h (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER REFERENCES p (id) ON DELETE CASCADE)',
        'CREATE TRIGGER pt AFTER UPDATE ON p BEGIN SELECT 1; END',
        'CREATE TRIGGER ht AFTER DELETE ON h BEGIN UPDATE p SET id = OLD.pid + 100 WHERE id = 2; END',
        "INSERT INTO p VALUES (1, 'a'), (2, 'b')", 'INSERT INTO h (pid) VALUES (1)',
        "INSERT OR REPLACE INTO p VALUES (1, 'A'), (101, 'B')"], ['p', 'h']);
    // Con una clave foránea hacia sí misma, la cascada borra filas de la tabla del upsert
    $nombre = 'arbol' . getmypid();
    Database::crear($nombre, $raiz);
    $bd = new Database($nombre, $raiz);
    $bd->consultar('CREATE TABLE tree (id INTEGER PRIMARY KEY, parent INTEGER REFERENCES tree (id) ON DELETE CASCADE)');
    $bd->consultar('INSERT INTO tree VALUES (1, NULL), (2, 1), (3, 2)');
    try { $bd->consultar('INSERT OR REPLACE INTO tree VALUES (1, NULL), (2, 1)'); $arbol = 'bien'; } catch (JsonSqlDbError) { $arbol = 'error controlado'; }
    $filas = count($bd->consultar('SELECT * FROM tree'));
    Database::borrar($nombre, $raiz);
    return $r === [true, true, true, true, true] && ($arbol === 'bien' ? $filas === 2 : $filas === 3) ?: json_encode([$r, $arbol, $filas], JSON_UNESCAPED_UNICODE);
});
chk('TRUNCATE TABLE con una clave foránea hacia sí misma vacía la tabla, y con autoincremento se escribe una sola vez aunque se pueda escribir por partes (2.9)', function () use ($raiz) {
    $nombre = 'trunc' . getmypid();
    Database::crear($nombre, $raiz);
    $bd = new Database($nombre, $raiz);
    $bd->consultar('CREATE TABLE tree (id INTEGER PRIMARY KEY, parent INTEGER REFERENCES tree (id))');
    $bd->consultar('INSERT INTO tree VALUES (1, NULL), (2, 1), (3, 2)');
    $arbol = $bd->consultar('TRUNCATE TABLE tree')['filas'];
    $bd->consultar('CREATE TABLE a (id INTEGER PRIMARY KEY AUTOINCREMENT, x TEXT)');
    $bd->consultar("INSERT INTO a (x) VALUES ('u'), ('v'), ('w')");
    $bd->consultar('CREATE INDEX ix_a_x ON a (x)');            // la estructura guarda ya el contador en 4
    [, $repetidas] = StorageCambios::cuentaPartes();
    $vaciadas = $bd->consultar('TRUNCATE TABLE a')['filas'];
    [, $repetidas2] = StorageCambios::cuentaPartes();
    $bd->consultar("INSERT INTO a (x) VALUES ('nueva')");
    $r = [$arbol, $bd->consultar('SELECT * FROM tree'), $vaciadas, $repetidas2 - $repetidas, $bd->consultar('SELECT * FROM a'),
          $bd->consultar('TRUNCATE TABLE tree')['filas']];
    Database::borrar($nombre, $raiz);
    return $r === [3, [], 3, 0, [['id' => 1, 'x' => 'nueva']], 0] ?: json_encode($r);
});
chk('las tablas que leen las sentencias de un trigger, con subconsultas, UPDATE … FROM, DELETE … USING o SELECT … FROM, cuentan para el bloqueo: la escritura bloquea la base entera (2.9)', function () use ($raiz) {
    $nombre = 'cierre' . getmypid();
    Database::crear($nombre, $raiz);
    $bd = new Database($nombre, $raiz);
    foreach (['CREATE TABLE a (x INTEGER)', 'CREATE TABLE b (y INTEGER)', 'CREATE TABLE c (z INTEGER)'] as $q) { $bd->consultar($q); }
    $cuerpos = ['INSERT INTO b VALUES ((SELECT MAX(z) FROM c))', 'UPDATE b SET y = c.z FROM c WHERE c.z = NEW.x',
                'DELETE FROM b WHERE y IN (SELECT z FROM c)', 'DELETE FROM b USING c WHERE b.y = c.z',
                "SELECT RAISE(ABORT, 'no') FROM c WHERE z = NEW.x"];
    $r = [];
    $ast = JsonSQLDB\Parser::analizar('INSERT INTO a VALUES (1)');
    foreach ($cuerpos as $i => $cuerpo) {
        $bd->consultar("CREATE TRIGGER t$i AFTER INSERT ON a BEGIN $cuerpo; END");
        $r[] = $bd->tablasAfectadas($ast);
        $bd->consultar("DROP TRIGGER t$i");
    }
    // Lo que solo escribe en otra tabla sigue bloqueando solo las dos
    $bd->consultar("CREATE TRIGGER tb AFTER INSERT ON a BEGIN INSERT INTO b VALUES (NEW.x); SELECT RAISE(ABORT, 'no') WHERE NEW.x < 0; END");
    $r[] = $bd->tablasAfectadas($ast);
    Database::borrar($nombre, $raiz);
    return $r === [null, null, null, null, null, ['a', 'b']] ?: json_encode($r);
});

echo "\n== Limpieza ==\n";
chk('sin ficheros temporales y base borrada', function () use ($raiz, $base) {
    $restos = glob("$raiz/$base/*.tmp");
    Database::borrar($base, $raiz);
    @rmdir($raiz);
    return $restos === [] && !is_dir("$raiz/$base");
});

chk('el recuento de filas guardado en la caché nunca miente: una edición a mano se ve, también la del mismo segundo con el mismo tamaño (2.9)', function () use ($raiz) {
    exec('rm -rf ' . escapeshellarg($raiz . '/recuento'));
    Database::crear('recuento', $raiz);
    (new Database('recuento', $raiz))->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT)");
    (new Database('recuento', $raiz))->consultar("INSERT INTO t VALUES (1, 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'), (2, 'b'), (3, 'c')");
    $st = (new JsonSQLDB\Storage($raiz, 'recuento'));
    $f = $raiz . '/recuento/t.json';
    $cuenta = static function () use ($raiz): int { return StorageLectura::contarFilas(new JsonSQLDB\Storage($raiz, 'recuento'), 't'); };
    touch($f, time() - 100);
    $a = $cuenta();                                   // se cuenta y se guarda
    $a2 = $cuenta();                                  // de la caché
    // A mano: una fila más (cambia el tamaño)
    $texto = (string)file_get_contents($f);
    $texto = preg_replace('/(\n    \{"id":2,[^\n]*\n)/', "$1    {\"id\":4,\"x\":\"d\"},\n", $texto, 1);
    file_put_contents($f, $texto);
    touch($f, time() - 50);
    $b = $cuenta();
    // En el mismo segundo y con el mismo tamaño: la fila 1 partida en dos
    $ahora = time();
    touch($f, $ahora);
    $c = $cuenta();                                   // no se guarda: modificada en este segundo
    $texto = (string)file_get_contents($f);
    $vieja = '    {"id":1,"x":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"},';
    $nueva = '    {"id":1,"x":"A"},' . "\n" . '    {"id":5,"x":"' . str_repeat('B', strlen($vieja) - strlen('    {"id":1,"x":"A"},') - strlen("\n    {\"id\":5,\"x\":\"\"},")) . '"},';
    $mismo = strlen($vieja) === strlen($nueva);
    file_put_contents($f, str_replace($vieja, $nueva, $texto));
    touch($f, $ahora);
    clearstatcache();
    $d = $cuenta();
    exec('rm -rf ' . escapeshellarg($raiz . '/recuento'));
    return [$a, $a2, $b, $c, $d, $mismo] === [3, 3, 4, 4, 5, true] ?: json_encode([$a, $a2, $b, $c, $d, $mismo, $st instanceof JsonSQLDB\Storage]);
});
chk('con OPcache activo, las partes estables se cargan de OPcache con las mismas filas; al reescribirse se borra su .php; y se puede desactivar (2.9)', function () use ($raiz) {
    if (!function_exists('opcache_get_status')) { return null; }
    exec('rm -rf ' . escapeshellarg($raiz . '/opc'));
    Database::crear('opc', $raiz);
    $bd = new Database('opc', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT, n DOUBLE)');
    for ($i = 1; $i <= 4500; $i += 500) {
        $v = [];
        for ($j = $i; $j < $i + 500; $j++) { $v[] = "($j, 'fila \"$j\" con ''comillas'' y \\\\ barra', " . ($j / 7) . ')'; }
        $bd->consultar('INSERT INTO t VALUES ' . implode(', ', $v));
    }
    $correr = static fn(string ...$extra): array => (array)json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 '
        . escapeshellarg(__DIR__ . '/_opcache.php') . ' ' . escapeshellarg($raiz) . ' ' . implode(' ', array_map('escapeshellarg', $extra)) . ' 2>&1'), true);
    $con = $correr();
    exec('rm -rf ' . escapeshellarg($raiz . '/opc/.cache'));
    $sin = $correr('sin');
    exec('rm -rf ' . escapeshellarg($raiz . '/opc'));
    // Sin estabilidad, ninguno; estables, 4 en la petición (el límite); las mismas
    // filas; tras el UPDATE, el .php de la parte 1 ya no está; ningún .tmp; y
    // con JSONSQLDB_CACHE_OPCACHE = false, ningún .php
    return ($con['recien'] ?? -1) === 0 && ($con['tras'] ?? 0) === 4 && ($con['estado'] ?? 0) === 1 && ($sin['estado'] ?? 1) === 0
        && ($con['iguales'] ?? false) === true
        && ($con['quedan'] ?? 0) === 3 && ($con['tmp'] ?? 1) === 0 && ($sin['tras'] ?? 1) === 0 && ($sin['iguales'] ?? false) === true
        ?: json_encode([$con, $sin]);
});
chk('en un mismo proceso, el estado y la estructura ya leídos no se usan si otro proceso cambió la tabla (2.9)', function () use ($raiz) {
    if (is_dir("$raiz/proc")) { Database::borrar('proc', $raiz); }
    Database::crear('proc', $raiz);
    $bd = new Database('proc', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, x TEXT)');
    $bd->consultar("INSERT INTO t (x) VALUES ('a'), ('b')");
    // Estables: se pueden recordar. Cada vez con otra hora, como pasa al
    // escribir: con la misma, y el mismo tamaño, el sistema de ficheros puede
    // reutilizar el inodo y el sello no cambiaría, cosa que fuera de la prueba
    // no ocurre
    $estables = static function (int $hace) use ($raiz): void {
        foreach ((array)glob("$raiz/proc/t*.json") as $f) { touch((string)$f, time() - $hace); }
    };
    $estables(60);
    $antes = [(new Database('proc', $raiz))->consultar('SELECT COUNT(*) AS n FROM t'), (new Database('proc', $raiz))->consultar('SELECT * FROM t WHERE id = 1')];
    // Otro proceso cambia la estructura y añade una fila
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' $b = new JsonSQLDB\\Database("proc", ' . var_export($raiz, true) . ');'
        . ' $b->consultar("ALTER TABLE t ADD COLUMN y INTEGER DEFAULT 7"); $b->consultar("INSERT INTO t (x) VALUES (\'c\')");';
    shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo));
    $estables(30);
    $despues = [(new Database('proc', $raiz))->consultar('SELECT COUNT(*) AS n FROM t'), (new Database('proc', $raiz))->consultar('SELECT * FROM t WHERE id = 3')];
    Database::borrar('proc', $raiz);
    return $antes === [[['n' => 2]], [['id' => 1, 'x' => 'a']]] && $despues === [[['n' => 3]], [['id' => 3, 'x' => 'c', 'y' => 7]]]
        ?: json_encode([$antes, $despues]);
});
chk('en un mismo proceso, el estado de una tabla escrita en este segundo no se recuerda: dos escrituras más pueden dejarle el mismo sello (2.9)', function () use ($raiz) {
    // El sistema de ficheros reutiliza el inodo que deja libre cada escritura:
    // dos escrituras después, rev.json puede tener el mismo inodo, tamaño y
    // segundo que antes. Lo de este segundo no se recuerda: con un estado
    // viejo, el autoincremento repetiría los números que ha dado otro proceso
    if (is_dir("$raiz/seg")) { Database::borrar('seg', $raiz); }
    Database::crear('seg', $raiz);
    $bd = new Database('seg', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, x TEXT)');
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' $b = new JsonSQLDB\\Database("seg", ' . var_export($raiz, true) . ');'
        . ' $b->consultar("INSERT INTO t (x) VALUES (\'otro\')"); $b->consultar("INSERT INTO t (x) VALUES (\'otro\')");';
    $mal = [];
    for ($i = 0; $i < 3; $i++) {
        while (microtime(true) - floor(microtime(true)) > 0.3) { usleep(20000); }   // al empezar un segundo
        try {
            $bd->consultar("INSERT INTO t (x) VALUES ('este')");
            (new Database('seg', $raiz))->consultar('SELECT COUNT(*) FROM t');
            shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo));
            $bd->consultar("INSERT INTO t (x) VALUES ('este')");
        } catch (JsonSqlDbError $e) {
            $mal[] = $e->getMessage();
        }
    }
    $n = (new Database('seg', $raiz))->consultar('SELECT COUNT(*) AS n, COUNT(DISTINCT id) AS d FROM t')[0];
    Database::borrar('seg', $raiz);
    return $mal === [] && $n === ['n' => 12, 'd' => 12] ?: json_encode([$mal, $n], JSON_UNESCAPED_UNICODE);
});
chk('el estado de una tabla guardado en OPcache no se usa si otro proceso la cambió, y DROP TABLE lo borra (2.9)', function () use ($raiz) {
    if (!function_exists('opcache_get_status')) { return null; }
    if (is_dir("$raiz/est")) { Database::borrar('est', $raiz); }
    Database::crear('est', $raiz);
    $bd = new Database('est', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, x TEXT)');
    $bd->consultar("INSERT INTO t (x) VALUES ('a'), ('b')");
    foreach ((array)glob($raiz . '/est/t*.json') as $f) {
        touch((string)$f, time() - 60);                 // estable: su estado se puede guardar
    }
    $correr = static fn(string $paso, bool $opcache): array => (array)json_decode((string)shell_exec(escapeshellarg(PHP_BINARY)
        . ($opcache ? ' -d opcache.enable_cli=1' : '') . ' ' . escapeshellarg(__DIR__ . '/_estado_cache.php') . ' '
        . escapeshellarg($raiz) . ' ' . $paso . ' 2>&1'), true);
    $primero = $correr('leer', true);                   // guarda el estado en su .php
    $otro    = $correr('escribir', false);              // otro proceso, sin OPcache, añade una fila
    foreach ((array)glob($raiz . '/est/t*.json') as $f) {
        touch((string)$f, time() - 30);                 // pasa el tiempo: estable otra vez, con otro sello
    }
    // Con un .php viejo a mano, otra fila: el contador de AUTOINCREMENT está
    // en el estado, y con el viejo repetiría un id (las filas tienen sus propios
    // sellos; el contador, no)
    $tras = $correr('escribir', true);
    // DROP TABLE se lleva su .php: sin la tabla, nada más lo borraría
    foreach ((array)glob($raiz . '/est/t*.json') as $f) {
        touch((string)$f, time() - 20);
    }
    $borrada = $correr('borrar', true);
    return ($primero['n'] ?? 0) === 2 && ($primero['estado'] ?? 0) === 1 && ($otro['n'] ?? 0) === 3 && ($tras['n'] ?? 0) === 4
        && $borrada === ['antes' => 1, 'estado' => 0]
        ?: json_encode([$primero, $otro, $tras, $borrada]);
});
chk('con APCu disponible se usa, y JSONSQLDB_CACHE_APCU = false lo deja de lado: la caché va a disco (2.9)', function () use ($raiz) {
    if (!extension_loaded('apcu')) { return null; }
    exec('rm -rf ' . escapeshellarg($raiz . '/apc'));
    Database::crear('apc', $raiz);
    $bd = new Database('apc', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT)');
    $bd->consultar("INSERT INTO t VALUES (1, 'a')");
    exec('rm -rf ' . escapeshellarg($raiz . '/apc/.cache'));
    $ficheros = static function (bool $apcu) use ($raiz): int {
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);' . ($apcu ? '' : ' define("JSONSQLDB_CACHE_APCU", false);')
            . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . ' (new JsonSQLDB\Database("apc", ' . var_export($raiz, true) . '))->consultar("SELECT * FROM t");'
            . ' echo count((array)glob(' . var_export($raiz . '/apc/.cache/*.cache', true) . '));';
        return (int)shell_exec(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 -r ' . escapeshellarg($codigo));
    };
    $con = $ficheros(true);
    $sin = $ficheros(false);
    exec('rm -rf ' . escapeshellarg($raiz . '/apc'));
    return $con === 0 && $sin > 0 ?: "con APCu $con ficheros, sin APCu $sin";
});

chk('con APCu casi lleno no se le da nada más: una tabla mayor que su memoria no lo vacía (2.9: lo vaciaba en cada recorrido, más lento que sin caché)', function () use ($raiz) {
    if (!extension_loaded('apcu')) { return null; }
    exec('rm -rf ' . escapeshellarg($raiz . '/apcg'));
    Database::crear('apcg', $raiz);
    $bd = new Database('apcg', $raiz);
    $bd->consultar('CREATE TABLE g (id INTEGER PRIMARY KEY, r TEXT)');
    for ($k = 0; $k < 20; $k++) {
        $bd->consultar('INSERT INTO g (r) VALUES ' . implode(', ', array_fill(0, 1000, "('" . str_repeat('r', 300) . "')")));
    }
    // Dos recorridos en un proceso con 4 MB de APCu: la tabla ocupa más del doble
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' $bd = new JsonSQLDB\Database("apcg", ' . var_export($raiz, true) . ');'
        . ' $a = $bd->consultar("SELECT SUM(LENGTH(r)) AS n FROM g"); $b = $bd->consultar("SELECT SUM(LENGTH(r)) AS n FROM g");'
        . ' $i = apcu_cache_info(true);'
        . ' echo json_encode([$a[0]["n"], $b[0]["n"], $i["expunges"], $i["num_entries"] > 0]);';
    $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 -d apc.shm_size=4M -r ' . escapeshellarg($codigo) . ' 2>&1'));
    exec('rm -rf ' . escapeshellarg($raiz . '/apcg'));
    return $r === '[6000000,6000000,0,true]' ?: $r;
});

chk('un UPDATE que toca todas las partes escribe cada una al hacerla: con 16 MB, en 40 partes de 400 KB (2.9: las juntaba todas y agotaba la memoria)', function () use ($raiz) {
    exec('rm -rf ' . escapeshellarg($raiz . '/upd'));
    $base = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_FILAS_POR_PARTE", 100); define("JSONSQLDB_CACHE_ACTIVA", false);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' if (!is_dir(' . var_export($raiz . '/upd', true) . ')) { JsonSQLDB\Database::crear("upd", ' . var_export($raiz, true) . '); }'
        . ' $bd = new JsonSQLDB\Database("upd", ' . var_export($raiz, true) . ');';
    $correr = static fn(string $memoria, string $codigo): string
        => trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$memoria -r " . escapeshellarg($base . $codigo) . ' 2>&1'));
    $correr('256M', '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER, r TEXT)");'
        . ' for ($k = 0; $k < 40; $k++) { $v = []; for ($i = 1; $i <= 100; $i++) { $v[] = "(" . ($k * 100 + $i) . ", 0, \'" . str_repeat("r", 4000) . "\')"; }'
        . ' $bd->consultar("INSERT INTO t VALUES " . implode(", ", $v)); }');
    $r = $correr('16M', 'echo json_encode([$bd->consultar("UPDATE t SET n = n + 1 WHERE id % 100 = 0")["filas"], $bd->consultar("SELECT SUM(n) AS s FROM t")[0]["s"]]);');
    exec('rm -rf ' . escapeshellarg($raiz . '/upd'));
    return $r === '[40,40]' ?: substr($r, 0, 300);
});

chk('un INSERT OR IGNORE … SELECT en una tabla con dos columnas UNIQUE de texto da lo mismo con 16, 32 y 256 MB: si su índice entero no cabe, busca cada clave por los filtros de los trozos (2.9: lo armaba entero y se cortaba)', function () use ($raiz) {
    exec('rm -rf ' . escapeshellarg($raiz . '/uni') . ' ' . escapeshellarg($raiz . '/uni0'));
    $base = static fn(string $dir): string => 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_ACTIVA", false); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' if (!is_dir(' . var_export("$raiz/$dir", true) . ')) { JsonSQLDB\Database::crear("' . $dir . '", ' . var_export($raiz, true) . '); }'
        . ' $bd = new JsonSQLDB\Database("' . $dir . '", ' . var_export($raiz, true) . ');';
    $correr = static fn(string $memoria, string $dir, string $codigo): string
        => trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$memoria -r " . escapeshellarg($base($dir) . $codigo) . ' 2>&1'));
    // t: 40.000 filas; s: 10.000, de ellas 1.000 con un email de t y 500 con un nombre de t, que no entran
    $correr('256M', 'uni0', '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, email TEXT UNIQUE, nombre TEXT UNIQUE)");'
        . ' $bd->consultar("CREATE TABLE s (id INTEGER PRIMARY KEY, email TEXT, nombre TEXT)");'
        . ' for ($k = 0; $k < 40; $k++) { $v = []; for ($i = 1; $i <= 1000; $i++) { $n = $k * 1000 + $i; $v[] = "($n, \'c$n@ejemplo.es\', \'Nombre $n\')"; }'
        . ' $bd->consultar("INSERT INTO t VALUES " . implode(", ", $v)); }'
        . ' for ($k = 0; $k < 10; $k++) { $v = []; for ($i = 1; $i <= 1000; $i++) { $n = $k * 1000 + $i;'
        . ' $v[] = "(" . (100000 + $n) . ", \'" . ($n % 10 === 0 ? "c" . ($n * 3) : "n$n") . "@ejemplo.es\', \'Nombre " . ($n % 20 === 5 ? $n * 3 : "n$n") . "\')"; }'
        . ' $bd->consultar("INSERT INTO s VALUES " . implode(", ", $v)); }');
    $resultados = [];
    foreach (['16M', '32M', '256M'] as $memoria) {
        exec('rm -rf ' . escapeshellarg($raiz . '/uni') . ' && cp -r ' . escapeshellarg($raiz . '/uni0') . ' ' . escapeshellarg($raiz . '/uni'));
        $resultados[$memoria] = $correr($memoria, 'uni', 'echo json_encode([$bd->consultar("INSERT OR IGNORE INTO t SELECT * FROM s")["filas"],'
            . ' array_values($bd->consultar("SELECT COUNT(*) AS n, SUM(id) AS i, SUM(LENGTH(email)) AS e, SUM(LENGTH(nombre)) AS m FROM t")[0])]);');
    }
    exec('rm -rf ' . escapeshellarg($raiz . '/uni') . ' ' . escapeshellarg($raiz . '/uni0'));
    $esperado = json_decode((string)$resultados['256M'], true);
    return is_array($esperado) && $esperado[0] === 8500 && count(array_unique($resultados)) === 1 ?: $resultados;
});

chk('una escritura que no cabe en memoria se hace por tramos: con 16 MB, UPDATE, INSERT … SELECT, upsert y DELETE de 16 MB de filas (2.9: la memoria se agotaba)', function () use ($raiz) {
    exec('rm -rf ' . escapeshellarg($raiz . '/tram'));
    $base = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_FILAS_POR_PARTE", 100); define("JSONSQLDB_CACHE_ACTIVA", false);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' if (!is_dir(' . var_export($raiz . '/tram', true) . ')) { JsonSQLDB\Database::crear("tram", ' . var_export($raiz, true) . '); }'
        . ' $bd = new JsonSQLDB\Database("tram", ' . var_export($raiz, true) . ');';
    $correr = static fn(string $memoria, string $codigo): string
        => trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$memoria -r " . escapeshellarg($base . $codigo) . ' 2>&1'));
    $correr('256M', '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER, r TEXT UNIQUE)"); $bd->consultar("CREATE TABLE c (id INTEGER PRIMARY KEY, n INTEGER, r TEXT)");'
        . ' for ($k = 0; $k < 40; $k++) { $v = []; for ($i = 1; $i <= 100; $i++) { $v[] = "(" . ($k * 100 + $i) . ", 0, \'" . ($k * 100 + $i) . str_repeat("r", 4000) . "\')"; }'
        . ' $bd->consultar("INSERT INTO t VALUES " . implode(", ", $v)); }');
    // Cada sentencia, con lo que dejan las anteriores: n suma 4000 tras el UPDATE,
    // c recibe las 4000 filas, el upsert pone n = 2 en todas y el DELETE quita la mitad
    $r = $correr('16M', '$s = static fn(string $q) => array_values($bd->consultar($q)[0]); echo json_encode(['
        . ' $bd->consultar("UPDATE t SET n = n + 1")["filas"], $s("SELECT SUM(n) AS s FROM t"),'
        . ' $bd->consultar("INSERT INTO c SELECT * FROM t")["filas"], $s("SELECT COUNT(*) AS n, SUM(n) AS s FROM c"),'
        . ' $bd->consultar("INSERT INTO t SELECT id, n + 1, r FROM c WHERE true ON CONFLICT (id) DO UPDATE SET n = excluded.n")["filas"], $s("SELECT COUNT(*) AS n, SUM(n) AS s FROM t"),'
        . ' $bd->consultar("DELETE FROM t WHERE id % 2 = 0")["filas"], $s("SELECT COUNT(*) AS n, SUM(id) AS s FROM t")]);');
    exec('rm -rf ' . escapeshellarg($raiz . '/tram'));
    return $r === '[4000,[4000],4000,[4000,4000],4000,[4000,8000],2000,[2000,4000000]]' ?: substr($r, 0, 300);
});

chk('un INSERT … SELECT en una tabla vacía ve las claves repetidas del origen, también tras escribir un tramo: con 24 y 256 MB (2.9: con el índice vacío no se mira)', function () use ($raiz) {
    exec('rm -rf ' . escapeshellarg($raiz . '/vac'));
    $base = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_FILAS_POR_PARTE", 100); define("JSONSQLDB_CACHE_ACTIVA", false);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' if (!is_dir(' . var_export($raiz . '/vac', true) . ')) { JsonSQLDB\Database::crear("vac", ' . var_export($raiz, true) . '); }'
        . ' $bd = new JsonSQLDB\Database("vac", ' . var_export($raiz, true) . ');';
    $correr = static fn(string $memoria, string $codigo): string
        => trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$memoria -r " . escapeshellarg($base . $codigo) . ' 2>&1'));
    $correr('256M', '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, k TEXT, r TEXT)");'
        . ' for ($k = 0; $k < 40; $k++) { $v = []; for ($i = 1; $i <= 100; $i++) { $v[] = "(" . ($k * 100 + $i) . ", \'k" . ($k * 100 + $i) . "\', \'" . str_repeat("r", 4000) . "\')"; }'
        . ' $bd->consultar("INSERT INTO t VALUES " . implode(", ", $v)); }');
    // La fila repetida va al final: con 24 MB, después de escribir las primeras
    $r = [];
    foreach (['24M', '256M'] as $memoria) {
        foreach (['SELECT * FROM t UNION ALL SELECT 99999, k, \'x\' FROM t WHERE id = 3', 'SELECT * FROM t UNION ALL SELECT 3, \'otra\', \'x\''] as $sel) {
            $r[] = $correr($memoria, '$bd->consultar("DROP TABLE IF EXISTS d"); $bd->consultar("CREATE TABLE d (id INTEGER PRIMARY KEY, k TEXT UNIQUE, r TEXT)");'
                . ' try { $bd->consultar("INSERT INTO d ' . $sel . '"); echo "sin error"; } catch (JsonSQLDB\JsonSqlDbError $e) { echo $e->sqlState; }'
                . ' echo " ", $bd->consultar("SELECT COUNT(*) AS n FROM d")[0]["n"];');
        }
    }
    exec('rm -rf ' . escapeshellarg($raiz . '/vac'));
    return $r === array_fill(0, 4, 'CONSTRAINT 0') ?: $r;
});

echo "\n== Transacciones (2.9) ==\n";
$txBase = static function () use ($raiz): Database {
    exec('rm -rf ' . escapeshellarg($raiz . '/tx') . ' ' . escapeshellarg($raiz . '/listo'));
    Database::crear('tx', $raiz);
    $bd = new Database('tx', $raiz);
    $bd->consultar('CREATE TABLE cuentas (id INTEGER PRIMARY KEY, titular TEXT UNIQUE, saldo DOUBLE)');
    $bd->consultar('CREATE INDEX ix_saldo ON cuentas (saldo)');
    $bd->consultar("INSERT INTO cuentas VALUES (1, 'Ana', 500), (2, 'Luis', 100)");
    return $bd;
};
$txVer = static fn(): array => (new Database('tx', $raiz))->consultar('SELECT id, titular, saldo FROM cuentas ORDER BY id');
chk('transaccion(): todo pasa junto, y dentro cada sentencia ve lo que hicieron las anteriores', function () use ($txBase, $txVer) {
    $bd = $txBase();
    $visto = $bd->transaccion(static function (Database $bd) {
        $bd->consultar('UPDATE cuentas SET saldo = saldo - 100 WHERE id = 1');
        $s = $bd->consultar('SELECT saldo FROM cuentas WHERE id = 1')[0]['saldo'];
        $bd->consultar('UPDATE cuentas SET saldo = saldo + 100 WHERE id = 2');
        return $s;
    });
    return $visto === 400.0 && array_column($txVer(), 'saldo') === [400.0, 200.0] ?: json_encode([$visto, $txVer()]);
});
chk('una sentencia que falla a mitad de sus renombrados en una transacción la deja sin poder confirmarse aunque se capture el error: se deshace entera (2.10: sin diario por sentencia)', function () use ($raiz) {
    exec('rm -rf ' . escapeshellarg($raiz . '/rota'));
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_FILAS_POR_PARTE", 10);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' use JsonSQLDB\Database; Database::crear("rota", ' . var_export($raiz, true) . '); $bd = new Database("rota", ' . var_export($raiz, true) . ');'
        . ' $bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)");'
        . ' $bd->consultar("INSERT INTO t VALUES " . implode(",", array_map(fn($i) => "($i, \'a\')", range(1, 25))));'
        // Donde irá la parte nueva, una carpeta con algo dentro: ese renombrado falla
        . ' mkdir(' . var_export($raiz . '/rota/t.part4.json/x', true) . ', 0775, true);'
        . ' try { $bd->transaccion(function (Database $d) { $d->consultar("UPDATE t SET v = \'b\'");'
        . ' try { $d->consultar("INSERT INTO t VALUES (31, \'c\'), (32, \'c\'), (33, \'c\'), (34, \'c\'), (35, \'c\'), (36, \'c\')"); } catch (Throwable $e) {} });'
        . ' echo "confirmada"; } catch (Throwable $e) { echo "deshecha"; }'
        . ' rmdir(' . var_export($raiz . '/rota/t.part4.json/x', true) . '); rmdir(' . var_export($raiz . '/rota/t.part4.json', true) . ');'
        . ' $bd = new Database("rota", ' . var_export($raiz, true) . ');'
        . ' echo json_encode([$bd->consultar("SELECT v, COUNT(*) AS n FROM t GROUP BY v"), $bd->consultar("CHECK TABLES")]);';
    $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    exec('rm -rf ' . escapeshellarg($raiz . '/rota'));
    return $r === 'deshecha[[{"v":"a","n":25}],[]]' ?: substr($r, 0, 300);
});
chk('transaccion(): una excepción lo deshace todo, también un CREATE TABLE y un DELETE, y la excepción sigue su camino', function () use ($txBase, $txVer, $raiz) {
    $bd = $txBase();
    $antes = $txVer();
    try {
        $bd->transaccion(static function (Database $bd): void {
            $bd->consultar('UPDATE cuentas SET saldo = 0');
            $bd->consultar('DELETE FROM cuentas WHERE id = 2');
            $bd->consultar('CREATE TABLE nueva (x INTEGER)');
            throw new RuntimeException('me arrepiento');
        });
        return 'no lanzó la excepción';
    } catch (RuntimeException $e) {
        $tablas = array_column($bd->consultar('SHOW TABLES'), 'tabla');
        return $e->getMessage() === 'me arrepiento' && $txVer() === $antes && $tablas === ['cuentas'] && !is_dir($raiz . '/tx/.lote')
            ?: json_encode([$txVer(), $tablas]);
    }
});
chk('una tabla cuyo nombre empieza por «_» también cambia de generación al deshacer, y los nombres de los ficheros de la base no se aceptan (2.9)', function () use ($txBase, $raiz) {
    $bd = $txBase();
    $bd->consultar('CREATE TABLE _mov (id INTEGER PRIMARY KEY, n INTEGER)');
    $bd->consultar('INSERT INTO _mov VALUES (1, 10)');
    try {
        $bd->transaccion(static function (Database $bd): void {
            $bd->consultar('UPDATE _mov SET n = 20');
            throw new RuntimeException('no');
        });
    } catch (RuntimeException) {
    }
    $rev = json_decode((string)file_get_contents($raiz . '/tx/_mov.rev.json'), true);
    $reservados = [];
    foreach (['_database', '_VIEWS', '_revs', '_base'] as $n) {
        try { $bd->consultar("CREATE TABLE $n (x INTEGER)"); $reservados[] = "$n creada"; } catch (JsonSqlDbError) { }
    }
    return isset($rev['gen']) && $reservados === [] && is_file($raiz . '/tx/_database.json')
        && json_decode((string)file_get_contents($raiz . '/tx/_database.json'), true)['engine'] === 'jsonSQLDB' ?: json_encode([$rev, $reservados]);
});
chk('lote(): si una sentencia falla, ninguna queda; si todas van bien, devuelve un resultado por sentencia', function () use ($txBase, $txVer) {
    $bd = $txBase();
    $antes = $txVer();
    try { $bd->lote(['UPDATE cuentas SET saldo = saldo * 2', "INSERT INTO cuentas VALUES (3, 'Ana', 1)"]); $mal = 'pasó'; } catch (JsonSqlDbError $e) { $mal = $e->sqlState; }
    $r = $bd->lote([['UPDATE cuentas SET saldo = ? WHERE id = 1', [7]], 'SELECT COUNT(*) AS n FROM cuentas']);
    return $mal === 'CONSTRAINT' && ($r[0]['filas'] ?? null) === 1 && $r[1] === [['n' => 2]] && array_column($txVer(), 'saldo') === [7.0, 100.0]
        && $antes !== $txVer() ?: json_encode([$mal, $r, $txVer()]);
});
chk('después de deshacer, la caché no devuelve nada de lo deshecho aunque las revisiones vuelvan a contar los mismos números (con APCu, que no se limpia por tabla)', function () use ($raiz) {
    if (!extension_loaded('apcu')) { return null; }
    $r = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 ' . escapeshellarg(__DIR__ . '/_deshacer_apcu.php') . ' ' . escapeshellarg($raiz) . ' 2>&1');
    return trim($r) === '["id","titular","saldo"]' ?: $r;
});
chk('deshacer una transacción con la caché en APCu la vacía sin traerla a memoria: con 20 MB en APCu, menos de 8 MB de pico, y lo leído después es lo de antes (2.10: 33 MB)', function () use ($raiz) {
    if (!extension_loaded('apcu')) { return null; }
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
        . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . '; use JsonSQLDB\Database;'
        . '$raiz = ' . var_export($raiz, true) . '; Database::crear("apcu2", $raiz); $bd = new Database("apcu2", $raiz);'
        . '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, b TEXT)");'
        . 'for ($k = 0; $k < 20; $k++) { $v = []; for ($i = 1; $i <= 1000; $i++) { $v[] = "(" . ($k * 1000 + $i) . ", \'" . str_repeat(chr(97 + $i % 26), 1000) . "\')"; } $bd->consultar("INSERT INTO t VALUES " . implode(",", $v)); }'
        // Ficheros de hace un minuto: estables, así que la lectura los guarda en APCu
        . 'foreach (glob("$raiz/apcu2/*") as $f) { touch($f, time() - 60); } clearstatcache();'
        . '$bd = new Database("apcu2", $raiz); $bd->consultar("SELECT COUNT(*) AS n FROM t WHERE b LIKE \'zz%\'");'
        // Sin memory_reset_peak_usage() (PHP 8.0 y 8.1), el pico es también el
        // de la lectura de antes: unos 5 MB, por debajo del tope
        . '$mb = apcu_cache_info(true)["mem_size"] / 1048576; if (function_exists("memory_reset_peak_usage")) { memory_reset_peak_usage(); } $m0 = memory_get_usage();'
        . 'try { $bd->transaccion(function ($bd) { $bd->consultar("UPDATE t SET b = \'x\' WHERE id = 1"); throw new RuntimeException("no"); }); } catch (RuntimeException $e) {}'
        . '$pico = (memory_get_peak_usage() - $m0) / 1048576;'
        . 'echo json_encode([round($mb), round($pico, 1), strlen((string)$bd->consultar("SELECT b FROM t WHERE id = 1")[0]["b"])]);';
    $proc = proc_open([PHP_BINARY, '-d', 'apc.enable_cli=1', '-d', 'apc.shm_size=64M', '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos);
    $salida = (string)stream_get_contents($tubos[1]);
    fclose($tubos[1]);
    proc_close($proc);
    StorageMantenimiento::borrarBase($raiz, 'apcu2');
    $r = json_decode($salida, true);
    return is_array($r) && $r[0] >= 20 && $r[1] < 8 && $r[2] === 1000 ?: $salida;
});
chk('una transacción a medias de un proceso que muere: los demás no ven nada mientras dura, y al abrir la base se deshace', function () use ($txBase, $txVer, $raiz) {
    $txBase();
    $p = proc_open([PHP_BINARY, __DIR__ . '/_transaccion.php', $raiz], [], $tubos);
    for ($i = 0; $i < 500 && !is_file($raiz . '/listo'); $i++) { usleep(20000); }
    $lee = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
         . ' echo json_encode((new JsonSQLDB\Database("tx", ' . var_export($raiz, true) . '))->consultar("SELECT saldo FROM cuentas WHERE id = 1"));';
    $durante = (string)shell_exec('timeout 2 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($lee) . ' 2>&1');
    $vivo = proc_get_status($p)['running'];
    proc_terminate($p, 9);
    proc_close($p);
    $despues = $txVer();
    $tablas = array_column((new Database('tx', $raiz))->consultar('SHOW TABLES'), 'tabla');
    exec('rm -rf ' . escapeshellarg($raiz . '/tx') . ' ' . escapeshellarg($raiz . '/listo'));
    return $durante === '' && $vivo && array_column($despues, 'saldo') === [500.0, 100.0] && $tablas === ['cuentas']
        ?: json_encode([$durante, $vivo, $despues, $tablas]);
});
chk('un lote que solo lee no para a los demás mientras dura, y da lo mismo que antes (2.9: tomaba el bloqueo exclusivo)', function () use ($txBase, $raiz) {
    $bd = $txBase();
    $r = $bd->lote(['BEGIN', 'SELECT COUNT(*) AS n FROM cuentas', ['SELECT saldo FROM cuentas WHERE id = ?', [2]], 'ROLLBACK']);
    $motor = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
           . '$bd = new JsonSQLDB\Database("tx", ' . var_export($raiz, true) . ');';
    $largo = proc_open([PHP_BINARY, '-r', $motor . '$bd->lote(["WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 400000) SELECT COUNT(*) AS n FROM c"]);'], [], $tubos);
    usleep(300000);
    $t0 = microtime(true);
    $otro = (string)shell_exec('timeout 2 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($motor . 'echo json_encode($bd->consultar("SELECT saldo FROM cuentas WHERE id = 1"));') . ' 2>&1');
    $espera = microtime(true) - $t0;
    $seguia = proc_get_status($largo)['running'];
    proc_terminate($largo, 9);
    proc_close($largo);
    exec('rm -rf ' . escapeshellarg($raiz . '/tx'));
    return $r[1] === [['n' => 2]] && $r[2] == [['saldo' => 100]] && count($r) === 4 && $otro === '[{"saldo":500}]' && $seguia
        ?: json_encode([$r, $otro, round($espera, 2), $seguia]);
});
chk('un lote que solo lee no se queda bloqueado con un INSERT que toca dos tablas por su clave foránea (2.9: se esperaban el uno al otro para siempre)', function () use ($raiz) {
    $dir = "$raiz/mutuo";
    exec('rm -rf ' . escapeshellarg($dir));
    Database::crear('m', $dir);
    $bd = new Database('m', $dir);
    $bd->consultar('CREATE TABLE p (id INTEGER PRIMARY KEY)');
    $bd->consultar('CREATE TABLE h (id INTEGER PRIMARY KEY, pid INTEGER, FOREIGN KEY (pid) REFERENCES p (id))');
    $bd->consultar('INSERT INTO p VALUES ' . implode(',', array_map(static fn($i) => "($i)", range(1, 250))));
    $bd->consultar('INSERT INTO h VALUES ' . implode(',', array_map(static fn($i) => "($i, $i)", range(1, 250))));
    $motor = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
           . '$bd = new JsonSQLDB\Database("m", ' . var_export($dir, true) . '); $t = microtime(true);';
    $con = static fn(string $codigo): string => 'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($motor . $codigo . ' echo round(microtime(true) - $t, 1);');
    // El lote lee p, tarda (un cruce de p consigo misma) y luego lee h; el INSERT llega entre medias
    $lector = proc_open($con('$bd->lote(["SELECT COUNT(*) AS a FROM p", "SELECT COUNT(*) AS b FROM p x, p y, p z WHERE x.id + y.id = z.id", "SELECT COUNT(*) AS c FROM h"]);'),
        [1 => ['pipe', 'w']], $t1);
    usleep(700000);
    $escritor = (string)shell_exec($con('$bd->consultar("INSERT INTO h VALUES (1000, 5)");') . ' 2>&1');
    $leido = (string)stream_get_contents($t1[1]);
    fclose($t1[1]);
    $codigo = proc_close($lector);
    $n = (new Database('m', $dir))->consultar('SELECT COUNT(*) AS n FROM h');
    exec('rm -rf ' . escapeshellarg($dir));
    return $codigo === 0 && is_numeric($leido) && is_numeric(trim($escritor)) && $n === [['n' => 251]]
        ?: json_encode(['lector' => [$codigo, $leido], 'escritor' => $escritor, 'h' => $n]);
});
chk('si no se puede guardar el original de un fichero, la transacción se deshace sin dejar el journal de esa sentencia (2.9: quedaba, y un corte ahí guardaba media transacción)', function () use ($txBase, $txVer, $raiz) {
    $bd = $txBase();
    $bd->consultar('CREATE TABLE b (id INTEGER PRIMARY KEY, v TEXT)');
    $bd->consultar("INSERT INTO b VALUES (1, 'x')");
    $antes = $txVer();
    try {
        $bd->transaccion(static function (Database $bd) use ($raiz): void {
            $bd->consultar('UPDATE cuentas SET saldo = 0');
            mkdir($raiz . '/tx/.lote/b.rev.json');      // su original ya no se puede guardar
            $bd->consultar("INSERT INTO b VALUES (2, 'y')");
        });
        $error = 'pasó';
    } catch (JsonSqlDbError $e) {
        $error = $e->getMessage();
    }
    $journal = (array)glob($raiz . '/tx/.tx/*');
    $b = (new Database('tx', $raiz))->consultar('SELECT * FROM b');
    $cuentas = $txVer();
    exec('rm -rf ' . escapeshellarg($raiz . '/tx'));
    return str_contains($error, 'b.rev.json') && $journal === [] && $cuentas === $antes && $b === [['id' => 1, 'v' => 'x']]
        ?: json_encode([$error, $journal, $cuentas, $b]);
});
chk('una sentencia que falla dentro de una transacción no deja nada abierto: la siguiente va bien (2.9: «Ya hay una escritura abierta»)', function () use ($txBase, $txVer, $raiz) {
    $bd = $txBase();
    $antes = $txVer();
    $r = $bd->transaccion(static function (Database $bd): array {
        $r = [];
        foreach (['ALTER TABLE cuentas ADD CONSTRAINT ck CHECK (saldo > 1000)', 'CREATE TABLE z (x INTEGER)'] as $q) {
            try { $bd->consultar($q); $r[] = 'ok'; } catch (JsonSqlDbError $e) { $r[] = $e->sqlState; }
        }
        return $r;
    });
    $tablas = array_column((new Database('tx', $raiz))->consultar('SHOW TABLES'), 'tabla');
    $cuentas = $txVer();
    exec('rm -rf ' . escapeshellarg($raiz . '/tx'));
    return $r === ['CONSTRAINT', 'ok'] && $tablas === ['cuentas', 'z'] && $cuentas === $antes ?: json_encode([$r, $tablas]);
});
chk('si no se puede poner un fichero en su sitio, la escritura se termina al abrir la base; en una transacción, se deshace entera (2.9: la base ya no se abría)', function () use ($raiz) {
    $dir = "$raiz/ren";
    exec('rm -rf ' . escapeshellarg($dir));
    Database::crear('r', $dir);
    $bd = new Database('r', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY)');
    $bd->consultar('INSERT INTO t VALUES (1)');
    $estorbo = static function (string $f) use ($dir): void { mkdir("$dir/r/$f"); touch("$dir/r/$f/x"); };   // un directorio no se reemplaza
    $quitar  = static fn(string $f) => exec('rm -rf ' . escapeshellarg("$dir/r/$f"));
    $tablas  = static fn(): array => array_column((new Database('r', $dir))->consultar('SHOW TABLES'), 'tabla');
    $r = [];
    // Fuera de una transacción
    $estorbo('x.rev.json');
    try { $bd->consultar('CREATE TABLE x (id INTEGER PRIMARY KEY)'); $r[] = 'pasó'; } catch (JsonSqlDbError $e) { $r[] = $e->sqlState; }
    $quitar('x.rev.json');
    $r[] = $tablas();
    // En una transacción: lo de antes de la sentencia también se deshace, y al abrir la base nada vuelve
    $estorbo('y.rev.json');
    try {
        $bd->transaccion(static function (Database $bd) use (&$r): void {
            $bd->consultar('INSERT INTO t VALUES (2)');
            foreach (['CREATE TABLE y (id INTEGER PRIMARY KEY)', 'INSERT INTO t VALUES (3)'] as $q) {
                try { $bd->consultar($q); $r[] = 'pasó'; } catch (JsonSqlDbError $e) { $r[] = $e->sqlState; }
            }
        });
        $r[] = 'pasó';
    } catch (JsonSqlDbError $e) {
        $r[] = $e->sqlState;
    }
    $quitar('y.rev.json');
    $r[] = $tablas();
    $r[] = array_column((new Database('r', $dir))->consultar('SELECT id FROM t'), 'id');
    $r[] = (array)glob("$dir/r/.tx/*");
    exec('rm -rf ' . escapeshellarg($dir));
    return $r === ['IO', ['t', 'x'], 'IO', 'IO', 'IO', ['t', 'x'], [1], []] ?: json_encode($r);
});
chk('en una transacción de INSERT de una fila, un UNIQUE de texto se mira en el texto de los trozos en cada sentencia, sin juntar el índice entero en memoria, y los repetidos se rechazan igual (2.10: desde el noveno lo juntaba en cada uno)', function () use ($raiz) {
    $dir = "$raiz/tx_unico";
    @mkdir($dir, 0775, true);
    Database::crear('u', $dir);
    $bd = new Database('u', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, email TEXT UNIQUE, s TEXT)');
    for ($b = 0; $b < 20000; $b += 2000) {
        $v = [];
        for ($i = $b + 1; $i <= $b + 2000; $i++) {
            $v[] = "($i, 'c$i@e.es', '" . str_repeat('s', 40) . "')";
        }
        $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    }
    unset($bd);
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_LOG_ACTIVO", false);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' $bd = new JsonSQLDB\Database("u", ' . var_export($dir, true) . '); $b = memory_get_usage(); $rechazos = [];'
        . ' $bd->transaccion(function ($bd) use (&$rechazos) {'
        . '   for ($i = 1; $i <= 30; $i++) { $bd->consultar("INSERT INTO t VALUES (?, ?, ?)", [20000 + $i, "n$i@e.es", "x"]); }'
        . '   foreach (["c777@e.es", "n5@e.es", "n30@e.es"] as $e) { try { $bd->consultar("INSERT INTO t VALUES (?, ?, ?)", [30000, $e, "x"]); } catch (Throwable $x) { $rechazos[] = $e; } }'
        . ' });'
        . ' echo json_encode([round((memory_get_peak_usage() - $b) / 1048576, 1), $rechazos, $bd->consultar("SELECT COUNT(*) AS n FROM t")[0]["n"]]);';
    $r = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'), true);
    StorageMantenimiento::borrarBase($dir, 'u');
    @rmdir($dir);
    // Con el índice entero en memoria pasaba de 3 MB
    return is_array($r) && $r[0] < 2 && $r[1] === ['c777@e.es', 'n5@e.es', 'n30@e.es'] && $r[2] === 20030 ?: json_encode($r);
});
chk('dentro de una transacción ningún fichero se fuerza a disco hasta confirmarla, y entonces cada uno una vez; fuera, cada sentencia los suyos (2.10)', function () use ($raiz) {
    if (!function_exists('fsync')) {
        return true;                                // PHP 8.0: no hay fsync()
    }
    $dir = "$raiz/tx_fsync";
    @mkdir($dir, 0775, true);
    Database::crear('s', $dir);
    $bd = new Database('s', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, email TEXT UNIQUE)');
    $antes = StorageEscritura::sincronizados();
    for ($i = 1; $i <= 5; $i++) {
        $bd->consultar('INSERT INTO t VALUES (?, ?)', [$i, "a$i"]);
    }
    $fuera = StorageEscritura::sincronizados() - $antes;
    $antes = StorageEscritura::sincronizados();
    $dentro = null;
    $bd->transaccion(function (Database $bd) use (&$dentro, $antes) {
        for ($i = 6; $i <= 55; $i++) {
            $bd->consultar('INSERT INTO t VALUES (?, ?)', [$i, "a$i"]);
        }
        $dentro = StorageEscritura::sincronizados() - $antes;
    });
    $alConfirmar = StorageEscritura::sincronizados() - $antes - $dentro;
    $n = $bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
    unset($bd);
    StorageMantenimiento::borrarBase($dir, 's');
    @rmdir($dir);
    // Fuera, la parte y el rev.json de cada INSERT; dentro, nada, y al confirmar esos dos
    return $fuera >= 10 && $dentro === 0 && $alConfirmar >= 2 && $alConfirmar <= 4 && $n === 55
        ?: json_encode(compact('fuera', 'dentro', 'alConfirmar', 'n'));
});
chk('dentro de una transacción no se escribe la caché en disco, que cada sentencia dejaba vieja, y lo leído después es lo escrito (2.10)', function () use ($raiz) {
    if (function_exists('apcu_enabled') && apcu_enabled()) {
        return true;                                // con APCu no va al disco
    }
    $dir = "$raiz/tx_cache";
    @mkdir($dir, 0775, true);
    Database::crear('k', $dir);
    $bd = new Database('k', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, email TEXT UNIQUE)');
    $bd->consultar('INSERT INTO t VALUES (1, ?)', ['a1']);
    $bd->consultar('SELECT * FROM t WHERE email = ?', ['a1']);
    $ficheros = static fn(): array => array_map('basename', (array)glob("$dir/k/.cache/*"));
    $antes = $ficheros();
    $bd->transaccion(function (Database $bd) {
        for ($i = 2; $i <= 21; $i++) {
            $bd->consultar('INSERT INTO t VALUES (?, ?)', [$i, "a$i"]);
            $bd->consultar('SELECT COUNT(*) AS n FROM t WHERE email = ?', ["a$i"]);
        }
    });
    $durante = count(array_diff($ficheros(), $antes));         // los nuevos (los viejos sí se borran)
    $filas = $bd->consultar('SELECT id FROM t WHERE email = ?', ['a21']);
    $n = $bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
    unset($bd);
    StorageMantenimiento::borrarBase($dir, 'k');
    @rmdir($dir);
    return $durante === 0 && $filas === [['id' => 21]] && $n === 21 ?: json_encode(compact('antes', 'durante', 'filas', 'n'));
});
chk('una transacción dentro de otra, o crear una base dentro, se rechaza', function () use ($txBase, $raiz) {
    $bd = $txBase();
    $r = [];
    foreach ([static fn(Database $b) => $b->transaccion(static fn() => 1), static fn(Database $b) => $b->consultar('CREATE DATABASE otra')] as $f) {
        try { $bd->transaccion($f); $r[] = 'pasó'; } catch (JsonSqlDbError $e) { $r[] = $e->sqlState; }
    }
    exec('rm -rf ' . escapeshellarg($raiz . '/tx'));
    return $r === ['LOCK', 'LOCK'] ?: json_encode($r);
});

echo "\n== Huecos de filas borradas (2.9) ==\n";
// Una tabla de $n filas en partes de 1000, con tres índices (clave primaria,
// UNIQUE y uno normal)
$huecosBd = static function (int $n = 3000) use ($raiz): Database {
    $dir = "$raiz/huecos";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/h")) { Database::borrar('h', $dir); }
    Database::crear('h', $dir);
    $bd = new Database('h', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, s VARCHAR(20) UNIQUE, n INTEGER)');
    $bd->consultar('CREATE INDEX ix_n ON t (n)');
    $v = [];
    for ($i = 1; $i <= $n; $i++) { $v[] = "('s$i', " . ($i % 50) . ')'; }
    foreach (array_chunk($v, 1000) as $b) { $bd->consultar('INSERT INTO t (s, n) VALUES ' . implode(',', $b)); }
    return $bd;
};
$huellas = static function () use ($raiz): array {
    $out = [];
    foreach ((array)glob("$raiz/huecos/h/t*.json") as $f) { $out[basename((string)$f)] = md5_file((string)$f); }
    return $out;
};
$revH   = static fn(): array => (array)json_decode((string)file_get_contents("$raiz/huecos/h/t.rev.json"), true);
$nulls  = static function () use ($raiz): int {
    $n = 0;
    foreach ((array)glob("$raiz/huecos/h/t*.json") as $f) {
        if (preg_match('/\/t(\.part\d+)?\.json$/', (string)$f)) { $n += preg_match_all('/^\s*null,?$/m', (string)file_get_contents((string)$f)); }
    }
    return $n;
};

chk('un DELETE deja null en su sitio y solo reescribe su parte, sus trozos de índice y la revisión (2.9)', function () use ($huecosBd, $huellas, $revH, $raiz) {
    $bd = $huecosBd();
    $antes = $huellas();
    $bd->consultar('DELETE FROM t WHERE id = 1500');
    $cambiados = array_keys(array_diff_assoc($huellas(), $antes));
    sort($cambiados);
    $linea = str_contains((string)file_get_contents("$raiz/huecos/h/t.part2.json"), "\n    null,\n");
    return $cambiados === ['t.idx.auto_id.part2.json', 't.idx.auto_s.part2.json', 't.idx.ix_n.part2.json', 't.part2.json', 't.rev.json']
        && $linea && ($revH()['huecos'] ?? null) === [0, 1, 0] && $revH()['rows'] === 3000
        ?: json_encode([$cambiados, $linea, $revH()['huecos'] ?? null]);
});
chk('con huecos, COUNT, el orden de inserción, los índices, ORDER BY con LIMIT y OFFSET, e INSERT al final, cuadran (2.9)', function () use ($huecosBd) {
    $bd = $huecosBd();
    $bd->consultar('DELETE FROM t WHERE id IN (1, 999, 1000, 1001, 2000, 3000) OR n = 7');
    $fuera = [1, 999, 1000, 1001, 2000, 3000];
    $esperados = array_values(array_filter(range(1, 3000), static fn($i) => !in_array($i, $fuera, true) && $i % 50 !== 7));
    $ids = array_column($bd->consultar('SELECT id FROM t'), 'id');
    $bd->consultar("INSERT INTO t (s, n) VALUES ('nuevo', 7)");
    $todas = $bd->consultar('SELECT s FROM t');
    $r = [
        $ids === $esperados,
        (int)$bd->consultar('SELECT COUNT(*) AS c FROM t')[0]['c'] === count($esperados) + 1,
        $bd->consultar('SELECT id FROM t WHERE n = 8 ORDER BY id') === $bd->consultar('SELECT id FROM t WHERE n + 0 = 8 ORDER BY id'),
        $bd->consultar("SELECT id FROM t WHERE s = 's1002'") === [['id' => 1002]],
        $bd->consultar("SELECT id FROM t WHERE s = 's1001'") === [],
        array_column($bd->consultar('SELECT id FROM t ORDER BY id LIMIT 3 OFFSET 997'), 'id') === array_slice($esperados, 997, 3),
        array_column($bd->consultar('SELECT id FROM t LIMIT 3 OFFSET 997'), 'id') === array_slice($esperados, 997, 3),
        ($todas[count($todas) - 1]['s'] ?? null) === 'nuevo' && count($todas) === count($esperados) + 1,
    ];
    return !in_array(false, $r, true) ?: json_encode($r);
});
chk('al pasar del 25 % de huecos la tabla se compacta sola: sin null en disco ni huecos en la revisión, y todo cuadra (2.9)', function () use ($huecosBd, $revH, $nulls) {
    $bd = $huecosBd();
    $bd->consultar('DELETE FROM t WHERE id <= 700');          // 700 de 3000: huecos
    $con = [array_sum($revH()['huecos'] ?? []), $nulls()];
    $bd->consultar('DELETE FROM t WHERE id <= 800');          // 800 > 750: se compacta
    return $con === [700, 700] && !isset($revH()['huecos']) && $nulls() === 0 && $revH()['rows'] === 2200
        && $bd->consultar("SELECT id FROM t WHERE s = 's801'") === [['id' => 801]]
        && $bd->consultar('SELECT id FROM t WHERE n = 3 ORDER BY id') === $bd->consultar('SELECT id FROM t WHERE n + 0 = 3 ORDER BY id')
        && (int)$bd->consultar('SELECT COUNT(*) AS c FROM t')[0]['c'] === 2200 ?: json_encode([$con, $revH()['huecos'] ?? null, $nulls()]);
});
chk('VACUUM compacta las tablas con huecos, y si no hay, no toca nada (2.9)', function () use ($huecosBd, $revH, $nulls, $huellas) {
    $bd = $huecosBd();
    $bd->consultar('DELETE FROM t WHERE n = 9');              // 60 huecos
    $r1 = $bd->consultar('VACUUM');
    $tras = $huellas();
    $r2 = $bd->consultar('VACUUM');
    return $r1['filas'] === 60 && !isset($revH()['huecos']) && $nulls() === 0 && $r2['filas'] === 0 && $huellas() === $tras
        && (int)$bd->consultar('SELECT COUNT(*) AS c FROM t')[0]['c'] === 2940
        && $bd->consultar("SELECT n FROM t WHERE s = 's2999'") === [['n' => 49]] ?: json_encode([$r1, $r2, $nulls()]);
});
chk('una parte con todas sus filas borradas deja un trozo de índice vacío que sigue valiendo: el INSERT siguiente no reescribe la tabla (2.9)', function () use ($huecosBd, $huellas, $raiz) {
    $bd = $huecosBd(5000);
    $bd->consultar('DELETE FROM t WHERE id BETWEEN 1001 AND 2000');   // la parte 2 entera: 20 %
    $vacio = str_ends_with((string)file_get_contents("$raiz/huecos/h/t.idx.ix_n.part2.json"), "\"keys\":{}}\n");
    $antes = $huellas();
    $bd->consultar("INSERT INTO t (s, n) VALUES ('tras', 1)");
    $cambiados = array_keys(array_diff_assoc($huellas(), $antes));
    $tocadas = preg_grep('/^t(\.part\d+)?\.json$/', $cambiados);
    return $vacio && array_values($tocadas) === ['t.part6.json'] && $bd->consultar("SELECT id FROM t WHERE s = 'tras'") === [['id' => 5001]]
        ?: json_encode([$vacio, $cambiados]);
});
chk('los huecos que quedarían al final no se dejan: la tabla se acorta, también con los de antes y de una parte a otra, y una cola no los acumula (2.9)', function () use ($huecosBd, $revH, $nulls, $huellas) {
    $bd = $huecosBd(2005);                                    // partes de 1000, 1000 y 5
    $bd->consultar('DELETE FROM t WHERE id IN (1998, 1999, 2000)');   // huecos al final de la parte 2
    $antes = [$revH()['rows'], $revH()['huecos'] ?? null];
    $bd->consultar('DELETE FROM t WHERE id > 2000');          // la parte 3 entera, y con ella los tres huecos
    $ficheros = array_keys($huellas());
    $tras = [$revH()['rows'], $revH()['huecos'] ?? null, count($revH()['parts']), $nulls(), preg_grep('/part3/', $ficheros)];
    $consultas = [
        $bd->consultar('SELECT COUNT(*) AS c, MAX(id) AS m FROM t'), $bd->consultar("SELECT id FROM t WHERE s = 's1997'"),
        $bd->consultar("SELECT id FROM t WHERE s = 's2001'"), $bd->consultar('CHECK TABLES'), $bd->consultar('CHECK KEYS'),
    ];
    $iguales = $bd->consultar('SELECT id FROM t WHERE n = 47 ORDER BY id') === $bd->consultar('SELECT id FROM t WHERE n + 0 = 47 ORDER BY id');
    // Una cola: se añade al final y se borra enseguida; las partes de antes no se tocan
    $partes = array_intersect_key($huellas(), ['t.json' => 1, 't.part2.json' => 1]);
    for ($i = 0; $i < 5; $i++) {
        $bd->consultar("INSERT INTO t (s, n) VALUES ('cola$i', 1)");
        $bd->consultar("DELETE FROM t WHERE s = 'cola$i'");
    }
    $cola = [$revH()['rows'], $nulls(), array_intersect_key($huellas(), $partes) === $partes];
    $bd->consultar("INSERT INTO t (s, n) VALUES ('tras', 1)");
    $nueva = $bd->consultar("SELECT id FROM t WHERE s = 'tras'");
    $esperado = [$antes, $tras, $consultas, $iguales, $cola, $nueva] === [[2005, [0, 3, 0]], [1997, null, 2, 0, []],
        [[['c' => 1997, 'm' => 1997]], [['id' => 1997]], [], [], []], true, [1997, 0, true], [['id' => 2011]]];
    return $esperado ?: json_encode([$antes, $tras, $consultas, $iguales, $cola, $nueva]);
});
chk('en una parte de antes de la 2.7, sin desfases, borrar la última fila también acorta la tabla (2.9)', function () use ($huecosBd, $revH, $raiz) {
    $huecosBd(20);
    $f = "$raiz/huecos/h/t.json";
    // Como la escribía la 2.6: una fila por línea y sin offsets
    $filas = json_decode((string)file_get_contents($f), true)['rows'];
    file_put_contents($f, "{\n  \"table\": \"t\",\n  \"rows\": [\n    "
        . implode(",\n    ", array_map(static fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $filas)) . "\n  ]\n}\n");
    touch($f, time() - 60);
    exec('rm -rf ' . escapeshellarg("$raiz/huecos/h/.cache"));
    $bd = new Database('h', "$raiz/huecos");
    $bd->consultar('DELETE FROM t WHERE id = 20');
    $r = [$revH()['rows'], $revH()['huecos'] ?? null, count(json_decode((string)file_get_contents($f), true)['rows']),
          $bd->consultar('SELECT COUNT(*) AS c, MAX(id) AS m FROM t'), $bd->consultar('SELECT id FROM t WHERE id = 20')];
    return $r === [19, null, 19, [['c' => 19, 'm' => 19]], []] ?: json_encode($r);
});
chk('CHECK TABLES no da los huecos por un problema (2.9)', function () use ($huecosBd) {
    $bd = $huecosBd();
    $bd->consultar('DELETE FROM t WHERE id IN (5, 1500, 2999)');
    return $bd->consultar('CHECK TABLES') === [] ?: json_encode($bd->consultar('CHECK TABLES'));
});
chk('con OPcache y con APCu, una tabla con huecos se lee igual (2.9)', function () use ($huecosBd, $raiz) {
    $bd = $huecosBd();
    $bd->consultar('DELETE FROM t WHERE n IN (2, 3)');
    $esperado = json_encode([$bd->consultar('SELECT COUNT(*) AS c FROM t'), $bd->consultar('SELECT id FROM t WHERE n = 4 LIMIT 5 OFFSET 40'),
                             $bd->consultar("SELECT s FROM t WHERE id = 1503")]);
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$bd = new JsonSQLDB\\Database("h", ' . var_export("$raiz/huecos", true) . ');'
            . 'for ($i = 0; $i < 2; $i++) { $r = json_encode([$bd->consultar("SELECT COUNT(*) AS c FROM t"), $bd->consultar("SELECT id FROM t WHERE n = 4 LIMIT 5 OFFSET 40"),'
            . ' $bd->consultar("SELECT s FROM t WHERE id = 1503")]); } echo $r;';
    foreach ((array)glob("$raiz/huecos/h/t*.json") as $f) { touch((string)$f, time() - 60); }   // estables: van a OPcache
    $mal = [];
    foreach (['-d opcache.enable_cli=1', '-d apc.enable_cli=1'] as $modo) {
        $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " $modo -r " . escapeshellarg($codigo) . ' 2>&1'));
        if ($r !== $esperado) { $mal[] = "$modo: $r"; }
    }
    return $mal === [] ?: implode(' | ', $mal);
});
chk('la caché en disco: cada escritura borra por su nombre lo que deja atrás, cada 32 revisiones se barre lo demás y DROP TABLE no deja nada (2.9)', function () use ($huecosBd, $revH, $raiz) {
    $bd    = $huecosBd();
    $cache = "$raiz/huecos/h/.cache";
    // Otra conexión que lo lee todo: estructura, partes y trozos de índice
    // van a la caché, y el SELECT * también a la de resultados
    $leer = static function () use ($raiz): void {
        $b = new Database('h', "$raiz/huecos");
        $b->consultar('SELECT * FROM t');
        $b->consultar("SELECT id FROM t WHERE s = 's1500'");
        $b->consultar('SELECT id FROM t WHERE n = 7');
    };
    // Las entradas que no son de la revisión actual de lo suyo
    $viejas = static function () use ($cache, $revH): array {
        $e = $revH();
        $out = [];
        foreach ((array)glob("$cache/*") as $f) {
            $n = basename((string)$f);
            if (!preg_match('/^\w{32}\.(m|p(\d+)|x(.+)p(\d+))-(\d+)\./', $n, $m)) { continue; }
            $actual = match (true) {
                $m[1] === 'm' => $e['rev'],
                $m[2] !== ''  => $e['parts'][(int)$m[2] - 1] ?? null,
                default       => $e['indexes'][$m[3]][(int)$m[4] - 1] ?? null,
            };
            if ((int)$m[5] !== $actual) { $out[] = $n; }
        }
        return $out;
    };
    $leer();
    // Una entrada de una revisión pasada que no deja atrás ninguna escritura:
    // la de una lectura que se cruzó con una. Se quita en el barrido
    $p1 = preg_grep('/^\w{32}\.p1-\d+\.cache$/', array_map('basename', (array)glob("$cache/*")));
    if ($p1 === []) { return 'la lectura no dejó la parte 1 en la caché'; }
    $sobra = (string)preg_replace('/-\d+\.cache$/', '-0.cache', (string)reset($p1));
    touch("$cache/$sobra");
    $mal = [];
    for ($i = 0; $revH()['rev'] % 32 !== 31; $i++) {
        $bd->consultar('UPDATE t SET n = n + 1 WHERE id = ' . (1 + $i % 3 * 1000));   // cada vez otra parte
        $leer();
        if ($viejas() !== [$sobra]) { $mal[] = 'rev ' . $revH()['rev'] . ': ' . implode(',', $viejas()); break; }
    }
    $bd->consultar('UPDATE t SET n = n + 1 WHERE id = 2');                       // la revisión 32: se barre
    $barrido = $viejas();
    $bd->consultar('DROP TABLE t');
    $quedan = array_map('basename', (array)glob("$cache/*"));
    return $mal === [] && $barrido === [] && $quedan === [] ?: json_encode([$mal, $barrido, $quedan]);
});
chk('UPDATE OR REPLACE de una tabla que no cabe en la memoria, escrito por tramos, no devuelve las filas que borró: las mismas que SQLite (2.10)', function () use ($raiz) {
    // 20.000 filas de 300 bytes con 32 MB: la sentencia se escribe por tramos,
    // y una fila que borró y aún estaba por leer volvía con su clave
    $dir    = "$raiz/tramos";
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$d = ' . var_export($dir, true) . '; @mkdir($d, 0775, true); JsonSQLDB\\Database::crear("b", $d); $b = new JsonSQLDB\\Database("b", $d);'
            . '$b->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, u INTEGER UNIQUE, x TEXT)"); $x = str_repeat("x", 300);'
            . 'for ($i = 0; $i < 20; $i++) { $v = []; for ($j = 1; $j <= 1000; $j++) { $n = $i * 1000 + $j; $v[] = "($n, $n, \'$x\')"; }'
            . ' $b->consultar("INSERT INTO t VALUES " . implode(",", $v)); }'
            . '$r = $b->consultar("UPDATE OR REPLACE t SET u = u + 1");'
            . 'echo json_encode([$r["filas"], $b->consultar("SELECT COUNT(*) AS n, SUM(u) AS s FROM t")[0]]);';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M -r ' . escapeshellarg($codigo) . ' 2>&1'));
    exec('rm -rf ' . escapeshellarg($dir));
    // En SQLite: cada fila impar pasa a la clave de la par siguiente, que se borra
    return $salida === json_encode([10000, ['n' => 10000, 's' => 100010000]]) ?: $salida;
});
chk('en una transacción, una sentencia que falla después de escribir parte por tramos no se queda a medias aunque se capture el error: la transacción ya solo se puede deshacer; sin tramos, el error capturado no impide confirmarla (2.10: se confirmaba con 17.664 de 20.000 filas cambiadas)', function () use ($raiz) {
    $dir    = "$raiz/tramos_tx";
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$d = ' . var_export($dir, true) . ' . "/" . ini_get("memory_limit"); @mkdir($d, 0775, true); JsonSQLDB\\Database::crear("b", $d); $b = new JsonSQLDB\\Database("b", $d);'
            . '$b->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, u INTEGER UNIQUE, x TEXT)"); $x = str_repeat("x", 300);'
            . 'for ($i = 0; $i < 20; $i++) { $v = []; for ($j = 1; $j <= 1000; $j++) { $n = $i * 1000 + $j; $v[] = "($n, $n, \'$x\')"; }'
            . ' $b->consultar("INSERT INTO t VALUES " . implode(",", $v)); }'
            . '$r = [];'
            . 'foreach ([true, false] as $sigue) { try { $b->transaccion(function () use ($b, $sigue, &$r) {'
            . '  try { $b->consultar("UPDATE t SET x = \'y\', u = CASE WHEN id = 19999 THEN 1 ELSE u END"); } catch (JsonSQLDB\\JsonSqlDbError $e) { $r[] = "capturado"; }'
            . '  if ($sigue) { $b->consultar("INSERT INTO t VALUES (30000, 30000, \'z\')"); } }); $r[] = "confirmada"; }'
            . ' catch (JsonSQLDB\\JsonSqlDbError $e) { $r[] = "deshecha"; } }'
            . '$r[] = $b->consultar("SELECT COUNT(*) AS n FROM t WHERE x = \'y\'")[0]["n"]; $r[] = $b->consultar("SELECT COUNT(*) AS n FROM t")[0]["n"];'
            . 'echo json_encode($r);';
    $salida = [];
    foreach (['32M', '256M'] as $m) {
        $salida[$m] = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$m -r " . escapeshellarg($codigo) . ' 2>&1'));
    }
    exec('rm -rf ' . escapeshellarg($dir));
    // Con 32 MB escribe por tramos: las dos se deshacen. Con 256 MB, de una vez:
    // el error deja la tabla como estaba y la transacción se confirma
    return $salida === ['32M' => '["capturado","deshecha","capturado","deshecha",0,20000]',
                        '256M' => '["capturado","confirmada","capturado","confirmada",0,20001]'] ?: json_encode($salida);
});
chk('en una transacción, cada fichero que ya reemplazó una sentencia anterior se escribe en su sitio, sin temporal ni renombrado (salvo en PHP 8.0); deshacerla devuelve los originales (2.10)', function () use ($raiz) {
    $dir = "$raiz/en_sitio";
    @mkdir($dir, 0775, true);
    Database::crear('s', $dir);
    $bd = new Database('s', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, email TEXT UNIQUE)');
    $bd->consultar("INSERT INTO t VALUES (1, 'a1'), (2, 'a2'), (3, 'a3')");
    $inodos = static function () use ($dir): array {
        clearstatcache();
        $r = [];
        foreach ((array)glob("$dir/s/t.*json") as $f) {
            $r[basename((string)$f)] = fileinode((string)$f);
        }
        return $r;
    };
    $antes = $inodos();
    $tras1 = $tras3 = [];
    $bd->transaccion(function (Database $bd) use (&$tras1, &$tras3, $inodos) {
        $bd->consultar("INSERT INTO t VALUES (4, 'a4')");
        $tras1 = $inodos();
        $bd->consultar("INSERT INTO t VALUES (5, 'a5')");
        $bd->consultar("UPDATE t SET email = 'b5' WHERE id = 5");
        $tras3 = $inodos();
    });
    try {
        $bd->transaccion(function (Database $bd) {
            $bd->consultar("INSERT INTO t VALUES (6, 'a6')");
            $bd->consultar("INSERT INTO t VALUES (7, 'a7')");
            $bd->consultar('DELETE FROM t WHERE id = 1');
            throw new RuntimeException('deshacer');
        });
    } catch (RuntimeException $e) {
    }
    $filas = $bd->consultar('SELECT id, email FROM t ORDER BY id');
    $porEmail = $bd->consultar("SELECT id FROM t WHERE email = 'a7' OR email = 'b5'");
    unset($bd);
    StorageMantenimiento::borrarBase($dir, 's');
    @rmdir($dir);
    $esperadas = [[1, 'a1'], [2, 'a2'], [3, 'a3'], [4, 'a4'], [5, 'b5']];
    // En PHP 8.0, que no puede forzarlos a disco al confirmar, se renombran como siempre
    $enSitio = function_exists('fsync') ? $tras3 === $tras1 : $tras3 !== $tras1;
    return $tras1 !== $antes && $enSitio && array_map('array_values', $filas) === $esperadas && $porEmail === [['id' => 5]]
        ?: json_encode(compact('antes', 'tras1', 'tras3', 'filas', 'porEmail'));
});
chk('en una transacción, un fallo al escribir en su sitio un solo fichero la deja solo para deshacer, y sin leer en ella; un renombrado que falla deja el fichero como estaba, y lo que viene después no se escribe en su sitio sobre el original guardado; un original que no se puede devolver al deshacerla no deja la conexión dentro; lo que se guardó antes de un fallo está en el manifiesto, y lo que no llegó a él se guarda otra vez (2.10)', function () use ($raiz) {
    $dir = "$raiz/fallo_escritura";
    $r   = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_fallo_escritura.php') . ' ' . escapeshellarg($dir) . ' 2>&1'));
    @rmdir($dir);
    // En PHP 8.0 no se escribe en su sitio: falla el temporal, y la sentencia no deja nada
    $escribir = function_exists('fsync') ? '"v2: error","lectura: error","deshecha","v0"' : '"v2: error","lectura: 2","confirmada","v0,v1"';
    return $r === '[' . $escribir . ',"v1: error","deshecha a mano","v0","deshacer: error","antes",2,"insert: error",'
        . '"manifiesto: t.json,t.idx.auto_id.json,t.idx.auto_u.json","deshecha a mano","insert: error","manifiesto: t.json,t.rev.json","deshecha a mano","antes"]' ?: $r;
});
chk('en una transacción, una sentencia que pasa de la memoria sin haber escrito nada por tramos y luego falla no la deja solo para deshacer: con 32 y 256 MB (2.10)', function () use ($raiz) {
    $dir    = "$raiz/tramo_vacio";
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$d = ' . var_export($dir, true) . ' . "/" . ini_get("memory_limit"); @mkdir($d, 0775, true); JsonSQLDB\\Database::crear("b", $d); $b = new JsonSQLDB\\Database("b", $d);'
            . '$b->consultar("CREATE TABLE p (id INTEGER PRIMARY KEY)");'
            . '$b->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, u INTEGER UNIQUE, x TEXT, f INTEGER REFERENCES p(id))"); $x = str_repeat("x", 300);'
            . 'for ($i = 0; $i < 20; $i++) { $v = []; for ($j = 1; $j <= 1000; $j++) { $n = $i * 1000 + $j; $v[] = "($n, $n, \'$x\', NULL)"; }'
            . ' $b->consultar("INSERT INTO t VALUES " . implode(",", $v)); }'
            . '$r = [];'
            // Todas las filas chocan y se saltan; la última, nueva, no tiene padre: error al acabar
            . 'try { $b->transaccion(function () use ($b, &$r) {'
            . '  try { $b->consultar("INSERT OR IGNORE INTO t SELECT CASE WHEN id = 20000 THEN 50000 ELSE id END, CASE WHEN id = 20000 THEN 50000 ELSE u END, x, CASE WHEN id = 20000 THEN 99 ELSE NULL END FROM t"); }'
            . '  catch (JsonSQLDB\\JsonSqlDbError $e) { $r[] = "capturado"; }'
            . '  $b->consultar("INSERT INTO t VALUES (30000, 30000, \'z\', NULL)"); }); $r[] = "confirmada"; }'
            . ' catch (JsonSQLDB\\JsonSqlDbError $e) { $r[] = "deshecha"; }'
            . '$r[] = $b->consultar("SELECT COUNT(*) AS n FROM t")[0]["n"];'
            . 'echo json_encode($r);';
    $salida = [];
    foreach (['32M', '256M'] as $m) {
        $salida[$m] = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$m -r " . escapeshellarg($codigo) . ' 2>&1'));
    }
    exec('rm -rf ' . escapeshellarg($dir));
    return $salida === ['32M' => '["capturado","confirmada",20001]', '256M' => '["capturado","confirmada",20001]'] ?: json_encode($salida);
});
chk('un DELETE escrito por tramos con ON DELETE CASCADE hacia una hija sin índice (cargada) borra justo sus hijas, también si un trigger hace cargar la tabla que borra y luego borra otra por tramos; y un UPDATE por tramos cuya cascada da a una hija la clave única que otra tuvo antes del tramo no ve un duplicado: 3.000 filas, tramos forzados (2.10: desde la 2.9, un tramo escribía sin huecos las tablas cargadas, que se seguían usando por sus posiciones de antes, y quedaban huérfanas y se borraban otras filas)', function () use ($raiz) {
    $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_tramo_forzado.php') . ' ' . escapeshellarg("$raiz/tramo_forzado") . ' 2>&1'));
    return $r === '[[1500,1500,0,3000,true],[1499,1499,0,0,true],[[1,10,20],[2,10,2]]]' ?: $r;
});
chk('una cascada ON UPDATE hacia una hija con trigger y un REPLACE de una fila padre no cargan la tabla padre entera: 60.000 filas en 40 MB (2.10, ver WriterForaneas::padreTiene())', function () use ($raiz) {
    $r = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_padre_grande.php') . ' ' . escapeshellarg("$raiz/padre_grande") . ' 2>&1'));
    return $r === '["ok","ok",300]' ?: $r;
});
chk('la carpeta temporal queda vacía y se elimina', function () use ($raiz) {
    if (is_dir("$raiz/est")) { Database::borrar('est', $raiz); }
    if (is_dir("$raiz/huecos/h")) { Database::borrar('h', "$raiz/huecos"); }
    @rmdir("$raiz/huecos");
    clearstatcache();
    return @rmdir($raiz) && !is_dir($raiz) ?: 'quedan: ' . implode(',', array_diff((array)scandir($raiz), ['.', '..']));
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
