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
 *  ['k'=>'like','e'=>AST,'patron'=>AST,'escape'=>?AST,'not'=>bool,'glob'=>?true]
 *  ['k'=>'regexp','e'=>AST,'patron'=>AST,'not'=>bool]
 *  ['k'=>'cast','e'=>AST,'tipo'=>string]
 *  ['k'=>'null','e'=>AST,'not'=>bool]           IS NULL / IS NOT NULL
 *  ['k'=>'sub','select'=>AST]                   subconsulta escalar
 *
 * Las comparaciones (= <> < … IS, IN y BETWEEN) llevan además 'escrita': su
 * colación escrita con COLLATE, o '' (2.9, ver colacionEscrita()).
 *
 * Aquí, las expresiones y el FROM; el resto del SELECT, en ParserConsulta;
 * INSERT, en ParserEscritura; UPDATE y DELETE, en ParserCambios (2.10), y el
 * resto de sentencias en ParserSentencias, que heredan de esta clase y solo se
 * cargan cuando hacen falta (2.9): sin OPcache, PHP compila en cada petición
 * lo que carga.
 *
 * https://miguelenred.es/jsonsqldb
 */
/**
 * @phpstan-import-type Token from Lexer
 */
class Parser
{
    /** Dentro de ON DUPLICATE KEY UPDATE, VALUES(col) es el valor propuesto */
    protected bool $valoresPropuestos = false;

    /** Las palabras de la fecha de ahora, sin paréntesis o con ellos vacíos */
    private const AHORA = ['CURRENT_TIMESTAMP' => 'DATETIME', 'CURRENT_DATE' => 'DATE', 'CURRENT_TIME' => 'TIME',
                           'LOCALTIME' => 'DATETIME', 'LOCALTIMESTAMP' => 'DATETIME',
                           'UTC_DATE' => 'UTC_DATE', 'UTC_TIME' => 'UTC_TIME', 'UTC_TIMESTAMP' => 'UTC_TIMESTAMP'];
    /** …y las funciones de MySQL que dicen lo mismo */
    // Las funciones que lleva ParserFunciones (2.10)
    private const CON_PALABRAS = ['TRIM' => 1, 'POSITION' => 1, 'SUBSTRING' => 1, 'SUBSTR' => 1, 'EXTRACT' => 1, 'TIMESTAMPDIFF' => 1,
        'TIMESTAMPADD' => 1, 'DATE_ADD' => 1, 'DATE_SUB' => 1, 'ADDDATE' => 1, 'SUBDATE' => 1, 'CONVERT' => 1];
    private const AHORA_MYSQL = ['NOW' => 'DATETIME', 'SYSDATE' => 'DATETIME', 'CURDATE' => 'DATE', 'CURTIME' => 'TIME'];

    /** Las palabras que pueden seguir a un valor dentro de una comparación (ver exprComparacion()) */
    private const SIGUE_COMPARACION = ['IS' => 1, 'NOT' => 1, 'IN' => 1, 'REGEXP' => 1, 'RLIKE' => 1, 'GLOB' => 1, 'LIKE' => 1, 'BETWEEN' => 1];

    /** Palabras que nunca se toman como alias sin AS */
    protected const RESERVADAS = [
        'FROM','WHERE','GROUP','ORDER','HAVING','LIMIT','OFFSET','JOIN','INNER','LEFT','RIGHT','UNION','INTERSECT','EXCEPT','WITH','RECURSIVE',
        'FULL','CROSS','OUTER','ON','AS','AND','OR','NOT','IN','IS','LIKE','REGEXP','RLIKE','BETWEEN','CASE','CAST','WHEN',
        'THEN','ELSE','END','SELECT','DISTINCT','ALL','ASC','DESC','BY','NULL','UNION','VALUES','SET','RETURNING',
        'USING','NATURAL','XOR','DIV','NULLS','FOR','LOCK','GLOB',
    ];

    /** @var list<Token> */
    protected array  $t;
    protected int    $i = 0;
    protected string $sql;
    private array  $params;
    private int    $iParam = 0;
    /** ¿Llama la sentencia a RANDOMBLOB o ZEROBLOB? (ver WriterAvanzado::exigirTexto()) */
    private bool   $conBytes = false;
    /** ¿Hay alguna subconsulta en una expresión? (ver Database::tablaUnica() y sinFila()) */
    protected bool   $conSubconsulta = false;
    /** ¿Se ha leído ya algún x COLLATE …? Sin él no hay colación escrita que buscar (ver escrita()) */
    private bool   $conColacion = false;
    /** Subconsultas del FROM sin alias, para darles uno (ver origen()) */
    protected int    $sinAlias = 0;
    /** ¿Se admiten funciones de ventana aquí? Solo en las columnas de un SELECT y en su ORDER BY */
    protected bool   $ventanasPermitidas = false;
    /** Funciones de ventana del SELECT que se está analizando (ver selectSimple()) */
    protected int    $ventanasVistas = 0;
    /** Nombre escrito de una tabla => el suyo de verdad (ver real()) */
    private ?\Closure $nombres = null;
    /** Los nombres de los WITH leídos, en minúsculas: no son tablas (ver real()) */
    protected array  $ctes = [];
    /** Un valor ya leído con el que sigue exprConcat(): el IN (…) tras el que viene un || o un + (ver exprComparacion()) */
    private ?array $izq = null;
    private const RELACIONALES = ['<' => 1, '<=' => 1, '>' => 1, '>=' => 1];

