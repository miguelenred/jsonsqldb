<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que el almacenamiento solo hace al cambiar o quitar filas que ya están
 * escritas (UPDATE y DELETE): por partes, sin leer ni reescribir la tabla
 * entera, compactando cuando los huecos pasan de la cuenta, y la escritura
 * por partes con su bloqueo compartido. Métodos de Storage que reciben el
 * almacenamiento en $st. Van aparte para que un INSERT no tenga que
 * compilarlos (2.9): sin OPcache, PHP compila en cada petición lo que carga.
 * Hereda de Storage solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageCambios extends Storage
{
    /** @var array{0: int, 1: int} escrituras por partes confirmadas y repetidas con la tabla entera, en este proceso */
    private static array $cuentaPartes = [0, 0];

    private function __construct()
    {
    }

    /** Escrituras por partes confirmadas y repetidas con la tabla entera en este proceso (para las pruebas). */
    public static function cuentaPartes(): array
    {
        return self::$cuentaPartes;
    }

    /** Anota una escritura por partes que tuvo que repetirse con la tabla entera. */
    public static function anotarRepeticion(): void
    {
        self::$cuentaPartes[1]++;
    }

    /**
     * Cambia y borra filas por posición sin leer la tabla entera: se leen y
     * reescriben solo las partes de las filas que cambian.
     *
     * Un DELETE no mueve las filas de detrás (2.9): cada borrada se queda como
     * un hueco, null, en su sitio, y se escribe como un cambio más. Antes las
     * movía todas un puesto, y eso reescribía las partes y los trozos de índice
     * de detrás: medio segundo por fila en una tabla de 60.000. Si con los
     * huecos nuevos la tabla pasara del 25 % de huecos, se compacta entera:
     * las filas se reparten otra vez sin ellos, como hace VACUUM. Los huecos
     * que quedarían al final no se dejan: la tabla se acorta (ver
     * largoSinHuecosAlFinal()).
     *
     * Las $nuevas van detrás, como en anadirFilas(): un upsert sin cargar la
     * tabla cambia, borra y añade en la misma sentencia (2.9).
     *
     * @param array<int,array> $cambios  posición => fila nueva
     * @param list<int>        $borradas posiciones que desaparecen
     * @param list<array{name: string, columns: list<string>, auto: bool}> $definiciones
     * @param bool             $compactar si se puede compactar la tabla (ver arriba)
     * @param array<int,array> $nuevas   filas que se añaden al final
     */
    public static function modificarFilas(Storage $st, string $tabla, array $cambios, array $borradas, array $definiciones = [],
                                          bool $compactar = true, array $nuevas = []): void
    {
        $nuevas = array_values($nuevas);
        [$filasAntes, $partesAntes] = StorageEscritura::situacion($st, $tabla, 'ESCRITURA');
        $chunk = $st->filasPorParte;
        if (!$compactar && $filasAntes === null) {
            // No pasa: DatabaseAvanzada::porTramos() deja antes la tabla como debe estar
            throw JsonSqlDbError::io("La tabla '$tabla' no se puede escribir por tramos: su estado no dice dónde acaba cada parte");
        }
        // Sin $compactar (un tramo de una sentencia que sigue, ver Writer::tramo()),
        // las borradas se quedan como huecos: compactar movería las filas que
        // la sentencia aún tiene que leer por su posición
        if ($borradas !== [] && $filasAntes !== null && (!$compactar
            || (array_sum((array)($st->estado($tabla)['huecos'] ?? [])) + \count($borradas)) * 4 <= $filasAntes)) {
            foreach ($borradas as $pos) {
                $cambios[$pos] = null;
            }
            $borradas = [];
        }
        // Las filas como quedan, por su posición: las del disco con los cambios
        // (un null es un hueco, y no se da) y sin las borradas. Se leen parte a
        // parte, solo si hacen falta, y nunca juntas (2.9: la tabla entera en
        // memoria, también para compactarla). Detrás, las nuevas desde $tras
        // (al compactar se renumeran todas y su posición no cuenta)
        $quedan = static function (int $tras = 0) use ($st, $tabla, $cambios, $borradas, $nuevas): \Generator {
            $fuera = array_fill_keys($borradas, true);
            foreach ($st->filas($tabla) as $pos => $fila) {
                $fila = \array_key_exists($pos, $cambios) ? $cambios[$pos] : $fila;
                if ($fila !== null && !isset($fuera[$pos])) {
                    yield $pos => $fila;
                }
            }
            foreach ($nuevas as $k => $fila) {
                yield $tras + $k => $fila;
            }
        };
        if ($filasAntes !== null && $borradas !== []) {
            StorageCompactacion::compactarPorBloques($st, $tabla, $cambios, $borradas, $nuevas, $filasAntes, $partesAntes, $definiciones, $quedan);
            return;
        }
        if ($filasAntes === null) {
            StorageEntera::reescribirEntera($st, $tabla, $quedan, StorageLectura::contarFilas($st, $tabla) - \count($borradas) + \count($nuevas), $partesAntes, $definiciones);
            return;
        }
        // Un hueco solo sirve para que no se muevan las filas de detrás: al
        // final no hay ninguna, y la tabla se acorta. Una tabla en la que se
        // añade y se borra al final, como una cola, los iba acumulando, y cada
        // INSERT reescribía una última parte cada vez más grande
        $largo = self::largoSinHuecosAlFinal($st, $tabla, $filasAntes, $cambios);
        $porParte = [];
        foreach ($cambios as $pos => $fila) {
            if ($pos < $largo) {
                $porParte[intdiv($pos, $chunk)][$pos - intdiv($pos, $chunk) * $chunk] = $fila;
            } else {
                unset($cambios[$pos]);
            }
        }
        $ultima = $largo === 0 ? 0 : intdiv($largo - 1, $chunk);
        $enUltima = $largo - $ultima * $chunk;
        if ($largo < $filasAntes && $enUltima < min($chunk, $filasAntes - $ultima * $chunk)) {
            $porParte[$ultima] ??= [];                // se corta aunque no cambie nada más en ella
        }
        // Las nuevas que caben en la última parte van en ella; las demás, a partes nuevas
        $caben = $nuevas === [] ? 0 : $chunk - $enUltima;
        if ($caben > 0) {
            $porParte[$ultima] ??= [];
        }
        $todas = fn(): \Generator => StorageEscritura::enPartes($quedan($largo), $chunk);
        // Cada parte se hace cuando escribirTabla() la va a escribir, y se suelta
        // al escribirla: juntas, un UPDATE de 100.000 filas repartidas por una
        // tabla de un millón tenía en memoria el texto de mil partes y agotaba
        // los 256 MB con un error fatal (2.9)
        $partes = (static function () use ($st, $tabla, $porParte, $ultima, $largo, $chunk, $filasAntes, $nuevas, $caben): \Generator {
            foreach ($porParte as $i => $enParte) {
                $corte = $i === $ultima ? $largo - $i * $chunk : null;
                $mas   = $i === $ultima ? \array_slice($nuevas, 0, $caben) : [];
                // Cada fila cambia en su sitio: se empalma en el texto de la parte
                $texto = $filasAntes === 0 ? null
                    : StorageEscritura::empalmarParte($st, $tabla, $i + 1, min($chunk, $filasAntes - $i * $chunk), $enParte, $mas, $corte);
                if ($texto !== null) {
                    yield $i => $texto;
                    continue;
                }
                $parte = $filasAntes === 0 ? [] : $st->parte($tabla, $i + 1, $st->ficheroDatos($tabla, $i + 1));
                foreach ($enParte as $j => $fila) {
                    $parte[$j] = $fila;
                }
                yield $i => array_merge($corte === null ? $parte : \array_slice($parte, 0, $corte), $mas);
            }
            foreach (array_chunk(\array_slice($nuevas, $caben), $chunk) as $k => $bloque) {
                yield $ultima + 1 + $k => $bloque;
            }
        })();
        StorageEscritura::escribirTabla($st, $tabla, null, $definiciones, $todas, $partes, $largo + \count($nuevas), $largo, $nuevas,
            $cambios, $partesAntes);
    }

    /**
     * Cuántas líneas tiene la tabla sin los huecos que le quedan al final
     * tras estos cambios (2.9): los de las borradas ahora (null en $cambios)
     * y los que ya había. Solo se mira si se borra la última línea; una parte
     * sin desfases (de antes de la 2.7) no se mira y se deja como está.
     *
     * @param array<int,?array> $cambios
     */
    private static function largoSinHuecosAlFinal(Storage $st, string $tabla, int $filas, array $cambios): int
    {
        if ($filas === 0 || !\array_key_exists($filas - 1, $cambios) || $cambios[$filas - 1] !== null) {
            return $filas;
        }
        $chunk  = $st->filasPorParte;
        $largo  = $filas;
        $parte  = 0;
        $saltos = null;
        $fh     = false;
        while ($largo > 0) {
            $pos = $largo - 1;
            if (\array_key_exists($pos, $cambios)) {
                if ($cambios[$pos] !== null) {
                    break;
                }
                $largo--;
                continue;
            }
            // Un hueco de antes: su línea es null
            if (intdiv($pos, $chunk) + 1 !== $parte) {
                if ($fh !== false) {
                    fclose($fh);
                }
                $parte   = intdiv($pos, $chunk) + 1;
                $fichero = $st->ficheroDatos($tabla, $parte);
                $saltos  = $st->desfasesDeParte($tabla, $parte, $fichero);
                $fh      = $saltos === null ? false : @fopen($fichero, 'rb');
            }
            $k = $pos - ($parte - 1) * $chunk;
            if ($fh === false || \strlen((string)$saltos) < 4 * ($k + 1)) {
                break;
            }
            // La línea entera, con su sangría: ninguna fila lleva un salto de
            // línea dentro, así que no se puede confundir con un null de una fila
            fseek($fh, (int)unpack('N', (string)$saltos, 4 * $k)[1] - 5);
            $linea = (string)fread($fh, 10);
            if ($linea !== "\n    null," && $linea !== "\n    null\n") {
                break;                                // una fila
            }
            $largo--;
        }
        if ($fh !== false) {
            fclose($fh);
        }
        return $largo;
    }

    /**
     * Filas de unas posiciones concretas, leyendo solo sus partes, según se
     * piden: en memoria, una parte a la vez (2.9: todas juntas, y un UPDATE
     * de 300.000 filas por un índice agotaba los 256 MB).
     *
     * @param list<int> $posiciones
     * @return \Generator<int,array> posición => fila, en orden de posición; las que no existen no salen
     */
    public static function filasEnPosiciones(Storage $st, string $tabla, array $posiciones): \Generator
    {
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $chunk = max(1, (int)($st->estado($tabla)['chunk'] ?? $st->filasPorParte));
        sort($posiciones);
        $porParte = [];
        foreach ($posiciones as $pos) {
            $porParte[intdiv($pos, $chunk) + 1][] = $pos;
        }
        foreach ($porParte as $parte => $enParte) {
            // Una racha de llamadas en la misma parte —un upsert de muchas filas
            // en el orden de la tabla— la lee a saltos hasta FILAS_POR_LINEA
            // filas, y después la decodifica una vez y se la queda (solo esa).
            // Leerlas siempre a saltos hacía un ON CONFLICT … DO UPDATE de 20.000
            // filas más lento que cargando la tabla (2.9)
            $clave = $st->etiqueta($tabla) . ':' . $parte . ':' . ($st->estado($tabla)['parts'][$parte - 1] ?? $st->rev($tabla));
            $racha = ($st->parteSuelta[0] ?? null) === $clave ? $st->parteSuelta : [$clave, 0, null];
            if ($racha[2] === null) {
                $fichero = $st->ficheroDatos($tabla, $parte);
                if (!is_file($fichero)) {
                    continue;
                }
                // Pocas filas de una parte: se leen sus líneas, sin decodificarla
                if (\count($enParte) <= self::FILAS_POR_LINEA && $racha[1] < self::FILAS_POR_LINEA) {
                    $sueltas = $st->filasEnParte($tabla, $parte, $fichero, array_map(static fn($p) => $p - ($parte - 1) * $chunk, $enParte));
                    if ($sueltas !== null) {
                        $st->parteSuelta = [$clave, $racha[1] + \count($enParte), null];
                        foreach ($sueltas as $d => $fila) {
                            yield $d + ($parte - 1) * $chunk => $fila;
                        }
                        continue;
                    }
                }
                $racha[2] = $st->parte($tabla, $parte, $fichero);
            }
            $st->parteSuelta = $racha;
            $filas = $racha[2];
            foreach ($enParte as $pos) {
                if (isset($filas[$pos - ($parte - 1) * $chunk])) {
                    yield $pos => $filas[$pos - ($parte - 1) * $chunk];
                }
            }
        }
    }

    /**
     * Todas las filas de la tabla con su posición, parte a parte: en memoria
     * solo hay una parte a la vez (un UPDATE o DELETE que recorre la tabla
     * guarda solo las filas que cumplen su WHERE).
     *
     * @return \Generator<int, array>
     */
    public static function filasConPosicion(Storage $st, string $tabla): \Generator
    {
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $todas = $st->partes($tabla);
        $chunk = max(1, (int)($st->estado($tabla)['chunk'] ?? $st->filasPorParte));
        $quedan = Memoria::cada();              // filas hasta mirar la memoria (ver Storage::filas())
        for ($parte = 1; $parte <= $todas; $parte++) {
            foreach ($st->parte($tabla, $parte, $st->ficheroDatos($tabla, $parte)) as $i => $fila) {
                if (--$quedan === 0) {
                    Memoria::revisar('la lectura de la tabla');
                    $quedan = Memoria::cada();
                }
                if ($fila !== null) {                       // un hueco (2.9)
                    yield ($parte - 1) * $chunk + $i => $fila;
                }
            }
        }
    }

    /**
     * Las filas de las partes que contienen los textos buscados (ver Storage::parte()),
     * con su posición: lo que un UPDATE o un DELETE con WHERE col = 'texto'
     * sobre una columna sin índice puede tocar, sin cargar la tabla entera.
     * Null si no hay textos. Aunque estén en casi todas las partes se sigue:
     * renunciar a medias obligaba a leerlas otra vez por el camino de siempre.
     *
     * @param list<list<string>> $agujas
     * @return \Generator<int,array>|null posición => fila, según se leen
     */
    public static function filasConTexto(Storage $st, string $tabla, array $agujas): ?\Generator
    {
        if ($agujas === []) {
            return null;
        }
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        return (static function () use ($st, $tabla, $agujas): \Generator {
            $todas = $st->partes($tabla);
            // El tamaño con que se escribieron las partes, no el configurado ahora:
            // si se cambió, las posiciones van por el de entonces
            $chunk = max(1, (int)($st->estado($tabla)['chunk'] ?? $st->filasPorParte));
            for ($parte = 1; $parte <= $todas; $parte++) {
                foreach ($st->parte($tabla, $parte, $st->ficheroDatos($tabla, $parte), $agujas) as $i => $fila) {
                    if ($fila !== null) {                       // un hueco (2.9)
                        yield ($parte - 1) * $chunk + $i => $fila;
                    }
                }
            }
        })();
    }

    /**
     * Bloqueo para una escritura por partes: compartido de la base y de la
     * tabla. Hace la recuperación de siempre al abrir la base.
     */
    public static function bloquearPartes(Storage $st, string $tabla): void
    {
        self::validarTabla($tabla);
        if ($st->lockNivel > 0) {
            throw JsonSqlDbError::lock('La escritura por partes tiene que ser el bloqueo de fuera');
        }
        $st->bloquear(false);
        try {
            $st->locksTabla[$tabla] = $st->abrirLock($st->dir . '/.' . $tabla . '.lock', false, "la tabla '$tabla'");
        } catch (\Throwable $e) {
            $st->desbloquear();                   // sin el compartido de la base cogido para siempre (2.9, ver Storage::bloquear())
            throw $e;
        }
        $st->modoPartes = $tabla;
        $st->pendientePartes = [];
        // Una tabla de antes de la 2.5, sin revisión por parte, no se puede
        // escribir por partes: se va por el camino de siempre
        $estado = $st->estado($tabla);
        $moderna = isset($estado['rows'], $estado['parts'], $estado['creada']) && \is_array($estado['parts']);
        foreach ($estado['indexes'] as $revs) {
            $moderna = $moderna && \is_array($revs);
        }
        if (!$moderna) {
            $st->desbloquear();
            throw new ConflictoPartes();
        }
    }

    /**
     * La segunda mitad de la escritura por partes: pasa al exclusivo de la
     * tabla, comprueba que lo que se reescribió no ha cambiado, y deja
     * preparada la revisión nueva con los cambios de los demás y los suyos.
     * Lanza ConflictoPartes, sin haber renombrado nada, si no puede ser.
     */
    public static function confirmarPartes(Storage $st): void
    {
        $t = (string)$st->modoPartes;
        $p = $st->pendientePartes[$t] ?? null;

        // Primero se suelta el compartido y después se pide el exclusivo, con
        // su torno: pedirlo sin soltar el otro dejaría a dos escrituras por
        // partes esperándose la una a la otra
        flock($st->locksTabla[$t], LOCK_UN);
        fclose($st->locksTabla[$t]);
        unset($st->locksTabla[$t]);
        $st->modoPartes = null;
        $st->locksTabla[$t] = $st->abrirLock($st->dir . '/.' . $t . '.lock', true, "la tabla '$t'");
        $st->lockExclusivo  = true;
        if ($p === null) {
            return;
        }

        $conflicto = function () use ($st): void {
            StorageEscritura::txAbandonar($st);
            throw new ConflictoPartes();
        };
        // Entre medias puede haber quedado un journal a medias de otro proceso,
        // o un barrido de temporales: los dos se resuelven por el camino de siempre
        clearstatcache();
        if ((array)glob($st->dirTx . '/*') !== []) {
            $conflicto();
        }
        foreach (array_keys($st->txRenombrar) as $tmp) {
            if (!is_file((string)$tmp)) {
                $conflicto();
            }
        }
        unset($st->estados[$t], $st->indicesMemo[$t]);
        $st->partesMemo = [];
        $fresco = $st->estado($t);
        $antes  = $p['antes'];
        $nuevo  = $p['nuevo'];
        $rev    = (int)$nuevo['rev'];
        $total  = \count($nuevo['parts']);
        $partesAntes = (int)$p['partesAntes'];

        if (($fresco['creada'] ?? null) !== ($antes['creada'] ?? null) || ($fresco['chunk'] ?? null) !== ($antes['chunk'] ?? null)
            || (string)@md5_file($st->ficheroMeta($t)) !== $p['meta']) {
            $conflicto();                       // la tabla o su estructura ha cambiado
        }
        $nombres = array_keys($fresco['indexes']);
        $propios = array_keys($nuevo['indexes']);
        sort($nombres);
        sort($propios);
        if ($nombres !== $propios) {
            $conflicto();
        }
        // Si cambia el número de filas, las posiciones de detrás se mueven: nadie
        // más puede haber añadido ni quitado filas
        if ((int)$nuevo['rows'] !== (int)$antes['rows']
            && ((int)$fresco['rows'] !== (int)$antes['rows'] || \count($fresco['parts']) !== $partesAntes)) {
            $conflicto();
        }
        // Las partes reescritas o quitadas, y los trozos de índice, tienen que
        // estar como estaban cuando se leyeron
        $tocada = static fn(int $i, $r): bool => $r === $rev || ($i >= $total && $i < $partesAntes);
        $m = $fresco;
        for ($i = 0; $i < max($total, $partesAntes); $i++) {
            if ($tocada($i, $nuevo['parts'][$i] ?? null) && ($fresco['parts'][$i] ?? null) !== ($antes['parts'][$i] ?? null)) {
                $conflicto();
            }
            if ($i < $total && ($nuevo['parts'][$i] ?? null) === $rev) {
                $m['parts'][$i]  = $rev;
                $m['huecos'][$i] = (int)($nuevo['huecos'][$i] ?? 0);
            }
        }
        // Se corta lo que esta escritura ha quitado del final (ver
        // largoSinHuecosAlFinal()), y nada más (2.9): se cortaba a las partes
        // que vio ella, y si entretanto un INSERT abría otra, el estado se
        // quedaba con una parte menos que la tabla y los índices sin usarse
        $corta = $total < $partesAntes;
        if ($corta) {
            $m['parts'] = \array_slice($m['parts'], 0, $total);
        }
        // Tantos como partes quedan: si otro ha acortado la tabla entretanto
        // (ver largoSinHuecosAlFinal()), son menos que las que vio esta escritura
        $huecos = [];
        for ($i = 0; $i < \count($m['parts']); $i++) {
            $huecos[] = (int)($m['huecos'][$i] ?? 0);
        }
        unset($m['huecos']);
        if (array_sum($huecos) > 0) {
            $m['huecos'] = $huecos;
        }
        foreach ($nuevo['indexes'] as $n => $revs) {
            $rangos = (array)($m['rangos'][$n] ?? []);
            for ($i = 0; $i < max($total, $partesAntes); $i++) {
                $r = $revs[$i] ?? null;
                if ($tocada($i, $r) && ($fresco['indexes'][$n][$i] ?? null) !== ($antes['indexes'][$n][$i] ?? null)) {
                    $conflicto();
                }
                if ($i < $total && $r === $rev) {
                    $m['indexes'][$n][$i] = $rev;
                    $rangos[$i] = $nuevo['rangos'][$n][$i] ?? null;
                }
            }
            $largo = $corta ? $total : \count((array)$m['indexes'][$n]);
            $m['indexes'][$n] = \array_slice(array_values((array)$m['indexes'][$n]), 0, $largo);
            // El rango de cada trozo en su sitio, y null en el que no lo tenga
            // (2.9): un estado de antes de la 2.7 no los trae, y con el de un
            // solo trozo puesto se corría al primero
            $m['rangos'][$n]  = \array_slice(array_replace(array_fill(0, $largo, null), $rangos), 0, $largo);
            // Hasta qué revisión tiene sus claves como la 2.10 (ver
            // Storage::clavesAlDia()), solo en los que tienen una columna de
            // texto (los que lo llevan en $nuevo): si ya valía, o si esta
            // escritura ha hecho todos sus trozos, hasta la de ahora; si no,
            // alguno puede ser de antes, y se queda sin él. Sin trozos nuevos se
            // queda el que tuviera, valga o no
            $juntas = $m['indexes'][$n];
            if (!isset($nuevo['textos'][$n])) {
                unset($m['textos'][$n]);
            } elseif ($juntas !== ($fresco['indexes'][$n] ?? null)) {
                $hasta = $fresco['textos'][$n] ?? null;
                $yaLa  = \is_int($hasta) && \is_array($fresco['indexes'][$n] ?? null) && $hasta <= (int)$fresco['rev']
                    && ($fresco['indexes'][$n] === [] || $hasta >= max($fresco['indexes'][$n]));
                if ($yaLa || array_diff($juntas, [$rev]) === []) {
                    $m['textos'][$n] = max((int)$fresco['rev'], $rev - 1) + 1;
                } else {
                    unset($m['textos'][$n]);
                }
            }
        }
        $m['rows'] = (int)$fresco['rows'] + ((int)$nuevo['rows'] - (int)$antes['rows']);
        $m['rev']  = max((int)$fresco['rev'], $rev - 1) + 1;

        $guardar = $m;
        $guardar['indexes'] = (object)$m['indexes'];
        $guardar['rangos']  = (object)$m['rangos'];
        if (($m['textos'] ?? []) === []) {
            unset($guardar['textos'], $m['textos']);
        } else {
            $guardar['textos'] = (object)$m['textos'];
        }
        StorageEscritura::escribirAtomico($st, $st->ficheroRev($t), json_encode($guardar, self::JSON_META) . "\n");
        StorageEscritura::limpiarCache($st, $t, $fresco, $m, $p['indicesAntes']);
        $st->estados[$t] = $m;
        $st->pendientePartes = [];
        self::$cuentaPartes[0]++;
    }
}
