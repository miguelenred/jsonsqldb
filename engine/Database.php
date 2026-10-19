<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Punto de entrada del motor.
 *
 *   $bd = new Database('mibase');
 *   $filas = $bd->consultar('SELECT * FROM usuarios WHERE edad > 18');
 *   $filas = $bd->consultar('SELECT * FROM usuarios WHERE ciudad = ?', ['Madrid']);
 *
 * Se encarga de: analizar la SQL, coger el bloqueo que corresponda
 * (compartido en lectura, exclusivo en escritura), ejecutar y registrar
 * la consulta en el log.
 *
 * https://miguelenred.es/jsonsqldb
 */
class Database
{
    protected Storage $st;
    protected Catalog $cat;
    /** Catalog::nombres(), a mano: pedirla en cada sentencia se notaba en un SELECT 1 (2.10) */
    private \Closure $nombres;
    /** ¿Se está dentro de transaccion()? */
    protected bool $enTransaccion = false;
    private string  $base;
    /** @var (callable(array):void)|null adónde van las filas de un SELECT en consultarPorFilas() */
    private $porFila = null;
    /** Filas de un SELECT que han salido por $porFila (null: no ha habido SELECT así) */
    private ?int $enviadas = null;

    public function __construct(string $base, ?string $raiz = null)
    {
        $this->base = $base;
        $this->st   = new Storage($raiz ?? Config::datos(), $base);
        $this->cat  = new Catalog($this->st);
        $this->nombres = $this->cat->nombres();
    }

    public function nombre(): string  { return $this->base; }
    public function catalogo(): Catalog { return $this->cat; }

    // ------------------------------------------------------------------
    // Gestión de bases de datos
    // ------------------------------------------------------------------

    public static function bases(?string $raiz = null): array
    {
        return Storage::bases($raiz ?? Config::datos());
    }

    public static function crear(string $base, ?string $raiz = null): void
    {
        Storage::crearBase($raiz ?? Config::datos(), $base);
    }

    public static function borrar(string $base, ?string $raiz = null): void
    {
        Storage::borrarBase($raiz ?? Config::datos(), $base);
    }

    // ------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------

    /**
     * Como consultar(), pero las filas de un SELECT que se pueda resolver
     * leyendo y proyectando (sin ORDER BY, DISTINCT ni agregados) no se
     * devuelven juntas: van a $porFila una a una según salen, y quien llama
     * decide qué hacer con ellas (la API las va escribiendo como JSON). Así el
     * resultado entero no está nunca en memoria como array, que ocupa varias
     * veces su JSON. Devuelve cuántas filas han salido así, o, si no ha salido
     * ninguna por ahí, lo mismo que consultar(): las filas de un SELECT que ya
     * estaban enteras (ordenadas, agrupadas o de la caché) o el resultado de
     * cualquier otra sentencia.
     *
     * @param callable(array):void $porFila
     * @return int|array
     */
    public function consultarPorFilas(string $sql, array $params, ?callable $autorizar, callable $porFila)
    {
        // $porFila es de la aplicación: con su serialize_precision, no con el
        // -1 con el que trabaja el motor (ver Config::precisionExacta())
        $suya = Config::precisionAjena();
        $this->porFila = function (array $fila) use ($porFila, $suya): void {
            $this->enviadas = ($this->enviadas ?? 0) + 1;
            if ($suya === null) {
                $porFila($fila);
                return;
            }
            ini_set('serialize_precision', $suya);
            try {
                $porFila($fila);
            } finally {
                ini_set('serialize_precision', '-1');
            }
        };
        $this->enviadas = null;
        try {
            $res = $this->consultar($sql, $params, $autorizar);
        } finally {
            $this->porFila  = null;
            $enviadas       = $this->enviadas;
            $this->enviadas = null;                       // que un consultar() posterior registre sus propias filas
        }
        return $enviadas ?? $res;
    }

    /** Lo que devuelve una escritura: sus filas si lleva RETURNING (2.8), o el resumen de siempre. */
    protected static function resultadoEscritura(array $res): array
    {
        // REPAIR TABLES añade las filas que ha apartado (2.9)
        return $res['devueltas'] ?? ['success' => true, 'filas' => $res['filas'], 'mensaje' => $res['mensaje']]
            + (isset($res['apartadas']) ? ['apartadas' => $res['apartadas']] : []);
    }

