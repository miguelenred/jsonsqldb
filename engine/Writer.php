<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Ejecutor de escrituras y de DDL.
 *
 * Todo lo que se modifica se acumula en memoria y se vuelca a disco de una vez
 * al final: si algo falla a mitad (una restricción, un trigger con RAISE), no se
 * escribe nada y la base queda como estaba.
 *
 * Comprueba NOT NULL, tipos, clave primaria, UNIQUE y claves foráneas (con
 * ON DELETE / ON UPDATE), y ejecuta los triggers BEFORE y AFTER con NEW y OLD.
 *
 * Aquí, lo de todas las escrituras. El INSERT está en WriterInsercion;
 * UPDATE y DELETE, en WriterCambios (TRUNCATE y los que cruzan otras tablas,
 * en WriterCambiosEspeciales); las claves foráneas, en WriterForaneas;
 * los choques con una clave (REPLACE, ON CONFLICT…), en WriterConflictos; el
 * cuerpo de los triggers, en WriterTriggers; RETURNING, en WriterAvanzado, y
 * la DDL, en WriterDdl.
 *
 * https://miguelenred.es/jsonsqldb
 */
class Writer
{
    /** Anidamiento máximo de triggers, para cortar recursiones infinitas */
    private const MAX_ANIDAMIENTO = 8;

    protected Catalog $cat;
    protected array $datos      = [];   // tabla => filas en memoria
    protected array $anexo      = [];   // tabla => filas añadidas al final sin leer la tabla
    protected array $cambios    = [];   // tabla => posición => fila cambiada sin leer la tabla
    protected array $borradas   = [];   // tabla => posición => true, borrada sin leer la tabla
    /**
     * @var array<string, array<string, array<string, array<int, true>>>> de las
     * filas cambiadas sin leer la tabla, sus posiciones por la clave de una
     * clave foránea, y las cambiadas desde que se miró (2.10, ver
     * WriterForaneas::hijasSinCargar())
     */
    protected array $idxCambios = [];
    protected array $cambiosSinMirar = [];
    protected array $metas      = [];   // tabla => estructura en memoria
    protected array $sucioDatos = [];
    protected array $astCache   = [];   // sql de trigger => árbol ya analizado
    protected array $idxPadre   = [];   // tabla|cols => definición de índice, o claves recogidas
    /** @var array<string,array> resultados de subconsultas que no miran fuera, por su texto: de una sentencia (ver WriterTriggers::ejecutarCuerpo()) */
    protected array $subsFijas = [];
    /** @var array<string,array> sus conjuntos de valores, para IN */
    protected array $conjuntosFijos = [];
    /** @var array<string,int> siguiente autoincremento por tabla, movido en esta sentencia */
    protected array $autoinc    = [];
    /** @var array<string,int> siguiente id de una clave primaria entera sin AUTOINCREMENT, en esta sentencia */
    protected array $rowid      = [];
    /**
     * La sentencia ya ha escrito parte por tramos (ver tramo()): si falla, la
     * transacción no puede confirmarse con ella a medias, solo deshacerse
     * (2.10: si el error se capturaba, se confirmaba con lo escrito hasta él)
     */
    private bool $aMedias = false;
    /** @var array<string, array<int, array>> los CHECK de cada tabla, ya analizados */
    private array $checks     = [];
    private int   $anidamiento = 0;
    /** Memoria en uso a partir de la cual la sentencia escribe lo que lleva (ver tramo()) */
    private int   $tope;
    /** Memoria que puede juntar un INSERT … SELECT antes de meter lo que lleva (ver WriterInsercion) */
    protected int $lote;
    private int   $vueltas = 0;

    public function __construct(Catalog $cat)
    {
        $this->cat  = $cat;
        $limite     = Memoria::limiteEnBytes();
        // La mitad, menos sitio para escribir lo que lleve (decodificar una
        // parte para leer las claves de antes, los trozos de índice): con la
        // mitad justa, a 16 MB no quedaba ni para eso. Nunca menos de un cuarto
        $this->tope = $limite > 0 ? max(intdiv($limite, 4), intdiv($limite, 2) - 16 * 1048576) : PHP_INT_MAX;
        $this->lote = $limite > 0 ? min(8 * 1048576, intdiv($limite, 16)) : 8 * 1048576;
    }

    /**
     * Ejecuta una sentencia de escritura o de definición.
     * @return array ['filas' => int, 'mensaje' => string]
     */
    public function ejecutar(array $ast): array
    {
        // Las vistas son de solo lectura: no se escribe ni se altera sobre ellas
        // CREATE TABLE se comprueba aparte, con su propio mensaje
        $destino = $ast['k'] === 'create_table' ? null : ($ast['tabla'] ?? null);
        if (\is_string($destino) && $this->cat->esVista($destino)) {
            $operacion = [
                'insert'      => 'INSERT', 'update' => 'UPDATE', 'delete' => 'DELETE',
                'alter_table' => 'ALTER TABLE', 'drop_table' => 'DROP TABLE',
            ][$ast['k']] ?? $ast['k'];
            $extra = $ast['k'] === 'drop_table' ? " Usa DROP VIEW \"$destino\"." : '';
            throw JsonSqlDbError::schema(
                "'$destino' es una vista, no una tabla: no admite $operacion.$extra"
            );
        }

        // El DML abre su escritura en volcar(), con el ámbito del bloqueo que
        // tiene. Todo lo demás lleva siempre el exclusivo de la base y puede
        // tocar varias tablas: una sola escritura de base para todo ello.
        if (\in_array($ast['k'], ['insert', 'update', 'delete'], true)) {
            return $this->despachar($ast);
        }
        $st = $this->cat->storage();
        StorageEscritura::txIniciar($st, Database::operacion($ast));
        $r = $this->despachar($ast);
        StorageEscritura::txConfirmar($st);
        return $r;
    }

