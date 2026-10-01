<?php
declare(strict_types=1);

/**
 * Fuzz diferencial contra SQLite.
 *
 * La idea: en vez de escribir consultas a mano y comprobar lo que crees que
 * deben devolver, se generan consultas al azar, se ejecutan las mismas en
 * jsonSQLDB y en SQLite sobre los mismos datos, y los resultados tienen que
 * coincidir. Es lo que usan SQLite, DuckDB y PostgreSQL (SQLsmith, SQLancer)
 * para encontrar los fallos que nadie imagina, y es la evidencia más fuerte
 * que se puede dar de un motor de consultas sin contratar una auditoría.
 *
 * Cada consulta generada es reproducible: la misma semilla produce exactamente
 * la misma secuencia, así que un fallo se puede volver a provocar con
 *   php tests/f13_fuzz_contra_sqlite.php --semilla=1234 --solo=57
 * y la consulta que lo provoca se ve entera en la salida.
 *
 * Uso:
 *   php tests/f13_fuzz_contra_sqlite.php                    # 2.000 consultas
 *   php tests/f13_fuzz_contra_sqlite.php --n=50000          # tanda larga
 *   php tests/f13_fuzz_contra_sqlite.php --semilla=1234
 *   php tests/f13_fuzz_contra_sqlite.php --solo=57 --semilla=1234
 *   php tests/f13_fuzz_contra_sqlite.php --rechazadas       # lista lo que el motor no admite
 *   php tests/f13_fuzz_contra_sqlite.php --sin-indices      # sin índices en jsonSQLDB
 *
 * Sale con código 1 si hay alguna divergencia, para que sirva en CI.
 *
 * FUERA DEL ALCANCE, a propósito, porque son diferencias documentadas en
 * docs/02-queries.md y no fallos:
 *   - `7 / 2` da 3.5 aquí y 3 en SQLite; `%` igual.
 *   - '5' = 5 es cierto aquí.
 *   - ROUND redondea como PHP, no como SQLite, en los empates.
 *   - El orden de texto usa el cotejamiento configurado, no BINARY.
 * Por eso el fuzz no compara texto con < ni >, ni MIN/MAX sobre texto, ni usa
 * ORDER BY sobre columnas de texto. Eso se queda para f12_contra_sqlite.php,
 * que lo prueba a mano y con las diferencias ya escritas.
 *
 * https://miguelenred.es/jsonsqldb
 */
define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_CACHE_RESULTADOS', 0);
require dirname(__DIR__) . '/engine/bootstrap.php';

use JsonSQLDB\Database;
use JsonSQLDB\JsonSqlDbError;

if (!class_exists('SQLite3')) {
    echo "Sin la extensión sqlite3: esta prueba se salta.\n";
    exit(0);
}

// --- Argumentos -------------------------------------------------------------
$opciones = ['n' => 2000, 'semilla' => (int)date('Ymd'), 'solo' => 0,
             'rechazadas' => false, 'indices' => true];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--rechazadas') { $opciones['rechazadas'] = true; }
    elseif ($a === '--sin-indices') { $opciones['indices'] = false; }
    elseif (preg_match('/^--(n|semilla|solo)=(\d+)$/', $a, $m)) { $opciones[$m[1]] = (int)$m[2]; }
    else { fwrite(STDERR, "Opción no reconocida: $a\n"); exit(2); }
}

// --- Utilidades -------------------------------------------------------------
function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach ((array)scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        is_dir("$dir/$f") ? borrarArbol("$dir/$f") : @unlink("$dir/$f");
    }
    @rmdir($dir);
}

/**
 * Una sentencia en SQLite.
 *
 * El bucle solo se recorre si la sentencia devuelve columnas: pedirle filas a
 * un CREATE o a un INSERT lo vuelve a ejecutar (así funciona fetchArray() de
 * SQLite3), y el segundo CREATE TABLE falla con "table already exists".
 * El mismo detalle está documentado en f12_contra_sqlite.php.
 */
function enSqlite(SQLite3 $s, string $q): array {
    $r = $s->query($q);
    if ($r === false) { throw new RuntimeException('SQLite rechaza: ' . $s->lastErrorMsg() . ' | ' . $q); }
    $filas = [];
    while ($r->numColumns() > 0 && ($f = $r->fetchArray(SQLITE3_ASSOC)) !== false) { $filas[] = $f; }
    return $filas;
}

