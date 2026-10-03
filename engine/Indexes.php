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
 * https://miguelenred.es/jsonsqldb
 */
final class Indexes
{
    /** Prefijo reservado para los índices automáticos de PK y UNIQUE. */
    public const PREFIJO_AUTO = 'auto_';

    private const RE_NOMBRE = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /**
     * Tope de combinaciones de clave que se buscan de una vez. Un IN de tres
     * columnas con diez valores cada una son mil búsquedas: a partir de ahí sale
     * más barato recorrer la tabla.
     */
    private const MAX_CLAVES = 512;
    /**
     * Con claves numéricas el tope es mayor: cada una se busca solo en el trozo
     * cuyo rango la contiene, así que 4.000 claves cuestan unas 4.000 búsquedas
     * pequeñas. Con texto cada clave se busca en todos los trozos
     */
    private const MAX_CLAVES_NUMERICAS = 4096;

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
     * @return list<array{name: string, columns: list<string>, auto: bool}>
     */
    public static function definiciones(array $meta): array
    {
        $out    = [];
        $vistas = [];
        // Los conjuntos únicos (clave primaria y UNIQUE): un índice sobre uno de
        // ellos devuelve una fila o ninguna por clave
        $unicos = [];
        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $unicos[strtolower(implode(',', array_map('strval', $uq['columns'])))] = true;
        }