    private function despachar(array $ast): array
    {
        if ($ast['k'] !== 'insert' && $ast['k'] !== 'update' && $ast['k'] !== 'delete') {
            return WriterDdl::despachar($this, $ast);
        }
        if (isset($ast['bytes'])) {
            WriterAvanzado::exigirTexto($ast);
        }
        $this->devueltas   = [];
        $this->indicesVivos = [];
        $this->aMedias     = false;
        try {
            $this->foraneasPendientes = [];
            $n = $ast['k'] === 'insert' ? WriterInsercion::insertar($this, $ast) : ($ast['k'] === 'update' ? WriterCambios::actualizar($this, $ast) : WriterCambios::borrar($this, $ast));
            if ($this->foraneasPendientes !== []) {
                WriterForaneas::alAcabar($this);
            }
            foreach (array_keys($this->porCompactar) as $t) {
                $this->compactar((string)$t);
            }
            $this->porCompactar = [];
            // RETURNING, antes de escribir: si da error, la sentencia no deja
            // nada, como en SQLite (2.10: la fila quedaba escrita)
            $devueltas = ($ast['returning'] ?? null) !== null ? WriterAvanzado::devolver($this, $ast['tabla'], $ast['returning'], $ast['alias'] ?? null) : null;
            $this->devueltas = [];
            $this->volcar();
        } catch (\Throwable $e) {
            if ($this->aMedias) {
                StorageEscritura::loteAMedias($this->cat->storage());
            }
            throw $e;
        }
        $r = ['filas' => $n, 'mensaje' => "$n fila(s) " . ['insert' => 'insertada(s)', 'update' => 'actualizada(s)', 'delete' => 'eliminada(s)'][$ast['k']]];
        if ($devueltas !== null) {
            $r['devueltas'] = $devueltas;
        }
        return $r;
    }

    // ==================================================================
    // Estado en memoria
    // ==================================================================

    public function filas(string $tabla): array
    {
        if (!isset($this->datos[$tabla])) {
            // Lo cambiado sin leer la tabla pasa a estar en ella, como si se
            // hubiera hecho de la forma normal
            $this->datos[$tabla] = StorageEscritura::leerFilas($this->cat->storage(), $tabla);
            foreach ($this->cambios[$tabla] ?? [] as $pos => $fila) {
                $this->datos[$tabla][$pos] = $fila;
                $this->marcarSuelta($tabla, $pos);
            }
            foreach ($this->anexo[$tabla] ?? [] as $fila) {
                $this->marcarDesde($tabla, \count($this->datos[$tabla]));
                $this->datos[$tabla][] = $fila;
            }
            foreach (array_keys($this->borradas[$tabla] ?? []) as $pos) {
                unset($this->datos[$tabla][$pos]);
                $this->marcarDesde($tabla, $pos);
            }
            if (isset($this->borradas[$tabla])) {
                $this->porCompactar[$tabla] = true;      // al terminar la sentencia (ver WriterCambios::filaActual())
            }
            // Y lo que un upsert sabía de sus claves sin cargarla: las filas que
            // metía (posiciones negativas) tienen ya su sitio en la tabla. Se
            // vuelve a armar de ella (2.9: se seguía usando, y la fila de una
            // posición negativa ya no estaba)
            unset($this->cambios[$tabla], $this->anexo[$tabla], $this->borradas[$tabla], $this->unicas[$tabla], $this->idxCambios[$tabla], $this->cambiosSinMirar[$tabla]);
            $this->olvidarIndices($tabla);
        }
        return $this->datos[$tabla];
    }

    /** @var array<string,array{cols: list<string>, mapa: array<string,array<int,true>>}> lado hijo de las claves foráneas (hijasDe()) */
    protected array $idxHijo = [];
    /** @var array<string,int> cuántas veces se han pedido las hijas de cada clave foránea en la sentencia */
    protected array $pedidasHijo = [];
    /** @var array<string,true> tablas con huecos de un borrado en cascada, por compactar al terminar la sentencia */
    protected array $porCompactar = [];
    /** @var list<array> filas escritas por la sentencia, para su RETURNING */
    protected array $devueltas = [];
    /**
     * Por tabla y clave única, clave → posición (ON CONFLICT, OR IGNORE, OR
     * REPLACE). Con la tabla en memoria, de todas sus filas; sin cargarla
     * (2.9), solo de las claves que toca la sentencia: una posición negativa
     * es una fila que mete ella (-1 - su sitio en anexo), y false, una clave
     * que ha dejado libre. Las demás se buscan en el índice del disco (ver
     * WriterConflictos::chocaCon()). Por tabla (2.9): las sentencias de un
     * trigger usan este mismo Writer, y un upsert suyo, o un INSERT, vaciaba
     * lo del de fuera.
     *
     * @var array<string,array<string,array<string,int|false>>>
     */
    protected array $unicas = [];
    /** @var array<string, array<string, ?array>> el índice de cada clave única para WriterConflictos::chocaEnDisco(), una vez por sentencia */
    protected array $defUnica = [];
    /** @var array<string, array<string, int>> para WriterConflictos::chocaEnDisco(), el $volcados en que su índice estaba vacío, o -1 */
    protected array $vacioUnica = [];
    /**
     * Las claves únicas que una cascada ha puesto en filas de una tabla sin
     * cargarla, por tabla y clave única: clave → posición (2.9). El índice del
     * disco no las tiene (ver WriterForaneas::otraConClave()).
     *
     * @var array<string,array<string,array<string,int>>>
     */
    protected array $clavesCascada = [];
    /**
     * Las claves únicas que lleva la sentencia que escribe cada tabla (ver
     * indicesUnicos()), por referencia: una cascada sobre la misma tabla (una
     * clave foránea hacia sí misma) las tiene al día (2.10: no las veía, y un
     * UPDATE podía repetir un UNIQUE). Ver WriterForaneas::avisarSentencia().
     *
     * @var array<string, array>
     */
    protected array $indicesVivos = [];
    /** Veces que se ha escrito lo que llevaba la sentencia (ver volcar()): lo visto antes en el disco puede haber cambiado */
    protected int $volcados = 0;
    /** @var array<string, array<string, mixed>> lo que se deduce de la definición de cada tabla, una vez por sentencia (ver unicos()) */
    protected array $deMeta = [];
    /**
     * Qué posiciones cambió cada tabla, para no reescribirla entera al guardar.
     *
     *   desde[t]   a partir de esa posición las filas se han desplazado
     *   sueltas[t] posiciones que cambiaron sin mover a las demás
     *   sabe[t]    si se puede afirmar lo anterior; si no, se reescribe todo
     *
     * @var array<string,int>            $desde
     * @var array<string,array<int,true>> $sueltas
     * @var array<string,bool>           $sabe
     */
    private array $desde   = [];
    private array $sueltas = [];
    protected array $sabe    = [];
    /** @var array<string,true> tablas cuya estructura se escribe con sus filas (TRUNCATE, ver vaciar()) */
    protected array $conMeta = [];

