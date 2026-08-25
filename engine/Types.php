<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Tipos soportados por jsonSQLDB.
 *
 *   INTEGER   enteros
 *   DOUBLE    coma flotante
 *   DECIMAL   coma flotante redondeado a 'scale' decimales
 *   TEXT      cadena (con 'length' opcional)
 *   DATETIME  'yyyy-MM-dd[ HH:mm[:ss[.fff]]]'  (hora y milisegundos opcionales)
 *
 * Se aceptan los alias habituales de SQLite para que el mismo CREATE TABLE
 * funcione en ambos motores.
 */
final class Types
{
    public const INTEGER  = 'INTEGER';
    public const DOUBLE   = 'DOUBLE';
    public const DECIMAL  = 'DECIMAL';
    public const TEXT     = 'TEXT';
    public const DATETIME = 'DATETIME';

    /** Alias aceptados en el CREATE TABLE => tipo interno */
    private const ALIAS = [
        'INT' => self::INTEGER, 'INTEGER' => self::INTEGER, 'TINYINT' => self::INTEGER,
        'SMALLINT' => self::INTEGER, 'MEDIUMINT' => self::INTEGER, 'BIGINT' => self::INTEGER,
        'BOOL' => self::INTEGER, 'BOOLEAN' => self::INTEGER,

        'REAL' => self::DOUBLE, 'FLOAT' => self::DOUBLE, 'DOUBLE' => self::DOUBLE,
        'DOUBLE PRECISION' => self::DOUBLE,

        'DECIMAL' => self::DECIMAL, 'NUMERIC' => self::DECIMAL, 'NUMBER' => self::DECIMAL,
        'MONEY' => self::DECIMAL,

        'TEXT' => self::TEXT, 'VARCHAR' => self::TEXT, 'NVARCHAR' => self::TEXT,
        'CHAR' => self::TEXT, 'NCHAR' => self::TEXT, 'CLOB' => self::TEXT,
        'STRING' => self::TEXT, 'BLOB' => self::TEXT,

        'DATE' => self::DATETIME, 'DATETIME' => self::DATETIME, 'TIMESTAMP' => self::DATETIME,
    ];

    /** yyyy-MM-dd[ HH:mm[:ss[.fff]]] — separador T o espacio en la entrada */
    private const RE_FECHA = '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,3}))?)?)?$/';

    /**
     * Analiza una declaración de tipo: 'VARCHAR(50)', 'DECIMAL(10,2)', 'INT'.
     * Devuelve ['type'=>..., 'length'=>?int, 'scale'=>?int].
     */
    public static function parse(string $declarado): array
    {
        $d = strtoupper(trim($declarado));
        $length = null;
        $scale  = null;

        if (preg_match('/^([A-Z ]+?)\s*\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\)$/', $d, $m)) {
            $d      = trim($m[1]);
            $length = (int)$m[2];
            $scale  = isset($m[3]) ? (int)$m[3] : null;
        }

        if (!isset(self::ALIAS[$d])) {
            throw JsonSqlDbError::type("Tipo de dato no soportado: $declarado");
        }
        $tipo = self::ALIAS[$d];

        if ($tipo === self::DECIMAL) {
            // scale a null si la declaración no la traía: así no pisa la que ya
            // esté guardada en la estructura. El valor por defecto lo pone
            // Catalog::normalizarColumna.
            return ['type' => $tipo, 'length' => null, 'scale' => $scale];
        }
        if ($tipo === self::TEXT) {
            return ['type' => $tipo, 'length' => $length, 'scale' => null];
        }
        return ['type' => $tipo, 'length' => null, 'scale' => null];
    }

