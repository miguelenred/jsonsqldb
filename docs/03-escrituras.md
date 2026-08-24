# jsonSQLDB — Fase 3: escrituras, restricciones y triggers

`INSERT`, `UPDATE`, `DELETE` y todo el DDL por SQL, con validación de tipos,
`NOT NULL`, clave primaria, `UNIQUE`, claves foráneas y ejecución de triggers.

## 1. Uso

```php
$bd = new Database('mibase');

$bd->consultar("INSERT INTO clientes (nombre, email) VALUES ('Ana', 'ana@x.es')");
// ['success' => true, 'filas' => 1, 'mensaje' => '1 fila(s) insertada(s)']
```

`SELECT` devuelve la lista de filas; el resto de sentencias devuelve
`['success' => true, 'filas' => n, 'mensaje' => '...']`.

Las escrituras cogen el **bloqueo exclusivo** de la base: se ejecutan de una en
una y los `SELECT` esperan a que terminen. Las lecturas entre sí siguen siendo
simultáneas.

## 2. Sentencias soportadas

### INSERT

```sql
INSERT INTO clientes (nombre, email) VALUES ('Ana', 'ana@x.es');
INSERT INTO clientes (nombre, ciudad) VALUES ('Luis', 'Madrid'), ('María', 'Valencia');
INSERT INTO clientes VALUES (100, 'Marta', 'marta@x.es', 25.5, 'Alicante', '2026-05-01');
INSERT INTO clientes (nombre, saldo, ciudad) VALUES ('Sara', 10 * 3 + 0.5, DEFAULT);
INSERT INTO copia (nombre, ciudad) SELECT nombre, ciudad FROM clientes WHERE ciudad = 'Madrid';
```

`INSERT OR REPLACE` / `OR IGNORE` se aceptan por compatibilidad, pero el
modificador se ignora: se comporta como un `INSERT` normal.

### UPDATE y DELETE

```sql
UPDATE clientes SET saldo = saldo + 100, ciudad = UPPER(ciudad) WHERE id = 3;
DELETE FROM pedidos WHERE fecha < '2026-01-01';
```

Sin `WHERE` afectan a toda la tabla.

### CREATE TABLE

```sql
CREATE TABLE IF NOT EXISTS pedidos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    cliente_id  INTEGER NOT NULL,
    referencia  VARCHAR(20) NOT NULL,
    total       DECIMAL(10,2) NOT NULL DEFAULT 0,
    fecha       DATETIME,
    CONSTRAINT uq_ref UNIQUE (cliente_id, referencia),
    FOREIGN KEY (cliente_id) REFERENCES clientes(id)
        ON DELETE CASCADE ON UPDATE CASCADE
);
```

- Restricciones de columna: `PRIMARY KEY [AUTOINCREMENT]`, `NOT NULL`, `UNIQUE`,
  `DEFAULT valor`, `REFERENCES tabla(col) [ON DELETE ...] [ON UPDATE ...]`.
- Restricciones de tabla: `PRIMARY KEY (cols)` (admite clave compuesta),
  `UNIQUE (cols)`, `FOREIGN KEY (cols) REFERENCES ...`, con `CONSTRAINT nombre`.
- Un solo `AUTOINCREMENT` por tabla y solo sobre `INTEGER`.
- `DEFAULT` admite un valor fijo, no expresiones.
- No soportado: `CHECK`, `COLLATE`, columnas generadas.

### ALTER TABLE y DROP TABLE

```sql
ALTER TABLE clientes ADD COLUMN telefono VARCHAR(20) DEFAULT '-';
ALTER TABLE clientes RENAME COLUMN telefono TO movil;
ALTER TABLE clientes DROP COLUMN movil;
ALTER TABLE copia RENAME TO copia_clientes;
DROP TABLE IF EXISTS copia_clientes;
```

`RENAME TO` actualiza también las claves foráneas de las demás tablas que
apunten a ella. No se puede borrar una columna que forme parte de un `UNIQUE`
compuesto o de una clave foránea (propia o ajena), ni una tabla que esté siendo
referenciada.

### Triggers

