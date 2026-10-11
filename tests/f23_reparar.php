<?php
/**
 * Fase 23 (2.9): ficheros JSON estropeados o editados a mano. Lo seguro se
 * repara solo al leerlo, aunque la tabla esté en la caché; una fila dudosa da
 * error con su línea sin tocar nada, y REPAIR TABLES la aparta; el original
 * queda en .reparados/. Uso: php tests/f23_reparar.php
 */
declare(strict_types=1);

define('JSONSQLDB_CONEXION_DIRECTA', true);
// Lo que se prueba es la lectura de los ficheros: sin caché de resultados, que
// serviría una consulta repetida sin leerlos. Con ella, en su prueba, aparte
define('JSONSQLDB_CACHE_RESULTADOS', 0);
require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\Database;

$raiz = sys_get_temp_dir() . '/jsonsqldb_test_f23';
exec('rm -rf ' . escapeshellarg($raiz));
mkdir($raiz, 0777, true);

$ok = 0;
$ko = 0;
function chk(string $titulo, callable $fn): void
{
    global $ok, $ko;
    try {
        $r = $fn();
    } catch (Throwable $e) {
        $r = get_class($e) . ': ' . $e->getMessage();
    }
    if ($r === true) {
        $ok++;
        echo "  ok    $titulo\n";
    } else {
        $ko++;
        echo "  FALLO $titulo -> " . (is_string($r) ? $r : var_export($r, true)) . "\n";
    }
}

/** Una base nueva con una tabla de dos partes y un índice, ya leída (caché caliente) */
function nueva(): string
{
    global $raiz;
    exec('rm -rf ' . escapeshellarg("$raiz/b"));
    Database::crear('b', $raiz);
    $bd = new Database('b', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, nombre TEXT, ciudad TEXT)');
    $bd->consultar('CREATE INDEX ix_ciudad ON t (ciudad)');
    $v = [];
    for ($i = 1; $i <= 1500; $i++) {
        $v[] = "($i, 'N$i', 'C" . ($i % 7) . "')";
    }
    $bd->consultar('INSERT INTO t VALUES ' . implode(',', $v));
    // Como en una base de verdad, los ficheros son de antes: una edición a
    // mano en el mismo segundo que la escritura y con el mismo tamaño no se
    // distingue por el sello (documentado)
    foreach (glob("$raiz/b/t*.json") ?: [] as $f) {
        touch($f, time() - 60);
    }
    leer();
    return "$raiz/b";
}

/** Una consulta que lee todas las partes, y una por el índice */
function leer(): array
{
    global $raiz;
    $bd = new Database('b', $raiz);
    return [$bd->consultar("SELECT COUNT(*) AS n, SUM(id) AS s FROM t WHERE nombre <> 'x'")[0],
            $bd->consultar("SELECT COUNT(*) AS n FROM t WHERE ciudad = 'C9'")[0]['n']];
}

$valida = static fn(string $f): bool => json_decode((string)file_get_contents($f)) !== null;

