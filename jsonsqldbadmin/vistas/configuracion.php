<?php
/**
 * Configuración del panel. Los administradores la cambian desde aquí; se
 * guarda en config.php, validada y, si cambia la conexión, probada antes (ver
 * Instalador::guardarConfiguracion()). Lo que dejaría fuera a quien la está
 * cambiando no se acepta. Dónde viven los usuarios y el nombre de la cookie
 * solo se cambian a mano en config.php.
 *
 * https://miguelenred.es/jsonsqldb
 */
Auth::exigirAdmin();

// Una consulta de prueba, para saber que el motor responde y cuánto tarda. La
// barra lateral ya ha pedido la lista de bases: sin olvidarla, esto mediría
// una respuesta guardada, 0,0 ms
Api::olvidarShow();
$inicio = microtime(true);
try {
    $bases  = count(Api::bases());
    $prueba = null;
} catch (Throwable $e) {
    $bases  = 0;
    $prueba = $e->getMessage();
}
$ms = (microtime(true) - $inicio) * 1000;

$directa  = Api::directa();
[$datosBien, $datosTexto] = Instalador::datosExpuestos();
$lectura  = trim((string)ADMIN_API_KEY_LECTURA) !== '';
$ajustes  = Instalador::ajustes();
$numero   = static function (string $campo, string $texto, string $ayuda = '') use ($ajustes): string {
    [$c, $min, $max] = $ajustes[$campo];
    return '<div class="col-sm-6 col-lg-4"><label class="form-label" for="' . $campo . '">' . h($texto) . '</label>'
        . '<input class="form-control" type="number" id="' . $campo . '" name="' . $campo . '" min="' . $min . '" max="' . $max
        . '" value="' . h((string)constant($c)) . '" required>'
        . ($ayuda !== '' ? '<div class="form-text">' . h($ayuda) . '</div>' : '') . '</div>';
};
?>
<div class="page-head">
  <div>
    <h1><?= h(t('Configuración')) ?></h1>
    <p><?= t('Cómo se conecta el panel y cómo se comporta. Se guarda en <code>{1}</code>', [1 => h(basename(Instalador::rutaConfig()))]) ?></p>
  </div>
</div>

<?php if (PHP_VERSION_ID < 80100): ?>
  <div class="alert alert-warning">
    <?= t('<strong>Se recomienda PHP 8.1 o posterior: PHP {1} no puede forzar los datos al disco.</strong> Al escribir, el sistema operativo guarda los datos en memoria y los pasa al disco un poco después. La función <code>fsync()</code>, que obliga a grabarlos en el momento, existe en PHP solo desde la 8.1, y en 8.0 no hay forma fiable de hacer lo mismo sin extensiones. Por eso, con 8.0, jsonSQLDB no pierde nada si el proceso muere a mitad de una escritura, pero ante un <strong>corte de luz o un fallo del sistema operativo</strong> se pueden perder las escrituras que aún no estaban en disco —en Linux con la configuración por defecto, aproximadamente los últimos 30 segundos—, aunque se hubieran dado por hechas. Las tablas no se dañan en ext4 con sus opciones por defecto. Si tu hosting ofrece PHP 8.1 o posterior, cámbialo; la explicación completa está en el README del proyecto.', [1 => h(PHP_VERSION)]) ?>
  </div>
<?php endif; ?>

<?php if ($datosBien === false): ?>
  <div class="alert alert-danger"><?= t('<strong>La carpeta de datos se puede descargar desde fuera.</strong> {1}.', [1 => h($datosTexto)]) ?></div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-4 align-items-center">
    <div><span class="text-body-secondary small"><?= h(t('Conexión')) ?></span><br><strong><?= $directa ? t('Directa al motor') : t('Por la API') ?></strong></div>
    <div><span class="text-body-secondary small"><?= h(t('Respuesta del motor')) ?></span><br>
      <?php if ($prueba === null): ?>
        <?= t('<strong class="text-success">{1} {2} en {3} ms</strong>', [1 => $bases, 2 => $bases === 1 ? 'base' : 'bases', 3 => sprintf('%.1f', $ms)]) ?>
      <?php else: ?>
        <?= t('<strong class="text-danger">no responde</strong>') ?> <span class="small"><?= h($prueba) ?></span>
      <?php endif; ?>
    </div>
    <div><span class="text-body-secondary small"><?= h(t('Versión')) ?></span><?= t('<br><strong>{1}</strong> · PHP {2}', [1 => h(version() !== '' ? version() : '—'), 2 => h(PHP_VERSION)]) ?></div>
    <div><span class="text-body-secondary small"><?= h(t('Carpeta de datos')) ?></span><br>
      <strong class="<?= $datosBien === true ? 'text-success' : ($datosBien === false ? 'text-danger' : 'text-body-secondary') ?>"><?= h($datosTexto) ?></strong></div>
    <div><span class="text-body-secondary small"><?= h(t('Usuarios y auditoría')) ?></span><br><code class="small"><?= h((string)ADMIN_DATA_PATH) ?></code></div>
  </div>
