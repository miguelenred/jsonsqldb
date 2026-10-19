<?php
declare(strict_types=1);

/**
 * Prueba de la fase 1 (núcleo). Ejecutar:  php tests/f1_nucleo.php
 * No deja nada en disco: borra la base de pruebas al terminar.
 *
 * https://miguelenred.es/jsonsqldb
 */
require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Catalog;
use JsonSQLDB\CatalogDdl;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Storage;
use JsonSQLDB\StorageCambios;
use JsonSQLDB\StorageEntera;
use JsonSQLDB\StorageEscritura;
use JsonSQLDB\Types;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test';
$base = 'pruebas';
$ok = 0; $ko = 0;

function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
        if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
        elseif ($r === null) { echo "  --   $titulo (se salta con este PHP)\n"; }
        else { $ko++; echo "  FALLO $titulo -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        global $ko; $ko++;
        echo "  FALLO $titulo -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function esperaError(string $titulo, string $estado, callable $fn): void {
    chk($titulo, static function () use ($estado, $fn) {
        try { $fn(); } catch (JsonSqlDbError $e) { return $e->sqlState === $estado ?: "estado {$e->sqlState}"; }
        return 'no lanzó error';
    });
}

if (is_dir("$raiz/$base")) { Storage::borrarBase($raiz, $base); }
@mkdir($raiz, 0775, true);

echo "\n== Tipos ==\n";
chk('INTEGER desde string', fn() => Types::cast('42', ['name'=>'x','type'=>'INTEGER','length'=>null,'scale'=>null]) === 42);
chk('DECIMAL redondea a escala', fn() => Types::cast('10.567', ['name'=>'x','type'=>'DECIMAL','length'=>null,'scale'=>2]) === 10.57);
chk('TEXT respeta longitud', fn() => Types::cast('abc', ['name'=>'x','type'=>'TEXT','length'=>5,'scale'=>null]) === 'abc');
esperaError('TEXT excede longitud', 'TYPE', fn() => Types::cast('abcdef', ['name'=>'x','type'=>'TEXT','length'=>5,'scale'=>null]));
chk('fecha solo día', fn() => Types::cast('2026-02-28', ['name'=>'f','type'=>'DATETIME','length'=>null,'scale'=>null]) === '2026-02-28');
chk('fecha con hora', fn() => Types::cast('2026-02-28 10:05', ['name'=>'f','type'=>'DATETIME','length'=>null,'scale'=>null]) === '2026-02-28 10:05');
chk('fecha con milisegundos', fn() => Types::cast('2026-02-28T10:05:09.7', ['name'=>'f','type'=>'DATETIME','length'=>null,'scale'=>null]) === '2026-02-28 10:05:09.700');
esperaError('fecha inexistente', 'TYPE', fn() => Types::cast('2026-02-30', ['name'=>'f','type'=>'DATETIME','length'=>null,'scale'=>null]));
chk('alias VARCHAR(50)', fn() => Types::parse('VARCHAR(50)') === ['type'=>'TEXT','length'=>50,'scale'=>null]);
chk('alias DECIMAL(10,3)', fn() => Types::parse('DECIMAL(10,3)') === ['type'=>'DECIMAL','length'=>null,'scale'=>3]);
chk('DECIMAL sin escala la deja sin fijar', fn() => Types::parse('DECIMAL') === ['type'=>'DECIMAL','length'=>null,'scale'=>null]);
chk('DECIMAL sin escala toma 2 en la columna', fn() =>
    Catalog::normalizarColumna(['name'=>'x','type'=>'DECIMAL'])['scale'] === 2);
chk('la escala guardada sobrevive a releer la estructura', fn() =>
    Catalog::normalizarColumna(Catalog::normalizarColumna(['name'=>'x','type'=>'DECIMAL(10,3)']))['scale'] === 3);
esperaError('tipo desconocido', 'TYPE', fn() => Types::parse('GEOMETRY'));

echo "\n== Base de datos ==\n";
chk('crear base', function () use ($raiz, $base) { Storage::crearBase($raiz, $base); return is_file("$raiz/$base/_database.json"); });
esperaError('crear base duplicada', 'CONFIG', fn() => Storage::crearBase($raiz, $base));
esperaError('nombre de base inválido', 'CONFIG', fn() => Storage::crearBase($raiz, '../fuera'));
chk('listado de bases', fn() => Storage::bases($raiz) === [$base]);

$st  = new Storage($raiz, $base);
$cat = new Catalog($st);

echo "\n== Estructura ==\n";
esperaError('escribir sin bloqueo exclusivo', 'LOCK', fn() => CatalogDdl::crearTabla($cat, 'x', ['columns'=>[['name'=>'a','type'=>'INT']]]));

$st->bloquear(true);

chk('crear usuarios', function () use ($cat) {
    CatalogDdl::crearTabla($cat, 'usuarios', [
        'columns' => [
            ['name'=>'id',     'type'=>'INTEGER',      'pk'=>true, 'autoincrement'=>true],
            ['name'=>'nombre', 'type'=>'VARCHAR(50)',  'notnull'=>true],
            ['name'=>'email',  'type'=>'VARCHAR(120)', 'unique'=>true],
            ['name'=>'saldo',  'type'=>'DECIMAL(10,2)','default'=>0],
            ['name'=>'alta',   'type'=>'DATETIME'],
        ],
    ]);
    return $cat->existe('usuarios');
});

chk('crear pedidos con FK', function () use ($cat) {
    CatalogDdl::crearTabla($cat, 'pedidos', [
        'columns' => [
            ['name'=>'id',         'type'=>'INTEGER','pk'=>true,'autoincrement'=>true],
            ['name'=>'usuario_id', 'type'=>'INTEGER','notnull'=>true],
            ['name'=>'total',      'type'=>'DECIMAL(10,2)','notnull'=>true],
        ],
        'foreign_keys' => [
            ['columns'=>['usuario_id'],'table'=>'usuarios','references'=>['id'],'on_delete'=>'CASCADE'],
        ],
    ]);
    return $cat->meta('pedidos')['foreign_keys'][0]['name'] === 'fk_pedidos_usuario_id';
});

esperaError('FK a tabla inexistente', 'SCHEMA', fn() => CatalogDdl::crearTabla($cat, 't1', [
    'columns'=>[['name'=>'a','type'=>'INT']],
    'foreign_keys'=>[['columns'=>['a'],'table'=>'nohay','references'=>['id']]],
]));
esperaError('AUTOINCREMENT no entero', 'SCHEMA', fn() => CatalogDdl::crearTabla($cat, 't2', [
    'columns'=>[['name'=>'a','type'=>'TEXT','autoincrement'=>true]],
]));
esperaError('columna duplicada', 'SCHEMA', fn() => CatalogDdl::crearTabla($cat, 't3', [
    'columns'=>[['name'=>'a','type'=>'INT'],['name'=>'A','type'=>'INT']],
]));
esperaError('borrar tabla referenciada', 'CONSTRAINT', fn() => CatalogDdl::borrarTabla($cat, 'usuarios'));

