<?php
declare(strict_types=1);

/**
 * Llamadas del panel a la API de jsonSQLDB.
 *
 * Siempre con parámetros ligados: los valores viajan aparte y el servidor los
 * inserta ya analizados, así que nada de lo que el usuario escriba en un
 * formulario puede alterar la sentencia.
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
    public static function sql(string $base, string $sql, array $params = []): array
    {
        $json = $params === [] ? '' : (string)json_encode(array_values($params), JSON_UNESCAPED_UNICODE);
        $ts   = (string)time();

        $post = http_build_query([
            'api_key'   => ADMIN_API_KEY,
            'db'        => $base,
            'sql'       => $sql,
            'params'    => $json,
            'timestamp' => $ts,
            'token'     => hash_hmac('sha256',
                '+' . ADMIN_API_KEY . '|' . $ts . '|' . $sql . $json . '¿', ADMIN_HMAC_SECRET),
        ]);

        $cuerpo = self::enviar($post);
        $datos  = json_decode($cuerpo, true);

        if (!is_array($datos)) {
            throw new RuntimeException('Respuesta no válida de la API: ' . substr($cuerpo, 0, 300));
        }
        if (isset($datos['error'])) {
            throw new RuntimeException((string)$datos['error']);
        }
        return $datos;
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
        if (self::$url !== '') {
            return self::$url;
        }
        if (ADMIN_API_URL !== '') {
            return self::$url = ADMIN_API_URL;
        }
        $https  = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $raiz   = rtrim(dirname(dirname($script)), '/');

        return self::$url = ($https ? 'https' : 'http') . '://' . $host . $raiz . '/api/jsonsqldb_api.php';
    }

    /** POST al endpoint. Usa cURL si está, si no, el envoltorio de PHP. */
    private static function enviar(string $post): string
    {
        $url = self::url();
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
                throw new RuntimeException("No se pudo llamar a la API ($url): $error");
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
            throw new RuntimeException("No se pudo llamar a la API ($url)");
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
                "No se puede leer el certificado indicado en ADMIN_SSL_CA: $ca"
            );
        }
        return $ca;
    }
}