```sql
CREATE TRIGGER trg_valida BEFORE INSERT ON pedidos
FOR EACH ROW
WHEN NEW.total < 0
BEGIN
    SELECT RAISE(ABORT, 'El total no puede ser negativo');
END;

CREATE TRIGGER trg_suma AFTER INSERT ON pedidos
FOR EACH ROW
BEGIN
    UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id;
END;

DROP TRIGGER IF EXISTS trg_suma;
```

- Momento: `BEFORE` o `AFTER`. Evento: `INSERT`, `UPDATE` o `DELETE`.
- `FOR EACH ROW` es opcional (siempre se ejecuta por fila).
- `WHEN` filtra qué filas lo disparan.
- El cuerpo admite `INSERT`, `UPDATE`, `DELETE` y `SELECT RAISE(...)`.
- `NEW.columna` está disponible en INSERT y UPDATE; `OLD.columna` en UPDATE y
  DELETE. Se sustituyen por su valor antes de ejecutar la sentencia.
- `RAISE(ABORT, 'mensaje')` cancela la operación completa: no se escribe nada.
- Un trigger ve los cambios que ha hecho la propia sentencia, aunque todavía no
  estén en disco.
- Anidamiento máximo de 8 niveles: una recursión infinita se corta con un error
  y no deja datos escritos.
- No soportado: `INSTEAD OF` y `UPDATE OF columnas`.

## 3. Restricciones

| Restricción | Cuándo se comprueba | Qué pasa si falla |
|---|---|---|
| Tipo de dato | INSERT y UPDATE | error `TYPE` |
| `NOT NULL` | INSERT y UPDATE | error `CONSTRAINT` |
| Clave primaria / `UNIQUE` | INSERT y UPDATE | error `CONSTRAINT` |
| Clave foránea (lado hijo) | INSERT y UPDATE | error `CONSTRAINT` |
| Clave foránea (lado padre) | UPDATE y DELETE del padre | según `ON DELETE` / `ON UPDATE` |

Acciones de clave foránea: `NO ACTION` (por defecto), `RESTRICT`, `CASCADE`,
`SET NULL`, `SET DEFAULT`. Las cascadas se propagan en varios niveles y disparan
los triggers de las tablas hijas.

Si alguna columna de la clave es `NULL`, ni la unicidad ni la clave foránea se
comprueban (mismo criterio que SQL estándar).

## 4. Atomicidad

Una sentencia de escritura **se aplica entera o no se aplica**. Todos los
cambios se acumulan en memoria y se vuelcan a los ficheros JSON al final. Si
falla una restricción en la fila 3 de un `INSERT` de 3 filas, no se escribe
ninguna. Lo mismo con los triggers: si un `RAISE(ABORT)` salta en el nivel más
profundo de una cascada, no queda nada a medias.

Los ficheros se escriben con temporal + `rename`, así que tampoco puede quedar
un `.json` a medio escribir aunque se corte la luz.

## 5. Rendimiento

Medido en el contenedor de pruebas (PHP 8.3):

| Operación | Tiempo |
|---|---|
| `INSERT` de 5.000 filas en una sentencia | ~190 ms |
| `UPDATE` de 500 filas sobre una tabla de 5.000 | ~81 ms |
| `DELETE` de 1.000 filas sobre una tabla de 5.000 | ~114 ms |

Claves: las comprobaciones de unicidad y de clave foránea usan índices hash
construidos una vez por sentencia, y el fichero de datos se escribe una sola vez
al final aunque la sentencia toque miles de filas.

## 6. Ficheros de la fase 3

| Fichero | Responsabilidad |
|---|---|
| `engine/Show.php` | ejecuta las sentencias SHOW |
| `engine/Writer.php` | INSERT, UPDATE, DELETE, DDL, restricciones y triggers |
| `engine/Parser.php` | ampliado con DML, DDL, triggers y `RAISE` |
| `engine/Database.php` | decide bloqueo compartido o exclusivo según la sentencia |
| `tests/f3_escrituras.php` | 56 comprobaciones |

## 7. Pruebas

