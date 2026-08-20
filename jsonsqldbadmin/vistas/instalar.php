<div class="caja-acceso">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 mb-1"><i class="bi bi-database"></i> jsonSQLDB<span class="text-primary">admin</span></h1>
      <p class="text-body-secondary small">No hay ningún usuario todavía. Crea el administrador.</p>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="mb-3">
          <label class="form-label" for="usuario">Usuario</label>
          <input class="form-control" id="usuario" name="usuario" required autofocus
                 pattern="[A-Za-z0-9_.@\-]{3,32}" value="<?= h(post('usuario')) ?>">
          <div class="form-text">De 3 a 32 caracteres: letras, números y . _ - @</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="clave">Contraseña</label>
          <input class="form-control" id="clave" name="clave" type="password" required minlength="10">
          <div class="form-text">Mínimo 10 caracteres. Se guarda con bcrypt.</div>
        </div>
        <button class="btn btn-primary w-100">Crear administrador</button>
      </form>
    </div>
  </div>
</div>
