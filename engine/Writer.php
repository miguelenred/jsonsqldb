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
 * Aquí, lo de todas las escrituras y el INSERT. UPDATE y DELETE están en
 * WriterCambios; las claves foráneas, en WriterForaneas; los choques con una
 * clave (REPLACE, ON CONFLICT…), en WriterConflictos; el cuerpo de los
 * triggers, en WriterTriggers, y la DDL, en WriterDdl.
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
    protected array $metas      = [];   // tabla => estructura en memoria
    protected array $sucioDatos = [];
    protected array $astCache   = [];   // sql de trigger => árbol ya analizado
    protected array $idxPadre   = [];   // tabla|cols => definición de índice, o claves recogidas
    /** @var array<string,array> resultados de subconsultas que no miran fuera, por su texto */
    private array $subsFijas = [];
    /** @var array<string,array> sus conjuntos de valores, para IN */
    private array $conjuntosFijos = [];
    /** @var array<string,int> siguiente autoincremento por tabla, movido en esta sentencia */
    protected array $autoinc    = [];
    /** @var array<string,int> siguiente id de una clave primaria entera sin AUTOINCREMENT, en esta sentencia */
    protected array $rowid      = [];
    /** @var array<string, array<int, array>> los CHECK de cada tabla, ya analizados */
    private array $checks     = [];
    private int   $anidamiento = 0;
    /** Memoria en uso a partir de la cual la sentencia escribe lo que lleva (ver tramo()) */
    private int   $tope;
    /** Memoria que puede juntar un INSERT … SELECT antes de meter lo que lleva (ver insertar()) */
    private int   $lote;
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
        if (is_string($destino) && $this->cat->esVista($destino)) {
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
        if (in_array($ast['k'], ['insert', 'update', 'delete'], true)) {
            return $this->despachar($ast);
        }
        $st = $this->cat->storage();
        StorageEscritura::txIniciar($st, Database::operacion($ast));
        $r = $this->despachar($ast);
        StorageEscritura::txConfirmar($st);
        return $r;
    }

    /** Funciones que pasan bytes a texto: dentro de ellas, RANDOMBLOB y ZEROBLOB se pueden guardar */
    private const A_TEXTO = ['HEX', 'QUOTE', 'LENGTH', 'OCTET_LENGTH', 'BIT_LENGTH', 'TO_BASE64', 'MD5', 'SHA1', 'SHA2', 'CRC32', 'TYPEOF'];

    /**
     * Un valor que se va a guardar no puede salir de RANDOMBLOB ni de ZEROBLOB
     * tal cual (2.9): son bytes, y aquí las tablas guardan texto. Se mira en la
     * sentencia, no en los bytes, para que el error no dependa de que unos
     * bytes al azar formen o no un texto válido. Solo si el analizador vio
     * alguna de las dos (la marca 'bytes'): el resto no paga nada.
     */
    private static function exigirTexto(array $ast): void
    {
        $valores = [];
        foreach ($ast['filas'] ?? [] as $fila) {
            array_push($valores, ...$fila);
        }
        foreach ($ast['select']['cols'] ?? [] as $c) {
            $valores[] = $c['expr'] ?? null;
        }
        foreach (array_merge($ast['set'] ?? [], $ast['conflicto']['set'] ?? []) as $s) {
            $valores[] = $s['expr'];
        }
        foreach ($valores as $e) {
            if (is_array($e) && self::daBytes($e)) {
                throw JsonSqlDbError::type('RANDOMBLOB() y ZEROBLOB() dan bytes, que no se pueden guardar en una tabla: '
                    . 'guárdalos como texto, con HEX(…)');
            }
        }
    }

    private static function daBytes(array $n): bool
    {
        if (($n['k'] ?? null) === 'fn') {
            $f = strtoupper((string)$n['nombre']);
            if ($f === 'RANDOMBLOB' || $f === 'ZEROBLOB') {
                return true;
            }
            if (in_array($f, self::A_TEXTO, true)) {
                return false;
            }
        }
        foreach ($n as $hijo) {
            if (is_array($hijo) && self::daBytes($hijo)) {
                return true;
            }
        }
        return false;
    }

    private function despachar(array $ast): array
    {
        if ($ast['k'] !== 'insert' && $ast['k'] !== 'update' && $ast['k'] !== 'delete') {
            return WriterDdl::despachar($this, $ast);
        }
        if (isset($ast['bytes'])) {
            self::exigirTexto($ast);
        }
        $this->devueltas = [];
        $n = $ast['k'] === 'insert' ? $this->insertar($ast) : ($ast['k'] === 'update' ? WriterCambios::actualizar($this, $ast) : WriterCambios::borrar($this, $ast));
        foreach (array_keys($this->porCompactar) as $t) {
            $this->compactar((string)$t);
        }
        $this->porCompactar = [];
        $this->volcar();
        $r = ['filas' => $n, 'mensaje' => "$n fila(s) " . ['insert' => 'insertada(s)', 'update' => 'actualizada(s)', 'delete' => 'eliminada(s)'][$ast['k']]];
        if (($ast['returning'] ?? null) !== null) {
            $r['devueltas'] = $this->devolver($ast['tabla'], $ast['returning'], $ast['alias'] ?? null);
        }
        $this->devueltas = [];
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
                $this->marcarDesde($tabla, count($this->datos[$tabla]));
                $this->datos[$tabla][] = $fila;
            }
            foreach (array_keys($this->borradas[$tabla] ?? []) as $pos) {
                unset($this->datos[$tabla][$pos]);
                $this->marcarDesde($tabla, $pos);
            }
            if (isset($this->borradas[$tabla])) {
                $this->porCompactar[$tabla] = true;      // al terminar la sentencia (ver filaActual())
            }
            // Y lo que un upsert sabía de sus claves sin cargarla: las filas que
            // metía (posiciones negativas) tienen ya su sitio en la tabla. Se
            // vuelve a armar de ella (2.9: se seguía usando, y la fila de una
            // posición negativa ya no estaba)
            unset($this->cambios[$tabla], $this->anexo[$tabla], $this->borradas[$tabla], $this->unicas[$tabla]);
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
     * chocaCon()). Por tabla (2.9): las sentencias de un trigger usan este
     * mismo Writer, y un upsert suyo, o un INSERT, vaciaba lo del de fuera.
     *
     * @var array<string,array<string,array<string,int|false>>>
     */
    protected array $unicas = [];
    /** @var array<string, array<string, ?array>> el índice de cada clave única para chocaEnDisco(), una vez por sentencia */
    protected array $defUnica = [];
    /** @var array<string, array<string, int>> para chocaEnDisco(), el $volcados en que su índice estaba vacío, o -1 */
    protected array $vacioUnica = [];
    /**
     * Las claves únicas que una cascada ha puesto en filas de una tabla sin
     * cargarla, por tabla y clave única: clave → posición (2.9). El índice del
     * disco no las tiene (ver otraConClave()).
     *
     * @var array<string,array<string,array<string,int>>>
     */
    protected array $clavesCascada = [];
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
    private function marcarDesde(string $tabla, int $pos): void
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
     * Añade una fila al final. Nada de lo anterior se mueve, así que solo
     * cambia la última parte. Si la tabla no está en memoria y se puede, la
     * fila se apunta aparte y la tabla no se lee.
     */
    private function anadirFila(string $tabla, array $fila, bool $sinLeer): void
    {
        if ($sinLeer && !isset($this->datos[$tabla])) {
            $this->anexo[$tabla][] = $fila;
        } else {
            $this->filas($tabla);
            $this->marcarDesde($tabla, count($this->datos[$tabla]));
            $this->datos[$tabla][] = $fila;
        }
        $this->sucioDatos[$tabla] = true;
        $this->olvidarPadre($tabla);
        if (isset($this->datos[$tabla])) {
            $this->apuntarHija($tabla, (int)array_key_last($this->datos[$tabla]), $fila);
        }
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
     * una sola vez al terminar, y guardarTabla() lo haría igualmente al escribir.
     */
    protected function quitarFilaEn(string $tabla, int $pos): void
    {
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
            if (strncmp($id, $tabla . '|', strlen($tabla) + 1) === 0) {
                unset($this->idxHijo[$id]);
            }
        }
    }

    private function olvidarPadre(string $tabla): void
    {
        foreach (array_keys($this->idxPadre) as $clave) {
            if (strncmp($clave, $tabla . '|', strlen($tabla) + 1) === 0) {
                unset($this->idxPadre[$clave]);
            }
        }
    }

    /** Una fila escrita en $pos: entra en los mapas de hijas de su tabla con su clave de ahora. */
    private function apuntarHija(string $tabla, int $pos, array $fila): void
    {
        foreach ($this->idxHijo as $id => $i) {
            if (strncmp($id, $tabla . '|', strlen($tabla) + 1) === 0) {
                $k = self::claveDe($fila, $i['cols'], $i['plegar']);
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
     */
    private function volcar(bool $compactar = true): void
    {
        $st     = $this->cat->storage();
        $tablas = array_keys($this->sucioDatos);
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
            StorageEscritura::guardarTabla($st,
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

        $this->anexo      = [];
        $this->cambios    = [];
        $this->borradas   = [];
        $this->sucioDatos = [];
        $this->desde      = [];
        $this->sueltas    = [];
        $this->sabe       = [];
        $this->conMeta    = [];
        $this->cat->olvidar();
    }

    /**
     * Una sentencia que cambia más filas de las que caben en memoria (2.9):
     * cuando la memoria en uso pasa de $tope (casi la mitad de memory_limit),
     * escribe lo que lleva y sigue (con una cuarta parte, sentencias que la
     * 2.8 hacía de una vez se repetían por tramos y tardaban hasta un 60 % más). Solo dentro de una transacción, que la deja ser todo o
     * nada aunque escriba a medias; fuera, pide que se repita dentro (ver
     * DatabaseAvanzada::porTramos()), y eso pasa antes de haber escrito nada. Lo que
     * la sentencia sabía de sus claves únicas queda en los índices del disco.
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
        $this->volcar(false);
        foreach (array_keys($indices) as $n) {
            $indices[$n]['nuevas'] = $indices[$n]['quitadas'] = [];
        }
        $this->unicas = [];                         // lo de la sentencia ya está en los índices
    }

    // ==================================================================
    // INSERT
    // ==================================================================

    protected function insertar(array $ast): int
    {
        $tabla = $ast['tabla'];
        $meta  = $this->meta($tabla);

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
        // IGNORE u OR REPLACE sí se carga: hay que saber con qué fila choca
        // Con ON CONFLICT, OR IGNORE u OR REPLACE la fila con que choca se busca
        // también en el índice (2.9: se cargaba la tabla entera, y con un
        // millón de filas un upsert de una sola no cabía en 256 MB); sin
        // índices que sirvan, en la tabla cargada, como siempre
        $conflicto = $ast['conflicto'] ?? null;
        $devolver  = ($ast['returning'] ?? null) !== null;
        $objetivo  = null;
        $anexar    = $this->sinLeer($tabla, $meta);
        if ($conflicto !== null) {
            if (!$anexar) {
                $this->filas($tabla);
            }
            unset($this->unicas[$tabla], $this->defUnica[$tabla], $this->vacioUnica[$tabla]);
            $objetivo = $conflicto['cols'] === null ? null : WriterConflictos::claveObjetivo($this, $tabla, $meta, $conflicto['cols']);
        }
        $indices = $this->indicesUnicos($tabla, $meta);
        $puestas = 0;
        $borradasPorReemplazo = false;

        $meter = function (array $valores) use ($tabla, $meta, $cols, $conflicto, $objetivo, $anexar, $devolver,
                                                &$indices, &$puestas, &$borradasPorReemplazo): void {
            if ($anexar && !isset($this->datos[$tabla])) {
                $this->tramo($indices);                 // lo que llevan las anteriores, si ya no cabe
            }
            if (count($valores) !== count($cols)) {
                throw JsonSqlDbError::constraint(
                    'El número de valores (' . count($valores) . ') no coincide con el de columnas (' . count($cols) . ')'
                );
            }

            $nueva = [];
            foreach ($meta['columns'] as $c) {
                $nueva[$c['name']] = $c['default_expr'] === null ? $c['default'] : self::porDefecto($c);
            }
            foreach ($cols as $i => $c) {
                if (!(is_array($valores[$i]) && ($valores[$i][0] ?? null) === '__default__')) {
                    $nueva[$c] = $valores[$i];
                }
            }

            $nueva = $this->prepararFila($tabla, $meta, $nueva, true);

            $preparada = $nueva;
            $nueva = $this->lanzarTriggers($tabla, 'BEFORE', 'INSERT', $nueva, null);
            if ($nueva !== $preparada) {
                // Un SET NEW la ha cambiado: otra vez tipos y NOT NULL
                $nueva = $this->prepararFila($tabla, $meta, $nueva, true);
            }
            if ($conflicto !== null) {
                // OR REPLACE la busca abajo, en cada clave única
                $choca = $conflicto['modo'] === 'reemplazar' ? null : WriterConflictos::chocaCon($this, $tabla, $meta, $nueva, $objetivo);
                if ($choca !== null && $conflicto['modo'] === 'nada') {
                    return;                             // OR IGNORE, DO NOTHING
                }
                if ($choca !== null && $conflicto['modo'] === 'actualizar') {
                    if (WriterConflictos::actualizarConflicto($this, $tabla, $meta, $choca, $nueva, $conflicto, $indices, $devolver)) {
                        $puestas++;
                    }
                    return;
                }
                if ($conflicto['modo'] === 'reemplazar') {
                    // Las filas con las que choca, en cualquier clave única, se
                    // borran (con sus acciones de clave foránea, sin triggers:
                    // como SQLite con recursive_triggers desactivado). Aquí la
                    // cascada va antes, no como en un DELETE (ver borrar()): la
                    // fila que entra suele traer la misma clave, y SQLite, que
                    // mira las claves foráneas al acabar la sentencia, acepta
                    // que lo que hagan los triggers de las hijas apunte a ella
                    while (($choca = WriterConflictos::chocaCon($this, $tabla, $meta, $nueva, null)) !== null) {
                        $vieja = WriterConflictos::filaEn($this, $tabla, $choca);
                        $this->propagarHijos($tabla, $meta, $vieja, null);
                        $this->ponerEn($tabla, $choca, null);
                        WriterCambios::quitarDeIndices($this, $meta, $vieja, $indices);
                        WriterConflictos::apuntarUnicas($this, $tabla, $meta, $choca, $vieja, null);
                        $borradasPorReemplazo = $borradasPorReemplazo || isset($this->datos[$tabla]);
                    }
                }
            }
            $this->comprobarUnicos($tabla, $meta, $nueva, $indices, null);
            $this->comprobarForaneas($tabla, $meta, $nueva);
            $this->anadirAIndices($meta, $nueva, $indices);

            $this->anadirFila($tabla, $nueva, $anexar);
            $puestas++;
            if ($conflicto !== null) {
                WriterConflictos::apuntarUnicas($this, $tabla, $meta, isset($this->datos[$tabla])
                    ? (int)array_key_last($this->datos[$tabla]) : -1 - (int)array_key_last($this->anexo[$tabla]), null, $nueva);
            }
            if ($devolver) {
                $this->devueltas[] = $nueva;
            }

            $this->lanzarTriggers($tabla, 'AFTER', 'INSERT', $nueva, null);
        };

        // Valores de origen: VALUES(...) o SELECT. Un SELECT de otras tablas va
        // por lotes según sale, sin juntar su resultado (2.9: un INSERT …
        // SELECT de un millón de filas lo tenía entero en memoria); si lee la
        // tabla en la que se inserta, entero antes, que no vea lo que se mete
        if ($ast['select'] !== null) {
            $tablas = Select::tablasDe($ast['select'], $this->cat);
            $enMemoria = $tablas === null || array_intersect_key(array_change_key_case($this->sucioDatos + $this->datos),
                array_change_key_case(array_flip($tablas))) !== [];
            if ($enMemoria || in_array(strtolower($tabla), array_map('strtolower', $tablas), true)) {
                // Cada fila se suelta al meterla: con muchas, lo que lleva la
                // sentencia crece según el origen mengua (ver tramo())
                $origen = $this->seleccionar($ast['select']);
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
                $limite  = $this->lote;
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
                    if (count($lote) === 8192 || memory_get_usage() - $inicio > $limite) {
                        $soltar();
                    }
                };
                foreach ((new Select($this->cat))->ejecutarHacia($ast['select'], $porFila) ?? [] as $fila) {
                    $porFila($fila);
                }
                $soltar();
            }
        } else {
            // Todos los valores antes de meter ninguno: una subconsulta de la
            // tabla no ve las filas de esta misma sentencia
            $origen = [];
            $ctx = ['fila' => [], 'sub' => fn(array $s): array => $this->seleccionar($s)];
            foreach ($ast['filas'] as $fila) {
                $valores = [];
                foreach ($fila as $e) {
                    // Un literal, o un número negativo, es su valor: sin pasar
                    // por Evaluator, que ni se carga
                    $n = $e['k'] === 'un' && $e['op'] === '-' && $e['e']['k'] === 'lit' ? $e['e']['v'] : null;
                    $valores[] = match (true) {
                        $e['k'] === 'lit'           => $e['v'],
                        $e['k'] === 'default'       => ['__default__'],
                        is_int($n) || is_float($n)  => -$n,
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
            $this->porCompactar[$tabla] = true;  // al terminar la sentencia (ver filaActual())
        }
        unset($this->unicas[$tabla], $this->defUnica[$tabla], $this->vacioUnica[$tabla]);
        return $puestas;
    }

    /**
     * Catalog::conjuntosUnicos() de la tabla, una vez por sentencia: se pide
     * varias veces por cada fila que se escribe, y armarlo cada vez era una
     * parte apreciable de un INSERT de muchas filas (2.9).
     */
    protected function unicos(array $meta): array
    {
        return $this->deMeta[$meta['table']]['unicos'] ??= Catalog::conjuntosUnicos($meta);
    }

    /** Cambia (o borra, con null) la fila de una posición, con la tabla en memoria o sin cargarla. */
    protected function ponerEn(string $tabla, int $pos, ?array $fila): void
    {
        if (isset($this->datos[$tabla])) {
            $fila === null ? $this->quitarFilaEn($tabla, $pos) : $this->ponerFilaEn($tabla, $pos, $fila);
            return;
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
        }
        $this->sucioDatos[$tabla] = true;
    }

    /**
     * RETURNING: las filas escritas, proyectadas como las columnas de un
     * SELECT sobre la tabla (* son todas, en su orden).
     *
     * @return list<array>
     */
    private function devolver(string $tabla, array $columnas, ?string $alias): array
    {
        $meta = $this->meta($tabla);
        $mapa = $this->mapaColumnas($tabla, $meta, $alias);
        $out  = [];
        foreach ($this->devueltas as $fila) {
            $r = [];
            foreach ($columnas as $c) {
                if ($c['star']) {
                    foreach ($meta['columns'] as $col) {
                        $r[$col['name']] = $fila[$col['name']] ?? null;
                    }
                    continue;
                }
                $r[$c['alias'] ?? Select::etiqueta($c['expr'])] = Evaluator::evaluar(Evaluator::resolver($c['expr'], $mapa), ['fila' => $fila]);
            }
            $out[] = $r;
        }
        return $out;
    }

    // ==================================================================
    // Validación de filas
    // ==================================================================

    /** Aplica autoincremento, convierte tipos y comprueba NOT NULL. */
    protected function prepararFila(string $tabla, array $meta, array $fila, bool $esInsert): array
    {
        // Una clave primaria de una sola columna entera, sin AUTOINCREMENT, se
        // numera sola como en SQLite (2.9): sin valor, la mayor que hay + 1.
        // Una vez por sentencia (ver unicos())
        [$auto, $rowid] = $this->deMeta[$meta['table']]['auto'] ??= (static function (array $meta): array {
            $auto = Catalog::columnaAutoincremento($meta);
            $pk   = Catalog::clavePrimaria($meta);
            return [$auto, $auto === null && count($pk) === 1 ? $pk[0] : null];
        })($meta);

        foreach ($meta['columns'] as $c) {
            $nombre = $c['name'];
            $valor  = $fila[$nombre] ?? null;

            if ($valor === null && $esInsert && $auto !== null && $nombre === $auto) {
                $valor = $this->siguienteAutoincremento($tabla);
            }
            $esRowid = $esInsert && $nombre === $rowid && $c['type'] === 'INTEGER';
            if ($valor === null && $esRowid) {
                $valor = $this->siguienteRowid($tabla, $meta, $nombre);
            }
            if ($valor !== null) {
                $valor = Types::cast($valor, $c);
                if ($auto !== null && $nombre === $auto && $esInsert) {
                    $this->ajustarAutoincremento($tabla, (int)$valor);
                }
                if ($esRowid && isset($this->rowid[$tabla]) && is_int($valor) && $valor >= $this->rowid[$tabla]) {
                    $this->rowid[$tabla] = $valor + 1;
                }
            }
            if ($valor === null && $c['notnull']) {
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
        foreach ($meta['checks'] ?? [] as $i => $ck) {
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
     * El contador de autoincremento no ensucia la estructura: se lleva aparte
     * y al volcar va a rev.json (ver StorageEscritura::ponerAutoincremento()).
     */
    private function siguienteAutoincremento(string $tabla): int
    {
        $n = $this->autoinc[$tabla] ?? (int)$this->meta($tabla)['autoincrement']['next'];
        $this->autoinc[$tabla] = $n + 1;
        return $n;
    }

    /**
     * La siguiente de una clave primaria entera sin AUTOINCREMENT: la mayor
     * que hay —por su índice, que la da sin leer la tabla; si no se puede,
     * recorriéndola—, con lo que esta sentencia lleva añadido, + 1. Como en
     * SQLite, puede volver a dar la de una fila borrada que era la mayor: para
     * no repetir nunca, AUTOINCREMENT.
     */
    private function siguienteRowid(string $tabla, array $meta, string $col): int
    {
        if (!isset($this->rowid[$tabla])) {
            $mayor = 0;
            $hay   = static function ($v) use (&$mayor): void {
                if (is_int($v) && $v > $mayor) {
                    $mayor = $v;
                }
            };
            if (isset($this->datos[$tabla])) {
                foreach ($this->datos[$tabla] as $f) {
                    $hay($f[$col] ?? null);
                }
            } else {
                $st  = $this->cat->storage();
                $def = $this->indiceDe($tabla, $meta, [$col]);
                $top = $def === null ? null : StorageLectura::filasPorOrden($st, $tabla, $def, true, 1);
                foreach ($top ?? $st->filas($tabla) as $f) {
                    $hay($f[$col] ?? null);
                }
                foreach ($this->anexo[$tabla] ?? [] as $f) {
                    $hay($f[$col] ?? null);
                }
            }
            $this->rowid[$tabla] = $mayor + 1;
        }
        return $this->rowid[$tabla]++;
    }

    private function ajustarAutoincremento(string $tabla, int $valor): void
    {
        $meta = $this->meta($tabla);
        if ($meta['autoincrement'] === null) {
            return;
        }
        $n = $this->autoinc[$tabla] ?? (int)$meta['autoincrement']['next'];
        if ($valor >= $n) {
            $this->autoinc[$tabla] = $valor + 1;
        }
    }

    /**
     * ¿Se puede insertar en esta tabla sin leerla? Hace falta que no esté ya
     * en memoria, que no tenga triggers —que podrían consultarla—, que no se
     * referencie a sí misma, y que cada conjunto único tenga índice en disco
     * para comprobar la unicidad contra él. Con $aunqueCambiada, aunque la
     * sentencia ya tenga cambios suyos sin escribir (ver hijasSinCargar()).
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

    private function anadirAIndices(array $meta, array $fila, array &$indices): void
    {
        foreach ($this->unicos($meta) as $uq) {
            $clave = self::claveDe($fila, $uq['columns'], $uq['plegar']);
            if ($clave !== null) {
                $indices[$uq['name']]['nuevas'][$clave] = true;
                unset($indices[$uq['name']]['quitadas'][$clave]);
            }
        }
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
     */
    protected static function claveDe(array $fila, array $cols, array $plegar = []): ?string
    {
        if ($plegar !== []) {
            return Indexes::claveFila($fila, $cols, $plegar);
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
    private function plegarDelPadre(array $fk): array
    {
        return $this->plegarFk[strtolower((string)$fk['table']) . "\0" . implode("\0", $fk['references'])]
            ??= Catalog::plegarDe($this->meta($fk['table']), $fk['references']);
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
     * Lado hijo: el valor insertado o actualizado debe existir en la tabla
     * padre. Con $fks, solo esas claves foráneas de la tabla (ver cambiarHijas()).
     */
    protected function comprobarForaneas(string $tabla, array $meta, array $fila, ?array $fks = null): void
    {
        foreach ($fks ?? $meta['foreign_keys'] as $fk) {
            // Con la colación de las columnas del padre, como en SQLite (2.9)
            $plegar = $this->plegarDelPadre($fk);
            $clave  = self::claveDe($fila, $fk['columns'], $plegar);
            if ($clave === null) {
                continue;                       // con NULL no se comprueba
            }
            if (!WriterForaneas::padreTiene($this, $fk['table'], $fk['references'], $clave, $plegar)) {
                $valores = [];
                foreach ($fk['columns'] as $c) {
                    $valores[] = Valor::aTexto($fila[$c]);
                }
                throw JsonSqlDbError::constraint(
                    "'$tabla." . implode(', ', $fk['columns']) . "' = (" . implode(', ', $valores) .
                    ") no existe en '{$fk['table']}' (clave foránea '{$fk['name']}')"
                );
            }
        }
    }

    /**
     * Lado padre: aplica ON DELETE / ON UPDATE a las filas hijas, con la fila
     * padre ya escrita o ya borrada, como SQLite (2.9; salvo la que quita un
     * OR REPLACE, ver insertar()).
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
            // leen por él y la tabla hija no se carga (ver hijasSinCargar())
            $sinCargar = WriterForaneas::hijasSinCargar($this, $hija, $metaHija, $fk['columns'], $claveVieja, $plegar);
            $hijas     = $sinCargar ?? WriterForaneas::hijasDe($this, $hija, $fk['columns'], $claveVieja, $plegar);
            if ($hijas === []) {
                continue;
            }

            if ($accion === 'NO ACTION' || $accion === 'RESTRICT') {
                throw JsonSqlDbError::constraint(
                    "'$hija' tiene " . count($hijas) . " fila(s) que dependen de esta (clave foránea '{$fk['name']}')"
                );
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
        foreach ($this->cat->tablas() as $hija) {
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
        return function (array $sel, int $sid, array $filaExterna = [], string|bool|null $colacion = null) use ($mapa): array {
            // Dos IN con la misma subconsulta pueden comparar con distinta colación (2.9)
            $deEste = md5(serialize($sel)) . "\0" . var_export($colacion, true);
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
            $c = IndexesBusqueda::conjuntoDeIn($valores, $colacion, $select->colacionExterna);
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