```
php tests/f1_nucleo.php       → OK: 56
php tests/f2_parser.php       → OK: 60
php tests/f2_select.php       → OK: 77
php tests/f3_escrituras.php   → OK: 56
```

---

## Anexo: estructura desde SQL (SHOW y restricciones)

Añadido para el panel, pero utilizable desde cualquier aplicación.

### Consultar la estructura

Devuelven filas igual que un `SELECT`, y bastan con permiso de **lectura**:

| Sentencia | Devuelve |
|---|---|
| `SHOW DATABASES` | `base` |
| `SHOW TABLES` | `tabla`, `columnas`, `filas`, `creada` |
| `SHOW VIEWS` | `vista`, `sql`, `creada` |
| `SHOW SCHEMA t` (o `SHOW COLUMNS FROM t`) | `columna`, `tipo`, `longitud`, `escala`, `pk`, `auto`, `notnull`, `unico`, `defecto` |
| `SHOW KEYS FROM t` | `tipo` (`PRIMARY`/`UNIQUE`/`FOREIGN`), `nombre`, `columnas`, `tabla_destino`, `columnas_destino`, `on_delete`, `on_update` |
| `SHOW TRIGGERS [FROM t]` | `nombre`, `tabla`, `timing`, `evento`, `cuando`, `sql` |

### Modificar una columna existente

```sql
ALTER TABLE clientes MODIFY COLUMN saldo DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE clientes MODIFY COLUMN cod VARCHAR(20) UNIQUE;
```

`MODIFY COLUMN` (o `CHANGE COLUMN`, o `ALTER COLUMN`) cambia el tipo, la
longitud, los decimales, `NOT NULL`, `UNIQUE` y `DEFAULT` de una columna que ya
existe. Los datos se convierten al tipo nuevo.

Antes de guardar nada se comprueba que los datos actuales aguantan el cambio: si
un valor no se puede convertir, si queda un nulo en una columna que pasa a
`NOT NULL` sin `DEFAULT`, o si hay repetidos en una que pasa a `UNIQUE`, la
sentencia falla y **la tabla se queda exactamente como estaba**.

Lo que no puede cambiar `MODIFY COLUMN`: la clave primaria (se gestiona aparte,
más abajo) y el `AUTOINCREMENT`, que obliga a recrear la tabla. Para renombrar,
`ALTER TABLE t RENAME COLUMN vieja TO nueva`.

### Borrar una columna

```sql
ALTER TABLE clientes DROP COLUMN observaciones;
```

Se borra la columna, su valor en todas las filas y **todo lo que dependía de
ella**: si era la columna `AUTOINCREMENT`, el contador desaparece con ella y no
queda un `autoincrement` apuntando a una columna que ya no existe.

No se borra, y se explica por qué, si la columna:

- forma parte de una clave única o de una clave foránea de la tabla;
- está referenciada por una clave foránea de otra tabla;
- se menciona en un trigger de la tabla (bórralo o reescríbelo antes);
- es parte de una clave primaria compuesta y, al quitarla, la clave que queda
  tendría valores repetidos.

Si te encuentras una tabla de una versión anterior con un `autoincrement`
huérfano en su `.meta.json`, el motor lo ignora al leerla y lo quita la próxima
vez que se guarde la estructura. No hay que tocar el fichero a mano.

### Comprobar la integridad referencial

El motor respeta las claves foráneas en cada `INSERT`, `UPDATE` y `DELETE`, así
que trabajando por SQL no pueden romperse. Pero los datos son ficheros JSON en
disco: alguien puede editarlos a mano, restaurar la copia de una tabla sin la
otra, o mezclar bases.

```sql
CHECK KEYS;                   -- revisa toda la base y solo informa
CHECK KEYS FROM pedidos;      -- solo esa tabla
REPAIR KEYS;                  -- además corrige lo que se puede corregir solo
```

`CHECK KEYS` devuelve una fila por problema, con la tabla, la restricción, el
valor huérfano, a qué apunta y si se puede corregir solo. Si no hay problemas,
devuelve cero filas.

`REPAIR KEYS` pone a `NULL` las claves huérfanas cuya columna lo admita. **Nunca
borra filas**: si la columna es `NOT NULL` o forma parte de la clave primaria, lo
informa y lo deja como está, porque qué hacer con ese dato es una decisión tuya.

