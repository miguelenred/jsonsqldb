<?php
declare(strict_types=1);

/**
 * Usuarios del panel (bcrypt, en JSON), sesión, límite de intentos y CSRF.
 *
 * Roles:
 *   admin    todo
 *   lectura  ver la estructura y los datos, y lanzar SELECT/SHOW
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
            'secure'   => ($_SERVER['HTTPS'] ?? 'off') !== 'off',
        ]);
        session_start();

        // Caducidad por inactividad
        $limite = ADMIN_SESION_MINUTOS * 60;
        if (isset($_SESSION['visto']) && time() - (int)$_SESSION['visto'] > $limite) {
            self::cerrar();
            session_start();
            $_SESSION['aviso'] = 'La sesión ha caducado por inactividad.';
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

    public static function esAdmin(): bool
    {
        return (($_SESSION['usuario']['rol'] ?? '') === 'admin');
    }

    /** Corta la petición si el usuario no es administrador. */
    public static function exigirAdmin(): void
    {
        if (!self::esAdmin()) {
            throw new RuntimeException('Esta acción necesita permiso de administrador.');
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

    /** Crea un usuario. Devuelve el nombre normalizado. */
    public static function crear(string $usuario, string $clave, string $rol): string
    {
        $usuario = trim($usuario);
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,32}$/', $usuario)) {
            throw new RuntimeException('El usuario admite de 3 a 32 caracteres: letras, números y . _ - @');
        }
        if (!in_array($rol, ['admin', 'lectura'], true)) {
            throw new RuntimeException('Rol no válido');
        }
        self::validarClave($clave);
        if (self::buscar($usuario) !== null) {
            throw new RuntimeException("El usuario '$usuario' ya existe");
        }

        $usuarios = self::usuarios();
        $usuarios[] = [
            'usuario' => $usuario,
            'hash'    => password_hash($clave, PASSWORD_BCRYPT, ['cost' => ADMIN_BCRYPT_COSTE]),
            'rol'     => $rol,
            'creado'  => date('Y-m-d H:i:s'),
            'acceso'  => null,
        ];
        Store::guardar(self::USUARIOS, $usuarios);
        return $usuario;
    }

    public static function cambiarClave(string $usuario, string $clave): void
    {
        self::validarClave($clave);
        $usuarios = self::usuarios();
        foreach ($usuarios as $i => $u) {
            if (strcasecmp((string)$u['usuario'], $usuario) === 0) {
                $usuarios[$i]['hash'] = password_hash($clave, PASSWORD_BCRYPT, ['cost' => ADMIN_BCRYPT_COSTE]);
                Store::guardar(self::USUARIOS, $usuarios);
                return;
            }
        }
        throw new RuntimeException("El usuario '$usuario' no existe");
    }

    public static function borrar(string $usuario): void
    {
        $usuarios = self::usuarios();
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
            throw new RuntimeException("El usuario '$usuario' no existe");
        }
        if ($admins === 0) {
            throw new RuntimeException('Tiene que quedar al menos un administrador');
        }
        Store::guardar(self::USUARIOS, $quedan);
    }

    private static function validarClave(string $clave): void
    {
        if (strlen($clave) < 10) {
            throw new RuntimeException('La contraseña necesita al menos 10 caracteres');
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
        $espera = self::bloqueoRestante($ip);
        if ($espera > 0) {
            throw new RuntimeException("Demasiados intentos fallidos. Prueba dentro de $espera minuto(s).");
        }

        $u = self::buscar($usuario);
        // password_verify siempre, exista o no el usuario: mismo tiempo de respuesta
        $hash = (string)($u['hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv');
        if (!password_verify($clave, $hash) || $u === null) {
            self::apuntarFallo($ip);
            throw new RuntimeException('Usuario o contraseña incorrectos');
        }

        self::limpiarFallos($ip);
        session_regenerate_id(true);
        $_SESSION['usuario'] = ['usuario' => $u['usuario'], 'rol' => $u['rol']];
        $_SESSION['visto']   = time();
        $_SESSION['csrf']    = bin2hex(random_bytes(32));

        $usuarios = self::usuarios();
        foreach ($usuarios as $i => $x) {
            if (strcasecmp((string)$x['usuario'], (string)$u['usuario']) === 0) {
                $usuarios[$i]['acceso'] = date('Y-m-d H:i:s');
            }
        }
        Store::guardar(self::USUARIOS, $usuarios);
    }

    /** Minutos que faltan para poder reintentar, 0 si no hay bloqueo. */
    public static function bloqueoRestante(string $ip): int
    {
        $intentos = Store::leer(self::INTENTOS);
        $e = $intentos[$ip] ?? null;
        if (!is_array($e) || (int)$e['fallos'] < ADMIN_LOGIN_MAX_FALLOS) {
            return 0;
        }
        $fin = (int)$e['ultimo'] + ADMIN_LOGIN_BLOQUEO_MIN * 60;
        return $fin > time() ? (int)ceil(($fin - time()) / 60) : 0;
    }

    private static function apuntarFallo(string $ip): void
    {
        $intentos = Store::leer(self::INTENTOS);
        $e = $intentos[$ip] ?? ['fallos' => 0, 'ultimo' => 0];

        // Si el bloqueo anterior ya expiró, se empieza a contar de nuevo
        if ((int)$e['fallos'] >= ADMIN_LOGIN_MAX_FALLOS
            && (int)$e['ultimo'] + ADMIN_LOGIN_BLOQUEO_MIN * 60 <= time()) {
            $e = ['fallos' => 0, 'ultimo' => 0];
        }
        $intentos[$ip] = ['fallos' => (int)$e['fallos'] + 1, 'ultimo' => time()];

        // Poda de entradas viejas para que el fichero no crezca
        $corte = time() - ADMIN_LOGIN_BLOQUEO_MIN * 60 * 4;
        foreach ($intentos as $k => $v) {
            if ((int)($v['ultimo'] ?? 0) < $corte) { unset($intentos[$k]); }
        }
        Store::guardar(self::INTENTOS, $intentos);
    }

    private static function limpiarFallos(string $ip): void
    {
        $intentos = Store::leer(self::INTENTOS);
        if (isset($intentos[$ip])) {
            unset($intentos[$ip]);
            Store::guardar(self::INTENTOS, $intentos);
        }
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
        $enviado = (string)($_POST['csrf'] ?? '');
        if ($enviado === '' || !hash_equals(self::csrf(), $enviado)) {
            throw new RuntimeException('Formulario caducado. Vuelve a intentarlo.');
        }
    }
}
