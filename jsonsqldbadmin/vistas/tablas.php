<?php
$tablas = Api::sql($base, 'SHOW TABLES');
usort($tablas, static fn($a, $b) => strcasecmp((string)$a['tabla'], (string)$b['tabla']));
?>
<div class="page-head">
  <div>
    <h1><?= h($base) ?></h1>
    <p><?= count($tablas) ?> <?= count($tablas) === 1 ? 'tabla' : 'tablas' ?> en esta base de datos</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary" href="<?= h(url(['p' => 'sql', 'db' => $base])) ?>"><?= icono('terminal') ?> Consola SQL</a>
    <?php if (Auth::esAdmin()): ?>
      <a class="btn btn-primary" href="<?= h(url(['p' => 'crear_tabla', 'db' => $base])) ?>"><?= icono('plus') ?> Nueva tabla</a>
    <?php endif; ?>
  </div>
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
                <?= icono('table') ?> <?= h($n) ?></a></td>
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
<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><?= icono('upload') ?> Importar sentencias SQL</div>
      <div class="card-body">
        <p class="small text-body-secondary">Un fichero <code>.sql</code>, como el volcado que genera el panel, o
          cualquier lista de sentencias separadas por punto y coma. Se ejecutan en orden, por la misma vía que el
          resto del panel. <strong>No hay transacciones</strong>: si una falla, las anteriores ya están hechas y
          se dice cuál era.</p>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="importar_sql">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <input class="form-control" type="file" name="fichero" accept=".sql,text/plain" required style="max-width:22rem">
          <button class="btn btn-primary"><?= icono('upload') ?> Importar</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><?= icono('upload') ?> Cargar un CSV en una tabla</div>
      <div class="card-body">
        <p class="small text-body-secondary">La primera línea, con los nombres de las columnas. El separador
          (coma, punto y coma o tabulador) se deduce solo, y un campo vacío es <code>NULL</code>. Se inserta en
          lotes de 200 filas; sin transacciones, como arriba.</p>
        <?php if ($tablas === []): ?>
          <p class="small mb-0">Crea antes la tabla.</p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="importar_csv">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <select class="form-select" name="tabla" required style="max-width:12rem">
            <?php foreach ($tablas as $t): ?><option><?= h((string)$t['tabla']) ?></option><?php endforeach; ?>
          </select>
          <input class="form-control" type="file" name="fichero" accept=".csv,text/csv,text/plain" required style="max-width:18rem">
          <button class="btn btn-primary"><?= icono('upload') ?> Cargar</button>
        </form>
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
