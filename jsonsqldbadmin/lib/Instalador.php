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
            [PHP_VERSION_ID >= 80000 ? (PHP_VERSION_ID >= 80100 ? true : null) : false, 'PHP ' . PHP_VERSION
                . (PHP_VERSION_ID < 80000 ? ' (hace falta 8.0 o posterior)'
                   : (PHP_VERSION_ID < 80100 ? ': sin fsync(), un corte de luz puede perder unos 30 s de escrituras; se recomienda 8.1 o posterior' : ''))],
            [is_dir($datos) && is_writable($datos), 'Carpeta de usuarios y auditoría del panel '
                . (is_dir($datos) && is_writable($datos) ? 'con permiso de escritura' : 'sin permiso de escritura: ' . $datos)],
            [is_writable($dirConfig), is_writable($dirConfig)
                ? 'Se puede escribir config.php'
                : 'No se puede escribir config.php en ' . $dirConfig . ': dale permiso de escritura mientras instalas'],
            [self::motorDetectado() !== '' ? true : null, self::motorDetectado() !== ''
                ? 'Motor encontrado junto al panel'
                : 'No hay motor junto al panel: solo servirá la conexión por API a otra máquina'],
            [function_exists('curl_init') ? true : null, function_exists('curl_init')
                ? 'cURL disponible' : 'Sin cURL: la API se llamará con las funciones de flujo de PHP'],
            [$https ? true : null, $https ? 'Conexión HTTPS'
                : 'Estás instalando por HTTP: la contraseña viaja sin cifrar. En tu máquina da igual; en un servidor, usa HTTPS'],
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
        $modo     = (string)($d['conexion'] ?? '');
        $usuario  = trim((string)($d['usuario'] ?? ''));
        $clave    = (string)($d['clave'] ?? '');
        $permitirHttp = !empty($d['http']);

        if (!in_array($modo, ['directa', 'api'], true)) {
            throw new RuntimeException('Elige cómo se conecta el panel con el motor.');
        }
        // Si ya hay usuarios (se borró config.php para reconfigurar), se
        // conservan y el asistente solo rehace la conexión
        $crearUsuario = !Auth::hayUsuarios();
        if ($crearUsuario) {
            self::comprobarClaves($clave, (string)($d['clave2'] ?? ''));
            Auth::validarNuevo($usuario, $clave);
        }
        if (!is_writable(dirname(self::rutaConfig()))) {
            throw new RuntimeException('No se puede escribir ' . self::rutaConfig()
                . ': da permiso de escritura a la carpeta del panel mientras instalas.');
        }

        $valores = ['ADMIN_CONEXION' => $modo, 'ADMIN_EXIGIR_HTTPS' => !$permitirHttp];
        $resumen = [];
        $apiNueva = null;

        if ($modo === 'directa') {
            $ruta = rtrim(str_replace('\\', '/', trim((string)($d['motor'] ?? ''))), '/');
            if ($ruta === '') {
                $ruta = self::motorDetectado();
            }
            $bases = self::probarDirecta($ruta);
            // La ruta normal no se escribe: así el panel sigue funcionando si se
            // mueve la carpeta entera de jsonSQLDB
            $valores['ADMIN_MOTOR_RUTA'] = $ruta === self::motorDetectado() ? '' : $ruta;
            $valores['ADMIN_API_KEY'] = '';
            $valores['ADMIN_HMAC_SECRET'] = '';
            $resumen = ['Conexión' => 'Directa al motor', 'Motor' => $ruta, 'Bases encontradas' => (string)$bases];
        } else {
            $url      = trim((string)($d['api_url'] ?? ''));
            $generar  = !empty($d['api_generar']) && !is_file(self::rutaConfigApi());
            if ($generar) {
                // La API de esta misma instalación, sin configurar todavía: se
                // crea su configuración con claves nuevas, y la de su cuenta de
                // administración es la del panel
                $claveApi   = bin2hex(random_bytes(32));
                $secretoApi = bin2hex(random_bytes(32));
                $apiNueva   = self::configApi($claveApi, $secretoApi, $permitirHttp);
            } else {
                $claveApi   = trim((string)($d['api_key'] ?? ''));
                $secretoApi = trim((string)($d['api_secret'] ?? ''));
                if ($claveApi === '' || $secretoApi === '') {
                    throw new RuntimeException('Indica la API key y el secreto HMAC de la cuenta de administración.');
                }
                $bases = Api::probar($url !== '' ? $url : Api::url(), $claveApi, $secretoApi);
                $resumen['Bases encontradas'] = (string)$bases;
            }
            $valores['ADMIN_API_KEY'] = $claveApi;
            $valores['ADMIN_HMAC_SECRET'] = $secretoApi;
            if ($url !== '') {
                $valores['ADMIN_API_URL'] = $url;
            }
            $resumen = ['Conexión' => 'API', 'URL' => $url !== '' ? $url : Api::url()] + $resumen;
            if ($generar) {
                $resumen['Configuración de la API'] = 'creada con claves nuevas';
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
        $resumen['HTTPS'] = $permitirHttp ? 'no exigido (solo para pruebas en local)' : 'exigido';
        return $resumen;
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
            throw new RuntimeException('Las dos contraseñas no coinciden.');
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
            'export_max'       => ['ADMIN_EXPORT_MAX', 1000, 10000000],
        ];
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
        $modo = (string)($d['conexion'] ?? ADMIN_CONEXION);
        if (!in_array($modo, ['api', 'directa'], true)) {
            throw new RuntimeException('Conexión no válida.');
        }
        $v['ADMIN_CONEXION'] = $modo;
        $motor = rtrim(str_replace('\\', '/', trim((string)($d['motor'] ?? ''))), '/');
        $v['ADMIN_MOTOR_RUTA'] = $motor === self::motorDetectado() ? '' : $motor;
        $url = trim((string)($d['api_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) {
            throw new RuntimeException('La URL de la API tiene que empezar por http:// o https://.');
        }
        $v['ADMIN_API_URL'] = $url;
        foreach ([['api_key', 'ADMIN_API_KEY'], ['api_secret', 'ADMIN_HMAC_SECRET'],
                  ['lectura_key', 'ADMIN_API_KEY_LECTURA'], ['lectura_secret', 'ADMIN_HMAC_SECRET_LECTURA']] as [$campo, $c]) {
            $nuevo = trim((string)($d[$campo] ?? ''));
            $v[$c] = $nuevo !== '' ? $nuevo : (string)constant($c);
        }
        if (!empty($d['quitar_lectura'])) {
            $v['ADMIN_API_KEY_LECTURA'] = $v['ADMIN_HMAC_SECRET_LECTURA'] = '';
        }
        if (($v['ADMIN_API_KEY_LECTURA'] === '') !== ($v['ADMIN_HMAC_SECRET_LECTURA'] === '')) {
            throw new RuntimeException('La clave de solo lectura necesita también su secreto, o ninguno de los dos.');
        }
        $ca = trim((string)($d['ssl_ca'] ?? ''));
        if ($ca !== '' && !is_file($ca)) {
            throw new RuntimeException("No existe el fichero del certificado: $ca");
        }
        $v['ADMIN_SSL_CA'] = $ca;
        $v['ADMIN_SSL_AUTOFIRMADO'] = !empty($d['ssl_autofirmado']);

        // --- Números con su rango
        foreach (self::ajustes() as $campo => [$c, $min, $max]) {
            $n = filter_var($d[$campo] ?? null, FILTER_VALIDATE_INT);
            if ($n === false || $n < $min || $n > $max) {
                throw new RuntimeException("'" . str_replace('_', ' ', $campo) . "' tiene que ser un número entre $min y $max.");
            }
            $v[$c] = $n;
        }
        $sep = (string)($d['csv_separador'] ?? ';');
        if ($sep === 'tab') {
            $sep = "\t";
        }
        if (!in_array($sep, [';', ',', "\t"], true)) {
            throw new RuntimeException('El separador del CSV tiene que ser punto y coma, coma o tabulador.');
        }
        $v['ADMIN_CSV_SEPARADOR'] = $sep;

        // --- Seguridad: nada que deje fuera a quien lo está cambiando
        $ips = [];
        foreach (preg_split('/[\s,]+/', trim((string)($d['ips'] ?? ''))) ?: [] as $ip) {
            if ($ip === '') {
                continue;
            }
            [$dir, $bits] = array_pad(explode('/', $ip, 2), 2, null);
            $esV6 = filter_var($dir, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (filter_var($dir, FILTER_VALIDATE_IP) === false
                || ($bits !== null && (!ctype_digit($bits) || (int)$bits > ($esV6 ? 128 : 32)))) {
                throw new RuntimeException("No es una IP ni un rango válido: $ip");
            }
            $ips[] = $ip;
        }
        if ($ips !== [] && !util_ip_permitida(util_ip(), $ips)) {
            throw new RuntimeException('Tu IP (' . util_ip() . ') no está en la lista: te quedarías fuera. Añádela.');
        }
        $v['ADMIN_IPS_PERMITIDAS'] = $ips;
        $v['ADMIN_EXIGIR_HTTPS'] = !empty($d['exigir_https']);
        if ($v['ADMIN_EXIGIR_HTTPS'] && !util_https()) {
            throw new RuntimeException('Estás entrando por HTTP: exigir HTTPS te dejaría fuera. Entra por HTTPS y actívalo desde ahí.');
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
                        throw new RuntimeException('En esa carpeta no está jsonSQLDB: tiene que contener engine/ y config.php.');
                    }
                } else {
                    self::probarDirecta($ruta);
                }
            } else {
                if (strpos($v['ADMIN_API_KEY'], 'CHANGE_ME') === 0 || $v['ADMIN_API_KEY'] === '' || $v['ADMIN_HMAC_SECRET'] === '') {
                    throw new RuntimeException('Para conectar por la API hacen falta la API key y el secreto de administración.');
                }
                Api::probar($url !== '' ? $url : Api::urlDeducida(), $v['ADMIN_API_KEY'], $v['ADMIN_HMAC_SECRET']);
            }
        }
        $texto = (string)@file_get_contents(self::rutaConfig());
        if ($texto === '') {
            throw new RuntimeException('No se puede leer ' . self::rutaConfig() . '.');
        }
        $cambiados = [];
        foreach ($cambios as $c) {
            $cambiados[$c] = $v[$c];
        }
        self::escribir(self::rutaConfig(), self::fijar($texto, $cambiados));
        return $cambios;
    }

    /**
     * Pone valores en un config.php: sustituye el define() de cada constante,
     * y si el fichero es de una versión que no la tenía, la añade al final.
     */
    private static function fijar(string $texto, array $valores): string
    {
        foreach ($valores as $constante => $valor) {
            $literal = is_array($valor)
                ? '[' . implode(', ', array_map(static fn($x) => var_export($x, true), $valor)) . ']'
                : var_export($valor, true);
            $patron = "/define\\('" . $constante . "',\\s*(?:[^;]|\\n)*?\\);/";
            $texto  = preg_replace_callback($patron,
                static fn(): string => "define('" . $constante . "', " . $literal . ');', $texto, 1, $n) ?? $texto;
            if ($n !== 1) {
                $texto = rtrim($texto) . "\n\ndefined('" . $constante . "') || define('" . $constante . "', " . $literal . ");\n";
            }
        }
        return $texto;
    }

    /** Carga el motor de esa carpeta y lista sus bases. Devuelve cuántas hay. */
    private static function probarDirecta(string $ruta): int
    {
        if ($ruta === '' || !is_file("$ruta/engine/bootstrap.php") || !is_file("$ruta/config.php")) {
            throw new RuntimeException('En esa carpeta no está jsonSQLDB: tiene que contener engine/ y config.php.');
        }
        defined('JSONSQLDB_CONEXION_DIRECTA') || define('JSONSQLDB_CONEXION_DIRECTA', true);
        require_once "$ruta/config.php";
        require_once "$ruta/engine/bootstrap.php";
        $datos = (string)JSONSQLDB_DATA_PATH;
        if (!is_dir($datos) || !is_writable($datos)) {
            throw new RuntimeException("La carpeta de datos del motor ($datos) no existe o no tiene permiso de escritura.");
        }
        try {
            return count(\JsonSQLDB\Database::consultarGlobal('SHOW DATABASES'));
        } catch (Throwable $e) {
            throw new RuntimeException('El motor no responde: ' . $e->getMessage());
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
            $patron = "/define\\('" . $constante . "',\\s*(?:[^;]|\\n)*?\\);/";
            $nuevo  = "define('" . $constante . "', " . var_export($valor, true) . ');';
            $texto  = preg_replace($patron, $nuevo, $texto, 1, $n) ?? $texto;
            if ($n !== 1) {
                throw new RuntimeException("La plantilla config.dist.php no tiene $constante: está incompleta.");
            }
        }
        return $texto;
    }

    /** Configuración de la API a partir de su plantilla, con claves al azar. */
    private static function configApi(string $claveAdmin, string $secretoAdmin, bool $permitirHttp): string
    {
        $plantilla = dirname(self::rutaConfigApi()) . '/jsonsqldb_api_config.dist.php';
        if (!is_file($plantilla)) {
            throw new RuntimeException('Falta api/jsonsqldb_api_config.dist.php: no se puede crear la configuración de la API.');
        }
        if (!is_writable(dirname(self::rutaConfigApi()))) {
            throw new RuntimeException('No se puede escribir en la carpeta api/: dale permiso de escritura mientras instalas, '
                . 'o crea su configuración con «php configurar.php» y usa aquí su clave de administración.');
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
            throw new RuntimeException("No se pudo escribir $ruta. Comprueba los permisos de la carpeta.");
        }
        // Contiene las claves de acceso: en un hosting compartido, los demás
        // usuarios de la máquina no deben poder leerlo
        @chmod($ruta, 0600);
    }
}
