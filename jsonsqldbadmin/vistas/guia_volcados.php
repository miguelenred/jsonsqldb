<?php
/**
 * Ventana con la guía para hacer el volcado de cada motor y lo que no llega
 * igual, dentro del panel (se abre desde el bloque «Importar un volcado SQL»).
 * Un botón con data-pestana="access" la abre en esa pestaña (assets/panel.js).
 */
$motores = [
    'sqlite'     => 'SQLite',
    'mysql'      => 'MySQL / MariaDB',
    'postgresql' => 'PostgreSQL',
    'sqlserver'  => 'SQL Server',
    'access'     => 'Microsoft Access',
];
?>
<div class="modal fade" id="guiaVolcados" tabindex="-1" aria-labelledby="guiaVolcadosTitulo" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="guiaVolcadosTitulo"><?= h(t('Cómo hacer el volcado de cada motor')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('Cerrar')) ?>"></button>
      </div>
      <div class="modal-body">
        <p class="small text-body-secondary"><?= h(t('Haz el volcado, súbelo en la página de la base donde lo quieres y deja el formato en «Detectar el formato». Al terminar, el resumen dice lo que no ha llegado igual.')) ?></p>

        <ul class="nav nav-tabs" role="tablist">
          <?php foreach ($motores as $id => $nombre): ?>
            <li class="nav-item" role="presentation">
              <button class="nav-link<?= $id === 'sqlite' ? ' active' : '' ?>" id="pestana-<?= h($id) ?>" data-bs-toggle="tab"
                      data-bs-target="#guia-<?= h($id) ?>" type="button" role="tab" aria-controls="guia-<?= h($id) ?>"
                      aria-selected="<?= $id === 'sqlite' ? 'true' : 'false' ?>"><?= h($nombre) ?></button>
            </li>
          <?php endforeach; ?>
        </ul>

        <div class="tab-content pt-3 small">
          <!-- SQLite -->
          <div class="tab-pane fade show active" id="guia-sqlite" role="tabpanel" aria-labelledby="pestana-sqlite">
            <p><?= t('Con la herramienta de línea de órdenes <code>sqlite3</code> (en Windows, <code>sqlite3.exe</code>, de sqlite.org):') ?></p>
            <pre class="bg-body-tertiary p-2 rounded"><code>sqlite3 tienda.db .dump &gt; tienda.sql