echo "== Lo seguro se repara solo, aunque la tabla esté en la caché ==\n";
chk('una parte con una coma de más y sin su llave final: la consulta da lo de siempre y el fichero queda reparado', function () use ($valida) {
    $d = nueva();
    $f = "$d/t.json";
    file_put_contents($f, substr(str_replace('{"id":5,"nombre":"N5","ciudad":"C5"}', '{"id":5,"nombre":"N5","ciudad":"C5"},,', (string)file_get_contents($f)), 0, -2));
    $r = leer();
    $copias = glob("$d/.reparados/t.json.*") ?: [];
    return $r[0] === ['n' => 1500, 's' => 1125750] && $valida($f) && count($copias) === 1
        && !$valida($copias[0]) ?: json_encode([$r, $valida($f), $copias]);
});
chk('un rev.json destrozado: la consulta es correcta, ninguna caché vieja vale y se rehace', function () use ($valida) {
    $d = nueva();
    file_put_contents("$d/t.rev.json", '{"rev": 7, "parts": [');
    $r = leer();
    return $r[0] === ['n' => 1500, 's' => 1125750] && $valida("$d/t.rev.json") ?: json_encode($r);
});
chk('un índice destrozado no da resultados erróneos y se rehace en cuanto una consulta lo usa', function () use ($valida) {
    global $raiz;
    $d = nueva();
    file_put_contents("$d/t.idx.ix_ciudad.json", 'basura');
    // Un resultado ya guardado sigue valiendo (los datos no han cambiado); una
    // consulta que usa el índice lo encuentra roto y lo rehace
    $r = (new Database('b', $raiz))->consultar("SELECT COUNT(*) AS n FROM t WHERE ciudad = 'C3'");
    return $r === [['n' => 214]] && $valida("$d/t.idx.ix_ciudad.json") ?: json_encode($r);
});
chk('COUNT(*) cuenta las filas con comas de más igual que al leerlas, entera la parte o a trozos (2.9: con dos comas, una fila menos)', function () {
    global $raiz;
    exec('rm -rf ' . escapeshellarg("$raiz/g"));
    Database::crear('g', $raiz);
    $bd = new Database('g', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, x TEXT)');
    for ($i = 0; $i < 10; $i++) {
        $bd->consultar('INSERT INTO t VALUES ' . implode(',', array_map(static fn($n) => "($n, '" . str_repeat('x', 5000) . "')", range($i * 100 + 1, $i * 100 + 100))));
    }
    $f = "$raiz/g/t.json";
    $texto = (string)file_get_contents($f);
    foreach (['2' => '},,', '3' => '} ,', '4' => '}, ,'] as $id => $cola) {
        $texto = (string)preg_replace('/^(\s*\{"id":' . $id . ',[^\n]*\}),$/m', '$1' . substr($cola, 1), $texto);
    }
    file_put_contents($f, $texto);
    touch($f, time() + 3600);                          // sin el recuento guardado: se cuenta cada vez
    $contar = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
            . ' echo (new JsonSQLDB\Database("g", ' . var_export($raiz, true) . '))->consultar("SELECT COUNT(*) AS n FROM t")[0]["n"];';
    $aTrozos = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=16M -r ' . escapeshellarg($contar) . ' 2>&1');
    $entera = (new Database('g', $raiz))->consultar('SELECT COUNT(*) AS m FROM t')[0]['m'];     // otra consulta: no sale de la caché
    $leidas = count((new Database('g', $raiz))->consultar('SELECT id FROM t'));
    exec('rm -rf ' . escapeshellarg("$raiz/g"));
    return $aTrozos === '1000' && $entera === 1000 && $leidas === 1000 ?: json_encode([$aTrozos, $entera, $leidas]);
});
chk('la estructura con una coma sobrante se repara; cortada, da error y no se adivina', function () use ($valida) {
    $d = nueva();
    $m = "$d/t.meta.json";
    $bien = (string)file_get_contents($m);
    file_put_contents($m, preg_replace('/\]\s*\}\s*$/', '],}', $bien));
    leer();
    $reparada = $valida($m);
    file_put_contents($m, substr($bien, 0, (int)(strlen($bien) / 2)));
    try {
        leer();
        $cortada = 'no dio error';
    } catch (JsonSQLDB\JsonSqlDbError $e) {
        $cortada = str_contains($e->getMessage(), 'no se puede arreglar sola') ? true : $e->getMessage();
    }
    return $reparada && $cortada === true ?: json_encode([$reparada, $cortada]);
});

