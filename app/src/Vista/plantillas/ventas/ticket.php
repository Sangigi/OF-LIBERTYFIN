<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Dominio\Dinero as D;
$v = $venta;
?><!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%2327ae60'/><text x='50' y='50' font-family='DM Sans,system-ui,sans-serif' font-size='62' font-weight='800' fill='white' text-anchor='middle' dominant-baseline='central'>L</text></svg>">
<meta name="theme-color" content="#27ae60">
<title>Ticket <?= P::e($v['codigo_venta']) ?></title>
<style>
/* El ticket trae su propio CSS: es la única pantalla que se imprime,
   y cargar la hoja completa solo para tacharla al imprimir no tiene
   sentido. Ancho de 80 mm, el de las impresoras térmicas. */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',system-ui,sans-serif;background:#f1f4f2;
     color:#1b2420;padding:20px;display:flex;flex-direction:column;align-items:center;gap:14px}
.t{width:80mm;max-width:100%;background:#fff;padding:16px 18px;
   border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08);font-size:12px;line-height:1.45}
.mrc{text-align:center;padding-bottom:12px;border-bottom:1px dashed #cfd8d3}
.mrc b{font-size:15px;letter-spacing:-.3px;display:block}
.mrc small{font-size:10.5px;color:#6d7a74;display:block;margin-top:2px}
.fol{text-align:center;padding:11px 0;border-bottom:1px dashed #cfd8d3}
.fol b{font-family:ui-monospace,monospace;font-size:13px;letter-spacing:.5px}
.fol small{display:block;font-size:10.5px;color:#6d7a74;margin-top:2px}
.dat{padding:11px 0;border-bottom:1px dashed #cfd8d3}
.dat div{display:flex;justify-content:space-between;gap:10px;padding:2px 0}
.dat span{color:#6d7a74} .dat b{font-weight:600;text-align:right}
.it{padding:11px 0;border-bottom:1px dashed #cfd8d3}
.it .l{display:flex;justify-content:space-between;gap:10px;padding:4px 0}
.it .l b{font-weight:600} .it .l small{display:block;color:#6d7a74;font-size:10.5px}
.it .m{font-family:ui-monospace,monospace;font-weight:600;white-space:nowrap}
.sum{padding:11px 0;border-bottom:1px dashed #cfd8d3}
.sum div{display:flex;justify-content:space-between;gap:10px;padding:2px 0}
.sum .tt{font-size:15px;font-weight:800;padding-top:8px;margin-top:6px;border-top:1px solid #1b2420}
.sum .m{font-family:ui-monospace,monospace}
.sal{margin-top:10px;padding:9px 11px;border-radius:7px;background:#fbf3e0;color:#c58a14;
     font-size:11.5px;text-align:center}
.pie{text-align:center;padding-top:12px;font-size:10.5px;color:#6d7a74;line-height:1.6}
.acc{display:flex;gap:9px}
.acc button,.acc a{padding:9px 18px;border-radius:99px;border:1px solid #e6ebe8;background:#fff;
  color:#43504a;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;font-family:inherit}
.acc button{background:#27ae60;border-color:#27ae60;color:#fff}
@media print{
  body{background:#fff;padding:0;display:block}
  .t{width:auto;box-shadow:none;border-radius:0;padding:0}
  .acc{display:none}
  @page{margin:6mm}
}
</style>
</head>
<body>
<div class="t">
  <div class="mrc">
    <b><?= P::e($empresa['nombre_empresa'] ?? 'LibertyFin') ?></b>
    <?php if (!empty($empresa['rfc'])): ?><small>RFC <?= P::e($empresa['rfc']) ?></small><?php endif; ?>
    <?php if (!empty($empresa['direccion'])): ?><small><?= P::e($empresa['direccion']) ?></small><?php endif; ?>
    <?php if (!empty($empresa['telefono'])): ?><small>Tel. <?= P::e($empresa['telefono']) ?></small><?php endif; ?>
  </div>

  <div class="fol">
    <b><?= P::e($v['codigo_venta']) ?></b>
    <small><?= date('d/m/Y · H:i', strtotime($v['fecha'])) ?></small>
  </div>

  <div class="dat">
    <div><span>Cliente</span><b><?= P::e($v['cliente'] ?: 'Público general') ?></b></div>
    <?php if ($v['vendedor']): ?>
      <div><span>Atendió</span><b><?= P::e($v['vendedor']) ?></b></div><?php endif; ?>
    <div><span>Pago</span><b><?= P::e(ucfirst($v['metodo_pago'])) ?></b></div>
  </div>

  <div class="it">
    <?php foreach ($lineas as $l): ?>
      <div class="l">
        <span><b><?= P::e($l['producto'] ?: 'Servicio') ?></b>
          <small><?= (float)$l['cantidad'] ?> × <?= D::pesos($l['precio_unitario']) ?></small></span>
        <span class="m"><?= D::pesos($l['subtotal']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="sum">
    <div><span>Subtotal</span><span class="m"><?= D::pesos($v['subtotal']) ?></span></div>
    <?php if ((float)$v['descuento'] > 0): ?>
      <div><span>Descuento</span><span class="m">−<?= D::pesos($v['descuento']) ?></span></div><?php endif; ?>
    <?php if ((float)$v['iva'] > 0): ?>
      <div><span>IVA</span><span class="m"><?= D::pesos($v['iva']) ?></span></div><?php endif; ?>
    <div class="tt"><span>Total</span><span class="m"><?= D::pesos($v['total']) ?></span></div>
    <div style="margin-top:6px"><span>Pagado</span><span class="m"><?= D::pesos($v['cobrado']) ?></span></div>
  </div>

  <?php if ((float)$v['saldo'] > 0.01): ?>
    <div class="sal">Queda un saldo de <b><?= D::pesos($v['saldo']) ?></b></div>
  <?php endif; ?>

  <div class="pie">
    <?php if ((float)$v['saldo'] > 0.01): ?>
      Este comprobante ampara un pago parcial.<br>
    <?php endif; ?>
    Gracias por su preferencia.
  </div>
</div>

<div class="acc">
  <button type="button" onclick="window.print()">Imprimir</button>
  <a href="/ventas/<?= (int)$v['id'] ?>">Volver</a>
</div>

<script>
// Si llega con ?auto=1 desde el cobro, se manda a imprimir solo.
if (new URLSearchParams(location.search).get('auto') === '1') {
  window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });
}
</script>
</body>
</html>
