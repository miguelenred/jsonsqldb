<?php
declare(strict_types=1);

/**
 * Lo que las pruebas piden al sistema, igual en Linux, macOS y Windows
 * (2.10): lanzar este mismo PHP en otro proceso sin pasar por el intérprete
 * de órdenes, y copiar o borrar una carpeta con todo lo que tenga.
 *
 * Con shell_exec() y escapeshellarg(), en Windows el código de un -r llegaba
 * roto (allí escapeshellarg() cambia las comillas dobles por espacios), y
 * «rm -rf», «cp -r» y «timeout» no existen.
 *
 * https://miguelenred.es/jsonsqldb
 */

/**
 * Ejecuta este PHP con $args (por ejemplo ['-d', 'memory_limit=16M', '-r', $codigo])
 * y devuelve lo que escribe, con lo de la salida de errores en su sitio si
 * $errores. Con $segundos > 0, si sigue en marcha pasado ese tiempo lo para
 * y devuelve lo que llevaba escrito.
 */
function phpHijo(array $args, bool $errores = true, float $segundos = 0): string
{
    if ($segundos > 0) {
        return phpRecoger(phpLanzar($args, $errores), $segundos);
    }
    $p = proc_open(array_merge([PHP_BINARY], $args), [0 => ['file', ficheroNulo(), 'r'], 1 => ['pipe', 'w'],
        2 => $errores ? ['redirect', 1] : ['file', ficheroNulo(), 'w']], $tubos);
    if (!is_resource($p)) {
        return '';
    }
    $texto = (string)stream_get_contents($tubos[1]);
    fclose($tubos[1]);
    proc_close($p);
    return $texto;
}

/**
 * Lanza este PHP con $args y vuelve sin esperar; phpRecoger() lo espera. Lo
 * que escribe va a un fichero temporal: en Windows una tubería no se puede
 * leer sin quedarse esperando a que el proceso acabe.
 *
 * @return array{0: resource|false, 1: string} el proceso y su fichero
 */
function phpLanzar(array $args, bool $errores = true): array
{
    $fichero = (string)tempnam(sys_get_temp_dir(), 'hijo');
    $p = proc_open(array_merge([PHP_BINARY], $args), [0 => ['file', ficheroNulo(), 'r'], 1 => ['file', $fichero, 'w'],
        2 => $errores ? ['redirect', 1] : ['file', ficheroNulo(), 'w']], $tubos);
    return [$p, $fichero];
}

/**
 * Espera a un proceso de phpLanzar(), como mucho $segundos (0: lo que tarde),
 * y devuelve lo que escribió; si sigue en marcha, lo para.
 *
 * @param array{0: resource|false, 1: string} $hijo
 */
function phpRecoger(array $hijo, float $segundos = 0): string
{
    [$p, $fichero] = $hijo;
    if (is_resource($p)) {
        for ($hasta = microtime(true) + $segundos; proc_get_status($p)['running'] && ($segundos <= 0 || microtime(true) < $hasta);) {
            usleep(10000);
        }
        proc_terminate($p, 9);
        proc_close($p);
    }
    $texto = (string)@file_get_contents($fichero);
    @unlink($fichero);
    return $texto;
}

/** Donde se tira lo que no se quiere leer: NUL en Windows, /dev/null en los demás */
function ficheroNulo(): string
{
    return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
}

/** Copia la carpeta $desde, con todo lo que tenga, en $hasta. */
function copiarArbol(string $desde, string $hasta): void
{
    if (!is_dir($desde)) {
        copy($desde, $hasta);
        return;
    }
    @mkdir($hasta, 0775, true);
    foreach ((array)scandir($desde) as $f) {
        if ($f !== '.' && $f !== '..') {
            copiarArbol("$desde/$f", "$hasta/$f");
        }
    }
}

/** Borra cada carpeta con todo lo que tenga (o el fichero, si es uno). */
function borrarArbol(string ...$rutas): void
{
    foreach ($rutas as $ruta) {
        if (!is_dir($ruta)) {
            @unlink($ruta);
            continue;
        }
        foreach ((array)scandir($ruta) as $f) {
            if ($f !== '.' && $f !== '..') {
                borrarArbol("$ruta/$f");
            }
        }
        @rmdir($ruta);
    }
}
