<?php
declare(strict_types=1);

/**
 * Asistente del primer arranque de jsonSQLDBadmin.
 *
 * Sustituye a copiar config.dist.php a mano: elige cómo habla el panel con el
 * motor (por la API o por conexión directa), prueba esa conexión ANTES de
 * guardar nada, escribe config.php y crea el usuario administrador.
 *
 * Solo se muestra mientras el panel no está configurado (no hay config.php, o
 * sigue con los valores CHANGE_ME_ de la plantilla) o no tiene ningún usuario.
 * Una vez instalado no hay forma de volver a él desde el navegador: para
 * reconfigurar, se borra config.php.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Instalador
{
    /** Fichero de configuración del panel: el de la variable de entorno, o config.php. */
    public static function rutaConfig(): string
    {
        return (string)(getenv('JSONSQLDBADMIN_CONFIG') ?: dirname(__DIR__) . '/config.php');
    }

    /** Configuración de la API en esta instalación, si el panel está junto a ella. */
    public static function rutaConfigApi(): string
    {
        return dirname(__DIR__, 2) . '/api/jsonsqldb_api_config.php';
    }

    /**
     * ¿Hace falta el asistente completo? Sí si no hay config.php, o si la
     * conexión es por API y la clave o el secreto siguen sin poner: con los
     * valores de la plantilla cualquiera podría entrar, porque están
     * publicados en el repositorio.
     */
    public static function faltaConfig(): bool
    {
        if (!is_file(self::rutaConfig())) {
            return true;
        }
        if (defined('ADMIN_CONEXION') && ADMIN_CONEXION === 'directa') {
            return false;
        }
        foreach ([ADMIN_API_KEY, ADMIN_HMAC_SECRET] as $v) {
            if (trim((string)$v) === '' || strpos((string)$v, 'CHANGE_ME') === 0) {
                return true;
            }
        }
        return false;
    }

    /** Carpeta de jsonSQLDB junto al panel, si está donde se espera; si no, ''. */
    public static function motorDetectado(): string
    {
        $raiz = str_replace('\\', '/', dirname(__DIR__, 2));
        return is_file("$raiz/engine/bootstrap.php") && is_file("$raiz/config.php") ? $raiz : '';
    }

    /**
     * Una ruta del servidor como se enseña antes de instalar, cuando aún no
     * se sabe quién mira (2.9): desde la carpeta del proyecto, sin lo que hay
     * por encima; si está fuera, solo su último nombre.
     */
    public static function rutaVisible(string $ruta): string
    {
        $ruta     = str_replace('\\', '/', $ruta);
        $proyecto = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
        return strncmp($ruta, $proyecto, strlen($proyecto)) === 0 ? substr($ruta, strlen($proyecto)) : '…/' . basename($ruta);
    }

    /**
     * Lo que se comprueba del servidor antes de instalar, para la columna
     * lateral: [estado, texto], con estado true (bien), false (mal) o null
     * (aviso, no impide instalar).
     *
     * @return list<array{0: ?bool, 1: string}>
     */
    public static function comprobaciones(): array
    {
        $datos = (string)ADMIN_DATA_PATH;
        $dirConfig = dirname(self::rutaConfig());
        $https = util_https();
        return [
            [PHP_VERSION_ID >= 80000 ? (PHP_VERSION_ID >= 80100 ? true : null) : false, 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
                . (PHP_VERSION_ID < 80000 ? ' ' . t('(hace falta 8.0 o posterior)')
                   : (PHP_VERSION_ID < 80100 ? ': ' . t('sin fsync(), un corte de luz puede perder unos 30 s de escrituras; se recomienda 8.1 o posterior') : ''))],
            [is_dir($datos) && is_writable($datos), is_dir($datos) && is_writable($datos)
                ? t('Carpeta de usuarios y auditoría del panel con permiso de escritura')
                : t('Carpeta de usuarios y auditoría del panel sin permiso de escritura: {ruta}', ['ruta' => self::rutaVisible($datos)])],
            [is_writable($dirConfig), is_writable($dirConfig)
                ? t('Se puede escribir config.php')
                : t('No se puede escribir config.php en {ruta}: dale permiso de escritura mientras instalas', ['ruta' => self::rutaVisible($dirConfig)])],
            [self::motorDetectado() !== '' ? true : null, self::motorDetectado() !== ''
                ? t('Motor encontrado junto al panel')
                : t('No hay motor junto al panel: solo servirá la conexión por API a otra máquina')],
            [function_exists('curl_init') ? true : null, function_exists('curl_init')
                ? t('cURL disponible') : t('Sin cURL: la API se llamará con las funciones de flujo de PHP')],
            [$https ? true : null, $https ? t('Conexión HTTPS')
                : t('Estás instalando por HTTP: la contraseña viaja sin cifrar. En tu máquina da igual; en un servidor, usa HTTPS')],
            (static function (): array {
                [$bien, $texto] = self::datosExpuestos();
                return [$bien, t('Carpeta de datos: {estado}', ['estado' => $texto])];
            })(),
        ];
    }

    /**
     * Valida el formulario, prueba la conexión y, si todo va bien, escribe la
     * configuración y crea el administrador. Devuelve un resumen para la
     * pantalla final; lanza RuntimeException con un mensaje para el usuario
     * si algo falla, sin haber escrito nada.
     *
     * @return array<string,string>
     */
    public static function instalar(array $d): array
    {
        $modo     = textoDe($d, 'conexion');
        $usuario  = trim(textoDe($d, 'usuario'));
        $clave    = textoDe($d, 'clave');
        $permitirHttp = !empty($d['http']);

        if (!in_array($modo, ['directa', 'api'], true)) {
            throw new RuntimeException(t('Elige cómo se conecta el panel con el motor.'));
        }
        // Si ya hay usuarios (se borró config.php para reconfigurar), se
        // conservan y el asistente solo rehace la conexión
        $crearUsuario = !Auth::hayUsuarios();
        if ($crearUsuario) {
            self::comprobarClaves($clave, textoDe($d, 'clave2'));
            Auth::validarNuevo($usuario, $clave);
        }
        if (!is_writable(dirname(self::rutaConfig()))) {
            throw new RuntimeException(t('No se puede escribir {ruta}: da permiso de escritura a la carpeta del panel mientras instalas.',
                ['ruta' => self::rutaVisible(self::rutaConfig())]));
        }

        $valores = ['ADMIN_CONEXION' => $modo, 'ADMIN_EXIGIR_HTTPS' => !$permitirHttp];
        $resumen = [];
        $apiNueva = null;

        if ($modo === 'directa') {
            $ruta = rtrim(str_replace('\\', '/', trim(textoDe($d, 'motor'))), '/');
            if ($ruta === '') {
                $ruta = self::motorDetectado();
            }
            $bases = self::probarDirecta($ruta);
            // La ruta normal no se escribe: así el panel sigue funcionando si se
            // mueve la carpeta entera de jsonSQLDB
            $valores['ADMIN_MOTOR_RUTA'] = $ruta === self::motorDetectado() ? '' : $ruta;
            $valores['ADMIN_API_KEY'] = '';
            $valores['ADMIN_HMAC_SECRET'] = '';
            $resumen = [t('Conexión') => t('Directa al motor'), t('Motor') => $ruta, t('Bases encontradas') => (string)$bases];
        } else {
            $url      = trim(textoDe($d, 'api_url'));
            $generar  = !empty($d['api_generar']) && !is_file(self::rutaConfigApi());
            if ($generar) {
                // La API de esta misma instalación, sin configurar todavía: se
                // crea su configuración con claves nuevas, y la de su cuenta de
                // administración es la del panel
                $claveApi   = bin2hex(random_bytes(32));
                $secretoApi = bin2hex(random_bytes(32));
                $apiNueva   = self::configApi($claveApi, $secretoApi, $permitirHttp);
            } else {
                $claveApi   = trim(textoDe($d, 'api_key'));
                $secretoApi = trim(textoDe($d, 'api_secret'));
                if ($claveApi === '' || $secretoApi === '') {
                    throw new RuntimeException(t('Indica la API key y el secreto HMAC de la cuenta de administración.'));
                }
                $bases = Api::probar($url !== '' ? $url : Api::url(), $claveApi, $secretoApi);
                $resumen[t('Bases encontradas')] = (string)$bases;
            }
            $valores['ADMIN_API_KEY'] = $claveApi;
            $valores['ADMIN_HMAC_SECRET'] = $secretoApi;
            // Siempre escrita: deducida en cada petición de la cabecera Host, que
            // manda el navegador, un Host manipulado podía llevarse la petición
            // firmada (con la API key). La de ahora la ha comprobado el instalador
            $valores['ADMIN_API_URL'] = $url !== '' ? $url : Api::url();
            $resumen = [t('Conexión') => 'API', 'URL' => $url !== '' ? $url : Api::url()] + $resumen;
            if ($generar) {
                $resumen[t('Configuración de la API')] = t('creada con claves nuevas');
            }
        }

        // Todo comprobado: ahora se escribe. La configuración primero: si luego
        // fallara el usuario, la siguiente visita pide solo el administrador
        $config = self::configPanel($valores);
        if ($apiNueva !== null) {
            self::escribir(self::rutaConfigApi(), $apiNueva);
        }
        self::escribir(self::rutaConfig(), $config);
        if ($crearUsuario) {
            Auth::crear($usuario, $clave, 'admin');
            $resumen['Administrador'] = $usuario;
        }
        $resumen['HTTPS'] = $permitirHttp ? t('no exigido (solo para pruebas en local)') : t('exigido');
        return $resumen;
    }

    /** Fichero con el código de instalación: en datos/, que el servidor no sirve. */
    public const FICHERO_CODIGO = 'codigo-instalacion.txt';

    /**
     * El código de instalación. Mientras no hay administrador, el asistente lo
     * pide: está en un fichero del servidor, así que solo puede terminar la
     * instalación quien tiene acceso al servidor. Antes, el primero que llegaba
     * al panel recién publicado podía crear el administrador. Se crea la
     * primera vez que se pide (o con php configurar.php, que lo muestra).
     */
    public static function codigo(): string
    {
        $ruta = self::rutaCodigo();
        $codigo = is_file($ruta) ? trim((string)file_get_contents($ruta)) : '';
        if (!preg_match('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/', $codigo)) {
            $codigo = implode('-', str_split(bin2hex(random_bytes(8)), 4));
            if (@file_put_contents($ruta, $codigo . "\n", LOCK_EX) === false) {
                throw new RuntimeException(t('No se pudo escribir {destino}', ['destino' => self::rutaVisible($ruta)]));
            }
            @chmod($ruta, 0600);
        }
        return $codigo;
    }

    /** Comprueba el código que se ha escrito en el asistente. */
    public static function comprobarCodigo(string $dado): void
    {
        $dado = strtolower(str_replace([' ', '-'], '', trim($dado)));
        if ($dado === '' || !hash_equals(str_replace('-', '', self::codigo()), $dado)) {
            throw new RuntimeException(t('El código de instalación no es correcto. Está en el fichero {fichero} del servidor.',
                ['fichero' => self::FICHERO_CODIGO]));
        }
    }

    /** Terminada la instalación, el código ya no sirve. */
    public static function borrarCodigo(): void
    {
        @unlink(self::rutaCodigo());
    }

    private static function rutaCodigo(): string
    {
        return Store::ruta(self::FICHERO_CODIGO);  // la carpeta de datos del panel (ADMIN_DATA_PATH)
    }

    /** Solo crea el administrador: la configuración ya está hecha. */
    public static function crearAdmin(string $usuario, string $clave, string $repetida): string
    {
        self::comprobarClaves($clave, $repetida);
        return Auth::crear($usuario, $clave, 'admin');
    }

    private static function comprobarClaves(string $clave, string $repetida): void
    {
        if ($repetida !== '' && $repetida !== $clave) {
            throw new RuntimeException(t('Las dos contraseñas no coinciden.'));
        }
    }

    /**
     * Los ajustes que se pueden cambiar desde la página de Configuración, con
     * cómo se validan. Lo demás (dónde viven los usuarios, el nombre de la
     * cookie) solo se cambia a mano: cambiarlo con el panel en marcha dejaría
     * fuera a quien lo está usando.
     *
     * @return array<string, array{0: string, 1: mixed, 2: mixed}> campo => [constante, mínimo o lista, máximo]
     */
    public static function ajustes(): array
    {
        return [
            'timeout'          => ['ADMIN_TIMEOUT', 5, 600],
            'sesion_minutos'   => ['ADMIN_SESION_MINUTOS', 5, 1440],
            'login_max_fallos' => ['ADMIN_LOGIN_MAX_FALLOS', 3, 50],
            'bloqueo_min'      => ['ADMIN_LOGIN_BLOQUEO_MIN', 1, 1440],
            'bcrypt'           => ['ADMIN_BCRYPT_COSTE', 10, 14],
            'audit_dias'       => ['ADMIN_AUDIT_DIAS', 1, 3650],
            'filas_pagina'     => ['ADMIN_FILAS_PAGINA', 10, 1000],
            'celda_max'        => ['ADMIN_CELDA_MAX', 20, 5000],
        ];
    }

    /** El nombre de un ajuste, como lo muestra la página de Configuración (sin traducir). */
    public static function etiqueta(string $campo): string
    {
        return [
            'timeout'          => 'Tiempo máximo de una llamada a la API (s)',
            'sesion_minutos'   => 'Minutos de inactividad hasta cerrar la sesión',
            'login_max_fallos' => 'Intentos fallidos antes de bloquear',
            'bloqueo_min'      => 'Minutos de bloqueo',
            'bcrypt'           => 'Coste de bcrypt',
            'audit_dias'       => 'Días que se guarda la auditoría',
            'filas_pagina'     => 'Filas por página',
            'celda_max'        => 'Caracteres visibles por celda',
        ][$campo] ?? $campo;
    }

    /**
     * Guarda los cambios de la página de Configuración en config.php. Valida
     * cada valor, se niega a lo que dejaría fuera a quien lo está haciendo
     * (una lista de IPs sin la suya, exigir HTTPS entrando por HTTP), prueba
     * la conexión nueva antes de escribir nada si ha cambiado, y escribe el
     * fichero de una pieza. Devuelve los nombres de lo que ha cambiado; lanza
     * RuntimeException sin haber escrito nada si algo no vale.
     *
     * Los secretos no se muestran nunca: un campo de clave vacío deja la que
     * hay.
     *
     * @return list<string>
     */
    public static function guardarConfiguracion(array $d): array
    {
        $v = [];
        // --- Conexión
        $modo = textoDe($d, 'conexion', ADMIN_CONEXION);
        if (!in_array($modo, ['api', 'directa'], true)) {
            throw new RuntimeException(t('Conexión no válida.'));
        }
        $v['ADMIN_CONEXION'] = $modo;
        $motor = rtrim(str_replace('\\', '/', trim(textoDe($d, 'motor'))), '/');
        $v['ADMIN_MOTOR_RUTA'] = $motor === self::motorDetectado() ? '' : $motor;
        $url = trim(textoDe($d, 'api_url'));
        if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) {
            throw new RuntimeException(t('La URL de la API tiene que empezar por http:// o https://.'));
        }
        // Vacía, la de esta instalación tal como la ve quien guarda ahora: escrita,
        // no deducida en cada petición de la cabecera Host (ver arriba)
        $v['ADMIN_API_URL'] = $url !== '' || $modo !== 'api' ? $url : Api::urlDeducida();
        foreach ([['api_key', 'ADMIN_API_KEY'], ['api_secret', 'ADMIN_HMAC_SECRET'],
                  ['lectura_key', 'ADMIN_API_KEY_LECTURA'], ['lectura_secret', 'ADMIN_HMAC_SECRET_LECTURA']] as [$campo, $c]) {
            $nuevo = trim(textoDe($d, $campo));
            $v[$c] = $nuevo !== '' ? $nuevo : (string)constant($c);
        }
        if (!empty($d['quitar_lectura'])) {
            $v['ADMIN_API_KEY_LECTURA'] = $v['ADMIN_HMAC_SECRET_LECTURA'] = '';
        }
        if (($v['ADMIN_API_KEY_LECTURA'] === '') !== ($v['ADMIN_HMAC_SECRET_LECTURA'] === '')) {
            throw new RuntimeException(t('La clave de solo lectura necesita también su secreto, o ninguno de los dos.'));
        }
        $ca = trim(textoDe($d, 'ssl_ca'));
        if ($ca !== '' && !is_file($ca)) {
            throw new RuntimeException(t('No existe el fichero del certificado: {ca}', ['ca' => $ca]));
        }
        $v['ADMIN_SSL_CA'] = $ca;
        $v['ADMIN_SSL_AUTOFIRMADO'] = !empty($d['ssl_autofirmado']);

        // --- Números con su rango
        foreach (self::ajustes() as $campo => [$c, $min, $max]) {
            $n = filter_var($d[$campo] ?? null, FILTER_VALIDATE_INT);
            if ($n === false || $n < $min || $n > $max) {
                throw new RuntimeException(t("'{campo}' tiene que ser un número entre {min} y {max}.",
                    ['campo' => t(self::etiqueta($campo)), 'min' => $min, 'max' => $max]));
            }
            $v[$c] = $n;
        }
        $sep = textoDe($d, 'csv_separador', ';');
        if ($sep === 'tab') {
            $sep = "\t";
        }
        if (!in_array($sep, [';', ',', "\t"], true)) {
            throw new RuntimeException(t('El separador del CSV tiene que ser punto y coma, coma o tabulador.'));
        }
        $v['ADMIN_CSV_SEPARADOR'] = $sep;

        // --- Seguridad: nada que deje fuera a quien lo está cambiando
        $ips = [];
        foreach (preg_split('/[\s,]+/', trim(textoDe($d, 'ips'))) ?: [] as $ip) {
            if ($ip === '') {
                continue;
            }
            [$dir, $bits] = array_pad(explode('/', $ip, 2), 2, null);
            $esV6 = filter_var($dir, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (filter_var($dir, FILTER_VALIDATE_IP) === false
                || ($bits !== null && (!ctype_digit($bits) || (int)$bits > ($esV6 ? 128 : 32)))) {
                throw new RuntimeException(t('No es una IP ni un rango válido: {ip}', ['ip' => $ip]));
            }
            $ips[] = $ip;
        }
        if ($ips !== [] && !util_ip_permitida(util_ip(), $ips)) {
            throw new RuntimeException('Tu IP (' . util_ip() . ') no está en la lista: te quedarías fuera. Añádela.');
        }
        $v['ADMIN_IPS_PERMITIDAS'] = $ips;
        $v['ADMIN_EXIGIR_HTTPS'] = !empty($d['exigir_https']);
        if ($v['ADMIN_EXIGIR_HTTPS'] && !util_https()) {
            throw new RuntimeException(t('Estás entrando por HTTP: exigir HTTPS te dejaría fuera. Entra por HTTPS y actívalo desde ahí.'));
        }
        $v['ADMIN_CONFIAR_EN_PROXY'] = !empty($d['confiar_proxy']);

        // --- Qué cambia de verdad
        $cambios = [];
        foreach ($v as $c => $nuevo) {
            if (!defined($c) || constant($c) !== $nuevo) {
                $cambios[] = $c;
            }
        }
        if ($cambios === []) {
            return [];
        }
        // Una conexión nueva se prueba antes de guardarla
        $deConexion = ['ADMIN_CONEXION', 'ADMIN_MOTOR_RUTA', 'ADMIN_API_URL', 'ADMIN_API_KEY', 'ADMIN_HMAC_SECRET'];
        if (array_intersect($cambios, $deConexion) !== []) {
            if ($modo === 'directa') {
                $ruta = $motor !== '' ? $motor : self::motorDetectado();
                // En este proceso solo se puede cargar un motor: si ya está
                // cargado el de otra carpeta, se comprueba que la nueva lo es
                if (class_exists('JsonSQLDB\\Database', false) && $ruta !== Api::rutaMotor()) {
                    if (!is_file("$ruta/engine/bootstrap.php") || !is_file("$ruta/config.php")) {
                        throw new RuntimeException(t('En esa carpeta no está jsonSQLDB: tiene que contener engine/ y config.php.'));
                    }
                } else {
                    self::probarDirecta($ruta);
                }
            } else {
                if (strpos($v['ADMIN_API_KEY'], 'CHANGE_ME') === 0 || $v['ADMIN_API_KEY'] === '' || $v['ADMIN_HMAC_SECRET'] === '') {
                    throw new RuntimeException(t('Para conectar por la API hacen falta la API key y el secreto de administración.'));
                }
                Api::probar($url !== '' ? $url : Api::urlDeducida(), $v['ADMIN_API_KEY'], $v['ADMIN_HMAC_SECRET']);
            }
        }
        $texto = (string)@file_get_contents(self::rutaConfig());
        if ($texto === '') {
            throw new RuntimeException(t('No se puede leer {ruta}.', ['ruta' => self::rutaConfig()]));
        }
        $cambiados = [];
        foreach ($cambios as $c) {
            $cambiados[$c] = $v[$c];
        }
        self::escribir(self::rutaConfig(), self::fijar($texto, $cambiados));
        return $cambios;
    }

    /**
     * Pone valores en un config.php: sustituye el valor del define() de cada
     * constante, y si el fichero es de una versión que no la tenía, la añade
     * al final.
     */
    private static function fijar(string $texto, array $valores): string
    {
        foreach ($valores as $constante => $valor) {
            $tramo = self::valorDeDefine($texto, $constante);
            $texto = $tramo === null
                ? rtrim($texto) . "\n\ndefined('" . $constante . "') || define('" . $constante . "', " . self::literal($valor) . ");\n"
                : substr($texto, 0, $tramo[0]) . self::literal($valor) . substr($texto, $tramo[1]);
        }
        return $texto;
    }

    /** Un valor escrito como PHP: una lista, en una línea. */
    private static function literal($valor): string
    {
        return is_array($valor)
            ? '[' . implode(', ', array_map(static fn($x) => var_export($x, true), $valor)) . ']'
            : var_export($valor, true);
    }

    /**
     * Dónde está el valor del define() de $constante en el texto de un
     * config.php: [desde, hasta) en bytes, o null si no está. Con los tokens
     * de PHP (2.9): con una expresión regular, un valor que llevaba «);» o
     * «;» dentro se cortaba, y al guardarlo otra vez su resto quedaba como
     * código fuera del texto.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function valorDeDefine(string $texto, string $constante): ?array
    {
        $piezas = [];
        $pos = 0;
        foreach (token_get_all($texto) as $tk) {
            $s = is_array($tk) ? $tk[1] : $tk;
            if (!is_array($tk) || !in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $piezas[] = [$s, $pos, is_array($tk) ? $tk[0] : null];
            }
            $pos += strlen($s);
        }
        $n = count($piezas);
        for ($i = 0; $i + 4 < $n; $i++) {
            if ($piezas[$i][2] !== T_STRING || strcasecmp($piezas[$i][0], 'define') !== 0 || $piezas[$i + 1][0] !== '('
                || $piezas[$i + 2][2] !== T_CONSTANT_ENCAPSED_STRING || substr($piezas[$i + 2][0], 1, -1) !== $constante
                || $piezas[$i + 3][0] !== ',') {
                continue;
            }
            $nivel = 0;
            for ($k = $i + 4; $k < $n; $k++) {
                $s = $piezas[$k][0];
                if ($s === '(' || $s === '[') {
                    $nivel++;
                } elseif (($s === ')' || $s === ']') && $nivel-- === 0) {
                    $ultima = $piezas[$k - 1];
                    return $k > $i + 4 ? [$piezas[$i + 4][1], $ultima[1] + strlen($ultima[0])] : null;
                }
            }
            return null;
        }
        return null;
    }

    /**
     * ¿Se pueden descargar los ficheros de datos desde fuera? Se pide por HTTP
     * un fichero que siempre está en data/ (su web.config) y se mira qué
     * contesta el servidor: si lo entrega, cualquiera podría descargarse las
     * bases, y es lo que pasa en nginx si no se han puesto sus reglas.
     * Devuelve [estado, texto]: estado true = protegida, false = expuesta,
     * null = no se ha podido saber.
     *
     * @return array{0: ?bool, 1: string}
     */
    public static function datosExpuestos(): array
    {
        if (PHP_SAPI === 'cli-server') {
            return [null, t('no se puede comprobar con el servidor integrado de PHP (php -S)')];
        }
        // La carpeta de datos está junto a api/ en la instalación normal. Si la
        // API está en otro sitio, o el motor en otra carpeta que el panel, no
        // se sabe qué URL tiene data/, y mejor decirlo que dar una respuesta
        // tranquilizadora comprobando una dirección equivocada
        if (Api::directa() && trim((string)ADMIN_MOTOR_RUTA) !== '') {
            return [null, t('no se puede comprobar: el motor está en otra carpeta que el panel')];
        }
        // Solo con la URL de la API escrita en la configuración (2.9): sacada
        // de la cabecera Host, que escribe quien pide, cualquiera, sin haber
        // entrado, hacía que el servidor pidiera lo que quisiera a cualquier
        // máquina y puerto de su red, solo con abrir el asistente
        $api = trim((string)ADMIN_API_URL);
        if ($api === '') {
            return [null, t('sin comprobar hasta que la URL de la API (ADMIN_API_URL) esté en la configuración. Comprueba a mano que data/web.config no se puede descargar')];
        }
        if (!preg_match('#^(https?://.+)/api/[^/?]+\.php$#i', $api, $m)) {
            return [null, t('no se puede comprobar: la API ({api}) no está en la carpeta api/ de la instalación', ['api' => $api])];
        }
        $url = $m[1] . '/data/web.config';
        try {
            // Con el certificado comprobado como en las llamadas a la API (2.9: sin comprobar)
            $codigo = Api::codigoHttp($url);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];                // ADMIN_SSL_CA que no se puede leer
        }
        if ($codigo === 200) {
            return [false, t('DESCARGABLE: {url} responde. Cualquiera puede bajarse las bases. En nginx, añade las reglas de nginx/jsonsqldb.conf; en Apache, comprueba que se leen los .htaccess (AllowOverride)', ['url' => $url])];
        }
        if ($codigo === 401 || $codigo === 403 || $codigo === 404) {
            return [true, t('protegida ({url} responde {codigo})', ['url' => $url, 'codigo' => $codigo])];
        }
        return [null, $codigo !== null ? t('no se ha podido comprobar ({url} responde {codigo})', ['url' => $url, 'codigo' => $codigo])
                                       : t('no se ha podido comprobar ({url} no contesta)', ['url' => $url])];
    }

    /** Carga el motor de esa carpeta y lista sus bases. Devuelve cuántas hay. */
    private static function probarDirecta(string $ruta): int
    {
        if ($ruta === '' || !is_file("$ruta/engine/bootstrap.php") || !is_file("$ruta/config.php")) {
            throw new RuntimeException(t('En esa carpeta no está jsonSQLDB: tiene que contener engine/ y config.php.'));
        }
        defined('JSONSQLDB_CONEXION_DIRECTA') || define('JSONSQLDB_CONEXION_DIRECTA', true);
        require_once "$ruta/config.php";
        require_once "$ruta/engine/bootstrap.php";
        $datos = (string)JSONSQLDB_DATA_PATH;
        if (!is_dir($datos) || !is_writable($datos)) {
            throw new RuntimeException(t('La carpeta de datos del motor ({datos}) no existe o no tiene permiso de escritura.', ['datos' => $datos]));
        }
        try {
            return count(\JsonSQLDB\Database::consultarGlobal('SHOW DATABASES'));
        } catch (Throwable $e) {
            throw new RuntimeException(t('El motor no responde: {error}', ['error' => $e->getMessage()]));
        }
    }

    /**
     * config.php a partir de la plantilla, con los valores del asistente en su
     * sitio. Se sustituye cada define() completo, así que los comentarios de
     * la plantilla se quedan y el fichero sigue explicando cada opción.
     */
    private static function configPanel(array $valores): string
    {
        $texto = (string)file_get_contents(dirname(__DIR__) . '/config.dist.php');
        $texto = preg_replace('/^\/\/ =+\n\/\/ PLANTILLA\..*?\n\/\/ =+\n/sm', '', $texto, 1) ?? $texto;
        foreach ($valores as $constante => $valor) {
            // Por los tokens, como fijar(): con preg_replace, un «$1» en una
            // clave se tomaba por una referencia y desaparecía (2.9)
            $tramo = self::valorDeDefine($texto, $constante);
            if ($tramo === null) {
                throw new RuntimeException(t('La plantilla config.dist.php no tiene {constante}: está incompleta.', ['constante' => $constante]));
            }
            $texto = substr($texto, 0, $tramo[0]) . self::literal($valor) . substr($texto, $tramo[1]);
        }
        return $texto;
    }

    /** Configuración de la API a partir de su plantilla, con claves al azar. */
    private static function configApi(string $claveAdmin, string $secretoAdmin, bool $permitirHttp): string
    {
        $plantilla = dirname(self::rutaConfigApi()) . '/jsonsqldb_api_config.dist.php';
        if (!is_file($plantilla)) {
            throw new RuntimeException(t('Falta api/jsonsqldb_api_config.dist.php: no se puede crear la configuración de la API.'));
        }
        if (!is_writable(dirname(self::rutaConfigApi()))) {
            throw new RuntimeException(t('No se puede escribir en la carpeta api/: dale permiso de escritura mientras instalas, o crea su configuración con «php configurar.php» y usa aquí su clave de administración.'));
        }
        $valores = [
            'CHANGE_ME_ADMIN_API_KEY'   => $claveAdmin,
            'CHANGE_ME_ADMIN_SECRET'    => $secretoAdmin,
            'CHANGE_ME_APP_API_KEY'     => bin2hex(random_bytes(32)),
            'CHANGE_ME_APP_SECRET'      => bin2hex(random_bytes(32)),
            'CHANGE_ME_EXAMPLE_API_KEY' => bin2hex(random_bytes(32)),
            'CHANGE_ME_EXAMPLE_SECRET'  => bin2hex(random_bytes(32)),
        ];
        // Los más largos primero: uno que sea prefijo de otro no se queda a medias
        uksort($valores, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $texto = strtr((string)file_get_contents($plantilla), $valores);
        if ($permitirHttp) {
            $texto = str_replace("define('EXIGIR_HTTPS', true)", "define('EXIGIR_HTTPS', false)", $texto);
        }
        return $texto;
    }

    /** Escribe un fichero de configuración de una pieza y lo deja legible solo por su dueño. */
    private static function escribir(string $ruta, string $texto): void
    {
        $tmp = $ruta . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $texto) === false || !@rename($tmp, $ruta)) {
            @unlink($tmp);
            throw new RuntimeException(t('No se pudo escribir {ruta}. Comprueba los permisos de la carpeta.', ['ruta' => self::rutaVisible($ruta)]));
        }
        // Contiene las claves de acceso: en un hosting compartido, los demás
        // usuarios de la máquina no deben poder leerlo
        @chmod($ruta, 0600);
    }
}