    /** Cambió a partir de $pos, desplazando lo que venga detrás. */
    protected function marcarDesde(string $tabla, int $pos): void
    {
        $this->desde[$tabla] = min($this->desde[$tabla] ?? PHP_INT_MAX, max(0, $pos));
        $this->sabe[$tabla]  = $this->sabe[$tabla] ?? true;
    }

    /** Cambió UNA fila y las demás siguen donde estaban. */
    private function marcarSuelta(string $tabla, int $pos): void
    {
        $this->sueltas[$tabla][$pos] = true;
        $this->sabe[$tabla] = $this->sabe[$tabla] ?? true;
    }

    /**
     * Escribe una fila en su sitio sin copiar la tabla entera.
     *
     * El patrón de antes era: sacar el array con filas(), tocar una posición y
     * devolverlo entero a $this->datos. Con dos referencias vivas al mismo array,
     * PHP separa la copia en cuanto se escribe, así que cada fila afectada
     * copiaba la tabla completa. Aquí se escribe directamente en $this->datos,
     * sin variable intermedia que lo referencie.
     */
    protected function ponerFilaEn(string $tabla, int $pos, array $fila): void
    {
        if ($this->rowid !== [] || $this->foraneasPendientes !== []) {
            $this->filaCambia($tabla, $this->datos[$tabla][$pos] ?? [], $fila);
        }
        $this->datos[$tabla][$pos] = $fila;
        $this->sucioDatos[$tabla]  = true;
        $this->marcarSuelta($tabla, $pos);        // no mueve a las demás
        $this->olvidarPadre($tabla);
        $this->apuntarHija($tabla, $pos, $fila);  // su clave puede ser otra
    }

    /**
     * Quita una fila sin copiar la tabla, y sin recolocar las demás.
     *
     * Compactar con array_values() en cada borrado movía todas las filas
     * siguientes, así que la posición dejaba de valer y había que buscarla otra
     * vez. Dejando el hueco, las posiciones siguen siendo buenas; se compacta
     * una sola vez al terminar, y StorageEntera::guardarTabla() lo haría
     * igualmente al escribir.
     */
    protected function quitarFilaEn(string $tabla, int $pos): void
    {
        if ($this->rowid !== [] || $this->foraneasPendientes !== []) {
            $this->filaCambia($tabla, $this->datos[$tabla][$pos] ?? [], null);
        }
        unset($this->datos[$tabla][$pos]);
        $this->sucioDatos[$tabla] = true;
        $this->marcarDesde($tabla, $pos);         // al compactar se mueve lo de detrás
        $this->olvidarPadre($tabla);              // en el lado hijo, la posición vacía se salta al usarla
    }

    /** Cierra los huecos que hayan dejado los borrados. */
    private function compactar(string $tabla): void
    {
        if (isset($this->datos[$tabla])) {
            $this->datos[$tabla] = array_values($this->datos[$tabla]);
            $this->olvidarIndices($tabla);        // las posiciones han cambiado
        }
    }

    /** Lo que se sabía de la tabla como padre y como hija: ya no vale. */
    protected function olvidarIndices(string $tabla): void
    {
        $this->olvidarPadre($tabla);
        foreach (array_keys($this->idxHijo) as $id) {
            if (strncmp($id, $tabla . '|', \strlen($tabla) + 1) === 0) {
                unset($this->idxHijo[$id]);
            }
        }
    }

    protected function olvidarPadre(string $tabla): void
    {
        foreach (array_keys($this->idxPadre) as $clave) {
            if (strncmp($clave, $tabla . '|', \strlen($tabla) + 1) === 0) {
                unset($this->idxPadre[$clave]);
            }
        }
    }

    /** Una fila escrita en $pos: entra en los mapas de hijas de su tabla con su clave de ahora. */
    protected function apuntarHija(string $tabla, int $pos, array $fila): void
    {
        foreach ($this->idxHijo as $id => $i) {
            if (strncmp($id, $tabla . '|', \strlen($tabla) + 1) === 0) {
                $k = self::claveDe($fila, $i['cols'], $i['plegar'], $i['afin'] ?? []);
                if ($k !== null) {
                    $this->idxHijo[$id]['mapa'][$k][$pos] = true;
                }
            }
        }
    }

    protected function meta(string $tabla): array
    {
        if (!isset($this->metas[$tabla])) {
            if (!$this->cat->existe($tabla)) {
                throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
            }
            $this->metas[$tabla] = $this->cat->meta($tabla);
        }
        return $this->metas[$tabla];
    }

