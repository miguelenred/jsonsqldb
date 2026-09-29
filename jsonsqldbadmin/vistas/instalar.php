<?php
/**
 * Asistente del primer arranque (ver lib/Instalador.php).
 *
 *   $completo  true: conexión con el motor + administrador; false: solo falta
 *              el administrador, porque config.php ya está hecho
 *   $resumen   si la instalación ha terminado, lo que se ha configurado
 *
 * https://miguelenred.es/jsonsqldb
 */
$completo = $completo ?? false;
$modo     = post('conexion', Instalador::motorDetectado() !== '' ? 'directa' : 'api');
$apiLocal = is_file(Instalador::rutaConfigApi());
$conUsuario = !Auth::hayUsuarios();
$pasos    = $completo ? ($conUsuario ? ['1 Conexión', '2 Seguridad', '3 Administrador'] : ['1 Conexión', '2 Seguridad'])
                      : ['Administrador'];
?>
<div class="setup-wrap">
<?php if (!empty($resumen)): ?>
  <div class="setup-card">
    <div class="setup-success">
      <div class="tick"><?= icono('check') ?></div>
      <h2>jsonSQLDBadmin está instalado</h2>
      <p>La configuración se ha guardado en <code><?= h(basename(Instalador::rutaConfig())) ?></code>.
         Ya puedes entrar.</p>
      <table class="kv mx-auto mb-4" style="max-width:520px">
        <?php foreach ($resumen as $k => $v): ?>
          <tr><th><?= h($k) ?></th><td class="text-end"><?= h($v) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <a href="<?= h(url()) ?>" class="btn btn-primary">Ir al panel →</a>
    </div>
  </div>
  <div class="setup-foot">jsonSQLDBadmin<?= version() !== '' ? ' v' . h(version()) : '' ?> · PHP <?= h(PHP_VERSION) ?> ·
    <a href="https://miguelenred.es/jsonsqldb" target="_blank" rel="noopener">miguelenred.es/jsonsqldb</a></div>
