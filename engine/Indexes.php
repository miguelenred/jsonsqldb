<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Índices de búsqueda.
 *
 * Un índice va en ficheros aparte, uno por parte de la tabla —
 * `<tabla>.idx.<nombre>.json`, `<tabla>.idx.<nombre>.part2.json`, ...—, y cada
 * uno asocia el valor de una o varias columnas con las posiciones de las filas
 * de esa parte que lo tienen:
 *
 *   {"index":"...","table":"...","columns":["email"],"part":2,"rev":7,"chunk":1000,
 *    "keys":{"t11:ana@ej.com":1143,"t6:Madrid":[1002,1009,1015],...}}
 *
 * Una posición sola se guarda como entero y varias como lista: en una clave
 * primaria son todas únicas y una lista de uno costaría el triple en memoria.
 * Los índices de antes de la 2.6 eran un solo fichero con todas las claves
 * (y antes de la 2.5 con listas siempre); se leen igual y se reparten en
 * trozos en la siguiente escritura.
 *
 * Para qué sirve. El coste de un SELECT no está en evaluar el WHERE, está en
 * leer y decodificar los ficheros de la tabla entera. El índice dice en qué
 * posiciones están las filas buscadas, y de ahí se deduce en qué partes
 * (`<tabla>.partN.json`) viven: se decodifican solo esas. En una tabla de veinte
 * partes, buscar por una columna indexada lee una en vez de veinte. Las
 * escrituras lo usan también: un INSERT comprueba la unicidad contra el índice
 * y un UPDATE o DELETE por clave lee solo la parte de la fila.
 *
 * Para qué NO sirve. Solo acelera igualdades e IN. Los rangos, LIKE, ORDER BY y
 * los agregados siguen leyendo la tabla completa.
 *
 * Cómo se mantiene. Las posiciones no son estables: al guardar, las filas se
 * reindexan desde cero y se reparten en partes, así que un DELETE desplaza
 * todas las filas siguientes. Los trozos anteriores se corrigen cuando se
 * puede demostrar qué cambió —filas añadidas al final, sustituidas en su
 * sitio, o desplazadas a partir de una posición— y solo se reescriben los
 * trozos afectados; ante la menor duda el índice se rehace entero (ver
 * Storage). Un trozo que no cambia no se reescribe.
 *
 * Claves. La igualdad del motor no es la de PHP: 5, '5' y '5.0' son el mismo
 * valor (ver Valor::comparar). La clave lo respeta —los valores numéricos se
 * normalizan a número y el resto a texto— porque si no, buscar 5 no encontraría
 * las filas que guardan '5'. Cada trozo lleva su longitud por delante para que
 * la concatenación de varias columnas no sea ambigua y para poder buscar por
 * prefijo: un índice sobre (a, b) sirve para buscar solo por a.
 *
 * Los NULL no se indexan: ninguna igualdad los encuentra nunca.
 *
 * Qué condiciones de un WHERE puede resolver un índice, y con cuál, está en
 * IndexesBusqueda: una escritura que no busca filas no tiene que compilarlo.
 *
 * https://miguelenred.es/jsonsqldb
 */
class Indexes
{
    /** Prefijo reservado para los índices automáticos de PK y UNIQUE. */
    public const PREFIJO_AUTO = 'auto_';

    private const RE_NOMBRE = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /**
     * Tope de combinaciones de clave que se buscan de una vez. Un IN de tres
     * columnas con diez valores cada una son mil búsquedas: a partir de ahí sale
     * más barato recorrer la tabla.
     */
    protected const MAX_CLAVES = 512;
    /**
     * Con claves numéricas el tope es mayor: cada una se busca solo en el trozo
     * cuyo rango la contiene, así que 4.000 claves cuestan unas 4.000 búsquedas
     * pequeñas. Con texto cada clave se busca en todos los trozos
     */
    protected const MAX_CLAVES_NUMERICAS = 4096;

