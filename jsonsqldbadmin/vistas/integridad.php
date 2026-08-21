<?php
$admin = Auth::esAdmin();

// La comprobación es de solo lectura; la corrección escribe y pide ser admin
// El formulario no manda 'accion' a propósito: esta pantalla resuelve su propio
// POST, igual que el editor SQL, en vez de pasar por el despachador de acciones.
$corregir = $admin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && post('corregir') === '1';
$resultado = null;
$mensaje   = null;
$error     = null;

if ($corregir) {
    try {
        Auth::comprobarCsrf();
        $r = Api::sql($base, 'REPAIR KEYS');
        $mensaje = (string)$r['mensaje'];
        Audit::registrar('corregir_claves', $mensaje, $base);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $resultado = Api::sql($base, 'CHECK KEYS');
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">
    <a class="link-secondary text-decoration-none" href="<?= h(url(['p' => 'tablas', 'db' => $base])) ?>">
      <i class="bi bi-database"></i> <?= h($base) ?></a>
    <span class="text-body-tertiary">/</span> <i class="bi bi-shield-check"></i> Integridad
  </h1>
  <a class="btn btn-sm btn-outline-secondary"
     href="<?= h(url(['p' => 'integridad', 'db' => $base])) ?>">
    <i class="bi bi-arrow-clockwise"></i> Volver a comprobar</a>
</div>

<?php if ($error !== null): ?>
  <div class="alert alert-danger"><i class="bi bi-x-octagon"></i> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($mensaje !== null): ?>
  <div class="alert alert-info"><i class="bi bi-wrench"></i> <?= h($mensaje) ?></div>
<?php endif; ?>

<?php if ($resultado === []): ?>
  <div class="alert alert-success">
    <i class="bi bi-check2-circle"></i>
    <strong>Todo correcto.</strong> Ninguna fila apunta a un valor que no exista en su tabla
    destino.
  </div>
<?php elseif (is_array($resultado)): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    <strong><?= count($resultado) ?> fila(s) huérfana(s).</strong>
    Apuntan a valores que ya no existen en la tabla destino. Trabajando por SQL esto no puede
    pasar: casi siempre es que alguien editó un <code>.json</code> a mano o restauró la copia de
    una tabla sin la otra.
  </div>

  <div class="card mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead><tr>
          <th>Tabla</th><th>Restricción</th><th>Columnas</th><th>Valor huérfano</th>
          <th>Apunta a</th><th>¿Corregible?</th>
        </tr></thead>
        <tbody>
        <?php foreach ($resultado as $p): ?>
          <tr>
            <td><?= h($p['tabla']) ?></td>
            <td class="small text-body-secondary"><?= h($p['restriccion']) ?></td>
            <td class="small"><?= h($p['columnas']) ?></td>
            <td class="small"><code><?= h($p['valor']) ?></code></td>
            <td class="small text-body-secondary"><?= h($p['apunta_a']) ?></td>
            <td>
              <?php if ((int)$p['corregible'] === 1): ?>
                <span class="badge text-bg-success">sí, poniendo NULL</span>
              <?php else: ?>
                <span class="badge text-bg-secondary" title="La columna no admite NULL">
                  a mano</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($admin): ?>
    <?php $corregibles = count(array_filter($resultado, static fn(array $p): bool => (int)$p['corregible'] === 1)); ?>
    <div class="card">
      <div class="card-body">
        <h2 class="h6">Corregir automáticamente</h2>
        <p class="small mb-2">
          Se pondrán a <code>NULL</code> las <strong><?= $corregibles ?></strong> fila(s) cuya
          columna lo admita. <strong>Nunca se borra ninguna fila</strong>: las que apuntan desde
          una columna «no nula» se dejan como están, porque decidir qué hacer con ese dato es cosa
          tuya, no de un botón.
        </p>
        <p class="small text-body-secondary">
          Haz una copia de la base antes, desde <em>Bases → Copia ZIP</em>.
        </p>
        <form method="post" onsubmit="return confirm('¿Poner a NULL las claves huérfanas que se puedan?');">
          <?= csrf() ?>
          <input type="hidden" name="corregir" value="1">
          <button class="btn btn-warning"<?= $corregibles === 0 ? ' disabled' : '' ?>>
            <i class="bi bi-wrench"></i> Corregir <?= $corregibles ?> fila(s)</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card mt-3">
  <div class="card-body small text-body-secondary">
    <strong>Qué comprueba:</strong> que toda fila con una clave foránea apunte a una fila que
    existe en su tabla destino. Las filas con <code>NULL</code> en esas columnas no cuentan, igual
    que en la comprobación normal del motor.
    <br>
    <strong>Lee del disco</strong>, saltándose la caché a propósito: la caché solo se invalida
    cuando escribe el motor, así que una edición a mano seguiría oculta si no fuera así.
    <br>
    Desde SQL: <code>CHECK KEYS</code> para comprobar y <code>REPAIR KEYS</code> para corregir,
    con <code>FROM tabla</code> opcional para limitarlo a una tabla.
  </div>
</div>
