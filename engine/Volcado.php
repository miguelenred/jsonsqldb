<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Filas en ficheros temporales (2.9), para lo que una consulta no puede tener
 * entero en memoria: un JOIN o un GROUP BY con un millón de filas se reparten
 * en particiones por su clave, que luego se leen de una en una; una
 * subconsulta del FROM se escribe entera y se lee según se usa.
 *
 * Cada fila va serializada con su longitud delante, como en OrdenExterno:
 * serialize() conserva los tipos, y un texto puede llevar saltos de línea.
 * Los ficheros van en la carpeta .orden de la base, se abren al escribir la
 * primera fila de cada partición y se borran al terminar pase lo que pase;
 * los de un proceso que murió a medias, al crear el siguiente, pasada una hora.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Volcado
{
    /** @var array<int, resource> */
    private array $fh = [];
    /** @var array<int, string> */
    private array $ficheros = [];
    /** @var array<int, string> lo que aún no se ha escrito de cada partición (ver anadir()) */
    private array $pendiente = [];
    private string $carpeta;

    public function __construct(string $carpeta)
    {
        $this->carpeta = $carpeta;
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
            throw JsonSqlDbError::io("No se puede crear la carpeta temporal '$carpeta'");
        }
        foreach ((array)glob($carpeta . '/*.vol') as $f) {
            if ((int)@filemtime((string)$f) < time() - 3600) {
                @unlink((string)$f);
            }
        }
        register_shutdown_function([$this, 'borrar']);
    }

    /** Añade una fila a la partición $p. */
    public function anadir(array $fila, int $p = 0): void
    {
        if (!isset($this->fh[$p])) {
            $f  = $this->carpeta . '/' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.vol';
            $fh = @fopen($f, 'w+b');
            if ($fh === false) {
                throw JsonSqlDbError::io("No se puede escribir el temporal '$f'");
            }
            [$this->fh[$p], $this->ficheros[$p]] = [$fh, $f];
        }
        // A bloques de 16 KB por partición, no fila a fila (2.9): un fwrite()
        // por fila era una séptima parte de un GROUP BY de un millón por el disco
        $s = serialize($fila);
        $this->pendiente[$p] = ($this->pendiente[$p] ?? '') . pack('N', strlen($s)) . $s;
        if (strlen($this->pendiente[$p]) >= 16384) {
            $this->escribir($p);
        }
    }

    private function escribir(int $p): void
    {
        if (($this->pendiente[$p] ?? '') !== '' && fwrite($this->fh[$p], $this->pendiente[$p]) === false) {
            throw JsonSqlDbError::io("No se puede escribir el temporal '{$this->ficheros[$p]}'");
        }
        unset($this->pendiente[$p]);
    }

    /**
     * Las filas de la partición $p, en el orden en que llegaron; al acabar de
     * leerla, su fichero se borra.
     *
     * @return \Generator<int, array>
     */
    public function leer(int $p = 0): \Generator
    {
        $fh = $this->fh[$p] ?? null;
        if ($fh === null) {
            return;
        }
        try {
            $this->escribir($p);
            rewind($fh);
            // A bloques de 64 KB (ver OrdenExterno::leerTramo()); una fila que
            // cruza el bloque se completa con el siguiente
            $bloque  = '';
            $pos     = 0;
            $vigilar = Memoria::cada();            // filas hasta mirar la memoria (ver Storage::filas())
            while (true) {
                if ($pos + 4 > strlen($bloque) || $pos + 4 + ($largo = unpack('N', $bloque, $pos)[1]) > strlen($bloque)) {
                    $mas = fread($fh, 65536);
                    if ($mas === false || $mas === '') {
                        break;
                    }
                    $bloque = substr($bloque, $pos) . $mas;
                    $pos    = 0;
                    continue;
                }
                if (--$vigilar === 0) {
                    Memoria::revisar('la lectura del temporal');
                    $vigilar = Memoria::cada();
                }
                yield unserialize(substr($bloque, $pos + 4, $largo), ['allowed_classes' => false]);
                $pos += 4 + $largo;
            }
        } finally {
            fclose($fh);
            @unlink($this->ficheros[$p]);
            unset($this->fh[$p], $this->ficheros[$p]);
        }
    }

    /** Borra los temporales. Pública para poder registrarla al cierre. */
    public function borrar(): void
    {
        foreach ($this->fh as $p => $fh) {
            @fclose($fh);
            @unlink($this->ficheros[$p]);
        }
        $this->fh = $this->ficheros = $this->pendiente = [];
    }
}
