<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Vigilante de memoria.
 *
 * Se mide con memory_get_usage() SIN el parámetro `real`. La diferencia importa:
 * el valor «real» incluye los bloques que PHP ya ha pedido al sistema y que
 * conserva para reutilizarlos, aunque estén libres. Tras una consulta grande ese
 * número se queda alto —28 MB reservados con solo 1,5 MB en uso— y la siguiente
 * consulta, por pequeña que fuera, se cortaba nada más empezar. Lo que crece y
 * acaba topando con el límite es la memoria realmente en uso.
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
    /**
     * Cada cuántas filas se mira. Lejos del límite basta con mirar de vez en
     * cuando; cerca hay que mirar a menudo, porque un solo salto del array puede
     * pasarse de largo. Comprobar cuesta 0,01 microsegundos, así que apretar
     * cerca del límite no se nota.
     */
    private const CADA_LEJOS = 512;
    private const CADA_CERCA = 8;

    /** A partir de qué fracción del límite se empieza a mirar más a menudo. */
    private const VIGILANCIA_ESTRECHA = 0.5;

    /** Cuántos saltos como el último se reservan antes de cortar. */
    private const SALTOS_RESERVADOS = 4;

    /**
     * Cuánto ocupa un fichero ya convertido a datos, respecto a su tamaño.
     *
     * Son dos números porque los dos formatos se expanden de forma muy distinta:
     * medido sobre una tabla de 20.000 filas, el JSON de 1,9 MB y la caché
     * serializada de 8,1 MB acababan siendo los mismos 26 MB de arrays.
     */
    private const FACTOR_JSON  = 14;
    private const FACTOR_CACHE = 3.5;

    private static int $limite  = 0;      // memory_limit en bytes, 0 = sin límite
    private static int $techo   = 0;      // bytes a partir de los cuales se corta
    private static int $cuenta  = 0;
    private static int $cada    = self::CADA_LEJOS;
    private static int $ultimo  = 0;      // memoria en la comprobación anterior

    /**
     * Prepara el vigilante para la consulta que empieza.
     *
     * Se llama una vez por consulta: releer el límite en cada fila sería tirar
     * el tiempo, porque no cambia durante la petición.
     */
    public static function iniciar(): void
    {
        self::$cuenta = 0;
        self::$cada   = self::CADA_LEJOS;
        self::$techo  = 0;
        self::$limite = 0;
        self::$ultimo = memory_get_usage();

        if (!Config::limiteMemoriaActivo()) {
            return;
        }
        $limite = self::limiteEnBytes();
        if ($limite <= 0) {
            return;                        // sin límite: nada que vigilar
        }
        self::$limite = $limite;
        self::$techo  = (int)($limite * Config::margenMemoria());
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
        if ((++self::$cuenta % self::$cada) !== 0) {
            return;
        }

        $uso   = memory_get_usage();

        // Al pasar de la mitad del límite se mira mucho más a menudo
        if (self::$cada !== self::CADA_CERCA
            && $uso > self::$limite * self::VIGILANCIA_ESTRECHA) {
            self::$cada = self::CADA_CERCA;
        }

        $salto = max(0, $uso - self::$ultimo);
        self::$ultimo = $uso;

        // Mirar solo el techo no basta: entre dos comprobaciones el consumo
        // puede pegar un salto mayor que el margen que queda y llegar al fatal
        // sin pasar por aquí, porque PHP duplica la tabla hash al crecer y
        // porque una sola fila grande puede ocupar mucho. Por eso se reserva
        // varias veces lo que acaba de crecer, contado por fila comprobada.
        $porFila   = (int)ceil($salto / max(1, self::$cada));
        $reservado = max($salto, $porFila * self::CADA_CERCA) * self::SALTOS_RESERVADOS;

        $cabeOtroSalto = ($uso + $reservado) < self::$limite;

        if ($uso < self::$techo && $cabeOtroSalto) {
            return;
        }

        $usada = round($uso / 1048576, 1);
        $tope  = round(self::$limite / 1048576, 1);

        throw JsonSqlDbError::memoria(
            "Se ha cortado $donde: lleva {$usada} MB de los {$tope} MB que PHP tiene asignados. "
            . 'Acota la consulta con WHERE o LIMIT, o sube memory_limit si de verdad necesitas '
            . 'ese volumen de una vez.'
        );
    }

    /**
     * Comprueba ANTES de leer un fichero si su contenido va a caber.
     *
     * Comprobar cada 512 filas no sirve aquí: file_get_contents() y json_decode()
     * materializan el fichero entero de golpe, y el pico ocurre antes de que
     * nadie pueda mirar nada. Un JSON de 200 MB agota la memoria en una sola
     * instrucción.
     *
     * El factor es una estimación: un array de PHP ocupa bastante más que el
     * texto del que sale, porque cada fila es una tabla hash con sus claves, y
     * cuánto más depende de la forma de los datos —muchas columnas cortas
     * inflan más que pocas largas—. Se cuentan seis veces el tamaño del fichero.
     *
     * Esto **reduce** la ventana, no la cierra: con datos que se expandan más de
     * lo estimado se puede seguir llegando al fatal. Cerrarla del todo exigiría
     * leer el fichero por trozos en vez de entero, que es un cambio de fondo del
     * almacenamiento.
     */
    public static function comprobarFichero(string $fichero): void
    {
        if (self::$limite === 0 || !is_file($fichero)) {
            return;
        }
        $bytes = (int)filesize($fichero);
        if ($bytes <= 0) {
            return;
        }

        $factor    = substr($fichero, -6) === '.cache' ? self::FACTOR_CACHE : self::FACTOR_JSON;
        $necesaria = memory_get_usage() + (int)($bytes * $factor);
        if ($necesaria < self::$limite) {
            return;
        }

        throw JsonSqlDbError::memoria(
            'No se puede leer ' . basename($fichero) . ': ocupa '
            . round($bytes / 1048576, 1) . ' MB y, ya convertido a datos, no cabría en los '
            . round(self::$limite / 1048576, 1) . ' MB que PHP tiene asignados. '
            . 'Sube memory_limit o reparte la tabla en varias más pequeñas.'
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
