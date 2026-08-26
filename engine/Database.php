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
 */
final class Database
{
    private Storage $st;
    private Catalog $cat;
    private string  $base;

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
     * Ejecuta una sentencia SQL.
     *
     * SELECT devuelve la lista de filas.
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

        try {
            $ast = Parser::analizar($sql, $params);
            $op  = self::operacion($ast);
            if (in_array($ast['k'], ['show_databases', 'create_database', 'drop_database'], true)) {
                return self::consultarGlobal($sql, $params, $autorizar, dirname($this->st->dir()));
            }
            if ($autorizar !== null) {
                $autorizar($ast['k']);          // permite comprobar permisos antes de ejecutar
            }
            // CHECK KEYS solo lee; REPAIR KEYS puede escribir
            $escritura = $ast['k'] !== 'select'
                      && $ast['k'] !== 'union'
                      && $ast['k'] !== 'check_keys'
                      && strncmp($ast['k'], 'show_', 5) !== 0;

            // Solo se llena una de las dos, según $escritura. Se inicializan para
            // que una rama nueva mal escrita no dé un aviso de PHP y un log falso.
            $filas = [];
            $res   = ['filas' => 0, 'mensaje' => ''];

            // Decidir el alcance del bloqueo obliga a mirar la estructura, y eso
            // ocurre ANTES de tenerlo. Lo leído entonces puede quedar obsoleto en
            // cuanto otro proceso escriba, así que se olvida nada más bloquear:
            // sin esto, dos procesos podían reutilizar el mismo autoincremento.
            $tablaSola = $escritura ? $this->tablaUnica($ast) : null;

            $this->st->bloquear($escritura, $tablaSola);
            $this->cat->olvidar();
            try {
                if ($escritura) {
                    $res = (new Writer($this->cat))->ejecutar($ast);
                } elseif ($ast['k'] === 'select' || $ast['k'] === 'union') {
                    $filas = (new Select($this->cat))->ejecutar($ast);
                } elseif ($ast['k'] === 'check_keys') {
                    $filas = (new Integrity($this->cat))->claves($ast['tabla'], false);
                } else {
                    $filas = (new Show($this->cat))->ejecutar($ast);
                }
            } finally {
                $this->st->desbloquear();
                $this->cat->olvidar();
            }

            if (!$escritura) {
                Logger::registrar($this->base, $op, $sql, count($filas), (microtime(true) - $t0) * 1000, null, $params);
                return $filas;
            }

            Logger::registrar($this->base, $op, $sql, $res['filas'], (microtime(true) - $t0) * 1000, null, $params);
            return ['success' => true, 'filas' => $res['filas'], 'mensaje' => $res['mensaje']];

        } catch (JsonSqlDbError $e) {
            Logger::registrar($this->base, $op, $sql, null, (microtime(true) - $t0) * 1000,
                $e->sqlState . ': ' . $e->getMessage(), $params);
            throw $e;
        }
    }

    /**
     * Sentencias que no van contra una base concreta:
     * SHOW DATABASES, CREATE DATABASE y DROP DATABASE.
     *
     * @throws JsonSqlDbError si la sentencia necesita una base de datos
     */
    public static function consultarGlobal(string $sql, array $params = [], ?callable $autorizar = null, ?string $raiz = null)
    {
        self::exigirAcceso();

        $t0   = microtime(true);
        $raiz = $raiz ?? Config::datos();
        $op   = 'DESCONOCIDA';

        try {
            $ast = Parser::analizar($sql, $params);
            $op  = self::operacion($ast);
            if (!in_array($ast['k'], ['show_databases', 'create_database', 'drop_database'], true)) {
                throw JsonSqlDbError::config('Esta sentencia necesita indicar una base de datos');
            }
            if ($autorizar !== null) {
                $autorizar($ast['k']);
            }

            if ($ast['k'] === 'show_databases') {
                $filas = [];
                foreach (Storage::bases($raiz) as $b) {
                    $filas[] = ['base' => $b];
                }
                Logger::registrar('', $op, $sql, count($filas), (microtime(true) - $t0) * 1000, null, $params);
                return $filas;
            }

            $base = $ast['base'];
            if ($ast['k'] === 'create_database') {
                $existe = in_array($base, Storage::bases($raiz), true);
                if ($existe && $ast['si_no_existe']) {
                    $mensaje = "La base '$base' ya existía";
                } else {
                    Storage::crearBase($raiz, $base);
                    $mensaje = "Base de datos '$base' creada";
                }
            } else {
                $existe = in_array($base, Storage::bases($raiz), true);
                if (!$existe && $ast['si_existe']) {
                    $mensaje = "La base '$base' no existía";
                } else {
                    Storage::borrarBase($raiz, $base);
                    $mensaje = "Base de datos '$base' borrada";
                }
            }

            Logger::registrar($base, $op, $sql, 0, (microtime(true) - $t0) * 1000, null, $params);
            return ['success' => true, 'filas' => 0, 'mensaje' => $mensaje];

        } catch (JsonSqlDbError $e) {
            Logger::registrar('', $op, $sql, null, (microtime(true) - $t0) * 1000,
                $e->sqlState . ': ' . $e->getMessage(), $params);
            throw $e;
        }
    }

    /**
     * Corta la ejecución si se está usando el motor directamente y la conexión
     * directa no está permitida.
     *
     * La API define JSONSQLDB_VIA_API, así que sus peticiones pasan siempre.
     */
    private static function exigirAcceso(): void
    {
        if (defined('JSONSQLDB_VIA_API') || Config::conexionDirecta()) {
            return;
        }
        throw JsonSqlDbError::permission(
            'La conexión directa al motor está desactivada. Usa la API '
            . '(api/jsonsqldb_api.php) o pon JSONSQLDB_CONEXION_DIRECTA a true en config.php'
        );
    }

    /** Tipo de operación tal y como se guarda en el log. */
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
        // INSERT ... SELECT lee de otras tablas
        if (($ast['select'] ?? null) !== null) {
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

    public static function operacion(array $ast): string
    {
        return strtoupper(str_replace('_', ' ', $ast['k']));
    }
}
