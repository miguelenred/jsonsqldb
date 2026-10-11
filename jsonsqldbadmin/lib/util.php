<?php
declare(strict_types=1);

/**
 * Funciones comunes del panel.
 *
 * https://miguelenred.es/jsonsqldb
 */

/**
 * La dirección de un fichero de assets/ con la fecha del fichero detrás
 * (?v=…): al actualizar el panel, el navegador pide el nuevo en vez de seguir
 * con el que tenía guardado (un panel.js viejo dejaba botones sin hacer nada).
 */
function recurso(string $fichero): string
{
    return 'assets/' . $fichero . '?v=' . (string)@filemtime(dirname(__DIR__) . '/assets/' . $fichero);
}

/**
 * Versión del proyecto.
 *
 * La dice el motor, que es de donde sale. El panel no lo carga —habla con él por
 * HTTP— así que si no está disponible se lee el mismo fichero directamente. Lo
 * que no puede haber son dos sitios que sepan la versión por su cuenta: así es
 * como el índice de la documentación se quedó tres versiones atrás.
 */
function version(): string
{
    static $v = null;
    if ($v === null) {
        if (class_exists(\JsonSQLDB\Config::class)) {
            $v = \JsonSQLDB\Config::version();
            return $v === 'desconocida' ? '' : $v;
        }
        $f = dirname(__DIR__, 2) . '/VERSION';
        $v = is_file($f) ? trim((string)file_get_contents($f)) : '';
    }
    return $v;
}

/** Escapa para HTML. Se usa en TODA salida que venga de datos o del usuario. */
function h($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** El nonce de esta respuesta para los <script> de la página (ver la CSP en index.php). */
function nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

/** Campo oculto con el token CSRF. */
function csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h(Auth::csrf()) . '">';
}

/**
 * IP del cliente. Solo mira las cabeceras del proxy si se ha declarado que hay
 * uno de confianza: de lo contrario cualquiera podría falsearla.
 */
function util_ip(): string
{
    if (ADMIN_CONFIAR_EN_PROXY) {
        // La última de la lista, la que pone el proxy (2.9): la primera la
        // escribe el cliente, y con ella se saltaba la lista de IPs
        // permitidas y el bloqueo por intentos, o bloqueaba la IP de otro
        $reenviada = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($reenviada !== '') {
            $lista  = explode(',', $reenviada);
            $ultima = trim((string)end($lista));
            if (filter_var($ultima, FILTER_VALIDATE_IP) !== false) {
                return $ultima;
            }
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '-');
}

/** ¿La petición ha llegado por HTTPS? */
function util_https(): bool
{
    if (($_SERVER['HTTPS'] ?? 'off') !== 'off' && ($_SERVER['HTTPS'] ?? '') !== '') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    return ADMIN_CONFIAR_EN_PROXY
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * ¿La IP está en la lista? Admite IP suelta y rango CIDR, IPv4 e IPv6.
 * Lista vacía = no se filtra.
 *
 * @param string[] $lista
 */
function util_ip_permitida(string $ip, array $lista): bool
{
    if ($lista === []) {
        return true;
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($lista as $entrada) {
        $entrada = trim((string)$entrada);
        if ($entrada === '') {
            continue;
        }
        if (strpos($entrada, '/') === false) {
            if (@inet_pton($entrada) === $bin) {
                return true;
            }
            continue;
        }
        [$red, $bits] = explode('/', $entrada, 2);
        $redBin = @inet_pton(trim($red));
        $bits   = (int)$bits;
        if ($redBin === false || strlen($redBin) !== strlen($bin) || $bits < 0) {
            continue;
        }
        $bytes = intdiv($bits, 8);
        $resto = $bits % 8;
        if ($bytes > 0 && strncmp($bin, $redBin, $bytes) !== 0) {
            continue;
        }
        if ($resto === 0) {
            return true;
        }
        $mascara = chr((0xFF << (8 - $resto)) & 0xFF);
        if (isset($bin[$bytes], $redBin[$bytes])
            && (($bin[$bytes] & $mascara) === ($redBin[$bytes] & $mascara))) {
            return true;
        }
    }
    return false;
}

/** URL del propio panel con los parámetros indicados. */
function url(array $params = []): string
{
    $base = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    return $params === [] ? $base : $base . '?' . http_build_query($params);
}

/** Redirige y termina. */
function redirigir(array $params = []): void
{
    header('Location: ' . url($params));
    exit;
}

/**
 * El selector de idioma: un enlace por idioma a la página en la que se está,
 * con ?idioma=xx (ver index.php). Con alguien dentro, el enlace lleva además
 * el token que deja guardar el idioma en su usuario (2.9).
 */
function selectorIdioma(): string
{
    $token = Auth::identificado() ? ['token' => Auth::tokenIdioma()] : [];
    $out = '<nav class="lang-switch" aria-label="' . h(t('Idioma')) . '">';
    foreach (Idioma::DISPONIBLES as $codigo => $nombre) {
        $actual = Idioma::actual() === $codigo;
        $out .= '<a href="' . h(url(['idioma' => $codigo] + $token + $_GET)) . '" hreflang="' . $codigo . '" lang="' . $codigo . '"'
              . ' title="' . h($nombre) . '"' . ($actual ? ' class="active" aria-current="true"' : '') . '>'
              . strtoupper($codigo) . '</a>';
    }
    return $out . '</nav>';
}

/** Guarda un mensaje para la siguiente página. */
function flash(string $tipo, string $texto): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'texto' => $texto];
}

/** Saca los mensajes pendientes y los borra. */
function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    if (isset($_SESSION['aviso'])) {
        $f[] = ['tipo' => 'warning', 'texto' => (string)$_SESSION['aviso']];
        unset($_SESSION['aviso']);
    }
    return $f;
}

