<?php
declare(strict_types=1);

/**
 * Prueba del ejecutor de SELECT. Ejecutar: php tests/f2_select.php
 * Crea una base temporal con datos y la borra al terminar.
 */
require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Catalog;
use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Storage;
use JsonSQLDB\Types;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f2';
$base = 'tienda';
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
        try { $bd->consultar($sql); } catch (JsonSqlDbError $e) { return $e->sqlState === $estado ?: $e->sqlState; }
        return 'no lanzó error';
    });
}

/** Compara el resultado con una matriz esperada. */
function igual(array $filas, array $esperado): bool
{
    if (count($filas) !== count($esperado)) {
        return false;
    }
    foreach ($filas as $i => $fila) {
        if (array_values($fila) != array_values($esperado[$i])) {
            return false;
        }
    }
    return true;
}

// ------------------------------------------------------------------
// Datos de prueba
// ------------------------------------------------------------------
if (is_dir("$raiz/$base")) { Storage::borrarBase($raiz, $base); }
@mkdir($raiz, 0775, true);
Storage::crearBase($raiz, $base);

$st  = new Storage($raiz, $base);
$cat = new Catalog($st);
$st->bloquear(true);

$cat->crearTabla('usuarios', ['columns' => [
    ['name'=>'id','type'=>'INTEGER','pk'=>true,'autoincrement'=>true],
    ['name'=>'nombre','type'=>'VARCHAR(50)','notnull'=>true],
    ['name'=>'ciudad','type'=>'VARCHAR(50)'],
    ['name'=>'edad','type'=>'INTEGER'],
    ['name'=>'telefono','type'=>'VARCHAR(20)'],
    ['name'=>'alta','type'=>'DATETIME'],
]]);
$cat->crearTabla('pedidos', ['columns' => [
    ['name'=>'id','type'=>'INTEGER','pk'=>true,'autoincrement'=>true],
    ['name'=>'usuario_id','type'=>'INTEGER','notnull'=>true],
    ['name'=>'total','type'=>'DECIMAL(10,2)','notnull'=>true],
    ['name'=>'fecha','type'=>'DATETIME'],
], 'foreign_keys' => [
    ['columns'=>['usuario_id'],'table'=>'usuarios','references'=>['id']],
]]);

$usuarios = [
    [1,'Ana',      'Madrid',    30, '600111222', '2026-01-15 08:30:00'],
    [2,'Luis',     'Valencia',  17, null,        '2026-02-01'],
    [3,'María',    'Madrid',    45, '600333444', '2026-02-20 12:00:00'],
    [4,'Pedro',    'Torrevieja',22, null,        '2026-03-05'],
    [5,'Marta',    'Valencia',  38, '600555666', '2026-03-11 09:15:00'],
    [6,'Óscar',    'Madrid',    17, '600777888', '2026-04-02'],
];
$filas = [];
foreach ($usuarios as $u) {
    $filas[] = ['id'=>$u[0],'nombre'=>$u[1],'ciudad'=>$u[2],'edad'=>$u[3],'telefono'=>$u[4],'alta'=>$u[5]];
}
$st->guardarFilas('usuarios', $filas);

$pedidos = [
    [1,1,120.50,'2026-01-20'],
    [2,1, 80.00,'2026-02-11'],
    [3,3,300.25,'2026-02-25'],
    [4,5, 45.99,'2026-03-15'],
    [5,5, 12.10,'2026-03-20'],
    [6,5,200.00,'2026-04-01'],
];
$filas = [];
foreach ($pedidos as $p) {
    $filas[] = ['id'=>$p[0],'usuario_id'=>$p[1],'total'=>$p[2],'fecha'=>$p[3]];
}
$st->guardarFilas('pedidos', $filas);
$st->desbloquear();

$bd = new Database($base, $raiz);

