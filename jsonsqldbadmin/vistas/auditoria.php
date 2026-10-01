<?php
$dias  = Audit::dias();
$dia   = get('dia', $dias[0] ?? date('Y-m-d'));
$busca = minus(get('q'));

$eventos = in_array($dia, $dias, true) ? Audit::dia($dia) : [];
if ($busca !== '') {
    $eventos = array_values(array_filter($eventos, static function (array $e) use ($busca): bool {
        return str_contains(minus(implode(' ', array_map('strval', $e))), $busca);
    }));
}
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
    <select class="form-select form-select-sm" id="dia" name="dia" onchange="this.form.submit()">
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
    <?= t('{1} evento(s) · se conservan {2}', [1 => count($eventos), 2 => ADMIN_AUDIT_DIAS > 0 ? t('{n} días', ['n' => (int)ADMIN_AUDIT_DIAS]) : t('siempre')]) ?>
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
