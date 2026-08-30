<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Almacenamiento de una base de datos jsonSQLDB.
 *
 * Estructura en disco:
 *   <raiz>/<base>/_database.json      metadatos de la base
 *   <raiz>/<base>/_revs.json          contador de revisión por tabla (invalida caché)
 *   <raiz>/<base>/_views.json         vistas: nombre => SELECT guardado
 *   <raiz>/<base>/<tabla>.meta.json   estructura de la tabla
 *   <raiz>/<base>/<tabla>.json        datos (una fila por línea, legible)
 *   <raiz>/<base>/<tabla>.part2.json  siguientes partes (JSONSQLDB_FILAS_POR_PARTE)
 *   <raiz>/<base>/<tabla>.idx.<n>.json  índices de búsqueda (ver Indexes)
 *   <raiz>/<base>/.cache/             caché serializada (regenerable, borrable)
 *   <raiz>/<base>/.tx/_base/          journal de una operación de varias tablas
 *   <raiz>/<base>/.tx/<tabla>/        journal de una escritura de una tabla
 *   <raiz>/<base>/.lock               fichero de bloqueo de la base
 *   <raiz>/<base>/.<tabla>.lock       fichero de bloqueo de una tabla
 *
 * Concurrencia: dos niveles de bloqueo con flock, siempre pedidos en este orden
 * —primero la base, después la tabla—, que es lo que hace imposible un
 * interbloqueo. Ver bloquear() para el detalle.
 *   - lectura                          => SH en la base + SH en cada tabla leída
 *   - escritura de UNA tabla aislada    => SH en la base + EX en la tabla
 *   - cascadas, triggers ajenos, DDL    => EX en la base
 */
final class Storage
{
    private const JSON_FILA = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    private const JSON_META = self::JSON_FILA | JSON_PRETTY_PRINT;

    private const RE_BASE  = '/^[A-Za-z0-9_-]{1,64}$/';
    private const RE_TABLA = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    private string $dir;
    private string $dirCache;
    private string $dirTx;

    /**
     * Bloqueos de tabla abiertos, por nombre de tabla. Uno exclusivo cuando la
     * escritura afecta solo a esa tabla; uno compartido por cada tabla que lee
     * una consulta.
     *
     * @var array<string, resource>
     */
    private array $locksTabla = [];
    private string $base;

    /** @var resource|null */
    private $lock = null;
    private int  $lockNivel = 0;
    private bool $lockExclusivo = false;

    /** @var array<string,int> revisión de cada tabla, leída dentro del bloqueo */
    private array  $revs = [];
    /** @var array<string,int>|null _revs.json de versiones anteriores a la 2.0 */
    private ?array $revsLegadas = null;
    private bool   $cache;
    private bool   $apcu;
    private string $prefijo;
    private int    $filasPorParte;
    private bool   $indices;

    public function __construct(string $raiz, string $base)
    {
        if (!preg_match(self::RE_BASE, $base)) {
            throw JsonSqlDbError::config("Nombre de base de datos no válido: '$base'");
        }
        $dir = rtrim(str_replace('\\', '/', $raiz), '/') . '/' . $base;
        if (!is_dir($dir)) {
            throw JsonSqlDbError::config("La base de datos '$base' no existe");
        }
        $this->dir      = $dir;
        $this->base     = $base;
        $this->dirCache = $dir . '/.cache';
        $this->dirTx    = $dir . '/.tx';
        $this->cache    = Config::cacheActiva();
        $this->apcu     = function_exists('apcu_fetch') && ini_get('apc.enabled') !== '0';
        $this->prefijo  = 'jsq:' . substr(md5($dir), 0, 12) . ':';
        $this->filasPorParte = Config::filasPorParte();
        $this->indices       = Config::indices();
    }

    public function indicesActivos(): bool { return $this->indices; }

    // ------------------------------------------------------------------
    // Bases de datos
    // ------------------------------------------------------------------

    /** Lista las bases de datos existentes bajo la raíz de datos. */
    public static function bases(string $raiz): array
    {
        $raiz = rtrim(str_replace('\\', '/', $raiz), '/');
        if (!is_dir($raiz)) {
            return [];
        }
        $out = [];
        foreach ((array)scandir($raiz) as $e) {
            if ($e !== '.' && $e !== '..' && preg_match(self::RE_BASE, $e)
                && is_file("$raiz/$e/_database.json")) {
                $out[] = $e;
            }
        }
        sort($out);
        return $out;
    }

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
        self::borrarRecursivo($dir);

