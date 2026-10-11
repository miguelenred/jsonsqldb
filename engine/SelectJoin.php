<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * El JOIN por tabla hash (también por el disco), USING y NATURAL, y el
 * EXISTS correlacionado como semi-join. Métodos de Select que reciben la
 * consulta en $sel. Van aparte para que una búsqueda sencilla no tenga que
 * compilarlos (2.9): sin OPcache, PHP compila en cada petición lo que carga.
 * Hereda de Select solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SelectJoin extends Select
{
    private function __construct()
    {
    }

    /**
     * Un EXISTS correlacionado por una igualdad, como un semi-join (2.9):
     * EXISTS (SELECT … FROM t WHERE t.c = <de fuera> AND <solo de t>) es que
     * el valor de fuera esté entre los t.c que cumplen lo demás. Esos se sacan
     * una vez (ver valoresDe()), y cada fila de fuera solo mira si el suyo
     * está, con la igualdad de un IN. Antes la subconsulta se ejecutaba entera
     * por cada fila de fuera: con un millón de clientes y otro de pedidos,
     * horas. Null si no tiene esa forma —otra tabla, una vista, una igualdad
     * con colación, algo que no sea una igualdad mirando fuera, GROUP BY,
     * LIMIT, OFFSET, agregados o funciones de ventana (2.9: con un agregado
     * la subconsulta da siempre una fila, y con OFFSET puede no dar ninguna
     * aunque alguna cumpla)—, y entonces va como siempre.
     *
     * @param array $mapaFuera el de la consulta de fuera
     * @return array{0: array, 1: array}|null el conjunto (ver IndexesBusqueda::conjunto()) y la expresión de fuera, resuelta
     */
    public static function semiJoin(Select $sel, array $sub, array $mapaFuera): ?array
    {
        if ($sub['k'] !== 'select' || isset($sub['with']) || count($sub['from']) !== 1 || $sub['from'][0]['tipo'] !== 'tabla'
            || $sub['where'] === null || $sub['group'] !== null || $sub['having'] !== null || $sub['limit'] !== null
            || ($sub['offset'] ?? 0) > 0 || !empty($sub['ventanas'])) {
            return null;
        }
        foreach (array_column($sub['cols'], 'expr') as $e) {
            if (Evaluator::tieneAgregado($e)) {
                return null;
            }
        }
        $nombre = (string)$sub['from'][0]['nombre'];
        if (isset($sel->con[strtolower($nombre)]) || $sel->cat->esVista($nombre) || !$sel->cat->existe($nombre)) {
            return null;
        }
        [, $fuentes] = $sel->fuentesDeTabla($nombre, $sub['from'][0]['alias'] ?? $nombre, null);
        $dentro = $sel->mapaColumnas($fuentes);
        $marca  = Evaluator::$correlacionada;
        $par    = null;                             // [la columna de dentro, la expresión de fuera], sin resolver
        $resto  = null;
        try {
            foreach (IndexesBusqueda::conjunciones($sub['where']) as $c) {
                Evaluator::$correlacionada = false;
                $r = Evaluator::resolver($c, $dentro, [], $mapaFuera);
                if (!Evaluator::$correlacionada) {
                    $resto = $resto === null ? $c : ['k' => 'bin', 'op' => 'AND', 'i' => $resto, 'd' => $c];
                    continue;
                }
                if ($par !== null || $r['k'] !== 'bin' || $r['op'] !== '=') {
                    return null;
                }
                foreach ([['i', 'd'], ['d', 'i']] as [$a, $b]) {
                    if ($r[$a]['k'] === 'col' && !isset($r[$a]['externa']) && self::soloDeFuera($r[$b])) {
                        $par = [$c[$a], $c[$b]];
                        break;
                    }
                }
                if ($par === null) {
                    return null;
                }
            }
            $fuera = $par === null ? null : Evaluator::resolver($par[1], $mapaFuera);
        } catch (JsonSqlDbError) {
            return null;
        } finally {
            Evaluator::$correlacionada = $marca;
        }
        if ($par === null) {
            return null;
        }
        [$valores] = $sel->valoresDe(['k' => 'select', 'distinct' => false, 'cols' => [['star' => false, 'alias' => null, 'expr' => $par[0]]],
            'from' => $sub['from'], 'where' => $resto, 'group' => null, 'having' => null, 'order' => [], 'limit' => null, 'offset' => null]);
        if ($sel->cat->storage()->traza !== null) {
            SelectAvanzada::apuntar($sel, $nombre, 'EXISTS, una vez para todas las filas de fuera (semi-join)', null, count($valores));
        }
        return [IndexesBusqueda::conjunto($valores), $fuera];
    }

    /** ¿Solo columnas de fuera, y ninguna subconsulta, en esta expresión ya resuelta? */
    private static function soloDeFuera(array $n): bool
    {
        if (($n['k'] ?? null) === 'col') {
            return isset($n['externa']);
        }
        if (isset($n['select'])) {
            return false;
        }
        foreach ($n as $v) {
            if (is_array($v) && !self::soloDeFuera($v)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Une el acumulado de la izquierda con un nuevo origen. Las filas
     * cruzadas salen según se producen: quien las consume —el WHERE, la
     * agrupación— no necesita el cruce entero en memoria.
     *
     * @param iterable<array> $izq
     * @return \Generator<int, array>
     */
    public static function unir(Select $sel, iterable $izq, array $fuentesIzq, array $der, array $o): \Generator
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

        $mapa = $sel->mapaColumnas(array_merge($fuentesIzq, $der['fuentes']));
        $on   = Evaluator::resolver($o['on'], $mapa, [], $sel->externo['mapa'] ?? []);
        [$pares, $resto] = $sel->igualdades($on, array_flip($clavesDer));

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
        $plegar      = [];                  // la colación de cada igualdad que la tiene (2.9)
        foreach ($pares as $j => [$ki, $kd, $c]) {
            $clavesExt[] = $derecho ? $kd : $ki;
            $clavesInt[] = $derecho ? $ki : $kd;
            if ($c !== null) {
                $plegar[$j] = $c;
            }
        }
        $nulos = array_fill_keys($derecho ? $clavesIzq : $clavesDer, null);
        [$sub, $conjunto] = $sel->subconsultasDelOn();

        // Índice hash del lado interno. Guarda posiciones, no filas, para poder
        // saber después cuáles quedaron sin pareja (lo necesita FULL JOIN).
        $indice = null;
        if ($clavesInt !== []) {
            $indice = [];
            foreach ($internas as $i => $fila) {
                $clave = self::claveHash($sel, $fila, $clavesInt, $plegar);
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

        $vigilar = Memoria::cada();                  // filas hasta mirar la memoria (ver Storage::filas())
        foreach ($externas as $ext) {
            if ($indice === null) {
                $candidatas = $todas;
            } else {
                // Una sola llamada: claveHash() recorre la fila y no es gratis
                $clave      = self::claveHash($sel, $ext, $clavesExt, $plegar);
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
                        'filaExterna' => $sel->externo['fila'] ?? []])) !== true) {
                    continue;
                }
                if (--$vigilar === 0) {
                    Memoria::revisar('el JOIN');
                    $vigilar = Memoria::cada();
                }
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

    /**
     * Un INNER o LEFT JOIN cuyo lado interno no cupo en memoria (2.9, ver
     * Select::correr()): los dos lados se reparten por su clave de igualdad en
     * ficheros temporales (ver Volcado), así que cada fila solo puede casar
     * con las de su misma partición, y cada partición se cruza en memoria como
     * siempre (ver unir()). Una fila de fuera con algún NULL en la clave no
     * casa con nada: en un LEFT sale ya, con nulos. Sin igualdades en el ON
     * no hay por dónde repartir, y se cruza entero, como siempre. Las filas
     * salen partición a partición.
     *
     * @param iterable<array> $izq
     * @return \Generator<int, array>
     */
    public static function unirEnDisco(Select $sel, iterable $izq, array $fuentesIzq, array $der, array $o): \Generator
    {
        $clavesDer = array_column($der['fuentes'], 2);
        $mapa      = $sel->mapaColumnas(array_merge($fuentesIzq, $der['fuentes']));
        $on        = Evaluator::resolver($o['on'], $mapa, [], $sel->externo['mapa'] ?? []);
        [$pares]   = $sel->igualdades($on, array_flip($clavesDer));
        if ($pares === []) {
            $der['filas'] = iterator_to_array($der['filas'], false);
            yield from self::unir($sel, $izq, $fuentesIzq, $der, $o);
            return;
        }
        $clavesIzq = $clavesInt = $plegar = [];
        foreach ($pares as $j => [$ki, $kd, $c]) {
            $clavesIzq[] = $ki;
            $clavesInt[] = $kd;
            if ($c !== null) {
                $plegar[$j] = $c;
            }
        }
        $nulos  = array_fill_keys($clavesDer, null);
        $carpeta = $sel->cat->storage()->carpetaOrden();
        $dentro = new Volcado($carpeta);
        $fuera  = new Volcado($carpeta);
        try {
            foreach ($der['filas'] as $fila) {
                $k = self::claveHash($sel, $fila, $clavesInt, $plegar);
                if ($k !== null) {
                    $dentro->anadir($fila, crc32($k) % self::PARTICIONES);
                }
            }
            foreach ($izq as $fila) {
                $k = self::claveHash($sel, $fila, $clavesIzq, $plegar);
                if ($k !== null) {
                    $fuera->anadir($fila, crc32($k) % self::PARTICIONES);
                } elseif (($o['join'] ?? '') === 'LEFT') {
                    yield $fila + $nulos;
                }
            }
            for ($p = 0; $p < self::PARTICIONES; $p++) {
                $parte = ['fuentes' => $der['fuentes'], 'filas' => iterator_to_array($dentro->leer($p), false)];
                yield from self::unir($sel, $fuera->leer($p), $fuentesIzq, $parte, $o);
            }
        } finally {
            $dentro->borrar();
            $fuera->borrar();
        }
    }

    /**
     * Clave de igualdad de una fila; null si algún valor es NULL (NULL nunca casa).
     *
     * Iguala lo mismo que el = del WHERE (Valor::comparar()): un texto numérico
     * vale por su número, así que 1, 1.0, '1' y ' 1' casan entre sí. Con
     * Valor::clave() a secas, que distingue 1 de '1' para DISTINCT y GROUP BY,
     * un JOIN entre un entero y un decimal o un texto numérico no devolvía
     * ninguna fila. El booleano va como número, igual que en los índices.
     *
     * @param array<int, string> $plegar la colación de las que la tienen, por posición (2.9)
     */
    private static function claveHash(Select $sel, array $fila, array $claves, array $plegar = []): ?string
    {
        $k = '';
        foreach ($claves as $j => $c) {
            $v = $fila[$c] ?? null;
            if ($v === null) {
                return null;
            }
            if (isset($plegar[$j])) {
                $v = Functions::plegar($v, $plegar[$j]);
            }
            if (is_string($v) && (is_numeric($v) || is_numeric(trim($v)))) {
                $v = trim($v) + 0;
            } elseif (is_bool($v)) {
                $v = (int)$v;
            }
            $k .= Valor::clave($v) . "\0";
        }
        return $k;
    }

    /**
     * El ON de un JOIN … USING (cols) o un NATURAL JOIN (2.9): cada columna de
     * la derecha igualada a la de mismo nombre de la izquierda (si está en
     * varias de sus tablas, la primera, como SQLite). NATURAL usa todas las
     * que coinciden.
     */
    public static function onDeUsing(Select $sel, array &$o, array $fuentesIzq): ?array
    {
        if ($o['tipo'] !== 'tabla' || isset($sel->con[strtolower((string)$o['nombre'])])
            || $sel->cat->esVista((string)$o['nombre']) || !$sel->cat->existe((string)$o['nombre'])) {
            throw JsonSqlDbError::syntax('USING y NATURAL JOIN solo están soportados con tablas; con una vista o una subconsulta, escribe el ON');
        }
        $alias  = (string)($o['alias'] ?? $o['nombre']);
        $deDer  = array_column($sel->cat->meta((string)$o['nombre'])['columns'], 'name');
        $izq    = [];
        foreach ($fuentesIzq as $f) {
            if (empty($f[3])) {
                $izq[strtolower($f[1])][] = $f[0];
            }
        }
        $cols = $o['using'] ?? array_values(array_filter($deDer, static fn(string $c): bool => isset($izq[strtolower($c)])));
        $on = null;
        foreach ($cols as $c) {
            $real = null;
            foreach ($deDer as $d) {
                if (strcasecmp($d, $c) === 0) { $real = $d; }
            }
            $lados = $izq[strtolower($c)] ?? [];
            if ($real === null || $lados === []) {
                throw JsonSqlDbError::schema("USING ($c): la columna tiene que estar a los dos lados del JOIN");
            }
            // En más de una tabla de la izquierda: la primera, como SQLite
            $igual = ['k' => 'bin', 'op' => '=', 'i' => ['k' => 'col', 'tabla' => $lados[0], 'nombre' => $c], 'd' => ['k' => 'col', 'tabla' => $alias, 'nombre' => $real]];
            $on = $on === null ? $igual : ['k' => 'bin', 'op' => 'AND', 'i' => $on, 'd' => $igual];
        }
        $o['usingCols'] = $cols;
        // Un NATURAL JOIN sin columnas en común es un producto cartesiano, como en SQLite
        return $on;
    }

    /**
     * Tras un JOIN … USING, cada columna común se ve una vez: en SELECT * y sin
     * tabla delante es la de la izquierda (la de la derecha en un RIGHT JOIN,
     * que es la que siempre tiene valor). Con su tabla delante, las dos. Solo
     * con USING o NATURAL (ver Select::origenes()).
     */
    public static function ocultarUsing(array $fuentes, array $o): array
    {
        $alias = strtolower((string)($o['alias'] ?? $o['nombre']));
        $derecha = ($o['join'] ?? '') !== 'RIGHT';
        $comunes = array_map('strtolower', $o['usingCols']);
        foreach ($fuentes as $i => $f) {
            if (!empty($f[3]) || !in_array(strtolower($f[1]), $comunes, true)) {
                continue;
            }
            if ((strtolower($f[0]) === $alias) === $derecha) {
                $fuentes[$i][3] = true;               // la que no se ve
            }
        }
        return $fuentes;
    }
}
