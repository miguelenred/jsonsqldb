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
 *
 * Aquí, lo que necesitan las consultas; los cambios de estructura, en
 * CatalogDdl, que solo se carga cuando hace falta (2.9).
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Catalog
{
    private Storage $st;
    /** @var array<string,array> caché de metas normalizadas dentro de la misma petición */
    private array $memo = [];
    /** @var array<string, array{0: string, 1: array}> base y tabla => [huella, meta normalizada], la última del proceso (ver meta()) */
    private static array $normalizadas = [];

    public function __construct(Storage $storage)
    {
        $this->st = $storage;
    }

    public function storage(): Storage { return $this->st; }

    /**
     * Tope de tablas que se bloquean una a una. Pasado ese número, pedir tantos
     * bloqueos cuesta más que el exclusivo de la base.
     */
    private const MAX_TABLAS_BLOQUEADAS = 8;

    /** @var list<string>|null lista de tablas de esta petición */
    private ?array $memoTablas = null;

    /**
     * Nombres de las tablas de la base.
     *
     * Memoizado porque propagarHijos() lo pregunta UNA VEZ POR FILA afectada
     * («¿alguien me referencia?»), y detrás hay un glob del directorio. En un
     * UPDATE de 8.000 filas eso eran 8.000 listados de carpeta. La lista solo
     * cambia con una operación de estructura, y esas llaman a olvidar().
     */
    public function tablas(): array
    {
        return $this->memoTablas ??= $this->st->tablas();
    }

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
    public function cierreDeTablas(string $tabla): ?array
    {
        if (!$this->existe($tabla)) {
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
            if (!$this->existe($actual)) {
                return null;                       // referencia a algo que no está
            }
            $vistas[$clave] = $actual;

            // Un conjunto enorme no compensa: sale más barato el exclusivo de
            // la base que pedir cincuenta bloqueos uno a uno
            if (count($vistas) > self::MAX_TABLAS_BLOQUEADAS) {
                return null;
            }

            $meta = $this->meta($actual);

            // Hacia arriba: las tablas a las que esta apunta
            foreach ($meta['foreign_keys'] as $fk) {
                $pendiente[] = (string)$fk['table'];
            }
            // Hacia abajo: las que apuntan a esta
            foreach ($this->tablas() as $otra) {
                foreach ($this->meta($otra)['foreign_keys'] as $fk) {
                    if (strcasecmp((string)$fk['table'], $actual) === 0) {
                        $pendiente[] = $otra;
                    }
                }
            }
            // Y donde escriban sus triggers
            foreach ($meta['triggers'] as $trg) {
                foreach (self::sentenciasDe((array)($trg['body'] ?? [])) as $sql) {
                    $destino = self::tablaDeLaSentencia($sql);
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
            if (!is_array($paso)) {
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
    private static function tablaDeLaSentencia(string $sql): ?string
    {
        // SET NEW.col = … solo cambia la fila que se está escribiendo
        if (preg_match('/^\s*SET\s+NEW\s*\./i', $sql)) {
            return '';
        }
        try {
            $ast = Parser::analizar($sql);
        } catch (\Throwable) {
            return null;
        }
        // Lo que lee de otras tablas también habría que bloquearlo: un SELECT
        // con FROM o con subconsultas, un INSERT … SELECT, y desde la 2.9 las
        // subconsultas, UPDATE … FROM y DELETE … USING (ver
        // Database::leeOtras()), que se leían sin bloqueo
        if (in_array($ast['k'], ['select', 'union'], true)) {
            return $ast['k'] === 'select' && ($ast['from'] ?? []) === [] && empty($ast['subconsultas']) ? '' : null;
        }
        if (!in_array($ast['k'], ['insert', 'update', 'delete'], true) || Database::leeOtras($ast)) {
            return null;                           // cualquier otra cosa, a la base
        }
        $tabla = $ast['tabla'] ?? null;
        return is_string($tabla) && $tabla !== '' ? $tabla : null;
    }

    public function existe(string $tabla): bool { return $this->st->existe($tabla); }

    // ------------------------------------------------------------------
    // Vistas
    // ------------------------------------------------------------------

    /** @return array<string,array> nombre => ['sql' => ..., 'created_at' => ...] */
    public function vistas(): array
    {
        return $this->st->leerVistas();
    }

    public function esVista(string $nombre): bool
    {
        return $this->vista($nombre) !== null;
    }

    /** Definición de una vista, o null si no existe. */
    public function vista(string $nombre): ?array
    {
        foreach ($this->vistas() as $n => $v) {
            if (strcasecmp((string)$n, $nombre) === 0) {
                return ['name' => (string)$n] + (array)$v;
            }
        }
        return null;
    }

    /** Estructura normalizada de una tabla. */
    public function meta(string $tabla): array
    {
        if (isset($this->memo[$tabla])) {
            return $this->memo[$tabla];
        }
        $meta = $this->st->leerMeta($tabla, $huella);
        // La misma estructura que la última vez en este proceso: ya está
        // normalizada (2.9). Normalizarla en cada consulta era el 6 % de una
        // búsqueda por clave
        $ultima = self::$normalizadas[$this->st->dir() . "\0" . $tabla] ?? null;
        if ($huella !== null && $ultima !== null && $ultima[0] === $huella) {
            return $this->memo[$tabla] = $ultima[1];
        }

        $meta['table']        = $meta['table'] ?? $tabla;
        $meta['columns']      = array_map([self::class, 'normalizarColumna'], $meta['columns'] ?? []);
        $meta['unique']       = $meta['unique']       ?? [];
        $meta['foreign_keys'] = $meta['foreign_keys'] ?? [];
        $meta['triggers']     = $meta['triggers']     ?? [];
        $meta['indexes']      = $meta['indexes']      ?? [];
        $meta['checks']       = $meta['checks']       ?? [];
        $meta['autoincrement'] = $meta['autoincrement'] ?? null;

        // Un índice sobre una columna que ya no existe es basura de un ALTER
        // anterior: se ignora en vez de romper cada consulta de la tabla.
        $meta['indexes'] = array_values(array_filter(
            $meta['indexes'],
            static function ($idx) use ($meta): bool {
                foreach ((array)($idx['columns'] ?? []) as $c) {
                    if (self::columna($meta, (string)$c) === null) {
                        return false;
                    }
                }
                return ($idx['columns'] ?? []) !== [];
            }
        ));

        // Un autoincremento que apunte a una columna inexistente es basura de
        // una versión anterior: se ignora en lugar de arrastrar el error.
        $auto = $meta['autoincrement']['column'] ?? null;
        if ($auto !== null && self::columna($meta, (string)$auto) === null) {
            $meta['autoincrement'] = null;
        }

        if ($huella !== null) {
            if (count(self::$normalizadas) >= 32) {
                self::$normalizadas = [];             // las de las tablas que se están usando
            }
            self::$normalizadas[$this->st->dir() . "\0" . $tabla] = [$huella, $meta];
        }
        return $this->memo[$tabla] = $meta;
    }

    /** Olvida lo memorizado (tras un rollback o un cambio externo). */
    public function olvidar(?string $tabla = null): void
    {
        if ($tabla === null) {
            $this->memo       = [];
            $this->memoTablas = null;      // pudo crearse o borrarse una tabla
        } else {
            unset($this->memo[$tabla]);    // la lista de nombres no cambia
        }
    }

    // ------------------------------------------------------------------
    // Columnas
    // ------------------------------------------------------------------

    /**
     * Un nombre de columna: de 1 a 64 caracteres, sin caracteres de control.
     * Como en SQLite, puede llevar espacios o signos (`CREATE TABLE t AS
     * SELECT id + 1 …` da una columna «id + 1»); entonces se escribe entre
     * comillas dobles. Nunca forma parte de un nombre de fichero.
     */
    public static function exigirNombreColumna(string $nombre): void
    {
        if (!preg_match('/^(?=.*\S)[^\x00-\x1f\x7f]{1,64}$/u', $nombre)) {
            throw JsonSqlDbError::schema("Nombre de columna no válido: '" . (preg_match('//u', $nombre) ? $nombre : '?') . "'");
        }
    }

    /**
     * Normaliza la definición de una columna.
     * Entrada: ['name'=>'id','type'=>'INTEGER','pk'=>true,'autoincrement'=>true,
     *           'notnull'=>true,'unique'=>false,'default'=>null]
     */
    public static function normalizarColumna(array $col): array
    {
        $nombre = trim((string)($col['name'] ?? ''));
        self::exigirNombreColumna($nombre);
        $tipo = Types::parse((string)($col['type'] ?? 'TEXT'));

        $out = [
            'name'          => $nombre,
            'type'          => $tipo['type'],
            'length'        => $tipo['length'] ?? ($col['length'] ?? null),
            'scale'         => $tipo['scale']  ?? ($col['scale']  ?? null),
            // (el DECIMAL sin escala declarada toma 2 más abajo)
            'notnull'       => !empty($col['notnull']),
            'default'       => $col['default'] ?? null,
            // Un DEFAULT que se calcula al insertar (2.8): CURRENT_TIMESTAMP o (expresión)
            'default_expr'  => isset($col['default_expr']) && $col['default_expr'] !== '' ? (string)$col['default_expr'] : null,
            'pk'            => !empty($col['pk']),
            'autoincrement' => !empty($col['autoincrement']),
            'unique'        => !empty($col['unique']),
        ];
        // UNSIGNED de MySQL (2.9): solo se guarda si se declara, para que las
        // estructuras de antes no cambien
        if (!empty($col['unsigned'])) {
            if (!in_array($tipo['type'], [Types::INTEGER, Types::DOUBLE, Types::DECIMAL], true)) {
                throw JsonSqlDbError::schema("UNSIGNED solo es válido en columnas numéricas ('$nombre')");
            }
            $out['unsigned'] = true;
        }

        if ($out['type'] === Types::DECIMAL && $out['scale'] === null) {
            $out['scale'] = 2;
        }
        // COLLATE NOCASE o RTRIM (2.9); BINARY es lo de siempre y no se guarda
        if (isset($col['collate']) && strtoupper((string)$col['collate']) !== 'BINARY') {
            $c = strtoupper((string)$col['collate']);
            if (!in_array($c, ['NOCASE', 'RTRIM'], true)) {
                throw JsonSqlDbError::schema("COLLATE $c no está soportado: NOCASE, RTRIM o BINARY ('$nombre')");
            }
            if ($out['type'] !== Types::TEXT) {
                throw JsonSqlDbError::schema("COLLATE solo vale en columnas de texto ('$nombre')");
            }
            $out['collate'] = $c;
        }
        // ENUM de MySQL (2.9): un texto con su lista de valores; con NOT NULL y
        // sin DEFAULT, el primero, como allí
        if (isset($col['enum'])) {
            $valores = array_values(array_map('strval', (array)$col['enum']));
            if ($valores === [] || $out['type'] !== Types::TEXT) {
                throw JsonSqlDbError::schema("ENUM necesita al menos un valor ('$nombre')");
            }
            $out['enum'] = $valores;
            if ($out['notnull'] && $out['default'] === null && $out['default_expr'] === null) {
                $out['default'] = $valores[0];
            }
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
        if ($out['default_expr'] !== null) {
            $out['default'] = null;
            Parser::analizar('SELECT ' . $out['default_expr']);   // que se pueda calcular
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
        // Las colaciones, en la misma vuelta que las UNIQUE de columna: esto
        // se llama en cada fila que se escribe
        $por     = [];
        $sueltas = [];
        foreach ($meta['columns'] as $col) {
            if (isset($col['collate'])) {
                $por[strtolower((string)$col['name'])] = $col['collate'];
            }
            if ($col['unique'] && !$col['pk']) {
                $sueltas[] = $col['name'];
            }
        }
        $out = [];
        $pk  = self::clavePrimaria($meta);
        if ($pk !== []) {
            $out[] = ['name' => 'PRIMARY', 'columns' => $pk, 'plegar' => self::plegarCon($por, $pk)];
        }
        foreach ($sueltas as $c) {
            $out[] = ['name' => 'uq_' . $meta['table'] . '_' . $c, 'columns' => [$c], 'plegar' => self::plegarCon($por, [$c])];
        }
        foreach ($meta['unique'] as $uq) {
            $out[] = ['name' => $uq['name'] ?? 'uq', 'columns' => $uq['columns'], 'plegar' => self::plegarCon($por, $uq['columns'])];
        }
        return $out;
    }

    /**
     * La colación de cada una de esas columnas (NOCASE o RTRIM; null la
     * binaria), por posición; [] si ninguna la tiene (2.9). Es con lo que se
     * calculan sus claves: de un índice, de un UNIQUE, y de una clave foránea
     * que apunta a ellas.
     *
     * @param list<string> $columnas
     * @return array<int, ?string>
     */
    public static function plegarDe(array $meta, array $columnas): array
    {
        return self::plegarCon(self::colaciones($meta), $columnas);
    }

    /**
     * Como plegarDe(), con las colaciones de la tabla ya sacadas (ver colaciones()).
     *
     * @param array<string, string> $por
     * @param list<string>          $columnas
     * @return array<int, ?string>
     */
    public static function plegarCon(array $por, array $columnas): array
    {
        if ($por === []) {
            return [];
        }
        $out = [];
        foreach (array_values($columnas) as $i => $c) {
            $out[$i] = $por[strtolower((string)$c)] ?? null;
        }
        return array_filter($out) === [] ? [] : $out;
    }

    /** @return array<string, string> las columnas (en minúsculas) de una tabla con COLLATE, y la suya (2.9) */
    public static function colaciones(array $meta): array
    {
        $out = [];
        foreach ($meta['columns'] as $c) {
            if (isset($c['collate'])) {
                $out[strtolower((string)$c['name'])] = $c['collate'];
            }
        }
        return $out;
    }

    /** Nombre de columna, solo o con la tabla delante, en minúsculas => su nombre (para Evaluator::resolver()) */
    public static function mapaColumnas(string $tabla, array $columnas): array
    {
        $mapa = [];
        foreach ($columnas as $c) {
            $mapa[strtolower($c['name'])] = $c['name'];
            $mapa[strtolower($tabla . '.' . $c['name'])] = $c['name'];
            if (isset($c['collate'])) {
                $mapa["\0c:" . $c['name']] = $c['collate'];    // su colación (ver Evaluator::resolver())
            }
        }
        return $mapa;
    }

    // ------------------------------------------------------------------
    // Índices
    // ------------------------------------------------------------------

    /**
     * Índices efectivos de una tabla, los creados a mano y los automáticos.
     *
     * @return list<array{name: string, columns: list<string>, auto: bool}>
     */
    public function indicesDe(string $tabla): array
    {
        return Indexes::definiciones($this->meta($tabla));
    }

    // ------------------------------------------------------------------
    // Autoincremento
    // ------------------------------------------------------------------

    /** Columna de autoincremento de la tabla, o null. */
    public static function columnaAutoincremento(array $meta): ?string
    {
        return $meta['autoincrement']['column'] ?? null;
    }

    // ------------------------------------------------------------------
    // Cambios de estructura: en CatalogDdl, que solo se carga para ellos
    // ------------------------------------------------------------------

    /** Ver CatalogDdl::exigirNombreLibreDeVista() */
    public function exigirNombreLibreDeVista(string $nombre): void
    {
        CatalogDdl::exigirNombreLibreDeVista($this, $nombre);
    }

    /** Ver CatalogDdl::crearVista() */
    public function crearVista(string $nombre, string $sql, bool $siNoExiste = false): bool
    {
        return CatalogDdl::crearVista($this, $nombre, $sql, $siNoExiste);
    }

    /** Ver CatalogDdl::borrarVista() */
    public function borrarVista(string $nombre, bool $siExiste = false): bool
    {
        return CatalogDdl::borrarVista($this, $nombre, $siExiste);
    }

    /** Ver CatalogDdl::crearTabla() */
    public function crearTabla(string $tabla, array $def): void
    {
        CatalogDdl::crearTabla($this, $tabla, $def);
    }

    /** Ver CatalogDdl::borrarTabla() */
    public function borrarTabla(string $tabla): void
    {
        CatalogDdl::borrarTabla($this, $tabla);
    }

    /** Ver CatalogDdl::renombrarTabla() */
    public function renombrarTabla(string $desde, string $hasta): void
    {
        CatalogDdl::renombrarTabla($this, $desde, $hasta);
    }

    /** Ver CatalogDdl::anadirColumna() */
    public function anadirColumna(string $tabla, array $spec): void
    {
        CatalogDdl::anadirColumna($this, $tabla, $spec);
    }

    /** Ver CatalogDdl::modificarColumna() */
    public function modificarColumna(string $tabla, array $spec, ?string $desde = null): void
    {
        CatalogDdl::modificarColumna($this, $tabla, $spec, $desde);
    }

    /** Ver CatalogDdl::borrarColumna() */
    public function borrarColumna(string $tabla, string $nombre): void
    {
        CatalogDdl::borrarColumna($this, $tabla, $nombre);
    }

    /** Ver CatalogDdl::renombrarColumna() */
    public function renombrarColumna(string $tabla, string $desde, string $hasta): void
    {
        CatalogDdl::renombrarColumna($this, $tabla, $desde, $hasta);
    }

    /** Ver CatalogDdl::anadirUnico() */
    public function anadirUnico(string $tabla, array $uq): string
    {
        return CatalogDdl::anadirUnico($this, $tabla, $uq);
    }

    /** Ver CatalogDdl::anadirCheck() */
    public function anadirCheck(string $tabla, array $ck): string
    {
        return CatalogDdl::anadirCheck($this, $tabla, $ck);
    }

    /** Ver CatalogDdl::anadirFk() */
    public function anadirFk(string $tabla, array $fk): string
    {
        return CatalogDdl::anadirFk($this, $tabla, $fk);
    }

    /** Ver CatalogDdl::crearIndice() */
    public function crearIndice(string $tabla, string $nombre, array $columnas, bool $siNoExiste = false): bool
    {
        return CatalogDdl::crearIndice($this, $tabla, $nombre, $columnas, $siNoExiste);
    }

    /** Ver CatalogDdl::borrarIndice() */
    public function borrarIndice(?string $tabla, string $nombre, bool $siExiste = false): ?string
    {
        return CatalogDdl::borrarIndice($this, $tabla, $nombre, $siExiste);
    }

    /** Ver CatalogDdl::borrarRestriccion() */
    public function borrarRestriccion(string $tabla, string $nombre): void
    {
        CatalogDdl::borrarRestriccion($this, $tabla, $nombre);
    }

    /** Ver CatalogDdl::anadirClavePrimaria() */
    public function anadirClavePrimaria(string $tabla, array $columnas): void
    {
        CatalogDdl::anadirClavePrimaria($this, $tabla, $columnas);
    }

    /** Ver CatalogDdl::borrarClavePrimaria() */
    public function borrarClavePrimaria(string $tabla): void
    {
        CatalogDdl::borrarClavePrimaria($this, $tabla);
    }

    /** Ver CatalogDdl::crearTrigger() */
    public function crearTrigger(string $tabla, array $trg): void
    {
        CatalogDdl::crearTrigger($this, $tabla, $trg);
    }

    /** Ver CatalogDdl::borrarTrigger() */
    public function borrarTrigger(string $nombre): string
    {
        return CatalogDdl::borrarTrigger($this, $nombre);
    }

}