        // Si algo quedó sin borrar, decirlo: una base a medias es peor que un
        // error, porque parece que existe y no se puede usar
        clearstatcache();
        if (is_dir($dir)) {
            $quedan = array_diff((array)scandir($dir), ['.', '..']);
            throw JsonSqlDbError::io(
                "La base '$base' se ha borrado solo en parte: no se pudieron eliminar "
                . count($quedan) . ' fichero(s). Comprueba los permisos y bórrala a mano.'
            );
        }
    }

    private static function borrarRecursivo(string $dir): void
    {
        foreach ((array)scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $ruta = "$dir/$e";
            is_dir($ruta) ? self::borrarRecursivo($ruta) : @unlink($ruta);
        }
        @rmdir($dir);
    }

    public function nombre(): string { return $this->base; }
    public function dir(): string    { return $this->dir; }

    // ------------------------------------------------------------------
    // Bloqueo
    // ------------------------------------------------------------------

    /**
     * Adquiere el bloqueo de la base. Reentrante: las llamadas anidadas
     * incrementan el nivel y deben ser compatibles con el modo ya adquirido.
     */
    /**
     * Bloqueo de la base, y opcionalmente de una sola tabla.
     *
     * Hay dos niveles, y siempre se piden en este orden, nunca al revés. Ese
     * orden fijo es lo que hace imposible un interbloqueo:
     *
     *   1. `.lock` de la base
     *   2. `.<tabla>.lock` de la tabla, si la operación afecta solo a una
     *
     * | Operación                                    | Base | Tabla    |
     * |----------------------------------------------|------|----------|
     * | SELECT y demás lecturas                      | SH   | SH (*)   |
     * | Escritura en UNA tabla sin claves ni triggers | SH   | EX       |
     * | Cascadas, triggers a otras tablas, DDL        | EX   | —        |
     *
     * (*) Las lecturas piden el compartido de cada tabla que tocan, y lo piden
     * sobre la marcha, la primera vez que leen de ella. Es necesario desde que
     * una tabla puede ocupar varios ficheros: la escritura los reemplaza uno a
     * uno, y sin ese bloqueo una lectura simultánea podía coger la primera parte
     * ya nueva y la segunda todavía vieja. Entre lecturas, SH y SH no se
     * estorban; solo se espera si hay una escritura en curso de esa misma tabla.
     *
     * Con el bloqueo compartido de la base, dos escrituras en tablas distintas
     * pueden ir a la vez y no bloquean las lecturas de las demás tablas. En
     * cuanto la operación toca más de una tabla se pide el exclusivo de la base,
     * que espera a que terminen todas las escrituras pendientes de todas ellas.
     *
     * No hay interbloqueo posible: el orden base -> tabla es fijo, un escritor
     * pide como mucho una tabla y nunca más, y los bloqueos compartidos de las
     * lecturas no se estorban entre sí.
     */
    public function bloquear(bool $exclusivo, ?string $tabla = null): void
    {
        if ($this->lockNivel > 0) {
            if ($exclusivo && !$this->lockExclusivo) {
                throw JsonSqlDbError::lock('No se puede escribir dentro de un bloqueo de lectura');
            }
            $this->lockNivel++;
            return;
        }

        // Escritura acotada a una tabla: compartido en la base, exclusivo en ella
        $exclusivoBase = $exclusivo && $tabla === null;

        $this->lock          = $this->abrirLock($this->dir . '/.lock', $exclusivoBase, "la base '{$this->base}'");
        $this->lockNivel     = 1;
        $this->lockExclusivo = $exclusivo;
        $this->revs          = [];     // releer revisiones dentro del bloqueo
        $this->revsLegadas   = null;

        // La recuperación va antes de coger ningún bloqueo de tabla: así puede
        // pedirlos sin riesgo de esperar a alguien que a su vez la espere.
        $this->recuperar($exclusivoBase);

        if ($exclusivoBase) {
            // Con el exclusivo de la base no hay ninguna escritura viva en
            // ninguna tabla: todo temporal que quede es de un proceso muerto
            $this->barrerTemporales();
        }

        if ($exclusivo && $tabla !== null) {
            self::validarTabla($tabla);
            $this->locksTabla[$tabla] = $this->abrirLock(
                $this->dir . '/.' . $tabla . '.lock', true, "la tabla '$tabla'"
            );
        }
    }

    /**
     * Coge el compartido de una tabla que se va a leer, si no se tiene ya.
     *
     * En escritura no se pide nada: o se tiene el exclusivo de la base, que ya
     * cubre todas las tablas, o se tiene el exclusivo de esta y pedir además el
     * compartido sobre otro descriptor bloquearía al proceso consigo mismo.
     */
    private function bloquearLectura(string $tabla): void
    {
        if ($this->lockNivel === 0 || $this->lockExclusivo || isset($this->locksTabla[$tabla])) {
            return;
        }
        $this->locksTabla[$tabla] = $this->abrirLock(
            $this->dir . '/.' . $tabla . '.lock', false, "la tabla '$tabla'"
        );
    }

    /** @return resource */
    private function abrirLock(string $fichero, bool $exclusivo, string $queEs)
    {
        $fh = @fopen($fichero, 'c');
        if ($fh === false) {
            throw JsonSqlDbError::io("No se puede abrir el fichero de bloqueo de $queEs");
        }
        if (!flock($fh, $exclusivo ? LOCK_EX : LOCK_SH)) {
            fclose($fh);
            throw JsonSqlDbError::lock("No se puede bloquear $queEs");
        }
        return $fh;
    }

    // ------------------------------------------------------------------
    // Journal
    //
    // Casi ninguna escritura toca un solo fichero. Un ALTER TABLE reescribe
    // estructura y datos; un INSERT en una tabla repartida en partes reescribe
    // varias; cualquier tabla con índices reescribe además los suyos. Cada
    // escritura suelta es atómica (temporal + rename), pero el conjunto no: si
    // el proceso muere entre dos de ellas, la tabla queda a medias.
    //
    // Antes de empezar se copia lo que se va a tocar en .tx/<ámbito>/ junto con
    // un manifiesto. Si todo va bien, esa carpeta se borra. Si el proceso muere,
    // se queda ahí, y su sola presencia es la señal de que algo no terminó: la
    // siguiente vez que se abre la base se deshace y todo vuelve a como estaba.
    //
    // El ámbito es el que decide qué bloqueo hace falta para deshacerlo:
    //   .tx/_base/     operación de varias tablas o de estructura -> EX de base
    //   .tx/<tabla>/   escritura de una sola tabla                -> EX de tabla
    //
    // Están todos colgando de .tx/ para que comprobar si hay alguno sea un solo
    // stat, que es lo que se hace en cada petición. Sin nada pendiente —el caso
    // normal— no cuesta nada más.
    // ------------------------------------------------------------------

    /** Ámbito del journal abierto por este proceso, o null si no hay ninguno. */
    private ?string $txAmbito = null;

    private function dirJournal(?string $tabla): string
    {
        // '_base' empieza por guión bajo y ninguna tabla puede llamarse así
        return $this->dirTx . '/' . ($tabla ?? '_base');
    }

    /**
     * ¿Quedó alguna operación a medias? Comprobarlo cuesta un stat y se hace
     * una vez por petición, al coger el bloqueo.
     */
    private function recuperar(bool $yaExclusivo): void
    {
        if (!is_dir($this->dirTx)) {
            return;                                   // el caso normal, y es gratis
        }

        $this->migrarJournalPlano();

        foreach ((array)glob($this->dirTx . '/*', GLOB_ONLYDIR) as $dir) {
            $ambito = basename((string)$dir);
            if ($ambito === '_base') {
                $this->recuperarBase($yaExclusivo);
            } elseif (preg_match(self::RE_TABLA, $ambito)) {
                $this->recuperarTabla($ambito);
            }
        }
        @rmdir($this->dirTx);                         // falla sola si aún queda alguno
        clearstatcache(true, $this->dirTx);
    }

    /**
     * Recoge un journal de antes de la 2.0, que no tenía ámbito.
     *
     * Hasta la 2.0 las copias iban sueltas dentro de `.tx/`, sin carpeta de
     * ámbito, porque solo existía el journal de base. La recuperación de ahora
     * busca subcarpetas, así que un journal antiguo pendiente le pasaba
     * desapercibido: al actualizar el código sin haber abierto antes la base, la
     * operación a medias no se deshacía nunca y los datos se quedaban rotos.
     *
     * Se reconoce por tener el manifiesto suelto en la raíz de `.tx/`. Se mueve
     * a `.tx/_base/`, que es donde iría hoy, y a partir de ahí lo deshace el
     * mismo código que todo lo demás. Los manifiestos de entonces listaban los
     * ficheros sin su tamaño, y la comprobación de tamaños ya contempla ese
     * formato.
     */
    private function migrarJournalPlano(): void
    {
        if (!is_file($this->dirTx . '/manifiesto.json')) {
            return;
        }
        $destino = $this->dirJournal(null);
        if (!@mkdir($destino, 0775, true) && !is_dir($destino)) {
            return;                                   // se reintentará al abrir otra vez
        }
        // El manifiesto, el último: hasta que llegue, la carpeta nueva está
        // incompleta y sin él no se restaura nada
        foreach ((array)glob($this->dirTx . '/*') as $f) {
            $nombre = basename((string)$f);
            if ($nombre !== 'manifiesto.json' && is_file((string)$f)) {
                @rename((string)$f, $destino . '/' . $nombre);
            }
        }
        @rename($this->dirTx . '/manifiesto.json', $destino . '/manifiesto.json');
        clearstatcache(true, $destino);
    }

    /** Deshace el journal de base. Exige el exclusivo de la base. */
    private function recuperarBase(bool $yaExclusivo): void
    {
        // Si estamos leyendo, se sube el bloqueo un momento y se vuelve a bajar.
        // La conversión suelta antes el compartido, así que dos lectores que la
        // intenten a la vez no se quedan esperándose: pasa uno y luego el otro.
        if (!$yaExclusivo && !@flock($this->lock, LOCK_EX)) {
            return;
        }
        clearstatcache(true, $this->dirJournal(null));
        if (is_dir($this->dirJournal(null))) {        // por si otro se adelantó
            $this->deshacer(null);
        }
        if (!$yaExclusivo) {
            @flock($this->lock, LOCK_SH);
        }
    }

    /**
     * Deshace el journal de una tabla. Exige el exclusivo de esa tabla, y lo
     * pide sin esperar: si no lo consigue es que hay una escritura viva, el
     * journal está en uso y no hay nada que deshacer.
     */
    private function recuperarTabla(string $tabla): void
    {
        if (isset($this->locksTabla[$tabla])) {
            return;                                   // es el nuestro, está en curso
        }
        $fh = @fopen($this->dir . '/.' . $tabla . '.lock', 'c');
        if ($fh === false) {
            return;
        }
        try {
            if (!@flock($fh, LOCK_EX | LOCK_NB)) {
                return;                               // escritura en curso: no es huérfano
            }
            clearstatcache(true, $this->dirJournal($tabla));
            if (is_dir($this->dirJournal($tabla))) {
                $this->deshacer($tabla);
            }
            @flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
    }

    /** ¿Hay ya un journal abierto por este proceso? Evita anidar uno dentro de otro. */
    public function txAbierta(): bool
    {
        return $this->txAmbito !== null;
    }

    /**
     * Abre el journal: copia los ficheros de las tablas indicadas.
     * Solo con bloqueo exclusivo.
     *
     * $ambito es la tabla cuyo bloqueo exclusivo se tiene, o null si se tiene el
     * de la base entera. Es lo que sabrá la recuperación para pedir el bloqueo
     * que corresponde.
     *
     * @param string[] $tablas
     */
    public function txIniciar(string $operacion, array $tablas, ?string $ambito = null): void
    {
        $this->exigirEscritura();
        if ($ambito !== null && !isset($this->locksTabla[$ambito])) {
            throw JsonSqlDbError::lock("Journal de '$ambito' sin su bloqueo exclusivo");
        }

        $dir = $this->dirJournal($ambito);
        $this->borrarDirJournal($ambito);             // por si acaso quedó uno

        // Se reintenta porque hay una carrera muy fina: mkdir recursivo crea
        // primero .tx y luego .tx/<ámbito>, y entre las dos cosas otro proceso
        // puede haber barrido .tx por vacío al terminar SU journal. Entonces el
        // segundo mkdir falla, y sin reintento la escritura se perdía entera.
        for ($intento = 0; ; $intento++) {
            if (@mkdir($dir, 0775, true) || is_dir($dir)) {
                break;
            }
            clearstatcache(true, $dir);
            if ($intento >= 3) {
                throw JsonSqlDbError::io('No se puede crear la carpeta del journal');
            }
        }
        $this->txAmbito = $ambito ?? '_base';

        // Se anota el tamaño de cada copia. Al restaurar se comprueba que
        // siguen midiendo lo mismo: si el disco devolviera una copia truncada,
        // vale más negarse a seguir que volcarla encima de los datos buenos.
        $copiados = [];
        foreach ($this->ficherosDe($tablas, $ambito === null) as $f) {
            $destino = $dir . '/' . basename($f);
            if (!$this->copiarSeguro($f, $destino)) {
                $this->deshacer($ambito);
                throw JsonSqlDbError::io('No se puede copiar ' . basename($f) . ' al journal');
            }
            clearstatcache(true, $destino);
            $copiados[basename($f)] = (int)filesize($destino);
        }

        // El manifiesto, el último y de una sola pieza. Es lo que convierte un
        // montón de copias sueltas en un journal que se puede creer.
        $this->escribirAtomico($dir . '/manifiesto.json', json_encode([
            'estado'    => 'ACTIVA',
            'operacion' => $operacion,
            'ambito'    => $ambito,
            'tablas'    => array_values($tablas),
            'ficheros'  => $copiados,
            'ts'        => date('Y-m-d H:i:s'),
        ], self::JSON_META) . "\n");
    }

    /** Cierra el journal: la operación terminó bien y la copia sobra. */
    public function txConfirmar(): void
    {
        if ($this->txAmbito === null) {
            return;
        }
        $ambito = $this->txAmbito === '_base' ? null : $this->txAmbito;
        $dir    = $this->dirJournal($ambito);

        // Se marca COMMITTED antes de borrar: si el corte ocurre entre las dos
        // cosas, al recuperar se ve que ya había terminado y no se deshace nada.
        $this->escribirAtomico($dir . '/manifiesto.json', json_encode([
            'estado' => 'COMMITTED',
            'ts'     => date('Y-m-d H:i:s'),
        ], self::JSON_META) . "\n");

        $this->borrarDirJournal($ambito);
        $this->txAmbito = null;
    }

    /** Deshace lo que hubiera empezado y no terminado en un ámbito. */
    private function deshacer(?string $ambito): void
    {
        $dir = $this->dirJournal($ambito);
        if (!is_dir($dir)) {
            return;
        }
        $manifiesto = json_decode((string)@file_get_contents($dir . '/manifiesto.json'), true);

        // Sin manifiesto no hay nada que deshacer, y restaurar sería peligroso.
        //
        // El manifiesto se escribe DESPUÉS de copiar y de una sola pieza, así
        // que su ausencia significa que las copias no llegaron a terminar. Y
        // como txIniciar() copia antes de tocar ningún fichero de datos, si las
        // copias no terminaron es que no se modificó nada: los datos están
        // enteros y lo único que sobra es la carpeta.
        //
        // Restaurar en ese caso era corromper la tabla: una copia interrumpida
        // a medio fichero se volcaba encima del original, que estaba bien.
        if (!is_array($manifiesto) || ($manifiesto['estado'] ?? '') === '') {
            $this->borrarDirJournal($ambito);
            $this->txAmbito = null;
            return;
        }

        // COMMITTED = terminó bien y solo faltaba limpiar. No se toca nada.
        if (($manifiesto['estado'] ?? '') === 'COMMITTED') {
            $this->borrarDirJournal($ambito);
            $this->txAmbito = null;
            return;
        }

        $tablas = (array)($manifiesto['tablas'] ?? []);
        if ($tablas === [] && $ambito !== null) {
            $tablas = [$ambito];
        }

        // Antes de tocar nada: que las copias sean las que se anotaron. Un
        // tamaño distinto significa que el journal no es de fiar, y entonces lo
        // único seguro es pararse. Restaurar a ciegas destruiría datos que
        // quizá estaban intactos, y borrarlo perdería la única copia que queda.
        $esperados = (array)($manifiesto['ficheros'] ?? []);
        foreach ($esperados as $nombre => $tam) {
            if (!is_string($nombre) || !is_int($tam)) {
                continue;                             // manifiesto de una versión anterior
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

        foreach ($this->ficherosDe($tablas, $ambito === null) as $f) {
            @unlink($f);                              // fuera lo que dejó a medias
        }
        foreach ((array)glob($dir . '/*') as $copia) {
            if (basename((string)$copia) === 'manifiesto.json') {
                continue;
            }
            // Con fsync también al devolverlas: si la luz se va a mitad de la
            // recuperación, lo restaurado tiene que estar en el disco. El
            // journal no se borra hasta el final, así que si no llega a
            // terminar, la próxima vez se repite entera.
            $this->copiarSeguro((string)$copia, $this->dir . '/' . basename((string)$copia));
        }
        foreach ($tablas as $t) {
            if (is_string($t)) {
                $this->limpiarCache($t);              // la caché apuntaba a lo deshecho
                unset($this->revs[$t]);
            }
        }
        $this->borrarDirJournal($ambito);
        $this->txAmbito = null;
    }

    /**
     * Ficheros en disco de unas tablas: datos, partes, estructura, revisión e
     * índices. Con $conBase se añaden los de la base, que solo puede tocar una
     * operación que tenga su bloqueo exclusivo.
     *
     * @param string[] $tablas
     * @return string[]
     */
    private function ficherosDe(array $tablas, bool $conBase): array
    {
        $out = [];
        foreach ($tablas as $t) {
            if (!is_string($t) || $t === '') {
                continue;
            }
            foreach (['.json', '.part*.json', '.meta.json', '.rev.json', '.idx.*.json'] as $patron) {
                foreach ((array)glob($this->dir . '/' . $t . $patron) as $f) {
                    $out[] = (string)$f;
                }
            }
        }
        if ($conBase) {
            foreach (['_views.json', '_database.json', '_revs.json'] as $f) {
                if (is_file($this->dir . '/' . $f)) {
                    $out[] = $this->dir . '/' . $f;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Copia un fichero forzándolo a disco.
     *
     * `copy()` no vale para esto. Deja el contenido en la caché del sistema
     * operativo, y eso basta para sobrevivir a que muera el proceso —la caché
     * es del sistema, no suya— pero no a que se vaya la luz. El journal se
     * apoya entero en que sus copias estén de verdad en el disco antes de que
     * se dé por bueno el manifiesto: si no, un corte de corriente dejaba un
     * manifiesto válido señalando copias vacías o a medias, y la recuperación
     * las volcaba encima de unos datos que estaban bien.
     *
     * Se copia por trozos, no de una vez, para no tener el fichero entero en
     * memoria: una tabla grande no cabría.
     */
    private function copiarSeguro(string $origen, string $destino): bool
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
            if (@stream_copy_to_stream($in, $out) === false) {
                return false;
            }
            if (!@fflush($out)) {
                return false;
            }
            if (function_exists('fsync') && !@fsync($out)) {
                return false;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        return true;
    }

    private function borrarDirJournal(?string $ambito): void
    {
        $dir = $this->dirJournal($ambito);

        // El manifiesto, primero. Es lo que da por bueno el resto de la carpeta:
        // si se muriera aquí en medio con el manifiesto todavía puesto y la
        // mitad de las copias ya borradas, la siguiente recuperación restauraría
        // un juego incompleto y se perdería lo que faltase.
        @unlink($dir . '/manifiesto.json');
        foreach ((array)glob($dir . '/*') as $f) {
            @unlink((string)$f);
        }
        @rmdir($dir);
        @rmdir($this->dirTx);                         // falla sola si queda otro ámbito
        clearstatcache(true, $dir);
    }

    /** Libera los bloqueos (solo cuando se cierra el último nivel). */
    public function desbloquear(): void
    {
        if ($this->lockNivel === 0) {
            return;
        }
        if (--$this->lockNivel > 0) {
            return;
        }
        // Se sueltan en orden inverso al que se pidieron
        foreach ($this->locksTabla as $fh) {
            if (is_resource($fh)) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->locksTabla    = [];
        $this->lock          = null;
        $this->lockExclusivo = false;
        $this->revs          = [];
        $this->revsLegadas   = null;
    }

    /** ¿Se tiene el bloqueo exclusivo de esta tabla en concreto? */
    public function tieneExclusivoDe(string $tabla): bool
    {
        return $this->lockExclusivo && isset($this->locksTabla[$tabla]);
    }

    public function enEscritura(): bool
    {
        return $this->lockNivel > 0 && $this->lockExclusivo;
    }

    private function exigirEscritura(): void
    {
        if (!$this->enEscritura()) {
            throw JsonSqlDbError::lock('Operación de escritura sin bloqueo exclusivo');
        }
    }

    // ------------------------------------------------------------------
    // Tablas
    // ------------------------------------------------------------------

    /** Nombres de las tablas existentes. */
    public function tablas(): array
    {
        $out = [];
        foreach ((array)glob($this->dir . '/*.meta.json') as $f) {
            $n = basename($f, '.meta.json');
            if (preg_match(self::RE_TABLA, $n)) {
                $out[] = $n;
            }
        }
        sort($out);
        return $out;
    }

    public function existe(string $tabla): bool
    {
        self::validarTabla($tabla);
        return is_file($this->ficheroMeta($tabla));
    }

    public static function validarTabla(string $tabla): void
    {
        if (!preg_match(self::RE_TABLA, $tabla)) {
            throw JsonSqlDbError::schema("Nombre de tabla no válido: '$tabla'");
        }
    }

    /** Estructura de una tabla. */
    public function leerMeta(string $tabla): array
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);
        $clave = $this->claveCache($tabla, 'm');
        $meta  = $this->cacheLeer($clave);
        if ($meta !== null) {
            return $meta;
        }
        $fichero = $this->ficheroMeta($tabla);
        if (!is_file($fichero)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        $meta = json_decode((string)file_get_contents($fichero), true);
        if (!is_array($meta)) {
            throw JsonSqlDbError::io("Estructura ilegible en '$tabla.meta.json'");
        }
        $this->cacheGuardar($clave, $meta);
        return $meta;
    }

    /** Guarda la estructura de una tabla e invalida su caché. */
    public function guardarMeta(string $tabla, array $meta, array $definiciones = []): void
    {
        $this->guardarTabla($tabla, null, $meta, $definiciones);
    }

    // ------------------------------------------------------------------
    // Vistas
    // ------------------------------------------------------------------

    /**
     * Vistas de la base: nombre => ['sql' => ..., 'created_at' => ...].
     * Una vista es solo un SELECT guardado; no tiene datos propios.
     *
     * @return array<string,array>
     */
    public function leerVistas(): array
    {
        $f = $this->dir . '/_views.json';
        if (!is_file($f)) {
            return [];
        }
        $v = json_decode((string)@file_get_contents($f), true);
        return is_array($v) ? $v : [];
    }

    /** @param array<string,array> $vistas */
    public function guardarVistas(array $vistas): void
    {
        $this->exigirEscritura();
        $this->escribirAtomico($this->dir . '/_views.json', json_encode($vistas, self::JSON_META) . "\n");
    }

    /**
     * Todas las filas de una tabla (concatenando las partes).
     *
     * $sinCache fuerza leer del disco. La caché se invalida por el contador de
     * revisión, que solo sube cuando escribe el motor: si alguien edita el JSON
     * a mano, la caché sigue devolviendo lo viejo. La comprobación de integridad
     * necesita ver lo que hay de verdad en el fichero.
     */
    public function leerFilas(string $tabla, bool $sinCache = false, ?int $tope = null): array
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);

        $clave = $this->claveCache($tabla, 'd');
        if (!$sinCache) {
            $filas = $this->cacheLeer($clave);
            if ($filas !== null) {
                return $tope !== null && count($filas) > $tope ? array_slice($filas, 0, $tope) : $filas;
            }
        }

        $filas    = [];
        $completa = true;
        for ($parte = 1; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                break;
            }
            // Si hace falta la tabla entera, se decodifica el fichero de una
            // vez, que es bastante más rápido que fila a fila; leer por líneas
            // solo compensa cuando se van a descartar casi todas.
            foreach ($this->filasDeParte($fichero, $tope !== null) as $fila) {
                if ($tope !== null && count($filas) >= $tope) {
                    $completa = false;
                    break 2;                          // ya no hacen falta más
                }
                Memoria::comprobar('la lectura de la tabla');
                $filas[] = $fila;
            }
        }

        // Media tabla en la caché sería peor que no tenerla: la siguiente
        // consulta la daría por completa
        if ($completa && $tope === null) {
            $this->cacheGuardar($clave, $filas);
        }
        return $filas;
    }

    /** Cuenta las filas de una tabla sin llegar a construirlas en memoria. */
    public function contarFilas(string $tabla): int
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);

        $filas = $this->cacheLeer($this->claveCache($tabla, 'd'));
        if ($filas !== null) {
            return count($filas);
        }
        $n = 0;
        for ($parte = 1; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                return $n;
            }
            foreach ($this->filasDeParte($fichero, true) as $ignorada) {
                $n++;
            }
        }
    }

    /**
     * Recorre las filas de un fichero de datos.
     *
     * Con $porLineas se lee una fila cada vez, aprovechando que el fichero se
     * escribe con una fila por línea. Así solo hay en memoria una fila más lo
     * que el llamante decida quedarse, en vez del texto completo y el array
     * completo a la vez: un SELECT con LIMIT sobre una tabla de 50.000 filas
     * pasó de 50 MB de pico a 3,6 MB.
     *
     * Sin él se decodifica el fichero de una vez, que para 50.000 filas es
     * alrededor de un 25 % más rápido porque son diez llamadas a json_decode en
     * lugar de cincuenta mil. Se usa cuando el llamante se va a quedar con todas
     * las filas igualmente y no hay memoria que ahorrar.
     *
     * Si el fichero no está en el formato de una fila por línea —editado a mano,
     * o compactado— se vuelve al json_decode de siempre, que entiende cualquier
     * JSON válido.
     *
     * @return \Generator<int, array>
     */
    private function filasDeParte(string $fichero, bool $porLineas = false): \Generator
    {
        // Si no cabe ni leído entero, mejor cortar antes de empezar
        Memoria::comprobarFichero($fichero);

        if (!$porLineas) {
            $json = json_decode((string)file_get_contents($fichero), true);
            if (!is_array($json) || !is_array($json['rows'] ?? null)) {
                throw JsonSqlDbError::io('Datos ilegibles en ' . basename($fichero));
            }
            foreach ($json['rows'] as $fila) {
                yield $fila;
            }
            return;
        }

        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            throw JsonSqlDbError::io('No se puede leer ' . basename($fichero));
        }
        try {
            $enFilas = false;
            $sueltas = 0;
            while (($linea = fgets($fh)) !== false) {
                $linea = trim($linea);
                if (!$enFilas) {
                    if (strncmp($linea, '"rows":', 7) !== 0) {
                        continue;                     // cabecera
                    }
                    if (substr($linea, -2) === '[]') {
                        return;                       // tabla vacía
                    }
                    $enFilas = true;
                    continue;
                }
                $linea = rtrim($linea, ',');
                if ($linea === '' || $linea[0] !== '{' || substr($linea, -1) !== '}') {
                    continue;                         // cierre del array o del objeto
                }
                $fila = json_decode($linea, true);
                if (!is_array($fila)) {
                    $enFilas = false;                 // no es el formato esperado
                    break;
                }
                $sueltas++;
                yield $fila;
            }

            if ($enFilas) {
                return;
            }
            if ($sueltas > 0) {
                throw JsonSqlDbError::io('Datos ilegibles en ' . basename($fichero));
            }
        } finally {
            fclose($fh);
        }

        // Formato no canónico: se lee entero
        $json = json_decode((string)file_get_contents($fichero), true);
        if (!is_array($json) || !is_array($json['rows'] ?? null)) {
            throw JsonSqlDbError::io('Datos ilegibles en ' . basename($fichero));
        }
        foreach ($json['rows'] as $fila) {
            Memoria::comprobar('la lectura de la tabla');
            yield $fila;
        }
    }

    /**
     * Escribe de una vez todo lo que cambia de una tabla: datos, estructura e
     * índices, con una sola subida de revisión.
     *
     * Van juntos porque los índices llevan dentro la revisión a la que
     * corresponden, y una revisión nueva los deja a todos por reconstruir. Si se
     * guardaran los datos y la estructura por separado, cada INSERT en una tabla
     * con AUTOINCREMENT reconstruiría los índices dos veces.
     *
     * $filas o $meta a null significa «esto no cambia». $definiciones son los
     * índices que deben quedar escritos (ver Indexes); si la tabla no tiene
     * ninguno, no se lee nada de más.
     *
     * @param list<array>|null $filas
     * @param array|null       $meta
     * @param list<array{name: string, columns: list<string>, auto: bool}> $definiciones
     */
    public function guardarTabla(string $tabla, ?array $filas, ?array $meta, array $definiciones = []): void
    {
        self::validarTabla($tabla);
        $this->exigirEscritura();

        $definiciones = $this->indices ? $definiciones : [];
        $nombres      = [];
        foreach ($definiciones as $def) {
            $nombres[] = $def['name'];
        }

        // Un proceso muerto de golpe no ejecuta el finally de escribirAtomico() y
        // deja su temporal ahí. Aquí se tiene el exclusivo de la tabla, así que
        // ningún otro proceso puede estar escribiéndola: cualquier temporal suyo
        // que no sea el nuestro sobra. Así no se acumulan nunca.
        $this->barrerTemporales($tabla);

        // La revisión sube ANTES de escribir nada, y el orden importa. Si un
        // corte de corriente cae entre las dos cosas, queda una revisión nueva
        // con los datos viejos: nadie tiene nada cacheado bajo esa revisión, así
        // que la siguiente lectura va al fichero y ve lo correcto. Al revés
        // —datos nuevos y revisión vieja— la caché seguiría sirviendo lo de
        // antes, y eso no se detecta nunca.
        // Las que hay ahora y las que va a haber: al subir la revisión hay que
        // invalidar la caché de las dos, porque la tabla puede encoger
        $partesCache = max(
            $this->partes($tabla),
            $filas === null ? 0 : (int)max(1, ceil(count($filas) / $this->filasPorParte))
        );
        $rev = $this->subirRev($tabla, $nombres, $partesCache);

        if ($filas !== null) {
            $filas  = array_values($filas);
            $partes = $filas === [] ? [[]] : array_chunk($filas, $this->filasPorParte);

            foreach ($partes as $i => $bloque) {
                $this->escribirAtomico(
                    $this->ficheroDatos($tabla, $i + 1),
                    $this->codificarTabla($tabla, $bloque)
                );
            }
            // Eliminar partes sobrantes de una escritura anterior más grande
            for ($parte = count($partes) + 1; ; $parte++) {
                $fichero = $this->ficheroDatos($tabla, $parte);
                if (!is_file($fichero)) {
                    break;
                }
                @unlink($fichero);
            }
        }

        if ($meta !== null) {
            $meta['updated_at'] = date('Y-m-d H:i:s');
            $this->escribirAtomico($this->ficheroMeta($tabla), json_encode($meta, self::JSON_META) . "\n");
        }

        // Un índice se construye desde las filas. Si esta escritura no las
        // tocaba, se leen —salvo que no haya ningún índice, que es lo normal en
        // una tabla sin clave primaria y no hace falta leer nada.
        if ($definiciones !== [] || $this->indicesEnDisco($tabla) !== []) {
            $this->escribirIndices($tabla, $filas ?? $this->leerFilas($tabla), $definiciones, $rev);
        }

        if ($filas !== null) {
            $this->cacheGuardar($this->claveCache($tabla, 'd', $rev), $filas);
        }
        if ($meta !== null) {
            $this->cacheGuardar($this->claveCache($tabla, 'm', $rev), $meta);
        }
    }

    /** Reescribe todas las filas de una tabla. */
    public function guardarFilas(string $tabla, array $filas, array $definiciones = []): void
    {
        $this->guardarTabla($tabla, $filas, null, $definiciones);
    }

    // ------------------------------------------------------------------
    // Índices
    // ------------------------------------------------------------------

    /**
     * Borra los temporales huérfanos de una tabla, o los de toda la base.
     *
     * Solo se llama teniendo el bloqueo exclusivo que corresponde, que es lo que
     * garantiza que ningún temporal que se vea sea de una escritura viva.
     */
    private function barrerTemporales(?string $tabla = null): void
    {
        // Solo se borra aquello cuya escritura tenemos bloqueada, que es lo que
        // garantiza que ningún otro proceso pueda estar a medias de renombrarlo:
        //
        //   $tabla === null   se tiene el EXCLUSIVO DE LA BASE, que excluye a
        //                     todo el mundo: cualquier temporal que quede sobra.
        //   $tabla !== null   se tiene el exclusivo DE ESA TABLA, así que solo
        //                     se tocan los suyos. Los de otras tablas se dejan
        //                     en paz: puede haber otro proceso escribiéndolas
        //                     ahora mismo, que para eso el bloqueo es por tabla.
        //
        // No se mira el identificador del proceso. No hace falta: esto se llama
        // antes de escribir nada, así que no hay ningún temporal propio en vuelo
        // que proteger. Y era frágil, porque el identificador de un proceso
        // puede ser sufijo del de otro.
        $patron = $tabla === null ? '/*.tmp' : '/' . $tabla . '.*.tmp';
        foreach ((array)glob($this->dir . $patron) as $f) {
            @unlink((string)$f);
        }
    }

    /** Cuántos ficheros de datos tiene ahora mismo una tabla. */
    public function partes(string $tabla): int
    {
        self::validarTabla($tabla);
        for ($parte = 1; ; $parte++) {
            if (!is_file($this->ficheroDatos($tabla, $parte))) {
                return $parte - 1;
            }
        }
    }

    /** ¿Tiene la tabla algún índice escrito? */
    public function tieneIndices(string $tabla): bool
    {
        self::validarTabla($tabla);
        return $this->indicesEnDisco($tabla) !== [];
    }

    private function ficheroIndice(string $tabla, string $indice): string
    {
        return $this->dir . '/' . $tabla . '.idx.' . $indice . '.json';
    }

    /**
     * Escribe los índices de una tabla y borra los que ya no toca.
     *
     * @param list<array> $definiciones
     */
    private function escribirIndices(string $tabla, array $filas, array $definiciones, int $rev): void
    {
        $vigentes = [];
        foreach ($definiciones as $def) {
            $vigentes[$def['name']] = true;
            $this->escribirAtomico($this->ficheroIndice($tabla, $def['name']), json_encode([
                'index'   => $def['name'],
                'table'   => $tabla,
                'columns' => $def['columns'],
                'rev'     => $rev,
                'rows'    => count($filas),
                'chunk'   => $this->filasPorParte,
                'keys'    => Indexes::construir($filas, $def['columns']),
            ], self::JSON_FILA) . "\n");
        }

        foreach ($this->indicesEnDisco($tabla) as $nombre => $fichero) {
            if (!isset($vigentes[$nombre])) {
                @unlink($fichero);
            }
        }
    }

    /**
     * Índices que hay ahora mismo en disco: nombre => ruta.
     *
     * @return array<string, string>
     */
    private function indicesEnDisco(string $tabla): array
    {
        $out    = [];
        $inicio = strlen($tabla) + 5;                 // '<tabla>.idx.'
        foreach ((array)glob($this->dir . '/' . $tabla . '.idx.*.json') as $f) {
            $nombre = substr(basename((string)$f, '.json'), $inicio);
            if ($nombre !== '') {
                $out[$nombre] = (string)$f;
            }
        }
        return $out;
    }

    /**
     * Filas de una parte, con su propia entrada de caché.
     *
     * La caché de tabla completa no sirve aquí: el sentido de buscar por índice
     * es no leer la tabla entera. Sin una caché por parte, cada búsqueda puntual
     * volvía a decodificar las mil filas de su parte para quedarse con una.
     *
     * La clave lleva la revisión de la tabla, que sube en cada escritura, así
     * que la invalidación es automática igual que con las demás.
     *
     * @return list<array>
     */
    private function filasDeParteCacheadas(string $tabla, int $parte, string $fichero): array
    {
        $clave = $this->claveCache($tabla, 'p' . $parte);
        $filas = $this->cacheLeer($clave);
        if (is_array($filas)) {
            return $filas;
        }
        $filas = [];
        foreach ($this->filasDeParte($fichero, true) as $fila) {
            $filas[] = $fila;
        }
        $this->cacheGuardar($clave, $filas);
        return $filas;
    }

    /**
     * Lee un índice, o null si no sirve.
     *
     * La revisión guardada tiene que ser la de la tabla y las columnas las
     * esperadas. Si no coinciden, el fichero es de antes de un cambio o alguien
     * lo ha tocado a mano: se ignora y la consulta recorre la tabla, que es más
     * lento pero nunca da un resultado equivocado.
     *
     * @param array{name: string, columns: list<string>} $def
     */
    private function leerIndice(string $tabla, array $def): ?array
    {
        $clave = $this->claveCache($tabla, 'x' . $def['name']);
        $idx   = $this->cacheLeer($clave);

        if ($idx === null) {
            $fichero = $this->ficheroIndice($tabla, $def['name']);
            if (!is_file($fichero)) {
                return null;
            }
            Memoria::comprobarFichero($fichero);
            $idx = json_decode((string)file_get_contents($fichero), true);
            if (!is_array($idx) || !is_array($idx['keys'] ?? null)) {
                return null;
            }
            $this->cacheGuardar($clave, $idx);
        }

        if ((int)($idx['rev'] ?? -1) !== $this->rev($tabla)
            || ($idx['columns'] ?? null) !== $def['columns']) {
            return null;
        }
        return $idx;
    }

    /**
     * Filas de una tabla que casan con unas claves de índice, leyendo solo las
     * partes donde están. Devuelve null si el índice no sirve o no compensa, y
     * entonces hay que recorrer la tabla como siempre.
     *
     * Las filas salen en el orden en que están en la tabla, que es el que
     * espera un SELECT sin ORDER BY.
     *
     * @param array{name: string, columns: list<string>} $def
     * @param list<string> $claves
     */
    public function filasPorIndice(string $tabla, array $def, array $claves, bool $prefijo): ?array
    {
        self::validarTabla($tabla);
        if (!$this->indices) {
            return null;
        }
        $this->bloquearLectura($tabla);

        $idx = $this->leerIndice($tabla, $def);
        if ($idx === null) {
            return null;
        }

        $posiciones = [];
        if ($prefijo) {
            // Índice sobre (a, b) usado para buscar solo por a: las claves
            // llevan la longitud por delante, así que el prefijo es inequívoco.
            foreach ($idx['keys'] as $k => $lista) {
                foreach ($claves as $c) {
                    if (strncmp((string)$k, $c, strlen($c)) === 0) {
                        foreach ($lista as $p) { $posiciones[(int)$p] = true; }
                        break;
                    }
                }
            }
        } else {
            foreach ($claves as $c) {
                foreach ($idx['keys'][$c] ?? [] as $p) { $posiciones[(int)$p] = true; }
            }
        }

        $chunk = max(1, (int)($idx['chunk'] ?? $this->filasPorParte));
        $total = max(0, (int)($idx['rows'] ?? 0));
        $todas = $total === 0 ? 1 : (int)ceil($total / $chunk);

        $necesarias = [];
        foreach (array_keys($posiciones) as $p) {
            $necesarias[intdiv($p, $chunk) + 1] = true;
        }
        // Leer más de la mitad de las partes no ahorra bastante como para
        // renunciar a la caché de la tabla entera. Con una sola parte, nunca.
        //
        // Sin ninguna parte que leer no se entra aquí, y se sale abajo con la
        // lista vacía: una búsqueda que no encuentra nada no llega a abrir un
        // solo fichero.
        if ($necesarias !== [] && count($necesarias) * 2 > $todas) {
            return null;
        }

        $filas = [];
        foreach (array_keys($necesarias) as $parte) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                return null;                          // índice y datos no cuadran
            }
            // Solo se guardan las filas que pide el índice: de una parte puede
            // que haga falta una sola, y no tiene sentido construir el resto.
            $base = ($parte - 1) * $chunk;
            foreach ($this->filasDeParteCacheadas($tabla, $parte, $fichero) as $desfase => $fila) {
                if (isset($posiciones[$base + $desfase])) {
                    $filas[$base + $desfase] = $fila;
                    Memoria::comprobar('la lectura por índice');
                }
            }
        }

        ksort($filas);
        return array_values($filas);
    }

    /** Crea los ficheros de una tabla nueva. */
    public function crearTabla(string $tabla, array $meta, array $definiciones = []): void
    {
        self::validarTabla($tabla);
        $this->exigirEscritura();
        if ($this->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' ya existe");
        }
        $this->guardarTabla($tabla, [], $meta, $definiciones);
    }

    /** Borra estructura, datos, índices y caché de una tabla. */
    public function borrarTabla(string $tabla): void
    {
        self::validarTabla($tabla);
        $this->exigirEscritura();

        $indices = $this->indicesEnDisco($tabla);
        $this->limpiarCache($tabla, null, array_keys($indices));

        @unlink($this->ficheroMeta($tabla));
        for ($parte = 1; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                break;
            }
            @unlink($fichero);
        }
        foreach ($indices as $fichero) {
            @unlink($fichero);
        }
        @unlink($this->dir . '/' . $tabla . '.rev.json');
        unset($this->revs[$tabla]);
    }

    /** Renombra una tabla (ficheros, revisión e índices). */
    public function renombrarTabla(string $desde, string $hasta, array $definiciones = []): void
    {
        self::validarTabla($desde);
        self::validarTabla($hasta);
        $this->exigirEscritura();
        if (!$this->existe($desde)) {
            throw JsonSqlDbError::schema("La tabla '$desde' no existe");
        }
        if ($this->existe($hasta)) {
            throw JsonSqlDbError::schema("La tabla '$hasta' ya existe");
        }

        $meta  = $this->leerMeta($desde);
        $filas = $this->leerFilas($desde);
        $meta['table'] = $hasta;

        $this->borrarTabla($desde);
        $this->guardarTabla($hasta, $filas, $meta, $definiciones);
    }

    // ------------------------------------------------------------------
    // Ficheros
    // ------------------------------------------------------------------

    private function ficheroMeta(string $tabla): string
    {
        return $this->dir . '/' . $tabla . '.meta.json';
    }

    private function ficheroDatos(string $tabla, int $parte): string
    {
        return $this->dir . '/' . $tabla . ($parte > 1 ? '.part' . $parte : '') . '.json';
    }

    /** Fichero de datos legible: cabecera indentada y una fila por línea. */
    private function codificarTabla(string $tabla, array $filas): string
    {
        $out = "{\n  \"table\": " . json_encode($tabla, self::JSON_FILA) . ",\n  \"rows\": [";
        $sep = "\n    ";
        foreach ($filas as $fila) {
            $json = json_encode($fila, self::JSON_FILA);
            if ($json === false) {
                throw JsonSqlDbError::io("No se puede codificar una fila de '$tabla' a JSON");
            }
            $out .= $sep . $json;
            $sep = ",\n    ";
        }
        return $out . ($filas === [] ? "]\n}\n" : "\n  ]\n}\n");
    }

    /** Escritura atómica: fichero temporal + rename, sin dejar restos. */
    /**
     * Escribe un fichero de forma que un corte no lo deje a medias.
     *
     * Se escribe en un temporal, se fuerza a disco y se pone en su sitio con
     * rename(), que el sistema de ficheros hace de una pieza. Sin el volcado a
     * disco previo habría atomicidad de nombre pero no durabilidad: el sistema
     * operativo puede haber aceptado la escritura y tenerla todavía en su caché,
     * de modo que un corte de corriente dejaría el fichero nuevo vacío o a
     * medias aunque el rename ya hubiera ocurrido.
     *
     * fsync() existe desde PHP 8.1. En 8.0 se hace lo que se puede: vaciar el
     * buffer de PHP. Está documentado como diferencia entre versiones.
     *
     * En Windows rename() NO reemplaza un fichero existente, así que hay que
     * borrarlo antes. Eso abre una ventana —fichero viejo borrado, nuevo aún sin
     * renombrar— en la que un corte deja la tabla sin su fichero. Es una
     * limitación del sistema, no del motor, y por eso el journal copia los
     * ficheros antes de tocarlos: la recuperación los devuelve a su sitio.
     */
    private function escribirAtomico(string $fichero, string $contenido): void
    {
        $tmp = $fichero . '.' . getmypid() . '.tmp';
        try {
            $fh = @fopen($tmp, 'wb');
            if ($fh === false) {
                throw JsonSqlDbError::io('No se puede escribir ' . basename($fichero));
            }
            try {
                if (@fwrite($fh, $contenido) !== strlen($contenido)) {
                    throw JsonSqlDbError::io('Escritura incompleta de ' . basename($fichero));
                }
                @fflush($fh);
                if (function_exists('fsync')) {
                    @fsync($fh);                // los datos, en el disco de verdad
                }
            } finally {
                @fclose($fh);
            }

            if (!@rename($tmp, $fichero)) {
                @unlink($fichero);              // Windows: rename falla si el destino existe
                if (!@rename($tmp, $fichero)) {
                    throw JsonSqlDbError::io('No se puede reemplazar ' . basename($fichero));
                }
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // ------------------------------------------------------------------
    // Revisiones y caché
    // ------------------------------------------------------------------

    /**
     * Revisión de una tabla: sube en cada escritura suya e invalida su caché.
     *
     * Cada tabla guarda la suya en su propio fichero. En un único fichero
     * compartido no podía estar: dos escrituras en tablas distintas van a la vez
     * —ese es justo el sentido del bloqueo por tabla— y las dos lo reescribirían
     * entero, de modo que la última en terminar borraría la subida de la otra y
     * dejaría su caché sirviendo datos viejos.
     */
    private function rev(string $tabla): int
    {
        if (isset($this->revs[$tabla])) {
            return $this->revs[$tabla];
        }
        $fichero = $this->dir . '/' . $tabla . '.rev.json';
        $json    = is_file($fichero) ? json_decode((string)@file_get_contents($fichero), true) : null;
        $rev     = is_array($json) ? (int)($json['rev'] ?? 0) : $this->revLegada($tabla);

        return $this->revs[$tabla] = $rev;
    }

    /**
     * Revisión en el _revs.json de las versiones anteriores a la 2.0.
     *
     * Solo se mira cuando la tabla aún no tiene fichero propio, para que una
     * base creada con una versión anterior no reutilice revisiones ya usadas y
     * dé por buena una entrada de caché que no le corresponde. En la primera
     * escritura de cada tabla se crea el fichero nuevo y esto deja de leerse.
     */
    private function revLegada(string $tabla): int
    {
        if ($this->revsLegadas === null) {
            $fichero = $this->dir . '/_revs.json';
            $json    = is_file($fichero) ? json_decode((string)@file_get_contents($fichero), true) : [];
            $this->revsLegadas = is_array($json) ? $json : [];
        }
        return (int)($this->revsLegadas[$tabla] ?? 0);
    }

    /**
     * @param string[] $indices nombres de índice cuya caché también sobra
     * @param int      $partes  cuántas partes tiene o va a tener la tabla
     */
    private function subirRev(string $tabla, array $indices = [], int $partes = 0): int
    {
        $rev = $this->rev($tabla) + 1;
        $this->escribirAtomico(
            $this->dir . '/' . $tabla . '.rev.json',
            json_encode(['rev' => $rev], self::JSON_META) . "\n"
        );
        $this->limpiarCache($tabla, $rev, $indices, $partes);
        $this->revs[$tabla] = $rev;
        return $rev;
    }

    private function claveCache(string $tabla, string $tipo, ?int $rev = null): string
    {
        return $this->prefijo . $tabla . ':' . $tipo . ':' . ($rev ?? $this->rev($tabla));
    }

    private function cacheLeer(string $clave)
    {
        if (!$this->cache) {
            return null;
        }
        if ($this->apcu) {
            $ok  = false;
            $val = apcu_fetch($clave, $ok);
            return $ok ? $val : null;
        }
        $fichero = $this->ficheroCache($clave);
        if (!is_file($fichero)) {
            return null;
        }
        // La caché también se materializa entera de golpe
        Memoria::comprobarFichero($fichero);

        $val = @unserialize((string)file_get_contents($fichero), ['allowed_classes' => false]);
        return $val === false ? null : $val;
    }

    private function cacheGuardar(string $clave, $valor): void
    {
        if (!$this->cache) {
            return;
        }
        // Guardar en caché no es gratis: en disco hay que serializar el valor
        // entero, lo que durante un momento lo tiene dos veces en memoria, y en
        // APCu hay que copiarlo a la memoria compartida. Si ya se va justo, es
        // mejor quedarse sin caché que sin memoria; la consulta en curso no
        // depende de ella y la siguiente volverá a leer del fichero.
        if (Memoria::apretado()) {
            return;
        }
        // Y por encima de cierto tamaño tampoco compensa. Serializar una tabla
        // grande la tiene dos veces en memoria un instante, y justo esas son las
        // que menos falta hacen en caché: las búsquedas puntuales van por índice
        // y leen solo su parte.
        $tope = Config::cacheMaxFilas();
        if ($tope > 0 && is_array($valor) && count($valor) > $tope) {
            return;
        }
        if ($this->apcu) {
            apcu_store($clave, $valor);
            return;
        }
        if (!is_dir($this->dirCache) && !@mkdir($this->dirCache, 0775, true) && !is_dir($this->dirCache)) {
            return;   // sin caché en disco: el motor sigue funcionando
        }
        // Sin escritura atómica ni fsync, a diferencia de los datos. La caché es
        // regenerable y su clave lleva la revisión de la tabla, así que un
        // fichero a medias solo produce un unserialize() fallido, que cacheLeer()
        // ya trata como «no hay caché». Ahorrarse el fsync quita de cada
        // escritura una sincronización del tamaño de la tabla.
        @file_put_contents($this->ficheroCache($clave), serialize($valor));
    }

    /**
     * Elimina las entradas de caché de una tabla.
     *
     * En APCu no se pueden borrar por patrón, así que se borran las de la
     * revisión anterior y la actual, que son las únicas alcanzables. Las de
     * índices llevan su nombre en el tipo y hay que enumerarlas.
     *
     * @param string[] $indices
     */
    private function limpiarCache(string $tabla, ?int $rev = null, array $indices = [], int $partes = 0): void
    {
        if ($this->apcu) {
            $rev ??= $this->rev($tabla);
            $tipos = ['m', 'd'];
            foreach ($indices as $i) {
                $tipos[] = 'x' . $i;
            }
            // Y las de cada parte. Se barren solo las que hay, no un tope fijo:
            // hacerlo a ciegas eran quinientos y pico apcu_delete por escritura,
            // y aun así una tabla con más partes que el tope se quedaba con
            // entradas sin borrar.
            for ($parte = 1; $parte <= $partes; $parte++) {
                $tipos[] = 'p' . $parte;
            }
            foreach ($tipos as $tipo) {
                for ($r = max(0, $rev - 1); $r <= $rev; $r++) {
                    apcu_delete($this->prefijo . $tabla . ':' . $tipo . ':' . $r);
                }
            }
            return;
        }
        foreach ((array)glob($this->dirCache . '/' . md5($this->prefijo . $tabla) . '.*.cache') as $f) {
            @unlink($f);
        }
    }

    private function ficheroCache(string $clave): string
    {
        // md5(prefijo+tabla) agrupa las entradas de una misma tabla para poder borrarlas juntas
        $partes = explode(':', $clave);
        $rev    = array_pop($partes);
        $tipo   = array_pop($partes);
        $tabla  = array_pop($partes);
        return $this->dirCache . '/' . md5($this->prefijo . $tabla) . '.' . $tipo . $rev . '.cache';
    }
}
