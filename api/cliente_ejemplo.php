<?php
declare(strict_types=1);

/**
 * jsonSQLDB - Cliente de ejemplo.
 *
 * Copia este fichero en la aplicación que vaya a consumir la API. Solo necesita
 * la URL del endpoint, su API key y el secreto con el que firma esa clave.
 *
 * EL SECRETO ES EL DE LA CLAVE: el campo 'hmac_secret' de esa misma entrada de
 * $API_KEYS en api/jsonsqldb_api_config.php. Cada clave tiene el suyo, distinto
 * del de las demás; si se compartiera, cualquiera que lo tuviera podría firmar
 * peticiones haciéndose pasar por otra clave.
 *
 *   $cli = JsonSqlDbCliente::pruebas();
 *   $filas = $cli->consultar('SELECT * FROM clientes WHERE ciudad = ?', ['Malaga']);
 *
 * Varias sentencias como una transacción, todas o ninguna (2.9):
 *   $cli->lote([['UPDATE cuentas SET saldo = saldo - ? WHERE id = ?', [50, 1]],
 *               ['UPDATE cuentas SET saldo = saldo + ? WHERE id = ?', [50, 2]]]);
 *
 * O montándolo a mano, que es lo que hará tu aplicación de verdad:
 *
 *   $cli = new JsonSqlDbCliente('https://miservidor/jsonsqldb/api/jsonsqldb_api.php',
 *                               'MI_API_KEY', 'EL_SECRETO_DE_ESA_KEY', 'mibase');
 *
 * Si la API va por HTTPS con certificado propio:
 *   $cli->certificado('C:/xampp/apache/conf/ssl.crt/server.crt');
 *   $cli->aceptarAutofirmado();   // atajo, sin verificar
 *
 * https://miguelenred.es/jsonsqldb
 */
final class JsonSqlDbCliente
{
    private string $url;
    private string $apiKey;
    private string $secreto;      // el 'hmac_secret' de esta API key
    private string $base;
    private int    $timeout;
    private string $ca          = '';
    private bool   $autofirmado = false;

    // Datos de la clave de los ejemplos: permiso de escritura sobre 'pruebas'.
    // La misma que usa cliente_ejemplo.ps1. Para tu aplicación, crea una clave
    // propia en api/jsonsqldb_api_config.php.
    // La clave y el secreto de los ejemplos llegan por variables de entorno:
    // escritos aquí, este fichero los serviría la web a quien lo pidiera.
    // php configurar.php dice cuáles son; para tu aplicación, crea una clave
    // propia en api/jsonsqldb_api_config.php y guárdala fuera de la web.
    public const EJEMPLO_URL     = 'https://example.com/jsonsqldb/api/jsonsqldb_api.php';
    public const EJEMPLO_BASE    = 'pruebas';

    /** Cliente ya configurado contra la base 'pruebas'. */
    public static function pruebas(): self
    {
        $cli = new self((string)(getenv('JSONSQLDB_URL') ?: self::EJEMPLO_URL),
                        (string)(getenv('JSONSQLDB_API_KEY') ?: 'CHANGE_ME_EXAMPLE_API_KEY'),
                        (string)(getenv('JSONSQLDB_HMAC_SECRET') ?: 'CHANGE_ME_EXAMPLE_SECRET'),
                        self::EJEMPLO_BASE);
        // Sin comprobar el certificado, solo contra el propio equipo (2.9):
        // antes también contra un servidor de verdad, sin decir nada
        if (in_array(strtolower((string)parse_url($cli->url, PHP_URL_HOST)), ['localhost', '127.0.0.1', '[::1]'], true)) {
            $cli->aceptarAutofirmado();
        }
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
        return $this->pedir(['sql' => $sql, 'params' => $params], $sql . $params);
    }

    /**
     * Varias sentencias en una transacción (2.9): se ejecutan todas, o
     * ninguna si una falla, y cada una ve lo que hicieron las anteriores.
     * Cada sentencia es un texto SQL o [sql, parámetros]. Devuelve el
     * resultado de cada una, en orden.
     *
     *   $cli->lote([
     *       ['UPDATE cuentas SET saldo = saldo - ? WHERE id = ?', [50, 1]],
     *       ['UPDATE cuentas SET saldo = saldo + ? WHERE id = ?', [50, 2]],
     *   ]);
     *
     * @param list<string|array{0: string, 1?: array}> $sentencias
     * @return list<array>
     * @throws RuntimeException si la API devuelve un error (y entonces no se ha hecho nada)
     */
    public function lote(array $sentencias): array
    {
        $lote = (string)json_encode(array_map(static fn($s): array => is_array($s)
            ? ['sql' => (string)$s[0], 'params' => array_values((array)($s[1] ?? []))]
            : ['sql' => (string)$s, 'params' => []], array_values($sentencias)), JSON_UNESCAPED_UNICODE);
        // En la firma, el texto del lote tal como se envía ocupa el lugar de sql y params
        return $this->pedir(['lote' => $lote], $lote);
    }

    /**
     * La petición firmada: $campos (sql y params, o lote) y lo que de ellos
     * entra en la firma.
     *
     * @param array<string, string> $campos
     */
    private function pedir(array $campos, string $firmado): array
    {
        $timestamp = (string)time();
        // Al azar en cada petición: dos consultas iguales en el mismo segundo
        // tendrían si no el mismo token, y la API rechazaría la segunda
        $nonce     = bin2hex(random_bytes(8));
        $token     = hash_hmac(
            'sha256',
            '+' . $this->apiKey . '|' . $this->base . '|' . $timestamp . '|' . $nonce . '|' . $firmado . '¿',
            $this->secreto
        );

        $post = http_build_query(['api_key' => $this->apiKey, 'db' => $this->base] + $campos + [
            'timestamp' => $timestamp,
            'nonce'     => $nonce,
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