/**
 * Un campo de un formulario ($_POST, $_GET o una de sus listas) como texto,
 * tal cual, sin quitar espacios (las contraseñas van así); $defecto si falta
 * o no es un valor simple (2.9): con (string), uno enviado como lista
 * (campo[]=x) daba el aviso «Array to string conversion» y valía «Array», y
 * se llegaba a programar una copia de la base «Array».
 */
function textoDe(array $datos, string $nombre, string $defecto = ''): string
{
    $v = $datos[$nombre] ?? $defecto;
    return is_scalar($v) ? (string)$v : $defecto;
}

/** Valor de $_POST como texto. */
function post(string $nombre, string $defecto = ''): string
{
    return trim(textoDe($_POST, $nombre, $defecto));
}

/** Valor de $_GET como texto. */
function get(string $nombre, string $defecto = ''): string
{
    return trim(textoDe($_GET, $nombre, $defecto));
}

/** Comprueba un identificador de tabla, columna o restricción. */
function identificador(string $valor, string $que): string
{
    // Lo mismo que admite el motor: hasta 64 caracteres. Una columna, como en
    // SQLite, puede llevar espacios o signos (engine/Catalog.php); va siempre
    // entre comillas con cita()
    $re = $que === 'columna' ? '/^(?=.*\S)[^\x00-\x1f\x7f]{1,64}$/u' : '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
    if (!preg_match($re, $valor)) {
        throw new RuntimeException(t('Nombre de {que} no válido: \'{valor}\'', ['que' => t($que), 'valor' => $valor]));
    }
    return $valor;
}

/**
 * Una columna como clave de un campo de formulario (name="valor[...]"), en
 * hexadecimal (2.9): PHP corta esa clave en el primer «]», y una columna
 * llamada «id]x» llegaba como «id», otra columna.
 */
function campo(string $columna): string
{
    return bin2hex($columna);
}

/** La columna de la clave de un campo de formulario (ver campo()); una que no está en hexadecimal no vale. */
function columnaDeCampo(string $clave): string
{
    return identificador(preg_match('/^(?:[0-9a-f]{2})+$/', $clave) === 1 ? (string)hex2bin($clave) : '', 'columna');
}

/** Comprueba un nombre de base de datos. */
function nombreBase(string $valor): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $valor)) {
        throw new RuntimeException(t('Nombre de base de datos no válido: \'{valor}\'', ['valor' => $valor]));
    }
    return $valor;
}