    /**
     * Convierte un valor al tipo de la columna. Devuelve el valor listo para
     * guardar en JSON. NULL se propaga (el NOT NULL lo comprueba el ejecutor).
     *
     * @param array $col Columna normalizada del catálogo.
     */
    public static function cast($valor, array $col)
    {
        if ($valor === null) {
            return null;
        }
        if (is_array($valor) || is_object($valor)) {
            throw JsonSqlDbError::type("Valor no escalar para la columna '{$col['name']}'");
        }
        if (is_bool($valor)) {
            $valor = $valor ? 1 : 0;
        }

        switch ($col['type']) {
            case self::INTEGER:
                if (is_int($valor)) return $valor;
                if (is_float($valor) && floor($valor) === $valor) return (int)$valor;
                if (is_string($valor) && preg_match('/^[+-]?\d+$/', trim($valor))) return (int)trim($valor);
                throw JsonSqlDbError::type("Valor no entero para '{$col['name']}': " . self::texto($valor));

            case self::DOUBLE:
                if (is_int($valor) || is_float($valor)) return (float)$valor;
                if (is_string($valor) && is_numeric(trim($valor))) return (float)trim($valor);
                throw JsonSqlDbError::type("Valor no numérico para '{$col['name']}': " . self::texto($valor));

            case self::DECIMAL:
                if (is_int($valor) || is_float($valor)) return round((float)$valor, $col['scale']);
                if (is_string($valor) && is_numeric(trim($valor))) return round((float)trim($valor), $col['scale']);
                throw JsonSqlDbError::type("Valor no numérico para '{$col['name']}': " . self::texto($valor));

            case self::DATETIME:
                return self::fecha((string)$valor, $col['name']);

            default: // TEXT
                $s = (string)$valor;
                if ($col['length'] !== null && self::longitud($s) > $col['length']) {
                    throw JsonSqlDbError::type("Texto demasiado largo para '{$col['name']}' (máx {$col['length']})");
                }
                return $s;
        }
    }

    /**
     * Valida y normaliza una fecha. Conserva la precisión recibida
     * (solo fecha, con minutos, con segundos o con milisegundos).
     */
    public static function fecha(string $valor, string $columna): string
    {
        $v = trim($valor);
        if (!preg_match(self::RE_FECHA, $v, $m)) {
            throw JsonSqlDbError::type(
                "Fecha inválida para '$columna': '$valor' (formato yyyy-MM-dd[ HH:mm[:ss[.fff]]])"
            );
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw JsonSqlDbError::type("Fecha inexistente para '$columna': '$valor'");
        }
        $out = "$m[1]-$m[2]-$m[3]";
        if (isset($m[4])) {
            if ((int)$m[4] > 23 || (int)$m[5] > 59) {
                throw JsonSqlDbError::type("Hora inválida para '$columna': '$valor'");
            }
            $out .= " $m[4]:$m[5]";
            // Los grupos opcionales no aparecen si no coinciden, así que basta
            // con isset(): nunca llegan como cadena vacía
            if (isset($m[6])) {
                if ((int)$m[6] > 59) {
                    throw JsonSqlDbError::type("Segundos inválidos para '$columna': '$valor'");
                }
                $out .= ":$m[6]";
                if (isset($m[7])) {
                    $out .= '.' . str_pad($m[7], 3, '0');
                }
            }
        }
        return $out;
    }

    /** ¿La cadena tiene forma de fecha jsonSQLDB? (usado por funciones de fecha) */
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
        $decl = self::parse($tipo);
        $base = $decl['type'];

        switch ($base) {
            case 'INTEGER':
                // Se trunca hacia cero, no se redondea: CAST(1.9 AS INTEGER) = 1
                return (int)Valor::aNumero($v);

            case 'DOUBLE':
                return (float)Valor::aNumero($v);

            case 'DECIMAL':
                return round((float)Valor::aNumero($v), (int)($decl['scale'] ?? 2));

            case 'TEXT':
                return Valor::aTexto($v);

            case 'BOOLEAN':
                return Valor::verdadero($v) === true ? 1 : 0;

            case 'DATETIME':
                $t = trim(Valor::aTexto($v));
                if (!self::esFecha($t)) {
                    throw JsonSqlDbError::type(
                        "CAST a $base: '$t' no es una fecha con formato AAAA-MM-DD"
                    );
                }
                return $t;
        }

        throw JsonSqlDbError::syntax("CAST a un tipo desconocido: '$tipo'");
    }

    public static function esFecha(string $valor): bool
    {
        if (!preg_match(self::RE_FECHA, trim($valor), $m)) {
            return false;
        }
        // El formato no basta: '2026-02-30' lo cumple y no existe. Sin esta
        // comprobación PHP lo "arregla" solo y lo convierte en el 2 de marzo.
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] > 0;
    }

    /** Longitud en caracteres UTF-8 sin depender de mbstring (hostings limitados). */
    public static function longitud(string $s): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($s, 'UTF-8')
            : strlen((string)preg_replace('/[\x80-\xBF]/', '', $s));
    }

    private static function texto($valor): string
    {
        return substr((string)$valor, 0, 40);
    }
}
