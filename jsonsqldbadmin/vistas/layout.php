<?php
/**
 * Estructura común de todas las páginas: barra lateral con las bases y sus
 * tablas, barra superior con la ruta, el tema y el usuario, y el pie. El login
 * y el asistente de instalación van sueltos, a pantalla completa.
 *
 * https://miguelenred.es/jsonsqldb
 *
 * @var string $vistaActual
 */
$suelto   = in_array($vistaActual, ['login', 'instalar'], true);
$usuario  = Auth::usuario();
$baseAct  = $base ?? '';
$tablaAct = $tabla ?? '';
$mensajes = $suelto ? $mensajes : flashes();
$titulos  = ['bases' => t('Bases de datos'), 'tablas' => t('Tablas'), 'vistas' => t('Vistas'), 'sql' => t('Consola SQL'),
             'integridad' => t('Integridad'), 'crear_tabla' => t('Nueva tabla'), 'auditoria' => t('Auditoría'),
             'usuarios' => t('Usuarios'), 'configuracion' => t('Configuración'), 'error' => 'Error',
             'login' => 'Acceso', 'instalar' => t('Instalación')];
$titulo   = $titulos[$vistaActual] ?? '';

// Lo que enseña la barra lateral. Si el motor no responde, el panel se pinta
// igual y la página dice por qué: una barra lateral vacía no debe tumbar nada
$sideBases = $sideTablas = $sideVistas = [];
$sinConexion = '';
if (!$suelto) {
    try {
        $sideBases = Api::bases();
        sort($sideBases, SORT_NATURAL | SORT_FLAG_CASE);
        if ($baseAct !== '' && in_array($baseAct, $sideBases, true)) {
            $sideTablas = array_column(Api::sql($baseAct, 'SHOW TABLES'), 'tabla');
            $sideVistas = array_column(Api::sql($baseAct, 'SHOW VIEWS'), 'vista');
            natcasesort($sideTablas);
            natcasesort($sideVistas);
        }
    } catch (Throwable $e) {
        $sinConexion = $e->getMessage();
    }
}
$nav = static function (string $pagina, string $ico, string $texto, array $extra = []) use ($vistaActual): string {
    $on = $vistaActual === $pagina ? ' active' : '';
    return '<a href="' . h(url(['p' => $pagina] + $extra)) . '" class="nav-item' . $on . '"'
         . ($on !== '' ? ' aria-current="page"' : '') . '>' . icono($ico) . '<span>' . h($texto) . '</span></a>';
};
?><!doctype html>
<html lang="<?= h(Idioma::actual()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= h($titulo !== '' ? $titulo . ' · ' : '') ?>jsonSQLDBadmin</title>
<script nonce="<?= h(nonce()) ?>">try{var t=localStorage.getItem('jsa-theme');if(t!=='light'&&t!=='dark')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}</script>
<link rel="stylesheet" href="assets/bootstrap.min.css">
<link rel="stylesheet" href="assets/panel.css">
</head>
<?php if ($suelto): ?>
<body class="<?= $vistaActual === 'instalar' ? 'setup-body' : 'login-body' ?>">
  <?php require __DIR__ . '/' . $vistaActual . '.php'; ?>
<script src="assets/bootstrap.bundle.min.js"></script>
<script src="assets/panel.js"></script>
</body>
</html>
<?php return; endif; ?>
<body class="app">

