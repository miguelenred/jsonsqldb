<?php
$vistas = Api::sql($base, 'SHOW VIEWS');
$tablas = array_column(Api::sql($base, 'SHOW TABLES'), 'tabla');
$admin  = Auth::esAdmin();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">
    <a class="link-secondary text-decoration-none" href="<?= h(url(['p' => 'tablas', 'db' => $base])) ?>">
      <i class="bi bi-database"></i> <?= h($base) ?></a>
    <span class="text-body-tertiary">/</span> <i class="bi bi-eye"></i> Vistas
  </h1>
  <?php if ($admin): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#nuevaVista">
      <i class="bi bi-plus-circle"></i> Nueva vista</button>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <div class="card-body p-0">
    <?php if ($vistas === []): ?>
      <p class="text-body-secondary m-3 mb-0">
        Esta base no tiene vistas. Una vista es un <code>SELECT</code> guardado con nombre: la
        consultas como si fuera una tabla y siempre devuelve los datos del momento.
      </p>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead><tr>
          <th>Vista</th><th>Consulta</th><th>Creada</th><th class="text-end">Acciones</th>
        </tr></thead>
        <tbody>
        <?php foreach ($vistas as $v): $n = (string)$v['vista']; ?>
          <tr>
            <td><a href="<?= h(url(['p' => 'sql', 'db' => $base,
                                    'sql' => 'SELECT * FROM ' . cita($n) . ' LIMIT 100'])) ?>">
                <i class="bi bi-eye"></i> <?= h($n) ?></a></td>
            <td><code class="small text-body-secondary"><?= celda($v['sql']) ?></code></td>
            <td class="small text-body-secondary text-nowrap"><?= h($v['creada'] ?? '') ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= h(url(['p' => 'sql', 'db' => $base,
                                  'sql' => 'SELECT * FROM ' . cita($n) . ' LIMIT 100'])) ?>">Consultar</a>
              <?php if ($admin): ?>
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                        data-bs-target="#bv<?= h(md5($n)) ?>"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <div class="card-footer small text-body-secondary">
    Las vistas son de <strong>solo lectura</strong>: no admiten <code>INSERT</code>,
    <code>UPDATE</code> ni <code>DELETE</code>. Y no guardan resultados: se resuelven en cada
    consulta, así que una vista sobre un <code>JOIN</code> grande recorre las tablas cada vez.
    Sirven para no repetir SQL, no para ir más rápido.
  </div>
</div>

<?php if ($admin): ?>
<div class="modal fade" id="nuevaVista" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="crear_vista">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="volver" value="vistas">
      <div class="modal-header"><h5 class="modal-title">Nueva vista en «<?= h($base) ?>»</h5></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="vistaNombre">Nombre</label>
          <input class="form-control form-control-sm" id="vistaNombre" name="nombre" required
                 pattern="[A-Za-z_][A-Za-z0-9_]*" placeholder="v_clientes_activos">
          <div class="form-text">
            No puede llamarse igual que una tabla. Empezar por <code>v_</code> ayuda a
            distinguirlas de un vistazo.
          </div>
        </div>
        <div>
          <label class="form-label" for="vistaSql">Consulta</label>
          <textarea class="form-control sql-area" id="vistaSql" name="sql" rows="7" required
                    placeholder="SELECT ..."></textarea>
          <div class="form-text">
            Tiene que ser un <code>SELECT</code>. Puede llevar <code>JOIN</code>,
            <code>GROUP BY</code>, subconsultas y hasta otras vistas.
            <?php if ($tablas !== []): ?>
              Tablas de esta base:
              <?= implode(', ', array_map(
                    static fn(string $t): string => '<code>' . h($t) . '</code>', $tablas)) ?>.
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Crear</button>
      </div>
    </form>
  </div></div>
</div>

<?php foreach ($vistas as $v): $n = (string)$v['vista']; ?>
<div class="modal fade" id="bv<?= h(md5($n)) ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="borrar_vista">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="nombre" value="<?= h($n) ?>">
      <input type="hidden" name="volver" value="vistas">
      <div class="modal-header"><h5 class="modal-title">Borrar la vista «<?= h($n) ?>»</h5></div>
      <div class="modal-body">
        Se borra solo la consulta guardada. <strong>Los datos no se tocan</strong>, porque una
        vista no tiene datos propios.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger">Borrar</button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>