echo "\n== Ediciones a mano válidas: se ven al momento ==\n";
chk('un dato cambiado a mano con la tabla en la caché se ve en la consulta siguiente', function () {
    global $raiz;
    $d = nueva();
    $f = "$d/t.part2.json";
    file_put_contents($f, str_replace('"nombre":"N1200"', '"nombre":"Editado"', (string)file_get_contents($f)));
    $r = (new Database('b', $raiz))->consultar("SELECT nombre FROM t WHERE nombre LIKE 'Edit%'");
    return $r === [['nombre' => 'Editado']] ?: json_encode($r);
});
chk('una columna indexada cambiada a mano: la tabla se reescribe y el índice la encuentra', function () {
    global $raiz;
    $d = nueva();
    $f = "$d/t.part2.json";
    file_put_contents($f, str_replace('{"id":1200,"nombre":"N1200","ciudad":"C3"}', '{"id":1200,"nombre":"N1200","ciudad":"C9"}', (string)file_get_contents($f)));
    leer();                                               // la lee, ve la edición y reescribe la tabla
    $r = (new Database('b', $raiz))->consultar("SELECT id FROM t WHERE ciudad = 'C9'");
    return $r === [['id' => 1200]] ?: json_encode($r);
});

chk('con la caché vacía, una edición válida que cambia una columna indexada también se ve (los desfases no cuadran)', function () {
    global $raiz;
    $d = nueva();
    exec('rm -rf ' . escapeshellarg("$d/.cache"));
    $f = "$d/t.part2.json";
    file_put_contents($f, str_replace('{"id":1200,"nombre":"N1200","ciudad":"C3"}', '{"id":1200,"nombre":"N1200","ciudad":"C9 editada"}', (string)file_get_contents($f)));
    leer();
    $r = (new Database('b', $raiz))->consultar("SELECT id FROM t WHERE ciudad = 'C9 editada'");
    return $r === [['id' => 1200]] ?: json_encode($r);
});
chk('una parte recién escrita, que en la caché solo tiene su huella: tocarla no reescribe nada, y una edición que deja cada línea con su longitud se ve y el índice la encuentra (2.9)', function () {
    global $raiz;
    $d = nueva();
    $bd = new Database('b', $raiz);
    $f = "$d/t.part2.json";
    $rev = static fn(): int => json_decode((string)file_get_contents("$d/t.rev.json"), true)['rev'];
    // Cada UPDATE reescribe la segunda parte: de ella, en la caché, solo queda la huella
    $bd->consultar("UPDATE t SET nombre = 'M1100' WHERE id = 1100");
    touch($f, time() - 30);
    $antes = $rev();
    leer();
    $tocada = $rev() === $antes;
    $bd->consultar("UPDATE t SET nombre = 'M1101' WHERE id = 1101");
    file_put_contents($f, str_replace('{"id":1200,"nombre":"N1200","ciudad":"C3"}', '{"id":1200,"nombre":"N1200","ciudad":"C9"}', (string)file_get_contents($f)));
    touch($f, time() - 20);                             // en otro segundo que la escritura (ver la documentación)
    $antes = $rev();
    leer();                                             // la lee, ve que no es lo que escribió el motor y reescribe la tabla
    $r = (new Database('b', $raiz))->consultar("SELECT id FROM t WHERE ciudad = 'C9'");
    return $tocada && $rev() > $antes && $r === [['id' => 1200]] ?: json_encode([$tocada, $antes, $rev(), $r]);
});

chk('la huella de una parte que no se vuelve a escribir sigue en la caché aunque otras escrituras la barran: su edición a mano se ve (2.9)', function () {
    global $raiz;
    $d = nueva();
    $bd = new Database('b', $raiz);
    $f = "$d/t.part2.json";
    $bd->consultar("UPDATE t SET nombre = 'M1100' WHERE id = 1100");        // la segunda parte, con su huella
    for ($i = 0; $i < 33; $i++) {                                         // la primera, hasta pasar por un barrido (cada 32)
        $bd->consultar("UPDATE t SET nombre = 'M$i' WHERE id = 5");
    }
    file_put_contents($f, str_replace('{"id":1200,"nombre":"N1200","ciudad":"C3"}', '{"id":1200,"nombre":"N1200","ciudad":"C9"}', (string)file_get_contents($f)));
    touch($f, time() - 20);
    leer();
    $r = (new Database('b', $raiz))->consultar("SELECT id FROM t WHERE ciudad = 'C9'");
    return $r === [['id' => 1200]] ?: json_encode($r);
});

