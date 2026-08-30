<?php
declare(strict_types=1);

/**
 * Restaura una base de datos desde la copia ZIP que genera Exportar::zip().
 *
 * Escribe directamente en el disco del motor, así que solo funciona cuando el
 * panel y la API están en la misma máquina. Entre máquinas distintas hay que
 * usar el volcado en SQL, que va por la API.
 *
 * Todo lo que entra se valida antes de tocar nada:
 *
 *   - Solo ficheros .json, .htaccess y web.config. Nada más se copia.
 *   - Los nombres de tabla se validan con la misma regla del motor.
 *   - Cualquier ruta con '..', absoluta o con separadores raros se rechaza: es
 *     el ataque clásico contra los ZIP, escribir fuera de la carpeta destino.
 *   - Cada .json de tabla se comprueba que sea JSON válido con la forma que
 *     espera el motor.
 *
 * Y antes de sobrescribir se guarda una copia de lo que había, para poder
 * volver atrás si la restauración falla a medias.
 */
final class Importar
{
    /** Ficheros sueltos que sí se aceptan además de los .json */
    private const PERMITIDOS = ['.htaccess', 'web.config'];

    private const MAX_FICHEROS = 2000;

    /**
     * Restaura $zip dentro de $rutaBase, que es la carpeta de la base.
     *
     * @param string $zip       fichero .zip subido
     * @param string $base      nombre de la base de destino
     * @param string $rutaBase  carpeta de datos de esa base
     * @return string resumen de lo hecho
     */
    public static function zip(string $zip, string $base, string $rutaBase): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'La extensión zip de PHP no está activada. Actívala en php.ini (extension=zip) '
                . 'o restaura desde un volcado en SQL.'
            );
        }

        $arch = new ZipArchive();
        if ($arch->open($zip) !== true) {
            throw new RuntimeException('El fichero no es un ZIP válido o está dañado.');
        }

        try {
            $entradas = self::entradasValidas($arch, $rutaBase);
            if ($entradas === []) {
                throw new RuntimeException(
                    'El ZIP no contiene ninguna tabla. ¿Seguro que es una copia generada por '
                    . 'este panel? Dentro debe haber una carpeta con los ficheros .json.'
                );
            }

            // Copia de seguridad de lo que hay ahora, por si algo falla a medias
            $respaldo = self::respaldar($rutaBase);

            try {
                foreach ($entradas as $interna => $destino) {
                    $contenido = $arch->getFromName($interna);
                    if ($contenido === false) {
                        throw new RuntimeException("No se pudo leer '$interna' del ZIP.");
                    }
                    $dir = dirname($destino);
                    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                        throw new RuntimeException("No se puede crear la carpeta '$dir'.");
                    }
                    if (@file_put_contents($destino, $contenido) === false) {
                        throw new RuntimeException('No se puede escribir ' . basename($destino) . '.');
                    }
                }
            } catch (Throwable $e) {
                self::restaurar($respaldo, $rutaBase);
                throw new RuntimeException(
                    'La restauración falló y se ha dejado la base como estaba. ' . $e->getMessage()
                );
            }

            self::borrarArbol($respaldo);

            $tablas = 0;
            foreach (array_keys($entradas) as $interna) {
                if (substr($interna, -10) === '.meta.json') {
                    $tablas++;
                }
            }
            return count($entradas) . ' fichero(s) restaurados, ' . $tablas . ' tabla(s).';
        } finally {
            $arch->close();
        }
    }

    /**
     * Entradas del ZIP que se van a copiar: nombre interno => ruta de destino.
     *
     * @return array<string,string>
     */
    private static function entradasValidas(ZipArchive $arch, string $rutaBase): array
    {
        if ($arch->numFiles > self::MAX_FICHEROS) {
            throw new RuntimeException(
                'El ZIP tiene demasiados ficheros (' . $arch->numFiles . '). '
                . 'Una copia de este panel no debería pasar de unos pocos cientos.'
            );
        }

        $out = [];
        for ($i = 0; $i < $arch->numFiles; $i++) {
            $interna = (string)$arch->getNameIndex($i);
            if ($interna === '' || substr($interna, -1) === '/') {
                continue;                                   // carpetas
            }

            $limpia = str_replace('\\', '/', $interna);

            // Ruta hacia arriba, absoluta o con unidad de Windows: fuera
            if (strpos($limpia, '../') !== false || strpos($limpia, './') === 0
                || $limpia[0] === '/' || preg_match('/^[A-Za-z]:/', $limpia) === 1) {
                throw new RuntimeException(
                    "El ZIP contiene una ruta que sale de la carpeta de destino: '$interna'. "
                    . 'No se ha tocado nada.'
                );
            }

            // El ZIP trae una carpeta raíz con el nombre de la base original
            $partes = explode('/', $limpia);
            array_shift($partes);
            if ($partes === []) {
                continue;
            }
            $relativa = implode('/', $partes);
            $fichero  = basename($relativa);

            if (in_array($fichero, self::PERMITIDOS, true)) {
                $out[$interna] = $rutaBase . '/' . $relativa;
                continue;
            }
            if (substr($fichero, -5) !== '.json') {
                continue;                                   // lo demás se ignora
            }
            self::validarNombreJson($fichero);
            self::validarContenido($arch, $i, $fichero);

            $out[$interna] = $rutaBase . '/' . $relativa;
        }
        return $out;
    }

    /**
     * El nombre del .json tiene que ser el de una tabla o uno de los internos.
     *
     * Los sufijos son los que puede tener un fichero de una tabla:
     *
     *   usuarios.json              datos, primera parte
     *   usuarios.part2.json        siguientes partes
     *   usuarios.meta.json         estructura
     *   usuarios.rev.json          revisión           (desde la 2.0)
     *   usuarios.idx.auto_id.json  un índice          (desde la 2.0)
     *
     * Los dos últimos son de la 2.0 y no estaban aquí, así que exportar una base
     * y volver a restaurarla fallaba: el propio ZIP recién generado se rechazaba
     * por «un nombre que no es de tabla». `_revs.json` sigue admitiéndose porque
     * los ZIP de versiones anteriores lo llevan.
     */
    private static function validarNombreJson(string $fichero): void
    {
        if (in_array($fichero, ['_database.json', '_revs.json', '_views.json'], true)) {
            return;
        }
        $tabla = preg_replace(
            '/(\.meta|\.rev|\.idx\.[A-Za-z_][A-Za-z0-9_]{0,63})?(\.part\d+)?\.json$/',
            '',
            $fichero
        );
        if (!is_string($tabla) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $tabla) !== 1) {
            throw new RuntimeException(
                "El ZIP contiene un fichero con un nombre que no es de tabla: '$fichero'. "
                . 'No se ha tocado nada.'
            );
        }
    }

    /** El contenido tiene que ser JSON válido con la forma que espera el motor. */
    private static function validarContenido(ZipArchive $arch, int $i, string $fichero): void
    {
        $texto = $arch->getFromIndex($i);
        if ($texto === false) {
            throw new RuntimeException("No se pudo leer '$fichero' del ZIP.");
        }
        $datos = json_decode($texto, true);
        if (!is_array($datos)) {
            throw new RuntimeException(
                "'$fichero' no es un JSON válido. No se ha tocado nada."
            );
        }
        // Solo los ficheros de datos tienen 'rows'. La estructura, la revisión y
        // los índices tienen su propia forma, y los internos empiezan por '_'.
        $sinFilas = substr($fichero, -10) === '.meta.json'
                 || substr($fichero, -9)  === '.rev.json'
                 || strpos($fichero, '.idx.') !== false
                 || $fichero[0] === '_';
        if (!$sinFilas && !isset($datos['rows'])) {
            throw new RuntimeException(
                "'$fichero' no tiene la forma de un fichero de datos del motor. "
                . 'No se ha tocado nada.'
            );
        }
    }

    /** Aparta lo que hay ahora y devuelve dónde ha quedado. */
    private static function respaldar(string $rutaBase): string
    {
        $respaldo = $rutaBase . '.antes-de-restaurar';
        self::borrarArbol($respaldo);

        if (!is_dir($rutaBase)) {
            return $respaldo;                               // base nueva: nada que guardar
        }
        if (!@rename($rutaBase, $respaldo)) {
            throw new RuntimeException(
                'No se pudo apartar la base actual antes de restaurar. Comprueba los permisos '
                . 'de escritura en la carpeta de datos.'
            );
        }
        return $respaldo;
    }

    private static function restaurar(string $respaldo, string $rutaBase): void
    {
        if (!is_dir($respaldo)) {
            return;
        }
        self::borrarArbol($rutaBase);
        @rename($respaldo, $rutaBase);
    }

    private static function borrarArbol(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $r = $dir . '/' . $f;
            is_dir($r) ? self::borrarArbol($r) : @unlink($r);
        }
        @rmdir($dir);
    }
}
