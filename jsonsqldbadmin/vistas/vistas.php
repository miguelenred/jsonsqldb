<?php
$vistas = Api::sql($base, 'SHOW VIEWS');
$tablas = array_column(Api::sql($base, 'SHOW TABLES'), 'tabla');
$admin  = Auth::esAdmin();
?>
<div class="page-head">
  <div>
    <h1><?= h(t('Vistas')) ?></h1>
    <p><?= t('Consultas guardadas con nombre en {1}: se usan como tablas y siempre dan los datos del momento', [1 => h($base)]) ?></p>
  </div>
  <?php if ($admin): ?>
    <div class="page-actions">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#nuevaVista"><?= icono('plus') ?> <?= h(t('Nueva vista')) ?></button>
    </div>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <div class="card-body p-0">
    <?php if ($vistas === []): ?>
      <p class="text-body-secondary m-3 mb-0">
        <?= t('Esta base no tiene vistas. Una vista es un <code>SELECT</code> guardado con nombre: la consultas como si fuera una tabla y siempre devuelve los datos del momento.') ?>
      </p>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead><tr>
          <th><?= h(t('Vista')) ?></th><th><?= h(t('Consulta')) ?></th><th><?= h(t('Creada')) ?></th><th class="text-end"><?= h(t('Acciones')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($vistas as $v): $n = (string)$v['vista']; ?>
          <tr>
            <td><a href="<?= h(url(['p' => 'sql', 'db' => $base,
                                    'sql' => 'SELECT * FROM ' . cita($n) . ' LIMIT 100'])) ?>">
                <?= icono('eye') ?> <?= h($n) ?></a></td>
            <td><code class="small text-body-secondary"><?= celda($v['sql']) ?></code></td>
            <td class="small text-body-secondary text-nowrap"><?= h($v['creada'] ?? '') ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= h(url(['p' => 'sql', 'db' => $base,
                                  'sql' => 'SELECT * FROM ' . cita($n) . ' LIMIT 100'])) ?>"><?= h(t('Consultar')) ?></a>
              <?php if ($admin): ?>
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                        data-bs-target="#bv<?= h(md5($n)) ?>"><?= icono('trash') ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <div class="card-footer small text-body-secondary">
    <?= t('Las vistas son de <strong>solo lectura</strong>: no admiten <code>INSERT</code>, <code>UPDATE</code> ni <code>DELETE</code>. Y no guardan resultados: se resuelven en cada consulta, así que una vista sobre un <code>JOIN</code> grande recorre las tablas cada vez. Sirven para no repetir SQL, no para ir más rápido.') ?>
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
      <div class="modal-header"><h5 class="modal-title"><?= t('Nueva vista en «{1}»', [1 => h($base)]) ?></h5></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="vistaNombre"><?= h(t('Nombre')) ?></label>
          <input class="form-control form-control-sm" id="vistaNombre" name="nombre" required
                 pattern="[A-Za-z_][A-Za-z0-9_]*" placeholder="v_clientes_activos">
          <div class="form-text">
            <?= t('No puede llamarse igual que una tabla. Empezar por <code>v_</code> ayuda a distinguirlas de un vistazo.') ?>
          </div>
        </div>
        <div>
          <label class="form-label" for="vistaSql"><?= h(t('Consulta')) ?></label>
          <textarea class="form-control sql-area" id="vistaSql" name="sql" rows="7" required
                    placeholder="SELECT ..."></textarea>
          <div class="form-text">
            <?= t('Tiene que ser un <code>SELECT</code>. Puede llevar <code>JOIN</code>, <code>GROUP BY</code>, subconsultas y hasta otras vistas.') ?>
            <?php if ($tablas !== []): ?>
              <?= t('Tablas de esta base: {1}.', [1 => implode(', ', array_map(
                    static fn(string $t): string => '<code>' . h($t) . '</code>', $tablas))]) ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cancelar')) ?></button>
        <button class="btn btn-primary"><?= h(t('Crear')) ?></button>
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
      <div class="modal-header"><h5 class="modal-title"><?= t('Borrar la vista «{1}»', [1 => h($n)]) ?></h5></div>
      <div class="modal-body">
        <?= t('Se borra solo la consulta guardada. <strong>Los datos no se tocan</strong>, porque una vista no tiene datos propios.') ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cancelar')) ?></button>
        <button class="btn btn-danger"><?= h(t('Borrar')) ?></button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>