chk('con la caché de resultados, la consulta que lee la parte editada reescribe la tabla y retira los resultados guardados de ella', function () {
    global $raiz;
    $d = nueva();
    $f = "$d/t.part2.json";
    $consulta = static function (string $sql) use ($raiz): string {
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
                . ' echo json_encode((new JsonSQLDB\\Database("b", ' . var_export($raiz, true) . '))->consultar(' . var_export($sql, true) . '));';
        return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    };
    $suma = "SELECT COUNT(*) AS n, SUM(id) AS s FROM t WHERE ciudad <> 'C9'";
    $r = [$consulta($suma), count(glob("$d/.cache/q.*.cache") ?: [])];    // guardada
    file_put_contents($f, str_replace('{"id":1200,"nombre":"N1200","ciudad":"C3"}', '{"id":1200,"nombre":"N1200","ciudad":"C9"}', (string)file_get_contents($f)));
    $r[] = $consulta("SELECT id FROM t WHERE nombre = 'N1200'");               // lee la parte: ve la edición
    $r[] = $consulta($suma);
    $r[] = $consulta("SELECT id FROM t WHERE ciudad = 'C9'");
    return $r === ['[{"n":1500,"s":1125750}]', 1, '[{"id":1200}]', '[{"n":1499,"s":1124550}]', '[{"id":1200}]'] ?: json_encode($r);
});

echo "\n== Lo dudoso: error con la línea, sin tocar nada, y REPAIR TABLES ==\n";
chk('una fila con una comilla borrada da error con el fichero y la línea, y el fichero no se toca', function () {
    $d = nueva();
    $f = "$d/t.part2.json";
    file_put_contents($f, str_replace('{"id":1200,"nombre":"N1200"', '{"id":1200,"nombre":"N1200', (string)file_get_contents($f)));
    $roto = (string)file_get_contents($f);
    try {
        leer();
        return 'no dio error';
    } catch (JsonSQLDB\JsonSqlDbError $e) {
        return str_contains($e->getMessage(), 't.part2.json') && str_contains($e->getMessage(), 'línea 203')
            && file_get_contents($f) === $roto && !is_dir("$d/.reparados") ?: $e->getMessage();
    }
});
chk('CHECK TABLES lo cuenta; REPAIR TABLES aparta la fila, guarda el original y la tabla vuelve a funcionar', function () use ($valida) {
    global $raiz;
    $d = "$raiz/b";
    $check = (new Database('b', $raiz))->consultar('CHECK TABLES');
    $repair = (new Database('b', $raiz))->consultar('REPAIR TABLES');
    $r = leer();
    $copias = glob("$d/.reparados/t.part2.json.*") ?: [];
    $apartada = $repair['apartadas'][0] ?? [];
    return count($check) === 1 && str_contains($check[0]['problema'], 'línea 203') && ($repair['filas'] ?? 0) === 1
        && count($repair['apartadas'] ?? []) === 1 && ($apartada['fichero'] ?? '') === 't.part2.json'
        && str_contains($apartada['fila'] ?? '', 'línea 203') && str_contains($apartada['fila'] ?? '', '"N1200,')
        && $r[0] === ['n' => 1499, 's' => 1125750 - 1200] && $valida("$d/t.part2.json") && count($copias) === 1
        && str_contains((string)file_get_contents($copias[0]), '"N1200,') ?: json_encode([$check, $repair, $r, $copias]);
});
chk('la fila apartada sigue pendiente en CHECK TABLES, con su número, hasta resolverla', function () {
    global $raiz;
    $c = (new Database('b', $raiz))->consultar('CHECK TABLES');
    return count($c) === 1 && $c[0]['apartada'] === 1 && $c[0]['arreglo'] === JsonSQLDB\Storage::PENDIENTE
        && str_contains($c[0]['problema'], '"N1200,') ?: json_encode($c, JSON_UNESCAPED_UNICODE);
});
chk('corregida, se vuelve a meter con el INSERT y REPAIR TABLES FORGET en un lote; si el INSERT no vale, sigue pendiente', function () {
    global $raiz;
    $bd = new Database('b', $raiz);
    try {
        $bd->lote([['INSERT INTO t (id, nombre, ciudad) VALUES (?, ?, ?)', [1, 'repetido', 'C3']], 'REPAIR TABLES FORGET 1']);
        return 'el id repetido no dio error';
    } catch (JsonSQLDB\JsonSqlDbError) {
    }
    $sigue = count($bd->consultar('CHECK TABLES')) === 1;
    $bd->lote([['INSERT INTO t (id, nombre, ciudad) VALUES (?, ?, ?)', [1200, 'N1200', 'C3']], 'REPAIR TABLES FORGET 1']);
    $fila = $bd->consultar('SELECT nombre, ciudad FROM t WHERE id = 1200');
    try {
        $bd->consultar('REPAIR TABLES FORGET 1');
        $otra = 'olvidó dos veces la misma';
    } catch (JsonSQLDB\JsonSqlDbError $e) {
        $otra = str_contains($e->getMessage(), 'No hay ninguna fila apartada');
    }
    return $sigue && $fila === [['nombre' => 'N1200', 'ciudad' => 'C3']] && $bd->consultar('CHECK TABLES') === [] && $otra === true
        ?: json_encode([$sigue, $fila, $otra]);
});

