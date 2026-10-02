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
 *
 * https://miguelenred.es/jsonsqldb
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
            // 'fallos' (la lista global de antes de 2.7.3) ya no se usa: se quita
            unset($estado['fallos']);
            $estado += ['ips' => [], 'fallosIp' => [], 'claves' => [], 'nonces' => []];

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

        foreach (['ips', 'fallosIp', 'claves'] as $grupo) {
            foreach ($estado[$grupo] as $quien => $marcas) {
                $vivas = array_values(array_filter($marcas, static fn(int $t): bool => $t >= $limite));
                if ($vivas === []) {
                    unset($estado[$grupo][$quien]);
                } else {
                    $estado[$grupo][$quien] = $vivas;
                }
            }
        }

        $limiteNonce = $ahora - (RATE_TIMESTAMP_DIFF + 60);
        foreach ($estado['nonces'] as $nonce => $t) {
            if ($t < $limiteNonce) {
                unset($estado['nonces'][$nonce]);
            }
        }
        return $estado;
    }

    /**
     * ¿Ha fallado ya demasiadas veces la autenticación desde esta IP? Se mira
     * antes de comprobar nada más, y solo cierra el paso a esa IP.
     *
     * Hasta 2.7.2 había además un bloqueo global: 30 fallos de cualquiera, sin
     * ni siquiera una clave válida, cerraban la API a todo el mundo durante un
     * día. Era una forma de tirarla desde fuera, y se ha quitado.
     */
    public function ipBloqueada(string $ip): bool
    {
        if (!RATE_LIMIT_ACTIVO) {
            return false;
        }
        return (bool)$this->transaccion(static function (array $e) use ($ip): array {
            $limite = time() - RATE_LIMIT_SECONDS;
            $n = count(array_filter($e['fallosIp'][$ip] ?? [], static fn(int $t): bool => $t >= $limite));
            return [$e, $n >= self::fallosPorIp()];
        }, true);                              // solo mira: no reescribe nada
    }

    /** Fallos de una IP antes de bloquearla; 10 si la configuración es de antes de 2.7.3. */
    private static function fallosPorIp(): int
    {
        return max(1, defined('RATE_LIMIT_FALLOS_IP') ? (int)RATE_LIMIT_FALLOS_IP : 10);
    }

    /**
     * Anota un fallo de autenticación de una IP, y cuenta la petición.
     *
     * Las dos cosas van en la misma transacción: una reescritura del fichero
     * por petición rechazada, no dos. Con tope en las dos listas: pasado el
     * límite nada cambia la decisión, y solo engordaría el fichero.
     */
    public function fallo(string $ip): void
    {
        if (!RATE_LIMIT_ACTIVO) {
            return;
        }
        $this->transaccion(static function (array $e) use ($ip): array {
            if (count($e['fallosIp'][$ip] ?? []) < self::fallosPorIp()) {
                $e['fallosIp'][$ip][] = time();
            }
            if (count($e['ips'][$ip] ?? []) < RATE_LIMIT_MAX) {
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
    public function nonceYContar(string $nonce, string $ip, string $clave = '')
    {
        $antiReplay = ANTI_REPLAY_ACTIVO;
        $rate       = RATE_LIMIT_ACTIVO;
        if (!$antiReplay && !$rate) {
            return true;
        }
        // Una configuración de antes de 2.7.3 no tiene esta constante: sin cupo
        $porClave = defined('RATE_LIMIT_POR_CLAVE') ? (int)RATE_LIMIT_POR_CLAVE : 0;
        $r = $this->transaccion(static function (array $e) use ($nonce, $ip, $clave, $antiReplay, $rate, $porClave): array {
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
            // Pasado el límite ya no se anota nada más: el rechazo no depende de
            // cuántas marcas haya por encima, y un ataque de miles de peticiones
            // haría crecer el fichero de estado, que cada petición lee y reescribe
            if ($dentro) {
                $marcas[] = time();
            }
            $e['ips'][$ip] = $marcas;
            if (!$dentro) {
                return [$e, 'limite'];
            }
            // Cupo de cada API key, sea cual sea la IP (0 = sin cupo): para que
            // una clave filtrada, o una aplicación desbocada, no lo agote todo
            if ($porClave > 0 && $clave !== '') {
                $deClave = array_values(array_filter($e['claves'][$clave] ?? [], static fn(int $t): bool => $t >= $limite));
                if (count($deClave) >= $porClave) {
                    $e['claves'][$clave] = $deClave;
                    return [$e, 'limite'];
                }
                $deClave[] = time();
                $e['claves'][$clave] = $deClave;
            }
            return [$e, true];
        });
        return $r === null ? false : $r;
    }

    /** Ficheros llenos por día a partir de los cuales no se anotan las peticiones sin clave válida. */
    private const MAX_FICHEROS_DIA = 20;

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
        [$fichero, $vuelta] = $this->ficheroDelDia();
        // Un día con más de MAX_FICHEROS_DIA ficheros llenos ya no admite las
        // peticiones rechazadas antes de saber de quién son: sin tope, cualquiera
        // podía llenar el disco a base de peticiones sin clave
        if ($vuelta >= self::MAX_FICHEROS_DIA && ($entrada['origen'] ?? '') === '') {
            return;
        }
        @file_put_contents($fichero, $linea . "\n", FILE_APPEND | LOCK_EX);

        if (mt_rand(1, 200) === 1) {
            $this->purgarHistorico();
        }
    }

    /** @return array{0: string, 1: int} el fichero de hoy en el que escribir y cuántos van llenos */
    private function ficheroDelDia(): array
    {
        $base = $this->dir . '/peticiones-' . date('Y-m-d');
        $max  = Config::logMaxSize();
        $f    = $base . '.json';
        $i    = 0;
        if ($max > 0) {
            for ($i = 1; is_file($f) && filesize($f) >= $max; $i++) {
                $f = $base . '.' . $i . '.json';
            }
            $i--;
        }
        return [$f, $i];
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
