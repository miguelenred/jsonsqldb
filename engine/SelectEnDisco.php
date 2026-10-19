<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que no cupo en memoria, por el disco: proyectar, quitar las repetidas y
 * ordenar (ver OrdenExterno) según llegan las filas (2.10, aparte de
 * SelectOrden para que un ORDER BY que cabe en memoria no tenga que
 * compilarlo: sin OPcache, PHP compila en cada petición lo que carga).
 * Métodos de Select que reciben la consulta en $sel. Hereda de Select solo
 * para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SelectEnDisco extends Select
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
            $directas[$i] = Evaluator::claveDirecta($o['expr']);
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
}
