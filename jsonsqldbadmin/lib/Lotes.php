<?php
declare(strict_types=1);

/**
 * Filas de un SELECT para exportarlas sin agotar la memoria de PHP.
 *
 * Se mide lo que ocupa una muestra de filas y se calcula cuántas caben a la
 * vez con la memoria que queda libre (memory_limit menos lo ya usado). Si el
 * resultado entero cabe, se pide de una vez; si no, por lotes de ese tamaño
 * (LIMIT … OFFSET …, o según la clave primaria, ver tabla()), y en memoria
 * solo hay un lote.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Lotes
{
    /** Filas de la muestra con la que se mide lo que ocupa cada una. */
    private const MUESTRA = 20;
    /**
     * Veces lo que ocupa el lote como array que se reservan: la respuesta de
     * la API en texto, el texto que se genera para la descarga y, en conexión
     * directa, el trabajo del motor en el mismo proceso.
     */
    private const MARGEN = 6;
    /** Tope por petición aunque sobre memoria: una respuesta enorme tarda y puede cortarse. */
    private const MAX_LOTE = 100000;

    /** Bytes de memory_limit (-1 si no hay límite). */
    public static function limite(): int
    {
        $n = bytesIni('memory_limit');
        return $n > 0 ? $n : -1;
    }

    /** Filas que caben a la vez, sabiendo que $n filas ocupan $bytes. */
    public static function filasPorLote(int $bytes, int $n): int
    {
        $limite = self::limite();
        if ($limite < 0 || $n === 0) {
            return self::MAX_LOTE;
        }
        $porFila = max(64, intdiv($bytes, $n));
        $libre   = max(0, $limite - memory_get_usage());
        // Al menos una fila: con filas muy anchas, de una en una (antes, nunca
        // menos que la muestra, y con filas de cientos de KB no cabía)
        return max(1, min(self::MAX_LOTE, intdiv($libre, $porFila * self::MARGEN)));
    }

    /**
     * Las filas de $sql (un SELECT): un array con todas si se sabe cuántas son
     * ($total) y caben, o un generador que las pide por lotes.
     *
     * @return iterable<int,array<string,mixed>>
     */
    public static function filas(string $base, string $sql, array $params = [], ?int $total = null): iterable
    {
        $sql = rtrim(trim($sql), "; \t\r\n");
        if (Api::directa() && $base !== '') {
            return self::porTemporal($base, $sql, $params);
        }
        if ($total !== null) {
            $muestra = self::pedir($base, $sql, $params, self::MUESTRA, 0);
            if (count($muestra) < self::MUESTRA) {
                return $muestra;                         // ya está entero
            }
            if ($total <= self::filasPorLote(self::ocupa($muestra), count($muestra))) {
                unset($muestra);
                return self::pedir($base, $sql, $params, null, 0);
            }
            unset($muestra);
        }
        return self::porLotes($base, $sql, $params);
    }

    /**
     * Las filas de una tabla para exportarla, con el filtro del panel ($donde)
     * y su orden ($orden, '' sin él). Ordenada por su clave primaria, con la
     * conexión por API (ver porClave()), por lotes según esa clave (2.10):
     * cada uno sigue al anterior por su última clave (WHERE id > ? ORDER BY id
     * LIMIT n), sin ordenar en cada lote todo lo que va detrás, y un INSERT o
     * un DELETE entre dos lotes no repite ni salta filas. Por posición, un
     * ORDER BY id de 300.000 filas en lotes de 5.000 tardaba 44,6 s, y así
     * 2,0. Lo demás, por posición (ver filas()): sin ordenar es más rápido que
     * por clave (se salta las partes enteras), y el panel avisa de que un
     * cambio a la vez puede repetir o saltar filas.
     *
     * @return iterable<int,array<string,mixed>>
     */
    public static function tabla(string $base, string $tabla, string $donde, array $params, string $orden, bool $desc, bool $porClave): iterable
    {
        if ($porClave && !Api::directa()) {
            return self::siguiendoClave($base, $tabla, $orden, $donde, $params, $desc);
        }
        return self::filas($base, 'SELECT * FROM ' . cita($tabla) . $donde
            . ($orden === '' ? '' : ' ORDER BY ' . cita($orden) . ($desc ? ' DESC' : ' ASC')), $params);
    }

    /**
     * ¿Se exporta por lotes según la clave (ver tabla())? Si se ordena por la
     * clave primaria, de una sola columna y numérica: en una de texto, el
     * ORDER BY sigue la colación y el > compara byte a byte, y se saltaban y
     * repetían filas.
     *
     * @param list<array> $columnas las de SHOW SCHEMA
     */
    public static function porClave(array $columnas, string $orden): bool
    {
        $pk = array_values(array_filter($columnas, static fn(array $c): bool => (int)$c['pk'] === 1));
        return count($pk) === 1 && $pk[0]['columna'] === $orden && in_array($pk[0]['tipo'], ['INTEGER', 'DOUBLE', 'DECIMAL'], true);
    }

    /**
     * Los lotes de tabla(): el primero de MUESTRA filas para medir, y los
     * demás del tamaño que cabe, cada uno detrás de la última clave del anterior.
     *
     * @return Generator<int,array<string,mixed>>
     */
    private static function siguiendoClave(string $base, string $tabla, string $clave, string $donde, array $params, bool $desc): Generator
    {
        $orden  = ' ORDER BY ' . cita($clave) . ($desc ? ' DESC' : '');
        // El filtro, entre paréntesis: sus OR no pueden quedar fuera del AND
        $tras   = ' WHERE ' . ($donde === '' ? '' : '(' . (string)preg_replace('/^\s*WHERE\s+/i', '', $donde) . ') AND ')
                . cita($clave) . ($desc ? ' < ?' : ' > ?');
        $lote   = self::MUESTRA;
        $ultima = null;
        do {
            $filas = Api::sql($base, 'SELECT * FROM ' . cita($tabla) . ($ultima === null ? $donde : $tras) . $orden . " LIMIT $lote",
                              $ultima === null ? $params : [...$params, $ultima]);
            $n = count($filas);
            $pedidas = $lote;
            if ($ultima === null) {
                $lote = self::filasPorLote(self::ocupa($filas), $n);
            }
            if ($n > 0) {
                $ultima = $filas[$n - 1][$clave];
            }
            foreach ($filas as $f) {
                yield $f;
            }
            unset($filas);
        } while ($n === $pedidas);
    }

    /**
     * Con la conexión directa (2.9), la consulta una vez: sus filas, según
     * las da el motor, a un temporal, y de ahí según se piden. Por lotes cada
     * uno la repetía entera, y un ORDER BY de toda la tabla con un OFFSET
     * alto, en el mismo proceso que el panel, se cortaba por la memoria.
     *
     * @return Generator<int,array<string,mixed>>
     */
    private static function porTemporal(string $base, string $sql, array $params): Generator
    {
        $f  = ficheroTemporal('lotes');
        $fh = $f === false ? false : @fopen($f, 'w+b');
        if ($fh === false) {
            throw new RuntimeException(t('No se pudo crear un fichero temporal para la exportación.'));
        }
        register_shutdown_function(static fn() => @unlink($f));    // si PHP se corta a medias
        try {
            Api::filasDirectas($base, $sql, $params, static function (array $fila) use ($fh): void {
                $s = serialize($fila);
                fwrite($fh, pack('N', strlen($s)) . $s);
            });
            rewind($fh);
            while (($n = fread($fh, 4)) !== false && strlen($n) === 4) {
                yield unserialize((string)fread($fh, unpack('N', $n)[1]), ['allowed_classes' => false]);
            }
        } finally {
            fclose($fh);
            @unlink($f);
        }
    }

    /** @return Generator<int,array<string,mixed>> */
    private static function porLotes(string $base, string $sql, array $params): Generator
    {
        $lote  = self::MUESTRA;
        $desde = 0;
        do {
            $filas = self::pedir($base, $sql, $params, $lote, $desde);
            $n     = count($filas);
            $pedidas = $lote;
            if ($desde === 0) {
                $lote = self::filasPorLote(self::ocupa($filas), $n);
            }
            foreach ($filas as $f) {
                yield $f;
            }
            unset($filas);
            $desde += $n;
        } while ($n === $pedidas);
    }

    /**
     * Lo que ocupan unas filas en memoria, midiendo una copia. Medir la
     * memoria antes y después de pedirlas contaba también lo que se cargaba
     * esa primera vez (el motor entero con la conexión directa): salían lotes
     * de 200 filas y miles de peticiones.
     */
    private static function ocupa(array $filas): int
    {
        $antes = memory_get_usage();
        $copia = unserialize(serialize($filas));
        $bytes = memory_get_usage() - $antes;
        unset($copia);
        return $bytes;
    }

    /** @return list<array<string,mixed>> */
    private static function pedir(string $base, string $sql, array $params, ?int $limite, int $desde): array
    {
        if ($limite !== null) {
            // En otra línea: si la consulta acaba en un comentario (-- …), el
            // LIMIT quedaba dentro de él y se pedía la tabla entera de una vez
            $sql = self::admiteLimite($sql, $params)
                ? "$sql\nLIMIT $limite OFFSET $desde"
                : "SELECT * FROM ($sql\n) AS _lote LIMIT $limite OFFSET $desde";
        }
        $filas = Api::sql($base, $sql, $params);
        if (isset($filas['success'])) {
            throw new RuntimeException(t('Esa sentencia no devuelve filas que exportar.'));
        }
        return $filas;
    }

    /**
     * ¿Se le puede añadir LIMIT … OFFSET … al final? Si ya lleva el suyo, se
     * envuelve en una subconsulta, que el motor resuelve entera en cada lote:
     * funciona igual, pero gasta más.
     */
    private static function admiteLimite(string $sql, array $params): bool
    {
        try {
            GeneradorSql::cargarAnalizador();
            $ast = \JsonSQLDB\Parser::analizar($sql, $params);
        } catch (Throwable) {
            return false;
        }
        return in_array($ast['k'], ['select', 'union'], true) && $ast['limit'] === null && $ast['offset'] === null;
    }
}
