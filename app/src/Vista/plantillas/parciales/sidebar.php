<?php use LibertyFin\Vista\Widget as W; use LibertyFin\Vista\Plantilla as P;
$menu = [
  ['grupo' => 'Operación'],
  ['ruta' => '/',          'icono' => 'panel',   'texto' => 'Panel'],
  ['ruta' => '/caja',      'icono' => 'caja',    'texto' => 'Caja'],
  ['ruta' => '/ventas',    'icono' => 'venta',   'texto' => 'Ventas'],
  ['ruta' => '/clientes',  'icono' => 'cliente', 'texto' => 'Clientes'],
  ['grupo' => 'Administración'],
  ['ruta' => '/comisiones','icono' => 'comi',    'texto' => 'Comisiones'],
  ['ruta' => '/servicios', 'icono' => 'serv',    'texto' => 'Servicios'],
];
$u = $_SESSION['usuario_nombre'] ?? 'Usuario';
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
      <span class="lf-av"><?= P::e($ini) ?></span>
      <span style="flex:1"><b><?= P::e($u) ?></b><small><?= P::e($_SESSION['usuario_rol'] ?? '') ?></small></span>
    </div>
  </div>
</aside>
