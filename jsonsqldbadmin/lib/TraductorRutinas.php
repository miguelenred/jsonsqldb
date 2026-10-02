<?php
declare(strict_types=1);

require_once __DIR__ . '/NoTraducible.php';
require_once __DIR__ . '/GeneradorSql.php';

/**
 * Traduce al SQL de jsonSQLDB las vistas y los triggers de un volcado de
 * MySQL / MariaDB, PostgreSQL, SQL Server o Access.
 *
 * Trabaja sobre los tokens del Traductor (que ya ha resuelto las comillas, los
 * escapes y los esquemas) y reescribe:
 *  - las funciones que aquí se llaman de otra forma o no existen: IF(),
 *    NOW(), CURDATE(), DATE_ADD(… INTERVAL …), DATEDIFF, DATE_FORMAT, YEAR(),
 *    LOCATE, LEFT, RIGHT, CONCAT_WS, GREATEST, FLOOR, CEIL, TRUNCATE, MOD…
 *  - la sintaxis: CAST a los tipos de aquí, LIMIT a, b, los JOIN entre
 *    paréntesis de las vistas que guarda MySQL, FROM DUAL;
 *  - el cuerpo de los triggers: IF/ELSEIF/ELSE, SET NEW.col = …, SIGNAL →
 *    RAISE(ABORT, …), los bloques BEGIN … END anidados.
 * Lo que no tiene ninguna forma de escribirse aquí (variables locales,
 * bucles, procedimientos) lanza NoTraducible con el motivo: la importación
 * salta esa vista o ese trigger y lo dice en el resumen.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class TraductorRutinas
{
    private Traductor $tr;
    private string $d;

    public function __construct(Traductor $tr, string $dialecto)
    {
        $this->tr = $tr;
        $this->d = $dialecto;
    }

    // ------------------------------------------------------------------
    // Vistas
    // ------------------------------------------------------------------

    /**
     * CREATE [OR REPLACE] [ALGORITHM=…] [DEFINER=…] [SQL SECURITY …] VIEW v
     * [(columnas)] AS consulta [WITH CHECK OPTION]
     *
     * @param list<array> $t tokens de la sentencia
     */
    public function vista(array $t): string
    {
        $k = $this->tras($t, 'VIEW');
        $nombre = $t[$k]['v'] ?? '';
        $k++;
        if (($t[$k]['v'] ?? '') === '(') {
            throw new NoTraducible(t('una vista con la lista de columnas delante del AS'));
        }
        if (strtoupper($t[$k]['v'] ?? '') !== 'AS') {
            throw new NoTraducible(t('no se encuentra el AS de la vista'));
        }
        $consulta = array_slice($t, $k + 1);
        // WITH [CASCADED|LOCAL] CHECK OPTION: aquí las vistas no se escriben
        $n = count($consulta);
        if ($n >= 3 && strtoupper($consulta[$n - 2]['v']) === 'CHECK' && strtoupper($consulta[$n - 1]['v']) === 'OPTION') {
            $consulta = array_slice($consulta, 0, $n - (in_array(strtoupper($consulta[$n - 3]['v']), ['CASCADED', 'LOCAL'], true) ? 4 : 3));
        }
        while ($consulta !== [] && end($consulta)['v'] === ';') {
            array_pop($consulta);
        }
        return 'CREATE VIEW ' . $this->tr->nombreTabla($nombre) . ' AS ' . $this->consulta($consulta);
    }

    /** Una consulta: sus JOIN entre paréntesis, LIMIT a, b, FROM DUAL y sus expresiones. */
    public function consulta(array $t): string
    {
        $t = $this->quitarParentesisJoin($t);
        if ($this->d === 'sqlserver' || $this->d === 'access') {
            $t = $this->limiteSqlServer($t);
        }
        if ($this->d === 'access') {
            // DISTINCTROW quita los registros repetidos de las tablas, no los
            // valores repetidos: en la consulta de una tabla no quita nada
            $t = array_values(array_filter($t, fn($x) => $this->palabra($x) !== 'DISTINCTROW'));
        }
        if ($this->d === 'mysql') {
            $t = $this->joinSinOn($t);
        }
        if ($this->d === 'postgresql') {
            $t = $this->limitePg($t);
        }
        $out = [];
        $n = count($t);
        for ($k = 0; $k < $n; $k++) {
            $u = $this->palabra($t[$k]);
            if ($u === 'FROM' && $this->palabra($t[$k + 1] ?? []) === 'DUAL') {
                $k++;
                continue;
            }
            // LIMIT desde, cuántas → LIMIT cuántas OFFSET desde
            if ($u === 'LIMIT' && ($t[$k + 2]['v'] ?? '') === ',') {
                $out[] = ['k' => 'id', 'v' => 'LIMIT'];
                $out[] = $t[$k + 3];
                $out[] = ['k' => 'id', 'v' => 'OFFSET'];
                $out[] = $t[$k + 1];
                $k += 3;
                continue;
            }
            $out[] = $t[$k];
        }
        return $this->expr($out);
    }

    /**
     * SELECT TOP (n) … → … LIMIT n (TOP 100 PERCENT, el de las vistas con
     * ORDER BY de Management Studio, se quita); OFFSET n ROWS FETCH NEXT m
     * ROWS ONLY → LIMIT m OFFSET n; WITH (NOLOCK) y demás pistas, fuera.
     * Solo en el SELECT de fuera: las subconsultas lo hacen al traducirse.
     */
    private function limiteSqlServer(array $t): array
    {
        $limite = null;
        $desde = null;
        $out = [];
        $nivel = 0;
        for ($k = 0, $n = count($t); $k < $n; $k++) {
            $x = $t[$k];
            $u = $this->palabra($x);
            if ($x['v'] === '(') { $nivel++; }
            if ($x['v'] === ')') { $nivel--; }
            if ($nivel === 0 && $u === 'TOP' && $this->palabra($t[$k - 1] ?? []) !== '' ) {
                $paren = ($t[$k + 1]['v'] ?? '') === '(';
                $valor = $t[$k + ($paren ? 2 : 1)];
                $k += $paren ? 3 : 1;
                if ($this->palabra($t[$k + 1] ?? []) === 'PERCENT') {
                    $k++;                           // TOP 100 PERCENT
                } else {
                    $limite = $valor['v'];
                }
                continue;
            }
            if ($nivel === 0 && $u === 'OFFSET' && in_array($this->palabra($t[$k + 2] ?? []), ['ROWS', 'ROW'], true)) {
                $desde = $t[$k + 1]['v'];
                $k += 2;
                if ($this->palabra($t[$k + 1] ?? []) === 'FETCH') {
                    $limite = $t[$k + 3]['v'];      // FETCH NEXT m ROWS ONLY
                    $k += 5;
                }
                continue;
            }
            if ($u === 'WITH' && ($t[$k + 1]['v'] ?? '') === '(' && in_array($this->palabra($t[$k + 2] ?? []), ['NOLOCK', 'READUNCOMMITTED', 'ROWLOCK', 'UPDLOCK', 'HOLDLOCK', 'NOWAIT', 'READPAST', 'INDEX'], true)) {
                $k = $this->cierre($t, $k + 1);
                continue;
            }
            $out[] = $x;
        }
        if ($limite !== null) {
            $out[] = ['k' => 'id', 'v' => 'LIMIT'];
            $out[] = ['k' => 'num', 'v' => (string)$limite];
        }
        if ($desde !== null) {
            $out[] = ['k' => 'id', 'v' => 'OFFSET'];
            $out[] = ['k' => 'num', 'v' => (string)$desde];
        }
        return $out;
    }

    /**
     * MySQL guarda un CROSS JOIN (o un JOIN sin condición) como «join» a secas,
     * sin ON: aquí eso es un CROSS JOIN.
     */
    private function joinSinOn(array $t): array
    {
        $n = count($t);
        for ($k = 0; $k < $n; $k++) {
            if ($this->palabra($t[$k]) !== 'JOIN' || in_array($this->palabra($t[$k - 1] ?? []), ['LEFT', 'RIGHT', 'FULL', 'CROSS', 'OUTER'], true)) {
                continue;
            }
            $nivel = 0;
            $conOn = false;
            for ($j = $k + 1; $j < $n; $j++) {
                $v = $t[$j]['v'];
                if ($v === '(') { $nivel++; }
                if ($v === ')') { if ($nivel === 0) { break; } $nivel--; }
                if ($nivel > 0) { continue; }
                $p = $this->palabra($t[$j]);
                if ($p === 'ON' || $p === 'USING') { $conOn = true; break; }
                if (in_array($p, ['JOIN', 'WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'INNER', 'LEFT', 'RIGHT', 'CROSS'], true) || $v === ',') { break; }
            }
            if (!$conOn) {
                if ($this->palabra($t[$k - 1] ?? []) === 'INNER') {
                    $t[$k - 1] = ['k' => 'id', 'v' => 'CROSS'];
                } else {
                    array_splice($t, $k, 0, [['k' => 'id', 'v' => 'CROSS']]);
                    $k++;
                    $n++;
                }
            }
        }
        return $t;
    }

    /**
     * pg_dump escribe OFFSET antes que LIMIT, y FETCH FIRST n ROWS ONLY: aquí,
     * LIMIT n OFFSET m, en ese orden. Solo en la consulta de fuera.
     */
    private function limitePg(array $t): array
    {
        $limite = null;
        $desde = null;
        $out = [];
        $nivel = 0;
        for ($k = 0, $n = count($t); $k < $n; $k++) {
            $x = $t[$k];
            if ($x['v'] === '(') { $nivel++; }
            if ($x['v'] === ')') { $nivel--; }
            $u = $nivel === 0 ? $this->palabra($x) : '';
            if ($u === 'LIMIT' && isset($t[$k + 1])) {
                $limite = $t[++$k];
                continue;
            }
            if ($u === 'OFFSET' && isset($t[$k + 1])) {
                $desde = $t[++$k];
                if (in_array($this->palabra($t[$k + 1] ?? []), ['ROW', 'ROWS'], true)) { $k++; }
                continue;
            }
            if ($u === 'FETCH' && in_array($this->palabra($t[$k + 1] ?? []), ['FIRST', 'NEXT'], true)) {
                $limite = ($t[$k + 2]['k'] ?? '') === 'num' ? $t[$k + 2] : ['k' => 'num', 'v' => '1'];
                $k += ($t[$k + 2]['k'] ?? '') === 'num' ? 4 : 3;   // FETCH FIRST [n] ROWS ONLY
                if ($this->palabra($t[$k + 1] ?? []) === 'ONLY') { $k++; }
                continue;
            }
            $out[] = $x;
        }
        if ($limite !== null) {
            array_push($out, ['k' => 'id', 'v' => 'LIMIT'], $limite);
        }
        if ($desde !== null) {
            array_push($out, ['k' => 'id', 'v' => 'OFFSET'], $desde);
        }
        return $out;
    }

    /**
     * MySQL guarda las vistas con los JOIN entre paréntesis:
     * FROM ((a x JOIN b y ON …) LEFT JOIN c ON …). Aquí no hacen falta y el
     * analizador no los admite: se quitan los que abren justo después de FROM
     * o JOIN y no son una subconsulta.
     */
    private function quitarParentesisJoin(array $t): array
    {
        $quitar = [];
        $n = count($t);
        for ($k = 0; $k < $n; $k++) {
            if (($t[$k]['v'] ?? '') !== '(' || isset($quitar[$k])) {
                continue;
            }
            $antes = $k - 1;
            while ($antes >= 0 && isset($quitar[$antes])) { $antes--; }
            $prev = $antes >= 0 ? $this->palabra($t[$antes]) : '';
            $sig = $this->palabra($t[$k + 1] ?? []);
            if (in_array($prev, ['FROM', 'JOIN'], true) && !in_array($sig, ['SELECT', 'WITH'], true)) {
                $cierre = $this->cierre($t, $k);
                $quitar[$k] = true;
                $quitar[$cierre] = true;
            }
        }
        return array_values(array_filter($t, static fn($x, $k) => !isset($quitar[$k]), ARRAY_FILTER_USE_BOTH));
    }

    // ------------------------------------------------------------------
    // Triggers
    // ------------------------------------------------------------------

    /**
     * CREATE [DEFINER=…] TRIGGER n {BEFORE|AFTER} {INSERT|UPDATE|DELETE} ON t
     * FOR EACH ROW [FOLLOWS|PRECEDES otro] cuerpo
     *
     * @param list<array> $t
     * @return list<array{0: string, 1: string}> [nombre, sql]: uno por evento
     */
    public function trigger(array $t): array
    {
        if ($this->d === 'postgresql') {
            return $this->triggerPg($t);
        }
        if ($this->d === 'sqlserver') {
            return $this->triggerSqlServer($t);
        }
        $k = $this->tras($t, 'TRIGGER');
        $nombre = $t[$k]['v'];
        $momento = $this->palabra($t[$k + 1] ?? []);
        $evento = $this->palabra($t[$k + 2] ?? []);
        if (!in_array($momento, ['BEFORE', 'AFTER'], true) || !in_array($evento, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            throw new NoTraducible(t('trigger {m} {e}: aquí solo hay BEFORE y AFTER de INSERT, UPDATE o DELETE', ['m' => $momento, 'e' => $evento]));
        }
        if ($this->palabra($t[$k + 3] ?? []) !== 'ON') {
            throw new NoTraducible(t('no se encuentra el ON del trigger'));
        }
        $tabla = $t[$k + 4]['v'];
        $k += 5;
        if ($this->palabra($t[$k] ?? []) === 'FOR') {
            $k += 3;                                // FOR EACH ROW
        }
        if (in_array($this->palabra($t[$k] ?? []), ['FOLLOWS', 'PRECEDES'], true)) {
            $k += 2;
        }
        $cuerpo = array_slice($t, $k);
        while ($cuerpo !== [] && end($cuerpo)['v'] === ';') {
            array_pop($cuerpo);
        }
        if ($this->palabra($cuerpo[0] ?? []) === 'BEGIN') {
            $cuerpo = array_slice($cuerpo, 1, $this->finBloque($cuerpo, 0) - 1);
        }
        $sentencias = $this->bloque($cuerpo, $momento);
        if ($sentencias === '') {
            throw new NoTraducible(t('el trigger no hace nada que se pueda hacer aquí'));
        }
        return [[$nombre, 'CREATE TRIGGER ' . $this->tr->nombre($nombre) . " $momento $evento ON " . $this->tr->nombre($tabla)
             . " FOR EACH ROW BEGIN $sentencias END"]];
    }

    /**
     * Un trigger de PostgreSQL: CREATE TRIGGER n {BEFORE|AFTER} ev [OR ev…]
     * ON t FOR EACH ROW [WHEN (cond)] EXECUTE FUNCTION f(), con el cuerpo de
     * f en plpgsql. Uno por evento: TG_OP pasa a ser el nombre del evento.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function triggerPg(array $t): array
    {
        $k = $this->tras($t, 'TRIGGER');
        $nombre = $t[$k]['v'];
        $momento = $this->palabra($t[++$k] ?? []);
        if (!in_array($momento, ['BEFORE', 'AFTER'], true)) {
            throw new NoTraducible(t('trigger {m} {e}: aquí solo hay BEFORE y AFTER de INSERT, UPDATE o DELETE', ['m' => $momento, 'e' => '']));
        }
        $eventos = [];
        for ($k++; $k < count($t); $k++) {
            $u = $this->palabra($t[$k]);
            if (in_array($u, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                $eventos[] = $u;
            } elseif ($u === 'OF') {
                $this->tr->avisar(t('UPDATE OF columnas en triggers: aquí se dispara con cualquier UPDATE'));
                while (isset($t[$k + 1]) && $this->palabra($t[$k + 1]) !== 'ON') { $k++; }
            } elseif ($u === 'TRUNCATE') {
                throw new NoTraducible(t('trigger de TRUNCATE'));
            } elseif ($u !== 'OR') {
                break;
            }
        }
        if ($this->palabra($t[$k] ?? []) !== 'ON') {
            throw new NoTraducible(t('no se encuentra el ON del trigger'));
        }
        $tabla = $t[++$k]['v'];
        $cuando = null;
        for ($k++; $k < count($t); $k++) {
            $u = $this->palabra($t[$k]);
            if ($u === 'STATEMENT') {
                throw new NoTraducible(t('trigger FOR EACH STATEMENT: aquí los triggers son por fila'));
            }
            if ($u === 'REFERENCING') {
                throw new NoTraducible(t('trigger con REFERENCING'));
            }
            if ($u === 'WHEN') {
                $cierre = $this->cierre($t, $k + 1);
                $cuando = array_slice($t, $k + 2, $cierre - $k - 2);
                $k = $cierre;
            }
            if ($u === 'FUNCTION' || $u === 'PROCEDURE') {
                break;
            }
        }
        $funcion = (string)($t[$k + 1]['v'] ?? '');
        $cuerpo = $this->tr->funcion($funcion);
        if ($cuerpo === null) {
            throw new NoTraducible(t("no se encuentra la función {f}() en el volcado", ['f' => $funcion]));
        }
        $b = $this->tr->tokensConCasts($cuerpo);
        if ($this->palabra($b[0] ?? []) === 'DECLARE') {
            throw new NoTraducible(t('variables locales (DECLARE): aquí no hay variables'));
        }
        if ($this->palabra($b[0] ?? []) !== 'BEGIN') {
            throw new NoTraducible(t('falta el END del cuerpo'));
        }
        $b = array_slice($b, 1, $this->finBloque($b, 0) - 1);

        $out = [];
        foreach ($eventos as $evento) {
            // TG_OP es el evento que dispara: con un trigger por evento, un literal
            $deEste = array_map(static fn($x) => strtoupper((string)$x['v']) === 'TG_OP' && empty($x['q'])
                ? ['k' => 'str', 'v' => $evento] : $x, $b);
            $sentencias = $this->bloque($deEste, $momento);
            if ($sentencias === '') {
                continue;                           // en este evento no hace nada
            }
            $n = count($eventos) > 1 ? $nombre . '_' . strtolower($evento) : $nombre;
            $out[] = [$n, 'CREATE TRIGGER ' . $this->tr->nombre($n) . " $momento $evento ON " . $this->tr->nombre($tabla)
                . ' FOR EACH ROW' . ($cuando !== null ? ' WHEN ' . $this->expr($cuando) : '') . " BEGIN $sentencias END"];
        }
        if ($out === []) {
            throw new NoTraducible(t('el trigger no hace nada que se pueda hacer aquí'));
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Triggers de SQL Server
    // ------------------------------------------------------------------

    /** Palabras con las que empieza una sentencia de T-SQL (no siempre acaban en ;). */
    private const INICIOS_SS = ['SET', 'IF', 'BEGIN', 'END', 'UPDATE', 'INSERT', 'DELETE', 'DECLARE', 'SELECT', 'RAISERROR',
        'THROW', 'ROLLBACK', 'RETURN', 'PRINT', 'COMMIT', 'WHILE', 'EXEC', 'EXECUTE', 'ELSE', 'MERGE', 'OPEN', 'FETCH',
        'CLOSE', 'DEALLOCATE'];

    /** El evento que se traduce, la tabla del trigger, sus columnas y las variables vistas. */
    private string $ssEvento = '';
    private string $ssTabla = '';
    /** @var array<string,string> */
    private array $ssColumnas = [];
    /** @var array<string,string> @variable → su valor, ya en el SQL de aquí */
    private array $ssVars = [];

    /**
     * Un trigger de SQL Server: CREATE TRIGGER n ON t {AFTER|FOR} ev[, ev…] AS
     * cuerpo. Allí se dispara una vez por sentencia, con las filas en las
     * tablas inserted y deleted; aquí, una vez por fila. Con una fila, inserted
     * es NEW y deleted es OLD: las consultas sobre ellas se reescriben (el JOIN
     * con inserted pasa al WHERE, inserted.col pasa a NEW.col), las variables
     * que se cargan de ellas se sustituyen por su valor, y uno por evento.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function triggerSqlServer(array $t): array
    {
        $k = $this->tras($t, 'TRIGGER');
        $nombre = $t[$k]['v'];
        if ($this->palabra($t[$k + 1] ?? []) !== 'ON') {
            throw new NoTraducible(t('no se encuentra el ON del trigger'));
        }
        $tabla = $t[$k + 2]['v'];
        $k += 3;
        while ($k < count($t) && !in_array($this->palabra($t[$k]), ['FOR', 'AFTER', 'INSTEAD'], true)) {
            $k++;                                   // WITH ENCRYPTION, EXECUTE AS…
        }
        if ($this->palabra($t[$k] ?? []) === 'INSTEAD') {
            throw new NoTraducible(t('INSTEAD OF: aquí un trigger no puede sustituir la escritura que lo dispara'));
        }
        $eventos = [];
        for ($k++; $k < count($t); $k++) {
            $u = $this->palabra($t[$k]);
            if (in_array($u, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                $eventos[] = $u;
            } elseif ($u === 'AS') {
                break;
            }
        }
        $cuerpo = array_slice($t, $k + 1);
        while ($cuerpo !== [] && end($cuerpo)['v'] === ';') {
            array_pop($cuerpo);
        }
        if ($this->palabra($cuerpo[0] ?? []) === 'BEGIN' && $this->finBloque($cuerpo, 0) === count($cuerpo) - 1) {
            $cuerpo = array_slice($cuerpo, 1, -1);
        }
        $out = [];
        foreach ($eventos as $evento) {
            $this->ssEvento = $evento;
            $this->ssTabla = $tabla;
            $this->ssColumnas = $this->tr->columnas($tabla);
            $this->ssVars = ['@@ROWCOUNT' => '1'];
            $sentencias = $this->bloqueSs($cuerpo);
            if ($sentencias === '') {
                continue;
            }
            $n = count($eventos) > 1 ? $nombre . '_' . strtolower($evento) : $nombre;
            $sql = 'CREATE TRIGGER ' . $this->tr->nombre($n) . " AFTER $evento ON " . $this->tr->nombre($tabla)
                . " FOR EACH ROW BEGIN $sentencias END";
            // Lo que actualiza la propia fila, a un BEFORE con SET NEW: en SQL
            // Server un trigger no se vuelve a disparar a sí mismo; aquí sí
            [$antes, $despues] = GeneradorSql::propiaFilaComoBefore($sql);
            if ($antes !== null) {
                $out[] = [$n . '_antes', $antes];
            }
            if ($despues !== null) {
                $out[] = [$n, $despues];
            }
        }
        if ($out === []) {
            throw new NoTraducible(t('el trigger no hace nada que se pueda hacer aquí'));
        }
        return $out;
    }

    /** Las sentencias de un bloque de T-SQL, traducidas. */
    private function bloqueSs(array $t): string
    {
        $out = '';
        $lanzado = false;                           // un RAISERROR/THROW antes del ROLLBACK
        for ($k = 0, $n = count($t); $k < $n;) {
            $u = $this->palabra($t[$k]);
            if ($t[$k]['v'] === ';') {
                $k++;
                continue;
            }
            if ($u === 'BEGIN') {
                $fin = $this->finBloque($t, $k);
                $out .= $this->bloqueSs(array_slice($t, $k + 1, $fin - $k - 1));
                $k = $fin + 1;
                continue;
            }
            if ($u === 'IF') {
                [$texto, $k] = $this->siSs($t, $k);
                $out .= $texto;
                continue;
            }
            $fin = $this->finSentenciaSs($t, $k);
            $s = array_slice($t, $k, $fin - $k);
            $k = $fin;
            if (in_array($u, ['RAISERROR', 'THROW'], true)) {
                $lanzado = true;
            }
            if ($u === 'ROLLBACK') {
                // Un ROLLBACK en un trigger termina la sentencia con error en SQL Server
                if (!$lanzado) {
                    $out .= "SELECT RAISE(ABORT, 'La transacción terminó en el trigger'); ";
                }
                continue;
            }
            $traducida = $this->sentenciaSs($s);
            if ($traducida !== '') {
                $out .= $traducida . '; ';
            }
        }
        return $out;
    }

    /** IF cond sentencia|BEGIN…END [ELSE sentencia|BEGIN…END] @return array{0: string, 1: int} */
    private function siSs(array $t, int $k): array
    {
        // La condición acaba donde empieza la sentencia
        $j = $k + 1;
        $nivel = 0;
        $caso = 0;
        for ($n = count($t); $j < $n; $j++) {
            if ($t[$j]['v'] === '(') { $nivel++; }
            if ($t[$j]['v'] === ')') { $nivel--; }
            if ($this->palabra($t[$j]) === 'CASE') { $caso++; }
            if ($caso > 0 && $this->palabra($t[$j]) === 'END') { $caso--; continue; }
            if ($nivel === 0 && $caso === 0 && $j > $k + 1 && in_array($this->palabra($t[$j]), self::INICIOS_SS, true)
                && !($this->palabra($t[$j]) === 'SELECT' && $this->palabra($t[$j - 1] ?? []) === 'EXISTS')) {
                break;
            }
        }
        $cond = $this->condicionSs(array_merge([['k' => 'op', 'v' => '(']], array_slice($t, $k + 1, $j - $k - 1), [['k' => 'op', 'v' => ')']]));
        [$entonces, $j] = $this->ramaSs($t, $j);
        $sino = '';
        if ($this->palabra($t[$j] ?? []) === 'ELSE') {
            [$sino, $j] = $this->ramaSs($t, $j + 1);
        }
        if ($cond === 'FALSO') {
            return [$sino, $j];
        }
        if ($cond === 'CIERTO') {
            return [$entonces, $j];
        }
        $texto = "IF $cond THEN " . ($entonces !== '' ? $entonces : 'SELECT 0; ')
               . ($sino !== '' ? "ELSE $sino" : '') . 'END IF; ';
        return [$texto, $j];
    }

    /** Una rama de un IF: un BEGIN … END o una sola sentencia. @return array{0: string, 1: int} */
    private function ramaSs(array $t, int $k): array
    {
        if ($this->palabra($t[$k] ?? []) === 'BEGIN') {
            $fin = $this->finBloque($t, $k);
            return [$this->bloqueSs(array_slice($t, $k + 1, $fin - $k - 1)), $fin + 1];
        }
        if ($this->palabra($t[$k] ?? []) === 'IF') {
            return $this->siSs($t, $k);
        }
        $fin = $this->finSentenciaSs($t, $k);
        return [$this->bloqueSs(array_slice($t, $k, $fin - $k)), $fin];
    }

    /** El final de la sentencia de T-SQL que empieza en $k (la siguiente que empieza, o su ;). */
    private function finSentenciaSs(array $t, int $k): int
    {
        $u = $this->palabra($t[$k]);
        $propias = ['INSERT' => ['SELECT'], 'UPDATE' => ['SET'], 'SELECT' => [], 'DELETE' => []][$u] ?? [];
        $nivel = 0;
        $caso = 0;                                  // CASE … END: su ELSE y su END no acaban nada
        for ($j = $k + 1, $n = count($t); $j < $n; $j++) {
            $v = $t[$j]['v'];
            if ($v === '(') { $nivel++; }
            if ($v === ')') { $nivel--; }
            if ($this->palabra($t[$j]) === 'CASE') { $caso++; continue; }
            if ($caso > 0 && $this->palabra($t[$j]) === 'END') { $caso--; continue; }
            if ($nivel !== 0 || $caso > 0) {
                continue;
            }
            if ($v === ';') {
                return $j;
            }
            $p = $this->palabra($t[$j]);
            if (in_array($p, self::INICIOS_SS, true)) {
                $i = array_search($p, $propias, true);
                if ($i !== false) {
                    unset($propias[$i]);            // el SELECT de INSERT … SELECT, el SET de UPDATE
                    continue;
                }
                return $j;
            }
        }
        return $n;
    }

    /** Una sentencia de T-SQL de un trigger. */
    private function sentenciaSs(array $s): string
    {
        $u = $this->palabra($s[0]);
        switch ($u) {
            case 'SET':
                if (($s[1]['v'] ?? '') === '@') {
                    $this->ssVars['@' . strtolower((string)$s[2]['v'])] = '(' . $this->exprSs(array_slice($s, 4)) . ')';
                }
                return '';                          // SET NOCOUNT ON y demás opciones: nada que hacer aquí
            case 'DECLARE':
                foreach ($this->separar(array_slice($s, 1)) as $d) {
                    $igual = null;
                    foreach ($d as $i => $x) {
                        if ($x['v'] === '=') { $igual = $i; break; }
                    }
                    $this->ssVars['@' . strtolower((string)($d[1]['v'] ?? ''))] = $igual !== null ? '(' . $this->exprSs(array_slice($d, $igual + 1)) . ')' : 'NULL';
                }
                return '';
            case 'SELECT':
                if (($s[1]['v'] ?? '') === '@') {
                    $this->asignarDesdeConsulta($s);
                }
                return '';                          // un SELECT que no carga variables no hace nada
            case 'RAISERROR':
            case 'THROW':
                $textos = array_values(array_filter($s, static fn($x) => $x['k'] === 'str'));
                if ($textos === []) {
                    $this->tr->avisar(t('Mensajes de error compuestos en triggers: se importa solo su texto fijo'));
                }
                return 'SELECT RAISE(ABORT, ' . $this->tr->texto([$textos[0] ?? ['k' => 'str', 'v' => 'Error']]) . ')';
            case 'RETURN':
            case 'PRINT':
            case 'COMMIT':
                return '';
            case 'INSERT':
            case 'UPDATE':
            case 'DELETE':
                return $this->escrituraSs($s);
        }
        throw new NoTraducible(t("sentencia '{s}' sin equivalente en un trigger de aquí", ['s' => $u !== '' ? $u : $s[0]['v']]));
    }

    /** SELECT @a = expr, @b = expr FROM … : cada variable vale su expresión (una subconsulta si hay otras tablas). */
    private function asignarDesdeConsulta(array $s): void
    {
        $desde = $this->buscarOpcional($s, 'FROM');
        $lista = array_slice($s, 1, ($desde ?? count($s)) - 1);
        $resto = $desde !== null ? array_slice($s, $desde) : [];
        foreach ($this->separar($lista) as $a) {
            $var = '@' . strtolower((string)$a[1]['v']);
            $consulta = array_merge([['k' => 'id', 'v' => 'SELECT']], array_slice($a, 3), $resto);
            $sql = $this->consultaSs($consulta);
            if ($sql === null) {
                $this->ssVars[$var] = 'NULL';       // de la tabla vacía en este evento
                continue;
            }
            // Sin otras tablas, el valor es la expresión; si no, una subconsulta
            $this->ssVars[$var] = preg_match('/^SELECT (.*)$/s', $sql, $m) && !preg_match('/\bFROM\b/i', $m[1]) && !preg_match('/\bWHERE\b/i', $m[1])
                ? '(' . $m[1] . ')' : '(' . $sql . ')';
        }
    }

    /**
     * Una condición de IF: EXISTS sobre inserted/deleted, UPDATE(col),
     * @@ROWCOUNT, variables. 'CIERTO' o 'FALSO' si en este evento ya se sabe.
     */
    private function condicionSs(array $t): string
    {
        $c = $this->exprSs($t);
        $simple = trim(preg_replace('/\s+/', ' ', $c) ?? $c);
        if (in_array($simple, ['( (1 = 1) )', '(1 = 1)', '1 = 1', '( NOT (1 = 0) )'], true)) {
            return 'CIERTO';
        }
        if (in_array($simple, ['( (1 = 0) )', '(1 = 0)', '1 = 0', '( NOT (1 = 1) )'], true)) {
            return 'FALSO';
        }
        return $c;
    }

    /** INSERT, UPDATE o DELETE de T-SQL, con inserted/deleted pasados a NEW/OLD. */
    private function escrituraSs(array $s): string
    {
        $u = $this->palabra($s[0]);
        if ($u === 'INSERT') {
            $sel = $this->buscarOpcional($s, 'SELECT');
            if ($sel === null) {
                return $this->exprSs($s);           // INSERT … VALUES
            }
            $consulta = $this->consultaSs(array_slice($s, $sel));
            return $consulta === null ? '' : $this->exprSs(array_slice($s, 0, $sel)) . ' ' . $consulta;
        }
        // UPDATE x SET … [FROM t x JOIN inserted i ON …] [WHERE …] / DELETE [FROM] x [FROM …] [WHERE …]
        $objetivo = $u === 'UPDATE' ? $s[1] : ($this->palabra($s[1] ?? []) === 'FROM' ? $s[2] : $s[1]);
        $set = $u === 'UPDATE' ? $this->buscar($s, 0, ['SET']) : null;
        $desde = null;
        $nivel = 0;
        for ($k = ($set ?? 2) + 1, $n = count($s); $k < $n; $k++) {
            if ($s[$k]['v'] === '(') { $nivel++; }
            if ($s[$k]['v'] === ')') { $nivel--; }
            if ($nivel === 0 && $this->palabra($s[$k]) === 'FROM') { $desde = $k; break; }
        }
        $finSet = $desde ?? ($this->buscarOpcional($s, 'WHERE') ?? count($s));
        $donde = null;
        if ($desde === null) {
            $w = $this->buscarOpcional($s, 'WHERE');
            $donde = $w !== null ? array_slice($s, $w + 1) : null;
            $tabla = $objetivo['v'];
            $alias = $tabla;
        } else {
            // El FROM, como consulta: lo que no es inserted/deleted queda; sus ON van al WHERE
            $consulta = $this->consultaSs(array_merge([['k' => 'id', 'v' => 'SELECT'], ['k' => 'num', 'v' => '1']], array_slice($s, $desde)), true);
            if ($consulta === null) {
                return '';                          // sobre la tabla vacía en este evento
            }
            [$tabla, $alias, $condicion, $cambiarFrom] = $consulta;
            $donde = $condicion;
        }
        $cambios = $set !== null ? array_slice($s, $set + 1, $finSet - $set - 1) : [];
        $quitarAlias = isset($cambiarFrom) ? $cambiarFrom : fn(array $x) => $this->sinAlias($x, $alias, $tabla);
        $sql = $u === 'UPDATE'
            ? 'UPDATE ' . $this->tr->nombre($tabla) . ' SET ' . $this->exprSs($quitarAlias($cambios))
            : 'DELETE FROM ' . $this->tr->nombre($tabla);
        if ($donde !== null && $donde !== []) {
            $sql .= ' WHERE ' . (is_string($donde) ? $donde : $this->exprSs($quitarAlias($donde)));
        }
        return $sql;
    }

    /** alias.col → col, en un UPDATE o DELETE que aquí no tiene FROM. */
    private function sinAlias(array $t, string $alias, string $tabla): array
    {
        $out = [];
        for ($k = 0, $n = count($t); $k < $n; $k++) {
            if (($t[$k]['k'] ?? '') === 'id' && ($t[$k + 1]['v'] ?? '') === '.' && in_array(strtolower((string)$t[$k]['v']), [strtolower($alias), strtolower($tabla)], true)) {
                $k++;
                continue;
            }
            $out[] = $t[$k];
        }
        return $out;
    }

    /**
     * Un SELECT de T-SQL con inserted/deleted en su FROM: se quitan del FROM
     * (sus ON pasan al WHERE) y sus columnas pasan a NEW/OLD. null si lee de la
     * tabla que en este evento está vacía. Con $comoObjetivo, para el FROM de
     * un UPDATE/DELETE, devuelve [tabla, alias, condición] de la única tabla
     * que queda.
     *
     * @return string|array|null
     */
    private function consultaSs(array $t, bool $comoObjetivo = false)
    {
        $desde = null;
        $nivel = 0;
        foreach ($t as $k => $x) {
            if ($x['v'] === '(') { $nivel++; }
            if ($x['v'] === ')') { $nivel--; }
            if ($nivel === 0 && $this->palabra($x) === 'FROM') { $desde = $k; break; }
        }
        if ($desde === null) {
            return $comoObjetivo ? null : $this->exprSs($t);
        }
        // Fin del FROM: WHERE, GROUP, ORDER, HAVING o el final
        $fin = count($t);
        $nivel = 0;
        for ($k = $desde + 1, $n = count($t); $k < $n; $k++) {
            if ($t[$k]['v'] === '(') { $nivel++; }
            if ($t[$k]['v'] === ')') { $nivel--; }
            if ($nivel === 0 && in_array($this->palabra($t[$k]), ['WHERE', 'GROUP', 'ORDER', 'HAVING'], true)) { $fin = $k; break; }
        }
        // Las tablas del FROM, con su JOIN y su ON
        $origenes = [];
        $actual = ['join' => null, 'tokens' => [], 'on' => null];
        $nivel = 0;
        for ($k = $desde + 1; $k < $fin; $k++) {
            $x = $t[$k];
            $p = $this->palabra($x);
            if ($x['v'] === '(') { $nivel++; }
            if ($x['v'] === ')') { $nivel--; }
            if ($nivel === 0 && ($x['v'] === ',' || in_array($p, ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'FULL'], true))) {
                $origenes[] = $actual;
                $j = $k;
                while ($this->palabra($t[$j] ?? []) !== 'JOIN' && $t[$j]['v'] !== ',') { $j++; }
                $actual = ['join' => $x['v'] === ',' ? ',' : implode(' ', array_map(fn($y) => $y['v'], array_slice($t, $k, $j - $k + 1))), 'tokens' => [], 'on' => null];
                $k = $j;
                continue;
            }
            if ($nivel === 0 && $p === 'ON' && $actual['on'] === null) {
                $actual['on'] = [];
                continue;
            }
            if ($actual['on'] !== null) {
                $actual['on'][] = $x;
            } else {
                $actual['tokens'][] = $x;
            }
        }
        $origenes[] = $actual;

        $vacia = $this->ssEvento === 'INSERT' ? 'DELETED' : ($this->ssEvento === 'DELETE' ? 'INSERTED' : '');
        $pseudo = [];                               // alias → NEW/OLD
        $quedan = [];
        $condiciones = [];
        foreach ($origenes as $o) {
            $nombre = strtoupper((string)($o['tokens'][0]['v'] ?? ''));
            $alias = (string)(end($o['tokens'])['v'] ?? '');
            if (in_array($nombre, ['INSERTED', 'DELETED'], true) && empty($o['tokens'][0]['q'])) {
                if ($nombre === $vacia) {
                    return null;
                }
                $pseudo[strtolower($alias)] = $nombre === 'INSERTED' ? 'NEW' : 'OLD';
                $pseudo[strtolower($nombre)] = $nombre === 'INSERTED' ? 'NEW' : 'OLD';
                if ($o['on'] !== null) { $condiciones[] = $o['on']; }
                continue;
            }
            if ($o['on'] !== null && $quedan === []) {
                $condiciones[] = $o['on'];          // su JOIN era con inserted: el ON pasa al WHERE
                $o['on'] = null;
            }
            $quedan[] = $o;
        }
        // Columnas de inserted/deleted: alias.col → NEW.col; sin alias, si
        // solo se leía de ellas, las de la tabla del trigger
        $soloPseudo = $quedan === [] ? (reset($pseudo) ?: null) : null;
        // En el FROM de un UPDATE/DELETE, la tabla que queda es la que se
        // escribe: aquí sus columnas van sin alias
        $objetivo = $comoObjetivo && count($quedan) === 1
            ? [strtolower((string)$quedan[0]['tokens'][0]['v']), strtolower((string)end($quedan[0]['tokens'])['v'])] : [];
        $cambiar = function (array $x) use ($pseudo, $soloPseudo, $objetivo): array {
            $out = [];
            for ($k = 0, $n = count($x); $k < $n; $k++) {
                $tk = $x[$k];
                if ($tk['k'] === 'id' && ($x[$k + 1]['v'] ?? '') === '.' && isset($pseudo[strtolower((string)$tk['v'])])) {
                    $out[] = ['k' => 'id', 'v' => $pseudo[strtolower((string)$tk['v'])]];
                    continue;
                }
                if ($tk['k'] === 'id' && ($x[$k + 1]['v'] ?? '') === '.' && in_array(strtolower((string)$tk['v']), $objetivo, true)) {
                    $k++;                           // c.saldo → saldo
                    continue;
                }
                if ($soloPseudo !== null && $tk['k'] === 'id' && ($x[$k - 1]['v'] ?? '') !== '.' && ($x[$k + 1]['v'] ?? '') !== '('
                    && isset($this->ssColumnas[strtolower((string)$tk['v'])])) {
                    array_push($out, ['k' => 'id', 'v' => $soloPseudo], ['k' => 'op', 'v' => '.']);
                }
                $out[] = $tk;
            }
            return $out;
        };
        $where = $fin < count($t) && $this->palabra($t[$fin]) === 'WHERE' ? array_slice($t, $fin + 1) : [];
        $cola = $where !== [] ? [] : array_slice($t, $fin);
        if ($where !== []) {
            // WHERE … [GROUP/ORDER…]: lo de después del WHERE va aparte
            $nivel = 0;
            foreach ($where as $i => $x) {
                if ($x['v'] === '(') { $nivel++; }
                if ($x['v'] === ')') { $nivel--; }
                if ($nivel === 0 && in_array($this->palabra($x), ['GROUP', 'ORDER', 'HAVING'], true)) {
                    $cola = array_slice($where, $i);
                    $where = array_slice($where, 0, $i);
                    break;
                }
            }
            $condiciones[] = $where;
        }
        $cond = $condiciones === [] ? null : implode(' AND ', array_map(fn($c) => '(' . $this->exprSs($cambiar($c)) . ')', $condiciones));

        if ($comoObjetivo) {
            if (count($quedan) !== 1) {
                throw new NoTraducible(t('UPDATE o DELETE con varias tablas en su FROM'));
            }
            $tk = $quedan[0]['tokens'];
            return [(string)$tk[0]['v'], (string)end($tk)['v'], $cond, $cambiar];
        }
        $lista = array_slice($t, 0, $desde);
        if ($quedan === [] && count($lista) === 2 && $lista[1]['v'] === '*') {
            $lista[1] = ['k' => 'num', 'v' => '1'];  // SELECT * FROM inserted → SELECT 1
        }
        $sql = $this->exprSs($cambiar($lista));
        if ($quedan !== []) {
            $partes = [];
            foreach ($quedan as $i => $o) {
                $partes[] = ($i === 0 ? '' : ($o['join'] === ',' ? ', ' : $o['join'] . ' ')) . $this->exprSs($o['tokens'])
                    . ($o['on'] !== null ? ' ON ' . $this->exprSs($cambiar($o['on'])) : '');
            }
            $sql .= ' FROM ' . implode(' ', $partes);
        }
        if ($cond !== null) {
            $sql .= " WHERE $cond";
        }
        return $cola !== [] ? $sql . ' ' . $this->exprSs($cambiar($cola)) : $sql;
    }

    /**
     * Una expresión de T-SQL: variables por su valor, UPDATE(col),
     * EXISTS(SELECT … FROM inserted …) y las subconsultas sobre inserted/deleted.
     */
    private function exprSs(array $t): string
    {
        $out = [];
        for ($k = 0, $n = count($t); $k < $n; $k++) {
            $x = $t[$k];
            // @variable y @@ROWCOUNT
            if ($x['v'] === '@') {
                $doble = ($t[$k + 1]['v'] ?? '') === '@';
                $nombre = '@' . ($doble ? '@' : '') . strtolower((string)($t[$k + ($doble ? 2 : 1)]['v'] ?? ''));
                $valor = $this->ssVars[strtoupper($nombre)] ?? $this->ssVars[$nombre] ?? null;
                if ($valor === null) {
                    throw new NoTraducible(t('variables locales (DECLARE): aquí no hay variables'));
                }
                $out[] = ['k' => 'raw', 'v' => $valor];
                $k += $doble ? 2 : 1;
                continue;
            }
            // UPDATE(col): en INSERT, siempre; en UPDATE, si ha cambiado
            if ($this->palabra($x) === 'UPDATE' && ($t[$k + 1]['v'] ?? '') === '(' && $k > 0) {
                $col = $this->tr->nombre((string)$t[$k + 2]['v']);
                $out[] = ['k' => 'raw', 'v' => $this->ssEvento === 'UPDATE'
                    ? "((OLD.$col <> NEW.$col) OR (OLD.$col IS NULL AND NEW.$col IS NOT NULL) OR (OLD.$col IS NOT NULL AND NEW.$col IS NULL))"
                    : ($this->ssEvento === 'INSERT' ? '(1 = 1)' : '(1 = 0)')];
                $k += 3;
                continue;
            }
            // (SELECT …) con inserted/deleted
            if ($x['v'] === '(' && $this->palabra($t[$k + 1] ?? []) === 'SELECT') {
                $cierre = $this->cierre($t, $k);
                $sub = $this->consultaSs(array_slice($t, $k + 1, $cierre - $k - 1));
                $existe = $this->palabra($t[$k - 1] ?? []) === 'EXISTS';
                if ($sub === null) {
                    // Sobre la tabla vacía: EXISTS es falso; otra subconsulta, NULL
                    if ($existe) {
                        array_pop($out);
                        $no = ($out !== [] && $this->palabra(end($out)) === 'NOT');
                        if ($no) { array_pop($out); }
                        $out[] = ['k' => 'raw', 'v' => $no ? '(1 = 1)' : '(1 = 0)'];
                    } else {
                        $out[] = ['k' => 'raw', 'v' => 'NULL'];
                    }
                } else {
                    $out[] = ['k' => 'raw', 'v' => "($sub)"];
                }
                $k = $cierre;
                continue;
            }
            $out[] = $x;
        }
        return $this->expr($out);
    }

    /** El índice del END que cierra el BEGIN de $k. */
    private function finBloque(array $t, int $k): int
    {
        $nivel = 0;
        for ($n = count($t); $k < $n; $k++) {
            $u = $this->palabra($t[$k]);
            $sig = $this->palabra($t[$k + 1] ?? []);
            if ($u === 'BEGIN' || ($u === 'CASE')) {
                $nivel++;
            } elseif ($u === 'END' && !in_array($sig, ['IF', 'LOOP', 'WHILE', 'REPEAT'], true)) {
                $nivel--;
                if ($nivel === 0) {
                    return $k;
                }
            }
        }
        throw new NoTraducible(t('falta el END del cuerpo'));
    }

    /**
     * Las sentencias de un cuerpo, en el SQL de aquí, separadas por ;.
     *
     * @param list<array> $t
     */
    private function bloque(array $t, string $momento): string
    {
        $out = '';
        $n = count($t);
        for ($k = 0; $k < $n;) {
            $u = $this->palabra($t[$k]);
            if ($t[$k]['v'] === ';') {
                $k++;
                continue;
            }
            if ($u === 'IF') {
                [$texto, $k] = $this->siMysql($t, $k, $momento);
                $out .= $texto . '; ';
                continue;
            }
            if ($u === 'BEGIN') {
                $fin = $this->finBloque($t, $k);
                $out .= $this->bloque(array_slice($t, $k + 1, $fin - $k - 1), $momento);
                $k = $fin + 1;
                continue;
            }
            $fin = $this->finSentencia($t, $k);
            $s = array_slice($t, $k, $fin - $k);
            $k = $fin;
            $traducida = $this->sentencia($s, $momento);
            if ($traducida !== '') {
                $out .= $traducida . '; ';
            }
        }
        return $out;
    }

    /** IF cond THEN … [ELSEIF cond THEN …] [ELSE …] END IF, con su ;. @return array{0: string, 1: int} */
    private function siMysql(array $t, int $k, string $momento): array
    {
        $texto = '';
        $palabra = 'IF';
        while (true) {
            $then = $this->buscar($t, $k + 1, ['THEN']);
            $cond = $this->expr(array_slice($t, $k + 1, $then - $k - 1));
            $fin = $this->buscar($t, $then + 1, ['ELSEIF', 'ELSIF', 'ELSE', 'END'], true);
            $texto .= "$palabra $cond THEN " . $this->bloqueONulo(array_slice($t, $then + 1, $fin - $then - 1), $momento);
            $u = $this->palabra($t[$fin]);
            if ($u === 'ELSEIF' || $u === 'ELSIF') {
                $palabra = 'ELSEIF';
                $k = $fin;
                continue;
            }
            if ($u === 'ELSE') {
                $finElse = $this->buscar($t, $fin + 1, ['END'], true);
                $texto .= 'ELSE ' . $this->bloqueONulo(array_slice($t, $fin + 1, $finElse - $fin - 1), $momento);
                $fin = $finElse;
            }
            // END IF ;
            return [$texto . 'END IF', $fin + 2];
        }
    }

    /** Una rama vacía (lo que había no se traduce a nada) necesita una sentencia que no haga nada. */
    private function bloqueONulo(array $t, string $momento): string
    {
        $b = $this->bloque($t, $momento);
        return $b !== '' ? $b : 'SELECT 0; ';
    }

    /**
     * Busca la siguiente de $palabras en el nivel actual: sin entrar en
     * paréntesis ni, si $bloques, en IF … END IF anidados.
     */
    private function buscar(array $t, int $k, array $palabras, bool $bloques = false): int
    {
        $nivel = 0;
        $anid = 0;
        for ($n = count($t); $k < $n; $k++) {
            $v = $t[$k]['v'];
            if ($v === '(') { $nivel++; continue; }
            if ($v === ')') { $nivel--; continue; }
            if ($nivel > 0) { continue; }
            $u = $this->palabra($t[$k]);
            if ($bloques && $u === 'IF' && $this->palabra($t[$k - 1] ?? []) !== 'END') { $anid++; continue; }
            if ($bloques && $u === 'END' && $this->palabra($t[$k + 1] ?? []) === 'IF' && $anid > 0) { $anid--; $k++; continue; }
            if ($anid === 0 && in_array($u, $palabras, true)) {
                return $k;
            }
        }
        throw new NoTraducible(t('falta {p}', ['p' => implode('/', $palabras)]));
    }

    /** El final (el ;) de la sentencia que empieza en $k. */
    private function finSentencia(array $t, int $k): int
    {
        $nivel = 0;
        for ($n = count($t); $k < $n; $k++) {
            $v = $t[$k]['v'];
            if ($v === '(') { $nivel++; }
            if ($v === ')') { $nivel--; }
            if ($v === ';' && $nivel === 0) {
                return $k;
            }
        }
        return $k;
    }

    /** Una sentencia del cuerpo de un trigger de MySQL. */
    private function sentencia(array $s, string $momento): string
    {
        $u = $this->palabra($s[0]);
        if ($this->d === 'postgresql') {
            switch ($u) {
                case 'NEW':
                    // NEW.col := expr (o =). En un AFTER no tiene efecto en PostgreSQL
                    if ($momento !== 'BEFORE') {
                        return '';
                    }
                    $op = 3;
                    if (($s[1]['v'] ?? '') !== '.' || !in_array($s[$op]['v'] ?? '', [':=', ':', '='], true)) {
                        throw new NoTraducible(t('asignación sin ='));
                    }
                    $desde = ($s[$op]['v'] === ':' && ($s[$op + 1]['v'] ?? '') === '=') ? $op + 2 : $op + 1;
                    return 'SET NEW.' . $this->tr->nombre($s[2]['v']) . ' = ' . $this->expr(array_slice($s, $desde));
                case 'RAISE':
                    $nivel = $this->palabra($s[1] ?? []);
                    if (in_array($nivel, ['NOTICE', 'WARNING', 'INFO', 'LOG', 'DEBUG'], true)) {
                        return '';                  // un mensaje, sin efecto en los datos
                    }
                    $textos = array_values(array_filter($s, static fn($x) => $x['k'] === 'str'));
                    if (count(array_filter($s, static fn($x) => $x['v'] === ',')) > 0) {
                        $this->tr->avisar(t('Mensajes de error compuestos en triggers: se importa solo su texto fijo'));
                    }
                    return 'SELECT RAISE(ABORT, ' . $this->tr->texto([$textos[0] ?? ['k' => 'str', 'v' => 'Error']]) . ')';
                case 'RETURN':
                    if ($momento === 'BEFORE' && $this->palabra($s[1] ?? []) === 'NULL') {
                        throw new NoTraducible(t('RETURN NULL en un trigger BEFORE: aquí no se puede cancelar una escritura en silencio'));
                    }
                    return '';
                case 'PERFORM':
                case 'NULL':
                    return '';
            }
        }
        switch ($u) {
            case 'SET':
                return $this->asignacion($s, $momento);
            case 'SIGNAL':
                return 'SELECT RAISE(ABORT, ' . $this->mensaje($s) . ')';
            case 'INSERT':
            case 'REPLACE':
                return $this->insertar($s);
            case 'UPDATE':
            case 'DELETE':
                return $this->expr($s);
            case 'SELECT':
                if ($this->buscarOpcional($s, 'INTO') !== null) {
                    throw new NoTraducible(t('SELECT … INTO variable: aquí no hay variables'));
                }
                return '';                          // un SELECT suelto no hace nada en un trigger
            case 'DECLARE':
                throw new NoTraducible(t('variables locales (DECLARE): aquí no hay variables'));
        }
        throw new NoTraducible(t("sentencia '{s}' sin equivalente en un trigger de aquí", ['s' => $u !== '' ? $u : $s[0]['v']]));
    }

    /** SET NEW.col = expr [, NEW.col = expr] */
    private function asignacion(array $s, string $momento): string
    {
        $partes = [];
        foreach ($this->separar(array_slice($s, 1)) as $a) {
            if ($this->palabra($a[0] ?? []) !== 'NEW' || ($a[1]['v'] ?? '') !== '.') {
                throw new NoTraducible(t('SET a una variable: aquí solo se puede cambiar NEW.columna'));
            }
            $igual = 3;
            if (!in_array($a[$igual]['v'] ?? '', ['=', ':='], true)) {
                throw new NoTraducible(t('asignación sin ='));
            }
            $partes[] = 'NEW.' . $this->tr->nombre($a[2]['v']) . ' = ' . $this->expr(array_slice($a, $igual + 1));
        }
        if ($momento !== 'BEFORE') {
            throw new NoTraducible(t('SET NEW en un trigger AFTER'));
        }
        return 'SET ' . implode(', ', $partes);
    }

    /** El mensaje de un SIGNAL … SET MESSAGE_TEXT = '…', como literal. */
    private function mensaje(array $s): string
    {
        $k = $this->buscarOpcional($s, 'MESSAGE_TEXT');
        if ($k === null) {
            return "'Error'";
        }
        $valor = array_slice($s, $k + 2);
        // RAISE solo admite un texto fijo: si el mensaje se compone, se toma su
        // parte fija y se dice en el resumen
        $textos = array_values(array_filter($valor, static fn($x) => $x['k'] === 'str'));
        if (count($valor) !== 1) {
            $this->tr->avisar(t('Mensajes de error compuestos en triggers: se importa solo su texto fijo'));
        }
        return $this->tr->texto([$textos[0] ?? ['k' => 'str', 'v' => 'Error']]);
    }

    /** INSERT, REPLACE (como INSERT) y la forma INSERT INTO t SET a = 1, b = 2. */
    private function insertar(array $s): string
    {
        if ($this->palabra($s[0]) === 'REPLACE') {
            throw new NoTraducible(t('REPLACE INTO: aquí no hay sustitución de filas por clave'));
        }
        if ($this->buscarOpcional($s, 'DUPLICATE') !== null) {
            throw new NoTraducible(t('INSERT … ON DUPLICATE KEY UPDATE'));
        }
        $k = $this->palabra($s[1] ?? []) === 'INTO' ? 2 : 1;
        if ($this->palabra($s[$k + 1] ?? []) === 'SET') {
            $cols = [];
            $vals = [];
            foreach ($this->separar(array_slice($s, $k + 2)) as $a) {
                $cols[] = $this->tr->nombre($a[0]['v']);
                $vals[] = $this->expr(array_slice($a, 2));
            }
            return 'INSERT INTO ' . $this->tr->nombre($s[$k]['v']) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
        }
        return $this->expr($s);
    }

    // ------------------------------------------------------------------
    // Expresiones
    // ------------------------------------------------------------------

    /**
     * Tokens de una expresión (o de una sentencia entera) en el SQL de aquí,
     * con las funciones y operadores de MySQL reescritos.
     */
    public function expr(array $t): string
    {
        $t = $this->operadores($t);
        $out = [];
        $n = count($t);
        for ($k = 0; $k < $n; $k++) {
            $x = $t[$k];
            $u = $this->palabra($x);
            if ($u === 'COLLATE') {
                $k++;
                continue;
            }
            if ($u === '' && $this->d === 'postgresql' && $x['k'] === 'id' && !empty($x['q']) && ($t[$k + 1]['v'] ?? '') === '('
                && in_array(strtoupper($x['v']), ['SUBSTRING', 'LEFT', 'RIGHT', 'POSITION', 'TRIM', 'OVERLAY'], true)) {
                $u = strtoupper($x['v']);
            }
            // STRING_AGG(x, sep) WITHIN GROUP (ORDER BY …) de SQL Server: el orden, dentro
            if ($u === 'STRING_AGG' && $this->palabra($t[$this->cierre($t, $k + 1) + 1] ?? []) === 'WITHIN') {
                $cierre = $this->cierre($t, $k + 1);
                $finGrupo = $this->cierre($t, $cierre + 3);
                $args = array_merge(array_slice($t, $k + 2, $cierre - $k - 2), array_slice($t, $cierre + 4, $finGrupo - $cierre - 4));
                $out[] = $this->funcion('STRING_AGG', 'STRING_AGG', $args);
                $k = $finGrupo;
                continue;
            }
            if ($u !== '' && ($t[$k + 1]['v'] ?? '') === '(' && !in_array($u, ['IN', 'EXISTS', 'AS', 'ON', 'AND', 'OR', 'NOT', 'VALUES', 'INTO', 'WHERE', 'FROM', 'JOIN', 'USING', 'OVER', 'THEN', 'ELSE', 'WHEN'], true)
                && ($this->palabra($t[$k - 1] ?? []) !== 'INTO')) {
                $cierre = $this->cierre($t, $k + 1);
                $out[] = $this->funcion($u, $x['v'], array_slice($t, $k + 2, $cierre - $k - 2));
                $k = $cierre;
                continue;
            }
            switch ($u) {
                case 'CURRENT_TIMESTAMP': case 'LOCALTIMESTAMP': case 'LOCALTIME': case 'SYSDATE':
                    $out[] = "DATETIME('now')"; continue 2;
                case 'CURRENT_DATE':
                    $out[] = "DATE('now')"; continue 2;
                case 'CURRENT_TIME':
                    $out[] = "TIME('now')"; continue 2;
                case 'XOR':
                    throw new NoTraducible(t('el operador XOR'));
            }
            if ($x['k'] === 'op' && $x['v'] === '&&') { $out[] = 'AND'; continue; }
            if ($x['k'] === 'op' && $x['v'] === '!' ) { $out[] = 'NOT'; continue; }
            $out[] = $this->tr->texto([$x]);
        }
        return implode(' ', $out);
    }

    /**
     * Los operadores de MySQL que aquí no existen, reescritos con sus dos
     * operandos: x + INTERVAL n UNIDAD (así guarda MySQL un DATE_ADD), a MOD b,
     * a DIV b y a <=> b. El resultado va como un token ya escrito.
     */
    private function operadores(array $t): array
    {
        $t = $this->esVerdadero($t);
        if ($this->d === 'postgresql') {
            $t = $this->operadoresPg($t);
        }
        if ($this->d === 'sqlserver' || $this->d === 'access') {
            $t = $this->concatenarSs($t);
        }
        if ($this->d === 'access') {
            $t = $this->operadoresAccess($t);
        }
        for ($k = 1; $k < count($t); $k++) {
            $x = $t[$k];
            $u = $this->palabra($x);
            $esIntervalo = $x['k'] === 'op' && in_array($x['v'], ['+', '-'], true) && $this->palabra($t[$k + 1] ?? []) === 'INTERVAL';
            // a MOD b y a DIV b (MOD(a, b) es una función, no esto)
            $infijo = in_array($u, ['MOD', 'DIV'], true) && ($t[$k + 1]['v'] ?? '') !== '(';
            if (!$esIntervalo && !$infijo && !($x['k'] === 'op' && $x['v'] === '<=>')) {
                continue;
            }
            $ini = $this->inicioOperando($t, $k - 1);
            $izq = $this->expr(array_slice($t, $ini, $k - $ini));
            if ($esIntervalo) {
                // INTERVAL cantidad UNIDAD: la cantidad es un operando y la unidad, una palabra
                $finCant = $this->finOperando($t, $k + 2);
                $unidad = $t[$finCant + 1] ?? ['k' => 'id', 'v' => ''];
                $args = [array_slice($t, $ini, $k - $ini),
                         array_merge([['k' => 'id', 'v' => 'INTERVAL']], array_slice($t, $k + 2, $finCant - $k - 1), [$unidad])];
                $texto = $this->sumarFecha($x['v'] === '-' ? 'DATE_SUB' : 'DATE_ADD', $args);
                $fin = $finCant + 1;
            } else {
                $fin = $this->finOperando($t, $k + 1);
                $der = $this->expr(array_slice($t, $k + 1, $fin - $k));
                $texto = ['MOD' => "($izq % $der)", 'DIV' => "CAST($izq / $der AS INTEGER)"][$u]
                    // <=> da 1 o 0, nunca NULL: 2 <=> NULL es 0
                    ?? "(CASE WHEN ($izq) IS NULL AND ($der) IS NULL THEN 1 WHEN ($izq) IS NULL OR ($der) IS NULL THEN 0 WHEN ($izq) = ($der) THEN 1 ELSE 0 END)";
            }
            array_splice($t, $ini, $fin - $ini + 1, [['k' => 'raw', 'v' => $texto]]);
            $k = $ini;
        }
        return $t;
    }

    /**
     * En SQL Server, + también concatena: es || cuando uno de los dos lados es
     * texto (una cadena, una columna de texto, una función que da texto o un
     * CAST a texto).
     */
    private function concatenarSs(array $t): array
    {
        for ($k = 1; $k < count($t); $k++) {
            if (!($t[$k]['k'] === 'op' && $t[$k]['v'] === '+')) {
                continue;
            }
            $ini = $this->inicioOperando($t, $k - 1);
            $fin = $this->finOperando($t, $k + 1);
            if ($this->esTextoSs(array_slice($t, $ini, $k - $ini)) || $this->esTextoSs(array_slice($t, $k + 1, $fin - $k))) {
                $t[$k] = ['k' => 'op', 'v' => '||'];
            }
        }
        return $t;
    }

    private function esTextoSs(array $op): bool
    {
        if ($op === []) {
            return false;
        }
        if (count($op) === 1 && $op[0]['k'] === 'str') {
            return true;
        }
        if (($op[0]['k'] ?? '') === 'raw') {
            return (bool)preg_match("/^\(?'|^\(?(UPPER|LOWER|SUBSTR|TRIM|LTRIM|RTRIM|REPLACE|STRFTIME|COALESCE\(CAST)|AS TEXT\)\)?$/", (string)$op[0]['v']);
        }
        $f = $this->palabra($op[0]);
        if (in_array($f, ['UPPER', 'LOWER', 'LEFT', 'RIGHT', 'SUBSTRING', 'LTRIM', 'RTRIM', 'TRIM', 'REPLACE', 'CONCAT', 'FORMAT', 'STR'], true)) {
            return true;
        }
        if (in_array($f, ['CAST', 'CONVERT'], true)) {
            foreach ($op as $x) {
                if (in_array($this->palabra($x), ['VARCHAR', 'NVARCHAR', 'CHAR', 'NCHAR', 'TEXT', 'NTEXT'], true)) {
                    return true;
                }
            }
            return false;
        }
        if ($f === 'ISNULL' || $f === 'COALESCE') {
            return $this->esTextoSs(array_slice($op, 2, 1));
        }
        $ultimo = end($op);
        return $ultimo['k'] === 'id' && $this->tr->esColumnaTexto((string)$ultimo['v']);
    }

    /**
     * Lo propio de Access: Tabla!Campo, a & b (concatena y un NULL cuenta como
     * texto vacío, salvo si los dos lo son), a \ b (división entera) y LIKE con
     * los comodines de Access (* ? # [...]) o ALIKE con los de siempre.
     */
    private function operadoresAccess(array $t): array
    {
        for ($k = 1; $k < count($t); $k++) {
            $x = $t[$k];
            if ($x['k'] === 'op' && $x['v'] === '!' && ($t[$k - 1]['k'] ?? '') === 'id' && ($t[$k + 1]['k'] ?? '') === 'id') {
                $t[$k] = ['k' => 'op', 'v' => '.'];
                continue;
            }
            $u = $this->palabra($x);
            // a ^ b con dos números escritos: el resultado (aquí no hay potencia)
            if ($x['k'] === 'op' && $x['v'] === '^') {
                if (($t[$k - 1]['k'] ?? '') === 'num' && ($t[$k + 1]['k'] ?? '') === 'num') {
                    $valor = (float)$t[$k - 1]['v'] ** (float)$t[$k + 1]['v'];
                    array_splice($t, $k - 1, 3, [['k' => 'num', 'v' => $valor == (int)$valor ? (string)(int)$valor : (string)$valor]]);
                    $k--;
                    continue;
                }
                throw new NoTraducible(t('el operador ^ con valores calculados'));
            }
            if (($x['k'] === 'op' && in_array($x['v'], ['&', '\\'], true)) || $u === 'LIKE' || $u === 'ALIKE') {
                $ini = $this->inicioOperando($t, $k - 1);
                $no = $u !== '' && $this->palabra($t[$ini - 1] ?? []) === 'NOT';
                $fin = $this->finOperando($t, $k + 1);
                $a = $this->expr(array_slice($t, $ini, $k - $ini));
                $der = array_slice($t, $k + 1, $fin - $k);
                if ($x['v'] === '&') {
                    // La cadena entera (a & b & c) de una vez: de dos en dos, cada
                    // paso repetía los anteriores y la expresión crecía al doble
                    // con cada &
                    $partes = [$a, $this->expr($der)];
                    while (($t[$fin + 1]['k'] ?? '') === 'op' && ($t[$fin + 1]['v'] ?? '') === '&') {
                        $desde = $fin + 2;
                        $fin = $this->finOperando($t, $desde);
                        $partes[] = $this->expr(array_slice($t, $desde, $fin - $desde + 1));
                    }
                    $texto = '(CASE WHEN COALESCE(' . implode(', ', $partes) . ') IS NULL THEN NULL ELSE '
                        . implode(' || ', array_map(static fn($p) => "COALESCE($p, '')", $partes)) . ' END)';
                } elseif ($x['v'] === '\\') {
                    $texto = "CAST(($a) / (" . $this->expr($der) . ') AS INTEGER)';
                } else {
                    $texto = $this->likeAccess($a, $der, $u === 'ALIKE');
                    if ($no) {
                        $texto = "NOT ($texto)";
                        $ini--;
                    }
                }
                array_splice($t, $ini, $fin - $ini + 1, [['k' => 'raw', 'v' => $texto]]);
                $k = $ini;
            }
        }
        return $t;
    }

    /**
     * LIKE de Access: * es cualquier texto, ? un carácter, # una cifra y [..]
     * una clase. Sin # ni corchetes es el LIKE de aquí (que tampoco distingue
     * mayúsculas); con ellos, la expresión regular equivalente. ALIKE usa ya
     * % y _.
     */
    private function likeAccess(string $a, array $der, bool $ansi): string
    {
        if (count($der) !== 1 || $der[0]['k'] !== 'str') {
            return "$a LIKE " . $this->expr($der);
        }
        $p = (string)$der[0]['v'];
        if ($ansi) {
            return "$a LIKE " . $this->tr->texto([['k' => 'str', 'v' => $p]]);
        }
        if (!preg_match('/[#\[]/', $p)) {
            return "$a LIKE " . $this->tr->texto([['k' => 'str', 'v' => strtr($p, ['*' => '%', '?' => '_'])]]);
        }
        $re = '';
        foreach ((array)preg_split('/(\[[^\]]*\])/u', $p, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $trozo) {
            if ($trozo[0] === '[') {
                $re .= '[' . (str_starts_with($trozo, '[!') ? '^' . substr($trozo, 2, -1) : substr($trozo, 1, -1)) . ']';
                continue;
            }
            foreach ((array)preg_split('//u', $trozo, -1, PREG_SPLIT_NO_EMPTY) as $c) {
                $re .= ['*' => '.*', '?' => '.', '#' => '[0-9]'][$c] ?? preg_quote((string)$c, '/');
            }
        }
        return "$a REGEXP " . $this->tr->texto([['k' => 'str', 'v' => '(?is)^' . $re . '$']]);
    }

    /**
     * X IS [NOT] TRUE / FALSE / UNKNOWN. MySQL 8 guarda así un NOT EXISTS
     * («exists(…) is false») y un NOT IN con subconsulta («x in (…) is false»).
     * Aquí: CASE, que da 1 o 0 y nunca NULL, como esos operadores. X llega hasta
     * el AND, OR, NOT, WHERE… anterior: IS va detrás de las comparaciones.
     * TRUE y FALSE ya vienen como 1 y 0.
     */
    private function esVerdadero(array $t): array
    {
        for ($k = 1; $k < count($t); $k++) {
            if ($this->palabra($t[$k]) !== 'IS') {
                continue;
            }
            $no = $this->palabra($t[$k + 1] ?? []) === 'NOT';
            $v = $t[$k + ($no ? 2 : 1)] ?? null;
            if ($v === null) {
                continue;
            }
            $cual = $v['k'] === 'num' && in_array($v['v'], ['1', '0'], true) ? ($v['v'] === '1' ? 'TRUE' : 'FALSE') : $this->palabra($v);
            if (!in_array($cual, ['TRUE', 'FALSE', 'UNKNOWN'], true)) {
                continue;                           // IS NULL y los demás, como estaban
            }
            // Hacia atrás, hasta el conector anterior del mismo nivel
            $ini = $k - 1;
            $nivel = 0;
            for (; $ini >= 0; $ini--) {
                $x = $t[$ini];
                if ($x['v'] === ')') { $nivel++; continue; }
                if ($x['v'] === '(') { if ($nivel === 0) { break; } $nivel--; continue; }
                if ($nivel === 0 && ($x['v'] === ',' || in_array($this->palabra($x),
                        ['AND', 'OR', 'NOT', 'WHERE', 'ON', 'HAVING', 'WHEN', 'THEN', 'ELSE', 'SELECT', 'DISTINCT'], true))) {
                    break;
                }
            }
            $ini++;
            $x = $this->expr(array_slice($t, $ini, $k - $ini));
            $fin = $k + ($no ? 2 : 1);
            $texto = $cual === 'UNKNOWN' ? "(($x) IS " . ($no ? 'NOT ' : '') . 'NULL)'
                : '(CASE WHEN ' . ($cual === 'FALSE' ? "NOT ($x)" : "($x)") . ' THEN ' . ($no ? '0 ELSE 1' : '1 ELSE 0') . ' END)';
            array_splice($t, $ini, $fin - $ini + 1, [['k' => 'raw', 'v' => $texto]]);
            $k = $ini;
        }
        return $t;
    }

    /** Palabras que pueden ir delante de un paréntesis sin ser el nombre de una función. */
    private const NO_FUNCIONES = ['AND', 'OR', 'NOT', 'WHEN', 'THEN', 'ELSE', 'SELECT', 'WHERE', 'ON', 'IN', 'BY', 'AS', 'FROM',
        'DISTINCT', 'ORDER', 'GROUP', 'HAVING', 'LIMIT', 'OFFSET', 'CASE', 'IS', 'LIKE', 'BETWEEN', 'EXISTS', 'ALL', 'ANY', 'SOME',
        'RETURN', 'SET', 'VALUES', 'INTO', 'JOIN', 'USING', 'IF', 'ELSEIF', 'ELSIF', 'END', 'UNION', 'EXCEPT', 'INTERSECT', 'WITH'];

    /** Palabras que forman los tipos de PostgreSQL detrás de :: */
    private const TIPOS_PG = ['INTEGER', 'INT', 'INT2', 'INT4', 'INT8', 'BIGINT', 'SMALLINT', 'NUMERIC', 'DECIMAL', 'TEXT',
        'CHARACTER', 'VARYING', 'VARCHAR', 'CHAR', 'BPCHAR', 'NAME', 'TIMESTAMP', 'TIMESTAMPTZ', 'WITHOUT', 'WITH', 'TIME',
        'ZONE', 'DATE', 'BOOLEAN', 'BOOL', 'DOUBLE', 'PRECISION', 'REAL', 'FLOAT4', 'FLOAT8', 'INTERVAL', 'JSON', 'JSONB',
        'UUID', 'MONEY', 'REGCLASS'];

    /**
     * Lo propio de PostgreSQL, en el orden en que se une: x::tipo, x ± intervalo,
     * IS [NOT] DISTINCT FROM, = ANY (ARRAY[…]) / <> ALL (…), y los operadores
     * con los que pg_dump escribe LIKE (~~) y las expresiones regulares (~).
     */
    private function operadoresPg(array $t): array
    {
        // x::tipo
        for ($k = 1; $k < count($t); $k++) {
            if (!($t[$k]['k'] === 'op' && $t[$k]['v'] === '::')) {
                continue;
            }
            $ini = $this->inicioOperando($t, $k - 1);
            $fin = $k;
            $tipo = '';
            // time(0) without time zone: palabras, un paréntesis y más palabras
            while (true) {
                while (in_array($this->palabra($t[$fin + 1] ?? []), self::TIPOS_PG, true)) {
                    $tipo .= ' ' . $this->palabra($t[++$fin]);
                }
                if ($tipo !== '' && ($t[$fin + 1]['v'] ?? '') === '(') {
                    $fin = $this->cierre($t, $fin + 1);
                    continue;
                }
                break;
            }
            $esLista = false;
            while (($t[$fin + 1]['v'] ?? '') === '[' && ($t[$fin + 2]['v'] ?? '') === ']') {
                $fin += 2;                          // ::text[]
                $esLista = true;
            }
            $op = array_slice($t, $ini, $k - $ini);
            $tipo = trim($tipo);
            $literal = count($op) === 1 && in_array($op[0]['k'], ['str', 'num'], true);
            if ($esLista || ($literal && !in_array($tipo, ['DATE', 'INTERVAL'], true) && !str_starts_with($tipo, 'TIMESTAMP'))) {
                // 'x'::text, 7::numeric, ARRAY[…]::text[]: el valor tal cual
                array_splice($t, $ini, $fin - $ini + 1, $op);
                $k = $ini;
                continue;
            }
            if ($tipo === 'INTERVAL') {
                if (count($op) !== 1 || $op[0]['k'] !== 'str') {
                    throw new NoTraducible(t('un intervalo calculado'));
                }
                $nuevo = ['k' => 'intervalo', 'v' => $op[0]['v']];
            } else {
                $x = $this->expr($op);
                if (preg_match('/^(INTEGER|INT[248]?|BIGINT|SMALLINT)$/', $tipo)) {
                    $texto = "CAST(ROUND($x) AS INTEGER)";
                } elseif (in_array($tipo, ['DATE'], true)) {
                    $texto = "DATE($x)";
                } elseif (str_starts_with($tipo, 'TIMESTAMP')) {
                    $texto = "DATETIME($x)";
                } elseif (str_starts_with($tipo, 'TIME')) {
                    $texto = "TIME($x)";
                } elseif (preg_match('/^(TEXT|CHARACTER|VARCHAR|CHAR|BPCHAR|NAME)/', $tipo)) {
                    $texto = "CAST($x AS TEXT)";
                } else {
                    $texto = $x;                    // numeric, boolean…: el valor ya es ese
                }
                $nuevo = ['k' => 'raw', 'v' => count($op) > 1 && $texto === $x ? "($x)" : $texto];
            }
            array_splice($t, $ini, $fin - $ini + 1, [$nuevo]);
            $k = $ini;
        }
        for ($k = 1; $k < count($t); $k++) {
            $x = $t[$k];
            // x + '1 day'::interval
            if ($x['k'] === 'op' && in_array($x['v'], ['+', '-'], true) && ($t[$k + 1]['k'] ?? '') === 'intervalo') {
                $ini = $this->inicioOperando($t, $k - 1);
                $mods = array_map(static fn($m) => "'" . ($x['v'] === '-' ? (str_starts_with($m, '-') ? substr($m, 1) : '-' . ltrim($m, '+')) : $m) . "'",
                    $this->modificadoresIntervalo((string)$t[$k + 1]['v']));
                $texto = 'DATETIME(' . $this->expr(array_slice($t, $ini, $k - $ini)) . ', ' . implode(', ', $mods) . ')';
                array_splice($t, $ini, $k + 2 - $ini, [['k' => 'raw', 'v' => $texto]]);
                $k = $ini;
                continue;
            }
            // a IS [NOT] DISTINCT FROM b
            if ($this->palabra($x) === 'IS' && in_array('DISTINCT', [$this->palabra($t[$k + 1] ?? []), $this->palabra($t[$k + 2] ?? [])], true)) {
                $no = $this->palabra($t[$k + 1]) === 'NOT';
                $desde = $k + ($no ? 4 : 3);
                $ini = $this->inicioOperando($t, $k - 1);
                $fin = $this->finOperando($t, $desde);
                $a = $this->expr(array_slice($t, $ini, $k - $ini));
                $b = $this->expr(array_slice($t, $desde, $fin - $desde + 1));
                $distinto = "(($a <> $b) OR ($a IS NULL AND $b IS NOT NULL) OR ($a IS NOT NULL AND $b IS NULL))";
                array_splice($t, $ini, $fin - $ini + 1, [['k' => 'raw', 'v' => $no ? "(NOT $distinto)" : $distinto]]);
                $k = $ini;
                continue;
            }
            // x = ANY (ARRAY[…]) / x <> ALL (ARRAY[…]) / x = ANY (SELECT …)
            if ($x['k'] === 'op' && in_array($x['v'], ['=', '<>', '!='], true) && in_array($this->palabra($t[$k + 1] ?? []), ['ANY', 'ALL'], true)
                && ($t[$k + 2]['v'] ?? '') === '(') {
                $cuantos = $this->palabra($t[$k + 1]);
                if (($x['v'] === '=') !== ($cuantos === 'ANY')) {
                    throw new NoTraducible(t('{op} {c} (…)', ['op' => $x['v'], 'c' => $cuantos]));
                }
                $ini = $this->inicioOperando($t, $k - 1);
                $cierre = $this->cierre($t, $k + 2);
                $dentro = array_slice($t, $k + 3, $cierre - $k - 3);
                while (($dentro[0]['v'] ?? '') === '(' && $this->cierre($dentro, 0) === count($dentro) - 1) {
                    $dentro = array_slice($dentro, 1, -1);      // ((ARRAY[…]))
                }
                if ($this->palabra($dentro[0] ?? []) === 'ARRAY') {
                    $dentro = array_slice($dentro, 2, -1);      // ARRAY[ … ]
                }
                $lista = $this->palabra($dentro[0] ?? []) === 'SELECT' ? $this->consulta($dentro) : $this->expr($dentro);
                $texto = $this->expr(array_slice($t, $ini, $k - $ini)) . ($cuantos === 'ANY' ? ' IN (' : ' NOT IN (') . $lista . ')';
                array_splice($t, $ini, $cierre - $ini + 1, [['k' => 'raw', 'v' => $texto]]);
                $k = $ini;
                continue;
            }
            // ~~ (LIKE), ~~* (ILIKE), ~ y ~* (expresiones regulares), con ! delante si es NOT
            if ($x['k'] === 'op' && ($x['v'] === '~' || ($x['v'] === '!' && ($t[$k + 1]['v'] ?? '') === '~'))) {
                $no = $x['v'] === '!';
                $j = $k + ($no ? 1 : 0);
                $like = ($t[$j + 1]['v'] ?? '') === '~';
                $j += $like ? 1 : 0;
                $sinCaja = ($t[$j + 1]['v'] ?? '') === '*';
                $j += $sinCaja ? 1 : 0;
                $ini = $this->inicioOperando($t, $k - 1);
                $fin = $this->finOperando($t, $j + 1);
                $izq = $this->expr(array_slice($t, $ini, $k - $ini));
                $der = array_slice($t, $j + 1, $fin - $j);
                // LIKE … ESCAPE lo guarda PostgreSQL como ~~ like_escape(patrón, escape)
                if ($like && strtoupper((string)($der[0]['v'] ?? '')) === 'LIKE_ESCAPE' && ($der[1]['v'] ?? '') === '(') {
                    $args = $this->separar(array_slice($der, 2, -1));
                    $escape = $args[1][0]['v'] ?? null;
                    $der = $args[0] ?? $der;
                }
                $texto = $like ? $this->likePg($izq, $der, $no, $sinCaja, $escape ?? null)
                    : $izq . ($no ? ' NOT' : '') . ' REGEXP ' . ($sinCaja ? "'(?i)' || " : '') . $this->expr($der);
                array_splice($t, $ini, $fin - $ini + 1, [['k' => 'raw', 'v' => $texto]]);
                $k = $ini;
            }
        }
        return $t;
    }

    /**
     * LIKE de PostgreSQL. Allí distingue mayúsculas y aquí no: con un patrón
     * escrito tal cual se convierte en la expresión regular equivalente, que sí
     * las distingue. ILIKE es el LIKE de aquí.
     */
    private function likePg(string $izq, array $der, bool $no, bool $sinCaja, ?string $escape = null): string
    {
        if ($sinCaja) {
            return "$izq " . ($no ? 'NOT ' : '') . 'LIKE ' . $this->expr($der)
                . ($escape !== null ? ' ESCAPE ' . $this->tr->texto([['k' => 'str', 'v' => $escape]]) : '');
        }
        if (count($der) !== 1 || $der[0]['k'] !== 'str') {
            $this->tr->avisar(t('LIKE con un patrón calculado: aquí no distingue mayúsculas'));
            return "$izq " . ($no ? 'NOT ' : '') . 'LIKE ' . $this->expr($der);
        }
        $re = '';
        $literal = false;
        foreach ((array)preg_split('//u', (string)$der[0]['v'], -1, PREG_SPLIT_NO_EMPTY) as $c) {
            if (!$literal && $escape !== null && $c === $escape) {
                $literal = true;
                continue;
            }
            $re .= !$literal && $c === '%' ? '.*' : (!$literal && $c === '_' ? '.' : preg_quote((string)$c, '/'));
            $literal = false;
        }
        return "$izq " . ($no ? 'NOT ' : '') . 'REGEXP ' . $this->tr->texto([['k' => 'str', 'v' => '(?s)^' . $re . '$']]);
    }

    /** Un intervalo de PostgreSQL ('1 day', '02:00:00', '1 year 2 mons') en modificadores de aquí. */
    private function modificadoresIntervalo(string $i): array
    {
        $mods = [];
        $resto = trim($i);
        if (preg_match('/(-?)(\d+):(\d+):(\d+(?:\.\d+)?)$/', $resto, $m)) {
            $seg = (int)$m[2] * 3600 + (int)$m[3] * 60 + (float)$m[4];
            $mods[] = ($m[1] === '-' ? '-' : '+') . $seg . ' seconds';
            $resto = trim(substr($resto, 0, -strlen($m[0])));
        }
        $unidades = ['year' => 'years', 'years' => 'years', 'mon' => 'months', 'mons' => 'months', 'month' => 'months', 'months' => 'months',
                     'day' => 'days', 'days' => 'days', 'hour' => 'hours', 'hours' => 'hours', 'min' => 'minutes', 'mins' => 'minutes',
                     'minute' => 'minutes', 'minutes' => 'minutes', 'sec' => 'seconds', 'secs' => 'seconds', 'second' => 'seconds', 'seconds' => 'seconds'];
        preg_match_all('/(-?\d+(?:\.\d+)?)\s*([a-z]+)/i', $resto, $mm, PREG_SET_ORDER);
        foreach ($mm as $p) {
            $u = $unidades[strtolower($p[2])] ?? throw new NoTraducible(t("intervalo '{i}'", ['i' => $i]));
            $mods[] = (str_starts_with($p[1], '-') ? '' : '+') . $p[1] . " $u";
        }
        if ($mods === []) {
            throw new NoTraducible(t("intervalo '{i}'", ['i' => $i]));
        }
        return $mods;
    }

    /** Dónde empieza el operando que acaba en $k: un nombre (a.b), una llamada f(…), un (…) o un valor. */
    private function inicioOperando(array $t, int $k): int
    {
        if (($t[$k]['v'] ?? '') === ')' && ($t[$k]['k'] ?? '') === 'op') {
            $nivel = 0;
            for (; $k >= 0; $k--) {
                if ($t[$k]['v'] === ')') { $nivel++; }
                if ($t[$k]['v'] === '(' && --$nivel === 0) { break; }
            }
            return $k > 0 && $t[$k - 1]['k'] === 'id' && empty($t[$k - 1]['q']) && $this->palabra($t[$k - 1]) !== ''
                && !in_array($this->palabra($t[$k - 1]), self::NO_FUNCIONES, true) ? $k - 1 : $k;
        }
        while ($k >= 2 && ($t[$k - 1]['v'] ?? '') === '.' && ($t[$k - 2]['k'] ?? '') === 'id') {
            $k -= 2;
        }
        return $k;
    }

    /** Dónde acaba el operando que empieza en $k. */
    private function finOperando(array $t, int $k): int
    {
        if (($t[$k]['k'] ?? '') === 'op' && $t[$k]['v'] === '-') {
            $k++;                                   // -5
        }
        if (($t[$k]['v'] ?? '') === '(') {
            return $this->cierre($t, $k);
        }
        if (($t[$k]['k'] ?? '') === 'id' && ($t[$k + 1]['v'] ?? '') === '(') {
            return $this->cierre($t, $k + 1);
        }
        while (($t[$k + 1]['v'] ?? '') === '.' && ($t[$k + 2]['k'] ?? '') === 'id') {
            $k += 2;
        }
        return $k;
    }

    /** @param list<array> $args los tokens de dentro del paréntesis */
    private function funcion(string $u, string $original, array $args): string
    {
        $a = in_array($u, ['CAST', 'CONVERT', 'GROUP_CONCAT', 'SUBSTRING', 'POSITION', 'TRIM', 'EXTRACT', 'STRING_AGG'], true)
            ? [] : array_map(fn($x) => $this->expr($x), $this->separar($args));
        if ($this->d === 'postgresql') {
            $r = $this->funcionPg($u, $args, $a);
            if ($r !== null) {
                return $r;
            }
        }
        if ($this->d === 'sqlserver') {
            $r = $this->funcionSqlServer($u, $args, $a);
            if ($r !== null) {
                return $r;
            }
        }
        if ($this->d === 'access') {
            $r = $this->funcionAccess($u, $args, $a);
            if ($r !== null) {
                return $r;
            }
        }
        switch ($u) {
            case 'REGEXP_LIKE':
                // regexp_like(texto, patrón[, opciones]): así guarda MySQL 8 un REGEXP
                $opciones = isset($a[2]) ? trim($a[2], "'") : '';
                return '(' . $a[0] . ' REGEXP ' . (str_contains($opciones, 'i') ? "'(?i)' || " : '') . $a[1] . ')';
            case 'IF':
                $this->exigirArgs($u, $a, 3);
                return "(CASE WHEN {$a[0]} THEN {$a[1]} ELSE {$a[2]} END)";
            case 'NOW': case 'SYSDATE': case 'CURRENT_TIMESTAMP': case 'LOCALTIME': case 'LOCALTIMESTAMP': case 'UTC_TIMESTAMP':
                return "DATETIME('now')";
            case 'CURDATE': case 'CURRENT_DATE': case 'UTC_DATE':
                return "DATE('now')";
            case 'CURTIME': case 'CURRENT_TIME': case 'UTC_TIME':
                return "TIME('now')";
            case 'CHAR_LENGTH': case 'CHARACTER_LENGTH': case 'LENGTH':
                return "LENGTH({$a[0]})";
            case 'UCASE': return "UPPER({$a[0]})";
            case 'LCASE': return "LOWER({$a[0]})";
            case 'LOCATE':
                if (count($a) > 2) {
                    throw new NoTraducible(t('LOCATE con posición de inicio'));
                }
                return "INSTR({$a[1]}, {$a[0]})";
            case 'POSITION':
                $in = $this->buscar($args, 0, ['IN']);
                return 'INSTR(' . $this->expr(array_slice($args, $in + 1)) . ', ' . $this->expr(array_slice($args, 0, $in)) . ')';
            case 'LEFT':  return "SUBSTR({$a[0]}, 1, {$a[1]})";
            case 'RIGHT': return "SUBSTR({$a[0]}, -({$a[1]}))";
            case 'MID':   return 'SUBSTR(' . implode(', ', $a) . ')';
            case 'SUBSTRING':
            case 'SUBSTR':
                // SUBSTRING(s FROM a [FOR b]) o SUBSTRING(s, a, b)
                $desde = $this->buscarOpcional($args, 'FROM');
                if ($desde !== null) {
                    $para = $this->buscarOpcional($args, 'FOR');
                    return 'SUBSTR(' . $this->expr(array_slice($args, 0, $desde)) . ', '
                        . $this->expr(array_slice($args, $desde + 1, ($para ?? count($args)) - $desde - 1))
                        . ($para !== null ? ', ' . $this->expr(array_slice($args, $para + 1)) : '') . ')';
                }
                return 'SUBSTR(' . implode(', ', array_map(fn($x) => $this->expr($x), $this->separar($args))) . ')';
            case 'TRIM':
                // TRIM([BOTH|LEADING|TRAILING] [c] FROM s)
                $desde = $this->buscarOpcional($args, 'FROM');
                if ($desde === null) {
                    return 'TRIM(' . $this->expr($args) . ')';
                }
                $lado = $this->palabra($args[0] ?? []);
                $ini = in_array($lado, ['BOTH', 'LEADING', 'TRAILING'], true) ? 1 : 0;
                $fn = ['LEADING' => 'LTRIM', 'TRAILING' => 'RTRIM'][$lado] ?? 'TRIM';
                $quitar = array_slice($args, $ini, $desde - $ini);
                return "$fn(" . $this->expr(array_slice($args, $desde + 1)) . ($quitar !== [] ? ', ' . $this->expr($quitar) : '') . ')';
            case 'CONCAT':
                return count($a) === 1 ? $a[0] : 'CONCAT(' . implode(', ', $a) . ')';
            case 'CONCAT_WS':
                // Como en MySQL: el separador entre los que no son NULL
                $sep = array_shift($a);
                $partes = array_map(static fn($x) => "COALESCE($sep || $x, '')", $a);
                return 'SUBSTR(' . implode(' || ', $partes) . ", LENGTH($sep) + 1)";
            case 'GREATEST': return 'MAX(' . implode(', ', $a) . ')';
            case 'LEAST':    return 'MIN(' . implode(', ', $a) . ')';
            case 'MOD':      return "({$a[0]} % {$a[1]})";
            case 'FLOOR':    return "(CAST({$a[0]} AS INTEGER) - ({$a[0]} < CAST({$a[0]} AS INTEGER)))";
            case 'CEIL':
            case 'CEILING':  return "(CAST({$a[0]} AS INTEGER) + ({$a[0]} > CAST({$a[0]} AS INTEGER)))";
            case 'TO_DAYS':
                // Días desde el año 0, como MySQL: 1970-01-01 es el 719528
                return "(CAST(STRFTIME('%s', DATE({$a[0]})) / 86400 AS INTEGER) + 719528)";
            case 'TRUNCATE':
                if (!preg_match('/^\d+$/', $a[1] ?? '')) {
                    throw new NoTraducible(t('TRUNCATE con decimales calculados'));
                }
                $p = str_pad('1', (int)$a[1] + 1, '0');
                return $a[1] === '0' ? "CAST({$a[0]} AS INTEGER)" : "(CAST({$a[0]} * $p AS INTEGER) / $p.0)";
            case 'RAND':     return 'RANDOM()';
            case 'YEAR': case 'MONTH': case 'DAY': case 'DAYOFMONTH': case 'HOUR': case 'MINUTE': case 'SECOND':
                $f = ['YEAR' => '%Y', 'MONTH' => '%m', 'DAY' => '%d', 'DAYOFMONTH' => '%d', 'HOUR' => '%H', 'MINUTE' => '%M', 'SECOND' => '%S'][$u];
                return "CAST(STRFTIME('$f', {$a[0]}) AS INTEGER)";
            case 'DATEDIFF':
                return "CAST((STRFTIME('%s', DATE({$a[0]})) - STRFTIME('%s', DATE({$a[1]}))) / 86400 AS INTEGER)";
            case 'DATE_FORMAT':
                return 'STRFTIME(' . $this->formatoMysql($args) . ", {$a[0]})";
            case 'DATE_ADD': case 'ADDDATE': case 'DATE_SUB': case 'SUBDATE':
                return $this->sumarFecha($u, $this->separar($args));
            case 'CAST':
                $as = $this->buscar($args, 0, ['AS']);
                return $this->conversion($this->expr(array_slice($args, 0, $as)), array_slice($args, $as + 1));
            case 'CONVERT':
                $using = $this->buscarOpcional($args, 'USING');
                if ($using !== null) {
                    return $this->expr(array_slice($args, 0, $using));
                }
                $partes = $this->separar($args);
                return $this->conversion($this->expr($partes[0]), $partes[1] ?? []);
            case 'GROUP_CONCAT':
                return $this->agrupar($args);
        }
        // Las que se llaman igual: tal cual (si aquí no existe, el motor lo dirá)
        return strtoupper($original) . '(' . implode(', ', $a) . ')';
    }

    /** Las funciones de PostgreSQL que aquí se escriben de otra forma; null si es una común. */
    private function funcionPg(string $u, array $args, array $a): ?string
    {
        switch ($u) {
            case 'STRPOS': return "INSTR({$a[0]}, {$a[1]})";
            case 'BTRIM':  return 'TRIM(' . implode(', ', $a) . ')';
            case 'CONCAT':
                // En PostgreSQL CONCAT se salta los NULL
                return '(' . implode(' || ', array_map(static fn($x) => "COALESCE(CAST($x AS TEXT), '')", $a)) . ')';
            case 'GREATEST':
            case 'LEAST':
                // También se saltan los NULL: NULL solo si lo son todos
                $f = $u === 'GREATEST' ? 'MAX' : 'MIN';
                $r = array_shift($a);
                foreach ($a as $x) {
                    $r = "$f(COALESCE($r, $x), COALESCE($x, $r))";
                }
                return $r;
            case 'TRUNC':
                return $this->funcion('TRUNCATE', 'TRUNCATE', count($args) === 1 ? array_merge($args, [['k' => 'op', 'v' => ','], ['k' => 'num', 'v' => '0']]) : $args);
            case 'DATE_TRUNC':
                $unidad = strtolower(trim($a[0], "'"));
                if (in_array($unidad, ['day', 'month', 'year'], true)) {
                    return "DATETIME({$a[1]}, 'start of $unidad')";
                }
                if (in_array($unidad, ['hour', 'minute'], true)) {
                    return "DATETIME(STRFTIME('" . ($unidad === 'hour' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d %H:%M:00') . "', {$a[1]}))";
                }
                throw new NoTraducible(t("date_trunc('{u}')", ['u' => $unidad]));
            case 'DATE_PART':
                return $this->parteFecha(strtolower(trim($a[0], "'")), $a[1]);
            case 'EXTRACT':
                $desde = $this->buscar($args, 0, ['FROM']);
                return $this->parteFecha(strtolower((string)$args[0]['v']), $this->expr(array_slice($args, $desde + 1)));
            case 'TO_CHAR':
                $f = $this->separar($args)[1] ?? [];
                if (count($f) !== 1 || $f[0]['k'] !== 'str') {
                    throw new NoTraducible(t('to_char con un formato calculado'));
                }
                $codigos = ['YYYY' => '%Y', 'MM' => '%m', 'DD' => '%d', 'HH24' => '%H', 'MI' => '%M', 'SS' => '%S', 'DDD' => '%j'];
                $salida = (string)preg_replace_callback('/YYYY|HH24|DDD|MM|DD|MI|SS|[A-Za-z]+/', static function ($m) use ($codigos) {
                    return $codigos[$m[0]] ?? throw new NoTraducible(t('Código de to_char sin traducción: {c}', ['c' => $m[0]]));
                }, $f[0]['v']);
                return 'STRFTIME(' . $this->tr->texto([['k' => 'str', 'v' => $salida]]) . ", {$a[0]})";
            case 'STRING_AGG':
                // string_agg([DISTINCT] x, sep [ORDER BY …]): GROUP_CONCAT con el mismo orden
                return $this->agrupar(array_merge($args));
        }
        return null;
    }

    /** Las funciones de SQL Server que aquí se escriben de otra forma; null si es una común. */
    private function funcionSqlServer(string $u, array $args, array $a): ?string
    {
        $unidades = ['YEAR' => 'years', 'YY' => 'years', 'YYYY' => 'years', 'MONTH' => 'months', 'MM' => 'months', 'M' => 'months',
                     'DAY' => 'days', 'DD' => 'days', 'D' => 'days', 'WEEK' => 'weeks', 'WK' => 'weeks', 'WW' => 'weeks',
                     'HOUR' => 'hours', 'HH' => 'hours', 'MINUTE' => 'minutes', 'MI' => 'minutes', 'N' => 'minutes',
                     'SECOND' => 'seconds', 'SS' => 'seconds', 'S' => 'seconds'];
        switch ($u) {
            case 'ISNULL':   return "IFNULL({$a[0]}, {$a[1]})";
            case 'DATALENGTH':
                // Bytes: dos por carácter en los tipos N (NVARCHAR, N'…'), uno en los demás
                $ancho = preg_match('/\bN(VAR)?CHAR\b/i', implode(' ', array_map(static fn($x) => (string)$x['v'], $args)))
                    || (($args[0]['k'] ?? '') === 'str' && !empty($args[0]['n'])) ? 2 : 1;
                return "(LENGTH(CAST({$a[0]} AS TEXT)) * $ancho)";
            case 'DATEFROMPARTS':
                return "DATE(SUBSTR('000' || ({$a[0]}), -4) || '-' || SUBSTR('0' || ({$a[1]}), -2) || '-' || SUBSTR('0' || ({$a[2]}), -2))";
            case 'ROUND':
                // ROUND(x, n, 1) trunca en vez de redondear
                if (count($a) === 3 && $a[2] !== '0') {
                    $partes = $this->separar($args);
                    return $this->funcion('TRUNCATE', 'TRUNCATE', array_merge($partes[0], [['k' => 'op', 'v' => ',']], $partes[1]));
                }
                return null;
            case 'STRING_AGG':
                return $this->agrupar($args);
            case 'LEN':      return "LENGTH(RTRIM({$a[0]}))";        // LEN no cuenta los espacios del final
            case 'CHARINDEX':
                if (count($a) > 2) {
                    throw new NoTraducible(t('LOCATE con posición de inicio'));
                }
                return "INSTR({$a[1]}, {$a[0]})";
            case 'GETDATE': case 'SYSDATETIME': case 'GETUTCDATE': case 'SYSUTCDATETIME': case 'CURRENT_TIMESTAMP':
                return "DATETIME('now')";
            case 'IIF':
                return "(CASE WHEN {$a[0]} THEN {$a[1]} ELSE {$a[2]} END)";
            case 'CONCAT':
                // En SQL Server CONCAT se salta los NULL
                return '(' . implode(' || ', array_map(static fn($x) => "COALESCE(CAST($x AS TEXT), '')", $a)) . ')';
            case 'DATEADD':
                $unidad = $unidades[$this->palabra($args[0] ?? [])] ?? throw new NoTraducible(t("INTERVAL en '{u}'", ['u' => (string)($args[0]['v'] ?? '')]));
                $n = $unidad === 'weeks' ? "(({$a[1]}) * 7)" : $a[1];
                $unidad = $unidad === 'weeks' ? 'days' : $unidad;
                $partes = $this->separar($args);
                $cant = $partes[1];
                $signo = '+';
                if (count($cant) === 2 && $cant[0]['v'] === '-' && $cant[1]['k'] === 'num') {
                    $signo = '-';
                    $cant = [$cant[1]];
                }
                return count($cant) === 1 && $cant[0]['k'] === 'num' && $unidad !== 'weeks'
                    ? "DATETIME({$a[2]}, '$signo" . $cant[0]['v'] . " $unidad')"
                    : "DATETIME({$a[2]}, CASE WHEN $n < 0 THEN '' ELSE '+' END || ($n) || ' $unidad')";
            case 'DATEDIFF':
                // Cuántos límites de la unidad hay entre las dos, como SQL Server
                $unidad = $unidades[$this->palabra($args[0] ?? [])] ?? throw new NoTraducible(t("INTERVAL en '{u}'", ['u' => (string)($args[0]['v'] ?? '')]));
                [$x, $y] = [$a[1], $a[2]];
                switch ($unidad) {
                    case 'years':  return "(CAST(STRFTIME('%Y', $y) AS INTEGER) - CAST(STRFTIME('%Y', $x) AS INTEGER))";
                    case 'months': return "((CAST(STRFTIME('%Y', $y) AS INTEGER) - CAST(STRFTIME('%Y', $x) AS INTEGER)) * 12 + CAST(STRFTIME('%m', $y) AS INTEGER) - CAST(STRFTIME('%m', $x) AS INTEGER))";
                    case 'days':   return "CAST((STRFTIME('%s', DATE($y)) - STRFTIME('%s', DATE($x))) / 86400 AS INTEGER)";
                    case 'hours':  return "CAST((STRFTIME('%s', STRFTIME('%Y-%m-%d %H:00:00', $y)) - STRFTIME('%s', STRFTIME('%Y-%m-%d %H:00:00', $x))) / 3600 AS INTEGER)";
                    case 'minutes':return "CAST((STRFTIME('%s', STRFTIME('%Y-%m-%d %H:%M:00', $y)) - STRFTIME('%s', STRFTIME('%Y-%m-%d %H:%M:00', $x))) / 60 AS INTEGER)";
                    case 'seconds':return "CAST(STRFTIME('%s', $y) - STRFTIME('%s', $x) AS INTEGER)";
                }
                throw new NoTraducible(t("INTERVAL en '{u}'", ['u' => $unidad]));
            case 'DATEPART':
                $p = $this->palabra($args[0] ?? []);
                $mapa = ['YEAR' => 'year', 'YY' => 'year', 'YYYY' => 'year', 'MONTH' => 'month', 'MM' => 'month', 'M' => 'month',
                         'DAY' => 'day', 'DD' => 'day', 'D' => 'day', 'HOUR' => 'hour', 'HH' => 'hour', 'MINUTE' => 'minute',
                         'MI' => 'minute', 'SECOND' => 'second', 'SS' => 'second', 'DAYOFYEAR' => 'doy', 'DY' => 'doy'];
                return $this->parteFecha($mapa[$p] ?? '?', $a[1]);
            case 'FORMAT':
                $f = $this->separar($args)[1] ?? [];
                if (count($f) !== 1 || $f[0]['k'] !== 'str') {
                    throw new NoTraducible(t('DATE_FORMAT con un formato calculado'));
                }
                $codigos = ['yyyy' => '%Y', 'MM' => '%m', 'dd' => '%d', 'HH' => '%H', 'mm' => '%M', 'ss' => '%S'];
                $salida = (string)preg_replace_callback('/yyyy|MM|dd|HH|mm|ss|[A-Za-z]+/', static function ($m) use ($codigos) {
                    return $codigos[$m[0]] ?? throw new NoTraducible(t('Código de DATE_FORMAT sin traducción: {c}', ['c' => $m[0]]));
                }, $f[0]['v']);
                return 'STRFTIME(' . $this->tr->texto([['k' => 'str', 'v' => $salida]]) . ", {$a[0]})";
            case 'CONVERT':
                // CONVERT(tipo, x [, estilo]): el tipo va primero
                $partes = $this->separar($args);
                $x = $this->expr($partes[1] ?? []);
                $tipo = $partes[0];
                $estilo = (int)($partes[2][0]['v'] ?? -1);
                $base = $this->palabra($tipo[0] ?? []);
                if (in_array($base, ['VARCHAR', 'NVARCHAR', 'CHAR', 'NCHAR'], true) && in_array($estilo, [120, 121, 20, 21, 23, 126], true)) {
                    $largo = ($tipo[1]['v'] ?? '') === '(' ? (int)$tipo[2]['v'] : 30;
                    return "SUBSTR(STRFTIME('%Y-%m-%d %H:%M:%S', $x), 1, $largo)";
                }
                return $this->conversion($x, $tipo);
        }
        return null;
    }

    /** Las funciones de Access (VBA en las consultas) que aquí se escriben de otra forma. */
    private function funcionAccess(string $u, array $args, array $a): ?string
    {
        $unidad = static function (string $lit): string {
            $i = strtolower(trim($lit, "'\""));
            return ['yyyy' => 'years', 'q' => 'quarters', 'm' => 'months', 'y' => 'days', 'd' => 'days', 'w' => 'days',
                    'ww' => 'weeks', 'h' => 'hours', 'n' => 'minutes', 's' => 'seconds'][$i]
                ?? throw new NoTraducible(t("INTERVAL en '{u}'", ['u' => $i]));
        };
        switch ($u) {
            case 'NZ':      return 'COALESCE(' . $a[0] . ', ' . ($a[1] ?? "''") . ')';
            case 'CHR':
                if (!preg_match('/^\d+$/', trim($a[0]))) {
                    throw new NoTraducible(t('Chr() con un código calculado'));
                }
                return $this->tr->texto([['k' => 'str', 'v' => mb_chr((int)$a[0], 'UTF-8')]]);
            case 'IIF':     return "(CASE WHEN {$a[0]} THEN {$a[1]} ELSE {$a[2]} END)";
            case 'ISNULL':  return "({$a[0]} IS NULL)";
            case 'MID':     return 'SUBSTR(' . implode(', ', $a) . ')';
            case 'LEN':     return "LENGTH({$a[0]})";
            case 'INSTR':
                // InStr([inicio,] texto, buscado[, comparación]). En una consulta,
                // Access compara textos sin distinguir mayúsculas
                if (count($a) >= 3 && preg_match('/^\d+$/', $a[0])) {
                    if ($a[0] !== '1') {
                        throw new NoTraducible(t('LOCATE con posición de inicio'));
                    }
                    // El cuarto, 0 (vbBinaryCompare), sí distingue mayúsculas
                    return ($a[3] ?? '') === '0' ? "INSTR({$a[1]}, {$a[2]})" : "INSTR(LOWER({$a[1]}), LOWER({$a[2]}))";
                }
                return "INSTR(LOWER({$a[0]}), LOWER({$a[1]}))";
            case 'UCASE': return "UPPER({$a[0]})";
            case 'LCASE': return "LOWER({$a[0]})";
            case 'DATE':  return count($a) === 0 ? "DATE('now')" : null;
            case 'NOW':   return "DATETIME('now')";
            case 'TIME':  return count($a) === 0 ? "TIME('now')" : null;
            case 'DATEVALUE': return "DATE({$a[0]})";
            case 'TIMEVALUE': return "TIME({$a[0]})";
            case 'CDATE': case 'CVDATE': return "DATETIME({$a[0]})";
            case 'CSTR':  return "CAST({$a[0]} AS TEXT)";
            case 'CINT': case 'CLNG': return "CAST(ROUND({$a[0]}) AS INTEGER)";
            case 'CDBL': case 'CSNG': case 'VAL': return "CAST({$a[0]} AS DOUBLE)";
            case 'CCUR':  return "ROUND({$a[0]}, 4)";
            case 'CDEC':  return $a[0];
            case 'CBOOL': return "(CASE WHEN {$a[0]} THEN 1 ELSE 0 END)";
            case 'INT':   return "(CAST({$a[0]} AS INTEGER) - ({$a[0]} < CAST({$a[0]} AS INTEGER)))";
            case 'FIX':   return "CAST({$a[0]} AS INTEGER)";
            case 'SGN':   return "(CASE WHEN {$a[0]} > 0 THEN 1 WHEN {$a[0]} < 0 THEN -1 ELSE 0 END)";
            case 'DATEADD':
                $u2 = $unidad($a[0]);
                $a[1] = (string)preg_replace(['/^\+\s*/', '/^-\s+/'], ['', '-'], trim($a[1]));
                [$n, $u2] = $u2 === 'weeks' ? ["(({$a[1]}) * 7)", 'days'] : ($u2 === 'quarters' ? ["(({$a[1]}) * 3)", 'months'] : [$a[1], $u2]);
                return preg_match('/^-?\d+(\.\d+)?$/', $n)
                    ? "DATETIME({$a[2]}, '" . (str_starts_with($n, '-') ? '' : '+') . "$n $u2')"
                    : "DATETIME({$a[2]}, CASE WHEN $n < 0 THEN '' ELSE '+' END || ($n) || ' $u2')";
            case 'DATEDIFF':
                $partes = $this->separar($args);
                $mapa = ['years' => 'year', 'months' => 'month', 'days' => 'day', 'hours' => 'hour', 'minutes' => 'minute', 'seconds' => 'second'];
                $u2 = $unidad($a[0]);
                return $this->funcionSqlServer('DATEDIFF', array_merge([['k' => 'id', 'v' => $mapa[$u2] ?? 'day'], ['k' => 'op', 'v' => ',']],
                    $partes[1], [['k' => 'op', 'v' => ',']], $partes[2]), ['', $a[1], $a[2]]);
            case 'DATEPART':
                $mapa = ['years' => 'year', 'months' => 'month', 'days' => 'day', 'hours' => 'hour', 'minutes' => 'minute', 'seconds' => 'second'];
                return $this->parteFecha($mapa[$unidad($a[0])] ?? '?', $a[1]);
            case 'DATESERIAL':
                return "DATE(SUBSTR('000' || ({$a[0]}), -4) || '-' || SUBSTR('0' || ({$a[1]}), -2) || '-' || SUBSTR('0' || ({$a[2]}), -2))";
            case 'FORMAT':
                $f = $this->separar($args)[1] ?? [];
                if (count($f) !== 1 || $f[0]['k'] !== 'str') {
                    throw new NoTraducible(t('DATE_FORMAT con un formato calculado'));
                }
                // mm son minutos detrás de h; si no, meses. Lo que va entre comillas
                // o detrás de \ es texto tal cual
                $salida = (string)preg_replace_callback('/"[^"]*"|\\\\.|yyyy|hh|nn|ss|mm|dd|[a-z]+/i', static function ($m) {
                    static $hora = false;
                    if ($m[0][0] === '"' || $m[0][0] === '\\') {
                        return str_replace('%', '%%', $m[0][0] === '"' ? substr($m[0], 1, -1) : substr($m[0], 1));
                    }
                    $c = strtolower($m[0]);
                    $r = ['yyyy' => '%Y', 'dd' => '%d', 'hh' => '%H', 'nn' => '%M', 'ss' => '%S', 'mm' => $hora ? '%M' : '%m'][$c]
                        ?? throw new NoTraducible(t('Código de DATE_FORMAT sin traducción: {c}', ['c' => $m[0]]));
                    $hora = $c === 'hh';
                    return $r;
                }, $f[0]['v']);
                return 'STRFTIME(' . $this->tr->texto([['k' => 'str', 'v' => $salida]]) . ", {$a[0]})";
            case 'FIRST': case 'LAST': case 'STDEV': case 'STDEVP': case 'VAR': case 'VARP':
                throw new NoTraducible(t("Función sin traducción: {f}()", ['f' => $u]));
        }
        return null;
    }

    /** EXTRACT / date_part de una unidad, como entero. */
    private function parteFecha(string $unidad, string $x): string
    {
        $f = ['year' => '%Y', 'month' => '%m', 'day' => '%d', 'hour' => '%H', 'minute' => '%M', 'second' => '%S', 'doy' => '%j', 'dow' => '%w'][$unidad]
            ?? throw new NoTraducible(t("EXTRACT de '{u}'", ['u' => $unidad]));
        return "CAST(STRFTIME('$f', $x) AS INTEGER)";
    }

    /** CAST(x AS tipo) con el tipo de MySQL pasado a uno de aquí. */
    private function conversion(string $x, array $tipo): string
    {
        $base = $this->palabra($tipo[0] ?? []);
        if ($base === 'DATE') {
            return "DATE($x)";                      // aquí CAST AS DATE conserva la hora
        }
        if ($base === 'TIME') {
            return "TIME($x)";
        }
        $destino = [
            'SIGNED' => 'INTEGER', 'UNSIGNED' => 'INTEGER', 'INT' => 'INTEGER', 'INTEGER' => 'INTEGER', 'BIGINT' => 'INTEGER',
            'CHAR' => 'TEXT', 'VARCHAR' => 'TEXT', 'NCHAR' => 'TEXT', 'TEXT' => 'TEXT', 'BINARY' => 'TEXT', 'JSON' => 'TEXT',
            'DOUBLE' => 'DOUBLE', 'FLOAT' => 'DOUBLE', 'REAL' => 'DOUBLE',
            'DATETIME' => 'DATETIME', 'TIMESTAMP' => 'DATETIME', 'DATETIME2' => 'DATETIME', 'SMALLDATETIME' => 'DATETIME',
            'NVARCHAR' => 'TEXT', 'NTEXT' => 'TEXT', 'BIT' => 'INTEGER', 'TINYINT' => 'INTEGER', 'SMALLINT' => 'INTEGER',
            'MONEY' => 'DOUBLE',
        ][$base] ?? null;
        if ($base === 'DECIMAL' || $base === 'NUMERIC') {
            $escala = ($tipo[4]['k'] ?? '') === 'num' ? (int)$tipo[4]['v'] : 0;
            return "CAST($x AS DECIMAL(38,$escala))";
        }
        if ($destino === null) {
            throw new NoTraducible(t("CAST a '{tipo}' sin equivalente", ['tipo' => $base]));
        }
        // MySQL redondea al convertir a entero (CAST(1.5 AS SIGNED) = 2); aquí
        // CAST trunca, como en SQLite y SQL Server
        if ($destino === 'INTEGER' && $this->d === 'mysql') {
            return "CAST(ROUND($x) AS INTEGER)";
        }
        return "CAST($x AS $destino)";
    }

    /** DATE_ADD(d, INTERVAL n UNIDAD) y compañía: los modificadores de aquí. */
    private function sumarFecha(string $u, array $args): string
    {
        $d = $this->expr($args[0]);
        $signo = in_array($u, ['DATE_SUB', 'SUBDATE'], true) ? '-' : '+';
        $int = $args[1] ?? [];
        if ($this->palabra($int[0] ?? []) !== 'INTERVAL') {
            // ADDDATE(d, n): días
            $int = array_merge([['k' => 'id', 'v' => 'INTERVAL']], $int, [['k' => 'id', 'v' => 'DAY']]);
        }
        $unidad = $this->palabra(end($int));
        $cant = array_slice($int, 1, -1);
        [$mult, $nombre] = ['SECOND' => [1, 'seconds'], 'MINUTE' => [1, 'minutes'], 'HOUR' => [1, 'hours'], 'DAY' => [1, 'days'],
                            'WEEK' => [7, 'days'], 'MONTH' => [1, 'months'], 'QUARTER' => [3, 'months'], 'YEAR' => [1, 'years']][$unidad]
            ?? throw new NoTraducible(t("INTERVAL en '{u}'", ['u' => $unidad]));
        if (count($cant) === 2 && $cant[0]['v'] === '-' && $cant[1]['k'] === 'num') {
            $signo = $signo === '+' ? '-' : '+';      // INTERVAL -2 HOUR
            $cant = [$cant[1]];
        }
        if (count($cant) === 1 && $cant[0]['k'] === 'num') {
            return "DATETIME($d, '$signo" . ((float)$cant[0]['v'] * $mult) . " $nombre')";
        }
        // Calculada: el signo de la suma y el de la cantidad, juntos
        $n = '(' . ($signo === '-' ? '-' : '') . '(' . $this->expr($cant) . ") * $mult)";
        return "DATETIME($d, CASE WHEN $n < 0 THEN '' ELSE '+' END || $n || ' $nombre')";
    }

    /** El formato de DATE_FORMAT en el de STRFTIME. */
    private function formatoMysql(array $args): string
    {
        $partes = $this->separar($args);
        $f = $partes[1] ?? [];
        if (count($f) !== 1 || $f[0]['k'] !== 'str') {
            throw new NoTraducible(t('DATE_FORMAT con un formato calculado'));
        }
        $codigos = ['%Y' => '%Y', '%m' => '%m', '%d' => '%d', '%H' => '%H', '%i' => '%M', '%s' => '%S', '%S' => '%S',
                    '%j' => '%j', '%w' => '%w', '%T' => '%H:%M:%S', '%%' => '%%'];
        $salida = (string)preg_replace_callback('/%./', static function ($m) use ($codigos) {
            if (!isset($codigos[$m[0]])) {
                throw new NoTraducible(t('Código de DATE_FORMAT sin traducción: {c}', ['c' => $m[0]]));
            }
            return $codigos[$m[0]];
        }, $f[0]['v']);
        return $this->tr->texto([['k' => 'str', 'v' => $salida]]);
    }

    /** GROUP_CONCAT([DISTINCT] x [ORDER BY …] [SEPARATOR 's']) */
    private function agrupar(array $args): string
    {
        $distinto = $this->palabra($args[0] ?? []) === 'DISTINCT';
        if ($distinto) {
            $args = array_slice($args, 1);
        }
        $sep = null;
        $s = $this->buscarOpcional($args, 'SEPARATOR');
        if ($s !== null) {
            $sep = $this->expr(array_slice($args, $s + 1));
            $args = array_slice($args, 0, $s);
        }
        $orden = $this->buscarOpcional($args, 'ORDER');
        $partes = $this->separar($orden !== null ? array_slice($args, 0, $orden) : $args);
        if ($sep === null && count($partes) === 2) {
            // string_agg(x, sep …) de PostgreSQL y GROUP_CONCAT(x, sep) de SQLite
            $sep = $this->expr($partes[1]);
            $args = array_merge($partes[0], $orden !== null ? array_slice($args, $orden) : []);
            $orden = $this->buscarOpcional($args, 'ORDER');
        }
        $porOrden = '';
        if ($orden !== null) {
            $porOrden = ' ' . $this->expr(array_slice($args, $orden));
            $args = array_slice($args, 0, $orden);
        }
        return 'GROUP_CONCAT(' . ($distinto ? 'DISTINCT ' : '') . $this->expr($args) . ($sep !== null ? ", $sep" : '') . $porOrden . ')';
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** La palabra (sin comillas) de un token, en mayúsculas; '' si no lo es. */
    private function palabra(array $x): string
    {
        return ($x['k'] ?? '') === 'id' && empty($x['q']) ? strtoupper((string)$x['v']) : '';
    }

    /** El índice siguiente a la palabra $p (fuera de paréntesis). */
    private function tras(array $t, string $p): int
    {
        return $this->buscar($t, 0, [$p]) + 1;
    }

    private function buscarOpcional(array $t, string $p): ?int
    {
        try {
            return $this->buscar($t, 0, [$p]);
        } catch (NoTraducible $e) {
            return null;
        }
    }

    /** El paréntesis que cierra el de $k. */
    private function cierre(array $t, int $k): int
    {
        $nivel = 0;
        for ($n = count($t); $k < $n; $k++) {
            if ($t[$k]['v'] === '(') { $nivel++; }
            if ($t[$k]['v'] === ')' && --$nivel === 0) {
                return $k;
            }
        }
        throw new NoTraducible(t('paréntesis sin cerrar'));
    }

    /** @return list<list<array>> los tokens separados por las comas de su nivel */
    private function separar(array $t): array
    {
        $partes = [];
        $actual = [];
        $nivel = 0;
        foreach ($t as $x) {
            if ($x['v'] === '(') { $nivel++; }
            if ($x['v'] === ')') { $nivel--; }
            if ($x['v'] === ',' && $nivel === 0) {
                $partes[] = $actual;
                $actual = [];
                continue;
            }
            $actual[] = $x;
        }
        if ($actual !== []) {
            $partes[] = $actual;
        }
        return $partes;
    }

    private function exigirArgs(string $f, array $a, int $n): void
    {
        if (count($a) !== $n) {
            throw new NoTraducible(t('{f}() con {n} argumentos', ['f' => $f, 'n' => count($a)]));
        }
    }
}
