<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * ORDER BY sin un índice que lo dé hecho: en memoria, las primeras N con un
 * montón, o por el disco cuando no cabe (ver OrdenExterno). Métodos de
 * Select que reciben la consulta en $sel. Van aparte para que una búsqueda
 * sencilla no tenga que compilarlos (2.9): sin OPcache, PHP compila en cada
 * petición lo que carga. Hereda de Select solo para llegar a lo protegido;
 * no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SelectOrden extends Select
{
    private function __construct()
    {
    }

    /**
     * Lo que no cupo en memoria (ver Select::correr()), por el disco: las
     * filas —o los grupos, con sus agregados— se proyectan según llegan, sin
     * repetidas si hay DISTINCT, y con ORDER BY lo juntado pasa a ordenarse en
     * disco cuando la memoria aprieta (ver OrdenExterno). Sale ya con su OFFSET
     * y su LIMIT, y por el sumidero si la consulta lo tiene, sin juntar el
     * resultado.
     *
     * @param iterable<array>         $contextos el de cada fila, para el evaluador
     * @param array<string,true>|null $vistos
     */
    public static function ordenarEnDisco(Select $sel, iterable $contextos, ?array $vistos, array $orden, \Closure $proyectar,
                                          array $ast, array &$resultado, array $plegarSalida = []): void
    {
        $externo = $orden === [] ? null : new OrdenExterno($orden, $sel->cat->storage()->carpetaOrden());
        $saltar  = (int)($ast['offset'] ?? 0);
        $quedan  = $ast['limit'] === null ? -1 : (int)$ast['limit'];
        $entregar = static function (array $fila) use ($sel, &$resultado): void {
            if ($sel->sumidero !== null && $sel->nivel === $sel->nivelSumidero) {
                ($sel->sumidero)($fila);
                $sel->entregado = true;
            } else {
                $resultado[] = $fila;
            }
        };
        // Las claves de orden que son una columna de la fila se cogen de ella
        // (2.9): evaluarlas con el contexto costaba un 10 % de un ORDER BY de un
        // millón de filas en disco. Un alias de la salida va por el evaluador
        $directas = [];
        foreach ($orden as $i => $o) {
            $directas[$i] = ($o['expr']['k'] ?? '') === 'col' && isset($o['expr']['clave']) && !isset($o['expr']['externa']) ? $o['expr']['clave'] : null;
        }
        $vigilar = Memoria::cada();                  // filas hasta mirar la memoria (ver Storage::filas())
        foreach ($quedan === 0 ? [] : $contextos as $ctx) {
            if (--$vigilar === 0) {
                Memoria::revisar('la construcción del resultado');
                $vigilar = Memoria::cada();
            }
            $fila = $proyectar($ctx['fila'], $ctx);
            if ($vistos !== null) {
                $clave = '';
                if ($plegarSalida === []) {
                    foreach ($fila as $v) {
                        $clave .= Valor::clave($v) . "\0";
                    }
                } else {                        // con COLLATE (2.9)
                    foreach ($fila as $c => $v) {
                        $clave .= Valor::clave(isset($plegarSalida[$c]) ? Functions::plegar($v, $plegarSalida[$c]) : $v) . "\0";
                    }
                }
                if (isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;
            }
            if ($externo === null) {
                // Sin ORDER BY, cada una sale ya, con el OFFSET y el LIMIT
                if ($saltar > 0) {
                    $saltar--;
                } else {
                    $entregar($fila);
                    if (--$quedan === 0) {
                        break;
                    }
                }
                continue;
            }
            $claves = [];
            foreach ($directas as $i => $clave) {
                if ($clave !== null) {
                    $claves[$i] = $ctx['fila'][$clave] ?? null;
                } else {
                    $conAlias = $ctx;
                    $conAlias['salida'] = $fila;                   // permite ORDER BY por alias de salida
                    $claves[$i] = Evaluator::evaluar($orden[$i]['expr'], $conAlias);
                }
            }
            $externo->anadir($claves, $fila);
        }
        unset($contextos, $vistos);
        foreach ($externo === null ? [] : $externo->filas($saltar, $ast['limit'] === null ? null : (int)$ast['limit']) as $fila) {
            $entregar($fila);
        }
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
        if ($cuantas !== null && $cuantas < count($indices) && $cuantas <= 1000) {
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
        $tipos = [];
        foreach (array_keys($orden) as $i) {
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
            // asort() la rehace como tabla hash: 40 bytes por hueco, y los huecos van de dos en dos
            Memoria::antesDePedir(40 << (int)ceil(log(max(8, count($col)), 2)), 'la ordenación');
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
        // array_multisort() pide de una vez 32 bytes por fila y array, más uno
        Memoria::antesDePedir(count($indices) * (32 * (intdiv(count($args), 3) + 1) + 8), 'la ordenación');
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
                $c = Valor::compararOrden($clavesOrden[$i][$a], $clavesOrden[$i][$b]);
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
        $clavesOrden = array_fill(0, count($claves), []);
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
            foreach (array_slice($ordenados, $cuantas) as $fuera) {
                unset($vivas[$fuera]);
                foreach ($claves as $i => $_) {
                    unset($clavesOrden[$i][$fuera]);
                }
            }
            $ordenados = array_slice($ordenados, 0, $cuantas);
            $liston    = count($ordenados) === $cuantas ? $ordenados[$cuantas - 1] : null;
            return $ordenados;
        };
        // Con una sola clave, la comparación con el listón se hace aquí, sin
        // pasar por el comparador general (una llamada y un bucle por fila) y
        // sin guardar la clave de una fila que no entra. Mismo resultado: a
        // igualdad gana la que llegó antes, que es la del listón
        $una  = count($claves) === 1 ? $claves[0] : null;
        $desc = $una !== null && $orden[0]['dir'] === 'DESC';
        // Entre dos textos, compararOrden() calcula la clave de colación de los
        // dos en cada llamada: la del listón se guarda mientras no cambie
        $texto   = Collation::activa();
        $deListo = null;                            // [índice del listón, su valor, su clave de colación]
        foreach ($filas as $fila) {
            if ($una !== null) {
                $v = $fila[$una] ?? null;
                if ($liston !== null) {
                    $lv = $clavesOrden[0][$liston];
                    if ($texto && is_string($v) && is_string($lv) && !is_numeric(trim($v)) && !is_numeric(trim($lv))) {
                        if ($deListo === null || $deListo[0] !== $liston) {
                            $deListo = [$liston, $lv, Collation::clave($lv)];
                        }
                        $c = strcmp(Collation::clave($v), $deListo[2]) <=> 0;
                        $c = $c !== 0 ? $c : (strcmp($v, $lv) <=> 0);
                    } elseif ((is_int($v) || is_float($v)) && (is_int($lv) || is_float($lv))) {
                        $c = $v <=> $lv;            // dos números: lo que haría compararOrden(), sin las dos llamadas
                    } else {
                        $c = Valor::compararOrden($v, $lv);
                    }
                    if ($desc ? $c <= 0 : $c >= 0) {
                        $k++;
                        continue;                   // no entra: va detrás de la peor guardada
                    }
                }
                $clavesOrden[0][$k] = $v;
                $vivas[$k] = $fila;
                if (count($vivas) >= $tope) {
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
                if (count($vivas) >= $tope) {
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
