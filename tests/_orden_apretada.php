<?php
/**
 * Uso interno de tests/f8_indices.php (2.10): un ORDER BY en disco en el que
 * ordenar de una vez lo juntado de un tramo no cabe. asort(), arsort() y
 * array_multisort() en el espacio de nombres del motor se cortan desde 1.000
 * filas, como Memoria::antesDePedir() en la CI de GitHub con PHP 8.0 y 8.1
 * (por eso en un proceso aparte, antes de que el motor las use).
 *
 * Uso: php _orden_apretada.php <carpeta> '<consulta>'. Escribe las filas, el
 * md5 de todas y los temporales que quedan en .orden/, como la prueba.
 */
declare(strict_types=1);

namespace JsonSQLDB {
    function asort(array &$a, int $modo = SORT_REGULAR): bool
    {
        apretar(\count($a));
        return \asort($a, $modo);
    }

    function arsort(array &$a, int $modo = SORT_REGULAR): bool
    {
        apretar(\count($a));
        return \arsort($a, $modo);
    }

    /**
     * @param array<mixed> $a
     * @param mixed        ...$resto
     */
    function array_multisort(&$a, &...$resto): bool
    {
        apretar(\count($a));
        return \array_multisort($a, ...$resto);
    }

    function apretar(int $filas): void
    {
        if ($filas >= 1000) {
            throw JsonSqlDbError::memoria('Se ha cortado la ordenación (simulado)');
        }
    }
}

namespace {
    define('JSONSQLDB_CONEXION_DIRECTA', true);
    define('JSONSQLDB_CACHE_RESULTADOS', 0);
    define('JSONSQLDB_CACHE_ACTIVA', false);
    define('JSONSQLDB_FILAS_POR_PARTE', 1000);
    require __DIR__ . '/../engine/bootstrap.php';

    [, $dir, $q] = $argv;
    $bd = new JsonSQLDB\Database('e', $dir);
    $h  = hash_init('md5');
    $n  = 0;
    $r  = $bd->consultarPorFilas($q, [], null, static function (array $f) use ($h, &$n): void {
        hash_update($h, serialize($f));
        $n++;
    });
    foreach (is_array($r) ? $r : [] as $f) {                // lo que no salió por el sumidero
        hash_update($h, serialize($f));
        $n++;
    }
    echo $n, ':', hash_final($h), ':', count(glob("$dir/e/.orden/*") ?: []);
}
