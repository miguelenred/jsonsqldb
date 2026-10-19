<?php
declare(strict_types=1);

/**
 * Ficheros JSON del propio panel (usuarios, intentos de acceso y auditoría).
 * Escritura atómica: temporal forzado a disco + rename, sin dejar restos si
 * algo falla.
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
        return self::decodificar($fichero) ?? $defecto;
    }

    /** El contenido de un JSON; [] si no existe y null si lo que hay no es un JSON válido. */
    private static function decodificar(string $fichero): ?array
    {
        $f = self::ruta($fichero);
        if (!is_file($f)) {
            return [];
        }
        $datos = json_decode((string)@file_get_contents($f), true);
        return is_array($datos) ? $datos : null;
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
        self::conBloqueo($fichero, static function () use ($fichero, $cambio): void {
            // Un fichero dañado no se pisa: con él como vacío, dar de alta un
            // usuario habría borrado los demás
            $antes = self::decodificar($fichero);
            if ($antes === null) {
                throw new RuntimeException(t('{fichero} está dañado y no se cambia; corrígelo o quítalo', ['fichero' => self::ruta($fichero)]));
            }
            // Si nada cambia, nada se escribe: un intento de entrar con la IP
            // bloqueada solo mira
            $despues = $cambio($antes);
            if ($despues !== $antes) {
                self::guardar($fichero, $despues);
            }
        });
    }

    /**
     * Ejecuta $fn con el bloqueo exclusivo de un fichero de la carpeta del
     * panel, sobre su .lock: dos peticiones a la vez no se pisan.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function conBloqueo(string $fichero, callable $fn)
    {
        $lock = @fopen(self::ruta($fichero) . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException(t('No se pudo escribir {destino}', ['destino' => self::ruta($fichero) . '.lock']));
        }
        try {
            flock($lock, LOCK_EX);
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Escribe un fichero entero o lo deja como estaba: temporal al lado,
     * forzado a disco, con sus permisos antes de ponerlo en su sitio, rename y
     * la carpeta forzada también (2.10: sin fsync, un corte de luz podía dejar
     * usuarios.json o config.php vacíos; el motor lo hace así en cada
     * escritura). En PHP 8.0, que no tiene fsync(), queda el rename.
     */
    public static function escribirAtomico(string $destino, string $contenido, ?int $permisos = null): void
    {
        $tmp = $permisos === null ? $destino . '.' . getmypid() . '.tmp' : self::temporalPrivado($destino);
        try {
            $f = $tmp === false ? false : @fopen($tmp, 'wb');
            // Los permisos, antes de escribir nada (2.10: se ponían después, y
            // las claves estaban un momento a la vista de los demás usuarios)
            if ($f !== false && $permisos !== null) {
                @chmod($tmp, $permisos);
            }
            $ok = $f !== false && @fwrite($f, $contenido) === strlen($contenido) && fflush($f)
                && (!function_exists('fsync') || @fsync($f));
            if ($f !== false) {
                fclose($f);
            }
            if (!$ok || !@rename($tmp, $destino)) {
                throw new RuntimeException(t('No se pudo escribir {destino}', ['destino' => $destino]));
            }
        } finally {
            if ($tmp !== false && is_file($tmp)) { @unlink($tmp); }
        }
        if (function_exists('fsync') && ($d = @fopen(dirname($destino), 'r')) !== false) {
            @fsync($d);
            fclose($d);
        }
    }

    /**
     * El temporal de un fichero con claves, al lado: tempnam() lo crea ya solo
     * para su dueño (0600) y con un nombre que no se puede adivinar (2.10: con
     * fopen nacía con los permisos del umask, y quien lo abriera antes del
     * chmod lo seguía leyendo después). false si no puede ser en esa carpeta.
     */
    public static function temporalPrivado(string $destino): string|false
    {
        $dir = dirname($destino);
        $tmp = @tempnam($dir, basename($destino) . '.');
        if ($tmp !== false && realpath(dirname($tmp)) !== realpath($dir)) {
            @unlink($tmp);                      // sin sitio en $dir, tempnam lo crea en el temporal del sistema
            return false;
        }
        return $tmp;
    }

    public static function guardar(string $fichero, array $datos): void
    {
        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException(t('No se pudo serializar {fichero}', ['fichero' => $fichero]));
        }
        // Solo para el dueño: usuarios.json lleva los hashes de las contraseñas, e
        // intentos.json las IPs y los usuarios probados (2.10: con el umask, en
        // un hosting compartido los leían los demás usuarios de la máquina)
        self::escribirAtomico(self::ruta($fichero), $json . "\n", 0600);
    }

    /** Añade una línea a un fichero de registro diario. */
    public static function anadirLinea(string $fichero, array $entrada): void
    {
        $json = json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        static $dichos = [];
        if ($json !== false && @file_put_contents(self::ruta($fichero), $json . "\n", FILE_APPEND | LOCK_EX) === false
            && !isset($dichos[$fichero])) {
            // La acción sigue, pero se dice una vez en el registro de errores de PHP (2.10: se callaba)
            $dichos[$fichero] = true;
            error_log('jsonSQLDBadmin: no se puede escribir ' . self::ruta($fichero) . ' (permisos, cuota o disco lleno)');
        }
    }

    /**
     * Las últimas líneas JSON de un fichero que cumplen $filtro, de la más
     * reciente a la más antigua, saltando las $saltar primeras y como mucho
     * $n, leyendo desde el final por bloques y parando al tenerlas (2.10: se
     * leía y se decodificaba entero, y la auditoría de un día con decenas de
     * miles de eventos no cabía en la memoria). Devuelve también si hay más
     * antes.
     *
     * @param callable(array): bool|null $filtro
     * @return array{0: list<array>, 1: bool}
     */
    public static function ultimasLineas(string $fichero, int $n, ?callable $filtro = null, int $saltar = 0): array
    {
        $f  = self::ruta($fichero);
        $fh = is_file($f) ? @fopen($f, 'rb') : false;
        if ($fh === false) {
            return [[], false];
        }
        $out   = [];
        $resto = '';
        $pos   = (int)fstat($fh)['size'];
        try {
            while ($pos > 0) {
                $leer = min(65536, $pos);
                $pos -= $leer;
                fseek($fh, $pos);
                $lineas = explode("\n", (string)fread($fh, $leer) . $resto);
                // La primera puede empezar en el bloque de antes
                $resto = $pos > 0 ? (string)array_shift($lineas) : '';
                for ($i = count($lineas) - 1; $i >= 0; $i--) {
                    $e = $lineas[$i] === '' ? null : json_decode($lineas[$i], true);
                    if (is_array($e) && ($filtro === null || $filtro($e)) && $saltar-- <= 0) {
                        if (count($out) === $n) {
                            return [$out, true];
                        }
                        $out[] = $e;
                    }
                }
            }
            return [$out, false];
        } finally {
            fclose($fh);
        }
    }
}
