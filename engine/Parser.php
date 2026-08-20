<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Analizador sintáctico. Devuelve el árbol (AST) de la sentencia.
 *
 * SELECT:
 * ['k'=>'select','distinct'=>bool,
 *  'cols'  => [ ['star'=>true,'tabla'=>?] | ['expr'=>AST,'alias'=>?] ],
 *  'from'  => [ ['tipo'=>'tabla'|'sub','nombre'=>?,'select'=>?,'alias'=>?,
 *               'join'=>null|'INNER'|'LEFT'|'RIGHT'|'CROSS','on'=>?AST] ],
 *  'where' => ?AST, 'group' => ?AST[], 'having' => ?AST,
 *  'order' => [ ['expr'=>AST,'dir'=>'ASC'|'DESC'] ], 'limit'=>?int, 'offset'=>?int]
 *
 * Expresiones:
 *  ['k'=>'lit','v'=>...]                        literal o NULL
 *  ['k'=>'col','tabla'=>?,'nombre'=>...]        referencia a columna
 *  ['k'=>'bin','op'=>...,'i'=>AST,'d'=>AST]     operación binaria
 *  ['k'=>'un','op'=>'-'|'NOT','e'=>AST]         operación unaria
 *  ['k'=>'fn','nombre'=>...,'args'=>[],'star'=>bool,'distinct'=>bool]
 *  ['k'=>'case','base'=>?AST,'when'=>[[cond,res]],'else'=>?AST]
 *  ['k'=>'in','e'=>AST,'lista'=>?AST[],'select'=>?AST,'not'=>bool]
 *  ['k'=>'between','e'=>AST,'min'=>AST,'max'=>AST,'not'=>bool]
 *  ['k'=>'like','e'=>AST,'patron'=>AST,'escape'=>?AST,'not'=>bool]
 *  ['k'=>'null','e'=>AST,'not'=>bool]           IS NULL / IS NOT NULL
 *  ['k'=>'sub','select'=>AST]                   subconsulta escalar
 */
final class Parser
{
    /** Palabras que nunca se toman como alias sin AS */
    private const RESERVADAS = [
        'FROM','WHERE','GROUP','ORDER','HAVING','LIMIT','OFFSET','JOIN','INNER','LEFT','RIGHT',
        'FULL','CROSS','OUTER','ON','AS','AND','OR','NOT','IN','IS','LIKE','BETWEEN','CASE','WHEN',
        'THEN','ELSE','END','SELECT','DISTINCT','ALL','ASC','DESC','BY','NULL','UNION','VALUES','SET',
    ];

    private array  $t;
    private int    $i = 0;
    private string $sql;
    private array  $params;
    private int    $iParam = 0;

    private function __construct(array $tokens, string $sql, array $params)
    {
        $this->t      = $tokens;
        $this->sql    = $sql;
        $this->params = $params;
    }

    /**
     * Analiza una sentencia completa.
     *
     * Cada ? de la SQL se sustituye por el valor correspondiente de $params en
     * el árbol, como literal. El valor nunca se concatena al texto SQL, así que
     * no puede alterar la sentencia por mucho SQL que contenga.
     *
     * @param array $params Valores posicionales: null, bool, int, float o string.
     */
    public static function analizar(string $sql, array $params = []): array
    {
        $p   = new self(Lexer::tokens($sql), $sql, array_values($params));
        $ast = $p->sentencia();
        $p->comer('punc', ';');
        if (!$p->es('eof')) {
            throw JsonSqlDbError::syntax('Solo se admite una sentencia por consulta');
        }
        if ($p->iParam !== count($p->params)) {
            throw JsonSqlDbError::syntax(
                'La sentencia tiene ' . $p->iParam . ' marcadores ? y se han recibido ' . count($p->params) . ' parámetros'
            );
        }
        return $ast;
    }

