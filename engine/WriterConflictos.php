<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Una fila nueva que choca con una clave primaria o única: OR REPLACE, OR
 * IGNORE, REPLACE INTO, ON CONFLICT … DO UPDATE y ON DUPLICATE KEY UPDATE.
 * Métodos de Writer que reciben el ejecutor en $w. Van aparte para que un
 * INSERT corriente no tenga que compilarlos (2.9): sin OPcache, PHP compila
 * en cada petición lo que carga. Hereda de Writer solo para llegar a lo
 * protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class WriterConflictos extends Writer
{
    private function __construct()
    {
    }

    /**
     * ON CONFLICT … DO UPDATE: cambia la fila con la que choca. En SET y en
     * WHERE, las columnas son las de esa fila y excluded.col las de la que se
     * quería insertar. Pasa por todo lo de un UPDATE (tipos, triggers,
     * claves únicas y foráneas). False si el WHERE la deja como está.
     */
    public static function actualizarConflicto(Writer $w, string $tabla, array $meta, int $pos, array $propuesta, array $conflicto, array &$indices, bool $devolver): bool
    {
        $mapa = $w->mapaColumnas($tabla, $meta);
        foreach ($meta['columns'] as $c) {
            $mapa['excluded.' . strtolower($c['name'])] = 'excluded.' . $c['name'];
            $mapa["\0a:excluded." . $c['name']]        = Types::afinidad((string)$c['type']);   // 2.10
        }
        $vieja = self::filaEn($w, $tabla, $pos);
        $fila  = $vieja;
        foreach ($meta['columns'] as $c) {
            $fila['excluded.' . $c['name']] = $propuesta[$c['name']] ?? null;
        }
        $sub = $w->subconsultas($mapa);
        $ctx = ['fila' => $fila, 'sub' => $sub, 'conjunto' => $w->conjuntos($mapa)];
        if ($conflicto['where'] !== null
            && Valor::verdadero(Evaluator::evaluar(Evaluator::resolver($conflicto['where'], $mapa), $ctx)) !== true) {
            return false;
        }
        $nueva = $vieja;
        foreach ($conflicto['set'] as $s) {
            $col = Catalog::columna($meta, $s['col']);
            if ($col === null) {
                throw JsonSqlDbError::schema("La columna '{$s['col']}' no existe en '$tabla'");
            }
            $nueva[$col['name']] = Evaluator::evaluar(Evaluator::resolver($s['expr'], $mapa), $ctx);
        }
        $nueva = $w->prepararFila($tabla, $meta, $nueva, false);
        $preparada = $nueva;
        $nueva = $w->lanzarTriggers($tabla, 'BEFORE', 'UPDATE', $nueva, $vieja);
        if ($nueva !== $preparada) {
            $nueva = $w->prepararFila($tabla, $meta, $nueva, false);
        }
        if (!self::sigue($w, $tabla, $pos)) {
            return true;                          // la ha borrado su BEFORE
        }
        $w->comprobarUnicos($tabla, $meta, $nueva, $indices, $vieja);
        $w->comprobarForaneas($tabla, $meta, $nueva);
        // Escrita antes de sus cascadas, como en un UPDATE (ver actualizar())
        $w->ponerEn($tabla, $pos, $nueva, $vieja);
        $w->cambiarEnIndices($meta, $vieja, $nueva, $indices);
        self::apuntarUnicas($w, $tabla, $meta, $pos, $vieja, $nueva);
        $w->propagarHijos($tabla, $meta, $vieja, $nueva);
        if ($devolver) {
            $w->devueltas[] = $nueva;
        }
        $w->lanzarTriggers($tabla, 'AFTER', 'UPDATE', $nueva, $vieja);
        return true;
    }

    /**
     * OR REPLACE en un INSERT o un UPDATE (2.10): un NULL en una columna NOT
     * NULL toma su valor por defecto, como en SQLite. Sin él sigue siendo
     * NULL, y un error al comprobar la fila.
     */
    public static function nulosPorDefecto(array $meta, array $fila): array
    {
        foreach ($meta['columns'] as $c) {
            if ($c['notnull'] && ($fila[$c['name']] ?? null) === null) {
                $fila[$c['name']] = self::porDefecto($c);
            }
        }
        return $fila;
    }

    /**
     * OR REPLACE: borra las filas con las que $nueva choca en alguna clave
     * única, con sus acciones de clave foránea y sin triggers (como SQLite con
     * recursive_triggers desactivado). En un UPDATE (2.10), $pos y $vieja son
     * la fila que cambia, que no choca consigo misma.
     *
     * @return list<int> las posiciones que ha borrado
     */
    public static function quitarChoques(Writer $w, string $tabla, array $meta, ?int $pos, ?array $vieja, array $nueva, array &$indices): array
    {
        $quitadas = [];
        foreach ($w->unicos($meta) as $uq) {
            $clave = self::claveDe($nueva, $uq['columns'], $uq['plegar']);
            if ($clave === null || ($vieja !== null && $clave === self::claveDe($vieja, $uq['columns'], $uq['plegar']))) {
                continue;
            }
            while (($choca = self::chocaCon($w, $tabla, $meta, $nueva, $uq['name'])) !== null && $choca !== $pos) {
                $otra = self::filaEn($w, $tabla, $choca);
                $w->propagarHijos($tabla, $meta, $otra, null);
                $w->ponerEn($tabla, $choca, null, $otra);
                WriterForaneas::padreQuitado($w, $tabla, $meta, $otra);
                WriterCambios::quitarDeIndices($w, $meta, $otra, $indices);
                self::apuntarUnicas($w, $tabla, $meta, $choca, $otra, null);
                $quitadas[] = $choca;
            }
        }
        return $quitadas;
    }

    /** El conjunto único que nombra un ON CONFLICT (cols); error si no hay ninguno con esas columnas, como en SQLite. */
    public static function claveObjetivo(Writer $w, string $tabla, array $meta, array $cols): string
    {
        $buscadas = array_map('strtolower', $cols);
        sort($buscadas);
        foreach ($w->unicos($meta) as $uq) {
            $suyas = array_map('strtolower', $uq['columns']);
            sort($suyas);
            if ($suyas === $buscadas) {
                return $uq['name'];
            }
        }
        throw JsonSqlDbError::constraint("ON CONFLICT (" . implode(', ', $cols) . ") no corresponde a ninguna clave primaria ni restricción UNIQUE de '$tabla'");
    }

    /**
     * Posición de la fila con la que choca $nueva en la clave única $soloEsa,
     * o en cualquiera si es null; null si no choca. Los mapas clave →
     * posición se arman la primera vez y se mantienen con apuntarUnicas().
     */
    public static function chocaCon(Writer $w, string $tabla, array $meta, array $nueva, ?string $soloEsa): ?int
    {
        foreach ($w->unicos($meta) as $uq) {
            $n = $uq['name'];
            if ($soloEsa !== null && $n !== $soloEsa) {
                continue;
            }
            $clave = self::claveDe($nueva, $uq['columns'], $uq['plegar']);
            if ($clave === null) {
                continue;                               // con algún NULL no hay choque
            }
            if (!isset($w->datos[$tabla])) {
                // Sin cargar la tabla: lo que ha tocado la sentencia, y si no, el índice
                $pos = \array_key_exists($clave, $w->unicas[$tabla][$n] ?? [])
                    ? $w->unicas[$tabla][$n][$clave] : self::chocaEnDisco($w, $tabla, $meta, $uq, $clave);
                if (\is_int($pos)) {
                    return $pos;
                }
                if (!isset($w->datos[$tabla])) {
                    continue;                           // libre (si el índice no sirvió, ya está cargada: abajo)
                }
            }
            $pos = $w->unicas[$tabla][$n][$clave] ?? null;
            // Una cascada de la propia sentencia puede haber borrado esa fila
            // sin pasar por aquí (2.9: se usaba igual, y una fila que ya no
            // estaba era un error de PHP): entonces el mapa se vuelve a armar
            if (!isset($w->unicas[$tabla][$n])
                || ($pos !== null && self::claveDe($w->datos[$tabla][$pos] ?? [], $uq['columns'], $uq['plegar']) !== $clave)) {
                $mapa = [];
                foreach ($w->datos[$tabla] as $p => $f) {
                    $k = self::claveDe($f, $uq['columns'], $uq['plegar']);
                    if ($k !== null) {
                        $mapa[$k] = $p;
                    }
                }
                $w->unicas[$tabla][$n] = $mapa;
                $pos = $mapa[$clave] ?? null;
            }
            if ($pos !== null) {
                return $pos;
            }
        }
        return null;
    }

    /**
     * La posición de la fila de la tabla en disco con esa clave única, como
     * está ahora en la sentencia; null si no hay ninguna. Si el índice no sirve, se carga la
     * tabla y null: quien llama sigue con ella en memoria.
     */
    private static function chocaEnDisco(Writer $w, string $tabla, array $meta, array $uq, string $clave): ?int
    {
        // indiceDe() repasa las revisiones de todos los trozos: una vez, no en cada fila
        $n          = $uq['name'];
        $def        = $w->defUnica[$tabla][$n] ??= $w->indiceDe($tabla, $meta, $uq['columns'], true);
        $st         = $w->cat->storage();
        if ($def !== null && ($w->vacioUnica[$tabla][$n] ??= $w->vacioDesde($tabla, $def)) === $w->volcados) {
            return null;                                // el índice está vacío (ver vacioDesde())
        }
        // Como la comprobación de unicidad (ver claveOcupada()): una búsqueda de
        // texto por trozo para unas pocas filas, y los trozos decodificados para
        // muchas. posicionesPorIndice(), hecha para una consulta, repasaba todos
        // los trozos en cada fila: 10 veces más lento que cargar la tabla
        $posiciones = $def === null ? null : StorageEscritura::posicionesDeClave($st, $tabla, $def, $clave);
        if ($posiciones === null) {
            $w->filas($tabla);                   // y sus claves se arman de ella (ver filas())
            return null;
        }
        // La fila no hace falta leerla: si la sentencia hubiera cambiado su
        // clave, o la hubiera borrado, $unicas lo diría y no se habría
        // llegado aquí (ver apuntarUnicas()); y si la hubiera borrado de otra
        // forma, está en $borradas. Leerla para comprobarlo era la mitad de lo
        // que costaba un INSERT OR IGNORE de muchas filas (2.9)
        foreach ($posiciones as $pos) {
            if (!isset($w->borradas[$tabla][$pos])) {
                return $pos;
            }
        }
        return null;
    }

    /** La fila de una posición como está en la sentencia; negativa, una que mete ella (ver $unicas). */
    protected static function filaEn(Writer $w, string $tabla, int $pos): array
    {
        if ($pos < 0) {
            return $w->anexo[$tabla][-1 - $pos];
        }
        if (isset($w->datos[$tabla])) {
            return $w->datos[$tabla][$pos];
        }
        if (isset($w->cambios[$tabla][$pos])) {
            return $w->cambios[$tabla][$pos];
        }
        foreach (StorageCambios::filasEnPosiciones($w->cat->storage(), $tabla, [$pos]) as $fila) {
            return $fila;
        }
        throw JsonSqlDbError::io("La fila $pos de '$tabla' no está en el disco");
    }

    /** ¿Sigue estando la fila de esa posición (ver filaEn())? */
    private static function sigue(Writer $w, string $tabla, int $pos): bool
    {
        if (isset($w->datos[$tabla])) {
            return isset($w->datos[$tabla][$pos]);
        }
        return $pos < 0 ? isset($w->anexo[$tabla][-1 - $pos]) : !isset($w->borradas[$tabla][$pos]);
    }

    /** Una fila que entra, cambia o sale: los mapas de chocaCon() al día. */
    public static function apuntarUnicas(Writer $w, string $tabla, array $meta, int $pos, ?array $vieja, ?array $nueva): void
    {
        $parcial = !isset($w->datos[$tabla]);
        foreach ($w->unicos($meta) as $uq) {
            $n = $uq['name'];
            if (!$parcial && !isset($w->unicas[$tabla][$n])) {
                continue;                               // se armará leyendo la tabla, ya al día
            }
            $kv = $vieja === null ? null : self::claveDe($vieja, $uq['columns'], $uq['plegar']);
            $kn = $nueva === null ? null : self::claveDe($nueva, $uq['columns'], $uq['plegar']);
            if ($kv === $kn) {
                continue;                               // la misma clave en la misma fila: ya está apuntada (2.9)
            }
            if ($kv !== null && ($parcial || ($w->unicas[$tabla][$n][$kv] ?? null) === $pos)) {
                if ($parcial) {
                    $w->unicas[$tabla][$n][$kv] = false;  // libre, aunque el índice del disco la tenga
                } else {
                    unset($w->unicas[$tabla][$n][$kv]);
                }
            }
            if ($kn !== null) {
                $w->unicas[$tabla][$n][$kn] = $pos;
            }
        }
    }
}
