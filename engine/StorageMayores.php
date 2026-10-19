<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Los mayores valores de un índice numérico, de sus claves y sin leer las
 * filas (2.10). Lo usa el INSERT sin id en una clave primaria entera sin
 * AUTOINCREMENT (ver WriterInsercion::siguienteRowid()), que antes leía con
 * StorageOrden::filasPorOrden() las filas de esas claves: en una tabla sin
 * cargar, decodificar la parte donde está la mayor. Va aparte para que un
 * INSERT con su id no tenga que compilarlo, ni el sin id StorageOrden: sin
 * OPcache, PHP compila en cada petición lo que carga. Métodos de Storage que
 * reciben el almacenamiento en $st. Hereda de Storage solo para llegar a lo
 * protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageMayores extends Storage
{
    private function __construct()
    {
    }

    /**
     * Los $k mayores valores de la primera columna del índice $def, de mayor a
     * menor, uno por clave. Con $k = 1, el mayor de los rangos del estado; si
     * no, los trozos se leen empezando por el de mayor extremo y se para en
     * cuanto el siguiente ya no puede mejorar el k-ésimo. Null si no se puede:
     * algún trozo sin rango numérico o con una clave de texto, o menos de $k
     * valores (los NULL no están en el índice).
     *
     * @return list<int|float>|null
     */
    public static function deIndice(Storage $st, string $tabla, array $def, int $k): ?array
    {
        if (!$st->indices) {
            return null;
        }
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null || $plan['legado'] !== null) {
            return null;
        }
        if ($k === 1) {
            // La mayor, de los rangos que el estado guarda por trozo, sin abrir
            // ningún fichero, como MAX() (ver StorageRangos::extremosIndice()):
            // null si alguno no tiene rango o no hay ningún valor
            return $plan['max'] === null ? null : [$plan['max']];
        }
        $trozos = [];
        foreach ($plan['rangos'] as $i => $r) {
            if ($r === null) {
                return null;
            }
            if ($r !== []) {
                $trozos[$i + 1] = $r;
            }
        }
        uasort($trozos, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
        $nombre = $def['name'];
        $vs = [];
        foreach ($trozos as $p => $r) {
            if (isset($vs[$k - 1]) && $r[1] < $vs[$k - 1]) {
                break;                                  // ni este ni los siguientes lo mejoran
            }
            $yaEstaba = isset($st->indicesMemo[$tabla][$nombre][$p]);
            $keys = $st->trozoIndice($tabla, $def, $p);
            if (!$yaEstaba) {
                unset($st->indicesMemo[$tabla][$nombre][$p]);
            }
            if ($keys === null) {
                return null;
            }
            foreach ($keys as $clave => $_) {
                $v = Indexes::valorNumerico((string)$clave);
                if ($v === null) {
                    return null;
                }
                $vs[] = $v;
            }
            if (isset($vs[$k - 1])) {
                rsort($vs);
                $vs = \array_slice($vs, 0, $k);
            }
            Memoria::comprobar('la lectura del índice');
        }
        return isset($vs[$k - 1]) ? $vs : null;
    }
}
