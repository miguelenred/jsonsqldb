<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Las sentencias que no son consultas ni escrituras de filas: CREATE, DROP,
 * ALTER, SHOW, CHECK, REPAIR, EXPLAIN y las de MySQL y SQLite que se aceptan.
 * Hereda el análisis de consultas y expresiones de Parser, y el de INSERT,
 * UPDATE y DELETE de ParserEscritura (lo usan los cuerpos de los triggers).
 * Parser::analizar() la elige al ver la primera palabra. Va aparte para que
 * una consulta o una escritura no tengan que compilarla (2.9).
 *
 * https://miguelenred.es/jsonsqldb
 */
/**
 * @phpstan-import-type Token from Lexer
 */
final class ParserSentencias extends ParserEscritura
{
    /** Cualquier sentencia: las consultas y las escrituras de filas, con las clases de arriba; las demás, aquí */
    protected function sentencia(): array
    {
        foreach (['SELECT', 'WITH', 'INSERT', 'REPLACE', 'UPDATE', 'DELETE'] as $palabra) {
            if ($this->es('id', $palabra)) {
                return parent::sentencia();
            }
        }
        if ($this->es('id', 'CREATE')) { return $this->create(); }
        if ($this->es('id', 'DROP'))   { return $this->drop(); }
        if ($this->es('id', 'ALTER'))  { return $this->alter(); }
        if ($this->es('id', 'SHOW'))   { return $this->show(); }
        if ($this->es('id', 'CHECK'))  { return $this->check(false); }
        // EXPLAIN [ANALYZE] [QUERY PLAN] SELECT … (2.9): la ejecuta y dice cómo
        // leyó cada tabla; aquí el índice se decide al leer, según los datos
        if ($this->comer('id', 'EXPLAIN')) {
            $this->comer('id', 'ANALYZE');
            if ($this->comer('id', 'QUERY')) {
                $this->exigir('id', 'PLAN');
            }
            $consulta = $this->sentencia();
            if ($consulta['k'] !== 'select' && $consulta['k'] !== 'union') {
                throw JsonSqlDbError::syntax('EXPLAIN solo admite SELECT');
            }
            return ['k' => 'explain', 'consulta' => $consulta];
        }
        if ($this->es('id', 'REPAIR')) { return $this->check(true); }
        // De MySQL (2.9)
        if ($this->comer('id', 'TRUNCATE')) {
            $this->comer('id', 'TABLE');
            return ['k' => 'delete', 'tabla' => $this->nombreTabla(), 'where' => null, 'returning' => null, 'truncar' => true];
        }
        if (($this->es('id', 'DESCRIBE') || $this->es('id', 'DESC')) && ($this->t[$this->i + 1]['t'] ?? '') === 'id') {
            $this->avanzar();
            return ['k' => 'show_schema', 'tabla' => $this->nombreTabla()];
        }
        // VACUUM compacta las tablas con huecos de filas borradas; ANALYZE no
        // tiene efecto: el planificador no guarda estadísticas (2.9)
        if ($this->comer('id', 'VACUUM')) {
            if ($this->es('id') && !$this->es('id', 'INTO')) {
                $this->nombreSimple('base');               // VACUUM main
            }
            if ($this->es('id', 'INTO')) {
                throw JsonSqlDbError::syntax('VACUUM INTO no está soportado: para una copia, exporta la base desde el panel o copia su carpeta');
            }
            return ['k' => 'vacuum'];
        }
        if ($this->comer('id', 'ANALYZE')) {
            $this->comer('id', 'TABLE');
            if ($this->es('id')) {
                do { $this->nombreTabla(); } while ($this->comer('punc', ','));
            }
            return ['k' => 'nada', 'mensaje' => 'ANALYZE no hace falta: el planificador no usa estadísticas guardadas'];
        }
        if ($this->comer('id', 'USE')) {
            return ['k' => 'use', 'base' => $this->nombreSimple('base')];
        }
        foreach (['BEGIN', 'START', 'COMMIT', 'END', 'ROLLBACK', 'SAVEPOINT', 'RELEASE'] as $control) {
            if ($this->es('id', $control)) {
                throw JsonSqlDbError::syntax("$control solo vale al principio o al final de un lote (que ya es una transacción): "
                    . 'aquí cada petición es independiente. Manda las sentencias juntas en un lote (en la API, el campo lote; '
                    . 'en la consola del panel, separadas por ;)');
            }
        }

        $tk = $this->actual();
        throw JsonSqlDbError::syntax("Sentencia no soportada: '" . ($tk['v'] === '' ? 'vacía' : $tk['v']) . "'");
    }

    // ==================================================================
    // CREATE / DROP / ALTER
    // ==================================================================

