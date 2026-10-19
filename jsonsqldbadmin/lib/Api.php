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

    /**
     * Ejecuta una sentencia y devuelve las filas (SELECT/SHOW) o
     * ['success'=>true,'filas'=>n,'mensaje'=>'...'].
     *
     * @throws RuntimeException si la API responde con error o no se puede llamar
     */
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
        $r = isset(self::$transacciones[$base])
            ? self::enMotor(static fn(callable $autorizar) => self::$transacciones[$base]->consultar($sql, array_values($params), $autorizar))
            : (self::directa() ? self::sqlDirecta($base, $sql, $params) : self::sqlApi($base, $sql, $params));
        if ($esShow) {
            self::$memoShow[$base . "\0" . $sql] = $r;
        }
        return $r;
    }

    /** @var array<string,array> respuestas de SHOW ya pedidas en esta petición */
    private static array $memoShow = [];

    /** @var array<string, \JsonSQLDB\Database> base => la de su transacción abierta (ver enTransaccion()) */
    private static array $transacciones = [];

    /**
     * Ejecuta $fn con la base en una transacción del motor, cargado en este
     * proceso (2.9): lo que $fn haga sobre esa base con sql() va dentro, y si
     * falla se deshace entero. Es lo que hace todo o nada una importación
     * cuando el panel alcanza la carpeta de la base ($raiz es la de datos del
     * motor), también si va por la API, como la copia en ZIP. Mientras dura,
     * la base está bloqueada: las demás peticiones esperan, y deshacer no se
     * lleva nada que hayan escrito otros.
     *
     * @return mixed lo que devuelva $fn
     */
    public static function enTransaccion(string $base, string $raiz, callable $fn)
    {
        self::cargarMotor();
        $db = new \JsonSQLDB\Database($base, $raiz);
        try {
            return $db->transaccion(static function () use ($db, $base, $fn) {
                self::$transacciones[$base] = $db;
                self::$memoShow = [];
                try {
                    return $fn();
                } finally {
                    unset(self::$transacciones[$base]);
                    self::$memoShow = [];
                }
            });
        } catch (\JsonSQLDB\JsonSqlDbError $e) {
            throw new RuntimeException(t('Error en la consulta: ') . $e->sqlState . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /** ¿Va por HTTP lo que se haga ahora con esta base? No con conexión directa ni en una transacción de enTransaccion(). */
    public static function porHttp(string $base): bool
    {
        return !self::directa() && !isset(self::$transacciones[$base]);
    }

    /** Olvida los SHOW ya pedidos: para medir de verdad lo que tarda el motor. */
    public static function olvidarShow(): void
    {
        self::$memoShow = [];
    }

    private static function sqlApi(string $base, string $sql, array $params): array
    {
        $json = $params === [] ? '' : (string)json_encode(array_values($params), JSON_UNESCAPED_UNICODE);
        return self::pedirApi($base, ['sql' => $sql, 'params' => $json], $sql . $json);
    }

    /**
     * Una petición firmada a la API: $campos (sql y params, o lote) y lo que de
     * ellos entra en la firma. Devuelve la respuesta, o lanza su error.
     *
     * @param array<string, string> $campos
     */
    private static function pedirApi(string $base, array $campos, string $firmado): array
    {
        $ts    = (string)time();
        // Al azar en cada petición (2.9): sin él, dos sentencias iguales en el
        // mismo segundo daban el mismo token y la API rechazaba la segunda
        $nonce = bin2hex(random_bytes(8));

        [$clave, $secreto] = self::credenciales();

        $post = http_build_query(['api_key' => $clave, 'db' => $base] + $campos + [
            'timestamp' => $ts,
            'nonce'     => $nonce,
            'token'     => hash_hmac('sha256',
                '+' . $clave . '|' . $base . '|' . $ts . '|' . $nonce . '|' . $firmado . '¿', $secreto),
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
        $ts    = (string)time();
        $sql   = 'SHOW DATABASES';
        $nonce = bin2hex(random_bytes(8));
        $post = http_build_query([
            'api_key'   => $clave,
            'db'        => '',
            'sql'       => $sql,
            'params'    => '',
            'timestamp' => $ts,
            'nonce'     => $nonce,
            'token'     => hash_hmac('sha256', '+' . $clave . '||' . $ts . '|' . $nonce . '|' . $sql . '¿', $secreto),
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

    /**
     * Con la conexión directa, las filas de un SELECT una a una a $porFila,
     * según las da el motor (2.9): una exportación las saca de una vez, sin
     * pedirlas por lotes, que con un ORDER BY repetían la consulta entera en
     * cada uno y, en la memoria del panel, acababan por no caber.
     *
     * @param callable(array):void $porFila
     */
    public static function filasDirectas(string $base, string $sql, array $params, callable $porFila): void
    {
        self::$memoShow = [];
        [$r] = self::enMotor(static fn(callable $autorizar): array
            => [(new \JsonSQLDB\Database($base))->consultarPorFilas($sql, array_values($params), $autorizar, $porFila)]);
        if (!is_array($r)) {
            return;                                 // salieron todas por $porFila
        }
        if (isset($r['success'])) {
            throw new RuntimeException(t('Esa sentencia no devuelve filas que exportar.'));
        }
        foreach ($r as $fila) {
            $porFila($fila);                         // las que el motor dio juntas
        }
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
                             'show_keys', 'show_triggers', 'show_indexes', 'show_create', 'check_keys', 'check_tables', 'check_indexes', 'explain'];

    /**
     * Varias sentencias en una transacción (2.9): todas o ninguna. Cada una, un
     * texto SQL o [sql, parámetros]. Devuelve un resultado por sentencia.
     *
     * @param list<string|array{0: string, 1?: array}> $sentencias
     * @return list<mixed>
     */
    public static function lote(string $base, array $sentencias): array
    {
        self::$memoShow = [];
        if (!self::directa()) {
            $lote = (string)json_encode(array_map(static fn($s): array => is_array($s)
                ? ['sql' => (string)$s[0], 'params' => array_values((array)($s[1] ?? []))] : ['sql' => (string)$s, 'params' => []], $sentencias),
                JSON_UNESCAPED_UNICODE);
            return self::pedirApi($base, ['lote' => $lote], $lote);
        }
        return self::enMotor(static fn(callable $autorizar) => (new \JsonSQLDB\Database($base))->lote($sentencias, $autorizar));
    }

    /**
     * La consulta por conexión directa. El permiso lo pone el rol de quien ha
     * entrado, con la misma lista que la API aplica a una clave de lectura: el
     * motor rechaza la sentencia antes de ejecutarla, no el panel después.
     */
    private static function sqlDirecta(string $base, string $sql, array $params): array
    {
        return self::enMotor(static fn(callable $autorizar) => $base === ''
            ? \JsonSQLDB\Database::consultarGlobal($sql, array_values($params), $autorizar)
            : (new \JsonSQLDB\Database($base))->consultar($sql, array_values($params), $autorizar));
    }

    /**
     * Lo que se hace con el motor en este mismo proceso: con los permisos del
     * usuario del panel, su nombre en el registro y los errores con el mismo
     * texto que devuelve la API.
     *
     * @param callable(callable): array $hacer recibe el autorizador
     */
    private static function enMotor(callable $hacer): array
    {
        self::cargarMotor();
        $lectura   = class_exists('Auth') && Auth::identificado() && !Auth::esAdmin();
        $autorizar = static function (string $tipo) use ($lectura): void {
            if ($lectura && !in_array($tipo, self::LECTURA, true)) {
                throw \JsonSQLDB\JsonSqlDbError::permission(t('Tu usuario solo tiene permiso de lectura'));
            }
        };
        $usuario = class_exists('Auth') ? (string)(Auth::usuario()['usuario'] ?? '') : '';
        // Quién y desde dónde, para el registro del motor: con una constante,
        // Logger solo se carga si se escribe una línea (2.10: se cargaba en
        // cada consulta del panel)
        \defined('JSONSQLDB_REGISTRO') || \define('JSONSQLDB_REGISTRO', ['jsonSQLDBadmin' . ($usuario !== '' ? " ($usuario)" : ''), util_ip()]);
        try {
            return $hacer($autorizar);
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

    /** POST al endpoint. */
    private static function enviar(string $post, ?string $url = null): string
    {
        $url ??= self::url();
        [$r, , $error] = self::http($url, $post, ADMIN_TIMEOUT);
        if ($r === null) {
            throw new RuntimeException($error !== ''
                ? t('No se pudo llamar a la API ({url}): {error}', ['url' => $url, 'error' => $error])
                : t('No se pudo llamar a la API ({url})', ['url' => $url]));
        }
        return $r;
    }

    /**
     * El código HTTP con que contesta un GET a $url, o null si no contesta en
     * 3 s: para ver desde fuera si el servidor entrega un fichero (ver
     * Instalador::datosExpuestos()).
     */
    public static function codigoHttp(string $url): ?int
    {
        [$r, $codigo] = self::http($url, null, 3);
        return $r === null ? null : $codigo;
    }

    /**
     * Una petición HTTP del panel: POST con $post, GET sin él. Con cURL si
     * está; si no, con los flujos de PHP. El certificado se comprueba igual en
     * todas (2.9: la comprobación de la carpeta de datos no lo comprobaba).
     *
     * @return array{0: ?string, 1: int, 2: string} el cuerpo (null si no contesta), el código HTTP y el error de cURL
     */
    private static function http(string $url, ?string $post, int $segundos): array
    {
        // Las opciones de certificado solo tienen sentido en HTTPS
        $ca  = stripos($url, 'https://') === 0 ? self::certificado() : '';
        $verificar = $ca !== '' || !ADMIN_SSL_AUTOFIRMADO;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opciones = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $segundos,
                CURLOPT_SSL_VERIFYPEER => $verificar,
                CURLOPT_SSL_VERIFYHOST => $verificar ? 2 : 0,
            ];
            if ($post !== null) {
                $opciones[CURLOPT_POST]       = true;
                $opciones[CURLOPT_POSTFIELDS] = $post;
            }
            if ($ca !== '') {
                $opciones[CURLOPT_CAINFO] = $ca;
            }
            curl_setopt_array($ch, $opciones);
            $r = curl_exec($ch);
            // Sin curl_close(): no hace nada desde PHP 8.0, y PHP 8.5 avisa de que está obsoleta
            return [$r === false ? null : (string)$r, (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_error($ch)];
        }

        $ssl = $ca !== ''
            ? ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $ca]
            : ['verify_peer'       => $verificar,
               'verify_peer_name'  => $verificar,
               'allow_self_signed' => !$verificar];
        $peticion = ['method' => 'GET', 'timeout' => $segundos, 'ignore_errors' => true];
        if ($post !== null) {
            $peticion = ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post] + $peticion;
        }
        $f = @fopen($url, 'rb', false, stream_context_create(['http' => $peticion, 'ssl' => $ssl]));
        if ($f === false) {
            return [null, 0, ''];
        }
        $r = (string)stream_get_contents($f);
        // Las cabeceras, de los datos del flujo: $http_response_header está obsoleta desde PHP 8.5
        $estado = (string)(stream_get_meta_data($f)['wrapper_data'][0] ?? '');
        fclose($f);
        return [$r, preg_match('#^HTTP/\S+\s+(\d{3})#', $estado, $m) ? (int)$m[1] : 0, ''];
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