/** Minúsculas sin depender de mbstring (hostings limitados). */
function minus(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** Recorta una celda larga para el listado de datos. */
function celda($valor): string
{
    if ($valor === null) {
        return '<span class="text-body-tertiary fst-italic">NULL</span>';
    }
    if (is_bool($valor)) {
        $valor = $valor ? 1 : 0;
    }
    $texto = (string)$valor;
    $max   = (int)ADMIN_CELDA_MAX;
    if (function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') > $max : strlen($texto) > $max) {
        $corte = function_exists('mb_substr') ? mb_substr($texto, 0, $max, 'UTF-8') : substr($texto, 0, $max);
        return '<span title="' . h($texto) . '">' . h($corte) . '…</span>';
    }
    return h($texto);
}

/**
 * Un DEFAULT que se calcula al insertar, como va detrás de DEFAULT: la fecha
 * de ahora tal cual (CURRENT_TIMESTAMP, CURRENT_DATE, CURRENT_TIME) y una
 * expresión entre paréntesis, sin ponerle otros si ya los tiene.
 */
function defectoCalculado(string $sql): string
{
    $sql = trim($sql);
    if (preg_match('/^CURRENT_(TIMESTAMP|DATE|TIME)$/i', $sql)) {
        return strtoupper($sql);
    }
    // ¿El primer paréntesis cierra al final? Sin contar los de dentro de un texto
    $nivel = 0;
    $n     = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $ch = $sql[$i];
        if ($ch === "'" || $ch === '"') {
            $fin = strpos($sql, $ch, $i + 1);
            while ($fin !== false && ($sql[$fin + 1] ?? '') === $ch) {      // '' dentro de un texto
                $fin = strpos($sql, $ch, $fin + 2);
            }
            if ($fin === false) {
                break;
            }
            $i = $fin;
            continue;
        }
        if ($ch === '(') {
            $nivel++;
        } elseif ($ch === ')' && --$nivel === 0 && $i < $n - 1) {
            break;
        }
        if ($nivel === 0 && $i === 0) {
            break;
        }
    }
    return $i === $n && $nivel === 0 && ($sql[0] ?? '') === '(' ? $sql : '(' . $sql . ')';
}

/** Declaración de tipo a partir de los campos del formulario. */
function tipoSql(string $tipo, string $longitud, string $escala): string
{
    $tipo = strtoupper(identificador($tipo, 'tipo'));
    if ($tipo === 'DECIMAL' || $tipo === 'NUMERIC') {
        $e = $escala === '' ? '2' : (string)(int)$escala;
        return "DECIMAL(10,$e)";
    }
    if (($tipo === 'TEXT' || $tipo === 'VARCHAR') && $longitud !== '') {
        return 'VARCHAR(' . (int)$longitud . ')';
    }
    return $tipo;
}

/**
 * WHERE que busca un texto en todas las columnas a la vez.
 * El texto viaja como parámetro ligado; los nombres de columna se citan.
 *
 * @param array<int,array<string,mixed>> $columnas filas de SHOW SCHEMA
 * @return array{0:string,1:array} ['' , []] si no hay filtro
 */
function condicionFiltro(array $columnas, string $filtro): array
{
    $filtro = trim($filtro);
    if ($filtro === '' || $columnas === []) {
        return ['', []];
    }
    $partes = [];
    $params = [];
    foreach ($columnas as $c) {
        $partes[] = cita((string)$c['columna']) . ' LIKE ?';
        $params[] = '%' . $filtro . '%';
    }
    return [' WHERE ' . implode(' OR ', $partes), $params];
}

