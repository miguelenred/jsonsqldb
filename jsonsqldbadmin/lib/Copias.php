<?php
declare(strict_types=1);

/**
 * Copias programadas de las bases: cada programación dice qué base, cada
 * cuánto (cada N horas, cada día o cada semana a una hora), en qué formato
 * (ZIP o volcado SQL) y cuántas copias se conservan; las más antiguas se
 * borran solas.
 *
 * Las copias van a la carpeta copias/ de los datos del panel
 * (ADMIN_DATA_PATH), una subcarpeta por base. Se escriben en un temporal que
 * se renombra al terminar: una copia a medias nunca parece completa.
 *
 * Quién las hace: herramientas/copias-cron.php desde el cron del sistema o el
 * Programador de tareas de Windows; y, sin cron, el propio panel: la página
 * avisa al navegador de que hay una pendiente y este la pide aparte, sin
 * esperar la respuesta (assets/panel.js), así que nadie espera por ella. Un
 * bloqueo impide que se hagan dos a la vez.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Copias
{
    private const FICHERO = 'copias.json';
    private const FORMATOS = ['zip', 'sql'];
    private const FRECUENCIAS = ['horas', 'diaria', 'semanal'];

    /** La carpeta de las copias: copias/ dentro de la de datos del panel. */
    public static function carpeta(): string
    {
        return rtrim(str_replace('\\', '/', (string)ADMIN_DATA_PATH), '/') . '/copias';
    }

    /** @return list<array{id:string,base:string,frecuencia:string,horas:int,hora:int,dia:int,formato:string,conservar:int,ultima:?string,error:?string}> */
    public static function programaciones(): array
    {
        return array_values(Store::leer(self::FICHERO, []));
    }

    /** Valida y guarda una programación nueva. */
    public static function anadir(array $d): array
    {
        $p = [
            'id'         => bin2hex(random_bytes(8)),
            'base'       => nombreBase((string)($d['base'] ?? '')),
            'frecuencia' => in_array($d['frecuencia'] ?? '', self::FRECUENCIAS, true) ? (string)$d['frecuencia'] : 'diaria',
            'horas'      => max(1, min(168, (int)($d['horas'] ?? 24))),
            'hora'       => max(0, min(23, (int)($d['hora'] ?? 3))),
            'dia'        => max(1, min(7, (int)($d['dia'] ?? 1))),
            'formato'    => in_array($d['formato'] ?? '', self::FORMATOS, true) ? (string)$d['formato'] : 'zip',
            'conservar'  => max(1, min(365, (int)($d['conservar'] ?? 7))),
            'ultima'     => null,
            'error'      => null,
        ];
        if ($p['formato'] === 'zip' && !class_exists('ZipArchive')) {
            throw new RuntimeException(t('La extensión zip de PHP no está activada, así que no se puede generar el ZIP. Actívala en php.ini (extension=zip) o usa el volcado en SQL.'));
        }
        Store::actualizar(self::FICHERO, static function (array $todas) use ($p): array {
            $todas[] = $p;
            return $todas;
        });
        return $p;
    }

    public static function borrar(string $id): void
    {
        Store::actualizar(self::FICHERO, static fn(array $todas): array =>
            array_values(array_filter($todas, static fn(array $p): bool => $p['id'] !== $id)));
    }

    /** ¿Le toca a esta programación? */
    public static function toca(array $p, int $ahora): bool
    {
        $ultima = $p['ultima'] === null ? 0 : (int)strtotime((string)$p['ultima']);
        if ($p['frecuencia'] === 'horas') {
            return $ahora - $ultima >= (int)$p['horas'] * 3600;
        }
        // La última vez que debió hacerse: hoy (o el día de la semana) a su hora,
        // o la anterior si esa todavía no ha llegado
        $momento = (int)mktime((int)$p['hora'], 0, 0, (int)date('n', $ahora), (int)date('j', $ahora), (int)date('Y', $ahora));
        if ($p['frecuencia'] === 'semanal') {
            $momento -= (((int)date('N', $ahora) - (int)$p['dia'] + 7) % 7) * 86400;
            if ($momento > $ahora) { $momento -= 7 * 86400; }
        } elseif ($momento > $ahora) {
            $momento -= 86400;
        }
        return $ultima < $momento;
    }

    /** ¿Hay alguna copia pendiente? (barato: solo lee la programación) */
    public static function hayPendientes(): bool
    {
        $ahora = time();
        foreach (self::programaciones() as $p) {
            if (self::toca($p, $ahora)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hace las copias pendientes. Si otro proceso ya las está haciendo, no
     * hace nada. Devuelve un resumen por copia.
     *
     * @return list<string>
     */
    public static function ejecutarPendientes(): array
    {
        return self::correr(static fn(array $p): bool => self::toca($p, time()));
    }

    /** Hace ya la copia de una programación, le toque o no; si falla, lanza el error. */
    public static function ejecutar(string $id): string
    {
        if (!in_array($id, array_column(self::programaciones(), 'id'), true)) {
            throw new RuntimeException(t('Esa programación no existe.'));
        }
        $hechas = self::correr(static fn(array $p): bool => $p['id'] === $id);
        if ($hechas === []) {
            throw new RuntimeException(t('Hay otra copia en marcha: vuelve a intentarlo en un momento.'));
        }
        foreach (self::programaciones() as $p) {
            if ($p['id'] === $id && $p['error'] !== null) {
                throw new RuntimeException($hechas[0]);
            }
        }
        return $hechas[0];
    }

    /**
     * @param callable(array): bool $cuales
     * @return list<string>
     */
    private static function correr(callable $cuales): array
    {
        // Con la conexión directa, el motor primero: su config.php fija la zona
        // horaria, y cargarlo a mitad de la copia cambiaba la hora de lo que
        // viene después (dos copias seguidas salían con horas de dos zonas)
        if (Api::directa()) {
            Api::cargarMotor();
        }
        if (!is_dir(self::carpeta()) && !@mkdir(self::carpeta(), 0775, true) && !is_dir(self::carpeta())) {
            throw new RuntimeException(t('No se puede crear la carpeta \'{dir}\'.', ['dir' => self::carpeta()]));
        }
        $cerrojo = fopen(self::carpeta() . '/.lock', 'c');
        if ($cerrojo === false || !flock($cerrojo, LOCK_EX | LOCK_NB)) {
            return [];                                  // ya hay otra en marcha
        }
        $hechas = [];
        try {
            // Con el cerrojo no hay ninguna otra en marcha: un temporal que quede
            // es de una copia que murió a medias (sin memoria, el proceso matado)
            foreach ((array)glob(self::carpeta() . '/*/.*.tmp') as $huerfano) {
                @unlink((string)$huerfano);
            }
            foreach (self::programaciones() as $p) {
                if (!$cuales($p)) {
                    continue;
                }
                $error = null;
                try {
                    $hechas[] = self::hacer($p);
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    $hechas[] = $p['base'] . ': ' . $error;
                }
                // Hecha o fallida, cuenta como intentada: si no, un error la
                // repetiría en cada visita al panel
                Store::actualizar(self::FICHERO, static function (array $todas) use ($p, $error): array {
                    foreach ($todas as $i => $q) {
                        if ($q['id'] === $p['id']) {
                            // Con su desfase: el cron y el servidor web pueden tener otra zona horaria
                            $todas[$i]['ultima'] = date(DATE_ATOM);
                            $todas[$i]['error']  = $error;
                        }
                    }
                    return $todas;
                });
                Audit::registrar('copia_programada', end($hechas), $p['base']);
            }
        } finally {
            flock($cerrojo, LOCK_UN);
            fclose($cerrojo);
        }
        return $hechas;
    }

    /** Hace una copia y borra las que sobran. */
    private static function hacer(array $p): string
    {
        $dir = self::carpeta() . '/' . $p['base'];
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(t('No se puede crear la carpeta \'{dir}\'.', ['dir' => $dir]));
        }
        $nombre = $p['base'] . '-' . date('Ymd-His') . ($p['formato'] === 'zip' ? '.zip' : '.sql');
        $tmp = "$dir/.$nombre.tmp";
        try {
            Api::sql($p['base'], 'SHOW TABLES');        // que la base exista y la clave pueda leerla
            if ($p['formato'] === 'zip') {
                Exportar::zipEn($p['base'], rutaDeLaBase($p['base']), $tmp);
            } else {
                Exportar::volcadoEn($p['base'], $tmp);
            }
            if (!@rename($tmp, "$dir/$nombre")) {
                throw new RuntimeException(t('No se puede escribir en la carpeta \'{dir}\'.', ['dir' => $dir]));
            }
        } finally {
            if (is_file($tmp)) { @unlink($tmp); }
        }
        // Las más antiguas, fuera
        $todas = self::ficheros($p['base'], $p['formato']);
        foreach (array_slice($todas, 0, max(0, count($todas) - (int)$p['conservar'])) as $vieja) {
            @unlink("$dir/$vieja");
        }
        return t('{base}: copia {fichero} ({kb} KB)', ['base' => $p['base'], 'fichero' => $nombre,
            'kb' => Idioma::numero((int)ceil((int)filesize("$dir/$nombre") / 1024))]);
    }

    /**
     * Las copias que hay de una base, de la más antigua a la más reciente
     * según la fecha del fichero (no según el nombre: el cron y el servidor
     * web pueden tener otra zona horaria); con $formato, solo las de ese formato.
     *
     * @return list<string>
     */
    public static function ficheros(string $base, ?string $formato = null): array
    {
        $out = [];
        foreach ((array)glob(self::carpeta() . '/' . nombreBase($base) . '/*') as $f) {
            $n = basename((string)$f);
            if (is_file((string)$f) && $n[0] !== '.' && ($formato === null || str_ends_with($n, '.' . $formato))) {
                $out[$n] = (int)filemtime((string)$f);
            }
        }
        uksort($out, static fn(string $a, string $b): int => [$out[$a], $a] <=> [$out[$b], $b]);
        return array_keys($out);
    }

    /** La hora de la última copia, en la zona horaria de esta petición. */
    public static function ultima(array $p): string
    {
        return $p['ultima'] === null ? t('nunca') : date('Y-m-d H:i:s', (int)strtotime((string)$p['ultima']));
    }

    /** La ruta de una copia, comprobando que es de esa base y no sale de su carpeta. */
    public static function ruta(string $base, string $fichero): string
    {
        if (!in_array($fichero, self::ficheros($base), true)) {
            throw new RuntimeException(t('Esa copia no existe.'));
        }
        return self::carpeta() . '/' . $base . '/' . $fichero;
    }
}