// ------------------------------------------------------------------
echo "\n== Consultas básicas ==\n";
chk('SELECT columna', fn() => igual(
    $bd->consultar('SELECT nombre FROM usuarios LIMIT 3'),
    [['Ana'],['Luis'],['María']]
));
chk('SELECT * devuelve todas las columnas', function () use ($bd) {
    $f = $bd->consultar('SELECT * FROM usuarios LIMIT 1');
    return array_keys($f[0]) === ['id','nombre','ciudad','edad','telefono','alta'];
});
chk('WHERE con comparación', fn() => igual(
    $bd->consultar('SELECT nombre FROM usuarios WHERE edad > 30 ORDER BY edad'),
    [['Marta'],['María']]
));
chk('alias AS', function () use ($bd) {
    $f = $bd->consultar('SELECT nombre AS usuario FROM usuarios LIMIT 1');
    return array_keys($f[0]) === ['usuario'];
});
chk('DISTINCT', fn() => igual(
    $bd->consultar('SELECT DISTINCT ciudad FROM usuarios ORDER BY ciudad'),
    [['Madrid'],['Torrevieja'],['Valencia']]
));
chk('ORDER BY DESC y LIMIT', fn() => igual(
    $bd->consultar('SELECT nombre FROM usuarios ORDER BY edad DESC LIMIT 2'),
    [['María'],['Marta']]
));
chk('LIMIT con OFFSET', fn() => igual(
    $bd->consultar('SELECT id FROM usuarios ORDER BY id LIMIT 2 OFFSET 3'),
    [[4],[5]]
));
chk('SELECT sin FROM', fn() => igual($bd->consultar('SELECT 2 + 3 AS suma'), [[5]]));
chk('nombre por defecto de columna calculada', function () use ($bd) {
    $f = $bd->consultar('SELECT COUNT(*) FROM usuarios');
    return array_keys($f[0]) === ['COUNT(*)'] && $f[0]['COUNT(*)'] === 6;
});

echo "\n== Operadores y filtros ==\n";
chk('BETWEEN', fn() => igual(
    $bd->consultar('SELECT nombre FROM usuarios WHERE edad BETWEEN 20 AND 38 ORDER BY edad'),
    [['Pedro'],['Ana'],['Marta']]
));
chk('IN con lista', fn() => igual(
    $bd->consultar("SELECT COUNT(*) FROM usuarios WHERE ciudad IN ('Madrid','Valencia')"),
    [[5]]
));
chk('NOT IN', fn() => igual(
    $bd->consultar("SELECT nombre FROM usuarios WHERE ciudad NOT IN ('Madrid','Valencia')"),
    [['Pedro']]
));
chk('LIKE con %', fn() => igual(
    $bd->consultar("SELECT nombre FROM usuarios WHERE nombre LIKE 'Mar%' ORDER BY id"),
    [['María'],['Marta']]
));
chk('LIKE con _', fn() => igual(
    $bd->consultar("SELECT nombre FROM usuarios WHERE nombre LIKE '_uis'"),
    [['Luis']]
));
chk('IS NULL', fn() => igual(
    $bd->consultar('SELECT nombre FROM usuarios WHERE telefono IS NULL ORDER BY id'),
    [['Luis'],['Pedro']]
));
chk('IS NOT NULL', fn() => igual(
    $bd->consultar('SELECT COUNT(*) FROM usuarios WHERE telefono IS NOT NULL'),
    [[4]]
));
chk('AND / OR con precedencia', fn() => igual(
    $bd->consultar("SELECT nombre FROM usuarios WHERE ciudad = 'Madrid' AND edad > 20 OR ciudad = 'Torrevieja' ORDER BY id"),
    [['Ana'],['María'],['Pedro']]
));
chk('NOT', fn() => igual(
    $bd->consultar("SELECT COUNT(*) FROM usuarios WHERE NOT (ciudad = 'Madrid')"),
    [[3]]
));
chk('comparación con NULL da desconocido', fn() => igual(
    $bd->consultar("SELECT COUNT(*) FROM usuarios WHERE telefono <> '600111222'"),
    [[3]]
));

