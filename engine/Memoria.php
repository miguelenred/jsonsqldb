<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Vigilante de memoria.
 *
 * El resultado de una consulta vive entero en memoria. Si se pide más de la que
 * PHP tiene asignada, PHP corta con un error fatal: no es una excepción, no se
 * puede capturar, no se ejecuta ningún finally y el cliente recibe una respuesta
 * rota en lugar de un mensaje.
 *
 * Esto lo vigila desde dentro. Cada cierto número de filas mira cuánta memoria
 * se lleva consumida y, si se acerca al techo, corta la consulta con un error
 * normal del motor: el proceso sigue vivo, los bloqueos se sueltan por el camino
 * de siempre y el cliente recibe un JSON explicando qué ha pasado.
 *
 * No hace milagros: una consulta que necesita más memoria de la que hay no se
 * puede completar. Lo que cambia es CÓMO falla.
 */
final class Memoria
{
    /** Cada cuántas filas se mira. Mirar en cada una costaría más que ahorrar. */
    private const CADA = 512;

    private static int $techo   = 0;      // bytes a partir de los cuales se corta
    private static int $cuenta  = 0;

    /**
     * Prepara el vigilante para la consulta que empieza.
     *
     * Se llama una vez por consulta: releer el límite en cada fila sería tirar
     * el tiempo, porque no cambia durante la petición.
     */
    public static function iniciar(): void
    {
        self::$cuenta = 0;
        self::$techo  = 0;

        if (!Config::limiteMemoriaActivo()) {
            return;
        }
        $limite = self::limiteEnBytes();
        if ($limite <= 0) {
            return;                        // sin límite: nada que vigilar
        }
        self::$techo = (int)($limite * Config::margenMemoria());
    }

    /**
     * Se llama mientras se acumulan filas. Corta si queda poco margen.
     *
     * @param string $donde qué se estaba haciendo, para que el error lo diga
     */
    public static function comprobar(string $donde = 'la consulta'): void
    {
        if (self::$techo === 0) {
            return;
        }
        if ((++self::$cuenta % self::CADA) !== 0) {
            return;
        }
        if (memory_get_usage(true) < self::$techo) {
            return;
        }

        $usada = round(memory_get_usage(true) / 1048576, 1);
        $tope  = round(self::limiteEnBytes() / 1048576, 1);

        throw JsonSqlDbError::memoria(
            "Se ha cortado $donde: lleva {$usada} MB de los {$tope} MB que PHP tiene asignados. "
            . 'Acota la consulta con WHERE o LIMIT, o sube memory_limit si de verdad necesitas '
            . 'ese volumen de una vez.'
        );
    }

    /** memory_limit en bytes. Devuelve 0 si no hay límite. */
    private static function limiteEnBytes(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int)$v;
        switch (strtoupper(substr($v, -1))) {
            case 'G': return $n * 1024 * 1024 * 1024;
            case 'M': return $n * 1024 * 1024;
            case 'K': return $n * 1024;
        }
        return $n;
    }
}
