<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Ordenación en disco para un ORDER BY que no cabe en memoria (2.9).
 *
 * Las filas llegan con sus claves de orden. Mientras caben, se juntan en
 * memoria; cuando la memoria aprieta (ver Memoria::apretado()), las juntadas
 * se ordenan y se escriben a un fichero temporal —un «tramo» ya ordenado— y
 * se sueltan. Al final se mezclan todos los tramos leyéndolos a la vez, fila a
 * fila: en memoria solo hay una fila por tramo.
 *
 * El orden es exactamente el de la ordenación en memoria (SelectOrden::
 * comparadorDe()): Valor::compararOrden() clave a clave, DESC invertido, y a
 * igualdad, el orden de llegada; entre tramos, el que se escribió antes.
 *
 * Los temporales van en la carpeta .orden de la base, se borran al terminar
 * pase lo que pase, y uno que se quedó de un proceso muerto se borra al crear
 * el siguiente, pasada una hora.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class OrdenExterno
{
    /** @var list<array{dir: string}> el ORDER BY */
    private array $orden;
    /** @var list<bool> si cada clave va en orden descendente */
    private array $desc;
    private string $carpeta;
    /** @var list<array{0: list<mixed>, 1: array}> claves y fila, en orden de llegada */
    private array $junto = [];
    /** @var list<string> ficheros de los tramos ya escritos */
    private array $tramos = [];
    private int $llegadas = 0;
    /** Memoria en uso al escribir el último tramo: el siguiente, con al menos 2 MB más */
    private int $tras = 0;
    /** Memoria en uso a partir de la cual lo juntado se escribe en un tramo: la mitad del límite */
    private int $techo;

    /** @param list<array{dir: string}> $orden */
    public function __construct(array $orden, string $carpeta)
    {
        $this->orden   = array_values($orden);
        $this->desc    = array_map(static fn(array $o): bool => $o['dir'] === 'DESC', $this->orden);
        $this->carpeta = $carpeta;
        $limite        = Memoria::limiteEnBytes();
        $this->techo   = $limite > 0 ? intdiv($limite, 2) : PHP_INT_MAX;
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
            throw JsonSqlDbError::io("No se puede crear la carpeta temporal de ordenación '$carpeta'");
        }
        // Tramos de un proceso que murió a medias (sin memoria, matado)
        foreach ((array)glob($carpeta . '/*.tramo') as $f) {
            if ((int)@filemtime((string)$f) < time() - 3600) {
                @unlink((string)$f);
            }
        }
        register_shutdown_function([$this, 'borrar']);
    }

    /** @param list<mixed> $claves */
    public function anadir(array $claves, array $fila): void
    {
        $this->junto[] = [$claves, $fila];
        // Se mira la memoria en uso, no la reservada (Memoria::apretado()):
        // PHP no devuelve la reservada, que se queda en lo más alto que llegó
        // (también en el intento de ordenar en memoria), y entonces cada 2 MB
        // de filas era un tramo: un millón de filas en 256 MB, 389 tramos y
        // casi tres minutos de mezcla (2.9). Cada tramo lleva al menos 2 MB
        if ((++$this->llegadas & 255) === 0 && memory_get_usage() > $this->techo && memory_get_usage() - $this->tras > 2097152) {
            $this->volcar();
        }
    }

    /**
     * Las filas en orden, saltando $desde y hasta $cuantas (null: todas).
     *
     * @return \Generator<int, array>
     */
    public function filas(int $desde = 0, ?int $cuantas = null): \Generator
    {
        try {
            if ($this->tramos === []) {
                $fuentes = [$this->ordenadas()];
            } else {
                $this->volcar();
                $fuentes = array_map([$this, 'leerTramo'], $this->tramos);
            }
            // Mezcla: en cada paso, la cabeza menor de todos los tramos. Son
            // pocos (uno por cada vez que la memoria apretó): basta mirarlos todos
            $cabezas = [];
            foreach ($fuentes as $i => $g) {
                if ($g->valid()) {
                    $cabezas[$i] = $g->current();
                }
            }
            $vigilar = Memoria::cada();                // filas hasta mirar la memoria (ver Storage::filas())
            while ($cabezas !== [] && $cuantas !== 0) {
                $mejor = null;
                foreach ($cabezas as $i => $c) {
                    if ($mejor === null || $this->comparar($c[0], $cabezas[$mejor][0]) < 0) {
                        $mejor = $i;                   // a igualdad gana el tramo anterior
                    }
                }
                if ($desde > 0) {
                    $desde--;
                } else {
                    if (--$vigilar === 0) {
                        Memoria::revisar('la ordenación');
                        $vigilar = Memoria::cada();
                    }
                    yield $cabezas[$mejor][1];
                    if ($cuantas !== null) {
                        $cuantas--;
                    }
                }
                $fuentes[$mejor]->next();
                if ($fuentes[$mejor]->valid()) {
                    $cabezas[$mejor] = $fuentes[$mejor]->current();
                } else {
                    unset($cabezas[$mejor]);
                }
            }
        } finally {
            // Primero se cierran los tramos (2.9): soltar los lectores ejecuta
            // su finally, que cierra cada fichero. Abiertos, Windows no deja
            // borrarlos y se quedaban hasta que otra ordenación los barría, una
            // hora después
            unset($fuentes, $g);
            $this->borrar();
        }
    }

    /** Borra los temporales. Pública para poder registrarla al cierre. */
    public function borrar(): void
    {
        foreach ($this->tramos as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tramos = [];
        $this->junto  = [];
    }

    /** -1, 0 o 1, como SelectOrden::comparadorDe() sin el desempate por posición. */
    private function comparar(array $a, array $b): int
    {
        foreach ($this->desc as $i => $desc) {
            $x = $a[$i];
            $y = $b[$i];
            // Dos números, aquí mismo: es lo que Valor::compararOrden() acabaría
            // haciendo, y la mezcla compara una vez por fila y tramo (2.9)
            $c = (\is_int($x) || \is_float($x)) && (\is_int($y) || \is_float($y))
                ? ($x <=> $y ?: (\is_int($x) === \is_int($y) ? 0 : Valor::mezclados($x, $y)))   // ver Valor::numeros()
                : Valor::compararOrden($x, $y, !empty($this->orden[$i]['binaria']));
            if ($c !== 0) {
                return $desc ? -$c : $c;
            }
        }
        return 0;
    }

    /** @return \Generator<int, array{0: list<mixed>, 1: array}> lo juntado, ordenado (estable) */
    private function ordenadas(): \Generator
    {
        foreach ($this->enOrden(0, \count($this->junto)) as $i) {
            yield $this->junto[$i];
            unset($this->junto[$i]);
        }
    }

    /**
     * Las posiciones de lo juntado de $desde a $hasta (sin ella), en orden
     * (estable). Por columnas y con la ordenación nativa, como en memoria (ver
     * SelectOrden::ordenNativo(), que cuenta las filas desde 0); con el
     * comparador de PHP solo si una clave mezcla tipos. Con usort() y el
     * comparador siempre, ordenar los tramos de un millón de filas costaba más
     * que todo lo demás (2.9).
     *
     * Si ordenarlas de una vez no cabe (Memoria::antesDePedir()), cada mitad
     * por su lado, y las dos se mezclan en el mismo tramo, a igualdad la
     * primera (2.10): en PHP 8.0 y 8.1, con su memoria troceada, un ORDER BY
     * en disco se cortaba así al escribir un tramo con memoria de sobra.
     *
     * @return list<int>
     */
    private function enOrden(int $desde, int $hasta): array
    {
        $columnas = [];
        $filas    = $desde === 0 && $hasta === \count($this->junto) ? $this->junto : \array_slice($this->junto, $desde, $hasta - $desde);
        foreach ($filas as $n => [$claves]) {
            foreach ($claves as $i => $v) {
                $columnas[$i][$n] = $v;
            }
        }
        $pos = array_keys($filas);
        unset($filas);
        try {
            $orden = SelectOrden::ordenNativo($this->orden, $columnas, $pos);
        } catch (JsonSqlDbError $e) {
            if ($e->sqlState !== 'MEMORIA' || $hasta - $desde < 2) {
                throw $e;
            }
            unset($columnas);
            $mitad = intdiv($desde + $hasta, 2);
            $a = $this->enOrden($desde, $mitad);
            $b = $this->enOrden($mitad, $hasta);
            $orden = [];
            for ($i = $j = 0; isset($a[$i], $b[$j]);) {
                $orden[] = $this->comparar($this->junto[$b[$j]][0], $this->junto[$a[$i]][0]) < 0 ? $b[$j++] : $a[$i++];
            }
            return array_merge($orden, \array_slice($a, $i), \array_slice($b, $j));
        }
        unset($columnas);
        if ($orden === null) {
            $orden = $desde === 0 ? $pos : range($desde, $hasta - 1);
            usort($orden, function (int $a, int $b): int {
                return $this->comparar($this->junto[$a][0], $this->junto[$b][0]) ?: $a <=> $b;
            });
        } elseif ($desde > 0) {
            foreach ($orden as $k => $p) {
                $orden[$k] = $p + $desde;
            }
        }
        return $orden;
    }

    /**
     * Ordena lo juntado y lo escribe en un tramo: cada fila serializada con su
     * longitud delante, que serialize() conserva los tipos (un 1.0 sigue
     * siendo un número decimal, y un texto puede llevar saltos de línea).
     */
    public function volcar(): void
    {
        if ($this->junto === []) {
            return;
        }
        $fichero = $this->carpeta . '/' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.tramo';
        $fh = @fopen($fichero, 'wb');
        if ($fh === false) {
            throw JsonSqlDbError::io("No se puede escribir el temporal de ordenación '$fichero'");
        }
        $this->tramos[] = $fichero;
        try {
            // A bloques de 64 KB, no fila a fila: un fwrite() por fila era un
            // cuarto del tiempo de ordenar un millón en disco (2.9)
            $bloque = '';
            foreach ($this->ordenadas() as $e) {
                $s = serialize($e);
                $bloque .= pack('N', \strlen($s)) . $s;
                if (\strlen($bloque) >= 65536) {
                    if (fwrite($fh, $bloque) === false) {
                        throw JsonSqlDbError::io("No se puede escribir el temporal de ordenación '$fichero'");
                    }
                    $bloque = '';
                }
            }
            if ($bloque !== '' && fwrite($fh, $bloque) === false) {
                throw JsonSqlDbError::io("No se puede escribir el temporal de ordenación '$fichero'");
            }
        } finally {
            fclose($fh);
        }
        $this->junto = [];
        $this->tras  = memory_get_usage();
    }

    /** @return \Generator<int, array{0: list<mixed>, 1: array}> */
    private function leerTramo(string $fichero): \Generator
    {
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            throw JsonSqlDbError::io("No se puede leer el temporal de ordenación '$fichero'");
        }
        try {
            // A bloques de 64 KB, como se escribió (ver volcar()); una fila que
            // cruza el bloque se completa con el siguiente
            $bloque = '';
            $pos    = 0;
            while (true) {
                if ($pos + 4 > \strlen($bloque) || $pos + 4 + ($largo = unpack('N', $bloque, $pos)[1]) > \strlen($bloque)) {
                    $mas = fread($fh, 65536);
                    if ($mas === false || $mas === '') {
                        break;
                    }
                    $bloque = substr($bloque, $pos) . $mas;
                    $pos    = 0;
                    continue;
                }
                yield unserialize(substr($bloque, $pos + 4, $largo), ['allowed_classes' => false]);
                $pos += 4 + $largo;
            }
        } finally {
            fclose($fh);
        }
    }
}
