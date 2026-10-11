<?php
declare(strict_types=1);

/**
 * Prueba del analizador (léxico + sintáctico). Ejecutar: php tests/f2_parser.php
 * No toca el disco.
 *
 * https://miguelenred.es/jsonsqldb
 */
require_once __DIR__ . '/../engine/bootstrap.php';

use JsonSQLDB\JsonSqlDbError;
use JsonSQLDB\Lexer;
use JsonSQLDB\Parser;

$ok = 0; $ko = 0;

function chk(string $titulo, callable $fn): void {
    global $ok, $ko;
    try {
        $r = $fn();
        if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
        else { $ko++; echo "  FALLO $titulo -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        global $ko; $ko++;
        echo "  FALLO $titulo -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function malaSintaxis(string $titulo, string $sql): void {
    chk($titulo, static function () use ($sql) {
        try { Parser::analizar($sql); } catch (JsonSqlDbError $e) { return $e->sqlState === 'SYNTAX' ?: $e->sqlState; }
        return 'no lanzó error';
    });
}

echo "\n== Léxico ==\n";
chk('comentarios y multilínea', function () {
    $t = Lexer::tokens("SELECT a -- comentario\n /* otro\n comentario */ FROM t");
    return count($t) === 5 && $t[0]['u'] === 'SELECT' && $t[3]['v'] === 't' && $t[4]['t'] === 'eof';
});
chk('cadena con comilla escapada', function () {
    $t = Lexer::tokens("SELECT 'O''Donnell'");
    return $t[1]['t'] === 'str' && $t[1]['v'] === "O'Donnell";
});
chk('identificadores entrecomillados', function () {
    $t = Lexer::tokens('SELECT "mi campo", [otro campo], `tercero` FROM t');
    return $t[1]['v'] === 'mi campo' && $t[3]['v'] === 'otro campo' && $t[5]['v'] === 'tercero'
        && $t[1]['q'] === true;
});
chk('números enteros y decimales', function () {
    $t = Lexer::tokens('SELECT 10, 3.5, 1e3, .25');
    return $t[1]['v'] === 10 && $t[3]['v'] === 3.5 && $t[5]['v'] === 1000.0 && $t[7]['v'] === 0.25;
});
chk('operadores de dos caracteres', function () {
    $t = Lexer::tokens("SELECT a<>b, c>=d, e||f");
    return $t[2]['v'] === '<>' && $t[6]['v'] === '>=' && $t[10]['v'] === '||';
});
chk('control de línea en errores', function () {
    try { Lexer::tokens("SELECT a\nFROM t\nWHERE b = 'sin cerrar"); }
    catch (JsonSqlDbError $e) { return str_contains($e->getMessage(), 'línea 3'); }
    return 'no lanzó error';
});
malaSintaxis('carácter no válido', 'SELECT a # b');

echo "\n== SELECT básico ==\n";
chk('columnas, alias y DISTINCT', function () {
    $a = Parser::analizar('SELECT DISTINCT nombre AS usuario, edad n FROM usuarios');
    return $a['distinct'] === true
        && $a['cols'][0]['alias'] === 'usuario' && $a['cols'][0]['expr']['nombre'] === 'nombre'
        && $a['cols'][1]['alias'] === 'n'
        && $a['from'][0]['nombre'] === 'usuarios' && $a['from'][0]['alias'] === null;
});
chk('asterisco global y por tabla', function () {
    $a = Parser::analizar('SELECT *, u.* FROM usuarios u');
    return $a['cols'][0] === ['star' => true, 'tabla' => null]
        && $a['cols'][1] === ['star' => true, 'tabla' => 'u']
        && $a['from'][0]['alias'] === 'u';
});
chk('SELECT sin FROM', function () {
    $a = Parser::analizar("SELECT 1 + 2 AS suma");
    return $a['from'] === [] && $a['cols'][0]['expr']['op'] === '+';
});
chk('punto y coma final', fn() => Parser::analizar('SELECT 1;')['k'] === 'select');
malaSintaxis('dos sentencias', 'SELECT 1; SELECT 2');
chk('UPDATE y DELETE admiten ORDER BY, LIMIT y OFFSET, con RETURNING antes o después (2.9)', function () {
    $a = Parser::analizar('UPDATE t SET a = 1 WHERE a > 0 ORDER BY a DESC, b LIMIT 1 OFFSET 2');
    $b = Parser::analizar('DELETE FROM t RETURNING a ORDER BY a LIMIT 5');
    $c = Parser::analizar('DELETE FROM t LIMIT 5 RETURNING a');
    return count($a['order']) === 2 && $a['limit'] === 1 && $a['offset'] === 2 && $b['limit'] === 5 && $b['returning'] !== null
        && $c['returning'] !== null && $c['order'] === [] ?: json_encode([$a, $b, $c]);
});
malaSintaxis('DELETE … ORDER sin BY', 'DELETE FROM t ORDER a LIMIT 1');
malaSintaxis('LIMIT con una columna', 'SELECT a FROM t LIMIT a');
malaSintaxis('LIMIT con decimales', 'SELECT a FROM t LIMIT 2.5');
chk('el ; del final puede ir repetido, y LIMIT admite una expresión constante o un negativo', function () {
    $a = Parser::analizar('SELECT a FROM t LIMIT 2 * 3 OFFSET -4;;');
    $b = Parser::analizar('SELECT a FROM t LIMIT -1');
    return $a['limit'] === 6 && $a['offset'] === 0 && $b['limit'] === null ?: [$a['limit'], $a['offset'], $b['limit']];
});
malaSintaxis('sentencia no soportada', 'PRAGMA table_info(t)');

echo "\n== Precedencia de operadores ==\n";
chk('AND antes que OR', function () {
    $a = Parser::analizar('SELECT 1 WHERE a OR b AND c');
    $w = $a['where'];
    return $w['op'] === 'OR' && $w['d']['op'] === 'AND';
});
chk('multiplicación antes que suma', function () {
    $w = Parser::analizar('SELECT 1 WHERE a + b * c > 0')['where'];
    return $w['op'] === '>' && $w['i']['op'] === '+' && $w['i']['d']['op'] === '*';
});
chk('comparación antes que AND', function () {
    $w = Parser::analizar('SELECT 1 WHERE a = 1 AND b = 2')['where'];
    return $w['op'] === 'AND' && $w['i']['op'] === '=' && $w['d']['op'] === '=';
});
chk('unario negativo', function () {
    $w = Parser::analizar('SELECT 1 WHERE -a > -5')['where'];
    return $w['i']['k'] === 'un' && $w['i']['op'] === '-' && $w['d']['e']['v'] === 5;
});
chk('paréntesis cambian la precedencia', function () {
    $w = Parser::analizar('SELECT 1 WHERE (a OR b) AND c')['where'];
    return $w['op'] === 'AND' && $w['i']['op'] === 'OR';
});
chk('concatenación', function () {
    $e = Parser::analizar("SELECT nombre || ' ' || apellidos AS completo")['cols'][0]['expr'];
    return $e['op'] === '||' && $e['i']['op'] === '||';
});
chk('|| , -> y ->> van por encima de * / % y de + -, al mismo nivel y por la izquierda, como en SQLite (2.9: el || iba por debajo de la suma)', function () {
    $c = static fn(string $sql): array => Parser::analizar("SELECT $sql")['cols'][0]['expr'];
    $a = $c('1 + 2 || 3');              // 1 + (2 || 3)
    $b = $c('2 * 3 || 4 - 1');          // (2 * (3 || 4)) - 1
    $d = $c("'x' || j -> 'a'");         // ('x' || j) -> 'a'
    $e = $c("j ->> 'a' || 'b'");        // (j ->> 'a') || 'b'
    $f = $c("- j -> 'a'");              // (-j) -> 'a'
    return $a['op'] === '+' && $a['d']['op'] === '||'
        && $b['op'] === '-' && $b['i']['op'] === '*' && $b['i']['d']['op'] === '||'
        && $d['nombre'] === '->' && $d['args'][0]['op'] === '||'
        && $e['op'] === '||' && $e['i']['nombre'] === '->>'
        && $f['nombre'] === '->' && $f['args'][0]['op'] === '-'
        ?: json_encode([$a, $b, $d, $e, $f]);
});
chk('20.000 comparaciones encadenadas o anidadas (=, <, BETWEEN, IN, IS, con COLLATE) se analizan en mucho menos de un segundo, y la cadena = y 5.000 NULLIF o MIN anidados se resuelven igual (2.9: cada una recorría las anteriores; 20.000, más de un minuto)', function () {
    // En otro proceso, con tope de tiempo: sin el arreglo, cada una tarda minutos
    $codigo = 'require ' . var_export(dirname(__DIR__) . '/engine/bootstrap.php', true) . '; set_time_limit(20);
        $cpu = static function (): float { $r = getrusage(); return $r["ru_utime.tv_sec"] + $r["ru_utime.tv_usec"] / 1e6 + $r["ru_stime.tv_sec"] + $r["ru_stime.tv_usec"] / 1e6; };
        $n = 20000; $m = 5000;
        $peor = [];
        foreach (["SELECT 1" . str_repeat("=1", $n), "SELECT 1" . str_repeat("<1", $n), "SELECT 1" . str_repeat(" BETWEEN 1 AND 1", $n),
                  "SELECT 1" . str_repeat(" IN (1)", $n), "SELECT 1" . str_repeat(" IS 1", $n), "SELECT \'a\' COLLATE NOCASE" . str_repeat("=\'a\'", $n),
                  "SELECT 1" . str_repeat("=1", $n) . " COLLATE NOCASE", "SELECT " . str_repeat("1=(", $m) . "1" . str_repeat(")", $m),
                  "SELECT " . str_repeat("(", $m) . "1" . str_repeat("=1)", $m), "SELECT " . str_repeat("ABS(1=", $m) . "1" . str_repeat(")", $m),
                  "SELECT \'a\' COLLATE NOCASE, " . str_repeat("(", $m) . "1" . str_repeat("=1)", $m)] as $i => $sql) {
            $t = $cpu();
            $ast = JsonSQLDB\Parser::analizar($sql);
            $peor[$i] = round($cpu() - $t, 3);
        }
        $t = $cpu();
        JsonSQLDB\Evaluator::resolver(JsonSQLDB\Parser::analizar("SELECT 1" . str_repeat("=1", $n))["cols"][0]["expr"], []);
        $peor["resolver"] = round($cpu() - $t, 3);
        // NULLIF, MIN y MAX anidados: cada uno buscaba la colación en todo lo de dentro (2.9)
        foreach (["nullif" => str_repeat("NULLIF(", $m) . "1" . str_repeat(", 2)", $m), "min" => str_repeat("MIN(1, ", $m) . "1" . str_repeat(")", $m)] as $k => $e) {
            $t = $cpu();
            JsonSQLDB\Evaluator::resolver(JsonSQLDB\Parser::analizar("SELECT $e")["cols"][0]["expr"], []);
            $peor[$k] = round($cpu() - $t, 3);
        }
        echo json_encode($peor);';
    $salida = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($codigo) . ' 2>&1'));
    $peor = json_decode($salida, true);
    return is_array($peor) && count($peor) === 14 && max($peor) < 0.5 ?: substr($salida, 0, 300);
});

