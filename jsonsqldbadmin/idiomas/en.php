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
    'El volcado del panel, o uno de SQLite (<code>sqlite3 base.db .dump</code>), MySQL / MariaDB (<code>mysqldump</code>), PostgreSQL (<code>pg_dump</code>, en texto) o SQL Server (el script de «Generar scripts» de Management Studio, con esquema y datos). Se traducen al SQL de aquí, y al terminar se dice qué no ha llegado igual (un <code>CHECK</code>, un <code>ENUM</code>…). Si el volcado trae <code>DROP TABLE</code>, las tablas con el mismo nombre se sustituyen. <strong>No hay transacciones</strong>: si una sentencia falla, las anteriores ya están hechas y se dice cuál era.'
        => 'The panel\'s dump, or one from SQLite (<code>sqlite3 base.db .dump</code>), MySQL / MariaDB (<code>mysqldump</code>), PostgreSQL (<code>pg_dump</code>, plain format) or SQL Server (Management Studio\'s «Generate Scripts», with schema and data). They are translated into the SQL used here, and at the end the panel says what did not arrive the same (a <code>CHECK</code>, an <code>ENUM</code>…). If the dump contains <code>DROP TABLE</code>, tables with the same name are replaced. <strong>There are no transactions</strong>: if a statement fails, the previous ones are already done and the panel says which one it was.',
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
    'No se pudo apartar la base actual antes de restaurar. Comprueba los permisos de escritura en la carpeta de datos.'
        => 'The current database could not be set aside before restoring. Check the write permissions on the data folder.',
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
    'Una o varias sentencias separadas por <code>;</code>. Dentro puedes usar <code>NEW.columna</code> (el valor que entra, en INSERT y UPDATE), <code>OLD.columna</code> (el que había, en UPDATE y DELETE) y <code>RAISE(ABORT, \'mensaje\')</code> para cancelar la operación con un error.'
        => 'One or more statements separated by <code>;</code>. Inside you can use <code>NEW.columna</code> (the incoming value, in INSERT and UPDATE), <code>OLD.columna</code> (the previous one, in UPDATE and DELETE) and <code>RAISE(ABORT, \'mensaje\')</code> to cancel the operation with an error.',
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
    'Vistas, triggers, funciones y procedimientos saltados: su SQL es de otro dialecto'
        => 'Views, triggers, functions and procedures skipped: their SQL is another dialect',
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
    'escritas en el SQL de jsonSQLDB; revísalas antes de crearlas'
        => 'written in jsonSQLDB\'s SQL; review them before creating them',
    'escritos en el SQL de jsonSQLDB; hay que reescribirlos para este motor'
        => 'written in jsonSQLDB\'s SQL; they have to be rewritten for this engine',
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
];
