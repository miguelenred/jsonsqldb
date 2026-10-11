<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las sentencias que no son INSERT, UPDATE ni DELETE: las que cambian la
 * estructura —CREATE TABLE (también AS SELECT), DROP TABLE, ALTER TABLE,
 * índices, vistas y triggers— y las de mantenimiento (REPAIR, VACUUM).
 * Métodos de Writer que reciben el ejecutor en $w. Van aparte para que una
 * escritura de filas no tenga que compilarlos (2.9): sin OPcache, PHP compila
 * en cada petición lo que carga. Hereda de Writer solo para llegar a lo
 * protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class WriterDdl extends Writer
{
    private function __construct()
    {
    }

    /** Las sentencias que no son INSERT, UPDATE ni DELETE (ver Writer::despachar()). */
    public static function despachar(Writer $w, array $ast): array
    {
        switch ($ast['k']) {
            case 'create_table':
                return self::crearTabla($w, $ast);
            case 'drop_table':
                return self::borrarTabla($w, $ast);
            case 'alter_table':
                return self::alterarTabla($w, $ast);
            case 'create_trigger':
                return self::crearTrigger($w, $ast);
            case 'drop_trigger':
                return self::borrarTrigger($w, $ast);

            case 'create_index':
                $creado = $w->cat->crearIndice(
                    $ast['tabla'], $ast['nombre'], $ast['columnas'], $ast['si_no_existe']
                );
                return ['filas' => 0, 'mensaje' => $creado
                    ? "Índice '{$ast['nombre']}' creado sobre '{$ast['tabla']}'"
                    : "El índice '{$ast['nombre']}' ya existía"];

            case 'drop_index':
                // Un CREATE UNIQUE INDEX es una restricción UNIQUE: se borra como tal
                foreach ($ast['tabla'] === null ? $w->cat->tablas() : [$ast['tabla']] as $t) {
                    foreach ($w->cat->meta($t)['unique'] as $uq) {
                        if (strcasecmp((string)($uq['name'] ?? ''), $ast['nombre']) === 0) {
                            $w->cat->borrarRestriccion($t, $ast['nombre']);
                            return ['filas' => 0, 'mensaje' => "Índice único '{$ast['nombre']}' borrado de '$t'"];
                        }
                    }
                }
                $tabla = $w->cat->borrarIndice($ast['tabla'], $ast['nombre'], $ast['si_existe']);
                return ['filas' => 0, 'mensaje' => $tabla !== null
                    ? "Índice '{$ast['nombre']}' borrado de '$tabla'"
                    : "El índice '{$ast['nombre']}' no existía"];

            case 'repair_keys':
                $problemas = (new Integrity($w->cat))->claves($ast['tabla'], true);
                $arreglados = 0;
                foreach ($problemas as $p) {
                    if ($p['accion'] === 'puesta a NULL') { $arreglados++; }
                }
                $quedan = count($problemas) - $arreglados;
                return ['filas' => $arreglados, 'mensaje' =>
                    $problemas === []
                        ? 'Las claves foráneas están bien: no hay filas huérfanas'
                        : "$arreglados fila(s) corregida(s)" .
                          ($quedan > 0 ? ", $quedan sin corregir (la columna no admite NULL)" : '')];

            case 'vacuum':
                // Las tablas con huecos de filas borradas se reescriben sin
                // ellos, y las de índices sin filtro (de la 2.8 o antes) rehacen
                // sus índices (2.9); las demás no se tocan
                $st      = $w->cat->storage();
                $tablas  = 0;
                $huecos  = 0;
                $indices = 0;
                foreach ($w->cat->tablas() as $t) {
                    $n    = StorageMantenimiento::huecos($st, $t);
                    $defs = Indexes::definiciones($w->cat->meta($t));
                    if ($n > 0) {
                        StorageMantenimiento::compactar($st, $t, $defs);
                        $tablas++;
                        $huecos += $n;
                    } elseif (StorageMantenimiento::rehacerIndicesSinFiltro($st, $t, $defs)) {
                        $indices++;
                    }
                }
                $hecho = array_filter([
                    $tablas === 0 ? '' : "$huecos hueco(s) de filas borradas quitados en $tablas tabla(s)",
                    $indices === 0 ? '' : "índices de $indices tabla(s) rehechos con el filtro de la 2.9",
                ]);
                return ['filas' => $huecos, 'mensaje' => $hecho === [] ? 'VACUUM: no había huecos que quitar ni índices que rehacer'
                    : 'VACUUM: ' . implode('; ', $hecho)];
            case 'repair_tables':
                if (isset($ast['olvidar'])) {
                    $p = StorageMantenimiento::olvidarApartada($w->cat->storage(), $ast['olvidar']);
                    return ['filas' => 1, 'mensaje' => "Fila apartada {$p['id']} de '{$p['tabla']}' olvidada: sigue en la copia de .reparados/"];
                }
                $problemas = (new Integrity($w->cat))->ficheros($ast['tabla'], true);
                $tablas    = count(array_unique(array_column($problemas, 'tabla')));
                // Las filas apartadas, con su fichero y su línea: para saber qué
                // volver a meter, corregido, sin tener que buscarlo en la copia
                $apartadas = [];
                foreach ($problemas as $p) {
                    if ($p['arreglo'] === Storage::APARTADA) {
                        $apartadas[] = ['id' => $p['apartada'], 'tabla' => $p['tabla'], 'fichero' => $p['fichero'], 'fila' => $p['problema']];
                    }
                }
                return ['filas' => count($problemas), 'apartadas' => $apartadas, 'mensaje' => $problemas === []
                    ? 'Los ficheros de las tablas están bien: no hay nada que reparar'
                    : count($problemas) . " problema(s) reparado(s) en $tablas tabla(s)"
                      . ($apartadas === [] ? '' : ', ' . count($apartadas) . ' fila(s) apartada(s)')
                      . '; los originales están en .reparados/'];

            case 'create_view':
                $creada = $w->cat->crearVista($ast['nombre'], $ast['sql'], $ast['si_no_existe']);
                return ['filas' => 0, 'mensaje' => $creada
                    ? "Vista '{$ast['nombre']}' creada"
                    : "La vista '{$ast['nombre']}' ya existía"];

            case 'drop_view':
                $borrada = $w->cat->borrarVista($ast['nombre'], $ast['si_existe']);
                return ['filas' => 0, 'mensaje' => $borrada
                    ? "Vista '{$ast['nombre']}' borrada"
                    : "La vista '{$ast['nombre']}' no existía"];
        }
        throw JsonSqlDbError::syntax("Sentencia no ejecutable: {$ast['k']}");
    }

    // ==================================================================
    // DDL
    // ==================================================================

    public static function crearTabla(Writer $w, array $ast): array
    {
        $tabla = $ast['tabla'];
        if ($w->cat->existe($tabla)) {
            if ($ast['si_no_existe']) {
                return ['filas' => 0, 'mensaje' => "La tabla '$tabla' ya existía"];
            }
            throw JsonSqlDbError::schema("La tabla '$tabla' ya existe");
        }
        $w->cat->exigirNombreLibreDeVista($tabla);

        if (isset($ast['como'])) {
            // Como MySQL: columnas, clave primaria, claves únicas, índices y
            // CHECK (2.9: se perdían); ni claves foráneas, ni triggers, ni
            // filas, y el autoincremento desde 1
            $origen = $w->cat->meta((string)$ast['como']);
            $def = ['columns' => $origen['columns'], 'unique' => $origen['unique'], 'foreign_keys' => [],
                    'indexes' => $origen['indexes'], 'checks' => $origen['checks'] ?? []];
            $w->cat->crearTabla($tabla, $def);
            return ['filas' => 0, 'mensaje' => "Tabla '$tabla' creada como '{$ast['como']}'"];
        }
        if ($ast['def'] === null) {
            return self::crearTablaDeConsulta($w, $tabla, $ast['select']);
        }
        $def = $ast['def'];

        // PRIMARY KEY declarada a nivel de tabla
        foreach ($def['pk'] ?? [] as $nombre) {
            $encontrada = false;
            foreach ($def['columns'] as $i => $c) {
                if (strcasecmp($c['name'], $nombre) === 0) {
                    $def['columns'][$i]['pk'] = true;
                    $encontrada = true;
                }
            }
            if (!$encontrada) {
                throw JsonSqlDbError::schema("PRIMARY KEY sobre columna inexistente '$nombre'");
            }
        }
        unset($def['pk']);

        // REFERENCES escrito dentro de una columna
        foreach ($def['columns'] as $i => $c) {
            if (isset($c['references'])) {
                $def['foreign_keys'][] = $c['references'];
                unset($def['columns'][$i]['references']);
            }
        }
        $def['columns'] = array_values($def['columns']);
        $indices = $def['indices'] ?? [];
        unset($def['indices']);

        // Los KEY / INDEX declarados con la tabla (MySQL), con el nombre de su
        // primera columna si no lo traen, como MySQL
        $usados = [];
        foreach ($indices as $ix) {
            $nombre = $ix['name'];
            if ($nombre === null) {
                $base = (string)preg_replace('/[^A-Za-z0-9_]/', '_', (string)$ix['columns'][0]);
                for ($nombre = $base, $n = 2; isset($usados[strtolower($nombre)]); $n++) {
                    $nombre = $base . '_' . $n;
                }
            }
            $usados[strtolower($nombre)] = true;
            $def['indexes'][] = ['name' => $nombre, 'columns' => $ix['columns']];
        }
        $w->cat->crearTabla($tabla, $def);
        return ['filas' => 0, 'mensaje' => "Tabla '$tabla' creada"];
    }

    /**
     * CREATE TABLE t AS SELECT: las columnas son las del resultado, sin claves,
     * NOT NULL ni valores por defecto, como en SQLite. Una columna que sale tal
     * cual de una tabla conserva su tipo; las demás lo toman de sus valores
     * (enteros, números o texto; TEXT si son todos NULL o se mezclan).
     *
     * El resultado no se junta (2.9: entero en memoria): los nombres salen de
     * la consulta sin filas, los tipos que hagan falta de una lectura de los
     * valores, y las filas se escriben por tandas según salen. Cada tanda se
     * confirma, así que va dentro de una transacción (ver Database::consultar()),
     * que la deja ser todo o nada.
     */
    private static function crearTablaDeConsulta(Writer $w, string $tabla, array $select): array
    {
        $vacia = $select;
        $vacia['limit']  = 0;
        $vacia['offset'] = null;
        $cols  = (new Select($w->cat))->ejecutarConColumnas($vacia)['cols'];
        $tipos = self::tiposDeOrigen($w, $select, count($cols));
        if (count($tipos) < count($cols)) {
            $vistos = array_fill(0, count($cols), null);
            $anotar = static function (array $fila) use (&$vistos): void {
                foreach (array_values($fila) as $i => $v) {
                    $vistos[$i] = self::tipoMas($vistos[$i], $v);
                }
            };
            foreach ((new Select($w->cat))->ejecutarHacia($select, $anotar) ?? [] as $fila) {
                $anotar($fila);
            }
        }
        $def = ['columns' => [], 'unique' => [], 'foreign_keys' => []];
        foreach ($cols as $i => $nombre) {
            $def['columns'][] = ['name' => (string)$nombre, 'notnull' => false, 'default' => null, 'pk' => false,
                                 'autoincrement' => false, 'unique' => false]
                              + ($tipos[$i] ?? ['type' => $vistos[$i] ?? Types::TEXT, 'length' => null, 'scale' => null]);
        }
        $w->cat->crearTabla($tabla, $def);

        $st       = $w->cat->storage();
        // Las que toman el tipo de sus valores, por posición: cada valor que
        // no es ya de su tipo se convierte (2.9: iba tal cual, y en una TEXT
        // de valores mezclados quedaban números, y en una DOUBLE, enteros).
        // Las que salen tal cual de una tabla ya lo tienen
        $castear  = [];
        foreach (array_diff_key($def['columns'], $tipos) as $i => $c) {
            $castear[$i] = [Catalog::normalizarColumna($c), [Types::INTEGER => 'integer', Types::DOUBLE => 'double'][$c['type']] ?? 'string'];
        }
        $claves   = null;                  // las de las filas, las mismas en todas
        $tanda    = [];
        $n        = 0;
        $limite   = Memoria::limiteEnBytes();
        $tope     = $limite > 0 && StorageEscritura::enLote($st) ? intdiv($limite, 4) : PHP_INT_MAX;
        $escribir = static function () use ($st, $tabla, &$tanda): void {
            StorageEscritura::txConfirmar($st);
            StorageEscritura::txIniciar($st, 'CREATE TABLE');
            StorageEscritura::anadirFilas($st, $tabla, $tanda);
            $tanda = [];
        };
        $guardar = static function (array $fila) use (&$tanda, &$n, $tope, $escribir, $castear, &$claves): void {
            if ($castear !== []) {
                $claves ??= array_keys($fila);
                foreach ($castear as $i => [$col, $php]) {
                    $v = $fila[$claves[$i]];
                    if ($v !== null && gettype($v) !== $php) {
                        $fila[$claves[$i]] = Types::cast($v, $col);
                    }
                }
            }
            $tanda[] = $fila;
            if ((++$n & 255) === 0 && memory_get_usage() > $tope) {
                $escribir();
            }
        };
        foreach ((new Select($w->cat))->ejecutarHacia($select, $guardar) ?? [] as $fila) {
            $guardar($fila);
        }
        if ($tanda !== []) {
            $escribir();
        }
        return ['filas' => $n, 'mensaje' => "Tabla '$tabla' creada con $n fila(s)"];
    }

    /**
     * Tipo de cada columna de salida que es una columna de una tabla del FROM,
     * por posición; null si la consulta no es un SELECT sencillo sobre tablas.
     *
     * @return array<int,array{type:string,length:?int,scale:?int}>
     */
    private static function tiposDeOrigen(Writer $w, array $select, int $cuantas): array
    {
        if ($select['k'] !== 'select' || isset($select['with'])) {
            return [];
        }
        $porAlias = [];
        foreach ($select['from'] ?? [] as $o) {
            $nombre = $o['nombre'] ?? null;
            $porAlias[strtolower((string)($o['alias'] ?? $nombre))] = ($o['tipo'] ?? '') === 'tabla' && is_string($nombre)
                && $w->cat->existe($nombre) && !$w->cat->esVista($nombre) ? $w->meta($nombre) : null;
        }
        $tipo = static fn(array $c): array => ['type' => $c['type'], 'length' => $c['length'] ?? null, 'scale' => $c['scale'] ?? null];
        $tipos = [];
        foreach ($select['cols'] as $c) {
            if (!empty($c['star'])) {
                foreach ($porAlias as $alias => $meta) {
                    if ($c['tabla'] !== null && strcasecmp($alias, $c['tabla']) !== 0) {
                        continue;
                    }
                    if ($meta === null) {
                        return [];                     // un * sobre una subconsulta o vista: no se sabe cuántas
                    }
                    foreach ($meta['columns'] as $col) {
                        $tipos[] = $tipo($col);
                    }
                }
                continue;
            }
            $e = $c['expr'];
            $col = null;
            if ($e['k'] === 'col') {
                foreach ($porAlias as $alias => $meta) {
                    if ($meta === null || ($e['tabla'] !== null && strcasecmp($alias, $e['tabla']) !== 0)) {
                        continue;
                    }
                    $hallada = Catalog::columna($meta, $e['nombre']);
                    if ($hallada !== null) {
                        if ($col !== null) {
                            $col = null;               // ambigua: que decidan los valores
                            break;
                        }
                        $col = $hallada;
                    }
                }
            }
            $tipos[] = $col === null ? null : $tipo($col);
        }
        return count($tipos) === $cuantas ? array_filter($tipos) : [];
    }

    /** El tipo de una columna de valores tras ver uno más: enteros, números o texto; TEXT si se mezclan. */
    private static function tipoMas(?string $tipo, $v): ?string
    {
        $t = $v === null ? null : (is_int($v) || is_bool($v) ? Types::INTEGER : (is_float($v) ? Types::DOUBLE : Types::TEXT));
        if ($t === null || $t === $tipo || $tipo === Types::TEXT) {
            return $tipo;
        }
        if ($tipo === null) {
            return $t;
        }
        return $t !== Types::TEXT ? Types::DOUBLE : Types::TEXT;     // enteros y decimales; con texto, texto
    }

    public static function borrarTabla(Writer $w, array $ast): array
    {
        $tabla = $ast['tabla'];
        if (!$w->cat->existe($tabla)) {
            if ($ast['si_existe']) {
                return ['filas' => 0, 'mensaje' => "La tabla '$tabla' no existía"];
            }
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        $w->cat->borrarTabla($tabla);
        unset($w->datos[$tabla], $w->metas[$tabla]);
        return ['filas' => 0, 'mensaje' => "Tabla '$tabla' eliminada"];
    }

    public static function alterarTabla(Writer $w, array $ast): array
    {
        $tabla = $ast['tabla'];
        switch ($ast['accion']) {
            case 'add':
                $w->cat->anadirColumna($tabla, $ast['def']);
                $mensaje = "Columna '{$ast['def']['name']}' añadida a '$tabla'";
                break;
            case 'drop':
                $w->cat->borrarColumna($tabla, $ast['col']);
                $mensaje = "Columna '{$ast['col']}' eliminada de '$tabla'";
                break;
            case 'rename':
                $w->cat->renombrarTabla($tabla, $ast['nuevo']);
                $mensaje = "Tabla '$tabla' renombrada a '{$ast['nuevo']}'";
                break;
            case 'add_constraint':
                $nombres = [];
                if (!empty($ast['si_no_existe'])) {
                    foreach ($w->cat->meta($tabla)['unique'] as $uq) {
                        if (strcasecmp((string)($uq['name'] ?? ''), (string)$ast['unique'][0]['name']) === 0) {
                            return ['filas' => 0, 'mensaje' => "El índice '{$ast['unique'][0]['name']}' ya existía"];
                        }
                    }
                }
                foreach ($ast['unique'] as $uq) {
                    $nombres[] = $w->cat->anadirUnico($tabla, $uq);
                }
                foreach ($ast['foreign_keys'] as $fk) {
                    $nombres[] = $w->cat->anadirFk($tabla, $fk);
                }
                foreach ($ast['checks'] ?? [] as $ck) {
                    $nombres[] = $w->cat->anadirCheck($tabla, $ck);
                }
                $mensaje = "Restricción '" . implode("', '", $nombres) . "' añadida a '$tabla'";
                break;
            case 'modify':
                $w->cat->modificarColumna($tabla, $ast['def']);
                $mensaje = "Columna '{$ast['def']['name']}' modificada en '$tabla'";
                break;
            case 'change':
                $w->cat->modificarColumna($tabla, $ast['def'], $ast['col']);
                $mensaje = "Columna '{$ast['col']}' cambiada a '{$ast['def']['name']}' en '$tabla'";
                break;
            case 'add_pk':
                $w->cat->anadirClavePrimaria($tabla, $ast['columnas']);
                $mensaje = "Clave primaria de '$tabla' creada sobre (" . implode(', ', $ast['columnas']) . ')';
                break;
            case 'drop_pk':
                $w->cat->borrarClavePrimaria($tabla);
                $mensaje = "Clave primaria de '$tabla' eliminada";
                break;
            case 'drop_constraint':
                $w->cat->borrarRestriccion($tabla, $ast['nombre']);
                $mensaje = "Restricción '{$ast['nombre']}' eliminada de '$tabla'";
                break;
            default:
                $w->cat->renombrarColumna($tabla, $ast['col'], $ast['nuevo']);
                $mensaje = "Columna '{$ast['col']}' renombrada a '{$ast['nuevo']}'";
        }
        unset($w->datos[$tabla], $w->metas[$tabla]);
        return ['filas' => 0, 'mensaje' => $mensaje];
    }

    public static function crearTrigger(Writer $w, array $ast): array
    {
        $nombre = $ast['trg']['name'];
        foreach ($w->cat->tablas() as $t) {
            foreach ($w->cat->meta($t)['triggers'] as $trg) {
                if (strcasecmp($trg['name'], $nombre) === 0) {
                    if ($ast['si_no_existe']) {
                        return ['filas' => 0, 'mensaje' => "El trigger '$nombre' ya existía"];
                    }
                    throw JsonSqlDbError::schema("El trigger '$nombre' ya existe");
                }
            }
        }

        // Comprobar que el cuerpo es analizable antes de guardarlo, y que un
        // SET NEW, NEW y OLD están donde pueden estar
        if ($ast['trg']['when'] !== null) {
            self::exigirFila($ast['trg'], WriterTriggers::analizarExpr($w, $ast['trg']['when']));
        }
        self::comprobarCuerpo($w, $ast['trg'], $ast['trg']['body']);
        $w->cat->crearTrigger($ast['tabla'], $ast['trg']);
        unset($w->metas[$ast['tabla']]);
        return ['filas' => 0, 'mensaje' => "Trigger '$nombre' creado"];
    }

    /** @param list<string|array> $cuerpo */
    private static function comprobarCuerpo(Writer $w, array $trg, array $cuerpo): void
    {
        foreach ($cuerpo as $paso) {
            if (is_array($paso)) {
                foreach ($paso['si'] as [$cond, $sentencias]) {
                    self::exigirFila($trg, WriterTriggers::analizarExpr($w, $cond));
                    self::comprobarCuerpo($w, $trg, $sentencias);
                }
                self::comprobarCuerpo($w, $trg, $paso['sino'] ?? []);
            } elseif (strncasecmp(ltrim($paso), 'SET', 3) === 0) {
                if ($trg['timing'] !== 'BEFORE' || $trg['event'] === 'DELETE') {
                    throw JsonSqlDbError::syntax("SET NEW solo vale en un trigger BEFORE INSERT o BEFORE UPDATE ('{$trg['name']}')");
                }
                self::exigirFila($trg, ParserEscritura::analizarSetNew($paso));
            } else {
                $ast = Parser::analizar($paso);
                // Lo que no podría ejecutar (WriterTriggers::ejecutarSinVolcar()) se rechaza al crearlo (2.9)
                if (!in_array($ast['k'], ['insert', 'update', 'delete', 'select', 'union'], true)) {
                    throw JsonSqlDbError::syntax("El trigger '{$trg['name']}' solo puede hacer INSERT, UPDATE, DELETE o SELECT");
                }
                self::exigirFila($trg, $ast);
            }
        }
    }

    /**
     * Un trigger DELETE no tiene NEW, ni uno INSERT, OLD: usarlos es un error
     * al crearlo, como en MySQL (2.9). Antes se aceptaba, y el error salía en
     * cada escritura de la tabla.
     */
    private static function exigirFila(array $trg, array $n): void
    {
        if (($n['k'] ?? null) === 'col' && is_string($n['tabla'] ?? null)) {
            $cual = strtoupper($n['tabla']);
            if (($cual === 'NEW' && $trg['event'] === 'DELETE') || ($cual === 'OLD' && $trg['event'] === 'INSERT')) {
                throw JsonSqlDbError::syntax("$cual no está disponible en un trigger {$trg['event']} ('{$trg['name']}')");
            }
        }
        foreach ($n as $hijo) {
            if (is_array($hijo)) {
                self::exigirFila($trg, $hijo);
            }
        }
    }

    public static function borrarTrigger(Writer $w, array $ast): array
    {
        $nombre = $ast['nombre'];
        try {
            $tabla = $w->cat->borrarTrigger($nombre);
        } catch (JsonSqlDbError $e) {
            if ($ast['si_existe'] && $e->sqlState === 'SCHEMA') {
                return ['filas' => 0, 'mensaje' => "El trigger '$nombre' no existía"];
            }
            throw $e;
        }
        unset($w->metas[$tabla]);
        return ['filas' => 0, 'mensaje' => "Trigger '$nombre' eliminado"];
    }
}
