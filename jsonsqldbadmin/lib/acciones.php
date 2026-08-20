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
            flash('success', "Base de datos '$nombre' creada.");
            redirigir(['p' => 'tablas', 'db' => $nombre]);

        case 'borrar_base':
            Auth::exigirAdmin();
            $nombre = nombreBase(post('nombre'));
            if (post('confirmacion') !== $nombre) {
                throw new RuntimeException('Para borrar la base hay que escribir su nombre exacto.');
            }
            Api::sql('', 'DROP DATABASE ' . cita($nombre));
            Audit::registrar('borrar_base', $nombre, $nombre);
            flash('success', "Base de datos '$nombre' borrada.");
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
                throw new RuntimeException('La tabla necesita al menos una columna.');
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
            flash('success', "Tabla '$nombre' creada.");
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $nombre]);

        case 'borrar_tabla':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            Api::sql($base, 'DROP TABLE ' . cita($tabla));
            Audit::registrar('borrar_tabla', $tabla, $base);
            flash('success', "Tabla '$tabla' borrada.");
            redirigir(['p' => 'tablas', 'db' => $base]);

        case 'vaciar_tabla':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $r = Api::sql($base, 'DELETE FROM ' . cita($tabla));
            Audit::registrar('vaciar_tabla', $tabla, $base);
            flash('success', "Tabla '$tabla' vaciada ({$r['filas']} fila(s)).");
            redirigir(['p' => 'datos', 'db' => $base, 'tabla' => $tabla]);

        case 'renombrar_tabla':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $nuevo = identificador(post('nuevo'), 'tabla');
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' RENAME TO ' . cita($nuevo));
            Audit::registrar('renombrar_tabla', "$tabla → $nuevo", $base);
            flash('success', "Tabla renombrada a '$nuevo'.");
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $nuevo]);

        // ---------------- Columnas ----------------
        case 'anadir_columna':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $def = definicionColumna($_POST);
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' ADD COLUMN ' . $def);
            Audit::registrar('anadir_columna', "$tabla.$def", $base);
            flash('success', 'Columna añadida.');
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
            flash('success', 'Columna guardada.');
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_columna':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $col = identificador(post('columna'), 'columna');
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' DROP COLUMN ' . cita($col));
            Audit::registrar('borrar_columna', "$tabla.$col", $base);
            flash('success', "Columna '$col' borrada.");
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
            flash('success', 'Clave única añadida.');
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'anadir_fk':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $cols    = columnasSeleccionadas();
            $destino = identificador(post('tabla_destino'), 'tabla');
            $refs    = columnasSeleccionadas('referencias');
            if (count($refs) !== count($cols)) {
                throw new RuntimeException('La clave foránea necesita el mismo número de columnas a cada lado.');
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
            flash('success', 'Clave foránea añadida.');
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'anadir_pk':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $cols = columnasSeleccionadas();
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' ADD PRIMARY KEY ('
                   . implode(', ', array_map('cita', $cols)) . ')');
            Audit::registrar('anadir_pk', $tabla . ' (' . implode(',', $cols) . ')', $base);
            flash('success', 'Clave primaria creada.');
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_pk':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' DROP PRIMARY KEY');
            Audit::registrar('borrar_pk', $tabla, $base);
            flash('success', 'Clave primaria eliminada.');
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_restriccion':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $nombre = identificador(post('nombre'), 'restricción');
            Api::sql($base, 'ALTER TABLE ' . cita($tabla) . ' DROP CONSTRAINT ' . cita($nombre));
            Audit::registrar('borrar_restriccion', "$tabla.$nombre", $base);
            flash('success', "Restricción '$nombre' eliminada.");
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        // ---------------- Triggers ----------------
        case 'crear_trigger':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            $nombre = identificador(post('nombre'), 'trigger');
            $timing = strtoupper(post('timing')) === 'BEFORE' ? 'BEFORE' : 'AFTER';
            $evento = strtoupper(post('evento'));
            if (!in_array($evento, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                throw new RuntimeException('El evento del trigger debe ser INSERT, UPDATE o DELETE.');
            }
            $cuerpo = trim(post('cuerpo'));
            if ($cuerpo === '') {
                throw new RuntimeException('El trigger necesita al menos una sentencia.');
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
            flash('success', "Trigger '$nombre' creado.");
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        case 'borrar_trigger':
            Auth::exigirAdmin();
            $nombre = identificador(post('nombre'), 'trigger');
            Api::sql($base, 'DROP TRIGGER ' . cita($nombre));
            Audit::registrar('borrar_trigger', $nombre, $base);
            flash('success', "Trigger '$nombre' borrado.");
            redirigir(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla]);

        // ---------------- Filas ----------------
        case 'insertar_fila':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            [$cols, $vals] = valoresDelFormulario();
            if ($cols === []) {
                throw new RuntimeException('No hay ningún valor que insertar.');
            }
            Api::sql($base,
                'INSERT INTO ' . cita($tabla) . ' (' . implode(', ', array_map('cita', $cols)) . ') VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals);
            Audit::registrar('insertar_fila', $tabla, $base);
            flash('success', 'Fila insertada.');
            redirigir(['p' => 'datos', 'db' => $base, 'tabla' => $tabla]);

        case 'actualizar_fila':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            [$cols, $vals] = valoresDelFormulario();
            if ($cols === []) {
                throw new RuntimeException('No hay ningún valor que guardar.');
            }
            [$donde, $clave] = condicionClave();
            $sets = [];
            foreach ($cols as $c) { $sets[] = cita($c) . ' = ?'; }
            Api::sql($base, 'UPDATE ' . cita($tabla) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $donde,
                     array_merge($vals, $clave));
            Audit::registrar('actualizar_fila', $tabla, $base);
            flash('success', 'Fila guardada.');
            redirigir(['p' => 'datos', 'db' => $base, 'tabla' => $tabla, 'pag' => get('pag', '1')]);

        case 'borrar_fila':
            Auth::exigirAdmin();
            identificador($tabla, 'tabla');
            [$donde, $clave] = condicionClave();
            $r = Api::sql($base, 'DELETE FROM ' . cita($tabla) . ' WHERE ' . $donde, $clave);
            Audit::registrar('borrar_fila', $tabla, $base);
            flash('success', "{$r['filas']} fila(s) borrada(s).");
            redirigir(['p' => 'datos', 'db' => $base, 'tabla' => $tabla]);

        // ---------------- Exportación ----------------
        case 'exportar':
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
                    throw new RuntimeException('Solo se pueden exportar los resultados de SELECT y SHOW.');
                }
                $nombre = tablaDeLaConsulta($sql);
            }

            $filas = Api::sql($base, $sql, $params);
            if (isset($filas['success'])) {
                throw new RuntimeException('Esa sentencia no devuelve filas que exportar.');
            }
            if (count($filas) > ADMIN_EXPORT_MAX) {
                throw new RuntimeException(
                    'La exportación supera el tope de ' . number_format((int)ADMIN_EXPORT_MAX, 0, ',', '.')
                    . ' filas (ADMIN_EXPORT_MAX). Acota la consulta con WHERE o LIMIT.'
                );
            }

            Audit::registrar('exportar_' . $formato, $nombre . ' · ' . count($filas) . ' fila(s)', $base);

            if ($formato === 'sql') {
                Exportar::inserts($filas, $nombre);
            }
            Exportar::csv($filas, $nombre);
            // Exportar termina la petición

        case 'exportar_base':
            $nombre  = nombreBase(post('nombre'));
            $formato = post('formato') === 'zip' ? 'zip' : 'sql';

            if ($formato === 'zip') {
                $ruta = rutaDeLaBase($nombre);
                Audit::registrar('exportar_zip', $nombre, $nombre);
                Exportar::zip($nombre, $ruta);       // termina la petición
            }

            $tablas = [];
            $filas  = 0;
            foreach (Api::sql($nombre, 'SHOW TABLES') as $t) {
                $tabla = (string)$t['tabla'];
                $datos = Api::sql($nombre, 'SELECT * FROM ' . cita($tabla));
                $filas += count($datos);
                if ($filas > ADMIN_EXPORT_MAX) {
                    throw new RuntimeException(
                        'El volcado supera el tope de ' . number_format((int)ADMIN_EXPORT_MAX, 0, ',', '.')
                        . ' filas (ADMIN_EXPORT_MAX). Exporta las tablas por separado o usa el ZIP.'
                    );
                }
                $tablas[] = [
                    'tabla'    => $tabla,
                    'columnas' => Api::sql($nombre, 'SHOW SCHEMA ' . cita($tabla)),
                    'claves'   => Api::sql($nombre, 'SHOW KEYS FROM ' . cita($tabla)),
                    'filas'    => $datos,
                ];
            }
            Audit::registrar('exportar_base', $nombre . ' · ' . $filas . ' fila(s)', $nombre);
            Exportar::base($nombre, $tablas, Api::sql($nombre, 'SHOW TRIGGERS'));
            // Exportar termina la petición

        // ---------------- Usuarios ----------------
        case 'crear_usuario':
            Auth::exigirAdmin();
            $nombre = Auth::crear(post('usuario'), (string)($_POST['clave'] ?? ''), post('rol'));
            Audit::registrar('crear_usuario', $nombre);
            flash('success', "Usuario '$nombre' creado.");
            redirigir(['p' => 'usuarios']);

        case 'borrar_usuario':
            Auth::exigirAdmin();
            $nombre = post('usuario');
            if (strcasecmp($nombre, (string)Auth::usuario()['usuario']) === 0) {
                throw new RuntimeException('No puedes borrar tu propio usuario.');
            }
            Auth::borrar($nombre);
            Audit::registrar('borrar_usuario', $nombre);
            flash('success', "Usuario '$nombre' borrado.");
            redirigir(['p' => 'usuarios']);

        case 'cambiar_clave':
            $propio = (string)Auth::usuario()['usuario'];
            $nombre = post('usuario', $propio);
            if (strcasecmp($nombre, $propio) !== 0) {
                Auth::exigirAdmin();
            }
            Auth::cambiarClave($nombre, (string)($_POST['clave'] ?? ''));
            Audit::registrar('cambiar_clave', $nombre);
            flash('success', 'Contraseña cambiada.');
            redirigir(['p' => 'usuarios']);
    }

    throw new RuntimeException("Acción desconocida: '$accion'");
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
            throw new RuntimeException("AUTOINCREMENT solo vale en columnas INTEGER ('$nombre').");
        }
        if (empty($c['pk']) || !$pkEnLinea) {
            throw new RuntimeException('AUTOINCREMENT necesita que la columna sea clave primaria simple.');
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
        throw new RuntimeException('Hay que elegir al menos una columna.');
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
                throw new RuntimeException("La columna '$col' no admite nulos.");
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
        throw new RuntimeException('Esta tabla no tiene clave primaria: no se puede identificar la fila.');
    }
    return [implode(' AND ', $partes), $vals];
}
