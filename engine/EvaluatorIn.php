<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * IN fila a fila, cuando no se ha podido compilar ni ir por un índice (2.10,
 * aparte de Evaluator para que una consulta sin IN no tenga que compilarlo:
 * sin OPcache, PHP compila en cada petición lo que carga). La búsqueda en un
 * conjunto preparado, que usa también la versión compilada, está en
 * EvaluatorConjunto. No se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class EvaluatorIn
{
    private function __construct()
    {
    }

    /**
     * Resuelve un IN.
     *
     * La lista de valores se recorría entera para cada fila, y con una
     * subconsulta eso es cuadrático: 30.000 filas contra 2.000 valores son
     * sesenta millones de comparaciones, trece segundos donde SQLite tarda ocho
     * milisegundos.
     *
     * Cuando los valores son los mismos para todas las filas —una subconsulta
     * que no mira hacia fuera, o una lista de literales— se agrupan una sola vez
     * por clave y después cada fila cuesta una búsqueda. La clave solo acota los
     * candidatos: la igualdad la sigue decidiendo Valor::comparar(), porque dos
     * valores distintos pueden compartir clave y el resultado tiene que ser el
     * mismo que antes, no parecido.
     *
     * Con una subconsulta correlacionada los valores cambian con cada fila y no
     * hay nada que reutilizar; ahí se recorre como siempre.
     */
    public static function in(array $n, array $ctx)
    {
        $v = Evaluator::evaluar($n['e'], $ctx);

        // Conjunto ya preparado: literales resueltos en Evaluator::resolver(),
        // o una subconsulta cuyo resultado no depende de la fila
        $preparado = $n['conjunto'] ?? null;
        if ($preparado === null && $n['select'] !== null && isset($ctx['conjunto'])) {
            $preparado = ($ctx['conjunto'])($n['select'], $n['sid'], $ctx['fila'] ?? [], $n['colacionIn'] ?? null, $n['afinidadIn'] ?? null);
        }

        if ($preparado !== null) {
            // Con la colación de la subconsulta (2.9), el valor también plegado
            return EvaluatorConjunto::enConjunto(isset($preparado[3]) ? Functions::plegar($v, $preparado[3]) : $v, $preparado, (bool)$n['not']);
        }

        $valores = [];
        if ($n['select'] !== null) {
            // Sin conjunto preparado, solo la colación de x; la de la
            // subconsulta, que se sabe al ejecutarla, no llega aquí
            $c = \is_string($n['colacionIn'] ?? null) ? $n['colacionIn'] : null;
            foreach (($ctx['sub'])($n['select'], $n['sid'], $ctx['fila'] ?? []) as $fila) {
                $valores[] = $c === null ? reset($fila) : Functions::plegar(reset($fila), $c);
            }
            if ($c !== null) {
                $v = Functions::plegar($v, $c);
            }
        } else {
            foreach ($n['lista'] as $i => $e) {
                $valores[$i] = Evaluator::evaluar($e, $ctx);
            }
        }

        if ($valores === []) {
            return $n['not'] ? 1 : 0;            // NOT IN () siempre cierto
        }
        if ($v === null) {
            return null;
        }

        $hayNulo = false;
        foreach ($valores as $i => $x) {
            if ($x === null) { $hayNulo = true; continue; }
            // Con una columna de texto en la lista, x como texto para ella
            // (2.10, ver Evaluator::resolver())
            if (Valor::comparar(isset($n['textoEn'][$i]) ? Valor::conAfinidad($v, 'T') : $v, $x) === 0) {
                return $n['not'] ? 0 : 1;
            }
        }
        return $hayNulo ? null : ($n['not'] ? 1 : 0);
    }
}
