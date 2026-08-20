<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">
    <a class="link-secondary text-decoration-none" href="<?= h(url(['p' => 'tablas', 'db' => $base])) ?>">
      <i class="bi bi-database"></i> <?= h($base) ?></a>
    <span class="text-body-tertiary">/</span> <i class="bi bi-table"></i> <?= h($tabla) ?>
  </h1>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link<?= $vistaActual === 'datos' ? ' active' : '' ?>"
       href="<?= h(url(['p' => 'datos', 'db' => $base, 'tabla' => $tabla])) ?>">
      <i class="bi bi-list-ul"></i> Datos</a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?= $vistaActual === 'estructura' ? ' active' : '' ?>"
       href="<?= h(url(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla])) ?>">
      <i class="bi bi-diagram-3"></i> Estructura</a>
  </li>
</ul>