    /**
     * Una transacción (2.9): lo que haga $fn con esta base pasa entero o no
     * pasa nada. Dentro se puede consultar y escribir como siempre, y cada
     * sentencia ve lo que hicieron las anteriores; nadie más ve nada hasta el
     * final, porque se tiene el bloqueo exclusivo de la base. Si $fn lanza
     * una excepción, o falla una sentencia, todo vuelve a como estaba y la
     * excepción sigue su camino. Devuelve lo que devuelva $fn.
     *
     * Solo con conexión directa: por la API cada petición es independiente
     * (para eso está lote()). Si el proceso muere a medias, la transacción se
     * deshace al abrir la base.
     *
     * @param callable(self): mixed $fn
     * @return mixed
     */
    public function transaccion(callable $fn)
    {
        return DatabaseAvanzada::correrTransaccion($this, $fn);
    }

    /** Lo que la consulta dejó por reparar o por rehacer, si dejó algo (ver DatabaseAvanzada::reparar()) */
    protected function repararPendientes(): void
    {
        if ($this->st->hayPendientes()) {
            // También al acabar una transacción, fuera de consultar(): con
            // serialize_precision a -1 (ver Config::precisionExacta())
            $precision = Config::precisionExacta();
            try {
                DatabaseAvanzada::reparar($this, $this->st->porReparar());
            } finally {
                $precision === null || ini_set('serialize_precision', $precision);
            }
        }
    }

    /**
     * Varias sentencias en una transacción (2.9): todas o ninguna. Cada una,
     * un texto SQL o [sql, parámetros]. Devuelve la lista de resultados, uno
     * por sentencia, en orden; $autorizar se aplica a cada una, como en
     * consultar(). Lo hace DatabaseAvanzada.
     *
     * @param list<string|array{0: string, 1?: array}> $sentencias
     * @return list<mixed>
     */
    public function lote(array $sentencias, ?callable $autorizar = null): array
    {
        return DatabaseAvanzada::correrLote($this, $sentencias, $autorizar);
    }

    /** ¿Solo lee? CHECK KEYS, CHECK TABLES y CHECK INDEXES solo leen; REPAIR, escribe */
    protected static function soloLee(array $ast): bool
    {
        return \in_array($ast['k'], ['select', 'union', 'explain', 'check_keys', 'check_tables', 'check_indexes'], true)
            || strncmp($ast['k'], 'show_', 5) === 0;
    }