chk('el número de una fila apartada no se repite nunca, aunque ya no quede ninguna pendiente', function () {
    global $raiz;
    $f = "$raiz/b/t.part2.json";                         // la base de las pruebas de arriba: la 1 ya se resolvió
    file_put_contents($f, str_replace('{"id":1300,"nombre":"N1300"', '{"id":1300,"nombre":"N1300', (string)file_get_contents($f)));
    $r = (new Database('b', $raiz))->consultar('REPAIR TABLES');
    return ($r['apartadas'][0]['id'] ?? null) === 2 ?: json_encode($r['apartadas'] ?? $r);
});
chk('REPAIR TABLES FORGET en una transacción vale al confirmarla: deshecha, la fila sigue pendiente; REPAIR TABLES no se hace dentro (2.9)', function () {
    global $raiz;
    $bd = new Database('b', $raiz);
    $pendientes = static fn(): array => array_column((new Database('b', $raiz))->consultar('CHECK TABLES'), 'apartada');
    try {
        $bd->lote(['REPAIR TABLES FORGET 2', ['INSERT INTO t (id, nombre, ciudad) VALUES (?, ?, ?)', [1, 'repetido', 'C3']]]);
        $mal = 'pasó';
    } catch (JsonSQLDB\JsonSqlDbError $e) {
        $mal = $e->sqlState;
    }
    $deshecha = $pendientes();
    $dentro = $bd->transaccion(static function (Database $bd): array {
        $bd->consultar('REPAIR TABLES FORGET 2');
        $r = [array_column($bd->consultar('CHECK TABLES'), 'apartada')];
        foreach (['REPAIR TABLES FORGET 2', 'REPAIR TABLES'] as $q) {
            try { $bd->consultar($q); $r[] = 'pasó'; } catch (JsonSQLDB\JsonSqlDbError $e) { $r[] = $e->sqlState; }
        }
        return $r;
    });
    $confirmada = $pendientes();
    return $mal === 'CONSTRAINT' && $deshecha === [2] && $dentro === [[], 'SCHEMA', 'LOCK'] && $confirmada === []
        ?: json_encode([$mal, $deshecha, $dentro, $confirmada]);
});

