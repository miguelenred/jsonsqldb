<?php
$columnas = Api::sql($base, 'SHOW SCHEMA ' . cita($tabla));
$claves   = Api::sql($base, 'SHOW KEYS FROM ' . cita($tabla));
$admin    = Auth::esAdmin();

// Columnas de la clave primaria: sin ellas no se puede editar ni borrar una fila
$pk = [];
foreach ($claves as $k) {
    if ($k['tipo'] === 'PRIMARY') {
        $pk = array_filter(explode(',', (string)$k['columnas']));
    }
}

// Filtro: busca el texto en todas las columnas a la vez
$filtro = get('q');
[$donde, $paramsFiltro] = condicionFiltro($columnas, $filtro);

$porPagina = max(1, (int)ADMIN_FILAS_PAGINA);
$pagina    = max(1, (int)get('pag', '1'));
$total     = (int)Api::valor($base, 'SELECT COUNT(*) FROM ' . cita($tabla) . $donde, $paramsFiltro);
$paginas   = max(1, (int)ceil($total / $porPagina));
$pagina    = min($pagina, $paginas);
$desde     = ($pagina - 1) * $porPagina;

$orden = get('orden');
$dir   = strtoupper(get('dir')) === 'DESC' ? 'DESC' : 'ASC';
$sql   = 'SELECT * FROM ' . cita($tabla) . $donde;
if ($orden !== '' && in_array($orden, array_column($columnas, 'columna'), true)) {
    $sql .= ' ORDER BY ' . cita($orden) . ' ' . $dir;
}
$filas = Api::sql($base, $sql . ' LIMIT ? OFFSET ?', array_merge($paramsFiltro, [$porPagina, $desde]));

require __DIR__ . '/_pestanas.php';
?>

<div class="toolbar">
  <form class="search-box" method="get" role="search">
    <input type="hidden" name="p" value="datos">
    <input type="hidden" name="db" value="<?= h($base) ?>">
    <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
    <input type="hidden" name="orden" value="<?= h($orden) ?>">
    <input type="hidden" name="dir" value="<?= h($dir) ?>">
    <?= icono('search') ?>
    <input name="q" value="<?= h($filtro) ?>" placeholder="<?= h(t('Filtrar en todas las columnas… (Enter)')) ?>" aria-label="<?= h(t('Filtrar')) ?>">
    <?php if ($filtro !== ''): ?>
      <a class="clear" href="<?= h(url(['p' => 'datos', 'db' => $base, 'tabla' => $tabla])) ?>"
         title="<?= h(t('Quitar el filtro')) ?>"><?= icono('x') ?></a>
    <?php endif; ?>
  </form>
  <span class="toolbar-info">
    <?= t('<strong>{1}</strong> fila(s){2} · página {3} de {4}', [1 => number_format($total, 0, ',', '.'), 2 => $filtro === '' ? '' : ' filtradas', 3 => $pagina, 4 => $paginas]) ?>
  </span>
  <div class="toolbar-actions">
    <?php foreach (['csv' => ['CSV', 'filetype-csv'], 'sql' => ['INSERT', 'filetype-sql']] as $f => $b): ?>
      <form method="post">
        <?= csrf() ?>
        <input type="hidden" name="accion" value="exportar">
        <input type="hidden" name="formato" value="<?= h($f) ?>">
        <input type="hidden" name="db" value="<?= h($base) ?>">
        <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
        <input type="hidden" name="orden" value="<?= h($orden) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <input type="hidden" name="q" value="<?= h($filtro) ?>">
        <input type="hidden" name="volver" value="datos">
        <button class="btn btn-sm btn-outline-secondary" title="<?= h(t('Exportar la tabla entera')) ?>">
          <?= icono($b[1]) ?> <?= h($b[0]) ?></button>
      </form>
    <?php endforeach; ?>
    <?php if ($admin): ?>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#nuevaFila">
        <?= t('{1} Insertar fila', [1 => icono('plus-circle')]) ?></button>
    <?php endif; ?>
  </div>
