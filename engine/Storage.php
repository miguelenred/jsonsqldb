<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Almacenamiento de una base de datos jsonSQLDB.
 *
 * Estructura en disco:
 *   <raiz>/<base>/_database.json      metadatos de la base
 *   <raiz>/<base>/<tabla>.rev.json    revisión de la tabla y estado de sus partes
 *   <raiz>/<base>/_revs.json          contador común de antes de la 2.0; solo se
 *                                     lee si una tabla no tiene todavía el suyo
 *   <raiz>/<base>/_views.json         vistas: nombre => SELECT guardado
 *   <raiz>/<base>/<tabla>.meta.json   estructura de la tabla
 *   <raiz>/<base>/<tabla>.json        datos (una fila por línea, legible)
 *   <raiz>/<base>/<tabla>.part2.json  siguientes partes (JSONSQLDB_FILAS_POR_PARTE)
 *   <raiz>/<base>/<tabla>.idx.<n>.json  índices de búsqueda (ver Indexes)
 *   <raiz>/<base>/.cache/             caché serializada por parte (regenerable)
 *   <raiz>/<base>/.tx/<ámbito>/       journal de una escritura en curso
 *   <raiz>/<base>/.lock               fichero de bloqueo de la base
 *   <raiz>/<base>/.<tabla>.lock       fichero de bloqueo de una tabla
 *
 * Concurrencia: dos niveles de bloqueo con flock, siempre pedidos en este orden
 * —primero la base, después la tabla—, que es lo que hace imposible un
 * interbloqueo. Ver bloquear().
 *
 * Durabilidad: cada fichero se escribe en un temporal que se fuerza a disco.
 * Cuando una operación toca más de uno, los temporales se ponen en su sitio
 * de golpe guiados por un journal de rehacer (ver txConfirmar()).
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
    private string $base;

    /** @var array<string, resource> bloqueos de tabla abiertos, por nombre */
    private array $locksTabla = [];
    /** @var resource|null */
    private $lock = null;
    private int  $lockNivel = 0;
    private bool $lockExclusivo = false;

    /** @var array<string,array> estado de cada tabla (rev.json), leído dentro del bloqueo */
    private array  $estados = [];
    /** @var array<string,array<string,array>> índices leídos dentro del bloqueo */
    private array  $indicesMemo = [];
    /** @var array<string,int>|null _revs.json de versiones anteriores a la 2.0 */
    private ?array $revsLegadas = null;
    private bool   $cache;
    private bool   $apcu;
    private string $prefijo;
    private int    $filasPorParte;
    private bool   $indices;

    /**
     * Escritura en curso: lo ya escrito en temporales, que se pondrá en su
     * sitio al confirmar.
     *
     *   txAmbito    tabla cuyo exclusivo se tiene, '_base' con el de la base,
     *               o null si no hay ninguna abierta
     *   txRenombrar temporal => fichero definitivo
     *   txBorrar    ficheros definitivos que desaparecen
     *   txTablas    tablas tocadas
     *   txCache     entradas de caché que se guardan al confirmar
     */
    private ?string $txAmbito    = null;
    private bool    $txPropia    = false;   // la abrió guardarTabla() y la confirma ella
    private string  $txOperacion = '';
    private array   $txRenombrar = [];
    private array   $txBorrar    = [];
    private array   $txTablas    = [];
    private array   $txCache     = [];

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
    public function nombre(): string { return $this->base; }
    public function dir(): string    { return $this->dir; }

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

    // ------------------------------------------------------------------
    // Bloqueo
    // ------------------------------------------------------------------

    /**
     * Bloqueo de la base, y opcionalmente de unas tablas concretas.
     *
     * Hay dos niveles, y siempre se piden en este orden, nunca al revés. Ese
     * orden fijo es lo que hace imposible un interbloqueo:
     *
     *   1. `.lock` de la base
     *   2. `.<tabla>.lock` de cada tabla, si la operación se acota a unas
     *
     * | Operación                                    | Base | Tabla    |
     * |----------------------------------------------|------|----------|
     * | SELECT y demás lecturas                      | SH   | SH (*)   |
     * | Escritura acotada a unas tablas              | SH   | EX       |
     * | Operaciones de estructura y las no acotables | EX   | —        |
     *
     * (*) Las lecturas piden el compartido de cada tabla que tocan, sobre la
     * marcha, la primera vez que leen de ella: así una lectura simultánea a una
     * escritura no coge una parte nueva y otra vieja.
     *
     * Reentrante: las llamadas anidadas incrementan el nivel y no pueden pedir
     * escritura dentro de un bloqueo de lectura.
     *
     * @param string|list<string>|null $tabla tabla(s) cuyo exclusivo se pide,
     *        EN EL ORDEN DADO: si todos los procesos siguen el mismo orden no
     *        puede haber un ciclo de esperas.
     */
    public function bloquear(bool $exclusivo, $tabla = null): void
    {
        $tablas = $tabla === null ? [] : (is_array($tabla) ? array_values($tabla) : [$tabla]);
        if ($this->lockNivel > 0) {
            if ($exclusivo && !$this->lockExclusivo) {
                throw JsonSqlDbError::lock('No se puede escribir dentro de un bloqueo de lectura');
            }
            $this->lockNivel++;
            return;
        }

        $exclusivoBase = $exclusivo && $tablas === [];

        $this->lock          = $this->abrirLock($this->dir . '/.lock', $exclusivoBase, "la base '{$this->base}'");
        $this->lockNivel     = 1;
        $this->lockExclusivo = $exclusivo;
        $this->estados       = [];     // releer revisiones dentro del bloqueo
        $this->indicesMemo   = [];
        $this->revsLegadas   = null;

        // La recuperación va antes de coger ningún bloqueo de tabla: así puede
        // pedirlos sin riesgo de esperar a alguien que a su vez la espere.
        $this->recuperar($exclusivoBase);

        if ($exclusivoBase) {
            // Con el exclusivo de la base no hay ninguna escritura viva en
            // ninguna tabla: todo temporal que quede es de un proceso muerto
            $this->barrerTemporales();
        } elseif ($exclusivo) {
            foreach ($tablas as $t) {
                self::validarTabla($t);
                $this->locksTabla[$t] ??= $this->abrirLock($this->dir . '/.' . $t . '.lock', true, "la tabla '$t'");
            }
        }
    }

    /**
     * Coge el compartido de una tabla que se va a leer, si no se tiene ya. En
     * escritura no se pide nada: o se tiene el exclusivo de la base, que cubre
     * todas, o el de esta tabla, y pedir además el compartido sobre otro
     * descriptor bloquearía al proceso consigo mismo.
     */
    private function bloquearLectura(string $tabla): void
    {
        if ($this->lockNivel === 0 || $this->lockExclusivo || isset($this->locksTabla[$tabla])) {
            return;
        }
        $this->locksTabla[$tabla] = $this->abrirLock($this->dir . '/.' . $tabla . '.lock', false, "la tabla '$tabla'");
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

    /** Libera los bloqueos (solo cuando se cierra el último nivel). */
    public function desbloquear(): void
    {
        if ($this->lockNivel === 0 || --$this->lockNivel > 0) {
            return;
        }
        if ($this->txAmbito !== null) {
            $this->txAbandonar();             // una escritura que no llegó a confirmarse
        }
        foreach ($this->locksTabla as $fh) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->locksTabla    = [];
        $this->lock          = null;
        $this->lockExclusivo = false;
        $this->estados       = [];
        $this->indicesMemo   = [];
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
    // Journal
    //
    // Casi ninguna escritura toca un solo fichero: una tabla repartida en
    // partes, sus índices, su estructura y su revisión. Cada fichero se escribe
    // entero en un temporal forzado a disco, y nada se pone en su sitio hasta
    // que TODOS están escritos. Entonces se anota en .tx/<ámbito>/manifiesto.json
    // qué temporal va a qué fichero y qué ficheros sobran, y se hace. Si el
    // proceso muere a mitad, la siguiente vez que se abre la base el manifiesto
    // se vuelve a aplicar: los temporales están en el disco, así que rehacer
    // siempre termina. Sin manifiesto, los temporales sobran y los datos están
    // intactos.
    //
    // El ámbito es el bloqueo que se tiene, y dice cuál hará falta para rehacer:
    //   .tx/_base/     operación con el exclusivo de la base
    //   .tx/<tabla>/   escritura con el exclusivo de esa tabla (y de las demás
    //                  que liste el manifiesto)
    //
    // Los journals de versiones anteriores guardaban copias para deshacer; se
    // reconocen y se deshacen igual (ver deshacer()).
    // ------------------------------------------------------------------

    private function dirJournal(?string $tabla): string
    {
        return $this->dirTx . '/' . ($tabla ?? '_base');    // ninguna tabla puede llamarse _base
    }

    /** ¿Quedó alguna operación a medias? Cuesta un stat, una vez por bloqueo. */
    private function recuperar(bool $yaExclusivo): void
    {
        if (!is_dir($this->dirTx)) {
            return;
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
     * Recoge un journal de antes de la 2.0, que dejaba las copias sueltas en la
     * raíz de `.tx/`. Se mueve a `.tx/_base/` y lo deshace el código de siempre.
     */
    private function migrarJournalPlano(): void
    {
        if (!is_file($this->dirTx . '/manifiesto.json')) {
            return;
        }
        $destino = $this->dirJournal(null);
        if (!@mkdir($destino, 0775, true) && !is_dir($destino)) {
            return;
        }
        // El manifiesto, el último: sin él la carpeta nueva no se restaura
        foreach ((array)glob($this->dirTx . '/*') as $f) {
            $nombre = basename((string)$f);
            if ($nombre !== 'manifiesto.json' && is_file((string)$f)) {
                @rename((string)$f, $destino . '/' . $nombre);
            }
        }
        @rename($this->dirTx . '/manifiesto.json', $destino . '/manifiesto.json');
        clearstatcache(true, $destino);
    }

    /** Aplica el journal de base. Exige el exclusivo de la base. */
    private function recuperarBase(bool $yaExclusivo): void
    {
        // Leyendo, se sube el bloqueo un momento y se vuelve a bajar. Convertir
        // suelta antes el compartido, así que dos lectores no se esperan.
        if (!$yaExclusivo && !@flock($this->lock, LOCK_EX)) {
            return;
        }
        clearstatcache(true, $this->dirJournal(null));
        if (is_dir($this->dirJournal(null))) {        // por si otro se adelantó
            $this->aplicarJournal(null);
        }
        if (!$yaExclusivo) {
            @flock($this->lock, LOCK_SH);
        }
    }

    /**
     * Aplica el journal de una tabla. Exige el exclusivo de todas las tablas
     * que lista, y lo pide sin esperar: si no lo consigue es que hay una
     * escritura viva y el journal está en uso.
     */
    private function recuperarTabla(string $ambito): void
    {
        $dir        = $this->dirJournal($ambito);
        $manifiesto = json_decode((string)@file_get_contents($dir . '/manifiesto.json'), true);
        $tablas     = is_array($manifiesto) ? (array)($manifiesto['tablas'] ?? []) : [];
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
                if (isset($this->locksTabla[$t])) {
                    return;                           // es el nuestro, está en curso
                }
                $fh = @fopen($this->dir . '/.' . $t . '.lock', 'c');
                if ($fh === false) {
                    return;
                }
                $fhs[] = $fh;
                if (!@flock($fh, LOCK_EX | LOCK_NB)) {
                    return;                           // hay una escritura viva: no es huérfano
                }
            }
            clearstatcache(true, $dir);
            if (is_dir($dir)) {
                $this->aplicarJournal($ambito);
            }
        } finally {
            foreach ($fhs as $fh) {
                @flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    /** ¿Hay una escritura abierta por este proceso? */
    public function txAbierta(): bool
    {
        return $this->txAmbito !== null;
    }

    /**
     * Abre una escritura de varios ficheros. Todo lo que se guarde hasta
     * txConfirmar() se queda en temporales y se pone en su sitio de golpe.
     *
     * $ambito es la tabla cuyo bloqueo exclusivo se tiene, o null si se tiene
     * el de la base entera.
     */
    public function txIniciar(string $operacion, ?string $ambito = null): void
    {
        $this->exigirEscritura();
        if ($this->txAmbito !== null) {
            throw JsonSqlDbError::lock('Ya hay una escritura abierta');
        }
        if ($ambito !== null && !isset($this->locksTabla[$ambito])) {
            throw JsonSqlDbError::lock("Escritura acotada a '$ambito' sin su bloqueo");
        }
        $this->txAmbito    = $ambito ?? '_base';
        $this->txOperacion = $operacion;
    }

    /**
     * Pone en su sitio todo lo escrito desde txIniciar().
     *
     * Con un solo fichero en juego el rename ya es atómico y no hace falta
     * journal. Con más, se escribe el manifiesto —de una pieza y forzado a
     * disco— antes de tocar nada: a partir de ahí la operación es irrevocable
     * y, si el proceso muere, se termina al abrir la base.
     */
    public function txConfirmar(): void
    {
        if ($this->txAmbito === null) {
            return;
        }
        $ambito = $this->txAmbito === '_base' ? null : $this->txAmbito;
        $tablas = array_keys($this->txTablas);
        if ($ambito !== null) {
            foreach ($tablas as $t) {
                if (!isset($this->locksTabla[$t])) {
                    throw JsonSqlDbError::lock("Escritura acotada a '$ambito' que toca '$t' sin su bloqueo");
                }
            }
        }

        $dir = null;
        if (count($this->txRenombrar) + count($this->txBorrar) > 1) {
            $dir = $this->dirJournal($ambito);
            // mkdir recursivo crea .tx y luego .tx/<ámbito>; entre las dos cosas
            // otro proceso puede haber barrido .tx por vacío al terminar el suyo
            for ($intento = 0; !@mkdir($dir, 0775, true) && !is_dir($dir); $intento++) {
                clearstatcache(true, $dir);
                if ($intento >= 3) {
                    throw JsonSqlDbError::io('No se puede crear la carpeta del journal');
                }
            }
            $this->fsyncDir($this->dirTx);
            $renombrar = [];
            foreach ($this->txRenombrar as $tmp => $f) {
                $renombrar[basename((string)$tmp)] = basename($f);
            }
            $this->volcarFichero($dir . '/manifiesto.json', json_encode([
                'tipo'      => 'redo',
                'operacion' => $this->txOperacion,
                'ambito'    => $ambito,
                'tablas'    => $tablas,
                'renombrar' => $renombrar,
                'borrar'    => array_map('basename', array_keys($this->txBorrar)),
                'ts'        => date('Y-m-d H:i:s'),
            ], self::JSON_META) . "\n");
        }

        $this->rehacer($this->txRenombrar, array_keys($this->txBorrar));

        if ($dir !== null) {
            $this->borrarDirJournal($ambito);
        }
        foreach ($this->txCache as [$clave, $valor]) {
            $this->cacheGuardar($clave, $valor);
        }
        $this->txAmbito = null;
        $this->txPropia = false;
        $this->txRenombrar = $this->txBorrar = $this->txTablas = $this->txCache = [];
    }

    /** Tira una escritura que no se confirmó: los temporales sobran, los datos siguen intactos. */
    private function txAbandonar(): void
    {
        foreach (array_keys($this->txRenombrar) as $tmp) {
            @unlink((string)$tmp);
        }
        foreach (array_keys($this->txTablas) as $t) {
            unset($this->estados[$t], $this->indicesMemo[$t]);   // lo subido en memoria no llegó al disco
        }
        $this->txAmbito = null;
        $this->txPropia = false;
        $this->txRenombrar = $this->txBorrar = $this->txTablas = $this->txCache = [];
    }

    /**
     * Pone cada temporal en su sitio y borra lo que sobra. Idempotente: se
     * puede repetir tras un corte hasta que termine.
     *
     * @param array<string,string> $renombrar temporal => definitivo (rutas completas)
     * @param list<string>         $borrar    rutas completas
     */
    private function rehacer(array $renombrar, array $borrar): void
    {
        foreach ($renombrar as $tmp => $fichero) {
            $tmp = (string)$tmp;
            if (!is_file($tmp)) {
                if (is_file($fichero)) {
                    continue;                     // ya se había renombrado
                }
                throw JsonSqlDbError::io('Falta el temporal de ' . basename($fichero) . ' al rehacer la escritura');
            }
            if (!@rename($tmp, $fichero)) {
                @unlink($fichero);                // Windows: rename falla si el destino existe
                if (!@rename($tmp, $fichero)) {
                    throw JsonSqlDbError::io('No se puede reemplazar ' . basename($fichero));
                }
            }
        }
        foreach ($borrar as $f) {
            @unlink($f);
        }
        // El contenido ya está en el disco; ahora los nombres
        $this->fsyncDir($this->dir);
    }

    /** Aplica o deshace el journal de un ámbito, según de qué versión sea. */
    private function aplicarJournal(?string $ambito): void
    {
        $dir        = $this->dirJournal($ambito);
        $manifiesto = json_decode((string)@file_get_contents($dir . '/manifiesto.json'), true);

        // Sin manifiesto no hay nada que hacer: se escribe DESPUÉS de los
        // temporales y de una pieza, así que si falta no se tocó ningún dato
        if (is_array($manifiesto) && ($manifiesto['tipo'] ?? '') === 'redo') {
            $renombrar = [];
            foreach ((array)($manifiesto['renombrar'] ?? []) as $tmp => $f) {
                if (is_string($tmp) && is_string($f) && strpos($tmp, '/') === false && strpos($f, '/') === false) {
                    $renombrar[$this->dir . '/' . $tmp] = $this->dir . '/' . $f;
                }
            }
            $borrar = [];
            foreach ((array)($manifiesto['borrar'] ?? []) as $f) {
                if (is_string($f) && strpos($f, '/') === false) {
                    $borrar[] = $this->dir . '/' . $f;
                }
            }
            $this->rehacer($renombrar, $borrar);
        } elseif (is_array($manifiesto) && ($manifiesto['estado'] ?? '') !== '') {
            $this->deshacer($ambito, $manifiesto);
        }
        foreach ((array)($manifiesto['tablas'] ?? []) as $t) {
            if (is_string($t)) {
                unset($this->estados[$t]);
            }
        }
        $this->borrarDirJournal($ambito);
    }

    /**
     * Deshace un journal de una versión anterior a la 2.5: copias de los
     * ficheros de antes de la escritura, que se vuelven a poner en su sitio.
     */
    private function deshacer(?string $ambito, array $manifiesto): void
    {
        if ($manifiesto['estado'] === 'COMMITTED') {
            return;                               // terminó bien y solo faltaba limpiar
        }
        $dir    = $this->dirJournal($ambito);
        $tablas = (array)($manifiesto['tablas'] ?? []);
        if ($tablas === [] && $ambito !== null) {
            $tablas = [$ambito];
        }
        // Que las copias midan lo anotado: si no, el journal no es de fiar y lo
        // único seguro es pararse. Los manifiestos anteriores a la 2.0 traían
        // una lista de nombres sin tamaño.
        foreach ((array)($manifiesto['ficheros'] ?? []) as $nombre => $tam) {
            if (!is_string($nombre) || !is_int($tam)) {
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
        foreach ($this->ficherosDe($tablas, $ambito === null) as $f) {
            @unlink($f);                              // fuera lo que dejó a medias
        }
        foreach ((array)glob($dir . '/*') as $ruta) {
            $nombre = basename((string)$ruta);
            if ($nombre !== 'manifiesto.json' && substr($nombre, -4) !== '.tmp') {
                $this->copiarSeguro((string)$ruta, $this->dir . '/' . $nombre);
            }
        }
        // La caché en disco puede tener entradas de la revisión deshecha
        foreach ($tablas as $t) {
            if (is_string($t)) {
                foreach ((array)glob($this->dirCache . '/' . md5($this->prefijo . $t) . '.*.cache') as $f) {
                    @unlink((string)$f);
                }
            }
        }
    }

    /**
     * Ficheros en disco de unas tablas: datos, partes, estructura, revisión e
     * índices; con $conBase, también los de la base.
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

    /** Copia un fichero por trozos forzándolo a disco (copy() lo deja en la caché del sistema). */
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
            return @stream_copy_to_stream($in, $out) !== false && @fflush($out)
                && (!function_exists('fsync') || @fsync($out));
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function borrarDirJournal(?string $ambito): void
    {
        $dir = $this->dirJournal($ambito);
        // El manifiesto, primero: es lo que da por válido el resto
        @unlink($dir . '/manifiesto.json');
        foreach ((array)glob($dir . '/*') as $f) {
            @unlink((string)$f);
        }
        @rmdir($dir);
        @rmdir($this->dirTx);                         // falla sola si queda otro ámbito
        // Que haya desaparecido de verdad: tras un corte no debe volver a verse
        $this->fsyncDir($this->dir);
        clearstatcache(true, $dir);
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
        $propia = $this->txAmbito === null;
        if ($propia) {
            $this->txIniciar('VISTAS');
        }
        $this->escribirAtomico($this->dir . '/_views.json', json_encode($vistas, self::JSON_META) . "\n");
        if ($propia) {
            $this->txConfirmar();
        }
    }

    // ------------------------------------------------------------------
    // Lectura de filas
    // ------------------------------------------------------------------

    /**
     * Recorre las filas de una tabla parte a parte, sin tenerla entera en
     * memoria: lo que hay a la vez es una parte más lo que el llamante se
     * quede. Cada parte tiene su entrada de caché, ligada a la revisión en que
     * se escribió, así que las que una escritura no toca siguen cacheadas.
     *
     * $sinCache fuerza leer del disco: la caché se invalida por la revisión,
     * que solo sube cuando escribe el motor, y la comprobación de integridad
     * necesita ver lo que hay de verdad en el fichero.
     *
     * @return \Generator<int, array>
     */
    public function filas(string $tabla, bool $sinCache = false): \Generator
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);
        for ($parte = 1; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                return;
            }
            foreach ($sinCache ? $this->decodificarParte($fichero) : $this->parte($tabla, $parte, $fichero) as $fila) {
                Memoria::comprobar('la lectura de la tabla');
                yield $fila;
            }
        }
    }

    /**
     * Todas las filas de una tabla en un array (concatenando las partes).
     *
     * @return list<array>
     */
    public function leerFilas(string $tabla, bool $sinCache = false): array
    {
        $filas = [];
        foreach ($this->filas($tabla, $sinCache) as $fila) {
            $filas[] = $fila;
        }
        return $filas;
    }

    /**
     * Cuenta las filas de una tabla sin construirlas en memoria: el motor
     * escribe una fila por línea, así que contar es leer líneas. A propósito no
     * mira la caché ni la revisión: cuenta lo que hay en el fichero.
     */
    public function contarFilas(string $tabla): int
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);

        $n = 0;
        for ($parte = 1; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                return $n;
            }
            $n += $this->contarLineasDeParte($fichero) ?? count($this->decodificarParte($fichero));
        }
    }

    /**
     * Cuenta las filas de un fichero de datos canónico (una por línea). Devuelve
     * null si no está en ese formato —editado a mano o compactado— y hay que
     * decodificarlo. En un fichero canónico con una fila corrupta cuenta lo que
     * parece una fila; detectar eso es cosa de INTEGRITY CHECK.
     */
    private function contarLineasDeParte(string $fichero): ?int
    {
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            throw JsonSqlDbError::io('No se puede leer ' . basename($fichero));
        }
        try {
            $enFilas = false;
            $n = 0;
            while (($linea = fgets($fh)) !== false) {
                $linea = trim($linea);
                if (!$enFilas) {
                    if (strncmp($linea, '"rows":', 7) !== 0) {
                        continue;                     // cabecera
                    }
                    if (substr($linea, -2) === '[]') {
                        return 0;
                    }
                    $enFilas = true;
                    continue;
                }
                $linea = rtrim($linea, ',');
                if ($linea !== '' && $linea[0] === '{' && substr($linea, -1) === '}') {
                    $n++;
                }
            }
            return $enFilas ? $n : null;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Filas de un fichero de datos, decodificado de una vez: para mil filas
     * es bastante más rápido que fila a fila, y el pico es una sola parte.
     *
     * @return list<array>
     */
    private function decodificarParte(string $fichero): array
    {
        Memoria::comprobarFichero($fichero);
        $json = json_decode((string)file_get_contents($fichero), true);
        if (!is_array($json) || !is_array($json['rows'] ?? null)) {
            throw JsonSqlDbError::io('Datos ilegibles en ' . basename($fichero));
        }
        return array_values($json['rows']);
    }

    /**
     * Filas de una parte, pasando por su caché. La clave lleva la revisión en
     * que se escribió esa parte, que consta en el fichero de revisión de la
     * tabla, así que se invalida sola al reescribirla y no antes.
     *
     * @return list<array>
     */
    private function parte(string $tabla, int $parte, string $fichero): array
    {
        $clave = $this->claveParte($tabla, $parte);
        $filas = $this->cacheLeer($clave);
        if (is_array($filas)) {
            return $filas;
        }
        $filas = $this->decodificarParte($fichero);
        $this->cacheGuardar($clave, $filas);
        return $filas;
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    /**
     * Escribe de una vez todo lo que cambia de una tabla: datos, estructura e
     * índices, con una sola subida de revisión. Van juntos porque el fichero
     * de revisión dice qué índices y qué partes siguen valiendo.
     *
     * $filas o $meta a null significa «esto no cambia». $definiciones son los
     * índices que deben quedar escritos (ver Indexes).
     *
     * Sin $sabeQueCambio se reescribe la tabla entera, que es lo seguro. Con
     * él, $desdePos es la posición a partir de la cual las filas se
     * desplazaron (null si ninguna) y $posSueltas las que cambiaron sin mover
     * a las demás: solo se reescriben las partes afectadas, y los índices se
     * corrigen en vez de rehacerse.
     *
     * Si no hay una escritura abierta con txIniciar(), esta se confirma sola.
     *
     * @param list<array>|null $filas
     * @param list<array{name: string, columns: list<string>, auto: bool}> $definiciones
     * @param list<int> $posSueltas
     */
    public function guardarTabla(
        string $tabla,
        ?array $filas,
        ?array $meta,
        array $definiciones = [],
        ?int $desdePos = null,
        array $posSueltas = [],
        bool $sabeQueCambio = false
    ): void {
        $this->abrirEscritura($tabla, 'ESCRITURA');
        $estado      = $this->estado($tabla);
        $partesAntes = $this->partes($tabla);
        $filasAntes  = isset($estado['rows']) ? (int)$estado['rows'] : null;

        if ($filas === null) {
            // Solo cambia la estructura: ni partes ni posiciones se mueven
            $nFilas = $filasAntes ?? $this->contarFilas($tabla);
            $this->escribirTabla($tabla, $meta, $definiciones, fn(): array => $this->leerFilas($tabla),
                [], $nFilas, $filasAntes, null, [], $partesAntes);
            return;
        }
        $filas  = array_values($filas);
        $nFilas = count($filas);
        $partes = $filas === [] ? [[]] : array_chunk($filas, $this->filasPorParte);

        // Solo se reescriben las partes que pudieron cambiar: insertar una fila
        // en una tabla de cien partes cambia la última, y rehacer las cien es
        // el grueso del coste. Si el tamaño de parte no es el de antes, los
        // límites se han movido y hay que rehacerlas todas.
        if (!$sabeQueCambio || ($estado['chunk'] ?? null) !== $this->filasPorParte) {
            $this->escribirTabla($tabla, $meta, $definiciones, fn(): array => $filas, $partes, $nFilas, null, null, [], $partesAntes);
            return;
        }
        $aEscribir = [];
        $sueltas   = [];
        foreach ($posSueltas as $pos) {
            $aEscribir[intdiv((int)$pos, $this->filasPorParte)] = true;
            $sueltas[(int)$pos] = $filas[$pos];
        }
        $primera = $desdePos === null ? count($partes) : intdiv($desdePos, $this->filasPorParte);
        for ($i = min($primera, $partesAntes); $i < count($partes); $i++) {
            $aEscribir[$i] = true;                 // desplazadas, o partes que no existían
        }
        $partes = array_intersect_key($partes, $aEscribir);

        // Con qué se puede corregir el índice anterior en vez de rehacerlo:
        // desde dónde se desplazaron las filas (nada, si solo se añadieron al
        // final) y cuáles cambiaron en su sitio
        $desde = $desdePos ?? $nFilas;
        if ($filasAntes === null || $desde > $filasAntes || $sueltas !== [] && $nFilas !== $filasAntes) {
            $desde = null;
        }
        $this->escribirTabla($tabla, $meta, $definiciones, fn(): array => $filas, $partes, $nFilas, $desde,
            $desde === null ? null : array_slice($filas, $desde), $sueltas, $partesAntes);
    }

    /**
     * Añade filas al final de una tabla sin leerla entera: se reescribe la
     * última parte y las que hagan falta, y los índices se amplían con las
     * nuevas. Si el estado de la tabla no permite afirmar dónde acaba, se
     * vuelve al camino normal.
     *
     * @param list<array> $nuevas
     * @param list<array{name: string, columns: list<string>, auto: bool}> $definiciones
     */
    public function anadirFilas(string $tabla, array $nuevas, ?array $meta, array $definiciones = []): void
    {
        $nuevas = array_values($nuevas);
        [$filasAntes, $partesAntes, $ultima] = $this->situacion($tabla, 'ESCRITURA');
        $todas = function () use ($tabla, $nuevas): array {
            $filas = $this->leerFilas($tabla);
            foreach ($nuevas as $fila) {
                $filas[] = $fila;
            }
            return $filas;
        };
        if ($filasAntes === null) {
            $filas = $todas();
            $this->escribirTabla($tabla, $meta, $definiciones, fn(): array => $filas,
                array_chunk($filas, $this->filasPorParte) ?: [[]], count($filas), null, null, [], $partesAntes);
            return;
        }
        $cola = $filasAntes === 0 ? [] : $this->parte($tabla, $ultima, $this->ficheroDatos($tabla, $ultima));
        foreach ($nuevas as $fila) {
            $cola[] = $fila;
        }
        $partes = [];
        foreach (array_chunk($cola, $this->filasPorParte) ?: [[]] as $i => $bloque) {
            $partes[$ultima - 1 + $i] = $bloque;
        }
        $this->escribirTabla($tabla, $meta, $definiciones, $todas, $partes, $filasAntes + count($nuevas),
            $filasAntes, $nuevas, [], $partesAntes);
    }

    /**
     * Cambia y borra filas por posición sin leer la tabla entera: se leen y
     * reescriben solo las partes desde la primera posición afectada. Un
     * borrado desplaza todas las filas siguientes, así que desde ahí se
     * rehacen las partes; un cambio en su sitio toca solo la suya.
     *
     * @param array<int,array> $cambios  posición => fila nueva
     * @param list<int>        $borradas posiciones que desaparecen
     * @param list<array{name: string, columns: list<string>, auto: bool}> $definiciones
     */
    public function modificarFilas(string $tabla, array $cambios, array $borradas, ?array $meta, array $definiciones = []): void
    {
        [$filasAntes, $partesAntes] = $this->situacion($tabla, 'ESCRITURA');
        $chunk = $this->filasPorParte;
        $todas = function () use ($tabla, $cambios, $borradas): array {
            $filas = $this->leerFilas($tabla);
            foreach ($cambios as $pos => $fila) {
                $filas[$pos] = $fila;
            }
            foreach ($borradas as $pos) {
                unset($filas[$pos]);
            }
            return array_values($filas);
        };
        if ($filasAntes === null) {
            $filas = $todas();
            $this->escribirTabla($tabla, $meta, $definiciones, fn(): array => $filas,
                array_chunk($filas, $chunk) ?: [[]], count($filas), null, null, [], $partesAntes);
            return;
        }
        $desde   = $borradas === [] ? $filasAntes : min($borradas);
        $primera = intdiv($desde, $chunk);            // base 0
        $fuera   = array_fill_keys($borradas, true);
        $partes  = [];
        $sueltas = [];
        foreach ($cambios as $pos => $fila) {
            if ($pos < $desde) {
                $sueltas[$pos] = $fila;
                $i = intdiv($pos, $chunk);
                $partes[$i] ??= $this->parte($tabla, $i + 1, $this->ficheroDatos($tabla, $i + 1));
                $partes[$i][$pos - $i * $chunk] = $fila;
            }
        }
        $cola = null;
        if ($borradas !== []) {
            // Desde la primera borrada, todo se recoloca: se leen esas partes
            // y se vuelven a repartir sin las borradas
            $cola     = [];
            $cabeza   = [];
            for ($i = $primera; $i < $partesAntes; $i++) {
                foreach ($this->parte($tabla, $i + 1, $this->ficheroDatos($tabla, $i + 1)) as $j => $fila) {
                    $pos = $i * $chunk + $j;
                    if ($pos < $desde) {
                        $cabeza[] = $fila;
                    } elseif (!isset($fuera[$pos])) {
                        $cola[] = $cambios[$pos] ?? $fila;
                    }
                }
                Memoria::comprobar('la lectura de la tabla');
            }
            $partes = array_intersect_key($partes, array_flip(range(0, max(0, $primera - 1))));
            foreach (array_chunk(array_merge($cabeza, $cola), $chunk) ?: [[]] as $i => $bloque) {
                $partes[$primera + $i] = $bloque;
            }
        }
        $this->escribirTabla($tabla, $meta, $definiciones, $todas, $partes,
            $filasAntes - count($borradas), $desde, $cola, $sueltas, $partesAntes);
    }

    /**
     * Filas de unas posiciones concretas, leyendo solo sus partes.
     *
     * @param list<int> $posiciones
     * @return array<int,array> posición => fila, en orden de posición; las que no existen no salen
     */
    public function filasEnPosiciones(string $tabla, array $posiciones): array
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);
        $chunk = max(1, (int)($this->estado($tabla)['chunk'] ?? $this->filasPorParte));
        sort($posiciones);
        $out = [];
        foreach ($posiciones as $pos) {
            $parte   = intdiv($pos, $chunk) + 1;
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                continue;
            }
            $fila = $this->parte($tabla, $parte, $fichero)[$pos - ($parte - 1) * $chunk] ?? null;
            if ($fila !== null) {
                $out[$pos] = $fila;
            }
        }
        return $out;
    }

    /**
     * Abre la escritura de una tabla y dice cómo está: [filas, partes, última
     * parte]. Las filas son null si no se puede afirmar dónde acaba cada
     * parte —fichero de revisión de antes de la 2.5, tamaño de parte cambiado
     * o ficheros que no cuadran—, y entonces hay que leerla entera.
     *
     * @return array{0: int|null, 1: int, 2: int}
     */
    private function situacion(string $tabla, string $operacion): array
    {
        $this->abrirEscritura($tabla, $operacion);
        $estado      = $this->estado($tabla);
        $partesAntes = $this->partes($tabla);
        $filas       = isset($estado['rows']) ? (int)$estado['rows'] : null;
        $ultima      = $filas === null || $filas === 0 ? 1 : intdiv($filas - 1, $this->filasPorParte) + 1;
        if (($estado['chunk'] ?? null) !== $this->filasPorParte || $ultima !== max(1, $partesAntes)) {
            $filas = null;
        }
        return [$filas, $partesAntes, $ultima];
    }

    /** Comprobaciones comunes a toda escritura de una tabla y apertura de la suya si no hay otra. */
    private function abrirEscritura(string $tabla, string $operacion): void
    {
        self::validarTabla($tabla);
        $this->exigirEscritura();
        if (isset($this->txTablas[$tabla])) {
            throw JsonSqlDbError::lock("La tabla '$tabla' ya se ha guardado en esta escritura");
        }
        if ($this->txAmbito === null) {
            $this->txIniciar($operacion, isset($this->locksTabla[$tabla]) ? $tabla : null);
            $this->txPropia = true;
        }
        $this->txTablas[$tabla] = true;
        // Un proceso muerto de golpe no ejecuta el finally de escribirTemporal()
        // y deja su temporal ahí. Aquí se tiene el exclusivo de la tabla, así
        // que cualquier temporal suyo es de un proceso muerto y sobra.
        $this->barrerTemporales($tabla);
    }

    /**
     * El núcleo de la escritura: partes, estructura, revisión e índices.
     *
     * @param callable():list<array> $todas   devuelve todas las filas tal como
     *                                        quedan; solo se llama si un índice
     *                                        hay que rehacerlo entero
     * @param array<int,list<array>> $partes  partes a escribir, índice base 0 => filas
     * @param int                    $nFilas  cuántas filas queda teniendo la tabla
     * @param int|null               $desde   primera posición cuyo contenido cambió
     *                                        respecto al índice anterior; null si
     *                                        hay que rehacerlo
     * @param list<array>|null       $cola    las filas desde $desde, en orden
     * @param array<int,array>       $sueltas posición => fila nueva, cambiadas en su
     *                                        sitio por debajo de $desde
     */
    private function escribirTabla(
        string $tabla,
        ?array $meta,
        array $definiciones,
        callable $todas,
        array $partes,
        int $nFilas,
        ?int $desde,
        ?array $cola,
        array $sueltas,
        int $partesAntes
    ): void {
        $filas = null;                                  // todas las filas, si algún índice las pide
        $definiciones = $this->indices ? $definiciones : [];
        $estado       = $this->estado($tabla);
        $revAntes     = $estado['rev'];
        $rev          = $revAntes + 1;
        $revsAntes    = array_slice(array_pad((array)($estado['parts'] ?? []), $partesAntes, $revAntes), 0, $partesAntes);
        $total        = max(1, (int)ceil($nFilas / $this->filasPorParte));
        $revsPartes   = array_slice(array_pad($revsAntes, $total, $rev), 0, $total);
        $revsIndices  = [];

        // Las filas viejas de las posiciones sueltas, para quitarlas del índice:
        // las partes todavía son las de antes, porque nada se ha renombrado
        $viejas = [];
        if ($desde !== null && $sueltas !== [] && $definiciones !== []) {
            foreach ($this->filasEnPosiciones($tabla, array_keys($sueltas)) as $pos => $fila) {
                $viejas[$pos] = $fila;
            }
            if (count($viejas) !== count($sueltas)) {
                $desde = null;                     // el disco no cuadra con lo que se creía
            }
        }

        $indicesAntes = $this->indicesEnDisco($tabla);
        foreach ($partes as $i => $bloque) {
            $this->escribirParte($this->ficheroDatos($tabla, $i + 1), $tabla, $bloque);
            $revsPartes[$i]  = $rev;
            $this->txCache[] = [$this->claveParte($tabla, $i + 1, $rev), $bloque];
        }
        unset($partes, $bloque);
        for ($parte = $total + 1; $parte <= $partesAntes; $parte++) {
            $this->borrarFichero($this->ficheroDatos($tabla, $parte));
        }

        if ($meta !== null) {
            $meta['updated_at'] = date('Y-m-d H:i:s');
            $this->escribirAtomico($this->ficheroMeta($tabla), json_encode($meta, self::JSON_META) . "\n");
            $this->txCache[] = [$this->claveCache($tabla, 'm', $rev), $meta];
        }

        // Los índices: se rehacen, se corrigen o, si no cambian, se dejan
        // como están y solo se anota que siguen valiendo
        $vigentes = [];
        foreach ($definiciones as $def) {
            $vigentes[$def['name']] = true;
            $viejo = $desde === null ? null : $this->leerIndice($tabla, $def);
            if ($desde === null || $viejo === null || !isset($viejo['rows']) || (int)$viejo['rows'] < $desde) {
                $filas ??= $todas();
                $keys = Indexes::construir($filas, $def['columns']);
            } else {
                $keys = $this->clavesDelIndice($def, $viejo, $nFilas, $desde, $cola, $sueltas, $viejas);
            }
            if ($keys === null) {
                $revsIndices[$def['name']] = (int)$viejo['rev'];
                continue;
            }
            $idx = [
                'index'   => $def['name'],
                'table'   => $tabla,
                'columns' => $def['columns'],
                'rev'     => $rev,
                'rows'    => $nFilas,
                'chunk'   => $this->filasPorParte,
                'keys'    => $keys,
            ];
            unset($keys, $viejo);
            $this->escribirAtomico($this->ficheroIndice($tabla, $def['name']), json_encode($idx, self::JSON_FILA) . "\n");
            $this->txCache[]           = [$this->claveCache($tabla, 'x' . $def['name'], $rev), $idx];
            $revsIndices[$def['name']] = $rev;
            unset($idx);
        }
        foreach ($indicesAntes as $nombre => $fichero) {
            if (!isset($vigentes[$nombre])) {
                $this->borrarFichero($fichero);
            }
        }

        // La revisión y el estado de partes e índices: es lo que invalida la caché
        $nuevo = ['rev' => $rev, 'chunk' => $this->filasPorParte, 'rows' => $nFilas,
                  'parts' => array_values($revsPartes), 'indexes' => (object)$revsIndices];
        $this->escribirAtomico($this->ficheroRev($tabla), json_encode($nuevo, self::JSON_META) . "\n");
        $nuevo['indexes'] = $revsIndices;

        $this->limpiarCache($tabla, $estado, $nuevo, array_keys($indicesAntes));
        $this->estados[$tabla] = $nuevo;
        unset($this->indicesMemo[$tabla]);

        if ($this->txPropia) {
            $this->txPropia = false;
            $this->txConfirmar();
        }
    }

    /** Reescribe todas las filas de una tabla. */
    public function guardarFilas(string $tabla, array $filas, array $definiciones = []): void
    {
        $this->guardarTabla($tabla, $filas, null, $definiciones);
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
        $propia = $this->txAmbito === null;
        if ($propia) {
            $this->txIniciar('DROP TABLE', isset($this->locksTabla[$tabla]) ? $tabla : null);
        }
        $this->txTablas[$tabla] = true;

        $indices = $this->indicesEnDisco($tabla);
        $partes  = $this->partes($tabla);
        $this->limpiarCache($tabla, $this->estado($tabla), ['rev' => -1, 'parts' => [], 'indexes' => []], array_keys($indices));

        $this->borrarFichero($this->ficheroMeta($tabla));
        for ($parte = 1; $parte <= $partes; $parte++) {
            $this->borrarFichero($this->ficheroDatos($tabla, $parte));
        }
        foreach ($indices as $fichero) {
            $this->borrarFichero($fichero);
        }
        $this->borrarFichero($this->ficheroRev($tabla));
        unset($this->estados[$tabla], $this->indicesMemo[$tabla]);

        if ($propia) {
            $this->txConfirmar();
        }
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
        $propia = $this->txAmbito === null;
        if ($propia) {
            $this->txIniciar('RENAME TABLE');
        }
        $meta  = $this->leerMeta($desde);
        $filas = $this->leerFilas($desde);
        $meta['table'] = $hasta;

        $this->borrarTabla($desde);
        $this->guardarTabla($hasta, $filas, $meta, $definiciones);
        if ($propia) {
            $this->txConfirmar();
        }
    }

    /**
     * Borra los temporales huérfanos de una tabla, o los de toda la base. Solo
     * se llama teniendo el bloqueo exclusivo que corresponde, que garantiza
     * que ningún temporal que se vea sea de una escritura viva; y antes de
     * escribir nada, así que no hay ningún temporal propio en vuelo.
     */
    private function barrerTemporales(?string $tabla = null): void
    {
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

    /**
     * Escribe una parte de una tabla fila a fila, sin armar el JSON entero en
     * memoria: cabecera indentada y una fila por línea.
     *
     * @param list<array> $filas
     */
    private function escribirParte(string $fichero, string $tabla, array $filas): void
    {
        $this->escribirTemporal($fichero, static function ($fh) use ($tabla, $filas, $fichero): void {
            $escribir = static function (string $texto) use ($fh, $fichero): void {
                if (@fwrite($fh, $texto) !== strlen($texto)) {
                    throw JsonSqlDbError::io('Escritura incompleta de ' . basename($fichero));
                }
            };
            $escribir("{\n  \"table\": " . json_encode($tabla, self::JSON_FILA) . ",\n  \"rows\": [");
            $sep = "\n    ";
            foreach ($filas as $fila) {
                $json = json_encode($fila, self::JSON_FILA);
                if ($json === false) {
                    throw JsonSqlDbError::io("No se puede codificar una fila de '$tabla' a JSON");
                }
                $escribir($sep . $json);
                $sep = ",\n    ";
            }
            $escribir($filas === [] ? "]\n}\n" : "\n  ]\n}\n");
        });
    }

    /** Escribe un fichero entero dentro de la escritura abierta. */
    private function escribirAtomico(string $fichero, string $contenido): void
    {
        $this->escribirTemporal($fichero, static function ($fh) use ($contenido, $fichero): void {
            if (@fwrite($fh, $contenido) !== strlen($contenido)) {
                throw JsonSqlDbError::io('Escritura incompleta de ' . basename($fichero));
            }
        });
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
     */
    private function escribirTemporal(string $fichero, callable $volcar): void
    {
        $tmp = $fichero . '.' . getmypid() . '.tmp';
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw JsonSqlDbError::io('No se puede escribir ' . basename($fichero));
        }
        try {
            $volcar($fh);
            @fflush($fh);
            if (function_exists('fsync')) {
                @fsync($fh);                // los datos, en el disco de verdad
            }
        } catch (\Throwable $e) {
            @fclose($fh);
            @unlink($tmp);
            throw $e;
        }
        @fclose($fh);
        unset($this->txBorrar[$fichero]);   // si se borraba y se vuelve a escribir, gana lo último
        $this->txRenombrar[$tmp] = $fichero;
    }

    /** Apunta un fichero para borrarlo al confirmar. */
    private function borrarFichero(string $fichero): void
    {
        $tmp = array_search($fichero, $this->txRenombrar, true);
        if ($tmp !== false) {
            @unlink((string)$tmp);
            unset($this->txRenombrar[$tmp]);
        }
        $this->txBorrar[$fichero] = true;
    }

    /** Escribe un fichero y lo pone en su sitio ya mismo (temporal + fsync + rename). */
    private function volcarFichero(string $fichero, string $contenido): void
    {
        $tmp = $fichero . '.' . getmypid() . '.tmp';
        try {
            $fh = @fopen($tmp, 'wb');
            if ($fh === false || @fwrite($fh, $contenido) !== strlen($contenido)) {
                throw JsonSqlDbError::io('No se puede escribir ' . basename($fichero));
            }
            @fflush($fh);
            if (function_exists('fsync')) {
                @fsync($fh);
            }
            @fclose($fh);
            if (!@rename($tmp, $fichero)) {
                @unlink($fichero);
                if (!@rename($tmp, $fichero)) {
                    throw JsonSqlDbError::io('No se puede reemplazar ' . basename($fichero));
                }
            }
            $this->fsyncDir($fichero);
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
    private function fsyncDir(string $ruta): void
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

    private function ficheroIndice(string $tabla, string $indice): string
    {
        return $this->dir . '/' . $tabla . '.idx.' . $indice . '.json';
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
     * Las claves de un índice corrigiendo el anterior, que quien llama ha
     * comprobado que sirve: de estas columnas, válido para la revisión
     * anterior y con tantas filas como posiciones había antes de $desde. Una
     * entrada de menos no da un error, da una consulta que devuelve de menos.
     *
     * Las posiciones anteriores a $desde no cambiaron: se cortan las que hay
     * a partir de ahí y se añaden las de $cola. Las sueltas (por debajo de
     * $desde) se sustituyen una a una.
     *
     * Devuelve null si el índice anterior sigue valiendo tal cual.
     *
     * @param array<int,array> $sueltas posición => fila nueva
     * @param array<int,array> $viejas  posición => fila de antes
     * @return array<string, int|list<int>>|null
     */
    private function clavesDelIndice(array $def, array $viejo, int $nFilas, int $desde, ?array $cola, array $sueltas, array $viejas): ?array
    {
        $keys   = $viejo['keys'];
        $habia  = (int)$viejo['rows'];
        $cambio = false;
        foreach ($sueltas as $pos => $fila) {
            $keys = Indexes::sustituir($keys, $def['columns'], $pos, $viejas[$pos], $fila, $cambio);
        }
        if ($habia > $desde) {
            $keys   = Indexes::recortar($keys, $desde);      // las de detrás se movieron o ya no están
            $cambio = true;
        }
        if ($desde < $nFilas) {
            $keys   = Indexes::ampliar($keys, $cola ?? [], $def['columns'], $desde);
            $cambio = true;
        }
        return $cambio ? $keys : null;
    }

    /**
     * Claves de un índice válido para la revisión actual, o null si no lo
     * hay. Sirve para comprobar unicidad o existencia sin leer la tabla.
     *
     * @param array{name: string, columns: list<string>} $def
     * @return array<string, int|list<int>>|null
     */
    public function clavesDeIndice(string $tabla, array $def): ?array
    {
        self::validarTabla($tabla);
        if (!$this->indices) {
            return null;
        }
        $this->bloquearLectura($tabla);
        $idx = $this->leerIndice($tabla, $def);
        return $idx === null ? null : $idx['keys'];
    }

    /**
     * Lee un índice vigente, o null si no sirve: el fichero de revisión de la
     * tabla dice en qué revisión se escribió cada índice (desde la 2.5) y el
     * índice tiene que decir la misma; antes, tenía que ser la de la tabla.
     * Las columnas tienen que ser las esperadas. Un índice desfasado o tocado
     * a mano se ignora y la consulta recorre la tabla: más lento, nunca
     * equivocado.
     *
     * Dentro de un bloqueo se guarda lo leído: una escritura pregunta por el
     * mismo índice varias veces.
     *
     * @param array{name: string, columns: list<string>} $def
     */
    private function leerIndice(string $tabla, array $def): ?array
    {
        $estado = $this->estado($tabla);
        $rev    = (int)($estado['indexes'][$def['name']] ?? $estado['rev']);
        $idx    = $this->indicesMemo[$tabla][$def['name']] ?? null;
        if ($idx === null) {
            $clave = $this->claveCache($tabla, 'x' . $def['name'], $rev);
            $idx   = $this->cacheLeer($clave);
            if ($idx === null) {
                $fichero = $this->ficheroIndice($tabla, $def['name']);
                if (!is_file($fichero)) {
                    return null;
                }
                Memoria::comprobarFichero($fichero);
                $idx = json_decode((string)file_get_contents($fichero), true);
                if (!is_array($idx) || !is_array($idx['keys'] ?? null) || (int)($idx['rev'] ?? -1) !== $rev) {
                    return null;
                }
                $this->cacheGuardar($clave, $idx);
            }
            $this->indicesMemo[$tabla][$def['name']] = $idx;
        }
        return ($idx['columns'] ?? null) === $def['columns'] ? $idx : null;
    }

    /**
     * Filas de una tabla que casan con unas claves de índice, leyendo solo las
     * partes donde están. Devuelve null si el índice no sirve o no compensa, y
     * entonces hay que recorrer la tabla. Las filas salen en el orden de la
     * tabla, que es el que espera un SELECT sin ORDER BY.
     *
     * @param array{name: string, columns: list<string>} $def
     * @param list<string> $claves
     * @return list<array>|null
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
            // llevan la longitud por delante, así que el prefijo es inequívoco
            foreach ($idx['keys'] as $k => $lista) {
                foreach ($claves as $c) {
                    if (strncmp((string)$k, $c, strlen($c)) === 0) {
                        foreach (Indexes::posiciones($lista) as $p) { $posiciones[$p] = true; }
                        break;
                    }
                }
            }
        } else {
            foreach ($claves as $c) {
                if (isset($idx['keys'][$c])) {
                    foreach (Indexes::posiciones($idx['keys'][$c]) as $p) { $posiciones[$p] = true; }
                }
            }
        }

        $chunk = max(1, (int)($idx['chunk'] ?? $this->filasPorParte));
        $total = max(0, (int)($idx['rows'] ?? 0));
        $todas = $total === 0 ? 1 : (int)ceil($total / $chunk);
        unset($idx);

        $necesarias = [];
        foreach (array_keys($posiciones) as $p) {
            $necesarias[intdiv($p, $chunk) + 1] = true;
        }
        // Leer más de la mitad de las partes no ahorra bastante respecto a
        // recorrer la tabla. Sin ninguna, se sale con la lista vacía sin abrir
        // un solo fichero.
        if ($necesarias !== [] && count($necesarias) * 2 > $todas) {
            return null;
        }

        $filas = [];
        foreach (array_keys($necesarias) as $parte) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                return null;                          // índice y datos no cuadran
            }
            $base = ($parte - 1) * $chunk;
            foreach ($this->parte($tabla, $parte, $fichero) as $desfase => $fila) {
                if (isset($posiciones[$base + $desfase])) {
                    $filas[$base + $desfase] = $fila;
                    Memoria::comprobar('la lectura por índice');
                }
            }
        }
        ksort($filas);
        return array_values($filas);
    }

    // ------------------------------------------------------------------
    // Ficheros
    // ------------------------------------------------------------------

    private function ficheroMeta(string $tabla): string
    {
        return $this->dir . '/' . $tabla . '.meta.json';
    }

    private function ficheroRev(string $tabla): string
    {
        return $this->dir . '/' . $tabla . '.rev.json';
    }

    private function ficheroDatos(string $tabla, int $parte): string
    {
        return $this->dir . '/' . $tabla . ($parte > 1 ? '.part' . $parte : '') . '.json';
    }

    // ------------------------------------------------------------------
    // Revisiones y caché
    // ------------------------------------------------------------------

    /**
     * Estado de una tabla según su fichero de revisión:
     *
     *   rev    sube en cada escritura suya e invalida su caché
     *   chunk  tamaño de parte con que se escribió
     *   rows     cuántas filas tiene (desde la 2.5)
     *   parts    revisión en que se escribió cada parte (desde la 2.5)
     *   indexes  revisión en que se escribió cada índice (desde la 2.5)
     *
     * Cada tabla guarda el suyo: dos escrituras en tablas distintas van a la
     * vez y un fichero común lo reescribirían las dos enteras.
     */
    private function estado(string $tabla): array
    {
        if (isset($this->estados[$tabla])) {
            return $this->estados[$tabla];
        }
        $fichero = $this->ficheroRev($tabla);
        $json    = is_file($fichero) ? json_decode((string)@file_get_contents($fichero), true) : null;
        $estado  = is_array($json) ? ['rev' => (int)($json['rev'] ?? 0)] + $json : ['rev' => $this->revLegada($tabla)];
        $estado['indexes'] = (array)($estado['indexes'] ?? []);
        return $this->estados[$tabla] = $estado;
    }

    private function rev(string $tabla): int
    {
        return $this->estado($tabla)['rev'];
    }

    /**
     * Revisión en el _revs.json de las versiones anteriores a la 2.0, para que
     * una base antigua no reutilice revisiones ya usadas por la caché.
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

    private function claveCache(string $tabla, string $tipo, ?int $rev = null): string
    {
        return $this->prefijo . $tabla . ':' . $tipo . ':' . ($rev ?? $this->rev($tabla));
    }

    /**
     * Clave de caché de una parte: lleva la revisión en que se escribió ESA
     * parte, no la de la tabla. Una base de antes de la 2.5 no anota las
     * partes; entonces vale la de la tabla, que sube en cada escritura.
     */
    private function claveParte(string $tabla, int $parte, ?int $rev = null): string
    {
        if ($rev === null) {
            $estado = $this->estado($tabla);
            $rev    = (int)($estado['parts'][$parte - 1] ?? $estado['rev']);
        }
        return $this->prefijo . $tabla . ':p' . $parte . ':' . $rev;
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
        Memoria::comprobarFichero($fichero);
        $val = @unserialize((string)file_get_contents($fichero), ['allowed_classes' => false]);
        return $val === false ? null : $val;
    }

    private function cacheGuardar(string $clave, $valor): void
    {
        // Guardar no es gratis: serializar tiene el valor dos veces un instante.
        // Si ya se va justo, mejor sin caché que sin memoria.
        if (!$this->cache || Memoria::apretado()) {
            return;
        }
        if ($this->apcu) {
            apcu_store($clave, $valor);
            return;
        }
        if (!is_dir($this->dirCache) && !@mkdir($this->dirCache, 0775, true) && !is_dir($this->dirCache)) {
            return;   // sin caché en disco: el motor sigue funcionando
        }
        // Sin escritura atómica ni fsync: la caché es regenerable y su clave
        // lleva la revisión, así que un fichero a medias solo produce un
        // unserialize() fallido, que cacheLeer() trata como «no hay caché»
        @file_put_contents($this->ficheroCache($clave), serialize($valor));
    }

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
    private function limpiarCache(string $tabla, array $antes, array $despues, array $indices): void
    {
        $revAntes  = (int)$antes['rev'];
        $partes    = $this->partes($tabla);
        $conservar = [];
        $sobran    = ['m:' . $revAntes];
        for ($i = 0; $i < $partes; $i++) {
            $r = (int)($antes['parts'][$i] ?? $revAntes);
            if (($despues['parts'][$i] ?? null) === $r) {
                $conservar['p' . ($i + 1) . $r] = true;
            } else {
                $sobran[] = 'p' . ($i + 1) . ':' . $r;
            }
        }
        foreach ($indices as $n) {
            $r = (int)($antes['indexes'][$n] ?? $revAntes);
            if (($despues['indexes'][$n] ?? null) === $r) {
                $conservar['x' . $n . $r] = true;
            } else {
                $sobran[] = 'x' . $n . ':' . $r;
            }
        }
        if ($this->apcu) {
            foreach ($sobran as $s) {
                apcu_delete($this->prefijo . $tabla . ':' . $s);
            }
            return;
        }
        $md5 = md5($this->prefijo . $tabla);
        foreach ((array)glob($this->dirCache . '/' . $md5 . '.*.cache') as $f) {
            if (!isset($conservar[substr(basename((string)$f, '.cache'), strlen($md5) + 1)])) {
                @unlink((string)$f);
            }
        }
    }

    private function ficheroCache(string $clave): string
    {
        // md5(prefijo+tabla) agrupa las entradas de una misma tabla para poder borrarlas juntas
        [, , $tabla, $tipo, $rev] = explode(':', $clave);
        return $this->dirCache . '/' . md5($this->prefijo . $tabla) . '.' . $tipo . $rev . '.cache';
    }
}
