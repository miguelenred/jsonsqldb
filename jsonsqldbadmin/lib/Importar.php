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
    /**
     * Importa un volcado SQL. Con $rutaBase (la carpeta de la base, cuando el
     * panel la alcanza: misma máquina que el motor) es todo o nada: antes se
     * hace una copia de la base y, si algo falla, se deja como estaba. Sin
     * ella, lo que se haya ejecutado antes del fallo queda hecho.
     */
    public static function sql(string $fichero, string $base, string $dialecto = 'auto', ?string $rutaBase = null): string
    {
        return self::deshacible($rutaBase, fn(bool $d) => self::importarSql($fichero, $base, $dialecto, $d));
    }

    /** Importa un CSV en una tabla; con $rutaBase, todo o nada (como sql()). */
    public static function csv(string $fichero, string $base, string $tabla, ?string $rutaBase = null): string
    {
        return self::deshacible($rutaBase, fn(bool $d) => self::importarCsv($fichero, $base, $tabla, $d));
    }

    /**
     * Ejecuta una importación con una copia de la base de antes, si se puede, y
     * la devuelve a como estaba si algo falla.
     *
     * @param callable(bool): string $importar recibe si se puede deshacer
     */
    private static function deshacible(?string $rutaBase, callable $importar): string
    {
        // Hace falta poder escribir en la carpeta de la base y en la de al lado
        // (la copia): si no, devolverla a como estaba podría quedarse a medias
        if ($rutaBase === null || !is_dir($rutaBase) || !is_writable($rutaBase) || !is_writable(dirname($rutaBase))) {
            return $importar(false);
        }
        // La copia, con la base bloqueada: entera de un mismo momento
        try {
            $copia = conBaseBloqueada($rutaBase, static fn(): string => self::copiaDeAntes($rutaBase, '.antes-de-importar'));
        } catch (RuntimeException $e) {
            return $importar(false);                // sin sitio para la copia: como siempre
        }
        try {
            $r = $importar(true);
        } catch (Throwable $e) {
            try {
                conBaseBloqueada($rutaBase, static fn() => self::devolver($copia, $rutaBase));
            } catch (RuntimeException $r) {
                // El mensaje de la importación decía que todo había vuelto atrás: aquí no es así
                throw new RuntimeException($r->getMessage() . ' ' . t('Lo que paró la importación: {error}',
                    ['error' => ($e->getPrevious() ?? $e)->getMessage()]), 0, $e);
            } finally {
                self::olvidarCache();
            }
            throw $e;
        }
        self::borrarArbol($copia);
        return $r;
    }

    private static function copiarArbol(string $origen, string $destino): bool
    {
        if (!@mkdir($destino, 0775, true) && !is_dir($destino)) {
            return false;
        }
        foreach ((array)scandir($origen) as $f) {
            if ($f === '.' || $f === '..' || esFicheroDeBloqueo((string)$f)) {
                continue;
            }
            $de = "$origen/$f";
            $a = "$destino/$f";
            if (is_dir($de) ? !self::copiarArbol($de, $a) : !@copy($de, $a)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Después de devolver una base a como estaba, la caché de APCu del motor
     * podría tener resultados de lo que se deshizo con los mismos números de
     * revisión: se vacía (la de disco está dentro de la carpeta de la base).
     */
    private static function olvidarCache(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled() && class_exists('APCUIterator')) {
            apcu_delete(new APCUIterator('/^jsq:/'));
        }
    }

    private static function importarSql(string $fichero, string $base, string $dialecto, bool $deshacible): string
    {
        $fh = self::abrirUtf8($fichero);
        if (!in_array($dialecto, ['jsonsqldb', 'sqlite', 'mysql', 'postgresql', 'sqlserver', 'access'], true)) {
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
        if ($traductor !== null && in_array($dialecto, ['postgresql', 'sqlserver', 'access'], true)) {
            foreach (self::sentencias($fh, $dialecto) as [$sql]) {
                $traductor->observar($sql);
            }
            rewind($fh);
        }
        try {
            self::$noUtf8 = 0;
            foreach (self::sentencias($fh, $dialecto) as [$sql, $en]) {
                $linea = $en;
                if ($traductor !== null) {
                    $traductor->venidaDeLatin1(self::$latin1);
                    foreach ($traductor->traducir($sql) as $traducida) {
                        // Una vista traducida que el motor no acepta se salta y
                        // se dice; el resto del volcado sigue
                        if (preg_match('/^CREATE VIEW\s+("(?:[^"]|"")+")/', $traducida, $v)) {
                            $vaciar();
                            try {
                                Api::sql($base, $traducida);
                                $hechas++;
                            } catch (RuntimeException $e) {
                                $traductor->avisar(t("{que} '{n}' sin importar: {motivo}", ['que' => t('Vista'), 'n' => trim($v[1], '"'), 'motivo' => $e->getMessage()]));
                            }
                            continue;
                        }
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
                // Y los triggers, los últimos: con los datos ya cargados
                $linea = t('el final (triggers)');
                foreach ($traductor->triggers() as [$nombreTrg, $trg]) {
                    try {
                        Api::sql($base, $trg);
                        $hechas++;
                    } catch (RuntimeException $e) {
                        $traductor->avisar(t("{que} '{n}' sin importar: {motivo}", ['que' => 'Trigger', 'n' => $nombreTrg, 'motivo' => $e->getMessage()]));
                    }
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException($deshacible
                ? t('Paró cerca de la línea {linea}: {error}. No se ha cambiado nada: la base ha vuelto a como estaba antes de importar.',
                    ['linea' => $linea, 'error' => rtrim($e->getMessage(), '. ')])
                : t('Se ejecutaron {n} sentencia(s) y paró cerca de la línea {linea}: {error}. Lo anterior ya está hecho: no hay transacciones.',
                    ['n' => $hechas, 'linea' => $linea, 'error' => rtrim($e->getMessage(), '. ')]), 0, $e);
        } finally {
            fclose($fh);
        }
        $nombres = ['jsonsqldb' => 'jsonSQLDB', 'sqlite' => 'SQLite', 'mysql' => 'MySQL / MariaDB',
                    'postgresql' => 'PostgreSQL', 'sqlserver' => 'SQL Server', 'access' => 'Microsoft Access'];
        $avisos = $traductor === null ? [] : $traductor->avisos();
        if (self::$noUtf8 > 0) {
            $avisos[] = t('Sentencias que no estaban en UTF-8, leídas como Latin-1 / Windows-1252 ({n}): si eran datos binarios, vuelca con --hex-blob', ['n' => self::$noUtf8]);
        }
        return t('{n} sentencia(s) ejecutadas (volcado de {motor}).', ['n' => $hechas, 'motor' => $nombres[$dialecto]])
            . ($avisos === [] ? '' : ' ' . t('Lo que no ha llegado igual: {avisos}.', ['avisos' => implode('; ', $avisos)]));
    }

    /**
     * Abre un volcado para leerlo en UTF-8. Management Studio guarda los
     * scripts en UTF-16 («Unicode») si no se le dice otra cosa, y algunos
     * programas de Windows ponen la marca de UTF-8 al principio: lo primero se
     * convierte a un temporal en UTF-8, por trozos; lo segundo se salta.
     *
     * @return resource
     */
    private static function abrirUtf8(string $fichero)
    {
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            throw new RuntimeException(t('No se puede leer el fichero subido.'));
        }
        $marca = (string)fread($fh, 3);
        if (strncmp($marca, "\xEF\xBB\xBF", 3) === 0) {
            $limpio = fopen('php://temp', 'w+b');
            stream_copy_to_stream($fh, $limpio);
            fclose($fh);
            rewind($limpio);
            return $limpio;
        }
        $origen = strncmp($marca, "\xFF\xFE", 2) === 0 ? 'UTF-16LE' : (strncmp($marca, "\xFE\xFF", 2) === 0 ? 'UTF-16BE' : null);
        if ($origen === null) {
            rewind($fh);
            return $fh;
        }
        // A un temporal en memoria (o en disco si pasa de 2 MB), de 1 MB en 1 MB
        // y sin partir un carácter: en UTF-16 cada unidad son 2 bytes, y un par
        // sustituto va entero si el trozo acaba en su primera mitad
        fseek($fh, 2);
        $limpio = fopen('php://temp', 'w+b');
        $resto = '';
        while (!feof($fh)) {
            $trozo = $resto . (string)fread($fh, 1048576);
            $corte = strlen($trozo) - strlen($trozo) % 2;
            $alto = $origen === 'UTF-16LE' ? ord($trozo[$corte - 1] ?? "\0") : ord($trozo[$corte - 2] ?? "\0");
            if ($corte >= 2 && $alto >= 0xD8 && $alto <= 0xDB) {
                $corte -= 2;                        // primera mitad de un par sustituto
            }
            $resto = (string)substr($trozo, $corte);
            fwrite($limpio, (string)mb_convert_encoding(substr($trozo, 0, $corte), 'UTF-8', $origen));
        }
        fclose($fh);
        rewind($limpio);
        return $limpio;
    }

    /** Lo que pasa el separador por una nota «-- [t].[c] DEFAULT valor» o «-- [relación] ON DELETE CASCADE» de un volcado de Access. */
    private const MARCA_NOTA = "\0nota:";

    /** Sentencias que no venían en UTF-8 en la última importación (leídas como Windows-1252). */
    private static int $noUtf8 = 0;

    /**
     * Las sentencias del fichero, en UTF-8. Un volcado viejo de MySQL o uno
     * hecho con --default-character-set=latin1 trae los textos en Latin-1 /
     * Windows-1252: sin convertirlos, un solo byte suelto rompía el análisis
     * de toda la sentencia («Sentencia no soportada: 'I'»).
     *
     * @param resource $fh
     * @return \Generator<array{0: string, 1: int}>
     */
    private static function sentencias($fh, string $dialecto): \Generator
    {
        $notas = [];                                // lo que hay que añadir a la sentencia que viene
        foreach (self::sentenciasCrudas($fh, $dialecto) as [$sql, $n]) {
            if (str_starts_with($sql, self::MARCA_NOTA)) {
                $notas[] = substr($sql, strlen(self::MARCA_NOTA));
                continue;
            }
            if ($notas !== []) {
                $sql = self::aplicarNotas($sql, $notas);
                $notas = [];
            }
            self::$latin1 = !mb_check_encoding($sql, 'UTF-8');
            if (self::$latin1) {
                $sql = (string)mb_convert_encoding($sql, 'UTF-8', 'Windows-1252');
                self::$noUtf8++;
            }
            yield [$sql, $n];
        }
    }

    /**
     * Pone en la sentencia lo que su nota decía: el DEFAULT detrás del tipo de
     * su columna y la acción detrás del REFERENCES de su relación. Lo que no
     * encuentra lo deja como está.
     *
     * @param list<string> $notas
     */
    private static function aplicarNotas(string $sql, array $notas): string
    {
        foreach ($notas as $nota) {
            if (preg_match('/^\[([^\]]+)\]\.\[([^\]]+)\]\s+DEFAULT\s+(.+)$/ui', $nota, $m)) {
                $col = '\[' . preg_quote($m[2], '/') . '\]';
                $sql = (string)preg_replace('/(' . $col . '\s+[A-Za-z]+(?:\s*\(\s*\d+(?:\s*,\s*\d+)?\s*\))?)/u',
                    '$1 DEFAULT ' . str_replace(['\\', '$'], ['\\\\', '\\$'], trim($m[3])), $sql, 1);
            } elseif (preg_match('/^\[([^\]]+)\]\s+(ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET NULL))$/ui', $nota, $m)) {
                $fk = '\[' . preg_quote($m[1], '/') . '\]';
                $sql = (string)preg_replace('/(CONSTRAINT\s+' . $fk . '\s+FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s*\[[^\]]+\]\s*\([^)]*\))/ui',
                    '$1 ' . strtoupper($m[2]), $sql, 1);
            }
        }
        return $sql;
    }

    /** ¿La última sentencia entregada venía en Latin-1? */
    private static bool $latin1 = false;

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
    private static function sentenciasCrudas($fh, string $dialecto = 'jsonsqldb'): \Generator
    {
        $mysql = $dialecto === 'mysql';
        $pg    = $dialecto === 'postgresql';
        $ss    = $dialecto === 'sqlserver';
        $access = $dialecto === 'access';
        $actual = '';
        $comilla = '';          // '' fuera; el carácter que cierra la cadena o el nombre
        $escapes = false;       // la cadena admite escapes de barra invertida
        $dolar = '';            // dentro de $etiqueta$ … $etiqueta$ (PostgreSQL)
        $bloque = false;        // dentro de un /* comentario */
        $nivel = 0;             // BEGIN y CASE abiertos
        $delim = ';';           // DELIMITER de mysqldump (;; alrededor de los triggers)
        $condicional = 0;       // dentro de /*!50003 … */ de mysqldump: es SQL
        $n = 0;
        while (($l = fgets($fh)) !== false) {
            $n++;
            $vacia = trim($actual) === '' && $comilla === '' && $dolar === '' && !$bloque;
            if ($vacia && $mysql && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $l, $dm)) {
                $delim = $dm[1];
                continue;
            }
            if ($vacia && $pg && preg_match('/^\s*\\\\/', $l)) {
                continue;                               // \restrict y otras órdenes de psql
            }
            // «-- [tabla].[columna] DEFAULT valor» y «-- [relación] ON DELETE CASCADE»:
            // lo que la sintaxis ANSI-89 de Access no deja escribir en la sentencia
            if ($vacia && $access && preg_match('/^\s*--\s*(\[[^\]]+\]\.\[[^\]]+\]\s+DEFAULT\s+.+?|\[[^\]]+\]\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET NULL))\s*$/ui', $l, $mq)) {
                yield [self::MARCA_NOTA . $mq[1], $n];
                continue;
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
                // /*!50003 … */ de mysqldump: lo de dentro es SQL (vistas, triggers…)
                if ($mysql && $c === '/' && ($l[$i + 1] ?? '') === '*' && ($l[$i + 2] ?? '') === '!') {
                    $i += 2;
                    while (ctype_digit($l[$i + 1] ?? '')) { $i++; }
                    $condicional++;
                    $actual .= ' ';
                    continue;
                }
                if ($condicional > 0 && $c === '*' && ($l[$i + 1] ?? '') === '/') {
                    $condicional--;
                    $i++;
                    $actual .= ' ';
                    continue;
                }
                if ($c === '/' && ($l[$i + 1] ?? '') === '*') { $bloque = true; $i++; continue; }
                // Con otro DELIMITER, el ; es un carácter más (el de dentro de un trigger)
                if ($delim !== ';' && substr($l, $i, strlen($delim)) === $delim) {
                    $i += strlen($delim) - 1;
                    if (trim($actual) !== '') {
                        yield [trim($actual) . ';', $n];
                    }
                    $actual = '';
                    continue;
                }
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
                if ($c === ';' && $nivel <= 0 && $delim === ';') {
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
    private static function importarCsv(string $fichero, string $base, string $tabla, bool $deshacible): string
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
            $noUtf8 = 0;
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
                    $lote[] = array_map(static function ($v) use (&$noUtf8) {
                        if ($v === '' || $v === null) {
                            return null;
                        }
                        if (!mb_check_encoding($v, 'UTF-8')) {
                            $noUtf8++;
                            return (string)mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
                        }
                        return $v;
                    }, $r);
                    if (count($lote) >= self::LOTE) {
                        $insertar();
                    }
                }
                $insertar();
            } catch (Throwable $e) {
                throw new RuntimeException($deshacible
                    ? t('El problema está en la línea {linea} o en las {lote} anteriores: {error}. No se ha cargado nada: la tabla ha vuelto a como estaba.',
                        ['linea' => $linea, 'lote' => self::LOTE, 'error' => rtrim($e->getMessage(), '. ')])
                    : t('Se cargaron {n} fila(s); el problema está en la línea {linea} o en las {lote} anteriores: {error}. Lo cargado ya está dentro: no hay transacciones.',
                        ['n' => $filas, 'linea' => $linea, 'lote' => self::LOTE, 'error' => rtrim($e->getMessage(), '. ')]), 0, $e);
            }
        } finally {
            fclose($fh);
        }
        return t("{n} fila(s) cargadas en '{tabla}'.", ['n' => $filas, 'tabla' => $tabla])
            . ($noUtf8 > 0 ? ' ' . t('Campos que no estaban en UTF-8, leídos como Latin-1 / Windows-1252: {n}.', ['n' => $noUtf8]) : '');
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

            // Con el bloqueo exclusivo de la base (el del motor): mientras se
            // restaura no hay consultas ni escrituras en ella, y las que llegan
            // esperan. Se restaura en su misma carpeta, sin cambiarle el nombre,
            // para que quien espera el bloqueo siga esperando el mismo fichero
            conBaseBloqueada($rutaBase, static function () use ($arch, $entradas, $rutaBase): void {
                $copia = self::copiaDeAntes($rutaBase, '.antes-de-restaurar');
                try {
                    self::vaciar($rutaBase);
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
                    self::devolver($copia, $rutaBase);
                    throw new RuntimeException(
                        t('La restauración falló y se ha dejado la base como estaba. {error}', ['error' => $e->getMessage()])
                    );
                } finally {
                    self::olvidarCache();           // la base es otra: sus revisiones vuelven a empezar
                }
                self::borrarArbol($copia);
            });

            $tablas = 0;
            foreach (array_keys($entradas) as $interna) {
                if (substr($interna, -10) === '.meta.json') {
                    $tablas++;
                }
            }
            return t('{f} fichero(s) restaurados, {t} tabla(s).', ['f' => count($entradas), 't' => $tablas]);
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
            // Ningún trozo de la ruta puede ser «.» o «..», ni estar vacío (a//b),
            // ni llevar un byte nulo: ni siquiera al final, donde la lista de
            // nombres permitidos ya lo dejaba fuera
            foreach ($partes as $p) {
                if ($p === '' || $p === '.' || $p === '..' || strpos($p, "\0") !== false) {
                    throw new RuntimeException(
                        t("El ZIP contiene una ruta que sale de la carpeta de destino: '{ruta}'. No se ha tocado nada.", ['ruta' => $interna])
                    );
                }
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
        // Y, por si acaso, cada destino queda dentro de la carpeta de la base
        $raiz = rtrim(str_replace('\\', '/', $rutaBase), '/') . '/';
        foreach ($out as $interna => $destino) {
            if (strpos(str_replace('\\', '/', $destino), $raiz) !== 0) {
                throw new RuntimeException(
                    t("El ZIP contiene una ruta que sale de la carpeta de destino: '{ruta}'. No se ha tocado nada.", ['ruta' => $interna])
                );
            }
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

    /**
     * Copia la base a su lado (sin los ficheros de bloqueo) y devuelve dónde.
     * Se llama con la base bloqueada.
     */
    private static function copiaDeAntes(string $rutaBase, string $sufijo): string
    {
        $copia = $rutaBase . $sufijo;
        self::borrarArbol($copia);
        if (!self::copiarArbol($rutaBase, $copia)) {
            self::borrarArbol($copia);
            throw new RuntimeException(
                t('No se pudo hacer la copia de la base antes de cambiarla. Comprueba el espacio libre y los permisos de la carpeta de datos.')
            );
        }
        return $copia;
    }

    /** Vacía la carpeta de una base, salvo sus ficheros de bloqueo (los tiene cogidos quien la cambia). */
    private static function vaciar(string $rutaBase): void
    {
        foreach ((array)scandir($rutaBase) as $f) {
            if ($f === '.' || $f === '..' || esFicheroDeBloqueo((string)$f)) {
                continue;
            }
            is_dir("$rutaBase/$f") ? self::borrarArbol("$rutaBase/$f") : @unlink("$rutaBase/$f");
        }
    }

    /**
     * Deja la base como en la copia, en su misma carpeta, y borra la copia. Se
     * llama con la base bloqueada. Si la copia no se puede volver a poner, se
     * conserva y se dice dónde está.
     */
    private static function devolver(string $copia, string $rutaBase): void
    {
        self::vaciar($rutaBase);
        if (!self::copiarArbol($copia, $rutaBase)) {
            throw new RuntimeException(t('No se pudo devolver la base a como estaba: la copia de antes está en {copia}.', ['copia' => $copia]));
        }
        self::borrarArbol($copia);
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
