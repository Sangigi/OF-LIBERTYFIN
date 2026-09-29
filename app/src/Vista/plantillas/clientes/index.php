<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$ini = function ($n) {
    $p = preg_split('/\s+/', trim($n));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : ''));
};
$qs = function (array $x = []) use ($desde,$hasta,$buscar) {
    return '?' . http_build_query(array_merge(['desde'=>$desde,'hasta'=>$hasta,'q'=>$buscar], $x));
};
$ant = $antiguedad;
?>

<form class="lf-filtros" method="get">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Nombre o teléfono">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-stats">
  <div class="stat-card"><div class="lf-tile"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['activos'] ?? 0) ?></div>
    <div class="stat-label">Clientes activos</div>
    <div class="stat-meta">con venta en el periodo</div></div>

  <div class="stat-card"><div class="lf-tile l"><?= W::icono('mas','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['nuevos'] ?? 0) ?></div>
    <div class="stat-label">Nuevos</div>
    <div class="stat-meta">su primera compra fue en este periodo</div></div>

  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['con_saldo'] ?? 0) ?></div>
    <div class="stat-label">Con saldo abierto</div>
    <div class="stat-meta"><?= D::pesos($resumen['saldo'] ?? 0) ?> por cobrar</div></div>

  <div class="stat-card"><div class="lf-tile g"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['recurrentes'] ?? 0) ?></div>
    <div class="stat-label">Recurrentes</div>
    <div class="stat-meta">dos o más compras</div></div>
</div>

<div class="lf-split">
  <?php if ($top): ?>
  <section class="card">
    <header class="card-header">Clientes que más facturan</header>
    <div class="card-body">
      <?php W::barrasH(array_map(function($t){
        return ['rotulo'=>$t['nombre'],'monto'=>$t['monto']]; }, $top)); ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div><span>Antigüedad del saldo</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Desde el último abono</p></div>
      <span class="badge bg-warning"><?= D::pesos($ant['total'] ?? 0) ?></span>
    </header>
    <div class="card-body">
      <?php if ((float)($ant['total'] ?? 0) <= 0): ?>
        <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:14px 0">
          Nadie debe nada. Todo liquidado.</p>
      <?php else:
        W::segmentos([
          ['rotulo'=>'1 a 30 días',   'monto'=>$ant['t30']    ?? 0, 'color'=>'var(--lf-brand)'],
          ['rotulo'=>'31 a 60 días',  'monto'=>$ant['t60']    ?? 0, 'color'=>'var(--lf-amb)'],
          ['rotulo'=>'Más de 60 días','monto'=>$ant['t60mas'] ?? 0, 'color'=>'var(--lf-rojo)'],
        ]);
        if ((float)($ant['t60mas'] ?? 0) > 0): ?>
          <p style="margin-top:16px;padding-top:14px;border-top:1px solid var(--lf-linea);
                    font-size:12.5px;color:var(--lf-tinta-3)">
            <?= D::pesos($ant['t60mas']) ?> en <?= (int)$ant['n60mas'] ?>
            venta<?= $ant['n60mas']==1?'':'s' ?> llevan más de 60 días sin un solo abono.
            Son a los que hay que llamar primero.</p>
        <?php endif;
      endif; ?>
    </div>
  </section>
</div>

<section class="card">
  <header class="card-header">Directorio</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Cliente</th><th>Área</th><th class="text-end">Compras</th>
        <th class="text-end">Facturado</th><th class="text-end">Saldo</th><th>Última</th>
      </tr></thead>
      <tbody>
      <?php if (!$clientes): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          Ningún cliente con actividad en este periodo.</td></tr>
      <?php endif; ?>
      <?php foreach ($clientes as $c): $deb = (float)$c['saldo'] > 0.01; ?>
        <tr>
          <td data-label="Cliente">
            <span style="display:flex;align-items:center;gap:10px">
              <span class="lf-av gris" style="width:30px;height:30px;font-size:11px">
                <?= P::e($ini($c['nombre'])) ?></span>
              <span style="min-width:0">
                <b style="display:block;font-weight:600"><?= P::e($c['nombre']) ?></b>
                <?php if ($c['telefono']): ?>
                  <small style="color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($c['telefono']) ?></small>
                <?php endif; ?>
              </span>
            </span>
          </td>
          <td data-label="Área"><span class="badge bg-secondary"><?= P::e($c['area'] ?: 'Sin área') ?></span></td>
          <td data-label="Compras"   class="text-end lf-mono"><?= (int)$c['compras'] ?></td>
          <td data-label="Facturado" class="text-end lf-mono"><?= D::pesos($c['facturado']) ?></td>
          <td data-label="Saldo" class="text-end">
            <?php if ($deb): ?>
              <b class="lf-mono" style="color:var(--lf-amb)"><?= D::pesos($c['saldo']) ?></b>
            <?php else: ?>
              <span class="badge bg-success">Al corriente</span>
            <?php endif; ?>
          </td>
          <td data-label="Última" class="lf-mono" style="font-size:12px">
            <?= date('d M', strtotime($c['ultima'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($clientes) ?> de <?= number_format($total) ?> clientes</span>
    <?php if ($paginas > 1): ?>
    <nav><ul class="pagination" style="margin:0">
      <li class="page-item <?= $pagina<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= P::e($qs(['p'=>$pagina-1])) ?>">‹</a></li>
      <li class="page-item disabled"><span class="page-link"><?= $pagina ?> / <?= $paginas ?></span></li>
      <li class="page-item <?= $pagina>=$paginas?'disabled':'' ?>">
        <a class="page-link" href="<?= P::e($qs(['p'=>$pagina+1])) ?>">›</a></li>
    </ul></nav>
    <?php endif; ?>
  </div>
</section>