        foreach ($meta['indexes'] ?? [] as $idx) {
            $cols = array_values(array_map('strval', (array)($idx['columns'] ?? [])));
            if ($cols === []) {
                continue;
            }
            $clave = strtolower(implode(',', $cols));
            if (isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $out[] = ['name' => (string)$idx['name'], 'columns' => $cols, 'auto' => false, 'unico' => isset($unicos[$clave])];
        }

        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $cols  = array_values(array_map('strval', $uq['columns']));
            $clave = strtolower(implode(',', $cols));
            if ($cols === [] || isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $out[] = ['name' => self::nombreAuto($cols), 'columns' => $cols, 'auto' => true, 'unico' => true];
        }

        return $out;
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
     * Un valor convertido en trozo de clave: tipo, longitud y contenido.
     *
     * @param mixed $v
     */
    private static function trozo($v): string
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
     * ¿Puede estar alguna clave con estos valores numéricos en un trozo con
     * este rango? Un valor null es una clave de texto, que puede estar en
     * cualquiera. Con rango desconocido (null) sí; con un trozo vacío ([]) no.
     *
     * @param list<int|float|null> $valores los de valorNumerico() de cada clave
     */
    public static function cabeEnRango(array $valores, ?array $rango): bool
    {
        if ($rango === null) {
            return true;
        }
        if ($rango === []) {
            return false;
        }
        foreach ($valores as $v) {
            if ($v === null || ($v >= $rango[0] && $v <= $rango[1])) {
                return true;
            }
        }
        return false;
    }

    /**
     * ¿La clave de este valor es de fiar para decidir una igualdad?
     *
     * Casi siempre sí. La excepción son los números tan grandes que ya no caben
     * exactos en un entero: ahí un mismo valor puede escribirse de dos formas
     * que el motor considera iguales y que dan claves distintas. Son valores por
     * encima de 9·10^18, que en la práctica no aparecen, pero cuando el conjunto
     * se usa para responder directamente —como en un IN— no hay un WHERE detrás
     * que corrija el fallo, así que esos se comparan uno a uno.
     *
     * @param mixed $v
     */
    public static function claveFiable($v): bool
    {
        if (is_bool($v) || $v === null || !Valor::esNumerico($v)) {
            return true;
        }
        $n = Valor::aNumero($v);
        return is_int($n) ? abs($n) < 9.0E+18 : (is_finite($n) && abs($n) < 9.0E+18);
    }

    /**
     * Agrupa unos valores por su clave, para poder resolver un IN sin recorrer
     * la lista entera en cada fila.
     *
     * Devuelve [conjunto, dudosos, hayNulo]. En el conjunto, cada clave lleva
     * los valores originales que le corresponden: la clave acota los candidatos
     * pero la igualdad la sigue decidiendo Valor::comparar(), porque dos valores
     * distintos pueden compartir clave.
     *
     * @param list<mixed> $valores
     * @return array{0: array<string, list<mixed>>, 1: list<mixed>, 2: bool}
     */
    public static function conjunto(array $valores): array
    {
        $conjunto = [];
        $dudosos  = [];
        $hayNulo  = false;

        foreach ($valores as $x) {
            if ($x === null) { $hayNulo = true; continue; }
            if (!self::claveFiable($x)) { $dudosos[] = $x; continue; }
            $conjunto[(string)self::clave([$x])][] = $x;
        }
        return [$conjunto, $dudosos, $hayNulo];
    }

    /**
     * Construye el contenido de un índice a partir de las filas de la tabla.
     *
     * @param list<array>    $filas
     * @param list<string>   $columnas
     * @return array<string, list<int>>
     */
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
    public static function ampliar(array $anterior, array $filas, array $columnas, int $desde): array
    {
        $keys = $anterior;
        foreach ($filas as $i => $fila) {
            $valores = [];
            foreach ($columnas as $c) {
                $valores[] = $fila[$c] ?? null;
            }
            $clave = self::clave($valores);
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
     * Cambia la fila de una posición sin mover las demás: se quita la clave
     * vieja y se anota la nueva. $cambio se pone a true si el índice cambió.
     *
     * @param array<string, int|list<int>> $keys
     * @param list<string>                 $columnas
     * @return array<string, int|list<int>>
     */
    /**
     * ¿Cambia la clave de índice de una fila? Si las columnas del índice
     * valen lo mismo, no: ni siquiera hace falta calcularla.
     */
    public static function cambiaClave(array $columnas, array $vieja, array $nueva): bool
    {
        $vv = $nv = [];
        foreach ($columnas as $c) {
            $vv[] = $vieja[$c] ?? null;
            $nv[] = $nueva[$c] ?? null;
        }
        return $vv !== $nv && self::clave($vv) !== self::clave($nv);
    }

    public static function sustituir(array $keys, array $columnas, int $pos, array $vieja, array $nueva, bool &$cambio): array
    {
        $vv = $nv = [];
        foreach ($columnas as $c) {
            $vv[] = $vieja[$c] ?? null;
            $nv[] = $nueva[$c] ?? null;
        }
        $cv = self::clave($vv);
        $cn = self::clave($nv);
        if ($cv === $cn) {
            return $keys;
        }
        $cambio = true;
        if ($cv !== null && isset($keys[$cv])) {
            $lista = array_values(array_diff(self::posiciones($keys[$cv]), [$pos]));
            if ($lista === []) {
                unset($keys[$cv]);
            } else {
                $keys[$cv] = count($lista) === 1 ? $lista[0] : $lista;
            }
        }
        if ($cn !== null) {
            self::anotar($keys, $cn, $pos);
        }
        return $keys;
    }

    public static function construir(array $filas, array $columnas): array
    {
        $keys = [];
        foreach ($filas as $pos => $fila) {
            $valores = [];
            foreach ($columnas as $c) {
                $valores[] = $fila[$c] ?? null;
            }
            $clave = self::clave($valores);
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

    /**
     * El mejor índice para unos predicados de igualdad: el primero de candidatos().
     *
     * @return array{def: array, claves: list<string>, prefijo: bool}|null
     */
    public static function elegir(array $defs, array $predicados): ?array
    {
        return self::candidatos($defs, $predicados)[0] ?? null;
    }

    /**
     * Los índices que sirven para unos predicados de igualdad, del mejor al
     * peor: primero uno único cubierto entero (una fila o ninguna por clave),
     * después el que cubre más columnas por la izquierda, y a igualdad, el
     * orden de definición. Antes, a igualdad de columnas ganaba el primero
     * definido, y los creados a mano van antes que los de la clave primaria:
     * con un índice sobre «ciudad», «WHERE id = ? AND ciudad = ?» leía todas
     * las filas de esa ciudad en vez de una. Quien busca prueba el siguiente
     * si uno no compensa.
     *
     * @return list<array{def: array, claves: list<string>, prefijo: bool}>
     */
    public static function candidatos(array $defs, array $predicados): array
    {
        $lista = [];
        foreach (array_values($defs) as $orden => $def) {
            $n = 0;
            foreach ($def['columns'] as $col) {
                if (!isset($predicados[strtolower($col)])) {
                    break;
                }
                $n++;
            }
            if ($n === 0) {
                continue;
            }
            // Producto de los valores de cada columna cubierta: un IN aporta varios
            $claves = [''];
            $tope = self::MAX_CLAVES;
            if ($n === 1) {
                $numericas = true;
                foreach ($predicados[strtolower($def['columns'][0])] as $v) {
                    $numericas = $numericas && (is_int($v) || is_float($v));
                }
                $tope = $numericas ? self::MAX_CLAVES_NUMERICAS : self::MAX_CLAVES;
            }
            for ($i = 0; $i < $n && $claves !== null; $i++) {
                $valores = $predicados[strtolower($def['columns'][$i])];
                if (count($claves) * count($valores) > $tope) {
                    $claves = null;                 // demasiadas: con este índice sale más barato recorrer
                    break;
                }
                $nuevas = [];
                foreach ($claves as $base) {
                    foreach ($valores as $v) {
                        if ($v !== null) {          // NULL nunca es igual a nada
                            $nuevas[] = $base . self::trozo($v);
                        }
                    }
                }
                $claves = $nuevas;
            }
            if ($claves === null) {
                continue;
            }
            $claves = array_values(array_unique($claves));
            if ($claves === []) {
                return [];                          // ninguna fila puede cumplirlo
            }
            $lista[] = ['def' => $def, 'claves' => $claves, 'prefijo' => $n < count($def['columns']),
                        'unico' => !empty($def['unico']) && $n === count($def['columns']), 'n' => $n, 'orden' => $orden];
        }
        usort($lista, static fn($a, $b) => [$b['unico'], $b['n'], $a['orden']] <=> [$a['unico'], $a['n'], $b['orden']]);
        return array_map(static fn($c) => ['def' => $c['def'], 'claves' => $c['claves'], 'prefijo' => $c['prefijo']], $lista);
    }

    // ------------------------------------------------------------------
    // Predicados aprovechables de un WHERE
    // ------------------------------------------------------------------

    /**
     * De las igualdades del WHERE (col = 'texto' o col IN ('a', 'b')), los
     * textos que una parte tiene que contener, entre comillas como van en el
     * fichero, para que alguna de sus filas pueda cumplirlas (ver
     * Storage::parte()). Sirven a la lectura y a UPDATE y DELETE. Solo textos que no son números (un número se compara
     * por su valor, '5' = 5.0) y de caracteres que ningún JSON escribe de otra
     * manera: letras, cifras, espacios y signos corrientes, sin comillas,
     * barras ni < > & '. Con cualquier otro valor, esa columna no aporta nada.
     *
     * @param array<string, list<mixed>> $predicados columna => valores
     * @return list<list<string>>
     */
    public static function agujas(array $predicados): array
    {
        $out = [];
        foreach ($predicados as $valores) {
            $lista = [];
            foreach ($valores as $v) {
                if (!is_string($v) || $v === '' || is_numeric(trim($v)) || !preg_match('#^[\x20-\x7E]+$#', $v)
                    || strpbrk($v, "\"\\/<>&'") !== false) {
                    continue 2;
                }
                $lista[] = '"' . $v . '"';
            }
            if ($lista !== []) {
                $out[] = $lista;
            }
        }
        return $out;
    }

    /**
     * Saca del WHERE los predicados de igualdad que un índice puede resolver.
     *
     * Solo se miran las conjunciones de primer nivel (`a = 1 AND b IN (2,3)`) y
     * solo `=` e `IN` contra literales. Queda fuera a propósito:
     *
     *   - el OR de primer nivel, que no permite descartar ninguna fila;
     *   - todo lo que cuelgue de un NOT, donde la condición está negada;
     *   - `IS NULL`, porque los NULL no están en el índice y filtrar por él
     *     dejaría fuera las filas que un LEFT JOIN rellena con NULL;
     *   - `NOT IN` y los rangos, que no son igualdades.
     *
     * Filtrar la tabla antes del JOIN es seguro porque el WHERE se aplica
     * después de cruzar: una fila rellenada con NULL nunca supera `col = valor`,
     * así que el resultado final es el mismo tanto si se descartó antes como si
     * se descartó después.
     *
     * El resultado es  tabla o alias en minúsculas => columna => valores. Las
     * columnas sin cualificar solo se aceptan cuando el FROM tiene un único
     * origen: con varios no se sabe de cuál son sin resolver el mapa de
     * columnas, y eso exige haber leído ya las tablas.
     *
     * @return array<string, array<string, list<mixed>>>
     */
    public static function predicados(?array $where, ?string $aliasUnico): array
    {
        if ($where === null) {
            return [];
        }
        $out = [];
        foreach (self::conjunciones($where) as $n) {
            [$col, $valores] = self::igualdad($n);
            if ($col === null) {
                continue;
            }
            $alias = $col['tabla'] === null ? $aliasUnico : strtolower((string)$col['tabla']);
            if ($alias === null) {
                continue;
            }
            $nombre = strtolower((string)$col['nombre']);
            // Dos condiciones sobre la misma columna: se queda la primera, que
            // basta para acotar. La otra la aplica el WHERE como siempre.
            $out[$alias][$nombre] ??= $valores;
        }
        return $out;
    }

    /**
     * Rangos numéricos del WHERE por alias y columna: `col > 5`, `col <= 9`,
     * `col BETWEEN 1 AND 3` y sus combinaciones en la cadena de AND de
     * primer nivel, con literales numéricos. Cada rango es [min, max], y un
     * extremo null es abierto. Sirven para saltar las partes de la tabla
     * cuyo trozo de índice no puede contener ningún valor del rango (ver
     * Storage::filasPorRango()); el WHERE se aplica igual a lo que se lee.
     *
     * @return array<string, array<string, array{0: int|float|null, 1: int|float|null}>>
     */
    public static function rangos(?array $where, ?string $aliasUnico): array
    {
        if ($where === null) {
            return [];
        }
        $out = [];
        foreach (self::conjunciones($where) as $n) {
            foreach (self::acotaciones($n) as [$col, $min, $max]) {
                $alias = $col['tabla'] === null ? $aliasUnico : strtolower((string)$col['tabla']);
                if ($alias === null) {
                    continue;
                }
                $nombre = strtolower((string)$col['nombre']);
                $r = $out[$alias][$nombre] ?? [null, null];
                if ($min !== null && ($r[0] === null || $min > $r[0])) { $r[0] = $min; }
                if ($max !== null && ($r[1] === null || $max < $r[1])) { $r[1] = $max; }
                $out[$alias][$nombre] = $r;
            }
        }
        return $out;
    }

    /**
     * Las acotaciones numéricas de un nodo: [columna, min, max].
     *
     * @return list<array{0: array, 1: int|float|null, 2: int|float|null}>
     */
    private static function acotaciones(array $n): array
    {
        $k = $n['k'] ?? '';
        if ($k === 'between' && empty($n['not']) && ($n['e']['k'] ?? '') === 'col') {
            $a = self::literalNumerico($n['min']);
            $b = self::literalNumerico($n['max']);
            return $a === null || $b === null ? [] : [[$n['e'], $a, $b]];
        }
        if ($k !== 'bin' || !in_array($n['op'] ?? '', ['<', '<=', '>', '>='], true)) {
            return [];
        }
        $op = $n['op'];
        [$col, $lit] = [$n['i'], $n['d']];
        if (($col['k'] ?? '') !== 'col') {
            [$col, $lit] = [$n['d'], $n['i']];
            $op = strtr($op, ['<' => '>', '>' => '<']);      // 5 < col  es  col > 5
        }
        if (($col['k'] ?? '') !== 'col') {
            return [];
        }
        $v = self::literalNumerico($lit);
        if ($v === null) {
            return [];
        }
        // Los extremos abiertos (< y >) se tratan como cerrados: acotar de más
        // solo lee una parte de más, y el WHERE deja fuera el igual
        return $op === '<' || $op === '<=' ? [[$col, null, $v]] : [[$col, $v, null]];
    }

    /** @return int|float|null */
    private static function literalNumerico(array $n)
    {
        if (($n['k'] ?? '') !== 'lit' || $n['v'] === null || is_bool($n['v'])) {
            return null;
        }
        return is_int($n['v']) || is_float($n['v']) ? $n['v'] : (Valor::esNumerico($n['v']) ? Valor::aNumero($n['v']) : null);
    }

    /** @return list<array> conjunciones de primer nivel */
    public static function conjunciones(array $n): array
    {
        if (($n['k'] ?? '') === 'bin' && ($n['op'] ?? '') === 'AND') {
            return array_merge(self::conjunciones($n['i']), self::conjunciones($n['d']));
        }
        return [$n];
    }

    /**
     * Si el nodo es `col = literal` o `col IN (literales)`, devuelve la columna
     * y los valores; si no, [null, []].
     *
     * @return array{0: array|null, 1: list<mixed>}
     */
    private static function igualdad(array $n): array
    {
        $k = $n['k'] ?? '';

        if ($k === 'bin' && ($n['op'] ?? '') === '=') {
            [$col, $lit] = [$n['i'], $n['d']];
            if (($col['k'] ?? '') !== 'col' || ($lit['k'] ?? '') !== 'lit') {
                [$col, $lit] = [$n['d'], $n['i']];
            }
            if (($col['k'] ?? '') === 'col' && ($lit['k'] ?? '') === 'lit' && $lit['v'] !== null) {
                return [$col, [$lit['v']]];
            }
            return [null, []];
        }

        if ($k === 'in' && empty($n['not']) && ($n['select'] ?? null) === null
            && is_array($n['lista'] ?? null) && $n['lista'] !== []
            && ($n['e']['k'] ?? '') === 'col') {
            $valores = [];
            foreach ($n['lista'] as $e) {
                if (($e['k'] ?? '') !== 'lit' || $e['v'] === null) {
                    return [null, []];          // un solo elemento no literal lo invalida
                }
                $valores[] = $e['v'];
            }
            return [$n['e'], $valores];
        }

        return [null, []];
    }
}
