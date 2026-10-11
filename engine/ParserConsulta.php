<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * La gramática del SELECT: WITH, UNION, INTERSECT y EXCEPT, la lista de
 * columnas, los orígenes del FROM, GROUP BY, HAVING, WINDOW, ORDER BY, LIMIT
 * y OFFSET; y las funciones que se escriben con palabras, como TRIM(… FROM
 * …) o EXTRACT(… FROM …). Métodos de Parser que reciben el analizador en
 * $an. Van aparte para que una escritura no tenga que compilarlos (2.9): sin
 * OPcache, PHP compila en cada petición lo que carga. Hereda de Parser solo
 * para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class ParserConsulta extends Parser
{
    private function __construct()
    {
    }

    /**
     * Un SELECT, o varios combinados con UNION, INTERSECT o EXCEPT.
     *
     * En  A UNION B ORDER BY x LIMIT n  el ORDER BY y el LIMIT son del conjunto,
     * no de B. Como selectSimple() ya los ha consumido al analizar la última
     * parte, se le quitan y se suben al nodo de la unión.
     */
    public static function select(Parser $an): array
    {
        // WITH [RECURSIVE] nombre [(cols)] AS (SELECT ...), otro AS (...) SELECT ...
        // Cada nombre queda disponible en el FROM de lo que viene después, igual
        // que una vista, pero solo durante esta sentencia. La lista de columnas
        // les da nombre ('columnas'); una que se nombra a sí misma es recursiva
        // (2.9, como en SQLite con o sin RECURSIVE): 'recursiva' es su primera
        // parte recursiva (ver ParserAvanzado::recursiva())
        $con = [];
        if ($an->comer('id', 'WITH')) {
            $an->comer('id', 'RECURSIVE');
            do {
                $nombre = $an->nombreTabla();
                foreach (array_keys($con) as $otro) {
                    if (strcasecmp((string)$otro, $nombre) === 0) {
                        throw JsonSqlDbError::syntax("El nombre '$nombre' se repite en el WITH");
                    }
                }
                $columnas = $an->es('punc', '(') ? $an->listaColumnasEntreParentesis() : null;
                $an->exigir('id', 'AS');
                $an->exigir('punc', '(');
                $sel = self::select($an);
                $an->exigir('punc', ')');
                if ($columnas !== null) {
                    $sel['columnas'] = $columnas;
                }
                // Sin nombrarse a sí misma no es recursiva, y no hace falta cargar ParserAvanzado
                $primera = self::vecesQueNombra($sel, $nombre) === 0 ? null : ParserAvanzado::recursiva($sel, $nombre);
                if ($primera !== null) {
                    $sel['recursiva'] = $primera;
                }
                $con[$nombre] = $sel;
            } while ($an->comer('punc', ','));
        }

        $consulta = self::selectConCombinacion($an);
        if ($con !== []) {
            $consulta['with'] = $con;
        }
        return $consulta;
    }

    private static function selectConCombinacion(Parser $an): array
    {
        $primera = self::selectSimple($an);
        if (!$an->es('id', 'UNION') && !$an->es('id', 'INTERSECT') && !$an->es('id', 'EXCEPT')) {
            return $primera;
        }

        $partes = [$primera];
        $ops    = [];                       // un operador entre cada dos partes
        while (true) {
            if ($an->comer('id', 'UNION')) {
                $ops[] = $an->comer('id', 'ALL') ? 'UNION ALL' : 'UNION';
            } elseif ($an->comer('id', 'INTERSECT')) {
                $ops[] = 'INTERSECT';
            } elseif ($an->comer('id', 'EXCEPT')) {
                $ops[] = 'EXCEPT';
            } else {
                break;
            }
            $partes[] = self::selectSimple($an);
        }

        $ultima = $partes[count($partes) - 1];
        $union  = [
            'k'      => 'union',
            'partes' => $partes,
            'ops'    => $ops,
            'order'  => $ultima['order'],
            'limit'  => $ultima['limit'],
            'offset' => $ultima['offset'],
        ];
        $partes[count($partes) - 1]['order']  = [];
        $partes[count($partes) - 1]['limit']  = null;
        $partes[count($partes) - 1]['offset'] = null;
        $union['partes'] = $partes;

        return $union;
    }

    private static function selectSimple(Parser $an): array
    {
        $an->exigir('id', 'SELECT');

        $distinct = false;
        if ($an->comer('id', 'DISTINCT')) {
            $distinct = true;
        } else {
            $an->comer('id', 'ALL');
        }

        // Las funciones de ventana (2.9) solo valen en las columnas y en el
        // ORDER BY de este SELECT; dentro de una subconsulta, en las suyas
        [$permitidasAntes, $vistasAntes] = [$an->ventanasPermitidas, $an->ventanasVistas];
        [$an->ventanasPermitidas, $an->ventanasVistas] = [true, 0];
        $sel = [
            'k' => 'select', 'distinct' => $distinct, 'cols' => self::listaColumnas($an),
            'from' => [], 'where' => null, 'group' => null, 'having' => null,
            'order' => [], 'limit' => null, 'offset' => null,
        ];
        $an->ventanasPermitidas = false;

        if ($an->comer('id', 'FROM')) {
            $sel['from'] = $an->from();
        }
        if ($an->comer('id', 'WHERE')) {
            $sel['where'] = $an->expr();
        }
        if ($an->comer('id', 'GROUP')) {
            $an->exigir('id', 'BY');
            $sel['group'] = [];
            do {
                $sel['group'][] = $an->plegar([$an->expr()])[0];
            } while ($an->comer('punc', ','));

            if ($an->comer('id', 'HAVING')) {
                $sel['having'] = $an->expr();
            }
        } elseif ($an->comer('id', 'HAVING')) {
            $sel['having'] = $an->expr();   // HAVING sin GROUP BY: agrupación total
        }
        // WINDOW nombre AS (…) [, …]: ventanas con nombre para los OVER (2.9)
        $ventanas = [];
        if ($an->es('id', 'WINDOW')) {
            $an->avanzar();
            do {
                $nombre = $an->nombreSimple('ventana');
                $an->exigir('id', 'AS');
                $ventanas[strtolower($nombre)] = ParserAvanzado::conBase(ParserAvanzado::defVentana($an), $ventanas);
            } while ($an->comer('punc', ','));
        }
        if ($an->comer('id', 'ORDER')) {
            $an->exigir('id', 'BY');
            $an->ventanasPermitidas = true;
            $sel['order'] = self::listaOrden($an);
            $an->ventanasPermitidas = false;
        }
        if ($an->ventanasVistas > 0) {
            $sel['cols']     = ParserAvanzado::resolverVentanas($sel['cols'], $ventanas);
            $sel['order']    = ParserAvanzado::resolverVentanas($sel['order'], $ventanas);
            $sel['ventanas'] = true;
        }
        [$an->ventanasPermitidas, $an->ventanasVistas] = [$permitidasAntes, $vistasAntes];
        if ($an->comer('id', 'LIMIT')) {
            $primero = self::limite($an, 'LIMIT');
            if ($an->comer('punc', ',')) {            // LIMIT offset, cantidad
                $sel['offset'] = max(0, $primero ?? 0);
                $sel['limit']  = self::limite($an, 'LIMIT');
            } else {
                $sel['limit'] = $primero;
                if ($an->comer('id', 'OFFSET')) {
                    $sel['offset'] = max(0, self::limite($an, 'OFFSET') ?? 0);
                }
            }
        }
        // FOR UPDATE / FOR SHARE [OF t] [NOWAIT | SKIP LOCKED] y LOCK IN SHARE
        // MODE de MySQL (2.9): sin efecto, cada lectura ya ve un estado entero
        if ($an->comer('id', 'FOR')) {
            if (!$an->comer('id', 'UPDATE')) {
                $an->exigir('id', 'SHARE');
            }
            if ($an->comer('id', 'OF')) {
                do { $an->nombreTabla(); } while ($an->comer('punc', ','));
            }
            if (!$an->comer('id', 'NOWAIT') && $an->comer('id', 'SKIP')) {
                $an->exigir('id', 'LOCKED');
            }
        } elseif ($an->comer('id', 'LOCK')) {
            $an->exigir('id', 'IN');
            $an->exigir('id', 'SHARE');
            $an->exigir('id', 'MODE');
        }

        return $sel;
    }

    public static function listaColumnas(Parser $an): array
    {
        $cols = [];
        do {
            // *
            if ($an->es('op', '*')) {
                $an->avanzar();
                $cols[] = ['star' => true, 'tabla' => null];
                continue;
            }
            // tabla.*
            if ($an->es('id') && $an->esSiguiente('punc', '.') && $an->esSiguiente('op', '*', 2)) {
                $tabla = $an->avanzar()['v'];
                $an->avanzar();
                $an->avanzar();
                $cols[] = ['star' => true, 'tabla' => $tabla];
                continue;
            }

            $expr  = $an->expr();
            $alias = $an->alias();
            $cols[] = ['star' => false, 'expr' => $expr, 'alias' => $alias];
        } while ($an->comer('punc', ','));

        return $cols;   // el do-while garantiza al menos una columna
    }

    protected static function origen(Parser $an, ?string $join): array
    {
        $o = ['tipo' => 'tabla', 'nombre' => null, 'select' => null, 'alias' => null,
              'join' => $join, 'on' => null];

        if ($an->comer('punc', '(')) {
            $o['tipo']   = 'sub';
            $o['select'] = self::select($an);
            $an->exigir('punc', ')');
            // Sin alias, uno que no se puede escribir, como hace SQLite (2.9:
            // era obligatorio): sus columnas se nombran sin tabla
            $o['alias'] = $an->alias() ?? '(subconsulta ' . ++$an->sinAlias . ')';
            return $o;
        }

        $tk = $an->actual();
        if ($tk['t'] !== 'id' || (!$tk['q'] && in_array($tk['u'], self::RESERVADAS, true))) {
            throw JsonSqlDbError::syntax("Se esperaba un nombre de tabla y se encontró '{$tk['v']}'");
        }
        $an->avanzar();
        $o['nombre'] = $tk['v'];
        $o['alias']  = $an->alias();
        return $o;
    }

    /**
     * El número de un LIMIT o un OFFSET: un entero, un ? o una expresión
     * constante (LIMIT 10 * 2), como en SQLite. Y como en SQLite, un número
     * negativo es «sin límite»: devuelve null (el OFFSET lo deja en 0).
     */
    public static function limite(Parser $an, string $clausula): ?int
    {
        if ($clausula === 'LIMIT' && $an->comer('id', 'ALL')) {
            return null;                              // LIMIT ALL de SQLite: sin límite (2.9)
        }
        $e = self::sinFila($an, $clausula);
        try {
            $v = Evaluator::evaluar(Evaluator::resolver($e, []), []);
        } catch (JsonSqlDbError) {
            throw JsonSqlDbError::syntax("$clausula necesita un número entero o una expresión constante, sin columnas");
        }
        if (is_string($v) && is_numeric(trim($v))) {
            $v = trim($v) + 0;
        }
        if (is_float($v) && $v === floor($v) && abs($v) < 9.0e18) {
            $v = (int)$v;
        }
        if (!is_int($v)) {
            throw JsonSqlDbError::syntax("$clausula necesita un número entero");
        }
        return $v < 0 ? null : $v;
    }

    /**
     * La expresión de algo que se calcula al analizar, sin ninguna fila: el
     * LIMIT, el OFFSET y los desplazamientos del marco de una ventana. Aquí
     * una subconsulta no se puede ejecutar (2.9: acababa en un error de PHP):
     * es un error que lo dice. SQLite sí la admite en LIMIT y OFFSET; en el
     * marco, tampoco.
     */
    public static function sinFila(Parser $an, string $clausula): array
    {
        $antes = $an->conSubconsulta;
        $an->conSubconsulta = false;
        $e = $an->expr();
        if ($an->conSubconsulta) {
            throw JsonSqlDbError::syntax("$clausula no admite subconsultas: escribe un número, un ? o una expresión constante");
        }
        $an->conSubconsulta = $antes;
        return $e;
    }

    /** @return list<array{expr: array, dir: string}> los elementos de un ORDER BY */
    public static function listaOrden(Parser $an): array
    {
        $orden = [];
        do {
            $e   = $an->expr();
            $dir = 'ASC';
            if ($an->comer('id', 'DESC'))      { $dir = 'DESC'; }
            elseif ($an->comer('id', 'ASC'))   { $dir = 'ASC'; }
            // NULLS FIRST / NULLS LAST (2.9). Lo de siempre es NULL primero en
            // ASC y último en DESC; lo contrario es ordenar antes por «es NULL»
            if ($an->comer('id', 'NULLS')) {
                $primero = $an->comer('id', 'FIRST');
                if (!$primero) {
                    $an->exigir('id', 'LAST');
                }
                if ($primero === ($dir === 'DESC')) {
                    if (($e['k'] ?? '') === 'lit' && is_int($e['v'])) {
                        throw JsonSqlDbError::syntax('NULLS ' . ($primero ? 'FIRST' : 'LAST') . ' con el número de una columna: escribe la columna o su alias');
                    }
                    $orden[] = ['expr' => ['k' => 'null', 'e' => $e, 'not' => false], 'dir' => $primero ? 'DESC' : 'ASC', 'nulos' => true];
                }
            }
            $orden[] = ['expr' => $an->plegar([$e])[0], 'dir' => $dir];
        } while ($an->comer('punc', ','));
        return $orden;
    }

    /** Cuántas veces nombra la consulta la tabla $nombre en un FROM, a cualquier profundidad. */
    public static function vecesQueNombra(array $n, string $nombre): int
    {
        $veces = ($n['tipo'] ?? null) === 'tabla' && strcasecmp((string)($n['nombre'] ?? ''), $nombre) === 0 ? 1 : 0;
        foreach ($n as $v) {
            if (is_array($v)) {
                $veces += self::vecesQueNombra($v, $nombre);
            }
        }
        return $veces;
    }

    /**
     * Las funciones que se escriben con palabras en vez de comas (2.9), ya con
     * el paréntesis abierto: TRIM([LEADING|TRAILING|BOTH] [c] FROM x),
     * POSITION(a IN b), SUBSTRING(x FROM i [FOR n]), EXTRACT(unidad FROM f),
     * CONVERT(x, tipo) y CONVERT(x USING utf8). Null si no es una de ellas: la
     * llamada sigue como cualquier otra.
     */
    protected static function funcionConPalabras(Parser $an, string $nombre): ?array
    {
        $fn = static fn(string $n, array $args): array => ['k' => 'fn', 'nombre' => $n, 'args' => $args, 'star' => false, 'distinct' => false];
        switch ($nombre) {
            case 'TRIM':
                $lado = 'TRIM';
                if ($an->comer('id', 'LEADING'))      { $lado = 'LTRIM'; }
                elseif ($an->comer('id', 'TRAILING')) { $lado = 'RTRIM'; }
                else                                    { $an->comer('id', 'BOTH'); }
                $quitar = null;
                if (!$an->es('id', 'FROM')) {
                    $quitar = $an->exprBits();
                    if (!$an->es('id', 'FROM')) {
                        if ($lado !== 'TRIM') {
                            throw JsonSqlDbError::syntax("TRIM($lado …) necesita FROM");
                        }
                        // TRIM(x) o TRIM(x, c): la forma de siempre
                        $args = [$quitar];
                        while ($an->comer('punc', ',')) {
                            $args[] = $an->expr();
                        }
                        $an->exigir('punc', ')');
                        return $fn('TRIM', $args);
                    }
                }
                $an->exigir('id', 'FROM');
                $texto = $an->expr();
                $an->exigir('punc', ')');
                return $fn($lado, $quitar === null ? [$texto] : [$texto, $quitar]);
            case 'POSITION':
                $buscado = $an->exprBits();
                if (!$an->comer('id', 'IN')) {
                    throw JsonSqlDbError::syntax('POSITION(a IN b): falta IN');
                }
                $texto = $an->exprBits();
                $an->exigir('punc', ')');
                return $fn('INSTR', [$texto, $buscado]);
            case 'SUBSTRING':
            case 'SUBSTR':
                $texto = $an->expr();
                if (!$an->comer('id', 'FROM')) {
                    $args = [$texto];
                    while ($an->comer('punc', ',')) {
                        $args[] = $an->expr();
                    }
                    $an->exigir('punc', ')');
                    return $fn($nombre, $args);
                }
                $args = [$texto, $an->expr()];
                if ($an->comer('id', 'FOR')) {
                    $args[] = $an->expr();
                }
                $an->exigir('punc', ')');
                return $fn('SUBSTR', $args);
            case 'EXTRACT':
                $unidad = strtoupper($an->nombreSimple('unidad'));
                $funciones = ['YEAR' => 'YEAR', 'MONTH' => 'MONTH', 'DAY' => 'DAY', 'HOUR' => 'HOUR', 'MINUTE' => 'MINUTE',
                              'SECOND' => 'SECOND', 'QUARTER' => 'QUARTER'];
                if (!isset($funciones[$unidad])) {
                    throw JsonSqlDbError::syntax("EXTRACT($unidad FROM …) no está soportado: YEAR, QUARTER, MONTH, DAY, HOUR, MINUTE o SECOND");
                }
                $an->exigir('id', 'FROM');
                $fecha = $an->expr();
                $an->exigir('punc', ')');
                return $fn($funciones[$unidad], [$fecha]);
            case 'TIMESTAMPDIFF':
            case 'TIMESTAMPADD':
                $unidad = $an->unidadDeTiempo();
                $an->exigir('punc', ',');
                $a = $an->expr();
                $an->exigir('punc', ',');
                $b = $an->expr();
                $an->exigir('punc', ')');
                return $nombre === 'TIMESTAMPDIFF' ? $fn('TIMESTAMPDIFF', [$unidad, $a, $b]) : $fn('DATE_ADD', [$b, $a, $unidad]);
            case 'DATE_ADD':
            case 'DATE_SUB':
            case 'ADDDATE':
            case 'SUBDATE':
                // DATE_ADD(f, INTERVAL n unidad); ADDDATE(f, n) son días
                $fecha = $an->expr();
                $an->exigir('punc', ',');
                $cuanto = $an->expr();
                $an->exigir('punc', ')');
                $resta = $nombre === 'DATE_SUB' || $nombre === 'SUBDATE';
                if (($cuanto['k'] ?? '') === 'intervalo') {
                    return $fn($resta ? 'DATE_SUB' : 'DATE_ADD', [$fecha, $cuanto['e'], $cuanto['u']]);
                }
                if ($nombre === 'DATE_ADD' || $nombre === 'DATE_SUB') {
                    throw JsonSqlDbError::syntax("$nombre(fecha, INTERVAL n unidad): falta el INTERVAL");
                }
                return $fn($resta ? 'DATE_SUB' : 'DATE_ADD', [$fecha, $cuanto, ['k' => 'lit', 'v' => 'DAY']]);
            case 'CONVERT':
                $e = $an->expr();
                if ($an->comer('id', 'USING')) {
                    $juego = strtolower($an->nombreSimple('juego de caracteres'));
                    if (!in_array($juego, ['utf8', 'utf8mb4', 'utf8mb3'], true)) {
                        throw JsonSqlDbError::syntax("CONVERT(… USING $juego): aquí todo es UTF-8; solo vale USING utf8 o utf8mb4");
                    }
                    $an->exigir('punc', ')');
                    return $e;
                }
                $an->exigir('punc', ',');
                $tipo = $an->tipoDeCast();
                $an->exigir('punc', ')');
                return ['k' => 'cast', 'e' => $e, 'tipo' => $tipo];
        }
        return null;
    }
}
