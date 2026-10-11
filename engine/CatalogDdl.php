<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Los cambios de estructura del catálogo (CREATE, ALTER, DROP, vistas,
 * triggers, autoincremento), con lo público de Catalog, que se recibe en
 * $cat. Van aparte para que una consulta no tenga que compilarlos (2.9):
 * sin OPcache, PHP compila en cada petición lo que carga. Catalog los sigue
 * ofreciendo con el mismo nombre. No se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class CatalogDdl
{
    private const ACCIONES_FK = ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL', 'SET DEFAULT'];
    private const TIMINGS     = ['BEFORE', 'AFTER'];
    private const EVENTOS     = ['INSERT', 'UPDATE', 'DELETE'];

    private function __construct()
    {
    }

    // ------------------------------------------------------------------
    // Vistas
    // ------------------------------------------------------------------

    /** Corta la operación si ya hay una vista con ese nombre. */
    public static function exigirNombreLibreDeVista(Catalog $cat, string $nombre): void
    {
        if ($cat->esVista($nombre)) {
            throw JsonSqlDbError::schema("Ya existe una vista llamada '$nombre'");
        }
    }

    /**
     * Crea una vista: un SELECT guardado con nombre.
     *
     * Se comprueba que la consulta se analiza y que no choca con una tabla.
     * No se ejecuta: una vista puede apuntar a datos que aún no existen.
     */
    public static function crearVista(Catalog $cat, string $nombre, string $sql, bool $siNoExiste = false): bool
    {
        Storage::validarTabla($nombre);

        if ($cat->existe($nombre)) {
            throw JsonSqlDbError::schema("Ya existe una tabla llamada '$nombre'");
        }
        if ($cat->esVista($nombre)) {
            if ($siNoExiste) {
                return false;
            }
            throw JsonSqlDbError::schema("La vista '$nombre' ya existe");
        }

        $vistas = $cat->vistas();
        $vistas[$nombre] = ['sql' => $sql, 'created_at' => date('Y-m-d H:i:s')];
        StorageMantenimiento::guardarVistas($cat->storage(), $vistas);
        return true;
    }

    public static function borrarVista(Catalog $cat, string $nombre, bool $siExiste = false): bool
    {
        $vistas = $cat->vistas();
        foreach (array_keys($vistas) as $n) {
            if (strcasecmp((string)$n, $nombre) === 0) {
                unset($vistas[$n]);
                StorageMantenimiento::guardarVistas($cat->storage(), $vistas);
                return true;
            }
        }
        if ($siExiste) {
            return false;
        }
        throw JsonSqlDbError::schema("La vista '$nombre' no existe");
    }

    /**
     * Guarda datos y estructura en una sola escritura: cada fila pasada por
     * $porFila, parte a parte (2.9: la tabla entera en memoria).
     *
     * Juntas y no una detrás de otra: cada escritura sube la revisión de la
     * tabla y reconstruye sus índices, así que separarlas sería hacer ese
     * trabajo dos veces y dejar un instante con los datos nuevos y la
     * estructura vieja.
     */
    private static function reescribirFilas(Catalog $cat, string $tabla, callable $porFila, array $meta): void
    {
        StorageMantenimiento::reescribir($cat->storage(), $tabla, $porFila, self::compactar($meta), Indexes::definiciones($meta));
        $cat->olvidar($tabla);
    }

    /**
     * Guarda la estructura (compactada) e invalida la caché de la petición.
     * Con $unico, las columnas de un UNIQUE nuevo: sus filas no pueden repetir
     * la clave, y se mira al construir su índice (ver StorageEscritura::escribirIndice()),
     * en la misma pasada (2.9: una antes, aparte, hacía ADD UNIQUE más lento que en 2.8.0)
     *
     * @param list<string>|null $unico
     */
    private static function guardar(Catalog $cat, string $tabla, array $meta, ?array $unico = null): void
    {
        // Las definiciones van siempre: un ALTER puede añadir o quitar una
        // PRIMARY KEY o un UNIQUE, y con ellos el índice automático que llevan.
        $definiciones = Indexes::definiciones($meta);
        if ($unico !== null) {
            $clave = strtolower(implode("\0", $unico));
            $suya  = null;
            foreach ($definiciones as $i => $def) {
                if (strtolower(implode("\0", $def['columns'])) === $clave) {
                    $suya = $i;
                }
            }
            if ($suya === null || !$cat->storage()->indicesActivos()) {
                self::exigirSinRepetidos($cat, $tabla, $unico, Catalog::plegarDe($meta, $unico));   // sin índice que construir
            } else {
                $definiciones[$suya]['exigir'] = true;
            }
        }
        StorageMantenimiento::guardarMeta($cat->storage(), $tabla, self::compactar($meta), $definiciones);
        $cat->olvidar($tabla);
    }

    /**
     * Error si las filas de la tabla repiten la clave de $columnas, con la
     * fila que la repite (la segunda, en el orden de la tabla).
     *
     * @param list<string> $columnas
     */
    private static function exigirSinRepetidos(Catalog $cat, string $tabla, array $columnas, array $plegar): void
    {
        $vistos = [];
        foreach ($cat->storage()->filas($tabla) as $fila) {
            $clave = Indexes::claveFila($fila, $columnas, $plegar);
            if ($clave === null) {
                continue;                                   // con NULL no aplica la unicidad
            }
            if (isset($vistos[$clave])) {
                throw self::repetida($tabla, $fila, $columnas);
            }
            $vistos[$clave] = true;
        }
    }

    /** El error de unos datos que repiten la clave de un UNIQUE o de la clave primaria que se añade */
    public static function repetida(string $tabla, array $fila, array $columnas): JsonSqlDbError
    {
        return JsonSqlDbError::constraint("Los datos de '$tabla' repiten el valor " . self::valoresDe($fila, $columnas) . ' en (' . implode(', ', $columnas) . ')');
    }

    // ------------------------------------------------------------------
    // Tablas
    // ------------------------------------------------------------------

    /**
     * Crea una tabla.
     * $def = ['columns'=>[...], 'unique'=>[...], 'foreign_keys'=>[...]]
     */
    public static function crearTabla(Catalog $cat, string $tabla, array $def): void
    {
        Storage::validarTabla($tabla);

        $columnas = array_map([Catalog::class, 'normalizarColumna'], $def['columns'] ?? []);
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
            'indexes'       => [],
            'checks'        => [],
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

        // AUTO_INCREMENT = n de MySQL (2.9): el primer valor del contador
        if (isset($def['autoinc_inicio'])) {
            if ($meta['autoincrement'] === null) {
                throw JsonSqlDbError::schema("AUTO_INCREMENT = {$def['autoinc_inicio']}: la tabla '$tabla' no tiene columna AUTO_INCREMENT");
            }
            $meta['autoincrement']['next'] = max(1, (int)$def['autoinc_inicio']);
        }
        foreach ($def['unique'] ?? [] as $uq) {
            $meta['unique'][] = self::normalizarUnico($tabla, $meta, $uq);
        }
        foreach ($def['foreign_keys'] ?? [] as $fk) {
            $meta['foreign_keys'][] = self::normalizarFk($cat, $tabla, $meta, $fk);
        }
        // CHECK (2.9): la expresión tiene que poder calcularse con las columnas
        // de la tabla; se comprueba con cada fila que se escribe
        foreach ($def['checks'] ?? [] as $ck) {
            self::checkResuelto($tabla, $columnas, (string)$ck['sql']);
            $meta['checks'][] = ['name' => $ck['name'], 'sql' => (string)$ck['sql']];
        }
        // Índices declarados con la tabla (KEY / INDEX de MySQL, 2.9)
        foreach ($def['indexes'] ?? [] as $ix) {
            Indexes::validarNombre((string)$ix['name']);
            $cols = [];
            foreach ($ix['columns'] as $c) {
                $col = Catalog::columna($meta, (string)$c);
                if ($col === null) {
                    throw JsonSqlDbError::schema("Índice sobre columna inexistente '$c' en '$tabla'");
                }
                $cols[] = $col['name'];
            }
            foreach ($meta['indexes'] as $otro) {
                if (strcasecmp($otro['name'], (string)$ix['name']) === 0) {
                    throw JsonSqlDbError::schema("El índice '{$ix['name']}' está dos veces en '$tabla'");
                }
            }
            $meta['indexes'][] = ['name' => (string)$ix['name'], 'columns' => $cols];
        }

        // Dos claves únicas con el mismo nombre y otras columnas: una de las
        // dos dejaba de comprobarse (2.9). Con las mismas, sobra pero no
        // estorba, y se admite como siempre; y si además tendrían el mismo
        // índice, ya lo dice compactar()
        if (Indexes::autoRepetido($meta) === null) {
            $vistos = [];
            foreach (Catalog::conjuntosUnicos($meta) as $uq) {
                $n = strtolower($uq['name']);
                if (isset($vistos[$n]) && $vistos[$n] !== $uq['columns']) {
                    throw JsonSqlDbError::schema("Ya existe una restricción llamada '{$uq['name']}' en '$tabla'");
                }
                $vistos[$n] = $uq['columns'];
            }
        }

        StorageMantenimiento::crearTabla($cat->storage(), $tabla, self::compactar($meta), Indexes::definiciones($meta));
        $cat->olvidar();                  // hay una tabla más
    }

    public static function borrarTabla(Catalog $cat, string $tabla): void
    {
        if (!$cat->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        foreach ($cat->storage()->tablas() as $otra) {
            if ($otra === $tabla) continue;
            foreach ($cat->meta($otra)['foreign_keys'] as $fk) {
                if (strcasecmp($fk['table'], $tabla) === 0) {
                    throw JsonSqlDbError::constraint("'$otra' tiene una clave foránea hacia '$tabla'");
                }
            }
        }
        StorageMantenimiento::borrarTabla($cat->storage(), $tabla);
        $cat->olvidar();                  // hay una tabla menos
    }

    public static function renombrarTabla(Catalog $cat, string $desde, string $hasta): void
    {
        $meta = $cat->meta($desde);
        // Con el nombre de una vista, la tabla quedaba escondida detrás (2.9)
        self::exigirNombreLibreDeVista($cat, $hasta);
        // Los triggers y las vistas guardan su SQL tal cual se escribió: con el
        // nombre viejo dejaban de funcionar (2.9). Reescribir ese SQL exige
        // saber, en cada sitio donde sale el nombre, si es esta tabla o un
        // alias, una columna o una tabla del WITH que se llama igual; antes de
        // arriesgarse a cambiar lo que no es, se pide hacerlo a mano
        foreach ($cat->vistas() as $nombre => $v) {
            if (self::usaTabla((string)$v['sql'], $desde)) {
                throw JsonSqlDbError::constraint("No se puede renombrar '$desde': la vista '$nombre' la usa. "
                    . 'Bórrala, renombra la tabla y vuelve a crearla con el nombre nuevo');
            }
        }
        foreach ($cat->tablas() as $t) {
            foreach ($cat->meta($t)['triggers'] as $trg) {
                $sentencias = Catalog::sentenciasDe((array)($trg['body'] ?? []));
                if (($trg['when'] ?? null) !== null) {
                    $sentencias[] = 'SELECT ' . $trg['when'];
                }
                foreach ($sentencias as $sql) {
                    if (self::usaTabla($sql, $desde)) {
                        throw JsonSqlDbError::constraint("No se puede renombrar '$desde': el trigger '{$trg['name']}' de '$t' la usa. "
                            . 'Bórralo, renombra la tabla y vuelve a crearlo con el nombre nuevo');
                    }
                }
            }
        }
        $meta['table'] = $hasta;
        StorageMantenimiento::renombrarTabla($cat->storage(), $desde, $hasta, Indexes::definiciones($meta));
        foreach ($cat->storage()->tablas() as $t) {
            $meta    = $cat->meta($t);
            $cambia  = false;
            foreach ($meta['foreign_keys'] as $i => $fk) {
                if (strcasecmp($fk['table'], $desde) === 0) {
                    $meta['foreign_keys'][$i]['table'] = $hasta;
                    $cambia = true;
                }
            }
            if ($cambia) {
                self::guardar($cat, $t, $meta);
            }
        }
        $cat->olvidar();                  // ha cambiado un nombre
    }

    /** ¿Usa la sentencia (de una vista o de un trigger) la tabla $tabla? Una que ya no se deja analizar, tampoco funciona ahora: no */
    private static function usaTabla(string $sql, string $tabla): bool
    {
        try {
            $ast = strncasecmp(ltrim($sql), 'SET', 3) === 0 ? ParserEscritura::analizarSetNew($sql) : Parser::analizar($sql);
        } catch (JsonSqlDbError) {
            return false;
        }
        return self::nombraTabla($ast, $tabla);
    }

    /** ¿Sale $tabla en el árbol: en un FROM, un JOIN o una subconsulta, o como la tabla que se escribe? */
    private static function nombraTabla(array $n, string $tabla): bool
    {
        if ((($n['tipo'] ?? null) === 'tabla' && is_string($n['nombre'] ?? null) && strcasecmp($n['nombre'], $tabla) === 0)
            || (in_array($n['k'] ?? null, ['insert', 'update', 'delete'], true) && strcasecmp((string)$n['tabla'], $tabla) === 0)) {
            return true;
        }
        foreach ($n as $hijo) {
            if (is_array($hijo) && self::nombraTabla($hijo, $tabla)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // ALTER TABLE
    // ------------------------------------------------------------------

    public static function anadirColumna(Catalog $cat, string $tabla, array $spec): void
    {
        $meta = $cat->meta($tabla);
        $col  = Catalog::normalizarColumna($spec);

        if (Catalog::columna($meta, $col['name']) !== null) {
            throw JsonSqlDbError::schema("La columna '{$col['name']}' ya existe en '$tabla'");
        }
        if ($col['autoincrement']) {
            throw JsonSqlDbError::schema('No se puede añadir una columna AUTOINCREMENT a una tabla existente');
        }

        $hayFilas = StorageLectura::contarFilas($cat->storage(), $tabla) > 0;
        if ($col['default_expr'] !== null && $hayFilas) {
            // Como SQLite: el valor de las filas que ya están no se puede inventar
            throw JsonSqlDbError::schema("No se puede añadir la columna '{$col['name']}' con un DEFAULT calculado: la tabla tiene datos");
        }
        if ($col['notnull'] && $col['default'] === null && $hayFilas) {
            throw JsonSqlDbError::schema("La columna '{$col['name']}' es NOT NULL y necesita DEFAULT: la tabla tiene datos");
        }
        // Una PRIMARY KEY también (2.9: solo se miraba UNIQUE), salvo que la
        // tabla ya tenga una: la nueva columna se suma a ella, que ya es única
        if (($col['unique'] || ($col['pk'] && Catalog::clavePrimaria($meta) === []))
            && $col['default'] !== null && StorageLectura::contarFilas($cat->storage(), $tabla) > 1) {
            // Todas las filas tendrían el mismo valor (2.9: se añadía repetido)
            throw JsonSqlDbError::constraint("Los datos de '$tabla' repetirían el valor "
                . json_encode($col['default'], JSON_UNESCAPED_UNICODE) . " en ({$col['name']})");
        }
        $meta['columns'][] = $col;
        if (!empty($spec['checks']) || isset($spec['references'])) {
            self::restriccionesDeColumna($cat, $tabla, $meta, $spec, StorageMantenimiento::releer(
                static function () use ($cat, $tabla, $col, $hayFilas): \Generator {
                    foreach ($hayFilas ? $cat->storage()->filas($tabla) : [] as $fila) {
                        $fila[$col['name']] = $col['default'];
                        yield $fila;
                    }
                }));
        }

        // Por bloques de texto: la columna nueva, con su valor, al final de
        // cada fila (donde la pondría PHP al añadirla al array)
        $par = StorageMantenimiento::claveJson($col['name']) . StorageMantenimiento::valorJson($col['default']);
        $porLinea = static fn(string $l): string => $l === '{}' ? '{' . $par . '}' : substr($l, 0, -1) . ',' . $par . '}';
        if (StorageMantenimiento::reescribirLineas($cat->storage(), $tabla, $porLinea, self::compactar($meta), Indexes::definiciones($meta))) {
            $cat->olvidar($tabla);
            return;
        }
        self::reescribirFilas($cat, $tabla, static function (array $fila) use ($col): array {
            $fila[$col['name']] = $col['default'];
            return $fila;
        }, $meta);
    }

    /**
     * El CHECK y el REFERENCES escritos en la columna de un ADD, MODIFY o
     * CHANGE van a la tabla, como al crearla (2.9: se perdían sin avisar).
     * Las filas, ya con la columna como queda, tienen que cumplirlos.
     *
     * @param list<array> $filas
     */
    private static function restriccionesDeColumna(Catalog $cat, string $tabla, array &$meta, array $spec, iterable $filas): void
    {
        foreach ($spec['checks'] ?? [] as $sql) {
            self::exigirCheck($tabla, $meta, (string)$sql, $filas);
            $meta['checks'][] = ['name' => null, 'sql' => (string)$sql];
        }
        if (isset($spec['references'])) {
            $fk = self::normalizarFk($cat, $tabla, $meta, $spec['references']);
            self::exigirNombreLibre($meta, $fk['name']);
            self::exigirPadres($cat, $tabla, $fk, $filas);
            $meta['foreign_keys'][] = $fk;
        }
    }

    /**
     * MODIFY: cambia el tipo y las restricciones de una columna que ya existe.
     * Con $desde (CHANGE de MySQL, 2.9), la columna que se llama así pasa además
     * a llamarse como dice $spec, todo en un paso: en dos, el segundo leía la
     * estructura de antes del primero.
     *
     * Convierte los datos que hay al tipo nuevo y comprueba que cumplen lo que
     * se le pide antes de guardar nada: si algún valor no se puede convertir,
     * si queda un NULL en una columna NOT NULL o si hay repetidos en una UNIQUE,
     * la operación falla y la tabla se queda como estaba.
     *
     * No toca la clave primaria ni el autoincremento: eso obliga a recrear la
     * tabla.
     */
    public static function modificarColumna(Catalog $cat, string $tabla, array $spec, ?string $desde = null): void
    {
        $meta   = $cat->meta($tabla);
        $nueva  = Catalog::normalizarColumna($spec);
        $actual = Catalog::columna($meta, $desde ?? $nueva['name']);

        if ($actual === null) {
            throw JsonSqlDbError::schema("La columna '" . ($desde ?? $nueva['name']) . "' no existe en '$tabla'");
        }
        $renombra = strcasecmp($actual['name'], $nueva['name']) !== 0;
        if ($renombra) {
            if (Catalog::columna($meta, $nueva['name']) !== null) {
                throw JsonSqlDbError::schema("La columna '{$nueva['name']}' ya existe en '$tabla'");
            }
            self::exigirColumnaLibre($cat, $tabla, $meta, $actual['name']);
        }
        if ($nueva['autoincrement'] !== $actual['autoincrement']) {
            throw JsonSqlDbError::schema('No se puede añadir ni quitar AUTOINCREMENT: hay que recrear la tabla');
        }
        // La clave primaria se conserva tal cual estaba
        $nueva['pk'] = $actual['pk'];
        if ($actual['pk'] && !$nueva['notnull']) {
            $nueva['notnull'] = true;
        }

        // Conversión de los datos, fila a fila según se leen: para las
        // comprobaciones y para escribirlos (2.9: la tabla entera en memoria)
        $convertir = static function (array $fila) use ($tabla, $actual, $nueva, $renombra): array {
            $valor = $fila[$actual['name']] ?? null;
            if ($renombra) {
                unset($fila[$actual['name']]);
            }

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
                } catch (JsonSqlDbError) {
                    throw JsonSqlDbError::type(
                        "El valor " . json_encode($fila[$nueva['name']] ?? null, JSON_UNESCAPED_UNICODE)
                        . " de '$tabla.{$nueva['name']}' no se puede convertir a {$nueva['type']}"
                    );
                }
            }

            $fila[$nueva['name']] = $valor;
            return $fila;
        };
        $filas = StorageMantenimiento::releer(static function () use ($cat, $tabla, $convertir): \Generator {
            foreach ($cat->storage()->filas($tabla) as $fila) {
                yield $convertir($fila);
            }
        });

        foreach ($meta['columns'] as $i => $c) {
            if (strcasecmp($c['name'], $actual['name']) === 0) {
                $meta['columns'][$i] = $nueva;
            }
        }
        if ($renombra) {
            // Los índices y el autoincremento siguen a la columna
            foreach ($meta['indexes'] as $i => $idx) {
                foreach ($idx['columns'] as $j => $c) {
                    if (strcasecmp((string)$c, $actual['name']) === 0) {
                        $meta['indexes'][$i]['columns'][$j] = $nueva['name'];
                    }
                }
            }
            if (strcasecmp((string)($meta['autoincrement']['column'] ?? ''), $actual['name']) === 0) {
                $meta['autoincrement']['column'] = $nueva['name'];
            }
        }
        self::exigirClavesTras($cat, $tabla, $meta, $filas, $nueva['name'], ($actual['collate'] ?? null) !== ($nueva['collate'] ?? null));
        self::restriccionesDeColumna($cat, $tabla, $meta, $spec, $filas);

        self::reescribirFilas($cat, $tabla, $convertir, $meta);
    }

    /**
     * Tras un MODIFY: cada UNIQUE (y la clave primaria) en el que está la
     * columna sigue sin repetidos con sus valores y su colación nuevos; antes
     * de 2.9 solo se miraba el de la columna sola. Si cambia la colación
     * (2.9), además, las claves foráneas que apuntan a ella siguen
     * encontrando su fila: con BINARY, 'abc' ya no encuentra 'ABC'.
     *
     * @param array<int, array> $filas las de la tabla, ya convertidas
     */
    private static function exigirClavesTras(Catalog $cat, string $tabla, array $meta, iterable $filas, string $columna,
                                             bool $otraColacion): void
    {
        foreach (Catalog::conjuntosUnicos($meta) as $u) {
            if (!in_array(strtolower($columna), array_map('strtolower', $u['columns']), true)) {
                continue;
            }
            $vistos = [];
            foreach ($filas as $fila) {
                $clave = Indexes::claveFila($fila, $u['columns'], $u['plegar']);
                if ($clave === null) {
                    continue;
                }
                if (isset($vistos[$clave])) {
                    throw count($u['columns']) === 1
                        ? JsonSqlDbError::constraint("'$tabla.$columna' tiene valores repetidos: no puede ser UNIQUE")
                        : self::repetida($tabla, $fila, $u['columns']);
                }
                $vistos[$clave] = true;
            }
        }
        if (!$otraColacion) {
            return;
        }
        foreach ($cat->storage()->tablas() as $otra) {
            $misma = strcasecmp($otra, $tabla) === 0;
            foreach (($misma ? $meta : $cat->meta($otra))['foreign_keys'] as $fk) {
                if (strcasecmp($fk['table'], $tabla) !== 0
                    || !in_array(strtolower($columna), array_map('strtolower', $fk['references']), true)) {
                    continue;
                }
                $plegar = Catalog::plegarDe($meta, $fk['references']);
                $padres = [];
                foreach ($filas as $fila) {
                    $clave = Indexes::claveFila($fila, $fk['references'], $plegar);
                    if ($clave !== null) { $padres[$clave] = true; }
                }
                foreach ($misma ? $filas : $cat->storage()->filas($otra) as $hija) {
                    $clave = Indexes::claveFila($hija, $fk['columns'], $plegar);
                    if ($clave !== null && !isset($padres[$clave])) {
                        throw JsonSqlDbError::constraint("Los datos de '$otra' apuntan a " . self::valoresDe($hija, $fk['columns']) . " y no existe en '$tabla'");
                    }
                }
            }
        }
    }

    public static function borrarColumna(Catalog $cat, string $tabla, string $nombre): void
    {
        $meta = $cat->meta($tabla);
        $col  = Catalog::columna($meta, $nombre);
        if ($col === null) {
            throw JsonSqlDbError::schema("La columna '$nombre' no existe en '$tabla'");
        }
        if (count($meta['columns']) === 1) {
            throw JsonSqlDbError::schema("No se puede borrar la única columna de '$tabla'");
        }
        self::exigirColumnaLibre($cat, $tabla, $meta, $col['name']);

        $meta['columns'] = array_values(array_filter(
            $meta['columns'],
            static fn(array $c): bool => strcasecmp($c['name'], $col['name']) !== 0
        ));

        // El autoincremento se va con su columna
        if (($meta['autoincrement']['column'] ?? null) !== null
            && strcasecmp((string)$meta['autoincrement']['column'], $col['name']) === 0) {
            $meta['autoincrement'] = null;
        }

        // Y los índices que la usaban: un índice acelera, no restringe, así que
        // se va sin más en vez de impedir el ALTER
        $meta['indexes'] = array_values(array_filter(
            $meta['indexes'],
            static function (array $idx) use ($col): bool {
                foreach ($idx['columns'] as $c) {
                    if (strcasecmp((string)$c, $col['name']) === 0) {
                        return false;
                    }
                }
                return true;
            }
        ));

        // Por bloques de texto, salvo si era parte de la clave primaria (hay
        // que comprobar que la que queda sigue siendo única, y eso pide las filas)
        if (!$col['pk']) {
            $porLinea = static function (string $l) use ($col): ?string {
                $quitada = self::quitarDeLinea($l, $col['name']);
                return $quitada === null ? null : $quitada[0];
            };
            if (StorageMantenimiento::reescribirLineas($cat->storage(), $tabla, $porLinea, self::compactar($meta), Indexes::definiciones($meta))) {
                $cat->olvidar($tabla);
                return;
            }
        }

        $quitar = static function (array $fila) use ($col): array {
            unset($fila[$col['name']]);
            return $fila;
        };

        // Si la columna formaba parte de una clave primaria compuesta, la que
        // queda tiene que seguir siendo única
        if ($col['pk']) {
            $resto = Catalog::clavePrimaria($meta);
            if ($resto !== []) {
                $vistos = [];
                $plegar = Catalog::plegarDe($meta, $resto);
                foreach ($cat->storage()->filas($tabla) as $fila) {
                    $clave = Indexes::claveFila($fila, $resto, $plegar);
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

        self::reescribirFilas($cat, $tabla, $quitar, $meta);
    }

    public static function renombrarColumna(Catalog $cat, string $tabla, string $desde, string $hasta): void
    {
        $meta = $cat->meta($tabla);
        $col  = Catalog::columna($meta, $desde);
        if ($col === null) {
            throw JsonSqlDbError::schema("La columna '$desde' no existe en '$tabla'");
        }
        Catalog::exigirNombreColumna($hasta);
        if (Catalog::columna($meta, $hasta) !== null) {
            throw JsonSqlDbError::schema("La columna '$hasta' ya existe en '$tabla'");
        }
        self::exigirColumnaLibre($cat, $tabla, $meta, $col['name']);

        foreach ($meta['columns'] as $i => $c) {
            if (strcasecmp($c['name'], $col['name']) === 0) {
                $meta['columns'][$i]['name'] = $hasta;
            }
        }
        // Los índices siguen a la columna: si no, se quedarían apuntando a un
        // nombre que ya no existe y se perderían sin avisar
        foreach ($meta['indexes'] as $i => $idx) {
            foreach ($idx['columns'] as $j => $c) {
                if (strcasecmp((string)$c, $col['name']) === 0) {
                    $meta['indexes'][$i]['columns'][$j] = $hasta;
                }
            }
        }
        if (($meta['autoincrement']['column'] ?? null) !== null
            && strcasecmp($meta['autoincrement']['column'], $col['name']) === 0) {
            $meta['autoincrement']['column'] = $hasta;
        }

        // Por bloques de texto: el par sale de su sitio y vuelve con el nombre
        // nuevo al final de la fila (donde lo deja PHP al renombrar la clave)
        $porLinea = static function (string $l) use ($col, $hasta): ?string {
            $quitada = self::quitarDeLinea($l, $col['name']);
            if ($quitada === null) {
                return null;
            }
            [$resto, $valor] = $quitada;
            return ($resto === '{}' ? '{' : substr($resto, 0, -1) . ',') . StorageMantenimiento::claveJson($hasta) . $valor . '}';
        };
        if (StorageMantenimiento::reescribirLineas($cat->storage(), $tabla, $porLinea, self::compactar($meta), Indexes::definiciones($meta))) {
            $cat->olvidar($tabla);
            return;
        }

        self::reescribirFilas($cat, $tabla, static function (array $fila) use ($col, $hasta): array {
            if (array_key_exists($col['name'], $fila)) {
                $fila[$hasta] = $fila[$col['name']];
                unset($fila[$col['name']]);
            }
            return $fila;
        }, $meta);
    }

    /**
     * Quita el par "columna":valor del JSON de una fila, sin decodificarla:
     * [la fila sin él, el valor tal como estaba escrito]. Null si la fila no
     * lo tiene una vez exactamente, o el valor no es un escalar de JSON (y
     * entonces se hace decodificando). "columna": solo puede aparecer como
     * clave: dentro de un texto, una comilla va escapada (\").
     *
     * @return array{0: string, 1: string}|null
     */
    private static function quitarDeLinea(string $linea, string $columna): ?array
    {
        $aguja = StorageMantenimiento::claveJson($columna);
        $donde = strpos($linea, $aguja);
        if ($donde === false || $donde === 0 || !in_array($linea[$donde - 1], ['{', ','], true)
            || strpos($linea, $aguja, $donde + 1) !== false) {
            return null;
        }
        $desde = $donde + strlen($aguja);
        if (!preg_match('/\G(?:"(?:[^"\\\\]++|\\\\.)*+"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null)/', $linea, $m, 0, $desde)) {
            return null;
        }
        $valor = $m[0];
        $tras  = $desde + strlen($valor);
        $sigue = $linea[$tras] ?? '';
        if ($sigue !== ',' && $sigue !== '}') {
            return null;
        }
        $antes = substr($linea, 0, $donde - 1);          // sin el { o la , de delante
        $abre  = $linea[$donde - 1];
        if ($abre === '{') {
            // Era la primera: lo que sigue (tras su coma, si la hay) empieza la fila
            $resto = '{' . ($sigue === ',' ? substr($linea, $tras + 1) : '}');
        } else {
            $resto = $antes . substr($linea, $tras);
        }
        return [$resto, $valor];
    }

    /** Impide tocar una columna usada por UNIQUE compuesto o por una FK (propia o ajena). */
    private static function exigirColumnaLibre(Catalog $cat, string $tabla, array $meta, string $columna): void
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
        foreach ($meta['checks'] as $ck) {
            if (preg_match('/\\b' . preg_quote($columna, '/') . '\\b/i', $ck['sql']) === 1) {
                throw JsonSqlDbError::constraint("'$columna' se usa en el CHECK " . ($ck['name'] !== null ? "'{$ck['name']}'" : "({$ck['sql']})") . ': no se puede cambiar');
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
        foreach ($cat->storage()->tablas() as $otra) {
            if ($otra === $tabla) continue;
            foreach ($cat->meta($otra)['foreign_keys'] as $fk) {
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
    // Restricciones sobre una tabla ya creada
    // ------------------------------------------------------------------

    /**
     * Añade una clave única. Comprueba antes que los datos actuales la cumplen.
     * Devuelve el nombre de la restricción.
     */
    public static function anadirUnico(Catalog $cat, string $tabla, array $uq): string
    {
        $meta = self::metaExistente($cat, $tabla);
        $nuevo = self::normalizarUnico($tabla, $meta, $uq);
        self::exigirNombreLibre($meta, $nuevo['name']);

        $meta['unique'][] = $nuevo;
        self::guardar($cat, $tabla, $meta, $nuevo['columns']);
        return $nuevo['name'];
    }

    /**
     * Añade un CHECK (2.9). Como en MySQL, antes comprueba que las filas que
     * ya hay lo cumplen; si alguna no, la tabla se queda como estaba.
     */
    public static function anadirCheck(Catalog $cat, string $tabla, array $ck): string
    {
        $meta = self::metaExistente($cat, $tabla);
        if ($ck['name'] !== null) {
            self::exigirNombreLibre($meta, $ck['name']);
        }
        self::exigirCheck($tabla, $meta, (string)$ck['sql'], $cat->storage()->filas($tabla));
        $meta['checks'][] = ['name' => $ck['name'], 'sql' => (string)$ck['sql']];
        self::guardar($cat, $tabla, $meta);
        return $ck['name'] ?? 'CHECK';
    }

    /** Error si alguna de $filas no cumple el CHECK $sql */
    private static function exigirCheck(string $tabla, array $meta, string $sql, iterable $filas): void
    {
        $expr = self::checkResuelto($tabla, $meta['columns'], $sql);
        foreach ($filas as $fila) {
            if (Valor::verdadero(Evaluator::evaluar($expr, ['fila' => $fila])) === false) {
                throw JsonSqlDbError::constraint("Hay filas en '$tabla' que no cumplen el CHECK ($sql)");
            }
        }
    }

    /** Un CHECK listo para evaluar con las filas de la tabla; error si usa algo que no está */
    private static function checkResuelto(string $tabla, array $columnas, string $sql): array
    {
        return Evaluator::resolver(Parser::analizar('SELECT (' . $sql . ')')['cols'][0]['expr'], Catalog::mapaColumnas($tabla, $columnas));
    }

    /**
     * Añade una clave foránea. Comprueba antes que las filas actuales apuntan
     * a filas existentes de la tabla destino. Devuelve el nombre.
     */
    public static function anadirFk(Catalog $cat, string $tabla, array $fk): string
    {
        $meta  = self::metaExistente($cat, $tabla);
        $nuevo = self::normalizarFk($cat, $tabla, $meta, $fk);
        self::exigirNombreLibre($meta, $nuevo['name']);

        self::exigirPadres($cat, $tabla, $nuevo, $cat->storage()->filas($tabla));

        $meta['foreign_keys'][] = $nuevo;
        self::guardar($cat, $tabla, $meta);
        return $nuevo['name'];
    }

    /** Error si alguna de $filas apunta a una fila que no existe en el padre de $fk */
    private static function exigirPadres(Catalog $cat, string $tabla, array $fk, iterable $filas): void
    {
        // Con la colación de las columnas del padre, como al escribir (2.9)
        $padres = [];
        $plegar = Catalog::plegarDe($cat->meta($fk['table']), $fk['references']);
        foreach ($cat->storage()->filas($fk['table']) as $fila) {
            $clave = Indexes::claveFila($fila, $fk['references'], $plegar);
            if ($clave !== null) { $padres[$clave] = true; }
        }
        foreach ($filas as $fila) {
            $clave = Indexes::claveFila($fila, $fk['columns'], $plegar);
            if ($clave !== null && !isset($padres[$clave])) {
                throw JsonSqlDbError::constraint("Los datos de '$tabla' apuntan a " . self::valoresDe($fila, $fk['columns']) . " y no existe en '{$fk['table']}'");
            }
        }
    }

    // ------------------------------------------------------------------
    // Índices
    // ------------------------------------------------------------------

    /**
     * Crea un índice sobre una o varias columnas.
     *
     * @param string[] $columnas
     * @return bool false si ya existía y se pidió IF NOT EXISTS
     */
    public static function crearIndice(Catalog $cat, string $tabla, string $nombre, array $columnas, bool $siNoExiste = false): bool
    {
        Indexes::validarNombre($nombre);
        $meta = self::metaExistente($cat, $tabla);

        $cols  = [];
        $vistas = [];
        foreach ($columnas as $c) {
            $col = Catalog::columna($meta, (string)$c);
            if ($col === null) {
                throw JsonSqlDbError::schema("Índice sobre columna inexistente '$c' en '$tabla'");
            }
            if (isset($vistas[strtolower($col['name'])])) {
                throw JsonSqlDbError::schema("La columna '{$col['name']}' se repite en el índice '$nombre'");
            }
            $vistas[strtolower($col['name'])] = true;
            $cols[] = $col['name'];
        }
        if ($cols === []) {
            throw JsonSqlDbError::schema("El índice '$nombre' no indica columnas");
        }

        // El nombre acaba siendo parte de un nombre de fichero, y hay sistemas
        // que no distinguen mayúsculas: la comparación tampoco
        foreach ($cat->indicesDe($tabla) as $idx) {
            if (strcasecmp($idx['name'], $nombre) === 0) {
                if ($siNoExiste) {
                    return false;
                }
                throw JsonSqlDbError::schema(
                    $idx['auto']
                        ? "'$nombre' es el índice automático de una PRIMARY KEY o un UNIQUE de '$tabla'"
                        : "El índice '$nombre' ya existe en '$tabla'"
                );
            }
        }

        $meta['indexes'][] = ['name' => $nombre, 'columns' => $cols];
        self::guardar($cat, $tabla, $meta);
        return true;
    }

    /**
     * Borra un índice creado a mano. Devuelve la tabla a la que pertenecía, o
     * null si no existía y se pidió IF EXISTS.
     */
    public static function borrarIndice(Catalog $cat, ?string $tabla, string $nombre, bool $siExiste = false): ?string
    {
        foreach ($tabla === null ? $cat->tablas() : [$tabla] as $t) {
            $meta = self::metaExistente($cat, $t);
            foreach ($meta['indexes'] as $i => $idx) {
                if (strcasecmp((string)$idx['name'], $nombre) !== 0) {
                    continue;
                }
                array_splice($meta['indexes'], $i, 1);
                self::guardar($cat, $t, $meta);
                return $t;
            }
            // Un índice automático no se borra suelto: se va con su restricción
            foreach (Indexes::definiciones($meta) as $def) {
                if ($def['auto'] && strcasecmp($def['name'], $nombre) === 0) {
                    throw JsonSqlDbError::schema(
                        "'$nombre' es el índice automático de una PRIMARY KEY o un UNIQUE de '$t': "
                        . 'quita la restricción con ALTER TABLE si no lo quieres'
                    );
                }
            }
        }
        if ($siExiste) {
            return null;
        }
        throw JsonSqlDbError::schema(
            "El índice '$nombre' no existe" . ($tabla === null ? '' : " en '$tabla'")
        );
    }

    /** Borra una clave única o foránea por su nombre. */
    public static function borrarRestriccion(Catalog $cat, string $tabla, string $nombre): void
    {
        $meta = self::metaExistente($cat, $tabla);

        foreach ($meta['unique'] as $i => $uq) {
            if (strcasecmp((string)($uq['name'] ?? ''), $nombre) === 0) {
                array_splice($meta['unique'], $i, 1);
                self::guardar($cat, $tabla, $meta);
                return;
            }
        }
        foreach ($meta['foreign_keys'] as $i => $fk) {
            if (strcasecmp((string)($fk['name'] ?? ''), $nombre) === 0) {
                array_splice($meta['foreign_keys'], $i, 1);
                self::guardar($cat, $tabla, $meta);
                return;
            }
        }
        foreach ($meta['checks'] as $i => $ck) {
            if (strcasecmp((string)($ck['name'] ?? ''), $nombre) === 0) {
                array_splice($meta['checks'], $i, 1);
                self::guardar($cat, $tabla, $meta);
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
    public static function anadirClavePrimaria(Catalog $cat, string $tabla, array $columnas): void
    {
        $meta = self::metaExistente($cat, $tabla);

        if (Catalog::clavePrimaria($meta) !== []) {
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
            $col = Catalog::columna($meta, $c);
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
        $plegar = Catalog::plegarDe($meta, $nombres);
        foreach ($cat->storage()->filas($tabla) as $fila) {
            foreach ($nombres as $n) {
                if (($fila[$n] ?? null) === null) {
                    throw JsonSqlDbError::constraint(
                        "'$tabla.$n' tiene valores nulos: no puede formar parte de la clave primaria"
                    );
                }
            }
            $clave = Indexes::claveFila($fila, $nombres, $plegar);
            if (isset($vistos[$clave])) {
                throw self::repetida($tabla, $fila, $nombres);
            }
            $vistos[$clave] = true;
        }

        foreach ($meta['columns'] as $i => $c) {
            if (in_array($c['name'], $nombres, true)) {
                $meta['columns'][$i]['pk']      = true;
                $meta['columns'][$i]['notnull'] = true;
            }
        }
        self::guardar($cat, $tabla, $meta);
    }

    /** Quita la clave primaria. Las columnas siguen siendo NOT NULL. */
    public static function borrarClavePrimaria(Catalog $cat, string $tabla): void
    {
        $meta = self::metaExistente($cat, $tabla);

        if (Catalog::clavePrimaria($meta) === []) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no tiene clave primaria");
        }
        if (Catalog::columnaAutoincremento($meta) !== null) {
            throw JsonSqlDbError::schema(
                "La clave primaria de '$tabla' es AUTOINCREMENT: para quitarla hay que recrear la tabla"
            );
        }

        foreach ($meta['columns'] as $i => $c) {
            if ($c['pk']) {
                $meta['columns'][$i]['pk'] = false;
            }
        }
        self::guardar($cat, $tabla, $meta);
    }

    /**
     * Los valores de esas columnas de una fila, para un mensaje: ("05", 3).
     * La clave con la que se comparan (2.9: Indexes::claveFila(), la misma
     * igualdad que al escribir) no se deja leer.
     */
    private static function valoresDe(array $fila, array $columnas): string
    {
        return '(' . implode(', ', array_map(static fn($c): string => (string)json_encode($fila[$c] ?? null, JSON_UNESCAPED_UNICODE), $columnas)) . ')';
    }

    private static function metaExistente(Catalog $cat, string $tabla): array
    {
        if (!$cat->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        return $cat->meta($tabla);
    }

    private static function exigirNombreLibre(array $meta, string $nombre): void
    {
        // También el de las claves que no se guardan con nombre (2.9): la
        // primaria, PRIMARY, y la UNIQUE de una columna, uq_<tabla>_<columna>.
        // Con el mismo nombre, una de las dos dejaba de comprobarse
        foreach (array_merge(Catalog::conjuntosUnicos($meta), $meta['foreign_keys'], $meta['checks']) as $r) {
            if (strcasecmp((string)($r['name'] ?? ''), $nombre) === 0) {
                throw JsonSqlDbError::schema("Ya existe una restricción llamada '$nombre' en '{$meta['table']}'");
            }
        }
    }

    private static function normalizarUnico(string $tabla, array $meta, array $uq): array
    {
        $cols = [];
        foreach ($uq['columns'] ?? [] as $c) {
            $col = Catalog::columna($meta, (string)$c);
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

    private static function normalizarFk(Catalog $cat, string $tabla, array $meta, array $fk): array
    {
        $cols = [];
        foreach ($fk['columns'] ?? [] as $c) {
            $col = Catalog::columna($meta, (string)$c);
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
            if (!$cat->existe($destino)) {
                throw JsonSqlDbError::schema("FOREIGN KEY hacia una tabla inexistente: '$destino'");
            }
            $metaDestino = $cat->meta($destino);
        }

        $refs = [];
        foreach ($fk['references'] ?? [] as $c) {
            $col = Catalog::columna($metaDestino, (string)$c);
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

    public static function crearTrigger(Catalog $cat, string $tabla, array $trg): void
    {
        $meta   = $cat->meta($tabla);
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
        // Cada paso es el texto de una sentencia o un bloque IF (un array, ver
        // ParserSentencias::cuerpoTrigger()); los textos vacíos sobran
        $cuerpo = array_values(array_filter(
            array_map(static fn($p) => is_string($p) ? trim($p) : $p, (array)($trg['body'] ?? [])),
            static fn($p): bool => $p !== ''
        ));
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
        self::guardar($cat, $tabla, $meta);
    }

    /** Borra un trigger por nombre. Devuelve la tabla a la que pertenecía. */
    public static function borrarTrigger(Catalog $cat, string $nombre): string
    {
        foreach ($cat->storage()->tablas() as $tabla) {
            $meta = $cat->meta($tabla);
            foreach ($meta['triggers'] as $i => $t) {
                if (strcasecmp($t['name'], $nombre) === 0) {
                    unset($meta['triggers'][$i]);
                    $meta['triggers'] = array_values($meta['triggers']);
                    self::guardar($cat, $tabla, $meta);
                    return $tabla;
                }
            }
        }
        throw JsonSqlDbError::schema("El trigger '$nombre' no existe");
    }

    // ------------------------------------------------------------------
    // Serialización compacta (ficheros .meta.json legibles)
    // ------------------------------------------------------------------

    public static function compactar(array $meta): array
    {
        // Lo que se guarda tiene que poder indexarse: dos claves únicas con el
        // mismo índice automático no (2.9, ver Indexes::autoRepetido())
        $repetido = Indexes::autoRepetido($meta);
        if ($repetido !== null) {
            throw JsonSqlDbError::schema("Las claves únicas (" . implode(', ', $repetido[1]) . ") y (" . implode(', ', $repetido[2])
                . ") de '" . ($meta['table'] ?? '') . "' tendrían el mismo índice, '{$repetido[0]}': cambia el nombre de una columna");
        }
        $cols = [];
        foreach ($meta['columns'] as $col) {
            $c = ['name' => $col['name'], 'type' => $col['type']];
            foreach (['length', 'scale', 'default', 'default_expr', 'enum', 'collate'] as $k) {
                if (($col[$k] ?? null) !== null) {
                    $c[$k] = $col[$k];
                }
            }
            foreach (['notnull', 'pk', 'autoincrement', 'unique', 'unsigned'] as $k) {
                if (!empty($col[$k])) {
                    $c[$k] = true;
                }
            }
            $cols[] = $c;
        }

        $out = ['table' => $meta['table'], 'columns' => $cols];
        foreach (['unique', 'foreign_keys', 'triggers', 'indexes', 'checks'] as $k) {
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
