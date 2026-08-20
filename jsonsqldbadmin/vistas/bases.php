<?php
$bases = Api::bases();
sort($bases);
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-hdd-stack"></i> Bases de datos</div>
      <div class="card-body p-0">
        <?php if ($bases === []): ?>
          <p class="text-body-secondary m-3">Todavía no hay ninguna base de datos.</p>
        <?php else: ?>
          <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Base</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($bases as $b): ?>
              <tr>
                <td>
                  <a href="<?= h(url(['p' => 'tablas', 'db' => $b])) ?>">
                    <i class="bi bi-database"></i> <?= h($b) ?></a>
                </td>
                <td class="text-end">
                  <div class="d-inline-flex gap-2 align-items-center">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= h(url(['p' => 'sql', 'db' => $b])) ?>"><i class="bi bi-terminal"></i> SQL</a>
                    <?php foreach (['sql' => ['Volcado SQL', 'filetype-sql'],
                                    'zip' => ['Copia ZIP', 'file-zip']] as $f => $bt): ?>
                      <form method="post">
                        <?= csrf() ?>
                        <input type="hidden" name="accion" value="exportar_base">
                        <input type="hidden" name="formato" value="<?= h($f) ?>">
                        <input type="hidden" name="nombre" value="<?= h($b) ?>">
                        <input type="hidden" name="volver" value="bases">
                        <button class="btn btn-sm btn-outline-secondary" title="<?= h($bt[0]) ?>">
                          <i class="bi bi-<?= h($bt[1]) ?>"></i></button>
                      </form>
                    <?php endforeach; ?>
                    <?php if (Auth::esAdmin()): ?>
                      <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                              data-bs-target="#borrar<?= h(md5($b)) ?>"><i class="bi bi-trash"></i></button>
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
        <i class="bi bi-filetype-sql"></i> volcado en SQL: estructura y datos, legible y
        reejecutable. <i class="bi bi-file-zip"></i> copia en ZIP: los ficheros JSON tal cual,
        con su estructura de carpetas; se descomprime dentro de <code>data/</code> y la base
        queda restaurada.
      </div>
    </div>
  </div>

  <?php if (Auth::esAdmin()): ?>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-plus-circle"></i> Nueva base de datos</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="crear_base">
          <input type="hidden" name="volver" value="bases">
          <div class="col-8">
            <label class="form-label" for="nombreBase">Nombre</label>
            <input class="form-control" id="nombreBase" name="nombre" required
                   pattern="[A-Za-z0-9_\-]{1,64}">
          </div>
          <div class="col-4"><button class="btn btn-primary w-100">Crear</button></div>
          <div class="col-12 form-text">Letras, números, guion y guion bajo. Máximo 64 caracteres.</div>
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
      <div class="modal-header"><h5 class="modal-title">Borrar «<?= h($b) ?>»</h5></div>
      <div class="modal-body">
        <p>Se borran <strong>todas las tablas y todos los datos</strong> de esta base. No se puede deshacer.</p>
        <label class="form-label">Escribe <code><?= h($b) ?></code> para confirmar</label>
        <input class="form-control" name="confirmacion" required autocomplete="off">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger">Borrar</button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; endif; ?>
