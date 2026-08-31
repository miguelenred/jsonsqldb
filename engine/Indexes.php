<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Índices de búsqueda.
 *
 * Un índice es un fichero aparte, `<tabla>.idx.<nombre>.json`, que asocia el
 * valor de una o varias columnas con las posiciones de las filas que lo tienen:
 *
 *   {"index":"...","columns":["email"],"rev":7,"rows":20000,"chunk":5000,
 *    "keys":{"t11:ana@ej.com":[143], ...}}
 *
 * Para qué sirve. El coste de un SELECT no está en evaluar el WHERE, está en
 * leer y decodificar los ficheros de la tabla entera. El índice dice en qué
 * posiciones están las filas buscadas, y de ahí se deduce en qué partes
 * (`<tabla>.partN.json`) viven: se decodifican solo esas. En una tabla de veinte
 * partes, buscar por una columna indexada lee una en vez de veinte.
 *
 * Para qué NO sirve. Solo acelera igualdades e IN. Los rangos, LIKE, ORDER BY y
 * los agregados siguen leyendo la tabla completa. Y no acelera ninguna
 * escritura: una escritura reescribe la tabla entera de todos modos, y encima
 * ahora tiene que reescribir el índice.
 *
 * Por qué se reconstruye entero en cada escritura. Las posiciones no son
 * estables: al guardar, las filas se reindexan desde cero y se reparten en
 * partes, así que un solo DELETE desplaza todas las filas siguientes. Mantener
 * las posiciones al día de forma incremental sería una fuente inagotable de
 * errores sutiles; reconstruir el índice desde el array de filas que ya está en
 * memoria cuesta un recorrido, que al lado del json_encode de la tabla es poco.
 *
 * Claves. La igualdad del motor no es la de PHP: 5, '5' y '5.0' son el mismo
 * valor (ver Valor::comparar). La clave lo respeta —los valores numéricos se
 * normalizan a número y el resto a texto— porque si no, buscar 5 no encontraría
 * las filas que guardan '5'. Cada trozo lleva su longitud por delante para que
 * la concatenación de varias columnas no sea ambigua y para poder buscar por
 * prefijo: un índice sobre (a, b) sirve para buscar solo por a.
 *
 * Los NULL no se indexan: ninguna igualdad los encuentra nunca.
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
            $out[] = ['name' => (string)$idx['name'], 'columns' => $cols, 'auto' => false];
        }

        foreach (Catalog::conjuntosUnicos($meta) as $uq) {
            $cols  = array_values(array_map('strval', $uq['columns']));
            $clave = strtolower(implode(',', $cols));
            if ($cols === [] || isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $out[] = ['name' => self::nombreAuto($cols), 'columns' => $cols, 'auto' => true];
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
        return strlen($nombre) <= 64
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
     * Añade al índice ANTERIOR solo las filas nuevas del final.
     *
     * Reconstruir el índice entero es el 67 % de lo que cuesta insertar una fila
     * en una tabla grande: se recorren todas las filas y se calcula la clave de
     * cada una, cuando las de antes no se han movido ni han cambiado.
     *
     * Solo vale si las posiciones anteriores siguen siendo las mismas. Quien
     * llama tiene que haberlo comprobado; aquí se da por cierto. Y la
     * comprobación tiene que ser estricta, porque los dos errores no cuestan lo
     * mismo: una entrada de MÁS solo hace la consulta más lenta —el WHERE se
     * vuelve a aplicar sobre las filas leídas— pero una de MENOS devuelve
     * resultados incompletos sin que nada lo delate.
     *
     * @param array<string, list<int>> $anterior claves del índice de antes
     * @param list<array>              $filas    la tabla entera, ya con las nuevas
     * @param list<string>             $columnas columnas del índice
     * @param int                      $desde    primera posición nueva
     * @return array<string, list<int>>
     */
    public static function ampliar(array $anterior, array $filas, array $columnas, int $desde): array
    {
        $keys = $anterior;
        $n    = count($filas);
        for ($pos = $desde; $pos < $n; $pos++) {
            $valores = [];
            foreach ($columnas as $c) {
                $valores[] = $filas[$pos][$c] ?? null;
            }
            $clave = self::clave($valores);
            if ($clave === null) {
                continue;                       // los NULL no se indexan
            }
            $keys[$clave][] = $pos;
            Memoria::comprobar('la ampliación del índice');
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
            $keys[$clave][] = $pos;
            Memoria::comprobar('la construcción del índice');
        }
        return $keys;
    }

    // ------------------------------------------------------------------
    // Elección de índice para una consulta
    // ------------------------------------------------------------------

    /**
     * Elige el índice más aprovechable para unos predicados de igualdad.
     *
     * $predicados es  columna en minúsculas => lista de valores  (un `=` da un
     * valor, un `IN` da varios). Sirve el índice que cubra más columnas por la
     * izquierda: uno sobre (a, b) vale para buscar por a, y mejor todavía por
     * a y b. Se descarta el que no cubra ni la primera.
     *
     * @param list<array{name: string, columns: list<string>, auto: bool}> $defs
     * @param array<string, list<mixed>> $predicados
     * @return array{def: array, claves: list<string>, prefijo: bool}|null
     */
    public static function elegir(array $defs, array $predicados): ?array
    {
        $mejor = null;
        $mejorN = 0;

        foreach ($defs as $def) {
            $n = 0;
            foreach ($def['columns'] as $col) {
                if (!isset($predicados[strtolower($col)])) {
                    break;
                }
                $n++;
            }
            if ($n > $mejorN) {
                $mejor  = $def;
                $mejorN = $n;
            }
        }
        if ($mejor === null) {
            return null;
        }

        // Producto de los valores de cada columna cubierta: un IN aporta varios
        $claves = [''];
        for ($i = 0; $i < $mejorN; $i++) {
            $valores = $predicados[strtolower($mejor['columns'][$i])];
            if (count($claves) * count($valores) > self::MAX_CLAVES) {
                return null;                    // demasiadas: sale más barato recorrer
            }
            $nuevas = [];
            foreach ($claves as $base) {
                foreach ($valores as $v) {
                    if ($v === null) {
                        continue;               // NULL nunca es igual a nada
                    }
                    $nuevas[] = $base . self::trozo($v);
                }
            }
            $claves = $nuevas;
        }

        $claves = array_values(array_unique($claves));
        if ($claves === []) {
            return null;
        }

        return ['def' => $mejor, 'claves' => $claves,
                'prefijo' => $mejorN < count($mejor['columns'])];
    }

    // ------------------------------------------------------------------
    // Predicados aprovechables de un WHERE
    // ------------------------------------------------------------------

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

    /** @return list<array> conjunciones de primer nivel */
    private static function conjunciones(array $n): array
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
