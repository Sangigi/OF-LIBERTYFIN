<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token   = $_SESSION['lf_token'];
$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';
$total   = (float)($resumen['total'] ?? 0);
$pagar   = (float)($resumen['por_pagar'] ?? 0);
$sin     = (float)($resumen['sin_asignar'] ?? 0);
$iniciales = function ($n) {
    $p = preg_split('/\s+/', trim($n));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : ''));
};
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<form class="lf-filtros" method="get">
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::corto($pagar) ?></div>
    <div class="stat-label">Por pagar</div>
    <?php W::avance(D::pct($pagar, max(0.01,$total))); ?>
    <div class="stat-meta">de <?= D::corto($total) ?> que generó el periodo</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= D::corto($sin) ?></div>
    <div class="stat-label">Sin dueño</div>
    <?php W::avance(D::pct($sin, max(0.01,$total)), true); ?>
    <div class="stat-meta"><?= (int)($resumen['ventas_pendientes'] ?? 0) ?> ventas sin confirmar quién vendió</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::corto($resumen['por_liberar'] ?? 0) ?></div>
    <div class="stat-label">Por liberar</div>
    <div class="stat-meta">se libera conforme los clientes paguen</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['colaboradores'] ?? 0) ?></div>
    <div class="stat-label">Colaboradores</div>
    <div class="stat-meta">con comisión en el periodo</div>
  </div>
</div>

<?php if ($sin_dueno): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-amb) 30%,transparent)">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><span>Comisiones sin dueño</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Cuentan para el total, pero no se le pueden pagar a nadie hasta confirmar quién vendió</p></div>
    <span class="badge bg-warning"><?= D::pesos($sin) ?></span>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Venta</th><th>Área</th><th class="text-end">%</th>
        <th class="text-end">Monto</th><th style="width:250px">Asignar a</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sin_dueno as $s): ?>
        <tr>
          <td data-label="Venta">
            <a href="/ventas/<?= (int)$s['venta_id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($s['cliente'] ?: 'Público general') ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($s['codigo_venta']) ?> · <?= date('d M', strtotime($s['fecha'])) ?></span>
          </td>
          <td data-label="Área"><span class="badge bg-secondary"><?= P::e($s['area_nombre']) ?></span></td>
          <td data-label="%" class="text-end lf-mono"><?= number_format($s['porcentaje'],2) ?>%</td>
          <td data-label="Monto" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($s['monto']) ?></td>
          <td data-label="Asignar a">
            <?php if (!$esAdmin): ?>
              <span style="font-size:12px;color:var(--lf-tinta-4)">Solo un administrador</span>
            <?php elseif (empty($candidatos[$s['area_nombre']])): ?>
              <span style="font-size:12px;color:var(--lf-tinta-4)">
                No hay colaboradores en <?= P::e($s['area_nombre']) ?></span>
            <?php else: ?>
              <form method="post" action="/comisiones/reasignar" style="display:flex;gap:7px">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="renglon" value="<?= (int)$s['renglon'] ?>">
                <select class="form-select form-select-sm" name="colaborador" required>
                  <option value="">Elegir…</option>
                  <?php foreach ($candidatos[$s['area_nombre']] as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= P::e($c['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm" type="submit">Asignar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<div class="lf-split">
  <section class="card">
    <header class="card-header">Por colaborador</header>
    <div style="padding:0 10px 8px">
      <?php if (!$equipo): ?>
        <p style="padding:26px;text-align:center;color:var(--lf-tinta-4);font-size:13px">
          No hay comisiones devengadas en este periodo.</p>
      <?php endif; ?>
      <?php foreach ($equipo as $c): $huerfano = (int)$c['sin_dueno'] === 1; ?>
        <div class="lf-row" style="<?= $huerfano ? 'opacity:.72' : '' ?>">
          <span class="lf-av <?= $huerfano ? 'gris' : '' ?>">
            <?= $huerfano ? '?' : P::e($iniciales($c['colaborador_nombre'])) ?></span>
          <span style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px"><?= P::e($c['colaborador_nombre']) ?></b>
            <small style="color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($c['area_nombre']) ?> · <?= (int)$c['ventas'] ?> venta<?= $c['ventas']==1?'':'s' ?></small>
          </span>
          <?php if ($huerfano): ?>
            <span class="badge bg-warning" style="margin-right:8px">No se paga</span>
          <?php endif; ?>
          <b class="lf-mono" style="font-size:14px"><?= D::pesos($c['devengado']) ?></b>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between">
      <span>Total generado</span>
      <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($total) ?></b>
    </div>
  </section>

  <?php if ($areas): ?>
  <section class="card">
    <header class="card-header">Por área</header>
    <div class="card-body">
      <?php W::dona(array_map(function($a){
        return ['rotulo'=>$a['area'] ?: 'Sin área','monto'=>$a['monto']]; }, $areas),
        D::corto($total), 'generado'); ?>
    </div>
  </section>
  <?php endif; ?>
</div>
