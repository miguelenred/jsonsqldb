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
$titulos  = ['bases' => 'Bases de datos', 'tablas' => 'Tablas', 'vistas' => 'Vistas', 'sql' => 'Consola SQL',
             'integridad' => 'Integridad', 'crear_tabla' => 'Nueva tabla', 'auditoria' => 'Auditoría',
             'usuarios' => 'Usuarios', 'configuracion' => 'Configuración', 'error' => 'Error',
             'login' => 'Acceso', 'instalar' => 'Instalación'];
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
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= h($titulo !== '' ? $titulo . ' · ' : '') ?>jsonSQLDBadmin</title>
<script>try{var t=localStorage.getItem('jsa-theme');if(t!=='light'&&t!=='dark')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}</script>
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

<aside class="sidebar" id="sidebar" aria-label="Navegación">
    <a class="brand" href="<?= h(url(['p' => 'bases'])) ?>">
        <span class="brand-logo"><?= icono('database') ?></span>
        <span class="brand-text">jsonSQLDBadmin<?php if (version() !== ''): ?><small>v<?= h(version()) ?></small><?php endif; ?></span>
    </a>
    <nav class="nav-main">
        <?= $nav('bases', 'home', 'Inicio') ?>
        <?php if ($baseAct !== ''): ?>
            <?= $nav('sql', 'terminal', 'Consola SQL', ['db' => $baseAct]) ?>
            <?= $nav('vistas', 'view', 'Vistas', ['db' => $baseAct]) ?>
            <?= $nav('integridad', 'shield', 'Integridad', ['db' => $baseAct]) ?>
        <?php endif; ?>
        <?= $nav('auditoria', 'history', 'Auditoría') ?>
        <?= $nav('usuarios', 'users', 'Usuarios') ?>
        <?php if (Auth::esAdmin()): ?>
            <?= $nav('configuracion', 'settings', 'Configuración') ?>
        <?php endif; ?>
    </nav>

    <div class="side-head">
        <span>Bases y tablas</span>
        <?php if (Auth::esAdmin()): ?>
            <a href="<?= h(url(['p' => 'bases'])) ?>#nuevaBase" class="side-add" title="Nueva base de datos"><?= icono('plus') ?></a>
        <?php endif; ?>
    </div>
    <?php if (count($sideTablas) + count($sideVistas) > 8): ?>
        <div class="side-filter"><?= icono('search') ?><input type="search" id="tableFilter" placeholder="Filtrar tablas…" aria-label="Filtrar tablas"></div>
    <?php endif; ?>
    <div class="side-tables" id="sideTables">
        <?php if ($sinConexion !== ''): ?>
            <div class="side-empty">Sin conexión con el motor.</div>
        <?php elseif ($sideBases === []): ?>
            <div class="side-empty">Aún no hay bases de datos.</div>
        <?php endif; ?>
        <?php foreach ($sideBases as $b): $esta = $b === $baseAct; ?>
            <a class="side-db<?= $esta ? ' active' : '' ?>" href="<?= h(url(['p' => 'tablas', 'db' => $b])) ?>" title="<?= h($b) ?>">
                <?= icono('database') ?><span><?= h($b) ?></span>
            </a>
            <?php if ($esta): ?>
                <div class="side-sub">
                    <?php if ($sideTablas === [] && $sideVistas === []): ?>
                        <div class="side-empty">Sin tablas.
                            <?php if (Auth::esAdmin()): ?><a href="<?= h(url(['p' => 'crear_tabla', 'db' => $b])) ?>">Crear la primera</a><?php endif; ?>
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
        <button class="icon-btn d-lg-none" type="button" id="sidebarToggle" aria-label="Menú"><?= icono('menu') ?></button>
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
            <span class="db-meta d-none d-md-inline-flex" title="<?= h(Api::directa() ? 'El panel carga el motor y le habla sin HTTP' : 'El panel habla con el motor por la API: ' . Api::url()) ?>">
                <span class="engine"><?= Api::directa() ? 'Directa' : 'API' ?></span><?= $sinConexion === '' ? count($sideBases) . ' ' . (count($sideBases) === 1 ? 'base' : 'bases') : 'sin conexión' ?>
            </span>
            <button class="icon-btn" type="button" id="themeToggle" title="Tema claro u oscuro" aria-label="Cambiar tema"><?= icono('moon', 'when-light') ?><?= icono('sun', 'when-dark') ?></button>
            <div class="dropdown">
                <button class="user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar"><?= h(strtoupper(substr((string)($usuario['usuario'] ?? '?'), 0, 1))) ?></span>
                    <span class="d-none d-sm-inline"><?= h($usuario['usuario'] ?? '') ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-body-secondary">Rol: <?= h($usuario['rol'] ?? '') ?></span></li>
                    <li><a class="dropdown-item" href="<?= h(url(['p' => 'usuarios'])) ?>"><?= icono('key') ?> Cambiar mi contraseña</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= h(url(['p' => 'salir'])) ?>"><?= icono('logout') ?> Cerrar sesión</a></li>
                </ul>
            </div>
        </div>
    </header>

    <main class="content">
        <?php if ($sinConexion !== ''): ?>
            <div class="alert alert-danger"><strong>Sin conexión con el motor.</strong> <?= h($sinConexion) ?></div>
        <?php endif; ?>
        <?php foreach ($mensajes as $m): ?>
            <div class="alert alert-<?= h($m['tipo']) ?> alert-dismissible fade show" role="alert">
                <?= h($m['texto']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            </div>
        <?php endforeach; ?>

        <?php require __DIR__ . '/' . $vistaActual . '.php'; ?>
    </main>
    <footer class="app-footer">
        jsonSQLDBadmin<?= version() !== '' ? ' v' . h(version()) : '' ?> ·
        <?= Api::directa() ? 'conexión directa' : 'conexión por API' ?> · PHP <?= h(PHP_VERSION) ?>
    </footer>
</div>
<script src="assets/bootstrap.bundle.min.js"></script>
<script src="assets/panel.js"></script>
</body>
</html>
