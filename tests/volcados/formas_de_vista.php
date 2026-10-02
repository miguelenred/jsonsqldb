<?php
declare(strict_types=1);

/**
 * Para tests/f16_vistas_triggers.php y tests/f17_rutinas_importadas.php: dos
 * tablas con datos de todo tipo y 25 formas de vista (todo lo que admite un
 * SELECT del motor). f16 las exporta a MySQL y PostgreSQL y compara; f17 las
 * hace ir y volver por mysqldump y pg_dump.
 *
 * Devuelve [sentencias para crear las tablas y los datos, vistas].
 */
return [
    [
        "CREATE TABLE a (id INTEGER PRIMARY KEY, n VARCHAR(20), x DOUBLE, d DATETIME, k INTEGER)",
        "CREATE TABLE b (id INTEGER PRIMARY KEY, a_id INTEGER, t VARCHAR(30), v DECIMAL(10,2))",
        "INSERT INTO a VALUES (1,'Ana',1.5,'2026-01-02 03:04:05',3),(2,'bob',-2.25,NULL,NULL),(3,'Éva',0,'2025-12-31 23:59:59',7),(4,'o''hara',10,'2026-06-15 12:00:00',3),(5,NULL,NULL,NULL,0)",
        "INSERT INTO b VALUES (1,1,'x',10.5),(2,1,'y',NULL),(3,2,'x',-3),(4,9,'z',1),(5,3,'50%',2.25)",
    ],
    [
        "SELECT id, n FROM a WHERE n IS NOT NULL ORDER BY n DESC, id LIMIT 3 OFFSET 1",
        "SELECT a.n, b.t FROM a LEFT JOIN b ON b.a_id = a.id WHERE b.t IS NULL OR b.v > 0",
        "SELECT a.id, COUNT(b.id) AS c, SUM(b.v) AS s, MIN(b.t) AS mi, MAX(b.v) AS ma, COUNT(DISTINCT b.t) AS dt FROM a LEFT JOIN b ON b.a_id = a.id GROUP BY a.id HAVING COUNT(b.id) >= 0",
        "SELECT id FROM a WHERE id IN (SELECT a_id FROM b WHERE v IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM b WHERE b.a_id = a.id AND b.t = 'z')",
        "SELECT id, CASE WHEN x > 1 THEN 'g' WHEN x < 0 THEN 'n' ELSE 'z' END AS c, CASE k WHEN 3 THEN 'tres' WHEN 7 THEN 'siete' END AS c2 FROM a",
        "SELECT id, x BETWEEN 0 AND 2 AS en, x NOT BETWEEN 0 AND 2 AS fuera, n LIKE '%a%' AS l, n NOT LIKE 'A%' AS nl FROM a",
        "SELECT t FROM b WHERE t LIKE '50!%' ESCAPE '!'",
        "SELECT id, COALESCE(n, 'nadie', 'x') AS c, IFNULL(x, -1) AS i, NULLIF(k, 3) AS ni, MAX(id, k) AS mx, MIN(id, k) AS mn FROM a",
        "SELECT id, UPPER(n) AS u, LOWER(n) AS lo, LENGTH(n) AS len, SUBSTR(n, 2) AS s1, SUBSTR(n, -2, 1) AS s2, TRIM('  ' || n || '  ') AS tr, REPLACE(n, 'a', '@') AS r, INSTR(n, 'a') AS ins FROM a",
        "SELECT id, n || '-' || id AS c1, CONCAT(n, '/', k) AS c2 FROM a",
        "SELECT id, x * 2 + 1 AS e1, x / 4 AS e2, k % 2 AS e3, -x AS e4, ABS(x) AS e5, ROUND(x, 1) AS e6, ROUND(x) AS e7, CAST(x AS INTEGER) AS e8, CAST(k AS TEXT) AS e9 FROM a",
        "SELECT id, DATE(d) AS d1, TIME(d) AS d2, DATETIME(d, '+1 day', '-2 hours') AS d3, STRFTIME('%Y/%m/%d %H:%M:%S', d) AS d4, DATE(d, 'start of year') AS d5 FROM a WHERE d IS NOT NULL",
        "SELECT n FROM a WHERE id < 3 UNION ALL SELECT t FROM b WHERE id > 3 ORDER BY 1 LIMIT 3",
        "SELECT n FROM a INTERSECT SELECT n FROM a WHERE id > 1",
        "SELECT n FROM a EXCEPT SELECT n FROM a WHERE id > 2",
        "WITH x AS (SELECT a_id, SUM(v) AS s FROM b GROUP BY a_id), y AS (SELECT a_id FROM x WHERE s > 0) SELECT a.n FROM a JOIN y ON y.a_id = a.id",
        "SELECT q.n, q.c FROM (SELECT n, COUNT(*) AS c FROM a GROUP BY n) AS q WHERE q.c > 0",
        "SELECT a1.id, a2.id AS otro FROM a a1 JOIN a a2 ON a2.k = a1.k AND a2.id > a1.id",
        "SELECT id, (SELECT COUNT(*) FROM b WHERE b.a_id = a.id) AS nb, (SELECT MAX(v) FROM b) AS mv FROM a",
        "SELECT k, GROUP_CONCAT(n, '|' ORDER BY n DESC) AS ns FROM a WHERE n IS NOT NULL GROUP BY k",
        "SELECT DISTINCT k FROM a WHERE k IS NOT NULL",
        "SELECT a.id, b.id AS bid FROM a CROSS JOIN b WHERE a.id = 1 AND b.id < 3",
        "SELECT id, n FROM a WHERE n REGEXP '^[A-Z]'",
        "SELECT 'texto con ''comilla''' AS t, 1 AS uno, NULL AS nada, 2.5 AS dec FROM a WHERE id = 1",
        "SELECT b.t, AVG(b.v) AS media FROM b GROUP BY b.t ORDER BY media DESC",
]
];
