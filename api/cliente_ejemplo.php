<?php
declare(strict_types=1);

/**
 * jsonSQLDB - Cliente de ejemplo.
 *
 * Copia este fichero en la aplicación que vaya a consumir la API. Solo necesita
 * la URL del endpoint, su API key y el secreto HMAC.
 *
 *   $cli = JsonSqlDbCliente::pruebas();
 *   $filas = $cli->consultar('SELECT * FROM clientes WHERE ciudad = ?', ['Torrevieja']);
 *
 * O montándolo a mano, que es lo que hará tu aplicación de verdad:
 *
 *   $cli = new JsonSqlDbCliente('https://miservidor/jsonsqldb/api/jsonsqldb_api.php',
 *                               'MI_API_KEY', 'MI_HMAC_SECRET', 'mibase');
 *
 * Si la API va por HTTPS con certificado propio:
 *   $cli->certificado('C:/xampp/apache/conf/ssl.crt/server.crt');
 *   $cli->aceptarAutofirmado();   // atajo, sin verificar
 */
final class JsonSqlDbCliente
{
    private string $url;
    private string $apiKey;
    private string $secreto;
    private string $base;
    private int    $timeout;
    private string $ca          = '';
    private bool   $autofirmado = false;

    // Datos de la clave de los ejemplos: permiso de escritura sobre 'pruebas'.
    // La misma que usa cliente_ejemplo.ps1. Para tu aplicación, crea una clave
    // propia en api/jsonsqldb_api_config.php.
    public const EJEMPLO_URL     = 'https://example.com/jsonsqldb/api/jsonsqldb_api.php';
    public const EJEMPLO_API_KEY = 'CHANGE_ME_EXAMPLE_API_KEY';
    public const EJEMPLO_SECRETO = 'CHANGE_ME_EXAMPLE_SECRET';
    public const EJEMPLO_BASE    = 'pruebas';

    /** Cliente ya configurado contra la base 'pruebas'. */
    public static function pruebas(): self
    {
        $cli = new self(self::EJEMPLO_URL, self::EJEMPLO_API_KEY, self::EJEMPLO_SECRETO, self::EJEMPLO_BASE);
        $cli->aceptarAutofirmado();
        return $cli;
    }

    public function __construct(string $url, string $apiKey, string $secreto, string $base, int $timeout = 60)
    {
        $this->url     = $url;
        $this->apiKey  = $apiKey;
        $this->secreto = $secreto;
        $this->base    = $base;
        $this->timeout = $timeout;
    }

    /**
     * Certificado del servidor de la API cuando es propio o de una CA interna.
     * Se sigue verificando, pero contra este fichero .crt / .pem.
     *
     *   $cli->certificado('C:/xampp/apache/conf/ssl.crt/server.crt');
     */
    public function certificado(string $rutaCrt): self
    {
        if (!is_file($rutaCrt) || !is_readable($rutaCrt)) {
            throw new RuntimeException("No se puede leer el certificado: $rutaCrt");
        }
        $this->ca = $rutaCrt;
        return $this;
    }

    /**
     * Aceptar el certificado sin comprobarlo (autofirmado en red interna).
     * Es peor que certificado(): deja de protegerte frente a un intermediario.
     */
    public function aceptarAutofirmado(bool $si = true): self
    {
        $this->autofirmado = $si;
        return $this;
    }

    /**
     * Ejecuta una sentencia. Cada ? de la SQL toma su valor de $parametros, que
     * viajan aparte y el servidor inserta ya analizados: nunca se concatenan al
     * texto de la consulta, así que un valor no puede alterarla.
     *
     * Valores admitidos: null, bool, int, float y string.
     *
     * @return array filas del SELECT, o ['success'=>true,'filas'=>n,'mensaje'=>'...']
     * @throws RuntimeException si la API devuelve un error
     */
    public function consultar(string $sql, array $parametros = []): array
    {
        $params = $parametros === []
            ? ''
            : (string)json_encode(array_values($parametros), JSON_UNESCAPED_UNICODE);

        $timestamp = (string)time();
        $token     = hash_hmac(
            'sha256',
            '+' . $this->apiKey . '|' . $timestamp . '|' . $sql . $params . '¿',
            $this->secreto
        );

        $post = http_build_query([
            'api_key'   => $this->apiKey,
            'db'        => $this->base,
            'sql'       => $sql,
            'params'    => $params,
            'timestamp' => $timestamp,
            'token'     => $token,
        ]);

        $verificar = $this->ca !== '' || !$this->autofirmado;

        $ch = curl_init($this->url);
        $opciones = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $verificar,
            CURLOPT_SSL_VERIFYHOST => $verificar ? 2 : 0,
        ];
        if ($this->ca !== '') {
            $opciones[CURLOPT_CAINFO] = $this->ca;
        }
        curl_setopt_array($ch, $opciones);
        $respuesta = curl_exec($ch);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            throw new RuntimeException('No se pudo conectar con la API: ' . $errorCurl);
        }

        $datos = json_decode($respuesta, true);
        if (!is_array($datos)) {
            throw new RuntimeException('Respuesta no válida de la API: ' . substr((string)$respuesta, 0, 200));
        }
        if (isset($datos['error'])) {
            throw new RuntimeException((string)$datos['error']);
        }
        return $datos;
    }
}