chk('si la reparación no puede hacerse (otra parte tiene una fila dudosa), lo leído roto no queda en la caché: se repara al arreglar la otra', function () use ($valida) {
    $d = nueva();
    $f1 = "$d/t.json";
    $f2 = "$d/t.part2.json";
    file_put_contents($f1, substr((string)file_get_contents($f1), 0, -2));               // se arregla sola
    $bien2 = (string)file_get_contents($f2);
    $fecha2 = (int)filemtime($f2);
    file_put_contents($f2, str_replace('{"id":1200,"nombre":"N1200"', '{"id":1200,"nombre":"N1200', $bien2));
    try { leer(); } catch (JsonSQLDB\JsonSqlDbError) { }
    $sigueRota = !$valida($f1);                           // la reparación no se ha hecho: falta la otra
    // Alguien deja la otra exactamente como estaba (contenido y fecha): así
    // no hay ninguna edición nueva que vuelva a leer la tabla entera
    file_put_contents($f2, $bien2);
    touch($f2, $fecha2);
    leer();
    return $sigueRota && $valida($f1) && count(glob("$d/.reparados/t.json.*") ?: []) === 1 ?: json_encode([$sigueRota, $valida($f1)]);
});

echo "\n== El camino normal no cambia ==\n";
chk('copiar o tocar los ficheros sin cambiar su contenido (una copia de seguridad repuesta, un touch) no reescribe nada', function () {
    $d = nueva();
    $antes = [md5_file("$d/t.rev.json"), md5_file("$d/t.json")];
    foreach (glob("$d/t*.json") ?: [] as $f) {
        $c = (string)file_get_contents($f);
        unlink($f);
        file_put_contents($f, $c);                        // otro inodo, otra fecha, el mismo contenido
    }
    leer();
    return $antes === [md5_file("$d/t.rev.json"), md5_file("$d/t.json")] && !is_dir("$d/.reparados") ?: 'se reescribió la tabla';
});
chk('leer sin nada roto no escribe nada ni crea .reparados', function () {
    $d = nueva();
    $antes = [md5_file("$d/t.rev.json"), md5_file("$d/t.json"), md5_file("$d/t.part2.json")];
    for ($i = 0; $i < 3; $i++) {
        leer();
    }
    return $antes === [md5_file("$d/t.rev.json"), md5_file("$d/t.json"), md5_file("$d/t.part2.json")] && !is_dir("$d/.reparados")
        ?: 'cambió algo';
});

