<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$listo = !$faltan && $docs['estado'] === 'aprobada';
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$listo): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-amb) 36%,transparent)">
  <header class="card-header">
    <div><span>Falta esto para poder timbrar</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Se revisa aquí y no al enviar: descubrirlo con la factura a medias es peor</p></div>
  </header>
  <div class="card-body">
    <?php if ($faltan): ?>
      <a class="lf-row" href="/cuenta?t=fiscales">
        <span class="lf-tile a" style="width:34px;height:34px;border-radius:11px;margin:0;flex-shrink:0">
          <?= W::icono('alerta','16px') ?></span>
        <span style="flex:1;min-width:0">
          <b style="display:block;font-size:13.5px">Datos fiscales incompletos</b>
          <small style="color:var(--lf-tinta-4);font-size:11.5px">
            Falta <?= P::e(implode(', ', $faltan)) ?></small>
        </span>
        <?= W::icono('venta','15px') ?>
      </a>
    <?php endif; ?>
    <?php if ($docs['estado'] !== 'aprobada'): ?>
      <a class="lf-row" href="/cuenta?t=documentos">
        <span class="lf-tile a" style="width:34px;height:34px;border-radius:11px;margin:0;flex-shrink:0">
          <?= W::icono('alerta','16px') ?></span>
        <span style="flex:1;min-width:0">
          <b style="display:block;font-size:13.5px">Documentación sin aprobar</b>
          <small style="color:var(--lf-tinta-4);font-size:11.5px">
            <?= (int)$docs['aprobados'] ?> de <?= (int)$docs['total'] ?> aprobados</small>
        </span>
        <?= W::icono('venta','15px') ?>
      </a>
    <?php endif; ?>
  </div>
</section>
<?php elseif ($sandbox): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Modo de pruebas.</b> Las facturas se timbran contra el ambiente de
    pruebas del SAT: no tienen validez fiscal. Se cambia en
    <code>config/integraciones.php</code>, poniendo <code>'sandbox' =&gt; false</code>.</span>
</div>
<?php endif; ?>

<form class="lf-filtros" method="get">
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<section class="card">
  <header class="card-header">
    <div><span>Ventas del periodo</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Solo se factura lo cobrado: el SAT no timbra lo que todavía no se pagó</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Cliente</th><th class="text-end">Total</th><th class="text-end">Cobrado</th>
        <th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php if (!$ventas): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay ventas en este periodo.</td></tr>
      <?php endif; ?>
      <?php foreach ($ventas as $v):
        $liquidada = (float)$v['saldo'] <= 0.01;
        $sinCliente = empty($v['cliente']); ?>
        <tr>
          <td data-label="Cliente">
            <a href="/ventas/<?= (int)$v['id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($v['cliente'] ?: 'Público general') ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($v['codigo_venta']) ?> · <?= date('d M', strtotime($v['fecha'])) ?></span>
          </td>
          <td data-label="Total" class="text-end lf-mono"><?= D::pesos($v['total']) ?></td>
          <td data-label="Cobrado" class="text-end lf-mono"><?= D::pesos($v['cobrado']) ?></td>
          <td data-label="Estado">
            <?php if (!$liquidada): ?>
              <span class="badge bg-warning">Sin liquidar</span>
            <?php elseif ($sinCliente): ?>
              <span class="badge bg-secondary">Sin cliente</span>
            <?php else: ?>
              <span class="badge bg-success">Se puede facturar</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <?php if ($listo && $liquidada && !$sinCliente): ?>
              <a class="btn btn-secondary btn-sm" href="/facturacion/<?= (int)$v['id'] ?>/timbrar">Timbrar</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Una venta sin cliente no se puede facturar: el CFDI necesita el RFC de quien
    recibe. Y una sin liquidar tampoco, porque el SAT timbra el pago, no la promesa.
  </div>
</section>