</div>

<form method="post" autocomplete="off">
  <?= csrf() ?>
  <input type="hidden" name="accion" value="guardar_configuracion">

  <div class="card mb-3">
    <div class="card-header"><?= icono('plug') ?> <?= h(t('Conexión con el motor')) ?></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="conexion" id="cDirecta" value="directa" <?= $directa ? 'checked' : '' ?>>
            <label class="form-check-label" for="cDirecta"><?= h(t('Directa')) ?></label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="conexion" id="cApi" value="api" <?= $directa ? '' : 'checked' ?>>
            <label class="form-check-label" for="cApi"><?= h(t('Por la API')) ?></label>
          </div>
          <div class="form-text"><?= h(t('Si cambia la conexión, se prueba antes de guardar: con una que no responde no se guarda nada.')) ?></div>
        </div>
        <div class="col-lg-6">
          <label class="form-label" for="motor"><?= h(t('Carpeta de jsonSQLDB')) ?> <span class="text-body-secondary"><?= h(t('(directa)')) ?></span></label>
          <input class="form-control" id="motor" name="motor" value="<?= h((string)ADMIN_MOTOR_RUTA) ?>"
                 placeholder="<?= h(Instalador::motorDetectado()) ?>">
          <div class="form-text"><?= h(t('Vacía = la que está junto al panel.')) ?></div>
        </div>
        <div class="col-lg-6">
          <label class="form-label" for="api_url"><?= h(t('URL de la API')) ?> <span class="text-body-secondary">(API)</span></label>
          <input class="form-control" id="api_url" name="api_url" value="<?= h((string)ADMIN_API_URL) ?>"
                 placeholder="<?= h(Api::urlDeducida()) ?>">
          <div class="form-text"><?= h(t('Vacía = la de esta instalación.')) ?></div>
          <?php if ((string)ADMIN_API_URL === '' && !Api::directa()): ?>
            <div class="form-text text-warning"><?= h(t('Ahora se deduce en cada petición de la cabecera Host, que manda el navegador: guarda la configuración para dejarla escrita.')) ?></div>
          <?php endif; ?>
        </div>
        <div class="col-lg-6">
          <label class="form-label" for="api_key"><?= h(t('API key de administración')) ?></label>
          <input class="form-control font-monospace" id="api_key" name="api_key"
                 placeholder="<?= trim((string)ADMIN_API_KEY) === '' ? t('sin configurar') : t('configurada (vacío = no cambiarla)') ?>">
        </div>
        <div class="col-lg-6">
          <label class="form-label" for="api_secret"><?= h(t('Su secreto HMAC')) ?></label>
          <input class="form-control font-monospace" id="api_secret" name="api_secret" type="password" autocomplete="new-password"
                 placeholder="<?= trim((string)ADMIN_HMAC_SECRET) === '' ? t('sin configurar') : t('configurado (vacío = no cambiarlo)') ?>">
        </div>
        <div class="col-lg-6">
          <label class="form-label" for="lectura_key"><?= h(t('API key de solo lectura')) ?> <span class="text-body-secondary"><?= h(t('(opcional)')) ?></span></label>
          <input class="form-control font-monospace" id="lectura_key" name="lectura_key"
                 placeholder="<?= $lectura ? t('configurada (vacío = no cambiarla)') : t('sin configurar') ?>">
          <div class="form-text"><?= h(t('Con ella firman los usuarios de solo lectura y el motor rechaza cualquier escritura suya. Tiene que poder ver todas las bases.')) ?></div>
        </div>
        <div class="col-lg-6">
          <label class="form-label" for="lectura_secret"><?= h(t('Su secreto HMAC')) ?></label>
          <input class="form-control font-monospace" id="lectura_secret" name="lectura_secret" type="password" autocomplete="new-password"
                 placeholder="<?= $lectura ? t('configurado (vacío = no cambiarlo)') : t('sin configurar') ?>">
          <?php if ($lectura): ?>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="quitar_lectura" name="quitar_lectura" value="1">
              <label class="form-check-label" for="quitar_lectura"><?= h(t('Quitar la clave de solo lectura')) ?></label>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-lg-6">
          <label class="form-label" for="ssl_ca"><?= h(t('Certificado de la CA')) ?> <span class="text-body-secondary"><?= h(t('(API por HTTPS con CA propia)')) ?></span></label>
          <input class="form-control" id="ssl_ca" name="ssl_ca" value="<?= h((string)ADMIN_SSL_CA) ?>" placeholder="/ruta/a/ca.pem">
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="ssl_autofirmado" name="ssl_autofirmado" value="1" <?= ADMIN_SSL_AUTOFIRMADO ? 'checked' : '' ?>>
            <label class="form-check-label" for="ssl_autofirmado"><?= h(t('Aceptar un certificado autofirmado sin comprobarlo (solo pruebas)')) ?></label>
          </div>
        </div>
        <?= $numero('timeout', t('Tiempo máximo de una llamada a la API (s)')) ?>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><?= icono('shield') ?> <?= h(t('Seguridad')) ?></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-6">
          <label class="form-label" for="ips"><?= h(t('IPs permitidas')) ?></label>
          <textarea class="form-control font-monospace" id="ips" name="ips" rows="3"
                    placeholder="<?= h(t('Vacío = cualquiera')) ?>"><?= h(implode("\n", (array)ADMIN_IPS_PERMITIDAS)) ?></textarea>
          <div class="form-text"><?= t('Una por línea; también rangos como 192.168.1.0/24. Tu IP ahora: <code>{1}</code>. Una lista sin ella no se acepta.', [1 => h(util_ip())]) ?></div>
        </div>
        <div class="col-lg-6">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="exigir_https" name="exigir_https" value="1" <?= ADMIN_EXIGIR_HTTPS ? 'checked' : '' ?>>
            <label class="form-check-label" for="exigir_https"><?= h(t('Exigir HTTPS')) ?></label>
            <div class="form-text"><?= h(t('Solo se puede activar entrando ya por HTTPS.')) ?></div>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="confiar_proxy" name="confiar_proxy" value="1" <?= ADMIN_CONFIAR_EN_PROXY ? 'checked' : '' ?>>
            <label class="form-check-label" for="confiar_proxy"><?= h(t('Hay un proxy de confianza delante (usar su cabecera X-Forwarded-For)')) ?></label>
            <div class="form-text"><?= h(t('Actívalo solo si es así: si no, cualquiera podría falsear su IP.')) ?></div>
          </div>
        </div>
        <?= $numero('sesion_minutos', t('Minutos de inactividad hasta cerrar la sesión')) ?>
        <?= $numero('login_max_fallos', t('Intentos fallidos antes de bloquear')) ?>
        <?= $numero('bloqueo_min', t('Minutos de bloqueo')) ?>
        <?= $numero('bcrypt', t('Coste de bcrypt'), t('Cada punto duplica el tiempo de comprobar una contraseña.')) ?>
        <?= $numero('audit_dias', t('Días que se guarda la auditoría')) ?>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><?= icono('table') ?> <?= h(t('Datos y exportación')) ?></div>
    <div class="card-body">
      <div class="row g-3">
        <?= $numero('filas_pagina', t('Filas por página')) ?>
        <?= $numero('celda_max', t('Caracteres visibles por celda')) ?>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label" for="csv_separador"><?= h(t('Separador del CSV')) ?></label>
          <select class="form-select" id="csv_separador" name="csv_separador">
            <?php foreach ([';' => t('Punto y coma (Excel en español)'), ',' => t('Coma'), 'tab' => t('Tabulador')] as $val => $txt): ?>
              <option value="<?= h($val) ?>" <?= (ADMIN_CSV_SEPARADOR === ($val === 'tab' ? "\t" : $val)) ? 'selected' : '' ?>><?= h($txt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 align-items-center mb-4">
    <button class="btn btn-primary"><?= icono('check') ?> <?= h(t('Guardar la configuración')) ?></button>
    <span class="small text-body-secondary"><?= t('Dónde se guardan los usuarios y el nombre de la cookie de sesión solo se cambian a mano en <code>config.php</code>. Para volver al asistente, borra ese fichero.') ?></span>
  </div>
</form>
