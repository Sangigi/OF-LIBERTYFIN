<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
$r = $resumen;
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)$r['activas'] ?></div>
    <div class="stat-label">Empresas activas</div>
    <div class="stat-meta">de <?= (int)$r['empresas'] ?> registradas</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= (int)$r['por_vencer'] ?></div>
    <div class="stat-label">Vencen pronto</div>
    <div class="stat-meta">en los próximos 15 días</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)$r['vencidas'] ?></div>
    <div class="stat-label">Vencidas</div>
    <div class="stat-meta">siguen entrando igual</div>
  </div>
</div>

<form class="lf-filtros" method="get">
  <div class="lf-search" style="max-width:340px">
    <?= W::icono('buscar','15px') ?>
    <input class="form-control form-control-sm" type="search" name="q"
           value="<?= P::e($buscar) ?>" placeholder="Nombre, correo, RFC o base de datos">
  </div>
  <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
  <?php if ($buscar): ?><a class="btn btn-secondary btn-sm" href="/soporte">Limpiar</a><?php endif; ?>
</form>

<?php if (!$conPulso): ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Son demasiadas empresas para consultar sus cifras en la lista. Búscala por
    nombre y ábrela para ver su ficha completa.</span>
</div>
<?php endif; ?>

<section class="card">
  <header class="card-header">Empresas</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Empresa</th><th>Contacto</th><th>Plan</th><th>Vence</th>
        <th class="text-end">Ventas</th><th>Última</th><th>Esquema</th>
      </tr></thead>
      <tbody>
      <?php if (!$empresas): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          <?= $buscar ? 'Ninguna empresa coincide.' : 'No hay empresas registradas.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($empresas as $e):
        $venc = !empty($e['fecha_vencimiento']) ? strtotime($e['fecha_vencimiento']) : null;
        $dias = $venc ? floor(($venc - strtotime('today')) / 86400) : null;
        $pu   = $e['pulso'];
        $atras = $pu && $pu['esquema'] < $ultima; ?>
        <tr style="<?= $e['activo'] ? '' : 'opacity:.55' ?>">
          <td data-label="Empresa">
            <a href="/soporte/<?= (int)$e['id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($e['nombre_empresa']) ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($e['nombre_base_datos']) ?></span>
            <?php if (!$e['activo']): ?>
              <span class="badge bg-secondary" style="margin-top:3px">Inactiva</span><?php endif; ?>
          </td>
          <td data-label="Contacto" style="font-size:12.5px">
            <?= P::e($e['nombre_contacto'] ?: '—') ?>
            <?php if ($e['email_admin']): ?>
              <a style="display:block;font-size:11px" href="mailto:<?= P::e($e['email_admin']) ?>">
                <?= P::e($e['email_admin']) ?></a>
            <?php endif; ?>
          </td>
          <td data-label="Plan" style="font-size:12.5px"><?= P::e(ucfirst($e['plan'] ?: 'prueba')) ?>
            <?php if ($e['no_distribuidor']): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
                dist. <?= P::e($e['no_distribuidor']) ?></span><?php endif; ?></td>
          <td data-label="Vence">
            <?php if ($dias === null): ?><span style="color:var(--lf-tinta-4)">—</span>
            <?php elseif ($dias < 0): ?>
              <span class="badge bg-danger">hace <?= abs($dias) ?> d</span>
            <?php elseif ($dias < 15): ?>
              <span class="badge bg-warning"><?= $dias ?> d</span>
            <?php else: ?>
              <span class="lf-mono" style="font-size:12px"><?= date('d/m/y', $venc) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Ventas" class="text-end lf-mono">
            <?= $pu ? number_format((int)$pu['ventas']) : '—' ?>
            <?php if ($pu && (int)$pu['cajas'] > 0): ?>
              <span style="display:block;color:var(--lf-brand-2);font-size:11px">
                <?= (int)$pu['cajas'] ?> caja<?= $pu['cajas']==1?'':'s' ?> abierta<?= $pu['cajas']==1?'':'s' ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Última" class="lf-mono" style="font-size:12px">
            <?= ($pu && $pu['ultima']) ? date('d/m/y', strtotime($pu['ultima']))
                : '<span style="color:var(--lf-tinta-4)">—</span>' ?></td>
          <td data-label="Esquema">
            <?php if (!$pu): ?><span style="color:var(--lf-tinta-4)">—</span>
            <?php elseif ($atras): ?>
              <span class="badge bg-warning">v<?= (int)$pu['esquema'] ?> de <?= (int)$ultima ?></span>
            <?php else: ?>
              <span class="badge bg-success">al día</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Una empresa vencida sigue entrando igual: el vencimiento se muestra, no se
    aplica. Cortar el acceso solo porque venció una fecha deja a un negocio sin
    poder cobrar, y eso se decide con una llamada, no con un <code>IF</code>.
  </div>
</section>