/**
 * Ejecuta $fn con el bloqueo exclusivo de una base: el mismo que coge el motor
 * (engine/Storage.php, los ficheros .turno y .lock de su carpeta), así que
 * mientras dura no hay ninguna consulta ni escritura en ella, y las que llegan
 * esperan. Para copiar la base al ZIP o restaurarla sin mezclar ficheros de
 * dos momentos. $fn no puede usar el motor: esperaría a este mismo bloqueo.
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function conBaseBloqueada(string $ruta, callable $fn)
{
    if (!is_dir($ruta) && !@mkdir($ruta, 0775, true) && !is_dir($ruta)) {
        throw new RuntimeException(t('No se puede crear la carpeta \'{dir}\'.', ['dir' => $ruta]));
    }
    $torno = @fopen("$ruta/.turno", 'c');
    $lock  = @fopen("$ruta/.lock", 'c');
    if ($torno === false || $lock === false) {
        throw new RuntimeException(t('No se puede bloquear la base para copiarla o restaurarla: comprueba los permisos de su carpeta.'));
    }
    // Como el motor: por el torno, para no quedarse esperando detrás de un
    // goteo de lecturas
    flock($torno, LOCK_EX);
    flock($lock, LOCK_EX);
    flock($torno, LOCK_UN);
    try {
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        fclose($torno);
    }
}

/** ¿Es un fichero de bloqueo del motor (.lock, .turno, .tabla.lock)? No se copian ni se borran. */
function esFicheroDeBloqueo(string $nombre): bool
{
    // .turno de la base y .<tabla>.turno de cada tabla, y los .lock
    return str_starts_with($nombre, '.') && (str_ends_with($nombre, '.turno') || str_ends_with($nombre, '.lock'));
}

/** La carpeta de una base si el panel la alcanza (misma máquina que el motor), o null. */
function rutaDeLaBaseSiSeAlcanza(string $base): ?string
{
    try {
        return rutaDeLaBase($base);
    } catch (Throwable) {
        return null;
    }
}

/**
 * Carpeta en disco de una base de datos, comprobada: para la copia en ZIP,
 * su restauración y la transacción en la que va una importación.
 */
function rutaDeLaBase(string $base): string
{
    // Antes de mirar el disco: si la API está en otra máquina, los ficheros del
    // motor no están aquí y cualquier ruta local que encontremos sería de otra
    // instalación distinta. Vale más decirlo que copiar la base equivocada.
    $mismoHost = mismoHostQueLaApi();
    if ($mismoHost === false) {
        throw new RuntimeException(
            t('La copia en ZIP necesita que el panel y el motor estén en la misma máquina, porque lee los ficheros directamente del disco. La API está en {api} y el panel se está sirviendo desde {panel}. Usa el volcado en SQL, que va por la API y funciona entre máquinas distintas.',
              ['api' => h(parse_url(Api::url(), PHP_URL_HOST) ?: '?'), 'panel' => h((string)($_SERVER['HTTP_HOST'] ?? '?'))])
        );
    }

    $raiz = trim((string)ADMIN_RUTA_DATOS_MOTOR);
    if ($raiz === '' && Api::directa()) {
        // Con conexión directa el motor está cargado aquí mismo: su carpeta de
        // datos es la de su configuración, sin tener que repetirla en el panel
        Api::cargarMotor();
        $raiz = (string)JSONSQLDB_DATA_PATH;
    }
    if ($raiz === '') {
        $raiz = dirname(__DIR__, 2) . '/data';          // instalación normal
    }
    $ruta = rtrim(str_replace('\\', '/', $raiz), '/') . '/' . $base;

    if (!is_dir($ruta)) {
        throw new RuntimeException(
            t("No se encuentra la carpeta de la base '{base}' en {ruta}. Indica la ruta de la carpeta data/ del motor en ADMIN_RUTA_DATOS_MOTOR, o usa el volcado en SQL, que va por la API y no necesita acceso al disco.",
              ['base' => $base, 'ruta' => $ruta])
        );
    }
    return $ruta;
}

/**
 * ¿El panel y la API se sirven desde la misma máquina?
 *
 * Devuelve null cuando no se puede saber: la URL de la API es relativa al propio
 * panel, o no hay HTTP_HOST (línea de comandos). En ese caso no se bloquea nada.
 */
