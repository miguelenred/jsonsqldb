<?php
Auth::exigirAdmin();
$tipos = ['INTEGER', 'DOUBLE', 'DECIMAL', 'TEXT', 'DATETIME'];
$filas = 6;

/** Una fila de columna. $i es el índice, o __I__ para la plantilla del botón. */
$filaColumna = static function (string $i) use ($tipos): string {
    ob_start(); ?>
    <tr>
      <td><input class="form-control form-control-sm" name="columnas[<?= $i ?>][nombre]"
                 pattern="[A-Za-z_][A-Za-z0-9_]*"></td>
      <td>
        <select class="form-select form-select-sm tipo-col" name="columnas[<?= $i ?>][tipo]">
          <?php foreach ($tipos as $t): ?>
            <option<?= $t === 'TEXT' ? ' selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td><input class="form-control form-control-sm long-col" type="number" min="1"
                 name="columnas[<?= $i ?>][longitud]" style="width:6rem" disabled></td>
      <td><input class="form-control form-control-sm esc-col" type="number" min="0" max="10"
                 name="columnas[<?= $i ?>][escala]" style="width:5rem" placeholder="2" disabled></td>
      <td class="text-center"><input class="form-check-input pk-col" type="checkbox"
                 name="columnas[<?= $i ?>][pk]" value="1"></td>
      <td class="text-center"><input class="form-check-input auto-col" type="checkbox"
                 name="columnas[<?= $i ?>][auto]" value="1" disabled
                 title="Solo para columnas INTEGER que sean clave primaria"></td>
      <td class="text-center"><input class="form-check-input" type="checkbox"
                 name="columnas[<?= $i ?>][notnull]" value="1"></td>
      <td class="text-center"><input class="form-check-input" type="checkbox"
                 name="columnas[<?= $i ?>][unico]" value="1"></td>
      <td><input class="form-control form-control-sm" name="columnas[<?= $i ?>][defecto]"></td>
      <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger quitar-fila"
                 title="Quitar esta fila"><i class="bi bi-x-lg"></i></button></td>
    </tr>
    <?php return (string)ob_get_clean();
};
?>
<h1 class="h5 mb-3"><i class="bi bi-plus-circle"></i> Nueva tabla en <?= h($base) ?></h1>

<form method="post">
  <?= csrf() ?>
  <input type="hidden" name="accion" value="crear_tabla">
  <input type="hidden" name="db" value="<?= h($base) ?>">
  <input type="hidden" name="volver" value="crear_tabla">

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-5">
          <label class="form-label" for="nombreTabla">Nombre de la tabla</label>
          <input class="form-control" id="nombreTabla" name="nombre" required
                 pattern="[A-Za-z_][A-Za-z0-9_]*" autofocus>
        </div>
        <div class="col-md-7 form-text">
          Deja en blanco las filas de columna que no vayas a usar.
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Columnas</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr>
          <th style="min-width:11rem">Nombre</th><th>Tipo</th>
          <th title="Solo para TEXT">Long. texto</th>
          <th title="Solo para DECIMAL">Decimales</th>
          <th class="text-center">PK</th><th class="text-center">Auto</th>
          <th class="text-center">No nulo</th><th class="text-center">Única</th>
          <th style="min-width:9rem">Por defecto</th><th></th>
        </tr></thead>
        <tbody id="filasColumnas">
        <?php for ($i = 0; $i < $filas; $i++) { echo $filaColumna((string)$i); } ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <button type="button" class="btn btn-sm btn-outline-primary" id="anadirFila">
        <i class="bi bi-plus-lg"></i> Añadir columna</button>
      <div class="small text-body-secondary mt-2 mb-0">
        Las filas que dejes en blanco se ignoran. La longitud solo se aplica a TEXT (pasa a
        VARCHAR) y la escala a DECIMAL (por defecto 2). AUTOINCREMENT necesita una columna
        INTEGER que sea clave primaria. Marca varias PK para una clave primaria compuesta.
      </div>
    </div>
  </div>

  <button class="btn btn-primary"><i class="bi bi-check2"></i> Crear tabla</button>
  <a class="btn btn-outline-secondary" href="<?= h(url(['p' => 'tablas', 'db' => $base])) ?>">Cancelar</a>
</form>

<template id="plantillaColumna"><?= $filaColumna('__I__') ?></template>
<script>
(function () {
    const cuerpo    = document.getElementById('filasColumnas');
    const plantilla = document.getElementById('plantillaColumna');
    let siguiente   = <?= $filas ?>;

    document.getElementById('anadirFila').addEventListener('click', function () {
        const html = plantilla.innerHTML.replaceAll('__I__', String(siguiente++));
        cuerpo.insertAdjacentHTML('beforeend', html);
        window.ajustarTipos();
        cuerpo.lastElementChild.querySelector('input').focus();
    });

    // Quitar una fila, dejando siempre al menos una
    cuerpo.addEventListener('click', function (e) {
        const boton = e.target.closest('.quitar-fila');
        if (boton && cuerpo.rows.length > 1) {
            boton.closest('tr').remove();
        }
    });

})();
</script>
