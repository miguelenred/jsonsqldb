<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * INSERT: las filas nuevas, sus claves únicas y su clave primaria entera o
 * su AUTOINCREMENT (2.10, aparte de Writer para que un UPDATE o un DELETE no
 * tengan que compilarlo: sin OPcache, PHP compila en cada petición lo que
 * carga). Métodos de Writer que reciben el ejecutor en $w. Hereda de Writer
 * solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class WriterInsercion extends Writer
{
    private function __construct()
    {
    }

    public static function insertar(Writer $w, array $ast): int
    {
        $tabla = $ast['tabla'];
        $meta  = $w->meta($tabla);

        $nombres = [];
        foreach ($meta['columns'] as $c) {
            $nombres[] = $c['name'];
        }
        $cols = $ast['cols'] ?? $nombres;

        foreach ($cols as $i => $c) {
            $col = Catalog::columna($meta, $c);
            if ($col === null) {
                throw JsonSqlDbError::schema("La columna '$c' no existe en '$tabla'");
            }
            $cols[$i] = $col['name'];
        }

        // Si la tabla no está en memoria y nada obliga a leerla, las filas
        // nuevas se apuntan aparte y se añaden al final al volcar: la unicidad
        // se comprueba contra los índices de disco y una tabla de cien mil
        // filas no pasa por la memoria para insertar una. Con ON CONFLICT, OR
        // IGNORE u OR REPLACE la fila con que choca se busca también en el
        // índice (2.9: se cargaba la tabla entera, y con un millón de filas un
        // upsert de una sola no cabía en 256 MB); sin índices que sirvan, en
        // la tabla cargada, como siempre
        $conflicto = $ast['conflicto'] ?? null;
        $devolver  = ($ast['returning'] ?? null) !== null;
        $objetivo  = null;
        $anexar    = $w->sinLeer($tabla, $meta);
        if ($conflicto !== null) {
            if (!$anexar) {
                $w->filas($tabla);
            }
            unset($w->unicas[$tabla], $w->defUnica[$tabla], $w->vacioUnica[$tabla]);
            $objetivo = $conflicto['cols'] === null ? null : WriterConflictos::claveObjetivo($w, $tabla, $meta, $conflicto['cols']);
        }
        $indices = $w->indicesUnicos($tabla, $meta);
        $vivos   = $w->indicesVivos;
        $w->indicesVivos[$tabla] = &$indices;
        $puestas = 0;
        $borradasPorReemplazo = false;
        // Con OR IGNORE, una fila que no cumple un NOT NULL o un CHECK se
        // salta, como en SQLite, después de sus triggers BEFORE (2.10: era un
        // error y no entraba ninguna). Con ON CONFLICT DO NOTHING, no: solo
        // mira las claves únicas, también como en SQLite
        // Con OR REPLACE, un NULL en una columna NOT NULL toma su valor por
        // defecto, también como en SQLite (2.10: era un error)
        $restringida = ($meta['checks'] ?? []) !== [] || \in_array(true, array_column($meta['columns'], 'notnull'), true);
        $saltar      = ($conflicto['saltar'] ?? false) && $restringida;
        $porDefecto  = ($conflicto['modo'] ?? null) === 'reemplazar' && $restringida;

        // Lo de cada fila, por su clase y no con self::, que dentro de un
        // cierre se resuelve en cada llamada (el cierre podría cambiar de
        // clase): eran dos búsquedas de clase por fila
        $meter = static function (array $valores) use ($w, $tabla, $meta, $cols, $conflicto, $objetivo, $anexar, $devolver, $saltar,
                                                       $porDefecto, &$indices, &$puestas, &$borradasPorReemplazo): void {
            if ($anexar && !isset($w->datos[$tabla])) {
                $w->tramo($indices);                 // lo que llevan las anteriores, si ya no cabe
            }
            if (\count($valores) !== \count($cols)) {
                throw JsonSqlDbError::constraint(
                    'El número de valores (' . \count($valores) . ') no coincide con el de columnas (' . \count($cols) . ')'
                );
            }

            $nueva = [];
            foreach ($meta['columns'] as $c) {
                $nueva[$c['name']] = $c['default_expr'] === null ? $c['default'] : WriterInsercion::porDefecto($c);
            }
            foreach ($cols as $i => $c) {
                if (!(\is_array($valores[$i]) && ($valores[$i][0] ?? null) === '__default__')) {
                    $nueva[$c] = $valores[$i];
                }
            }

            $rowid = $w->rowid[$tabla] ?? null;
            $nueva = $w->prepararFila($tabla, $meta, $nueva, true, !$saltar && !$porDefecto);

            $preparada = $nueva;
            $nueva = $w->lanzarTriggers($tabla, 'BEFORE', 'INSERT', $nueva, null);
            if ($nueva !== $preparada || $saltar || $porDefecto) {
                // Un SET NEW la ha cambiado: otra vez tipos y NOT NULL
                if ($porDefecto) {
                    $nueva = WriterConflictos::nulosPorDefecto($meta, $nueva);
                }
                try {
                    $nueva = $w->prepararFila($tabla, $meta, $nueva, true);
                } catch (JsonSqlDbError $e) {
                    if (!$saltar || $e->sqlState !== 'CONSTRAINT') {
                        throw $e;
                    }
                    WriterInsercion::devolverRowid($w, $tabla, $meta, $nueva, $rowid);
                    return;                             // OR IGNORE: esta no entra
                }
            }
            if ($conflicto !== null) {
                // OR REPLACE la busca abajo, en cada clave única
                $choca = $conflicto['modo'] === 'reemplazar' ? null : WriterConflictos::chocaCon($w, $tabla, $meta, $nueva, $objetivo);
                if ($choca !== null && $conflicto['modo'] === 'nada') {
                    WriterInsercion::devolverRowid($w, $tabla, $meta, $nueva, $rowid);
                    return;                             // OR IGNORE, DO NOTHING
                }
                if ($choca !== null && $conflicto['modo'] === 'actualizar') {
                    WriterInsercion::devolverRowid($w, $tabla, $meta, $nueva, $rowid);
                    if (WriterConflictos::actualizarConflicto($w, $tabla, $meta, $choca, $nueva, $conflicto, $indices, $devolver)) {
                        $puestas++;
                    }
                    return;
                }
                if ($conflicto['modo'] === 'reemplazar') {
                    // Las filas con las que choca, en cualquier clave única, se
                    // borran. Aquí la cascada va antes, no como en un DELETE
                    // (ver WriterCambios::borrar()): la fila que entra suele
                    // traer la misma clave, y SQLite, que mira las claves
                    // foráneas al acabar la sentencia, acepta que lo que hagan
                    // los triggers de las hijas apunte a ella
                    $borradasPorReemplazo = WriterConflictos::quitarChoques($w, $tabla, $meta, null, null, $nueva, $indices) !== []
                        && isset($w->datos[$tabla]) || $borradasPorReemplazo;
                }
            }
            $w->comprobarUnicos($tabla, $meta, $nueva, $indices, null);
            $w->comprobarForaneas($tabla, $meta, $nueva);
            WriterInsercion::anadirAIndices($w, $meta, $nueva, $indices);

            WriterInsercion::anadirFila($w, $tabla, $nueva, $anexar);
            $puestas++;
            if ($conflicto !== null) {
                WriterConflictos::apuntarUnicas($w, $tabla, $meta, isset($w->datos[$tabla])
                    ? (int)array_key_last($w->datos[$tabla]) : -1 - (int)array_key_last($w->anexo[$tabla]), null, $nueva);
            }
            if ($devolver) {
                $w->devueltas[] = $nueva;
            }

            $w->lanzarTriggers($tabla, 'AFTER', 'INSERT', $nueva, null);
        };

        // Valores de origen: VALUES(...) o SELECT. Un SELECT de otras tablas va
        // por lotes según sale, sin juntar su resultado (2.9: un INSERT …
        // SELECT de un millón de filas lo tenía entero en memoria); si lee la
        // tabla en la que se inserta, entero antes, que no vea lo que se mete
        if ($ast['select'] !== null) {
            $tablas = Select::tablasDe($ast['select'], $w->cat);
            $enMemoria = $tablas === null || array_intersect_key(array_change_key_case($w->sucioDatos + $w->datos),
                array_change_key_case(array_flip($tablas))) !== [];
            if ($enMemoria || \in_array(strtolower($tabla), array_map('strtolower', $tablas), true)) {
                // Cada fila se suelta al meterla: con muchas, lo que lleva la
                // sentencia crece según el origen mengua (ver tramo())
                $origen = $w->seleccionar($ast['select']);
                foreach (array_keys($origen) as $k) {
                    $meter(array_values($origen[$k]));
                    unset($origen[$k]);
                }
            } else {
                // Por lotes de hasta 8.192 filas o $lote bytes: turnarse el
                // SELECT y la escritura en cada fila era un 10 % más lento que
                // la 2.8.0, que juntaba el resultado entero
                $lote    = [];
                $inicio  = memory_get_usage();
                $limite  = $w->lote;
                $soltar  = static function () use (&$lote, &$inicio, $meter): void {
                    foreach (array_keys($lote) as $k) {
                        $meter($lote[$k]);
                        unset($lote[$k]);               // cada fila se suelta al meterla
                    }
                    $lote   = [];
                    $inicio = memory_get_usage();
                };
                $porFila = static function (array $fila) use (&$lote, &$inicio, $limite, $soltar): void {
                    $lote[] = array_values($fila);
                    if (\count($lote) === 8192 || memory_get_usage() - $inicio > $limite) {
                        $soltar();
                    }
                };
                foreach ((new Select($w->cat))->ejecutarHacia($ast['select'], $porFila) ?? [] as $fila) {
                    $porFila($fila);
                }
                $soltar();
            }
        } else {
            // Todos los valores antes de meter ninguno: una subconsulta de la
            // tabla no ve las filas de esta misma sentencia
            $origen = [];
            $ctx = ['fila' => [], 'sub' => fn(array $s): array => $w->seleccionar($s)];
            foreach ($ast['filas'] as $fila) {
                $valores = [];
                foreach ($fila as $e) {
                    // Un literal, o un número negativo, es su valor: sin pasar
                    // por Evaluator, que ni se carga
                    $n = $e['k'] === 'un' && $e['op'] === '-' && $e['e']['k'] === 'lit' ? $e['e']['v'] : null;
                    $valores[] = match (true) {
                        $e['k'] === 'lit'           => $e['v'],
                        $e['k'] === 'default'       => ['__default__'],
                        \is_int($n) || \is_float($n)  => -$n,
                        default                     => Evaluator::evaluar(Evaluator::resolver($e, []), $ctx),
                    };
                }
                $origen[] = $valores;
            }
            foreach ($origen as $valores) {
                $meter($valores);
            }
        }

        if ($borradasPorReemplazo) {
            $w->porCompactar[$tabla] = true;  // al terminar la sentencia (ver WriterCambios::filaActual())
        }
        unset($w->unicas[$tabla], $w->defUnica[$tabla], $w->vacioUnica[$tabla]);
        $w->indicesVivos = $vivos;
        return $puestas;
    }

    /**
     * Añade una fila al final. Nada de lo anterior se mueve, así que solo
     * cambia la última parte. Si la tabla no está en memoria y se puede, la
     * fila se apunta aparte y la tabla no se lee.
     */
    private static function anadirFila(Writer $w, string $tabla, array $fila, bool $sinLeer): void
    {
        if ($sinLeer && !isset($w->datos[$tabla])) {
            $w->anexo[$tabla][] = $fila;
        } else {
            $w->filas($tabla);
            $w->marcarDesde($tabla, \count($w->datos[$tabla]));
            $w->datos[$tabla][] = $fila;
        }
        $w->sucioDatos[$tabla] = true;
        $w->olvidarPadre($tabla);
        if (isset($w->datos[$tabla])) {
            $w->apuntarHija($tabla, (int)array_key_last($w->datos[$tabla]), $fila);
        }
    }

    private static function anadirAIndices(Writer $w, array $meta, array $fila, array &$indices): void
    {
        foreach ($w->unicos($meta) as $uq) {
            $clave = self::claveDe($fila, $uq['columns'], $uq['plegar']);
            if ($clave !== null) {
                $indices[$uq['name']]['nuevas'][$clave] = true;
                unset($indices[$uq['name']]['quitadas'][$clave]);
            }
        }
    }

    /**
     * Una fila que no entra (OR IGNORE, DO NOTHING o la que cambia un DO
     * UPDATE) no gasta su número de una clave primaria entera sin
     * AUTOINCREMENT (2.10): la siguiente sigue siendo la mayor + 1, como en
     * SQLite. Con AUTOINCREMENT sí lo gasta, como en SQLite, si entra alguna
     * fila de la sentencia: si no entra ninguna, el contador no se escribe.
     * Solo si nadie ha pedido otro después (un trigger que inserta en la tabla).
     */
    private static function devolverRowid(Writer $w, string $tabla, array $meta, array $fila, ?int $antes): void
    {
        $col = $w->deMeta[$meta['table']]['auto'][1] ?? null;
        $v   = $col === null ? null : $fila[$col] ?? null;
        if (\is_int($v) && ($w->rowid[$tabla] ?? null) === $v + 1) {
            $w->rowid[$tabla] = $antes ?? $v;
        }
    }

    /**
     * El contador de autoincremento no ensucia la estructura: se lleva aparte
     * y al volcar va a rev.json (ver StorageEscritura::ponerAutoincremento()).
     */
    public static function siguienteAutoincremento(Writer $w, string $tabla): int
    {
        $n = $w->autoinc[$tabla] ?? (int)$w->meta($tabla)['autoincrement']['next'];
        $w->autoinc[$tabla] = $n + 1;
        return $n;
    }

    public static function ajustarAutoincremento(Writer $w, string $tabla, int $valor): void
    {
        $meta = $w->meta($tabla);
        if ($meta['autoincrement'] === null) {
            return;
        }
        $n = $w->autoinc[$tabla] ?? (int)$meta['autoincrement']['next'];
        if ($valor >= $n) {
            $w->autoinc[$tabla] = $valor + 1;
        }
    }

    /**
     * La siguiente de una clave primaria entera sin AUTOINCREMENT: la mayor
     * que hay —por su índice, sin leer la tabla ni sus filas: el mayor de los
     * rangos que guarda el estado, o sus claves si la sentencia ya ha quitado
     * o cambiado filas (2.10: leía las filas de las mayores claves); si no se
     * puede, recorriéndola—, con lo que esta sentencia lleva hecho, + 1. Como en
     * SQLite, puede volver a dar la de una fila borrada que era la mayor: para
     * no repetir nunca, AUTOINCREMENT. Sin cargar la tabla, las filas que la
     * sentencia ha quitado o cambiado no cuentan como están en el disco, sino
     * como están ahora (2.10: con OR REPLACE salía de una fila ya borrada).
     */
    public static function siguienteRowid(Writer $w, string $tabla, array $meta, string $col): int
    {
        if (!isset($w->rowid[$tabla])) {
            $mayor = 0;
            $hay   = static function ($v) use (&$mayor): void {
                if (\is_int($v) && $v > $mayor) {
                    $mayor = $v;
                }
            };
            if (isset($w->datos[$tabla])) {
                foreach ($w->datos[$tabla] as $f) {
                    $hay($f[$col] ?? null);
                }
            } else {
                $st      = $w->cat->storage();
                $def     = $w->indiceDe($tabla, $meta, [$col], true);   // el del disco: lo de la sentencia se mira aparte
                $tocadas = ($w->borradas[$tabla] ?? []) + ($w->cambios[$tabla] ?? []);
                $top     = $def === null ? null : StorageMayores::deIndice($st, $tabla, $def, 1 + \count($tocadas));
                if ($top === null) {
                    foreach ($st->filas($tabla) as $pos => $f) {
                        if (!isset($tocadas[$pos])) {
                            $hay($f[$col] ?? null);
                        }
                    }
                } else {
                    // Las del disco, de mayor a menor: la primera que sigue como estaba
                    foreach ($top as $v) {
                        if ($tocadas === [] || self::sigueEnDisco($w, $tabla, $def, $col, $v)) {
                            $hay($v);
                            break;
                        }
                    }
                }
                foreach (array_merge($w->cambios[$tabla] ?? [], $w->anexo[$tabla] ?? []) as $f) {
                    $hay($f[$col] ?? null);
                }
            }
            $w->rowid[$tabla] = $mayor + 1;
        }
        return $w->rowid[$tabla]++;
    }

    /** ¿La fila del disco con id $v sigue en su sitio, sin borrar ni cambiar en esta sentencia? */
    private static function sigueEnDisco(Writer $w, string $tabla, array $def, string $col, int $v): bool
    {
        foreach (StorageEscritura::posicionesDeClave($w->cat->storage(), $tabla, $def, (string)Indexes::clave([$v])) ?? [] as $p) {
            if (!isset($w->borradas[$tabla][$p]) && ($w->cambios[$tabla][$p][$col] ?? $v) === $v) {
                return true;
            }
        }
        return false;
    }
}
