# jsonSQLDB — Fase 2: consultas SELECT

Analizador SQL propio (léxico + sintáctico) y ejecutor de `SELECT` sobre los
ficheros JSON. El dialecto **se parece al de SQLite**, del que toma la mayoría de
las decisiones, pero **no es compatible con él**: hay construcciones de SQLite
que aquí no existen, otras que vienen de MySQL, y diferencias de comportamiento
concretas. Todo eso está en las dos tablas de más abajo. No des por hecho que una
consulta que funciona en SQLite funciona aquí, ni al revés.

## 1. Uso

```php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/engine/bootstrap.php';

use JsonSQLDB\Database;

$bd = new Database('mibase');

$filas = $bd->consultar("
    SELECT ciudad, COUNT(*) AS clientes
    FROM   usuarios
    WHERE  alta >= ?
    GROUP  BY ciudad
    HAVING COUNT(*) > 1
    ORDER  BY clientes DESC
", ['2026-01-01']);
```

Devuelve un array de filas asociativas. La SQL puede ser **multilínea** y llevar
comentarios `--` o `/* */`. `Database::consultar()` se ocupa del bloqueo (lectura
compartida) y de registrar la consulta en el log.

El segundo argumento son los valores de los `?`: se insertan en el árbol de la
sentencia ya analizados, nunca en el texto SQL, así que un valor no puede
alterar la consulta. Ver `docs/04-api.md` §1.1.

### UNION

```sql
SELECT nombre FROM clientes
UNION
SELECT razon_social FROM empresas
ORDER BY 1;
```

`UNION` quita las filas repetidas; `UNION ALL` las conserva y es más rápido
porque no compara nada.

- Todas las partes tienen que devolver **el mismo número de columnas**; si no,
  se rechaza diciendo cuántas devuelve cada una.
- Los **nombres de las columnas salen de la primera parte**. Las demás aportan
  sus valores por posición, aunque sus columnas se llamen de otra forma.
- El `ORDER BY` y el `LIMIT` finales son **del conjunto**, no de la última
  parte. En ese punto las tablas de origen ya no existen, así que el `ORDER BY`
  solo admite nombres de columna del resultado o su posición (`ORDER BY 1`).
- Cada parte conserva su propio `WHERE`, `GROUP BY` y `JOIN`.

### De dónde viene cada cosa

El dialecto es **principalmente el de SQLite**, que es el motor ligero al que se
parece este proyecto. Pero unas cuantas construcciones vienen de otros sitios,
porque son las que la gente espera encontrar:

| Construcción | Origen | Nota |
|---|---|---|
| `\|\|` para concatenar | SQLite, estándar | La forma nativa aquí |
| `CONCAT(...)` | MySQL, SQL Server | No existe en SQLite. Si algún argumento es `NULL`, el resultado es `NULL` |
| `GROUP_CONCAT(col, sep)` | SQLite y MySQL | En MySQL se escribe `SEPARATOR sep`; aquí es el segundo argumento, como en SQLite |
| `REGEXP` / `RLIKE` | MySQL | En SQLite `REGEXP` existe pero hay que programarlo aparte. Aquí funciona sin más |
| `LIMIT n, m` | MySQL | También vale `LIMIT n OFFSET m`, de SQLite |
| `IFNULL` | SQLite y MySQL | `COALESCE` es el estándar y también está |
| `CAST(x AS tipo)` | Estándar | Admite los tipos de `CREATE TABLE` y sus alias |
| `FULL JOIN` | Estándar | No existe en SQLite ni en MySQL 5 |
| `AUTOINCREMENT` | SQLite | En MySQL es `AUTO_INCREMENT`, que también se acepta |

Si algo no aparece en esta lista ni en la tabla siguiente, lo más probable es que
se comporte como en SQLite, pero **la referencia es esta documentación**, no la de
SQLite: donde las dos digan cosas distintas, manda esta.

### Cómo escribir consultas que vayan rápidas

No hay índices: toda búsqueda recorre la tabla. Pero el motor tiene dos atajos
que se activan solos, y merece la pena escribir pensando en ellos.

