<?php
/**
 * Cabecera de las páginas de una tabla: nombre y pestañas.
 *
 * https://miguelenred.es/jsonsqldb
 */
?>
<div class="object-head">
  <div class="object-title">
    <span class="object-ico"><?= icono('table') ?></span>
    <div><h1><?= h($tabla) ?></h1><span class="object-kind"><?= t('Tabla de {1}', [1 => h($base)]) ?></span></div>
  </div>
  <nav class="tabs" aria-label="<?= h(t('Secciones de la tabla')) ?>">
    <a class="tab<?= $vistaActual === 'datos' ? ' active' : '' ?>"
       href="<?= h(url(['p' => 'datos', 'db' => $base, 'tabla' => $tabla])) ?>"><?= icono('table') ?><?= h(t('Datos')) ?></a>
    <a class="tab<?= $vistaActual === 'estructura' ? ' active' : '' ?>"
       href="<?= h(url(['p' => 'estructura', 'db' => $base, 'tabla' => $tabla])) ?>"><?= icono('columns') ?><?= h(t('Estructura')) ?></a>
    <a class="tab" href="<?= h(url(['p' => 'sql', 'db' => $base,
       'sql' => 'SELECT * FROM ' . cita($tabla) . ' LIMIT 100'])) ?>"><?= icono('terminal') ?>SQL</a>
  </nav>
</div>
