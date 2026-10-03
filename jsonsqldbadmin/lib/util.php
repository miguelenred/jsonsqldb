<?php
declare(strict_types=1);

/**
 * Versión del proyecto.
 *
 * La dice el motor, que es de donde sale. El panel no lo carga —habla con él por
 * HTTP— así que si no está disponible se lee el mismo fichero directamente. Lo
 * que no puede haber son dos sitios que sepan la versión por su cuenta: así es
 * como el índice de la documentación se quedó tres versiones atrás.
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

/** Campo oculto con el token CSRF. */
/** El nonce de esta respuesta para los <script> de la página (ver la CSP en index.php). */
function nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

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
        $reenviada = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($reenviada !== '') {
            $primera = trim(explode(',', $reenviada)[0]);
            if (filter_var($primera, FILTER_VALIDATE_IP) !== false) {
                return $primera;
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
 * con ?idioma=xx (ver index.php).
 */
function selectorIdioma(): string
{
    $out = '<nav class="lang-switch" aria-label="' . h(t('Idioma')) . '">';
    foreach (Idioma::DISPONIBLES as $codigo => $nombre) {
        $actual = Idioma::actual() === $codigo;
        $out .= '<a href="' . h(url(['idioma' => $codigo] + $_GET)) . '" hreflang="' . $codigo . '" lang="' . $codigo . '"'
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

/** Valor de $_POST como texto. */
function post(string $nombre, string $defecto = ''): string
{
    $v = $_POST[$nombre] ?? $defecto;
    return is_scalar($v) ? trim((string)$v) : $defecto;
}

/** Valor de $_GET como texto. */
function get(string $nombre, string $defecto = ''): string
{
    $v = $_GET[$nombre] ?? $defecto;
    return is_scalar($v) ? trim((string)$v) : $defecto;
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
 * Carpeta en disco de una base de datos, comprobada.
 * Solo la usa la copia en ZIP.
 */
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
    return $nombre === '.turno' || (str_starts_with($nombre, '.') && str_ends_with($nombre, '.lock'));
}

/** La carpeta de una base si el panel la alcanza (misma máquina que el motor), o null. */
function rutaDeLaBaseSiSeAlcanza(string $base): ?string
{
    try {
        return rutaDeLaBase($base);
    } catch (Throwable $e) {
        return null;
    }
}

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
              ['base' => $base, 'ruta' => $raiz])
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
