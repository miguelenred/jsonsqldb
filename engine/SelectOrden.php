<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * ORDER BY: si un índice da hechas las primeras filas (ver ordenPorIndice());
 * si no, en memoria, las primeras N con un montón; por el disco, cuando no
 * cabe, en SelectEnDisco. Métodos de Select que reciben la consulta en $sel.
 * Van aparte para que una búsqueda sencilla no tenga que compilarlos (2.9):
 * sin OPcache, PHP compila en cada petición lo que carga. Hereda de Select
 * solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SelectOrden extends Select
{
    private function __construct()
    {
    }

    /**
     * ¿Se pueden sacar las filas de un «ORDER BY col [DESC] LIMIT n» del índice
     * de col, sin leer la tabla entera (ver StorageOrden::filasPorOrden())?
     * Solo con una tabla, sin agrupación ni DISTINCT, sin WHERE o con solo el
     * límite de esa columna (ver limiteDeOrden()), con una sola clave
     * de orden que sea una columna numérica de la tabla con índice, o de
     * texto sin COLLATE (2.10; con un COLLATE BINARY escrito, byte a byte). En
     * orden ascendente los NULL van primero y no están en el índice: hace falta
     * que la columna no los admita. Las filas que salen se ordenan y se cortan
     * después como siempre: solo cambia cuántas se leen. Quien llama ya ha
     * visto que hay LIMIT y una sola clave de orden: aquí, y no en Select,
     * para que una consulta sin ORDER BY no lo compile (2.10).
     *
     * @return array{def: array, desc: bool, k: int, binaria: ?bool, desde: array{0: int|float, 1: bool}|null}|null
     */
    public static function ordenPorIndice(Select $sel, array $ast): ?array
    {
        $o = ($ast['k'] ?? '') === 'select' && empty($ast['ventanas']) ? $sel->tablaDirecta($ast) : null;
        if ($o === null) {
            return null;
        }
        $e = $ast['order'][0]['expr'];
        $desc = strtoupper((string)$ast['order'][0]['dir']) === 'DESC';
        // col COLLATE BINARY: byte a byte, como las claves del índice (2.10)
        $binaria = ($e['k'] ?? '') === 'fn' && $e['nombre'] === 'COLLATE' && strtoupper((string)($e['args'][1]['v'] ?? '')) === 'BINARY';
        if ($binaria) {
            $e = $e['args'][0];
        }
        if (($e['k'] ?? '') !== 'col') {
            return null;
        }
        // Con un WHERE, solo si es el límite de esa misma columna por el lado
        // que se ordena (2.10): WHERE id > ? ORDER BY id LIMIT n, lo que pide
        // quien recorre una tabla por su clave, lote a lote (el panel al
        // exportar). Leía todo lo que pasaba del límite para quedarse con n
        $desde = null;
        if ($ast['where'] !== null) {
            $desde = self::limiteDeOrden($ast['where'], $e, $desc);
            if ($desde === null) {
                return null;
            }
        }
        $alias = strtolower((string)($o['alias'] ?? $o['nombre']));
        if ($e['tabla'] !== null && strtolower((string)$e['tabla']) !== $alias) {
            return null;
        }
        foreach ($ast['cols'] as $c) {
            if (empty($c['star']) && (Evaluator::tieneAgregado($c['expr'])
                // Un alias de la salida con el nombre de la columna manda en el ORDER BY
                || (($c['alias'] ?? null) !== null && strcasecmp((string)$c['alias'], (string)$e['nombre']) === 0
                    && !(($c['expr']['k'] ?? '') === 'col' && strcasecmp((string)$c['expr']['nombre'], (string)$e['nombre']) === 0)))) {
                return null;
            }
        }
        $col = Catalog::columna($sel->cat->meta($o['nombre']), (string)$e['nombre']);
        $k = self::cuantasHacenFalta($ast);
        // Y de texto (2.10), sin COLLATE en la columna: sus claves son el texto
        // tal cual, y se ordenan como el ORDER BY (ver StorageOrden::filasPorOrden())
        $texto = $col !== null && $col['type'] === Types::TEXT;
        if ($col === null || (!$texto && ($binaria || !\in_array($col['type'], [Types::INTEGER, Types::DOUBLE, Types::DECIMAL], true)))
            || ($texto && $desde !== null)
            || (!$desc && empty($col['notnull']) && empty($col['pk'])) || $k === null || $k < 1 || $k > 10000) {
            return null;
        }
        foreach ($sel->cat->indicesDe($o['nombre']) as $def) {
            if (strcasecmp((string)$def['columns'][0], $col['name']) === 0 && Indexes::sirve($def, 1)
                && (!$texto || ($def['plegar'][0] ?? null) === null)) {
                return ['def' => $def, 'desc' => $desc, 'k' => $k, 'binaria' => $texto ? $binaria || !Collation::activa() : null, 'desde' => $desde];
            }
        }
        return null;
    }

    /**
     * El límite que pone $where a la columna del ORDER BY por el lado por el
     * que se ordena, como [número, estricto]: col > n o col >= n (también
     * escrito n < col) con ASC, col < n o col <= n con DESC. Null si el WHERE
     * es otra cosa: entonces se lee como siempre.
     *
     * @return array{0: int|float, 1: bool}|null
     */
    private static function limiteDeOrden(array $where, array $col, bool $desc): ?array
    {
        if (($where['k'] ?? '') !== 'bin' || !\in_array($where['op'], ['>', '>=', '<', '<='], true)) {
            return null;
        }
        [$c, $v, $op] = ($where['i']['k'] ?? '') === 'col' ? [$where['i'], $where['d'], $where['op']]
            : [$where['d'], $where['i'], strtr($where['op'], ['>' => '<', '<' => '>'])];
        if (($c['k'] ?? '') !== 'col' || strcasecmp((string)$c['nombre'], (string)$col['nombre']) !== 0
            || ($c['tabla'] !== null && $col['tabla'] !== null && strcasecmp((string)$c['tabla'], (string)$col['tabla']) !== 0)
            || ($v['k'] ?? '') !== 'lit' || !(\is_int($v['v']) || \is_float($v['v'])) || ($op[0] === '>') === $desc
            // Pasado 2^53, < y <= no son exactos entre un entero y un decimal:
            // sin el atajo (2.10, ver Valor::numeros())
            || abs($v['v']) >= 9007199254740992) {
            return null;
        }
        return [$v['v'], \strlen($op) === 1];
    }

    /**
     * Si un término del ORDER BY es un número suelto (ORDER BY 2), con COLLATE
     * detrás o sin él: la posición y la colación escrita (o null). Si no,
     * [null, null].
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function posicionConColacion(array $e): array
    {
        $c = null;
        if (($e['k'] ?? '') === 'fn' && $e['nombre'] === 'PLEGAR') {
            $e = $e['args'][0];
        }
        if (($e['k'] ?? '') === 'fn' && $e['nombre'] === 'COLLATE') {
            [$e, $c] = [$e['args'][0], (string)$e['args'][1]['v']];
        }
        return ($e['k'] ?? '') === 'lit' && \is_int($e['v']) ? [$e['v'], $c] : [null, null];
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
    public static function indicesOrdenados(array $ast, array $orden, array $clavesOrden, array $indices): array
    {
        $cuantas = self::cuantasHacenFalta($ast);
        // Para quedarse con muchas (un OFFSET alto), ordenarlas todas con la
        // ordenación nativa y cortar es más rápido que escogerlas con el
        // comparador de PHP: con OFFSET 12000 en 60.000 filas, 489 → 210 ms
        if ($cuantas !== null && $cuantas < \count($indices) && $cuantas <= 1000) {
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
     * (sin ella con COLLATE BINARY, 'binaria' en el término, 2.10)
     * desempatando byte a byte y después por posición. Desde la 2.10 un texto
     * que parece un número es un texto más. Con una clave mixta se devuelve
     * null y se ordena como siempre.
     *
     * @param array<int, array<int, mixed>> $clavesOrden
     * @param list<int>               $indices
     * @return list<int>|null
     */
    public static function ordenNativo(array $orden, array &$clavesOrden, array $indices): ?array
    {
        // Nada que ordenar. Con GROUP BY y ORDER BY sobre una tabla vacía (o un
        // WHERE que no deja ninguna fila) las claves ni siquiera existían, y
        // asort() recibía null: error interno en vez de un resultado vacío
        if ($indices === []) {
            return [];
        }
        // Qué hay en cada columna. Se decide antes de tocar nada: si alguna no
        // sirve, las claves tienen que seguir enteras para compararOrden()
        // Solo enteros, SORT_REGULAR: los compara como enteros, y es más rápido
        // (2.10: con SORT_NUMERIC pasaban a decimal y pasado 2^53 empataban).
        // Con decimales, SORT_NUMERIC, que con ellos es más rápido y exacto,
        // salvo con un entero pasado 2^53 (compararOrden() los compara exactos)
        $tipos = [];
        foreach (array_keys($orden) as $i) {
            $enteros = $grandes = $decimales = $textos = $nulos = 0;
            foreach ($clavesOrden[$i] as $v) {
                if ($v === null) {
                    $nulos++;
                } elseif (\is_int($v)) {
                    $enteros++;
                    if ($v > 9007199254740992 || $v < -9007199254740992) {
                        $grandes++;
                    }
                } elseif (\is_float($v)) {
                    $decimales++;
                } elseif (\is_string($v)) {
                    $textos++;
                } else {
                    return null;                         // booleanos (de un JSON editado a mano)
                }
            }
            if (($enteros + $decimales > 0 && $textos > 0) || ($grandes > 0 && $decimales > 0)) {
                return null;                             // mezcla: solo compararOrden() sabe
            }
            $tipos[$i] = [$textos > 0, $nulos > 0, $decimales > 0 ? SORT_NUMERIC : SORT_REGULAR];
        }

        // Una sola clave numérica: asort() en su sitio, sin más arrays. Es el
        // caso corriente (ORDER BY id, por fecha, por importe) y el más barato
        if (\count($orden) === 1 && !$tipos[0][0]) {
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
            // asort() la rehace como tabla hash: 40 bytes por hueco, y los huecos van de dos en dos
            Memoria::antesDePedir(40 << (int)ceil(log(max(8, \count($col)), 2)), 'la ordenación');
            $orden[0]['dir'] === 'DESC' ? arsort($col, $tipos[0][2]) : asort($col, $tipos[0][2]);
            $indices = array_keys($col);
            unset($col);
            // Los NULL van primero; con DESC, los últimos
            return $orden[0]['dir'] === 'DESC' ? array_merge($indices, $nulos) : array_merge($nulos, $indices);
        }

        // Las columnas se sacan de $clavesOrden según se usan, para que
        // array_multisort() las ordene en su sitio sin copiarlas
        $args = [];
        foreach ($orden as $i => $o) {
            [$texto, $hayNulos, $numerica] = $tipos[$i];
            $col = $clavesOrden[$i];
            unset($clavesOrden[$i]);
            $dir = $o['dir'] === 'DESC' ? SORT_DESC : SORT_ASC;
            if ($hayNulos) {
                $nulos = array_fill(0, \count($col), 1);
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
            if ($texto && empty($o['binaria']) && Collation::activa()) {
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
            $args[] = $texto ? SORT_STRING : $numerica;
        }
        $args[] = &$indices;                              // el desempate final: la posición
        $args[] = SORT_ASC;
        $args[] = SORT_NUMERIC;
        // array_multisort() pide de una vez 32 bytes por fila y array, más uno
        Memoria::antesDePedir(\count($indices) * (32 * (intdiv(\count($args), 3) + 1) + 8), 'la ordenación');
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
    public static function comparadorDe(array $orden, array &$clavesOrden): \Closure
    {
        return static function (int $a, int $b) use (&$clavesOrden, $orden): int {
            foreach ($orden as $i => $o) {
                $c = Valor::compararOrden($clavesOrden[$i][$a], $clavesOrden[$i][$b], !empty($o['binaria']));
                if ($c !== 0) {
                    return $o['dir'] === 'DESC' ? -$c : $c;
                }
            }
            return $a <=> $b;                        // orden estable
        };
    }

    /**
     * Las $cuantas primeras filas según el ORDER BY, sin ordenar el resto.
     *
     * Se guardan las candidatas hasta tener el doble de las que hacen falta;
     * entonces se ordenan (con la ordenación nativa si se puede) y se quedan
     * las $cuantas mejores, y la peor de ellas pasa a ser el listón: una fila
     * que no lo mejora se descarta con una sola comparación. Antes era un
     * montón (SplHeap) que comparaba cada fila varias veces a través de dos
     * funciones: un LIMIT 1000 con dos columnas tardaba seis veces más que
     * ordenar la tabla entera. En memoria viven como mucho el doble de filas.
     *
     * @return array{0: array<int,array>, 1: list<int>} las filas vivas y sus índices, ya en orden
     */
    public static function primerasDe(iterable $filas, array $claves, array $orden, int $cuantas): array
    {
        if ($cuantas <= 0) {
            return [[], []];
        }
        $clavesOrden = array_fill(0, \count($claves), []);
        $comparar    = self::comparadorDe($orden, $clavesOrden);
        $tope        = max(2 * $cuantas, $cuantas + 64);
        $vivas       = [];
        $liston      = null;                        // índice de la peor de las guardadas tras recortar
        $k           = 0;
        // Ordena las vivas y se queda con las $cuantas primeras
        // Las claves se quitan recorriendo $claves y no $clavesOrden: un foreach
        // sobre $clavesOrden lo comparte, y quitar algo dentro obligaba a PHP a
        // copiar las listas de claves enteras en cada fila descartada
        $recortar = static function () use (&$vivas, &$clavesOrden, &$liston, $orden, $comparar, $cuantas, $claves): array {
            // ordenNativo() quiere las claves seguidas desde 0, como las de
            // una tabla entera (y se las come): una copia renumerada, y después
            // cada posición se traduce a su fila
            $filasVivas = array_keys($vivas);
            $copia = [];
            foreach ($claves as $i => $_) {
                $copia[$i] = array_values($clavesOrden[$i]);
            }
            $posiciones = self::ordenNativo($orden, $copia, array_keys($filasVivas));
            $ordenados = $posiciones === null
                ? self::todasOrdenadas($filasVivas, $comparar)
                : array_map(static fn(int $p): int => $filasVivas[$p], $posiciones);
            foreach (\array_slice($ordenados, $cuantas) as $fuera) {
                unset($vivas[$fuera]);
                foreach ($claves as $i => $_) {
                    unset($clavesOrden[$i][$fuera]);
                }
            }
            $ordenados = \array_slice($ordenados, 0, $cuantas);
            $liston    = \count($ordenados) === $cuantas ? $ordenados[$cuantas - 1] : null;
            return $ordenados;
        };
        // Con una sola clave, la comparación con el listón se hace aquí, sin
        // pasar por el comparador general (una llamada y un bucle por fila) y
        // sin guardar la clave de una fila que no entra. Mismo resultado: a
        // igualdad gana la que llegó antes, que es la del listón
        $una  = \count($claves) === 1 ? $claves[0] : null;
        $desc = $una !== null && $orden[0]['dir'] === 'DESC';
        // Entre dos textos, compararOrden() calcula la clave de colación de los
        // dos en cada llamada: la del listón se guarda mientras no cambie
        $binaria = $una !== null && !empty($orden[0]['binaria']);
        $texto   = !$binaria && Collation::activa();
        $deListo = null;                            // [índice del listón, su valor, su clave de colación]
        foreach ($filas as $fila) {
            if ($una !== null) {
                $v = $fila[$una] ?? null;
                if ($liston !== null) {
                    $lv = $clavesOrden[0][$liston];
                    if ($texto && \is_string($v) && \is_string($lv)) {
                        if ($deListo === null || $deListo[0] !== $liston) {
                            $deListo = [$liston, $lv, Collation::clave($lv)];
                        }
                        $c = strcmp(Collation::clave($v), $deListo[2]) <=> 0;
                        $c = $c !== 0 ? $c : (strcmp($v, $lv) <=> 0);
                    } elseif ((\is_int($v) || \is_float($v)) && (\is_int($lv) || \is_float($lv))) {
                        // Dos números: lo que haría compararOrden(), sin las dos llamadas
                        $c = $v <=> $lv ?: (\is_int($v) === \is_int($lv) ? 0 : Valor::mezclados($v, $lv));
                    } else {
                        $c = Valor::compararOrden($v, $lv, $binaria);
                    }
                    if ($desc ? $c <= 0 : $c >= 0) {
                        $k++;
                        continue;                   // no entra: va detrás de la peor guardada
                    }
                }
                $clavesOrden[0][$k] = $v;
                $vivas[$k] = $fila;
                if (\count($vivas) >= $tope) {
                    $recortar();
                }
                $k++;
                Memoria::comprobar('la ordenación');
                continue;
            }
            foreach ($claves as $i => $clave) {
                $clavesOrden[$i][$k] = $fila[$clave] ?? null;
            }
            if ($liston !== null && $comparar($k, $liston) > 0) {
                foreach ($claves as $i => $_) {
                    unset($clavesOrden[$i][$k]);    // no entra: va detrás de la peor guardada
                }
            } else {
                $vivas[$k] = $fila;
                if (\count($vivas) >= $tope) {
                    $recortar();
                }
            }
            $k++;
            Memoria::comprobar('la ordenación');
        }
        return [$vivas, $recortar()];
    }

    /**
     * Ordena la lista entera.
     *
     * @param list<int> $indices
     */
    public static function todasOrdenadas(array $indices, callable $comparar): array
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
        $monton = self::monton($comparar);

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

    /** Un montón con arriba lo que $comparar pone primero (positivo: $a va más arriba que $b) */
    public static function monton(callable $comparar): \SplHeap
    {
        return new class ($comparar) extends \SplHeap {
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
    }

    /**
     * Añade a las claves de ordenación (por columnas) las de una fila.
     *
     * @param array<array<int, mixed>> $clavesOrden
     */
    public static function anotarClaves(array &$clavesOrden, array $orden, array $ctx, array $proyectada): void
    {
        $ctx['salida'] = $proyectada;                    // permite ORDER BY por alias de salida
        foreach ($orden as $i => $o) {
            $clavesOrden[$i][] = Evaluator::evaluar($o['expr'], $ctx);
        }
    }
}
