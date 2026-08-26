<?php
declare(strict_types=1);

/**
 * Prueba del ejecutor de SELECT. Ejecutar: php tests/f2_select.php
 * Crea una base temporal con datos y la borra al terminar.
 */
// Las pruebas usan el motor directamente, sin pasar por la API
define('JSONSQLDB_CONEXION_DIRECTA', true);

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

echo "\n== Casos límite de operadores y funciones ==\n";
chk('el módulo con un divisor que se vuelve cero al truncar da NULL', function () use ($bd) {
    // 0.4 no es cero, pero (int)0.4 sí: antes reventaba con DivisionByZeroError
    $r = $bd->consultar('SELECT 5 % 0 AS a, 5 % 0.4 AS b, -1 % -0.5 AS c')[0];
    return $r === ['a' => null, 'b' => null, 'c' => null] ?: json_encode($r);
});
chk('el módulo normal sigue funcionando', function () use ($bd) {
    $r = $bd->consultar('SELECT 7 % 3 AS a, -7 % 3 AS b, 7 % -3 AS c')[0];
    return $r === ['a' => 1, 'b' => -1, 'c' => 1] ?: json_encode($r);
});
chk('SUBSTR con índice 0 se comporta como en SQLite', function () use ($bd) {
    // La posición 0 es el hueco anterior al primer carácter, así que se pierde
    // uno de los caracteres pedidos
    $r = $bd->consultar("SELECT SUBSTR('abcdef', 0, 3) AS a, SUBSTR('abc', 0, 1) AS b,
                                SUBSTR('abcdef', 0, 1) AS c")[0];
    return $r === ['a' => 'ab', 'b' => '', 'c' => ''] ?: json_encode($r);
});
chk('SUBSTR con índices positivos y negativos', function () use ($bd) {
    $r = $bd->consultar("SELECT SUBSTR('abcdef', 1, 3) AS a, SUBSTR('abcdef', -2) AS b,
                                SUBSTR('abcdef', 2, -2) AS c, SUBSTR('abcdef', 100) AS d")[0];
    return $r === ['a' => 'abc', 'b' => 'ef', 'c' => 'a', 'd' => ''] ?: json_encode($r);
});
chk('SUBSTR respeta los caracteres UTF-8', function () use ($bd) {
    $r = $bd->consultar("SELECT SUBSTR('áéíóú', 2, 2) AS a")[0]['a'];
    return $r === 'éí' ?: $r;
});
chk('ROUND redondea alejándose del cero, como SQLite', function () use ($bd) {
    $r = $bd->consultar('SELECT ROUND(2.5) AS a, ROUND(-2.5) AS b, ROUND(2.4) AS c')[0];
    return $r === ['a' => 3.0, 'b' => -3.0, 'c' => 2.0] ?: json_encode($r);
});

echo "\n== Fechas: NULL y fechas imposibles ==\n";
chk('DATE/TIME/DATETIME sobre NULL dan NULL, no la fecha de hoy', function () use ($bd) {
    $r = $bd->consultar('SELECT DATE(NULL) AS a, TIME(NULL) AS b, DATETIME(NULL) AS c')[0];
    return $r === ['a' => null, 'b' => null, 'c' => null] ?: json_encode($r);
});
chk('sin argumentos siguen dando la fecha de ahora', function () use ($bd) {
    $r = $bd->consultar('SELECT DATE() AS a')[0]['a'];
    return is_string($r) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r) === 1 ?: $r;
});
chk('una fecha imposible no se "arregla" sola', function () use ($bd) {
    $r = $bd->consultar("SELECT DATE('2026-02-30') AS a, DATE('2026-13-40') AS b,
                                DATE('0000-00-00') AS c")[0];
    return $r === ['a' => null, 'b' => null, 'c' => null] ?: json_encode($r);
});
chk('el 29 de febrero de un bisiesto sí vale', function () use ($bd) {
    return $bd->consultar("SELECT DATE('2028-02-29') AS a")[0]['a'] === '2028-02-29';
});
chk('una columna DATETIME rechaza una fecha imposible', function () use ($bd) {
    $bd->consultar('CREATE TABLE fx (f DATETIME)');
    $ok = false;
    try { $bd->consultar('INSERT INTO fx (f) VALUES (?)', ['2026-02-30']); }
    catch (JsonSqlDbError $e) { $ok = $e->sqlState === 'TYPE'; }
    $bd->consultar('DROP TABLE fx');
    return $ok ?: 'aceptó el 30 de febrero';
});

echo "\n== FULL JOIN, REGEXP y CAST ==\n";
chk('FULL JOIN trae las huérfanas de los dos lados', function () use ($bd) {
    $bd->consultar('CREATE TABLE fc (id INTEGER PRIMARY KEY AUTOINCREMENT, n VARCHAR(10))');
    $bd->consultar('CREATE TABLE fp (id INTEGER PRIMARY KEY AUTOINCREMENT, cid INTEGER, ref VARCHAR(10))');
    $bd->consultar("INSERT INTO fc (n) VALUES ('Ana'),('Luis')");
    $bd->consultar("INSERT INTO fp (cid, ref) VALUES (1,'R1'),(99,'HUERFANA')");

    $r = $bd->consultar('SELECT fc.n AS n, fp.ref AS ref FROM fc FULL JOIN fp ON fp.cid = fc.id');
    $pares = [];
    foreach ($r as $f) { $pares[] = ($f['n'] ?? '-') . '/' . ($f['ref'] ?? '-'); }
    sort($pares);
    return $pares === ['-/HUERFANA', 'Ana/R1', 'Luis/-'] ?: $pares;
});
chk('FULL OUTER JOIN es lo mismo', function () use ($bd) {
    $a = $bd->consultar('SELECT fc.n AS n FROM fc FULL JOIN fp ON fp.cid = fc.id');
    $b = $bd->consultar('SELECT fc.n AS n FROM fc FULL OUTER JOIN fp ON fp.cid = fc.id');
    return count($a) === count($b) && count($a) === 3;
});
chk('los otros JOIN siguen igual', function () use ($bd) {
    return count($bd->consultar('SELECT fc.n AS n FROM fc INNER JOIN fp ON fp.cid = fc.id')) === 1
        && count($bd->consultar('SELECT fc.n AS n FROM fc LEFT JOIN fp ON fp.cid = fc.id')) === 2
        && count($bd->consultar('SELECT fc.n AS n FROM fc RIGHT JOIN fp ON fp.cid = fc.id')) === 2;
});
chk('REGEXP filtra con expresión regular', function () use ($bd) {
    $bd->consultar('CREATE TABLE rx (m VARCHAR(40))');
    $bd->consultar("INSERT INTO rx (m) VALUES ('ana@test.com'),('no-es-correo'),('LUIS@X.ES')");
    $r = $bd->consultar('SELECT m FROM rx WHERE m REGEXP ?', ['^[^@]+@[^@]+\\.[a-z]+$']);
    return count($r) === 1 && $r[0]['m'] === 'ana@test.com' ?: json_encode($r);
});
chk('NOT REGEXP invierte y RLIKE es su alias', function () use ($bd) {
    $n = $bd->consultar('SELECT COUNT(*) AS n FROM rx WHERE m NOT REGEXP ?', ['@'])[0]['n'];
    $a = $bd->consultar('SELECT COUNT(*) AS n FROM rx WHERE m RLIKE ?', ['^ana'])[0]['n'];
    return $n === 1 && $a === 1 ?: "not=$n rlike=$a";
});
chk('REGEXP distingue mayúsculas salvo que se pida lo contrario', function () use ($bd) {
    $s = $bd->consultar('SELECT COUNT(*) AS n FROM rx WHERE m REGEXP ?', ['^luis'])[0]['n'];
    $i = $bd->consultar('SELECT COUNT(*) AS n FROM rx WHERE m REGEXP ?', ['(?i)^luis'])[0]['n'];
    return $s === 0 && $i === 1 ?: "sensible=$s insensible=$i";
});
chk('REGEXP sobre NULL da NULL', function () use ($bd) {
    return $bd->consultar('SELECT NULL REGEXP ? AS x', ['a'])[0]['x'] === null;
});
chk('una expresión regular no válida se explica', function () use ($bd) {
    try { $bd->consultar('SELECT m FROM rx WHERE m REGEXP ?', ['[']); }
    catch (JsonSqlDbError $e) { return str_contains($e->getMessage(), 'no válida'); }
    return 'no lanzó error';
});
chk('CAST convierte entre tipos', function () use ($bd) {
    $r = $bd->consultar("SELECT CAST('42' AS INTEGER) AS a, CAST(1.9 AS INTEGER) AS b,
                                CAST(7 AS TEXT) AS c, CAST('3.14159' AS DECIMAL(10,2)) AS d,
                                CAST(NULL AS INTEGER) AS e")[0];
    return $r === ['a' => 42, 'b' => 1, 'c' => '7', 'd' => 3.14, 'e' => null] ?: json_encode($r);
});
chk('CAST trunca hacia cero, no redondea', function () use ($bd) {
    $r = $bd->consultar('SELECT CAST(-1.9 AS INTEGER) AS a, CAST(1.9 AS INTEGER) AS b')[0];
    return $r['a'] === -1 && $r['b'] === 1 ?: json_encode($r);
});
chk('CAST admite la longitud y la ignora', function () use ($bd) {
    return $bd->consultar("SELECT CAST(7 AS VARCHAR(10)) AS x")[0]['x'] === '7';
});
chk('CAST a DATETIME valida la fecha', function () use ($bd) {
    try { $bd->consultar("SELECT CAST('no' AS DATETIME) AS x"); }
    catch (JsonSqlDbError $e) { return $e->sqlState === 'TYPE'; }
    return 'no lanzó error';
});
error('CAST a un tipo inexistente', 'TYPE', 'SELECT CAST(1 AS FANTASMA) AS x');
chk('limpiar las tablas', function () use ($bd) {
    foreach (['fc', 'fp', 'rx'] as $t) { $bd->consultar("DROP TABLE $t"); }
    return true;
});

echo "\n== UNION ==\n";
chk('UNION quita duplicados', function () use ($bd) {
    $bd->consultar('CREATE TABLE ua (n VARCHAR(10))');
    $bd->consultar('CREATE TABLE ub (m VARCHAR(10))');
    $bd->consultar("INSERT INTO ua (n) VALUES ('uno'),('dos')");
    $bd->consultar("INSERT INTO ub (m) VALUES ('dos'),('tres')");
    $r = array_column($bd->consultar('SELECT n FROM ua UNION SELECT m FROM ub'), 'n');
    return $r === ['uno', 'dos', 'tres'] ?: $r;
});
chk('UNION ALL los conserva', function () use ($bd) {
    $r = array_column($bd->consultar('SELECT n FROM ua UNION ALL SELECT m FROM ub'), 'n');
    return $r === ['uno', 'dos', 'dos', 'tres'] ?: $r;
});
chk('las columnas se toman de la primera parte', function () use ($bd) {
    $r = $bd->consultar('SELECT n FROM ua UNION SELECT m FROM ub');
    return array_keys($r[0]) === ['n'] ?: array_keys($r[0]);
});
chk('ORDER BY se aplica al conjunto', function () use ($bd) {
    $r = array_column($bd->consultar('SELECT n FROM ua UNION SELECT m FROM ub ORDER BY n'), 'n');
    return $r === ['dos', 'tres', 'uno'] ?: $r;
});
chk('ORDER BY por posición', function () use ($bd) {
    $r = array_column($bd->consultar('SELECT n FROM ua UNION SELECT m FROM ub ORDER BY 1 DESC'), 'n');
    return $r === ['uno', 'tres', 'dos'] ?: $r;
});
chk('LIMIT se aplica al conjunto, no a la última parte', function () use ($bd) {
    $r = array_column($bd->consultar('SELECT n FROM ua UNION SELECT m FROM ub ORDER BY n LIMIT 2'), 'n');
    return $r === ['dos', 'tres'] ?: $r;
});
chk('tres partes encadenadas', function () use ($bd) {
    $r = array_column($bd->consultar("SELECT n FROM ua UNION SELECT m FROM ub UNION SELECT 'cuatro' AS x"), 'n');
    return count($r) === 4 && in_array('cuatro', $r, true) ?: $r;
});
chk('cada parte conserva su propio WHERE', function () use ($bd) {
    $r = array_column($bd->consultar("SELECT n FROM ua WHERE n = 'uno' UNION SELECT m FROM ub WHERE m = 'tres'"), 'n');
    return $r === ['uno', 'tres'] ?: $r;
});
error('distinto número de columnas', 'SYNTAX', 'SELECT n, n FROM ua UNION SELECT m FROM ub');
error('ORDER BY con una expresión no permitida', 'SYNTAX',
      'SELECT n FROM ua UNION SELECT m FROM ub ORDER BY UPPER(n)');
chk('limpiar las tablas del UNION', function () use ($bd) {
    $bd->consultar('DROP TABLE ua');
    $bd->consultar('DROP TABLE ub');
    return true;
});

echo "\n== CONCAT y GROUP_CONCAT ==\n";
chk('CONCAT junta varios argumentos', function () use ($bd) {
    return $bd->consultar("SELECT CONCAT('a', '-', 'b') AS x")[0]['x'] === 'a-b';
});
chk('CONCAT devuelve NULL si alguno lo es', function () use ($bd) {
    return $bd->consultar("SELECT CONCAT('a', NULL, 'b') AS x")[0]['x'] === null;
});
error('CONCAT con un solo argumento', 'SYNTAX', "SELECT CONCAT('a') AS x");
chk('GROUP_CONCAT agrupa con coma', function () use ($bd) {
    $r = $bd->consultar('SELECT ciudad, GROUP_CONCAT(nombre) AS ns FROM usuarios GROUP BY ciudad ORDER BY ciudad');
    return is_string($r[0]['ns']) && str_contains($r[0]['ns'], ',') ?: json_encode($r);
});
chk('GROUP_CONCAT con separador propio', function () use ($bd) {
    $r = $bd->consultar("SELECT GROUP_CONCAT(nombre, ' | ') AS ns FROM usuarios");
    return str_contains((string)$r[0]['ns'], ' | ');
});
chk('GROUP_CONCAT con DISTINCT', function () use ($bd) {
    $r = $bd->consultar('SELECT GROUP_CONCAT(DISTINCT ciudad) AS cs FROM usuarios');
    $partes = explode(',', (string)$r[0]['cs']);
    return count($partes) === count(array_unique($partes));
});
chk('GROUP_CONCAT ignora los NULL', function () use ($bd) {
    $bd->consultar('CREATE TABLE gc (a VARCHAR(10))');
    $bd->consultar("INSERT INTO gc (a) VALUES ('x'), (NULL), ('y')");
    $r = $bd->consultar('SELECT GROUP_CONCAT(a) AS s FROM gc');
    $bd->consultar('DROP TABLE gc');
    return $r[0]['s'] === 'x,y' ?: $r[0]['s'];
});

echo "\n== EXISTS ==\n";
chk('EXISTS es cierto si la subconsulta devuelve filas', function () use ($bd) {
    return $bd->consultar('SELECT COUNT(*) AS n FROM usuarios WHERE EXISTS (SELECT 1 FROM usuarios)')[0]['n'] > 0;
});
chk('EXISTS es falso si no devuelve ninguna', function () use ($bd) {
    return $bd->consultar("SELECT COUNT(*) AS n FROM usuarios WHERE EXISTS (SELECT 1 FROM usuarios WHERE edad > 999)")[0]['n'] === 0;
});
chk('NOT EXISTS invierte', function () use ($bd) {
    $todas = $bd->consultar('SELECT COUNT(*) AS n FROM usuarios')[0]['n'];
    $r = $bd->consultar("SELECT COUNT(*) AS n FROM usuarios WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE edad > 999)");
    return $r[0]['n'] === $todas;
});
chk('EXISTS correlacionado: la subconsulta ve la fila de fuera', function () use ($bd) {
    $bd->consultar('CREATE TABLE cc (id INTEGER PRIMARY KEY AUTOINCREMENT, n VARCHAR(10))');
    $bd->consultar('CREATE TABLE cp (id INTEGER PRIMARY KEY AUTOINCREMENT, cid INTEGER, t DECIMAL(10,2))');
    $bd->consultar("INSERT INTO cc (n) VALUES ('Ana'),('Luis'),('Eva')");
    $bd->consultar('INSERT INTO cp (cid, t) VALUES (1,100),(1,50),(2,30)');

    $r = array_column($bd->consultar(
        'SELECT n FROM cc WHERE EXISTS (SELECT 1 FROM cp WHERE cp.cid = cc.id) ORDER BY n'), 'n');
    return $r === ['Ana', 'Luis'] ?: $r;
});
chk('NOT EXISTS correlacionado', function () use ($bd) {
    $r = array_column($bd->consultar(
        'SELECT n FROM cc WHERE NOT EXISTS (SELECT 1 FROM cp WHERE cp.cid = cc.id)'), 'n');
    return $r === ['Eva'] ?: $r;
});
chk('subconsulta escalar correlacionada en la lista de columnas', function () use ($bd) {
    $r = $bd->consultar('SELECT n, (SELECT SUM(t) FROM cp WHERE cp.cid = cc.id) AS g
                         FROM cc ORDER BY n');
    return $r === [['n' => 'Ana', 'g' => 150.0], ['n' => 'Eva', 'g' => null],
                   ['n' => 'Luis', 'g' => 30.0]] ?: json_encode($r);
});
chk('IN correlacionado', function () use ($bd) {
    $r = array_column($bd->consultar(
        'SELECT n FROM cc WHERE id IN (SELECT cid FROM cp WHERE cp.t > 40)'), 'n');
    return $r === ['Ana'] ?: $r;
});
chk('una subconsulta sin correlacionar se ejecuta una sola vez', function () use ($bd) {
    // Sigue funcionando el camino rápido: mismo resultado que antes
    $r = array_column($bd->consultar('SELECT n FROM cc WHERE id IN (SELECT cid FROM cp) ORDER BY n'), 'n');
    return $r === ['Ana', 'Luis'] ?: $r;
});
chk('una columna que no existe en ningún lado sigue dando error', function () use ($bd) {
    try { $bd->consultar('SELECT n FROM cc WHERE EXISTS (SELECT 1 FROM cp WHERE cp.cid = cc.fantasma)'); }
    catch (JsonSqlDbError $e) { return str_contains($e->getMessage(), 'Columna desconocida'); }
    return 'no lanzó error';
});
chk('limpiar las tablas correlacionadas', function () use ($bd) {
    $bd->consultar('DROP TABLE cp');
    $bd->consultar('DROP TABLE cc');
    return true;
});

echo "\n== RANDOM ==\n";
chk('devuelve enteros de 64 bits, como SQLite', function () use ($bd) {
    $grande = false;
    $negativo = false;
    for ($i = 0; $i < 60; $i++) {
        $v = $bd->consultar('SELECT RANDOM() AS r')[0]['r'];
        if (!is_int($v)) { return 'no es entero: ' . var_export($v, true); }
        if (abs($v) > 2 ** 40) { $grande = true; }
        if ($v < 0)           { $negativo = true; }
    }
    // Con 32 bits nunca se pasaría de 2^40; y tiene que dar negativos
    return ($grande && $negativo) ?: "grande=$grande negativo=$negativo";
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
