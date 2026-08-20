<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Catálogo: gestiona la estructura de las tablas (<tabla>.meta.json).
 *
 * Formato de <tabla>.meta.json (las claves vacías no se escriben):
 * {
 *   "table": "usuarios",
 *   "columns": [
 *     {"name":"id","type":"INTEGER","pk":true,"autoincrement":true,"notnull":true},
 *     {"name":"email","type":"TEXT","length":120,"notnull":true,"unique":true},
 *     {"name":"alta","type":"DATETIME","default":"2026-01-01"}
 *   ],
 *   "unique": [ {"name":"uq_usuarios_nif","columns":["nif"]} ],
 *   "foreign_keys": [
 *     {"name":"fk_pedidos_usuario","columns":["usuario_id"],
 *      "table":"usuarios","references":["id"],
 *      "on_delete":"CASCADE","on_update":"NO ACTION"}
 *   ],
 *   "triggers": [
 *     {"name":"trg_x","timing":"AFTER","event":"INSERT","when":null,
 *      "body":["UPDATE ..."],"sql":"CREATE TRIGGER ..."}
 *   ],
 *   "autoincrement": {"column":"id","next":1},
 *   "created_at": "...", "updated_at": "..."
 * }
 */
final class Catalog
{
    private const ACCIONES_FK = ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL', 'SET DEFAULT'];
    private const TIMINGS     = ['BEFORE', 'AFTER'];
    private const EVENTOS     = ['INSERT', 'UPDATE', 'DELETE'];

    private Storage $st;
    /** @var array<string,array> caché de metas normalizadas dentro de la misma petición */
    private array $memo = [];

    public function __construct(Storage $storage)
    {
        $this->st = $storage;
    }

    public function storage(): Storage { return $this->st; }

    public function tablas(): array    { return $this->st->tablas(); }

    public function existe(string $tabla): bool { return $this->st->existe($tabla); }

    /** Estructura normalizada de una tabla. */
    public function meta(string $tabla): array
    {
        if (isset($this->memo[$tabla])) {
            return $this->memo[$tabla];
        }
        $meta = $this->st->leerMeta($tabla);

        $meta['table']        = $meta['table'] ?? $tabla;
        $meta['columns']      = array_map([self::class, 'normalizarColumna'], $meta['columns'] ?? []);
        $meta['unique']       = $meta['unique']       ?? [];
        $meta['foreign_keys'] = $meta['foreign_keys'] ?? [];
        $meta['triggers']     = $meta['triggers']     ?? [];
        $meta['autoincrement'] = $meta['autoincrement'] ?? null;

        // Un autoincremento que apunte a una columna inexistente es basura de
        // una versión anterior: se ignora en lugar de arrastrar el error.
        $auto = $meta['autoincrement']['column'] ?? null;
        if ($auto !== null && self::columna($meta, (string)$auto) === null) {
            $meta['autoincrement'] = null;
        }

        return $this->memo[$tabla] = $meta;
    }

    /** Guarda la estructura (compactada) e invalida la caché de la petición. */
    private function guardar(string $tabla, array $meta): void
    {
        $this->st->guardarMeta($tabla, self::compactar($meta));
        unset($this->memo[$tabla]);
    }

    /** Olvida lo memorizado (tras un rollback o un cambio externo). */
    public function olvidar(?string $tabla = null): void
    {
        if ($tabla === null) { $this->memo = []; } else { unset($this->memo[$tabla]); }
    }

    // ------------------------------------------------------------------
    // Columnas
    // ------------------------------------------------------------------

