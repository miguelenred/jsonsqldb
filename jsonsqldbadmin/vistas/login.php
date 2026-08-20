<div class="caja-acceso">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 mb-3"><i class="bi bi-database"></i> jsonSQLDB<span class="text-primary">admin</span></h1>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="mb-3">
          <label class="form-label" for="usuario">Usuario</label>
          <input class="form-control" id="usuario" name="usuario" required autofocus
                 value="<?= h(post('usuario')) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="clave">Contraseña</label>
          <input class="form-control" id="clave" name="clave" type="password" required>
        </div>
        <button class="btn btn-primary w-100">Entrar</button>
      </form>
    </div>
  </div>
</div>