    private function create(): array
    {
        $this->exigir('id', 'CREATE');
        if ($this->comer('id', 'TRIGGER')) {
            return $this->createTrigger();
        }
        if ($this->comer('id', 'VIEW')) {
            $siNoExiste = false;
            if ($this->comer('id', 'IF')) {
                $this->exigir('id', 'NOT');
                $this->exigir('id', 'EXISTS');
                $siNoExiste = true;
            }
            $nombre = $this->nombreTabla();
            $this->exigir('id', 'AS');

            // Se guarda el texto del SELECT tal cual lo escribió el usuario:
            // así la vista se puede leer y editar, y se vuelve a analizar al
            // usarla en vez de arrastrar un árbol de una versión anterior.
            $desde = $this->actual()['p'];
            $ast   = ParserConsulta::select($this);
            $hasta = $this->actual()['p'];
            $sql   = rtrim(trim(substr($this->sql, $desde, $hasta - $desde)), ';');

            return ['k' => 'create_view', 'nombre' => $nombre, 'sql' => $sql,
                    'select' => $ast, 'si_no_existe' => $siNoExiste];
        }
        if ($this->comer('id', 'DATABASE')) {
            $siNoExiste = false;
            if ($this->comer('id', 'IF')) {
                $this->exigir('id', 'NOT');
                $this->exigir('id', 'EXISTS');
                $siNoExiste = true;
            }
            return ['k' => 'create_database', 'base' => $this->nombreSimple('base de datos'),
                    'si_no_existe' => $siNoExiste];
        }
        if ($this->comer('id', 'INDEX')) {
            return $this->createIndex();
        }
        // Un índice aquí solo acelera la búsqueda; la unicidad la imponen
        // PRIMARY KEY y UNIQUE, y esos ya crean su índice ellos solos
        if ($this->comer('id', 'UNIQUE')) {
            // CREATE UNIQUE INDEX (2.9): una restricción UNIQUE con ese nombre, que
            // sí impide repetir valores y trae su propio índice
            $this->exigir('id', 'INDEX');
            $siNoExiste = false;
            if ($this->comer('id', 'IF')) {
                $this->exigir('id', 'NOT');
                $this->exigir('id', 'EXISTS');
                $siNoExiste = true;
            }
            $nombre = $this->nombreSimple('índice');
            $this->exigir('id', 'ON');
            $tabla = $this->nombreTabla();
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'add_constraint', 'si_no_existe' => $siNoExiste,
                    'unique' => [['name' => $nombre, 'columns' => $this->listaColumnasIndice()]], 'foreign_keys' => []];
        }
        // Aceptarlos e ignorarlos era lo más peligroso de todo: creabas una tabla
        // permanente creyendo que era temporal.
        if ($this->es('id', 'TEMP') || $this->es('id', 'TEMPORARY')) {
            throw JsonSqlDbError::syntax(
                'CREATE TEMP/TEMPORARY TABLE no está soportado: no hay tablas temporales. '
                . 'Crea una tabla normal y bórrala con DROP TABLE cuando termines.'
            );
        }
        $this->exigir('id', 'TABLE');
        return $this->createTable();
    }

    /** CREATE INDEX [IF NOT EXISTS] n ON t (col [, col...]) */
    private function createIndex(): array
    {
        $siNoExiste = false;
        if ($this->comer('id', 'IF')) {
            $this->exigir('id', 'NOT');
            $this->exigir('id', 'EXISTS');
            $siNoExiste = true;
        }
        $nombre = $this->nombreSimple('índice');
        $this->exigir('id', 'ON');
        $tabla = $this->nombreTabla();

        return ['k' => 'create_index', 'tabla' => $tabla, 'nombre' => $nombre,
                'columnas' => $this->listaColumnasIndice(), 'si_no_existe' => $siNoExiste];
    }

    /** CREATE TABLE [IF NOT EXISTS] t (definiciones) | CREATE TABLE [IF NOT EXISTS] t AS SELECT ... */
    private function createTable(): array
    {
        $siNoExiste = false;
        if ($this->comer('id', 'IF')) {
            $this->exigir('id', 'NOT');
            $this->exigir('id', 'EXISTS');
            $siNoExiste = true;
        }
        $tabla = $this->nombreTabla();
        if ($this->comer('id', 'LIKE')) {
            // CREATE TABLE t2 LIKE t de MySQL (2.9): la misma estructura, vacía
            return ['k' => 'create_table', 'tabla' => $tabla, 'si_no_existe' => $siNoExiste, 'def' => null,
                    'como' => $this->nombreTabla()];
        }
        if ($this->comer('id', 'AS')) {
            if (!$this->es('id', 'SELECT') && !$this->es('id', 'WITH')) {
                throw JsonSqlDbError::syntax('CREATE TABLE … AS espera un SELECT');
            }
            return ['k' => 'create_table', 'tabla' => $tabla, 'si_no_existe' => $siNoExiste, 'def' => null,
                    'select' => ParserConsulta::select($this)];
        }
        $this->exigir('punc', '(');

        $def = ['columns' => [], 'unique' => [], 'foreign_keys' => []];
        do {
            if ($this->es('punc', ')')) {
                break;                                    // coma final tolerada
            }
            if ($this->esRestriccionDeTabla()) {
                $this->restriccionDeTabla($def);
                continue;
            }
            // Un CHECK de columna vale para la fila entera, como en SQLite: va
            // con los de la tabla (2.9)
            $col = $this->definicionColumna();
            foreach ($col['checks'] ?? [] as $sql) {
                $def['checks'][] = ['name' => null, 'sql' => $sql];
            }
            unset($col['checks']);
            $def['columns'][] = $col;
        } while ($this->comer('punc', ','));

        $this->exigir('punc', ')');
        if ($this->es('id', 'WITHOUT')) {
            throw JsonSqlDbError::syntax(
                'WITHOUT ROWID no está soportado. Aquí no hay rowid: las filas se guardan '
                . 'como objetos JSON y la clave primaria es la que declares.'
            );
        }
        $this->opcionesDeTabla($def);

        return ['k' => 'create_table', 'tabla' => $tabla, 'si_no_existe' => $siNoExiste, 'def' => $def];
    }

    /**
     * Las opciones que MySQL pone detrás del CREATE TABLE (2.9). ENGINE, el
     * juego de caracteres, la colación, ROW_FORMAT y COMMENT no cambian nada
     * aquí: todo es texto UTF-8 en JSON, con la colación de la configuración.
     * AUTO_INCREMENT = n sí se aplica: es el primer valor del contador.
     */
    private function opcionesDeTabla(array &$def): void
    {
        while (!$this->es('eof') && !$this->es('punc', ';')) {
            $this->comer('punc', ',');
            $this->comer('id', 'DEFAULT');
            $tk = $this->actual();
            if ($tk['t'] !== 'id') {
                break;
            }
            $opcion = $tk['u'];
            if ($opcion === 'CHARACTER' && $this->esSiguiente('id', 'SET')) {
                $this->avanzar();
                $opcion = 'CHARSET';
            }
            if (!in_array($opcion, ['ENGINE', 'CHARSET', 'COLLATE', 'AUTO_INCREMENT', 'COMMENT', 'ROW_FORMAT'], true)) {
                throw JsonSqlDbError::syntax("Opción de tabla no soportada: {$tk['v']}");
            }
            $this->avanzar();
            $this->comer('op', '=');
            $v = $this->actual();
            if ($opcion === 'AUTO_INCREMENT') {
                $def['autoinc_inicio'] = $this->enteroPositivo('AUTO_INCREMENT');
                continue;
            }
            if ($opcion === 'COMMENT' ? $v['t'] !== 'str' : !in_array($v['t'], ['id', 'str'], true)) {
                throw JsonSqlDbError::syntax("Falta el valor de $opcion");
            }
            $this->avanzar();
        }
    }

    private function esRestriccionDeTabla(): bool
    {
        if ($this->es('id', 'CONSTRAINT') || $this->es('id', 'FOREIGN') || $this->es('id', 'CHECK')) {
            return true;
        }
        // KEY / INDEX [nombre] (cols), UNIQUE KEY / UNIQUE INDEX, FULLTEXT… de
        // MySQL; una columna llamada key lleva un tipo detrás, no un nombre y (
        if (($this->es('id', 'KEY') || $this->es('id', 'INDEX') || $this->es('id', 'FULLTEXT') || $this->es('id', 'SPATIAL'))
            && !$this->actual()['q'] && ($this->esSiguiente('punc', '(') || !Types::esTipo((string)($this->t[$this->i + 1]['v'] ?? '')))) {
            return true;
        }
        if ($this->es('id', 'UNIQUE') && ($this->esSiguiente('id', 'KEY') || $this->esSiguiente('id', 'INDEX'))) {
            return true;
        }
        // PRIMARY KEY (...) y UNIQUE (...) a nivel de tabla llevan paréntesis detrás
        if ($this->es('id', 'PRIMARY')) {
            return true;
        }
        return $this->es('id', 'UNIQUE') && $this->esSiguiente('punc', '(');
    }

    private function restriccionDeTabla(array &$def): void
    {
        $nombre = null;
        if ($this->comer('id', 'CONSTRAINT')) {
            $nombre = $this->nombreSimple('restricción');
        }

        if ($this->comer('id', 'PRIMARY')) {
            $this->exigir('id', 'KEY');
            foreach ($this->listaColumnasEntreParentesis() as $c) {
                $def['pk'][] = $c;
            }
            return;
        }
        if ($this->comer('id', 'UNIQUE')) {
            if ($this->comer('id', 'KEY') || $this->comer('id', 'INDEX')) {
                $nombre = $this->es('punc', '(') ? $nombre : $this->nombreSimple('clave única');
            }
            $cols = $this->listaColumnasEntreParentesis();
            $def['unique'][] = ['name' => $nombre, 'columns' => $cols];
            return;
        }
        if ($this->es('id', 'FULLTEXT') || $this->es('id', 'SPATIAL')) {
            throw JsonSqlDbError::syntax("Los índices {$this->actual()['u']} no están soportados");
        }
        if ($this->comer('id', 'KEY') || $this->comer('id', 'INDEX')) {
            // Un índice normal, declarado con la tabla (MySQL): se crea con ella
            $nombreIndice = $this->es('punc', '(') ? null : $this->nombreSimple('índice');
            $def['indices'][] = ['name' => $nombreIndice, 'columns' => $this->listaColumnasIndice()];
            return;
        }
        if ($this->comer('id', 'FOREIGN')) {
            $this->exigir('id', 'KEY');
            $cols = $this->listaColumnasEntreParentesis();
            $def['foreign_keys'][] = $this->referencias($cols, $nombre);
            return;
        }
        if ($this->comer('id', 'CHECK')) {
            $def['checks'][] = ['name' => $nombre, 'sql' => $this->textoCheck()];
            return;
        }
        throw JsonSqlDbError::syntax('Restricción de tabla no reconocida');
    }

    /** columna TIPO [restricciones] */
    private function definicionColumna(): array
    {
        $col = ['name' => $this->nombreSimple('columna'), 'type' => 'TEXT',
                'notnull' => false, 'default' => null, 'pk' => false,
                'autoincrement' => false, 'unique' => false];

        // Tipo: puede llevar varias palabras y paréntesis, como DOUBLE PRECISION o VARCHAR(50)
        if ($this->es('id') && !$this->esRestriccionDeColumna()) {
            $tipo = $this->avanzar()['v'];
            while ($this->es('id') && !$this->esRestriccionDeColumna()) {
                $tipo .= ' ' . $this->avanzar()['v'];
            }
            if (strcasecmp($tipo, 'ENUM') === 0 && $this->comer('punc', '(')) {
                // ENUM('a', 'b', …) de MySQL (2.9): un texto que solo admite esos valores
                $valores = [];
                do {
                    $tk = $this->actual();
                    if ($tk['t'] !== 'str') {
                        throw JsonSqlDbError::syntax('ENUM necesita una lista de textos entre comillas');
                    }
                    $this->avanzar();
                    $valores[] = (string)$tk['v'];
                } while ($this->comer('punc', ','));
                $this->exigir('punc', ')');
                $tipo = 'TEXT';
                $col['enum'] = $valores;
            } elseif ($this->comer('punc', '(')) {
                $tipo .= '(' . $this->enteroPositivo('el tipo');
                if ($this->comer('punc', ',')) {
                    $tipo .= ',' . $this->enteroPositivo('el tipo');
                }
                $this->exigir('punc', ')');
                $tipo .= ')';
            }
            $col['type'] = $tipo;
            // UNSIGNED de MySQL (2.9): aquí, que no entren negativos
            while ($this->es('id', 'UNSIGNED') || $this->es('id', 'SIGNED') || $this->es('id', 'ZEROFILL')) {
                if ($this->es('id', 'ZEROFILL')) {
                    throw JsonSqlDbError::syntax('ZEROFILL no está soportado: solo cambia cómo se muestra el número (con ceros delante)');
                }
                $col['unsigned'] = $this->avanzar()['u'] === 'UNSIGNED' || !empty($col['unsigned']);
            }
        }

        while (true) {
            if ($this->comer('id', 'CONSTRAINT')) {
                $this->nombreSimple('restricción');
                continue;
            }
            if ($this->comer('id', 'PRIMARY')) {
                $this->exigir('id', 'KEY');
                $this->comer('id', 'ASC');
                $this->comer('id', 'DESC');
                $col['pk'] = true;
                if ($this->comer('id', 'AUTOINCREMENT') || $this->comer('id', 'AUTO_INCREMENT')) {
                    $col['autoincrement'] = true;
                }
                continue;
            }
            if ($this->comer('id', 'AUTOINCREMENT') || $this->comer('id', 'AUTO_INCREMENT')) {
                $col['autoincrement'] = true;
                continue;
            }
            if ($this->comer('id', 'NOT')) {
                $this->exigir('id', 'NULL');
                $col['notnull'] = true;
                continue;
            }
            if ($this->comer('id', 'NULL')) {
                continue;
            }
            if ($this->comer('id', 'UNIQUE')) {
                $col['unique'] = true;
                continue;
            }
            if ($this->comer('id', 'DEFAULT')) {
                $v = $this->valorPorDefecto();
                if (is_array($v)) {
                    $col['default_expr'] = $v['expr'];     // se calcula al insertar
                } else {
                    $col['default'] = $v;
                }
                continue;
            }
            if ($this->es('id', 'REFERENCES')) {
                $col['references'] = $this->referencias([$col['name']], null);
                continue;
            }
            // MySQL: un comentario y el juego de caracteres de la columna no
            // cambian nada aquí, donde todo es texto UTF-8 en JSON (2.9)
            if ($this->comer('id', 'COMMENT')) {
                if ($this->actual()['t'] !== 'str') {
                    throw JsonSqlDbError::syntax('COMMENT espera un texto entre comillas');
                }
                $this->avanzar();
                continue;
            }
            if ($this->comer('id', 'CHARSET') || ($this->es('id', 'CHARACTER') && $this->esSiguiente('id', 'SET') && $this->avanzar() && $this->avanzar())) {
                $this->nombreSimple('juego de caracteres');
                continue;
            }
            if ($this->es('id', 'ON') && $this->esSiguiente('id', 'UPDATE')) {
                throw JsonSqlDbError::syntax('ON UPDATE en una columna no está soportado: pon el valor en el UPDATE, o usa un trigger BEFORE UPDATE');
            }
            // COLLATE NOCASE | RTRIM | BINARY (2.9, como SQLite): cómo se
            // compara, se ordena, se agrupa y se indexa la columna
            if ($this->comer('id', 'COLLATE')) {
                $c = strtoupper($this->nombreSimple('colación'));
                if (!in_array($c, ['NOCASE', 'RTRIM', 'BINARY'], true)) {
                    throw JsonSqlDbError::syntax("COLLATE $c no está soportado: NOCASE, RTRIM o BINARY");
                }
                $col['collate'] = $c;
                continue;
            }
            if ($this->comer('id', 'CHECK')) {
                $col['checks'][] = $this->textoCheck();
                continue;
            }
            if ($this->es('id', 'GENERATED')) {
                throw JsonSqlDbError::syntax("'{$this->actual()['v']}' no está soportado en una columna");
            }
            break;
        }

        return $col;
    }

    /** @phpstan-impure */
    private function esRestriccionDeColumna(): bool
    {
        foreach (['PRIMARY','NOT','NULL','UNIQUE','DEFAULT','REFERENCES','CHECK','COLLATE',
                  'CONSTRAINT','AUTOINCREMENT','AUTO_INCREMENT','GENERATED',
                  'UNSIGNED','SIGNED','ZEROFILL','COMMENT','CHARSET','CHARACTER','ON'] as $p) {
            if ($this->es('id', $p)) {
                return true;
            }
        }
        return false;
    }

    /** REFERENCES tabla(cols) [ON DELETE acción] [ON UPDATE acción] */
    private function referencias(array $cols, ?string $nombre): array
    {
        $this->exigir('id', 'REFERENCES');
        $destino = $this->nombreTabla();
        $refs    = $this->es('punc', '(') ? $this->listaColumnasEntreParentesis() : [];

        $fk = ['name' => $nombre, 'columns' => $cols, 'table' => $destino, 'references' => $refs,
               'on_delete' => 'NO ACTION', 'on_update' => 'NO ACTION'];

        while ($this->comer('id', 'ON')) {
            if ($this->comer('id', 'DELETE')) {
                $fk['on_delete'] = $this->accionFk();
            } elseif ($this->comer('id', 'UPDATE')) {
                $fk['on_update'] = $this->accionFk();
            } else {
                throw JsonSqlDbError::syntax('Se esperaba ON DELETE u ON UPDATE');
            }
        }
        // Cláusulas que no cambian el resultado: se aceptan y se ignoran
        $this->comer('id', 'DEFERRABLE');
        return $fk;
    }

    private function accionFk(): string
    {
        if ($this->comer('id', 'CASCADE'))  { return 'CASCADE'; }
        if ($this->comer('id', 'RESTRICT')) { return 'RESTRICT'; }
        if ($this->comer('id', 'NO'))       { $this->exigir('id', 'ACTION'); return 'NO ACTION'; }
        if ($this->comer('id', 'SET')) {
            if ($this->comer('id', 'NULL'))    { return 'SET NULL'; }
            if ($this->comer('id', 'DEFAULT')) { return 'SET DEFAULT'; }
        }
        throw JsonSqlDbError::syntax('Acción de clave foránea no reconocida');
    }

    /**
     * Las columnas de un índice: (a, b DESC, c(10)). El sentido y la longitud
     * de prefijo (2.9) solo dicen cómo guardar el índice en otros motores; el
     * de aquí sirve igual en los dos sentidos y con el valor entero.
     *
     * @return list<string>
     */
    private function listaColumnasIndice(): array
    {
        $this->exigir('punc', '(');
        $cols = [];
        do {
            $cols[] = $this->nombreSimple('columna');
            if ($this->comer('punc', '(')) {
                $this->enteroPositivo('la longitud del prefijo');
                $this->exigir('punc', ')');
            }
            $this->comer('id', 'ASC') || $this->comer('id', 'DESC');
        } while ($this->comer('punc', ','));
        $this->exigir('punc', ')');
        return $cols;
    }

    /**
     * CHECK (expresión), 2.9: su texto, que se comprueba con cada fila que se
     * escribe. Como un DEFAULT, sin parámetros ni subconsultas.
     */
    private function textoCheck(): string
    {
        $this->exigir('punc', '(');
        $ini   = $this->i;
        $desde = (int)$this->actual()['p'];
        $this->expr();
        $hasta = (int)$this->actual()['p'];
        for ($k = $ini; $k < $this->i; $k++) {
            $t = $this->t[$k];
            if ($t['t'] === 'param' || ($t['t'] === 'id' && !$t['q'] && $t['u'] === 'SELECT')) {
                throw JsonSqlDbError::syntax('Un CHECK no puede llevar parámetros ni subconsultas');
            }
        }
        $this->exigir('punc', ')');
        return $this->textoEntre($desde, $hasta);
    }

    /**
     * Valor de un DEFAULT: un literal, o (2.8) ['expr' => texto] con una
     * expresión que se calcula al insertar cada fila: CURRENT_TIMESTAMP,
     * CURRENT_DATE, CURRENT_TIME o (expresión) entre paréntesis, como en
     * SQLite. La expresión no puede usar columnas, subconsultas ni parámetros.
     *
     * @return mixed|array{expr: string}
     */
    private function valorPorDefecto()
    {
        $tk = $this->actual();
        if ($tk['t'] === 'id' && !$tk['q'] && in_array($tk['u'], ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'], true)) {
            $this->avanzar();
            return ['expr' => $tk['u']];
        }
        if ($this->es('punc', '(')) {
            $ini = $this->i;
            $this->avanzar();
            $desde = (int)$this->actual()['p'];
            $e = $this->expr();
            $hasta = (int)$this->actual()['p'];
            $this->exigir('punc', ')');
            if ($e['k'] === 'lit') {
                return $e['v'];                          // (5) o ('x'): un valor fijo, como antes
            }
            if ($e['k'] === 'un' && $e['op'] === '-' && ($e['e']['k'] ?? '') === 'lit' && is_numeric($e['e']['v'])) {
                return -$e['e']['v'];
            }
            for ($k = $ini; $k < $this->i; $k++) {
                $t = $this->t[$k];
                if ($t['t'] === 'param' || ($t['t'] === 'id' && !$t['q'] && $t['u'] === 'SELECT')) {
                    throw JsonSqlDbError::syntax('Un DEFAULT no puede llevar parámetros ni subconsultas');
                }
            }
            if (self::usaColumnas($e)) {
                throw JsonSqlDbError::syntax('Un DEFAULT no puede usar columnas');
            }
            return ['expr' => trim(substr($this->sql, $desde, $hasta - $desde))];
        }

        $negativo = false;
        if ($this->es('op', '-')) { $this->avanzar(); $negativo = true; }
        elseif ($this->es('op', '+')) { $this->avanzar(); }

        $tk = $this->actual();
        if ($tk['t'] === 'num') {
            $this->avanzar();
            return $negativo ? -$tk['v'] : $tk['v'];
        }
        if ($negativo) {
            throw JsonSqlDbError::syntax('DEFAULT negativo solo válido con números');
        }
        if ($tk['t'] === 'str') {
            $this->avanzar();
            return $tk['v'];
        }
        if ($tk['t'] === 'id' && !$tk['q'] && $tk['u'] === 'NULL') {
            $this->avanzar();
            return null;
        }
        throw JsonSqlDbError::syntax("DEFAULT admite un valor fijo, CURRENT_TIMESTAMP, CURRENT_DATE, CURRENT_TIME o (una expresión), y se encontró '{$tk['v']}'");
    }

    /** ¿La expresión lee alguna columna? */
    private static function usaColumnas(array $n): bool
    {
        if (($n['k'] ?? '') === 'col') {
            return true;
        }
        foreach ($n as $v) {
            if (is_array($v) && self::usaColumnas($v)) {
                return true;
            }
        }
        return false;
    }

    /**
     * CREATE TRIGGER [IF NOT EXISTS] n [BEFORE|AFTER] (INSERT|UPDATE|DELETE) ON t
     * [FOR EACH ROW] [WHEN expr] BEGIN sentencia; ... END
     */
    private function createTrigger(): array
    {
        $siNoExiste = false;
        if ($this->comer('id', 'IF')) {
            $this->exigir('id', 'NOT');
            $this->exigir('id', 'EXISTS');
            $siNoExiste = true;
        }
        $nombre = $this->nombreSimple('trigger');

        $timing = 'AFTER';
        if ($this->comer('id', 'BEFORE'))     { $timing = 'BEFORE'; }
        elseif ($this->comer('id', 'AFTER'))  { $timing = 'AFTER'; }
        elseif ($this->es('id', 'INSTEAD'))   { throw JsonSqlDbError::syntax('INSTEAD OF no está soportado'); }

        if ($this->comer('id', 'INSERT'))      { $evento = 'INSERT'; }
        elseif ($this->comer('id', 'DELETE'))  { $evento = 'DELETE'; }
        elseif ($this->comer('id', 'UPDATE'))  {
            $evento = 'UPDATE';
            if ($this->comer('id', 'OF')) {
                throw JsonSqlDbError::syntax('UPDATE OF columnas no está soportado en los triggers');
            }
        } else {
            throw JsonSqlDbError::syntax('El trigger debe indicar INSERT, UPDATE o DELETE');
        }

        $this->exigir('id', 'ON');
        $tabla = $this->nombreTabla();

        if ($this->comer('id', 'FOR')) {
            $this->exigir('id', 'EACH');
            $this->exigir('id', 'ROW');
        }

        $cuando = null;
        if ($this->comer('id', 'WHEN')) {
            $ini = $this->actual()['p'];
            $this->expr();
            $cuando = $this->textoEntre($ini, $this->actual()['p']);
        }

        $this->exigir('id', 'BEGIN');
        $cuerpo = $this->cuerpoTrigger(['END']);
        $this->exigir('id', 'END');

        if ($cuerpo === []) {
            throw JsonSqlDbError::syntax("El trigger '$nombre' no tiene sentencias");
        }

        return ['k' => 'create_trigger', 'tabla' => $tabla, 'si_no_existe' => $siNoExiste,
                'trg' => ['name' => $nombre, 'timing' => $timing, 'event' => $evento,
                          'when' => $cuando, 'body' => $cuerpo, 'sql' => trim($this->sql)]];
    }

    /**
     * Las sentencias del cuerpo de un trigger, hasta una de las palabras de
     * $hasta. Cada una se guarda como su texto, salvo:
     *  - IF cond THEN … [ELSEIF cond THEN …] [ELSE …] END IF, que se guarda como
     *    ['si' => [[cond, [sentencias]], …], 'sino' => ?[sentencias]];
     *  - SET NEW.col = expr [, NEW.col = expr], que solo vale en un trigger
     *    BEFORE INSERT o BEFORE UPDATE y cambia la fila que se va a escribir.
     * Con esto un trigger de MySQL o de PostgreSQL se puede importar tal como
     * funciona allí, sin reescribirlo en otra forma.
     *
     * @param list<string> $hasta
     * @return list<string|array>
     */
    private function cuerpoTrigger(array $hasta): array
    {
        $cuerpo = [];
        while (true) {
            foreach ($hasta as $palabra) {
                if ($this->es('id', $palabra)) {
                    return $cuerpo;
                }
            }
            if ($this->es('eof')) {
                throw JsonSqlDbError::syntax('Falta el END del trigger');
            }
            if ($this->comer('id', 'IF')) {
                $si = [];
                do {
                    $ini = $this->actual()['p'];
                    $this->expr();
                    $cond = $this->textoEntre($ini, $this->actual()['p']);
                    $this->exigir('id', 'THEN');
                    $si[] = [$cond, $this->cuerpoTrigger(['ELSEIF', 'ELSE', 'END'])];
                } while ($this->comer('id', 'ELSEIF'));
                $sino = null;
                if ($this->comer('id', 'ELSE')) {
                    $sino = $this->cuerpoTrigger(['END']);
                }
                $this->exigir('id', 'END');
                $this->exigir('id', 'IF');
                $cuerpo[] = ['si' => $si, 'sino' => $sino];
            } elseif ($this->es('id', 'SET')) {
                $ini = $this->actual()['p'];
                $this->setNew();
                $cuerpo[] = $this->textoEntre($ini, $this->actual()['p']);
            } else {
                $ini = $this->actual()['p'];
                $this->sentencia();
                $cuerpo[] = $this->textoEntre($ini, $this->actual()['p']);
            }
            $hayFin = false;
            foreach ($hasta as $palabra) {
                $hayFin = $hayFin || $this->es('id', $palabra);
            }
            if (!$this->comer('punc', ';') && !$hayFin) {
                throw JsonSqlDbError::syntax('Falta el ; entre las sentencias del trigger');
            }
        }
    }


    private function drop(): array
    {
        $this->exigir('id', 'DROP');

        if ($this->comer('id', 'TRIGGER')) {
            $siExiste = $this->siExiste();
            return ['k' => 'drop_trigger', 'nombre' => $this->nombreSimple('trigger'), 'si_existe' => $siExiste];
        }
        if ($this->comer('id', 'VIEW')) {
            $siExiste = $this->siExiste();
            return ['k' => 'drop_view', 'nombre' => $this->nombreTabla(), 'si_existe' => $siExiste];
        }
        if ($this->comer('id', 'DATABASE')) {
            $siExiste = $this->siExiste();
            return ['k' => 'drop_database', 'base' => $this->nombreSimple('base de datos'),
                    'si_existe' => $siExiste];
        }
        if ($this->comer('id', 'INDEX')) {
            $siExiste = $this->siExiste();
            $nombre   = $this->nombreSimple('índice');
            // El ON es opcional: sin él se busca en todas las tablas
            $tabla = $this->comer('id', 'ON') ? $this->nombreTabla() : null;
            return ['k' => 'drop_index', 'nombre' => $nombre, 'tabla' => $tabla,
                    'si_existe' => $siExiste];
        }
        $this->exigir('id', 'TABLE');
        $siExiste = $this->siExiste();
        return ['k' => 'drop_table', 'tabla' => $this->nombreTabla(), 'si_existe' => $siExiste];
    }

    private function siExiste(): bool
    {
        if ($this->comer('id', 'IF')) {
            $this->exigir('id', 'EXISTS');
            return true;
        }
        return false;
    }

    /**
     * CHECK KEYS [FROM t]     revisa las claves foráneas y solo informa
     * REPAIR KEYS [FROM t]    además corrige lo que se puede corregir solo
     * CHECK TABLES [FROM t]   revisa los ficheros JSON y solo informa (2.9)
     * REPAIR TABLES [FROM t]  además los repara (ver StorageMantenimiento::revisarFicheros())
     * REPAIR TABLES FORGET n  deja de tener pendiente la fila apartada n (2.9)
     */
    private function check(bool $corregir): array
    {
        $this->exigir('id', $corregir ? 'REPAIR' : 'CHECK');
        $que = $this->comer('id', 'TABLES') ? 'tables' : null;
        if ($que === 'tables' && $corregir && $this->comer('id', 'FORGET')) {
            return ['k' => 'repair_tables', 'tabla' => null, 'olvidar' => $this->enteroPositivo('REPAIR TABLES FORGET')];
        }
        if ($que === null) {
            $this->exigir('id', 'KEYS');
            $que = 'keys';
        }
        $tabla = $this->comer('id', 'FROM') ? $this->nombreTabla() : null;

        return ['k' => ($corregir ? 'repair_' : 'check_') . $que, 'tabla' => $tabla];
    }

    /**
     * SHOW DATABASES | TABLES | SCHEMA t | COLUMNS FROM t | KEYS FROM t
     *      | TRIGGERS [FROM t]
     */
    private function show(): array
    {
        $this->exigir('id', 'SHOW');
        $tk = $this->actual();
        if ($tk['t'] !== 'id') {
            throw JsonSqlDbError::syntax(
                'SHOW necesita DATABASES, TABLES, VIEWS, SCHEMA, COLUMNS, KEYS, INDEXES, TRIGGERS o CREATE');
        }
        $que = $tk['u'];
        $this->avanzar();

        switch ($que) {
            case 'DATABASES':
                return ['k' => 'show_databases'];
            case 'TABLES':
                return ['k' => 'show_tables'];
            case 'VIEWS':
                return ['k' => 'show_views'];
            case 'SCHEMA':
            case 'COLUMNS':
                $this->comer('id', 'FROM');
                return ['k' => 'show_schema', 'tabla' => $this->nombreTabla()];
            case 'KEYS':
                $this->comer('id', 'FROM');
                return ['k' => 'show_keys', 'tabla' => $this->nombreTabla()];
            case 'CREATE':
                // SHOW CREATE TABLE t y SHOW CREATE VIEW v, de MySQL (2.9)
                if ($this->comer('id', 'VIEW')) {
                    return ['k' => 'show_create', 'vista' => $this->nombreTabla()];
                }
                $this->exigir('id', 'TABLE');
                return ['k' => 'show_create', 'tabla' => $this->nombreTabla()];
            case 'TRIGGERS':
                $tabla = $this->comer('id', 'FROM') ? $this->nombreTabla() : null;
                return ['k' => 'show_triggers', 'tabla' => $tabla];

            case 'INDEXES':
            case 'INDICES':
            case 'INDEX':
                $tabla = $this->comer('id', 'FROM') ? $this->nombreTabla() : null;
                return ['k' => 'show_indexes', 'tabla' => $tabla];
        }
        throw JsonSqlDbError::syntax("SHOW no admite '{$tk['v']}'");
    }

    /** ALTER TABLE t ADD/DROP/RENAME */
    private function alter(): array
    {
        $this->exigir('id', 'ALTER');
        $this->exigir('id', 'TABLE');
        $tabla = $this->nombreTabla();

        if ($this->comer('id', 'ADD')) {
            if ($this->esRestriccionDeTabla()) {
                $def = ['pk' => [], 'unique' => [], 'foreign_keys' => []];
                $this->restriccionDeTabla($def);
                if ($def['pk'] !== []) {
                    return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'add_pk',
                            'columnas' => $def['pk']];
                }
                if (isset($def['indices'])) {
                    // ADD INDEX / ADD KEY de MySQL: CREATE INDEX
                    $ix = $def['indices'][0];
                    return ['k' => 'create_index', 'tabla' => $tabla, 'nombre' => $ix['name'] ?? (string)preg_replace('/[^A-Za-z0-9_]/', '_', $ix['columns'][0]),
                            'columnas' => $ix['columns'], 'si_no_existe' => false];
                }
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'add_constraint',
                        'unique' => $def['unique'], 'foreign_keys' => $def['foreign_keys'], 'checks' => $def['checks'] ?? []];
            }
            $this->comer('id', 'COLUMN');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'add',
                    'def' => $this->definicionColumna()];
        }
        if ($this->comer('id', 'MODIFY')) {
            $this->comer('id', 'COLUMN');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'modify',
                    'def' => $this->definicionColumna()];
        }
        if ($this->comer('id', 'CHANGE')) {
            // CHANGE [COLUMN] vieja nueva TIPO … de MySQL: renombra y modifica (2.9;
            // antes se tomaba como MODIFY y el nombre nuevo pasaba por un tipo)
            $this->comer('id', 'COLUMN');
            $vieja = $this->nombreSimple('columna');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'change',
                    'col' => $vieja, 'def' => $this->definicionColumna()];
        }
        if ($this->comer('id', 'DROP')) {
            if ($this->comer('id', 'PRIMARY')) {
                $this->exigir('id', 'KEY');
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'drop_pk'];
            }
            if ($this->comer('id', 'CONSTRAINT')) {
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'drop_constraint',
                        'nombre' => $this->nombreSimple('restricción')];
            }
            if ($this->comer('id', 'INDEX') || $this->comer('id', 'KEY')) {
                // DROP INDEX / DROP KEY de MySQL: el índice, o la clave única con ese nombre
                return ['k' => 'drop_index', 'nombre' => $this->nombreSimple('índice'), 'tabla' => $tabla, 'si_existe' => false];
            }
            if ($this->comer('id', 'FOREIGN')) {
                $this->exigir('id', 'KEY');
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'drop_constraint',
                        'nombre' => $this->nombreSimple('restricción')];
            }
            if ($this->comer('id', 'CHECK')) {
                // DROP CHECK nombre, de MySQL (2.9)
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'drop_constraint',
                        'nombre' => $this->nombreSimple('restricción')];
            }
            $this->comer('id', 'COLUMN');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'drop',
                    'col' => $this->nombreSimple('columna')];
        }
        if ($this->comer('id', 'RENAME')) {
            if ($this->comer('id', 'TO')) {
                return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'rename',
                        'nuevo' => $this->nombreTabla()];
            }
            $this->comer('id', 'COLUMN');
            $col = $this->nombreSimple('columna');
            $this->exigir('id', 'TO');
            return ['k' => 'alter_table', 'tabla' => $tabla, 'accion' => 'rename_col',
                    'col' => $col, 'nuevo' => $this->nombreSimple('columna')];
        }
        throw JsonSqlDbError::syntax('ALTER TABLE solo admite ADD, MODIFY, DROP o RENAME');
    }

    private function textoEntre(int $desde, int $hasta): string
    {
        return rtrim(trim(substr($this->sql, $desde, $hasta - $desde)), ';');
    }
}
