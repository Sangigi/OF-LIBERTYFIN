<?php
use LibertyFin\Vista\Widget as W;
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\ConfigRepo;

/**
 * El menú.
 *
 * Cada entrada declara el permiso que pide, y se filtra con la MISMA
 * matriz que usa el portero de public/index.php. Así no puede pasar que
 * el enlace aparezca y la ruta rechace, ni al revés.
 */
// DOS MENÚS, no uno filtrado.
//
// Las secciones de empresa muestran los datos de UNA empresa: la de la
// sesión. Mezclarlas con las de plataforma haría que soporte viera
// "Ventas" y leyera ahí las de quien le prestó la cuenta, creyendo que
// son las del cliente que llamó. Para mirar dentro de una empresa está
// su ficha, que sí dice de quién son los números.
$deEmpresa = [
  ['grupo' => 'Operación'],
  ['ruta' => '/',           'icono' => 'panel',   'texto' => 'Panel',         'p' => 'ver.panel'],
  ['ruta' => '/caja',       'icono' => 'caja',    'texto' => 'Caja',          'p' => 'cobrar'],
  ['ruta' => '/ventas',     'icono' => 'venta',   'texto' => 'Ventas',        'p' => 'ver.ventas'],
  ['ruta' => '/clientes',   'icono' => 'cliente', 'texto' => 'Clientes',      'p' => 'ver.clientes'],
  ['ruta' => '/cobranza',   'icono' => 'reloj',   'texto' => 'Cobranza',      'p' => 'ver.cobranza',
   'sec' => 'cobranza'],
  ['ruta' => '/corte',      'icono' => 'caja',    'texto' => 'Corte de caja', 'p' => 'ver.corte',
   'sec' => 'corte'],
  ['ruta' => '/recargas',   'icono' => 'bolsa',   'texto' => 'Recargas',      'p' => 'ver.recargas',
   'si' => 'emida', 'sec' => 'recargas'],
  ['grupo' => 'Administración'],
  ['ruta' => '/comisiones', 'icono' => 'comi',    'texto' => 'Comisiones',    'p' => 'ver.comisiones',
   'sec' => 'comisiones'],
  ['ruta' => '/gastos',     'icono' => 'baja',    'texto' => 'Gastos',        'p' => 'ver.gastos',
   'sec' => 'gastos'],
  ['ruta' => '/servicios',  'icono' => 'serv',    'texto' => 'Servicios',     'p' => 'ver.servicios'],
  ['ruta' => '/reportes',   'icono' => 'pct',     'texto' => 'Reportes',      'p' => 'ver.reportes',
   'sec' => 'reportes'],
  ['ruta' => '/facturacion','icono' => 'serv',    'texto' => 'Facturación',   'p' => 'ver.facturacion',
   'si' => 'facturapi', 'sec' => 'facturacion'],
  ['ruta' => '/auditoria',  'icono' => 'reloj',   'texto' => 'Bitácora',      'p' => 'ver.auditoria'],
  ['ruta' => '/ajustes',    'icono' => 'serv',    'texto' => 'Ajustes',       'p' => 'ver.ajustes'],
];

$dePlataforma = [
  ['grupo' => 'Soporte'],
  ['ruta' => '/',             'icono' => 'panel',   'texto' => 'Panel',          'p' => 'ver.soporte'],
  ['ruta' => '/tickets',      'icono' => 'alerta',  'texto' => 'Tickets',        'p' => 'ver.tickets'],
  ['ruta' => '/soporte',      'icono' => 'cliente', 'texto' => 'Empresas',       'p' => 'ver.empresas'],
  ['ruta' => '/conocimiento', 'icono' => 'serv',    'texto' => 'Conocimiento',   'p' => 'ver.conocimiento'],
  ['grupo' => 'Plataforma'],
  ['ruta' => '/informes',     'icono' => 'pct',     'texto' => 'Informes',       'p' => 'ver.informes'],
  ['ruta' => '/mantenimiento','icono' => 'alerta',  'texto' => 'Mantenimiento',  'p' => 'ver.mantenimiento'],
];

$secciones = Permisos::esPlataforma($_SESSION['usuario_rol'] ?? '') ? $dePlataforma : $deEmpresa;

// Se filtra por permiso y por integración. Un grupo cuyas entradas
// desaparecieron tampoco se muestra: un encabezado suelto se ve roto.
// Qué secciones apagó soporte para esta empresa.
$apagadas = [];
try {
    $cfg = new ConfigRepo(Conexion::de($_SESSION['empresa_db']));
    foreach (ConfigRepo::APAGABLES as $k => $_) {
        if (!$cfg->seccionActiva($k)) $apagadas[$k] = true;
    }
} catch (\Throwable $e) { /* sin tabla de config, todo encendido */ }

$menu = []; $pendiente = null;
foreach ($secciones as $s) {
    if (isset($s['grupo'])) { $pendiente = $s; continue; }
    if (!Permisos::puede($s['p'])) continue;
    if (isset($s['si']) && !Integraciones::activa($s['si'])) continue;
    // Soporte sigue viéndolas aunque estén apagadas: para eso entra.
    if (isset($s['sec']) && isset($apagadas[$s['sec']])
        && ($_SESSION['usuario_rol'] ?? '') !== 'soporte') continue;
    if ($pendiente) { $menu[] = $pendiente; $pendiente = null; }
    $menu[] = $s;
}