echo "\n== Huecos, letras, la marca de UTF-8, vistas y partes que faltan (2.9) ==\n";
chk('con huecos de filas borradas y su rev.json perdido, la consulta sigue bien (antes, un error de tipos)', function () {
    $d = nueva();
    $bd = new Database('b', dirname($d));
    $bd->consultar('DELETE FROM t WHERE id IN (5, 1200)');
    unlink("$d/t.rev.json");
    $r = leer();
    $bd = new Database('b', dirname($d));
    $primeras = array_column($bd->consultar('SELECT * FROM t ORDER BY nombre LIMIT 2'), 'id');
    $n = $bd->consultar("UPDATE t SET ciudad = 'z' WHERE id = 6")['filas'];
    return $r[0] === ['n' => 1498, 's' => 1125750 - 1205] && $primeras === [1, 10] && $n === 1 ?: json_encode([$r, $primeras, $n]);
});
chk('una parte con huecos y el JSON roto se repara sola, con los huecos en su sitio', function () use ($valida) {
    $d = nueva();
    $bd = new Database('b', dirname($d));
    $bd->consultar('DELETE FROM t WHERE id IN (5, 7)');
    $f = "$d/t.json";
    file_put_contents($f, str_replace('{"id":6,"nombre":"N6","ciudad":"C6"},', '{"id":6,"nombre":"N6","ciudad":"C6"},,', (string)file_get_contents($f)));
    $r = leer();
    $bd = new Database('b', dirname($d));
    $despues = $bd->consultar('SELECT id FROM t WHERE id BETWEEN 4 AND 8 ORDER BY id');
    return $r[0] === ['n' => 1498, 's' => 1125750 - 12] && $valida($f) && array_column($despues, 'id') === [4, 6, 8]
        && !is_file("$d/.reparados/apartadas.json") ?: json_encode([$r, $despues]);
});
chk('una fila con «Å» (su UTF-8 lleva el byte 0x85) en una parte rota se repara entera, sin partirla', function () use ($valida) {
    $d = nueva();
    $bd = new Database('b', dirname($d));
    $bd->consultar("UPDATE t SET nombre = 'Åsa Ångström' WHERE id = 3");
    $f = "$d/t.json";
    file_put_contents($f, str_replace('{"id":4,"nombre":"N4","ciudad":"C4"},', '{"id":4,"nombre":"N4","ciudad":"C4"},,', (string)file_get_contents($f)));
    $r = leer();
    $bd = new Database('b', dirname($d));
    $n = $bd->consultar('SELECT nombre FROM t WHERE id = 3')[0]['nombre'] ?? null;
    return $r[0]['n'] === 1500 && $n === 'Åsa Ångström' && $valida($f) ?: json_encode([$r, $n], JSON_UNESCAPED_UNICODE);
});
chk('la marca de UTF-8 que pone un editor al principio de una parte o de la estructura se quita sola', function () use ($valida) {
    $d = nueva();
    foreach (["$d/t.json", "$d/t.meta.json"] as $f) {
        file_put_contents($f, "\xEF\xBB\xBF" . file_get_contents($f));
    }
    $r = leer();
    return $r[0] === ['n' => 1500, 's' => 1125750] && strncmp((string)file_get_contents("$d/t.json"), "\xEF\xBB\xBF", 3) !== 0
        && $valida("$d/t.meta.json") && strncmp((string)file_get_contents("$d/t.meta.json"), "\xEF\xBB\xBF", 3) !== 0 ?: json_encode($r);
});
chk('_views.json con una coma de más sigue dando sus vistas; cortado, da error y CREATE VIEW no lo sobrescribe', function () {
    $d = nueva();
    $bd = new Database('b', dirname($d));
    $bd->consultar('CREATE VIEW v1 AS SELECT id FROM t WHERE id < 3');
    $f = "$d/_views.json";
    $bueno = (string)file_get_contents($f);
    file_put_contents($f, preg_replace('/\}\s*\}\s*$/', '},}', $bueno));
    $con = array_column((new Database('b', dirname($d)))->consultar('SELECT * FROM v1'), 'id');
    file_put_contents($f, substr($bueno, 0, 30));
    $errores = [];
    foreach (['SELECT * FROM v1', 'CREATE VIEW v2 AS SELECT 1'] as $q) {
        try { (new Database('b', dirname($d)))->consultar($q); $errores[] = 'pasó'; } catch (Throwable $e) { $errores[] = str_contains($e->getMessage(), '_views.json'); }
    }
    return $con === [1, 2] && $errores === [true, true] && (string)file_get_contents($f) === substr($bueno, 0, 30) ?: json_encode([$con, $errores]);
});
chk('una parte de en medio borrada da error en vez de acortar la tabla, y CHECK TABLES la nombra', function () {
    $d = nueva();
    $bd = new Database('b', dirname($d));
    $bd->consultar('INSERT INTO t SELECT id + 1500, nombre, ciudad FROM t WHERE id <= 1000');      // tres partes
    unlink("$d/t.part2.json");
    $mal = [];
    foreach (["SELECT COUNT(*) AS n FROM t WHERE nombre <> 'x'", 'SELECT COUNT(*) AS n FROM t', 'REPAIR TABLES FROM t'] as $q) {
        try { (new Database('b', dirname($d)))->consultar($q); $mal[] = "$q pasó"; } catch (Throwable $e) { if (!str_contains($e->getMessage(), 't.part2.json')) { $mal[] = $e->getMessage(); } }
    }
    $check = (new Database('b', dirname($d)))->consultar('CHECK TABLES FROM t');
    return $mal === [] && in_array('falta el fichero', array_column($check, 'problema'), true) ?: json_encode([$mal, $check], JSON_UNESCAPED_UNICODE);
});
chk('el estado que dejaba la 2.8 al vaciar la última parte (su revisión en la lista, sin fichero) se lee bien y CHECK TABLES no ve nada (2.9)', function () {
    $d = nueva();
    (new Database('b', dirname($d)))->consultar('DELETE FROM t WHERE id > 1000');          // de dos partes a una
    $e = json_decode((string)file_get_contents("$d/t.rev.json"), true);
    $e['parts'][] = $e['rev'];
    file_put_contents("$d/t.rev.json", json_encode($e, JSON_PRETTY_PRINT) . "\n");
    touch("$d/t.rev.json", time() - 60);
    try { $leido = leer(); } catch (Throwable $x) { $leido = $x->getMessage(); }
    $check = (new Database('b', dirname($d)))->consultar('CHECK TABLES FROM t');
    return $leido === [['n' => 1000, 's' => 500500], 0] && $check === [] ?: json_encode([$leido, $check], JSON_UNESCAPED_UNICODE);
});

