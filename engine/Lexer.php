<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Analizador léxico. Convierte la SQL (puede ser multilínea) en tokens.
 *
 * Token: ['t'=>tipo, 'v'=>valor, 'u'=>mayúsculas (solo 'id'), 'l'=>línea, 'p'=>posición]
 *   id    identificador o palabra clave      (usuarios, SELECT, "mi campo", [campo], `campo`)
 *   num   número                             (10, 3.5, 1e3)
 *   str   cadena literal                     ('texto', 'con '' comilla')
 *   op    operador                           = <> != < <= > >= + - * / % ||
 *   punc  signo                              ( ) , . ;
 *   param marcador de parámetro ligado       ?
 *   eof   fin de la sentencia
 *
 * Se ignoran los comentarios -- hasta fin de línea y los bloques.
 *
 * https://miguelenred.es/jsonsqldb
 */
/**
 * Un token es siempre una de estas formas. Tenerlas separadas, en vez de un
 * array suelto, es lo que permite al análisis estático seguir el parser.
 *
 * @phpstan-type TokenId    array{t: 'id', v: string, u: string, q: bool, l: int, p: int}
 * @phpstan-type TokenOtro  array{t: 'str'|'op'|'punc'|'param'|'eof', v: string, l: int, p: int}
 * @phpstan-type TokenNum   array{t: 'num', v: int|float, l: int, p: int, texto: string}
 * @phpstan-type Token      TokenId|TokenOtro|TokenNum
 */
final class Lexer
{
    private const CIFRAS = '0123456789';
    /** Lo que puede ir dentro de un nombre sin comillas */
    private const LETRAS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$';
    // Como mapas, para buscar con isset() y no recorrer una lista (2.9)
    private const OPS2 = ['<>' => 1, '!=' => 1, '<=' => 1, '>=' => 1, '||' => 1, '==' => 1, '<<' => 1, '>>' => 1];
    private const OPS1 = ['=' => 1, '<' => 1, '>' => 1, '+' => 1, '-' => 1, '*' => 1, '/' => 1, '%' => 1, '&' => 1, '|' => 1, '~' => 1];
    private const PUNC = ['(' => 1, ')' => 1, ',' => 1, '.' => 1, ';' => 1];

    /** @return list<array> cada token: ['t' => tipo, 'v' => valor, 'l' => línea, 'p' => posición, …] */
    public static function tokens(string $sql): array
    {
        $tokens = [];
        $n      = \strlen($sql);
        $i      = 0;
        $linea  = 1;

        while ($i < $n) {
            $c   = $sql[$i];
            $ini = $i;

            // Espacios y saltos de línea: el tramo entero de una vez (2.9)
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $k      = strspn($sql, " \t\r\n", $i);
                $linea += substr_count($sql, "\n", $i, $k);
                $i     += $k;
                continue;
            }

            // Comentario de línea
            if ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-') {
                $fin = strpos($sql, "\n", $i);
                $i   = $fin === false ? $n : $fin;
                continue;
            }

