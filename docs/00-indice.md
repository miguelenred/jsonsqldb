# jsonSQLDB

Versión **1.4.0** — ver [CHANGELOG.md](../CHANGELOG.md).

Base de datos SQL sobre ficheros JSON, en PHP puro. Sin Composer, sin
extensiones raras y sin servidor de base de datos: se copia la carpeta y
funciona.

Tres piezas, cada una encima de la anterior:

```
jsonsqldbadmin/   panel web          →  habla solo con la API
api/              API HTTP firmada   →  habla solo con el motor
engine/           motor SQL          →  lee y escribe data/
data/             una carpeta por base, un .json por tabla
```

## Documentación

| Documento | Qué cuenta |
|---|---|
| [01-nucleo.md](01-nucleo.md) | almacenamiento, tipos, bloqueos, catálogo, log |
| [02-consultas.md](02-consultas.md) | `SELECT`: sintaxis, funciones, orden alfabético |
| [03-escrituras.md](03-escrituras.md) | `INSERT`/`UPDATE`/`DELETE`, DDL, claves, triggers |
| [04-api.md](04-api.md) | endpoint HTTP, firma HMAC, parámetros ligados, clientes |
| [05-admin.md](05-admin.md) | jsonSQLDBadmin: instalación, usuarios, qué se puede hacer |
| [../nginx/LEEME.md](../nginx/LEEME.md) | **Obligatorio si usas nginx**: bloqueos equivalentes al .htaccess |

## Por dónde empezar

**Si tu servidor es nginx**, lee primero `nginx/LEEME.md`. El proyecto trae
`.htaccess` y `web.config`, que nginx no lee: sin las reglas de esa carpeta,
`data/` queda accesible desde el navegador y cualquiera puede descargarse tus
tablas.

**Instalar**: sube la carpeta al servidor, cambia las API keys y sus secretos
en `api/jsonsqldb_api_config.php`, replica la clave admin y el secreto en
`jsonsqldbadmin/config.php`, y entra en `jsonsqldbadmin/`. La primera pantalla
te pide crear el administrador. Detalle en [05-admin.md §1](05-admin.md).

**Crear la primera base**: desde el panel (*Bases → Nueva base de datos*), con
`CREATE DATABASE mibase` por la API con `db` vacío, o
`JsonSQLDB\Database::crear('mibase')` desde PHP.

**Consultar desde una aplicación**: copia el cliente que te toque
(`api/cliente_ejemplo.php`, `.ps1` para PowerShell, `.py` para Python) y usa
siempre parámetros ligados:

```php
$filas = $cli->consultar('SELECT * FROM clientes WHERE ciudad = ?', ['Torrevieja']);
```

## Lo que conviene saber antes

- **Los valores nunca se concatenan a la SQL.** Se ponen `?` y los valores van
  aparte; el servidor los inserta ya analizados. Ver [04-api.md §1.1](04-api.md).
- **La base va en el parámetro `db`**, no en la SQL. No hay `USE` ni
  `mibase.clientes`, y una consulta no cruza dos bases.
- **`DECIMAL` es coma flotante redondeada**, no decimal exacto. Para importes
  donde el céntimo tenga que cuadrar al milímetro, guarda céntimos en un
  `INTEGER`. Ver [01-nucleo.md](01-nucleo.md).
- **El orden alfabético es configurable** (`JSONSQLDB_COLACION`): por defecto
  ignora mayúsculas y acentos y coloca la `ñ` tras la `n`. Solo afecta a
  `ORDER BY`. Ver [02-consultas.md](02-consultas.md).
- **`ALTER TABLE` no lo puede todo**: añade, modifica y borra columnas, claves
  únicas, foráneas y primarias, pero el `AUTOINCREMENT` solo se pone al crear la
  tabla. Ver [03-escrituras.md](03-escrituras.md).

## Pruebas

Siete ficheros, sin dependencias. Usan carpetas temporales, así que no tocan tus
datos.

```
php tests/f1_nucleo.php       → OK: 52    almacenamiento, tipos, bloqueos
php tests/f2_parser.php       → OK: 60    analizador y parámetros ligados
php tests/f2_select.php       → OK: 77    ejecución de SELECT y orden alfabético
php tests/f3_escrituras.php   → OK: 56    escrituras, DDL, claves y triggers
php tests/f4_api.php          → OK: 50    peticiones reales contra la API
php tests/f5_esquema.php      → OK: 87    SHOW, ALTER y restricciones
php tests/f5_admin.php        → OK: 111    el panel, navegado como un usuario
```

`f5_admin.php` necesita la extensión cURL, y levanta dos servidores propios de
PHP: uno para el panel y otro para la API, porque el servidor integrado atiende
una petición cada vez y el panel llama a la API dentro de la suya.