echo "\n== Predicados ==\n";
chk('IS NULL / IS NOT NULL', function () {
    $w = Parser::analizar('SELECT 1 WHERE a IS NULL AND b IS NOT NULL')['where'];
    return $w['i']['k'] === 'null' && $w['i']['not'] === false
        && $w['d']['k'] === 'null' && $w['d']['not'] === true;
});
chk('IN con lista', function () {
    $w = Parser::analizar("SELECT 1 WHERE ciudad IN ('Madrid','Valencia')")['where'];
    return $w['k'] === 'in' && count($w['lista']) === 2 && $w['select'] === null && $w['not'] === false;
});
chk('NOT IN con subconsulta', function () {
    $w = Parser::analizar('SELECT 1 WHERE id NOT IN (SELECT usuario_id FROM pedidos)')['where'];
    return $w['k'] === 'in' && $w['not'] === true && $w['select']['from'][0]['nombre'] === 'pedidos';
});
chk('BETWEEN no se come el AND externo', function () {
    $w = Parser::analizar('SELECT 1 WHERE precio BETWEEN 10 AND 50 AND stock > 0')['where'];
    return $w['op'] === 'AND' && $w['i']['k'] === 'between'
        && $w['i']['min']['v'] === 10 && $w['i']['max']['v'] === 50 && $w['d']['op'] === '>';
});
chk('NOT BETWEEN', function () {
    $w = Parser::analizar('SELECT 1 WHERE precio NOT BETWEEN 10 AND 50')['where'];
    return $w['k'] === 'between' && $w['not'] === true;
});
chk('LIKE con ESCAPE', function () {
    $w = Parser::analizar("SELECT 1 WHERE nombre LIKE 'Mar!%%' ESCAPE '!'")['where'];
    return $w['k'] === 'like' && $w['escape']['v'] === '!' && $w['not'] === false;
});
chk('NOT LIKE', function () {
    $w = Parser::analizar("SELECT 1 WHERE nombre NOT LIKE 'Mar%'")['where'];
    return $w['k'] === 'like' && $w['not'] === true;
});
chk('NOT lógico', function () {
    $w = Parser::analizar('SELECT 1 WHERE NOT (a = 1)')['where'];
    return $w['k'] === 'un' && $w['op'] === 'NOT' && $w['e']['op'] === '=';
});

