<?php
$dias  = Audit::dias();
$dia   = get('dia', $dias[0] ?? date('Y-m-d'));
$busca = minus(get('q'));

// De 500 en 500, de los más recientes a los más antiguos (2.10: el día
// entero, que con muchos eventos no cabía en la memoria ni en la página)
$porPagina = 500;
$desde = max(0, (int)get('desde', '0'));
[$eventos, $hayMas] = in_array($dia, $dias, true) ? Audit::dia($dia, $porPagina, $busca, $desde) : [[], false];
$conservan = ADMIN_AUDIT_DIAS > 0 ? t('{n} días', ['n' => (int)ADMIN_AUDIT_DIAS]) : t('siempre');
?>
<div class="page-head">
  <div>
    <h1><?= h(t('Auditoría')) ?></h1>
    <p><?= h(t('Quién ha hecho qué en el panel, día a día')) ?></p>
  </div>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
  <input type="hidden" name="p" value="auditoria">
  <div class="col-auto">
    <label class="form-label" for="dia"><?= h(t('Día')) ?></label>
    <select class="form-select form-select-sm" id="dia" name="dia" data-enviar-al-cambiar>
      <?php if ($dias === []): ?><option><?= h($dia) ?></option><?php endif; ?>
      <?php foreach ($dias as $d): ?>
        <option<?= $d === $dia ? ' selected' : '' ?>><?= h($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label" for="q"><?= h(t('Buscar')) ?></label>
    <input class="form-control form-control-sm" id="q" name="q" value="<?= h(get('q')) ?>"
           placeholder="<?= h(t('usuario, acción, tabla…')) ?>">
  </div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary"><?= h(t('Filtrar')) ?></button></div>
  <div class="col-auto text-body-secondary small pb-2">
    <?= $desde === 0 && !$hayMas ? t('{1} evento(s) · se conservan {2}', [1 => count($eventos), 2 => $conservan])
        : t('Eventos del {1} al {2}, del más reciente al más antiguo · se conservan {3}', [1 => $desde + 1, 2 => $desde + count($eventos), 3 => $conservan]) ?>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-striped mb-0 align-middle">
      <thead><tr><th><?= h(t('Hora')) ?></th><th><?= h(t('Usuario')) ?></th><th>IP</th><th><?= h(t('Base')) ?></th><th><?= h(t('Acción')) ?></th><th><?= h(t('Detalle')) ?></th></tr></thead>
      <tbody>
      <?php if ($eventos === []): ?>
        <tr><td colspan="6" class="text-body-secondary"><?= h(t('Sin eventos.')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($eventos as $e): ?>
        <tr>
          <td class="small text-nowrap"><?= h(substr((string)($e['ts'] ?? ''), 11)) ?></td>
          <td class="small"><?= h($e['usuario'] ?? '') ?></td>
          <td class="small text-body-secondary"><?= h($e['ip'] ?? '') ?></td>
          <td class="small"><?= h($e['base'] ?? '') ?></td>
          <td class="small">
            <span class="badge text-bg-<?= str_contains((string)($e['accion'] ?? ''), 'error')
                  || ($e['accion'] ?? '') === 'acceso_fallido' ? 'danger' : 'secondary' ?>">
              <?= h($e['accion'] ?? '') ?></span>
          </td>
          <td class="small"><?= celda($e['detalle'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($desde > 0 || $hayMas): ?>
  <p class="mt-2 d-flex gap-2">
    <?php if ($desde > 0): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(url(['p' => 'auditoria', 'dia' => $dia, 'q' => get('q'), 'desde' => max(0, $desde - $porPagina)])) ?>">
        <?= h(t('Más recientes')) ?></a>
    <?php endif; ?>
    <?php if ($hayMas): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(url(['p' => 'auditoria', 'dia' => $dia, 'q' => get('q'), 'desde' => $desde + $porPagina])) ?>">
        <?= h(t('Anteriores')) ?></a>
    <?php endif; ?>
  </p>
<?php endif; ?>
