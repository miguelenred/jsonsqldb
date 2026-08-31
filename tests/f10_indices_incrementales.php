<?php
declare(strict_types=1);

/**
 * f10_indices_incrementales.php — el índice que se amplía en vez de rehacerse
 *
 * Rehacer el índice entero era el 67 % de lo que cuesta insertar una fila en una
 * tabla grande, y casi siempre es trabajo repetido: si la escritura solo añade
 * al final, las filas de antes no se mueven y sus claves son las mismas.
 *
 * El riesgo de esta optimización no es simétrico, y por eso tiene su propia
 * suite:
 *
 *   - una entrada de MÁS en el índice solo hace la consulta más lenta, porque el
 *     WHERE se vuelve a aplicar sobre las filas que se leen;
 *   - una entrada de MENOS devuelve resultados incompletos, y nada lo delata:
 *     no hay error, no hay aviso, solo faltan filas.
 *
 * Así que aquí no basta con «los tests pasan». Cada prueba compara el índice
 * ampliado contra el que saldría de rehacerlo entero, y las consultas contra el
 * mismo resultado sin índice. Si la salvaguarda que decide cuándo se puede
 * ampliar se quita, esta suite tiene que ponerse roja.
 */

define('JSONSQLDB_CONEXION_DIRECTA', true);
define('JSONSQLDB_FILAS_POR_PARTE', 50);

require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Catalog;
use JsonSQLDB\Database;
use JsonSQLDB\Indexes;
use JsonSQLDB\Storage;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f10';
$ok = 0; $ko = 0;

function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
        if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
        else { $ko++; echo "  FALLO $titulo -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        $ko++;
        echo "  FALLO $titulo -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function borrarArbol(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach ((array)scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $r = "$dir/$f";
        is_dir($r) ? borrarArbol($r) : @unlink($r);
    }
    @rmdir($dir);
}

function resumen(): void {
    global $ok, $ko;
    echo "\n---------------------------------------\n";
    echo "OK: $ok   FALLOS: $ko\n";
    exit($ko === 0 ? 0 : 1);
}

borrarArbol($raiz);
@mkdir($raiz, 0775, true);

/** Crea una base con una tabla indexada y $n filas. */
function preparar(string $raiz, int $n): Database
{
    borrarArbol("$raiz/x");
    Database::crear('x', $raiz);
    $bd = new Database('x', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, cod VARCHAR(20) UNIQUE,
                                    ciudad VARCHAR(20), v VARCHAR(30))');
    $bd->consultar('CREATE INDEX ix_ciudad ON t (ciudad)');
    $bd->consultar('CREATE INDEX ix_comp ON t (ciudad, v)');

    $ciudades = ['Madrid', 'Elche', 'Murcia', 'Bilbao'];
    $filas = [];
    for ($i = 1; $i <= $n; $i++) {
        $filas[] = "($i, 'c$i', '" . $ciudades[$i % 4] . "', 'v" . ($i % 7) . "')";
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $filas));
    return $bd;
}

/**
 * Los índices tal y como están en disco, sin la revisión (que sube siempre).
 *
 * @return array<string, array>
 */
function indicesEnDisco(string $raiz): array
{
    $out = [];
    foreach ((array)glob("$raiz/x/t.idx.*.json") as $f) {
        $j = json_decode((string)file_get_contents((string)$f), true);
        unset($j['rev']);
        $out[basename((string)$f)] = $j;
    }
    ksort($out);
    return $out;
}

/** Rehace todos los índices desde cero y devuelve cómo quedarían. */
function indicesRehechos(string $raiz): array
{
    $st = new Storage($raiz, 'x');
    $st->bloquear(true);
    $cat   = new Catalog($st);
    $meta  = $cat->meta('t');
    $filas = $st->leerFilas('t', true);
    $st->desbloquear();
    unset($st, $cat);

    $out = [];
    foreach (Indexes::definiciones($meta) as $def) {
        $out["t.idx.{$def['name']}.json"] = [
            'index'   => $def['name'],
            'table'   => 't',
            'columns' => $def['columns'],
            'rows'    => count($filas),
            'chunk'   => (int)JSONSQLDB_FILAS_POR_PARTE,
            'keys'    => Indexes::construir($filas, $def['columns']),
        ];
    }
    ksort($out);
    return $out;
}

/** Compara el índice de disco con el que saldría de rehacerlo. */
function cuadraConRehacerlo(string $raiz): bool|string
{
    $enDisco  = indicesEnDisco($raiz);
    $rehechos = indicesRehechos($raiz);
    if (array_keys($enDisco) !== array_keys($rehechos)) {
        return 'no están los mismos índices: ' . implode(',', array_keys($enDisco))
             . ' frente a ' . implode(',', array_keys($rehechos));
    }
    foreach ($rehechos as $nombre => $esperado) {
        $hay = $enDisco[$nombre];
        if (($hay['rows'] ?? null) !== $esperado['rows']) {
            return "$nombre dice tener {$hay['rows']} filas y son {$esperado['rows']}";
        }
        if (($hay['keys'] ?? null) !== $esperado['keys']) {
            // Qué falta y qué sobra, que es lo que hace falta para depurarlo
            $faltan = $sobran = 0;
            foreach ($esperado['keys'] as $k => $posiciones) {
                $faltan += count(array_diff($posiciones, (array)($hay['keys'][$k] ?? [])));
            }
            foreach ((array)($hay['keys'] ?? []) as $k => $posiciones) {
                $sobran += count(array_diff((array)$posiciones, (array)($esperado['keys'][$k] ?? [])));
            }
            return "$nombre no cuadra: faltan $faltan posiciones, sobran $sobran";
        }
    }
    return true;
}