chk('clave primaria', fn() => Catalog::clavePrimaria($cat->meta('usuarios')) === ['id']);
chk('conjuntos únicos', fn() => count(Catalog::conjuntosUnicos($cat->meta('usuarios'))) === 2);
chk('columna autoincremento', fn() => Catalog::columnaAutoincremento($cat->meta('usuarios')) === 'id');

echo "\n== Datos ==\n";
chk('insertar y releer', function () use ($st, $cat) {
    $meta  = $cat->meta('usuarios');
    $filas = [];
    foreach ([['Ana','ana@x.es',10.555,'2026-01-15 08:30'], ['Luis','luis@x.es',0,null]] as $d) {
        $fila = ['id' => count($filas) + 1];
        foreach (['nombre','email','saldo','alta'] as $i => $c) {
            $fila[$c] = Types::cast($d[$i], Catalog::columna($meta, $c));
        }
        $filas[] = $fila;
    }
    StorageEntera::guardarFilas($st, 'usuarios', $filas);
    $leidas = StorageEscritura::leerFilas($st, 'usuarios');
    return count($leidas) === 2
        && $leidas[0]['id'] === 1 && $leidas[1]['id'] === 2
        && $leidas[0]['saldo'] === 10.56
        && $leidas[0]['alta'] === '2026-01-15 08:30'
        && $leidas[1]['alta'] === null;
});

chk('fichero de datos legible (una fila por línea)', function () use ($raiz, $base) {
    $txt = (string)file_get_contents("$raiz/$base/usuarios.json");
    return substr_count($txt, "\n    {") === 2 && str_contains($txt, '"table": "usuarios"');
});


echo "\n== ALTER TABLE ==\n";
chk('añadir columna', function () use ($cat, $st) {
    CatalogDdl::anadirColumna($cat, 'usuarios', ['name'=>'ciudad','type'=>'VARCHAR(60)','default'=>'Malaga']);
    $filas = StorageEscritura::leerFilas($st, 'usuarios');
    return $filas[0]['ciudad'] === 'Malaga' && Catalog::columna($cat->meta('usuarios'), 'ciudad') !== null;
});
chk('renombrar columna', function () use ($cat, $st) {
    CatalogDdl::renombrarColumna($cat, 'usuarios', 'ciudad', 'poblacion');
    return array_key_exists('poblacion', StorageEscritura::leerFilas($st, 'usuarios')[0]);
});
chk('borrar columna', function () use ($cat, $st) {
    CatalogDdl::borrarColumna($cat, 'usuarios', 'poblacion');
    return !array_key_exists('poblacion', StorageEscritura::leerFilas($st, 'usuarios')[0])
        && Catalog::columna($cat->meta('usuarios'), 'poblacion') === null;
});
esperaError('borrar columna referenciada por FK', 'CONSTRAINT', fn() => CatalogDdl::borrarColumna($cat, 'usuarios', 'id'));
esperaError('borrar columna de una FK propia', 'CONSTRAINT', fn() => CatalogDdl::borrarColumna($cat, 'pedidos', 'usuario_id'));

echo "\n== Triggers ==\n";
/** Los triggers de pedidos para INSERT en un momento (BEFORE o AFTER) */
$disparos = static fn(string $momento): array => array_values(array_filter($cat->meta('pedidos')['triggers'],
    static fn(array $t): bool => $t['timing'] === $momento && $t['event'] === 'INSERT'));
chk('crear trigger', function () use ($cat, $disparos) {
    CatalogDdl::crearTrigger($cat, 'pedidos', [
        'name'=>'trg_pedidos_ins','timing'=>'AFTER','event'=>'INSERT',
        'when'=>'NEW.total > 0',
        'body'=>['UPDATE usuarios SET saldo = saldo + NEW.total WHERE id = NEW.usuario_id'],
        'sql'=>'CREATE TRIGGER trg_pedidos_ins ...',
    ]);
    return count($disparos('AFTER')) === 1 && $disparos('BEFORE') === [];
});
esperaError('trigger duplicado', 'SCHEMA', fn() => CatalogDdl::crearTrigger($cat, 'pedidos', [
    'name'=>'trg_pedidos_ins','timing'=>'AFTER','event'=>'INSERT','body'=>['DELETE FROM usuarios'],
]));
esperaError('evento no soportado', 'SCHEMA', fn() => CatalogDdl::crearTrigger($cat, 'pedidos', [
    'name'=>'trg_x','timing'=>'AFTER','event'=>'TRUNCATE','body'=>['DELETE FROM usuarios'],
]));
chk('borrar trigger', fn() => CatalogDdl::borrarTrigger($cat, 'trg_pedidos_ins') === 'pedidos' && $disparos('AFTER') === []);

echo "\n== Renombrar tabla ==\n";
chk('renombrar propaga FK', function () use ($cat) {
    CatalogDdl::renombrarTabla($cat, 'usuarios', 'clientes');
    return $cat->existe('clientes') && !$cat->existe('usuarios')
        && $cat->meta('pedidos')['foreign_keys'][0]['table'] === 'clientes'
        && $cat->meta('clientes')['autoincrement']['next'] === 1;
});

echo "\n== Paginación y rendimiento ==\n";
chk('una tabla grande se reparte en las partes que tocan', function () use ($cat, $st, $raiz, $base) {
    CatalogDdl::crearTabla($cat, 'log', ['columns'=>[
        ['name'=>'id','type'=>'INTEGER','pk'=>true],
        ['name'=>'texto','type'=>'TEXT'],
        ['name'=>'fecha','type'=>'DATETIME'],
    ]]);
    $filas = [];
    for ($i = 1; $i <= 12000; $i++) {
        $filas[] = ['id'=>$i, 'texto'=>"línea $i", 'fecha'=>'2026-08-19 12:00:00'];
    }
    $t0 = microtime(true);
    StorageEntera::guardarFilas($st, 'log', $filas);
    $tw = microtime(true) - $t0;

    // Cuántas partes salen depende de JSONSQLDB_FILAS_POR_PARTE, así que se
    // calcula en vez de darlo por sabido: si no, cambiar el valor por defecto
    // rompe la prueba sin que nada esté mal
    $esperadas = (int)ceil(12000 / JsonSQLDB\Config::filasPorParte());
    $partes = is_file("$raiz/$base/log.json")
           && !is_file("$raiz/$base/log.part" . ($esperadas + 1) . ".json");
    for ($n = 2; $n <= $esperadas; $n++) {
        $partes = $partes && is_file("$raiz/$base/log.part$n.json");
    }

    $t0 = microtime(true);
    $leidas = StorageEscritura::leerFilas($st, 'log');   // desde caché
    $tc = microtime(true) - $t0;

    printf("       %d partes | escritura %.3fs | lectura cacheada %.4fs | parte %.1f KB\n",
        $esperadas, $tw, $tc, filesize("$raiz/$base/log.json") / 1024);

    return $partes && count($leidas) === 12000 && $leidas[11999]['id'] === 12000;
});

chk('reducir filas elimina partes sobrantes', function () use ($st, $raiz, $base) {
    StorageEntera::guardarFilas($st, 'log', array_slice(StorageEscritura::leerFilas($st, 'log'), 0, 10));
    return !is_file("$raiz/$base/log.part2.json") && count(StorageEscritura::leerFilas($st, 'log')) === 10;
});