            // Comentario de bloque
            if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
                $fin = strpos($sql, '*/', $i + 2);
                if ($fin === false) {
                    throw JsonSqlDbError::syntax("Comentario /* sin cerrar (línea $linea)");
                }
                $linea += substr_count(substr($sql, $i, $fin - $i), "\n");
                $i = $fin + 2;
                continue;
            }

            // Cadena literal
            if ($c === "'") {
                // De comilla en comilla, no de carácter en carácter (2.9): ''
                // dentro es una comilla
                $valor = '';
                $i++;
                while (true) {
                    $fin = strpos($sql, "'", $i);
                    if ($fin === false) {
                        $linea += substr_count($sql, "\n", $i);
                        throw JsonSqlDbError::syntax("Cadena sin cerrar (línea $linea)");
                    }
                    $linea += substr_count($sql, "\n", $i, $fin - $i);
                    $valor .= substr($sql, $i, $fin - $i);
                    if ($fin + 1 < $n && $sql[$fin + 1] === "'") {
                        $valor .= "'";
                        $i = $fin + 2;
                        continue;
                    }
                    $i = $fin + 1;
                    break;
                }
                $tokens[] = ['t' => 'str', 'v' => $valor, 'l' => $linea, 'p' => $ini];
                continue;
            }

            // Identificador entrecomillado: "campo"  [campo]  `campo`
            if ($c === '"' || $c === '[' || $c === '`') {
                $cierre = $c === '[' ? ']' : $c;
                $valor  = '';
                $i++;
                while (true) {
                    if ($i >= $n) {
                        throw JsonSqlDbError::syntax("Identificador sin cerrar (línea $linea)");
                    }
                    if ($sql[$i] === $cierre) {
                        if ($cierre !== ']' && $i + 1 < $n && $sql[$i + 1] === $cierre) {
                            $valor .= $cierre; $i += 2; continue;
                        }
                        $i++;
                        break;
                    }
                    $valor .= $sql[$i++];
                }
                $tokens[] = ['t' => 'id', 'v' => $valor, 'u' => strtoupper($valor), 'q' => true, 'l' => $linea, 'p' => $ini];
                continue;
            }

            // Número
            if ($c >= '0' && $c <= '9'
                || ($c === '.' && $i + 1 < $n && $sql[$i + 1] >= '0' && $sql[$i + 1] <= '9')) {
                $i += strspn($sql, self::CIFRAS, $i);
                $flotante = false;
                if ($i < $n && $sql[$i] === '.') {
                    $flotante = true;
                    $i++;
                    $i += strspn($sql, self::CIFRAS, $i);
                }
                if ($i < $n && ($sql[$i] === 'e' || $sql[$i] === 'E')) {
                    $salto = $i + 1;
                    if ($salto < $n && ($sql[$salto] === '+' || $sql[$salto] === '-')) { $salto++; }
                    if ($salto < $n && $sql[$salto] >= '0' && $sql[$salto] <= '9') {
                        $flotante = true;
                        $i = $salto + strspn($sql, self::CIFRAS, $salto);
                    }
                }
                $texto    = substr($sql, $ini, $i - $ini);
                // Un entero que no cabe en 64 bits se lee como decimal, como en
                // SQLite: (int) lo dejaba en PHP_INT_MAX sin avisar, y un
                // 18446744073709551615 (BIGINT UNSIGNED) se guardaba cambiado.
                // 9223372036854775808 con un - delante es PHP_INT_MIN: lo ve el analizador
                $entero = !$flotante && self::cifrasCaben($texto);
                $tokens[] = ['t' => 'num', 'v' => $entero ? (int)$texto : (float)$texto, 'l' => $linea, 'p' => $ini, 'texto' => $texto];
                continue;
            }

            // Identificador / palabra clave
            if ($c === '_' || ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')) {
                $i += strspn($sql, self::LETRAS, $i);
                $texto    = substr($sql, $ini, $i - $ini);
                $tokens[] = ['t' => 'id', 'v' => $texto, 'u' => strtoupper($texto), 'q' => false, 'l' => $linea, 'p' => $ini];
                continue;
            }

            // Marcador de parámetro ligado
            if ($c === '?') {
                $tokens[] = ['t' => 'param', 'v' => '?', 'l' => $linea, 'p' => $ini];
                $i++;
                continue;
            }

            // <=> de MySQL (2.9), el = que trata NULL como un valor; ->> y -> de
            // SQLite y MySQL, para sacar un trozo de un JSON
            if (($c === '<' && substr($sql, $i, 3) === '<=>') || ($c === '-' && ($sql[$i + 1] ?? '') === '>')) {
                $op = $c === '<' || ($sql[$i + 2] ?? '') === '>' ? substr($sql, $i, 3) : '->';
                $tokens[] = ['t' => 'op', 'v' => $op, 'l' => $linea, 'p' => $ini];
                $i += \strlen($op);
                continue;
            }

            // Operadores de dos caracteres
            if ($i + 1 < $n && isset(self::OPS2[$dos = $c . $sql[$i + 1]])) {
                $tokens[] = ['t' => 'op', 'v' => $dos, 'l' => $linea, 'p' => $ini];
                $i += 2;
                continue;
            }

            // Operadores de un carácter
            if (isset(self::OPS1[$c])) {
                $tokens[] = ['t' => 'op', 'v' => $c, 'l' => $linea, 'p' => $ini];
                $i++;
                continue;
            }

            // Signos
            if (isset(self::PUNC[$c])) {
                $tokens[] = ['t' => 'punc', 'v' => $c, 'l' => $linea, 'p' => $ini];
                $i++;
                continue;
            }

            throw JsonSqlDbError::syntax("Carácter no válido '$c' (línea $linea)");
        }

        $tokens[] = ['t' => 'eof', 'v' => '', 'l' => $linea, 'p' => $n];
        return $tokens;
    }

    /** ¿Cabe en un entero de 64 bits este número escrito solo con cifras (y un signo)? */
    public static function cifrasCaben(string $cifras, bool $negativo = false): bool
    {
        $cifras = ltrim($cifras, '0') ?: '0';
        $limite = $negativo ? '9223372036854775808' : '9223372036854775807';
        return \strlen($cifras) < 19 || (\strlen($cifras) === 19 && strcmp($cifras, $limite) <= 0);
    }
}