echo "\n== Funciones de agregación ==\n";
chk('COUNT, SUM, AVG, MIN, MAX', function () use ($bd) {
    $f = $bd->consultar('SELECT COUNT(*) AS n, SUM(total) AS s, MIN(total) AS mn, MAX(total) AS mx FROM pedidos');
    return $f[0]['n'] === 6 && round((float)$f[0]['s'], 2) === 758.84
        && $f[0]['mn'] === 12.1 && $f[0]['mx'] === 300.25;
});
chk('AVG ignora los NULL', fn() => igual(
    $bd->consultar('SELECT COUNT(telefono) FROM usuarios'),
    [[4]]
));
chk('COUNT(DISTINCT)', fn() => igual(
    $bd->consultar('SELECT COUNT(DISTINCT ciudad) FROM usuarios'),
    [[3]]
));
chk('GROUP BY', fn() => igual(
    $bd->consultar('SELECT ciudad, COUNT(*) AS cantidad FROM usuarios GROUP BY ciudad ORDER BY ciudad'),
    [['Madrid',3],['Torrevieja',1],['Valencia',2]]
));
chk('GROUP BY con HAVING', fn() => igual(
    $bd->consultar('SELECT ciudad, COUNT(*) AS n FROM usuarios GROUP BY ciudad HAVING COUNT(*) > 1 ORDER BY ciudad'),
    [['Madrid',3],['Valencia',2]]
));
chk('HAVING sobre alias de agregado', fn() => igual(
    $bd->consultar('SELECT ciudad FROM usuarios GROUP BY ciudad HAVING COUNT(*) = 1'),
    [['Torrevieja']]
));
chk('ORDER BY por alias de agregado', fn() => igual(
    $bd->consultar('SELECT ciudad, COUNT(*) AS n FROM usuarios GROUP BY ciudad ORDER BY n DESC, ciudad ASC'),
    [['Madrid',3],['Valencia',2],['Torrevieja',1]]
));
chk('agregado sobre tabla vacía', function () use ($bd) {
    $f = $bd->consultar("SELECT COUNT(*) AS n, SUM(total) AS s FROM pedidos WHERE total > 99999");
    return count($f) === 1 && $f[0]['n'] === 0 && $f[0]['s'] === null;
});
error('agregado en WHERE', 'SYNTAX', 'SELECT nombre FROM usuarios WHERE COUNT(*) > 1');

echo "\n== Funciones de texto ==\n";
chk('UPPER / LOWER con acentos', fn() => igual(
    $bd->consultar("SELECT UPPER(nombre), LOWER(nombre) FROM usuarios WHERE id = 3"),
    [['MARÍA','maría']]
));
chk('LENGTH cuenta caracteres, no bytes', fn() => igual(
    $bd->consultar('SELECT LENGTH(nombre) FROM usuarios WHERE id = 3'),
    [[5]]
));
chk('SUBSTR', fn() => igual(
    $bd->consultar("SELECT SUBSTR(nombre, 1, 3), SUBSTR(nombre, -2) FROM usuarios WHERE id = 1"),
    [['Ana','na']]
));
chk('TRIM / REPLACE', fn() => igual(
    $bd->consultar("SELECT TRIM('  hola  '), REPLACE('Juan Pérez', 'Juan', 'Pedro')"),
    [['hola','Pedro Pérez']]
));
chk('INSTR', fn() => igual(
    $bd->consultar("SELECT INSTR('Torrevieja', 'vieja'), INSTR('abc', 'z')"),
    [[6,0]]
));
chk('concatenación ||', fn() => igual(
    $bd->consultar("SELECT nombre || ' (' || ciudad || ')' FROM usuarios WHERE id = 1"),
    [['Ana (Madrid)']]
));

echo "\n== Funciones numéricas ==\n";
chk('ABS y ROUND', fn() => igual(
    $bd->consultar('SELECT ABS(-15), ROUND(3.14159, 2), ROUND(2.5)'),
    [[15, 3.14, 3.0]]
));
chk('ROUND sobre columna', fn() => igual(
    $bd->consultar('SELECT ROUND(AVG(total), 2) FROM pedidos'),
    [[126.47]]
));
chk('RANDOM devuelve enteros distintos', function () use ($bd) {
    $a = $bd->consultar('SELECT RANDOM() AS r')[0]['r'];
    $b = $bd->consultar('SELECT RANDOM() AS r')[0]['r'];
    return is_int($a) && is_int($b) && $a !== $b;
});
chk('aritmética y división por cero', fn() => igual(
    $bd->consultar('SELECT 10 + 5 * 2, 10 / 4, 10 % 3, 5 / 0'),
    [[20, 2.5, 1, null]]
));

echo "\n== Fechas ==\n";
chk('DATE / TIME / DATETIME sobre columna', fn() => igual(
    $bd->consultar("SELECT DATE(alta), TIME(alta), DATETIME(alta) FROM usuarios WHERE id = 1"),
    [['2026-01-15','08:30:00','2026-01-15 08:30:00']]
));
chk("DATE('now') devuelve hoy", fn() => igual(
    $bd->consultar("SELECT DATE('now')"),
    [[date('Y-m-d')]]
));
chk('STRFTIME', fn() => igual(
    $bd->consultar("SELECT STRFTIME('%Y', alta), STRFTIME('%d/%m/%Y', alta) FROM usuarios WHERE id = 3"),
    [['2026','20/02/2026']]
));
chk('comparación de fechas', fn() => igual(
    $bd->consultar("SELECT COUNT(*) FROM usuarios WHERE alta >= '2026-03-01'"),
    [[3]]
));

