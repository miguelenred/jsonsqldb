<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * LIKE, GLOB y REGEXP: los patrones como expresiones regulares, con su
 * caché, y lo que se hace cuando PCRE no puede decidir. Métodos de
 * Evaluator. Van aparte para que una consulta sin patrones no tenga que
 * compilarlos (2.9): sin OPcache, PHP compila en cada petición lo que carga.
 * Hereda de Evaluator solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class EvaluatorPatrones extends Evaluator
{
    /** @var array<string, string> las expresiones regulares de los patrones de LIKE y GLOB ya convertidos (ver guardarPatron()) */
    private static array $patrones = [];
    /** Lo que ocupan sus textos */
    private static int $tamPatrones = 0;

    private function __construct()
    {
    }

    protected static function like(array $n, array $ctx)
    {
        $v = self::evaluar($n['e'], $ctx);
        $p = self::evaluar($n['patron'], $ctx);
        if ($v === null || $p === null) {
            return null;
        }
        $escape = null;
        if ($n['escape'] !== null) {
            // ESCAPE NULL da NULL, como en SQLite (2.10: era como sin ESCAPE)
            $escape = self::evaluar($n['escape'], $ctx);
            if ($escape === null) {
                return null;
            }
            $escape = Valor::aTexto($escape);
        }
        $regex  = empty($n['glob']) ? self::patronALike(Valor::aTexto($p), $escape) : self::patronAGlob(Valor::aTexto($p));
        $t      = \is_string($v) ? $v : Valor::aTexto($v);
        $r      = preg_match($regex, $t);
        return (($r === false ? self::casaSinRetroceso($regex, $t) : $r === 1) !== $n['not']) ? 1 : 0;
    }

    /**
     * x REGEXP patron  (RLIKE es un alias, como en MySQL)
     *
     * El patrón lo escribe quien consulta, así que se valida antes de usarlo y
     * se controla el desbordamiento del motor de expresiones regulares: una
     * expresión mal construida sobre un texto largo puede tardar una eternidad,
     * y es preferible un error claro a un proceso colgado.
     */
    protected static function regexp(array $n, array $ctx)
    {
        $v = self::evaluar($n['e'], $ctx);
        $p = self::evaluar($n['patron'], $ctx);
        if ($v === null || $p === null) {
            return null;
        }

        $patron = Valor::aTexto($p);
        // La barra es el delimitador: se escapa la que no lo está ya (2.9: 'a\/b'
        // quedaba 'a\\/b', una expresión no válida). strtr() va de izquierda a
        // derecha y con lo más largo primero, así que '\\' se queda como está
        $regex  = '/' . (strpos($patron, '\\') === false ? str_replace('/', '\\/', $patron)
            : strtr($patron, ['\\\\' => '\\\\', '\\/' => '\\/', '/' => '\\/'])) . '/u';

        // Silencia el aviso de PCRE. Se quita con restore_error_handler() (2.9:
        // se ponía otro encima con el anterior, y cada fila dejaba uno más en
        // la pila de PHP: 20.000 filas, 8 MB que no se soltaban)
        set_error_handler(static fn(): bool => true);
        $r = preg_match($regex, Valor::aTexto($v));
        restore_error_handler();

        if ($r === false) {
            $error = preg_last_error();
            if ($error === PREG_BACKTRACK_LIMIT_ERROR || $error === PREG_RECURSION_LIMIT_ERROR) {
                throw JsonSqlDbError::syntax(
                    "La expresión regular '$patron' es demasiado costosa para este texto: "
                    . 'simplifícala o acota antes las filas con un WHERE'
                );
            }
            throw JsonSqlDbError::syntax("Expresión regular no válida: '$patron'");
        }

        return (($r === 1) !== $n['not']) ? 1 : 0;
    }

    /**
     * Cuando preg_match() no puede decidir un LIKE o un GLOB, y devuelve false
     * en vez de 0 o 1 (2.9: se tomaba como «no casa», un resultado falso sin
     * avisar). Con un texto que no es UTF-8 válido, byte a byte, como un
     * patrón que no lo es (ver guardarPatron()). Con muchos % (o *) sobre un
     * texto largo, PCRE prueba cada reparto del texto entre ellos y se pasa de
     * su límite: aquí cada trozo entre dos % se busca una vez, en la primera
     * posición en que está, sin volver atrás —que es lo mismo, porque un %
     * casa con cualquier cosa y cada trozo tiene un largo fijo—, y el último
     * al final. Si ni así, el error de REGEXP. El camino de siempre no cambia.
     */
    public static function casaSinRetroceso(string $regex, string $texto): bool
    {
        if (preg_last_error() === PREG_BAD_UTF8_ERROR) {
            $regex = rtrim($regex, 'u');
        }
        // '/^…$/' y sus opciones; un % es '.*' y nada más lo es (ver patronALike() y patronAGlob())
        $fin    = (int)strrpos($regex, '$/');
        $trozos = explode('.*', substr($regex, 2, $fin - 2));
        if (\count($trozos) > 2) {
            $ultimo = array_pop($trozos);
            $regex  = '/^' . array_shift($trozos) . '(?>.*?' . implode(')(?>.*?', $trozos) . ').*' . $ultimo . substr($regex, $fin);
        }
        $r = preg_match($regex, $texto);
        if ($r === false) {
            throw JsonSqlDbError::syntax('El patrón de LIKE o GLOB es demasiado costoso para este texto: '
                . 'simplifícalo o acota antes las filas con un WHERE');
        }
        return $r === 1;
    }

    /**
     * Convierte el patrón GLOB de SQLite en expresión regular (2.9): * varios,
     * ? uno, [abc] y [^abc] un carácter de la lista o fuera de ella; distingue
     * mayúsculas, al revés que LIKE.
     */
    public static function patronAGlob(string $patron): string
    {
        $clave = 'g' . $patron;
        if (isset(self::$patrones[$clave])) {
            return self::$patrones[$clave];
        }
        $out = '';
        $n   = \strlen($patron);
        for ($i = 0; $i < $n; $i++) {
            $c = $patron[$i];
            if ($c === '*') {
                $out .= '.*';
            } elseif ($c === '?') {
                $out .= '.';
            } elseif ($c === '[') {
                // Un [ sin cerrar no casa con nada, como en SQLite (2.9: era un
                // ValueError de PHP, o un [ literal)
                if (($clase = self::claseGlob($patron, $i)) === null) {
                    $out = '(?!)';
                    break;
                }
                $out .= $clase;
            } else {
                $out .= preg_quote($c, '/');
            }
        }
        return self::guardarPatron($clave, $patron, $out, 's');
    }

    /**
     * La lista [abc] de un GLOB que empieza en $i, como en SQLite (2.9): un ]
     * justo detrás de [ o de [^ es de la lista; a-z es un rango, y uno al
     * revés (z-a) no añade nada; un - al principio o al final es un -. Antes
     * [^]] y []…] daban una expresión que no compilaba: un aviso de PHP por
     * fila y 0. Deja $i en su ], o null si no se cierra. Todo va escapado: en
     * la expresión no queda un '.*' que no sea un * (ver casaSinRetroceso()).
     */
    private static function claseGlob(string $patron, int &$i): ?string
    {
        $n      = \strlen($patron);
        $j      = $i + 1;
        $no     = ($patron[$j] ?? '') === '^';
        $j     += $no ? 1 : 0;
        $lista  = '';
        $previo = null;                       // el carácter anterior, si puede empezar un rango
        if (($patron[$j] ?? '') === ']') {
            $lista = '\\]';
            $j++;
        }
        // Un carácter UTF-8 entero cada vez: un rango puede ser á-é
        $siguiente = static function () use ($patron, &$j): string {
            $b = \ord($patron[$j]);
            $c = substr($patron, $j, $b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : ($b >= 0xC0 ? 2 : 1)));
            $j += \strlen($c);
            return $c;
        };
        while ($j < $n) {
            $c = $siguiente();
            if ($c === ']') {
                $i = $j - 1;
                return '[' . ($no ? '^' : '') . $lista . ']';
            }
            if ($c === '-' && $previo !== null && $j < $n && $patron[$j] !== ']') {
                $hasta = $siguiente();
                // En UTF-8 el orden de los bytes es el de los caracteres
                $lista .= strcmp($previo, $hasta) <= 0 ? preg_quote($previo, '/') . '-' . preg_quote($hasta, '/') : '';
                $previo = null;
                continue;
            }
            $lista .= preg_quote($c, '/');
            $previo = $c;
        }
        return null;
    }

    /**
     * Convierte el patrón LIKE en expresión regular. % = varios, _ = uno.
     * Como en SQLite (2.10), solo las letras de la A a la Z son iguales en
     * mayúsculas y en minúsculas: cada una va como [aA], sin la opción i, que
     * con UTF-8 también juntaba É y é (SQLite no). El carácter de ESCAPE es
     * uno solo, aunque ocupe varios bytes (se tomaba su primer byte: con
     * ESCAPE 'ñ', cualquier á o é escapaba lo que la seguía), y un patrón que
     * acaba en él no casa con nada.
     */
    public static function patronALike(string $patron, ?string $escape): string
    {
        $clave = 'l' . $patron . "\0" . ($escape ?? '');
        if (isset(self::$patrones[$clave])) {
            return self::$patrones[$clave];
        }
        if ($escape !== null && \strlen($escape) !== 1 && preg_match('/^.$/su', $escape) !== 1) {
            throw JsonSqlDbError::syntax('ESCAPE tiene que ser un solo carácter');
        }
        $largo = $escape === null ? 0 : \strlen($escape);
        $out   = '';
        $n     = \strlen($patron);
        for ($i = 0; $i < $n; $i++) {
            $c = $patron[$i];
            if ($largo > 0 && $c === $escape[0] && substr($patron, $i, $largo) === $escape) {
                $i += $largo;
                if ($i >= $n) {
                    $out = '(?!)';
                    break;
                }
                // El carácter escapado, entero aunque sea de varios bytes
                $b  = \ord($patron[$i]);
                $c  = substr($patron, $i, $b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : ($b >= 0xC0 ? 2 : 1)));
                $i += \strlen($c) - 1;
            } elseif ($c === '%') {
                $out .= '.*';
                continue;
            } elseif ($c === '_') {
                $out .= '.';
                continue;
            }
            // Sin ctype_alpha() ni strtolower(), que dependen del locale
            $o    = \strlen($c) === 1 ? \ord($c) | 0x20 : 0;
            $out .= $o >= 0x61 && $o <= 0x7A ? '[' . \chr($o) . \chr($o ^ 0x20) . ']' : preg_quote($c, '/');
        }
        return self::guardarPatron($clave, $patron, $out, 's');
    }

    /**
     * La expresión regular de un patrón de LIKE o GLOB ya convertido, que
     * se guarda para la próxima vez. Como mucho 4.096 o 1 MB, como las
     * claves de Collation::clave() (2.9: sin tope, un LIKE '%' || b.texto ||
     * '%' sobre 300.000 filas guardaba 70 MB). Una que no compila es un error,
     * no se guarda.
     */
    private static function guardarPatron(string $clave, string $patron, string $out, string $opciones): string
    {
        $regex = '/^' . $out . '$/' . $opciones . 'u';
        if (@preg_match($regex, '') === false) {
            $regex = '/^' . $out . '$/' . $opciones;       // texto no UTF-8 válido
            if (@preg_match($regex, '') === false) {
                throw JsonSqlDbError::syntax("Patrón de LIKE o GLOB no válido: '$patron'");
            }
        }
        if (\count(self::$patrones) >= 4096 || self::$tamPatrones > 1048576) {
            self::$patrones    = [];
            self::$tamPatrones = 0;
        }
        self::$tamPatrones += \strlen($clave) + \strlen($regex);
        return self::$patrones[$clave] = $regex;
    }
}
