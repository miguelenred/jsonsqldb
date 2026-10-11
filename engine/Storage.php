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
 *   <raiz>/<base>/.tx/<ámbito>.json   journal de una escritura en curso
 *   <raiz>/<base>/.lote/              originales de una transacción en curso (2.9)
 *   <raiz>/<base>/.orden/             tramos de un ORDER BY ordenado en disco (2.9)
 *   <raiz>/<base>/.reparados/         originales de lo reparado y filas apartadas (2.9)
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
 * de golpe guiados por un journal de rehacer (ver StorageEscritura::txConfirmar()).
 *
 * Lo que solo hacen las lecturas está en StorageLectura; lo que solo hacen
 * las escrituras, en StorageEscritura; las búsquedas por rangos, en
 * StorageRangos, y lo que se hace pocas veces, en StorageMantenimiento.
 *
 * https://miguelenred.es/jsonsqldb
 */
class Storage
{
    protected const JSON_FILA = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    protected const JSON_META = self::JSON_FILA | JSON_PRETTY_PRINT;

    /** @var list<array>|null cómo se leyó cada tabla, solo durante un EXPLAIN (ver SelectAvanzada::apuntar()) */
    public ?array $traza = null;

    /** Hasta cuántas filas de una parte se leen por líneas en vez de decodificarla entera (medido: 30 cuestan como decodificar una parte) */
    protected const FILAS_POR_LINEA = 30;
    /** Trozos de cada índice que se guardan en el proceso mientras dura el bloqueo (ver trozoIndice()) */
    private const TROZOS_EN_MEMORIA = 64;
    /** La huella del texto de una parte que deja una escritura (2.9, ver parte()): basta para notar una edición, y sale por muy poco al lado de escribir la parte */
    protected const HUELLA = 'crc32b';

    protected const RE_BASE  = '/^[A-Za-z0-9_-]{1,64}$/';
    protected const RE_TABLA = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
    /**
     * Nombres que no puede tener una tabla (2.9): sus ficheros serían los de la
     * base (_database.json, _views.json, el _revs.json de antes de la 2.0) o el
     * ámbito del journal de la base (.tx/_base.json). Antes se aceptaban, y
     * CREATE TABLE _database sustituía el fichero de la base. En minúsculas:
     * en Windows los nombres de fichero no distinguen mayúsculas.
     */
    private const RESERVADOS = ['_database' => true, '_views' => true, '_revs' => true, '_base' => true];

    protected string $dir;
    protected string $dirCache;
    protected string $dirTx;
    private string $base;

    /** @var array<string, resource> bloqueos de tabla abiertos, por nombre */
    protected array $locksTabla = [];
    /** @var resource|null */
    protected $lock = null;
    protected int  $lockNivel = 0;
    protected bool $lockExclusivo = false;

