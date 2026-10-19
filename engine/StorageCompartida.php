<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que se guarda para otros procesos en las cachés compartidas (2.10, aparte
 * de Storage para que sin OPcache ni APCu no se compile): el estado de una
 * tabla en APCu o en un .php para OPcache, y las partes leídas en .php.
 *
 * Métodos de Storage que reciben el almacenamiento en $st.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageCompartida extends Storage
{
    /** Partes pasadas a .php en esta petición: pocas, para que ninguna sea mucho más lenta */
    private static int $phpGenerados = 0;
    private const PHP_POR_PETICION = 4;

    public static function guardarEstado(Storage $st, string $tabla, string $sello, array $estado): void
    {
        if (!$st->opcache) {
            if (self::apcuConSitio()) {
                apcu_store($st->prefijo . 'e:' . $tabla, ['s' => $sello, 'e' => $estado]);
            }
            return;
        }
        // Recién reescrito, OPcache puede servir aún el anterior un par de
        // segundos: entonces no se vuelve a escribir, se lee el fichero
        $php = $st->ficheroEstado($tabla);
        if ((!is_file($php) || (int)@filectime($php) < time() - 3) && self::opcacheConSitio(true)
            && (is_dir($st->dirCache) || @mkdir($st->dirCache, 0775, true) || is_dir($st->dirCache))) {
            self::volcarPhp($php, ['s' => $sello, 'e' => $estado]);
        }
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
    public static function aPhp(Storage $st, string $clave, string $php, array $filas): void
    {
        // La edad, la del fichero de datos de esa parte: lo que de verdad cambia
        [, , $etiqueta, $tipo] = explode(':', $clave);
        $datos = $st->ficheroDatos(substr($etiqueta, 0, (int)strrpos($etiqueta, '@')), (int)substr($tipo, 1));
        if (self::$phpGenerados >= self::PHP_POR_PETICION || (int)@filemtime($datos) >= time() - 2 || !self::opcacheConSitio(true)
            || (!is_dir($st->dirCache) && !@mkdir($st->dirCache, 0775, true) && !is_dir($st->dirCache))) {
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
    public static function volcarPhp(string $php, array $valor): void
    {
        $tmp   = $php . '.' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.tmp';
        $texto = '<?php return ' . var_export($valor, true) . ';';
        if (@file_put_contents($tmp, $texto) !== \strlen($texto) || !@touch($tmp, time() - 10) || !@rename($tmp, $php)) {
            @unlink($tmp);
        }
    }
}