/**
 * Qué entrada se marca como activa.
 *
 * Antes se comparaba por icono, y dos secciones que comparten icono se
 * encendían juntas: Caja y Corte usan 'caja', Clientes y Usuarios usan
 * 'cliente'. Se compara por ruta, que es lo único único.
 *
 * Gana la coincidencia más larga: estando en /ventas/137 debe marcarse
 * /ventas, no la raíz.
 */
$aqui = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$activa = '/'; $largo = 0;
foreach ($menu as $m) {
    if (isset($m['grupo'])) continue;
    $r = $m['ruta'];
    $coincide = ($r === '/') ? ($aqui === '/') : ($aqui === $r || strpos($aqui, $r . '/') === 0);
    if ($coincide && strlen($r) >= $largo) { $activa = $r; $largo = strlen($r); }
}
// Usuarios y Mi cuenta viven bajo Ajustes en el menú.
if (strpos($aqui, '/usuarios') === 0) $activa = '/ajustes';

$u   = $_SESSION['usuario_nombre'] ?? 'Usuario';
$rol = Permisos::rotulo($_SESSION['usuario_rol'] ?? '');
$ini = strtoupper(mb_substr($u, 0, 1) . mb_substr(strstr($u, ' ') ?: '', 1, 1));
?>
<?php
// En móvil el menú lateral se abre con el botón de la barra superior y
// las cuatro secciones más usadas viven en una barra de abajo, al alcance
// del pulgar. El lateral completo sigue ahí para todo lo demás.
$dedo = [];
foreach ($menu as $m) {
    if (isset($m['grupo'])) continue;
    $dedo[] = $m;
    if (count($dedo) >= 4) break;
}
?>
<div class="lf-velo" id="lfVelo" hidden></div>

<aside class="lf-side" id="lfSide">
  <button type="button" class="lf-cerrar-menu" id="lfCerrarMenu" aria-label="Cerrar menú">&times;</button>

  <div class="lf-marca">
    <?php $logo = $_SESSION['lf_marca_logo'] ?? ''; ?>
    <?php if ($logo): ?>
      <img class="g" src="<?= P::e($logo) ?>" alt="" width="38" height="38">
    <?php else: ?>
      <span class="g"><?= P::e(mb_strtoupper(mb_substr($_SESSION['empresa_nombre'] ?? 'L', 0, 1))) ?></span>
    <?php endif; ?>
    <span><b><?= P::e($_SESSION['empresa_nombre'] ?? 'LibertyFin') ?></b>
      <small><?= P::e($_SESSION['sucursal_nombre'] ?? 'Matriz') ?></small></span>
  </div>

  <nav class="lf-nav">
    <?php foreach ($menu as $m): ?>
      <?php if (isset($m['grupo'])): ?>
        <div class="sec"><?= P::e($m['grupo']) ?></div>
      <?php else: ?>
        <a href="<?= P::e($m['ruta']) ?>" class="<?= ($activa === $m['ruta'] ? 'on' : '') ?>">
          <?= W::icono($m['icono'], '17px') ?><?= P::e($m['texto']) ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="lf-pie">
    <div class="lf-ucard">
      <?php $foto = $_SESSION['lf_foto'] ?? ''; ?>
      <a href="/cuenta" class="lf-av<?= $foto ? ' con-foto' : '' ?>" title="Mi cuenta"
         style="text-decoration:none<?= $foto ? ";background-image:url('" . P::e($foto) . "')" : '' ?>">
        <?= $foto ? '' : P::e($ini) ?></a>
      <span style="flex:1;min-width:0"><b><?= P::e($u) ?></b><small><?= P::e($rol) ?></small></span>
      <a href="/salir" class="lf-btn-ghost" title="Cerrar sesión" style="flex-shrink:0">
        <?= W::icono('baja','15px') ?></a>
    </div>
  </div>
</aside>

<nav class="lf-dedo" aria-label="Secciones principales">
  <?php foreach ($dedo as $m): ?>
    <a href="<?= P::e($m['ruta']) ?>" class="<?= $activa === $m['ruta'] ? 'on' : '' ?>">
      <?= W::icono($m['icono'], '19px') ?><span><?= P::e($m['texto']) ?></span>
    </a>
  <?php endforeach; ?>
  <button type="button" id="lfAbrirMenu" aria-label="Más secciones">
    <?= W::icono('panel', '19px') ?><span>Más</span>
  </button>
</nav>

<script>
(function(){
  var side = document.getElementById('lfSide'),
      velo = document.getElementById('lfVelo'),
      abrir = document.getElementById('lfAbrirMenu'),
      cerrar = document.getElementById('lfCerrarMenu');
  if (!side || !velo) return;

  function estado(ab){
    document.body.classList.toggle('lf-menu-abierto', ab);
    velo.hidden = !ab;
    if (abrir) abrir.setAttribute('aria-expanded', ab ? 'true' : 'false');
  }
  if (abrir)  abrir.addEventListener('click', function(){ estado(true); });
  if (cerrar) cerrar.addEventListener('click', function(){ estado(false); });
  velo.addEventListener('click', function(){ estado(false); });
  // Escape cierra: en un panel que tapa la pantalla, no tener salida por
  // teclado deja atrapado a quien no usa el ratón.
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') estado(false);
  });
  // Al tocar una sección se cierra solo: dejarlo abierto encima de la
  // página que acaba de cargar obliga a un toque de más.
  side.addEventListener('click', function(e){
    if (e.target.closest('a')) estado(false);
  });
})();
</script>