echo "\n== Funciones y CASE ==\n";
chk('COUNT(*)', function () {
    $e = Parser::analizar('SELECT COUNT(*) FROM t')['cols'][0]['expr'];
    return $e['k'] === 'fn' && $e['nombre'] === 'COUNT' && $e['star'] === true && $e['args'] === [];
});
chk('COUNT(DISTINCT x)', function () {
    $e = Parser::analizar('SELECT COUNT(DISTINCT ciudad) FROM t')['cols'][0]['expr'];
    return $e['distinct'] === true && $e['args'][0]['nombre'] === 'ciudad';
});
chk('función con varios argumentos', function () {
    $e = Parser::analizar("SELECT SUBSTR(nombre, 1, 3) FROM t")['cols'][0]['expr'];
    return $e['nombre'] === 'SUBSTR' && count($e['args']) === 3 && $e['args'][2]['v'] === 3;
});
chk('funciones anidadas', function () {
    $e = Parser::analizar("SELECT UPPER(TRIM(nombre)) FROM t")['cols'][0]['expr'];
    return $e['nombre'] === 'UPPER' && $e['args'][0]['nombre'] === 'TRIM';
});
chk('CASE con WHEN/ELSE', function () {
    $e = Parser::analizar("SELECT CASE WHEN edad >= 18 THEN 'Adulto' ELSE 'Menor' END AS cat FROM t")['cols'][0];
    return $e['expr']['k'] === 'case' && $e['expr']['base'] === null
        && count($e['expr']['when']) === 1 && $e['expr']['else']['v'] === 'Menor' && $e['alias'] === 'cat';
});
chk('CASE con expresión base', function () {
    $e = Parser::analizar("SELECT CASE tipo WHEN 1 THEN 'A' WHEN 2 THEN 'B' END FROM t")['cols'][0]['expr'];
    return $e['base']['nombre'] === 'tipo' && count($e['when']) === 2 && $e['else'] === null;
});
malaSintaxis('CASE sin END', "SELECT CASE WHEN a THEN 1 FROM t");

