<?php
$programas = Copias::programaciones();
$bases     = array_column(Api::sql('', 'SHOW DATABASES'), 'base');
$zip       = class_exists('ZipArchive');
$cron      = str_replace('\\', '/', dirname(__DIR__)) . '/herramientas/copias-cron.php';
$dias      = [1 => t('lunes'), 2 => t('martes'), 3 => t('miércoles'), 4 => t('jueves'), 5 => t('viernes'), 6 => t('sábado'), 7 => t('domingo')];
$cuando    = static function (array $p) use ($dias): string {
    if ($p['frecuencia'] === 'horas') {
        return t('cada {n} hora(s)', ['n' => (int)$p['horas']]);
    }
    $hora = sprintf('%02d:00', (int)$p['hora']);
    return $p['frecuencia'] === 'diaria' ? t('cada día a las {h}', ['h' => $hora])
        : t('cada {dia} a las {h}', ['dia' => $dias[(int)$p['dia']] ?? '', 'h' => $hora]);
};
?>
<div class="page-head">
  <div>
    <h1><?= h(t('Copias programadas')) ?></h1>
    <p><?= h(t('Copias automáticas de las bases, en ZIP o en volcado SQL')) ?></p>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><?= icono('history') ?> <?= h(t('Programación')) ?></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th><?= h(t('Base')) ?></th><th><?= h(t('Cuándo')) ?></th><th><?= h(t('Formato')) ?></th>
            <th><?= h(t('Conservar')) ?></th><th><?= h(t('Última')) ?></th><th class="text-end"><?= h(t('Acciones')) ?></th></tr></thead>
          <tbody>
          <?php if ($programas === []): ?>
            <tr><td colspan="6" class="text-body-secondary small"><?= h(t('No hay ninguna copia programada.')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($programas as $p): ?>
            <tr>
              <td><strong><?= h($p['base']) ?></strong></td>
              <td class="small"><?= h($cuando($p)) ?></td>
              <td><span class="badge text-bg-light"><?= h(strtoupper($p['formato'])) ?></span></td>
              <td class="small"><?= (int)$p['conservar'] ?></td>
              <td class="small"><?= h(Copias::ultima($p)) ?>
                <?php if (($p['error'] ?? null) !== null): ?><br><span class="text-danger"><?= h($p['error']) ?></span><?php endif; ?></td>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline">
                  <?= csrf() ?>
                  <input type="hidden" name="accion" value="copia_ahora">
                  <input type="hidden" name="id" value="<?= h($p['id']) ?>">
                  <input type="hidden" name="volver" value="copias">
                  <button class="btn btn-sm btn-outline-secondary" title="<?= h(t('Hacer la copia ahora')) ?>"><?= icono('play') ?></button>
                </form>
                <form method="post" class="d-inline" data-confirm="<?= h(t('¿Quitar esta programación? Las copias ya hechas se quedan.')) ?>">
                  <?= csrf() ?>
                  <input type="hidden" name="accion" value="borrar_programacion">
                  <input type="hidden" name="id" value="<?= h($p['id']) ?>">
                  <input type="hidden" name="volver" value="copias">
                  <button class="btn btn-sm btn-outline-danger"><?= icono('trash') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php foreach (array_unique(array_column($programas, 'base')) as $b): $ficheros = array_reverse(Copias::ficheros($b)); ?>
    <div class="card mb-3">
      <div class="card-header"><?= icono('archive') ?> <?= h(t('Copias de «{base}»', ['base' => $b])) ?></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <tbody>
          <?php if ($ficheros === []): ?>
            <tr><td class="text-body-secondary small"><?= h(t('Todavía no hay copias.')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($ficheros as $f): ?>
            <tr>
              <td class="small"><?= h($f) ?></td>
              <td class="small text-body-secondary"><?= h(Idioma::numero((int)ceil((int)filesize(Copias::ruta($b, $f)) / 1024))) ?> KB</td>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline">
                  <?= csrf() ?>
                  <input type="hidden" name="accion" value="descargar_copia">
                  <input type="hidden" name="nombre" value="<?= h($b) ?>">
                  <input type="hidden" name="fichero" value="<?= h($f) ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="<?= h(t('Descargar')) ?>"><?= icono('download') ?></button>
                </form>
                <form method="post" class="d-inline" data-confirm="<?= h(t('¿Borrar esta copia?')) ?>">
                  <?= csrf() ?>
                  <input type="hidden" name="accion" value="borrar_copia">
                  <input type="hidden" name="nombre" value="<?= h($b) ?>">
                  <input type="hidden" name="fichero" value="<?= h($f) ?>">
                  <input type="hidden" name="volver" value="copias">
                  <button class="btn btn-sm btn-outline-danger"><?= icono('trash') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><?= icono('plus') ?> <?= h(t('Nueva copia programada')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="programar_copia">
          <input type="hidden" name="volver" value="copias">
          <div class="mb-2">
            <label class="form-label" for="cp-base"><?= h(t('Base')) ?></label>
            <select class="form-select" id="cp-base" name="base" required>
              <?php foreach ($bases as $b): ?><option><?= h((string)$b) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label" for="cp-frec"><?= h(t('Frecuencia')) ?></label>
              <select class="form-select" id="cp-frec" name="frecuencia">
                <option value="diaria"><?= h(t('Cada día')) ?></option>
                <option value="semanal"><?= h(t('Cada semana')) ?></option>
                <option value="horas"><?= h(t('Cada N horas')) ?></option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="cp-horas"><?= h(t('N horas')) ?></label>
              <input class="form-control" id="cp-horas" type="number" name="horas" min="1" max="168" value="6">
            </div>
            <div class="col-6">
              <label class="form-label" for="cp-dia"><?= h(t('Día (semanal)')) ?></label>
              <select class="form-select" id="cp-dia" name="dia">
                <?php foreach ($dias as $n => $d): ?><option value="<?= $n ?>"><?= h($d) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="cp-hora"><?= h(t('Hora')) ?></label>
              <select class="form-select" id="cp-hora" name="hora">
                <?php for ($i = 0; $i < 24; $i++): ?><option value="<?= $i ?>"<?= $i === 3 ? ' selected' : '' ?>><?= sprintf('%02d:00', $i) ?></option><?php endfor; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="cp-formato"><?= h(t('Formato')) ?></label>
              <select class="form-select" id="cp-formato" name="formato">
                <option value="zip"<?= $zip ? '' : ' disabled' ?>>ZIP</option>
                <option value="sql"<?= $zip ? '' : ' selected' ?>><?= h(t('Volcado SQL')) ?></option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="cp-conservar"><?= h(t('Copias que se conservan')) ?></label>
              <input class="form-control" id="cp-conservar" type="number" name="conservar" min="1" max="365" value="7">
            </div>
          </div>
          <button class="btn btn-primary"><?= icono('plus') ?> <?= h(t('Programar')) ?></button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><?= icono('info') ?> <?= h(t('Quién hace las copias')) ?></div>
      <div class="card-body small">
        <p><?= t('Lo mejor es el <strong>cron</strong> del servidor, cada 15 minutos (hace solo las que tocan):') ?></p>
        <pre class="bg-body-tertiary p-2 rounded"><code>0,15,30,45 * * * * php <?= h($cron) ?></code></pre>
        <p><?= t('En Windows, con el <strong>Programador de tareas</strong>:') ?></p>
        <pre class="bg-body-tertiary p-2 rounded"><code>schtasks /create /tn "jsonSQLDB copias" /sc minute /mo 15 /tr "php.exe <?= h(str_replace('/', '\\', $cron)) ?>"</code></pre>
        <p class="mb-0"><?= t('<strong>Sin cron</strong>, las hace el panel: cuando alguien lo abre y hay una pendiente, el navegador la pide aparte sin esperar la respuesta. Así una copia puede llegar tarde si nadie entra. Se guardan en la carpeta <code>copias/</code> de los datos del panel, en la que PHP tiene que poder escribir.') ?></p>
      </div>
    </div>
  </div>
</div>
