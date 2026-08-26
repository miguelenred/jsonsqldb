# 05 — jsonSQLDBadmin (panel de administración)

Panel web para administrar jsonSQLDB desde el navegador. PHP puro, sin Composer
y sin nada de fuera: Bootstrap y los iconos están en `jsonsqldbadmin/assets/`.

El panel **nunca toca el motor ni los ficheros de datos**: todo pasa por la API,
firmado con HMAC y con parámetros ligados. Si mañana mueves la API a otro
servidor, el panel sigue valiendo cambiando una constante.

```
navegador  →  jsonsqldbadmin/index.php  →  api/jsonsqldb_api.php  →  engine/  →  data/
```

## 1. Instalación

1. Sube la carpeta `jsonsqldbadmin/` junto al resto del proyecto.
2. Abre `jsonsqldbadmin/config.php` y comprueba que `ADMIN_API_KEY` y
   `ADMIN_HMAC_SECRET` son **los mismos** que en `api/jsonsqldb_api_config.php`.
   Si cambias uno, cambia el otro.
3. Entra en `https://tuservidor/jsonsqldb/jsonsqldbadmin/`. **La primera vez que
   se abre el panel, y solo la primera, pide crear el usuario administrador**:
   eliges nombre y contraseña ahí mismo. No existe ninguna contraseña por
   defecto ni ningún usuario de fábrica, así que no hay nada que cambiar después
   ni riesgo de dejarse el `admin/admin` puesto. La contraseña se guarda con
   bcrypt y necesita al menos 10 caracteres.

   En cuanto ese usuario existe, la pantalla de alta desaparece y el panel pide
   usuario y contraseña como cualquier otro. Si algún día pierdes el acceso,
   borra `jsonsqldbadmin/datos/usuarios.json` y el panel te volverá a pedir el
   alta del administrador.
4. Comprueba que `jsonsqldbadmin/datos/` tiene permiso de escritura: ahí se
   guardan los usuarios y la auditoría.

`ADMIN_API_URL` puede quedarse vacía: el panel deduce la URL de la API a partir
de la suya (`../api/jsonsqldb_api.php`). Rellénala solo si la API está en otro
dominio o en otra ruta.

## 2. Usuarios del panel

Son independientes de las API keys. Se guardan en `datos/usuarios.json` con
bcrypt, y no tienen nada que ver con las claves de la API.

| Rol | Puede |
|---|---|
| `admin` | todo: crear y borrar bases, tablas, columnas, claves, triggers, filas y usuarios |
| `lectura` | ver estructura y datos, y lanzar `SELECT` y `SHOW` desde el editor SQL |

Protecciones incluidas: sesión con caducidad por inactividad
(`ADMIN_SESION_MINUTOS`), bloqueo por IP tras varios fallos
(`ADMIN_LOGIN_MAX_FALLOS`), token CSRF en todos los formularios, cookie
`HttpOnly` + `SameSite=Strict`, y `Secure` automática si entras por HTTPS.

Todo lo que se hace queda en `datos/auditoria-AAAA-MM-DD.json`: usuario, IP,
base, acción y detalle. Se consulta desde la pestaña **Auditoría** y se purga
sola pasados `ADMIN_AUDIT_DIAS` días.

## 3. Qué se puede hacer

**Bases de datos** — listar, crear, borrar y **exportar**. Borrar exige escribir
el nombre exacto de la base. Hay dos formas de exportar:

- **Volcado SQL**: un `.sql` con `CREATE TABLE`, los `INSERT`, las claves únicas
  y foráneas (al final, cuando ya existen todas las tablas) y los triggers. Es
  legible y se puede reejecutar sentencia a sentencia.
- **Copia ZIP**: los ficheros JSON tal cual están en disco, con su estructura de
  carpetas. Se descomprime dentro de `data/` y la base queda restaurada, con sus
  metadatos y revisiones. Es la copia fiel.