    /** Las funciones que solo son de ventana (2.9), con su mínimo y su máximo de argumentos */
    protected const VENTANA = ['ROW_NUMBER' => [0, 0], 'RANK' => [0, 0], 'DENSE_RANK' => [0, 0], 'PERCENT_RANK' => [0, 0],
        'CUME_DIST' => [0, 0], 'NTILE' => [1, 1], 'LAG' => [1, 3], 'LEAD' => [1, 3], 'FIRST_VALUE' => [1, 1],
        'LAST_VALUE' => [1, 1], 'NTH_VALUE' => [2, 2]];

    protected function __construct(array $tokens, string $sql, array $params)
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
     * @param \Closure|null $nombres el nombre de verdad de una tabla escrita sin
     *                               distinguir mayúsculas (ver Catalog::nombreReal())
     */
    public static function analizar(string $sql, array $params = [], ?\Closure $nombres = null): array
    {
        $t   = Lexer::tokens($sql);
        $p   = match ($t[0]['t'] === 'id' && !$t[0]['q'] ? $t[0]['u'] : '') {
            'SELECT', 'WITH'                       => new self($t, $sql, array_values($params)),
            'INSERT', 'REPLACE', 'UPDATE', 'DELETE' => new ParserEscritura($t, $sql, array_values($params)),
            default                                 => new ParserSentencias($t, $sql, array_values($params)),
        };
        $p->nombres = $nombres;
        $ast = $p->sentencia();
        while ($p->comer('punc', ';')) {
            // Como en SQLite, el ; del final puede ir repetido
        }
        if (!$p->es('eof')) {
            // Lo que sobra, con lo primero que sobra (2.10: sin decir dónde,
            // y con un FROM a, b USING o un 1 IS NULL + 1 no se entendía)
            throw JsonSqlDbError::syntax("Sobra lo que va desde '" . $p->actual()['v'] . "': solo se admite una sentencia por consulta");
        }
        if ($p->iParam !== \count($p->params)) {
            throw JsonSqlDbError::syntax(
                'La sentencia tiene ' . $p->iParam . ' marcadores ? y se han recibido ' . \count($p->params) . ' parámetros'
            );
        }
        if ($p->conBytes) {
            $ast['bytes'] = true;
        }
        if ($p->conSubconsulta) {
            $ast['subconsultas'] = true;
        }
        return $ast;
    }

    /** Valor del siguiente ? de la sentencia. */
    private function parametro()
    {
        if (!\array_key_exists($this->iParam, $this->params)) {
            throw JsonSqlDbError::syntax('Faltan parámetros para los marcadores ? de la sentencia');
        }
        $v = $this->params[$this->iParam++];
        if ($v === null || \is_int($v) || \is_float($v) || \is_string($v)) {
            return $v;
        }
        if (\is_bool($v)) {
            return $v ? 1 : 0;
        }
        throw JsonSqlDbError::syntax('El parámetro ' . $this->iParam . ' no es un valor simple');
    }

    /**
     * Una consulta (SELECT o WITH): las demás sentencias las analiza
     * ParserSentencias (ver analizar()).
     */
    protected function sentencia(): array
    {
        return ParserConsulta::select($this);
    }

    // ==================================================================
    // FROM, alias y enteros literales (el resto del SELECT, en ParserConsulta)
    // ==================================================================

    /** @param array|null $primero el primer origen, si ya está leído (UPDATE y DELETE con JOIN) */
    protected function from(?array $primero = null): array
    {
        $from   = [$primero ?? ParserConsulta::origen($this, null)];
        while (true) {
            if ($this->comer('punc', ',')) {
                $origen = ParserConsulta::origen($this, 'CROSS');
                // Con USING u ON detrás, una coma es un JOIN, como en SQLite
                // (2.10: «solo se admite una sentencia»)
                if ($this->comer('id', 'USING')) {
                    $origen['join']  = 'INNER';
                    $origen['using'] = $this->listaColumnasEntreParentesis();
                } elseif ($this->comer('id', 'ON')) {
                    $origen['join'] = 'INNER';
                    $origen['on']   = $this->expr();
                }
                $from[] = $origen;
                continue;
            }
            $tipo = null;
            $natural = $this->comer('id', 'NATURAL');            // 2.9
            if ($this->es('id', 'INNER'))      { $this->avanzar(); $tipo = 'INNER'; }
            elseif ($this->es('id', 'LEFT'))   { $this->avanzar(); $this->comer('id', 'OUTER'); $tipo = 'LEFT'; }
            elseif ($this->es('id', 'RIGHT'))  { $this->avanzar(); $this->comer('id', 'OUTER'); $tipo = 'RIGHT'; }
            elseif ($this->es('id', 'CROSS'))  { $this->avanzar(); $tipo = 'CROSS'; }
            elseif ($this->es('id', 'FULL'))   {
                $this->avanzar(); $this->comer('id', 'OUTER'); $tipo = 'FULL';
            }
            elseif ($this->es('id', 'JOIN'))   { $tipo = 'INNER'; }

            if ($tipo === null) {
                if ($natural) {
                    throw JsonSqlDbError::syntax('NATURAL tiene que ir delante de un JOIN');
                }
                break;
            }
            $this->exigir('id', 'JOIN');
            $origen = ParserConsulta::origen($this, $tipo);

            // NATURAL JOIN y JOIN … USING (cols) de SQLite y MySQL (2.9): el ON
            // lo monta Select, que sabe qué columnas tiene cada lado
            if ($natural || $this->es('id', 'USING')) {
                if ($tipo === 'CROSS' || $tipo === 'FULL') {
                    throw JsonSqlDbError::syntax("USING y NATURAL no están soportados con $tipo JOIN");
                }
                if ($natural) {
                    $origen['natural'] = true;
                } else {
                    $this->avanzar();
                    $origen['using'] = $this->listaColumnasEntreParentesis();
                }
            } elseif ($this->comer('id', 'ON')) {
                $origen['on'] = $this->expr();
            } elseif ($tipo !== 'CROSS') {
                throw JsonSqlDbError::syntax("Falta la condición ON del $tipo JOIN");
            }
            $from[] = $origen;
        }
        return $from;
    }

