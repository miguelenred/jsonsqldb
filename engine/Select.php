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
    /** @var callable(string,?int):array lector de filas; permite ver cambios aún no volcados a disco */
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
        $this->lector    = $lector
            ?? static fn(string $t, ?int $tope = null): array => $cat->storage()->leerFilas($t, false, $tope);
    }

    /** Ejecuta la consulta y devuelve las filas de salida. */
    public function ejecutar(array $ast): array
    {
        return $this->correr($ast)['filas'];
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

        // ¿Se puede dejar de recorrer en cuanto haya suficientes filas?
        // Solo si el resultado no depende de las filas que vendrían después:
        // sin ORDER BY, sin agrupar, sin agregados y sin DISTINCT.
        $tope = $this->topeTemprano($ast);

        [$filas, $claves] = $this->origenes($ast['from'], $ast['where'], $tope);
        $mapa = $this->mapaColumnas($claves);
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

        // WHERE
        if ($ast['where'] !== null) {
            $where = Evaluator::resolver($ast['where'], $mapa, [], $this->externo['mapa'] ?? []);

            // Camino rápido para  columna = valor  y demás comparaciones simples:
            // evita pasar por el evaluador general en cada una de las filas.
            $simple = self::comparacionSimple($where);

            $filtradas = [];
            foreach ($filas as $fila) {
                if ($simple !== null) {
                    $v    = $fila[$simple['clave']] ?? null;
                    $vale = $v === null ? false : self::compara($simple['op'], $v, $simple['valor']);
                } else {
                    $vale = Valor::verdadero(Evaluator::evaluar(
                        $where, ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto,
                                 'filaExterna' => $externa])) === true;
                }
                if ($vale) {
                    Memoria::comprobar('el filtrado del WHERE');
                    $filtradas[] = $fila;
                    if ($tope !== null && count($filtradas) >= $tope) {
                        break;                      // ya no hacen falta más
                    }
                }
            }
            $filas = $filtradas;
        } elseif ($tope !== null && count($filas) > $tope) {
            $filas = array_slice($filas, 0, $tope);
        }

        // Columnas de salida (expandiendo * )
        $salida = $this->columnasSalida($ast['cols'], $claves, $mapa);

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

        // Grupos: cada elemento es [fila representativa, filas del grupo|null]
        $bloques = $agrupar ? $this->agrupar($filas, $grupoExprs, $sub, $conjunto) : null;

        // Proyección + claves de ordenación
        $resultado = [];
        $clavesOrden = [];

        if ($bloques !== null) {
            foreach ($bloques as $grupo) {
                $ctx = ['fila' => $grupo[0] ?? [], 'grupo' => $grupo, 'sub' => $sub,
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
                    $clavesOrden[] = $this->clavesOrden($orden, $ctx, $fila);
                }
            }
        } else {
            // Se va soltando cada fila de origen según se proyecta. Si no, la
            // tabla leída y el resultado conviven enteros hasta el final del
            // bucle, o sea dos copias de lo mismo en el pico.
            foreach (array_keys($filas) as $k) {
                Memoria::comprobar('la construcción del resultado');
                $ctx  = ['fila' => $filas[$k], 'sub' => $sub, 'conjunto' => $conjunto,
                         'filaExterna' => $externa];
                $fila = [];
                foreach ($salida as $c) {
                    $fila[$c['nombre']] = Evaluator::evaluar($c['expr'], $ctx);
                }
                unset($filas[$k]);
                $resultado[] = $fila;
                // Sin ORDER BY no hay nada que ordenar: construir las claves
                // sería un array más por fila para tirarlo enseguida
                if ($orden !== []) {
                    $clavesOrden[] = $this->clavesOrden($orden, $ctx, $fila);
                }
            }
        }

        // DISTINCT
        if ($ast['distinct']) {
            $vistos = [];
            $r = [];
            $k = [];
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
                if ($orden !== []) {
                    $k[] = $clavesOrden[$i];
                }
            }
            $resultado   = $r;
            $clavesOrden = $k;
        }

        // ORDER BY
        if ($orden !== []) {
            $comparar = static function (int $a, int $b) use ($clavesOrden, $orden): int {
                foreach ($orden as $i => $o) {
                    $c = Valor::compararOrden($clavesOrden[$a][$i], $clavesOrden[$b][$i]);
                    if ($c !== 0) {
                        return $o['dir'] === 'DESC' ? -$c : $c;
                    }
                }
                return $a <=> $b;                        // orden estable
            };

            // Con LIMIT no hace falta ordenarlo todo: basta con quedarse con las
            // primeras. Ordenar un millón de filas para devolver diez es tirar
            // el trabajo, y además obliga a tener el resultado entero ordenado
            // en memoria a la vez.
            //
            // El desempate por posición original es el mismo que usa el orden
            // estable de arriba, así que las filas que salen y su orden son
            // EXACTAMENTE los mismos que ordenando entero y cortando después.
            $cuantas = self::cuantasHacenFalta($ast);
            $indices = $cuantas !== null && $cuantas < count($resultado)
                ? self::primeras(array_keys($resultado), $cuantas, $comparar)
                : self::todasOrdenadas(array_keys($resultado), $comparar);

            $ordenadas = [];
            foreach ($indices as $i) {
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

    /** @return array{0: array, 1: string[]} filas planas y lista de claves "alias.columna" */
    private function origenes(array $from, ?array $where = null, ?int $tope = null): array
    {
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

        $primero = $this->cargar($from[0], $predicados, $topeLectura);
        $filas   = $primero['filas'];
        $claves  = $primero['claves'];

        for ($i = 1, $n = count($from); $i < $n; $i++) {
            $der    = $this->cargar($from[$i], $predicados);
            $filas  = $this->unir($filas, $claves, $der, $from[$i]);
            $claves = array_merge($claves, $der['claves']);
        }
        return [$filas, $claves];
    }

    /** Carga una tabla o subconsulta como filas planas con prefijo de alias. */
    private function cargar(array $o, array $predicados = [], ?int $tope = null): array
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
            // filas buscadas; si no lo hay, o no compensa, se lee la tabla.
            $origen = $this->porIndice($nombre, $predicados[strtolower($alias)] ?? [])
                   ?? ($this->lector)($nombre, $tope);
        }

        $claves = [];
        foreach ($cols as $c) {
            $claves[] = $alias . '.' . $c;
        }

        // Aquí cada fila se copia con las claves prefijadas por el alias, y eso
        // llegaba a tener la tabla dos veces en memoria: la leída y la aplanada.
        // Se va soltando cada fila original según se convierte, así que solo hay
        // una copia y media a medio camino en vez de dos enteras.
        $filas = [];
        foreach (array_keys($origen) as $k) {
            $plana = [];
            foreach ($cols as $i => $c) {
                $plana[$claves[$i]] = $origen[$k][$c] ?? null;
            }
            unset($origen[$k]);
            $filas[] = $plana;
            Memoria::comprobar('la carga de la tabla');
        }

        return ['alias' => $alias, 'cols' => $cols, 'claves' => $claves, 'filas' => $filas];
    }

    /** Une el acumulado de la izquierda con un nuevo origen. */
    private function unir(array $izq, array $clavesIzq, array $der, array $o): array
    {
        $tipo = $o['join'] ?? 'CROSS';

        if ($tipo === 'CROSS' || $o['on'] === null) {
            $salida = [];
            foreach ($izq as $a) {
                foreach ($der['filas'] as $b) {
                    Memoria::comprobar('el producto cartesiano');
                    $salida[] = $a + $b;
                }
            }
            return $salida;
        }

        $mapa = $this->mapaColumnas(array_merge($clavesIzq, $der['claves']));
        $on   = Evaluator::resolver($o['on'], $mapa, [], $this->externo['mapa'] ?? []);
        [$pares, $resto] = $this->igualdades($on, array_flip($der['claves']));

        // RIGHT JOIN = mismo algoritmo con los papeles cambiados
        $derecho     = $tipo === 'RIGHT';
        $externas    = $derecho ? $der['filas'] : $izq;
        $internas    = $derecho ? $izq : $der['filas'];
        $clavesExt   = [];
        $clavesInt   = [];
        foreach ($pares as [$ki, $kd]) {
            $clavesExt[] = $derecho ? $kd : $ki;
            $clavesInt[] = $derecho ? $ki : $kd;
        }
        $nulos = array_fill_keys($derecho ? $clavesIzq : $der['claves'], null);
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
                if ($clave !== null) {
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

        $salida = [];
        foreach ($externas as $ext) {
            if ($indice === null) {
                $candidatas = $todas;
            } else {
                // Una sola llamada: claveHash() recorre la fila y no es gratis
                $clave      = $this->claveHash($ext, $clavesExt);
                $candidatas = $clave === null ? [] : ($indice[$clave] ?? []);
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
                $salida[]   = $fila;
                $encontrada = true;
                if ($completo) {
                    $casadas[$i] = true;
                }
            }
            if (!$encontrada && $tipo !== 'INNER') {
                $salida[] = $ext + $nulos;
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
                    $salida[] = $int + $nulosExt;
                }
            }
        }

        return $salida;
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

    /** 'alias.col' => 'alias.col', y 'col' => 'alias.col' (false si es ambigua) */
    private function mapaColumnas(array $claves): array
    {
        $mapa = [];
        foreach ($claves as $clave) {
            $mapa[strtolower($clave)] = $clave;
            $corto = strtolower(substr($clave, strrpos($clave, '.') + 1));
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

    /** Expande * y calcula el nombre de salida de cada columna. */
    private function columnasSalida(array $cols, array $claves, array $mapa): array
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
                foreach ($claves as $clave) {
                    $punto = strrpos($clave, '.');
                    $alias = substr($clave, 0, $punto);
                    $corto = substr($clave, $punto + 1);
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

    /** @return array<int,array> lista de grupos; cada grupo es una lista de filas */
    private function agrupar(array $filas, array $exprs, callable $sub, callable $conjunto): array
    {
        if ($exprs === []) {
            return [$filas];        // agregación total: una sola fila aunque no haya datos
        }
        $grupos = [];
        foreach ($filas as $fila) {
            $ctx   = ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto,
                      'filaExterna' => $this->externo['fila'] ?? []];
            $clave = '';
            foreach ($exprs as $e) {
                $clave .= Valor::clave(Evaluator::evaluar($e, $ctx)) . "\0";
            }
            $grupos[$clave][] = $fila;
        }
        return array_values($grupos);
    }

    private function clavesOrden(array $orden, array $ctx, array $proyectada): array
    {
        if ($orden === []) {
            return [];
        }
        $ctx['fila'] = $ctx['fila'] + $proyectada;        // permite ORDER BY por alias de salida
        $claves = [];
        foreach ($orden as $o) {
            $claves[] = Evaluator::evaluar($o['expr'], $ctx);
        }
        return $claves;
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
