<?php
declare(strict_types=1);

/**
 * Prueba de la fase 1 (núcleo). Ejecutar:  php tests/f1_nucleo.php
 * No deja nada en disco: borra la base de pruebas al terminar.
 */
require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Catalog;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Storage;
use JsonSQLDB\Types;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test';
$base = 'pruebas';
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
esperaError('escribir sin bloqueo exclusivo', 'LOCK', fn() => $cat->crearTabla('x', ['columns'=>[['name'=>'a','type'=>'INT']]]));

$st->bloquear(true);

chk('crear usuarios', function () use ($cat) {
    $cat->crearTabla('usuarios', [
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
    $cat->crearTabla('pedidos', [
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

esperaError('FK a tabla inexistente', 'SCHEMA', fn() => $cat->crearTabla('t1', [
    'columns'=>[['name'=>'a','type'=>'INT']],
    'foreign_keys'=>[['columns'=>['a'],'table'=>'nohay','references'=>['id']]],
]));
esperaError('AUTOINCREMENT no entero', 'SCHEMA', fn() => $cat->crearTabla('t2', [
    'columns'=>[['name'=>'a','type'=>'TEXT','autoincrement'=>true]],
]));
esperaError('columna duplicada', 'SCHEMA', fn() => $cat->crearTabla('t3', [
    'columns'=>[['name'=>'a','type'=>'INT'],['name'=>'A','type'=>'INT']],
]));
esperaError('borrar tabla referenciada', 'CONSTRAINT', fn() => $cat->borrarTabla('usuarios'));

chk('clave primaria', fn() => Catalog::clavePrimaria($cat->meta('usuarios')) === ['id']);
chk('conjuntos únicos', fn() => count(Catalog::conjuntosUnicos($cat->meta('usuarios'))) === 2);
chk('columna autoincremento', fn() => Catalog::columnaAutoincremento($cat->meta('usuarios')) === 'id');

echo "\n== Datos ==\n";
chk('insertar y releer', function () use ($st, $cat) {
    $meta  = $cat->meta('usuarios');
    $filas = [];
    foreach ([['Ana','ana@x.es',10.555,'2026-01-15 08:30'], ['Luis','luis@x.es',0,null]] as $d) {
        $fila = ['id' => $cat->siguienteAutoincremento('usuarios')];
        foreach (['nombre','email','saldo','alta'] as $i => $c) {
            $fila[$c] = Types::cast($d[$i], Catalog::columna($meta, $c));
        }
        $filas[] = $fila;
    }
    $st->guardarFilas('usuarios', $filas);
    $leidas = $st->leerFilas('usuarios');
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

chk('autoincremento persiste', fn() => $cat->siguienteAutoincremento('usuarios') === 3);
chk('ajustar autoincremento', function () use ($cat) {
    $cat->ajustarAutoincremento('usuarios', 50);
    return $cat->siguienteAutoincremento('usuarios') === 51;
});

echo "\n== ALTER TABLE ==\n";
chk('añadir columna', function () use ($cat, $st) {
    $cat->anadirColumna('usuarios', ['name'=>'ciudad','type'=>'VARCHAR(60)','default'=>'Torrevieja']);
    $filas = $st->leerFilas('usuarios');
    return $filas[0]['ciudad'] === 'Torrevieja' && Catalog::columna($cat->meta('usuarios'), 'ciudad') !== null;
});
chk('renombrar columna', function () use ($cat, $st) {
    $cat->renombrarColumna('usuarios', 'ciudad', 'poblacion');
    return array_key_exists('poblacion', $st->leerFilas('usuarios')[0]);
});
chk('borrar columna', function () use ($cat, $st) {
    $cat->borrarColumna('usuarios', 'poblacion');
    return !array_key_exists('poblacion', $st->leerFilas('usuarios')[0])
        && Catalog::columna($cat->meta('usuarios'), 'poblacion') === null;
});
esperaError('borrar columna referenciada por FK', 'CONSTRAINT', fn() => $cat->borrarColumna('usuarios', 'id'));
esperaError('borrar columna de una FK propia', 'CONSTRAINT', fn() => $cat->borrarColumna('pedidos', 'usuario_id'));

echo "\n== Triggers ==\n";
chk('crear trigger', function () use ($cat) {
    $cat->crearTrigger('pedidos', [
        'name'=>'trg_pedidos_ins','timing'=>'AFTER','event'=>'INSERT',
        'when'=>'NEW.total > 0',
        'body'=>['UPDATE usuarios SET saldo = saldo + NEW.total WHERE id = NEW.usuario_id'],
        'sql'=>'CREATE TRIGGER trg_pedidos_ins ...',
    ]);
    return count($cat->triggers('pedidos','AFTER','INSERT')) === 1
        && $cat->triggers('pedidos','BEFORE','INSERT') === [];
});
esperaError('trigger duplicado', 'SCHEMA', fn() => $cat->crearTrigger('pedidos', [
    'name'=>'trg_pedidos_ins','timing'=>'AFTER','event'=>'INSERT','body'=>['DELETE FROM usuarios'],
]));
esperaError('evento no soportado', 'SCHEMA', fn() => $cat->crearTrigger('pedidos', [
    'name'=>'trg_x','timing'=>'AFTER','event'=>'TRUNCATE','body'=>['DELETE FROM usuarios'],
]));
chk('borrar trigger', fn() => $cat->borrarTrigger('trg_pedidos_ins') === 'pedidos'
    && $cat->triggers('pedidos','AFTER','INSERT') === []);

echo "\n== Renombrar tabla ==\n";
chk('renombrar propaga FK', function () use ($cat) {
    $cat->renombrarTabla('usuarios', 'clientes');
    return $cat->existe('clientes') && !$cat->existe('usuarios')
        && $cat->meta('pedidos')['foreign_keys'][0]['table'] === 'clientes'
        && $cat->meta('clientes')['autoincrement']['next'] === 52;
});

echo "\n== Paginación y rendimiento ==\n";
chk('una tabla grande se reparte en las partes que tocan', function () use ($cat, $st, $raiz, $base) {
    $cat->crearTabla('log', ['columns'=>[
        ['name'=>'id','type'=>'INTEGER','pk'=>true],
        ['name'=>'texto','type'=>'TEXT'],
        ['name'=>'fecha','type'=>'DATETIME'],
    ]]);
    $filas = [];
    for ($i = 1; $i <= 12000; $i++) {
        $filas[] = ['id'=>$i, 'texto'=>"línea $i", 'fecha'=>'2026-08-19 12:00:00'];
    }
    $t0 = microtime(true);
    $st->guardarFilas('log', $filas);
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
    $leidas = $st->leerFilas('log');   // desde caché
    $tc = microtime(true) - $t0;

    printf("       %d partes | escritura %.3fs | lectura cacheada %.4fs | parte %.1f KB\n",
        $esperadas, $tw, $tc, filesize("$raiz/$base/log.json") / 1024);

    return $partes && count($leidas) === 12000 && $leidas[11999]['id'] === 12000;
});

chk('reducir filas elimina partes sobrantes', function () use ($st, $raiz, $base) {
    $st->guardarFilas('log', array_slice($st->leerFilas('log'), 0, 10));
    return !is_file("$raiz/$base/log.part2.json") && count($st->leerFilas('log')) === 10;
});

echo "\n== Caché e invalidación ==\n";
chk('la caché devuelve datos actualizados tras escribir', function () use ($st) {
    $antes = count($st->leerFilas('log'));
    $filas = $st->leerFilas('log');
    $filas[] = ['id'=>999,'texto'=>'nueva','fecha'=>null];
    $st->guardarFilas('log', $filas);
    return count($st->leerFilas('log')) === $antes + 1;
});

echo "\n== Bloqueo ==\n";
chk('anidado exclusivo', function () use ($st) {
    $st->bloquear(true); $st->desbloquear();
    return $st->enEscritura();
});
$st->desbloquear();
chk('liberado del todo', fn() => $st->enEscritura() === false);
chk('otra petición ve la escritura (caché invalidada por revisión)', function () use ($raiz, $base) {
    $st2 = new Storage($raiz, $base);   // instancia nueva = simula otra petición
    $st2->bloquear(false);
    $n = count($st2->leerFilas('log'));
    $st2->desbloquear();
    return $n === 11;
});
chk('lectura simultánea', function () use ($st, $raiz, $base) {
    $st->bloquear(false);
    $st2 = new Storage($raiz, $base);
    $st2->bloquear(false);      // no debe bloquearse
    $n = count($st2->leerFilas('log'));
    $st2->desbloquear();
    $st->desbloquear();
    return $n === 11;
});
esperaError('no se puede escribir dentro de una lectura', 'LOCK', function () use ($st) {
    $st->bloquear(false);
    try { $st->bloquear(true); } finally { $st->desbloquear(); }
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
@rmdir($raiz);

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

    // COUNT(v) y no COUNT(*): desde 2.4.0 el COUNT(*) cuenta líneas sin cargar
    // nada, así que ya no sirve de sonda para el corte. COUNT(v) sigue el
    // camino normal y materializa la tabla, que es lo que se quiere cortar.
    $sonda = static fn(string $sql): string => 'define("JSONSQLDB_CONEXION_DIRECTA", true);'
          . 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
          . '$bd = new JsonSQLDB\\Database("g", ' . var_export($base, true) . ');'
          . 'try { echo $bd->consultar(' . var_export($sql, true) . ')[0]["n"]; }'
          . 'catch (JsonSQLDB\\JsonSqlDbError $e) { echo $e->sqlState; }';

    // Con poca memoria: corte explicado. Con suficiente: la consulta sale.
    $poco  = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=12M -r '
           . escapeshellarg($sonda('SELECT COUNT(v) AS n FROM t')) . ' 2>&1'));
    $mucho = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=256M -r '
           . escapeshellarg($sonda('SELECT COUNT(v) AS n FROM t')) . ' 2>&1'));
    // Y el COUNT(*) de 2.4.0 tiene que salir justo donde la tabla no cabe:
    // cuenta líneas, no depende del memory_limit ni de lo que haya en caché.
    $atajo = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=12M -r '
           . escapeshellarg($sonda('SELECT COUNT(*) AS n FROM t')) . ' 2>&1'));

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

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
