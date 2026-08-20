<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Almacenamiento de una base de datos jsonSQLDB.
 *
 * Estructura en disco:
 *   <raiz>/<base>/_database.json      metadatos de la base
 *   <raiz>/<base>/_revs.json          contador de revisión por tabla (invalida caché)
 *   <raiz>/<base>/_views.json         vistas: nombre => SELECT guardado
 *   <raiz>/<base>/<tabla>.meta.json   estructura de la tabla
 *   <raiz>/<base>/<tabla>.json        datos (una fila por línea, legible)
 *   <raiz>/<base>/<tabla>.part2.json  siguientes partes (JSONSQLDB_FILAS_POR_PARTE)
 *   <raiz>/<base>/.cache/             caché serializada (regenerable, borrable)
 *   <raiz>/<base>/.lock               fichero de bloqueo lectura/escritura
 *
 * Concurrencia: un único bloqueo por base de datos.
 *   - lectura  => flock LOCK_SH  (varias a la vez)
 *   - escritura=> flock LOCK_EX  (una sola; los SELECT esperan a que termine)
 */
final class Storage
{
    private const JSON_FILA = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    private const JSON_META = self::JSON_FILA | JSON_PRETTY_PRINT;

    private const RE_BASE  = '/^[A-Za-z0-9_-]{1,64}$/';
    private const RE_TABLA = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    private string $dir;
    private string $dirCache;
    private string $base;

    /** @var resource|null */
    private $lock = null;
    private int  $lockNivel = 0;
    private bool $lockExclusivo = false;

    private ?array $revs = null;
    private bool   $cache;
    private bool   $apcu;
    private string $prefijo;
    private int    $filasPorParte;

    public function __construct(string $raiz, string $base)
    {
        if (!preg_match(self::RE_BASE, $base)) {
            throw JsonSqlDbError::config("Nombre de base de datos no válido: '$base'");
        }
        $dir = rtrim(str_replace('\\', '/', $raiz), '/') . '/' . $base;
        if (!is_dir($dir)) {
            throw JsonSqlDbError::config("La base de datos '$base' no existe");
        }
        $this->dir      = $dir;
        $this->base     = $base;
        $this->dirCache = $dir . '/.cache';
        $this->cache    = Config::cacheActiva();
        $this->apcu     = function_exists('apcu_fetch') && ini_get('apc.enabled') !== '0';
        $this->prefijo  = 'jsq:' . substr(md5($dir), 0, 12) . ':';
        $this->filasPorParte = Config::filasPorParte();
    }

    // ------------------------------------------------------------------
    // Bases de datos
    // ------------------------------------------------------------------

    /** Lista las bases de datos existentes bajo la raíz de datos. */
    public static function bases(string $raiz): array
    {
        $raiz = rtrim(str_replace('\\', '/', $raiz), '/');
        if (!is_dir($raiz)) {
            return [];
        }
        $out = [];
        foreach ((array)scandir($raiz) as $e) {
            if ($e !== '.' && $e !== '..' && preg_match(self::RE_BASE, $e)
                && is_file("$raiz/$e/_database.json")) {
                $out[] = $e;
            }
        }
        sort($out);
        return $out;
    }