    /**
     * Ejecuta una sentencia SQL.
     *
     * SELECT devuelve la lista de filas.
     * INSERT, UPDATE y DELETE con RETURNING, las filas escritas.
     * El resto devuelve ['success'=>true, 'filas'=>n, 'mensaje'=>'...'].
     *
     * $params son los valores de los ? de la sentencia. Se insertan en el árbol
     * como literales, nunca en el texto SQL: no pueden alterar la consulta.
     *
     * $autorizar recibe el tipo de sentencia ('select', 'insert', 'create_table'...)
     * antes de ejecutarla y puede lanzar una excepción para denegarla.
     */
    public function consultar(string $sql, array $params = [], ?callable $autorizar = null)
    {
        self::exigirAcceso();

        $t0 = microtime(true);
        $op = 'DESCONOCIDA';

        Memoria::iniciar();
        // serialize_precision a -1 mientras trabaja, y al acabar, la que había
        // (ver Config::precisionExacta()); sin llamarla si ya es -1, lo de siempre
        $precision = ini_get('serialize_precision') === '-1' ? null : Config::precisionExacta();

        try {
            if ($this->enTransaccion) {
                StorageEscritura::exigirEntera($this->st);
            }
            $ast = Parser::analizar($sql, $params, $this->nombres);
            $op  = self::operacion($ast);
            // Lo que aquí no tiene efecto (ANALYZE, USE de esta base): ni bloquea
            if ($ast['k'] === 'nada' || $ast['k'] === 'use') {
                if ($ast['k'] === 'use' && $ast['base'] !== $this->base) {
                    throw JsonSqlDbError::syntax("USE no cambia de base: cada conexión, y cada petición de la API, va a una. "
                        . "Para '{$ast['base']}', ábrela con su nombre");
                }
                return self::resultadoEscritura(['filas' => 0, 'mensaje' => $ast['mensaje'] ?? "Ya estás en '{$this->base}'"]);
            }
            if (\in_array($ast['k'], ['show_databases', 'create_database', 'drop_database'], true)) {
                if ($this->enTransaccion && $ast['k'] !== 'show_databases') {
                    throw JsonSqlDbError::lock('Crear o borrar una base no se puede hacer dentro de una transacción');
                }
                return DatabaseAvanzada::correrGlobal($sql, $params, $autorizar, dirname($this->st->dir()));
            }
            if ($this->enTransaccion && $ast['k'] === 'repair_tables' && !isset($ast['olvidar'])) {
                // Lo que aparta se apunta fuera de la transacción, y deshacerla no lo quitaría (2.9)
                throw JsonSqlDbError::lock('REPAIR TABLES no se puede hacer dentro de una transacción');
            }
            if ($autorizar !== null) {
                // Permite comprobar permisos antes de ejecutar; es de la
                // aplicación, y va con su serialize_precision
                $precision === null || ini_set('serialize_precision', $precision);
                try {
                    $autorizar($ast['k']);
                } finally {
                    $precision === null || ini_set('serialize_precision', '-1');
                }
            }
            $escritura = !self::soloLee($ast);
            $apuntada = $escritura && \in_array($ast['k'], ['insert', 'update', 'delete'], true) && \is_string($ast['tabla'] ?? null);
            if ($apuntada) {
                StorageEscritura::escribiendo($this->st, $ast['tabla']);
            }

            // Solo se llena una de las dos, según $escritura. Se inicializan para
            // que una rama nueva mal escrita no dé un aviso de PHP y un log falso.
            $filas = [];
            $res   = ['filas' => 0, 'mensaje' => ''];

            // Decidir el alcance del bloqueo obliga a mirar la estructura, y eso
            // ocurre ANTES de tenerlo. Lo leído entonces puede quedar obsoleto en
            // cuanto otro proceso escriba, así que se olvida nada más bloquear:
            // sin esto, dos procesos podían reutilizar el mismo autoincremento.
            $tablas = $escritura ? $this->tablasAfectadas($ast) : null;

            // CREATE TABLE … AS SELECT escribe sus filas por tandas según salen
            // (2.9, ver WriterDdl::crearTablaDeConsulta()): en una transacción,
            // que la deja ser todo o nada
            if ($ast['k'] === 'create_table' && ($ast['def'] ?? null) === null && !isset($ast['como']) && !$this->enTransaccion) {
                $res = $this->transaccion(fn(): array => (new Writer($this->cat))->ejecutar($ast));
                if (Config::seRegistra($op, false)) {
                    Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
                }
                return self::resultadoEscritura($res);
            }

            // Un UPDATE o DELETE que solo depende de cada fila se escribe por
            // partes (ver StorageCambios::bloquearPartes()); si se cruza con otra
            // escritura en la misma parte, se repite con la tabla entera
            // Dentro de una transacción ya se tiene la base entera
            $porPartes = $escritura && !$this->enTransaccion ? $this->tablaPorPartes($ast) : null;
            if ($porPartes !== null) {
                try {
                    StorageCambios::bloquearPartes($this->st, $porPartes);
                    $this->cat->olvidar();
                    try {
                        // Lo decidido antes de bloquear puede haber cambiado
                        if ($this->tablaPorPartes($ast) !== $porPartes) {
                            throw new ConflictoPartes();
                        }
                        $res = (new Writer($this->cat))->ejecutar($ast);
                    } finally {
                        $this->st->desbloquear();
                        $this->cat->olvidar();
                    }
                    if (Config::seRegistra($op, false)) {
                        Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
                    }
                    return self::resultadoEscritura($res);
                } catch (ConflictoPartes) {
                    StorageCambios::anotarRepeticion();          // a la cola: con la tabla entera, abajo
                } catch (SentenciaGrande) {
                    $res = DatabaseAvanzada::porTramos($this, $ast);
                    if (Config::seRegistra($op, false)) {
                        Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
                    }
                    return self::resultadoEscritura($res);
                }
            }

            $this->st->bloquear($escritura, $tablas);
            $this->cat->olvidar();
            $grande = false;
            try {
                if ($escritura) {
                    try {
                        $res = (new Writer($this->cat))->ejecutar($ast);
                    } catch (SentenciaGrande) {
                        $grande = true;                // aún no ha escrito nada: en una transacción, abajo
                    }
                } elseif ($ast['k'] === 'select' || $ast['k'] === 'union') {
                    $filas = Select::conCache($this->cat, $this->st, $this->porFila, $ast, $sql, $params);
                } elseif ($ast['k'] === 'explain') {
                    $filas = DatabaseAvanzada::explicar($this, $ast['consulta']);
                } elseif ($ast['k'] === 'check_keys') {
                    $filas = (new Integrity($this->cat))->claves($ast['tabla'], false);
                } elseif ($ast['k'] === 'check_tables') {
                    $filas = (new Integrity($this->cat))->ficheros($ast['tabla'], false);
                } elseif ($ast['k'] === 'check_indexes') {
                    $filas = (new Integrity($this->cat))->indices($ast['tabla'], false);
                } else {
                    $filas = (new Show($this->cat))->ejecutar($ast);
                }
            } catch (\Throwable $e) {
                // En una transacción el bloqueo sigue: lo que la sentencia dejó
                // sin confirmar se tira aquí (2.9: la siguiente lo confirmaba con lo suyo)
                if ($this->enTransaccion && StorageEscritura::txAbierta($this->st)) {
                    StorageEscritura::txAbandonar($this->st);
                }
                throw $e;
            } finally {
                $this->st->desbloquear();
                $this->cat->olvidar();
                $this->repararPendientes();
            }

            if (!$escritura) {
                if (Config::seRegistra($op, false)) {
                    Logger::registrar($this->base, $op, $sql, $this->enviadas ?? \count($filas), (microtime(true) - $t0) * 1000, null, $params);
                }
                return $filas;
            }
            if ($grande) {
                $res = DatabaseAvanzada::porTramos($this, $ast);
            }

            if (Config::seRegistra($op, false)) {
                Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
            }
            return self::resultadoEscritura($res);

        } catch (JsonSqlDbError $e) {
            if (Config::seRegistra($op, true)) {
                Logger::registrar($this->base, $op, $sql, null, (microtime(true) - $t0) * 1000,
                    $e->sqlState . ': ' . $e->getMessage(), $params);
            }
            throw $e;
        } finally {
            if ($apuntada ?? false) {
                StorageEscritura::escribiendo($this->st, null);
            }
            $precision === null || ini_set('serialize_precision', $precision);
        }
    }