    /**
     * Vuelca a disco todo lo modificado, en una sola escritura: cada tabla
     * puede ocupar varios ficheros y un corte entre dos no debe dejarla a
     * medias. El ámbito de la escritura es el bloqueo que se tiene: si es el
     * exclusivo de TODAS las tablas tocadas, basta con el de una de ellas,
     * que es lo que permite que escrituras en tablas distintas vayan a la vez;
     * si no, se entró con el exclusivo de la base y la escritura es de base.
     *
     * Sin $compactar (un tramo, ver tramo()), solo las tablas sin cargar: una
     * cargada sigue entera en memoria igual, y escrita sin sus huecos sus
     * posiciones dejarían de ser las del disco, que la sentencia, un trigger
     * o una cascada aún pueden leer (2.10: una cascada sobre una hija sin
     * índice dejaba huérfanas y borraba otras filas, desde la 2.9). Se escribe
     * al acabar la sentencia, con lo demás.
     */
    private function volcar(bool $compactar = true): void
    {
        $st     = $this->cat->storage();
        $tablas = array_keys($this->sucioDatos);
        if (!$compactar) {
            $tablas = array_values(array_filter($tablas, fn($t): bool => !isset($this->datos[$t])));
        }
        if ($tablas === []) {
            return;
        }
        $this->volcados++;
        if (!StorageEscritura::txAbierta($st)) {
            sort($tablas, SORT_STRING);
            $ambito = (string)$tablas[0];
            foreach ($tablas as $t) {
                if (!StorageEscritura::tieneExclusivoDe($st, (string)$t)) {
                    $ambito = null;
                    break;
                }
            }
            StorageEscritura::txIniciar($st, 'ESCRITURA', $ambito);
        }

        foreach ($tablas as $tabla) {
            $defs = Indexes::definiciones($this->metas[$tabla] ?? $this->cat->meta($tabla));
            if (isset($this->autoinc[$tabla])) {
                StorageEscritura::ponerAutoincremento($st, $tabla, $this->autoinc[$tabla]);
            }
            // Un upsert sin cargar la tabla puede dejar las tres cosas: cambios,
            // borradas y filas nuevas (2.9: con nuevas solo se escribían ellas)
            if (isset($this->cambios[$tabla]) || isset($this->borradas[$tabla])) {
                StorageCambios::modificarFilas($st, $tabla, $this->cambios[$tabla] ?? [],
                    array_keys($this->borradas[$tabla] ?? []), $defs, $compactar, $this->anexo[$tabla] ?? []);
                continue;
            }
            if (isset($this->anexo[$tabla])) {
                StorageEscritura::anadirFilas($st, $tabla, $this->anexo[$tabla], $defs);
                continue;
            }
            StorageEntera::guardarTabla($st,
                $tabla,
                isset($this->sucioDatos[$tabla]) ? $this->datos[$tabla] : null,
                isset($this->conMeta[$tabla]) ? CatalogDdl::compactar($this->metas[$tabla]) : null,
                $defs,
                $this->desde[$tabla] ?? null,
                array_keys($this->sueltas[$tabla] ?? []),
                ($this->sabe[$tabla] ?? false) === true
            );
        }
        StorageEscritura::txConfirmar($st);

        // Lo de las tablas sin cargar va todo; de las cargadas, lo escrito
        $this->anexo      = [];
        $this->cambios    = [];
        $this->idxCambios = $this->cambiosSinMirar = [];
        $this->borradas   = [];
        $this->clavesCascada = [];               // sus posiciones, ya en los índices (2.10: un duplicado falso tras un tramo)
        foreach ($tablas as $t) {
            unset($this->sucioDatos[$t], $this->desde[$t], $this->sueltas[$t], $this->sabe[$t], $this->conMeta[$t]);
        }
        $this->cat->olvidar();
    }

    /**
     * Una sentencia que cambia más filas de las que caben en memoria (2.9):
     * cuando la memoria en uso pasa de $tope (casi la mitad de memory_limit),
     * escribe lo que lleva y sigue (con una cuarta parte, sentencias que la
     * 2.8 hacía de una vez se repetían por tramos y tardaban hasta un 60 %
     * más). Solo dentro de una transacción, que la deja ser todo o nada aunque
     * escriba a medias; fuera, pide que se repita dentro (ver
     * DatabaseAvanzada::porTramos()), y eso pasa antes de haber escrito nada.
     * Lo que la sentencia sabía de sus claves únicas queda en los índices del
     * disco.
     * Antes, un UPDATE de 300.000 filas de una tabla de un millón agotaba los
     * 256 MB con un error fatal.
     *
     * @param array<string, array> $indices ver indicesUnicos()
     */
    protected function tramo(array &$indices = []): void
    {
        if ((++$this->vueltas & 255) !== 0 || memory_get_usage() <= $this->tope) {
            return;
        }
        if (!StorageEscritura::enLote($this->cat->storage())) {
            throw new SentenciaGrande();
        }
        $antes = $this->volcados;
        $this->volcar(false);
        $this->aMedias = $this->aMedias || $this->volcados !== $antes;
        foreach (array_keys($indices) as $n) {
            $indices[$n]['nuevas'] = $indices[$n]['quitadas'] = [];
        }
        $this->unicas = [];                         // lo de la sentencia ya está en los índices
    }

    // ==================================================================
    // Claves únicas y cambio de una fila
    // ==================================================================

    /**
     * Catalog::conjuntosUnicos() de la tabla, una vez por sentencia: se pide
     * varias veces por cada fila que se escribe, y armarlo cada vez era una
     * parte apreciable de un INSERT de muchas filas (2.9).
     */
    protected function unicos(array $meta): array
    {
        return $this->deMeta[$meta['table']]['unicos'] ??= Catalog::conjuntosUnicos($meta);
    }

    /** Cambia (o borra, con null) la fila $vieja de una posición, con la tabla en memoria o sin cargarla. */
    protected function ponerEn(string $tabla, int $pos, ?array $fila, array $vieja): void
    {
        if (isset($this->datos[$tabla])) {
            $fila === null ? $this->quitarFilaEn($tabla, $pos) : $this->ponerFilaEn($tabla, $pos, $fila);
            return;
        }
        if ($this->rowid !== [] || $this->foraneasPendientes !== []) {
            $this->filaCambia($tabla, $vieja, $fila);
        }
        if ($pos < 0) {
            if ($fila === null) {
                unset($this->anexo[$tabla][-1 - $pos]);
            } else {
                $this->anexo[$tabla][-1 - $pos] = $fila;
            }
        } elseif ($fila === null) {
            $this->borradas[$tabla][$pos] = true;
            unset($this->cambios[$tabla][$pos]);
        } else {
            $this->cambios[$tabla][$pos] = $fila;
            if (isset($this->idxCambios[$tabla])) {
                $this->cambiosSinMirar[$tabla][$pos] = true;
            }
        }
        $this->sucioDatos[$tabla] = true;
    }