    /** Crea la carpeta de una base de datos con su fichero de metadatos y protecciones. */
    public static function crearBase(string $raiz, string $base): void
    {
        if (!preg_match(self::RE_BASE, $base)) {
            throw JsonSqlDbError::config("Nombre de base de datos no válido: '$base'");
        }
        $raiz = rtrim(str_replace('\\', '/', $raiz), '/');
        $dir  = "$raiz/$base";
        if (is_dir($dir)) {
            throw JsonSqlDbError::config("La base de datos '$base' ya existe");
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw JsonSqlDbError::io("No se puede crear la carpeta de la base '$base'");
        }
        // Protección si la carpeta acaba estando dentro del webroot
        @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
        @file_put_contents("$dir/web.config",
            "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security>"
            . "<authorization><deny users=\"*\" /></authorization>"
            . "</security></system.webServer></configuration>\n");

        $info = [
            'database'   => $base,
            'engine'     => 'jsonSQLDB',
            'version'    => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (@file_put_contents("$dir/_database.json", json_encode($info, self::JSON_META) . "\n") === false) {
            throw JsonSqlDbError::io("No se puede escribir _database.json en '$base'");
        }
    }

    /** Borra por completo una base de datos y todo su contenido. */
    public static function borrarBase(string $raiz, string $base): void
    {
        if (!preg_match(self::RE_BASE, $base)) {
            throw JsonSqlDbError::config("Nombre de base de datos no válido: '$base'");
        }
        $dir = rtrim(str_replace('\\', '/', $raiz), '/') . '/' . $base;
        if (!is_file("$dir/_database.json")) {
            throw JsonSqlDbError::config("La base de datos '$base' no existe");
        }
        self::borrarRecursivo($dir);
    }

    private static function borrarRecursivo(string $dir): void
    {
        foreach ((array)scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $ruta = "$dir/$e";
            is_dir($ruta) ? self::borrarRecursivo($ruta) : @unlink($ruta);
        }
        @rmdir($dir);
    }

    public function nombre(): string { return $this->base; }
    public function dir(): string    { return $this->dir; }

    // ------------------------------------------------------------------
    // Bloqueo
    // ------------------------------------------------------------------

    /**
     * Adquiere el bloqueo de la base. Reentrante: las llamadas anidadas
     * incrementan el nivel y deben ser compatibles con el modo ya adquirido.
     */
    public function bloquear(bool $exclusivo): void
    {
        if ($this->lockNivel > 0) {
            if ($exclusivo && !$this->lockExclusivo) {
                throw JsonSqlDbError::lock('No se puede escribir dentro de un bloqueo de lectura');
            }
            $this->lockNivel++;
            return;
        }

        $fichero = $this->dir . '/.lock';
        $fh = @fopen($fichero, 'c');
        if ($fh === false) {
            throw JsonSqlDbError::io("No se puede abrir el fichero de bloqueo de '{$this->base}'");
        }
        if (!flock($fh, $exclusivo ? LOCK_EX : LOCK_SH)) {
            fclose($fh);
            throw JsonSqlDbError::lock("No se puede bloquear la base '{$this->base}'");
        }
        $this->lock          = $fh;
        $this->lockNivel     = 1;
        $this->lockExclusivo = $exclusivo;
        $this->revs          = null;   // releer revisiones dentro del bloqueo
    }

    /** Libera el bloqueo (solo cuando se cierra el último nivel). */
    public function desbloquear(): void
    {
        if ($this->lockNivel === 0) {
            return;
        }
        if (--$this->lockNivel > 0) {
            return;
        }
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock          = null;
        $this->lockExclusivo = false;
        $this->revs          = null;
    }

    public function enEscritura(): bool
    {
        return $this->lockNivel > 0 && $this->lockExclusivo;
    }

    private function exigirEscritura(): void
    {
        if (!$this->enEscritura()) {
            throw JsonSqlDbError::lock('Operación de escritura sin bloqueo exclusivo');
        }
    }

    // ------------------------------------------------------------------
    // Tablas
    // ------------------------------------------------------------------

    /** Nombres de las tablas existentes. */
    public function tablas(): array
    {
        $out = [];
        foreach ((array)glob($this->dir . '/*.meta.json') as $f) {
            $n = basename($f, '.meta.json');
            if (preg_match(self::RE_TABLA, $n)) {
                $out[] = $n;
            }
        }
        sort($out);
        return $out;
    }

    public function existe(string $tabla): bool
    {
        self::validarTabla($tabla);
        return is_file($this->ficheroMeta($tabla));
    }

    public static function validarTabla(string $tabla): void
    {
        if (!preg_match(self::RE_TABLA, $tabla)) {
            throw JsonSqlDbError::schema("Nombre de tabla no válido: '$tabla'");
        }
    }

    /** Estructura de una tabla. */
    public function leerMeta(string $tabla): array
    {
        self::validarTabla($tabla);
        $clave = $this->claveCache($tabla, 'm');
        $meta  = $this->cacheLeer($clave);
        if ($meta !== null) {
            return $meta;
        }
        $fichero = $this->ficheroMeta($tabla);
        if (!is_file($fichero)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        $meta = json_decode((string)file_get_contents($fichero), true);
        if (!is_array($meta)) {
            throw JsonSqlDbError::io("Estructura ilegible en '$tabla.meta.json'");
        }
        $this->cacheGuardar($clave, $meta);
        return $meta;
    }

    /** Guarda la estructura de una tabla e invalida su caché. */
    public function guardarMeta(string $tabla, array $meta): void
    {
        self::validarTabla($tabla);
        $this->exigirEscritura();
        $meta['updated_at'] = date('Y-m-d H:i:s');
        $this->escribirAtomico($this->ficheroMeta($tabla), json_encode($meta, self::JSON_META) . "\n");
        $rev = $this->subirRev($tabla);
        $this->cacheGuardar($this->claveCache($tabla, 'm', $rev), $meta);
    }

    // ------------------------------------------------------------------
    // Vistas
    // ------------------------------------------------------------------

    /**
     * Vistas de la base: nombre => ['sql' => ..., 'created_at' => ...].
     * Una vista es solo un SELECT guardado; no tiene datos propios.
     *
     * @return array<string,array>
     */
    public function leerVistas(): array
    {
        $f = $this->dir . '/_views.json';
        if (!is_file($f)) {
            return [];
        }
        $v = json_decode((string)@file_get_contents($f), true);
        return is_array($v) ? $v : [];
    }

    /** @param array<string,array> $vistas */
    public function guardarVistas(array $vistas): void
    {
        $this->exigirEscritura();
        $this->escribirAtomico($this->dir . '/_views.json', json_encode($vistas, self::JSON_META) . "\n");
    }

    /** Todas las filas de una tabla (concatenando las partes). */
    public function leerFilas(string $tabla): array
    {
        self::validarTabla($tabla);
        $clave = $this->claveCache($tabla, 'd');
        $filas = $this->cacheLeer($clave);
        if ($filas !== null) {
            return $filas;
        }

        $filas = [];
        for ($parte = 1; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                break;
            }
            $json = json_decode((string)file_get_contents($fichero), true);
            if (!is_array($json) || !isset($json['rows']) || !is_array($json['rows'])) {
                throw JsonSqlDbError::io("Datos ilegibles en " . basename($fichero));
            }
            foreach ($json['rows'] as $fila) {
                $filas[] = $fila;
            }
        }
        $this->cacheGuardar($clave, $filas);
        return $filas;
    }

    /** Reescribe todas las filas de una tabla e invalida su caché. */
    public function guardarFilas(string $tabla, array $filas): void
    {
        self::validarTabla($tabla);
        $this->exigirEscritura();

        $filas  = array_values($filas);
        $partes = $filas === [] ? [[]] : array_chunk($filas, $this->filasPorParte);

        foreach ($partes as $i => $bloque) {
            $this->escribirAtomico(
                $this->ficheroDatos($tabla, $i + 1),
                $this->codificarTabla($tabla, $bloque)
            );
        }
        // Eliminar partes sobrantes de una escritura anterior más grande
        for ($parte = count($partes) + 1; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                break;
            }
            @unlink($fichero);
        }

        $rev = $this->subirRev($tabla);
        $this->cacheGuardar($this->claveCache($tabla, 'd', $rev), $filas);
    }

