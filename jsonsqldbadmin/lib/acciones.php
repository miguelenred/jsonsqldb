<?php
declare(strict_types=1);

/**
 * Todas las acciones que modifican algo. Se llama desde index.php con el CSRF
 * ya comprobado. Cada acción hace su trabajo y redirige; los errores se
 * convierten en un mensaje para el usuario.
 *
 * Nunca se concatena un valor en la SQL: van siempre como parámetros ligados.
 * Los nombres de tabla y columna sí forman parte de la sentencia, así que se
 * validan con identificador() y se citan con cita().
 *
 * https://miguelenred.es/jsonsqldb
 */
function ejecutarAccion(string $accion): void
{
    $base  = post('db');
    $tabla = post('tabla');

    switch ($accion) {

        // ---------------- Bases de datos ----------------
        case 'crear_base':
            Auth::exigirAdmin();
            $nombre = nombreBase(post('nombre'));
            Api::sql('', 'CREATE DATABASE ' . cita($nombre));
            Audit::registrar('crear_base', $nombre, $nombre);
            flash('success', t('Base de datos \'{nombre}\' creada.', ['nombre' => $nombre]));
            redirigir(['p' => 'tablas', 'db' => $nombre]);

        case 'borrar_base':
            Auth::exigirAdmin();
            $nombre = nombreBase(post('nombre'));
            if (post('confirmacion') !== $nombre) {
                throw new RuntimeException(t('Para borrar la base hay que escribir su nombre exacto.'));
            }
            Api::sql('', 'DROP DATABASE ' . cita($nombre));
            Audit::registrar('borrar_base', $nombre, $nombre);
            flash('success', t('Base de datos \'{nombre}\' borrada.', ['nombre' => $nombre]));
            redirigir(['p' => 'bases']);

        // ---------------- Tablas ----------------
        case 'crear_tabla':
            Auth::exigirAdmin();
            $nombre  = identificador(post('nombre'), 'tabla');
            $activas = [];
            foreach ((array)($_POST['columnas'] ?? []) as $c) {
                if (is_array($c) && trim((string)($c['nombre'] ?? '')) !== '') {
                    $activas[] = $c;
                }
            }
            if ($activas === []) {
                throw new RuntimeException(t('La tabla necesita al menos una columna.'));
            }

            // Clave primaria compuesta: va a nivel de tabla, no en cada columna
            $pk = [];
            foreach ($activas as $c) {
                if (!empty($c['pk'])) { $pk[] = identificador(trim((string)$c['nombre']), 'columna'); }
            }
            $compuesta = count($pk) > 1;

            $cols = [];
            foreach ($activas as $c) {
                $cols[] = definicionColumna($c, !$compuesta);
            }
            if ($compuesta) {
                $cols[] = 'PRIMARY KEY (' . implode(', ', array_map('cita', $pk)) . ')';
            }
            Api::sql($base, 'CREATE TABLE ' . cita($nombre) . " (\n  " . implode(",\n  ", $cols) . "\n)");
            Audit::registrar('crear_tabla', $nombre, $base);
            flash('success', t('Tabla \'{nombre}\' creada.', ['nombre' => $nombre]));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $nombre]);

        case 'borrar_tabla':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            Api::sql($base, 'DROP TABLE ' . cita($tabla));
            Audit::registrar('borrar_tabla', $tabla, $base);
            flash('success', t('Tabla \'{tabla}\' borrada.', ['tabla' => $tabla]));
            redirigir(['p' => 'tablas', 'db' => $base]);

        case 'vaciar_tabla':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $r = Api::sql($base, 'DELETE FROM ' . cita($tabla));
            Audit::registrar('vaciar_tabla', $tabla, $base);
            flash('success', t('Tabla \'{tabla}\' vaciada ({r} fila(s)).', ['tabla' => $tabla, 'r' => $r['filas']]));
            redirigir(['p' => 'datos', 'db' => $base, 'tabla' => $tabla]);

        case 'renombrar_tabla':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $nuevo = identificador(post('nuevo'), 'tabla');
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' RENAME TO ' . cita($nuevo));
            Audit::registrar('renombrar_tabla', "$tabla → $nuevo", $base);
            flash('success', t('Tabla renombrada a \'{nuevo}\'.', ['nuevo' => $nuevo]));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $nuevo]);

        // ---------------- Columnas ----------------
        case 'anadir_columna':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $def = definicionColumna($_POST);
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' ADD COLUMN ' . $def);
            Audit::registrar('anadir_columna', "$tabla.$def", $base);
            flash('success', t('Columna añadida.'));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'editar_columna':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $col    = identificador(post('columna'), 'columna');
            $nombre = identificador(post('nombre'), 'columna');

            // El renombrado va primero: lo demás se aplica ya con el nombre nuevo
            if (strcasecmp($col, $nombre) !== 0) {
                Api::sql($base, 'ALTER TABLE ' . cita($tabla)
                       . ' RENAME COLUMN ' . cita($col) . ' TO ' . cita($nombre));
            }
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' MODIFY COLUMN '
                   . definicionColumna(array_merge($_POST, ['nombre' => $nombre])));

            Audit::registrar('editar_columna', "$tabla.$col" . ($col === $nombre ? '' : " → $nombre"), $base);
            flash('success', t('Columna guardada.'));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_columna':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $col = identificador(post('columna'), 'columna');
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' DROP COLUMN ' . cita($col));
            Audit::registrar('borrar_columna', "$tabla.$col", $base);
            flash('success', t('Columna \'{col}\' borrada.', ['col' => $col]));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        // ---------------- Restricciones ----------------
        case 'anadir_unica':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $cols = columnasSeleccionadas();
            $sql  = 'ALTER TABLE ' . cita($tabla) . ' ADD ';
            if (post('nombre') !== '') {
                $sql .= 'CONSTRAINT ' . cita(identificador(post('nombre'), 'restricción')) . ' ';
            }
            Api::sql($base, $sql . 'UNIQUE (' . implode(', ', array_map('cita', $cols)) . ')');
            Audit::registrar('anadir_unica', $tabla . ' (' . implode(',', $cols) . ')', $base);
            flash('success', t('Clave única añadida.'));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'anadir_fk':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $cols    = columnasSeleccionadas();
            $destino = identificador(post('tabla_destino'), 'tabla');
            $refs    = columnasSeleccionadas('referencias');
            if (count($refs) !== count($cols)) {
                throw new RuntimeException(t('La clave foránea necesita el mismo número de columnas a cada lado.'));
            }
            $sql = 'ALTER TABLE ' . cita($tabla) . ' ADD ';
            if (post('nombre') !== '') {
                $sql .= 'CONSTRAINT ' . cita(identificador(post('nombre'), 'restricción')) . ' ';
            }
            $sql .= 'FOREIGN KEY (' . implode(', ', array_map('cita', $cols)) . ') REFERENCES '
                  . cita($destino) . ' (' . implode(', ', array_map('cita', $refs)) . ')'
                  . ' ON DELETE ' . accionFk(post('on_delete'))
                  . ' ON UPDATE ' . accionFk(post('on_update'));
            Api::sql($base, $sql);
            Audit::registrar('anadir_fk', "$tabla → $destino", $base);
            flash('success', t('Clave foránea añadida.'));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'anadir_pk':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $cols = columnasSeleccionadas();
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' ADD PRIMARY KEY ('
                   . implode(', ', array_map('cita', $cols)) . ')');
            Audit::registrar('anadir_pk', $tabla . ' (' . implode(',', $cols) . ')', $base);
            flash('success', t('Clave primaria creada.'));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_pk':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' DROP PRIMARY KEY');
            Audit::registrar('borrar_pk', $tabla, $base);
            flash('success', t('Clave primaria eliminada.'));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_restriccion':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $nombre = identificador(post('nombre'), 'restricción');
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' DROP CONSTRAINT ' . cita($nombre));
            Audit::registrar('borrar_restriccion', "$tabla.$nombre", $base);
            flash('success', t('Restricción \'{nombre}\' eliminada.', ['nombre' => $nombre]));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        // ---------------- Restaurar desde ZIP ----------------
        case 'importar_zip':
            Auth::exigirAdmin();
            $nombre = identificador(post('nombre'), 'base de datos');

            if (mismoHostQueLaApi() === false) {
                throw new RuntimeException(t('Restaurar desde ZIP necesita que el panel y el motor estén en la misma máquina, porque escribe los ficheros directamente. Usa el volcado en SQL: se importa desde la página de la base y funciona entre máquinas distintas.'));
            }
            [$fichero, $original, $porTrozos] = Subidas::fichero('zip');
            try {
                // rutaDeLaBase() ya comprueba que la carpeta existe y que el motor
                // está en esta máquina, y explica el motivo si no es así
                $resumen = Importar::zip($fichero, $nombre, rutaDeLaBase($nombre));
            } finally {
                Subidas::borrar($fichero, $porTrozos);
            }
            Audit::registrar('importar_zip', $resumen . ' (' . $original . ')', $nombre);
            flash('success', t('Base \'{nombre}\' restaurada. {resumen}', ['nombre' => $nombre, 'resumen' => $resumen]));
            redirigir(['p' => 'bases']);

        case 'importar_sql':
        case 'importar_csv':
        case 'importar_xlsx':
            Auth::exigirAdmin();
            @set_time_limit(0);                    // un fichero grande tarda lo que tarda
            $nombre = nombreBase(post('db'));
            [$fichero, $original, $porTrozos] = Subidas::fichero('fichero');
            try {
                // Con la carpeta de la base a mano (misma máquina), todo o nada
                $ruta = rutaDeLaBaseSiSeAlcanza($nombre);
                if ($accion === 'importar_sql') {
                    $resumen = Importar::sql($fichero, $nombre, (string)post('formato', 'auto'), $ruta);
                } elseif ($accion === 'importar_csv') {
                    $resumen = Importar::csv($fichero, $nombre, identificador(post('tabla'), 'tabla'), $ruta);
                } else {
                    $resumen = Importar::xlsx($fichero, $nombre, identificador(post('tabla'), 'tabla'), $ruta);
                }
            } finally {
                Subidas::borrar($fichero, $porTrozos);
            }
            Audit::registrar($accion, $resumen . ' (' . $original . ')', $nombre);
            flash('success', $resumen);
            redirigir(['p' => 'tablas', 'db' => $nombre]);

        case 'subir_trozo':
            // Un trozo de un fichero que se sube por partes (ver Subidas): la
            // respuesta es JSON, para el navegador, nunca una redirección
            header('Content-Type: application/json; charset=UTF-8');
            try {
                Auth::exigirAdmin();
                $recibido = Subidas::recibirTrozo(post('id'), (int)post('desde'), $_FILES['trozo'] ?? null);
                echo json_encode(['recibido' => $recibido]);
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;

        // ---------------- Copias programadas ----------------
        case 'programar_copia':
            Auth::exigirAdmin();
            $p = Copias::anadir($_POST);
            Audit::registrar('programar_copia', $p['frecuencia'] . ' · ' . $p['formato'], $p['base']);
            flash('success', t('Copia de \'{base}\' programada.', ['base' => $p['base']]));
            redirigir(['p' => 'copias']);

        case 'borrar_programacion':
            Auth::exigirAdmin();
            Copias::borrar(post('id'));
            Audit::registrar('borrar_programacion', post('id'));
            flash('success', t('Programación quitada.'));
            redirigir(['p' => 'copias']);

        case 'copia_ahora':
            Auth::exigirAdmin();
            @set_time_limit(0);
            flash('success', Copias::ejecutar(post('id')));
            redirigir(['p' => 'copias']);

        case 'descargar_copia':
            Auth::exigirAdmin();
            $ruta = Copias::ruta(nombreBase(post('nombre')), post('fichero'));
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: ' . (str_ends_with($ruta, '.zip') ? 'application/zip' : 'text/plain; charset=UTF-8'));
            header('Content-Disposition: attachment; filename="' . basename($ruta) . '"');
            header('Content-Length: ' . (string)filesize($ruta));
            header('Cache-Control: no-store');
            readfile($ruta);
            exit;

        case 'borrar_copia':
            Auth::exigirAdmin();
            $nombre = nombreBase(post('nombre'));
            @unlink(Copias::ruta($nombre, post('fichero')));
            Audit::registrar('borrar_copia', post('fichero'), $nombre);
            flash('success', t('Copia borrada.'));
            redirigir(['p' => 'copias']);

        case 'ejecutar_copias':
            // La pide el navegador por su cuenta cuando la página avisa de que
            // hay copias pendientes (assets/panel.js): sigue aunque se cierre
            // la página, y suelta la sesión para no bloquear las siguientes
            header('Content-Type: application/json; charset=UTF-8');
            ignore_user_abort(true);
            @set_time_limit(0);
            session_write_close();
            try {
                echo json_encode(['hechas' => Copias::ejecutarPendientes()], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;

        // ---------------- Vistas ----------------
        case 'crear_vista':
            Auth::exigirAdmin();
            $nombre = identificador(post('nombre'), 'vista');
            $sql    = trim(post('sql'));
            if (!preg_match('/^\s*SELECT\b/i', $sql)) {
                throw new RuntimeException(t('Una vista tiene que ser un SELECT.'));
            }
            Api::sql($base, 'CREATE VIEW ' . cita($nombre) . ' AS ' . rtrim($sql, "; \t\n"));
            Audit::registrar('crear_vista', $nombre, $base);
            flash('success', t('Vista \'{nombre}\' creada.', ['nombre' => $nombre]));
            redirigir(['p' => 'vistas', 'db' => $base]);

        case 'borrar_vista':
            Auth::exigirAdmin();
            $nombre = identificador(post('nombre'), 'vista');
            Api::sql($base, 'DROP VIEW ' . cita($nombre));
            Audit::registrar('borrar_vista', $nombre, $base);
            flash('success', t('Vista \'{nombre}\' borrada.', ['nombre' => $nombre]));
            redirigir(['p' => 'vistas', 'db' => $base]);

        // ---------------- Triggers ----------------
        case 'crear_trigger':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $nombre = identificador(post('nombre'), 'trigger');
            $timing = strtoupper(post('timing')) === 'BEFORE' ? 'BEFORE' : 'AFTER';
            $evento = strtoupper(post('evento'));
            if (!in_array($evento, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                throw new RuntimeException(t('El evento del trigger debe ser INSERT, UPDATE o DELETE.'));
            }
            $cuerpo = trim(post('cuerpo'));
            if ($cuerpo === '') {
                throw new RuntimeException(t('El trigger necesita al menos una sentencia.'));
            }
            if (!str_ends_with($cuerpo, ';')) {
                $cuerpo .= ';';
            }
            $cuando = trim(post('cuando'));

            $sql = 'CREATE TRIGGER ' . cita($nombre) . "\n"
                 . $timing . ' ' . $evento . ' ON ' . cita($tabla) . "\n"
                 . ($cuando === '' ? '' : 'WHEN ' . $cuando . "\n")
                 . "BEGIN\n" . $cuerpo . "\nEND";

            Api::sql($base, $sql);
            Audit::registrar('crear_trigger', "$nombre · $timing $evento en $tabla", $base);
            flash('success', t('Trigger \'{nombre}\' creado.', ['nombre' => $nombre]));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_trigger':
            Auth::exigirAdmin();
            $nombre = identificador(post('nombre'), 'trigger');
            Api::sql($base, 'DROP TRIGGER ' . cita($nombre));
            Audit::registrar('borrar_trigger', $nombre, $base);
            flash('success', t('Trigger \'{nombre}\' borrado.', ['nombre' => $nombre]));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        // ---------------- Índices ----------------
        case 'crear_indice':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $nombre = identificador(post('nombre'), 'índice');
            $cols   = (array)($_POST['columnas'] ?? []);
            if ($cols === []) {
                throw new RuntimeException(t('Elige al menos una columna para el índice.'));
            }
            foreach ($cols as $i => $c) {
                $cols[$i] = cita(identificador((string)$c, 'columna'));
            }
            Api::sql($base, 'CREATE INDEX ' . cita($nombre) . ' ON ' . cita($tabla)
                          . ' (' . implode(', ', $cols) . ')');
            Audit::registrar('crear_indice', $tabla . '.' . $nombre, $base);
            flash('success', t('Índice \'{nombre}\' creado.', ['nombre' => $nombre]));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_indice':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $nombre = identificador(post('nombre'), 'índice');
            Api::sql($base, 'DROP INDEX ' . cita($nombre) . ' ON ' . cita($tabla));
            Audit::registrar('borrar_indice', $tabla . '.' . $nombre, $base);
            flash('success', t('Índice \'{nombre}\' borrado.', ['nombre' => $nombre]));
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        // ---------------- Filas ----------------
        case 'insertar_fila':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            [$cols, $vals] = valoresDelFormulario();
            if ($cols === []) {
                throw new RuntimeException(t('No hay ningún valor que insertar.'));
            }
            Api::sql($base,
                'INSERT INTO ' . cita($tabla) . ' (' . implode(', ', array_map('cita', $cols)) . ') VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals);
            Audit::registrar('insertar_fila', $tabla, $base);
            flash('success', t('Fila insertada.'));
            redirigir(['p' => 'datos', 'db' => $base, 'tabla' => $tabla]);

        case 'actualizar_fila':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            [$cols, $vals] = valoresDelFormulario();
            if ($cols === []) {
                throw new RuntimeException(t('No hay ningún valor que guardar.'));
            }
            [$donde, $clave] = condicionClave();
            $sets = [];
            foreach ($cols as $c) { $sets[] = cita($c) . ' = ?'; }
            Api::sql($base, 'UPDATE ' . cita($tabla) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $donde,
                     array_merge($vals, $clave));
            Audit::registrar('actualizar_fila', $tabla, $base);
            flash('success', t('Fila guardada.'));
            redirigir(['p' => 'datos', 'db' => $base, 'tabla' => $tabla, 'pag' => get('pag', '1')]);

        case 'borrar_fila':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            [$donde, $clave] = condicionClave();
            $r = Api::sql($base, 'DELETE FROM ' . cita($tabla) . ' WHERE ' . $donde, $clave);
            Audit::registrar('borrar_fila', $tabla, $base);
            flash('success', t('{r} fila(s) borrada(s).', ['r' => $r['filas']]));
            redirigir(['p' => 'datos', 'db' => $base, 'tabla' => $tabla]);

        // ---------------- Exportación ----------------
        case 'exportar':
            @set_time_limit(0);                    // una exportación grande tarda lo que tarda
            $formato = post('formato') === 'sql' ? 'sql' : 'csv';
            $sql     = post('sql');

            $params = [];
            if ($sql === '') {
                // Exportación de la tabla, con el mismo filtro y orden de la pantalla
                identificador($tabla, 'tabla');
                [$donde, $params] = condicionFiltro(
                    Api::sql($base, 'SHOW SCHEMA ' . cita($tabla)),
                    post('q')
                );
                $sql   = 'SELECT * FROM ' . cita($tabla) . $donde;
                $orden = post('orden');
                if ($orden !== '') {
                    $sql .= ' ORDER BY ' . cita(identificador($orden, 'columna'))
                          . (strtoupper(post('dir')) === 'DESC' ? ' DESC' : ' ASC');
                }
                $nombre = $tabla;
            } else {
                // Exportación del resultado de una consulta del editor
                if (!preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
                    throw new RuntimeException(t('Solo se pueden exportar los resultados de SELECT y SHOW.'));
                }
                $nombre = tablaDeLaConsulta($sql);
            }

            // SHOW devuelve poco y no admite LIMIT: entero. Un SELECT, por lotes
            // si no cabe en memoria (ver Lotes)
            $filas = preg_match('/^\s*SHOW\b/i', $sql) ? Api::sql($base, $sql, $params) : Lotes::filas($base, $sql, $params);
            if (is_array($filas) && isset($filas['success'])) {
                throw new RuntimeException(t('Esa sentencia no devuelve filas que exportar.'));
            }

            Audit::registrar('exportar_' . $formato, $nombre, $base);

            if ($formato === 'sql') {
                Exportar::inserts($filas, $nombre);
            }
            Exportar::csv($filas, $nombre);
            // Exportar termina la petición

        case 'exportar_base':
            @set_time_limit(0);
            $nombre  = nombreBase(post('nombre'));
            $formato = in_array(post('formato'), ['zip', 'mysql', 'postgresql', 'sqlserver', 'access'], true) ? post('formato') : 'sql';

            if ($formato === 'zip') {
                // El ZIP lee los ficheros directamente, sin pasar por la API: antes
                // se pregunta a la API por la base, con las credenciales de quien
                // pide, para que una clave limitada a otras bases no pueda
                // llevarse esta
                Api::sql($nombre, 'SHOW TABLES');
                $ruta = rutaDeLaBase($nombre);
                Audit::registrar('exportar_zip', $nombre, $nombre);
                Exportar::zip($nombre, $ruta);       // termina la petición
            }

            $tablas = Exportar::tablasDe($nombre);
            Audit::registrar('exportar_base', $nombre . ' · ' . array_sum(array_column($tablas, 'n')) . ' fila(s)', $nombre);
            Exportar::base($nombre, $tablas, Api::sql($nombre, 'SHOW TRIGGERS'), Api::sql($nombre, 'SHOW VIEWS'),
                           Api::sql($nombre, 'SHOW INDEXES'), $formato === 'sql' ? 'sqlite' : $formato, Exportar::cargador($nombre, $tablas));
            // Exportar termina la petición

        // ---------------- Usuarios ----------------
        case 'guardar_configuracion':
            Auth::exigirAdmin();
            $cambios = Instalador::guardarConfiguracion($_POST);
            // En la auditoría van los nombres de lo cambiado, nunca los valores:
            // entre ellos puede haber claves
            Audit::registrar('configuracion', $cambios === [] ? 'sin cambios' : implode(', ', $cambios));
            flash($cambios === [] ? 'info' : 'success', $cambios === []
                ? t('No había nada que cambiar.')
                : t('Configuración guardada ({n} cambio(s)). Se aplica desde esta página.', ['n' => count($cambios)]));
            redirigir(['p' => 'configuracion']);

        case 'crear_usuario':
            Auth::exigirAdmin();
            $nombre = Auth::crear(post('usuario'), (string)($_POST['clave'] ?? ''), post('rol'));
            Audit::registrar('crear_usuario', $nombre);
            flash('success', t('Usuario \'{nombre}\' creado.', ['nombre' => $nombre]));
            redirigir(['p' => 'usuarios']);

        case 'borrar_usuario':
            Auth::exigirAdmin();
            $nombre = post('usuario');
            if (strcasecmp($nombre, (string)Auth::usuario()['usuario']) === 0) {
                throw new RuntimeException(t('No puedes borrar tu propio usuario.'));
            }
            Auth::borrar($nombre);
            Audit::registrar('borrar_usuario', $nombre);
            flash('success', t('Usuario \'{nombre}\' borrado.', ['nombre' => $nombre]));
            redirigir(['p' => 'usuarios']);

        case 'cambiar_clave':
            $propio = (string)Auth::usuario()['usuario'];
            $nombre = post('usuario', $propio);
            if (strcasecmp($nombre, $propio) !== 0) {
                Auth::exigirAdmin();
            }
            Auth::cambiarClave($nombre, (string)($_POST['clave'] ?? ''));
            if (strcasecmp($nombre, $propio) === 0) {
                Auth::renovarHuella();              // las demás sesiones de ese usuario sí caducan
            }
            Audit::registrar('cambiar_clave', $nombre);
            flash('success', t('Contraseña cambiada.'));
            redirigir(['p' => 'usuarios']);
    }

    throw new RuntimeException(t('Acción desconocida: \'{accion}\'', ['accion' => $accion]));
}

// ----------------------------------------------------------------------
// Auxiliares
// ----------------------------------------------------------------------

/**
 * Monta la definición de una columna a partir de los campos del formulario.
 * $pkEnLinea a false cuando la clave primaria es compuesta y va aparte.
 */
function definicionColumna(array $c, bool $pkEnLinea = true): string
{
    $nombre = identificador(trim((string)($c['nombre'] ?? '')), 'columna');
    $def    = cita($nombre) . ' ' . tipoSql(
        (string)($c['tipo'] ?? 'TEXT'),
        (string)($c['longitud'] ?? ''),
        (string)($c['escala'] ?? '')
    );

    if (!empty($c['pk']) && $pkEnLinea) { $def .= ' PRIMARY KEY'; }
    if (!empty($c['auto'])) {
        if (strtoupper(trim((string)($c['tipo'] ?? ''))) !== 'INTEGER') {
            throw new RuntimeException(t('AUTOINCREMENT solo vale en columnas INTEGER (\'{nombre}\').', ['nombre' => $nombre]));
        }
        if (empty($c['pk']) || !$pkEnLinea) {
            throw new RuntimeException(t('AUTOINCREMENT necesita que la columna sea clave primaria simple.'));
        }
        $def .= ' AUTOINCREMENT';
    }
    if (!empty($c['notnull'])) { $def .= ' NOT NULL'; }
    if (!empty($c['unico']))   { $def .= ' UNIQUE'; }

    $defecto = trim((string)($c['defecto'] ?? ''));
    if ($defecto !== '') {
        // El valor por defecto forma parte de la estructura: se admite solo un
        // literal simple, nunca una expresión.
        if (is_numeric($defecto)) {
            $def .= ' DEFAULT ' . $defecto;
        } elseif (strcasecmp($defecto, 'NULL') === 0) {
            $def .= ' DEFAULT NULL';
        } else {
            $def .= " DEFAULT '" . str_replace("'", "''", $defecto) . "'";
        }
    }
    return $def;
}

/** Columnas marcadas en un formulario de restricción. */
function columnasSeleccionadas(string $campo = 'columnas'): array
{
    $cols = [];
    foreach ((array)($_POST[$campo] ?? []) as $c) {
        if (is_scalar($c) && trim((string)$c) !== '') {
            $cols[] = identificador(trim((string)$c), 'columna');
        }
    }
    if ($cols === []) {
        throw new RuntimeException(t('Hay que elegir al menos una columna.'));
    }
    return $cols;
}

/** Nombre de tabla que se usa al exportar el resultado de una consulta. */
function tablaDeLaConsulta(string $sql): string
{
    return preg_match('/\bFROM\s+"?([A-Za-z_][A-Za-z0-9_]*)"?/i', $sql, $m) ? $m[1] : 'consulta';
}

function accionFk(string $valor): string
{
    $valor = strtoupper(trim($valor));
    $ok    = ['NO ACTION', 'CASCADE', 'RESTRICT', 'SET NULL', 'SET DEFAULT'];
    return in_array($valor, $ok, true) ? $valor : 'NO ACTION';
}

/**
 * Columnas y valores de un formulario de fila.
 * Devuelve [columnas, valores]; los valores van como parámetros ligados.
 *
 * Una casilla vacía significa «sin valor», no cadena vacía: la columna no se
 * manda, y así el motor aplica el autoincremento o el valor por defecto en
 * lugar de recibir '' y protestar por el tipo. La excepción es el texto, donde
 * la cadena vacía sí es un valor legítimo.
 *
 * @return array{0:string[],1:array}
 */
function valoresDelFormulario(): array
{
    $cols  = [];
    $vals  = [];
    $nulos   = (array)($_POST['nulo'] ?? []);
    $autos   = (array)($_POST['auto'] ?? []);
    $tipos   = (array)($_POST['tipo'] ?? []);
    $noNulas = (array)($_POST['nn']   ?? []);

    foreach ((array)($_POST['valor'] ?? []) as $col => $v) {
        $col = identificador((string)$col, 'columna');

        if (isset($autos[$col])) {
            // Columna automática: la pone la base. Ni valor ni NULL.
            continue;
        }
        if (isset($nulos[$col])) {
            if (isset($noNulas[$col])) {
                throw new RuntimeException(t('La columna \'{col}\' no admite nulos.', ['col' => $col]));
            }
            $cols[] = $col;
            $vals[] = null;
            continue;
        }
        if (!is_scalar($v)) {
            continue;
        }
        $texto = (string)$v;
        if ($texto === '' && strtoupper((string)($tipos[$col] ?? 'TEXT')) !== 'TEXT') {
            continue;                              // sin valor: que decida el motor
        }
        $cols[] = $col;
        $vals[] = $texto;
    }
    return [$cols, $vals];
}

/**
 * WHERE que identifica una fila por su clave primaria.
 *
 * @return array{0:string,1:array}
 */
function condicionClave(): array
{
    $partes = [];
    $vals   = [];
    foreach ((array)($_POST['pk'] ?? []) as $col => $v) {
        $col      = identificador((string)$col, 'columna');
        $partes[] = cita($col) . ' = ?';
        $vals[]   = is_scalar($v) ? (string)$v : null;
    }
    if ($partes === []) {
        throw new RuntimeException(t('Esta tabla no tiene clave primaria: no se puede identificar la fila.'));
    }
    return [implode(' AND ', $partes), $vals];
}
