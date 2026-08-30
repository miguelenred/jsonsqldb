<?php
declare(strict_types=1);

/**
 * Prueba de escrituras y DDL. Ejecutar: php tests/f3_escrituras.php
 * Crea una base temporal y la borra al terminar.
 */
// Las pruebas usan el motor directamente, sin pasar por la API
define('JSONSQLDB_CONEXION_DIRECTA', true);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Storage;

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

chk('un UPDATE masivo crece de forma lineal, no cuadrática', function () use ($raiz) {
    // Tres costes ocultos lo volvían cuadrático: buscar cada fila recorriendo
    // la tabla, listar el directorio una vez por fila para ver quién
    // referencia la tabla, y copiar el array entero en cada iteración por el
    // copy-on-write de PHP. Con 8.000 filas eran 1,2 segundos.
    //
    // Se mide el coste POR FILA al doblar el tamaño: si es cuadrático, se
    // dobla; si es lineal, se queda parecido. Un factor 2,5 deja margen de
    // sobra para el ruido de una máquina compartida y aun así delata un O(n²),
    // que daría 2 limpio y subiendo.
    $porFila = [];
    foreach ([1000, 4000] as $n) {
        $dir = $raiz . '/masivo' . $n;
        @mkdir($dir, 0775, true);
        Database::crear('m', $dir);
        $bd = new Database('m', $dir);
        $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, ciudad VARCHAR(20), v INTEGER)');
        $vals = [];
        for ($i = 1; $i <= $n; $i++) { $vals[] = "($i,'Madrid',0)"; }
        foreach (array_chunk($vals, 2000) as $b) {
            $bd->consultar('INSERT INTO t VALUES ' . implode(',', $b));
        }
        $t0 = microtime(true);
        $bd->consultar("UPDATE t SET v = 1 WHERE ciudad = 'Madrid'");
        $porFila[$n] = ((microtime(true) - $t0) * 1000) / $n;

        $tocadas = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t WHERE v = 1')[0]['n'];
        unset($bd);
        Database::borrar('m', $dir);
        @rmdir($dir);
        if ($tocadas !== $n) {
            return "el UPDATE de $n filas tocó $tocadas";
        }
    }
    $factor = $porFila[1000] > 0 ? $porFila[4000] / $porFila[1000] : 0;
    return $factor < 2.5
        ?: sprintf('el coste por fila se multiplicó por %.1f al cuadruplicar (%.4f -> %.4f ms)',
                   $factor, $porFila[1000], $porFila[4000]);
});

chk('un DELETE masivo también', function () use ($raiz) {
    $porFila = [];
    foreach ([1000, 4000] as $n) {
        $dir = $raiz . '/masivod' . $n;
        @mkdir($dir, 0775, true);
        Database::crear('m', $dir);
        $bd = new Database('m', $dir);
        $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, ciudad VARCHAR(20))');
        $vals = [];
        for ($i = 1; $i <= $n; $i++) { $vals[] = "($i,'Madrid')"; }
        foreach (array_chunk($vals, 2000) as $b) {
            $bd->consultar('INSERT INTO t VALUES ' . implode(',', $b));
        }
        $t0 = microtime(true);
        $bd->consultar("DELETE FROM t WHERE ciudad = 'Madrid'");
        $porFila[$n] = ((microtime(true) - $t0) * 1000) / $n;

        $quedan = (int)$bd->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
        unset($bd);
        Database::borrar('m', $dir);
        @rmdir($dir);
        if ($quedan !== 0) {
            return "el DELETE de $n filas dejó $quedan sin borrar";
        }
    }
    $factor = $porFila[1000] > 0 ? $porFila[4000] / $porFila[1000] : 0;
    return $factor < 2.5
        ?: sprintf('el coste por fila se multiplicó por %.1f al cuadruplicar', $factor);
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
