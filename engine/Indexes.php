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

    public const RE_NOMBRE = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

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
     * usarlo (ver sirve()); 'afin', la afinidad de cada columna, y 'texto',
     * si alguna es de texto o decimal (2.10): sus claves cambian en la 2.10.
     *
     * @return list<array{name: string, columns: list<string>, auto: bool, minimo: int, afin: list<string>, texto: bool}>
     */
    public static function definiciones(array $meta): array
    {
        $out    = [];
        $vistas = [];
        $noNulas = [];
        // Y la afinidad de cada columna (2.10), con la que se busca en él (ver
        // IndexesBusqueda::candidatos()); con alguna de texto o decimal, sus
        // claves de antes de la 2.10 no sirven (ver Storage::clavesAlDia()):
        // un texto que es un número iba a la clave del número, y un decimal
        // entero de 9·10^18 a 2^63, a una de decimal (ver trozo())
        $afinidades = $cambian = [];
        foreach ($meta['columns'] as $c) {
            $minuscula = strtolower((string)$c['name']);
            if (!empty($c['notnull'])) {
                $noNulas[$minuscula] = true;
            }
            $afinidades[$minuscula] = $c['type'] === Types::TEXT ? 'T' : 'N';
            $cambian[$minuscula]    = $c['type'] === Types::TEXT || $c['type'] === Types::DOUBLE || $c['type'] === Types::DECIMAL;
        }
        $cambia = static function (array $cols) use ($cambian): bool {
            foreach ($cols as $c) {
                if ($cambian[strtolower($c)] ?? true) {
                    return true;
                }
            }
            return false;
        };
        // Una fila con NULL en alguna columna del índice no está en él: buscar
        // solo por las primeras perdería las que lo tienen en las de detrás
        // (2.10: WHERE a = 1 con un índice sobre (a, b) no veía las filas con
        // b NULL, en UPDATE y DELETE desde la 2.5.0 y en SELECT y COUNT desde
        // la 2.7.4). Vale desde donde todas las
        // que quedan detrás son NOT NULL
        $minimo = static function (array $cols) use ($noNulas): int {
            $m = \count($cols);
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
            $afin = [];
            foreach ($cols as $c) {
                $afin[] = $afinidades[strtolower($c)] ?? 'T';
            }
            $out[] = ['name' => (string)$idx['name'], 'columns' => $cols, 'auto' => false, 'unico' => isset($unicos[$clave]),
                      'plegar' => Catalog::plegarCon($colaciones, $cols), 'minimo' => $minimo($cols),
                      'afin' => $afin, 'texto' => $cambia($cols)];
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
            $afin = [];
            foreach ($cols as $c) {
                $afin[] = $afinidades[strtolower($c)] ?? 'T';
            }
            $out[] = ['name' => $nombre, 'columns' => $cols, 'auto' => true, 'unico' => true,
                      'plegar' => $uq['plegar'], 'minimo' => $minimo($cols), 'afin' => $afin, 'texto' => $cambia($cols)];
        }

        return $out;
    }

    /** ¿Sirve el índice para buscar por sus $n primeras columnas (ver definiciones())? */
    public static function sirve(array $def, int $n): bool
    {
        return $n >= ($def['minimo'] ?? \count($def['columns']));
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
    public static function nombreAuto(array $columnas): string
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
    public static function claveFila(?array $fila, array $columnas, array $plegar = [], array $afinidad = []): ?string
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
            // Con la afinidad de otras columnas (2.10): la de las del padre
            // en una clave foránea, que es con la que se comparan (ver Valor::conAfinidad())
            if (isset($afinidad[$i])) {
                $v = Valor::conAfinidad($v, $afinidad[$i]);
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
        // Un entero ya es su propia forma normalizada; y un texto va como
        // texto, aunque sea un número (2.10: '7' y '007' son distintos, y
        // los dos iban a la clave del 7). Construir un índice llama a esto una
        // vez por fila y por columna: son los dos casos de casi siempre
        if (\is_int($v)) {
            $texto = (string)$v;
            return 'n' . \strlen($texto) . ':' . $texto;
        }
        if (\is_string($v)) {
            return 't' . \strlen($v) . ':' . $v;
        }
        // Un booleano (de un JSON editado a mano) es 1 o 0, como para comparar
        if (\is_bool($v)) {
            return $v ? 'n1:1' : 'n1:0';
        }
        // 5 y 5.0 son el mismo valor para el motor: la misma clave, en todo
        // el rango de los enteros, de -2^63 hasta 2^63 sin él (2.10: hasta
        // 9·10^18, y 9.1e18 en una columna REAL no casaba con el entero
        // 9100000000000000000 de una clave foránea INTEGER)
        $n = (float)$v;
        if ($n >= -9.2233720368547758E18 && $n < 9.2233720368547758E18 && (float)(int)$n === $n) {
            $texto = (string)(int)$n;
            return 'n' . \strlen($texto) . ':' . $texto;
        }
        if (is_finite($n)) {
            $texto = var_export($n, true);
            return 'n' . \strlen($texto) . ':' . $texto;
        }
        $texto = Valor::aTexto($v);
        return 't' . \strlen($texto) . ':' . $texto;
    }

    /**
     * Valor numérico de la primera columna de una clave, o null si esa
     * columna es texto. Es lo que permite saber en qué trozos de un índice
     * puede estar una clave sin abrirlos (ver IndexesEscritura::rango()).
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
    public const FILTRO_BITS = 10;
    public const FILTRO_K    = 7;         // IndexesEscritura::resumenDeTexto() los lleva desplegados
    /**
     * El filtro cubre también las claves numéricas (ver IndexesEscritura::resumenDeTexto()) cuando
     * el rango del trozo abarca al menos este número de veces su tamaño de parte:
     * valores repartidos, que el rango no acota. Lo mira igual quien escribe y
     * quien busca (ver Storage::clavesEnTexto()), con el mismo dato.
     */
    public const FILTRO_NUMEROS_DESDE = 8;

    /** ¿Lleva filtro de números un trozo con ese rango numérico (ver IndexesEscritura::rango()) y ese tamaño de parte? */
    public static function filtraNumeros(?array $rango, int $chunk): bool
    {
        return \is_array($rango) && \count($rango) === 2 && $rango[1] - $rango[0] >= self::FILTRO_NUMEROS_DESDE * $chunk;
    }

    /**
     * El filtro de un trozo como se maneja en memoria y en la caché (2.9), a
     * partir de su cabecera (ver IndexesEscritura::resumenDeTexto()): los bits que van en el
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
        $m  = (\strlen($filtro) - 1) << 3;                   // tras el byte que dice qué cubre
        $h1 = crc32($clave);
        $h2 = crc32(strrev($clave)) | 1;
        for ($i = 0; $i < self::FILTRO_K; $i++) {
            $b = ($h1 + $i * $h2) % $m;
            if ((\ord($filtro[1 + ($b >> 3)]) & (1 << ($b & 7))) === 0) {
                return false;
            }
        }
        return true;
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
        if (\is_int($v)) {
            return [$v];
        }
        return \is_array($v) ? array_map('intval', $v) : [];
    }

    // ------------------------------------------------------------------
    // Elección de índice para una consulta
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Predicados aprovechables de un WHERE
    // ------------------------------------------------------------------

}