<aside class="sidebar" id="sidebar" aria-label="<?= h(t('Navegación')) ?>">
    <a class="brand" href="<?= h(url(['p' => 'bases'])) ?>">
        <span class="brand-logo"><?= icono('database') ?></span>
        <span class="brand-text">jsonSQLDBadmin<?php if (version() !== ''): ?><small>v<?= h(version()) ?></small><?php endif; ?></span>
    </a>
    <nav class="nav-main">
        <?= $nav('bases', 'home', t('Inicio')) ?>
        <?php if ($baseAct !== ''): ?>
            <?= $nav('sql', 'terminal', t('Consola SQL'), ['db' => $baseAct]) ?>
            <?= $nav('vistas', 'view', t('Vistas'), ['db' => $baseAct]) ?>
            <?= $nav('integridad', 'shield', t('Integridad'), ['db' => $baseAct]) ?>
        <?php endif; ?>
        <?= $nav('auditoria', 'history', t('Auditoría')) ?>
        <?= $nav('usuarios', 'users', t('Usuarios')) ?>
        <?php if (Auth::esAdmin()): ?>
            <?= $nav('configuracion', 'settings', t('Configuración')) ?>
        <?php endif; ?>
    </nav>

    <div class="side-head">
        <span><?= h(t('Bases y tablas')) ?></span>
        <?php if (Auth::esAdmin()): ?>
            <a href="<?= h(url(['p' => 'bases'])) ?>#nuevaBase" class="side-add" title="<?= h(t('Nueva base de datos')) ?>"><?= icono('plus') ?></a>
        <?php endif; ?>
    </div>
    <?php if (count($sideTablas) + count($sideVistas) > 8): ?>
        <div class="side-filter"><?= icono('search') ?><input type="search" id="tableFilter" placeholder="<?= h(t('Filtrar tablas…')) ?>" aria-label="<?= h(t('Filtrar tablas')) ?>"></div>
    <?php endif; ?>
    <div class="side-tables" id="sideTables">
        <?php if ($sinConexion !== ''): ?>
            <div class="side-empty"><?= h(t('Sin conexión con el motor.')) ?></div>
        <?php elseif ($sideBases === []): ?>
            <div class="side-empty"><?= h(t('Aún no hay bases de datos.')) ?></div>
        <?php endif; ?>
        <?php foreach ($sideBases as $b): $esta = $b === $baseAct; ?>
            <a class="side-db<?= $esta ? ' active' : '' ?>" href="<?= h(url(['p' => 'tablas', 'db' => $b])) ?>" title="<?= h($b) ?>">
                <?= icono('database') ?><span><?= h($b) ?></span>
            </a>
            <?php if ($esta): ?>
                <div class="side-sub">
                    <?php if ($sideTablas === [] && $sideVistas === []): ?>
                        <div class="side-empty"><?= h(t('Sin tablas.')) ?>
                            <?php if (Auth::esAdmin()): ?><a href="<?= h(url(['p' => 'crear_tabla', 'db' => $b])) ?>"><?= h(t('Crear la primera')) ?></a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($sideTablas as $t): ?>
                        <a class="side-table<?= $t === $tablaAct ? ' active' : '' ?>" href="<?= h(url(['p' => 'datos', 'db' => $b, 'tabla' => $t])) ?>"
                           title="<?= h($t) ?>" data-name="<?= h(minus($t)) ?>"><?= icono('table') ?><span><?= h($t) ?></span></a>
                    <?php endforeach; ?>
                    <?php foreach ($sideVistas as $v): ?>
                        <a class="side-table" href="<?= h(url(['p' => 'sql', 'db' => $b, 'sql' => 'SELECT * FROM ' . cita($v) . ' LIMIT 100'])) ?>"
                           title="Vista <?= h($v) ?>" data-name="<?= h(minus($v)) ?>"><?= icono('view') ?><span><?= h($v) ?></span></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="side-foot">
        <a href="https://miguelenred.es/jsonsqldb" target="_blank" rel="noopener">miguelenred.es/jsonsqldb</a>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="main">
    <header class="topbar">
        <button class="icon-btn d-lg-none" type="button" id="sidebarToggle" aria-label="<?= h(t('Menú')) ?>"><?= icono('menu') ?></button>
        <div class="crumbs">
            <?php if ($baseAct !== ''): ?>
                <a class="crumb-db" href="<?= h(url(['p' => 'tablas', 'db' => $baseAct])) ?>"><?= icono('database') ?><?= h($baseAct) ?></a>
                <span class="crumb-sep">/</span>
                <span class="crumb-cur"><?= h($tablaAct !== '' ? $tablaAct : $titulo) ?></span>
            <?php else: ?>
                <span class="crumb-cur"><?= h($titulo) ?></span>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <span class="db-meta d-none d-md-inline-flex" title="<?= h(Api::directa() ? t('El panel carga el motor y le habla sin HTTP') : t('El panel habla con el motor por la API: {url}', ['url' => Api::url()])) ?>">
                <span class="engine"><?= Api::directa() ? h(t('Directa')) : 'API' ?></span><?= $sinConexion === '' ? count($sideBases) . ' ' . h(count($sideBases) === 1 ? t('base') : t('bases')) : h(t('sin conexión')) ?>
            </span>
            <?= selectorIdioma() ?>
            <button class="icon-btn" type="button" id="themeToggle" title="<?= h(t('Tema claro u oscuro')) ?>" aria-label="<?= h(t('Cambiar tema')) ?>"><?= icono('moon', 'when-light') ?><?= icono('sun', 'when-dark') ?></button>
            <div class="dropdown">
                <button class="user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar"><?= h(strtoupper(substr((string)($usuario['usuario'] ?? '?'), 0, 1))) ?></span>
                    <span class="d-none d-sm-inline"><?= h($usuario['usuario'] ?? '') ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-body-secondary"><?= t('Rol: {1}', [1 => h($usuario['rol'] ?? '')]) ?></span></li>
                    <li><a class="dropdown-item" href="<?= h(url(['p' => 'usuarios'])) ?>"><?= icono('key') ?> <?= h(t('Cambiar mi contraseña')) ?></a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><form method="post" action="<?= h(url(['p' => 'salir'])) ?>"><?= csrf() ?>
                      <button class="dropdown-item"><?= icono('logout') ?> <?= h(t('Cerrar sesión')) ?></button></form></li>
                </ul>
            </div>
        </div>
    </header>

    <main class="content">
        <?php if ($sinConexion !== ''): ?>
            <div class="alert alert-danger"><?= t('<strong>Sin conexión con el motor.</strong> {1}', [1 => h($sinConexion)]) ?></div>
        <?php endif; ?>
        <?php if (PHP_VERSION_ID < 80100): ?>
            <div class="alert alert-warning"><?= t('<strong>PHP {v}: las escrituras no son duraderas ante un corte de luz.</strong> El motor necesita fsync(), que llega con PHP 8.1, para que una escritura confirmada sobreviva a un apagón o a un fallo del sistema. Usa PHP 8.1 o posterior en producción.', ['v' => h(PHP_VERSION)]) ?></div>
        <?php endif; ?>
        <?php foreach ($mensajes as $m): ?>
            <div class="alert alert-<?= h($m['tipo']) ?> alert-dismissible fade show" role="alert">
                <?= h($m['texto']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= h(t('Cerrar')) ?>"></button>
            </div>
        <?php endforeach; ?>

        <?php require __DIR__ . '/' . $vistaActual . '.php'; ?>
    </main>
    <footer class="app-footer">
        <?= t('jsonSQLDBadmin{1} · {2} · PHP {3}', [1 => version() !== '' ? ' v' . h(version()) : '', 2 => Api::directa() ? t('conexión directa') : t('conexión por API'), 3 => h(PHP_VERSION)]) ?>
    </footer>
</div>
<script src="assets/bootstrap.bundle.min.js"></script>
<script src="assets/panel.js"></script>
</body>
</html>
