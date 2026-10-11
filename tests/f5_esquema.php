<?php
declare(strict_types=1);

/**
 * Prueba de las sentencias SHOW y de las restricciones añadidas sobre tablas
 * ya creadas. Ejecutar: php tests/f5_esquema.php
 *
 * https://miguelenred.es/jsonsqldb
 */
// Las pruebas usan el motor directamente, sin pasar por la API
define('JSONSQLDB_CONEXION_DIRECTA', true);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Storage;
use JsonSQLDB\StorageLectura;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f5';
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

/**
 * ¿Hay algún journal en la base? La carpeta .tx puede existir vacía, y un
 * temporal de manifiesto que dejó un proceso muerto es basura, no un journal:
 * lo barre la siguiente escritura con el exclusivo de la base.
 */
function hayJournal(string $base): bool {
    foreach ((array)glob("$base/.tx/*") as $f) {
        if (substr((string)$f, -4) !== '.tmp') { return true; }
    }
    return false;
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
chk('CREATE y DROP DATABASE por SQL', function () use ($bd) {
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

chk('ADD COLUMN y MODIFY guardan el CHECK y el REFERENCES de la columna, y las filas que hay los cumplen (2.9: se perdían sin avisar)', function () use ($bd) {
    $bd->consultar('CREATE TABLE padre (id INTEGER PRIMARY KEY)');
    $bd->consultar('INSERT INTO padre VALUES (1)');
    $bd->consultar('CREATE TABLE hijo (id INTEGER PRIMARY KEY)');
    $bd->consultar('INSERT INTO hijo VALUES (1), (2)');
    $r = [];
    foreach (['ALTER TABLE hijo ADD COLUMN a INTEGER DEFAULT 1 CHECK (a > 5)', 'ALTER TABLE hijo ADD COLUMN b INTEGER DEFAULT 2 REFERENCES padre (id)',
              'ALTER TABLE hijo ADD COLUMN c INTEGER DEFAULT 1 UNIQUE', 'ALTER TABLE hijo ADD COLUMN d INTEGER DEFAULT 7 CHECK (d > 5)',
              'ALTER TABLE hijo ADD COLUMN e INTEGER DEFAULT 1 REFERENCES padre (id)', 'ALTER TABLE hijo MODIFY d INTEGER CHECK (d < 7)',
              'ALTER TABLE hijo ADD COLUMN g INTEGER', 'ALTER TABLE hijo MODIFY g INTEGER REFERENCES padre (id)',
              'INSERT INTO hijo (id, d) VALUES (3, 1)', 'INSERT INTO hijo (id, d, e) VALUES (3, 6, 9)', 'INSERT INTO hijo (id, d, g) VALUES (3, 6, 9)',
              'INSERT INTO hijo (id, d, e, g) VALUES (3, 6, 1, 1)'] as $q) {
        try { $bd->consultar($q); $r[] = 'ok'; } catch (JsonSqlDbError $e) { $r[] = $e->sqlState; }
    }
    $cols = array_column($bd->consultar('SHOW COLUMNS FROM hijo'), 'columna');
    $bd->consultar('DROP TABLE hijo');
    $bd->consultar('DROP TABLE padre');
    return $r === ['CONSTRAINT', 'CONSTRAINT', 'CONSTRAINT', 'ok', 'ok', 'CONSTRAINT', 'ok', 'ok', 'CONSTRAINT', 'CONSTRAINT', 'CONSTRAINT', 'ok']
        && $cols === ['id', 'd', 'e', 'g'] ?: json_encode([$r, $cols]);
});

echo "\n== Borrar una columna no deja rastro ==\n";
chk('el autoincremento se va con su columna', function () use ($bd, $raiz) {
    $bd->consultar('CREATE TABLE rastro (id INTEGER PRIMARY KEY AUTOINCREMENT, a VARCHAR(10))');
    $bd->consultar("INSERT INTO rastro (a) VALUES ('x')");
    $bd->consultar('ALTER TABLE rastro DROP COLUMN id');
    $meta = json_decode((string)file_get_contents("$raiz/tienda/rastro.meta.json"), true);
    return !array_key_exists('autoincrement', $meta) ?: $meta['autoincrement'];
});
chk('la columna desaparece también de las filas', function () use ($raiz) {
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
    return !hayJournal("$raiz/tienda");
});
/**
 * Simula el corte de una escritura justo después de escribir el manifiesto:
 * los temporales están en el disco y el manifiesto dice dónde van. Es el único
 * momento en que un corte deja trabajo pendiente: antes del manifiesto los
 * temporales sobran y los datos siguen intactos.
 *
 * @param array<string,string> $ficheros nombre definitivo => contenido nuevo
 */
function simularCorte(string $dir, ?string $ambito, array $tablas, array $ficheros, array $borrar = []): void {
    $renombrar = [];
    foreach ($ficheros as $nombre => $contenido) {
        file_put_contents("$dir/$nombre.999999.tmp", $contenido);
        $renombrar["$nombre.999999.tmp"] = $nombre;
    }
    $jd = "$dir/.tx/" . ($ambito ?? '_base');
    @mkdir($jd, 0775, true);
    file_put_contents("$jd/manifiesto.json", json_encode([
        'tipo' => 'redo', 'operacion' => 'ESCRITURA', 'ambito' => $ambito,
        'tablas' => $tablas, 'renombrar' => $renombrar, 'borrar' => $borrar,
    ]));
}

chk('un corte a mitad de una escritura se termina al volver a abrir', function () use ($raiz) {
    $dir = "$raiz/tienda";
    $rev = json_decode((string)file_get_contents("$dir/diario2.rev.json"), true);
    simularCorte($dir, null, ['diario2'], [
        'diario2.json'     => "{\n  \"table\": \"diario2\",\n  \"rows\": [\n    {\"id\":1,\"a\":\"uno\"},\n    {\"id\":2,\"a\":\"dos\"},\n    {\"id\":3,\"a\":\"tres\"}\n  ]\n}\n",
        'diario2.rev.json' => json_encode(['rev' => $rev['rev'] + 1, 'chunk' => $rev['chunk'], 'rows' => 3, 'parts' => [$rev['rev'] + 1]]),
    ]);

    // Al abrir de nuevo, el motor encuentra el manifiesto y termina lo que faltaba
    $bd2 = new Database('tienda', $raiz);
    $filas = $bd2->consultar('SELECT * FROM diario2 ORDER BY id');
    return !hayJournal("$dir") && count($filas) === 3 && $filas[2]['a'] === 'tres'
        && glob("$dir/*.tmp") === [];
});
chk('un corte antes del manifiesto no toca nada: los temporales sobran', function () use ($raiz) {
    $dir = "$raiz/tienda";
    file_put_contents("$dir/diario2.json.999999.tmp", 'a medias');
    @mkdir("$dir/.tx/_base", 0775, true);                // carpeta sin manifiesto

    $bd2 = new Database('tienda', $raiz);
    $bd2->consultar('SELECT 1 AS uno');                  // basta con abrir la base
    $bd2->consultar("INSERT INTO diario2 (id, a) VALUES (4, 'cuatro')");   // la escritura barre el temporal
    return !hayJournal("$dir") && glob("$dir/*.tmp") === []
        && $bd2->consultar('SELECT COUNT(*) AS n FROM diario2')[0]['n'] === 4;
});
chk('un journal cuyos temporales ya estaban en su sitio no repite nada', function () use ($raiz) {
    $dir   = "$raiz/tienda";
    $antes = (string)file_get_contents("$dir/diario2.json");
    @mkdir("$dir/.tx/_base", 0775, true);
    file_put_contents("$dir/.tx/_base/manifiesto.json", json_encode([
        'tipo' => 'redo', 'tablas' => ['diario2'],
        'renombrar' => ['diario2.json.999999.tmp' => 'diario2.json'],
    ]));

    $bd2 = new Database('tienda', $raiz);
    $bd2->consultar('SELECT 1 AS uno');
    return !hayJournal("$dir") && (string)file_get_contents("$dir/diario2.json") === $antes;
});
chk('el DROP de una tabla también va con journal', function () use ($raiz) {
    $bd2 = new Database('tienda', $raiz);
    $bd2->consultar('DROP TABLE diario2');
    return !hayJournal("$raiz/tienda")
        && !in_array('diario2', $bd2->consultar('SHOW TABLES') === [] ? []
             : array_column($bd2->consultar('SHOW TABLES'), 'tabla'), true);
});

echo "\n== Journal en escrituras de varias tablas ==\n";
chk('un CASCADE que toca dos tablas se journaliza', function () use ($bd, $raiz) {
    $bd->consultar('CREATE TABLE jpadres (id INTEGER PRIMARY KEY AUTOINCREMENT, n VARCHAR(10))');
    $bd->consultar('CREATE TABLE jhijas (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER)');
    $bd->consultar('ALTER TABLE jhijas ADD CONSTRAINT fk_j FOREIGN KEY (pid)
                    REFERENCES jpadres(id) ON DELETE CASCADE');
    $bd->consultar("INSERT INTO jpadres (n) VALUES ('uno'),('dos')");
    $bd->consultar('INSERT INTO jhijas (pid) VALUES (1),(1),(2)');

    $bd->consultar('DELETE FROM jpadres WHERE id = 1');
    return $bd->consultar('SELECT COUNT(*) AS n FROM jhijas')[0]['n'] === 1
        && !hayJournal("$raiz/tienda");
});
chk('un corte a mitad de un CASCADE se termina entero', function () use ($raiz) {
    $dir = "$raiz/tienda";
    // El borrado del padre 2 y de su hija: el padre ya estaba renombrado
    // cuando cayó el corte, la hija todavía no
    $rp = json_decode((string)file_get_contents("$dir/jpadres.rev.json"), true);
    $rh = json_decode((string)file_get_contents("$dir/jhijas.rev.json"), true);
    $padres = "{\n  \"table\": \"jpadres\",\n  \"rows\": [\n    {\"id\":1,\"n\":\"uno\"}\n  ]\n}\n";
    simularCorte($dir, null, ['jpadres', 'jhijas'], [
        // Los rev.json que deja una escritura de la 2.7 llevan el contador de autoincremento
        'jpadres.rev.json' => json_encode(['rev' => $rp['rev'] + 1, 'chunk' => $rp['chunk'], 'rows' => 1, 'parts' => [$rp['rev'] + 1], 'autoinc' => $rp['autoinc']]),
        'jhijas.json'      => "{\n  \"table\": \"jhijas\",\n  \"rows\": []\n}\n",
        'jhijas.rev.json'  => json_encode(['rev' => $rh['rev'] + 1, 'chunk' => $rh['chunk'], 'rows' => 0, 'parts' => [$rh['rev'] + 1], 'autoinc' => $rh['autoinc']]),
    ]);
    file_put_contents("$dir/jpadres.json", $padres);       // este rename ya había ocurrido
    file_put_contents("$dir/jpadres.json.999999.tmp", $padres);
    $m = json_decode((string)file_get_contents("$dir/.tx/_base/manifiesto.json"), true);
    $m['renombrar'] = ['jpadres.json.999999.tmp' => 'jpadres.json'] + $m['renombrar'];
    file_put_contents("$dir/.tx/_base/manifiesto.json", json_encode($m));
    @unlink("$dir/jpadres.json.999999.tmp");

    $bd2 = new Database('tienda', $raiz);
    return $bd2->consultar('SELECT COUNT(*) AS n FROM jpadres')[0]['n'] === 1
        && $bd2->consultar('SELECT COUNT(*) AS n FROM jhijas')[0]['n'] === 0
        && !hayJournal("$dir") && glob("$dir/*.tmp") === [];
});
chk('una escritura de una sola tabla usa el journal de esa tabla, no el de la base', function () use ($raiz) {
    // Una tabla con clave primaria tiene índice, así que su escritura toca
    // varios ficheros y sí se journaliza. Lo que importa es el ÁMBITO: tiene
    // que ser el de la tabla, porque el de la base esperaría a que terminaran
    // todas las escrituras de todas las demás.
    $bd2 = new Database('tienda', $raiz);
    $bd2->consultar("INSERT INTO jpadres (n) VALUES ('tres')");
    return !hayJournal("$raiz/tienda")
        && $bd2->consultar('SELECT COUNT(*) AS n FROM jpadres')[0]['n'] === 2;
});
chk('el journal de una tabla se termina solo con el bloqueo de esa tabla', function () use ($raiz) {
    // Una escritura acotada a una tabla que murió tras escribir su manifiesto
    $dir = "$raiz/tienda";
    $rp  = json_decode((string)file_get_contents("$dir/jpadres.rev.json"), true);
    simularCorte($dir, 'jpadres', ['jpadres'], [
        'jpadres.json'     => "{\n  \"table\": \"jpadres\",\n  \"rows\": [\n    {\"id\":1,\"n\":\"uno\"},\n    {\"id\":3,\"n\":\"tres\"},\n    {\"id\":4,\"n\":\"cuatro\"}\n  ]\n}\n",
        'jpadres.rev.json' => json_encode(['rev' => $rp['rev'] + 1, 'chunk' => $rp['chunk'], 'rows' => 3, 'parts' => [$rp['rev'] + 1], 'autoinc' => 5]),
    ]);

    // Una lectura cualquiera lo encuentra y lo termina sin necesitar el
    // exclusivo de la base
    $bd2 = new Database('tienda', $raiz);
    return $bd2->consultar('SELECT COUNT(*) AS n FROM jpadres')[0]['n'] === 3
        && !hayJournal("$dir");
});
chk('un journal huérfano de una tabla no bloquea las lecturas de otra', function () use ($raiz) {
    @mkdir("$raiz/tienda/.tx/jpadres", 0775, true);     // sin manifiesto: murió antes

    $bd2 = new Database('tienda', $raiz);
    $n = $bd2->consultar('SELECT COUNT(*) AS n FROM jhijas')[0]['n'];
    return $n === 0 && !hayJournal("$raiz/tienda");
});
chk('un trigger que escribe en otra tabla también se journaliza', function () use ($raiz) {
    $bd2 = new Database('tienda', $raiz);
    $bd2->consultar("CREATE TRIGGER trg_j AFTER INSERT ON jhijas
                     BEGIN UPDATE jpadres SET n = 'tocado' WHERE id = NEW.pid; END");
    $bd2->consultar('INSERT INTO jhijas (pid) VALUES (3)');
    return $bd2->consultar('SELECT n FROM jpadres WHERE id = 3')[0]['n'] === 'tocado'
        && !hayJournal("$raiz/tienda");
});
chk('limpiar las tablas del journal', function () use ($raiz) {
    $bd2 = new Database('tienda', $raiz);
    $bd2->consultar('DROP TRIGGER trg_j');
    $bd2->consultar('DROP TABLE jhijas');
    $bd2->consultar('DROP TABLE jpadres');
    return true;
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

chk('un trigger puede tener IF … ELSEIF … ELSE … END IF, también anidados', function () use ($bd) {
    $bd->consultar("CREATE TABLE prod (id INTEGER PRIMARY KEY AUTOINCREMENT, precio DECIMAL(10,2), tramo TEXT)");
    $bd->consultar("CREATE TABLE avisos (id INTEGER PRIMARY KEY AUTOINCREMENT, msg TEXT)");
    $bd->consultar("CREATE TRIGGER prod_ai AFTER INSERT ON prod BEGIN
        IF NEW.precio < 10 THEN INSERT INTO avisos (msg) VALUES ('barato ' || NEW.id);
        ELSEIF NEW.precio < 100 THEN
            IF NEW.precio = 50 THEN INSERT INTO avisos (msg) VALUES ('justo ' || NEW.id); END IF;
        ELSE INSERT INTO avisos (msg) VALUES ('caro ' || NEW.id); UPDATE prod SET tramo = 'alto' WHERE id = NEW.id;
        END IF;
    END");
    $bd->consultar("INSERT INTO prod (precio) VALUES (5), (50), (70), (500)");
    $m = array_column($bd->consultar('SELECT msg FROM avisos ORDER BY id'), 'msg');
    $t = array_column($bd->consultar('SELECT tramo FROM prod ORDER BY id'), 'tramo');
    $bd->consultar('DROP TRIGGER prod_ai');
    return $m === ['barato 1', 'justo 2', 'caro 4'] && $t === [null, null, null, 'alto'] ?: json_encode([$m, $t]);
});
chk('un trigger BEFORE cambia la fila que se escribe con SET NEW.col = …', function () use ($bd) {
    // Como en MySQL (SET NEW.x = …) y en PostgreSQL (NEW.x := …): de izquierda
    // a derecha, cada asignación ve las anteriores, y el valor se convierte al
    // tipo de la columna
    $bd->consultar("CREATE TRIGGER prod_bi BEFORE INSERT ON prod BEGIN
        IF NEW.precio < 0 THEN SELECT RAISE(ABORT, 'precio negativo'); END IF;
        SET NEW.precio = NEW.precio * 2, NEW.tramo = CASE WHEN NEW.precio >= 100 THEN 'alto' ELSE 'bajo' END;
    END");
    $bd->consultar("CREATE TRIGGER prod_bu BEFORE UPDATE ON prod BEGIN SET NEW.tramo = 'cambiado ' || OLD.tramo; END");
    $bd->consultar("INSERT INTO prod (precio) VALUES (60)");
    $a = $bd->consultar('SELECT precio, tramo FROM prod ORDER BY id DESC LIMIT 1')[0];
    $bd->consultar('UPDATE prod SET precio = 1 WHERE id = 5');
    $b = $bd->consultar('SELECT precio, tramo FROM prod WHERE id = 5')[0];
    try { $bd->consultar("INSERT INTO prod (precio) VALUES (-1)"); $c = 'sin error'; }
    catch (JsonSQLDB\JsonSqlDbError $e) { $c = $e->getMessage(); }
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM prod')[0]['n'];
    $bd->consultar('DROP TRIGGER prod_bi');
    $bd->consultar('DROP TRIGGER prod_bu');
    return (float)$a['precio'] === 120.0 && $a['tramo'] === 'alto' && $b['tramo'] === 'cambiado alto'
        && $c === 'precio negativo' && $n === 5 ?: json_encode([$a, $b, $c, $n]);
});
chk('lo que pone SET NEW pasa las mismas comprobaciones: NOT NULL, únicos y claves', function () use ($bd) {
    $bd->consultar('CREATE TABLE snn (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL, cod TEXT UNIQUE)');
    $bd->consultar("INSERT INTO snn VALUES (1, 'a', 'X')");
    $bd->consultar("CREATE TRIGGER snn_bi BEFORE INSERT ON snn BEGIN
        IF NEW.id = 2 THEN SET NEW.nombre = NULL; END IF;
        IF NEW.id = 3 THEN SET NEW.cod = 'X'; END IF;
    END");
    $mal = [];
    foreach ([2 => 'NULL', 3 => 'UNIQUE'] as $id => $que) {
        try { $bd->consultar("INSERT INTO snn VALUES ($id, 'b', 'Y$id')"); $mal[] = "$que aceptado"; } catch (JsonSQLDB\JsonSqlDbError) { }
    }
    $n = (int)$bd->consultar('SELECT COUNT(*) AS n FROM snn')[0]['n'];
    $bd->consultar('DROP TABLE snn');
    return $mal === [] && $n === 1 ?: implode(', ', $mal) . " · $n filas";
});
foreach (['sin índice' => [], 'con índice en las claves foráneas (2.7.5: las hijas se leen por él sin cargar la tabla)' => ['CREATE INDEX ix_ch_pid ON ch (pid)', 'CREATE INDEX ix_cn_hid ON cn (hid)']] as $variante => $indices) {
chk("cascadas con muchos padres a la vez (borrar, cambiar la clave, SET NULL, tres niveles) dejan lo mismo que SQLite, $variante", function () use ($bd, $indices) {
    // El lado hijo se resuelve con un mapa que se mantiene al escribir (2.7.4):
    // tiene que dar exactamente lo mismo que recorrer la tabla por cada padre
    if (!class_exists('SQLite3')) { return null; }
    $lite = new SQLite3(':memory:');
    $lite->exec('PRAGMA foreign_keys = ON');
    $ddl = ['CREATE TABLE cp (id INTEGER PRIMARY KEY, n TEXT)',
            'CREATE TABLE ch (id INTEGER PRIMARY KEY, pid INTEGER, k INTEGER, FOREIGN KEY (pid) REFERENCES cp (id) ON DELETE CASCADE ON UPDATE CASCADE)',
            'CREATE TABLE cn (id INTEGER PRIMARY KEY, hid INTEGER, FOREIGN KEY (hid) REFERENCES ch (id) ON DELETE SET NULL ON UPDATE CASCADE)'];
    $datos = [];
    for ($i = 1; $i <= 60; $i++) { $datos[] = "INSERT INTO cp VALUES ($i, 'p$i')"; }
    for ($i = 1; $i <= 300; $i++) { $datos[] = 'INSERT INTO ch VALUES (' . $i . ', ' . (($i * 7) % 60 + 1) . ', ' . ($i % 5) . ')'; }
    for ($i = 1; $i <= 400; $i++) { $datos[] = 'INSERT INTO cn VALUES (' . $i . ', ' . (($i * 3) % 300 + 1) . ')'; }
    $escrituras = ['UPDATE cp SET id = id + 1000 WHERE id BETWEEN 10 AND 30',      // la clave cambia en cascada
                   'DELETE FROM cp WHERE id BETWEEN 1 AND 9',                     // y se borra en cascada
                   'UPDATE ch SET id = id + 5000 WHERE k = 2',                    // dos niveles
                   'DELETE FROM ch WHERE k IN (0, 3)',                            // SET NULL en los nietos
                   'DELETE FROM cp WHERE id > 1015'];
    // Una a una, también el borrado de un solo padre y el cambio de una clave
    $escrituras = array_merge(['DELETE FROM cp WHERE id = 33', 'UPDATE cp SET id = 2000 WHERE id = 34',
                               'UPDATE ch SET id = 9000 WHERE id = 7'], $escrituras);
    foreach (array_merge($ddl, $indices, $datos, $escrituras) as $q) {
        $lite->exec($q);
        $bd->consultar($q);
    }
    $mal = [];
    foreach (['cp', 'ch', 'cn'] as $t) {
        $r = $lite->query("SELECT * FROM $t ORDER BY id");
        $a = [];
        while ($f = $r->fetchArray(SQLITE3_ASSOC)) { $a[] = $f; }
        if ($a !== $bd->consultar("SELECT * FROM $t ORDER BY id")) { $mal[] = $t; }
    }
    foreach (['cn', 'ch', 'cp'] as $t) { $bd->consultar("DROP TABLE $t"); }
    return $mal === [] ?: 'distinto en ' . implode(', ', $mal);
});
}
chk('SET NEW solo se admite en BEFORE INSERT y BEFORE UPDATE, y solo sobre NEW', function () use ($bd) {
    $mal = [];
    foreach (["CREATE TRIGGER x1 AFTER INSERT ON prod BEGIN SET NEW.tramo = 'x'; END",
              "CREATE TRIGGER x2 BEFORE DELETE ON prod BEGIN SET NEW.tramo = 'x'; END",
              "CREATE TRIGGER x3 BEFORE INSERT ON prod BEGIN SET OLD.tramo = 'x'; END",
              "CREATE TRIGGER x4 BEFORE INSERT ON prod BEGIN IF NEW.id > 0 THEN SET NEW.tramo = 'x'; END"] as $sql) {
        try { $bd->consultar($sql); $mal[] = $sql; } catch (JsonSQLDB\JsonSqlDbError) { }
    }
    $bd->consultar('DROP TABLE prod');
    $bd->consultar('DROP TABLE avisos');
    return $mal === [] ?: 'aceptado: ' . implode(' | ', $mal);
});

echo "\n== Caché de resultados ==\n";

chk('una consulta repetida se sirve de la caché y una escritura la invalida', function () use ($raiz) {
    $dir = "$raiz/rc";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/c")) { Database::borrar('c', $dir); }
    Database::crear('c', $dir);
    $bd = new Database('c', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a VARCHAR(10), n INTEGER)');
    $bd->consultar("INSERT INTO t VALUES (1,'x',5),(2,'y',7),(3,'z',9)");
    // Relleno con n = 0, que no cambia ningún resultado de abajo: la consulta
    // tiene que costar al menos 1 ms para que se guarde (2.9)
    $v = [];
    for ($i = 4; $i <= 20000; $i++) { $v[] = "($i,'r',0)"; }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    $bd->consultar('CREATE VIEW v AS SELECT a FROM t WHERE n > 6');
    $sql = 'SELECT * FROM t WHERE n > ?';

    $r1 = $bd->consultar($sql, [6]);
    $r2 = $bd->consultar($sql, [6]);
    if ($r1 !== $r2 || count($r1) !== 2) { return 'la segunda ejecución no devuelve lo mismo'; }
    $conApcu = function_exists('apcu_enabled') && apcu_enabled();
    if (!$conApcu && count(glob("$dir/c/.cache/q.*.cache")) !== 1) { return 'no se guardó el resultado en disco'; }

    $bd->consultar('UPDATE t SET n = 1 WHERE id = 2');
    if (count($bd->consultar($sql, [6])) !== 1) { return 'tras escribir se sirvió el resultado viejo'; }
    if (!$conApcu && count(glob("$dir/c/.cache/q.*.cache")) !== 1) { return 'el resultado viejo no se borró del disco'; }
    if (count($bd->consultar($sql, [0])) !== 3) { return 'otros parámetros son otra consulta'; }

    // Una vista depende de sus tablas: escribir en ellas invalida su resultado
    if (count($bd->consultar('SELECT * FROM v')) !== 1) { return 'vista'; }
    $bd->consultar('UPDATE t SET n = 9 WHERE id = 2');
    if (count($bd->consultar('SELECT * FROM v')) !== 2) { return 'la vista sirvió el resultado viejo'; }

    // Lo que depende del momento no se guarda
    $a = $bd->consultar('SELECT RANDOM() AS r FROM t LIMIT 1')[0]['r'];
    $b = $bd->consultar('SELECT RANDOM() AS r FROM t LIMIT 1')[0]['r'];
    if ($a === $b) { return 'RANDOM() se sirvió de la caché'; }

    // Una tabla borrada y creada de nuevo con el mismo nombre no hereda resultados
    $bd->consultar('DROP VIEW v');
    $bd->consultar('DROP TABLE t');
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a VARCHAR(10), n INTEGER)');
    $bd->consultar("INSERT INTO t VALUES (9,'q',50)");
    if ($bd->consultar($sql, [0]) !== [['id' => 9, 'a' => 'q', 'n' => 50]]) { return 'la tabla nueva heredó un resultado de la antigua'; }
    unset($bd);
    Database::borrar('c', $dir);
    @rmdir($dir);
    return true;
});
chk("'now' en cualquier función de fechas, o como parámetro, no sale de la caché de resultados (2.9: solo se miraba en DATE() y compañía)", function () use ($raiz) {
    $dir = "$raiz/rc5";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/c")) { Database::borrar('c', $dir); }
    Database::crear('c', $dir);
    $bd = new Database('c', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT)');
    $v = [];
    for ($i = 1; $i <= 20000; $i++) { $v[] = "($i, 'r')"; }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    $repetidas = [];
    // Cada una cuesta más de 1 ms (recorre la tabla) y su resultado cambia cada microsegundo
    foreach (["SELECT COUNT(*) AS n, DATE_FORMAT('now', '%f') AS f FROM t WHERE a LIKE '%y%'" => [],
              "SELECT COUNT(*) AS n, TIMESTAMPDIFF(MICROSECOND, '2020-01-01', ' NOW ') AS f FROM t WHERE a LIKE '%y%'" => [],
              "SELECT COUNT(*) AS n, DATE_FORMAT(?, '%f') AS f FROM t WHERE a LIKE '%y%'" => ['now']] as $sql => $p) {
        $a = $bd->consultar($sql, $p);
        usleep(2000);
        if ($bd->consultar($sql, $p) === $a) { $repetidas[] = $sql; }
    }
    Database::borrar('c', $dir);
    @rmdir($dir);
    return $repetidas === [] ?: json_encode($repetidas);
});
chk('una consulta que cuesta menos de 1 ms no se guarda en la caché de resultados (2.9)', function () use ($raiz) {
    $dir = "$raiz/rc2";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/c")) { Database::borrar('c', $dir); }
    Database::crear('c', $dir);
    $bd = new Database('c', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a VARCHAR(10))');
    $bd->consultar("INSERT INTO t VALUES (1,'x'),(2,'y')");
    // Con la máquina cargada, una vez puede costar más: basta con que alguna no se guarde
    for ($i = 0, $n = 1; $i < 5 && $n > 0; $i++) {
        array_map('unlink', glob("$dir/c/.cache/q.*.cache") ?: []);
        $bd->consultar('SELECT a FROM t WHERE id = 1');
        $n = count(glob("$dir/c/.cache/q.*.cache") ?: []);
    }
    Database::borrar('c', $dir);
    @rmdir($dir);
    return $n === 0 ?: "se guardaron $n";
});
chk('en disco no se guarda un resultado de más de 1 MB, ni uno que no lee ninguna tabla (2.9: una clave de lectura llenaba el disco)', function () use ($raiz) {
    if (function_exists('apcu_enabled') && apcu_enabled()) { return true; }   // con APCu no van al disco
    $dir = "$raiz/rc4";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/c")) { Database::borrar('c', $dir); }
    Database::crear('c', $dir);
    $bd = new Database('c', $dir);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT)');
    $v = [];
    for ($i = 1; $i <= 2000; $i++) { $v[] = "($i, '" . str_repeat('x', 1000) . "')"; }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    $cuantos = static fn(): int => count(glob("$dir/c/.cache/q.*.cache") ?: []);
    $bd->consultar('SELECT * FROM t');                                                    // unos 2 MB
    $grande = $cuantos();
    $bd->consultar('WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 20000) SELECT COUNT(*) AS n FROM c');
    $sinTablas = $cuantos();
    $bd->consultar("SELECT COUNT(*) AS n FROM t WHERE a LIKE '%y%'");                    // cuesta, y es pequeño: este sí
    $normal = $cuantos();
    Database::borrar('c', $dir);
    @rmdir($dir);
    return [$grande, $sinTablas, $normal] === [0, 0, 1] ?: json_encode([$grande, $sinTablas, $normal]);
});
chk('dos resultados que caen en el mismo sitio no se confunden, y nunca hay más de 4.096 ficheros por tablas (2.9)', function () use ($raiz) {
    $dir = "$raiz/rc3";
    @mkdir($dir, 0775, true);
    if (is_dir("$dir/c")) { Database::borrar('c', $dir); }
    Database::crear('c', $dir);
    (new Database('c', $dir))->consultar('CREATE TABLE t (x INTEGER)');
    $st = new Storage($dir, 'c');
    $st->bloquear(false);
    $base = substr(StorageLectura::claveResultado($st, ['t'], 'x', []), 0, -32);
    // Dos claves que acaban igual: van al mismo fichero
    $k1 = $base . str_repeat('a', 29) . 'abc';
    $k2 = $base . str_repeat('b', 29) . 'abc';
    StorageLectura::guardarResultado($st, $k1, ['t'], [['uno' => 1]]);
    $pisada = [StorageLectura::resultadoCacheado($st, $k1, ['t']), StorageLectura::resultadoCacheado($st, $k2, ['t'])];
    StorageLectura::guardarResultado($st, $k2, ['t'], [['dos' => 2]]);
    $pisada[] = StorageLectura::resultadoCacheado($st, $k1, ['t']);
    $pisada[] = StorageLectura::resultadoCacheado($st, $k2, ['t']);
    for ($i = 0; $i < 5000; $i++) { StorageLectura::guardarResultado($st, $base . md5((string)$i), ['t'], [['i' => $i]]); }
    $ficheros = count(glob("$dir/c/.cache/q.*.cache") ?: []);
    $st->desbloquear();
    unset($st);
    Database::borrar('c', $dir);
    @rmdir($dir);
    $conApcu = function_exists('apcu_enabled') && apcu_enabled();
    return $pisada === [[['uno' => 1]], null, null, [['dos' => 2]]] && ($conApcu || ($ficheros > 0 && $ficheros <= 4096))
        ?: json_encode([$pisada, $ficheros]);
});

chk('redefinir una vista con el mismo nombre no sirve el resultado de la vieja', function () use ($raiz) {
    $dir = "$raiz/rv";
    @mkdir($dir, 0775, true);
    Database::crear('c', $dir);
    $bd = new Database('c', $dir);
    $bd->consultar('CREATE TABLE a (id INTEGER PRIMARY KEY, v INTEGER)');
    $bd->consultar('INSERT INTO a VALUES (1, 10), (2, 20), (3, 30)');
    $bd->consultar('CREATE VIEW vv AS SELECT id, v FROM a WHERE v > 5');
    $antes = $bd->consultar('SELECT * FROM vv ORDER BY id');
    $bd->consultar('DROP VIEW vv');
    $bd->consultar('CREATE VIEW vv AS SELECT id, v FROM a WHERE v > 25');
    $despues = $bd->consultar('SELECT * FROM vv ORDER BY id');
    // Y la misma definición otra vez sí se sirve de la caché (no se pierde)
    $bd->consultar('DROP VIEW vv');
    $bd->consultar('CREATE VIEW vv AS SELECT id, v FROM a WHERE v > 5');
    $otraVez = $bd->consultar('SELECT * FROM vv ORDER BY id');
    unset($bd);
    Database::borrar('c', $dir);
    @rmdir($dir);
    return count($antes) === 3 && $despues === [['id' => 3, 'v' => 30]] && $otraVez === $antes ?: json_encode($despues);
});
chk('cambiar la colación en la configuración no sirve resultados ordenados con la anterior', function () use ($raiz) {
    $dir = "$raiz/rcol";
    @mkdir($dir, 0775, true);
    Database::crear('c', $dir);
    $bd = new Database('c', $dir);
    $bd->consultar('CREATE TABLE t (s TEXT)');
    $bd->consultar("INSERT INTO t VALUES ('avila'), ('Ávila'), ('Zapata'), ('M'), ('oscar')");
    unset($bd);
    // Dos procesos, cada uno con su colación, sobre la misma caché en disco
    $script = $raiz . '/colacion.php';
    file_put_contents($script, '<?php define("JSONSQLDB_COLACION", $argv[1]); define("JSONSQLDB_CONEXION_DIRECTA", true);'
        . ' define("JSONSQLDB_CACHE_ACTIVA", true);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' echo json_encode(array_column((new JsonSQLDB\\Database("c", ' . var_export($dir, true) . '))->consultar("SELECT s FROM t ORDER BY s"), "s"), JSON_UNESCAPED_UNICODE);');
    $correr = static fn(string $c): string => (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $c);
    $general = $correr('general');
    $binaria = $correr('binaria');
    @unlink($script);
    Database::borrar('c', $dir);
    @rmdir($dir);
    return $general === '["avila","Ávila","M","oscar","Zapata"]' && $binaria === '["M","Zapata","avila","oscar","Ávila"]'
        ?: "$general / $binaria";
});

echo "\n== CREATE TABLE … AS SELECT ==\n";
chk('crea la tabla con las filas, los tipos de las columnas de origen y sin restricciones', function () use ($bd) {
    $bd->consultar('CREATE TABLE ctas_o (id INTEGER PRIMARY KEY AUTOINCREMENT, n VARCHAR(20) NOT NULL UNIQUE, p DECIMAL(10,3), f DATETIME)');
    $bd->consultar("INSERT INTO ctas_o (n, p, f) VALUES ('a', 1.5, '2026-01-02'), ('b', 2, NULL), ('c', NULL, NULL)");
    $r = $bd->consultar('CREATE TABLE ctas_c AS SELECT * FROM ctas_o WHERE id > 1');
    if ($r['filas'] !== 2) { return $r; }
    $esq = array_map(static fn($c) => [$c['columna'], $c['tipo'], $c['longitud'], $c['escala'], $c['pk'], $c['notnull'], $c['unico'], $c['auto']],
        $bd->consultar('SHOW SCHEMA ctas_c'));
    $esperado = [['id', 'INTEGER', null, null, 0, 0, 0, 0], ['n', 'TEXT', 20, null, 0, 0, 0, 0],
                 ['p', 'DECIMAL', null, 3, 0, 0, 0, 0], ['f', 'DATETIME', null, null, 0, 0, 0, 0]];
    if ($esq !== $esperado) { return $esq; }
    // Sin UNIQUE ni clave primaria: admite repetidos
    $bd->consultar("INSERT INTO ctas_c (id, n) VALUES (2, 'b')");
    return $bd->consultar('SELECT COUNT(*) AS n FROM ctas_c')[0]['n'] === 3;
});
chk('las expresiones toman el tipo de sus valores', function () use ($bd) {
    $bd->consultar("CREATE TABLE ctas_e AS SELECT n AS nombre, id * 2 AS doble, id / 2.0 AS mitad, NULL AS nada, id || 'z' AS texto FROM ctas_o");
    $tipos = array_column($bd->consultar('SHOW SCHEMA ctas_e'), 'tipo', 'columna');
    if ($tipos !== ['nombre' => 'TEXT', 'doble' => 'INTEGER', 'mitad' => 'DOUBLE', 'nada' => 'TEXT', 'texto' => 'TEXT']) { return $tipos; }
    return $bd->consultar('SELECT doble, texto FROM ctas_e ORDER BY doble') === [['doble' => 2, 'texto' => '1z'], ['doble' => 4, 'texto' => '2z'], ['doble' => 6, 'texto' => '3z']];
});
chk('sin filas también crea las columnas; con IF NOT EXISTS no toca la que ya existe', function () use ($bd) {
    $bd->consultar('CREATE TABLE ctas_v AS SELECT id, n FROM ctas_o WHERE 0');
    if (array_column($bd->consultar('SHOW SCHEMA ctas_v'), 'columna') !== ['id', 'n']) { return 'columnas'; }
    $r = $bd->consultar('CREATE TABLE IF NOT EXISTS ctas_v AS SELECT 1 AS x');
    return $r['filas'] === 0 && array_column($bd->consultar('SHOW SCHEMA ctas_v'), 'columna') === ['id', 'n'];
});
chk('una expresión sin AS da nombre a su columna, como en SQLite, y se usa entre comillas', function () use ($bd) {
    $bd->consultar('CREATE TABLE ctas_x AS SELECT id + 1, COUNT(*) FROM ctas_o GROUP BY id');
    if (array_column($bd->consultar('SHOW SCHEMA ctas_x'), 'columna') !== ['id + 1', 'COUNT(*)']) { return 'nombres'; }
    // Una clave sobre esa columna no pone su nombre en el del fichero del índice
    $bd->consultar('ALTER TABLE ctas_x ADD UNIQUE ("id + 1")');
    $idx = glob(sys_get_temp_dir() . '/jsonsqldb_test_f5/tienda/ctas_x.idx.*');
    if ($idx === []) { return 'no se creó el índice'; }
    foreach ($idx as $f) {
        if (!preg_match('/^ctas_x\.idx\.auto_[a-f0-9]{32}\.json$/', basename($f))) { return basename($f); }
    }
    $r = $bd->consultar('SELECT "id + 1" AS v FROM ctas_x WHERE "id + 1" = 3');
    $bd->consultar('DROP TABLE ctas_x');
    return $r === [['v' => 3]];
});
chk('ADD, DROP y RENAME COLUMN con comillas, barras, acentos y emojis en los nombres dejan las filas legibles', function () use ($bd) {
    // Las tres van por bloques de texto: la clave de la fila tiene que
    // escribirse como la escribe json_encode(), con sus escapes
    $c = static fn(string $n): string => '"' . str_replace('"', '""', $n) . '"';
    $bd->consultar('CREATE TABLE raros (id INTEGER PRIMARY KEY, ' . $c('a"b') . ' TEXT, ' . $c('c\\d') . ' TEXT, ' . $c('x/y') . ' TEXT)');
    $bd->consultar('INSERT INTO raros VALUES (1, ?, ?, ?), (2, ?, ?, ?)', ['uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis']);
    $bd->consultar('ALTER TABLE raros RENAME COLUMN ' . $c('a"b') . ' TO ' . $c('año "😀"'));
    $bd->consultar('ALTER TABLE raros DROP COLUMN ' . $c('c\\d'));
    $bd->consultar('ALTER TABLE raros ADD COLUMN ' . $c('nueva\\"') . " TEXT DEFAULT 'x'");
    $r = $bd->consultar('SELECT * FROM raros ORDER BY id');
    $bd->consultar('DROP TABLE raros');
    return $r === [['id' => 1, 'año "😀"' => 'uno', 'x/y' => 'tres', 'nueva\\"' => 'x'],
                   ['id' => 2, 'año "😀"' => 'cuatro', 'x/y' => 'seis', 'nueva\\"' => 'x']] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
esperaError('un nombre de columna con caracteres de control no vale', 'SCHEMA', fn() => $bd->consultar("CREATE TABLE ctas_y (\"a\tb\" INTEGER)"));
esperaError('la tabla ya existe', 'SCHEMA', fn() => $bd->consultar('CREATE TABLE ctas_o AS SELECT 1 AS x'));
chk('limpieza de las tablas de CREATE TABLE … AS', function () use ($bd) {
    foreach (['ctas_o', 'ctas_c', 'ctas_e', 'ctas_v'] as $t) { $bd->consultar("DROP TABLE $t"); }
    return true;
});

echo "\n== Claves, nombres y estructura (2.9) ==\n";
/** Cada sentencia en una base nueva: lo que devuelve, o 'error: mensaje' */
function enBaseNueva(string $raiz, array $sentencias): array {
    $nombre = 'nueva' . getmypid();
    Database::crear($nombre, $raiz);
    $b = new Database($nombre, $raiz);
    $r = [];
    foreach ($sentencias as $q) {
        try { $x = $b->consultar($q); $r[] = isset($x['success']) ? 'ok' : $x; } catch (JsonSqlDbError $e) { $r[] = 'error: ' . $e->getMessage(); }
    }
    Database::borrar($nombre, $raiz);
    return $r;
}
chk("ADD UNIQUE, ADD PRIMARY KEY, ADD FOREIGN KEY y REPAIR KEYS comparan las claves como al escribir: '5' y '05' son la misma (2.9: REPAIR KEYS ponía a NULL una hija que sí tenía su fila)", function () use ($raiz) {
    $r = enBaseNueva($raiz, [
        'CREATE TABLE p (code TEXT PRIMARY KEY)', 'CREATE TABLE h (id INTEGER PRIMARY KEY, pcode TEXT REFERENCES p (code))',
        "INSERT INTO p VALUES ('5')", "INSERT INTO h VALUES (1, '05')",
        'REPAIR KEYS', 'SELECT pcode FROM h', 'CHECK KEYS',
        'CREATE TABLE t (code TEXT, n INTEGER)', "INSERT INTO t VALUES ('5', 1), ('05', 2)",
        'ALTER TABLE t ADD UNIQUE (code)', 'ALTER TABLE t ADD PRIMARY KEY (code)', 'ALTER TABLE t ADD UNIQUE (code, n)',
        'CREATE TABLE h2 (id INTEGER PRIMARY KEY, pcode TEXT)', "INSERT INTO h2 VALUES (1, '5.0')",
        'ALTER TABLE h2 ADD FOREIGN KEY (pcode) REFERENCES p (code)',
        "INSERT INTO h2 VALUES (2, '6')",
        'ALTER TABLE h2 DROP FOREIGN KEY fk_h2_pcode', "INSERT INTO h2 VALUES (3, '6')", 'ALTER TABLE h2 ADD FOREIGN KEY (pcode) REFERENCES p (code)',
    ]);
    return $r[4] === 'ok' && $r[5] === [['pcode' => '05']] && $r[6] === []
        && $r[9] === 'error: Los datos de \'t\' repiten el valor ("05") en (code)'
        && $r[10] === 'error: Los datos de \'t\' repiten el valor ("05") en (code)' && $r[11] === 'ok'
        && $r[14] === 'ok' && str_starts_with((string)$r[15], 'error:') && $r[17] === 'ok'
        && $r[18] === 'error: Los datos de \'h2\' apuntan a ("6") y no existe en \'p\'' ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('ADD COLUMN … PRIMARY KEY DEFAULT x en una tabla de dos filas o más es un error, como UNIQUE: todas tendrían la misma clave (2.9); con una clave primaria ya puesta, se suma a ella', function () use ($raiz) {
    $r = enBaseNueva($raiz, [
        'CREATE TABLE t (a TEXT)', "INSERT INTO t VALUES ('x'), ('y')",
        'ALTER TABLE t ADD COLUMN id INTEGER PRIMARY KEY DEFAULT 0', 'SELECT * FROM t ORDER BY a',
        'CREATE TABLE u (a TEXT PRIMARY KEY)', "INSERT INTO u VALUES ('x'), ('y')",
        'ALTER TABLE u ADD COLUMN b INTEGER PRIMARY KEY DEFAULT 0', "INSERT INTO u VALUES ('x', 1)", "INSERT INTO u VALUES ('x', 0)",
        'CREATE TABLE v (a TEXT)', "INSERT INTO v VALUES ('x')", 'ALTER TABLE v ADD COLUMN id INTEGER PRIMARY KEY DEFAULT 7', 'SELECT * FROM v',
    ]);
    return $r[2] === 'error: Los datos de \'t\' repetirían el valor 0 en (id)' && $r[3] === [['a' => 'x'], ['a' => 'y']]
        && $r[6] === 'ok' && $r[7] === 'ok' && str_starts_with((string)$r[8], 'error: Valor duplicado')
        && $r[11] === 'ok' && $r[12] === [['a' => 'x', 'id' => 7]] ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('RENAME TO no acepta el nombre de una vista, ni deja sin su tabla a una vista o a un trigger que la usan: lo dice y no cambia nada (2.9)', function () use ($raiz) {
    $r = enBaseNueva($raiz, [
        'CREATE TABLE a (x INTEGER)', 'CREATE TABLE b (y INTEGER)', 'CREATE TABLE c (z INTEGER)', 'CREATE VIEW v AS SELECT * FROM c',
        'ALTER TABLE a RENAME TO v',                                                         // 4
        'CREATE TRIGGER tb AFTER INSERT ON b BEGIN INSERT INTO a VALUES (NEW.y); END',
        'ALTER TABLE a RENAME TO a2', 'INSERT INTO b VALUES (1)', 'SELECT * FROM a',          // 6, 7, 8
        'ALTER TABLE c RENAME TO c2', 'SELECT * FROM v',                                     // 9, 10
        "CREATE TRIGGER tc AFTER INSERT ON c BEGIN SELECT RAISE(ABORT, 'no') WHERE NEW.z < 0; END",
        'DROP VIEW v', 'ALTER TABLE c RENAME TO c2', 'INSERT INTO c2 VALUES (1)', 'SELECT * FROM c2', // 13: sus propios triggers no la nombran
    ]);
    $bien = str_contains((string)$r[4], "Ya existe una vista llamada 'v'")
        && str_contains((string)$r[6], "el trigger 'tb' de 'b' la usa") && $r[7] === 'ok' && $r[8] === [['x' => 1]]
        && str_contains((string)$r[9], "la vista 'v' la usa") && $r[10] === []
        && $r[13] === 'ok' && $r[14] === 'ok' && $r[15] === [['z' => 1]];
    return $bien ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('RENAME TO tampoco se hace si el SET NEW, el WHEN o el IF de un trigger leen la tabla (2.9)', function () use ($raiz) {
    $r = enBaseNueva($raiz, [
        'CREATE TABLE a (x INTEGER)', 'CREATE TABLE b (y INTEGER)',
        "CREATE TRIGGER t1 BEFORE INSERT ON b BEGIN SET NEW.y = (SELECT MAX(x) FROM a); END", 'ALTER TABLE a RENAME TO a2', 'DROP TRIGGER t1',
        "CREATE TRIGGER t2 AFTER INSERT ON b WHEN EXISTS (SELECT 1 FROM a) BEGIN SELECT 1; END", 'ALTER TABLE a RENAME TO a2', 'DROP TRIGGER t2',
        "CREATE TRIGGER t3 AFTER INSERT ON b BEGIN IF (SELECT COUNT(*) FROM a) > 0 THEN SELECT 1; END IF; END", 'ALTER TABLE a RENAME TO a2', 'DROP TRIGGER t3',
        'ALTER TABLE a RENAME TO a2',
    ]);
    return str_contains((string)$r[3], "'t1'") && str_contains((string)$r[6], "'t2'") && str_contains((string)$r[9], "'t3'") && $r[11] === 'ok'
        ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('una clave única no puede llamarse como otra de otras columnas, tampoco como la de una UNIQUE de columna (uq_<tabla>_<columna>): una de las dos dejaba de comprobarse (2.9)', function () use ($raiz) {
    $r = enBaseNueva($raiz, [
        'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, x TEXT UNIQUE, y TEXT, CONSTRAINT uq_t_x UNIQUE (y))',
        'CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT, y TEXT, CONSTRAINT u1 UNIQUE (x), CONSTRAINT U1 UNIQUE (y))',
        'CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT UNIQUE, y TEXT)',
        'ALTER TABLE t ADD CONSTRAINT uq_t_x UNIQUE (y)', 'ALTER TABLE t ADD CONSTRAINT PRIMARY UNIQUE (y)',
        "INSERT INTO t VALUES (1, 'a', 'p')", "INSERT INTO t VALUES (2, 'a', 'q')",
        // Con las mismas columnas sobra, pero no estorba: se admite como siempre
        'CREATE TABLE s (x TEXT UNIQUE, UNIQUE (x))', "INSERT INTO s VALUES ('a')", "INSERT INTO s VALUES ('a')",
    ]);
    return $r[0] === "error: Ya existe una restricción llamada 'uq_t_x' en 't'" && $r[1] === "error: Ya existe una restricción llamada 'U1' en 't'"
        && $r[3] === "error: Ya existe una restricción llamada 'uq_t_x' en 't'" && $r[4] === "error: Ya existe una restricción llamada 'PRIMARY' en 't'"
        && str_starts_with((string)$r[6], 'error: Valor duplicado') && $r[7] === 'ok' && str_starts_with((string)$r[9], 'error: Valor duplicado')
        ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});
chk('un valor de ENUM de más de 40 caracteres se puede guardar (2.9: se comparaba recortado y no entraba nunca)', function () use ($raiz) {
    $largo = str_repeat('a', 30) . str_repeat('b', 20);
    $r = enBaseNueva($raiz, [
        "CREATE TABLE e (v ENUM('$largo', 'corto'))", "INSERT INTO e VALUES ('" . strtoupper($largo) . "')",
        "INSERT INTO e VALUES ('" . substr($largo, 0, 45) . "')", 'SELECT v FROM e',
    ]);
    return $r[1] === 'ok' && str_starts_with((string)$r[2], 'error:') && $r[3] === [['v' => $largo]] ?: json_encode($r);
});
chk('CREATE TABLE … AS SELECT guarda cada valor con el tipo de su columna: los números de una columna de texto, como texto, y los enteros de una DOUBLE, como número decimal (2.9)', function () use ($raiz) {
    $nombre = 'ctas' . getmypid();
    Database::crear($nombre, $raiz);
    $b = new Database($nombre, $raiz);
    $b->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, n VARCHAR(5))');
    $b->consultar("INSERT INTO t VALUES (1, 'a'), (2, NULL), (3, '7')");
    $b->consultar("CREATE TABLE t2 AS SELECT id, n, CASE WHEN id = 1 THEN 'x' ELSE id END AS mix, CASE WHEN id = 1 THEN 1.5 ELSE id END AS d, id = 2 AS b FROM t");
    $filas = $b->consultar('SELECT * FROM t2 ORDER BY id');
    $tipos = array_column($b->consultar('SHOW SCHEMA t2'), 'tipo', 'columna');
    $texto = (string)file_get_contents("$raiz/$nombre/t2.json");
    Database::borrar($nombre, $raiz);
    return $tipos === ['id' => 'INTEGER', 'n' => 'TEXT', 'mix' => 'TEXT', 'd' => 'DOUBLE', 'b' => 'INTEGER']
        && $filas === [['id' => 1, 'n' => 'a', 'mix' => 'x', 'd' => 1.5, 'b' => 0], ['id' => 2, 'n' => null, 'mix' => '2', 'd' => 2.0, 'b' => 1],
                       ['id' => 3, 'n' => '7', 'mix' => '3', 'd' => 3.0, 'b' => 0]]
        && str_contains($texto, '"mix":"2","d":2.0') ?: json_encode([$tipos, $filas, $texto]);
});
chk('ADD UNIQUE mira los repetidos al construir su índice, en la misma pasada: entre partes, con un índice ya hecho sobre las mismas columnas y sin índices (2.9)', function () use ($raiz) {
    $v = [];
    for ($i = 1; $i <= 1200; $i++) {
        $v[] = "($i, 'v$i')";
    }
    $r = enBaseNueva($raiz, [
        'CREATE TABLE t (id INTEGER PRIMARY KEY, c TEXT)', 'INSERT INTO t VALUES ' . implode(',', $v),
        "UPDATE t SET c = 'v7' WHERE id = 1150",               // la repetida, en otra parte
        'ALTER TABLE t ADD UNIQUE (c)', "INSERT INTO t VALUES (2000, 'v2000')",
        "UPDATE t SET c = 'v1150' WHERE id = 1150", 'CREATE INDEX ix_c ON t (c)', "UPDATE t SET c = 'v9' WHERE id = 10",
        'ALTER TABLE t ADD UNIQUE (c)',                        // ya hay un índice en (c): se rehace mirando
        "UPDATE t SET c = 'v10' WHERE id = 10", 'ALTER TABLE t ADD UNIQUE (c)', "INSERT INTO t VALUES (2001, 'v1')",
    ]);
    // Sin índices (JSONSQLDB_INDICES = false), por la pasada aparte
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_INDICES", false);'
        . ' require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . ' $d = ' . var_export($raiz, true) . '; JsonSQLDB\Database::crear("sinidx", $d); $b = new JsonSQLDB\Database("sinidx", $d);'
        . ' $b->consultar("CREATE TABLE t (c TEXT)"); $b->consultar("INSERT INTO t VALUES (\'a\'), (\'b\'), (\'a\')");'
        . ' try { $b->consultar("ALTER TABLE t ADD UNIQUE (c)"); echo "sin error"; } catch (Throwable $e) { echo $e->getMessage(); }'
        . ' JsonSQLDB\Database::borrar("sinidx", $d);';
    $sin = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1');
    $rep = static fn(string $x): string => "error: Los datos de 't' repiten el valor (\"$x\") en (c)";
    return $r[3] === $rep('v7') && $r[4] === 'ok' && $r[8] === $rep('v9') && $r[10] === 'ok' && str_starts_with((string)$r[11], 'error: Valor duplicado')
        && $sin === "Los datos de 't' repiten el valor (\"a\") en (c)" ?: json_encode([$r, $sin], JSON_UNESCAPED_UNICODE);
});
chk('CREATE TRIGGER no acepta NEW en un trigger DELETE ni OLD en uno INSERT, en el cuerpo, el WHEN, un IF o un SET NEW, como MySQL (2.9: el error salía en cada escritura)', function () use ($raiz) {
    $r = enBaseNueva($raiz, [
        'CREATE TABLE t (a INTEGER)', 'CREATE TABLE lg (v INTEGER)',
        'CREATE TRIGGER x1 AFTER DELETE ON t BEGIN INSERT INTO lg VALUES (NEW.a); END',
        'CREATE TRIGGER x2 AFTER INSERT ON t BEGIN INSERT INTO lg VALUES (old.a); END',
        'CREATE TRIGGER x3 AFTER INSERT ON t WHEN OLD.a > 1 BEGIN INSERT INTO lg VALUES (1); END',
        'CREATE TRIGGER x4 BEFORE DELETE ON t BEGIN IF NEW.a > 1 THEN SELECT 1; END IF; END',
        'CREATE TRIGGER x5 BEFORE INSERT ON t BEGIN SET NEW.a = OLD.a + 1; END',
        'CREATE TRIGGER x6 AFTER DELETE ON t BEGIN DELETE FROM lg WHERE v IN (SELECT a FROM t WHERE a = NEW.a); END',
        // Los que sí valen
        'CREATE TRIGGER b1 AFTER UPDATE ON t WHEN NEW.a > OLD.a BEGIN INSERT INTO lg VALUES (NEW.a - OLD.a); END',
        'CREATE TRIGGER b2 AFTER DELETE ON t BEGIN INSERT INTO lg VALUES (OLD.a); END',
        'CREATE TRIGGER b3 BEFORE INSERT ON t BEGIN SET NEW.a = NEW.a * 10; END',
        'INSERT INTO t VALUES (1)', 'UPDATE t SET a = 15', 'DELETE FROM t', 'SELECT v FROM lg ORDER BY v', 'SHOW TRIGGERS',
    ]);
    $mal = [];
    foreach ([2 => 'NEW', 3 => 'OLD', 4 => 'OLD', 5 => 'NEW', 6 => 'OLD', 7 => 'NEW'] as $i => $cual) {
        if (!str_contains((string)$r[$i], "$cual no está disponible")) { $mal[] = $r[$i]; }
    }
    return $mal === [] && $r[11] === 'ok' && $r[14] === [['v' => 5], ['v' => 15]] && count($r[15]) === 3 ?: json_encode([$mal, $r], JSON_UNESCAPED_UNICODE);
});

chk('CREATE TRIGGER rechaza en su cuerpo lo que un trigger no puede hacer (DDL, SHOW, ANALYZE), también dentro de un IF (2.9: el error salía en cada escritura)', function () use ($raiz) {
    $r = enBaseNueva($raiz, [
        'CREATE TABLE t (a INTEGER)', 'CREATE TABLE lg (v INTEGER)',
        'CREATE TRIGGER x1 AFTER INSERT ON t BEGIN DROP TABLE lg; END',
        'CREATE TRIGGER x2 AFTER INSERT ON t BEGIN IF NEW.a > 1 THEN CREATE TABLE z (b INTEGER); END IF; END',
        'CREATE TRIGGER x3 AFTER INSERT ON t BEGIN SHOW TABLES; END',
        'CREATE TRIGGER x4 AFTER INSERT ON t BEGIN ANALYZE; END',
        'CREATE TRIGGER b1 AFTER INSERT ON t BEGIN SELECT 1; INSERT INTO lg VALUES (NEW.a); UPDATE lg SET v = v + 1; END',
        'INSERT INTO t VALUES (4)', 'SELECT v FROM lg', 'SHOW TRIGGERS',
    ]);
    $mal = array_filter(array_slice($r, 2, 4), fn($x) => !str_contains((string)$x, 'solo puede hacer INSERT, UPDATE, DELETE o SELECT'));
    return $mal === [] && $r[6] === 'ok' && $r[8] === [['v' => 5]] && count($r[9]) === 1 ?: json_encode($r, JSON_UNESCAPED_UNICODE);
});

echo "\n== Limpieza ==\n";
chk('borrar las bases de prueba', function () use ($raiz) {
    Storage::borrarBase($raiz, 'tienda');
    Storage::borrarBase($raiz, 'otra');
    clearstatcache();
    return @rmdir($raiz) && !is_dir($raiz) ?: 'quedan: ' . implode(',', array_diff((array)scandir($raiz), ['.', '..']));
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
