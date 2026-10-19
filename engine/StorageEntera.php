<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Escribir una tabla entera: la de una sentencia que la cargó en memoria, la
 * de la DDL y la de una reparación (2.10, aparte de StorageEscritura para que
 * un INSERT, un UPDATE o un DELETE que no la cargan no tengan que compilarlo:
 * sin OPcache, PHP compila en cada petición lo que carga). Métodos de Storage
 * que reciben el almacenamiento en $st. Hereda de Storage solo para llegar a
 * lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageEntera extends Storage
{
    private function __construct()
    {
    }

    /**
     * Escribe de una vez todo lo que cambia de una tabla: datos, estructura e
     * índices, con una sola subida de revisión. Van juntos porque el fichero
     * de revisión dice qué índices y qué partes siguen valiendo.
     *
     * $filas o $meta a null significa «esto no cambia». $definiciones son los
     * índices que deben quedar escritos (ver Indexes).
     *
     * Sin $sabeQueCambio se reescribe la tabla entera, que es lo seguro. Con
     * él, $desdePos es la posición a partir de la cual las filas se
     * desplazaron (null si ninguna) y $posSueltas las que cambiaron sin mover
     * a las demás: solo se reescriben las partes afectadas, y los índices se
     * corrigen en vez de rehacerse.
     *
     * Si no hay una escritura abierta con StorageEscritura::txIniciar(), esta se
     * confirma sola.
     *
     * @param list<array>|null $filas
     * @param list<array{name: string, columns: list<string>, auto: bool}> $definiciones
     * @param list<int> $posSueltas
     */
    public static function guardarTabla(
        Storage $st,
        string $tabla,
        ?array $filas,
        ?array $meta,
        array $definiciones = [],
        ?int $desdePos = null,
        array $posSueltas = [],
        bool $sabeQueCambio = false
    ): void {
        StorageEscritura::abrirEscritura($st, $tabla, 'ESCRITURA');
        $estado      = $st->estado($tabla);
        $partesAntes = $st->partes($tabla);
        $filasAntes  = isset($estado['rows']) ? (int)$estado['rows'] : null;

        if ($filas === null) {
            // Solo cambia la estructura: ni partes ni posiciones se mueven. Con
            // otro tamaño de parte que el de las partes que hay, sí: la tabla
            // se reescribe entera con el de ahora (2.9). Antes, las partes que
            // no cabían en la cuenta con el tamaño nuevo se borraban (también
            // en la 2.8: un CREATE INDEX tras cambiar JSONSQLDB_FILAS_POR_PARTE)
            if (($estado['chunk'] ?? null) === $st->filasPorParte) {
                $nFilas = $filasAntes ?? StorageLectura::contarFilas($st, $tabla);
                StorageEscritura::escribirTabla($st, $tabla, $meta, $definiciones, fn(): \Generator => StorageEscritura::enPartes($st->filas($tabla), $st->filasPorParte),
                    [], $nFilas, $filasAntes, null, [], $partesAntes);
                return;
            }
            self::reescribirEntera($st, $tabla, fn(): \Generator => $st->filas($tabla), StorageLectura::contarFilas($st, $tabla), $partesAntes, $definiciones, $meta);
            return;
        }
        $filas  = array_values($filas);
        $nFilas = \count($filas);
        $partes = $filas === [] ? [[]] : array_chunk($filas, $st->filasPorParte);

        // Solo se reescriben las partes que pudieron cambiar: insertar una fila
        // en una tabla de cien partes cambia la última, y rehacer las cien es
        // el grueso del coste. Si el tamaño de parte no es el de antes, los
        // límites se han movido y hay que rehacerlas todas.
        // Con huecos (2.9), las posiciones de lo que llega de memoria ya no son
        // las del disco: la tabla se escribe entera, y sin ellos
        if (!$sabeQueCambio || ($estado['chunk'] ?? null) !== $st->filasPorParte
            || array_sum((array)($estado['huecos'] ?? [])) > 0) {
            StorageEscritura::escribirTabla($st, $tabla, $meta, $definiciones, fn(): \Generator => StorageEscritura::enPartes($filas, $st->filasPorParte), $partes, $nFilas, null, null, [], $partesAntes);
            return;
        }
        $aEscribir = [];
        $sueltas   = [];
        foreach ($posSueltas as $pos) {
            if ($desdePos !== null && $pos >= $desdePos) {
                continue;                          // va con las desplazadas (2.9: con huecos, era otra fila)
            }
            $aEscribir[intdiv((int)$pos, $st->filasPorParte)] = true;
            $sueltas[(int)$pos] = $filas[$pos];
        }
        $primera = $desdePos === null ? \count($partes) : intdiv($desdePos, $st->filasPorParte);
        for ($i = min($primera, $partesAntes); $i < \count($partes); $i++) {
            $aEscribir[$i] = true;                 // desplazadas, o partes que no existían
        }
        $partes = array_intersect_key($partes, $aEscribir);

        // Con qué se puede corregir el índice anterior en vez de rehacerlo:
        // desde dónde se desplazaron las filas (nada, si solo se añadieron al
        // final) y cuáles cambiaron en su sitio
        $desde = $desdePos ?? $nFilas;
        if ($filasAntes === null || $desde > $filasAntes || $sueltas !== [] && $nFilas !== $filasAntes) {
            $desde = null;
        }
        StorageEscritura::escribirTabla($st, $tabla, $meta, $definiciones, fn(): \Generator => StorageEscritura::enPartes($filas, $st->filasPorParte), $partes, $nFilas, $desde,
            $desde === null ? null : \array_slice($filas, $desde), $sueltas, $partesAntes);
    }

    /**
     * Escribe como tabla, renumeradas sin huecos y en partes del tamaño de
     * ahora, las filas que da $quedan, con sus índices rehechos. Se leen
     * una vez, cuando se escriben (ver StorageEscritura::escribirTabla()). La
     * escritura de la tabla ya está abierta.
     *
     * @param callable(): iterable<int, array> $quedan las filas que quedan, en orden
     */
    public static function reescribirEntera(Storage $st, string $tabla, callable $quedan, int $nFilas, int $partesAntes,
                                             array $definiciones, ?array $meta = null): void
    {
        $compactas = static function () use ($quedan): \Generator {
            $n = 0;
            foreach ($quedan() as $fila) {
                yield $n++ => $fila;
            }
        };
        $chunk = $st->filasPorParte;
        StorageEscritura::escribirTabla($st, $tabla, $meta, $definiciones, fn(): iterable => $nFilas === 0 ? [] : StorageEscritura::enPartes($compactas(), $chunk),
            $nFilas === 0 ? [[]] : StorageEscritura::enPartes($compactas(), $chunk), $nFilas, null, null, [], $partesAntes);
    }

    /** Reescribe todas las filas de una tabla. */
    public static function guardarFilas(Storage $st, string $tabla, array $filas, array $definiciones = []): void
    {
        self::guardarTabla($st, $tabla, $filas, null, $definiciones);
    }
}
