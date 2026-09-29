<?php
/**
 * Configuración: cómo está conectado el panel y con qué garantías. Solo la
 * ven los administradores. No cambia nada: la configuración vive en
 * config.php, y para rehacerla con el asistente basta con borrar ese fichero.
 *
 * https://miguelenred.es/jsonsqldb
 */
Auth::exigirAdmin();

// Una consulta de prueba, para saber que el motor responde y cuánto tarda
$inicio = microtime(true);
try {
    $bases = count(Api::bases());
    $prueba = null;
} catch (Throwable $e) {
    $bases = 0;
    $prueba = $e->getMessage();
}
$ms = (microtime(true) - $inicio) * 1000;

$filas = [
    'Conexión'            => Api::directa() ? 'Directa: el panel carga el motor y le habla sin HTTP'
                                            : 'Por la API firmada con HMAC',
    Api::directa() ? 'Motor' : 'URL de la API' => Api::directa() ? Api::rutaMotor() : Api::url(),
    'Respuesta del motor' => $prueba === null
        ? sprintf('%s en %.1f ms', $bases . ($bases === 1 ? ' base' : ' bases'), $ms)
        : 'no responde: ' . $prueba,
    'Configuración'       => Instalador::rutaConfig(),
    'Usuarios y auditoría'=> (string)ADMIN_DATA_PATH,
    'HTTPS'               => ADMIN_EXIGIR_HTTPS ? 'exigido' : 'no exigido: solo para pruebas en local',
    'IPs permitidas'      => ADMIN_IPS_PERMITIDAS === [] ? 'cualquiera' : implode(', ', (array)ADMIN_IPS_PERMITIDAS),
    'Versión'             => (version() !== '' ? version() : '—') . ' · PHP ' . PHP_VERSION,
];
if (Api::directa() && defined('JSONSQLDB_DATA_PATH')) {
    $filas['Datos del motor'] = (string)JSONSQLDB_DATA_PATH;
    $filas['Caché'] = function_exists('apcu_enabled') && apcu_enabled() ? 'APCu' : 'en disco (.cache/ de cada base)';
}
?>
<div class="page-head">
  <div>
    <h1>Configuración</h1>
    <p>Cómo está conectado el panel y con qué garantías</p>
  </div>
</div>

<?php if (PHP_VERSION_ID < 80100): ?>
  <div class="alert alert-warning">
    <strong>Se recomienda PHP 8.1 o posterior: PHP <?= h(PHP_VERSION) ?> no puede forzar los datos al disco.</strong>
    Al escribir, el sistema operativo guarda los datos en memoria y los pasa al disco un poco después. La
    función <code>fsync()</code>, que obliga a grabarlos en el momento, existe en PHP solo desde la 8.1, y en 8.0
    no hay forma fiable de hacer lo mismo sin extensiones. Por eso, con 8.0, jsonSQLDB no pierde nada si el
    proceso muere a mitad de una escritura, pero ante un <strong>corte de luz o un fallo del sistema
    operativo</strong> se pueden perder las escrituras que aún no estaban en disco —en Linux con la configuración
    por defecto, aproximadamente los últimos 30 segundos—, aunque se hubieran dado por hechas. Las tablas no se
    dañan en ext4 con sus opciones por defecto. Si tu hosting ofrece PHP 8.1 o posterior, cámbialo; la
    explicación completa está en el README del proyecto.
  </div>
<?php endif; ?>

<div class="settings-grid">
  <div class="card">
    <div class="card-header"><?= icono('plug') ?> Conexión y sistema</div>
    <div class="card-body">
      <table class="kv">
        <?php foreach ($filas as $k => $v): ?>
          <tr><th><?= h($k) ?></th><td class="break-all"><?= h($v) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><?= icono('info') ?> Cómo cambiarla</div>
    <div class="card-body settings-hint">
      <p>Todo está en <code><?= h(basename(Instalador::rutaConfig())) ?></code>, con un comentario que explica
         cada opción. Se edita a mano; el panel no la cambia desde aquí.</p>
      <p><strong>Para volver al asistente</strong> (por ejemplo, para pasar de la API a la conexión directa),
         borra <code>config.php</code> y abre el panel: los usuarios y la auditoría se conservan, y el asistente
         pedirá solo la conexión.</p>
      <p class="mb-0"><strong>Conexión directa</strong> exige que el panel y los datos estén en la misma máquina y
         que el proceso de PHP pueda escribir en la carpeta de datos del motor. <strong>Por la API</strong>, la
         clave y el secreto del panel tienen que coincidir con los de su cuenta en
         <code>api/jsonsqldb_api_config.php</code>.</p>
    </div>
  </div>
</div>