    public static function validarNombre(string $nombre): void
    {
        if (!preg_match(self::RE_NOMBRE, $nombre)) {
            throw JsonSqlDbError::schema("Nombre de índice no válido: '$nombre'");
        }
        if (stripos($nombre, self::PREFIJO_AUTO) === 0) {
            throw JsonSqlDbError::schema(
                "'$nombre': el prefijo '" . self::PREFIJO_AUTO . "' está reservado para los "
                . 'índices automáticos de PRIMARY KEY y UNIQUE'
            );
        }
    }

    // ------------------------------------------------------------------
    // Definiciones
    // ------------------------------------------------------------------

    /**
     * Índices efectivos de una tabla: los creados a mano más los automáticos de
     * PRIMARY KEY y UNIQUE.
     *
     * Si un índice a mano cubre exactamente las mismas columnas que uno
     * automático, sobra el automático: sería el mismo fichero dos veces.
     *
     * 'minimo' es cuántas de sus primeras columnas hay que buscar para poder
     * usarlo (ver sirve()).
     *
     * @return list<array{name: string, columns: list<string>, auto: bool, minimo: int}>
     */
    public static function definiciones(array $meta): array
    {
        $out    = [];
        $vistas = [];
        $noNulas = [];
        foreach ($meta['columns'] as $c) {
            if (!empty($c['notnull'])) {
                $noNulas[strtolower((string)$c['name'])] = true;
            }
        }
        // Una fila con NULL en alguna columna del índice no está en él: buscar
        // solo por las primeras perdería las que lo tienen en las de detrás
        // (2.9.1: WHERE a = 1 con un índice sobre (a, b) no veía las filas con
        // b NULL, en UPDATE y DELETE desde la 2.5.0 y en SELECT y COUNT desde
        // la 2.7.4). Vale desde donde todas las
        // que quedan detrás son NOT NULL
        $minimo = static function (array $cols) use ($noNulas): int {
            $m = count($cols);
            while ($m > 1 && isset($noNulas[strtolower($cols[$m - 1])])) {
                $m--;
            }
            return $m;
        };
        // Los conjuntos únicos (clave primaria y UNIQUE): un índice sobre uno de
        // ellos devuelve una fila o ninguna por clave
        $unicos    = [];
        $conjuntos = Catalog::conjuntosUnicos($meta);
        foreach ($conjuntos as $uq) {
            $unicos[strtolower(implode("\0", array_map('strval', $uq['columns'])))] = true;   // un nombre puede llevar comas (2.9)
        }
        $colaciones = Catalog::colaciones($meta);          // 2.9

        foreach ($meta['indexes'] ?? [] as $idx) {
            $cols = array_values(array_map('strval', (array)($idx['columns'] ?? [])));
            if ($cols === []) {
                continue;
            }
            $clave = strtolower(implode("\0", $cols));
            if (isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $out[] = ['name' => (string)$idx['name'], 'columns' => $cols, 'auto' => false, 'unico' => isset($unicos[$clave]),
                      'plegar' => Catalog::plegarCon($colaciones, $cols), 'minimo' => $minimo($cols)];
        }

        $autos = [];
        foreach ($conjuntos as $uq) {
            $cols  = array_values(array_map('strval', $uq['columns']));
            $clave = strtolower(implode("\0", $cols));
            if ($cols === [] || isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $nombre = self::nombreAuto($cols);
            // Dos conjuntos con el mismo nombre ((a, b) y (a_b)) compartirían
            // fichero: el segundo se queda sin índice y se comprueba leyendo la
            // tabla. Una tabla así ya no se puede crear (ver autoRepetido())
            if (isset($autos[strtolower($nombre)])) {
                continue;
            }
            $autos[strtolower($nombre)] = true;
            $out[] = ['name' => $nombre, 'columns' => $cols, 'auto' => true, 'unico' => true,
                      'plegar' => $uq['plegar'], 'minimo' => $minimo($cols)];
        }

        return $out;
    }

    /** ¿Sirve el índice para buscar por sus $n primeras columnas (ver definiciones())? */
    public static function sirve(array $def, int $n): bool
    {
        return $n >= ($def['minimo'] ?? count($def['columns']));
    }

    /**
     * Dos conjuntos únicos de una tabla cuyo índice automático se llamaría
     * igual, o null (2.9): (a, b) y (a_b) dan los dos auto_a_b, el mismo
     * fichero, y antes ninguno de los dos funcionaba bien.
     *
     * @return array{0: string, 1: list<string>, 2: list<string>}|null nombre y las dos listas de columnas
     */
    public static function autoRepetido(array $meta): ?array
    {
        $vistos = [];
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $cols   = array_values(array_map('strval', $uq['columns']));
            $nombre = strtolower(self::nombreAuto($cols));
            if (isset($vistos[$nombre]) && strcasecmp(implode("\0", $vistos[$nombre]), implode("\0", $cols)) !== 0) {
                return [self::nombreAuto($cols), $vistos[$nombre], $cols];
            }
            $vistos[$nombre] = $cols;
        }
        return null;
    }

    /**
     * Nombre del índice automático de un conjunto de columnas.
     *
     * Se deriva de las columnas y no del nombre de la restricción, porque el
     * nombre acaba siendo parte de un nombre de fichero y el de una restricción
     * lo pone quien escribe la SQL, sin más validación. Si sale demasiado largo
     * para un nombre de fichero, se resume en un hash: sigue siendo el mismo
     * para las mismas columnas.
     */
    private static function nombreAuto(array $columnas): string
    {
        $nombre = self::PREFIJO_AUTO . implode('_', $columnas);
        // Una columna con espacios o signos no puede ir en un nombre de fichero
        return preg_match(self::RE_NOMBRE, $nombre)
            ? $nombre
            : self::PREFIJO_AUTO . md5(strtolower(implode(',', $columnas)));
    }

    // ------------------------------------------------------------------
    // Claves
    // ------------------------------------------------------------------

    /**
     * Clave de índice de una lista de valores, o null si alguno es NULL.
     *
     * @param list<mixed> $valores
     */
    public static function clave(array $valores): ?string
    {
        $clave = '';
        foreach ($valores as $v) {
            if ($v === null) {
                return null;
            }
            $clave .= self::trozo($v);
        }
        return $clave;
    }

    /**
     * Clave de índice de unas columnas de una fila, cada valor con la
     * colación de su columna ($plegar, por posición: ver
     * Catalog::plegarDe()), o null si alguno es NULL o la fila es un hueco
     * (null: una borrada, ver Storage). Trozo a trozo, sin juntar antes los
     * valores para clave() (2.9): desde que ADD UNIQUE, ADD PRIMARY KEY, ADD
     * FOREIGN KEY y CHECK KEYS comparan las claves así, con cada fila, esa
     * vuelta de más era entre un tercio y la mitad de lo que les añadía.
     *
     * @param list<string>       $columnas
     * @param array<int, ?string> $plegar
     */
    public static function claveFila(?array $fila, array $columnas, array $plegar = []): ?string
    {
        if ($fila === null) {
            return null;
        }
        $clave = '';
        foreach ($columnas as $i => $c) {
            $v = $fila[$c] ?? null;
            if ($v === null) {
                return null;
            }
            $clave .= self::trozo(isset($plegar[$i]) ? Functions::plegar($v, $plegar[$i]) : $v);
        }
        return $clave;
    }

    /**
     * Un valor convertido en trozo de clave: tipo, longitud y contenido.
     *
     * @param mixed $v
     */
    protected static function trozo($v): string
    {
        // Los dos atajos de abajo son el mismo resultado por el camino corto, y
        // aquí se nota: construir un índice llama a esto una vez por fila y por
        // columna, y era casi la mitad del coste de un INSERT.

        // Un entero ya es su propia forma normalizada
        if (is_int($v)) {
            $texto = (string)$v;
            return 'n' . strlen($texto) . ':' . $texto;
        }
        // Y un texto que no es un número va al camino de texto sin más vueltas.
        // El trim() es el mismo que hace Valor::esNumerico(): ' 5 ' es numérico
        // para el motor, así que no puede tratarse como texto.
        if (is_string($v) && !is_numeric($v) && !is_numeric(trim($v))) {
            return 't' . strlen($v) . ':' . $v;
        }

        // Un booleano se compara por texto ('1' y '0'), así que sin esto true
        // y 1 —que el motor considera el mismo valor— acabarían en claves
        // distintas y buscar 1 no encontraría la fila que guarda true.
        //
        // Se pasa a número y no a texto porque la igualdad del motor no es
        // transitiva: true == 1 y 1 == '1.0', pero true != '1.0'. Con ninguna
        // clave se puede reproducir eso exactamente, así que se elige el lado
        // que devuelve filas de MÁS: el WHERE las descarta después, y una fila
        // de más no se nota en el resultado. Una de menos no se detecta nunca.
        if (is_bool($v)) {
            $v = $v ? 1 : 0;
        }
        if (Valor::esNumerico($v)) {
            $n = Valor::aNumero($v);
            // 5 y 5.0 son el mismo valor para el motor: la misma clave. El tope
            // deja fuera los flotantes que no caben en un entero.
            if (is_float($n) && is_finite($n) && abs($n) < 9.0E+18 && (float)(int)$n === $n) {
                $n = (int)$n;
            }
            if (is_int($n) || is_finite($n)) {
                $texto = is_int($n) ? (string)$n : var_export($n, true);
                return 'n' . strlen($texto) . ':' . $texto;
            }
        }
        $texto = Valor::aTexto($v);
        return 't' . strlen($texto) . ':' . $texto;
    }

    /**
     * Valor numérico de la primera columna de una clave, o null si esa
     * columna es texto. Es lo que permite saber en qué trozos de un índice
     * puede estar una clave sin abrirlos (ver rango()).
     *
     * @return int|float|null
     */
    public static function valorNumerico(string $clave)
    {
        if ($clave === '' || $clave[0] !== 'n') {
            return null;
        }
        $sep = strpos($clave, ':');
        if ($sep === false) {
            return null;
        }
        $texto = substr($clave, $sep + 1, (int)substr($clave, 1, $sep - 1));
        return strpos($texto, '.') === false && strpos($texto, 'E') === false ? (int)$texto : (float)$texto;
    }

    /**
     * El valor de la primera columna de una clave: el número de una clave
     * numérica o el texto tal cual de una de texto.
     *
     * @return int|float|string
     */
    public static function primerValor(string $clave)
    {
        $numero = self::valorNumerico($clave);
        if ($numero !== null) {
            return $numero;
        }
        $sep = (int)strpos($clave, ':');
        return substr($clave, $sep + 1, (int)substr($clave, 1, $sep - 1));
    }

    /** Bits por clave del filtro de un trozo (ver filtro()): ~1 % de falsos positivos con 7 comprobaciones */
    private const FILTRO_BITS = 10;
    private const FILTRO_K    = 7;         // resumenDeTexto() los lleva desplegados
    /**
     * El filtro cubre también las claves numéricas (ver resumenDeTexto()) cuando
     * el rango del trozo abarca al menos este número de veces su tamaño de parte:
     * valores repartidos, que el rango no acota. Lo mira igual quien escribe y
     * quien busca (ver Storage::clavesEnTexto()), con el mismo dato.
     */
    public const FILTRO_NUMEROS_DESDE = 8;

    /** ¿Lleva filtro de números un trozo con ese rango numérico (ver Indexes::rango()) y ese tamaño de parte? */
    public static function filtraNumeros(?array $rango, int $chunk): bool
    {
        return is_array($rango) && count($rango) === 2 && $rango[1] - $rango[0] >= self::FILTRO_NUMEROS_DESDE * $chunk;
    }

    /**
     * Lo que va en la cabecera de un trozo de índice sobre sus claves de texto
     * (2.9), en una sola pasada:
     *  - 'filtro': un filtro de Bloom (en base64) que dice sin leer las claves
     *    si una NO está en el trozo; si dice que puede estar, puede ser un
     *    falso positivo (~1 %) y entonces se leen. Las claves de texto no
     *    tienen rango, y una búsqueda por una leía todos los trozos. Vacío si
     *    el trozo no tiene claves de texto.
     *  - 'min' y 'max', si todas sus claves son de texto: el menor y el mayor
     *    valor de la primera columna, comparados como compara el WHERE dos
     *    textos (strcmp), para los rangos.
     *
     * Con $conNumeros (2.9), el filtro cubre también las claves numéricas, y
     * lo dice 'cubre': "todo" en vez de "texto". Es para un índice
     * cuyos valores van repartidos por toda la tabla (el cliente de cada
     * pedido), donde el rango de cada trozo no descarta ninguno y una búsqueda
     * los leía todos. Con las claves de una clave primaria, que cada trozo
     * acota, no hace falta (ver StorageEscritura::escribirTrozo()). En memoria
     * el filtro lleva esa marca en su primer byte (ver filtro()).
     *
     * @param array<string, mixed> $keys clave => posiciones
     * @return array{filtro: string, cubre?: string, min?: string, max?: string}
     */
    public static function resumenDeTexto(array $keys, bool $conNumeros = false): array
    {
        $texto = [];
        $todas = $keys !== [];
        $min = $max = null;
        foreach ($keys as $clave => $_) {
            $clave = (string)$clave;
            if ($clave === '' || $clave[0] !== 't') {
                $todas = false;
                if ($conNumeros && $clave !== '' && $clave[0] === 'n') {
                    $texto[] = $clave;
                }
                continue;
            }
            $texto[] = $clave;
            if ($todas) {
                // primerValor(), sin la llamada: una vez por clave
                $sep = (int)strpos($clave, ':');
                $v   = substr($clave, $sep + 1, (int)substr($clave, 1, $sep - 1));
                if ($min === null) {
                    $min = $max = $v;
                } elseif (strcmp($v, $min) < 0) {
                    $min = $v;
                } elseif (strcmp($v, $max) > 0) {
                    $max = $v;
                }
            }
        }
        $resumen = ['filtro' => ''];
        if ($texto !== []) {
            // En palabras de 32 bits, empaquetado al final en little-endian: el
            // bit b queda en el byte b >> 3, bit b & 7, que es lo que lee
            // puedeEstar(). Dos crc32 por clave y siete posiciones de ellos
            // (FILTRO_K), sumando el paso módulo m, que da los mismos bits que
            // (h1 + i·h2) % m y cuesta un cuarto menos: se hace por cada trozo
            // que se escribe
            $m = max(64, (int)ceil(count($texto) * self::FILTRO_BITS / 32) * 32);
            $w = array_fill(0, $m >> 5, 0);
            foreach ($texto as $clave) {
                $b = crc32($clave) % $m;
                $s = (crc32(strrev($clave)) | 1) % $m;
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31); $b += $s; if ($b >= $m) { $b -= $m; }
                $w[$b >> 5] |= 1 << ($b & 31);
            }
            $resumen['filtro'] = base64_encode(pack('V*', ...$w));
            $resumen['cubre']  = $conNumeros ? 'todo' : 'texto';
        }
        if ($todas && $min !== null) {
            $resumen['min'] = $min;
            $resumen['max'] = $max;
        }
        return $resumen;
    }

