<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que solo usan algunas consultas: las funciones de ventana (OVER,
 * WINDOW, los marcos ROWS, RANGE y GROUPS) y la comprobación de un WITH
 * RECURSIVE. Métodos de Parser que reciben el analizador en $an. Van aparte
 * para que el resto de consultas no tengan que compilarlos (2.9): sin
 * OPcache, PHP compila en cada petición lo que carga. Hereda de Parser solo
 * para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class ParserAvanzado extends Parser
{
    private function __construct()
    {
    }

    // ==================================================================
    // SELECT
    // ==================================================================

    /**
     * Si la consulta $nombre del WITH se nombra a sí misma, la posición de su
     * primera parte recursiva; si no, null. Como en SQLite: unas partes que no
     * se nombran, después las que sí, unidas con UNION o UNION ALL; cada una de
     * estas la nombra una vez, en su FROM. Lo que se calcula por tandas (ver
     * SelectCompuesta::recursiva()) y daría otra cosa que fila a fila —un
     * agregado, DISTINCT, ella en el lado de un JOIN que rellena con NULL— o el
     * ORDER BY, que en SQLite decide el orden de la cola, es un error que lo
     * dice.
     */
    public static function recursiva(array $sel, string $nombre): ?int
    {
        if (ParserConsulta::vecesQueNombra($sel, $nombre) === 0) {
            return null;
        }
        $partes  = $sel['k'] === 'union' ? $sel['partes'] : [$sel];
        $primera = null;
        foreach ($partes as $i => $p) {
            $veces = ParserConsulta::vecesQueNombra($p, $nombre);
            if ($veces === 0) {
                if ($primera !== null) {
                    throw JsonSqlDbError::syntax("En '$nombre', las partes que no se nombran a sí mismas van antes que las que sí");
                }
                continue;
            }
            $primera ??= $i;
            $suya = null;
            foreach ($p['from'] as $j => $o) {
                if ($o['tipo'] === 'tabla' && strcasecmp((string)$o['nombre'], $nombre) === 0) {
                    $suya = $j;
                }
            }
            if ($veces > 1 || $suya === null) {
                throw JsonSqlDbError::syntax("Cada parte recursiva de '$nombre' la nombra una sola vez, en su FROM (no en una subconsulta ni dos veces)");
            }
            if (\in_array($p['from'][$suya]['join'], ['LEFT', 'FULL'], true)
                || array_intersect(['RIGHT', 'FULL'], array_column(\array_slice($p['from'], $suya + 1), 'join')) !== []) {
                throw JsonSqlDbError::syntax("'$nombre' no puede ir en el lado de un LEFT, RIGHT o FULL JOIN que se rellena con NULL dentro de sí misma");
            }
            $agregado = $p['group'] !== null || $p['having'] !== null || $p['distinct'];
            foreach ($p['cols'] as $c) {
                $agregado = $agregado || (empty($c['star']) && Evaluator::tieneAgregado($c['expr']));
            }
            if ($agregado) {
                throw JsonSqlDbError::syntax("La parte recursiva de '$nombre' no admite agregados, GROUP BY ni DISTINCT");
            }
        }
        if ($primera === 0) {
            throw JsonSqlDbError::syntax("'$nombre' se nombra a sí misma: necesita antes una parte que no lo haga, y UNION o UNION ALL entre ellas");
        }
        if (array_diff(\array_slice($sel['ops'], $primera - 1), ['UNION', 'UNION ALL']) !== []) {
            throw JsonSqlDbError::syntax("En '$nombre', entre sus partes recursivas y las de antes solo vale UNION o UNION ALL");
        }
        if ($sel['order'] !== []) {
            throw JsonSqlDbError::syntax("ORDER BY dentro de '$nombre', que se nombra a sí misma, no está soportado: ordena en la consulta de fuera");
        }
        return $primera;
    }

    // ==================================================================
    // Expresiones (de menor a mayor precedencia)
    // ==================================================================

    /**
     * Una función de ventana (2.9), con el OVER ya leído: f(…) [FILTER (WHERE
     * …)] OVER nombre | OVER (…). Las de numerar y LAG, LEAD, FIRST_VALUE,
     * LAST_VALUE y NTH_VALUE, y cualquier agregado. Se calcula en
     * SelectAvanzada::ventanas().
     */
    public static function ventana(Parser $an, array $fn, ?array $filtro): array
    {
        $nombre   = $fn['nombre'];
        $agregado = Evaluator::esAgregado($fn);
        if (!$an->ventanasPermitidas) {
            throw JsonSqlDbError::syntax("Una función de ventana ($nombre … OVER) solo puede ir en las columnas de un SELECT o en su ORDER BY");
        }
        if (!$agregado && !isset(self::VENTANA[$nombre])) {
            throw JsonSqlDbError::syntax("$nombre() no es una función de ventana ni de agregado: no admite OVER");
        }
        if ($fn['distinct'] || isset($fn['orden'])) {
            throw JsonSqlDbError::syntax("$nombre(): DISTINCT y ORDER BY dentro de los paréntesis no están soportados con OVER");
        }
        if ($filtro !== null && !$agregado) {
            throw JsonSqlDbError::syntax("FILTER solo vale con una función de agregado, no con $nombre()");
        }
        [$min, $max] = self::VENTANA[$nombre] ?? [0, 2];
        if (!$agregado && ($fn['star'] || \count($fn['args']) < $min || \count($fn['args']) > $max)) {
            throw JsonSqlDbError::syntax("$nombre() lleva " . ($min === $max ? $min : "de $min a $max") . ' argumento(s)');
        }
        foreach (array_merge($fn['args'], $filtro === null ? [] : [$filtro]) as $a) {
            if (self::conVentana($a)) {
                throw JsonSqlDbError::syntax("No se puede poner una función de ventana dentro de $nombre()");
            }
        }
        $an->ventanasVistas++;
        $an->ventanasPermitidas = false;              // ni en su PARTITION BY ni en su ORDER BY
        $def = $an->es('punc', '(')
            ? self::defVentana($an)
            : ['base' => $an->nombreSimple('ventana'), 'tal' => true, 'particion' => [], 'orden' => [], 'marco' => null];
        $an->ventanasPermitidas = true;
        return ['k' => 'ventana', 'nombre' => $nombre, 'args' => $fn['args'], 'star' => $fn['star'], 'filtro' => $filtro, 'def' => $def];
    }

    /** (… [nombre] [PARTITION BY …] [ORDER BY …] [ROWS | RANGE | GROUPS …]): una ventana */
    public static function defVentana(Parser $an): array
    {
        $an->exigir('punc', '(');
        $def = ['base' => null, 'particion' => [], 'orden' => [], 'marco' => null];
        $tk  = $an->actual();
        if ($tk['t'] === 'id' && ($tk['q'] || !\in_array($tk['u'], ['PARTITION', 'ORDER', 'ROWS', 'RANGE', 'GROUPS'], true))) {
            $def['base'] = $an->avanzar()['v'];
        }
        if ($an->comer('id', 'PARTITION')) {
            $an->exigir('id', 'BY');
            do {
                $def['particion'][] = $an->plegar([$an->expr()])[0];
            } while ($an->comer('punc', ','));
        }
        if ($an->comer('id', 'ORDER')) {
            $an->exigir('id', 'BY');
            $def['orden'] = ParserConsulta::listaOrden($an);
        }
        if ($an->es('id', 'ROWS') || $an->es('id', 'RANGE') || $an->es('id', 'GROUPS')) {
            $def['marco'] = self::marco($an);
        }
        $an->exigir('punc', ')');
        return $def;
    }

    /**
     * El marco de una ventana: {ROWS | RANGE | GROUPS} {BETWEEN inicio AND fin
     * | inicio} [EXCLUDE {NO OTHERS | CURRENT ROW | GROUP | TIES}]. Un
     * desplazamiento es una expresión constante: aquí ya queda su valor.
     */
    private static function marco(Parser $an): array
    {
        $tipo = $an->avanzar()['u'];
        if ($an->comer('id', 'BETWEEN')) {
            $inicio = self::limiteMarco($an, $tipo, true);
            $an->exigir('id', 'AND');
            $fin = self::limiteMarco($an, $tipo, false);
        } else {
            $inicio = self::limiteMarco($an, $tipo, true);
            $fin    = ['k' => 'CURRENT ROW'];
        }
        $rango = ['UNBOUNDED PRECEDING' => 0, 'PRECEDING' => 1, 'CURRENT ROW' => 2, 'FOLLOWING' => 3, 'UNBOUNDED FOLLOWING' => 4];
        if ($inicio['k'] === 'UNBOUNDED FOLLOWING' || $fin['k'] === 'UNBOUNDED PRECEDING' || $rango[$inicio['k']] > $rango[$fin['k']]) {
            throw JsonSqlDbError::syntax("Marco de ventana no soportado: de {$inicio['k']} a {$fin['k']}");
        }
        $excluir = 'NO OTHERS';
        if ($an->comer('id', 'EXCLUDE')) {
            if ($an->comer('id', 'NO')) {
                $an->exigir('id', 'OTHERS');
            } elseif ($an->comer('id', 'CURRENT')) {
                $an->exigir('id', 'ROW');
                $excluir = 'CURRENT ROW';
            } elseif ($an->comer('id', 'GROUP')) {
                $excluir = 'GROUP';
            } else {
                $an->exigir('id', 'TIES');
                $excluir = 'TIES';
            }
        }
        return ['tipo' => $tipo, 'inicio' => $inicio, 'fin' => $fin, 'excluir' => $excluir];
    }

    /** Un extremo del marco: UNBOUNDED PRECEDING | n PRECEDING | CURRENT ROW | n FOLLOWING | UNBOUNDED FOLLOWING */
    private static function limiteMarco(Parser $an, string $tipo, bool $inicio): array
    {
        if ($an->comer('id', 'UNBOUNDED')) {
            if ($an->comer('id', 'PRECEDING')) {
                return ['k' => 'UNBOUNDED PRECEDING'];
            }
            $an->exigir('id', 'FOLLOWING');
            return ['k' => 'UNBOUNDED FOLLOWING'];
        }
        if ($an->comer('id', 'CURRENT')) {
            $an->exigir('id', 'ROW');
            return ['k' => 'CURRENT ROW'];
        }
        $e = ParserConsulta::sinFila($an, 'El marco de una ventana');
        try {
            $v = Evaluator::evaluar(Evaluator::resolver($e, []), []);
        } catch (JsonSqlDbError) {
            $v = null;
        }
        $v = Valor::esNumerico($v) ? Valor::aNumero($v) : null;
        // Con ROWS y GROUPS, un entero que quepa en 64 bits (2.10: 9e999 y 1e19 valían 0)
        if ($v === null || $v < 0 || ($tipo !== 'RANGE' && !\is_int($v) && !(is_finite($v) && floor($v) === $v && $v < 9.2233720368547758E18))) {
            throw JsonSqlDbError::syntax('El ' . ($inicio ? 'inicio' : 'final') . ' del marco tiene que ser '
                . ($tipo === 'RANGE' ? 'un número' : 'un entero') . ' no negativo y constante');
        }
        $k = $an->comer('id', 'PRECEDING') ? 'PRECEDING' : null;
        if ($k === null) {
            $an->exigir('id', 'FOLLOWING');
            $k = 'FOLLOWING';
        }
        return ['k' => $k, 'n' => $tipo === 'RANGE' ? $v : (int)$v];
    }

    /**
     * Una ventana basada en otra con nombre (WINDOW): como en SQLite, toma su
     * PARTITION BY, su ORDER BY si no lleva el suyo, y no puede cambiar
     * ninguno de los dos ni ampliar una que ya tiene marco. OVER nombre, sin
     * paréntesis ('tal'), es esa ventana tal cual.
     *
     * @param array<string, array> $ventanas las de la cláusula WINDOW, en minúsculas
     */
    public static function conBase(array $def, array $ventanas): array
    {
        if ($def['base'] === null) {
            return $def;
        }
        $b    = $def['base'];
        $base = $ventanas[strtolower($b)] ?? null;
        if ($base === null) {
            throw JsonSqlDbError::syntax("No hay ninguna ventana '$b' en el WINDOW de la consulta");
        }
        if (!empty($def['tal'])) {
            return $base;
        }
        if ($base['marco'] !== null) {
            throw JsonSqlDbError::syntax("La ventana '$b' ya tiene marco (ROWS, RANGE o GROUPS): no se puede ampliar");
        }
        if ($def['particion'] !== []) {
            throw JsonSqlDbError::syntax("Una ventana basada en '$b' no puede cambiar su PARTITION BY");
        }
        if ($def['orden'] !== [] && $base['orden'] !== []) {
            throw JsonSqlDbError::syntax("Una ventana basada en '$b' no puede cambiar su ORDER BY");
        }
        return ['base' => null, 'particion' => $base['particion'], 'orden' => $def['orden'] ?: $base['orden'], 'marco' => $def['marco']];
    }

    /**
     * Pone a cada función de ventana su ventana entera (la del WINDOW si se
     * basa en una) y comprueba lo que depende de ella: RANGE con un
     * desplazamiento necesita un solo ORDER BY. No entra en las subconsultas,
     * que tienen las suyas.
     */
    public static function resolverVentanas(array $n, array $ventanas): array
    {
        if (($n['k'] ?? null) === 'ventana') {
            $def = self::conBase($n['def'], $ventanas);
            unset($def['tal']);
            $m = $def['marco'];
            if ($m !== null && $m['tipo'] === 'RANGE' && (isset($m['inicio']['n']) || isset($m['fin']['n']))
                && \count(array_filter($def['orden'], static fn(array $o): bool => empty($o['nulos']))) !== 1) {
                throw JsonSqlDbError::syntax('RANGE con un desplazamiento (n PRECEDING o FOLLOWING) necesita un ORDER BY de una sola expresión');
            }
            $n['def'] = $def;
            return $n;
        }
        foreach ($n as $k => $v) {
            if (\is_array($v) && $k !== 'select') {
                $n[$k] = self::resolverVentanas($v, $ventanas);
            }
        }
        return $n;
    }

    /** ¿Hay alguna función de ventana en la expresión? (sin entrar en subconsultas) */
    private static function conVentana(array $n): bool
    {
        if (($n['k'] ?? null) === 'ventana') {
            return true;
        }
        foreach ($n as $k => $v) {
            if (\is_array($v) && $k !== 'select' && self::conVentana($v)) {
                return true;
            }
        }
        return false;
    }
}