    /** Crea los ficheros de una tabla nueva. */
    public function crearTabla(string $tabla, array $meta): void
    {
        self::validarTabla($tabla);
        $this->exigirEscritura();
        if ($this->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' ya existe");
        }
        $this->guardarFilas($tabla, []);
        $this->guardarMeta($tabla, $meta);
    }

    /** Borra estructura, datos y caché de una tabla. */
    public function borrarTabla(string $tabla): void
    {
        self::validarTabla($tabla);
        $this->exigirEscritura();
        @unlink($this->ficheroMeta($tabla));
        for ($parte = 1; ; $parte++) {
            $fichero = $this->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                break;
            }
            @unlink($fichero);
        }
        $this->limpiarCache($tabla);
        $revs = $this->revs();
        unset($revs[$tabla]);
        $this->guardarRevs($revs);
    }

    /** Renombra una tabla (ficheros + entrada de revisión). */
    public function renombrarTabla(string $desde, string $hasta): void
    {
        self::validarTabla($desde);
        self::validarTabla($hasta);
        $this->exigirEscritura();
        if (!$this->existe($desde)) {
            throw JsonSqlDbError::schema("La tabla '$desde' no existe");
        }
        if ($this->existe($hasta)) {
            throw JsonSqlDbError::schema("La tabla '$hasta' ya existe");
        }

        $meta = $this->leerMeta($desde);
        $filas = $this->leerFilas($desde);
        $meta['table'] = $hasta;

        $this->borrarTabla($desde);
        $this->guardarFilas($hasta, $filas);
        $this->guardarMeta($hasta, $meta);
    }

    // ------------------------------------------------------------------
    // Ficheros
    // ------------------------------------------------------------------

    private function ficheroMeta(string $tabla): string
    {
        return $this->dir . '/' . $tabla . '.meta.json';
    }

    private function ficheroDatos(string $tabla, int $parte): string
    {
        return $this->dir . '/' . $tabla . ($parte > 1 ? '.part' . $parte : '') . '.json';
    }

    /** Fichero de datos legible: cabecera indentada y una fila por línea. */
    private function codificarTabla(string $tabla, array $filas): string
    {
        $out = "{\n  \"table\": " . json_encode($tabla, self::JSON_FILA) . ",\n  \"rows\": [";
        $sep = "\n    ";
        foreach ($filas as $fila) {
            $json = json_encode($fila, self::JSON_FILA);
            if ($json === false) {
                throw JsonSqlDbError::io("No se puede codificar una fila de '$tabla' a JSON");
            }
            $out .= $sep . $json;
            $sep = ",\n    ";
        }
        return $out . ($filas === [] ? "]\n}\n" : "\n  ]\n}\n");
    }

    /** Escritura atómica: fichero temporal + rename, sin dejar restos. */
    private function escribirAtomico(string $fichero, string $contenido): void
    {
        $tmp = $fichero . '.' . getmypid() . '.tmp';
        try {
            if (@file_put_contents($tmp, $contenido) === false) {
                throw JsonSqlDbError::io('No se puede escribir ' . basename($fichero));
            }
            if (!@rename($tmp, $fichero)) {
                @unlink($fichero);              // Windows: rename falla si el destino existe
                if (!@rename($tmp, $fichero)) {
                    throw JsonSqlDbError::io('No se puede reemplazar ' . basename($fichero));
                }
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // ------------------------------------------------------------------
    // Revisiones y caché
    // ------------------------------------------------------------------

    /** Revisión actual de cada tabla; cambia en cada escritura e invalida la caché. */
    private function revs(): array
    {
        if ($this->revs === null) {
            $fichero = $this->dir . '/_revs.json';
            $json    = is_file($fichero) ? json_decode((string)file_get_contents($fichero), true) : [];
            $this->revs = is_array($json) ? $json : [];
        }
        return $this->revs;
    }

    private function subirRev(string $tabla): int
    {
        $revs = $this->revs();
        $rev  = ((int)($revs[$tabla] ?? 0)) + 1;
        $revs[$tabla] = $rev;
        $this->guardarRevs($revs);
        $this->limpiarCache($tabla);
        return $rev;
    }

    private function guardarRevs(array $revs): void
    {
        $this->revs = $revs;
        $this->escribirAtomico($this->dir . '/_revs.json', json_encode($revs, self::JSON_META) . "\n");
    }

    private function claveCache(string $tabla, string $tipo, ?int $rev = null): string
    {
        $rev ??= (int)($this->revs()[$tabla] ?? 0);
        return $this->prefijo . $tabla . ':' . $tipo . ':' . $rev;
    }

    private function cacheLeer(string $clave)
    {
        if (!$this->cache) {
            return null;
        }
        if ($this->apcu) {
            $ok  = false;
            $val = apcu_fetch($clave, $ok);
            return $ok ? $val : null;
        }
        $fichero = $this->ficheroCache($clave);
        if (!is_file($fichero)) {
            return null;
        }
        $val = @unserialize((string)file_get_contents($fichero), ['allowed_classes' => false]);
        return $val === false ? null : $val;
    }

    private function cacheGuardar(string $clave, $valor): void
    {
        if (!$this->cache) {
            return;
        }
        if ($this->apcu) {
            apcu_store($clave, $valor);
            return;
        }
        if (!is_dir($this->dirCache) && !@mkdir($this->dirCache, 0775, true) && !is_dir($this->dirCache)) {
            return;   // sin caché en disco: el motor sigue funcionando
        }
        $this->escribirAtomico($this->ficheroCache($clave), serialize($valor));
    }

    /** Elimina todas las entradas de caché de una tabla (cualquier revisión). */
    private function limpiarCache(string $tabla): void
    {
        if ($this->apcu) {
            $rev = (int)($this->revs()[$tabla] ?? 0);
            foreach (['m', 'd'] as $tipo) {
                for ($r = max(0, $rev - 1); $r <= $rev; $r++) {
                    apcu_delete($this->prefijo . $tabla . ':' . $tipo . ':' . $r);
                }
            }
            return;
        }
        foreach ((array)glob($this->dirCache . '/' . md5($this->prefijo . $tabla) . '.*.cache') as $f) {
            @unlink($f);
        }
    }

    private function ficheroCache(string $clave): string
    {
        // md5(prefijo+tabla) agrupa las entradas de una misma tabla para poder borrarlas juntas
        $partes = explode(':', $clave);
        $rev    = array_pop($partes);
        $tipo   = array_pop($partes);
        $tabla  = array_pop($partes);
        return $this->dirCache . '/' . md5($this->prefijo . $tabla) . '.' . $tipo . $rev . '.cache';
    }
}
