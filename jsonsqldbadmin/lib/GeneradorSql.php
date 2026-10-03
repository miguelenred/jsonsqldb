<?php
declare(strict_types=1);

require_once __DIR__ . '/NoTraducible.php';

use JsonSQLDB\Parser;
use JsonSQLDB\Select;

/**
 * Escribe las vistas y los triggers de una base de jsonSQLDB en el SQL de
 * MySQL / MariaDB, PostgreSQL, SQL Server o Access.
 *
 * No reemplaza texto: analiza la SQL con el analizador del propio motor y
 * vuelve a escribir el árbol en el dialecto de destino, con lo que cambian las
 * funciones (LENGTH → CHAR_LENGTH, LEN, Len…), las comillas, LIMIT (TOP,
 * OFFSET … FETCH), las condiciones usadas como valor y al revés, y en los
 * triggers la forma entera:
 *  - MySQL: FOR EACH ROW con IF y SIGNAL. Un trigger AFTER que actualiza su
 *    propia fila (MySQL no deja que un trigger toque su tabla) pasa a un
 *    trigger BEFORE con SET NEW.
 *  - PostgreSQL: una función plpgsql y el trigger que la llama.
 *  - SQL Server: no tiene triggers fila a fila ni BEFORE. Cada trigger recorre
 *    las filas de inserted/deleted una a una con NEW y OLD en variables, y un
 *    BEFORE pasa a INSTEAD OF, que hace la escritura al final.
 *  - Access no tiene triggers en su SQL: van comentados, explicándolo.
 * Las diferencias de semántica que se pueden igualar se igualan: LIKE sin
 * distinguir mayúsculas, la división que no trunca y da NULL entre cero, la
 * concatenación que da NULL si una parte lo es.
 *
 * Lo que no tiene ninguna forma de escribirse en el destino lanza
 * NoTraducible con el motivo.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class GeneradorSql
{
    private string $d;
    /** @var array<string,string> variable de SQL Server de cada NEW.col / OLD.col */
    private array $vars = [];
    /** ¿Se está escribiendo el cuerpo de un trigger? (NEW y OLD válidos) */
    private bool $enTrigger = false;
    /** @var array<string,array> los WITH, que en Access se escriben dentro del FROM */
    private array $con = [];
    /** @var array<string,array<string,string>> tipo de cada columna de cada tabla, en minúsculas */
    private array $tipos = [];
    /** @var array<string,string> las tablas de los FROM que se están escribiendo: alias → tabla */
    private array $alias = [];

    public function __construct(string $dialecto)
    {
        $this->d = $dialecto;
        self::cargarAnalizador();
    }

    /**
     * Los tipos de las columnas de cada tabla (lo que da SHOW SCHEMA), para
     * saber qué se ordena como texto.
     *
     * @param array<string,list<array>> $columnas tabla → columnas
     */
    public function conTipos(array $columnas): self
    {
        foreach ($columnas as $tabla => $cols) {
            foreach ($cols as $c) {
                $this->tipos[strtolower((string)$tabla)][strtolower((string)$c['columna'])] =
                    $c['longitud'] !== null ? 'TEXT' : strtoupper((string)$c['tipo']);
            }
        }
        return $this;
    }

    /** Las clases del motor que hacen falta para analizar: solo el analizador. */
    public static function cargarAnalizador(): void
    {
        if (class_exists(Parser::class, false)) {
            return;
        }
        $motor = defined('ADMIN_MOTOR_RUTA') && is_dir((string)ADMIN_MOTOR_RUTA . '/engine')
            ? (string)ADMIN_MOTOR_RUTA . '/engine' : dirname(__DIR__, 2) . '/engine';
        foreach (['JsonSqlDbError', 'Lexer', 'Parser', 'Select', 'Collation'] as $clase) {
            if (!is_file("$motor/$clase.php")) {
                throw new NoTraducible(t('No se encuentra el analizador del motor en {ruta}', ['ruta' => $motor]));
            }
            require_once "$motor/$clase.php";
        }
    }

    // ------------------------------------------------------------------
    // Vistas
    // ------------------------------------------------------------------

    /** CREATE VIEW en el dialecto de destino. */
    public function vista(string $nombre, string $sql): string
    {
        $ast = Parser::analizar($sql);
        $cuerpo = $this->consulta($ast, true);
        switch ($this->d) {
            case 'sqlserver':
                // CREATE VIEW tiene que ir solo en su lote
                return "GO\nCREATE VIEW " . $this->q($nombre) . " AS\n" . $cuerpo . ";\nGO";
            default:
                return 'CREATE VIEW ' . $this->q($nombre) . ' AS ' . $cuerpo . ';';
        }
    }

    /**
     * Las vistas en un orden en que cada una va después de las que usa.
     *
     * @param list<array{vista:string,sql:string}> $vistas
     * @return list<array{vista:string,sql:string}>
     */
    public static function ordenarVistas(array $vistas): array
    {
        self::cargarAnalizador();
        $por = [];
        foreach ($vistas as $v) {
            $por[strtolower((string)$v['vista'])] = $v;
        }
        $orden = [];
        $visto = [];
        $visitar = static function (string $n) use (&$visitar, &$orden, &$visto, $por): void {
            if (isset($visto[$n]) || !isset($por[$n])) {
                return;
            }
            $visto[$n] = true;
            try {
                foreach (self::tablasUsadas(Parser::analizar((string)$por[$n]['sql'])) as $t) {
                    $visitar($t);
                }
            } catch (\Throwable $e) {
                // una vista que no se analiza va donde caiga; ya dará su error
            }
            $orden[] = $por[$n];
        };
        foreach (array_keys($por) as $n) {
            $visitar($n);
        }
        return $orden;
    }

    /** @return list<string> nombres (en minúsculas) de lo que aparece en los FROM de un árbol */
    private static function tablasUsadas(array $n): array
    {
        $r = [];
        if (isset($n['tipo'], $n['nombre']) && $n['tipo'] === 'tabla' && is_string($n['nombre'])) {
            $r[] = strtolower($n['nombre']);
        }
        foreach ($n as $v) {
            if (is_array($v)) {
                array_push($r, ...self::tablasUsadas($v));
            }
        }
        return $r;
    }

    // ------------------------------------------------------------------
    // Triggers
    // ------------------------------------------------------------------

    /**
     * Los triggers de una tabla en el dialecto de destino. Van todos juntos
     * porque en SQL Server los BEFORE de un mismo evento son un único INSTEAD OF.
     *
     * @param list<array> $triggers   lo que da SHOW TRIGGERS: nombre y su sql
     * @param list<array> $columnas   las columnas de la tabla (SHOW COLUMNS)
     * @param list<string> $pk        las columnas de la clave primaria
     */
    public function triggers(string $tabla, array $triggers, array $columnas, array $pk): string
    {
        $defs = [];
        foreach ($triggers as $trg) {
            $ast = Parser::analizar((string)$trg['sql']);
            $defs[] = $ast['trg'];
        }
        switch ($this->d) {
            case 'mysql':      return $this->triggersMysql($tabla, $defs, $pk);
            case 'postgresql': return $this->triggersPg($tabla, $defs);
            case 'sqlserver':  return $this->triggersSqlServer($tabla, $defs, $columnas, $pk);
        }
        throw new NoTraducible(t('Access no tiene triggers: ni su SQL ni OLEDB pueden crearlos. Las macros de datos de un .accdb se hacen a mano en Access.'));
    }

    private function triggersMysql(string $tabla, array $defs, array $pk): string
    {
        $out = '';
        foreach ($defs as $trg) {
            // MySQL no deja que un trigger escriba en su propia tabla. Un AFTER
            // que actualiza su misma fila (WHERE pk = NEW.pk) se escribe como un
            // BEFORE que cambia NEW, que es lo mismo
            $this->enTrigger = true;
            $antes = [];
            if ($trg['timing'] === 'AFTER' && $trg['event'] !== 'DELETE' && count($pk) === 1) {
                [$trg['body'], $antes] = $this->separarPropiaFila($trg['body'], $tabla, $pk[0]);
            }
            if ($antes !== []) {
                $out .= $this->triggerMysql($trg['name'] . '_antes', 'BEFORE', $trg['event'], $tabla, $trg['when'], $antes);
            }
            if ($trg['body'] !== []) {
                $out .= $this->triggerMysql($trg['name'], $trg['timing'], $trg['event'], $tabla, $trg['when'], $trg['body']);
            }
            $this->enTrigger = false;
        }
        return $out;
    }

    private function triggerMysql(string $nombre, string $timing, string $evento, string $tabla, ?string $cuando, array $cuerpo): string
    {
        $sentencias = $this->cuerpo($cuerpo, 1);
        if ($cuando !== null) {
            $sentencias = '  IF ' . $this->expr($this->exprDe($cuando), true) . " THEN\n" . $this->sangrar($sentencias) . "  END IF;\n";
        }
        return "DELIMITER ;;\nCREATE TRIGGER " . $this->q($nombre) . " $timing $evento ON " . $this->q($tabla)
             . " FOR EACH ROW BEGIN\n" . $sentencias . "END;;\nDELIMITER ;\n";
    }

    /**
     * Separa de un cuerpo los UPDATE de la propia tabla y fila (WHERE pk =
     * NEW.pk), convertidos en SET NEW, conservando los IF que los rodean.
     *
     * @return array{0: list<string|array>, 1: list<string|array>} lo que queda, y lo que pasa a BEFORE
     */
    private function separarPropiaFila(array $cuerpo, string $tabla, ?string $pk): array
    {
        $queda = [];
        $antes = [];
        foreach ($cuerpo as $paso) {
            if (is_array($paso)) {
                $si1 = [];
                $si2 = [];
                foreach ($paso['si'] as [$cond, $sent]) {
                    [$a, $b] = $this->separarPropiaFila($sent, $tabla, $pk);
                    $si1[] = [$cond, $a];
                    $si2[] = [$cond, $b];
                }
                [$s1, $s2] = $paso['sino'] !== null ? $this->separarPropiaFila($paso['sino'], $tabla, $pk) : [null, null];
                $queda[] = ['si' => $si1, 'sino' => $s1];
                if (array_filter(array_column($si2, 1)) !== [] || ($s2 ?? []) !== []) {
                    $antes[] = ['si' => $si2, 'sino' => $s2];
                }
                continue;
            }
            $ast = strncasecmp(ltrim($paso), 'SET', 3) === 0 ? null : Parser::analizar($paso);
            $w = $ast['where'] ?? null;
            if ($ast !== null && $ast['k'] === 'update' && strcasecmp($ast['tabla'], $tabla) === 0
                && ($w['k'] ?? '') === 'bin' && $w['op'] === '='
                && ($w['i']['k'] ?? '') === 'col' && $w['i']['tabla'] === null && strcasecmp($w['i']['nombre'], $pk ?? $w['i']['nombre']) === 0
                && ($w['d']['k'] ?? '') === 'col' && strcasecmp((string)$w['d']['tabla'], 'NEW') === 0 && strcasecmp($w['d']['nombre'], $w['i']['nombre']) === 0) {
                $asig = [];
                foreach ($ast['set'] as $s) {
                    // Las columnas de la tabla, sin calificar, son las de NEW
                    $asig[] = 'NEW.' . cita($s['col']) . ' = ' . $this->comoTexto($this->aNew($s['expr']));
                }
                $antes[] = 'SET ' . implode(', ', $asig);
                continue;
            }
            $queda[] = $paso;
        }
        // Un IF que se ha quedado sin nada en ninguna rama sobra
        $queda = array_values(array_filter($queda, static fn($p): bool => !is_array($p)
            || array_filter(array_column($p['si'], 1)) !== [] || ($p['sino'] ?? []) !== []));
        return [$queda, $antes];
    }

    /**
     * Para el importador: de un trigger AFTER de jsonSQLDB, los UPDATE de su
     * propia fila (WHERE col = NEW.col) pasan a un trigger BEFORE con SET NEW.
     * Un trigger de SQL Server que actualiza la fila que lo dispara no se
     * vuelve a disparar allí; aquí lo haría, y en un UPDATE no acabaría nunca.
     *
     * @return array{0: ?string, 1: ?string} [el BEFORE, el AFTER que queda]; null si no hay
     */
    public static function propiaFilaComoBefore(string $sql): array
    {
        self::cargarAnalizador();
        $ast = Parser::analizar($sql);
        $trg = $ast['trg'];
        if ($trg['timing'] !== 'AFTER' || $trg['event'] === 'DELETE') {
            return [null, $sql];
        }
        $g = new self('jsonsqldb');
        $g->enTrigger = true;
        [$queda, $antes] = $g->separarPropiaFila($trg['body'], $ast['tabla'], null);
        if ($antes === []) {
            return [null, $sql];
        }
        $cabecera = static fn(string $n, string $m) => 'CREATE TRIGGER "' . $n . "\" $m {$trg['event']} ON \"{$ast['tabla']}\" FOR EACH ROW"
            . ($trg['when'] !== null ? ' WHEN ' . $trg['when'] : '') . ' BEGIN ';
        return [$cabecera($trg['name'] . '_antes', 'BEFORE') . self::cuerpoTexto($antes) . ' END',
                $queda === [] ? null : $cabecera($trg['name'], 'AFTER') . self::cuerpoTexto($queda) . ' END'];
    }

    /** Un cuerpo de trigger (textos y bloques IF) escrito otra vez como SQL de jsonSQLDB. */
    private static function cuerpoTexto(array $cuerpo): string
    {
        $out = '';
        foreach ($cuerpo as $paso) {
            if (!is_array($paso)) {
                $out .= $paso . '; ';
                continue;
            }
            foreach ($paso['si'] as $i => [$cond, $sent]) {
                $out .= ($i === 0 ? 'IF ' : 'ELSEIF ') . $cond . ' THEN ' . ($sent === [] ? 'SELECT 0; ' : self::cuerpoTexto($sent));
            }
            if ($paso['sino'] !== null) {
                $out .= 'ELSE ' . ($paso['sino'] === [] ? 'SELECT 0; ' : self::cuerpoTexto($paso['sino']));
            }
            $out .= 'END IF; ';
        }
        return $out;
    }

    /** Las columnas sin tabla de una expresión pasan a ser NEW.columna. */
    private function aNew(array $n): array
    {
        if (($n['k'] ?? null) === 'col' && $n['tabla'] === null) {
            return ['k' => 'col', 'tabla' => 'NEW', 'nombre' => $n['nombre']];
        }
        if (in_array($n['k'] ?? null, ['sub', 'exists'], true)) {
            return $n;                         // dentro de una subconsulta, las columnas son suyas
        }
        foreach ($n as $c => $v) {
            if (is_array($v)) {
                $n[$c] = $this->aNew($v);
            }
        }
        return $n;
    }

    /** Una expresión del árbol escrita otra vez en el SQL de jsonSQLDB. */
    private function comoTexto(array $n): string
    {
        $d = $this->d;
        $this->d = 'jsonsqldb';
        try {
            return $this->expr($n);
        } finally {
            $this->d = $d;
        }
    }

    private function triggersPg(string $tabla, array $defs): string
    {
        $out = '';
        $this->enTrigger = true;
        foreach ($defs as $trg) {
            $sentencias = $this->cuerpo($trg['body'], 1);
            if ($trg['when'] !== null) {
                $sentencias = '  IF ' . $this->expr($this->exprDe($trg['when']), true) . " THEN\n" . $this->sangrar($sentencias) . "  END IF;\n";
            }
            $devolver = $trg['timing'] === 'AFTER' ? 'NULL' : ($trg['event'] === 'DELETE' ? 'OLD' : 'NEW');
            $funcion = $this->q($trg['name'] . '_fn');
            $out .= "CREATE FUNCTION $funcion() RETURNS trigger LANGUAGE plpgsql AS \$jsonsqldb\$\nBEGIN\n"
                  . $sentencias . "  RETURN $devolver;\nEND;\n\$jsonsqldb\$;\n"
                  . 'CREATE TRIGGER ' . $this->q($trg['name']) . " {$trg['timing']} {$trg['event']} ON " . $this->q($tabla)
                  . " FOR EACH ROW EXECUTE PROCEDURE $funcion();\n";
        }
        $this->enTrigger = false;
        return $out;
    }

    /**
     * SQL Server: un trigger por cada AFTER, y uno INSTEAD OF por evento que
     * reúne los BEFORE (solo se admite uno). Cada uno recorre sus filas con un
     * cursor y deja NEW y OLD en variables.
     */
    private function triggersSqlServer(string $tabla, array $defs, array $columnas, array $pk): string
    {
        $porEvento = [];
        $out = '';
        foreach ($defs as $trg) {
            if ($trg['timing'] === 'BEFORE') {
                $porEvento[$trg['event']][] = $trg;
            } else {
                $out .= $this->triggerSqlServer($trg['name'], 'AFTER', $trg['event'], $tabla, [$trg], $columnas, $pk);
            }
        }
        foreach ($porEvento as $evento => $lista) {
            $nombre = count($lista) === 1 ? $lista[0]['name'] : $tabla . '_antes_' . strtolower($evento);
            $out .= $this->triggerSqlServer($nombre, 'INSTEAD OF', $evento, $tabla, $lista, $columnas, $pk);
        }
        return $out;
    }

    private function triggerSqlServer(string $nombre, string $momento, string $evento, string $tabla,
                                      array $trgs, array $columnas, array $pk): string
    {
        if ($evento !== 'INSERT' && $pk === []) {
            throw new NoTraducible(t("SQL Server: sin clave primaria en '{tabla}' no se puede saber qué fila de deleted corresponde a cada una de inserted", ['tabla' => $tabla]));
        }
        $this->vars = [];
        $decl = [];
        $leer = [];
        $destinos = [];
        foreach (['NEW' => 'n', 'OLD' => 'o'] as $cual => $pre) {
            if (($cual === 'NEW' && $evento === 'DELETE') || ($cual === 'OLD' && $evento === 'INSERT')) {
                continue;
            }
            $origen = $cual === 'NEW' ? 'i' : 'd';
            foreach ($columnas as $k => $c) {
                $var = '@' . $pre . $k;
                $this->vars[$cual . '.' . strtolower((string)$c['columna'])] = $var;
                $decl[] = $var . ' ' . $this->tipoVariable($c);
                $leer[] = $origen . '.' . $this->q((string)$c['columna']);
                $destinos[] = $var;
            }
        }
        $origenes = ['INSERT' => 'inserted AS i', 'DELETE' => 'deleted AS d',
                     'UPDATE' => 'inserted AS i JOIN deleted AS d ON '
                         . implode(' AND ', array_map(fn($c) => 'i.' . $this->q($c) . ' = d.' . $this->q($c), $pk))][$evento];

        $this->enTrigger = true;
        $sentencias = '';
        foreach ($trgs as $trg) {
            $s = $this->cuerpo($trg['body'], 2);
            if ($trg['when'] !== null) {
                $s = '    IF ' . $this->expr($this->exprDe($trg['when']), true) . "\n    BEGIN\n" . $this->sangrar($s) . "    END\n";
            }
            $sentencias .= $s;
        }
        // INSTEAD OF: después de su lógica, la escritura que sustituye
        if ($momento === 'INSTEAD OF') {
            $sentencias .= $this->escrituraSustituida($evento, $tabla, $columnas, $pk);
        }
        $this->enTrigger = false;

        return "GO\nCREATE TRIGGER " . $this->q($nombre) . ' ON ' . $this->q($tabla) . " $momento $evento AS\nBEGIN\n"
             . "  SET NOCOUNT ON;\n"
             . '  DECLARE ' . implode(', ', $decl) . ";\n"
             . '  DECLARE filas CURSOR LOCAL FAST_FORWARD FOR SELECT ' . implode(', ', $leer) . " FROM $origenes;\n"
             . "  OPEN filas;\n"
             . '  FETCH NEXT FROM filas INTO ' . implode(', ', $destinos) . ";\n"
             . "  WHILE @@FETCH_STATUS = 0\n  BEGIN\n"
             . $sentencias
             . '    FETCH NEXT FROM filas INTO ' . implode(', ', $destinos) . ";\n"
             . "  END;\n  CLOSE filas;\n  DEALLOCATE filas;\nEND;\nGO\n";
    }

    /** La escritura que hace un INSTEAD OF al final, con NEW tal como lo hayan dejado los SET. */
    private function escrituraSustituida(string $evento, string $tabla, array $columnas, array $pk): string
    {
        $donde = $evento === 'INSERT' ? ''
            : implode(' AND ', array_map(fn($c) => $this->q($c) . ' = ' . $this->vars['OLD.' . strtolower($c)], $pk));
        if ($evento === 'DELETE') {
            return '    DELETE FROM ' . $this->q($tabla) . " WHERE $donde;\n";
        }
        $cols = [];
        $vals = [];
        foreach ($columnas as $c) {
            if ((int)($c['auto'] ?? 0) === 1) {
                continue;                      // la pone SQL Server (IDENTITY)
            }
            $cols[] = $this->q((string)$c['columna']);
            $vals[] = $this->vars['NEW.' . strtolower((string)$c['columna'])];
        }
        if ($evento === 'INSERT') {
            return '    INSERT INTO ' . $this->q($tabla) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n";
        }
        $set = array_map(static fn($c, $v) => "$c = $v", $cols, $vals);
        return '    UPDATE ' . $this->q($tabla) . ' SET ' . implode(', ', $set) . " WHERE $donde;\n";
    }

    private function tipoVariable(array $c): string
    {
        $tipo = strtoupper((string)$c['tipo']);
        if ($c['escala'] !== null || $tipo === 'DECIMAL') {
            return 'DECIMAL(38,' . (int)($c['escala'] ?? 6) . ')';
        }
        return ['INTEGER' => 'BIGINT', 'DOUBLE' => 'FLOAT(53)', 'DATETIME' => 'DATETIME2(3)'][$tipo] ?? 'NVARCHAR(MAX)';
    }

    /**
     * El cuerpo de un trigger: cada paso en el dialecto de destino, sangrado.
     *
     * @param list<string|array> $cuerpo
     */
    private function cuerpo(array $cuerpo, int $nivel): string
    {
        $pre = str_repeat('  ', $nivel);
        $out = '';
        foreach ($cuerpo as $paso) {
            if (is_array($paso)) {
                $out .= $this->bloqueIf($paso, $nivel);
                continue;
            }
            if (strncasecmp(ltrim($paso), 'SET', 3) === 0) {
                foreach (Parser::analizarSetNew($paso)['asig'] as [$col, $e]) {
                    $valor = $this->expr($e);
                    switch ($this->d) {
                        case 'mysql':      $out .= $pre . 'SET NEW.' . $this->q($col) . " = $valor;\n"; break;
                        case 'postgresql': $out .= $pre . 'NEW.' . $this->q($col) . " := $valor;\n"; break;
                        default:           $out .= $pre . 'SET ' . $this->variable('NEW', $col) . " = $valor;\n";
                    }
                }
                continue;
            }
            $s = $this->sentenciaTrigger(Parser::analizar($paso), $nivel);
            $out .= $s;
        }
        return $out;
    }

    private function bloqueIf(array $paso, int $nivel): string
    {
        $pre = str_repeat('  ', $nivel);
        $out = '';
        foreach ($paso['si'] as $i => [$cond, $sent]) {
            $c = $this->expr($this->exprDe($cond), true);
            $dentro = $this->cuerpo($sent, $nivel + 1);
            if ($this->d === 'sqlserver') {
                $out .= $pre . ($i === 0 ? 'IF ' : 'ELSE IF ') . $c . "\n$pre" . "BEGIN\n" . $this->noVacio($dentro, $nivel + 1) . $pre . "END\n";
            } else {
                $out .= $pre . ($i === 0 ? 'IF ' : ($this->d === 'postgresql' ? 'ELSIF ' : 'ELSEIF ')) . $c . " THEN\n" . $this->noVacio($dentro, $nivel + 1);
            }
        }
        if ($paso['sino'] !== null) {
            $dentro = $this->cuerpo($paso['sino'], $nivel + 1);
            $out .= $this->d === 'sqlserver'
                ? $pre . "ELSE\n$pre" . "BEGIN\n" . $this->noVacio($dentro, $nivel + 1) . $pre . "END\n"
                : $pre . "ELSE\n" . $this->noVacio($dentro, $nivel + 1);
        }
        return $out . ($this->d === 'sqlserver' ? '' : $pre . "END IF;\n");
    }

    /** Una rama que se ha quedado vacía (un SELECT sin efecto) necesita algo dentro. */
    private function noVacio(string $sentencias, int $nivel): string
    {
        return $sentencias !== '' ? $sentencias
            : str_repeat('  ', $nivel) . ['mysql' => 'DO 0;', 'postgresql' => 'NULL;', 'sqlserver' => 'SET NOCOUNT ON;'][$this->d] . "\n";
    }

    /** Una sentencia del cuerpo de un trigger. Un SELECT sin RAISE no hace nada y se omite. */
    private function sentenciaTrigger(array $ast, int $nivel): string
    {
        $pre = str_repeat('  ', $nivel);
        if ($ast['k'] === 'select') {
            $raise = $this->raiseDe($ast);
            if ($raise === null) {
                return '';
            }
            [$mensaje, $cond] = $raise;
            $lanzar = $this->lanzar($mensaje);
            if ($cond === null) {
                return $pre . $lanzar . "\n";
            }
            return $this->d === 'sqlserver'
                ? $pre . 'IF ' . $cond . "\n$pre  " . $lanzar . "\n"
                : $pre . 'IF ' . $cond . " THEN\n$pre  " . $lanzar . "\n$pre" . "END IF;\n";
        }
        if ($ast['k'] === 'union') {
            return '';
        }
        return $pre . $this->escritura($ast) . ";\n";
    }

    /**
     * Si un SELECT de trigger lanza un RAISE, su mensaje y la condición en que
     * lo hace (ya escrita), o null en la condición si lo lanza siempre.
     * Reconoce las formas de SQLite: SELECT RAISE(…) [FROM …] [WHERE …] y
     * SELECT CASE WHEN cond THEN RAISE(…) END.
     *
     * @return array{0: ?string, 1: ?string}|null
     */
    private function raiseDe(array $sel): ?array
    {
        if (count($sel['cols']) !== 1 || !empty($sel['cols'][0]['star'])) {
            return null;
        }
        $e = $sel['cols'][0]['expr'];
        $conds = [];
        if ($e['k'] === 'case' && $e['base'] === null && $e['else'] === null && count($e['when']) === 1
            && $e['when'][0][1]['k'] === 'raise') {
            $conds[] = $e['when'][0][0];
            $e = $e['when'][0][1];
        }
        if ($e['k'] !== 'raise') {
            return null;
        }
        if ($sel['from'] !== []) {
            $existe = $sel;
            $existe['cols'] = [['star' => false, 'expr' => ['k' => 'lit', 'v' => 1], 'alias' => null]];
            if ($conds !== []) {
                $existe['where'] = $existe['where'] === null ? $conds[0] : ['k' => 'bin', 'op' => 'AND', 'i' => $existe['where'], 'd' => $conds[0]];
            }
            return [$e['mensaje'], $this->expr(['k' => 'exists', 'select' => $existe], true)];
        }
        if ($sel['where'] !== null) {
            $conds[] = $sel['where'];
        }
        if ($conds === []) {
            return [$e['mensaje'], null];
        }
        $c = array_shift($conds);
        foreach ($conds as $otra) {
            $c = ['k' => 'bin', 'op' => 'AND', 'i' => $c, 'd' => $otra];
        }
        return [$e['mensaje'], $this->expr($c, true)];
    }

    private function lanzar(?string $mensaje): string
    {
        $m = $mensaje ?? 'RAISE';
        switch ($this->d) {
            case 'mysql':      return "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = " . $this->texto($m) . ';';
            case 'postgresql': return 'RAISE EXCEPTION USING MESSAGE = ' . $this->texto($m) . ';';
            default:           return 'THROW 50000, ' . $this->texto($m) . ', 1;';
        }
    }

    /** INSERT, UPDATE o DELETE. */
    private function escritura(array $ast): string
    {
        $t = $this->q($ast['tabla']);
        switch ($ast['k']) {
            case 'insert':
                $cols = $ast['cols'] !== null ? ' (' . implode(', ', array_map([$this, 'q'], $ast['cols'])) . ')' : '';
                if ($ast['select'] !== null) {
                    return "INSERT INTO $t$cols " . $this->consulta($ast['select']);
                }
                $filas = array_map(fn(array $f) => '(' . implode(', ', array_map(fn($e) => $this->expr($e), $f)) . ')', $ast['filas']);
                return "INSERT INTO $t$cols VALUES " . implode(', ', $filas);
            case 'update':
                $set = array_map(fn($s) => $this->q($s['col']) . ' = ' . $this->expr($s['expr']), $ast['set']);
                return "UPDATE $t SET " . implode(', ', $set) . ($ast['where'] !== null ? ' WHERE ' . $this->expr($ast['where'], true) : '');
            case 'delete':
                return "DELETE FROM $t" . ($ast['where'] !== null ? ' WHERE ' . $this->expr($ast['where'], true) : '');
        }
        throw new NoTraducible(t("Un trigger solo puede hacer INSERT, UPDATE, DELETE o SELECT"));
    }

    // ------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------

    /** Un SELECT o una combinación (UNION…), con su ORDER BY y su LIMIT. */
    public function consulta(array $ast, bool $esVista = false): string
    {
        $prefijo = '';
        $conAntes = $this->con;
        if (!empty($ast['with'])) {
            if ($this->d === 'access') {
                $this->con = array_change_key_case($ast['with'], CASE_LOWER) + $this->con;
            } else {
                $partes = [];
                foreach ($ast['with'] as $nombre => $sel) {
                    $partes[] = $this->q((string)$nombre) . ' AS (' . $this->consulta($sel) . ')';
                }
                $prefijo = 'WITH ' . implode(', ', $partes) . ' ';
            }
        }
        try {
            if ($ast['k'] === 'union') {
                return $prefijo . $this->union($ast, $esVista);
            }
            return $prefijo . $this->select($ast, $esVista);
        } finally {
            $this->con = $conAntes;
        }
    }

    private function union(array $u, bool $esVista): string
    {
        $partes = [];
        foreach ($u['partes'] as $i => $p) {
            if ($i > 0) {
                $op = $u['ops'][$i - 1];
                if ($this->d === 'access' && $op !== 'UNION' && $op !== 'UNION ALL') {
                    throw new NoTraducible(t('Access no tiene {op}', ['op' => $op]));
                }
                $partes[] = $op;
            }
            $partes[] = $this->select($p, false);
        }
        $sql = implode(' ', $partes);
        if ($u['order'] === [] && $u['limit'] === null && $u['offset'] === null) {
            return $sql;
        }
        // Con ORDER BY o LIMIT, la combinación entera va en una subconsulta
        $envuelta = ['k' => 'select', 'distinct' => false, 'cols' => [['star' => true, 'tabla' => null]],
                     'from' => [['tipo' => 'sql', 'sql' => $sql, 'alias' => 'u', 'join' => null, 'on' => null]],
                     'where' => null, 'group' => null, 'having' => null,
                     'order' => $u['order'], 'limit' => $u['limit'], 'offset' => $u['offset']];
        return $this->select($envuelta, $esVista);
    }

    private function select(array $s, bool $esVista): string
    {
        $limit = $s['limit'];
        $offset = $s['offset'];
        $orden = $s['order'];
        $cola = '';
        $top = '';
        if ($limit !== null || $offset !== null) {
            switch ($this->d) {
                case 'mysql':
                    $cola = ' LIMIT ' . ($limit ?? '18446744073709551615') . ($offset !== null ? ' OFFSET ' . (int)$offset : '');
                    break;
                case 'postgresql':
                    $cola = ($limit !== null ? ' LIMIT ' . (int)$limit : '') . ($offset !== null ? ' OFFSET ' . (int)$offset : '');
                    break;
                case 'sqlserver':
                    if ($offset === null) {
                        $top = 'TOP (' . (int)$limit . ') ';
                    } else {
                        if ($orden === []) {
                            $orden = [['expr' => ['k' => 'sql', 'sql' => '(SELECT NULL)'], 'dir' => 'ASC']];
                        }
                        $cola = ' OFFSET ' . (int)$offset . ' ROWS' . ($limit !== null ? ' FETCH NEXT ' . (int)$limit . ' ROWS ONLY' : '');
                    }
                    break;
                case 'access':
                    if ((int)$offset !== 0) {
                        throw new NoTraducible(t('Access no tiene OFFSET'));
                    }
                    $top = 'TOP ' . (int)$limit . ' ';
                    break;
            }
        } elseif ($esVista && $this->d === 'sqlserver' && $orden !== []) {
            // SQL Server no admite ORDER BY en una vista sin TOP; con este TOP,
            // la vista se crea y el orden se aplica a quien la consulta
            $top = 'TOP (9223372036854775807) ';
        }

        $cols = [];
        foreach ($s['cols'] as $c) {
            if (!empty($c['star'])) {
                $cols[] = $c['tabla'] !== null ? $this->q($c['tabla']) . '.*' : '*';
                continue;
            }
            $e = $this->expr($c['expr']);
            // El nombre de la columna, el mismo que le da jsonSQLDB: sin alias,
            // los motores llaman distinto a una expresión
            $alias = $c['alias'] ?? ($c['expr']['k'] === 'col' ? null : Select::etiqueta($c['expr']));
            $cols[] = $alias !== null ? $e . ' AS ' . $this->q($alias) : $e;
        }
        $aliasAntes = $this->alias;
        foreach ($s['from'] as $o) {
            if (($o['tipo'] ?? '') === 'tabla') {
                $this->alias[strtolower((string)($o['alias'] ?? $o['nombre']))] = strtolower((string)$o['nombre']);
            }
        }
        $sql = 'SELECT ' . ($s['distinct'] ? 'DISTINCT ' : '') . $top . implode(', ', $cols);
        if ($s['from'] !== []) {
            $sql .= ' FROM ' . $this->from($s['from']);
        }
        if ($s['where'] !== null) {
            $sql .= ' WHERE ' . $this->expr($s['where'], true);
        }
        if ($s['group'] !== null) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn($g) => $this->expr($g), $s['group']));
        }
        if ($s['having'] !== null) {
            $sql .= ' HAVING ' . $this->expr($s['having'], true);
        }
        if ($orden !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(fn($o) => $this->ordenarPor($o, $s['cols']), $orden));
        }
        $this->alias = $aliasAntes;
        return $sql . $cola;
    }

    /**
     * Un elemento de ORDER BY. Un número es la posición de la columna, en
     * todos. Un texto se ordena como aquí: sin distinguir mayúsculas ni
     * acentos, la ñ después de la n, y a igualdad byte a byte (ver
     * engine/Collation.php); la misma clave, escrita en el SQL del destino.
     */
    private function ordenarPor(array $o, array $cols): string
    {
        $e = $o['expr'];
        $dir = $o['dir'] === 'DESC' ? ' DESC' : '';
        $real = $e;
        if ($e['k'] === 'lit' && is_int($e['v'])) {
            $real = $cols[$e['v'] - 1]['expr'] ?? $e;
            $txt = (string)$e['v'];
        } else {
            if ($e['k'] === 'col' && $e['tabla'] === null) {
                foreach ($cols as $c) {
                    if (empty($c['star']) && $c['alias'] !== null && strcasecmp($c['alias'], $e['nombre']) === 0) {
                        $real = $c['expr'];
                    }
                }
            }
            $txt = $this->expr($e);
        }
        if (!in_array($this->d, ['mysql', 'postgresql', 'sqlserver'], true) || !$this->esTexto($real)) {
            return $txt . $dir;
        }
        $binaria = ['mysql' => 'utf8mb4_bin', 'postgresql' => '"C"', 'sqlserver' => 'Latin1_General_100_BIN2'][$this->d];
        return $this->claveOrden($txt) . " COLLATE $binaria$dir, $txt COLLATE $binaria$dir";
    }

    /** ¿Da texto esta expresión? Lo que se puede saber sin ejecutarla. */
    private function esTexto(array $n): bool
    {
        switch ($n['k']) {
            case 'lit':
                return is_string($n['v']);
            case 'col':
                $tabla = $n['tabla'] !== null ? ($this->alias[strtolower($n['tabla'])] ?? null) : null;
                foreach ($tabla !== null ? [$tabla] : array_unique(array_values($this->alias)) as $t) {
                    $tipo = $this->tipos[$t][strtolower($n['nombre'])] ?? null;
                    if ($tipo !== null) {
                        return in_array($tipo, ['TEXT', 'VARCHAR', 'CHAR'], true);
                    }
                }
                return false;
            case 'bin':
                return $n['op'] === '||';
            case 'cast':
                return in_array(preg_replace('/\W.*$/', '', strtoupper((string)$n['tipo'])), ['TEXT', 'VARCHAR', 'CHAR'], true);
            case 'case':
                foreach ($n['when'] as [, $r]) {
                    if ($this->esTexto($r)) { return true; }
                }
                return $n['else'] !== null && $this->esTexto($n['else']);
            case 'fn':
                $f = strtoupper((string)$n['nombre']);
                if (in_array($f, ['UPPER', 'LOWER', 'SUBSTR', 'SUBSTRING', 'REPLACE', 'TRIM', 'LTRIM', 'RTRIM', 'CONCAT', 'GROUP_CONCAT', 'STRFTIME'], true)) {
                    return true;
                }
                if (in_array($f, ['COALESCE', 'IFNULL', 'NULLIF', 'MIN', 'MAX'], true)) {
                    foreach ($n['args'] as $a) {
                        if ($this->esTexto($a)) { return true; }
                    }
                }
        }
        return false;
    }

    /** La clave de ordenación de engine/Collation.php, en el SQL del destino. */
    private function claveOrden(string $x): string
    {
        $variantes = (new \ReflectionClassConstant(\JsonSQLDB\Collation::class, 'VARIANTES'))->getValue();
        $propias = (new \ReflectionClassConstant(\JsonSQLDB\Collation::class, 'PROPIAS'))->getValue();
        $de = '';
        $a = '';
        $pares = [];
        foreach ($variantes as $base => $letras) {
            foreach ((array)preg_split('//u', $letras, -1, PREG_SPLIT_NO_EMPTY) as $c) {
                $de .= $c;
                $a .= $base;
                $pares[$c] = $base;
            }
        }
        if ($this->d === 'mysql') {
            // MySQL no tiene TRANSLATE: un REPLACE por letra
            foreach ($pares + $propias as $c => $base) {
                $x = 'REPLACE(' . $x . ', ' . $this->texto($c) . ', ' . $this->texto($base) . ')';
            }
            return "LOWER($x)";
        }
        $x = 'TRANSLATE(' . $x . ', ' . $this->texto($de) . ', ' . $this->texto($a) . ')';
        foreach ($propias as $c => $base) {
            $x = 'REPLACE(' . $x . ', ' . $this->texto($c) . ', ' . $this->texto($base) . ')';
        }
        return "LOWER($x)";
    }

    /** El FROM con sus JOIN. En Access cada JOIN va entre paréntesis. */
    private function from(array $from): string
    {
        $sql = $this->origen($from[0]);
        for ($i = 1, $n = count($from); $i < $n; $i++) {
            $o = $from[$i];
            if ($o['join'] === 'FULL' && in_array($this->d, ['mysql', 'access'], true)) {
                throw new NoTraducible(t('{motor} no tiene FULL JOIN', ['motor' => $this->d === 'mysql' ? 'MySQL' : 'Access']));
            }
            if ($o['join'] === 'CROSS') {
                $sql .= $this->d === 'access' ? ', ' . $this->origen($o) : ' CROSS JOIN ' . $this->origen($o);
                continue;
            }
            $tipo = ['INNER' => 'INNER JOIN', 'LEFT' => 'LEFT JOIN', 'RIGHT' => 'RIGHT JOIN', 'FULL' => 'FULL OUTER JOIN'][$o['join']];
            $sql = ($this->d === 'access' && $i > 1 ? '(' . $sql . ')' : $sql)
                 . " $tipo " . $this->origen($o) . ' ON ' . $this->expr($o['on'], true);
        }
        return $sql;
    }

    private function origen(array $o): string
    {
        if ($o['tipo'] === 'sql') {
            return '(' . $o['sql'] . ') AS ' . $this->q($o['alias']);
        }
        if ($o['tipo'] === 'sub') {
            return '(' . $this->consulta($o['select']) . ') AS ' . $this->q($o['alias']);
        }
        $nombre = (string)$o['nombre'];
        // Access no tiene WITH: el nombre se sustituye por su consulta
        if ($this->d === 'access' && isset($this->con[strtolower($nombre)])) {
            return '(' . $this->consulta($this->con[strtolower($nombre)]) . ') AS ' . $this->q($o['alias'] ?? $nombre);
        }
        return $this->q($nombre) . ($o['alias'] !== null ? ' AS ' . $this->q($o['alias']) : '');
    }

    // ------------------------------------------------------------------
    // Expresiones
    // ------------------------------------------------------------------

    private function exprDe(string $sql): array
    {
        return Parser::analizar('SELECT ' . $sql)['cols'][0]['expr'];
    }

    /** Las expresiones que dan verdadero o falso. */
    private static function esCondicion(array $n): bool
    {
        if ($n['k'] === 'bin') {
            return in_array($n['op'], ['=', '<>', '!=', '<', '<=', '>', '>=', 'AND', 'OR'], true);
        }
        return ($n['k'] === 'un' && $n['op'] === 'NOT') || in_array($n['k'], ['like', 'regexp', 'in', 'between', 'null', 'exists'], true);
    }

    /**
     * Una expresión. $cond dice si se usa como condición (WHERE, ON, WHEN…) o
     * como valor: PostgreSQL, SQL Server y Access no mezclan las dos cosas, y
     * jsonSQLDB, como SQLite y MySQL, sí.
     */
    private function expr(array $n, bool $cond = false): string
    {
        if (in_array($this->d, ['mysql', 'jsonsqldb'], true)) {
            return $this->exprBase($n);
        }
        $esCond = self::esCondicion($n);
        if ($cond && !$esCond) {
            return '(' . $this->exprBase($n) . ') <> 0';
        }
        if (!$cond && $esCond) {
            $c = $this->exprBase($n);
            switch ($this->d) {
                case 'postgresql': return 'CAST((' . $c . ') AS INTEGER)';
                case 'sqlserver':  return '(CASE WHEN ' . $c . ' THEN 1 WHEN NOT (' . $c . ') THEN 0 END)';
                // Con NULL, ni cierta ni falsa: NULL, como aquí
                default:           return 'IIf(' . $c . ', 1, IIf(NOT (' . $c . '), 0, Null))';
            }
        }
        return $this->exprBase($n);
    }

    private function exprBase(array $n): string
    {
        switch ($n['k']) {
            case 'lit':     return $this->literal($n['v']);
            case 'sql':     return $n['sql'];
            case 'default': return $this->d === 'access' ? throw new NoTraducible(t('Access no tiene DEFAULT en un UPDATE')) : 'DEFAULT';
            case 'col':     return $this->columna($n);
            case 'bin':     return $this->binaria($n);
            case 'un':
                return $n['op'] === 'NOT' ? 'NOT (' . $this->expr($n['e'], true) . ')' : '-(' . $this->expr($n['e']) . ')';
            case 'fn':      return $this->funcion($n);
            case 'case':    return $this->caso($n);
            case 'in':
                $dentro = $n['select'] !== null ? $this->consulta($n['select'])
                    : implode(', ', array_map(fn($e) => $this->expr($e), $n['lista']));
                return $this->expr($n['e']) . ($n['not'] ? ' NOT IN (' : ' IN (') . $dentro . ')';
            case 'between':
                return $this->expr($n['e']) . ($n['not'] ? ' NOT BETWEEN ' : ' BETWEEN ') . $this->expr($n['min']) . ' AND ' . $this->expr($n['max']);
            case 'like':     return $this->like($n);
            case 'regexp':   return $this->regexp($n);
            case 'null':     return $this->expr($n['e']) . ($n['not'] ? ' IS NOT NULL' : ' IS NULL');
            case 'cast':     return $this->conversion($n);
            case 'sub':      return '(' . $this->consulta($n['select']) . ')';
            case 'exists':   return 'EXISTS (' . $this->consulta($n['select']) . ')';
            case 'raise':
                throw new NoTraducible(t('RAISE solo se puede traducir como sentencia de un trigger'));
        }
        throw new NoTraducible(t("Expresión sin traducción: '{k}'", ['k' => (string)$n['k']]));
    }

    private function columna(array $n): string
    {
        $tabla = $n['tabla'];
        if ($tabla !== null && $this->enTrigger && in_array(strtoupper($tabla), ['NEW', 'OLD'], true)) {
            if ($this->d === 'sqlserver') {
                return $this->variable(strtoupper($tabla), $n['nombre']);
            }
            return strtoupper($tabla) . '.' . $this->q($n['nombre']);
        }
        return ($tabla !== null ? $this->q($tabla) . '.' : '') . $this->q($n['nombre']);
    }

    private function variable(string $cual, string $col): string
    {
        $v = $this->vars[$cual . '.' . strtolower($col)] ?? null;
        if ($v === null) {
            throw new NoTraducible(t('{cual}.{col} no es una columna de la tabla', ['cual' => $cual, 'col' => $col]));
        }
        return $v;
    }

    private function binaria(array $n): string
    {
        $op = $n['op'] === '!=' ? '<>' : $n['op'];
        if ($op === 'AND' || $op === 'OR') {
            return '(' . $this->expr($n['i'], true) . " $op " . $this->expr($n['d'], true) . ')';
        }
        if ($op === '||') {
            return $this->concatenar($this->partesConcat($n));
        }
        $i = $this->expr($n['i']);
        $d = $this->expr($n['d']);
        switch ($op) {
            case '/':
                // Como aquí: nunca trunca, y entre cero da NULL
                switch ($this->d) {
                    case 'postgresql': return "(CAST($i AS DOUBLE PRECISION) / NULLIF($d, 0))";
                    case 'sqlserver':  return "(CAST($i AS FLOAT) / NULLIF($d, 0))";
                    case 'access':     return "($i / IIf($d = 0, Null, $d))";
                    default:           return "($i / NULLIF($d, 0))";
                }
            case '%':
                // Como aquí: sobre la parte entera de cada uno, y entre cero NULL
                switch ($this->d) {
                    case 'postgresql': return "(CAST(TRUNC($i) AS BIGINT) % NULLIF(CAST(TRUNC($d) AS BIGINT), 0))";
                    case 'sqlserver':  return "(CAST($i AS BIGINT) % NULLIF(CAST($d AS BIGINT), 0))";
                    case 'access':     return "(Fix($i) Mod IIf(Fix($d) = 0, Null, Fix($d)))";
                    case 'mysql':      return "(TRUNCATE($i, 0) % NULLIF(TRUNCATE($d, 0), 0))";
                    default:           return "($i % $d)";
                }
        }
        return "($i $op $d)";
    }

    /** @return list<array> las partes de una cadena a || b || c */
    private function partesConcat(array $n): array
    {
        if ($n['k'] === 'bin' && $n['op'] === '||') {
            return array_merge($this->partesConcat($n['i']), $this->partesConcat($n['d']));
        }
        return [$n];
    }

    /** Concatenar como aquí: si una parte es NULL, el resultado es NULL. */
    private function concatenar(array $partes): string
    {
        $txt = array_map(fn($p) => $this->expr($p), $partes);
        switch ($this->d) {
            case 'mysql':
                return 'CONCAT(' . implode(', ', $txt) . ')';
            case 'postgresql':
                return '(' . implode(' || ', array_map(static fn($t, $p) => ($p['k'] === 'lit' && is_string($p['v'])) ? $t : "CAST($t AS TEXT)", $txt, $partes)) . ')';
            case 'sqlserver':
                return '(' . implode(' + ', array_map(static fn($t) => "CAST($t AS NVARCHAR(MAX))", $txt)) . ')';
            case 'access':
                $nulos = implode(' Or ', array_map(static fn($t) => "IsNull($t)", $txt));
                return "IIf($nulos, Null, " . implode(' & ', $txt) . ')';
        }
        return '(' . implode(' || ', $txt) . ')';
    }

    private function caso(array $n): string
    {
        if ($this->d === 'access') {
            // Access no tiene CASE: IIf anidados
            $sino = $n['else'] !== null ? $this->expr($n['else']) : 'Null';
            foreach (array_reverse($n['when']) as [$c, $r]) {
                $cond = $n['base'] !== null ? '(' . $this->expr($n['base']) . ' = ' . $this->expr($c) . ')' : $this->expr($c, true);
                $sino = "IIf($cond, " . $this->expr($r) . ", $sino)";
            }
            return $sino;
        }
        $sql = 'CASE' . ($n['base'] !== null ? ' ' . $this->expr($n['base']) : '');
        foreach ($n['when'] as [$c, $r]) {
            $sql .= ' WHEN ' . ($n['base'] !== null ? $this->expr($c) : $this->expr($c, true)) . ' THEN ' . $this->expr($r);
        }
        if ($n['else'] !== null) {
            $sql .= ' ELSE ' . $this->expr($n['else']);
        }
        return $sql . ' END';
    }

    /** LIKE aquí no distingue mayúsculas (sí acentos), como en SQLite. */
    private function like(array $n): string
    {
        $no = $n['not'] ? 'NOT ' : '';
        $e = $this->expr($n['e']);
        $p = $this->expr($n['patron']);
        $esc = $n['escape'] !== null ? ' ESCAPE ' . $this->expr($n['escape']) : '';
        switch ($this->d) {
            case 'postgresql': return "$e {$no}ILIKE $p$esc";
            case 'access':
                if ($esc !== '') {
                    throw new NoTraducible(t('Access no tiene LIKE … ESCAPE'));
                }
                return ($no !== '' ? 'NOT ' : '') . "($e ALIKE $p)";
            case 'jsonsqldb':  return "$e {$no}LIKE $p$esc";
        }
        return "LOWER($e) {$no}LIKE LOWER($p)$esc";
    }

    private function regexp(array $n): string
    {
        $e = $this->expr($n['e']);
        // Access no tiene expresiones regulares, pero las que salen de un LIKE
        // de Access al importarlo (^A[0-9][0-9].*$) vuelven a ser ese LIKE
        if ($this->d === 'access' && $n['patron']['k'] === 'lit' && is_string($n['patron']['v'])) {
            $like = self::regexComoLikeAccess($n['patron']['v']);
            if ($like !== null) {
                return ($n['not'] ? 'NOT ' : '') . "($e LIKE " . $this->texto($like) . ')';
            }
        }
        $p = $this->expr($n['patron']);
        switch ($this->d) {
            case 'mysql':      return "$e " . ($n['not'] ? 'NOT ' : '') . "REGEXP $p";
            case 'postgresql': return "$e " . ($n['not'] ? '!~ ' : '~ ') . $p;
            case 'jsonsqldb':  return "$e " . ($n['not'] ? 'NOT ' : '') . "REGEXP $p";
        }
        throw new NoTraducible(t('{motor} no tiene expresiones regulares (REGEXP)', ['motor' => $this->d === 'access' ? 'Access' : 'SQL Server']));
    }

    /** Una expresión regular anclada y sencilla, como patrón de LIKE de Access (* ? # [..]); null si no lo es. */
    private static function regexComoLikeAccess(string $re): ?string
    {
        if (!preg_match('/^(?:\(\?[is]+\))?\^(.*)\$$/s', $re, $m)) {
            return null;
        }
        $out = '';
        $r = $m[1];
        for ($i = 0, $n = strlen($r); $i < $n; $i++) {
            $c = $r[$i];
            if (substr($r, $i, 2) === '.*') { $out .= '*'; $i++; continue; }
            if (substr($r, $i, 5) === '[0-9]') { $out .= '#'; $i += 4; continue; }
            if ($c === '.') { $out .= '?'; continue; }
            if ($c === '[') {
                $fin = strpos($r, ']', $i);
                if ($fin === false) { return null; }
                $clase = substr($r, $i + 1, $fin - $i - 1);
                $out .= '[' . (str_starts_with($clase, '^') ? '!' . substr($clase, 1) : $clase) . ']';
                $i = $fin;
                continue;
            }
            if ($c === '\\' && $i + 1 < $n) {
                $c = $r[++$i];
            } elseif (strpos('()|+?{}^$', $c) !== false) {
                return null;                        // algo que un LIKE no puede decir
            }
            $out .= strpos('*?#[', $c) !== false ? '[' . $c . ']' : $c;
        }
        return $out;
    }

    private function conversion(array $n): string
    {
        $e = $this->expr($n['e']);
        $tipo = strtoupper(trim((string)$n['tipo']));
        $base = (string)preg_replace('/\s*\(.*$/', '', $tipo);
        $dec = preg_match('/\((\d+)\s*,\s*(\d+)\)/', $tipo, $m) ? [(int)$m[1], (int)$m[2]] : null;
        // A entero, aquí se trunca hacia el cero (como SQLite): MySQL y
        // PostgreSQL redondean, y CLng de Access redondea al par
        if (in_array($base, ['INTEGER', 'INT', 'BIGINT'], true)) {
            switch ($this->d) {
                case 'mysql':      return "CAST(TRUNCATE($e, 0) AS SIGNED)";
                case 'postgresql': return "CAST(TRUNC(CAST($e AS NUMERIC)) AS BIGINT)";
                case 'access':     return "Fix($e)";
            }
        }
        if ($this->d === 'access') {
            $fn = ['INTEGER' => 'CLng', 'INT' => 'CLng', 'BIGINT' => 'CLng', 'TEXT' => 'CStr', 'VARCHAR' => 'CStr', 'CHAR' => 'CStr',
                   'REAL' => 'CDbl', 'DOUBLE' => 'CDbl', 'FLOAT' => 'CDbl', 'DECIMAL' => 'CCur', 'NUMERIC' => 'CCur',
                   'DATE' => 'DateValue', 'DATETIME' => 'CDate', 'TIMESTAMP' => 'CDate'][$base] ?? null;
            if ($fn === null) {
                throw new NoTraducible(t("CAST a '{tipo}' sin equivalente en Access", ['tipo' => $tipo]));
            }
            return "$fn($e)";
        }
        $mapa = [
            'mysql'      => ['INTEGER' => 'SIGNED', 'INT' => 'SIGNED', 'BIGINT' => 'SIGNED', 'TEXT' => 'CHAR', 'VARCHAR' => 'CHAR', 'CHAR' => 'CHAR',
                             'REAL' => 'DOUBLE', 'DOUBLE' => 'DOUBLE', 'FLOAT' => 'DOUBLE', 'DATE' => 'DATE', 'DATETIME' => 'DATETIME', 'TIMESTAMP' => 'DATETIME'],
            'postgresql' => ['INTEGER' => 'BIGINT', 'INT' => 'BIGINT', 'BIGINT' => 'BIGINT', 'TEXT' => 'TEXT', 'VARCHAR' => 'TEXT', 'CHAR' => 'TEXT',
                             'REAL' => 'DOUBLE PRECISION', 'DOUBLE' => 'DOUBLE PRECISION', 'FLOAT' => 'DOUBLE PRECISION', 'DATE' => 'DATE',
                             'DATETIME' => 'TIMESTAMP', 'TIMESTAMP' => 'TIMESTAMP'],
            'sqlserver'  => ['INTEGER' => 'BIGINT', 'INT' => 'BIGINT', 'BIGINT' => 'BIGINT', 'TEXT' => 'NVARCHAR(MAX)', 'VARCHAR' => 'NVARCHAR(MAX)',
                             'CHAR' => 'NVARCHAR(MAX)', 'REAL' => 'FLOAT', 'DOUBLE' => 'FLOAT', 'FLOAT' => 'FLOAT', 'DATE' => 'DATE',
                             'DATETIME' => 'DATETIME2', 'TIMESTAMP' => 'DATETIME2'],
        ];
        if ($base === 'DECIMAL' || $base === 'NUMERIC') {
            [$p, $s] = $dec ?? [38, 6];
            return 'CAST(' . $e . ' AS ' . ($this->d === 'postgresql' ? 'NUMERIC' : 'DECIMAL') . '(' . min($p, $this->d === 'mysql' ? 65 : 38) . ",$s))";
        }
        $destino = $this->d === 'jsonsqldb' ? $tipo : ($mapa[$this->d][$base] ?? null);
        if ($destino === null) {
            throw new NoTraducible(t("CAST a '{tipo}' sin equivalente", ['tipo' => $tipo]));
        }
        return "CAST($e AS $destino)";
    }

    // ------------------------------------------------------------------
    // Funciones
    // ------------------------------------------------------------------

    private function funcion(array $n): string
    {
        $f = strtoupper((string)$n['nombre']);
        $a = array_map(fn($x) => $this->expr($x), $n['args']);
        $d = $this->d;
        $distinto = $n['distinct'] ? 'DISTINCT ' : '';
        if ($d === 'jsonsqldb') {
            return $f . '(' . ($n['star'] ? '*' : $distinto . implode(', ', $a)) . ')';
        }
        switch ($f) {
            case 'COUNT':
                return ($d === 'access' ? 'Count(' : 'COUNT(') . ($n['star'] ? '*' : $distinto . $a[0]) . ')';
            case 'SUM':
                return ($d === 'access' ? 'Sum(' : 'SUM(') . $distinto . $a[0] . ')';
            case 'AVG':
                // En SQL Server la media de enteros es entera: aquí, decimal
                return $d === 'sqlserver' ? "AVG({$distinto}CAST($a[0] AS FLOAT))" : ($d === 'access' ? 'Avg(' : 'AVG(') . $distinto . $a[0] . ')';
            case 'MIN':
            case 'MAX':
                if (count($a) === 1) {
                    return ($d === 'access' ? ucfirst(strtolower($f)) : $f) . '(' . $distinto . $a[0] . ')';
                }
                return $this->minMax($f, $a);
            case 'UPPER': return $d === 'access' ? "UCase($a[0])" : "UPPER($a[0])";
            case 'LOWER': return $d === 'access' ? "LCase($a[0])" : "LOWER($a[0])";
            case 'LENGTH':
                return ['mysql' => "CHAR_LENGTH($a[0])", 'postgresql' => "LENGTH(CAST($a[0] AS TEXT))",
                        'sqlserver' => "(DATALENGTH(CAST($a[0] AS NVARCHAR(MAX))) / 2)", 'access' => "Len($a[0])"][$d];
            case 'TRIM':
            case 'LTRIM':
            case 'RTRIM':
                return $this->recortar($f, $a);
            case 'REPLACE':
                return ($d === 'access' ? 'Replace(' : 'REPLACE(') . implode(', ', $a) . ')';
            case 'SUBSTR':
            case 'SUBSTRING':
                if ($d === 'access') {
                    return 'Mid(' . implode(', ', $a) . ')';
                }
                // Un inicio negativo cuenta desde el final, como en SQLite y MySQL;
                // en PostgreSQL y SQL Server daría vacío
                $inicio = $a[1];
                if ($d === 'postgresql' || $d === 'sqlserver') {
                    $texto = $d === 'postgresql' ? "CAST($a[0] AS TEXT)" : $a[0];
                    $largo = $d === 'postgresql' ? "LENGTH($texto)" : "LEN($texto + 'x') - 1";
                    if (!preg_match('/^\d+$/', trim($inicio))) {
                        $inicio = "(CASE WHEN ($inicio) < 0 THEN $largo + ($inicio) + 1 ELSE ($inicio) END)";
                    }
                    return $d === 'postgresql'
                        ? "SUBSTR($texto, $inicio" . (isset($a[2]) ? ", $a[2]" : '') . ')'
                        : "SUBSTRING($texto, $inicio, " . ($a[2] ?? '2147483647') . ')';
                }
                return "SUBSTRING($a[0], " . implode(', ', array_slice($a, 1)) . ')';
            case 'INSTR':
                return ['mysql' => "INSTR($a[0], $a[1])", 'postgresql' => "STRPOS(CAST($a[0] AS TEXT), $a[1])",
                        'sqlserver' => "CHARINDEX($a[1], $a[0])", 'access' => "InStr(1, $a[0], $a[1], 0)"][$d];
            case 'ABS':
                return ($d === 'access' ? 'Abs(' : 'ABS(') . $a[0] . ')';
            case 'ROUND':
                $dec = $a[1] ?? '0';
                // Como aquí, alejándose del cero también con DOUBLE: MySQL redondea
                // un DOUBLE al par (ROUND(-2.25, 1) = -2.2); con DECIMAL, no
                return ['mysql' => "ROUND(CAST($a[0] AS DECIMAL(65,30)), $dec)", 'postgresql' => "ROUND(CAST($a[0] AS NUMERIC), $dec)",
                        'sqlserver' => "ROUND($a[0], $dec)",
                        // Round de Access redondea al par: aquí, alejándose del cero
                        'access' => preg_match('/^\d+$/', $dec)
                            ? '(Fix(' . $a[0] . ' * ' . 10 ** (int)$dec . ' + Sgn(' . $a[0] . ') * 0.5) / ' . 10 ** (int)$dec . ')'
                            : "(Fix($a[0] * 10 ^ $dec + Sgn($a[0]) * 0.5) / 10 ^ $dec)"][$d];
            case 'RANDOM':
                return ['mysql' => 'RAND()', 'postgresql' => 'RANDOM()', 'sqlserver' => 'RAND(CHECKSUM(NEWID()))', 'access' => 'Rnd()'][$d];
            case 'COALESCE':
            case 'IFNULL':
                if ($d === 'access') {
                    $r = $a[count($a) - 1];
                    for ($i = count($a) - 2; $i >= 0; $i--) {
                        $r = "IIf(IsNull({$a[$i]}), $r, {$a[$i]})";
                    }
                    return $r;
                }
                return ($f === 'IFNULL' && $d === 'mysql' ? 'IFNULL(' : 'COALESCE(') . implode(', ', $a) . ')';
            case 'NULLIF':
                return $d === 'access' ? "IIf($a[0] = $a[1], Null, $a[0])" : "NULLIF($a[0], $a[1])";
            case 'CONCAT':
                return $this->concatenar($n['args']);
            case 'GROUP_CONCAT':
                $sep = $a[1] ?? $this->texto(',');
                // ORDER BY dentro del grupo
                $orden = implode(', ', array_map(fn($o) => $this->expr($o['expr']) . ($o['dir'] === 'DESC' ? ' DESC' : ''), $n['orden'] ?? []));
                switch ($d) {
                    case 'mysql':      return "GROUP_CONCAT($distinto$a[0]" . ($orden !== '' ? " ORDER BY $orden" : '') . " SEPARATOR $sep)";
                    case 'postgresql': return "STRING_AGG({$distinto}CAST($a[0] AS TEXT), $sep" . ($orden !== '' ? " ORDER BY $orden" : '') . ')';
                    case 'sqlserver':
                        if ($distinto !== '') {
                            throw new NoTraducible(t('SQL Server no tiene STRING_AGG(DISTINCT …)'));
                        }
                        return "STRING_AGG(CAST($a[0] AS NVARCHAR(MAX)), $sep)" . ($orden !== '' ? " WITHIN GROUP (ORDER BY $orden)" : '');
                }
                throw new NoTraducible(t('Access no tiene una función para unir los textos de un grupo (GROUP_CONCAT)'));
            case 'DATE':
            case 'TIME':
            case 'DATETIME':
                return $this->fecha($f, $n['args']);
            case 'STRFTIME':
                return $this->strftime($n['args']);
        }
        throw new NoTraducible(t("Función sin traducción: {f}()", ['f' => $f]));
    }

    /** MIN/MAX de varios valores: NULL si alguno lo es, como aquí. */
    private function minMax(string $f, array $a): string
    {
        $nulos = implode(' OR ', array_map(static fn($x) => "$x IS NULL", $a));
        if ($this->d === 'mysql') {
            return ($f === 'MIN' ? 'LEAST(' : 'GREATEST(') . implode(', ', $a) . ')';
        }
        if ($this->d === 'postgresql') {
            return "(CASE WHEN $nulos THEN NULL ELSE " . ($f === 'MIN' ? 'LEAST(' : 'GREATEST(') . implode(', ', $a) . ') END)';
        }
        $op = $f === 'MIN' ? '<' : '>';
        $r = $a[0];
        for ($i = 1, $n = count($a); $i < $n; $i++) {
            $r = $this->d === 'access' ? "IIf($r $op {$a[$i]}, $r, {$a[$i]})" : "(CASE WHEN $r $op {$a[$i]} THEN $r ELSE {$a[$i]} END)";
        }
        return $this->d === 'access'
            ? 'IIf(' . str_replace(' OR ', ' Or ', $nulos) . ", Null, $r)"
            : "(CASE WHEN $nulos THEN NULL ELSE $r END)";
    }

    private function recortar(string $f, array $a): string
    {
        $d = $this->d;
        if (count($a) === 1) {
            if ($d === 'access') {
                return ['TRIM' => 'Trim(', 'LTRIM' => 'LTrim(', 'RTRIM' => 'RTrim('][$f] . $a[0] . ')';
            }
            if ($d === 'sqlserver' && $f === 'TRIM') {
                return "LTRIM(RTRIM($a[0]))";
            }
            return "$f($a[0])";
        }
        // Con los caracteres a quitar
        switch ($d) {
            case 'postgresql': return ['TRIM' => 'BTRIM(', 'LTRIM' => 'LTRIM(', 'RTRIM' => 'RTRIM('][$f] . "$a[0], $a[1])";
            case 'sqlserver':  return 'TRIM(' . ['TRIM' => '', 'LTRIM' => 'LEADING ', 'RTRIM' => 'TRAILING '][$f] . "$a[1] FROM $a[0])";
            case 'mysql':      return 'TRIM(' . ['TRIM' => 'BOTH ', 'LTRIM' => 'LEADING ', 'RTRIM' => 'TRAILING '][$f] . "$a[1] FROM $a[0])";
        }
        throw new NoTraducible(t('Access no tiene {f} con caracteres propios', ['f' => $f]));
    }

    /**
     * DATE, TIME y DATETIME: con 'now', con una fecha, y con los modificadores
     * de SQLite más usados: '+N days' (y horas, minutos, segundos, meses y
     * años) y 'start of month' / 'start of year' / 'start of day'.
     */
    private function fecha(string $f, array $args): string
    {
        $d = $this->d;
        // Sin argumentos (DATETIME(), y CURRENT_TIMESTAMP, que es eso) es 'now'
        $base = array_shift($args) ?? ['k' => 'lit', 'v' => 'now'];
        $ahora = $base['k'] === 'lit' && is_string($base['v']) && strtolower($base['v']) === 'now';
        // 'unixepoch': el valor son segundos desde 1970, en UTC
        $epoch = isset($args[0]) && $args[0]['k'] === 'lit' && is_string($args[0]['v']) && strtolower(trim($args[0]['v'])) === 'unixepoch';
        if ($epoch) {
            array_shift($args);
            $s = $this->expr($base);
            $x = ['mysql' => "(TIMESTAMP('1970-01-01') + INTERVAL ($s) SECOND)", 'postgresql' => "(TIMESTAMP '1970-01-01' + ($s) * INTERVAL '1 second')",
                  'sqlserver' => "DATEADD(second, $s, CAST('1970-01-01' AS DATETIME2(0)))", 'access' => "DateAdd(\"s\", $s, #1970-01-01#)"][$d];
        }
        $x = $epoch ? $x : ($ahora
            ? ['mysql' => 'NOW()', 'postgresql' => 'LOCALTIMESTAMP(0)', 'sqlserver' => 'CAST(GETDATE() AS DATETIME2(0))', 'access' => 'Now()'][$d]
            : ['mysql' => 'CAST(' . $this->expr($base) . ' AS DATETIME)', 'postgresql' => 'CAST(' . $this->expr($base) . ' AS TIMESTAMP)',
               'sqlserver' => 'CAST(' . $this->expr($base) . ' AS DATETIME2)', 'access' => 'CDate(' . $this->expr($base) . ')'][$d]);
        foreach ($args as $m) {
            if ($m['k'] !== 'lit' || !is_string($m['v'])) {
                throw new NoTraducible(t('Solo se traducen modificadores de fecha escritos tal cual'));
            }
            $x = $this->modificar($x, strtolower(trim($m['v'])));
        }
        switch ($f) {
            case 'DATE':
                return ['mysql' => "DATE($x)", 'postgresql' => "CAST($x AS DATE)", 'sqlserver' => "CAST($x AS DATE)", 'access' => "DateValue($x)"][$d];
            case 'TIME':
                return ['mysql' => "TIME($x)", 'postgresql' => "CAST($x AS TIME(0))", 'sqlserver' => "CAST($x AS TIME(0))", 'access' => "TimeValue($x)"][$d];
        }
        return $x;
    }

    private function modificar(string $x, string $m): string
    {
        $d = $this->d;
        if ($m === 'localtime' || $m === 'utc') {
            return $x;                              // aquí las fechas no llevan zona: no cambian nada
        }
        if (preg_match('/^([+-]?\d+(?:\.\d+)?)\s+(second|minute|hour|day|month|year)s?$/', $m, $p)) {
            $n = $p[1];
            $u = $p[2];
            switch ($d) {
                case 'mysql':      return "($x + INTERVAL $n " . strtoupper($u) . ')';
                case 'postgresql': return "($x + INTERVAL '$n $u')";
                case 'sqlserver':  return "DATEADD($u, $n, $x)";
                case 'access':     return 'DateAdd("' . ['second' => 's', 'minute' => 'n', 'hour' => 'h', 'day' => 'd', 'month' => 'm', 'year' => 'yyyy'][$u] . "\", $n, $x)";
            }
        }
        if (preg_match('/^start of (month|year|day)$/', $m, $p)) {
            $u = $p[1];
            switch ($d) {
                case 'mysql':      return "CAST(DATE_FORMAT($x, '" . ['month' => '%Y-%m-01', 'year' => '%Y-01-01', 'day' => '%Y-%m-%d'][$u] . "') AS DATETIME)";
                case 'postgresql': return "DATE_TRUNC('$u', $x)";
                case 'sqlserver':  return ['month' => "CAST(DATEFROMPARTS(YEAR($x), MONTH($x), 1) AS DATETIME2(0))",
                                           'year' => "CAST(DATEFROMPARTS(YEAR($x), 1, 1) AS DATETIME2(0))",
                                           'day' => "CAST(CAST($x AS DATE) AS DATETIME2(0))"][$u];
                case 'access':     return ['month' => "DateSerial(Year($x), Month($x), 1)", 'year' => "DateSerial(Year($x), 1, 1)",
                                           'day' => "DateValue($x)"][$u];
            }
        }
        throw new NoTraducible(t("Modificador de fecha sin traducción: '{m}'", ['m' => $m]));
    }

    /** STRFTIME con un formato escrito tal cual: cada código de SQLite en el del destino. */
    private function strftime(array $args): string
    {
        $fmt = array_shift($args);
        if ($fmt === null || $fmt['k'] !== 'lit' || !is_string($fmt['v'])) {
            throw new NoTraducible(t('STRFTIME solo se traduce con el formato escrito tal cual'));
        }
        $x = $this->fecha('DATETIME', $args === [] ? [['k' => 'lit', 'v' => 'now']] : $args);
        if ($fmt['v'] === '%s') {
            // Segundos desde 1970 (con él se calculan las diferencias de fechas)
            return ['mysql' => "UNIX_TIMESTAMP($x)", 'postgresql' => "CAST(EXTRACT(EPOCH FROM $x) AS BIGINT)",
                    'sqlserver' => "DATEDIFF_BIG(second, '1970-01-01', $x)", 'access' => "DateDiff(\"s\", #1970-01-01#, $x)"][$this->d];
        }
        $codigos = [
            'mysql'      => ['%Y' => '%Y', '%m' => '%m', '%d' => '%d', '%H' => '%H', '%M' => '%i', '%S' => '%s', '%j' => '%j', '%w' => '%w', '%%' => '%%'],
            'postgresql' => ['%Y' => 'YYYY', '%m' => 'MM', '%d' => 'DD', '%H' => 'HH24', '%M' => 'MI', '%S' => 'SS', '%j' => 'DDD', '%%' => '%'],
            'sqlserver'  => ['%Y' => 'yyyy', '%m' => 'MM', '%d' => 'dd', '%H' => 'HH', '%M' => 'mm', '%S' => 'ss', '%%' => '%'],
            'access'     => ['%Y' => 'yyyy', '%m' => 'mm', '%d' => 'dd', '%H' => 'hh', '%M' => 'nn', '%S' => 'ss', '%j' => 'y', '%w' => 'w', '%%' => '%'],
        ][$this->d];
        $salida = '';
        $partes = preg_split('/(%.)/', $fmt['v'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($partes as $p) {
            if ($p[0] === '%' && strlen($p) === 2) {
                if (!isset($codigos[$p])) {
                    throw new NoTraducible(t("Código de STRFTIME sin traducción: {c}", ['c' => $p]));
                }
                $salida .= $codigos[$p];
            } else {
                // El texto suelto, protegido de que el destino lo tome por un
                // código (los separadores -/: . y el espacio ya lo son)
                $soloSeparadores = preg_match('#^[-/:. ]+$#', $p) === 1;
                $salida .= ['mysql' => $p, 'postgresql' => $soloSeparadores ? $p : '"' . $p . '"',
                            'sqlserver' => $soloSeparadores ? $p : "'" . $p . "'", 'access' => $soloSeparadores ? $p : '"' . $p . '"'][$this->d];
            }
        }
        switch ($this->d) {
            case 'mysql':      return "DATE_FORMAT($x, " . $this->texto($salida) . ')';
            case 'postgresql': return "TO_CHAR($x, " . $this->texto($salida) . ')';
            case 'sqlserver':  return "FORMAT($x, " . $this->texto($salida) . ')';
        }
        return "Format($x, " . $this->texto($salida) . ')';
    }

    // ------------------------------------------------------------------
    // Nombres y valores
    // ------------------------------------------------------------------

    public function q(string $nombre): string
    {
        switch ($this->d) {
            case 'mysql':     return '`' . str_replace('`', '``', $nombre) . '`';
            case 'sqlserver':
            case 'access':    return '[' . str_replace(']', ']]', $nombre) . ']';
        }
        return '"' . str_replace('"', '""', $nombre) . '"';
    }

    private function literal($v): string
    {
        if ($v === null)  { return $this->d === 'access' ? 'Null' : 'NULL'; }
        if (is_bool($v))  { return $v ? '1' : '0'; }
        if (is_int($v))   { return (string)$v; }
        if (is_float($v)) { return var_export($v, true); }
        return $this->texto((string)$v);
    }

    private function texto(string $s): string
    {
        switch ($this->d) {
            case 'mysql':     return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
            case 'sqlserver': return "N'" . str_replace("'", "''", $s) . "'";
        }
        return "'" . str_replace("'", "''", $s) . "'";
    }

    private function sangrar(string $texto): string
    {
        return (string)preg_replace('/^(?=.)/m', '  ', $texto);
    }
}
