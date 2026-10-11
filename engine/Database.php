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
     * Lo que tiene que haber costado una consulta, en nanosegundos, para
     * guardar su resultado (2.9): 1 ms. Una búsqueda por clave cuesta menos
     * que guardarla, y casi nunca se repite tal cual; guardarlas todas
     * llenaba la caché de resultados que nadie volvía a pedir.
     */
    private const RESULTADO_MINIMO = 1000000;

    /**
     * Ejecuta un SELECT pasando por la caché de resultados: la clave lleva la
     * SQL, los parámetros y la revisión de cada tabla implicada, así que un
     * resultado guardado solo vale mientras nadie escriba en ninguna de ellas.
     * No se guardan las consultas que no son deterministas (RANDOM(), la
     * fecha actual) ni los resultados grandes, que no compensan.
     *
     * @return list<array>
     */
    private function seleccionar(array $ast, string $sql, array $params): array
    {
        $tope   = Config::cacheResultados();
        $tablas = $tope > 0 ? Select::tablasDe($ast, $this->cat) : null;
        // Lo que no lee ninguna tabla no se guarda (2.9): ninguna escritura lo
        // borraría nunca, y cada consulta distinta dejaba su fichero
        if ($tablas !== null && array_filter($tablas, static fn(string $t): bool => $t[0] !== '#') === []) {
            $tablas = null;
        }
        $porFila = $this->porFila;
        if ($tablas === null) {
            return $porFila === null
                ? (new Select($this->cat))->ejecutar($ast)
                : (new Select($this->cat))->ejecutarHacia($ast, $porFila) ?? [];
        }
        $clave = StorageLectura::claveResultado($this->st, $tablas, $sql, $params);
        $filas = StorageLectura::resultadoCacheado($this->st, $clave, $tablas);
        if ($filas !== null) {
            return $filas;                           // ya está entero: se devuelve tal cual
        }
        $inicio = hrtime(true);
        if ($porFila === null) {
            $filas = (new Select($this->cat))->ejecutar($ast);
        } else {
            // Las filas que salen una a una se guardan también para la caché
            // mientras no pasen del tope; pasado, se dejan de guardar
            $guardadas = [];
            $filas = (new Select($this->cat))->ejecutarHacia($ast, static function (array $fila) use ($porFila, &$guardadas, $tope): void {
                if ($guardadas !== null) {
                    $guardadas[] = $fila;
                    if (count($guardadas) > $tope) {
                        $guardadas = null;
                    }
                }
                $porFila($fila);
            });
            if ($filas === null) {
                if ($guardadas !== null && hrtime(true) - $inicio >= self::RESULTADO_MINIMO) {
                    StorageLectura::guardarResultado($this->st, $clave, $tablas, $guardadas);
                }
                return [];
            }
        }
        if (count($filas) <= $tope && hrtime(true) - $inicio >= self::RESULTADO_MINIMO) {
            StorageLectura::guardarResultado($this->st, $clave, $tablas, $filas);
        }
        return $filas;
    }

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
        $this->porFila = function (array $fila) use ($porFila): void {
            $this->enviadas = ($this->enviadas ?? 0) + 1;
            $porFila($fila);
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
        self::exigirAcceso();
        if ($this->enTransaccion) {
            throw JsonSqlDbError::lock('Ya hay una transacción abierta en esta base');
        }
        $this->st->bloquear(true);
        $this->cat->olvidar();
        try {
            StorageMantenimiento::loteIniciar($this->st);
            $this->enTransaccion = true;
            try {
                $r = $fn($this);
            } catch (\Throwable $e) {
                StorageMantenimiento::loteDeshacer($this->st);
                throw $e;
            }
            StorageMantenimiento::loteConfirmar($this->st);
            return $r;
        } finally {
            $this->enTransaccion = false;
            $this->st->desbloquear();
            $this->cat->olvidar();
            $this->repararPendientes();
        }
    }

    /**
     * Repara en disco los ficheros que la consulta leyó estropeados a mano y
     * reparados en memoria (2.9, ver Storage::decodificarParte()). Al acabar,
     * sin ningún bloqueo: reparar es escribir, con el exclusivo de la base.
     * La consulta ya ha ido bien, así que un fallo aquí no la estropea: se
     * apunta en el registro y la tabla sigue como estaba hasta repararla.
     */
    private function repararPendientes(): void
    {
        foreach ($this->st->porReparar() as $tabla => $ficheros) {
            $t0 = microtime(true);
            $sql = "REPAIR TABLES FROM $tabla -- automática: " . implode(', ', array_map(
                static fn(string $f, string $motivo): string => "$f ($motivo)", array_keys($ficheros), $ficheros));
            try {
                $this->st->bloquear(true);
                $this->cat->olvidar();
                $hecho = $this->cat->existe($tabla)
                    ? StorageMantenimiento::revisarFicheros($this->st, $tabla, $this->cat->indicesDe($tabla), false, in_array('editado', $ficheros, true))
                    : [];
                Logger::registrar($this->base, 'REPAIR', $sql, count($hecho), (microtime(true) - $t0) * 1000);
            } catch (\Throwable $e) {
                Logger::registrar($this->base, 'REPAIR', $sql, null, (microtime(true) - $t0) * 1000, $e->getMessage());
            } finally {
                $this->st->desbloquear();
                $this->cat->olvidar();
            }
        }
        $this->st->porReparar();          // lo que la propia reparación volvió a leer roto ya está arreglado
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

    /** ¿Solo lee? CHECK KEYS y CHECK TABLES solo leen; REPAIR, escribe */
    protected static function soloLee(array $ast): bool
    {
        return in_array($ast['k'], ['select', 'union', 'explain', 'check_keys', 'check_tables'], true)
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

        try {
            $ast = Parser::analizar($sql, $params);
            $op  = self::operacion($ast);
            // Lo que aquí no tiene efecto (ANALYZE, USE de esta base): ni bloquea
            if ($ast['k'] === 'nada' || $ast['k'] === 'use') {
                if ($ast['k'] === 'use' && $ast['base'] !== $this->base) {
                    throw JsonSqlDbError::syntax("USE no cambia de base: cada conexión, y cada petición de la API, va a una. "
                        . "Para '{$ast['base']}', ábrela con su nombre");
                }
                return self::resultadoEscritura(['filas' => 0, 'mensaje' => $ast['mensaje'] ?? "Ya estás en '{$this->base}'"]);
            }
            if (in_array($ast['k'], ['show_databases', 'create_database', 'drop_database'], true)) {
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
                $autorizar($ast['k']);          // permite comprobar permisos antes de ejecutar
            }
            $escritura = !self::soloLee($ast);

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
                Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
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
                    Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
                    return self::resultadoEscritura($res);
                } catch (ConflictoPartes) {
                    StorageCambios::anotarRepeticion();          // a la cola: con la tabla entera, abajo
                } catch (SentenciaGrande) {
                    $res = DatabaseAvanzada::porTramos($this, $ast);
                    Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
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
                    $filas = $this->seleccionar($ast, $sql, $params);
                } elseif ($ast['k'] === 'explain') {
                    $filas = DatabaseAvanzada::explicar($this, $ast['consulta']);
                } elseif ($ast['k'] === 'check_keys') {
                    $filas = (new Integrity($this->cat))->claves($ast['tabla'], false);
                } elseif ($ast['k'] === 'check_tables') {
                    $filas = (new Integrity($this->cat))->ficheros($ast['tabla'], false);
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
                Logger::registrar($this->base, $op, $sql, $this->enviadas ?? count($filas), (microtime(true) - $t0) * 1000, null, $params);
                return $filas;
            }
            if ($grande) {
                $res = DatabaseAvanzada::porTramos($this, $ast);
            }

            Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
            return self::resultadoEscritura($res);

        } catch (JsonSqlDbError $e) {
            Logger::registrar($this->base, $op, $sql, null, (microtime(true) - $t0) * 1000,
                $e->sqlState . ': ' . $e->getMessage(), $params);
            throw $e;
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
        if (defined('JSONSQLDB_VIA_API') || Config::conexionDirecta()) {
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
        if (!Config::escrituraPorPartes() || !in_array($ast['k'], ['update', 'delete'], true) || !empty($ast['truncar'])) {
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
     * de los triggers (ver Catalog::tablaDeLaSentencia()).
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
        if (!in_array($ast['k'], ['insert', 'update', 'delete'], true)) {
            return null;
        }
        $destino = $ast['tabla'] ?? null;
        if (!is_string($destino) || $destino === '' || self::leeOtras($ast)) {
            return null;
        }
        return $this->cat->cierreDeTablas($destino);
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
        if (!in_array($ast['k'], ['insert', 'update', 'delete'], true)) {
            return null;
        }
        $tabla = $ast['tabla'] ?? null;
        if (!is_string($tabla) || $tabla === '' || !$this->cat->existe($tabla)) {
            return null;
        }
        if (self::leeOtras($ast)) {
            return null;
        }

        $meta = $this->cat->meta($tabla);
        if (($meta['foreign_keys'] ?? []) !== [] || ($meta['triggers'] ?? []) !== []) {
            return null;                      // puede propagar hacia otras
        }
        // ¿Alguien la referencia? Un borrado suyo podría arrastrar filas ajenas
        foreach ($this->cat->tablas() as $otra) {
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
