<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Compactar una tabla por bloques de texto, sin decodificar las filas que no
 * cambian: lo hace un DELETE que deja más de un 25 % de huecos y VACUUM, y
 * sus líneas de parte las usa también la reescritura de StorageMantenimiento
 * (2.10, aparte de StorageCambios para que un UPDATE o un DELETE que no
 * compacta no tenga que compilarlo: sin OPcache, PHP compila en cada petición
 * lo que carga). Métodos de Storage que reciben el almacenamiento en $st.
 * Hereda de Storage solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageCompactacion extends Storage
{
    private function __construct()
    {
    }

    /**
     * Compacta la tabla por bloques de texto (2.9, como la 2.8 movía las
     * filas al borrar): cada fila es una línea de su parte y pasa tal cual a
     * su sitio nuevo, sin decodificarla; solo se codifica la que cambia. Las
     * claves de índice salen de los trozos de antes, que ya las tienen, y cada
     * trozo nuevo se escribe con su parte. Una parte o un trozo que no tenga
     * la forma esperada se lee como siempre. Decodificar y codificar cada
     * fila, y sacar las claves de las filas, hacía un DELETE de un tercio de
     * la tabla un 30 % más lento que en la 2.8. Se lee parte a parte: en
     * memoria, una de antes y la nueva.
     *
     * @param array<int,array> $cambios  posición => fila nueva
     * @param list<int>        $borradas posiciones que desaparecen
     * @param list<array>      $nuevas   filas que se añaden al final
     * @param callable(int=): iterable<int, array> $quedan las filas que quedan, por si hay que rehacer los índices desde ellas
     */
    public static function compactarPorBloques(Storage $st, string $tabla, array $cambios, array $borradas, array $nuevas,
                                                int $filasAntes, int $partesAntes, array $definiciones, callable $quedan,
                                                ?array $meta = null): void
    {
        $chunk        = $st->filasPorParte;
        $definiciones = $st->indices ? $definiciones : [];
        $n            = 0;                          // las filas que quedan, contadas al escribirlas (contarlas antes leía la tabla otra vez)
        $claves       = [];                         // parte nueva => índice => sus claves, y el resumen de antes si siguen siendo las mismas
        $partes = (static function () use ($st, $tabla, $cambios, $borradas, $nuevas, $filasAntes, $partesAntes, $definiciones,
                                           $chunk, &$claves, &$n): \Generator {
            $fuera  = array_fill_keys($borradas, true);
            $vacias = array_fill_keys(array_column($definiciones, 'name'), []);
            $lineas = [];                           // las de la parte nueva que se va llenando
            $suyas  = $vacias;                      // y sus claves, por índice
            // La parte de antes de la que viene entera la nueva (ninguna fila
            // fuera ni cambiada, empezando a la par), o -1: sus trozos tienen
            // las mismas claves, y su filtro se vuelve a escribir tal cual
            $misma  = -1;
            // Las partes de antes, y detrás, como una más, las filas nuevas
            for ($i = 0; $i <= $partesAntes; $i++) {
                $deAntes = $i < $partesAntes;
                $cuantas = $deAntes ? min($chunk, $filasAntes - $i * $chunk) : \count($nuevas);
                $texto   = $deAntes ? self::lineasDeParte($st, $tabla, $i + 1, $cuantas) : null;
                $filas   = $deAntes && $texto === null ? $st->parte($tabla, $i + 1, $st->ficheroDatos($tabla, $i + 1)) : $nuevas;
                // Por índice, la clave de cada posición de esta parte, de su trozo
                $deTrozo = [];
                foreach ($deAntes ? $definiciones : [] as $def) {
                    // Sin quedárselo en memoria: se usa una vez
                    $yaEstaba = isset($st->indicesMemo[$tabla][$def['name']][$i + 1]);
                    $st->trozosSinCache = true;         // se va a reescribir: no a la caché
                    $keys     = $st->trozoIndice($tabla, $def, $i + 1);
                    $st->trozosSinCache = false;
                    if (!$yaEstaba) {
                        unset($st->indicesMemo[$tabla][$def['name']][$i + 1]);
                    }
                    if ($keys === null && $texto !== null) {
                        $filas = $st->parte($tabla, $i + 1, $st->ficheroDatos($tabla, $i + 1));
                    }
                    $deTrozo[$def['name']] = $keys === null ? null : IndexesEscritura::porPosicion($keys);
                }
                for ($k = 0; $k < $cuantas; $k++) {
                    $pos  = $i * $chunk + $k;
                    $fila = $deAntes ? $cambios[$pos] ?? null : $filas[$k];
                    if ($k === 0) {
                        $misma = $deAntes && $lineas === [] && $cuantas === $chunk ? $i : -1;
                    }
                    if ($deAntes && isset($fuera[$pos])) {
                        $misma = -1;
                        continue;
                    }
                    if ($fila !== null) {
                        $misma = -1;
                        $linea = json_encode($fila, self::JSON_FILA);
                        if ($linea === false) {
                            throw StorageEscritura::noCodificable($tabla, $fila);
                        }
                    } elseif ($texto !== null) {
                        $linea = $texto[$k];
                    } else {
                        $linea = ($filas[$k] ?? null) === null ? 'null' : json_encode($filas[$k], self::JSON_FILA);
                    }
                    if ($linea === 'null') {
                        $misma = -1;
                        continue;                   // un hueco de antes
                    }
                    foreach ($definiciones as $def) {
                        $nombre = $def['name'];
                        $clave  = $fila === null && $deTrozo[$nombre] !== null ? $deTrozo[$nombre][$pos] ?? null
                            : Indexes::claveFila($fila ?? $filas[$k], $def['columns'], $def['plegar'] ?? []);
                        if ($clave !== null) {
                            IndexesEscritura::anotar($suyas[$nombre], $clave, $n);
                        }
                    }
                    $lineas[] = $linea;
                    if (++$n % $chunk === 0) {
                        $resumenes = [];
                        foreach ($misma >= 0 ? $definiciones : [] as $def) {
                            $resumenes[$def['name']] = $st->indicesMemo[$tabla][$def['name']]['resumen'][$misma + 1] ?? null;
                        }
                        $claves[intdiv($n - 1, $chunk)] = [$suyas, $resumenes];
                        yield intdiv($n - 1, $chunk) => self::textoDeParte($tabla, $lineas);
                        $lineas = [];
                        $suyas  = $vacias;
                    }
                }
                Memoria::comprobar('la compactación de la tabla');
            }
            if ($lineas !== [] || $n === 0) {
                $claves[intdiv($n, $chunk)] = [$suyas, []];
                yield intdiv($n, $chunk) => self::textoDeParte($tabla, $lineas);
            }
        })();
        $compactas = static function () use ($quedan): \Generator {
            $n = 0;
            foreach ($quedan() as $fila) {
                yield $n++ => $fila;
            }
        };
        StorageEscritura::escribirTabla($st, $tabla, $meta, $definiciones, fn(): iterable => StorageEscritura::enPartes($compactas(), $chunk), $partes,
            static function () use (&$n): int { return $n; }, null, null, [], $partesAntes, static function (int $i) use (&$claves): ?array {
                $suyas = $claves[$i] ?? null;
                unset($claves[$i]);
                return $suyas;
            });
    }

    /**
     * Las filas de una parte como sus líneas de texto, sin decodificarlas; un
     * hueco es la línea null. Null si la parte no tiene la forma que escribe
     * StorageEscritura::escribirParte() con $filas filas (ver
     * StorageEscritura::empalmarParte(), que hace la misma comprobación).
     *
     * @return list<string>|null
     */
    public static function lineasDeParte(Storage $st, string $tabla, int $parte, int $filas): ?array
    {
        $fichero = $st->ficheroDatos($tabla, $parte);
        $desf    = $st->desfasesDeParte($tabla, $parte, $fichero);
        $texto   = $desf === null ? false : @file_get_contents($fichero);
        if ($texto === false || \strlen($desf) !== 4 * $filas
            || !preg_match('/"offsets_at": (\d+)\n\}\n$/', substr($texto, -40), $m)) {
            return null;
        }
        $cierre   = $filas === 0 ? "],\n" : "\n  ],\n";
        $finFilas = (int)$m[1] - \strlen($cierre);
        $cabecera = "{\n  \"table\": " . json_encode($tabla, self::JSON_FILA) . ",\n  \"rows\": [";
        if (strncmp($texto, $cabecera, \strlen($cabecera)) !== 0 || substr($texto, $finFilas, \strlen($cierre)) !== $cierre) {
            return null;
        }
        $desfases = $filas === 0 ? [] : array_values(unpack('N*', $desf));
        $out = [];
        foreach ($desfases as $j => $ini) {
            // Cada línea acaba donde empieza la separación de la siguiente
            $fin   = isset($desfases[$j + 1]) ? $desfases[$j + 1] - 6 : $finFilas;
            $linea = substr($texto, $ini, $fin - $ini);
            if ($linea !== 'null' && ($linea === '' || $linea[0] !== '{' || substr($linea, -1) !== '}')) {
                return null;                          // null: el hueco de una borrada (2.9)
            }
            $out[] = $linea;
        }
        return $out;
    }

    /**
     * El texto de una parte con estas líneas de filas, ya codificadas: el
     * mismo formato, byte a byte, que escribe StorageEscritura::escribirParte().
     *
     * @param list<string> $lineas
     */
    public static function textoDeParte(string $tabla, array $lineas): string
    {
        $texto    = "{\n  \"table\": " . json_encode($tabla, self::JSON_FILA) . ",\n  \"rows\": [";
        $desfases = [];
        $sep      = "\n    ";
        foreach ($lineas as $linea) {
            $desfases[] = \strlen($texto) + \strlen($sep);
            $texto     .= $sep . $linea;
            $sep        = ",\n    ";
        }
        $texto .= $lineas === [] ? "],\n" : "\n  ],\n";
        $aqui   = \strlen($texto);
        return $texto . '  "offsets": [' . implode(',', $desfases) . "],\n  \"offsets_at\": $aqui\n}\n";
    }
}
