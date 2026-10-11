<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Estado de la API guardado en JSON (sin base de datos externa).
 *
 *   logs/api/estado/n-XX.json   nonces ya usados (anti-replay)
 *   logs/api/estado/i-XX.json   peticiones y fallos de autenticación por IP
 *   logs/api/estado/c-XX.json   peticiones por API key
 *   logs/api/peticiones-*.json  histórico de peticiones (una por línea)
 *
 * Cada cosa va en uno de 256 ficheros según las dos primeras cifras del md5
 * del nonce, de la IP o de la clave (2.9). Una petición lee y reescribe solo
 * los dos o tres que le tocan, pequeños, y cada uno bajo su bloqueo
 * exclusivo. Antes todo iba en un único estado.json que se reescribía entero
 * en cada petición y crecía con el tráfico de toda la instalación: 2,4 ms y
 * 211 KB escritos por petición con 5.000 en la ventana. Cada fichero se limpia
 * de lo caducado al escribirlo, así que no crece indefinidamente.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class ApiStore
{
    private string $dir;
    /** El estado.json de antes de la 2.9: solo se mira para no aceptar un nonce que ya figura en él */
    private string $legado;

    public function __construct(string $dir)
    {
        $this->dir    = rtrim(str_replace('\\', '/', $dir), '/');
        $this->legado = $this->dir . '/estado.json';
    }

    /** Crea la carpeta si hace falta. Devuelve false si no se puede escribir. */
    private function preparar(): bool
    {
        $dir = $this->dir . '/estado';
        return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
    }

    /** El fichero de estado de un tipo ('n', 'i' o 'c') y un nonce, IP o clave. */
    private function fichero(string $tipo, string $quien): string
    {
        return $this->dir . '/estado/' . $tipo . '-' . substr(md5($quien), 0, 2) . '.json';
    }

    /**
     * Lee un fichero de estado, aplica los cambios de $fn y lo vuelve a
     * guardar, todo bajo un único bloqueo exclusivo.
     *
     * Con $soloLectura no se purga ni se reescribe: solo se mira, con bloqueo
     * compartido.
     *
     * @param callable(array):array $fn recibe el estado y devuelve [estado, resultado]
     * @return mixed lo que devuelva $fn como resultado, o null si no se pudo
     */
    private function transaccion(string $fichero, callable $fn, bool $soloLectura = false)
    {
        if (!$this->preparar()) {
            return null;
        }
        // 'c+' en los dos casos: 'c' abre solo para escribir y stream_get_contents
        // no leería nada. Lo que distingue a la lectura es el bloqueo compartido
        // y no volver a escribir el fichero, no el modo de apertura.
        $fh = @fopen($fichero, 'c+');
        if ($fh === false) {
            return null;
        }
        try {
            if (!flock($fh, $soloLectura ? LOCK_SH : LOCK_EX)) {
                return null;
            }
            $texto  = stream_get_contents($fh);
            $estado = $texto === '' ? [] : (json_decode((string)$texto, true) ?: []);
            $estado += ['ips' => [], 'fallosIp' => [], 'claves' => [], 'nonces' => []];

            [$estado, $resultado] = $fn($estado);
            if ($soloLectura) {
                return $resultado;
            }
            // Sin JSON_PRETTY_PRINT: esto no lo lee nadie a mano y se reescribe
            // en cada petición, así que la sangría es peso puro
            $json = json_encode(array_filter(self::purgar($estado)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    private static function purgar(array $estado): array
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
        $ip = self::red($ip);
        return (bool)$this->transaccion($this->fichero('i', $ip), static function (array $e) use ($ip): array {
            $limite = time() - RATE_LIMIT_SECONDS;
            $n = count(array_filter($e['fallosIp'][$ip] ?? [], static fn(int $t): bool => $t >= $limite));
            return [$e, $n >= self::fallosPorIp()];
        }, true);                              // solo mira: no reescribe nada
    }

    /**
     * Con qué se cuentan las peticiones y los fallos de una IP (2.9): una IPv6,
     * por su red /64, que es lo que un proveedor da a cada cliente; quien tiene
     * una tiene 2^64 direcciones, y cambiando de una a otra se saltaba el
     * límite por IP y el bloqueo por intentos fallidos. Una IPv4, también la
     * escrita como IPv6 (::ffff:1.2.3.4), tal cual.
     */
    private static function red(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        if (strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            return (string)inet_ntop(substr($bin, 12));
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
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
        $ip = self::red($ip);
        $this->transaccion($this->fichero('i', $ip), static function (array $e) use ($ip): array {
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
     * Registra el nonce y cuenta la petición: el nonce en su fichero, la IP en
     * el suyo y la clave en el suyo, cada uno en su transacción y en ese orden.
     * Un nonce repetido se rechaza antes de contar nada; uno nuevo queda
     * anotado aunque la petición se pase del cupo, como antes.
     *
     * Devuelve true si todo va bien, 'nonce' si el token ya se usó, 'limite' si
     * se pasó del cupo, y false si un fichero no se pudo tocar. Como antes, un
     * fallo de entrada/salida cierra el paso en vez de dejarlo abierto.
     *
     * El nonce se guarda con la hora de la petición si va por delante de la
     * del servidor (2.9): se olvida pasada la ventana desde esa hora, que es
     * cuando su token deja de valer. Con la de llegada, el de un cliente con
     * el reloj adelantado más de un minuto se olvidaba mientras su token aún
     * valía, y se podía repetir.
     *
     * @return true|string|false
     */
    public function nonceYContar(string $nonce, string $ip, string $clave = '', int $hora = 0)
    {
        $antiReplay = ANTI_REPLAY_ACTIVO;
        $rate       = RATE_LIMIT_ACTIVO;
        if ($antiReplay) {
            $legado = $this->nonceEnLegado($nonce);
            $r = $legado ? 'nonce' : $this->transaccion($this->fichero('n', $nonce), static function (array $e) use ($nonce, $hora): array {
                if (isset($e['nonces'][$nonce])) {
                    return [$e, 'nonce'];
                }
                $e['nonces'][$nonce] = max(time(), $hora);
                return [$e, true];
            });
            if ($r !== true) {
                return $r ?? false;
            }
        }
        if (!$rate) {
            return true;
        }
        $limite = time() - RATE_LIMIT_SECONDS;
        $ip     = self::red($ip);
        $r = $this->transaccion($this->fichero('i', $ip), static function (array $e) use ($ip, $limite): array {
            $marcas = array_values(array_filter($e['ips'][$ip] ?? [], static fn(int $t): bool => $t >= $limite));
            $dentro = count($marcas) < RATE_LIMIT_MAX;
            // Pasado el límite ya no se anota nada más: el rechazo no depende de
            // cuántas marcas haya por encima, y un ataque de miles de peticiones
            // haría crecer el fichero, que cada petición de esa IP lee y reescribe
            if ($dentro) {
                $marcas[] = time();
            }
            $e['ips'][$ip] = $marcas;
            return [$e, $dentro ? true : 'limite'];
        });
        if ($r !== true) {
            return $r ?? false;
        }
        // Cupo de cada API key, sea cual sea la IP (0 = sin cupo): para que una
        // clave filtrada, o una aplicación desbocada, no lo agote todo. Una
        // configuración de antes de 2.7.3 no tiene esta constante: sin cupo
        $porClave = defined('RATE_LIMIT_POR_CLAVE') ? (int)RATE_LIMIT_POR_CLAVE : 0;
        if ($porClave <= 0 || $clave === '') {
            return true;
        }
        $r = $this->transaccion($this->fichero('c', $clave), static function (array $e) use ($clave, $limite, $porClave): array {
            $deClave = array_values(array_filter($e['claves'][$clave] ?? [], static fn(int $t): bool => $t >= $limite));
            if (count($deClave) >= $porClave) {
                $e['claves'][$clave] = $deClave;
                return [$e, 'limite'];
            }
            $deClave[] = time();
            $e['claves'][$clave] = $deClave;
            return [$e, true];
        });
        return $r ?? false;
    }

    /**
     * ¿Está el nonce en el estado.json de antes de la 2.9? Solo mientras ese
     * fichero puede tener nonces vigentes (unos minutos tras actualizar);
     * después se borra.
     */
    private function nonceEnLegado(string $nonce): bool
    {
        clearstatcache(true, $this->legado);
        if (!is_file($this->legado)) {
            return false;
        }
        if ((int)@filemtime($this->legado) < time() - (RATE_TIMESTAMP_DIFF + 60)) {
            @unlink($this->legado);
            return false;
        }
        $e = json_decode((string)@file_get_contents($this->legado), true);
        return isset($e['nonces'][$nonce]);
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
        // Un texto que no es UTF-8 válido (un User-Agent, un error con el
        // nombre que se escribió, un recorte a mitad de una letra) va con �
        // en su lugar (2.9: json_encode() daba false y la petición no se anotaba)
        $linea = json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