    /** [AS] alias — devuelve null si no hay */
    protected function alias(): ?string
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
        if ($tk['t'] === 'id' && ($tk['q'] || !\in_array($tk['u'], self::RESERVADAS, true))
            && !(!$tk['q'] && $tk['u'] === 'WINDOW' && $this->esSiguiente('id', 'AS', 2))) {
            $this->avanzar();
            return $tk['v'];
        }
        return null;
    }

    /** Un entero positivo literal o un ?: longitudes y decimales de un tipo. */
    protected function enteroPositivo(string $clausula): int
    {
        $tk = $this->actual();
        if ($tk['t'] === 'param') {
            $this->avanzar();
            $v = $this->parametro();
            if (!\is_int($v) && !(\is_string($v) && ctype_digit($v))) {
                throw JsonSqlDbError::syntax("$clausula necesita un número entero positivo");
            }
            $v = (int)$v;
        } else {
            if ($tk['t'] !== 'num' || !\is_int($tk['v'])) {
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

    protected function expr(): array
    {
        // Un valor suelto (un literal, un ?, una columna) seguido de una coma,
        // un paréntesis, FROM, AS o el final, que es lo más frecuente en una
        // lista de columnas, un VALUES o un IN: sin bajar por todos los
        // niveles, que darían lo mismo (2.10, antes de publicarla: con su nivel
        // de más, un SELECT 1 hacía más que en la 2.9). NOT es de exprNot(), y
        // CASE e INTERVAL siguen después de su palabra (CASE AS WHEN …, con una
        // columna llamada AS): lo de detrás de su END o de su unidad se perdería
        $tk  = $this->t[$this->i];
        $sig = $this->t[$this->i + 1] ?? $tk;
        if ($this->izq === null && (($sig['t'] === 'punc' && ($sig['v'] === ',' || $sig['v'] === ')')) || $sig['t'] === 'eof'
                || ($sig['t'] === 'id' && !$sig['q'] && ($sig['u'] === 'FROM' || $sig['u'] === 'AS')))) {
            if ($tk['t'] === 'num' || $tk['t'] === 'str') {
                $this->i++;
                return ['k' => 'lit', 'v' => $tk['v']];
            }
            if ($tk['t'] === 'param' || ($tk['t'] === 'id' && ($tk['q'] || ($tk['u'] !== 'NOT' && $tk['u'] !== 'CASE' && $tk['u'] !== 'INTERVAL')))) {
                return $this->exprPrimaria();
            }
        }
        return $this->exprOr();
    }

    // Cada valor de una expresión baja por todos los niveles de precedencia:
    // en ellos se mira el token directamente, sin llamar a comer() ni a es(),
    // que es lo que más pesa al analizar (2.9)

    private function exprOr(): array
    {
        $e = $this->exprXor();
        while (($tk = $this->t[$this->i])['t'] === 'id' && $tk['u'] === 'OR' && !$tk['q']) {
            $this->i++;
            $e = ['k' => 'bin', 'op' => 'OR', 'i' => $e, 'd' => $this->exprXor()];
        }
        return $e;
    }

    /** XOR de MySQL (2.9): entre OR y AND, como allí */
    private function exprXor(): array
    {
        $e = $this->exprAnd();
        while (($tk = $this->t[$this->i])['t'] === 'id' && $tk['u'] === 'XOR' && !$tk['q']) {
            $this->i++;
            $e = ['k' => 'bin', 'op' => 'XOR', 'i' => $e, 'd' => $this->exprAnd()];
        }
        return $e;
    }

    private function exprAnd(): array
    {
        $e = $this->exprNot();
        while (($tk = $this->t[$this->i])['t'] === 'id' && $tk['u'] === 'AND' && !$tk['q']) {
            $this->i++;
            $e = ['k' => 'bin', 'op' => 'AND', 'i' => $e, 'd' => $this->exprNot()];
        }
        return $e;
    }

    private function exprNot(): array
    {
        $tk = $this->t[$this->i];
        if ($tk['t'] === 'id' && $tk['u'] === 'NOT' && !$tk['q']) {
            $this->i++;
            return ['k' => 'un', 'op' => 'NOT', 'e' => $this->exprNot()];
        }
        return $this->exprComparacion();
    }

    /**
     * El nivel de =, IS, IN, LIKE, GLOB, REGEXP y BETWEEN, por debajo de
     * < <= > >= (ver exprRelacional()), como en SQLite (2.10: todos iban al
     * mismo nivel, y 0 = 1 < 2 daba 1 donde SQLite da 0). Lo de la derecha de
     * cada uno es del nivel de arriba, y tras un IN (…) cerrado puede seguir
     * un operador de más precedencia: 2 IN (2) || 0 es (2 IN (2)) || 0.
     */
    private function exprComparacion(): array
    {
        // < <= > >= (ver exprRelacional()), aquí mismo: todo valor pasa por
        // aquí, y casi ninguno lleva uno detrás
        $e = $this->exprBits();
        if (($tk = $this->t[$this->i])['t'] === 'op' && isset(self::RELACIONALES[$tk['v']])) {
            $e = $this->relacional($e);
        }

        while (true) {
            // Lo más habitual tras un valor es una coma, un paréntesis o una
            // palabra que no sigue la comparación: entonces se sale sin probar
            // una a una todas las que sí (el resultado es el mismo)
            $tk = $this->t[$this->i];
            if ($tk['t'] !== 'op' && ($tk['t'] !== 'id' || $tk['q'] || !isset(self::SIGUE_COMPARACION[$tk['u']]))) {
                return $e;
            }
            // IS [NOT] NULL; y (2.9) IS [NOT] valor de SQLite e IS [NOT] DISTINCT
            // FROM del estándar: el = que trata NULL como un valor más, con la
            // colación escrita como el = (2.10: 'a' IS 'A' COLLATE NOCASE daba 0).
            // Lo de la derecha es una expresión: 1 IS NULL + 1 es 1 IS (NULL + 1)
            if ($this->es('id', 'IS')) {
                $this->avanzar();
                $not = $this->comer('id', 'NOT');
                if ($this->comer('id', 'DISTINCT')) {
                    $this->exigir('id', 'FROM');
                    $not = !$not;
                }
                $esNull = $this->es('id', 'NULL');
                $d = $this->exprRelacional();
                if ($esNull && $d === ['k' => 'lit', 'v' => null]) {
                    $e = ['k' => 'null', 'e' => $e, 'not' => $not];
                    continue;
                }
                [$i, $d] = $this->plegar([$e, $d], $c);
                $e = ['k' => 'bin', 'op' => '<=>', 'i' => $i, 'd' => $d, 'escrita' => $c];
                if ($not) {
                    $e = ['k' => 'un', 'op' => 'NOT', 'e' => $e];
                }
                continue;
            }

            $not = false;
            if ($this->es('id', 'NOT')
                && ($this->esSiguiente('id', 'IN') || $this->esSiguiente('id', 'LIKE')
                    || $this->esSiguiente('id', 'BETWEEN') || $this->esSiguiente('id', 'REGEXP')
                    || $this->esSiguiente('id', 'RLIKE') || $this->esSiguiente('id', 'GLOB'))) {
                $this->avanzar();
                $not = true;
            }

            if ($this->comer('id', 'IN')) {
                $this->exigir('punc', '(');
                if ($this->es('id', 'SELECT') || $this->es('id', 'WITH')) {
                    $e = ['k' => 'in', 'e' => $e, 'lista' => null, 'select' => ParserConsulta::select($this), 'not' => $not, 'escrita' => $this->escrita([$e])];
                    $this->conSubconsulta = true;
                } else {
                    $lista = [];
                    if (!$this->es('punc', ')')) {
                        do {
                            $lista[] = $this->expr();
                        } while ($this->comer('punc', ','));
                    }
                    $plegados = $this->plegar(array_merge([$e], $lista), $c);
                    $e = ['k' => 'in', 'e' => array_shift($plegados), 'lista' => $plegados, 'select' => null, 'not' => $not, 'escrita' => $c];
                }
                $this->exigir('punc', ')');
                $this->izq = $e;                    // lo que siga, sobre el IN (ver exprConcat())
                $e = $this->exprRelacional();
                continue;
            }

            if ($this->comer('id', 'REGEXP') || $this->comer('id', 'RLIKE')) {
                $e = ['k' => 'regexp', 'e' => $e, 'patron' => $this->exprRelacional(), 'not' => $not];
                continue;
            }

            // x GLOB patrón de SQLite: como LIKE, con * ? y [ ], y distinguiendo mayúsculas
            if ($this->comer('id', 'GLOB')) {
                $e = ['k' => 'like', 'e' => $e, 'patron' => $this->exprRelacional(), 'escape' => null, 'not' => $not, 'glob' => true];
                continue;
            }

            if ($this->comer('id', 'LIKE')) {
                $patron = $this->exprRelacional();
                $escape = $this->comer('id', 'ESCAPE') ? $this->exprBits() : null;
                $e = ['k' => 'like', 'e' => $e, 'patron' => $patron, 'escape' => $escape, 'not' => $not];
                continue;
            }

            if ($this->comer('id', 'BETWEEN')) {
                $min = $this->exprRelacional();
                $this->exigir('id', 'AND');
                [$e, $min, $max] = $this->plegar([$e, $min, $this->exprRelacional()], $c);
                $e = ['k' => 'between', 'e' => $e, 'min' => $min, 'max' => $max, 'not' => $not, 'escrita' => $c];
                continue;
            }

            if ($not) {
                throw JsonSqlDbError::syntax('NOT mal colocado');
            }

            $tk = $this->actual();
            if ($tk['t'] === 'op' && \in_array($tk['v'], ['=', '==', '<>', '!=', '<=>'], true)) {
                $this->avanzar();
                $op = ['!=' => '<>', '==' => '='][$tk['v']] ?? $tk['v'];
                [$i, $d] = $this->plegar([$e, $this->exprRelacional()], $c);
                $e  = ['k' => 'bin', 'op' => $op, 'i' => $i, 'd' => $d, 'escrita' => $c];
                continue;
            }

            return $e;
        }
    }

    /** < <= > >=, entre la igualdad y los bits, como en SQLite (2.10) */
    private function exprRelacional(): array
    {
        $e = $this->exprBits();
        return ($tk = $this->t[$this->i])['t'] === 'op' && isset(self::RELACIONALES[$tk['v']]) ? $this->relacional($e) : $e;
    }

    /** Los < <= > >= que siguen a $e */
    private function relacional(array $e): array
    {
        while (($tk = $this->t[$this->i])['t'] === 'op' && isset(self::RELACIONALES[$tk['v']])) {
            $this->i++;
            [$i, $d] = $this->plegar([$e, $this->exprBits()], $c);
            $e = ['k' => 'bin', 'op' => $tk['v'], 'i' => $i, 'd' => $d, 'escrita' => $c];
        }
        return $e;
    }

    /** Operadores de bits (2.9): << >> & |, entre la comparación y la suma, como en SQLite */
    protected function exprBits(): array
    {
        $e = $this->exprSuma();
        while (true) {
            $tk = $this->t[$this->i];
            if ($tk['t'] !== 'op' || ($tk['v'] !== '&' && $tk['v'] !== '|' && $tk['v'] !== '<<' && $tk['v'] !== '>>')) {
                return $e;
            }
            $this->avanzar();
            $e = ['k' => 'bin', 'op' => $tk['v'], 'i' => $e, 'd' => $this->exprSuma()];
        }
    }

    private function exprSuma(): array
    {
        $e = $this->exprProducto();
        while (true) {
            $tk = $this->t[$this->i];
            if ($tk['t'] === 'op' && ($tk['v'] === '+' || $tk['v'] === '-')) {
                $this->avanzar();
                $e = self::conIntervalo(['k' => 'bin', 'op' => $tk['v'], 'i' => $e, 'd' => $this->exprProducto()]);
                continue;
            }
            return $e;
        }
    }

    private function exprProducto(): array
    {
        $e = $this->exprConcat();
        while (true) {
            $tk = $this->actual();
            // DIV (división entera) y MOD de MySQL (2.9)
            if ($tk['t'] === 'id' && !$tk['q'] && ($tk['u'] === 'DIV' || $tk['u'] === 'MOD')) {
                $this->avanzar();
                $e = ['k' => 'bin', 'op' => $tk['u'] === 'DIV' ? 'DIV' : '%', 'i' => $e, 'd' => $this->exprConcat()];
                continue;
            }
            if ($tk['t'] === 'op' && \in_array($tk['v'], ['*', '/', '%'], true)) {
                $this->avanzar();
                $e = ['k' => 'bin', 'op' => $tk['v'], 'i' => $e, 'd' => $this->exprConcat()];
                continue;
            }
            return $e;
        }
    }

    /**
     * || y, al mismo nivel, x -> ruta y x ->> ruta (SQLite 3.38 y MySQL; ver
     * Functions::masCompatibles()): por encima de * / % y de + -, como en
     * SQLite (2.9: el || iba por debajo de la suma, como en PostgreSQL, y
     * 1 + 2 || 3 daba '33' donde SQLite da 24)
     */
    private function exprConcat(): array
    {
        if ($this->izq !== null) {
            $e = $this->izq;                        // lo ya leído (ver exprComparacion())
            $this->izq = null;
        } else {
            $e = $this->exprUnaria();
        }
        while (($tk = $this->t[$this->i])['t'] === 'op' && ($tk['v'] === '||' || $tk['v'] === '->' || $tk['v'] === '->>')) {
            $this->i++;
            $d = $this->exprUnaria();
            $e = $tk['v'] === '||' ? ['k' => 'bin', 'op' => '||', 'i' => $e, 'd' => $d]
                : ['k' => 'fn', 'nombre' => $tk['v'], 'args' => [$e, $d], 'star' => false, 'distinct' => false];
        }
        return $e;
    }

    private function exprUnaria(): array
    {
        $tk = $this->actual();
        if ($tk['t'] === 'op' && $tk['v'] === '~') {
            $this->avanzar();
            return ['k' => 'un', 'op' => '~', 'e' => $this->exprUnaria()];
        }
        if ($tk['t'] === 'op' && ($tk['v'] === '-' || $tk['v'] === '+')) {
            $this->avanzar();
            // -9223372036854775808 es el entero más pequeño: el número solo no cabe
            $sig = $this->actual();
            if ($tk['v'] === '-' && $sig['t'] === 'num' && ltrim((string)($sig['texto'] ?? ''), '0') === '9223372036854775808') {
                $this->avanzar();
                return ['k' => 'lit', 'v' => PHP_INT_MIN];
            }
            $e = $this->exprUnaria();
            return $tk['v'] === '-' ? ['k' => 'un', 'op' => '-', 'e' => $e] : $e;
        }
        $e = $this->exprPrimaria();
        // x COLLATE NOCASE | RTRIM | BINARY (2.9, como SQLite): en sí no cambia
        // el valor; lo que cambia es cómo se compara y se ordena (ver plegar())
        while (($tk = $this->t[$this->i])['t'] === 'id' && $tk['u'] === 'COLLATE' && !$tk['q']) {
            $this->i++;
            $c = strtoupper($this->nombreSimple('colación'));
            if (!\in_array($c, ['NOCASE', 'RTRIM', 'BINARY'], true)) {
                throw JsonSqlDbError::syntax("COLLATE $c no está soportado: NOCASE, RTRIM o BINARY");
            }
            $e = ['k' => 'fn', 'nombre' => 'COLLATE', 'args' => [$e, ['k' => 'lit', 'v' => $c]], 'star' => false, 'distinct' => false];
            $this->conColacion = true;
        }
        return $e;
    }

    /**
     * La colación escrita con COLLATE en una expresión (NOCASE, RTRIM o
     * BINARY), o null. Como en SQLite, vale también la de dentro (`b || 'x'
     * COLLATE NOCASE`): la primera empezando por la izquierda, sin entrar en
     * subconsultas ni en lo que es de una ventana. Una comparación la lleva
     * ya calculada en 'escrita' ('' si no tiene), y no se recorre (2.9).
     */
    public static function colacionEscrita(array $n): ?string
    {
        $k = $n['k'] ?? '';
        if ($k === 'col' || $k === 'lit' || $k === 'sub' || $k === 'exists' || $k === 'ventana') {
            return null;                    // lo más frecuente, sin recorrerlo
        }
        if ($k === 'fn' && ($n['nombre'] ?? '') === 'COLLATE') {
            return (string)$n['args'][1]['v'];
        }
        if (isset($n['escrita'])) {
            return $n['escrita'] === '' ? null : $n['escrita'];
        }
        foreach ($n as $clave => $v) {
            if (\is_array($v) && $clave !== 'select' && $clave !== 'filtro' && $clave !== 'orden'
                && ($c = self::colacionEscrita($v)) !== null) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Para comparar u ordenar con una colación: si alguno de los operandos la
     * lleva (como en SQLite, la de la izquierda manda), todos pasan por
     * PLEGAR, que da el valor que se compara; con BINARY, o sin colación,
     * quedan como estaban. En $c queda esa colación (ver escrita()).
     *
     * @param list<array> $ops
     * @return list<array>
     */
    protected function plegar(array $ops, ?string &$c = null): array
    {
        $c = $this->escrita($ops);
        if ($c === '' || $c === 'BINARY') {
            return $ops;
        }
        return array_map(static fn(array $o): array => ['k' => 'fn', 'nombre' => 'PLEGAR',
            'args' => [$o, ['k' => 'lit', 'v' => $c]], 'star' => false, 'distinct' => false], $ops);
    }

    /**
     * La colación escrita de unos operandos, la del primero que la lleva, o
     * '' si ninguno: la de la comparación que se forma con ellos, que la
     * guarda en 'escrita' (2.9). Así no se vuelven a recorrer: en a = b = c …
     * cada comparación lleva dentro todas las anteriores, y recorrerlas cada
     * vez hacía el análisis cuadrático (20.000 operadores, más de un minuto).
     * Sin ningún COLLATE leído hasta aquí, no hay nada que buscar.
     *
     * @param list<array> $ops
     */
    private function escrita(array $ops): string
    {
        if ($this->conColacion) {
            foreach ($ops as $o) {
                if (($c = self::colacionEscrita($o)) !== null) {
                    return $c;
                }
            }
        }
        return '';
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
            if ($this->es('id', 'SELECT') || $this->es('id', 'WITH')) {
                $sub = ['k' => 'sub', 'select' => ParserConsulta::select($this)];
                $this->exigir('punc', ')');
                $this->conSubconsulta = true;
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

                // CAST(expr AS tipo)
                if ($tk['u'] === 'CAST' && $this->esSiguiente('punc', '(')) {
                    $this->avanzar();
                    $this->exigir('punc', '(');
                    $e = $this->expr();
                    $this->exigir('id', 'AS');
                    $tipo = $this->tipoDeCast();
                    $this->exigir('punc', ')');
                    return ['k' => 'cast', 'e' => $e, 'tipo' => $tipo];
                }

                // EXISTS (SELECT ...): cierto si la subconsulta devuelve alguna fila
                if ($tk['u'] === 'EXISTS' && $this->esSiguiente('punc', '(')) {
                    $this->avanzar();
                    $this->exigir('punc', '(');
                    $sub = ['k' => 'exists', 'select' => ParserConsulta::select($this)];
                    $this->exigir('punc', ')');
                    $this->conSubconsulta = true;
                    return $sub;
                }
                if ($tk['u'] === 'TRUE')  { $this->avanzar(); return ['k' => 'lit', 'v' => 1]; }
                if ($tk['u'] === 'FALSE') { $this->avanzar(); return ['k' => 'lit', 'v' => 0]; }
            }

            // RAISE(ABORT, 'mensaje') — solo tiene sentido dentro de un trigger
            if (!$tk['q'] && $tk['u'] === 'RAISE' && $this->esSiguiente('punc', '(')) {
                $this->avanzar();
                $this->avanzar();
                $accion = strtoupper($this->nombreSimple('acción de RAISE'));
                // Solo ABORT. Las otras tres de SQLite significan cosas distintas
                // que aquí no se pueden cumplir: ROLLBACK necesita transacciones,
                // que no hay, e IGNORE tiene una semántica propia. Aceptarlas y
                // tratarlas como ABORT sería prometer algo que no se hace.
                if ($accion !== 'ABORT') {
                    throw JsonSqlDbError::syntax(
                        "RAISE($accion) no está soportado: solo RAISE(ABORT, 'mensaje'). "
                        . ($accion === 'ROLLBACK'
                            ? 'ROLLBACK necesitaría transacciones de varias sentencias.'
                            : "El efecto de $accion no se puede reproducir aquí.")
                    );
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

            // CURRENT_TIMESTAMP, CURRENT_DATE y CURRENT_TIME, sin paréntesis
            // como en SQL: la fecha y hora de ahora, como DATETIME() y compañía;
            // y UTC_DATE, UTC_TIME y UTC_TIMESTAMP, como en MySQL (2.9)
            if (!$tk['q'] && isset(self::AHORA[$tk['u']]) && !$this->esSiguiente('punc', '(')) {
                $this->avanzar();
                return ['k' => 'fn', 'nombre' => self::AHORA[$tk['u']], 'args' => [], 'star' => false, 'distinct' => false];
            }

            // INTERVAL n unidad (MySQL): solo vale junto a una fecha, con + o -,
            // o dentro de DATE_ADD / DATE_SUB, que lo convierten (ver conIntervalo())
            if ($tk['u'] === 'INTERVAL' && !$tk['q'] && !$this->esSiguiente('punc', '(')) {
                $this->avanzar();
                $n = $this->exprProducto();
                return ['k' => 'intervalo', 'e' => $n, 'u' => $this->unidadDeTiempo()];
            }

            // VALUES(col) dentro de ON DUPLICATE KEY UPDATE: el valor propuesto
            if ($this->valoresPropuestos && $tk['u'] === 'VALUES' && $this->esSiguiente('punc', '(')) {
                $this->avanzar();
                $this->avanzar();
                $col = $this->nombreSimple('columna');
                $this->exigir('punc', ')');
                return ['k' => 'col', 'tabla' => 'excluded', 'nombre' => $col];
            }

            // Llamada a función
            if ($this->esSiguiente('punc', '(')) {
                $nombre = strtoupper($tk['v']);
                // COLLATE y PLEGAR son del árbol (ver plegar()), no funciones que
                // se puedan escribir: COLLATE(x) leía un segundo argumento que no hay (2.9)
                if ($nombre === 'COLLATE' || $nombre === 'PLEGAR') {
                    throw JsonSqlDbError::syntax("Función no soportada: $nombre(); una colación va detrás del valor, como en x COLLATE NOCASE");
                }
                $this->avanzar();
                $this->avanzar();
                // Las formas con palabras del estándar y de MySQL (2.9)
                if (isset(self::CON_PALABRAS[$nombre])) {
                    return ParserFunciones::funcionConPalabras($this, $nombre);
                }
                // NOW(), CURDATE() y compañía de MySQL son DATETIME(), DATE() y
                // TIME() sin argumentos: así la caché sabe que dependen del momento
                if (isset(self::AHORA[$nombre]) || isset(self::AHORA_MYSQL[$nombre])) {
                    if (!$this->comer('punc', ')')) {
                        throw JsonSqlDbError::syntax("$nombre() va sin argumentos");
                    }
                    return ['k' => 'fn', 'nombre' => self::AHORA[$nombre] ?? self::AHORA_MYSQL[$nombre], 'args' => [], 'star' => false, 'distinct' => false];
                }
                // STRING_AGG(x, sep) de SQLite 3.44 y PostgreSQL es GROUP_CONCAT(x, sep)
                if ($nombre === 'STRING_AGG') {
                    $nombre = 'GROUP_CONCAT';
                }
                $fn = ['k' => 'fn', 'nombre' => $nombre, 'args' => [], 'star' => false, 'distinct' => false];
                $this->conBytes = $this->conBytes || $nombre === 'RANDOMBLOB' || $nombre === 'ZEROBLOB';

                if ($this->es('op', '*')) {
                    $this->avanzar();
                    $fn['star'] = true;
                } elseif (!$this->es('punc', ')')) {
                    $fn['distinct'] = $this->comer('id', 'DISTINCT');
                    do {
                        $fn['args'][] = $this->expr();
                    } while ($this->comer('punc', ','));
                    // GROUP_CONCAT(x [, sep] ORDER BY …): el orden dentro del grupo,
                    // como en MySQL y en SQLite 3.44
                    if ($nombre === 'GROUP_CONCAT' && $this->comer('id', 'ORDER')) {
                        $this->exigir('id', 'BY');
                        $fn['orden'] = ParserConsulta::listaOrden($this);
                    }
                    // GROUP_CONCAT(x … SEPARATOR ';') de MySQL: el segundo argumento de SQLite
                    if ($nombre === 'GROUP_CONCAT' && \count($fn['args']) === 1 && $this->comer('id', 'SEPARATOR')) {
                        $fn['args'][] = $this->expr();
                    }
                }
                $this->exigir('punc', ')');
                // JSON_GROUP_OBJECT(clave, valor) y JSON_OBJECTAGG: un agregado de
                // un argumento, la pareja (ver SelectAgrupada::agregado())
                if (($nombre === 'JSON_GROUP_OBJECT' || $nombre === 'JSON_OBJECTAGG') && \count($fn['args']) === 2) {
                    $fn['args'] = [['k' => 'fn', 'nombre' => 'JSON_ARRAY', 'args' => $fn['args'], 'star' => false, 'distinct' => false]];
                }
                // FILTER (WHERE …) de un agregado y OVER de una función de ventana (2.9)
                $filtro = null;
                if ($this->es('id', 'FILTER') && $this->esSiguiente('punc', '(')) {
                    $this->avanzar();
                    $this->exigir('punc', '(');
                    $this->exigir('id', 'WHERE');
                    $filtro = $this->expr();
                    $this->exigir('punc', ')');
                }
                if ($this->comer('id', 'OVER')) {
                    return ParserAvanzado::ventana($this, $fn, $filtro);
                }
                if (isset(self::VENTANA[$nombre])) {
                    throw JsonSqlDbError::syntax("$nombre() es una función de ventana: necesita OVER (…)");
                }
                if ($filtro !== null) {
                    if (!Evaluator::esAgregado($fn)) {
                        throw JsonSqlDbError::syntax("FILTER solo vale con una función de agregado, no con $nombre()");
                    }
                    $fn['filtro'] = $filtro;
                }
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

    /** Las unidades de INTERVAL, DATE_ADD y TIMESTAMPDIFF (MySQL) */
    private const UNIDADES = ['MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR'];

    protected function unidadDeTiempo(): array
    {
        $u = strtoupper($this->nombreSimple('unidad'));
        if (!\in_array($u, self::UNIDADES, true)) {
            throw JsonSqlDbError::syntax("Unidad de tiempo no soportada: $u (" . implode(', ', self::UNIDADES) . ')');
        }
        return ['k' => 'lit', 'v' => $u];
    }

    /**
     * fecha + INTERVAL n unidad (y - , e INTERVAL n unidad + fecha) de MySQL: el
     * DATE_ADD o el DATE_SUB que dicen (2.9)
     */
    private static function conIntervalo(array $bin): array
    {
        $i = $bin['i'];
        $d = $bin['d'];
        if (($d['k'] ?? '') === 'intervalo') {
            return ['k' => 'fn', 'nombre' => $bin['op'] === '+' ? 'DATE_ADD' : 'DATE_SUB', 'args' => [$i, $d['e'], $d['u']], 'star' => false, 'distinct' => false];
        }
        if (($i['k'] ?? '') === 'intervalo' && $bin['op'] === '+') {
            return ['k' => 'fn', 'nombre' => 'DATE_ADD', 'args' => [$d, $i['e'], $i['u']], 'star' => false, 'distinct' => false];
        }
        return $bin;
    }

    /** El tipo de un CAST o un CONVERT: SIGNED y UNSIGNED de MySQL son enteros (2.9) */
    protected function tipoDeCast(): string
    {
        $tipo = strtoupper((string)$this->avanzar()['v']);
        if ($tipo === 'SIGNED' || $tipo === 'UNSIGNED') {
            $this->comer('id', 'INTEGER') || $this->comer('id', 'INT');
            return $tipo === 'SIGNED' ? 'INTEGER' : 'UNSIGNED';
        }
        // Se admite y se ignora la longitud: CAST(x AS VARCHAR(10))
        if ($this->comer('punc', '(')) {
            $this->enteroPositivo('CAST');
            $this->comer('punc', ',') && $this->enteroPositivo('CAST');
            $this->exigir('punc', ')');
        }
        return $tipo;
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
    // Auxiliares comunes
    // ==================================================================

    protected function listaColumnasEntreParentesis(): array
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

    /**
     * El nombre de una tabla como está guardada: sin distinguir mayúsculas,
     * como en SQLite (2.10: FOO no encontraba Foo, y foo se podía crear al
     * lado, que en Windows es el mismo fichero). Los nombres de un WITH, tal
     * cual.
     */
    protected function real(string $nombre): string
    {
        return $this->nombres === null || isset($this->ctes[strtolower($nombre)]) ? $nombre : ($this->nombres)($nombre);
    }

    protected function nombreTabla(bool $real = true): string
    {
        $tk = $this->actual();
        if ($tk['t'] !== 'id' || (!$tk['q'] && \in_array($tk['u'], self::RESERVADAS, true))) {
            throw JsonSqlDbError::syntax("Se esperaba un nombre de tabla y se encontró '{$tk['v']}'");
        }
        $this->avanzar();
        $nombre = $this->sinEsquema($tk['v']);
        return $real ? $this->real($nombre) : $nombre;
    }

    /**
     * El nombre de una tabla con el prefijo de esquema que pueda llevar
     * delante, que se descarta: aquí cada base es una carpeta y no hay
     * esquemas. Al escribir vale cualquiera, como siempre; al leer (el FROM,
     * $soloMain), solo main, el de SQLite, para que otra_base.tabla no lea en
     * silencio la tabla de esta. Los de catálogo de otros motores
     * (information_schema…) dicen qué usar en su lugar (2.10: en el FROM no se
     * aceptaba ninguno, y el error era «solo se admite una sentencia»).
     */
    protected function sinEsquema(string $nombre, bool $soloMain = false): string
    {
        if (!$this->es('punc', '.')) {
            return $nombre;
        }
        if (\in_array(strtolower($nombre), ['information_schema', 'pg_catalog', 'sys', 'mysql', 'performance_schema'], true)) {
            throw JsonSqlDbError::syntax("No hay '$nombre' en jsonSQLDB: las tablas, columnas e índices se ven con SHOW TABLES, SHOW COLUMNS FROM tabla y SHOW INDEXES FROM tabla");
        }
        if ($soloMain && strtolower($nombre) !== 'main') {
            throw JsonSqlDbError::syntax("'$nombre.' delante de una tabla: una consulta solo lee de su base, sin prefijo (o con main.)");
        }
        $this->avanzar();
        return $this->nombreSimple('tabla');
    }

    protected function nombreSimple(string $que): string
    {
        $tk = $this->actual();
        if ($tk['t'] !== 'id') {
            throw JsonSqlDbError::syntax("Se esperaba un nombre de $que y se encontró '{$tk['v']}'");
        }
        $this->avanzar();
        return $tk['v'];
    }

    // ==================================================================
    // Utilidades sobre el flujo de tokens
    // ==================================================================

    /** @return Token */
    protected function actual(): array
    {
        return $this->t[$this->i];
    }

    /**
     * El token actual, pasando al siguiente. Nunca más allá del final (2.9):
     * con una sentencia cortada, como «SELECT CAST(1 AS», se leía detrás de
     * él y acababa en un error de PHP.
     *
     * @phpstan-impure
     * @return Token
     */
    protected function avanzar(): array
    {
        $tk = $this->t[$this->i];
        if ($tk['t'] === 'eof') {
            throw JsonSqlDbError::syntax("La sentencia se acaba antes de tiempo (línea {$tk['l']})");
        }
        $this->i++;
        return $tk;
    }

    protected function es(string $tipo, ?string $valor = null): bool
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

    protected function esSiguiente(string $tipo, string $valor, int $salto = 1): bool
    {
        $tk = $this->t[$this->i + $salto] ?? null;
        if ($tk === null || $tk['t'] !== $tipo) {
            return false;
        }
        return $tipo === 'id' ? (!$tk['q'] && $tk['u'] === $valor) : $tk['v'] === $valor;
    }

    /** @phpstan-impure consume el token si coincide */
    protected function comer(string $tipo, string $valor): bool
    {
        if ($this->es($tipo, $valor)) {
            $this->i++;
            return true;
        }
        return false;
    }

    protected function exigir(string $tipo, string $valor): void
    {
        if (!$this->comer($tipo, $valor)) {
            $tk = $this->actual();
            $enc = $tk['v'] === '' ? 'fin de consulta' : $tk['v'];
            throw JsonSqlDbError::syntax("Se esperaba '$valor' y se encontró '$enc' (línea {$tk['l']})");
        }
    }
}
