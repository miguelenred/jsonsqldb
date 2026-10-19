<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Lo que el almacenamiento solo hace al leer: recuentos de filas, filas por
 * índice, por el orden de un índice o según la revisión, las partes que una
 * consulta se puede saltar y la caché de resultados. Métodos de Storage que
 * reciben el almacenamiento en $st. Van aparte para que una escritura no
 * tenga que compilarlos (2.9): sin OPcache, PHP compila en cada petición lo
 * que carga. Hereda de Storage solo para llegar a lo protegido; no se
 * instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageLectura extends Storage
{
    private function __construct()
    {
    }

    /**
     * Las partes enteras que se pueden saltar para dejar atrás $filas filas
     * (2.9): [primera parte que hay que leer, filas que quedan atrás]. Cada
     * parte tiene las filas de su tamaño menos los huecos que el estado le
     * cuenta; la última, las que falten, y nunca se salta. Sin tamaño en el
     * estado (tablas de antes de la 2.2), no se salta nada. Aquí, con lo que
     * carga cualquier lectura (2.10: en StorageOrden, un OFFSET compilaba
     * entero lo de ORDER BY … LIMIT).
     *
     * @return array{0: int, 1: int}
     */
    public static function partesQueSalta(Storage $st, string $tabla, int $filas): array
    {
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $estado = $st->estado($tabla);
        $chunk  = (int)($estado['chunk'] ?? 0);
        $total  = (int)($estado['rows'] ?? 0);
        if ($chunk <= 0 || $total <= 0) {
            return [1, 0];
        }
        $huecos   = (array)($estado['huecos'] ?? []);
        $parte    = 1;
        $saltadas = 0;
        while ($parte * $chunk < $total) {              // no es la última
            $tiene = $chunk - (int)($huecos[$parte - 1] ?? 0);
            if ($saltadas + $tiene > $filas || !is_file($st->ficheroDatos($tabla, $parte))) {
                break;
            }
            $saltadas += $tiene;
            $parte++;
        }
        return [$parte, $saltadas];
    }

    /**
     * Cuenta las filas de una tabla sin construirlas en memoria: el motor
     * escribe una fila por línea, así que contar es leer líneas. A propósito no
     * mira la caché ni la revisión: cuenta lo que hay en el fichero.
     */
    public static function contarFilas(Storage $st, string $tabla): int
    {
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);

        // El recuento de cada parte se guarda en la caché (2.9) con lo que lo
        // hace válido: la revisión de la parte, su tamaño y su fecha. Si algo
        // de eso cambia (una escritura, o una edición a mano) se vuelve a
        // contar esa parte, y solo esa. El de una parte modificada en este
        // mismo segundo no se guarda: otra edición dentro del mismo segundo
        // podría dejarla con el mismo tamaño y la misma fecha (lo mismo que
        // hace git para no fiarse de una fecha reciente)
        $clave    = $st->prefijo . $st->etiqueta($tabla) . ':n:0';
        $guardado = $st->cacheLeer($clave);
        $guardado = \is_array($guardado) ? $guardado : [];
        $estado   = $st->estado($tabla);
        $ahora    = time();
        $nuevo    = [];
        $n = 0;
        for ($parte = 1; ; $parte++) {
            $fichero = $st->ficheroDatos($tabla, $parte);
            clearstatcache(true, $fichero);
            $info = @stat($fichero);
            if ($info === false) {
                $st->exigirParte($tabla, $parte);
                break;
            }
            $sello = [(int)($estado['parts'][$parte - 1] ?? $estado['rev']), (int)$info['size'], (int)$info['mtime']];
            $antes = $guardado[$parte] ?? null;
            $cuantas = \is_array($antes) && \array_slice($antes, 0, 3) === $sello
                ? (int)$antes[3]
                : (self::contarLineasDeParte($st, $fichero) ?? \count($st->decodificarParte($fichero)));
            if ($sello[2] < $ahora) {
                $nuevo[$parte] = [$sello[0], $sello[1], $sello[2], $cuantas];
            }
            $n += $cuantas;
        }
        if ($nuevo !== $guardado) {
            $st->cacheGuardar($clave, $nuevo);
        }
        return $n;
    }

    /**
     * Cuenta las filas de un fichero de datos canónico (una por línea). Devuelve
     * null si no está en ese formato —editado a mano o compactado— y hay que
     * decodificarlo. En un fichero canónico con una fila corrupta cuenta lo que
     * parece una fila; detectar eso es cosa de CHECK TABLES.
     */
    private static function contarLineasDeParte(Storage $st, string $fichero): ?int
    {
        // Lo normal, una parte que cabe de sobra: entera y contada por PCRE, que
        // es lo más rápido. Más grande que una cuarta parte de la memoria libre
        // (filas de muchos MB), a trozos de 64 KB, que no la llenan (2.9)
        $tam = @filesize($fichero);
        $limite = Memoria::limiteEnBytes();
        if ($tam !== false && ($limite <= 0 || $tam < intdiv($limite - memory_get_usage(), 4))) {
            $texto = @file_get_contents($fichero);
            if ($texto === false) {
                throw JsonSqlDbError::io('No se puede leer ' . basename($fichero));
            }
            if (!preg_match('/^[ \t]*"rows":[ \t]*\[[ \t]*(\])?/m', $texto, $m, PREG_OFFSET_CAPTURE)) {
                return null;
            }
            if (isset($m[1])) {
                return 0;                                  // "rows":[]
            }
            $fin = preg_match('/^[ \t]*\][ \t,]*\r?$/m', $texto, $f, PREG_OFFSET_CAPTURE, $m[0][1]) ? $f[0][1] : \strlen($texto);
            // Con las comas que sobren, como al leerla (2.9: con dos, la fila no contaba)
            return (int)preg_match_all('/^[ \t]*\{.*\}[ \t,]*\r?$/m', substr($texto, $m[0][1], $fin - $m[0][1]));
        }
        $fh = @fopen($fichero, 'rb');
        if ($fh === false) {
            throw JsonSqlDbError::io('No se puede leer ' . basename($fichero));
        }
        try {
            $enFilas = false;
            $n = 0;
            // A trozos de 64 KB (2.9): de cada línea basta su principio y su
            // final, y leerla entera copiaba en memoria filas de muchos MB
            // (tres filas de 12 MB tumbaban un SHOW TABLES con 32 MB)
            $cabeza = null;
            while (true) {
                $trozo = fgets($fh, 65536);
                if ($trozo === false && $cabeza === null) {
                    break;
                }
                $cabeza ??= ltrim((string)$trozo);
                if ($trozo !== false && substr($trozo, -1) !== "\n") {
                    continue;                         // la línea sigue en el trozo siguiente
                }
                $cola   = rtrim((string)$trozo, " \t\r\n,");
                $inicio = rtrim($cabeza, " \t\r\n,");
                $cabeza = null;
                if (!$enFilas) {
                    if (strncmp($inicio, '"rows":', 7) !== 0) {
                        if ($trozo === false) { break; }
                        continue;                     // cabecera
                    }
                    if (substr($cola, -2) === '[]') {
                        return 0;
                    }
                    $enFilas = true;
                } elseif ($inicio !== '' && $inicio[0] === '{' && substr($cola, -1) === '}') {
                    $n++;
                } elseif ($inicio === ']') {
                    break;                            // detrás vienen los desfases
                }
                if ($trozo === false) {
                    break;
                }
            }
            return $enFilas ? $n : null;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Cuántas filas tienen alguna de esas claves exactas: lo que daría
     * count(posicionesPorIndice()), pero sin juntar las posiciones ni
     * ordenarlas (2.9): de una lista se cuentan sus comas. Una fila tiene una
     * sola clave en cada índice, así que con las claves sin repetir, como las
     * da IndexesBusqueda::candidatos(), no se cuenta ninguna dos veces.
     *
     * @param list<string> $claves sin repetir
     */
    public static function contarPorIndice(Storage $st, string $tabla, array $def, array $claves): ?int
    {
        self::validarTabla($tabla);
        if (!$st->indices) {
            return null;
        }
        $st->bloquearLectura($tabla);
        $trozos = $st->trozosConClaves($tabla, $def, $claves);
        $n = 0;
        if ($trozos === [] || ($trozos !== null && $st->clavesEnTexto($tabla, $def, $trozos, $claves, false, $n) !== null)) {
            return $n;
        }
        $posiciones = $st->posicionesPorIndice($tabla, $def, $claves, false);
        return $posiciones === null ? null : \count($posiciones);
    }

    /**
     * Cuántas filas dice el fichero de revisión que tiene la tabla, sin los
     * huecos de las borradas (2.9), o null si no lo dice (bases de antes de la
     * 2.5). Es un dato de planificación, no
     * un recuento: para contar está contarFilas().
     */
    public static function filasSegunRevision(Storage $st, string $tabla): ?int
    {
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $estado = $st->estado($tabla);
        return isset($estado['rows']) ? (int)$estado['rows'] - array_sum((array)($estado['huecos'] ?? [])) : null;
    }

    /**
     * Las filas de esas posiciones, en el orden de la tabla, leyendo solo sus
     * partes; null si habría que leer más de media tabla.
     *
     * @param list<int> $posiciones ordenadas
     * @return list<array>|null
     */
    public static function filasDePosiciones(Storage $st, string $tabla, array $posiciones): ?array
    {
        $chunk = max(1, (int)($st->estado($tabla)['chunk'] ?? $st->filasPorParte));
        $todas = max(1, $st->partes($tabla));

        $necesarias = [];
        foreach ($posiciones as $p) {
            $necesarias[intdiv($p, $chunk) + 1] = ($necesarias[intdiv($p, $chunk) + 1] ?? 0) + 1;
        }
        // ¿Compensa frente a recorrer la tabla? Una parte con pocas filas
        // pedidas se lee por líneas, sin decodificarla: cuesta lo que sus
        // líneas, y LINEAS_COMO_PARTE líneas cuestan como recorrer una parte.
        // Antes contaba como una parte entera, y 100 claves repartidas por la
        // tabla renunciaban al índice y la recorrían entera. Si sale más de la
        // mitad de las partes, se recorre. Sin ninguna, se sale con la lista
        // vacía sin abrir un solo fichero.
        $coste = 0.0;
        foreach ($necesarias as $n) {
            $coste += $n <= self::FILAS_POR_LINEA ? $n / self::LINEAS_COMO_PARTE : 1;
        }
        if ($necesarias !== [] && $coste * 2 > $todas) {
            return null;
        }
        $porParte = [];
        foreach ($posiciones as $p) {
            $porParte[intdiv($p, $chunk) + 1][] = $p - intdiv($p, $chunk) * $chunk;
        }

        $filas = [];
        foreach ($porParte as $parte => $desfases) {
            $fichero = $st->ficheroDatos($tabla, $parte);
            if (!is_file($fichero)) {
                return null;                          // índice y datos no cuadran
            }
            // Pocas filas de una parte: se leen sus líneas, sin decodificar las
            // mil. Muchas, o una parte que no trae desfases: se decodifica
            if (\count($desfases) <= self::FILAS_POR_LINEA) {
                $sueltas = $st->filasEnParte($tabla, $parte, $fichero, $desfases);
                if ($sueltas !== null) {
                    foreach ($sueltas as $fila) {
                        $filas[] = $fila;
                    }
                    continue;
                }
            }
            $buscadas = array_fill_keys($desfases, true);
            foreach ($st->parte($tabla, $parte, $fichero) as $desfase => $fila) {
                if (isset($buscadas[$desfase])) {
                    $filas[] = $fila;
                    Memoria::comprobar('la lectura por índice');
                }
            }
        }
        return $filas;
    }

    /**
     * Filas de una tabla que casan con unas claves de índice, leyendo solo las
     * partes donde están. Devuelve null si el índice no sirve o no compensa, y
     * entonces hay que recorrer la tabla. Las filas salen en el orden de la
     * tabla, que es el que espera un SELECT sin ORDER BY.
     *
     * @param array{name: string, columns: list<string>} $def
     * @param list<string> $claves
     * @return list<array>|null
     */
    public static function filasPorIndice(Storage $st, string $tabla, array $def, array $claves, bool $prefijo, ?int $tope = null, bool $parcial = false): ?array
    {
        // Con claves numéricas se sabe de antemano en qué parte cae cada una
        // (el rango de su trozo): si no va a compensar, se renuncia sin buscar
        if (!$prefijo && $tope === null && self::noCompensa($st, $tabla, $def, $claves)) {
            return null;
        }
        $posiciones = $st->posicionesPorIndice($tabla, $def, $claves, $prefijo, $tope, $parcial);
        if ($posiciones !== null && $tope !== null) {
            $posiciones = \array_slice($posiciones, 0, $tope);   // las primeras en el orden de la tabla (2.9)
        }
        return $posiciones === null ? null : self::filasDePosiciones($st, $tabla, $posiciones);
    }

    /**
     * ¿Leer estas claves numéricas por el índice saldría más caro que recorrer
     * la tabla? Cada una cae en la parte cuyo trozo la contiene por rango; con
     * el mismo cálculo que filasPorIndice() hace después con las posiciones.
     */
    private static function noCompensa(Storage $st, string $tabla, array $def, array $claves): bool
    {
        if (\count($claves) <= self::FILAS_POR_LINEA) {
            return false;
        }
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null || $plan['legado'] !== null || $plan['partes'] === 0) {
            return false;
        }
        $valores = [];
        foreach ($claves as $c) {
            $v = Indexes::valorNumerico($c);
            if ($v === null) {
                return false;                       // de texto: puede estar en cualquiera
            }
            $valores[] = $v;
        }
        sort($valores);
        // Por trozo, cuántas caen en su rango: dos búsquedas binarias, no un
        // recorrido de todas las claves por cada trozo
        // Primera posición con un valor mayor ($incluido: o igual) que $x
        $corte = static function (array $a, $x, bool $incluido): int {
            $lo = 0;
            $hi = \count($a);
            while ($lo < $hi) {
                $m = ($lo + $hi) >> 1;
                if ($incluido ? $a[$m] < $x : $a[$m] <= $x) { $lo = $m + 1; } else { $hi = $m; }
            }
            return $lo;
        };
        $coste = 0.0;
        foreach ($plan['rangos'] as $r) {
            if ($r === null) {
                return false;                       // un trozo sin acotar: no se puede estimar
            }
            if ($r === []) {
                continue;
            }
            $n = $corte($valores, $r[1], false) - $corte($valores, $r[0], true);
            if ($n > 0) {
                $coste += $n <= self::FILAS_POR_LINEA ? $n / self::LINEAS_COMO_PARTE : 1;
            }
        }
        return $coste * 2 > max(1, $st->partes($tabla));
    }

    /**
     * Clave de caché de un resultado de consulta: la SQL con sus parámetros,
     * la revisión de cada tabla implicada, la firma de cada vista (las
     * dependencias que empiezan por '#', ver Select::tablasDe()) y lo que la
     * configuración cambia en un resultado: la colación, su mapa y la versión
     * del motor. Cambiar cualquiera de ellas es otra clave, así que un
     * resultado guardado nunca sobrevive a una escritura, a una vista
     * redefinida, a otra colación ni a una actualización del motor. Una edición
     * a mano tampoco: el resultado lleva los sellos de los ficheros que leyó
     * (2.9, ver resultadoCacheado()).
     *
     * @param list<string> $tablas
     */
    public static function claveResultado(Storage $st, array $tablas, string $sql, array $params): string
    {
        $revs = Config::version() . '|' . Config::colacion() . '|' . serialize(Config::colacionMapa()) . ';';
        foreach ($tablas as $t) {
            if ($t[0] === '#') {
                $revs .= $t . ';';
                continue;
            }
            self::validarTabla($t);
            $st->bloquearLectura($t);
            $estado = $st->estado($t);
            $revs  .= self::etiquetaDe($t, $estado) . ':' . $estado['rev'] . ';';
        }
        return $st->prefijo . '_q:r:' . md5($sql . "\0" . serialize($params) . "\0" . $revs);
    }

    /** La clave en APCu de un resultado: 4.096 en toda la base, por lo mismo (ver ficheroResultado()) */
    private static function cubetaResultado(Storage $st, string $clave): string
    {
        return substr($clave, 0, -32) . substr($clave, -3);
    }

    /**
     * El fichero de un resultado: su nombre dice de qué tablas depende, para
     * que la limpieza de una escritura en cualquiera de ellas lo borre (ver
     * StorageEscritura::limpiarCache()). Antes se buscaba con glob(), listando la carpeta de la
     * caché en cada acierto: 0,1 ms con 40 ficheros y 10 ms con 20.000. Ahora
     * se construye el nombre exacto.
     *
     * Los resultados de unas mismas tablas van a 4.096 sitios como mucho
     * (2.9): antes cada consulta distinta dejaba un fichero hasta la siguiente
     * escritura, y una tabla que solo se lee, consultada fila a fila, los
     * acumulaba sin fin. Dos que caen en el mismo sitio se pisan: el resultado
     * lleva su clave y solo vale para ella.
     *
     * @param list<string> $tablas
     */
    private static function ficheroResultado(Storage $st, string $clave, array $tablas): string
    {
        $nombre = 'q';
        foreach ($tablas as $t) {
            if ($t[0] !== '#') {                          // una vista: su firma ya va en la clave
                $nombre .= '.' . md5($st->prefijo . $t);
            }
        }
        return $st->dirCache . '/' . $nombre . '.' . substr($clave, -3) . '.cache';
    }

    /**
     * Guarda un resultado. En disco el nombre del fichero dice de qué tablas
     * depende, para que la limpieza de las escrituras en cualquiera de ellas
     * lo tire (ver StorageEscritura::limpiarCache()); hasta entonces su clave ya no coincide.
     * En APCu no se puede buscar por nombre: caduca solo.
     *
     * @param list<string> $tablas
     * @param list<array>  $filas
     */
    public static function guardarResultado(Storage $st, string $clave, array $tablas, array $filas): void
    {
        if (!$st->cache || Memoria::apretado()) {
            return;
        }
        $valor = ['k' => $clave, 'f' => $filas];
        if ($st->apcu) {
            if (self::apcuConSitio()) {
                apcu_store(self::cubetaResultado($st, $clave), $valor, 3600);
            }
            return;
        }
        if (!$st->cacheDisco || !is_dir($st->dirCache) && !@mkdir($st->dirCache, 0775, true) && !is_dir($st->dirCache)) {
            return;
        }
        // En disco, hasta 1 MB (2.9): sin tope, una clave de solo lectura podía
        // llenarlo con resultados enormes, uno por consulta distinta. En APCu
        // no hace falta: su memoria tiene un tamaño y lo viejo sale solo
        $texto = serialize($valor);
        if (\strlen($texto) > self::RESULTADO_MAXIMO_DISCO) {
            return;
        }
        foreach ($tablas as $t) {
            if ($t[0] !== '#') {
                @touch($st->marcaResultados($t));   // antes que el resultado: ver StorageEscritura::limpiarCache()
            }
        }
        @file_put_contents(self::ficheroResultado($st, $clave, $tablas), $texto);
    }

    /**
     * Las filas guardadas de una consulta, o null si no están o ya no valen.
     *
     * @param list<string> $tablas las mismas con las que se calculó la clave
     * @return list<array>|null
     */
    public static function resultadoCacheado(Storage $st, string $clave, array $tablas): ?array
    {
        $v = $st->cacheLeer(self::cubetaResultado($st, $clave), self::ficheroResultado($st, $clave, $tablas));
        // Otra consulta puede haber caído en el mismo sitio (ver ficheroResultado())
        // Sin comprobar los sellos de lo que leyó la consulta: los 52 stat() de un
        // JOIN de dos tablas hacían el acierto más lento que en 2.8.0, el doble
        // con open_basedir. Una edición a mano la ve la siguiente consulta que
        // lea ese fichero, que reescribe la tabla y con ello retira sus resultados
        return \is_array($v) && ($v['k'] ?? null) === $clave && isset($v['f']) ? $v['f'] : null;
    }

}