Un detalle importante: la comprobación **lee del disco saltándose la caché**. La
caché se invalida por un contador de revisión que solo sube cuando escribe el
motor, así que una edición a mano seguiría oculta si se leyera de la caché.

Desde el panel está en la pestaña **Integridad**, con el listado de problemas y
un botón para corregir. `CHECK` vale con permiso de lectura; `REPAIR` necesita
escritura.

### Vistas

Una vista es un `SELECT` guardado con nombre. Se consulta como si fuera una
tabla, y siempre devuelve los datos del momento porque **no guarda resultados**:
se resuelve en cada consulta.

```sql
CREATE VIEW v_clientes_activos AS
    SELECT id, nombre, saldo FROM clientes WHERE saldo > 0;

CREATE VIEW IF NOT EXISTS v_totales AS
    SELECT c.nombre AS cliente, SUM(p.total) AS gastado
    FROM   clientes c INNER JOIN pedidos p ON p.cliente_id = c.id
    GROUP  BY c.nombre;

SELECT * FROM v_totales WHERE gastado > 100 ORDER BY gastado DESC;

SHOW VIEWS;
DROP VIEW v_totales;
```

Admite todo lo que admite un `SELECT`: `JOIN`, `GROUP BY`, `HAVING`,
subconsultas, y **otras vistas** (hasta 8 niveles; más allá se corta, que es lo
que evita que dos vistas que se llamen entre sí cuelguen el motor).

Reglas:

- Son de **solo lectura**. `INSERT`, `UPDATE` y `DELETE` sobre una vista se
  rechazan con un mensaje explícito.
- Se borran con `DROP VIEW`, no con `DROP TABLE`. Al borrarla no se pierde ningún
  dato: la vista no tiene datos propios.
- Una vista no puede llamarse igual que una tabla, ni al revés.
- Se guardan en `data/<base>/_views.json`, con el texto del `SELECT` tal cual lo
  escribiste. Se vuelve a analizar cada vez que se usa.

Lo que **no** hacen: acelerar nada. Como no hay índices, una vista sobre un
`JOIN` de tres tablas recorre las tres cada vez que la consultas. Sirven para no
repetir SQL y para dar un nombre a una consulta complicada, no para ir más
rápido.

### Clave primaria de una tabla existente

```sql
ALTER TABLE notas ADD PRIMARY KEY (ref, linea);
ALTER TABLE notas DROP PRIMARY KEY;
```

Solo se puede crear si la tabla **no tiene ya** una clave primaria: para
cambiarla, primero se quita y luego se pone la nueva. Las columnas elegidas
pasan a ser `NOT NULL`.

Antes de crearla se comprueban los datos actuales: si alguna de esas columnas
tiene nulos, o si hay combinaciones repetidas, la sentencia falla y no se toca
nada.

`DROP PRIMARY KEY` no vale si la clave es `AUTOINCREMENT`: ahí sí hay que
recrear la tabla. El `AUTOINCREMENT` solo se puede poner al crearla.

### Restricciones sobre una tabla ya creada

```sql
ALTER TABLE pedidos ADD CONSTRAINT uq_pedidos_ref UNIQUE (ref);
ALTER TABLE pedidos ADD UNIQUE (ref);                      -- se autonombra uq_pedidos_ref

ALTER TABLE pedidos ADD CONSTRAINT fk_pedidos_cliente
      FOREIGN KEY (cliente_id) REFERENCES clientes(id)
      ON DELETE CASCADE ON UPDATE NO ACTION;

ALTER TABLE pedidos DROP CONSTRAINT fk_pedidos_cliente;
```

Antes de guardar la restricción se comprueban **los datos que ya hay**: si una
clave única encuentra valores repetidos, o una clave foránea encuentra filas
que apuntan a un padre inexistente, la sentencia falla y la estructura no se
toca. Las filas con `NULL` en las columnas implicadas no cuentan, igual que en
SQLite.

`ALTER TABLE` no puede añadir ni quitar la clave primaria: eso obliga a
recrear la tabla.