    // ==================================================================
    // Validación de filas
    // ==================================================================

    /**
     * Aplica autoincremento, convierte tipos y comprueba NOT NULL.
     *
     * @param bool $comprobar sin él, ni NOT NULL ni CHECK: los mira después (OR IGNORE, ver WriterInsercion::insertar())
     */
    protected function prepararFila(string $tabla, array $meta, array $fila, bool $esInsert, bool $comprobar = true): array
    {
        // Una clave primaria de una sola columna entera, sin AUTOINCREMENT, se
        // numera sola como en SQLite (2.9): sin valor, la mayor que hay + 1.
        // Una vez por sentencia (ver unicos())
        [$auto, $rowid] = $this->deMeta[$meta['table']]['auto'] ??= (static function (array $meta): array {
            $auto = Catalog::columnaAutoincremento($meta);
            $pk   = Catalog::clavePrimaria($meta);
            return [$auto, $auto === null && \count($pk) === 1 ? $pk[0] : null];
        })($meta);

        foreach ($meta['columns'] as $c) {
            $nombre = $c['name'];
            $valor  = $fila[$nombre] ?? null;

            if ($valor === null && $esInsert && $auto !== null && $nombre === $auto) {
                $valor = WriterInsercion::siguienteAutoincremento($this, $tabla);
            }
            $esRowid = $esInsert && $nombre === $rowid && $c['type'] === 'INTEGER';
            if ($valor === null && $esRowid) {
                $valor = WriterInsercion::siguienteRowid($this, $tabla, $meta, $nombre);
            }
            if ($valor !== null) {
                $valor = Types::cast($valor, $c);
                if ($auto !== null && $nombre === $auto && $esInsert) {
                    WriterInsercion::ajustarAutoincremento($this, $tabla, (int)$valor);
                }
                if ($esRowid && isset($this->rowid[$tabla]) && \is_int($valor) && $valor >= $this->rowid[$tabla]) {
                    $this->rowid[$tabla] = $valor + 1;
                }
            }
            if ($valor === null && $c['notnull'] && $comprobar) {
                throw JsonSqlDbError::constraint("La columna '$tabla.$nombre' no admite NULL");
            }
            $fila[$nombre] = $valor;
        }

        // Descartar claves que no son columnas de la tabla
        $limpia = [];
        foreach ($meta['columns'] as $c) {
            $limpia[$c['name']] = $fila[$c['name']];
        }
        // CHECK (2.9): falla si da falso; NULL pasa, como en SQLite y MySQL
        foreach ($comprobar ? $meta['checks'] ?? [] : [] as $i => $ck) {
            $expr = $this->checks[$tabla][$i] ??= Evaluator::resolver(
                Parser::analizar('SELECT (' . $ck['sql'] . ')')['cols'][0]['expr'], $this->mapaColumnas($tabla, $meta));
            if (Valor::verdadero(Evaluator::evaluar($expr, ['fila' => $limpia])) === false) {
                throw JsonSqlDbError::constraint("No se cumple el CHECK " . ($ck['name'] !== null ? "'{$ck['name']}' " : '')
                    . "({$ck['sql']}) de '$tabla'");
            }
        }
        return $limpia;
    }

    /**
     * Una fila que se quita ($nueva null) o se cambia en la sentencia (2.10).
     * Si tenía la mayor clave primaria entera sin AUTOINCREMENT, la siguiente
     * se vuelve a calcular con las que quedan, y si pasa a tener una mayor, la
     * siguiente sale de ella, como en SQLite (antes se seguía contando desde
     * la que ya no estaba); solo si la sentencia ha dado ya alguna. Y si era
     * una hija que se mira al acabar la sentencia, ya no cuenta. Quien la llama
     * mira antes si hay algo de eso: se cambian muchas filas, y casi nunca
     */
    protected function filaCambia(string $tabla, array $vieja, ?array $nueva): void
    {
        if (isset($this->rowid[$tabla])) {
            $col = $this->deMeta[$this->meta($tabla)['table']]['auto'][1];
            $v   = $nueva[$col] ?? null;
            if (\is_int($v) && $v >= $this->rowid[$tabla]) {
                $this->rowid[$tabla] = $v + 1;
            } elseif (($vieja[$col] ?? null) === $this->rowid[$tabla] - 1 && $v !== $vieja[$col]) {
                unset($this->rowid[$tabla]);
            }
        }
        if (isset($this->foraneasPendientes[$tabla])) {
            WriterForaneas::hijaQuitada($this, $tabla, $vieja, $nueva);
        }
    }

