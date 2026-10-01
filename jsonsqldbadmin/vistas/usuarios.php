<?php
$usuarios = Auth::usuarios();
$yo       = (string)Auth::usuario()['usuario'];
$admin    = Auth::esAdmin();
usort($usuarios, static fn($a, $b) => strcasecmp((string)$a['usuario'], (string)$b['usuario']));
?>
<div class="page-head">
  <div>
    <h1><?= h(t('Usuarios')) ?></h1>
    <p><?= h(t('Quién puede entrar al panel y con qué permiso')) ?></p>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><?= icono('people') ?> <?= h(t('Usuarios del panel')) ?></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th><?= h(t('Usuario')) ?></th><th><?= h(t('Rol')) ?></th><th><?= h(t('Creado')) ?></th><th><?= h(t('Último acceso')) ?></th>
            <th class="text-end"><?= h(t('Acciones')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($usuarios as $u): $n = (string)$u['usuario']; ?>
            <tr>
              <td><strong><?= h($n) ?></strong>
                <?php if (strcasecmp($n, $yo) === 0): ?>
                  <span class="badge text-bg-light"><?= h(t('tú')) ?></span>
                <?php endif; ?></td>
              <td><span class="badge text-bg-<?= ($u['rol'] ?? '') === 'admin' ? 'info' : 'secondary' ?>">
                  <?= h($u['rol'] ?? '') ?></span></td>
              <td class="small text-body-secondary"><?= h($u['creado'] ?? '') ?></td>
              <td class="small text-body-secondary"><?= h($u['acceso'] ?? 'nunca') ?></td>
              <td class="text-end">
                <?php if ($admin || strcasecmp($n, $yo) === 0): ?>
                  <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                          data-bs-target="#clave<?= h(md5($n)) ?>"><?= icono('key') ?></button>
                <?php endif; ?>
                <?php if ($admin && strcasecmp($n, $yo) !== 0): ?>
                  <form method="post" class="d-inline" data-confirm="<?= h(t('¿Borrar el usuario?')) ?>">
                    <?= csrf() ?>
                    <input type="hidden" name="accion" value="borrar_usuario">
                    <input type="hidden" name="usuario" value="<?= h($n) ?>">
                    <input type="hidden" name="volver" value="usuarios">
                    <button class="btn btn-sm btn-outline-danger"><?= icono('trash') ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($admin): ?>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><?= icono('person-plus') ?> <?= h(t('Nuevo usuario')) ?></div>
      <div class="card-body">
        <form method="post" autocomplete="off">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="crear_usuario">
          <input type="hidden" name="volver" value="usuarios">
          <div class="mb-2">
            <label class="form-label" for="nuevoUsuario"><?= h(t('Usuario')) ?></label>
            <input class="form-control" id="nuevoUsuario" name="usuario" required
                   pattern="[A-Za-z0-9_.@\-]{3,32}">
          </div>
          <div class="mb-2">
            <label class="form-label" for="nuevaClave"><?= h(t('Contraseña')) ?></label>
            <input class="form-control" id="nuevaClave" name="clave" type="password" required minlength="10">
          </div>
          <div class="mb-3">
            <label class="form-label" for="nuevoRol"><?= h(t('Rol')) ?></label>
            <select class="form-select" id="nuevoRol" name="rol">
              <option value="lectura"><?= h(t('lectura — ver datos y lanzar SELECT/SHOW')) ?></option>
              <option value="admin"><?= h(t('admin — todo')) ?></option>
            </select>
          </div>
          <button class="btn btn-primary"><?= h(t('Crear usuario')) ?></button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php foreach ($usuarios as $u): $n = (string)$u['usuario'];
      if (!$admin && strcasecmp($n, $yo) !== 0) { continue; } ?>
<div class="modal fade" id="clave<?= h(md5($n)) ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" autocomplete="off">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="cambiar_clave">
      <input type="hidden" name="usuario" value="<?= h($n) ?>">
      <input type="hidden" name="volver" value="usuarios">
      <div class="modal-header"><h5 class="modal-title"><?= t('Contraseña de «{1}»', [1 => h($n)]) ?></h5></div>
      <div class="modal-body">
        <input class="form-control" name="clave" type="password" required minlength="10"
               placeholder="<?= h(t('Nueva contraseña')) ?>">
        <div class="form-text"><?= h(t('Mínimo 10 caracteres.')) ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cancelar')) ?></button>
        <button class="btn btn-primary"><?= h(t('Cambiar')) ?></button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
