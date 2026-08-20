<?php
/** @var string $vistaActual */
$suelto  = in_array($vistaActual, ['login', 'instalar'], true);
$usuario = Auth::usuario();
$baseAct = $base ?? '';
$mensajes = flashes();
?><!doctype html>
<html lang="es" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>jsonSQLDBadmin</title>
<link rel="stylesheet" href="assets/bootstrap.min.css">
<link rel="stylesheet" href="assets/bootstrap-icons.css">
<style>
    body { font-size: .925rem; }
    .tabla-datos td, .tabla-datos th { white-space: nowrap; vertical-align: middle; }
    .barra-lateral { min-width: 230px; max-width: 230px; }
    .sql-area { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .875rem; }
    .caja-acceso { max-width: 26rem; margin: 8vh auto; }
</style>
</head>
<body class="bg-body-tertiary">

<?php if (!$suelto): ?>
<nav class="navbar navbar-expand-lg bg-dark border-bottom" data-bs-theme="dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= h(url(['p' => 'bases'])) ?>">
      <i class="bi bi-database"></i> jsonSQLDB<span class="text-info">admin</span>
      <?php if (version() !== ''): ?>
        <span class="badge text-bg-secondary align-middle ms-1"
              style="font-size:.65rem">v<?= h(version()) ?></span>
      <?php endif; ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="menu">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link<?= $vistaActual === 'bases' ? ' active' : '' ?>"
           href="<?= h(url(['p' => 'bases'])) ?>"><i class="bi bi-hdd-stack"></i> Bases</a></li>
        <?php if ($baseAct !== ''): ?>
        <li class="nav-item"><a class="nav-link<?= $vistaActual === 'tablas' ? ' active' : '' ?>"
           href="<?= h(url(['p' => 'tablas', 'db' => $baseAct])) ?>"><i class="bi bi-table"></i> Tablas</a></li>
        <li class="nav-item"><a class="nav-link<?= $vistaActual === 'sql' ? ' active' : '' ?>"
           href="<?= h(url(['p' => 'sql', 'db' => $baseAct])) ?>"><i class="bi bi-terminal"></i> SQL</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link<?= $vistaActual === 'auditoria' ? ' active' : '' ?>"
           href="<?= h(url(['p' => 'auditoria'])) ?>"><i class="bi bi-clipboard-check"></i> Auditoría</a></li>
        <li class="nav-item"><a class="nav-link<?= $vistaActual === 'usuarios' ? ' active' : '' ?>"
           href="<?= h(url(['p' => 'usuarios'])) ?>"><i class="bi bi-people"></i> Usuarios</a></li>
      </ul>
      <span class="navbar-text me-3">
        <i class="bi bi-person-circle"></i> <?= h($usuario['usuario'] ?? '') ?>
        <span class="badge text-bg-<?= ($usuario['rol'] ?? '') === 'admin' ? 'info' : 'secondary' ?>">
          <?= h($usuario['rol'] ?? '') ?></span>
      </span>
      <a class="btn btn-sm btn-outline-light" href="<?= h(url(['p' => 'salir'])) ?>">
        <i class="bi bi-box-arrow-right"></i> Salir</a>
    </div>
  </div>
</nav>
<?php endif; ?>

<main class="<?= $suelto ? '' : 'container-fluid py-3' ?>">
  <?php if ($mensajes !== []): ?>
    <div class="<?= $suelto ? 'caja-acceso mb-0' : '' ?>">
    <?php foreach ($mensajes as $m): ?>
      <div class="alert alert-<?= h($m['tipo']) ?> alert-dismissible fade show" role="alert">
        <?= h($m['texto']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php require __DIR__ . '/' . $vistaActual . '.php'; ?>
</main>

<script src="assets/bootstrap.bundle.min.js"></script>
<script src="assets/panel.js"></script>
</body>
</html>
