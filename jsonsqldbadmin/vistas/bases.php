<?php
$bases = Api::bases();
sort($bases);

// La copia en ZIP lee el disco: solo tiene sentido si el motor está aquí
$zipDisponible = mismoHostQueLaApi() !== false;
?>
<div class="page-head">
  <div>
    <h1><?= h(t('Bases de datos')) ?></h1>
    <p><?= count($bases) ?> <?= count($bases) === 1 ? 'base' : 'bases' ?> ·
       <?= Api::directa() ? t('conexión directa al motor') : t('conexión por la API') ?></p>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><?= icono('hdd-stack') ?> <?= h(t('Bases de datos')) ?></div>
      <div class="card-body p-0">
        <?php if ($bases === []): ?>
          <p class="text-body-secondary m-3"><?= h(t('Todavía no hay ninguna base de datos.')) ?></p>
        <?php else: ?>
          <table class="table table-hover mb-0 align-middle">
            <thead><tr><th><?= h(t('Base')) ?></th><th class="text-end"><?= h(t('Acciones')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($bases as $b): ?>
              <tr>
                <td>
                  <a href="<?= h(url(['p' => 'tablas', 'db' => $b])) ?>">
                    <?= icono('database') ?> <?= h($b) ?></a>
                </td>
                <td class="text-end">
                  <div class="d-inline-flex gap-2 align-items-center">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= h(url(['p' => 'sql', 'db' => $b])) ?>"><?= icono('terminal') ?> SQL</a>
                    <?php if ($zipDisponible && Auth::esAdmin()): ?>
                      <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal"
                              data-bs-target="#imp<?= h(md5($b)) ?>" title="<?= h(t('Restaurar desde una copia ZIP')) ?>">
                        <?= icono('upload') ?></button>
                    <?php endif; ?>
                    <form method="post" class="d-flex gap-1" title="<?= h(t('Volcado SQL para otro motor')) ?>">
                      <?= csrf() ?>
                      <input type="hidden" name="accion" value="exportar_base">
                      <input type="hidden" name="nombre" value="<?= h($b) ?>">
                      <select class="form-select form-select-sm" name="formato" aria-label="<?= h(t('Formato del volcado')) ?>" style="width:auto">
                        <option value="sql"><?= h(t('SQL: jsonSQLDB y SQLite')) ?></option>
                        <option value="mysql"><?= h(t('SQL: MySQL / MariaDB')) ?></option>
                        <option value="postgresql"><?= h(t('SQL: PostgreSQL')) ?></option>
                        <option value="sqlserver"><?= h(t('SQL: SQL Server')) ?></option>
                      </select>
                      <button class="btn btn-sm btn-outline-secondary" title="<?= h(t('Descargar el volcado')) ?>"><?= icono('download') ?></button>
                    </form>
                    <?php
                    $formatos = $zipDisponible ? ['zip' => [t('Copia ZIP'), 'file-zip']] : [];
                    foreach ($formatos as $f => $bt): ?>
                      <form method="post">
                        <?= csrf() ?>
                        <input type="hidden" name="accion" value="exportar_base">
                        <input type="hidden" name="formato" value="<?= h($f) ?>">
                        <input type="hidden" name="nombre" value="<?= h($b) ?>">
                        <input type="hidden" name="volver" value="bases">
                        <button class="btn btn-sm btn-outline-secondary" title="<?= h($bt[0]) ?>">
                          <?= icono($bt[1]) ?></button>
                      </form>
                    <?php endforeach; ?>
                    <?php if (Auth::esAdmin()): ?>
                      <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                              data-bs-target="#borrar<?= h(md5($b)) ?>"><?= icono('trash') ?></button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <div class="card-footer small text-body-secondary">
        <?= t('{1} volcado en SQL: estructura y datos, legible y reejecutable.', [1 => icono('filetype-sql')]) ?>
        <?php if ($zipDisponible): ?>
          <?= t('{1} copia en ZIP: los ficheros JSON tal cual, con su estructura de carpetas. {2} restaura esa copia sobre la base, sustituyendo lo que haya.', [1 => icono('file-zip'), 2 => icono('upload')]) ?>
        <?php else: ?>
          <?= t('<br> {1} La <strong>copia en ZIP no está disponible</strong>: lee los ficheros del disco y la API está en otra máquina (<code>{2}</code>, y el panel se sirve desde <code>{3}</code>). El volcado en SQL va por la API y funciona igual entre máquinas distintas.', [1 => icono('info-circle'), 2 => h(parse_url(Api::url(), PHP_URL_HOST) ?: '?'), 3 => h(explode(':', (string)($_SERVER['HTTP_HOST'] ?? '?'))[0])]) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (Auth::esAdmin()): ?>
  <div class="col-lg-5">
    <div class="card" id="nuevaBase">
      <div class="card-header"><?= icono('plus-circle') ?> <?= h(t('Nueva base de datos')) ?></div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="crear_base">
          <input type="hidden" name="volver" value="bases">
          <div class="col-8">
            <label class="form-label" for="nombreBase"><?= h(t('Nombre')) ?></label>
            <input class="form-control" id="nombreBase" name="nombre" required
                   pattern="[A-Za-z0-9_\-]{1,64}">
          </div>
          <div class="col-4"><button class="btn btn-primary w-100"><?= h(t('Crear')) ?></button></div>
          <div class="col-12 form-text"><?= h(t('Letras, números, guion y guion bajo. Máximo 64 caracteres.')) ?></div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if (Auth::esAdmin()): foreach ($bases as $b): ?>
<div class="modal fade" id="borrar<?= h(md5($b)) ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="borrar_base">
      <input type="hidden" name="nombre" value="<?= h($b) ?>">
      <input type="hidden" name="volver" value="bases">
      <div class="modal-header"><h5 class="modal-title"><?= t('Borrar «{1}»', [1 => h($b)]) ?></h5></div>
      <div class="modal-body">
        <p><?= t('Se borran <strong>todas las tablas y todos los datos</strong> de esta base. No se puede deshacer.') ?></p>
        <label class="form-label"><?= t('Escribe <code>{1}</code> para confirmar', [1 => h($b)]) ?></label>
        <input class="form-control" name="confirmacion" required autocomplete="off">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cancelar')) ?></button>
        <button class="btn btn-danger"><?= h(t('Borrar')) ?></button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; endif; ?>

<?php if ($zipDisponible && Auth::esAdmin()): ?>
<?php foreach ($bases as $b): ?>
<div class="modal fade" id="imp<?= h(md5($b)) ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" enctype="multipart/form-data">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="importar_zip">
      <input type="hidden" name="nombre" value="<?= h($b) ?>">
      <div class="modal-header">
        <h5 class="modal-title"><?= t('Restaurar «{1}» desde un ZIP', [1 => h($b)]) ?></h5>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 small">
          <?= t('<strong>Se sustituye todo el contenido actual de la base.</strong> Antes de escribir nada se aparta una copia de lo que hay, y si la restauración falla a medias se deja como estaba.') ?>
        </div>
        <div class="mb-2">
          <label class="form-label" for="zip<?= h(md5($b)) ?>"><?= h(t('Fichero ZIP')) ?></label>
          <input class="form-control form-control-sm" type="file" name="zip" accept=".zip"
                 id="zip<?= h(md5($b)) ?>" required>
        </div>
        <div class="form-text">
          <?= t('Tiene que ser una copia generada por este panel. Solo se restauran los ficheros <code>.json</code>; cualquier otra cosa que venga dentro se ignora, y si el ZIP trae rutas que salgan de la carpeta de destino se rechaza entero sin tocar nada.') ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cancelar')) ?></button>
        <button class="btn btn-warning"
                data-confirm-click="¿Sustituir el contenido de <?= h($b) ?>?">
          <?= t('{1} Restaurar', [1 => icono('upload')]) ?></button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>