**Comparaciones simples.** Un `WHERE columna = valor` —y lo mismo con `<>`, `<`,
`<=`, `>`, `>=`— se resuelve sin pasar por el evaluador general de expresiones.
Medido sobre 2.000 filas, una consulta por clave primaria pasa de 2,9 ms a
1,7 ms. Envolver la comparación en algo más complicado (`OR`, funciones,
paréntesis con otras condiciones) desactiva el atajo.

**Corte temprano por `LIMIT`.** Si la consulta lleva `LIMIT` y **no** lleva
`ORDER BY`, `GROUP BY`, `DISTINCT` ni agregados, el motor deja de recorrer en
cuanto tiene las filas que le has pedido. Sobre 2.000 filas, un
`WHERE ... LIMIT 10` pasa de 4,0 ms a 1,4 ms. Con `ORDER BY` no se puede cortar,
porque la última fila de la tabla podría ser la primera del resultado.

**Y lo que más diferencia marca de todo: agrupa los `INSERT`.** Cada sentencia de
escritura reescribe el fichero de la tabla, así que dos mil `INSERT` sueltos
reescriben dos mil veces. La misma carga en una sola sentencia con varios
`VALUES` es **180 veces más rápida**, medido:

```sql
-- 2.000 sentencias: unos 5 segundos
INSERT INTO clientes (nombre) VALUES ('Ana');
INSERT INTO clientes (nombre) VALUES ('Luis');
...

-- una sentencia: unos 30 milisegundos
INSERT INTO clientes (nombre) VALUES ('Ana'), ('Luis'), ...;
```

Con parámetros ligados desde PHP:

```php
$vals = []; $params = [];
foreach ($filas as $f) { $vals[] = '(?, ?)'; $params[] = $f['nombre']; $params[] = $f['ciudad']; }
$cli->consultar('INSERT INTO clientes (nombre, ciudad) VALUES ' . implode(',', $vals), $params);
```

Ten en cuenta el límite de la API: `MAX_PARAMS` son 1.000 parámetros por
petición, así que para cargas grandes ve por lotes de unos cientos de filas.

## 1.1. Qué NO se soporta

La regla del proyecto es simple: **si una sentencia se acepta, hace exactamente lo
que promete. Si no se puede hacer, se rechaza con un error claro.** Nunca se
acepta algo y se ignora en silencio, porque eso deja al programador creyendo que
tiene una garantía que no tiene.

Estas construcciones existen en SQLite y aquí **dan error**:

| Construcción | Por qué y qué hacer |
|---|---|
| `INSERT OR IGNORE` / `OR REPLACE` | No hay upsert. Haz un `SELECT` y decide entre `INSERT` y `UPDATE` |
| `CREATE TEMP TABLE` / `TEMPORARY` | No hay tablas temporales. Crea una normal y bórrala con `DROP TABLE` |
| `WITHOUT ROWID` | No hay `rowid`: las filas son objetos JSON y la clave es la que declares |
| `BEGIN` / `COMMIT` / `ROLLBACK` | No hay transacciones de varias sentencias. Cada sentencia es atómica por su cuenta |
| `CHECK (...)` | Usa un trigger `BEFORE` con `RAISE(ABORT, '...')` |
| `CREATE INDEX` | No hay índices. Las búsquedas recorren la tabla |
| Funciones de ventana, CTE (`WITH`), `INTERSECT`, `EXCEPT` | Fuera del alcance del proyecto |
| Subconsultas **correlacionadas** | La subconsulta no ve las columnas de la consulta exterior. Reescríbela con un `JOIN` |
| `ALTER TABLE` sobre la clave primaria | Se gestiona con `ADD`/`DROP PRIMARY KEY`, y el `AUTOINCREMENT` solo al crear |

Y estas diferencias de comportamiento conviene tenerlas presentes:

- `DECIMAL` es coma flotante redondeada, no decimal exacto.
- `ORDER BY` usa la colación configurada, no el orden binario, salvo que lo
  cambies.
- `LIKE` no distingue mayúsculas, pero **sí** distingue acentos.
- La comparación entre un texto y un número **convierte el texto**: `'12abc'`
  vale 12. SQLite no hace esto: aplica la afinidad del tipo declarado de la
  columna, con reglas propias. Es la diferencia de comportamiento más grande
  entre los dos motores, y es deliberada: aquí la regla es una sola y cabe en una
  línea.
