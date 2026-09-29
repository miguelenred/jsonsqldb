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
