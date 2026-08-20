# jsonSQLDB en nginx

**Léelo antes de publicar nada.** El proyecto trae `.htaccess` (Apache) y
`web.config` (IIS) en cada carpeta que debe estar cerrada al navegador. **nginx
no lee ninguno de los dos.** Si copias el proyecto a un servidor nginx sin
aplicar las reglas de este directorio, cualquiera puede pedir

```
https://tuservidor/jsonsqldb/data/mibase/clientes.json
```

y descargarse la tabla entera, con todos sus datos, sin autenticarse.

Esto no es un fallo de nginx ni del proyecto: es que cada servidor tiene su
propio sistema de configuración y nginx la centraliza en un fichero en lugar de
repartirla por carpetas.

## Qué hay aquí

| Fichero | Qué es |
|---|---|
| `jsonsqldb.conf` | Las reglas de bloqueo, listas para incluir |
| `LEEME.md` | Esto |

## Instalación

**1. Copia el fichero de reglas** a donde nginx guarda sus fragmentos:

```bash
sudo cp nginx/jsonsqldb.conf /etc/nginx/snippets/jsonsqldb.conf
```

**2. Inclúyelo dentro del bloque `server`** de tu sitio, normalmente en
`/etc/nginx/sites-available/tusitio`:

```nginx
server {
    listen 443 ssl;
    server_name tuservidor.com;

    root /var/www/html;
    index index.php index.html;

    ssl_certificate     /etc/letsencrypt/live/tuservidor.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tuservidor.com/privkey.pem;

    # jsonSQLDB
    include snippets/jsonsqldb.conf;

    # ... el resto de tu configuración
}
```

**3. Comprueba y recarga:**

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## Qué tienes que ajustar en `jsonsqldb.conf`

Son tres cosas, todas señaladas con un comentario en el fichero.

**La ruta del proyecto.** Las reglas asumen que el proyecto está en
`/jsonsqldb` dentro del sitio, es decir que se llega a él por
`https://tuservidor/jsonsqldb/`. Si lo pones en otro sitio, cambia `/jsonsqldb`
por tu ruta en **todos** los bloques `location`. Si el proyecto es la raíz del
sitio, quita el prefijo entero y deja las rutas empezando por `/`.

**El socket de PHP-FPM.** El fichero apunta a
`unix:/run/php/php8.3-fpm.sock`. Comprueba cuál tienes:

```bash
ls /run/php/
```

Si usas PHP por TCP en lugar de socket, sustituye esa línea por
`fastcgi_pass 127.0.0.1:9000;`.

**El tiempo de espera.** `fastcgi_read_timeout 300;` son cinco minutos. Si
trabajas con tablas grandes y alguna consulta tarda más, súbelo; ten en cuenta
que `TIME_LIMIT` en `api/jsonsqldb_api_config.php` también limita por su lado, y
manda el más bajo de los dos.

## Qué bloquean las reglas

| Bloqueo | Por qué |
|---|---|
| `data/`, `logs/`, `engine/`, `docs/`, `tests/`, `nginx/` | Datos, registros, código del motor y documentación |
| `jsonsqldbadmin/lib/`, `vistas/`, `datos/` | Internos del panel: usuarios con sus hashes y auditoría |
| `config.php`, `*_config.php`, `*.dist.php` | Claves API y secreto HMAC |
| `*.json`, `*.md`, `*.log`, `*.crt`, `*.pem`, `*.key`… | Las tablas son ficheros `.json`: este es el bloqueo clave |
| Ficheros ocultos (`.git`, `.env`, `.htaccess`) | Lo de siempre |
| Cualquier `.php` que no sea el endpoint o el panel | El resto no son puntos de entrada |

Solo quedan accesibles dos ficheros PHP, que son los únicos que deben serlo:

- `api/jsonsqldb_api.php` — el endpoint de la API
- `jsonsqldbadmin/index.php` — el panel

Y los estáticos de `jsonsqldbadmin/assets/`, que se sirven sin pasar por PHP.

## Comprobación después de instalar

Ninguna de estas cuatro peticiones debe devolver contenido. Todas tienen que
contestar 404:

```bash
curl -o /dev/null -s -w "%{http_code}\n" https://tuservidor/jsonsqldb/data/
curl -o /dev/null -s -w "%{http_code}\n" https://tuservidor/jsonsqldb/api/jsonsqldb_api_config.php
curl -o /dev/null -s -w "%{http_code}\n" https://tuservidor/jsonsqldb/jsonsqldbadmin/datos/usuarios.json
curl -o /dev/null -s -w "%{http_code}\n" https://tuservidor/jsonsqldb/engine/Database.php
```

Y estas dos sí deben funcionar:

```bash
# La API rechaza el GET, pero responde: eso significa que PHP la está ejecutando
curl -s https://tuservidor/jsonsqldb/api/jsonsqldb_api.php
# → {"error":"Método no permitido"}

# El panel devuelve HTML
curl -o /dev/null -s -w "%{http_code}\n" https://tuservidor/jsonsqldb/jsonsqldbadmin/
# → 200
```

Si la primera prueba te devuelve el JSON de una tabla en vez de un 404, las
reglas no se están aplicando: revisa que el `include` esté **dentro** del bloque
`server` correcto y que la ruta del prefijo coincida.

## Permisos de escritura

Con nginx, PHP suele correr como `www-data`. Estas tres carpetas necesitan
escritura:

```bash
sudo chown -R www-data:www-data data logs jsonsqldbadmin/datos
sudo chmod -R 750 data logs jsonsqldbadmin/datos
```

El resto del proyecto puede quedarse en solo lectura (`755` para carpetas,
`644` para ficheros).

## Recomendación adicional

Si el panel solo lo vas a usar tú, lo más efectivo es no exponerlo a Internet.
Dos formas, de más a menos restrictiva:

```nginx
# Solo desde la red local o la VPN
location ~ ^/jsonsqldb/jsonsqldbadmin/ {
    allow 192.168.1.0/24;
    allow 10.8.0.0/24;
    deny  all;
    # ... y a continuación el bloque fastcgi de jsonsqldb.conf
}
```

O usa `ADMIN_IPS_PERMITIDAS` en `jsonsqldbadmin/config.php`, que hace lo mismo
desde PHP y viaja con el proyecto. Las dos cosas a la vez no sobran.