Junto al de exportar hay un botón para **restaurar desde una copia ZIP**, con las
mismas condiciones: escribe en el disco del motor, así que necesita que el panel
y la API estén en la misma máquina. Antes de tocar nada aparta lo que hay, y si
la restauración falla a medias lo devuelve a su sitio.

Del ZIP solo se restauran los `.json`, más el `.htaccess` y el `web.config`;
cualquier otra cosa se ignora. Los nombres de tabla se validan, el contenido se
comprueba que sea JSON con la forma que espera el motor, y **si alguna ruta del
ZIP sale de la carpeta de destino se rechaza el fichero entero sin tocar nada**:
es el ataque clásico contra los ZIP.

El botón de la copia ZIP **solo aparece si el panel y la API se sirven desde la
misma máquina**. El panel compara el host de `ADMIN_API_URL` con el suyo; si no
coinciden, oculta el botón y explica por qué, en vez de dejarte copiar los
ficheros de otra instalación que casualmente estuviera en el disco local.

La copia ZIP es lo único del panel que lee los ficheros del motor directamente,
y solo en lectura: un ZIP fiel necesita los `.json` tal cual, y la API devuelve
datos, no ficheros. La ruta sale de `ADMIN_RUTA_DATOS_MOTOR`, o de `../data` si
la dejas vacía. Si el panel está en otra máquina que el motor, la copia ZIP
avisa de que no está disponible y te quedas con el volcado SQL. El ZIP se monta
en un temporal que se borra siempre, también si la descarga se corta a medias.

**Tablas** — listar con número de columnas y de filas, crear, renombrar, vaciar
y borrar. El formulario de creación arranca con seis filas de columna y trae un
botón *Añadir columna* para las que hagan falta, más una X para quitar las que
sobren; las que queden en blanco se ignoran.

**Estructura** — todo lo que admite el motor:

- Columnas: añadir, **editar** y borrar. Al editar se cambia lo mismo que al
  crear: nombre, tipo, longitud, decimales, `NOT NULL`, `UNIQUE` y `DEFAULT`.
  Los datos se convierten, y si no aguantan el cambio no se toca nada y se
  explica por qué. La clave primaria se gestiona desde «Claves», y el
  `AUTOINCREMENT` no se puede cambiar: el propio formulario lo avisa. La casilla de `AUTOINCREMENT` solo se
  puede marcar en una columna `INTEGER` que además sea clave primaria, que es lo
  único que admite el motor.
- Clave primaria simple o compuesta, al crear la tabla y también **después**:
  si una tabla no tiene, el apartado «Claves» ofrece crearla marcando una o
  varias columnas, y quitarla. Con datos nulos o repetidos se rechaza. Una clave
  `AUTOINCREMENT` no se puede quitar: el panel lo indica.
- Claves únicas de una o varias columnas, sobre tablas que ya tienen datos.
- Claves foráneas con `ON DELETE` / `ON UPDATE` (`NO ACTION`, `CASCADE`,
  `RESTRICT`, `SET NULL`, `SET DEFAULT`). El desplegable de columnas de la tabla
  destino se rellena solo.
- Triggers, con un asistente: nombre, desplegable de `BEFORE`/`AFTER`,
  desplegable de `INSERT`/`UPDATE`/`DELETE`, condición opcional (el `WHEN`) y el
  cuerpo. Debajo se ve en directo la sentencia que se va a crear. Dentro del
  cuerpo valen `NEW.columna`, `OLD.columna` y `RAISE(ABORT, 'mensaje')`.
- Borrar cualquier clave única o foránea, y cualquier trigger.

Al añadir una clave única o foránea se validan **los datos que ya hay**: si hay
valores repetidos o filas huérfanas, la operación se rechaza y la estructura no
se toca.

