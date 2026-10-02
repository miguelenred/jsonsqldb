<?php
declare(strict_types=1);

/**
 * Ficheros JSON del propio panel (usuarios, intentos de acceso y auditoría).
 * Escritura atómica: temporal + rename, sin dejar restos si algo falla.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Store
{
    /** Carpeta de datos del panel, creada si hace falta. */
    public static function dir(): string
    {
        $dir = rtrim(str_replace('\\', '/', ADMIN_DATA_PATH), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(t('No se puede crear la carpeta de datos del panel: {dir}', ['dir' => $dir]));
        }
        return $dir;
    }

    public static function ruta(string $fichero): string
    {
        return self::dir() . '/' . $fichero;
    }

    /** Lee un JSON. Si no existe o está corrupto, devuelve $defecto. */
    public static function leer(string $fichero, array $defecto = []): array
    {
        $f = self::ruta($fichero);
        if (!is_file($f)) {
            return $defecto;
        }
        $datos = json_decode((string)@file_get_contents($f), true);
        return is_array($datos) ? $datos : $defecto;
    }

    /**
     * Lee, cambia y guarda un fichero de una vez, con un bloqueo exclusivo
     * sobre un fichero .lock al lado: dos peticiones a la vez no se pisan el
     * cambio. guardar() solo, con su temporal y su rename, deja el fichero
     * siempre entero, pero no impide que dos procesos lean lo mismo, sumen
     * uno cada uno y guarden los dos el mismo número.
     *
     * @param callable(array): array $cambio
     */
    public static function actualizar(string $fichero, callable $cambio): void
    {
        $lock = @fopen(self::ruta($fichero) . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException(t('No se pudo escribir {destino}', ['destino' => self::ruta($fichero) . '.lock']));
        }
        try {
            flock($lock, LOCK_EX);
            self::guardar($fichero, $cambio(self::leer($fichero)));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function guardar(string $fichero, array $datos): void
    {
        $destino = self::ruta($fichero);
        $tmp     = $destino . '.' . getmypid() . '.tmp';
        $json    = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException(t('No se pudo serializar {fichero}', ['fichero' => $fichero]));
        }
        try {
            if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $destino)) {
                throw new RuntimeException(t('No se pudo escribir {destino}', ['destino' => $destino]));
            }
        } finally {
            if (is_file($tmp)) { @unlink($tmp); }
        }
    }

    /** Añade una línea a un fichero de registro diario. */
    public static function anadirLinea(string $fichero, array $entrada): void
    {
        $json = json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents(self::ruta($fichero), $json . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** Lee un fichero de líneas JSON. */
    public static function leerLineas(string $fichero): array
    {
        $f = self::ruta($fichero);
        if (!is_file($f)) {
            return [];
        }
        $out = [];
        foreach ((array)file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $e = json_decode($linea, true);
            if (is_array($e)) { $out[] = $e; }
        }
        return $out;
    }
}
