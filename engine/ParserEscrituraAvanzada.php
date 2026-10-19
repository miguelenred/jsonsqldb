<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que solo usan algunas escrituras: el SET NEW de un trigger y el cruce de
 * un UPDATE … FROM o un DELETE … USING con la tabla que se escribe (2.10,
 * aparte de ParserEscritura para que el resto no tengan que compilarlo: sin
 * OPcache, PHP compila en cada petición lo que carga). Métodos de Parser que
 * reciben el analizador en $an. Hereda de Parser solo para llegar a lo
 * protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class ParserEscrituraAvanzada extends Parser
{
    private function __construct()
    {
    }

    /**
     * El FROM de un UPDATE o el USING de un DELETE de SQLite y PostgreSQL: se
     * cruzan con la tabla que se escribe. Va delante, salvo con un RIGHT o un
     * FULL JOIN entre las otras: entonces detrás, para que ese JOIN sea entre
     * ellas, como lo escribió quien lo pide.
     */
    public static function conLaDestino(array $destino, array $otras): array
    {
        $exterior = array_intersect(['RIGHT', 'FULL'], array_column($otras, 'join')) !== [];
        $desde    = $exterior ? [...$otras, $destino] : [$destino, ...$otras];
        foreach ($desde as $i => $o) {
            $desde[$i]['join'] = $i === 0 ? null : ($o['join'] ?? 'CROSS');
        }
        return $desde;
    }

    /**
     * SET NEW.col = expr [, NEW.col = expr], dentro de un trigger.
     *
     * @return array{k: string, asig: list<array{0: string, 1: array}>}
     */
    public static function setNew(Parser $an): array
    {
        $an->exigir('id', 'SET');
        $asig = [];
        do {
            $quien = $an->nombreSimple('NEW');
            if (strtoupper($quien) !== 'NEW') {
                throw JsonSqlDbError::syntax('En un trigger, SET solo puede cambiar NEW.columna');
            }
            $an->exigir('punc', '.');
            $col = $an->nombreSimple('columna');
            if (!$an->comer('op', '=') && !$an->comer('op', ':=')) {
                throw JsonSqlDbError::syntax("Se esperaba '=' después de NEW.$col");
            }
            $asig[] = [$col, $an->expr()];
        } while ($an->comer('punc', ','));
        return ['k' => 'set_new', 'asig' => $asig];
    }

    /** SET NEW.col = expr suelto, como lo analiza un trigger al ejecutarse. */
    public static function analizarSetNew(string $sql): array
    {
        $p = new ParserEscritura(Lexer::tokens($sql), $sql, []);
        $r = self::setNew($p);
        $p->comer('punc', ';');
        if (!$p->es('eof')) {
            throw JsonSqlDbError::syntax('Sobra texto después de SET NEW');
        }
        return $r;
    }
}