echo "\n== Caché e invalidación ==\n";
chk('la caché devuelve datos actualizados tras escribir', function () use ($st) {
    $antes = count(StorageEscritura::leerFilas($st, 'log'));
    $filas = StorageEscritura::leerFilas($st, 'log');
    $filas[] = ['id'=>999,'texto'=>'nueva','fecha'=>null];
    StorageEntera::guardarFilas($st, 'log', $filas);
    return count(StorageEscritura::leerFilas($st, 'log')) === $antes + 1;
});

echo "\n== Bloqueo ==\n";
chk('anidado exclusivo', function () use ($st) {
    $st->bloquear(true); $st->desbloquear();
    return StorageEscritura::enEscritura($st);
});
$st->desbloquear();
chk('liberado del todo', fn() => StorageEscritura::enEscritura($st) === false);
chk('otra petición ve la escritura (caché invalidada por revisión)', function () use ($raiz, $base) {
    $st2 = new Storage($raiz, $base);   // instancia nueva = simula otra petición
    $st2->bloquear(false);
    $n = count(StorageEscritura::leerFilas($st2, 'log'));
    $st2->desbloquear();
    return $n === 11;
});
chk('lectura simultánea', function () use ($st, $raiz, $base) {
    $st->bloquear(false);
    $st2 = new Storage($raiz, $base);
    $st2->bloquear(false);      // no debe bloquearse
    $n = count(StorageEscritura::leerFilas($st2, 'log'));
    $st2->desbloquear();
    $st->desbloquear();
    return $n === 11;
});
esperaError('no se puede escribir dentro de una lectura', 'LOCK', function () use ($st) {
    $st->bloquear(false);
    try { $st->bloquear(true); } finally { $st->desbloquear(); }
});
chk('si el bloqueo de una tabla no se puede coger, el de la base no se queda cogido, ni escribiendo por partes; la escritura siguiente tiene su exclusivo de verdad (2.9)', function () use ($raiz, $base) {
    $dir = "$raiz/$base";
    mkdir("$dir/.zz.lock");                         // un fichero de bloqueo que no se puede abrir
    $s = new Storage($raiz, $base);
    // ¿Puede otro coger el exclusivo de la base? flock va por descriptor: vale en el mismo proceso
    $libre = static function () use ($dir): bool {
        $fh = fopen("$dir/.lock", 'c');
        $si = flock($fh, LOCK_EX | LOCK_NB);
        if ($si) { flock($fh, LOCK_UN); }
        fclose($fh);
        return $si;
    };
    $r = [];
    foreach ([static fn() => $s->bloquear(true, 'zz'), static fn() => StorageCambios::bloquearPartes($s, 'zz')] as $pedir) {
        try { $pedir(); $r[] = 'no dio error'; } catch (JsonSqlDbError $e) { $r[] = $libre() && !StorageEscritura::enEscritura($s); }
    }
    rmdir("$dir/.zz.lock");
    $s->bloquear(true);
    $r[] = !$libre();
    $s->desbloquear();
    return $r === [true, true, true] ?: json_encode($r);
});

echo "\n== Sincronización del directorio ==\n";

chk('el directorio se puede sincronizar de verdad, no en apariencia', function () {
    // Escribir un fichero con fsync pone su CONTENIDO en el disco, pero el
    // nombre puede seguir solo en la caché del sistema: tras un corte, el
    // fichero puede no aparecer en el directorio. Por eso hay que sincronizar
    // también el directorio.
    //
    // Lo que se comprueba aquí es CÓMO se hace, porque hay una forma que parece
    // funcionar y no hace nada: fsync() sobre lo que devuelve opendir()
    // devuelve false en silencio. Hay que abrir el directorio con fopen().
    if (!function_exists('fsync')) {
        echo "       (omitida: fsync() necesita PHP 8.1)\n";
        return true;
    }
    $dir = sys_get_temp_dir() . '/jsonsqldb_fsyncdir_' . getmypid();
    @mkdir($dir, 0775, true);

    $porOpendir = @fsync(@opendir($dir));
    $fh = @fopen($dir, 'r');
    $porFopen = $fh !== false ? @fsync($fh) : false;
    if ($fh !== false) { @fclose($fh); }
    @rmdir($dir);

    if ($porFopen !== true) {
        return 'fsync() sobre el directorio abierto con fopen() no funcionó';
    }
    // Si algún día opendir() empezara a valer, mejor enterarse: significaría
    // que el comentario del código ya no describe la realidad
    return $porOpendir === false
        ?: 'fsync(opendir()) ha empezado a funcionar: revisar el comentario de fsyncDir()';
});

echo "\n== La versión, en un solo sitio ==\n";

