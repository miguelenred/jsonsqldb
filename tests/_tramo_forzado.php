<?php
/**
 * Uso interno de tests/f3_escrituras.php (2.10): en la carpeta <raíz>, dos
 * DELETE que se escriben por tramos (sin cargar la tabla que borran) con ON
 * DELETE CASCADE hacia una hija sin índice sobre la clave foránea, que sí se
 * carga. En el segundo, un trigger de la hija hace que se cargue también la
 * tabla del DELETE, que se sigue leyendo del disco parte a parte, y luego
 * borra otra tabla sin cargarla, que escribe por tramos. Los tramos
 * se fuerzan con memory_get_usage() en el espacio de nombres del motor (por
 * eso en un proceso aparte): cada vez que Writer::tramo() mira la memoria, la
 * ve llena. Escribe en JSON, de cada uno, las filas que quedan, las hijas
 * huérfanas, las filas de la otra tabla y si hubo tramos. Y, por último, un
 * UPDATE por tramos cuya cascada da a una hija la clave única que otra tuvo
 * antes del tramo: sus filas.
 */
declare(strict_types=1);

namespace JsonSQLDB {
    function memory_get_usage(bool $real = false): int
    {
        if (!$real && !empty($GLOBALS['forzar'])
            && (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '') === 'tramo') {
            $GLOBALS['tramos']++;
            return \PHP_INT_MAX >> 1;
        }
        return \memory_get_usage($real);
    }
}

namespace {
    ini_set('memory_limit', '256M');            // sin límite no hay tramos
    define('JSONSQLDB_CONEXION_DIRECTA', true);
    require __DIR__ . '/../engine/bootstrap.php';

    use JsonSQLDB\Database;

    $r = [];
    foreach ([false, true] as $conTrigger) {
        $dir = $argv[1] . '/' . (int)$conTrigger;
        @mkdir($dir, 0775, true);
        Database::crear('b', $dir);
        $bd = new Database('b', $dir);
        $bd->consultar('CREATE TABLE p (id INTEGER PRIMARY KEY, a INTEGER)');
        $bd->consultar('CREATE TABLE h (id INTEGER PRIMARY KEY, p INTEGER REFERENCES p(id) ON DELETE CASCADE)');
        $valores = implode(',', array_map(static fn(int $i): string => "($i, $i)", range(1, 3000)));
        $bd->consultar("INSERT INTO p VALUES $valores");
        $bd->consultar("INSERT INTO h VALUES $valores");
        $bd->consultar('CREATE TABLE b (id INTEGER PRIMARY KEY, a INTEGER)');
        $bd->consultar("INSERT INTO b VALUES $valores");
        if ($conTrigger) {
            // Al borrar la hija 5, borra también el padre 6 (y su hija); al borrar la 7, toda la tabla b
            $bd->consultar('CREATE TRIGGER th AFTER DELETE ON h WHEN OLD.id IN (5, 7) BEGIN '
                . 'DELETE FROM p WHERE id = 6 AND OLD.id = 5; DELETE FROM b WHERE OLD.id = 7; END');
        }
        $GLOBALS['tramos'] = 0;
        $GLOBALS['forzar'] = true;
        $bd->consultar('DELETE FROM p WHERE a % 2 = 1');
        $GLOBALS['forzar'] = false;
        $r[] = [
            $bd->consultar('SELECT COUNT(*) AS n FROM p')[0]['n'],
            $bd->consultar('SELECT COUNT(*) AS n FROM h WHERE p % 2 = 0')[0]['n'],
            $bd->consultar('SELECT COUNT(*) AS n FROM h WHERE p NOT IN (SELECT id FROM p)')[0]['n'],
            $bd->consultar('SELECT COUNT(*) AS n FROM b')[0]['n'],
            $GLOBALS['tramos'] > 1,
        ];
        unset($bd);
        Database::borrar('b', $dir);
        @rmdir($dir);
    }
    $dir = $argv[1] . '/claves';
    @mkdir($dir, 0775, true);
    Database::crear('b', $dir);
    $bd = new Database('b', $dir);
    $bd->consultar('CREATE TABLE p (id INTEGER PRIMARY KEY)');
    $bd->consultar('CREATE TABLE c (id INTEGER PRIMARY KEY, a INTEGER REFERENCES p(id) ON UPDATE CASCADE, '
        . 'b INTEGER REFERENCES p(id) ON UPDATE CASCADE, UNIQUE (a, b))');
    $bd->consultar('CREATE INDEX ca ON c (a)');
    $bd->consultar('CREATE INDEX cb ON c (b)');
    $bd->consultar('INSERT INTO p VALUES (1), (2), ' . implode(',', array_map(static fn(int $i): string => "($i)", range(100, 399))) . ', (3)');
    $bd->consultar('INSERT INTO c VALUES (1, 1, 2), (2, 1, 3)');
    $GLOBALS['forzar'] = true;
    try {
        $bd->consultar('UPDATE p SET id = CASE id WHEN 1 THEN 10 WHEN 2 THEN 20 WHEN 3 THEN 2 ELSE id END');
    } catch (JsonSQLDB\JsonSqlDbError $e) {
        $r[] = $e->getMessage();
    }
    $GLOBALS['forzar'] = false;
    $r[] = array_map('array_values', $bd->consultar('SELECT * FROM c ORDER BY id'));
    unset($bd);
    Database::borrar('b', $dir);
    @rmdir($dir);
    @rmdir($argv[1]);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
}
