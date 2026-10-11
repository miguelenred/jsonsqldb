<?php
declare(strict_types=1);

require_once __DIR__ . '/GeneradorSql.php';   // vistas y triggers en el SQL de cada motor

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
    /**
     * Lo que se escribe al final de un volcado que se cortó a medias: una
     * sentencia que ningún motor acepta, para que importarlo dé error (y en
     * el panel, que importa todo o nada, no cargue nada) en vez de cargar una
     * parte sin que nadie llegue a leer el comentario de la última línea.
     */
    public const INCOMPLETO = 'INCOMPLETE_DUMP_DO_NOT_IMPORT;';

    /** Lo que cierra una descarga que se ha cortado: el aviso y la sentencia de arriba */
    private static function incompleta(string $error): string
    {
        return "\n" . self::comentario(t('ERROR: la exportación está incompleta: {error}', ['error' => $error])) . self::INCOMPLETO . "\n";
    }

    /**
     * Un texto como comentario del volcado, «-- » en cada una de sus líneas.
     * Lo que se comenta puede venir de la base (un CHECK o una vista que el
     * otro motor no admite, el error al traducirla) y llevar saltos de línea,
     * también un \r suelto: con «-- » solo delante, lo que hubiera detrás de
     * uno se ejecutaba al cargar el volcado en el otro motor (2.9).
     */
    private static function comentario(string $texto): string
    {
        return '-- ' . implode("\n-- ", preg_split('/\r\n|\r|\n/', $texto)) . "\n";
    }

    /**
     * JSON (2.9): una lista de objetos, uno por fila y una fila por línea. Es
     * lo que el panel acepta al importar en una tabla.
     *
     * @param iterable<array<string,mixed>> $filas
     */
    public static function json(iterable $filas, string $nombre): void
    {
        self::primerLote($filas);
        self::cabeceras($nombre . '.json', 'text/plain; charset=UTF-8');
        try {
            $primera = true;
            echo '[';
            foreach ($filas as $f) {
                echo ($primera ? "\n" : ",\n"), self::filaJson($f);
                $primera = false;
            }
            echo "\n]\n";
        } catch (Throwable $e) {
            echo self::incompleta($e->getMessage());
        }
        exit;
    }

    /**
     * Excel (2.9), con EscritorXlsx: se escribe entero antes de empezar la
     * descarga, así que un error se ve en el panel y no deja un fichero roto.
     *
     * @param iterable<array<string,mixed>> $filas
     * @param array<string,string>          $tipos columna => tipo, si se sabe
     */
    public static function xlsx(iterable $filas, string $nombre, array $tipos = []): void
    {
        self::primerLote($filas);
        $libro = null;
        $tmp = self::temporal(t('No se pudo crear el fichero de Excel.'));
        try {
            foreach ($filas as $f) {
                $libro ??= new EscritorXlsx(array_map('strval', array_keys($f)), $tipos);
                $libro->fila($f);
            }
            if ($libro !== null) {
                $libro->guardar($tmp);
            }
            self::cabeceras($nombre . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
        } finally {
            if ($libro !== null) {
                $libro->borrarTemporales();
            }
            @unlink($tmp);
        }
        exit;
    }

    /** @param iterable<array<string,mixed>> $filas  un array o un generador (por lotes, ver Lotes) */
    public static function csv(iterable $filas, string $nombre): void
    {
        self::primerLote($filas);
        self::cabeceras($nombre . '.csv', 'text/csv; charset=UTF-8');

        $salida = fopen('php://output', 'wb');
        // BOM para que Excel reconozca el UTF-8 y no rompa los acentos
        fwrite($salida, "\xEF\xBB\xBF");

        try {
            self::lineasCsv($filas, $salida);
        } catch (Throwable $e) {
            // La descarga ya ha empezado: el error, al final, para que nadie
            // tome el fichero por completo
            fwrite($salida, self::incompleta($e->getMessage()));
        }
        fclose($salida);
        exit;
    }

    /**
     * Lo que Excel tomaría por una fórmula al abrir un CSV: se exporta con un
     * apóstrofo delante, y al importar se le quita uno (ver Importar::csv()).
     * También lo que ya empieza por apóstrofos y sigue con una fórmula (2.9):
     * sin el suyo, '-5 volvía como -5 al importarlo.
     */
    public const FORMULA = '/^\'*(?:\s*[=+\-@]|[\t\r])/';

    /**
     * Las filas en CSV, con su cabecera, como texto: para enseñar un ejemplo.
     *
     * @param list<array<string,mixed>> $filas
     */
    public static function textoCsv(array $filas): string
    {
        $m = fopen('php://memory', 'w+b');
        self::lineasCsv($filas, $m);
        rewind($m);
        $texto = (string)stream_get_contents($m);
        fclose($m);
        return $texto;
    }

    /** Una fila del JSON que exporta y acepta el panel */
    public static function filaJson(array $f): string
    {
        return (string)json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param resource $salida */
    private static function lineasCsv(iterable $filas, $salida): void
    {
        $sep = (string)ADMIN_CSV_SEPARADOR;
        $primera = true;
        foreach ($filas as $f) {
            if ($primera) {
                fputcsv($salida, array_keys($f), $sep, '"', '');
                $primera = false;
            }
            $linea = [];
            foreach ($f as $v) {
                $texto = $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (is_float($v) ? var_export($v, true) : (string)$v));
                // Un texto que empieza por = + - @ lo toma Excel por una fórmula
                // y la ejecuta al abrir el fichero: con un apóstrofo delante, es
                // texto (Excel no lo muestra). Los números no se tocan
                if (is_string($v) && preg_match(self::FORMULA, $v)) {
                    $texto = "'" . $texto;
                }
                $linea[] = $texto;
            }
            // Sin carácter de escape (2.9), como el CSV estándar y Excel: solo
            // se doblan las comillas. Con '\\', una barra seguida de comillas
            // se escribía sin doblarlas y el texto no volvía igual
            fputcsv($salida, $linea, $sep, '"', '');
        }
    }

    /**
     * Pide el primer lote antes de enviar las cabeceras de la descarga: si la
     * consulta falla, el error se ve en el panel en vez de acabar dentro del
     * fichero descargado.
     */
    private static function primerLote(iterable $filas): void
    {
        // Un generador vacío se da por terminado al pedirle la primera fila, y
        // el foreach de después fallaba («Cannot traverse an already closed
        // generator») con la descarga ya empezada: el fichero solo llevaba el
        // error. Sin filas no hay nada que exportar, y se dice en el panel
        $vacio = $filas instanceof Generator ? ($filas->current() === null && !$filas->valid()) : ($filas === []);
        if ($vacio) {
            throw new RuntimeException(t('La consulta no ha devuelto ninguna fila: no hay nada que exportar'));
        }
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
        $tmp = self::temporal(t('No se pudo crear el fichero temporal del ZIP.'));
        try {
            self::zipEn($base, $ruta, $tmp);
            self::cabeceras($base . '.zip', 'application/zip');
            header('Content-Length: ' . (string)filesize($tmp));
            readfile($tmp);
        } finally {
            if (is_file($tmp)) { @unlink($tmp); }
        }
        exit;
    }

    /**
     * Un fichero temporal para montar una descarga (el ZIP, el Excel), que se
     * borra pase lo que pase: también si la petición muere por el camino (la
     * memoria, el tiempo), cuando ningún finally llega a ejecutarse (2.9: el
     * Excel dejaba el suyo).
     */
    private static function temporal(string $error): string
    {
        $tmp = ficheroTemporal('jsonsqldb_');
        if ($tmp === false) {
            throw new RuntimeException($error);
        }
        register_shutdown_function(static function () use ($tmp): void {
            if (is_file($tmp)) { @unlink($tmp); }
        });
        return $tmp;
    }

    /** Escribe en $destino la copia ZIP de la base (ver zip()). */
    public static function zipEn(string $base, string $ruta, string $destino): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                t('La extensión zip de PHP no está activada, así que no se puede generar el ZIP. Actívala en php.ini (extension=zip) o usa el volcado en SQL.')
            );
        }
        $ruta = rtrim(str_replace('\\', '/', $ruta), '/');
        // Con el bloqueo exclusivo de la base (el del motor) hasta cerrar el
        // ZIP, que es cuando se leen los ficheros: sin él, una escritura a
        // mitad de la copia dejaba partes, índices y revisiones de dos momentos
        conBaseBloqueada($ruta, static function () use ($destino, $ruta, $base): void {
            $zip = new ZipArchive();
            if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(t('No se pudo abrir el ZIP temporal para escribir.'));
            }
            $corte = strlen(dirname($ruta)) + 1;      // deja fuera todo lo anterior a la base
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $zip->addEmptyDir($base);
            foreach ($items as $item) {
                /** @var SplFileInfo $item */
                $real    = str_replace('\\', '/', $item->getPathname());
                $interna = substr($real, $corte);
                // La caché y los temporales de una ordenación se rehacen solos: a
                // la copia no aportan nada y la caché puede ocupar mucho
                if (preg_match('#/\.(cache|orden)(/|$)#', substr($real, strlen($ruta)))) {
                    continue;
                }
                if ($item->isDir()) {
                    $zip->addEmptyDir($interna);
                } elseif ($item->isFile() && !esFicheroDeBloqueo($item->getFilename())) {
                    $zip->addFile($real, $interna);
                }
            }
            if ($zip->close() !== true) {
                throw new RuntimeException(t('No se pudo cerrar el ZIP temporal.'));
            }
        });
    }

    /** Escribe en $destino el volcado SQL de la base, tabla a tabla y por lotes si no cabe en memoria. */
    public static function volcadoEn(string $base, string $destino): void
    {
        $f = fopen($destino, 'wb');
        if ($f === false) {
            throw new RuntimeException(t('No se puede escribir en la carpeta \'{dir}\'.', ['dir' => dirname($destino)]));
        }
        try {
            $tablas = self::tablasDe($base);
            self::volcado($base, $tablas, Api::sql($base, 'SHOW TRIGGERS'), Api::sql($base, 'SHOW VIEWS'), Api::sql($base, 'SHOW INDEXES'),
                'sqlite', self::cargador($base, $tablas), static function (string $trozo) use ($f, $destino): void {
                    if (fwrite($f, $trozo) !== strlen($trozo)) {
                        throw new RuntimeException(t('No se puede escribir en la carpeta \'{dir}\'.', ['dir' => dirname($destino)]));
                    }
                });
        } finally {
            fclose($f);
        }
    }

    /**
     * Volcado completo de una base: estructura y datos, en SQL.
     *
     * Las claves foráneas y los triggers van al final, después de que existan
     * todas las tablas, así que el fichero se puede ejecutar de arriba abajo.
     *
     * @param array<int,array{tabla:string,columnas:array,claves:array,filas?:array,n?:int}> $tablas  las filas, o cuántas son si se cargan por tablas ($cargar)
     * @param array<int,array<string,mixed>> $triggers
     */
    public static function base(string $base, array $tablas, array $triggers, array $vistas = [],
                                array $indices = [], string $dialecto = 'sqlite', ?callable $cargar = null): void
    {
        $ext = ['mysql' => '.mysql.sql', 'postgresql' => '.pg.sql', 'sqlserver' => '.sqlserver.sql', 'access' => '.access.sql', 'json' => '.json'][$dialecto] ?? '.sql';
        // Antes de empezar la descarga, una fila de cada tabla (2.9): una tabla
        // que no se puede leer (no cabe en la memoria de PHP) cortaba el volcado
        // a medias, con las tablas de detrás sin escribir y un fichero que
        // parecía completo. Así el error se ve en el panel y no se descarga nada
        $fallan = [];
        foreach ($tablas as $t) {
            try {
                Api::sql($base, 'SELECT * FROM ' . cita((string)$t['tabla']) . ' LIMIT 1');
            } catch (Throwable $e) {
                $fallan[] = $t['tabla'] . ': ' . $e->getMessage();
            }
        }
        if ($fallan !== []) {
            throw new RuntimeException(t('No se puede volcar la base: estas tablas no se pueden leer y el volcado quedaría incompleto. {detalle}',
                ['detalle' => implode(' · ', $fallan)]));
        }
        self::cabeceras($base . $ext, 'text/plain; charset=UTF-8');
        // Tabla a tabla hacia el navegador, sin juntar antes el volcado entero.
        // Si algo falla a medias, la descarga ya ha empezado: el error va al
        // final del fichero, y detrás una sentencia que ningún motor acepta,
        // para que importarlo falle en vez de cargar media base sin avisar
        $salida = static function (string $trozo): void {
            echo $trozo;
            flush();
        };
        try {
            if ($dialecto === 'json') {
                self::volcadoJson($base, $tablas, $triggers, $vistas, $indices, $cargar, $salida);
            } else {
                self::volcado($base, $tablas, $triggers, $vistas, $indices, $dialecto, $cargar, $salida);
            }
        } catch (Throwable $e) {
            echo self::incompleta($e->getMessage());
        }
        exit;
    }

    /**
     * La base entera en JSON (2.9), lo que lee Importar::importarJsonBase():
     * "estructura" y "despues" son el SQL del volcado de jsonSQLDB sin sus
     * INSERT (el mismo que escribe volcado(), así que crea lo mismo), y en
     * medio, "tablas": cada una con su "nombre" y sus "filas", una por línea,
     * padres antes que hijos. Sale por partes, tabla a tabla.
     */
    private static function volcadoJson(string $base, array $tablas, array $triggers, array $vistas, array $indices,
                                        callable $cargar, callable $salida): void
    {
        // Sin filas: el volcado SQL es solo la estructura. Un generador vacío,
        // como las tablas que llegan por lotes, deja para el final las claves
        // de una tabla hacia sí misma, que es lo que se carga aquí después.
        // volcado() da un trozo por tabla y uno último con lo de después de
        // las filas (claves en ciclo, vistas y triggers)
        $trozos = [];
        self::volcado($base, $tablas, $triggers, $vistas, $indices, 'sqlite',
            static fn(): Generator => yield from [], static function (string $trozo) use (&$trozos): void { $trozos[] = $trozo; });
        $despues = (string)array_pop($trozos);
        $estructura = implode('', $trozos);
        $salida("{\n\"formato\": \"jsonSQLDB\",\n\"base\": " . self::filaJson([$base]) . ",\n\"fecha\": \"" . date('Y-m-d H:i:s')
            . "\",\n\"estructura\": " . substr(self::filaJson([$estructura]), 1, -1) . ",\n\"tablas\": [");
        [$orden] = self::ordenar(array_column($tablas, null, 'tabla'));
        foreach ($orden as $i => $nombre) {
            $salida(($i === 0 ? "\n" : ",\n") . '{"nombre": ' . substr(self::filaJson([$nombre]), 1, -1) . ', "filas": [');
            $primera = true;
            foreach ($cargar($nombre) as $f) {
                $salida(($primera ? "\n" : ",\n") . self::filaJson($f));
                $primera = false;
            }
            $salida("\n]}");
        }
        $salida("\n],\n\"despues\": " . substr(self::filaJson([$despues]), 1, -1) . "\n}\n");
    }

    /**
     * Estructura de cada tabla de la base y cuántas filas tiene, para
     * volcado(): las filas se piden después, tabla a tabla, con cargador().
     *
     * @return list<array{tabla:string,columnas:array,claves:array,n:int}>
     */
    public static function tablasDe(string $base): array
    {
        $tablas = [];
        foreach (Api::sql($base, 'SHOW TABLES') as $t) {
            $tabla = (string)$t['tabla'];
            $tablas[] = [
                'tabla'    => $tabla,
                'columnas' => Api::sql($base, 'SHOW SCHEMA ' . cita($tabla)),
                'claves'   => Api::sql($base, 'SHOW KEYS FROM ' . cita($tabla)),
                'n'        => (int)$t['filas'],
            ];
        }
        return $tablas;
    }

    /**
     * Las filas de cada tabla de tablasDe(): enteras si caben en memoria, o
     * por lotes (ver Lotes).
     */
    public static function cargador(string $base, array $tablas): callable
    {
        $n = array_column($tablas, 'n', 'tabla');
        return static fn(string $tabla): iterable => Lotes::filas($base, 'SELECT * FROM ' . cita($tabla), [], $n[$tabla] ?? 0);
    }

    /** @var list<string> lo que la sintaxis ANSI-89 de Access no deja escribir en la sentencia que viene */
    private static array $notasDdl = [];

    /** Dialectos de volcado: 'sqlite' (que también es el de jsonSQLDB), 'mysql', 'postgresql', 'sqlserver', 'access'. */
    public const DIALECTOS = ['sqlite', 'mysql', 'postgresql', 'sqlserver', 'access'];

    /** El dialecto del volcado en curso. Solo cambia durante volcado(). */
    private static string $d = 'sqlite';
    /** Caracteres NUL quitados de los textos, en los motores que no los admiten */
    private static int $nulos = 0;
    /** @var array<string,true> nombres de restricciones e índices que se repiten en varias tablas */
    private static array $repetidos = [];

    /**
     * Las columnas de una clave o un índice de SHOW KEYS o SHOW INDEXES: van
     * separadas por comas, y la que lleva una coma, entre comillas como en
     * CSV (2.9: se partía en dos).
     *
     * @return list<string>
     */
    private static function columnasDe(?string $lista): array
    {
        return $lista === null || $lista === '' ? [] : str_getcsv($lista, ',', '"', '');
    }

    /** Un nombre entre comillas: dobles en SQL estándar, invertidas en MySQL, corchetes en SQL Server. */
    private static function q(string $nombre): string
    {
        switch (self::$d) {
            case 'mysql':     return '`' . str_replace('`', '``', $nombre) . '`';
            case 'sqlserver':
            case 'access':    return '[' . str_replace(']', ']]', $nombre) . ']';
            default:          return cita($nombre);
        }
    }

    /**
     * La expresión de un CHECK (SQL de aquí) en el SQL del destino (2.9): los
     * nombres con sus comillas, != como <>, LENGTH como cuenta caracteres
     * allí, IFNULL como COALESCE. Null si usa algo que allí no significa lo
     * mismo: || (en MySQL y SQL Server no concatena así), JSON_VALID fuera de
     * MySQL y SQLite, una función desconocida o un nombre que no es columna.
     *
     * @param list<array> $columnas las de SHOW COLUMNS
     */
    private static function expresionCheck(string $sql, array $columnas): ?string
    {
        $cols = [];
        foreach ($columnas as $c) {
            $cols[strtolower((string)$c['columna'])] = (string)$c['columna'];
        }
        $palabras = ['AND', 'OR', 'NOT', 'IN', 'BETWEEN', 'IS', 'NULL', 'LIKE', 'ABS', 'LOWER', 'UPPER', 'COALESCE', 'ROUND', 'TRIM'];
        $mal = false;
        $out = preg_replace_callback(
            '/\'(?:[^\']|\'\')*\'|\d+(?:\.\d+)?(?:[eE][-+]?\d+)?|"((?:[^"]|"")*)"|([A-Za-z_][A-Za-z0-9_]*)|(\|\||!=)/',
            static function (array $m) use ($cols, $palabras, &$mal): string {
                if (($m[3] ?? '') !== '') {
                    $mal = $mal || ($m[3] === '||' && in_array(self::$d, ['mysql', 'sqlserver'], true));
                    return $m[3] === '!=' ? '<>' : $m[3];
                }
                $nombre = ($m[1] ?? '') !== '' ? str_replace('""', '"', $m[1]) : ($m[2] ?? '');
                if ($nombre === '') {
                    return $m[0];                               // un texto o un número
                }
                if (isset($cols[strtolower($nombre)])) {
                    return self::q($cols[strtolower($nombre)]);
                }
                $w = strtoupper($nombre);
                if (($m[1] ?? '') === '' && in_array($w, $palabras, true)) {
                    return $m[0];
                }
                if ($w === 'LENGTH' || $w === 'CHAR_LENGTH') {
                    return ['mysql' => 'CHAR_LENGTH', 'sqlserver' => 'LEN'][self::$d] ?? 'LENGTH';
                }
                if ($w === 'IFNULL') {
                    return 'COALESCE';
                }
                if ($w === 'JSON_VALID' && in_array(self::$d, ['mysql', 'sqlite'], true)) {
                    return $m[0];
                }
                $mal = true;
                return $m[0];
            },
            $sql
        );
        return $mal ? null : $out;
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
     * El volcado de una base, en el dialecto pedido. El de jsonSQLDB se carga
     * igual en jsonSQLDB (con la importación del panel) que en SQLite (`sqlite3
     * base.db < base.sql`): es la puerta de salida del proyecto, y por eso cada
     * restricción va dentro de su CREATE TABLE, que es lo único que SQLite
     * admite. Las tablas salen en el orden de sus claves foráneas, las
     * referenciadas antes, y las filas de una tabla que se referencia a sí
     * misma, cada padre antes que sus hijos. Solo si hay un ciclo entre tablas
     * (o una fila que se apunta a sí misma) esa clave va al final con ALTER
     * TABLE, que SQLite no acepta, y el volcado lo dice en un comentario.
     *
     * Las filas pueden venir ya en cada tabla ('filas') o pedirse tabla a tabla
     * con $cargar(nombre), que devuelve un array o, si no cabe en memoria, un
     * generador (ver Lotes); y el texto puede devolverse entero o entregarse
     * por trozos a $salida (cada bloque de filas, ver bloques()). Con los dos,
     * en memoria hay como mucho una tabla, o un lote si no cabe.
     *
     * @param array<int,array{tabla:string,columnas:array,claves:array,filas?:array,n?:int}> $tablas  las filas, o cuántas son si se cargan por tablas ($cargar)
     */
    public static function volcado(string $base, array $tablas, array $triggers, array $vistas = [],
                                   array $indices = [], string $dialecto = 'sqlite',
                                   ?callable $cargar = null, ?callable $salida = null): string
    {
        self::$d = in_array($dialecto, self::DIALECTOS, true) ? $dialecto : 'sqlite';
        self::$nulos = 0;
        try {
            return self::componer($base, $tablas, $triggers, $vistas, $indices, $cargar, $salida);
        } finally {
            self::$d = 'sqlite';
            self::$repetidos = [];
        }
    }

    /**
     * @param array<int,array{tabla:string,columnas:array,claves:array,filas?:array,n?:int}> $tablas  las filas, o cuántas son si se cargan por tablas ($cargar)
     * @param array<int,array{indice:string,tabla:string,columnas:string,automatico:int}> $indices
     */
    private static function componer(string $base, array $tablas, array $triggers, array $vistas, array $indices,
                                     ?callable $cargar = null, ?callable $salida = null): string
    {
        $filas = 0;
        $porNombre = [];
        foreach ($tablas as $t) {
            $filas += isset($t['filas']) ? count($t['filas']) : (int)($t['n'] ?? 0);
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
                if ($k['tipo'] !== 'PRIMARY' && $k['nombre'] !== '') { $usos[strtolower((string)$k['nombre'])][$t['tabla']] = true; }
            }
        }
        self::$repetidos = array_map(static fn(): bool => true, array_filter($usos, static fn(array $u): bool => count($u) > 1));

        $out  = '-- jsonSQLDB · ' . t("volcado de la base '{base}'", ['base' => $base]) . ' · ' . date('Y-m-d H:i:s') . "\n";
        $out .= '-- ' . t('{t} tabla(s), {f} fila(s)', ['t' => count($tablas), 'f' => $filas]) . "\n";
        $citext = false;                   // ¿alguna columna COLLATE NOCASE? En PostgreSQL es citext (2.9)
        foreach ($tablas as $t) {
            foreach ($t['columnas'] as $c) {
                $citext = $citext || ($c['colacion'] ?? null) === 'NOCASE';
            }
        }
        $out .= self::cabeceraDialecto($base, $citext);
        foreach ($orden as $nombre) {
            $t = $porNombre[$nombre];
            $filasT = $t['filas'] ?? ($cargar !== null ? $cargar($nombre) : []);
            $out .= "\n-- ------------------------------------------------------------\n";
            $out .= '-- ' . t('Tabla: {tabla}', ['tabla' => $nombre]) . "\n";
            $out .= "-- ------------------------------------------------------------\n";
            if (is_array($filasT)) {
                // Las filas primero: si una tabla que se apunta a sí misma tiene un
                // ciclo, su clave pasa a las diferidas y ya no va en el CREATE TABLE
                $t['filas'] = $filasT;
                $filasT = self::padresPrimero($t, $diferidas);
                $est = self::estadisticas($filasT);
            } else {
                // No cabe en memoria y llega por lotes: una pasada para saber qué
                // tipos piden los datos y otra para escribirlos. Sin la tabla
                // entera no se pueden ordenar padres antes que hijos, así que su
                // clave hacia sí misma va al final, con las diferidas
                $est = self::estadisticas($filasT);
                $filasT = $cargar($nombre);
                foreach ($t['claves'] as $k) {
                    if ($k['tipo'] === 'FOREIGN' && (string)$k['tabla_destino'] === $nombre) {
                        $diferidas[] = [$nombre, $k];
                    }
                }
            }
            $enIndice = [];
            foreach ($propios as $i) {
                if ($i['tabla'] === $nombre) {
                    foreach (self::columnasDe($i['columnas']) as $c) { $enIndice[$c] = true; }
                }
            }
            $out .= self::createTable($nombre, $t['columnas'], $t['claves'], $diferidas, $enIndice, $est);
            $auto = null;
            foreach ($t['columnas'] as $c) {
                if ((int)$c['auto'] === 1) { $auto = (string)$c['columna']; }
            }
            if ($est['filas'] > 0) {
                // SQL Server no deja escribir en una columna IDENTITY sin pedirlo
                $identidad = self::$d === 'sqlserver' && $auto !== null;
                $out .= "\n" . ($identidad ? 'SET IDENTITY_INSERT ' . self::q($nombre) . " ON;\n" : '');
                $fechas = array_column(array_filter($t['columnas'],
                    static fn(array $c): bool => strtoupper((string)$c['tipo']) === 'DATETIME'), 'columna');
                foreach (self::bloques($filasT) as $bloque) {
                    $out .= self::lineasInsert($bloque, $nombre, $fechas);
                    if ($salida !== null) {
                        $salida($out);
                        $out = '';
                    }
                }
                $out .= $identidad ? 'SET IDENTITY_INSERT ' . self::q($nombre) . " OFF;\n" : '';
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
                      . ' (' . implode(', ', array_map(static fn($c) => self::q((string)$c), self::columnasDe($i['columnas']))) . ");\n";
            }
            // Por trozos: esta tabla sale ya, y sus filas dejan sitio a la siguiente
            if ($salida !== null) {
                $salida($out);
                $out = '';
            }
            unset($t, $filasT);
        }
        if ($diferidas !== []) {
            $out .= "\n-- ------------------------------------------------------------\n";
            $out .= '-- ' . t('Claves foráneas que no pueden ir en su CREATE TABLE: forman un ciclo entre tablas, o una fila se apunta a sí misma.')
                  . (self::$d === 'sqlite' ? ' ' . t('SQLite no acepta ALTER TABLE … ADD CONSTRAINT: ahí se cargarán los datos sin ellas.') : '') . "\n";
            $out .= "-- ------------------------------------------------------------\n";
            foreach ($diferidas as [$tabla, $k]) {
                $sentencia = 'ALTER TABLE ' . self::q($tabla) . ' ADD ' . self::claveForanea($tabla, $k) . ";\n";
                foreach (self::$notasDdl as $n) {
                    $out .= self::comentario($n);
                }
                self::$notasDdl = [];
                $out .= $sentencia . (self::$d === 'access' ? "\n" : '');
            }
        }
        // Vistas y triggers: tal cual para SQLite y jsonSQLDB; para los demás,
        // traducidos a su SQL (ver GeneradorSql). Lo que no tiene forma de
        // escribirse allí (o un aviso de PHP por algo inesperado) deja esa vista
        // o ese trigger comentado con el motivo, no el volcado a medias, y se
        // cuenta en la cabecera
        set_error_handler(static function (int $n, string $msg): bool {
            throw new NoTraducible($msg);
        }, E_WARNING | E_NOTICE);                  // no los avisos de obsoleto de versiones nuevas de PHP
        try {
            $gen = self::$d === 'sqlite' ? null
                : (new GeneradorSql(self::$d))->conTipos(array_map(static fn(array $t): array => $t['columnas'], $porNombre));
            $sinTraducir = [];
            if ($vistas !== []) {
                $out .= "\n-- ------------------------------------------------------------\n-- " . t('Vistas') . "\n";
                $out .= "-- ------------------------------------------------------------\n";
                foreach ($gen === null ? $vistas : GeneradorSql::ordenarVistas($vistas) as $v) {
                    $sql = 'CREATE VIEW ' . self::q((string)$v['vista']) . ' AS ' . rtrim(trim((string)$v['sql']), ';') . ';';
                    if ($gen === null) {
                        $out .= $sql . "\n";
                        continue;
                    }
                    try {
                        $out .= $gen->vista((string)$v['vista'], (string)$v['sql']) . "\n";
                    } catch (Throwable $e) {
                        $sinTraducir[] = (string)$v['vista'];
                        $out .= self::comentario(t('Sin traducir: {motivo}', ['motivo' => $e->getMessage()]) . "\n" . $sql);
                    }
                }
            }
            if ($triggers !== []) {
                $out .= "\n-- ------------------------------------------------------------\n-- " . t('Triggers') . "\n";
                $out .= "-- ------------------------------------------------------------\n";
                if ($gen === null) {
                    foreach ($triggers as $trg) {
                        $out .= rtrim(trim((string)$trg['sql']), ';') . ";\n";
                    }
                } else {
                    $porTabla = [];
                    foreach ($triggers as $trg) {
                        $porTabla[(string)$trg['tabla']][] = $trg;
                    }
                    foreach ($porTabla as $tabla => $lista) {
                        $t = $porNombre[$tabla] ?? null;
                        $pk = [];
                        foreach ($t['claves'] ?? [] as $k) {
                            if ($k['tipo'] === 'PRIMARY') {
                                $pk = self::columnasDe($k['columnas']);
                            }
                        }
                        try {
                            $out .= $gen->triggers($tabla, $lista, $t['columnas'] ?? [], $pk, $t['claves'] ?? []);
                        } catch (Throwable $e) {
                            foreach ($lista as $trg) {
                                $sinTraducir[] = (string)$trg['nombre'];
                                $out .= self::comentario(t('Sin traducir: {motivo}', ['motivo' => $e->getMessage()])
                                      . "\n" . rtrim(trim((string)$trg['sql']), ';') . ';');
                            }
                        }
                    }
                }
            }
        } finally {
            restore_error_handler();
        }
        if ($sinTraducir !== []) {
            $aviso = self::comentario(t('Sin traducir (van comentados al final): {lista}', ['lista' => implode(', ', $sinTraducir)]));
            // Por trozos, la cabecera ya ha salido: el aviso va aquí
            $out = $salida !== null ? "\n" . $aviso . $out
                : str_replace('-- jsonsqldb-dialecto: ' . self::$d . "\n", '-- jsonsqldb-dialecto: ' . self::$d . "\n" . $aviso, $out);
        }
        if (self::$nulos > 0) {
            $out .= "\n-- " . t('AVISO: {n} carácter(es) NUL (\\0) quitados de los textos: este motor no los admite dentro de un texto', ['n' => self::$nulos]) . "\n";
        }
        $out .= ['mysql' => "\nSET FOREIGN_KEY_CHECKS = 1;\n", 'postgresql' => "\nCOMMIT;\n",
                 'sqlserver' => "\nCOMMIT TRANSACTION;\n"][self::$d] ?? '';
        if ($salida !== null) {
            $salida($out);
            return '';
        }
        return $out;
    }

    /**
     * Las primeras líneas del volcado: cómo cargarlo y lo que hay que saber,
     * en el idioma del panel. La línea «-- jsonsqldb-dialecto:» no se traduce:
     * es la que mira el importador para saber de qué motor es.
     */
    private static function cabeceraDialecto(string $base, bool $citext): string
    {
        $c = static fn(string $texto): string => self::comentario($texto);
        $out = '-- jsonsqldb-dialecto: ' . self::$d . "\n";
        switch (self::$d) {
            case 'mysql':
                // Modo estricto: un valor que no cabe es un error, no un número
                // cambiado en silencio (sin él, MySQL recorta y sigue)
                return $out . $c(t('Para MySQL 8 y MariaDB 10.3 o posteriores: {orden}', ['orden' => "mysql nombre_base < $base.mysql.sql"]))
                     . $c(t('Enteros como BIGINT (los de PHP son de 64 bits), texto en utf8mb4_bin (las comparaciones de jsonSQLDB distinguen mayúsculas y acentos, y así una clave única sigue admitiendo lo mismo), y en utf8mb4_general_ci el de las columnas COLLATE NOCASE. Las vistas y los triggers van al final, traducidos al SQL de MySQL; el que no se puede escribir allí va comentado, con el motivo.'))
                     . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n"
                     . "SET SQL_MODE = 'STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';\n";
            case 'postgresql':
                return $out . $c(t('Para PostgreSQL 10 o posterior: {orden}', ['orden' => "psql -v ON_ERROR_STOP=1 -d nombre_base -f $base.pg.sql"]))
                     . $c(t('Todo va en una transacción: o se carga entero o no se carga nada. Enteros como BIGINT, fechas como TIMESTAMP(3), autoincremento como IDENTITY. Las vistas y los triggers van al final, traducidos (cada trigger con su función); el que no se puede escribir allí va comentado, con el motivo.'))
                     . "SET client_encoding = 'UTF8';\nBEGIN;\n"
                     . ($citext ? "CREATE EXTENSION IF NOT EXISTS citext;\n" : '');
            case 'sqlserver':
                return $out . $c(t('Para SQL Server 2017 o posterior: {orden}, o ábrelo en SQL Server Management Studio. Todo va en una transacción.', ['orden' => "sqlcmd -d nombre_base -i $base.sqlserver.sql"]))
                     . $c(t('Texto como NVARCHAR con cotejamiento binario (las comparaciones de jsonSQLDB distinguen mayúsculas y acentos), o Latin1_General_100_CI_AS en las columnas COLLATE NOCASE, enteros como BIGINT, fechas como DATETIME2(3), autoincremento como IDENTITY. Una clave foránea de una tabla hacia sí misma va sin ON DELETE/ON UPDATE: SQL Server no admite acciones en cadena que puedan formar un ciclo. Las vistas y los triggers van al final, traducidos; el que no se puede escribir allí va comentado, con el motivo.'))
                     . "SET NOCOUNT ON;\nSET XACT_ABORT ON;\nSET ANSI_NULLS ON;\nSET QUOTED_IDENTIFIER ON;\nBEGIN TRANSACTION;\n";
            case 'access':
                return $out . $c(t('Para Microsoft Access, en su sintaxis de siempre (ANSI-89), la de la vista SQL de una consulta. Access ejecuta una sola sentencia cada vez: copia cada una (sin las líneas que empiezan por --) en Crear → Diseño de consulta → Vista SQL y pulsa Ejecutar. Para cargar el fichero entero de una vez, usa el script access-to-jsonsqldb.ps1 (opción «Load an SQL file into Access»).'))
                     . $c(t('Las consultas guardadas van como CREATE VIEW, que Access solo admite en bases .mdb de Access 2000 a 2003 y .accdb, y solo con la sintaxis ANSI-92 (por ADO/OLEDB, o con la opción «Sintaxis compatible con SQL Server (ANSI 92)» de la base, desde Access 2002); con la de siempre da error, y en Access 97 o anterior no existe. En cualquier versión: pega lo que va detrás de AS en una consulta nueva y guárdala con el nombre de la vista, o carga el fichero con el script, que las crea como consultas guardadas sin CREATE VIEW. Lo que esta sintaxis no tiene va en una línea -- encima de su tabla o relación, para hacerlo a mano: «-- [tabla].[columna] DEFAULT valor» (en Access, vista Diseño de la tabla → Valor predeterminado) y «-- [relación] ON DELETE CASCADE» u ON UPDATE (Herramientas de base de datos → Relaciones → Exigir integridad referencial → Eliminar o Actualizar en cascada). Al importar este fichero en jsonSQLDBadmin, esas líneas se aplican solas.'))
                     . $c(t('DECIMAL pasa a CURRENCY (hasta 4 decimales) o DOUBLE; un salto de línea dentro de un texto, a Chr(13) & Chr(10). Access no tiene triggers: van comentados al final.'));
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
            $cols = self::columnasDe($k['columnas']);
            if ($k['tipo'] === 'PRIMARY' && count($cols) === 1) {
                $pk = $cols[0];
            }
        }
        foreach ($t['claves'] as $k) {
            if ($k['tipo'] !== 'FOREIGN' || (string)$k['tabla_destino'] !== $t['tabla']) {
                continue;
            }
            $cols = self::columnasDe($k['columnas']);
            if ($pk === null || count($cols) !== 1 || self::columnasDe($k['columnas_destino']) !== [$pk]) {
                $diferidas[] = [$t['tabla'], $k];
                continue;
            }
            $col   = $cols[0];
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
        if (self::$d === 'access') {
            // La sintaxis ANSI-89 de Access no tiene ON DELETE ni ON UPDATE: la
            // relación se crea sin ellas y se dice qué hay que marcar a mano
            // (SET DEFAULT, que Access no tiene, también se dice, 2.9)
            $acciones = '';
            foreach (['DELETE' => $k['on_delete'], 'UPDATE' => $k['on_update']] as $cuando => $accion) {
                if (in_array($accion, ['CASCADE', 'SET NULL'], true)) {
                    self::$notasDdl[] = '[' . (string)$k['nombre'] . "] ON $cuando $accion";
                } elseif ($accion === 'SET DEFAULT') {
                    self::$notasDdl[] = '[' . (string)$k['nombre'] . "] ON $cuando SET DEFAULT: " . t('Access no lo tiene; la relación queda sin esa acción');
                }
            }
        }
        if (self::$d === 'mysql') {
            // InnoDB acepta SET DEFAULT y lo guarda como RESTRICT sin avisar
            // (2.9): se escribe NO ACTION, que es lo que hará allí, y se dice
            foreach (['DELETE' => 'on_delete', 'UPDATE' => 'on_update'] as $cuando => $campo) {
                if ($k[$campo] === 'SET DEFAULT') {
                    $acciones = str_replace("ON $cuando SET DEFAULT", "ON $cuando NO ACTION", $acciones);
                    self::$notasDdl[] = '`' . (string)$k['nombre'] . "` ON $cuando SET DEFAULT: " . t('MySQL (InnoDB) no lo tiene; queda NO ACTION, y borrar o cambiar la fila padre dará error');
                }
            }
        }
        return 'CONSTRAINT ' . self::q(self::nombreObjeto($tabla, (string)$k['nombre']))
             . ' FOREIGN KEY (' . implode(', ', array_map($q, self::columnasDe($k['columnas']))) . ')'
             . ' REFERENCES ' . self::q((string)$k['tabla_destino'])
             . ' (' . implode(', ', array_map($q, self::columnasDe($k['columnas_destino']))) . ')'
             . $acciones;
    }

    /**
     * CREATE TABLE reconstruido a partir de la estructura, con sus restricciones dentro.
     *
     * @param array{filas:int,enteras:array<string,int>,largo:array<string,int>,bytes:array<string,int>,fuera32:array<string,true>} $est ver estadisticas()
     */
    private static function createTable(string $tabla, array $columnas, array $claves, array $diferidas,
                                        array $enIndice, array $est): string
    {
        // Columnas que forman parte de una clave o un índice: en MySQL y SQL
        // Server un texto sin longitud no puede estar en uno
        $enClave = $enIndice;
        foreach ($claves as $k) {
            foreach (self::columnasDe($k['columnas']) as $c) { $enClave[$c] = true; }
        }
        $pk = [];
        foreach ($claves as $k) {
            if ($k['tipo'] === 'PRIMARY') {
                $pk = self::columnasDe($k['columnas']);
            }
        }
        $compuesta = count($pk) > 1;
        $sueltas = [];

        $tipos = [];
        foreach ($columnas as $c) {
            $nombre = (string)$c['columna'];
            $tipos[$nombre] = self::$d === 'sqlite' ? self::tipo($c) : self::tipoDestino($c, isset($enClave[$nombre]), $est);
        }
        if (self::$d === 'mysql') {
            $tipos = self::filaMysql($tipos, $enClave, $est);
            // ENUM (2.9): MySQL lo tiene; los demás, con un CHECK (abajo)
            foreach ($columnas as $c) {
                if (($c['valores'] ?? null) !== null) {
                    $tipos[(string)$c['columna']] = 'ENUM(' . implode(', ', array_map([self::class, 'literal'], $c['valores'])) . ')';
                }
            }
        }
        $partes = [];
        foreach ($columnas as $c) {
            $nombre = (string)$c['columna'];
            $tipo   = $tipos[$nombre];
            $esPk   = (int)$c['pk'] === 1 && !$compuesta;
            $auto   = (int)$c['auto'] === 1;
            $d = self::q($nombre) . ' ' . $tipo . self::colacion($c, $tabla);
            switch (self::$d) {
                case 'sqlite':
                    // SQLite solo admite AUTOINCREMENT en INTEGER PRIMARY KEY; en otra
                    // columna el volcado no se cargaba allí (aquí sí se admite)
                    $d .= ($esPk ? ' PRIMARY KEY' : '') . ($auto && $esPk ? ' AUTOINCREMENT' : '')
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
                case 'access':
                    // El autonumérico de Access es su propio tipo: COUNTER. Las
                    // claves, con CONSTRAINT y nombre, como las pide la sintaxis
                    // ANSI-89 de la vista SQL de Access
                    $d = self::q($nombre) . ' ' . ($auto ? 'COUNTER' : $tipo)
                        . (!$auto && ((int)$c['pk'] === 1 || (int)$c['notnull'] === 1) ? ' NOT NULL' : '')
                        . ($esPk ? ' CONSTRAINT ' . self::q(self::nombreObjeto($tabla, 'PK_' . $tabla)) . ' PRIMARY KEY' : '');
                    break;
            }
            if ((int)$c['unico'] === 1) {
                // En SQL Server, NULL cuenta como un valor en UNIQUE: dos filas sin
                // dato chocarían. Se crea como índice único filtrado, después
                if (self::$d === 'access') {
                    $d .= ' CONSTRAINT ' . self::q(self::nombreObjeto($tabla, 'UQ_' . $tabla . '_' . $nombre)) . ' UNIQUE';
                } elseif (self::$d !== 'sqlserver') {
                    $d .= ' UNIQUE';
                }
                $sueltas[$nombre] = true;
            }
            $calculado = $c['defecto_calculado'] ?? null;
            if ($calculado !== null) {
                // 2.8: un DEFAULT que se calcula al insertar (CURRENT_TIMESTAMP, (expresión))
                $expr = self::defectoCalculado((string)$calculado, $tipo);
                if ($expr !== null && self::$d === 'access') {
                    self::$notasDdl[] = '[' . $tabla . '].[' . $nombre . '] DEFAULT ' . $expr;
                } elseif ($expr !== null) {
                    $d .= ' DEFAULT ' . $expr;
                }
            } elseif ($c['defecto'] !== null && self::$d === 'access') {
                // La sintaxis ANSI-89 no tiene DEFAULT: se dice, para ponerlo a mano
                self::$notasDdl[] = '[' . $tabla . '].[' . $nombre . '] DEFAULT ' . self::literal($c['defecto']);
            } elseif ($c['defecto'] !== null) {
                // MySQL 8 solo admite el valor por defecto de un TEXT entre paréntesis,
                // también el de un MEDIUMTEXT o un LONGTEXT (2.9: ver textoMysql())
                $lit = self::literal($c['defecto']);
                $d  .= ' DEFAULT ' . (self::$d === 'mysql' && str_ends_with($tipo, 'TEXT') ? '(' . $lit . ')' : $lit);
            }
            $partes[] = $d;
        }
        if ($compuesta) {
            $partes[] = (self::$d === 'access' ? 'CONSTRAINT ' . self::q(self::nombreObjeto($tabla, 'PK_' . $tabla)) . ' ' : '')
                . 'PRIMARY KEY (' . implode(', ', array_map(static fn($c) => self::q((string)$c), $pk)) . ')';
        }
        $aplazadas = [];
        foreach ($diferidas as [$t, $k]) {
            if ($t === $tabla) { $aplazadas[(string)$k['nombre']] = true; }
        }
        $despues = '';
        foreach ($claves as $k) {
            $cols = self::columnasDe($k['columnas']);
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
        // ENUM fuera de MySQL y CHECK (2.9). Lo que el destino no puede
        // comprobar igual (o Access, que en su SQL no tiene CHECK) va como nota
        // encima de la tabla, para ponerlo a mano
        foreach ($columnas as $c) {
            if (($c['valores'] ?? null) !== null && self::$d !== 'mysql') {
                $ck = 'CHECK (' . self::q((string)$c['columna']) . ' IN (' . implode(', ', array_map([self::class, 'literal'], $c['valores'])) . '))';
                if (self::$d === 'access') { self::$notasDdl[] = $ck; } else { $partes[] = $ck; }
            }
        }
        foreach ($claves as $k) {
            if ($k['tipo'] === 'CHECK') {
                $expr = self::$d === 'access' ? null : self::expresionCheck((string)$k['expresion'], $columnas);
                $ck = ($k['nombre'] !== '' ? 'CONSTRAINT ' . self::q(self::nombreObjeto($tabla, (string)$k['nombre'])) . ' ' : '')
                    . 'CHECK (' . ($expr ?? $k['expresion']) . ')';
                if ($expr === null) { self::$notasDdl[] = $ck; } else { $partes[] = $ck; }
            }
        }

        // Lo que el motor de destino no deja escribir, encima de la tabla
        $notas = '';
        foreach (self::$notasDdl as $n) {
            $notas .= self::comentario($n);
        }
        self::$notasDdl = [];
        return $notas . 'CREATE TABLE ' . self::q($tabla) . " (\n  " . implode(",\n  ", $partes) . "\n)"
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
    private static function tipoDestino(array $c, bool $enClave, array $est): string
    {
        if (self::$d === 'access') {
            return self::tipoAccess($c, $enClave, $est);
        }
        $tipo = strtoupper((string)$c['tipo']);
        $ss   = self::$d === 'sqlserver';
        // Una columna COLLATE NOCASE (2.9): en SQL Server, con un cotejamiento
        // que no distingue mayúsculas; en PostgreSQL, citext
        $nocase = ($c['colacion'] ?? null) === 'NOCASE';
        $cot  = $ss ? ($nocase ? ' COLLATE Latin1_General_100_CI_AS' : ' COLLATE Latin1_General_100_BIN2') : '';
        if ($nocase && self::$d === 'postgresql' && $tipo === 'TEXT') {
            return 'CITEXT';
        }
        if ($c['longitud'] !== null) {
            $n = (int)$c['longitud'];
            if ($ss) { return ($n <= 4000 ? "NVARCHAR($n)" : 'NVARCHAR(MAX)') . $cot; }
            return self::$d === 'mysql' && $n > 16383 ? self::textoMysql(max($n * 4, $est['bytes'][(string)$c['columna']] ?? 0)) : "VARCHAR($n)";
        }
        if ($c['escala'] !== null || $tipo === 'DECIMAL') {
            // Cifras: las que piden los datos, al menos 20 y como mucho las del
            // motor (65 en MySQL, 38 en SQL Server); más no cabe y dará error
            $escala = $c['escala'] !== null ? (int)$c['escala'] : 6;
            $enteras = max(20 - $escala, $est['enteras'][(string)$c['columna']] ?? 0);
            $max = ['mysql' => 65, 'sqlserver' => 38][self::$d] ?? 1000;
            return (self::$d === 'postgresql' ? 'NUMERIC(' : 'DECIMAL(') . min($max, $enteras + $escala) . ',' . $escala . ')';
        }
        switch ($tipo) {
            case 'INTEGER':  return 'BIGINT';
            case 'DOUBLE':   return ['postgresql' => 'DOUBLE PRECISION', 'sqlserver' => 'FLOAT(53)'][self::$d] ?? 'DOUBLE';
            case 'DATETIME': return ['postgresql' => 'TIMESTAMP(3)', 'sqlserver' => 'DATETIME2(3)'][self::$d] ?? 'DATETIME(3)';
        }
        if (!$enClave || self::$d === 'postgresql') {
            if (self::$d === 'mysql') {
                return self::textoMysql($est['bytes'][(string)$c['columna']] ?? 0);
            }
            return $ss ? 'NVARCHAR(MAX)' . $cot : 'TEXT';
        }
        $max = max(191, $est['largo'][(string)$c['columna']] ?? 0);
        // En SQL Server la clave de un índice no pasa de 900 bytes (450 NVARCHAR)
        return $ss ? ($max <= 450 ? "NVARCHAR($max)" : 'NVARCHAR(MAX)') . $cot : 'VARCHAR(' . $max . ')';
    }

    /**
     * El texto de MySQL donde caben $bytes (2.9): TEXT hasta 65.535 bytes,
     * MEDIUMTEXT hasta 16 MB y LONGTEXT más. En bytes, no en caracteres: en
     * utf8mb4 un carácter ocupa hasta cuatro. Antes era siempre TEXT, y un
     * texto de 70.000 caracteres no se podía cargar allí.
     */
    private static function textoMysql(int $bytes): string
    {
        return $bytes <= 65535 ? 'TEXT' : ($bytes <= 16777215 ? 'MEDIUMTEXT' : 'LONGTEXT');
    }

    /**
     * MySQL no admite filas de más de 65.535 bytes sin contar los TEXT y BLOB
     * (2.9): si las columnas de una tabla pasan de eso (cuatro VARCHAR(16000)
     * en utf8mb4), las VARCHAR más largas que no están en una clave pasan a
     * TEXT, empezando por la mayor, hasta que cabe.
     *
     * @param array<string, string> $tipos columna => tipo de MySQL
     * @param array<string, true>   $enClave
     * @return array<string, string>
     */
    private static function filaMysql(array $tipos, array $enClave, array $est): array
    {
        $bytes = static fn(string $t): int => preg_match('/^VARCHAR\((\d+)\)/', $t, $m) ? (int)$m[1] * 4 + 2
            : (preg_match('/^DECIMAL\((\d+)/', $t, $m) ? intdiv((int)$m[1], 2) + 1 : (str_ends_with($t, 'TEXT') ? 12 : 8));
        $total = array_sum(array_map($bytes, $tipos));
        $largas = array_filter($tipos, static fn(string $t, string $c): bool => !isset($enClave[$c]) && str_starts_with($t, 'VARCHAR('),
            ARRAY_FILTER_USE_BOTH);
        uasort($largas, static fn(string $a, string $b): int => $bytes($b) <=> $bytes($a));
        foreach ($largas as $c => $t) {
            if ($total <= 65535) {
                break;
            }
            $tipos[$c] = self::textoMysql($est['bytes'][$c] ?? 0);
            $total += $bytes($tipos[$c]) - $bytes($t);
        }
        return $tipos;
    }

    /**
     * El tipo de una columna en Access (Jet/ACE, DDL en modo ANSI-92). Texto de
     * hasta 255 caracteres, TEXT(n); más largo, MEMO, que no se puede indexar.
     * Enteros, LONG, que es de 32 bits: si los datos no caben, DECIMAL(19,0).
     * DECIMAL, con 28 cifras como mucho, las de Access.
     */
    private static function tipoAccess(array $c, bool $enClave, array $est): string
    {
        $tipo = strtoupper((string)$c['tipo']);
        $col = (string)$c['columna'];
        if ($c['longitud'] !== null) {
            return (int)$c['longitud'] <= 255 ? 'TEXT(' . (int)$c['longitud'] . ')' : 'MEMO';
        }
        if ($c['escala'] !== null || $tipo === 'DECIMAL') {
            // La sintaxis ANSI-89 no tiene DECIMAL: CURRENCY, exacto con 4
            // decimales; con más, DOUBLE (aquí DECIMAL ya es de coma flotante)
            $escala = $c['escala'] !== null ? (int)$c['escala'] : 6;
            return $escala <= 4 ? 'CURRENCY' : 'DOUBLE';
        }
        switch ($tipo) {
            case 'INTEGER':
                // LONG es de 32 bits; DOUBLE, exacto hasta 2^53
                return isset($est['fuera32'][$col]) ? 'DOUBLE' : 'LONG';
            case 'DOUBLE':   return 'DOUBLE';
            case 'DATETIME': return 'DATETIME';
            case 'BOOLEAN':  return 'BIT';
        }
        if (!$enClave) {
            return 'MEMO';
        }
        // Un texto en una clave o un índice: TEXT(255) si los datos caben
        return ($est['largo'][$col] ?? 0) > 255 ? 'MEMO' : 'TEXT(255)';
    }

    /**
     * Lo que los tipos de destino necesitan saber de los datos de una tabla,
     * en una pasada y sin guardar las filas: cuántas hay, las cifras enteras
     * de cada columna numérica, el texto más largo de cada una (en
     * caracteres) y qué enteros no caben en 32 bits.
     *
     * @param iterable<array<string,mixed>> $filas
     * @return array{filas:int,enteras:array<string,int>,largo:array<string,int>,fuera32:array<string,true>}
     */
    private static function estadisticas(iterable $filas): array
    {
        $est = ['filas' => 0, 'enteras' => [], 'largo' => [], 'bytes' => [], 'fuera32' => []];
        foreach ($filas as $f) {
            $est['filas']++;
            foreach ($f as $col => $v) {
                if (is_int($v) || is_float($v)) {
                    $cifras = strlen(number_format(abs((float)$v), 0, '.', ''));
                    if ($cifras > ($est['enteras'][$col] ?? 0)) { $est['enteras'][$col] = $cifras; }
                    if (is_int($v) && ($v > 2147483647 || $v < -2147483648)) { $est['fuera32'][$col] = true; }
                } elseif (is_string($v)) {
                    $largo = largoUtf8($v);
                    if ($largo > ($est['largo'][$col] ?? 0)) { $est['largo'][$col] = $largo; }
                    if (strlen($v) > ($est['bytes'][$col] ?? 0)) { $est['bytes'][$col] = strlen($v); }
                }
            }
        }
        return $est;
    }

    /**
     * Las filas en bloques de hasta 500, para escribir la salida según se
     * genera; y de menos si sus textos pasan de 2 MB (2.9): con filas de
     * cientos de KB, quinientas juntas y su texto SQL no cabían en la
     * memoria del panel y el volcado moría a medias con un error fatal.
     *
     * @param iterable<array<string,mixed>> $filas
     * @return Generator<int,list<array<string,mixed>>>
     */
    private static function bloques(iterable $filas): Generator
    {
        $bloque = [];
        $bytes  = 0;
        foreach ($filas as $f) {
            $bloque[] = $f;
            foreach ($f as $v) {
                $bytes += is_string($v) ? strlen($v) : 8;
            }
            if (count($bloque) === 500 || $bytes > 2097152) {
                yield $bloque;
                $bloque = [];
                $bytes  = 0;
            }
        }
        if ($bloque !== []) {
            yield $bloque;
        }
    }

    /**
     * El COLLATE de una columna (2.9) que va detrás de su tipo: el mismo en
     * SQLite, y en MySQL el más parecido a NOCASE, utf8mb4_general_ci. RTRIM
     * no lo necesitan MySQL ni SQL Server, que al comparar ya no cuentan los
     * espacios del final; PostgreSQL no tiene nada parecido, y va como nota.
     * El NOCASE de SQL Server y PostgreSQL va en el tipo (ver tipoDestino()).
     */
    private static function colacion(array $c, string $tabla): string
    {
        $col = $c['colacion'] ?? null;
        if ($col === null) {
            return '';
        }
        if (self::$d === 'sqlite') {
            return ' COLLATE ' . $col;
        }
        if (self::$d === 'mysql' && $col === 'NOCASE') {
            return ' COLLATE utf8mb4_general_ci';
        }
        if (self::$d === 'postgresql' && $col === 'RTRIM') {
            self::$notasDdl[] = t('{columna}: COLLATE RTRIM (sin contar los espacios del final al comparar) no existe en PostgreSQL',
                ['columna' => self::q($tabla) . '.' . self::q((string)$c['columna'])]);
        }
        return '';
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
     * @param iterable<array<string,mixed>> $filas  un array o un generador (por lotes, ver Lotes)
     */
    public static function inserts(iterable $filas, string $tabla): void
    {
        self::primerLote($filas);
        self::cabeceras($tabla . '.sql', 'text/plain; charset=UTF-8');

        echo "-- jsonSQLDB · $tabla · " . date('Y-m-d H:i:s') . "\n\n";
        $n = 0;
        try {
            foreach (self::bloques($filas) as $bloque) {
                echo self::lineasInsert($bloque, $tabla);
                $n += count($bloque);
                flush();
            }
            echo "\n-- " . t('{n} fila(s)', ['n' => $n]) . "\n";
        } catch (Throwable $e) {
            echo self::incompleta($e->getMessage());
        }
        exit;
    }

    /**
     * @param list<array<string,mixed>> $filas
     * @param list<string> $fechas columnas DATETIME: en Access, sus valores van como #fecha#
     */
    private static function lineasInsert(array $filas, string $tabla, array $fechas = []): string
    {
        $fechas = array_flip($fechas);
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
                $v = $f[$c] ?? null;
                $vals[] = self::$d === 'access' && isset($fechas[$c]) && is_string($v)
                    ? '#' . substr(str_replace('T', ' ', $v), 0, 19) . '#' : self::literal($v);
            }
            $out .= $cab . implode(', ', $vals) . ");\n";
        }
        return $out;
    }

    /**
     * Un DEFAULT calculado en el SQL del dialecto: CURRENT_TIMESTAMP,
     * CURRENT_DATE y CURRENT_TIME tal cual donde existen; cualquier otra
     * expresión, traducida como en las vistas y entre paréntesis. Null si no
     * tiene traducción (entonces no se escribe).
     */
    private static function defectoCalculado(string $expr, string $tipo): ?string
    {
        $clave = strtoupper(trim($expr));
        $fijas = ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'];
        if (self::$d === 'sqlite') {
            return in_array($clave, $fijas, true) ? $clave : '(' . $expr . ')';
        }
        // CURRENT_TIMESTAMP sin paréntesis vale en PostgreSQL y SQL Server; en
        // MySQL solo en una columna de fecha y hora y con su misma precisión
        // (DATETIME(3) pide CURRENT_TIMESTAMP(3)), que vale desde la 5.6
        if ($clave === 'CURRENT_TIMESTAMP' && (self::$d === 'postgresql' || self::$d === 'sqlserver')) {
            return 'CURRENT_TIMESTAMP';
        }
        if ($clave === 'CURRENT_TIMESTAMP' && self::$d === 'mysql' && preg_match('/^(DATETIME|TIMESTAMP)(\((\d)\))?$/', $tipo, $m)) {
            return 'CURRENT_TIMESTAMP' . (isset($m[3]) ? '(' . $m[3] . ')' : '');
        }
        try {
            $g = new GeneradorSql(self::$d === 'access' ? 'access' : self::$d);
            $sql = $g->consulta(\JsonSQLDB\Parser::analizar('SELECT ' . $expr . ' AS v'));
            // SELECT <expresión> AS <v entre comillas del dialecto>
            if (!preg_match('/^SELECT (.+) AS \S+$/s', $sql, $m)) {
                return null;
            }
            return '(' . $m[1] . ')';
        } catch (NoTraducible) {
            return null;
        }
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
        $s = self::$d === 'mysql' ? str_replace(['\\', "'", "\0"], ['\\\\', "''", '\\0'], (string)$v) : str_replace("'", "''", (string)$v);
        // Un NUL dentro de un texto (2.9): ningún motor lo admite escrito tal
        // cual en una cadena y el volcado no se podía cargar. MySQL lo escribe
        // \0 (arriba); SQLite y SQL Server lo forman con char(0) / NCHAR(0);
        // PostgreSQL y Access no lo guardan en un texto: se quita y se avisa
        if (str_contains($s, "\0")) {
            if (self::$d === 'postgresql' || self::$d === 'access') {
                self::$nulos += substr_count($s, "\0");
                $s = str_replace("\0", '', $s);
            } else {
                $n = self::$d === 'sqlserver' ? 'N' : '';
                return implode(self::$d === 'sqlserver' ? ' + NCHAR(0) + ' : ' || char(0) || ',
                    array_map(static fn(string $p): string => $n . "'" . $p . "'", explode("\0", $s)));
            }
        }
        if (self::$d === 'access' && strpbrk($s, "\r\n") !== false) {
            // En Access no hay forma de escribir un salto de línea dentro de un
            // texto: se une con Chr(13) y Chr(10). Así la sentencia cabe en una
            // línea, se puede pegar en la vista SQL, y ningún editor ni Git
            // cambia el dato al cambiar los finales de línea del fichero
            $trozos = preg_split('/(\r|\n)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
            $partes = [];
            foreach ($trozos as $t) {
                $partes[] = $t === "\r" ? 'Chr(13)' : ($t === "\n" ? 'Chr(10)' : "'" . $t . "'");
            }
            return implode(' & ', array_values(array_filter($partes, static fn($p) => $p !== "''")) ?: ["''"]);
        }
        return (self::$d === 'sqlserver' ? 'N' : '') . "'" . $s . "'";
    }

    private static function cabeceras(string $fichero, string $tipo): void
    {
        $fichero = preg_replace('/[^A-Za-z0-9_.-]/', '_', $fichero) . '';
        $sello   = date('Ymd-Hi');
        $punto   = strrpos($fichero, '.');
        if ($punto === false) {
            $punto = strlen($fichero);                // sin extensión: el sello al final
        }
        $fichero = substr($fichero, 0, $punto) . '-' . $sello . substr($fichero, $punto);

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: ' . $tipo);
        header('Content-Disposition: attachment; filename="' . $fichero . '"');
        header('Cache-Control: no-store');
        // Un error fatal de PHP (la memoria, el tiempo) a mitad de una descarga
        // de texto no pasa por los catch: el fichero se quedaba cortado sin
        // ninguna marca. Al cerrarse PHP se añade el aviso y la sentencia que
        // impide importarlo (2.9)
        if (str_starts_with($tipo, 'text/')) {
            register_shutdown_function(static function (): void {
                $e = errorFatal();
                if ($e !== null) {
                    echo self::incompleta($e['message']);
                }
            });
        }
    }
}
