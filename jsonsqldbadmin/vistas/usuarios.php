<?php
$usuarios = Auth::usuarios();
$yo       = (string)Auth::usuario()['usuario'];
$admin    = Auth::esAdmin();
usort($usuarios, static fn($a, $b) => strcasecmp((string)$a['usuario'], (string)$b['usuario']));
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-people"></i> Usuarios del panel</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Usuario</th><th>Rol</th><th>Creado</th><th>Último acceso</th>
            <th class="text-end">Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($usuarios as $u): $n = (string)$u['usuario']; ?>
            <tr>
              <td><strong><?= h($n) ?></strong>
                <?php if (strcasecmp($n, $yo) === 0): ?>
                  <span class="badge text-bg-light">tú</span>
                <?php endif; ?></td>
              <td><span class="badge text-bg-<?= ($u['rol'] ?? '') === 'admin' ? 'info' : 'secondary' ?>">
                  <?= h($u['rol'] ?? '') ?></span></td>
              <td class="small text-body-secondary"><?= h($u['creado'] ?? '') ?></td>
              <td class="small text-body-secondary"><?= h($u['acceso'] ?? 'nunca') ?></td>
              <td class="text-end">
                <?php if ($admin || strcasecmp($n, $yo) === 0): ?>
                  <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                          data-bs-target="#clave<?= h(md5($n)) ?>"><i class="bi bi-key"></i></button>
                <?php endif; ?>
                <?php if ($admin && strcasecmp($n, $yo) !== 0): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('¿Borrar el usuario?');">
                    <?= csrf() ?>
                    <input type="hidden" name="accion" value="borrar_usuario">
                    <input type="hidden" name="usuario" value="<?= h($n) ?>">
                    <input type="hidden" name="volver" value="usuarios">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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
      <div class="card-header"><i class="bi bi-person-plus"></i> Nuevo usuario</div>
      <div class="card-body">
        <form method="post" autocomplete="off">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="crear_usuario">
          <input type="hidden" name="volver" value="usuarios">
          <div class="mb-2">
            <label class="form-label" for="nuevoUsuario">Usuario</label>
            <input class="form-control" id="nuevoUsuario" name="usuario" required
                   pattern="[A-Za-z0-9_.@\-]{3,32}">
          </div>
          <div class="mb-2">
            <label class="form-label" for="nuevaClave">Contraseña</label>
            <input class="form-control" id="nuevaClave" name="clave" type="password" required minlength="10">
          </div>
          <div class="mb-3">
            <label class="form-label" for="nuevoRol">Rol</label>
            <select class="form-select" id="nuevoRol" name="rol">
              <option value="lectura">lectura — ver datos y lanzar SELECT/SHOW</option>
              <option value="admin">admin — todo</option>
            </select>
          </div>
          <button class="btn btn-primary">Crear usuario</button>
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
      <div class="modal-header"><h5 class="modal-title">Contraseña de «<?= h($n) ?>»</h5></div>
      <div class="modal-body">
        <input class="form-control" name="clave" type="password" required minlength="10"
               placeholder="Nueva contraseña">
        <div class="form-text">Mínimo 10 caracteres.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Cambiar</button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
