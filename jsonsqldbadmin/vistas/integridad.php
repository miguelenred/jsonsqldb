<?php
$admin = Auth::esAdmin();

// La comprobación es de solo lectura; la corrección escribe y pide ser admin
// El formulario no manda 'accion' a propósito: esta pantalla resuelve su propio
// POST, igual que el editor SQL, en vez de pasar por el despachador de acciones.
$envio     = $admin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$resultado = null;
$ficheros  = null;
$mensaje   = null;
$apartadas = [];
$error     = null;

// REPAIR KEYS (claves foráneas) o, desde la 2.9, REPAIR TABLES (ficheros JSON)
foreach (['corregir' => ['REPAIR KEYS', 'corregir_claves'], 'reparar_ficheros' => ['REPAIR TABLES', 'reparar_ficheros']] as $campo => [$sql, $auditoria]) {
    if ($envio && post($campo) === '1') {
        try {
            Auth::comprobarCsrf();
            $r = Api::sql($base, $sql);
            $mensaje = (string)$r['mensaje'];
            $apartadas = (array)($r['apartadas'] ?? []);           // las filas que REPAIR TABLES ha apartado
            Audit::registrar($auditoria, $mensaje, $base);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

try {
    $ficheros  = Api::sql($base, 'CHECK TABLES');
    $resultado = Api::sql($base, 'CHECK KEYS');
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// Una fila apartada, corregida a mano (2.9): el panel comprueba que es un
// objeto JSON con columnas de su tabla, y el motor la valida al meterla
// (tipos, NOT NULL, UNIQUE, claves foráneas). El INSERT y REPAIR TABLES
// FORGET van en una transacción: o la fila entra y deja de estar pendiente,
// o no pasa nada. Descartarla es solo el FORGET: sigue en .reparados/
$pendientes = array_values(array_filter((array)$ficheros, static fn(array $p): bool => $p['apartada'] !== null));
$editadas   = [];                                    // id => texto, para no perder lo escrito si no vale
$idAccion   = (int)post('apartada');
$pendiente  = current(array_filter($pendientes, static fn(array $p): bool => $p['apartada'] === $idAccion)) ?: null;
if ($envio && $pendiente !== null && in_array(post('hacer'), ['guardar', 'descartar'], true)) {
    try {
        Auth::comprobarCsrf();
        if (post('hacer') === 'descartar') {
            $mensaje = (string)Api::sql($base, 'REPAIR TABLES FORGET ' . $idAccion)['mensaje'];
        } else {
            $editadas[$idAccion] = (string)post('fila');
            $fila = json_decode(trim(rtrim(trim($editadas[$idAccion]), ',')), true);
            if (!is_array($fila) || $fila === [] || array_keys($fila) === range(0, count($fila) - 1)) {
                throw new RuntimeException(t('No es una fila: tiene que ser un objeto JSON, {"columna": valor, …}, como el ejemplo.'));
            }
            $columnas = array_column(Api::sql($base, 'SHOW COLUMNS FROM ' . cita($pendiente['tabla'])), 'columna');
            $sobran   = array_diff(array_keys($fila), $columnas);
            if ($sobran !== []) {
                throw new RuntimeException(t('{1} no es una columna de {2}.', [1 => implode(', ', $sobran), 2 => $pendiente['tabla']]));
            }
            Api::lote($base, [
                ['INSERT INTO ' . cita($pendiente['tabla']) . ' (' . implode(', ', array_map('cita', array_keys($fila))) . ') VALUES ('
                    . implode(', ', array_fill(0, count($fila), '?')) . ')', array_values($fila)],
                'REPAIR TABLES FORGET ' . $idAccion,
            ]);
            unset($editadas[$idAccion]);
            $mensaje = t('Fila corregida y de vuelta en {1}.', [1 => $pendiente['tabla']]);
        }
        Audit::registrar('fila_apartada', post('hacer') . ' ' . $idAccion, $base);
        $ficheros   = Api::sql($base, 'CHECK TABLES');
        $pendientes = array_values(array_filter($ficheros, static fn(array $p): bool => $p['apartada'] !== null));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$problemas = array_values(array_filter((array)$ficheros, static fn(array $p): bool => $p['apartada'] === null));

// Como ejemplo de cada tabla, una fila real suya si la tiene; si no, una inventada
$ejemplos = [];
foreach ($pendientes as $p) {
    if (!isset($ejemplos[$p['tabla']])) {
        try {
            $ejemplos[$p['tabla']] = Api::sql($base, 'SELECT * FROM ' . cita($p['tabla']) . ' LIMIT 1')[0]
                ?? Importar::filasDeEjemplo(Api::sql($base, 'SHOW COLUMNS FROM ' . cita($p['tabla'])))[0];
        } catch (Throwable) {
            $ejemplos[$p['tabla']] = [];
        }
    }
}
?>
<div class="page-head">
  <div>
    <h1><?= h(t('Integridad')) ?></h1>
    <p><?= t('Ficheros JSON estropeados y claves foráneas que apuntan a filas que no existen, en {1}', [1 => h($base)]) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline-secondary" href="<?= h(url(['p' => 'integridad', 'db' => $base])) ?>"><?= icono('refresh') ?> <?= h(t('Volver a comprobar')) ?></a>
  </div>
</div>

<?php if ($error !== null): ?>
  <div class="alert alert-danger"><?= icono('x-octagon') ?> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($mensaje !== null): ?>
  <div class="alert alert-info"><?= icono('wrench') ?> <?= h($mensaje) ?></div>
<?php endif; ?>

<?php if ($apartadas !== []): ?>
  <div class="card mb-4 border-warning">
    <div class="card-body">
      <h2 class="h6"><?= h(t('Filas apartadas')) ?></h2>
      <p class="small mb-2">
        <?= t('Estas filas no se podían arreglar sin adivinar y han salido de su tabla. Siguen tal cual en la copia del fichero original, en la carpeta <code>.reparados</code> de la base. Para recuperarlas, corrígelas y vuelve a meterlas con un <code>INSERT</code>.') ?>
      </p>
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0 align-middle">
          <thead><tr><th><?= h(t('Tabla')) ?></th><th><?= h(t('Fichero')) ?></th><th><?= h(t('Fila')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($apartadas as $a): ?>
            <tr><td><?= h((string)$a['tabla']) ?></td><td><code><?= h((string)$a['fichero']) ?></code></td>
                <td><code class="text-break"><?= h((string)$a['fila']) ?></code></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<h2 class="h5"><?= h(t('Ficheros JSON')) ?></h2>
<?php if (is_array($ficheros) && $problemas === [] && $pendientes === []): ?>
  <div class="alert alert-success">
    <?= t('{1} <strong>Todo correcto.</strong> Todos los ficheros de las tablas se leen bien.', [1 => icono('check2-circle')]) ?>
  </div>
<?php endif; ?>
<?php if ($problemas !== []): ?>
  <div class="alert alert-warning">
    <?= t('{1} <strong>{2} problema(s) en los ficheros.</strong> Casi siempre es una edición a mano que ha roto el JSON. Lo seguro (comas, llaves y corchetes entre filas, índices, el estado de la tabla) el motor lo repara solo en cuanto lo lee; una fila que no se puede arreglar con seguridad necesita que decidas tú.', [1 => icono('exclamation-triangle'), 2 => count($problemas)]) ?>
  </div>
  <div class="card mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead><tr><th><?= h(t('Tabla')) ?></th><th><?= h(t('Fichero')) ?></th><th><?= h(t('Problema')) ?></th><th><?= h(t('Arreglo')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($problemas as $p): ?>
          <tr><td><?= h((string)$p['tabla']) ?></td><td><code><?= h((string)$p['fichero']) ?></code></td>
              <td><code class="text-break"><?= h((string)$p['problema']) ?></code></td><td><?= h((string)$p['arreglo']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($admin): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h6"><?= h(t('Reparar los ficheros')) ?></h2>
        <p class="small mb-2">
          <?= t('Se repara lo seguro y se apartan las filas que no se pueden arreglar con seguridad. <strong>No se pierde nada</strong>: el original de cada fichero reparado se guarda en la carpeta <code>.reparados</code> de la base, con la fecha, y las filas apartadas siguen ahí.') ?>
        </p>
        <form method="post" data-confirm="<?= h(t('¿Reparar los ficheros y apartar las filas que no se pueden arreglar?')) ?>">
          <?= csrf() ?>
          <input type="hidden" name="reparar_ficheros" value="1">
          <button class="btn btn-warning"><?= t('{1} Reparar los ficheros', [1 => icono('wrench')]) ?></button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($pendientes !== []): ?>
  <h3 class="h6"><?= h(t('Filas apartadas pendientes')) ?></h3>
  <p class="small text-body-secondary">
    <?= t('Filas que se apartaron porque no se podían arreglar sin adivinar. Corrige cada una tomando como modelo la fila de ejemplo y guárdala: el motor comprueba los tipos, los valores obligatorios, las claves únicas y las foráneas antes de meterla. Si no la quieres, descártala: sigue en la copia de <code>.reparados</code>.') ?>
  </p>
  <?php foreach ($pendientes as $p): $id = (int)$p['apartada']; ?>
    <div class="card mb-3">
      <div class="card-body">
        <h4 class="h6 mb-2"><?= h(t('Fila {1} de {2}', [1 => $id, 2 => $p['tabla']])) ?> <span class="small text-body-secondary">(<code><?= h((string)$p['fichero']) ?></code>)</span></h4>
        <div class="small mb-1"><?= h(t('Tal como estaba')) ?></div>
        <pre class="small bg-body-tertiary p-2 mb-2 text-break"><?= h((string)$p['problema']) ?></pre>
        <div class="small mb-1"><?= h(t('Ejemplo de una fila correcta de {1}', [1 => $p['tabla']])) ?></div>
        <pre class="small bg-body-tertiary p-2 mb-2 text-break"><?= h((string)json_encode($ejemplos[$p['tabla']] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php if ($admin): ?>
          <form method="post">
            <?= csrf() ?>
            <input type="hidden" name="apartada" value="<?= $id ?>">
            <label class="form-label small" for="fila<?= $id ?>"><?= h(t('Corregida')) ?></label>
            <textarea class="form-control font-monospace mb-2" id="fila<?= $id ?>" name="fila" rows="3"><?= h($editadas[$id]
                ?? rtrim(preg_replace('/^línea \d+: /', '', (string)$p['problema']) ?? '', ',')) ?></textarea>
            <button class="btn btn-primary btn-sm" name="hacer" value="guardar"><?= t('{1} Guardar', [1 => icono('check2-circle')]) ?></button>
            <button class="btn btn-outline-danger btn-sm" name="hacer" value="descartar"
                    data-confirm-click="<?= h(t('¿Descartar esta fila? Seguirá en la copia de .reparados.')) ?>"><?= h(t('Descartar')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<h2 class="h5"><?= h(t('Claves foráneas')) ?></h2>
<?php if ($resultado === []): ?>
  <div class="alert alert-success">
    <?= t('{1} <strong>Todo correcto.</strong> Ninguna fila apunta a un valor que no exista en su tabla destino.', [1 => icono('check2-circle')]) ?>
  </div>
<?php elseif (is_array($resultado)): ?>
  <div class="alert alert-warning">
    <?= t('{1} <strong>{2} fila(s) huérfana(s).</strong> Apuntan a valores que ya no existen en la tabla destino. Trabajando por SQL esto no puede pasar: casi siempre es que alguien editó un <code>.json</code> a mano o restauró la copia de una tabla sin la otra.', [1 => icono('exclamation-triangle'), 2 => count($resultado)]) ?>
  </div>

  <div class="card mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead><tr>
          <th><?= h(t('Tabla')) ?></th><th><?= h(t('Restricción')) ?></th><th><?= h(t('Columnas')) ?></th><th><?= h(t('Valor huérfano')) ?></th>
          <th><?= h(t('Apunta a')) ?></th><th><?= h(t('¿Corregible?')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($resultado as $p): ?>
          <tr>
            <td><?= h($p['tabla']) ?></td>
            <td class="small text-body-secondary"><?= h($p['restriccion']) ?></td>
            <td class="small"><?= h($p['columnas']) ?></td>
            <td class="small"><code><?= h($p['valor']) ?></code></td>
            <td class="small text-body-secondary"><?= h($p['apunta_a']) ?></td>
            <td>
              <?php if ((int)$p['corregible'] === 1): ?>
                <span class="badge text-bg-success"><?= h(t('sí, poniendo NULL')) ?></span>
              <?php else: ?>
                <span class="badge text-bg-secondary" title="<?= h(t('La columna no admite NULL')) ?>">
                  <?= h(t('a mano')) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($admin): ?>
    <?php $corregibles = count(array_filter($resultado, static fn(array $p): bool => (int)$p['corregible'] === 1)); ?>
    <div class="card">
      <div class="card-body">
        <h2 class="h6"><?= h(t('Corregir automáticamente')) ?></h2>
        <p class="small mb-2">
          <?= t('Se pondrán a <code>NULL</code> las <strong>{1}</strong> fila(s) cuya columna lo admita. <strong>Nunca se borra ninguna fila</strong>: las que apuntan desde una columna «no nula» se dejan como están, porque decidir qué hacer con ese dato es cosa tuya, no de un botón.', [1 => $corregibles]) ?>
        </p>
        <p class="small text-body-secondary">
          <?= t('Haz una copia de la base antes, desde <em>Bases → Copia ZIP</em>.') ?>
        </p>
        <form method="post" data-confirm="<?= h(t('¿Poner a NULL las claves huérfanas que se puedan?')) ?>">
          <?= csrf() ?>
          <input type="hidden" name="corregir" value="1">
          <button class="btn btn-warning"<?= $corregibles === 0 ? ' disabled' : '' ?>>
            <?= t('{1} Corregir {2} fila(s)', [1 => icono('wrench'), 2 => $corregibles]) ?></button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card mt-3">
  <div class="card-body small text-body-secondary">
    <?= t('<strong>Qué comprueba:</strong> que cada fichero JSON de las tablas se pueda leer, y que toda fila con una clave foránea apunte a una fila que existe en su tabla destino (las filas con <code>NULL</code> en esas columnas no cuentan, igual que en la comprobación normal del motor). <strong>Lee del disco.</strong> <br> Desde SQL: <code>CHECK TABLES</code> y <code>REPAIR TABLES</code> para los ficheros, <code>CHECK KEYS</code> y <code>REPAIR KEYS</code> para las claves, con <code>FROM tabla</code> opcional para limitarlo a una tabla.') ?>
  </div>
</div>
