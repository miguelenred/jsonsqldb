<?php
$tablas = Api::sql($base, 'SHOW TABLES');
usort($tablas, static fn($a, $b) => strcasecmp((string)$a['tabla'], (string)$b['tabla']));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><i class="bi bi-database"></i> <?= h($base) ?></h1>
  <?php if (Auth::esAdmin()): ?>
    <a class="btn btn-sm btn-primary" href="<?= h(url(['p' => 'crear_tabla', 'db' => $base])) ?>">
      <i class="bi bi-plus-circle"></i> Nueva tabla</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <?php if ($tablas === []): ?>
      <p class="text-body-secondary m-3">Esta base no tiene tablas todavía.</p>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead><tr>
          <th>Tabla</th><th class="text-end">Columnas</th><th class="text-end">Filas</th>
          <th>Creada</th><th class="text-end">Acciones</th>
        </tr></thead>
        <tbody>
        <?php foreach ($tablas as $t): $n = (string)$t['tabla']; ?>
          <tr>
            <td><a href="<?= h(url(['p' => 'datos', 'db' => $base, 'tabla' => $n])) ?>">
                <i class="bi bi-table"></i> <?= h($n) ?></a></td>
            <td class="text-end"><?= (int)$t['columnas'] ?></td>
            <td class="text-end"><?= number_format((int)$t['filas'], 0, ',', '.') ?></td>
            <td class="text-body-secondary small"><?= h($t['creada'] ?? '') ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= h(url(['p' => 'datos', 'db' => $base, 'tabla' => $n])) ?>">Datos</a>
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= h(url(['p' => 'estructura', 'db' => $base, 'tabla' => $n])) ?>">Estructura</a>
              <?php if (Auth::esAdmin()): ?>
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                        data-bs-target="#bt<?= h(md5($n)) ?>"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::esAdmin()): foreach ($tablas as $t): $n = (string)$t['tabla']; ?>
<div class="modal fade" id="bt<?= h(md5($n)) ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="borrar_tabla">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($n) ?>">
      <input type="hidden" name="volver" value="tablas">
      <div class="modal-header"><h5 class="modal-title">Borrar la tabla «<?= h($n) ?>»</h5></div>
      <div class="modal-body">Se pierde la estructura y las <?= number_format((int)$t['filas'], 0, ',', '.') ?>
        fila(s) que contiene. No se puede deshacer.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger">Borrar</button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; endif; ?>
