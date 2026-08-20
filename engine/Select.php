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
    /** @var callable(string):array lector de filas; permite ver cambios aún no volcados a disco */
    private $lector;
    /** @var array<int,array> resultado de cada subconsulta ya ejecutada */
    private array $subs = [];

    public function __construct(Catalog $cat, ?callable $lector = null)
    {
        $this->cat    = $cat;
        $this->lector = $lector ?? static fn(string $t): array => $cat->storage()->leerFilas($t);
    }

    /** Ejecuta la consulta y devuelve las filas de salida. */
    public function ejecutar(array $ast): array
    {
        return $this->correr($ast)['filas'];
    }

    /** @return array{cols: string[], filas: array} */
    private function correr(array $ast): array
    {
        [$filas, $claves] = $this->origenes($ast['from']);
        $mapa = $this->mapaColumnas($claves);
        $sub  = function (array $sel, int $sid): array {
            return $this->subs[$sid] ??= $this->correr($sel)['filas'];
        };

        // WHERE
        if ($ast['where'] !== null) {
            $where = Evaluator::resolver($ast['where'], $mapa);
            $filtradas = [];
            foreach ($filas as $fila) {
                if (Valor::verdadero(Evaluator::evaluar($where, ['fila' => $fila, 'sub' => $sub])) === true) {
                    $filtradas[] = $fila;
                }
            }
            $filas = $filtradas;
        }

        // Columnas de salida (expandiendo * )
        $salida = $this->columnasSalida($ast['cols'], $claves, $mapa);

        // ¿Hay agrupación?
        $grupoExprs = [];
        if ($ast['group'] !== null) {
            foreach ($ast['group'] as $e) {
                $grupoExprs[] = Evaluator::resolver($e, $mapa);
            }
        }
        $agrupar = $grupoExprs !== [] || $ast['having'] !== null || $this->hayAgregados($salida, $ast);

        $having = $ast['having'] === null ? null : Evaluator::resolver($ast['having'], $mapa);

        // Alias de salida utilizables en ORDER BY
        $aliasSalida = [];
        foreach ($salida as $c) {
            $aliasSalida[strtolower($c['nombre'])] = $c['nombre'];
        }
        $orden = [];
        foreach ($ast['order'] as $o) {
            $orden[] = ['expr' => Evaluator::resolver($o['expr'], $mapa, $aliasSalida), 'dir' => $o['dir']];
        }

        // Grupos: cada elemento es [fila representativa, filas del grupo|null]
        $bloques = $agrupar ? $this->agrupar($filas, $grupoExprs, $sub) : null;

        // Proyección + claves de ordenación
        $resultado = [];
        $clavesOrden = [];

        if ($bloques !== null) {
            foreach ($bloques as $grupo) {
                $ctx = ['fila' => $grupo[0] ?? [], 'grupo' => $grupo, 'sub' => $sub];
                if ($having !== null && Valor::verdadero(Evaluator::evaluar($having, $ctx)) !== true) {
                    continue;
                }
                $fila = [];
                foreach ($salida as $c) {
                    $fila[$c['nombre']] = Evaluator::evaluar($c['expr'], $ctx);
                }
                $resultado[]   = $fila;
                $clavesOrden[] = $this->clavesOrden($orden, $ctx, $fila);
            }
        } else {
            foreach ($filas as $f) {
                $ctx  = ['fila' => $f, 'sub' => $sub];
                $fila = [];
                foreach ($salida as $c) {
                    $fila[$c['nombre']] = Evaluator::evaluar($c['expr'], $ctx);
                }
                $resultado[]   = $fila;
                $clavesOrden[] = $this->clavesOrden($orden, $ctx, $fila);
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
                $k[] = $clavesOrden[$i];
            }
            $resultado   = $r;
            $clavesOrden = $k;
        }

        // ORDER BY
        if ($orden !== []) {
            $indices = array_keys($resultado);
            usort($indices, static function (int $a, int $b) use ($clavesOrden, $orden): int {
                foreach ($orden as $i => $o) {
                    $c = Valor::compararOrden($clavesOrden[$a][$i], $clavesOrden[$b][$i]);
                    if ($c !== 0) {
                        return $o['dir'] === 'DESC' ? -$c : $c;
                    }
                }
                return $a <=> $b;                        // orden estable
            });
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

    // ==================================================================
    // Orígenes de datos y JOIN
    // ==================================================================

    /** @return array{0: array, 1: string[]} filas planas y lista de claves "alias.columna" */
    private function origenes(array $from): array
    {
        if ($from === []) {
            return [[[]], []];                            // SELECT sin FROM: una fila vacía
        }

        $primero = $this->cargar($from[0]);
        $filas   = $primero['filas'];
        $claves  = $primero['claves'];

        for ($i = 1, $n = count($from); $i < $n; $i++) {
            $der    = $this->cargar($from[$i]);
            $filas  = $this->unir($filas, $claves, $der, $from[$i]);
            $claves = array_merge($claves, $der['claves']);
        }
        return [$filas, $claves];
    }

    /** Carga una tabla o subconsulta como filas planas con prefijo de alias. */
    private function cargar(array $o): array
    {
        if ($o['tipo'] === 'sub') {
            $r      = $this->correr($o['select']);
            $alias  = $o['alias'];
            $cols   = $r['cols'];
            $origen = $r['filas'];
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
            $origen = ($this->lector)($nombre);
        }

        $claves = [];
        foreach ($cols as $c) {
            $claves[] = $alias . '.' . $c;
        }

        $filas = [];
        foreach ($origen as $fila) {
            $plana = [];
            foreach ($cols as $i => $c) {
                $plana[$claves[$i]] = $fila[$c] ?? null;
            }
            $filas[] = $plana;
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
                    $salida[] = $a + $b;
                }
            }
            return $salida;
        }

        $mapa = $this->mapaColumnas(array_merge($clavesIzq, $der['claves']));
        $on   = Evaluator::resolver($o['on'], $mapa);
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

        // Índice hash del lado interno
        $indice = null;
        if ($clavesInt !== []) {
            $indice = [];
            foreach ($internas as $fila) {
                $clave = $this->claveHash($fila, $clavesInt);
                if ($clave !== null) {
                    $indice[$clave][] = $fila;
                }
            }
        }

        $salida = [];
        foreach ($externas as $ext) {
            $candidatas = $internas;
            if ($indice !== null) {
                $clave      = $this->claveHash($ext, $clavesExt);
                $candidatas = $clave === null ? [] : ($indice[$clave] ?? []);
            }

            $encontrada = false;
            foreach ($candidatas as $int) {
                $fila = $ext + $int;
                if ($resto !== null
                    && Valor::verdadero(Evaluator::evaluar($resto, ['fila' => $fila, 'sub' => $sub])) !== true) {
                    continue;
                }
                $salida[]   = $fila;
                $encontrada = true;
            }
            if (!$encontrada && $tipo !== 'INNER') {
                $salida[] = $ext + $nulos;
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
            $expr = Evaluator::resolver($c['expr'], $mapa);
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
    private function agrupar(array $filas, array $exprs, callable $sub): array
    {
        if ($exprs === []) {
            return [$filas];        // agregación total: una sola fila aunque no haya datos
        }
        $grupos = [];
        foreach ($filas as $fila) {
            $ctx   = ['fila' => $fila, 'sub' => $sub];
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
