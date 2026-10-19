<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Acceso a la configuración. Si config.php no define una constante, se usa
 * el valor por defecto de aquí, de modo que el motor funciona igualmente
 * (útil en pruebas y en scripts de mantenimiento).
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Config
{
    /**
     * Versión del motor. Se lee del fichero VERSION para que no haya dos sitios
     * que puedan decir cosas distintas: estaba fijada a mano en '1.0.0' y no la
     * usaba nadie, así que llevaba versiones desincronizada sin que se notara.
     */
    public static function version(): string
    {
        static $v = null;
        if ($v === null) {
            $f = dirname(__DIR__) . '/VERSION';
            $v = is_file($f) ? trim((string)@file_get_contents($f)) : 'desconocida';
            if ($v === '') { $v = 'desconocida'; }
        }
        return $v;
    }

    public static function datos(): string
    {
        $ruta = \defined('JSONSQLDB_DATA_PATH') ? (string)JSONSQLDB_DATA_PATH : '';
        if ($ruta === '') {
            throw JsonSqlDbError::config('JSONSQLDB_DATA_PATH no está definido en config.php');
        }
        return rtrim(str_replace('\\', '/', $ruta), '/');
    }

    public static function filasPorParte(): int
    {
        $n = \defined('JSONSQLDB_FILAS_POR_PARTE') ? (int)JSONSQLDB_FILAS_POR_PARTE : 1000;
        return $n > 0 ? $n : 1000;
    }

    /**
     * true: caché en APCu si lo hay y si no en disco; 'apcu': solo en memoria
     * compartida, nunca en disco; false: sin caché.
     *
     * @return bool|string
     */
    public static function cacheActiva()
    {
        if (!\defined('JSONSQLDB_CACHE_ACTIVA')) {
            return true;
        }
        if (\is_string(JSONSQLDB_CACHE_ACTIVA) && strtolower(JSONSQLDB_CACHE_ACTIVA) === 'apcu') {
            return 'apcu';
        }
        return (bool)JSONSQLDB_CACHE_ACTIVA;
    }

    /**
     * ¿Se usa APCu para la caché, si está disponible? (2.9) Sí por defecto;
     * JSONSQLDB_CACHE_APCU = false lo deja de lado y la caché va a disco.
     */
    public static function cacheApcu(): bool
    {
        return !\defined('JSONSQLDB_CACHE_APCU') || (bool)JSONSQLDB_CACHE_APCU;
    }

    /**
     * ¿Se usa OPcache para la caché de las partes, si está activo? (2.9, ver
     * Storage::cacheLeer()). Sí por defecto: vale la pena, y sin OPcache el
     * motor funciona igual. Se desactiva con JSONSQLDB_CACHE_OPCACHE = false.
     */
    public static function cacheOpcache(): bool
    {
        return !\defined('JSONSQLDB_CACHE_OPCACHE') || (bool)JSONSQLDB_CACHE_OPCACHE;
    }

    /**
     * ¿Se enciende el JIT de OPcache si PHP lo deja? (2.10, ver
     * Storage::encenderJit()). Sí por defecto; JSONSQLDB_JIT = false lo deja
     * como esté.
     */
    public static function jit(): bool
    {
        return !\defined('JSONSQLDB_JIT') || (bool)JSONSQLDB_JIT;
    }

    /**
     * serialize_precision a -1 mientras el motor trabaja (2.10): con él
     * escriben json_encode() y var_export() los decimales de los datos, de
     * los índices y de la caché de OPcache. Con otro valor en php.ini, o
     * puesto por la aplicación, un decimal se guardaba recortado (con 10,
     * 0.30000000000000004 quedaba 0.3) o con cifras de más (con 17, 0.1 era
     * 0.10000000000000001, y su clave en un índice, otra). Devuelve el que
     * había, para volver a él al acabar, o null si ya era -1 o si ini_set()
     * está desactivada.
     */
    public static function precisionExacta(): ?string
    {
        $antes = self::precisionAjena();
        if ($antes !== null) {
            ini_set('serialize_precision', '-1');
        }
        return $antes;
    }

    /** El serialize_precision de ahora si no es -1 y ini_set() puede cambiarlo; si no, null (ver precisionExacta()) */
    public static function precisionAjena(): ?string
    {
        static $puede = null;
        $puede ??= \function_exists('ini_set');
        $p = ini_get('serialize_precision');
        return $p === '-1' || $p === false || !$puede ? null : $p;
    }

    /** ¿Se escriben por partes los UPDATE y DELETE que lo admiten? (ver StorageCambios::bloquearPartes()) */
    public static function escrituraPorPartes(): bool
    {
        return !\defined('JSONSQLDB_ESCRITURA_POR_PARTES') || (bool)JSONSQLDB_ESCRITURA_POR_PARTES;
    }

    /** Máximo de filas de un resultado de SELECT para guardarlo en caché; 0 la desactiva. */
    public static function cacheResultados(): int
    {
        $n = \defined('JSONSQLDB_CACHE_RESULTADOS') ? (int)JSONSQLDB_CACHE_RESULTADOS : 5000;
        return max(0, $n);
    }

    public static function logActivo(): bool
    {
        return \defined('JSONSQLDB_LOG_ACTIVO') && (bool)JSONSQLDB_LOG_ACTIVO;
    }

    /**
     * ¿Va al registro una sentencia $op, con error o sin él? Se pregunta antes
     * de llamar a Logger::registrar(), que así no se carga para nada (2.10:
     * sin OPcache, PHP compila en cada petición lo que carga, y con el nivel
     * 'escrituras' de config.php cada lectura lo compilaba para no escribir).
     * Lecturas: SELECT, UNION, EXPLAIN, SHOW y CHECK (2.9; antes solo se
     * saltaba SELECT)
     */
    public static function seRegistra(string $op, bool $conError): bool
    {
        if (!self::logActivo()) {
            return false;
        }
        $nivel = self::logNivel();
        return $conError || $nivel === 'todo' || ($nivel === 'escrituras'
            && $op !== 'SELECT' && $op !== 'UNION' && $op !== 'EXPLAIN' && strncmp($op, 'SHOW', 4) !== 0 && strncmp($op, 'CHECK', 5) !== 0);
    }

    public static function logPath(): string
    {
        $ruta = \defined('JSONSQLDB_LOG_PATH') ? (string)JSONSQLDB_LOG_PATH : '';
        return $ruta !== '' ? rtrim(str_replace('\\', '/', $ruta), '/') : '';
    }

    /**
     * 'todo' | 'escrituras' | 'errores'. Por defecto 'escrituras', como pone
     * config.php desde la 2.9: sin definirla (un config.php de antes) daba 'todo'
     */
    public static function logNivel(): string
    {
        $n = \defined('JSONSQLDB_LOG_NIVEL') ? strtolower((string)JSONSQLDB_LOG_NIVEL) : 'escrituras';
        return \in_array($n, ['todo', 'escrituras', 'errores'], true) ? $n : 'escrituras';
    }

    public static function logMaxSql(): int
    {
        return \defined('JSONSQLDB_LOG_MAX_SQL') ? max(0, (int)JSONSQLDB_LOG_MAX_SQL) : 2000;
    }

    public static function logMaxSize(): int
    {
        return \defined('JSONSQLDB_LOG_MAX_SIZE') ? max(0, (int)JSONSQLDB_LOG_MAX_SIZE) : 5242880;
    }

    /** Colación de ORDER BY: 'general' (por idioma) o 'binaria' (como SQLite). */
    public static function colacion(): string
    {
        $v = \defined('JSONSQLDB_COLACION') ? strtolower((string)JSONSQLDB_COLACION) : 'general';
        return $v === 'binaria' ? 'binaria' : 'general';
    }

    /**
     * Correcciones al mapa de ordenación, para las letras propias de un idioma.
     * @return array<string,string>
     */
    public static function colacionMapa(): array
    {
        if (!\defined('JSONSQLDB_COLACION_MAPA')) {
            return [];
        }
        $mapa = [];
        foreach (JSONSQLDB_COLACION_MAPA as $letra => $clave) {
            if (\is_string($letra) && \is_string($clave) && $letra !== '') {
                $mapa[$letra] = $clave;
            }
        }
        return $mapa;
    }

    /** ¿Se registran los valores de los parámetros ligados? */
    public static function logParams(): bool
    {
        return \defined('JSONSQLDB_LOG_PARAMS') && JSONSQLDB_LOG_PARAMS === true;
    }

    /**
     * ¿Se permite usar el motor directamente, sin pasar por la API?
     *
     * La API define JSONSQLDB_VIA_API, así que esto solo afecta al código que
     * instancia Database por su cuenta.
     */
    public static function conexionDirecta(): bool
    {
        return \defined('JSONSQLDB_CONEXION_DIRECTA') && JSONSQLDB_CONEXION_DIRECTA === true;
    }

    /** ¿Atiende la API (2.9)? Sin la constante, de un config.php anterior, sí. */
    public static function apiActiva(): bool
    {
        return !\defined('JSONSQLDB_API_ACTIVA') || JSONSQLDB_API_ACTIVA === true;
    }

    /** ¿Se mantienen y se usan los índices de búsqueda? */
    public static function indices(): bool
    {
        return !\defined('JSONSQLDB_INDICES') || JSONSQLDB_INDICES === true;
    }

    /** ¿Se corta la consulta al acercarse al límite de memoria de PHP? */
    public static function limiteMemoriaActivo(): bool
    {
        return !\defined('JSONSQLDB_MEMORIA_VIGILAR') || JSONSQLDB_MEMORIA_VIGILAR === true;
    }

    /** Fracción de memory_limit a partir de la cual se corta (0.85 = al 85 %). */
    public static function margenMemoria(): float
    {
        $v = \defined('JSONSQLDB_MEMORIA_MARGEN') ? (float)JSONSQLDB_MEMORIA_MARGEN : 0.85;
        return ($v > 0.5 && $v < 1.0) ? $v : 0.85;
    }

    public static function logDias(): int
    {
        return \defined('JSONSQLDB_LOG_DIAS') ? max(0, (int)JSONSQLDB_LOG_DIAS) : 90;
    }
}
