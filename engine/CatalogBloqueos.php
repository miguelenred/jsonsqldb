<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Qué tablas hay que bloquear para una escritura que puede tocar varias (2.10,
 * aparte de Catalog para que una lectura o una escritura de una sola tabla no
 * tengan que compilarlo): las de sus claves foráneas, hacia arriba y hacia
 * abajo, y las que escriben sus triggers.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class CatalogBloqueos
{
    /**
     * Tope de tablas que se bloquean una a una. Pasado ese número, pedir tantos
     * bloqueos cuesta más que el exclusivo de la base.
     */
    private const MAX_TABLAS_BLOQUEADAS = 8;

    /**
     * Todas las tablas que una escritura sobre $tabla puede llegar a tocar.
     *
     * Escribir en una tabla no se queda siempre en ella: una clave foránea con
     * ON DELETE CASCADE arrastra filas hijas, un trigger puede escribir donde
     * quiera, y esas tablas tienen a su vez sus propias claves y triggers. Hay
     * que saber el conjunto ENTERO antes de bloquear nada: pedir un bloqueo a
     * mitad de una escritura es la receta del interbloqueo.
     *
     * Se recorre en las dos direcciones y de forma transitiva:
     *   - las tablas a las que $tabla apunta (padres);
     *   - las que apuntan a $tabla (hijas, que una cascada puede tocar);
     *   - las que escriben los triggers, leyendo su SQL.
     *
     * Devuelve la lista ORDENADA, que es lo que hace imposible el interbloqueo:
     * si todos los procesos piden los bloqueos en el mismo orden, no puede haber
     * un ciclo de esperas.
     *
     * Devuelve null si no se puede afirmar el conjunto —una tabla que no existe,
     * un trigger cuya SQL no se deja analizar, demasiadas tablas— y entonces
     * quien llama tiene que caer al bloqueo de toda la base.
     *
     * @return list<string>|null
     */
    public static function cierreDeTablas(Catalog $cat, string $tabla): ?array
    {
        if (!$cat->existe($tabla)) {
            return null;
        }
        $vistas    = [];
        $pendiente = [$tabla];

        while ($pendiente !== []) {
            $actual = array_pop($pendiente);
            $clave  = strtolower($actual);
            if (isset($vistas[$clave])) {
                continue;
            }
            if (!$cat->existe($actual)) {
                return null;                       // referencia a algo que no está
            }
            $vistas[$clave] = $actual;

            // Un conjunto enorme no compensa: sale más barato el exclusivo de
            // la base que pedir cincuenta bloqueos uno a uno
            if (\count($vistas) > self::MAX_TABLAS_BLOQUEADAS) {
                return null;
            }

            $meta = $cat->meta($actual);

            // Hacia arriba: las tablas a las que esta apunta
            foreach ($meta['foreign_keys'] as $fk) {
                $pendiente[] = (string)$fk['table'];
            }
            // Hacia abajo: las que apuntan a esta
            foreach ($cat->tablasEsquema() as $otra) {
                foreach ($cat->meta($otra)['foreign_keys'] as $fk) {
                    if (strcasecmp((string)$fk['table'], $actual) === 0) {
                        $pendiente[] = $otra;
                    }
                }
            }
            // Y donde escriban sus triggers
            foreach ($meta['triggers'] as $trg) {
                foreach (self::sentenciasDe((array)($trg['body'] ?? [])) as $sql) {
                    $destino = self::tablaDeLaSentencia($cat, $sql);
                    if ($destino === null) {
                        return null;               // no se sabe a dónde escribe
                    }
                    if ($destino !== '') {
                        $pendiente[] = $destino;
                    }
                }
            }
        }

        $lista = array_values($vistas);
        sort($lista, SORT_STRING);
        return $lista;
    }

    /**
     * Los textos de las sentencias de un cuerpo de trigger, entrando en los
     * bloques IF, y sus condiciones (que pueden leer de otras tablas).
     *
     * @param list<string|array> $cuerpo
     * @return list<string>
     */
    public static function sentenciasDe(array $cuerpo): array
    {
        $todas = [];
        foreach ($cuerpo as $paso) {
            if (!\is_array($paso)) {
                $todas[] = (string)$paso;
                continue;
            }
            foreach ($paso['si'] as [$cond, $sentencias]) {
                $todas[] = 'SELECT ' . $cond;
                array_push($todas, ...self::sentenciasDe($sentencias));
            }
            array_push($todas, ...self::sentenciasDe($paso['sino'] ?? []));
        }
        return $todas;
    }

    /**
     * Sobre qué tabla escribe una sentencia de trigger.
     *
     * Cadena vacía si no escribe en ninguna (un SELECT), y null si no se puede
     * saber, que obliga a bloquear la base entera. Se analiza de verdad en vez
     * de mirar el texto: una SQL con un nombre de tabla dentro de una cadena
     * engañaría a cualquier expresión regular.
     */
    private static function tablaDeLaSentencia(Catalog $cat, string $sql): ?string
    {
        // SET NEW.col = … solo cambia la fila que se está escribiendo
        if (preg_match('/^\s*SET\s+NEW\s*\./i', $sql)) {
            return '';
        }
        try {
            $ast = Parser::analizar($sql, [], $cat->nombres());
        } catch (\Throwable) {
            return null;
        }
        // Lo que lee de otras tablas también habría que bloquearlo: un SELECT
        // con FROM o con subconsultas, un INSERT … SELECT, y desde la 2.9 las
        // subconsultas, UPDATE … FROM y DELETE … USING (ver
        // Database::leeOtras()), que se leían sin bloqueo
        if (\in_array($ast['k'], ['select', 'union'], true)) {
            return $ast['k'] === 'select' && ($ast['from'] ?? []) === [] && empty($ast['subconsultas']) ? '' : null;
        }
        if (!\in_array($ast['k'], ['insert', 'update', 'delete'], true) || Database::leeOtras($ast)) {
            return null;                           // cualquier otra cosa, a la base
        }
        $tabla = $ast['tabla'] ?? null;
        return \is_string($tabla) && $tabla !== '' ? $tabla : null;
    }
}
