<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Los temporales que deja una escritura que no acabó (2.10, aparte de
 * StorageEscritura para que una escritura no tenga que compilarlo: se barre
 * si la anterior murió a medias y en una de cada 32, ver
 * StorageEscritura::abrirEscritura()).
 *
 * Métodos de Storage que reciben el almacenamiento en $st. Hereda de Storage
 * solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageBarrido extends Storage
{
    private function __construct()
    {
    }

    /** ¿Sigue vivo el proceso de una línea de StorageEscritura::marcar()? Este mismo cuenta como vivo; uno que no se sabe, como muerto. */
    public static function vivo(string $linea): bool
    {
        if (!preg_match('/^escribiendo: pid (\d+) desde ([0-9: -]{19})/', $linea, $m)) {
            return false;
        }
        // Sin poder preguntar por el proceso, como sus temporales: vivo si es de hace menos de un minuto
        return (int)$m[1] === getmypid() || (self::pidVivo((int)$m[1]) ?? time() - (int)strtotime($m[2]) <= 60);
    }

    /** Los temporales de la base y de su journal: con su exclusivo, todos son de procesos muertos (ver Storage::bloquear()) */
    public static function base(Storage $st): void
    {
        foreach ((array)glob($st->dir . '/*.tmp') as $f) {
            @unlink((string)$f);
        }
        foreach ((array)glob($st->dirTx . '/*.tmp') as $f) {
            @unlink((string)$f);                      // un manifiesto que no llegó a escribirse
        }
    }

    /**
     * Borra los temporales de una tabla que son de procesos que ya no existen:
     * el nombre lleva el pid de quien lo escribió. Si no se puede saber si ese
     * proceso vive (sin /proc ni posix), solo los de más de un minuto.
     */
    public static function temporalesMuertos(Storage $st, string $tabla): void
    {
        $yo = getmypid();
        foreach ((array)glob($st->dir . '/' . $tabla . '.*.tmp') as $f) {
            $f = (string)$f;
            if (!preg_match('/\.(\d+)(?:\.[a-z]+)?\.tmp$/', $f, $m) || (int)$m[1] === $yo) {
                continue;
            }
            if ((self::pidVivo((int)$m[1]) ?? (time() - (int)@filemtime($f)) <= 60) === false) {
                @unlink($f);
            }
        }
    }

    /** ¿Existe ese proceso? Null si no se puede saber (sin /proc ni posix). */
    private static function pidVivo(int $pid): ?bool
    {
        // Con @: un hosting con open_basedir no deja ni mirar /proc, y el aviso
        // salía por pantalla en medio de la respuesta
        if (@is_dir('/proc/self')) {
            return @is_dir("/proc/$pid");
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0) || posix_get_last_error() !== 3;     // ESRCH: no existe
        }
        return null;
    }
}
