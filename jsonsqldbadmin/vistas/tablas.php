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
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap"<?= atributosSubida() ?>>
          <?= csrf() ?>
          <input type="hidden" name="accion" value="importar_sql">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <select class="form-select" name="formato" style="max-width:15rem" aria-label="<?= h(t('Formato del volcado')) ?>">
            <option value="auto"><?= h(t('Detectar el formato')) ?></option>
            <option value="jsonsqldb">jsonSQLDB</option>
            <option value="sqlite"><?= h(t('SQLite (.dump)')) ?></option>
            <option value="mysql"><?= h(t('MySQL / MariaDB (mysqldump)')) ?></option>
            <option value="postgresql"><?= h(t('PostgreSQL (pg_dump)')) ?></option>
            <option value="sqlserver"><?= h(t('SQL Server (Generar scripts)')) ?></option>
            <option value="access"><?= h(t('Microsoft Access (script de PowerShell)')) ?></option>
          </select>
          <?= campoImportacion('fichero', '.sql,text/plain') ?>
          <button class="btn btn-primary"><?= icono('upload') ?> <?= h(t('Importar')) ?></button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><?= icono('upload') ?> <?= h(t('Cargar un CSV o un Excel en una tabla')) ?></div>
      <div class="card-body">
        <p class="small text-body-secondary"><?= t('La primera línea, con los nombres de las columnas. El separador (coma, punto y coma o tabulador) se deduce solo, y un campo vacío es <code>NULL</code>. Se inserta en lotes de 200 filas; sin transacciones, como arriba.') ?></p>
        <?php if ($tablas === []): ?>
          <p class="small mb-0"><?= h(t('Crea antes la tabla.')) ?></p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap"<?= atributosSubida() ?>>
          <?= csrf() ?>
          <input type="hidden" name="accion" value="importar_csv">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <select class="form-select" name="tabla" required style="max-width:12rem">
            <?php foreach ($tablas as $t): ?><option><?= h((string)$t['tabla']) ?></option><?php endforeach; ?>
          </select>
          <?= campoImportacion('fichero', '.csv,text/csv,text/plain') ?>
          <button class="btn btn-primary"><?= icono('upload') ?> <?= h(t('Cargar')) ?></button>
        </form>
        <?php $xlsx = class_exists('ZipArchive') && class_exists('XMLReader'); ?>
        <p class="small text-body-secondary mt-3 mb-2"><?= t('O la primera hoja de un <strong>Excel (.xlsx)</strong>: la primera fila, con los nombres de las columnas; las celdas con formato de fecha llegan como fecha.') ?></p>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap"<?= atributosSubida() ?>>
          <?= csrf() ?>
          <input type="hidden" name="accion" value="importar_xlsx">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <select class="form-select" name="tabla" required style="max-width:12rem" aria-label="<?= h(t('Tabla')) ?>">
            <?php foreach ($tablas as $t): ?><option><?= h((string)$t['tabla']) ?></option><?php endforeach; ?>
          </select>
          <?= campoImportacion('fichero', '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') ?>
          <button class="btn btn-primary"<?= $xlsx ? '' : ' disabled title="' . h(t('Necesita las extensiones zip y xml de PHP.')) . '"' ?>><?= icono('upload') ?> <?= h(t('Cargar Excel')) ?></button>
        </form>
        <?php if (!$xlsx): ?><p class="small text-body-secondary mt-1 mb-0"><?= h(t('Para cargar un Excel hay que activar las extensiones zip y xml de PHP (extension=zip en php.ini).')) ?></p><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
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
