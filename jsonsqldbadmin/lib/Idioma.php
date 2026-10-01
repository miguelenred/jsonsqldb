<?php
declare(strict_types=1);

/**
 * Idioma del panel: español o inglés.
 *
 * Cuál se usa, por orden:
 *  1. El que ha elegido el usuario que ha entrado (se guarda con él y le sigue
 *     en cualquier navegador).
 *  2. El elegido en esta sesión antes de entrar (pantalla de entrada,
 *     asistente de instalación).
 *  3. El del navegador (cabecera Accept-Language): el primero de su lista que
 *     el panel tenga.
 *  4. Inglés.
 *
 * Los textos se escriben en español en el código, dentro de t(), y su
 * traducción está en idiomas/en.php con el texto en español como clave. Los
 * mensajes que vienen del motor no se traducen: son del motor.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Idioma
{
    public const DISPONIBLES = ['es' => 'Español', 'en' => 'English'];
    private const POR_DEFECTO = 'en';

    private static ?string $actual = null;
    /** @var array<string,string>|null */
    private static ?array $ingles = null;

    public static function actual(): string
    {
        if (self::$actual !== null) {
            return self::$actual;
        }
        // Fuera del panel (una herramienta o una prueba que carga sus clases) no hay usuarios
        $conUsuario = class_exists('Auth', false) && Auth::identificado();
        $elegido = $conUsuario ? Auth::idiomaDe((string)($_SESSION['usuario']['usuario'] ?? '')) : null;
        $elegido ??= $_SESSION['idioma'] ?? null;
        return self::$actual = isset(self::DISPONIBLES[$elegido]) ? (string)$elegido : self::delNavegador();
    }

    /**
     * El primero de los idiomas del navegador que hay aquí, por su orden de
     * preferencia (q). Sin ninguno, inglés.
     */
    public static function delNavegador(?string $cabecera = null): string
    {
        $cabecera ??= (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $lista = [];
        foreach (explode(',', $cabecera) as $n => $parte) {
            if (!preg_match('/^\s*([a-zA-Z]{1,8})(?:-[a-zA-Z0-9]{1,8})*\s*(?:;\s*q\s*=\s*([0-9.]+))?\s*$/', $parte, $m)) {
                continue;
            }
            // A igual preferencia, gana el que va antes
            $lista[] = [(float)($m[2] ?? 1), -$n, strtolower($m[1])];
        }
        rsort($lista);
        foreach ($lista as [$q, , $idioma]) {
            if ($q > 0 && isset(self::DISPONIBLES[$idioma])) {
                return $idioma;
            }
        }
        return self::POR_DEFECTO;
    }

    /** Cambia el idioma: en la sesión y, si alguien ha entrado, en su usuario. */
    public static function elegir(string $idioma): void
    {
        if (!isset(self::DISPONIBLES[$idioma])) {
            return;
        }
        $_SESSION['idioma'] = $idioma;
        if (class_exists('Auth', false) && Auth::identificado()) {
            Auth::guardarIdioma((string)$_SESSION['usuario']['usuario'], $idioma);
        }
        self::$actual = $idioma;
    }

    /** Un texto en el idioma actual. {nombre} se sustituye por $valores['nombre']. */
    public static function texto(string $texto, array $valores = []): string
    {
        if (self::actual() !== 'es') {
            self::$ingles ??= require dirname(__DIR__) . '/idiomas/en.php';
            $texto = self::$ingles[$texto] ?? $texto;
        }
        foreach ($valores as $clave => $valor) {
            $texto = str_replace('{' . $clave . '}', (string)$valor, $texto);
        }
        return $texto;
    }

    /** Un número con los separadores del idioma: 1.234,5 o 1,234.5. */
    public static function numero(float $n, int $decimales = 0): string
    {
        return self::actual() === 'es'
            ? number_format($n, $decimales, ',', '.')
            : number_format($n, $decimales, '.', ',');
    }
}

/** Atajo de Idioma::texto(): t('Guardar'), t('Se cargaron {n} filas', ['n' => 5]). */
function t(string $texto, array $valores = []): string
{
    return Idioma::texto($texto, $valores);
}