chk('el motor, el panel y el fichero VERSION dicen lo mismo', function () {
    // Había una constante Config::VERSION fijada a mano en '1.0.0' que no leía
    // nadie, y llevaba versiones desincronizada sin que se notara. Lo mismo le
    // pasó al índice de la documentación, que se quedó tres versiones atrás.
    // Ahora todo sale del fichero VERSION, y esto lo comprueba.
    $raizProyecto = dirname(__DIR__);
    $fichero = $raizProyecto . '/VERSION';
    if (!is_file($fichero)) {
        return 'no hay fichero VERSION';
    }
    $esperada = trim((string)file_get_contents($fichero));
    if ($esperada === '') {
        return 'el fichero VERSION está vacío';
    }

    if (JsonSQLDB\Config::version() !== $esperada) {
        return "el motor dice '" . JsonSQLDB\Config::version() . "' y VERSION dice '$esperada'";
    }

    // El panel tiene su propia función porque no carga el motor; se comprueba
    // en un proceso aparte para no arrastrar aquí sus constantes
    $codigo = 'require ' . var_export($raizProyecto . '/engine/bootstrap.php', true) . ';'
            . 'require ' . var_export($raizProyecto . '/jsonsqldbadmin/lib/util.php', true) . ';'
            . 'echo version();';
    $delPanel = trim((string)shell_exec(
        escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    if ($delPanel !== $esperada) {
        return "el panel dice '$delPanel' y VERSION dice '$esperada'";
    }

    // Y la última entrada del CHANGELOG tiene que ser esa versión
    $cambios = (string)@file_get_contents($raizProyecto . '/CHANGELOG.md');
    if (preg_match('/^## \[([^\]]+)\]/m', $cambios, $m) !== 1) {
        return 'no se pudo leer la primera versión del CHANGELOG';
    }
    return $m[1] === $esperada ?: "el CHANGELOG empieza en '{$m[1]}' y VERSION dice '$esperada'";
});

echo "\n== Limpieza ==\n";
chk('sin ficheros temporales huérfanos', function () use ($raiz, $base) {
    return glob("$raiz/$base/*.tmp") === [];
});
chk('borrar base', function () use ($raiz, $base) {
    Storage::borrarBase($raiz, $base);
    return !is_dir("$raiz/$base");
});

echo "\n== Vigilancia de memoria ==\n";
chk('corta bien y deja el proceso utilizable, con distintos límites', function () use ($raiz) {
    // El consumo pega saltos al crecer los arrays de PHP, y el salto depende de
    // la versión y del tamaño. Se prueban varios límites para que un salto
    // grande no se cuele hasta el fatal en ninguno.
    $fallos = [];
    foreach (['16M', '32M', '64M', '128M'] as $limite) {
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
                . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
                . '$r = ' . var_export($raiz . '/mem2', true) . ';'
                . '@mkdir($r, 0777, true);'
                . 'if (!is_dir("$r/m")) { JsonSQLDB\\Database::crear("m", $r); }'
                . '$bd = new JsonSQLDB\\Database("m", $r);'
                . 'if ($bd->consultar("SHOW TABLES") === []) {'
                . '  $bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(200))");'
                . '  for ($i = 0; $i < 1200; $i++) { $bd->consultar("INSERT INTO t (v) VALUES (?)", [str_repeat("x", 180)]); } }'
                . 'JsonSQLDB\\Memoria::alAgotarse(function () { echo "MEMORIA"; });'
                . 'try { $bd->consultar("SELECT a.id, b.v FROM t a CROSS JOIN t b"); echo "SIN CORTAR"; }'
                . 'catch (JsonSQLDB\\JsonSqlDbError $e) { echo $e->sqlState; }'
                // Y después una consulta pequeña: tras un corte, PHP conserva los
                // bloques que ya pidió al sistema aunque estén libres. Si se
                // midiera eso en vez de la memoria en uso, esta segunda consulta
                // se cortaría nada más empezar aunque quepa de sobra.
                . 'try { echo "|", $bd->consultar("SELECT COUNT(*) AS n FROM t")[0]["n"]; }'
                . 'catch (Throwable $e) { echo "|SEGUNDA FALLA"; }';
        $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$limite -r "
                . escapeshellarg($codigo) . ' 2>&1'));
        if ($salida !== 'MEMORIA|1200') {
            $fallos[] = "$limite -> " . substr($salida, 0, 60);
        }
    }
    exec('rm -rf ' . escapeshellarg($raiz . '/mem2'));
    return $fallos === [] ?: implode(' | ', $fallos);
});
chk('una consulta que no cabe en memoria se corta con un error, no con un fatal', function () use ($raiz) {
    // En otro proceso, con poca memoria y una tabla que no cabe multiplicada
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$r = ' . var_export($raiz . '/mem', true) . ';'
            . '@mkdir($r, 0777, true);'
            . 'JsonSQLDB\\Database::crear("m", $r);'
            . '$bd = new JsonSQLDB\\Database("m", $r);'
            . '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(200))");'
            . 'for ($i = 0; $i < 1500; $i++) { $bd->consultar("INSERT INTO t (v) VALUES (?)", [str_repeat("x", 180)]); }'
            . 'try { $bd->consultar("SELECT a.id, b.v FROM t a CROSS JOIN t b"); echo "SIN CORTAR"; }'
            . 'catch (JsonSQLDB\\JsonSqlDbError $e) { echo $e->sqlState, "|", $bd->consultar("SELECT COUNT(*) AS n FROM t")[0]["n"]; }';

    $salida = trim((string)shell_exec(
        escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M -r ' . escapeshellarg($codigo) . ' 2>&1'));

    // MEMORIA = cortó bien; y después sigue respondiendo, así que el proceso vivía
    return $salida === 'MEMORIA|1500' ?: $salida;
});
chk('la red actúa aunque la predicción esté desactivada', function () use ($raiz) {
    // Esta es la garantía que no depende de acertar: aunque el vigilante no
    // llegue a tiempo, la función de cierre convierte el final en un aviso.
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'define("JSONSQLDB_MEMORIA_VIGILAR", false);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . 'JsonSQLDB\\Memoria::alAgotarse(function () { echo "RED"; });'
            . '$bd = new JsonSQLDB\\Database("m", ' . var_export($raiz . '/mem', true) . ');'
            . 'try { $bd->consultar("SELECT a.id, b.v FROM t a CROSS JOIN t b"); echo "SIN CORTAR"; }'
            . 'catch (Throwable $e) { echo "excepcion"; }';
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M -r '
            . escapeshellarg($codigo) . ' 2>/dev/null');
    return str_contains($salida, 'RED') ?: trim($salida);
});
// La red (2.9): al agotarse la memoria, el sitio para responder sale de subir
// memory_limit en ese momento; si el hosting no deja cambiarlo, de una reserva.
// El aviso de estas pruebas necesita 1 MB, como una respuesta de verdad que
// tenga algo de trabajo: sin margen, PHP no llega a escribirlo
$redCodigo = static function (bool $sinIniSet): string {
    return escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M' . ($sinIniSet ? ' -d disable_functions=ini_set' : '') . ' -r '
        . escapeshellarg('define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . 'JsonSQLDB\\Memoria::alAgotarse(function () { $r = str_repeat("x", 1048576); echo "RESPONDE ", strlen($r); });'
            . 'JsonSQLDB\\Memoria::iniciar(); $a = []; while (true) { $a[] = str_repeat("y", 500); }')
        . ' 2>/dev/null';
};
chk('al agotarse la memoria hay sitio para responder: se sube memory_limit en ese momento', fn() =>
    str_contains((string)shell_exec($redCodigo(false)), 'RESPONDE 1048576') ?: 'no respondió');
chk('si el hosting no deja cambiar memory_limit, el sitio sale de la reserva', fn() =>
    str_contains((string)shell_exec($redCodigo(true)), 'RESPONDE 1048576') ?: 'no respondió');
chk('sin agotarse no se aparta memoria, y al terminar no se toca memory_limit', function () {
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=64M -r ' . escapeshellarg(
        'define("JSONSQLDB_CONEXION_DIRECTA", true);'
        . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . '$antes = memory_get_usage(); JsonSQLDB\\Memoria::iniciar(); echo memory_get_usage() - $antes < 262144 ? "SIN RESERVA " : "CON RESERVA ";'
        . 'register_shutdown_function(function () { echo ini_get("memory_limit"); });') . ' 2>&1'));
    return $salida === 'SIN RESERVA 64M' ?: $salida;
});
chk('el registro, por defecto, apunta las escrituras y los errores, no las lecturas (2.9)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_log';
    $limpiar = static function () use ($dir): void {
        if (is_dir("$dir/l")) { Storage::borrarBase($dir, 'l'); }
        array_map('unlink', glob("$dir/logs/*") ?: []);
        @rmdir("$dir/logs");
        @rmdir($dir);
    };
    $limpiar();
    @mkdir("$dir/logs", 0775, true);
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_LOG_PATH", ' . var_export("$dir/logs", true) . ');'
        . 'require ' . var_export(dirname(__DIR__) . '/config.php', true) . ';'
        . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . 'JsonSQLDB\\Database::crear("l", ' . var_export($dir, true) . '); $b = new JsonSQLDB\\Database("l", ' . var_export($dir, true) . ');'
        . '$b->consultar("CREATE TABLE t (x INTEGER)"); $b->consultar("INSERT INTO t VALUES (1)");'
        . 'foreach (["SELECT x FROM t", "SELECT x FROM t UNION SELECT 2", "SHOW TABLES", "CHECK TABLES"] as $q) { $b->consultar($q); }'
        . 'try { $b->consultar("SELECT nada FROM t"); } catch (Throwable $e) {}';
    shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1');
    $ops = [];
    foreach ((array)glob("$dir/logs/consultas-*.json") as $f) {
        foreach (file((string)$f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $e = json_decode($l, true);
            $ops[] = ($e['op'] ?? '?') . (isset($e['error']) && $e['error'] !== null ? ' (error)' : '');
        }
    }
    $limpiar();
    return $ops === ['CREATE TABLE', 'INSERT', 'SELECT (error)'] && !is_dir($dir) ?: json_encode($ops);
});
chk('sin JSONSQLDB_LOG_NIVEL (un config.php de antes de la 2.9), el registro apunta las escrituras y los errores, como dice la documentación (2.9)', fn() =>
    !defined('JSONSQLDB_LOG_NIVEL') && JsonSQLDB\Config::logNivel() === 'escrituras' ?: JsonSQLDB\Config::logNivel());