echo "\n== FROM y JOIN ==\n";
chk('INNER JOIN con ON', function () {
    $a = Parser::analizar('SELECT u.nombre, p.total FROM usuarios u INNER JOIN pedidos p ON u.id = p.usuario_id');
    return count($a['from']) === 2 && $a['from'][1]['join'] === 'INNER'
        && $a['from'][1]['alias'] === 'p' && $a['from'][1]['on']['op'] === '='
        && $a['cols'][0]['expr']['tabla'] === 'u';
});
chk('LEFT OUTER JOIN', function () {
    $a = Parser::analizar('SELECT * FROM a LEFT OUTER JOIN b ON a.id = b.a_id');
    return $a['from'][1]['join'] === 'LEFT';
});
chk('JOIN a secas es INNER', function () {
    $a = Parser::analizar('SELECT * FROM a JOIN b ON a.id = b.a_id');
    return $a['from'][1]['join'] === 'INNER';
});
chk('varios JOIN encadenados', function () {
    $a = Parser::analizar('SELECT * FROM a JOIN b ON a.id=b.a JOIN c ON b.id=c.b LEFT JOIN d ON c.id=d.c');
    return count($a['from']) === 4 && $a['from'][3]['join'] === 'LEFT';
});
chk('coma equivale a CROSS JOIN', function () {
    $a = Parser::analizar('SELECT * FROM a, b WHERE a.id = b.a_id');
    return $a['from'][1]['join'] === 'CROSS' && $a['from'][1]['on'] === null;
});
chk('subconsulta en FROM', function () {
    $a = Parser::analizar('SELECT t.c FROM (SELECT ciudad AS c FROM usuarios) t');
    return $a['from'][0]['tipo'] === 'sub' && $a['from'][0]['alias'] === 't'
        && $a['from'][0]['select']['from'][0]['nombre'] === 'usuarios';
});
chk('subconsulta en FROM sin alias: recibe uno que no se puede escribir, como en SQLite (2.9: era un error)', function () {
    $a = Parser::analizar('SELECT * FROM (SELECT 1), (SELECT 2)');
    return [$a['from'][0]['alias'], $a['from'][1]['alias']] === ['(subconsulta 1)', '(subconsulta 2)'] ?: json_encode(array_column($a['from'], 'alias'));
});
malaSintaxis('JOIN sin ON', 'SELECT * FROM a JOIN b');
chk('FULL JOIN se analiza', function () {
    $a = Parser::analizar('SELECT * FROM a FULL JOIN b ON a.id = b.a');
    return $a['from'][1]['join'] === 'FULL' ?: json_encode($a['from'][1]['join'] ?? null);
});
chk('FULL OUTER JOIN también', function () {
    $a = Parser::analizar('SELECT * FROM a FULL OUTER JOIN b ON a.id = b.a');
    return $a['from'][1]['join'] === 'FULL';
});
chk('REGEXP y RLIKE se analizan', function () {
    $a = Parser::analizar("SELECT * FROM t WHERE m REGEXP 'x'");
    $b = Parser::analizar("SELECT * FROM t WHERE m NOT RLIKE 'x'");
    return $a['where']['k'] === 'regexp' && $a['where']['not'] === false
        && $b['where']['k'] === 'regexp' && $b['where']['not'] === true;
});
chk('CAST se analiza, con y sin longitud', function () {
    $a = Parser::analizar('SELECT CAST(x AS INTEGER) AS v FROM t');
    $b = Parser::analizar('SELECT CAST(x AS DECIMAL(10,2)) AS v FROM t');
    return $a['cols'][0]['expr']['k'] === 'cast' && $a['cols'][0]['expr']['tipo'] === 'INTEGER'
        && $b['cols'][0]['expr']['tipo'] === 'DECIMAL';
});

