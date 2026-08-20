<?php
$sql       = (string)($_POST['sql'] ?? $_GET['sql'] ?? '');
$resultado = null;
$error     = null;
$ms        = 0.0;

if ($sql !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Auth::comprobarCsrf();
        if (!Auth::esAdmin() && !preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
            throw new RuntimeException('Con permiso de lectura solo se pueden lanzar SELECT y SHOW.');
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
<h1 class="h5 mb-3">
  <a class="link-secondary text-decoration-none" href="<?= h(url(['p' => 'tablas', 'db' => $base])) ?>">
    <i class="bi bi-database"></i> <?= h($base) ?></a>
  <span class="text-body-tertiary">/</span> <i class="bi bi-terminal"></i> SQL
</h1>

<div class="card mb-3">
  <div class="card-body">
    <form method="post">
      <?= csrf() ?>
      <textarea class="form-control sql-area" name="sql" rows="7" required
                placeholder="SELECT * FROM mi_tabla WHERE ..."><?= h($sql) ?></textarea>
      <div class="d-flex justify-content-between align-items-center mt-2">
        <div class="form-text mb-0">
          Una sentencia por ejecución. Admite varias líneas y comentarios <code>--</code> y <code>/* */</code>.
        </div>
        <button class="btn btn-primary"><i class="bi bi-play-fill"></i> Ejecutar</button>
      </div>
    </form>
  </div>
</div>

<?php if ($error !== null): ?>
  <div class="alert alert-danger"><i class="bi bi-x-octagon"></i> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($resultado !== null): ?>
  <?php if (isset($resultado['success'])): ?>
    <div class="alert alert-success">
      <i class="bi bi-check2-circle"></i> <?= h($resultado['mensaje']) ?>
      <span class="text-body-secondary">(<?= number_format($ms, 1, ',', '.') ?> ms)</span>
    </div>
  <?php elseif ($resultado === []): ?>
    <div class="alert alert-secondary">
      La consulta no ha devuelto ninguna fila
      <span class="text-body-secondary">(<?= number_format($ms, 1, ',', '.') ?> ms)</span>.
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= count($resultado) ?> fila(s)
          <span class="text-body-secondary ms-2"><?= number_format($ms, 1, ',', '.') ?> ms</span></span>
        <div class="d-flex gap-2">
          <?php foreach (['csv' => ['CSV', 'filetype-csv'], 'sql' => ['INSERT', 'filetype-sql']] as $f => $b): ?>
            <form method="post">
              <?= csrf() ?>
              <input type="hidden" name="accion" value="exportar">
              <input type="hidden" name="formato" value="<?= h($f) ?>">
              <input type="hidden" name="db" value="<?= h($base) ?>">
              <input type="hidden" name="sql" value="<?= h($sql) ?>">
              <input type="hidden" name="volver" value="sql">
              <button class="btn btn-sm btn-outline-secondary" title="Exportar este resultado">
                <i class="bi bi-<?= h($b[1]) ?>"></i> <?= h($b[0]) ?></button>
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
