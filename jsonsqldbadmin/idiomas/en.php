<?php
declare(strict_types=1);

/**
 * Textos del panel en inglés. La clave es el texto en español, tal como va
 * en t() en el código; el valor, su traducción. tests/f15_idiomas.php
 * comprueba que cada texto del panel está aquí y que aquí no sobra ninguno.
 *
 * https://miguelenred.es/jsonsqldb
 */
return [
    '\'{campo}\' tiene que ser un número entre {min} y {max}.'
        => '\'{campo}\' must be a number between {min} and {max}.',
    '\'{fichero}\' no es un JSON válido. No se ha tocado nada.'
        => '\'{fichero}\' is not valid JSON. Nothing has been touched.',
    '\'{fichero}\' no tiene la forma de un fichero de datos del motor. No se ha tocado nada.'
        => '\'{fichero}\' does not have the shape of an engine data file. Nothing has been touched.',
    '(API por HTTPS con CA propia)'
        => '(API over HTTPS with your own CA)',
    '(automático)'
        => '(automatic)',
    '(directa)'
        => '(direct)',
    '(hace falta 8.0 o posterior)'
        => '(8.0 or later is required)',
    '(opcional)'
        => '(optional)',
    '({1} ms)'
        => '({1} ms)',
    '<b>Configuración</b> {1}'
        => '<b>Configuration</b> {1}',
    '<b>Usuarios del panel</b> {1}'
        => '<b>Panel users</b> {1}',
    '<br> {1} La <strong>copia en ZIP no está disponible</strong>: lee los ficheros del disco y la API está en otra máquina (<code>{2}</code>, y el panel se sirve desde <code>{3}</code>). El volcado en SQL va por la API y funciona igual entre máquinas distintas.'
        => '<br> {1} The <strong>ZIP copy is not available</strong>: it reads the files from disk and the API is on another machine (<code>{2}</code>, while the panel is served from <code>{3}</code>). The SQL dump goes through the API and works the same between different machines.',
    '<br><strong>{1}</strong> · PHP {2}'
        => '<br><strong>{1}</strong> · PHP {2}',
    '<strong class="text-danger">no responde</strong>'
        => '<strong class="text-danger">not responding</strong>',
    '<strong class="text-success">{1} {2} en {3} ms</strong>'
        => '<strong class="text-success">{1} {2} in {3} ms</strong>',
    '<strong>API</strong> si el motor está en otra máquina, o si prefieres que el panel pase por la misma puerta firmada que tus aplicaciones.'
        => '<strong>API</strong> if the engine is on another machine, or if you prefer the panel to go through the same signed door as your applications.',
    '<strong>Directa</strong> si el panel y los datos están en el mismo servidor: no hay claves que configurar y cada consulta se ahorra una petición HTTP.'
        => '<strong>Direct</strong> if the panel and the data are on the same server: there are no keys to configure and every query saves an HTTP request.',
    '<strong>La carpeta de datos se puede descargar desde fuera.</strong> {1}.'
        => '<strong>The data folder can be downloaded from outside.</strong> {1}.',
    '<strong>Qué comprueba:</strong> que toda fila con una clave foránea apunte a una fila que existe en su tabla destino. Las filas con <code>NULL</code> en esas columnas no cuentan, igual que en la comprobación normal del motor. <br> <strong>Lee del disco</strong>, saltándose la caché a propósito: la caché solo se invalida cuando escribe el motor, así que una edición a mano seguiría oculta si no fuera así. <br> Desde SQL: <code>CHECK KEYS</code> para comprobar y <code>REPAIR KEYS</code> para corregir, con <code>FROM tabla</code> opcional para limitarlo a una tabla.'
        => '<strong>What it checks:</strong> that every row with a foreign key points to a row that exists in its target table. Rows with <code>NULL</code> in those columns do not count, as in the engine\'s normal check. <br> <strong>It reads from disk</strong>, skipping the cache on purpose: the cache is only invalidated when the engine writes, so a manual edit would otherwise stay hidden. <br> From SQL: <code>CHECK KEYS</code> to check and <code>REPAIR KEYS</code> to fix, with an optional <code>FROM tabla</code> to limit it to one table.',
    '<strong>Se recomienda PHP 8.1 o posterior: PHP {1} no puede forzar los datos al disco.</strong> Al escribir, el sistema operativo guarda los datos en memoria y los pasa al disco un poco después. La función <code>fsync()</code>, que obliga a grabarlos en el momento, existe en PHP solo desde la 8.1, y en 8.0 no hay forma fiable de hacer lo mismo sin extensiones. Por eso, con 8.0, jsonSQLDB no pierde nada si el proceso muere a mitad de una escritura, pero ante un <strong>corte de luz o un fallo del sistema operativo</strong> se pueden perder las escrituras que aún no estaban en disco —en Linux con la configuración por defecto, aproximadamente los últimos 30 segundos—, aunque se hubieran dado por hechas. Las tablas no se dañan en ext4 con sus opciones por defecto. Si tu hosting ofrece PHP 8.1 o posterior, cámbialo; la explicación completa está en el README del proyecto.'
        => '<strong>PHP 8.1 or later is recommended: PHP {1} cannot force the data to disk.</strong> When writing, the operating system keeps the data in memory and moves it to disk a little later. The <code>fsync()</code> function, which forces it to be written at once, exists in PHP only since 8.1, and in 8.0 there is no reliable way to do the same without extensions. So, with 8.0, jsonSQLDB loses nothing if the process dies in the middle of a write, but after a <strong>power cut or an operating system failure</strong> the writes that were not on disk yet can be lost —on Linux with the default settings, roughly the last 30 seconds—, even if they had been reported as done. Tables are not damaged on ext4 with its default options. If your hosting offers PHP 8.1 or later, switch to it; the full explanation is in the project\'s README.',
    '<strong>Se sustituye todo el contenido actual de la base.</strong> Antes de escribir nada se aparta una copia de lo que hay, y si la restauración falla a medias se deja como estaba.'
        => '<strong>All the current content of the database is replaced.</strong> Before writing anything, a copy of what is there is set aside, and if the restore fails halfway it is left as it was.',
    '<strong>Sin conexión con el motor.</strong> {1}'
        => '<strong>No connection with the engine.</strong> {1}',
    '<strong>{1}</strong> fila(s){2} · página {3} de {4}'
        => '<strong>{1}</strong> row(s){2} · page {3} of {4}',
    'API key de administración'
        => 'Administration API key',
    'API key de solo lectura'
        => 'Read-only API key',
    'AUTOINCREMENT necesita que la columna sea clave primaria simple.'
        => 'AUTOINCREMENT needs the column to be a simple primary key.',
    'AUTOINCREMENT solo vale en columnas INTEGER (\'{nombre}\').'
        => 'AUTOINCREMENT only works on INTEGER columns (\'{nombre}\').',
    'Acceso no permitido desde esta IP.'
        => 'Access is not allowed from this IP.',
    'Acciones'
        => 'Actions',
    'Acción'
        => 'Action',
    'Acción desconocida: \'{accion}\''
        => 'Unknown action: \'{accion}\'',
    'Aceptar un certificado autofirmado sin comprobarlo (solo pruebas)'
        => 'Accept a self-signed certificate without checking it (testing only)',
    'Actívalo solo si es así: si no, cualquiera podría falsear su IP.'
        => 'Turn it on only if that is the case: otherwise anyone could fake their IP.',
    'Administrador'
        => 'Administrator',
    'Administrador \'{nombre}\' creado. Ya puedes entrar.'
        => 'Administrator \'{nombre}\' created. You can sign in now.',
    'Antes (BEFORE)'
        => 'Before (BEFORE)',
    'Apunta a'
        => 'Points to',
    'Asistente de configuración inicial'
        => 'Initial setup wizard',
    'Auditoría'
        => 'Audit log',
    'Auto'
        => 'Auto',
    'Añadir'
        => 'Add',
    'Añadir columna'
        => 'Add column',
    'Aún no hay bases de datos.'
        => 'There are no databases yet.',
    'BLOB y BINARY importados como TEXT: solo llegan los que son texto UTF-8'
        => 'BLOB and BINARY imported as TEXT: only those that are UTF-8 text arrive',
    'Base'
        => 'Database',
    'Base \'{nombre}\' restaurada. {resumen}'
        => 'Database \'{nombre}\' restored. {resumen}',
    'Base de datos \'{nombre}\' borrada.'
        => 'Database \'{nombre}\' deleted.',
    'Base de datos \'{nombre}\' creada.'
        => 'Database \'{nombre}\' created.',
    'Bases de datos'
        => 'Databases',
    'Bases encontradas'
        => 'Databases found',
    'Bases y tablas'
        => 'Databases and tables',
    'Borrar'
        => 'Delete',
    'Borrar (DELETE)'
        => 'Delete (DELETE)',
    'Borrar la tabla «{1}»'
        => 'Delete the table «{1}»',
    'Borrar la vista «{1}»'
        => 'Delete the view «{1}»',
    'Borrar «{1}»'
        => 'Delete «{1}»',
    'Buscar'
        => 'Search',
    'COLLATE y CHARACTER SET quitados de las columnas'
        => 'COLLATE and CHARACTER SET removed from the columns',
    'CREATE DATABASE saltado: se importa en la base elegida'
        => 'CREATE DATABASE skipped: the import goes into the chosen database',
    'Cada punto duplica el tiempo de comprobar una contraseña.'
        => 'Each point doubles the time it takes to check a password.',
    'Cambiar'
        => 'Change',
    'Cambiar mi contraseña'
        => 'Change my password',
    'Cambiar tema'
        => 'Change theme',
    'Cancelar'
        => 'Cancel',
    'Caracteres visibles por celda'
        => 'Visible characters per cell',
    'Caracteres, solo para TEXT'
        => 'Characters, only for TEXT',
    'Cargar'
        => 'Load',
    'Cargar un CSV en una tabla'
        => 'Load a CSV into a table',
    'Carpeta de datos'
        => 'Data folder',
    'Carpeta de datos: {estado}'
        => 'Data folder: {estado}',
    'Carpeta de jsonSQLDB'
        => 'jsonSQLDB folder',
    'Carpeta de usuarios y auditoría del panel con permiso de escritura'
        => 'Panel users and audit folder is writable',
    'Carpeta de usuarios y auditoría del panel sin permiso de escritura: {ruta}'
        => 'Panel users and audit folder is not writable: {ruta}',
    'Cerrar'
        => 'Close',
    'Cerrar sesión'
        => 'Sign out',
    'Certificado de la CA'
        => 'CA certificate',
    'Clave foránea añadida.'
        => 'Foreign key added.',
    'Clave primaria creada.'
        => 'Primary key created.',
    'Clave primaria de «{1}»'
        => 'Primary key of «{1}»',
    'Clave primaria eliminada.'
        => 'Primary key removed.',
    'Clave única añadida.'
        => 'Unique key added.',
    'Claves'
        => 'Keys',
    'Claves foráneas de {1} que apuntan a filas que no existen'
        => 'Foreign keys in {1} that point to rows that do not exist',
    'Claves foráneas que no pueden ir en su CREATE TABLE: forman un ciclo entre tablas, o una fila se apunta a sí misma.'
        => 'Foreign keys that cannot go in their CREATE TABLE: they form a cycle between tables, or a row points to itself.',
    'Columna'
        => 'Column',
    'Columna \'{col}\' borrada.'
        => 'Column \'{col}\' deleted.',
    'Columna añadida.'
        => 'Column added.',
    'Columna guardada.'
        => 'Column saved.',
    'Columnas'
        => 'Columns',
    'Columnas a las que apunta'
        => 'Columns it points to',
    'Columnas de esta tabla'
        => 'Columns of this table',
    'Columnas de lista (int[], text[]…) importadas como TEXT'
        => 'List columns (int[], text[]…) imported as TEXT',
    'Columnas generadas: se importan como columnas normales, con los valores del volcado'
        => 'Generated columns: imported as normal columns, with the values in the dump',
    'Columnas sin tipo importadas como TEXT: un número que haya en ellas queda como texto'
        => 'Untyped columns imported as TEXT: a number in them is stored as text',
    'Coma'
        => 'Comma',
    'Comprobaciones del servidor'
        => 'Server checks',
    'Con ella firman los usuarios de solo lectura y el motor rechaza cualquier escritura suya. Tiene que poder ver todas las bases.'
        => 'Read-only users sign with it, and the engine rejects any write from them. It must be able to see every database.',
    'Con las dos, el motor aplica los permisos de cada usuario del panel: uno de solo lectura no puede escribir aunque el panel se equivocara.'
        => 'With both, the engine enforces each panel user\'s permissions: a read-only one cannot write even if the panel made a mistake.',
    'Con permiso de lectura solo se pueden lanzar SELECT y SHOW.'
        => 'With read permission only SELECT and SHOW can be run.',
    'Condición'
        => 'Condition',
    'Condición (opcional)'
        => 'Condition (optional)',
    'Conexión'
        => 'Connection',
    'Conexión HTTPS'
        => 'HTTPS connection',
    'Conexión con el motor'
        => 'Connection with the engine',
    'Conexión directa'
        => 'Direct connection',
    'Conexión no válida.'
        => 'Invalid connection.',
    'Configuración'
        => 'Configuration',
    'Configuración de la API'
        => 'API configuration',
    'Configuración guardada ({n} cambio(s)). Se aplica desde esta página.'
        => 'Configuration saved ({n} change(s)). It applies from this page on.',
    'Consejos rápidos'
        => 'Quick tips',
    'Consola SQL'
        => 'SQL console',
    'Consulta'
        => 'Query',
    'Consultar'
        => 'Run query',
    'Consultas guardadas con nombre en {1}: se usan como tablas y siempre dan los datos del momento'
        => 'Named saved queries in {1}: they are used like tables and always return current data',
    'Contraseña'
        => 'Password',
    'Contraseña cambiada.'
        => 'Password changed.',
    'Contraseña de «{1}»'
        => 'Password of «{1}»',
    'Contraseñas bcrypt, bloqueo por intentos y auditoría'
        => 'bcrypt passwords, lockout after failed attempts and audit log',
    'Copia ZIP'
        => 'ZIP copy',
    'Corregir automáticamente'
        => 'Fix automatically',
    'Coste de bcrypt'
        => 'bcrypt cost',
    'Crea antes la tabla.'
        => 'Create the table first.',
    'Crea el administrador'
        => 'Create the administrator',
    'Creada'
        => 'Created',
    'Creado'
        => 'Created',
    'Crear'
        => 'Create',
    'Crear administrador'
        => 'Create administrator',
    'Crear la primera'
        => 'Create the first one',
    'Crear tabla'
        => 'Create table',
    'Crear usuario'
        => 'Create user',
    'Cuándo'
        => 'When',
    'Cómo se conecta el panel y cómo se comporta. Se guarda en <code>{1}</code>'
        => 'How the panel connects and how it behaves. Saved in <code>{1}</code>',
    'DATETIME usa el formato <code>AAAA-MM-DD</code>, con la hora opcional.'
        => 'DATETIME uses the format <code>YYYY-MM-DD</code>, with an optional time.',
    'DESCARGABLE: {url} responde. Cualquiera puede bajarse las bases. En nginx, añade las reglas de nginx/jsonsqldb.conf; en Apache, comprueba que se leen los .htaccess (AllowOverride)'
        => 'DOWNLOADABLE: {url} answers. Anyone can download the databases. On nginx, add the rules from nginx/jsonsqldb.conf; on Apache, check that the .htaccess files are read (AllowOverride)',
    'Datos'
        => 'Data',
    'Datos binarios (BLOB, bytea, image) que no son texto: se guardan como su hexadecimal, \\x…'
        => 'Binary data (BLOB, bytea, image) that is not text: kept as its hexadecimal, \\x…',
    'Datos y exportación'
        => 'Data and export',
    'Datos, estructura, claves, triggers, vistas y consola SQL'
        => 'Data, structure, keys, triggers, views and SQL console',
    'De 3 a 32 caracteres: letras, números y . _ - @'
        => '3 to 32 characters: letters, numbers and . _ - @',
    'De qué'
        => 'What',
    'Decimales'
        => 'Decimals',
    'Deja en blanco las filas de columna que no vayas a usar.'
        => 'Leave blank the column rows you will not use.',
    'Demasiados intentos fallidos. Prueba dentro de {espera} minuto(s).'
        => 'Too many failed attempts. Try again in {espera} minute(s).',
    'Descargar el volcado'
        => 'Download the dump',
    'Después (AFTER)'
        => 'After (AFTER)',
    'Detalle'
        => 'Detail',
    'Detectar el formato'
        => 'Detect the format',
    'Directa'
        => 'Direct',
    'Directa al motor'
        => 'Direct to the engine',
    'Déjalo desmarcado en un servidor: por el panel viajan contraseñas y datos. Se puede cambiar después en <code>ADMIN_EXIGIR_HTTPS</code>.'
        => 'Leave it unchecked on a server: passwords and data travel through the panel. It can be changed later in <code>ADMIN_EXIGIR_HTTPS</code>.',
    'Déjalo vacío para quitar el valor por defecto.'
        => 'Leave it empty to remove the default value.',
    'Día'
        => 'Day',
    'Días que se guarda la auditoría'
        => 'Days the audit log is kept',
    'Dónde se guardan los usuarios y el nombre de la cookie de sesión solo se cambian a mano en <code>config.php</code>. Para volver al asistente, borra ese fichero.'
        => 'Where the users are stored and the session cookie name can only be changed by hand in <code>config.php</code>. To go back to the wizard, delete that file.',
    'ENUM y SET importados como TEXT: aquí no se limitan los valores'
        => 'ENUM and SET imported as TEXT: the values are not restricted here',
    'Editar fila'
        => 'Edit row',
    'Editar «{1}»'
        => 'Edit «{1}»',
    'Ejecutar'
        => 'Run',
    'El <code>AUTOINCREMENT</code> no se puede añadir aquí: solo se puede poner al crear la tabla.'
        => '<code>AUTOINCREMENT</code> cannot be added here: it can only be set when creating the table.',
    'El ZIP contiene un fichero con un nombre que no es de tabla: \'{fichero}\'. No se ha tocado nada.'
        => 'The ZIP contains a file whose name is not a table\'s: \'{fichero}\'. Nothing has been touched.',
    'El ZIP contiene una ruta que sale de la carpeta de destino: \'{ruta}\'. No se ha tocado nada.'
        => 'The ZIP contains a path that leaves the target folder: \'{ruta}\'. Nothing has been touched.',
    'El ZIP no contiene ninguna tabla. ¿Seguro que es una copia generada por este panel? Dentro debe haber una carpeta con los ficheros .json.'
        => 'The ZIP contains no table. Are you sure it is a copy made by this panel? It must contain a folder with the .json files.',
    'El ZIP tiene demasiados ficheros ({n}). Una copia de este panel no debería pasar de unos pocos cientos.'
        => 'The ZIP has too many files ({n}). A copy from this panel should not go beyond a few hundred.',
    'El autoincremento no se puede quitar desde aquí: hay que recrear la tabla.'
        => 'Autoincrement cannot be removed from here: the table has to be recreated.',
    'El evento del trigger debe ser INSERT, UPDATE o DELETE.'
        => 'The trigger event must be INSERT, UPDATE or DELETE.',
    'El fichero acaba con una cadena o un comentario sin cerrar.'
        => 'The file ends with an unclosed string or comment.',
    'El fichero intenta borrar una base de datos; eso no se importa.'
        => 'The file tries to delete a database; that is not imported.',
    'El fichero intenta crear o borrar una base de datos; eso no se importa.'
        => 'The file tries to create or delete a database; that is not imported.',
    'El fichero no es un ZIP válido o está dañado.'
        => 'The file is not a valid ZIP or it is damaged.',
    'El fichero recibido no es una subida válida.'
        => 'The file received is not a valid upload.',
    'El motor no responde: {error}'
        => 'The engine is not responding: {error}',
    'El orden importa: un índice sobre (a, b) sirve para buscar por a, o por a y b, pero no para buscar solo por b. Acelera las igualdades y los IN; no los rangos, ni LIKE, ni ORDER BY. Y hace algo más lenta cada escritura de la tabla.'
        => 'Order matters: an index on (a, b) helps to search by a, or by a and b, but not by b alone. It speeds up equalities and IN; not ranges, LIKE or ORDER BY. And it makes every write to the table a little slower.',
    'El panel carga el motor y le habla sin HTTP'
        => 'The panel loads the engine and talks to it without HTTP',
    'El panel carga el motor y le habla sin HTTP. Para cuando el panel y los datos están en el mismo servidor.'
        => 'The panel loads the engine and talks to it without HTTP. For when the panel and the data are on the same server.',
    'El panel habla con el motor por la API: {url}'
        => 'The panel talks to the engine through the API: {url}',
    'El separador del CSV tiene que ser punto y coma, coma o tabulador.'
        => 'The CSV separator must be a semicolon, a comma or a tab.',
    'El trigger necesita al menos una sentencia.'
        => 'The trigger needs at least one statement.',
    'El usuario \'{usuario}\' no existe'
        => 'The user \'{usuario}\' does not exist',
    'El usuario admite de 3 a 32 caracteres: letras, números y . _ - @'
        => 'The user name takes 3 to 32 characters: letters, numbers and . _ - @',
    'El volcado supera el tope de {n} filas (ADMIN_EXPORT_MAX). Exporta las tablas por separado o usa el ZIP.'
        => 'The dump exceeds the limit of {n} rows (ADMIN_EXPORT_MAX). Export the tables separately or use the ZIP.',
    'Elige al menos una columna para el índice.'
        => 'Choose at least one column for the index.',
    'Elige cómo se conecta el panel con el motor.'
        => 'Choose how the panel connects to the engine.',
    'Elige primero una base de datos.'
        => 'Choose a database first.',
    'En esa carpeta no está jsonSQLDB: tiene que contener engine/ y config.php.'
        => 'jsonSQLDB is not in that folder: it must contain engine/ and config.php.',
    'En la base de datos {1}'
        => 'In the database {1}',
    'Enteros como BIGINT (los de PHP son de 64 bits), texto en utf8mb4_bin (las comparaciones de jsonSQLDB distinguen mayúsculas y acentos, y así una clave única sigue admitiendo lo mismo). Las vistas y los triggers van comentados al final: su SQL es el de jsonSQLDB y hay que revisarlo antes de crearlos.'
        => 'Integers as BIGINT (PHP\'s are 64-bit), text in utf8mb4_bin (jsonSQLDB\'s comparisons tell capitals and accents apart, and this way a unique key still accepts the same values). Views and triggers are commented out at the end: their SQL is jsonSQLDB\'s and has to be reviewed before creating them.',
    'Entrar'
        => 'Sign in',
    'Error desconocido'
        => 'Unknown error',
    'Error en la consulta: '
        => 'Query error: ',
    'Es AUTOINCREMENT: hay que recrear la tabla'
        => 'It is AUTOINCREMENT: the table has to be recreated',
    'Esa sentencia no devuelve filas que exportar.'
        => 'That statement returns no rows to export.',
    'Escribe <code>api/jsonsqldb_api_config.php</code> con claves al azar; la de administración será la del panel. Si lo marcas, deja vacíos los dos campos de abajo.'
        => 'Writes <code>api/jsonsqldb_api_config.php</code> with random keys; the administration one will be the panel\'s. If you check it, leave the two fields below empty.',
    'Escribe <code>{1}</code> para confirmar'
        => 'Type <code>{1}</code> to confirm',
    'Esta acción necesita permiso de administrador.'
        => 'This action needs administrator permission.',
    'Esta base no tiene tablas todavía.'
        => 'This database has no tables yet.',
    'Esta base no tiene vistas. Una vista es un <code>SELECT</code> guardado con nombre: la consultas como si fuera una tabla y siempre devuelve los datos del momento.'
        => 'This database has no views. A view is a named <code>SELECT</code>: you query it as if it were a table and it always returns current data.',
    'Esta columna es autoincremental.'
        => 'This column is autoincrement.',
    'Esta columna es clave primaria y autoincremental.'
        => 'This column is the primary key and autoincrement.',
    'Esta columna es clave primaria.'
        => 'This column is the primary key.',
    'Esta tabla no tiene clave primaria, así que no se pueden editar ni borrar filas sueltas desde el panel. Usa la pestaña SQL.'
        => 'This table has no primary key, so single rows cannot be edited or deleted from the panel. Use the SQL tab.',
    'Esta tabla no tiene clave primaria: no se puede identificar la fila.'
        => 'This table has no primary key: the row cannot be identified.',
    'Este panel solo admite conexiones HTTPS y esta petición ha llegado por HTTP.

En un servidor de verdad: pon un certificado (Let\'s Encrypt es gratis).
En tu máquina, para probar: cambia ADMIN_EXIGIR_HTTPS a false en jsonsqldbadmin/config.php, y haz lo mismo con EXIGIR_HTTPS en api/jsonsqldb_api_config.php. Vuelve a ponerlas a true antes de publicar.'
        => 'This panel only accepts HTTPS connections and this request arrived over HTTP.

On a real server: install a certificate (Let\'s Encrypt is free).
On your machine, for testing: set ADMIN_EXIGIR_HTTPS to false in jsonsqldbadmin/config.php, and do the same with EXIGIR_HTTPS in api/jsonsqldb_api_config.php. Set them back to true before going live.',
    'Estructura'
        => 'Structure',
    'Estás entrando por HTTP: exigir HTTPS te dejaría fuera. Entra por HTTPS y actívalo desde ahí.'
        => 'You are connecting over HTTP: requiring HTTPS would lock you out. Connect over HTTPS and turn it on from there.',
    'Estás instalando por HTTP: la contraseña viaja sin cifrar. En tu máquina da igual; en un servidor, usa HTTPS'
        => 'You are installing over HTTP: the password travels unencrypted. On your own machine it does not matter; on a server, use HTTPS',
    'Exigir HTTPS'
        => 'Require HTTPS',
    'Exportar este resultado'
        => 'Export this result',
    'Exportar la tabla entera'
        => 'Export the whole table',
    'Falta api/jsonsqldb_api_config.dist.php: no se puede crear la configuración de la API.'
        => 'api/jsonsqldb_api_config.dist.php is missing: the API configuration cannot be created.',
    'Fechas con más precisión que el milisegundo: se guardan en milisegundos'
        => 'Dates with more precision than the millisecond: stored in milliseconds',
    'Fechas con zona horaria importadas como DATETIME, sin la zona'
        => 'Dates with time zone imported as DATETIME, without the zone',
    'Fechas con zona horaria: se ha quitado la zona; la hora queda como venía en el volcado'
        => 'Dates with time zone: the zone has been removed; the time stays as it came in the dump',
    'Fichero ZIP'
        => 'ZIP file',
    'Fila guardada.'
        => 'Row saved.',
    'Fila insertada.'
        => 'Row inserted.',
    'Filas'
        => 'Rows',
    'Filas como máximo en un volcado SQL'
        => 'Maximum rows in an SQL dump',
    'Filas por página'
        => 'Rows per page',
    'Filtrar'
        => 'Filter',
    'Filtrar en todas las columnas… (Enter)'
        => 'Filter on every column… (Enter)',
    'Filtrar tablas'
        => 'Filter tables',
    'Filtrar tablas…'
        => 'Filter tables…',
    'Formato del volcado'
        => 'Dump format',
    'Formulario caducado. Vuelve a intentarlo.'
        => 'The form has expired. Try again.',
    'Guardar'
        => 'Save',
    'Guardar la configuración'
        => 'Save the configuration',
    'Hay que elegir al menos una columna.'
        => 'At least one column has to be chosen.',
    'Hay un proxy de confianza delante (usar su cabecera X-Forwarded-For)'
        => 'There is a trusted proxy in front (use its X-Forwarded-For header)',
    'Haz una copia de la base antes, desde <em>Bases → Copia ZIP</em>.'
        => 'Make a copy of the database first, from <em>Databases → ZIP copy</em>.',
    'Hora'
        => 'Time',
    'IPs permitidas'
        => 'Allowed IPs',
    'Idioma'
        => 'Language',
    'Importar'
        => 'Import',
    'Importar un volcado SQL'
        => 'Import an SQL dump',
    'Indica la API key y el secreto HMAC de la cuenta de administración.'
        => 'Enter the API key and the HMAC secret of the administration account.',
    'Iniciar sesión'
        => 'Sign in',
    'Insertar'
        => 'Insert',
    'Insertar (INSERT)'
        => 'Insert (INSERT)',
    'Insertar fila en «{1}»'
        => 'Insert a row into «{1}»',
    'Instalación'
        => 'Installation',
    'Instalar jsonSQLDBadmin'
        => 'Install jsonSQLDBadmin',
    'Integridad'
        => 'Integrity',
    'Intentos fallidos antes de bloquear'
        => 'Failed attempts before locking',
    'Introduce tu usuario y tu contraseña.'
        => 'Enter your user name and your password.',
    'Ir al panel →'
        => 'Go to the panel →',
    'La API de este servidor aún no está configurada: créala ahora con claves nuevas'
        => 'The API on this server is not configured yet: create it now with new keys',
    'La URL de la API tiene que empezar por http:// o https://.'
        => 'The API URL must start with http:// or https://.',
    'La URL no responde como la API de jsonSQLDB: {url}'
        => 'The URL does not answer like the jsonSQLDB API: {url}',
    'La carpeta de datos del motor ({datos}) no existe o no tiene permiso de escritura.'
        => 'The engine\'s data folder ({datos}) does not exist or is not writable.',
    'La clave de solo lectura necesita también su secreto, o ninguno de los dos.'
        => 'The read-only key also needs its secret, or neither of them.',
    'La clave foránea necesita el mismo número de columnas a cada lado.'
        => 'The foreign key needs the same number of columns on each side.',
    'La clave primaria se gestiona en el apartado «Claves».'
        => 'The primary key is managed in the «Keys» section.',
    'La columna \'{col}\' no admite nulos.'
        => 'The column \'{col}\' does not accept nulls.',
    'La columna no admite NULL'
        => 'The column does not accept NULL',
    'La configuración se ha guardado en <code>{1}</code>. Ya puedes entrar.'
        => 'The configuration has been saved in <code>{1}</code>. You can sign in now.',
    'La consulta no ha devuelto ninguna fila'
        => 'The query returned no rows',
    'La contraseña necesita al menos 10 caracteres'
        => 'The password needs at least 10 characters',
    'La copia en ZIP necesita que el panel y el motor estén en la misma máquina, porque lee los ficheros directamente del disco. La API está en {api} y el panel se está sirviendo desde {panel}. Usa el volcado en SQL, que va por la API y funciona entre máquinas distintas.'
        => 'The ZIP copy needs the panel and the engine on the same machine, because it reads the files straight from disk. The API is on {api} and the panel is being served from {panel}. Use the SQL dump, which goes through the API and works between different machines.',
    'La exportación supera el tope de {n} filas (ADMIN_EXPORT_MAX). Acota la consulta con WHERE o LIMIT.'
        => 'The export exceeds the limit of {n} rows (ADMIN_EXPORT_MAX). Narrow the query with WHERE or LIMIT.',
    'La extensión zip de PHP no está activada. Actívala en php.ini (extension=zip) o restaura desde un volcado en SQL.'
        => 'PHP\'s zip extension is not enabled. Enable it in php.ini (extension=zip) or restore from an SQL dump.',
    'La plantilla config.dist.php no tiene {constante}: está incompleta.'
        => 'The config.dist.php template has no {constante}: it is incomplete.',
    'La primera línea tiene que traer los nombres de las columnas.'
        => 'The first line must carry the column names.',
    'La primera línea, con los nombres de las columnas. El separador (coma, punto y coma o tabulador) se deduce solo, y un campo vacío es <code>NULL</code>. Se inserta en lotes de 200 filas; sin transacciones, como arriba.'
        => 'The first line, with the column names. The separator (comma, semicolon or tab) is detected on its own, and an empty field is <code>NULL</code>. Rows are inserted in batches of 200; without transactions, as above.',
    'La que contiene <code>engine/</code> y <code>config.php</code>. Vacía = la que está junto al panel.'
        => 'The one that contains <code>engine/</code> and <code>config.php</code>. Empty = the one next to the panel.',
    'La restauración falló y se ha dejado la base como estaba. {error}'
        => 'The restore failed and the database has been left as it was. {error}',
    'La sesión ha caducado por inactividad.'
        => 'The session expired due to inactivity.',
    'La sesión se ha cerrado: tu usuario ya no existe o su contraseña ha cambiado.'
        => 'The session has been closed: your user no longer exists or its password has changed.',
    'La tabla necesita al menos una columna.'
        => 'The table needs at least one column.',
    'Las claves foráneas de otras tablas se actualizan solas.'
        => 'Foreign keys in other tables are updated on their own.',
    'Las dos contraseñas no coinciden.'
        => 'The two passwords do not match.',
    'Las filas que dejes en blanco se ignoran. La longitud solo se aplica a TEXT (pasa a VARCHAR) y la escala a DECIMAL (por defecto 2). AUTOINCREMENT necesita una columna INTEGER que sea clave primaria. Marca varias PK para una clave primaria compuesta.'
        => 'Rows you leave blank are ignored. The length only applies to TEXT (it becomes VARCHAR) and the scale to DECIMAL (2 by default). AUTOINCREMENT needs an INTEGER column that is the primary key. Tick several PK for a composite primary key.',
    'Las vistas son de <strong>solo lectura</strong>: no admiten <code>INSERT</code>, <code>UPDATE</code> ni <code>DELETE</code>. Y no guardan resultados: se resuelven en cada consulta, así que una vista sobre un <code>JOIN</code> grande recorre las tablas cada vez. Sirven para no repetir SQL, no para ir más rápido.'
        => 'Views are <strong>read-only</strong>: they do not accept <code>INSERT</code>, <code>UPDATE</code> or <code>DELETE</code>. And they do not store results: they are resolved on every query, so a view over a big <code>JOIN</code> scans the tables every time. They are there to avoid repeating SQL, not to go faster.',
    'Letras, números, guion y guion bajo. Máximo 64 caracteres.'
        => 'Letters, numbers, hyphen and underscore. 64 characters at most.',
    'Lo que el panel necesita para funcionar'
        => 'What the panel needs to work',
    'Lo que no ha llegado igual: {avisos}.'
        => 'What did not arrive the same: {avisos}.',
    'Long. texto'
        => 'Text length',
    'Los datos que ya hay se convierten al tipo nuevo. Si algún valor no se puede convertir, si queda un nulo en una columna «no nula» o si hay repetidos en una «única», no se cambia nada y se te dice por qué.'
        => 'The existing data is converted to the new type. If a value cannot be converted, if a null is left in a «not null» column or if there are duplicates in a «unique» one, nothing is changed and you are told why.',
    'Los dos están en la cuenta de administración de <code>api/jsonsqldb_api_config.php</code>. Antes de guardar se prueba la conexión con ellos.'
        => 'Both are in the administration account of <code>api/jsonsqldb_api_config.php</code>. The connection is tested with them before saving.',
    'Los usuarios del panel se conservan: al terminar, entra con el tuyo de siempre.'
        => 'The panel users are kept: when it finishes, sign in with your usual one.',
    'Marca varias para una clave primaria compuesta; el orden es el de la tabla. Las columnas elegidas pasan a ser «no nulas». Si los datos actuales tienen nulos o combinaciones repetidas, la operación se rechaza y no se toca nada.'
        => 'Tick several for a composite primary key; the order is the table\'s. The chosen columns become «not null». If the current data has nulls or repeated combinations, the operation is rejected and nothing is touched.',
    'Menú'
        => 'Menu',
    'Minutos de bloqueo'
        => 'Lockout minutes',
    'Minutos de inactividad hasta cerrar la sesión'
        => 'Minutes of inactivity before the session closes',
    'Modificar (UPDATE)'
        => 'Update (UPDATE)',
    'Motor'
        => 'Engine',
    'Motor encontrado junto al panel'
        => 'Engine found next to the panel',
    'MySQL / MariaDB (mysqldump)'
        => 'MySQL / MariaDB (mysqldump)',
    'Mínimo 10 caracteres.'
        => 'At least 10 characters.',
    'Mínimo 10 caracteres. Se guarda con bcrypt.'
        => 'At least 10 characters. Stored with bcrypt.',
    'NEW.total > 0'
        => 'NEW.total > 0',
    'Navegación'
        => 'Navigation',
    'Ninguna fila coincide con el filtro.'
        => 'No row matches the filter.',
    'No admite nulos'
        => 'Does not accept nulls',
    'No es una IP ni un rango válido: {ip}'
        => 'Not a valid IP or range: {ip}',
    'No existe el fichero del certificado: {ca}'
        => 'The certificate file does not exist: {ca}',
    'No había nada que cambiar.'
        => 'There was nothing to change.',
    'No hay motor junto al panel: solo servirá la conexión por API a otra máquina'
        => 'There is no engine next to the panel: only the API connection to another machine will work',
    'No hay ningún usuario todavía. El primero es administrador.'
        => 'There are no users yet. The first one is an administrator.',
    'No hay ningún valor que guardar.'
        => 'There is no value to save.',
    'No hay ningún valor que insertar.'
        => 'There is no value to insert.',
    'No llegó ningún fichero. Comprueba que no supera el límite de subida de PHP (upload_max_filesize y post_max_size).'
        => 'No file arrived. Check that it does not exceed PHP\'s upload limit (upload_max_filesize and post_max_size).',
    'No nulo'
        => 'Not null',
    'No puede llamarse igual que una tabla. Empezar por <code>v_</code> ayuda a distinguirlas de un vistazo.'
        => 'It cannot have the same name as a table. Starting with <code>v_</code> helps to tell them apart at a glance.',
    'No puedes borrar tu propio usuario.'
        => 'You cannot delete your own user.',
    'No se encuentra el motor en {ruta}: falta engine/bootstrap.php o config.php. Indica la carpeta de jsonSQLDB en ADMIN_MOTOR_RUTA.'
        => 'The engine is not at {ruta}: engine/bootstrap.php or config.php is missing. Set the jsonSQLDB folder in ADMIN_MOTOR_RUTA.',
    'No se encuentra la carpeta de la base \'{base}\' en {ruta}. Indica la ruta de la carpeta data/ del motor en ADMIN_RUTA_DATOS_MOTOR, o usa el volcado en SQL, que va por la API y no necesita acceso al disco.'
        => 'The folder of the database \'{base}\' is not in {ruta}. Set the path of the engine\'s data/ folder in ADMIN_RUTA_DATOS_MOTOR, or use the SQL dump, which goes through the API and needs no disk access.',
    'No se ha podido completar la operación'
        => 'The operation could not be completed',
    'No se pudo escribir {destino}'
        => '{destino} could not be written',
    'No se pudo escribir {ruta}. Comprueba los permisos de la carpeta.'
        => '{ruta} could not be written. Check the folder\'s permissions.',
    'No se pudo leer \'{fichero}\' del ZIP.'
        => '\'{fichero}\' could not be read from the ZIP.',
    'No se pudo leer \'{interna}\' del ZIP.'
        => '\'{interna}\' could not be read from the ZIP.',
    'No se pudo llamar a la API ({url})'
        => 'The API could not be called ({url})',
    'No se pudo llamar a la API ({url}): {error}'
        => 'The API could not be called ({url}): {error}',
    'No se pudo serializar {fichero}'
        => '{fichero} could not be serialised',
    'No se puede crear la carpeta \'{dir}\'.'
        => 'The folder \'{dir}\' cannot be created.',
    'No se puede crear la carpeta de datos del panel: {dir}'
        => 'The panel\'s data folder cannot be created: {dir}',
    'No se puede escribir config.php en {ruta}: dale permiso de escritura mientras instalas'
        => 'config.php cannot be written in {ruta}: make it writable while installing',
    'No se puede escribir en la carpeta api/: dale permiso de escritura mientras instalas, o crea su configuración con «php configurar.php» y usa aquí su clave de administración.'
        => 'The api/ folder is not writable: make it writable while installing, or create its configuration with «php configurar.php» and use its administration key here.',
    'No se puede escribir {fichero}.'
        => '{fichero} cannot be written.',
    'No se puede escribir {ruta}: da permiso de escritura a la carpeta del panel mientras instalas.'
        => '{ruta} cannot be written: make the panel\'s folder writable while installing.',
    'No se puede leer el certificado indicado en ADMIN_SSL_CA: {ca}'
        => 'The certificate set in ADMIN_SSL_CA cannot be read: {ca}',
    'No se puede leer el fichero subido.'
        => 'The uploaded file cannot be read.',
    'No se puede leer {ruta}.'
        => '{ruta} cannot be read.',
    'Nombre'
        => 'Name',
    'Nombre \'{n}\' cambiado a \'{limpio}\': aquí solo valen letras, números y _'
        => 'Name \'{n}\' changed to \'{limpio}\': only letters, numbers and _ are valid here',
    'Nombre (opcional)'
        => 'Name (optional)',
    'Nombre de base de datos no válido: \'{valor}\''
        => 'Invalid database name: \'{valor}\'',
    'Nombre de la tabla'
        => 'Table name',
    'Nombre de {que} no válido: \'{valor}\''
        => 'Invalid {que} name: \'{valor}\'',
    'Nueva base de datos'
        => 'New database',
    'Nueva clave foránea'
        => 'New foreign key',
    'Nueva clave única'
        => 'New unique key',
    'Nueva contraseña'
        => 'New password',
    'Nueva tabla'
        => 'New table',
    'Nueva vista'
        => 'New view',
    'Nueva vista en «{1}»'
        => 'New view in «{1}»',
    'Nuevo nombre de la tabla'
        => 'New table name',
    'Nuevo trigger en «{1}»'
        => 'New trigger in «{1}»',
    'Nuevo usuario'
        => 'New user',
    'Nuevo índice'
        => 'New index',
    'ON UPDATE CURRENT_TIMESTAMP quitado: aquí no existe'
        => 'ON UPDATE CURRENT_TIMESTAMP removed: it does not exist here',
    'Opciones de tabla quitadas (ENGINE, CHARSET, WITHOUT ROWID…)'
        => 'Table options removed (ENGINE, CHARSET, WITHOUT ROWID…)',
    'Operaciones sobre la tabla'
        => 'Operations on the table',
    'Origen'
        => 'Source',
    'Panel de administración de jsonSQLDB: bases de datos SQL en ficheros JSON legibles, en PHP puro, para el hosting donde no hay servidor de base de datos.'
        => 'Administration panel for jsonSQLDB: SQL databases in readable JSON files, in plain PHP, for hosting with no database server.',
    'Para MySQL 8 y MariaDB 10.3 o posteriores: {orden}'
        => 'For MySQL 8 and MariaDB 10.3 or later: {orden}',
    'Para PostgreSQL 10 o posterior: {orden}'
        => 'For PostgreSQL 10 or later: {orden}',
    'Para SQL Server 2016 o posterior: {orden}, o ábrelo en SQL Server Management Studio. Todo va en una transacción.'
        => 'For SQL Server 2016 or later: {orden}, or open it in SQL Server Management Studio. Everything goes in one transaction.',
    'Para borrar la base hay que escribir su nombre exacto.'
        => 'To delete the database you have to type its exact name.',
    'Para cambiarla después, borra <code>config.php</code> y vuelve a abrir el panel.'
        => 'To change it later, delete <code>config.php</code> and open the panel again.',
    'Para conectar por la API hacen falta la API key y el secreto de administración.'
        => 'To connect through the API, the administration API key and secret are needed.',
    'Para hacer esta columna clave primaria, usa el apartado «Claves».'
        => 'To make this column the primary key, use the «Keys» section.',
    'Permitir entrar por HTTP, sin cifrar (solo para pruebas en tu máquina)'
        => 'Allow connecting over HTTP, unencrypted (only for testing on your machine)',
    'Peticiones firmadas a <code>api/jsonsqldb_api.php</code>, en este servidor o en otro.'
        => 'Signed requests to <code>api/jsonsqldb_api.php</code>, on this server or another.',
    'Por defecto'
        => 'Default',
    'Por la API'
        => 'Through the API',
    'Por la API firmada o con conexión directa al motor'
        => 'Through the signed API or with a direct connection to the engine',
    'PostgreSQL (pg_dump)'
        => 'PostgreSQL (pg_dump)',
    'Punto y coma (Excel en español)'
        => 'Semicolon (Excel in Spanish)',
    'Quitar el filtro'
        => 'Remove the filter',
    'Quitar esta fila'
        => 'Remove this row',
    'Quitar la clave de solo lectura'
        => 'Remove the read-only key',
    'Quitar la clave primaria'
        => 'Remove the primary key',
    'Quién ha hecho qué en el panel, día a día'
        => 'Who did what in the panel, day by day',
    'Quién puede entrar al panel y con qué permiso'
        => 'Who can enter the panel and with what permission',
    'Qué hace'
        => 'What it does',
    'Referencia'
        => 'Reference',
    'Renombrar'
        => 'Rename',
    'Repite la contraseña'
        => 'Repeat the password',
    'Respuesta del motor'
        => 'Engine response',
    'Respuesta no válida de la API: {cuerpo}'
        => 'Invalid response from the API: {cuerpo}',
    'Restaurar desde ZIP necesita que el panel y el motor estén en la misma máquina, porque escribe los ficheros directamente. Usa el volcado en SQL: se importa desde la página de la base y funciona entre máquinas distintas.'
        => 'Restoring from ZIP needs the panel and the engine on the same machine, because it writes the files directly. Use the SQL dump: it is imported from the database\'s page and works between different machines.',
    'Restaurar desde una copia ZIP'
        => 'Restore from a ZIP copy',
    'Restaurar «{1}» desde un ZIP'
        => 'Restore «{1}» from a ZIP',
    'Restricciones CHECK quitadas: aquí no existen'
        => 'CHECK constraints removed: they do not exist here',
    'Restricción'
        => 'Constraint',
    'Restricción \'{nombre}\' eliminada.'
        => 'Constraint \'{nombre}\' removed.',
    'Rol'
        => 'Role',
    'Rol no válido'
        => 'Invalid role',
    'Rol: {1}'
        => 'Role: {1}',
    'SELECT * FROM mi_tabla WHERE ...'
        => 'SELECT * FROM my_table WHERE ...',
    'SQL Server (Generar scripts)'
        => 'SQL Server (Generate Scripts)',
    'SQL: MySQL / MariaDB'
        => 'SQL: MySQL / MariaDB',
    'SQL: PostgreSQL'
        => 'SQL: PostgreSQL',
    'SQL: SQL Server'
        => 'SQL: SQL Server',
    'SQL: jsonSQLDB y SQLite'
        => 'SQL: jsonSQLDB and SQLite',
    'SQLite (.dump)'
        => 'SQLite (.dump)',
    'SQLite no acepta ALTER TABLE … ADD CONSTRAINT: ahí se cargarán los datos sin ellas.'
        => 'SQLite does not accept ALTER TABLE … ADD CONSTRAINT: the data will load there without them.',
    'Se borra solo la consulta guardada. <strong>Los datos no se tocan</strong>, porque una vista no tiene datos propios.'
        => 'Only the saved query is deleted. <strong>The data is not touched</strong>, because a view has no data of its own.',
    'Se borran <strong>todas las tablas y todos los datos</strong> de esta base. No se puede deshacer.'
        => '<strong>All the tables and all the data</strong> of this database are deleted. It cannot be undone.',
    'Se carga en jsonSQLDB (Importar un volcado SQL, en la página de la base) y en SQLite: {orden}'
        => 'Loads into jsonSQLDB (Import an SQL dump, on the database\'s page) and into SQLite: {orden}',
    'Se cargaron {n} fila(s); el problema está en la línea {linea} o en las {lote} anteriores: {error}. Lo cargado ya está dentro: no hay transacciones.'
        => '{n} row(s) were loaded; the problem is in line {linea} or in the {lote} before it: {error}. What was loaded is already in: there are no transactions.',
    'Se cargaron {n} filas'
        => '{n} rows were loaded',
    'Se ejecutaron {n} sentencia(s) y paró cerca de la línea {linea}: {error}. Lo anterior ya está hecho: no hay transacciones.'
        => '{n} statement(s) ran and it stopped near line {linea}: {error}. What came before is already done: there are no transactions.',
    'Se pierde la estructura y las {1} fila(s) que contiene. No se puede deshacer.'
        => 'The structure and the {1} row(s) it holds are lost. It cannot be undone.',
    'Se pierden los datos de esta columna en todas las filas.'
        => 'The data of this column is lost in every row.',
    'Se pondrán a <code>NULL</code> las <strong>{1}</strong> fila(s) cuya columna lo admita. <strong>Nunca se borra ninguna fila</strong>: las que apuntan desde una columna «no nula» se dejan como están, porque decidir qué hacer con ese dato es cosa tuya, no de un botón.'
        => 'The <strong>{1}</strong> row(s) whose column allows it will be set to <code>NULL</code>. <strong>No row is ever deleted</strong>: those that point from a «not null» column are left as they are, because deciding what to do with that data is up to you, not a button.',
    'Se puede escribir config.php'
        => 'config.php can be written',
    'Secciones de la tabla'
        => 'Table sections',
    'Secreto HMAC de esa cuenta'
        => 'HMAC secret of that account',
    'Seguridad'
        => 'Security',
    'Sentencia que se va a crear'
        => 'Statement that will be created',
    'Sentencias contra {1}. <kbd>Ctrl</kbd>+<kbd>Enter</kbd> ejecuta.'
        => 'Statements against {1}. <kbd>Ctrl</kbd>+<kbd>Enter</kbd> runs.',
    'Sentencias de administración saltadas (COMMENT, GRANT, IF, EXEC, CREATE SEQUENCE…)'
        => 'Administration statements skipped (COMMENT, GRANT, IF, EXEC, CREATE SEQUENCE…)',
    'Sentencias de control saltadas (PRAGMA, SET, LOCK, BEGIN, COMMIT…)'
        => 'Control statements skipped (PRAGMA, SET, LOCK, BEGIN, COMMIT…)',
    'Separador del CSV'
        => 'CSV separator',
    'Si alguna fila apunta a un valor que no existe en la tabla destino, la operación se rechaza.'
        => 'If any row points to a value that does not exist in the target table, the operation is rejected.',
    'Si cambia la conexión, se prueba antes de guardar: con una que no responde no se guarda nada.'
        => 'If the connection changes, it is tested before saving: with one that does not answer nothing is saved.',
    'Si la pones, el trigger solo salta cuando se cumple. Genera el <code>WHEN</code>.'
        => 'If you set it, the trigger only fires when it holds. It generates the <code>WHEN</code>.',
    'Si los datos actuales tienen valores repetidos, la operación se rechaza.'
        => 'If the current data has repeated values, the operation is rejected.',
    'Sin cURL: la API se llamará con las funciones de flujo de PHP'
        => 'No cURL: the API will be called with PHP\'s stream functions',
    'Sin conexión con el motor.'
        => 'No connection with the engine.',
    'Sin eventos.'
        => 'No events.',
    'Sin filas.'
        => 'No rows.',
    'Sin tablas.'
        => 'No tables.',
    'Sin triggers.'
        => 'No triggers.',
    'Sin índices.'
        => 'No indexes.',
    'Solo para DECIMAL'
        => 'Only for DECIMAL',
    'Solo para TEXT'
        => 'Only for TEXT',
    'Solo para columnas INTEGER que sean clave primaria'
        => 'Only for INTEGER columns that are the primary key',
    'Solo se puede activar entrando ya por HTTPS.'
        => 'It can only be turned on when already connected over HTTPS.',
    'Solo se pueden exportar los resultados de SELECT y SHOW.'
        => 'Only the results of SELECT and SHOW can be exported.',
    'Su secreto HMAC'
        => 'Its HMAC secret',
    'TIME importado como TEXT'
        => 'TIME imported as TEXT',
    'TIMESTAMP de SQL Server (rowversion) importado como TEXT'
        => 'SQL Server TIMESTAMP (rowversion) imported as TEXT',
    'Tabla'
        => 'Table',
    'Tabla \'{nombre}\' creada.'
        => 'Table \'{nombre}\' created.',
    'Tabla \'{tabla}\' borrada.'
        => 'Table \'{tabla}\' deleted.',
    'Tabla \'{tabla}\' vaciada ({r} fila(s)).'
        => 'Table \'{tabla}\' emptied ({r} row(s)).',
    'Tabla a la que apunta'
        => 'Table it points to',
    'Tabla de {1}'
        => 'Table of {1}',
    'Tabla renombrada a \'{nuevo}\'.'
        => 'Table renamed to \'{nuevo}\'.',
    'Tabla: {tabla}'
        => 'Table: {tabla}',
    'Tablas de esta base: {1}.'
        => 'Tables in this database: {1}.',
    'Tabulador'
        => 'Tab',
    'Tema claro u oscuro'
        => 'Light or dark theme',
    'Texto como NVARCHAR con cotejamiento binario (las comparaciones de jsonSQLDB distinguen mayúsculas y acentos), enteros como BIGINT, fechas como DATETIME2(3), autoincremento como IDENTITY. Una clave foránea de una tabla hacia sí misma va sin ON DELETE/ON UPDATE: SQL Server no admite acciones en cadena que puedan formar un ciclo. Las vistas y los triggers van comentados.'
        => 'Text as NVARCHAR with a binary collation (jsonSQLDB\'s comparisons tell capitals and accents apart), integers as BIGINT, dates as DATETIME2(3), autoincrement as IDENTITY. A foreign key from a table to itself goes without ON DELETE/ON UPDATE: SQL Server does not accept cascading actions that could form a cycle. Views and triggers are commented out.',
    'Tiempo máximo de una llamada a la API (s)'
        => 'Maximum time of an API call (s)',
    'Tiene que quedar al menos un administrador'
        => 'At least one administrator has to remain',
    'Tiene que ser un <code>SELECT</code>. Puede llevar <code>JOIN</code>, <code>GROUP BY</code>, subconsultas y hasta otras vistas.'
        => 'It must be a <code>SELECT</code>. It can have <code>JOIN</code>, <code>GROUP BY</code>, subqueries and even other views.',
    'Tiene que ser una copia generada por este panel. Solo se restauran los ficheros <code>.json</code>; cualquier otra cosa que venga dentro se ignora, y si el ZIP trae rutas que salgan de la carpeta de destino se rechaza entero sin tocar nada.'
        => 'It must be a copy made by this panel. Only the <code>.json</code> files are restored; anything else inside is ignored, and if the ZIP has paths that leave the target folder it is rejected whole without touching anything.',
    'Tipo'
        => 'Type',
    'Tipo {base} importado como TEXT'
        => 'Type {base} imported as TEXT',
    'Tipo {nombre} importado como TEXT'
        => 'Type {nombre} imported as TEXT',
    'Todavía no hay ninguna base de datos.'
        => 'There is no database yet.',
    'Todo va en una transacción: o se carga entero o no se carga nada. Enteros como BIGINT, fechas como TIMESTAMP(3), autoincremento como IDENTITY. Las vistas y los triggers van comentados al final.'
        => 'Everything goes in one transaction: it either loads whole or nothing loads. Integers as BIGINT, dates as TIMESTAMP(3), autoincrement as IDENTITY. Views and triggers are commented out at the end.',
    'Trigger \'{nombre}\' borrado.'
        => 'Trigger \'{nombre}\' deleted.',
    'Trigger \'{nombre}\' creado.'
        => 'Trigger \'{nombre}\' created.',
    'Triggers'
        => 'Triggers',
    'Tu usuario solo tiene permiso de lectura'
        => 'Your user only has read permission',
    'UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id;'
        => 'UPDATE customers SET balance = balance + NEW.total WHERE id = NEW.customer_id;',
    'URL de la API'
        => 'API URL',
    'Una cadena sin cerrar en el fichero.'
        => 'An unclosed string in the file.',
    'Una casilla vacía significa «sin valor»: en las columnas automáticas, numéricas y de fecha la columna no se manda, y toma su valor por defecto. Marca NULL para guardar un nulo, y deja el texto vacío para guardar una cadena vacía. Las columnas marcadas como «obligatorio» no admiten nulos, así que no ofrecen la casilla.'
        => 'An empty box means «no value»: in automatic, numeric and date columns the column is not sent, and it takes its default value. Tick NULL to store a null, and leave the text empty to store an empty string. Columns marked as «required» do not accept nulls, so they do not offer the box.',
    'Una o varias sentencias separadas por <code>;</code>. Dentro puedes usar <code>NEW.columna</code> (el valor que entra, en INSERT y UPDATE), <code>OLD.columna</code> (el que había, en UPDATE y DELETE), <code>RAISE(ABORT, \'mensaje\')</code> para cancelar la operación con un error, <code>IF … THEN … ELSE … END IF</code>, y en un BEFORE INSERT o BEFORE UPDATE <code>SET NEW.columna = …</code> para cambiar la fila antes de guardarla.'
        => 'One or more statements separated by <code>;</code>. Inside you can use <code>NEW.columna</code> (the incoming value, in INSERT and UPDATE), <code>OLD.columna</code> (the previous one, in UPDATE and DELETE), <code>RAISE(ABORT, \'mensaje\')</code> to cancel the operation with an error, <code>IF … THEN … ELSE … END IF</code>, and in a BEFORE INSERT or BEFORE UPDATE <code>SET NEW.columna = …</code> to change the row before it is saved.',
    'Una por línea; también rangos como 192.168.1.0/24. Tu IP ahora: <code>{1}</code>. Una lista sin ella no se acepta.'
        => 'One per line; ranges like 192.168.1.0/24 too. Your IP now: <code>{1}</code>. A list without it is not accepted.',
    'Una sentencia por ejecución. Admite varias líneas y comentarios <code>--</code> y <code>/* */</code>.'
        => 'One statement per run. Several lines and <code>--</code> and <code>/* */</code> comments are allowed.',
    'Una vista tiene que ser un SELECT.'
        => 'A view must be a SELECT.',
    'Usuario'
        => 'User',
    'Usuario \'{nombre}\' borrado.'
        => 'User \'{nombre}\' deleted.',
    'Usuario \'{nombre}\' creado.'
        => 'User \'{nombre}\' created.',
    'Usuario o contraseña incorrectos'
        => 'Wrong user name or password',
    'Usuarios'
        => 'Users',
    'Usuarios del panel'
        => 'Panel users',
    'Usuarios y auditoría'
        => 'Users and audit log',
    'Vacía = la de esta instalación.'
        => 'Empty = this installation\'s.',
    'Vacía = la que está junto al panel.'
        => 'Empty = the one next to the panel.',
    'Vacío = cualquiera'
        => 'Empty = anyone',
    'Valor huérfano'
        => 'Orphan value',
    'Valores por defecto calculados quitados (CURRENT_TIMESTAMP, NOW()…): aquí solo valen literales'
        => 'Computed default values removed (CURRENT_TIMESTAMP, NOW()…): only literals are valid here',
    'Versión'
        => 'Version',
    'Vista'
        => 'View',
    'Vista \'{nombre}\' borrada.'
        => 'View \'{nombre}\' deleted.',
    'Vista \'{nombre}\' creada.'
        => 'View \'{nombre}\' created.',
    'Vistas'
        => 'Views',
    'Volcado SQL para otro motor'
        => 'SQL dump for another engine',
    'Volver a comprobar'
        => 'Check again',
    'Volver al listado de bases'
        => 'Back to the list of databases',
    'a mano'
        => 'by hand',
    'admin — todo'
        => 'admin — everything',
    'auto'
        => 'auto',
    'base'
        => 'database',
    'bases'
        => 'databases',
    'cURL disponible'
        => 'cURL available',
    'conexión directa'
        => 'direct connection',
    'conexión directa al motor'
        => 'direct connection to the engine',
    'conexión por API'
        => 'API connection',
    'conexión por la API'
        => 'connection through the API',
    'configurada (vacío = no cambiarla)'
        => 'set (empty = do not change it)',
    'configurado (vacío = no cambiarlo)'
        => 'set (empty = do not change it)',
    'creada con claves nuevas'
        => 'created with new keys',
    'creado a mano'
        => 'created by hand',
    'el final (claves foráneas)'
        => 'the end (foreign keys)',
    'exigido'
        => 'required',
    'fija'
        => 'fixed',
    'fila(s)'
        => 'row(s)',
    'jsonSQLDBadmin está instalado'
        => 'jsonSQLDBadmin is installed',
    'jsonSQLDBadmin{1} · PHP puro, sin dependencias externas ·'
        => 'jsonSQLDBadmin{1} · plain PHP, no external dependencies ·',
    'jsonSQLDBadmin{1} · PHP {2} ·'
        => 'jsonSQLDBadmin{1} · PHP {2} ·',
    'jsonSQLDBadmin{1} · {2} · PHP {3}'
        => 'jsonSQLDBadmin{1} · {2} · PHP {3}',
    'lectura — ver datos y lanzar SELECT/SHOW'
        => 'read — see data and run SELECT/SHOW',
    'lo pone la base'
        => 'set by the database',
    'motor encontrado'
        => 'engine found',
    'ms'
        => 'ms',
    'no exigido (solo para pruebas en local)'
        => 'not required (only for local testing)',
    'no se ha podido comprobar ({url} no contesta)'
        => 'could not be checked ({url} does not answer)',
    'no se ha podido comprobar ({url} responde {codigo})'
        => 'could not be checked ({url} answers {codigo})',
    'no se puede comprobar con el servidor integrado de PHP (php -S)'
        => 'cannot be checked with PHP\'s built-in server (php -S)',
    'no se puede comprobar: el motor está en otra carpeta que el panel'
        => 'cannot be checked: the engine is in a different folder from the panel',
    'no se puede comprobar: la API ({api}) no está en la carpeta api/ de la instalación'
        => 'cannot be checked: the API ({api}) is not in the installation\'s api/ folder',
    'obligatorio'
        => 'required',
    'protegida ({url} responde {codigo})'
        => 'protected ({url} answers {codigo})',
    'siempre'
        => 'forever',
    'sin conexión'
        => 'no connection',
    'sin configurar'
        => 'not set',
    'sin fsync(), un corte de luz puede perder unos 30 s de escrituras; se recomienda 8.1 o posterior'
        => 'no fsync(): a power cut can lose about 30 s of writes; 8.1 or later is recommended',
    'sin motor aquí'
        => 'no engine here',
    'sin nulos'
        => 'no nulls',
    'sí, poniendo NULL'
        => 'yes, setting NULL',
    'tú'
        => 'you',
    'usuario, acción, tabla…'
        => 'user, action, table…',
    'volcado de la base \'{base}\''
        => 'dump of the database \'{base}\'',
    '{1} <strong>Todo correcto.</strong> Ninguna fila apunta a un valor que no exista en su tabla destino.'
        => '{1} <strong>All correct.</strong> No row points to a value that does not exist in its target table.',
    '{1} <strong>{2} fila(s) huérfana(s).</strong> Apuntan a valores que ya no existen en la tabla destino. Trabajando por SQL esto no puede pasar: casi siempre es que alguien editó un <code>.json</code> a mano o restauró la copia de una tabla sin la otra.'
        => '{1} <strong>{2} orphan row(s).</strong> They point to values that no longer exist in the target table. Working through SQL this cannot happen: almost always someone edited a <code>.json</code> by hand or restored the copy of one table without the other.',
    '{1} Añadir columna'
        => '{1} Add column',
    '{1} Clave foránea'
        => '{1} Foreign key',
    '{1} Clave primaria'
        => '{1} Primary key',
    '{1} Clave única'
        => '{1} Unique key',
    '{1} Corregir {2} fila(s)'
        => '{1} Fix {2} row(s)',
    '{1} Insertar fila'
        => '{1} Insert row',
    '{1} Nuevo trigger'
        => '{1} New trigger',
    '{1} Nuevo índice'
        => '{1} New index',
    '{1} Restaurar'
        => '{1} Restore',
    '{1} Vaciar la tabla (borrar todas las filas)'
        => '{1} Empty the table (delete every row)',
    '{1} copia en ZIP: los ficheros JSON tal cual, con su estructura de carpetas. {2} restaura esa copia sobre la base, sustituyendo lo que haya.'
        => '{1} ZIP copy: the JSON files as they are, with their folder structure. {2} restores that copy over the database, replacing what is there.',
    '{1} en esta base de datos'
        => '{1} in this database',
    '{1} evento(s) · se conservan {2}'
        => '{1} event(s) · kept for {2}',
    '{1} volcado en SQL: estructura y datos, legible y reejecutable.'
        => '{1} SQL dump: structure and data, readable and re-runnable.',
    '{1}PHP {2} ·'
        => '{1}PHP {2} ·',
    '{n} días'
        => '{n} days',
    '{n} fila(s)'
        => '{n} row(s)',
    '{n} fila(s) cargadas en \'{tabla}\'.'
        => '{n} row(s) loaded into \'{tabla}\'.',
    '{n} sentencia(s) ejecutadas (volcado de {motor}).'
        => '{n} statement(s) run (dump from {motor}).',
    '{r} fila(s) borrada(s).'
        => '{r} row(s) deleted.',
    '{t} tabla(s), {f} fila(s)'
        => '{t} table(s), {f} row(s)',
    '«Long. texto» son caracteres y solo vale para TEXT; «Decimales» solo para DECIMAL (2 si lo dejas vacío). DATETIME se guarda como <code>AAAA-MM-DD</code> con la hora opcional (<code>AAAA-MM-DD HH:MM:SS</code>). Si la tabla ya tiene filas y la columna es «no nula», pon un valor por defecto.'
        => '«Text length» is in characters and only applies to TEXT; «Decimals» only to DECIMAL (2 if you leave it empty). DATETIME is stored as <code>YYYY-MM-DD</code> with an optional time (<code>YYYY-MM-DD HH:MM:SS</code>). If the table already has rows and the column is «not null», set a default value.',
    '· DEL {1} · UPD {2}'
        => '· DEL {1} · UPD {2}',
    '¿Borrar el trigger?'
        => 'Delete the trigger?',
    '¿Borrar el usuario?'
        => 'Delete the user?',
    '¿Borrar el índice?'
        => 'Delete the index?',
    '¿Borrar esta fila?'
        => 'Delete this row?',
    '¿Corregible?'
        => 'Fixable?',
    '¿Eliminar la restricción?'
        => 'Remove the constraint?',
    '¿Poner a NULL las claves huérfanas que se puedan?'
        => 'Set to NULL the orphan keys that allow it?',
    '¿Quitar la clave primaria?'
        => 'Remove the primary key?',
    '¿Qué conexión elegir?'
        => 'Which connection to choose?',
    'Índice \'{nombre}\' borrado.'
        => 'Index \'{nombre}\' deleted.',
    'Índice \'{nombre}\' creado.'
        => 'Index \'{nombre}\' created.',
    'Índices'
        => 'Indexes',
    'Índices FULLTEXT y SPATIAL quitados: aquí no existen'
        => 'FULLTEXT and SPATIAL indexes removed: they do not exist here',
    'Índices parciales (WHERE) creados sobre toda la tabla'
        => 'Partial indexes (WHERE) created over the whole table',
    'Último acceso'
        => 'Last access',
    'Única'
        => 'Unique',
    '— elige —'
        => '— choose —',
    'Tablas'
        => 'Tables',
    'Inicio'
        => 'Home',
    'Sin traducir: {motivo}'
        => 'Not translated: {motivo}',
    'Sin traducir (van comentados al final): {lista}'
        => 'Not translated (commented out at the end): {lista}',
    'No se encuentra el analizador del motor en {ruta}'
        => 'The engine\'s SQL parser is not at {ruta}',
    'Access no tiene triggers: ni su SQL ni OLEDB pueden crearlos. Las macros de datos de un .accdb se hacen a mano en Access.'
        => 'Access has no triggers: neither its SQL nor OLEDB can create them. The data macros of an .accdb are made by hand in Access.',
    'SQL Server: sin clave primaria en \'{tabla}\' no se puede saber qué fila de deleted corresponde a cada una de inserted'
        => 'SQL Server: without a primary key in \'{tabla}\' there is no way to tell which row of deleted belongs to each row of inserted',
    'Un trigger solo puede hacer INSERT, UPDATE, DELETE o SELECT'
        => 'A trigger can only do INSERT, UPDATE, DELETE or SELECT',
    'Access no tiene {op}'
        => 'Access has no {op}',
    'Access no tiene OFFSET'
        => 'Access has no OFFSET',
    '{motor} no tiene FULL JOIN'
        => '{motor} has no FULL JOIN',
    'Access no tiene DEFAULT en un UPDATE'
        => 'Access has no DEFAULT in an UPDATE',
    'RAISE solo se puede traducir como sentencia de un trigger'
        => 'RAISE can only be translated as a statement of a trigger',
    'Expresión sin traducción: \'{k}\''
        => 'Expression with no translation: \'{k}\'',
    '{cual}.{col} no es una columna de la tabla'
        => '{cual}.{col} is not a column of the table',
    'Access no tiene LIKE … ESCAPE'
        => 'Access has no LIKE … ESCAPE',
    '{motor} no tiene expresiones regulares (REGEXP)'
        => '{motor} has no regular expressions (REGEXP)',
    'CAST a \'{tipo}\' sin equivalente en Access'
        => 'CAST to \'{tipo}\' with no equivalent in Access',
    'CAST a \'{tipo}\' sin equivalente'
        => 'CAST to \'{tipo}\' with no equivalent',
    'SQL Server no tiene STRING_AGG(DISTINCT …)'
        => 'SQL Server has no STRING_AGG(DISTINCT …)',
    'Access no tiene una función para unir los textos de un grupo (GROUP_CONCAT)'
        => 'Access has no function to join the texts of a group (GROUP_CONCAT)',
    'Función sin traducción: {f}()'
        => 'Function with no translation: {f}()',
    'Access no tiene {f} con caracteres propios'
        => 'Access has no {f} with your own characters',
    'Solo se traducen modificadores de fecha escritos tal cual'
        => 'Only date modifiers written as literals are translated',
    'Modificador de fecha sin traducción: \'{m}\''
        => 'Date modifier with no translation: \'{m}\'',
    'STRFTIME solo se traduce con el formato escrito tal cual'
        => 'STRFTIME is only translated with its format written as a literal',
    'Código de STRFTIME sin traducción: {c}'
        => 'STRFTIME code with no translation: {c}',
    '{que} \'{n}\' sin importar: {motivo}'
        => '{que} \'{n}\' not imported: {motivo}',
    'el final (triggers)'
        => 'the end (triggers)',
    'Funciones y procedimientos almacenados saltados: aquí no existen'
        => 'Stored functions and procedures skipped: they do not exist here',
    'una vista con la lista de columnas delante del AS'
        => 'a view with the list of columns before the AS',
    'no se encuentra el AS de la vista'
        => 'the AS of the view is not there',
    'trigger {m} {e}: aquí solo hay BEFORE y AFTER de INSERT, UPDATE o DELETE'
        => 'trigger {m} {e}: here there are only BEFORE and AFTER of INSERT, UPDATE or DELETE',
    'no se encuentra el ON del trigger'
        => 'the ON of the trigger is not there',
    'el trigger no hace nada que se pueda hacer aquí'
        => 'the trigger does nothing that can be done here',
    'falta el END del cuerpo'
        => 'the END of the body is missing',
    'falta {p}'
        => '{p} is missing',
    'SELECT … INTO variable: aquí no hay variables'
        => 'SELECT … INTO variable: there are no variables here',
    'variables locales (DECLARE): aquí no hay variables'
        => 'local variables (DECLARE): there are no variables here',
    'sentencia \'{s}\' sin equivalente en un trigger de aquí'
        => 'statement \'{s}\' with no equivalent in a trigger here',
    'SET a una variable: aquí solo se puede cambiar NEW.columna'
        => 'SET of a variable: here only NEW.column can be changed',
    'asignación sin ='
        => 'assignment with no =',
    'SET NEW en un trigger AFTER'
        => 'SET NEW in an AFTER trigger',
    'Mensajes de error compuestos en triggers: se importa solo su texto fijo'
        => 'Error messages built from parts in triggers: only their fixed text is imported',
    'REPLACE INTO: aquí no hay sustitución de filas por clave'
        => 'REPLACE INTO: there is no replacement of rows by key here',
    'INSERT … ON DUPLICATE KEY UPDATE'
        => 'INSERT … ON DUPLICATE KEY UPDATE',
    'el operador XOR'
        => 'the XOR operator',
    'LOCATE con posición de inicio'
        => 'LOCATE with a start position',
    'TRUNCATE con decimales calculados'
        => 'TRUNCATE with computed decimals',
    'INTERVAL en \'{u}\''
        => 'INTERVAL in \'{u}\'',
    'DATE_FORMAT con un formato calculado'
        => 'DATE_FORMAT with a computed format',
    'Código de DATE_FORMAT sin traducción: {c}'
        => 'DATE_FORMAT code with no translation: {c}',
    'paréntesis sin cerrar'
        => 'unclosed parenthesis',
    '{f}() con {n} argumentos'
        => '{f}() with {n} arguments',
    'UPDATE OF columnas en triggers: aquí se dispara con cualquier UPDATE'
        => 'UPDATE OF columns in triggers: here it fires with any UPDATE',
    'trigger de TRUNCATE'
        => 'TRUNCATE trigger',
    'trigger FOR EACH STATEMENT: aquí los triggers son por fila'
        => 'FOR EACH STATEMENT trigger: here triggers run per row',
    'trigger con REFERENCING'
        => 'trigger with REFERENCING',
    'no se encuentra la función {f}() en el volcado'
        => 'the function {f}() is not in the dump',
    'INSTEAD OF: aquí un trigger no puede sustituir la escritura que lo dispara'
        => 'INSTEAD OF: here a trigger cannot replace the write that fires it',
    'UPDATE o DELETE con varias tablas en su FROM'
        => 'UPDATE or DELETE with several tables in its FROM',
    'RETURN NULL en un trigger BEFORE: aquí no se puede cancelar una escritura en silencio'
        => 'RETURN NULL in a BEFORE trigger: here a write cannot be cancelled silently',
    'un intervalo calculado'
        => 'a computed interval',
    '{op} {c} (…)'
        => '{op} {c} (…)',
    'LIKE con un patrón calculado: aquí no distingue mayúsculas'
        => 'LIKE with a computed pattern: here it does not tell capitals apart',
    'intervalo \'{i}\''
        => 'interval \'{i}\'',
    'date_trunc(\'{u}\')'
        => 'date_trunc(\'{u}\')',
    'to_char con un formato calculado'
        => 'to_char with a computed format',
    'Código de to_char sin traducción: {c}'
        => 'to_char code with no translation: {c}',
    'EXTRACT de \'{u}\''
        => 'EXTRACT of \'{u}\'',
    'Autonuméricos que no son la clave primaria: importados como enteros'
        => 'Autonumbers that are not the primary key: imported as integers',
    'el operador ^ con valores calculados'
        => 'the ^ operator with computed values',
    'SQL: Microsoft Access'
        => 'SQL: Microsoft Access',
    'Microsoft Access (script de PowerShell)'
        => 'Microsoft Access (PowerShell script)',
    'Ahora se deduce en cada petición de la cabecera Host, que manda el navegador: guarda la configuración para dejarla escrita.'
        => 'Right now it is worked out on every request from the Host header, which the browser sends: save the configuration to have it written down.',
    'Sentencias que no estaban en UTF-8, leídas como Latin-1 / Windows-1252 ({n}): si eran datos binarios, vuelca con --hex-blob'
        => 'Statements that were not in UTF-8, read as Latin-1 / Windows-1252 ({n}): if they were binary data, dump with --hex-blob',
    'Campos que no estaban en UTF-8, leídos como Latin-1 / Windows-1252: {n}.'
        => 'Fields that were not in UTF-8, read as Latin-1 / Windows-1252: {n}.',
    'Paró cerca de la línea {linea}: {error}. No se ha cambiado nada: la base ha vuelto a como estaba antes de importar.'
        => 'It stopped near line {linea}: {error}. Nothing has changed: the database is back as it was before the import.',
    'El problema está en la línea {linea} o en las {lote} anteriores: {error}. No se ha cargado nada: la tabla ha vuelto a como estaba.'
        => 'The problem is in line {linea} or in the {lote} before it: {error}. Nothing has been loaded: the table is back as it was.',
    '{f} fichero(s) restaurados, {t} tabla(s).'
        => '{f} file(s) restored, {t} table(s).',
    'Nombre \'{n}\' cambiado a \'{otro}\': \'{ya}\' ya se llamaba \'{destino}\' aquí'
        => 'Name \'{n}\' changed to \'{otro}\': \'{ya}\' was already called \'{destino}\' here',
    'Fechas «0000-00-00» de MySQL importadas como NULL: aquí no existen'
        => 'MySQL «0000-00-00» dates imported as NULL: they do not exist here',
    'DECIMAL de más de 15 cifras: aquí se guarda como número de coma flotante, con unas 15 cifras exactas'
        => 'DECIMAL of more than 15 digits: here it is stored as a floating-point number, with about 15 exact digits',
    'ERROR: el volcado está incompleto: {error}'
        => 'ERROR: the dump is incomplete: {error}',
    'No se pudo devolver la base a como estaba: la copia de antes está en {copia}.'
        => 'The database could not be put back as it was: the copy from before is in {copia}.',
    'Lo que paró la importación: {error}'
        => 'What stopped the import: {error}',
    'El usuario \'{usuario}\' ya existe'
        => 'The user \'{usuario}\' already exists',
    'La extensión zip de PHP no está activada, así que no se puede generar el ZIP. Actívala en php.ini (extension=zip) o usa el volcado en SQL.'
        => 'PHP\'s zip extension is not enabled, so the ZIP cannot be made. Enable it in php.ini (extension=zip) or use the SQL dump.',
    'No se pudo crear el fichero temporal del ZIP.'
        => 'The ZIP\'s temporary file could not be created.',
    'No se pudo abrir el ZIP temporal para escribir.'
        => 'The temporary ZIP could not be opened for writing.',
    'No se pudo cerrar el ZIP temporal.'
        => 'The temporary ZIP could not be closed.',
    'No se pudo hacer la copia de la base antes de cambiarla. Comprueba el espacio libre y los permisos de la carpeta de datos.'
        => 'The copy of the database could not be made before changing it. Check the free space and the permissions of the data folder.',
    'El código de instalación no es correcto. Está en el fichero {fichero} del servidor.'
        => 'The installation code is not correct. It is in the file {fichero} on the server.',
    'No se puede bloquear la base para copiarla o restaurarla: comprueba los permisos de su carpeta.'
        => 'The database cannot be locked to copy or restore it: check the permissions of its folder.',
    'Código de instalación'
        => 'Installation code',
    'Para que solo quien tiene acceso al servidor pueda terminar la instalación: está en el fichero <code>{fichero}</code> de la carpeta de datos del panel (<code>jsonsqldbadmin/datos/</code>, salvo que <code>ADMIN_DATA_PATH</code> diga otra), y también lo muestra <code>php configurar.php</code>. Se borra al terminar.'
        => 'So that only someone with access to the server can finish the installation: it is in the file <code>{fichero}</code> in the panel\'s data folder (<code>jsonsqldbadmin/datos/</code>, unless <code>ADMIN_DATA_PATH</code> says otherwise), and <code>php configurar.php</code> shows it too. It is deleted when the installation is done.',
    '<strong>PHP {v}: las escrituras no son duraderas ante un corte de luz.</strong> El motor necesita fsync(), que llega con PHP 8.1, para que una escritura confirmada sobreviva a un apagón o a un fallo del sistema. Usa PHP 8.1 o posterior en producción.'
        => '<strong>PHP {v}: writes are not durable across a power cut.</strong> The engine needs fsync(), which arrives with PHP 8.1, for a confirmed write to survive a power failure or a system crash. Use PHP 8.1 or later in production.',
    'Para Microsoft Access, en su sintaxis de siempre (ANSI-89), la de la vista SQL de una consulta. Access ejecuta una sola sentencia cada vez: copia cada una (sin las líneas que empiezan por --) en Crear → Diseño de consulta → Vista SQL y pulsa Ejecutar. Para cargar el fichero entero de una vez, usa el script access-to-jsonsqldb.ps1 (opción «Load an SQL file into Access»).'
        => 'For Microsoft Access, in its usual syntax (ANSI-89), the one of a query\'s SQL view. Access runs one statement at a time: copy each one (without the lines that start with --) into Create → Query Design → SQL View and click Run. To load the whole file at once, use the access-to-jsonsqldb.ps1 script (option «Load an SQL file into Access»).',
    'DECIMAL pasa a CURRENCY (hasta 4 decimales) o DOUBLE; un salto de línea dentro de un texto, a Chr(13) & Chr(10). Access no tiene triggers: van comentados al final.'
        => 'DECIMAL becomes CURRENCY (up to 4 decimals) or DOUBLE; a line break inside a text, Chr(13) & Chr(10). Access has no triggers: they are commented out at the end.',
    'Chr() con un código calculado'
        => 'Chr() with a computed code',
    'Cómo hacer el volcado de cada motor'
        => 'How to make the dump of each database',
    'Haz el volcado, súbelo en la página de la base donde lo quieres y deja el formato en «Detectar el formato». Al terminar, el resumen dice lo que no ha llegado igual.'
        => 'Make the dump, upload it on the page of the database you want it in, and leave the format on «Detect the format». When it finishes, the summary says what did not arrive the same.',
    'Con la herramienta de línea de órdenes <code>sqlite3</code> (en Windows, <code>sqlite3.exe</code>, de sqlite.org):'
        => 'With the <code>sqlite3</code> command-line tool (on Windows, <code>sqlite3.exe</code>, from sqlite.org):',
    'La segunda línea vuelca solo algunas tablas. Con <em>DB Browser for SQLite</em>: Archivo → Exportar → Base de datos a archivo SQL.'
        => 'The second line dumps only some tables. With <em>DB Browser for SQLite</em>: File → Export → Database to SQL file.',
    'Con <code>mysqldump</code> (en las versiones recientes de MariaDB, <code>mariadb-dump</code>):'
        => 'With <code>mysqldump</code> (on recent MariaDB versions, <code>mariadb-dump</code>):',
    '<code>--default-character-set=utf8mb4</code> conserva los acentos y los emojis. Un volcado antiguo en Latin-1 también se lee.'
        => '<code>--default-character-set=utf8mb4</code> keeps accents and emoji. An old dump in Latin-1 is read too.',
    'No uses <code>--xml</code>, <code>--tab</code> ni <code>--compatible</code>: no escriben SQL que el importador lea.'
        => 'Do not use <code>--xml</code>, <code>--tab</code> or <code>--compatible</code>: they do not write SQL the importer reads.',
    'Desde phpMyAdmin: Exportar → Personalizado → Formato: SQL.'
        => 'From phpMyAdmin: Export → Custom → Format: SQL.',
    'Las vistas y los triggers se traducen, también como los guarda MySQL 8. Los procedimientos y las funciones se saltan.'
        => 'Views and triggers are translated, also the way MySQL 8 stores them. Procedures and functions are skipped.',
    'Con <code>pg_dump</code>, en su formato de texto, el de siempre:'
        => 'With <code>pg_dump</code>, in its plain text format, the default one:',
    'No uses <code>-Fc</code>, <code>-Fd</code> ni <code>-Ft</code>: son archivos binarios para <code>pg_restore</code>.'
        => 'Do not use <code>-Fc</code>, <code>-Fd</code> or <code>-Ft</code>: they are binary archives for <code>pg_restore</code>.',
    'Desde pgAdmin: Copia de seguridad → Formato: Plano.'
        => 'From pgAdmin: Backup → Format: Plain.',
    'Las vistas y los triggers se traducen, con la función plpgsql de cada trigger. Un trigger de varios eventos (INSERT OR UPDATE) pasa a ser uno por evento.'
        => 'Views and triggers are translated, with each trigger\'s plpgsql function. A trigger for several events (INSERT OR UPDATE) becomes one per event.',
    'Con SQL Server Management Studio:'
        => 'With SQL Server Management Studio:',
    'Botón derecho sobre la base → Tareas → Generar scripts…'
        => 'Right-click the database → Tasks → Generate Scripts…',
    'Elige las tablas, o la base entera.'
        => 'Choose the tables, or the whole database.',
    'En «Establecer opciones de scripting», abre «Avanzadas» y pon «Tipos de datos para incluir en el script» en «Esquema y datos».'
        => 'In «Set Scripting Options», open «Advanced» and set «Types of data to script» to «Schema and data».',
    'Guárdalo en un solo fichero, en Unicode o en UTF-8.'
        => 'Save it to a single file, in Unicode or in UTF-8.',
    'Las vistas y los triggers se traducen. Un trigger de SQL Server trabaja con todas las filas a la vez (<code>inserted</code> y <code>deleted</code>); aquí, fila a fila, así que se reescribe. Los triggers <code>INSTEAD OF</code>, los cursores y los procedimientos almacenados se saltan.'
        => 'Views and triggers are translated. A SQL Server trigger works with all the rows at once (<code>inserted</code> and <code>deleted</code>); here, row by row, so it is rewritten. <code>INSTEAD OF</code> triggers, cursors and stored procedures are skipped.',
    'Con el script de PowerShell <code>access-to-jsonsqldb.ps1</code>, que <a href="{1}">se descarga aquí</a>. Necesita <strong>Windows</strong>.'
        => 'With the PowerShell script <code>access-to-jsonsqldb.ps1</code>, <a href="{1}">downloaded here</a>. It needs <strong>Windows</strong>.',
    'Botón derecho sobre el fichero descargado → <strong>«Ejecutar con PowerShell»</strong>.'
        => 'Right-click the downloaded file → <strong>«Run with PowerShell»</strong>.',
    'Elige <strong>«Dump an Access database to SQL»</strong>, el .mdb o .accdb y la carpeta donde guardar el volcado.'
        => 'Choose <strong>«Dump an Access database to SQL»</strong>, the .mdb or .accdb file and the folder to save the dump in.',
    'Importa aquí el fichero .access.sql que deja en esa carpeta.'
        => 'Import here the .access.sql file it leaves in that folder.',
    'La base se abre solo para leer. Con un <strong>.mdb</strong> no hace falta instalar nada: Windows trae su controlador (Jet), aunque solo para programas de 32 bits, y el script se vuelve a abrir solo con el PowerShell de 32 bits. Un <strong>.accdb</strong> necesita el <strong>Access Database Engine 2016</strong>, de los mismos bits que PowerShell (con Office de 32 bits, el de 32 bits y el PowerShell de 32 bits); si falta, el script lo dice y da el enlace para descargarlo.'
        => 'The database is opened read-only. An <strong>.mdb</strong> needs nothing installed: Windows has its engine (Jet), but only for 32-bit programs, and the script opens itself again in the 32-bit PowerShell. An <strong>.accdb</strong> needs the <strong>Access Database Engine 2016</strong>, with the same bitness as PowerShell (with 32-bit Office, the 32-bit one and the 32-bit PowerShell); if it is missing, the script says so and gives the link to download it.',
    'Para el camino contrario, de aquí a Access: exporta la base como <strong>«SQL: Microsoft Access»</strong> y cárgalo con la opción <strong>«Load an SQL file into Access»</strong> del mismo script, que crea la base si no existe; o pega cada sentencia en Access a mano.'
        => 'For the other way round, from here to Access: export the database as <strong>«SQL: Microsoft Access»</strong> and load it with the <strong>«Load an SQL file into Access»</strong> option of the same script, which creates the database if it does not exist; or paste each statement into Access by hand.',
    'Limitaciones de Access'
        => 'Access limitations',
    'El fichero está escrito en el SQL de Access, en su sintaxis de siempre (ANSI-89): cada sentencia se puede pegar en Crear → Diseño de consulta → Vista SQL y ejecutar. Esa sintaxis tiene estos límites:'
        => 'The file is written in Access SQL, in its usual syntax (ANSI-89): each statement can be pasted into Create → Query Design → SQL View and run. That syntax has these limits:',
    '<strong>Una sentencia cada vez.</strong> La vista SQL no ejecuta varias seguidas, y las líneas que empiezan por <code>--</code> no se copian. Para un fichero entero, la opción del script.'
        => '<strong>One statement at a time.</strong> The SQL view does not run several in a row, and the lines that start with <code>--</code> are not copied. For a whole file, the script\'s option.',
    '<strong>Sin DEFAULT ni relaciones en cascada.</strong> Van en una línea encima de su tabla o relación (<code>-- [tabla].[columna] DEFAULT valor</code>, <code>-- [relación] ON DELETE CASCADE</code>) para ponerlas a mano: el valor predeterminado en la vista Diseño de la tabla; la cascada en Herramientas de base de datos → Relaciones → Exigir integridad referencial. Al importar el fichero aquí se aplican solas.'
        => '<strong>No DEFAULT and no cascading relationships.</strong> They go in a line above their table or relationship (<code>-- [table].[column] DEFAULT value</code>, <code>-- [relationship] ON DELETE CASCADE</code>) to set them by hand: the default value in the table\'s Design view; the cascade in Database Tools → Relationships → Enforce Referential Integrity. When the file is imported here, they are applied on their own.',
    '<strong>Sin DECIMAL.</strong> Pasa a <code>CURRENCY</code> con hasta 4 decimales y a <code>DOUBLE</code> con más. <code>LONG</code> es de 32 bits: un entero mayor pasa a <code>DOUBLE</code>, exacto hasta 2^53.'
        => '<strong>No DECIMAL.</strong> It becomes <code>CURRENCY</code> with up to 4 decimals and <code>DOUBLE</code> with more. <code>LONG</code> is 32-bit: a larger integer becomes <code>DOUBLE</code>, exact up to 2^53.',
    '<strong>Un INSERT por fila</strong>, y un salto de línea dentro de un texto se escribe <code>\'a\' &amp; Chr(13) &amp; Chr(10) &amp; \'b\'</code>.'
        => '<strong>One INSERT per row</strong>, and a line break inside a text is written <code>\'a\' &amp; Chr(13) &amp; Chr(10) &amp; \'b\'</code>.',
    '<strong>Sin triggers.</strong> Van comentados al final; en un .accdb, las macros de datos se hacen a mano.'
        => '<strong>No triggers.</strong> They are commented out at the end; in an .accdb, data macros are made by hand.',
    '<strong>Sin</strong> FULL JOIN, INTERSECT, EXCEPT, OFFSET, GROUP_CONCAT ni expresiones regulares: las vistas que los usan van comentadas con el motivo.'
        => '<strong>No</strong> FULL JOIN, INTERSECT, EXCEPT, OFFSET, GROUP_CONCAT or regular expressions: the views that use them are commented out with the reason.',
    'El texto llega hasta 255 caracteres; más largo es <code>MEMO</code>, que no se puede indexar. Las fechas van del año 100 al 9999. Sí/No vale -1 en Access y 1 aquí.'
        => 'Text goes up to 255 characters; longer is <code>MEMO</code>, which cannot be indexed. Dates go from year 100 to 9999. Yes/No is -1 in Access and 1 here.',
    'Access compara los textos sin distinguir mayúsculas, también con <code>=</code>; aquí <code>=</code> sí las distingue.'
        => 'Access compares text without case, also with <code>=</code>; here <code>=</code> does tell capitals apart.',
    '<strong>No llegan</strong> los adjuntos ni los objetos OLE (datos binarios), las consultas de acción, de referencias cruzadas o con parámetros, ni las consultas ocultas de formularios e informes. El script las nombra al terminar.'
        => '<strong>What does not come along:</strong> attachments and OLE objects (binary data), action, crosstab or parameter queries, and the hidden queries of forms and reports. The script names them when it finishes.',
    'En ninguno llegan igual: las restricciones <code>CHECK</code>, los valores por defecto calculados (<code>CURRENT_TIMESTAMP</code>, <code>NOW()</code>), las listas <code>ENUM</code>, la zona horaria de las fechas y los datos binarios que no son texto (se guardan en hexadecimal). Un nombre que aquí no vale se cambia (<code>Order Details</code> → <code>Order_Details</code>). Si el volcado trae <code>DROP TABLE</code>, la tabla con el mismo nombre se sustituye: ante la duda, importa en una base nueva.'
        => 'Not the same in any of them: <code>CHECK</code> constraints, computed default values (<code>CURRENT_TIMESTAMP</code>, <code>NOW()</code>), <code>ENUM</code> value lists, the time zone of dates and binary data that is not text (kept in hexadecimal). A name that is not valid here is changed (<code>Order Details</code> → <code>Order_Details</code>). If the dump contains <code>DROP TABLE</code>, the table with the same name is replaced: when in doubt, import into a new database.',
    'El volcado del panel, o uno de SQLite, MySQL / MariaDB, PostgreSQL, SQL Server o Microsoft Access, con sus vistas y sus triggers. Se traducen al SQL de aquí, y al terminar se dice qué no ha llegado igual. Si el volcado trae <code>DROP TABLE</code>, las tablas con el mismo nombre se sustituyen.'
        => 'The panel\'s own dump, or one from SQLite, MySQL / MariaDB, PostgreSQL, SQL Server or Microsoft Access, with its views and triggers. They are translated to the SQL used here, and at the end it says what did not arrive the same. If the dump contains <code>DROP TABLE</code>, the tables with the same name are replaced.',
    'Con el panel en la misma máquina que el motor es <strong>todo o nada</strong>: si algo falla, la base queda como estaba. Si no, lo anterior al fallo queda hecho y se dice dónde paró.'
        => 'With the panel on the same machine as the engine it is <strong>all or nothing</strong>: if something fails, the database is left as it was. Otherwise, what came before the failure is already done and it says where it stopped.',
    'Microsoft Access: script y limitaciones'
        => 'Microsoft Access: script and limitations',
    '<strong>Microsoft Access</strong>: <a href="{1}">descarga el script de PowerShell</a> (necesita <strong>Windows</strong>; botón derecho → «Ejecutar con PowerShell»). Vuelca un .mdb o .accdb a un fichero que se importa aquí, y carga en Access lo que exportes como «SQL: Microsoft Access». Con un .mdb no hace falta instalar nada; con un .accdb, si falta el controlador de Access, lo dice y da el enlace para descargarlo.'
        => '<strong>Microsoft Access</strong>: <a href="{1}">download the PowerShell script</a> (it needs <strong>Windows</strong>; right-click → «Run with PowerShell»). It dumps an .mdb or .accdb to a file you import here, and loads into Access what you export as «SQL: Microsoft Access». An .mdb needs nothing installed; with an .accdb, if the Access engine is missing, it says so and gives the link to download it.',
    'Las consultas guardadas van como CREATE VIEW, que Access solo admite en bases .mdb de Access 2000 a 2003 y .accdb, y solo con la sintaxis ANSI-92 (por ADO/OLEDB, o con la opción «Sintaxis compatible con SQL Server (ANSI 92)» de la base, desde Access 2002); con la de siempre da error, y en Access 97 o anterior no existe. En cualquier versión: pega lo que va detrás de AS en una consulta nueva y guárdala con el nombre de la vista, o carga el fichero con el script, que las crea como consultas guardadas sin CREATE VIEW. Lo que esta sintaxis no tiene va en una línea -- encima de su tabla o relación, para hacerlo a mano: «-- [tabla].[columna] DEFAULT valor» (en Access, vista Diseño de la tabla → Valor predeterminado) y «-- [relación] ON DELETE CASCADE» u ON UPDATE (Herramientas de base de datos → Relaciones → Exigir integridad referencial → Eliminar o Actualizar en cascada). Al importar este fichero en jsonSQLDBadmin, esas líneas se aplican solas.'
        => 'Saved queries go as CREATE VIEW, which Access only accepts in .mdb databases of Access 2000 to 2003 and in .accdb ones, and only in ANSI-92 syntax (through ADO/OLEDB, or with the database\'s «SQL Server Compatible Syntax (ANSI 92)» option, since Access 2002); in the usual syntax it is an error, and in Access 97 or earlier it does not exist. In any version: paste what follows AS into a new query and save it with the view\'s name, or load the file with the script, which creates them as saved queries without CREATE VIEW. What this syntax does not have goes in a -- line above its table or relationship, to do by hand: «-- [table].[column] DEFAULT value» (in Access, the table\'s Design view → Default Value) and «-- [relationship] ON DELETE CASCADE» or ON UPDATE (Database Tools → Relationships → Enforce Referential Integrity → Cascade Delete or Update). When this file is imported into jsonSQLDBadmin, those lines are applied on their own.',
    '<strong>CREATE VIEW no vale en todas las bases ni en todos los modos.</strong> Las consultas guardadas van como <code>CREATE VIEW [nombre] AS SELECT …</code>. Access lo admite en las bases .mdb de Access 2000 a 2003 (Jet 4.0) y en las .accdb (Access 2007 y posteriores), pero solo con la sintaxis ANSI-92: por ADO/OLEDB, o en la vista SQL si la base tiene activada «Sintaxis compatible con SQL Server (ANSI 92)» (opción que existe desde Access 2002; en Access 2010 y posteriores, Archivo → Opciones → Diseñadores de objetos). Con la sintaxis de siempre (ANSI-89) da error de sintaxis, y en una base de Access 97 o anterior (Jet 3) no existe. A mano, en cualquier versión: pega lo que va detrás de <code>AS</code> en una consulta nueva y guárdala con ese nombre. El script de PowerShell las crea como consultas guardadas sin usar CREATE VIEW, así que con él vale cualquier versión.'
        => '<strong>CREATE VIEW does not work in every database or mode.</strong> Saved queries go as <code>CREATE VIEW [name] AS SELECT …</code>. Access accepts it in .mdb databases of Access 2000 to 2003 (Jet 4.0) and in .accdb ones (Access 2007 and later), but only in ANSI-92 syntax: through ADO/OLEDB, or in the SQL view if the database has «SQL Server Compatible Syntax (ANSI 92)» turned on (an option that exists since Access 2002; in Access 2010 and later, File → Options → Object Designers). In the usual syntax (ANSI-89) it is a syntax error, and in an Access 97 or earlier database (Jet 3) it does not exist. By hand, in any version: paste what follows <code>AS</code> into a new query and save it with that name. The PowerShell script creates them as saved queries without CREATE VIEW, so with it any version works.',
];
