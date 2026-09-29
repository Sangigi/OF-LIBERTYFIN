<?php use LibertyFin\Vista\Widget as W; use LibertyFin\Vista\Plantilla as P; ?>
<header class="lf-top">
  <div>
    <h1><?= W::icono($icono, '18px') ?><span><?= P::e($titulo) ?></span></h1>
    <?php if ($subtitulo): ?><p><?= P::e($subtitulo) ?></p><?php endif; ?>
  </div>
  <div class="lf-acc">
    <button class="btn btn-secondary"><?= W::icono('baja', '15px') ?>Exportar</button>
    <a class="btn btn-primary" href="/caja"><?= W::icono('mas', '15px') ?>Nueva venta</a>
  </div>
</header>
