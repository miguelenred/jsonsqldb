<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que solo usan algunas peticiones: los lotes de sentencias (ver
 * Database::lote()), las sentencias de toda la instalación (SHOW DATABASES,
 * CREATE y DROP DATABASE), EXPLAIN y las escrituras por tramos. Métodos de
 * Database que reciben la base en $db. Van aparte para que una consulta
 * corriente no tenga que compilarlos (2.9): sin OPcache, PHP compila en cada
 * petición lo que carga. Hereda de Database solo para llegar a lo protegido;
 * no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class DatabaseAvanzada extends Database
{
    private function __construct()
    {
    }

    /**
     * Database::lote(): un lote que solo lee va sentencia a sentencia; el
     * resto, en una transacción.
     *
     * @param list<string|array{0: string, 1?: array}> $sentencias
     * @return list<mixed>
     */
    public static function correrLote(Database $db, array $sentencias, ?callable $autorizar = null): array
    {
        if ($sentencias === []) {
            return [];
        }
        // BEGIN (o START TRANSACTION) al principio y COMMIT (o END) al final
        // sobran: el lote ya es una transacción. ROLLBACK al final ejecuta el
        // lote y lo deshace entero. En otro sitio, son un error (2.9)
        $sentencias = array_values($sentencias);
        $control    = [];
        foreach ($sentencias as $i => $s) {
            $c = self::control(is_array($s) ? (string)($s[0] ?? '') : (string)$s);
            if ($c !== null) {
                if ($c === 'BEGIN' ? $i !== 0 : $i !== count($sentencias) - 1) {
                    throw JsonSqlDbError::syntax($c === 'BEGIN' ? 'BEGIN solo vale como primera sentencia del lote'
                        : "$c solo vale como última sentencia del lote");
                }
                $control[$i] = $c;
            }
        }
        $r      = [];
        $correr = static function () use ($db, $sentencias, $autorizar, $control, &$r): void {
            foreach ($sentencias as $i => $s) {
                if (isset($control[$i])) {
                    $r[] = self::resultadoEscritura(['filas' => 0, 'mensaje' => ['BEGIN' => 'Transacción iniciada',
                        'COMMIT' => 'Transacción confirmada', 'ROLLBACK' => 'Transacción deshecha: no se ha guardado nada'][$control[$i]]]);
                    continue;
                }
                [$sql, $params] = self::sentenciaDelLote($s);
                $r[] = $db->consultar($sql, $params, $autorizar);
            }
        };
        // Un lote que solo lee no es una transacción (2.9): cada sentencia toma
        // y suelta su bloqueo compartido, como si llegaran una tras otra. Con
        // el exclusivo, una clave de solo lectura podía parar a todos mientras
        // durase; con el compartido de todo el lote, se bloqueaba para siempre
        // con una escritura en dos tablas, que las pide en otro orden
        if (!$db->enTransaccion && self::loteDeLectura($sentencias, $control)) {
            $correr();
            return $r;
        }
        $marca = new \RuntimeException('deshacer el lote');
        try {
            $db->transaccion(static function () use ($correr, $control, $marca): void {
                $correr();
                if (in_array('ROLLBACK', $control, true)) {
                    throw $marca;                         // transaccion() deshace todo al recibirla
                }
            });
        } catch (\RuntimeException $e) {
            if ($e !== $marca) {
                throw $e;
            }
        }
        return $r;
    }

    /**
     * ¿Solo leen todas las sentencias de un lote? Las de control no cuentan,
     * y las que aquí no hacen nada (ANALYZE, USE de esta base) tampoco. Una que
     * no se puede analizar, no: el lote va como transacción y da su error.
     *
     * @param array<int, string|array> $sentencias
     * @param array<int, string>       $control
     */
    private static function loteDeLectura(array $sentencias, array $control): bool
    {
        foreach ($sentencias as $i => $s) {
            if (isset($control[$i])) {
                continue;
            }
            [$sql, $params] = self::sentenciaDelLote($s);
            try {
                $ast = Parser::analizar($sql, $params);
            } catch (JsonSqlDbError) {
                return false;
            }
            if (!self::soloLee($ast) && $ast['k'] !== 'nada' && $ast['k'] !== 'use') {
                return false;
            }
        }
        return true;
    }

    /**
     * Una sentencia de un lote, el texto SQL o [sql, params], como [sql, params].
     *
     * @param string|array $s
     * @return array{0: string, 1: array}
     */
    private static function sentenciaDelLote($s): array
    {
        return is_array($s) ? [(string)($s[0] ?? ''), (array)($s[1] ?? [])] : [(string)$s, []];
    }

    /** BEGIN, COMMIT o ROLLBACK si la sentencia es de control de una transacción */
    private static function control(string $sql): ?string
    {
        if (!preg_match('/^\s*(BEGIN|START\s+TRANSACTION|COMMIT|END|ROLLBACK)(?:\s+(?:DEFERRED|IMMEDIATE|EXCLUSIVE))?(?:\s+(?:TRANSACTION|WORK))?\s*;?\s*$/i', $sql, $m)) {
            return null;
        }
        $c = strtoupper($m[1]);
        return $c === 'END' ? 'COMMIT' : ($c[0] === 'S' ? 'BEGIN' : $c);
    }

    /** Database::consultarGlobal(): SHOW DATABASES, CREATE DATABASE y DROP DATABASE */
    public static function correrGlobal(string $sql, array $params = [], ?callable $autorizar = null, ?string $raiz = null)
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
     * EXPLAIN (2.9): ejecuta la consulta sin la caché de resultados, fila a
     * fila y sin guardarlas, y devuelve cómo leyó cada tabla: con qué índice o
     * recorriéndola, y cuántas filas dio cada lectura.
     */
    protected static function explicar(Database $db, array $consulta): array
    {
        $db->st->traza = [];
        try {
            (new Select($db->cat))->ejecutarHacia($consulta, static function (): void {});
            return $db->st->traza;
        } finally {
            $db->st->traza = null;
        }
    }

    /**
     * Una escritura que no cabe en memoria de una vez (2.9): se repite en una
     * transacción, donde Writer escribe lo que lleva según avanza y todo
     * sigue siendo todo o nada (ver Writer::tramo()). Antes, una tabla cuyo
     * estado no dice dónde acaba cada parte se reescribe ya como debe estar:
     * escribir por tramos va por posiciones, y eso no las movería después.
     */
    protected static function porTramos(Database $db, array $ast): array
    {
        return $db->transaccion(static function () use ($db, $ast): array {
            foreach ((is_string($ast['tabla'] ?? null) ? $db->cat->cierreDeTablas($ast['tabla']) : null) ?? [] as $t) {
                if ($db->cat->existe($t) && !StorageEscritura::exacta($db->st, $t)) {
                    StorageMantenimiento::compactar($db->st, $t, $db->cat->indicesDe($t));
                }
            }
            $db->cat->olvidar();
            return (new Writer($db->cat))->ejecutar($ast);
        });
    }
}
