<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Ejecutor de SELECT.
 *
 * Durante la ejecución cada fila es un array plano con claves "alias.columna",
 * de modo que leer una columna es un acceso directo. Los JOIN con condición de
 * igualdad se resuelven con tabla hash (no comparando todas las filas contra
 * todas), y las subconsultas se ejecutan una sola vez y se guardan en memoria.
 */
final class Select
{
    private Catalog $cat;
    /** @var callable(string):iterable<array> lector de filas; permite ver cambios aún no volcados a disco */
    private $lector;

    /**
     * Si se puede resolver una consulta por índice.
     *
     * Solo con el lector de disco. Cuando escribe el Writer, las filas que se
     * ven son las que tiene a medias en memoria y los índices en disco todavía
     * no las conocen.
     */
    private bool $indexable;
    /** Profundidad máxima de vistas anidadas (una vista que usa otra vista). */
    private const MAX_VISTAS = 8;

    /** @var int vistas resueltas en la cadena actual, para cortar ciclos */
    private int $profundidadVista = 0;

    /** @var array<string,array> consultas con nombre del WITH en curso */
    private array $con = [];

    /** @var array{mapa: array, fila: array}|null consulta exterior, si estamos dentro de una subconsulta */
    private ?array $externo = null;

    /** @var array<int,array<string,array>> resultados de subconsultas correlacionadas, por fila exterior */
    private array $subsCorr = [];

    /** @var array<int,array> resultado de cada subconsulta ya ejecutada */
    private array $subs = [];
    /** @var array<int, array> conjuntos de un IN cuya subconsulta no mira hacia fuera */
    private array $conjuntos = [];

    public function __construct(Catalog $cat, ?callable $lector = null)
    {
        $this->cat       = $cat;
        $this->indexable = $lector === null;
        $this->lector    = $lector ?? static fn(string $t): iterable => $cat->storage()->filas($t);
    }

    /** Ejecuta la consulta y devuelve las filas de salida. */
    public function ejecutar(array $ast): array
    {
        return $this->correr($ast)['filas'];
    }

    /**
     * Tablas de las que depende una consulta —las del FROM, las de sus
     * subconsultas y las de las vistas que use—, o null si su resultado no
     * se puede guardar: porque no es determinista (RANDOM(), la fecha de
     * ahora) o porque nombra una tabla que no existe.
     *
     * @return list<string>|null
     */
    public static function tablasDe(array $ast, Catalog $cat, int $nivel = 0): ?array
    {
        if ($nivel > self::MAX_VISTAS) {
            return null;
        }
        $tablas = [];
        $cte    = [];
        if (!self::recorrer($ast, $cat, $nivel, $tablas, $cte)) {
            return null;
        }
        $tablas = array_keys($tablas);
        sort($tablas, SORT_STRING);
        return $tablas;
    }

