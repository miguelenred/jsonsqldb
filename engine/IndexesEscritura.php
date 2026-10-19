<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que mantiene los índices al escribir: el resumen de cada trozo (su
 * filtro, el rango de sus números y sus extremos), sus claves por posición, y
 * las claves que se añaden, cambian o se sustituyen con las filas. Métodos de
 * Indexes. Van aparte para que una lectura no tenga que compilarlos (2.10):
 * sin OPcache, PHP compila en cada petición lo que carga. Hereda de Indexes
 * solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class IndexesEscritura extends Indexes
{
    private function __construct()
    {
    }

    /**
     * Lo que va en la cabecera de un trozo de índice sobre sus claves de texto
     * (2.9), en una sola pasada:
     *  - 'filtro': un filtro de Bloom (en base64) que dice sin leer las claves
     *    si una NO está en el trozo; si dice que puede estar, puede ser un
     *    falso positivo (~1 %) y entonces se leen. Las claves de texto no
     *    tienen rango, y una búsqueda por una leía todos los trozos. Vacío si
     *    el trozo no tiene claves de texto.
     *  - 'min' y 'max', si todas sus claves son de texto: el menor y el mayor
     *    valor de la primera columna, comparados como compara el WHERE dos
     *    textos (strcmp), para los rangos.
     *
     * Con $conNumeros (2.9), el filtro cubre también las claves numéricas, y
     * lo dice 'cubre': "todo" en vez de "texto". Es para un índice
     * cuyos valores van repartidos por toda la tabla (el cliente de cada
     * pedido), donde el rango de cada trozo no descarta ninguno y una búsqueda
     * los leía todos. Con las claves de una clave primaria, que cada trozo
     * acota, no hace falta (ver StorageEscritura::escribirTrozo()). En memoria
     * el filtro lleva esa marca en su primer byte (ver Indexes::filtro()).
     *
     * @param array<string, mixed> $keys clave => posiciones
     * @return array{filtro: string, cubre?: string, min?: string, max?: string}
     */
    public static function resumenDeTexto(array $keys, bool $conNumeros = false, int $holgura = 0): array
    {
        $texto = [];
        $todas = $keys !== [];
        $min = $max = null;
        foreach ($keys as $clave => $_) {
            $clave = (string)$clave;
            if ($clave === '' || $clave[0] !== 't') {
                $todas = false;
                if ($conNumeros && $clave !== '' && $clave[0] === 'n') {
                    $texto[] = $clave;
                }
                continue;
            }
            $texto[] = $clave;
            if ($todas) {
                // primerValor(), sin la llamada: una vez por clave
                $sep = (int)strpos($clave, ':');
                $v   = substr($clave, $sep + 1, (int)substr($clave, 1, $sep - 1));
                if ($min === null) {
                    $min = $max = $v;
                } elseif (strcmp($v, $min) < 0) {
                    $min = $v;
                } elseif (strcmp($v, $max) > 0) {
                    $max = $v;
                }
            }
        }
        $resumen = ['filtro' => ''];
        if ($texto !== []) {
            // En palabras de 32 bits, empaquetado al final en little-endian: el
            // bit b queda en el byte b >> 3, bit b & 7, que es lo que lee
            // puedeEstar(). Dos crc32 por clave y siete posiciones de ellos
            // (FILTRO_K), sumando el paso módulo m, que da los mismos bits que
            // (h1 + i·h2) % m y cuesta un cuarto menos: se hace por cada trozo
            // que se escribe
            $m = max(64, (int)ceil((\count($texto) + $holgura) * self::FILTRO_BITS / 32) * 32);
            $w = array_fill(0, $m >> 5, 0);
            foreach ($texto as $clave) {
                $b = crc32($clave) % $m;
                $s = (crc32(strrev($clave)) | 1) % $m;
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31);
            }
            $resumen['filtro'] = base64_encode(pack('V*', ...$w));
            $resumen['cubre']  = $conNumeros ? 'todo' : 'texto';
        }
        if ($todas && $min !== null) {
            $resumen['min'] = $min;
            $resumen['max'] = $max;
        }
        return $resumen;
    }

    /**
     * Cada posición de un trozo con su clave (2.9): para quitar las de las
     * filas que se borran sin leerlas, y para pasar las claves de una parte
     * que se compacta a su sitio nuevo (ver StorageEscritura).
     *
     * @param array<string, int|list<int>> $keys
     * @return array<int, string>
     */
    public static function porPosicion(array $keys): array
    {
        $out = [];
        foreach ($keys as $clave => $donde) {
            if (\is_array($donde)) {
                foreach ($donde as $p) {
                    $out[$p] = (string)$clave;
                }
            } else {
                $out[$donde] = (string)$clave;
            }
        }
        return $out;
    }

    /**
     * Mínimo y máximo del valor numérico de la primera columna en un trozo
     * de índice: [min, max]; [] si el trozo no tiene claves; null si alguna
     * clave es de texto y no se puede acotar. Con una clave primaria
     * autoincremental cada trozo cubre un tramo de ids, y una búsqueda por id
     * abre un trozo en vez de todos.
     *
     * @param array<string, int|list<int>> $keys
     * @return array{0: int|float, 1: int|float}|array{}|null
     */
    public static function rango(array $keys): ?array
    {
        if ($keys === []) {
            return [];
        }
        // El caso de siempre —una clave primaria entera— se resuelve con una
        // sola expresión regular sobre todas las claves juntas: la mitad de
        // tiempo que mirarlas una a una. Si alguna no es un entero solo, por
        // el camino general
        $n = preg_match_all('/^n\d+:(-?\d+)$/m', implode("\n", array_keys($keys)), $m);
        if ($n === \count($keys)) {
            return [(int)min($m[1]), (int)max($m[1])];
        }
        $min = $max = null;
        foreach (array_keys($keys) as $clave) {
            $v = self::valorNumerico((string)$clave);
            if ($v === null) {
                return null;
            }
            if ($min === null || $v < $min) { $min = $v; }
            if ($max === null || $v > $max) { $max = $v; }
        }
        return $min === null ? [] : [$min, $max];
    }

    /**
     * Añade al índice ANTERIOR las filas que hay a partir de una posición.
     *
     * Reconstruir el índice entero es la mayor parte de lo que cuesta escribir
     * una fila en una tabla grande, y casi siempre es trabajo repetido: las
     * claves de las filas que no se han movido son las mismas. Solo vale si
     * las posiciones anteriores a $desde siguen siendo las mismas; quien llama
     * tiene que haberlo comprobado con rigor, porque una entrada de MÁS solo
     * hace la consulta más lenta y una de MENOS devuelve resultados incompletos
     * sin que nada lo delate.
     *
     * @param array<string, int|list<int>> $anterior claves del índice de antes
     * @param list<array>                  $filas    las filas desde $desde, en orden
     * @param list<string>                 $columnas columnas del índice
     * @param int                          $desde    posición de la primera de $filas
     * @return array<string, int|list<int>>
     */
    public static function ampliar(array $anterior, array $filas, array $columnas, int $desde, array $plegar = []): array
    {
        $keys = $anterior;
        foreach ($filas as $i => $fila) {
            // Sin colación, la clave aquí mismo: esto va una vez por fila
            if ($plegar === []) {
                $valores = [];
                foreach ($columnas as $c) {
                    $valores[] = $fila[$c] ?? null;
                }
                $clave = self::clave($valores);
            } else {
                $clave = self::claveFila($fila, $columnas, $plegar);
            }
            if ($clave !== null) {
                self::anotar($keys, $clave, $desde + $i);
            }
            Memoria::comprobar('la ampliación del índice');
        }
        return $keys;
    }

    /**
     * ¿Cambia la clave de índice de una fila? Si las columnas del índice
     * valen lo mismo, no: ni siquiera hace falta calcularla.
     *
     * @param array|null $nueva null: la fila se ha borrado y queda un hueco (2.9)
     */
    public static function cambiaClave(array $columnas, array $vieja, ?array $nueva, array $plegar = []): bool
    {
        $vv = $nv = [];
        foreach ($columnas as $c) {
            $vv[] = $vieja[$c] ?? null;
            $nv[] = $nueva[$c] ?? null;
        }
        if ($plegar === []) {
            return $vv !== $nv && self::clave($vv) !== self::clave($nv);
        }
        return $vv !== $nv && self::claveFila($vieja, $columnas, $plegar) !== ($nueva === null ? null : self::claveFila($nueva, $columnas, $plegar));
    }

    /**
     * Cambia la clave de una posición sin mover las demás: sale de la de
     * antes y entra en la nueva (null: sin clave, como la de un hueco).
     *
     * Se cambia $keys en su sitio, y la posición sale de la lista de su clave
     * sin hacer otra (2.9): un UPDATE de toda la tabla sobre una columna
     * indexada con pocos valores copiaba dos veces en cada fila la lista de su
     * valor, de cientos de posiciones.
     *
     * @param array<string, int|list<int>> $keys
     */
    public static function sustituir(array &$keys, int $pos, ?string $antes, ?string $ahora): void
    {
        if ($antes !== null && isset($keys[$antes])) {
            if (!\is_array($keys[$antes])) {
                if ((int)$keys[$antes] === $pos) {
                    unset($keys[$antes]);
                }
            } elseif (($i = array_search($pos, $keys[$antes])) !== false) {
                array_splice($keys[$antes], (int)$i, 1);
                if (\count($keys[$antes]) <= 1) {
                    if ($keys[$antes] === []) {
                        unset($keys[$antes]);
                    } else {
                        $keys[$antes] = (int)$keys[$antes][0];
                    }
                }
            }
        }
        if ($ahora !== null) {
            self::anotar($keys, $ahora, $pos);
        }
    }

    /**
     * Apunta una posición bajo una clave. Con una sola posición se guarda el
     * entero a secas, no una lista de uno: en una clave primaria son todas, y
     * en memoria una lista de un elemento cuesta tres veces lo que un entero.
     *
     * @param array<string, int|list<int>> $keys
     */
    public static function anotar(array &$keys, string $clave, int $pos): void
    {
        if (!isset($keys[$clave])) {
            $keys[$clave] = $pos;
        } elseif (\is_int($keys[$clave])) {
            $keys[$clave] = [$keys[$clave], $pos];
        } else {
            $keys[$clave][] = $pos;
        }
    }
}
