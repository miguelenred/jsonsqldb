<?php
declare(strict_types=1);

/**
 * Llamadas del panel al motor jsonSQLDB, por una de dos vías:
 *
 *  - API (ADMIN_CONEXION = 'api', la de siempre): POST firmado con HMAC a
 *    api/jsonsqldb_api.php, en esta máquina o en otra.
 *  - Directa (ADMIN_CONEXION = 'directa', desde la 2.7): el panel carga el
 *    motor y le habla sin HTTP. Solo si el panel y los datos están en la misma
 *    máquina; a cambio no hay claves que configurar ni una petición HTTP por
 *    cada consulta.
 *
 * Las dos devuelven lo mismo y fallan con el mismo mensaje, así que las vistas
 * no saben por cuál van. Siempre con parámetros ligados: los valores viajan
 * aparte y el motor los inserta ya analizados, así que nada de lo que el
 * usuario escriba en un formulario puede alterar la sentencia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Api
{
    /** Última URL usada, para los mensajes de error. */
    private static string $url = '';

    /**
     * Ejecuta una sentencia y devuelve las filas (SELECT/SHOW) o
     * ['success'=>true,'filas'=>n,'mensaje'=>'...'].
     *
     * @throws RuntimeException si la API responde con error o no se puede llamar
     */
    /**
     * Clave con la que firmar: la de solo lectura si el usuario que ha entrado
     * tiene ese rol y hay una configurada.
     *
     * Sin esto, el panel firmaba siempre con la clave admin y lo único que
     * impedía escribir a un usuario de lectura era una comprobación de la propia
     * aplicación. Con la clave de lectura, el motor se convierte en la segunda
     * red: aunque un fallo del panel dejara pasar un DELETE, la API lo rechaza.
     *
     * @return array{0: string, 1: string} clave y secreto
     */
    private static function credenciales(): array
    {
        $lectura = trim((string)ADMIN_API_KEY_LECTURA);

        if ($lectura !== '' && class_exists("Auth") && Auth::identificado() && !Auth::esAdmin()) {
            return [$lectura, (string)ADMIN_HMAC_SECRET_LECTURA];
        }
        return [ADMIN_API_KEY, ADMIN_HMAC_SECRET];
    }

    public static function sql(string $base, string $sql, array $params = []): array
    {
        // Un SHOW se repite en la misma página (la barra lateral y la vista
        // piden las mismas tablas): se contesta una vez por petición. Cualquier
        // otra sentencia puede cambiar lo que devuelve, así que lo olvida todo
        $esShow = $params === [] && strncasecmp(ltrim($sql), 'SHOW', 4) === 0;
        if ($esShow && isset(self::$memoShow[$base . "\0" . $sql])) {
            return self::$memoShow[$base . "\0" . $sql];
        }
        if (!$esShow) {
            self::$memoShow = [];
        }
        $r = self::directa() ? self::sqlDirecta($base, $sql, $params) : self::sqlApi($base, $sql, $params);
        if ($esShow) {
            self::$memoShow[$base . "\0" . $sql] = $r;
        }
        return $r;
    }

    /** @var array<string,array> respuestas de SHOW ya pedidas en esta petición */
    private static array $memoShow = [];

    /** Olvida los SHOW ya pedidos: para medir de verdad lo que tarda el motor. */
    public static function olvidarShow(): void
    {
        self::$memoShow = [];
    }

    private static function sqlApi(string $base, string $sql, array $params): array
    {
        $json = $params === [] ? '' : (string)json_encode(array_values($params), JSON_UNESCAPED_UNICODE);
        $ts   = (string)time();

        [$clave, $secreto] = self::credenciales();

        $post = http_build_query([
            'api_key'   => $clave,
            'db'        => $base,
            'sql'       => $sql,
            'params'    => $json,
            'timestamp' => $ts,
            'token'     => hash_hmac('sha256',
                '+' . $clave . '|' . $base . '|' . $ts . '|' . $sql . $json . '¿', $secreto),
        ]);

        $cuerpo = self::enviar($post);
        $datos  = json_decode($cuerpo, true);

        if (!is_array($datos)) {
            throw new RuntimeException(t('Respuesta no válida de la API: {cuerpo}', ['cuerpo' => substr($cuerpo, 0, 300)]));
        }
        if (isset($datos['error'])) {
            // El mensaje es del motor y llega en español; su comienzo, que es
            // de la API, va en el idioma del panel
            $error = (string)$datos['error'];
            throw new RuntimeException(strpos($error, 'Error en la consulta: ') === 0
                ? t('Error en la consulta: ') . substr($error, strlen('Error en la consulta: ')) : $error);
        }
        return $datos;
    }

    /**
     * Prueba una conexión por API con unas credenciales concretas, sin
     * depender de la configuración: es lo que usa el asistente de instalación
     * antes de escribir config.php. Devuelve cuántas bases ve; lanza el error
     * de la API si no responde o rechaza la firma.
     */
    public static function probar(string $url, string $clave, string $secreto): int
    {
        $ts  = (string)time();
        $sql = 'SHOW DATABASES';
        $post = http_build_query([
            'api_key'   => $clave,
            'db'        => '',
            'sql'       => $sql,
            'params'    => '',
            'timestamp' => $ts,
            'token'     => hash_hmac('sha256', '+' . $clave . '||' . $ts . '|' . $sql . '¿', $secreto),
        ]);
        $datos = json_decode(self::enviar($post, $url), true);
        if (!is_array($datos)) {
            throw new RuntimeException(t('La URL no responde como la API de jsonSQLDB: {url}', ['url' => $url]));
        }
        if (isset($datos['error'])) {
            throw new RuntimeException((string)$datos['error']);
        }
        return count($datos);
    }

    /** ¿Va el panel por conexión directa al motor, sin API? */
    public static function directa(): bool
    {
        return defined('ADMIN_CONEXION') && ADMIN_CONEXION === 'directa';
    }

    /**
     * Carpeta de jsonSQLDB (la que contiene engine/ y config.php) para la
     * conexión directa. Vacía en la configuración = la carpeta padre del
     * panel, que es donde está en la instalación normal.
     */
    public static function rutaMotor(): string
    {
        $ruta = defined('ADMIN_MOTOR_RUTA') ? trim((string)ADMIN_MOTOR_RUTA) : '';
        return rtrim(str_replace('\\', '/', $ruta !== '' ? $ruta : dirname(__DIR__, 2)), '/');
    }

    /**
     * Carga el motor para la conexión directa. La conexión directa está
     * desactivada por defecto en el motor; el panel la activa para sí mismo
     * antes de cargar su configuración, que respeta lo que ya esté definido.
     */
    public static function cargarMotor(): void
    {
        if (class_exists('JsonSQLDB\\Database', false)) {
            return;
        }
        $raiz = self::rutaMotor();
        if (!is_file($raiz . '/engine/bootstrap.php') || !is_file($raiz . '/config.php')) {
            throw new RuntimeException(
                t('No se encuentra el motor en {ruta}: falta engine/bootstrap.php o config.php. Indica la carpeta de jsonSQLDB en ADMIN_MOTOR_RUTA.', ['ruta' => $raiz])
            );
        }
        defined('JSONSQLDB_CONEXION_DIRECTA') || define('JSONSQLDB_CONEXION_DIRECTA', true);
        require_once $raiz . '/config.php';
        require_once $raiz . '/engine/bootstrap.php';
    }

    /** Sentencias que puede lanzar un usuario de solo lectura, las mismas que una API key de lectura. */
    private const LECTURA = ['select', 'union', 'show_databases', 'show_tables', 'show_views', 'show_schema',
                             'show_keys', 'show_triggers', 'show_indexes', 'check_keys'];

    /**
     * La consulta por conexión directa. El permiso lo pone el rol de quien ha
     * entrado, con la misma lista que la API aplica a una clave de lectura: el
     * motor rechaza la sentencia antes de ejecutarla, no el panel después.
     */
    private static function sqlDirecta(string $base, string $sql, array $params): array
    {
        self::cargarMotor();
        $lectura   = class_exists('Auth') && Auth::identificado() && !Auth::esAdmin();
        $autorizar = static function (string $tipo) use ($lectura): void {
            if ($lectura && !in_array($tipo, self::LECTURA, true)) {
                throw \JsonSQLDB\JsonSqlDbError::permission(t('Tu usuario solo tiene permiso de lectura'));
            }
        };
        $usuario = class_exists('Auth') ? (string)(Auth::usuario()['usuario'] ?? '') : '';
        \JsonSQLDB\Logger::contexto('jsonSQLDBadmin' . ($usuario !== '' ? " ($usuario)" : ''), util_ip());
        try {
            return $base === ''
                ? \JsonSQLDB\Database::consultarGlobal($sql, array_values($params), $autorizar)
                : (new \JsonSQLDB\Database($base))->consultar($sql, array_values($params), $autorizar);
        } catch (\JsonSQLDB\JsonSqlDbError $e) {
            // El mismo texto que devuelve la API, para que el panel no distinga
            throw new RuntimeException(t('Error en la consulta: ') . $e->sqlState . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /** Como sql(), pero devuelve solo el primer valor de la primera fila. */
    public static function valor(string $base, string $sql, array $params = [])
    {
        $filas = self::sql($base, $sql, $params);
        if ($filas === [] || !is_array($filas[0])) {
            return null;
        }
        return reset($filas[0]);
    }

    /** Lista de bases de datos. */
    public static function bases(): array
    {
        return array_column(self::sql('', 'SHOW DATABASES'), 'base');
    }

    /** URL del endpoint: la de la configuración o la deducida de la petición. */
    public static function url(): string
    {
        if (self::directa()) {
            return '';                      // no hay endpoint: el motor está aquí mismo
        }
        if (self::$url !== '') {
            return self::$url;
        }
        return self::$url = ADMIN_API_URL !== '' ? ADMIN_API_URL : self::urlDeducida();
    }

    /** La URL de la API de esta instalación, deducida de la petición: ../api/jsonsqldb_api.php. */
    public static function urlDeducida(): string
    {
        $https  = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $raiz   = rtrim(dirname(dirname($script)), '/');
        return ($https ? 'https' : 'http') . '://' . $host . $raiz . '/api/jsonsqldb_api.php';
    }

    /** POST al endpoint. Usa cURL si está, si no, el envoltorio de PHP. */
    private static function enviar(string $post, ?string $url = null): string
    {
        $url ??= self::url();
        // Las opciones de certificado solo tienen sentido en HTTPS
        $ca  = stripos($url, 'https://') === 0 ? self::certificado() : '';
        $verificar = $ca !== '' || !ADMIN_SSL_AUTOFIRMADO;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opciones = [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => ADMIN_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => $verificar,
                CURLOPT_SSL_VERIFYHOST => $verificar ? 2 : 0,
            ];
            if ($ca !== '') {
                $opciones[CURLOPT_CAINFO] = $ca;
            }
            curl_setopt_array($ch, $opciones);
            $r     = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if ($r === false) {
                throw new RuntimeException(t('No se pudo llamar a la API ({url}): {error}', ['url' => $url, 'error' => $error]));
            }
            return (string)$r;
        }

        $ssl = $ca !== ''
            ? ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $ca]
            : ['verify_peer'       => $verificar,
               'verify_peer_name'  => $verificar,
               'allow_self_signed' => !$verificar];

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $post,
                'timeout'       => ADMIN_TIMEOUT,
                'ignore_errors' => true,
            ],
            'ssl'  => $ssl,
        ]);
        $r = @file_get_contents($url, false, $ctx);
        if ($r === false) {
            throw new RuntimeException(t('No se pudo llamar a la API ({url})', ['url' => $url]));
        }
        return $r;
    }

    /** Ruta del certificado de confianza, comprobada. '' si no se usa. */
    private static function certificado(): string
    {
        $ca = trim((string)ADMIN_SSL_CA);
        if ($ca === '') {
            return '';
        }
        if (!is_file($ca) || !is_readable($ca)) {
            throw new RuntimeException(
                t('No se puede leer el certificado indicado en ADMIN_SSL_CA: {ca}', ['ca' => $ca])
            );
        }
        return $ca;
    }
}