echo "\n== GROUP BY, HAVING, ORDER BY, LIMIT ==\n";
chk('GROUP BY con HAVING', function () {
    $a = Parser::analizar('SELECT ciudad, COUNT(*) AS n FROM usuarios GROUP BY ciudad HAVING COUNT(*) > 1');
    return count($a['group']) === 1 && $a['group'][0]['nombre'] === 'ciudad'
        && $a['having']['op'] === '>' && $a['having']['i']['nombre'] === 'COUNT';
});
chk('GROUP BY con varias columnas', function () {
    $a = Parser::analizar('SELECT a, b FROM t GROUP BY a, b');
    return count($a['group']) === 2;
});
chk('ORDER BY con direcciones', function () {
    $a = Parser::analizar('SELECT * FROM t ORDER BY nombre ASC, edad DESC');
    return $a['order'][0]['dir'] === 'ASC' && $a['order'][1]['dir'] === 'DESC'
        && $a['order'][1]['expr']['nombre'] === 'edad';
});
chk('ORDER BY con expresión', function () {
    $a = Parser::analizar('SELECT * FROM t ORDER BY LENGTH(nombre) DESC');
    return $a['order'][0]['expr']['k'] === 'fn';
});
chk('LIMIT y OFFSET', function () {
    $a = Parser::analizar('SELECT * FROM t LIMIT 10 OFFSET 20');
    return $a['limit'] === 10 && $a['offset'] === 20;
});
chk('LIMIT offset, cantidad', function () {
    $a = Parser::analizar('SELECT * FROM t LIMIT 20, 10');
    return $a['limit'] === 10 && $a['offset'] === 20;
});
malaSintaxis('LIMIT no numérico', 'SELECT * FROM t LIMIT x');
chk('una subconsulta en LIMIT, OFFSET o el marco de una ventana es un error que lo dice (2.9: error de PHP; SQLite la admite en LIMIT y OFFSET, aquí no se puede ejecutar al analizar)', function () {
    foreach (['SELECT 1 LIMIT (SELECT 1)', 'SELECT 1 LIMIT 1 OFFSET (SELECT 1)', 'SELECT 1 LIMIT (SELECT 1), 2', 'SELECT 1 LIMIT EXISTS (SELECT 1)',
              'SELECT 1 LIMIT 1 IN (SELECT 1)', 'DELETE FROM t LIMIT (SELECT 1)', 'UPDATE t SET a = 1 LIMIT 1 OFFSET (SELECT 1)',
              'SELECT SUM(a) OVER (ORDER BY a ROWS BETWEEN (SELECT 1) PRECEDING AND CURRENT ROW) FROM t',
              'SELECT SUM(a) OVER (ORDER BY a ROWS (SELECT 1) PRECEDING) FROM t'] as $sql) {
        try {
            Parser::analizar($sql);
            return "$sql: no lanzó error";
        } catch (JsonSqlDbError $e) {
            if ($e->sqlState !== 'SYNTAX' || !str_contains($e->getMessage(), 'no admite subconsultas')) { return "$sql: " . $e->getMessage(); }
        } catch (Throwable $e) {
            return "$sql: " . get_class($e) . ': ' . $e->getMessage();
        }
    }
    // Y lo de siempre sigue valiendo, con una subconsulta en otra parte de la sentencia
    $a = Parser::analizar('SELECT (SELECT 1) LIMIT 2 * 3 OFFSET ?', [4]);
    return $a['limit'] === 6 && $a['offset'] === 4 && !empty($a['subconsultas']) ?: json_encode($a);
});

