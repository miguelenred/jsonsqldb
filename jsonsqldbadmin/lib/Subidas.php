<?php
declare(strict_types=1);

/**
 * De dónde sale el fichero de una importación, sea del tamaño que sea:
 *
 *   - subido entero, como siempre, si cabe en el límite de subida de PHP;
 *   - subido por trozos: el navegador lo corta en trozos menores que
 *     upload_max_filesize y post_max_size y los envía uno tras otro; si se
 *     corta la conexión, sigue desde el último que llegó;
 *   - o dejado por FTP en la carpeta de importar (datos/importar), sin
 *     pasar por el navegador.
 *
 * Los trozos se juntan en datos/importar/.subidas/ y ese fichero se
 * borra al terminar la importación, vaya bien o mal; uno que se quedó a medias
 * se borra solo pasados dos días.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Subidas
{
    /** Trozo más grande, aunque el límite de PHP sea mayor: una petición corta se reintenta mejor. */
    private const MAX_TROZO = 8 * 1048576;
    /** Lo que se deja para los demás campos del formulario dentro de post_max_size. */
    private const HOLGURA = 65536;
    /** Segundos que se guarda una subida a medias. */
    private const CADUCIDAD = 2 * 86400;

    /** Bytes de cada trozo: por debajo de los dos límites de subida de PHP. */
    public static function tamanoTrozo(): int
    {
        $limite = self::MAX_TROZO;
        $subida = bytesIni('upload_max_filesize');
        $post   = bytesIni('post_max_size');
        if ($subida > 0) { $limite = min($limite, $subida); }
        if ($post > 0)   { $limite = min($limite, $post - self::HOLGURA); }
        return max(65536, $limite);
    }

    /** La carpeta de importar: importar/ dentro de la de datos del panel (ADMIN_DATA_PATH). */
    public static function ruta(): string
    {
        return rtrim(str_replace('\\', '/', (string)ADMIN_DATA_PATH), '/') . '/importar';
    }

    /** La carpeta de importar, creada si falta. */
    public static function carpeta(): string
    {
        $dir = self::ruta();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(t('No se puede crear la carpeta \'{dir}\'.', ['dir' => $dir]));
        }
        return $dir;
    }

    /**
     * Los ficheros que hay en la carpeta de importar (los dejados por FTP),
     * por nombre, con su tamaño. Vacío si la carpeta no existe.
     *
     * @return array<string,int>
     */
    public static function delServidor(): array
    {
        $dir = self::ruta();
        $out = [];
        foreach (is_dir($dir) ? (array)scandir($dir) : [] as $f) {
            $f = (string)$f;
            if (self::nombreValido($f) && is_file("$dir/$f")) {
                $out[$f] = (int)filesize("$dir/$f");
            }
        }
        return $out;
    }

    /**
     * Recibe un trozo y lo añade a la subida $id. Sin trozo, solo dice cuántos
     * bytes han llegado ya (para seguir después de un corte). Un trozo que no
     * empieza donde acaba lo recibido no se escribe: el navegador sigue desde
     * lo que se le devuelve.
     */
    public static function recibirTrozo(string $id, int $desde, ?array $trozo): int
    {
        $fichero = self::ficheroDeSubida($id);
        self::caducar();
        clearstatcache(true, $fichero);
        $recibido = is_file($fichero) ? (int)filesize($fichero) : 0;
        if ($trozo === null || $desde !== $recibido) {
            return $recibido;
        }
        if (($trozo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$trozo['tmp_name'])) {
            throw new RuntimeException(t('No llegó el trozo del fichero.'));
        }
        $de = fopen((string)$trozo['tmp_name'], 'rb');
        $a  = fopen($fichero, 'ab');
        if ($de === false || $a === false || !flock($a, LOCK_EX)) {
            throw new RuntimeException(t('No se puede escribir en la carpeta \'{dir}\'.', ['dir' => dirname($fichero)]));
        }
        try {
            // Con el bloqueo: si el navegador reintentó mientras este llegaba, el otro ve lo escrito
            clearstatcache(true, $fichero);
            if ((int)filesize($fichero) === $desde) {
                stream_copy_to_stream($de, $a);
                fflush($a);
            }
        } finally {
            flock($a, LOCK_UN);
            fclose($a);
            fclose($de);
        }
        clearstatcache(true, $fichero);
        return (int)filesize($fichero);
    }

    /**
     * El fichero que trae el formulario de importación: subido entero en
     * $campo, subido por trozos (subida, subida_tamano) o de la carpeta de
     * importar (servidor).
     *
     * @return array{0:string,1:string,2:bool} ruta, nombre original y si es una subida por trozos (se borra al acabar)
     */
    public static function fichero(string $campo): array
    {
        $id = post('subida');
        if ($id !== '') {
            $ruta = self::ficheroDeSubida($id);
            clearstatcache(true, $ruta);
            if (!is_file($ruta) || (int)filesize($ruta) !== (int)post('subida_tamano')) {
                throw new RuntimeException(t('El fichero no ha llegado entero. Vuelve a elegirlo: la subida sigue desde donde se quedó.'));
            }
            return [$ruta, basename(post('subida_nombre')) ?: $id, true];
        }
        $servidor = post('servidor');
        if ($servidor !== '') {
            if (!isset(self::delServidor()[$servidor])) {
                throw new RuntimeException(t('El fichero \'{f}\' no está en la carpeta de importar.', ['f' => $servidor]));
            }
            return [self::carpeta() . '/' . $servidor, $servidor, false];
        }
        $subido = $_FILES[$campo] ?? null;
        if (!is_array($subido) || ($subido['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string)$subido['tmp_name'])) {
            throw new RuntimeException(t('No llegó ningún fichero. Comprueba que no supera el límite de subida de PHP (upload_max_filesize y post_max_size).'));
        }
        return [(string)$subido['tmp_name'], basename((string)$subido['name']), false];
    }

    /** Borra la subida por trozos ya importada. */
    public static function borrar(string $ruta, bool $porTrozos): void
    {
        if ($porTrozos && is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /** Un nombre de la carpeta de importar: sin rutas y sin ficheros ocultos ni de protección. */
    private static function nombreValido(string $f): bool
    {
        return $f !== '' && $f[0] !== '.' && $f === basename($f) && strcasecmp($f, 'web.config') !== 0
            && !preg_match('/[\\\\\/\x00-\x1f]/', $f);
    }

    private static function ficheroDeSubida(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new RuntimeException(t('Identificador de subida no válido.'));
        }
        $dir = self::carpeta() . '/.subidas';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(t('No se puede crear la carpeta \'{dir}\'.', ['dir' => $dir]));
        }
        return "$dir/$id.part";
    }

    /** Las subidas que se quedaron a medias hace más de dos días. */
    private static function caducar(): void
    {
        foreach ((array)glob(self::carpeta() . '/.subidas/*.part') as $f) {
            if (is_file((string)$f) && (int)filemtime((string)$f) < time() - self::CADUCIDAD) {
                @unlink((string)$f);
            }
        }
    }
}