    /**
     * Sentencias que no van contra una base concreta: SHOW DATABASES, CREATE
     * DATABASE y DROP DATABASE. Lo hace DatabaseAvanzada.
     *
     * @throws JsonSqlDbError si la sentencia necesita una base de datos
     */
    public static function consultarGlobal(string $sql, array $params = [], ?callable $autorizar = null, ?string $raiz = null)
    {
        return DatabaseAvanzada::correrGlobal($sql, $params, $autorizar, $raiz);
    }

    /**
     * Corta la ejecución si se está usando el motor directamente y la conexión
     * directa no está permitida.
     *
     * La API define JSONSQLDB_VIA_API, así que sus peticiones pasan siempre.
     */
    protected static function exigirAcceso(): void
    {
        if (\defined('JSONSQLDB_VIA_API') || Config::conexionDirecta()) {
            return;
        }
        throw JsonSqlDbError::permission(
            'La conexión directa al motor está desactivada. Usa la API '
            . '(api/jsonsqldb_api.php) o pon JSONSQLDB_CONEXION_DIRECTA a true en config.php'
        );
    }

    /**
     * La tabla de un UPDATE o DELETE que se puede escribir por partes, o null.
     * Solo lo que depende de cada fila y de nada más: la tabla no tiene claves
     * foráneas ni triggers, nadie la referencia (tablaUnica()), no hay
     * subconsultas, y un UPDATE no toca columnas de una clave primaria o
     * única, cuya unicidad es cosa de toda la tabla. Así, dos escrituras sobre
     * partes distintas no pueden depender la una de la otra. TRUNCATE TABLE
     * no (2.9): vacía la tabla entera, y con autoincremento escribe también
     * su estructura, que por partes no se puede juntar (se repetía entera).
     */
    private function tablaPorPartes(array $ast): ?string
    {
        if (!Config::escrituraPorPartes() || !\in_array($ast['k'], ['update', 'delete'], true) || !empty($ast['truncar'])) {
            return null;
        }
        $tabla = $this->tablaUnica($ast);
        if ($tabla === null) {
            return null;
        }
        if ($ast['k'] === 'update') {
            $unicas = [];
            foreach (Catalog::conjuntosUnicos($this->cat->meta($tabla)) as $uq) {
                foreach ($uq['columns'] as $c) {
                    $unicas[strtolower((string)$c)] = true;
                }
            }
            foreach ($ast['set'] as $s) {
                if (isset($unicas[strtolower((string)$s['col'])])) {
                    return null;
                }
            }
        }
        return $tabla;
    }