- `ROUND` redondea alejándose del cero (`ROUND(2.5)` = 3), igual que SQLite. En
  cambio `ROUND(2.675, 2)` da 2.68 aquí y 2.67 en SQLite: 2.675 no existe exacto
  en coma flotante, y cada motor resuelve el empate de una forma. Si el céntimo
  tiene que cuadrar, guarda céntimos en un `INTEGER`.
- El `%` trabaja con enteros, como en SQLite: si el divisor se queda en cero al
  truncarlo (`5 % 0.4`), el resultado es `NULL`.

## 2. Sintaxis soportada

| Cláusula | Detalle |
|---|---|
| `SELECT` | columnas, expresiones, `*`, `tabla.*`, `DISTINCT`, `AS` (el `AS` es opcional) |
| `FROM` | tablas, alias, subconsultas (`FROM (SELECT ...) t`, alias obligatorio) |
| `JOIN` | `INNER`, `LEFT [OUTER]`, `RIGHT [OUTER]`, `CROSS`, coma; `ON` con cualquier condición |
| `WHERE` | cualquier expresión |
| `GROUP BY` | una o varias expresiones |
| `HAVING` | con o sin `GROUP BY` |
| `ORDER BY` | expresiones o alias de salida, `ASC`/`DESC`, varias claves |
| `LIMIT` | `LIMIT n`, `LIMIT n OFFSET m`, `LIMIT m, n` |

Operadores: `= <> != < <= > >=`, `AND OR NOT`, `IN`, `NOT IN`, `BETWEEN`,
`NOT BETWEEN`, `LIKE ... [ESCAPE c]`, `NOT LIKE`, `IS NULL`, `IS NOT NULL`,
`+ - * / %`, `||` (concatenar).

Identificadores con espacios: `"mi campo"`, `[mi campo]` o `` `mi campo` ``.

## 3. Funciones

| Grupo | Funciones |
|---|---|
| Agregación | `COUNT(*)`, `COUNT(x)`, `COUNT(DISTINCT x)`, `SUM`, `AVG`, `MIN`, `MAX` |
| Texto | `UPPER`, `LOWER`, `LENGTH`, `SUBSTR`/`SUBSTRING`, `TRIM`, `LTRIM`, `RTRIM`, `REPLACE`, `INSTR` |
| Números | `ABS`, `ROUND`, `RANDOM` |
| Fecha | `DATE`, `TIME`, `DATETIME`, `STRFTIME` |
| Nulos | `COALESCE`, `IFNULL`, `NULLIF` |
| Varios | `MIN`/`MAX` con 2 o más argumentos (escalares), `CASE ... WHEN ... THEN ... ELSE ... END` |

- Las funciones de texto trabajan con **caracteres UTF‑8**, no con bytes
  (`LENGTH('María')` = 5). Si el hosting no tiene `mbstring`, el motor usa un
  cálculo propio equivalente, incluidos acentos y eñes en `UPPER`/`LOWER`.
- `DATE`/`TIME`/`DATETIME` sin argumento, o con `'now'`, devuelven la fecha
  actual: `SELECT DATE('now')`.
- `STRFTIME` admite `%Y %m %d %H %M %S %f %j %w %W %s %%`.
- Los agregados ignoran los `NULL`; `SUM` y `AVG` sobre un conjunto vacío
  devuelven `NULL` y `COUNT` devuelve 0.

## 4. Semántica de los valores

- **Comparaciones**: si los dos valores son numéricos se comparan como números;
  si no, como texto. Las fechas se guardan con formato fijo `yyyy-MM-dd...`, así
  que compararlas y ordenarlas funciona sin conversión.
- **NULL**: cualquier comparación con `NULL` da desconocido, así que
  `telefono <> '600111222'` **no** devuelve las filas con teléfono nulo (igual
  que en SQLite y MySQL). Para incluirlas: `telefono IS NULL OR telefono <> '...'`.
- `LIKE` **no distingue mayúsculas de minúsculas**, igual que SQLite, pero **sí
  distingue acentos**, y ahí SQLite hace lo mismo solo porque tampoco los conoce.
