<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Sentencias de consulta de la estructura. Solo leen; devuelven filas como un
 * SELECT para que el panel y las aplicaciones las traten igual.
 *
 *   SHOW TABLES
 *   SHOW VIEWS
 *   SHOW SCHEMA t          (o SHOW COLUMNS FROM t)
 *   SHOW KEYS FROM t       claves únicas y foráneas
 *   SHOW TRIGGERS [FROM t]
 *   SHOW INDEXES [FROM t]
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Show
{
    private Catalog $cat;

    public function __construct(Catalog $cat)
    {
        $this->cat = $cat;
    }

    public function ejecutar(array $ast): array
    {
        switch ($ast['k']) {
            case 'show_tables':    return $this->tablas();
            case 'show_views':     return $this->vistas();
            case 'show_schema':    return $this->esquema($ast['tabla']);
            case 'show_keys':      return $this->claves($ast['tabla']);
            case 'show_triggers':  return $this->triggers($ast['tabla']);
            case 'show_indexes':   return $this->indices($ast['tabla']);
            case 'show_create':    return isset($ast['vista']) ? $this->crearVista($ast['vista']) : $this->crear($ast['tabla']);
        }
        throw JsonSqlDbError::syntax("Sentencia no ejecutable: {$ast['k']}");
    }

    private function tablas(): array
    {
        $out = [];
        foreach ($this->cat->tablas() as $t) {
            $meta = $this->cat->meta($t);
            $out[] = [
                'tabla'    => $t,
                'columnas' => count($meta['columns']),
                'filas'    => StorageLectura::contarFilas($this->cat->storage(), $t),
                'creada'   => $meta['created_at'] ?? null,
            ];
        }
        return $out;
    }

    private function vistas(): array
    {
        $out = [];
        foreach ($this->cat->vistas() as $nombre => $v) {
            $out[] = [
                'vista'  => (string)$nombre,
                'sql'    => (string)($v['sql'] ?? ''),
                'creada' => $v['created_at'] ?? null,
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['vista'], $b['vista']));
        return $out;
    }

    /**
     * SHOW CREATE TABLE (2.9): la sentencia que crea la tabla tal y como
     * está, con sus claves, y un CREATE INDEX por cada índice que no sale de
     * una clave. Ejecutarla en otra base da la misma estructura.
     */
    private function crear(string $tabla): array
    {
        $meta  = $this->exigirTabla($tabla);
        $q     = static fn(string $n): string => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $n) ? $n : '"' . str_replace('"', '""', $n) . '"';
        $lista = static fn(array $cols): string => implode(', ', array_map($q, $cols));
        $pk    = Catalog::clavePrimaria($meta);
        $auto  = Catalog::columnaAutoincremento($meta);
        $partes = [];
        foreach ($meta['columns'] as $c) {
            // DECIMAL guarda solo su escala, con unas 15 cifras exactas en total
            $tipo = isset($c['enum']) ? 'ENUM(' . implode(', ', array_map(static fn($e) => "'" . str_replace("'", "''", $e) . "'", $c['enum'])) . ')'
                : ($c['type'] === 'DECIMAL' && $c['scale'] !== null ? 'DECIMAL(' . max(15, $c['scale'] + 1) . ', ' . $c['scale'] . ')'
                : ($c['length'] === null ? $c['type']
                : ($c['type'] === 'TEXT' ? 'VARCHAR' : $c['type']) . '(' . $c['length'] . ($c['scale'] !== null ? ', ' . $c['scale'] : '') . ')'));
            $d = $q($c['name']) . ' ' . $tipo . (empty($c['unsigned']) ? '' : ' UNSIGNED')
                . (isset($c['collate']) ? ' COLLATE ' . $c['collate'] : '');
            if (count($pk) === 1 && $c['pk']) {
                $d .= ' PRIMARY KEY' . ($auto === $c['name'] ? ' AUTOINCREMENT' : '');
            } elseif ($c['notnull']) {
                $d .= ' NOT NULL';
            }
            if ($c['unique']) {
                $d .= ' UNIQUE';
            }
            if (isset($c['default_expr'])) {
                $e  = (string)$c['default_expr'];
                $d .= ' DEFAULT ' . (preg_match('/^CURRENT_(TIMESTAMP|DATE|TIME)$/i', $e) ? $e : "($e)");
            } elseif ($c['default'] !== null) {
                $v  = $c['default'];
                $d .= ' DEFAULT ' . (is_string($v) ? "'" . str_replace("'", "''", $v) . "'" : (is_bool($v) ? (int)$v : Valor::aTexto($v)));
            }
            $partes[] = $d;
        }
        if (count($pk) > 1) {
            $partes[] = 'PRIMARY KEY (' . $lista($pk) . ')';
        }
        foreach ($meta['unique'] as $u) {
            $partes[] = 'UNIQUE (' . $lista($u['columns']) . ')';
        }
        foreach ($meta['foreign_keys'] as $fk) {
            $partes[] = 'FOREIGN KEY (' . $lista($fk['columns']) . ') REFERENCES ' . $q($fk['table']) . ' (' . $lista($fk['references']) . ')'
                . ($fk['on_delete'] !== 'NO ACTION' ? ' ON DELETE ' . $fk['on_delete'] : '')
                . ($fk['on_update'] !== 'NO ACTION' ? ' ON UPDATE ' . $fk['on_update'] : '');
        }
        foreach ($meta['checks'] as $ck) {
            $partes[] = ($ck['name'] !== null ? 'CONSTRAINT ' . $q($ck['name']) . ' ' : '') . 'CHECK (' . $ck['sql'] . ')';
        }
        $sql = 'CREATE TABLE ' . $q($tabla) . " (\n  " . implode(",\n  ", $partes) . "\n)";
        foreach ($meta['indexes'] as $ix) {
            $sql .= ";\nCREATE INDEX " . $q($ix['name']) . ' ON ' . $q($tabla) . ' (' . $lista($ix['columns']) . ')';
        }
        return [['tabla' => $tabla, 'sql' => $sql]];
    }

    /** SHOW CREATE VIEW (2.9) */
    private function crearVista(string $nombre): array
    {
        foreach ($this->cat->vistas() as $v => $def) {
            if (strcasecmp((string)$v, $nombre) === 0) {
                return [['vista' => (string)$v, 'sql' => 'CREATE VIEW ' . $v . ' AS ' . (string)($def['sql'] ?? '')]];
            }
        }
        throw JsonSqlDbError::schema("La vista '$nombre' no existe");
    }

    private function esquema(string $tabla): array
    {
        $meta = $this->exigirTabla($tabla);
        $auto = Catalog::columnaAutoincremento($meta);
        $out  = [];
        foreach ($meta['columns'] as $c) {
            $out[] = [
                'columna'  => $c['name'],
                'tipo'     => $c['type'],
                'longitud' => $c['length'],
                'escala'   => $c['scale'],
                'pk'       => $c['pk'] ? 1 : 0,
                'auto'     => $auto !== null && $auto === $c['name'] ? 1 : 0,
                'notnull'  => $c['notnull'] ? 1 : 0,
                'unico'    => $c['unique'] ? 1 : 0,
                'defecto'  => $c['default'],
                // El DEFAULT que se calcula al insertar (2.8): su SQL, o null
                'defecto_calculado' => $c['default_expr'] ?? null,
                // UNSIGNED de MySQL (2.9)
                'sin_signo' => empty($c['unsigned']) ? 0 : 1,
                // La lista de un ENUM (2.9), o null
                'valores'   => $c['enum'] ?? null,
                // COLLATE NOCASE o RTRIM (2.9), o null: la binaria
                'colacion'  => $c['collate'] ?? null,
            ];
        }
        return $out;
    }

    private function claves(string $tabla): array
    {
        $meta = $this->exigirTabla($tabla);
        $out  = [];

        $pk = Catalog::clavePrimaria($meta);
        if ($pk !== []) {
            $out[] = self::clave('PRIMARY', 'PRIMARY', $pk);
        }
        foreach ($meta['columns'] as $c) {
            if ($c['unique'] && !$c['pk']) {
                $out[] = self::clave('UNIQUE', 'uq_' . $tabla . '_' . $c['name'], [$c['name']]);
            }
        }
        foreach ($meta['unique'] as $uq) {
            $out[] = self::clave('UNIQUE', (string)($uq['name'] ?? 'uq'), $uq['columns']);
        }
        foreach ($meta['foreign_keys'] as $fk) {
            $out[] = array_merge(
                self::clave('FOREIGN', (string)$fk['name'], $fk['columns']),
                ['tabla_destino'    => $fk['table'],
                 'columnas_destino' => self::lista($fk['references']),
                 'on_delete'        => $fk['on_delete'],
                 'on_update'        => $fk['on_update']]
            );
        }
        // CHECK (2.9): su expresión; sin nombre, no se puede quitar por él
        foreach ($meta['checks'] as $ck) {
            $out[] = array_merge(self::clave('CHECK', (string)($ck['name'] ?? ''), []), ['expresion' => $ck['sql']]);
        }
        return $out;
    }

    private function triggers(?string $tabla): array
    {
        $tablas = $tabla === null ? $this->cat->tablas() : [$this->exigirTabla($tabla)['table']];
        $out    = [];
        foreach ($tablas as $t) {
            foreach ($this->cat->meta($t)['triggers'] as $trg) {
                $out[] = [
                    'nombre'  => $trg['name'],
                    'tabla'   => $t,
                    'timing'  => $trg['timing'],
                    'evento'  => $trg['event'],
                    'cuando'  => $trg['when'] ?? null,
                    'sql'     => $trg['sql'] ?? null,
                ];
            }
        }
        return $out;
    }

    private function indices(?string $tabla): array
    {
        $tablas = $tabla === null ? $this->cat->tablas() : [$this->exigirTabla($tabla)['table']];
        $out    = [];
        foreach ($tablas as $t) {
            foreach ($this->cat->indicesDe($t) as $idx) {
                $out[] = [
                    'indice'   => $idx['name'],
                    'tabla'    => $t,
                    'columnas' => self::lista($idx['columns']),
                    // Los automáticos vienen de una PRIMARY KEY o un UNIQUE y no
                    // se pueden borrar por su cuenta
                    'automatico' => $idx['auto'] ? 1 : 0,
                ];
            }
        }
        return $out;
    }

    /**
     * Las columnas separadas por comas; la que lleva una coma o unas comillas,
     * entre comillas dobles, como en CSV (2.9: se partía en dos al leerla)
     *
     * @param string[] $columnas
     */
    private static function lista(array $columnas): string
    {
        return implode(',', array_map(static fn(string $c): string =>
            strpbrk($c, ',"') === false ? $c : '"' . str_replace('"', '""', $c) . '"', $columnas));
    }

    /** @param string[] $columnas */
    private static function clave(string $tipo, string $nombre, array $columnas): array
    {
        return [
            'tipo'             => $tipo,
            'nombre'           => $nombre,
            'columnas'         => self::lista($columnas),
            'tabla_destino'    => null,
            'columnas_destino' => null,
            'on_delete'        => null,
            'on_update'        => null,
            'expresion'        => null,
        ];
    }

    private function exigirTabla(string $tabla): array
    {
        if (!$this->cat->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        return $this->cat->meta($tabla);
    }
}