sqlite3 tienda.db ".dump clientes pedidos" &gt; dos_tablas.sql</code></pre>
            <p><?= t('La segunda línea vuelca solo algunas tablas. Con <em>DB Browser for SQLite</em>: Archivo → Exportar → Base de datos a archivo SQL.') ?></p>
          </div>

          <!-- MySQL / MariaDB -->
          <div class="tab-pane fade" id="guia-mysql" role="tabpanel" aria-labelledby="pestana-mysql">
            <p><?= t('Con <code>mysqldump</code> (en las versiones recientes de MariaDB, <code>mariadb-dump</code>):') ?></p>
            <pre class="bg-body-tertiary p-2 rounded"><code>mysqldump --default-character-set=utf8mb4 -u usuario -p tienda &gt; tienda.sql</code></pre>
            <ul>
              <li><?= t('<code>--default-character-set=utf8mb4</code> conserva los acentos y los emojis. Un volcado antiguo en Latin-1 también se lee.') ?></li>
              <li><?= t('No uses <code>--xml</code>, <code>--tab</code> ni <code>--compatible</code>: no escriben SQL que el importador lea.') ?></li>
              <li><?= t('Desde phpMyAdmin: Exportar → Personalizado → Formato: SQL.') ?></li>
              <li><?= t('Las vistas y los triggers se traducen, también como los guarda MySQL 8. Los procedimientos y las funciones se saltan.') ?></li>
            </ul>
          </div>

          <!-- PostgreSQL -->
          <div class="tab-pane fade" id="guia-postgresql" role="tabpanel" aria-labelledby="pestana-postgresql">
            <p><?= t('Con <code>pg_dump</code>, en su formato de texto, el de siempre:') ?></p>
            <pre class="bg-body-tertiary p-2 rounded"><code>pg_dump --no-owner -h servidor -U usuario tienda &gt; tienda.sql</code></pre>
            <ul>
              <li><?= t('No uses <code>-Fc</code>, <code>-Fd</code> ni <code>-Ft</code>: son archivos binarios para <code>pg_restore</code>.') ?></li>
              <li><?= t('Desde pgAdmin: Copia de seguridad → Formato: Plano.') ?></li>
              <li><?= t('Las vistas y los triggers se traducen, con la función plpgsql de cada trigger. Un trigger de varios eventos (INSERT OR UPDATE) pasa a ser uno por evento.') ?></li>
            </ul>
          </div>

          <!-- SQL Server -->
          <div class="tab-pane fade" id="guia-sqlserver" role="tabpanel" aria-labelledby="pestana-sqlserver">
            <p><?= h(t('Con SQL Server Management Studio:')) ?></p>
            <ol>
              <li><?= h(t('Botón derecho sobre la base → Tareas → Generar scripts…')) ?></li>
              <li><?= h(t('Elige las tablas, o la base entera.')) ?></li>
              <li><?= h(t('En «Establecer opciones de scripting», abre «Avanzadas» y pon «Tipos de datos para incluir en el script» en «Esquema y datos».')) ?></li>
              <li><?= h(t('Guárdalo en un solo fichero, en Unicode o en UTF-8.')) ?></li>
            </ol>
            <p><?= t('Las vistas y los triggers se traducen. Un trigger de SQL Server trabaja con todas las filas a la vez (<code>inserted</code> y <code>deleted</code>); aquí, fila a fila, así que se reescribe. Los triggers <code>INSTEAD OF</code>, los cursores y los procedimientos almacenados se saltan.') ?></p>
          </div>

          <!-- Microsoft Access -->
          <div class="tab-pane fade" id="guia-access" role="tabpanel" aria-labelledby="pestana-access">
            <p><?= t('Con el script de PowerShell <code>access-to-jsonsqldb.ps1</code>, que <a href="{1}">se descarga aquí</a>. Necesita <strong>Windows</strong>.', [1 => h(url(['p' => 'script_access']))]) ?></p>
            <ol>
              <li><?= t('Botón derecho sobre el fichero descargado → <strong>«Ejecutar con PowerShell»</strong>.') ?></li>
              <li><?= t('Elige <strong>«Dump an Access database to SQL»</strong>, el .mdb o .accdb y la carpeta donde guardar el volcado.') ?></li>
              <li><?= h(t('Importa aquí el fichero .access.sql que deja en esa carpeta.')) ?></li>
            </ol>
            <p><?= t('La base se abre solo para leer. Con un <strong>.mdb</strong> no hace falta instalar nada: Windows trae su controlador (Jet), aunque solo para programas de 32 bits, y el script se vuelve a abrir solo con el PowerShell de 32 bits. Un <strong>.accdb</strong> necesita el <strong>Access Database Engine 2016</strong>, de los mismos bits que PowerShell (con Office de 32 bits, el de 32 bits y el PowerShell de 32 bits); si falta, el script lo dice y da el enlace para descargarlo.') ?></p>
            <p><?= t('Para el camino contrario, de aquí a Access: exporta la base como <strong>«SQL: Microsoft Access»</strong> y cárgalo con la opción <strong>«Load an SQL file into Access»</strong> del mismo script, que crea la base si no existe; o pega cada sentencia en Access a mano.') ?></p>

            <h6 class="mt-3"><?= h(t('Limitaciones de Access')) ?></h6>
            <p><?= h(t('El fichero está escrito en el SQL de Access, en su sintaxis de siempre (ANSI-89): cada sentencia se puede pegar en Crear → Diseño de consulta → Vista SQL y ejecutar. Esa sintaxis tiene estos límites:')) ?></p>
            <ul>
              <li><?= t('<strong>Una sentencia cada vez.</strong> La vista SQL no ejecuta varias seguidas, y las líneas que empiezan por <code>--</code> no se copian. Para un fichero entero, la opción del script.') ?></li>
              <li><?= t('<strong>CREATE VIEW no vale en todas las bases ni en todos los modos.</strong> Las consultas guardadas van como <code>CREATE VIEW [nombre] AS SELECT …</code>. Access lo admite en las bases .mdb de Access 2000 a 2003 (Jet 4.0) y en las .accdb (Access 2007 y posteriores), pero solo con la sintaxis ANSI-92: por ADO/OLEDB, o en la vista SQL si la base tiene activada «Sintaxis compatible con SQL Server (ANSI 92)» (opción que existe desde Access 2002; en Access 2010 y posteriores, Archivo → Opciones → Diseñadores de objetos). Con la sintaxis de siempre (ANSI-89) da error de sintaxis, y en una base de Access 97 o anterior (Jet 3) no existe. A mano, en cualquier versión: pega lo que va detrás de <code>AS</code> en una consulta nueva y guárdala con ese nombre. El script de PowerShell las crea como consultas guardadas sin usar CREATE VIEW, así que con él vale cualquier versión.') ?></li>
              <li><?= t('<strong>Sin DEFAULT ni relaciones en cascada.</strong> Van en una línea encima de su tabla o relación (<code>-- [tabla].[columna] DEFAULT valor</code>, <code>-- [relación] ON DELETE CASCADE</code>) para ponerlas a mano: el valor predeterminado en la vista Diseño de la tabla; la cascada en Herramientas de base de datos → Relaciones → Exigir integridad referencial. Al importar el fichero aquí se aplican solas.') ?></li>
              <li><?= t('<strong>Sin DECIMAL.</strong> Pasa a <code>CURRENCY</code> con hasta 4 decimales y a <code>DOUBLE</code> con más. <code>LONG</code> es de 32 bits: un entero mayor pasa a <code>DOUBLE</code>, exacto hasta 2^53.') ?></li>
              <li><?= t('<strong>Un INSERT por fila</strong>, y un salto de línea dentro de un texto se escribe <code>\'a\' &amp; Chr(13) &amp; Chr(10) &amp; \'b\'</code>.') ?></li>
              <li><?= t('<strong>Sin triggers.</strong> Van comentados al final; en un .accdb, las macros de datos se hacen a mano.') ?></li>
              <li><?= t('<strong>Sin</strong> FULL JOIN, INTERSECT, EXCEPT, OFFSET, GROUP_CONCAT ni expresiones regulares: las vistas que los usan van comentadas con el motivo.') ?></li>
              <li><?= t('El texto llega hasta 255 caracteres; más largo es <code>MEMO</code>, que no se puede indexar. Las fechas van del año 100 al 9999. Sí/No vale -1 en Access y 1 aquí.') ?></li>
              <li><?= t('Access compara los textos sin distinguir mayúsculas, también con <code>=</code>; aquí <code>=</code> sí las distingue.') ?></li>
              <li><?= t('<strong>No llegan</strong> los adjuntos ni los objetos OLE (datos binarios), las consultas de acción, de referencias cruzadas o con parámetros, ni las consultas ocultas de formularios e informes. El script las nombra al terminar.') ?></li>
            </ul>
          </div>
        </div>

        <hr>
        <p class="small mb-0"><?= t('En ninguno llegan igual: las restricciones <code>CHECK</code>, los valores por defecto calculados que no son la fecha u hora actual (esos sí llegan: <code>CURRENT_TIMESTAMP</code>, <code>NOW()</code>, <code>getdate()</code>…), las listas <code>ENUM</code>, la zona horaria de las fechas y los datos binarios que no son texto (se guardan en hexadecimal). Un nombre que aquí no vale se cambia (<code>Order Details</code> → <code>Order_Details</code>). Si el volcado trae <code>DROP TABLE</code>, la tabla con el mismo nombre se sustituye: ante la duda, importa en una base nueva.') ?></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(t('Cerrar')) ?></button>
      </div>
    </div>
  </div>
</div>
