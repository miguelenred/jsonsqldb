<?php
$sql       = (string)($_POST['sql'] ?? $_GET['sql'] ?? '');
$resultado = null;
$error     = null;
$ms        = 0.0;

if ($sql !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Auth::comprobarCsrf();
        if (!Auth::esAdmin() && !preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
            throw new RuntimeException(t('Con permiso de lectura solo se pueden lanzar SELECT y SHOW.'));
        }
        $t0        = microtime(true);
        $resultado = Api::sql($base, $sql);
        $ms        = (microtime(true) - $t0) * 1000;
        Audit::registrar('sql', substr($sql, 0, 500), $base);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        Audit::registrar('sql_error', substr($sql, 0, 500) . ' → ' . $e->getMessage(), $base);
    }
}
?>
<div class="page-head">
  <div>
    <h1><?= h(t('Consola SQL')) ?></h1>
    <p><?= t('Sentencias contra {1}. <kbd>Ctrl</kbd>+<kbd>Enter</kbd> ejecuta.', [1 => h($base)]) ?></p>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="post">
      <?= csrf() ?>
      <textarea class="form-control sql-area" name="sql" rows="7" required
                placeholder="<?= h(t('SELECT * FROM mi_tabla WHERE ...')) ?>"><?= h($sql) ?></textarea>
      <div class="d-flex justify-content-between align-items-center mt-2">
        <div class="form-text mb-0">
          <?= t('Una sentencia por ejecución. Admite varias líneas y comentarios <code>--</code> y <code>/* */</code>.') ?>
        </div>
        <button class="btn btn-primary"><?= icono('play-fill') ?> <?= h(t('Ejecutar')) ?></button>
      </div>
    </form>
  </div>
</div>

<?php if ($error !== null): ?>
  <div class="alert alert-danger"><?= icono('x-octagon') ?> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($resultado !== null): ?>
  <?php if (isset($resultado['success'])): ?>
    <div class="alert alert-success">
      <?= icono('check2-circle') ?> <?= h($resultado['mensaje']) ?>
      <span class="text-body-secondary"><?= t('({1} ms)', [1 => number_format($ms, 1, ',', '.')]) ?></span>
    </div>
  <?php elseif ($resultado === []): ?>
    <div class="alert alert-secondary">
      <?= h(t('La consulta no ha devuelto ninguna fila')) ?>
      <span class="text-body-secondary"><?= t('({1} ms)', [1 => number_format($ms, 1, ',', '.')]) ?></span>.
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= count($resultado) ?> <?= h(t('fila(s)')) ?>
          <span class="text-body-secondary ms-2"><?= number_format($ms, 1, ',', '.') ?> <?= h(t('ms')) ?></span></span>
        <div class="d-flex gap-2">
          <?php foreach (['csv' => ['CSV', 'filetype-csv'], 'sql' => ['INSERT', 'filetype-sql']] as $f => $b): ?>
            <form method="post">
              <?= csrf() ?>
              <input type="hidden" name="accion" value="exportar">
              <input type="hidden" name="formato" value="<?= h($f) ?>">
              <input type="hidden" name="db" value="<?= h($base) ?>">
              <input type="hidden" name="sql" value="<?= h($sql) ?>">
              <input type="hidden" name="volver" value="sql">
              <button class="btn btn-sm btn-outline-secondary" title="<?= h(t('Exportar este resultado')) ?>">
                <?= icono($b[1]) ?> <?= h($b[0]) ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-striped tabla-datos mb-0">
          <thead><tr>
            <?php foreach (array_keys($resultado[0]) as $col): ?>
              <th><?= h($col) ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($resultado as $f): ?>
            <tr><?php foreach ($f as $v): ?><td><?= celda($v) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>
