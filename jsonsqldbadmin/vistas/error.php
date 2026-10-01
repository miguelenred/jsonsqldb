<div class="alert alert-danger">
  <h2 class="h5 d-flex align-items-center gap-2"><?= icono('exclamation-triangle') ?> <?= h(t('No se ha podido completar la operación')) ?></h2>
  <p class="mb-2"><?= h($mensaje ?? t('Error desconocido')) ?></p>
  <a class="btn btn-sm btn-outline-danger" href="<?= h(url(['p' => 'bases'])) ?>"><?= h(t('Volver al listado de bases')) ?></a>
</div>
