<?php
declare(strict_types=1);

/**
 * Prueba de las sentencias SHOW y de las restricciones añadidas sobre tablas
 * ya creadas. Ejecutar: php tests/f5_esquema.php
 */
require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Storage;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f5';
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
    chk($titulo, static function () use ($estado, $fn) {
        try { $fn(); } catch (JsonSqlDbError $e) { return $e->sqlState === $estado ?: $e->sqlState . ': ' . $e->getMessage(); }
        return 'no lanzó error';
    });
}

// --- Entorno ---
if (is_dir($raiz)) {
    foreach (Storage::bases($raiz) as $b) { Storage::borrarBase($raiz, $b); }
}
@mkdir($raiz, 0775, true);
Database::crear('tienda', $raiz);
Database::crear('otra', $raiz);
$bd = new Database('tienda', $raiz);

$bd->consultar('CREATE TABLE clientes (
    id     INTEGER PRIMARY KEY AUTOINCREMENT,
    cod    VARCHAR(10) NOT NULL,
    nombre VARCHAR(50),
    saldo  DECIMAL(10,2) DEFAULT 0)');
$bd->consultar('CREATE TABLE pedidos (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    cliente_id INTEGER,
    ref        VARCHAR(20),
    total      DECIMAL(10,2))');
$bd->consultar("INSERT INTO clientes (cod, nombre, saldo) VALUES ('A','Ana',10.5),('B','Luis',0)");
$bd->consultar("INSERT INTO pedidos (cliente_id, ref, total) VALUES (1,'R1',5),(2,'R2',7)");

echo "\n== SHOW ==\n";
chk('SHOW DATABASES', function () use ($bd) {
    $b = array_column($bd->consultar('SHOW DATABASES'), 'base');
    sort($b);
    return $b === ['otra', 'tienda'];
});
chk('CREATE y DROP DATABASE por SQL', function () use ($bd, $raiz) {
    $bd->consultar('CREATE DATABASE nueva');
    $hay = fn() => in_array('nueva', array_column($bd->consultar('SHOW DATABASES'), 'base'), true);
    $creada = $hay();
    $bd->consultar('CREATE DATABASE IF NOT EXISTS nueva');
    $bd->consultar('DROP DATABASE nueva');
    return $creada && !$hay();
});
chk('sentencia global sin base de datos', function () use ($raiz) {
    $r = Database::consultarGlobal('SHOW DATABASES', [], null, $raiz);
    return count($r) === 2;
});
esperaError('sentencia normal sin base de datos', 'CONFIG',
    fn() => Database::consultarGlobal('SELECT 1', [], null, $raiz));
chk('SHOW TABLES con columnas y filas', function () use ($bd) {
    $t = [];
    foreach ($bd->consultar('SHOW TABLES') as $f) { $t[$f['tabla']] = $f; }
    return isset($t['clientes'], $t['pedidos'])
        && $t['clientes']['columnas'] === 4 && $t['clientes']['filas'] === 2
        && $t['pedidos']['filas'] === 2;
});
chk('SHOW SCHEMA describe la tabla', function () use ($bd) {
    $c = [];
    foreach ($bd->consultar('SHOW SCHEMA clientes') as $f) { $c[$f['columna']] = $f; }
    return $c['id']['pk'] === 1 && $c['id']['auto'] === 1 && $c['id']['tipo'] === 'INTEGER'
        && $c['cod']['notnull'] === 1 && $c['cod']['longitud'] === 10
        && $c['saldo']['tipo'] === 'DECIMAL' && $c['saldo']['escala'] === 2
        && $c['saldo']['defecto'] === 0.0;
});
chk('SHOW COLUMNS FROM es lo mismo', function () use ($bd) {
    return $bd->consultar('SHOW COLUMNS FROM clientes') === $bd->consultar('SHOW SCHEMA clientes');
});
chk('SHOW KEYS lista la primaria', function () use ($bd) {
    $k = $bd->consultar('SHOW KEYS FROM clientes');
    return count($k) === 1 && $k[0]['tipo'] === 'PRIMARY' && $k[0]['columnas'] === 'id';
});
esperaError('SHOW SCHEMA de una tabla inexistente', 'SCHEMA', fn() => $bd->consultar('SHOW SCHEMA fantasma'));
esperaError('SHOW de algo no soportado', 'SYNTAX', fn() => $bd->consultar('SHOW COSAS'));

