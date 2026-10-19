<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que solo usan algunas escrituras: RETURNING y la comprobación de que un
 * valor que se guarda no son los bytes de RANDOMBLOB o ZEROBLOB (2.10, aparte
 * de Writer para que el resto no tengan que compilarlo: sin OPcache, PHP
 * compila en cada petición lo que carga). Métodos de Writer que reciben el
 * ejecutor en $w. Hereda de Writer solo para llegar a lo protegido; no se
 * instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class WriterAvanzado extends Writer
{
    /** Funciones que pasan bytes a texto: dentro de ellas, RANDOMBLOB y ZEROBLOB se pueden guardar */
    private const A_TEXTO = ['HEX', 'QUOTE', 'LENGTH', 'OCTET_LENGTH', 'BIT_LENGTH', 'TO_BASE64', 'MD5', 'SHA1', 'SHA2', 'CRC32', 'TYPEOF'];

    private function __construct()
    {
    }

    /**
     * Un valor que se va a guardar no puede salir de RANDOMBLOB ni de ZEROBLOB
     * tal cual (2.9): son bytes, y aquí las tablas guardan texto. Se mira en la
     * sentencia, no en los bytes, para que el error no dependa de que unos
     * bytes al azar formen o no un texto válido. Solo si el analizador vio
     * alguna de las dos (la marca 'bytes'): el resto no paga nada.
     */
    public static function exigirTexto(array $ast): void
    {
        $valores = [];
        foreach ($ast['filas'] ?? [] as $fila) {
            array_push($valores, ...$fila);
        }
        foreach ($ast['select']['cols'] ?? [] as $c) {
            $valores[] = $c['expr'] ?? null;
        }
        foreach (array_merge($ast['set'] ?? [], $ast['conflicto']['set'] ?? []) as $s) {
            $valores[] = $s['expr'];
        }
        foreach ($valores as $e) {
            if (\is_array($e) && self::daBytes($e)) {
                throw JsonSqlDbError::type('RANDOMBLOB() y ZEROBLOB() dan bytes, que no se pueden guardar en una tabla: '
                    . 'guárdalos como texto, con HEX(…)');
            }
        }
    }

    private static function daBytes(array $n): bool
    {
        if (($n['k'] ?? null) === 'fn') {
            $f = strtoupper((string)$n['nombre']);
            if ($f === 'RANDOMBLOB' || $f === 'ZEROBLOB') {
                return true;
            }
            if (\in_array($f, self::A_TEXTO, true)) {
                return false;
            }
        }
        foreach ($n as $hijo) {
            if (\is_array($hijo) && self::daBytes($hijo)) {
                return true;
            }
        }
        return false;
    }

    /**
     * RETURNING: las filas escritas, proyectadas como las columnas de un
     * SELECT sobre la tabla (* son todas, en su orden).
     *
     * @return list<array>
     */
    public static function devolver(Writer $w, string $tabla, array $columnas, ?string $alias): array
    {
        $meta = $w->meta($tabla);
        $mapa = $w->mapaColumnas($tabla, $meta, $alias);
        // Resueltas una vez, también sin filas: una columna que no existe es
        // un error aunque la sentencia no cambie nada, como en SQLite (2.10)
        $exprs = [];
        foreach ($columnas as $i => $c) {
            if (!$c['star']) {
                $exprs[$i] = Evaluator::resolver($c['expr'], $mapa);
            }
        }
        // Una subconsulta ve las tablas como las deja la sentencia (2.10: un
        // error de PHP), sin lo que se guardó de ella antes de escribir
        $w->subsFijas = $w->conjuntosFijos = [];
        $ctx = ['sub' => $w->subconsultas($mapa), 'conjunto' => $w->conjuntos($mapa)];
        $out = [];
        foreach ($w->devueltas as $fila) {
            $r = [];
            foreach ($columnas as $i => $c) {
                if ($c['star']) {
                    foreach ($meta['columns'] as $col) {
                        $r[$col['name']] = $fila[$col['name']] ?? null;
                    }
                    continue;
                }
                $r[$c['alias'] ?? Select::etiqueta($c['expr'])] = Evaluator::evaluar($exprs[$i], ['fila' => $fila] + $ctx);
            }
            $out[] = $r;
        }
        return $out;
    }
}