function mismoHostQueLaApi(): ?bool
{
    $hostApi = parse_url(Api::url(), PHP_URL_HOST);
    $hostAqui = (string)($_SERVER['HTTP_HOST'] ?? '');

    if (!is_string($hostApi) || $hostApi === '' || $hostAqui === '') {
        return null;
    }
    $hostAqui = self_soloHost($hostAqui);
    $hostApi  = self_soloHost($hostApi);

    if ($hostApi === '' || $hostAqui === '') {
        return null;                       // no se puede afirmar nada
    }
    if ($hostApi === $hostAqui) {
        return true;
    }
    // Distintos nombres para la misma máquina: localhost, 127.0.0.1, ::1
    $locales = ['localhost', '127.0.0.1', '::1'];
    if (in_array($hostApi, $locales, true) && in_array($hostAqui, $locales, true)) {
        return true;
    }
    return false;
}

/**
 * Deja un host sin puerto y sin corchetes, en minúsculas.
 *
 * Los corchetes importan: una dirección IPv6 viaja como `[::1]:8080`, y
 * quedarse con lo anterior al primer `:` devolvía `[`. Eso hacía que el panel
 * creyera estar en otra máquina que el motor y se negara a restaurar un ZIP,
 * que es justo lo que pasaba al servir por IPv6.
 */
function self_soloHost(string $host): string
{
    $host = strtolower(trim($host));
    if ($host !== '' && $host[0] === '[') {
        $cierre = strpos($host, ']');
        return $cierre === false ? substr($host, 1) : substr($host, 1, $cierre - 1);
    }
    // Más de un ':' y sin corchetes es una IPv6 escrita a pelo: no lleva
    // puerto, y cortar por el primer ':' la dejaría vacía. Dos vacíos se darían
    // por iguales, y el panel se creería en la misma máquina que el motor.
    if (substr_count($host, ':') > 1) {
        return $host;
    }
    return explode(':', $host)[0];
}

/** Nombre de tabla o columna citado para meterlo en la SQL. */
function cita(string $nombre): string
{
    return '"' . str_replace('"', '""', $nombre) . '"';
}

/** Bytes de una directiva de php.ini como memory_limit o upload_max_filesize ('128M'); -1 o 0 si no tiene límite. */
function bytesIni(string $directiva): int
{
    $v = trim((string)ini_get($directiva));
    $n = (int)$v;
    switch (strtoupper(substr($v, -1))) {
        case 'G': $n *= 1024;
        // no break
        case 'M': $n *= 1024;
        // no break
        case 'K': $n *= 1024;
    }
    return $n;
}

/**
 * Atributos de un formulario de importación para que el navegador suba el
 * fichero por trozos si pasa del límite de subida de PHP (assets/panel.js,
 * lib/Subidas.php).
 */
function atributosSubida(): string
{
    return ' data-trozo="' . Subidas::tamanoTrozo() . '" data-texto-subiendo="' . h(t('Subiendo el fichero: {p} %')) . '"'
         . ' data-texto-error="' . h(t('La subida se ha cortado. Vuelve a elegir el mismo fichero y a pulsar el botón: sigue desde donde se quedó.')) . '"';
}

/**
 * El campo del fichero de una importación y, si hay ficheros dejados por FTP
 * en la carpeta de importar, un desplegable para elegir uno de ellos.
 */
function campoImportacion(string $campo, string $accept, string $id = ''): string
{
    $html = '<input class="form-control" type="file" name="' . h($campo) . '" accept="' . h($accept) . '"'
          . ($id !== '' ? ' id="' . h($id) . '"' : '') . ' style="max-width:22rem">';
    $enServidor = Subidas::delServidor();
    if ($enServidor !== []) {
        $html .= '<select class="form-select" name="servidor" style="max-width:22rem" aria-label="'
               . h(t('O un fichero de la carpeta de importar')) . '"><option value="">'
               . h(t('… o uno de la carpeta de importar')) . '</option>';
        foreach ($enServidor as $nombre => $bytes) {
            $html .= '<option value="' . h($nombre) . '">' . h($nombre) . ' (' . h(Idioma::numero((int)ceil($bytes / 1024))) . ' KB)</option>';
        }
        $html .= '</select>';
    }
    return $html;
}

/**
 * Un fichero temporal nuevo y vacío. En la carpeta temporal del sistema y, si
 * no se puede (un hosting con open_basedir que no la incluye), en la de datos
 * del panel. False si no se puede en ninguna.
 *
 * @return string|false
 */