chk('con el registro de config.php (escrituras y errores), una lectura no carga Logger, que no va a escribir nada, y una escritura sí y queda registrada; con el nivel errores, una escritura no lo carga y un error sí (2.10)', function () {
    // Con el config.php de la raíz, que enciende el registro con el nivel
    // escrituras; si cambia lo que trae, cambia lo que se espera aquí
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_log3';
    exec('rm -rf ' . escapeshellarg($dir));
    $codigo = static fn(string $sql, string $nivel): string => 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_LOG_PATH", ' . var_export("$dir/logs", true) . ');'
        . ($nivel === '' ? '' : 'define("JSONSQLDB_LOG_NIVEL", ' . var_export($nivel, true) . ');')
        . 'require ' . var_export(dirname(__DIR__) . '/config.php', true) . ';'
        . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . 'if (!is_dir(' . var_export("$dir/l", true) . ')) { JsonSQLDB\\Database::crear("l", ' . var_export($dir, true) . '); }'
        . 'try { (new JsonSQLDB\\Database("l", ' . var_export($dir, true) . '))->consultar(' . var_export($sql, true) . '); } catch (JsonSQLDB\\JsonSqlDbError $e) {}'
        . 'echo (int)in_array("Logger", array_map(fn($f) => basename($f, ".php"), get_included_files()), true);';
    $cargado = [];
    foreach ([['CREATE TABLE t (x INTEGER)', ''], ['SELECT 1', ''], ['SELECT x FROM t', ''], ['INSERT INTO t VALUES (1)', ''],
              ['INSERT INTO t VALUES (2)', 'errores'], ['SELECT nada FROM t', 'errores']] as [$sql, $nivel]) {
        $cargado[] = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo($sql, $nivel)) . ' 2>&1'));
    }
    $ops = [];
    foreach ((array)glob("$dir/logs/consultas-*.json") as $f) {
        foreach (file((string)$f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $e = json_decode($l, true);
            $ops[] = ($e['op'] ?? '?') . (isset($e['error']) && $e['error'] !== null ? ' (error)' : '');
        }
    }
    exec('rm -rf ' . escapeshellarg($dir));
    return $cargado === ['1', '0', '0', '1', '0', '1'] && $ops === ['CREATE TABLE', 'INSERT', 'SELECT (error)'] ?: json_encode([$cargado, $ops]);
});
chk('el registro escribe la línea aunque el corte de la SQL caiga en medio de una letra o lleve bytes que no son UTF-8, y la corta sin partir letras (2.9)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_log2';
    exec('rm -rf ' . escapeshellarg($dir));
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_LOG_ACTIVO", true); define("JSONSQLDB_LOG_MAX_SQL", 25);'
        . 'define("JSONSQLDB_LOG_PARAMS", true); define("JSONSQLDB_LOG_PATH", ' . var_export("$dir/logs", true) . ');'
        . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . 'JsonSQLDB\\Database::crear("l", ' . var_export($dir, true) . '); $b = new JsonSQLDB\\Database("l", ' . var_export($dir, true) . ');'
        . '$b->consultar("CREATE TABLE t (x TEXT)");'
        . '$b->consultar("INSERT INTO t VALUES (\'añoñ\')");'                  // el corte, a los 25 bytes, cae dentro de la ñ
        . '$b->consultar("INSERT /* \xff */ INTO t VALUES (\'b\')");'           // un byte que no es UTF-8, en un comentario
        . 'JsonSQLDB\\Logger::registrar("l", "INSERT", "X", 1, 1.0, null, [NAN, "a\xffb"]);';
    // Sin shell: con PHP 8.0, escapeshellarg() quita las ñ si el locale es C
    proc_close(proc_open([PHP_BINARY, '-r', $codigo], [], $tubos));
    $vistas = [];
    foreach ((array)glob("$dir/logs/consultas-*.json") as $f) {
        foreach (file((string)$f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $e = json_decode($l, true);
            $vistas[] = [$e['sql'] ?? null, $e['params'] ?? null];
        }
    }
    exec('rm -rf ' . escapeshellarg($dir));
    return $vistas === [['CREATE TABLE t (x TEXT)', null], ["INSERT INTO t VALUES ('a…", null], ["INSERT /* \u{FFFD} */ INTO t VAL…", null], ['X', [0, "a\u{FFFD}b"]]]
        ?: json_encode($vistas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});
chk('sin vigilancia, la misma consulta muere con un fatal de PHP', function () use ($raiz) {
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'define("JSONSQLDB_MEMORIA_VIGILAR", false);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$bd = new JsonSQLDB\\Database("m", ' . var_export($raiz . '/mem', true) . ');'
            . 'try { $bd->consultar("SELECT a.id, b.v FROM t a CROSS JOIN t b"); echo "SIN CORTAR"; }'
            . 'catch (Throwable $e) { echo "capturado"; }';

    $salida = (string)shell_exec(
        escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M -r ' . escapeshellarg($codigo) . ' 2>&1');

    // Se comprueba que el interruptor sirve de algo: sin él, fatal incapturable
    return str_contains($salida, 'Allowed memory size') ?: trim($salida);
});
chk('también corta con filas grandes, donde cada una pesa mucho', function () use ($raiz) {
    // Con filas de 4 KB, una sola fila puede ocupar más que una tanda entera de
    // filas pequeñas: el margen hay que calcularlo por fila, no por tanda.
    $base = $raiz . '/mem3';
    $prep = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
          . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
          . '$r = ' . var_export($base, true) . ';'
          . '@mkdir($r, 0777, true);'
          . 'JsonSQLDB\\Database::crear("g", $r);'
          . '$bd = new JsonSQLDB\\Database("g", $r);'
          . '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(4000))");'
          // 500 filas de 3,9 KB: el producto cartesiano son 250.000 filas, que
          // no caben ni con 64 MB. Eran 300 y con las mejoras de la 2.2 el
          // resultado pasó a caber, así que la prueba dejó de probar el corte.
          . '$p = array_fill(0, 500, str_repeat("y", 3900));'
          . '$v = implode(",", array_fill(0, 500, "(?)"));'
          . '$bd->consultar("INSERT INTO t (v) VALUES $v", $p);';
    shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M -r ' . escapeshellarg($prep) . ' 2>&1');

    $leer = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
          . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
          . '$bd = new JsonSQLDB\\Database("g", ' . var_export($base, true) . ');'
          . 'JsonSQLDB\\Memoria::alAgotarse(function () { echo "MEMORIA"; });'
          . 'try { $bd->consultar("SELECT a.id, b.v FROM t a CROSS JOIN t b"); echo "SIN CORTAR"; }'
          . 'catch (JsonSQLDB\\JsonSqlDbError $e) { echo $e->sqlState; }';

    $fallos = [];
    foreach (['16M', '32M', '48M', '64M'] as $limite) {
        $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . " -d memory_limit=$limite -r "
                . escapeshellarg($leer) . ' 2>&1'));
        if ($salida !== 'MEMORIA') {
            $fallos[] = "$limite -> " . substr($salida, 0, 50);
        }
    }
    exec('rm -rf ' . escapeshellarg($base));
    return $fallos === [] ?: implode(' | ', $fallos);
});
chk('un fichero que no cabe se rechaza antes de leerlo', function () use ($raiz) {
    // El pico de json_decode ocurre en una sola instrucción: comprobar cada 512
    // filas no llega a tiempo. Se mira el tamaño del fichero antes de abrirlo.
    $base = $raiz . '/grande';
    $prep = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
          . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
          . '$r = ' . var_export($base, true) . ';'
          . '@mkdir($r, 0777, true);'
          . 'JsonSQLDB\\Database::crear("g", $r);'
          . '$bd = new JsonSQLDB\\Database("g", $r);'
          . '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v VARCHAR(400))");'
          . '$p = array_fill(0, 900, str_repeat("x", 380));'
          . '$v = implode(",", array_fill(0, 900, "(?)"));'
          . 'for ($i = 0; $i < 12; $i++) { $bd->consultar("INSERT INTO t (v) VALUES $v", $p); }';
    shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M -r ' . escapeshellarg($prep) . ' 2>&1');

    // Un ORDER BY sin LIMIT y no un COUNT: desde 2.4.0 el COUNT(*) cuenta
    // líneas sin cargar nada, y desde 2.6.0 los agregados se acumulan sin
    // tener la tabla en memoria, así que ya no sirven de sonda para el corte.
    // Ordenar entero sí obliga a tener todas las filas a la vez.
    // La sonda imprime cuántas filas salen (o el valor de COUNT(*)), o el
    // estado del error si la consulta se corta
    $sonda = static fn(string $sql, string $limite): string => trim((string)shell_exec(
        escapeshellarg(PHP_BINARY) . " -d memory_limit=$limite -r " . escapeshellarg(
            'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$bd = new JsonSQLDB\\Database("g", ' . var_export($base, true) . ');'
            . 'try { $r = $bd->consultar(' . var_export($sql, true) . '); echo $r[0]["n"] ?? count($r); }'
            . 'catch (JsonSQLDB\\JsonSqlDbError $e) { echo $e->sqlState; }'
        ) . ' 2>&1'));

    // Con poca memoria: corte explicado. Con suficiente: la consulta sale.
    $poco  = $sonda('SELECT v FROM t ORDER BY v', '12M');
    $mucho = $sonda('SELECT v FROM t ORDER BY v', '256M');
    // Y el COUNT(*) de 2.4.0 tiene que salir justo donde la tabla no cabe:
    // cuenta líneas, no depende del memory_limit ni de lo que haya en caché.
    $atajo = $sonda('SELECT COUNT(*) AS n FROM t', '12M');

    exec('rm -rf ' . escapeshellarg($base));
    return ($poco === 'MEMORIA' && $mucho === '10800' && $atajo === '10800')
        ?: "12M -> " . substr($poco, 0, 50) . " | 256M -> " . substr($mucho, 0, 50)
         . " | COUNT(*) 12M -> " . substr($atajo, 0, 50);
});
chk('los datos siguen intactos tras el corte', function () use ($raiz) {
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$bd = new JsonSQLDB\\Database("m", ' . var_export($raiz . '/mem', true) . ');'
            . '$bd->consultar("INSERT INTO t (v) VALUES (?)", ["despues"]);'
            . 'echo $bd->consultar("SELECT COUNT(*) AS n FROM t")[0]["n"];';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    exec('rm -rf ' . escapeshellarg($raiz . '/mem'));
    return $salida === '1501' ?: $salida;
});

