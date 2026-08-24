<?php
declare(strict_types=1);

/** Versión del proyecto, leída del fichero VERSION de la raíz. */
function version(): string
{
    static $v = null;
    if ($v === null) {
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
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $valor)) {
        throw new RuntimeException("Nombre de $que no válido: '$valor'");
    }
    return $valor;
}

/** Comprueba un nombre de base de datos. */
function nombreBase(string $valor): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $valor)) {
        throw new RuntimeException("Nombre de base de datos no válido: '$valor'");
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
function rutaDeLaBase(string $base): string
{
    // Antes de mirar el disco: si la API está en otra máquina, los ficheros del
    // motor no están aquí y cualquier ruta local que encontremos sería de otra
    // instalación distinta. Vale más decirlo que copiar la base equivocada.
    $mismoHost = mismoHostQueLaApi();
    if ($mismoHost === false) {
        throw new RuntimeException(
            'La copia en ZIP necesita que el panel y el motor estén en la misma máquina, '
            . 'porque lee los ficheros directamente del disco. La API está en '
            . h(parse_url(Api::url(), PHP_URL_HOST) ?: '?') . ' y el panel se está sirviendo desde '
            . h((string)($_SERVER['HTTP_HOST'] ?? '?')) . '. Usa el volcado en SQL, que va por la API '
            . 'y funciona entre máquinas distintas.'
        );
    }

    $raiz = trim((string)ADMIN_RUTA_DATOS_MOTOR);
    if ($raiz === '') {
        $raiz = dirname(__DIR__, 2) . '/data';          // instalación normal
    }
    $ruta = rtrim(str_replace('\\', '/', $raiz), '/') . '/' . $base;

    if (!is_dir($ruta)) {
        throw new RuntimeException(
            "No se encuentra la carpeta de la base '$base' en $raiz. Indica la ruta de la "
            . 'carpeta data/ del motor en ADMIN_RUTA_DATOS_MOTOR, o usa el volcado en SQL, '
            . 'que va por la API y no necesita acceso al disco.'
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
    // HTTP_HOST puede traer el puerto; el host de la URL no
    $hostAqui = strtolower(explode(':', $hostAqui)[0]);
    $hostApi  = strtolower($hostApi);

    if ($hostApi === $hostAqui) {
        return true;
    }
    // Distintos nombres para la misma máquina: localhost, 127.0.0.1, ::1
    $locales = ['localhost', '127.0.0.1', '::1', '[::1]'];
    if (in_array($hostApi, $locales, true) && in_array($hostAqui, $locales, true)) {
        return true;
    }
    return false;
}

/** Nombre de tabla o columna citado para meterlo en la SQL. */
function cita(string $nombre): string
{
    return '"' . str_replace('"', '""', $nombre) . '"';
}
