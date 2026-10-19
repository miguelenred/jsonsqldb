<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que el almacenamiento hace pocas veces: crear, renombrar y borrar
 * tablas y bases, cambiar su estructura por bloques de texto, transacciones,
 * la recuperación tras un corte y la reparación de ficheros. Métodos de
 * Storage que reciben el almacenamiento en $st, como StorageEscritura, de la
 * que usa la escritura de ficheros. Van aparte para que una lectura o una
 * escritura de filas no tengan que compilarlos (2.9). Hereda de Storage solo
 * para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageMantenimiento extends Storage
{
    /** ALTER TABLE reescritos por bloques de texto en este proceso (ver reescribirLineas()) */
    private static int $cuentaBloques = 0;

    private function __construct()
    {
    }

    /**
     * Una clave de fila tal como la escribe el motor, con los dos puntos:
     * "nombre":. Con comillas, barras invertidas o caracteres de control en
     * el nombre, escapados como json_encode() (2.8: los nombres de columna
     * pueden llevarlos).
     */
    public static function claveJson(string $columna): string
    {
        return json_encode($columna, self::JSON_FILA) . ':';
    }

    /** Un valor de fila tal como lo escribe el motor. */
    public static function valorJson($valor): string
    {
        return (string)json_encode($valor, self::JSON_FILA);
    }

    // ------------------------------------------------------------------
    // Bases de datos
    // ------------------------------------------------------------------

    /** Crea la carpeta de una base de datos con su fichero de metadatos y protecciones. */
    public static function crearBase(string $raiz, string $base): void
    {
        if (!preg_match(self::RE_BASE, $base)) {
            throw JsonSqlDbError::config("Nombre de base de datos no válido: '$base'");
        }
        $raiz = rtrim(str_replace('\\', '/', $raiz), '/');
        $dir  = "$raiz/$base";
        if (is_dir($dir)) {
            throw JsonSqlDbError::config("La base de datos '$base' ya existe");
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw JsonSqlDbError::io("No se puede crear la carpeta de la base '$base'");
        }
        // Protección si la carpeta acaba estando dentro del webroot
        @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
        @file_put_contents("$dir/web.config",
            "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security>"
            . "<authorization><deny users=\"*\" /></authorization>"
            . "</security></system.webServer></configuration>\n");

        $info = [
            'database'   => $base,
            'engine'     => 'jsonSQLDB',
            'version'    => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (@file_put_contents("$dir/_database.json", json_encode($info, self::JSON_META) . "\n") === false) {
            throw JsonSqlDbError::io("No se puede escribir _database.json en '$base'");
        }
    }

    /** Borra por completo una base de datos y todo su contenido. */
    public static function borrarBase(string $raiz, string $base): void
    {
        if (!preg_match(self::RE_BASE, $base)) {
            throw JsonSqlDbError::config("Nombre de base de datos no válido: '$base'");
        }
        $dir = rtrim(str_replace('\\', '/', $raiz), '/') . '/' . $base;
        if (!is_file("$dir/_database.json")) {
            throw JsonSqlDbError::config("La base de datos '$base' no existe");
        }
        // Con el bloqueo exclusivo de la base, pasando por su torno: se espera
        // a que acaben las consultas que la están usando, y las que lleguen
        // después esperan a que termine el borrado en vez de ver ficheros
        // desapareciendo a mitad de una lectura
        $torno = @fopen("$dir/.turno", 'c');
        $lock  = @fopen("$dir/.lock", 'c');
        if ($torno === false || $lock === false) {
            throw JsonSqlDbError::lock("No se puede bloquear la base '$base' para borrarla");
        }
        flock($torno, LOCK_EX);
        flock($lock, LOCK_EX);
        flock($torno, LOCK_UN);
        try {
            // Los dos ficheros de bloqueo, los últimos: en Windows no se puede
            // borrar un fichero que alguien tiene abierto
            self::borrarRecursivo($dir, ["$dir/.lock", "$dir/.turno"]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            fclose($torno);
        }
        @unlink("$dir/.lock");
        @unlink("$dir/.turno");
        @rmdir($dir);

        // Si algo quedó sin borrar, decirlo: una base a medias es peor que un
        // error, porque parece que existe y no se puede usar
        clearstatcache();
        if (is_dir($dir)) {
            $quedan = array_diff((array)scandir($dir), ['.', '..']);
            throw JsonSqlDbError::io(
                "La base '$base' se ha borrado solo en parte: no se pudieron eliminar "
                . \count($quedan) . ' fichero(s). Comprueba los permisos y bórrala a mano.'
            );
        }
    }

    /** @param list<string> $salvo ficheros que no se tocan (se borran aparte) */
    private static function borrarRecursivo(string $dir, array $salvo = []): void
    {
        foreach ((array)scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $ruta = "$dir/$e";
            if (\in_array($ruta, $salvo, true)) {
                continue;
            }
            is_dir($ruta) ? self::borrarRecursivo($ruta) : @unlink($ruta);
        }
        @rmdir($dir);                       // falla sola si quedan los que se salvan
    }

    // ------------------------------------------------------------------
    // Bloqueo
    // ------------------------------------------------------------------

    /** ALTER TABLE reescritos por bloques de texto en este proceso (para las pruebas). */
    public static function cuentaBloques(): int
    {
        return self::$cuentaBloques;
    }

    /**
     * El manifiesto de la transacción, en .lote/ junto a los originales. No
     * acaba en .json: una tabla puede llamarse «manifiesto», y su copia y el
     * manifiesto eran el mismo fichero (2.9)
     */
    private static function manifiestoLote(Storage $st): string
    {
        return $st->dirLote() . '/manifiesto.lote';
    }

    /** Abre una transacción. Exige el bloqueo exclusivo de la base. */
    public static function loteIniciar(Storage $st): void
    {
        if (!$st->lockExclusivo || $st->locksTabla !== [] || $st->lockNivel === 0) {
            throw JsonSqlDbError::lock('Una transacción necesita el bloqueo exclusivo de la base');
        }
        if ($st->lote !== null) {
            throw JsonSqlDbError::lock('Ya hay una transacción abierta');
        }
        $dir = $st->dirLote();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw JsonSqlDbError::io('No se puede crear la carpeta de la transacción');
        }
        $st->lote = ['guardados' => [], 'nuevos' => []];
        self::loteManifiesto($st);
    }

    /**
     * Guarda los originales de los ficheros que una escritura va a reemplazar
     * o borrar, y apunta los que todavía no existen; el manifiesto, forzado a
     * disco, antes de tocar ninguno.
     *
     * @param list<string> $finales
     */
    public static function loteGuardar(Storage $st, array $finales): void
    {
        $cambio = false;
        $antes  = [$st->lote['guardados'], $st->lote['nuevos']];
        // Lo guardado, al manifiesto también si otro fichero falla después; y
        // si el manifiesto no se puede escribir, no cuenta como guardado y se
        // guarda otra vez la próxima (2.10: quedaba apuntado solo en memoria,
        // las sentencias siguientes ya no lo guardaban, y deshacer no lo devolvía)
        try {
            foreach ($finales as $f) {
                $nombre = basename($f);
                if (isset($st->lote['guardados'][$nombre]) || isset($st->lote['nuevos'][$nombre])) {
                    continue;
                }
                clearstatcache(true, $f);
                if (is_file($f)) {
                    $copia = $st->dirLote() . '/' . $nombre;
                    @unlink($copia);
                    if (!@link($f, $copia) && !self::copiarSeguro($st, $f, $copia)) {
                        throw JsonSqlDbError::io('No se puede guardar el original de ' . $nombre . ' para la transacción');
                    }
                    $st->lote['guardados'][$nombre] = true;
                } else {
                    $st->lote['nuevos'][$nombre] = true;
                }
                $cambio = true;
            }
        } finally {
            if ($cambio) {
                try {
                    self::loteManifiesto($st);
                } catch (\Throwable $e) {
                    [$st->lote['guardados'], $st->lote['nuevos']] = $antes;
                    throw $e;
                }
            }
        }
    }

    private static function loteManifiesto(Storage $st): void
    {
        StorageEscritura::volcarFichero(self::manifiestoLote($st), json_encode([
            'guardados' => array_keys($st->lote['guardados']),
            'nuevos'    => array_keys($st->lote['nuevos']),
            'ts'        => date('Y-m-d H:i:s'),
        ], self::JSON_META) . "\n");
    }

    /** Confirma la transacción: lo escrito se queda, y los originales sobran. */
    public static function loteConfirmar(Storage $st): void
    {
        if ($st->lote === null) {
            return;
        }
        if (isset($st->lote['rota'])) {
            self::loteDeshacer($st);
            throw JsonSqlDbError::io('La transacción se ha deshecho: una de sus escrituras se quedó a medias');
        }
        // Lo escrito, a disco antes del punto de no retorno, cada fichero una
        // vez (2.10: cada sentencia forzaba los suyos, y en una transacción de
        // cien INSERT eran trescientos fsync; ver StorageEscritura::escribirTemporal())
        if (function_exists('fsync')) {
            foreach (array_keys($st->lote['sincronizar'] ?? []) as $f) {
                $fh = is_file((string)$f) ? @fopen((string)$f, 'r+') : false;
                if ($fh !== false) {
                    @fsync($fh);
                    @fclose($fh);
                    StorageEscritura::sincronizados(1);
                }
            }
        }
        StorageEscritura::fsyncDir($st->dir);
        // Sin manifiesto, la transacción ya no se deshace: es el punto de no retorno
        @unlink(self::manifiestoLote($st));
        StorageEscritura::fsyncDir($st->dirLote());
        self::borrarLote($st);
        $olvidar = $st->lote['olvidar'] ?? [];
        $st->lote = null;
        if ($olvidar !== []) {
            // Después del punto de no retorno: un corte aquí la deja pendiente
            // (se puede olvidar otra vez), nunca olvidada sin lo demás
            [$lista, $siguiente] = self::leerApartadas($st);
            self::guardarApartadas($st, array_filter($lista, static fn($p): bool => !isset($olvidar[$p['id'] ?? null])), $siguiente);
        }
    }

    /**
     * Deshace la transacción abierta: todo vuelve a como estaba al empezarla.
     * Si algo no se puede devolver, la transacción se suelta igual: su
     * manifiesto sigue en `.lote/` y el siguiente bloqueo lo vuelve a
     * intentar (ver Storage::recuperar()). 2.10: se quedaba abierta, y lo
     * que hiciera después la conexión iba dentro de ella y se perdía.
     */
    public static function loteDeshacer(Storage $st): void
    {
        if ($st->lote === null) {
            return;
        }
        try {
            StorageEscritura::txAbandonar($st);
            self::devolverLote($st, array_keys($st->lote['guardados']), array_keys($st->lote['nuevos']));
        } finally {
            $st->lote = null;
        }
    }

    /**
     * Devuelve los originales guardados y quita los ficheros que la
     * transacción creó. Después, cada tabla tocada sube de generación y se
     * vacía su caché: sus revisiones vuelven a números ya usados en lo
     * deshecho, y nada de eso puede salir de ninguna caché.
     *
     * @param list<string> $guardados
     * @param list<string> $nuevos
     */
    private static function devolverLote(Storage $st, array $guardados, array $nuevos): void
    {
        $tablas = [];
        foreach (array_merge($guardados, $nuevos) as $nombre) {
            $t = strstr((string)$nombre, '.', true);
            if ($t !== false && preg_match(self::RE_TABLA, $t)) {
                $tablas[$t] = true;
            }
        }
        // La generación más alta de cada tabla, la de ahora, antes de devolver nada
        $gen = [];
        foreach (array_keys($tablas) as $t) {
            clearstatcache(true, $st->ficheroRev($t));
            $e = json_decode((string)@file_get_contents($st->ficheroRev($t)), true);
            $gen[$t] = \is_array($e) ? (int)($e['gen'] ?? 0) : 0;
            self::vaciarCacheDe($st, $t, \is_array($e) ? $e : []);
        }
        foreach ($guardados as $nombre) {
            $copia = $st->dirLote() . '/' . $nombre;
            $final = $st->dir . '/' . $nombre;
            if (is_file($copia) && !@rename($copia, $final) && !StorageEscritura::reemplazar($copia, $final)) {
                throw JsonSqlDbError::io('No se puede devolver ' . $nombre . ' al deshacer la transacción: sigue en .lote/');
            }
        }
        foreach ($nuevos as $nombre) {
            @unlink($st->dir . '/' . $nombre);
        }
        foreach (array_keys($tablas) as $t) {
            clearstatcache(true, $st->ficheroRev($t));
            $e = json_decode((string)@file_get_contents($st->ficheroRev($t)), true);
            if (\is_array($e)) {
                self::vaciarCacheDe($st, $t, $e);
                $e['gen'] = max($gen[$t], (int)($e['gen'] ?? 0)) + 1;
                foreach (['indexes', 'rangos'] as $k) {
                    if (isset($e[$k]) && $e[$k] === []) {
                        $e[$k] = (object)[];
                    }
                }
                StorageEscritura::volcarFichero($st->ficheroRev($t), json_encode($e, self::JSON_META) . "\n");
            }
        }
        StorageEscritura::fsyncDir($st->dir);
        self::$memoProceso = [];
        $st->estados = $st->indicesMemo = $st->partesMemo = [];
        @unlink(self::manifiestoLote($st));
        self::borrarLote($st);
    }

    /** Toda la caché de una tabla con ese estado: disco (también sus .php) y APCu */
    private static function vaciarCacheDe(Storage $st, string $tabla, array $estado): void
    {
        foreach ((array)glob($st->dirCache . '/' . md5($st->prefijo . self::etiquetaDe($tabla, $estado)) . '.*') as $f) {
            @unlink((string)$f);
        }
        @unlink($st->marcaResultados($tabla));
        foreach ((array)glob($st->dirCache . '/q.*' . md5($st->prefijo . $tabla) . '*.cache') as $f) {
            @unlink((string)$f);
        }
        @unlink($st->ficheroEstado($tabla));
        if ($st->apcu && class_exists('APCUIterator')) {
            // Solo las claves (2.10): sin APC_ITER_KEY el iterador trae también
            // los valores, y deshacer una transacción cargaba en memoria la
            // caché entera de la base (20 MB en APCu, 33 MB de pico)
            apcu_delete(new \APCUIterator('/^' . preg_quote($st->prefijo, '/') . '/', APC_ITER_KEY));
        }
    }

    private static function borrarLote(Storage $st): void
    {
        foreach ((array)glob($st->dirLote() . '/*') as $f) {
            @unlink((string)$f);
        }
        @rmdir($st->dirLote());
    }

    /**
     * Una transacción que un proceso dejó a medias al morir: se deshace al
     * abrir la base, con su exclusivo (si quien abre solo lee, lo sube un
     * momento, como el journal).
     */
    public static function recuperarLote(Storage $st, bool $yaExclusivo): void
    {
        $m = json_decode((string)@file_get_contents(self::manifiestoLote($st)), true);
        if (!\is_array($m)) {
            if (is_dir($st->dirLote())) {
                self::borrarLote($st);                      // sin manifiesto: se confirmó, solo faltaba limpiar
            }
            return;
        }
        if (!$yaExclusivo && !@flock($st->lock, LOCK_EX)) {
            return;
        }
        clearstatcache(true, self::manifiestoLote($st));
        if (is_file(self::manifiestoLote($st))) {    // por si otro se adelantó
            self::devolverLote($st, self::deLaBase((array)($m['guardados'] ?? [])), self::deLaBase((array)($m['nuevos'] ?? [])));
        }
        if (!$yaExclusivo) {
            @flock($st->lock, LOCK_SH);
        }
    }

    /**
     * Recoge un journal de antes de la 2.0, que dejaba las copias sueltas en la
     * raíz de `.tx/` (lo dice su manifiesto, ver Storage::recuperar()). Se
     * mueve a `.tx/_base/` y lo deshace el código de siempre.
     */
    public static function migrarJournalPlano(Storage $st): void
    {
        $destino = self::dirJournal($st, null);
        if (!@mkdir($destino, 0775, true) && !is_dir($destino)) {
            return;
        }
        // El manifiesto, el último: sin él la carpeta nueva no se restaura
        foreach ((array)glob($st->dirTx . '/*') as $f) {
            $nombre = basename((string)$f);
            if ($nombre !== 'manifiesto.json' && is_file((string)$f)) {
                @rename((string)$f, $destino . '/' . $nombre);
            }
        }
        @rename($st->dirTx . '/manifiesto.json', $destino . '/manifiesto.json');
        clearstatcache(true, $destino);
    }

    /** Aplica el journal de base. Exige el exclusivo de la base. */
    public static function recuperarBase(Storage $st, bool $yaExclusivo): void
    {
        // Leyendo, se sube el bloqueo un momento y se vuelve a bajar. Convertir
        // suelta antes el compartido, así que dos lectores no se esperan.
        if (!$yaExclusivo && !@flock($st->lock, LOCK_EX)) {
            return;
        }
        clearstatcache(true, self::dirJournal($st, null));
        clearstatcache(true, StorageEscritura::ficheroJournal($st, null));
        if (is_file(StorageEscritura::ficheroJournal($st, null)) || is_dir(self::dirJournal($st, null))) {   // por si otro se adelantó
            self::aplicarJournal($st, null);
        }
        if (!$yaExclusivo) {
            @flock($st->lock, LOCK_SH);
        }
    }

    /**
     * Aplica el journal de una tabla. Exige el exclusivo de todas las tablas
     * que lista, y lo pide sin esperar: si no lo consigue es que hay una
     * escritura viva y el journal está en uso.
     */
    public static function recuperarTabla(Storage $st, string $ambito): void
    {
        $manifiesto = self::leerManifiesto($st, $ambito);
        $tablas     = \is_array($manifiesto) ? (array)($manifiesto['tablas'] ?? []) : [];
        $tablas     = array_values(array_filter(
            array_map('strval', $tablas),
            static fn(string $t): bool => preg_match(self::RE_TABLA, $t) === 1
        ));
        if ($tablas === []) {
            $tablas = [$ambito];
        }
        sort($tablas, SORT_STRING);                   // el mismo orden que al escribir

        $fhs = [];
        try {
            foreach ($tablas as $t) {
                if (isset($st->locksTabla[$t])) {
                    return;                           // es el nuestro, está en curso
                }
                $fh = @fopen($st->dir . '/.' . $t . '.lock', 'c');
                if ($fh === false) {
                    return;
                }
                $fhs[] = $fh;
                if (!@flock($fh, LOCK_EX | LOCK_NB)) {
                    return;                           // hay una escritura viva: no es huérfano
                }
            }
            clearstatcache(true, self::dirJournal($st, $ambito));
            clearstatcache(true, StorageEscritura::ficheroJournal($st, $ambito));
            if (is_file(StorageEscritura::ficheroJournal($st, $ambito)) || is_dir(self::dirJournal($st, $ambito))) {
                self::aplicarJournal($st, $ambito);
            }
        } finally {
            foreach ($fhs as $fh) {
                @flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    /**
     * El manifiesto de un ámbito, esté en su fichero (2.7) o en su carpeta
     * (2.0 a 2.6), o null si no hay ninguno legible.
     */
    private static function leerManifiesto(Storage $st, ?string $ambito): ?array
    {
        $fichero = StorageEscritura::ficheroJournal($st, $ambito);
        if (!is_file($fichero)) {
            $fichero = self::dirJournal($st, $ambito) . '/manifiesto.json';
        }
        $m = json_decode((string)@file_get_contents($fichero), true);
        return \is_array($m) ? $m : null;
    }

    /** Aplica o deshace el journal de un ámbito, según de qué versión sea. */
    private static function aplicarJournal(Storage $st, ?string $ambito): void
    {
        $manifiesto = self::leerManifiesto($st, $ambito);

        // Sin manifiesto no hay nada que hacer: se escribe DESPUÉS de los
        // temporales y de una pieza, así que si falta no se tocó ningún dato
        if ($manifiesto !== null && ($manifiesto['tipo'] ?? '') === 'redo') {
            $renombrar    = [];
            $regenerables = [];
            $sinRehacer   = array_fill_keys((array)($manifiesto['regenerables'] ?? []), true);
            // Solo ficheros de la base, cada uno con su temporal (2.9): lo
            // demás no lo pudo escribir el motor (ver deLaBase())
            foreach ((array)($manifiesto['renombrar'] ?? []) as $tmp => $f) {
                if (self::deLaBase([$f]) !== [] && preg_match('/^' . preg_quote((string)$f, '/') . '\.\d+\.tmp$/', (string)$tmp)) {
                    $renombrar[$st->dir . '/' . $tmp] = $st->dir . '/' . $f;
                    if (isset($sinRehacer[$f])) {
                        $regenerables[$st->dir . '/' . $tmp] = true;
                    }
                }
            }
            $borrar = [];
            foreach (self::deLaBase((array)($manifiesto['borrar'] ?? [])) as $f) {
                $borrar[] = $st->dir . '/' . $f;
            }
            StorageEscritura::rehacer($st, $renombrar, $borrar, $regenerables);
        } elseif ($manifiesto !== null && ($manifiesto['estado'] ?? '') !== '') {
            self::deshacer($st, $ambito, $manifiesto);
        }
        foreach ((array)($manifiesto['tablas'] ?? []) as $t) {
            if (\is_string($t)) {
                unset($st->estados[$t]);
            }
        }
        @unlink(StorageEscritura::ficheroJournal($st, $ambito));
        self::borrarDirJournal($st, $ambito);
    }

    /**
     * Deshace un journal de una versión anterior a la 2.5: copias de los
     * ficheros de antes de la escritura, que se vuelven a poner en su sitio.
     */
    private static function deshacer(Storage $st, ?string $ambito, array $manifiesto): void
    {
        if ($manifiesto['estado'] === 'COMMITTED') {
            return;                               // terminó bien y solo faltaba limpiar
        }
        $dir    = self::dirJournal($st, $ambito);
        $tablas = array_values(array_filter((array)($manifiesto['tablas'] ?? []),
            static fn($t): bool => \is_string($t) && preg_match(self::RE_TABLA, $t) === 1));
        if ($tablas === [] && $ambito !== null) {
            $tablas = [$ambito];
        }
        // Que las copias midan lo anotado: si no, el journal no es de fiar y lo
        // único seguro es pararse. Los manifiestos anteriores a la 2.0 traían
        // una lista de nombres sin tamaño.
        foreach ((array)($manifiesto['ficheros'] ?? []) as $nombre => $tam) {
            if (!\is_string($nombre) || !\is_int($tam)) {
                continue;
            }
            clearstatcache(true, $dir . '/' . $nombre);
            if (!is_file($dir . '/' . $nombre) || filesize($dir . '/' . $nombre) !== $tam) {
                throw JsonSqlDbError::io(
                    "El journal de '" . ($ambito ?? 'la base') . "' está dañado: la copia de "
                    . "'$nombre' no mide lo que debería. No se ha tocado nada. La carpeta "
                    . basename($dir) . ' sigue ahí con las copias para revisarlas a mano.'
                );
            }
        }
        foreach (self::ficherosDe($st, $tablas, $ambito === null) as $f) {
            @unlink($f);                              // fuera lo que dejó a medias
        }
        foreach (self::deLaBase(array_diff(array_map('basename', (array)glob($dir . '/*')), ['manifiesto.json'])) as $nombre) {
            self::copiarSeguro($st, $dir . '/' . $nombre, $st->dir . '/' . $nombre);
        }
        // La caché en disco puede tener entradas de la revisión deshecha
        foreach ($tablas as $t) {
            if (\is_string($t)) {
                unset($st->estados[$t]);
                foreach ((array)glob($st->dirCache . '/' . md5($st->prefijo . $st->etiqueta($t)) . '.*') as $f) {
                    @unlink((string)$f);                   // .cache, y su .php del modo OPcache
                }
            }
        }
    }

    /**
     * De unos nombres, los de ficheros de la base: datos, partes, estructura,
     * revisión e índices de una tabla, y los de la base. Es todo lo que un
     * diario o una transacción puede devolver, renombrar o borrar (2.9): sus
     * listas se leen del disco al abrir la base, y una que viniera de otra
     * parte (un ZIP restaurado) no puede tocar nada más de su carpeta, como su
     * .htaccess o su web.config.
     *
     * @param array<mixed> $nombres
     * @return list<string>
     */
    private static function deLaBase(array $nombres): array
    {
        return array_values(array_filter($nombres, static fn($n): bool => \is_string($n) && preg_match(
            '/^(?:_database|_views|_revs|[A-Za-z_][A-Za-z0-9_]{0,63}(?:\.meta|\.rev|\.idx\.[A-Za-z_][A-Za-z0-9_]{0,63})?(?:\.part\d+)?)\.json$/', $n) === 1));
    }

    /**
     * Ficheros en disco de unas tablas: datos, partes, estructura, revisión e
     * índices; con $conBase, también los de la base.
     *
     * @param string[] $tablas
     * @return string[]
     */
    private static function ficherosDe(Storage $st, array $tablas, bool $conBase): array
    {
        $out = [];
        foreach ($tablas as $t) {
            if (!\is_string($t) || $t === '') {
                continue;
            }
            foreach (['.json', '.part*.json', '.meta.json', '.rev.json', '.idx.*.json'] as $patron) {
                foreach ((array)glob($st->dir . '/' . $t . $patron) as $f) {
                    $out[] = (string)$f;
                }
            }
        }
        if ($conBase) {
            foreach (['_views.json', '_database.json', '_revs.json'] as $f) {
                if (is_file($st->dir . '/' . $f)) {
                    $out[] = $st->dir . '/' . $f;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Copia un fichero por trozos forzándolo a disco (copy() lo deja en la caché del sistema). */
    private static function copiarSeguro(Storage $st, string $origen, string $destino): bool
    {
        $in = @fopen($origen, 'rb');
        if ($in === false) {
            return false;
        }
        $out = @fopen($destino, 'wb');
        if ($out === false) {
            fclose($in);
            return false;
        }
        try {
            return @stream_copy_to_stream($in, $out) !== false && @fflush($out)
                && (!function_exists('fsync') || @fsync($out));
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** Retira la carpeta de journal de un ámbito, de las versiones 2.0 a 2.6. */
    private static function borrarDirJournal(Storage $st, ?string $ambito): void
    {
        $dir = self::dirJournal($st, $ambito);
        if (!is_dir($dir)) {
            return;
        }
        // El manifiesto, primero: es lo que da por válido el resto
        @unlink($dir . '/manifiesto.json');
        foreach ((array)glob($dir . '/*') as $f) {
            @unlink((string)$f);
        }
        @rmdir($dir);
        // Que haya desaparecido de verdad: tras un corte no debe volver a verse
        StorageEscritura::fsyncDir($st->dirTx);
        clearstatcache(true, $dir);
    }

    // ------------------------------------------------------------------
    // Tablas
    // ------------------------------------------------------------------

    /** Guarda la estructura de una tabla e invalida su caché. */
    public static function guardarMeta(Storage $st, string $tabla, array $meta, array $definiciones = []): void
    {
        StorageEntera::guardarTabla($st, $tabla, null, $meta, $definiciones);
    }

    // ------------------------------------------------------------------
    // Vistas
    // ------------------------------------------------------------------

    /** @param array<string,array> $vistas */
    public static function guardarVistas(Storage $st, array $vistas): void
    {
        StorageEscritura::exigirEscritura($st);
        $propia = $st->txAmbito === null;
        if ($propia) {
            StorageEscritura::txIniciar($st, 'VISTAS');
        }
        StorageEscritura::escribirAtomico($st, $st->dir . '/_views.json', json_encode($vistas, self::JSON_META) . "\n");
        if ($propia) {
            StorageEscritura::txConfirmar($st);
        }
    }

    // ------------------------------------------------------------------
    // Lectura de filas
    // ------------------------------------------------------------------

    /**
     * Los ficheros de una tabla estropeados a mano (2.9). Sin $definiciones
     * solo los cuenta (CHECK TABLES). Con ellas —los índices de la tabla— los
     * repara: guarda el original de cada uno en .reparados/ con la fecha y
     * reescribe la tabla entera por el camino normal de escritura, que deja
     * las partes en su formato, rehace índices y desfases y cambia las
     * revisiones, así que ninguna caché vieja vale. Una fila que no se puede
     * arreglar con seguridad para la reparación con su error, salvo con
     * $apartar (REPAIR TABLES): entonces se quita de la tabla, y sigue en la
     * copia del original. La estructura no se adivina nunca. Con $editada (un
     * fichero editado a mano y válido) se reescribe aunque no haya nada roto.
     * Al reparar, quien llama tiene el exclusivo de la base.
     *
     * @param list<array>|null $definiciones
     * @return list<array{tabla: string, fichero: string, problema: string, arreglo: string}>
     */
    public static function revisarFicheros(Storage $st, string $tabla, ?array $definiciones = null,
                                           bool $apartar = false, bool $editada = false): array
    {
        $reparar = $definiciones !== null;
        $out     = [];
        $rotos   = [];
        $anotar  = static function (string $fichero, string $problema, string $arreglo, ?int $apartada = null) use (&$out, $tabla): void {
            $out[] = ['tabla' => $tabla, 'fichero' => basename($fichero), 'problema' => $problema, 'arreglo' => $arreglo,
                      'apartada' => $apartada];
        };
        // Las filas que un REPAIR TABLES anterior apartó y siguen pendientes
        [$pendientes, $siguiente] = self::leerApartadas($st);
        $nuevas = [];
        if (!$reparar) {
            foreach ($pendientes as $p) {
                if ($p['tabla'] === $tabla) {
                    $anotar($p['fichero'], ($p['linea'] > 0 ? "línea {$p['linea']}: " : '') . $p['texto'], self::PENDIENTE, $p['id']);
                }
            }
        }

        $f = $st->ficheroMeta($tabla);
        if (!\is_array(json_decode((string)@file_get_contents($f), true))) {
            $sola = Reparador::objeto((string)@file_get_contents($f)) !== null;
            $anotar($f, 'estructura ilegible', $sola ? 'se arregla sola' : 'a mano: la estructura no se adivina');
            if (!$sola && $reparar) {
                throw JsonSqlDbError::io("Estructura ilegible en '$tabla.meta.json': no se puede arreglar sola, corrígela a mano");
            }
            $rotos[] = $f;
        }
        $f = $st->ficheroRev($tabla);
        if (is_file($f) && !\is_array(json_decode((string)file_get_contents($f), true))) {
            $anotar($f, 'estado ilegible', 'se rehace con los datos');
            $rotos[] = $f;
        }
        // Las filas para reescribir la tabla, si se repara, se juntan mientras
        // caben holgadas; si no, se sueltan y se vuelven a leer parte a parte
        // al escribirla (2.9: se juntaban todas, y una tabla más grande que la
        // memoria fallaba con un error fatal en cada lectura que la reparaba)
        $filas   = [];
        $juntas  = $reparar;
        $cuantas = 0;
        $juntar  = static function (array $deParte) use (&$filas, &$juntas, &$cuantas): void {
            $cuantas += \count($deParte);
            if ($juntas) {
                array_push($filas, ...$deParte);
                if (Memoria::apretado()) {
                    $filas  = [];
                    $juntas = false;
                }
            }
        };
        for ($p = 1, $n = max($st->partes($tabla), $st->partesSegunEstado($tabla)); $p <= $n; $p++) {
            $f = $st->ficheroDatos($tabla, $p);
            if (!is_file($f)) {
                // Una parte borrada no se rehace: sus filas no están en ningún sitio
                $anotar($f, 'falta el fichero', 'a mano: restáuralo de una copia');
                if ($reparar) {
                    throw JsonSqlDbError::io("Falta " . basename($f) . ": restáuralo de una copia antes de reparar '$tabla'");
                }
                continue;
            }
            $texto   = (string)file_get_contents($f);
            $validas = self::filasValidas($texto);
            if ($validas !== null) {
                $juntar($validas);
                continue;
            }
            $r       = Reparador::parte($texto);
            $rotos[] = $f;
            if ($r['dudosas'] === []) {
                $anotar($f, 'JSON roto', 'se arregla solo');
            } elseif ($reparar && !$apartar) {
                throw JsonSqlDbError::io(self::avisoDudosas(basename($f), $r['dudosas']));
            }
            foreach ($r['dudosas'] as [$linea, $txt]) {
                $id = null;
                if ($reparar) {
                    $id       = $siguiente++;
                    $nuevas[] = ['id' => $id, 'tabla' => $tabla, 'fichero' => basename($f), 'linea' => $linea,
                                 'texto' => $txt, 'fecha' => date('Y-m-d H:i:s')];
                }
                $anotar($f, ($linea > 0 ? "línea $linea: " : '') . $txt,
                    $apartar ? self::APARTADA : 'a mano, o REPAIR TABLES la aparta', $id);
            }
            $juntar($r['filas']);
        }
        foreach ($st->estado($tabla)['indexes'] as $nombre => $revs) {
            foreach (array_keys((array)$revs) as $i) {
                $f = $st->ficheroIndice($tabla, (string)$nombre, $i + 1);
                if (is_file($f) && !\is_array(json_decode((string)file_get_contents($f), true))) {
                    $anotar($f, 'índice ilegible', 'se rehace con los datos');
                    $rotos[] = $f;
                }
            }
        }
        if (!$reparar || ($rotos === [] && !$editada)) {
            return $out;
        }
        // La tabla entera, por el camino normal de escritura, una parte a la vez
        $reescribir = static function () use ($st, $tabla, $definiciones, $n, $cuantas, $juntas, $filas): void {
            $todas = static function () use ($st, $tabla, $n, $juntas, $filas): \Generator {
                if ($juntas) {
                    yield from $filas;
                    return;
                }
                $i = 0;
                for ($p = 1; $p <= $n; $p++) {
                    $texto = (string)file_get_contents($st->ficheroDatos($tabla, $p));
                    foreach (self::filasValidas($texto) ?? Reparador::parte($texto)['filas'] as $fila) {
                        yield $i++ => $fila;
                    }
                }
            };
            StorageEscritura::abrirEscritura($st, $tabla, 'ESCRITURA');
            $partesAntes = $st->partes($tabla);
            StorageEscritura::escribirTabla($st, $tabla, $st->leerMeta($tabla), $definiciones,
                static fn(): \Generator => StorageEscritura::enPartes($todas(), $st->filasPorParte),
                $cuantas === 0 ? [[]] : StorageEscritura::enPartes($todas(), $st->filasPorParte), $cuantas, null, null, [], $partesAntes);
        };
        if ($rotos === []) {
            // Editada a mano y válida: solo hay que reescribirla, para que sus
            // índices, desfases y revisiones vuelvan a cuadrar con lo editado
            $reescribir();
            return $out;
        }
        $copias = $st->dir . '/.reparados';
        if (!is_dir($copias) && !@mkdir($copias, 0775, true) && !is_dir($copias)) {
            throw JsonSqlDbError::io('No se puede crear la carpeta .reparados para guardar los originales');
        }
        $sello = date('Ymd-His');
        foreach (array_unique($rotos) as $f) {
            if (!self::copiarSeguro($st, $f, $copias . '/' . basename($f) . '.' . $sello)) {
                throw JsonSqlDbError::io('No se puede guardar el original de ' . basename($f) . ' antes de repararlo');
            }
        }
        $reescribir();
        if ($nuevas !== []) {
            self::guardarApartadas($st, array_merge($pendientes, $nuevas), $siguiente);
        }
        return $out;
    }

    /** Las filas de una parte que es JSON válido, huecos incluidos; null si no lo es (2.9) */
    private static function filasValidas(string $texto): ?array
    {
        $json = json_decode($texto, true);
        return \is_array($json) && \is_array($json['rows'] ?? null) ? array_values($json['rows']) : null;
    }

    private static function ficheroApartadas(Storage $st): string
    {
        return $st->dir . '/.reparados/apartadas.json';
    }

    /**
     * Las filas que REPAIR TABLES apartó y siguen pendientes (2.9: id, tabla,
     * fichero, línea, texto y fecha), y el número de la siguiente. Los números no se repiten
     * nunca, ni con la lista vacía: quien se quedó con el de una fila ya
     * resuelta no puede olvidar otra con él.
     *
     * @return array{0: list<array>, 1: int}
     */
    private static function leerApartadas(Storage $st): array
    {
        $v = json_decode((string)@file_get_contents(self::ficheroApartadas($st)), true);
        $olvidadas = $st->lote['olvidar'] ?? [];          // en la transacción abierta
        return [array_values(array_filter((array)($v['filas'] ?? []), static fn($p): bool => !isset($olvidadas[$p['id'] ?? null]))),
                max(1, (int)($v['siguiente'] ?? 1))];
    }

    /** @param list<array> $lista */
    private static function guardarApartadas(Storage $st, array $lista, int $siguiente): void
    {
        StorageEscritura::volcarFichero(self::ficheroApartadas($st),
            json_encode(['siguiente' => $siguiente, 'filas' => array_values($lista)], self::JSON_META) . "\n");
    }

    /**
     * Deja de tener pendiente la fila apartada $id (REPAIR TABLES FORGET): ya
     * se ha vuelto a meter, corregida, o se descarta. Sigue en la copia del
     * original. Devuelve la fila olvidada.
     */
    public static function olvidarApartada(Storage $st, int $id): array
    {
        [$lista, $siguiente] = self::leerApartadas($st);
        foreach ($lista as $i => $p) {
            if ($p['id'] === $id) {
                if ($st->lote !== null) {
                    // En una transacción, al confirmarla (2.9: se olvidaba ya,
                    // y si se deshacía, la fila no volvía pero dejaba de estar pendiente)
                    $st->lote['olvidar'][$id] = true;
                    return $p;
                }
                unset($lista[$i]);
                self::guardarApartadas($st, $lista, $siguiente);
                return $p;
            }
        }
        throw JsonSqlDbError::schema("No hay ninguna fila apartada con el número $id");
    }

    /** @param list<array{0: int, 1: string}> $dudosas */
    protected static function avisoDudosas(string $fichero, array $dudosas): string
    {
        $lineas = [];
        foreach (\array_slice($dudosas, 0, 5) as [$n, $texto]) {
            $lineas[] = ($n > 0 ? "línea $n: " : '') . $texto;
        }
        return "Datos ilegibles en $fichero: " . (\count($dudosas) === 1 ? 'una fila no' : \count($dudosas) . ' filas no')
            . ' se puede' . (\count($dudosas) === 1 ? '' : 'n') . ' arreglar sola' . (\count($dudosas) === 1 ? '' : 's') . ' ('
            . implode('; ', $lineas) . (\count($dudosas) > 5 ? '; …' : '') . '). Corrígelo a mano, o con REPAIR TABLES, que la aparta y la guarda en .reparados/';
    }

    /**
     * Un índice de antes de la 2.6: un solo fichero cuya revisión tiene que
     * ser la anotada (o la de la tabla, si el fichero de revisión es anterior
     * a la 2.5).
     *
     * @return array<string, int|list<int>>|null
     */
    protected static function indiceLegado(Storage $st, string $tabla, array $def, int $rev): ?array
    {
        $nombre = $def['name'];
        if (isset($st->indicesMemo[$tabla][$nombre][0])) {
            return $st->indicesMemo[$tabla][$nombre][0];
        }
        $fichero = $st->ficheroIndice($tabla, $nombre);
        if (!is_file($fichero)) {
            return null;
        }
        Memoria::comprobarFichero($fichero);
        $idx = json_decode((string)file_get_contents($fichero), true);
        if (!\is_array($idx) || !\is_array($idx['keys'] ?? null) || (int)($idx['rev'] ?? -1) !== $rev
            || isset($idx['part']) || ($idx['columns'] ?? null) !== $def['columns']) {
            return null;
        }
        return $st->indicesMemo[$tabla][$nombre][0] = $idx['keys'];
    }

    /**
     * Revisión en el _revs.json de las versiones anteriores a la 2.0, para que
     * una base antigua no reutilice revisiones ya usadas por la caché.
     */
    protected static function revLegada(Storage $st, string $tabla): int
    {
        if ($st->revsLegadas === null) {
            $fichero = $st->dir . '/_revs.json';
            $json    = is_file($fichero) ? json_decode((string)@file_get_contents($fichero), true) : [];
            $st->revsLegadas = \is_array($json) ? $json : [];
        }
        return (int)($st->revsLegadas[$tabla] ?? 0);
    }

    /**
     * Un iterable que se puede recorrer varias veces sin juntar nada: cada
     * recorrido llama otra vez a $fn (2.9). Para mirar las filas de una tabla
     * grande más de una vez, como hacen las comprobaciones de un ALTER TABLE.
     *
     * @param \Closure(): \Iterator $fn
     */
    public static function releer(\Closure $fn): \IteratorAggregate
    {
        return new class ($fn) implements \IteratorAggregate {
            public function __construct(private \Closure $fn)
            {
            }

            public function getIterator(): \Iterator
            {
                return ($this->fn)();
            }
        };
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    /**
     * Reescribe todas las filas de una tabla línea a línea, por bloques de
     * texto: $porLinea recibe el JSON de cada fila y devuelve el nuevo (o null
     * si no sabe, y entonces no se escribe nada). Las posiciones no se mueven,
     * así que los índices que siguen valiendo se quedan como están. Es lo que
     * usan ALTER TABLE … ADD, DROP y RENAME COLUMN: antes decodificaban la
     * tabla entera, cambiaban cada fila y la volvían a codificar, y rehacían
     * todos los índices. False si alguna parte no tiene la forma esperada:
     * quien llama lo hace entonces como siempre.
     *
     * @param callable(string):?string $porLinea
     */
    public static function reescribirLineas(Storage $st, string $tabla, callable $porLinea, array $meta, array $definiciones): bool
    {
        // Antes de abrir la escritura: si no se puede, quien llama la abre
        // para hacerlo como siempre, y una tabla solo se abre una vez
        $estado      = $st->estado($tabla);
        $filasAntes  = isset($estado['rows']) ? (int)$estado['rows'] : 0;
        $partesAntes = $st->partes($tabla);
        $chunk       = $st->filasPorParte;
        if ($filasAntes === 0 || ($estado['chunk'] ?? null) !== $chunk || $partesAntes !== (int)ceil($filasAntes / $chunk)) {
            return false;
        }
        // Cada parte se hace cuando se va a escribir, y se suelta (2.9: todas
        // juntas en memoria). Si una no tiene la forma esperada, se deja todo
        // y quien llama lo hace como siempre
        $lineas = static function (int $i) use ($st, $tabla, $porLinea, $chunk, $filasAntes): array {
            $lineas = StorageCompactacion::lineasDeParte($st, $tabla, $i + 1, min($chunk, $filasAntes - $i * $chunk));
            foreach ($lineas ?? throw new \UnexpectedValueException() as $k => $linea) {
                if ($linea !== 'null') {                 // un hueco se queda como está
                    $lineas[$k] = $porLinea($linea) ?? throw new \UnexpectedValueException();
                }
            }
            Memoria::comprobar('la reescritura de la tabla');
            return $lineas;
        };
        $partes = (static function () use ($lineas, $tabla, $partesAntes): \Generator {
            for ($i = 0; $i < $partesAntes; $i++) {
                yield $i => StorageCompactacion::textoDeParte($tabla, $lineas($i));
            }
        })();
        // Si un índice no vale tal cual (uno nuevo, o sobre una columna que
        // cambia de nombre), se rehace de las filas nuevas
        $todas = static function () use ($lineas, $partesAntes, $chunk): \Generator {
            for ($i = 0; $i < $partesAntes; $i++) {
                $filas = [];
                foreach ($lineas($i) as $k => $linea) {
                    if ($linea !== 'null') {
                        $filas[$i * $chunk + $k] = json_decode($linea, true);
                    }
                }
                yield $i => $filas;
            }
        };
        $marca = self::marca($st);
        StorageEscritura::abrirEscritura($st, $tabla, 'ESCRITURA');
        $propia = $st->txPropia;
        try {
            StorageEscritura::escribirTabla($st, $tabla, $meta, $definiciones, $todas, $partes, $filasAntes, $filasAntes, [], [], $partesAntes);
        } catch (\UnexpectedValueException) {
            // Lo escrito de esta tabla se tira; con la escritura abierta por
            // ella, la escritura entera
            $propia ? StorageEscritura::txAbandonar($st) : self::volverA($st, $marca);
            return false;
        }
        self::$cuentaBloques++;
        return true;
    }

    /**
     * VACUUM (2.9): rehace, sin tocar las partes, los índices de una tabla con
     * algún trozo sin filtro, como los escribían la 2.8 y las anteriores; con
     * él, una búsqueda por un texto no lee los trozos en que no puede estar.
     * Y (2.10) los de una tabla con algún índice sobre una columna de texto
     * o decimal con las claves de antes (ver Storage::clavesAlDia()), que no se usan
     * hasta rehacerlos; lo hace también la primera consulta que los
     * encuentra, al acabar (ver DatabaseAvanzada::reparar()). De cada trozo
     * se lee solo el principio: el filtro va antes que las claves. Con
     * $todos (REPAIR INDEXES), sin mirar nada. True si los rehízo.
     *
     * @param list<array{name: string, columns: list<string>, auto: bool}> $definiciones
     */
    public static function rehacerIndicesViejos(Storage $st, string $tabla, array $definiciones, bool $todos = false): bool
    {
        if (!$st->indices) {
            return false;                          // sin índices no hay nada que rehacer
        }
        $revs  = (array)($st->estado($tabla)['indexes'] ?? []);
        $viejo = $todos;
        foreach ($todos ? [] : $definiciones as $def) {
            $trozos = $revs[$def['name']] ?? null;
            $viejo  = !\is_array($trozos) || !$st->clavesAlDia($tabla, $def);
            for ($p = 1; !$viejo && $p <= \count($trozos); $p++) {
                $principio = (string)@file_get_contents($st->ficheroIndice($tabla, $def['name'], $p), false, null, 0, 4096);
                $viejo     = strpos($principio, '"filtro":"') === false;
            }
            if ($viejo) {
                break;
            }
        }
        if (!$viejo) {
            return false;
        }
        // Con otro tamaño de parte, la tabla entera (ver StorageEntera::guardarTabla())
        if (($st->estado($tabla)['chunk'] ?? null) !== $st->filasPorParte) {
            self::compactar($st, $tabla, $definiciones);
            return true;
        }
        StorageEscritura::abrirEscritura($st, $tabla, 'ESCRITURA');
        StorageEscritura::escribirTabla($st, $tabla, null, $definiciones,
            static fn(): \Generator => StorageEscritura::enPartes($st->filas($tabla), $st->filasPorParte),
            [], (int)($st->estado($tabla)['rows'] ?? StorageLectura::contarFilas($st, $tabla)), null, null, [], $st->partes($tabla));
        return true;
    }

    /**
     * CHECK INDEXES (2.10): los problemas de los índices de una tabla, cada
     * uno contra sus filas, trozo a trozo y parte a parte (solo una parte en
     * memoria): que se puedan usar (si no, una consulta recorre la tabla); que
     * cada trozo tenga las claves de las filas de su parte, ni una de más ni
     * de menos, en sus posiciones; que su filtro deje ver todas sus claves de
     * texto; y que el rango que el estado guarda de cada trozo cubra sus
     * números. Las dos últimas harían que una búsqueda no lo abriera y se
     * perdiera filas. Vacío si todo cuadra o si los índices están apagados.
     *
     * @param list<array{name: string, columns: list<string>}> $definiciones
     * @return list<array{tabla: string, indice: string, problema: string, arreglo: string}>
     */
    public static function revisarIndices(Storage $st, string $tabla, array $definiciones): array
    {
        if (!$st->indices) {
            return [];
        }
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $out     = [];
        // Uno que no se usa solo hace más lentas las búsquedas; uno que se usa
        // y no cuadra puede hacer que den filas de menos
        $anotar  = static function (array $def, string $problema, bool $seUsa = true) use (&$out, $tabla): void {
            $out[] = ['tabla' => $tabla, 'indice' => (string)$def['name'], 'problema' => $problema,
                      'arreglo' => $seUsa ? 'REPAIR INDEXES lo rehace; hasta entonces, buscar por él puede dar filas de menos'
                          : 'REPAIR INDEXES lo rehace, y también la siguiente escritura de la tabla; hasta entonces, se recorre la tabla'];
        };
        $planes = [];
        foreach ($definiciones as $def) {
            if (!$st->clavesAlDia($tabla, $def)) {
                $anotar($def, 'sus claves son de antes de la 2.10 (un texto que era un número iba a la clave de ese número, y un decimal entero de 9·10^18 a 2^63, a una de decimal): no se usa', false);
            } elseif (($plan = $st->planIndice($tabla, $def)) === null) {
                $anotar($def, 'le faltan trozos, o no son de la revisión de la tabla: no se usa', false);
            } else {
                $planes[$def['name']] = $plan;
            }
        }
        $chunk = max(1, (int)($st->estado($tabla)['chunk'] ?? $st->filasPorParte));
        $todas = $st->partes($tabla);
        for ($p = 1; $planes !== [] && $p <= $todas; $p++) {
            $filas = [];
            foreach ($st->parte($tabla, $p, $st->ficheroDatos($tabla, $p)) as $i => $fila) {
                if ($fila !== null) {
                    $filas[($p - 1) * $chunk + $i] = $fila;
                }
            }
            foreach ($definiciones as $def) {
                $plan = $planes[$def['name']] ?? null;
                if ($plan === null || $plan['legado'] !== null) {
                    continue;                      // uno de antes de la 2.6, de un fichero: VACUUM lo rehace
                }
                $esperadas = StorageEscritura::construir($filas, $def['columns'], $def['plegar'] ?? []);
                $keys      = $st->trozoIndice($tabla, $def, $p);
                if ($keys === null) {
                    $anotar($def, "el trozo $p no se puede leer, o es de otra revisión: no se usa", false);
                    continue;
                }
                $deMas = $deMenos = 0;
                foreach ($keys as $clave => $donde) {
                    $donde = (array)$donde;
                    $bien  = array_map('intval', (array)($esperadas[(string)$clave] ?? []));
                    $deMas += \count(array_diff(array_map('intval', $donde), $bien));
                }
                foreach ($esperadas as $clave => $donde) {
                    $deMenos += \count(array_diff(array_map('intval', (array)$donde), array_map('intval', (array)($keys[(string)$clave] ?? []))));
                }
                if ($deMas + $deMenos > 0) {
                    $anotar($def, "el trozo $p tiene $deMas clave(s) que sus filas no tienen y le faltan $deMenos");
                }
                $filtro = Indexes::filtro($st->indicesMemo[$tabla][$def['name']]['resumen'][$p] ?? []);
                $ocultas = 0;
                foreach ($filtro === null ? [] : array_keys($keys) as $clave) {
                    $ocultas += Indexes::puedeEstar($filtro, (string)$clave) ? 0 : 1;
                }
                if ($ocultas > 0) {
                    $anotar($def, "el filtro del trozo $p no deja ver $ocultas de sus claves");
                }
                $rango = $plan['rangos'][$p - 1] ?? null;
                $suyo  = IndexesEscritura::rango($keys);
                if ($rango !== null && $suyo !== [] && ($rango === [] || $suyo === null || $suyo[0] < $rango[0] || $suyo[1] > $rango[1])) {
                    $anotar($def, "el rango del trozo $p en el estado no cubre sus claves");
                }
            }
        }
        return $out;
    }

    /**
     * Crea los ficheros de una tabla nueva.
     *
     * @param list<array<string,mixed>> $filas las iniciales (CREATE TABLE … AS SELECT)
     */
    public static function crearTabla(Storage $st, string $tabla, array $meta, array $definiciones = [], array $filas = []): void
    {
        self::validarTabla($tabla);
        StorageEscritura::exigirEscritura($st);
        if ($st->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' ya existe");
        }
        StorageEntera::guardarTabla($st, $tabla, $filas, $meta, $definiciones);
    }

    /**
     * Cuenta en _database.json, en «esquema», un cambio en las tablas de la
     * base (2.10): una que se crea, se borra o cambia de nombre. Reescrito con
     * el resto de la escritura, lo ve quien guarda la lista de tablas (ver
     * StorageEscritura::tablasEsquema()). Una vez por escritura. Aquí desde
     * la 2.10, que solo lo usa la estructura: en StorageEscritura lo
     * compilaba cada escritura
     */
    public static function anotarEsquema(Storage $st): void
    {
        $f = $st->dir . '/_database.json';
        if (\in_array($f, $st->txRenombrar, true) || !is_file($f)) {
            return;
        }
        $info = json_decode((string)@file_get_contents($f), true);
        $info = (\is_array($info) ? $info : ['database' => basename($st->dir), 'engine' => 'jsonSQLDB', 'version' => 1])
            + ['esquema' => 0];
        $info['esquema'] = (int)$info['esquema'] + 1;
        StorageEscritura::escribirAtomico($st, $f, json_encode($info, self::JSON_META) . "\n");
        // La lista de tablas guardada ya no vale: fuera, como la caché de una tabla que se borra
        StorageEscritura::$tablasProceso = [];
        $st->apcu ? apcu_delete($st->prefijo . '.base:t:0') : @unlink($st->ficheroCache($st->prefijo . '.base:t:0'));
    }

    /** Borra estructura, datos, índices y caché de una tabla. */
    public static function borrarTabla(Storage $st, string $tabla): void
    {
        self::validarTabla($tabla);
        StorageEscritura::exigirEscritura($st);
        $propia = $st->txAmbito === null;
        if ($propia) {
            StorageEscritura::txIniciar($st, 'DROP TABLE', isset($st->locksTabla[$tabla]) ? $tabla : null);
        }
        $st->txTablas[$tabla] = true;
        self::anotarEsquema($st);

        $indices = StorageEscritura::indicesEnDisco($st, $tabla);
        $partes  = $st->partes($tabla);
        StorageEscritura::limpiarCache($st, $tabla, $st->estado($tabla), ['rev' => -1, 'parts' => [], 'indexes' => []], array_keys($indices));
        @unlink($st->ficheroEstado($tabla));      // el de OPcache (2.9): sin la tabla no lo tira nadie

        StorageEscritura::borrarFichero($st, $st->ficheroMeta($tabla));
        for ($parte = 1; $parte <= $partes; $parte++) {
            StorageEscritura::borrarFichero($st, $st->ficheroDatos($tabla, $parte));
        }
        foreach ($indices as $ficheros) {
            foreach ($ficheros as $fichero) {
                StorageEscritura::borrarFichero($st, $fichero);
            }
        }
        StorageEscritura::borrarFichero($st, $st->ficheroRev($tabla));
        unset($st->estados[$tabla], $st->indicesMemo[$tabla]);

        if ($propia) {
            StorageEscritura::txConfirmar($st);
        }
    }

    /** Renombra una tabla (ficheros, revisión e índices). */
    public static function renombrarTabla(Storage $st, string $desde, string $hasta, array $definiciones = []): void
    {
        self::validarTabla($desde);
        self::validarTabla($hasta);
        StorageEscritura::exigirEscritura($st);
        if (!$st->existe($desde)) {
            throw JsonSqlDbError::schema("La tabla '$desde' no existe");
        }
        if ($st->existe($hasta)) {
            throw JsonSqlDbError::schema("La tabla '$hasta' ya existe");
        }
        $propia = $st->txAmbito === null;
        if ($propia) {
            StorageEscritura::txIniciar($st, 'RENAME TABLE');
        }
        $meta = $st->leerMeta($desde);
        $meta['table'] = $hasta;

        // Las filas pasan parte a parte (2.9: la tabla entera en memoria)
        self::reescribir($st, $hasta, static fn(array $fila): array => $fila, $meta, $definiciones, $desde);
        self::borrarTabla($st, $desde);
        if ($propia) {
            StorageEscritura::txConfirmar($st);
        }
    }

    /**
     * Reescribe la tabla entera sin huecos, parte a parte y sin juntarla en
     * memoria (2.9): VACUUM, la compactación de un DELETE y el cambio de
     * tamaño de parte. Antes se leía entera, y con un millón de filas
     * necesitaba unos 700 MB.
     */
    public static function compactar(Storage $st, string $tabla, array $definiciones, ?array $meta = null): void
    {
        StorageEscritura::abrirEscritura($st, $tabla, 'ESCRITURA');
        $quedan = fn(): \Generator => $st->filas($tabla);
        if (StorageEscritura::exacta($st, $tabla)) {
            StorageCompactacion::compactarPorBloques($st, $tabla, [], [], [], (int)$st->estado($tabla)['rows'], $st->partes($tabla), $definiciones, $quedan, $meta);
            return;
        }
        StorageEntera::reescribirEntera($st, $tabla, $quedan, StorageLectura::contarFilas($st, $tabla), $st->partes($tabla), $definiciones, $meta);
    }

    /** Los huecos de filas borradas que tiene una tabla (2.9; ver modificarFilas()) */
    public static function huecos(Storage $st, string $tabla): int
    {
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        return array_sum((array)($st->estado($tabla)['huecos'] ?? []));
    }

    /**
     * Reescribe la tabla entera con cada fila pasada por $porFila, parte a
     * parte y sin juntarla en memoria (2.9): el ALTER TABLE que cambia las
     * filas. Las posiciones quedan sin huecos, como en compactar().
     *
     * Con $desde, las filas salen de esa otra tabla (RENAME TABLE).
     *
     * @param callable(array): array $porFila
     */
    public static function reescribir(Storage $st, string $tabla, callable $porFila, array $meta, array $definiciones,
                                      ?string $desde = null): void
    {
        $desde ??= $tabla;
        StorageEscritura::abrirEscritura($st, $tabla, 'ESCRITURA');
        StorageEntera::reescribirEntera($st, $tabla, static function () use ($st, $desde, $porFila): \Generator {
            foreach ($st->filas($desde) as $pos => $fila) {
                yield $pos => $porFila($fila);
            }
        }, StorageLectura::contarFilas($st, $desde), $st->partes($tabla), $definiciones, $meta);
    }

    /** Lo apuntado hasta ahora en la escritura abierta, para volver a ello (ver volverA()). */
    public static function marca(Storage $st): array
    {
        return [$st->txRenombrar, $st->txRegenerables, $st->txBorrar, $st->txCache, $st->txTablas, $st->txHuellas, $st->txPartes, $st->txEnSitio];
    }

    /** Deshace lo apuntado desde marca() en una escritura que sigue abierta: borra los temporales nuevos. */
    private static function volverA(Storage $st, array $marca): void
    {
        foreach (array_keys(array_diff_key($st->txRenombrar, $marca[0])) as $tmp) {
            @unlink((string)$tmp);
        }
        [$st->txRenombrar, $st->txRegenerables, $st->txBorrar, $st->txCache, $st->txTablas, $st->txHuellas, $st->txPartes, $st->txEnSitio] = $marca;
    }

    /** Carpeta del journal de un ámbito, como lo dejaban las versiones 2.0 a 2.6. */
    private static function dirJournal(Storage $st, ?string $tabla): string
    {
        return $st->dirTx . '/' . ($tabla ?? '_base');    // ninguna tabla puede llamarse _base
    }

    /**
     * Error si una clave del trozo ya salió en otro o se repite en él, con la
     * fila que la repite: la primera así en el orden de la tabla, como si se
     * recorriera fila a fila.
     *
     * @param array<string, int|list<int>> $keys
     * @param array<int, array>             $filas  posición => fila
     * @param array<string, true>           $vistas las claves de los trozos anteriores
     */
    public static function exigirUnica(string $tabla, array $def, array $keys, array $filas, array &$vistas): void
    {
        $repite = null;
        foreach ($keys as $k => $pos) {
            $p = Indexes::posiciones($pos);
            $r = isset($vistas[$k]) ? $p[0] : ($p[1] ?? null);
            if ($r !== null && ($repite === null || $r < $repite)) {
                $repite = $r;
            }
            $vistas[$k] = true;
        }
        if ($repite !== null) {
            throw CatalogDdl::repetida($tabla, $filas[$repite], $def['columns']);
        }
    }
}