    /**
     * ¿Lee de otras tablas? Un INSERT … SELECT, una subconsulta o un UPDATE o
     * DELETE con JOIN (2.9). Entonces no se bloquean solo las que escribe: con
     * el exclusivo de unas tablas, las demás se leen sin bloqueo (ver
     * Storage::bloquearLectura()), y otro proceso podría estar escribiéndolas.
     * Hasta la 2.9 las subconsultas no contaban. También para las sentencias
     * de los triggers (ver CatalogBloqueos::tablaDeLaSentencia()).
     */
    public static function leeOtras(array $ast): bool
    {
        return ($ast['select'] ?? null) !== null || !empty($ast['subconsultas']) || isset($ast['desde']);
    }

    /**
     * Qué tablas hay que bloquear para esta escritura, o null si hay que
     * bloquear la base entera.
     *
     * Se devuelven ORDENADAS. Ese orden es lo que hace imposible el
     * interbloqueo: dos procesos que necesiten las mismas tablas las piden en la
     * misma secuencia, así que uno espera al otro en vez de esperarse
     * mutuamente. Y se piden TODAS de golpe antes de escribir nada: pedir un
     * bloqueo más a mitad de la escritura sería justo lo que hay que evitar.
     *
     * @return list<string>|null
     */
    public function tablasAfectadas(array $ast): ?array
    {
        $tabla = $this->tablaUnica($ast);
        if ($tabla !== null) {
            return [$tabla];                  // no puede propagar: ella sola
        }
        // Solo el DML sencillo tiene un conjunto de tablas calculable
        if (!\in_array($ast['k'], ['insert', 'update', 'delete'], true)) {
            return null;
        }
        $destino = $ast['tabla'] ?? null;
        if (!\is_string($destino) || $destino === '' || self::leeOtras($ast)) {
            return null;
        }
        return CatalogBloqueos::cierreDeTablas($this->cat, $destino);
    }

    /**
     * Si la escritura afecta a UNA sola tabla, devuelve su nombre; si no, null.
     *
     * Solo entonces se puede bloquear la tabla en vez de la base entera. Basta
     * con que haya una clave foránea, que otra tabla la referencie o que exista
     * un trigger para que la operación pueda tocar más de una: en ese caso se
     * pide el bloqueo exclusivo de la base, que espera a que terminen todas las
     * escrituras pendientes de todas las tablas.
     *
     * Ante cualquier duda, null. Un bloqueo de más solo cuesta paralelismo; uno
     * de menos cuesta datos.
     */
    public function tablaUnica(array $ast): ?string
    {
        // Solo el DML sencillo. El DDL, las vistas, los triggers y REPAIR KEYS
        // tocan metadatos de la base o varias tablas a la vez.
        if (!\in_array($ast['k'], ['insert', 'update', 'delete'], true)) {
            return null;
        }
        $tabla = $ast['tabla'] ?? null;
        if (!\is_string($tabla) || $tabla === '' || !$this->cat->existe($tabla)) {
            return null;
        }
        if (self::leeOtras($ast)) {
            return null;
        }

        $meta = $this->cat->meta($tabla);
        if (($meta['foreign_keys'] ?? []) !== [] || ($meta['triggers'] ?? []) !== []) {
            return null;                      // puede propagar hacia otras
        }
        // Un INSERT que no cambia filas que ya estén (sin OR REPLACE ni DO
        // UPDATE) no toca a quien la referencie: una fila nueva no tiene hijas.
        // Sin mirarlas no se lista la carpeta (2.10: en cada INSERT, y con
        // 200.000 filas eran 800 ficheros)
        if ($ast['k'] === 'insert' && ($ast['conflicto']['modo'] ?? 'nada') === 'nada') {
            return $tabla;
        }
        // ¿Alguien la referencia? Un borrado suyo podría arrastrar filas ajenas
        foreach ($this->cat->tablasEsquema() as $otra) {
            if ($otra === $tabla) {
                continue;
            }
            foreach ($this->cat->meta($otra)['foreign_keys'] ?? [] as $fk) {
                if (strcasecmp((string)$fk['table'], $tabla) === 0) {
                    return null;
                }
            }
        }
        return $tabla;
    }

    /** Tipo de operación tal y como se guarda en el log. */
    public static function operacion(array $ast): string
    {
        return strtoupper(str_replace('_', ' ', $ast['k']));
    }
}
