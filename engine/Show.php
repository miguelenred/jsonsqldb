<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Sentencias de consulta de la estructura. Solo leen; devuelven filas como un
 * SELECT para que el panel y las aplicaciones las traten igual.
 *
 *   SHOW TABLES
 *   SHOW SCHEMA t          (o SHOW COLUMNS FROM t)
 *   SHOW KEYS FROM t       claves únicas y foráneas
 *   SHOW TRIGGERS [FROM t]
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
            case 'show_schema':    return $this->esquema($ast['tabla']);
            case 'show_keys':      return $this->claves($ast['tabla']);
            case 'show_triggers':  return $this->triggers($ast['tabla']);
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
                'filas'    => count($this->cat->storage()->leerFilas($t)),
                'creada'   => $meta['created_at'] ?? null,
            ];
        }
        return $out;
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
                 'columnas_destino' => implode(',', $fk['references']),
                 'on_delete'        => $fk['on_delete'],
                 'on_update'        => $fk['on_update']]
            );
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

    /** @param string[] $columnas */
    private static function clave(string $tipo, string $nombre, array $columnas): array
    {
        return [
            'tipo'             => $tipo,
            'nombre'           => $nombre,
            'columnas'         => implode(',', $columnas),
            'tabla_destino'    => null,
            'columnas_destino' => null,
            'on_delete'        => null,
            'on_update'        => null,
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
