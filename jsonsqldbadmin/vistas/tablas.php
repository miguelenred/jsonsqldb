<?php
$tablas = Api::sql($base, 'SHOW TABLES');
usort($tablas, static fn($a, $b) => strcasecmp((string)$a['tabla'], (string)$b['tabla']));
?>
<div class="page-head">
  <div>
    <h1><?= h($base) ?></h1>
    <p><?= count($tablas) ?> <?= t('{1} en esta base de datos', [1 => count($tablas) === 1 ? 'tabla' : 'tablas']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary" href="<?= h(url(['p' => 'sql', 'db' => $base])) ?>"><?= icono('terminal') ?> <?= h(t('Consola SQL')) ?></a>
    <?php if (Auth::esAdmin()): ?>
      <a class="btn btn-primary" href="<?= h(url(['p' => 'crear_tabla', 'db' => $base])) ?>"><?= icono('plus') ?> <?= h(t('Nueva tabla')) ?></a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <?php if ($tablas === []): ?>
      <p class="text-body-secondary m-3"><?= h(t('Esta base no tiene tablas todavía.')) ?></p>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead><tr>
          <th><?= h(t('Tabla')) ?></th><th class="text-end"><?= h(t('Columnas')) ?></th><th class="text-end"><?= h(t('Filas')) ?></th>
          <th><?= h(t('Creada')) ?></th><th class="text-end"><?= h(t('Acciones')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($tablas as $t): $n = (string)$t['tabla']; ?>
          <tr>
            <td><a href="<?= h(url(['p' => 'datos', 'db' => $base, 'tabla' => $n])) ?>">
                <?= icono('table') ?> <?= h($n) ?></a></td>
            <td class="text-end"><?= (int)$t['columnas'] ?></td>
            <td class="text-end"><?= number_format((int)$t['filas'], 0, ',', '.') ?></td>
            <td class="text-body-secondary small"><?= h($t['creada'] ?? '') ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= h(url(['p' => 'datos', 'db' => $base, 'tabla' => $n])) ?>"><?= h(t('Datos')) ?></a>
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= h(url(['p' => 'estructura', 'db' => $base, 'tabla' => $n])) ?>"><?= h(t('Estructura')) ?></a>
              <?php if (Auth::esAdmin()): ?>
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                        data-bs-target="#bt<?= h(md5($n)) ?>"><?= icono('trash') ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::esAdmin()): ?>
<?php require __DIR__ . '/guia_volcados.php'; ?>
<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><?= icono('upload') ?> <?= h(t('Importar un volcado SQL')) ?></div>
      <div class="card-body">
        <p class="small text-body-secondary"><?= t('El volcado del panel, o uno de SQLite, MySQL / MariaDB, PostgreSQL, SQL Server o Microsoft Access, con sus vistas y sus triggers. Se traducen al SQL de aquí, y al terminar se dice qué no ha llegado igual. Si el volcado trae <code>DROP TABLE</code>, las tablas con el mismo nombre se sustituyen.') ?></p>
        <p class="small text-body-secondary"><?= t('Con el panel en la misma máquina que el motor es <strong>todo o nada</strong>: si algo falla, la base queda como estaba. Si no, lo anterior al fallo queda hecho y se dice dónde paró.') ?></p>
        <p class="small d-flex gap-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#guiaVolcados">
            <?= h(t('Cómo hacer el volcado de cada motor')) ?></button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#guiaVolcados" data-pestana="access">
            <?= h(t('Microsoft Access: script y limitaciones')) ?></button>
        </p>
        <p class="small"><?= t('<strong>Microsoft Access</strong>: <a href="{1}">descarga el script de PowerShell</a> (necesita <strong>Windows</strong>; botón derecho → «Ejecutar con PowerShell»). Vuelca un .mdb o .accdb a un fichero que se importa aquí, y carga en Access lo que exportes como «SQL: Microsoft Access». Con un .mdb no hace falta instalar nada; con un .accdb, si falta el controlador de Access, lo dice y da el enlace para descargarlo.',
            [1 => h(url(['p' => 'script_access']))]) ?></p>
        <p class="small"><?= t('<strong>SQL Server</strong>: <a href="{1}">descarga el script de PowerShell</a> (necesita <strong>Windows</strong>, sin instalar nada). Vuelca una base del servidor a un fichero que se importa aquí, y carga en SQL Server lo que exportes como «SQL: SQL Server».',
            [1 => h(url(['p' => 'script_sqlserver']))]) ?></p>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap"<?= atributosSubida() ?>>
          <?= csrf() ?>
          <input type="hidden" name="accion" value="importar_sql">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <select class="form-select" name="formato" style="max-width:15rem" aria-label="<?= h(t('Formato del volcado')) ?>">
            <option value="auto"><?= h(t('Detectar el formato')) ?></option>
            <option value="jsonsqldb">jsonSQLDB</option>
            <option value="json"><?= h(t('JSON de jsonSQLDB')) ?></option>
            <option value="sqlite"><?= h(t('SQLite (.dump)')) ?></option>
            <option value="mysql"><?= h(t('MySQL / MariaDB (mysqldump)')) ?></option>
            <option value="postgresql"><?= h(t('PostgreSQL (pg_dump)')) ?></option>
            <option value="sqlserver"><?= h(t('SQL Server (Generar scripts)')) ?></option>
            <option value="access"><?= h(t('Microsoft Access (script de PowerShell)')) ?></option>
          </select>
          <?= campoImportacion('fichero', '.sql,.json,text/plain,application/json') ?>
          <button class="btn btn-primary"><?= icono('upload') ?> <?= h(t('Importar')) ?></button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><?= icono('upload') ?> <?= h(t('Cargar un CSV, un Excel o un JSON en una tabla')) ?></div>
      <div class="card-body">
        <p class="small text-body-secondary"><?= t('La primera fila (en JSON, cada objeto) trae los nombres de las columnas. Se inserta en lotes; con la carpeta de la base a mano, todo o nada.') ?></p>
        <?php if ($tablas === []): ?>
          <p class="small mb-0"><?= h(t('Crea antes la tabla.')) ?></p>
        <?php else: ?>
        <?php $xlsx = class_exists('ZipArchive') && class_exists('XMLReader'); ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap"<?= atributosSubida() ?>>
          <?= csrf() ?>
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <select class="form-select" name="tabla" required style="max-width:12rem" aria-label="<?= h(t('Tabla')) ?>">
            <?php foreach ($tablas as $t): ?><option<?= (string)$t['tabla'] === get('tabla') ? ' selected' : '' ?>><?= h((string)$t['tabla']) ?></option><?php endforeach; ?>
          </select>
          <select class="form-select" name="accion" style="max-width:9rem" aria-label="<?= h(t('Formato')) ?>">
            <option value="importar_csv">CSV</option>
            <?php if ($xlsx): ?><option value="importar_xlsx">Excel (.xlsx)</option><?php endif; ?>
            <option value="importar_json">JSON</option>
          </select>
          <?= campoImportacion('fichero', '.csv,.xlsx,.json,text/csv,text/plain,application/json,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') ?>
          <button class="btn btn-primary"><?= icono('upload') ?> <?= h(t('Cargar')) ?></button>
        </form>
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center mt-3">
          <input type="hidden" name="p" value="tablas">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <input type="hidden" name="ejemplos" value="1">
          <span class="small text-body-secondary"><?= h(t('Cómo tiene que ser el fichero, con plantillas:')) ?></span>
          <select class="form-select form-select-sm" name="tabla" style="max-width:12rem" aria-label="<?= h(t('Tabla')) ?>">
            <?php foreach ($tablas as $t): ?><option<?= (string)$t['tabla'] === get('tabla') ? ' selected' : '' ?>><?= h((string)$t['tabla']) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-outline-secondary"><?= h(t('Ver ejemplos')) ?></button>
        </form>
        <?php if (!$xlsx): ?><p class="small text-body-secondary mt-2 mb-0"><?= h(t('Para cargar un Excel hay que activar las extensiones zip y xml de PHP (extension=zip en php.ini).')) ?></p><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// Ventana de ejemplos (2.9): cómo tiene que ser el fichero para la tabla
// elegida, en los tres formatos, con su plantilla para descargar
$ejemplo = Auth::esAdmin() && get('ejemplos') !== '' ? (string)get('tabla') : '';
if ($ejemplo !== '' && in_array($ejemplo, array_map('strval', array_column($tablas, 'tabla')), true)):
    $filasEjemplo = Importar::filasDeEjemplo(Api::sql($base, 'SHOW COLUMNS FROM ' . cita($ejemplo)));
    $plantilla = static fn(string $formato, string $texto): string => '<form method="post" class="mt-2">' . csrf()
        . '<input type="hidden" name="accion" value="plantilla_importar"><input type="hidden" name="db" value="' . h($base) . '">'
        . '<input type="hidden" name="tabla" value="' . h($ejemplo) . '"><input type="hidden" name="formato" value="' . $formato . '">'
        . '<button class="btn btn-sm btn-outline-primary">' . icono('download') . ' ' . h($texto) . '</button></form>';
?>
<div class="modal fade" id="ejemplos" tabindex="-1" data-abrir>
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><?= t('Cómo cargar datos en «{1}»', [1 => h($ejemplo)]) ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('Cerrar')) ?>"></button>
    </div>
    <div class="modal-body">
      <h6>CSV</h6>
      <p class="small"><?= t('La primera línea, con los nombres de las columnas. El separador (coma, punto y coma o tabulador) se deduce solo; un campo con el separador, comillas o un salto de línea va entre comillas dobles, y una comilla dentro se escribe doble. Un campo vacío es <code>NULL</code>. En UTF-8 o en Windows-1252.') ?></p>
      <pre class="small border rounded p-2"><?= h(Exportar::textoCsv($filasEjemplo)) ?></pre>
      <?= $plantilla('csv', t('Descargar la plantilla CSV')) ?>
      <h6 class="mt-4">Excel (.xlsx)</h6>
      <p class="small"><?= t('La primera hoja: en la primera fila, los nombres de las columnas. Las celdas con formato de fecha llegan como fecha, y una celda vacía es <code>NULL</code>.') ?></p>
      <div class="table-responsive"><table class="table table-sm table-bordered small mb-0">
        <thead><tr><?php foreach (array_keys($filasEjemplo[0]) as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($filasEjemplo as $f): ?><tr><?php foreach ($f as $v): ?><td><?= h((string)$v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
      </table></div>
      <?= class_exists('ZipArchive') ? $plantilla('xlsx', t('Descargar la plantilla Excel')) : '' ?>
      <h6 class="mt-4">JSON</h6>
      <p class="small"><?= t('Una lista de objetos, uno por fila, con los nombres de las columnas como claves. Las columnas son las del primer objeto: a una fila que no trae alguna se le pone <code>NULL</code>. <code>null</code> es <code>NULL</code>, <code>true</code> y <code>false</code> son 1 y 0, y una lista o un objeto se guarda como texto JSON.') ?></p>
      <pre class="small border rounded p-2"><?= h("[\n" . implode(",\n", array_map([Exportar::class, 'filaJson'], $filasEjemplo)) . "\n]") ?></pre>
      <?= $plantilla('json', t('Descargar la plantilla JSON')) ?>
    </div>
  </div></div>
</div>
<?php endif; ?>

<?php if (Auth::esAdmin()): foreach ($tablas as $t): $n = (string)$t['tabla']; ?>
<div class="modal fade" id="bt<?= h(md5($n)) ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="borrar_tabla">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($n) ?>">
      <input type="hidden" name="volver" value="tablas">
      <div class="modal-header"><h5 class="modal-title"><?= t('Borrar la tabla «{1}»', [1 => h($n)]) ?></h5></div>
      <div class="modal-body"><?= t('Se pierde la estructura y las {1} fila(s) que contiene. No se puede deshacer.', [1 => number_format((int)$t['filas'], 0, ',', '.')]) ?></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cancelar')) ?></button>
        <button class="btn btn-danger"><?= h(t('Borrar')) ?></button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; endif; ?>
