<?php
declare(strict_types=1);

/**
 * Importaciones: la copia ZIP que genera Exportar::zip(), un fichero de
 * sentencias SQL (el volcado del panel u otro) y un CSV en una tabla.
 *
 * La del ZIP escribe directamente en el disco del motor, así que solo
 * funciona cuando el panel y el motor están en la misma máquina. Las de SQL y
 * CSV van sentencia a sentencia por la API o por la conexión directa, y
 * funcionan entre máquinas distintas.
 *
 * Todo lo que entra se valida antes de tocar nada:
 *
 *   - Solo ficheros .json, .htaccess y web.config. Nada más se copia.
 *   - Los nombres de tabla se validan con la misma regla del motor.
 *   - Cualquier ruta con '..', absoluta o con separadores raros se rechaza: es
 *     el ataque clásico contra los ZIP, escribir fuera de la carpeta destino.
 *   - Cada .json de tabla se comprueba que sea JSON válido con la forma que
 *     espera el motor.
 *
 * Y antes de sobrescribir se guarda una copia de lo que había, para poder
 * volver atrás si la restauración falla a medias.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Importar
{
    /** Filas por sentencia al cargar un CSV o juntar los INSERT de un volcado. */
    private const LOTE = 200;

    /**
     * Ejecuta un fichero de sentencias SQL —el volcado que genera el panel, u
     * otro— sentencia a sentencia, por la API o por la conexión directa, así
     * que funciona entre máquinas distintas. Se lee de forma continua: en
     * memoria solo está la sentencia en curso, sea cual sea el tamaño.
     *
     * Los INSERT seguidos de una misma tabla con las mismas columnas se juntan
     * en uno de varias filas, que el motor escribe de una vez.
     *
     * No hay transacciones: si una sentencia falla, las anteriores ya están
     * hechas. Se para ahí y se dice cuál era y cuántas se ejecutaron.
     */
    public static function sql(string $fichero, string $base, string $dialecto = 'auto'): string
    {
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            throw new RuntimeException(t('No se puede leer el fichero subido.'));
        }
        if (!in_array($dialecto, ['jsonsqldb', 'sqlite', 'mysql', 'postgresql', 'sqlserver'], true)) {
            $dialecto = Traductor::detectar((string)fread($fh, 8192));
            rewind($fh);
        }
        // Un volcado de SQLite o de MySQL pasa sentencia a sentencia por el
        // traductor; uno de jsonSQLDB va tal cual
        $traductor = $dialecto === 'jsonsqldb' ? null : new Traductor($dialecto);
        $hechas = 0;
        $linea  = 0;
        $prefijo = '';
        $tuplas  = [];
        $vaciar = static function () use (&$prefijo, &$tuplas, &$hechas, $base): void {
            if ($tuplas === []) {
                return;
            }
            Api::sql($base, substr($prefijo, 0, -1) . implode(', ', $tuplas));
            $hechas += count($tuplas);
            $prefijo = '';
            $tuplas  = [];
        };
        $ejecutar = static function (string $sql) use (&$prefijo, &$tuplas, &$hechas, $vaciar, $base): void {
            // INSERT INTO t [(a, b)] VALUES (…);  → se junta con los siguientes iguales
            if (preg_match('/^(INSERT\s+INTO\s+(?:"(?:[^"]|"")+"|[^\s(]+)\s*(?:\([^()]*\)\s*)?VALUES\s*\()/is', $sql, $m)
                && substr_count($sql, '),') === 0) {
                $tupla = '(' . rtrim(substr($sql, strlen($m[1])), "; \t\r\n");
                if ($m[1] !== $prefijo || count($tuplas) >= self::LOTE) {
                    $vaciar();
                    $prefijo = $m[1];
                }
                $tuplas[] = $tupla;
                return;
            }
            $vaciar();
            Api::sql($base, $sql);
            $hechas++;
        };
        // PostgreSQL y SQL Server declaran la clave primaria, el autoincremento
        // o los valores por defecto después de crear la tabla, a veces después
        // de sus datos: una primera pasada los recoge para el CREATE TABLE
        if ($traductor !== null && in_array($dialecto, ['postgresql', 'sqlserver'], true)) {
            foreach (self::sentencias($fh, $dialecto) as [$sql]) {
                $traductor->observar($sql);
            }
            rewind($fh);
        }
        try {
            foreach (self::sentencias($fh, $dialecto) as [$sql, $en]) {
                $linea = $en;
                if ($traductor !== null) {
                    foreach ($traductor->traducir($sql) as $traducida) {
                        $ejecutar($traducida);
                    }
                    continue;
                }
                // Se importa en una base: crear o borrar otras no es cosa suya,
                // y un fichero ajeno no debería poder hacerlo por descuido
                if (preg_match('/^\s*(CREATE|DROP)\s+DATABASE\b/i', $sql)) {
                    throw new RuntimeException(t('El fichero intenta crear o borrar una base de datos; eso no se importa.'));
                }
                $ejecutar($sql);
            }
            $vaciar();
            // Las claves foráneas de un volcado ajeno, al final: ya están todas
            // las tablas y sus filas
            if ($traductor !== null) {
                $linea = t('el final (claves foráneas)');
                foreach ($traductor->aplazadas() as $alter) {
                    Api::sql($base, $alter);
                    $hechas++;
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException(t('Se ejecutaron {n} sentencia(s) y paró cerca de la línea {linea}: {error}. Lo anterior ya está hecho: no hay transacciones.',
                ['n' => $hechas, 'linea' => $linea, 'error' => rtrim($e->getMessage(), '. ')]), 0, $e);
        } finally {
            fclose($fh);
        }
        $nombres = ['jsonsqldb' => 'jsonSQLDB', 'sqlite' => 'SQLite', 'mysql' => 'MySQL / MariaDB',
                    'postgresql' => 'PostgreSQL', 'sqlserver' => 'SQL Server'];
        $avisos = $traductor === null ? [] : $traductor->avisos();
        return t('{n} sentencia(s) ejecutadas (volcado de {motor}).', ['n' => $hechas, 'motor' => $nombres[$dialecto]])
            . ($avisos === [] ? '' : ' ' . t('Lo que no ha llegado igual: {avisos}.', ['avisos' => implode('; ', $avisos)]));
    }

    /**
     * Las sentencias de un fichero SQL, una a una, con la línea en que acaba
     * cada una. Separa por punto y coma fuera de cadenas, identificadores
     * entre comillas y comentarios, y respeta los BEGIN … END de un trigger
     * y los CASE … END, que llevan puntos y coma o END dentro. Según el
     * dialecto del volcado:
     *  - mysql: la barra invertida escapa dentro de una cadena; `nombres`.
     *  - postgresql: cadenas $$…$$ (el cuerpo de una función), E'…' con
     *    escapes, las órdenes de psql (\restrict) se saltan, y un COPY … FROM
     *    stdin se devuelve junto con sus líneas de datos, hasta \.
     *  - sqlserver: una línea GO termina el lote; [nombres]; y como sus
     *    scripts no suelen llevar punto y coma, una línea que empieza por
     *    INSERT, CREATE, ALTER, SET… empieza otra sentencia, salvo dentro del
     *    cuerpo de un procedimiento, una vista o un trigger.
     *
     * @param resource $fh
     * @return \Generator<int, array{0: string, 1: int}>
     */
    private static function sentencias($fh, string $dialecto = 'jsonsqldb'): \Generator
    {
        $mysql = $dialecto === 'mysql';
        $pg    = $dialecto === 'postgresql';
        $ss    = $dialecto === 'sqlserver';
        $actual = '';
        $comilla = '';          // '' fuera; el carácter que cierra la cadena o el nombre
        $escapes = false;       // la cadena admite escapes de barra invertida
        $dolar = '';            // dentro de $etiqueta$ … $etiqueta$ (PostgreSQL)
        $bloque = false;        // dentro de un /* comentario */
        $nivel = 0;             // BEGIN y CASE abiertos
        $n = 0;
        while (($l = fgets($fh)) !== false) {
            $n++;
            $vacia = trim($actual) === '' && $comilla === '' && $dolar === '' && !$bloque;
            if ($vacia && $pg && preg_match('/^\s*\\\\/', $l)) {
                continue;                               // \restrict y otras órdenes de psql
            }
            if ($ss && $comilla === '' && !$bloque && preg_match('/^\s*GO\s*(\d+)?\s*$/i', $l)) {
                if (trim($actual) !== '') { yield [trim($actual), $n]; }
                $actual = '';
                $nivel = 0;
                continue;
            }
            if ($ss && $comilla === '' && !$bloque && trim($actual) !== '' && $nivel <= 0
                && preg_match('/^\s*(INSERT|CREATE|ALTER|SET|DROP|IF|EXEC|EXECUTE|USE|PRINT|UPDATE|DELETE|DECLARE|DBCC)\b/i', $l)
                && !preg_match('/^\s*(CREATE|ALTER)\s+(PROC|PROCEDURE|VIEW|TRIGGER|FUNCTION)\b/i', $actual)) {
                yield [trim($actual), $n - 1];
                $actual = '';
            }
            $largo = strlen($l);
            for ($i = 0; $i < $largo; $i++) {
                $c = $l[$i];
                if ($bloque) {
                    if ($c === '*' && ($l[$i + 1] ?? '') === '/') { $bloque = false; $i++; }
                    continue;
                }
                if ($dolar !== '') {
                    $actual .= $c;
                    if ($c === '$' && substr($l, $i, strlen($dolar)) === $dolar) {
                        $actual .= substr($dolar, 1);
                        $i += strlen($dolar) - 1;
                        $dolar = '';
                    }
                    continue;
                }
                if ($comilla !== '') {
                    $actual .= $c;
                    // \' no cierra una cadena que admite escapes (MySQL, E'…')
                    if ($escapes && $c === '\\' && $i + 1 < $largo) {
                        $actual .= $l[++$i];
                        continue;
                    }
                    if ($c === $comilla) {
                        if (($l[$i + 1] ?? '') === $comilla) { $actual .= $c; $i++; } else { $comilla = ''; }
                    }
                    continue;
                }
                if ($c === '-' && ($l[$i + 1] ?? '') === '-') {                   // comentario hasta fin de línea
                    $actual .= "\n";
                    break;
                }
                if ($c === '/' && ($l[$i + 1] ?? '') === '*') { $bloque = true; $i++; continue; }
                if ($pg && $c === '$' && preg_match('/\G\$[A-Za-z_]*\$/', $l, $m, 0, $i)) {
                    $dolar = $m[0];
                    $actual .= $dolar;
                    $i += strlen($dolar) - 1;
                    continue;
                }
                if ($c === "'" || $c === '"' || ($mysql && $c === '`') || ($ss && $c === '[')) {
                    $comilla = $c === '[' ? ']' : $c;
                    $escapes = $c === "'" && ($mysql || ($pg && $i > 0 && ($l[$i - 1] === 'E' || $l[$i - 1] === 'e')
                        && ($i < 2 || !ctype_alnum($l[$i - 2]))));
                    $actual .= $c;
                    continue;
                }
                if ($c === ';' && $nivel <= 0) {
                    $sentencia = trim($actual);
                    $actual = '';
                    $nivel = 0;
                    if ($sentencia === '') {
                        continue;
                    }
                    // COPY … FROM stdin: lo que sigue, hasta \., son datos y no SQL
                    if ($pg && preg_match('/^COPY\s.+\sFROM\s+stdin$/is', $sentencia)) {
                        $datos = '';
                        while (($l2 = fgets($fh)) !== false) {
                            $n++;
                            if (rtrim($l2, "\r\n") === '\\.') { break; }
                            $datos .= $l2;
                        }
                        yield [$sentencia . ";\n" . $datos, $n];
                        continue 2;            // a la línea siguiente al \\.
                    }
                    yield [$sentencia . ';', $n];
                    continue;
                }
                if (ctype_alpha($c) && ($i === 0 || !ctype_alnum($l[$i - 1]) && $l[$i - 1] !== '_')) {
                    $palabra = strtoupper((string)preg_replace('/[^A-Za-z_].*$/s', '', substr($l, $i, 12)));
                    // BEGIN abre un bloque solo dentro de un CREATE (el cuerpo de un
                    // trigger); suelto es BEGIN TRANSACTION, que los volcados de
                    // SQLite ponen al principio y no se cierra con END
                    if ($palabra === 'CASE' || ($palabra === 'BEGIN' && stripos(ltrim($actual), 'CREATE') === 0)) { $nivel++; }
                    elseif ($palabra === 'END') { $nivel--; }
                }
                $actual .= $c;
            }
            // El salto de línea ya ha pasado por el bucle como un carácter más:
            // dentro de una cadena es parte del valor y no se añade otro
        }
        if ($comilla !== '' || $bloque || $dolar !== '') {
            throw new RuntimeException(t('El fichero acaba con una cadena o un comentario sin cerrar.'));
        }
        if (trim($actual) !== '') {
            yield [trim($actual), $n];
        }
    }

    /**
     * Carga un CSV en una tabla que ya existe. La primera línea son los
     * nombres de las columnas; el separador (coma, punto y coma o tabulador)
     * se deduce de ella. Un campo vacío es NULL. Se lee de forma continua y
     * se inserta en lotes, con parámetros ligados.
     *
     * Sin transacciones: si un lote falla (un tipo que no encaja, una clave
     * repetida), lo anterior ya está dentro y se dice hasta qué línea.
     */
    public static function csv(string $fichero, string $base, string $tabla): string
    {
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            throw new RuntimeException(t('No se puede leer el fichero subido.'));
        }
        try {
            $primera = (string)fgets($fh);
            $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera) ?? $primera;   // BOM de Excel
            $sep = ',';
            foreach ([';', "\t", ','] as $s) {
                if (substr_count($primera, $s) > substr_count($primera, $sep)) { $sep = $s; }
            }
            $cols = array_map('trim', str_getcsv(rtrim($primera, "\r\n"), $sep, '"', ''));
            if ($cols === [] || in_array('', $cols, true)) {
                throw new RuntimeException(t('La primera línea tiene que traer los nombres de las columnas.'));
            }
            $cab = 'INSERT INTO ' . cita($tabla) . ' (' . implode(', ', array_map('cita', $cols)) . ') VALUES ';
            $marcas = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            $lote = [];
            $filas = 0;
            $linea = 1;
            $insertar = static function () use (&$lote, &$filas, $base, $cab, $marcas): void {
                if ($lote === []) { return; }
                Api::sql($base, $cab . implode(', ', array_fill(0, count($lote), $marcas)), array_merge(...$lote));
                $filas += count($lote);
                $lote = [];
            };
            try {
                while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
                    $linea++;
                    if ($r === [null]) { continue; }                       // línea en blanco
                    if (count($r) !== count($cols)) {
                        throw new RuntimeException('tiene ' . count($r) . ' campo(s) y la cabecera ' . count($cols) . '.');
                    }
                    $lote[] = array_map(static fn($v) => $v === '' ? null : $v, $r);
                    if (count($lote) >= self::LOTE) {
                        $insertar();
                    }
                }
                $insertar();
            } catch (Throwable $e) {
                throw new RuntimeException(t('Se cargaron {n} fila(s); el problema está en la línea {linea} o en las {lote} anteriores: {error}. Lo cargado ya está dentro: no hay transacciones.',
                    ['n' => $filas, 'linea' => $linea, 'lote' => self::LOTE, 'error' => rtrim($e->getMessage(), '. ')]), 0, $e);
            }
        } finally {
            fclose($fh);
        }
        return t("{n} fila(s) cargadas en '{tabla}'.", ['n' => $filas, 'tabla' => $tabla]);
    }

    /** Ficheros sueltos que sí se aceptan además de los .json */
    private const PERMITIDOS = ['.htaccess', 'web.config'];

    private const MAX_FICHEROS = 2000;

    /**
     * Restaura $zip dentro de $rutaBase, que es la carpeta de la base.
     *
     * @param string $zip       fichero .zip subido
     * @param string $base      nombre de la base de destino
     * @param string $rutaBase  carpeta de datos de esa base
     * @return string resumen de lo hecho
     */
    public static function zip(string $zip, string $base, string $rutaBase): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                t('La extensión zip de PHP no está activada. Actívala en php.ini (extension=zip) o restaura desde un volcado en SQL.')
            );
        }

        $arch = new ZipArchive();
        if ($arch->open($zip) !== true) {
            throw new RuntimeException(t('El fichero no es un ZIP válido o está dañado.'));
        }

        try {
            $entradas = self::entradasValidas($arch, $rutaBase);
            if ($entradas === []) {
                throw new RuntimeException(
                    t('El ZIP no contiene ninguna tabla. ¿Seguro que es una copia generada por este panel? Dentro debe haber una carpeta con los ficheros .json.')
                );
            }

            // Copia de seguridad de lo que hay ahora, por si algo falla a medias
            $respaldo = self::respaldar($rutaBase);

            try {
                foreach ($entradas as $interna => $destino) {
                    $contenido = $arch->getFromName($interna);
                    if ($contenido === false) {
                        throw new RuntimeException(t('No se pudo leer \'{interna}\' del ZIP.', ['interna' => $interna]));
                    }
                    $dir = dirname($destino);
                    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                        throw new RuntimeException(t('No se puede crear la carpeta \'{dir}\'.', ['dir' => $dir]));
                    }
                    if (@file_put_contents($destino, $contenido) === false) {
                        throw new RuntimeException(t('No se puede escribir {fichero}.', ['fichero' => basename($destino)]));
                    }
                }
            } catch (Throwable $e) {
                self::restaurar($respaldo, $rutaBase);
                throw new RuntimeException(
                    t('La restauración falló y se ha dejado la base como estaba. {error}', ['error' => $e->getMessage()])
                );
            }

            self::borrarArbol($respaldo);

            $tablas = 0;
            foreach (array_keys($entradas) as $interna) {
                if (substr($interna, -10) === '.meta.json') {
                    $tablas++;
                }
            }
            return count($entradas) . ' fichero(s) restaurados, ' . $tablas . ' tabla(s).';
        } finally {
            $arch->close();
        }
    }

    /**
     * Entradas del ZIP que se van a copiar: nombre interno => ruta de destino.
     *
     * @return array<string,string>
     */
    private static function entradasValidas(ZipArchive $arch, string $rutaBase): array
    {
        if ($arch->numFiles > self::MAX_FICHEROS) {
            throw new RuntimeException(
                t('El ZIP tiene demasiados ficheros ({n}). Una copia de este panel no debería pasar de unos pocos cientos.', ['n' => $arch->numFiles])
            );
        }

        $out = [];
        for ($i = 0; $i < $arch->numFiles; $i++) {
            $interna = (string)$arch->getNameIndex($i);
            if ($interna === '' || substr($interna, -1) === '/') {
                continue;                                   // carpetas
            }

            $limpia = str_replace('\\', '/', $interna);

            // Ruta hacia arriba, absoluta o con unidad de Windows: fuera
            if (strpos($limpia, '../') !== false || strpos($limpia, './') === 0
                || $limpia[0] === '/' || preg_match('/^[A-Za-z]:/', $limpia) === 1) {
                throw new RuntimeException(
                    t("El ZIP contiene una ruta que sale de la carpeta de destino: '{ruta}'. No se ha tocado nada.", ['ruta' => $interna])
                );
            }

            // El ZIP trae una carpeta raíz con el nombre de la base original
            $partes = explode('/', $limpia);
            array_shift($partes);
            if ($partes === []) {
                continue;
            }
            $relativa = implode('/', $partes);
            $fichero  = basename($relativa);

            if (in_array($fichero, self::PERMITIDOS, true)) {
                $out[$interna] = $rutaBase . '/' . $relativa;
                continue;
            }
            if (substr($fichero, -5) !== '.json') {
                continue;                                   // lo demás se ignora
            }
            self::validarNombreJson($fichero);
            self::validarContenido($arch, $i, $fichero);

            $out[$interna] = $rutaBase . '/' . $relativa;
        }
        return $out;
    }

    /**
     * El nombre del .json tiene que ser el de una tabla o uno de los internos.
     *
     * Los sufijos son los que puede tener un fichero de una tabla:
     *
     *   usuarios.json              datos, primera parte
     *   usuarios.part2.json        siguientes partes
     *   usuarios.meta.json         estructura
     *   usuarios.rev.json          revisión           (desde la 2.0)
     *   usuarios.idx.auto_id.json  un índice          (desde la 2.0)
     *
     * Los dos últimos son de la 2.0 y no estaban aquí, así que exportar una base
     * y volver a restaurarla fallaba: el propio ZIP recién generado se rechazaba
     * por «un nombre que no es de tabla». `_revs.json` sigue admitiéndose porque
     * los ZIP de versiones anteriores lo llevan.
     */
    private static function validarNombreJson(string $fichero): void
    {
        if (in_array($fichero, ['_database.json', '_revs.json', '_views.json'], true)) {
            return;
        }
        $tabla = preg_replace(
            '/(\.meta|\.rev|\.idx\.[A-Za-z_][A-Za-z0-9_]{0,63})?(\.part\d+)?\.json$/',
            '',
            $fichero
        );
        if (!is_string($tabla) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $tabla) !== 1) {
            throw new RuntimeException(
                t("El ZIP contiene un fichero con un nombre que no es de tabla: '{fichero}'. No se ha tocado nada.", ['fichero' => $fichero])
            );
        }
    }

    /** El contenido tiene que ser JSON válido con la forma que espera el motor. */
    private static function validarContenido(ZipArchive $arch, int $i, string $fichero): void
    {
        $texto = $arch->getFromIndex($i);
        if ($texto === false) {
            throw new RuntimeException(t('No se pudo leer \'{fichero}\' del ZIP.', ['fichero' => $fichero]));
        }
        $datos = json_decode($texto, true);
        if (!is_array($datos)) {
            throw new RuntimeException(
                t('\'{fichero}\' no es un JSON válido. No se ha tocado nada.', ['fichero' => $fichero])
            );
        }
        // Solo los ficheros de datos tienen 'rows'. La estructura, la revisión y
        // los índices tienen su propia forma, y los internos empiezan por '_'.
        $sinFilas = substr($fichero, -10) === '.meta.json'
                 || substr($fichero, -9)  === '.rev.json'
                 || strpos($fichero, '.idx.') !== false
                 || $fichero[0] === '_';
        if (!$sinFilas && !isset($datos['rows'])) {
            throw new RuntimeException(
                t("'{fichero}' no tiene la forma de un fichero de datos del motor. No se ha tocado nada.", ['fichero' => $fichero])
            );
        }
    }

    /** Aparta lo que hay ahora y devuelve dónde ha quedado. */
    private static function respaldar(string $rutaBase): string
    {
        $respaldo = $rutaBase . '.antes-de-restaurar';
        self::borrarArbol($respaldo);

        if (!is_dir($rutaBase)) {
            return $respaldo;                               // base nueva: nada que guardar
        }
        if (!@rename($rutaBase, $respaldo)) {
            throw new RuntimeException(
                t('No se pudo apartar la base actual antes de restaurar. Comprueba los permisos de escritura en la carpeta de datos.')
            );
        }
        return $respaldo;
    }

    private static function restaurar(string $respaldo, string $rutaBase): void
    {
        if (!is_dir($respaldo)) {
            return;
        }
        self::borrarArbol($rutaBase);
        @rename($respaldo, $rutaBase);
    }

    private static function borrarArbol(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $r = $dir . '/' . $f;
            is_dir($r) ? self::borrarArbol($r) : @unlink($r);
        }
        @rmdir($dir);
    }
}