    /**
     * Cada posición de un trozo con su clave (2.9): para quitar las de las
     * filas que se borran sin leerlas, y para pasar las claves de una parte
     * que se compacta a su sitio nuevo (ver StorageEscritura).
     *
     * @param array<string, int|list<int>> $keys
     * @return array<int, string>
     */
    public static function porPosicion(array $keys): array
    {
        $out = [];
        foreach ($keys as $clave => $donde) {
            if (is_array($donde)) {
                foreach ($donde as $p) {
                    $out[$p] = (string)$clave;
                }
            } else {
                $out[$donde] = (string)$clave;
            }
        }
        return $out;
    }

    /**
     * Un número de 64 bits que resume una clave (2.9): con las de un índice
     * grande en un mapa de huellas, que ocupa menos de la mitad que el de sus
     * claves (ver StorageEscritura::posicionesDeClave()). Dos claves distintas
     * pueden tener la misma: quien la usa confirma la clave.
     */
    public static function huella(string $clave): int
    {
        return crc32($clave) << 32 | crc32(strrev($clave));
    }

    /**
     * El filtro de un trozo como se maneja en memoria y en la caché (2.9), a
     * partir de su cabecera (ver resumenDeTexto()): los bits que van en el
     * fichero, con un byte delante que dice si cubre también los números (1,
     * 'cubre' "todo") o solo los textos (0). Vacío si el trozo no tiene
     * filtro; null si lo que hay no es base64.
     *
     * @param array{filtro?: string, cubre?: string} $resumen
     */
    public static function filtro(array $resumen): ?string
    {
        $bits = base64_decode((string)($resumen['filtro'] ?? ''), true);
        if ($bits === false) {
            return null;
        }
        return $bits === '' ? '' : (($resumen['cubre'] ?? '') === 'todo' ? "\x01" : "\x00") . $bits;
    }

