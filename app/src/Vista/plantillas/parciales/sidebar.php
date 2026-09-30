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
$secciones = [
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
  ['ruta' => '/ajustes',    'icono' => 'serv',    'texto' => 'Ajustes',       'p' => 'ver.ajustes'],
  ['ruta' => '/mantenimiento','icono'=> 'alerta', 'texto' => 'Mantenimiento', 'p' => 'ver.mantenimiento'],
];

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

$u   = $_SESSION['usuario_nombre'] ?? 'Usuario';
$rol = Permisos::ROLES[$_SESSION['usuario_rol'] ?? '']['rotulo'] ?? ($_SESSION['usuario_rol'] ?? '');
$ini = strtoupper(mb_substr($u, 0, 1) . mb_substr(strstr($u, ' ') ?: '', 1, 1));
?>
<aside class="lf-side">
  <div class="lf-marca">
    <span class="g">L</span>
    <span><b>LibertyFin</b><small><?= P::e($_SESSION['sucursal_nombre'] ?? 'Matriz') ?></small></span>
  </div>

  <nav class="lf-nav">
    <?php foreach ($menu as $m): ?>
      <?php if (isset($m['grupo'])): ?>
        <div class="sec"><?= P::e($m['grupo']) ?></div>
      <?php else: ?>
        <a href="<?= P::e($m['ruta']) ?>" class="<?= ($activo === $m['icono'] ? 'on' : '') ?>">
          <?= W::icono($m['icono'], '17px') ?><?= P::e($m['texto']) ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="lf-pie">
    <div class="lf-ucard">
      <a href="/cuenta" class="lf-av" title="Mi cuenta" style="text-decoration:none"><?= P::e($ini) ?></a>
      <span style="flex:1;min-width:0"><b><?= P::e($u) ?></b><small><?= P::e($rol) ?></small></span>
      <a href="/salir" class="lf-btn-ghost" title="Cerrar sesión" style="flex-shrink:0">
        <?= W::icono('baja','15px') ?></a>
    </div>
  </div>
</aside>