/**
 * La consulta por índice tiene que devolver lo mismo que sin él.
 *
 * El `OR 1 = 0` impide usar el índice sin cambiar qué filas cumplen: es el mismo
 * truco que usa f8_indices.
 */
function igualQueSinIndice(Database $bd, string $where): bool|string
{
    $con = $bd->consultar("SELECT id FROM t WHERE $where ORDER BY id");
    $sin = $bd->consultar("SELECT id FROM t WHERE ($where) OR 1 = 0 ORDER BY id");
    if ($con === $sin) {
        return true;
    }
    $idsCon = array_column($con, 'id');
    $idsSin = array_column($sin, 'id');
    return sprintf('con índice %d filas, sin índice %d (faltan %s)',
        count($idsCon), count($idsSin),
        implode(',', array_slice(array_diff($idsSin, $idsCon), 0, 5)) ?: 'ninguna');
}

// ----------------------------------------------------------------------

echo "== Insertar al final amplía el índice sin rehacerlo ==\n";

chk('tras insertar una fila, el índice es el mismo que si se rehiciera', function () use ($raiz) {
    $bd = preparar($raiz, 500);
    $bd->consultar("INSERT INTO t VALUES (9001, 'z1', 'Madrid', 'v3')");
    $r = cuadraConRehacerlo($raiz);
    unset($bd);
    return $r;
});

chk('la fila nueva se encuentra por el índice', function () use ($raiz) {
    $bd = new Database('x', $raiz);
    $r = igualQueSinIndice($bd, "ciudad = 'Madrid'");
    if ($r !== true) { return $r; }
    $hay = $bd->consultar("SELECT id FROM t WHERE ciudad = 'Madrid' AND v = 'v3' ORDER BY id");
    unset($bd);
    return in_array(9001, array_column($hay, 'id'), true)
        ?: 'la fila recién insertada no aparece buscando por el índice compuesto';
});

chk('muchas inserciones seguidas siguen cuadrando', function () use ($raiz) {
    $bd = preparar($raiz, 300);
    for ($i = 1; $i <= 25; $i++) {
        $bd->consultar('INSERT INTO t VALUES (?, ?, ?, ?)',
            [10000 + $i, "y$i", $i % 2 ? 'Elche' : 'Madrid', 'v' . ($i % 7)]);
    }
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Elche'");
    unset($bd);
    return $r;
});

chk('un INSERT de varias filas de golpe también', function () use ($raiz) {
    $bd = preparar($raiz, 400);
    $bd->consultar("INSERT INTO t VALUES (8001,'w1','Bilbao','v1'),
                                        (8002,'w2','Bilbao','v2'),
                                        (8003,'w3','Murcia','v1')");
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Bilbao'");
    unset($bd);
    return $r;
});

echo "\n== Cuando NO se puede ampliar, se rehace ==\n";

chk('un UPDATE de una fila del medio no reutiliza el índice viejo', function () use ($raiz) {
    // Cambiar una fila indexada cambia su clave: la entrada de antes tiene que
    // desaparecer, y ampliando el índice se quedaría.
    $bd = preparar($raiz, 400);
    $bd->consultar("UPDATE t SET ciudad = 'Vigo' WHERE id = 200");
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Vigo'");
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Madrid'");
    unset($bd);
    return $r;
});

chk('un DELETE del medio mueve todas las posiciones de detrás', function () use ($raiz) {
    // Es el caso más peligroso: al compactar, cada fila posterior cambia de
    // posición, así que TODAS las entradas del índice viejo son incorrectas.
    $bd = preparar($raiz, 400);
    $bd->consultar('DELETE FROM t WHERE id = 5');
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Elche'");
    unset($bd);
    return $r;
});

chk('borrar y luego insertar, en la misma sentencia y en distintas', function () use ($raiz) {
    $bd = preparar($raiz, 300);
    $bd->consultar('DELETE FROM t WHERE id > 290');
    $bd->consultar("INSERT INTO t VALUES (7001, 'q1', 'Elche', 'v0')");
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Elche'");
    unset($bd);
    return $r;
});

chk('un UPDATE que no toca columnas indexadas tampoco rompe nada', function () use ($raiz) {
    $bd = preparar($raiz, 300);
    $bd->consultar("UPDATE t SET cod = 'nuevo' WHERE id = 150");
    $r = cuadraConRehacerlo($raiz);
    unset($bd);
    return $r;
});

chk('crear un índice nuevo con la tabla ya llena', function () use ($raiz) {
    $bd = preparar($raiz, 300);
    $bd->consultar("INSERT INTO t VALUES (6001, 'p1', 'Madrid', 'v1')");
    $bd->consultar('CREATE INDEX ix_v ON t (v)');
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "v = 'v1'");
    unset($bd);
    return $r;
});

