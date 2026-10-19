<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * INSERT, y lo que tiene en común con UPDATE y DELETE, que están en
 * ParserCambios (el SET NEW de los triggers y el cruce de UPDATE … FROM y
 * DELETE … USING, en ParserEscrituraAvanzada). Hereda el análisis de
 * consultas y expresiones de Parser, que la elige al ver la primera palabra
 * (ver Parser::analizar()); ParserSentencias la hereda a su vez. Va aparte
 * para que una consulta no tenga que compilarla (2.9).
 *
 * https://miguelenred.es/jsonsqldb
 */
/**
 * @phpstan-import-type Token from Lexer
 */
class ParserEscritura extends Parser
{
    /** Una consulta, con Parser; INSERT y REPLACE, aquí; UPDATE y DELETE, en ParserCambios */
    protected function sentencia(): array
    {
        if ($this->es('id', 'INSERT') || $this->es('id', 'REPLACE')) { return $this->insert(); }
        if ($this->es('id', 'UPDATE')) { return ParserCambios::update($this); }
        if ($this->es('id', 'DELETE')) { return ParserCambios::delete($this); }
        return parent::sentencia();
    }

    // ==================================================================
    // INSERT
    // ==================================================================

    /**
     * INSERT [OR IGNORE | OR REPLACE] INTO t [(cols)] VALUES (...)[, (...)] | SELECT ...
     *     [ON CONFLICT [(cols)] DO NOTHING | DO UPDATE SET c = expr, ... [WHERE expr]]
     *     [RETURNING ...]
     * REPLACE INTO … es INSERT OR REPLACE, como en SQLite.
     *
     * 'conflicto' (2.8): null, o qué hacer si la fila choca con una clave única:
     *   modo 'nada'       saltarse la fila (OR IGNORE, DO NOTHING)
     *   modo 'reemplazar' borrar las filas con las que choca y meter esta (OR REPLACE)
     *   modo 'actualizar' cambiar la fila con la que choca (DO UPDATE)
     *   cols              las columnas de la clave que se mira (null: cualquiera)
     */
    private function insert(): array
    {
        $conflicto = null;
        if ($this->comer('id', 'REPLACE')) {
            $conflicto = ['modo' => 'reemplazar', 'cols' => null, 'set' => [], 'where' => null];
        } else {
            $this->exigir('id', 'INSERT');
            if ($this->comer('id', 'IGNORE')) {
                // INSERT IGNORE de MySQL: el INSERT OR IGNORE de SQLite
                $conflicto = ['modo' => 'nada', 'cols' => null, 'set' => [], 'where' => null, 'saltar' => true];
            } elseif ($this->comer('id', 'OR')) {
                $modo = $this->modoConflicto('INSERT', 'OR IGNORE, OR REPLACE u ON CONFLICT');
                if ($modo !== null) {
                    $conflicto = ['modo' => $modo, 'cols' => null, 'set' => [], 'where' => null] + ($modo === 'nada' ? ['saltar' => true] : []);
                }
            }
        }
        $this->exigir('id', 'INTO');

        $ins = ['k' => 'insert', 'tabla' => $this->nombreTabla(), 'cols' => null,
                'filas' => null, 'select' => null, 'conflicto' => $conflicto, 'returning' => null];

        // Lista de columnas: hay que distinguirla de un VALUES sin columnas
        if ($this->es('punc', '(') && !$this->esSiguiente('id', 'SELECT')) {
            $this->avanzar();
            $ins['cols'] = [];
            do {
                $ins['cols'][] = $this->nombreSimple('columna');
            } while ($this->comer('punc', ','));
            $this->exigir('punc', ')');
        }

        if ($ins['cols'] === null && $this->comer('id', 'SET')) {
            // INSERT INTO t SET a = 1, b = 2 de MySQL: una fila con esas columnas
            $ins['cols']  = [];
            $fila = [];
            do {
                $ins['cols'][] = $this->nombreSimple('columna');
                if (!$this->comer('op', '=')) {
                    throw JsonSqlDbError::syntax("Falta '=' al asignar la columna '" . end($ins['cols']) . "'");
                }
                $fila[] = $this->comer('id', 'DEFAULT') ? ['k' => 'default'] : $this->expr();
            } while ($this->comer('punc', ','));
            $ins['filas'] = [$fila];
        } elseif ($ins['cols'] === null && $this->es('id', 'DEFAULT') && $this->esSiguiente('id', 'VALUES')) {
            // INSERT INTO t DEFAULT VALUES de SQLite: una fila con los valores por defecto
            $this->avanzar();
            $this->avanzar();
            $ins['cols']  = [];
            $ins['filas'] = [[]];
        } elseif ($this->comer('id', 'VALUES')) {
            $ins['filas'] = [];
            do {
                $this->exigir('punc', '(');
                $fila = [];
                if (!$this->es('punc', ')')) {
                    do {
                        $fila[] = $this->comer('id', 'DEFAULT')
                            ? ['k' => 'default']
                            : $this->expr();
                    } while ($this->comer('punc', ','));
                }
                $this->exigir('punc', ')');
                $ins['filas'][] = $fila;
            } while ($this->comer('punc', ','));
        } elseif ($this->es('id', 'SELECT')) {
            $ins['select'] = ParserConsulta::select($this);
        } elseif ($this->comer('punc', '(') && $this->es('id', 'SELECT')) {
            $ins['select'] = ParserConsulta::select($this);
            $this->exigir('punc', ')');
        } else {
            throw JsonSqlDbError::syntax('Se esperaba VALUES o SELECT en el INSERT');
        }

        if ($this->es('id', 'ON') && $this->esSiguiente('id', 'DUPLICATE')) {
            // ON DUPLICATE KEY UPDATE de MySQL: si choca con cualquier clave
            // única, se actualiza esa fila; VALUES(col) es el valor propuesto,
            // el excluded.col de SQLite
            if ($conflicto !== null) {
                throw JsonSqlDbError::syntax('ON DUPLICATE KEY UPDATE junto con INSERT IGNORE, OR IGNORE, OR REPLACE o REPLACE INTO no está soportado: usa uno de los dos');
            }
            $this->avanzar();
            $this->avanzar();
            $this->exigir('id', 'KEY');
            $this->exigir('id', 'UPDATE');
            $set = [];
            $this->valoresPropuestos = true;
            try {
                do {
                    $col = $this->nombreSimple('columna');
                    if (!$this->comer('op', '=')) {
                        throw JsonSqlDbError::syntax("Falta '=' al asignar la columna '$col'");
                    }
                    $set[] = ['col' => $col, 'expr' => $this->expr()];
                } while ($this->comer('punc', ','));
            } finally {
                $this->valoresPropuestos = false;
            }
            $ins['conflicto'] = ['modo' => 'actualizar', 'cols' => null, 'set' => $set, 'where' => null];
        } elseif ($this->es('id', 'ON') && $this->esSiguiente('id', 'CONFLICT')) {
            if ($conflicto !== null) {
                throw JsonSqlDbError::syntax('ON CONFLICT junto con OR IGNORE, OR REPLACE o REPLACE INTO no está soportado: usa uno de los dos');
            }
            $this->avanzar();
            $this->avanzar();
            $cols = $this->es('punc', '(') ? $this->listaColumnasEntreParentesis() : null;
            $this->exigir('id', 'DO');
            if ($this->comer('id', 'NOTHING')) {
                $ins['conflicto'] = ['modo' => 'nada', 'cols' => $cols, 'set' => [], 'where' => null];
            } else {
                $this->exigir('id', 'UPDATE');
                $this->exigir('id', 'SET');
                $set = [];
                do {
                    $col = $this->nombreSimple('columna');
                    if (!$this->comer('op', '=')) {
                        throw JsonSqlDbError::syntax("Falta '=' al asignar la columna '$col'");
                    }
                    $set[] = ['col' => $col, 'expr' => $this->expr()];
                } while ($this->comer('punc', ','));
                $where = $this->comer('id', 'WHERE') ? $this->expr() : null;
                $ins['conflicto'] = ['modo' => 'actualizar', 'cols' => $cols, 'set' => $set, 'where' => $where];
            }
        }
        $ins['returning'] = $this->returning();
        return $ins;
    }

    /** RETURNING col, expr AS x, * (2.8): las filas escritas, como un SELECT. */
    protected function returning(): ?array
    {
        return $this->comer('id', 'RETURNING') ? ParserConsulta::listaColumnas($this) : null;
    }

    /**
     * Lo que va tras INSERT OR o UPDATE OR: 'nada' (IGNORE), 'reemplazar'
     * (REPLACE) o null (ABORT, lo de siempre).
     */
    protected function modoConflicto(string $sentencia, string $usa): ?string
    {
        $modo = strtoupper((string)$this->actual()['v']);
        $this->avanzar();
        if ($modo === 'IGNORE' || $modo === 'REPLACE') {
            return $modo === 'IGNORE' ? 'nada' : 'reemplazar';
        }
        if ($modo !== 'ABORT') {
            // FAIL y ROLLBACK deshacen distinto que ABORT en SQLite; aquí
            // toda sentencia es todo o nada, así que no se admiten
            throw JsonSqlDbError::syntax("$sentencia OR $modo no está soportado: usa $usa");
        }
        return null;
    }
}