function ficheroTemporal(string $prefijo)
{
    $f = @tempnam(sys_get_temp_dir(), $prefijo);
    if ($f === false || !is_writable($f)) {
        $f = @tempnam(rtrim(str_replace('\\', '/', (string)ADMIN_DATA_PATH), '/'), $prefijo);
    }
    return $f;
}

// ----------------------------------------------------------------------
// Texto UTF-8 en PHP puro (2.9): el panel no necesita mbstring
// ----------------------------------------------------------------------

/**
 * El error fatal con que se está cerrando PHP (la memoria, el tiempo), o null.
 * Para las funciones de cierre que avisan de una descarga o una importación
 * que se ha cortado, porque esos errores no pasan por ningún catch.
 *
 * @return array{type: int, message: string, file: string, line: int}|null
 */
function errorFatal(): ?array
{
    $e = error_get_last();
    return $e !== null && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) ? $e : null;
}

/** array_is_list() de PHP 8.1, para PHP 8.0: claves 0, 1, 2… en orden */
function array_is_list_compat(array $a): bool
{
    $i = 0;
    foreach ($a as $k => $_) {
        if ($k !== $i++) {
            return false;
        }
    }
    return true;
}

/** ¿Es UTF-8 válido? */
function utf8Valido(string $s): bool
{
    return $s === '' || preg_match('//u', $s) === 1;
}

/** Caracteres (no bytes) de un texto UTF-8 válido. */
function largoUtf8(string $s): int
{
    return strlen($s) - (int)preg_match_all('/[\x80-\xBF]/', $s);
}

/** El carácter UTF-8 de un código Unicode. */
function caracterUtf8(int $c): string
{
    if ($c < 0 || $c > 0x10FFFF) { return ''; }
    if ($c < 0x80) { return chr($c); }
    if ($c < 0x800) { return chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F)); }
    if ($c < 0x10000) { return chr(0xE0 | ($c >> 12)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F)); }
    return chr(0xF0 | ($c >> 18)) . chr(0x80 | (($c >> 12) & 0x3F)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
}

/**
 * Windows-1252 (y Latin-1, que es su parte de 0xA0 a 0xFF) a UTF-8. Los cinco
 * bytes que Windows-1252 deja sin carácter pasan al control del mismo código.
 *
 * @return array<string,string> byte => carácter UTF-8, de 0x80 a 0xFF
 */
function tablaCp1252(): array
{
    static $tabla = null;
    if ($tabla === null) {
        $propios = [0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020,
                    0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
                    0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022,
                    0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
                    0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178];
        $tabla = [];
        for ($b = 0x80; $b <= 0xFF; $b++) {
            $tabla[chr($b)] = caracterUtf8($propios[$b] ?? $b);
        }
    }
    return $tabla;
}

function cp1252AUtf8(string $s): string
{
    return strtr($s, tablaCp1252());
}

/** De UTF-8 a Windows-1252; lo que no tiene byte en Windows-1252, '?' */
function utf8ACp1252(string $s): string
{
    static $inversa = null;
    $inversa ??= array_flip(tablaCp1252());
    return (string)preg_replace_callback('/[^\x00-\x7F]/u', static fn(array $m): string => $inversa[$m[0]] ?? '?', $s);
}

/** UTF-16 (LE o BE, con los pares sustitutos completos) a UTF-8. */
function utf16AUtf8(string $s, bool $bigEndian): string
{
    $unidades = array_values((array)unpack($bigEndian ? 'n*' : 'v*', substr($s, 0, strlen($s) - strlen($s) % 2)));
    $out = '';
    for ($i = 0, $n = count($unidades); $i < $n; $i++) {
        $u = $unidades[$i];
        if ($u >= 0xD800 && $u <= 0xDBFF && $i + 1 < $n && $unidades[$i + 1] >= 0xDC00 && $unidades[$i + 1] <= 0xDFFF) {
            $u = 0x10000 + (($u - 0xD800) << 10) + ($unidades[++$i] - 0xDC00);
        } elseif ($u >= 0xD800 && $u <= 0xDFFF) {
            $u = 0xFFFD;                              // medio par suelto
        }
        $out .= caracterUtf8($u);
    }
    return $out;
}

