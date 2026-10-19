<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las lecturas en el orden de un índice (2.10, aparte de StorageLectura para
 * que una búsqueda por clave no tenga que compilarlas): las k primeras filas
 * de un ORDER BY … LIMIT por un índice numérico (las de uno de texto, en
 * StorageOrdenTexto).
 *
 * Métodos de Storage que reciben el almacenamiento en $st.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageOrden extends Storage
{
    /**
     * Las $k primeras filas de la tabla ordenada por la primera columna de un
     * índice numérico (ORDER BY col [DESC] LIMIT k), en el orden de la tabla:
     * en un empate, las de posición más baja, así que ordenarlas y cortar da
     * lo mismo que ordenar la tabla entera. Los trozos se leen empezando por el de
     * mejor extremo (su rango, que ya está en el estado) y se para en cuanto
     * el siguiente ya no puede mejorar el k-ésimo. Null si no se puede: algún
     * trozo sin rango numérico, menos de $k valores (los NULL no están en el
     * índice) o más de media tabla que leer. Con $desde = [n, estricto]
     * (2.10), solo los valores después de n en ese orden (WHERE id > n ORDER
     * BY id LIMIT k): los trozos que no llegan a n ni se leen.
     *
     * @param array{0: int|float, 1: bool}|null $desde
     * @return list<array>|null
     */
    public static function filasPorOrden(Storage $st, string $tabla, array $def, bool $desc, int $k, ?bool $binaria = null, ?array $desde = null): ?array
    {
        if (!$st->indices || $k < 1) {
            return null;
        }
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null || $plan['legado'] !== null) {
            return null;
        }
        if ($binaria !== null) {
            return StorageOrdenTexto::filasPorOrdenDeTexto($st, $tabla, $def, $desc, $k, $binaria, $plan['partes']);
        }
        // ¿Pasa el límite? (con DESC, por debajo)
        $pasa = $desde === null ? null : static fn($v): bool => $desc ? ($desde[1] ? $v < $desde[0] : $v <= $desde[0])
                                                                    : ($desde[1] ? $v > $desde[0] : $v >= $desde[0]);
        $trozos = [];
        $detras = 0.0;                                  // trozos detrás del límite, y la parte de cada uno
        foreach ($plan['rangos'] as $i => $r) {
            if ($r === null) {
                return null;
            }
            if ($r === []) {
                continue;
            }
            if ($desde === null) {
                $trozos[$i + 1] = $r;
                continue;
            }
            // Lo que el trozo tiene detrás del límite, repartido por igual en su
            // rango (sin llamar a $pasa: lo que cuesta se paga aunque no compense)
            $fuera = $desc ? $desde[0] - $r[0] : $r[1] - $desde[0];
            if ($fuera > 0 || ($fuera == 0 && !$desde[1])) {
                $trozos[$i + 1] = $r;
                $detras += $r[1] <= $r[0] ? 1.0 : min(1.0, $fuera / ($r[1] - $r[0]));
            }
        }
        // Con pocas filas detrás del límite, leerlas por el rango cuesta menos
        // que leer su índice y luego sus filas: null, y va por el rango. Medido
        // en 30.000 filas, compensa con más de una parte y de cuatro veces las
        // que se piden (con menos, WHERE id > n ORDER BY id LIMIT k iba hasta
        // un 60 % más lento)
        if ($desde !== null) {
            $chunk = max(1, (int)($st->estado($tabla)['chunk'] ?? 0));
            if ($detras * $chunk <= max(4 * $k, $chunk)) {
                return null;
            }
        }
        uasort($trozos, $desc ? static fn(array $a, array $b): int => $b[1] <=> $a[1]
                              : static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $nombre = $def['name'];
        $vs = $ps = [];                                 // en paralelo: valor y posición
        $corte = null;                                  // el k-ésimo mejor valor visto
        foreach ($trozos as $p => $r) {
            if ($corte !== null && ($desc ? $r[1] < $corte : $r[0] > $corte)) {
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
            // El trozo en el que cae el límite: solo sus claves de después de
            // él (2.10). Aquí y no en el bucle, que no pregunte en cada clave
            if ($pasa !== null && !$pasa($r[$desc ? 1 : 0])) {
                $keys = array_filter($keys, static fn($clave): bool => ($v = Indexes::valorNumerico((string)$clave)) === null || $pasa($v),
                                     ARRAY_FILTER_USE_KEY);
            }
            foreach ($keys as $clave => $donde) {
                $v = Indexes::valorNumerico((string)$clave);
                if ($v === null) {
                    return null;
                }
                if ($corte !== null && ($desc ? $v < $corte : $v > $corte)) {
                    continue;                           // peor que el k-ésimo ya visto (2.10)
                }
                foreach ((array)$donde as $x) {
                    $vs[] = $v;
                    $ps[] = (int)$x;
                }
            }
            // Solo las $k mejores: en un empate, las de posición más baja, que
            // son las que el ORDER BY deja, porque ordena en el orden de la
            // tabla; ninguna de las que salen puede volver, porque lo que
            // llega después tiene que mejorarlas (2.10: se guardaban todas las
            // del valor del corte, y con valores muy repetidos, ORDER BY edad
            // DESC LIMIT 20 leía cientos de filas para quedarse con 20; y con
            // pares [valor, posición] y una función de PHP por cada uno al
            // filtrar, ORDER BY id DESC LIMIT 10 hacía un 1,4 % más que la 2.9)
            if (\count($vs) >= $k) {
                array_multisort($vs, $desc ? SORT_DESC : SORT_ASC, SORT_REGULAR, $ps, SORT_ASC, SORT_NUMERIC);
                $vs = \array_slice($vs, 0, $k);
                $ps = \array_slice($ps, 0, $k);
                $corte = $vs[$k - 1];
            }
            Memoria::comprobar('la lectura del índice');
        }
        if (\count($ps) < $k) {
            return null;
        }
        sort($ps);
        return StorageLectura::filasDePosiciones($st, $tabla, $ps);
    }
}
