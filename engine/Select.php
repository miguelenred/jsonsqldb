<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Ejecutor de SELECT.
 *
 * Durante la ejecución cada fila es un array plano con claves "alias.columna",
 * de modo que leer una columna es un acceso directo. Los JOIN con condición de
 * igualdad se resuelven con tabla hash (no comparando todas las filas contra
 * todas), y las subconsultas se ejecutan una sola vez y se guardan en memoria.
 *
 * https://miguelenred.es/jsonsqldb
 */
class Select
{
    protected Catalog $cat;
    /** @var callable(string, list<list<string>>=):iterable<array> lector de filas (tabla y, si sirven, los textos que tiene que contener una parte: ver Storage::filas()); permite ver cambios aún no volcados a disco */
    protected $lector;
    /** @var array{0: string, 1: ?string} cómo se leyó la última tabla, para EXPLAIN */
    private array $via = ['recorrido', null];
    /** El OFFSET de una consulta sin WHERE ni ORDER BY sobre una tabla, para saltar partes enteras al leerla (2.9); y las filas saltadas así */
    private int $saltoPedido = 0;
    private int $saltadas = 0;
    /** @var array{0: int, 1: list<string>}|null LIMIT (+ OFFSET) y las columnas de un WHERE que es solo igualdades: un índice que las cubra para en cuanto las tiene (2.9) */
    private ?array $topeIndice = null;

    /**
     * Si se puede resolver una consulta por índice.
     *
     * Solo con el lector de disco. Cuando escribe el Writer, las filas que se
     * ven son las que tiene a medias en memoria y los índices en disco todavía
     * no las conocen.
     */
    protected bool $indexable;
    /** Profundidad máxima de vistas anidadas (una vista que usa otra vista). */
    private const MAX_VISTAS = 8;


    /**
     * La columna con la posición de cada fila en su tabla, en el origen del
     * FROM marcado con 'posicion' (UPDATE y DELETE con JOIN, ver
     * WriterCambiosEspeciales::cruce()). Un nombre de columna no puede llevar un \0.
     */
    public const POSICION = "\0posicion";

    /** Particiones de un GROUP BY o de un JOIN por el disco: con un millón de grupos, unos 16.000 en cada una */
    protected const PARTICIONES = 64;

    /** @var int vistas resueltas en la cadena actual, para cortar ciclos */
    private int $profundidadVista = 0;

    /** @var array<string,array> consultas con nombre del WITH en curso, por su nombre en minúsculas */
    protected array $con = [];
    /** @var array<string,true> las del WITH que se están calculando, para cortar un círculo (ver delWith()) */
    protected array $enCurso = [];

    /** @var array{mapa: array, fila: array}|null consulta exterior, si estamos dentro de una subconsulta */
    protected ?array $externo = null;

    /** @var array<int,array<string,array>> resultados de subconsultas correlacionadas, por fila exterior (ver guardarCorr()) */
    private array $subsCorr = [];
    /** Cuántos guardan entre $subsCorr y $conjuntosCorr */
    private int $corrGuardados = 0;
    /** Cuántos pueden guardar como mucho, como las claves de Collation::clave() */
    private const CORR_MAX = 4096;

    /** @var array<int,array> resultado de cada subconsulta ya ejecutada */
    private array $subs = [];
    /** @var array<int, array> conjuntos de un IN cuya subconsulta no mira hacia fuera */
    private array $conjuntos = [];
    /** @var array<int, array<string, array>> los de una que sí mira hacia fuera, por fila de fuera */
    private array $conjuntosCorr = [];
    /** @var array<int, array|false> cada EXISTS correlacionado como semi-join, o false si no se puede (ver SelectJoin::semiJoin()) */
    private array $semi = [];
    /** @var array<int, ?string> la colación de la primera columna de cada subconsulta ya ejecutada (2.9) */
    private array $colacionSub = [];
    /** @var array<int, string> y su afinidad (2.10, ver IndexesBusqueda::conjuntoDeIn()) */
    private array $afinidadSub = [];
    /** La colación de la primera columna de lo último que ejecutó ejecutarExterna() (2.9), para el IN de Writer */
    public ?string $colacionExterna = null;
    /** Y su afinidad (2.10) */
    public string $afinidadExterna = '';

    public function __construct(Catalog $cat, ?callable $lector = null)
    {
        $this->cat       = $cat;
        $this->indexable = $lector === null;
        $this->lector    = $lector ?? static fn(string $t, array $agujas = []): iterable => $cat->storage()->filas($t, false, $agujas);
    }

    /** @var (callable(array):void)|null adónde van las filas de la consulta de fuera (ejecutarHacia()) */
    protected $sumidero = null;
    /** Profundidad (ver $nivel) de la consulta cuyas filas van al sumidero: la de fuera, o una subconsulta (ver valoresDe()) */
    protected int $nivelSumidero = 1;
    /** Profundidad de correr(): 1 es la consulta de fuera; más, subconsultas, vistas y CTE */
    protected int $nivel = 0;
    /** ¿Han salido filas por el sumidero? */
    protected bool $entregado = false;

    /** Ejecuta la consulta y devuelve las filas de salida. */
    public function ejecutar(array $ast): array
    {
        return $this->correr($ast)['filas'];
    }

    /**
     * Como ejecutar(), con los nombres de las columnas de salida, que hacen
     * falta aunque no salga ninguna fila (CREATE TABLE … AS SELECT).
     *
     * @return array{cols: list<string>, filas: list<array<string,mixed>>}
     */
    public function ejecutarConColumnas(array $ast): array
    {
        $r = $this->correr($ast);
        return ['cols' => array_values($r['cols']), 'filas' => $r['filas']];
    }

    /**
     * Como ejecutar(), pero en una consulta sin ORDER BY, DISTINCT ni
     * agregados cada fila va a $porFila según se lee y se proyecta, sin
     * juntarlas en un array; entonces devuelve null. Las que necesitan todo el
     * resultado para ordenarlo o agruparlo lo devuelven como siempre: ya está
     * entero en memoria, y entregarlo fila a fila solo costaría tiempo.
     *
     * @param callable(array):void $porFila
     */
    public function ejecutarHacia(array $ast, callable $porFila): ?array
    {
        $this->sumidero      = $porFila;
        $this->nivelSumidero = 1;
        $this->entregado     = false;
        try {
            $filas = $this->correr($ast)['filas'];
        } finally {
            $this->sumidero = null;
        }
        return $this->entregado ? null : $filas;
    }

    /**
     * Una subconsulta de un UPDATE o un DELETE, que puede mirar la fila de
     * fuera: $mapa dice cómo se llaman sus columnas (col y tabla.col) y $fila
     * es la fila que se está escribiendo.
     */
    public function ejecutarExterna(array $ast, array $mapa, array $fila): array
    {
        $this->externo = ['mapa' => $mapa, 'fila' => $fila];
        $r = $this->correr($ast);
        $this->colacionExterna = $r['colaciones'][0] ?? null;
        $this->afinidadExterna = $r['afinidades'][0] ?? '';
        return $r['filas'];
    }

    /** Como ejecutarExterna(), pero solo los valores de la primera columna (ver valoresDe()): los de un IN de Writer. */
    public function valoresExterna(array $ast, array $mapa, array $fila): array
    {
        $this->externo = ['mapa' => $mapa, 'fila' => $fila];
        [$valores, $this->colacionExterna, $this->afinidadExterna] = $this->valoresDe($ast);
        return $valores;
    }

    /**
     * Los valores de la primera columna de una subconsulta, y su colación y
     * su afinidad (2.10), tomados según salen sus filas, sin juntarlas (2.9): un IN (SELECT id
     * FROM t) de un millón de filas las tenía todas en memoria, y no cabía en
     * 256 MB. Lo que la subconsulta no puede dar fila a fila (ORDER BY,
     * agregados) llega entero, como siempre.
     *
     * @return array{0: list<mixed>, 1: ?string, 2: string}
     */
    protected function valoresDe(array $sel): array
    {
        $valores = [];
        $previo  = [$this->sumidero, $this->nivelSumidero, $this->entregado];
        $this->sumidero      = static function (array $fila) use (&$valores): void {
            $valores[] = reset($fila);
        };
        $this->nivelSumidero = $this->nivel + 1;
        try {
            $r = $this->correr($sel);
            foreach ($r['filas'] as $fila) {
                $valores[] = reset($fila);
            }
            return [$valores, $r['colaciones'][0] ?? null, $r['afinidades'][0] ?? ''];
        } finally {
            [$this->sumidero, $this->nivelSumidero, $this->entregado] = $previo;
        }
    }

    /**
     * Lo que tiene que haber costado una consulta, en nanosegundos, para
     * guardar su resultado (2.9): 1 ms. Una búsqueda por clave cuesta menos
     * que guardarla, y casi nunca se repite tal cual; guardarlas todas
     * llenaba la caché de resultados que nadie volvía a pedir.
     */
    private const RESULTADO_MINIMO = 1000000;

    /**
     * Ejecuta un SELECT pasando por la caché de resultados: la clave lleva la
     * SQL, los parámetros y la revisión de cada tabla implicada, así que un
     * resultado guardado solo vale mientras nadie escriba en ninguna de ellas.
     * No se guardan las consultas que no son deterministas (RANDOM(), la
     * fecha actual) ni los resultados grandes, que no compensan. Lo llama
     * Database::consultar(), con lo suyo: aquí desde la 2.10, que solo lo
     * usan las consultas (en Database lo compilaba también cada escritura).
     *
     * @param (callable(array):void)|null $porFila ver Database::consultarPorFilas()
     * @return list<array>
     */
    public static function conCache(Catalog $cat, Storage $st, ?callable $porFila, array $ast, string $sql, array $params): array
    {
        $tope   = Config::cacheResultados();
        $tablas = $tope > 0 ? self::tablasDe($ast, $cat) : null;
        // Lo que no lee ninguna tabla no se guarda (2.9): ninguna escritura lo
        // borraría nunca, y cada consulta distinta dejaba su fichero
        if ($tablas !== null && array_filter($tablas, static fn(string $t): bool => $t[0] !== '#') === []) {
            $tablas = null;
        }
        if ($tablas === null) {
            return $porFila === null
                ? (new self($cat))->ejecutar($ast)
                : (new self($cat))->ejecutarHacia($ast, $porFila) ?? [];
        }
        $clave = StorageLectura::claveResultado($st, $tablas, $sql, $params);
        $filas = StorageLectura::resultadoCacheado($st, $clave, $tablas);
        if ($filas !== null) {
            return $filas;                           // ya está entero: se devuelve tal cual
        }
        $inicio = hrtime(true);
        if ($porFila === null) {
            $filas = (new self($cat))->ejecutar($ast);
        } else {
            // Las filas que salen una a una se guardan también para la caché
            // mientras no pasen del tope; pasado, se dejan de guardar
            $guardadas = [];
            $filas = (new self($cat))->ejecutarHacia($ast, static function (array $fila) use ($porFila, &$guardadas, $tope): void {
                if ($guardadas !== null) {
                    $guardadas[] = $fila;
                    if (\count($guardadas) > $tope) {
                        $guardadas = null;
                    }
                }
                $porFila($fila);
            });
            if ($filas === null) {
                if ($guardadas !== null && hrtime(true) - $inicio >= self::RESULTADO_MINIMO) {
                    StorageLectura::guardarResultado($st, $clave, $tablas, $guardadas);
                }
                return [];
            }
        }
        if (\count($filas) <= $tope && hrtime(true) - $inicio >= self::RESULTADO_MINIMO) {
            StorageLectura::guardarResultado($st, $clave, $tablas, $filas);
        }
        return $filas;
    }

    /**
     * Tablas de las que depende una consulta —las del FROM, las de sus
     * subconsultas y las de las vistas que use—, o null si su resultado no
     * se puede guardar: porque no es determinista (RANDOM(), la fecha de
     * ahora) o porque nombra una tabla que no existe.
     *
     * @return list<string>|null
     */
    public static function tablasDe(array $ast, Catalog $cat, int $nivel = 0): ?array
    {
        if ($nivel > self::MAX_VISTAS) {
            return null;
        }
        $tablas = [];
        $cte    = [];
        if (!self::recorrer($ast, $cat, $nivel, $tablas, $cte)) {
            return null;
        }
        $tablas = array_keys($tablas);
        sort($tablas, SORT_STRING);
        return $tablas;
    }

