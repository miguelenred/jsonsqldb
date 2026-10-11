<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * UPDATE y DELETE: qué filas tocan (por un índice o recorriendo la tabla,
 * también con JOIN), y cambiarlas o quitarlas con sus índices. Métodos de
 * Writer que reciben el ejecutor en $w. Van aparte para que un INSERT no
 * tenga que compilarlos (2.9): sin OPcache, PHP compila en cada petición lo
 * que carga. Hereda de Writer solo para llegar a lo protegido; no se
 * instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class WriterCambios extends Writer
{
    private function __construct()
    {
    }

    public static function actualizar(Writer $w, array $ast): int
    {
        $tabla = $ast['tabla'];
        $meta  = $w->meta($tabla);
        $mapa  = $w->mapaColumnas($tabla, $meta, $ast['alias'] ?? null);

        $sets  = [];
        foreach ($ast['set'] as $s) {
            $col = Catalog::columna($meta, $s['col']);
            if ($col === null) {
                throw JsonSqlDbError::schema("La columna '{$s['col']}' no existe en '$tabla'");
            }
            $sets[] = ['col' => $col, 'expr' => $s['expr']['k'] === 'default' ? null : $s['expr']];
        }
        // Con otras tablas (2.9), lo que se asigna sale del cruce
        $cruce = isset($ast['desde']) ? self::cruce($w, $ast, array_column($sets, 'expr')) : null;
        if ($cruce === null) {
            foreach ($sets as $i => $s) {
                if ($s['expr'] !== null) {
                    $sets[$i]['expr'] = Evaluator::resolver($s['expr'], $mapa);
                }
            }
        }
        $where  = $ast['where'] === null || $cruce !== null ? null : Evaluator::resolver($ast['where'], $mapa);
        $simple = $where === null ? null : Valor::comparacionSimple($where);

        // Con un WHERE que resuelve un índice y nada que obligue a leer la
        // tabla, se leen solo las partes de las filas candidatas y al volcar se
        // reescriben solo esas partes. Con otras tablas, las del cruce
        [$parcial, $candidatas] = self::candidatasDe($w, $tabla, $meta, $ast, $cruce);
        $indices = $w->indicesUnicos($tabla, $meta);
        $sub     = $w->subconsultas($mapa);
        $conj    = $w->conjuntos($mapa);       // una vez, no en cada fila
        $tocadas = 0;

        foreach (self::objetivo($w, $ast, $mapa, $candidatas, $where, $simple, $sub, $conj) as $pos0 => $vieja) {
            $vieja = self::filaActual($w, $tabla, $pos0, $vieja);
            if ($vieja === null) {
                continue;                        // un trigger o una cascada ya la había borrado
            }
            $ctx = ['fila' => $vieja, 'sub' => $sub, 'conjunto' => $conj];
            $nueva = $vieja;
            foreach ($sets as $i => $s) {
                $nueva[$s['col']['name']] = $s['expr'] === null
                    ? self::porDefecto($s['col'])
                    : ($cruce !== null ? $cruce[$pos0]["v$i"] : Evaluator::evaluar($s['expr'], $ctx));
            }
            $nueva = $w->prepararFila($tabla, $meta, $nueva, false);

            $preparada = $nueva;
            $nueva = $w->lanzarTriggers($tabla, 'BEFORE', 'UPDATE', $nueva, $vieja);
            if ($nueva !== $preparada) {
                $nueva = $w->prepararFila($tabla, $meta, $nueva, false);
            }
            if (self::filaActual($w, $tabla, $pos0, $vieja) === null) {
                continue;                        // la ha borrado su propio BEFORE
            }
            $w->comprobarUnicos($tabla, $meta, $nueva, $indices, $vieja);
            $w->comprobarForaneas($tabla, $meta, $nueva);

            if ($parcial !== null && !isset($w->datos[$tabla])) {
                $w->cambios[$tabla][$pos0] = $nueva;
                $w->sucioDatos[$tabla]     = true;
            } else {
                // Sin variable local con la tabla: una segunda referencia viva
                // obligaría a PHP a copiarla entera en cada fila
                $w->ponerFilaEn($tabla, $pos0, $nueva);
            }
            $w->cambiarEnIndices($meta, $vieja, $nueva, $indices);
            // Las cascadas, con la fila ya escrita, como en SQLite (2.9): las
            // hijas apuntan a la clave nueva, que ya está, y lo que cambien en
            // la misma tabla (una clave foránea hacia sí misma) no se pisa
            $w->propagarHijos($tabla, $meta, $vieja, $nueva);
            $tocadas++;
            if (($ast['returning'] ?? null) !== null) {
                $w->devueltas[] = $nueva;
            }

            $w->lanzarTriggers($tabla, 'AFTER', 'UPDATE', $nueva, $vieja);
            if ($parcial !== null && !isset($w->datos[$tabla])) {
                $w->tramo($indices);
            }
        }

        return $tocadas;
    }

    public static function borrar(Writer $w, array $ast): int
    {
        $tabla = $ast['tabla'];
        $meta  = $w->meta($tabla);
        if (!empty($ast['truncar'])) {
            return self::vaciar($w, $tabla, $meta);
        }
        $mapa  = $w->mapaColumnas($tabla, $meta, $ast['alias'] ?? null);
        $cruce = isset($ast['desde']) ? self::cruce($w, $ast, []) : null;
        $where  = $ast['where'] === null || $cruce !== null ? null : Evaluator::resolver($ast['where'], $mapa);
        $simple = $where === null ? null : Valor::comparacionSimple($where);
        $sub   = $w->subconsultas($mapa);
        $conj  = $w->conjuntos($mapa);

        // Con la tabla en memoria, las filas se eligen antes de empezar y se
        // guarda su posición: casi siempre sigue ahí, y encontrarla otra vez
        // recorriendo la tabla era lo que volvía cuadrático un borrado masivo.
        // Sin cargarla, se borran según se leen, que nada se mueve (2.9: se
        // juntaban todas, y cien mil filas agotaban la memoria)
        [$parcial, $candidatas] = self::candidatasDe($w, $tabla, $meta, $ast, $cruce);
        $objetivo = self::objetivo($w, $ast, $mapa, $candidatas, $where, $simple, $sub, $conj);
        if ($parcial === null && !is_array($objetivo)) {
            $objetivo = iterator_to_array($objetivo, true);
        }

        $quitadas = 0;
        foreach ($objetivo as $pos0 => $vieja) {
            $vieja = self::filaActual($w, $tabla, $pos0, $vieja);
            if ($vieja === null) {
                continue;                        // ya la había borrado una cascada o un trigger
            }
            $w->lanzarTriggers($tabla, 'BEFORE', 'DELETE', null, $vieja);
            $vieja = self::filaActual($w, $tabla, $pos0, $vieja);
            if ($vieja === null) {
                continue;
            }
            // La fila sale antes de sus cascadas, como en SQLite (2.9): lo que
            // hagan los triggers de las hijas ya no la ve, y una fila nueva que
            // apunte a ella es un error de clave foránea (antes se aceptaba, y
            // quedaba apuntando a una fila borrada)
            if ($parcial !== null && !isset($w->datos[$tabla])) {
                $w->borradas[$tabla][$pos0] = true;
                $w->sucioDatos[$tabla]      = true;
            } else {
                $w->quitarFilaEn($tabla, $pos0);
            }
            $w->propagarHijos($tabla, $meta, $vieja, null);
            $quitadas++;
            if (($ast['returning'] ?? null) !== null) {
                $w->devueltas[] = $vieja;
            }

            $w->lanzarTriggers($tabla, 'AFTER', 'DELETE', null, $vieja);
            if ($parcial !== null && !isset($w->datos[$tabla])) {
                $w->tramo();
            }
        }
        if (isset($w->datos[$tabla])) {
            $w->porCompactar[$tabla] = true;  // al terminar la sentencia (ver filaActual())
        }
        return $quitadas;
    }

    /**
     * Las filas que cambia un UPDATE o un DELETE: las candidatas que cumplen
     * el WHERE, según se leen; y con ORDER BY o LIMIT (2.9), todas antes,
     * ordenadas (sin cambiar el orden de las que empatan) y solo las del LIMIT.
     *
     * @param iterable<int, array> $candidatas posición => fila
     * @return iterable<int, array>
     */
    private static function objetivo(Writer $w, array $ast, array $mapa, iterable $candidatas, ?array $where, $simple, callable $sub, callable $conj): iterable
    {
        $filtradas = (static function () use ($candidatas, $where, $simple, $sub, $conj): \Generator {
            foreach ($candidatas as $pos => $fila) {
                if ($where === null || self::cumple($where, $simple, $fila, ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conj])) {
                    yield $pos => $fila;
                }
            }
        })();
        $orden  = $ast['order'] ?? [];
        $limite = $ast['limit'] ?? null;
        $saltar = (int)($ast['offset'] ?? 0);
        if ($orden === [] && $limite === null && $saltar === 0) {
            return $filtradas;
        }
        if ($orden === []) {
            // Las primeras, sin juntar las demás
            return (static function () use ($filtradas, $limite, $saltar): \Generator {
                $n = 0;
                foreach ($filtradas as $pos => $fila) {
                    if ($limite !== null && $n >= $saltar + $limite) {
                        return;
                    }
                    if ($n++ >= $saltar) {
                        yield $pos => $fila;
                    }
                }
            })();
        }
        $exprs  = array_map(static fn(array $o): array => Evaluator::resolver($o['expr'], $mapa), $orden);
        $claves = [];
        $ordenar = static function (array $elegidas) use (&$claves, $orden): array {
            uksort($elegidas, static function (int $a, int $b) use ($claves, $orden): int {
                foreach ($orden as $i => $o) {
                    $c = Valor::compararOrden($claves[$a][$i], $claves[$b][$i]);
                    if ($c !== 0) {
                        return strtoupper((string)$o['dir']) === 'DESC' ? -$c : $c;
                    }
                }
                return $a <=> $b;
            });
            return $elegidas;
        };
        // Con LIMIT, solo las que pueden quedar: al pasar del doble se ordenan
        // y se recortan (2.9: se juntaban todas las que cumplían el WHERE)
        $cuantas  = $limite === null ? null : $saltar + $limite;
        $elegidas = [];
        foreach ($filtradas as $pos => $fila) {
            $ctx = ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conj];
            $claves[$pos]   = array_map(static fn(array $e) => Evaluator::evaluar($e, $ctx), $exprs);
            $elegidas[$pos] = $fila;
            if ($cuantas !== null && count($elegidas) > 2 * $cuantas + 100) {
                $elegidas = array_slice($ordenar($elegidas), 0, $cuantas, true);
                $claves   = array_intersect_key($claves, $elegidas);
            }
        }
        return array_slice($ordenar($elegidas), $saltar, $limite, true);
    }

    /**
     * ¿La fila cumple el WHERE? Con una comparación simple se resuelve sin pasar
     * por el evaluador general, que es lo que domina el coste en tablas grandes.
     *
     * @param array{clave: string, op: string, valor: mixed}|null $simple
     */
    private static function cumple(array $where, ?array $simple, array $fila, array $ctx): bool
    {
        if ($simple !== null) {
            $v = $fila[$simple['clave']] ?? null;
            return $v !== null && Valor::compara($simple['op'], $v, $simple['valor']);
        }
        return Valor::verdadero(Evaluator::evaluar($where, $ctx)) === true;
    }

    /**
     * La fila de la posición $pos tal como está ahora, o null si ya no está.
     * Como en SQLite (2.9), un UPDATE o un DELETE trabaja con las filas que
     * eligió al empezar, cada una como la hayan dejado los triggers y las
     * cascadas de las anteriores; antes, una fila cambiada así se saltaba.
     * Las posiciones no se mueven en toda la sentencia: los huecos de lo
     * borrado se cierran al terminarla (ver despachar()).
     */
    public static function filaActual(Writer $w, string $tabla, int $pos, array $leida): ?array
    {
        if (isset($w->datos[$tabla])) {
            return $w->datos[$tabla][$pos] ?? null;
        }
        return isset($w->borradas[$tabla][$pos]) ? null : $w->cambios[$tabla][$pos] ?? $leida;
    }

    /**
     * Las filas sobre las que trabaja un UPDATE o un DELETE, posición => fila,
     * y si se han leído sin cargar la tabla (las del índice, ver porIndice()).
     * Con otras tablas (2.9), las que salen en el cruce.
     *
     * @param array<int, array>|null $cruce ver cruce()
     * @return array{0: ?iterable, 1: iterable<int, array>}
     */
    private static function candidatasDe(Writer $w, string $tabla, array $meta, array $ast, ?array $cruce): array
    {
        if ($cruce === null) {
            $parcial = $w->sinLeer($tabla, $meta) ? self::porIndice($w, $tabla, $meta, $ast, true) : null;
            return [$parcial, $parcial ?? self::candidatas($w, $tabla, $meta, $ast)];
        }
        // sinLeer() después del cruce: si leyó la tabla de la memoria, la ha cargado
        $parcial = $w->sinLeer($tabla, $meta) ? StorageCambios::filasEnPosiciones($w->cat->storage(), $tabla, array_keys($cruce)) : null;
        return [$parcial, $parcial ?? array_intersect_key($w->filas($tabla), $cruce)];
    }

    /**
     * Las filas sobre las que evaluar un WHERE: las que acota un índice, o la
     * tabla entera.
     *
     * @return array<int,array> posición => fila
     */
    private static function candidatas(Writer $w, string $tabla, array $meta, array $ast): array
    {
        $porIndice = isset($w->datos[$tabla]) ? null : self::porIndice($w, $tabla, $meta, $ast);
        $filas     = $w->filas($tabla);
        if ($porIndice === null) {
            return $filas;
        }
        return array_intersect_key($filas, is_array($porIndice) ? $porIndice : iterator_to_array($porIndice, true));
    }

    /**
     * Filas que pueden cumplir un WHERE de igualdad sobre una columna
     * indexada, leídas de sus partes y sin pasar por la tabla entera. Null si
     * no hay índice que sirva. Del WHERE tal como se escribió, no del
     * resuelto: ahí una columna con COLLATE va dentro de PLEGAR (2.9), y el
     * índice ya pliega lo que busca.
     *
     * @return iterable<int,array>|null posición => fila
     */
    private static function porIndice(Writer $w, string $tabla, array $meta, array $ast, bool $conTexto = false): ?iterable
    {
        if (isset($w->sucioDatos[$tabla])) {
            return null;                             // el índice no conoce los cambios en memoria
        }
        $st = $w->cat->storage();
        if ($ast['where'] === null) {
            // Todas, parte a parte (2.9: un UPDATE sin WHERE cargaba la tabla entera)
            return $conTexto ? StorageCambios::filasConPosicion($st, $tabla) : null;
        }
        $alias      = strtolower($ast['alias'] ?? $tabla);
        $predicados = IndexesBusqueda::predicados($ast['where'], $alias)[$alias] ?? [];
        // Del mejor al peor: si uno no compensa, el siguiente
        foreach (IndexesBusqueda::candidatos($w->cat->indicesDe($tabla), $predicados) as $c) {
            $posiciones = $st->posicionesPorIndice($tabla, $c['def'], $c['claves'], $c['prefijo']);
            if ($posiciones !== null) {
                return StorageCambios::filasEnPosiciones($st, $tabla, $posiciones);
            }
        }
        // Sin índice que sirva, un col = 'texto' todavía deja saltar las
        // partes que no lo contienen: un UPDATE o DELETE de unas pocas filas
        // ya no carga la tabla entera. Solo cuando lo que se devuelve evita
        // cargarla ($conTexto): si después se carga igual, leer las partes
        // antes sería leerlas dos veces
        if (!$conTexto) {
            return null;
        }
        // Y si no hay texto que buscar, la tabla se recorre parte a parte en
        // vez de cargarla entera: en memoria quedan solo las filas que cumplen
        return StorageCambios::filasConTexto($st, $tabla, IndexesBusqueda::agujas($predicados, Catalog::colaciones($meta)))
            ?? StorageCambios::filasConPosicion($st, $tabla);
    }

    protected static function quitarDeIndices(Writer $w, array $meta, array $fila, array &$indices): void
    {
        foreach ($w->unicos($meta) as $uq) {
            $clave = self::claveDe($fila, $uq['columns'], $uq['plegar']);
            if ($clave !== null) {
                $indices[$uq['name']]['quitadas'][$clave] = true;
                unset($indices[$uq['name']]['nuevas'][$clave]);
            }
        }
    }

    /**
     * TRUNCATE TABLE de MySQL (2.9): todas las filas, sin triggers y con el
     * autoincremento otra vez en 1; si otra tabla la referencia, error, y una
     * clave foránea hacia sí misma no lo impide, como en MySQL. Sin leer las
     * filas: la tabla se escribe vacía, y con el contador en su estructura,
     * que guarda el mayor de los dos (ver Storage::leerMeta()), en la misma
     * escritura. Antes se borraba fila a fila, la clave hacia sí misma daba
     * error, y la estructura se guardaba antes y aparte: escrita por partes,
     * la sentencia se repetía entera.
     */
    private static function vaciar(Writer $w, string $tabla, array $meta): int
    {
        foreach ($w->clavesHacia($tabla) as [$otra]) {
            if (strcasecmp($otra, $tabla) !== 0) {
                throw JsonSqlDbError::constraint("TRUNCATE TABLE '$tabla': '$otra' tiene una clave foránea hacia ella; usa DELETE FROM");
            }
        }
        $n = isset($w->datos[$tabla]) ? count($w->datos[$tabla])
            : StorageLectura::contarFilas($w->cat->storage(), $tabla) + count($w->anexo[$tabla] ?? []) - count($w->borradas[$tabla] ?? []);
        $auto = Catalog::columnaAutoincremento($meta) !== null;
        if ($n === 0 && !$auto) {
            return 0;                            // nada que escribir
        }
        $w->datos[$tabla] = [];
        unset($w->anexo[$tabla], $w->cambios[$tabla], $w->borradas[$tabla], $w->rowid[$tabla], $w->unicas[$tabla]);
        $w->olvidarIndices($tabla);
        $w->sucioDatos[$tabla] = true;
        $w->sabe[$tabla]       = false;       // entera (ver volcar())
        if ($auto) {
            $w->autoinc[$tabla] = 1;
            $w->metas[$tabla]['autoincrement']['next'] = 1;
            $w->conMeta[$tabla] = true;
        }
        return $n;
    }

    /**
     * UPDATE … FROM / JOIN y DELETE … USING / JOIN (2.9): el cruce se hace una
     * vez con el motor del SELECT, con los datos de antes de la sentencia, y
     * da cada fila de la tabla que se escribe por su posición, que es por
     * donde se escribe después. Si sale varias veces cuenta la primera, como
     * en SQLite y PostgreSQL.
     *
     * Lee del disco, con sus índices, salvo que la sentencia tenga algo a
     * medias en memoria en alguna de sus tablas (un trigger): entonces de ahí.
     *
     * @param list<array|null> $exprs lo que asigna el SET (null: DEFAULT, no sale del cruce)
     * @return array<int, array<string, mixed>> posición => ["v$i" => valor de $exprs[$i]], por posición
     */
    private static function cruce(Writer $w, array $ast, array $exprs): array
    {
        $sel = Parser::analizar('SELECT 1');
        $sel['cols'] = [['star' => false, 'alias' => 'p',
                         'expr' => ['k' => 'col', 'tabla' => $ast['alias'] ?? $ast['tabla'], 'nombre' => Select::POSICION]]];
        foreach ($exprs as $i => $e) {
            if ($e !== null) {
                $sel['cols'][] = ['star' => false, 'alias' => "v$i", 'expr' => $e];
            }
        }
        $sel['from']  = $ast['desde'];
        $sel['where'] = $ast['where'];

        $tablas  = Select::tablasDe($sel, $w->cat);
        $memoria = $tablas === null;
        $sucias  = array_change_key_case($w->sucioDatos);
        foreach ($tablas ?? [] as $t) {
            $memoria = $memoria || isset($sucias[strtolower($t)]);
        }
        $select = $memoria ? new Select($w->cat, $w->lectorSub()) : new Select($w->cat);

        $out     = [];
        $guardar = static function (array $f) use (&$out): void {
            $p = $f['p'];
            if ($p !== null && !isset($out[$p])) {
                unset($f['p']);
                $out[$p] = $f;
            }
        };
        foreach ($select->ejecutarHacia($sel, $guardar) ?? [] as $f) {
            $guardar($f);
        }
        ksort($out);
        return $out;
    }
}
