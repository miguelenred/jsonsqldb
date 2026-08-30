<?php
$columnas = Api::sql($base, 'SHOW SCHEMA ' . cita($tabla));
$claves   = Api::sql($base, 'SHOW KEYS FROM ' . cita($tabla));
$triggers = Api::sql($base, 'SHOW TRIGGERS FROM ' . cita($tabla));
$indices  = Api::sql($base, 'SHOW INDEXES FROM ' . cita($tabla));
$otras    = array_column(Api::sql($base, 'SHOW TABLES'), 'tabla');
$admin    = Auth::esAdmin();
$tipos    = ['INTEGER', 'DOUBLE', 'DECIMAL', 'TEXT', 'DATETIME'];
$acciones = ['NO ACTION', 'CASCADE', 'RESTRICT', 'SET NULL', 'SET DEFAULT'];

$hayPk   = false;
$hayAuto = false;
foreach ($claves as $k) { if ($k['tipo'] === 'PRIMARY') { $hayPk = true; } }
foreach ($columnas as $c) { if ((int)$c['auto'] === 1) { $hayAuto = true; } }

require __DIR__ . '/_pestanas.php';
?>

<div class="row g-3">
  <!-- Columnas -->
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="bi bi-layout-three-columns"></i> Columnas</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr>
            <th>Columna</th><th>Tipo</th><th class="text-center">PK</th><th class="text-center">Auto</th>
            <th class="text-center">No nulo</th><th class="text-center">Única</th><th>Por defecto</th>
            <?php if ($admin): ?><th class="text-end">Acciones</th><?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($columnas as $c): $cn = (string)$c['columna']; ?>
            <tr>
              <td><strong><?= h($cn) ?></strong></td>
              <td><?= h($c['tipo']) ?><?php
                  if ($c['longitud'] !== null) { echo '(' . (int)$c['longitud'] . ')'; }
                  elseif ($c['escala'] !== null) { echo '(10,' . (int)$c['escala'] . ')'; } ?></td>
              <td class="text-center"><?= $c['pk'] ? '<span class="badge text-bg-primary">PK</span>' : '' ?></td>
              <td class="text-center"><?= $c['auto'] ? '<i class="bi bi-check2"></i>' : '' ?></td>
              <td class="text-center"><?= $c['notnull'] ? '<i class="bi bi-check2"></i>' : '' ?></td>
              <td class="text-center"><?= $c['unico'] ? '<i class="bi bi-check2"></i>' : '' ?></td>
              <td class="text-body-secondary"><?= $c['defecto'] === null ? '' : celda($c['defecto']) ?></td>
              <?php if ($admin): ?>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                        data-bs-target="#ren<?= h(md5($cn)) ?>"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                        data-bs-target="#delc<?= h(md5($cn)) ?>"><i class="bi bi-trash"></i></button>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($admin): ?>
  <!-- Añadir columna -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-plus-circle"></i> Añadir columna</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="anadir_columna">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
          <input type="hidden" name="volver" value="estructura">
          <div class="col-md-4">
            <label class="form-label">Nombre</label>
            <input class="form-control form-control-sm" name="nombre" required pattern="[A-Za-z_][A-Za-z0-9_]*">
          </div>
          <div class="col-md-3">
            <label class="form-label">Tipo</label>
            <select class="form-select form-select-sm tipo-col" name="tipo">
              <?php foreach ($tipos as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label" title="Caracteres, solo para TEXT">Long. texto</label>
            <input class="form-control form-control-sm long-col" type="number" min="1"
                   name="longitud" disabled>
          </div>
          <div class="col-md-3">
            <label class="form-label" title="Solo para DECIMAL">Decimales</label>
            <input class="form-control form-control-sm esc-col" type="number" min="0" max="10"
                   name="escala" placeholder="2" disabled>
          </div>
          <div class="col-md-4">
            <label class="form-label">Por defecto</label>
            <input class="form-control form-control-sm" name="defecto">
          </div>
          <div class="col-12">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" id="nn" name="notnull" value="1">
              <label class="form-check-label" for="nn">No nulo</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" id="uq" name="unico" value="1">
              <label class="form-check-label" for="uq">Única</label>
            </div>
            <button class="btn btn-sm btn-primary float-end">Añadir</button>
          </div>
          <div class="col-12 form-text">
            «Long. texto» son caracteres y solo vale para TEXT; «Decimales» solo para DECIMAL
            (2 si lo dejas vacío). DATETIME se guarda como <code>AAAA-MM-DD</code> con la hora
            opcional (<code>AAAA-MM-DD HH:MM:SS</code>). Si la tabla ya tiene filas y la columna
            es «no nula», pon un valor por defecto.
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Operaciones sobre la tabla -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-gear"></i> Operaciones sobre la tabla</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end mb-4">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="renombrar_tabla">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
          <input type="hidden" name="volver" value="estructura">
          <div class="col-md-8">
            <label class="form-label">Nuevo nombre de la tabla</label>
            <input class="form-control form-control-sm" name="nuevo" required
                   pattern="[A-Za-z_][A-Za-z0-9_]*" value="<?= h($tabla) ?>">
          </div>
          <div class="col-md-4"><button class="btn btn-sm btn-outline-primary w-100">Renombrar</button></div>
          <div class="col-12 form-text">Las claves foráneas de otras tablas se actualizan solas.</div>
        </form>

        <form method="post" onsubmit="return confirm('¿Borrar TODAS las filas de <?= h($tabla) ?>?');">
          <?= csrf() ?>
          <input type="hidden" name="accion" value="vaciar_tabla">
          <input type="hidden" name="db" value="<?= h($base) ?>">
          <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
          <input type="hidden" name="volver" value="estructura">
          <button class="btn btn-sm btn-outline-danger">
            <i class="bi bi-eraser"></i> Vaciar la tabla (borrar todas las filas)</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Claves -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-key"></i> Claves</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Tipo</th><th>Nombre</th><th>Columnas</th><th>Referencia</th>
            <?php if ($admin): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($claves as $k): ?>
            <tr>
              <td><span class="badge text-bg-<?= $k['tipo'] === 'FOREIGN' ? 'warning' :
                    ($k['tipo'] === 'PRIMARY' ? 'primary' : 'secondary') ?>"><?= h($k['tipo']) ?></span></td>
              <td class="small"><?= h($k['nombre']) ?></td>
              <td class="small"><?= h($k['columnas']) ?></td>
              <td class="small">
                <?php if ($k['tabla_destino'] !== null): ?>
                  <?= h($k['tabla_destino']) ?>(<?= h($k['columnas_destino']) ?>)
                  <span class="text-body-secondary">
                    · DEL <?= h($k['on_delete']) ?> · UPD <?= h($k['on_update']) ?></span>
                <?php endif; ?>
              </td>
              <?php if ($admin): ?>
              <td class="text-end">
                <?php if ($k['tipo'] === 'PRIMARY'): ?>
                  <?php if (!$hayAuto): ?>
                    <form method="post" onsubmit="return confirm('¿Quitar la clave primaria?');">
                      <?= csrf() ?>
                      <input type="hidden" name="accion" value="borrar_pk">
                      <input type="hidden" name="db" value="<?= h($base) ?>">
                      <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
                      <input type="hidden" name="volver" value="estructura">
                      <button class="btn btn-sm btn-outline-danger"
                              title="Quitar la clave primaria"><i class="bi bi-trash"></i></button>
                    </form>
                  <?php else: ?>
                    <span class="text-body-tertiary small" title="Es AUTOINCREMENT: hay que recrear la tabla">
                      fija</span>
                  <?php endif; ?>
                <?php else: ?>
                  <form method="post" onsubmit="return confirm('¿Eliminar la restricción?');">
                    <?= csrf() ?>
                    <input type="hidden" name="accion" value="borrar_restriccion">
                    <input type="hidden" name="db" value="<?= h($base) ?>">
                    <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
                    <input type="hidden" name="nombre" value="<?= h($k['nombre']) ?>">
                    <input type="hidden" name="volver" value="estructura">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($admin): ?>
      <div class="card-footer">
        <?php if (!$hayPk): ?>
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#nuevaPk">
            <i class="bi bi-plus"></i> Clave primaria</button>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#nuevaUnica">
          <i class="bi bi-plus"></i> Clave única</button>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#nuevaFk">
          <i class="bi bi-plus"></i> Clave foránea</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Triggers -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-lightning"></i> Triggers</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Nombre</th><th>Cuándo</th><th>Condición</th>
            <?php if ($admin): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php if ($triggers === []): ?>
            <tr><td colspan="4" class="text-body-secondary">Sin triggers.</td></tr>
          <?php endif; ?>
          <?php foreach ($triggers as $tg): ?>
            <tr>
              <td class="small"><?= h($tg['nombre']) ?></td>
              <td class="small"><?= h($tg['timing'] . ' ' . $tg['evento']) ?></td>
              <td class="small text-body-secondary"><?= celda($tg['cuando']) ?></td>
              <?php if ($admin): ?>
              <td class="text-end">
                <form method="post" onsubmit="return confirm('¿Borrar el trigger?');">
                  <?= csrf() ?>
                  <input type="hidden" name="accion" value="borrar_trigger">
                  <input type="hidden" name="db" value="<?= h($base) ?>">
                  <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
                  <input type="hidden" name="nombre" value="<?= h($tg['nombre']) ?>">
                  <input type="hidden" name="volver" value="estructura">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($admin): ?>
      <div class="card-footer">
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#nuevoTrigger">
          <i class="bi bi-plus"></i> Nuevo trigger</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Índices -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-search"></i> Índices</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Nombre</th><th>Columnas</th><th>Origen</th>
            <?php if ($admin): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php if ($indices === []): ?>
            <tr><td colspan="4" class="text-body-secondary">Sin índices.</td></tr>
          <?php endif; ?>
          <?php foreach ($indices as $ix): ?>
            <tr>
              <td class="small"><?= h($ix['indice']) ?></td>
              <td class="small"><?= h($ix['columnas']) ?></td>
              <td class="small text-body-secondary">
                <?= (int)$ix['automatico'] === 1 ? 'PRIMARY KEY / UNIQUE' : 'creado a mano' ?>
              </td>
              <?php if ($admin): ?>
              <td class="text-end">
                <?php if ((int)$ix['automatico'] === 0): ?>
                <form method="post" onsubmit="return confirm('¿Borrar el índice?');">
                  <?= csrf() ?>
                  <input type="hidden" name="accion" value="borrar_indice">
                  <input type="hidden" name="db" value="<?= h($base) ?>">
                  <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
                  <input type="hidden" name="nombre" value="<?= h($ix['indice']) ?>">
                  <input type="hidden" name="volver" value="estructura">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($admin): ?>
      <div class="card-footer">
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#nuevoIndice">
          <i class="bi bi-plus"></i> Nuevo índice</button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($admin): ?>
<div class="modal fade" id="nuevoIndice" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="crear_indice">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
      <input type="hidden" name="volver" value="estructura">
      <div class="modal-header"><h5 class="modal-title">Nuevo índice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Nombre</label>
          <input name="nombre" class="form-control" required
                 pattern="[A-Za-z_][A-Za-z0-9_]*" maxlength="64">
        </div>
        <div class="mb-3">
          <label class="form-label">Columnas</label>
          <select name="columnas[]" class="form-select" multiple size="6" required>
            <?php foreach ($columnas as $c): ?>
              <option value="<?= h($c['columna']) ?>"><?= h($c['columna']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">
            El orden importa: un índice sobre (a, b) sirve para buscar por a, o por a y b,
            pero no para buscar solo por b. Acelera las igualdades y los IN; no los rangos,
            ni LIKE, ni ORDER BY. Y hace algo más lenta cada escritura de la tabla.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Crear</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($admin): ?>
<!-- Modales de columna -->
<?php foreach ($columnas as $c): $cn = (string)$c['columna']; $mid = md5($cn); ?>
  <div class="modal fade" id="ren<?= h($mid) ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="post">
        <?= csrf() ?>
        <input type="hidden" name="accion" value="editar_columna">
        <input type="hidden" name="db" value="<?= h($base) ?>">
        <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
        <input type="hidden" name="columna" value="<?= h($cn) ?>">
        <input type="hidden" name="volver" value="estructura">
        <div class="modal-header"><h5 class="modal-title">Editar «<?= h($cn) ?>»</h5></div>
        <div class="modal-body">

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Nombre</label>
              <input class="form-control form-control-sm" name="nombre" required
                     pattern="[A-Za-z_][A-Za-z0-9_]*" value="<?= h($cn) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Tipo</label>
              <select class="form-select form-select-sm tipo-col" name="tipo">
                <?php foreach ($tipos as $t): ?>
                  <option<?= $t === (string)$c['tipo'] ? ' selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" title="Caracteres, solo para TEXT">Long. texto</label>
              <input class="form-control form-control-sm long-col" type="number" min="1"
                     name="longitud" value="<?= h($c['longitud'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" title="Solo para DECIMAL">Decimales</label>
              <input class="form-control form-control-sm esc-col" type="number" min="0" max="10"
                     name="escala" placeholder="2" value="<?= h($c['escala'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Por defecto</label>
              <input class="form-control form-control-sm" name="defecto"
                     value="<?= h($c['defecto'] ?? '') ?>">
              <div class="form-text">Déjalo vacío para quitar el valor por defecto.</div>
            </div>
          </div>

          <div class="mt-2">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="notnull" value="1"
                     id="nn<?= h($mid) ?>" <?= (int)$c['notnull'] === 1 ? 'checked' : '' ?>
                     <?= (int)$c['pk'] === 1 ? 'disabled' : '' ?>>
              <label class="form-check-label" for="nn<?= h($mid) ?>">No nulo</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="unico" value="1"
                     id="uq<?= h($mid) ?>" <?= (int)$c['unico'] === 1 ? 'checked' : '' ?>>
              <label class="form-check-label" for="uq<?= h($mid) ?>">Única</label>
            </div>
          </div>

          <?php if ((int)$c['pk'] === 1 || (int)$c['auto'] === 1): ?>
            <div class="alert alert-secondary py-2 small mt-3 mb-0">
              Esta columna es
              <?= (int)$c['pk'] === 1 ? 'clave primaria' : '' ?><?= (int)$c['pk'] === 1 && (int)$c['auto'] === 1 ? ' y ' : '' ?><?= (int)$c['auto'] === 1 ? 'autoincremental' : '' ?>.
              <?php if ((int)$c['auto'] === 1): ?>
                El autoincremento no se puede quitar desde aquí: hay que recrear la tabla.
              <?php else: ?>
                La clave primaria se gestiona en el apartado «Claves».
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="form-text mt-3">
              Para hacer esta columna clave primaria, usa el apartado «Claves».
            </div>
          <?php endif; ?>

          <div class="form-text mt-2">
            DATETIME usa el formato <code>AAAA-MM-DD</code>, con la hora opcional.
          </div>
          <div class="alert alert-warning py-2 small mt-3 mb-0">
            Los datos que ya hay se convierten al tipo nuevo. Si algún valor no se puede
            convertir, si queda un nulo en una columna «no nula» o si hay repetidos en una
            «única», no se cambia nada y se te dice por qué.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary">Guardar</button>
        </div>
      </form>
    </div></div>
  </div>

  <div class="modal fade" id="delc<?= h($mid) ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="post">
        <?= csrf() ?>
        <input type="hidden" name="accion" value="borrar_columna">
        <input type="hidden" name="db" value="<?= h($base) ?>">
        <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
        <input type="hidden" name="columna" value="<?= h($cn) ?>">
        <input type="hidden" name="volver" value="estructura">
        <div class="modal-header"><h5 class="modal-title">Borrar «<?= h($cn) ?>»</h5></div>
        <div class="modal-body">Se pierden los datos de esta columna en todas las filas.</div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-danger">Borrar</button>
        </div>
      </form>
    </div></div>
  </div>
<?php endforeach; ?>

<!-- Nueva clave primaria -->
<?php if (!$hayPk): ?>
<div class="modal fade" id="nuevaPk" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="anadir_pk">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
      <input type="hidden" name="volver" value="estructura">
      <div class="modal-header"><h5 class="modal-title">Clave primaria de «<?= h($tabla) ?>»</h5></div>
      <div class="modal-body">
        <label class="form-label">Columnas</label>
        <?php foreach ($columnas as $c): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="columnas[]"
                   id="pkc<?= h(md5((string)$c['columna'])) ?>" value="<?= h($c['columna']) ?>">
            <label class="form-check-label" for="pkc<?= h(md5((string)$c['columna'])) ?>">
              <?= h($c['columna']) ?>
              <span class="text-body-tertiary small"><?= h($c['tipo']) ?></span></label>
          </div>
        <?php endforeach; ?>
        <div class="form-text mt-2">
          Marca varias para una clave primaria compuesta; el orden es el de la tabla. Las columnas
          elegidas pasan a ser «no nulas». Si los datos actuales tienen nulos o combinaciones
          repetidas, la operación se rechaza y no se toca nada.
        </div>
        <div class="alert alert-secondary py-2 small mt-3 mb-0">
          El <code>AUTOINCREMENT</code> no se puede añadir aquí: solo se puede poner al crear la
          tabla.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Crear</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<!-- Nueva clave única -->
<div class="modal fade" id="nuevaUnica" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="anadir_unica">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
      <input type="hidden" name="volver" value="estructura">
      <div class="modal-header"><h5 class="modal-title">Nueva clave única</h5></div>
      <div class="modal-body">
        <label class="form-label">Columnas</label>
        <?php foreach ($columnas as $c): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="columnas[]"
                   id="uqc<?= h(md5((string)$c['columna'])) ?>" value="<?= h($c['columna']) ?>">
            <label class="form-check-label" for="uqc<?= h(md5((string)$c['columna'])) ?>">
              <?= h($c['columna']) ?></label>
          </div>
        <?php endforeach; ?>
        <label class="form-label mt-3">Nombre (opcional)</label>
        <input class="form-control" name="nombre" pattern="[A-Za-z_][A-Za-z0-9_]*">
        <div class="form-text">Si los datos actuales tienen valores repetidos, la operación se rechaza.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Añadir</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Nueva clave foránea -->
<div class="modal fade" id="nuevaFk" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="anadir_fk">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
      <input type="hidden" name="volver" value="estructura">
      <div class="modal-header"><h5 class="modal-title">Nueva clave foránea</h5></div>
      <div class="modal-body">
        <label class="form-label">Columnas de esta tabla</label>
        <select class="form-select" name="columnas[]" multiple size="4" required>
          <?php foreach ($columnas as $c): ?>
            <option value="<?= h($c['columna']) ?>"><?= h($c['columna']) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="form-label mt-3">Tabla a la que apunta</label>
        <select class="form-select" name="tabla_destino" id="fkTabla" required>
          <option value="">— elige —</option>
          <?php foreach ($otras as $o): ?>
            <option value="<?= h($o) ?>"><?= h($o) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="form-label mt-3">Columnas a las que apunta</label>
        <select class="form-select" name="referencias[]" id="fkColumnas" multiple size="4" required></select>

        <div class="row g-2 mt-2">
          <div class="col-6">
            <label class="form-label">ON DELETE</label>
            <select class="form-select" name="on_delete">
              <?php foreach ($acciones as $a): ?><option><?= h($a) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">ON UPDATE</label>
            <select class="form-select" name="on_update">
              <?php foreach ($acciones as $a): ?><option><?= h($a) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <label class="form-label mt-3">Nombre (opcional)</label>
        <input class="form-control" name="nombre" pattern="[A-Za-z_][A-Za-z0-9_]*">
        <div class="form-text">
          Si alguna fila apunta a un valor que no existe en la tabla destino, la operación se rechaza.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Añadir</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Nuevo trigger -->
<div class="modal fade" id="nuevoTrigger" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" id="formTrigger">
      <?= csrf() ?>
      <input type="hidden" name="accion" value="crear_trigger">
      <input type="hidden" name="db" value="<?= h($base) ?>">
      <input type="hidden" name="tabla" value="<?= h($tabla) ?>">
      <input type="hidden" name="volver" value="estructura">
      <div class="modal-header"><h5 class="modal-title">Nuevo trigger en «<?= h($tabla) ?>»</h5></div>
      <div class="modal-body">

        <div class="row g-2">
          <div class="col-md-5">
            <label class="form-label" for="trgNombre">Nombre</label>
            <input class="form-control form-control-sm" id="trgNombre" name="nombre" required
                   pattern="[A-Za-z_][A-Za-z0-9_]*" value="trg_<?= h($tabla) ?>_ins">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="trgTiming">Cuándo</label>
            <select class="form-select form-select-sm" id="trgTiming" name="timing">
              <option value="AFTER">Después (AFTER)</option>
              <option value="BEFORE">Antes (BEFORE)</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="trgEvento">De qué</label>
            <select class="form-select form-select-sm" id="trgEvento" name="evento">
              <option value="INSERT">Insertar (INSERT)</option>
              <option value="UPDATE">Modificar (UPDATE)</option>
              <option value="DELETE">Borrar (DELETE)</option>
            </select>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label" for="trgCuando">Condición (opcional)</label>
          <input class="form-control form-control-sm sql-area" id="trgCuando" name="cuando"
                 placeholder="NEW.total > 0">
          <div class="form-text">
            Si la pones, el trigger solo salta cuando se cumple. Genera el <code>WHEN</code>.
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label" for="trgCuerpo">Qué hace</label>
          <textarea class="form-control sql-area" id="trgCuerpo" name="cuerpo" rows="5" required
                    placeholder="UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id;"></textarea>
          <div class="form-text">
            Una o varias sentencias separadas por <code>;</code>. Dentro puedes usar
            <code>NEW.columna</code> (el valor que entra, en INSERT y UPDATE),
            <code>OLD.columna</code> (el que había, en UPDATE y DELETE) y
            <code>RAISE(ABORT, 'mensaje')</code> para cancelar la operación con un error.
          </div>
        </div>

        <label class="form-label mt-3 mb-1">Sentencia que se va a crear</label>
        <pre class="border rounded bg-body-tertiary p-2 mb-0 sql-area" id="trgVista"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Crear</button>
      </div>
    </form>
  </div></div>
</div>

<script>
// Vista previa del trigger, montada igual que en el servidor
(function () {
    const campos = ['trgNombre', 'trgTiming', 'trgEvento', 'trgCuando', 'trgCuerpo']
        .map(function (id) { return document.getElementById(id); });
    const vista = document.getElementById('trgVista');
    if (!vista || campos.some(function (c) { return c === null; })) { return; }

    function pintar() {
        const [nombre, timing, evento, cuando, cuerpo] = campos.map(function (c) { return c.value.trim(); });
        let sql = 'CREATE TRIGGER ' + (nombre || 'sin_nombre')
                + '\n' + timing + ' ' + evento + ' ON <?= h($tabla) ?>';
        if (cuando !== '') { sql += '\nWHEN ' + cuando; }
        sql += '\nBEGIN\n  ' + (cuerpo || '-- qué hace') + '\nEND';
        vista.textContent = sql;
    }
    campos.forEach(function (c) { c.addEventListener('input', pintar); c.addEventListener('change', pintar); });
    pintar();
})();

// Columnas de la tabla destino de la clave foránea
const COLUMNAS_POR_TABLA = <?= json_encode(
    (static function () use ($base, $otras) {
        $mapa = [];
        foreach ($otras as $t) {
            $mapa[$t] = array_column(Api::sql($base, 'SHOW SCHEMA ' . cita($t)), 'columna');
        }
        return $mapa;
    })(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

document.getElementById('fkTabla')?.addEventListener('change', function () {
    const destino = document.getElementById('fkColumnas');
    destino.innerHTML = '';
    (COLUMNAS_POR_TABLA[this.value] || []).forEach(function (c) {
        const o = document.createElement('option');
        o.value = c;
        o.textContent = c;
        destino.appendChild(o);
    });
});
</script>
<?php endif; ?>