</div>

<?php if ($admin && $pk === []): ?>
  <div class="alert alert-warning py-2 small">
    <?= h(t('Esta tabla no tiene clave primaria, así que no se pueden editar ni borrar filas sueltas desde el panel. Usa la pestaña SQL.')) ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover table-striped tabla-datos mb-0">
      <thead><tr>
        <?php foreach ($columnas as $c): $cn = (string)$c['columna'];
              $nuevaDir = ($orden === $cn && $dir === 'ASC') ? 'DESC' : 'ASC'; ?>
          <th>
            <a class="link-body-emphasis text-decoration-none"
               href="<?= h(url(['p' => 'datos', 'db' => $base, 'tabla' => $tabla,
                                'orden' => $cn, 'dir' => $nuevaDir, 'pag' => $pagina,
                                'q' => $filtro])) ?>">
              <?= h($cn) ?>
              <?php if ($orden === $cn): ?>
                <?= icono($dir === 'ASC' ? 'caret-up' : 'caret-down') ?>
              <?php endif; ?>
            </a>
          </th>
        <?php endforeach; ?>
        <?php if ($admin && $pk !== []): ?><th class="text-end"><?= h(t('Acciones')) ?></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if ($filas === []): ?>
        <tr><td colspan="<?= count($columnas) + 1 ?>" class="text-body-secondary">
          <?= $filtro === '' ? t('Sin filas.') : t('Ninguna fila coincide con el filtro.') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $i => $f): ?>
        <tr>
          <?php foreach ($columnas as $c): ?>
            <td><?= celda($f[$c['columna']] ?? null) ?></td>
          <?php endforeach; ?>
          <?php if ($admin && $pk !== []): ?>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                      data-bs-target="#edit<?= $i ?>"><?= icono('pencil') ?></button>
              <form method="post" class="d-inline" data-confirm="<?= h(t('¿Borrar esta fila?')) ?>">
                <?= csrf() ?>
                <input type="hidden" name="accion" value="borrar_fila">
                <input type="hidden" name="db" value="<?= h($base) ?>">
                <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
                <input type="hidden" name="volver" value="datos">
                <?php foreach ($pk as $c): ?>
                  <input type="hidden" name="pk[<?= h($c) ?>]" value="<?= h($f[$c] ?? '') ?>">
                <?php endforeach; ?>
                <button class="btn btn-sm btn-outline-danger"><?= icono('trash') ?></button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($paginas > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm">
    <?php
    $ventana = 5;
    $ini = max(1, $pagina - intdiv($ventana, 2));
    $fin = min($paginas, $ini + $ventana - 1);
    $enlace = static fn(int $p): string => url(['p' => 'datos', 'db' => $base, 'tabla' => $tabla,
                                                'pag' => $p, 'orden' => $orden, 'dir' => $dir,
                                                'q' => $filtro]);
    ?>
    <li class="page-item<?= $pagina <= 1 ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= h($enlace(1)) ?>">«</a></li>
    <?php for ($p = $ini; $p <= $fin; $p++): ?>
      <li class="page-item<?= $p === $pagina ? ' active' : '' ?>">
        <a class="page-link" href="<?= h($enlace($p)) ?>"><?= $p ?></a></li>
    <?php endfor; ?>
    <li class="page-item<?= $pagina >= $paginas ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= h($enlace($paginas)) ?>">»</a></li>
  </ul>
</nav>
<?php endif; ?>

<?php if ($admin):
    /** Pinta los campos de una fila. $valores null = fila nueva. */
    $campos = static function (array $columnas, ?array $valores, string $ref): void {
        foreach ($columnas as $c) {
            $cn  = (string)$c['columna'];
            $id  = 'f' . $ref . md5($cn);
            $val = $valores === null ? $c['defecto'] : ($valores[$cn] ?? null);
            $auto    = (int)$c['auto'] === 1;
            $noNulo  = (int)$c['notnull'] === 1;
            ?>
            <div class="mb-2">
              <label class="form-label mb-0" for="<?= h($id) ?>">
                <?= h($cn) ?>
                <span class="text-body-tertiary small"><?= h($c['tipo']) ?></span>
                <?php if ((int)$c['pk'] === 1): ?><span class="badge text-bg-primary">PK</span><?php endif; ?>
                <?php if ($auto): ?><span class="badge text-bg-secondary"><?= h(t('auto')) ?></span><?php endif; ?>
                <?php if ($noNulo && !$auto): ?>
                  <span class="badge text-bg-light border" title="<?= h(t('No admite nulos')) ?>"><?= h(t('obligatorio')) ?></span>
                <?php endif; ?>
              </label>
              <input type="hidden" name="tipo[<?= h($cn) ?>]" value="<?= h($c['tipo']) ?>">
              <?php if ($auto): ?><input type="hidden" name="auto[<?= h($cn) ?>]" value="1"><?php endif; ?>
              <?php if ($noNulo): ?><input type="hidden" name="nn[<?= h($cn) ?>]" value="1"><?php endif; ?>
              <div class="input-group input-group-sm">
                <input class="form-control" id="<?= h($id) ?>" name="valor[<?= h($cn) ?>]"
                       value="<?= h($val ?? '') ?>"<?= $auto ? ' placeholder="' . h(t('(automático)')) . '" readonly' : '' ?>>
                <?php if ($auto): ?>
                  <span class="input-group-text text-body-tertiary"><?= h(t('lo pone la base')) ?></span>
                <?php elseif ($noNulo): ?>
                  <span class="input-group-text text-body-tertiary"><?= h(t('sin nulos')) ?></span>
                <?php else: ?>
                  <div class="input-group-text">
                    <input class="form-check-input mt-0 me-1" type="checkbox"
                           name="nulo[<?= h($cn) ?>]" value="1" id="n<?= h($id) ?>"
                           <?= $val === null && $valores !== null ? 'checked' : '' ?>>
                    <label class="form-check-label" for="n<?= h($id) ?>">NULL</label>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <?php
        }
    };
?>

<!-- Insertar -->
<div class="modal fade" id="nuevaFila" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="insertar_fila">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
      <input type="hidden" name="volver" value="datos">
      <div class="modal-header"><h5 class="modal-title"><?= t('Insertar fila en «{1}»', [1 => h($tabla)]) ?></h5></div>
      <div class="modal-body"><?php $campos($columnas, null, 'nueva'); ?>
        <div class="form-text">
          <?= h(t('Una casilla vacía significa «sin valor»: en las columnas automáticas, numéricas y de fecha la columna no se manda, y toma su valor por defecto. Marca NULL para guardar un nulo, y deja el texto vacío para guardar una cadena vacía. Las columnas marcadas como «obligatorio» no admiten nulos, así que no ofrecen la casilla.')) ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cancelar')) ?></button>
        <button class="btn btn-primary"><?= h(t('Insertar')) ?></button>
      </div>
    </form>
  </div></div>
</div>

<!-- Editar -->
<?php if ($pk !== []): foreach ($filas as $i => $f): ?>
<div class="modal fade" id="edit<?= $i ?>" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="actualizar_fila">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
      <input type="hidden" name="volver" value="datos">
      <?php foreach ($pk as $c): ?>
        <input type="hidden" name="pk[<?= h($c) ?>]" value="<?= h($f[$c] ?? '') ?>">
      <?php endforeach; ?>
      <div class="modal-header"><h5 class="modal-title"><?= h(t('Editar fila')) ?></h5></div>
      <div class="modal-body"><?php $campos($columnas, $f, (string)$i); ?></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cancelar')) ?></button>
        <button class="btn btn-primary"><?= h(t('Guardar')) ?></button>
      </div>
    </form>
  </div></div>
</div>
<?php endforeach; endif; ?>
<?php endif; ?>