echo "\n== Conexión directa al motor ==\n";
chk('bloqueada por defecto', function () use ($raiz) {
    // En otro proceso, sin activar el parámetro
    $codigo = 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$r = ' . var_export($raiz . '/directa', true) . ';'
            . '@mkdir($r, 0777, true);'
            . 'try { JsonSQLDB\\Database::crear("d", $r);'
            . '      (new JsonSQLDB\\Database("d", $r))->consultar("CREATE TABLE t (a INTEGER)");'
            . '      echo "permitida"; }'
            . 'catch (Throwable $e) { echo $e->getMessage(); }';
    $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1');
    return str_contains($salida, 'conexión directa al motor está desactivada') ?: $salida;
});
chk('permitida con el parámetro a true, y con permisos de admin', function () use ($raiz) {
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
            . 'define("JSONSQLDB_LOG_ACTIVO", true);'
            . 'define("JSONSQLDB_LOG_PATH", ' . var_export($raiz . '/logs', true) . ');'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$r = ' . var_export($raiz . '/directa', true) . ';'
            . 'JsonSQLDB\\Database::crear("d2", $r);'
            . '$bd = new JsonSQLDB\\Database("d2", $r);'
            . '$bd->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, n VARCHAR(10))");'
            . '$bd->consultar("INSERT INTO t (n) VALUES (?)", ["uno"]);'
            . 'echo count($bd->consultar("SELECT * FROM t"));';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    $GLOBALS['logDirecta'] = $raiz . '/logs';
    return $salida === '1' ?: $salida;
});
chk('el log de una conexión directa pone ip = local', function () {
    foreach ((array)glob($GLOBALS['logDirecta'] . '/consultas-*.json') as $f) {
        foreach ((array)file((string)$f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $e = json_decode($linea, true);
            if (is_array($e) && ($e['ip'] ?? '') === 'local') {
                return true;
            }
        }
    }
    return 'ninguna entrada con ip=local: ' . implode(',', array_map('basename',
        (array)glob($GLOBALS['logDirecta'] . '/*')));
});
chk('limpiar la base de la conexión directa', function () use ($raiz) {
    exec('rm -rf ' . escapeshellarg($raiz . '/directa') . ' ' . escapeshellarg($raiz . '/logs'));
    return true;
});
chk('la caché en disco no confunde la parte 1 en la revisión 13 con la 11 en la 3: leer no reescribe la tabla (2.9)', function () use ($raiz) {
    $r = $raiz . '/cachep';
    $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_FILAS_POR_PARTE", 10);'
            . 'define("JSONSQLDB_CACHE_APCU", false); define("JSONSQLDB_CACHE_OPCACHE", false);'
            . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . '$r = ' . var_export($r, true) . '; JsonSQLDB\Database::crear("c", $r); $b = new JsonSQLDB\Database("c", $r);'
            . '$b->consultar("CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER)");'
            . '$v = []; for ($i = 1; $i <= 100; $i++) { $v[] = "($i, 0)"; } $b->consultar("INSERT INTO t VALUES " . implode(",", $v));'
            . '$v = []; for ($i = 101; $i <= 110; $i++) { $v[] = "($i, 0)"; } $b->consultar("INSERT INTO t VALUES " . implode(",", $v));'
            . 'for ($k = 0; $k < 10; $k++) { $b->consultar("UPDATE t SET n = n + 1 WHERE id = 1"); }'
            . '$rev = fn() => json_decode(file_get_contents("$r/c/t.rev.json"), true)["rev"]; $antes = $rev();'
            . '(new JsonSQLDB\Database("c", $r))->consultar("SELECT SUM(n) FROM t");'
            . '$s = (new JsonSQLDB\Database("c", $r))->consultar("SELECT SUM(n) AS s FROM t")[0]["s"];'
            . 'echo json_encode([$antes, $rev(), $s]);';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    exec('rm -rf ' . escapeshellarg($r));
    return $salida === '[13,13,10]' ?: $salida;
});
chk('cada petición carga solo las clases que usa, y sin OPcache no compila más código que en la 2.10, que es menos que en la 2.9.0 o, en un INSERT con su id, lo mismo (2.10); una consulta carga Evaluator antes de leer las filas (2.10)', function () use ($raiz) {
    // Sin OPcache, PHP compila en cada petición todo lo que carga. Cada
    // sentencia, en un proceso nuevo, con la caché de resultados apagada. Con
    // 300 filas en c, el JOIN de una fila de p busca su cliente por la clave
    // (con pocas, cruzaría con una tabla hash, que va aparte)
    $r = $raiz . '/carga';
    $motor = var_export(dirname(__DIR__) . '/engine/bootstrap.php', true);
    $ini = 'define("JSONSQLDB_CONEXION_DIRECTA", true); define("JSONSQLDB_CACHE_RESULTADOS", 0); require ' . $motor . ';'
         . '$b = new JsonSQLDB\\Database("c", ' . var_export($r, true) . ');';
    $correr = static fn(string $codigo): string => trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    $correr('define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . $motor . '; JsonSQLDB\\Database::crear("c", ' . var_export($r, true) . ');'
        . '$b = new JsonSQLDB\\Database("c", ' . var_export($r, true) . ');'
        . '$b->consultar("CREATE TABLE c (id INTEGER PRIMARY KEY, email VARCHAR(30) UNIQUE, n INTEGER)");'
        . '$b->consultar("CREATE TABLE p (id INTEGER PRIMARY KEY, cid INTEGER, total INTEGER)");'
        . '$v = []; for ($i = 1; $i <= 300; $i++) { $v[] = "($i, \'$i@x\', $i)"; } $b->consultar("INSERT INTO c VALUES " . implode(",", $v));'
        . '$b->consultar("INSERT INTO p VALUES (1, 1, 10), (2, 3, 20)");');
    $aparte = ['ParserEscritura', 'ParserSentencias', 'ParserAvanzado', 'CatalogDdl', 'StorageEscritura', 'StorageCambios',
               'StorageMantenimiento', 'StorageRangos', 'FunctionsAmpliadas', 'EvaluatorPatrones', 'SelectOrden', 'SelectAgrupada',
               'SelectCompuesta', 'SelectJoin', 'SelectAvanzada', 'DatabaseAvanzada', 'Writer', 'WriterCambios', 'WriterForaneas',
               'WriterConflictos', 'WriterTriggers', 'WriterDdl', 'StorageBarrido', 'StorageCompartida', 'StorageBusquedas',
               'StorageOrden', 'CatalogBloqueos', 'SelectJoinIndice', 'ParserFunciones', 'IndexesEscritura', 'StorageCompactacion',
               'SelectEnDisco', 'StorageOrdenTexto', 'EvaluatorIn', 'TypesCast', 'WriterCambiosEspeciales', 'StorageEntera', 'WriterAvanzado',
               'ParserEscrituraAvanzada', 'Logger', 'WriterInsercion', 'ParserCambios', 'StorageMayores', 'EvaluatorConjunto'];
    $escritura = ['ParserSentencias', 'ParserAvanzado', 'ParserConsulta', 'CatalogDdl', 'StorageMantenimiento', 'StorageRangos',
                  'Select', 'SelectAvanzada', 'DatabaseAvanzada', 'WriterForaneas', 'WriterConflictos', 'WriterTriggers', 'WriterDdl',
                  'StorageBarrido', 'StorageCompartida', 'StorageBusquedas', 'StorageOrden', 'CatalogBloqueos', 'SelectJoinIndice',
                  'ParserFunciones', 'StorageCompactacion', 'SelectEnDisco', 'StorageOrdenTexto', 'EvaluatorIn', 'TypesCast',
                  'WriterCambiosEspeciales', 'StorageEntera', 'WriterAvanzado', 'ParserEscrituraAvanzada', 'Logger', 'StorageMayores',
                  'EvaluatorConjunto'];
    // Lo que no puede cargar cada sentencia, y su tope de piezas de código
    // (sin espacios ni comentarios): lo que compila en la 2.10 en esta misma
    // base, con 40 de margen: las lecturas, entre 1.100 y 3.550 menos que la
    // 2.9.0 (un WHERE con IN, 2.100: solo carga lo que busca en el conjunto,
    // no el IN fila a fila); un UPDATE y un DELETE, 1.700 menos; un INSERT con su id, lo
    // mismo, y uno sin él, 2.450 menos (no lee filas para saber el id). Lo
    // que va aparte y vuelve a una clase que se carga siempre pasa el tope.
    // Sin config.php el registro está apagado, así que ninguna carga Logger
    // (con el de config.php, lo mira la prueba del registro)
    $casos = [
        'SELECT * FROM c WHERE id = 2'                                       => [$aparte, 58645],
        "SELECT id FROM c WHERE email = '2@x'"                               => [$aparte, 58645],
        'SELECT p.id, c.email FROM p JOIN c ON c.id = p.cid WHERE p.id = 2'  => [array_diff($aparte, ['SelectJoinIndice']), 62848],
        'SELECT COUNT(*) FROM c'                                             => [$aparte, 45592],
        'SELECT id FROM c WHERE id BETWEEN 1 AND 2'                          => [array_diff($aparte, ['StorageRangos']), 58239],
        'SELECT UPPER(email) FROM c WHERE id = 2'                            => [$aparte, 62062],
        'SELECT id FROM c WHERE n IN (1, 2)'                                 => [array_diff($aparte, ['EvaluatorConjunto']), 58983],
        'SELECT n, COUNT(*), MAX(id) FROM c GROUP BY n'                      => [array_merge(array_diff($aparte, ['SelectAgrupada']), ['Functions']), 62121],
        'SELECT id FROM c ORDER BY n DESC LIMIT 3'                           => [array_diff($aparte, ['SelectOrden']), 57859],
        'SELECT id FROM c ORDER BY id DESC LIMIT 3'                          => [array_diff($aparte, ['SelectOrden', 'StorageOrden']), 61066],
        'SELECT id FROM c LIMIT 2 OFFSET 150'                                => [$aparte, 56972],
        'SELECT id FROM c WHERE id < 3 UNION SELECT cid FROM p'              => [array_diff($aparte, ['SelectCompuesta', 'StorageRangos']), 61113],
        'WITH t AS (SELECT id FROM c) SELECT id FROM t WHERE id = 2'         => [array_diff($aparte, ['SelectCompuesta']), 59119],
        "INSERT INTO c VALUES (301, 'z@x', -4)"                              => [array_merge($escritura, ['StorageLectura', 'StorageCambios', 'WriterCambios', 'Evaluator', 'ParserCambios']), 52294],
        "INSERT INTO c (email, n) VALUES ('y@x', 5)"                         => [array_merge(array_diff($escritura, ['StorageMayores']), ['StorageLectura', 'StorageCambios', 'WriterCambios', 'Evaluator', 'ParserCambios']), 52699],
        'UPDATE c SET n = n + 1 WHERE id = 301'                              => [array_merge($escritura, ['WriterInsercion']), 67205],
        'DELETE FROM c WHERE id = 301'                                       => [array_merge($escritura, ['WriterInsercion']), 67205],
    ];
    $medir = 'echo json_encode(array_map(static function (string $f): array { $n = 0;'
        . ' foreach (token_get_all((string)file_get_contents($f)) as $t) { $n += (int)!(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)); }'
        . ' return [basename($f, ".php"), $n]; }, array_filter(get_included_files(), static fn(string $f): bool => str_contains($f, "/engine/"))));';
    $mal = [];
    foreach ($casos as $sql => [$no, $tope]) {
        $cargadas = json_decode($correr($ini . '$b->consultar(' . var_export($sql, true) . ');' . $medir), true);
        if (!is_array($cargadas)) { $mal[] = "$sql: no respondió"; continue; }
        if (($sobra = array_intersect($no, array_column($cargadas, 0))) !== []) { $mal[] = "$sql carga " . implode(', ', $sobra); }
        if (($n = array_sum(array_column($cargadas, 1))) > $tope) { $mal[] = "$sql compila $n piezas (tope, $tope)"; }
    }
    // Evaluator, antes que lo que lee las filas: sin OPcache, compilarlo con
    // ellas y con esas clases ya en memoria subía el pico de una consulta por
    // clave 0,09 MB sobre la 2.9.0 (2.10)
    $orden = array_column((array)json_decode($correr($ini . '$b->consultar("SELECT * FROM c WHERE id = 2");' . $medir), true), 0);
    $ev    = array_search('Evaluator', $orden, true);
    if ($ev === false || $ev > array_search('StorageLectura', $orden, true)) {
        $mal[] = 'Evaluator se carga después de leer las filas: ' . implode(', ', $orden);
    }
    // Y lo que va aparte se carga cuando hace falta
    $cargadas = json_decode($correr($ini . '$b->consultar("CREATE TABLE x (a INTEGER)");'
        . 'echo json_encode(array_map(fn($f) => basename($f, ".php"), get_included_files()));'), true);
    if (array_diff(['ParserSentencias', 'CatalogDdl', 'WriterDdl', 'StorageMantenimiento'], (array)$cargadas) !== []) {
        $mal[] = 'CREATE TABLE no cargó lo suyo: ' . json_encode($cargadas);
    }
    exec('rm -rf ' . escapeshellarg($r));
    return $mal === [] ?: implode(' | ', $mal);
});
chk('el JIT de OPcache se enciende si php.ini le da memoria y lo deja en "off" o 0; con off sin comillas, que php.ini lee como vacío y PHP toma por "disable", no se puede; JSONSQLDB_JIT = false lo deja como está, y sin ini_set() no pasa nada (2.10)', function () {
    $dir = sys_get_temp_dir() . '/jsonsqldb_test_jit_' . getmypid();
    $codigo = static fn(string $antes): string => $antes . 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
        . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
        . '@mkdir(' . var_export($dir, true) . '); if (!is_dir(' . var_export("$dir/b", true) . ')) { JsonSQLDB\Database::crear("b", ' . var_export($dir, true) . '); }'
        . 'new JsonSQLDB\Database("b", ' . var_export($dir, true) . ');'
        . 'echo function_exists("opcache_get_status") && is_array($s = opcache_get_status(false)) ? (int)!empty($s["jit"]["on"]) : "sin";';
    $correr = static function (string $php, string $jit = '"off"', array $mas = []) use ($codigo): string {
        $p = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit_buffer_size=32M', '-d', "opcache.jit=$jit", ...$mas, '-r', $php],
                       [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        $s = trim((string)stream_get_contents($tubos[1])) . trim((string)stream_get_contents($tubos[2]));
        proc_close($p);
        return $s;
    };
    $r = [$correr($codigo('')), $correr($codigo(''), '0'), $correr($codigo(''), 'off'), $correr($codigo('define("JSONSQLDB_JIT", false);')),
          $correr($codigo(''), '"off"', ['-d', 'disable_functions=ini_set'])];
    exec('rm -rf ' . escapeshellarg($dir));
    if ($r[0] === 'sin') {
        return null;                    // este PHP no tiene OPcache para la línea de comandos
    }
    return $r === ['1', '1', '0', '0', '0'] ?: json_encode($r);
});
chk('el motor llama con \\ a las funciones que PHP compila a una instrucción propia (strlen, is_int, count…): sin ella, en un namespace, cada una es una llamada de verdad (2.10)', function () {
    // Las de zend_try_compile_special_func() de PHP 8.0 a 8.5 (sprintf, desde 8.4)
    $especiales = array_flip(['strlen', 'is_null', 'is_bool', 'is_long', 'is_int', 'is_integer', 'is_float', 'is_double', 'is_string', 'is_array',
        'is_object', 'is_resource', 'is_scalar', 'boolval', 'intval', 'floatval', 'doubleval', 'strval', 'defined', 'chr', 'ord', 'call_user_func_array',
        'call_user_func', 'in_array', 'count', 'sizeof', 'get_class', 'get_called_class', 'gettype', 'func_num_args', 'func_get_args', 'array_slice',
        'array_key_exists', 'sprintf']);
    $mal = [];
    foreach ((array)glob(dirname(__DIR__) . '/engine/*.php') as $f) {
        $t = token_get_all((string)file_get_contents((string)$f));
        $previo = null;
        foreach ($t as $i => $tk) {
            if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($tk) && $tk[0] === T_STRING && isset($especiales[strtolower($tk[1])])
                && !in_array($previo, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
                for ($j = $i + 1; isset($t[$j]) && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE; $j++) {
                }
                if (($t[$j] ?? null) === '(') {
                    $mal[] = basename((string)$f) . ":{$tk[2]} {$tk[1]}";
                }
            }
            $previo = is_array($tk) ? $tk[0] : $tk;
        }
    }
    return $mal === [] ?: count($mal) . ' sin \\: ' . implode(', ', array_slice($mal, 0, 10));
});
chk('la carpeta temporal queda vacía y se elimina', function () use ($raiz) {
    clearstatcache();
    return @rmdir($raiz) && !is_dir($raiz)
        ?: 'quedan: ' . implode(',', array_diff((array)scandir($raiz), ['.', '..']));
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
