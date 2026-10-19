<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las claves foráneas: que exista la fila padre, y las acciones ON DELETE y
 * ON UPDATE sobre las filas hijas. Métodos de Writer que reciben el ejecutor
 * en $w. Van aparte para que una escritura sin claves foráneas no tenga que
 * compilarlos (2.9): sin OPcache, PHP compila en cada petición lo que carga.
 * Hereda de Writer solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class WriterForaneas extends Writer
{
    private function __construct()
    {
    }

    /**
     * Una clave foránea que no se cumple a mitad de sentencia se mira otra vez
     * al acabarla, como en SQLite (2.10): la de una fila hija sin padre
     * ($error, el mensaje si sigue sin él) y la NO ACTION de un padre que se
     * va con $hijas filas que lo apuntan. Mientras ninguna fila de la tabla
     * hija con esa clave salga ni cambie de clave (ver hijaQuitada()), las de
     * entonces siguen ahí y no hay que buscarlas; si alguna lo hace, se buscan
     * al acabar. Por clave foránea: dos de una tabla pueden llamarse igual
     */
    public static function apuntar(Writer $w, string $hija, array $fk, string $clave, array $plegar, array $afin, ?int $hijas, ?string $error, bool $buscar = false): void
    {
        $p = &$w->foraneasPendientes[$hija][$fk['table'] . "\0" . implode("\0", $fk['references']) . "\0" . implode("\0", $fk['columns'])];
        $p ??= ['fk' => $fk, 'plegar' => $plegar, 'afin' => $afin, 'claves' => []];
        $p['claves'][$clave] ??= ['hijas' => $hijas, 'error' => $error, 'buscar' => $buscar];
        if ($buscar) {
            $p['claves'][$clave]['buscar'] = true;
        }
    }

    /**
     * Una fila hija sin su padre (ver Writer::comprobarForaneas()): se mira
     * otra vez al acabar la sentencia, con el error que se dará si sigue sin
     * él (2.10: el mensaje, aquí, para que Writer no lo compile)
     */
    public static function sinPadre(Writer $w, string $tabla, array $fk, array $fila, string $clave, array $plegar, array $afin): void
    {
        $valores = [];
        foreach ($fk['columns'] as $c) {
            $valores[] = Valor::aTexto($fila[$c]);
        }
        self::apuntar($w, $tabla, $fk, $clave, $plegar, $afin, null, "'$tabla." . implode(', ', $fk['columns'])
            . "' = (" . implode(', ', $valores) . ") no existe en '{$fk['table']}' (clave foránea '{$fk['name']}')");
    }

    /**
     * La fila que quita un OR REPLACE, después de sus cascadas, que van antes
     * (ver WriterInsercion::insertar()): lo que estas o sus triggers hayan
     * hecho apuntar a ella se busca al acabar la sentencia (2.10: pasaba la
     * comprobación con la fila aún ahí y quedaba huérfano, desde antes de la
     * 2.9).
     */
    public static function padreQuitado(Writer $w, string $tabla, array $meta, array $fila): void
    {
        foreach ($w->deMeta[$meta['table']]['hijas'] ??= $w->clavesHacia($tabla) as [$hija, $fk]) {
            $plegar = $w->plegarDelPadre($fk);
            $clave  = self::claveDe($fila, $fk['references'], $plegar);
            if ($clave !== null) {
                self::apuntar($w, $hija, $fk, $clave, $plegar, $w->afinDelPadre($fk, $w->meta($hija)), 0, null, true);
            }
        }
    }

    /** Una fila de $tabla sale o cambia de clave: las hijas con la que tenía se buscarán al acabar */
    public static function hijaQuitada(Writer $w, string $tabla, array $vieja, ?array $nueva): void
    {
        foreach ($w->foraneasPendientes[$tabla] as $id => $p) {
            $clave = $vieja === [] ? null : self::claveDe($vieja, $p['fk']['columns'], $p['plegar'], $p['afin']);
            if ($clave !== null && isset($p['claves'][$clave])
                && ($nueva === null || self::claveDe($nueva, $p['fk']['columns'], $p['plegar'], $p['afin']) !== $clave)) {
                $w->foraneasPendientes[$tabla][$id]['claves'][$clave]['buscar'] = true;
            }
        }
    }

    /** Al acabar la sentencia: error si queda alguna hija apuntando a un padre que no está */
    public static function alAcabar(Writer $w): void
    {
        foreach ($w->foraneasPendientes as $hija => $porClave) {
            $hija = (string)$hija;
            foreach ($porClave as $p) {
                foreach ($p['claves'] as $clave => $c) {
                    $clave = (string)$clave;
                    if (self::padreTiene($w, $p['fk']['table'], $p['fk']['references'], $clave, $p['plegar'])) {
                        continue;
                    }
                    if ($c['buscar']) {
                        $sinCargar = $p['afin'] !== [] ? null : self::hijasSinCargar($w, $hija, $w->meta($hija), $p['fk']['columns'], $clave, $p['plegar']);
                        $hijas     = $sinCargar ?? self::hijasDe($w, $hija, $p['fk']['columns'], $clave, $p['plegar'], $p['afin']);
                        if ($hijas === []) {
                            continue;
                        }
                        $c['hijas'] = $c['hijas'] === null ? null : \count($hijas);
                    }
                    throw $c['error'] !== null ? JsonSqlDbError::constraint($c['error']) : self::conHijas($hija, $p['fk'], (int)$c['hijas']);
                }
            }
        }
    }

    public static function conHijas(string $hija, array $fk, int $n): JsonSqlDbError
    {
        return JsonSqlDbError::constraint("'$hija' tiene $n fila(s) que dependen de esta (clave foránea '{$fk['name']}')");
    }

    /**
     * ¿Existe esta clave en una tabla padre? Contra su índice de disco si lo
     * hay; si no, sus claves se recogen una vez recorriéndola. Si la sentencia
     * ya la ha cambiado, por el mapa de hijasDe(), que se mantiene al escribir:
     * las claves recogidas se tiran en cada escritura de la tabla, y con las
     * filas de una cascada, o de sus triggers, que se comprueban contra un
     * padre que se está cambiando fila a fila (2.9), se recogerían otra vez
     * en cada una.
     */
    protected static function padreTiene(Writer $w, string $tabla, array $cols, string $clave, array $plegar): bool
    {
        $id = $tabla . '|' . implode("\0", $cols);
        if (!isset($w->idxPadre[$id])) {
            // Sin cargarla, por su índice también si la sentencia ya la ha
            // cambiado: lo cambiado se mira abajo (2.10: se cargaba entera, y
            // con una tabla grande se acababa la memoria)
            $def = $w->indiceDe($tabla, $w->meta($tabla), $cols, !isset($w->datos[$tabla]));
            if ($def !== null) {
                $w->idxPadre[$id] = $def;
            } elseif (isset($w->sucioDatos[$tabla])) {
                return self::hijasDe($w, $tabla, $cols, $clave, $plegar) !== [];
            } else {
                $idx = [];
                foreach ($w->filas($tabla) as $fila) {
                    $k = self::claveDe($fila, $cols, $plegar);
                    if ($k !== null) {
                        $idx[$k] = true;
                    }
                }
                $w->idxPadre[$id] = $idx;
            }
        }
        $idx = $w->idxPadre[$id];
        if (!isset($idx['name'])) {
            return isset($idx[$clave]);
        }
        $st = $w->cat->storage();
        if (!isset($w->sucioDatos[$tabla])) {
            return StorageEscritura::claveEnIndice($st, $tabla, $idx, $clave) === true;
        }
        // La sentencia ha cambiado la tabla sin cargarla después de recordar
        // su índice, que no lo sabe (2.9: se le creía, y una fila borrada en
        // la sentencia seguía valiendo como padre): sus filas con la clave,
        // como están ahora, y si no, las que la han tomado en la sentencia
        $posiciones = StorageEscritura::posicionesDeClave($st, $tabla, $idx, $clave);
        if ($posiciones === null) {
            unset($w->idxPadre[$id]);         // el índice ya no sirve: con la tabla
            return self::hijasDe($w, $tabla, $cols, $clave, $plegar) !== [];
        }
        foreach ($posiciones as $p) {
            if (!isset($w->borradas[$tabla][$p])
                && (!isset($w->cambios[$tabla][$p]) || self::claveDe($w->cambios[$tabla][$p], $cols, $plegar) === $clave)) {
                return true;
            }
        }
        // Las que han tomado la clave en la sentencia, por un mapa que se
        // mantiene al escribir (2.10: se recorrían todas en cada pregunta)
        if (isset($w->cambios[$tabla]) && self::cambiadasCon($w, $tabla, $cols, $clave, $plegar) !== []) {
            return true;
        }
        foreach ($w->anexo[$tabla] ?? [] as $f) {
            if (self::claveDe($f, $cols, $plegar) === $clave) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lado hijo de una clave foránea: las filas de $hija cuyas columnas $cols
     * valen $clave (con la afinidad del padre, $afin, si es otra: 2.10). El mapa clave → posiciones se arma una vez y se va
     * manteniendo al escribir, así que borrar o cambiar N padres ya no recorre
     * la tabla hija N veces (era cuadrático: 1.000 padres con 25.000 hijos,
     * 11 s). Una posición que se ha vaciado o cuya clave ha cambiado se salta.
     *
     * @return array<int,array> posición → fila, en orden de posición
     */
    public static function hijasDe(Writer $w, string $hija, array $cols, string $clave, array $plegar, array $afin = []): array
    {
        $w->filas($hija);                      // cargada: si se acaba de leer, sin mapas viejos
        $id = $hija . '|' . implode("\0", $cols) . '|' . implode(',', $plegar) . '|' . implode(',', $afin);
        // Con un solo padre (borrar o cambiar una fila), recorrer la tabla una
        // vez cuesta lo mismo que armar el mapa y no ocupa memoria: el mapa se
        // arma a partir del segundo padre de la sentencia
        if (!isset($w->idxHijo[$id]) && ($w->pedidasHijo[$id] = ($w->pedidasHijo[$id] ?? 0) + 1) === 1) {
            $out = [];
            foreach ($w->datos[$hija] as $pos => $f) {
                if (self::claveDe($f, $cols, $plegar, $afin) === $clave) {
                    $out[$pos] = $f;
                }
            }
            return $out;
        }
        if (!isset($w->idxHijo[$id])) {
            $mapa = [];
            foreach ($w->filas($hija) as $pos => $f) {
                $k = self::claveDe($f, $cols, $plegar, $afin);
                if ($k !== null) {
                    $mapa[$k][$pos] = true;
                }
            }
            $w->idxHijo[$id] = ['cols' => $cols, 'plegar' => $plegar, 'afin' => $afin, 'mapa' => $mapa];
        }
        $out = [];
        foreach (array_keys($w->idxHijo[$id]['mapa'][$clave] ?? []) as $pos) {
            $f = $w->datos[$hija][$pos] ?? null;
            if ($f !== null && self::claveDe($f, $cols, $plegar, $afin) === $clave) {
                $out[$pos] = $f;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Las filas hijas de una clave, leídas por un índice sobre las columnas de
     * la clave foránea y sin cargar la tabla hija: borrar un cliente ya no
     * lee la tabla de pedidos entera para encontrar los suyos. Solo si la
     * tabla hija está tal cual en disco y se puede escribir sin cargarla (sin
     * triggers ni cambios pendientes: ver sinLeer()); si no, null, y se busca
     * como siempre. La tabla no crea sola ese índice, como en SQLite: hay que
     * crearlo (CREATE INDEX … ON pedidos (cliente_id)).
     *
     * @return array<int,array>|null posición => fila
     */
    public static function hijasSinCargar(Writer $w, string $hija, array $metaHija, array $cols, string $clave, array $plegar): ?array
    {
        if (isset($w->datos[$hija]) || isset($w->anexo[$hija]) || !$w->sinLeer($hija, $metaHija, true)) {
            return null;
        }
        // Aunque esta sentencia ya haya cambiado o borrado hijas sin cargarla
        // (2.9: entonces se cargaba entera, y cien padres con ocho mil hijas no
        // cabían en 24 MB): el índice del disco da las que tenían la clave, y
        // cada una se mira como está ahora; las que la han tomado en esta
        // sentencia, aparte (ver cambiadasCon())
        $def = $w->indiceDe($hija, $metaHija, $cols, true);
        // Su índice tiene que plegar como el padre (2.9): si no, sus claves no son estas
        if ($def === null || ($def['plegar'] ?? []) !== $plegar) {
            return null;
        }
        $st         = $w->cat->storage();
        $posiciones = $st->posicionesPorIndice($hija, $def, [$clave], false);
        if ($posiciones === null) {
            return null;
        }
        $out = [];
        foreach (StorageCambios::filasEnPosiciones($st, $hija, $posiciones) as $pos => $fila) {
            $fila = $w->cambios[$hija][$pos] ?? $fila;
            if (!isset($w->borradas[$hija][$pos]) && self::claveDe($fila, $cols, $plegar) === $clave) {
                $out[$pos] = $fila;
            }
        }
        if (isset($w->cambios[$hija])) {
            $out += self::cambiadasCon($w, $hija, $cols, $clave, $plegar);
            ksort($out);
        }
        return $out;
    }

    /**
     * Las filas que la sentencia ha cambiado sin cargar la tabla y que tienen
     * ahora esa clave, de una hija o de un padre: el índice del disco no las
     * conoce (2.10: no se veían, y una cascada que daba la clave a una hija la
     * dejaba luego sin padre). Por un mapa de clave a posiciones, armado la
     * primera vez y puesto al día solo con las que han cambiado desde entonces.
     *
     * @return array<int, array> posición => fila
     */
    private static function cambiadasCon(Writer $w, string $tabla, array $cols, string $clave, array $plegar): array
    {
        $id    = implode("\0", $cols) . "\0" . implode("\0", $plegar);
        $nuevo = !isset($w->idxCambios[$tabla][$id]);
        if ($nuevo) {
            $w->idxCambios[$tabla][$id] = ['cols' => $cols, 'plegar' => $plegar, 'mapa' => []];
        }
        foreach ($w->idxCambios[$tabla] as $otro => $i) {
            foreach (array_keys($otro === $id && $nuevo ? $w->cambios[$tabla] : $w->cambiosSinMirar[$tabla] ?? []) as $pos) {
                $k = self::claveDe($w->cambios[$tabla][$pos] ?? [], $i['cols'], $i['plegar']);
                if ($k !== null) {
                    $w->idxCambios[$tabla][$otro]['mapa'][$k][$pos] = true;
                }
            }
        }
        unset($w->cambiosSinMirar[$tabla]);
        $out = [];
        foreach (array_keys($w->idxCambios[$tabla][$id]['mapa'][$clave] ?? []) as $pos) {
            $fila = $w->cambios[$tabla][$pos] ?? null;
            if ($fila !== null && !isset($w->borradas[$tabla][$pos]) && self::claveDe($fila, $cols, $plegar) === $clave) {
                $out[$pos] = $fila;
            }
        }
        return $out;
    }

    /**
     * Borra en cascada filas hijas leídas sin cargar su tabla: se apuntan como
     * borradas, igual que un DELETE que no la carga, y se propaga a sus
     * propias hijas, después, como en borrar(). Sin triggers que lanzar:
     * sinLeer() exige que no los haya.
     */
    public static function borrarHijasSinCargar(Writer $w, string $tabla, array $hijas): void
    {
        $meta = $w->meta($tabla);
        foreach ($hijas as $pos => $fila) {
            $w->ponerEn($tabla, $pos, null, $fila);
            self::avisarSentencia($w, $tabla, $meta, $fila, null);
            $w->propagarHijos($tabla, $meta, $fila, null);
        }
    }

    /** Borra filas hijas por posición, disparando sus propios triggers y sus cascadas, estas después de quitar cada una, como en borrar(). */
    public static function borrarHijas(Writer $w, string $tabla, array $hijas): void
    {
        $meta = $w->meta($tabla);
        foreach (array_keys($hijas) as $pos0) {
            $fila = $w->datos[$tabla][$pos0] ?? null;
            if ($fila === null) {
                continue;
            }
            $w->lanzarTriggers($tabla, 'BEFORE', 'DELETE', null, $fila);
            $fila = $w->datos[$tabla][$pos0] ?? null;
            if ($fila === null) {
                continue;
            }
            $w->quitarFilaEn($tabla, (int)$pos0);
            self::avisarSentencia($w, $tabla, $meta, $fila, null);
            $w->propagarHijos($tabla, $meta, $fila, null);

            $w->lanzarTriggers($tabla, 'AFTER', 'DELETE', null, $fila);
        }
        // Se compacta al terminar la sentencia: hacerlo aquí, tras cada padre,
        // movía las posiciones y obligaba a rehacer el mapa de hijas
        $w->porCompactar[$tabla] = true;
    }

    /**
     * ON UPDATE CASCADE, SET NULL y SET DEFAULT: cada hija cambia como en un
     * UPDATE (2.9), con sus triggers BEFORE y AFTER UPDATE, sus claves únicas
     * y foráneas, y la cascada hacia sus propias hijas. Antes solo se
     * convertían sus tipos: podía quedar repetida en un UNIQUE o apuntando a
     * un padre que no existe, sus hijas se quedaban con la clave vieja y sus
     * triggers no se enteraban.
     *
     * Solo se miran las claves únicas y foráneas con alguna de las columnas
     * que cambian, que en las demás todo sigue igual (todas, si un trigger
     * BEFORE ha tocado la fila); y la propia clave foránea con SET DEFAULT, y
     * con CASCADE si la hija tiene triggers o hijas: apunta a la fila padre,
     * ya escrita, pero lo que hagan por una hija anterior la puede haber
     * borrado (2.10: quedaba huérfana, desde antes de la 2.9). Con SET NULL,
     * a ninguna.
     *
     * @param array<int, array> $hijas posición => fila
     */
    public static function cambiarHijas(Writer $w, string $hija, array $metaHija, array $fk, string $accion, array $hijas, ?array $nueva): void
    {
        $unicos = [];
        foreach ($w->unicos($metaHija) as $uq) {
            if (array_intersect($uq['columns'], $fk['columns']) !== []) {
                $unicos[] = $uq;
            }
        }
        // Lo que no tiene que hacer, se sabe una vez: así una cascada sobre una
        // hija sin triggers ni hijas cuesta lo mismo que antes por fila
        $triggers = $metaHija['triggers'] !== [];
        $nietas   = ($w->deMeta[$metaHija['table']]['hijas'] ??= $w->clavesHacia($hija)) !== [];
        $foraneas = [];
        foreach ($metaHija['foreign_keys'] as $otra) {
            if (array_intersect($otra['columns'], $fk['columns']) !== []
                && ($otra !== $fk || $accion === 'SET DEFAULT' || ($accion === 'CASCADE' && ($triggers || $nietas)))) {
                $foraneas[] = $otra;
            }
        }
        foreach ($hijas as $pos => $vieja) {
            // Sin triggers ni hijas suyas, nada la ha podido tocar desde que se leyó
            if (($triggers || $nietas) && ($vieja = WriterCambios::filaActual($w, $hija, $pos, $vieja)) === null) {
                continue;                        // la ha borrado un trigger o una cascada de una hija anterior
            }
            $f = $vieja;
            foreach ($fk['columns'] as $i => $c) {
                $f[$c] = $accion === 'CASCADE' ? $nueva[$fk['references'][$i]]
                    : ($accion === 'SET NULL' ? null : self::porDefecto(Catalog::columna($metaHija, $c)));
            }
            $f = $w->prepararFila($hija, $metaHija, $f, false);
            $todas = false;                      // un SET NEW puede haber cambiado cualquier columna
            if ($triggers) {
                $preparada = $f;
                $f = $w->lanzarTriggers($hija, 'BEFORE', 'UPDATE', $f, $vieja);
                if ($f !== $preparada) {
                    $todas = true;
                    $f = $w->prepararFila($hija, $metaHija, $f, false);
                }
                if (WriterCambios::filaActual($w, $hija, $pos, $vieja) === null) {
                    continue;                    // la ha borrado su propio BEFORE
                }
            }
            foreach ($todas ? $w->unicos($metaHija) : $unicos as $uq) {
                $clave = self::claveDe($f, $uq['columns'], $uq['plegar']);
                if ($clave !== null && $clave !== self::claveDe($vieja, $uq['columns'], $uq['plegar'])
                    && self::otraConClave($w, $hija, $metaHija, $uq, $clave, $pos)) {
                    throw self::duplicado($hija, $uq);
                }
            }
            if ($todas || $foraneas !== []) {
                $w->comprobarForaneas($hija, $metaHija, $f, $todas ? null : $foraneas);
            }
            $w->ponerEn($hija, $pos, $f, $vieja);
            self::avisarSentencia($w, $hija, $metaHija, $vieja, $f);
            if ($unicos !== [] && !isset($w->datos[$hija])) {
                foreach ($unicos as $uq) {
                    $clave = self::claveDe($f, $uq['columns'], $uq['plegar']);
                    if ($clave !== null) {
                        $w->clavesCascada[$hija][$uq['name']][$clave] = $pos;
                    }
                }
            }
            if ($nietas) {
                $w->propagarHijos($hija, $metaHija, $vieja, $f);
            }
            if ($triggers) {
                $w->lanzarTriggers($hija, 'AFTER', 'UPDATE', $f, $vieja);
            }
        }
    }

    /**
     * Lo que una cascada cambia o borra en la tabla que escribe la sentencia
     * (una clave foránea hacia sí misma), también en las claves únicas que
     * lleva la sentencia (2.10, ver Writer::$indicesVivos).
     */
    private static function avisarSentencia(Writer $w, string $tabla, array $meta, array $vieja, ?array $nueva): void
    {
        if (isset($w->indicesVivos[$tabla])) {
            $nueva === null ? WriterCambios::quitarDeIndices($w, $meta, $vieja, $w->indicesVivos[$tabla])
                : $w->cambiarEnIndices($meta, $vieja, $nueva, $w->indicesVivos[$tabla]);
        }
    }

    /**
     * ¿Tiene otra fila de la tabla, la que no está en $pos, esta clave en el
     * conjunto único $uq? Para las hijas que cambia una cascada, que no
     * llevan el estado de un UPDATE (ver indicesUnicos()). Con la tabla en
     * memoria, por el mapa de hijasDe(); sin cargarla, por su índice (lo
     * exige hijasSinCargar()) con cada fila como está ahora, y por las claves
     * que han puesto las cascadas de la sentencia, que el índice no tiene.
     */
    private static function otraConClave(Writer $w, string $tabla, array $meta, array $uq, string $clave, int $pos): bool
    {
        if (!isset($w->datos[$tabla])) {
            $def        = $w->indiceDe($tabla, $meta, $uq['columns'], true);
            $posiciones = $def === null ? null : StorageEscritura::posicionesDeClave($w->cat->storage(), $tabla, $def, $clave);
            if ($posiciones !== null) {
                $posiciones[] = $w->clavesCascada[$tabla][$uq['name']][$clave] ?? $pos;
                foreach ($posiciones as $p) {
                    if ($p !== $pos && !isset($w->borradas[$tabla][$p]) && (!isset($w->cambios[$tabla][$p])
                        || self::claveDe($w->cambios[$tabla][$p], $uq['columns'], $uq['plegar']) === $clave)) {
                        return true;
                    }
                }
                return false;
            }
            $w->filas($tabla);                // el índice no sirve: con la tabla
        }
        foreach (array_keys(self::hijasDe($w, $tabla, $uq['columns'], $clave, $uq['plegar'])) as $p) {
            if ($p !== $pos) {
                return true;
            }
        }
        return false;
    }
}
