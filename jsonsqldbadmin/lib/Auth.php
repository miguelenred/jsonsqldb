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
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,32}$/', trim($usuario))) {
            throw new RuntimeException(t('El usuario admite de 3 a 32 caracteres: letras, números y . _ - @'));
        }
        self::validarClave($clave);
        if (self::buscar(trim($usuario)) !== null) {
            throw new RuntimeException("El usuario '" . trim($usuario) . "' ya existe");
        }
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
            throw new RuntimeException(t('Demasiados intentos fallidos. Prueba dentro de {espera} minuto(s).', ['espera' => $espera]));
        }

        $u = self::buscar($usuario);
        // password_verify siempre, exista o no el usuario: mismo tiempo de respuesta
        $hash = (string)($u['hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv');
        if (!password_verify($clave, $hash) || $u === null) {
            self::apuntarFallo($ip);
            throw new RuntimeException(t('Usuario o contraseña incorrectos'));
        }

        self::limpiarFallos($ip);
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
        // Con el fichero bloqueado: dos intentos a la vez no pueden contar uno
        Store::actualizar(self::INTENTOS, static function (array $intentos) use ($ip): array {
            return self::sumarFallo($intentos, $ip);
        });
    }

    /** @param array<string,array{fallos:int,ultimo:int}> $intentos */
    private static function sumarFallo(array $intentos, string $ip): array
    {
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
        return $intentos;
    }

    private static function limpiarFallos(string $ip): void
    {
        if (isset(Store::leer(self::INTENTOS)[$ip])) {
            Store::actualizar(self::INTENTOS, static function (array $intentos) use ($ip): array {
                unset($intentos[$ip]);
                return $intentos;
            });
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
            throw new RuntimeException(t('Formulario caducado. Vuelve a intentarlo.'));
        }
    }
}
