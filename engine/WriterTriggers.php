<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * El cuerpo de los triggers: su condición WHEN, sus sentencias con NEW y OLD
 * ya sustituidos, los bloques IF y SET NEW.col. Métodos de Writer que
 * reciben el ejecutor en $w. Van aparte para que una escritura sin triggers
 * no tenga que compilarlos (2.9): sin OPcache, PHP compila en cada petición
 * lo que carga. Hereda de Writer solo para llegar a lo protegido; no se
 * instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class WriterTriggers extends Writer
{
    private function __construct()
    {
    }

    /** ¿Se cumple una condición de trigger (WHEN, o la de un IF) con estos NEW y OLD? */
    protected static function cumpleCondicion(Writer $w, string $cond, ?array $new, ?array $old): bool
    {
        $ast = self::sustituir($w, self::analizarExpr($w, $cond), $new, $old);
        $ctx = ['fila' => [], 'sub' => fn(array $s): array => $w->seleccionar($s)];
        return Valor::verdadero(Evaluator::evaluar(Evaluator::resolver($ast, []), $ctx)) === true;
    }

    /**
     * Las sentencias de un trigger, en orden: texto de una sentencia, SET
     * NEW.col = expr, o un bloque IF … ELSEIF … ELSE … END IF.
     *
     * @param list<string|array> $cuerpo
     */
    protected static function ejecutarCuerpo(Writer $w, array $trg, array $cuerpo, array $meta, ?array $new, ?array $old): ?array
    {
        foreach ($cuerpo as $paso) {
            if (\is_array($paso)) {
                $hecho = false;
                foreach ($paso['si'] as [$cond, $sentencias]) {
                    if (self::cumpleCondicion($w, $cond, $new, $old)) {
                        $new = self::ejecutarCuerpo($w, $trg, $sentencias, $meta, $new, $old);
                        $hecho = true;
                        break;
                    }
                }
                if (!$hecho && $paso['sino'] !== null) {
                    $new = self::ejecutarCuerpo($w, $trg, $paso['sino'], $meta, $new, $old);
                }
                continue;
            }
            if (strncasecmp(ltrim($paso), 'SET', 3) === 0) {
                if ($trg['timing'] !== 'BEFORE' || $trg['event'] === 'DELETE' || $new === null) {
                    throw JsonSqlDbError::syntax("SET NEW solo vale en un trigger BEFORE INSERT o BEFORE UPDATE ('{$trg['name']}')");
                }
                $set = $w->astCache[$paso] ??= ParserEscrituraAvanzada::analizarSetNew($paso);
                // De izquierda a derecha, y cada una ve lo que dejaron las
                // anteriores: como el SET de MySQL y las asignaciones de PostgreSQL
                foreach ($set['asig'] as [$col, $expr]) {
                    $def = null;
                    foreach ($meta['columns'] as $c) {
                        if (strcasecmp($c['name'], $col) === 0) { $def = $c; }
                    }
                    if ($def === null) {
                        throw JsonSqlDbError::schema("NEW.$col no es una columna de la tabla");
                    }
                    $ctx = ['fila' => [], 'sub' => fn(array $s): array => $w->seleccionar($s)];
                    $valor = Evaluator::evaluar(Evaluator::resolver(self::sustituir($w, $expr, $new, $old), []), $ctx);
                    $new[$def['name']] = Types::cast($valor, $def);
                }
                continue;
            }
            $ast = self::sustituir($w, self::analizar($w, $paso), $new, $old);
            // Cada sentencia del trigger calcula sus subconsultas otra vez:
            // las de la anterior, o de otra vez que saltó, ya no valen (2.10:
            // valían para toda la sentencia de fuera); las de esta, al acabar
            $fijas = [$w->subsFijas, $w->conjuntosFijos];
            $w->subsFijas = $w->conjuntosFijos = [];
            try {
                if ($ast['k'] === 'select' || $ast['k'] === 'union') {
                    $w->seleccionar($ast);
                } else {
                    self::ejecutarSinVolcar($w, $ast, $trg['name']);
                }
            } finally {
                [$w->subsFijas, $w->conjuntosFijos] = $fijas;
            }
        }
        return $new;
    }

    private static function ejecutarSinVolcar(Writer $w, array $ast, string $trigger): void
    {
        // Lo que un upsert de fuera sabe de las claves de esa tabla puede
        // dejar de valer: se vuelve a armar de ella (2.9, ver $unicas)
        unset($w->unicas[$ast['tabla'] ?? '']);
        // Un RETURNING de un trigger de una versión anterior no devuelve nada:
        // sus filas se mezclaban con las de la sentencia de fuera (2.10)
        $ast['returning'] = null;
        switch ($ast['k']) {
            case 'insert': WriterInsercion::insertar($w, $ast); return;
            case 'update': WriterCambios::actualizar($w, $ast); return;
            case 'delete': WriterCambios::borrar($w, $ast);     return;
        }
        throw JsonSqlDbError::syntax("El trigger '$trigger' solo puede hacer INSERT, UPDATE, DELETE o SELECT");
    }

    /** Sustituye NEW.x y OLD.x por sus valores antes de ejecutar la sentencia. */
    private static function sustituir(Writer $w, array $n, ?array $new, ?array $old)
    {
        if (isset($n['k']) && $n['k'] === 'col' && $n['tabla'] !== null) {
            $cual = strtoupper($n['tabla']);
            if ($cual === 'NEW' || $cual === 'OLD') {
                $fila = $cual === 'NEW' ? $new : $old;
                if ($fila === null) {
                    throw JsonSqlDbError::syntax("$cual no está disponible en este trigger");
                }
                if (!\array_key_exists($n['nombre'], $fila)) {
                    throw JsonSqlDbError::schema("$cual.{$n['nombre']} no es una columna de la tabla");
                }
                return ['k' => 'lit', 'v' => $fila[$n['nombre']]];
            }
        }
        foreach ($n as $clave => $valor) {
            if (\is_array($valor)) {
                $n[$clave] = self::sustituir($w, $valor, $new, $old);
            }
        }
        return $n;
    }

    private static function analizar(Writer $w, string $sql): array
    {
        return $w->astCache[$sql] ??= Parser::analizar($sql, [], $w->cat->nombres());
    }

    /** Analiza una expresión suelta (la condición WHEN de un trigger). */
    public static function analizarExpr(Writer $w, string $sql): array
    {
        $ast = self::analizar($w, 'SELECT ' . $sql);
        return $ast['cols'][0]['expr'];
    }
}
