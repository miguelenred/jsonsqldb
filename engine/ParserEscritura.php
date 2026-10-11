<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * INSERT, UPDATE y DELETE, y el SET NEW de los triggers. Hereda el análisis
 * de consultas y expresiones de Parser, que la elige al ver la primera palabra
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
    /** Una consulta, con Parser; INSERT, REPLACE, UPDATE y DELETE, aquí */
    protected function sentencia(): array
    {
        if ($this->es('id', 'INSERT') || $this->es('id', 'REPLACE')) { return $this->insert(); }
        if ($this->es('id', 'UPDATE')) { return $this->update(); }
        if ($this->es('id', 'DELETE')) { return $this->delete(); }
        return parent::sentencia();
    }

    // ==================================================================
    // INSERT / UPDATE / DELETE
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
                $conflicto = ['modo' => 'nada', 'cols' => null, 'set' => [], 'where' => null];
            } elseif ($this->comer('id', 'OR')) {
                $modo = strtoupper((string)$this->actual()['v']);
                $this->avanzar();
                if ($modo === 'IGNORE') {
                    $conflicto = ['modo' => 'nada', 'cols' => null, 'set' => [], 'where' => null];
                } elseif ($modo === 'REPLACE') {
                    $conflicto = ['modo' => 'reemplazar', 'cols' => null, 'set' => [], 'where' => null];
                } elseif ($modo !== 'ABORT') {
                    // FAIL y ROLLBACK deshacen distinto que ABORT en SQLite; aquí
                    // toda sentencia es todo o nada, así que no se admiten
                    throw JsonSqlDbError::syntax("INSERT OR $modo no está soportado: usa OR IGNORE, OR REPLACE u ON CONFLICT");
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
    private function returning(): ?array
    {
        return $this->comer('id', 'RETURNING') ? ParserConsulta::listaColumnas($this) : null;
    }

    /**
     * UPDATE t [[AS] a] SET c = expr [, ...] [WHERE expr]
     *
     * Con otras tablas (2.9): UPDATE t SET … FROM s … (SQLite y PostgreSQL) y
     * UPDATE t JOIN s ON … SET t.c = … (MySQL). Cambia una sola tabla: la de
     * las columnas del SET que la llevan delante o, si ninguna, la primera.
     */
    private function update(): array
    {
        $this->exigir('id', 'UPDATE');
        $desde = $this->from($this->destino());
        $this->exigir('id', 'SET');
        $set   = [];
        $quien = null;
        do {
            $col = $this->nombreSimple('columna');
            if ($this->comer('punc', '.')) {
                [$de, $col] = [$col, $this->nombreSimple('columna')];
                if ($quien !== null && strcasecmp($quien, $de) !== 0) {
                    throw JsonSqlDbError::syntax("Un UPDATE solo puede cambiar una tabla, y el SET tiene columnas de '$quien' y de '$de'");
                }
                $quien = $de;
            }
            if (!$this->comer('op', '=')) {
                throw JsonSqlDbError::syntax("Falta '=' al asignar la columna '$col'");
            }
            $set[] = ['col' => $col, 'expr' => $this->comer('id', 'DEFAULT')
                ? ['k' => 'default']
                : $this->expr()];
        } while ($this->comer('punc', ','));

        if ($this->comer('id', 'FROM')) {
            if (count($desde) > 1) {
                throw JsonSqlDbError::syntax('Un UPDATE lleva las otras tablas en un JOIN antes de SET (MySQL) o en un FROM después (SQLite y PostgreSQL), no en los dos');
            }
            $propia = (string)($desde[0]['alias'] ?? $desde[0]['nombre']);
            if ($quien !== null && strcasecmp($quien, $propia) !== 0) {
                throw JsonSqlDbError::syntax("El SET de un UPDATE … FROM solo puede cambiar '$propia', no '$quien'");
            }
            $quien = $propia;
            $desde = self::conLaDestino($desde[0], $this->from());
        }
        $upd = ['k' => 'update', 'set' => $set, 'where' => $this->comer('id', 'WHERE') ? $this->expr() : null];
        return $this->limitadas($this->conDestino($upd, $desde, $quien));
    }

    /** DELETE t1, t2 FROM … de MySQL borra de varias tablas: aquí, de una */
    private function unaSola(): void
    {
        if ($this->es('punc', ',')) {
            throw JsonSqlDbError::syntax('Un DELETE solo puede borrar de una tabla');
        }
    }

    /** La tabla que se escribe, tras UPDATE o DELETE FROM: [esquema.]tabla [[AS] alias] */
    private function destino(): array
    {
        $nombre = $this->nombreTabla();
        return ['tipo' => 'tabla', 'nombre' => $nombre, 'select' => null, 'alias' => $this->alias(), 'join' => null, 'on' => null];
    }

    /**
     * El FROM de un UPDATE o el USING de un DELETE de SQLite y PostgreSQL: se
     * cruzan con la tabla que se escribe. Va delante, salvo con un RIGHT o un
     * FULL JOIN entre las otras: entonces detrás, para que ese JOIN sea entre
     * ellas, como lo escribió quien lo pide.
     */
    private static function conLaDestino(array $destino, array $otras): array
    {
        $exterior = array_intersect(['RIGHT', 'FULL'], array_column($otras, 'join')) !== [];
        $desde    = $exterior ? [...$otras, $destino] : [$destino, ...$otras];
        foreach ($desde as $i => $o) {
            $desde[$i]['join'] = $i === 0 ? null : ($o['join'] ?? 'CROSS');
        }
        return $desde;
    }

    /**
     * Pone en un UPDATE o un DELETE la tabla que escribe, que es la de nombre
     * o alias $quien (si no se dice, la primera). Con una sola tabla, la
     * sentencia de siempre; con más (2.9), 'desde' lleva el cruce entero con
     * la suya marcada: Writer lo resuelve con el SELECT y escribe cada fila
     * por su posición (ver WriterCambios::cruce()).
     */
    private function conDestino(array $ast, array $desde, ?string $quien): array
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
        if (count($desde) > 1) {
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
    private function limitadas(array $ast): array
    {
        $ast['returning'] = $this->returning();
        $ast['order'] = [];
        if ($this->comer('id', 'ORDER')) {
            $this->exigir('id', 'BY');
            $ast['order'] = ParserConsulta::listaOrden($this);
        }
        $ast['limit'] = $ast['offset'] = null;
        if ($this->comer('id', 'LIMIT')) {
            $ast['limit']  = ParserConsulta::limite($this, 'LIMIT');
            $ast['offset'] = $this->comer('id', 'OFFSET') ? ParserConsulta::limite($this, 'OFFSET') : null;
        }
        $ast['returning'] ??= $this->returning();
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
    private function delete(): array
    {
        $this->exigir('id', 'DELETE');
        $quien = null;
        if (!$this->es('id', 'FROM')) {
            $quien = $this->nombreSimple('tabla');
            if ($this->comer('punc', '.')) {
                $this->exigir('op', '*');                  // DELETE t.* FROM …
            }
            $this->unaSola();
        }
        $this->exigir('id', 'FROM');
        if ($quien !== null) {
            $desde = $this->from();
        } else {
            $desde = [$this->destino()];
            $this->unaSola();
            if ($this->comer('id', 'USING')) {
                $otras = $this->from();
                $quien = (string)($desde[0]['alias'] ?? $desde[0]['nombre']);
                // En MySQL, USING trae el cruce entero, con la tabla que se borra dentro
                $dentro = false;
                foreach ($otras as $o) {
                    $dentro = $dentro || strcasecmp((string)($o['alias'] ?? $o['nombre']), $quien) === 0;
                }
                $desde = $dentro ? $otras : self::conLaDestino($desde[0], $otras);
            }
        }
        $del = ['k' => 'delete', 'where' => $this->comer('id', 'WHERE') ? $this->expr() : null];
        return $this->limitadas($this->conDestino($del, $desde, $quien));
    }

    /**
     * SET NEW.col = expr [, NEW.col = expr], dentro de un trigger.
     *
     * @return array{k: string, asig: list<array{0: string, 1: array}>}
     */
    protected function setNew(): array
    {
        $this->exigir('id', 'SET');
        $asig = [];
        do {
            $quien = $this->nombreSimple('NEW');
            if (strtoupper($quien) !== 'NEW') {
                throw JsonSqlDbError::syntax('En un trigger, SET solo puede cambiar NEW.columna');
            }
            $this->exigir('punc', '.');
            $col = $this->nombreSimple('columna');
            if (!$this->comer('op', '=') && !$this->comer('op', ':=')) {
                throw JsonSqlDbError::syntax("Se esperaba '=' después de NEW.$col");
            }
            $asig[] = [$col, $this->expr()];
        } while ($this->comer('punc', ','));
        return ['k' => 'set_new', 'asig' => $asig];
    }

    /** SET NEW.col = expr suelto, como lo analiza un trigger al ejecutarse. */
    public static function analizarSetNew(string $sql): array
    {
        $p = new self(Lexer::tokens($sql), $sql, []);
        $r = $p->setNew();
        $p->comer('punc', ';');
        if (!$p->es('eof')) {
            throw JsonSqlDbError::syntax('Sobra texto después de SET NEW');
        }
        return $r;
    }
}
