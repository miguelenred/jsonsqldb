<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Repara lo que una edición a mano puede romper en un fichero JSON del motor
 * (2.9), solo cuando el arreglo es seguro: nunca adivina un dato. Lo que no
 * se puede arreglar así se devuelve como dudoso, con su línea, para que lo
 * decida una persona (a mano o con REPAIR TABLES).
 *
 * Funciones puras: no leen ni escriben ficheros. Quien las usa es Storage.
 */
final class Reparador
{
    /**
     * Las filas de una parte de datos que no es JSON válido.
     *
     * Una parte tiene una fila por línea, así que se lee línea a línea: lo que
     * rompe el fichero entero —una coma de más o de menos entre filas, el
     * corchete o la llave del final, la lista de desfases— no afecta a cada
     * fila. Una fila solo se arregla si le falta o le sobra la llave del
     * final, y solo si así queda un objeto JSON. Cualquier otra línea que no
     * sea de la estructura de la parte es dudosa.
     *
     * Si el fichero entero es JSON válido con su lista de filas (por ejemplo,
     * reformateado por un editor), se toman sus filas tal cual. Un hueco de
     * una fila borrada (null) sigue siendo un hueco, en su sitio (2.9).
     *
     * @return array{filas: list<array|null>, dudosas: list<array{0: int, 1: string}>}
     *         dudosas: [número de línea, su texto]
     */
    public static function parte(string $texto): array
    {
        $texto = self::sinBom($texto);
        $json = json_decode($texto, true);
        if (\is_array($json) && \is_array($json['rows'] ?? null) && self::esLista($json['rows'])) {
            $filas = [];
            $dudosas = [];
            foreach ($json['rows'] as $i => $fila) {
                if ($fila === null || (\is_array($fila) && $fila !== [] && !self::esLista($fila))) {
                    $filas[] = $fila;
                } else {
                    $dudosas[] = [0, 'fila ' . ($i + 1) . ': ' . substr((string)json_encode($fila), 0, 80)];
                }
            }
            return ['filas' => $filas, 'dudosas' => $dudosas];
        }

        $filas   = [];
        $dudosas = [];
        // Solo \r\n, \n y \r: \R parte también por el byte 0x85, que va dentro
        // de letras como «Å» en UTF-8
        foreach (preg_split('/\r\n|\n|\r/', $texto) ?: [] as $n => $linea) {
            $l = trim($linea);
            if ($l === '' || self::esEstructura($l)) {
                continue;
            }
            if (rtrim($l, " \t,") === 'null') {
                $filas[] = null;                            // un hueco
                continue;
            }
            $fila = self::fila($l);
            if ($fila === null) {
                $dudosas[] = [$n + 1, substr($l, 0, 80)];
            } else {
                $filas[] = $fila;
            }
        }
        return ['filas' => $filas, 'dudosas' => $dudosas];
    }

    /**
     * ¿Es una línea de la estructura de una parte, y no una fila? La llave de
     * apertura y de cierre, "table", "rows", el corchete que cierra las filas,
     * y "offsets" y "offsets_at", que se vuelven a calcular. Con o sin coma.
     */
    private static function esEstructura(string $l): bool
    {
        return \in_array(rtrim($l, ','), ['{', '}', ']', '[', '],', '}]', ']}'], true)
            || (bool)preg_match('/^"(table|rows|offsets|offsets_at)"\s*:/', $l);
    }

    /**
     * Sin la marca de UTF-8 del principio, que ponen algunos editores de
     * Windows al guardar y con la que el JSON ya no es válido (2.9).
     */
    private static function sinBom(string $texto): string
    {
        return strncmp($texto, "\xEF\xBB\xBF", 3) === 0 ? substr($texto, 3) : $texto;
    }

    /** array_is_list(), que solo existe desde PHP 8.1 */
    private static function esLista(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, \count($a) - 1);
    }

    /** Una línea de fila, si es un objeto JSON tal cual o con su llave final arreglada. */
    private static function fila(string $l): ?array
    {
        $l = rtrim($l, " \t,");
        if ($l === '' || $l[0] !== '{') {
            return null;
        }
        $candidatas = [$l];
        if (substr($l, -1) !== '}') {
            $candidatas[] = $l . '}';                       // le falta la llave del final
        } elseif (substr($l, -2) === '}}') {
            $candidatas[] = substr($l, 0, -1);              // le sobra una
        }
        foreach ($candidatas as $c) {
            $v = json_decode($c, true);
            if (\is_array($v) && $v !== [] && !self::esLista($v)) {
                foreach ($v as $dato) {
                    if (\is_array($dato)) {
                        return null;                        // una fila solo lleva valores sueltos
                    }
                }
                return $v;
            }
        }
        return null;
    }

    /**
     * Un fichero JSON de estructura (meta, revisión, vistas…) arreglado solo
     * en lo seguro: comas sobrantes antes de una llave o un corchete de
     * cierre, y la llave que cierra el fichero si es lo único que falta. Fuera de
     * los textos: dentro de una cadena no se toca nada. null si ni así es un
     * objeto JSON.
     */
    public static function objeto(string $texto): ?array
    {
        $texto = self::sinBom($texto);
        $v = json_decode($texto, true);
        if (\is_array($v) && !self::esLista($v)) {
            return $v;
        }
        $out     = '';
        $pila    = [];
        $cadena  = false;
        $escape  = false;
        $largo   = \strlen($texto);
        for ($i = 0; $i < $largo; $i++) {
            $c = $texto[$i];
            if ($cadena) {
                $out .= $c;
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $cadena = false;
                }
                continue;
            }
            if ($c === '"') {
                $cadena = true;
            } elseif ($c === '{' || $c === '[') {
                $pila[] = $c === '{' ? '}' : ']';
            } elseif ($c === '}' || $c === ']') {
                if ($pila === [] || array_pop($pila) !== $c) {
                    return null;                            // un cierre que no casa: no se adivina
                }
                $out = rtrim($out);
                if (substr($out, -1) === ',') {
                    $out = substr($out, 0, -1);             // coma sobrante antes del cierre
                }
            }
            $out .= $c;
        }
        if ($cadena) {
            return null;                                    // un texto sin cerrar: no se adivina
        }
        $out = rtrim($out);
        if (substr($out, -1) === ',') {
            $out = substr($out, 0, -1);
        }
        // Al final solo puede faltar la llave que cierra el fichero: si faltan
        // más cierres, el fichero puede estar cortado y completarlo daría por
        // buena una estructura a la que le falta algo
        if (\count($pila) > 1 || ($pila !== [] && $pila[0] !== '}')) {
            return null;
        }
        $out .= $pila === [] ? '' : '}';
        $v = json_decode($out, true);
        return \is_array($v) && !self::esLista($v) ? $v : null;
    }
}