chk('ALTER TABLE que cambia las columnas rehace los índices', function () use ($raiz) {
    $bd = preparar($raiz, 200);
    $bd->consultar('ALTER TABLE t ADD COLUMN extra VARCHAR(10)');
    $bd->consultar("INSERT INTO t VALUES (5001, 'o1', 'Murcia', 'v2', 'e')");
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Murcia'");
    unset($bd);
    return $r;
});

echo "\n== El índice de disco no se toma por bueno sin comprobarlo ==\n";

chk('un índice con un recuento de filas que no cuadra se rehace', function () use ($raiz) {
    // Es la salvaguarda: si el índice viejo se hizo con otro número de filas,
    // sus posiciones no son las de ahora. Se falsea a mano y la siguiente
    // inserción tiene que rehacerlo en vez de ampliarlo.
    $bd = preparar($raiz, 300);
    unset($bd);

    $f = "$raiz/x/t.idx.ix_ciudad.json";
    $j = json_decode((string)file_get_contents($f), true);
    $j['rows'] = 999;                              // mentira
    file_put_contents($f, json_encode($j) . "\n");

    $bd = new Database('x', $raiz);
    $bd->consultar("INSERT INTO t VALUES (4001, 'n1', 'Elche', 'v1')");
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Elche'");
    unset($bd);
    return $r;
});

chk('un índice de otra revisión se rehace', function () use ($raiz) {
    $bd = preparar($raiz, 300);
    unset($bd);

    $f = "$raiz/x/t.idx.ix_ciudad.json";
    $j = json_decode((string)file_get_contents($f), true);
    $j['rev'] = 99999;                             // de otro momento
    file_put_contents($f, json_encode($j) . "\n");

    $bd = new Database('x', $raiz);
    $bd->consultar("INSERT INTO t VALUES (4002, 'n2', 'Elche', 'v1')");
    $r = cuadraConRehacerlo($raiz);
    unset($bd);
    return $r;
});

chk('un índice ilegible se rehace en vez de romper', function () use ($raiz) {
    $bd = preparar($raiz, 200);
    unset($bd);

    file_put_contents("$raiz/x/t.idx.ix_ciudad.json", '{ esto no es JSON');

    $bd = new Database('x', $raiz);
    $bd->consultar("INSERT INTO t VALUES (4003, 'n3', 'Madrid', 'v1')");
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Madrid'");
    unset($bd);
    return $r;
});

chk('un índice cuyas columnas ya no son las de la tabla se rehace', function () use ($raiz) {
    $bd = preparar($raiz, 200);
    unset($bd);

    $f = "$raiz/x/t.idx.ix_ciudad.json";
    $j = json_decode((string)file_get_contents($f), true);
    $j['columns'] = ['v'];                         // no es lo que define el índice
    file_put_contents($f, json_encode($j) . "\n");

    $bd = new Database('x', $raiz);
    $bd->consultar("INSERT INTO t VALUES (4004, 'n4', 'Bilbao', 'v1')");
    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    $r = igualQueSinIndice($bd, "ciudad = 'Bilbao'");
    unset($bd);
    return $r;
});

echo "\n== Valores que suelen romper las claves ==\n";

chk('NULL, cadenas vacías y números en columnas indexadas', function () use ($raiz) {
    borrarArbol("$raiz/x");
    Database::crear('x', $raiz);
    $bd = new Database('x', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, cod VARCHAR(20) UNIQUE,
                                    ciudad VARCHAR(20), v VARCHAR(30))');
    $bd->consultar('CREATE INDEX ix_ciudad ON t (ciudad)');
    $bd->consultar('CREATE INDEX ix_comp ON t (ciudad, v)');
    $bd->consultar("INSERT INTO t VALUES (1,'a',NULL,'x'), (2,'b','','y'), (3,'c','0','z')");

    // Se añaden más de los mismos, que es cuando se amplía el índice
    $bd->consultar("INSERT INTO t VALUES (4,'d',NULL,'x')");
    $bd->consultar("INSERT INTO t VALUES (5,'e','','y')");
    $bd->consultar("INSERT INTO t VALUES (6,'f','0','z')");

    $r = cuadraConRehacerlo($raiz);
    if ($r !== true) { unset($bd); return $r; }
    foreach (["ciudad = ''", "ciudad = '0'", 'ciudad IS NULL'] as $where) {
        $r = igualQueSinIndice($bd, $where);
        if ($r !== true) { unset($bd); return "$where -> $r"; }
    }
    unset($bd);
    return true;
});

echo "\n== Limpieza ==\n";
chk('sin restos', function () use ($raiz) {
    borrarArbol($raiz);
    return !is_dir($raiz);
});

resumen();
