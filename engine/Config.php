<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Acceso a la configuración. Si config.php no define una constante, se usa
 * el valor por defecto de aquí, de modo que el motor funciona igualmente
 * (útil en pruebas y en scripts de mantenimiento).
 */
final class Config
{
    /** Versión de jsonSQLDB. */
    public const VERSION = '1.0.0';

    public static function datos(): string
    {
        $ruta = defined('JSONSQLDB_DATA_PATH') ? (string)JSONSQLDB_DATA_PATH : '';
        if ($ruta === '') {
            throw JsonSqlDbError::config('JSONSQLDB_DATA_PATH no está definido en config.php');
        }
        return rtrim(str_replace('\\', '/', $ruta), '/');
    }

    public static function filasPorParte(): int
    {
        $n = defined('JSONSQLDB_FILAS_POR_PARTE') ? (int)JSONSQLDB_FILAS_POR_PARTE : 5000;
        return $n > 0 ? $n : 5000;
    }

    public static function cacheActiva(): bool
    {
        return !defined('JSONSQLDB_CACHE_ACTIVA') || (bool)JSONSQLDB_CACHE_ACTIVA;
    }

    public static function logActivo(): bool
    {
        return defined('JSONSQLDB_LOG_ACTIVO') && (bool)JSONSQLDB_LOG_ACTIVO;
    }

    public static function logPath(): string
    {
        $ruta = defined('JSONSQLDB_LOG_PATH') ? (string)JSONSQLDB_LOG_PATH : '';
        return $ruta !== '' ? rtrim(str_replace('\\', '/', $ruta), '/') : '';
    }

    /** 'todo' | 'escrituras' | 'errores' */
    public static function logNivel(): string
    {
        $n = defined('JSONSQLDB_LOG_NIVEL') ? strtolower((string)JSONSQLDB_LOG_NIVEL) : 'todo';
        return in_array($n, ['todo', 'escrituras', 'errores'], true) ? $n : 'todo';
    }

    public static function logMaxSql(): int
    {
        return defined('JSONSQLDB_LOG_MAX_SQL') ? max(0, (int)JSONSQLDB_LOG_MAX_SQL) : 2000;
    }

    public static function logMaxSize(): int
    {
        return defined('JSONSQLDB_LOG_MAX_SIZE') ? max(0, (int)JSONSQLDB_LOG_MAX_SIZE) : 5242880;
    }

    /** Colación de ORDER BY: 'general' (por idioma) o 'binaria' (como SQLite). */
    public static function colacion(): string
    {
        $v = defined('JSONSQLDB_COLACION') ? strtolower((string)JSONSQLDB_COLACION) : 'general';
        return $v === 'binaria' ? 'binaria' : 'general';
    }

    /**
     * Correcciones al mapa de ordenación, para las letras propias de un idioma.
     * @return array<string,string>
     */
    public static function colacionMapa(): array
    {
        if (!defined('JSONSQLDB_COLACION_MAPA') || !is_array(JSONSQLDB_COLACION_MAPA)) {
            return [];
        }
        $mapa = [];
        foreach (JSONSQLDB_COLACION_MAPA as $letra => $clave) {
            if (is_string($letra) && is_string($clave) && $letra !== '') {
                $mapa[$letra] = $clave;
            }
        }
        return $mapa;
    }

    /** ¿Se registran los valores de los parámetros ligados? */
    public static function logParams(): bool
    {
        return defined('JSONSQLDB_LOG_PARAMS') && JSONSQLDB_LOG_PARAMS === true;
    }

    /** ¿Journal también en las escrituras que tocan varias tablas? */
    /**
     * ¿Se permite usar el motor directamente, sin pasar por la API?
     *
     * La API define JSONSQLDB_VIA_API, así que esto solo afecta al código que
     * instancia Database por su cuenta.
     */
    public static function conexionDirecta(): bool
    {
        return defined('JSONSQLDB_CONEXION_DIRECTA') && JSONSQLDB_CONEXION_DIRECTA === true;
    }

    public static function journalDatos(): bool
    {
        return !defined('JSONSQLDB_JOURNAL_DATOS') || JSONSQLDB_JOURNAL_DATOS === true;
    }

    public static function logDias(): int
    {
        return defined('JSONSQLDB_LOG_DIAS') ? max(0, (int)JSONSQLDB_LOG_DIAS) : 90;
    }
}