    /**
     * Normaliza la definición de una columna.
     * Entrada: ['name'=>'id','type'=>'INTEGER','pk'=>true,'autoincrement'=>true,
     *           'notnull'=>true,'unique'=>false,'default'=>null]
     */
    public static function normalizarColumna(array $col): array
    {
        $nombre = trim((string)($col['name'] ?? ''));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $nombre)) {
            throw JsonSqlDbError::schema("Nombre de columna no válido: '$nombre'");
        }
        $tipo = Types::parse((string)($col['type'] ?? 'TEXT'));

        $out = [
            'name'          => $nombre,
            'type'          => $tipo['type'],
            'length'        => $tipo['length'] ?? ($col['length'] ?? null),
            'scale'         => $tipo['scale']  ?? ($col['scale']  ?? null),
            // (el DECIMAL sin escala declarada toma 2 más abajo)
            'notnull'       => !empty($col['notnull']),
            'default'       => $col['default'] ?? null,
            'pk'            => !empty($col['pk']),
            'autoincrement' => !empty($col['autoincrement']),
            'unique'        => !empty($col['unique']),
        ];

        if ($out['type'] === Types::DECIMAL && $out['scale'] === null) {
            $out['scale'] = 2;
        }
        if ($out['autoincrement']) {
            if ($out['type'] !== Types::INTEGER) {
                throw JsonSqlDbError::schema("AUTOINCREMENT solo es válido en columnas INTEGER ('$nombre')");
            }
            $out['notnull'] = true;
        }
        if ($out['pk']) {
            $out['notnull'] = true;
        }
        if ($out['default'] !== null) {
            $out['default'] = Types::cast($out['default'], $out);
        }
        return $out;
    }

    /** Busca una columna por nombre (sin distinguir mayúsculas). */
    public static function columna(array $meta, string $nombre): ?array
    {
        foreach ($meta['columns'] as $col) {
            if (strcasecmp($col['name'], $nombre) === 0) {
                return $col;
            }
        }
        return null;
    }

    /** Nombres de las columnas que forman la clave primaria. */
    public static function clavePrimaria(array $meta): array
    {
        $pk = [];
        foreach ($meta['columns'] as $col) {
            if ($col['pk']) {
                $pk[] = $col['name'];
            }
        }
        return $pk;
    }

    /** Conjuntos de columnas con restricción de unicidad (PK incluida). */
    public static function conjuntosUnicos(array $meta): array
    {
        $out = [];
        $pk  = self::clavePrimaria($meta);
        if ($pk !== []) {
            $out[] = ['name' => 'PRIMARY', 'columns' => $pk];
        }
        foreach ($meta['columns'] as $col) {
            if ($col['unique'] && !$col['pk']) {
                $out[] = ['name' => 'uq_' . $meta['table'] . '_' . $col['name'], 'columns' => [$col['name']]];
            }
        }
        foreach ($meta['unique'] as $uq) {
            $out[] = ['name' => $uq['name'] ?? 'uq', 'columns' => $uq['columns']];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Tablas
    // ------------------------------------------------------------------

    /**
     * Crea una tabla.
     * $def = ['columns'=>[...], 'unique'=>[...], 'foreign_keys'=>[...]]
     */
    public function crearTabla(string $tabla, array $def): void
    {
        Storage::validarTabla($tabla);

        $columnas = array_map([self::class, 'normalizarColumna'], $def['columns'] ?? []);
        if ($columnas === []) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no tiene columnas");
        }
        $vistos = [];
        foreach ($columnas as $col) {
            $clave = strtolower($col['name']);
            if (isset($vistos[$clave])) {
                throw JsonSqlDbError::schema("Columna duplicada '{$col['name']}' en '$tabla'");
            }
            $vistos[$clave] = true;
        }

        $meta = [
            'table'         => $tabla,
            'columns'       => $columnas,
            'unique'        => [],
            'foreign_keys'  => [],
            'triggers'      => [],
            'autoincrement' => null,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        // AUTOINCREMENT: una sola columna por tabla
        foreach ($columnas as $col) {
            if ($col['autoincrement']) {
                if ($meta['autoincrement'] !== null) {
                    throw JsonSqlDbError::schema("Solo se admite un AUTOINCREMENT por tabla ('$tabla')");
                }
                $meta['autoincrement'] = ['column' => $col['name'], 'next' => 1];
            }
        }

        foreach ($def['unique'] ?? [] as $uq) {
            $meta['unique'][] = $this->normalizarUnico($tabla, $meta, $uq);
        }
        foreach ($def['foreign_keys'] ?? [] as $fk) {
            $meta['foreign_keys'][] = $this->normalizarFk($tabla, $meta, $fk);
        }

        $this->st->crearTabla($tabla, self::compactar($meta));
        unset($this->memo[$tabla]);
    }

    public function borrarTabla(string $tabla): void
    {
        if (!$this->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        foreach ($this->st->tablas() as $otra) {
            if ($otra === $tabla) continue;
            foreach ($this->meta($otra)['foreign_keys'] as $fk) {
                if (strcasecmp($fk['table'], $tabla) === 0) {
                    throw JsonSqlDbError::constraint("'$otra' tiene una clave foránea hacia '$tabla'");
                }
            }
        }
        $this->st->borrarTabla($tabla);
        unset($this->memo[$tabla]);
    }

    public function renombrarTabla(string $desde, string $hasta): void
    {
        $this->st->renombrarTabla($desde, $hasta);
        foreach ($this->st->tablas() as $t) {
            $meta    = $this->meta($t);
            $cambia  = false;
            foreach ($meta['foreign_keys'] as $i => $fk) {
                if (strcasecmp($fk['table'], $desde) === 0) {
                    $meta['foreign_keys'][$i]['table'] = $hasta;
                    $cambia = true;
                }
            }
            if ($cambia) {
                $this->guardar($t, $meta);
            }
        }
        unset($this->memo[$desde], $this->memo[$hasta]);
    }

    // ------------------------------------------------------------------
    // ALTER TABLE
    // ------------------------------------------------------------------

    public function anadirColumna(string $tabla, array $spec): void
    {
        $meta = $this->meta($tabla);
        $col  = self::normalizarColumna($spec);

        if (self::columna($meta, $col['name']) !== null) {
            throw JsonSqlDbError::schema("La columna '{$col['name']}' ya existe en '$tabla'");
        }
        if ($col['autoincrement']) {
            throw JsonSqlDbError::schema('No se puede añadir una columna AUTOINCREMENT a una tabla existente');
        }

        $filas = $this->st->leerFilas($tabla);
        if ($col['notnull'] && $col['default'] === null && $filas !== []) {
            throw JsonSqlDbError::schema("La columna '{$col['name']}' es NOT NULL y necesita DEFAULT: la tabla tiene datos");
        }
        foreach ($filas as $i => $fila) {
            $fila[$col['name']] = $col['default'];
            $filas[$i] = $fila;
        }

        $meta['columns'][] = $col;
        $this->st->guardarFilas($tabla, $filas);
        $this->guardar($tabla, $meta);
    }

    /**
     * Cambia el tipo y las restricciones de una columna que ya existe.
     *
     * Convierte los datos que hay al tipo nuevo y comprueba que cumplen lo que
     * se le pide antes de guardar nada: si algún valor no se puede convertir,
     * si queda un NULL en una columna NOT NULL o si hay repetidos en una UNIQUE,
     * la operación falla y la tabla se queda como estaba.
     *
     * No toca la clave primaria ni el autoincremento: eso obliga a recrear la
     * tabla.
     */
    public function modificarColumna(string $tabla, array $spec): void
    {
        $meta   = $this->meta($tabla);
        $nueva  = self::normalizarColumna($spec);
        $actual = self::columna($meta, $nueva['name']);

        if ($actual === null) {
            throw JsonSqlDbError::schema("La columna '{$nueva['name']}' no existe en '$tabla'");
        }
        if ($nueva['autoincrement'] !== $actual['autoincrement']) {
            throw JsonSqlDbError::schema('No se puede añadir ni quitar AUTOINCREMENT: hay que recrear la tabla');
        }
        // La clave primaria se conserva tal cual estaba
        $nueva['pk'] = $actual['pk'];
        if ($actual['pk'] && !$nueva['notnull']) {
            $nueva['notnull'] = true;
        }

        // Conversión de los datos, sobre una copia
        $filas = $this->st->leerFilas($tabla);
        $vistos = [];
        foreach ($filas as $i => $fila) {
            $valor = $fila[$nueva['name']] ?? null;

            if ($valor === null && $nueva['default'] !== null) {
                $valor = $nueva['default'];
            }
            if ($valor === null) {
                if ($nueva['notnull']) {
                    throw JsonSqlDbError::constraint(
                        "'$tabla.{$nueva['name']}' tiene valores nulos: no puede ser NOT NULL sin un DEFAULT"
                    );
                }
            } else {
                try {
                    $valor = Types::cast($valor, $nueva);
                } catch (JsonSqlDbError $e) {
                    throw JsonSqlDbError::type(
                        "El valor " . json_encode($fila[$nueva['name']] ?? null, JSON_UNESCAPED_UNICODE)
                        . " de '$tabla.{$nueva['name']}' no se puede convertir a {$nueva['type']}"
                    );
                }
                if ($nueva['unique'] || $actual['pk']) {
                    $clave = is_string($valor) ? 's:' . $valor : 'n:' . $valor;
                    if (isset($vistos[$clave])) {
                        throw JsonSqlDbError::constraint(
                            "'$tabla.{$nueva['name']}' tiene valores repetidos: no puede ser UNIQUE"
                        );
                    }
                    $vistos[$clave] = true;
                }
            }

            $fila[$nueva['name']] = $valor;
            $filas[$i] = $fila;
        }

        foreach ($meta['columns'] as $i => $c) {
            if (strcasecmp($c['name'], $nueva['name']) === 0) {
                $meta['columns'][$i] = $nueva;
            }
        }

        $this->st->guardarFilas($tabla, $filas);
        $this->guardar($tabla, $meta);
    }

    public function borrarColumna(string $tabla, string $nombre): void
    {
        $meta = $this->meta($tabla);
        $col  = self::columna($meta, $nombre);
        if ($col === null) {
            throw JsonSqlDbError::schema("La columna '$nombre' no existe en '$tabla'");
        }
        if (count($meta['columns']) === 1) {
            throw JsonSqlDbError::schema("No se puede borrar la única columna de '$tabla'");
        }
        $this->exigirColumnaLibre($tabla, $meta, $col['name']);

        $meta['columns'] = array_values(array_filter(
            $meta['columns'],
            static fn(array $c): bool => strcasecmp($c['name'], $col['name']) !== 0
        ));

        // El autoincremento se va con su columna
        if (($meta['autoincrement']['column'] ?? null) !== null
            && strcasecmp((string)$meta['autoincrement']['column'], $col['name']) === 0) {
            $meta['autoincrement'] = null;
        }

        $filas = $this->st->leerFilas($tabla);
        foreach ($filas as $i => $fila) {
            unset($fila[$col['name']]);
            $filas[$i] = $fila;
        }

        // Si la columna formaba parte de una clave primaria compuesta, la que
        // queda tiene que seguir siendo única
        if ($col['pk']) {
            $resto = self::clavePrimaria($meta);
            if ($resto !== []) {
                $vistos = [];
                foreach ($filas as $fila) {
                    $partes = [];
                    foreach ($resto as $n) {
                        $v = $fila[$n] ?? null;
                        $partes[] = is_string($v) ? 's:' . $v : 'n:' . $v;
                    }
                    $clave = implode('|', $partes);
                    if (isset($vistos[$clave])) {
                        throw JsonSqlDbError::constraint(
                            "Al quitar '{$col['name']}' la clave primaria (" . implode(', ', $resto)
                            . ") quedaría repetida en '$tabla'"
                        );
                    }
                    $vistos[$clave] = true;
                }
            }
        }

        $this->st->guardarFilas($tabla, $filas);
        $this->guardar($tabla, $meta);
    }

    public function renombrarColumna(string $tabla, string $desde, string $hasta): void
    {
        $meta = $this->meta($tabla);
        $col  = self::columna($meta, $desde);
        if ($col === null) {
            throw JsonSqlDbError::schema("La columna '$desde' no existe en '$tabla'");
        }
        if (self::columna($meta, $hasta) !== null) {
            throw JsonSqlDbError::schema("La columna '$hasta' ya existe en '$tabla'");
        }
        $this->exigirColumnaLibre($tabla, $meta, $col['name']);

        foreach ($meta['columns'] as $i => $c) {
            if (strcasecmp($c['name'], $col['name']) === 0) {
                $meta['columns'][$i]['name'] = $hasta;
            }
        }
        if (($meta['autoincrement']['column'] ?? null) !== null
            && strcasecmp($meta['autoincrement']['column'], $col['name']) === 0) {
            $meta['autoincrement']['column'] = $hasta;
        }

        $filas = $this->st->leerFilas($tabla);
        foreach ($filas as $i => $fila) {
            if (array_key_exists($col['name'], $fila)) {
                $fila[$hasta] = $fila[$col['name']];
                unset($fila[$col['name']]);
                $filas[$i] = $fila;
            }
        }

        $this->st->guardarFilas($tabla, $filas);
        $this->guardar($tabla, $meta);
    }

    /** Impide tocar una columna usada por UNIQUE compuesto o por una FK (propia o ajena). */
    private function exigirColumnaLibre(string $tabla, array $meta, string $columna): void
    {
        foreach ($meta['unique'] as $uq) {
            foreach ($uq['columns'] as $c) {
                if (strcasecmp($c, $columna) === 0) {
                    throw JsonSqlDbError::constraint("'$columna' forma parte del UNIQUE '{$uq['name']}'");
                }
            }
        }
        foreach ($meta['foreign_keys'] as $fk) {
            foreach ($fk['columns'] as $c) {
                if (strcasecmp($c, $columna) === 0) {
                    throw JsonSqlDbError::constraint("'$columna' forma parte de la clave foránea '{$fk['name']}'");
                }
            }
        }
        foreach ($meta['triggers'] as $trg) {
            $sql = (string)($trg['sql'] ?? '');
            if ($sql !== '' && preg_match('/\\b' . preg_quote($columna, '/') . '\\b/i', $sql) === 1) {
                throw JsonSqlDbError::constraint(
                    "'$columna' se usa en el trigger '{$trg['name']}': bórralo antes"
                );
            }
        }
        foreach ($this->st->tablas() as $otra) {
            if ($otra === $tabla) continue;
            foreach ($this->meta($otra)['foreign_keys'] as $fk) {
                if (strcasecmp($fk['table'], $tabla) !== 0) continue;
                foreach ($fk['references'] as $c) {
                    if (strcasecmp($c, $columna) === 0) {
                        throw JsonSqlDbError::constraint("'$otra.{$fk['name']}' referencia '$tabla.$columna'");
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Restricciones
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Restricciones sobre una tabla ya creada
    // ------------------------------------------------------------------

    /**
     * Añade una clave única. Comprueba antes que los datos actuales la cumplen.
     * Devuelve el nombre de la restricción.
     */
    public function anadirUnico(string $tabla, array $uq): string
    {
        $meta = $this->metaExistente($tabla);
        $nuevo = $this->normalizarUnico($tabla, $meta, $uq);
        $this->exigirNombreLibre($meta, $nuevo['name']);

        $vistos = [];
        foreach ($this->st->leerFilas($tabla) as $fila) {
            $clave = self::claveDeFila($fila, $nuevo['columns']);
            if ($clave === null) {
                continue;                                   // con NULL no aplica la unicidad
            }
            if (isset($vistos[$clave])) {
                throw JsonSqlDbError::constraint(
                    "Los datos de '$tabla' repiten el valor " . $clave . " en (" . implode(', ', $nuevo['columns']) . ')'
                );
            }
            $vistos[$clave] = true;
        }

        $meta['unique'][] = $nuevo;
        $this->guardar($tabla, $meta);
        return $nuevo['name'];
    }

    /**
     * Añade una clave foránea. Comprueba antes que las filas actuales apuntan
     * a filas existentes de la tabla destino. Devuelve el nombre.
     */
    public function anadirFk(string $tabla, array $fk): string
    {
        $meta  = $this->metaExistente($tabla);
        $nuevo = $this->normalizarFk($tabla, $meta, $fk);
        $this->exigirNombreLibre($meta, $nuevo['name']);

        $padres = [];
        foreach ($this->st->leerFilas($nuevo['table']) as $fila) {
            $clave = self::claveDeFila($fila, $nuevo['references']);
            if ($clave !== null) { $padres[$clave] = true; }
        }
        foreach ($this->st->leerFilas($tabla) as $fila) {
            $clave = self::claveDeFila($fila, $nuevo['columns']);
            if ($clave !== null && !isset($padres[$clave])) {
                throw JsonSqlDbError::constraint(
                    "Los datos de '$tabla' apuntan a " . $clave . " y no existe en '{$nuevo['table']}'"
                );
            }
        }

        $meta['foreign_keys'][] = $nuevo;
        $this->guardar($tabla, $meta);
        return $nuevo['name'];
    }

    /** Borra una clave única o foránea por su nombre. */
    public function borrarRestriccion(string $tabla, string $nombre): void
    {
        $meta = $this->metaExistente($tabla);

        foreach ($meta['unique'] as $i => $uq) {
            if (strcasecmp((string)($uq['name'] ?? ''), $nombre) === 0) {
                array_splice($meta['unique'], $i, 1);
                $this->guardar($tabla, $meta);
                return;
            }
        }
        foreach ($meta['foreign_keys'] as $i => $fk) {
            if (strcasecmp((string)($fk['name'] ?? ''), $nombre) === 0) {
                array_splice($meta['foreign_keys'], $i, 1);
                $this->guardar($tabla, $meta);
                return;
            }
        }
        throw JsonSqlDbError::schema("La tabla '$tabla' no tiene la restricción '$nombre'");
    }

    /**
     * Crea la clave primaria de una tabla que todavía no la tiene.
     *
     * Comprueba antes que los datos la aguantan: sin nulos y sin combinaciones
     * repetidas. Si falla, la tabla se queda como estaba.
     *
     * Cambiar una clave primaria que ya existe obliga a recrear la tabla: hay
     * que borrar la anterior primero.
     *
     * @param string[] $columnas
     */
    public function anadirClavePrimaria(string $tabla, array $columnas): void
    {
        $meta = $this->metaExistente($tabla);

        if (self::clavePrimaria($meta) !== []) {
            throw JsonSqlDbError::schema(
                "La tabla '$tabla' ya tiene clave primaria. Bórrala antes con "
                . "ALTER TABLE \"$tabla\" DROP PRIMARY KEY"
            );
        }
        if ($columnas === []) {
            throw JsonSqlDbError::schema('La clave primaria necesita al menos una columna');
        }

        $nombres = [];
        foreach ($columnas as $c) {
            $col = self::columna($meta, $c);
            if ($col === null) {
                throw JsonSqlDbError::schema("La columna '$c' no existe en '$tabla'");
            }
            if (isset($nombres[strtolower($col['name'])])) {
                throw JsonSqlDbError::schema("La columna '{$col['name']}' está repetida en la clave primaria");
            }
            $nombres[strtolower($col['name'])] = $col['name'];
        }
        $nombres = array_values($nombres);

        $vistos = [];
        foreach ($this->st->leerFilas($tabla) as $fila) {
            $partes = [];
            foreach ($nombres as $n) {
                $v = $fila[$n] ?? null;
                if ($v === null) {
                    throw JsonSqlDbError::constraint(
                        "'$tabla.$n' tiene valores nulos: no puede formar parte de la clave primaria"
                    );
                }
                $partes[] = is_string($v) ? 's:' . $v : 'n:' . $v;
            }
            $clave = implode('|', $partes);
            if (isset($vistos[$clave])) {
                throw JsonSqlDbError::constraint(
                    "Los datos de '$tabla' repiten el valor $clave en (" . implode(', ', $nombres) . ')'
                );
            }
            $vistos[$clave] = true;
        }

        foreach ($meta['columns'] as $i => $c) {
            if (in_array($c['name'], $nombres, true)) {
                $meta['columns'][$i]['pk']      = true;
                $meta['columns'][$i]['notnull'] = true;
            }
        }
        $this->guardar($tabla, $meta);
    }

    /** Quita la clave primaria. Las columnas siguen siendo NOT NULL. */
    public function borrarClavePrimaria(string $tabla): void
    {
        $meta = $this->metaExistente($tabla);

        if (self::clavePrimaria($meta) === []) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no tiene clave primaria");
        }
        if (self::columnaAutoincremento($meta) !== null) {
            throw JsonSqlDbError::schema(
                "La clave primaria de '$tabla' es AUTOINCREMENT: para quitarla hay que recrear la tabla"
            );
        }

        foreach ($meta['columns'] as $i => $c) {
            if ($c['pk']) {
                $meta['columns'][$i]['pk'] = false;
            }
        }
        $this->guardar($tabla, $meta);
    }

    private function metaExistente(string $tabla): array
    {
        if (!$this->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        return $this->meta($tabla);
    }

    private function exigirNombreLibre(array $meta, string $nombre): void
    {
        foreach (array_merge($meta['unique'], $meta['foreign_keys']) as $r) {
            if (strcasecmp((string)($r['name'] ?? ''), $nombre) === 0) {
                throw JsonSqlDbError::schema("Ya existe una restricción llamada '$nombre' en '{$meta['table']}'");
            }
        }
    }

    /** Clave comparable de una fila, o null si alguna columna es NULL. */
    private static function claveDeFila(array $fila, array $columnas): ?string
    {
        $partes = [];
        foreach ($columnas as $c) {
            $v = $fila[$c] ?? null;
            if ($v === null) { return null; }
            $partes[] = is_string($v) ? 's:' . $v : 'n:' . $v;
        }
        return implode('|', $partes);
    }

    private function normalizarUnico(string $tabla, array $meta, array $uq): array
    {
        $cols = [];
        foreach ($uq['columns'] ?? [] as $c) {
            $col = self::columna($meta, (string)$c);
            if ($col === null) {
                throw JsonSqlDbError::schema("UNIQUE sobre columna inexistente '$c' en '$tabla'");
            }
            $cols[] = $col['name'];
        }
        if ($cols === []) {
            throw JsonSqlDbError::schema("UNIQUE sin columnas en '$tabla'");
        }
        return [
            'name'    => (string)($uq['name'] ?? ('uq_' . $tabla . '_' . implode('_', $cols))),
            'columns' => $cols,
        ];
    }

    private function normalizarFk(string $tabla, array $meta, array $fk): array
    {
        $cols = [];
        foreach ($fk['columns'] ?? [] as $c) {
            $col = self::columna($meta, (string)$c);
            if ($col === null) {
                throw JsonSqlDbError::schema("FOREIGN KEY sobre columna inexistente '$c' en '$tabla'");
            }
            $cols[] = $col['name'];
        }
        $destino = (string)($fk['table'] ?? '');
        Storage::validarTabla($destino);

        // Autorreferencia: la tabla aún no está en disco al crearla
        $metaDestino = strcasecmp($destino, $tabla) === 0 ? $meta : null;
        if ($metaDestino === null) {
            if (!$this->existe($destino)) {
                throw JsonSqlDbError::schema("FOREIGN KEY hacia una tabla inexistente: '$destino'");
            }
            $metaDestino = $this->meta($destino);
        }

        $refs = [];
        foreach ($fk['references'] ?? [] as $c) {
            $col = self::columna($metaDestino, (string)$c);
            if ($col === null) {
                throw JsonSqlDbError::schema("FOREIGN KEY hacia columna inexistente '$destino.$c'");
            }
            $refs[] = $col['name'];
        }
        if ($refs === [] || count($refs) !== count($cols)) {
            throw JsonSqlDbError::schema("FOREIGN KEY con número de columnas distinto en '$tabla'");
        }

        $onDelete = strtoupper(trim((string)($fk['on_delete'] ?? 'NO ACTION')));
        $onUpdate = strtoupper(trim((string)($fk['on_update'] ?? 'NO ACTION')));
        foreach ([$onDelete, $onUpdate] as $accion) {
            if (!in_array($accion, self::ACCIONES_FK, true)) {
                throw JsonSqlDbError::schema("Acción de clave foránea no soportada: '$accion'");
            }
        }

        return [
            'name'       => (string)($fk['name'] ?? ('fk_' . $tabla . '_' . implode('_', $cols))),
            'columns'    => $cols,
            'table'      => $metaDestino['table'],
            'references' => $refs,
            'on_delete'  => $onDelete,
            'on_update'  => $onUpdate,
        ];
    }

    // ------------------------------------------------------------------
    // Triggers
    // ------------------------------------------------------------------

    public function crearTrigger(string $tabla, array $trg): void
    {
        $meta   = $this->meta($tabla);
        $nombre = trim((string)($trg['name'] ?? ''));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $nombre)) {
            throw JsonSqlDbError::schema("Nombre de trigger no válido: '$nombre'");
        }
        foreach ($meta['triggers'] as $t) {
            if (strcasecmp($t['name'], $nombre) === 0) {
                throw JsonSqlDbError::schema("El trigger '$nombre' ya existe");
            }
        }
        $timing = strtoupper(trim((string)($trg['timing'] ?? 'AFTER')));
        $evento = strtoupper(trim((string)($trg['event'] ?? '')));
        if (!in_array($timing, self::TIMINGS, true)) {
            throw JsonSqlDbError::schema("Momento de trigger no soportado: '$timing'");
        }
        if (!in_array($evento, self::EVENTOS, true)) {
            throw JsonSqlDbError::schema("Evento de trigger no soportado: '$evento'");
        }
        $cuerpo = array_values(array_filter(array_map('trim', (array)($trg['body'] ?? []))));
        if ($cuerpo === []) {
            throw JsonSqlDbError::schema("El trigger '$nombre' no tiene cuerpo");
        }

        $meta['triggers'][] = [
            'name'   => $nombre,
            'timing' => $timing,
            'event'  => $evento,
            'when'   => isset($trg['when']) && trim((string)$trg['when']) !== '' ? trim((string)$trg['when']) : null,
            'body'   => $cuerpo,
            'sql'    => (string)($trg['sql'] ?? ''),
        ];
        $this->guardar($tabla, $meta);
    }

    /** Borra un trigger por nombre. Devuelve la tabla a la que pertenecía. */
    public function borrarTrigger(string $nombre): string
    {
        foreach ($this->st->tablas() as $tabla) {
            $meta = $this->meta($tabla);
            foreach ($meta['triggers'] as $i => $t) {
                if (strcasecmp($t['name'], $nombre) === 0) {
                    unset($meta['triggers'][$i]);
                    $meta['triggers'] = array_values($meta['triggers']);
                    $this->guardar($tabla, $meta);
                    return $tabla;
                }
            }
        }
        throw JsonSqlDbError::schema("El trigger '$nombre' no existe");
    }

    /** Triggers aplicables a un evento concreto, en orden de definición. */
    public function triggers(string $tabla, string $timing, string $evento): array
    {
        $out = [];
        foreach ($this->meta($tabla)['triggers'] as $t) {
            if ($t['timing'] === $timing && $t['event'] === $evento) {
                $out[] = $t;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Autoincremento
    // ------------------------------------------------------------------

    /** Reserva y devuelve el siguiente valor de autoincremento de la tabla. */
    public function siguienteAutoincremento(string $tabla): int
    {
        $meta = $this->meta($tabla);
        if ($meta['autoincrement'] === null) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no tiene columna AUTOINCREMENT");
        }
        $valor = (int)$meta['autoincrement']['next'];
        $meta['autoincrement']['next'] = $valor + 1;
        $this->guardar($tabla, $meta);
        return $valor;
    }

    /** Ajusta el contador cuando se inserta un valor explícito mayor que el actual. */
    public function ajustarAutoincremento(string $tabla, int $valor): void
    {
        $meta = $this->meta($tabla);
        if ($meta['autoincrement'] === null || $valor < (int)$meta['autoincrement']['next']) {
            return;
        }
        $meta['autoincrement']['next'] = $valor + 1;
        $this->guardar($tabla, $meta);
    }

    /** Columna de autoincremento de la tabla, o null. */
    public static function columnaAutoincremento(array $meta): ?string
    {
        return $meta['autoincrement']['column'] ?? null;
    }

    // ------------------------------------------------------------------
    // Serialización compacta (ficheros .meta.json legibles)
    // ------------------------------------------------------------------

    public static function compactar(array $meta): array
    {
        $cols = [];
        foreach ($meta['columns'] as $col) {
            $c = ['name' => $col['name'], 'type' => $col['type']];
            foreach (['length', 'scale', 'default'] as $k) {
                if (($col[$k] ?? null) !== null) {
                    $c[$k] = $col[$k];
                }
            }
            foreach (['notnull', 'pk', 'autoincrement', 'unique'] as $k) {
                if (!empty($col[$k])) {
                    $c[$k] = true;
                }
            }
            $cols[] = $c;
        }

        $out = ['table' => $meta['table'], 'columns' => $cols];
        foreach (['unique', 'foreign_keys', 'triggers'] as $k) {
            if (!empty($meta[$k])) {
                $out[$k] = $meta[$k];
            }
        }
        if (!empty($meta['autoincrement'])) {
            $out['autoincrement'] = $meta['autoincrement'];
        }
        if (!empty($meta['created_at'])) {
            $out['created_at'] = $meta['created_at'];
        }
        return $out;
    }
}