    /** @var array<string,array> estado de cada tabla (rev.json), leído dentro del bloqueo */
    protected array  $estados = [];
    /** @var array<string,array<string,array>> índices leídos dentro del bloqueo */
    protected array  $indicesMemo = [];
    /** Los trozos de índice que se lean no van a la caché (2.9): una escritura los lee para reescribirlos, y guardarlos era una décima parte de un DELETE de muchas filas */
    protected bool   $trozosSinCache = false;
    /** @var array<string,array<string,array<int,int>>> trozos de índice ya vistos sanos, con su revisión (trozoSano()) */
    protected array  $trozosSanos = [];
    /** Tamaño en bytes de cada trozo visto sano o escrito, por etiqueta, índice y trozo (2.9; ver StorageEscritura::veredicto()) */
    protected array  $tamTrozos = [];
    /** @var array<string,array<string,string>> índices ya comprobados enteros, con las revisiones de sus trozos entonces (indiceValido()) */
    protected array  $indicesSanos = [];
    /** @var array<string,int> partes de cada tabla, dentro del bloqueo */
    protected array  $partesMemo = [];
    /** @var array<string,array<string,int>> preguntas por claves de texto a cada índice, dentro del bloqueo */
    protected array  $preguntasTexto = [];
    /** @var array{0: string, 1: int, 2: ?array}|null la última parte leída a saltos: cuál, cuántas filas y, ya decodificada, sus filas (ver filasEnPosiciones()) */
    protected ?array $parteSuelta = null;
    /** @var array<string,int> siguiente autoincremento a anotar en rev.json en la escritura en curso */
    protected array  $autoincPendiente = [];
    /** @var array<string,mixed> últimas entradas de caché, compartidas por todo el proceso (ver recordar()) */
    protected static array $memoProceso = [];
    /** @var array<string, array{0: string, 1: array}> fichero de revisión => [sello, estado], de este proceso (ver estado()) */
    private static array $estadosProceso = [];
    /**
     * La transacción abierta (2.9, ver StorageMantenimiento::loteIniciar()): los ficheros cuyo
     * original se ha guardado en .lote/ y los que no existían.
     *
     * @var array{guardados: array<string, true>, nuevos: array<string, true>}|null
     */
    protected ?array $lote = null;
    /** El arreglo de una fila que REPAIR TABLES aparta (ver revisarFicheros() y Writer) */
    public const APARTADA = 'fila apartada: sigue en la copia de .reparados/';
    /** El arreglo de una fila apartada que sigue pendiente (CHECK TABLES) */
    public const PENDIENTE = 'apartada: corrígela y vuelve a meterla, o descártala con REPAIR TABLES FORGET';
    /**
     * Ficheros estropeados o editados a mano que se han leído (reparados en
     * memoria si hacía falta) y que hay que arreglar en disco al acabar (2.9,
     * ver Reparador y Database::repararPendientes()): tabla => [fichero =>
     * 'roto' | 'editado'].
     *
     * @var array<string, array<string, string>>
     */
    protected array $porReparar = [];
    /** @var array<string,array<string,true>> las claves de $memoProceso por grupo, en orden de llegada */
    private static array $memoGrupos = ['x' => [], 'o' => [], 'm' => []];
    /** @var array<string,int>|null _revs.json de versiones anteriores a la 2.0 */
    protected ?array $revsLegadas = null;
    protected bool   $cache;
    protected bool   $cacheDisco;
    protected bool   $apcu;
    /** ¿Se cargan las partes con OPcache? (ver cacheLeer()) */
    protected bool   $opcache;
    /** Partes pasadas a .php en esta petición: pocas, para que ninguna sea mucho más lenta */
    private static int $phpGenerados = 0;
    private const PHP_POR_PETICION = 4;
    /** Lo más que ocupa un resultado guardado en disco (ver guardarResultado()) */
    protected const RESULTADO_MAXIMO_DISCO = 1048576;
    protected string $prefijo;
    protected int    $filasPorParte;
    protected bool   $indices;

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
     *   txHuellas   lo que la escritura sabe de lo que deja, que también se
     *               guarda al confirmar y fuera del tope de trozos (2.9): de
     *               cada índice, el veredicto y los filtros de sus trozos. Se
     *               guardaban ya al escribir, y una escritura que no llegaba a
     *               confirmarse dejaba en la caché los de unas revisiones que
     *               otra iba a usar con otro contenido
     *   txPartes    la huella de cada parte reescrita (2.9, ver parte()), por
     *               tabla y revisión: entrada de caché => parte => [huella, fichero]
     */
    protected ?string $txAmbito    = null;
    /** Tabla que se está escribiendo por partes, con su bloqueo compartido (ver bloquearPartes()) */
    protected ?string $modoPartes  = null;
    /** @var array<string,array> lo que la escritura por partes tiene que confirmar, por tabla */
    protected array   $pendientePartes = [];
    protected bool    $txPropia    = false;   // la abrió StorageEscritura::guardarTabla() y la confirma ella
    protected string  $txOperacion = '';
    protected array   $txRenombrar = [];
    /** @var array<string,true> temporales que no se fuerzan a disco porque su contenido se puede rehacer */
    protected array   $txRegenerables = [];
    protected array   $txBorrar    = [];
    protected array   $txTablas    = [];
    protected array   $txCache     = [];
    protected array   $txHuellas   = [];
    protected array   $txPartes    = [];

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
        $this->apcu       = Config::cacheApcu() && function_exists('apcu_enabled') && apcu_enabled();
        $this->cacheDisco = Config::cacheActiva() === true;
        $this->cache      = $this->cacheDisco || (Config::cacheActiva() === 'apcu' && $this->apcu);
        // Con OPcache activo se usa también con APCu, que tiene que copiar cada
        // parte al sacarla (21 ms la tabla entera, frente a 0,12 ms). No con
        // 'apcu', que quiere decir no escribir caché en disco
        $this->opcache    = $this->cacheDisco && Config::cacheOpcache() && self::opcacheConSitio(false);
        // 'jsq2' (2.9): las entradas de las partes, la estructura y los índices
        // llevan el sello de su fichero (ver parte()). Con otro prefijo, cada
        // versión ve solo las suyas: volver a una anterior no lee entradas que
        // no entiende
        $this->prefijo  = 'jsq2:' . substr(md5($dir), 0, 12) . ':';
        $this->filasPorParte = Config::filasPorParte();
        $this->indices       = Config::indices();
    }

    public function indicesActivos(): bool { return $this->indices; }
    /** Carpeta de los temporales de una ordenación que no cabe en memoria (ver OrdenExterno). */
    public function carpetaOrden(): string { return $this->dir . '/.orden'; }
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

    /** Crea una base (ver StorageMantenimiento::crearBase(), que solo se carga para esto) */
    public static function crearBase(string $raiz, string $base): void
    {
        StorageMantenimiento::crearBase($raiz, $base);
    }

    /** Borra una base entera (ver StorageMantenimiento::borrarBase()) */
    public static function borrarBase(string $raiz, string $base): void
    {
        StorageMantenimiento::borrarBase($raiz, $base);
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
        $this->preguntasTexto = [];
        $this->parteSuelta   = null;
        $this->revsLegadas   = null;
        $this->trozosSinCache = false;

        try {
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
        } catch (\Throwable $e) {
            // Sin bloquear nada (2.9): antes el compartido de la base se quedaba
            // cogido para siempre, y las escrituras siguientes del proceso, hasta
            // el DDL, creían tener un exclusivo que no tenían
            $this->desbloquear();
            throw $e;
        }
    }

    /** Los temporales de la base y de su journal: con su exclusivo, todos son de procesos muertos (ver bloquear()) */
    private function barrerTemporales(): void
    {
        foreach ((array)glob($this->dir . '/*.tmp') as $f) {
            @unlink((string)$f);
        }
        foreach ((array)glob($this->dirTx . '/*.tmp') as $f) {
            @unlink((string)$f);                      // un manifiesto que no llegó a escribirse
        }
    }

    /**
     * Coge el compartido de una tabla que se va a leer, si no se tiene ya. En
     * escritura no se pide nada: o se tiene el exclusivo de la base, que cubre
     * todas, o el de esta tabla, y pedir además el compartido sobre otro
     * descriptor bloquearía al proceso consigo mismo.
     */
    protected function bloquearLectura(string $tabla): void
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
    protected function abrirLock(string $fichero, bool $exclusivo, string $queEs)
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
            StorageEscritura::txAbandonar($this);             // una escritura que no llegó a confirmarse
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
        $this->preguntasTexto = [];
        $this->parteSuelta   = null;
        $this->revsLegadas   = null;
        $this->trozosSinCache = false;
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
    // reconocen y se deshacen igual (ver StorageMantenimiento::deshacer()).
    // ------------------------------------------------------------------

    /**
     * ¿Quedó alguna operación a medias? Cuesta un stat y un listado de una
     * carpeta casi siempre vacía, una vez por bloqueo.
     */
    private function recuperar(bool $yaExclusivo): void
    {
        // Después del journal de cada escritura, la transacción que lo contenía
        if (!is_dir($this->dirTx)) {
            if ($this->lote === null && is_dir($this->dirLote())) {
                StorageMantenimiento::recuperarLote($this, $yaExclusivo);
            }
            return;
        }
        // Un journal de antes de la 2.0, con su estado; el de una tabla que se
        // llame «manifiesto» tiene el mismo nombre, y es de los de ahora (2.9:
        // se tomaba por uno viejo y se llevaba los de las demás tablas)
        if (is_file($this->dirTx . '/manifiesto.json')
            && (json_decode((string)@file_get_contents($this->dirTx . '/manifiesto.json'), true)['tipo'] ?? null) !== 'redo') {
            StorageMantenimiento::migrarJournalPlano($this);
        }

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
                StorageMantenimiento::recuperarBase($this, $yaExclusivo);
            } elseif (preg_match(self::RE_TABLA, (string)$ambito)) {
                StorageMantenimiento::recuperarTabla($this, (string)$ambito);
            }
        }
        if ($this->lote === null && is_dir($this->dirLote())) {
            StorageMantenimiento::recuperarLote($this, $yaExclusivo);
        }
    }

    // ------------------------------------------------------------------
    // Transacciones (2.9)
    //
    // Cada sentencia de la transacción escribe como siempre, y la siguiente
    // ve lo que hizo. Antes de que una escritura reemplace o borre un
    // fichero por primera vez en la transacción, su original se guarda en
    // .lote/ con un enlace duro (el motor nunca modifica un fichero en su
    // sitio: escribe otro y lo renombra encima, así que el enlace conserva
    // el contenido de antes sin copiarlo). Confirmar es borrar .lote/;
    // deshacer, devolver los originales y quitar lo nuevo. Si el proceso
    // muere a medias, se deshace al abrir la base. Nadie ve nada a medias:
    // la transacción tiene el bloqueo exclusivo de la base.
    // ------------------------------------------------------------------

    protected function dirLote(): string
    {
        return $this->dir . '/.lote';
    }

    // ------------------------------------------------------------------
    // Tablas
    // ------------------------------------------------------------------

    /** Nombres de las tablas existentes. */
    public function tablas(): array
    {
        // Recorriendo la carpeta, no con glob(): con las partes y los trozos de
        // índice de un millón de filas son miles de ficheros, y glob() tardaba
        // un tercio más en mirarlos (2.9)
        $out = [];
        $dh  = @opendir($this->dir);
        while ($dh !== false && ($f = readdir($dh)) !== false) {
            if (str_ends_with($f, '.meta.json') && preg_match(self::RE_TABLA, $n = substr($f, 0, -10))) {
                $out[] = $n;
            }
        }
        if ($dh !== false) {
            closedir($dh);
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
        if (isset(self::RESERVADOS[strtolower($tabla)])) {
            throw JsonSqlDbError::schema("'$tabla' es un nombre reservado: sus ficheros serían los de la base");
        }
    }

    /**
     * Estructura de una tabla. En $huella, lo que la identifica —su clave de
     * caché, que lleva la revisión de la tabla, y el sello de su fichero—: la
     * misma huella es la misma estructura, sin compararla (ver
     * Catalog::meta()). Null si se ha reparado al leerla.
     */
    public function leerMeta(string $tabla, ?string &$huella = null): array
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);
        $clave   = $this->claveCache($tabla, 'm');
        $fichero = $this->ficheroMeta($tabla);
        $sello   = self::sello($fichero);
        $v       = $this->cacheLeer($clave);
        $meta    = null;
        $previa  = null;
        $huella  = null;
        $reparada = false;
        if (is_array($v) && isset($v['s'], $v['m'])) {
            if ($v['s'] === $sello) {
                $meta = $v['m'];
            } else {
                $previa = $v['m'];                                // ¿editada a mano? se mira abajo (2.9)
            }
        }
        if ($meta === null) {
            if (!is_file($fichero)) {
                throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
            }
            $texto = (string)file_get_contents($fichero);
            $meta  = json_decode($texto, true);
            if (!is_array($meta)) {
                // Estropeada a mano (2.9): solo comas sobrantes o la llave final
                $meta = Reparador::objeto($texto);
                if ($meta === null || !is_array($meta['columns'] ?? null)) {
                    throw JsonSqlDbError::io("Estructura ilegible en '$tabla.meta.json': no se puede arreglar sola, "
                        . 'corrígela a mano (la estructura de una tabla no se puede adivinar)');
                }
                $this->apuntarReparacion($fichero);
                $reparada = true;
            } elseif ($previa !== null && $previa !== $meta) {
                $this->apuntarReparacion($fichero, true);         // otra estructura: editada a mano
            }
            $this->cacheGuardar($clave, ['s' => $sello, 'm' => $meta]);
        }
        // El contador de autoincremento vive en rev.json desde la 2.7 (ver
        // StorageEscritura::ponerAutoincremento()); el de meta.json es el de la creación o el
        // de una versión anterior. Vale el mayor: un contador nunca baja.
        if (isset($meta['autoincrement']['next'])) {
            $vivo = $this->estado($tabla)['autoinc'] ?? null;
            if ($vivo !== null && (int)$vivo > (int)$meta['autoincrement']['next']) {
                $meta['autoincrement']['next'] = (int)$vivo;
            }
        }
        if (!$reparada) {
            $huella = $clave . '|' . $sello;
        }
        return $meta;
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
        $texto = (string)@file_get_contents($f);
        $v = json_decode($texto, true);
        if (is_array($v)) {
            return $v;
        }
        // Estropeado a mano (2.9): lo seguro se arregla al leer, como la
        // estructura de una tabla. Si no, error: antes se daba por vacío, las
        // vistas desaparecían y la siguiente CREATE VIEW lo sobrescribía
        $v = Reparador::objeto($texto);
        if ($v === null) {
            throw JsonSqlDbError::io('Vistas ilegibles en _views.json: no se puede arreglar solo, corrígelo a mano');
        }
        return $v;
    }

    // ------------------------------------------------------------------
    // Lectura de filas
    // ------------------------------------------------------------------

    /**
     * Recorre las filas de una tabla parte a parte, sin tenerla entera en
     * memoria: lo que hay a la vez es una parte más lo que el llamante se
     * quede. Cada parte tiene su entrada de caché, ligada a la revisión en que
     * se escribió y al sello de su fichero, así que las que una escritura no
     * toca siguen cacheadas.
     *
     * $sinCache fuerza leer del disco: la comprobación de claves (CHECK KEYS)
     * necesita ver lo que hay de verdad en el fichero.
     *
     * @param list<list<string>> $agujas textos que tiene que contener una parte
     *        para poder tener alguna fila que interese: de cada lista, al menos
     *        uno. Una parte que no los tiene no se decodifica (ver parte())
     * @return \Generator<int, array>
     */
    public function filas(string $tabla, bool $sinCache = false, array $agujas = [], int $desdeParte = 1): \Generator
    {
        self::validarTabla($tabla);
        $this->bloquearLectura($tabla);
        // Cada fila con su posición (quien escribe la usa), con los huecos de
        // las filas borradas ocupando su sitio, pero sin darlos (2.9). Si el
        // estado dice el tamaño de parte, cada una empieza en (parte − 1) ×
        // ese tamaño, y una que se salta por no tener los textos buscados no
        // hay que contarla; si no lo dice (tablas de antes de la 2.2), se
        // cuenta seguido, como siempre. Los huecos se saltan siempre, también
        // si el estado no los dice (perdido o roto): antes llegaban a la
        // consulta como filas y la rompían
        $estado = $this->estado($tabla);
        $chunk  = (int)($estado['chunk'] ?? 0);
        $pos    = 0;
        $quedan = Memoria::cada();              // filas hasta mirar la memoria
        for ($parte = $desdeParte; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                $this->exigirParte($tabla, $parte);
                return;
            }
            if ($chunk > 0) {
                $pos = ($parte - 1) * $chunk;
            }
            foreach ($sinCache ? $this->decodificarParte($fichero) : $this->parte($tabla, $parte, $fichero, $agujas) as $fila) {
                if (--$quedan === 0) {
                    Memoria::revisar('la lectura de la tabla');
                    $quedan = Memoria::cada();
                }
                if ($fila !== null) {
                    yield $pos => $fila;
                }
                $pos++;
            }
        }
    }

    /**
     * Que no falta la parte $parte de una tabla cuando su estado dice que la
     * tiene (2.9): antes, una parte de en medio borrada a mano acortaba en
     * silencio cada recorrido y cada COUNT(*).
     */
    protected function exigirParte(string $tabla, int $parte): void
    {
        $n = $this->partesSegunEstado($tabla);
        if ($parte <= $n) {
            throw JsonSqlDbError::io("Falta " . basename($this->ficheroDatos($tabla, $parte)) . ": la tabla '$tabla' tiene $n partes. "
                . 'Restáuralo de una copia; sin él, sus filas no están');
        }
    }

    /**
     * Cuántas partes dice el estado que tiene una tabla: una revisión por
     * parte, pero no más de las que ocupan sus filas. La 2.8 dejaba en la
     * lista la revisión de la última parte cuando un DELETE la vaciaba y su
     * fichero se borraba.
     */
    protected function partesSegunEstado(string $tabla): int
    {
        $estado = $this->estado($tabla);
        $n      = count((array)($estado['parts'] ?? []));
        $chunk  = (int)($estado['chunk'] ?? 0);
        return isset($estado['rows']) && $chunk > 0 ? min($n, max(1, (int)ceil((int)$estado['rows'] / $chunk))) : $n;
    }

    /**
     * Filas de un fichero de datos, decodificado de una vez: para mil filas
     * es bastante más rápido que fila a fila, y el pico es una sola parte.
     *
     * @return list<array>
     */
    protected function decodificarParte(string $fichero, ?string $texto = null): array
    {
        Memoria::comprobarFichero($fichero);
        $texto ??= (string)file_get_contents($fichero);
        $json = json_decode($texto, true);
        if (is_array($json) && is_array($json['rows'] ?? null)) {
            // Válida, pero si sus desfases no cuadran con el texto la ha
            // editado alguien: los índices y los desfases ya no valen y la
            // tabla se reescribe al acabar (2.9). Cuesta casi nada al lado
            // del json_decode de arriba
            if (isset($json['offsets_at']) && (!is_array($json['offsets'] ?? null)
                || count($json['offsets']) !== count($json['rows'])
                || strpos($texto, '"offsets": [', (int)$json['offsets_at']) !== (int)$json['offsets_at'] + 2)) {
                $this->apuntarReparacion($fichero, true);
            }
            return array_values($json['rows']);
        }
        // Estropeada a mano (2.9): si se puede arreglar con seguridad, sus
        // filas reparadas valen para esta consulta y el fichero se repara al
        // acabar; si no, error con las líneas, sin tocar nada
        $r = Reparador::parte($texto);
        if ($r['dudosas'] !== []) {
            throw JsonSqlDbError::io(StorageMantenimiento::avisoDudosas(basename($fichero), $r['dudosas']));
        }
        $this->apuntarReparacion($fichero);
        return $r['filas'];
    }

    /**
     * Las tablas con ficheros que reparar en disco, y se olvidan. Solo cuando
     * no queda ningún bloqueo: reparar es escribir, con su propio exclusivo.
     *
     * @return array<string, array<string, string>> tabla => [fichero => 'roto' | 'editado']
     */
    public function porReparar(): array
    {
        if ($this->lockNivel > 0 || $this->porReparar === []) {
            return [];
        }
        $r = $this->porReparar;
        $this->porReparar = [];
        return $r;
    }

    /** Roto, o editado a mano y válido (entonces solo hay que reescribir la tabla) */
    protected function apuntarReparacion(string $fichero, bool $editado = false): void
    {
        $nombre = basename($fichero);
        $tabla  = (string)strstr($nombre, '.', true);
        if (!$editado || !isset($this->porReparar[$tabla][$nombre])) {
            $this->porReparar[$tabla][$nombre] = $editado ? 'editado' : 'roto';
        }
    }

    /**
     * Filas de una parte, pasando por su caché. La clave lleva la revisión en
     * que se escribió esa parte, que consta en el fichero de revisión de la
     * tabla, así que se invalida sola al reescribirla y no antes.
     *
     * @return list<array>
     */
    protected function parte(string $tabla, int $parte, string $fichero, array $agujas = []): array
    {
        // La entrada de la caché lleva el sello del fichero que la dio (2.9):
        // si ya no es el mismo, alguien lo ha editado a mano y vale el disco
        $clave  = $this->claveParte($tabla, $parte);
        $sello  = self::sello($fichero);
        $v      = $this->cacheLeer($clave);
        $previa = null;                     // lo que tenía la caché, si el fichero ya no es el mismo
        $huella = null;                     // o la huella de lo que escribió el motor, si no tenía las filas
        if (is_array($v) && isset($v['s'], $v['f'])) {
            if ($v['s'] === $sello) {
                return $v['f'];
            }
            $previa = $v['f'];
        } elseif ($v === null) {
            // La escritura que puso la parte deja su sello y la huella de su
            // texto (2.9, ver StorageEscritura::escribirTabla()): con otro
            // sello y otra huella, la han editado a mano, aunque cada línea
            // conserve su longitud y nadie la hubiera leído antes. Antes eso
            // solo se veía con sus filas en la caché, y el índice se quedaba
            // sin la edición
            $h = $this->cacheLeer($this->claveCache($tabla, 'h', $this->revParte($tabla, $parte)))[$parte] ?? null;
            if ($h !== null && $h[0] !== $sello) {
                $huella = $h[1];
            }
        }
        // Un WHERE col = 'texto' solo puede casar con filas que llevan ese
        // texto, tal cual, en el fichero: si la parte no lo tiene, ninguna de
        // sus filas sirve y no hace falta decodificarla. Buscar en el texto
        // (strpos) cuesta muy poco frente a json_decode(), que se lleva la
        // mayor parte del tiempo de recorrer una tabla. Se lee lo mismo del
        // disco: lo que se ahorra es CPU y memoria. Sin decodificar no se
        // guarda en la caché
        $texto = null;
        if ($agujas !== [] || $huella !== null) {
            Memoria::comprobarFichero($fichero);
            $texto = (string)file_get_contents($fichero);
            if ($huella !== null && hash(self::HUELLA, $texto) !== $huella) {
                $this->apuntarReparacion($fichero, true);
            }
            foreach ($agujas as $alguna) {
                $esta = false;
                foreach ($alguna as $aguja) {
                    if (strpos($texto, $aguja) !== false) {
                        $esta = true;
                        break;
                    }
                }
                if (!$esta) {
                    return [];
                }
            }
        }
        $filas = $this->decodificarParte($fichero, $texto);
        unset($texto);
        // Con otro sello y otras filas, alguien lo ha editado a mano. Con las
        // mismas, solo se ha copiado o tocado (una copia de seguridad
        // repuesta, un touch): basta con el sello nuevo
        if ($previa !== null && $previa !== $filas) {
            $this->apuntarReparacion($fichero, true);
        }
        // Lo leído de un fichero roto o editado a mano no va a la caché: con el
        // sello de ese fichero se daría por bueno, y si la reparación no
        // llegara a hacerse, nadie lo volvería a mirar
        if (!isset($this->porReparar[strstr(basename($fichero), '.', true)][basename($fichero)])) {
            $this->cacheGuardar($clave, ['s' => $sello, 'f' => $filas]);
        }
        return $filas;
    }

    /**
     * El sello de un fichero: tamaño, fecha e inodo (2.9). El motor escribe
     * siempre un fichero nuevo y lo pone en su sitio, así que si el sello
     * cambia sin una revisión nueva, el fichero se ha tocado a mano.
     */
    protected static function sello(string $fichero): string
    {
        clearstatcache(true, $fichero);
        $s = @stat($fichero);
        return $s === false ? '' : self::selloDe($s);
    }

    /** @param array<string, int> $s lo que da stat() o fstat() */
    protected static function selloDe(array $s): string
    {
        return $s['size'] . ':' . $s['mtime'] . ':' . $s['ino'];
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
            $quedan = Memoria::cada();              // filas hasta mirar la memoria (ver filas())
            foreach ($partes as $parte) {
                $fichero = $this->ficheroDatos($tabla, $parte);
                if (!is_file($fichero)) {
                    return;
                }
                foreach ($this->parte($tabla, $parte, $fichero) as $fila) {
                    if (--$quedan === 0) {
                        Memoria::revisar('la lectura por rango');
                        $quedan = Memoria::cada();
                    }
                    if ($fila !== null) {                   // un hueco (2.9)
                        yield $fila;
                    }
                }
            }
        })();
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

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
        // El estado de la tabla ya dice cuántas hay (una revisión por parte):
        // basta comprobar que está la última y no la siguiente, dos llamadas al
        // disco en vez de una por parte (2.9). Si no cuadra —una tabla de antes
        // de la 2.5, o ficheros tocados a mano—, se cuentan como siempre
        $n = count((array)($this->estado($tabla)['parts'] ?? []));
        if ($n > 0 && is_file($this->ficheroDatos($tabla, $n)) && !is_file($this->ficheroDatos($tabla, $n + 1))) {
            return $this->partesMemo[$tabla] = $n;
        }
        for ($parte = 1; ; $parte++) {
            if (!is_file($this->ficheroDatos($tabla, $parte))) {
                return $this->partesMemo[$tabla] = $parte - 1;
            }
        }
    }

    /**
     * Dónde empieza cada fila de una parte, leído de su final; null si la
     * parte es de antes de la 2.7 o está editada a mano y no lo trae. Se
     * recuerda en el proceso: una racha de búsquedas por clave cae muchas
     * veces en la misma parte.
     *
     * @return string|null los desfases empaquetados, cuatro bytes cada uno (pack 'N')
     */
    protected function desfasesDeParte(string $tabla, int $parte, string $fichero): ?string
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
     * Unas filas de una parte, leyendo solo sus líneas: un fopen para todas y
     * un salto a cada una, en orden. Antes se abría el fichero una vez por
     * fila, así que como mucho se leían 8 de una parte; con una sola apertura
     * leer 30 sigue costando menos que decodificar las mil de la parte, y el
     * disco ve menos aperturas y menos bytes. Null si la parte no trae
     * desfases o algo no cuadra (entonces se decodifica entera).
     *
     * @param list<int> $desfases posiciones dentro de la parte
     * @return array<int,array>|null desfase => fila
     */
    protected function filasEnParte(string $tabla, int $parte, string $fichero, array $desfases): ?array
    {
        $saltos = $this->desfasesDeParte($tabla, $parte, $fichero);
        if ($saltos === null) {
            return null;
        }
        sort($desfases);
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            return null;
        }
        $out = [];
        foreach ($desfases as $desfase) {
            if ($desfase < 0 || strlen($saltos) < 4 * ($desfase + 1)) {
                fclose($fh);
                return null;
            }
            fseek($fh, (int)unpack('N', $saltos, 4 * $desfase)[1]);
            $linea = rtrim((string)fgets($fh), ",\r\n");
            $fila = $linea !== '' && $linea[0] === '{' ? json_decode($linea, true) : null;
            if (!is_array($fila)) {
                fclose($fh);
                return null;
            }
            $out[$desfase] = $fila;
        }
        fclose($fh);
        return $out;
    }

    // ------------------------------------------------------------------
    // Índices
    // ------------------------------------------------------------------

    /**
     * Fichero de un trozo de índice: el primero va sin sufijo y los demás
     * con `.partN`, igual que las partes de datos. Un índice de antes de la
     * 2.6 es un solo fichero sin sufijo con todas las claves.
     */
    protected function ficheroIndice(string $tabla, string $indice, int $parte = 1): string
    {
        return $this->dir . '/' . $tabla . '.idx.' . $indice . ($parte > 1 ? '.part' . $parte : '') . '.json';
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
    protected function trozoIndice(string $tabla, array $def, int $parte): ?array
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
        $clave   = $this->claveCache($tabla, 'x' . $nombre . 'p' . $parte, $rev);
        $fichero = $this->ficheroIndice($tabla, $nombre, $parte);
        // Con el sello de su fichero, como las partes (2.9): un índice tocado a
        // mano se vuelve a leer, y si está roto se rehace
        $sello   = self::sello($fichero);
        $v       = $this->cacheLeer($clave);
        $idx     = is_array($v) && isset($v['s'], $v['i']) && $v['s'] === $sello ? $v['i'] : null;
        if ($idx === null) {
            if (!is_file($fichero)) {
                return null;
            }
            Memoria::comprobarFichero($fichero);
            $idx = json_decode((string)file_get_contents($fichero), true);
            if (!is_array($idx)) {
                $this->apuntarReparacion($fichero);       // estropeado a mano: se rehace al acabar (2.9)
                return null;
            }
            if (!is_array($idx['keys'] ?? null) || (int)($idx['rev'] ?? -1) !== $rev
                || (int)($idx['part'] ?? 0) !== $parte) {
                return null;
            }
            if (!$this->trozosSinCache) {
                $this->cacheGuardar($clave, ['s' => $sello, 'i' => $idx]);
            }
        }
        if (($idx['columns'] ?? null) !== $def['columns']) {
            return null;
        }
        // Hasta TROZOS_EN_MEMORIA por índice: una escritura que repasaba los
        // trozos de una tabla de un millón de filas los guardaba todos, 4,8 MB
        // por índice (2.9). Sale el más antiguo
        if (count($this->indicesMemo[$tabla][$nombre] ?? []) >= self::TROZOS_EN_MEMORIA) {
            foreach (array_keys($this->indicesMemo[$tabla][$nombre]) as $k) {
                if (is_int($k)) {
                    unset($this->indicesMemo[$tabla][$nombre][$k]);
                    break;
                }
            }
        }
        // Su cabecera (filtro, cubre, min y max; ver Indexes::resumenDeTexto()),
        // para volverla a escribir tal cual si el trozo solo pierde claves (2.9)
        if (isset($idx['filtro'])) {
            $this->indicesMemo[$tabla][$nombre]['resumen'][$parte] = array_intersect_key($idx, ['filtro' => 1, 'cubre' => 1, 'min' => 1, 'max' => 1]);
        }
        return $this->indicesMemo[$tabla][$nombre][$parte] = $idx['keys'];
    }

    /**
     * Lo que hace falta saber de un índice para buscar en él sin abrir
     * trozos de más: cuántos trozos tiene, el rango numérico de cada uno (ver
     * Indexes::rango()) y el mínimo y máximo de todos, para descartar de un
     * golpe una clave fuera de ellos. Si los trozos con valores van en orden
     * y sin solaparse (una clave autoincremental), `orden` los lista como
     * [mínimo, máximo, trozo], para dar con el de una clave por bisección (2.9).
     * Un índice de antes de la 2.6 —un solo
     * fichero con todas las claves— viene en `legado`. Null si el índice no
     * sirve. Se recuerda mientras dure el bloqueo: una escritura de muchas
     * filas pregunta por cada una.
     *
     * @return array{partes: int, rangos: list<array|null>, min: int|float|null, max: int|float|null, orden: list<array{0: int|float, 1: int|float, 2: int}>|null, legado: ?array}|null
     */
    protected function planIndice(string $tabla, array $def): ?array
    {
        $nombre = $def['name'];
        if (isset($this->indicesMemo[$tabla][$nombre]['plan'])) {
            return $this->indicesMemo[$tabla][$nombre]['plan'];
        }
        $estado = $this->estado($tabla);
        $revs   = $estado['indexes'][$nombre] ?? $estado['rev'];
        if (!is_array($revs)) {
            $legado = StorageMantenimiento::indiceLegado($this, $tabla, $def, (int)$revs);
            if ($legado === null) {
                return null;
            }
            $plan = ['partes' => 0, 'rangos' => [], 'min' => null, 'max' => null, 'orden' => null, 'legado' => $legado];
        } else {
            $partes = max(1, $this->partes($tabla));
            if (count($revs) < $partes) {
                return null;
            }
            $rangos = array_slice(array_pad((array)($estado['rangos'][$nombre] ?? []), $partes, null), 0, $partes);
            $min = $max = null;
            $orden = [];
            foreach ($rangos as $i => $r) {
                if ($r === null) {
                    $min = $max = $orden = null;        // un trozo sin acotar: no hay mínimo ni máximo global
                    break;
                }
                if ($r !== []) {
                    if ($orden !== null && $max !== null && $r[0] <= $max) {
                        $orden = null;                  // se solapa con uno de antes
                    }
                    if ($orden !== null) {
                        $orden[] = [$r[0], $r[1], $i + 1];
                    }
                    if ($min === null || $r[0] < $min) { $min = $r[0]; }
                    if ($max === null || $r[1] > $max) { $max = $r[1]; }
                }
            }
            $plan = ['partes' => $partes, 'rangos' => $rangos, 'min' => $min, 'max' => $max, 'orden' => $orden, 'legado' => null];
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
            if (!IndexesBusqueda::cabeEnRango($valores, $plan['rangos'][$p - 1])) {
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
     * Posiciones de las filas cuya clave de índice es una de las dadas (o
     * empieza por una, con $prefijo: un índice sobre (a, b) usado para
     * buscar solo por a; las claves llevan la longitud por delante, así que
     * el prefijo es inequívoco). Null si el índice no sirve.
     *
     * Con $tope, para en cuanto tiene esas posiciones: los trozos van en el
     * orden de la tabla, así que las que da son las primeras, y puede dar
     * alguna más del último trozo mirado (2.9).
     *
     * @param list<string> $claves
     * @return list<int>|null ordenadas
     */
    public function posicionesPorIndice(string $tabla, array $def, array $claves, bool $prefijo, ?int $tope = null): ?array
    {
        self::validarTabla($tabla);
        if (!$this->indices) {
            return null;
        }
        $this->bloquearLectura($tabla);
        // Con el índice entero ya en memoria (ver StorageEscritura::posicionesDeClave()),
        // de ahí (2.9): si no, cada búsqueda volvía a decodificar todos sus
        // trozos, porque solo se guardan 64 (ver trozoIndice())
        $todos = $prefijo ? null : $this->indicesMemo[$tabla][$def['name']]['*'] ?? null;
        if ($todos !== null) {
            $posiciones = [];
            foreach ($claves as $c) {
                foreach (Indexes::posiciones($todos[$c] ?? null) as $p) {
                    $posiciones[$p] = true;
                }
            }
            $posiciones = array_keys($posiciones);
            sort($posiciones);
            return $posiciones;
        }
        $trozos = $prefijo ? null : $this->trozosConClaves($tabla, $def, $claves);
        if ($trozos !== null) {
            $sinCuenta = null;
            $hallado = $trozos === [] ? [] : $this->clavesEnTexto($tabla, $def, $trozos, $claves, false, $sinCuenta, $tope);
            if ($hallado !== null) {
                $posiciones = array_keys($hallado);
                sort($posiciones);
                return $posiciones;
            }
        }
        $trozos = $this->trozosParaClaves($tabla, $def, $claves);
        if ($trozos === null) {
            return null;
        }
        $posiciones = [];
        foreach ($trozos as $keys) {
            if ($tope !== null && count($posiciones) >= $tope) {
                break;
            }
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
     * Las claves exactas que buscar en cada trozo, para buscarlas en su texto
     * sin decodificarlo (ver clavesEnTexto()), o null si el índice no se
     * puede leer así. Las de texto pueden estar en cualquiera; las numéricas,
     * solo en los trozos cuyo rango las contiene. Antes las numéricas
     * decodificaban cada trozo que tocaban y se quedaba en memoria: un IN de
     * 100 claves repartidas, 8,7 MB y más que recorrer.
     *
     * @param list<string> $claves
     * @return array<int, list<string>>|null trozo => claves
     */
    protected function trozosConClaves(string $tabla, array $def, array $claves): ?array
    {
        if ($claves === [] || isset($this->indicesMemo[$tabla][$def['name']]['*'])) {
            return null;
        }
        $plan = $this->planIndice($tabla, $def);
        if ($plan === null || $plan['legado'] !== null) {
            return null;
        }
        // Las numéricas, en cada trozo solo las que caben en su rango
        $valores = array_map([Indexes::class, 'valorNumerico'], $claves);
        $numericas = !in_array(null, $valores, true);
        $trozos = [];
        for ($p = 1; $p <= $plan['partes']; $p++) {
            if (!$numericas) {
                $trozos[$p] = $claves;
                continue;
            }
            foreach ($claves as $i => $c) {
                if (IndexesBusqueda::cabeEnRango([$valores[$i]], $plan['rangos'][$p - 1])) {
                    $trozos[$p][] = $c;
                }
            }
        }
        return $trozos;
    }

    /**
     * Busca claves en el texto de los trozos de un índice, sin decodificarlos:
     * cada clave está escrita en el fichero como `"clave":posición` o
     * `"clave":[posiciones]`, detrás de una coma o de la llave de apertura, y
     * un `"` dentro de una clave va escapado, así que no se puede confundir con
     * el principio de otra. Antes de buscar se comprueba la cabecera del trozo
     * (índice, columnas, parte, revisión) y su final, como StorageEscritura::trozoSano(); desde
     * la 2.9, un trozo cuyo filtro dice que una clave de texto no está en él
     * no se lee más allá de su cabecera.
     * Devuelve posición => true de lo encontrado (con $parar, en cuanto hay
     * una), o null si algún trozo no se puede usar así. Con $cuenta (2.9)
     * solo cuenta las posiciones, que suma ahí, y devuelve [].
     *
     * @param int|array<int,list<string>> $partes cuántos trozos (todos, con
     *        las mismas $claves) o, por trozo, qué claves buscar en él
     * @param list<string> $claves
     * @return array<int, true>|null
     */
    protected function clavesEnTexto(string $tabla, array $def, $partes, array $claves, bool $parar, ?int &$cuenta = null, ?int $tope = null): ?array
    {
        $revs    = $this->estado($tabla)['indexes'][$def['name']] ?? null;
        if (!is_array($revs)) {
            return null;
        }
        $comoAguja = static fn($c) => json_encode($c, self::JSON_FILA) . ':';
        $hallado = [];
        $nombre  = $def['name'];
        // Un trozo de antes de la 2.9 no tiene filtro: leer primero la cabecera
        // y luego el resto le cuesta más que leerlo de una vez. En cuanto
        // aparece uno así, los siguientes se leen enteros, como antes. Y lo
        // mismo si no se busca ninguna clave de texto: el filtro no las cubre
        $hayTexto    = false;
        $soloNumeros = $claves !== [];
        foreach ($claves as $c) {
            $hayTexto    = $hayTexto || ($c !== '' && $c[0] === 't');
            $soloNumeros = $soloNumeros && $c !== '' && $c[0] === 'n';
        }
        // Sin claves que un filtro pueda cubrir, ni se mira: exactamente como
        // en la 2.8. Las numéricas lo tienen en los trozos de rango ancho (ver
        // Indexes::filtraNumeros()), que se sabe por el estado sin abrirlos
        $deUnaVez    = !$hayTexto && !$soloNumeros;
        $estado      = $this->estado($tabla);
        $rangos      = $soloNumeros ? (array)($estado['rangos'][$nombre] ?? []) : [];
        $aprendidos  = 0;                             // filtros leídos de la cabecera de su trozo
        $chunk       = (int)($estado['chunk'] ?? $this->filasPorParte);
        $sinDescarte = 0;                             // trozos seguidos en que el filtro no descartó nada
        // range(1, 0) da [1, 0], no una lista vacía: sin trozos no se busca en ninguno
        $lista = is_array($partes) ? $partes : ($partes > 0 ? array_fill_keys(range(1, $partes), null) : []);
        foreach ($lista as $p => $deEste) {
            if ($tope !== null && count($hallado) >= $tope) {
                break;                                // ya hay las que se pedían (ver posicionesPorIndice())
            }
            $buscadas = $deEste ?? $claves;
            $util     = $hayTexto || ($soloNumeros && Indexes::filtraNumeros($rangos[$p - 1] ?? null, $chunk));
            // Con el filtro del trozo ya leído (en esta lectura), o guardado
            // por otra (ver cargarFiltros()), sin abrirlo
            if ($util && !$deUnaVez && !isset($this->indicesMemo[$tabla][$nombre]['filtros'][$p])) {
                $this->cargarFiltros($tabla, $nombre, $revs);
            }
            $filtro = $this->indicesMemo[$tabla][$nombre]['filtros'][$p] ?? null;
            if ($filtro !== null) {
                $buscadas = array_values(array_filter($buscadas, static fn($c) => Indexes::puedeEstar($filtro, $c)));
                if ($buscadas === []) {
                    continue;
                }
            }
            $fichero = $this->ficheroIndice($tabla, $nombre, $p);
            // Con el filtro ya conocido no hay que leer la cabecera antes: el
            // trozo se lee de una vez (en una lectura larga, como un script con
            // miles de búsquedas, todos acababan leyéndose en dos)
            $fh = $deUnaVez || !$util || $filtro !== null ? null : @fopen($fichero, 'rb');
            if ($fh === false) {
                return null;
            }
            // Un trozo pequeño (pocas claves distintas) se lee de una vez: la
            // cabecera sola no ahorraría nada
            $tam   = $fh === null ? 0 : (int)(fstat($fh)['size'] ?? 0);
            $texto = $fh === null ? (string)@file_get_contents($fichero) : (string)fread($fh, $tam <= 16384 ? max(1, $tam) : 4096);
            $cabecera = '{' . substr((string)json_encode([
                'index' => $nombre, 'table' => $tabla, 'columns' => $def['columns'],
                'part' => $p, 'rev' => (int)($revs[$p - 1] ?? -1),
            ], self::JSON_FILA), 1, -1) . ',';
            if (strncmp($texto, $cabecera, strlen($cabecera)) !== 0) {
                if ($fh !== null) {
                    fclose($fh);
                }
                return null;
            }
            // El filtro va antes que las claves (2.9; un trozo de antes no lo
            // tiene y se lee entero, como siempre)
            // Justo detrás de la cabecera va "chunk":N, y después, si lo tiene,
            // el filtro: se mira ahí y no buscándolo en todo el texto, que en un
            // trozo sin filtro recorría las miles de comas de sus claves
            $f = false;
            if ($filtro === null && $util && $fh !== null) {
                $coma = strpos($texto, ',', strlen($cabecera));
                $f = $coma !== false && substr_compare($texto, '"filtro":"', $coma + 1, 10) === 0 ? $coma : false;
            }
            $deUnaVez = $deUnaVez || ($util && $filtro === null && $f === false);
            if ($f !== false) {
                // Hasta la comilla que lo cierra y, detrás, lo que quepa de `,"cubre":"todo"`
                while ((($fin = strpos($texto, '"', $f + 11)) === false || strlen($texto) < $fin + 16) && $fh !== null && !feof($fh)) {
                    $texto .= (string)fread($fh, 8192);
                }
                $filtro = $fin === false ? null : Indexes::filtro([
                    'filtro' => substr($texto, $f + 11, $fin - $f - 11),
                    'cubre'  => substr_compare($texto, ',"cubre":"todo"', $fin + 1, 15) === 0 ? 'todo' : '',
                ]);
                if ($filtro !== null) {
                    $this->indicesMemo[$tabla][$nombre]['filtros'][$p] = $filtro;
                    $aprendidos++;
                    $antes    = count($buscadas);
                    $buscadas = array_values(array_filter($buscadas, static fn($c) => Indexes::puedeEstar($filtro, $c)));
                    if ($buscadas === []) {
                        $sinDescarte = 0;
                        if ($fh !== null) {
                            fclose($fh);
                        }
                        continue;
                    }
                    // Un valor que está en casi todos los trozos (una ciudad): el
                    // filtro no ahorra nada y leer en dos veces cuesta más. Tras
                    // dos trozos así, los siguientes se leen enteros, como antes
                    $sinDescarte = count($buscadas) === $antes ? $sinDescarte + 1 : 0;
                    $deUnaVez    = $deUnaVez || $sinDescarte >= 2;
                }
            }
            if ($fh !== null) {
                $texto .= (string)stream_get_contents($fh);
                fclose($fh);
            }
            if (substr($texto, -3) !== "}}\n") {
                return null;
            }
            $agujas = array_map($comoAguja, $buscadas);
            $desde = strpos($texto, '"keys":{', strlen($cabecera));
            if ($desde === false) {
                return null;
            }
            // Con el rango del trozo (si todas sus claves son números), dónde
            // caería cada una si van en orden (ver buscarClave())
            $rango = $this->estado($tabla)['rangos'][$nombre][$p - 1] ?? null;
            $ancho = is_array($rango) && count($rango) === 2 && $rango[1] > $rango[0] ? $rango[1] - $rango[0] : 0;
            foreach ($agujas as $j => $aguja) {
                $num = $ancho > 0 ? Indexes::valorNumerico($buscadas[$j]) : null;
                $i   = self::buscarClave($texto, $aguja, $desde, $num === null ? null : ($num - $rango[0]) / $ancho);
                if ($i === null) {
                    continue;
                }
                $v = $i + strlen($aguja);
                if ($texto[$v] === '[') {
                    $fin = (int)strpos($texto, ']', $v);
                    if ($cuenta !== null) {
                        $cuenta += $fin === $v + 1 ? 0 : substr_count($texto, ',', $v, $fin - $v) + 1;
                        continue;
                    }
                    foreach ((array)json_decode(substr($texto, $v, $fin - $v + 1), true) as $pos) {
                        $hallado[(int)$pos] = true;
                    }
                } elseif ($cuenta !== null) {
                    $cuenta++;
                } else {
                    $hallado[(int)substr($texto, $v, strspn($texto, '0123456789', $v))] = true;
                }
                if ($parar) {
                    break 2;
                }
            }
        }
        if ($aprendidos > 0) {
            $this->guardarFiltros($tabla, $nombre, $revs);
        }
        return $hallado;
    }

    /**
     * Los filtros de los trozos de un índice (ver Indexes::resumenDeTexto()),
     * guardados en la caché por una lectura o una escritura anterior (2.9):
     * trozo => filtro, con la revisión de cada uno. Buscar un email en una
     * tabla de un millón de filas abría la cabecera de sus mil trozos (30 ms);
     * con los filtros a mano se sabe en cuáles puede estar sin abrir ninguno.
     * Los que no están en la misma revisión no se cargan: se leerán del trozo.
     *
     * @param list<int> $revs la revisión de cada trozo según el estado
     */
    protected function cargarFiltros(string $tabla, string $nombre, array $revs): void
    {
        if (isset($this->indicesMemo[$tabla][$nombre]['filtrosCargados'])) {
            return;
        }
        $this->indicesMemo[$tabla][$nombre]['filtrosCargados'] = true;
        $v = $this->cacheLeer($this->claveCache($tabla, 'f' . $nombre, 0));
        if (!is_array($v) || !is_array($v['r'] ?? null) || !is_array($v['f'] ?? null)) {
            return;
        }
        foreach ($v['f'] as $i => $filtro) {
            if (is_string($filtro) && ($v['r'][$i] ?? null) === (int)($revs[$i] ?? -1)) {
                $this->indicesMemo[$tabla][$nombre]['filtros'][$i + 1] ??= $filtro;
            }
        }
    }

    /**
     * Guarda en la caché los filtros de los trozos de un índice (ver
     * cargarFiltros()), si se conocen todos; con la revisión de cada uno.
     *
     * @param list<int> $revs
     */
    protected function guardarFiltros(string $tabla, string $nombre, array $revs, ?int $partes = null): void
    {
        $filtros = $this->indicesMemo[$tabla][$nombre]['filtros'] ?? [];
        $partes ??= max(1, $this->partes($tabla));
        if (count($filtros) < $partes || count($revs) < $partes) {
            return;
        }
        $f     = [];
        $algun = false;
        for ($p = 1; $p <= $partes; $p++) {
            if (!isset($filtros[$p])) {
                return;
            }
            $f[]   = $filtros[$p];
            $algun = $algun || $filtros[$p] !== '';
        }
        if (!$algun) {
            return;                                   // un índice de números acotados no tiene filtros: nada que guardar
        }
        $entrada = [$this->claveCache($tabla, 'f' . $nombre, 0), ['r' => array_map('intval', array_slice($revs, 0, $partes)), 'f' => $f]];
        // Con una escritura abierta, al confirmarla (2.9): los de los trozos que
        // escribe llevan una revisión que aún no está en el disco
        if ($this->txAmbito !== null) {
            $this->txHuellas[] = $entrada;
        } else {
            $this->cacheGuardar(...$entrada);
        }
    }

    /**
     * Dónde empieza una clave ("clave": con sus comillas) en el texto de un
     * trozo de índice, desde $desde; null si no está. Lo que no va detrás de
     * «{» o «,» está dentro de otra clave y no cuenta.
     *
     * $donde, de 0 a 1, es dónde cae el valor de una clave numérica en el
     * rango del trozo. Entonces se busca primero hacia atrás desde un poco
     * más allá de ese punto y luego hacia delante (2.9): en un trozo con las
     * claves en orden, como los de una clave primaria que crece, se encuentra
     * casi sin recorrerlo, donde antes se recorría de media la mitad (hasta
     * 7,5 µs en un trozo de mil claves). Si están desordenadas, cuesta de
     * media lo mismo que buscar desde el principio.
     */
    private static function buscarClave(string $texto, string $aguja, int $desde, ?float $donde): ?int
    {
        $largo = strlen($texto);
        $corte = $desde - 1;                          // desde dónde falta mirar hacia delante
        if ($donde !== null) {
            $tramo = $largo - $desde;
            $corte = max($desde - 1, min($largo - 1, $desde + (int)(max(0.0, min(1.0, $donde)) * $tramo) + 64 + ($tramo >> 5)));
            for ($x = $corte; $x >= $desde && ($i = strrpos($texto, $aguja, $x - $largo)) !== false && $i >= $desde; $x = $i - 1) {
                if ($texto[$i - 1] === '{' || $texto[$i - 1] === ',') {
                    return $i;
                }
            }
        }
        for ($i = $corte + 1; ($i = strpos($texto, $aguja, $i)) !== false; $i++) {
            if ($texto[$i - 1] === '{' || $texto[$i - 1] === ',') {
                return $i;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Ficheros
    // ------------------------------------------------------------------

    protected function ficheroMeta(string $tabla): string
    {
        return $this->dir . '/' . $tabla . '.meta.json';
    }

    protected function ficheroRev(string $tabla): string
    {
        return $this->dir . '/' . $tabla . '.rev.json';
    }

    protected function ficheroDatos(string $tabla, int $parte): string
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
    protected function estado(string $tabla): array
    {
        if (isset($this->estados[$tabla])) {
            return $this->estados[$tabla];
        }
        $fichero = $this->ficheroRev($tabla);
        // Ya decodificado (2.9): de una consulta anterior de este proceso, o
        // de OPcache o APCu. Leer y decodificar este fichero es lo que más pesa
        // al empezar cada consulta. Vale mientras tenga el mismo sello, y solo
        // si lleva más de 2 segundos sin cambiar: dos escrituras en el mismo
        // segundo no comparten sello
        $sello  = null;
        $compartida = $this->opcache || ($this->cache && $this->apcu);
        clearstatcache(true, $fichero);
        $st = @stat($fichero);
        if ($st !== false && $st['mtime'] < time() - 2) {
            $sello  = self::selloDe($st);
            $previo = self::$estadosProceso[$fichero] ?? null;
            if ($previo !== null && $previo[0] === $sello) {
                return $this->estados[$tabla] = $previo[1];
            }
            if ($compartida) {
                $php = $this->ficheroEstado($tabla);
                $v   = $this->opcache ? (is_file($php) ? include $php : null) : apcu_fetch($this->prefijo . 'e:' . $tabla);
                if (is_array($v) && ($v['s'] ?? null) === $sello) {
                    return $this->estados[$tabla] = $v['e'];
                }
            }
        }
        $texto   = is_file($fichero) ? (string)@file_get_contents($fichero) : null;
        $json    = $texto === null ? null : json_decode($texto, true);
        if ($texto !== null && !is_array($json)) {
            $sello = null;                          // lo arreglado no se guarda en la caché
            // Estropeado a mano (2.9). Antes se tomaba por inexistente, y las
            // revisiones volvían a empezar: la caché podía servir datos viejos.
            // Si no se arregla con seguridad, se rehace al acabar (es todo
            // cálculo sobre los datos) y mientras tanto la tabla lleva una
            // generación nueva, así que ninguna entrada de caché vieja vale
            $json = Reparador::objeto($texto) ?? ['gen' => random_int(1 << 30, PHP_INT_MAX)];
            $this->apuntarReparacion($fichero);
        }
        $estado  = is_array($json) ? ['rev' => (int)($json['rev'] ?? 0)] + $json : ['rev' => StorageMantenimiento::revLegada($this, $tabla)];
        $estado['indexes'] = (array)($estado['indexes'] ?? []);
        $estado['rangos']  = (array)($estado['rangos'] ?? []);
        if ($sello !== null) {
            // Unas pocas: las de las tablas que se están usando
            if (count(self::$estadosProceso) >= 32 || Memoria::apretado()) {
                self::$estadosProceso = [];
            }
            self::$estadosProceso[$fichero] = [$sello, $estado];
            if ($compartida) {
                $this->guardarEstado($tabla, $sello, $estado);
            }
        }
        return $this->estados[$tabla] = $estado;
    }

    /** Una entrada por tabla, con su sello: se sobrescribe y no se acumulan (ver estado()) */
    protected function ficheroEstado(string $tabla): string
    {
        return $this->dirCache . '/e.' . md5($this->prefijo . $tabla) . '.php';
    }

    private function guardarEstado(string $tabla, string $sello, array $estado): void
    {
        if (!$this->opcache) {
            if (self::apcuConSitio()) {
                apcu_store($this->prefijo . 'e:' . $tabla, ['s' => $sello, 'e' => $estado]);
            }
            return;
        }
        // Recién reescrito, OPcache puede servir aún el anterior un par de
        // segundos: entonces no se vuelve a escribir, se lee el fichero
        $php = $this->ficheroEstado($tabla);
        if ((!is_file($php) || (int)@filectime($php) < time() - 3) && self::opcacheConSitio(true)
            && (is_dir($this->dirCache) || @mkdir($this->dirCache, 0775, true) || is_dir($this->dirCache))) {
            self::volcarPhp($php, ['s' => $sello, 'e' => $estado]);
        }
    }

    protected function rev(string $tabla): int
    {
        return $this->estado($tabla)['rev'];
    }

    /**
     * La marca de que una tabla tiene resultados en disco (2.9): una escritura
     * en una tabla sin ella no los busca en la carpeta, que con muchos
     * ficheros era lo más lento de la escritura.
     */
    protected function marcaResultados(string $tabla): string
    {
        return $this->dirCache . '/q.' . md5($this->prefijo . $tabla) . '.hay';
    }

    /**
     * Etiqueta de una tabla en la caché: su nombre y el número `creada` de
     * su fichero de revisión. Una tabla borrada y creada de nuevo con el
     * mismo nombre empieza otra vez en la revisión 1, y sin la etiqueta sus
     * entradas se confundirían con las de la anterior, que en APCu no se
     * pueden borrar por tabla.
     */
    protected function etiqueta(string $tabla): string
    {
        return self::etiquetaDe($tabla, $this->estado($tabla));
    }

    /**
     * La etiqueta de las claves de caché de una tabla: su nombre, cuándo se
     * creó y (2.9) su generación, que sube al deshacer una transacción: así
     * ninguna clave de lo deshecho puede volver a usarse aunque las
     * revisiones de la tabla vuelvan a contar los mismos números.
     */
    protected static function etiquetaDe(string $tabla, array $estado): string
    {
        $gen = (int)($estado['gen'] ?? 0);
        return $tabla . '@' . (int)($estado['creada'] ?? 0) . ($gen > 0 ? '.' . $gen : '');
    }

    protected function claveCache(string $tabla, string $tipo, ?int $rev = null): string
    {
        return $this->prefijo . $this->etiqueta($tabla) . ':' . $tipo . ':' . ($rev ?? $this->rev($tabla));
    }

    /**
     * Clave de caché de una parte: lleva la revisión en que se escribió ESA
     * parte, no la de la tabla. Una base de antes de la 2.5 no anota las
     * partes; entonces vale la de la tabla, que sube en cada escritura.
     */
    protected function claveParte(string $tabla, int $parte, ?int $rev = null): string
    {
        return $this->prefijo . $this->etiqueta($tabla) . ':p' . $parte . ':' . ($rev ?? $this->revParte($tabla, $parte));
    }

    /** La revisión en que se escribió una parte (ver claveParte()) */
    protected function revParte(string $tabla, int $parte): int
    {
        $estado = $this->estado($tabla);
        return (int)($estado['parts'][$parte - 1] ?? $estado['rev']);
    }

    /** @param string|null $fichero el de la caché en disco, si quien llama ya lo sabe */
    protected function cacheLeer(string $clave, ?string $fichero = null)
    {
        if (!$this->cache) {
            return null;
        }
        if (isset(self::$memoProceso[$clave])) {
            return self::$memoProceso[$clave];
        }
        // Modo OPcache (2.9): una parte con su .php se carga de la memoria
        // compartida de OPcache, que dura entre peticiones, sin leer ni
        // reconstruir nada (la tabla entera, de unos 22 ms a 0,15 ms por visita)
        $php = $this->opcache && explode(':', $clave)[3][0] === 'p' ? substr($this->ficheroCache($clave), 0, -6) . '.php' : null;
        if ($php !== null && is_file($php)) {
            $val = include $php;
            if (is_array($val)) {
                return $val;
            }
        }
        if ($this->apcu) {
            $ok  = false;
            $val = apcu_fetch($clave, $ok);
            if (!$ok) {
                return null;
            }
        } else {
            $fichero ??= $this->ficheroCache($clave);
            if (!is_file($fichero)) {
                return null;
            }
            Memoria::comprobarFichero($fichero);
            $val = @unserialize((string)file_get_contents($fichero), ['allowed_classes' => false]);
            if ($val === false) {
                return null;
            }
        }
        if ($php !== null && isset($val['f'])) {          // con sus filas: la huella sola que deja una escritura, no (2.9)
            $this->aPhp($clave, $php, $val);
        }
        $this->recordar($clave, $val);
        return $val;
    }

    /**
     * Pasa a .php la caché de una parte (modo OPcache, 2.9), al leerla: nunca
     * al escribir, que quedan como siempre. Solo si lleva más de 2 segundos
     * sin cambiar (antes OPcache no la guardaría y cada lectura la compilaría),
     * si a OPcache le queda sitio, y unas pocas por petición. El fichero solo
     * lleva datos (var_export) y su nombre sale de la clave interna; se
     * escribe aparte y se renombra de una vez, con la fecha en el pasado para
     * que OPcache lo guarde ya.
     */
    private function aPhp(string $clave, string $php, array $filas): void
    {
        // La edad, la del fichero de datos de esa parte: lo que de verdad cambia
        [, , $etiqueta, $tipo] = explode(':', $clave);
        $datos = $this->ficheroDatos(substr($etiqueta, 0, (int)strrpos($etiqueta, '@')), (int)substr($tipo, 1));
        if (self::$phpGenerados >= self::PHP_POR_PETICION || (int)@filemtime($datos) >= time() - 2 || !self::opcacheConSitio(true)
            || (!is_dir($this->dirCache) && !@mkdir($this->dirCache, 0775, true) && !is_dir($this->dirCache))) {
            return;
        }
        self::$phpGenerados++;
        self::volcarPhp($php, $filas);      // un .tmp que quedara lo borra el barrido de StorageEscritura::limpiarCache()
    }

    /**
     * Escribe un .php de la caché que solo lleva datos (var_export): aparte y
     * renombrado de una vez, con la fecha en el pasado para que OPcache lo
     * guarde ya. El temporal es de este proceso y de esta vez (2.9): con un
     * nombre fijo, dos procesos que rehacían el mismo .php se pisaban, uno
     * renombraba el temporal mientras el otro lo estaba escribiendo, y el .php
     * quedaba cortado (un ParseError al cargarlo) o vacío. Solo se pone en su
     * sitio entero, y si algo falla no se queda.
     */
    private static function volcarPhp(string $php, array $valor): void
    {
        $tmp   = $php . '.' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.tmp';
        $texto = '<?php return ' . var_export($valor, true) . ';';
        if (@file_put_contents($tmp, $texto) !== strlen($texto) || !@touch($tmp, time() - 10) || !@rename($tmp, $php)) {
            @unlink($tmp);
        }
    }

    /**
     * ¿Le queda a APCu al menos esa fracción de memoria libre? Si no, no se le
     * da nada más (2.9), como a OPcache (ver opcacheConSitio()): lleno, APCu se
     * vacía entero (con apc.ttl = 0, lo de serie), y una tabla más grande que
     * su memoria lo llenaba y lo vaciaba en cada recorrido. Con un millón de
     * filas y los 32 MB de serie, recorrerla tardaba 2,5 veces lo que sin caché,
     * y se llevaba por delante lo guardado de las demás tablas. Las partes, que
     * son lo grande, piden la mitad libre; lo demás, una cuarta parte: así una
     * tabla grande no deja sin sitio a los trozos de índice ni a los resultados.
     */
    protected static function apcuConSitio(float $libre = 0.25): bool
    {
        $m = @apcu_sma_info(true);
        return is_array($m) && $m['avail_mem'] >= $libre * $m['num_seg'] * $m['seg_size'];
    }

    /**
     * ¿Está OPcache activo en esta petición? Con $sitio, además, ¿le queda al
     * menos un 25 % de memoria y de claves libres? Si no, no se le da nada más:
     * así nunca se llena ni se reinicia por culpa de la base.
     */
    private static function opcacheConSitio(bool $sitio): bool
    {
        static $activo = null;
        if (!$sitio && $activo !== null) {
            return $activo;                            // una vez por proceso
        }
        $s = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $activo = is_array($s) && !empty($s['opcache_enabled']);
        if (!$activo || !$sitio) {
            return $activo;
        }
        $m = $s['memory_usage'];
        $e = $s['opcache_statistics'];
        return $m['free_memory'] * 4 >= $m['used_memory'] + $m['free_memory'] + $m['wasted_memory']
            && $e['num_cached_keys'] * 4 <= $e['max_cached_keys'] * 3;
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
     * filasEnParte()).
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
        // Listas de desfases, 64 (2.9): ocupan 4 KB con partes de mil filas,
        // y con 16 una consulta que tocaba más partes las echaba todas en cada
        // vuelta: un IN de cien claves repartidas tardaba el doble
        $tope  = ['x' => 4, 'o' => 64, 'm' => 16][$grupo];
        self::$memoProceso[$clave] = $valor;
        self::$memoGrupos[$grupo][$clave] = true;
        while (count(self::$memoGrupos[$grupo]) > $tope) {
            $vieja = array_key_first(self::$memoGrupos[$grupo]);
            unset(self::$memoGrupos[$grupo][$vieja], self::$memoProceso[$vieja]);
        }
    }

    protected function cacheGuardar(string $clave, $valor): void
    {
        // Guardar no es gratis: serializar tiene el valor dos veces un instante.
        // Si ya se va justo, mejor sin caché que sin memoria.
        if (!$this->cache || Memoria::apretado()) {
            return;
        }
        $this->recordar($clave, $valor);
        if ($this->apcu) {
            if (self::apcuConSitio(explode(':', $clave)[3][0] === 'p' ? 0.5 : 0.25)) {
                apcu_store($clave, $valor);
            }
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

    /** Fichero de una entrada de caché; null si es un resultado que no está. */
    protected function ficheroCache(string $clave): string
    {
        // md5(prefijo+etiqueta) agrupa las entradas de una misma tabla para poder
        // borrarlas juntas. Con «-» entre el tipo y la revisión (2.9): sin él, la
        // parte 1 en la revisión 13 y la 11 en la 3 eran el mismo fichero
        [, , $etiqueta, $tipo, $rev] = explode(':', $clave);
        return $this->dirCache . '/' . md5($this->prefijo . $etiqueta) . '.' . $tipo . '-' . $rev . '.cache';
    }
}
