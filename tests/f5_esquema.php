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

echo "\n== Journal de las operaciones de estructura ==\n";
chk('una operación normal no deja rastro', function () use ($bd, $raiz) {
    $bd->consultar('CREATE TABLE diario (id INTEGER PRIMARY KEY AUTOINCREMENT, a VARCHAR(10))');
    $bd->consultar("INSERT INTO diario (a) VALUES ('uno'),('dos')");
    $bd->consultar('ALTER TABLE diario RENAME TO diario2');
    return !is_dir("$raiz/tienda/.tx");
});
chk('un corte a mitad se deshace al volver a abrir', function () use ($raiz) {
    // Se simula el corte: se abre el journal a mano, se destroza la tabla y se
    // deja el .tx sin confirmar, como si el proceso hubiera muerto ahí.
    $st = new Storage($raiz, 'tienda');
    $st->bloquear(true);
    $st->txIniciar('ALTER TABLE', ['diario2']);
    @unlink("$raiz/tienda/diario2.json");            // la operación a medias
    file_put_contents("$raiz/tienda/diario2.meta.json", '{"roto":true}');
    $st->desbloquear();
    unset($st);

    // Al abrir de nuevo, el motor encuentra el .tx y deshace
    $bd2 = new Database('tienda', $raiz);
    $filas = $bd2->consultar('SELECT * FROM diario2 ORDER BY id');
    return !is_dir("$raiz/tienda/.tx") && count($filas) === 2 && $filas[0]['a'] === 'uno';
});
chk('un journal ya confirmado no deshace nada', function () use ($raiz) {
    $st = new Storage($raiz, 'tienda');
    $st->bloquear(true);
    $st->txIniciar('ALTER TABLE', ['diario2']);
    $st->desbloquear();

    // La operación terminó: se marca COMMITTED pero no da tiempo a borrar
    file_put_contents("$raiz/tienda/.tx/manifiesto.json", '{"estado":"COMMITTED"}');
    $antes = (string)file_get_contents("$raiz/tienda/diario2.json");

    $bd2 = new Database('tienda', $raiz);
    $bd2->consultar('SELECT 1 AS uno');              // basta con abrir la base
    return !is_dir("$raiz/tienda/.tx")
        && (string)file_get_contents("$raiz/tienda/diario2.json") === $antes;
});
chk('el DROP de una tabla también va con journal', function () use ($raiz) {
    $bd2 = new Database('tienda', $raiz);
    $bd2->consultar('DROP TABLE diario2');
    return !is_dir("$raiz/tienda/.tx")
        && !in_array('diario2', $bd2->consultar('SHOW TABLES') === [] ? []
             : array_column($bd2->consultar('SHOW TABLES'), 'tabla'), true);
});

echo "\n== Integridad de claves foráneas ==\n";
chk('con los datos sanos no encuentra nada', fn() => $bd->consultar('CHECK KEYS') === []);
chk('detecta una fila huérfana metida a mano en el JSON', function () use ($bd, $raiz) {
    $bd->consultar('CREATE TABLE padres (id INTEGER PRIMARY KEY AUTOINCREMENT, nom VARCHAR(20))');
    $bd->consultar('CREATE TABLE hijas (id INTEGER PRIMARY KEY AUTOINCREMENT, padre_id INTEGER,
                                        obliga INTEGER NOT NULL DEFAULT 1)');
    $bd->consultar('ALTER TABLE hijas ADD CONSTRAINT fk_hijas FOREIGN KEY (padre_id) REFERENCES padres(id)');
    $bd->consultar("INSERT INTO padres (nom) VALUES ('uno')");
    $bd->consultar('INSERT INTO hijas (padre_id) VALUES (1)');

    // Edición a mano, como haría una persona con un editor de texto
    $f = "$raiz/tienda/hijas.json";
    file_put_contents($f, str_replace('"padre_id":1', '"padre_id":404', (string)file_get_contents($f)));

    $p = $bd->consultar('CHECK KEYS FROM hijas');
    return count($p) === 1 && $p[0]['tabla'] === 'hijas'
        && str_contains($p[0]['valor'], '404') && (int)$p[0]['corregible'] === 1;
});
chk('la caché no oculta la edición a mano', function () use ($bd) {
    // La caché solo se invalida cuando escribe el motor: CHECK KEYS lee del disco
    return count($bd->consultar('CHECK KEYS')) === 1;
});
chk('REPAIR pone a NULL lo que puede', function () use ($bd) {
    $r = $bd->consultar('REPAIR KEYS FROM hijas');
    return $r['filas'] === 1 && $bd->consultar('CHECK KEYS') === [];
});
chk('una columna NOT NULL no se corrige sola', function () use ($bd, $raiz) {
    $bd->consultar('ALTER TABLE hijas ADD CONSTRAINT fk_obliga FOREIGN KEY (obliga) REFERENCES padres(id)');
    $f = "$raiz/tienda/hijas.json";
    file_put_contents($f, str_replace('"obliga":1', '"obliga":505', (string)file_get_contents($f)));

    $p = $bd->consultar('CHECK KEYS FROM hijas');
    if (count($p) !== 1 || (int)$p[0]['corregible'] !== 0) { return $p; }

    $r = $bd->consultar('REPAIR KEYS FROM hijas');
    return $r['filas'] === 0 && str_contains($r['mensaje'], 'sin corregir')
        && count($bd->consultar('CHECK KEYS')) === 1;
});
chk('REPAIR nunca borra filas', function () use ($bd) {
    return $bd->consultar('SELECT COUNT(*) AS n FROM hijas')[0]['n'] === 1;
});
esperaError('CHECK KEYS sobre una tabla inexistente', 'SCHEMA',
    fn() => $bd->consultar('CHECK KEYS FROM fantasma'));
