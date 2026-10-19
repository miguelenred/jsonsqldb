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
    /** @var array<string, array{0: array, 1: list<array>}> carpeta y tabla => su estructura y sus índices (ver indicesDe()) */
    private static array $indices = [];

    public function __construct(Storage $storage)
    {
        $this->st = $storage;
    }

    public function storage(): Storage { return $this->st; }

    /** @var list<string>|null lista de tablas de esta petición */
    private ?array $memoTablas = null;
    /** @var list<string>|null la de tablasEsquema() */
    private ?array $memoEsquema = null;
    /** @var array<string, string> nombre escrito => el de la tabla (ver nombreReal()) */
    private array $reales = [];
    /** El que se le pasa al analizador (ver nombres()) */
    private ?\Closure $nombres = null;
    /** @var array<string, array>|null las vistas, leídas una vez por consulta (2.10, ver vistas()) */
    private ?array $memoVistas = null;

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

    /** Las tablas, para ver quién apunta a quién al escribir: sin mirar la carpeta si no ha cambiado el esquema (ver StorageEscritura::tablasEsquema()) */
    public function tablasEsquema(): array
    {
        return $this->memoEsquema ??= StorageEscritura::tablasEsquema($this->st);
    }

    public function existe(string $tabla): bool { return $this->st->existe($tabla); }

    /**
     * El nombre de una tabla como está guardada, escrito sin distinguir
     * mayúsculas, como en SQLite (2.10): el de su estructura si existe tal
     * cual (en Windows, «FOO» abre el fichero de «Foo»), el único que coincide
     * si no, y el escrito si no hay ninguna (una vista, un nombre nuevo). Si
     * hay dos que solo se distinguen por las mayúsculas (de antes de la 2.10),
     * hay que escribirlo exacto.
     */
    public function nombreReal(string $nombre): string
    {
        if (isset($this->reales[$nombre])) {
            return $this->reales[$nombre];
        }
        try {
            // Si el disco distingue mayúsculas, se llama así; si no, como diga su estructura
            if ($this->st->existe($nombre)) {
                return $this->reales[$nombre] = $this->st->distingueMayusculas($nombre)
                    ? $nombre : (string)($this->meta($nombre)['table'] ?? $nombre);
            }
        } catch (JsonSqlDbError) {
            return $nombre;                        // no puede ser una tabla: tal cual
        }
        if ($this->esVista($nombre)) {
            return $this->reales[$nombre] = $nombre;
        }
        $iguales = array_values(array_filter($this->tablas(), static fn(string $t): bool => strcasecmp($t, $nombre) === 0));
        if (\count($iguales) > 1) {
            throw JsonSqlDbError::schema("Hay más de una tabla que se llama '$nombre' sin distinguir mayúsculas ("
                . implode(', ', $iguales) . '): escribe su nombre exacto');
        }
        return isset($iguales[0]) ? $this->reales[$nombre] = $iguales[0] : $nombre;
    }

    /** nombreReal() para Parser::analizar() */
    public function nombres(): \Closure
    {
        return $this->nombres ??= function (string $nombre): string {
            return $this->nombreReal($nombre);
        };
    }

    // ------------------------------------------------------------------
    // Vistas
    // ------------------------------------------------------------------

    /**
     * Las vistas, una vez por consulta (2.10: una búsqueda por clave miraba
     * _views.json cuatro veces, y con vistas lo leía y decodificaba otras
     * tantas). Se olvidan con olvidar() y al escribirlas (ver CatalogDdl).
     *
     * @return array<string,array> nombre => ['sql' => ..., 'created_at' => ...]
     */
    public function vistas(): array
    {
        return $this->memoVistas ??= $this->st->leerVistas();
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
            if (\count(self::$normalizadas) >= 32) {
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
            $this->memoEsquema = null;
            $this->reales     = [];
            $this->memoVistas = null;
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
            if (!\in_array($tipo['type'], [Types::INTEGER, Types::DOUBLE, Types::DECIMAL], true)) {
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
            if (!\in_array($c, ['NOCASE', 'RTRIM'], true)) {
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

    /**
     * La afinidad de cada columna (en minúsculas), 'N' o 'T' (2.10, ver
     * Types::afinidad()): con ella se compara con lo que no es una columna.
     *
     * @return array<string, string>
     */
    public static function afinidades(array $meta): array
    {
        $out = [];
        foreach ($meta['columns'] as $c) {
            $out[strtolower((string)$c['name'])] = $c['type'] === Types::TEXT ? 'T' : 'N';     // Types::afinidad(), sin la llamada
        }
        return $out;
    }

    /**
     * La afinidad de cada una de esas columnas, por posición (2.10): con la
     * que se buscan sus claves (ver Indexes::claveFila()).
     *
     * @param list<string> $columnas
     * @return list<string>
     */
    public static function afinidadesDe(array $meta, array $columnas): array
    {
        $por = self::afinidades($meta);
        $out = [];
        foreach (array_values($columnas) as $c) {
            $out[] = $por[strtolower((string)$c)] ?? 'T';
        }
        return $out;
    }

    /**
     * Nombre de columna, solo o con la tabla delante, en minúsculas => su
     * nombre (para Evaluator::resolver()); y "\0c:" y "\0a:" con el nombre, su
     * colación (2.9) y su afinidad (2.10)
     */
    public static function mapaColumnas(string $tabla, array $columnas): array
    {
        $mapa = [];
        foreach ($columnas as $c) {
            $mapa[strtolower($c['name'])] = $c['name'];
            $mapa[strtolower($tabla . '.' . $c['name'])] = $c['name'];
            if (isset($c['collate'])) {
                $mapa["\0c:" . $c['name']] = $c['collate'];    // su colación (ver Evaluator::resolver())
            }
            $mapa["\0a:" . $c['name']] = $c['type'] === Types::TEXT ? 'T' : 'N';      // su afinidad (2.10, ver Types::afinidad())
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
        // Una vez por estructura y proceso (2.10): una consulta los pide
        // varias veces, y salen de recorrer sus columnas e índices
        $meta  = $this->meta($tabla);
        $clave = $this->st->dir() . "\0" . $tabla;
        $memo  = self::$indices[$clave] ?? null;
        if ($memo !== null && $memo[0] === $meta) {
            return $memo[1];
        }
        if (\count(self::$indices) >= 32) {
            self::$indices = [];                  // los de las tablas que se están usando
        }
        return (self::$indices[$clave] = [$meta, Indexes::definiciones($meta)])[1];
    }

    // ------------------------------------------------------------------
    // Autoincremento
    // ------------------------------------------------------------------

    /** Columna de autoincremento de la tabla, o null. */
    public static function columnaAutoincremento(array $meta): ?string
    {
        return $meta['autoincrement']['column'] ?? null;
    }

    // Los cambios de estructura están en CatalogDdl, que solo se carga para
    // ellos y se llama directamente: CatalogDdl::crearTabla($cat, …) (2.10)
}