    /**
     * ¿Puede estar esta clave en un trozo con este filtro (ver filtro())? Las
     * claves que el filtro no cubre pueden estar siempre.
     */
    public static function puedeEstar(string $filtro, string $clave): bool
    {
        if ($clave === '') {
            return true;
        }
        if ($clave[0] !== 't' && ($clave[0] !== 'n' || $filtro === '' || $filtro[0] !== "\x01")) {
            return true;                                    // el filtro no cubre esa clase de clave
        }
        if ($filtro === '') {
            return false;                                   // el trozo no tiene claves de texto
        }
        $m  = (strlen($filtro) - 1) << 3;                   // tras el byte que dice qué cubre
        $h1 = crc32($clave);
        $h2 = crc32(strrev($clave)) | 1;
        for ($i = 0; $i < self::FILTRO_K; $i++) {
            $b = ($h1 + $i * $h2) % $m;
            if ((ord($filtro[1 + ($b >> 3)]) & (1 << ($b & 7))) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Los trozos cuyo filtro dice que pueden tener una clave de texto (2.9),
     * como puedeEstar() en cada uno, para buscar una en todos los de un índice
     * grande: sus bits se calculan una vez por tamaño de filtro, no en cada
     * trozo.
     *
     * @param array<int, string> $filtros trozo => filtro
     * @return list<int>
     */
    public static function trozosPosibles(array $filtros, string $clave): array
    {
        $h1   = crc32($clave);
        $h2   = crc32(strrev($clave)) | 1;
        $bits = [];                                         // por tamaño de filtro: [byte, máscara] de cada bit
        $out  = [];
        foreach ($filtros as $p => $filtro) {
            if ($filtro === '') {
                continue;                                   // el trozo no tiene claves de texto
            }
            $m = (strlen($filtro) - 1) << 3;                // tras el byte que dice qué cubre (ver puedeEstar())
            if (!isset($bits[$m])) {
                $bits[$m] = [];
                for ($i = 0; $i < self::FILTRO_K; $i++) {
                    $b = ($h1 + $i * $h2) % $m;
                    $bits[$m][] = [1 + ($b >> 3), 1 << ($b & 7)];
                }
            }
            foreach ($bits[$m] as [$byte, $mascara]) {
                if ((ord($filtro[$byte]) & $mascara) === 0) {
                    continue 2;
                }
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Mínimo y máximo del valor numérico de la primera columna en un trozo
     * de índice: [min, max]; [] si el trozo no tiene claves; null si alguna
     * clave es de texto y no se puede acotar. Con una clave primaria
     * autoincremental cada trozo cubre un tramo de ids, y una búsqueda por id
     * abre un trozo en vez de todos.
     *
     * @param array<string, int|list<int>> $keys
     * @return array{0: int|float, 1: int|float}|array{}|null
     */
    public static function rango(array $keys): ?array
    {
        if ($keys === []) {
            return [];
        }
        // El caso de siempre —una clave primaria entera— se resuelve con una
        // sola expresión regular sobre todas las claves juntas: la mitad de
        // tiempo que mirarlas una a una. Si alguna no es un entero solo, por
        // el camino general
        $n = preg_match_all('/^n\d+:(-?\d+)$/m', implode("\n", array_keys($keys)), $m);
        if ($n === count($keys)) {
            return [(int)min($m[1]), (int)max($m[1])];
        }
        $min = $max = null;
        foreach (array_keys($keys) as $clave) {
            $v = self::valorNumerico((string)$clave);
            if ($v === null) {
                return null;
            }
            if ($min === null || $v < $min) { $min = $v; }
            if ($max === null || $v > $max) { $max = $v; }
        }
        return $min === null ? [] : [$min, $max];
    }

    /**
     * Añade al índice ANTERIOR las filas que hay a partir de una posición.
     *
     * Reconstruir el índice entero es la mayor parte de lo que cuesta escribir
     * una fila en una tabla grande, y casi siempre es trabajo repetido: las
     * claves de las filas que no se han movido son las mismas. Solo vale si
     * las posiciones anteriores a $desde siguen siendo las mismas; quien llama
     * tiene que haberlo comprobado con rigor, porque una entrada de MÁS solo
     * hace la consulta más lenta y una de MENOS devuelve resultados incompletos
     * sin que nada lo delate.
     *
     * @param array<string, int|list<int>> $anterior claves del índice de antes
     * @param list<array>                  $filas    las filas desde $desde, en orden
     * @param list<string>                 $columnas columnas del índice
     * @param int                          $desde    posición de la primera de $filas
     * @return array<string, int|list<int>>
     */
    public static function ampliar(array $anterior, array $filas, array $columnas, int $desde, array $plegar = []): array
    {
        $keys = $anterior;
        foreach ($filas as $i => $fila) {
            // Sin colación, la clave aquí mismo: esto va una vez por fila
            if ($plegar === []) {
                $valores = [];
                foreach ($columnas as $c) {
                    $valores[] = $fila[$c] ?? null;
                }
                $clave = self::clave($valores);
            } else {
                $clave = self::claveFila($fila, $columnas, $plegar);
            }
            if ($clave !== null) {
                self::anotar($keys, $clave, $desde + $i);
            }
            Memoria::comprobar('la ampliación del índice');
        }
        return $keys;
    }

    /**
     * Quita del índice todas las posiciones a partir de $desde: las que se
     * han desplazado o desaparecido. Lo que queda son las filas de delante,
     * que no se han movido.
     *
     * @param array<string, int|list<int>> $keys
     * @return array<string, int|list<int>>
     */
    public static function recortar(array $keys, int $desde): array
    {
        foreach ($keys as $clave => $v) {
            if (is_int($v)) {
                if ($v >= $desde) {
                    unset($keys[$clave]);
                }
                continue;
            }
            $quedan = [];
            foreach ($v as $p) {
                if ((int)$p < $desde) {
                    $quedan[] = (int)$p;
                }
            }
            if ($quedan === []) {
                unset($keys[$clave]);
            } elseif (count($quedan) === 1) {
                $keys[$clave] = $quedan[0];
            } else {
                $keys[$clave] = $quedan;
            }
        }
        return $keys;
    }

    /**
     * ¿Cambia la clave de índice de una fila? Si las columnas del índice
     * valen lo mismo, no: ni siquiera hace falta calcularla.
     *
     * @param array|null $nueva null: la fila se ha borrado y queda un hueco (2.9)
     */
    public static function cambiaClave(array $columnas, array $vieja, ?array $nueva, array $plegar = []): bool
    {
        $vv = $nv = [];
        foreach ($columnas as $c) {
            $vv[] = $vieja[$c] ?? null;
            $nv[] = $nueva[$c] ?? null;
        }
        if ($plegar === []) {
            return $vv !== $nv && self::clave($vv) !== self::clave($nv);
        }
        return $vv !== $nv && self::claveFila($vieja, $columnas, $plegar) !== ($nueva === null ? null : self::claveFila($nueva, $columnas, $plegar));
    }

    /**
     * Cambia la clave de una posición sin mover las demás: sale de la de
     * antes y entra en la nueva (null: sin clave, como la de un hueco).
     *
     * Se cambia $keys en su sitio, y la posición sale de la lista de su clave
     * sin hacer otra (2.9): un UPDATE de toda la tabla sobre una columna
     * indexada con pocos valores copiaba dos veces en cada fila la lista de su
     * valor, de cientos de posiciones.
     *
     * @param array<string, int|list<int>> $keys
     */
    public static function sustituir(array &$keys, int $pos, ?string $antes, ?string $ahora): void
    {
        if ($antes !== null && isset($keys[$antes])) {
            if (!is_array($keys[$antes])) {
                if ((int)$keys[$antes] === $pos) {
                    unset($keys[$antes]);
                }
            } elseif (($i = array_search($pos, $keys[$antes])) !== false) {
                array_splice($keys[$antes], (int)$i, 1);
                if (count($keys[$antes]) <= 1) {
                    if ($keys[$antes] === []) {
                        unset($keys[$antes]);
                    } else {
                        $keys[$antes] = (int)$keys[$antes][0];
                    }
                }
            }
        }
        if ($ahora !== null) {
            self::anotar($keys, $ahora, $pos);
        }
    }

    /**
     * Construye el contenido de un índice a partir de las filas de la tabla.
     *
     * @param list<array>    $filas
     * @param list<string>   $columnas
     * @param array<int, ?string> $plegar la colación de cada columna, por posición (2.9; ver claveFila())
     * @return array<string, list<int>>
     */
    public static function construir(array $filas, array $columnas, array $plegar = []): array
    {
        $keys = [];
        foreach ($filas as $pos => $fila) {
            // Sin colación, la clave aquí mismo: esto va una vez por fila
            if ($plegar === []) {
                $valores = [];
                foreach ($columnas as $c) {
                    $valores[] = $fila[$c] ?? null;
                }
                $clave = self::clave($valores);
            } else {
                $clave = self::claveFila($fila, $columnas, $plegar);
            }
            if ($clave === null) {
                continue;                       // los NULL no se indexan
            }
            self::anotar($keys, $clave, $pos);
            Memoria::comprobar('la construcción del índice');
        }
        return $keys;
    }

    /**
     * Apunta una posición bajo una clave. Con una sola posición se guarda el
     * entero a secas, no una lista de uno: en una clave primaria son todas, y
     * en memoria una lista de un elemento cuesta tres veces lo que un entero.
     *
     * @param array<string, int|list<int>> $keys
     */
    public static function anotar(array &$keys, string $clave, int $pos): void
    {
        if (!isset($keys[$clave])) {
            $keys[$clave] = $pos;
        } elseif (is_int($keys[$clave])) {
            $keys[$clave] = [$keys[$clave], $pos];
        } else {
            $keys[$clave][] = $pos;
        }
    }

    /**
     * Posiciones apuntadas bajo una clave, sea un entero o una lista. Los
     * índices de antes de la 2.5 guardaban siempre listas.
     *
     * @param int|list<int>|mixed $v
     * @return list<int>
     */
    public static function posiciones($v): array
    {
        if (is_int($v)) {
            return [$v];
        }
        return is_array($v) ? array_map('intval', $v) : [];
    }

    // ------------------------------------------------------------------
    // Elección de índice para una consulta
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Predicados aprovechables de un WHERE
    // ------------------------------------------------------------------

}
