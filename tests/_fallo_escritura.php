<?php
/**
 * Uso interno de tests/f3_escrituras.php (2.10): en la carpeta <raíz>,
 * transacciones en las que falla una escritura, simulada con fwrite(),
 * rename(), link() y stream_copy_to_stream() en el espacio de nombres del
 * motor (por eso en un proceso aparte, antes de que el motor las use).
 * Escribe en JSON lo que pasa.
 *
 * - escribir: la segunda vista, que ya se escribe en su sitio, deja
 *   _views.json a medias (como con el disco lleno). La transacción ya solo se
 *   puede deshacer, y tampoco se puede leer en ella.
 * - renombrar: la primera, que reemplaza el fichero, no se puede renombrar
 *   (como en Windows con el fichero abierto por otro). El fichero se queda
 *   como estaba, y las siguientes no se escriben en su sitio sobre el
 *   original que la transacción guardó: deshacerla lo devuelve.
 * - deshacer: al deshacerla, un original no se puede devolver (tampoco con
 *   los reintentos de Windows, ver StorageEscritura::reemplazar()). La
 *   conexión no se queda dentro de la transacción: lo siguiente lo devuelve,
 *   y lo que escribe después se queda.
 * - guardar: no se puede guardar el original del último fichero de una
 *   sentencia. Los que sí se guardaron están en el manifiesto de .lote/, que
 *   es lo que los devuelve si el proceso muere.
 * - manifiesto: no se puede escribir el manifiesto de .lote/. Lo de esa
 *   sentencia no cuenta como guardado: la siguiente lo guarda otra vez.
 */
declare(strict_types=1);

namespace JsonSQLDB {
    /** @param resource $h */
    function fwrite($h, string $s): int|false
    {
        $nombre = basename((string)stream_get_meta_data($h)['uri']);
        if (($GLOBALS['fallar'] ?? '') === 'escribir' && str_starts_with($nombre, '_views.json')
            || ($GLOBALS['fallar'] ?? '') === 'manifiesto' && str_starts_with($nombre, 'manifiesto.lote')) {
            return \fwrite($h, substr($s, 0, 10));
        }
        return \fwrite($h, $s);
    }

    function rename(string $de, string $a): bool
    {
        if (($GLOBALS['fallar'] ?? '') === 'deshacer' && str_contains($de, '/.lote/')) {
            return false;
        }
        return ($GLOBALS['fallar'] ?? '') === 'renombrar' && basename($a) === '_views.json' ? false : \rename($de, $a);
    }

    function link(string $de, string $a): bool
    {
        return ($GLOBALS['fallar'] ?? '') === 'guardar' && basename($de) === 't.rev.json' ? false : \link($de, $a);
    }

    /**
     * @param resource $de
     * @param resource $a
     */
    function stream_copy_to_stream($de, $a): int|false
    {
        return ($GLOBALS['fallar'] ?? '') === 'guardar' ? false : \stream_copy_to_stream($de, $a);
    }
}

namespace {
    define('JSONSQLDB_CONEXION_DIRECTA', true);
    require __DIR__ . '/../engine/bootstrap.php';

    use JsonSQLDB\Database;
    use JsonSQLDB\JsonSqlDbError;

    $r = [];
    foreach (['escribir', 'renombrar', 'deshacer', 'guardar', 'manifiesto'] as $fallo) {
        $dir = $argv[1] . '/' . $fallo;
        @mkdir($dir, 0775, true);
        Database::crear('b', $dir);
        $bd = new Database('b', $dir);
        if ($fallo === 'escribir' || $fallo === 'renombrar') {
            $bd->consultar('CREATE VIEW v0 AS SELECT 0 AS a');
            try {
                $bd->transaccion(static function (Database $bd) use (&$r, $fallo): void {
                    $vistas = $fallo === 'escribir' ? ['v1', 'v2'] : ['v1', 'v2', 'v3'];
                    foreach ($vistas as $i => $v) {
                        $GLOBALS['fallar'] = $i === ($fallo === 'escribir' ? 1 : 0) ? $fallo : '';
                        try {
                            $bd->consultar("CREATE VIEW $v AS SELECT 1 AS a");
                        } catch (JsonSqlDbError) {
                            $r[] = "$v: error";
                        }
                    }
                    $GLOBALS['fallar'] = '';
                    if ($fallo === 'renombrar') {
                        throw new RuntimeException('deshacer');
                    }
                    try {
                        $r[] = 'lectura: ' . count($bd->consultar('SHOW VIEWS'));
                    } catch (JsonSqlDbError) {
                        $r[] = 'lectura: error';
                    }
                });
                $r[] = 'confirmada';
            } catch (JsonSqlDbError) {
                $r[] = 'deshecha';
            } catch (RuntimeException) {
                $r[] = 'deshecha a mano';
            }
            $r[] = implode(',', array_column($bd->consultar('SHOW VIEWS'), 'vista'));
        } else {
            $bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT, u TEXT UNIQUE)');
            $bd->consultar("INSERT INTO t VALUES (1, 'antes', 'u1')");
            try {
                $bd->transaccion(static function (Database $bd) use (&$r, $fallo, $dir): void {
                    if ($fallo === 'guardar' || $fallo === 'manifiesto') {
                        $GLOBALS['fallar'] = $fallo;
                        try {
                            $bd->consultar("INSERT INTO t VALUES (2, 'x', 'u2')");
                        } catch (JsonSqlDbError) {
                            $r[] = 'insert: error';
                        }
                        $GLOBALS['fallar'] = '';
                        if ($fallo === 'manifiesto') {
                            $bd->consultar("UPDATE t SET v = 'durante' WHERE id = 1");
                        }
                        $m = json_decode((string)file_get_contents("$dir/b/.lote/manifiesto.lote"), true);
                        $r[] = 'manifiesto: ' . implode(',', $m['guardados'] ?? []);
                    } else {
                        $bd->consultar("UPDATE t SET v = 'durante' WHERE id = 1");
                        $GLOBALS['fallar'] = 'deshacer';
                    }
                    throw new RuntimeException('deshacer');
                });
            } catch (JsonSqlDbError) {
                $r[] = 'deshacer: error';
            } catch (RuntimeException) {
                $r[] = 'deshecha a mano';
            }
            $GLOBALS['fallar'] = '';
            if ($fallo === 'manifiesto') {
                $r[] = $bd->consultar('SELECT v FROM t')[0]['v'];
            }
            if ($fallo === 'deshacer') {
                $r[] = $bd->consultar('SELECT v FROM t')[0]['v'];
                $bd->consultar("INSERT INTO t VALUES (2, 'despues', 'u2')");
                $otra = new Database('b', $dir);
                $r[] = $otra->consultar('SELECT COUNT(*) AS n FROM t')[0]['n'];
                unset($otra);
            }
        }
        unset($bd);
        Database::borrar('b', $dir);
        @rmdir($dir);
    }
    echo json_encode($r);
}
