<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que el almacenamiento solo hace al escribir filas: tablas, partes e
 * índices en disco con su journal, y la caché que deja atrás cada
 * escritura. Cambiar o quitar filas ya escritas (UPDATE y DELETE) está en
 * StorageCambios, y lo que hace pocas veces (estructura, transacciones,
 * recuperación tras un corte, reparación, bases), en StorageMantenimiento.
 * Métodos de Storage que reciben el almacenamiento en $st. Van aparte para
 * que una lectura no tenga que compilarlos (2.9): sin OPcache, PHP compila
 * en cada petición lo que carga. Hereda de Storage solo para llegar a lo
 * protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageEscritura extends Storage
{
    /** Trozos de índice de una escritura que van a la caché al confirmarla (ver escribirTrozo()) */
    private const TROZOS_A_CACHE = 64;
    /** Lo que una sentencia de una transacción escribe en su sitio, como mucho (ver escribirAtomico()) */
    private const EN_SITIO_MAX = 1048576;

    private function __construct()
    {
    }

    // ------------------------------------------------------------------
    // Bloqueo
    // ------------------------------------------------------------------

    /** ¿Se tiene el bloqueo exclusivo de esta tabla en concreto, o se escribe por partes? */
    public static function tieneExclusivoDe(Storage $st, string $tabla): bool
    {
        return ($st->lockExclusivo && isset($st->locksTabla[$tabla])) || $st->modoPartes === $tabla;
    }

    public static function enEscritura(Storage $st): bool
    {
        return $st->lockNivel > 0 && ($st->lockExclusivo || $st->modoPartes !== null);
    }

    public static function exigirEscritura(Storage $st): void
    {
        if (!self::enEscritura($st)) {
            throw JsonSqlDbError::lock('Operación de escritura sin bloqueo exclusivo');
        }
    }

    /** Fichero del manifiesto de un ámbito (desde la 2.7): un solo fichero, sin carpeta propia. */
    public static function ficheroJournal(Storage $st, ?string $tabla): string
    {
        return $st->dirTx . '/' . ($tabla ?? '_base') . '.json';
    }

    /** ¿Hay una escritura abierta por este proceso? */
    public static function txAbierta(Storage $st): bool
    {
        return $st->txAmbito !== null;
    }

    /**
     * Abre una escritura de varios ficheros. Todo lo que se guarde hasta
     * txConfirmar() se queda en temporales y se pone en su sitio de golpe.
     *
     * $ambito es la tabla cuyo bloqueo exclusivo se tiene, o null si se tiene
     * el de la base entera.
     */
    public static function txIniciar(Storage $st, string $operacion, ?string $ambito = null): void
    {
        self::exigirEscritura($st);
        if ($st->txAmbito !== null) {
            throw JsonSqlDbError::lock('Ya hay una escritura abierta');
        }
        self::exigirEntera($st);
        if ($ambito !== null && !isset($st->locksTabla[$ambito])) {
            throw JsonSqlDbError::lock("Escritura acotada a '$ambito' sin su bloqueo");
        }
        $st->txAmbito    = $ambito ?? '_base';
        $st->txOperacion = $operacion;
    }

    /**
     * Nada más en una transacción que ya solo se puede deshacer (ver
     * loteAMedias()): ninguna escritura, y tampoco una lectura (2.10), que
     * vería lo escrito a medias.
     */
    public static function exigirEntera(Storage $st): void
    {
        if (isset($st->lote['rota'])) {
            throw JsonSqlDbError::io('Una escritura de la transacción se quedó a medias: la transacción ya solo se puede deshacer');
        }
    }

    /**
     * En una transacción, una escritura que se queda a medias (un renombrado
     * que falla, o una sentencia que falla después de escribir parte por
     * tramos: 2.10, ver Writer::tramo()): la transacción se deshace entera,
     * con los originales ya guardados, y no se puede confirmar.
     */
    public static function loteAMedias(Storage $st): void
    {
        if ($st->lote !== null) {
            $st->lote['rota'] = true;
        }
    }

    /**
     * Pone en su sitio todo lo escrito desde txIniciar().
     *
     * Con un solo fichero en juego el rename ya es atómico y no hace falta
     * journal. Con más, se escribe el manifiesto —de una pieza y forzado a
     * disco— antes de tocar nada: a partir de ahí la operación es irrevocable
     * y, si el proceso muere, se termina al abrir la base.
     */
    public static function txConfirmar(Storage $st): void
    {
        if ($st->txAmbito === null) {
            return;
        }
        if ($st->modoPartes !== null) {
            StorageCambios::confirmarPartes($st);
        }
        $ambito = $st->txAmbito === '_base' ? null : $st->txAmbito;
        $tablas = array_keys($st->txTablas);
        if ($ambito !== null) {
            foreach ($tablas as $t) {
                if (!isset($st->locksTabla[$t])) {
                    throw JsonSqlDbError::lock("Escritura acotada a '$ambito' que toca '$t' sin su bloqueo");
                }
            }
        }

        if ($st->lote !== null) {
            // Los originales de la transacción, antes del manifiesto (2.9): con
            // el manifiesto ya escrito, un corte aquí rehacía la sentencia y la
            // transacción ya no la podía deshacer
            StorageMantenimiento::loteGuardar($st, array_merge(array_values($st->txRenombrar), array_keys($st->txBorrar)));
        }
        $manifiesto = null;
        $varios     = \count($st->txRenombrar) + \count($st->txBorrar) + \count($st->txEnSitio) > 1;
        // Dentro de una transacción no hace falta (2.10): si el proceso muere a
        // mitad, al abrir la base se devuelven los originales que acaban de
        // guardarse, y el diario solo era un fichero y un fsync más por sentencia
        if ($varios && $st->lote === null) {
            $manifiesto = self::ficheroJournal($st, $ambito);
            if (!is_dir($st->dirTx) && !@mkdir($st->dirTx, 0775, true) && !is_dir($st->dirTx)) {
                throw JsonSqlDbError::io('No se puede crear la carpeta del journal');
            }
            $renombrar    = [];
            $regenerables = [];
            foreach ($st->txRenombrar as $tmp => $f) {
                $renombrar[basename((string)$tmp)] = basename($f);
                if (isset($st->txRegenerables[$tmp])) {
                    $regenerables[] = basename($f);
                }
            }
            // El manifiesto va forzado a disco junto con su entrada en .tx/: a
            // partir de aquí la escritura es irrevocable
            self::volcarFichero($manifiesto, json_encode([
                'tipo'         => 'redo',
                'operacion'    => $st->txOperacion,
                'ambito'       => $ambito,
                'tablas'       => $tablas,
                'renombrar'    => $renombrar,
                'borrar'       => array_map('basename', array_keys($st->txBorrar)),
                'regenerables' => $regenerables,
                'ts'           => date('Y-m-d H:i:s'),
            ], self::JSON_META) . "\n");
        }

        try {
            foreach ($st->txEnSitio as $f => $texto) {
                $fh = @fopen((string)$f, 'c+');
                if ($fh === false || @fwrite($fh, $texto) !== \strlen($texto) || !@ftruncate($fh, \strlen($texto))) {
                    throw JsonSqlDbError::io('No se puede escribir ' . basename((string)$f));
                }
                fclose($fh);
            }
            self::rehacer($st, $st->txRenombrar, array_keys($st->txBorrar), $st->txRegenerables);
            if ($st->lote !== null) {
                foreach ($st->txRenombrar as $f) {
                    $st->lote['propios'][basename($f)] = true;   // ya no es el inodo del original
                }
            }
        } catch (\Throwable $e) {
            if ($manifiesto !== null) {
                // Ya es irrevocable: sus temporales se quedan y se termina al
                // abrir la base (2.9: se borraban, y la base ya no se abría)
                $st->txRenombrar = $st->txRegenerables = $st->txBorrar = [];
            } elseif ($varios || $st->txEnSitio !== []) {
                // Con lo de varios ficheros ya puesto en parte, o uno escrito
                // en su sitio a medias: la transacción solo se puede deshacer.
                // Un solo renombrado que falla deja el fichero como estaba
                self::loteAMedias($st);
            }
            throw $e;
        }

        if ($manifiesto !== null) {
            // Sin forzarlo a disco: un manifiesto que sobrevive a un corte se
            // vuelve a aplicar, y aplicarlo dos veces es lo mismo que una
            @unlink($manifiesto);
        }
        foreach ([$st->txCache, $st->txHuellas] as $entradas) {
            foreach ($entradas as $c) {
                // Con el sello del fichero ya en su sitio (ver Storage::leerMeta()
                // y Storage::trozoIndice()); sin fichero, tal cual
                $st->cacheGuardar($c[0], isset($c[2]) ? ['s' => self::sello($c[2]), $c[3] ?? 'm' => $c[1]] : $c[1]);
            }
        }
        foreach ($st->txPartes as $clave => $partes) {
            $st->cacheGuardar($clave, array_map(static fn(array $p): array => [self::sello($p[1]), $p[0]], $partes));
        }
        $st->partesMemo = [];                    // los ficheros de datos han cambiado
        self::desmarcar($st);                    // ya no queda ningún temporal suyo
        $st->txAmbito = null;
        $st->txPropia = false;
        $st->txRenombrar = $st->txRegenerables = $st->txBorrar = $st->txTablas = $st->txCache = $st->txHuellas = $st->txPartes = $st->txEnSitio = [];
    }

    /**
     * Las tablas, para ver quién apunta a quién al escribir (2.10): sin mirar
     * la carpeta mientras _database.json siga igual, porque el DDL que crea o
     * borra una tabla lo reescribe y cuenta en él cuántas veces ha cambiado el
     * esquema (ver StorageMantenimiento::anotarEsquema()). Con 200.000 filas
     * la carpeta tiene 800 ficheros, y un UPDATE la miraba tres veces. Por si
     * alguien copió una tabla a mano o la tocó una versión anterior, se mira
     * igual en una de cada 32 escrituras de la tabla, y si falta alguna.
     *
     * @return list<string>
     */
    public static function tablasEsquema(Storage $st): array
    {
        // Con el sello del fichero y su cuenta: dos reescrituras en el mismo
        // segundo pueden dejarlo con el mismo tamaño y un inodo reutilizado
        $f      = $st->dir . '/_database.json';
        $sello  = self::sello($f);
        $sello  = $sello === '' ? '' : $sello . ':' . (preg_match('/"esquema": *(\d+)/', (string)@file_get_contents($f), $m) ? $m[1] : '0');
        $clave  = $st->prefijo . '.base:t:0';
        $mirar  = $sello === '' || ($st->aEscribir !== null && $st->existe($st->aEscribir)
            && ((int)($st->estado($st->aEscribir)['rev'] ?? 0) + 1) % 32 === 0);
        $v = self::$tablasProceso[$st->dir] ?? null;
        if (!$mirar && $v !== null && $v['s'] === $sello) {
            return $v['t'];
        }
        // De la caché compartida, si siguen todas ahí (una borrada a mano: se mira la carpeta)
        $v = $mirar ? null : $st->cacheLeer($clave);
        if (\is_array($v) && ($v['s'] ?? null) === $sello && \is_array($v['t'] ?? null)) {
            foreach ($v['t'] as $t) {
                if (!is_file($st->ficheroMeta((string)$t))) {
                    $v = null;
                    break;
                }
            }
            if ($v !== null) {
                self::$tablasProceso[$st->dir] = $v;
                return $v['t'];
            }
        }
        $v = ['s' => $sello, 't' => $st->tablas()];
        if ($sello !== '') {
            self::$tablasProceso[$st->dir] = $v;
            $st->cacheGuardar($clave, $v);
        }
        return $v['t'];
    }

    /**
     * @var array<string, array{s: string, t: list<string>}> carpeta de la base
     * => sus tablas (ver tablasEsquema()). Pública para que la olvide
     * StorageMantenimiento::anotarEsquema(), sin compilarla en cada lectura
     */
    public static array $tablasProceso = [];

    /** Apunta la tabla que va a escribir la sentencia que empieza, o null al acabar (ver Storage::leerMeta()) */
    public static function escribiendo(Storage $st, ?string $tabla): void
    {
        $st->aEscribir = $tabla === null ? null : strtolower($tabla);
    }

    /** Tira una escritura que no se confirmó: los temporales sobran, los datos siguen intactos. */
    public static function txAbandonar(Storage $st): void
    {
        foreach (array_keys($st->txRenombrar) as $tmp) {
            @unlink((string)$tmp);
        }
        foreach (array_keys($st->txTablas) as $t) {
            unset($st->estados[$t], $st->indicesMemo[$t]);   // lo subido en memoria no llegó al disco
        }
        $st->partesMemo = [];
        $st->autoincPendiente = [];
        self::desmarcar($st);
        $st->txAmbito = null;
        $st->txPropia = false;
        $st->txRenombrar = $st->txRegenerables = $st->txBorrar = $st->txTablas = $st->txCache = $st->txHuellas = $st->txPartes = $st->txEnSitio = [];
    }

    /**
     * Pone cada temporal en su sitio y borra lo que sobra. Idempotente: se
     * puede repetir tras un corte hasta que termine.
     *
     * Un temporal regenerable —un trozo de índice— que no llegó al disco no
     * detiene nada: su fichero queda sin escribir y el índice se rehace en
     * la siguiente escritura (ver trozoSano()); hasta entonces se recorre.
     *
     * @param array<string,string> $renombrar    temporal => definitivo (rutas completas)
     * @param list<string>         $borrar       rutas completas
     * @param array<string,true>   $regenerables temporales cuyo contenido se puede rehacer
     */
    public static function rehacer(Storage $st, array $renombrar, array $borrar, array $regenerables = []): void
    {
        $poner = static function (array $lista) use ($regenerables): void {
            foreach ($lista as $tmp => $fichero) {
                $tmp = (string)$tmp;
                if (!is_file($tmp)) {
                    if (is_file($fichero) || isset($regenerables[$tmp])) {
                        continue;                 // ya se había renombrado, o se puede rehacer
                    }
                    throw JsonSqlDbError::io('Falta el temporal de ' . basename($fichero) . ' al rehacer la escritura');
                }
                if (!@rename($tmp, $fichero) && !self::reemplazar($tmp, $fichero)) {
                    throw JsonSqlDbError::io('No se puede reemplazar ' . basename($fichero));
                }
            }
        };
        // _database.json, el último, después de borrar (2.10): quien lea su
        // cuenta de cambios del esquema ve ya las tablas como quedan, y no
        // guarda una lista sin la tabla nueva o con la borrada (ver tablasEsquema())
        $esquema = array_filter($renombrar, static fn(string $f): bool => basename($f) === '_database.json');
        $poner(array_diff_key($renombrar, $esquema));
        foreach ($borrar as $f) {
            @unlink($f);
        }
        $poner($esquema);
        // El contenido ya está en el disco; ahora los nombres. En una
        // transacción, todo junto al confirmarla (2.10)
        if ($st->lote === null) {
            self::fsyncDir($st->dir);
        }
    }

    // ------------------------------------------------------------------
    // Tablas
    // ------------------------------------------------------------------

    /**
     * Fija el siguiente valor de autoincremento de una tabla para la
     * escritura en curso: va a rev.json, que se escribe de todas formas, y
     * así un INSERT no reescribe también la estructura (ni la fuerza a
     * disco) solo por mover un contador.
     */
    public static function ponerAutoincremento(Storage $st, string $tabla, int $siguiente): void
    {
        self::exigirEscritura($st);
        $st->autoincPendiente[$tabla] = $siguiente;
    }

    // ------------------------------------------------------------------
    // Lectura de filas
    // ------------------------------------------------------------------

    /**
     * Todas las filas de una tabla en un array, por su posición: sin los
     * huecos de las filas borradas, que dejan saltos en las claves (2.9).
     *
     * @return array<int, array>
     */
    public static function leerFilas(Storage $st, string $tabla, bool $sinCache = false): array
    {
        $filas = [];
        foreach ($st->filas($tabla, $sinCache) as $pos => $fila) {
            $filas[$pos] = $fila;
        }
        return $filas;
    }

    /**
     * Unas filas por su posición, en orden, agrupadas por la parte en que
     * quedan: parte (base 0) => posición => fila. Es como escribirIndice()
     * rehace un índice sin tener la tabla entera en memoria (2.9): de una
     * tabla de un millón de filas, CREATE INDEX necesitaba 730 MB.
     *
     * @param iterable<int, array> $filas posición => fila
     * @return \Generator<int, array<int, array>>
     */
    public static function enPartes(iterable $filas, int $chunk): \Generator
    {
        $parte  = null;
        $bloque = [];
        foreach ($filas as $pos => $fila) {
            if (intdiv($pos, $chunk) !== $parte) {
                if ($bloque !== []) {
                    yield $parte => $bloque;
                }
                $parte  = intdiv($pos, $chunk);
                $bloque = [];
            }
            $bloque[$pos] = $fila;
        }
        if ($bloque !== []) {
            yield $parte => $bloque;
        }
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    /**
     * Añade filas al final de una tabla sin leerla entera: se reescribe la
     * última parte y las que hagan falta, y los índices se amplían con las
     * nuevas. Si el estado de la tabla no permite afirmar dónde acaba, se
     * vuelve al camino normal.
     *
     * @param list<array> $nuevas
     * @param list<array{name: string, columns: list<string>, auto: bool}> $definiciones
     */
    public static function anadirFilas(Storage $st, string $tabla, array $nuevas, array $definiciones = []): void
    {
        $nuevas = array_values($nuevas);
        [$filasAntes, $partesAntes, $ultima] = self::situacion($st, $tabla, 'ESCRITURA');
        if ($filasAntes === null) {
            $filas = array_merge(array_values(self::leerFilas($st, $tabla)), $nuevas);
            self::escribirTabla($st, $tabla, null, $definiciones, fn(): \Generator => self::enPartes($filas, $st->filasPorParte),
                array_chunk($filas, $st->filasPorParte) ?: [[]], \count($filas), null, null, [], $partesAntes);
            return;
        }
        // Si hay que rehacer un índice: las de antes, del disco, y las nuevas detrás
        $todas = fn(): \Generator => self::enPartes((static function () use ($st, $tabla, $nuevas, $filasAntes): \Generator {
            yield from $st->filas($tabla);
            foreach ($nuevas as $i => $fila) {
                yield $filasAntes + $i => $fila;
            }
        })(), $st->filasPorParte);
        // Lo que cabe en la última parte se empalma en su texto, sin leer ni
        // volver a escribir sus filas; lo que no, va a partes nuevas
        $partes  = [];
        $enUltima = $filasAntes - ($ultima - 1) * $st->filasPorParte;
        $caben    = max(0, $st->filasPorParte - $enUltima);
        $texto    = $filasAntes > 0 && $caben > 0
            ? self::empalmarParte($st, $tabla, $ultima, $enUltima, [], \array_slice($nuevas, 0, $caben)) : null;
        if ($texto !== null || ($filasAntes > 0 && $caben === 0)) {
            // Con la última parte llena, las filas van enteras a partes nuevas
            // y la última no se toca
            if ($texto !== null) {
                $partes[$ultima - 1] = $texto;
            }
            foreach (array_chunk(\array_slice($nuevas, $caben), $st->filasPorParte) as $i => $bloque) {
                $partes[$ultima + $i] = $bloque;
            }
        } else {
            $cola = $filasAntes === 0 ? [] : $st->parte($tabla, $ultima, $st->ficheroDatos($tabla, $ultima));
            foreach ($nuevas as $fila) {
                $cola[] = $fila;
            }
            foreach (array_chunk($cola, $st->filasPorParte) ?: [[]] as $i => $bloque) {
                $partes[$ultima - 1 + $i] = $bloque;
            }
        }
        self::escribirTabla($st, $tabla, null, $definiciones, $todas, $partes, $filasAntes + \count($nuevas),
            $filasAntes, $nuevas, [], $partesAntes);
    }

    /**
     * ¿Dice el estado de la tabla dónde acaba cada parte, con el tamaño de
     * parte de ahora? Si no, la primera escritura la reescribe entera (ver
     * situacion()).
     */
    public static function exacta(Storage $st, string $tabla): bool
    {
        $estado = $st->estado($tabla);
        $filas  = isset($estado['rows']) ? (int)$estado['rows'] : null;
        return $filas !== null && ($estado['chunk'] ?? null) === $st->filasPorParte
            && ($filas === 0 ? 1 : intdiv($filas - 1, $st->filasPorParte) + 1) === max(1, $st->partes($tabla));
    }

    /**
     * Abre la escritura de una tabla y dice cómo está: [filas, partes, última
     * parte]. Las filas son null si no se puede afirmar dónde acaba cada
     * parte —fichero de revisión de antes de la 2.5, tamaño de parte cambiado
     * o ficheros que no cuadran—, y entonces hay que leerla entera.
     *
     * @return array{0: int|null, 1: int, 2: int}
     */
    public static function situacion(Storage $st, string $tabla, string $operacion): array
    {
        self::abrirEscritura($st, $tabla, $operacion);
        $filas = self::exacta($st, $tabla) ? (int)$st->estado($tabla)['rows'] : null;
        return [$filas, $st->partes($tabla), $filas === null || $filas === 0 ? 1 : intdiv($filas - 1, $st->filasPorParte) + 1];
    }

    /** Comprobaciones comunes a toda escritura de una tabla y apertura de la suya si no hay otra. */
    public static function abrirEscritura(Storage $st, string $tabla, string $operacion): void
    {
        self::validarTabla($tabla);
        self::exigirEscritura($st);
        if (isset($st->txTablas[$tabla])) {
            throw JsonSqlDbError::lock("La tabla '$tabla' ya se ha guardado en esta escritura");
        }
        if ($st->modoPartes !== null && $st->modoPartes !== $tabla) {
            throw new ConflictoPartes();          // otra tabla: con los bloqueos de siempre
        }
        if ($st->txAmbito === null) {
            self::txIniciar($st, $operacion, isset($st->locksTabla[$tabla]) ? $tabla : null);
            $st->txPropia = true;
        }
        $st->txTablas[$tabla] = true;
        // Un proceso muerto de golpe no ejecuta el finally de escribirTemporal()
        // y deja su temporal ahí. Se barren si la escritura anterior de la tabla
        // no llegó a acabar (lo dice su .lock, ver marcar()), y cada 32
        // revisiones por si acaso: mirar la carpeta en cada escritura costaba
        // 0,26 ms con 200.000 filas (2.10). Pero ni con el exclusivo de la tabla
        // se puede dar por muerto todo temporal: una escritura por partes que
        // espera para confirmar tiene los suyos escritos y ha soltado la tabla.
        // Se borran solo los de procesos que ya no existen
        if (self::marcar($st, $tabla) || ((int)($st->estado($tabla)['rev'] ?? 0) + 1) % 32 === 0) {
            StorageBarrido::temporalesMuertos($st, $tabla);
        }
    }

    /**
     * Deja en el .lock de la tabla una línea que dice quién la está
     * escribiendo, «escribiendo: pid 1234 desde 2026-10-13 15:04:05» (2.10), y
     * dice si la escritura anterior dejó la suya: un proceso que no la quitó al
     * acabar murió a medias y pudo dejar temporales. Si el .lock no se puede
     * leer o escribir (en Windows, con el compartido de una escritura por
     * partes), true: se barre, como antes.
     */
    private static function marcar(Storage $st, string $tabla): bool
    {
        if (isset($st->txMarcas[$tabla])) {
            return false;
        }
        $fh = $st->locksTabla[$tabla] ?? @fopen($st->dir . '/.' . $tabla . '.lock', 'c+');
        if ($fh === false) {
            return true;
        }
        $linea = \sprintf("escribiendo: pid %d desde %s\n", getmypid(), date('Y-m-d H:i:s'));
        $antes = rewind($fh) ? (string)fread($fh, 256) : null;
        $ok    = $antes !== null && rewind($fh) && @fwrite($fh, $linea) === \strlen($linea) && ftruncate($fh, \strlen($linea));
        if (!isset($st->locksTabla[$tabla])) {
            fclose($fh);
        }
        if (!$ok) {
            return true;
        }
        $st->txMarcas[$tabla] = $linea;
        return $antes !== '' && !StorageBarrido::vivo($antes);
    }

    /** Quita las líneas de marcar() al acabar la escritura, si nadie ha puesto otra encima. */
    private static function desmarcar(Storage $st): void
    {
        foreach ($st->txMarcas as $tabla => $linea) {
            $fh = $st->locksTabla[$tabla] ?? @fopen($st->dir . '/.' . $tabla . '.lock', 'c+');
            if ($fh === false) {
                continue;
            }
            if (rewind($fh) && fread($fh, 256) === $linea) {
                ftruncate($fh, 0);
            }
            if (!isset($st->locksTabla[$tabla])) {
                fclose($fh);
            }
        }
        $st->txMarcas = [];
    }

    /**
     * El núcleo de la escritura: partes, estructura, revisión e índices.
     *
     * @param callable():iterable<int,array<int,array>> $todas  todas las filas tal como
     *                                        quedan, por partes (ver enPartes()); solo
     *                                        se llama si un índice hay que rehacerlo entero
     * @param iterable<int,list<array>|string> $partes  partes a escribir, índice base 0 => filas,
     *                                        o su texto ya hecho (ver empalmarParte());
     *                                        se recorren una vez, y pueden irse haciendo
     * @param int|callable(): int    $nFilas  cuántas filas queda teniendo la tabla; una función si solo se
     *                                        sabe al acabar de escribir las partes (ver StorageCompactacion::compactarPorBloques())
     * @param int|null               $desde   primera posición cuyo contenido cambió
     *                                        respecto al índice anterior; null si
     *                                        hay que rehacerlo
     * @param list<array>|null       $cola    las filas desde $desde, en orden
     * @param array<int,array>       $sueltas posición => fila nueva, cambiadas en su
     *                                        sitio por debajo de $desde
     * @param (callable(int): ?array)|null $clavesDe de una parte que llega como
     *                                        texto, [índice => sus claves, índice => el resumen de antes que
     *                                        sigue valiendo] (ver StorageCompactacion::compactarPorBloques())
     */
    public static function escribirTabla(
        Storage $st,
        string $tabla,
        ?array $meta,
        array $definiciones,
        callable $todas,
        iterable $partes,
        int|callable $nFilas,
        ?int $desde,
        ?array $cola,
        array $sueltas,
        int $partesAntes,
        ?callable $clavesDe = null
    ): void {
        $definiciones = $st->indices ? $definiciones : [];
        $estado       = $st->estado($tabla);
        $revAntes     = $estado['rev'];
        $rev          = $revAntes + 1;
        $revsAntes    = \array_slice(array_pad((array)($estado['parts'] ?? []), $partesAntes, $revAntes), 0, $partesAntes);
        $revsPartes   = [];                           // las que se escriben ahora; el resto, abajo
        $huecosPartes = [];                           // los huecos de las filas borradas en ellas (2.9)
        $revsIndices  = [];

        // Lo que se escriba ahora va a la caché con la etiqueta nueva de la
        // tabla (ver Storage::etiqueta()); lo de antes se borra con la de antes
        $creada = (int)($estado['creada'] ?? random_int(1, 2147483647));
        $st->estados[$tabla]['creada'] = $creada;

        // Lo que cambia de cada índice en las filas sueltas: por índice, la
        // clave de antes y la nueva de cada posición en que no son la misma. Las
        // de antes, del disco, donde las partes todavía son las de antes (nada
        // se ha renombrado), leídas parte a parte; y solo se guardan las que
        // cambian (2.9: las filas de antes, todas y juntas, y un UPDATE de
        // 250.000 filas de una tabla de un millón necesitaba otros 75 MB para
        // escribirse)
        // Una fila que se borra (null) no se lee: sale de su trozo por su
        // posición (ver escribirIndice()), que un DELETE de un cuarto de la
        // tabla decodificaba todas sus partes para saber las claves (2.9)
        $cambiosIdx = [];
        if ($desde !== null && $sueltas !== [] && $definiciones !== []) {
            $cambiadas = array_filter($sueltas, 'is_array');
            $vistas    = 0;
            foreach ($cambiadas === [] ? [] : StorageCambios::filasEnPosiciones($st, $tabla, array_keys($cambiadas)) as $pos => $fila) {
                $vistas++;
                foreach ($definiciones as $def) {
                    $plegar = $def['plegar'] ?? [];
                    if (IndexesEscritura::cambiaClave($def['columns'], $fila, $cambiadas[$pos], $plegar)) {
                        $cambiosIdx[$def['name']][$pos] = [Indexes::claveFila($fila, $def['columns'], $plegar),
                                                           Indexes::claveFila($cambiadas[$pos], $def['columns'], $plegar)];
                    }
                }
            }
            if ($vistas !== \count($cambiadas)) {
                $desde = null;                     // el disco no cuadra con lo que se creía
            }
            foreach ($sueltas as $pos => $fila) {
                if ($fila === null) {
                    foreach ($definiciones as $def) {
                        $cambiosIdx[$def['name']][$pos] = [null, null];
                    }
                }
            }
        }

        // Los índices que había: si el estado los nombra y todos siguen
        // definidos, sus nombres bastan (es lo que usan la limpieza de la caché
        // y el diario); si no —una tabla de antes de la 2.5, un índice que se
        // quita—, se buscan en el disco, que además da sus ficheros para
        // borrarlos. Buscarlos costaba casi 1 ms en cada escritura (2.9)
        $nombresAntes = array_keys((array)($estado['indexes'] ?? []));
        $indicesAntes = isset($estado['indexes']) && $nombresAntes !== []
            && array_diff($nombresAntes, array_column($definiciones, 'name')) === []
            ? array_fill_keys($nombresAntes, []) : self::indicesEnDisco($st, $tabla);
        // Cada parte llega como filas o, si se ha podido empalmar sobre la de
        // antes (ver empalmarParte()), como su texto ya hecho. Las partes no se
        // guardan en la caché al escribirlas: serializarlas y escribir la copia
        // costaba más que lo que ahorra a la primera lectura, que la decodifica
        // una vez y la guarda entonces
        //
        // Si los índices se rehacen enteros, el trozo de cada uno se hace al
        // escribir su parte, con sus filas (2.9): rehacerlos después volvía a
        // leer la tabla entera por cada índice, y compactar una con tres los
        // leía cuatro veces, el doble de lento que en la 2.8. Vale si se
        // escriben todas las partes, como filas; si no, se rehacen después
        $alPaso   = $desde === null && $definiciones !== [] ? [] : null;   // índice => trozo => rango
        $escritas = 0;
        foreach ($partes as $i => $bloque) {
            if (\is_string($bloque)) {
                self::escribirAtomico($st, $st->ficheroDatos($tabla, $i + 1), $bloque);
                $huella = hash(self::HUELLA, $bloque);
                // De texto, con las claves de cada índice si quien la hizo las da
                $suyas = $clavesDe === null ? null : $clavesDe($i);
                if ($alPaso !== null && $suyas !== null) {
                    foreach ($definiciones as $def) {
                        $alPaso[$def['name']][$i] = self::escribirTrozo($st, $tabla, $def, $i + 1, $rev,
                            $suyas[0][$def['name']] ?? [], $suyas[1][$def['name']] ?? null);
                    }
                } else {
                    $alPaso = null;
                }
            } else {
                $huella = self::escribirParte($st, $st->ficheroDatos($tabla, $i + 1), $tabla, $bloque);
                if ($alPaso !== null) {
                    // Con su posición en la tabla: unas partes la traen (enPartes()),
                    // otras van numeradas desde 0 (array_chunk()). Por su primera
                    // clave: array_is_list() no existe en PHP 8.0 (2.9)
                    $base   = $i * $st->filasPorParte;
                    $conPos = $base > 0 && array_key_first($bloque) === 0
                        ? array_combine(range($base, $base + \count($bloque) - 1), $bloque) : $bloque;
                    foreach ($definiciones as $def) {
                        $alPaso[$def['name']][$i] = self::escribirTrozo($st, $tabla, $def, $i + 1, $rev,
                            self::construir($conPos, $def['columns'], $def['plegar'] ?? []));
                    }
                    unset($conPos);
                }
            }
            // Su sello y la huella de su texto, a la caché al confirmar (2.9):
            // una edición a mano se ve aunque nadie haya leído la parte (ver
            // Storage::parte()). Las de todas las partes que escribe, en una
            // entrada: una por parte eran tantos ficheros de caché como
            // partes, y una cascada que reescribía cuarenta, más lenta que en 2.8.0
            $st->txPartes[$st->claveCache($tabla, 'h', $rev)][$i + 1] = [$huella, $st->ficheroDatos($tabla, $i + 1)];
            $escritas++;
            $revsPartes[$i]   = $rev;
            $huecosPartes[$i] = \is_string($bloque) ? (int)preg_match_all('/^[ \t]*null,?$/m', $bloque)
                : \count(array_keys($bloque, null, true));
        }
        unset($partes, $bloque);
        if (!\is_int($nFilas)) {
            $nFilas = $nFilas();                      // ya se sabe: las partes están escritas
        }
        $total = max(1, (int)ceil($nFilas / $st->filasPorParte));
        if ($escritas !== $total) {
            $alPaso = null;
        }
        // Las partes que no se han escrito siguen en su revisión, con sus huecos
        $revsPartes   = array_replace(\array_slice(array_pad($revsAntes, $total, $rev), 0, $total), $revsPartes);
        $huecosPartes = array_replace(\array_slice(array_pad(array_map('intval', (array)($estado['huecos'] ?? [])), $total, 0), 0, $total), $huecosPartes);
        for ($parte = $total + 1; $parte <= $partesAntes; $parte++) {
            self::borrarFichero($st, $st->ficheroDatos($tabla, $parte));
        }

        if ($meta !== null) {
            if (!is_file($st->ficheroMeta($tabla))) {
                StorageMantenimiento::anotarEsquema($st);           // una tabla nueva
            }
            $meta['updated_at'] = date('Y-m-d H:i:s');
            self::escribirAtomico($st, $st->ficheroMeta($tabla), json_encode($meta, self::JSON_META) . "\n");
            $st->txCache[] = [$st->claveCache($tabla, 'm', $rev), $meta, $st->ficheroMeta($tabla)];
        }

        // Los índices, trozo a trozo: uno por parte de la tabla. De cada
        // índice se reescriben solo los trozos que cambian y se anota en qué
        // revisión quedó cada uno; los demás siguen valiendo tal cual
        $vigentes      = [];
        $rangosIndices = [];
        foreach ($definiciones as $def) {
            $vigentes[$def['name']] = true;
            [$revsIndices[$def['name']], $rangosIndices[$def['name']]] = self::escribirIndice($st,
                $tabla, $def, $rev, $total, $nFilas, $desde, $cola, $cambiosIdx[$def['name']] ?? [], $todas, $alPaso[$def['name']] ?? null);
        }
        foreach ($indicesAntes as $nombre => $ficheros) {
            if (!isset($vigentes[$nombre])) {
                foreach ($ficheros as $fichero) {
                    self::borrarFichero($st, $fichero);
                }
            }
        }

        // La revisión y el estado de partes e índices: es lo que invalida la
        // caché. `creada` distingue esta tabla de otra que se llamó igual y se
        // borró: sus revisiones empiezan de cero y un resultado guardado en
        // APCu, que no se puede borrar por tabla, la confundiría con ella.
        $nuevo = ['rev' => $rev, 'chunk' => $st->filasPorParte, 'rows' => $nFilas, 'creada' => $creada,
                  'parts' => array_values($revsPartes), 'indexes' => (object)$revsIndices,
                  'rangos' => (object)$rangosIndices];
        // Los índices con alguna columna de texto o decimal quedan con las claves
        // de la 2.10, y «textos» dice hasta qué revisión (ver Storage::clavesAlDia());
        // los demás tienen las mismas claves que antes y no lo necesitan
        foreach ($definiciones as $def) {
            if (($def['texto'] ?? true) && isset($revsIndices[$def['name']])) {
                $nuevo['textos'][$def['name']] = $rev;
            }
        }
        if (array_sum($huecosPartes) > 0) {
            $nuevo['huecos'] = array_values($huecosPartes);
        }
        if (isset($estado['gen'])) {
            $nuevo['gen'] = (int)$estado['gen'];          // la generación sigue (ver Storage::etiquetaDe())
        }
        $autoinc = $st->autoincPendiente[$tabla] ?? $estado['autoinc'] ?? null;
        if ($autoinc !== null) {
            $nuevo['autoinc'] = (int)$autoinc;
        }
        unset($st->autoincPendiente[$tabla]);
        if ($st->modoPartes !== null) {
            // Por partes, la revisión se escribe al confirmar, ya con el
            // exclusivo, juntando esto con lo que otros hayan hecho entretanto.
            // Un cambio de estructura, o de tamaño de parte, no se puede juntar:
            // va por el camino de siempre. Con el tamaño cambiado, la tabla se
            // reescribía entera con partes del tamaño nuevo, pero al juntar se
            // quedaba el tamaño de antes en el fichero de revisión, y las
            // posiciones dejaban de cuadrar: un DELETE posterior no encontraba
            // la fila (comprobado en la 2.7.4)
            if ($meta !== null || ($estado['chunk'] ?? null) !== $st->filasPorParte) {
                throw new ConflictoPartes();
            }
            $nuevo['indexes'] = $revsIndices;
            $nuevo['rangos']  = $rangosIndices;
            $st->pendientePartes[$tabla] = [
                'antes' => $estado, 'nuevo' => $nuevo, 'partesAntes' => $partesAntes,
                'meta' => (string)@md5_file($st->ficheroMeta($tabla)), 'indicesAntes' => array_keys($indicesAntes),
            ];
            unset($st->indicesMemo[$tabla], $st->preguntasTexto[$tabla]);
            return;
        }
        self::escribirAtomico($st, $st->ficheroRev($tabla), json_encode($nuevo, self::JSON_META) . "\n");
        $nuevo['indexes'] = $revsIndices;
        $nuevo['rangos']  = $rangosIndices;

        self::limpiarCache($st, $tabla, $estado, $nuevo, array_keys($indicesAntes));
        $st->estados[$tabla] = $nuevo;
        // Y las preguntas por claves de texto, que se cuentan para decidir si
        // compensa juntar el índice entero en memoria (ver posicionesDeTexto()):
        // sin él, ya no hay nada que aprovechar (2.10: en una transacción de
        // INSERT de una fila, desde el noveno cada uno lo volvía a juntar, y
        // tardaba el doble que buscando la clave en el texto de los trozos)
        unset($st->indicesMemo[$tabla], $st->preguntasTexto[$tabla]);

        if ($st->txPropia) {
            $st->txPropia = false;
            self::txConfirmar($st);
        }
    }

    /**
     * El error de una fila que no se puede escribir en JSON (2.9): casi
     * siempre bytes que no son texto (RANDOMBLOB, ZEROBLOB, UNHEX…), que aquí
     * solo valen dentro de una expresión, como HEX(RANDOMBLOB(16)).
     */
    public static function noCodificable(string $tabla, array $fila): JsonSqlDbError
    {
        foreach ($fila as $col => $v) {
            if (\is_string($v) && !preg_match('//u', $v)) {
                return JsonSqlDbError::type("La columna '$col' de '$tabla' recibe bytes que no son texto (RANDOMBLOB, "
                    . 'ZEROBLOB, UNHEX…): no se pueden guardar tal cual; guárdalos como texto, con HEX()');
            }
            // Un número fuera de rango (1e400, 1e300 * 1e300) es infinito, y en
            // JSON no cabe (2.10: «No se puede codificar una fila»)
            if (\is_float($v) && !is_finite($v)) {
                return JsonSqlDbError::type("La columna '$col' de '$tabla' recibe un número fuera de rango (infinito o NaN), "
                    . 'que no se puede guardar');
            }
        }
        return JsonSqlDbError::io("No se puede codificar una fila de '$tabla' a JSON");
    }

    /**
     * Escribe una parte de una tabla fila a fila, sin armar el JSON entero en
     * memoria: cabecera indentada y una fila por línea. Devuelve la huella de
     * lo escrito (ver Storage::parte()).
     *
     * @param list<array> $filas
     */
    private static function escribirParte(Storage $st, string $fichero, string $tabla, array $filas): string
    {
        $huella = hash_init(self::HUELLA);
        self::escribirTemporal($st, $fichero, static function ($fh) use ($tabla, $filas, $fichero, $huella): void {
            // Se escribe en bloques de unos 64 KB: una llamada a fwrite() por
            // fila eran mil por parte, y el texto entero de golpe tendría la
            // parte dos veces en memoria
            $bloque = "{\n  \"table\": " . json_encode($tabla, self::JSON_FILA) . ",\n  \"rows\": [";
            $pos    = 0;
            $volcar = static function (string &$bloque) use ($fh, $fichero, &$pos, $huella): void {
                if (@fwrite($fh, $bloque) !== \strlen($bloque)) {
                    throw JsonSqlDbError::io('Escritura incompleta de ' . basename($fichero));
                }
                hash_update($huella, $bloque);
                $pos   += \strlen($bloque);
                $bloque = '';
            };
            // Dónde empieza cada fila: con ello una lectura por clave lee
            // una línea del fichero en vez de decodificarlo entero (ver
            // Storage::filasEnParte()). Van al final, con la posición del propio
            // listado en la última línea para encontrarlo sin leer nada más.
            $desfases = [];
            $sep      = "\n    ";
            foreach ($filas as $fila) {
                $json = json_encode($fila, self::JSON_FILA);
                if ($json === false) {
                    throw self::noCodificable($tabla, $fila);
                }
                $desfases[] = $pos + \strlen($bloque) + \strlen($sep);
                $bloque    .= $sep . $json;
                $sep        = ",\n    ";
                if (\strlen($bloque) >= 65536) {
                    $volcar($bloque);
                }
            }
            $bloque .= $filas === [] ? "],\n" : "\n  ],\n";
            $aqui    = $pos + \strlen($bloque);
            $bloque .= '  "offsets": [' . implode(',', $desfases) . "],\n  \"offsets_at\": $aqui\n}\n";
            $volcar($bloque);
        });
        return hash_final($huella);
    }

    /**
     * El texto nuevo de una parte, hecho sobre el de antes sin decodificar ni
     * volver a codificar sus filas: cambia las líneas de $cambios (desfase en
     * la parte => fila nueva), añade $nuevas al final y rehace los desfases.
     * El resultado es, byte a byte, el que escribiría escribirParte() con
     * todas las filas (lo comprueba tests/f3_escrituras.php). Null si la parte
     * no tiene la forma esperada —de antes de la 2.7, editada a mano, o con un
     * número de filas que no es $filas— y entonces se escribe como siempre.
     * Con $quedan, la parte se corta después de esas líneas: las de detrás
     * eran huecos del final (ver modificarFilas()).
     *
     * @param array<int,?array> $cambios
     * @param list<array>       $nuevas
     */
    public static function empalmarParte(Storage $st, string $tabla, int $parte, int $filas, array $cambios,
                                          array $nuevas, ?int $quedan = null): ?string
    {
        $fichero = $st->ficheroDatos($tabla, $parte);
        $desf    = $st->desfasesDeParte($tabla, $parte, $fichero);
        $texto   = $desf === null ? false : @file_get_contents($fichero);
        if ($texto === false || \strlen($desf) !== 4 * $filas
            || !preg_match('/"offsets_at": (\d+)\n\}\n$/', substr($texto, -40), $m)) {
            return null;
        }
        $finFilas = (int)$m[1];
        $cierre   = $filas === 0 ? "],\n" : "\n  ],\n";
        $cabecera = "{\n  \"table\": " . json_encode($tabla, self::JSON_FILA) . ",\n  \"rows\": [";
        if (strncmp($texto, $cabecera, \strlen($cabecera)) !== 0
            || substr($texto, $finFilas - \strlen($cierre), \strlen($cierre)) !== $cierre) {
            return null;
        }
        // La lista de desfases se escribió junto con las filas y offsets_at dice
        // dónde acaba la última: si alguien hubiera cambiado la longitud de una
        // línea, el final de las filas ya no estaría ahí y no se habría llegado
        // hasta aquí. Se trabaja por tramos del texto, sin recorrer las filas
        $desfases = $filas === 0 ? [] : array_values(unpack('N*', $desf));
        $finUltima = $finFilas - \strlen($cierre);
        if ($filas > 0 && ($desfases[0] !== \strlen($cabecera) + 5 || !\in_array($texto[$desfases[$filas - 1]], ['{', 'n'], true))) {
            return null;
        }
        $quedan = min($quedan ?? $filas, $filas);
        if ($quedan < $filas) {
            $finUltima = $quedan === 0 ? $finUltima : $desfases[$quedan] - 6;   // donde acaba la última que se queda
            $desfases  = \array_slice($desfases, 0, $quedan);
        }
        ksort($cambios);
        $nuevo  = '';
        $desde  = 0;
        $delta  = 0;
        $ajuste = [];                                 // fila => cuánto se mueve su desfase
        foreach ($cambios as $k => $fila) {
            if ($k < 0 || $k >= $filas) {
                return null;
            }
            if ($k >= $quedan) {
                continue;                             // en lo que se corta
            }
            $ini = $desfases[$k];
            $fin = $k + 1 < $quedan ? $desfases[$k + 1] - 6 : $finUltima;
            // Una fila, o el hueco (null) de una borrada (2.9)
            if (($texto[$ini] !== '{' || $texto[$fin - 1] !== '}') && substr($texto, $ini, $fin - $ini) !== 'null') {
                return null;
            }
            $json = json_encode($fila, self::JSON_FILA);
            if ($json === false) {
                throw self::noCodificable($tabla, $fila);
            }
            $nuevo .= substr($texto, $desde, $ini - $desde) . $json;
            $desde  = $fin;
            $delta += \strlen($json) - ($fin - $ini);
            $ajuste[$k + 1] = $delta;
        }
        $nuevo .= substr($texto, $desde, $finUltima - $desde);
        if ($ajuste !== []) {
            $mover = 0;
            for ($k = 0; $k < $quedan; $k++) {
                $mover = $ajuste[$k] ?? $mover;
                $desfases[$k] += $mover;
            }
        }
        if ($quedan === 0) {
            $nuevo = $cabecera;                       // "rows": [ … sin nada detrás todavía
        }
        $sep = $quedan === 0 ? "\n    " : ",\n    ";
        foreach ($nuevas as $fila) {
            $json = json_encode($fila, self::JSON_FILA);
            if ($json === false) {
                throw self::noCodificable($tabla, $fila);
            }
            $desfases[] = \strlen($nuevo) + \strlen($sep);
            $nuevo     .= $sep . $json;
            $sep        = ",\n    ";
        }
        $nuevo .= $desfases === [] ? "],\n" : "\n  ],\n";
        $aqui   = \strlen($nuevo);
        return $nuevo . '  "offsets": [' . implode(',', $desfases) . "],\n  \"offsets_at\": $aqui\n}\n";
    }

    /** Escribe un fichero entero dentro de la escritura abierta. */
    public static function escribirAtomico(Storage $st, string $fichero, string $contenido, bool $sincronizar = true): void
    {
        // En una transacción, un fichero que ya ha reemplazado una sentencia
        // anterior se escribe en su sitio al confirmar, sin temporal ni
        // renombrado (2.10): su original está a salvo en .lote/ en otro inodo
        // (ver txConfirmar(), 'propios'; antes de reemplazarlo, .lote/ guarda
        // un enlace al mismo inodo, y escribir en él estropearía el original) y la base,
        // bloqueada entera, no la lee nadie más. Si el proceso muere, vuelve
        // el original; al confirmar la transacción se fuerza a disco, también
        // un trozo de índice, que en su sitio podría quedar a medias; por eso
        // no en PHP 8.0, que no puede forzarlo. Con un tope por sentencia,
        // para no tener en memoria lo que no cabe (ver Writer::tramo()); lo
        // que pasa de él va como siempre
        if ($st->lote !== null && function_exists('fsync')
            && isset($st->lote['propios'][$nombre = basename($fichero)])
            && $nombre !== '_database.json'
            && \strlen($contenido) + array_sum(array_map('strlen', $st->txEnSitio)) <= self::EN_SITIO_MAX) {
            self::borrarTemporalDe($st, $fichero);
            unset($st->txBorrar[$fichero]);
            $st->txEnSitio[$fichero] = $contenido;
            $st->lote['sincronizar'][$fichero] = true;
            return;
        }
        self::escribirTemporal($st, $fichero, static function ($fh) use ($contenido, $fichero): void {
            if (@fwrite($fh, $contenido) !== \strlen($contenido)) {
                throw JsonSqlDbError::io('Escritura incompleta de ' . basename($fichero));
            }
        }, $sincronizar);
    }

    /**
     * Escribe el contenido de un fichero en su temporal, forzado a disco, y lo
     * apunta para ponerlo en su sitio al confirmar. Si algo falla, el temporal
     * se borra: nunca queda un fichero a medias.
     *
     * fsync() existe desde PHP 8.1. En 8.0 se hace lo que se puede: vaciar el
     * buffer de PHP.
     *
     * @param callable(resource):void $volcar
     * @param bool $sincronizar false para lo que no hace falta que sobreviva a un
     *             corte: los trozos de índice, que se rehacen desde las filas si
     *             faltan. Cada fsync cuesta milisegundos en un disco de hosting.
     */
    private static function escribirTemporal(Storage $st, string $fichero, callable $volcar, bool $sincronizar = true): void
    {
        $tmp = $fichero . '.' . getmypid() . '.tmp';
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw JsonSqlDbError::io('No se puede escribir ' . basename($fichero));
        }
        try {
            $volcar($fh);
            @fflush($fh);
            if ($sincronizar && function_exists('fsync')) {
                // Dentro de una transacción, al confirmarla, una vez por fichero
                // (2.10, ver StorageMantenimiento::loteConfirmar()): hasta entonces,
                // si el proceso muere, se devuelven los originales de .lote/
                if ($st->lote === null) {
                    @fsync($fh);            // los datos, en el disco de verdad
                    self::$sincronizados++;
                } else {
                    $st->lote['sincronizar'][$fichero] = true;
                }
            }
        } catch (\Throwable $e) {
            @fclose($fh);
            @unlink($tmp);
            throw $e;
        }
        @fclose($fh);
        unset($st->txBorrar[$fichero], $st->txEnSitio[$fichero]);   // si se borraba y se vuelve a escribir, gana lo último
        $st->txRenombrar[$tmp] = $fichero;
        if (!$sincronizar) {
            $st->txRegenerables[$tmp] = true;
        }
    }

    /** Ficheros de datos forzados a disco en este proceso (para las pruebas; ver escribirTemporal()) */
    private static int $sincronizados = 0;

    /** Cuántos van; con $mas, se suman antes (ver StorageMantenimiento::loteConfirmar()) */
    public static function sincronizados(int $mas = 0): int
    {
        return self::$sincronizados += $mas;
    }

    /** ¿Va la escritura dentro de una transacción (ver StorageMantenimiento::loteIniciar())? */
    public static function enLote(Storage $st): bool
    {
        return $st->lote !== null;
    }

    /** Apunta un fichero para borrarlo al confirmar. */
    public static function borrarFichero(Storage $st, string $fichero): void
    {
        self::borrarTemporalDe($st, $fichero);
        unset($st->txEnSitio[$fichero]);
        $st->txBorrar[$fichero] = true;
    }

    /** Lo que esta escritura llevaba ya para $fichero en un temporal: sobra */
    private static function borrarTemporalDe(Storage $st, string $fichero): void
    {
        $tmp = array_search($fichero, $st->txRenombrar, true);
        if ($tmp !== false) {
            @unlink((string)$tmp);
            unset($st->txRenombrar[$tmp], $st->txRegenerables[$tmp]);
        }
    }

    /**
     * Pone $origen en el sitio de $destino cuando renombrarlo encima acaba de
     * fallar (quien llama lo intenta antes: es lo de casi siempre). En Windows
     * falla mientras otro proceso tiene abierto el destino: se reintenta
     * durante un segundo, sin borrarlo nunca (2.10: se borraba y se volvía a
     * renombrar, y en ese momento otro proceso no lo encontraba; si seguía
     * abierto, ni se podía borrar y la escritura fallaba). False si no se ha podido.
     */
    public static function reemplazar(string $origen, string $destino): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        for ($i = 0; $i < 20; $i++) {
            usleep(50000);
            if (@rename($origen, $destino)) {
                return true;
            }
        }
        return false;
    }

    /** Escribe un fichero y lo pone en su sitio ya mismo (temporal + fsync + rename). */
    public static function volcarFichero(string $fichero, string $contenido): void
    {
        $tmp = $fichero . '.' . getmypid() . '.tmp';
        try {
            $fh = @fopen($tmp, 'wb');
            if ($fh === false || @fwrite($fh, $contenido) !== \strlen($contenido)) {
                throw JsonSqlDbError::io('No se puede escribir ' . basename($fichero));
            }
            @fflush($fh);
            if (function_exists('fsync')) {
                @fsync($fh);
            }
            @fclose($fh);
            if (!@rename($tmp, $fichero) && !self::reemplazar($tmp, $fichero)) {
                throw JsonSqlDbError::io('No se puede reemplazar ' . basename($fichero));
            }
            self::fsyncDir($fichero);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Fuerza a disco la ENTRADA DE DIRECTORIO, no el contenido del fichero.
     * Tras un rename() el contenido puede estar en el disco y el nombre solo
     * en la caché del sistema; POSIX no garantiza el orden. Hay que abrir el
     * directorio con fopen() en lectura: sobre opendir() fsync falla en
     * silencio. En Windows fopen() sobre un directorio no funciona y esto no
     * hace nada; allí el rename tampoco es atómico y de eso se encarga el
     * journal.
     */
    public static function fsyncDir(string $ruta): void
    {
        if (!function_exists('fsync')) {
            return;                             // PHP 8.0: no hay con qué
        }
        $fh = @fopen(is_dir($ruta) ? $ruta : dirname($ruta), 'r');
        if ($fh !== false) {
            @fsync($fh);
            @fclose($fh);
        }
    }

    // ------------------------------------------------------------------
    // Índices
    // ------------------------------------------------------------------

    /**
     * Índices que hay ahora mismo en disco: nombre => rutas de sus trozos.
     *
     * @return array<string, list<string>>
     */
    public static function indicesEnDisco(Storage $st, string $tabla): array
    {
        $out    = [];
        $inicio = \strlen($tabla) + 5;                 // '<tabla>.idx.'
        foreach ((array)glob($st->dir . '/' . $tabla . '.idx.*.json') as $f) {
            $nombre = substr(basename((string)$f, '.json'), $inicio);
            if (preg_match('/^(.+)\.part\d+$/', $nombre, $m)) {
                $nombre = $m[1];
            }
            if ($nombre !== '') {
                $out[$nombre][] = (string)$f;
            }
        }
        return $out;
    }

    /**
     * Escribe lo que cambia de un índice y devuelve la revisión de cada uno
     * de sus trozos. Un trozo cubre las posiciones de una parte de la tabla,
     * así que una escritura que toca una parte toca un trozo de cada índice,
     * y el resto queda como estaba.
     *
     * Con $desde se corrigen los trozos: los que contienen posiciones sueltas
     * se actualizan una a una, y del que contiene $desde en adelante se
     * cortan las posiciones desplazadas y se añaden las de $cola. Sin $desde,
     * o si algún trozo que hace falta no está al día, el índice se rehace
     * entero desde las filas, que se leen solo entonces.
     *
     * @param callable():iterable<int,array<int,array>> $todas por partes (ver escribirTabla())
     * @param array<int,array{0: ?string, 1: ?string}> $cambia posición => su clave de antes y la nueva, las que
     *                                        no son la misma; [null, null] es una fila borrada, que sale
     *                                        de la clave que tenga (ver escribirTabla())
     * @param array<int,array|null>|null $hechos trozo => rango de los ya escritos con su parte, si se rehace entero (ver escribirTabla())
     * @return array{0: list<int>, 1: list<array|null>} revisión y rango de cada trozo (ver IndexesEscritura::rango())
     */
    private static function escribirIndice(
        Storage $st,
        string $tabla,
        array $def,
        int $rev,
        int $total,
        int $nFilas,
        ?int $desde,
        ?array $cola,
        array $cambia,
        callable $todas,
        ?array $hechos = null
    ): array {
        $nombre = $def['name'];
        $estado = $st->estado($tabla);
        $revs   = $estado['indexes'][$nombre] ?? null;
        $rangos = $estado['rangos'][$nombre] ?? [];
        $chunk  = $st->filasPorParte;
        // Con claves de antes de la 2.10 en una columna de texto o decimal, entero (ver Storage::clavesAlDia())
        if (!$st->clavesAlDia($tabla, $def)) {
            $desde = null;
        }
        // El de un UNIQUE que se añade (ver CatalogDdl::guardar()) se construye
        // entero, mirando de paso que ninguna clave se repita
        $vistas = empty($def['exigir']) ? null : [];
        if ($vistas !== null) {
            $desde  = null;
            $hechos = null;
        }

        // Qué trozos hay que tocar, y si los que se corrigen están al día.
        // Los que se rehacen enteros desde la cola no necesitan el de antes.
        $tocar   = [];
        $cambian = [];                                 // trozo => posiciones cuya clave cambia
        if ($desde !== null && \is_array($revs) && \count($revs) >= (int)ceil($desde / $chunk)) {
            // Una fila cambiada solo toca su trozo si cambia su clave en este
            // índice: un UPDATE de una columna sin índice no lee ni reescribe
            // ningún trozo (antes los leía y decodificaba todos los de las
            // filas cambiadas para acabar dejándolos igual)
            foreach ($cambia as $pos => $_) {
                if ($pos < $desde) {
                    $tocar[intdiv((int)$pos, $chunk)] = true;
                    $cambian[intdiv((int)$pos, $chunk)][] = (int)$pos;
                }
            }
            // La cola —desde la primera posición que se añade o se mueve— solo
            // se rehace si la hay: alguna posición desde ahí existía antes o
            // existe ahora. Un UPDATE no añade ni quita filas, y rehacía igual
            // el último trozo de cada índice si la tabla no llenaba su última
            // parte (2.9). Sin saber cuántas filas había, se rehace como antes
            $antes = $st->estado($tabla)['rows'] ?? null;
            if ($desde < max($nFilas, $antes === null ? PHP_INT_MAX : (int)$antes)) {
                for ($p = intdiv($desde, $chunk); $p < $total; $p++) {
                    $tocar[$p] = true;
                }
            }
            // Los trozos que se corrigen se leen enteros; los que se dejan
            // como están solo se miran por encima —cabecera y cola, sin
            // decodificar—, para que un fichero dañado por un corte o editado
            // a mano no se quede así para siempre. Cien trozos cuestan un
            // milisegundo: se paga en cada escritura.
            $st->trozosSinCache = true;                   // los que se corrigen se reescriben: no a la caché
            $hasta = min($total, \count($revs));
            for ($p = 0; $p < $hasta; $p++) {
                if (isset($tocar[$p]) && $p * $chunk < $desde && $st->trozoIndice($tabla, $def, $p + 1) === null) {
                    $desde = null;
                    break;
                }
            }
            $st->trozosSinCache = false;
            if ($desde !== null && !self::trozosFiables($st, $tabla, $def, $revs, $hasta, array_diff_key(array_fill(0, $hasta, true), $tocar))) {
                $desde = null;
            }
        } else {
            $desde = null;
        }

        $salida = [];
        $tramos = [];
        if ($desde === null) {
            // Entero, desde las filas, trozo a trozo: cada uno con las de su
            // parte. Las partes sin filas, con el trozo vacío
            foreach ($hechos ?? [] as $p => $rango) {
                $tramos[$p] = $rango;
                $salida[$p] = $rev;
            }
            foreach ($hechos === null ? $todas() : [] as $p => $filasParte) {
                $keys = self::construir($filasParte, $def['columns'], $def['plegar'] ?? []);
                if ($vistas !== null) {
                    StorageMantenimiento::exigirUnica($tabla, $def, $keys, $filasParte, $vistas);
                }
                $tramos[$p] = self::escribirTrozo($st, $tabla, $def, $p + 1, $rev, $keys);
                $salida[$p] = $rev;
            }
            for ($p = 0; $p < $total; $p++) {
                if (!isset($salida[$p])) {
                    $tramos[$p] = self::escribirTrozo($st, $tabla, $def, $p + 1, $rev, []);
                    $salida[$p] = $rev;
                }
            }
            ksort($salida);
            ksort($tramos);
        } else {
            $st->trozosSinCache = true;
            $filasAntes = isset($st->estado($tabla)['rows']) ? (int)$st->estado($tabla)['rows'] : null;
            for ($p = 0; $p < $total; $p++) {
                if (!isset($tocar[$p])) {
                    $salida[$p] = (int)$revs[$p];
                    $tramos[$p] = $rangos[$p] ?? null;
                    continue;
                }
                $inicio = $p * $chunk;
                $fin    = $inicio + $chunk;               // exclusivo
                $keys   = $inicio >= $desde ? [] : (array)$st->trozoIndice($tabla, $def, $p + 1);
                $cambio = $inicio >= $desde;
                $pierde = !$cambio;                       // ¿solo salen claves? Su filtro de antes sigue valiendo
                $gana   = !$cambio && !isset($cambian[$p]) && \array_key_exists($p, $rangos);  // ¿solo entran? (ver abajo)
                $dePos  = null;
                // Solo las de este trozo (2.9: se miraban todas en cada trozo, y
                // un UPDATE de cien mil filas que cambiaba la clave se hacía cuadrático)
                foreach ($cambian[$p] ?? [] as $pos) {
                    [$antes, $ahora] = $cambia[$pos];
                    if ($antes === null && $ahora === null) {
                        // Una fila borrada: la clave que tenga, del trozo, sin leer la fila
                        $dePos ??= IndexesEscritura::porPosicion($keys);
                        $antes   = $dePos[$pos] ?? null;
                        if ($antes === null) {
                            continue;
                        }
                    }
                    $pierde = $pierde && $ahora === null;
                    IndexesEscritura::sustituir($keys, $pos, $antes, $ahora);
                    $cambio = true;
                }
                $ampliado = null;
                if ($fin > $desde) {
                    // Las posiciones desde $desde, si las había: sin filas
                    // detrás de $desde no queda nada que cortar (2.10: cada
                    // INSERT repasaba las mil claves del último trozo)
                    if ($inicio < $desde && ($filasAntes === null || $desde < $filasAntes)) {
                        $keys = self::recortar($keys, $desde);
                        $gana = false;                    // alguna clave puede haber salido
                    }
                    $cambio = $cambio || $inicio < $desde;
                    // Las filas nuevas de este trozo: la cola empieza en $desde
                    $primera = max($inicio, $desde);
                    $ultima  = min($fin, $nFilas);
                    if ($primera < $ultima) {
                        $suyas  = IndexesEscritura::ampliar([], \array_slice($cola ?? [], $primera - $desde, $ultima - $primera), $def['columns'], $primera, $def['plegar'] ?? []);
                        $nuevas = array_diff_key($suyas, $keys);
                        // Las que ya estaban, con sus posiciones nuevas detrás;
                        // las nuevas, detrás de todas y de una vez, que es lo
                        // mismo (2.10, antes de publicarla: clave a clave, un
                        // DELETE con cascada hacía un 2 % más que en la 2.9)
                        foreach (\count($nuevas) === \count($suyas) ? [] : array_diff_key($suyas, $nuevas) as $clave => $donde) {
                            foreach (Indexes::posiciones($donde) as $pos) {
                                IndexesEscritura::anotar($keys, (string)$clave, $pos);
                            }
                        }
                        $keys  += $nuevas;
                        $nuevas = array_keys($nuevas);
                        // Si solo entran claves al final del trozo, su rango y su
                        // cabecera se amplían con las nuevas, sin rehacerlos (2.10)
                        $resumenAntes = $st->indicesMemo[$tabla][$nombre]['resumen'][$p + 1] ?? null;
                        if ($gana && $resumenAntes !== null) {
                            $ampliado = ['rango' => self::rangoAmpliado($rangos[$p], IndexesEscritura::rango($suyas)),
                                         'resumen' => $resumenAntes, 'nuevas' => array_map('strval', $nuevas)];
                        }
                        $cambio = true;
                        $pierde = false;
                    }
                }
                if ($cambio) {
                    $tramos[$p] = self::escribirTrozo($st, $tabla, $def, $p + 1, $rev, $keys,
                        $pierde ? $st->indicesMemo[$tabla][$nombre]['resumen'][$p + 1] ?? null : null, $ampliado);
                    $salida[$p] = $rev;
                } else {
                    $salida[$p] = (int)$revs[$p];
                    $tramos[$p] = $rangos[$p] ?? null;
                }
            }
            $st->trozosSinCache = false;
        }
        // Trozos de sobra de cuando la tabla era más grande
        for ($p = $total + 1; is_file($st->ficheroIndice($tabla, $nombre, $p)); $p++) {
            self::borrarFichero($st, $st->ficheroIndice($tabla, $nombre, $p));
        }
        $salida = array_values($salida);
        // El veredicto para la escritura siguiente (ver veredicto()): la revisión
        // de cada trozo y su tamaño, que los escritos ahora y los comprobados
        // ya tienen. Se guarda si cambia algo, al confirmar la escritura (2.9:
        // una que no llegaba a confirmarse lo dejaba con revisiones que no
        // estaban en el disco, y que otra iba a usar)
        $tams = $st->tamTrozos[$st->etiqueta($tabla)][$nombre] ?? [];
        $t    = [];
        for ($p = 1; $p <= $total; $p++) {
            if (!isset($tams[$p])) {
                $t = null;
                break;
            }
            $t[] = $tams[$p];
        }
        if ($t !== null) {
            $v = ['r' => $salida, 't' => $t];
            if (self::veredicto($st, $tabla, $nombre) !== $v) {
                $st->txHuellas[] = [$st->claveCache($tabla, 'v' . $nombre, 0), $v];
            }
        }
        // Y los filtros de todos los trozos (ver Storage::cargarFiltros()): los
        // de ahora (ver escribirTrozo()) y, de los que no se han tocado, los
        // guardados. Solo si no estaban, o si ya hay bastantes desfasados: una
        // búsqueda lee de su trozo los pocos que falten, y guardarlos en cada
        // escritura costaba 5 ms con tres índices de mil trozos. También al
        // confirmar (ver Storage::guardarFiltros()): con el filtro de un trozo
        // que no llegó al disco, una búsqueda daba por ausente una clave que
        // estaba en el trozo de verdad
        $desfasados = self::filtrosDesfasados($st, $tabla, $nombre, $salida, $total);
        if ($desfasados === null || $desfasados > max(16, $total >> 4)) {
            if (\is_array($revs)) {
                $st->cargarFiltros($tabla, $nombre, $revs);
            }
            $st->guardarFiltros($tabla, $nombre, $salida, $total);
        }
        return [$salida, array_values($tramos)];
    }

    /**
     * ¿Tiene el fichero de un trozo la cabecera que debería? Se leen sus
     * primeros bytes y se comprueban índice, columnas, parte y revisión, sin
     * decodificar las claves: cuesta lo mismo tenga mil o cien mil.
     */
    private static function trozoSano(Storage $st, string $tabla, array $def, int $parte, int $rev): bool
    {
        // Un trozo ya visto sano en esta revisión lo sigue estando: se reescribe
        // de una pieza (escribirAtomico) y con otra revisión. Cada escritura
        // miraba todos los que no tocaba: 109 aperturas por INSERT a 60.000 filas
        $etiqueta = $st->etiqueta($tabla);            // la tabla de ahora, no una anterior con el mismo nombre
        $nombre   = $def['name'];
        if (($st->trozosSanos[$etiqueta][$nombre][$parte] ?? null) === $rev) {
            return true;
        }
        $fh = @fopen($st->ficheroIndice($tabla, $nombre, $parte), 'rb');
        if ($fh === false) {
            return false;
        }
        $tam      = (int)(fstat($fh)['size'] ?? 0);
        $cabecera = (string)fread($fh, 512);
        // Y el final: los trozos se escriben sin fsync, y un corte puede dejar
        // el fichero con la cabecera bien y la cola a ceros o cortada
        fseek($fh, -3, SEEK_END);
        $cola = (string)fread($fh, 3);
        fclose($fh);
        $esperada = substr(json_encode([
            'index' => $def['name'], 'table' => $tabla, 'columns' => $def['columns'],
            'part' => $parte, 'rev' => $rev,
        ], self::JSON_FILA), 1, -1);
        $sano = strncmp($cabecera, '{' . $esperada . ',', \strlen($esperada) + 2) === 0 && $cola === "}}\n";
        if ($sano) {
            $st->trozosSanos[$etiqueta][$nombre][$parte] = $rev;
            $st->tamTrozos[$etiqueta][$nombre][$parte]   = $tam;
        }
        return $sano;
    }

    /**
     * ¿Están sanos esos trozos (los de $cuales, o todos)? Primero con el
     * veredicto guardado por la escritura anterior (ver veredicto()): un trozo
     * en la misma revisión y con el mismo tamaño está como se dejó, y basta
     * un stat; los demás, por dentro (ver trozoSano()). Mirarlos todos por
     * dentro era la mitad de un UPDATE de una fila en una tabla de un millón
     * (3.000 trozos).
     *
     * @param list<int> $revs la revisión de cada trozo según el estado
     * @param array<int, mixed>|null $cuales trozo (desde 0) => lo que sea, o null para todos
     */
    private static function trozosFiables(Storage $st, string $tabla, array $def, array $revs, int $partes, ?array $cuales = null): bool
    {
        $nombre   = $def['name'];
        $etiqueta = $st->etiqueta($tabla);
        $v        = null;
        foreach ($cuales === null ? range(0, $partes - 1) : array_keys($cuales) as $i) {
            if ($i >= $partes) {
                continue;
            }
            $rev = (int)($revs[$i] ?? -1);
            if (($st->trozosSanos[$etiqueta][$nombre][$i + 1] ?? null) === $rev) {
                continue;
            }
            $v ??= self::veredicto($st, $tabla, $nombre) ?? [];
            if (($v['r'][$i] ?? null) === $rev) {
                $tam = @filesize($st->ficheroIndice($tabla, $nombre, $i + 1));
                if ($tam !== false && $tam > 0 && $tam === ($v['t'][$i] ?? null)) {
                    $st->trozosSanos[$etiqueta][$nombre][$i + 1] = $rev;
                    $st->tamTrozos[$etiqueta][$nombre][$i + 1]   = $tam;
                    continue;
                }
            }
            if (!self::trozoSano($st, $tabla, $def, $i + 1, $rev)) {
                return false;
            }
        }
        return true;
    }

    /**
     * El veredicto guardado en la caché sobre los trozos de un índice (2.9):
     * la revisión ('r') y el tamaño ('t') de cada uno la última vez que una
     * escritura los dejó sanos. Null si no hay, o si la caché está apagada.
     *
     * @return array{r: list<int>, t: list<int>}|null
     */
    private static function veredicto(Storage $st, string $tabla, string $nombre): ?array
    {
        $v = $st->cacheLeer($st->claveCache($tabla, 'v' . $nombre, 0));
        return \is_array($v) && \is_array($v['r'] ?? null) && \is_array($v['t'] ?? null) ? $v : null;
    }

    /**
     * La cabecera de un trozo de claves de texto al que solo se le añaden
     * claves (2.10), a partir de la de antes: los bits de las nuevas en el
     * mismo filtro, y el mínimo y el máximo ampliados. Rehacerlo entero, dos
     * crc32 por cada una de sus mil claves, era lo que más pesaba de un INSERT
     * con un UNIQUE de texto. Null si no se puede: el trozo tiene otras claves
     * que textos, o el filtro ya no tiene sus FILTRO_BITS por clave (quien
     * llama lo rehace entonces con holgura, ver IndexesEscritura::resumenDeTexto()).
     *
     * @param array{filtro?: string, cubre?: string, min?: string, max?: string} $resumen el de antes
     * @param list<string> $nuevas las claves que no estaban
     * @param int          $total  cuántas claves tiene ahora el trozo
     */
    private static function resumenAmpliado(array $resumen, array $nuevas, int $total): ?array
    {
        if ($nuevas === []) {
            return $resumen;                                // los mismos textos: todo sigue valiendo
        }
        $bits = base64_decode((string)($resumen['filtro'] ?? ''), true);
        $m    = $bits === false ? 0 : \strlen($bits) << 3;
        if (($resumen['cubre'] ?? '') !== 'texto' || !isset($resumen['min'], $resumen['max'])
            || $m === 0 || $total * Indexes::FILTRO_BITS > $m) {
            return null;
        }
        $min = (string)$resumen['min'];
        $max = (string)$resumen['max'];
        foreach ($nuevas as $clave) {
            if ($clave === '' || $clave[0] !== 't') {
                return null;
            }
            // Los mismos bits que IndexesEscritura::resumenDeTexto()
            $b = crc32($clave) % $m;
            $s = (crc32(strrev($clave)) | 1) % $m;
            for ($i = 0; $i < Indexes::FILTRO_K; $i++) {
                $bits[$b >> 3] = \chr(\ord($bits[$b >> 3]) | 1 << ($b & 7));
                $b += $s;
                if ($b >= $m) {
                    $b -= $m;
                }
            }
            $sep = (int)strpos($clave, ':');
            $v   = substr($clave, $sep + 1, (int)substr($clave, 1, $sep - 1));
            if (strcmp($v, $min) < 0) {
                $min = $v;
            } elseif (strcmp($v, $max) > 0) {
                $max = $v;
            }
        }
        return ['filtro' => base64_encode($bits), 'cubre' => 'texto', 'min' => $min, 'max' => $max];
    }

    /**
     * El rango numérico de un trozo al que solo se le añaden claves (2.10), a
     * partir del de antes y del de las nuevas (ver IndexesEscritura::rango()), sin volver a
     * mirar las mil que ya tenía.
     *
     * @param array|null $antes el de antes: null si tiene algún texto, [] si no tenía claves
     * @param array|null $nuevas el de las claves que se añaden, igual
     */
    private static function rangoAmpliado(?array $antes, ?array $nuevas): ?array
    {
        if ($antes === null || $nuevas === null) {
            return null;
        }
        if ($antes === [] || $nuevas === []) {
            return $antes === [] ? $nuevas : $antes;
        }
        return [min($antes[0], $nuevas[0]), max($antes[1], $nuevas[1])];
    }

    /**
     * Escribe un trozo de índice y devuelve su rango numérico (ver IndexesEscritura::rango()).
     *
     * @param array<string, int|list<int>> $keys
     * @param array|null $resumen el de antes (filtro, min y max), si el trozo solo ha perdido claves: un
     *                            filtro con claves de más solo da algún falso positivo más, y hacerlo era
     *                            una décima parte de un DELETE de muchas filas (2.9)
     */
    private static function escribirTrozo(Storage $st, string $tabla, array $def, int $parte, int $rev, array $keys, ?array $resumen = null, ?array $ampliado = null): ?array
    {
        $idx = [
            'index'   => $def['name'],
            'table'   => $tabla,
            'columns' => $def['columns'],
            'part'    => $parte,
            'rev'     => $rev,
            'chunk'   => $st->filasPorParte,
        ];
        // Antes que las claves: una búsqueda lee la cabecera y el filtro, y si
        // la clave no puede estar, no lee las claves (ver Storage::clavesEnTexto()).
        // Con números repartidos (ver Indexes::filtraNumeros()), el filtro los cubre
        // Con rango, todas sus claves son números: si el filtro no los cubre,
        // no hay nada que resumir y no se recorren otra vez (cada trozo de un
        // índice numérico que se reescribe: el de la clave primaria en cada
        // INSERT, los de una clave foránea en una cascada)
        // Si solo ha ganado claves (ver escribirIndice()), rango y cabecera se
        // amplían; si el filtro ya va justo, se rehace con sitio para otras
        // tantas como la cuarta parte de las que tiene (2.10)
        $rango   = $ampliado === null ? IndexesEscritura::rango($keys) : $ampliado['rango'];
        $numeros = Indexes::filtraNumeros($rango, $st->filasPorParte);
        if ($resumen === null && $ampliado !== null && !$numeros && $rango === null) {
            $resumen = self::resumenAmpliado($ampliado['resumen'], $ampliado['nuevas'], \count($keys))
                ?? IndexesEscritura::resumenDeTexto($keys, false, \count($keys) >> 2);
        }
        $idx     = $idx + ($resumen ?? ($rango !== null && !$numeros ? ['filtro' => ''] : IndexesEscritura::resumenDeTexto($keys, $numeros)));
        $idx['keys'] = $keys;
        // Su filtro, para guardarlos todos al acabar (ver Storage::guardarFiltros())
        $st->indicesMemo[$tabla][$def['name']]['filtros'][$parte] = Indexes::filtro($idx) ?? '';
        // Sin claves (las filas de su parte, todas borradas) va al disco como
        // {} y no como [], igual que con ellas: trozoSano() mira que acabe en }}
        $texto = json_encode($keys === [] ? array_merge($idx, ['keys' => new \stdClass()]) : $idx, self::JSON_FILA) . "\n";
        self::escribirAtomico($st, $st->ficheroIndice($tabla, $def['name'], $parte), $texto, false);
        $st->tamTrozos[$st->etiqueta($tabla)][$def['name']][$parte] = \strlen($texto);
        // A la caché al confirmar, los primeros: una escritura que reescribe
        // cientos de trozos los tenía todos en memoria hasta el final (2.9);
        // los demás se guardan cuando se leen. Y ninguno si la memoria ya va
        // justa: con claves largas, 64 trozos eran más de 16 MB (2.9)
        if (\count($st->txCache) < self::TROZOS_A_CACHE && !Memoria::apretado()) {
            $st->txCache[] = [$st->claveCache($tabla, 'x' . $def['name'] . 'p' . $parte, $rev), $idx, $st->ficheroIndice($tabla, $def['name'], $parte), 'i'];
        }
        return $rango;
    }

    /**
     * ¿Hay alguna fila con esta clave de índice? Null si el índice no sirve.
     */
    public static function claveEnIndice(Storage $st, string $tabla, array $def, string $clave): ?bool
    {
        $donde = self::posicionesDeClave($st, $tabla, $def, $clave);
        return $donde === null ? null : $donde !== [];
    }

    /** ¿No tiene el índice ninguna clave? Todos sus trozos con rango [] (ver Storage::planIndice()) */
    public static function indiceVacio(Storage $st, string $tabla, array $def): bool
    {
        $st->bloquearLectura($tabla);
        return ($st->planIndice($tabla, $def)['orden'] ?? null) === [];
    }

    /**
     * Las posiciones de la clave en un índice único ([] si no está), o null si
     * el índice no sirve. Recorre los trozos y para en cuanto la encuentra.
     * Es lo que usa un upsert sin cargar la tabla, fila a fila (2.9).
     *
     * @return list<int>|null
     */
    public static function posicionesDeClave(Storage $st, string $tabla, array $def, string $clave): ?array
    {
        self::validarTabla($tabla);
        if (!$st->indices) {
            return null;
        }
        $st->bloquearLectura($tabla);
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null) {
            return null;
        }
        if ($plan['legado'] !== null) {
            return isset($plan['legado'][$clave]) ? Indexes::posiciones($plan['legado'][$clave]) : [];
        }
        // Una clave numérica fuera del mínimo y el máximo de todo el índice —un
        // id nuevo en una tabla con clave autoincremental— no está en ningún
        // trozo, y se sabe sin mirar ninguno
        $v = Indexes::valorNumerico($clave);
        if ($v !== null && $plan['min'] !== null && ($v < $plan['min'] || $v > $plan['max'])) {
            return [];
        }
        if ($v === null) {
            // La misma clave dos veces seguidas, como al mirar si choca y
            // después si está ocupada, se busca una (2.9)
            $nombre = $def['name'];
            $ultima = $st->indicesMemo[$tabla][$nombre]['ultima'] ?? null;
            if ($ultima !== null && $ultima[0] === $clave) {
                return $ultima[1];
            }
            $r = self::posicionesDeTexto($st, $tabla, $def, $plan, $clave);
            $st->indicesMemo[$tabla][$nombre]['ultima'] = [$clave, $r];
            return $r;
        }
        // Con los trozos en orden (ver Storage::planIndice()), el único que puede
        // tenerla, por bisección: mirar el rango de todos en cada fila hacía
        // un upsert de muchas filas más lento que cargando la tabla (2.9)
        $trozos = $plan['orden'] === null ? range(1, $plan['partes']) : self::trozoConValor($plan['orden'], $v);
        foreach ($trozos as $p) {
            $r = $plan['rangos'][$p - 1];
            if ($r !== null && ($r === [] || $v < $r[0] || $v > $r[1])) {
                continue;
            }
            $keys = $st->trozoIndice($tabla, $def, $p);
            if ($keys === null) {
                return null;
            }
            if (isset($keys[$clave])) {
                return Indexes::posiciones($keys[$clave]);
            }
        }
        return [];
    }

    /**
     * posicionesDeClave() de una clave de texto, que puede estar en cualquier
     * trozo.
     *
     * @return list<int>|null
     */
    private static function posicionesDeTexto(Storage $st, string $tabla, array $def, array $plan, string $clave): ?array
    {
        // Una clave de texto puede estar en cualquier trozo: hay que mirarlos
        // todos. Las primeras preguntas se contestan buscando la clave en el
        // texto de cada trozo, sin decodificarlo (ver Storage::clavesEnTexto()): un
        // INSERT de una fila no arma mil entradas por trozo para mirar una.
        // Una escritura de muchas filas pregunta muchas veces: a partir de
        // la novena, los trozos se decodifican una vez y se quedan
        $nombre = $def['name'];
        $cuenta = ($st->preguntasTexto[$tabla][$nombre] ?? 0) + 1;
        $st->preguntasTexto[$tabla][$nombre] = $cuenta;
        $memo   = $st->indicesMemo[$tabla][$nombre] ?? [];
        if (!isset($memo['*']) && !isset($memo['#'])) {
            // En un solo mapa clave => posiciones (2.9: una lista de los
            // trozos, y cada fila los miraba uno a uno), si cabe holgado en
            // lo que queda hasta la mitad de memory_limit, que lo demás es
            // para las filas que junta la sentencia antes de escribirlas
            // (ver Writer::tramo()); con un millón de filas, unos 100 MB por
            // índice. Si no, por la huella de cada clave (ver
            // huella()), menos de la mitad; y si tampoco, cada clave
            // se busca en los trozos que según su filtro pueden tenerla
            $limite = Memoria::limiteEnBytes();
            $libre  = $limite > 0 ? intdiv(intdiv($limite, 2) - memory_get_usage(), 3) : PHP_INT_MAX;
            $filas  = (int)($st->estado($tabla)['rows'] ?? 0);
            $forma  = $filas * 120 < $libre ? '*' : ($filas * 80 < $libre ? '#' : null);
            if ($cuenta <= 8 || $forma === null) {
                $filtros = $memo['filtros'] ?? [];
                $trozos  = $plan['partes'];
                if ($forma === null && $clave !== '' && $clave[0] === 't' && \count($filtros) === $plan['partes']) {
                    // Los filtros de todos, ya leídos (solo cubren las de texto)
                    $trozos = array_fill_keys(self::trozosPosibles($filtros, $clave), [$clave]);
                }
                $hay = $trozos === [] ? [] : $st->clavesEnTexto($tabla, $def, $trozos, [$clave], true);
                if ($hay !== null || $forma === null) {
                    return $hay === null ? null : array_keys($hay);
                }
            }
            $mapa = [];
            for ($p = 1; $p <= $plan['partes']; $p++) {
                $keys = $st->trozoIndice($tabla, $def, $p);
                if ($keys === null) {
                    return null;
                }
                foreach ($keys as $k => $donde) {
                    $h = $forma === '*' ? $k : self::huella((string)$k);
                    $mapa[$h] = isset($mapa[$h]) ? array_merge(Indexes::posiciones($mapa[$h]), Indexes::posiciones($donde)) : $donde;
                }
            }
            $st->indicesMemo[$tabla][$nombre][$forma] = $memo[$forma] = $mapa;
            unset($mapa);
        }
        if (isset($memo['*'])) {
            return Indexes::posiciones($memo['*'][$clave] ?? null);
        }
        // Por la huella, las posiciones que pueden ser; la clave se confirma
        // en el texto de sus trozos, que una huella puede ser de otra
        $trozos = [];
        foreach (Indexes::posiciones($memo['#'][self::huella($clave)] ?? null) as $pos) {
            $trozos[intdiv($pos, $st->filasPorParte) + 1] = [$clave];
        }
        $hay = $trozos === [] ? [] : $st->clavesEnTexto($tabla, $def, $trozos, [$clave], false);
        return $hay === null ? null : array_keys($hay);
    }

    /**
     * El trozo cuyo rango contiene el valor, en una lista de rangos en orden y
     * sin solaparse; ninguno si cae entre dos.
     *
     * @param list<array{0: int|float, 1: int|float, 2: int}> $orden
     * @return list<int>
     */
    private static function trozoConValor(array $orden, int|float $v): array
    {
        $a = 0;
        $b = \count($orden) - 1;
        while ($a <= $b) {
            $m = ($a + $b) >> 1;
            if ($v < $orden[$m][0]) {
                $b = $m - 1;
            } elseif ($v > $orden[$m][1]) {
                $a = $m + 1;
            } else {
                return [$orden[$m][2]];
            }
        }
        return [];
    }

    /** ¿Está el índice al día en todos sus trozos? */
    public static function indiceValido(Storage $st, string $tabla, array $def): bool
    {
        // Antes se buscaba una clave imposible en el texto de todos los trozos:
        // cada escritura leía el índice entero (2-5 veces), y la mitad del
        // INSERT de una fila se iba en eso. Basta con lo que mira trozoSano():
        // la cabecera (nombre, parte y revisión que dice _rev.json) y el final
        // del fichero, que además detecta un trozo cortado, cosa que la
        // búsqueda no hacía. Y el veredicto se recuerda mientras las
        // revisiones del índice en _rev.json sean las mismas
        self::validarTabla($tabla);
        if (!$st->indices) {
            return false;
        }
        $st->bloquearLectura($tabla);
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null) {
            return false;
        }
        if ($plan['legado'] !== null) {
            return true;
        }
        $revs = $st->estado($tabla)['indexes'][$def['name']] ?? null;
        if (!\is_array($revs)) {
            return false;
        }
        $firma = $plan['partes'] . ':' . implode(',', \array_slice($revs, 0, $plan['partes']));
        // Por la etiqueta de la tabla: una borrada y creada otra vez con el
        // mismo nombre vuelve a empezar en las mismas revisiones
        $firma = $st->etiqueta($tabla) . ':' . $firma;
        if (($st->indicesSanos[$tabla][$def['name']] ?? null) === $firma) {
            return true;
        }
        if (!self::trozosFiables($st, $tabla, $def, $revs, $plan['partes'])) {
            return false;
        }
        $st->indicesSanos[$tabla][$def['name']] = $firma;
        return true;
    }

    /**
     * Cuántos trozos del índice tienen en la caché un filtro de otra revisión,
     * o ninguno (ver cargarFiltros()); null si no hay nada guardado.
     *
     * @param list<int> $revs
     */
    private static function filtrosDesfasados(Storage $st, string $tabla, string $nombre, array $revs, int $partes): ?int
    {
        $v = $st->cacheLeer($st->claveCache($tabla, 'f' . $nombre, 0));
        if (!\is_array($v) || !\is_array($v['r'] ?? null)) {
            return null;
        }
        $n = 0;
        for ($i = 0; $i < $partes; $i++) {
            if (($v['r'][$i] ?? null) !== (int)($revs[$i] ?? -1)) {
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Revisiones y caché
    // ------------------------------------------------------------------

    /**
     * Elimina las entradas de caché de una tabla que deja atrás una escritura:
     * la estructura de la revisión anterior, y las partes e índices que se han
     * reescrito o borrado. Lo que no se ha tocado conserva su entrada. En
     * APCu no se puede borrar por patrón, así que se enumeran las claves.
     *
     * @param array    $antes    estado de la tabla antes de la escritura
     * @param array    $despues  estado después
     * @param string[] $indices  nombres de los índices que había en disco
     */
    public static function limpiarCache(Storage $st, string $tabla, array $antes, array $despues, array $indices): void
    {
        $revAntes  = (int)$antes['rev'];
        $partes    = $st->partes($tabla);
        $conservar = [];
        $sobran    = ['m:' . $revAntes];
        $siguen = [];                                  // revisiones con alguna parte que sigue igual
        for ($i = 0; $i < $partes; $i++) {
            $r = (int)($antes['parts'][$i] ?? $revAntes);
            if (($despues['parts'][$i] ?? null) === $r) {
                $conservar['p' . ($i + 1) . '-' . $r] = $siguen[$r] = true;
            } else {
                $sobran[] = 'p' . ($i + 1) . ':' . $r;
            }
        }
        // Las huellas de una escritura (ver Storage::parte()), mientras quede
        // alguna parte suya; las de esta aún no están, se guardan al confirmar
        foreach (array_unique(array_map('intval', (array)($antes['parts'] ?? []))) as $r) {
            if (isset($siguen[$r])) {
                $conservar['h-' . $r] = true;
            } else {
                $sobran[] = 'h:' . $r;
            }
        }
        foreach ($indices as $n) {
            $ra = $antes['indexes'][$n] ?? $revAntes;
            if (!\is_array($ra)) {
                $sobran[] = 'x' . $n . ':' . (int)$ra;      // un índice de antes de la 2.6
                continue;
            }
            foreach ($ra as $i => $r) {
                if (($despues['indexes'][$n][$i] ?? null) === (int)$r) {
                    $conservar['x' . $n . 'p' . ($i + 1) . '-' . (int)$r] = true;
                } else {
                    $sobran[] = 'x' . $n . 'p' . ($i + 1) . ':' . (int)$r;
                }
            }
        }
        $etiqueta = self::etiquetaDe($tabla, $antes);
        if ($st->apcu) {
            foreach ($sobran as $s) {
                apcu_delete($st->prefijo . $etiqueta . ':' . $s);
                // Con OPcache, el .php de cada parte reescrita, por su nombre
                // exacto: sin recorrer la carpeta, que hacía la escritura más lenta
                if ($st->opcache && $s[0] === 'p') {
                    @unlink(substr($st->ficheroCache($st->prefijo . $etiqueta . ':' . $s), 0, -6) . '.php');
                }
            }
            return;
        }
        // Lo que sobra, por su nombre (2.9): buscarlo en la carpeta era el 12 %
        // de un UPDATE. Las claves llevan la revisión, así que lo que no se
        // borre aquí ya no se lee nunca: cada 32 revisiones, y al borrar la
        // tabla, se barre todo lo suyo, por lo que haya dejado un corte o una
        // lectura que guardó una revisión ya pasada
        $md5    = md5($st->prefijo . $etiqueta);
        $barrer = $despues['rev'] < 0 || $despues['rev'] % 32 === 0;
        if (!$barrer) {
            foreach ($sobran as $s) {
                [$tipo, $r] = explode(':', $s);
                @unlink("$st->dirCache/$md5.$tipo-$r.cache");
                if ($st->opcache && $tipo[0] === 'p') {
                    @unlink("$st->dirCache/$md5.$tipo-$r.php");
                }
            }
        } else {
            // Las de clave fija se quedan mientras la tabla siga (2.9): el
            // recuento de cada parte y, de cada índice que queda, el veredicto,
            // los filtros y el menor y el mayor de sus trozos llevan dentro la
            // revisión de cada parte o trozo, y lo desfasado no se usa. Se
            // barrían con lo demás, y después la primera búsqueda de un texto
            // abría la cabecera de todos los trozos, la escritura siguiente los
            // volvía a mirar todos y COUNT(*) volvía a contar las partes
            if ($despues['rev'] >= 0) {
                $conservar['n-0'] = true;
                foreach (array_keys((array)$despues['indexes']) as $n) {
                    $conservar["f$n-0"] = $conservar["v$n-0"] = $conservar["l$n-0"] = true;
                }
            }
            // Cada entrada tiene su .cache y, en modo OPcache, su .php (o un
            // .tmp que se quedó a medias): con la misma búsqueda, sin buscar más
            foreach ((array)glob($st->dirCache . '/' . $md5 . '.*') as $f) {
                $nombre = substr(basename((string)$f), \strlen($md5) + 1);
                if (!isset($conservar[substr($nombre, 0, (int)strpos($nombre . '.', '.'))]) || str_ends_with($nombre, '.tmp')) {
                    @unlink((string)$f);
                }
            }
        }
        // Los resultados que dependían de la tabla, si tiene (ver
        // Storage::marcaResultados()). La marca se quita antes de buscarlos: un
        // resultado guardado después vuelve a ponerla
        if (@unlink($st->marcaResultados($tabla)) || $barrer) {
            foreach ((array)glob($st->dirCache . '/q.*' . md5($st->prefijo . $tabla) . '*.cache') as $f) {
                @unlink((string)$f);
            }
        }
    }

    /**
     * Construye el contenido de un índice a partir de las filas de la tabla.
     *
     * @param list<array>    $filas
     * @param list<string>   $columnas
     * @param array<int, ?string> $plegar la colación de cada columna, por posición (2.9; ver claveFila())
     * @return array<string, list<int>>
     */
    public static function construir(array $filas, array $columnas, array $plegar = []): array
    {
        $keys = [];
        foreach ($filas as $pos => $fila) {
            // Sin colación, la clave aquí mismo: esto va una vez por fila
            if ($plegar === []) {
                $valores = [];
                foreach ($columnas as $c) {
                    $valores[] = $fila[$c] ?? null;
                }
                $clave = Indexes::clave($valores);
            } else {
                $clave = Indexes::claveFila($fila, $columnas, $plegar);
            }
            if ($clave === null) {
                continue;                       // los NULL no se indexan
            }
            IndexesEscritura::anotar($keys, $clave, $pos);
            Memoria::comprobar('la construcción del índice');
        }
        return $keys;
    }

    /**
     * Quita del índice todas las posiciones a partir de $desde: las que se
     * han desplazado o desaparecido. Lo que queda son las filas de delante,
     * que no se han movido.
     *
     * @param array<string, int|list<int>> $keys
     * @return array<string, int|list<int>>
     */
    public static function recortar(array $keys, int $desde): array
    {
        foreach ($keys as $clave => $v) {
            if (\is_int($v)) {
                if ($v >= $desde) {
                    unset($keys[$clave]);
                }
                continue;
            }
            $quedan = [];
            foreach ($v as $p) {
                if ((int)$p < $desde) {
                    $quedan[] = (int)$p;
                }
            }
            if ($quedan === []) {
                unset($keys[$clave]);
            } elseif (\count($quedan) === 1) {
                $keys[$clave] = $quedan[0];
            } else {
                $keys[$clave] = $quedan;
            }
        }
        return $keys;
    }

    /**
     * Los trozos cuyo filtro dice que pueden tener una clave de texto (2.9),
     * como puedeEstar() en cada uno, para buscar una en todos los de un índice
     * grande: sus bits se calculan una vez por tamaño de filtro, no en cada
     * trozo.
     *
     * @param array<int, string> $filtros trozo => filtro
     * @return list<int>
     */
    public static function trozosPosibles(array $filtros, string $clave): array
    {
        $h1   = crc32($clave);
        $h2   = crc32(strrev($clave)) | 1;
        $bits = [];                                         // por tamaño de filtro: [byte, máscara] de cada bit
        $out  = [];
        foreach ($filtros as $p => $filtro) {
            if ($filtro === '') {
                continue;                                   // el trozo no tiene claves de texto
            }
            $m = (\strlen($filtro) - 1) << 3;                // tras el byte que dice qué cubre (ver puedeEstar())
            if (!isset($bits[$m])) {
                $bits[$m] = [];
                for ($i = 0; $i < Indexes::FILTRO_K; $i++) {
                    $b = ($h1 + $i * $h2) % $m;
                    $bits[$m][] = [1 + ($b >> 3), 1 << ($b & 7)];
                }
            }
            foreach ($bits[$m] as [$byte, $mascara]) {
                if ((\ord($filtro[$byte]) & $mascara) === 0) {
                    continue 2;
                }
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Un número de 64 bits que resume una clave (2.9): con las de un índice
     * grande en un mapa de huellas, que ocupa menos de la mitad que el de sus
     * claves (ver StorageEscritura::posicionesDeClave()). Dos claves distintas
     * pueden tener la misma: quien la usa confirma la clave.
     */
    public static function huella(string $clave): int
    {
        return crc32($clave) << 32 | crc32(strrev($clave));
    }
}