**Datos** — listado paginado, orden por cualquier columna, **filtro** que busca
el texto en todas las columnas a la vez (se combina con el orden, la paginación
y la exportación), e insertar, editar y borrar filas. Las columnas
`AUTOINCREMENT` salen de solo lectura y sin casilla NULL, porque el valor lo
pone la base; las `NOT NULL` se marcan como «obligatorio» y tampoco ofrecen la
casilla NULL, porque no la admiten. Una casilla vacía significa «sin valor»: en las columnas
automáticas, numéricas y de fecha la columna no se manda y el motor aplica el
autoincremento o el `DEFAULT`; en las de texto sí se guarda la cadena vacía.
Para guardar un nulo se marca la casilla NULL. Para editar o borrar una fila suelta la tabla necesita clave
primaria; si no la tiene, el panel lo avisa y te manda al editor SQL.

**Clave de solo lectura** — si configuras `ADMIN_API_KEY_LECTURA` con una clave
de permiso `lectura`, el panel firma con ella cuando quien ha entrado no es
administrador. Así el motor se convierte en la segunda barrera: aunque un fallo
del panel dejara pasar un `DELETE`, la API lo rechazaría. Sin configurarla, todos
los usuarios firman con la clave admin y la única barrera es la comprobación del
propio panel.

**Integridad** — pantalla propia que comprueba que ninguna fila apunte a un valor
inexistente en su tabla destino, y un botón para corregir poniendo a `NULL` lo
que se pueda. Nunca borra filas. Un usuario de solo lectura ve el informe pero no
el botón. Útil cuando alguien ha editado un `.json` a mano o restaurado la copia
de una tabla sin la otra.

**Vistas** — pantalla propia en el menú: listado con su consulta, creación con
nombre y `SELECT`, y borrado. Desde el listado se salta al editor SQL con la
consulta ya escrita. Un usuario de solo lectura las ve pero no puede crearlas ni
borrarlas.

**SQL** — cualquier sentencia, una por ejecución, con el resultado en tabla y el
tiempo que ha tardado. Con rol `lectura` solo se admiten `SELECT` y `SHOW`.

**Exportar** — botones **CSV** e **INSERT** en la pantalla de datos (exporta la
tabla entera, con la ordenación que tengas puesta, no solo la página visible) y
en el resultado del editor SQL (exporta lo que ha devuelto la consulta).

- El CSV lleva BOM UTF-8 para que Excel no rompa los acentos, y usa `;` como
  separador (`ADMIN_CSV_SEPARADOR`, cámbialo a `,` si lo vas a abrir con otras
  herramientas). Los nulos salen como celda vacía.
- El INSERT genera una sentencia por fila, con los nombres entrecomillados, las
  comillas simples duplicadas y los nulos como `NULL`. Se puede volver a
  ejecutar tal cual en el editor SQL de otra base.
- Al exportar el resultado de una consulta, el nombre de la tabla de los INSERT
  se toma del primer `FROM` de la sentencia; si no hay ninguno, se llama
  `consulta`.
- El tope es `ADMIN_EXPORT_MAX` filas (100.000 por defecto) para no agotar la
  memoria de PHP. Si lo superas, el panel te lo dice y acotas con `WHERE` o
  `LIMIT`.

## 4. Crear la primera base de datos

`CREATE DATABASE` y `DROP DATABASE` son sentencias del motor, así que valen por
las tres vías:

- **Panel**: pantalla *Bases* → *Nueva base de datos*. Es lo más cómodo cuando
  no hay ninguna todavía.
- **API**: manda la sentencia con el parámetro `db` **vacío**. Es el único caso
  en el que `db` puede ir vacío, junto con `SHOW DATABASES` y `DROP DATABASE`.
- **PHP**: `JsonSQLDB\Database::crear('mibase')`, o
  `Database::consultarGlobal('CREATE DATABASE mibase')`.

```php
$cli = new JsonSqlDbCliente($url, $apiKey, $secreto, '');   // sin base
$cli->consultar('CREATE DATABASE mibase');
$cli->consultar('CREATE DATABASE IF NOT EXISTS mibase');
$bases = $cli->consultar('SHOW DATABASES');
```

