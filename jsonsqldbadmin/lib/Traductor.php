<?php
declare(strict_types=1);

require_once __DIR__ . '/TraductorRutinas.php';

/**
 * Traduce las sentencias de un volcado de SQLite (`sqlite3 base.db .dump`), de
 * MySQL / MariaDB (`mysqldump`), de PostgreSQL (`pg_dump`, en texto), de SQL
 * Server (el script de «Generar scripts» de Management Studio) o de Microsoft
 * Access (el de access-to-jsonsqldb.ps1) al SQL que entiende jsonSQLDB, una a
 * una.
 *
 * Lo que hace, por dialecto:
 *  - Los nombres entre `comillas invertidas` o [corchetes] pasan a "dobles".
 *  - Las cadenas de MySQL, con sus escapes de barra invertida (\n, \', \\…),
 *    pasan a cadenas normales; X'…', 0x… y b'…' pasan a texto o a número, y
 *    char(10) de los volcados de SQLite a su carácter.
 *  - En CREATE TABLE, los tipos de MySQL pasan a los de aquí (INT(11)
 *    UNSIGNED → INTEGER, LONGTEXT → TEXT…; ENUM conserva su lista, 2.9), se
 *    quita lo que aquí no existe (CHARACTER SET, COMMENT, ON UPDATE, las
 *    opciones de después del paréntesis; una colación que no distingue
 *    mayúsculas pasa a COLLATE NOCASE, 2.9: ver colacion()), los CHECK se
 *    quedan (2.9: ver check()), los KEY/INDEX de dentro salen como CREATE
 *    INDEX y las claves foráneas se dejan para el final, como ALTER TABLE,
 *    porque los volcados crean las tablas en cualquier orden y meten los
 *    datos con las comprobaciones desactivadas.
 *  - Se saltan las sentencias de control (PRAGMA, BEGIN, COMMIT, SET, LOCK,
 *    USE, DELIMITER…) y la tabla interna sqlite_sequence. Lo de dentro de los
 *    comentarios /*! … *\/ de mysqldump es SQL (sus vistas y triggers, entre
 *    otras cosas) y se traduce como el resto.
 *  - Las vistas y los triggers pasan por TraductorRutinas.
 *
 * Lo que no tiene equivalente (valores por defecto calculados que no son la
 * fecha de ahora, columnas generadas, un CHECK que usa algo que aquí no
 * existe…) se quita y se anota en los avisos: la importación dice
 * exactamente qué no ha llegado igual.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Traductor
{
    /** Palabras que abren una restricción de columna: el tipo acaba ahí. */
    private const RESTRICCIONES = ['NOT', 'NULL', 'DEFAULT', 'PRIMARY', 'UNIQUE', 'KEY', 'AUTO_INCREMENT',
        'AUTOINCREMENT', 'REFERENCES', 'CHECK', 'COLLATE', 'CHARACTER', 'CHARSET', 'COMMENT', 'ON', 'CONSTRAINT',
        'GENERATED', 'AS', 'INVISIBLE', 'VISIBLE', 'STORAGE', 'COLUMN_FORMAT', 'SRID', 'IDENTITY', 'ROWGUIDCOL',
        'SPARSE', 'FILESTREAM'];

    /**
     * Esquemas cuyo nombre se quita de delante de las tablas: los de siempre y
     * (2.9) los que el volcado crea con CREATE SCHEMA o usa en un CREATE TABLE.
     * Antes solo dbo y public: con cualquier otro, COPY ventas.pedidos buscaba
     * la tabla «ventas» y la importación se paraba.
     *
     * @var array<string, true>
     */
    private array $esquemas = ['dbo' => true, 'public' => true];
    /** @var array<string, array<string, true>> tabla => esquemas en los que la crea el volcado */
    private array $esquemasDeTabla = [];
    /** @var array<string, true> tablas virtuales de SQLite (FTS…), que se saltan con sus tablas auxiliares */
    private array $virtuales = [];
    /** @var array<string, string> partición de PostgreSQL => su tabla madre, que recibe sus filas */
    private array $particiones = [];
    /** @var array<string, true> vistas materializadas de PostgreSQL, creadas como vistas */
    private array $materializadas = [];

    /** Sentencias que en un volcado solo controlan la sesión o la transacción. */
    private const CONTROL = ['PRAGMA', 'BEGIN', 'COMMIT', 'END', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'SET', 'LOCK',
        'UNLOCK', 'USE', 'DELIMITER', 'ANALYZE', 'VACUUM', 'OPTIMIZE', 'FLUSH', 'START'];

    /** @var array<string,int> avisos de toda la importación, con cuántas veces */
    private array $avisos = [];
    /** @var list<string> claves foráneas para el final */
    private array $aplazadas = [];
    /** @var list<array{0: string, 1: string}> triggers para el final, después de los datos: [nombre, sql] */
    private array $triggers = [];
    /** @var array<string,string> cuerpos de las funciones de PostgreSQL, para sus triggers */
    private array $funciones = [];
    /** ¿Se conservan los ::tipo? Solo al traducir vistas y triggers, donde cambian el resultado */
    private bool $conCasts = false;
    private bool $mysql;
    private bool $pg;
    private bool $ss;
    private bool $access;
    /** @var array<string,array{pk: list<string>, auto: array<string,true>, defecto: array<string,string>, tipos: array<string,string>}> lo visto en la primera pasada, por tabla */
    private array $tablas = [];
    /** @var array<string,array{cols: list<string>, marcas?: array<string,string>}> columnas de cada tabla de MySQL y cuáles son BIT ('bit') */
    private array $columnasMysql = [];
    /** @var array<string,array{cols: list<string>, marcas: array<string,string>}>|null lo mismo de las tablas de PostgreSQL y SQL Server con columnas con zona horaria ('zona', 2.9; ver conZona()) */
    private ?array $columnasConZona = null;
    /** @var array<string, array<string, true>> las columnas de cada tabla creada, para los CHECK (2.9) */
    private array $columnasDe = [];

    /** La fecha cero de MySQL, «0000-00-00», que aquí no existe */
    private const FECHA_CERO = '/^0000-00-00( 00:00:00(\.0+)?)?$/';

    /** Lo que puede ir en un CHECK además de columnas, números, textos y operadores: aquí significa lo mismo */
    private const PALABRAS_CHECK  = ['AND', 'OR', 'NOT', 'IN', 'BETWEEN', 'IS', 'NULL', 'LIKE'];
    private const FUNCIONES_CHECK = ['LENGTH', 'CHAR_LENGTH', 'LOWER', 'UPPER', 'TRIM', 'ABS', 'COALESCE', 'IFNULL',
                                     'ROUND', 'SUBSTR', 'SUBSTRING', 'JSON_VALID'];
    /** Una letra con acento (y sus parientes), en un nombre de tabla: su letra base (2.9) */
    private const LETRA_BASE = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'ñ' => 'n', 'Ñ' => 'N', 'ç' => 'c', 'Ç' => 'C', 'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y',
        'ß' => 'ss', 'æ' => 'ae', 'Æ' => 'AE', 'œ' => 'oe', 'Œ' => 'OE',
    ];
    /** La palabra tras la que va el nombre de una tabla (ver marcarTablas()) */
    private const TRAS_TABLA = ['FROM' => 1, 'JOIN' => 1, 'INTO' => 1, 'UPDATE' => 1, 'TABLE' => 1, 'EXISTS' => 1];
    /** Lo que acaba la lista de tablas de un FROM */
    private const FIN_FROM = ['WHERE' => 1, 'GROUP' => 1, 'ORDER' => 1, 'HAVING' => 1, 'UNION' => 1, 'EXCEPT' => 1, 'INTERSECT' => 1,
                              'LIMIT' => 1, 'ON' => 1, 'USING' => 1, 'WINDOW' => 1, 'SET' => 1, 'VALUES' => 1, 'SELECT' => 1];
    /** @var array<string,string> nombre de destino (en minúsculas) de cada tabla o vista → nombre de origen */
    private array $destinos = [];
    /** @var array<string,string> nombre de origen de cada tabla o vista → su nombre aquí (ver nombreTabla()) */
    private array $tablaDe = [];
    /** @var array<string,true> nombres ya cambiados, para avisar una sola vez */
    private array $renombrados = [];
    /** Orden de las fechas d/m/a de SET DATEFORMAT (SQL Server), o '' */
    private string $formatoFecha = '';
    /**
     * ¿Concatena || en el MySQL del volcado? Solo con PIPES_AS_CONCAT (o
     * ANSI) en el sql_mode que mysqldump pone antes de cada trigger, que lo
     * guarda tal como se escribió; si no, allí || es OR (2.9).
     */
    private bool $tuberiasConcatenan = false;

    public function __construct(string $dialecto)
    {
        $this->mysql = $dialecto === 'mysql';
        $this->pg    = $dialecto === 'postgresql';
        $this->ss    = $dialecto === 'sqlserver';
        $this->access = $dialecto === 'access';
    }

    /** ¿Qué dialecto parece un volcado, por sus primeras líneas? */
    public static function detectar(string $inicio): string
    {
        // Los volcados del propio panel dicen para qué motor son en su cabecera,
        // con saltos de línea de Windows o no (2.9: el script de Access, en
        // Windows, escribe \r\n, y su volcado se tomaba por uno de aquí)
        if (preg_match('/^-- jsonsqldb-dialecto: (sqlite|mysql|postgresql|sqlserver|access)\r?$/m', $inicio, $m)) {
            return $m[1] === 'sqlite' ? 'jsonsqldb' : $m[1];
        }
        if (preg_match('/MDB Tools - A library for reading MS Access/', $inicio)) {
            return 'access';
        }
        if (preg_match('/^--\s*PostgreSQL database dump|^SET standard_conforming_strings|^COPY .+ FROM stdin;/mi', $inicio)) {
            return 'postgresql';
        }
        if (preg_match('/^\s*GO\s*$|^SET (ANSI_NULLS|QUOTED_IDENTIFIER|IDENTITY_INSERT)\b|\[dbo\]\./mi', $inicio)) {
            return 'sqlserver';
        }
        if (preg_match('/^--\s*(MySQL|MariaDB) dump|\/\*!40\d{3}|^\s*LOCK TABLES `|ENGINE=\w+/mi', $inicio)) {
            return 'mysql';
        }
        if (preg_match('/^\s*PRAGMA foreign_keys\s*=\s*OFF;|^\s*BEGIN TRANSACTION;|sqlite_sequence/mi', $inicio)) {
            return 'sqlite';
        }
        return 'jsonsqldb';
    }

    /**
     * Primera pasada (PostgreSQL y SQL Server): recoge lo que esos volcados
     * declaran fuera del CREATE TABLE —la clave primaria y el autoincremento
     * en PostgreSQL, los valores por defecto en SQL Server— y los tipos de
     * cada columna, para los datos de un COPY.
     */
    public function observar(string $sql): void
    {
        // Lo que avisan los tokens (una fecha con zona, un binario…) se cuenta en
        // la segunda pasada: contado también aquí, salía el doble de veces (2.9)
        $avisos = $this->avisos;
        $t = $this->tokens(preg_replace('/;\n.*/s', ';', $sql) ?? $sql);
        $this->avisos = $avisos;
        if ($this->pg && $this->particion($t)) {
            return;
        }
        $w = array_map(static fn(array $x): string => empty($x['q']) ? strtoupper($x['v']) : '', array_slice($t, 0, 12));
        // Los esquemas: CREATE SCHEMA [IF NOT EXISTS] x, y el de cada CREATE TABLE x.t
        if (($w[0] ?? '') === 'CREATE' && ($w[1] ?? '') === 'SCHEMA') {
            $nombre = $t[($w[2] ?? '') === 'IF' ? 5 : 2]['v'] ?? '';
            $this->esquemas[strtolower((string)$nombre)] = true;
            return;
        }
        // (del texto: los tokens ya vienen sin los esquemas que se conocen)
        $id = '(?:"([^"]+)"|\[([^\]]+)\]|`([^`]+)`|(\w+))';
        if (preg_match('/^\s*CREATE\s+(?:(?:TEMP|TEMPORARY|UNLOGGED)\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $id . '\s*\.\s*' . $id . '\s*\(/i', $sql, $m)) {
            $esquema = strtolower(implode('', array_slice($m, 1, 4)));
            $this->esquemas[$esquema] = true;
            $this->esquemasDeTabla[strtolower(implode('', array_slice($m, 5, 4)))][$esquema] = true;
        }
        if (($w[0] ?? '') === 'CREATE' && in_array('TABLE', array_slice($w, 1, 3), true)) {
            $tabla = '';
            foreach ($t as $k => $x) {
                if ($x['k'] === 'op' && $x['v'] === '(') { $tabla = $t[$k - 1]['v']; break; }
            }
            $this->tablas[$tabla] ??= ['pk' => [], 'auto' => [], 'defecto' => [], 'tipos' => []];
            // Tipos de cada columna: lo que va entre el nombre y la primera restricción
            $nivel = 0;
            $col = null;
            $tipo = '';
            foreach ($t as $x) {
                if ($x['k'] === 'op' && $x['v'] === '(') { $nivel++; if ($nivel === 1) { $col = null; continue; } }
                if ($x['k'] === 'op' && $x['v'] === ')') { $nivel--; }
                if ($nivel === 1 && $x['k'] === 'op' && $x['v'] === ',') {
                    if ($col !== null) { $this->tablas[$tabla]['tipos'][$col] = $tipo; }
                    $col = null;
                    continue;
                }
                if ($nivel >= 1 && $col === null && $x['k'] === 'id') { $col = $x['v']; $tipo = ''; continue; }
                if ($nivel >= 1 && $col !== null && $x['k'] === 'id' && $tipo !== '' && empty($x['q'])
                    && in_array(strtoupper($x['v']), self::RESTRICCIONES, true)) { $tipo .= '|'; }
                if ($nivel >= 1 && $col !== null && strpos($tipo, '|') === false && $x['k'] === 'id') { $tipo .= ' ' . strtoupper($x['v']); }
            }
            if ($col !== null) { $this->tablas[$tabla]['tipos'][$col] = $tipo; }
            return;
        }
        if (($w[0] ?? '') !== 'ALTER' || ($w[1] ?? '') !== 'TABLE') {
            return;
        }
        $k = ($w[2] ?? '') === 'ONLY' ? 3 : 2;
        $tabla = $t[$k]['v'] ?? '';
        $this->tablas[$tabla] ??= ['pk' => [], 'auto' => [], 'defecto' => [], 'tipos' => []];
        $texto = implode(' ', $w);
        // ALTER TABLE t ADD CONSTRAINT x PRIMARY KEY (id)
        if (preg_match('/\bADD (CONSTRAINT \S* )?PRIMARY KEY\b/', $texto)) {
            $this->tablas[$tabla]['pk'] = $this->lista(array_slice($t, $k + 1));
        }
        // ALTER TABLE t ALTER COLUMN id ADD GENERATED … AS IDENTITY / SET DEFAULT nextval(…)
        if (($w[$k + 1] ?? '') === 'ALTER' && preg_match('/\b(ADD GENERATED|SET DEFAULT NEXTVAL)\b/', $texto)) {
            $col = $t[$k + 2]['v'] ?? '';
            if (strtoupper($col) === 'COLUMN') { $col = $t[$k + 3]['v'] ?? ''; }
            $this->tablas[$tabla]['auto'][strtolower($col)] = true;
        }
        // ALTER TABLE t ADD [CONSTRAINT x] DEFAULT (…) FOR col (SQL Server)
        foreach ($t as $j => $x) {
            if (strtoupper($x['v']) === 'DEFAULT' && empty($x['q'])) {
                $fin = $this->saltarExpresion($t, $j + 1);
                if (strtoupper($t[$fin]['v'] ?? '') === 'FOR') {
                    $lit = $this->literalDe(array_slice($t, $j + 1, $fin - $j - 1));
                    if ($lit !== null) { $this->tablas[$tabla]['defecto'][strtolower($t[$fin + 1]['v'] ?? '')] = $lit; }
                }
                break;
            }
        }
    }

    /** ¿Es || una concatenación en lo que se traduce ahora? (ver $tuberiasConcatenan) */
    public function tuberiasConcatenan(): bool
    {
        return $this->tuberiasConcatenan;
    }

    /**
     * Los triggers traducidos, para crearlos al final: si se crearan donde van
     * en el volcado, saltarían con los datos que se cargan después.
     *
     * @return list<array{0: string, 1: string}> [nombre, sql]
     */
    public function triggers(): array
    {
        return $this->triggers;
    }

    /** @return list<string> lo que queda para el final: las claves foráneas */
    public function aplazadas(): array
    {
        return $this->aplazadas;
    }

    /** @return list<string> los avisos, cada uno con cuántas veces ha pasado */
    public function avisos(): array
    {
        $out = [];
        foreach ($this->avisos as $texto => $n) {
            $out[] = $n > 1 ? t('{aviso} ({n} veces)', ['aviso' => $texto, 'n' => $n]) : $texto;
        }
        return $out;
    }

    private function sqliteOJson(): bool
    {
        return !$this->mysql && !$this->pg && !$this->ss && !$this->access;
    }

    /**
     * ALTER TABLE de PostgreSQL y SQL Server: las claves primarias, los
     * autoincrementos y los valores por defecto ya fueron al CREATE TABLE
     * (ver observar()); las claves foráneas van al final; las únicas, tal cual;
     * lo demás no tiene equivalente.
     *
     * @return list<string>
     */
    private function alterarTabla(array $t): array
    {
        $k = strtoupper($t[2]['v'] ?? '') === 'ONLY' ? 3 : 2;
        $tabla = $t[$k]['v'] ?? '';
        $resto = array_slice($t, $k + 1);
        // WITH CHECK / WITH NOCHECK delante del ADD (SQL Server)
        if (strtoupper($resto[0]['v'] ?? '') === 'WITH') { $resto = array_slice($resto, 2); }
        $palabras = implode(' ', array_map(static fn(array $x): string => empty($x['q']) ? strtoupper($x['v']) : '', $resto));
        if (strpos($palabras, 'ADD') !== 0) {
            return [];                              // OWNER TO, ALTER COLUMN, CHECK CONSTRAINT…: ya hecho o sin equivalente
        }
        $nombre = null;
        $d = array_slice($resto, 1);
        if (strtoupper($d[0]['v'] ?? '') === 'CONSTRAINT') {
            $nombre = $d[1]['v'] ?? null;
            $d = array_slice($d, 2);
        }
        $w = strtoupper($d[0]['v'] ?? '');
        if ($w === 'FOREIGN') {
            $this->aplazar($tabla, $nombre, $d);
        } elseif ($w === 'UNIQUE') {
            $cols = $this->lista($d);
            return ['ALTER TABLE ' . $this->nombreObjeto($tabla) . ' ADD ' . ($nombre !== null ? 'CONSTRAINT ' . $this->nombreObjeto($nombre) . ' ' : '')
                . 'UNIQUE (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ')'];
        } elseif ($w === 'CHECK') {
            [$sql] = $this->check($tabla, $d, 1);
            if ($sql !== null) {
                return ['ALTER TABLE ' . $this->nombreObjeto($tabla) . ' ADD ' . ($nombre !== null ? 'CONSTRAINT ' . $this->nombreObjeto($nombre) . ' ' : '')
                    . 'CHECK ' . $sql];
            }
        }
        return [];                                  // PRIMARY KEY y DEFAULT: ya están en el CREATE TABLE
    }

    /**
     * Un COPY … FROM stdin de PostgreSQL, con sus líneas de datos, como INSERT
     * de 200 filas. Cada línea, campos separados por tabuladores; \N es NULL;
     * los booleanos t/f pasan a 1/0; un bytea \x… pasa a texto si es UTF-8; a
     * una fecha con zona horaria se le quita la zona.
     *
     * @return list<string>
     */
    private function copiar(string $sql, array $t): array
    {
        [, $datos] = array_pad(explode(";\n", $sql, 2), 2, '');
        $tabla = $t[1]['v'];
        $cols = $this->lista(array_slice($t, 2));
        $tipos = $this->tablas[$tabla]['tipos'] ?? [];
        $out = [];
        $lote = [];
        $cab = 'INSERT INTO ' . $this->nombreObjeto($tabla) . ' (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ') VALUES ';
        foreach (explode("\n", rtrim($datos, "\r\n")) as $linea) {
            if ($linea === '') { continue; }
            $vals = [];
            foreach (explode("\t", rtrim($linea, "\r")) as $j => $campo) {
                if ($campo === '\\N') { $vals[] = 'NULL'; continue; }
                $v = stripcslashes($campo);
                $tipo = $tipos[$cols[$j] ?? ''] ?? '';
                if (preg_match('/\bBOOL/', $tipo)) {
                    $vals[] = $v === 't' ? '1' : '0';
                    continue;
                }
                if (strpos($tipo, 'BYTEA') !== false && strpos($v, '\\x') === 0) {
                    $v = $this->binario(substr($v, 2));
                } elseif (preg_match(self::CON_ZONA, $tipo)) {
                    $v = $this->sinZona($v);
                }
                $vals[] = "'" . str_replace("'", "''", $this->fecha($v)) . "'";
            }
            $lote[] = '(' . implode(', ', $vals) . ')';
            if (count($lote) >= 200) { $out[] = $cab . implode(', ', $lote); $lote = []; }
        }
        if ($lote !== []) { $out[] = $cab . implode(', ', $lote); }
        return $out;
    }

    /**
     * Una fecha con más de tres decimales en los segundos (DATETIME2(7),
     * TIMESTAMP(6)…) se queda en milisegundos, que es lo que se guarda aquí.
     */
    private function fecha(string $v): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}\.\d{3})\d+$/', $v, $m)) {
            if ((int)substr($v, 23) !== 0) {
                $this->avisar(t('Fechas con más precisión que el milisegundo: se guardan en milisegundos'));
            }
            return $m[1];
        }
        return $v;
    }

    /** Los tipos de columna con zona horaria: timestamptz y timetz de PostgreSQL, datetimeoffset de SQL Server */
    private const CON_ZONA = '/WITH TIME ZONE|TIMESTAMPTZ|TIMETZ|DATETIMEOFFSET/';

    /**
     * Una fecha u hora sin su zona horaria (+02, +02:00, +0200, Z; con un
     * espacio delante, como la escribe sqlserver-to-jsonsqldb.ps1): aquí las
     * fechas no la llevan. La hora queda la que venía, y se avisa.
     */
    private function sinZona(string $v): string
    {
        if (!preg_match('/^((?:\d{4}-\d{2}-\d{2}[ T])?\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?) ?(?:Z|[+-]\d{2}(?::?\d{2}){0,2})$/i', $v, $m)) {
            return $v;
        }
        $this->avisar(t('Fechas con zona horaria: se ha quitado la zona; la hora queda como venía en el volcado'));
        return $m[1];
    }

    /**
     * Las tablas de PostgreSQL o SQL Server con alguna columna con zona
     * horaria (de la primera pasada, ver observar()): sus columnas en orden y
     * cuáles son esas, para quitarles la zona en los INSERT (2.9: solo se
     * quitaba en los COPY, y un INSERT de pg_dump --inserts o del script de
     * SQL Server paraba la importación).
     *
     * @return array<string, array{cols: list<string>, marcas: array<string, string>}>
     */
    private function conZona(): array
    {
        $out = [];
        foreach ($this->tablas as $tabla => $info) {
            $marcas = [];
            foreach ($info['tipos'] as $col => $tipo) {
                if (preg_match(self::CON_ZONA, (string)$tipo)) {
                    $marcas[strtolower((string)$col)] = 'zona';
                }
            }
            if ($marcas !== []) {
                $out[strtolower((string)$tabla)] = ['cols' => array_map('strval', array_keys($info['tipos'])), 'marcas' => $marcas];
            }
        }
        return $out;
    }

    /** Datos binarios en hexadecimal: texto si son UTF-8; si no, se guardan como '\x…' y se avisa. */
    private function binario(string $hex): string
    {
        $bin = (string)@hex2bin(strlen($hex) % 2 ? '0' . $hex : $hex);
        if ($bin !== '' && utf8Valido($bin)) {
            return $bin;
        }
        $this->avisar(t('Datos binarios (BLOB, bytea, image) que no son texto: se guardan como su hexadecimal, \\x…'));
        return '\\x' . strtolower($hex);
    }

    public function avisar(string $texto): void
    {
        $this->avisos[$texto] = ($this->avisos[$texto] ?? 0) + 1;
    }

    /**
     * Las sentencias de jsonSQLDB que corresponden a una del volcado: ninguna
     * si se salta, una, o varias (un CREATE TABLE con índices dentro).
     *
     * @return list<string>
     */
    public function traducir(string $sql): array
    {
        // En un COPY solo la primera línea es SQL; lo demás son sus datos
        $t = $this->tokens($this->pg && preg_match('/^COPY\b/i', $sql) ? explode(";\n", $sql, 2)[0] : $sql);
        if ($t === []) {
            return [];
        }
        $p1 = strtoupper($t[0]['v']);
        $p2 = strtoupper($t[1]['v'] ?? '');
        $p3 = strtoupper($t[2]['v'] ?? '');
        if ($this->ss && $p1 === 'SET' && $p2 === 'DATEFORMAT') {
            $this->formatoFecha = strtolower($t[2]['v'] ?? '');
            return [];
        }
        if ($this->mysql && $p1 === 'SET' && $p2 === 'SQL_MODE') {
            $this->tuberiasConcatenan = ($t[3]['k'] ?? '') === 'str' && preg_match('/\bPIPES_AS_CONCAT\b|(^|,)ANSI(,|$)/i', (string)$t[3]['v']) === 1;
        }
        if (in_array($p1, self::CONTROL, true) || (($this->pg || $this->ss || $this->access) && $p1 === 'SELECT')) {
            $this->avisar(t('Sentencias de control saltadas (PRAGMA, SET, LOCK, BEGIN, COMMIT…)'));
            return [];
        }
        $especial = $this->objetoEspecial($sql, $t, $p1, $p2);
        if ($especial !== null) {
            return $especial;
        }
        if ($this->pg && $p1 === 'COPY') {
            return $this->copiar($sql, $t);
        }
        if (in_array($p1, ['COMMENT', 'GRANT', 'REVOKE', 'IF', 'EXEC', 'EXECUTE', 'PRINT', 'DECLARE', 'DBCC', 'RAISERROR'], true)
            || ($p1 === 'CREATE' && in_array($p2, ['SEQUENCE', 'EXTENSION', 'SCHEMA', 'TYPE', 'DOMAIN', 'ROLE', 'USER', 'LOGIN'], true))
            || ($p1 === 'ALTER' && in_array($p2, ['SEQUENCE', 'SCHEMA', 'DATABASE', 'DEFAULT', 'ROLE', 'USER', 'AUTHORIZATION'], true))) {
            $this->avisar(t('Sentencias de administración saltadas (COMMENT, GRANT, IF, EXEC, CREATE SEQUENCE…)'));
            return [];
        }
        if ($p1 === 'DROP' && in_array($p2, ['VIEW', 'TRIGGER'], true) && !$this->sqliteOJson()) {
            // Una vista provisional de mysqldump (o un --clean de pg_dump) se
            // borra antes de crear la de verdad
            $k = strtoupper($t[2]['v'] ?? '') === 'IF' ? 4 : 2;
            return isset($t[$k]) ? ["DROP $p2 IF EXISTS " . ($p2 === 'VIEW' ? $this->nombreTabla((string)$t[$k]['v']) : $this->nombreObjeto((string)$t[$k]['v']))] : [];
        }
        if ($p1 === 'DROP' && in_array($p2, ['PROCEDURE', 'PROC', 'FUNCTION', 'SEQUENCE', 'TYPE', 'INDEX', 'SCHEMA'], true)) {
            return [];                              // lo que borra lo crea el propio volcado, y eso se salta
        }
        if ($this->mysql && $p1 === 'ALTER' && $p2 === 'TABLE' && preg_match('/\b(DISABLE|ENABLE) KEYS\s*;?$/i', $sql)) {
            return [];                              // /*!40000 ALTER TABLE t DISABLE KEYS */ de mysqldump
        }
        if ($p1 === 'DROP' && $p2 === 'TABLE') {
            // «IF EXISTS (…) DROP TABLE t» de SQL Server, partido en dos: el IF se
            // salta, y el DROP solo debe borrar si la tabla existe. Cada nombre,
            // sin chocar con otro (nombreTabla)
            $sentencias = [];
            foreach (array_slice($t, $p3 === 'IF' ? 4 : 2) as $x) {
                if ($x['k'] === 'id' && !in_array(strtoupper($x['v']), ['CASCADE', 'RESTRICT'], true) || !empty($x['q'])) {
                    $sentencias[] = 'DROP TABLE IF EXISTS ' . $this->nombreTabla((string)$x['v']);
                }
            }
            return $sentencias;
        }
        if ($p1 === 'ALTER' && $p2 === 'TABLE' && ($this->pg || $this->ss || $this->access)) {
            return $this->alterarTabla($t);
        }
        if ($this->ss && $p1 === 'INSERT' && $p2 !== 'INTO') {
            array_splice($t, 1, 0, [['k' => 'id', 'v' => 'INTO']]);  // INSERT [t] … de SQL Server
        }
        if ($this->access) {
            $t = $this->textosConChr($t, '&');
        }
        if ($this->mysql && ($p1 === 'INSERT' || $p1 === 'REPLACE')) {
            $t = $this->valores($t, $this->columnasMysql);
            $t = $this->upsertMysql($t);
        }
        if (($this->pg || $this->ss) && $p1 === 'INSERT' && ($this->columnasConZona ??= $this->conZona()) !== []) {
            $t = $this->valores($t, $this->columnasConZona);
        }
        // ALTER VIEW/FUNCTION/TYPE/SEQUENCE/SCHEMA … OWNER TO de pg_dump (sin
        // --no-owner) y demás ALTER que no son de una tabla: aquí no hay nada
        // que cambiar
        if ($p1 === 'ALTER' && $p2 !== 'TABLE') {
            $this->avisar(t('Sentencias de administración saltadas (COMMENT, GRANT, IF, EXEC, CREATE SEQUENCE…)'));
            return [];
        }
        if (($p1 === 'CREATE' || $p1 === 'DROP') && $p2 === 'DATABASE') {         // CREATE y DROP SCHEMA se saltan arriba
            if ($p1 === 'DROP') {
                throw new RuntimeException(t('El fichero intenta borrar una base de datos; eso no se importa.'));
            }
            $this->avisar(t('CREATE DATABASE saltado: se importa en la base elegida'));
            return [];
        }
        if ($this->tocaSecuencia($t)) {
            return [];                              // sqlite_sequence: su contador lo lleva el motor
        }
        if ($p1 === 'CREATE' && ($p2 === 'TABLE' || (in_array($p2, ['TEMP', 'TEMPORARY'], true) && $p3 === 'TABLE'))) {
            return $this->crearTabla($t);
        }
        if ($p1 === 'CREATE' && (in_array('INDEX', [$p2, $p3, strtoupper($t[3]['v'] ?? '')], true))) {
            return $this->crearIndice($t);
        }
        if ($p1 === 'CREATE' && !$this->sqliteOJson()) {
            return $this->rutina($t, $sql);
        }
        return [$this->texto($t)];
    }

    /** Los tokens de una sentencia conservando los ::tipo de PostgreSQL. */
    public function tokensConCasts(string $sql): array
    {
        $this->conCasts = true;
        try {
            return $this->tokens($sql);
        } finally {
            $this->conCasts = false;
        }
    }

    /**
     * Las columnas de una tabla del volcado con su tipo tal como venía, en
     * minúsculas (de la primera pasada; solo PostgreSQL y SQL Server).
     *
     * @return array<string,string>
     */
    public function columnas(string $tabla): array
    {
        foreach ($this->tablas as $t => $info) {
            if (strcasecmp((string)$t, $tabla) === 0) {
                return array_change_key_case(array_map('strtolower', $info['tipos']), CASE_LOWER);
            }
        }
        return [];
    }

    /** ¿Hay en el volcado una columna de texto con este nombre? (para el + de SQL Server) */
    public function esColumnaTexto(string $col): bool
    {
        return (bool)preg_grep('/CHAR|TEXT|STRING/i', $this->tiposDeColumna($col));
    }

    /**
     * Los tipos, tal como venían y en mayúsculas, de las columnas que se
     * llaman así en las tablas del volcado (de la primera pasada; solo
     * PostgreSQL y SQL Server): en $tabla, si es una del volcado; si no (un
     * alias, o nada), en todas.
     *
     * @return list<string>
     */
    public function tiposDeColumna(string $col, string $tabla = ''): array
    {
        $tipos = [];
        foreach ($this->tablas as $t => $info) {
            foreach ($info['tipos'] as $c => $tipo) {
                if (strcasecmp((string)$c, $col) === 0 && ($tabla === '' || strcasecmp((string)$t, $tabla) === 0)) {
                    $tipos[] = trim((string)$tipo, ' |');
                }
            }
        }
        return $tipos === [] && $tabla !== '' ? $this->tiposDeColumna($col) : $tipos;
    }

    /**
     * El nombre de una tabla o una vista, sin chocar con otra. Al sanearlo, dos
     * nombres distintos del volcado podían acabar en el mismo (ventas-2024 y
     * ventas_2024): la segunda tabla, con su DROP TABLE IF EXISTS, borraba la
     * primera. Ahora la segunda recibe un sufijo (_2, _3…) y se avisa.
     */
    public function nombreTabla(string $n): string
    {
        if (!isset($this->tablaDe[$n])) {
            $destino = trim($this->nombreObjeto($n), '"');
            $ya = $this->destinos[strtolower($destino)] ?? null;
            if ($ya !== null) {
                for ($i = 2; isset($this->destinos[strtolower($otro = substr($destino, 0, 64 - strlen("_$i")) . "_$i")]); $i++) {
                }
                $this->avisar(t("Nombre '{n}' cambiado a '{otro}': '{ya}' ya se llamaba '{destino}' aquí", ['n' => $n, 'otro' => $otro, 'ya' => $ya, 'destino' => $destino]));
                $destino = $otro;
            }
            $this->destinos[strtolower($destino)] = $n;
            $this->tablaDe[$n] = $destino;
        }
        return '"' . $this->tablaDe[$n] . '"';
    }

    /**
     * 'Primera' & Chr(13) & Chr(10) & 'línea': así escribe el volcado de Access un
     * texto con saltos de línea (el SQL de Access no tiene otra forma). Una
     * cadena de textos y Chr(n) unidos con $op (& en Access; + en SQL Server,
     * 2.9) pasa a ser un solo texto.
     */
    private function textosConChr(array $t, string $op): array
    {
        $out = [];
        for ($k = 0, $n = count($t); $k < $n; $k++) {
            $pieza = $this->piezaDeTexto($t, $k);
            if ($pieza === null || !TraductorRutinas::esOp($t[$pieza[1] + 1] ?? [], $op)) {
                $out[] = $t[$k];
                continue;
            }
            $texto = $pieza[0];
            $fin = $pieza[1];
            while (TraductorRutinas::esOp($t[$fin + 1] ?? [], $op) && ($sig = $this->piezaDeTexto($t, $fin + 2)) !== null) {
                $texto .= $sig[0];
                $fin = $sig[1];
            }
            if ($fin === $pieza[1]) {
                $out[] = $t[$k];                    // con algo que no es texto: se deja
                continue;
            }
            $out[] = ['k' => 'str', 'v' => $texto];
            $k = $fin;
        }
        return $out;
    }

    /** Un texto o un Chr(n) en $k: [su valor, dónde acaba], o null. */
    private function piezaDeTexto(array $t, int $k): ?array
    {
        $x = $t[$k] ?? null;
        if ($x === null) {
            return null;
        }
        if ($x['k'] === 'str') {
            return [(string)$x['v'], $k];
        }
        if ($x['k'] === 'id' && empty($x['q']) && strcasecmp((string)$x['v'], 'Chr') === 0 && TraductorRutinas::esOp($t[$k + 1] ?? [], '(')
            && ($t[$k + 2]['k'] ?? '') === 'num' && TraductorRutinas::esOp($t[$k + 3] ?? [], ')')) {
            return [caracterUtf8((int)$t[$k + 2]['v']), $k + 3];
        }
        return null;
    }

    /**
     * Un DEFAULT que es la fecha u hora de ahora, en cualquiera de sus formas
     * (CURRENT_TIMESTAMP, CURRENT_TIMESTAMP(6), now(), GETDATE(), Now() de
     * Access, CURRENT_DATE, CURDATE(), Date()…): CURRENT_TIMESTAMP,
     * CURRENT_DATE o CURRENT_TIME, que aquí se calculan al insertar. Null si es
     * otra cosa.
     */
    private static function fechaActual(array $tokens): ?string
    {
        $texto = '';
        foreach ($tokens as $x) {
            $texto .= strtolower((string)$x['v']);
        }
        while (preg_match('/^\((.*)\)$/s', $texto, $m)) {
            $texto = $m[1];                         // ((getdate())) de SQL Server
        }
        $texto = (string)preg_replace('/\(\d*\)$/', '', $texto);   // now(), CURRENT_TIMESTAMP(6)
        return [
            'current_timestamp' => 'CURRENT_TIMESTAMP', 'now' => 'CURRENT_TIMESTAMP', 'localtimestamp' => 'CURRENT_TIMESTAMP',
            'getdate' => 'CURRENT_TIMESTAMP', 'sysdatetime' => 'CURRENT_TIMESTAMP',
            'current_date' => 'CURRENT_DATE', 'curdate' => 'CURRENT_DATE', 'date' => 'CURRENT_DATE',
            'current_time' => 'CURRENT_TIME', 'curtime' => 'CURRENT_TIME', 'time' => 'CURRENT_TIME', 'localtime' => 'CURRENT_TIME',
            // La fecha de hoy como la escribe la exportación del panel en cada motor
            'date(now())' => 'CURRENT_DATE', 'cast(localtimestamp(0)asdate)' => 'CURRENT_DATE',
            'cast(cast(getdate()asdatetime2(0))asdate)' => 'CURRENT_DATE', 'datevalue(now())' => 'CURRENT_DATE',
            'cast(getdate()asdate)' => 'CURRENT_DATE',
        ][$texto] ?? null;
    }

    /**
     * INSERT IGNORE y ON DUPLICATE KEY UPDATE de MySQL, en la forma de aquí
     * (2.8): INSERT OR IGNORE, y ON CONFLICT DO UPDATE SET … con VALUES(col)
     * como excluded.col. Sin columnas tras ON CONFLICT, vale cualquier clave
     * única, que es lo que hace MySQL.
     */
    private function upsertMysql(array $t): array
    {
        if (strtoupper((string)($t[1]['v'] ?? '')) === 'IGNORE' && empty($t[1]['q'])) {
            array_splice($t, 1, 1, [['k' => 'id', 'v' => 'OR'], ['k' => 'id', 'v' => 'IGNORE']]);
        }
        $nivel = 0;
        for ($k = 0, $n = count($t); $k < $n; $k++) {
            $v = $t[$k]['v'];
            if ($v === '(' && $t[$k]['k'] === 'op') { $nivel++; continue; }
            if ($v === ')' && $t[$k]['k'] === 'op') { $nivel--; continue; }
            if ($nivel !== 0 || strtoupper((string)$v) !== 'ON' || strtoupper((string)($t[$k + 1]['v'] ?? '')) !== 'DUPLICATE') {
                continue;
            }
            // ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
            array_splice($t, $k, 4, [['k' => 'id', 'v' => 'ON'], ['k' => 'id', 'v' => 'CONFLICT'], ['k' => 'id', 'v' => 'DO'],
                                     ['k' => 'id', 'v' => 'UPDATE'], ['k' => 'id', 'v' => 'SET']]);
            for ($j = $k + 5; $j < count($t); $j++) {
                if (strtoupper((string)$t[$j]['v']) === 'VALUES' && TraductorRutinas::esOp($t[$j + 1] ?? [], '(') && TraductorRutinas::esOp($t[$j + 3] ?? [], ')')
                    && $t[$j + 2]['k'] === 'id') {
                    array_splice($t, $j, 4, [['k' => 'id', 'v' => 'excluded'], ['k' => 'op', 'v' => '.'], $t[$j + 2]]);
                }
            }
            break;
        }
        return $t;
    }

    /**
     * Los valores de un INSERT que aquí no se entienden tal cual:
     *  - MySQL: una columna BIT llega como sus bytes en crudo ('\x01', '\xAA'):
     *    pasa a ser el número que forman;
     *  - MySQL: una fecha «0000-00-00» (la fecha cero de MySQL) no existe: pasa
     *    a NULL;
     *  - PostgreSQL y SQL Server (2.9): la fecha de una columna con zona
     *    horaria (timestamptz, datetimeoffset) pierde la zona (ver sinZona()).
     *
     * @param array<string, array{cols: list<string>, marcas?: array<string, string>}> $porTabla
     *        las columnas de cada tabla, en orden, y las que son 'bit' o 'zona'
     */
    private function valores(array $t, array $porTabla): array
    {
        $k = strtoupper((string)($t[1]['v'] ?? '')) === 'INTO' ? 2 : 1;
        $info = $porTabla[strtolower((string)($t[$k]['v'] ?? ''))] ?? null;
        if ($info === null && !$this->mysql) {
            return $t;                              // una tabla sin columnas con zona: nada que hacer
        }
        $marcas = $info['marcas'] ?? [];
        // Con lista de columnas, su orden; si no, el del CREATE TABLE
        $cols = $info['cols'] ?? [];
        if (TraductorRutinas::esOp($t[$k + 1] ?? [], '(')) {
            $cols = [];
            for ($j = $k + 2; isset($t[$j]) && !($t[$j]['k'] === 'op' && $t[$j]['v'] === ')'); $j++) {
                if ($t[$j]['k'] === 'id') { $cols[] = (string)$t[$j]['v']; }
            }
        }
        $marca = array_map(static fn($c) => $marcas[strtolower($c)] ?? '', $cols);
        $valores = null;
        foreach ($t as $i => $x) {
            if (strtoupper((string)$x['v']) === 'VALUES' && empty($x['q'])) { $valores = $i; break; }
        }
        if ($valores === null) {
            return $t;
        }
        $nivel = 0;
        $pos = 0;
        for ($i = $valores + 1, $n = count($t); $i < $n; $i++) {
            $v = $t[$i]['v'];
            if ($t[$i]['k'] === 'op' && $v === '(') { $nivel++; if ($nivel === 1) { $pos = 0; } continue; }
            if ($t[$i]['k'] === 'op' && $v === ')') { $nivel--; continue; }
            if ($nivel === 1 && $t[$i]['k'] === 'op' && $v === ',') { $pos++; continue; }
            if ($nivel !== 1 || $t[$i]['k'] !== 'str') {
                continue;
            }
            $m = $marca[$pos] ?? '';
            if ($m === 'bit') {
                // Los bytes que mandó MySQL: si ese texto se pasó de Latin-1 a UTF-8, deshacerlo
                $bytes = !empty($t[$i]['latin1']) ? utf8ACp1252((string)$v) : (string)$v;
                $numero = 0;
                foreach (str_split($bytes) as $c) {
                    $numero = ($numero << 8) | ord($c);
                }
                $t[$i] = ['k' => 'num', 'v' => (string)$numero];
            } elseif ($m === 'zona') {
                $t[$i]['v'] = $this->fecha($this->sinZona((string)$v));
            } elseif ($this->mysql && preg_match(self::FECHA_CERO, (string)$v)) {
                $t[$i] = ['k' => 'id', 'v' => 'NULL'];
                $this->avisar(t('Fechas «0000-00-00» de MySQL importadas como NULL: aquí no existen'));
            }
        }
        return $t;
    }

    /** El cuerpo de una función de PostgreSQL guardada antes (CREATE FUNCTION). */
    public function funcion(string $nombre): ?string
    {
        return $this->funciones[strtolower($nombre)] ?? null;
    }

    /**
     * CREATE VIEW o CREATE TRIGGER de otro motor, traducido al SQL de aquí.
     * Las funciones y los procedimientos se saltan: aquí no existen. Lo que no
     * se puede traducir se salta y se dice por qué en el resumen.
     *
     * @return list<string>
     */
    private function rutina(array $t, string $sql): array
    {
        if ($this->pg) {
            $t = $this->tokensConCasts($sql);
        }
        // CREATE [OR REPLACE] [ALGORITHM=…] [DEFINER=…] [SQL SECURITY …] VIEW|TRIGGER …
        $objeto = '';
        foreach (array_slice($t, 1, 20) as $k => $x) {
            $u = empty($x['q']) ? strtoupper($x['v']) : '';
            if (in_array($u, ['VIEW', 'TRIGGER', 'FUNCTION', 'PROCEDURE', 'PROC', 'EVENT'], true)) {
                $objeto = $u;
                $t = array_merge([$t[0]], array_slice($t, $k + 1));
                break;
            }
        }
        $nombre = (string)($t[2]['v'] ?? '');
        $r = new TraductorRutinas($this, $this->mysql ? 'mysql' : ($this->pg ? 'postgresql' : ($this->access ? 'access' : 'sqlserver')));
        // Una función de PostgreSQL que devuelve trigger se guarda para el
        // trigger que la use; las demás no existen aquí
        if ($objeto === 'FUNCTION' && $this->pg) {
            $cuerpo = '';
            foreach ($t as $x) {
                if ($x['k'] === 'str' && strlen($x['v']) > strlen($cuerpo)) { $cuerpo = $x['v']; }
            }
            if (preg_match('/\bRETURNS\s+trigger\b/i', $sql)) {
                $this->funciones[strtolower($nombre)] = $cuerpo;
                return [];
            }
        }
        // Una vista o un trigger con algo que el traductor no espera se salta y
        // se dice, como lo que no tiene traducción: nunca para la importación
        // (ni un aviso de PHP por un índice que falta se queda en el camino)
        set_error_handler(static function (int $n, string $msg): bool {
            throw new NoTraducible($msg);
        }, E_WARNING | E_NOTICE);                  // no los avisos de obsoleto de versiones nuevas de PHP
        try {
            switch ($objeto) {
                case 'VIEW':
                    return [$r->vista($t)];
                case 'TRIGGER':
                    foreach ($r->trigger($t) as $trg) {
                        $this->triggers[] = $trg;
                    }
                    return [];
            }
        } catch (Throwable $e) {
            $this->avisar(t("{que} '{n}' sin importar: {motivo}", ['que' => $objeto === 'VIEW' ? t('Vista') : 'Trigger', 'n' => $nombre, 'motivo' => $e->getMessage()]));
            return [];
        } finally {
            restore_error_handler();
        }
        // Cada cosa con su nombre (2.9): antes todo lo que llegaba aquí se
        // contaba como «funciones y procedimientos», también lo que no lo era
        $this->avisar(match ($objeto) {
            'FUNCTION', 'PROCEDURE', 'PROC' => t('Funciones y procedimientos almacenados saltados: aquí no existen'),
            'EVENT'                         => t('Eventos programados de MySQL saltados: aquí no existen'),
            default                         => t('Sentencias sin equivalente aquí saltadas ({que})',
                ['que' => implode(' ', array_slice(array_map(static fn(array $x): string => strtoupper((string)$x['v']), $t), 0, 3))]),
        });
        return [];
    }

    // ------------------------------------------------------------------
    // Tokens
    // ------------------------------------------------------------------

    /**
     * Tokens de una sentencia: ['k' => tipo, 'v' => valor]. Tipos: id (nombre
     * o palabra), str (cadena, ya sin escapes), num, op (puntuación). Los
     * nombres entre comillas de cualquier tipo salen como id con 'q' => true.
     *
     * @return list<array{k: string, v: string, q?: bool}>
     */
    public function tokens(string $sql): array
    {
        $out = [];
        $n = strlen($sql);
        for ($i = 0; $i < $n;) {
            $c = $sql[$i];
            if (ctype_space($c)) { $i++; continue; }
            // Introductores de juego de caracteres de MySQL: _utf8mb4'…', _binary'…'.
            // Un _latin1 lo pone también Importar delante de cada texto que ha
            // tenido que pasar de Windows-1252 a UTF-8 (2.9): el texto lo dice
            // (ver valores())
            if ($c === '_' && preg_match('/\G_(utf8mb4|utf8|latin1|binary|ascii)\s*(?=\')/i', $sql, $m, 0, $i)) {
                $i += strlen($m[0]);
                [$valor, $i] = $this->cadena($sql, $i);
                $out[] = ['k' => 'str', 'v' => $valor] + (strtolower($m[1]) === 'latin1' ? ['latin1' => true] : []);
                continue;
            }
            // X'…' y x'…': hexadecimal
            if (($c === 'x' || $c === 'X') && ($sql[$i + 1] ?? '') === "'") {
                $fin = strpos($sql, "'", $i + 2);
                $out[] = $this->hexadecimal(substr($sql, $i + 2, (int)$fin - $i - 2));
                $i = (int)$fin + 1;
                continue;
            }
            // b'0101': bits
            if (($c === 'b' || $c === 'B') && ($sql[$i + 1] ?? '') === "'") {
                $fin = strpos($sql, "'", $i + 2);
                $out[] = ['k' => 'num', 'v' => (string)bindec(substr($sql, $i + 2, (int)$fin - $i - 2))];
                $i = (int)$fin + 1;
                continue;
            }
            if ($c === '0' && ($sql[$i + 1] ?? '') === 'x' && preg_match('/\G0x([0-9a-fA-F]+)/', $sql, $m, 0, $i)) {
                $out[] = $this->hexadecimal($m[1]);
                $i += strlen($m[0]);
                continue;
            }
            // N'…' (Unicode en SQL Server) y E'…' (con escapes, en PostgreSQL)
            if (($c === 'N' || $c === 'n' || $c === 'E' || $c === 'e') && ($sql[$i + 1] ?? '') === "'"
                && ($i === 0 || !ctype_alnum($sql[$i - 1]) && $sql[$i - 1] !== '_')) {
                [$valor, $i] = $this->cadena($sql, $i + 1, $c === 'E' || $c === 'e');
                $out[] = ['k' => 'str', 'v' => $valor];
                continue;
            }
            if ($c === "'") {
                [$valor, $i] = $this->cadena($sql, $i);
                $out[] = ['k' => 'str', 'v' => $valor];
                continue;
            }
            // $$…$$ y $etiqueta$…$etiqueta$ de PostgreSQL: el cuerpo de una función
            // #2026-03-01 10:00:00# (y #3/1/2026# de las consultas de Access): una fecha
            if ($this->access && $c === '#' && preg_match('/\G#([^#\n]{1,30})#/', $sql, $m, 0, $i)) {
                $f = trim($m[1]);
                if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})(.*)$#', $f, $p)) {
                    $anio = strlen($p[3]) === 2 ? (int)$p[3] + ((int)$p[3] < 30 ? 2000 : 1900) : (int)$p[3];
                    $f = sprintf('%04d-%02d-%02d', $anio, (int)$p[1], (int)$p[2]) . rtrim($p[4]);
                }
                $out[] = ['k' => 'str', 'v' => $this->fecha($f)];
                $i += strlen($m[0]);
                continue;
            }
            if ($this->pg && $c === '$' && preg_match('/\G(\$[A-Za-z_]*\$)/', $sql, $m, 0, $i)) {
                $fin = strpos($sql, $m[1], $i + strlen($m[1]));
                $fin = $fin === false ? $n : $fin;
                $out[] = ['k' => 'str', 'v' => substr($sql, $i + strlen($m[1]), $fin - $i - strlen($m[1]))];
                $i = $fin + strlen($m[1]);
                continue;
            }
            // En las consultas de Access, "…" es un texto, no un nombre
            if ($this->access && $c === '"') {
                $fin = $i + 1;
                $v = '';
                for (; $fin < $n; $fin++) {
                    if ($sql[$fin] === '"') {
                        if (($sql[$fin + 1] ?? '') === '"') { $v .= '"'; $fin++; continue; }
                        break;
                    }
                    $v .= $sql[$fin];
                }
                $out[] = ['k' => 'str', 'v' => $v];
                $i = $fin + 1;
                continue;
            }
            if ($c === '"' || $c === '`' || ($c === '[' && !$this->pg && !$this->mysql)) {
                $cierre = $c === '[' ? ']' : $c;
                $v = '';
                for ($i++; $i < $n; $i++) {
                    if ($sql[$i] === $cierre) {
                        if ($cierre !== ']' && ($sql[$i + 1] ?? '') === $cierre) { $v .= $cierre; $i++; continue; }
                        break;
                    }
                    $v .= $sql[$i];
                }
                $i++;
                $out[] = ['k' => 'id', 'v' => $v, 'q' => true];
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && ctype_digit($sql[$i + 1] ?? ''))) {
                preg_match('/\G\d*\.?\d+(?:[eE][+-]?\d+)?|\G\d+\.?/', $sql, $m, 0, $i);
                $out[] = ['k' => 'num', 'v' => $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (ctype_alpha($c) || $c === '_' || ord($c) >= 128) {
                preg_match('/\G[\p{L}\p{N}_$]+/u', $sql, $m, 0, $i);
                $palabra = $m[0] ?? $c;
                $out[] = ['k' => 'id', 'v' => $palabra];
                $i += strlen($palabra);
                continue;
            }
            // <=> de MySQL (igualdad que admite NULL), antes que <=
            if ($this->mysql && substr($sql, $i, 3) === '<=>') {
                $out[] = ['k' => 'op', 'v' => '<=>'];
                $i += 3;
                continue;
            }
            foreach (['<=', '>=', '<>', '!=', '||', '==', '::'] as $doble) {
                if (substr($sql, $i, 2) === $doble) { $out[] = ['k' => 'op', 'v' => $doble]; $i += 2; continue 2; }
            }
            $out[] = ['k' => 'op', 'v' => $c];
            $i++;
        }
        $out = $this->ajustar($out);
        // char(10) y char(13, 10) de los volcados de SQLite, y NCHAR(0) del
        // volcado para SQL Server (2.9): aquí no existen, así que se cambian
        // por el texto que dan. Solo en una expresión: el CHAR(10) de un tipo
        // se deja (2.9: code CHAR(10) pasaba a ser una columna sin tipo)
        $cambiados = false;
        for ($k = 0; $k < count($out); $k++) {
            if ($out[$k]['k'] !== 'id' || !empty($out[$k]['q'])) {
                continue;
            }
            $u = strtoupper($out[$k]['v']);
            if (($u !== 'CHAR' && $u !== 'NCHAR') || !TraductorRutinas::esOp($out[$k + 1] ?? [], '(') || self::esTipo($out, $k)) {
                continue;
            }
            $j = $k + 2;
            $texto = '';
            while (($out[$j]['k'] ?? '') === 'num') {
                $texto .= caracterUtf8((int)$out[$j]['v']);
                $j++;
                if (TraductorRutinas::esOp($out[$j] ?? [], ',')) { $j++; }
            }
            if (TraductorRutinas::esOp($out[$j] ?? [], ')')) {
                array_splice($out, $k, $j - $k + 1, [['k' => 'str', 'v' => $texto]]);
                $cambiados = true;
            }
        }
        // En SQL Server, N'a' + NCHAR(0) + N'b' es ya 'a' + '…' + 'b': un texto
        return $cambiados && $this->ss ? $this->textosConChr($out, '+') : $out;
    }

    /**
     * ¿Es un tipo el CHAR(…) de $t[$k]? Lo es detrás de AS (CAST), de ::, de
     * un nombre (la columna de un CREATE TABLE) o como primer argumento de
     * CONVERT; detrás de una palabra que empieza una expresión, no.
     */
    private static function esTipo(array $t, int $k): bool
    {
        $antes = $t[$k - 1] ?? ['k' => '', 'v' => ''];
        if ($antes['k'] === 'op') {
            return $antes['v'] === '::' || ($antes['v'] === '(' && ($t[$k - 2]['k'] ?? '') === 'id'
                && in_array(strtoupper((string)$t[$k - 2]['v']), ['CONVERT', 'TRY_CONVERT'], true));
        }
        return $antes['k'] === 'id' && (!empty($antes['q'])
            || !in_array(strtoupper((string)$antes['v']), ['SELECT', 'DISTINCT', 'WHEN', 'THEN', 'ELSE', 'AND', 'OR', 'NOT', 'LIKE', 'BETWEEN', 'BY', 'RETURN'], true));
    }

    /**
     * Cambios de dialecto sobre los tokens: quita el esquema de delante de los
     * nombres (dbo., public.) y los ::tipo de PostgreSQL, pasa TRUE y FALSE a
     * 1 y 0, deja CAST(valor AS tipo) en el valor y pone en orden año-mes-día
     * las fechas d/m/a de un SET DATEFORMAT.
     */
    private function ajustar(array $t): array
    {
        $out = [];
        $n = count($t);
        for ($k = 0; $k < $n; $k++) {
            $x = $t[$k];
            if ($x['k'] === 'id' && ($t[$k + 1]['v'] ?? '') === '.' && $t[$k + 1]['k'] === 'op' && ($t[$k + 2]['k'] ?? '') === 'id'
                && isset($this->esquemas[strtolower((string)$x['v'])]) && !TraductorRutinas::esOp($t[$k - 1] ?? [], '.')) {
                $k++;                                   // ventas.tabla → tabla
                continue;
            }
            if ($x['k'] === 'op' && $x['v'] === '::' && !$this->conCasts) {
                // ::tipo, ::character varying, ::numeric(12,2), ::text[]
                while (($t[$k + 1]['k'] ?? '') === 'id' && empty($t[$k + 1]['q'])) { $k++; }
                if (TraductorRutinas::esOp($t[$k + 1] ?? [], '(')) { $k = $this->saltarExpresion($t, $k + 1) - 1; }
                while (TraductorRutinas::esOp($t[$k + 1] ?? [], '[') || TraductorRutinas::esOp($t[$k + 1] ?? [], ']')) { $k++; }
                continue;
            }
            if (!$this->sqliteOJson() && $x['k'] === 'id' && empty($x['q']) && in_array(strtoupper($x['v']), ['TRUE', 'FALSE'], true)) {
                $out[] = ['k' => 'num', 'v' => strtoupper($x['v']) === 'TRUE' ? '1' : '0'];
                continue;
            }
            if (!$this->sqliteOJson() && $x['k'] === 'id' && empty($x['q']) && strtoupper($x['v']) === 'CAST'
                && TraductorRutinas::esOp($t[$k + 1] ?? [], '(') && in_array($t[$k + 2]['k'] ?? '', ['str', 'num'], true)
                && strtoupper($t[$k + 3]['v'] ?? '') === 'AS') {
                $valor = $t[$k + 2];
                $tipo = strtoupper($t[$k + 4]['v'] ?? '');
                if ($valor['k'] === 'str' && preg_match('/DATE|TIME/', $tipo)) {
                    $valor['v'] = (string)preg_replace('/^(\d{4}-\d{2}-\d{2})T/', '$1 ', $valor['v']);
                    // CAST(N'…+02:00' AS DateTimeOffset) de Management Studio, sin la zona (2.9)
                    $valor['v'] = $this->fecha($tipo === 'DATETIMEOFFSET' ? $this->sinZona($valor['v']) : $valor['v']);
                }
                $out[] = $valor;
                $k = $this->saltarExpresion($t, $k + 1) - 1;
                continue;
            }
            if ($x['k'] === 'str') {
                $x['v'] = $this->fecha($x['v']);
            }
            if ($x['k'] === 'str' && $this->formatoFecha !== '' && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(.*)$#', $x['v'], $m)) {
                [$a, $b] = $this->formatoFecha === 'dmy' ? [$m[2], $m[1]] : [$m[1], $m[2]];
                $x['v'] = sprintf('%s-%02d-%02d%s', $m[3], (int)$a, (int)$b, $m[4]);
            }
            $out[] = $x;
        }
        return $out;
    }

    /** Una cadena entre comillas simples desde $i; en MySQL y en E'…', con sus escapes de barra invertida. */
    private function cadena(string $sql, int $i, bool $escapes = false): array
    {
        $v = '';
        $n = strlen($sql);
        for ($i++; $i < $n; $i++) {
            $c = $sql[$i];
            if (($this->mysql || $escapes) && $c === '\\' && $i + 1 < $n) {
                $s = $sql[++$i];
                $v .= ['0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1A"][$s] ?? $s;
                continue;
            }
            if ($c === "'") {
                if (($sql[$i + 1] ?? '') === "'") { $v .= "'"; $i++; continue; }
                return [$v, $i + 1];
            }
            $v .= $c;
        }
        throw new RuntimeException(t('Una cadena sin cerrar en el fichero.'));
    }

    /** Un literal hexadecimal: texto si es UTF-8 válido; si son datos binarios, no se pueden guardar. */
    private function hexadecimal(string $hex): array
    {
        return ['k' => 'str', 'v' => $this->binario($hex)];
    }

    /**
     * Lo que no tiene equivalente aquí y se resuelve antes de traducir (2.9),
     * o null si la sentencia no es nada de eso:
     *  - SQLite: las tablas virtuales (FTS, R-Tree…), que .dump crea con un
     *    INSERT en sqlite_schema, se saltan con sus tablas auxiliares
     *    (busca_content, busca_data…); antes la importación se paraba en
     *    «La tabla 'sqlite_schema' no existe».
     *  - PostgreSQL: una tabla particionada se crea entera en su tabla madre:
     *    las particiones (PARTITION OF, ATTACH PARTITION) no se crean, sus
     *    filas van a la madre y sus claves e índices se saltan.
     *  - PostgreSQL: una vista materializada se crea como vista normal, que
     *    aquí se calcula al consultarla; REFRESH no hace falta y sus índices
     *    se saltan.
     *
     * @return list<string>|null
     */
    private function objetoEspecial(string $sql, array &$t, string $p1, string $p2): ?array
    {
        $nombres = array_map(static fn(array $x): string => strtolower((string)$x['v']), $t);
        if (in_array('sqlite_schema', $nombres, true) || in_array('sqlite_master', $nombres, true) || ($p1 === 'CREATE' && $p2 === 'VIRTUAL')) {
            if (preg_match('/CREATE\s+VIRTUAL\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["\'`\[]?(\w+)/i', $sql, $m)) {
                $this->virtuales[strtolower($m[1])] = true;
            }
            $this->avisar(t('Tablas virtuales de SQLite (FTS, R-Tree…) saltadas, con sus tablas auxiliares: aquí no existen'));
            return [];
        }
        $objeto = $this->objetivo($t);
        if ($this->virtuales !== [] && $objeto !== null
            && preg_match('/^(.+)_(content|segments|segdir|docsize|stat|data|idx|config)$/', strtolower($objeto), $m) && isset($this->virtuales[$m[1]])) {
            return [];
        }
        if (!$this->pg) {
            return null;
        }
        if ($p1 === 'REFRESH' || ($p1 === 'ALTER' && in_array($p2, ['MATERIALIZED', 'INDEX'], true))) {
            return [];                                     // REFRESH, OWNER TO, ATTACH PARTITION de un índice
        }
        if (($p1 === 'CREATE' || $p1 === 'DROP') && $p2 === 'MATERIALIZED') {
            if ($p1 === 'CREATE') {
                $this->materializadas[strtolower((string)($nombres[3] ?? ''))] = true;
                $this->avisar(t('Vistas materializadas de PostgreSQL creadas como vistas normales: aquí se calculan cada vez que se consultan'));
            }
            return $this->traducir((string)preg_replace(['/\bMATERIALIZED\s+/i', '/\s+WITH\s+(NO\s+)?DATA\s*;?\s*$/i'], ['', ''], $sql, 1));
        }
        if ($this->particion($t)) {
            $this->avisar(t('Particiones de PostgreSQL: no se crean; sus filas van a su tabla madre'));
            return [];
        }
        $w = implode(' ', array_map('strtoupper', $nombres));
        $madre = $this->particiones[strtolower((string)$objeto)] ?? null;
        if ($madre !== null) {
            if ($p1 !== 'COPY' && $p1 !== 'INSERT') {
                return [];                                 // las claves y los índices de la partición: los tiene la madre
            }
            foreach ($t as $k => $x) {
                if ($x['k'] === 'id' && strcasecmp((string)$x['v'], (string)$objeto) === 0) {
                    $t[$k]['v'] = $madre;                  // sus filas, a la madre
                    break;
                }
            }
            return null;
        }
        return $p1 === 'CREATE' && isset($this->materializadas[strtolower((string)$objeto)]) && str_contains($w, 'INDEX') ? [] : null;
    }

    /**
     * Si la sentencia hace de una tabla la partición de otra (CREATE TABLE x
     * PARTITION OF madre, o ALTER TABLE madre ATTACH PARTITION x), lo apunta
     * y devuelve true. Se mira también en la primera pasada (observar()): una
     * partición creada como tabla suelta y adjuntada después no debe crearse.
     */
    private function particion(array $t): bool
    {
        $w = array_map(static fn(array $x): string => empty($x['q']) ? strtoupper((string)$x['v']) : '', $t);
        $de = array_search('OF', $w, true);
        if (($w[0] ?? '') === 'CREATE' && $de !== false && ($w[$de - 1] ?? '') === 'PARTITION') {
            $this->particiones[strtolower((string)$this->objetivo($t))] = (string)$t[$de + 1]['v'];
            return true;
        }
        $parte = array_search('PARTITION', $w, true);
        if (($w[0] ?? '') === 'ALTER' && $parte !== false && ($w[$parte - 1] ?? '') === 'ATTACH') {
            $this->particiones[strtolower((string)$t[$parte + 1]['v'])] = (string)$this->objetivo($t);
            return true;
        }
        return false;
    }

    /**
     * La tabla sobre la que trabaja una sentencia, o null: CREATE TABLE x,
     * INSERT INTO x, COPY x, ALTER TABLE [ONLY] x, CREATE INDEX … ON [ONLY] x.
     */
    private function objetivo(array $t): ?string
    {
        $w = array_map(static fn(array $x): string => empty($x['q']) ? strtoupper((string)$x['v']) : '', $t);
        $tras = static function (string $palabra) use ($t, $w): ?string {
            $i = array_search($palabra, $w, true);
            if ($i === false) {
                return null;
            }
            $i++;
            while (in_array($w[$i] ?? '', ['IF', 'NOT', 'EXISTS', 'ONLY'], true)) {
                $i++;
            }
            return isset($t[$i]) ? (string)$t[$i]['v'] : null;
        };
        switch ($w[0] ?? '') {
            case 'COPY':   return isset($t[1]) ? (string)$t[1]['v'] : null;
            case 'INSERT': return $tras('INTO');
            case 'ALTER':  return ($w[1] ?? '') === 'TABLE' ? $tras('TABLE') : null;
            case 'CREATE': return in_array('INDEX', array_slice($w, 1, 2), true) ? $tras('ON') : $tras('TABLE');
        }
        return null;
    }

    /** ¿Es una sentencia sobre sqlite_sequence? */
    private function tocaSecuencia(array $t): bool
    {
        foreach (array_slice($t, 0, 6) as $tk) {
            if ($tk['k'] === 'id' && strtolower($tk['v']) === 'sqlite_sequence') {
                return true;
            }
        }
        return false;
    }

    /**
     * Los tokens otra vez como texto, en el SQL de aquí. Un nombre entre
     * comillas que no vale tal cual como el de una tabla, o el de una tabla
     * que aquí se llama de otro modo, depende de dónde va: de una tabla si lo
     * dice marcarTablas() (y delante de un punto, si la hay con ese nombre),
     * y si no, de una columna. Las marcas, solo entonces: cuestan.
     */
    public function texto(array $t): string
    {
        $out = '';
        $antes = null;
        $marcas = null;
        foreach ($t as $i => $tk) {
            if ($tk['k'] === 'str') {
                $s = "'" . str_replace("'", "''", $tk['v']) . "'";
            } elseif ($tk['k'] === 'id' && !empty($tk['q'])) {
                $v = (string)$tk['v'];
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $v) && ($this->tablaDe[$v] ?? $v) === $v) {
                    $s = '"' . $v . '"';
                } else {
                    $es = $tk['es'] ?? (count($t) > 1 ? ($marcas ??= $this->marcarTablas($t))[$i] ?? '' : '');
                    $s = $es === 'tabla' || ($es === 'calif' && isset($this->tablaDe[$v])) ? $this->nombreObjeto($v) : $this->nombre($v);
                }
            } else {
                $s = $tk['v'];
            }
            $pegado = $antes === null || in_array($s, [',', ')', ';'], true) || $antes === '(' || $antes === '.' || $s === '.';
            $out .= ($pegado ? '' : ' ') . $s;
            $antes = $s;
        }
        return $out;
    }

    /**
     * Dónde van los nombres de tabla de una sentencia: tras FROM, JOIN, INTO,
     * UPDATE o TABLE, y en la lista de un FROM con comas ('tabla'), y delante
     * de un punto, de una tabla o de un alias ('calif'). Los demás son
     * columnas, que guardan su nombre (2.9; ver texto()).
     *
     * @param list<array> $t
     * @return array<int, string> posición => 'tabla' o 'calif'
     */
    public function marcarTablas(array $t): array
    {
        // INSERT INTO t [(columnas)] VALUES …, cada fila de un volcado: solo t
        // es una tabla, y se ve sin recorrer las columnas (buscado en C)
        if (strtoupper((string)($t[0]['v'] ?? '')) === 'INSERT' && strtoupper((string)($t[1]['v'] ?? '')) === 'INTO') {
            $values = ($t[3]['v'] ?? '') === '(' ? array_search(')', array_column($t, 'v'), true) + 1 : 3;
            if (strtoupper((string)($t[$values]['v'] ?? '')) === 'VALUES') {
                return [2 => 'tabla'];
            }
        }
        $marcas = [];
        $enFrom = [];                               // por nivel de paréntesis
        $nivel = 0;
        $antes = '';                                // la palabra clave o el signo de antes
        foreach ($t as $k => $x) {
            if ($x['k'] === 'op') {
                $nivel += $x['v'] === '(' ? 1 : ($x['v'] === ')' ? -1 : 0);
                unset($enFrom[$nivel + 1]);
                $antes = $x['v'];
                continue;
            }
            $u = $x['k'] === 'id' && empty($x['q']) ? strtoupper((string)$x['v']) : '';
            if ($u === 'VALUES' && $nivel === 0) {
                break;                              // las filas de un INSERT: ahí no hay tablas
            }
            if ($u === 'FROM') {
                $enFrom[$nivel] = true;
            } elseif (isset(self::FIN_FROM[$u])) {
                unset($enFrom[$nivel]);
            } elseif ($x['k'] === 'id' && (isset(self::TRAS_TABLA[$antes]) || ($antes === ',' && isset($enFrom[$nivel])))) {
                $marcas[$k] = 'tabla';
            } elseif ($x['k'] === 'id' && ($t[$k + 1]['v'] ?? '') === '.' && $t[$k + 1]['k'] === 'op') {
                $marcas[$k] = 'calif';
            }
            $antes = $u;
        }
        return $marcas;
    }

    // ------------------------------------------------------------------
    // CREATE TABLE
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function crearTabla(array $t): array
    {
        // Cabecera hasta el paréntesis: CREATE [TEMP] TABLE [IF NOT EXISTS] nombre (
        $abre = null;
        foreach ($t as $k => $tk) {
            if ($tk['k'] === 'op' && $tk['v'] === '(') { $abre = $k; break; }
        }
        if ($abre === null) {
            return [$this->texto($t)];                       // CREATE TABLE … AS SELECT: tal cual
        }
        $cabecera = array_values(array_filter(array_slice($t, 0, $abre),
            static fn(array $x): bool => !in_array(strtoupper($x['v']), ['TEMP', 'TEMPORARY'], true) || !empty($x['q'])));
        $tabla = $t[$abre - 1]['v'];
        // La misma tabla en dos esquemas: sin esquemas aquí, se juntarían sus filas
        $suyos = array_keys($this->esquemasDeTabla[strtolower((string)$tabla)] ?? []);
        if (count($suyos) > 1) {
            throw new RuntimeException(t('La tabla «{tabla}» está en varios esquemas ({esquemas}) y aquí no hay esquemas: se mezclarían sus filas. Vuelca e importa cada esquema por separado (pg_dump -n esquema).',
                ['tabla' => (string)$tabla, 'esquemas' => implode(', ', $suyos)]));
        }
        $this->nombreTabla($tabla);                 // registrada: ningún otro nombre acabará en el suyo
        // Definiciones separadas por comas de primer nivel, hasta el paréntesis que cierra
        $defs = [[]];
        $nivel = 0;
        $cierre = count($t);
        for ($k = $abre + 1; $k < count($t); $k++) {
            $tk = $t[$k];
            if ($tk['k'] === 'op' && $tk['v'] === '(') { $nivel++; }
            if ($tk['k'] === 'op' && $tk['v'] === ')') {
                if ($nivel === 0) { $cierre = $k; break; }
                $nivel--;
            }
            if ($nivel === 0 && $tk['k'] === 'op' && $tk['v'] === ',') { $defs[] = []; continue; }
            $defs[count($defs) - 1][] = $tk;
        }
        $detras = array_values(array_filter(array_slice($t, $cierre + 1), static fn(array $x): bool => !TraductorRutinas::esOp($x, ';')));
        if ($detras !== []) {
            $this->avisar(t('Opciones de tabla quitadas (ENGINE, CHARSET, WITHOUT ROWID…)'));
        }
        // La colación de la tabla en MySQL (2.9): la tienen sus columnas de
        // texto que no dicen otra (ver columna())
        $colacionTabla = $this->mysql ? self::colacionDeTabla($detras) : null;

        $columnas = [];
        $restricciones = [];
        $indices = [];
        $autoinc = null;
        $pkTabla = null;
        foreach ($defs as $d) {
            if ($d !== [] && (!empty($d[0]['q']) || !in_array(strtoupper($d[0]['v']), ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'FOREIGN', 'CHECK'], true))) {
                $this->columnasDe[strtolower((string)$tabla)][strtolower((string)$d[0]['v'])] = true;
            }
        }
        foreach ($defs as $d) {
            if ($d === []) { continue; }
            $w = strtoupper($d[0]['v']);
            $esRestriccion = empty($d[0]['q']) && in_array($w, ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'FOREIGN', 'CHECK'], true);
            if (!$esRestriccion) {
                if ($this->mysql) {
                    $this->columnasMysql[strtolower($tabla)]['cols'][] = (string)$d[0]['v'];
                    if (strtoupper((string)($d[1]['v'] ?? '')) === 'BIT') {
                        $this->columnasMysql[strtolower($tabla)]['marcas'][strtolower((string)$d[0]['v'])] = 'bit';
                    }
                }
                [$col, $auto] = $this->columna($tabla, $d, $colacionTabla);
                $columnas[] = $col;
                if ($auto) { $autoinc = $d[0]['v']; }
                continue;
            }
            $nombre = null;
            if ($w === 'CONSTRAINT') {
                $nombre = $d[1]['v'] ?? null;
                $d = array_slice($d, 2);
                $w = strtoupper($d[0]['v'] ?? '');
            }
            if ($w === 'PRIMARY') {
                $pkTabla = $this->lista($d);
                $restricciones[] = 'PRIMARY KEY (' . implode(', ', array_map([$this, 'nombre'], $pkTabla)) . ')';
            } elseif ($w === 'UNIQUE') {
                // UNIQUE [KEY|INDEX] [nombre] (columnas)
                $resto = array_slice($d, 1);
                if (in_array(strtoupper($resto[0]['v'] ?? ''), ['KEY', 'INDEX'], true)) { $resto = array_slice($resto, 1); }
                if (($resto[0]['v'] ?? '') !== '(' && ($resto[0]['k'] ?? '') === 'id') { $nombre ??= $resto[0]['v']; }
                $cols = $this->columnasDeIndice($resto);              // UNIQUE KEY ((lower(x))) de MySQL 8
                if ($cols === null) {
                    continue;
                }
                $restricciones[] = ($nombre !== null ? 'CONSTRAINT ' . $this->nombreObjeto($nombre) . ' ' : '')
                                 . 'UNIQUE (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ')';
            } elseif ($w === 'KEY' || $w === 'INDEX') {
                $idx = ($d[1]['k'] ?? '') === 'id' ? $d[1]['v'] : 'ix_' . $tabla . '_' . (count($indices) + 1);
                $cols = $this->columnasDeIndice($d);
                if ($cols === null) {
                    continue;
                }
                $indices[] = 'CREATE INDEX ' . $this->nombreObjeto($idx) . ' ON ' . $this->nombreObjeto($tabla)
                           . ' (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ')';
            } elseif ($w === 'FOREIGN') {
                $this->aplazar($tabla, $nombre, $d);
            } elseif ($w === 'FULLTEXT' || $w === 'SPATIAL') {
                $this->avisar(t('Índices FULLTEXT y SPATIAL quitados: aquí no existen'));
            } else {
                [$sql] = $this->check($tabla, $d, 1);                 // CHECK (2.9)
                if ($sql !== null) {
                    $restricciones[] = ($nombre !== null ? 'CONSTRAINT ' . $this->nombreObjeto($nombre) . ' ' : '') . 'CHECK ' . $sql;
                }
            }
        }
        // Lo que PostgreSQL y SQL Server declaran fuera (ver observar())
        $fuera = $this->tablas[$tabla] ?? null;
        if ($fuera !== null) {
            if ($fuera['pk'] !== [] && $pkTabla === null && !preg_grep('/ PRIMARY KEY/', $columnas)) {
                $pkTabla = $fuera['pk'];
                $restricciones[] = 'PRIMARY KEY (' . implode(', ', array_map([$this, 'nombre'], $pkTabla)) . ')';
            }
            foreach ($columnas as $k => $c) {
                foreach ($fuera['auto'] as $col => $_) {
                    if (stripos($c, $this->nombre($col) . ' ') === 0 && strpos($c, 'AUTOINCREMENT') === false) {
                        $columnas[$k] .= ' AUTOINCREMENT';
                        $autoinc = $col;
                    }
                }
                foreach ($fuera['defecto'] as $col => $lit) {
                    if (stripos($c, $this->nombre($col) . ' ') === 0 && strpos($c, ' DEFAULT ') === false) {
                        $columnas[$k] .= ' DEFAULT ' . $lit;
                    }
                }
            }
        }
        // AUTOINCREMENT con la clave primaria declarada aparte: aquí va en la columna
        if ($autoinc !== null && $pkTabla !== null && count($pkTabla) === 1 && strcasecmp($this->nombre($pkTabla[0]), $this->nombre($autoinc)) === 0) {
            $restricciones = array_values(array_filter($restricciones, static fn(string $r): bool => strpos($r, 'PRIMARY KEY (') !== 0));
            foreach ($columnas as $k => $c) {
                if (strpos($c, $this->nombre($autoinc) . ' ') === 0) {
                    $columnas[$k] = str_replace(' AUTOINCREMENT', ' PRIMARY KEY AUTOINCREMENT', $c);
                }
            }
        }
        $sql = $this->texto($cabecera) . ' (' . implode(', ', array_merge($columnas, $restricciones)) . ')';
        return array_merge([$sql], $indices);
    }

    /**
     * Una columna: nombre, tipo traducido y sus restricciones. Devuelve el
     * texto y si es AUTOINCREMENT. $colacionTabla: la de la tabla en MySQL,
     * que es la de sus columnas de texto que no dicen otra (2.9).
     *
     * @return array{0: string, 1: bool}
     */
    private function columna(string $tabla, array $d, ?string $colacionTabla = null): array
    {
        $nombre = $d[0]['v'];
        // El tipo: palabras y su paréntesis, hasta la primera restricción
        $tipo = [];
        $k = 1;
        while ($k < count($d) && !(empty($d[$k]['q']) && $d[$k]['k'] === 'id' && in_array(strtoupper($d[$k]['v']), self::RESTRICCIONES, true)
            // CHARACTER es un tipo (character varying) salvo en CHARACTER SET
            && !(strtoupper($d[$k]['v']) === 'CHARACTER' && strtoupper($d[$k + 1]['v'] ?? '') !== 'SET'))) {
            $tipo[] = $d[$k];
            $k++;
        }
        $partes = [$this->nombre($nombre)];
        $t = $this->tipo($tipo);
        if ($t !== '') { $partes[] = $t; }
        // citext de PostgreSQL: texto que no distingue mayúsculas (2.9)
        $colacion = strtoupper((string)($tipo[0]['v'] ?? '')) === 'CITEXT' ? $this->colacion('citext') : null;
        $propia   = false;                 // ¿dice la columna su COLLATE?
        $colacionDelJuego = null;          // la de su CHARACTER SET, si lo dice sin COLLATE
        $fechaCero = false;                // ¿DEFAULT '0000-00-00'? (ver abajo)
        // SERIAL de PostgreSQL: un entero con autoincremento
        $auto = (bool)preg_match('/^(SMALL|BIG)?SERIAL\d?$/i', (string)($tipo[0]['v'] ?? ''))
            || ($this->access && preg_match('/^(COUNTER|AUTOINCREMENT)$/i', (string)($tipo[0]['v'] ?? '')));
        if ($auto) { $partes[] = 'AUTOINCREMENT'; }
        while ($k < count($d)) {
            $w = strtoupper($d[$k]['v']);
            if ($w === 'NOT' && strtoupper($d[$k + 1]['v'] ?? '') === 'NULL') { $partes[] = 'NOT NULL'; $k += 2; continue; }
            if ($w === 'NULL') { $k++; continue; }
            if ($w === 'PRIMARY') {
                $partes[] = 'PRIMARY KEY';
                $k += 2;
                if (in_array(strtoupper($d[$k]['v'] ?? ''), ['ASC', 'DESC'], true)) { $k++; }
                continue;
            }
            if ($w === 'UNIQUE') { $partes[] = 'UNIQUE'; $k++; if (strtoupper($d[$k]['v'] ?? '') === 'KEY') { $k++; } continue; }
            if ($w === 'KEY') { $partes[] = 'PRIMARY KEY'; $k++; continue; }
            if ($w === 'AUTO_INCREMENT' || $w === 'AUTOINCREMENT' || $w === 'IDENTITY') {
                if (!$auto) { $partes[] = 'AUTOINCREMENT'; }
                $auto = true;
                $k++;
                if (TraductorRutinas::esOp($d[$k] ?? [], '(')) { $k = $this->saltarExpresion($d, $k); }   // IDENTITY(1,1)
                continue;
            }
            if ($w === 'DEFAULT') {
                $fin = $this->saltarExpresion($d, $k + 1);
                // DEFAULT -1 son dos tokens: el signo y el número
                if (TraductorRutinas::esOp($d[$k + 1] ?? [], '-') && ($d[$k + 2]['k'] ?? '') === 'num') { $fin = $k + 3; }
                $lit = $this->literalDe(array_slice($d, $k + 1, $fin - $k - 1));
                $ahora = $lit === null ? self::fechaActual(array_slice($d, $k + 1, $fin - $k - 1)) : null;
                if ($lit !== null && preg_match(self::FECHA_CERO, trim($lit, "'"))) {
                    $fechaCero = true;                      // 2.9
                } elseif ($lit !== null) {
                    $partes[] = 'DEFAULT ' . $lit;
                } elseif ($ahora !== null) {
                    $partes[] = 'DEFAULT ' . $ahora;        // 2.8: la fecha de ahora, al insertar
                } elseif (preg_match('/^NEXTVAL\b/i', (string)($d[$k + 1]['v'] ?? ''))) {
                    if (!$auto) { $partes[] = 'AUTOINCREMENT'; }
                    $auto = true;
                } else {
                    $this->avisar(t('Valores por defecto calculados quitados: aquí valen los literales y la fecha u hora actual (CURRENT_TIMESTAMP, NOW()…)'));
                }
                $k = $fin;
                continue;
            }
            if ($w === 'REFERENCES') {
                $this->aplazar($tabla, null, array_merge([['k' => 'id', 'v' => 'FOREIGN'], ['k' => 'id', 'v' => 'KEY'],
                    ['k' => 'op', 'v' => '('], $d[0], ['k' => 'op', 'v' => ')']], array_slice($d, $k)));
                break;
            }
            if ($w === 'COLLATE') {
                // pg_catalog."default": el nombre es lo último
                $k++;
                while (TraductorRutinas::esOp($d[$k + 1] ?? [], '.') && isset($d[$k + 2])) { $k += 2; }
                $colacion = $this->colacion((string)($d[$k]['v'] ?? ''));
                $propia   = true;
                $k++;
                continue;
            }
            if ($w === 'CHARSET' || $w === 'CHARACTER') {
                $k += $w === 'CHARSET' ? 1 : 2;
                $this->avisar(t('CHARACTER SET quitado de las columnas: aquí todo el texto es UTF-8'));
                // Sin COLLATE, la de ese juego de caracteres: la binaria con binary, y si no, una _ci
                if (!$propia) {
                    $colacionDelJuego = strtolower((string)($d[$k]['v'] ?? '')) === 'binary' ? 'binary' : 'x_ci';
                }
                $k++;
                continue;
            }
            if ($w === 'COMMENT') { $k += 2; continue; }
            if ($w === 'ON' && strtoupper($d[$k + 1]['v'] ?? '') === 'UPDATE') {
                $this->avisar(t('ON UPDATE CURRENT_TIMESTAMP quitado: aquí no existe'));
                $k = $this->saltarExpresion($d, $k + 2);
                continue;
            }
            if ($w === 'CHECK') {
                [$sql, $k] = $this->check($tabla, $d, $k + 1);       // 2.9
                if ($sql !== null) {
                    $partes[] = 'CHECK ' . $sql;
                }
                continue;
            }
            if ($w === 'GENERATED' || $w === 'AS') {
                $this->avisar(t('Columnas generadas: se importan como columnas normales, con los valores del volcado'));
                $k = $this->saltarExpresion($d, $k + ($w === 'GENERATED' ? 3 : 1));
                continue;
            }
            if ($w === 'CONSTRAINT') { $k += 2; continue; }
            $k++;                                          // VISIBLE, STORED, VIRTUAL, ROWGUIDCOL, ASC…
        }
        // DEFAULT '0000-00-00' de MySQL (2.9): esa fecha aquí no existe y paraba
        // la importación en el CREATE TABLE. Las filas que la traen la guardan
        // como NULL (ver valores()): la columna se queda sin ese valor por
        // defecto y admite NULL, para que entren. El rowversion de SQL Server
        // (2.9) también admite NULL: sus valores los pone SQL Server, y ni el
        // script de Management Studio ni sqlserver-to-jsonsqldb.ps1 los traen
        $rowversion = $this->ss && in_array(strtoupper((string)($tipo[0]['v'] ?? '')), ['TIMESTAMP', 'ROWVERSION'], true);
        if ($fechaCero || $rowversion) {
            $partes = array_values(array_diff($partes, ['NOT NULL']));
        }
        if ($fechaCero) {
            $this->avisar(t('Valores por defecto «0000-00-00» de MySQL quitados, y sus columnas admiten NULL: esa fecha aquí no existe, y las filas que la traen la guardan como NULL'));
        }
        // Sin una suya, la de su juego de caracteres o la de la tabla, en las de
        // texto de MySQL (no en JSON, BLOB ni BINARY, que son binarias allí)
        if (!$propia && in_array(strtoupper((string)($tipo[0]['v'] ?? '')), self::TEXTOS_MYSQL, true)) {
            $deTabla = $colacionDelJuego ?? $colacionTabla;
            if ($deTabla !== null) {
                $colacion = $this->colacion($deTabla);
            }
        }
        // Solo en las de texto, como aquí
        if ($colacion !== null && !in_array(explode('(', $t)[0], ['INTEGER', 'DOUBLE', 'DECIMAL', 'DATETIME'], true)) {
            $partes[] = 'COLLATE ' . $colacion;
        }
        // Un autonumérico de Access que no es la clave primaria: aquí el
        // autoincremento solo existe en ella
        if ($this->access && $auto && !in_array('PRIMARY KEY', $partes, true)) {
            $partes = array_values(array_diff($partes, ['AUTOINCREMENT']));
            $auto = false;
            $this->avisar(t('Autonuméricos que no son la clave primaria: importados como enteros'));
        }
        return [implode(' ', $partes), $auto];
    }

    /** Los tipos de texto de MySQL, los que toman la colación de la tabla (2.9) */
    private const TEXTOS_MYSQL = ['CHAR', 'VARCHAR', 'NCHAR', 'NVARCHAR', 'CHARACTER', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'ENUM', 'SET'];

    /**
     * La colación de una tabla de MySQL, de las opciones de detrás del
     * paréntesis (2.9): la de COLLATE, o si solo dice su juego de caracteres
     * (CHARSET), la de ese juego, que en MySQL es siempre una _ci salvo la del
     * juego binary. Null si no dice nada: la del servidor, que no se sabe.
     *
     * @param list<array{k: string, v: string}> $opciones
     */
    private static function colacionDeTabla(array $opciones): ?string
    {
        $juego = null;
        foreach ($opciones as $i => $o) {
            if ($o['k'] !== 'id' || !empty($o['q'])) {
                continue;
            }
            $w = strtoupper($o['v']);
            $j = $i + 1 + (TraductorRutinas::esOp($opciones[$i + 1] ?? [], '=') ? 1 : 0);      // COLLATE = x o COLLATE x
            if ($w === 'COLLATE' && isset($opciones[$j])) {
                return (string)$opciones[$j]['v'];
            }
            if ($w === 'CHARSET' && isset($opciones[$j])) {
                $juego = (string)$opciones[$j]['v'];
            }
            if ($w === 'CHARACTER' && strtoupper($opciones[$i + 1]['v'] ?? '') === 'SET') {
                $j = $i + 2 + (TraductorRutinas::esOp($opciones[$i + 2] ?? [], '=') ? 1 : 0);
                $juego = isset($opciones[$j]) ? (string)$opciones[$j]['v'] : null;
            }
        }
        return $juego === null ? null : (strtolower($juego) === 'binary' ? 'binary' : 'x_ci');
    }

    /**
     * Lo que queda aquí del COLLATE de una columna (2.9): NOCASE si la de
     * origen no distingue mayúsculas (…_ci de MySQL, …_CI_AS de SQL Server,
     * citext de PostgreSQL), con un aviso, porque aquí solo se igualan las
     * de la A a la Z; NOCASE y RTRIM de SQLite, tal cual; nada con una que
     * las distingue (…_bin, …_cs, C, POSIX, BINARY), que es lo de aquí, y
     * nada con un aviso con cualquier otra.
     */
    private function colacion(string $nombre): ?string
    {
        $u = strtoupper($nombre);
        if ($u === 'NOCASE' || $u === 'RTRIM') {
            return $u;
        }
        if ($u === 'CITEXT' || preg_match('/_CI(_|$)/', $u)) {
            $this->avisar(t('Columnas que no distinguen mayúsculas (…_ci, citext) importadas con COLLATE NOCASE: aquí iguala mayúsculas y minúsculas solo de la A a la Z, sin acentos'));
            return 'NOCASE';
        }
        if (!in_array($u, ['BINARY', 'C', 'POSIX', 'UCS_BASIC', 'DEFAULT'], true) && !preg_match('/_BIN2?$|_CS(_|$)/', $u)) {
            $this->avisar(t('COLLATE {nombre} quitado de las columnas: aquí solo hay NOCASE, RTRIM y la binaria', ['nombre' => $nombre]));
        }
        return null;
    }

    /** El tipo de una columna en el SQL de aquí. Vacío si no lo tiene (SQLite lo permite). */
    private function tipo(array $tipo): string
    {
        if ($tipo === []) {
            // SQLite deja guardar cualquier cosa en una columna sin tipo; aquí
            // cada columna tiene uno, y el que lo admite todo es el texto
            $this->avisar(t('Columnas sin tipo importadas como TEXT: un número que haya en ellas queda como texto'));
            return 'TEXT';
        }
        $nombre = strtoupper(implode(' ', array_map(static fn(array $x): string => $x['k'] === 'id' ? $x['v'] : '',
            array_filter($tipo, static fn(array $x): bool => $x['k'] === 'id'))));
        $nombre = trim(preg_replace('/\b(UNSIGNED|SIGNED|ZEROFILL|BINARY|VARYING)\b/', '', $nombre) ?? $nombre);
        $args = [];
        foreach ($tipo as $x) {
            if ($x['k'] === 'num') { $args[] = $x['v']; }
        }
        $base = explode(' ', $nombre)[0];
        foreach ($tipo as $x) {
            if ($x['k'] === 'op' && $x['v'] === '[') {
                $this->avisar(t('Columnas de lista (int[], text[]…) importadas como TEXT'));
                return 'TEXT';
            }
        }
        if (in_array($base, ['INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'YEAR', 'BIT', 'BOOL', 'BOOLEAN',
                'SERIAL', 'BIGSERIAL', 'SMALLSERIAL', 'SERIAL4', 'SERIAL8', 'INT2', 'INT4', 'INT8'], true)) {
            return 'INTEGER';
        }
        if ($this->access) {
            // Los de Access, en DDL y como los escribe mdbtools («Long Integer», «Memo/Hyperlink»…)
            if (in_array($base, ['LONG', 'SHORT', 'BYTE', 'COUNTER', 'AUTOINCREMENT', 'YESNO', 'LOGICAL', 'LOGICAL1'], true)) {
                return 'INTEGER';
            }
            if (in_array($base, ['SINGLE', 'IEEESINGLE', 'IEEEDOUBLE', 'NUMBER'], true)) {
                return 'DOUBLE';
            }
            if ($base === 'CURRENCY') {
                return 'DECIMAL(19,4)';
            }
            if ($base === 'TEXT' && isset($args[0])) {
                return "VARCHAR({$args[0]})";
            }
            if (in_array($base, ['MEMO', 'HYPERLINK', 'NOTE', 'GUID', 'REPLICATION'], true)) {
                return 'TEXT';
            }
            if (in_array($base, ['OLEOBJECT', 'LONGBINARY', 'OLE', 'GENERAL', 'ATTACHMENT'], true)) {
                $this->avisar(t('BLOB y BINARY importados como TEXT: solo llegan los que son texto UTF-8'));
                return 'TEXT';
            }
        }
        if (in_array($base, ['FLOAT', 'DOUBLE', 'REAL', 'FLOAT4', 'FLOAT8'], true)) {
            return 'DOUBLE';
        }
        if (in_array($base, ['MONEY', 'SMALLMONEY'], true)) {
            return 'DECIMAL(20,4)';
        }
        if ($this->ss && ($base === 'TIMESTAMP' || $base === 'ROWVERSION')) {
            $this->avisar(t('TIMESTAMP y ROWVERSION de SQL Server importados como TEXT que admite NULL: sus valores los pone SQL Server'));
            return 'TEXT';
        }
        if (in_array($base, ['DATETIMEOFFSET', 'TIMESTAMPTZ'], true) || strpos($nombre, 'WITH TIME ZONE') !== false) {
            $this->avisar(t('Fechas con zona horaria importadas como DATETIME, sin la zona'));
            return 'DATETIME';
        }
        if (in_array($base, ['DATETIME2', 'SMALLDATETIME'], true)) {
            return 'DATETIME';
        }
        if (in_array($base, ['IMAGE', 'VARBINARY', 'BYTEA'], true)) {
            $this->avisar(t('BLOB y BINARY importados como TEXT: solo llegan los que son texto UTF-8'));
            return 'TEXT';
        }
        if (in_array($base, ['NTEXT', 'UNIQUEIDENTIFIER', 'UUID', 'XML', 'CITEXT', 'INET', 'CIDR', 'MACADDR', 'JSONB', 'SYSNAME'], true)) {
            return 'TEXT';
        }
        if (in_array($base, ['INTERVAL', 'SQL_VARIANT', 'HIERARCHYID', 'GEOGRAPHY', 'GEOMETRY', 'POINT'], true)) {
            $this->avisar(t('Tipo {base} importado como TEXT', ['base' => $base]));
            return 'TEXT';
        }
        if (in_array($base, ['DECIMAL', 'NUMERIC', 'DEC', 'FIXED'], true)) {
            if ((int)($args[0] ?? 0) > 15) {
                $this->avisar(t('DECIMAL de más de 15 cifras: aquí se guarda como número de coma flotante, con unas 15 cifras exactas'));
            }
            return count($args) >= 2 ? "DECIMAL({$args[0]},{$args[1]})" : 'DECIMAL(20,6)';
        }
        if (in_array($base, ['CHAR', 'VARCHAR', 'NCHAR', 'NVARCHAR', 'CHARACTER'], true)) {
            return isset($args[0]) ? "VARCHAR({$args[0]})" : 'TEXT';
        }
        if (in_array($base, ['DATE', 'DATETIME', 'TIMESTAMP'], true)) {
            return 'DATETIME';
        }
        if ($base === 'ENUM') {
            // Con su lista de valores, que aquí también se respeta (2.9)
            $valores = array_values(array_filter($tipo, static fn(array $x): bool => $x['k'] === 'str'));
            if ($valores !== []) {
                return 'ENUM(' . implode(', ', array_map(fn(array $x): string => $this->texto([$x]), $valores)) . ')';
            }
        }
        if ($base === 'ENUM' || $base === 'SET') {
            $this->avisar(t('SET importado como TEXT: aquí no se limitan los valores'));
            return 'TEXT';
        }
        if (in_array($base, ['TIME'], true)) {
            $this->avisar(t('TIME importado como TEXT'));
            return 'TEXT';
        }
        if (preg_match('/BLOB|BINARY/', $base)) {
            $this->avisar(t('BLOB y BINARY importados como TEXT: solo llegan los que son texto UTF-8'));
            return 'TEXT';
        }
        if (in_array($base, ['TEXT', 'TINYTEXT', 'MEDIUMTEXT', 'LONGTEXT', 'CLOB', 'JSON', 'STRING'], true)) {
            return 'TEXT';
        }
        $this->avisar(t('Tipo {nombre} importado como TEXT', ['nombre' => $nombre]));
        return 'TEXT';
    }

    /**
     * Las columnas de la lista del primer paréntesis: de cada elemento, su
     * nombre. Sin la longitud de prefijo de MySQL (nombre(10)) ni las
     * palabras que le siguen (2.9: antes cada una contaba como una columna):
     * ASC/DESC, COLLATE x, NULLS FIRST/LAST o la clase de operadores de
     * PostgreSQL (varchar_pattern_ops). Un elemento que es una expresión
     * (lower(nombre), (a + b)) no es una columna: no sale, y $expresion lo dice.
     */
    private function lista(array $d, bool &$expresion = false): array
    {
        $cols = [];
        $nivel = 0;
        $elemento = [];
        foreach ($d as $x) {
            if ($nivel === 0) {
                $nivel = TraductorRutinas::esOp($x, '(') ? 1 : 0;
                continue;
            }
            if ($nivel === 1 && (TraductorRutinas::esOp($x, ',') || TraductorRutinas::esOp($x, ')'))) {
                $col = self::columnaDe($elemento);
                if ($col === null) {
                    $expresion = true;
                } else {
                    $cols[] = $col;
                }
                if ($x['v'] === ')') {
                    break;
                }
                $elemento = [];
                continue;
            }
            $nivel += TraductorRutinas::esOp($x, '(') ? 1 : (TraductorRutinas::esOp($x, ')') ? -1 : 0);
            $elemento[] = $x;
        }
        return $cols;
    }

    /**
     * Las columnas de un índice o una clave única (ver lista()), o null si
     * alguna es una expresión: aquí un índice es sobre columnas, y no se crea
     * (2.9: lower(nombre) paraba la importación en «Índice sobre columna
     * inexistente»).
     */
    private function columnasDeIndice(array $d): ?array
    {
        $expresion = false;
        $cols = $this->lista($d, $expresion);
        if ($expresion) {
            $this->avisar(t('Índices sobre expresiones (lower(nombre)…): no se crean; si eran únicos, aquí no se comprueba esa unicidad'));
            return null;
        }
        return $cols;
    }

    /** La columna de un elemento de una lista (ver lista()), o null si es una expresión. */
    private static function columnaDe(array $e): ?string
    {
        if (($e[0]['k'] ?? '') !== 'id') {
            return null;                            // (lower(nombre)), un texto, un número
        }
        $resto = array_slice($e, 1);
        if (TraductorRutinas::esOp($resto[0] ?? [], '(') && ($resto[1]['k'] ?? '') === 'num' && TraductorRutinas::esOp($resto[2] ?? [], ')')) {
            $resto = array_slice($resto, 3);        // nombre(10)
        }
        foreach ($resto as $x) {
            if ($x['k'] !== 'id' && !TraductorRutinas::esOp($x, '.')) {
                return null;                        // lower(nombre), a + b
            }
        }
        return (string)$e[0]['v'];
    }

    /** Un valor por defecto que es un literal, quitados sus paréntesis: ((0)), (N'x'), -1. Null si es una expresión. */
    private function literalDe(array $e): ?string
    {
        while (count($e) >= 2 && TraductorRutinas::esOp($e[0], '(') && TraductorRutinas::esOp(end($e), ')')) {
            $e = array_slice($e, 1, -1);
        }
        if (count($e) === 2 && TraductorRutinas::esOp($e[0], '-') && $e[1]['k'] === 'num') {
            return '-' . $e[1]['v'];
        }
        if (count($e) === 1 && ($e[0]['k'] === 'str' || $e[0]['k'] === 'num' || strtoupper($e[0]['v']) === 'NULL')) {
            return $this->texto($e);
        }
        return null;
    }

    /**
     * La expresión de un CHECK en el SQL de aquí (2.9), desde el paréntesis
     * que la abre en $d[$k], y dónde sigue. Null si usa algo que aquí no se
     * comprueba igual (una función de otro sistema, un tipo, un nombre que no
     * es columna de la tabla): entonces se quita y se avisa, como antes. Las
     * conversiones ::tipo de PostgreSQL ya las quitó ajustar().
     *
     * @return array{0: ?string, 1: int}
     */
    private function check(string $tabla, array $d, int $k): array
    {
        $fin  = $this->saltarExpresion($d, $k);
        $e    = array_slice($d, $k, $fin - $k);
        $cols = $this->columnasDe[strtolower($tabla)] ?? [];
        foreach ($e as $i => $x) {
            $v = strtoupper((string)$x['v']);
            $vale = $x['k'] === 'num' || $x['k'] === 'str'
                || ($x['k'] === 'id' && (isset($cols[strtolower((string)$x['v'])]) || (empty($x['q'])
                    && (in_array($v, self::PALABRAS_CHECK, true) || (in_array($v, self::FUNCIONES_CHECK, true) && TraductorRutinas::esOp($e[$i + 1] ?? [], '('))))))
                || ($x['k'] === 'op' && in_array($x['v'], ['(', ')', ',', '=', '<>', '!=', '<', '>', '<=', '>=', '+', '-', '*', '/', '%', '||'], true));
            if (!$vale || !TraductorRutinas::esOp($e[0], '(')) {
                $this->avisar(t('Restricciones CHECK que usan algo que aquí no existe: quitadas'));
                return [null, $fin];
            }
        }
        // Las columnas sin comillas si no las necesitan, y -100000 junto
        $limpia = [];
        foreach ($e as $i => $x) {
            if ($x['k'] === 'id' && isset($cols[strtolower((string)$x['v'])])) {
                $n = trim($this->nombre((string)$x['v']), '"');
                $x = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $n) ? ['k' => 'id', 'v' => $n] : $x;
            } elseif ($x['k'] === 'num' && ($e[$i - 1]['v'] ?? '') === '-' && ($e[$i - 1]['k'] ?? '') === 'op'
                && in_array($e[$i - 2]['v'] ?? '(', ['(', ',', '=', '<>', '!=', '<', '>', '<=', '>=', '+', '-', '*', '/', '%'], true)) {
                array_pop($limpia);
                $x['v'] = '-' . $x['v'];
            }
            $limpia[] = $x;
        }
        return [$this->texto($limpia), $fin];
    }

    /** Salta una expresión de restricción: un valor, una llamada o un paréntesis. */
    private function saltarExpresion(array $d, int $k): int
    {
        if (TraductorRutinas::esOp($d[$k] ?? [], '(') || (($d[$k]['k'] ?? '') === 'id' && TraductorRutinas::esOp($d[$k + 1] ?? [], '('))) {
            if (!TraductorRutinas::esOp($d[$k] ?? [], '(')) { $k++; }
            $nivel = 0;
            for (; $k < count($d); $k++) {
                if ($d[$k]['v'] === '(' && $d[$k]['k'] === 'op') { $nivel++; }
                if ($d[$k]['v'] === ')' && $d[$k]['k'] === 'op') { $nivel--; if ($nivel === 0) { return $k + 1; } }
            }
            return $k;
        }
        return $k + 1;
    }

    /** Una clave foránea, para crearla al final con ALTER TABLE. */
    private function aplazar(string $tabla, ?string $nombre, array $d): void
    {
        $cols = $this->lista($d);
        $ref = null;
        foreach ($d as $k => $x) {
            if (strtoupper($x['v']) === 'REFERENCES' && empty($x['q'])) { $ref = $k; break; }
        }
        if ($ref === null) {
            return;
        }
        $destino = $d[$ref + 1]['v'];
        $colsDestino = $this->lista(array_slice($d, $ref + 2));
        $acciones = '';
        for ($k = $ref + 2; $k < count($d); $k++) {
            if (strtoupper($d[$k]['v']) === 'ON' && in_array(strtoupper($d[$k + 1]['v'] ?? ''), ['DELETE', 'UPDATE'], true)) {
                $a = strtoupper($d[$k + 2]['v'] ?? '');
                if ($a === 'SET' || $a === 'NO') { $a .= ' ' . strtoupper($d[$k + 3]['v'] ?? ''); }
                $acciones .= ' ON ' . strtoupper($d[$k + 1]['v']) . ' ' . $a;
            }
        }
        $nombre ??= 'fk_' . $tabla . '_' . implode('_', $cols);
        $this->aplazadas[] = 'ALTER TABLE ' . $this->nombreObjeto($tabla) . ' ADD CONSTRAINT ' . $this->nombreObjeto($nombre)
            . ' FOREIGN KEY (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ') REFERENCES ' . $this->nombreObjeto($destino)
            . ($colsDestino !== [] ? ' (' . implode(', ', array_map([$this, 'nombre'], $colsDestino)) . ')' : '') . $acciones;
    }

    // ------------------------------------------------------------------
    // CREATE INDEX
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function crearIndice(array $t): array
    {
        // CREATE [UNIQUE] [CLUSTERED|NONCLUSTERED] INDEX … de SQL Server
        $t = array_values(array_filter($t, static fn(array $x): bool =>
            !empty($x['q']) || !in_array(strtoupper($x['v']), ['CLUSTERED', 'NONCLUSTERED', 'CONCURRENTLY'], true)));
        $unico = strtoupper($t[1]['v']) === 'UNIQUE';
        $k = $unico ? 3 : 2;
        if (strtoupper($t[$k]['v'] ?? '') === 'IF') { $k += 3; }
        $nombre = $t[$k]['v'];
        $tabla = $t[$k + 2]['v'] ?? '';
        if (strtoupper($tabla) === 'ONLY') { $k++; $tabla = $t[$k + 2]['v'] ?? ''; }
        $cols = $this->columnasDeIndice(array_slice($t, $k + 3));
        if ($cols === null) {
            return [];
        }
        foreach ($t as $x) {
            if (strtoupper($x['v']) === 'WHERE' && empty($x['q'])) {
                $this->avisar(t('Índices parciales (WHERE) creados sobre toda la tabla'));
                break;
            }
        }
        $lista = '(' . implode(', ', array_map([$this, 'nombre'], $cols)) . ')';
        // Aquí un índice no impone unicidad: un índice único es una restricción UNIQUE
        return [$unico
            ? 'ALTER TABLE ' . $this->nombreObjeto($tabla) . ' ADD CONSTRAINT ' . $this->nombreObjeto($nombre) . ' UNIQUE ' . $lista
            : 'CREATE INDEX ' . $this->nombreObjeto($nombre) . ' ON ' . $this->nombreObjeto($tabla) . ' ' . $lista];
    }

    /**
     * Una columna, o un nombre de una expresión que no es de tabla (ver
     * marcarTablas()), entre comillas dobles: tal cual, con ñ, acentos o
     * espacios, que el motor admite en las columnas desde la 2.8 (2.9: se
     * cambiaban como los de tabla). Lo que ni así vale, como uno de tabla.
     */
    public function nombre(string $n): string
    {
        return preg_match('/^(?=.*\S)[^\x00-\x1f\x7f]{1,64}$/u', $n) ? '"' . str_replace('"', '""', $n) . '"' : $this->nombreObjeto($n);
    }

    /**
     * Un nombre de tabla, vista, índice, restricción o trigger, entre comillas
     * dobles. Aquí es de letras, números y _, y empieza por letra o _, porque
     * es parte de nombres de fichero: "Order Details" pasa a Order_Details, y
     * una letra con acento, a su letra base: AÑO pasa a ANO (2.9: un _ por
     * byte, A__O). El de una tabla o vista del volcado, el que se le dio.
     */
    public function nombreObjeto(string $n): string
    {
        if (isset($this->tablaDe[$n])) {
            return '"' . $this->tablaDe[$n] . '"';
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $n)) {
            // Un _ por letra (por byte si no es UTF-8), y delante del que empieza
            // por una cifra, dentro de los 64 (2.9: quedaba en 65)
            $base = strtr($n, self::LETRA_BASE);
            $limpio = (string)(preg_replace('/[^A-Za-z0-9_]/u', '_', $base) ?? preg_replace('/[^A-Za-z0-9_]/', '_', $base));
            $limpio = substr(preg_match('/^[A-Za-z_]/', $limpio) ? $limpio : '_' . $limpio, 0, 64);
            if (!isset($this->renombrados[$n])) {
                $this->renombrados[$n] = true;
                $this->avisar(t('Nombre \'{n}\' cambiado a \'{limpio}\': aquí solo valen letras, números y _', ['n' => $n, 'limpio' => $limpio]));
            }
            $n = $limpio;
        }
        return '"' . $n . '"';
    }
}
