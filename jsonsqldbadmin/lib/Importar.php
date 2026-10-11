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
 *   - Solo los .json de la base, y de .reparados/ los originales y las filas
 *     apartadas (2.9). Nada más se copia: ni .htaccess ni web.config, que
 *     deciden quién puede descargar la carpeta (la base conserva los suyos, y
 *     si no los tiene se le ponen los del motor), ni .cache/, .tx/ o .lote/.
 *   - Lo que se lee entero para comprobarlo tiene que caber en la memoria;
 *     lo demás se copia a trozos.
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
     * Lo que se manda en una petición a la API, por debajo de sus límites por
     * defecto (MAX_PARAMS 1000, MAX_SQL_LENGTH y MAX_PARAMS_LENGTH 100.000,
     * MAX_POST_SIZE 200.000): si en la API son menores, el lote se parte en
     * dos al responder que no cabe (ver enviarPartiendo()).
     */
    private const API_PARAMS = 1000;
    private const API_BYTES  = 80000;

    /**
     * Manda un lote con $mandar y devuelve cuántos elementos tenía. Si la API
     * lo rechaza por su tamaño (sus límites pueden ser menores que los de
     * aquí), lo parte en dos mitades y manda cada una, hasta uno solo.
     *
     * @param list<mixed>               $lote
     * @param callable(list<mixed>): mixed $mandar
     */
    private static function enviarPartiendo(array $lote, callable $mandar): int
    {
        try {
            $mandar($lote);
            return count($lote);
        } catch (RuntimeException $e) {
            $porTamano = false;
            foreach (['Petición demasiado grande', 'SQL demasiado larga', 'Demasiados parámetros', 'Parámetros demasiado largos'] as $m) {
                $porTamano = $porTamano || str_contains($e->getMessage(), $m);
            }
            if (!$porTamano || count($lote) === 1) {
                throw $e;
            }
            $mitad = intdiv(count($lote), 2);
            return self::enviarPartiendo(array_slice($lote, 0, $mitad), $mandar)
                 + self::enviarPartiendo(array_slice($lote, $mitad), $mandar);
        }
    }

    /**
     * Lo que puede ocupar de una vez una sentencia o un valor leído de un
     * fichero: una octava parte de la memoria libre, al menos 1 MB. Un fichero
     * de un ZIP, que se lee y se decodifica, una tercera parte (2.9): con la
     * octava, una base con filas grandes no se podía restaurar donde el motor
     * sí la lee.
     */
    private static function topeDeMemoria(int $partes = 8): int
    {
        $limite = bytesIni('memory_limit');
        return $limite <= 0 ? PHP_INT_MAX : max(1048576, intdiv($limite - memory_get_usage(), $partes));
    }

    /**
     * Importa un volcado SQL —el que genera el panel, u otro— sentencia a
     * sentencia, por la API o por la conexión directa, así que funciona entre
     * máquinas distintas. Se lee de forma continua: en memoria solo está la
     * sentencia en curso, sea cual sea el tamaño. Los INSERT seguidos de una
     * misma tabla con las mismas columnas se juntan en uno de varias filas,
     * que el motor escribe de una vez.
     *
     * Con $rutaBase (la carpeta de la base, cuando el panel la alcanza: misma
     * máquina que el motor) es todo o nada: va entera en una transacción del
     * motor (ver deshacible()). Sin ella, lo que se haya ejecutado antes del
     * fallo queda hecho: una transacción no puede pasar de una petición a la
     * API. Se para ahí y se dice cuál era y cuántas se ejecutaron.
     */
    public static function sql(string $fichero, string $base, string $dialecto = 'auto', ?string $rutaBase = null): string
    {
        return self::deshacible($base, $rutaBase, static function (bool $d) use ($fichero, $base, $dialecto): string {
            $fh = self::abrirUtf8($fichero);
            // El volcado JSON de jsonSQLDB (2.9) empieza por {; uno de SQL, nunca
            $inicio = ltrim((string)fread($fh, 512));
            rewind($fh);
            return $dialecto === 'json' || ($dialecto === 'auto' && str_starts_with($inicio, '{'))
                ? self::importarJsonBase($fh, $base, $d)
                : self::importarSql($fh, $base, $dialecto, $d);
        });
    }

    /** Importa un CSV en una tabla; con $rutaBase, todo o nada (como sql()). */
    public static function csv(string $fichero, string $base, string $tabla, ?string $rutaBase = null): string
    {
        return self::deshacible($base, $rutaBase, fn(bool $d) => self::importarCsv($fichero, $base, $tabla, $d));
    }

    /**
     * Ejecuta una importación, todo o nada si se puede: con la carpeta de la
     * base a mano (misma máquina), en una transacción del motor cargado aquí
     * (ver Api::enTransaccion()). Si algo falla, el motor deshace lo que hizo
     * la importación, y solo eso: mientras dura, la base está bloqueada y
     * nadie más escribe en ella. Hasta la 2.8 se copiaba la base entera y se
     * volvía a poner la copia, que se llevaba por delante lo que otros
     * hubieran escrito mientras tanto. Sin la carpeta, o sin el motor a mano,
     * la importación va sentencia a sentencia, como siempre.
     *
     * @param callable(bool): string $importar recibe si se puede deshacer
     */
    private static function deshacible(string $base, ?string $rutaBase, callable $importar): string
    {
        if ($rutaBase === null || !is_dir($rutaBase)) {
            return $importar(false);
        }
        try {
            Api::cargarMotor();
        } catch (RuntimeException) {
            return $importar(false);                // el motor no está aquí: por la API, como siempre
        }
        // Un error fatal de PHP (la memoria, el tiempo) no pasa por ningún
        // catch: la transacción se queda a medias en disco y el motor la
        // deshace la próxima vez que se abre la base. Se dice al cerrarse PHP
        $enCurso = true;
        register_shutdown_function(static function () use (&$enCurso): void {
            $e = errorFatal();
            if ($enCurso && $e !== null) {
                echo "\n" . h(t('La importación se ha parado: {error}', ['error' => $e['message']]) . ' '
                    . t('No se ha cambiado nada: lo que llegó a importar se deshace solo la próxima vez que se usa la base.')) . "\n";
            }
        });
        try {
            return Api::enTransaccion($base, dirname($rutaBase), static fn(): string => $importar(true));
        } finally {
            $enCurso = false;
        }
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
            apcu_delete(new APCUIterator('/^jsq2?:/'));     // jsq2: la del motor desde la 2.9
        }
    }

    /** @param resource $fh lo abre quien llama (ver sql()); aquí se cierra */
    private static function importarSql($fh, string $base, string $dialecto, bool $deshacible): string
    {
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
        $largo = 0;
        $porApi = Api::porHttp($base);
        $vaciar = static function () use (&$prefijo, &$tuplas, &$largo, &$hechas, $base): void {
            if ($tuplas === []) {
                return;
            }
            $cab = substr($prefijo, 0, -1);
            $hechas += self::enviarPartiendo($tuplas, static fn(array $t) => Api::sql($base, $cab . implode(', ', $t)));
            $prefijo = '';
            $tuplas  = [];
            $largo   = 0;
        };
        $conEstructura = false;                    // ¿ha llegado algún CREATE TABLE? (ver el catch)
        $ejecutar = static function (string $sql) use (&$prefijo, &$tuplas, &$largo, &$hechas, &$conEstructura, $vaciar, $base, $porApi): void {
            $conEstructura = $conEstructura || stripos($sql, 'CREATE TABLE') === 0;
            // INSERT INTO t [(a, b)] VALUES (…);  → se junta con los siguientes iguales,
            // si detrás de su fila no hay nada (2.9): un ON CONFLICT, un RETURNING o
            // el ON DUPLICATE KEY UPDATE de cada fila de phpMyAdmin acababan en
            // medio del INSERT juntado. Con un paréntesis de cada, basta con mirar
            // que acaba en el suyo; si no, que la fila es UNA_FILA
            if (preg_match('/^(INSERT\s+INTO\s+(?:"(?:[^"]|"")+"|[^\s(]+)\s*(?:\([^()]*\)\s*)?VALUES\s*\()/is', $sql, $m)
                && substr_count($sql, '),') === 0
                && (substr_count($tupla = '(' . rtrim(substr($sql, strlen($m[1])), "; \t\r\n"), '(') === 1 && substr_count($tupla, ')') === 1
                    ? str_ends_with($tupla, ')') : preg_match(self::UNA_FILA, $tupla) === 1)) {
                // Por la API, el INSERT juntado no pasa de su tamaño por petición
                if ($m[1] !== $prefijo || count($tuplas) >= self::LOTE || ($porApi && $largo + strlen($tupla) > self::API_BYTES)) {
                    $vaciar();
                    $prefijo = $m[1];
                }
                $tuplas[] = $tupla;
                $largo += strlen($tupla) + 2;
                return;
            }
            $vaciar();
            // Por la API, un INSERT de varias filas va por tandas que quepan en
            // sus límites (2.9): los de mysqldump llegan a 1 MB, y la API admite
            // 100.000 caracteres por sentencia
            $partido = $porApi && stripos($sql, 'INSERT') === 0 ? self::filasDeInsert($sql) : null;
            if ($partido !== null) {
                [$cab, $filas] = $partido;
                $mandar = static fn(array $t) => Api::sql($base, $cab . implode(', ', $t));
                $tanda = [];
                $bytes = 0;
                foreach ($filas as $f) {
                    if ($tanda !== [] && (count($tanda) >= self::LOTE || $bytes + strlen($f) > self::API_BYTES)) {
                        self::enviarPartiendo($tanda, $mandar);
                        $tanda = [];
                        $bytes = 0;
                    }
                    $tanda[] = $f;
                    $bytes += strlen($f) + 2;
                }
                self::enviarPartiendo($tanda, $mandar);
            } else {
                Api::sql($base, $sql);
            }
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
                // (los comentarios ya los ha quitado sentencias(): ni uno en medio lo esconde)
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
            $error = rtrim($e->getMessage(), '. ');
            // Un volcado solo de datos (mysqldump --no-create-info, pg_dump
            // --data-only) en una base sin esas tablas: decir por qué (2.9)
            if (!$conEstructura && preg_match("/La tabla '[^']+' no existe/", $error)) {
                $error .= '. ' . t('El volcado no trae la estructura de las tablas (CREATE TABLE): tienen que existir ya, o vuelca también la estructura');
            }
            throw new RuntimeException($deshacible
                ? t('Paró cerca de la línea {linea}: {error}. No se ha cambiado nada: la base ha vuelto a como estaba antes de importar.',
                    ['linea' => $linea, 'error' => $error])
                : t('Se ejecutaron {n} sentencia(s) y paró cerca de la línea {linea}: {error}. Lo anterior ya está hecho: la importación no va en una transacción.',
                    ['n' => $hechas, 'linea' => $linea, 'error' => $error]), 0, $e);
        } finally {
            fclose($fh);
        }
        $nombres = ['jsonsqldb' => 'jsonSQLDB', 'sqlite' => 'SQLite', 'mysql' => 'MySQL / MariaDB',
                    'postgresql' => 'PostgreSQL', 'sqlserver' => 'SQL Server', 'access' => 'Microsoft Access'];
        $avisos = $traductor === null ? [] : $traductor->avisos();
        if (self::$noUtf8 > 0) {
            $avisos[] = t('Textos que no estaban en UTF-8, leídos como Latin-1 / Windows-1252 ({n}); los demás de las mismas sentencias, que sí lo estaban, se han dejado como venían. Si eran datos binarios (BLOB), no han llegado igual: vuelca con --hex-blob', ['n' => self::$noUtf8]);
        }
        return t('{n} sentencia(s) ejecutadas (volcado de {motor}).', ['n' => $hechas, 'motor' => $nombres[$dialecto]])
            . ($avisos === [] ? '' : ' ' . t('Lo que no ha llegado igual: {avisos}.', ['avisos' => implode('; ', $avisos)]));
    }

    /**
     * Un paréntesis que se cierra al final, sin contar los de dentro de los
     * textos (2.9): la fila de un INSERT sin nada detrás (ver importarSql()).
     * Posesivos y una sola expresión, sin trocear: es por cada INSERT.
     */
    private const UNA_FILA = '/^(\((?:[^\'"()]++|\'(?:[^\']++|\'\')*+\'|"(?:[^"]++|"")*+"|(?1))*+\))\z/';

    /**
     * Un INSERT … VALUES de varias filas partido en su cabecera y sus filas
     * (2.9), para mandarlo a la API por tandas. Null si no es eso, si lleva
     * algo detrás de las filas (ON CONFLICT, RETURNING…) o un comentario:
     * entonces va entero, como venía.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private static function filasDeInsert(string $sql): ?array
    {
        if (!preg_match('/^INSERT\s+(?:OR\s+[A-Z]+\s+)?INTO\s+(?:"(?:[^"]|"")+"|[^\s(]+)\s*(?:\([^()]*\)\s*)?VALUES\s*/is', $sql, $m)) {
            return null;
        }
        $resto = substr($sql, strlen($m[0]));
        // Posesivos: con textos de cientos de KB, la otra forma agota la pila de PCRE
        if (!preg_match_all('/\'(?:[^\']++|\'\')*+\'|"(?:[^"]++|"")*+"|--|\/\*|[(),]|[^\'"(),\/-]++|[\/-]/', $resto, $piezas)
            || implode('', $piezas[0]) !== $resto) {
            return null;                              // una cadena sin cerrar
        }
        $filas = [];
        $fila  = '';
        $nivel = 0;
        $coma  = false;                               // tras una fila, toca una coma
        foreach ($piezas[0] as $p) {
            if ($p === '--' || $p === '/*') {
                return null;
            }
            if ($nivel > 0) {
                $fila .= $p;
                if ($p === '(') {
                    $nivel++;
                } elseif ($p === ')' && --$nivel === 0) {
                    $filas[] = $fila;
                    $coma = true;
                }
            } elseif ($p === '(' && !$coma) {
                $fila  = '(';
                $nivel = 1;
            } elseif ($p === ',' && $coma) {
                $coma = false;
            } elseif (trim($p, $coma ? " \t\r\n;" : " \t\r\n") !== '') {
                return null;
            }
        }
        return $nivel === 0 && $coma && count($filas) > 1 ? [$m[0], $filas] : null;
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
            fwrite($limpio, utf16AUtf8(substr($trozo, 0, $corte), $origen === 'UTF-16BE'));
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
     * Una sentencia que no es UTF-8, pasada a UTF-8 trozo a trozo (2.9): cada
     * texto entre comillas y cada tramo entre ellos por separado, y solo los
     * que no lo eran se leen como Windows-1252. Antes se convertía la
     * sentencia entera: un solo BLOB en crudo en un INSERT de doscientas filas
     * de mysqldump estropeaba los acentos, que estaban bien, de las demás.
     * En un volcado de MySQL, cada texto convertido lleva delante _latin1,
     * para que el traductor pueda devolver sus bytes a una columna BIT.
     */
    private static function aUtf8PorPartes(string $sql, bool $marcar): string
    {
        // Posesivos: con un texto de decenas de KB, la otra forma agotaba la
        // pila de PCRE y la sentencia se quedaba vacía
        $r = preg_replace_callback('/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|`[^`]*+`|"(?:[^"\\\\]++|\\\\.)*+"|[^\'`"]++|[\'`"]/s',
            static function (array $m) use ($marcar): string {
                if (utf8Valido($m[0])) {
                    return $m[0];
                }
                self::$noUtf8++;
                return ($marcar && $m[0][0] === "'" ? '_latin1' : '') . cp1252AUtf8($m[0]);
            }, $sql);
        if ($r === null) {                           // si aun así falla, la sentencia entera, como antes de la 2.9
            self::$noUtf8++;
            return cp1252AUtf8($sql);
        }
        return $r;
    }

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
            if (!utf8Valido($sql)) {
                $sql = self::aUtf8PorPartes($sql, $dialecto === 'mysql');
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

    /**
     * Las sentencias de un texto SQL, separadas por sus «;» (los de dentro de
     * un texto o un comentario no cuentan): para la consola, que con varias
     * las ejecuta en una transacción (2.9).
     *
     * @return list<string>
     */
    public static function partir(string $texto): array
    {
        $m = fopen('php://temp', 'w+b');
        fwrite($m, $texto);
        rewind($m);
        $out = [];
        foreach (self::sentenciasCrudas($m) as [$sentencia]) {
            $out[] = (string)$sentencia;
        }
        fclose($m);
        return $out;
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
     *    cuerpo de un procedimiento, una vista o un trigger (también CREATE OR
     *    ALTER, 2.9).
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
        $endAlFinal = false;    // la línea anterior acabó en END: ¿era el de un END IF partido en dos líneas?
        $delim = ';';           // DELIMITER de mysqldump (;; alrededor de los triggers)
        $condicional = 0;       // dentro de /*!50003 … */ de mysqldump: es SQL
        $n = 0;
        // Lo que puede ocupar una sentencia (2.9): una octava parte de la memoria
        // libre. Una sentencia enorme acababa en un error fatal de PHP, con la
        // página en blanco; así se dice cuál es y dónde empieza
        $tope   = self::topeDeMemoria();
        $desde  = 1;
        // Una línea no se lee de más de ese tamaño: leerla entera ya no cabía
        while (($l = $tope === PHP_INT_MAX ? fgets($fh) : fgets($fh, $tope + 2)) !== false) {
            $n++;
            if ($endAlFinal && trim($l) !== '') {
                $endAlFinal = false;
                if (preg_match('/^\s*(IF|LOOP|WHILE|REPEAT)\b/i', $l)) {
                    $nivel++;                           // END ⏎ IF: no cerraba ningún BEGIN (ver abajo)
                }
            }
            $vacia = trim($actual) === '' && $comilla === '' && $dolar === '' && !$bloque;
            if ($vacia) {
                $desde = $n;
            }
            if (strlen($actual) + strlen($l) > $tope) {
                throw new RuntimeException(t('La sentencia que empieza en la línea {linea} ocupa más de {mb} MB y no cabe en la memoria de PHP (memory_limit = {limite}). Sube ese límite, o pártela en varias.',
                    ['linea' => $desde, 'mb' => max(1, intdiv($tope, 1048576)), 'limite' => (string)ini_get('memory_limit')]));
            }
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
                && !preg_match('/^\s*(CREATE|ALTER)\s+(OR\s+ALTER\s+)?(PROC|PROCEDURE|VIEW|TRIGGER|FUNCTION)\b/i', $actual)) {
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
                        // Por tandas de 1 MB, cada una con su COPY delante (2.9):
                        // juntar todas las filas de una tabla grande en un texto
                        // no cabía en la memoria de PHP
                        $datos = '';
                        while (($l2 = fgets($fh)) !== false) {
                            $n++;
                            if (rtrim($l2, "\r\n") === '\\.') { break; }
                            $datos .= $l2;
                            if (strlen($datos) >= 1048576) {
                                yield [$sentencia . ";\n" . $datos, $n];
                                $datos = '';
                            }
                        }
                        if ($datos !== '') {
                            yield [$sentencia . ";\n" . $datos, $n];
                        }
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
                    elseif ($palabra === 'END') {
                        // END IF, END LOOP, END WHILE y END REPEAT cierran lo suyo, que
                        // no abrió nivel; END a secas y END CASE, un BEGIN o un CASE
                        // (2.9: el END de un END IF cerraba el trigger, y el volcado
                        // del propio panel no se podía volver a importar). T-SQL no los
                        // tiene, y allí un IF puede ir detrás de un END. Si END acaba
                        // la línea, lo dice la primera palabra de la siguiente
                        preg_match('/\G\s*(\w*)/', $l, $sig, 0, $i + 3);
                        if ($ss || !in_array(strtoupper($sig[1]), ['IF', 'LOOP', 'WHILE', 'REPEAT'], true)) {
                            $nivel--;
                            $endAlFinal = !$ss && $sig[1] === '' && $i + 3 + strlen($sig[0]) === $largo;
                        }
                    }
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
     * se deduce de las primeras líneas (ver separadorCsv()). Un campo vacío es
     * NULL. Se lee de forma continua y se inserta en lotes, con parámetros
     * ligados.
     *
     * Sin transacción (ver deshacible()), si un lote falla (un tipo que no
     * encaja, una clave repetida), lo anterior ya está dentro y se dice hasta
     * qué línea.
     */
    private static function importarCsv(string $fichero, string $base, string $tabla, bool $deshacible): string
    {
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            throw new RuntimeException(t('No se puede leer el fichero subido.'));
        }
        try {
            // Cada línea, con tope (ver leerCsv())
            TopeDeLinea::$tope = self::topeDeMemoria();
            in_array('jsonsqldb.tope', stream_get_filters(), true) || stream_filter_register('jsonsqldb.tope', TopeDeLinea::class);
            stream_filter_append($fh, 'jsonsqldb.tope', STREAM_FILTER_READ);
            $inicio = fread($fh, 3) === "\xEF\xBB\xBF" ? 3 : 0;               // BOM de Excel
            $sep  = self::separadorCsv($fh, $inicio);
            $cols = array_map(static fn($v): string => trim((string)$v), (array)self::leerCsv($fh, $sep, 1));
            $noUtf8 = 0;
            $filas = (static function () use ($fh, $sep, &$noUtf8): Generator {
                $linea = 1;
                while (($r = self::leerCsv($fh, $sep, $linea + 1)) !== false) {
                    $linea++;
                    if ($r === [null]) { continue; }                       // línea en blanco
                    yield $linea => array_map(static function ($v) use (&$noUtf8) {
                        if ($v === '' || $v === null) {
                            return null;
                        }
                        if (!utf8Valido($v)) {
                            $noUtf8++;
                            $v = cp1252AUtf8($v);
                        }
                        // El apóstrofo que la exportación pone delante de lo que
                        // Excel tomaría por una fórmula (ver Exportar::lineasCsv())
                        // se quita, uno solo, para que exportar e importar dé el
                        // mismo dato (2.9), también '-5 o '=A1
                        return $v[0] === "'" && preg_match(Exportar::FORMULA, substr($v, 1)) ? substr($v, 1) : $v;
                    }, $r);
                }
            })();
            $r = self::cargarFilas($cols, $filas, $base, $tabla, $deshacible);
        } finally {
            fclose($fh);
        }
        return $r . ($noUtf8 > 0 ? ' ' . t('Campos que no estaban en UTF-8, leídos como Latin-1 / Windows-1252: {n}.', ['n' => $noUtf8]) : '');
    }

    /**
     * Un registro de un CSV, que empieza en la línea $linea, con tope (2.9):
     * con una comilla sin cerrar, fgetcsv() leía el resto del fichero en un
     * solo campo y agotaba la memoria. El fichero lleva el filtro TopeDeLinea.
     *
     * @param resource $fh
     * @return list<string|null>|false
     */
    private static function leerCsv($fh, string $sep, int $linea)
    {
        TopeDeLinea::$leido = 0;
        $r = fgetcsv($fh, 0, $sep, '"', '');
        if (TopeDeLinea::$leido > TopeDeLinea::$tope) {
            throw new RuntimeException(t('La línea {linea} del CSV ocupa más de {mb} MB y no cabe en la memoria de PHP: quizá tiene una comilla sin cerrar.',
                ['linea' => $linea, 'mb' => max(1, intdiv(TopeDeLinea::$tope, 1048576))]));
        }
        return $r;
    }

    /**
     * El separador de un CSV: el de `,` `;` y tabulador que da el mismo número
     * de campos, más de uno, en las cinco primeras líneas; si lo dan varios,
     * el que da más. Contar los separadores de la primera línea contaba
     * también los que van entre comillas, y un fichero bueno (una coma dentro
     * de un nombre de columna) no se podía importar. Deja el fichero al
     * principio de los datos, tras el BOM si lo hay.
     *
     * @param resource $fh
     */
    private static function separadorCsv($fh, int $inicio): string
    {
        $mejor  = ',';
        $campos = 1;
        foreach ([',', ';', "\t"] as $candidato) {
            fseek($fh, $inicio);
            $n = null;
            // La línea, contando las que van en blanco, como al cargarlo (2.9)
            for ($linea = 1, $leidas = 0; $leidas < 5 && ($r = self::leerCsv($fh, $candidato, $linea)) !== false; $linea++) {
                if ($r === [null]) {
                    continue;                                       // línea en blanco
                }
                $leidas++;
                if ($n === null) {
                    $n = count($r);
                } elseif (count($r) !== $n) {
                    $n = 0;
                    break;
                }
            }
            if (($n ?? 0) > $campos) {
                $mejor  = $candidato;
                $campos = $n;
            }
        }
        fseek($fh, $inicio);
        return $mejor;
    }

    /**
     * Inserta en $tabla las filas que llegan (número de línea o de fila =>
     * valores, en el orden de $cols) en lotes de hasta LOTE filas (menos por la
     * API, para caber en sus límites): en memoria solo hay un lote, sea cual
     * sea el tamaño del fichero.
     *
     * @param list<string> $cols
     * @param iterable<int,list<mixed>> $filas
     */
    private static function cargarFilas(array $cols, iterable $filas, string $base, string $tabla, bool $deshacible, ?int &$cargadas = null): string
    {
        if ($cols === [] || in_array('', $cols, true)) {
            throw new RuntimeException(t('La primera línea tiene que traer los nombres de las columnas.'));
        }
        $cab = 'INSERT INTO ' . cita($tabla) . ' (' . implode(', ', array_map('cita', $cols)) . ') VALUES ';
        $marcas = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $lote = [];
        $bytes = 0;
        $hechas = 0;
        $linea = 1;
        // Por la API, cada lote cabe en sus límites por petición (2.9): con filas
        // anchas, doscientas pasaban de MAX_POST_SIZE y la importación fallaba
        // después de subir el fichero entero
        $porApi  = Api::porHttp($base);
        $maxFilas = $porApi ? max(1, min(self::LOTE, intdiv(self::API_PARAMS, max(1, count($cols))))) : self::LOTE;
        $insertar = static function () use (&$lote, &$bytes, &$hechas, $base, $cab, $marcas): void {
            if ($lote === []) { return; }
            $hechas += self::enviarPartiendo($lote, static fn(array $f) => Api::sql($base,
                $cab . implode(', ', array_fill(0, count($f), $marcas)), array_merge(...$f)));
            $lote = [];
            $bytes = 0;
        };
        try {
            foreach ($filas as $linea => $r) {
                if (count($r) !== count($cols)) {
                    throw new RuntimeException(t('tiene {n} campo(s) y la cabecera {cabecera}.', ['n' => count($r), 'cabecera' => count($cols)]));
                }
                $tam = $porApi ? strlen((string)json_encode($r, JSON_UNESCAPED_UNICODE)) : 0;
                if ($lote !== [] && $bytes + $tam > self::API_BYTES) {
                    $insertar();
                }
                $lote[] = $r;
                $bytes += $tam;
                if (count($lote) >= $maxFilas) {
                    $insertar();
                }
            }
            $insertar();
        } catch (Throwable $e) {
            throw new RuntimeException($deshacible
                ? t('El problema está en la línea {linea} o en las {lote} anteriores: {error}. No se ha cargado nada: la tabla ha vuelto a como estaba.',
                    ['linea' => $linea, 'lote' => max(1, count($lote)), 'error' => rtrim($e->getMessage(), '. ')])
                : t('Se cargaron {n} fila(s); el problema está en la línea {linea} o en las {lote} anteriores: {error}. Lo cargado ya está dentro: la importación no va en una transacción.',
                    ['n' => $hechas, 'linea' => $linea, 'lote' => max(1, count($lote)), 'error' => rtrim($e->getMessage(), '. ')]), 0, $e);
        }
        $cargadas = $hechas;
        return t("{n} fila(s) cargadas en '{tabla}'.", ['n' => $hechas, 'tabla' => $tabla]);
    }

    /** Importa la primera hoja de un .xlsx en una tabla; con $rutaBase, todo o nada (como sql()). */
    public static function xlsx(string $fichero, string $base, string $tabla, ?string $rutaBase = null): string
    {
        if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
            throw new RuntimeException(t('Importar un Excel necesita las extensiones zip y xml de PHP.'));
        }
        return self::deshacible($base, $rutaBase, static function (bool $d) use ($fichero, $base, $tabla): string {
            $hoja = new LectorXlsx($fichero);
            try {
                $filas = $hoja->filas();
                $cols  = $filas->valid() ? array_map(static fn($v): string => trim((string)$v), $filas->current()) : [];
                $filas->next();
                $resto = (static function () use ($filas, $cols): Generator {
                    // Las filas, con tantos valores como columnas: Excel no guarda
                    // las celdas vacías del final de una fila, y una con formato
                    // pero sin dato llega vacía. Un dato de más sí es un error
                    for (; $filas->valid(); $filas->next()) {
                        $r = array_pad($filas->current(), count($cols), null);
                        while (count($r) > count($cols) && end($r) === null) {
                            array_pop($r);
                        }
                        yield $filas->key() => $r;
                    }
                })();
                return self::cargarFilas($cols, $resto, $base, $tabla, $d);
            } finally {
                $hoja->cerrar();
            }
        });
    }

    /**
     * Importa un JSON en una tabla (2.9): una lista de objetos, una fila cada
     * uno, que es también lo que exporta el panel. Las columnas son las del
     * primer objeto; a una fila que no trae alguna se le pone NULL, y una que
     * trae otra que no está en el primero es un error. Un valor que es una
     * lista o un objeto se guarda como texto JSON; true y false, como 1 y 0.
     * Se lee por partes (ver JsonFlujo): vale aunque no quepa en la memoria.
     */
    public static function json(string $fichero, string $base, string $tabla, ?string $rutaBase = null): string
    {
        return self::deshacible($base, $rutaBase, static function (bool $d) use ($fichero, $base, $tabla): string {
            $fh = @fopen($fichero, 'rb');
            if ($fh === false) {
                throw new RuntimeException(t('No se puede leer el fichero subido.'));
            }
            try {
                $flujo  = new JsonFlujo($fh, self::topeDeMemoria());
                if ($flujo->siguiente() !== '[') {
                    throw new RuntimeException(t('El JSON tiene que ser una lista de objetos, una por fila: [{"columna": valor, …}, …].'));
                }
                [$cols, $filas] = self::filasJson($flujo);
                if ($cols === []) {
                    throw new RuntimeException(t('El JSON no trae ninguna fila: tiene que ser una lista de objetos, [{"columna": valor, …}, …].'));
                }
                $r = self::cargarFilas($cols, $filas, $base, $tabla, $d);
                if ($flujo->quedaAlgo()) {
                    throw new RuntimeException(t('Detrás de la lista hay algo más: el fichero tiene que ser solo la lista de filas.'));
                }
                return $r;
            } finally {
                fclose($fh);
            }
        });
    }

    /**
     * Las filas de una lista JSON de objetos, la siguiente del flujo: sus
     * columnas (las del primer objeto; [] si la lista está vacía) y las filas,
     * cada una con un valor por columna. A una fila que no trae alguna se le
     * pone NULL; una que trae otra que no está en la primera es un error.
     *
     * @return array{0: list<string>, 1: Generator<int, list<mixed>>}
     */
    private static function filasJson(JsonFlujo $flujo): array
    {
        $lista = $flujo->lista();
        if (!$lista->valid()) {
            return [[], (static fn(): Generator => yield from [])()];
        }
        $primera = self::objetoJson($flujo->valor(), 1);
        $cols = array_map('strval', array_keys($primera));
        $filas = (static function () use ($lista, $flujo, $cols, $primera): Generator {
            yield 1 => array_values($primera);
            for ($lista->next(); $lista->valid(); $lista->next()) {
                $n = $lista->current();
                $o = self::objetoJson($flujo->valor(), $n);
                $sobra = array_diff(array_keys($o), $cols);
                if ($sobra !== []) {
                    throw new RuntimeException(t('la fila {n} trae la columna «{col}», que no está en la primera', ['n' => $n, 'col' => (string)reset($sobra)]));
                }
                $fila = [];
                foreach ($cols as $c) {
                    $fila[] = $o[$c] ?? null;
                }
                yield $n => $fila;
            }
        })();
        return [$cols, $filas];
    }

    /**
     * Una base entera en el JSON de jsonSQLDB (2.9; ver Exportar::base()):
     * "estructura" (el SQL de las tablas y sus índices), "tablas" (cada una con
     * su "nombre" y sus "filas", padres antes que hijos) y "despues" (las
     * claves foráneas que forman un ciclo, las vistas y los triggers), en ese
     * orden. Las demás claves se saltan. Se lee por partes.
     *
     * @param resource $fh
     */
    private static function importarJsonBase($fh, string $base, bool $deshacible): string
    {
        $tablas = $filas = 0;
        try {
            $flujo = new JsonFlujo($fh, self::topeDeMemoria());
            if ($flujo->siguiente() !== '{') {
                throw new RuntimeException(t('El fichero no es un volcado JSON de jsonSQLDB: tiene que ser un objeto con "estructura", "tablas" y "despues".'));
            }
            $leidas = [];
            foreach ($flujo->objeto() as $clave) {
                if ($clave === 'estructura' || $clave === 'despues') {
                    $sql = $flujo->valor();
                    if (!is_string($sql) || ($clave === 'despues' && !isset($leidas['estructura']))) {
                        throw new RuntimeException(t('Volcado JSON mal formado: "{clave}" tiene que ser un texto con SQL, y "estructura" ir antes que "tablas" y "despues".', ['clave' => $clave]));
                    }
                    $m = fopen('php://temp', 'w+b');
                    fwrite($m, $sql);
                    rewind($m);
                    self::importarSql($m, $base, 'jsonsqldb', $deshacible);
                    $leidas[$clave] = true;
                    continue;
                }
                if ($clave !== 'tablas') {
                    $flujo->saltar();                         // formato, base, fecha…
                    continue;
                }
                if (!isset($leidas['estructura'])) {
                    throw new RuntimeException(t('Volcado JSON mal formado: "{clave}" tiene que ser un texto con SQL, y "estructura" ir antes que "tablas" y "despues".', ['clave' => $clave]));
                }
                foreach ($flujo->lista() as $_) {
                    $tabla = null;
                    foreach ($flujo->objeto() as $k) {
                        if ($k === 'nombre') {
                            $tabla = (string)$flujo->valor();
                        } elseif ($k === 'filas' && $tabla !== null) {
                            [$cols, $filasTabla] = self::filasJson($flujo);
                            if ($cols !== []) {
                                try {
                                    self::cargarFilas($cols, $filasTabla, $base, $tabla, $deshacible, $cargadas);
                                } catch (RuntimeException $e) {
                                    throw new RuntimeException(t('Tabla {tabla}: {error}', ['tabla' => $tabla, 'error' => $e->getMessage()]), 0, $e);
                                }
                                $filas += $cargadas;
                            }
                            $tablas++;
                        } else {
                            $flujo->saltar();
                        }
                    }
                }
            }
            if ($flujo->quedaAlgo()) {
                throw new RuntimeException(t('Detrás del volcado hay algo más.'));
            }
        } finally {
            fclose($fh);
        }
        return t('{t} tabla(s) y {f} fila(s) cargadas (volcado JSON de jsonSQLDB).', ['t' => $tablas, 'f' => $filas]);
    }

    /**
     * Una fila del JSON: un objeto, con sus valores en lo que admite la base.
     *
     * @return array<string, mixed>
     */
    private static function objetoJson($v, int $n): array
    {
        if (!is_array($v) || ($v !== [] && array_is_list_compat($v))) {
            throw new RuntimeException(t('la fila {n} no es un objeto {"columna": valor, …}', ['n' => $n]));
        }
        foreach ($v as $k => $x) {
            if (is_bool($x)) {
                $v[$k] = $x ? 1 : 0;
            } elseif (is_array($x)) {
                $v[$k] = json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            }
        }
        return $v;
    }

    /**
     * Dos filas de ejemplo para una tabla, con un valor de su tipo en cada
     * columna: las plantillas de importar en CSV, Excel y JSON (2.9).
     *
     * @param list<array<string,mixed>> $columnas las de SHOW COLUMNS
     * @return list<array<string,mixed>>
     */
    public static function filasDeEjemplo(array $columnas): array
    {
        $filas = [[], []];
        foreach ($columnas as $c) {
            $nombre = (string)$c['columna'];
            $tipo   = strtoupper((string)$c['tipo']);
            foreach ([1, 2] as $n) {
                $filas[$n - 1][$nombre] = match (true) {
                    str_contains($tipo, 'INT')                                     => $n,
                    (bool)preg_match('/DOUBLE|FLOAT|REAL|DEC|NUM/', $tipo)         => $n * 10 + 0.5,
                    str_contains($tipo, 'DATE') || str_contains($tipo, 'TIME')     => $n === 1 ? '2026-01-31' : '2026-02-28 13:45:00',
                    default                                                         => $nombre . ' ' . $n,
                };
            }
        }
        return $filas;
    }

    /**
     * Los ficheros que niegan el acceso a la carpeta de una base desde el
     * navegador, los mismos que pone el motor al crearla (Storage::crear()).
     * Los de un ZIP no se restauran (2.9): uno a medida abría la carpeta.
     */
    private const PROTECCION = [
        '.htaccess'  => "Require all denied\nDeny from all\n",
        'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security>"
                      . "<authorization><deny users=\"*\" /></authorization>"
                      . "</security></system.webServer></configuration>\n",
    ];

    private const MAX_FICHEROS = 2000;

    /**
     * Restaura $zip dentro de $rutaBase, que es la carpeta de la base.
     *
     * @param string $zip       fichero .zip subido
     * @param string $rutaBase  carpeta de datos de esa base
     * @return string resumen de lo hecho
     */
    public static function zip(string $zip, string $rutaBase): string
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
            if (array_filter($entradas, static fn(string $d): bool => !str_contains($d, '/.reparados/')) === []) {
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
                // Si PHP para a mitad (tiempo o memoria), la base vuelve a como
                // estaba al acabar el proceso (2.9): quedaba vacía o a medias, y
                // la copia de antes la borraba la restauración siguiente
                $aMedias = true;
                register_shutdown_function(static function () use (&$aMedias, $copia, $rutaBase): void {
                    if ($aMedias) {
                        self::devolver($copia, $rutaBase);
                    }
                });
                try {
                    self::vaciar($rutaBase);
                    foreach ($entradas as $interna => $destino) {
                        $dir = dirname($destino);
                        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                            throw new RuntimeException(t('No se puede crear la carpeta \'{dir}\'.', ['dir' => $dir]));
                        }
                        self::extraer($arch, $interna, $destino);
                    }
                    foreach (self::PROTECCION as $nombre => $texto) {
                        if (!is_file("$rutaBase/$nombre") && @file_put_contents("$rutaBase/$nombre", $texto) === false) {
                            throw new RuntimeException(t('No se puede escribir {fichero}.', ['fichero' => $nombre]));
                        }
                    }
                } catch (Throwable $e) {
                    $aMedias = false;
                    self::devolver($copia, $rutaBase);
                    throw new RuntimeException(
                        t('La restauración falló y se ha dejado la base como estaba. {error}', ['error' => $e->getMessage()])
                    );
                } finally {
                    self::olvidarCache();           // la base es otra: sus revisiones vuelven a empezar
                }
                $aMedias = false;
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
            // De las carpetas de dentro, solo .reparados/ (2.9), que la copia del
            // panel incluye: los originales de lo reparado y las filas apartadas.
            // Las demás se ignoran. Con .cache/, OPcache cargaría como código un
            // .php del ZIP; con .tx/ o .lote/, el motor tomaba lo que trajeran por
            // una escritura a medias al abrir la base, y borraba o reemplazaba
            // ficheros de su carpeta, también su .htaccess y su web.config
            if (count($partes) > 1) {
                if (count($partes) === 2 && $partes[0] === '.reparados' && self::esReparado($arch, $i, $partes[1])) {
                    $out[$interna] = $rutaBase . '/.reparados/' . $partes[1];
                }
                continue;
            }
            $fichero = $partes[0];

            if (substr($fichero, -5) !== '.json') {
                continue;                                   // lo demás se ignora
            }
            self::validarNombreJson($fichero);
            self::validarContenido($arch, $i, $fichero);

            $out[$interna] = $rutaBase . '/' . $fichero;
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

    /** Los ficheros de la base que no son de ninguna tabla */
    private const INTERNOS = ['_database.json', '_revs.json', '_views.json'];

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
        if (in_array($fichero, self::INTERNOS, true)) {
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

    /**
     * ¿Es de .reparados/? El original de un fichero reparado, con la fecha
     * detrás (usuarios.json.20261006-120000), o la lista de filas apartadas.
     */
    private static function esReparado(ZipArchive $arch, int $i, string $fichero): bool
    {
        if ($fichero === 'apartadas.json') {
            self::exigirQueQuepa($arch, $i, $fichero);
            $v = json_decode((string)$arch->getFromIndex($i), true);
            return is_array($v) && is_array($v['filas'] ?? null);
        }
        return preg_match('/^(?:_database|_views|_revs|[A-Za-z_][A-Za-z0-9_]{0,63}(?:\.meta|\.rev|\.idx\.[A-Za-z_][A-Za-z0-9_]{0,63})?(?:\.part\d+)?)\.json\.\d{8}-\d{6}$/', $fichero) === 1;
    }

    /**
     * Copia un fichero del ZIP a $destino a trozos (2.9): leído entero, uno
     * grande de .reparados/ podía agotar la memoria a mitad de la restauración.
     */
    private static function extraer(ZipArchive $arch, string $interna, string $destino): void
    {
        $de = $arch->getStream($interna);
        if ($de === false) {
            throw new RuntimeException(t('No se pudo leer \'{interna}\' del ZIP.', ['interna' => $interna]));
        }
        $a = @fopen($destino, 'wb');
        $copiados = $a === false ? false : stream_copy_to_stream($de, $a);
        fclose($de);
        if ($a === false || !fclose($a) || $copiados === false) {
            throw new RuntimeException(t('No se puede escribir {fichero}.', ['fichero' => basename($destino)]));
        }
        if ($copiados !== (int)($arch->statName($interna)['size'] ?? -1)) {
            throw new RuntimeException(t('No se pudo leer \'{interna}\' del ZIP.', ['interna' => $interna]));
        }
    }

    /** Un fichero del ZIP que se va a leer entero tiene que caber (2.9): uno enorme agotaba la memoria */
    private static function exigirQueQuepa(ZipArchive $arch, int $i, string $fichero): void
    {
        $tope = self::topeDeMemoria(3);
        if ((int)($arch->statIndex($i)['size'] ?? 0) > $tope) {
            throw new RuntimeException(t('\'{fichero}\' ocupa más de {mb} MB y no cabe en la memoria de PHP (memory_limit = {limite}). No se ha tocado nada.',
                ['fichero' => $fichero, 'mb' => max(1, intdiv($tope, 1048576)), 'limite' => (string)ini_get('memory_limit')]));
        }
    }

    /** El contenido tiene que ser JSON válido con la forma que espera el motor. */
    private static function validarContenido(ZipArchive $arch, int $i, string $fichero): void
    {
        self::exigirQueQuepa($arch, $i, $fichero);
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
        // los índices tienen su propia forma, y los internos también.
        $sinFilas = substr($fichero, -10) === '.meta.json'
                 || substr($fichero, -9)  === '.rev.json'
                 || strpos($fichero, '.idx.') !== false
                 || in_array($fichero, self::INTERNOS, true);
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

    /**
     * Vacía la carpeta de una base, salvo sus ficheros de bloqueo (los tiene
     * cogidos quien la cambia) y los que niegan el acceso desde el navegador.
     */
    private static function vaciar(string $rutaBase): void
    {
        foreach ((array)scandir($rutaBase) as $f) {
            if ($f === '.' || $f === '..' || esFicheroDeBloqueo((string)$f) || isset(self::PROTECCION[$f])) {
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

/**
 * Filtro de lectura que cuenta lo leído desde la última vez que se puso
 * $leido a 0 y corta la lectura pasado $tope (ver Importar::importarCsv()).
 * Al cortar, quien lee recibe lo que llevaba, y mira $leido para saberlo.
 */
final class TopeDeLinea extends php_user_filter
{
    public static int $leido = 0;
    public static int $tope  = PHP_INT_MAX;

    /** Sin tipo en $closing: PHP 8.0 no lo lleva, y añadirlo no sería compatible */
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($cubo = stream_bucket_make_writeable($in)) {
            self::$leido += $cubo->datalen;
            if (self::$leido > self::$tope) {
                return PSFS_ERR_FATAL;
            }
            $consumed += $cubo->datalen;
            stream_bucket_append($out, $cubo);
        }
        return PSFS_PASS_ON;
    }
}
