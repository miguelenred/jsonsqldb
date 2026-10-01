<?php
declare(strict_types=1);

/**
 * Exportación de resultados a CSV o a sentencias INSERT.
 *
 * Escribe directamente en la salida y termina la petición, así que no debe
 * haberse enviado nada antes.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Exportar
{
    /** @param array<int,array<string,mixed>> $filas */
    public static function csv(array $filas, string $nombre): void
    {
        self::cabeceras($nombre . '.csv', 'text/csv; charset=UTF-8');

        $salida = fopen('php://output', 'wb');
        // BOM para que Excel reconozca el UTF-8 y no rompa los acentos
        fwrite($salida, "\xEF\xBB\xBF");

        $sep = (string)ADMIN_CSV_SEPARADOR;
        if ($filas !== []) {
            fputcsv($salida, array_keys($filas[0]), $sep, '"', '\\');
            foreach ($filas as $f) {
                $linea = [];
                foreach ($f as $v) {
                    $linea[] = $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (is_float($v) ? var_export($v, true) : (string)$v));
                }
                fputcsv($salida, $linea, $sep, '"', '\\');
            }
        }
        fclose($salida);
        exit;
    }

    /**
     * Copia de una base en ZIP, con la estructura de carpetas tal cual está en
     * disco: se descomprime dentro de data/ y la base queda restaurada.
     *
     * El ZIP se monta en un temporal que se borra siempre, tanto si la descarga
     * va bien como si falla a medio camino.
     */
    public static function zip(string $base, string $ruta): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'La extensión zip de PHP no está activada, así que no se puede generar el ZIP. '
                . 'Actívala en php.ini (extension=zip) o usa el volcado en SQL.'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'jsonsqldb_');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear el fichero temporal del ZIP.');
        }
        // Se borra pase lo que pase, también si la petición muere por el camino
        register_shutdown_function(static function () use ($tmp): void {
            if (is_file($tmp)) { @unlink($tmp); }
        });

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo abrir el ZIP temporal para escribir.');
            }

            $ruta  = rtrim(str_replace('\\', '/', $ruta), '/');
            $corte = strlen(dirname($ruta)) + 1;          // deja fuera todo lo anterior a la base
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            $zip->addEmptyDir($base);
            foreach ($items as $item) {
                /** @var SplFileInfo $item */
                $real    = str_replace('\\', '/', $item->getPathname());
                $interna = substr($real, $corte);
                if ($item->isDir()) {
                    $zip->addEmptyDir($interna);
                } elseif ($item->isFile()) {
                    $zip->addFile($real, $interna);
                }
            }
            if ($zip->close() !== true) {
                throw new RuntimeException('No se pudo cerrar el ZIP temporal.');
            }

            self::cabeceras($base . '.zip', 'application/zip');
            header('Content-Length: ' . (string)filesize($tmp));
            readfile($tmp);
        } finally {
            if (is_file($tmp)) { @unlink($tmp); }
        }
        exit;
    }

    /**
     * Volcado completo de una base: estructura y datos, en SQL.
     *
     * Las claves foráneas y los triggers van al final, después de que existan
     * todas las tablas, así que el fichero se puede ejecutar de arriba abajo.
     *
     * @param array<int,array{tabla:string,columnas:array,claves:array,filas:array}> $tablas
     * @param array<int,array<string,mixed>> $triggers
     */
    public static function base(string $base, array $tablas, array $triggers, array $vistas = [],
                                array $indices = [], string $dialecto = 'sqlite'): void
    {
        $ext = ['mysql' => '.mysql.sql', 'postgresql' => '.pg.sql', 'sqlserver' => '.sqlserver.sql'][$dialecto] ?? '.sql';
        self::cabeceras($base . $ext, 'text/plain; charset=UTF-8');
        echo self::volcado($base, $tablas, $triggers, $vistas, $indices, $dialecto);
        exit;
    }

    /** Dialectos de volcado: 'sqlite' (que también es el de jsonSQLDB), 'mysql', 'postgresql', 'sqlserver'. */
    public const DIALECTOS = ['sqlite', 'mysql', 'postgresql', 'sqlserver'];

    /** El dialecto del volcado en curso. Solo cambia durante volcado(). */
    private static string $d = 'sqlite';
    /** @var array<string,true> nombres de restricciones e índices que se repiten en varias tablas */
    private static array $repetidos = [];

    /** Un nombre entre comillas: dobles en SQL estándar, invertidas en MySQL, corchetes en SQL Server. */
    private static function q(string $nombre): string
    {
        switch (self::$d) {
            case 'mysql':     return '`' . str_replace('`', '``', $nombre) . '`';
            case 'sqlserver': return '[' . str_replace(']', ']]', $nombre) . ']';
            default:          return cita($nombre);
        }
    }

    /**
     * El nombre de una restricción o un índice. En SQLite, PostgreSQL y SQL
     * Server es de toda la base y aquí de cada tabla: si dos tablas usan el
     * mismo, se le antepone la tabla.
     */
    private static function nombreObjeto(string $tabla, string $nombre): string
    {
        return self::$d !== 'mysql' && isset(self::$repetidos[strtolower($nombre)]) ? $tabla . '_' . $nombre : $nombre;
    }

    /**
     * El volcado de una base como texto. Se carga igual en jsonSQLDB (con la
     * importación del panel) que en SQLite (`sqlite3 base.db < base.sql`):
     * es la puerta de salida del proyecto, y por eso cada restricción va
     * dentro de su CREATE TABLE, que es lo único que SQLite admite. Las tablas
     * salen en el orden de sus claves foráneas, las referenciadas antes, y las
     * filas de una tabla que se referencia a sí misma, cada padre antes que
     * sus hijos. Solo si hay un ciclo entre tablas (o una fila que se apunta a
     * sí misma) esa clave va al final con ALTER TABLE, que SQLite no acepta, y
     * el volcado lo dice en un comentario.
     *
     * @param array<int,array{tabla:string,columnas:array,claves:array,filas:array}> $tablas
     */
    public static function volcado(string $base, array $tablas, array $triggers, array $vistas = [],
                                   array $indices = [], string $dialecto = 'sqlite'): string
    {
        self::$d = in_array($dialecto, self::DIALECTOS, true) ? $dialecto : 'sqlite';
        try {
            return self::componer($base, $tablas, $triggers, $vistas, $indices);
        } finally {
            self::$d = 'sqlite';
            self::$repetidos = [];
        }
    }

    /**
     * @param array<int,array{tabla:string,columnas:array,claves:array,filas:array}> $tablas
     * @param array<int,array{indice:string,tabla:string,columnas:string,automatico:int}> $indices
     */
    private static function componer(string $base, array $tablas, array $triggers, array $vistas, array $indices): string
    {
        $filas = 0;
        $porNombre = [];
        foreach ($tablas as $t) {
            $filas += count($t['filas']);
            $porNombre[$t['tabla']] = $t;
        }
        [$orden, $diferidas] = self::ordenar($porNombre);

        // Índices que no vienen de una clave (esos ya van en el CREATE TABLE),
        // y qué nombres de restricción o índice se repiten entre tablas
        $propios = array_values(array_filter($indices, static fn(array $i): bool => (int)$i['automatico'] === 0));
        $usos = [];
        foreach ($propios as $i) {
            $usos[strtolower((string)$i['indice'])][$i['tabla']] = true;
        }
        foreach ($tablas as $t) {
            foreach ($t['claves'] as $k) {
                if ($k['tipo'] !== 'PRIMARY') { $usos[strtolower((string)$k['nombre'])][$t['tabla']] = true; }
            }
        }
        self::$repetidos = array_map(static fn(): bool => true, array_filter($usos, static fn(array $u): bool => count($u) > 1));

        $out  = '-- jsonSQLDB · ' . t("volcado de la base '{base}'", ['base' => $base]) . ' · ' . date('Y-m-d H:i:s') . "\n";
        $out .= '-- ' . t('{t} tabla(s), {f} fila(s)', ['t' => count($tablas), 'f' => $filas]) . "\n";
        $out .= self::cabeceraDialecto($base);
        foreach ($orden as $nombre) {
            $t = $porNombre[$nombre];
            $out .= "\n-- ------------------------------------------------------------\n";
            $out .= '-- ' . t('Tabla: {tabla}', ['tabla' => $nombre]) . "\n";
            $out .= "-- ------------------------------------------------------------\n";
            // Las filas primero: si una tabla que se apunta a sí misma tiene un
            // ciclo, su clave pasa a las diferidas y ya no va en el CREATE TABLE
            $filasT = self::padresPrimero($t, $diferidas);
            $enIndice = [];
            foreach ($propios as $i) {
                if ($i['tabla'] === $nombre) {
                    foreach (explode(',', (string)$i['columnas']) as $c) { $enIndice[$c] = true; }
                }
            }
            $out .= self::createTable($nombre, $t['columnas'], $t['claves'], $diferidas, $enIndice, $filasT);
            $auto = null;
            foreach ($t['columnas'] as $c) {
                if ((int)$c['auto'] === 1) { $auto = (string)$c['columna']; }
            }
            if ($filasT !== []) {
                // SQL Server no deja escribir en una columna IDENTITY sin pedirlo
                $identidad = self::$d === 'sqlserver' && $auto !== null;
                $out .= "\n" . ($identidad ? 'SET IDENTITY_INSERT ' . self::q($nombre) . " ON;\n" : '')
                      . self::lineasInsert($filasT, $nombre)
                      . ($identidad ? 'SET IDENTITY_INSERT ' . self::q($nombre) . " OFF;\n" : '');
            }
            // En PostgreSQL la secuencia de una columna IDENTITY no se entera de
            // los id insertados a mano: se pone detrás del último
            if (self::$d === 'postgresql' && $auto !== null) {
                $out .= "SELECT setval(pg_get_serial_sequence('" . str_replace("'", "''", self::q($nombre)) . "', '"
                      . str_replace("'", "''", $auto) . "'), COALESCE((SELECT MAX(" . self::q($auto) . ') FROM '
                      . self::q($nombre) . "), 0) + 1, false);\n";
            }
            foreach ($propios as $i) {
                if ($i['tabla'] !== $nombre) {
                    continue;
                }
                $out .= 'CREATE INDEX ' . self::q(self::nombreObjeto($nombre, (string)$i['indice'])) . ' ON ' . self::q($nombre)
                      . ' (' . implode(', ', array_map(static fn($c) => self::q((string)$c), explode(',', (string)$i['columnas']))) . ");\n";
            }
        }
        if ($diferidas !== []) {
            $out .= "\n-- ------------------------------------------------------------\n";
            $out .= '-- ' . t('Claves foráneas que no pueden ir en su CREATE TABLE: forman un ciclo entre tablas, o una fila se apunta a sí misma.')
                  . (self::$d === 'sqlite' ? ' ' . t('SQLite no acepta ALTER TABLE … ADD CONSTRAINT: ahí se cargarán los datos sin ellas.') : '') . "\n";
            $out .= "-- ------------------------------------------------------------\n";
            foreach ($diferidas as [$tabla, $k]) {
                $out .= 'ALTER TABLE ' . self::q($tabla) . ' ADD ' . self::claveForanea($tabla, $k) . ";\n";
            }
        }
        // Vistas y triggers: tal cual en el volcado de jsonSQLDB y SQLite; en los
        // demás, comentados, porque su SQL podría crearse sin error y hacer otra
        // cosa (|| es un O lógico en MySQL; un trigger de PostgreSQL es una
        // función; uno de SQL Server no tiene NEW ni OLD)
        $comentar = self::$d !== 'sqlite';
        if ($vistas !== []) {
            $out .= "\n-- ------------------------------------------------------------\n-- " . t('Vistas') . ($comentar ? ': ' . t('escritas en el SQL de jsonSQLDB; revísalas antes de crearlas') : '') . "\n";
            $out .= "-- ------------------------------------------------------------\n";
            foreach ($vistas as $v) {
                $sql = 'CREATE VIEW ' . self::q((string)$v['vista']) . ' AS ' . rtrim(trim((string)$v['sql']), ';') . ';';
                $out .= ($comentar ? '-- ' . str_replace("\n", "\n-- ", $sql) : $sql) . "\n";
            }
        }
        if ($triggers !== []) {
            $out .= "\n-- ------------------------------------------------------------\n-- " . t('Triggers') . ($comentar ? ': ' . t('escritos en el SQL de jsonSQLDB; hay que reescribirlos para este motor') : '') . "\n";
            $out .= "-- ------------------------------------------------------------\n";
            foreach ($triggers as $trg) {
                $sql = rtrim(trim((string)$trg['sql']), ';') . ';';
                $out .= ($comentar ? '-- ' . str_replace("\n", "\n-- ", $sql) : $sql) . "\n";
            }
        }
        $out .= ['mysql' => "\nSET FOREIGN_KEY_CHECKS = 1;\n", 'postgresql' => "\nCOMMIT;\n",
                 'sqlserver' => "\nCOMMIT TRANSACTION;\n"][self::$d] ?? '';
        return $out;
    }

    /**
     * Las primeras líneas del volcado: cómo cargarlo y lo que hay que saber,
     * en el idioma del panel. La línea «-- jsonsqldb-dialecto:» no se traduce:
     * es la que mira el importador para saber de qué motor es.
     */
    private static function cabeceraDialecto(string $base): string
    {
        $c = static fn(string $texto): string => '-- ' . $texto . "\n";
        $out = '-- jsonsqldb-dialecto: ' . self::$d . "\n";
        switch (self::$d) {
            case 'mysql':
                // Modo estricto: un valor que no cabe es un error, no un número
                // cambiado en silencio (sin él, MySQL recorta y sigue)
                return $out . $c(t('Para MySQL 8 y MariaDB 10.3 o posteriores: {orden}', ['orden' => "mysql nombre_base < $base.mysql.sql"]))
                     . $c(t('Enteros como BIGINT (los de PHP son de 64 bits), texto en utf8mb4_bin (las comparaciones de jsonSQLDB distinguen mayúsculas y acentos, y así una clave única sigue admitiendo lo mismo). Las vistas y los triggers van comentados al final: su SQL es el de jsonSQLDB y hay que revisarlo antes de crearlos.'))
                     . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n"
                     . "SET SQL_MODE = 'STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';\n";
            case 'postgresql':
                return $out . $c(t('Para PostgreSQL 10 o posterior: {orden}', ['orden' => "psql -v ON_ERROR_STOP=1 -d nombre_base -f $base.pg.sql"]))
                     . $c(t('Todo va en una transacción: o se carga entero o no se carga nada. Enteros como BIGINT, fechas como TIMESTAMP(3), autoincremento como IDENTITY. Las vistas y los triggers van comentados al final.'))
                     . "SET client_encoding = 'UTF8';\nBEGIN;\n";
            case 'sqlserver':
                return $out . $c(t('Para SQL Server 2016 o posterior: {orden}, o ábrelo en SQL Server Management Studio. Todo va en una transacción.', ['orden' => "sqlcmd -d nombre_base -i $base.sqlserver.sql"]))
                     . $c(t('Texto como NVARCHAR con cotejamiento binario (las comparaciones de jsonSQLDB distinguen mayúsculas y acentos), enteros como BIGINT, fechas como DATETIME2(3), autoincremento como IDENTITY. Una clave foránea de una tabla hacia sí misma va sin ON DELETE/ON UPDATE: SQL Server no admite acciones en cadena que puedan formar un ciclo. Las vistas y los triggers van comentados.'))
                     . "SET NOCOUNT ON;\nSET XACT_ABORT ON;\nSET ANSI_NULLS ON;\nSET QUOTED_IDENTIFIER ON;\nBEGIN TRANSACTION;\n";
            default:
                return $out . $c(t('Se carga en jsonSQLDB (Importar un volcado SQL, en la página de la base) y en SQLite: {orden}', ['orden' => "sqlite3 $base.db < $base.sql"]));
        }
    }

    /**
     * Orden de las tablas: cada una después de las que referencia. Devuelve
     * ese orden y las claves foráneas que no caben en él: las de un ciclo
     * entre tablas distintas. Las de una tabla hacia sí misma se quedan en
     * su CREATE TABLE (ver padresPrimero()).
     *
     * @param array<string,array> $tablas
     * @return array{0: list<string>, 1: list<array{0: string, 1: array}>}
     */
    private static function ordenar(array $tablas): array
    {
        $orden = [];
        $estado = [];                           // 1 = visitando, 2 = hecha
        $diferidas = [];
        $visitar = function (string $t) use (&$visitar, &$orden, &$estado, &$diferidas, $tablas): void {
            $estado[$t] = 1;
            foreach ($tablas[$t]['claves'] as $k) {
                $destino = (string)($k['tabla_destino'] ?? '');
                if ($k['tipo'] !== 'FOREIGN' || $destino === $t || !isset($tablas[$destino])) {
                    continue;
                }
                if (($estado[$destino] ?? 0) === 1) {
                    $diferidas[] = [$t, $k];    // ciclo: esta clave va al final
                } elseif (($estado[$destino] ?? 0) === 0) {
                    $visitar($destino);
                }
            }
            $estado[$t] = 2;
            $orden[] = $t;
        };
        foreach (array_keys($tablas) as $t) {
            if (($estado[$t] ?? 0) === 0) {
                $visitar((string)$t);
            }
        }
        return [$orden, $diferidas];
    }

    /**
     * Las filas de una tabla que se referencia a sí misma (un jefe, una
     * categoría padre), cada padre antes que sus hijos, para que al cargarlas
     * la clave foránea no falle. Si una fila se apunta a sí misma o hay un
     * ciclo, la clave pasa a las diferidas y las filas salen como estaban.
     */
    private static function padresPrimero(array $t, array &$diferidas): array
    {
        $pk = null;
        foreach ($t['claves'] as $k) {
            if ($k['tipo'] === 'PRIMARY' && strpos((string)$k['columnas'], ',') === false) {
                $pk = (string)$k['columnas'];
            }
        }
        foreach ($t['claves'] as $k) {
            if ($k['tipo'] !== 'FOREIGN' || (string)$k['tabla_destino'] !== $t['tabla']) {
                continue;
            }
            $col = (string)$k['columnas'];
            if ($pk === null || strpos($col, ',') !== false || (string)$k['columnas_destino'] !== $pk) {
                $diferidas[] = [$t['tabla'], $k];
                continue;
            }
            $porPk = [];
            foreach ($t['filas'] as $i => $f) {
                $porPk[(string)($f[$pk] ?? '')] = $i;
            }
            // Cada fila después de su padre, sin recursión: una cadena de mil
            // filas guardada al revés no debe agotar la pila de PHP
            $salida = [];
            $estado = [];                       // 1 = en la pila, 2 = ya escrita
            $ciclo  = false;
            foreach (array_keys($t['filas']) as $inicio) {
                $pila = [(int)$inicio];
                while ($pila !== [] && !$ciclo) {
                    $i = end($pila);
                    if (($estado[$i] ?? 0) === 2) {
                        array_pop($pila);
                        continue;
                    }
                    $estado[$i] = 1;
                    $padre = $t['filas'][$i][$col] ?? null;
                    $j = $padre === null ? null : ($porPk[(string)$padre] ?? null);
                    if ($j === $i) {
                        $ciclo = true;          // se apunta a sí misma
                    } elseif ($j !== null && ($estado[$j] ?? 0) === 1) {
                        $ciclo = true;          // vuelta a una que está esperando: ciclo
                    } elseif ($j !== null && ($estado[$j] ?? 0) === 0) {
                        $pila[] = $j;           // primero el padre
                    } else {
                        $estado[$i] = 2;
                        $salida[] = $t['filas'][$i];
                        array_pop($pila);
                    }
                }
            }
            if ($ciclo) {
                $diferidas[] = [$t['tabla'], $k];
                continue;
            }
            $t['filas'] = $salida;
        }
        return $t['filas'];
    }

    /** Una clave foránea como restricción de tabla. */
    private static function claveForanea(string $tabla, array $k): string
    {
        $q = static fn($c) => self::q((string)$c);
        $acciones = ' ON DELETE ' . $k['on_delete'] . ' ON UPDATE ' . $k['on_update'];
        if (self::$d === 'sqlserver') {
            // SQL Server no tiene RESTRICT (NO ACTION hace lo mismo) ni admite
            // acciones en una clave de una tabla hacia sí misma
            $acciones = (string)$k['tabla_destino'] === $tabla ? ''
                : str_replace('RESTRICT', 'NO ACTION', $acciones);
        }
        return 'CONSTRAINT ' . self::q(self::nombreObjeto($tabla, (string)$k['nombre']))
             . ' FOREIGN KEY (' . implode(', ', array_map($q, array_filter(explode(',', (string)$k['columnas'])))) . ')'
             . ' REFERENCES ' . self::q((string)$k['tabla_destino'])
             . ' (' . implode(', ', array_map($q, array_filter(explode(',', (string)$k['columnas_destino'])))) . ')'
             . $acciones;
    }

    /** CREATE TABLE reconstruido a partir de la estructura, con sus restricciones dentro. */
    private static function createTable(string $tabla, array $columnas, array $claves, array $diferidas,
                                        array $enIndice = [], array $filas = []): string
    {
        // Columnas que forman parte de una clave o un índice: en MySQL y SQL
        // Server un texto sin longitud no puede estar en uno
        $enClave = $enIndice;
        foreach ($claves as $k) {
            foreach (explode(',', (string)$k['columnas']) as $c) { $enClave[$c] = true; }
        }
        $pk = [];
        foreach ($claves as $k) {
            if ($k['tipo'] === 'PRIMARY') {
                $pk = array_filter(explode(',', (string)$k['columnas']));
            }
        }
        $compuesta = count($pk) > 1;
        $sueltas = [];

        $partes = [];
        foreach ($columnas as $c) {
            $nombre = (string)$c['columna'];
            $tipo   = self::$d === 'sqlite' ? self::tipo($c) : self::tipoDestino($c, isset($enClave[$nombre]), $filas);
            $esPk   = (int)$c['pk'] === 1 && !$compuesta;
            $auto   = (int)$c['auto'] === 1;
            $d = self::q($nombre) . ' ' . $tipo;
            switch (self::$d) {
                case 'sqlite':
                    $d .= ($esPk ? ' PRIMARY KEY' : '') . ($auto ? ' AUTOINCREMENT' : '')
                        . ((int)$c['notnull'] === 1 && (int)$c['pk'] !== 1 ? ' NOT NULL' : '');
                    break;
                case 'mysql':
                    $d .= ((int)$c['pk'] === 1 || (int)$c['notnull'] === 1 ? ' NOT NULL' : '')
                        . ($auto ? ' AUTO_INCREMENT' : '') . ($esPk ? ' PRIMARY KEY' : '');
                    break;
                case 'postgresql':
                    $d .= ($auto ? ' GENERATED BY DEFAULT AS IDENTITY' : '')
                        . ((int)$c['pk'] === 1 || (int)$c['notnull'] === 1 ? ' NOT NULL' : '') . ($esPk ? ' PRIMARY KEY' : '');
                    break;
                case 'sqlserver':
                    $d .= ($auto ? ' IDENTITY(1,1)' : '')
                        . ((int)$c['pk'] === 1 || (int)$c['notnull'] === 1 ? ' NOT NULL' : '') . ($esPk ? ' PRIMARY KEY' : '');
                    break;
            }
            if ((int)$c['unico'] === 1) {
                // En SQL Server, NULL cuenta como un valor en UNIQUE: dos filas sin
                // dato chocarían. Se crea como índice único filtrado, después
                if (self::$d !== 'sqlserver') { $d .= ' UNIQUE'; }
                $sueltas[$nombre] = true;
            }
            if ($c['defecto'] !== null) {
                // MySQL 8 solo admite el valor por defecto de un TEXT entre paréntesis
                $lit = self::literal($c['defecto']);
                $d  .= ' DEFAULT ' . (self::$d === 'mysql' && $tipo === 'TEXT' ? '(' . $lit . ')' : $lit);
            }
            $partes[] = $d;
        }
        if ($compuesta) {
            $partes[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn($c) => self::q((string)$c), $pk)) . ')';
        }
        $aplazadas = [];
        foreach ($diferidas as [$t, $k]) {
            if ($t === $tabla) { $aplazadas[(string)$k['nombre']] = true; }
        }
        $despues = '';
        foreach ($claves as $k) {
            $cols = array_values(array_filter(explode(',', (string)$k['columnas'])));
            if ($k['tipo'] === 'UNIQUE' && !(count($cols) === 1 && isset($sueltas[$cols[0]]) && self::$d !== 'sqlserver')) {
                $nombreK = self::q(self::nombreObjeto($tabla, (string)$k['nombre']));
                $lista = implode(', ', array_map(static fn($c) => self::q((string)$c), $cols));
                if (self::$d === 'sqlserver') {
                    $filtro = implode(' AND ', array_map(static fn($c) => self::q((string)$c) . ' IS NOT NULL', $cols));
                    $despues .= "CREATE UNIQUE INDEX $nombreK ON " . self::q($tabla) . " ($lista) WHERE $filtro;\n";
                } else {
                    $partes[] = "CONSTRAINT $nombreK UNIQUE ($lista)";
                }
            } elseif ($k['tipo'] === 'FOREIGN' && !isset($aplazadas[(string)$k['nombre']])) {
                $partes[] = self::claveForanea($tabla, $k);
            }
        }

        return 'CREATE TABLE ' . self::q($tabla) . " (\n  " . implode(",\n  ", $partes) . "\n)"
             . (self::$d === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '') . ";\n" . $despues;
    }

    /**
     * El tipo de una columna en MySQL, PostgreSQL o SQL Server. Los enteros,
     * BIGINT: los de PHP son de 64 bits. Los DECIMAL, con las cifras que piden
     * los datos (al menos 20; aquí no se guarda la precisión). Un texto sin
     * longitud que está en una clave o un índice, con la longitud que necesitan
     * sus datos, porque MySQL y SQL Server no indexan un texto entero. Las
     * fechas, con milisegundos. El texto de SQL Server, NVARCHAR con
     * cotejamiento binario, para que compare como aquí.
     */
    private static function tipoDestino(array $c, bool $enClave, array $filas): string
    {
        $tipo = strtoupper((string)$c['tipo']);
        $ss   = self::$d === 'sqlserver';
        $cot  = $ss ? ' COLLATE Latin1_General_100_BIN2' : '';
        if ($c['longitud'] !== null) {
            $n = (int)$c['longitud'];
            if ($ss) { return ($n <= 4000 ? "NVARCHAR($n)" : 'NVARCHAR(MAX)') . $cot; }
            return self::$d === 'mysql' && $n > 16383 ? 'MEDIUMTEXT' : "VARCHAR($n)";
        }
        if ($c['escala'] !== null || $tipo === 'DECIMAL') {
            // Cifras: las que piden los datos, al menos 20 y como mucho las del
            // motor (65 en MySQL, 38 en SQL Server); más no cabe y dará error
            $escala = $c['escala'] !== null ? (int)$c['escala'] : 6;
            $enteras = 20 - $escala;
            foreach ($filas as $f) {
                $v = $f[(string)$c['columna']] ?? null;
                if (is_int($v) || is_float($v)) {
                    $enteras = max($enteras, strlen(number_format(abs((float)$v), 0, '.', '')));
                }
            }
            $max = ['mysql' => 65, 'sqlserver' => 38][self::$d] ?? 1000;
            return (self::$d === 'postgresql' ? 'NUMERIC(' : 'DECIMAL(') . min($max, $enteras + $escala) . ',' . $escala . ')';
        }
        switch ($tipo) {
            case 'INTEGER':  return 'BIGINT';
            case 'DOUBLE':   return ['postgresql' => 'DOUBLE PRECISION', 'sqlserver' => 'FLOAT(53)'][self::$d] ?? 'DOUBLE';
            case 'DATETIME': return ['postgresql' => 'TIMESTAMP(3)', 'sqlserver' => 'DATETIME2(3)'][self::$d] ?? 'DATETIME(3)';
        }
        if (!$enClave || self::$d === 'postgresql') {
            return $ss ? 'NVARCHAR(MAX)' . $cot : 'TEXT';
        }
        $max = 191;
        foreach ($filas as $f) {
            $v = $f[(string)$c['columna']] ?? null;
            if (is_string($v)) {
                $max = max($max, mb_strlen($v, 'UTF-8'));
            }
        }
        // En SQL Server la clave de un índice no pasa de 900 bytes (450 NVARCHAR)
        return $ss ? ($max <= 450 ? "NVARCHAR($max)" : 'NVARCHAR(MAX)') . $cot : 'VARCHAR(' . $max . ')';
    }

    /** Declaración del tipo, con longitud o decimales si los tiene. */
    private static function tipo(array $c): string
    {
        $tipo = (string)$c['tipo'];
        if ($c['longitud'] !== null) {
            return 'VARCHAR(' . (int)$c['longitud'] . ')';
        }
        if ($c['escala'] !== null) {
            return 'DECIMAL(10,' . (int)$c['escala'] . ')';
        }
        return $tipo;
    }

    /**
     * Una sentencia INSERT por fila, lista para volver a ejecutar.
     *
     * @param array<int,array<string,mixed>> $filas
     */
    public static function inserts(array $filas, string $tabla): void
    {
        self::cabeceras($tabla . '.sql', 'text/plain; charset=UTF-8');

        echo "-- jsonSQLDB · $tabla · " . date('Y-m-d H:i:s') . "\n";
        echo '-- ' . t('{n} fila(s)', ['n' => count($filas)]) . "\n\n";
        echo self::lineasInsert($filas, $tabla);
        exit;
    }

    /** @param array<int,array<string,mixed>> $filas */
    private static function lineasInsert(array $filas, string $tabla): string
    {
        if ($filas === []) {
            return '';
        }
        $cols = array_keys($filas[0]);
        $cab  = 'INSERT INTO ' . self::q($tabla) . ' ('
              . implode(', ', array_map(static fn($c) => self::q((string)$c), $cols)) . ') VALUES (';

        $out = '';
        foreach ($filas as $f) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = self::literal($f[$c] ?? null);
            }
            $out .= $cab . implode(', ', $vals) . ");\n";
        }
        return $out;
    }

    /** Un valor escrito como literal SQL. */
    private static function literal($v): string
    {
        if ($v === null)    { return 'NULL'; }
        if (is_bool($v))    { return $v ? '1' : '0'; }
        if (is_int($v))     { return (string)$v; }
        // El decimal más corto que vuelve a dar exactamente el mismo número:
        // con diez decimales fijos, 0.30000000000000004 salía como 0.3
        if (is_float($v))   { return var_export($v, true); }
        // En MySQL la barra invertida también escapa dentro de una cadena; en
        // SQL Server, una cadena con N delante es Unicode
        $s = self::$d === 'mysql' ? str_replace(['\\', "'"], ['\\\\', "''"], (string)$v) : str_replace("'", "''", (string)$v);
        return (self::$d === 'sqlserver' ? 'N' : '') . "'" . $s . "'";
    }

    private static function cabeceras(string $fichero, string $tipo): void
    {
        $fichero = preg_replace('/[^A-Za-z0-9_.-]/', '_', $fichero) . '';
        $sello   = date('Ymd-Hi');
        $punto   = strrpos($fichero, '.');
        $fichero = substr($fichero, 0, $punto) . '-' . $sello . substr($fichero, $punto);

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: ' . $tipo);
        header('Content-Disposition: attachment; filename="' . $fichero . '"');
        header('Cache-Control: no-store');
    }
}
