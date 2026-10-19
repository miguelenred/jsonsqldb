<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * La búsqueda de un valor en un conjunto preparado (IN de una lista o de una
 * subconsulta, el EXISTS de una igualdad), que usan IN fila a fila
 * (EvaluatorIn), su versión compilada (EvaluatorCompilado) y Select (2.10,
 * aparte de EvaluatorIn para que un WHERE con IN, que va compilado, no tenga
 * que compilar también el IN fila a fila: sin OPcache, PHP compila en cada
 * petición lo que carga). No se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class EvaluatorConjunto
{
    private function __construct()
    {
    }

    /**
     * ¿Está $v en un conjunto preparado [grupos por clave, dudosos, hay NULL]?
     * Con la lógica de tres valores del SQL: 1, 0 o NULL.
     */
    public static function enConjunto($v, array $preparado, bool $no)
    {
        [$conjunto, $dudosos, $hayNulo] = $preparado;
        if ($conjunto === [] && $dudosos === [] && !$hayNulo) {
            return $no ? 1 : 0;                      // NOT IN () siempre cierto
        }
        if ($v === null) {
            return null;
        }
        if (!empty($preparado[4])) {
            $v = Valor::conAfinidad($v, 'T');        // con afinidad de texto (2.10, ver IndexesBusqueda::conjunto())
        }
        $candidatos = $dudosos;
        if (IndexesBusqueda::claveFiable($v)) {
            $clave = Indexes::clave([$v]);
            if ($clave !== null && isset($conjunto[$clave])) {
                // Un valor solo, o la lista de los que comparten clave (ver
                // IndexesBusqueda::conjunto()); sin dudosos —lo normal— tal cual,
                // sin copiarla: esto se ejecuta una vez por fila
                $grupo      = $conjunto[$clave];
                $candidatos = \is_array($grupo) ? ($dudosos === [] ? $grupo : array_merge($grupo, $dudosos)) : [$grupo, ...$dudosos];
            }
            // Un texto que es un número lo iguala también un número (2.10, ver Valor::comparar())
            if (empty($preparado[4]) && \is_string($v) && Valor::esNumerico($v)) {
                $n = trim($v) + 0;
                if (!IndexesBusqueda::claveFiable($n)) {
                    foreach ($conjunto as $grupo) {
                        $candidatos = array_merge($candidatos, (array)$grupo);
                    }
                } elseif (isset($conjunto[$k = (string)Indexes::clave([$n])])) {
                    $candidatos = array_merge($candidatos, (array)$conjunto[$k]);
                }
            }
        } else {
            // Valor fuera del rango en que la clave es de fiar: se compara
            // contra todo, que es lo que se hacía siempre
            foreach ($conjunto as $grupo) {
                $candidatos = array_merge($candidatos, (array)$grupo);
            }
        }
        foreach ($candidatos as $x) {
            if (Valor::comparar($v, $x) === 0) {
                return $no ? 0 : 1;
            }
        }
        return $hayNulo ? null : ($no ? 1 : 0);
    }
}
