<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Excepción única del motor. $sqlState identifica el tipo de fallo para que
 * la API pueda decidir qué mensaje devuelve al cliente.
 *
 * Tipos usados: CONFIG, SCHEMA, TYPE, CONSTRAINT, SYNTAX, IO, LOCK, PERMISSION
 */
final class JsonSqlDbError extends \RuntimeException
{
    public string $sqlState;

    public function __construct(string $sqlState, string $mensaje)
    {
        $this->sqlState = $sqlState;
        parent::__construct($mensaje);
    }

    public static function config(string $m): self     { return new self('CONFIG', $m); }
    public static function schema(string $m): self     { return new self('SCHEMA', $m); }
    public static function type(string $m): self       { return new self('TYPE', $m); }
    public static function constraint(string $m): self { return new self('CONSTRAINT', $m); }
    public static function syntax(string $m): self     { return new self('SYNTAX', $m); }
    public static function io(string $m): self         { return new self('IO', $m); }

    /** Se ha cortado la consulta para no agotar la memoria de PHP. */
    public static function memoria(string $m): self
    {
        return new self('MEMORIA', $m);
    }
    public static function lock(string $m): self       { return new self('LOCK', $m); }
    public static function permission(string $m): self { return new self('PERMISSION', $m); }
}
