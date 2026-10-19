<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las búsquedas por un índice que no son las de siempre (2.10, aparte de
 * Storage para que una búsqueda por clave no tenga que compilarlas): por las
 * primeras columnas de un índice compuesto cuyas columnas de detrás admiten
 * NULL, que sirve solo en las partes donde no falta ninguna fila, y por
 * prefijo o sin poder leer las claves en el texto del trozo.
 *
 * Métodos de Storage que reciben el almacenamiento en $st.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageBusquedas extends Storage
{
    /**
     * posicionesPorIndice() con un índice que solo sirve en parte (2.10): en
     * los trozos completos —una clave por cada línea de su parte, sin huecos
     * ni filas con NULL que falten—, las posiciones de las claves que empiezan
     * por alguna de las buscadas; de las demás partes, todas. Si los completos
     * no llegan a la mitad, o no lo son la mayoría de los primeros, null:
     * recorrer la tabla cuesta menos. Si un trozo
     * es completo se guarda en la caché con su revisión, y una consulta solo
     * cuenta los que han cambiado desde la anterior: sin eso, un índice donde
     * casi todas las partes tienen algún NULL se leía en cada consulta para
     * acabar recorriendo la tabla.
     *
     * @param list<string> $claves
     * @return list<int>|null ordenadas
     */
    public static function posicionesEnPartesCompletas(Storage $st, string $tabla, array $def, array $claves): ?array
    {
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null || $plan['legado'] !== null || $plan['partes'] === 0) {
            return null;
        }
        $chunk  = max(1, (int)($st->estado($tabla)['chunk'] ?? $st->filasPorParte));
        $revs   = (array)($st->estado($tabla)['indexes'][$def['name']] ?? []);
        $clave  = $st->claveCache($tabla, 'c' . $def['name'], 0);
        $vistos = $st->cacheLeer($clave);
        $vistos = \is_array($vistos) ? $vistos : [];
        $nuevos = false;
        $faltan = 0;                                        // partes donde no sirve
        $completos = [];
        try {
            for ($p = 1; $p <= $plan['partes']; $p++) {
                $rev = (int)($revs[$p - 1] ?? -1);
                if (($vistos[$p][0] ?? null) !== $rev) {
                    $keys = $st->trozoIndice($tabla, $def, $p);
                    if ($keys === null) {
                        return null;
                    }
                    $n = 0;
                    foreach ($keys as $donde) {
                        $n += \is_array($donde) ? \count($donde) : 1;
                    }
                    $vistos[$p] = [$rev, $n === $chunk];
                    $nuevos = true;
                }
                // Se deja en cuanto faltan más de la mitad, o desde dos si
                // faltan en la mayoría de los vistos: un NULL en cada parte no
                // obliga a leer medio índice para acabar recorriendo la tabla
                if ($vistos[$p][1]) {
                    $completos[$p] = true;
                } elseif (++$faltan * 2 > $plan['partes'] || ($faltan >= 2 && $faltan * 2 > $p)) {
                    return null;
                }
            }
        } finally {
            if ($nuevos) {
                $st->cacheGuardar($clave, $vistos);
            }
        }
        $posiciones = [];
        for ($p = 1; $p <= $plan['partes']; $p++) {
            if (!isset($completos[$p])) {
                for ($x = ($p - 1) * $chunk, $fin = $p * $chunk; $x < $fin; $x++) {
                    $posiciones[$x] = true;
                }
                continue;
            }
            $keys = $st->trozoIndice($tabla, $def, $p);
            if ($keys === null) {
                return null;
            }
            foreach ($keys as $k => $lista) {
                foreach ($claves as $c) {
                    if (strncmp((string)$k, $c, \strlen($c)) === 0) {
                        foreach (Indexes::posiciones($lista) as $x) { $posiciones[$x] = true; }
                        break;
                    }
                }
            }
        }
        $posiciones = array_keys($posiciones);
        sort($posiciones);
        return $posiciones;
    }

    /**
     * Los trozos de un índice en los que pueden estar unas claves, o null si
     * el índice no sirve. Con el rango numérico de cada trozo se saltan los
     * que no pueden contener ninguna: en una clave primaria autoincremental
     * es abrir un trozo en vez de todos.
     *
     * @param list<string> $claves  claves buscadas (o sus prefijos)
     * @return list<array<string, int|list<int>>>|null
     */
    public static function trozosParaClaves(Storage $st, string $tabla, array $def, array $claves): ?array
    {
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null) {
            return null;
        }
        if ($plan['legado'] !== null) {
            return [$plan['legado']];
        }
        $valores = array_map([Indexes::class, 'valorNumerico'], $claves);
        $trozos  = [];
        for ($p = 1; $p <= $plan['partes']; $p++) {
            if (!self::cabeEnRango($valores, $plan['rangos'][$p - 1])) {
                continue;
            }
            $keys = $st->trozoIndice($tabla, $def, $p);
            if ($keys === null) {
                return null;
            }
            $trozos[] = $keys;
        }
        return $trozos;
    }

    /**
     * ¿Puede estar alguna clave con estos valores numéricos en un trozo con
     * este rango? Un valor null es una clave de texto, que puede estar en
     * cualquiera. Con rango desconocido (null) sí; con un trozo vacío ([]) no.
     *
     * @param list<int|float|null> $valores los de valorNumerico() de cada clave
     */
    private static function cabeEnRango(array $valores, ?array $rango): bool
    {
        if ($rango === null) {
            return true;
        }
        if ($rango === []) {
            return false;
        }
        foreach ($valores as $v) {
            if ($v === null || ($v >= $rango[0] && $v <= $rango[1])) {
                return true;
            }
        }
        return false;
    }
}