    /**
     * ¿Se puede insertar en esta tabla sin leerla? Hace falta que no esté ya
     * en memoria, que no tenga triggers —que podrían consultarla—, que no se
     * referencie a sí misma, y que cada conjunto único tenga índice en disco
     * para comprobar la unicidad contra él. Con $aunqueCambiada, aunque la
     * sentencia ya tenga cambios suyos sin escribir (ver
     * WriterForaneas::hijasSinCargar()).
     */
    protected function sinLeer(string $tabla, array $meta, bool $aunqueCambiada = false): bool
    {
        if (isset($this->datos[$tabla]) || $meta['triggers'] !== []) {
            return false;
        }
        foreach ($meta['foreign_keys'] as $fk) {
            if (strcasecmp($fk['table'], $tabla) === 0) {
                return false;
            }
        }
        foreach ($this->unicos($meta) as $uq) {
            if ($this->indiceDe($tabla, $meta, $uq['columns'], $aunqueCambiada) === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Definición del índice de disco de la tabla sobre esas columnas, si lo
     * hay y está al día: exige que la tabla no tenga cambios en memoria,
     * porque el índice no los conoce.
     *
     * @return array{name: string, columns: list<string>, auto: bool}|null
     */
    protected function indiceDe(string $tabla, array $meta, array $cols, bool $aunqueCambiada = false): ?array
    {
        if (!$aunqueCambiada && isset($this->sucioDatos[$tabla])) {
            return null;
        }
        foreach (Indexes::definiciones($meta) as $def) {
            if ($def['columns'] === $cols) {
                return StorageEscritura::indiceValido($this->cat->storage(), $tabla, $def) ? $def : null;
            }
        }
        return null;
    }

    /**
     * Estado de cada conjunto único de la tabla durante la sentencia. Las
     * claves que ya están en la tabla se consultan al índice de disco, trozo
     * a trozo, si lo hay y sirve; si no, se recorre la tabla una vez y se
     * guardan en `mapa`. Lo que la sentencia añade y quita va aparte, en
     * `nuevas` y `quitadas`, porque el índice de disco no lo sabe.
     *
     * @return array<string,array{def: ?array, mapa: ?array<string,true>, nuevas: array<string,true>, quitadas: array<string,true>, vacio: int}>
     */
    protected function indicesUnicos(string $tabla, array $meta): array
    {
        $indices = [];
        foreach ($this->unicos($meta) as $uq) {
            $def  = $this->indiceDe($tabla, $meta, $uq['columns']);
            $mapa = null;
            if ($def === null) {
                $mapa = [];
                foreach ($this->filas($tabla) as $fila) {
                    $clave = self::claveDe($fila, $uq['columns'], $uq['plegar']);
                    if ($clave !== null) {
                        $mapa[$clave] = true;
                    }
                }
            }
            $indices[$uq['name']] = ['def' => $def, 'mapa' => $mapa, 'nuevas' => [], 'quitadas' => [],
                                     'vacio' => $def === null ? -1 : $this->vacioDesde($tabla, $def)];
        }
        return $indices;
    }

    /**
     * $volcados si el índice en disco no tiene ninguna clave, como el de una
     * tabla recién creada, y -1 si tiene: mientras la sentencia no escriba, no
     * hace falta buscar en él. Cada fila de un INSERT … SELECT en una tabla
     * vacía lo buscaba (2.9: un 13-38 % más lento que la 2.8.0, que la cargaba)
     */
    protected function vacioDesde(string $tabla, array $def): int
    {
        return StorageEscritura::indiceVacio($this->cat->storage(), $tabla, $def) ? $this->volcados : -1;
    }

    /** ¿Hay una fila con esta clave en un conjunto único, contando lo hecho en esta sentencia? */
    private function claveOcupada(string $tabla, array $indice, string $clave): bool
    {
        if (isset($indice['nuevas'][$clave])) {
            return true;
        }
        if (isset($indice['quitadas'][$clave])) {
            return false;
        }
        if ($indice['def'] !== null) {
            return $indice['vacio'] !== $this->volcados
                && StorageEscritura::claveEnIndice($this->cat->storage(), $tabla, $indice['def'], $clave) === true;
        }
        return isset($indice['mapa'][$clave]);
    }

    protected function comprobarUnicos(string $tabla, array $meta, array $nueva, array &$indices, ?array $vieja): void
    {
        foreach ($this->unicos($meta) as $uq) {
            $clave = self::claveDe($nueva, $uq['columns'], $uq['plegar']);
            if ($clave === null) {
                continue;                       // con algún NULL no se aplica la unicidad
            }
            if ($vieja !== null && self::claveDe($vieja, $uq['columns'], $uq['plegar']) === $clave) {
                continue;                       // no ha cambiado
            }
            if ($this->claveOcupada($tabla, $indices[$uq['name']], $clave)) {
                throw self::duplicado($tabla, $uq);
            }
        }
    }

    protected static function duplicado(string $tabla, array $uq): JsonSqlDbError
    {
        $etiqueta = $uq['name'] === 'PRIMARY' ? 'clave primaria' : "restricción UNIQUE '{$uq['name']}'";
        return JsonSqlDbError::constraint("Valor duplicado en $etiqueta de '$tabla' (" . implode(', ', $uq['columns']) . ')');
    }

    /**
     * El valor por defecto de una columna: el fijo, o el que se calcula ahora
     * (CURRENT_TIMESTAMP, (expresión)). La expresión se analiza una vez.
     *
     * @return mixed
     */
    protected static function porDefecto(array $col)
    {
        $expr = $col['default_expr'] ?? null;
        if ($expr === null) {
            return $col['default'];
        }
        static $analizadas = [];
        $analizadas[$expr] ??= Evaluator::resolver(Parser::analizar('SELECT ' . $expr)['cols'][0]['expr'], []);
        return Evaluator::evaluar($analizadas[$expr], ['fila' => []]);
    }

    /**
     * Clave compuesta de una fila; null si alguna columna es NULL. Es la misma
     * clave que usan los índices de disco, para poder comprobar contra ellos.
     *
     * @param array<int, ?string> $plegar la colación de cada columna (2.9: ver Catalog::plegarDe())
     * @param list<string>        $afin   la afinidad con la que se comparan (2.10): la de las
     *                                    columnas del padre en una clave foránea (ver afinDelPadre())
     */
    protected static function claveDe(array $fila, array $cols, array $plegar = [], array $afin = []): ?string
    {
        if ($plegar !== [] || $afin !== []) {
            return Indexes::claveFila($fila, $cols, $plegar, $afin);
        }
        $valores = [];
        foreach ($cols as $c) {
            $valores[] = $fila[$c] ?? null;
        }
        return Indexes::clave($valores);
    }

    /** @var array<string, array<int, ?string>> la colación de las columnas a las que apunta cada clave foránea (2.9) */
    private array $plegarFk = [];

    /** La colación de las columnas del padre de una clave foránea, que es con la que se compara (2.9); una vez por sentencia */
    protected function plegarDelPadre(array $fk): array
    {
        return $this->plegarFk[strtolower((string)$fk['table']) . "\0" . implode("\0", $fk['references'])]
            ??= Catalog::plegarDe($this->meta($fk['table']), $fk['references']);
    }

    /** @var array<string, list<string>> la afinidad de las columnas a las que apunta cada clave foránea (2.10) */
    private array $afinFk = [];

    /**
     * La afinidad de las columnas del padre de una clave foránea (2.10): un
     * valor de la hija se compara con ella, como en SQLite ('7' en la hija es
     * el 7 de un padre INTEGER). Vacía si es la misma que la de la hija, que
     * es lo corriente: entonces las claves de las dos ya son comparables.
     */
    protected function afinDelPadre(array $fk, array $metaHija): array
    {
        $id = strtolower((string)$metaHija['table'] . "\0" . implode("\0", $fk['columns']) . "\0" . $fk['table'] . "\0" . implode("\0", $fk['references']));
        return $this->afinFk[$id] ??= (function () use ($fk, $metaHija): array {
            $padre = Catalog::afinidadesDe($this->meta($fk['table']), $fk['references']);
            return $padre === Catalog::afinidadesDe($metaHija, $fk['columns']) ? [] : $padre;
        })();
    }

    /**
     * Un UPDATE en las claves únicas: las que cambian salen y entran; las que
     * no, no se apuntan, que siguen en su índice (2.9: se apuntaban todas, y
     * con cien mil filas eran decenas de MB).
     */
    protected function cambiarEnIndices(array $meta, array $vieja, array $nueva, array &$indices): void
    {
        foreach ($this->unicos($meta) as $uq) {
            $antes = self::claveDe($vieja, $uq['columns'], $uq['plegar']);
            $ahora = self::claveDe($nueva, $uq['columns'], $uq['plegar']);
            if ($antes === $ahora) {
                continue;
            }
            if ($antes !== null) {
                $indices[$uq['name']]['quitadas'][$antes] = true;
                unset($indices[$uq['name']]['nuevas'][$antes]);
            }
            if ($ahora !== null) {
                $indices[$uq['name']]['nuevas'][$ahora] = true;
                unset($indices[$uq['name']]['quitadas'][$ahora]);
            }
        }
    }

    // ==================================================================
    // Claves foráneas
    // ==================================================================

    /**
     * @var array<string, array<string, array>> lo que una clave foránea deja
     * por mirar al acabar la sentencia (2.10, ver WriterForaneas::apuntar())
     */
    protected array $foraneasPendientes = [];

    /**
     * Lado hijo: el valor insertado o actualizado debe existir en la tabla
     * padre. Con $fks, solo esas claves foráneas de la tabla (ver
     * WriterForaneas::cambiarHijas()).
     * Si no está, se mira otra vez al acabar la sentencia, como en SQLite: el
     * padre puede llegar después en la misma sentencia (2.10: era un error al
     * momento, y una fila que apuntaba a otra de detrás del mismo INSERT no entraba)
     */
    protected function comprobarForaneas(string $tabla, array $meta, array $fila, ?array $fks = null): void
    {
        foreach ($fks ?? $meta['foreign_keys'] as $fk) {
            // Con la colación de las columnas del padre, como en SQLite (2.9), y su afinidad (2.10)
            $plegar = $this->plegarDelPadre($fk);
            $afin   = $this->afinDelPadre($fk, $meta);
            $clave  = self::claveDe($fila, $fk['columns'], $plegar, $afin);
            if ($clave === null) {
                continue;                       // con NULL no se comprueba
            }
            if (!WriterForaneas::padreTiene($this, $fk['table'], $fk['references'], $clave, $plegar)) {
                WriterForaneas::sinPadre($this, $tabla, $fk, $fila, $clave, $plegar, $afin);
            }
        }
    }

    /**
     * Lado padre: aplica ON DELETE / ON UPDATE a las filas hijas, con la fila
     * padre ya escrita o ya borrada, como SQLite (2.9; salvo la que quita un
     * OR REPLACE, ver WriterInsercion::insertar()).
     * $nueva a null significa que la fila padre se está borrando.
     */
    protected function propagarHijos(string $tabla, array $meta, array $vieja, ?array $nueva): void
    {
        // Qué claves foráneas apuntan a la tabla, una vez por sentencia: esto
        // se pregunta en cada fila que se escribe, y antes recorría cada vez
        // todas las tablas (2.9)
        foreach ($this->deMeta[$meta['table']]['hijas'] ??= $this->clavesHacia($tabla) as [$hija, $fk]) {
            $plegar     = $this->plegarDelPadre($fk);                       // la del padre (2.9)
            $claveVieja = self::claveDe($vieja, $fk['references'], $plegar);
            if ($claveVieja === null) {
                continue;
            }
            if ($nueva !== null && self::claveDe($nueva, $fk['references'], $plegar) === $claveVieja) {
                continue;                   // la clave referenciada no cambia
            }

            $accion   = $nueva === null ? $fk['on_delete'] : $fk['on_update'];
            $metaHija = $this->meta($hija);
            // Con un índice sobre la clave foránea de la hija, sus filas se
            // leen por él y la tabla hija no se carga (ver WriterForaneas::hijasSinCargar())
            // Con la afinidad del padre, si la de la hija es otra (2.10, ver afinDelPadre())
            $afin      = $this->afinDelPadre($fk, $metaHija);
            $sinCargar = $afin !== [] ? null : WriterForaneas::hijasSinCargar($this, $hija, $metaHija, $fk['columns'], $claveVieja, $plegar);
            $hijas     = $sinCargar ?? WriterForaneas::hijasDe($this, $hija, $fk['columns'], $claveVieja, $plegar, $afin);
            if ($hijas === []) {
                continue;
            }

            if ($accion === 'RESTRICT') {
                throw WriterForaneas::conHijas($hija, $fk, \count($hijas));
            }
            if ($accion === 'NO ACTION') {
                // Al acabar la sentencia, como en SQLite (2.10: al momento, y
                // un INSERT OR REPLACE de un padre con hijas, o borrar una
                // tabla que se apunta a sí misma, daban error)
                WriterForaneas::apuntar($this, $hija, $fk, $claveVieja, $plegar, $afin, \count($hijas), null);
                continue;
            }

            if ($accion === 'CASCADE' && $nueva === null) {
                // Con sus posiciones de verdad: renumeradas (array_values) no
                // coincidían, y cada hija se buscaba recorriendo la tabla
                $sinCargar !== null ? WriterForaneas::borrarHijasSinCargar($this, $hija, $hijas) : WriterForaneas::borrarHijas($this, $hija, $hijas);
                continue;
            }
            WriterForaneas::cambiarHijas($this, $hija, $metaHija, $fk, $accion, $hijas, $nueva);
        }
    }

    /** @return list<array{0: string, 1: array}> cada tabla con una clave foránea hacia $tabla, y esa clave */
    protected function clavesHacia(string $tabla): array
    {
        $out = [];
        foreach ($this->cat->tablasEsquema() as $hija) {
            foreach ($this->meta($hija)['foreign_keys'] as $fk) {
                if (strcasecmp($fk['table'], $tabla) === 0) {
                    $out[] = [$hija, $fk];
                }
            }
        }
        return $out;
    }

    // ==================================================================
    // Triggers
    // ==================================================================

    /**
     * Ejecuta los triggers de una tabla para un momento y un evento. Devuelve
     * NEW tal como lo dejan: un trigger BEFORE INSERT o BEFORE UPDATE puede
     * cambiarlo con SET NEW.col = expr, y lo que se escribe es esa fila.
     */
    protected function lanzarTriggers(string $tabla, string $timing, string $evento, ?array $new, ?array $old): ?array
    {
        $meta = $this->meta($tabla);
        if ($meta['triggers'] === []) {
            return $new;
        }
        if ($this->anidamiento >= self::MAX_ANIDAMIENTO) {
            throw JsonSqlDbError::constraint('Los triggers se están llamando en cadena demasiadas veces');
        }

        foreach ($meta['triggers'] as $trg) {
            if ($trg['timing'] !== $timing || $trg['event'] !== $evento) {
                continue;
            }

            $this->anidamiento++;
            try {
                if ($trg['when'] !== null && !WriterTriggers::cumpleCondicion($this, $trg['when'], $new, $old)) {
                    continue;
                }
                $new = WriterTriggers::ejecutarCuerpo($this, $trg, $trg['body'], $meta, $new, $old);
            } finally {
                $this->anidamiento--;
            }
        }
        return $new;
    }

    // ==================================================================
    // Auxiliares
    // ==================================================================

    /** Las consultas internas ven también los cambios todavía en memoria (ver lectorSub()). */
    protected function seleccionar(array $ast): array
    {
        return (new Select($this->cat, $this->lectorSub()))->ejecutar($ast);
    }

    /**
     * Las subconsultas de un UPDATE o un DELETE. Pueden mirar la fila que se
     * está escribiendo (`WHERE u.tid = t.id`), y entonces se ejecutan con ella;
     * las que no, se ejecutan una vez para toda la sentencia. Antes no podían
     * mirar fuera («Columna desconocida») y las demás se repetían para cada
     * fila. La memoria va por el texto de la subconsulta, no por su número:
     * los triggers ejecutan otras sentencias con este mismo Writer y sus
     * números podrían repetirse.
     *
     * @param array<string,string> $mapa columnas de la tabla que se escribe
     */
    protected function subconsultas(array $mapa): callable
    {
        return function (array $sel, int $sid, array $filaExterna = []) use ($mapa): array {
            $clave = md5(serialize($sel));
            if (isset($this->subsFijas[$clave])) {
                return $this->subsFijas[$clave];
            }
            $marca = Evaluator::$correlacionada;
            Evaluator::$correlacionada = false;
            try {
                $filas = (new Select($this->cat, $this->lectorSub()))->ejecutarExterna($sel, $mapa, $filaExterna);
                $mira  = Evaluator::$correlacionada;
            } finally {
                Evaluator::$correlacionada = $marca;
            }
            if (!$mira) {
                $this->subsFijas[$clave] = $filas;
            }
            return $filas;
        };
    }

    /**
     * El conjunto de valores de una subconsulta con IN, para no recorrerla en
     * cada fila: como subconsultas(), una vez si no mira la fila que se
     * escribe. Con sus valores según salen, sin juntar sus filas (2.9, ver
     * Select::valoresDe()).
     *
     * @param array<string,string> $mapa columnas de la tabla que se escribe
     */
    protected function conjuntos(array $mapa): callable
    {
        return function (array $sel, int $sid, array $filaExterna = [], string|bool|null $colacion = null, ?string $afinidad = null) use ($mapa): array {
            // Dos IN con la misma subconsulta pueden comparar con distinta colación (2.9) y afinidad (2.10)
            $deEste = md5(serialize($sel)) . "\0" . var_export($colacion, true) . "\0" . $afinidad;
            if (isset($this->conjuntosFijos[$deEste])) {
                return $this->conjuntosFijos[$deEste];
            }
            $marca = Evaluator::$correlacionada;
            Evaluator::$correlacionada = false;
            try {
                $select  = new Select($this->cat, $this->lectorSub());
                $valores = $select->valoresExterna($sel, $mapa, $filaExterna);
                $mira    = Evaluator::$correlacionada;
            } finally {
                Evaluator::$correlacionada = $marca;
            }
            $c = IndexesBusqueda::conjuntoDeIn($valores, $colacion, $select->colacionExterna, $afinidad, $select->afinidadExterna);
            if (!$mira) {
                $this->conjuntosFijos[$deEste] = $c;
            }
            return $c;
        };
    }

    /**
     * Cómo leen las tablas las subconsultas de la sentencia: la que esta ya
     * tiene en memoria o con cambios, de ahí, que es como está ahora; las
     * demás, del disco parte a parte (2.9: se cargaban enteras, y un IN
     * (SELECT id FROM t) con un millón de filas no cabía en 256 MB).
     */
    protected function lectorSub(): \Closure
    {
        $st = $this->cat->storage();
        return function (string $t, array $agujas = []) use ($st): iterable {
            $tocadas = array_change_key_case($this->datos + $this->sucioDatos);
            return isset($tocadas[strtolower($t)]) ? $this->filas($t) : $st->filas($t, false, $agujas);
        };
    }

    /** 'col' => 'col' y 'tabla.col' => 'col'; con alias (2.9), 'alias.col' en su lugar */
    protected function mapaColumnas(string $tabla, array $meta, ?string $alias = null): array
    {
        return Catalog::mapaColumnas($alias ?? $tabla, $meta['columns']);
    }

}