    /** Valor del siguiente ? de la sentencia. */
    private function parametro()
    {
        if (!array_key_exists($this->iParam, $this->params)) {
            throw JsonSqlDbError::syntax('Faltan parámetros para los marcadores ? de la sentencia');
        }
        $v = $this->params[$this->iParam++];
        if ($v === null || is_int($v) || is_float($v) || is_string($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        throw JsonSqlDbError::syntax('El parámetro ' . $this->iParam . ' no es un valor simple');
    }

    private function sentencia(): array
    {
        if ($this->es('id', 'SELECT')) { return $this->select(); }
        if ($this->es('id', 'INSERT')) { return $this->insert(); }
        if ($this->es('id', 'UPDATE')) { return $this->update(); }
        if ($this->es('id', 'DELETE')) { return $this->delete(); }
        if ($this->es('id', 'CREATE')) { return $this->create(); }
        if ($this->es('id', 'DROP'))   { return $this->drop(); }
        if ($this->es('id', 'ALTER'))  { return $this->alter(); }
        if ($this->es('id', 'SHOW'))   { return $this->show(); }

        $tk = $this->actual();
        throw JsonSqlDbError::syntax("Sentencia no soportada: '" . ($tk['v'] === '' ? 'vacía' : $tk['v']) . "'");
    }

    // ==================================================================
    // SELECT
    // ==================================================================

    private function select(): array
    {
        $this->exigir('id', 'SELECT');

        $distinct = false;
        if ($this->comer('id', 'DISTINCT')) {
            $distinct = true;
        } else {
            $this->comer('id', 'ALL');
        }

        $sel = [
            'k' => 'select', 'distinct' => $distinct, 'cols' => $this->listaColumnas(),
            'from' => [], 'where' => null, 'group' => null, 'having' => null,
            'order' => [], 'limit' => null, 'offset' => null,
        ];

        if ($this->comer('id', 'FROM')) {
            $sel['from'] = $this->from();
        }
        if ($this->comer('id', 'WHERE')) {
            $sel['where'] = $this->expr();
        }
        if ($this->comer('id', 'GROUP')) {
            $this->exigir('id', 'BY');
            $sel['group'] = [];
            do {
                $sel['group'][] = $this->expr();
            } while ($this->comer('punc', ','));

            if ($this->comer('id', 'HAVING')) {
                $sel['having'] = $this->expr();
            }
        } elseif ($this->comer('id', 'HAVING')) {
            $sel['having'] = $this->expr();   // HAVING sin GROUP BY: agrupación total
        }
        if ($this->comer('id', 'ORDER')) {
            $this->exigir('id', 'BY');
            do {
                $e   = $this->expr();
                $dir = 'ASC';
                if ($this->comer('id', 'DESC'))      { $dir = 'DESC'; }
                elseif ($this->comer('id', 'ASC'))   { $dir = 'ASC'; }
                $sel['order'][] = ['expr' => $e, 'dir' => $dir];
            } while ($this->comer('punc', ','));
        }
        if ($this->comer('id', 'LIMIT')) {
            $primero = $this->enteroPositivo('LIMIT');
            if ($this->comer('punc', ',')) {            // LIMIT offset, cantidad
                $sel['offset'] = $primero;
                $sel['limit']  = $this->enteroPositivo('LIMIT');
            } else {
                $sel['limit'] = $primero;
                if ($this->comer('id', 'OFFSET')) {
                    $sel['offset'] = $this->enteroPositivo('OFFSET');
                }
            }
        }

        return $sel;
    }

    private function listaColumnas(): array
    {
        $cols = [];
        do {
            // *
            if ($this->es('op', '*')) {
                $this->avanzar();
                $cols[] = ['star' => true, 'tabla' => null];
                continue;
            }
            // tabla.*
            if ($this->es('id') && $this->esSiguiente('punc', '.') && $this->esSiguiente('op', '*', 2)) {
                $tabla = $this->avanzar()['v'];
                $this->avanzar();
                $this->avanzar();
                $cols[] = ['star' => true, 'tabla' => $tabla];
                continue;
            }

            $expr  = $this->expr();
            $alias = $this->alias();
            $cols[] = ['star' => false, 'expr' => $expr, 'alias' => $alias];
        } while ($this->comer('punc', ','));

        if ($cols === []) {
            throw JsonSqlDbError::syntax('SELECT sin columnas');
        }
        return $cols;
    }

    private function from(): array
    {
        $from   = [$this->origen(null)];
        while (true) {
            if ($this->comer('punc', ',')) {
                $from[] = $this->origen('CROSS');
                continue;
            }
            $tipo = null;
            if ($this->es('id', 'INNER'))      { $this->avanzar(); $tipo = 'INNER'; }
            elseif ($this->es('id', 'LEFT'))   { $this->avanzar(); $this->comer('id', 'OUTER'); $tipo = 'LEFT'; }
            elseif ($this->es('id', 'RIGHT'))  { $this->avanzar(); $this->comer('id', 'OUTER'); $tipo = 'RIGHT'; }
            elseif ($this->es('id', 'CROSS'))  { $this->avanzar(); $tipo = 'CROSS'; }
            elseif ($this->es('id', 'FULL'))   {
                throw JsonSqlDbError::syntax('FULL JOIN no está soportado');
            }
            elseif ($this->es('id', 'JOIN'))   { $tipo = 'INNER'; }

            if ($tipo === null) {
                break;
            }
            $this->exigir('id', 'JOIN');
            $origen = $this->origen($tipo);

            if ($this->comer('id', 'ON')) {
                $origen['on'] = $this->expr();
            } elseif ($tipo !== 'CROSS') {
                throw JsonSqlDbError::syntax("Falta la condición ON del $tipo JOIN");
            }
            $from[] = $origen;
        }
        return $from;
    }

    private function origen(?string $join): array
    {
        $o = ['tipo' => 'tabla', 'nombre' => null, 'select' => null, 'alias' => null,
              'join' => $join, 'on' => null];

        if ($this->comer('punc', '(')) {
            $o['tipo']   = 'sub';
            $o['select'] = $this->select();
            $this->exigir('punc', ')');
            $o['alias'] = $this->alias();
            if ($o['alias'] === null) {
                throw JsonSqlDbError::syntax('Una subconsulta en FROM necesita alias');
            }
            return $o;
        }

        $tk = $this->actual();
        if ($tk['t'] !== 'id' || (!$tk['q'] && in_array($tk['u'], self::RESERVADAS, true))) {
            throw JsonSqlDbError::syntax("Se esperaba un nombre de tabla y se encontró '{$tk['v']}'");
        }
        $this->avanzar();
        $o['nombre'] = $tk['v'];
        $o['alias']  = $this->alias();
        return $o;
    }

    /** [AS] alias — devuelve null si no hay */
    private function alias(): ?string
    {
        if ($this->comer('id', 'AS')) {
            $tk = $this->actual();
            if ($tk['t'] !== 'id') {
                throw JsonSqlDbError::syntax("Alias no válido tras AS: '{$tk['v']}'");
            }
            $this->avanzar();
            return $tk['v'];
        }
        $tk = $this->actual();
        if ($tk['t'] === 'id' && ($tk['q'] || !in_array($tk['u'], self::RESERVADAS, true))) {
            $this->avanzar();
            return $tk['v'];
        }
        return null;
    }

    private function enteroPositivo(string $clausula): int
    {
        $tk = $this->actual();
        if ($tk['t'] === 'param') {
            $this->avanzar();
            $v = $this->parametro();
            if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
                throw JsonSqlDbError::syntax("$clausula necesita un número entero positivo");
            }
            $v = (int)$v;
        } else {
            if ($tk['t'] !== 'num' || !is_int($tk['v'])) {
                throw JsonSqlDbError::syntax("$clausula necesita un número entero positivo");
            }
            $this->avanzar();
            $v = $tk['v'];
        }
        if ($v < 0) {
            throw JsonSqlDbError::syntax("$clausula necesita un número entero positivo");
        }
        return $v;
    }

    // ==================================================================
    // Expresiones (de menor a mayor precedencia)
    // ==================================================================

    private function expr(): array
    {
        return $this->exprOr();
    }

    private function exprOr(): array
    {
        $e = $this->exprAnd();
        while ($this->comer('id', 'OR')) {
            $e = ['k' => 'bin', 'op' => 'OR', 'i' => $e, 'd' => $this->exprAnd()];
        }
        return $e;
    }

    private function exprAnd(): array
    {
        $e = $this->exprNot();
        while ($this->comer('id', 'AND')) {
            $e = ['k' => 'bin', 'op' => 'AND', 'i' => $e, 'd' => $this->exprNot()];
        }
        return $e;
    }

    private function exprNot(): array
    {
        if ($this->comer('id', 'NOT')) {
            return ['k' => 'un', 'op' => 'NOT', 'e' => $this->exprNot()];
        }
        return $this->exprComparacion();
    }

    private function exprComparacion(): array
    {
        $e = $this->exprConcat();

        while (true) {
            // IS [NOT] NULL
            if ($this->es('id', 'IS')) {
                $this->avanzar();
                $not = $this->comer('id', 'NOT');
                $this->exigir('id', 'NULL');
                $e = ['k' => 'null', 'e' => $e, 'not' => $not];
                continue;
            }

            $not = false;
            if ($this->es('id', 'NOT')
                && ($this->esSiguiente('id', 'IN') || $this->esSiguiente('id', 'LIKE')
                    || $this->esSiguiente('id', 'BETWEEN'))) {
                $this->avanzar();
                $not = true;
            }

            if ($this->comer('id', 'IN')) {
                $this->exigir('punc', '(');
                if ($this->es('id', 'SELECT')) {
                    $e = ['k' => 'in', 'e' => $e, 'lista' => null, 'select' => $this->select(), 'not' => $not];
                } else {
                    $lista = [];
                    if (!$this->es('punc', ')')) {
                        do {
                            $lista[] = $this->expr();
                        } while ($this->comer('punc', ','));
                    }
                    $e = ['k' => 'in', 'e' => $e, 'lista' => $lista, 'select' => null, 'not' => $not];
                }
                $this->exigir('punc', ')');
                continue;
            }

            if ($this->comer('id', 'LIKE')) {
                $patron = $this->exprConcat();
                $escape = $this->comer('id', 'ESCAPE') ? $this->exprConcat() : null;
                $e = ['k' => 'like', 'e' => $e, 'patron' => $patron, 'escape' => $escape, 'not' => $not];
                continue;
            }

            if ($this->comer('id', 'BETWEEN')) {
                $min = $this->exprConcat();
                $this->exigir('id', 'AND');
                $max = $this->exprConcat();
                $e = ['k' => 'between', 'e' => $e, 'min' => $min, 'max' => $max, 'not' => $not];
                continue;
            }

            if ($not) {
                throw JsonSqlDbError::syntax('NOT mal colocado');
            }

            $tk = $this->actual();
            if ($tk['t'] === 'op' && in_array($tk['v'], ['=', '<>', '!=', '<', '<=', '>', '>='], true)) {
                $this->avanzar();
                $op = $tk['v'] === '!=' ? '<>' : $tk['v'];
                $e  = ['k' => 'bin', 'op' => $op, 'i' => $e, 'd' => $this->exprConcat()];
                continue;
            }

            return $e;
        }
    }

    private function exprConcat(): array
    {
        $e = $this->exprSuma();
        while ($this->es('op', '||')) {
            $this->avanzar();
            $e = ['k' => 'bin', 'op' => '||', 'i' => $e, 'd' => $this->exprSuma()];
        }
        return $e;
    }

    private function exprSuma(): array
    {
        $e = $this->exprProducto();
        while (true) {
            $tk = $this->actual();
            if ($tk['t'] === 'op' && ($tk['v'] === '+' || $tk['v'] === '-')) {
                $this->avanzar();
                $e = ['k' => 'bin', 'op' => $tk['v'], 'i' => $e, 'd' => $this->exprProducto()];
                continue;
            }
            return $e;
        }
    }

    private function exprProducto(): array
    {
        $e = $this->exprUnaria();
        while (true) {
            $tk = $this->actual();
            if ($tk['t'] === 'op' && in_array($tk['v'], ['*', '/', '%'], true)) {
                $this->avanzar();
                $e = ['k' => 'bin', 'op' => $tk['v'], 'i' => $e, 'd' => $this->exprUnaria()];
                continue;
            }
            return $e;
        }
    }

    private function exprUnaria(): array
    {
        $tk = $this->actual();
        if ($tk['t'] === 'op' && ($tk['v'] === '-' || $tk['v'] === '+')) {
            $this->avanzar();
            $e = $this->exprUnaria();
            return $tk['v'] === '-' ? ['k' => 'un', 'op' => '-', 'e' => $e] : $e;
        }
        return $this->exprPrimaria();
    }

    private function exprPrimaria(): array
    {
        $tk = $this->actual();

        if ($tk['t'] === 'num' || $tk['t'] === 'str') {
            $this->avanzar();
            return ['k' => 'lit', 'v' => $tk['v']];
        }

        if ($tk['t'] === 'param') {
            $this->avanzar();
            return ['k' => 'lit', 'v' => $this->parametro()];
        }

        if ($tk['t'] === 'punc' && $tk['v'] === '(') {
            $this->avanzar();
            if ($this->es('id', 'SELECT')) {
                $sub = ['k' => 'sub', 'select' => $this->select()];
                $this->exigir('punc', ')');
                return $sub;
            }
            $e = $this->expr();
            $this->exigir('punc', ')');
            return $e;
        }

        if ($tk['t'] === 'id') {
            if (!$tk['q']) {
                if ($tk['u'] === 'NULL')  { $this->avanzar(); return ['k' => 'lit', 'v' => null]; }
                if ($tk['u'] === 'CASE')  { return $this->exprCase(); }
                if ($tk['u'] === 'TRUE')  { $this->avanzar(); return ['k' => 'lit', 'v' => 1]; }
                if ($tk['u'] === 'FALSE') { $this->avanzar(); return ['k' => 'lit', 'v' => 0]; }
            }

            // RAISE(ABORT, 'mensaje') — solo tiene sentido dentro de un trigger
            if (!$tk['q'] && $tk['u'] === 'RAISE' && $this->esSiguiente('punc', '(')) {
                $this->avanzar();
                $this->avanzar();
                $accion = strtoupper($this->nombreSimple('acción de RAISE'));
                if (!in_array($accion, ['ABORT', 'FAIL', 'ROLLBACK', 'IGNORE'], true)) {
                    throw JsonSqlDbError::syntax("RAISE no admite '$accion'");
                }
                $mensaje = null;
                if ($this->comer('punc', ',')) {
                    $t = $this->actual();
                    if ($t['t'] !== 'str') {
                        throw JsonSqlDbError::syntax('RAISE espera un mensaje de texto');
                    }
                    $this->avanzar();
                    $mensaje = $t['v'];
                }
                $this->exigir('punc', ')');
                return ['k' => 'raise', 'accion' => $accion, 'mensaje' => $mensaje];
            }

            // Llamada a función
            if ($this->esSiguiente('punc', '(')) {
                $nombre = strtoupper($tk['v']);
                $this->avanzar();
                $this->avanzar();
                $fn = ['k' => 'fn', 'nombre' => $nombre, 'args' => [], 'star' => false, 'distinct' => false];

                if ($this->es('op', '*')) {
                    $this->avanzar();
                    $fn['star'] = true;
                } elseif (!$this->es('punc', ')')) {
                    $fn['distinct'] = $this->comer('id', 'DISTINCT');
                    do {
                        $fn['args'][] = $this->expr();
                    } while ($this->comer('punc', ','));
                }
                $this->exigir('punc', ')');
                return $fn;
            }

            // Referencia a columna: nombre  o  tabla.nombre
            $this->avanzar();
            if ($this->es('punc', '.')) {
                $this->avanzar();
                $col = $this->actual();
                if ($col['t'] !== 'id') {
                    throw JsonSqlDbError::syntax("Se esperaba un nombre de columna tras '{$tk['v']}.'");
                }
                $this->avanzar();
                return ['k' => 'col', 'tabla' => $tk['v'], 'nombre' => $col['v']];
            }
            return ['k' => 'col', 'tabla' => null, 'nombre' => $tk['v']];
        }

        throw JsonSqlDbError::syntax("Expresión no válida cerca de '" . ($tk['v'] === '' ? 'fin de consulta' : $tk['v']) . "' (línea {$tk['l']})");
    }

    private function exprCase(): array
    {
        $this->exigir('id', 'CASE');
        $caso = ['k' => 'case', 'base' => null, 'when' => [], 'else' => null];

        if (!$this->es('id', 'WHEN')) {
            $caso['base'] = $this->expr();
        }
        while ($this->comer('id', 'WHEN')) {
            $cond = $this->expr();
            $this->exigir('id', 'THEN');
            $caso['when'][] = [$cond, $this->expr()];
        }
        if ($caso['when'] === []) {
            throw JsonSqlDbError::syntax('CASE sin WHEN');
        }
        if ($this->comer('id', 'ELSE')) {
            $caso['else'] = $this->expr();
        }
        $this->exigir('id', 'END');
        return $caso;
    }

    // ==================================================================
    // INSERT / UPDATE / DELETE
    // ==================================================================

    /** INSERT INTO t [(cols)] VALUES (...)[, (...)]  |  INSERT INTO t [(cols)] SELECT ... */
    private function insert(): array
    {
        $this->exigir('id', 'INSERT');
        $this->comer('id', 'OR');            // INSERT OR REPLACE/IGNORE: se ignora el modificador
        $this->comer('id', 'REPLACE');
        $this->comer('id', 'IGNORE');
        $this->exigir('id', 'INTO');

        $ins = ['k' => 'insert', 'tabla' => $this->nombreTabla(), 'cols' => null,
                'filas' => null, 'select' => null];

        // Lista de columnas: hay que distinguirla de un VALUES sin columnas
        if ($this->es('punc', '(') && !$this->esSiguiente('id', 'SELECT')) {
            $this->avanzar();
            $ins['cols'] = [];
            do {
                $ins['cols'][] = $this->nombreSimple('columna');
            } while ($this->comer('punc', ','));
            $this->exigir('punc', ')');
        }

        if ($this->comer('id', 'VALUES')) {
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
            return $ins;
        }

        if ($this->es('id', 'SELECT')) {
            $ins['select'] = $this->select();
            return $ins;
        }
        if ($this->comer('punc', '(') && $this->es('id', 'SELECT')) {
            $ins['select'] = $this->select();
            $this->exigir('punc', ')');
            return $ins;
        }

        throw JsonSqlDbError::syntax('Se esperaba VALUES o SELECT en el INSERT');
    }

    /** UPDATE t SET c = expr [, ...] [WHERE expr] */
    private function update(): array
    {
        $this->exigir('id', 'UPDATE');
        $upd = ['k' => 'update', 'tabla' => $this->nombreTabla(), 'set' => [], 'where' => null];
        $this->exigir('id', 'SET');

        do {
            $col = $this->nombreSimple('columna');
            if (!$this->comer('op', '=')) {
                throw JsonSqlDbError::syntax("Falta '=' al asignar la columna '$col'");
            }
            $upd['set'][] = ['col' => $col, 'expr' => $this->comer('id', 'DEFAULT')
                ? ['k' => 'default']
                : $this->expr()];
        } while ($this->comer('punc', ','));

        if ($this->comer('id', 'WHERE')) {
            $upd['where'] = $this->expr();
        }
        return $upd;
    }

    /** DELETE FROM t [WHERE expr] */
    private function delete(): array
    {
        $this->exigir('id', 'DELETE');
        $this->exigir('id', 'FROM');
        $del = ['k' => 'delete', 'tabla' => $this->nombreTabla(), 'where' => null];
        if ($this->comer('id', 'WHERE')) {
            $del['where'] = $this->expr();
        }
        return $del;
    }

    // ==================================================================
    // CREATE / DROP / ALTER
    // ==================================================================

    private function create(): array
    {
        $this->exigir('id', 'CREATE');
        if ($this->comer('id', 'TRIGGER')) {
            return $this->createTrigger();
        }
        if ($this->comer('id', 'VIEW')) {
            $siNoExiste = false;
            if ($this->comer('id', 'IF')) {
                $this->exigir('id', 'NOT');
                $this->exigir('id', 'EXISTS');
                $siNoExiste = true;
            }
            $nombre = $this->nombreTabla();
            $this->exigir('id', 'AS');

            // Se guarda el texto del SELECT tal cual lo escribió el usuario:
            // así la vista se puede leer y editar, y se vuelve a analizar al
            // usarla en vez de arrastrar un árbol de una versión anterior.
            $desde = $this->actual()['p'];
            $ast   = $this->select();
            $hasta = $this->actual()['p'];
            $sql   = rtrim(trim(substr($this->sql, $desde, $hasta - $desde)), ';');

            return ['k' => 'create_view', 'nombre' => $nombre, 'sql' => $sql,
                    'select' => $ast, 'si_no_existe' => $siNoExiste];
        }
        if ($this->comer('id', 'DATABASE')) {
            $siNoExiste = false;
            if ($this->comer('id', 'IF')) {
                $this->exigir('id', 'NOT');
                $this->exigir('id', 'EXISTS');
                $siNoExiste = true;
            }
            return ['k' => 'create_database', 'base' => $this->nombreSimple('base de datos'),
                    'si_no_existe' => $siNoExiste];
        }
        $this->comer('id', 'TEMP');
        $this->comer('id', 'TEMPORARY');
        $this->exigir('id', 'TABLE');
        return $this->createTable();
    }

    /** CREATE TABLE [IF NOT EXISTS] t (definiciones) */
    private function createTable(): array
    {
        $siNoExiste = false;
        if ($this->comer('id', 'IF')) {
            $this->exigir('id', 'NOT');
            $this->exigir('id', 'EXISTS');
            $siNoExiste = true;
        }
        $tabla = $this->nombreTabla();
        $this->exigir('punc', '(');

        $def = ['columns' => [], 'unique' => [], 'foreign_keys' => []];
        do {
            if ($this->es('punc', ')')) {
                break;                                    // coma final tolerada
            }
            if ($this->esRestriccionDeTabla()) {
                $this->restriccionDeTabla($def);
                continue;
            }
            $def['columns'][] = $this->definicionColumna();
        } while ($this->comer('punc', ','));

        $this->exigir('punc', ')');
        $this->comer('id', 'WITHOUT');                    // WITHOUT ROWID: se ignora
        $this->comer('id', 'ROWID');

        return ['k' => 'create_table', 'tabla' => $tabla, 'si_no_existe' => $siNoExiste, 'def' => $def];
    }

    private function esRestriccionDeTabla(): bool
    {
        if ($this->es('id', 'CONSTRAINT') || $this->es('id', 'FOREIGN') || $this->es('id', 'CHECK')) {
            return true;
        }
        // PRIMARY KEY (...) y UNIQUE (...) a nivel de tabla llevan paréntesis detrás
        if ($this->es('id', 'PRIMARY')) {
            return true;
        }
        return $this->es('id', 'UNIQUE') && $this->esSiguiente('punc', '(');
    }

    private function restriccionDeTabla(array &$def): void
    {
        $nombre = null;
        if ($this->comer('id', 'CONSTRAINT')) {
            $nombre = $this->nombreSimple('restricción');
        }

        if ($this->comer('id', 'PRIMARY')) {
            $this->exigir('id', 'KEY');
            foreach ($this->listaColumnasEntreParentesis() as $c) {
                $def['pk'][] = $c;
            }
            return;
        }
        if ($this->comer('id', 'UNIQUE')) {
            $cols = $this->listaColumnasEntreParentesis();
            $def['unique'][] = ['name' => $nombre, 'columns' => $cols];
            return;
        }
        if ($this->comer('id', 'FOREIGN')) {
            $this->exigir('id', 'KEY');
            $cols = $this->listaColumnasEntreParentesis();
            $def['foreign_keys'][] = $this->referencias($cols, $nombre);
            return;
        }
        if ($this->comer('id', 'CHECK')) {
            throw JsonSqlDbError::syntax('CHECK no está soportado');
        }
        throw JsonSqlDbError::syntax('Restricción de tabla no reconocida');
    }

    /** columna TIPO [restricciones] */
    private function definicionColumna(): array
    {
        $col = ['name' => $this->nombreSimple('columna'), 'type' => 'TEXT',
                'notnull' => false, 'default' => null, 'pk' => false,
                'autoincrement' => false, 'unique' => false];

        // Tipo: puede llevar varias palabras y paréntesis, como DOUBLE PRECISION o VARCHAR(50)
        if ($this->es('id') && !$this->esRestriccionDeColumna()) {
            $tipo = $this->avanzar()['v'];
            while ($this->es('id') && !$this->esRestriccionDeColumna()) {
                $tipo .= ' ' . $this->avanzar()['v'];
            }
            if ($this->comer('punc', '(')) {
                $tipo .= '(' . $this->enteroPositivo('el tipo');
                if ($this->comer('punc', ',')) {
                    $tipo .= ',' . $this->enteroPositivo('el tipo');
                }
                $this->exigir('punc', ')');
                $tipo .= ')';
            }
            $col['type'] = $tipo;
        }

        while (true) {
            if ($this->comer('id', 'CONSTRAINT')) {
                $this->nombreSimple('restricción');
                continue;
            }
            if ($this->comer('id', 'PRIMARY')) {
                $this->exigir('id', 'KEY');
                $this->comer('id', 'ASC');
                $this->comer('id', 'DESC');
                $col['pk'] = true;
                if ($this->comer('id', 'AUTOINCREMENT') || $this->comer('id', 'AUTO_INCREMENT')) {
                    $col['autoincrement'] = true;
                }
                continue;
            }
            if ($this->comer('id', 'AUTOINCREMENT') || $this->comer('id', 'AUTO_INCREMENT')) {
                $col['autoincrement'] = true;
                continue;
            }
            if ($this->comer('id', 'NOT')) {
                $this->exigir('id', 'NULL');
                $col['notnull'] = true;
                continue;
            }
            if ($this->comer('id', 'NULL')) {
                continue;
            }
            if ($this->comer('id', 'UNIQUE')) {
                $col['unique'] = true;
                continue;
            }
            if ($this->comer('id', 'DEFAULT')) {
                $col['default'] = $this->valorPorDefecto();
                continue;
            }
            if ($this->es('id', 'REFERENCES')) {
                $col['references'] = $this->referencias([$col['name']], null);
                continue;
            }
            if ($this->es('id', 'CHECK') || $this->es('id', 'COLLATE') || $this->es('id', 'GENERATED')) {
                throw JsonSqlDbError::syntax("'{$this->actual()['v']}' no está soportado en una columna");
            }
            break;
        }

        return $col;
    }

    private function esRestriccionDeColumna(): bool
    {
        foreach (['PRIMARY','NOT','NULL','UNIQUE','DEFAULT','REFERENCES','CHECK','COLLATE',
                  'CONSTRAINT','AUTOINCREMENT','AUTO_INCREMENT','GENERATED'] as $p) {
            if ($this->es('id', $p)) {
                return true;
            }
        }
        return false;
    }

    /** REFERENCES tabla(cols) [ON DELETE acción] [ON UPDATE acción] */
    private function referencias(array $cols, ?string $nombre): array
    {
        $this->exigir('id', 'REFERENCES');
        $destino = $this->nombreTabla();
        $refs    = $this->es('punc', '(') ? $this->listaColumnasEntreParentesis() : [];

        $fk = ['name' => $nombre, 'columns' => $cols, 'table' => $destino, 'references' => $refs,
               'on_delete' => 'NO ACTION', 'on_update' => 'NO ACTION'];

        while ($this->comer('id', 'ON')) {
            if ($this->comer('id', 'DELETE')) {
                $fk['on_delete'] = $this->accionFk();
            } elseif ($this->comer('id', 'UPDATE')) {
                $fk['on_update'] = $this->accionFk();
            } else {
                throw JsonSqlDbError::syntax('Se esperaba ON DELETE u ON UPDATE');
            }
        }
        // Cláusulas que no cambian el resultado: se aceptan y se ignoran
        $this->comer('id', 'DEFERRABLE');
        return $fk;
    }

    private function accionFk(): string
    {
        if ($this->comer('id', 'CASCADE'))  { return 'CASCADE'; }
        if ($this->comer('id', 'RESTRICT')) { return 'RESTRICT'; }
        if ($this->comer('id', 'NO'))       { $this->exigir('id', 'ACTION'); return 'NO ACTION'; }
        if ($this->comer('id', 'SET')) {
            if ($this->comer('id', 'NULL'))    { return 'SET NULL'; }
            if ($this->comer('id', 'DEFAULT')) { return 'SET DEFAULT'; }
        }
        throw JsonSqlDbError::syntax('Acción de clave foránea no reconocida');
    }

    private function listaColumnasEntreParentesis(): array
    {
        $this->exigir('punc', '(');
        $cols = [];
        do {
            $cols[] = $this->nombreSimple('columna');
            $this->comer('id', 'ASC');
            $this->comer('id', 'DESC');
        } while ($this->comer('punc', ','));
        $this->exigir('punc', ')');
        return $cols;
    }

    /** Valor literal de un DEFAULT (no se admiten expresiones con columnas) */
    private function valorPorDefecto()
    {
        $negativo = false;
        if ($this->es('op', '-')) { $this->avanzar(); $negativo = true; }
        elseif ($this->es('op', '+')) { $this->avanzar(); }

        $tk = $this->actual();
        if ($tk['t'] === 'num') {
            $this->avanzar();
            return $negativo ? -$tk['v'] : $tk['v'];
        }
        if ($negativo) {
            throw JsonSqlDbError::syntax('DEFAULT negativo solo válido con números');
        }
        if ($tk['t'] === 'str') {
            $this->avanzar();
            return $tk['v'];
        }
        if ($tk['t'] === 'id' && !$tk['q'] && $tk['u'] === 'NULL') {
            $this->avanzar();
            return null;
        }
        if ($this->comer('punc', '(')) {
            $v = $this->valorPorDefecto();
            $this->exigir('punc', ')');
            return $v;
        }
        throw JsonSqlDbError::syntax("DEFAULT solo admite un valor fijo, y se encontró '{$tk['v']}'");
    }

    /**
     * CREATE TRIGGER [IF NOT EXISTS] n [BEFORE|AFTER] (INSERT|UPDATE|DELETE) ON t
     * [FOR EACH ROW] [WHEN expr] BEGIN sentencia; ... END
     */
    private function createTrigger(): array
    {
        $siNoExiste = false;
        if ($this->comer('id', 'IF')) {
            $this->exigir('id', 'NOT');
            $this->exigir('id', 'EXISTS');
            $siNoExiste = true;
        }
        $nombre = $this->nombreSimple('trigger');

        $timing = 'AFTER';
        if ($this->comer('id', 'BEFORE'))     { $timing = 'BEFORE'; }
        elseif ($this->comer('id', 'AFTER'))  { $timing = 'AFTER'; }
        elseif ($this->es('id', 'INSTEAD'))   { throw JsonSqlDbError::syntax('INSTEAD OF no está soportado'); }

        if ($this->comer('id', 'INSERT'))      { $evento = 'INSERT'; }
        elseif ($this->comer('id', 'DELETE'))  { $evento = 'DELETE'; }
        elseif ($this->comer('id', 'UPDATE'))  {
            $evento = 'UPDATE';
            if ($this->comer('id', 'OF')) {
                throw JsonSqlDbError::syntax('UPDATE OF columnas no está soportado en los triggers');
            }
        } else {
            throw JsonSqlDbError::syntax('El trigger debe indicar INSERT, UPDATE o DELETE');
        }

        $this->exigir('id', 'ON');
        $tabla = $this->nombreTabla();

        if ($this->comer('id', 'FOR')) {
            $this->exigir('id', 'EACH');
            $this->exigir('id', 'ROW');
        }

        $cuando = null;
        if ($this->comer('id', 'WHEN')) {
            $ini = $this->actual()['p'];
            $this->expr();
            $cuando = $this->textoEntre($ini, $this->actual()['p']);
        }

        $this->exigir('id', 'BEGIN');
        $cuerpo = [];
        while (!$this->es('id', 'END')) {
            if ($this->es('eof')) {
                throw JsonSqlDbError::syntax('Falta el END del trigger');
            }
            $ini = $this->actual()['p'];
            $this->sentencia();
            $cuerpo[] = $this->textoEntre($ini, $this->actual()['p']);
            if (!$this->comer('punc', ';') && !$this->es('id', 'END')) {
                throw JsonSqlDbError::syntax('Falta el ; entre las sentencias del trigger');
            }
        }
        $this->exigir('id', 'END');

        if ($cuerpo === []) {
            throw JsonSqlDbError::syntax("El trigger '$nombre' no tiene sentencias");
        }

        return ['k' => 'create_trigger', 'tabla' => $tabla, 'si_no_existe' => $siNoExiste,
                'trg' => ['name' => $nombre, 'timing' => $timing, 'event' => $evento,
                          'when' => $cuando, 'body' => $cuerpo, 'sql' => trim($this->sql)]];
    }

    private function drop(): array
    {
        $this->exigir('id', 'DROP');

        if ($this->comer('id', 'TRIGGER')) {
            $siExiste = $this->siExiste();
            return ['k' => 'drop_trigger', 'nombre' => $this->nombreSimple('trigger'), 'si_existe' => $siExiste];
        }
        if ($this->comer('id', 'VIEW')) {
            $siExiste = $this->siExiste();
            return ['k' => 'drop_view', 'nombre' => $this->nombreTabla(), 'si_existe' => $siExiste];
        }
        if ($this->comer('id', 'DATABASE')) {
            $siExiste = $this->siExiste();
            return ['k' => 'drop_database', 'base' => $this->nombreSimple('base de datos'),
                    'si_existe' => $siExiste];
        }
        $this->exigir('id', 'TABLE');
        $siExiste = $this->siExiste();
        return ['k' => 'drop_table', 'tabla' => $this->nombreTabla(), 'si_existe' => $siExiste];
    }

    private function siExiste(): bool
    {
        if ($this->comer('id', 'IF')) {
            $this->exigir('id', 'EXISTS');
            return true;
        }
        return false;
    }

    /**
     * SHOW DATABASES | TABLES | SCHEMA t | COLUMNS FROM t | KEYS FROM t
     *      | TRIGGERS [FROM t]
     */
    private function show(): array
    {
        $this->exigir('id', 'SHOW');
        $tk = $this->actual();
        if ($tk['t'] !== 'id') {
            throw JsonSqlDbError::syntax(
                'SHOW necesita DATABASES, TABLES, VIEWS, SCHEMA, COLUMNS, KEYS o TRIGGERS');
        }
        $que = $tk['u'];
        $this->avanzar();

        switch ($que) {
            case 'DATABASES':
                return ['k' => 'show_databases'];
            case 'TABLES':
                return ['k' => 'show_tables'];
            case 'VIEWS':
                return ['k' => 'show_views'];
            case 'SCHEMA':
            case 'COLUMNS':
                $this->comer('id', 'FROM');
                return ['k' => 'show_schema', 'tabla' => $this->nombreTabla()];
            case 'KEYS':
                $this->comer('id', 'FROM');
                return ['k' => 'show_keys', 'tabla' => $this->nombreTabla()];
            case 'TRIGGERS':
                $tabla = $this->comer('id', 'FROM') ? $this->nombreTabla() : null;
                return ['k' => 'show_triggers', 'tabla' => $tabla];
        }
        throw JsonSqlDbError::syntax("SHOW no admite '{$tk['v']}'");
    }

    /** ALTER TABLE t ADD/DROP/RENAME */
    private function alter(): array
    {
        $this->exigir('id', 'ALTER');
        $this->exigir('id', 'TABLE');
        $tabla = $this->nombreTabla();

        if ($this->comer('id', 'ADD')) {
            if ($this->esRestriccionDeTabla()) {
                $def = ['pk' => [], 'unique' => [], 'foreign_keys' => []];
                $this->restriccionDeTabla($def);
                if ($def['pk'] !== []) {
                    return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'add_pk',
                            'columnas' => $def['pk']];
                }
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'add_constraint',
                        'unique' => $def['unique'], 'foreign_keys' => $def['foreign_keys']];
            }
            $this->comer('id', 'COLUMN');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'add',
                    'def' => $this->definicionColumna()];
        }
        if ($this->comer('id', 'MODIFY') || $this->comer('id', 'CHANGE')) {
            $this->comer('id', 'COLUMN');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'modify',
                    'def' => $this->definicionColumna()];
        }
        if ($this->comer('id', 'DROP')) {
            if ($this->comer('id', 'PRIMARY')) {
                $this->exigir('id', 'KEY');
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'drop_pk'];
            }
            if ($this->comer('id', 'CONSTRAINT')) {
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'drop_constraint',
                        'nombre' => $this->nombreSimple('restricción')];
            }
            $this->comer('id', 'COLUMN');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'drop',
                    'col' => $this->nombreSimple('columna')];
        }
        if ($this->comer('id', 'RENAME')) {
            if ($this->comer('id', 'TO')) {
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'rename',
                        'nuevo' => $this->nombreTabla()];
            }
            $this->comer('id', 'COLUMN');
            $col = $this->nombreSimple('columna');
            $this->exigir('id', 'TO');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'rename_col',
                    'col' => $col, 'nuevo' => $this->nombreSimple('columna')];
        }
        throw JsonSqlDbError::syntax('ALTER TABLE solo admite ADD, MODIFY, DROP o RENAME');
    }

    // ==================================================================
    // Auxiliares comunes
    // ==================================================================

    private function nombreTabla(): string
    {
        $tk = $this->actual();
        if ($tk['t'] !== 'id' || (!$tk['q'] && in_array($tk['u'], self::RESERVADAS, true))) {
            throw JsonSqlDbError::syntax("Se esperaba un nombre de tabla y se encontró '{$tk['v']}'");
        }
        $this->avanzar();
        // Prefijo de esquema (main.tabla): se acepta y se descarta
        if ($this->es('punc', '.')) {
            $this->avanzar();
            return $this->nombreSimple('tabla');
        }
        return $tk['v'];
    }

    private function nombreSimple(string $que): string
    {
        $tk = $this->actual();
        if ($tk['t'] !== 'id') {
            throw JsonSqlDbError::syntax("Se esperaba un nombre de $que y se encontró '{$tk['v']}'");
        }
        $this->avanzar();
        return $tk['v'];
    }

    private function textoEntre(int $desde, int $hasta): string
    {
        return rtrim(trim(substr($this->sql, $desde, $hasta - $desde)), ';');
    }

    // ==================================================================
    // Utilidades sobre el flujo de tokens
    // ==================================================================

    private function actual(): array
    {
        return $this->t[$this->i];
    }

    private function avanzar(): array
    {
        return $this->t[$this->i++];
    }

    private function es(string $tipo, ?string $valor = null): bool
    {
        $tk = $this->t[$this->i];
        if ($tk['t'] !== $tipo) {
            return false;
        }
        if ($valor === null) {
            return true;
        }
        return $tipo === 'id' ? (!$tk['q'] && $tk['u'] === $valor) : $tk['v'] === $valor;
    }

    private function esSiguiente(string $tipo, string $valor, int $salto = 1): bool
    {
        $tk = $this->t[$this->i + $salto] ?? null;
        if ($tk === null || $tk['t'] !== $tipo) {
            return false;
        }
        return $tipo === 'id' ? (!$tk['q'] && $tk['u'] === $valor) : $tk['v'] === $valor;
    }

    private function comer(string $tipo, string $valor): bool
    {
        if ($this->es($tipo, $valor)) {
            $this->i++;
            return true;
        }
        return false;
    }

    private function exigir(string $tipo, string $valor): void
    {
        if (!$this->comer($tipo, $valor)) {
            $tk = $this->actual();
            $enc = $tk['v'] === '' ? 'fin de consulta' : $tk['v'];
            throw JsonSqlDbError::syntax("Se esperaba '$valor' y se encontró '$enc' (línea {$tk['l']})");
        }
    }
}