chk('limpiar las tablas de integridad', function () use ($bd) {
    $bd->consultar('DROP TABLE hijas');
    $bd->consultar('DROP TABLE padres');
    return true;
});

echo "\n== Vistas ==\n";
chk('crear una vista y consultarla', function () use ($bd) {
    $bd->consultar("CREATE VIEW v_conA AS SELECT cod, nombre FROM clientes WHERE cod LIKE 'A%'");
    $r = $bd->consultar('SELECT * FROM v_conA');
    return array_keys($r[0] ?? []) === ['cod', 'nombre'];
});
chk('la vista se comporta como una tabla en el FROM', function () use ($bd) {
    $r = $bd->consultar('SELECT COUNT(*) AS n FROM v_conA WHERE nombre IS NOT NULL');
    return is_int($r[0]['n']);
});
chk('una vista puede usar JOIN y agregados', function () use ($bd) {
    $bd->consultar('CREATE VIEW v_totales AS
                    SELECT c.cod AS cod, COUNT(p.id) AS pedidos
                    FROM clientes c LEFT JOIN pedidos p ON p.cliente_id = c.id
                    GROUP BY c.cod');
    $r = $bd->consultar('SELECT * FROM v_totales ORDER BY cod');
    return isset($r[0]['cod'], $r[0]['pedidos']);
});
chk('una vista puede apoyarse en otra vista', function () use ($bd) {
    $bd->consultar('CREATE VIEW v_sobre_vista AS SELECT cod FROM v_totales WHERE pedidos >= 0');
    return is_array($bd->consultar('SELECT * FROM v_sobre_vista'));
});
chk('SHOW VIEWS las lista con su SQL', function () use ($bd) {
    $v = [];
    foreach ($bd->consultar('SHOW VIEWS') as $f) { $v[$f['vista']] = $f['sql']; }
    return isset($v['v_conA'], $v['v_totales'], $v['v_sobre_vista'])
        && str_contains($v['v_conA'], 'SELECT cod, nombre FROM clientes');
});
chk('la vista refleja los datos actuales', function () use ($bd) {
    $antes = $bd->consultar('SELECT COUNT(*) AS n FROM v_conA')[0]['n'];
    $bd->consultar("INSERT INTO clientes (cod, nombre) VALUES ('A9', 'Nuevo')");
    $despues = $bd->consultar('SELECT COUNT(*) AS n FROM v_conA')[0]['n'];
    $bd->consultar("DELETE FROM clientes WHERE cod = 'A9'");
    return $despues === $antes + 1;
});
esperaError('no se puede insertar en una vista', 'SCHEMA',
    fn() => $bd->consultar("INSERT INTO v_conA (cod) VALUES ('Z1')"));
esperaError('no se puede actualizar una vista', 'SCHEMA',
    fn() => $bd->consultar("UPDATE v_conA SET nombre = 'X'"));
esperaError('no se puede borrar en una vista', 'SCHEMA',
    fn() => $bd->consultar('DELETE FROM v_conA'));
esperaError('DROP TABLE no vale con una vista', 'SCHEMA',
    fn() => $bd->consultar('DROP TABLE v_conA'));
esperaError('una tabla no puede llamarse como una vista', 'SCHEMA',
    fn() => $bd->consultar('CREATE TABLE v_conA (a INTEGER)'));
esperaError('una vista no puede llamarse como una tabla', 'SCHEMA',
    fn() => $bd->consultar('CREATE VIEW clientes AS SELECT 1 AS uno'));
esperaError('vista repetida', 'SCHEMA',
    fn() => $bd->consultar('CREATE VIEW v_conA AS SELECT 1 AS uno'));
chk('IF NOT EXISTS no protesta', function () use ($bd) {
    $r = $bd->consultar('CREATE VIEW IF NOT EXISTS v_conA AS SELECT 1 AS uno');
    return $r['success'] === true && str_contains($r['mensaje'], 'ya existía');
});
chk('una vista que se referencia a sí misma se corta', function () use ($bd) {
    $bd->consultar('CREATE VIEW v_ciclo AS SELECT 1 AS uno');
    // Se sustituye su SQL a mano por una que se llama a sí misma
    $bd->consultar('DROP VIEW v_ciclo');
    $bd->consultar('CREATE VIEW v_a AS SELECT * FROM v_conA');
    try {
        $bd->consultar('DROP VIEW v_conA');
        $bd->consultar('CREATE VIEW v_conA AS SELECT * FROM v_a');   // ciclo v_a -> v_conA -> v_a
        $bd->consultar('SELECT * FROM v_a');
    } catch (JsonSqlDbError $e) {
        return str_contains($e->getMessage(), 'anidadas') ?: $e->getMessage();
    }
    return 'no cortó el ciclo';
});
chk('borrar las vistas', function () use ($bd) {
    foreach (['v_a', 'v_conA', 'v_totales', 'v_sobre_vista'] as $v) {
        $bd->consultar('DROP VIEW IF EXISTS ' . $v);
    }
    return $bd->consultar('SHOW VIEWS') === [];
});

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
