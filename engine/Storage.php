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
 *   <raiz>/<base>/<tabla>.idx.<n>.json  índice de búsqueda, trozo de la parte 1
 *   <raiz>/<base>/<tabla>.idx.<n>.part2.json  trozo de la parte 2 (ver Indexes)
 *   <raiz>/<base>/.cache/             caché serializada (partes, trozos de índice,
 *                                     estructura y resultados; regenerable)
 *   <raiz>/<base>/.tx/<ámbito>/       journal de una escritura en curso
 *   <raiz>/<base>/.lock               fichero de bloqueo de la base
 *   <raiz>/<base>/.<tabla>.lock       fichero de bloqueo de una tabla
 *   <raiz>/<base>/.turno, .<tabla>.turno  su torno (ver abrirLock())
 *
 * Concurrencia: dos niveles de bloqueo con flock, siempre pedidos en este orden
 * —primero la base, después la tabla—, que es lo que hace imposible un
 * interbloqueo. Ver bloquear().
 *
 * Durabilidad: cada fichero se escribe en un temporal que se fuerza a disco.
 * Cuando una operación toca más de uno, los temporales se ponen en su sitio
 * de golpe guiados por un journal de rehacer (ver txConfirmar()).
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Storage
{
    private const JSON_FILA = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    private const JSON_META = self::JSON_FILA | JSON_PRETTY_PRINT;

    /**
     * Hasta cuántas filas de una misma parte se leen línea a línea en vez de
     * decodificar la parte: una línea cuesta unas 30 µs y una parte de mil
     * filas unos 400.
     */
    private const FILAS_POR_LINEA = 8;

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
    /** @var array<string,int> partes de cada tabla, dentro del bloqueo */
    private array  $partesMemo = [];
    /** @var array<string,int> siguiente autoincremento a anotar en rev.json en la escritura en curso */
    private array  $autoincPendiente = [];
    /** @var array<string,mixed> últimas entradas de caché, compartidas por todo el proceso (ver recordar()) */
    private static array $memoProceso = [];
    /** @var array<string,array<string,true>> las claves de $memoProceso por grupo, en orden de llegada */
    private static array $memoGrupos = ['x' => [], 'o' => [], 'm' => []];
    /** @var array<string,int>|null _revs.json de versiones anteriores a la 2.0 */
    private ?array $revsLegadas = null;
    private bool   $cache;
    private bool   $cacheDisco;
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
    /** Tabla que se está escribiendo por partes, con su bloqueo compartido (ver bloquearPartes()) */
    private ?string $modoPartes  = null;
    /** @var array<string,array> lo que la escritura por partes tiene que confirmar, por tabla */
    private array   $pendientePartes = [];
    /** @var array{0: int, 1: int} escrituras por partes confirmadas y repetidas con la tabla entera, en este proceso */
    private static array $cuentaPartes = [0, 0];
    private bool    $txPropia    = false;   // la abrió guardarTabla() y la confirma ella
    private string  $txOperacion = '';
    private array   $txRenombrar = [];
    /** @var array<string,true> temporales que no se fuerzan a disco porque su contenido se puede rehacer */
    private array   $txRegenerables = [];
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
        $this->apcu       = function_exists('apcu_enabled') && apcu_enabled();
        $this->cacheDisco = Config::cacheActiva() === true;
        $this->cache      = $this->cacheDisco || (Config::cacheActiva() === 'apcu' && $this->apcu);
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
                if ($this->modoPartes !== null) {
                    throw new ConflictoPartes();   // algo pide más que su tabla: con la tabla entera
                }
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
        $this->partesMemo    = [];
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
        // Escribiendo por partes solo se leen las estructuras de otras tablas
        // (para ver quién referencia a quién), que son un fichero cada una y
        // se reemplazan de una pieza: sin bloquearlas. Cogerles el compartido
        // haría esperarse a dos escrituras por partes de tablas distintas
        if ($this->lockNivel === 0 || $this->lockExclusivo || isset($this->locksTabla[$tabla])
            || $this->modoPartes !== null) {
            return;
        }
        $this->locksTabla[$tabla] = $this->abrirLock($this->dir . '/.' . $tabla . '.lock', false, "la tabla '$tabla'");
    }

    /**
     * Coge un bloqueo pasando antes por su torno.
     *
     * flock no da preferencia a nadie: un exclusivo espera a que no quede
     * ningún compartido, y con lectores que se van solapando sin parar ese
     * momento puede no llegar nunca, y una escritura se queda esperando
     * segundos. El torno lo arregla: todo el mundo lo cruza antes de pedir el
     * bloqueo y lo suelta nada más tenerlo. Un escritor que espera se queda
     * con el torno, así que los lectores nuevos se paran en él; los que ya
     * están dentro terminan, el escritor entra, y al soltar el torno pasan
     * los que esperaban. Cuesta dos llamadas más por bloqueo y no cambia
     * nada más: el orden de bloqueo sigue siendo el mismo para todos.
     *
     * @return resource
     */
    private function abrirLock(string $fichero, bool $exclusivo, string $queEs)
    {
        $torno = @fopen(substr($fichero, 0, -5) . '.turno', 'c');
        if ($torno !== false && !flock($torno, $exclusivo ? LOCK_EX : LOCK_SH)) {
            fclose($torno);
            $torno = false;
        }
        $fh = @fopen($fichero, 'c');
        if ($fh === false) {
            if ($torno !== false) {
                flock($torno, LOCK_UN);
                fclose($torno);
            }
            throw JsonSqlDbError::io("No se puede abrir el fichero de bloqueo de $queEs");
        }
        $ok = flock($fh, $exclusivo ? LOCK_EX : LOCK_SH);
        if ($torno !== false) {
            flock($torno, LOCK_UN);
            fclose($torno);
        }
        if (!$ok) {
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
        $this->modoPartes    = null;
        $this->pendientePartes = [];
        $this->estados       = [];
        $this->indicesMemo   = [];
        $this->partesMemo    = [];
        $this->revsLegadas   = null;
    }

    // ------------------------------------------------------------------
    // Escritura por partes
    //
    // Una tabla es una serie de partes de mil filas, y un UPDATE o un DELETE
    // corriente cambia una o dos. Con el bloqueo de la tabla entera, dos
    // escrituras sobre filas lejanas de la misma tabla se esperan la una a la
    // otra durante todo su trabajo, y las lecturas esperan a las dos.
    //
    // Con la escritura por partes, el trabajo caro —leer, calcular, escribir
    // y forzar a disco los ficheros nuevos— se hace con el bloqueo COMPARTIDO
    // de la tabla: lecturas y otras escrituras por partes siguen a la vez. Al
    // confirmar, la escritura pasa al exclusivo, solo el tiempo de apuntar la
    // revisión y renombrar, y comprueba que ninguna de las partes que ha
    // reescrito haya cambiado entretanto. Si alguna cambió —dos escrituras en
    // la misma parte— se descarta sin haber tocado nada y se repite con la
    // tabla entera: se pone a la cola, y ninguna de las dos se pierde.
    //
    // Solo para lo que depende de cada fila y de nada más (ver
    // Database::tablaPorPartes()): sin claves foráneas ni triggers, sin
    // subconsultas y sin tocar columnas únicas. Lo demás, como siempre.
    // ------------------------------------------------------------------

    /**
     * Bloqueo para una escritura por partes: compartido de la base y de la
     * tabla. Hace la recuperación de siempre al abrir la base.
     */
    public function bloquearPartes(string $tabla): void
    {
        self::validarTabla($tabla);
        if ($this->lockNivel > 0) {
            throw JsonSqlDbError::lock('La escritura por partes tiene que ser el bloqueo de fuera');
        }
        $this->bloquear(false);
        $this->locksTabla[$tabla] = $this->abrirLock($this->dir . '/.' . $tabla . '.lock', false, "la tabla '$tabla'");
        $this->modoPartes = $tabla;
        $this->pendientePartes = [];
        // Una tabla de antes de la 2.5, sin revisión por parte, no se puede
        // escribir por partes: se va por el camino de siempre
        $estado = $this->estado($tabla);
        $moderna = isset($estado['rows'], $estado['parts'], $estado['creada']) && is_array($estado['parts']);
        foreach ($estado['indexes'] as $revs) {
            $moderna = $moderna && is_array($revs);
        }
        if (!$moderna) {
            $this->desbloquear();
            throw new ConflictoPartes();
        }
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
     * La segunda mitad de la escritura por partes: pasa al exclusivo de la
     * tabla, comprueba que lo que se reescribió no ha cambiado, y deja
     * preparada la revisión nueva con los cambios de los demás y los suyos.
     * Lanza ConflictoPartes, sin haber renombrado nada, si no puede ser.
     */
    private function confirmarPartes(): void
    {
        $t = (string)$this->modoPartes;
        $p = $this->pendientePartes[$t] ?? null;

        // Primero se suelta el compartido y después se pide el exclusivo, con
        // su torno: pedirlo sin soltar el otro dejaría a dos escrituras por
        // partes esperándose la una a la otra
        flock($this->locksTabla[$t], LOCK_UN);
        fclose($this->locksTabla[$t]);
        unset($this->locksTabla[$t]);
        $this->modoPartes = null;
        $this->locksTabla[$t] = $this->abrirLock($this->dir . '/.' . $t . '.lock', true, "la tabla '$t'");
        $this->lockExclusivo  = true;
        if ($p === null) {
            return;
        }

        $conflicto = function (): void {
            $this->txAbandonar();
            throw new ConflictoPartes();
        };
        // Entre medias puede haber quedado un journal a medias de otro proceso,
        // o un barrido de temporales: los dos se resuelven por el camino de siempre
        clearstatcache();
        if ((array)glob($this->dirTx . '/*') !== []) {
            $conflicto();
        }
        foreach (array_keys($this->txRenombrar) as $tmp) {
            if (!is_file((string)$tmp)) {
                $conflicto();
            }
        }
        unset($this->estados[$t], $this->indicesMemo[$t]);
        $this->partesMemo = [];
        $fresco = $this->estado($t);
        $antes  = $p['antes'];
        $nuevo  = $p['nuevo'];
        $rev    = (int)$nuevo['rev'];
        $total  = count($nuevo['parts']);
        $partesAntes = (int)$p['partesAntes'];

        if (($fresco['creada'] ?? null) !== ($antes['creada'] ?? null) || ($fresco['chunk'] ?? null) !== ($antes['chunk'] ?? null)
            || (string)@md5_file($this->ficheroMeta($t)) !== $p['meta']) {
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
            && ((int)$fresco['rows'] !== (int)$antes['rows'] || count($fresco['parts']) !== $partesAntes)) {
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
                $m['parts'][$i] = $rev;
            }
        }
        if ($total < count($m['parts'])) {
            $m['parts'] = array_slice($m['parts'], 0, $total);
        }
        foreach ($nuevo['indexes'] as $n => $revs) {
            for ($i = 0; $i < max($total, $partesAntes); $i++) {
                $r = $revs[$i] ?? null;
                if ($tocada($i, $r) && ($fresco['indexes'][$n][$i] ?? null) !== ($antes['indexes'][$n][$i] ?? null)) {
                    $conflicto();
                }
                if ($i < $total && $r === $rev) {
                    $m['indexes'][$n][$i] = $rev;
                    $m['rangos'][$n][$i]  = $nuevo['rangos'][$n][$i] ?? null;
                }
            }
            $m['indexes'][$n] = array_slice(array_values((array)$m['indexes'][$n]), 0, $total);
            $m['rangos'][$n]  = array_slice(array_values((array)($m['rangos'][$n] ?? [])), 0, $total);
        }
        $m['rows'] = (int)$fresco['rows'] + ((int)$nuevo['rows'] - (int)$antes['rows']);
        $m['rev']  = max((int)$fresco['rev'], $rev - 1) + 1;

        $guardar = $m;
        $guardar['indexes'] = (object)$m['indexes'];
        $guardar['rangos']  = (object)$m['rangos'];
        $this->escribirAtomico($this->ficheroRev($t), json_encode($guardar, self::JSON_META) . "\n");
        $this->limpiarCache($t, $fresco, $m, $p['indicesAntes']);
        $this->estados[$t] = $m;
        $this->pendientePartes = [];
        self::$cuentaPartes[0]++;
    }

    /** ¿Se tiene el bloqueo exclusivo de esta tabla en concreto, o se escribe por partes? */
    public function tieneExclusivoDe(string $tabla): bool
    {
        return ($this->lockExclusivo && isset($this->locksTabla[$tabla])) || $this->modoPartes === $tabla;
    }

    public function enEscritura(): bool
    {
        return $this->lockNivel > 0 && ($this->lockExclusivo || $this->modoPartes !== null);
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

    /** Carpeta del journal de un ámbito, como lo dejaban las versiones 2.0 a 2.6. */
    private function dirJournal(?string $tabla): string
    {
        return $this->dirTx . '/' . ($tabla ?? '_base');    // ninguna tabla puede llamarse _base
    }

    /** Fichero del manifiesto de un ámbito (desde la 2.7): un solo fichero, sin carpeta propia. */
    private function ficheroJournal(?string $tabla): string
    {
        return $this->dirTx . '/' . ($tabla ?? '_base') . '.json';
    }

    /**
     * ¿Quedó alguna operación a medias? Cuesta un stat y un listado de una
     * carpeta casi siempre vacía, una vez por bloqueo.
     */
    private function recuperar(bool $yaExclusivo): void
    {
        if (!is_dir($this->dirTx)) {
            return;
        }
        $this->migrarJournalPlano();

        $ambitos = [];
        foreach ((array)glob($this->dirTx . '/*') as $ruta) {
            $nombre = basename((string)$ruta);
            if (substr($nombre, -5) === '.json') {
                $nombre = substr($nombre, 0, -5);
            } elseif (!is_dir((string)$ruta)) {
                continue;                             // un temporal del manifiesto: sobra
            }
            $ambitos[$nombre] = true;
        }
        foreach (array_keys($ambitos) as $ambito) {
            if ($ambito === '_base') {
                $this->recuperarBase($yaExclusivo);
            } elseif (preg_match(self::RE_TABLA, (string)$ambito)) {
                $this->recuperarTabla((string)$ambito);
            }
        }
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
        clearstatcache(true, $this->ficheroJournal(null));
        if (is_file($this->ficheroJournal(null)) || is_dir($this->dirJournal(null))) {   // por si otro se adelantó
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
        $manifiesto = $this->leerManifiesto($ambito);
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
            clearstatcache(true, $this->dirJournal($ambito));
            clearstatcache(true, $this->ficheroJournal($ambito));
            if (is_file($this->ficheroJournal($ambito)) || is_dir($this->dirJournal($ambito))) {
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
        if ($this->modoPartes !== null) {
            $this->confirmarPartes();
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

        $manifiesto = null;
        if (count($this->txRenombrar) + count($this->txBorrar) > 1) {
            $manifiesto = $this->ficheroJournal($ambito);
            if (!is_dir($this->dirTx) && !@mkdir($this->dirTx, 0775, true) && !is_dir($this->dirTx)) {
                throw JsonSqlDbError::io('No se puede crear la carpeta del journal');
            }
            $renombrar    = [];
            $regenerables = [];
            foreach ($this->txRenombrar as $tmp => $f) {
                $renombrar[basename((string)$tmp)] = basename($f);
                if (isset($this->txRegenerables[$tmp])) {
                    $regenerables[] = basename($f);
                }
            }
            // El manifiesto va forzado a disco junto con su entrada en .tx/: a
            // partir de aquí la escritura es irrevocable
            $this->volcarFichero($manifiesto, json_encode([
                'tipo'         => 'redo',
                'operacion'    => $this->txOperacion,
                'ambito'       => $ambito,
                'tablas'       => $tablas,
                'renombrar'    => $renombrar,
                'borrar'       => array_map('basename', array_keys($this->txBorrar)),
                'regenerables' => $regenerables,
                'ts'           => date('Y-m-d H:i:s'),
            ], self::JSON_META) . "\n");
        }

        $this->rehacer($this->txRenombrar, array_keys($this->txBorrar), $this->txRegenerables);

        if ($manifiesto !== null) {
            // Sin forzarlo a disco: un manifiesto que sobrevive a un corte se
            // vuelve a aplicar, y aplicarlo dos veces es lo mismo que una
            @unlink($manifiesto);
        }
        foreach ($this->txCache as [$clave, $valor]) {
            $this->cacheGuardar($clave, $valor);
        }
        $this->partesMemo = [];                    // los ficheros de datos han cambiado
        $this->txAmbito = null;
        $this->txPropia = false;
        $this->txRenombrar = $this->txRegenerables = $this->txBorrar = $this->txTablas = $this->txCache = [];
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
        $this->partesMemo = [];
        $this->autoincPendiente = [];
        $this->txAmbito = null;
        $this->txPropia = false;
        $this->txRenombrar = $this->txRegenerables = $this->txBorrar = $this->txTablas = $this->txCache = [];
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
    private function rehacer(array $renombrar, array $borrar, array $regenerables = []): void
    {
        foreach ($renombrar as $tmp => $fichero) {
            $tmp = (string)$tmp;
            if (!is_file($tmp)) {
                if (is_file($fichero) || isset($regenerables[$tmp])) {
                    continue;                     // ya se había renombrado, o se puede rehacer
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

    /**
     * El manifiesto de un ámbito, esté en su fichero (2.7) o en su carpeta
     * (2.0 a 2.6), o null si no hay ninguno legible.
     */
    private function leerManifiesto(?string $ambito): ?array
    {
        $fichero = $this->ficheroJournal($ambito);
        if (!is_file($fichero)) {
            $fichero = $this->dirJournal($ambito) . '/manifiesto.json';
        }
        $m = json_decode((string)@file_get_contents($fichero), true);
        return is_array($m) ? $m : null;
    }

    /** Aplica o deshace el journal de un ámbito, según de qué versión sea. */
    private function aplicarJournal(?string $ambito): void
    {
        $manifiesto = $this->leerManifiesto($ambito);

        // Sin manifiesto no hay nada que hacer: se escribe DESPUÉS de los
        // temporales y de una pieza, así que si falta no se tocó ningún dato
        if ($manifiesto !== null && ($manifiesto['tipo'] ?? '') === 'redo') {
            $renombrar    = [];
            $regenerables = [];
            $sinRehacer   = array_fill_keys((array)($manifiesto['regenerables'] ?? []), true);
            foreach ((array)($manifiesto['renombrar'] ?? []) as $tmp => $f) {
                if (is_string($tmp) && is_string($f) && strpos($tmp, '/') === false && strpos($f, '/') === false) {
                    $renombrar[$this->dir . '/' . $tmp] = $this->dir . '/' . $f;
                    if (isset($sinRehacer[$f])) {
                        $regenerables[$this->dir . '/' . $tmp] = true;
                    }
                }
            }
            $borrar = [];
            foreach ((array)($manifiesto['borrar'] ?? []) as $f) {
                if (is_string($f) && strpos($f, '/') === false) {
                    $borrar[] = $this->dir . '/' . $f;
                }
            }
            $this->rehacer($renombrar, $borrar, $regenerables);
        } elseif ($manifiesto !== null && ($manifiesto['estado'] ?? '') !== '') {
            $this->deshacer($ambito, $manifiesto);
        }
        foreach ((array)($manifiesto['tablas'] ?? []) as $t) {
            if (is_string($t)) {
                unset($this->estados[$t]);
            }
        }
        @unlink($this->ficheroJournal($ambito));
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
                unset($this->estados[$t]);
                foreach ((array)glob($this->dirCache . '/' . md5($this->prefijo . $this->etiqueta($t)) . '.*.cache') as $f) {
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

    /** Retira la carpeta de journal de un ámbito, de las versiones 2.0 a 2.6. */
    private function borrarDirJournal(?string $ambito): void
    {
        $dir = $this->dirJournal($ambito);
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
        $this->fsyncDir($this->dirTx);
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
        if ($meta === null) {
            $fichero = $this->ficheroMeta($tabla);
            if (!is_file($fichero)) {
                throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
            }
            $meta = json_decode((string)file_get_contents($fichero), true);
            if (!is_array($meta)) {
                throw JsonSqlDbError::io("Estructura ilegible en '$tabla.meta.json'");
            }
            $this->cacheGuardar($clave, $meta);
        }
        // El contador de autoincremento vive en rev.json desde la 2.7 (ver
        // ponerAutoincremento()); el de meta.json es el de la creación o el
        // de una versión anterior. Vale el mayor: un contador nunca baja.
        if (isset($meta['autoincrement']['next'])) {
            $vivo = $this->estado($tabla)['autoinc'] ?? null;
            if ($vivo !== null && (int)$vivo > (int)$meta['autoincrement']['next']) {
                $meta['autoincrement']['next'] = (int)$vivo;
            }
        }
        return $meta;
    }

    /**
     * Fija el siguiente valor de autoincremento de una tabla para la
     * escritura en curso: va a rev.json, que se escribe de todas formas, y
     * así un INSERT no reescribe también la estructura (ni la fuerza a
     * disco) solo por mover un contador.
     */
    public function ponerAutoincremento(string $tabla, int $siguiente): void
    {
        $this->exigirEscritura();
        $this->autoincPendiente[$tabla] = $siguiente;
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
                $linea = rtrim(trim($linea), ',');
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
                if ($linea !== '' && $linea[0] === '{' && substr($linea, -1) === '}') {
                    $n++;
                } elseif ($linea === ']') {
                    break;                            // detrás vienen los desfases
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
        $out    = [];
        $actual = 0;                                 // la parte que se tiene abierta: van ordenadas
        $filas  = [];
        foreach ($posiciones as $pos) {
            $parte = intdiv($pos, $chunk) + 1;
            if ($parte !== $actual) {
                $fichero = $this->ficheroDatos($tabla, $parte);
                $filas   = is_file($fichero) ? $this->parte($tabla, $parte, $fichero) : [];
                $actual  = $parte;
            }
            $fila = $filas[$pos - ($parte - 1) * $chunk] ?? null;
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
        if ($this->modoPartes !== null && $this->modoPartes !== $tabla) {
            throw new ConflictoPartes();          // otra tabla: con los bloqueos de siempre
        }
        if ($this->txAmbito === null) {
            $this->txIniciar($operacion, isset($this->locksTabla[$tabla]) ? $tabla : null);
            $this->txPropia = true;
        }
        $this->txTablas[$tabla] = true;
        // Un proceso muerto de golpe no ejecuta el finally de escribirTemporal()
        // y deja su temporal ahí. Pero ni con el exclusivo de la tabla se puede
        // dar por muerto todo temporal: una escritura por partes que espera
        // para confirmar tiene los suyos escritos y ha soltado la tabla. Se
        // borran solo los de procesos que ya no existen
        $this->barrerTemporalesMuertos($tabla);
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
        $definiciones = $this->indices ? $definiciones : [];
        $estado       = $this->estado($tabla);
        $revAntes     = $estado['rev'];
        $rev          = $revAntes + 1;
        $revsAntes    = array_slice(array_pad((array)($estado['parts'] ?? []), $partesAntes, $revAntes), 0, $partesAntes);
        $total        = max(1, (int)ceil($nFilas / $this->filasPorParte));
        $revsPartes   = array_slice(array_pad($revsAntes, $total, $rev), 0, $total);
        $revsIndices  = [];
        $filas        = null;                           // todas las filas, si algún índice las pide

        // Lo que se escriba ahora va a la caché con la etiqueta nueva de la
        // tabla (ver etiqueta()); lo de antes se borra con la de antes
        $creada = (int)($estado['creada'] ?? random_int(1, 2147483647));
        $this->estados[$tabla]['creada'] = $creada;

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

        // Los índices, trozo a trozo: uno por parte de la tabla. De cada
        // índice se reescriben solo los trozos que cambian y se anota en qué
        // revisión quedó cada uno; los demás siguen valiendo tal cual
        $vigentes      = [];
        $rangosIndices = [];
        foreach ($definiciones as $def) {
            $vigentes[$def['name']] = true;
            [$revsIndices[$def['name']], $rangosIndices[$def['name']]] = $this->escribirIndice(
                $tabla, $def, $rev, $total, $nFilas, $desde, $cola, $sueltas, $viejas, $todas, $filas);
        }
        foreach ($indicesAntes as $nombre => $ficheros) {
            if (!isset($vigentes[$nombre])) {
                foreach ($ficheros as $fichero) {
                    $this->borrarFichero($fichero);
                }
            }
        }

        // La revisión y el estado de partes e índices: es lo que invalida la
        // caché. `creada` distingue esta tabla de otra que se llamó igual y se
        // borró: sus revisiones empiezan de cero y un resultado guardado en
        // APCu, que no se puede borrar por tabla, la confundiría con ella.
        $nuevo = ['rev' => $rev, 'chunk' => $this->filasPorParte, 'rows' => $nFilas, 'creada' => $creada,
                  'parts' => array_values($revsPartes), 'indexes' => (object)$revsIndices,
                  'rangos' => (object)$rangosIndices];
        $autoinc = $this->autoincPendiente[$tabla] ?? $estado['autoinc'] ?? null;
        if ($autoinc !== null) {
            $nuevo['autoinc'] = (int)$autoinc;
        }
        unset($this->autoincPendiente[$tabla]);
        if ($this->modoPartes !== null) {
            // Por partes, la revisión se escribe al confirmar, ya con el
            // exclusivo, juntando esto con lo que otros hayan hecho entretanto
            if ($meta !== null) {
                throw new ConflictoPartes();
            }
            $nuevo['indexes'] = $revsIndices;
            $nuevo['rangos']  = $rangosIndices;
            $this->pendientePartes[$tabla] = [
                'antes' => $estado, 'nuevo' => $nuevo, 'partesAntes' => $partesAntes,
                'meta' => (string)@md5_file($this->ficheroMeta($tabla)), 'indicesAntes' => array_keys($indicesAntes),
            ];
            unset($this->indicesMemo[$tabla]);
            return;
        }
        $this->escribirAtomico($this->ficheroRev($tabla), json_encode($nuevo, self::JSON_META) . "\n");
        $nuevo['indexes'] = $revsIndices;
        $nuevo['rangos']  = $rangosIndices;

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
        foreach ($indices as $ficheros) {
            foreach ($ficheros as $fichero) {
                $this->borrarFichero($fichero);
            }
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
    /**
     * Borra los temporales de una tabla que son de procesos que ya no existen:
     * el nombre lleva el pid de quien lo escribió. Si no se puede saber si ese
     * proceso vive (sin /proc ni posix), solo los de más de un minuto.
     */
    private function barrerTemporalesMuertos(string $tabla): void
    {
        $yo   = getmypid();
        $proc = is_dir('/proc/self');
        foreach ((array)glob($this->dir . '/' . $tabla . '.*.tmp') as $f) {
            $f = (string)$f;
            if (!preg_match('/\.(\d+)(?:\.[a-z]+)?\.tmp$/', $f, $m) || (int)$m[1] === $yo) {
                continue;
            }
            $pid = (int)$m[1];
            if ($proc) {
                $muerto = !is_dir("/proc/$pid");
            } elseif (function_exists('posix_kill')) {
                $muerto = !@posix_kill($pid, 0) && posix_get_last_error() === 3;   // ESRCH
            } else {
                $muerto = (time() - (int)@filemtime($f)) > 60;
            }
            if ($muerto) {
                @unlink($f);
            }
        }
    }

    private function barrerTemporales(?string $tabla = null): void
    {
        $patron = $tabla === null ? '/*.tmp' : '/' . $tabla . '.*.tmp';
        foreach ((array)glob($this->dir . $patron) as $f) {
            @unlink((string)$f);
        }
        if ($tabla === null) {
            foreach ((array)glob($this->dirTx . '/*.tmp') as $f) {
                @unlink((string)$f);                  // un manifiesto que no llegó a escribirse
            }
        }
    }

    /**
     * Cuántas filas dice el fichero de revisión que tiene la tabla, o null si
     * no lo dice (bases de antes de la 2.5). Es un dato de planificación, no
     * un recuento: para contar está contarFilas().
     */
    public function filasSegunRevision(string $tabla): ?int
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);
        $estado = $this->estado($tabla);
        return isset($estado['rows']) ? (int)$estado['rows'] : null;
    }

    /**
     * Cuántos ficheros de datos tiene ahora mismo una tabla. Dentro de un
     * bloqueo no cambia salvo que escriba este proceso, así que se recuerda:
     * una escritura de muchas filas pregunta por cada una.
     */
    public function partes(string $tabla): int
    {
        self::validarTabla($tabla);
        if (isset($this->partesMemo[$tabla])) {
            return $this->partesMemo[$tabla];
        }
        for ($parte = 1; ; $parte++) {
            if (!is_file($this->ficheroDatos($tabla, $parte))) {
                return $this->partesMemo[$tabla] = $parte - 1;
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
            $pos      = 0;
            $escribir = static function (string $texto) use ($fh, $fichero, &$pos): void {
                if (@fwrite($fh, $texto) !== strlen($texto)) {
                    throw JsonSqlDbError::io('Escritura incompleta de ' . basename($fichero));
                }
                $pos += strlen($texto);
            };
            $escribir("{\n  \"table\": " . json_encode($tabla, self::JSON_FILA) . ",\n  \"rows\": [");
            // Dónde empieza cada fila: con ello una lectura por clave lee
            // una línea del fichero en vez de decodificarlo entero (ver
            // filaEnParte()). Van al final, con la posición del propio
            // listado en la última línea para encontrarlo sin leer nada más.
            $desfases = [];
            $sep      = "\n    ";
            foreach ($filas as $fila) {
                $json = json_encode($fila, self::JSON_FILA);
                if ($json === false) {
                    throw JsonSqlDbError::io("No se puede codificar una fila de '$tabla' a JSON");
                }
                $desfases[] = $pos + strlen($sep);
                $escribir($sep . $json);
                $sep = ",\n    ";
            }
            $escribir($filas === [] ? "],\n" : "\n  ],\n");
            $aqui = $pos;
            $escribir('  "offsets": [' . implode(',', $desfases) . "],\n  \"offsets_at\": $aqui\n}\n");
        });
    }

    /**
     * Dónde empieza cada fila de una parte, leído de su final; null si la
     * parte es de antes de la 2.7 o está editada a mano y no lo trae. Se
     * recuerda en el proceso: una racha de búsquedas por clave cae muchas
     * veces en la misma parte.
     *
     * @return string|null los desfases empaquetados, cuatro bytes cada uno (pack 'N')
     */
    private function desfasesDeParte(string $tabla, int $parte, string $fichero): ?string
    {
        $clave = $this->prefijo . $this->etiqueta($tabla) . ':o' . $parte . ':' . (int)($this->estado($tabla)['parts'][$parte - 1] ?? $this->rev($tabla));
        if (isset(self::$memoProceso[$clave])) {
            return self::$memoProceso[$clave];
        }
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $tam = filesize($fichero);
            if ($tam === false || $tam < 40) {
                return null;
            }
            fseek($fh, -min(48, $tam), SEEK_END);
            if (!preg_match('/"offsets_at": (\d+)\s*\}\s*$/', (string)fread($fh, 48), $m)) {
                return null;
            }
            fseek($fh, (int)$m[1]);
            $linea = (string)fgets($fh);
            $ini   = strpos($linea, '[');
            $fin   = strrpos($linea, ']');
            if ($ini === false || $fin === false || $fin < $ini) {
                return null;
            }
            $lista = $ini + 1 === $fin ? [] : json_decode(substr($linea, $ini, $fin - $ini + 1), true);
            if (!is_array($lista)) {
                return null;
            }
        } finally {
            fclose($fh);
        }
        // En memoria van empaquetados, cuatro bytes por fila: un array de PHP
        // de mil enteros ocupa unas cinco veces más, y la lista se guarda en
        // la memoria del proceso para las búsquedas siguientes
        $lista = pack('N*', ...array_map('intval', $lista));
        $this->recordar($clave, $lista);
        return $lista;
    }

    /**
     * Una fila de una parte, leyendo solo su línea del fichero. Null si la
     * parte no trae desfases o la línea no es lo que debería (editada a
     * mano): entonces hay que decodificar la parte.
     */
    private function filaEnParte(string $tabla, int $parte, string $fichero, int $desfase): ?array
    {
        $desfases = $this->desfasesDeParte($tabla, $parte, $fichero);
        if ($desfases === null || $desfase < 0 || strlen($desfases) < 4 * ($desfase + 1)) {
            return null;
        }
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            return null;
        }
        fseek($fh, (int)unpack('N', $desfases, 4 * $desfase)[1]);
        $linea = rtrim((string)fgets($fh), ",\r\n");
        fclose($fh);
        if ($linea === '' || $linea[0] !== '{') {
            return null;
        }
        $fila = json_decode($linea, true);
        return is_array($fila) ? $fila : null;
    }

    /** Escribe un fichero entero dentro de la escritura abierta. */
    private function escribirAtomico(string $fichero, string $contenido, bool $sincronizar = true): void
    {
        $this->escribirTemporal($fichero, static function ($fh) use ($contenido, $fichero): void {
            if (@fwrite($fh, $contenido) !== strlen($contenido)) {
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
    private function escribirTemporal(string $fichero, callable $volcar, bool $sincronizar = true): void
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
        if (!$sincronizar) {
            $this->txRegenerables[$tmp] = true;
        }
    }

    /** Apunta un fichero para borrarlo al confirmar. */
    private function borrarFichero(string $fichero): void
    {
        $tmp = array_search($fichero, $this->txRenombrar, true);
        if ($tmp !== false) {
            @unlink((string)$tmp);
            unset($this->txRenombrar[$tmp], $this->txRegenerables[$tmp]);
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

    /**
     * Fichero de un trozo de índice: el primero va sin sufijo y los demás
     * con `.partN`, igual que las partes de datos. Un índice de antes de la
     * 2.6 es un solo fichero sin sufijo con todas las claves.
     */
    private function ficheroIndice(string $tabla, string $indice, int $parte = 1): string
    {
        return $this->dir . '/' . $tabla . '.idx.' . $indice . ($parte > 1 ? '.part' . $parte : '') . '.json';
    }

    /**
     * Índices que hay ahora mismo en disco: nombre => rutas de sus trozos.
     *
     * @return array<string, list<string>>
     */
    private function indicesEnDisco(string $tabla): array
    {
        $out    = [];
        $inicio = strlen($tabla) + 5;                 // '<tabla>.idx.'
        foreach ((array)glob($this->dir . '/' . $tabla . '.idx.*.json') as $f) {
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
     * @param callable():list<array> $todas
     * @param list<array>|null       $filas todas las filas, si ya se han leído
     * @param array<int,array>       $sueltas posición => fila nueva
     * @param array<int,array>       $viejas  posición => fila de antes
     * @return array{0: list<int>, 1: list<array|null>} revisión y rango de cada trozo (ver Indexes::rango())
     */
    private function escribirIndice(
        string $tabla,
        array $def,
        int $rev,
        int $total,
        int $nFilas,
        ?int $desde,
        ?array $cola,
        array $sueltas,
        array $viejas,
        callable $todas,
        ?array &$filas
    ): array {
        $nombre = $def['name'];
        $estado = $this->estado($tabla);
        $revs   = $estado['indexes'][$nombre] ?? null;
        $rangos = $estado['rangos'][$nombre] ?? [];
        $chunk  = $this->filasPorParte;

        // Qué trozos hay que tocar, y si los que se corrigen están al día.
        // Los que se rehacen enteros desde la cola no necesitan el de antes.
        $tocar = [];
        if ($desde !== null && is_array($revs) && count($revs) >= (int)ceil($desde / $chunk)) {
            foreach (array_keys($sueltas) as $pos) {
                if ($pos < $desde) {
                    $tocar[intdiv((int)$pos, $chunk)] = true;
                }
            }
            for ($p = intdiv($desde, $chunk); $p < $total; $p++) {
                $tocar[$p] = true;
            }
            // Los trozos que se corrigen se leen enteros; los que se dejan
            // como están solo se miran por encima —cabecera y cola, sin
            // decodificar—, para que un fichero dañado por un corte o editado
            // a mano no se quede así para siempre. Cien trozos cuestan un
            // milisegundo: se paga en cada escritura.
            for ($p = 0; $p < min($total, count($revs)); $p++) {
                $sano = isset($tocar[$p])
                    ? $p * $chunk >= $desde || $this->trozoIndice($tabla, $def, $p + 1) !== null
                    : $this->trozoSano($tabla, $def, $p + 1, (int)$revs[$p]);
                if (!$sano) {
                    $desde = null;
                    break;
                }
            }
        } else {
            $desde = null;
        }

        $salida = [];
        $tramos = [];
        if ($desde === null) {
            // Entero, desde las filas: se reparten por trozos según su posición
            $filas ??= $todas();
            $trozos = [];
            foreach (Indexes::construir($filas, $def['columns']) as $clave => $pos) {
                foreach (Indexes::posiciones($pos) as $p) {
                    $trozos[intdiv($p, $chunk)] ??= [];
                    Indexes::anotar($trozos[intdiv($p, $chunk)], $clave, $p);
                }
            }
            for ($p = 0; $p < $total; $p++) {
                $tramos[$p] = $this->escribirTrozo($tabla, $def, $p + 1, $rev, $trozos[$p] ?? []);
                $salida[$p] = $rev;
            }
        } else {
            for ($p = 0; $p < $total; $p++) {
                if (!isset($tocar[$p])) {
                    $salida[$p] = (int)$revs[$p];
                    $tramos[$p] = $rangos[$p] ?? null;
                    continue;
                }
                $inicio = $p * $chunk;
                $fin    = $inicio + $chunk;               // exclusivo
                $keys   = $inicio >= $desde ? [] : (array)$this->trozoIndice($tabla, $def, $p + 1);
                $cambio = $inicio >= $desde;
                foreach ($sueltas as $pos => $fila) {
                    if ($pos >= $inicio && $pos < $fin && $pos < $desde) {
                        $keys = Indexes::sustituir($keys, $def['columns'], $pos, $viejas[$pos], $fila, $cambio);
                    }
                }
                if ($fin > $desde) {
                    if ($inicio < $desde) {
                        $keys   = Indexes::recortar($keys, $desde);
                        $cambio = true;
                    }
                    // Las filas nuevas de este trozo: la cola empieza en $desde
                    $primera = max($inicio, $desde);
                    $ultima  = min($fin, $nFilas);
                    if ($primera < $ultima) {
                        $keys   = Indexes::ampliar($keys, array_slice($cola ?? [], $primera - $desde, $ultima - $primera), $def['columns'], $primera);
                        $cambio = true;
                    }
                }
                if ($cambio) {
                    $tramos[$p] = $this->escribirTrozo($tabla, $def, $p + 1, $rev, $keys);
                    $salida[$p] = $rev;
                } else {
                    $salida[$p] = (int)$revs[$p];
                    $tramos[$p] = $rangos[$p] ?? null;
                }
            }
        }
        // Trozos de sobra de cuando la tabla era más grande
        for ($p = $total + 1; is_file($this->ficheroIndice($tabla, $nombre, $p)); $p++) {
            $this->borrarFichero($this->ficheroIndice($tabla, $nombre, $p));
        }
        return [array_values($salida), array_values($tramos)];
    }

    /**
     * ¿Tiene el fichero de un trozo la cabecera que debería? Se leen sus
     * primeros bytes y se comprueban índice, columnas, parte y revisión, sin
     * decodificar las claves: cuesta lo mismo tenga mil o cien mil.
     */
    private function trozoSano(string $tabla, array $def, int $parte, int $rev): bool
    {
        $fh = @fopen($this->ficheroIndice($tabla, $def['name'], $parte), 'rb');
        if ($fh === false) {
            return false;
        }
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
        return strncmp($cabecera, '{' . $esperada . ',', strlen($esperada) + 2) === 0 && $cola === "}}\n";
    }

    /**
     * Escribe un trozo de índice y devuelve su rango numérico (ver Indexes::rango()).
     *
     * @param array<string, int|list<int>> $keys
     */
    private function escribirTrozo(string $tabla, array $def, int $parte, int $rev, array $keys): ?array
    {
        $idx = [
            'index'   => $def['name'],
            'table'   => $tabla,
            'columns' => $def['columns'],
            'part'    => $parte,
            'rev'     => $rev,
            'chunk'   => $this->filasPorParte,
            'keys'    => $keys,
        ];
        $this->escribirAtomico($this->ficheroIndice($tabla, $def['name'], $parte), json_encode($idx, self::JSON_FILA) . "\n", false);
        $this->txCache[] = [$this->claveCache($tabla, 'x' . $def['name'] . 'p' . $parte, $rev), $idx];
        return Indexes::rango($keys);
    }

    /**
     * Claves de un trozo de índice, o null si no sirve: el fichero de
     * revisión dice en qué revisión se escribió cada trozo y el trozo tiene
     * que decir la misma, y ser de estas columnas. Un trozo desfasado o
     * tocado a mano se ignora y la consulta recorre la tabla: más lento,
     * nunca equivocado.
     *
     * Dentro de un bloqueo se guarda lo leído: una escritura pregunta por el
     * mismo trozo varias veces.
     *
     * @return array<string, int|list<int>>|null
     */
    private function trozoIndice(string $tabla, array $def, int $parte): ?array
    {
        $nombre = $def['name'];
        if (isset($this->indicesMemo[$tabla][$nombre][$parte])) {
            return $this->indicesMemo[$tabla][$nombre][$parte];
        }
        $revs = $this->estado($tabla)['indexes'][$nombre] ?? null;
        if (!is_array($revs) || !isset($revs[$parte - 1])) {
            return null;
        }
        $rev   = (int)$revs[$parte - 1];
        $clave = $this->claveCache($tabla, 'x' . $nombre . 'p' . $parte, $rev);
        $idx   = $this->cacheLeer($clave);
        if ($idx === null) {
            $fichero = $this->ficheroIndice($tabla, $nombre, $parte);
            if (!is_file($fichero)) {
                return null;
            }
            Memoria::comprobarFichero($fichero);
            $idx = json_decode((string)file_get_contents($fichero), true);
            if (!is_array($idx) || !is_array($idx['keys'] ?? null) || (int)($idx['rev'] ?? -1) !== $rev
                || (int)($idx['part'] ?? 0) !== $parte) {
                return null;
            }
            $this->cacheGuardar($clave, $idx);
        }
        if (($idx['columns'] ?? null) !== $def['columns']) {
            return null;
        }
        return $this->indicesMemo[$tabla][$nombre][$parte] = $idx['keys'];
    }

    /**
     * Lo que hace falta saber de un índice para buscar en él sin abrir
     * trozos de más: cuántos trozos tiene, el rango numérico de cada uno (ver
     * Indexes::rango()) y el mínimo y máximo de todos, para descartar de un
     * golpe una clave fuera de ellos. Un índice de antes de la 2.6 —un solo
     * fichero con todas las claves— viene en `legado`. Null si el índice no
     * sirve. Se recuerda mientras dure el bloqueo: una escritura de muchas
     * filas pregunta por cada una.
     *
     * @return array{partes: int, rangos: list<array|null>, min: int|float|null, max: int|float|null, legado: ?array}|null
     */
    private function planIndice(string $tabla, array $def): ?array
    {
        $nombre = $def['name'];
        if (isset($this->indicesMemo[$tabla][$nombre]['plan'])) {
            return $this->indicesMemo[$tabla][$nombre]['plan'];
        }
        $estado = $this->estado($tabla);
        $revs   = $estado['indexes'][$nombre] ?? $estado['rev'];
        if (!is_array($revs)) {
            $legado = $this->indiceLegado($tabla, $def, (int)$revs);
            if ($legado === null) {
                return null;
            }
            $plan = ['partes' => 0, 'rangos' => [], 'min' => null, 'max' => null, 'legado' => $legado];
        } else {
            $partes = max(1, $this->partes($tabla));
            if (count($revs) < $partes) {
                return null;
            }
            $rangos = array_slice(array_pad((array)($estado['rangos'][$nombre] ?? []), $partes, null), 0, $partes);
            $min = $max = null;
            foreach ($rangos as $r) {
                if ($r === null) {
                    $min = $max = null;                 // un trozo sin acotar: no hay mínimo ni máximo global
                    break;
                }
                if ($r !== []) {
                    if ($min === null || $r[0] < $min) { $min = $r[0]; }
                    if ($max === null || $r[1] > $max) { $max = $r[1]; }
                }
            }
            $plan = ['partes' => $partes, 'rangos' => $rangos, 'min' => $min, 'max' => $max, 'legado' => null];
        }
        return $this->indicesMemo[$tabla][$nombre]['plan'] = $plan;
    }

    /**
     * Los trozos de un índice en los que pueden estar unas claves, o null si
     * el índice no sirve. Con el rango numérico de cada trozo se saltan los
     * que no pueden contener ninguna: en una clave primaria autoincremental
     * es abrir un trozo en vez de todos.
     *
     * @param list<string> $claves  claves buscadas (o sus prefijos)
     * @return list<array<string, int|list<int>>>|null
     */
    private function trozosParaClaves(string $tabla, array $def, array $claves): ?array
    {
        $plan = $this->planIndice($tabla, $def);
        if ($plan === null) {
            return null;
        }
        if ($plan['legado'] !== null) {
            return [$plan['legado']];
        }
        $valores = array_map([Indexes::class, 'valorNumerico'], $claves);
        $trozos  = [];
        for ($p = 1; $p <= $plan['partes']; $p++) {
            if (!Indexes::cabeEnRango($valores, $plan['rangos'][$p - 1])) {
                continue;
            }
            $keys = $this->trozoIndice($tabla, $def, $p);
            if ($keys === null) {
                return null;
            }
            $trozos[] = $keys;
        }
        return $trozos;
    }

    /**
     * Un índice de antes de la 2.6: un solo fichero cuya revisión tiene que
     * ser la anotada (o la de la tabla, si el fichero de revisión es anterior
     * a la 2.5).
     *
     * @return array<string, int|list<int>>|null
     */
    private function indiceLegado(string $tabla, array $def, int $rev): ?array
    {
        $nombre = $def['name'];
        if (isset($this->indicesMemo[$tabla][$nombre][0])) {
            return $this->indicesMemo[$tabla][$nombre][0];
        }
        $fichero = $this->ficheroIndice($tabla, $nombre);
        if (!is_file($fichero)) {
            return null;
        }
        Memoria::comprobarFichero($fichero);
        $idx = json_decode((string)file_get_contents($fichero), true);
        if (!is_array($idx) || !is_array($idx['keys'] ?? null) || (int)($idx['rev'] ?? -1) !== $rev
            || isset($idx['part']) || ($idx['columns'] ?? null) !== $def['columns']) {
            return null;
        }
        return $this->indicesMemo[$tabla][$nombre][0] = $idx['keys'];
    }

    /**
     * Posiciones de las filas cuya clave de índice es una de las dadas (o
     * empieza por una, con $prefijo: un índice sobre (a, b) usado para
     * buscar solo por a; las claves llevan la longitud por delante, así que
     * el prefijo es inequívoco). Null si el índice no sirve.
     *
     * @param list<string> $claves
     * @return list<int>|null ordenadas
     */
    public function posicionesPorIndice(string $tabla, array $def, array $claves, bool $prefijo): ?array
    {
        self::validarTabla($tabla);
        if (!$this->indices) {
            return null;
        }
        $this->bloquearLectura($tabla);
        $trozos = $this->trozosParaClaves($tabla, $def, $claves);
        if ($trozos === null) {
            return null;
        }
        $posiciones = [];
        foreach ($trozos as $keys) {
            if ($prefijo) {
                foreach ($keys as $k => $lista) {
                    foreach ($claves as $c) {
                        if (strncmp((string)$k, $c, strlen($c)) === 0) {
                            foreach (Indexes::posiciones($lista) as $p) { $posiciones[$p] = true; }
                            break;
                        }
                    }
                }
            } else {
                foreach ($claves as $c) {
                    if (isset($keys[$c])) {
                        foreach (Indexes::posiciones($keys[$c]) as $p) { $posiciones[$p] = true; }
                    }
                }
            }
        }
        $posiciones = array_keys($posiciones);
        sort($posiciones);
        return $posiciones;
    }

    /**
     * ¿Hay alguna fila con esta clave de índice? Null si el índice no sirve.
     * Recorre los trozos y para en cuanto la encuentra.
     */
    public function claveEnIndice(string $tabla, array $def, string $clave): ?bool
    {
        self::validarTabla($tabla);
        if (!$this->indices) {
            return null;
        }
        $this->bloquearLectura($tabla);
        $plan = $this->planIndice($tabla, $def);
        if ($plan === null) {
            return null;
        }
        if ($plan['legado'] !== null) {
            return isset($plan['legado'][$clave]);
        }
        // Una clave numérica fuera del mínimo y el máximo de todo el índice —un
        // id nuevo en una tabla con clave autoincremental— no está en ningún
        // trozo, y se sabe sin mirar ninguno
        $v = Indexes::valorNumerico($clave);
        if ($v !== null && $plan['min'] !== null && ($v < $plan['min'] || $v > $plan['max'])) {
            return false;
        }
        if ($v === null) {
            // Una clave de texto puede estar en cualquier trozo: se miran todos,
            // que quedan cargados en una lista para la siguiente pregunta
            $todos = $this->indicesMemo[$tabla][$def['name']]['*'] ?? null;
            if ($todos === null) {
                $todos = [];
                for ($p = 1; $p <= $plan['partes']; $p++) {
                    $keys = $this->trozoIndice($tabla, $def, $p);
                    if ($keys === null) {
                        return null;
                    }
                    $todos[] = $keys;
                }
                $this->indicesMemo[$tabla][$def['name']]['*'] = $todos;
            }
            foreach ($todos as $keys) {
                if (isset($keys[$clave])) {
                    return true;
                }
            }
            return false;
        }
        for ($p = 1; $p <= $plan['partes']; $p++) {
            $r = $plan['rangos'][$p - 1];
            if ($r !== null && ($r === [] || $v < $r[0] || $v > $r[1])) {
                continue;
            }
            $keys = $this->trozoIndice($tabla, $def, $p);
            if ($keys === null) {
                return null;
            }
            if (isset($keys[$clave])) {
                return true;
            }
        }
        return false;
    }

    /** ¿Está el índice al día en todos sus trozos? */
    public function indiceValido(string $tabla, array $def): bool
    {
        return $this->claveEnIndice($tabla, $def, "\0") !== null;
    }

    /**
     * Filas de las partes cuyo trozo de índice puede contener algún valor de
     * un rango numérico [min, max] (un extremo null es abierto): con una
     * clave autoincremental, o cualquier columna que crezca con el tiempo,
     * un `BETWEEN` lee unas pocas partes en vez de la tabla. Salen todas las
     * filas de esas partes; el WHERE hace el resto. Null si el índice no
     * sirve, no tiene rangos, o habría que leer más de la mitad de la tabla.
     *
     * @param int|float|null $min
     * @param int|float|null $max
     * @return \Generator<int, array>|null
     */
    public function filasPorRango(string $tabla, array $def, $min, $max): ?\Generator
    {
        self::validarTabla($tabla);
        if (!$this->indices) {
            return null;
        }
        $this->bloquearLectura($tabla);
        $plan = $this->planIndice($tabla, $def);
        if ($plan === null || $plan['legado'] !== null) {
            return null;
        }
        $partes = [];
        foreach ($plan['rangos'] as $i => $r) {
            if ($r === null) {
                return null;                          // un trozo sin acotar: no se sabe qué partes saltar
            }
            if ($r !== [] && ($min === null || $r[1] >= $min) && ($max === null || $r[0] <= $max)) {
                $partes[] = $i + 1;
            }
        }
        if (count($partes) * 2 > $plan['partes']) {
            return null;
        }
        return (function () use ($tabla, $partes) {
            foreach ($partes as $parte) {
                $fichero = $this->ficheroDatos($tabla, $parte);
                if (!is_file($fichero)) {
                    return;
                }
                foreach ($this->parte($tabla, $parte, $fichero) as $fila) {
                    Memoria::comprobar('la lectura por rango');
                    yield $fila;
                }
            }
        })();
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
        $posiciones = $this->posicionesPorIndice($tabla, $def, $claves, $prefijo);
        if ($posiciones === null) {
            return null;
        }
        $chunk = max(1, (int)($this->estado($tabla)['chunk'] ?? $this->filasPorParte));
        $todas = max(1, $this->partes($tabla));

        $necesarias = [];
        foreach ($posiciones as $p) {
            $necesarias[intdiv($p, $chunk) + 1] = true;
        }
        // Leer más de la mitad de las partes no ahorra bastante respecto a
        // recorrer la tabla. Sin ninguna, se sale con la lista vacía sin abrir
        // un solo fichero.
        if ($necesarias !== [] && count($necesarias) * 2 > $todas) {
            return null;
        }
        $porParte = [];
        foreach ($posiciones as $p) {
            $porParte[intdiv($p, $chunk) + 1][] = $p - intdiv($p, $chunk) * $chunk;
        }

        $filas = [];
        foreach ($porParte as $parte => $desfases) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                return null;                          // índice y datos no cuadran
            }
            // Pocas filas de una parte: se leen sus líneas, sin decodificar las
            // mil. Muchas, o una parte que no trae desfases: se decodifica
            if (count($desfases) <= self::FILAS_POR_LINEA) {
                $sueltas = [];
                foreach ($desfases as $d) {
                    $fila = $this->filaEnParte($tabla, $parte, $fichero, $d);
                    if ($fila === null) {
                        $sueltas = null;
                        break;
                    }
                    $sueltas[] = $fila;
                }
                if ($sueltas !== null) {
                    foreach ($sueltas as $fila) {
                        $filas[] = $fila;
                    }
                    continue;
                }
            }
            $buscadas = array_fill_keys($desfases, true);
            foreach ($this->parte($tabla, $parte, $fichero) as $desfase => $fila) {
                if (isset($buscadas[$desfase])) {
                    $filas[] = $fila;
                    Memoria::comprobar('la lectura por índice');
                }
            }
        }
        return $filas;
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
     *   indexes  revisión en que se escribió cada trozo de cada índice (2.5; por trozos desde la 2.6)
     *   rangos   mínimo y máximo numérico de cada trozo de cada índice (desde la 2.7)
     *   autoinc  siguiente valor de autoincremento (desde la 2.7; antes en meta.json)
     *   creada   número aleatorio fijo desde la primera escritura (desde la 2.6)
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
        $estado['rangos']  = (array)($estado['rangos'] ?? []);
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

    /**
     * Clave de caché de un resultado de consulta: la SQL con sus parámetros y
     * la revisión de cada tabla implicada. Cambiar cualquiera de ellas es otra
     * clave, así que un resultado guardado nunca sobrevive a una escritura.
     *
     * @param list<string> $tablas
     */
    public function claveResultado(array $tablas, string $sql, array $params): string
    {
        $revs = '';
        foreach ($tablas as $t) {
            self::validarTabla($t);
            $this->bloquearLectura($t);
            $estado = $this->estado($t);
            $revs  .= $t . ':' . (int)($estado['creada'] ?? 0) . ':' . $estado['rev'] . ';';
        }
        return $this->prefijo . '_q:r:' . md5($sql . "\0" . serialize($params) . "\0" . $revs);
    }

    /** @return list<array>|null */
    public function resultadoCacheado(string $clave): ?array
    {
        $v = $this->cacheLeer($clave);
        return is_array($v) ? $v : null;
    }

    /**
     * Guarda un resultado. En disco el nombre del fichero dice de qué tablas
     * depende, para que la siguiente escritura en cualquiera de ellas lo
     * tire. En APCu no se puede buscar por nombre: caduca solo.
     *
     * @param list<string> $tablas
     * @param list<array>  $filas
     */
    public function guardarResultado(string $clave, array $tablas, array $filas): void
    {
        if (!$this->cache || Memoria::apretado()) {
            return;
        }
        if ($this->apcu) {
            apcu_store($clave, $filas, 3600);
            return;
        }
        if (!$this->cacheDisco || !is_dir($this->dirCache) && !@mkdir($this->dirCache, 0775, true) && !is_dir($this->dirCache)) {
            return;
        }
        $nombre = 'q';
        foreach ($tablas as $t) {
            $nombre .= '.' . md5($this->prefijo . $t);
        }
        @file_put_contents($this->dirCache . '/' . $nombre . '.' . substr($clave, -32) . '.cache', serialize($filas));
    }

    /**
     * Etiqueta de una tabla en la caché: su nombre y el número `creada` de
     * su fichero de revisión. Una tabla borrada y creada de nuevo con el
     * mismo nombre empieza otra vez en la revisión 1, y sin la etiqueta sus
     * entradas se confundirían con las de la anterior, que en APCu no se
     * pueden borrar por tabla.
     */
    private function etiqueta(string $tabla): string
    {
        return $tabla . '@' . (int)($this->estado($tabla)['creada'] ?? 0);
    }

    private function claveCache(string $tabla, string $tipo, ?int $rev = null): string
    {
        return $this->prefijo . $this->etiqueta($tabla) . ':' . $tipo . ':' . ($rev ?? $this->rev($tabla));
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
        return $this->prefijo . $this->etiqueta($tabla) . ':p' . $parte . ':' . $rev;
    }

    private function cacheLeer(string $clave)
    {
        if (!$this->cache) {
            return null;
        }
        if (isset(self::$memoProceso[$clave])) {
            return self::$memoProceso[$clave];
        }
        if ($this->apcu) {
            $ok  = false;
            $val = apcu_fetch($clave, $ok);
            if (!$ok) {
                return null;
            }
        } else {
            $fichero = $this->ficheroCache($clave);
            if ($fichero === null || !is_file($fichero)) {
                return null;
            }
            Memoria::comprobarFichero($fichero);
            $val = @unserialize((string)file_get_contents($fichero), ['allowed_classes' => false]);
            if ($val === false) {
                return null;
            }
        }
        $this->recordar($clave, $val);
        return $val;
    }

    /**
     * Guarda en el propio proceso las últimas entradas de caché leídas o
     * escritas, para no volver a decodificarlas en la siguiente consulta: un
     * script que hace miles de búsquedas, o el panel, leen una y otra vez
     * los mismos trozos de índice y las mismas partes. La clave lleva la
     * revisión, así que si otro proceso escribe la entrada deja de pedirse
     * sola. Cabe poco a propósito, alrededor de un megabyte: trozos de
     * índice, listas de desfases y estructuras, que son pequeños. Las partes
     * no: son grandes y las búsquedas por clave ya no las necesitan (ver
     * filaEnParte()).
     */
    private function recordar(string $clave, $valor): void
    {
        [, , , $tipo] = explode(':', $clave);
        if ($tipo === 'r' || $tipo[0] === 'p') {
            return;                                 // resultados y partes: pueden ser grandes
        }
        if (Memoria::apretado()) {
            self::$memoProceso = [];                // si la memoria escasea, esto es lo primero que sobra
            self::$memoGrupos  = ['x' => [], 'o' => [], 'm' => []];
            return;
        }
        $grupo = $tipo[0] === 'x' ? 'x' : ($tipo[0] === 'o' ? 'o' : 'm');
        $tope  = ['x' => 4, 'o' => 16, 'm' => 16][$grupo];
        self::$memoProceso[$clave] = $valor;
        self::$memoGrupos[$grupo][$clave] = true;
        while (count(self::$memoGrupos[$grupo]) > $tope) {
            $vieja = array_key_first(self::$memoGrupos[$grupo]);
            unset(self::$memoGrupos[$grupo][$vieja], self::$memoProceso[$vieja]);
        }
    }

    private function cacheGuardar(string $clave, $valor): void
    {
        // Guardar no es gratis: serializar tiene el valor dos veces un instante.
        // Si ya se va justo, mejor sin caché que sin memoria.
        if (!$this->cache || Memoria::apretado()) {
            return;
        }
        $this->recordar($clave, $valor);
        if ($this->apcu) {
            apcu_store($clave, $valor);
            return;
        }
        if (!$this->cacheDisco) {
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
            $ra = $antes['indexes'][$n] ?? $revAntes;
            if (!is_array($ra)) {
                $sobran[] = 'x' . $n . ':' . (int)$ra;      // un índice de antes de la 2.6
                continue;
            }
            foreach ($ra as $i => $r) {
                if (($despues['indexes'][$n][$i] ?? null) === (int)$r) {
                    $conservar['x' . $n . 'p' . ($i + 1) . (int)$r] = true;
                } else {
                    $sobran[] = 'x' . $n . 'p' . ($i + 1) . ':' . (int)$r;
                }
            }
        }
        $etiqueta = $tabla . '@' . (int)($antes['creada'] ?? 0);
        if ($this->apcu) {
            foreach ($sobran as $s) {
                apcu_delete($this->prefijo . $etiqueta . ':' . $s);
            }
            return;
        }
        $md5 = md5($this->prefijo . $etiqueta);
        foreach ((array)glob($this->dirCache . '/' . $md5 . '.*.cache') as $f) {
            if (!isset($conservar[substr(basename((string)$f, '.cache'), strlen($md5) + 1)])) {
                @unlink((string)$f);
            }
        }
        foreach ((array)glob($this->dirCache . '/q.*' . md5($this->prefijo . $tabla) . '*.cache') as $f) {
            @unlink((string)$f);                      // los resultados que dependían de la tabla
        }
    }

    /** Fichero de una entrada de caché; null si es un resultado que no está. */
    private function ficheroCache(string $clave): ?string
    {
        // md5(prefijo+etiqueta) agrupa las entradas de una misma tabla para poder borrarlas juntas
        [, , $etiqueta, $tipo, $rev] = explode(':', $clave);
        if ($etiqueta === '_q') {
            $hay = glob($this->dirCache . '/q.*.' . $rev . '.cache');
            return $hay === false || $hay === [] ? null : (string)$hay[0];
        }
        return $this->dirCache . '/' . md5($this->prefijo . $etiqueta) . '.' . $tipo . $rev . '.cache';
    }
}