echo "\n== Consulta completa multilínea ==\n";
chk('todo junto', function () {
    $sql = "
        SELECT  u.ciudad,
                COUNT(*)            AS clientes,
                ROUND(AVG(p.total), 2) AS media
        FROM    usuarios u
        LEFT JOIN pedidos p ON p.usuario_id = u.id
        WHERE   u.alta BETWEEN '2026-01-01' AND '2026-12-31'
          AND   u.ciudad IN ('Madrid', 'Valencia', 'Torrevieja')
          AND   u.email IS NOT NULL
        GROUP BY u.ciudad
        HAVING  COUNT(*) > 2
        ORDER BY clientes DESC, u.ciudad ASC
        LIMIT   10;
    ";
    $a = Parser::analizar($sql);
    return count($a['cols']) === 3
        && $a['cols'][2]['alias'] === 'media'
        && $a['from'][1]['join'] === 'LEFT'
        && $a['where']['op'] === 'AND'
        && count($a['group']) === 1
        && $a['having']['op'] === '>'
        && count($a['order']) === 2
        && $a['limit'] === 10;
});

echo "\n== Sentencias cortadas ==\n";
chk('una sentencia cortada en cualquier punto da un error de sintaxis, nunca uno de PHP (2.9: SELECT CAST(1 AS y CONVERT(1, leían detrás del final)', function () {
    $sentencias = [
        'SELECT CAST(1 AS DECIMAL(10, 2)), CONVERT(2, SIGNED INTEGER), CONVERT(3 USING utf8mb4)',
        "WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 3) SELECT DISTINCT a.x, COUNT(*) FILTER (WHERE b > 0) AS n FROM t AS a LEFT JOIN u USING (x) WHERE a.y NOT BETWEEN 1 AND 2 AND z IS NOT DISTINCT FROM ? GROUP BY a.x HAVING n > 1 ORDER BY 1 DESC NULLS LAST LIMIT 5 OFFSET 1",
        "SELECT ROW_NUMBER() OVER (PARTITION BY a ORDER BY b ROWS BETWEEN 1 PRECEDING AND UNBOUNDED FOLLOWING EXCLUDE TIES), TRIM(LEADING 'x' FROM a), EXTRACT(YEAR FROM f), f + INTERVAL 1 DAY, j ->> '$.a' COLLATE NOCASE, CASE a WHEN 1 THEN 'x' ELSE 'y' END FROM t WINDOW w AS (ORDER BY a) FOR UPDATE",
        "INSERT INTO t (a, b) VALUES (1, DEFAULT), (?, 'x') ON CONFLICT (a) DO UPDATE SET b = excluded.b WHERE b < 9 RETURNING *",
        'INSERT OR REPLACE INTO t SELECT * FROM u',
        "INSERT INTO t SET a = 1 ON DUPLICATE KEY UPDATE b = VALUES(b)",
        "UPDATE t AS x SET a = a + 1 FROM u WHERE u.id = x.id RETURNING a",
        "DELETE FROM t WHERE a IN (SELECT a FROM u) ORDER BY a LIMIT 1",
        "CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY AUTOINCREMENT, a VARCHAR(10) NOT NULL DEFAULT 'x' COLLATE NOCASE CHECK (a <> ''), b ENUM('s', 'n'), c INT REFERENCES u (id) ON DELETE SET NULL, UNIQUE KEY k (a), FOREIGN KEY (b) REFERENCES v (b)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=5",
        "CREATE TRIGGER IF NOT EXISTS tr BEFORE INSERT ON t FOR EACH ROW WHEN NEW.a > 0 BEGIN IF NEW.a > 5 THEN SET NEW.b = 1; ELSEIF NEW.a > 2 THEN SELECT RAISE(ABORT, 'no'); ELSE INSERT INTO u (a) VALUES (NEW.a); END IF; END",
        "ALTER TABLE t ADD CONSTRAINT c UNIQUE (a, b)", 'SHOW CREATE TABLE t', 'EXPLAIN QUERY PLAN SELECT 1', 'REPAIR TABLES FORGET 3',
        'CREATE UNIQUE INDEX IF NOT EXISTS i ON t (a DESC, b(10))', 'DROP INDEX IF EXISTS i ON t',
    ];
    set_error_handler(static function (int $no, string $texto): bool { throw new ErrorException($texto, 0, $no); });
    try {
        foreach ($sentencias as $s) {
            for ($k = 0; $k <= strlen($s); $k++) {
                try {
                    Parser::analizar(substr($s, 0, $k), [1]);
                } catch (JsonSqlDbError) {
                } catch (Throwable $e) {
                    return substr($s, 0, $k) . ' -> ' . get_class($e) . ': ' . $e->getMessage();
                }
            }
        }
    } finally {
        restore_error_handler();
    }
    return true;
});

