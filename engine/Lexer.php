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
 */
final class Lexer
{
    private const OPS2 = ['<>', '!=', '<=', '>=', '||'];
    private const OPS1 = ['=', '<', '>', '+', '-', '*', '/', '%'];
    private const PUNC = ['(', ')', ',', '.', ';'];

    /** @return array<int,array> */
    public static function tokens(string $sql): array
    {
        $tokens = [];
        $n      = strlen($sql);
        $i      = 0;
        $linea  = 1;

        while ($i < $n) {
            $c   = $sql[$i];
            $ini = $i;

            // Espacios y saltos de línea
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                if ($c === "\n") { $linea++; }
                $i++;
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
                $valor = '';
                $i++;
                while (true) {
                    if ($i >= $n) {
                        throw JsonSqlDbError::syntax("Cadena sin cerrar (línea $linea)");
                    }
                    if ($sql[$i] === "'") {
                        if ($i + 1 < $n && $sql[$i + 1] === "'") { $valor .= "'"; $i += 2; continue; }
                        $i++;
                        break;
                    }
                    if ($sql[$i] === "\n") { $linea++; }
                    $valor .= $sql[$i++];
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
                while ($i < $n && $sql[$i] >= '0' && $sql[$i] <= '9') { $i++; }
                $flotante = false;
                if ($i < $n && $sql[$i] === '.') {
                    $flotante = true;
                    $i++;
                    while ($i < $n && $sql[$i] >= '0' && $sql[$i] <= '9') { $i++; }
                }
                if ($i < $n && ($sql[$i] === 'e' || $sql[$i] === 'E')) {
                    $salto = $i + 1;
                    if ($salto < $n && ($sql[$salto] === '+' || $sql[$salto] === '-')) { $salto++; }
                    if ($salto < $n && $sql[$salto] >= '0' && $sql[$salto] <= '9') {
                        $flotante = true;
                        $i = $salto;
                        while ($i < $n && $sql[$i] >= '0' && $sql[$i] <= '9') { $i++; }
                    }
                }
                $texto    = substr($sql, $ini, $i - $ini);
                $tokens[] = ['t' => 'num', 'v' => $flotante ? (float)$texto : (int)$texto, 'l' => $linea, 'p' => $ini];
                continue;
            }

            // Identificador / palabra clave
            if ($c === '_' || ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')) {
                while ($i < $n) {
                    $d = $sql[$i];
                    if ($d === '_' || $d === '$' || ($d >= 'a' && $d <= 'z')
                        || ($d >= 'A' && $d <= 'Z') || ($d >= '0' && $d <= '9')) {
                        $i++;
                        continue;
                    }
                    break;
                }
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

            // Operadores de dos caracteres
            if ($i + 1 < $n && in_array(substr($sql, $i, 2), self::OPS2, true)) {
                $tokens[] = ['t' => 'op', 'v' => substr($sql, $i, 2), 'l' => $linea, 'p' => $ini];
                $i += 2;
                continue;
            }

            // Operadores de un carácter
            if (in_array($c, self::OPS1, true)) {
                $tokens[] = ['t' => 'op', 'v' => $c, 'l' => $linea, 'p' => $ini];
                $i++;
                continue;
            }

            // Signos
            if (in_array($c, self::PUNC, true)) {
                $tokens[] = ['t' => 'punc', 'v' => $c, 'l' => $linea, 'p' => $ini];
                $i++;
                continue;
            }

            throw JsonSqlDbError::syntax("Carácter no válido '$c' (línea $linea)");
        }

        $tokens[] = ['t' => 'eof', 'v' => '', 'l' => $linea, 'p' => $n];
        return $tokens;
    }
}