<?php return; endif; ?>

  <div class="setup-top">
    <div class="setup-logo"><?= icono('database') ?></div>
    <div>
      <h1>jsonSQLDBadmin</h1>
      <p><?= $completo ? 'Asistente de configuración inicial' : 'Crea el administrador' ?></p>
    </div>
    <div class="setup-steps"><?php foreach ($pasos as $p): ?><span class="setup-step"><?= h($p) ?></span><?php endforeach; ?></div>
  </div>

  <div class="setup-grid">
    <aside class="setup-aside">
      <div class="setup-card">
        <h2>Comprobaciones del servidor</h2>
        <p class="sub">Lo que el panel necesita para funcionar</p>
        <?php foreach (Instalador::comprobaciones() as [$bien, $texto]): ?>
          <span class="setup-chip <?= $bien === false ? 'setup-chip-off' : ($bien === true ? 'setup-chip-on' : '') ?>"
                <?= $bien === null ? 'style="border:1px solid var(--line);color:var(--muted)"' : '' ?>>
            <?= $bien === false ? '✕' : ($bien === true ? '✓' : '!') ?> <?= h($texto) ?></span>
        <?php endforeach; ?>
        <div class="kv">
          <div><b>Configuración</b> <?= h(Instalador::rutaConfig()) ?></div>
          <div><b>Usuarios del panel</b> <?= h((string)ADMIN_DATA_PATH) ?></div>
        </div>
      </div>

      <?php if ($completo): ?>
      <div class="setup-card" style="margin-top:18px">
        <h2>¿Qué conexión elegir?</h2>
        <p class="sub">Consejos rápidos</p>
        <ul>
          <li><strong>Directa</strong> si el panel y los datos están en el mismo servidor: no hay claves que
              configurar y cada consulta se ahorra una petición HTTP.</li>
          <li><strong>API</strong> si el motor está en otra máquina, o si prefieres que el panel pase por la
              misma puerta firmada que tus aplicaciones.</li>
          <li>Con las dos, el motor aplica los permisos de cada usuario del panel: uno de solo lectura no
              puede escribir aunque el panel se equivocara.</li>
          <li>Para cambiarla después, borra <code>config.php</code> y vuelve a abrir el panel.</li>
        </ul>
      </div>
      <?php endif; ?>
    </aside>

    <div class="setup-card">
      <form method="post" autocomplete="off">
        <?= csrf() ?>
        <?php foreach ($mensajes as $m): ?>
          <div class="setup-section pb-0"><div class="alert alert-<?= h($m['tipo']) ?> mb-0"><?= h($m['texto']) ?></div></div>
        <?php endforeach; ?>
        <?php if (!empty($error)): ?>
          <div class="setup-section pb-0"><div class="alert alert-danger mb-0"><?= h($error) ?></div></div>
        <?php endif; ?>

        <?php if ($completo): ?>
        <div class="setup-section">
          <h3><span class="num">1</span> Conexión con el motor</h3>
          <div class="setup-options">
            <div class="setup-option">
              <input type="radio" name="conexion" id="conDirecta" value="directa" <?= $modo === 'directa' ? 'checked' : '' ?>>
              <label class="chead" for="conDirecta"><span class="radio-dot"></span><span class="oname">Conexión directa</span>
                <span class="otag"><?= Instalador::motorDetectado() !== '' ? 'motor encontrado' : 'sin motor aquí' ?></span></label>
              <div class="odesc">El panel carga el motor y le habla sin HTTP. Para cuando el panel y los datos están en el mismo servidor.</div>
              <div class="extra">
                <label class="form-label" for="motor">Carpeta de jsonSQLDB</label>
                <input class="form-control" id="motor" name="motor" value="<?= h(post('motor')) ?>"
                       placeholder="<?= h(Instalador::motorDetectado() ?: '/ruta/a/jsonsqldb') ?>">
                <div class="form-text">La que contiene <code>engine/</code> y <code>config.php</code>. Vacía = la que está junto al panel.</div>
              </div>
            </div>
            <div class="setup-option">
              <input type="radio" name="conexion" id="conApi" value="api" <?= $modo === 'api' ? 'checked' : '' ?>>
              <label class="chead" for="conApi"><span class="radio-dot"></span><span class="oname">Por la API</span>
                <span class="otag">HMAC</span></label>
              <div class="odesc">Peticiones firmadas a <code>api/jsonsqldb_api.php</code>, en este servidor o en otro.</div>
              <div class="extra">
                <div class="mb-3">
                  <label class="form-label" for="api_url">URL de la API</label>
                  <input class="form-control" id="api_url" name="api_url" value="<?= h(post('api_url')) ?>"
                         placeholder="<?= h(Api::url()) ?>">
                  <div class="form-text">Vacía = la de esta instalación.</div>
                </div>
                <?php if (!$apiLocal && Instalador::motorDetectado() !== ''): ?>
                  <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="api_generar" name="api_generar" value="1"
                           <?= post('api_generar') !== '' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="api_generar">La API de este servidor aún no está configurada:
                      créala ahora con claves nuevas</label>
                    <div class="form-text">Escribe <code>api/jsonsqldb_api_config.php</code> con claves al azar; la de
                      administración será la del panel. Si lo marcas, deja vacíos los dos campos de abajo.</div>
                  </div>
                <?php endif; ?>
                <div class="mb-3">
                  <label class="form-label" for="api_key">API key de administración</label>
                  <input class="form-control font-monospace" id="api_key" name="api_key" value="<?= h(post('api_key')) ?>">
                </div>
                <div>
                  <label class="form-label" for="api_secret">Secreto HMAC de esa cuenta</label>
                  <input class="form-control font-monospace" id="api_secret" name="api_secret" type="password">
                  <div class="form-text">Los dos están en la cuenta de administración de <code>api/jsonsqldb_api_config.php</code>.
                    Antes de guardar se prueba la conexión con ellos.</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="setup-section">
          <h3><span class="num">2</span> Seguridad</h3>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="http" name="http" value="1"
                   <?= post('http') !== '' || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !util_https()) ? 'checked' : '' ?>>
            <label class="form-check-label" for="http">Permitir entrar por HTTP, sin cifrar (solo para pruebas en tu máquina)</label>
            <div class="form-text">Déjalo desmarcado en un servidor: por el panel viajan contraseñas y datos. Se puede cambiar
              después en <code>ADMIN_EXIGIR_HTTPS</code>.</div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($conUsuario): ?>
        <div class="setup-section">
          <h3><?php if ($completo): ?><span class="num">3</span><?php endif; ?> Crea el administrador</h3>
          <?php if (!$completo): ?><p class="sub mt-1 mb-0">No hay ningún usuario todavía. El primero es administrador.</p><?php endif; ?>
          <div class="row g-3 mt-0">
            <div class="col-12">
              <label class="form-label" for="usuario">Usuario</label>
              <input class="form-control" id="usuario" name="usuario" required maxlength="32"
                     pattern="[A-Za-z0-9_.@\-]{3,32}" value="<?= h(post('usuario')) ?>">
              <div class="form-text">De 3 a 32 caracteres: letras, números y . _ - @</div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="clave">Contraseña</label>
              <input class="form-control" id="clave" name="clave" type="password" required minlength="10"
                     autocomplete="new-password">
              <div class="form-text">Mínimo 10 caracteres. Se guarda con bcrypt.</div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="clave2">Repite la contraseña</label>
              <input class="form-control" id="clave2" name="clave2" type="password" required minlength="10"
                     autocomplete="new-password">
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="setup-section">
          <p class="sub mb-0">Los usuarios del panel se conservan: al terminar, entra con el tuyo de siempre.</p>
        </div>
        <?php endif; ?>

        <div class="setup-section d-flex gap-2 align-items-center">
          <button class="btn btn-primary"><?= $completo ? 'Instalar jsonSQLDBadmin' : 'Crear administrador' ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="setup-foot">jsonSQLDBadmin<?= version() !== '' ? ' v' . h(version()) : '' ?> · PHP puro, sin dependencias externas ·
    <a href="https://miguelenred.es/jsonsqldb" target="_blank" rel="noopener">miguelenred.es/jsonsqldb</a></div>
</div>