malaSintaxis('COLLATE(x), que no es una función, es un error de sintaxis (2.9: leía un segundo argumento que no había)', 'SELECT COLLATE(1) = 1');
malaSintaxis('PLEGAR(x), del árbol interno, tampoco se puede escribir (2.9)', "SELECT PLEGAR('A', 'NOCASE')");

echo "\n== Parámetros ligados ==\n";
chk('el ? se convierte en literal', function () {
    $a = Parser::analizar('SELECT * FROM t WHERE ciudad = ?', ['Madrid']);
    return $a['where']['k'] === 'bin' && $a['where']['d'] === ['k' => 'lit', 'v' => 'Madrid'];
});
chk('varios ? en orden', function () {
    $a = Parser::analizar('SELECT * FROM t WHERE a = ? AND b = ?', [1, 'dos']);
    return $a['where']['i']['d']['v'] === 1 && $a['where']['d']['d']['v'] === 'dos';
});
chk('NULL y booleano como parámetro', function () {
    $a = Parser::analizar('INSERT INTO t (a, b) VALUES (?, ?)', [null, true]);
    return $a['filas'][0][0] === ['k' => 'lit', 'v' => null]
        && $a['filas'][0][1] === ['k' => 'lit', 'v' => 1];
});
chk('un valor con SQL dentro no altera la sentencia', function () {
    $a = Parser::analizar('SELECT * FROM t WHERE n = ?', ["x' OR 1=1; DROP TABLE t; --"]);
    return $a['k'] === 'select'
        && $a['where']['d'] === ['k' => 'lit', 'v' => "x' OR 1=1; DROP TABLE t; --"];
});
chk('? en LIMIT y OFFSET', function () {
    $a = Parser::analizar('SELECT * FROM t LIMIT ? OFFSET ?', [5, 10]);
    return $a['limit'] === 5 && $a['offset'] === 10;
});
chk('sobran parámetros', function () {
    try { Parser::analizar('SELECT * FROM t WHERE a = ?', [1, 2]); }
    catch (JsonSqlDbError $e) { return $e->sqlState === 'SYNTAX' ?: $e->sqlState; }
    return 'no lanzó error';
});
chk('faltan parámetros', function () {
    try { Parser::analizar('SELECT * FROM t WHERE a = ? AND b = ?', [1]); }
    catch (JsonSqlDbError $e) { return $e->sqlState === 'SYNTAX' ?: $e->sqlState; }
    return 'no lanzó error';
});
chk('el ? dentro de un literal no es marcador', function () {
    $a = Parser::analizar("SELECT * FROM t WHERE n = '¿de verdad?'");
    return $a['where']['d']['v'] === '¿de verdad?';
});
chk('parámetro no simple', function () {
    try { Parser::analizar('SELECT * FROM t WHERE a = ?', [['x']]); }
    catch (JsonSqlDbError $e) { return $e->sqlState === 'SYNTAX' ?: $e->sqlState; }
    return 'no lanzó error';
});

