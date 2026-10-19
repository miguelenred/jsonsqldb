<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las funciones que se escriben con palabras entre sus argumentos (2.10, aparte
 * de ParserConsulta para que una consulta sin ellas no tenga que compilarlas):
 * TRIM, POSITION, SUBSTRING/SUBSTR, EXTRACT, TIMESTAMPDIFF, TIMESTAMPADD,
 * DATE_ADD, DATE_SUB, ADDDATE, SUBDATE y CONVERT (Parser::CON_PALABRAS).
 *
 * Métodos de Parser que reciben el analizador en $an. Hereda de Parser solo
 * para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class ParserFunciones extends Parser
{
    /**
     * Las funciones que se escriben con palabras en vez de comas (2.9), ya con
     * el paréntesis abierto: TRIM([LEADING|TRAILING|BOTH] [c] FROM x),
     * POSITION(a IN b), SUBSTRING(x FROM i [FOR n]), EXTRACT(unidad FROM f),
     * CONVERT(x, tipo) y CONVERT(x USING utf8). También sus formas con comas,
     * como SUBSTR(x, i). Solo la llaman los nombres de Parser::CON_PALABRAS.
     */
    public static function funcionConPalabras(Parser $an, string $nombre): array
    {
        $fn = static fn(string $n, array $args): array => ['k' => 'fn', 'nombre' => $n, 'args' => $args, 'star' => false, 'distinct' => false];
        switch ($nombre) {
            case 'TRIM':
                $lado = 'TRIM';
                if ($an->comer('id', 'LEADING'))      { $lado = 'LTRIM'; }
                elseif ($an->comer('id', 'TRAILING')) { $lado = 'RTRIM'; }
                else                                    { $an->comer('id', 'BOTH'); }
                $quitar = null;
                if (!$an->es('id', 'FROM')) {
                    $quitar = $an->exprBits();
                    if (!$an->es('id', 'FROM')) {
                        if ($lado !== 'TRIM') {
                            throw JsonSqlDbError::syntax("TRIM($lado …) necesita FROM");
                        }
                        // TRIM(x) o TRIM(x, c): la forma de siempre
                        $args = [$quitar];
                        while ($an->comer('punc', ',')) {
                            $args[] = $an->expr();
                        }
                        $an->exigir('punc', ')');
                        return $fn('TRIM', $args);
                    }
                }
                $an->exigir('id', 'FROM');
                $texto = $an->expr();
                $an->exigir('punc', ')');
                return $fn($lado, $quitar === null ? [$texto] : [$texto, $quitar]);
            case 'POSITION':
                $buscado = $an->exprBits();
                if (!$an->comer('id', 'IN')) {
                    throw JsonSqlDbError::syntax('POSITION(a IN b): falta IN');
                }
                $texto = $an->exprBits();
                $an->exigir('punc', ')');
                return $fn('INSTR', [$texto, $buscado]);
            case 'SUBSTRING':
            case 'SUBSTR':
                $texto = $an->expr();
                if (!$an->comer('id', 'FROM')) {
                    $args = [$texto];
                    while ($an->comer('punc', ',')) {
                        $args[] = $an->expr();
                    }
                    $an->exigir('punc', ')');
                    return $fn($nombre, $args);
                }
                $args = [$texto, $an->expr()];
                if ($an->comer('id', 'FOR')) {
                    $args[] = $an->expr();
                }
                $an->exigir('punc', ')');
                return $fn('SUBSTR', $args);
            case 'EXTRACT':
                $unidad = strtoupper($an->nombreSimple('unidad'));
                $funciones = ['YEAR' => 'YEAR', 'MONTH' => 'MONTH', 'DAY' => 'DAY', 'HOUR' => 'HOUR', 'MINUTE' => 'MINUTE',
                              'SECOND' => 'SECOND', 'QUARTER' => 'QUARTER'];
                if (!isset($funciones[$unidad])) {
                    throw JsonSqlDbError::syntax("EXTRACT($unidad FROM …) no está soportado: YEAR, QUARTER, MONTH, DAY, HOUR, MINUTE o SECOND");
                }
                $an->exigir('id', 'FROM');
                $fecha = $an->expr();
                $an->exigir('punc', ')');
                return $fn($funciones[$unidad], [$fecha]);
            case 'TIMESTAMPDIFF':
            case 'TIMESTAMPADD':
                $unidad = $an->unidadDeTiempo();
                $an->exigir('punc', ',');
                $a = $an->expr();
                $an->exigir('punc', ',');
                $b = $an->expr();
                $an->exigir('punc', ')');
                return $nombre === 'TIMESTAMPDIFF' ? $fn('TIMESTAMPDIFF', [$unidad, $a, $b]) : $fn('DATE_ADD', [$b, $a, $unidad]);
            case 'DATE_ADD':
            case 'DATE_SUB':
            case 'ADDDATE':
            case 'SUBDATE':
                // DATE_ADD(f, INTERVAL n unidad); ADDDATE(f, n) son días
                $fecha = $an->expr();
                $an->exigir('punc', ',');
                $cuanto = $an->expr();
                $an->exigir('punc', ')');
                $resta = $nombre === 'DATE_SUB' || $nombre === 'SUBDATE';
                if (($cuanto['k'] ?? '') === 'intervalo') {
                    return $fn($resta ? 'DATE_SUB' : 'DATE_ADD', [$fecha, $cuanto['e'], $cuanto['u']]);
                }
                if ($nombre === 'DATE_ADD' || $nombre === 'DATE_SUB') {
                    throw JsonSqlDbError::syntax("$nombre(fecha, INTERVAL n unidad): falta el INTERVAL");
                }
                return $fn($resta ? 'DATE_SUB' : 'DATE_ADD', [$fecha, $cuanto, ['k' => 'lit', 'v' => 'DAY']]);
            default: // CONVERT
                $e = $an->expr();
                if ($an->comer('id', 'USING')) {
                    $juego = strtolower($an->nombreSimple('juego de caracteres'));
                    if (!\in_array($juego, ['utf8', 'utf8mb4', 'utf8mb3'], true)) {
                        throw JsonSqlDbError::syntax("CONVERT(… USING $juego): aquí todo es UTF-8; solo vale USING utf8 o utf8mb4");
                    }
                    $an->exigir('punc', ')');
                    return $e;
                }
                $an->exigir('punc', ',');
                $tipo = $an->tipoDeCast();
                $an->exigir('punc', ')');
                return ['k' => 'cast', 'e' => $e, 'tipo' => $tipo];
        }
    }
}