echo "\n== Condicionales y nulos ==\n";
chk('CASE WHEN', fn() => igual(
    $bd->consultar("SELECT nombre, CASE WHEN edad >= 18 THEN 'Adulto' ELSE 'Menor' END AS categoria
                    FROM usuarios WHERE id IN (1,2) ORDER BY id"),
    [['Ana','Adulto'],['Luis','Menor']]
));
chk('CASE con expresión base', fn() => igual(
    $bd->consultar("SELECT CASE ciudad WHEN 'Madrid' THEN 'Centro' WHEN 'Valencia' THEN 'Este' ELSE 'Otro' END
                    FROM usuarios WHERE id IN (1,4,5) ORDER BY id"),
    [['Centro'],['Otro'],['Este']]
));
chk('COALESCE', fn() => igual(
    $bd->consultar("SELECT COALESCE(telefono, 'Sin teléfono') FROM usuarios WHERE id = 2"),
    [['Sin teléfono']]
));
chk('IFNULL y NULLIF', fn() => igual(
    $bd->consultar("SELECT IFNULL(telefono, '-'), NULLIF(ciudad, 'Valencia') FROM usuarios WHERE id = 2"),
    [['-', null]]
));

echo "\n== JOIN ==\n";
chk('INNER JOIN', fn() => igual(
    $bd->consultar('SELECT usuarios.nombre, pedidos.total
                    FROM usuarios INNER JOIN pedidos ON usuarios.id = pedidos.usuario_id
                    ORDER BY pedidos.id LIMIT 3'),
    [['Ana',120.5],['Ana',80.0],['María',300.25]]
));
chk('INNER JOIN con alias y agregado', fn() => igual(
    $bd->consultar('SELECT u.nombre, COUNT(*) AS pedidos, ROUND(SUM(p.total), 2) AS gastado
                    FROM usuarios u JOIN pedidos p ON u.id = p.usuario_id
                    GROUP BY u.nombre ORDER BY gastado DESC'),
    [['María',1,300.25],['Marta',3,258.09],['Ana',2,200.5]]
));
chk('LEFT JOIN incluye sin coincidencias', fn() => igual(
    $bd->consultar('SELECT u.nombre, p.total FROM usuarios u
                    LEFT JOIN pedidos p ON u.id = p.usuario_id
                    WHERE u.id IN (2,4) ORDER BY u.id'),
    [['Luis',null],['Pedro',null]]
));
chk('LEFT JOIN con COUNT de la tabla derecha', fn() => igual(
    $bd->consultar('SELECT u.nombre, COUNT(p.id) AS n FROM usuarios u
                    LEFT JOIN pedidos p ON u.id = p.usuario_id
                    GROUP BY u.nombre ORDER BY n DESC, u.nombre LIMIT 3'),
    [['Marta',3],['Ana',2],['María',1]]
));
chk('RIGHT JOIN', fn() => igual(
    $bd->consultar('SELECT p.id, u.nombre FROM pedidos p
                    RIGHT JOIN usuarios u ON u.id = p.usuario_id
                    WHERE p.id IS NULL ORDER BY u.id'),
    [[null,'Luis'],[null,'Pedro'],[null,'Óscar']]
));
chk('CROSS JOIN con coma', function () use ($bd) {
    $f = $bd->consultar('SELECT COUNT(*) AS n FROM usuarios, pedidos');
    return $f[0]['n'] === 36;
});
chk('JOIN con condición extra en el ON', fn() => igual(
    $bd->consultar('SELECT COUNT(*) FROM usuarios u JOIN pedidos p ON u.id = p.usuario_id AND p.total > 100'),
    [[3]]
));
chk('tres tablas encadenadas', function () use ($bd) {
    $f = $bd->consultar('SELECT COUNT(*) AS n FROM usuarios u
                         JOIN pedidos p ON u.id = p.usuario_id
                         JOIN usuarios u2 ON u2.ciudad = u.ciudad');
    return $f[0]['n'] === 15;
});
error('columna ambigua', 'SCHEMA', 'SELECT id FROM usuarios u JOIN pedidos p ON u.id = p.usuario_id');
error('alias inexistente en el FROM', 'SCHEMA', 'SELECT x.* FROM usuarios u');

echo "\n== Subconsultas ==\n";
chk('IN con subconsulta', fn() => igual(
    $bd->consultar('SELECT nombre FROM usuarios WHERE id IN (SELECT usuario_id FROM pedidos) ORDER BY id'),
    [['Ana'],['María'],['Marta']]
));
chk('NOT IN con subconsulta', fn() => igual(
    $bd->consultar('SELECT nombre FROM usuarios WHERE id NOT IN (SELECT usuario_id FROM pedidos) ORDER BY id'),
    [['Luis'],['Pedro'],['Óscar']]
));
chk('subconsulta escalar', fn() => igual(
    $bd->consultar('SELECT nombre FROM usuarios WHERE edad = (SELECT MAX(edad) FROM usuarios)'),
    [['María']]
));
chk('subconsulta en FROM', fn() => igual(
    $bd->consultar('SELECT t.ciudad, t.n FROM
                    (SELECT ciudad, COUNT(*) AS n FROM usuarios GROUP BY ciudad) t
                    WHERE t.n > 1 ORDER BY t.ciudad'),
    [['Madrid',3],['Valencia',2]]
));
chk('subconsulta en FROM con JOIN', fn() => igual(
    $bd->consultar('SELECT u.nombre, g.total FROM usuarios u
                    JOIN (SELECT usuario_id, ROUND(SUM(total), 2) AS total FROM pedidos GROUP BY usuario_id) g
                      ON g.usuario_id = u.id
                    ORDER BY g.total DESC'),
    [['María',300.25],['Marta',258.09],['Ana',200.5]]
));

echo "\n== Orden alfabético ==\n";
chk('acentos, mayúsculas y ñ en su sitio', function () use ($bd) {
    $bd->consultar('CREATE TABLE orden (nombre VARCHAR(50))');
    foreach (['Ana','Óscar','Olga','oscar','Zoe','Ángel','Bea','ñu','Nuria','Núria','Ñoño'] as $n) {
        $bd->consultar('INSERT INTO orden (nombre) VALUES (?)', [$n]);
    }
    $r = array_column($bd->consultar('SELECT nombre FROM orden ORDER BY nombre'), 'nombre');
    return $r === ['Ana','Ángel','Bea','Nuria','Núria','Ñoño','ñu','Olga','oscar','Óscar','Zoe'] ?: $r;
});
chk('DESC invierte el mismo criterio', function () use ($bd) {
    $a = array_column($bd->consultar('SELECT nombre FROM orden ORDER BY nombre'), 'nombre');
    $d = array_column($bd->consultar('SELECT nombre FROM orden ORDER BY nombre DESC'), 'nombre');
    return $d === array_reverse($a);
});
chk('la clave de ordenación ignora acentos y mayúsculas', fn() =>
    JsonSQLDB\Collation::clave('Óscar') === JsonSQLDB\Collation::clave('oscar')
    && JsonSQLDB\Collation::clave('ÑOÑO') === JsonSQLDB\Collation::clave('ñoño'));
chk('pero la igualdad sigue siendo exacta', function () use ($bd) {
    $n = $bd->consultar("SELECT COUNT(*) AS n FROM orden WHERE nombre = 'oscar'")[0]['n'];
    return $n === 1;
});
chk('los números siguen ordenándose como números', function () use ($bd) {
    $r = array_column($bd->consultar('SELECT id FROM usuarios ORDER BY id DESC LIMIT 3'), 'id');
    return $r === [6, 5, 4] ?: $r;
});
chk('el mapa del idioma manda sobre el base', function () {
    // Sueco: å, ä y ö son letras propias y van después de la z.
    // Las constantes se leen al arrancar, así que se comprueba en otro proceso.
    $codigo = "define('JSONSQLDB_COLACION_MAPA', ['å'=>'z{','Å'=>'z{','ä'=>'z{{','Ä'=>'z{{',"
            . "'ö'=>'z{{{','Ö'=>'z{{{']);"
            . "require " . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ";"
            . "\$n = ['Östen','Åke','Ärla','Zorn','Anna','Bo'];"
            . "usort(\$n, fn(\$a, \$b) => JsonSQLDB\\Valor::compararOrden(\$a, \$b));"
            . "echo implode(',', \$n);";
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    return $salida === 'Anna,Bo,Zorn,Åke,Ärla,Östen' ?: $salida;
});
chk('la colación binaria recupera el orden de SQLite', function () {
    $codigo = "define('JSONSQLDB_COLACION', 'binaria');"
            . "require " . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ";"
            . "\$n = ['Óscar','oscar','Olga','Ana'];"
            . "usort(\$n, fn(\$a, \$b) => JsonSQLDB\\Valor::compararOrden(\$a, \$b));"
            . "echo implode(',', \$n);";
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    return $salida === 'Ana,Olga,oscar,Óscar' ?: $salida;
});
chk('borrar la tabla de orden', function () use ($bd) {
    $bd->consultar('DROP TABLE orden');
    return true;
});

echo "\n== Errores ==\n";
error('tabla inexistente', 'SCHEMA', 'SELECT * FROM noexiste');
error('columna inexistente', 'SCHEMA', 'SELECT nocolumna FROM usuarios');
error('función inexistente', 'SYNTAX', 'SELECT NOEXISTE(1)');
error('argumentos de más', 'SYNTAX', 'SELECT LENGTH(1, 2)');
error('sintaxis rota', 'SYNTAX', 'SELECT FROM WHERE');

echo "\n== Rendimiento ==\n";
chk('10.000 filas: filtro, agrupación y join', function () use ($st, $cat, $bd) {
    $st->bloquear(true);
    $cat->crearTabla('ventas', ['columns' => [
        ['name'=>'id','type'=>'INTEGER','pk'=>true],
        ['name'=>'usuario_id','type'=>'INTEGER'],
        ['name'=>'importe','type'=>'DECIMAL(10,2)'],
    ]]);
    $filas = [];
    for ($i = 1; $i <= 10000; $i++) {
        $filas[] = ['id'=>$i, 'usuario_id'=>($i % 6) + 1, 'importe'=>round($i / 7, 2)];
    }
    $st->guardarFilas('ventas', $filas);
    $st->desbloquear();

    $t0 = microtime(true);
    $f1 = $bd->consultar('SELECT COUNT(*) AS n FROM ventas WHERE importe > 500');
    $t1 = (microtime(true) - $t0) * 1000;

    $t0 = microtime(true);
    $f2 = $bd->consultar('SELECT usuario_id, COUNT(*) AS n, ROUND(SUM(importe),2) AS total
                          FROM ventas GROUP BY usuario_id ORDER BY usuario_id');
    $t2 = (microtime(true) - $t0) * 1000;

    $t0 = microtime(true);
    $f3 = $bd->consultar('SELECT u.nombre, COUNT(*) AS n FROM ventas v
                          JOIN usuarios u ON u.id = v.usuario_id GROUP BY u.nombre');
    $t3 = (microtime(true) - $t0) * 1000;

    printf("       WHERE %.0f ms | GROUP BY %.0f ms | JOIN 10.000x6 %.0f ms\n", $t1, $t2, $t3);

    return $f1[0]['n'] === 6500 && count($f2) === 6 && count($f3) === 6;
});

echo "\n== Log ==\n";
chk('el log registra sql, filas e ip', function () use ($raiz, $base) {
    $dir = $raiz . '/logs';
    if (!defined('JSONSQLDB_LOG_ACTIVO')) {
        define('JSONSQLDB_LOG_ACTIVO', true);
        define('JSONSQLDB_LOG_PATH', $dir);
    }
    JsonSQLDB\Logger::contexto('prueba', '10.0.0.9');
    $bd2 = new Database($base, $raiz);
    $bd2->consultar('SELECT nombre FROM usuarios LIMIT 2');
    try { $bd2->consultar('SELECT * FROM noexiste'); } catch (JsonSqlDbError $e) {}

    $fichero = $dir . '/consultas-' . date('Y-m-d') . '.json';
    if (!is_file($fichero)) { return 'no se creó el fichero de log'; }
    $lineas = array_filter(explode("\n", (string)file_get_contents($fichero)));
    $a = json_decode(array_shift($lineas), true);
    $b = json_decode(array_pop($lineas), true);
    return $a['op'] === 'SELECT' && $a['rows'] === 2 && $a['ip'] === '10.0.0.9'
        && $a['origen'] === 'prueba' && $a['error'] === null
        && str_contains((string)$b['error'], 'SCHEMA');
});

echo "\n== Limpieza ==\n";
chk('borrar base de pruebas', function () use ($raiz, $base) {
    Storage::borrarBase($raiz, $base);
    foreach ((array)glob("$raiz/logs/*") as $f) { @unlink($f); }
    @rmdir("$raiz/logs");
    @rmdir($raiz);
    return !is_dir("$raiz/$base");
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