echo "\n== Añadir restricciones ==\n";
chk('añadir UNIQUE sobre datos válidos', function () use ($bd) {
    $r = $bd->consultar('ALTER TABLE clientes ADD CONSTRAINT uq_clientes_cod UNIQUE (cod)');
    return $r['success'] === true && str_contains($r['mensaje'], 'uq_clientes_cod');
});
chk('el UNIQUE nuevo se aplica a los INSERT', function () use ($bd) {
    try { $bd->consultar("INSERT INTO clientes (cod, nombre) VALUES ('A','Otro')"); }
    catch (JsonSqlDbError $e) { return $e->sqlState === 'CONSTRAINT' ?: $e->sqlState; }
    return 'no lanzó error';
});
chk('UNIQUE sin nombre se autonombra', function () use ($bd) {
    $bd->consultar('ALTER TABLE pedidos ADD UNIQUE (ref)');
    $n = array_column($bd->consultar('SHOW KEYS FROM pedidos'), 'nombre');
    return in_array('uq_pedidos_ref', $n, true);
});
chk('añadir FOREIGN KEY sobre datos válidos', function () use ($bd) {
    $bd->consultar('ALTER TABLE pedidos ADD CONSTRAINT fk_pedidos_cliente
                    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE');
    foreach ($bd->consultar('SHOW KEYS FROM pedidos') as $k) {
        if ($k['tipo'] === 'FOREIGN') {
            return $k['nombre'] === 'fk_pedidos_cliente' && $k['tabla_destino'] === 'clientes'
                && $k['columnas_destino'] === 'id' && $k['on_delete'] === 'CASCADE'
                && $k['on_update'] === 'NO ACTION';
        }
    }
    return 'no aparece la clave foránea';
});
chk('la FK nueva rechaza un padre inexistente', function () use ($bd) {
    try { $bd->consultar("INSERT INTO pedidos (cliente_id, ref) VALUES (99,'R9')"); }
    catch (JsonSqlDbError $e) { return $e->sqlState === 'CONSTRAINT' ?: $e->sqlState; }
    return 'no lanzó error';
});
chk('la FK nueva propaga el ON DELETE CASCADE', function () use ($bd) {
    $bd->consultar('DELETE FROM clientes WHERE cod = ?', ['B']);
    return $bd->consultar('SELECT COUNT(*) AS n FROM pedidos')[0]['n'] === 1;
});
esperaError('nombre de restricción repetido', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE clientes ADD CONSTRAINT uq_clientes_cod UNIQUE (nombre)'));
esperaError('UNIQUE sobre columna inexistente', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE clientes ADD CONSTRAINT uq_x UNIQUE (fantasma)'));
esperaError('FK hacia una tabla inexistente', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE pedidos ADD CONSTRAINT fk_x FOREIGN KEY (cliente_id) REFERENCES fantasma(id)'));
esperaError('no se añade una clave primaria si ya hay una', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE clientes ADD PRIMARY KEY (cod)'));

echo "\n== Restricciones que los datos actuales no cumplen ==\n";
chk('UNIQUE rechazado por datos repetidos', function () use ($bd) {
    $bd->consultar("INSERT INTO clientes (cod, nombre) VALUES ('C','Ana')");
    try { $bd->consultar('ALTER TABLE clientes ADD CONSTRAINT uq_clientes_nombre UNIQUE (nombre)'); }
    catch (JsonSqlDbError $e) {
        $n = array_column($bd->consultar('SHOW KEYS FROM clientes'), 'nombre');
        return ($e->sqlState === 'CONSTRAINT' && !in_array('uq_clientes_nombre', $n, true))
            ?: $e->sqlState . ' / ' . implode(',', $n);
    }
    return 'no lanzó error';
});
chk('FK rechazada por filas huérfanas', function () use ($bd) {
    $bd->consultar('CREATE TABLE notas (id INTEGER PRIMARY KEY AUTOINCREMENT, cliente_id INTEGER)');
    $bd->consultar('INSERT INTO notas (cliente_id) VALUES (777)');
    try { $bd->consultar('ALTER TABLE notas ADD CONSTRAINT fk_notas FOREIGN KEY (cliente_id) REFERENCES clientes(id)'); }
    catch (JsonSqlDbError $e) {
        $tipos = array_column($bd->consultar('SHOW KEYS FROM notas'), 'tipo');
        return ($e->sqlState === 'CONSTRAINT' && !in_array('FOREIGN', $tipos, true)) ?: $e->sqlState;
    }
    return 'no lanzó error';
});
chk('NULL no impide añadir la FK', function () use ($bd) {
    $bd->consultar('DELETE FROM notas');
    $bd->consultar('INSERT INTO notas (cliente_id) VALUES (NULL)');
    $bd->consultar('ALTER TABLE notas ADD CONSTRAINT fk_notas FOREIGN KEY (cliente_id) REFERENCES clientes(id)');
    return count($bd->consultar('SHOW KEYS FROM notas')) === 2;
});

echo "\n== Borrar una columna no deja rastro ==\n";
chk('el autoincremento se va con su columna', function () use ($bd, $raiz) {
    $bd->consultar('CREATE TABLE rastro (id INTEGER PRIMARY KEY AUTOINCREMENT, a VARCHAR(10))');
    $bd->consultar("INSERT INTO rastro (a) VALUES ('x')");
    $bd->consultar('ALTER TABLE rastro DROP COLUMN id');
    $meta = json_decode((string)file_get_contents("$raiz/tienda/rastro.meta.json"), true);
    return !array_key_exists('autoincrement', $meta) ?: $meta['autoincrement'];
});
chk('la columna desaparece también de las filas', function () use ($bd, $raiz) {
    $datos = json_decode((string)file_get_contents("$raiz/tienda/rastro.json"), true);
    return !array_key_exists('id', $datos['rows'][0] ?? ['id' => 1]);
});
chk('una meta con autoincremento huérfano se ignora al leerla', function () use ($bd, $raiz) {
    // Simula el fichero que dejaban las versiones anteriores
    $f    = "$raiz/tienda/rastro.meta.json";
    $meta = json_decode((string)file_get_contents($f), true);
    $meta['autoincrement'] = ['column' => 'id', 'next' => 7];
    file_put_contents($f, json_encode($meta));
    $bd->catalogo()->olvidar('rastro');

    $bd->consultar("INSERT INTO rastro (a) VALUES ('y')");     // no debe intentar autonumerar
    $bd->consultar('ALTER TABLE rastro ADD COLUMN b VARCHAR(5)');
    $limpio = json_decode((string)file_get_contents($f), true);
    return !array_key_exists('autoincrement', $limpio) ?: $limpio['autoincrement'];
});
esperaError('no se borra una columna que usa un trigger', 'CONSTRAINT', function () use ($bd) {
    $bd->consultar('CREATE TABLE contrg (id INTEGER PRIMARY KEY AUTOINCREMENT, total DECIMAL(10,2))');
    $bd->consultar("CREATE TRIGGER trg_contrg AFTER INSERT ON contrg
                    WHEN NEW.total > 0 BEGIN UPDATE contrg SET total = 0 WHERE id = NEW.id; END");
    $bd->consultar('ALTER TABLE contrg DROP COLUMN total');
});
esperaError('no se borra si la clave primaria quedaría repetida', 'CONSTRAINT', function () use ($bd) {
    $bd->consultar('CREATE TABLE pkcomp (a INTEGER, b VARCHAR(10))');
    $bd->consultar('ALTER TABLE pkcomp ADD PRIMARY KEY (a, b)');
    $bd->consultar("INSERT INTO pkcomp (a,b) VALUES (1,'x'),(1,'y')");
    $bd->consultar('ALTER TABLE pkcomp DROP COLUMN b');
});
chk('limpiar las tablas de rastro', function () use ($bd) {
    foreach (['rastro', 'contrg', 'pkcomp'] as $t) {
        $bd->consultar('DROP TABLE ' . $t);
    }
    return true;
});

echo "\n== Clave primaria sobre una tabla existente ==\n";
chk('crear una clave primaria compuesta', function () use ($bd) {
    $bd->consultar('CREATE TABLE sinpk (a INTEGER, b VARCHAR(10), c VARCHAR(10))');
    $bd->consultar("INSERT INTO sinpk (a,b,c) VALUES (1,'x','n1'),(1,'y','n2'),(2,'x','n3')");
    $bd->consultar('ALTER TABLE sinpk ADD PRIMARY KEY (a, b)');
    foreach ($bd->consultar('SHOW KEYS FROM sinpk') as $k) {
        if ($k['tipo'] === 'PRIMARY') { return $k['columnas'] === 'a,b'; }
    }
    return 'no aparece la clave primaria';
});
chk('las columnas de la clave pasan a no nulas', function () use ($bd) {
    $nn = [];
    foreach ($bd->consultar('SHOW SCHEMA sinpk') as $c) { $nn[$c['columna']] = (int)$c['notnull']; }
    return $nn === ['a' => 1, 'b' => 1, 'c' => 0] ?: $nn;
});
chk('la clave nueva rechaza duplicados', function () use ($bd) {
    try { $bd->consultar("INSERT INTO sinpk (a,b,c) VALUES (1,'x','otro')"); }
    catch (JsonSqlDbError $e) { return $e->sqlState === 'CONSTRAINT' ?: $e->sqlState; }
    return 'no lanzó error';
});
esperaError('no se puede añadir una segunda clave primaria', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE sinpk ADD PRIMARY KEY (c)'));
chk('quitar la clave primaria', function () use ($bd) {
    $bd->consultar('ALTER TABLE sinpk DROP PRIMARY KEY');
    return $bd->consultar('SHOW KEYS FROM sinpk') === [];
});
chk('con nulos no se puede crear', function () use ($bd) {
    $bd->consultar("INSERT INTO sinpk (a,b,c) VALUES (9,'z',NULL)");
    try { $bd->consultar('ALTER TABLE sinpk ADD PRIMARY KEY (c)'); }
    catch (JsonSqlDbError $e) {
        return ($e->sqlState === 'CONSTRAINT' && $bd->consultar('SHOW KEYS FROM sinpk') === [])
            ?: $e->sqlState;
    }
    return 'no lanzó error';
});
chk('con repetidos tampoco', function () use ($bd) {
    $bd->consultar("UPDATE sinpk SET a = 1, b = 'x'");
    try { $bd->consultar('ALTER TABLE sinpk ADD PRIMARY KEY (a, b)'); }
    catch (JsonSqlDbError $e) { return $e->sqlState === 'CONSTRAINT' ?: $e->sqlState; }
    return 'no lanzó error';
});
esperaError('una clave primaria AUTOINCREMENT no se puede quitar', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE clientes DROP PRIMARY KEY'));
esperaError('quitar una clave primaria que no existe', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE sinpk DROP PRIMARY KEY'));
chk('borrar la tabla sin clave', function () use ($bd) {
    $bd->consultar('DROP TABLE sinpk');
    return true;
});

echo "\n== Modificar columnas ==\n";
chk('cambiar el tipo convirtiendo los datos', function () use ($bd) {
    $bd->consultar('CREATE TABLE mod (cod VARCHAR(10), importe VARCHAR(20))');
    $bd->consultar("INSERT INTO mod (cod, importe) VALUES ('A','10.556'),('B','20')");
    $bd->consultar('ALTER TABLE mod MODIFY COLUMN importe DECIMAL(10,2)');
    $f = $bd->consultar('SELECT importe FROM mod ORDER BY cod');
    return $f[0]['importe'] === 10.56 && $f[1]['importe'] === 20.0 ?: $f;
});
chk('la estructura refleja el tipo nuevo', function () use ($bd) {
    foreach ($bd->consultar('SHOW SCHEMA mod') as $c) {
        if ($c['columna'] === 'importe') {
            return $c['tipo'] === 'DECIMAL' && $c['escala'] === 2;
        }
    }
    return 'no aparece la columna';
});
chk('poner NOT NULL con un DEFAULT rellena los nulos', function () use ($bd) {
    $bd->consultar("INSERT INTO mod (cod) VALUES ('C')");
    $bd->consultar('ALTER TABLE mod MODIFY COLUMN importe DECIMAL(10,2) NOT NULL DEFAULT 0');
    return $bd->consultar("SELECT importe FROM mod WHERE cod = 'C'")[0]['importe'] === 0.0;
});
esperaError('NOT NULL sin DEFAULT con nulos presentes', 'CONSTRAINT', function () use ($bd) {
    $bd->consultar('ALTER TABLE mod ADD COLUMN nota VARCHAR(20)');
    $bd->consultar('ALTER TABLE mod MODIFY COLUMN nota VARCHAR(20) NOT NULL');
});
esperaError('UNIQUE con valores repetidos', 'CONSTRAINT', function () use ($bd) {
    $bd->consultar("UPDATE mod SET cod = 'A'");
    $bd->consultar('ALTER TABLE mod MODIFY COLUMN cod VARCHAR(10) UNIQUE');
});
esperaError('conversión imposible', 'TYPE',
    fn() => $bd->consultar('ALTER TABLE mod MODIFY COLUMN cod INTEGER'));
chk('tras un error la tabla queda igual', function () use ($bd) {
    foreach ($bd->consultar('SHOW SCHEMA mod') as $c) {
        if ($c['columna'] === 'cod') {
            return $c['tipo'] === 'TEXT' && (int)$c['unico'] === 0;
        }
    }
    return 'no aparece la columna';
});
esperaError('no se puede quitar el AUTOINCREMENT', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE clientes MODIFY COLUMN id INTEGER'));
esperaError('modificar una columna que no existe', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE mod MODIFY COLUMN fantasma INTEGER'));
chk('borrar la tabla de pruebas', function () use ($bd) {
    $bd->consultar('DROP TABLE mod');
    return true;
});

echo "\n== Borrar restricciones ==\n";
chk('borrar una clave única', function () use ($bd) {
    $bd->consultar('ALTER TABLE pedidos DROP CONSTRAINT uq_pedidos_ref');
    $n = array_column($bd->consultar('SHOW KEYS FROM pedidos'), 'nombre');
    return !in_array('uq_pedidos_ref', $n, true);
});
chk('borrar una clave foránea', function () use ($bd) {
    $bd->consultar('ALTER TABLE pedidos DROP CONSTRAINT fk_pedidos_cliente');
    $bd->consultar("INSERT INTO pedidos (cliente_id, ref) VALUES (99,'R9')");   // ya no hay FK
    return $bd->consultar('SELECT COUNT(*) AS n FROM pedidos')[0]['n'] === 2;
});
esperaError('borrar una restricción inexistente', 'SCHEMA',
    fn() => $bd->consultar('ALTER TABLE pedidos DROP CONSTRAINT no_existe'));

echo "\n== Triggers ==\n";
chk('SHOW TRIGGERS de una tabla y de toda la base', function () use ($bd) {
    $bd->consultar("CREATE TRIGGER trg_pedidos AFTER INSERT ON pedidos
                    WHEN NEW.total > 0
                    BEGIN UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id; END");
    $t = $bd->consultar('SHOW TRIGGERS FROM pedidos');
    return count($t) === 1 && $t[0]['nombre'] === 'trg_pedidos' && $t[0]['timing'] === 'AFTER'
        && $t[0]['evento'] === 'INSERT' && $t[0]['cuando'] !== null
        && count($bd->consultar('SHOW TRIGGERS')) === 1;
});

echo "\n== Limpieza ==\n";
chk('borrar las bases de prueba', function () use ($raiz) {
    Storage::borrarBase($raiz, 'tienda');
    Storage::borrarBase($raiz, 'otra');
    @rmdir($raiz);
    return !is_dir("$raiz/tienda");
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
