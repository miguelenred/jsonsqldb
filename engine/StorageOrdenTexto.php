<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las k primeras filas de un ORDER BY … LIMIT por el índice de una columna de
 * texto (2.10, aparte de StorageOrden para que el mismo atajo por una columna
 * numérica no tenga que compilarlo: sin OPcache, PHP compila en cada petición
 * lo que carga). Métodos de Storage que reciben el almacenamiento en $st.
 * Hereda de Storage solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageOrdenTexto extends Storage
{
    private function __construct()
    {
    }

    /**
     * Como StorageOrden::filasPorOrden() con una columna de texto: las $k primeras en el orden
     * del ORDER BY, que con $binaria es byte a byte y sin él el de la
     * colación configurada, desempatando byte a byte (ver Valor::compararOrden())
     * y, con el mismo texto, por su sitio en la tabla. Los trozos no tienen un
     * rango que diga cuál empieza mejor: se leen todos, que es leer el índice
     * en vez de la tabla, y se va dejando solo lo que aún puede entrar.
     *
     * @return list<array>|null
     */
    public static function filasPorOrdenDeTexto(Storage $st, string $tabla, array $def, bool $desc, int $k, bool $binaria, int $partes): ?array
    {
        $colacion = !$binaria && Collation::activa();
        $nombre   = $def['name'];
        $claves = $valores = $posiciones = [];                // en paralelo: clave de orden, texto, posición
        $orden  = $desc ? SORT_DESC : SORT_ASC;
        $quedarse = static function () use (&$claves, &$valores, &$posiciones, $colacion, $orden, $k): void {
            if ($colacion) {
                array_multisort($claves, $orden, SORT_STRING, $valores, $orden, SORT_STRING, $posiciones, SORT_ASC, SORT_NUMERIC);
            } else {
                array_multisort($valores, $orden, SORT_STRING, $posiciones, SORT_ASC, SORT_NUMERIC);
            }
            $claves     = \array_slice($claves, 0, $k);
            $valores    = \array_slice($valores, 0, $k);
            $posiciones = \array_slice($posiciones, 0, $k);
        };
        for ($p = 1; $p <= $partes; $p++) {
            $yaEstaba = isset($st->indicesMemo[$tabla][$nombre][$p]);
            $keys = $st->trozoIndice($tabla, $def, $p);
            if (!$yaEstaba) {
                unset($st->indicesMemo[$tabla][$nombre][$p]);
            }
            if ($keys === null) {
                return null;
            }
            // El k-ésimo de lo visto hasta ahora: lo que vaya detrás ya no entra,
            // ni lo igual, que está más adelante en la tabla que lo ya visto
            $corte = \count($valores) >= $k ? [$colacion ? end($claves) : null, end($valores)] : null;
            foreach ($keys as $clave => $donde) {
                $clave = (string)$clave;
                if ($clave === '' || $clave[0] !== 't') {
                    return null;
                }
                $sep = (int)strpos($clave, ':');            // Indexes::primerValor(), sin la llamada
                $v = substr($clave, $sep + 1, (int)substr($clave, 1, $sep - 1));
                $c = $colacion ? Collation::clave($v) : '';
                if ($corte !== null) {
                    $cmp = $colacion ? (strcmp($c, (string)$corte[0]) ?: strcmp($v, (string)$corte[1])) : strcmp($v, (string)$corte[1]);
                    if ($desc ? $cmp <= 0 : $cmp >= 0) {
                        continue;
                    }
                }
                foreach ((array)$donde as $x) {
                    $claves[]     = $c;
                    $valores[]    = $v;
                    $posiciones[] = (int)$x;
                }
            }
            if (\count($valores) > $k) {
                $quedarse();
            }
            Memoria::comprobar('la lectura del índice');
        }
        if (\count($valores) < $k) {
            return null;                                    // los NULL no están en el índice
        }
        $quedarse();
        sort($posiciones);
        return StorageLectura::filasDePosiciones($st, $tabla, $posiciones);
    }
}
