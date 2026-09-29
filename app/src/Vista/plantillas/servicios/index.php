<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$mv  = $resumen['mas_vendido'] ?? null;
$at  = $resumen['area_top'] ?? null;
$tot = 0; foreach ($areas as $a) $tot += (float)$a['monto'];
?>

<form class="lf-filtros" method="get">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Servicio o código">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-stats">
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['activos'] ?? 0) ?></div>
    <div class="stat-label">Servicios activos</div>
    <div class="stat-meta">en el catálogo</div></div>

  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('bolsa','19px') ?></div>
    <div class="stat-value" style="font-size:17px;letter-spacing:-.3px;font-family:var(--lf-font)">
      <?= P::e($mv['nombre'] ?? '—') ?></div>
    <div class="stat-label">Más vendido</div>
    <div class="stat-meta"><?= $mv ? (int)$mv['veces'] . ' ventas · ' . D::corto($mv['ingreso']) : 'sin ventas en el periodo' ?></div></div>

  <div class="stat-card"><div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value" style="font-size:17px;letter-spacing:-.3px;font-family:var(--lf-font)">
      <?= P::e($at['area'] ?? '—') ?></div>
    <div class="stat-label">Área con más ingreso</div>
    <div class="stat-meta"><?= $at ? D::pesos($at['cobrado']) . ' cobrados' : '—' ?></div></div>

  <div class="stat-card"><div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['sin_ventas'] ?? 0) ?></div>
    <div class="stat-label">Sin ventas</div>
    <div class="stat-meta">en los últimos 60 días</div></div>
</div>

<div class="lf-split">
  <?php if ($top): ?>
  <section class="card">
    <header class="card-header">Servicios que más facturan</header>
    <div class="card-body">
      <?php W::barrasH(array_map(function($t){
        return ['rotulo'=>$t['nombre'],'monto'=>$t['monto']]; }, $top)); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($areas): ?>
  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
      <div><span>Cobrado por área</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Cobrado, no facturado</p></div>
    </header>
    <div class="card-body">
      <?php W::dona(array_map(function($a){
        return ['rotulo'=>$a['area'],'monto'=>$a['monto']]; }, $areas),
        D::corto($tot), 'cobrado'); ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<section class="card">
  <header class="card-header">Catálogo</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Servicio</th><th>Código</th><th>Área</th>
        <th class="text-end">Precio</th><th class="text-end">Ventas</th><th class="text-end">Ingreso</th>
      </tr></thead>
      <tbody>
      <?php if (!$catalogo): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay servicios que coincidan.</td></tr>
      <?php endif; ?>
      <?php foreach ($catalogo as $s): ?>
        <tr>
          <td data-label="Servicio"><b style="font-weight:600"><?= P::e($s['nombre']) ?></b></td>
          <td data-label="Código"><span class="badge bg-secondary"><?= P::e($s['codigo']) ?></span></td>
          <td data-label="Área"><span class="badge bg-secondary"><?= P::e($s['categoria'] ?: 'Sin área') ?></span></td>
          <td data-label="Precio" class="text-end lf-mono"><?= D::pesos($s['precio']) ?></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$s['ventas'] ?></td>
          <td data-label="Ingreso" class="text-end">
            <?php if ((int)$s['ventas'] > 0): ?>
              <b class="lf-mono"><?= D::pesos($s['ingreso']) ?></b>
            <?php else: ?>
              <span class="badge bg-warning">Sin ventas</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <span><?= count($catalogo) ?> servicios</span>
    <span>Cobrado en el periodo <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($tot) ?></b></span>
  </div>
</section>