Una API key limitada a bases concretas (`'bases' => ['mibase']`) **no puede**
mandar `db` vacío: para crear bases hace falta una key con `['*']`.

## 4.1. HTTPS con certificado propio

Si la API va por HTTPS con un certificado autofirmado o de una CA interna, cURL
lo rechaza y verás *SSL certificate problem: self-signed certificate*. Hay dos
salidas, en `jsonsqldbadmin/config.php`:

```php
// 1) Recomendada: se sigue verificando, pero contra tu certificado
define('ADMIN_SSL_CA', 'C:/xampp/apache/conf/ssl.crt/server.crt');

// 2) Atajo: aceptar el certificado sin comprobarlo
define('ADMIN_SSL_AUTOFIRMADO', true);
```

Las dos opciones solo se aplican si `ADMIN_API_URL` es `https://`; en HTTP se
ignoran. `ADMIN_SSL_CA` manda: si tiene valor, `ADMIN_SSL_AUTOFIRMADO` se ignora. Si el
fichero no existe o no se puede leer, el panel lo dice claramente en vez de
fallar con un error de red confuso.

Con la opción 1, el **nombre del servidor de `ADMIN_API_URL` tiene que coincidir
con el del certificado**. Si el certificado es para `example.com`, la URL debe ser
`https://example.com/...`, no la IP ni `localhost`. Si no coincide, cURL sigue
protestando (ahora por el nombre, no por la firma) y toca usar la opción 2 o
regenerar el certificado con el nombre correcto.

La opción 2 es razonable en una red interna de confianza, pero deja de
protegerte frente a un intermediario: en una red que no controlas, usa la 1.

Las aplicaciones que consumen la API tienen lo mismo en `cliente_ejemplo.php`:

```php
$cli = new JsonSqlDbCliente($url, $apiKey, $secreto, 'mibase');
$cli->certificado('C:/xampp/apache/conf/ssl.crt/server.crt');
// o bien
$cli->aceptarAutofirmado();
```

## 5. Seguridad

- `config.php`, `lib/`, `vistas/` y `datos/` están bloqueados por `.htaccess` y
  `web.config`. Solo se sirven `index.php` y `assets/`. **En nginx esos ficheros
  no se aplican**: hay que instalar las reglas de la carpeta `nginx/` del
  proyecto, o las carpetas quedan accesibles desde el navegador.
- `ADMIN_IPS_PERMITIDAS` limita quién puede abrir el panel, por IP o por rango
  CIDR. Si solo lo usas tú desde la oficina o por VPN, es la medida más
  efectiva: quien no esté en la lista ni siquiera ve la pantalla de acceso.
- `ADMIN_EXIGIR_HTTPS` rechaza el acceso por HTTP. Por el panel viajan
  contraseñas y datos, así que en producción debería estar a `true`.
- Cabecera `Content-Security-Policy` restringida a `self`: todo lo que carga el
  panel (Bootstrap, iconos, fuentes) es local, así que no necesita permitir
  ningún origen externo.
- Toda salida pasa por `h()` (`htmlspecialchars`), así que un dato con HTML
  dentro se ve como texto y no se ejecuta.
- Los valores de los formularios viajan como **parámetros ligados**: nunca se
  concatenan a la SQL. Un campo con `'); DROP TABLE clientes; --` se guarda como
  texto y no altera nada.
- Los nombres de tabla y columna sí forman parte de la sentencia, así que se
  validan contra `^[A-Za-z_][A-Za-z0-9_]*$` y se citan con comillas dobles.
- Cabeceras `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` y
  `Referrer-Policy: same-origin`.

## 6. Configuración