echo "\n== Una tabla que no cabe en la memoria (2.9) ==\n";
chk('la reparación sola y REPAIR TABLES reescriben una tabla más grande que la memoria una parte a la vez (antes la juntaban entera y morían sin memoria)', function () use ($valida) {
    global $raiz;
    exec('rm -rf ' . escapeshellarg("$raiz/g"));
    Database::crear('g', $raiz);
    $bd = new Database('g', $raiz);
    $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, nombre TEXT, ciudad TEXT)');
    $bd->consultar('CREATE INDEX ix_ciudad ON t (ciudad)');
    for ($b = 0; $b < 60; $b++) {
        $bd->consultar('INSERT INTO t VALUES ' . implode(',', array_map(static fn(int $i): string => "($i, 'N$i " . str_repeat('x', 100) . "', 'C" . ($i % 7) . "')",
            range($b * 1000 + 1, $b * 1000 + 1000))));
    }
    unset($bd);
    $f = "$raiz/g/t.part3.json";
    $correr = static function (string $sql) use ($raiz): string {
        $codigo = 'define("JSONSQLDB_CONEXION_DIRECTA", true); require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . ';'
                . ' echo json_encode((new JsonSQLDB\Database("g", ' . var_export($raiz, true) . '))->consultar(' . var_export($sql, true) . '));';
        return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=24M -r ' . escapeshellarg($codigo) . ' 2>&1'));
    };
    $r = [];
    // Sin su llave final, que se arregla sola: la lectura la repara al acabar
    file_put_contents($f, substr((string)file_get_contents($f), 0, -2));
    $r[] = $correr("SELECT COUNT(*) AS n FROM t WHERE nombre <> 'x'");
    $r[] = $valida($f);
    file_put_contents($f, substr((string)file_get_contents($f), 0, -2));
    $r[] = json_decode($correr('REPAIR TABLES'), true)['filas'] ?? null;
    $r[] = $valida($f);
    $r[] = $correr("SELECT COUNT(*) AS n FROM t WHERE ciudad = 'C3'");
    $copias = count(glob("$raiz/g/.reparados/t.part3.json.*") ?: []);
    exec('rm -rf ' . escapeshellarg("$raiz/g"));
    return $r === ['[{"n":60000}]', true, 1, true, '[{"n":8572}]'] && $copias >= 1 ?: json_encode([$r, $copias]);
});

exec('rm -rf ' . escapeshellarg($raiz));
echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
