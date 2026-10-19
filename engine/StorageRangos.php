<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las lecturas por rango de un índice: comparaciones (<, <=, >, >=,
 * BETWEEN) sobre su primera columna, con números, textos y fechas, y el menor
 * y el mayor de una columna. Las partes que puede tocar un rango numérico, lo
 * más corriente, las lee Storage::filasPorRango().
 * Métodos de Storage que reciben el almacenamiento en $st. Van aparte para
 * que una búsqueda por clave o una escritura no tengan que compilarlos (2.9):
 * sin OPcache, PHP compila en cada petición lo que carga. Hereda de Storage
 * solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class StorageRangos extends Storage
{
    private function __construct()
    {
    }

    // ------------------------------------------------------------------
    // Índices
    // ------------------------------------------------------------------

    /**
     * La cabecera de un trozo de índice —todo menos sus claves: filtro y, si
     * sus claves son todas de texto, el menor y el mayor valor (2.9)—, leyendo
     * solo hasta donde empiezan las claves. Null si no es el trozo de esta
     * revisión.
     *
     * @return array<string, mixed>|null
     */
    private static function cabeceraTrozo(Storage $st, string $tabla, array $def, int $parte): ?array
    {
        $nombre = $def['name'];
        if (isset($st->indicesMemo[$tabla][$nombre]['cabeza'][$parte])) {
            return $st->indicesMemo[$tabla][$nombre]['cabeza'][$parte];
        }
        $rev = (int)($st->estado($tabla)['indexes'][$nombre][$parte - 1] ?? -1);
        $f   = $st->ficheroIndice($tabla, $nombre, $parte);
        $fh  = @fopen($f, 'rb');
        if ($fh === false) {
            return null;
        }
        $texto = '';
        while (($fin = strpos($texto, ',"keys":{')) === false && !feof($fh)) {
            $texto .= (string)fread($fh, 8192);
        }
        fclose($fh);
        $cabeza = $fin === false ? null : json_decode(substr($texto, 0, $fin) . '}', true);
        if (!\is_array($cabeza)) {
            // Sin cabecera legible: estropeado a mano, se rehace al acabar (2.9)
            $st->apuntarReparacion($st->ficheroIndice($tabla, $nombre, $parte));
            return null;
        }
        if (($cabeza['rev'] ?? null) !== $rev || ($cabeza['part'] ?? null) !== $parte
            || ($cabeza['columns'] ?? null) !== $def['columns']) {
            return null;
        }
        return $st->indicesMemo[$tabla][$nombre]['cabeza'][$parte] = $cabeza;
    }

    /**
     * Posiciones de las filas cuya primera columna en un índice cumple todas
     * unas comparaciones [operador, valor] (<, <=, >, >=, =), con valores de un
     * mismo tipo: todos números o todos textos que no son números. Se leen
     * los trozos del índice, no la tabla, que son unas cinco veces más
     * pequeños; con números se saltan los trozos cuyo rango no los alcanza.
     *
     * La comparación es la del WHERE (Valor::comparar) sobre el valor de la
     * clave, que es el de la fila: una clave de texto guarda el texto tal
     * cual y una numérica el número. Los valores van con la afinidad de la
     * columna (2.10: 5 con una de texto es '5'), y con valores de texto todas
     * las claves tienen que ser de texto, y con números todas numéricas: un
     * número y un texto no se comparan igual que dos números o dos textos.
     * Si aparece una de la otra clase, null, y se recorre la tabla. Null
     * también con un índice desfasado o antiguo.
     *
     * @param list<array{0: string, 1: int|float|string}> $comparaciones
     * @return list<int>|int|null  posiciones en orden o, con $contar, cuántas
     */
    public static function posicionesPorComparacion(Storage $st, string $tabla, array $def, array $comparaciones, bool $contar)
    {
        if (!$st->indices || $comparaciones === []) {
            return null;
        }
        // Con la afinidad de la columna (2.10), y con COLLATE (2.9) las claves
        // van plegadas, y lo que se compara con ellas también
        $a = $def['afin'][0] ?? null;
        $c = $def['plegar'][0] ?? null;
        $comparaciones = array_map(static fn(array $x): array
            => [$x[0], $c === null ? Valor::conAfinidad($x[1], $a) : Functions::plegar(Valor::conAfinidad($x[1], $a), $c)], $comparaciones);
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null) {
            return null;
        }
        $numeros = null;
        $min = $max = null;
        foreach ($comparaciones as [$op, $v]) {
            $esNumero = \is_int($v) || \is_float($v);
            if (($numeros !== null && $numeros !== $esNumero) || (!$esNumero && !\is_string($v))) {
                return null;
            }
            $numeros = $esNumero;
            if ($esNumero && $op !== '<' && $op !== '<=') {
                $min = $min === null ? $v : max($min, $v);
            }
            if ($esNumero && $op !== '>' && $op !== '>=') {
                $max = $max === null ? $v : min($max, $v);
            }
        }
        $tipo   = $numeros ? 'n' : 't';
        $nombre = $def['name'];
        $total  = 0;
        $pos    = [];
        // Para leer filas con números, primero una estimación sin abrir nada:
        // la parte del rango de cada trozo que cae dentro, como si sus valores
        // estuvieran repartidos por igual. Si hay que abrir más de media tabla,
        // ni se empieza: recorrerla es lo que hacía la 2.8, y no puede salir
        // más lento
        if (!$contar && $numeros && $plan['legado'] === null) {
            $estimado = 0.0;
            $chunk = max(1, (int)($st->estado($tabla)['chunk'] ?? $st->filasPorParte));
            foreach ($plan['rangos'] as $r) {
                if ($r === null || $r === []) {
                    continue;
                }
                $desde = $min === null ? $r[0] : max($r[0], $min);
                $hasta = $max === null ? $r[1] : min($r[1], $max);
                $filas = $hasta < $desde ? 0.0 : ($r[1] > $r[0] ? ($hasta - $desde + 1) / ($r[1] - $r[0] + 1) * $chunk : $chunk);
                // Se cuentan las partes que habrá que abrir: con pocas filas
                // en cada una pero repartidas por todas, leerlas por líneas
                // más el índice entero cuesta más que recorrer la tabla cuando
                // sus partes ya están en la caché
                $estimado += $filas >= 0.5 ? 1 : 0;
            }
            if ($estimado * 2 > $plan['partes']) {
                return null;
            }
        }
        // Con textos, lo mismo con el menor y el mayor valor de cada trozo, que
        // van en su cabecera (2.9): solo se leen los trozos que pueden tener
        // alguno, y si son más de la mitad, ni se empieza. Un trozo sin ellos
        // (de antes de la 2.9, o con claves de otro tipo) y se lee la tabla,
        // como en la 2.8
        $solo = null;
        if (!$numeros && $plan['legado'] === null) {
            $solo   = [];
            $enteros = 0;
            // El menor y el mayor de cada trozo también en la caché, con la
            // revisión del trozo: los que siguen al día no abren su cabecera
            $revsTrozos = (array)($st->estado($tabla)['indexes'][$nombre] ?? []);
            $claveLim   = $st->claveCache($tabla, 'l' . $nombre, 0);
            $lims       = $st->cacheLeer($claveLim);
            $lims       = \is_array($lims) ? $lims : [];
            $nuevos     = false;
            try {
            // El primero y el último, primero: si los dos caen enteros dentro
            // del rango, lo más probable es que lo haga casi toda la tabla
            $mirar = $plan['partes'] > 2 ? array_merge([1, $plan['partes']], range(2, $plan['partes'] - 1)) : range(1, $plan['partes']);
            foreach ($mirar as $n => $p) {
                if ($n === 2 && $enteros === 2 && !$contar) {
                    return null;
                }
                if (($plan['rangos'][$p - 1] ?? null) === []) {
                    continue;                             // trozo vacío
                }
                $revTrozo = (int)($revsTrozos[$p - 1] ?? -1);
                $l = $lims[$p] ?? null;
                if (\is_array($l) && $l[0] === $revTrozo) {
                    $cabeza = ['min' => $l[1], 'max' => $l[2]];
                } else {
                    $cabeza = self::cabeceraTrozo($st, $tabla, $def, $p);
                    if (!isset($cabeza['min'], $cabeza['max'])) {
                        return null;
                    }
                    $lims[$p] = [$revTrozo, $cabeza['min'], $cabeza['max']];
                    $nuevos = true;
                }
                $entero = true;
                foreach ($comparaciones as [$op, $b]) {
                    $alMax = Valor::comparar($cabeza['max'], $b);
                    $alMin = Valor::comparar($cabeza['min'], $b);
                    if (($op === '>' && $alMax <= 0) || ($op === '>=' && $alMax < 0) || ($op === '<' && $alMin >= 0)
                        || ($op === '<=' && $alMin > 0) || ($op === '=' && ($alMin > 0 || $alMax < 0))) {
                        continue 2;                       // ningún valor del trozo puede cumplir
                    }
                    $entero = $entero && (($op === '>' && $alMin > 0) || ($op === '>=' && $alMin >= 0)
                        || ($op === '<' && $alMax < 0) || ($op === '<=' && $alMax <= 0) || ($op === '=' && $alMin === 0 && $alMax === 0));
                }
                $enteros += $entero && $n < 2 ? 1 : 0;
                $solo[$p] = true;
                // En cuanto pasa de la mitad se deja, sin leer las cabeceras
                // que quedan: la decisión es la misma, y leerlas todas hacía
                // un rango que abarca casi toda la tabla un 2 % más lento que
                // recorrerla, como hacía la 2.8
                if (!$contar && \count($solo) * 2 > $plan['partes']) {
                    return null;
                }
            }
            } finally {
                if ($nuevos) {
                    $st->cacheGuardar($claveLim, $lims);
                }
            }
        }
        // Para leer filas, primero el primer trozo y el último: si ya apuntan a
        // más de media tabla, se deja (se recorrerá la tabla) sin haber leído
        // el índice entero. Contar siempre compensa.
        $orden = $plan['legado'] !== null ? [0] : range(1, $plan['partes']);
        if (!$contar && \count($orden) > 2) {
            $orden = array_merge([1, \count($orden)], range(2, \count($orden) - 1));
        }
        $coste = 0.0;
        $vistos = 0;
        foreach ($orden as $p) {
            if ($solo !== null && !isset($solo[$p])) {
                continue;
            }
            $r = $p > 0 ? $plan['rangos'][$p - 1] : null;
            if ($r === [] || ($numeros && $r !== null && (($min !== null && $r[1] < $min) || ($max !== null && $r[0] > $max)))) {
                continue;                                     // trozo vacío, o su rango no llega
            }
            $yaEstaba = $p > 0 && isset($st->indicesMemo[$tabla][$nombre][$p]);
            $keys = $p === 0 ? $plan['legado'] : $st->trozoIndice($tabla, $def, $p);
            if ($keys === null) {
                return null;
            }
            foreach ($keys as $clave => $donde) {
                $clave = (string)$clave;
                if ($clave[0] !== $tipo) {
                    return null;
                }
                $v = Indexes::primerValor($clave);
                foreach ($comparaciones as [$op, $b]) {
                    $c = Valor::comparar($v, $b);
                    if (!($op === '<' ? $c < 0 : ($op === '<=' ? $c <= 0 : ($op === '>' ? $c > 0 : ($op === '>=' ? $c >= 0 : $c === 0))))) {
                        continue 2;
                    }
                }
                if ($contar) {
                    $total += \is_array($donde) ? \count($donde) : 1;
                } else {
                    foreach ((array)$donde as $x) {
                        $pos[] = (int)$x;
                    }
                }
            }
            if (!$contar && $p > 0) {
                // Lo que costará leer las filas de esta parte (ver StorageLectura::filasDePosiciones())
                $aqui   = \count($pos) - $total;
                $total  = \count($pos);
                $coste += $aqui <= self::FILAS_POR_LINEA ? $aqui / self::LINEAS_COMO_PARTE : 1;
                if (++$vistos >= 2 && $coste / $vistos * \count($orden) * 2 > $plan['partes']) {
                    return null;
                }
            }
            // Un trozo que no estaba en memoria no se queda: recorrer un índice
            // entero no tiene por qué dejarlo entero en memoria
            if ($p > 0 && !$yaEstaba) {
                unset($st->indicesMemo[$tabla][$nombre][$p]);
            }
            Memoria::comprobar('la lectura del índice');
        }
        if ($contar) {
            return $total;
        }
        sort($pos);
        return $pos;
    }

    /**
     * El mínimo y el máximo de la primera columna de un índice numérico, de
     * los rangos que el estado ya guarda por trozo: sin abrir ningún fichero.
     * ['min' => null, 'max' => null] si no hay ningún valor (los NULL no
     * entran en un índice); null si no se sabe (algún trozo con claves de
     * texto o sin rango, un índice de antes de la 2.6, índices apagados).
     *
     * @return array{min:int|float|null,max:int|float|null}|null
     */
    public static function extremosIndice(Storage $st, string $tabla, array $def): ?array
    {
        if (!$st->indices) {
            return null;
        }
        self::validarTabla($tabla);
        $st->bloquearLectura($tabla);
        $plan = $st->planIndice($tabla, $def);
        if ($plan === null || $plan['legado'] !== null) {
            return null;
        }
        foreach ($plan['rangos'] as $r) {
            if ($r === null) {
                return null;
            }
        }
        return ['min' => $plan['min'], 'max' => $plan['max']];
    }

    /**
     * Filas de una tabla cuya primera columna en un índice cumple unas
     * comparaciones (ver posicionesPorComparacion()), leyendo los trozos del
     * índice y después solo las partes donde están. Null si no se puede o no
     * compensa: entonces se recorre la tabla.
     *
     * @param list<array{0: string, 1: int|float|string}> $comparaciones
     * @return list<array>|null
     */
    public static function filasPorComparacion(Storage $st, string $tabla, array $def, array $comparaciones): ?array
    {
        $posiciones = self::posicionesPorComparacion($st, $tabla, $def, $comparaciones, false);
        return \is_array($posiciones) ? StorageLectura::filasDePosiciones($st, $tabla, $posiciones) : null;
    }
}
