<?php
/**
 * Acceso al panel.
 *
 * https://miguelenred.es/jsonsqldb
 */
?>
<div class="login-shell">
    <div class="login-panel">
        <div class="app-logo"><?= icono('database') ?></div>
        <h2>jsonSQLDBadmin</h2>
        <p>Panel de administración de jsonSQLDB: bases de datos SQL en ficheros JSON legibles,
           en PHP puro, para el hosting donde no hay servidor de base de datos.</p>
        <div class="feats">
            <div><span class="ok">✓</span> Por la API firmada o con conexión directa al motor</div>
            <div><span class="ok">✓</span> Datos, estructura, claves, triggers, vistas y consola SQL</div>
            <div><span class="ok">✓</span> Contraseñas bcrypt, bloqueo por intentos y auditoría</div>
        </div>
    </div>
    <div class="login-form">
        <h4 class="fw-bold mb-1">Iniciar sesión</h4>
        <p class="text-body-secondary mb-4" style="font-size:13px">Introduce tu usuario y tu contraseña.</p>

        <?php foreach ($mensajes as $m): ?>
            <div class="alert alert-<?= h($m['tipo']) ?> py-2"><?= h($m['texto']) ?></div>
        <?php endforeach; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="mb-3">
                <label class="form-label" for="usuario">Usuario</label>
                <input class="form-control" id="usuario" name="usuario" required autofocus
                       autocomplete="username" value="<?= h(post('usuario')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="clave">Contraseña</label>
                <input class="form-control" id="clave" name="clave" type="password" required
                       autocomplete="current-password">
            </div>
            <button class="btn btn-primary w-100">Entrar</button>
        </form>

        <p class="text-body-secondary mt-4 mb-0" style="font-size:11.5px;text-align:center">
            <?= version() !== '' ? 'v' . h(version()) . ' · ' : '' ?>PHP <?= h(PHP_VERSION) ?> ·
            <a href="https://miguelenred.es/jsonsqldb" target="_blank" rel="noopener">miguelenred.es/jsonsqldb</a>
        </p>
    </div>
</div>
