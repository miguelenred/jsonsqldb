<?php
declare(strict_types=1);

/**
 * Usuarios del panel (bcrypt, en JSON), sesión, límite de intentos y CSRF.
 *
 * Roles:
 *   admin    todo
 *   lectura  ver la estructura y los datos, y lanzar SELECT/SHOW
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Auth
{
    private const USUARIOS = 'usuarios.json';
    private const INTENTOS = 'intentos.json';

    // ------------------------------------------------------------------
    // Sesión
    // ------------------------------------------------------------------

    public static function iniciarSesion(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(ADMIN_SESION_NOMBRE);
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Strict',
            // La misma comprobación que la de exigir HTTPS: detrás de un proxy
            // de confianza, su cabecera cuenta; si no, la cookie salía sin Secure
            'secure'   => util_https(),
        ]);
        session_start();

        // Caducidad por inactividad
        $limite = ADMIN_SESION_MINUTOS * 60;
        if (isset($_SESSION['visto']) && time() - (int)$_SESSION['visto'] > $limite) {
            self::cerrar();
            session_start();
            $_SESSION['aviso'] = t('La sesión ha caducado por inactividad.');
        }
        $_SESSION['visto'] = time();
    }

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    public static function identificado(): bool
    {
        return isset($_SESSION['usuario']['usuario']);
    }

    /**
     * Comprueba en cada petición que el usuario de la sesión sigue existiendo
     * con la misma contraseña, y toma su rol de lo guardado. Sin esto, borrar
     * un usuario o cambiarle la contraseña no le echaba: su sesión seguía
     * valiendo, y como la caducidad es por inactividad, podía no caducar
     * nunca. Devuelve false si la sesión se ha cerrado por eso.
     */
    public static function revalidar(): bool
    {
        if (!self::identificado()) {
            return true;
        }
        $u = self::buscar((string)$_SESSION['usuario']['usuario']);
        if ($u === null || !hash_equals(self::huella($u), (string)($_SESSION['usuario']['huella'] ?? ''))) {
            self::cerrar();
            return false;
        }
        $_SESSION['usuario']['rol'] = (string)$u['rol'];
        return true;
    }

    /** Huella de la contraseña guardada: cambia cuando cambia la contraseña. */
    private static function huella(array $u): string
    {
        return substr(hash('sha256', (string)($u['hash'] ?? '')), 0, 16);
    }

    /** Tras cambiar la propia contraseña, la sesión en curso sigue valiendo. */
    public static function renovarHuella(): void
    {
        $u = self::identificado() ? self::buscar((string)$_SESSION['usuario']['usuario']) : null;
        if ($u !== null) {
            $_SESSION['usuario']['huella'] = self::huella($u);
        }
    }

    public static function esAdmin(): bool
    {
        return (($_SESSION['usuario']['rol'] ?? '') === 'admin');
    }

    /** Corta la petición si el usuario no es administrador. */
    public static function exigirAdmin(): void
    {
        if (!self::esAdmin()) {
            throw new RuntimeException(t('Esta acción necesita permiso de administrador.'));
        }
    }

    public static function cerrar(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ------------------------------------------------------------------
    // Usuarios
    // ------------------------------------------------------------------

    public static function usuarios(): array
    {
        return Store::leer(self::USUARIOS);
    }

    public static function hayUsuarios(): bool
    {
        return self::usuarios() !== [];
    }

    public static function buscar(string $usuario): ?array
    {
        foreach (self::usuarios() as $u) {
            if (strcasecmp((string)$u['usuario'], $usuario) === 0) {
                return $u;
            }
        }
        return null;
    }

    /**
     * Comprueba que un usuario nuevo se puede crear, sin crearlo: el asistente
     * de instalación lo mira antes de escribir la configuración.
     */
    public static function validarNuevo(string $usuario, string $clave): void
    {
        if (!self::nombreValido(trim($usuario))) {
            throw new RuntimeException(t('El usuario admite de 3 a 32 caracteres: letras, números y . _ - @'));
        }
        self::validarClave($clave);
        if (self::buscar(trim($usuario)) !== null) {
            throw new RuntimeException(t("El usuario '{usuario}' ya existe", ['usuario' => trim($usuario)]));
        }
    }

    /** ¿Puede ser el nombre de un usuario? De 3 a 32 caracteres: letras, números y . _ - @ */
    private static function nombreValido(string $usuario): bool
    {
        return preg_match('/^[A-Za-z0-9_.@-]{3,32}$/', $usuario) === 1;
    }

    /** Crea un usuario. Devuelve el nombre normalizado. */
    public static function crear(string $usuario, string $clave, string $rol): string
    {
        $usuario = trim($usuario);
        if (!in_array($rol, ['admin', 'lectura'], true)) {
            throw new RuntimeException(t('Rol no válido'));
        }
        self::validarNuevo($usuario, $clave);
        $hash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => ADMIN_BCRYPT_COSTE]);

        // Leer, cambiar y guardar bajo un mismo bloqueo (Store::actualizar): dos
        // cambios a la vez ya no se pisan. Y dentro se vuelve a mirar que no
        // exista, por si otro lo ha creado mientras tanto
        Store::actualizar(self::USUARIOS, static function (array $usuarios) use ($usuario, $hash, $rol): array {
            foreach ($usuarios as $u) {
                if (strcasecmp((string)$u['usuario'], $usuario) === 0) {
                    throw new RuntimeException(t("El usuario '{usuario}' ya existe", ['usuario' => $usuario]));
                }
            }
            $usuarios[] = ['usuario' => $usuario, 'hash' => $hash, 'rol' => $rol, 'creado' => date('Y-m-d H:i:s'), 'acceso' => null];
            return $usuarios;
        });
        return $usuario;
    }

    /** El idioma que ha elegido un usuario, o null si no ha elegido ninguno. */
    public static function idiomaDe(string $usuario): ?string
    {
        $u = self::buscar($usuario);
        return isset($u['idioma']) ? (string)$u['idioma'] : null;
    }

    /** Guarda el idioma de un usuario: le sigue en cualquier navegador. */
    public static function guardarIdioma(string $usuario, string $idioma): void
    {
        Store::actualizar(self::USUARIOS, static function (array $usuarios) use ($usuario, $idioma): array {
            foreach ($usuarios as $i => $u) {
                if (strcasecmp((string)$u['usuario'], $usuario) === 0) {
                    $usuarios[$i]['idioma'] = $idioma;
                }
            }
            return $usuarios;
        });
    }

    public static function cambiarClave(string $usuario, string $clave): void
    {
        self::validarClave($clave);
        $hash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => ADMIN_BCRYPT_COSTE]);
        Store::actualizar(self::USUARIOS, static function (array $usuarios) use ($usuario, $hash): array {
            foreach ($usuarios as $i => $u) {
                if (strcasecmp((string)$u['usuario'], $usuario) === 0) {
                    $usuarios[$i]['hash'] = $hash;
                    return $usuarios;
                }
            }
            throw new RuntimeException(t('El usuario \'{usuario}\' no existe', ['usuario' => $usuario]));
        });
    }

    public static function borrar(string $usuario): void
    {
        Store::actualizar(self::USUARIOS, static fn(array $usuarios): array => self::sinUsuario($usuarios, $usuario));
    }

    /** La lista sin ese usuario; siempre tiene que quedar un administrador. */
    private static function sinUsuario(array $usuarios, string $usuario): array
    {
        $quedan   = [];
        $admins   = 0;
        foreach ($usuarios as $u) {
            if (strcasecmp((string)$u['usuario'], $usuario) === 0) {
                continue;
            }
            if (($u['rol'] ?? '') === 'admin') { $admins++; }
            $quedan[] = $u;
        }
        if (count($quedan) === count($usuarios)) {
            throw new RuntimeException(t('El usuario \'{usuario}\' no existe', ['usuario' => $usuario]));
        }
        if ($admins === 0) {
            throw new RuntimeException(t('Tiene que quedar al menos un administrador'));
        }
        return $quedan;
    }

    private static function validarClave(string $clave): void
    {
        if (strlen($clave) < 10) {
            throw new RuntimeException(t('La contraseña necesita al menos 10 caracteres'));
        }
        // bcrypt no admite el carácter nulo (2.9): password_hash() lanzaba un
        // ValueError sin traducir, y el asistente ya había escrito config.php
        if (strpos($clave, "\0") !== false) {
            throw new RuntimeException(t('La contraseña no puede llevar el carácter nulo'));
        }
    }

    // ------------------------------------------------------------------
    // Acceso
    // ------------------------------------------------------------------

    /**
     * Comprueba las credenciales y abre la sesión.
     *
     * @throws RuntimeException con el motivo del rechazo
     */
    public static function entrar(string $usuario, string $clave, string $ip): void
    {
        // El intento se cuenta antes de comprobar la contraseña, y con el
        // fichero bloqueado (2.9): antes se miraba sin bloquearlo y se contaba
        // al fallar, y varios a la vez pasaban todos, más allá del límite.
        // Un nombre que no puede ser de ningún usuario cuenta igual contra la
        // IP, pero todos con la misma clave, '?' (2.9): entero, unos pocos de
        // varios MB dejaban intentos.json en decenas de MB, que cada intento de
        // entrar, desde cualquier IP, leía y reescribía entero. La respuesta y
        // el tiempo, los de una contraseña equivocada
        $valido = self::nombreValido($usuario);
        $quien  = $valido ? strtolower($usuario) : '?';
        self::contarIntento($ip, $quien);

        $u = $valido ? self::buscar($usuario) : null;
        // password_verify siempre, exista o no el usuario: mismo tiempo de
        // respuesta, también con el coste configurado (2.9: el de un usuario que
        // no existía era siempre 10, y con 11 se le notaba la diferencia)
        $hash = (string)($u['hash'] ?? sprintf('$2y$%02d$', max(4, min(31, (int)ADMIN_BCRYPT_COSTE)))
            . 'invalidinvalidinvalidinvalidinvalidinvalidinvalidinva');     // 22 de sal y 31 de hash
        if (!password_verify($clave, $hash) || $u === null) {
            throw new RuntimeException(t('Usuario o contraseña incorrectos'));
        }

        self::quitarFallos($ip, $quien);
        session_regenerate_id(true);
        $_SESSION['usuario'] = ['usuario' => $u['usuario'], 'rol' => $u['rol'], 'huella' => self::huella($u)];
        $_SESSION['visto']   = time();
        $_SESSION['csrf']    = bin2hex(random_bytes(32));

        $nombre = (string)$u['usuario'];
        Store::actualizar(self::USUARIOS, static function (array $usuarios) use ($nombre): array {
            foreach ($usuarios as $i => $x) {
                if (strcasecmp((string)$x['usuario'], $nombre) === 0) {
                    $usuarios[$i]['acceso'] = date('Y-m-d H:i:s');
                }
            }
            return $usuarios;
        });
    }

    /**
     * Comprueba la contraseña de un usuario ya dentro, para cambiarla (2.9).
     * Cuenta como un intento de entrar: con la IP bloqueada no se comprueba, y
     * no se pueden probar contraseñas por aquí sin llegar al bloqueo.
     *
     * @throws RuntimeException si no es la suya
     */
    public static function comprobarClave(string $usuario, string $clave, string $ip): void
    {
        $quien = strtolower($usuario);
        self::contarIntento($ip, $quien);
        if (!password_verify($clave, (string)(self::buscar($usuario)['hash'] ?? ''))) {
            throw new RuntimeException(t('La contraseña actual no es correcta.'));
        }
        self::quitarFallos($ip, $quien);
    }

    /** Minutos que faltan para poder reintentar, 0 si no hay bloqueo. */
    public static function bloqueoRestante(string $ip): int
    {
        return self::restante(Store::leer(self::INTENTOS)[$ip] ?? null);
    }

    /** @param array{fallos:int,ultimo:int}|mixed $e los intentos de una IP */
    private static function restante($e): int
    {
        if (!is_array($e) || (int)($e['fallos'] ?? 0) < ADMIN_LOGIN_MAX_FALLOS) {
            return 0;
        }
        $fin = (int)$e['ultimo'] + ADMIN_LOGIN_BLOQUEO_MIN * 60;
        return $fin > time() ? (int)ceil(($fin - time()) / 60) : 0;
    }

    /**
     * Cuenta el intento (ver apuntarIntento()), o lo rechaza si la IP está
     * bloqueada.
     *
     * @throws RuntimeException con los minutos que faltan
     */
    private static function contarIntento(string $ip, string $quien): void
    {
        $espera = self::apuntarIntento($ip, $quien);
        if ($espera > 0) {
            throw new RuntimeException(t('Demasiados intentos fallidos. Prueba dentro de {espera} minuto(s).', ['espera' => $espera]));
        }
    }

    /**
     * Cuenta un intento de $quien desde $ip como fallido, si la IP no está
     * bloqueada; si lo está, devuelve los minutos que faltan y no cuenta nada.
     * Mirar y contar van con el fichero bloqueado: dos intentos a la vez no
     * pueden contar uno, ni pasar los dos con el último hueco.
     */
    private static function apuntarIntento(string $ip, string $quien): int
    {
        $espera = 0;
        Store::actualizar(self::INTENTOS, static function (array $intentos) use ($ip, $quien, &$espera): array {
            $espera = self::restante($intentos[$ip] ?? null);
            return $espera > 0 ? $intentos : self::sumarFallo($intentos, $ip, $quien);
        });
        return $espera;
    }

    /** @param array<string,array{fallos:int,ultimo:int,usuarios?:array<string,int>}> $intentos */
    private static function sumarFallo(array $intentos, string $ip, string $quien): array
    {
        $e = $intentos[$ip] ?? ['fallos' => 0, 'ultimo' => 0];

        // Pasado el tiempo del bloqueo desde el último fallo, se empieza a
        // contar de nuevo, haya llegado al bloqueo o no (2.9: si no, los fallos
        // no caducaban, y unos pocos despistes al mes acababan en un bloqueo)
        if ((int)$e['ultimo'] + ADMIN_LOGIN_BLOQUEO_MIN * 60 <= time()) {
            $e = ['fallos' => 0, 'ultimo' => 0];
        }
        $usuarios = (array)($e['usuarios'] ?? []);
        $usuarios[$quien] = (int)($usuarios[$quien] ?? 0) + 1;
        $intentos[$ip] = ['fallos' => (int)$e['fallos'] + 1, 'ultimo' => time(), 'usuarios' => $usuarios];

        // Poda de entradas viejas para que el fichero no crezca
        $corte = time() - ADMIN_LOGIN_BLOQUEO_MIN * 60 * 4;
        foreach ($intentos as $k => $v) {
            if ((int)($v['ultimo'] ?? 0) < $corte) { unset($intentos[$k]); }
        }
        return $intentos;
    }

    /**
     * Al entrar, se quitan los intentos de ese usuario desde esa IP (también
     * el de ahora), y solo esos (2.9): antes se borraban todos los de la IP,
     * y quien tuviera una cuenta podía probar contraseñas de otra sin llegar
     * nunca al bloqueo, entrando con la suya cada pocos intentos.
     */
    private static function quitarFallos(string $ip, string $quien): void
    {
        Store::actualizar(self::INTENTOS, static function (array $intentos) use ($ip, $quien): array {
            $e = $intentos[$ip] ?? null;
            if (!is_array($e) || !isset($e['usuarios'][$quien])) {
                return $intentos;
            }
            $e['fallos'] = (int)$e['fallos'] - (int)$e['usuarios'][$quien];
            unset($e['usuarios'][$quien]);
            if ($e['fallos'] <= 0) {
                unset($intentos[$ip]);
            } else {
                $intentos[$ip] = $e;
            }
            return $intentos;
        });
    }

    // ------------------------------------------------------------------
    // CSRF
    // ------------------------------------------------------------------

    public static function csrf(): string
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function comprobarCsrf(): void
    {
        $enviado = post('csrf');
        if ($enviado === '' || !hash_equals(self::csrf(), $enviado)) {
            throw new RuntimeException(t('Formulario caducado. Vuelve a intentarlo.'));
        }
    }

    /**
     * El token de los enlaces del selector de idioma, que deja guardar el
     * idioma en el usuario (2.9; ver index.php). Sacado del de CSRF, pero no
     * es él: va en la dirección, que queda en el historial y en los registros
     * del servidor, y solo sirve para esto.
     */
    public static function tokenIdioma(): string
    {
        return hash_hmac('sha256', 'idioma', self::csrf());
    }
}