    /**
     * Recorre el árbol entero de la consulta anotando las tablas.
     *
     * @param array<string,true> $tablas
     * @param array<string,true> $cte  nombres del WITH, que no son tablas
     */
    private static function recorrer(array $n, Catalog $cat, int $nivel, array &$tablas, array &$cte): bool
    {
        if (isset($n['with']) && \is_array($n['with'])) {
            foreach (array_keys($n['with']) as $nombre) {
                $cte[strtolower((string)$nombre)] = true;
            }
        }
        if (($n['k'] ?? null) === 'fn' && isset($n['nombre'])) {
            $f = strtoupper((string)$n['nombre']);
            // Lo que cambia en cada llamada no se guarda en la caché de resultados
            if (\in_array($f, ['RANDOM', 'RAND', 'UUID', 'RANDOMBLOB', 'UTC_DATE', 'UTC_TIME', 'UTC_TIMESTAMP'], true)) {
                return false;
            }
            if (\in_array($f, ['DATE', 'TIME', 'DATETIME', 'STRFTIME', 'UNIXEPOCH', 'JULIANDAY', 'UNIX_TIMESTAMP'], true)
                && ($f === 'STRFTIME' ? $n['args'][1] ?? null : $n['args'][0] ?? null) === null) {
                return false;                         // sin fecha: la de ahora
            }
        }
        // 'now' es la fecha de ahora para cualquier función de fechas, también
        // como parámetro, que llega aquí como literal (2.9: solo se miraba en
        // DATE() y compañía, y DATE_FORMAT('now', …) salía de la caché con la
        // hora de la primera vez)
        if (($n['k'] ?? null) === 'lit' && \is_string($n['v'] ?? null) && strcasecmp(trim($n['v']), 'now') === 0) {
            return false;
        }
        if (($n['tipo'] ?? null) === 'tabla' && isset($n['nombre'])) {
            $nombre = (string)$n['nombre'];
            if (!isset($cte[strtolower($nombre)])) {
                if ($cat->esVista($nombre)) {
                    $sqlVista = (string)$cat->vista($nombre)['sql'];
                    $de = self::tablasDe(Parser::analizar($sqlVista, [], $cat->nombres()), $cat, $nivel + 1);
                    if ($de === null) {
                        return false;
                    }
                    foreach ($de as $t) {
                        $tablas[$t] = true;
                    }
                    // La definición de la vista también decide el resultado:
                    // redefinirla con el mismo nombre no cambia la revisión de
                    // sus tablas, y la caché servía el resultado de la vieja
                    $tablas['#v:' . strtolower($nombre) . ':' . md5($sqlVista)] = true;
                } elseif ($cat->existe($nombre)) {
                    $tablas[$nombre] = true;
                } else {
                    return false;
                }
            }
        }
        foreach ($n as $v) {
            if (\is_array($v) && !self::recorrer($v, $cat, $nivel, $tablas, $cte)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{cols: string[], filas: array} */
    protected function correr(array $ast): array
    {
        // Las CTE del WITH quedan disponibles mientras se ejecuta esta consulta
        // y las de dentro. Se apilan para que un WITH anidado no pise al de
        // fuera y para restaurarlo todo al salir.
        $conAnterior = $this->con;
        if (isset($ast['with'])) {
            $this->con = array_change_key_case($ast['with'], CASE_LOWER) + $this->con;   // como las tablas, sin mayúsculas
        }

        $this->nivel++;
        try {
            if ($ast['k'] === 'union') {
                return SelectCompuesta::correrUnion($this, $ast);
            }
            try {
                return $this->correrSimple($ast);
            } catch (JsonSqlDbError $e) {
                // Algo que no cabe en memoria por el camino rápido, que lo junta
                // entero: un ORDER BY, el lado interno de un JOIN, los grupos de
                // un GROUP BY, una subconsulta del FROM (2.9, antes solo el ORDER
                // BY). Memoria avisa al pasar de la mitad del límite y se repite
                // por el camino que usa el disco. El camino rápido no cambia ni
                // un paso, y la repetición solo se paga cuando de verdad no cabe.
                // Una subconsulta del FROM que se está juntando no se repite ella:
                // juntaría lo mismo; se repite la de fuera, que la escribe en disco
                if ($e->sqlState !== 'APRETADA' || $this->nivel === $this->juntando) {
                    throw $e;
                }
                $antes = $this->enDisco;
                $this->enDisco = true;
                // Lo guardado de las subconsultas correlacionadas se vuelve a
                // calcular si hace falta: que no ocupe la memoria del disco (2.9)
                $this->subsCorr = $this->conjuntosCorr = [];
                $this->corrGuardados = 0;
                // Lo medido en el intento fallido no vale para este, y lo que
                // PHP se guardó de él (bloques libres) se devuelve: si no, la
                // memoria pedida sigue arriba y el disco se queda sin sitio
                gc_mem_caches();
                Memoria::iniciar();
                try {
                    return $this->correrSimple($ast);
                } finally {
                    $this->enDisco = $antes;
                }
            }
        } finally {
            $this->nivel--;
            $this->con = $conAnterior;
        }
    }

    /** @var array{def: array, desc: bool, k: int, binaria: ?bool, desde: array{0: int|float, 1: bool}|null}|null ver SelectOrden::ordenPorIndice(), para el primer origen */
    private ?array $porOrden = null;
    /** Algo no cupo en memoria por el camino rápido: por el que usa el disco (ver correr()) */
    protected bool $enDisco = false;
    /** El nivel (ver $nivel) de la subconsulta del FROM que se está juntando entera, o 0 (ver cargar()) */
    protected int $juntando = 0;
    /** @var array{0: array, 1: ?string}|null el WHERE de la consulta en curso y su alias único, para porRango() */
    private ?array $whereRangos = null;

    private function correrSimple(array $ast): array
    {
        $contado = $this->contarRapido($ast) ?? $this->extremosRapidos($ast);
        if ($contado !== null) {
            return $contado;
        }
        // De aquí en adelante se usa siempre Evaluator: se carga ya, antes de
        // leer filas y de cargar lo que hace falta para leerlas, para que sin
        // OPcache lo que cuesta compilarlo no se sume a lo que ocupan ellas
        // (2.10: el pico de una consulta por clave subía 0,09 MB sobre la 2.9.0);
        // una vez por proceso, que la llamada en cada consulta se notaba en un SELECT 1
        static $cargado = false;
        $cargado = $cargado || class_exists(Evaluator::class);

        // ¿Se puede dejar de recorrer en cuanto haya suficientes filas?
        // Solo si el resultado no depende de las filas que vendrían después:
        // sin ORDER BY, sin agrupar, sin agregados y sin DISTINCT.
        $tope = $this->topeTemprano($ast);

        [$filas, $fuentes] = $this->origenes($ast, $tope);
        $mapa = $this->mapaColumnas($fuentes);
        $externa = $this->externo['fila'] ?? [];
        // Lo de más fuera, para las subconsultas de esta (ver masFuera())
        $lejos = $this->externo === null ? null : self::masFuera($this->externo);
        // Una subconsulta, con la fila de fuera a la vista: sus filas, o con
        // $valores solo los de su primera columna (ver valoresDe()), y si ha
        // mirado hacia fuera. El análisis estático no puede saberlo: la marca la
        // pone Evaluator::resolver() durante la llamada anidada
        $correrSub = function (array $sel, int $sid, array $filaExterna, bool $valores) use ($mapa, $lejos): array {
            $externoPrevio   = $this->externo;
            $marcaPrevia     = Evaluator::$correlacionada;
            $externasPrevias = Evaluator::$externas;
            Evaluator::$correlacionada = false;
            Evaluator::$externas       = [];
            // Con las columnas de las consultas de más fuera detrás de las de
            // esta, que las tapan (2.10: una subconsulta dentro de otra no veía
            // las de dos niveles más afuera, «Columna desconocida»). $filaExterna
            // ya las trae (ver $sub y $conjunto)
            $this->externo = ['mapa' => $lejos === null ? $mapa : $mapa + $lejos['mapa'], 'fila' => $filaExterna];
            try {
                if ($valores) {
                    [$r, $this->colacionSub[$sid], $this->afinidadSub[$sid]] = $this->valoresDe($sel);
                } else {
                    $res = $this->correr($sel);
                    $r   = $res['filas'];
                    $this->colacionSub[$sid] = $res['colaciones'][0] ?? null;
                    $this->afinidadSub[$sid] = $res['afinidades'][0] ?? '';
                    unset($res);                   // que no queden dos referencias a las filas
                }
                return [$r, Evaluator::$correlacionada];
            } finally {
                // Lo que miró más allá de esta consulta (las claves con un "\1"
                // delante, ver masFuera()) la hace correlacionada a ella también,
                // con la de fuera, que lo ve un nivel más cerca
                $ajenas = [];
                foreach (Evaluator::$externas as $clave => $_) {
                    if (str_starts_with((string)$clave, "\1")) {
                        $ajenas[substr((string)$clave, 1)] = true;
                    }
                }
                $this->externo             = $externoPrevio;
                Evaluator::$correlacionada = $marcaPrevia || $ajenas !== [];
                Evaluator::$externas       = $externasPrevias + $ajenas;
            }
        };
        // Una subconsulta que no mira hacia fuera da siempre lo mismo: se
        // ejecuta una vez. Si está correlacionada, su resultado depende de
        // la fila de fuera, así que se guarda por fila.
        $sub  = function (array $sel, int $sid, array $filaExterna = [], bool $existe = false) use ($correrSub, $mapa, $lejos): array {
            if (isset($this->subs[$sid])) {
                return $this->subs[$sid];
            }
            if ($lejos !== null) {
                $filaExterna += $lejos['fila'];
            }
            // Un EXISTS que mira fuera por una igualdad: sin ejecutarlo por
            // cada fila (ver SelectJoin::semiJoin())
            if ($existe) {
                $this->semi[$sid] ??= SelectJoin::semiJoin($this, $sel, $mapa) ?? false;
                if ($this->semi[$sid] !== false) {
                    [$conj, $fuera] = $this->semi[$sid];
                    return EvaluatorConjunto::enConjunto(Evaluator::evaluar($fuera, ['fila' => $filaExterna]), $conj, false) === 1 ? [[1]] : [];
                }
            }
            $claveFila = $filaExterna === [] ? '' : self::claveFila($filaExterna);
            if (isset($this->subsCorr[$sid][$claveFila])) {
                return $this->subsCorr[$sid][$claveFila];
            }
            [$filas, $usoFuera] = $correrSub($sel, $sid, $filaExterna, false);
            if ($usoFuera) {
                $this->guardarCorr($this->subsCorr, $sid, $claveFila, $filas);
            } else {
                $this->subs[$sid] = $filas;
            }
            return $filas;
        };

        // Los valores de un IN agrupados por clave, para no recorrer la lista en
        // cada fila; igual que las filas de $sub, una vez o por fila de fuera
        $conjunto = function (array $sel, int $sid, array $filaExterna = [], string|bool|null $colacion = null, ?string $afinidad = null) use ($correrSub, $lejos): array {
            if (isset($this->conjuntos[$sid])) {
                return $this->conjuntos[$sid];
            }
            if ($lejos !== null) {
                $filaExterna += $lejos['fila'];
            }
            $claveFila = $filaExterna === [] ? '' : self::claveFila($filaExterna);
            if (isset($this->conjuntosCorr[$sid][$claveFila])) {
                return $this->conjuntosCorr[$sid][$claveFila];
            }
            [$valores, $usoFuera] = $correrSub($sel, $sid, $filaExterna, true);
            $c = IndexesBusqueda::conjuntoDeIn($valores, $colacion, $this->colacionSub[$sid] ?? null, $afinidad, $this->afinidadSub[$sid] ?? null);
            if ($usoFuera) {
                $this->guardarCorr($this->conjuntosCorr, $sid, $claveFila, $c);
            } else {
                $this->conjuntos[$sid] = $c;
            }
            return $c;
        };

        // WHERE: las filas que pasan salen según se recorren; las que no, se
        // sueltan al momento
        if ($ast['where'] !== null) {
            $where = Evaluator::resolver($ast['where'], $mapa, [], $this->externo['mapa'] ?? []);
            $filas = $this->filtrar($filas, $where, $sub, $conjunto, $externa, $tope);
        }

        // Columnas de salida (expandiendo * )
        $salida = $this->columnasSalida($ast['cols'], $fuentes, $mapa);
        // La colación de cada una (2.9, ver Evaluator::colacionSalida()):
        // DISTINCT las compara con ella, y la heredan si esto es una vista,
        // una subconsulta o una parte de un UNION
        $colaciones   = [];
        $afinidades   = [];                         // y la afinidad (2.10, ver Evaluator::afinidadDe())
        $plegarSalida = [];
        foreach ($salida as $c) {
            $afinidades[] = $c['expr']['k'] === 'col' ? $c['expr']['afinidad'] ?? '' : Evaluator::afinidadDe($c['expr']) ?? '';
            $colaciones[] = $col = Evaluator::colacionSalida($c['expr']);
            if ($col !== null && $col !== 'BINARY') {
                $plegarSalida[$c['nombre']] = $col;
            }
        }

        // ¿Hay agrupación?
        $grupoExprs = [];
        if ($ast['group'] !== null) {
            foreach ($ast['group'] as $e) {
                // GROUP BY 2: la segunda columna de la salida, como en ORDER BY
                // y en SQLite. Un número suelto agrupaba por la constante: todas
                // las filas en un grupo, y una sola fila de resultado sin avisar
                if (($e['k'] ?? '') === 'lit' && \is_int($e['v'])) {
                    $n = $e['v'];
                    if ($n < 1 || $n > \count($salida)) {
                        throw JsonSqlDbError::syntax("GROUP BY $n: la consulta devuelve " . \count($salida) . ' columna(s)');
                    }
                    if (Evaluator::tieneAgregado($salida[$n - 1]['expr'])) {
                        throw JsonSqlDbError::syntax("GROUP BY $n: esa columna es una función de agregado y no se puede agrupar por ella");
                    }
                    $grupoExprs[] = Evaluator::conColacion($salida[$n - 1]['expr']);
                    continue;
                }
                $grupoExprs[] = Evaluator::conColacion(Evaluator::resolver($e, $mapa, [], $this->externo['mapa'] ?? []));
            }
        }
        $agrupar = $grupoExprs !== [] || $ast['having'] !== null || $this->hayAgregados($salida, $ast);

        // Alias de salida, utilizables en el HAVING y en el ORDER BY
        $aliasSalida = [];
        foreach ($salida as $c) {
            $aliasSalida[strtolower($c['nombre'])] = $c['nombre'];
        }
        // En el HAVING, un alias de la salida que no es una columna de las
        // tablas se sustituye por su expresión: HAVING n > 3 con COUNT(*) AS n
        // es HAVING COUNT(*) > 3, que es lo que sabe acumular la agrupación
        $crudos = [];
        foreach ($ast['cols'] as $c) {
            if (empty($c['star']) && ($c['alias'] ?? null) !== null && !\array_key_exists(strtolower($c['alias']), $mapa)) {
                $crudos[strtolower((string)$c['alias'])] = $c['expr'];
            }
        }
        $having = $ast['having'] === null ? null : Evaluator::resolver(
            $crudos === [] ? $ast['having'] : SelectAgrupada::sustituirAlias($ast['having'], $crudos),
            $mapa, [], $this->externo['mapa'] ?? []);

        // Los alias escritos con AS, por su nombre en minúsculas (el primero, si se repite)
        $escritos = [];
        foreach ($salida as $c) {
            if (isset($c['como'])) {
                $escritos[$c['como']] ??= $c['nombre'];
            }
        }
        $orden = [];
        foreach ($ast['order'] as $o) {
            // ORDER BY 2: la segunda columna de la salida. Un número suelto no
            // ordenaba nada (era una constante) y el resultado salía en el
            // orden de la tabla sin avisar
            // Con COLLATE detrás (2.10: ORDER BY 2 COLLATE NOCASE no ordenaba
            // nada, era una constante), con esa colación
            [$pos, $colacion] = SelectOrden::posicionConColacion($o['expr']);
            if ($pos !== null) {
                $n = $pos;
                if ($n < 1 || $n > \count($salida)) {
                    throw JsonSqlDbError::syntax("ORDER BY $n: la consulta devuelve " . \count($salida) . ' columna(s)');
                }
                $e = $salida[$n - 1]['expr'];
                $colacion ??= Parser::colacionEscrita($e);
                $orden[] = ['expr' => $colacion === null || $colacion === 'BINARY' ? Evaluator::conColacion($e)
                    : ['k' => 'fn', 'nombre' => 'PLEGAR', 'args' => [$e, ['k' => 'lit', 'v' => $colacion]], 'star' => false, 'distinct' => false],
                    'dir' => $o['dir'], 'binaria' => $colacion === 'BINARY'];
                continue;
            }
            $e = ($escritos === [] ? null : self::aliasEscrito($o['expr'], $escritos))
                ?? Evaluator::resolver($o['expr'], $mapa, $aliasSalida, $this->externo['mapa'] ?? []);
            // Un alias de una columna con COLLATE se ordena con él, como ella (2.9)
            if (($e['k'] ?? '') === 'col' && isset($e['alias'], $plegarSalida[$e['alias']])) {
                $e = ['k' => 'fn', 'nombre' => 'PLEGAR', 'args' => [$e, ['k' => 'lit', 'v' => $plegarSalida[$e['alias']]]], 'star' => false, 'distinct' => false];
            }
            // Con COLLATE BINARY escrito, byte a byte, sin la colación configurada (2.10: la usaba igual)
            $orden[] = ['expr' => Evaluator::conColacion($e), 'dir' => $o['dir'], 'binaria' => Parser::colacionEscrita($e) === 'BINARY'];
        }

        // Proyección + claves de ordenación
        $resultado = [];
        $clavesOrden = [];

        // Los agregados de la salida, el HAVING y el ORDER BY se acumulan
        // recorriendo las filas una vez; después cada grupo es su fila
        // representativa y los resultados de sus acumuladores
        $defs = [];
        if ($agrupar) {
            foreach ($salida as $i => $c) {
                $salida[$i]['expr'] = SelectAgrupada::marcarAgregados($c['expr'], $defs);
            }
            if ($having !== null) {
                $having = SelectAgrupada::marcarAgregados($having, $defs);
            }
            foreach ($orden as $i => $o) {
                $orden[$i]['expr'] = SelectAgrupada::marcarAgregados($o['expr'], $defs);
            }
        }
        // Las funciones de ventana (2.9) se apartan: se calculan con todas las
        // filas, ya agrupadas si hay GROUP BY, antes de proyectar
        $ventanas = [];
        if (!empty($ast['ventanas'])) {
            foreach ($salida as $i => $c) {
                $salida[$i]['expr'] = SelectAvanzada::apartarVentanas($c['expr'], $ventanas);
            }
            foreach ($orden as $i => $o) {
                $orden[$i]['expr'] = SelectAvanzada::apartarVentanas($o['expr'], $ventanas);
            }
        }

        // Lo que se proyecta desde su contexto: un grupo, o una fila con sus ventanas
        $proyectarCtx = static function (array $origen, array $ctx) use ($salida): array {
            $fila = [];
            foreach ($salida as $c) {
                $fila[$c['nombre']] = Evaluator::evaluar($c['expr'], $ctx);
            }
            return $fila;
        };
        // Por el disco, los grupos se hacen por particiones, y sin GROUP BY, los
        // valores de un COUNT(DISTINCT …) (ver SelectAgrupada::agrupar())
        $particionar = $grupoExprs !== [] || ($agrupar && SelectAgrupada::distintosEnDisco($defs) !== []);
        $carpeta     = $this->enDisco && $particionar ? $this->cat->storage()->carpetaOrden() : null;

        if ($ventanas !== []) {
            // Cada fila, o cada grupo con sus agregados, que pasa el HAVING,
            // con solo las columnas que se usan después (2.10): se guardan
            // todas hasta calcular las ventanas, y con las demás columnas
            // ocupaban el doble o más
            $usar = self::clavesUsadas([$salida, $orden, $ventanas]);
            $contextos = (static function () use ($filas, $agrupar, $grupoExprs, $defs, $sub, $conjunto, $externa, $having, $carpeta, $usar): \Generator {
                foreach ($agrupar ? SelectAgrupada::agrupar($filas, $grupoExprs, $defs, $sub, $conjunto, $externa, $carpeta) : $filas as $f) {
                    $ctx = $agrupar ? ['fila' => $f[0], 'agregados' => $f[1], 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa]
                                    : ['fila' => $f, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa];
                    if ($having === null || Valor::verdadero(Evaluator::evaluar($having, $ctx)) === true) {
                        if ($usar !== null) {
                            $ctx['fila'] = array_intersect_key($ctx['fila'], $usar);
                        }
                        yield $ctx;
                    }
                }
            })();
            unset($filas);
            if ($this->enDisco) {
                // No cupo (2.9): las filas van a un temporal y en memoria solo
                // quedan los valores que usan las ventanas
                SelectEnDisco::ordenarEnDisco($this, SelectAvanzada::ventanasEnDisco($this, $contextos, $ventanas),
                    $ast['distinct'] ? [] : null, $orden, $proyectarCtx, $ast, $resultado, $plegarSalida);
                $orden        = [];
                $recortado    = true;
                $sinRepetidas = true;
            } else {
                // Si no caben, Memoria avisa y se repite por el disco (ver correr()).
                // Sin agrupar, solo las filas: lo demás del contexto es igual en
                // todas, y guardado en cada una ocupaba tanto como ella (2.10)
                $base  = [];
                $comun = null;
                $aviso = Memoria::avisarAlApretar(true);
                try {
                    foreach ($contextos as $ctx) {
                        Memoria::comprobar('las funciones de ventana');
                        if ($agrupar) {
                            $base[] = $ctx;
                        } else {
                            $comun ??= array_diff_key($ctx, ['fila' => 0]);     // una vez: es el mismo en todas (2.10)
                            $base[] = $ctx['fila'];
                        }
                    }
                    $comun ??= [];
                    unset($contextos);
                    // Salen en el orden en que las deja la primera ventana, como en SQLite.
                    // Las funciones se sueltan al volver: tenían $base, y soltarla
                    // fila a fila la copiaría
                    [$secuencia, $valores] = SelectAvanzada::ventanas($ventanas, \count($base),
                        static function (array $e) use ($base, $agrupar, $comun): array {
                            $c = [];
                            // Una columna, leída de la fila sin pasar por Evaluator (2.10)
                            if (!$agrupar && ($clave = Evaluator::claveDirecta($e)) !== null) {
                                foreach ($base as $f) {
                                    $c[] = $f[$clave] ?? null;
                                }
                                return $c;
                            }
                            foreach ($base as $x) {
                                Memoria::comprobar('las funciones de ventana');
                                $c[] = Evaluator::evaluar($e, $agrupar ? $x : ['fila' => $x] + $comun);
                            }
                            return $c;
                        },
                        static fn(array $e, int $k) => Evaluator::evaluar($e, $agrupar ? $base[$k] : ['fila' => $base[$k]] + $comun));
                } finally {
                    Memoria::dejarAviso($aviso);
                }
                foreach ($secuencia as $k) {
                    $v = [];
                    foreach ($valores as $i => $lista) {
                        $v[$i] = $lista[$k];
                    }
                    $ctx = $agrupar ? $base[$k] + ['ventanas' => $v] : ['fila' => $base[$k], 'ventanas' => $v] + $comun;
                    unset($base[$k]);
                    $fila = $proyectarCtx([], $ctx);
                    $resultado[] = $fila;
                    if ($orden !== []) {
                        SelectOrden::anotarClaves($clavesOrden, $orden, $ctx, $fila);
                    }
                }
            }
        } elseif ($agrupar) {
            // Cada grupo que pasa el HAVING, con sus agregados. Con muchos
            // grupos, si no caben, Memoria avisa y se repite por el disco
            // (ver correr()), y salen como las filas de una consulta sin
            // agrupar que no cupo (2.9: se juntaban siempre, y un GROUP BY
            // de un millón de grupos agotaba la memoria)
            $contextos = (static function () use ($filas, $grupoExprs, $defs, $sub, $conjunto, $externa, $having, $carpeta): \Generator {
                foreach (SelectAgrupada::agrupar($filas, $grupoExprs, $defs, $sub, $conjunto, $externa, $carpeta) as [$fila, $agregados]) {
                    $ctx = ['fila' => $fila, 'agregados' => $agregados, 'sub' => $sub,
                            'conjunto' => $conjunto, 'filaExterna' => $externa];
                    if ($having === null || Valor::verdadero(Evaluator::evaluar($having, $ctx)) === true) {
                        yield $ctx;
                    }
                }
            })();
            if ($this->enDisco) {
                SelectEnDisco::ordenarEnDisco($this, $contextos, $ast['distinct'] ? [] : null, $orden, $proyectarCtx, $ast, $resultado, $plegarSalida);
                $orden        = [];
                $recortado    = true;
                $sinRepetidas = true;
            } else {
                $aviso = Memoria::avisarAlApretar($particionar);
                try {
                    foreach ($contextos as $ctx) {
                        $fila = $proyectarCtx([], $ctx);
                        Memoria::comprobar('la construcción del resultado');
                        $resultado[] = $fila;
                        if ($orden !== []) {
                            SelectOrden::anotarClaves($clavesOrden, $orden, $ctx, $fila);
                        }
                    }
                } finally {
                    Memoria::dejarAviso($aviso);
                }
            }
        } else {
            // Una columna de salida que es una columna de la tabla se copia sin
            // pasar por el evaluador, que es lo corriente en un SELECT *
            $directas = [];
            foreach ($salida as $i => $c) {
                $directas[$i] = Evaluator::claveDirecta($c['expr']);
            }
            $proyectar = static function (array $origen, array $ctx) use ($salida, $directas): array {
                $fila = [];
                foreach ($salida as $i => $c) {
                    $fila[$c['nombre']] = $directas[$i] !== null
                        ? ($origen[$directas[$i]] ?? null)
                        : Evaluator::evaluar($c['expr'], $ctx);
                }
                return $fila;
            };
            // El contexto del evaluador (2.9): solo si alguna columna lo usa o
            // hay que ordenar; construirlo era un array más por fila
            $todasDirectas = !\in_array(null, $directas, true);
            $conCtx = !$todasDirectas || $orden !== [];
            // Si todas se copian de la fila (lo corriente, un SELECT *), cada
            // fila se arma en el bucle que la lee, sin llamar a $proyectar: la
            // llamada costaba tanto como armarla (2.10: SELECT id FROM t, un
            // 11 % menos). Nombre en la salida => clave en la fila
            $copiar = $todasDirectas ? array_combine(array_column($salida, 'nombre'), $directas) : null;

            // Con ORDER BY sobre columnas de la tabla se ordena antes de
            // proyectar: con LIMIT solo se construyen las filas que salen, y
            // solo esas viven en memoria; sin él la tabla y el resultado no
            // conviven enteros
            // Si no cupo por aquí, por el camino de abajo, que ordena en disco
            // si hace falta (ver correr())
            $clavesDirectas = $orden === [] || $ast['distinct'] || $this->enDisco
                ? null : $this->ordenDirecto($orden, $salida);
            if ($clavesDirectas !== null) {
                $cuantas = self::cuantasHacenFalta($ast);
                // Juntar la tabla (o muchas filas, con un OFFSET alto) para
                // ordenarla en memoria: si no cabe, Memoria avisa y se repite
                // ordenando en disco (ver correr())
                $aviso = Memoria::avisarAlApretar($cuantas === null || $cuantas > 1000);
                try {
                    if ($cuantas !== null) {
                        [$filas, $indices] = SelectOrden::primerasDe($filas, $clavesDirectas, $orden, $cuantas);
                        $vigilar = Memoria::cada();  // filas hasta mirar la memoria (ver Storage::filas())
                        foreach ($indices as $k) {
                            if (--$vigilar === 0) {
                                Memoria::revisar('la construcción del resultado');
                                $vigilar = Memoria::cada();
                            }
                            $resultado[] = $proyectar($filas[$k], ['fila' => $filas[$k], 'sub' => $sub,
                                                                    'conjunto' => $conjunto, 'filaExterna' => $externa]);
                            unset($filas[$k]);                       // cada fila de origen se usa una vez
                        }
                    } else {
                        // Cada fila se proyecta según se lee, no después de ordenar:
                        // para ordenar solo viven las columnas de la salida y las
                        // claves, y la tabla no llega a estar entera en memoria
                        // (2.10). Numeradas en el orden de lectura, que es el
                        // desempate: un generador puede repetir claves (yield from)
                        $proyectadas = [];
                        $clavesOrden = array_fill(0, \count($clavesDirectas), []);
                        $vigilar     = Memoria::cada();
                        $n           = 0;
                        foreach ($filas as $k => $fila) {
                            if ($copiar !== null) {
                                $proyectadas[$n] = [];
                                foreach ($copiar as $nombre => $clave) {
                                    $proyectadas[$n][$nombre] = $fila[$clave] ?? null;
                                }
                            } else {
                                $proyectadas[$n] = $proyectar($fila, ['fila' => $fila, 'sub' => $sub,
                                                                      'conjunto' => $conjunto, 'filaExterna' => $externa]);
                            }
                            foreach ($clavesDirectas as $i => $clave) {
                                $clavesOrden[$i][$n] = $fila[$clave] ?? null;
                            }
                            $n++;
                            if (--$vigilar === 0) {
                                Memoria::revisar('la construcción del resultado');
                                $vigilar = Memoria::cada();
                            }
                            if (\is_array($filas)) {
                                unset($filas[$k]);                   // la tabla no convive con lo proyectado
                            }
                        }
                        unset($filas);
                        $indices = SelectOrden::ordenNativo($orden, $clavesOrden, array_keys($proyectadas))
                                ?? SelectOrden::todasOrdenadas(array_keys($proyectadas), SelectOrden::comparadorDe($orden, $clavesOrden));
                        $clavesOrden = [];
                        foreach ($indices as $k) {
                            $resultado[] = $proyectadas[$k];
                            unset($proyectadas[$k]);
                        }
                    }
                } finally {
                    Memoria::dejarAviso($aviso);
                }
                unset($filas);
                $orden = [];                                 // ya está ordenado
            } elseif ($orden === [] && !$ast['distinct']) {
                // Sin ORDER BY ni DISTINCT, cada fila se proyecta según llega, sin
                // juntar antes la tabla: SELECT id FROM t tenía en memoria la
                // tabla entera y el resultado. Y el OFFSET se salta sin proyectar
                // y el LIMIT para la lectura: LIMIT 100 OFFSET 15000 construía
                // 15.100 filas para quedarse con 100
                $saltar = (int)($ast['offset'] ?? 0) - $this->saltadas;   // las saltadas al leer, ya (ver cargar())
                $quedan = $ast['limit'] === null ? -1 : (int)$ast['limit'];
                $this->saltadas = 0;
                $vigilar = Memoria::cada();          // filas hasta mirar la memoria (ver Storage::filas())
                foreach ($quedan === 0 ? [] : $filas as $origen) {
                    if ($saltar > 0) {
                        $saltar--;
                        continue;
                    }
                    if (--$vigilar === 0) {
                        Memoria::revisar('la construcción del resultado');
                        $vigilar = Memoria::cada();
                    }
                    if ($copiar !== null) {
                        $fila = [];
                        foreach ($copiar as $nombre => $clave) {
                            $fila[$nombre] = $origen[$clave] ?? null;
                        }
                    } else {
                        $fila = $proyectar($origen, ['fila' => $origen, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa]);
                    }
                    // La consulta de fuera, con sumidero: la fila sale ya, sin
                    // quedarse en el resultado (las subconsultas van más abajo)
                    if ($this->sumidero !== null && $this->nivel === $this->nivelSumidero) {
                        ($this->sumidero)($fila);
                        $this->entregado = true;
                    } else {
                        $resultado[] = $fila;
                    }
                    if (--$quedan === 0) {
                        break;
                    }
                }
                unset($filas, $origen);
                $recortado = true;
            } else {
                // Con DISTINCT, una fila repetida se descarta según se proyecta (2.9):
                // antes se juntaban la tabla entera y todas sus filas proyectadas
                // para quitar las repetidas al final, y SELECT DISTINCT ciudad
                // tenía 46 MB en memoria para devolver cinco
                $vistos = $ast['distinct'] ? [] : null;
                if ($this->enDisco) {
                    // Por el disco, también sin ORDER BY: con DISTINCT, lo que no
                    // se repite sale según llega, sin juntar el resultado (2.9)
                    $contextos = (static function () use ($filas, $sub, $conjunto, $externa): \Generator {
                        foreach ($filas as $origen) {
                            yield ['fila' => $origen, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa];
                        }
                    })();
                    SelectEnDisco::ordenarEnDisco($this, $contextos, $vistos, $orden, $proyectar, $ast, $resultado, $plegarSalida);
                    $orden = [];
                    $recortado = true;
                } else {
                    // El camino de siempre. Si lo juntado no cabe, Memoria avisa y
                    // se repite por el disco (ver correr())
                    $aviso = Memoria::avisarAlApretar(true);
                    try {
                        if ($vistos === null) {
                            if (!\is_array($filas)) {
                                $filas = iterator_to_array($filas, false);
                            }
                            // Se va soltando cada fila de origen según se proyecta. Si no, la
                            // tabla leída y el resultado conviven enteros hasta el final del
                            // bucle, o sea dos copias de lo mismo en el pico.
                            $vigilar = Memoria::cada();
                            foreach (array_keys($filas) as $k) {
                                if (--$vigilar === 0) {
                                    Memoria::revisar('la construcción del resultado');
                                    $vigilar = Memoria::cada();
                                }
                                $ctx  = ['fila' => $filas[$k], 'sub' => $sub, 'conjunto' => $conjunto,
                                         'filaExterna' => $externa];
                                $fila = $proyectar($filas[$k], $ctx);
                                unset($filas[$k]);
                                $resultado[] = $fila;
                                // Sin ORDER BY no hay nada que ordenar: construir las claves
                                // sería un array más por fila para tirarlo enseguida
                                if ($orden !== []) {
                                    SelectOrden::anotarClaves($clavesOrden, $orden, $ctx, $fila);
                                }
                            }
                        } else {
                            $vigilar = Memoria::cada();
                            foreach ($filas as $origen) {     // según llegan, sin juntar la tabla
                                if (--$vigilar === 0) {
                                    Memoria::revisar('la construcción del resultado');
                                    $vigilar = Memoria::cada();
                                }
                                $ctx  = $conCtx ? ['fila' => $origen, 'sub' => $sub, 'conjunto' => $conjunto, 'filaExterna' => $externa] : [];
                                $fila = $proyectar($origen, $ctx);
                                $clave = '';
                                if ($plegarSalida === []) {
                                    foreach ($fila as $v) {
                                        $clave .= Valor::clave($v) . "\0";
                                    }
                                } else {                        // con COLLATE (2.9)
                                    foreach ($fila as $c => $v) {
                                        $clave .= Valor::clave(isset($plegarSalida[$c]) ? Functions::plegar($v, $plegarSalida[$c]) : $v) . "\0";
                                    }
                                }
                                if (isset($vistos[$clave])) {
                                    continue;
                                }
                                $vistos[$clave] = true;
                                $resultado[] = $fila;
                                if ($orden !== []) {
                                    SelectOrden::anotarClaves($clavesOrden, $orden, $ctx, $fila);
                                }
                            }
                        }
                    } finally {
                        Memoria::dejarAviso($aviso);
                    }
                }
                $sinRepetidas = $vistos !== null;
                unset($filas, $vistos);
            }
        }

        // DISTINCT (la agrupación, que lo deja para aquí)
        if ($ast['distinct'] && empty($sinRepetidas)) {
            $vistos = [];
            $r = [];
            $k = array_fill(0, \count($orden), []);
            foreach ($resultado as $i => $fila) {
                $clave = '';
                if ($plegarSalida === []) {
                    foreach ($fila as $v) {
                        $clave .= Valor::clave($v) . "\0";
                    }
                } else {                        // con COLLATE (2.9)
                    foreach ($fila as $c => $v) {
                        $clave .= Valor::clave(isset($plegarSalida[$c]) ? Functions::plegar($v, $plegarSalida[$c]) : $v) . "\0";
                    }
                }
                if (isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;
                $r[] = $fila;
                foreach ($k as $j => $_) {
                    $k[$j][] = $clavesOrden[$j][$i];
                }
            }
            $resultado   = $r;
            $clavesOrden = $k;
        }

        // ORDER BY
        if ($orden !== []) {
            $ordenadas = [];
            foreach (SelectOrden::indicesOrdenados($ast, $orden, $clavesOrden, array_keys($resultado)) as $i) {
                $ordenadas[] = $resultado[$i];
            }
            $resultado = $ordenadas;
        }

        // LIMIT / OFFSET (si no se aplicaron ya al leer)
        if (empty($recortado) && ($ast['limit'] !== null || $ast['offset'] !== null)) {
            $resultado = \array_slice($resultado, $ast['offset'] ?? 0, $ast['limit']);
        }

        $cols = [];
        foreach ($salida as $c) {
            $cols[] = $c['nombre'];
        }
        return ['cols' => $cols, 'filas' => $resultado, 'colaciones' => $colaciones, 'afinidades' => $afinidades];
    }

    /**
     * Un término del ORDER BY que es un nombre suelto (con COLLATE detrás o
     * sin él) y el de un alias escrito con AS es ese alias, aunque haya una
     * columna que se llame igual, como en SQLite, MySQL y PostgreSQL (2.9:
     * mandaba la columna, y SELECT -saldo AS saldo … ORDER BY saldo ordenaba
     * al revés). Dentro de una expresión (saldo + 0) manda la columna, como en
     * SQLite. Null si no es eso: se resuelve como siempre.
     *
     * @param array<string, string> $escritos alias en minúsculas => nombre de la columna de salida
     */
    private static function aliasEscrito(array $e, array $escritos): ?array
    {
        if ($e['k'] === 'col') {
            return $e['tabla'] === null && isset($escritos[strtolower($e['nombre'])])
                ? $e + ['alias' => $escritos[strtolower($e['nombre'])]] : null;
        }
        if ($e['k'] !== 'fn' || ($e['nombre'] !== 'COLLATE' && $e['nombre'] !== 'PLEGAR')
            || ($dentro = self::aliasEscrito($e['args'][0], $escritos)) === null) {
            return null;
        }
        $e['args'][0] = $dentro;                 // el otro argumento es la colación, un literal
        return $e;
    }

    /**
     * Claves de ordenación que son columnas de la tabla —directamente o a
     * través de un alias de salida que lo es—, o null si alguna es otra cosa.
     *
     * @return list<string>|null
     */
    private function ordenDirecto(array $orden, array $salida): ?array
    {
        $porAlias = [];
        foreach ($salida as $c) {
            $porAlias[$c['nombre']] = $c['expr'];
        }
        $claves = [];
        foreach ($orden as $o) {
            $e = $o['expr'];
            if ($e['k'] === 'col' && isset($e['alias'])) {
                $e = $porAlias[$e['alias']] ?? $e;
            }
            if (($clave = Evaluator::claveDirecta($e)) === null) {
                return null;
            }
            $claves[] = $clave;
        }
        return $claves;
    }

    /**
     * Cuántas filas hay que dejar ordenadas para responder, o null si todas.
     *
     * Con `LIMIT 10 OFFSET 5` hacen falta las 15 primeras: el OFFSET se aplica
     * después sobre ellas.
     */
    protected static function cuantasHacenFalta(array $ast): ?int
    {
        if ($ast['limit'] === null) {
            return null;                          // sin LIMIT hay que ordenarlo todo
        }
        $limite = (int)$ast['limit'];
        $salto  = (int)($ast['offset'] ?? 0);
        // Un LIMIT enorme (el de un ORM para «sin límite») más el OFFSET no cabe
        // en un entero: es como no tener límite (2.9: TypeError)
        if ($limite < 0 || $salto < 0 || $limite > PHP_INT_MAX - $salto) {
            return null;
        }
        return $limite + $salto;
    }

    /**
     * COUNT(*) con WHERE col = 'texto' [AND …] que un índice cubre entero: las
     * filas que cumplen son exactamente las posiciones que el índice tiene
     * para esas claves, y contarlas no exige leer ninguna fila. Antes se
     * leían todas las que cumplían (un 10 % de la tabla, 18 ms con 20.000
     * filas) solo para contarlas. Desde la 2.10 un texto tiene su clave de
     * texto y cada valor se busca con la afinidad de su columna, así que la
     * misma clave es el mismo valor también con textos que son números (hasta
     * la 2.9 '7' y '007' compartían clave, y solo se contaban así textos que no
     * lo eran o números en columnas numéricas). Null si no se puede: lo
     * cuenta el camino normal.
     */
    private function contarPorIndice(string $tabla, string $alias, array $where): ?int
    {
        $st = $this->cat->storage();
        if (!$st->indicesActivos()) {
            return null;
        }
        $predicados = IndexesBusqueda::predicados($where, strtolower($alias))[strtolower($alias)] ?? [];
        if ($predicados === [] || \count(IndexesBusqueda::conjunciones($where)) !== \count($predicados)) {
            return $this->contarPorComparacion($tabla, $alias, $where);
        }
        // Cada valor va con la afinidad de su columna (2.10, ver
        // IndexesBusqueda::candidatos()), y su clave es la de los que son
        // iguales: los textos, byte a byte; los números, por su valor
        foreach ($predicados as $valores) {
            foreach ($valores as $v) {
                if (!(\is_int($v) || \is_float($v) || \is_string($v)) || !IndexesBusqueda::claveFiable($v)) {
                    return null;
                }
            }
        }
        foreach (IndexesBusqueda::candidatos($this->cat->indicesDe($tabla), $predicados) as $c) {
            if ($c['prefijo'] || \count($c['def']['columns']) !== \count($predicados)) {
                continue;                            // tiene que cubrirlas todas y solo esas
            }
            $n = StorageLectura::contarPorIndice($st, $tabla, $c['def'], $c['claves']);
            if ($n !== null) {
                return $n;
            }
        }
        return null;
    }

    /**
     * COUNT(*) con un WHERE que solo compara una columna con literales (rangos,
     * BETWEEN, igualdades numéricas), contando en los trozos de un índice
     * sobre esa columna sin leer la tabla (2.9).
     */
    private function contarPorComparacion(string $tabla, string $alias, array $where): ?int
    {
        [$comparaciones, $cubiertas] = IndexesBusqueda::comparaciones($where, strtolower($alias));
        $cols = $comparaciones[strtolower($alias)] ?? [];
        if (\count($cols) !== 1 || $cubiertas !== \count(IndexesBusqueda::conjunciones($where))) {
            return null;                             // otra columna u otra condición
        }
        // Solo igualdades: el camino de siempre las busca en el texto de los
        // trozos sin decodificarlos, y es más rápido que esto
        if (array_filter(current($cols), static fn(array $c): bool => $c[0] !== '=') === []) {
            return null;
        }
        foreach ($this->cat->indicesDe($tabla) as $def) {
            if (strtolower((string)$def['columns'][0]) === key($cols) && Indexes::sirve($def, 1)) {
                $n = StorageRangos::posicionesPorComparacion($this->cat->storage(), $tabla, $def, current($cols), true);
                if (\is_int($n)) {
                    return $n;
                }
            }
        }
        return null;
    }

    /**
     * La tabla de un SELECT sobre una sola tabla de verdad (ni vista, ni CTE,
     * ni subconsulta), sin GROUP BY, HAVING ni DISTINCT, que es lo que piden
     * los atajos que leen del índice o del recuento (contarRapido,
     * extremosRapidos, SelectOrden::ordenPorIndice); null si no. Con un lector propio (un
     * trigger en mitad de una escritura) las filas buenas están en memoria,
     * no en disco, y tampoco.
     */
    protected function tablaDirecta(array $ast): ?array
    {
        if (!$this->indexable || ($ast['k'] ?? 'select') !== 'select' || \count($ast['from']) !== 1
            || $ast['group'] !== null || $ast['having'] !== null || $ast['distinct']) {
            return null;
        }
        $o = $ast['from'][0];
        return $o['tipo'] === 'tabla' && !isset($this->con[strtolower($o['nombre'])])
            && !$this->cat->esVista($o['nombre']) && $this->cat->existe($o['nombre']) ? $o : null;
    }

    /**
     * SELECT COUNT(*) FROM tabla, sin nada más: el resultado es el número de
     * filas, que Storage sabe contar sin decodificar ni materializar la tabla.
     * Con un WHERE que un índice cubre entero, contado en el índice: el de
     * igualdades con textos (ver contarPorIndice()) o, desde la 2.9, el que
     * compara una sola columna (ver contarPorComparacion()). En cuanto la consulta
     * tiene cualquier otra cosa —otro WHERE, GROUP BY, JOIN, DISTINCT, más
     * columnas, una vista o una CTE— se sigue el camino normal, que es quien
     * sabe hacerla bien.
     */
    private function contarRapido(array $ast): ?array
    {
        $o = $this->tablaDirecta($ast);
        if ($o === null || $ast['order'] !== [] || $ast['limit'] !== null || $ast['offset'] !== null || \count($ast['cols']) !== 1) {
            return null;
        }
        $c = $ast['cols'][0];
        $e = $c['expr'] ?? null;
        if (($c['star'] ?? false) || !\is_array($e) || ($e['k'] ?? '') !== 'fn'
            || strtoupper((string)$e['nombre']) !== 'COUNT' || !($e['star'] ?? false) || isset($e['filtro'])) {
            return null;
        }
        $n = $ast['where'] === null
            ? StorageLectura::contarFilas($this->cat->storage(), $o['nombre'])
            : $this->contarPorIndice($o['nombre'], $o['alias'] ?? $o['nombre'], $ast['where']);
        if ($n === null) {
            return null;
        }
        if ($this->cat->storage()->traza !== null) {
            SelectAvanzada::apuntar($this, $o['nombre'], $ast['where'] === null ? 'recuento, sin leer filas' : 'recuento en el índice, sin leer filas', null, $n);
        }
        $nombre = $c['alias'] ?? self::etiqueta($e);
        return ['cols' => [$nombre], 'filas' => [[$nombre => $n]]];
    }

    /**
     * SELECT MIN(col), MAX(col) … de una tabla, sin WHERE: de los rangos que
     * el estado de un índice numérico sobre esa columna ya guarda por trozo,
     * sin leer la tabla. En 100.000 filas, de ~190 ms a menos de 1 ms. Vale
     * para columnas INTEGER, DOUBLE y DECIMAL, con un índice cuya primera
     * columna sea esa (la clave primaria lo es de la suya).
     */
    private function extremosRapidos(array $ast): ?array
    {
        $o = $this->tablaDirecta($ast);
        if ($o === null || $ast['where'] !== null || $ast['order'] !== [] || $ast['limit'] !== null || $ast['offset'] !== null) {
            return null;
        }
        $tabla  = $o['nombre'];
        $alias  = strtolower((string)($o['alias'] ?? $tabla));
        $meta   = $this->cat->meta($tabla);
        $cols   = [];
        $fila   = [];
        $memo   = [];
        foreach ($ast['cols'] as $c) {
            $e = $c['expr'] ?? null;
            $f = \is_array($e) && ($e['k'] ?? '') === 'fn' ? strtoupper((string)$e['nombre']) : '';
            $a = $e['args'][0] ?? null;
            if (($c['star'] ?? false) || ($f !== 'MIN' && $f !== 'MAX') || \count($e['args'] ?? []) !== 1 || isset($e['filtro'])
                || ($a['k'] ?? '') !== 'col' || ($a['tabla'] !== null && strtolower((string)$a['tabla']) !== $alias)) {
                return null;
            }
            $col = Catalog::columna($meta, (string)$a['nombre']);
            if ($col === null || !\in_array($col['type'], [Types::INTEGER, Types::DOUBLE, Types::DECIMAL], true)) {
                return null;
            }
            $clave = strtolower($col['name']);
            if (!\array_key_exists($clave, $memo)) {
                $memo[$clave] = null;
                foreach ($this->cat->indicesDe($tabla) as $def) {
                    if (strcasecmp((string)$def['columns'][0], $col['name']) === 0 && Indexes::sirve($def, 1)) {
                        $memo[$clave] = StorageRangos::extremosIndice($this->cat->storage(), $tabla, $def);
                        if ($memo[$clave] !== null) {
                            if ($this->cat->storage()->traza !== null) {
                                SelectAvanzada::apuntar($this, $tabla, 'menor y mayor del índice, sin leer filas', $def['name'], null);
                            }
                            break;
                        }
                    }
                }
            }
            if ($memo[$clave] === null) {
                return null;
            }
            $v = $memo[$clave][$f === 'MIN' ? 'min' : 'max'];
            $nombre = (string)($c['alias'] ?? self::etiqueta($e));
            if (\in_array($nombre, $cols, true)) {
                return null;                           // nombres repetidos: los renombra el camino normal
            }
            $cols[] = $nombre;
            $fila[$nombre] = $v === null ? null : Types::cast($v, $col);
        }
        return $cols === [] ? null : ['cols' => $cols, 'filas' => [$fila]];
    }

    /**
     * Cuántas filas hacen falta como mucho, o null si hay que recorrerlo todo.
     *
     * Con ORDER BY, GROUP BY, agregados o DISTINCT, la fila número 5.000 puede
     * cambiar el resultado, así que no se puede cortar antes de tiempo.
     */
    private function topeTemprano(array $ast): ?int
    {
        if ($ast['limit'] === null || $ast['order'] !== [] || $ast['distinct'] || !empty($ast['ventanas'])) {
            return null;
        }
        if (($ast['group'] ?? []) !== [] || ($ast['having'] ?? null) !== null) {
            return null;
        }
        foreach ($ast['cols'] as $c) {
            if (($c['star'] ?? false) === false && Evaluator::tieneAgregado($c['expr'])) {
                return null;
            }
        }
        return self::cuantasHacenFalta($ast);
    }

    /**
     * `col [NOT] BETWEEN a AND b` con a y b literales numéricos: [clave, a, b,
     * not], o null si no es eso. Con un valor NULL o incomparable el resultado
     * es desconocido, y no cumple ni con NOT, como en el evaluador general.
     */
    private static function rangoNumerico(array $n): ?array
    {
        if ($n['k'] !== 'between' || ($n['e']['k'] ?? '') !== 'col' || !isset($n['e']['clave'])
            || isset($n['e']['externa']) || ($n['min']['k'] ?? '') !== 'lit' || ($n['max']['k'] ?? '') !== 'lit') {
            return null;
        }
        $a = $n['min']['v'];
        $b = $n['max']['v'];
        // Pasado 2^53, >= y <= no son exactos entre un entero y un decimal: al
        // camino general (2.10, ver Valor::numeros())
        if (!(\is_int($a) || \is_float($a)) || !(\is_int($b) || \is_float($b)) || abs($a) >= 9007199254740992 || abs($b) >= 9007199254740992) {
            return null;
        }
        return [$n['e']['clave'], $a, $b, (bool)$n['not']];
    }

    /**
     * Intenta resolver la lectura de una tabla por índice.
     *
     * Devuelve null cuando no hay índice que sirva, cuando el que hay no está al
     * día o cuando haría falta leer casi toda la tabla igualmente: en todos esos
     * casos la lectura sigue por el camino de siempre.
     *
     * @param array<string, list<mixed>> $predicados
     */
    private function porIndice(string $tabla, array $predicados): ?array
    {
        if (!$this->indexable || $predicados === []) {
            return null;
        }
        $st = $this->cat->storage();
        if (!$st->indicesActivos()) {
            return null;
        }
        // Del mejor al peor: si uno no compensa (habría que leer media tabla), el siguiente
        foreach (IndexesBusqueda::candidatos($this->cat->indicesDe($tabla), $predicados) as $c) {
            // Si el índice cubre todo el WHERE, las primeras LIMIT filas que da son
            // las de la consulta: no hace falta sacar las demás posiciones (2.9: con
            // 100.000 filas de una ciudad y LIMIT 50 se leían las 100.000 y luego,
            // como eran demasiadas, se recorría la tabla)
            // Con uno que solo sirve en parte salen también filas que no cumplen: sin tope
            $tope = !$c['parcial'] && $this->topeIndice !== null && \count($this->topeIndice[1]) === $c['n']
                && array_diff($this->topeIndice[1], array_map('strtolower', \array_slice($c['def']['columns'], 0, $c['n']))) === []
                ? $this->topeIndice[0] : null;
            $filas = StorageLectura::filasPorIndice($st, $tabla, $c['def'], $c['claves'], $c['prefijo'], $tope, $c['parcial']);
            if ($filas !== null) {
                $this->via = ['índice (' . ($c['parcial'] ? 'parte de la clave, en las partes sin NULL' : ($c['prefijo'] ? 'parte de la clave' : (\count($c['claves']) > 1 ? 'IN' : '='))) . ')', $c['def']['name']];
                return $filas;
            }
        }
        return null;
    }

    /**
     * Las filas de las partes que pueden contener algún valor de un rango
     * numérico sobre la primera columna de un índice (ver Storage::filasPorRango()).
     *
     * @param array<string, array{0: int|float|null, 1: int|float|null}> $rangos columna => [min, max]
     */
    private function porRango(string $tabla, array $rangos, string $alias): ?iterable
    {
        if (!$this->indexable || $this->whereRangos === null) {
            return null;
        }
        $st = $this->cat->storage();
        if (!$st->indicesActivos()) {
            return null;
        }
        $defs = array_filter($this->cat->indicesDe($tabla), static fn(array $d): bool => Indexes::sirve($d, 1));
        foreach ($defs as $def) {
            // Un rango de números no sirve en una columna de texto (2.10): ahí
            // 5 es '5', y '10' < '5' (ver la comparación de abajo)
            $r = ($def['afin'][0] ?? null) === 'T' ? null : $rangos[strtolower($def['columns'][0])] ?? null;
            if ($r !== null && ($r[0] !== null || $r[1] !== null)) {
                $filas = $st->filasPorRango($tabla, $def, $r[0], $r[1]);
                if ($filas !== null) {
                    $this->via = ['índice, rango', $def['name']];
                    return $filas;
                }
            }
        }
        // Si el rango de cada trozo no basta (textos y fechas, que no lo
        // tienen, o valores repartidos por toda la tabla): las posiciones que
        // cumplen, buscadas en el índice, y solo sus partes (2.9)
        $comparaciones = null;
        foreach ($defs as $def) {
            $comparaciones ??= IndexesBusqueda::comparaciones($this->whereRangos[0], $this->whereRangos[1])[0][$alias] ?? [];
            $comps = $comparaciones[strtolower($def['columns'][0])] ?? [];
            if ($comps !== []) {
                $filas = StorageRangos::filasPorComparacion($st, $tabla, $def, $comps);
                if ($filas !== null) {
                    $this->via = ['índice, comparación', $def['name']];
                    return $filas;
                }
            }
        }
        return null;
    }

    /**
     * Lo de fuera de una consulta visto desde sus subconsultas, un nivel más
     * lejos (2.10): cada clave, con un "\1" más delante, para que no choque
     * con las de la consulta que queda en medio (las columnas se guardan sin
     * la tabla delante cuando hay una sola: «id» puede estar en las dos), y la
     * colación y la afinidad de cada una ("\0c:" y "\0a:" y la clave) con ella.
     *
     * @param array{mapa: array, fila: array} $externo
     * @return array{mapa: array, fila: array}
     */
    protected static function masFuera(array $externo): array
    {
        $mapa = [];
        foreach ($externo['mapa'] as $nombre => $clave) {
            if (str_starts_with((string)$nombre, "\0c:") || str_starts_with((string)$nombre, "\0a:")) {
                $mapa[substr((string)$nombre, 0, 3) . "\1" . substr((string)$nombre, 3)] = $clave;
            } else {
                $mapa[$nombre] = \is_string($clave) ? "\1" . $clave : $clave;
            }
        }
        $fila = [];
        foreach ($externo['fila'] as $clave => $v) {
            $fila["\1" . $clave] = $v;
        }
        return ['mapa' => $mapa, 'fila' => $fila];
    }

    /**
     * Guarda lo que dio una subconsulta correlacionada (sus filas o su
     * conjunto, ver correrSimple()) para la fila de fuera que lo dio. Es una
     * caché: entre todas, como mucho CORR_MAX, y si la memoria aprieta, se
     * vacía (2.9: crecía con cada fila de fuera distinta, sin que lo mirase
     * Memoria, y una subconsulta escalar sobre un millón de filas guardaba un
     * millón de resultados).
     *
     * @param array<int, array<string, array>> $cache $subsCorr o $conjuntosCorr
     */
    private function guardarCorr(array &$cache, int $sid, string $claveFila, array $valor): void
    {
        if ($this->corrGuardados >= self::CORR_MAX || Memoria::apretado()) {
            $this->subsCorr = $this->conjuntosCorr = [];
            $this->corrGuardados = 0;
        }
        $cache[$sid][$claveFila] = $valor;
        $this->corrGuardados++;
    }

    /**
     * Clave comparable de una fila completa, para deduplicar y cruzar.
     *
     * @param array<int, string> $plegar la colación de las columnas que la tienen, por posición (2.9)
     */
    protected static function claveFila(array $fila, array $plegar = []): string
    {
        $k = '';
        if ($plegar === []) {
            foreach ($fila as $v) {
                $k .= Valor::clave($v) . "\0";
            }
            return $k;
        }
        $i = 0;
        foreach ($fila as $v) {
            $k .= Valor::clave(isset($plegar[$i]) ? Functions::plegar($v, $plegar[$i]) : $v) . "\0";
            $i++;
        }
        return $k;
    }

    // ==================================================================
    // Orígenes de datos y JOIN
    // ==================================================================

    /**
     * Las filas del FROM y sus columnas. Cada columna es [alias, nombre,
     * clave], donde la clave es con la que está en la fila: el nombre a secas
     * si hay un solo origen, y `alias.nombre` si hay varios, para que dos
     * tablas con una columna igual no se pisen.
     *
     * @return array{0: iterable<array>, 1: list<array{0: string, 1: string, 2: string}>}
     */
    private function origenes(array $ast, ?int $tope = null): array
    {
        $from  = $ast['where'] === null || \count($ast['from']) < 2 ? $ast['from'] : SelectJoinIndice::comasAJoin($ast['from'], $ast['where']);
        $where = $ast['where'];
        if ($from === []) {
            return [[[]], []];                            // SELECT sin FROM: una fila vacía
        }

        // Con un solo origen, una columna sin cualificar solo puede ser suya.
        // Con varios haría falta el mapa de columnas, y para eso ya habría que
        // haber leído las tablas, que es justo lo que se quiere evitar.
        $unico = \count($from) === 1 && $from[0]['tipo'] === 'tabla'
            ? strtolower((string)($from[0]['alias'] ?? $from[0]['nombre']))
            : null;
        $predicados = $this->indexable ? IndexesBusqueda::predicados($where, $unico) : [];
        $rangos     = $this->indexable ? IndexesBusqueda::rangos($where, $unico) : [];
        // Las comparaciones exactas (también con textos) solo se calculan si
        // llega a hacer falta buscarlas en un índice (ver porRango())
        $whereAntes = $this->whereRangos;
        $this->whereRangos = $this->indexable && $where !== null ? [$where, $unico] : null;

        // El tope solo se puede aplicar al leer si no hay nada que filtrar
        // después: con WHERE o con JOIN, las filas que sobran no son las
        // últimas, y quedarse con las primeras daría un resultado distinto.
        $topeLectura = $where === null && \count($from) === 1 ? $tope : null;
        $this->saltoPedido = $topeLectura === null ? 0 : (int)($ast['offset'] ?? 0);
        $igualdades = $tope !== null && \count($from) === 1 ? IndexesBusqueda::soloIgualdades($where, $unico) : null;
        $this->topeIndice = $igualdades === null ? null : [$tope, $igualdades];

        // El primer origen se recorre sin materializarlo: con un solo origen,
        // el WHERE va descartando filas según llegan y en memoria solo quedan
        // las que pasan. Los demás son el lado interno del cruce y hacen falta
        // enteros.
        $varios  = \count($from) > 1;
        $usadas  = $varios ? $this->columnasUsadas($ast) : null;
        $this->porOrden = $ast['limit'] !== null && \count($ast['order']) === 1 ? SelectOrden::ordenPorIndice($this, $ast) : null;
        try {
            $primero = $this->cargar($from[0], $predicados, $topeLectura, $varios, $usadas, $rangos);
        } finally {
            $this->porOrden    = null;
            $this->saltoPedido = 0;
            $this->topeIndice  = null;
        }
        $filas   = $primero['filas'];
        $fuentes = $primero['fuentes'];

        $derechas = false;
        foreach ($from as $o) {
            $derechas = $derechas || \in_array($o['join'] ?? '', ['RIGHT', 'FULL'], true);
        }
        for ($i = 1, $n = \count($from); $i < $n; $i++) {
            if (!empty($from[$i]['natural']) || isset($from[$i]['using'])) {
                $from[$i]['on'] = SelectJoin::onDeUsing($this, $from[$i], $fuentes);
            }
            // Lo que el WHERE dice solo de las tablas que ya están en el
            // cruce se aplica antes de cruzar: cada fila que cae aquí es una
            // fila menos que buscar o casar. Con INNER y LEFT no cambia nada
            // del resultado; con RIGHT y FULL sí podría (una fila descartada
            // deja huérfanas a las de la otra tabla), y no se hace.
            // Tampoco si hay uno en cualquier punto de la cadena: un RIGHT JOIN
            // posterior rellena con NULL las filas que se quedan sin pareja, y
            // haber quitado antes filas de la izquierda cambia cuáles son
            if ($where !== null && !$derechas) {
                $filas = $this->empujarWhere($filas, $fuentes, $where);
            }
            // Con pocas filas a la izquierda y un índice en la tabla de la
            // derecha sobre las columnas del ON, se busca cada fila por su
            // clave en vez de cargar la tabla entera para cruzarla. Si a la
            // izquierda está una tabla entera, sin WHERE, se sabe cuántas filas
            // son sin leerlas: si son muchas, ni se intenta
            $izqConocida = $i === 1 && $where === null && $from[0]['tipo'] === 'tabla'
                && !isset($this->con[strtolower($from[0]['nombre'])])
                && $this->cat->existe($from[0]['nombre'])
                ? StorageLectura::filasSegunRevision($this->cat->storage(), $from[0]['nombre']) : null;
            $cruce = SelectJoinIndice::unirPorIndice($this, $filas, $fuentes, $from[$i], $usadas, $izqConocida);
            if ($cruce !== null) {
                [$filas, $fuentesDer] = $cruce;
            } else {
                $der = $this->cargar($from[$i], $predicados, null, true, $usadas, $rangos);
                if ($this->enDisco && \in_array($from[$i]['join'] ?? '', ['INNER', 'LEFT'], true) && $from[$i]['on'] !== null) {
                    // El lado interno no cupo: por particiones en disco (2.9)
                    $filas = SelectJoin::unirEnDisco($this, $filas, $fuentes, $der, $from[$i]);
                } else {
                    // Entero en memoria; si no cabe, Memoria avisa y se repite
                    // por el disco (ver correr())
                    $aviso = Memoria::avisarAlApretar(true);
                    try {
                        $der['filas'] = iterator_to_array($der['filas'], false);
                    } finally {
                        Memoria::dejarAviso($aviso);
                    }
                    $filas = SelectJoin::unir($this, $filas, $fuentes, $der, $from[$i]);
                }
                $fuentesDer = $der['fuentes'];
            }
            $fuentes = array_merge($fuentes, $fuentesDer);
            if (!empty($from[$i]['usingCols'])) {
                $fuentes = SelectJoin::ocultarUsing($fuentes, $from[$i]);   // USING y NATURAL
            }
        }
        $this->whereRangos = $whereAntes;
        return [$filas, $fuentes];
    }

    /**
     * Aplica a las filas las condiciones del WHERE que solo miran columnas
     * que ya tienen: las conjunciones de primer nivel que se resuelven con
     * estas fuentes y se pueden compilar. Las demás las aplica el WHERE
     * entero, después, como siempre (este también vuelve a pasar por ellas:
     * cuesta nada comparado con lo que ahorra).
     *
     * @param iterable<array> $filas
     * @param list<array{0: string, 1: string, 2: string}> $fuentes
     */
    private function empujarWhere(iterable $filas, array $fuentes, array $where): iterable
    {
        $mapa       = $this->mapaColumnas($fuentes);
        $compiladas = [];
        foreach (IndexesBusqueda::conjunciones($where) as $c) {
            try {
                $r = Evaluator::resolver($c, $mapa, [], []);
            } catch (JsonSqlDbError) {
                continue;                             // nombra columnas que aún no están
            }
            $f = EvaluatorCompilado::compilar($r);
            if ($f !== null) {
                $compiladas[] = $f;
            }
        }
        if ($compiladas === []) {
            return $filas;
        }
        return (static function () use ($filas, $compiladas) {
            foreach ($filas as $fila) {
                foreach ($compiladas as $f) {
                    if (Valor::verdadero($f($fila)) !== true) {
                        continue 2;
                    }
                }
                yield $fila;
            }
        })();
    }

    /**
     * Catalog::colaciones() y Catalog::afinidades() en una vuelta (2.10): las
     * dos piden las fuentes de cada tabla de una consulta. Aquí, que solo lo
     * usan las consultas: en Catalog lo compilaba también cada escritura
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private static function colacionesYAfinidades(array $meta): array
    {
        $colaciones = $afinidades = [];
        foreach ($meta['columns'] as $c) {
            $minuscula = strtolower((string)$c['name']);
            if (isset($c['collate'])) {
                $colaciones[$minuscula] = $c['collate'];
            }
            $afinidades[$minuscula] = $c['type'] === Types::TEXT ? 'T' : 'N';
        }
        return [$colaciones, $afinidades];
    }

    /**
     * Columnas de una tabla del FROM y sus fuentes con prefijo de alias,
     * quedándose solo con las que usa la consulta (ver columnasUsadas()).
     *
     * @return array{0: list<string>, 1: list<array{0: string, 1: string, 2: string}>}
     */
    protected function fuentesDeTabla(string $nombre, string $alias, ?array $usadas): array
    {
        $cols = [];
        foreach ($this->cat->meta($nombre)['columns'] as $c) {
            $cols[] = $c['name'];
        }
        if ($usadas !== null && !isset($usadas[2][strtolower($alias)])) {
            [$porAlias, $sueltas] = $usadas;
            $suyas = $porAlias[strtolower($alias)] ?? [];
            $cols  = array_values(array_filter($cols,
                static fn(string $c): bool => isset($suyas[strtolower($c)]) || isset($sueltas[strtolower($c)])));
        }
        $fuentes    = [];
        [$colaciones, $afinidades] = self::colacionesYAfinidades($this->cat->meta($nombre));
        foreach ($cols as $c) {
            $fuentes[] = [$alias, $c, $alias . '.' . $c, null, $colaciones[strtolower($c)] ?? null, $afinidades[strtolower($c)] ?? null];
        }
        return [$cols, $fuentes];
    }

    /**
     * Columnas que nombra la consulta, por alias (en minúsculas) y sin
     * cualificar, para cargar de cada origen de un cruce solo las que se
     * usan. Se recorre el árbol entero, subconsultas incluidas: de más nunca
     * hace daño. Null si hay un `*` sin tabla, que las quiere todas.
     *
     * Las de un JOIN … USING y un NATURAL JOIN no se nombran, pero su ON las
     * iguala (2.9: se descartaban antes de montarlo, y SELECT a.id, b.v FROM a
     * JOIN b USING (k) decía que k no estaba a los dos lados): van sin
     * cualificar, las de USING y, con NATURAL, todas las de su tabla, que son
     * las que puede tener en común con las de antes.
     *
     * @return array{0: array<string,array<string,true>>, 1: array<string,true>, 2: array<string,true>}|null
     *         [por alias, sin cualificar, alias con `alias.*`]
     */
    private function columnasUsadas(array $ast): ?array
    {
        $porAlias = [];
        $sueltas  = [];
        $todas    = [];
        if (!self::recorrerColumnas($ast, $porAlias, $sueltas, $todas)) {
            return null;
        }
        foreach ($ast['from'] as $o) {
            foreach ($o['using'] ?? [] as $c) {
                $sueltas[strtolower((string)$c)] = true;
            }
            if (!empty($o['natural']) && $o['tipo'] === 'tabla' && !isset($this->con[strtolower((string)$o['nombre'])])
                && !$this->cat->esVista((string)$o['nombre']) && $this->cat->existe((string)$o['nombre'])) {
                foreach ($this->cat->meta((string)$o['nombre'])['columns'] as $c) {
                    $sueltas[strtolower((string)$c['name'])] = true;
                }
            }
        }
        return [$porAlias, $sueltas, $todas];
    }

    /**
     * Las claves de la fila que leen unas expresiones ya resueltas (2.10), o
     * null si alguna lleva una subconsulta, que puede leer cualquier columna
     * de la fila de fuera.
     *
     * @return array<string, true>|null
     */
    private static function clavesUsadas(array $n, array $claves = []): ?array
    {
        if (($n['k'] ?? null) === 'sub' || ($n['k'] ?? null) === 'exists' || \is_array($n['select'] ?? null)) {
            return null;
        }
        if (($n['k'] ?? null) === 'col' && isset($n['clave']) && !isset($n['externa'])) {
            $claves[$n['clave']] = true;
        }
        foreach ($n as $v) {
            if (\is_array($v) && ($claves = self::clavesUsadas($v, $claves)) === null) {
                return null;
            }
        }
        return $claves;
    }

    private static function recorrerColumnas(array $n, array &$porAlias, array &$sueltas, array &$todas): bool
    {
        if (!empty($n['star'])) {
            if (($n['tabla'] ?? null) === null) {
                return false;
            }
            $todas[strtolower((string)$n['tabla'])] = true;
        } elseif (($n['k'] ?? null) === 'col' && isset($n['nombre'])) {
            if (($n['tabla'] ?? null) === null) {
                $sueltas[strtolower((string)$n['nombre'])] = true;
            } else {
                $porAlias[strtolower((string)$n['tabla'])][strtolower((string)$n['nombre'])] = true;
            }
        }
        foreach ($n as $v) {
            if (\is_array($v) && !self::recorrerColumnas($v, $porAlias, $sueltas, $todas)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Carga una tabla o subconsulta. Con $prefijar, cada fila se copia con
     * las claves `alias.columna`, que es lo que necesita un cruce, y solo
     * con las columnas que la consulta usa ($usadas); sin él, las filas salen
     * tal como están en la tabla, sin copiarlas, y en memoria son las mismas
     * que ya tiene la caché.
     *
     * @return array{fuentes: list<array{0: string, 1: string, 2: string}>, filas: iterable<array>}
     */
    private function cargar(array $o, array $predicados, ?int $tope, bool $prefijar, ?array $usadas, array $rangos = []): array
    {
        $heredadas = [[], []];             // las colaciones (2.9) y afinidades (2.10) de una subconsulta, una vista o una CTE
        if ($o['tipo'] === 'sub') {
            $r      = $this->correrEntera($o['select']);
            $alias  = $o['alias'];
            $cols   = $r['cols'];
            $origen = $r['filas'];
            $heredadas = SelectCompuesta::heredadas($r);
        } elseif (isset($this->con[strtolower($o['nombre'])])) {
            // Una consulta con nombre del WITH: se ejecuta como una subconsulta
            $r      = SelectCompuesta::delWith($this, strtolower($o['nombre']));
            $alias  = $o['alias'] ?? $o['nombre'];
            $cols   = $r['cols'];
            $origen = $r['filas'];
            $heredadas = SelectCompuesta::heredadas($r);
            unset($r);                     // que no queden dos referencias a las filas
        } elseif ($this->cat->esVista($o['nombre'])) {
            // Una vista es un SELECT guardado: se analiza y se ejecuta igual
            // que una subconsulta del FROM.
            $vista = $this->cat->vista($o['nombre']);

            if ($this->profundidadVista >= self::MAX_VISTAS) {
                throw JsonSqlDbError::schema(
                    "Demasiadas vistas anidadas en '{$o['nombre']}' (máximo " . self::MAX_VISTAS
                    . '): comprueba que no se referencian entre ellas'
                );
            }

            $this->profundidadVista++;
            try {
                $r = $this->correrEntera(Parser::analizar((string)$vista['sql'], [], $this->cat->nombres()));
            } finally {
                $this->profundidadVista--;
            }
            $alias  = $o['alias'] ?? $o['nombre'];
            $cols   = $r['cols'];
            $origen = $r['filas'];
            $heredadas = SelectCompuesta::heredadas($r);
            unset($r);                     // que no queden dos referencias a las filas
        } else {
            $nombre = $o['nombre'];
            if (!$this->cat->existe($nombre)) {
                throw JsonSqlDbError::schema("La tabla '$nombre' no existe");
            }
            $alias  = $o['alias'] ?? $nombre;
            $meta   = $this->cat->meta($nombre);
            $cols   = [];
            foreach ($meta['columns'] as $c) {
                $cols[] = $c['name'];
            }
            // Con un índice aprovechable se leen solo las partes donde están las
            // filas buscadas; si no lo hay, o no compensa, se recorre la tabla.
            $orden  = $this->porOrden;
            $st     = $this->cat->storage();
            // Con el límite del ORDER BY en el WHERE (ver SelectOrden::ordenPorIndice()), el
            // índice en orden antes que el rango: lee las n filas, no todo el rango
            $origen = $orden !== null && $orden['desde'] !== null && empty($o['posicion'])
                ? StorageOrden::filasPorOrden($st, $nombre, $orden['def'], $orden['desc'], $orden['k'], null, $orden['desde']) : null;
            if ($origen !== null) {
                $this->via = ['índice, en orden' . ($orden['desc'] ? ' descendente' : '') . ' desde el límite del WHERE', $orden['def']['name']];
            } elseif (!empty($o['posicion'])) {
                $cols[] = self::POSICION;
                $origen = SelectAvanzada::conPosicion($this, $nombre, $predicados[strtolower($alias)] ?? []);
            } else {
                $origen = $this->porIndice($nombre, $predicados[strtolower($alias)] ?? [])
                       ?? $this->porRango($nombre, $rangos[strtolower($alias)] ?? [], strtolower($alias));
            }
            if ($origen === null && $orden !== null && $orden['desde'] === null) {
                $origen = StorageOrden::filasPorOrden($st, $nombre, $orden['def'], $orden['desc'], $orden['k'], $orden['binaria']);
                $this->via = ['índice, en orden' . ($orden['desc'] ? ' descendente' : ''), $orden['def']['name']];
            }
            if ($origen === null) {
                $agujas = IndexesBusqueda::agujas($predicados[strtolower($alias)] ?? [], Catalog::colaciones($meta));
                $parte  = 1;
                if ($this->saltoPedido > 0 && $agujas === [] && $this->indexable) {
                    // LIMIT … OFFSET sin más: las partes que el OFFSET salta enteras
                    // no se leen (2.9: LIMIT 100 OFFSET 500000 decodificaba medio millón de filas)
                    [$parte, $this->saltadas] = StorageLectura::partesQueSalta($st, $nombre, $this->saltoPedido);
                }
                $origen = $parte > 1 ? $st->filas($nombre, false, [], $parte) : ($this->lector)($nombre, $agujas);
                $this->via = [$agujas === [] ? 'recorrido' : 'recorrido, sin decodificar las partes que no contienen el texto buscado', null];
            }
            if ($st->traza !== null) {
                $origen = SelectAvanzada::contando($this, SelectAvanzada::apuntar($this, $nombre, $this->via[0], $this->via[1], 0), $origen);
            }
        }

        if ($prefijar && $usadas !== null && !isset($usadas[2][strtolower($alias)])) {
            [$porAlias, $sueltas] = $usadas;
            $suyas = $porAlias[strtolower($alias)] ?? [];
            $cols  = array_values(array_filter($cols,
                static fn(string $c): bool => isset($suyas[strtolower($c)]) || isset($sueltas[strtolower($c)])));
        }
        $fuentes    = [];
        [$colaciones, $afinidades] = isset($meta) ? self::colacionesYAfinidades($meta) : $heredadas;
        foreach ($cols as $c) {
            $fuentes[] = [$alias, $c, $prefijar ? $alias . '.' . $c : $c, null, $colaciones[strtolower($c)] ?? null, $afinidades[strtolower($c)] ?? null];
        }
        if ($prefijar) {
            $origen = self::aplanar($origen, $cols, $alias);
        } elseif ($tope !== null) {
            $origen = self::acotar($origen, $tope);
        }
        return ['fuentes' => $fuentes, 'filas' => $origen];
    }

    /**
     * Una subconsulta del FROM o una vista, que se lee entera (2.9). Si no
     * cabe en memoria, Memoria avisa y la consulta de fuera se repite por el
     * disco (ver correr()); entonces sus filas se escriben en un temporal
     * según salen, y se leen de ahí según se usan.
     *
     * @return array{cols: string[], filas: iterable<array>, colaciones: array}
     */
    protected function correrEntera(array $ast): array
    {
        if ($this->enDisco) {
            $volcado = new Volcado($this->cat->storage()->carpetaOrden());
            $previo  = [$this->sumidero, $this->nivelSumidero, $this->entregado];
            $this->sumidero      = static function (array $fila) use ($volcado): void {
                $volcado->anadir($fila);
            };
            $this->nivelSumidero = $this->nivel + 1;
            try {
                $r = $this->correr($ast);
            } finally {
                [$this->sumidero, $this->nivelSumidero, $this->entregado] = $previo;
            }
            foreach ($r['filas'] as $fila) {
                $volcado->anadir($fila);        // lo que no salió fila a fila
            }
            $r['filas'] = $volcado->leer();
            return $r;
        }
        [$juntando, $this->juntando] = [$this->juntando, $this->nivel + 1];
        $aviso = Memoria::avisarAlApretar(true);
        try {
            return $this->correr($ast);
        } finally {
            Memoria::dejarAviso($aviso);
            $this->juntando = $juntando;
        }
    }

    /**
     * Copia cada fila con las claves prefijadas por el alias, según se pide.
     *
     * @param iterable<array> $origen
     * @param list<string>    $cols
     */
    private static function aplanar(iterable $origen, array $cols, string $alias): \Generator
    {
        // Los nombres con el alias, una vez (2.9): se montaban en cada fila y
        // cada columna, y en un JOIN grande era buena parte de su coste
        $prefijadas = [];
        foreach ($cols as $c) {
            $prefijadas[$c] = $alias . '.' . $c;
        }
        $vigilar = Memoria::cada();                  // filas hasta mirar la memoria (ver Storage::filas())
        foreach ($origen as $fila) {
            $plana = [];
            foreach ($prefijadas as $c => $p) {
                $plana[$p] = $fila[$c] ?? null;
            }
            if (--$vigilar === 0) {
                Memoria::revisar('la carga de la tabla');
                $vigilar = Memoria::cada();
            }
            yield $plana;
        }
    }

    /**
     * Las filas tal cual, parando en el tope.
     *
     * @param iterable<array> $origen
     */
    private static function acotar(iterable $origen, int $tope): \Generator
    {
        $n = 0;
        foreach ($origen as $fila) {
            if ($n++ >= $tope) {
                return;
            }
            yield $fila;
        }
    }

    /** x de un x COLLATE …, que en una igualdad ya plegada no cambia nada; lo demás, igual */
    private static function sinCollate(array $n): array
    {
        return $n['k'] === 'fn' && $n['nombre'] === 'COLLATE' ? $n['args'][0] : $n;
    }

    /**
     * $sub y $conjunto para el ON de un cruce. Ahí una subconsulta nunca mira
     * hacia fuera —se resuelve entera antes del cruce—, así que su resultado
     * y su conjunto se guardan siempre.
     *
     * @return array{0: \Closure, 1: \Closure}
     */
    protected function subconsultasDelOn(): array
    {
        $sub = function (array $sel, int $sid): array {
            if (!isset($this->subs[$sid])) {
                $r = $this->correr($sel);
                $this->subs[$sid]        = $r['filas'];
                $this->colacionSub[$sid] = $r['colaciones'][0] ?? null;
                $this->afinidadSub[$sid] = $r['afinidades'][0] ?? '';
            }
            return $this->subs[$sid];
        };
        $conjunto = function (array $sel, int $sid, array $filaExterna = [], string|bool|null $colacion = null, ?string $afinidad = null): array {
            if (!isset($this->conjuntos[$sid])) {
                [$valores, $this->colacionSub[$sid], $this->afinidadSub[$sid]] = $this->valoresDe($sel);
                $this->conjuntos[$sid] = IndexesBusqueda::conjuntoDeIn($valores, $colacion, $this->colacionSub[$sid], $afinidad, $this->afinidadSub[$sid]);
            }
            return $this->conjuntos[$sid];
        };
        return [$sub, $conjunto];
    }

    /**
     * Separa la condición ON en igualdades directas (para el hash) y el resto.
     * Cada igualdad lleva la colación con la que compara (2.9), o null: la de
     * una columna con COLLATE va como PLEGAR(col) = PLEGAR(col) (ver
     * Evaluator::resolver()); y cómo iguala (2.10, ver abajo).
     *
     * @return array{0: array<int,array{0:string,1:string,2:?string,3:string}>, 1: ?array}
     */
    protected function igualdades(array $on, array $clavesDer): array
    {
        $pares = [];
        $resto = [];

        $pila = [$on];
        while ($pila !== []) {
            $n = array_pop($pila);
            if ($n['k'] === 'bin' && $n['op'] === 'AND') {
                $pila[] = $n['i'];
                $pila[] = $n['d'];
                continue;
            }
            if ($n['k'] === 'bin' && $n['op'] === '=') {
                [$i, $d, $c] = [$n['i'], $n['d'], null];
                if ($i['k'] === 'fn' && $i['nombre'] === 'PLEGAR' && $d['k'] === 'fn' && $d['nombre'] === 'PLEGAR'
                    && $i['args'][1]['v'] === $d['args'][1]['v']) {
                    [$i, $d, $c] = [self::sinCollate($i['args'][0]), self::sinCollate($d['args'][0]), (string)$i['args'][1]['v']];
                }
                // Cómo iguala (2.10, ver SelectJoin::claveHash()): 'T' como
                // textos (una columna de texto con otra, o con una sin afinidad,
                // que llega como CAST(… AS TEXT): ver Evaluator::afinar()); 'N'
                // con números (alguna numérica); '' ninguna de las dos sabe, y
                // la clave solo acota: la igualdad se vuelve a mirar
                $modo = null;
                if ($i['k'] === 'cast' && $i['tipo'] === 'TEXT') {
                    [$i, $modo] = [$i['e'], 'T'];
                } elseif ($d['k'] === 'cast' && $d['tipo'] === 'TEXT') {
                    [$d, $modo] = [$d['e'], 'T'];
                }
                if ($i['k'] === 'col' && $d['k'] === 'col' && isset($i['clave'], $d['clave'])) {
                    $iEsDer = isset($clavesDer[$i['clave']]);
                    $dEsDer = isset($clavesDer[$d['clave']]);
                    if ($iEsDer !== $dEsDer) {
                        $ai     = $i['afinidad'] ?? '';
                        $ad     = $d['afinidad'] ?? '';
                        $modo ??= $ai === 'N' || $ad === 'N' ? 'N' : ($ai === 'T' && $ad === 'T' ? 'T' : '');
                        $pares[] = $iEsDer ? [$d['clave'], $i['clave'], $c, $modo] : [$i['clave'], $d['clave'], $c, $modo];
                        if ($modo === '') {
                            $resto[] = $n;
                        }
                        continue;
                    }
                }
            }
            $resto[] = $n;
        }

        $condicion = null;
        foreach ($resto as $r) {
            $condicion = $condicion === null ? $r : ['k' => 'bin', 'op' => 'AND', 'i' => $condicion, 'd' => $r];
        }
        return [$pares, $condicion];
    }

    // ==================================================================
    // Columnas, agrupación y orden
    // ==================================================================

    /**
     * 'alias.col' => clave, y 'col' => clave (false si es ambigua); y "\0c:clave"
     * => la colación de esa columna, si la tiene (2.9), y "\0a:clave" => su
     * afinidad, si es de una tabla (2.10). Una columna repetida por USING solo
     * va con su tabla delante.
     *
     * @param list<array{0: string, 1: string, 2: string, 3?: bool, 4?: string, 5?: string}> $fuentes
     */
    protected function mapaColumnas(array $fuentes): array
    {
        $mapa = [];
        foreach ($fuentes as $f) {
            [$alias, $col, $clave] = $f;
            $mapa[strtolower($alias . '.' . $col)] = $clave;
            if (isset($f[4])) {
                $mapa["\0c:" . $clave] = $f[4];        // su COLLATE (ver Evaluator::resolver())
            }
            if (isset($f[5]) && $f[5] !== '') {
                $mapa["\0a:" . $clave] = $f[5];        // su afinidad (ver Evaluator::afinidadDe())
            }
            if (!empty($f[3])) {
                continue;                             // una columna de USING repetida: solo con su tabla delante
            }
            $corto = strtolower($col);
            if (\array_key_exists($corto, $mapa)) {
                if ($mapa[$corto] !== $clave) {
                    $mapa[$corto] = false;
                }
            } else {
                $mapa[$corto] = $clave;
            }
        }
        return $mapa;
    }

    /**
     * Expande * y calcula el nombre de salida de cada columna.
     *
     * @param list<array{0: string, 1: string, 2: string}> $fuentes
     */
    private function columnasSalida(array $cols, array $fuentes, array $mapa): array
    {
        $salida = [];
        $usados = [];

        // 'como': el alias escrito con AS, en minúsculas (ver aliasEscrito())
        $anadir = function (string $nombre, array $expr, ?string $como = null) use (&$salida, &$usados): void {
            $base = $nombre;
            $n    = 2;
            while (isset($usados[$nombre])) {
                $nombre = $base . '_' . $n++;
            }
            $usados[$nombre] = true;
            $salida[] = $como === null ? ['nombre' => $nombre, 'expr' => $expr] : ['nombre' => $nombre, 'expr' => $expr, 'como' => strtolower($como)];
        };

        foreach ($cols as $c) {
            if (!empty($c['star'])) {
                $encontradas = 0;
                foreach ($fuentes as $f) {
                    [$alias, $corto, $clave] = $f;
                    if ($c['tabla'] !== null ? strcasecmp($alias, $c['tabla']) !== 0 : !empty($f[3])) {
                        continue;
                    }
                    // Con su colación y su afinidad, que hereda quien la lea (una vista, una subconsulta)
                    $col = ['k' => 'col', 'tabla' => $alias, 'nombre' => $corto, 'clave' => $clave];
                    if (isset($f[4])) {
                        $col['colacion'] = $f[4];
                    }
                    if (isset($f[5]) && $f[5] !== '') {
                        $col['afinidad'] = $f[5];
                    }
                    $anadir($corto, $col);
                    $encontradas++;
                }
                if ($c['tabla'] !== null && $encontradas === 0) {
                    throw JsonSqlDbError::schema("No hay ninguna tabla o alias '{$c['tabla']}' en el FROM");
                }
                continue;
            }
            $expr = Evaluator::resolver($c['expr'], $mapa, [], $this->externo['mapa'] ?? []);
            $anadir($c['alias'] ?? self::etiqueta($c['expr']), $expr, $c['alias'] ?? null);
        }

        if ($salida === []) {
            throw JsonSqlDbError::syntax('El SELECT no devuelve ninguna columna');
        }
        return $salida;
    }

    private function hayAgregados(array $salida, array $ast): bool
    {
        foreach ($salida as $c) {
            if (Evaluator::tieneAgregado($c['expr'])) {
                return true;
            }
        }
        foreach ($ast['order'] as $o) {
            if (Evaluator::tieneAgregado($o['expr'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Las filas que cumplen el WHERE, según se recorren. Con una comparación
     * simple o una condición compilable no se pasa por el evaluador general
     * en cada fila, que es lo que domina el coste en tablas grandes.
     *
     * @param iterable<array> $filas
     */
    private function filtrar(iterable $filas, array $where, callable $sub, callable $conjunto, array $externa, ?int $tope): \Generator
    {
        $simple    = Valor::comparacionSimple($where);
        $rango     = $simple === null ? self::rangoNumerico($where) : null;
        $compilado = $simple === null && $rango === null ? EvaluatorCompilado::compilar($where, $conjunto) : null;
        // Con un literal numérico, un valor numérico se compara aquí mismo:
        // Valor::comparar() haría exactamente lo mismo (<=>) tras una llamada.
        // Pasado 2^53, por Valor::comparar(), que es exacto entre un entero y
        // un decimal (2.10)
        $num = $simple !== null && (\is_int($simple['valor']) || \is_float($simple['valor'])) && abs($simple['valor']) < 9007199254740992;
        $n = 0;
        $vigilar = Memoria::cada();                  // filas hasta mirar la memoria (ver Storage::filas())
        foreach ($filas as $fila) {
            if ($simple !== null) {
                $v = $fila[$simple['clave']] ?? null;
                if ($num && (\is_int($v) || \is_float($v))) {
                    $c = $v <=> $simple['valor'];
                    switch ($simple['op']) {
                        case '=':  $vale = $c === 0; break;
                        case '<':  $vale = $c < 0; break;
                        case '<=': $vale = $c <= 0; break;
                        case '>':  $vale = $c > 0; break;
                        case '>=': $vale = $c >= 0; break;
                        default:   $vale = $c !== 0;          // <> y !=
                    }
                } else {
                    $vale = $v === null ? false : Valor::compara($simple['op'], $v, $simple['valor']);
                }
            } elseif ($rango !== null) {
                // col BETWEEN a AND b, con a y b números
                $v = $fila[$rango[0]] ?? null;
                if (\is_int($v) || \is_float($v)) {
                    $vale = ($v >= $rango[1] && $v <= $rango[2]) !== $rango[3];
                } else {
                    // NULL o incomparable: desconocido, que no cumple ni con NOT
                    $ca = $v === null ? null : Valor::comparar($v, $rango[1]);
                    $cb = $v === null ? null : Valor::comparar($v, $rango[2]);
                    $vale = $ca !== null && $cb !== null && (($ca >= 0 && $cb <= 0) !== $rango[3]);
                }
            } elseif ($compilado !== null) {
                $vale = Valor::verdadero($compilado($fila)) === true;
            } else {
                $vale = Valor::verdadero(Evaluator::evaluar(
                    $where, ['fila' => $fila, 'sub' => $sub, 'conjunto' => $conjunto,
                             'filaExterna' => $externa])) === true;
            }
            if ($vale) {
                if (--$vigilar === 0) {
                    Memoria::revisar('el filtrado del WHERE');
                    $vigilar = Memoria::cada();
                }
                yield $fila;
                if ($tope !== null && ++$n >= $tope) {
                    return;                             // ya no hacen falta más
                }
            }
        }
    }

    // ==================================================================
    // Funciones de ventana (2.9)
    // ==================================================================

    /** Nombre por defecto de una columna calculada, parecido al que da SQLite. */
    public static function etiqueta(array $n): string
    {
        switch ($n['k']) {
            case 'col':
                return $n['nombre'];
            case 'lit':
                if ($n['v'] === null)   { return 'NULL'; }
                if (\is_string($n['v'])) { return "'" . $n['v'] . "'"; }
                return (string)$n['v'];
            case 'fn':
                // x COLLATE NOCASE, como se escribió (2.9); PLEGAR lo pone el analizador
                if ($n['nombre'] === 'COLLATE') {
                    return self::etiqueta($n['args'][0]) . ' COLLATE ' . $n['args'][1]['v'];
                }
                if ($n['nombre'] === 'PLEGAR') {
                    return self::etiqueta($n['args'][0]);
                }
                // no break
            case 'ventana':
                $args = [];
                foreach ($n['args'] as $a) {
                    $args[] = self::etiqueta($a);
                }
                return $n['nombre'] . ($n['star'] ? '(*)' : '(' . (empty($n['distinct']) ? '' : 'DISTINCT ') . implode(', ', $args) . ')')
                    . (isset($n['filtro']) ? ' FILTER (WHERE ' . self::etiqueta($n['filtro']) . ')' : '')
                    . ($n['k'] === 'ventana' ? ' OVER (…)' : '');
            case 'bin':
                return self::etiqueta($n['i']) . ' ' . $n['op'] . ' ' . self::etiqueta($n['d']);
            case 'un':
                return $n['op'] . ' ' . self::etiqueta($n['e']);
            case 'case':
                return 'CASE';
            case 'sub':
                return 'subconsulta';
        }
        return 'expr';
    }
}
