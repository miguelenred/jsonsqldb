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

if (is_dir("$raiz/$base")) { Storage::borrarBase($raiz, $base); }
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
            ciudad  TEXT          DEFAULT 'Torrevieja',
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
error('CHECK no soportado', 'SYNTAX', 'CREATE TABLE t (a INTEGER CHECK (a > 0))');
error('tipo desconocido', 'TYPE', 'CREATE TABLE t (a GEOMETRY)');

echo "\n== INSERT ==\n";
chk('INSERT con autoincremento y valores por defecto', function () use ($bd) {
    $r = $bd->consultar("INSERT INTO clientes (nombre, email) VALUES ('Ana', 'ana@x.es')");
    $f = $bd->consultar('SELECT id, nombre, saldo, ciudad FROM clientes');
    return $r['filas'] === 1 && $f[0]['id'] === 1 && $f[0]['saldo'] === 0.0 && $f[0]['ciudad'] === 'Torrevieja';
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
chk('un DELETE en medio mueve las filas por bloques de texto y deja los mismos ficheros e índices que reescribiendo (2.8)', function () use ($raiz) {
    // Al borrar, las filas de detrás se mueven como líneas de texto, sin
    // decodificarlas, y sus claves de índice salen de los trozos de antes
    // (Storage::recolocarPorBloques()). Cada parte tiene que quedar byte a
    // byte como la escribiría la escritura completa, y cada índice igual que
    // si se rehiciera de las filas
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
    $antes = Storage::cuentaBloques();
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
    unset($bd);
    Database::borrar('e', $dir);
    @rmdir($dir);
    // Los cinco por bloques, no por el camino de siempre (al que se vuelve si
    // algo no tiene la forma esperada, y que daría lo mismo)
    $porBloques = Storage::cuentaBloques() - $antes;
    if ($porBloques < 4) { return "solo $porBloques borrados fueron por bloques"; }
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
    foreach ($vals as [$id, $sv, $dv, $nv]) { $esperado[] = ['id' => $id, 'texto' => $sv, 'd' => $dv, 'nuevo' => 'x "y"']; }
    $antes = Storage::cuentaBloques();
    foreach (["ALTER TABLE t ADD COLUMN nuevo TEXT DEFAULT 'x \"y\"'", 'ALTER TABLE t DROP COLUMN n', 'ALTER TABLE t RENAME COLUMN s TO texto'] as $q) {
        $bd->consultar($q);
    }
    $porBloques = Storage::cuentaBloques() - $antes;
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
    return $f[0]['saldo'] === 30.5 && $f[0]['ciudad'] === 'Torrevieja';
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
    return $bd->catalogo()->triggers('auditoria', 'AFTER', 'INSERT') === [];
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
    // mismas filas, y se exige que ningún fichero cambie ni un byte. Si el
    // cálculo de qué partes tocar se equivoca, la que no se reescribió se queda
    // con datos viejos y aquí se ve.
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
        // Sin decirle qué cambió: rehace todas las partes
        $st->guardarTabla('pp', $st->leerFilas('pp', true), null, Indexes::definiciones($meta));
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
        } catch (JsonSQLDB\JsonSqlDbError $e) {
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
        try { $bd->consultar($q); $mal[] = $q; } catch (JsonSQLDB\JsonSqlDbError $e) { }
    }
    $ahora = $bd->consultar('SELECT CURRENT_TIMESTAMP AS a, CURRENT_DATE AS b, CURRENT_TIME AS c')[0];
    Database::borrar($nombre, $raiz);
    return $mal === [] && strlen($ahora['a']) === 19 && strlen($ahora['b']) === 10 && strlen($ahora['c']) === 8
        ?: 'admitió: ' . implode(' | ', $mal) . ' · ' . json_encode($ahora);
});

echo "\n== Limpieza ==\n";
chk('sin ficheros temporales y base borrada', function () use ($raiz, $base) {
    $restos = glob("$raiz/$base/*.tmp");
    Database::borrar($base, $raiz);
    @rmdir($raiz);
    return $restos === [] && !is_dir("$raiz/$base");
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