/**
 * Forma canónica de un valor, para comparar dos motores sin que estorben
 * las diferencias de representación: 5, "5", 5.0 y 5.000001-sin-ruido son el
 * mismo dato. Los reales se redondean a 6 decimales porque sumar en distinto
 * orden puede cambiar el último bit, y eso no es un fallo de nadie.
 *
 * Los enteros y los reales comparten prefijo '#' a propósito: jsonSQLDB
 * devuelve AVG(a) como entero cuando el resultado es exacto (-10) y SQLite lo
 * devuelve como real (-10.0). Es el mismo número, así que no debe contar como
 * divergencia. Sin esto, el fuzz daba 11 falsos positivos por consulta con AVG.
 */
function canon($v): string {
    if ($v === null)  { return '~'; }
    if (is_bool($v))  { return $v ? '#1' : '#0'; }
    if (is_int($v))   { return '#' . $v; }
    if (is_float($v)) { return '#' . rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.'); }
    if (is_string($v)) {
        if ($v !== '' && is_numeric($v)) {
            return '#' . rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
        }
        return 'T:' . $v;
    }
    return '?' . json_encode($v);
}

/**
 * Huella de un resultado: las filas convertidas a texto y ordenadas. Se ordena
 * porque sin ORDER BY ninguno de los dos motores promete un orden concreto, y
 * comparar posiciones daría divergencias que no son fallos. Cuando la consulta
 * sí lleva ORDER BY total, se compara en orden (ver $enOrden).
 */
function huella(array $filas, bool $enOrden = false): array {
    $out = [];
    foreach ($filas as $f) {
        $out[] = implode("\x1f", array_map('canon', array_values($f)));
    }
    if (!$enOrden) { sort($out); }
    return $out;
}

function pintar(array $filas, int $max = 6): string {
    if ($filas === []) { return "        (sin filas)\n"; }
    $s = '';
    foreach (array_slice($filas, 0, $max) as $f) {
        $s .= '        ' . json_encode($f, JSON_UNESCAPED_UNICODE) . "\n";
    }
    if (count($filas) > $max) { $s .= '        ... y ' . (count($filas) - $max) . " más\n"; }
    return $s;
}

// --- Generador --------------------------------------------------------------
/**
 * Todas las funciones del generador usan mt_rand(), que es reproducible con
 * mt_srand(). La misma semilla y el mismo número de iteración producen
 * exactamente la misma consulta.
 */
function ri(int $lo, int $hi): int { return mt_rand($lo, $hi); }
function uno(array $xs) { return $xs[mt_rand(0, count($xs) - 1)]; }

/** Un número literal, entero o con decimales. */
function num(): string {
    return mt_rand(0, 3) === 0
        ? (string)(mt_rand(-40, 40) / 4.0)      // 3.25, -7.5 ...
        : (string)ri(-50, 50);
}

/**
 * Columnas numéricas para las condiciones. 'id' se queda fuera a propósito:
 * en las consultas con JOIN las dos tablas tienen 'id', así que una condición
 * sobre 'id' a secas es ambigua y los dos motores la rechazan — con razón.
 * Sería un fallo del generador, no del motor. El id sigue cubierto en las
 * listas de columnas y en los ORDER BY, donde sí se puede cualificar.
 */
function colNum(): string { return uno(['a', 'd', 'c']); }

/** Una condición sobre una columna numérica o sobre b (texto, solo = y <>). */
function condicion(int $prof = 0): string {
    if ($prof < 2 && mt_rand(0, 4) === 0) {
        $op = uno(['AND', 'OR']);
        $c  = '(' . condicion($prof + 1) . ' ' . $op . ' ' . condicion($prof + 1) . ')';
        return mt_rand(0, 4) === 0 ? 'NOT ' . $c : $c;
    }
    $tipo = ri(0, 11);
    if ($tipo <= 7) {                                   // comparaciones numéricas
        $c = colNum();
        $n = num();
        return uno([
            "$c = $n", "$c <> $n", "$c > $n", "$c >= $n", "$c < $n", "$c <= $n",
            "$c BETWEEN $n AND " . num(),
            "$c IN ($n, " . num() . ', ' . num() . ')',
            "$c NOT IN ($n, " . num() . ')',
        ]);
    }
    if ($tipo === 8) {                                  // NULL
        $c = uno(['a', 'd', 'c', 'b']);
        return mt_rand(0, 1) ? "$c IS NULL" : "$c IS NOT NULL";
    }
    if ($tipo === 9) {                                  // texto, solo igualdad
        return uno(["b = " . quoteTxt(), "b <> " . quoteTxt(), "b IS NULL", "b IS NOT NULL"]);
    }
    if ($tipo === 10) {                                 // LIKE, sin comodines dentro del patrón
        return 'b LIKE ' . uno(["'a%'", "'%a'", "'_n_'", "'A%'", "'%an%'", "''", "'%'"]);
    }
    return '(' . condicion($prof + 1) . ')';             // COALESCE y funciones
}

function quoteTxt(): string {
    return "'" . uno(['ana', 'Bea', '', 'carlos', 'ANA', 'niño', 'zzz']) . "'";
}

/** Una expresión aritmética escalar. */
function expresion(): string {
    $e = uno(['a', 'd', 'c', 'id', 'a + ' . num(), 'a * 2', '-a', 'c * 2']);
    return uno([$e, "ABS($e)", "COALESCE($e, 0)", "$e + d", "$e - c"]);
}

/**
 * Devuelve [sql, enOrden, modo]. $enOrden indica que la consulta lleva un
 * ORDER BY total (por id, que es único) y por tanto se puede comparar la
 * secuencia, no solo el conjunto de filas. Eso es lo que permite cazar fallos
 * del tipo "ORDER BY ... LIMIT se queda con las filas equivocadas".
 */
function generar(): array {
    $modo = ri(0, 5);
    $where = mt_rand(0, 2) === 0 ? '' : ' WHERE ' . condicion();

    if ($modo === 0) {                                  // filas sueltas
        $cols = uno(['id', 'id, a', 'id, a, d', 'id, b, c', 'id, a, b, c, d']);
        $orden = mt_rand(0, 1) === 0 ? '' : ' ORDER BY id';
        $lim = $orden !== '' && mt_rand(0, 2) === 0 ? ' LIMIT ' . ri(0, 8) : '';
        return ["SELECT $cols FROM t$where$orden$lim", $orden !== '', 'filas'];
    }
    if ($modo === 1) {                                  // agregados sin grupo
        $ag = uno(['COUNT(*)', 'COUNT(a)', 'COUNT(DISTINCT a)', 'SUM(a)', 'AVG(a)',
                   'MIN(a)', 'MAX(a)', 'SUM(c)', 'AVG(c)', 'COUNT(c)',
                   'SUM(a) + COUNT(*)', 'MAX(a) - MIN(a)', 'AVG(d) * 2']);
        return ["SELECT $ag AS v FROM t$where", false, 'agregado'];
    }
    if ($modo === 2) {                                  // GROUP BY
        $g = uno(['d', 'd, a', 'b', 'c']);
        $ag = uno(['COUNT(*)', 'SUM(a)', 'AVG(a)', 'MIN(c)', 'MAX(d)', 'COUNT(a)',
                   'COUNT(DISTINCT d)', 'SUM(a) + SUM(c)']);
        $hav = mt_rand(0, 2) === 0 ? ' HAVING COUNT(*) > ' . ri(0, 3) : '';
        $gsel = ($g === 'd, a') ? 'd, a' : $g;
        return ["SELECT $gsel, $ag AS v FROM t$where GROUP BY $g$hav", false, 'grupo'];
    }
    if ($modo === 3) {                                  // expresiones
        return ["SELECT id, " . expresion() . " AS v FROM t$where ORDER BY id", true, 'expresion'];
    }
    if ($modo === 4) {                                  // JOIN
        $tipo = uno(['JOIN', 'LEFT JOIN', 'INNER JOIN']);
        $w = $where === '' ? '' : $where . ' AND ';
        $agg = mt_rand(0, 1) === 0;
        if ($agg) {
            return ["SELECT COUNT(*) AS n, SUM(u.x) AS s FROM t $tipo u ON u.tid = t.id"
                    . ($where === '' ? '' : $where), false, 'join'];
        }
        $w2 = $where === '' ? '' : ' AND ' . substr($where, 7);
        return ["SELECT t.id AS ti, u.id AS ui, u.x AS ux FROM t $tipo u ON u.tid = t.id"
                . $w2 . ' ORDER BY t.id, u.id', true, 'join'];
    }
    // subconsulta. substr(..., 7) quita el ' WHERE ' inicial, que ya lo pone
    // la plantilla de abajo: sin eso salía 'WHERE  WHERE' y el motor lo
    // rechazaba con razón.
    $w = $where === '' ? '' : substr($where, 7) . ' AND ';
    return ['SELECT id FROM t WHERE ' . $w . 'id ' . uno(['IN', 'NOT IN'])
        . ' (SELECT tid FROM u WHERE tid IS NOT NULL)', false, 'subconsulta'];
}

// --- Preparación de las dos bases -------------------------------------------
$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f13';
borrarArbol($raiz);
mkdir($raiz, 0775, true);
Database::crear('b', $raiz);
$j = new Database('b', $raiz);
$s = new SQLite3(':memory:');
$s->enableExceptions(true);

// Sin claves foráneas ni UNIQUE a propósito: aquí se comparan consultas, y las
// restricciones las prueba f3_escrituras contra su propio modelo.
$esquema = [
    'CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b VARCHAR(20), c DOUBLE, d INTEGER)',
    'CREATE TABLE u (id INTEGER PRIMARY KEY, tid INTEGER, x INTEGER, y VARCHAR(10))',
];
foreach ($esquema as $q) { $j->consultar($q); enSqlite($s, $q); }
if ($opciones['indices']) {
    foreach (['CREATE INDEX ia ON t (a)', 'CREATE INDEX id2 ON t (d)', 'CREATE INDEX iut ON u (tid)'] as $q) {
        $j->consultar($q);
    }
}

// Datos deterministas: los mismos en los dos motores, con NULLs repartidos.
mt_srand($opciones['semilla']);
$textos = ['ana', 'Bea', '', 'carlos', 'ANA', 'niño', 'zzz', 'a$n', 'x y'];
$ins = [[], []];
for ($i = 1; $i <= 40; $i++) {
    $ins[0][] = sprintf('(%d, %s, %s, %s, %s)',
        $i,
        mt_rand(0, 4) === 0 ? 'NULL' : (string)ri(-50, 50),
        mt_rand(0, 4) === 0 ? 'NULL' : "'" . str_replace("'", "''", uno($textos)) . "'",
        mt_rand(0, 4) === 0 ? 'NULL' : (string)(ri(-160, 160) / 4.0),
        mt_rand(0, 4) === 0 ? 'NULL' : (string)ri(0, 5)
    );
}
for ($i = 1; $i <= 60; $i++) {
    $ins[1][] = sprintf('(%d, %s, %s, %s)',
        $i,
        mt_rand(0, 5) === 0 ? 'NULL' : (string)ri(0, 42),
        mt_rand(0, 5) === 0 ? 'NULL' : (string)ri(-20, 100),
        mt_rand(0, 5) === 0 ? 'NULL' : "'" . str_replace("'", "''", uno($textos)) . "'"
    );
}
foreach ([['t', 0], ['u', 1]] as [$tab, $k]) {
    $q = "INSERT INTO $tab (id, " . ($tab === 't' ? 'a, b, c, d' : 'tid, x, y') . ') VALUES '
        . implode(', ', $ins[$k]);
    $j->consultar($q);
    $s->exec($q);
}

// La semilla vuelve a fijarse para que el generador no dependa de la carga de datos.
mt_srand($opciones['semilla']);

// --- Carrera ----------------------------------------------------------------
echo "\nfuzz diferencial contra SQLite · jsonSQLDB " . JsonSQLDB\Config::version() . " · PHP " . PHP_VERSION . "\n";
echo 'semilla ' . $opciones['semilla'] . ' · ' . $opciones['n'] . ' consultas · índices: '
    . ($opciones['indices'] ? 'sí' : 'no') . "\n\n";

$comparadas = 0; $divergencias = []; $rechazadas = []; $enOrden = 0;
$t0 = microtime(true);

for ($i = 1; $i <= $opciones['n']; $i++) {
    [$sql, $orden, $modo] = generar();
    if ($opciones['solo'] > 0 && $i !== $opciones['solo']) { continue; }

    try {
        $mio = $j->consultar($sql);
    } catch (JsonSqlDbError $e) {
        $rechazadas[$sql] = $e->getMessage();
        if ($opciones['solo'] > 0) {
            echo "  El motor rechaza (iteración $i):\n    $sql\n    -> " . $e->getMessage() . "\n";
        }
        // Si SQLite sí la acepta, es una divergencia: el motor ha dejado de
        // admitir algo que el generador produce. Si tampoco, es del generador
        try {
            enSqlite($s, $sql);
            $divergencias[] = [$i, $sql, $orden, $modo, 'el motor la rechaza y SQLite no: ' . $e->getMessage(), []];
        } catch (Throwable $e2) {
            // los dos la rechazan
        }
        continue;
    } catch (Throwable $e) {
        $divergencias[] = [$i, $sql, $orden, $modo, 'excepción ' . get_class($e) . ': ' . $e->getMessage(), []];
        continue;
    }
    try {
        $suyo = enSqlite($s, $sql);
    } catch (Throwable $e) {
        $divergencias[] = [$i, $sql, $orden, $modo, 'SQLite lo rechaza (¿fallo del generador?): ' . $e->getMessage(), []];
        continue;
    }

    $comparadas++;
    if ($orden) { $enOrden++; }
    if (huella($mio, $orden) !== huella($suyo, $orden)) {
        $divergencias[] = [$i, $sql, $orden, $modo, 'resultados distintos', [$mio, $suyo]];
        if ($opciones['solo'] > 0) {
            echo "  DIVERGENCIA en la iteración $i:\n    $sql\n";
            echo "    jsonSQLDB:\n" . pintar($mio) . "    SQLite:\n" . pintar($suyo) . "\n";
        }
    }
}

$ms = (microtime(true) - $t0) * 1000;
echo "  consultas comparadas      $comparadas  (en orden: $enOrden)\n";
printf("  tiempo                    %.0f ms  (%.2f ms por consulta)\n", $ms, $ms / max(1, $comparadas));
echo '  rechazadas por el motor   ' . count($rechazadas) . "  (usa --rechazadas para verlas)\n";
echo '  divergencias              ' . count($divergencias) . "\n";

if ($opciones['rechazadas'] && $rechazadas !== []) {
    echo "\n  --- Lo que el motor no admite (candidato a tabla de diferencias) ---\n";
    $vistas = [];
    foreach ($rechazadas as $sql => $msg) {
        $clave = preg_replace('/-?\d+(\.\d+)?/', 'N', $sql);
        if (isset($vistas[$clave])) { continue; }
        $vistas[$clave] = true;
        echo "    $sql\n      -> " . substr($msg, 0, 110) . "\n";
    }
}

if ($divergencias !== []) {
    echo "\n  --- Divergencias (reproducibles) ---\n";
    foreach (array_slice($divergencias, 0, 10) as [$i, $sql, $orden, $modo, $que, $datos]) {
        echo "    iteración $i  [$modo" . ($orden ? ', en orden' : '') . "]\n";
        echo "    $sql\n";
        echo "    $que\n";
        if ($datos !== []) {
            echo "      jsonSQLDB:\n" . pintar($datos[0]) . "      SQLite:\n" . pintar($datos[1]);
        }
        echo "    Reproducir: php tests/f13_fuzz_contra_sqlite.php --semilla={$opciones['semilla']} --solo=$i\n\n";
    }
    if (count($divergencias) > 10) {
        echo '    ... y ' . (count($divergencias) - 10) . " divergencias más\n";
    }
}

echo "\n---------------------------------------\n";
if ($divergencias === []) {
    echo "OK: $comparadas   FALLOS: 0\n";
    $codigo = 0;
} else {
    echo 'OK: ' . ($comparadas - count($divergencias)) . '   FALLOS: ' . count($divergencias) . "\n";
    $codigo = 1;
}
borrarArbol($raiz);
exit($codigo);