| Constante | Por defecto | Para qué |
|---|---|---|
| `ADMIN_API_URL` | vacío | URL de la API; vacío = se deduce |
| `ADMIN_API_KEY` | clave admin | API key con la que trabaja el panel |
| `ADMIN_HMAC_SECRET` | secreto | el mismo que la API |
| `ADMIN_SSL_CA` | vacío | ruta del `.crt`/`.pem` del servidor de la API |
| `ADMIN_SSL_AUTOFIRMADO` | `false` | aceptar el certificado sin comprobarlo |
| `ADMIN_TIMEOUT` | `60` | segundos de espera de la llamada |
| `ADMIN_DATA_PATH` | `datos/` | usuarios y auditoría |
| `ADMIN_API_KEY_LECTURA` | vacío | clave de la API para los usuarios de solo lectura |
| `ADMIN_HMAC_SECRET_LECTURA` | vacío | su secreto |
| `ADMIN_IPS_PERMITIDAS` | vacío | IPs o rangos CIDR que pueden abrir el panel |
| `ADMIN_EXIGIR_HTTPS` | `false` | rechazar el acceso por HTTP |
| `ADMIN_CONFIAR_EN_PROXY` | `false` | fiarse de X-Forwarded-For / -Proto |
| `ADMIN_SESION_MINUTOS` | `60` | inactividad máxima |
| `ADMIN_LOGIN_MAX_FALLOS` | `5` | intentos antes de bloquear la IP |
| `ADMIN_LOGIN_BLOQUEO_MIN` | `15` | minutos de bloqueo |
| `ADMIN_BCRYPT_COSTE` | `11` | coste del hash de contraseñas |
| `ADMIN_AUDIT_DIAS` | `90` | días de auditoría (0 = siempre) |
| `ADMIN_FILAS_PAGINA` | `50` | filas por página en el listado de datos |
| `ADMIN_CELDA_MAX` | `120` | caracteres antes de recortar una celda |
| `ADMIN_CSV_SEPARADOR` | `;` | separador del CSV exportado |
| `ADMIN_EXPORT_MAX` | `100000` | tope de filas por exportación |
| `ADMIN_RUTA_DATOS_MOTOR` | vacío | carpeta `data/` del motor, para la copia ZIP |

## 7. Ficheros

| Ruta | Qué es |
|---|---|
| `jsonsqldbadmin/index.php` | único punto de entrada: sesión, router y acciones |
| `jsonsqldbadmin/config.php` | configuración |
| `jsonsqldbadmin/lib/Api.php` | llamadas firmadas a la API |
| `jsonsqldbadmin/lib/Auth.php` | usuarios, sesión, bloqueo por IP y CSRF |
| `jsonsqldbadmin/lib/Audit.php` | auditoría |
| `jsonsqldbadmin/lib/Exportar.php` | exportación a CSV, a sentencias INSERT y a ZIP |
| `jsonsqldbadmin/lib/Store.php` | lectura y escritura de los JSON del panel |
| `jsonsqldbadmin/lib/util.php` | escapado, URLs, mensajes y validaciones |
| `jsonsqldbadmin/lib/acciones.php` | todas las acciones que modifican algo |
| `jsonsqldbadmin/vistas/` | páginas |
| `jsonsqldbadmin/assets/` | Bootstrap 5.3.3 e iconos 1.11.3, en local |
| `jsonsqldbadmin/assets/panel.js` | habilita los campos de columna según el tipo |
| `jsonsqldbadmin/datos/` | `usuarios.json`, `intentos.json`, `auditoria-*.json` |
| `tests/f5_admin.php` | 118 comprobaciones navegando el panel de verdad |

## 8. Pruebas

`tests/f5_admin.php` levanta dos servidores propios de PHP (uno para el panel y
otro para la API, para que no se esperen entre ellos), y navega el panel con
cookies y tokens CSRF reales: instalación, acceso, bases, tablas, columnas,
claves, triggers, datos, editor SQL, permisos del rol de lectura y auditoría.
Usa una carpeta temporal, así que no toca tus datos.

```
php tests/f5_admin.php     → OK: 118
```

Necesita la extensión cURL. En Windows con XAMPP, actívala en `php.ini`
(`extension=curl`).
