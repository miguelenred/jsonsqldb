<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las conversiones menos corrientes: CAST(… AS tipo) y los valores de una
 * columna ENUM o UNSIGNED (2.10, aparte de Types para que una sentencia sin
 * ellas no tenga que compilarlas: sin OPcache, PHP compila en cada petición lo
 * que carga). No se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class TypesCast
{
    private function __construct()
    {
    }

    /**
     * CAST(expr AS tipo).
     *
     * Los nombres de tipo son los mismos que admite CREATE TABLE, con sus alias,
     * más los de SQLite. NULL se queda en NULL, como en cualquier motor.
     *
     * @param mixed $v
     * @return mixed
     */
    public static function convertirCast($v, string $tipo)
    {
        if ($v === null) {
            return null;
        }
        if ($tipo === 'TEXT') {
            return Valor::aTexto($v);            // el de la afinidad de texto (2.10, ver Evaluator::resolver())
        }
        if ($tipo === 'UNSIGNED') {
            // CAST(x AS UNSIGNED) de MySQL: un entero, y negativo es un error
            // (MySQL le daría la vuelta: -1 sería 18446744073709551615)
            $n = Valor::entero(Valor::aNumero($v));
            if ($n < 0) {
                throw JsonSqlDbError::type("CAST a UNSIGNED de un número negativo: $n");
            }
            return $n;
        }
        $decl = Types::parse($tipo);
        $base = $decl['type'];

        switch ($base) {
            case 'INTEGER':
                // Se trunca hacia cero, no se redondea: CAST(1.9 AS INTEGER) = 1;
                // y lo que no cabe se queda en el máximo o el mínimo, como en SQLite
                return Valor::entero(Valor::aNumero($v));

            case 'DOUBLE':
                return (float)Valor::aNumero($v);

            case 'DECIMAL':
                return round((float)Valor::aNumero($v), (int)($decl['scale'] ?? 2));

            case 'TEXT':
                return Valor::aTexto($v);

            case 'DATETIME':
                $t = trim(Valor::aTexto($v));
                if (!Types::esFecha($t)) {
                    throw JsonSqlDbError::type(
                        "CAST a $base: '$t' no es una fecha: se espera AAAA-MM-DD, con hora opcional HH:MM[:SS[.fff]] tras un espacio o una T"
                    );
                }
                return $t;
        }

        throw JsonSqlDbError::syntax("CAST a un tipo desconocido: '$tipo'");
    }

    /** Types::cast() de una columna ENUM o UNSIGNED (2.9) */
    public static function castMarcado($valor, array $col)
    {
        if (isset($col['enum'])) {
            // ENUM: uno de sus valores, sin distinguir mayúsculas, y se guarda
            // escrito como en la lista. Con el valor entero: recortado a 40
            // caracteres, como en el mensaje, uno más largo no entraba nunca (2.9)
            foreach ($col['enum'] as $e) {
                if (strcasecmp($e, (string)$valor) === 0) {
                    return $e;
                }
            }
            throw JsonSqlDbError::type("'" . Types::texto($valor) . "' no es un valor permitido en '{$col['name']}': "
                . implode(', ', array_map(static fn($e) => "'$e'", $col['enum'])));
        }
        $col['unsigned'] = false;
        $v = Types::cast($valor, $col);
        if ($v < 0) {
            throw JsonSqlDbError::type("Número negativo en una columna UNSIGNED: '{$col['name']}' = " . Types::texto($v));
        }
        return $v;
    }
}
