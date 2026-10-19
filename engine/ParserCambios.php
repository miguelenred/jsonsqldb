<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * UPDATE y DELETE (2.10, aparte de ParserEscritura para que un INSERT no
 * tenga que compilarlos: sin OPcache, PHP compila en cada petición lo que
 * carga). Métodos de ParserEscritura que reciben el analizador en $an, como
 * ParserConsulta los del SELECT. Hereda de ParserEscritura solo para llegar a
 * lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class ParserCambios extends ParserEscritura
{
    private function __construct()
    {
    }

    /**
     * UPDATE [OR IGNORE | OR REPLACE] t [[AS] a] SET c = expr [, ...] [WHERE expr]
     *
     * 'conflicto' (2.10): null, o qué hacer con una fila que no cumple una
     * restricción, como en SQLite: 'nada' (OR IGNORE), dejarla como estaba;
     * 'reemplazar' (OR REPLACE), borrar las filas con las que choca.
     *
     * Con otras tablas (2.9): UPDATE t SET … FROM s … (SQLite y PostgreSQL) y
     * UPDATE t JOIN s ON … SET t.c = … (MySQL). Cambia una sola tabla: la de
     * las columnas del SET que la llevan delante o, si ninguna, la primera.
     */
    public static function update(ParserEscritura $an): array
    {
        $an->exigir('id', 'UPDATE');
        $conflicto = $an->comer('id', 'OR') ? $an->modoConflicto('UPDATE', 'OR IGNORE u OR REPLACE') : null;
        $desde = $an->from(self::destino($an));
        $an->exigir('id', 'SET');
        $set   = [];
        $quien = null;
        do {
            $col = $an->nombreSimple('columna');
            if ($an->comer('punc', '.')) {
                [$de, $col] = [$col, $an->nombreSimple('columna')];
                if ($quien !== null && strcasecmp($quien, $de) !== 0) {
                    throw JsonSqlDbError::syntax("Un UPDATE solo puede cambiar una tabla, y el SET tiene columnas de '$quien' y de '$de'");
                }
                $quien = $de;
            }
            if (!$an->comer('op', '=')) {
                throw JsonSqlDbError::syntax("Falta '=' al asignar la columna '$col'");
            }
            $set[] = ['col' => $col, 'expr' => $an->comer('id', 'DEFAULT')
                ? ['k' => 'default']
                : $an->expr()];
        } while ($an->comer('punc', ','));

        if ($an->comer('id', 'FROM')) {
            if (\count($desde) > 1) {
                throw JsonSqlDbError::syntax('Un UPDATE lleva las otras tablas en un JOIN antes de SET (MySQL) o en un FROM después (SQLite y PostgreSQL), no en los dos');
            }
            $propia = (string)($desde[0]['alias'] ?? $desde[0]['nombre']);
            if ($quien !== null && strcasecmp($quien, $propia) !== 0) {
                throw JsonSqlDbError::syntax("El SET de un UPDATE … FROM solo puede cambiar '$propia', no '$quien'");
            }
            $quien = $propia;
            $desde = ParserEscrituraAvanzada::conLaDestino($desde[0], $an->from());
        }
        $upd = ['k' => 'update', 'set' => $set, 'where' => $an->comer('id', 'WHERE') ? $an->expr() : null, 'conflicto' => $conflicto];
        return self::limitadas($an, self::conDestino($an, $upd, $desde, $quien));
    }

    /** DELETE t1, t2 FROM … de MySQL borra de varias tablas: aquí, de una */
    private static function unaSola(ParserEscritura $an): void
    {
        if ($an->es('punc', ',')) {
            throw JsonSqlDbError::syntax('Un DELETE solo puede borrar de una tabla');
        }
    }

    /** La tabla que se escribe, tras UPDATE o DELETE FROM: [esquema.]tabla [[AS] alias] */
    private static function destino(ParserEscritura $an): array
    {
        $nombre = $an->nombreTabla();
        return ['tipo' => 'tabla', 'nombre' => $nombre, 'select' => null, 'alias' => $an->alias(), 'join' => null, 'on' => null];
    }

    /**
     * Pone en un UPDATE o un DELETE la tabla que escribe, que es la de nombre
     * o alias $quien (si no se dice, la primera). Con una sola tabla, la
     * sentencia de siempre; con más (2.9), 'desde' lleva el cruce entero con
     * la suya marcada: Writer lo resuelve con el SELECT y escribe cada fila
     * por su posición (ver WriterCambiosEspeciales::cruce()).
     */
    private static function conDestino(ParserEscritura $an, array $ast, array $desde, ?string $quien): array
    {
        $quien ??= (string)($desde[0]['alias'] ?? $desde[0]['nombre']);
        $cual   = null;
        foreach ($desde as $i => $o) {
            if (strcasecmp((string)($o['alias'] ?? $o['nombre']), $quien) === 0) {
                if ($cual !== null) {
                    throw JsonSqlDbError::syntax("'$quien' está dos veces: ponle un alias a una de ellas");
                }
                $cual = $i;
            }
        }
        if ($cual === null || $desde[$cual]['tipo'] !== 'tabla') {
            throw JsonSqlDbError::syntax($cual === null
                ? "La tabla '$quien' no está en la sentencia"
                : "'$quien' es una subconsulta: solo se puede escribir en una tabla");
        }
        $ast['tabla'] = $desde[$cual]['nombre'];
        $ast['alias'] = $desde[$cual]['alias'];
        if (\count($desde) > 1) {
            $desde[$cual]['posicion'] = true;
            $ast['desde'] = $desde;
        }
        return $ast;
    }

    /**
     * RETURNING, y ORDER BY … LIMIT n [OFFSET m] de un UPDATE o un DELETE
     * (2.9, como MySQL y SQLite): cambia solo esas filas, en ese orden.
     * RETURNING puede ir antes (SQLite) o después.
     */
    private static function limitadas(ParserEscritura $an, array $ast): array
    {
        $ast['returning'] = $an->returning();
        $ast['order'] = [];
        if ($an->comer('id', 'ORDER')) {
            $an->exigir('id', 'BY');
            $ast['order'] = ParserConsulta::listaOrden($an);
        }
        $ast['limit'] = $ast['offset'] = null;
        if ($an->comer('id', 'LIMIT')) {
            $ast['limit']  = ParserConsulta::limite($an, 'LIMIT');
            $ast['offset'] = $an->comer('id', 'OFFSET') ? ParserConsulta::limite($an, 'OFFSET') : null;
        }
        $ast['returning'] ??= $an->returning();
        if (isset($ast['desde']) && ($ast['order'] !== [] || $ast['limit'] !== null)) {
            throw JsonSqlDbError::syntax('ORDER BY y LIMIT no se admiten en un ' . strtoupper($ast['k']) . ' con otras tablas');
        }
        return $ast;
    }

    /**
     * DELETE FROM t [[AS] a] [WHERE expr]
     *
     * Con otras tablas (2.9): DELETE FROM t USING s … (PostgreSQL),
     * DELETE t FROM t JOIN s … y DELETE FROM t USING t JOIN s … (MySQL).
     * Borra de una sola tabla.
     */
    public static function delete(ParserEscritura $an): array
    {
        $an->exigir('id', 'DELETE');
        $quien = null;
        if (!$an->es('id', 'FROM')) {
            $quien = $an->nombreSimple('tabla');
            if ($an->comer('punc', '.')) {
                $an->exigir('op', '*');                  // DELETE t.* FROM …
            }
            self::unaSola($an);
        }
        $an->exigir('id', 'FROM');
        if ($quien !== null) {
            $desde = $an->from();
        } else {
            $desde = [self::destino($an)];
            self::unaSola($an);
            if ($an->comer('id', 'USING')) {
                $otras = $an->from();
                $quien = (string)($desde[0]['alias'] ?? $desde[0]['nombre']);
                // En MySQL, USING trae el cruce entero, con la tabla que se borra dentro
                $dentro = false;
                foreach ($otras as $o) {
                    $dentro = $dentro || strcasecmp((string)($o['alias'] ?? $o['nombre']), $quien) === 0;
                }
                $desde = $dentro ? $otras : ParserEscrituraAvanzada::conLaDestino($desde[0], $otras);
            }
        }
        $del = ['k' => 'delete', 'where' => $an->comer('id', 'WHERE') ? $an->expr() : null];
        return self::limitadas($an, self::conDestino($an, $del, $desde, $quien));
    }
}
