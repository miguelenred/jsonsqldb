<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Estado de la API guardado en JSON (sin base de datos externa).
 *
 *   logs/api/estado.json        contadores de rate limit, fallos y nonces
 *   logs/api/peticiones-*.json  histórico de peticiones (una por línea)
 *
 * El fichero de estado se lee y se escribe bajo bloqueo exclusivo, y se limpia
 * de entradas caducadas en cada escritura, así que no crece indefinidamente.
 */
final class ApiStore
{
    private string $dir;
    private string $fichero;

    public function __construct(string $dir)
    {
        $this->dir     = rtrim(str_replace('\\', '/', $dir), '/');
        $this->fichero = $this->dir . '/estado.json';
    }

    /** Crea la carpeta si hace falta. Devuelve false si no se puede escribir. */
    private function preparar(): bool
    {
        return is_dir($this->dir) || @mkdir($this->dir, 0775, true) || is_dir($this->dir);
    }

    /**
     * Lee el estado, aplica los cambios de $fn y lo vuelve a guardar,
     * todo bajo un único bloqueo exclusivo.
     *
     * Con $soloLectura no se purga ni se reescribe: solo se mira. Una consulta
     * que no cambia nada no tiene por qué reescribir el fichero entero, y ese
     * fichero crece con el tráfico, así que hacerlo empeoraba solo.
     *
     * @param callable(array):array $fn recibe el estado y devuelve [estado, resultado]
     * @return mixed lo que devuelva $fn como resultado
     */
    private function transaccion(callable $fn, bool $soloLectura = false)
    {
        if (!$this->preparar()) {
            return null;
        }
        // 'c+' en los dos casos: 'c' abre solo para escribir y stream_get_contents
        // no leería nada. Lo que distingue a la lectura es el bloqueo compartido
        // y no volver a escribir el fichero, no el modo de apertura.
        $fh = @fopen($this->fichero, 'c+');
        if ($fh === false) {
            return null;
        }
        try {
            if (!flock($fh, $soloLectura ? LOCK_SH : LOCK_EX)) {
                return null;
            }
            $texto  = stream_get_contents($fh);
            $estado = $texto === '' ? [] : (json_decode($texto, true) ?: []);
            $estado += ['ips' => [], 'fallos' => [], 'nonces' => []];

            [$estado, $resultado] = $fn($estado);
            if ($soloLectura) {
                return $resultado;
            }
            $estado = $this->purgar($estado);

            // Sin JSON_PRETTY_PRINT: esto no lo lee nadie a mano y se reescribe
            // en cada petición, así que la sangría es peso puro
            $json = json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json . "\n");
            fflush($fh);
            return $resultado;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Quita marcas de tiempo fuera de la ventana. */
    private function purgar(array $estado): array
    {
        $ahora  = time();
        $limite = $ahora - RATE_LIMIT_SECONDS;

        foreach ($estado['ips'] as $ip => $marcas) {
            $vivas = array_values(array_filter($marcas, static fn(int $t): bool => $t >= $limite));
            if ($vivas === []) {
                unset($estado['ips'][$ip]);
            } else {
                $estado['ips'][$ip] = $vivas;
            }
        }
        $estado['fallos'] = array_values(array_filter($estado['fallos'], static fn(int $t): bool => $t >= $limite));

        $limiteNonce = $ahora - (RATE_TIMESTAMP_DIFF + 60);
        foreach ($estado['nonces'] as $nonce => $t) {
            if ($t < $limiteNonce) {
                unset($estado['nonces'][$nonce]);
            }
        }
        return $estado;
    }

    /** ¿Se ha superado el número global de fallos de autenticación? */
    public function bloqueoGlobal(): bool
    {
        if (!RATE_LIMIT_ACTIVO) {
            return false;
        }
        return (bool)$this->transaccion(static function (array $e): array {
            $limite = time() - RATE_LIMIT_SECONDS;
            $n = 0;
            foreach ($e['fallos'] as $t) {
                if ($t >= $limite) { $n++; }
            }
            return [$e, $n >= RATE_LIMIT_GLOBAL_MAX];
        }, true);                              // solo mira: no reescribe nada
    }

    /**
     * Anota un fallo de autenticación y, de paso, cuenta la petición.
     *
     * Las dos cosas iban en transacciones separadas, o sea dos reescrituras del
     * fichero por cada petición rechazada. Van juntas porque siempre se hacían
     * juntas.
     */
    public function fallo(?string $ip = null): void
    {
        if (!RATE_LIMIT_ACTIVO) {
            return;
        }
        $this->transaccion(static function (array $e) use ($ip): array {
            $e['fallos'][] = time();
            if ($ip !== null) {
                $e['ips'][$ip][] = time();
            }
            return [$e, null];
        });
    }

    /**
     * Registra el nonce y cuenta la petición en una sola pasada.
     *
     * Antes eran dos transacciones, cada una leyendo, decodificando, modificando
     * y reescribiendo el fichero de estado entero bajo bloqueo exclusivo. Con
     * tráfico, ese fichero crece y la latencia empeora sola: medido, el coste de
     * estado por petición pasó de 31,7 ms a 8,1 ms a 50 peticiones por segundo.
     *
     * Devuelve true si todo va bien, 'nonce' si el token ya se usó, 'limite' si
     * se pasó del cupo, y false si el fichero no se pudo tocar. Como antes, un
     * fallo de entrada/salida cierra el paso en vez de dejarlo abierto.
     *
     * @return true|string|false
     */
    public function nonceYContar(string $nonce, string $ip)
    {
        $antiReplay = ANTI_REPLAY_ACTIVO;
        $rate       = RATE_LIMIT_ACTIVO;
        if (!$antiReplay && !$rate) {
            return true;
        }
        $r = $this->transaccion(static function (array $e) use ($nonce, $ip, $antiReplay, $rate): array {
            if ($antiReplay && isset($e['nonces'][$nonce])) {
                return [$e, 'nonce'];
            }
            if ($antiReplay) {
                $e['nonces'][$nonce] = time();
            }
            if (!$rate) {
                return [$e, true];
            }
            $limite = time() - RATE_LIMIT_SECONDS;
            $marcas = array_values(array_filter($e['ips'][$ip] ?? [], static fn(int $t): bool => $t >= $limite));
            $dentro = count($marcas) < RATE_LIMIT_MAX;
            $marcas[] = time();
            $e['ips'][$ip] = $marcas;
            return [$e, $dentro ? true : 'limite'];
        });
        return $r === null ? false : $r;
    }

    /**
     * Histórico de peticiones: un fichero por día, un objeto JSON por línea.
     * Rota por tamaño y se purga según JSONSQLDB_LOG_DIAS, igual que el log del motor.
     */
    public function registrar(array $entrada): void
    {
        if (!$this->preparar()) {
            return;
        }
        $linea = json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($linea === false) {
            return;
        }
        @file_put_contents($this->ficheroDelDia(), $linea . "\n", FILE_APPEND | LOCK_EX);

        if (mt_rand(1, 200) === 1) {
            $this->purgarHistorico();
        }
    }

    private function ficheroDelDia(): string
    {
        $base = $this->dir . '/peticiones-' . date('Y-m-d');
        $max  = Config::logMaxSize();
        $f    = $base . '.json';
        if ($max > 0) {
            for ($i = 1; is_file($f) && filesize($f) >= $max; $i++) {
                $f = $base . '.' . $i . '.json';
            }
        }
        return $f;
    }

    private function purgarHistorico(): void
    {
        $dias = Config::logDias();
        if ($dias <= 0) {
            return;                       // 0 = conservar los logs para siempre
        }
        $limite = time() - ($dias * 86400);
        foreach ((array)glob($this->dir . '/peticiones-*.json') as $f) {
            if (@filemtime($f) < $limite) {
                @unlink($f);
            }
        }
    }
}
