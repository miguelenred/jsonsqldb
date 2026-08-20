<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Log de consultas.
 *
 * Un fichero por día en JSONSQLDB_LOG_PATH: consultas-YYYY-MM-DD.json
 * Cada línea es un objeto JSON completo e independiente (formato JSON Lines):
 * así se puede añadir al final sin reescribir el fichero, que es lo que
 * permite loguear sin penalizar el rendimiento, y se sigue leyendo a simple
 * vista o procesando línea a línea.
 *
 * {"ts":"2026-08-19 12:00:00.123","ip":"10.0.0.5","db":"mibase","op":"SELECT",
 *  "rows":42,"ms":3.15,"origen":"Mi aplicación","sql":"SELECT ...","error":null}
 *
 *   rows  = registros mostrados (SELECT) o afectados (INSERT/UPDATE/DELETE)
 *   ms    = tiempo de ejecución en milisegundos
 *   origen= etiqueta de la API key que lanzó la consulta (vacío fuera de la API)
 */
final class Logger
{
    private static string $origen = '';
    private static ?string $ip    = null;

    /** Contexto de la petición: quién ejecuta y desde dónde (lo fija la API). */
    public static function contexto(string $origen, ?string $ip = null): void
    {
        self::$origen = $origen;
        self::$ip     = $ip;
    }

    /**
     * Registra una consulta.
     *
     * @param string      $base   base de datos
     * @param string      $op     SELECT, INSERT, UPDATE, DELETE, CREATE, ...
     * @param string      $sql    sentencia ejecutada
     * @param int|null    $filas  registros mostrados o afectados
     * @param float       $ms     milisegundos empleados
     * @param string|null $error  mensaje de error, o null si fue bien
     */
    public static function registrar(
        string $base,
        string $op,
        string $sql,
        ?int $filas,
        float $ms,
        ?string $error = null,
        array $params = []
    ): void {
        if (!Config::logActivo()) {
            return;
        }
        $nivel = Config::logNivel();
        if ($nivel === 'errores' && $error === null) {
            return;
        }
        if ($nivel === 'escrituras' && $error === null && $op === 'SELECT') {
            return;
        }

        $dir = Config::logPath();
        if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))) {
            return;   // sin log: el motor nunca falla por no poder escribirlo
        }

        $max = Config::logMaxSql();
        $entrada = [
            'ts'     => self::ahora(),
            'ip'     => self::ip(),
            'db'     => $base,
            'op'     => $op,
            'rows'   => $filas,
            'ms'     => round($ms, 2),
            'origen' => self::$origen,
            'sql'    => $max > 0 && strlen($sql) > $max ? substr($sql, 0, $max) . '…' : $sql,
        ];
        // Los valores solo se registran si se ha pedido expresamente: ahí viajan
        // contraseñas, tokens y datos personales.
        if ($params !== [] && Config::logParams()) {
            $entrada['params'] = $params;
        }
        $entrada['error'] = $error;

        $linea = json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($linea === false) {
            return;
        }

        $fichero = self::fichero($dir);
        @file_put_contents($fichero, $linea . "\n", FILE_APPEND | LOCK_EX);

        if (mt_rand(1, 200) === 1) {
            self::purgar($dir);
        }
    }

    /** Fichero del día, rotando si supera el tamaño máximo. */
    private static function fichero(string $dir): string
    {
        $base = $dir . '/consultas-' . date('Y-m-d');
        $max  = Config::logMaxSize();
        $f    = $base . '.json';
        if ($max > 0) {
            for ($i = 1; is_file($f) && filesize($f) >= $max; $i++) {
                $f = $base . '.' . $i . '.json';
            }
        }
        return $f;
    }

    /** Borra los ficheros de log más antiguos que JSONSQLDB_LOG_DIAS. */
    private static function purgar(string $dir): void
    {
        $dias = Config::logDias();
        if ($dias <= 0) {
            return;
        }
        $limite = time() - ($dias * 86400);
        foreach ((array)glob($dir . '/consultas-*.json') as $f) {
            if (@filemtime($f) < $limite) {
                @unlink($f);
            }
        }
    }

    private static function ahora(): string
    {
        $t = microtime(true);
        return date('Y-m-d H:i:s', (int)$t) . '.' . str_pad((string)(int)(($t - (int)$t) * 1000), 3, '0', STR_PAD_LEFT);
    }

    private static function ip(): string
    {
        if (self::$ip !== null) {
            return self::$ip;
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'desconocida'));
    }
}
