<?php
declare(strict_types=1);

/**
 * Traduce las sentencias de un volcado de SQLite (`sqlite3 base.db .dump`), de
 * MySQL / MariaDB (`mysqldump`), de PostgreSQL (`pg_dump`, en texto) o de SQL
 * Server (el script de «Generar scripts» de Management Studio) al SQL que
 * entiende jsonSQLDB, una a una.
 *
 * Lo que hace, por dialecto:
 *  - Los nombres entre `comillas invertidas` o [corchetes] pasan a "dobles".
 *  - Las cadenas de MySQL, con sus escapes de barra invertida (\n, \', \\…),
 *    pasan a cadenas normales; X'…', 0x… y b'…' pasan a texto o a número, y
 *    char(10) de los volcados de SQLite a su carácter.
 *  - En CREATE TABLE, los tipos de MySQL pasan a los de aquí (INT(11)
 *    UNSIGNED → INTEGER, LONGTEXT → TEXT, ENUM → TEXT…), se quita lo que aquí
 *    no existe (COLLATE, CHARACTER SET, COMMENT, ON UPDATE, las opciones de
 *    después del paréntesis), los KEY/INDEX de dentro salen como CREATE INDEX
 *    y las claves foráneas se dejan para el final, como ALTER TABLE, porque
 *    los volcados crean las tablas en cualquier orden y meten los datos con
 *    las comprobaciones desactivadas.
 *  - Se saltan las sentencias de control (PRAGMA, BEGIN, COMMIT, SET, LOCK,
 *    USE, DELIMITER…) y la tabla interna sqlite_sequence. Los comentarios
 *    /*! … *\/ de mysqldump ya los quita el separador, y con ellos sus vistas
 *    y triggers, que son de otro dialecto.
 *
 * Lo que no tiene equivalente (CHECK, valores por defecto calculados, columnas
 * generadas, ENUM convertido a texto…) se quita y se anota en los avisos: la
 * importación dice exactamente qué no ha llegado igual.
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

    /** Esquemas que se quitan de delante de los nombres: aquí no hay esquemas. */
    private const ESQUEMAS = ['dbo', 'public'];

    /** Sentencias que en un volcado solo controlan la sesión o la transacción. */
    private const CONTROL = ['PRAGMA', 'BEGIN', 'COMMIT', 'END', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'SET', 'LOCK',
        'UNLOCK', 'USE', 'DELIMITER', 'ANALYZE', 'VACUUM', 'OPTIMIZE', 'FLUSH', 'START'];

    /** @var array<string,int> avisos de toda la importación, con cuántas veces */
    private array $avisos = [];
    /** @var list<string> claves foráneas para el final */
    private array $aplazadas = [];
    private bool $mysql;
    private bool $pg;
    private bool $ss;
    /** @var array<string,array{pk: list<string>, auto: array<string,true>, defecto: array<string,string>, tipos: array<string,string>}> lo visto en la primera pasada, por tabla */
    private array $tablas = [];
    /** @var array<string,true> nombres ya cambiados, para avisar una sola vez */
    private array $renombrados = [];
    /** Orden de las fechas d/m/a de SET DATEFORMAT (SQL Server), o '' */
    private string $formatoFecha = '';

    public function __construct(string $dialecto)
    {
        $this->mysql = $dialecto === 'mysql';
        $this->pg    = $dialecto === 'postgresql';
        $this->ss    = $dialecto === 'sqlserver';
    }

    /** ¿Qué dialecto parece un volcado, por sus primeras líneas? */
    public static function detectar(string $inicio): string
    {
        // Los volcados del propio panel dicen para qué motor son en su cabecera
        if (preg_match('/^-- jsonsqldb-dialecto: (sqlite|mysql|postgresql|sqlserver)$/m', $inicio, $m)) {
            return $m[1] === 'sqlite' ? 'jsonsqldb' : $m[1];
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
        $t = $this->tokens(preg_replace('/;\n.*/s', ';', $sql) ?? $sql);
        $w = array_map(static fn(array $x): string => empty($x['q']) ? strtoupper($x['v']) : '', array_slice($t, 0, 12));
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
            foreach ($t as $k => $x) {
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
            $out[] = $n > 1 ? "$texto ($n veces)" : $texto;
        }
        return $out;
    }

    private function sqliteOJson(): bool
    {
        return !$this->mysql && !$this->pg && !$this->ss;
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
            return ['ALTER TABLE ' . $this->nombre($tabla) . ' ADD ' . ($nombre !== null ? 'CONSTRAINT ' . $this->nombre($nombre) . ' ' : '')
                . 'UNIQUE (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ')'];
        } elseif ($w === 'CHECK') {
            $this->avisar(t('Restricciones CHECK quitadas: aquí no existen'));
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
        [$cabecera, $datos] = array_pad(explode(";\n", $sql, 2), 2, '');
        $tabla = $t[1]['v'];
        $cols = $this->lista(array_slice($t, 2));
        $tipos = $this->tablas[$tabla]['tipos'] ?? [];
        $out = [];
        $lote = [];
        $cab = 'INSERT INTO ' . $this->nombre($tabla) . ' (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ') VALUES ';
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
                } elseif (preg_match('/TIME ZONE|TIMESTAMPTZ/', $tipo) && preg_match('/^(.+?)([+-]\d{2}(:?\d{2})?)$/', $v, $m)) {
                    $this->avisar(t('Fechas con zona horaria: se ha quitado la zona; la hora queda como venía en el volcado'));
                    $v = $m[1];
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

    /** Datos binarios en hexadecimal: texto si son UTF-8; si no, se guardan como '\x…' y se avisa. */
    private function binario(string $hex): string
    {
        $bin = (string)@hex2bin(strlen($hex) % 2 ? '0' . $hex : $hex);
        if ($bin !== '' && mb_check_encoding($bin, 'UTF-8')) {
            return $bin;
        }
        $this->avisar(t('Datos binarios (BLOB, bytea, image) que no son texto: se guardan como su hexadecimal, \\x…'));
        return '\\x' . strtolower($hex);
    }

    private function avisar(string $texto): void
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
        if (in_array($p1, self::CONTROL, true) || (($this->pg || $this->ss) && $p1 === 'SELECT')) {
            $this->avisar(t('Sentencias de control saltadas (PRAGMA, SET, LOCK, BEGIN, COMMIT…)'));
            return [];
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
        if ($p1 === 'DROP' && in_array($p2, ['PROCEDURE', 'PROC', 'VIEW', 'FUNCTION', 'TRIGGER', 'SEQUENCE', 'TYPE', 'INDEX', 'SCHEMA'], true)) {
            return [];                              // lo que borra lo crea el propio volcado, y eso se salta
        }
        if ($p1 === 'DROP' && $p2 === 'TABLE' && $p3 !== 'IF') {
            // «IF EXISTS (…) DROP TABLE t» de SQL Server, partido en dos: el IF se
            // salta, y el DROP solo debe borrar si la tabla existe
            array_splice($t, 2, 0, [['k' => 'id', 'v' => 'IF'], ['k' => 'id', 'v' => 'EXISTS']]);
        }
        if ($p1 === 'ALTER' && $p2 === 'TABLE' && ($this->pg || $this->ss)) {
            return $this->alterarTabla($t);
        }
        if ($this->ss && $p1 === 'INSERT' && $p2 !== 'INTO') {
            array_splice($t, 1, 0, [['k' => 'id', 'v' => 'INTO']]);  // INSERT [t] … de SQL Server
        }
        if (($p1 === 'CREATE' || $p1 === 'DROP') && ($p2 === 'DATABASE' || $p2 === 'SCHEMA')) {
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
            $this->avisar(t('Vistas, triggers, funciones y procedimientos saltados: su SQL es de otro dialecto'));
            return [];
        }
        return [$this->texto($t)];
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
    private function tokens(string $sql): array
    {
        $out = [];
        $n = strlen($sql);
        for ($i = 0; $i < $n;) {
            $c = $sql[$i];
            if (ctype_space($c)) { $i++; continue; }
            // Introductores de juego de caracteres de MySQL: _utf8mb4'…', _binary'…'
            if ($c === '_' && preg_match('/\G_(?:utf8mb4|utf8|latin1|binary|ascii)\s*(?=\')/i', $sql, $m, 0, $i)) {
                $i += strlen($m[0]);
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
            if ($this->pg && $c === '$' && preg_match('/\G(\$[A-Za-z_]*\$)/', $sql, $m, 0, $i)) {
                $fin = strpos($sql, $m[1], $i + strlen($m[1]));
                $fin = $fin === false ? $n : $fin;
                $out[] = ['k' => 'str', 'v' => substr($sql, $i + strlen($m[1]), $fin - $i - strlen($m[1]))];
                $i = $fin + strlen($m[1]);
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
            foreach (['<=', '>=', '<>', '!=', '||', '==', '::'] as $doble) {
                if (substr($sql, $i, 2) === $doble) { $out[] = ['k' => 'op', 'v' => $doble]; $i += 2; continue 2; }
            }
            $out[] = ['k' => 'op', 'v' => $c];
            $i++;
        }
        $out = $this->ajustar($out);
        // char(10) y char(13, 10) de los volcados de SQLite: aquí no existe
        // CHAR(), así que se cambia por el texto que da
        for ($k = 0; $k < count($out); $k++) {
            if ($out[$k]['k'] === 'id' && empty($out[$k]['q']) && strtoupper($out[$k]['v']) === 'CHAR'
                && ($out[$k + 1]['v'] ?? '') === '(' && ($out[$k + 1]['k'] ?? '') === 'op') {
                $j = $k + 2;
                $texto = '';
                while (($out[$j]['k'] ?? '') === 'num') {
                    $texto .= mb_chr((int)$out[$j]['v'], 'UTF-8');
                    $j++;
                    if (($out[$j]['v'] ?? '') === ',') { $j++; }
                }
                if (($out[$j]['v'] ?? '') === ')') {
                    array_splice($out, $k, $j - $k + 1, [['k' => 'str', 'v' => $texto]]);
                }
            }
        }
        return $out;
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
            if ($x['k'] === 'id' && ($t[$k + 1]['v'] ?? '') === '.' && ($t[$k + 2]['k'] ?? '') === 'id'
                && in_array(strtolower($x['v']), self::ESQUEMAS, true) && ($t[$k - 1]['v'] ?? '') !== '.') {
                $k++;                                   // dbo.tabla → tabla
                continue;
            }
            if ($x['k'] === 'op' && $x['v'] === '::') {
                // ::tipo, ::character varying, ::numeric(12,2), ::text[]
                while (($t[$k + 1]['k'] ?? '') === 'id' && empty($t[$k + 1]['q'])) { $k++; }
                if (($t[$k + 1]['v'] ?? '') === '(') { $k = $this->saltarExpresion($t, $k + 1) - 1; }
                while (($t[$k + 1]['v'] ?? '') === '[' || ($t[$k + 1]['v'] ?? '') === ']') { $k++; }
                continue;
            }
            if (!$this->sqliteOJson() && $x['k'] === 'id' && empty($x['q']) && in_array(strtoupper($x['v']), ['TRUE', 'FALSE'], true)) {
                $out[] = ['k' => 'num', 'v' => strtoupper($x['v']) === 'TRUE' ? '1' : '0'];
                continue;
            }
            if (!$this->sqliteOJson() && $x['k'] === 'id' && empty($x['q']) && strtoupper($x['v']) === 'CAST'
                && ($t[$k + 1]['v'] ?? '') === '(' && in_array($t[$k + 2]['k'] ?? '', ['str', 'num'], true)
                && strtoupper($t[$k + 3]['v'] ?? '') === 'AS') {
                $valor = $t[$k + 2];
                $tipo = strtoupper($t[$k + 4]['v'] ?? '');
                if ($valor['k'] === 'str' && preg_match('/DATE|TIME/', $tipo)) {
                    $valor['v'] = $this->fecha((string)preg_replace('/^(\d{4}-\d{2}-\d{2})T/', '$1 ', $valor['v']));
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

    /** Los tokens otra vez como texto, en el SQL de aquí. */
    private function texto(array $t): string
    {
        $out = '';
        $antes = null;
        foreach ($t as $tk) {
            if ($tk['k'] === 'str') {
                $s = "'" . str_replace("'", "''", $tk['v']) . "'";
            } elseif ($tk['k'] === 'id' && (!empty($tk['q']))) {
                $s = $this->nombre($tk['v']);
            } else {
                $s = $tk['v'];
            }
            $pegado = $antes === null || in_array($s, [',', ')', ';'], true) || $antes === '(' || $antes === '.' || $s === '.';
            $out .= ($pegado ? '' : ' ') . $s;
            $antes = $s;
        }
        return $out;
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
        $detras = array_filter(array_slice($t, $cierre + 1), static fn(array $x): bool => $x['v'] !== ';');
        if ($detras !== []) {
            $this->avisar(t('Opciones de tabla quitadas (ENGINE, CHARSET, WITHOUT ROWID…)'));
        }

        $columnas = [];
        $restricciones = [];
        $indices = [];
        $autoinc = null;
        $pkTabla = null;
        foreach ($defs as $d) {
            if ($d === []) { continue; }
            $w = strtoupper($d[0]['v']);
            $esRestriccion = empty($d[0]['q']) && in_array($w, ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'FOREIGN', 'CHECK'], true);
            if (!$esRestriccion) {
                [$col, $auto] = $this->columna($tabla, $d);
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
                $cols = $this->lista($resto);
                $restricciones[] = ($nombre !== null ? 'CONSTRAINT ' . $this->nombre($nombre) . ' ' : '')
                                 . 'UNIQUE (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ')';
            } elseif ($w === 'KEY' || $w === 'INDEX') {
                $idx = ($d[1]['k'] ?? '') === 'id' ? $d[1]['v'] : 'ix_' . $tabla . '_' . (count($indices) + 1);
                $indices[] = 'CREATE INDEX ' . $this->nombre($idx) . ' ON ' . $this->nombre($tabla)
                           . ' (' . implode(', ', array_map([$this, 'nombre'], $this->lista($d))) . ')';
            } elseif ($w === 'FOREIGN') {
                $this->aplazar($tabla, $nombre, $d);
            } elseif ($w === 'FULLTEXT' || $w === 'SPATIAL') {
                $this->avisar(t('Índices FULLTEXT y SPATIAL quitados: aquí no existen'));
            } else {
                $this->avisar(t('Restricciones CHECK quitadas: aquí no existen'));
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
     * texto y si es AUTOINCREMENT.
     *
     * @return array{0: string, 1: bool}
     */
    private function columna(string $tabla, array $d): array
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
        // SERIAL de PostgreSQL: un entero con autoincremento
        $auto = (bool)preg_match('/^(SMALL|BIG)?SERIAL\d?$/i', (string)($tipo[0]['v'] ?? ''));
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
                if (($d[$k]['v'] ?? '') === '(') { $k = $this->saltarExpresion($d, $k); }   // IDENTITY(1,1)
                continue;
            }
            if ($w === 'DEFAULT') {
                $fin = $this->saltarExpresion($d, $k + 1);
                // DEFAULT -1 son dos tokens: el signo y el número
                if (($d[$k + 1]['v'] ?? '') === '-' && ($d[$k + 2]['k'] ?? '') === 'num') { $fin = $k + 3; }
                $lit = $this->literalDe(array_slice($d, $k + 1, $fin - $k - 1));
                if ($lit !== null) {
                    $partes[] = 'DEFAULT ' . $lit;
                } elseif (preg_match('/^NEXTVAL\b/i', (string)($d[$k + 1]['v'] ?? ''))) {
                    if (!$auto) { $partes[] = 'AUTOINCREMENT'; }
                    $auto = true;
                } else {
                    $this->avisar(t('Valores por defecto calculados quitados (CURRENT_TIMESTAMP, NOW()…): aquí solo valen literales'));
                }
                $k = $fin;
                continue;
            }
            if ($w === 'REFERENCES') {
                $this->aplazar($tabla, null, array_merge([['k' => 'id', 'v' => 'FOREIGN'], ['k' => 'id', 'v' => 'KEY'],
                    ['k' => 'op', 'v' => '('], $d[0], ['k' => 'op', 'v' => ')']], array_slice($d, $k)));
                break;
            }
            if ($w === 'COLLATE' || $w === 'CHARSET') { $k += 2; $this->avisar(t('COLLATE y CHARACTER SET quitados de las columnas')); continue; }
            if ($w === 'CHARACTER') { $k += 3; $this->avisar(t('COLLATE y CHARACTER SET quitados de las columnas')); continue; }
            if ($w === 'COMMENT') { $k += 2; continue; }
            if ($w === 'ON' && strtoupper($d[$k + 1]['v'] ?? '') === 'UPDATE') {
                $this->avisar(t('ON UPDATE CURRENT_TIMESTAMP quitado: aquí no existe'));
                $k = $this->saltarExpresion($d, $k + 2);
                continue;
            }
            if ($w === 'CHECK' || $w === 'GENERATED' || $w === 'AS') {
                $this->avisar($w === 'CHECK' ? t('Restricciones CHECK quitadas: aquí no existen')
                    : t('Columnas generadas: se importan como columnas normales, con los valores del volcado'));
                $k = $this->saltarExpresion($d, $k + ($w === 'GENERATED' ? 3 : 1));
                continue;
            }
            if ($w === 'CONSTRAINT') { $k += 2; continue; }
            $k++;                                          // VISIBLE, STORED, VIRTUAL, ROWGUIDCOL, ASC…
        }
        return [implode(' ', $partes), $auto];
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
        if (in_array($base, ['FLOAT', 'DOUBLE', 'REAL', 'FLOAT4', 'FLOAT8'], true)) {
            return 'DOUBLE';
        }
        if (in_array($base, ['MONEY', 'SMALLMONEY'], true)) {
            return 'DECIMAL(20,4)';
        }
        if ($this->ss && $base === 'TIMESTAMP') {
            $this->avisar(t('TIMESTAMP de SQL Server (rowversion) importado como TEXT'));
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
            return count($args) >= 2 ? "DECIMAL({$args[0]},{$args[1]})" : 'DECIMAL(20,6)';
        }
        if (in_array($base, ['CHAR', 'VARCHAR', 'NCHAR', 'NVARCHAR', 'CHARACTER'], true)) {
            return isset($args[0]) ? "VARCHAR({$args[0]})" : 'TEXT';
        }
        if (in_array($base, ['DATE', 'DATETIME', 'TIMESTAMP'], true)) {
            return 'DATETIME';
        }
        if (in_array($base, ['ENUM', 'SET'], true)) {
            $this->avisar(t('ENUM y SET importados como TEXT: aquí no se limitan los valores'));
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

    /** Lista de columnas del primer paréntesis, sin longitudes de prefijo ni ASC/DESC. */
    private function lista(array $d): array
    {
        $cols = [];
        $nivel = 0;
        $dentro = false;
        foreach ($d as $x) {
            if ($x['k'] === 'op' && $x['v'] === '(') {
                $nivel++;
                if ($nivel === 1) { $dentro = true; }
                continue;
            }
            if ($x['k'] === 'op' && $x['v'] === ')') {
                $nivel--;
                if ($nivel === 0 && $dentro) { break; }
                continue;
            }
            if ($dentro && $nivel === 1 && $x['k'] === 'id' && !in_array(strtoupper($x['v']), ['ASC', 'DESC'], true)) {
                $cols[] = $x['v'];
            }
        }
        return $cols;
    }

    /** Un valor por defecto que es un literal, quitados sus paréntesis: ((0)), (N'x'), -1. Null si es una expresión. */
    private function literalDe(array $e): ?string
    {
        while (count($e) >= 2 && $e[0]['v'] === '(' && $e[0]['k'] === 'op' && end($e)['v'] === ')') {
            $e = array_slice($e, 1, -1);
        }
        if (count($e) === 2 && $e[0]['v'] === '-' && $e[1]['k'] === 'num') {
            return '-' . $e[1]['v'];
        }
        if (count($e) === 1 && ($e[0]['k'] === 'str' || $e[0]['k'] === 'num' || strtoupper($e[0]['v']) === 'NULL')) {
            return $this->texto($e);
        }
        return null;
    }

    /** Salta una expresión de restricción: un valor, una llamada o un paréntesis. */
    private function saltarExpresion(array $d, int $k): int
    {
        if (($d[$k]['v'] ?? '') === '(' || (($d[$k]['k'] ?? '') === 'id' && ($d[$k + 1]['v'] ?? '') === '(')) {
            if (($d[$k]['v'] ?? '') !== '(') { $k++; }
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
        $this->aplazadas[] = 'ALTER TABLE ' . $this->nombre($tabla) . ' ADD CONSTRAINT ' . $this->nombre($nombre)
            . ' FOREIGN KEY (' . implode(', ', array_map([$this, 'nombre'], $cols)) . ') REFERENCES ' . $this->nombre($destino)
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
        $cols = $this->lista(array_slice($t, $k + 3));
        foreach ($t as $x) {
            if (strtoupper($x['v']) === 'WHERE' && empty($x['q'])) {
                $this->avisar(t('Índices parciales (WHERE) creados sobre toda la tabla'));
                break;
            }
        }
        $lista = '(' . implode(', ', array_map([$this, 'nombre'], $cols)) . ')';
        // Aquí un índice no impone unicidad: un índice único es una restricción UNIQUE
        return [$unico
            ? 'ALTER TABLE ' . $this->nombre($tabla) . ' ADD CONSTRAINT ' . $this->nombre($nombre) . ' UNIQUE ' . $lista
            : 'CREATE INDEX ' . $this->nombre($nombre) . ' ON ' . $this->nombre($tabla) . ' ' . $lista];
    }

    /**
     * Un nombre entre comillas dobles. Aquí un nombre es de letras, números y
     * _, y empieza por letra o _: "Order Details" pasa a Order_Details.
     */
    private function nombre(string $n): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $n)) {
            $limpio = substr((string)preg_replace('/[^A-Za-z0-9_]/', '_', $n), 0, 64);
            $limpio = preg_match('/^[A-Za-z_]/', $limpio) ? $limpio : '_' . $limpio;
            if (!isset($this->renombrados[$n])) {
                $this->renombrados[$n] = true;
                $this->avisar(t('Nombre \'{n}\' cambiado a \'{limpio}\': aquí solo valen letras, números y _', ['n' => $n, 'limpio' => $limpio]));
            }
            $n = $limpio;
        }
        return '"' . $n . '"';
    }
}