echo "\n== Lo que no se soporta se rechaza, no se ignora ==\n";
foreach ([
    // OR IGNORE y OR REPLACE sí existen desde la 2.8; FAIL y ROLLBACK, no
    'INSERT OR FAIL'     => "INSERT OR FAIL INTO t (a) VALUES (1)",
    'INSERT OR ROLLBACK' => "INSERT OR ROLLBACK INTO t (a) VALUES (1)",
    'ON CONFLICT con OR IGNORE' => "INSERT OR IGNORE INTO t (a) VALUES (1) ON CONFLICT DO NOTHING",
    'CREATE TEMP'       => 'CREATE TEMP TABLE t (a INTEGER)',
    'CREATE TEMPORARY'  => 'CREATE TEMPORARY TABLE t (a INTEGER)',
    'WITHOUT ROWID'     => 'CREATE TABLE t (a INTEGER PRIMARY KEY) WITHOUT ROWID',
] as $titulo => $sql) {
    chk("$titulo se rechaza", function () use ($sql) {
        try { Parser::analizar($sql); }
        catch (JsonSqlDbError $e) {
            return $e->sqlState === 'SYNTAX' && str_contains($e->getMessage(), 'no está soportado')
                ?: $e->sqlState . ': ' . $e->getMessage();
        }
        return 'se aceptó en silencio';
    });
}
chk('INSERT OR IGNORE / OR REPLACE, REPLACE INTO, ON CONFLICT y RETURNING se analizan (2.8)', function () {
    $a = Parser::analizar("INSERT OR IGNORE INTO t (a) VALUES (1)");
    $b = Parser::analizar("REPLACE INTO t (a) VALUES (1) RETURNING *");
    $c = Parser::analizar("INSERT INTO t (a, b) VALUES (1, 2) ON CONFLICT (a) DO UPDATE SET b = excluded.b + b WHERE b < 9 RETURNING a, b AS x");
    $d = Parser::analizar("INSERT INTO t (a) SELECT a FROM u ON CONFLICT DO NOTHING");
    $e = Parser::analizar("DELETE FROM t WHERE a = 1 RETURNING a");
    return $a['conflicto']['modo'] === 'nada' && $b['conflicto']['modo'] === 'reemplazar' && $b['returning'][0]['star']
        && $c['conflicto']['modo'] === 'actualizar' && $c['conflicto']['cols'] === ['a'] && count($c['conflicto']['set']) === 1
        && $c['conflicto']['where'] !== null && $c['returning'][1]['alias'] === 'x'
        && $d['select'] !== null && $d['conflicto']['modo'] === 'nada' && $e['returning'] !== null
        ?: 'no se analizó como se esperaba';
});
chk('el INSERT normal sigue funcionando', function () {
    $a = Parser::analizar("INSERT INTO t (a) VALUES (1)");
    return $a['k'] === 'insert' && $a['tabla'] === 't';
});
chk('el CREATE TABLE normal sigue funcionando', function () {
    $a = Parser::analizar('CREATE TABLE t (a INTEGER PRIMARY KEY)');
    return $a['k'] === 'create_table';
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