    /**
     * Recorre el árbol entero de la consulta anotando las tablas.
     *
     * @param array<string,true> $tablas
     * @param array<string,true> $cte  nombres del WITH, que no son tablas
     */
    private static function recorrer(array $n, Catalog $cat, int $nivel, array &$tablas, array &$cte): bool
    {
        if (isset($n['with']) && is_array($n['with'])) {
            foreach (array_keys($n['with']) as $nombre) {
                $cte[strtolower((string)$nombre)] = true;
            }
        }
        if (($n['k'] ?? null) === 'fn' && isset($n['nombre'])) {
            $f = strtoupper((string)$n['nombre']);
            if ($f === 'RANDOM') {
                return false;
            }
            if (in_array($f, ['DATE', 'TIME', 'DATETIME', 'STRFTIME'], true)) {
                $arg = $f === 'STRFTIME' ? ($n['args'][1] ?? null) : ($n['args'][0] ?? null);
                if ($arg === null || ($arg['k'] === 'lit' && is_string($arg['v']) && strtolower($arg['v']) === 'now')) {
                    return false;                     // depende del momento
                }
            }
        }
        if (($n['tipo'] ?? null) === 'tabla' && isset($n['nombre'])) {
            $nombre = (string)$n['nombre'];
            if (!isset($cte[strtolower($nombre)])) {
                if ($cat->esVista($nombre)) {
                    $de = self::tablasDe(Parser::analizar((string)$cat->vista($nombre)['sql']), $cat, $nivel + 1);
                    if ($de === null) {
                        return false;
                    }
                    foreach ($de as $t) {
                        $tablas[$t] = true;
                    }
                } elseif ($cat->existe($nombre)) {
                    $tablas[$nombre] = true;
                } else {
                    return false;
                }
            }
        }
        foreach ($n as $v) {
            if (is_array($v) && !self::recorrer($v, $cat, $nivel, $tablas, $cte)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{cols: string[], filas: array} */
    private function correr(array $ast): array
    {
        // Las CTE del WITH quedan disponibles mientras se ejecuta esta consulta
        // y las de dentro. Se apilan para que un WITH anidado no pise al de
        // fuera y para restaurarlo todo al salir.
        $conAnterior = $this->con;
        if (isset($ast['with'])) {
            $this->con = $ast['with'] + $this->con;
        }

        try {
            return $ast['k'] === 'union'
                ? $this->correrUnion($ast)
                : $this->correrSimple($ast);
        } finally {
            $this->con = $conAnterior;
        }
    }

    private function correrSimple(array $ast): array
    {
        $contado = $this->contarRapido($ast);
        if ($contado !== null) {
            return $contado;
        }

        // ¿Se puede dejar de recorrer en cuanto haya suficientes filas?
        // Solo si el resultado no depende de las filas que vendrían después:
        // sin ORDER BY, sin agrupar, sin agregados y sin DISTINCT.
        $tope = $this->topeTemprano($ast);

        [$filas, $fuentes] = $this->origenes($ast, $tope);
        $mapa = $this->mapaColumnas($fuentes);
        $externa = $this->externo['fila'] ?? [];
        $sub  = function (array $sel, int $sid, array $filaExterna = []) use ($mapa): array {
            // Una subconsulta que no mira hacia fuera da siempre lo mismo: se
            // ejecuta una vez. Si está correlacionada, su resultado depende de
            // la fila de fuera, así que se guarda por fila.
            if (isset($this->subs[$sid])) {
                return $this->subs[$sid];
            }
            $claveFila = $filaExterna === [] ? '' : self::claveFila($filaExterna);
            if (isset($this->subsCorr[$sid][$claveFila])) {
                return $this->subsCorr[$sid][$claveFila];
            }

            $externoPrevio = $this->externo;
            $marcaPrevia   = Evaluator::$correlacionada;
            Evaluator::$correlacionada = false;
            $this->externo = ['mapa' => $mapa, 'fila' => $filaExterna];

            try {
                $filas = $this->correr($sel)['filas'];
                $usoFuera = Evaluator::$correlacionada;
            } finally {
                $this->externo             = $externoPrevio;
                Evaluator::$correlacionada = $marcaPrevia;
            }

            // El análisis estático no puede seguir esto: la marca la pone
            // Evaluator::resolver() durante la llamada anidada de arriba, no aquí
            if ($usoFuera) {
                $this->subsCorr[$sid][$claveFila] = $filas;
            } else {
                $this->subs[$sid] = $filas;
            }
            return $filas;
        };

        // Los valores de un IN agrupados por clave, para no recorrer la lista en
        // cada fila. Solo se guarda si la subconsulta no mira hacia fuera: si
        // está correlacionada, su resultado cambia con la fila y no hay nada que
        // reutilizar, así que se construye cada vez igual que antes.
        $conjunto = function (array $sel, int $sid, array $filaExterna = []) use ($sub): array {
            // Lo primero, el memo: recorrer las filas para sacar los valores ya
            // cuesta tanto como la búsqueda lineal que se quería evitar
            if (isset($this->conjuntos[$sid])) {
                return $this->conjuntos[$sid];
            }
            $valores = [];
            foreach ($sub($sel, $sid, $filaExterna) as $fila) {
                $valores[] = reset($fila);
            }
            $c = Indexes::conjunto($valores);
            if (isset($this->subs[$sid])) {
                $this->conjuntos[$sid] = $c;      // no mira hacia fuera: vale para todas
            }
            return $c;
        };

        // WHERE: las filas que pasan salen según se recorren; las que no, se
        // sueltan al momento
        if ($ast['where'] !== null) {
            $where = Evaluator::resolver($ast['where'], $mapa, [], $this->externo['mapa'] ?? []);
            $filas = $this->filtrar($filas, $where, $sub, $conjunto, $externa, $tope);
        }

        // Columnas de salida (expandiendo * )
        $salida = $this->columnasSalida($ast['cols'], $fuentes, $mapa);

        // ¿Hay agrupación?
        $grupoExprs = [];
        if ($ast['group'] !== null) {
            foreach ($ast['group'] as $e) {
                $grupoExprs[] = Evaluator::resolver($e, $mapa, [], $this->externo['mapa'] ?? []);
            }
        }
        $agrupar = $grupoExprs !== [] || $ast['having'] !== null || $this->hayAgregados($salida, $ast);

        $having = $ast['having'] === null ? null : Evaluator::resolver($ast['having'], $mapa, [], $this->externo['mapa'] ?? []);


        // Alias de salida utilizables en ORDER BY
        $aliasSalida = [];
        foreach ($salida as $c) {
            $aliasSalida[strtolower($c['nombre'])] = $c['nombre'];
        }
        $orden = [];
        foreach ($ast['order'] as $o) {
            $orden[] = ['expr' => Evaluator::resolver($o['expr'], $mapa, $aliasSalida, $this->externo['mapa'] ?? []), 'dir' => $o['dir']];
        }

        // Proyección + claves de ordenación
        $resultado = [];
        $clavesOrden = [];

        if ($agrupar) {
            // Los agregados de la salida, el HAVING y el ORDER BY se acumulan
            // recorriendo las filas una vez; después cada grupo es su fila
            // representativa y los resultados de sus acumuladores
            $defs = [];
            foreach ($salida as $i => $c) {
                $salida[$i]['expr'] = Evaluator::marcarAgregados($c['expr'], $defs);
            }
            if ($having !== null) {
                $having = Evaluator::marcarAgregados($having, $defs);
            }
            foreach ($orden as $i => $o) {
                $orden[$i]['expr'] = Evaluator::marcarAgregados($o['expr'], $defs);
            }
            foreach ($this->agrupar($filas, $grupoExprs, $defs, $sub, $conjunto, $externa) as [$fila, $agregados]) {
                $ctx = ['fila' => $fila, 'agregados' => $agregados, 'sub' => $sub,
                        'conjunto' => $conjunto, 'filaExterna' => $externa];
                if ($having !== null && Valor::verdadero(Evaluator::evaluar($having, $ctx)) !== true) {
                    continue;
                }
                $fila = [];
                foreach ($salida as $c) {
                    $fila[$c['nombre']] = Evaluator::evaluar($c['expr'], $ctx);
                }
                Memoria::comprobar('la construcción del resultado');
                $resultado[] = $fila;
                if ($orden !== []) {
                    $this->anotarClaves($clavesOrden, $orden, $ctx, $fila);
                }
            }
        } else {
            // Una columna de salida que es una columna de la tabla se copia sin
            // pasar por el evaluador, que es lo corriente en un SELECT *
            $directas = [];
            foreach ($salida as $i => $c) {
                $directas[$i] = $c['expr']['k'] === 'col' && isset($c['expr']['clave']) && !isset($c['expr']['externa'])
                    ? $c['expr']['clave'] : null;
            }
            $proyectar = static function (array $origen, array $ctx) use ($salida, $directas): array {
                $fila = [];
                foreach ($salida as $i => $c) {
                    $fila[$c['nombre']] = $directas[$i] !== null
                        ? ($origen[$directas[$i]] ?? null)
                        : Evaluator::evaluar($c['expr'], $ctx);
                }
                return $fila;
            };

            // Con ORDER BY sobre columnas de la tabla se ordena antes de
            // proyectar: con LIMIT solo se construyen las filas que salen, y
            // solo esas viven en memoria; sin él la tabla y el resultado no
            // conviven enteros
            $clavesDirectas = $orden === [] || $ast['distinct'] ? null : $this->ordenDirecto($orden, $salida);
            if ($clavesDirectas !== null) {
                $cuantas = self::cuantasHacenFalta($ast);
                if ($cuantas !== null) {
                    [$filas, $indices] = self::primerasDe($filas, $clavesDirectas, $orden, $cuantas);
                } else {
                    if (!is_array($filas)) {
                        $filas = iterator_to_array($filas, false);
                    }
                    $clavesOrden = array_fill(0, count($clavesDirectas), []);
                    foreach ($filas as $k => $fila) {
                        foreach ($clavesDirectas as $i => $clave) {
                            $clavesOrden[$i][$k] = $fila[$clave] ?? null;
                        }
                    }
                    $indices = self::ordenNativo($orden, $clavesOrden, array_keys($filas))
                            ?? self::todasOrdenadas(array_keys($filas), self::comparadorDe($orden, $clavesOrden));
                    $clavesOrden = [];
                }
                foreach ($indices as $k) {
                    Memoria::comprobar('la construcción del resultado');
                    $resultado[] = $proyectar($filas[$k], ['fila' => $filas[$k], 'sub' => $sub,
                                                            'conjunto' => $conjunto, 'filaExterna' => $externa]);
                    unset($filas[$k]);                       // cada fila de origen se usa una vez
                }
                unset($filas);
                $orden = [];                                 // ya está ordenado
            } else {
                if (!is_array($filas)) {
                    $filas = iterator_to_array($filas, false);
                }
                // Se va soltando cada fila de origen según se proyecta. Si no, la
                // tabla leída y el resultado conviven enteros hasta el final del
                // bucle, o sea dos copias de lo mismo en el pico.
                foreach (array_keys($filas) as $k) {
                    Memoria::comprobar('la construcción del resultado');
                    $ctx  = ['fila' => $filas[$k], 'sub' => $sub, 'conjunto' => $conjunto,
                             'filaExterna' => $externa];
                    $fila = $proyectar($filas[$k], $ctx);
                    unset($filas[$k]);
                    $resultado[] = $fila;
                    // Sin ORDER BY no hay nada que ordenar: construir las claves
                    // sería un array más por fila para tirarlo enseguida
                    if ($orden !== []) {
                        $this->anotarClaves($clavesOrden, $orden, $ctx, $fila);
                    }
                }
            }
        }

        // DISTINCT
        if ($ast['distinct']) {
            $vistos = [];
            $r = [];
            $k = array_fill(0, count($orden), []);
            foreach ($resultado as $i => $fila) {
                $clave = '';
                foreach ($fila as $v) {
                    $clave .= Valor::clave($v) . "\0";
                }
                if (isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;
                $r[] = $fila;
                foreach ($k as $j => $_) {
                    $k[$j][] = $clavesOrden[$j][$i];
                }
            }
            $resultado   = $r;
            $clavesOrden = $k;
        }

        // ORDER BY
        if ($orden !== []) {
            $ordenadas = [];
            foreach ($this->indicesOrdenados($ast, $orden, $clavesOrden, array_keys($resultado)) as $i) {
                $ordenadas[] = $resultado[$i];
            }
            $resultado = $ordenadas;
        }

        // LIMIT / OFFSET
        if ($ast['limit'] !== null || $ast['offset'] !== null) {
            $resultado = array_slice($resultado, $ast['offset'] ?? 0, $ast['limit']);
        }

        $cols = [];
        foreach ($salida as $c) {
            $cols[] = $c['nombre'];
        }
        return ['cols' => $cols, 'filas' => $resultado];
    }

    /**
     * Claves de ordenación que son columnas de la tabla —directamente o a
     * través de un alias de salida que lo es—, o null si alguna es otra cosa.
     *
     * @return list<string>|null
     */
    private function ordenDirecto(array $orden, array $salida): ?array
    {
        $porAlias = [];
        foreach ($salida as $c) {
            $porAlias[$c['nombre']] = $c['expr'];
        }
        $claves = [];
        foreach ($orden as $o) {
            $e = $o['expr'];
            if ($e['k'] === 'col' && isset($e['alias'])) {
                $e = $porAlias[$e['alias']] ?? $e;
            }
            if ($e['k'] !== 'col' || !isset($e['clave']) || isset($e['externa'])) {
                return null;
            }
            $claves[] = $e['clave'];
        }
        return $claves;
    }

    /**
     * Índices de las filas en el orden pedido. Con LIMIT no hace falta
     * ordenarlo todo: basta con quedarse con las primeras. Ordenar un millón
     * de filas para devolver diez es tirar el trabajo, y además obliga a tener
     * el resultado entero ordenado en memoria a la vez. El desempate por
     * posición original es el mismo que usa el orden estable, así que las filas
     * que salen y su orden son EXACTAMENTE los mismos que ordenando entero y
     * cortando después.
     *
     * Las claves van por columnas —una lista por expresión del ORDER BY,
     * indexada por fila— y no por filas: un array por fila para guardar un
     * valor costaba diez veces lo que el valor.
     *
     * @param array<int, array<int, mixed>> $clavesOrden
     * @param list<int>               $indices  índices de las filas a ordenar
     * @return list<int>
     */
    private function indicesOrdenados(array $ast, array $orden, array $clavesOrden, array $indices): array
    {
        $cuantas = self::cuantasHacenFalta($ast);
        if ($cuantas !== null && $cuantas < count($indices)) {
            return self::primeras($indices, $cuantas, self::comparadorDe($orden, $clavesOrden));
        }
        return self::ordenNativo($orden, $clavesOrden, $indices)
            ?? self::todasOrdenadas($indices, self::comparadorDe($orden, $clavesOrden));
    }

    /**
     * Ordena con array_multisort() cuando cada clave es toda de números o
     * toda de textos: sin llamar a un comparador de PHP por cada pareja, que
     * es lo que hace lento ordenar decenas de miles de filas. El resultado es
     * el mismo que con compararOrden(): los NULL van primero (últimos con
     * DESC), los números como números, y los textos por su clave de colación
     * desempatando byte a byte y después por posición. Con una clave mixta,
     * o con textos que parecen números, se devuelve null y se ordena como
     * siempre.
     *
     * @param array<int, array<int, mixed>> $clavesOrden
     * @param list<int>               $indices
     * @return list<int>|null
     */
    private static function ordenNativo(array $orden, array &$clavesOrden, array $indices): ?array
    {
        // Qué hay en cada columna. Se decide antes de tocar nada: si alguna no
        // sirve, las claves tienen que seguir enteras para compararOrden()
        $tipos = [];
        foreach ($orden as $i => $o) {
            $numeros = $textos = $nulos = 0;
            foreach ($clavesOrden[$i] as $v) {
                if ($v === null) {
                    $nulos++;
                } elseif (is_int($v) || is_float($v)) {
                    $numeros++;
                } elseif (is_string($v) && !is_numeric(trim($v))) {
                    $textos++;
                } else {
                    return null;                         // booleanos, textos numéricos...
                }
            }
            if ($numeros > 0 && $textos > 0) {
                return null;                             // mezcla: solo compararOrden() sabe
            }
            $tipos[$i] = [$textos > 0, $nulos > 0];
        }

        // Una sola clave numérica: asort() en su sitio, sin más arrays. Es el
        // caso corriente (ORDER BY id, por fecha, por importe) y el más barato
        if (count($orden) === 1 && !$tipos[0][0]) {
            $col = $clavesOrden[0];
            unset($clavesOrden[0]);
            $nulos = [];
            if ($tipos[0][1]) {
                foreach ($col as $k => $v) {
                    if ($v === null) {
                        $nulos[] = $k;
                        unset($col[$k]);
                    }
                }
            }
            $orden[0]['dir'] === 'DESC' ? arsort($col, SORT_NUMERIC) : asort($col, SORT_NUMERIC);
            $indices = array_keys($col);
            unset($col);
            // Los NULL van primero; con DESC, los últimos
            return $orden[0]['dir'] === 'DESC' ? array_merge($indices, $nulos) : array_merge($nulos, $indices);
        }

        // Las columnas se sacan de $clavesOrden según se usan, para que
        // array_multisort() las ordene en su sitio sin copiarlas
        $args = [];
        foreach ($orden as $i => $o) {
            [$texto, $hayNulos] = $tipos[$i];
            $col = $clavesOrden[$i];
            unset($clavesOrden[$i]);
            $dir = $o['dir'] === 'DESC' ? SORT_DESC : SORT_ASC;
            if ($hayNulos) {
                $nulos = array_fill(0, count($col), 1);
                foreach ($col as $k => $v) {
                    if ($v === null) {
                        $nulos[$k] = 0;
                        $col[$k]   = $texto ? '' : 0;
                    }
                }
                $args[] = $nulos;
                $args[] = $dir;
                $args[] = SORT_NUMERIC;
            }
            if ($texto && Collation::activa()) {
                $claves = [];
                foreach ($col as $k => $v) {
                    $claves[$k] = $v === '' ? '' : Collation::clave($v);
                }
                $args[] = $claves;
                $args[] = $dir;
                $args[] = SORT_STRING;
            }
            $args[] = $col;
            $args[] = $dir;
            $args[] = $texto ? SORT_STRING : SORT_NUMERIC;
        }
        $args[] = &$indices;                              // el desempate final: la posición
        $args[] = SORT_ASC;
        $args[] = SORT_NUMERIC;
        array_multisort(...$args);
        return $indices;
    }

    /**
     * Comparador de dos índices de fila según las claves de ordenación (por
     * columnas). Las claves se toman por referencia: en la ordenación en
     * streaming van cambiando mientras se compara.
     *
     * @param array<int, array<int, mixed>> $clavesOrden
     */
    private static function comparadorDe(array $orden, array &$clavesOrden): \Closure
    {
        return static function (int $a, int $b) use (&$clavesOrden, $orden): int {
            foreach ($orden as $i => $o) {
                $c = Valor::compararOrden($clavesOrden[$i][$a], $clavesOrden[$i][$b]);
                if ($c !== 0) {
                    return $o['dir'] === 'DESC' ? -$c : $c;
                }
            }
            return $a <=> $b;                        // orden estable
        };
    }

    /**
     * Las $cuantas primeras filas según el ORDER BY, recorriendo el origen
     * una vez y sin tenerlo entero: en memoria solo viven las filas que van
     * ganando. Devuelve esas filas por su índice de llegada y los índices en
     * el orden pedido. Mismo resultado que ordenar todo y cortar (ver
     * primeras()).
     *
     * @param iterable<array> $filas
     * @param list<string>    $claves  columna de cada expresión del ORDER BY
     * @return array{0: array<int, array>, 1: list<int>}
     */
    private static function primerasDe(iterable $filas, array $claves, array $orden, int $cuantas): array
    {
        if ($cuantas <= 0) {
            return [[], []];
        }
        $clavesOrden = array_fill(0, count($claves), []);
        $comparar    = self::comparadorDe($orden, $clavesOrden);
        $monton      = new class ($comparar) extends \SplHeap {
            /** @var callable */
            private $comparar;

            public function __construct(callable $comparar)
            {
                $this->comparar = $comparar;
            }

            protected function compare($a, $b): int
            {
                return ($this->comparar)($a, $b);
            }
        };
        $vivas = [];
        $k     = 0;
        foreach ($filas as $fila) {
            foreach ($claves as $i => $clave) {
                $clavesOrden[$i][$k] = $fila[$clave] ?? null;
            }
            if ($monton->count() < $cuantas) {
                $vivas[$k] = $fila;
                $monton->insert($k);
            } elseif ($comparar($k, $monton->top()) < 0) {
                $fuera = $monton->extract();
                unset($vivas[$fuera]);
                foreach ($clavesOrden as $i => $_) {
                    unset($clavesOrden[$i][$fuera]);
                }
                $vivas[$k] = $fila;
                $monton->insert($k);
            } else {
                foreach ($clavesOrden as $i => $_) {
                    unset($clavesOrden[$i][$k]);
                }
            }
            $k++;
            Memoria::comprobar('la ordenación');
        }
        $elegidos = [];
        foreach ($monton as $i) {
            $elegidos[] = $i;
        }
        usort($elegidos, $comparar);              // son pocas: ordenarlas es barato
        return [$vivas, $elegidos];
    }

    /**
     * Cuántas filas hay que dejar ordenadas para responder, o null si todas.
     *
     * Con `LIMIT 10 OFFSET 5` hacen falta las 15 primeras: el OFFSET se aplica
     * después sobre ellas.
     */
    private static function cuantasHacenFalta(array $ast): ?int
    {
        if ($ast['limit'] === null) {
            return null;                          // sin LIMIT hay que ordenarlo todo
        }
        $limite = (int)$ast['limit'];
        $salto  = (int)($ast['offset'] ?? 0);
        if ($limite < 0 || $salto < 0) {
            return null;
        }
        return $limite + $salto;
    }

    /**
     * Ordena la lista entera.
     *
     * @param list<int> $indices
     */
    private static function todasOrdenadas(array $indices, callable $comparar): array
    {
        usort($indices, $comparar);
        return $indices;
    }

    /**
     * Las $cuantas primeras según $comparar, sin ordenar el resto.
     *
     * Mantiene un montón con las mejores vistas hasta ahora: cada fila nueva se
     * compara con la peor de ellas y se descarta enseguida si no entra. Así el
     * coste pasa de ordenar n elementos a recorrerlos comparando contra un
     * conjunto de tamaño fijo, y en memoria solo viven esas $cuantas posiciones.
     *
     * @param list<int> $indices
     * @return list<int>
     */
    private static function primeras(array $indices, int $cuantas, callable $comparar): array
    {
        if ($cuantas <= 0) {
            return [];                            // LIMIT 0: no hace falta ninguna
        }
        // Montón con el PEOR de los elegidos arriba, para poder echarlo cuando
        // entre uno mejor. compare() devuelve positivo si el primero va más
        // arriba, así que basta con devolver la comparación tal cual.
        $monton = new class ($comparar) extends \SplHeap {
            /** @var callable */
            private $comparar;

            public function __construct(callable $comparar)
            {
                $this->comparar = $comparar;
            }

            protected function compare($a, $b): int
            {
                return ($this->comparar)($a, $b);
            }
        };

        foreach ($indices as $i) {
            if ($monton->count() < $cuantas) {
                $monton->insert($i);
                continue;
            }
            if ($comparar($i, $monton->top()) < 0) {
                $monton->extract();
                $monton->insert($i);
            }
        }

        $elegidos = [];
        foreach ($monton as $i) {
            $elegidos[] = $i;
        }
        usort($elegidos, $comparar);              // son pocas: ordenarlas es barato
        return $elegidos;
    }

    /**
     * Ejecuta las partes de un UNION y las junta.
     *
     * Las columnas de salida son las de la primera parte: las demás aportan sus
     * valores en el mismo orden, aunque sus columnas se llamen distinto, que es
     * lo que hace SQL.
     *
     * @return array{cols: string[], filas: array}
     */
    /**
     * SELECT COUNT(*) FROM tabla, sin nada más: el resultado es el número de
     * filas, que Storage sabe contar sin decodificar ni materializar la tabla.
     * En cuanto la consulta tiene cualquier otra cosa —WHERE, GROUP BY, JOIN,
     * DISTINCT, más columnas, una vista o una CTE— se sigue el camino normal,
     * que es quien sabe hacerla bien.
     */
    private function contarRapido(array $ast): ?array
    {
        // Con un lector propio (un trigger en mitad de una escritura) las filas
        // buenas están en memoria, no en disco: cuenta el camino normal.
        if (!$this->indexable) {
            return null;
        }
        if ($ast['where'] !== null || $ast['group'] !== null || $ast['having'] !== null
            || $ast['distinct'] || $ast['order'] !== [] || $ast['limit'] !== null
            || $ast['offset'] !== null || count($ast['from']) !== 1 || count($ast['cols']) !== 1) {
            return null;
        }
        $o = $ast['from'][0];
        if ($o['tipo'] !== 'tabla'
            || isset($this->con[strtolower($o['nombre'])]) || isset($this->con[$o['nombre']])
            || $this->cat->esVista($o['nombre'])) {
            return null;
        }
        $c = $ast['cols'][0];
        $e = $c['expr'] ?? null;
        if (($c['star'] ?? false) || !is_array($e) || ($e['k'] ?? '') !== 'fn'
            || strtoupper((string)$e['nombre']) !== 'COUNT' || !($e['star'] ?? false)) {
            return null;
        }
        if (!$this->cat->existe($o['nombre'])) {
            throw JsonSqlDbError::schema("La tabla '{$o['nombre']}' no existe");
        }
        $nombre = $c['alias'] ?? self::etiqueta($e);
        return ['cols' => [$nombre], 'filas' => [[$nombre => $this->cat->storage()->contarFilas($o['nombre'])]]];
    }

    private function correrUnion(array $ast): array
    {
        $cols  = [];
        $filas = [];

        foreach ($ast['partes'] as $i => $parte) {
            $r = $this->correr($parte);

            if ($i === 0) {
                $cols = $r['cols'];
            } elseif (count($r['cols']) !== count($cols)) {
                throw JsonSqlDbError::syntax(
                    'Todas las partes de un UNION tienen que devolver el mismo número de '
                    . 'columnas: la primera devuelve ' . count($cols) . ' y otra devuelve '
                    . count($r['cols'])
                );
            }

            // Las columnas se toman por posición y se renombran a las de la
            // primera parte, aunque en su SELECT se llamen de otra forma
            $nuevas = [];
            foreach ($r['filas'] as $fila) {
                $valores = array_values($fila);
                $n       = [];
                foreach ($cols as $j => $nombre) {
                    $n[$nombre] = $valores[$j] ?? null;
                }
                $nuevas[] = $n;
            }

            if ($i === 0) {
                $filas = $nuevas;
                continue;
            }

            switch ($ast['ops'][$i - 1] ?? 'UNION') {
                case 'UNION ALL':                 // se conserva todo, repetidos incluidos
                    $filas = array_merge($filas, $nuevas);
                    break;

                case 'UNION':                     // lo de A o lo de B, sin repetir
                    $filas = self::sinRepetidos(array_merge($filas, $nuevas));
                    break;

                case 'INTERSECT':                 // solo lo que está en las dos
                    $deB   = self::indice($nuevas);
                    $filas = self::sinRepetidos(array_filter(
                        $filas,
                        static fn(array $f): bool => isset($deB[self::claveFila($f)])
                    ));
                    break;

                case 'EXCEPT':                    // lo de A que no está en B
                    $deB   = self::indice($nuevas);
                    $filas = self::sinRepetidos(array_filter(
                        $filas,
                        static fn(array $f): bool => !isset($deB[self::claveFila($f)])
                    ));
                    break;
            }
        }

        $filas = $this->ordenarUnion($filas, $cols, $ast['order']);

        if ($ast['limit'] !== null || $ast['offset'] !== null) {
            $filas = array_slice($filas, $ast['offset'] ?? 0, $ast['limit']);
        }

        return ['cols' => $cols, 'filas' => array_values($filas)];
    }

    /**
     * Cuántas filas hacen falta como mucho, o null si hay que recorrerlo todo.
     *
     * Con ORDER BY, GROUP BY, agregados o DISTINCT, la fila número 5.000 puede
     * cambiar el resultado, así que no se puede cortar antes de tiempo.
     */
    private function topeTemprano(array $ast): ?int
    {
        if ($ast['limit'] === null || $ast['order'] !== [] || $ast['distinct']) {
            return null;
        }
        if (($ast['group'] ?? []) !== [] || ($ast['having'] ?? null) !== null) {
            return null;
        }
        foreach ($ast['cols'] as $c) {
            if (($c['star'] ?? false) === false && Evaluator::tieneAgregado($c['expr'])) {
                return null;
            }
        }
        return (int)$ast['limit'] + (int)($ast['offset'] ?? 0);
    }

    /**
     * Si el WHERE es  columna <op> literal , devuelve las piezas para comparar
     * directamente. Si no, null y se usa el evaluador general.
     *
     * @return array{clave: string, op: string, valor: mixed}|null
     */
    public static function comparacionSimple(array $n): ?array
    {
        if ($n['k'] !== 'bin' || !in_array($n['op'], ['=', '<>', '!=', '<', '<=', '>', '>='], true)) {
            return null;
        }
        // La columna a un lado y un literal al otro, en cualquier orden
        [$col, $lit, $op] = [$n['i'], $n['d'], $n['op']];
        if ($col['k'] !== 'col' || $lit['k'] !== 'lit') {
            [$col, $lit] = [$n['d'], $n['i']];
            if ($col['k'] !== 'col' || $lit['k'] !== 'lit') {
                return null;
            }
            $op = ['<' => '>', '>' => '<', '<=' => '>=', '>=' => '<='][$op] ?? $op;
        }
        if (!isset($col['clave']) || $lit['v'] === null) {
            return null;                            // alias o NULL: al camino general
        }
        return ['clave' => $col['clave'], 'op' => $op, 'valor' => $lit['v']];
    }

    /** @param mixed $a @param mixed $b */
    public static function compara(string $op, $a, $b): bool
    {
        $c = Valor::comparar($a, $b);
        if ($c === null) {
            return false;                           // incomparables: no cumple
        }
        switch ($op) {
            case '=':  return $c === 0;
            case '<>':
            case '!=': return $c !== 0;
            case '<':  return $c < 0;
            case '<=': return $c <= 0;
            case '>':  return $c > 0;
            case '>=': return $c >= 0;
        }
        return false;
    }

    /** Clave comparable de una fila completa, para deduplicar y cruzar. */
    /**
     * Intenta resolver la lectura de una tabla por índice.
     *
     * Devuelve null cuando no hay índice que sirva, cuando el que hay no está al
     * día o cuando haría falta leer casi toda la tabla igualmente: en todos esos
     * casos la lectura sigue por el camino de siempre.
     *
     * @param array<string, list<mixed>> $predicados
     */
    private function porIndice(string $tabla, array $predicados): ?array
    {
        if (!$this->indexable || $predicados === []) {
            return null;
        }
        $st = $this->cat->storage();
        if (!$st->indicesActivos()) {
            return null;
        }
        $elegido = Indexes::elegir($this->cat->indicesDe($tabla), $predicados);
        if ($elegido === null) {
            return null;
        }
        return $st->filasPorIndice($tabla, $elegido['def'], $elegido['claves'], $elegido['prefijo']);
    }

    private static function claveFila(array $fila): string
    {
        $k = '';
        foreach ($fila as $v) {
            $k .= Valor::clave($v) . "\0";
        }
        return $k;
    }

    /** @return array<string,true> */
    private static function indice(array $filas): array
    {
        $out = [];
        foreach ($filas as $f) {
            $out[self::claveFila($f)] = true;
        }
        return $out;
    }

    private static function sinRepetidos(array $filas): array
    {
        $vistos = [];
        $out    = [];
        foreach ($filas as $f) {
            $k = self::claveFila($f);
            if (isset($vistos[$k])) {
                continue;
            }
            $vistos[$k] = true;
            $out[]      = $f;
        }
        return $out;
    }

    /**
     * ORDER BY de un UNION. Solo admite nombres de columna del resultado o su
     * posición (1, 2...): en ese punto las tablas de origen ya no existen.
     *
     * @param string[] $cols
     */
    private function ordenarUnion(array $filas, array $cols, array $orden): array
    {
        if ($orden === [] || $filas === []) {
            return $filas;
        }

        $claves = [];
        foreach ($orden as $o) {
            $e = $o['expr'];
            if ($e['k'] === 'col' && $e['tabla'] === null && in_array($e['nombre'], $cols, true)) {
                $claves[] = $e['nombre'];
                continue;
            }
            if ($e['k'] === 'lit' && is_int($e['v']) && isset($cols[$e['v'] - 1])) {
                $claves[] = $cols[$e['v'] - 1];
                continue;
            }
            throw JsonSqlDbError::syntax(
                'El ORDER BY de un UNION solo admite nombres de columna del resultado o su '
                . 'posición (1, 2...)'
            );
        }

        $indices = array_keys($filas);
        usort($indices, static function (int $a, int $b) use ($filas, $claves, $orden): int {
            foreach ($claves as $i => $col) {
                $c = Valor::compararOrden($filas[$a][$col] ?? null, $filas[$b][$col] ?? null);
                if ($c !== 0) {
                    return $orden[$i]['dir'] === 'DESC' ? -$c : $c;
                }
            }
            return $a <=> $b;                        // orden estable
        });

        $out = [];
        foreach ($indices as $i) {
            $out[] = $filas[$i];
        }
        return $out;
    }

    // ==================================================================
    // Orígenes de datos y JOIN
    // ==================================================================

    /**
     * Las filas del FROM y sus columnas. Cada columna es [alias, nombre,
     * clave], donde la clave es con la que está en la fila: el nombre a secas
     * si hay un solo origen, y `alias.nombre` si hay varios, para que dos
     * tablas con una columna igual no se pisen.
     *
     * @return array{0: iterable<array>, 1: list<array{0: string, 1: string, 2: string}>}
     */
    private function origenes(array $ast, ?int $tope = null): array
    {
        $from  = $ast['from'];
        $where = $ast['where'];
        if ($from === []) {
            return [[[]], []];                            // SELECT sin FROM: una fila vacía
        }

        // Con un solo origen, una columna sin cualificar solo puede ser suya.
        // Con varios haría falta el mapa de columnas, y para eso ya habría que
        // haber leído las tablas, que es justo lo que se quiere evitar.
        $unico = count($from) === 1 && $from[0]['tipo'] === 'tabla'
            ? strtolower((string)($from[0]['alias'] ?? $from[0]['nombre']))
            : null;
        $predicados = $this->indexable ? Indexes::predicados($where, $unico) : [];

        // El tope solo se puede aplicar al leer si no hay nada que filtrar
        // después: con WHERE o con JOIN, las filas que sobran no son las
        // últimas, y quedarse con las primeras daría un resultado distinto.
        $topeLectura = $where === null && count($from) === 1 ? $tope : null;

        // El primer origen se recorre sin materializarlo: con un solo origen,
        // el WHERE va descartando filas según llegan y en memoria solo quedan
        // las que pasan. Los demás son el lado interno del cruce y hacen falta
        // enteros.
        $varios  = count($from) > 1;
        $usadas  = $varios ? self::columnasUsadas($ast) : null;
        $primero = $this->cargar($from[0], $predicados, $topeLectura, $varios, $usadas);
        $filas   = $primero['filas'];
        $fuentes = $primero['fuentes'];

        for ($i = 1, $n = count($from); $i < $n; $i++) {
            $der          = $this->cargar($from[$i], $predicados, null, true, $usadas);
            $der['filas'] = iterator_to_array($der['filas'], false);
            $filas   = $this->unir($filas, $fuentes, $der, $from[$i]);
            $fuentes = array_merge($fuentes, $der['fuentes']);
        }
        return [$filas, $fuentes];
    }

    /**
     * Columnas que nombra la consulta, por alias (en minúsculas) y sin
     * cualificar, para cargar de cada origen de un cruce solo las que se
     * usan. Se recorre el árbol entero, subconsultas incluidas: de más nunca
     * hace daño. Null si hay un `*` sin tabla, que las quiere todas.
     *
     * @return array{0: array<string,array<string,true>>, 1: array<string,true>, 2: array<string,true>}|null
     *         [por alias, sin cualificar, alias con `alias.*`]
     */
    private static function columnasUsadas(array $ast): ?array
    {
        $porAlias = [];
        $sueltas  = [];
        $todas    = [];
        $ok = self::recorrerColumnas($ast, $porAlias, $sueltas, $todas);
        return $ok ? [$porAlias, $sueltas, $todas] : null;
    }

    private static function recorrerColumnas(array $n, array &$porAlias, array &$sueltas, array &$todas): bool
    {
        if (!empty($n['star'])) {
            if (($n['tabla'] ?? null) === null) {
                return false;
            }
            $todas[strtolower((string)$n['tabla'])] = true;
        } elseif (($n['k'] ?? null) === 'col' && isset($n['nombre'])) {
            if (($n['tabla'] ?? null) === null) {
                $sueltas[strtolower((string)$n['nombre'])] = true;
            } else {
                $porAlias[strtolower((string)$n['tabla'])][strtolower((string)$n['nombre'])] = true;
            }
        }
        foreach ($n as $v) {
            if (is_array($v) && !self::recorrerColumnas($v, $porAlias, $sueltas, $todas)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Carga una tabla o subconsulta. Con $prefijar, cada fila se copia con
     * las claves `alias.columna`, que es lo que necesita un cruce, y solo
     * con las columnas que la consulta usa ($usadas); sin él, las filas salen
     * tal como están en la tabla, sin copiarlas, y en memoria son las mismas
     * que ya tiene la caché.
     *
     * @return array{fuentes: list<array{0: string, 1: string, 2: string}>, filas: iterable<array>}
     */
    private function cargar(array $o, array $predicados, ?int $tope, bool $prefijar, ?array $usadas): array
    {
        if ($o['tipo'] === 'sub') {
            $r      = $this->correr($o['select']);
            $alias  = $o['alias'];
            $cols   = $r['cols'];
            $origen = $r['filas'];
        } elseif (isset($this->con[$o['nombre']])) {
            // Una consulta con nombre del WITH: se ejecuta como una subconsulta
            $r      = $this->correr($this->con[$o['nombre']]);
            $alias  = $o['alias'] ?? $o['nombre'];
            $cols   = $r['cols'];
            $origen = $r['filas'];
            unset($r);                     // que no queden dos referencias a las filas
        } elseif ($this->cat->esVista($o['nombre'])) {
            // Una vista es un SELECT guardado: se analiza y se ejecuta igual
            // que una subconsulta del FROM.
            $vista = $this->cat->vista($o['nombre']);

            if ($this->profundidadVista >= self::MAX_VISTAS) {
                throw JsonSqlDbError::schema(
                    "Demasiadas vistas anidadas en '{$o['nombre']}' (máximo " . self::MAX_VISTAS
                    . '): comprueba que no se referencian entre ellas'
                );
            }

            $this->profundidadVista++;
            try {
                $r = $this->correr(Parser::analizar((string)$vista['sql']));
            } finally {
                $this->profundidadVista--;
            }
            $alias  = $o['alias'] ?? $o['nombre'];
            $cols   = $r['cols'];
            $origen = $r['filas'];
            unset($r);                     // que no queden dos referencias a las filas
        } else {
            $nombre = $o['nombre'];
            if (!$this->cat->existe($nombre)) {
                throw JsonSqlDbError::schema("La tabla '$nombre' no existe");
            }
            $alias  = $o['alias'] ?? $nombre;
            $meta   = $this->cat->meta($nombre);
            $cols   = [];
            foreach ($meta['columns'] as $c) {
                $cols[] = $c['name'];
            }
            // Con un índice aprovechable se leen solo las partes donde están las
            // filas buscadas; si no lo hay, o no compensa, se recorre la tabla.
            $origen = $this->porIndice($nombre, $predicados[strtolower($alias)] ?? [])
                   ?? ($this->lector)($nombre);
        }

        if ($prefijar && $usadas !== null && !isset($usadas[2][strtolower($alias)])) {
            [$porAlias, $sueltas] = $usadas;
            $suyas = $porAlias[strtolower($alias)] ?? [];
            $cols  = array_values(array_filter($cols,
                static fn(string $c): bool => isset($suyas[strtolower($c)]) || isset($sueltas[strtolower($c)])));
        }
        $fuentes = [];
        foreach ($cols as $c) {
            $fuentes[] = [$alias, $c, $prefijar ? $alias . '.' . $c : $c];
        }
        if ($prefijar) {
            $origen = self::aplanar($origen, $cols, $alias);
        } elseif ($tope !== null) {
            $origen = self::acotar($origen, $tope);
        }
        return ['fuentes' => $fuentes, 'filas' => $origen];
    }

    /**
     * Copia cada fila con las claves prefijadas por el alias, según se pide.
     *
     * @param iterable<array> $origen
     * @param list<string>    $cols
     */
    private static function aplanar(iterable $origen, array $cols, string $alias): \Generator
    {
        foreach ($origen as $fila) {
            $plana = [];
            foreach ($cols as $c) {
                $plana[$alias . '.' . $c] = $fila[$c] ?? null;
            }
            Memoria::comprobar('la carga de la tabla');
            yield $plana;
        }
    }

    /**
     * Las filas tal cual, parando en el tope.
     *
     * @param iterable<array> $origen
     */
    private static function acotar(iterable $origen, int $tope): \Generator
    {
        $n = 0;
        foreach ($origen as $fila) {
            if ($n++ >= $tope) {
                return;
            }
            yield $fila;
        }
    }

    /**
     * Une el acumulado de la izquierda con un nuevo origen. Las filas
     * cruzadas salen según se producen: quien las consume —el WHERE, la
     * agrupación— no necesita el cruce entero en memoria.
     *
     * @param iterable<array> $izq
     * @return \Generator<int, array>
     */
    private function unir(iterable $izq, array $fuentesIzq, array $der, array $o): \Generator
    {
        $tipo      = $o['join'] ?? 'CROSS';
        $clavesIzq = array_column($fuentesIzq, 2);
        $clavesDer = array_column($der['fuentes'], 2);

        if ($tipo === 'CROSS' || $o['on'] === null) {
            foreach ($izq as $a) {
                foreach ($der['filas'] as $b) {
                    Memoria::comprobar('el producto cartesiano');
                    yield $a + $b;
                }
            }
            return;
        }

        $mapa = $this->mapaColumnas(array_merge($fuentesIzq, $der['fuentes']));
        $on   = Evaluator::resolver($o['on'], $mapa, [], $this->externo['mapa'] ?? []);
        [$pares, $resto] = $this->igualdades($on, array_flip($clavesDer));

        // RIGHT JOIN = mismo algoritmo con los papeles cambiados. El lado
        // interno se recorre varias veces y se indexa por posición: entero.
        $derecho     = $tipo === 'RIGHT';
        if ($derecho && !is_array($izq)) {
            $izq = iterator_to_array($izq, false);
        }
        $externas    = $derecho ? $der['filas'] : $izq;
        $internas    = $derecho ? $izq : $der['filas'];
        $clavesExt   = [];
        $clavesInt   = [];
        foreach ($pares as [$ki, $kd]) {
            $clavesExt[] = $derecho ? $kd : $ki;
            $clavesInt[] = $derecho ? $ki : $kd;
        }
        $nulos = array_fill_keys($derecho ? $clavesIzq : $clavesDer, null);
        $sub   = function (array $sel, int $sid): array {
            return $this->subs[$sid] ??= $this->correr($sel)['filas'];
        };
        // Aquí la subconsulta nunca mira hacia fuera —se resuelve entera antes
        // del cruce— así que su conjunto se puede guardar siempre
        $conjunto = function (array $sel, int $sid) use ($sub): array {
            if (!isset($this->conjuntos[$sid])) {
                $valores = [];
                foreach ($sub($sel, $sid) as $fila) {
                    $valores[] = reset($fila);
                }
                $this->conjuntos[$sid] = Indexes::conjunto($valores);
            }
            return $this->conjuntos[$sid];
        };

        // Índice hash del lado interno. Guarda posiciones, no filas, para poder
        // saber después cuáles quedaron sin pareja (lo necesita FULL JOIN).
        $indice = null;
        if ($clavesInt !== []) {
            $indice = [];
            foreach ($internas as $i => $fila) {
                $clave = $this->claveHash($fila, $clavesInt);
                if ($clave === null) {
                    continue;
                }
                // Una posición sola se guarda como entero: en una clave única
                // son todas, y una lista de uno por fila cuesta el triple
                if (!isset($indice[$clave])) {
                    $indice[$clave] = $i;
                } elseif (is_int($indice[$clave])) {
                    $indice[$clave] = [$indice[$clave], $i];
                } else {
                    $indice[$clave][] = $i;
                }
            }
        }

        $completo = $tipo === 'FULL';
        $casadas  = [];                     // posiciones internas que sí casaron

        // Sin índice hay que probar contra todas las filas internas, y esa lista
        // es la misma en cada vuelta: se calcula una vez. Estaba dentro del
        // bucle y se descartaba enseguida cuando sí había índice, así que un
        // JOIN de 30.000 por 20.000 filas construía treinta mil veces un array
        // de veinte mil claves para no usarlo.
        $todas = $indice === null ? array_keys($internas) : [];

        foreach ($externas as $ext) {
            if ($indice === null) {
                $candidatas = $todas;
            } else {
                // Una sola llamada: claveHash() recorre la fila y no es gratis
                $clave      = $this->claveHash($ext, $clavesExt);
                $candidatas = $clave === null ? [] : ($indice[$clave] ?? []);
                if (is_int($candidatas)) {
                    $candidatas = [$candidatas];
                }
            }

            $encontrada = false;
            foreach ($candidatas as $i) {
                $fila = $ext + $internas[$i];
                if ($resto !== null
                    && Valor::verdadero(Evaluator::evaluar($resto, ['fila' => $fila, 'sub' => $sub,
                        'conjunto' => $conjunto,
                        'filaExterna' => $this->externo['fila'] ?? []])) !== true) {
                    continue;
                }
                Memoria::comprobar('el JOIN');
                yield $fila;
                $encontrada = true;
                if ($completo) {
                    $casadas[$i] = true;
                }
            }
            if (!$encontrada && $tipo !== 'INNER') {
                yield $ext + $nulos;
            }
        }

        // FULL JOIN: además, las filas internas que no casaron con ninguna,
        // rellenando con nulos el lado externo
        if ($completo) {
            // En un FULL el lado externo es siempre el izquierdo, así que los
            // nulos que faltan son los de sus columnas
            $nulosExt = array_fill_keys($clavesIzq, null);
            foreach ($internas as $i => $int) {
                if (!isset($casadas[$i])) {
                    yield $int + $nulosExt;
                }
            }
        }
    }

    /** Clave de igualdad de una fila; null si algún valor es NULL (NULL nunca casa). */
    private function claveHash(array $fila, array $claves): ?string
    {
        $k = '';
        foreach ($claves as $c) {
            $v = $fila[$c] ?? null;
            if ($v === null) {
                return null;
            }
            $k .= Valor::clave($v) . "\0";
        }
        return $k;
    }

    /**
     * Separa la condición ON en igualdades directas (para el hash) y el resto.
     * @return array{0: array<int,array{0:string,1:string}>, 1: ?array}
     */
    private function igualdades(array $on, array $clavesDer): array
    {
        $pares = [];
        $resto = [];

        $pila = [$on];
        while ($pila !== []) {
            $n = array_pop($pila);
            if ($n['k'] === 'bin' && $n['op'] === 'AND') {
                $pila[] = $n['i'];
                $pila[] = $n['d'];
                continue;
            }
            if ($n['k'] === 'bin' && $n['op'] === '='
                && $n['i']['k'] === 'col' && $n['d']['k'] === 'col'
                && isset($n['i']['clave'], $n['d']['clave'])) {
                $iEsDer = isset($clavesDer[$n['i']['clave']]);
                $dEsDer = isset($clavesDer[$n['d']['clave']]);
                if ($iEsDer !== $dEsDer) {
                    $pares[] = $iEsDer
                        ? [$n['d']['clave'], $n['i']['clave']]
                        : [$n['i']['clave'], $n['d']['clave']];
                    continue;
                }
            }
            $resto[] = $n;
        }

        $condicion = null;
        foreach ($resto as $r) {
            $condicion = $condicion === null ? $r : ['k' => 'bin', 'op' => 'AND', 'i' => $condicion, 'd' => $r];
        }
        return [$pares, $condicion];
    }

    // ==================================================================
    // Columnas, agrupación y orden
    // ==================================================================

    /**
     * 'alias.col' => clave, y 'col' => clave (false si es ambigua).
     *
     * @param list<array{0: string, 1: string, 2: string}> $fuentes
     */
    private function mapaColumnas(array $fuentes): array
    {
        $mapa = [];
        foreach ($fuentes as [$alias, $col, $clave]) {
            $mapa[strtolower($alias . '.' . $col)] = $clave;
            $corto = strtolower($col);
            if (array_key_exists($corto, $mapa)) {
                if ($mapa[$corto] !== $clave) {
                    $mapa[$corto] = false;
                }
            } else {
                $mapa[$corto] = $clave;
            }
        }
        return $mapa;
    }

    /**
     * Expande * y calcula el nombre de salida de cada columna.
     *
     * @param list<array{0: string, 1: string, 2: string}> $fuentes
     */
    private function columnasSalida(array $cols, array $fuentes, array $mapa): array
    {
        $salida = [];
        $usados = [];

        $anadir = function (string $nombre, array $expr) use (&$salida, &$usados): void {
            $base = $nombre;
            $n    = 2;
            while (isset($usados[$nombre])) {
                $nombre = $base . '_' . $n++;
            }
            $usados[$nombre] = true;
            $salida[] = ['nombre' => $nombre, 'expr' => $expr];
        };

        foreach ($cols as $c) {
            if (!empty($c['star'])) {
                $encontradas = 0;
                foreach ($fuentes as [$alias, $corto, $clave]) {
                    if ($c['tabla'] !== null && strcasecmp($alias, $c['tabla']) !== 0) {
                        continue;
                    }
                    $anadir($corto, ['k' => 'col', 'tabla' => $alias, 'nombre' => $corto, 'clave' => $clave]);
                    $encontradas++;
                }
                if ($c['tabla'] !== null && $encontradas === 0) {
                    throw JsonSqlDbError::schema("No hay ninguna tabla o alias '{$c['tabla']}' en el FROM");
                }
                continue;
            }
            $expr = Evaluator::resolver($c['expr'], $mapa, [], $this->externo['mapa'] ?? []);
            $anadir($c['alias'] ?? self::etiqueta($c['expr']), $expr);
        }

        if ($salida === []) {
            throw JsonSqlDbError::syntax('El SELECT no devuelve ninguna columna');
        }
        return $salida;
    }

    private function hayAgregados(array $salida, array $ast): bool
    {
        foreach ($salida as $c) {
            if (Evaluator::tieneAgregado($c['expr'])) {
                return true;
            }
        }
        foreach ($ast['order'] as $o) {
            if (Evaluator::tieneAgregado($o['expr'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Las filas que cumplen el WHERE, según se recorren. Con una comparación
     * simple o una condición compilable no se pasa por el evaluador general
     * en cada fila, que es lo que domina el coste en tablas grandes.
     *
     * @param iterable<array> $filas
     */
    private function filtrar(iterable $filas, array $where, callable $sub, callable $conjunto, array $externa, ?int $tope): \Generator
    {
        $simple    = self::comparacionSimple($where);
        $compilado = $simple === null ? Evaluator::compilar($where) : null;
        $n = 0;
        foreach ($filas as $fila) {
            if ($simple !== null) {
                $v    = $fila[$simple['clave']] ?? null;
                $vale = $v === null ? false : self::compara($simple['op'], $v, $simple['valor']);
            } elseif ($compilado !== null) {
                $vale = Valor::verdadero($compilado($fila)) === true;
            } else {
                $vale = Valor::verdadero(Evaluator::evaluar(
                    $where, ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto,
                             'filaExterna' => $externa])) === true;
            }
            if ($vale) {
                Memoria::comprobar('el filtrado del WHERE');
                yield $fila;
                if ($tope !== null && ++$n >= $tope) {
                    return;                             // ya no hacen falta más
                }
            }
        }
    }

    /**
     * Agrupa las filas acumulando sus agregados, sin guardar las filas: de
     * cada grupo queda su primera fila —contra la que se evalúan las columnas
     * sin agregar— y el resultado de cada acumulador de $defs.
     *
     * COUNT, SUM, AVG, MIN y MAX sin DISTINCT se acumulan sobre la marcha con
     * el mismo cálculo que hace Functions::agregado() sobre la lista entera.
     * Con DISTINCT, y en GROUP_CONCAT, se guardan los valores (solo los de esa
     * columna) y se delega en él al final.
     *
     * @param iterable<array>      $filas
     * @param array<string,array>  $defs   ver Evaluator::marcarAgregados()
     * @return list<array{0: array, 1: array<string,mixed>}> [fila representativa, agregados]
     */
    private function agrupar(iterable $filas, array $exprs, array $defs, callable $sub, callable $conjunto, array $externa): array
    {
        $args = [];
        foreach ($defs as $id => $d) {
            $args[$id] = $d['arg'] === null ? null : (Evaluator::compilar($d['arg']) ?? $d['arg']);
        }
        $claves = [];
        foreach ($exprs as $e) {
            $claves[] = Evaluator::compilar($e) ?? $e;
        }

        // Por grupo: la primera fila, cuántas filas, y por acumulador la suma
        // y el recuento de no nulos (SUM, AVG, COUNT), el mejor valor visto
        // (MIN, MAX) o la lista de valores (DISTINCT, GROUP_CONCAT)
        $grupos = [];
        foreach ($filas as $fila) {
            $ctx   = ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa];
            $clave = '';
            foreach ($claves as $e) {
                $clave .= Valor::clave($e instanceof \Closure ? $e($fila) : Evaluator::evaluar($e, $ctx)) . "\0";
            }
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = ['fila' => $fila, 'n' => 0, 'suma' => [], 'cuenta' => [], 'mejor' => [], 'lista' => []];
                Memoria::comprobar('la agrupación');
            }
            $grupos[$clave]['n']++;
            foreach ($defs as $id => $d) {
                if ($d['star']) {
                    continue;                           // COUNT(*): basta con contar filas
                }
                $a = $args[$id];
                $v = $a instanceof \Closure ? $a($fila) : Evaluator::evaluar($a, $ctx);
                if ($v === null) {
                    continue;                           // los agregados ignoran los NULL
                }
                if ($d['distinct'] || $d['nombre'] === 'GROUP_CONCAT') {
                    $grupos[$clave]['lista'][$id][] = $v;
                } elseif ($d['nombre'] === 'MIN' || $d['nombre'] === 'MAX') {
                    if (!array_key_exists($id, $grupos[$clave]['mejor'])) {
                        $grupos[$clave]['mejor'][$id] = $v;
                    } else {
                        $c = Valor::comparar($v, $grupos[$clave]['mejor'][$id]);
                        if ($c !== null && (($d['nombre'] === 'MIN' && $c < 0) || ($d['nombre'] === 'MAX' && $c > 0))) {
                            $grupos[$clave]['mejor'][$id] = $v;
                        }
                    }
                } else {                                // COUNT(x), SUM, AVG
                    $grupos[$clave]['suma'][$id]   = ($grupos[$clave]['suma'][$id] ?? 0) + Valor::aNumero($v);
                    $grupos[$clave]['cuenta'][$id] = ($grupos[$clave]['cuenta'][$id] ?? 0) + 1;
                }
            }
        }
        if ($exprs === [] && $grupos === []) {
            // Agregación total: una fila aunque no haya datos
            $grupos[''] = ['fila' => [], 'n' => 0, 'suma' => [], 'cuenta' => [], 'mejor' => [], 'lista' => []];
        }

        $out = [];
        foreach ($grupos as $g) {
            $valores = [];
            foreach ($defs as $id => $d) {
                $cuenta = $g['cuenta'][$id] ?? 0;
                if ($d['star']) {
                    $valores[$id] = $g['n'];
                } elseif ($d['distinct'] || $d['nombre'] === 'GROUP_CONCAT') {
                    $sep = ',';
                    if ($d['sep'] !== null) {
                        $s   = Evaluator::evaluar($d['sep'], ['fila' => $g['fila'], 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa]);
                        $sep = $s === null ? '' : Valor::aTexto($s);
                    }
                    $valores[$id] = Functions::agregado($d['nombre'], $g['lista'][$id] ?? [], $g['n'], $d['distinct'], $sep);
                } elseif ($d['nombre'] === 'COUNT') {
                    $valores[$id] = $cuenta;
                } elseif ($d['nombre'] === 'SUM') {
                    $valores[$id] = $cuenta === 0 ? null : $g['suma'][$id];
                } elseif ($d['nombre'] === 'AVG') {
                    $valores[$id] = $cuenta === 0 ? null : $g['suma'][$id] / $cuenta;
                } else {
                    $valores[$id] = $g['mejor'][$id] ?? null;   // MIN / MAX
                }
            }
            $out[] = [$g['fila'], $valores];
        }
        return $out;
    }

    /**
     * Añade a las claves de ordenación (por columnas) las de una fila.
     *
     * @param array<int, array<int, mixed>> $clavesOrden
     */
    private function anotarClaves(array &$clavesOrden, array $orden, array $ctx, array $proyectada): void
    {
        $ctx['fila'] = $ctx['fila'] + $proyectada;        // permite ORDER BY por alias de salida
        foreach ($orden as $i => $o) {
            $clavesOrden[$i][] = Evaluator::evaluar($o['expr'], $ctx);
        }
    }

    /** Nombre por defecto de una columna calculada, parecido al que da SQLite. */
    public static function etiqueta(array $n): string
    {
        switch ($n['k']) {
            case 'col':
                return $n['nombre'];
            case 'lit':
                if ($n['v'] === null)   { return 'NULL'; }
                if (is_string($n['v'])) { return "'" . $n['v'] . "'"; }
                return (string)$n['v'];
            case 'fn':
                if ($n['star']) {
                    return $n['nombre'] . '(*)';
                }
                $args = [];
                foreach ($n['args'] as $a) {
                    $args[] = self::etiqueta($a);
                }
                return $n['nombre'] . '(' . ($n['distinct'] ? 'DISTINCT ' : '') . implode(', ', $args) . ')';
            case 'bin':
                return self::etiqueta($n['i']) . ' ' . $n['op'] . ' ' . self::etiqueta($n['d']);
            case 'un':
                return $n['op'] . ' ' . self::etiqueta($n['e']);
            case 'case':
                return 'CASE';
            case 'sub':
                return 'subconsulta';
        }
        return 'expr';
    }
}
