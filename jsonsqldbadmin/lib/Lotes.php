<?php
declare(strict_types=1);

/**
 * Filas de un SELECT para exportarlas sin agotar la memoria de PHP.
 *
 * Se mide lo que ocupa una muestra de filas y se calcula cuántas caben a la
 * vez con la memoria que queda libre (memory_limit menos lo ya usado). Si el
 * resultado entero cabe, se pide de una vez; si no, por lotes de ese tamaño
 * (LIMIT … OFFSET …), y en memoria solo hay un lote.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Lotes
{
    /** Filas de la muestra con la que se mide lo que ocupa cada una. */
    private const MUESTRA = 200;
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
        return max(self::MUESTRA, min(self::MAX_LOTE, intdiv($libre, $porFila * self::MARGEN)));
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
        if ($total !== null) {
            $antes   = memory_get_usage();
            $muestra = self::pedir($base, $sql, $params, self::MUESTRA, 0);
            if (count($muestra) < self::MUESTRA) {
                return $muestra;                         // ya está entero
            }
            if ($total <= self::filasPorLote(memory_get_usage() - $antes, count($muestra))) {
                unset($muestra);
                return self::pedir($base, $sql, $params, null, 0);
            }
            unset($muestra);
        }
        return self::porLotes($base, $sql, $params);
    }

    /** @return Generator<int,array<string,mixed>> */
    private static function porLotes(string $base, string $sql, array $params): Generator
    {
        $lote  = self::MUESTRA;
        $desde = 0;
        do {
            $antes = memory_get_usage();
            $filas = self::pedir($base, $sql, $params, $lote, $desde);
            $n     = count($filas);
            $pedidas = $lote;
            if ($desde === 0) {
                $lote = self::filasPorLote(memory_get_usage() - $antes, $n);
            }
            foreach ($filas as $f) {
                yield $f;
            }
            unset($filas);
            $desde += $n;
        } while ($n === $pedidas);
    }

    /** @return list<array<string,mixed>> */
    private static function pedir(string $base, string $sql, array $params, ?int $limite, int $desde): array
    {
        if ($limite !== null) {
            $sql = self::admiteLimite($sql, $params)
                ? "$sql LIMIT $limite OFFSET $desde"
                : "SELECT * FROM ($sql) AS _lote LIMIT $limite OFFSET $desde";
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
        } catch (Throwable $e) {
            return false;
        }
        return in_array($ast['k'], ['select', 'union'], true) && $ast['limit'] === null && $ast['offset'] === null;
    }
}