- `ORDER BY` sobre texto usa la colación configurada en `config.php`
  (`JSONSQLDB_COLACION`). Por defecto, `'general'`: no distingue mayúsculas ni
  acentos, y coloca cada letra donde le toca, así que `'Óscar'` queda entre las
  O y `'ñu'` entre la n y la o. Con `'binaria'` se ordena byte a byte, como
  SQLite por defecto: primero las mayúsculas, después las minúsculas y al final
  lo acentuado.

  El alfabeto no es igual en todos los idiomas: en sueco `å`, `ä` y `ö` son
  letras propias que van **después de la z**, no variantes de `a` y `o`. Eso se
  corrige con `JSONSQLDB_COLACION_MAPA` en `config.php`, sin tocar el motor:

  ```php
  define('JSONSQLDB_COLACION_MAPA', [
      'å' => 'z{',  'Å' => 'z{',
      'ä' => 'z{{', 'Ä' => 'z{{',
      'ö' => 'z{{{', 'Ö' => 'z{{{',
  ]);
  ```

  La colación **solo afecta a `ORDER BY`**. Las comparaciones (`=`, `<`, `>`),
  las claves únicas, `GROUP BY` y `DISTINCT` siguen siendo exactos: `'Óscar'` y
  `'oscar'` son dos valores distintos y una clave única no los considera
  duplicados.
- División por cero devuelve `NULL` en vez de fallar.
- En `ORDER BY` los `NULL` van primero.

## 5. Rendimiento

Medido en el contenedor de pruebas (PHP 8.3, tabla de 10.000 filas):

| Consulta | Tiempo |
|---|---|
| `WHERE` con filtro numérico sobre 10.000 filas | ~32 ms |
| `GROUP BY` + `SUM` + `ORDER BY` sobre 10.000 filas | ~13 ms |
| `JOIN` de 10.000 × 6 filas con agrupación | ~38 ms |

Decisiones que lo hacen posible:

1. **Filas planas**: durante la ejecución cada fila es un array con claves
   `alias.columna`; leer una columna es un acceso directo al array.
2. **Nombres resueltos una vez**: antes de recorrer las filas se resuelve cada
   referencia a columna (incluidas ambigüedades) y se guarda la clave exacta en
   el árbol de la consulta. No se resuelven nombres por fila.
3. **JOIN por tabla hash**: si el `ON` contiene igualdades (`a.id = b.a_id`), se
   indexa el lado interno y se busca directamente, en vez de comparar todas las
   filas contra todas. Las condiciones que no son igualdades se aplican después,
   solo sobre las candidatas.
4. **Subconsultas ejecutadas una vez**: `IN (SELECT ...)` y las subconsultas
   escalares se ejecutan una sola vez por consulta y su resultado se reutiliza.
5. **Caché de tablas**: las filas ya leídas se reutilizan mientras nadie escriba
   (ver fase 1).

## 6. Lo que todavía no soporta

| No soportado | Alternativa |
|---|---|
| Subconsultas correlacionadas (que usan columnas de la consulta externa) | reescribir con `JOIN` |
| `INTERSECT`, `EXCEPT` | `UNION` sí está |
| Funciones de ventana (`OVER`) | — |
| `GLOB` | Usa `LIKE` o `REGEXP` |

Cualquier cosa no soportada devuelve un error claro con el número de línea, nunca
un resultado silenciosamente incorrecto.

## 7. Ficheros de la fase 2

| Fichero | Responsabilidad |
|---|---|
| `engine/Lexer.php` | SQL → tokens (comentarios, cadenas, identificadores, números) |
| `engine/Parser.php` | tokens → árbol de la consulta |
| `engine/Valor.php` | comparación, verdad lógica y conversión de valores |
| `engine/Collation.php` | orden alfabético de ORDER BY, configurable por idioma |
| `engine/Functions.php` | funciones escalares y de agregación |
| `engine/Evaluator.php` | evaluación de expresiones sobre filas y grupos |
| `engine/Select.php` | orígenes, JOIN, filtros, agrupación, orden y límite |
| `engine/Database.php` | fachada: analiza, bloquea, ejecuta y registra el log |
| `engine/Config.php` | lectura de `config.php` con valores por defecto |
| `engine/Logger.php` | log de consultas |
| `tests/f2_parser.php` | 70 comprobaciones del analizador |
| `tests/f2_select.php` | 138 comprobaciones del ejecutor, con datos reales |

## 8. Pruebas

```
php tests/f1_nucleo.php     → OK: 61
php tests/f2_parser.php     → OK: 70
php tests/f2_select.php     → OK: 138
```
