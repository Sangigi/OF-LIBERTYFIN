<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Cotizaciones as C;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$c = $cot;
$abierta = in_array($c['estado'], C::ABIERTAS, true);
$colores = ['borrador' => 'bg-secondary', 'enviada' => 'bg-info', 'aceptada' => 'bg-success', 'rechazada' => 'bg-danger'];
$boton = function ($estado, $texto, $clase = 'btn-secondary', $confirmar = '') use ($token, $c) {
    return '<form method="post" action="/cotizaciones/' . (int)$c['id'] . '/estado" style="display:inline"'
         . ($confirmar !== '' ? ' onsubmit="return confirm(\'' . P::e($confirmar) . '\')"' : '') . '>'
         . '<input type="hidden" name="token" value="' . P::e($token) . '">'
         . '<input type="hidden" name="estado" value="' . $estado . '">'
         . '<button class="btn ' . $clase . '" type="submit">' . P::e($texto) . '</button></form>';
};
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-doc-acciones">
  <a class="btn btn-secondary" href="/cotizaciones"><?= W::icono('atras','14px') ?> Cotizaciones</a>
  <span class="esp"></span>
  <a class="btn btn-secondary" href="/cotizaciones/<?= (int)$c['id'] ?>/imprimir" target="_blank" rel="noopener"><?= W::icono('doc','14px') ?> Imprimir / PDF</a>
  <?php if ($abierta && $puedeEditar): ?>
    <a class="btn btn-secondary" href="/cotizaciones?editar=<?= (int)$c['id'] ?>#altaCot">Editar</a>
    <?php if ($c['estado'] === 'borrador'): ?><?= $boton('enviada', 'Marcar como enviada') ?><?php endif; ?>
    <?= $boton('rechazada', 'No la aceptó', 'btn-secondary', '¿El cliente la rechazó? Podrás reabrirla después.') ?>
  <?php endif; ?>
  <?php if ($c['estado'] === 'rechazada' && $puedeEditar): ?><?= $boton('borrador', 'Reabrir') ?><?php endif; ?>
  <?php if ($abierta && $puedeCobrar): ?>
    <a class="btn btn-primary" href="/caja?cotizacion=<?= (int)$c['id'] ?>"><?= W::icono('caja','14px') ?> Cobrar en Caja</a>
  <?php endif; ?>
</div>

<section class="card lf-doc">
  <div class="lf-doc-cab">
    <div>
      <span class="lf-doc-folio lf-mono"><?= P::e($c['folio']) ?></span>
      <h3><?= P::e($c['cliente_nombre']) ?></h3>
      <?php if ($c['cliente_id']): ?><a href="/clientes/<?= (int)$c['cliente_id'] ?>" class="lf-doc-sub">Ver ficha del cliente</a>
      <?php else: ?><span class="lf-doc-sub">Prospecto (no está en el catálogo de clientes)</span><?php endif; ?>
    </div>
    <div class="lf-doc-meta">
      <?php if ($c['vencida']): ?><span class="badge bg-warning">Vencida</span>
      <?php else: ?><span class="badge <?= $colores[$c['estado']] ?? 'bg-secondary' ?>"><?= P::e(C::ESTADOS[$c['estado']]) ?></span><?php endif; ?>
      <dl>
        <dt>Fecha</dt><dd><?= P::e(date('d/m/Y', strtotime($c['creado_en']))) ?></dd>
        <dt>Vigente hasta</dt><dd><?= $c['vigencia'] ? P::e(date('d/m/Y', strtotime($c['vigencia']))) : '—' ?></dd>
        <?php if ($c['creado_por']): ?><dt>Hizo</dt><dd><?= P::e($c['creado_por']) ?></dd><?php endif; ?>
        <?php if ($c['codigo_venta']): ?><dt>Venta</dt><dd><a href="/ventas/<?= (int)$c['venta_id'] ?>"><?= P::e($c['codigo_venta']) ?></a></dd><?php endif; ?>
      </dl>
    </div>
  </div>
  <div class="table-responsive lf-cards" style="padding:0 12px">
    <table class="table">
      <thead><tr><th>Concepto</th><th class="text-end">Cantidad</th><th class="text-end">Precio c/u</th><th class="text-end">Importe</th></tr></thead>
      <tbody>
      <?php foreach ($c['lineas'] as $l): ?>
        <tr>
          <td data-label="Concepto"><?= P::e($l['nombre']) ?></td>
          <td data-label="Cantidad" class="text-end lf-mono"><?= P::e(\LibertyFin\Servicio\Inventario::num($l['cantidad'])) ?></td>
          <td data-label="Precio" class="text-end lf-mono"><?= D::pesos($l['precio']) ?></td>
          <td data-label="Importe" class="text-end lf-mono"><b><?= D::pesos($l['cantidad'] * $l['precio']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="3" class="text-end"><b>Total</b></td><td class="text-end lf-mono lf-doc-total"><?= D::pesos($c['total']) ?></td></tr></tfoot>
    </table>
  </div>
  <?php if ($c['notas']): ?>
    <div class="lf-doc-notas"><b>Notas</b><p><?= nl2br(P::e($c['notas'])) ?></p></div>
  <?php endif; ?>
  <div class="card-footer"><span>
    <?php if ($abierta): ?>
      Al cobrarla en Caja, la cotización queda <b>aceptada</b> y ligada a su venta. Puedes ajustar precios o cantidades en Caja antes de cobrar.
    <?php elseif ($c['estado'] === 'aceptada'): ?>
      Se volvió venta: ya no cambia. Lo que se cobró está en la venta.
    <?php else: ?>
      El cliente no la aceptó. «Reabrir» la regresa a borrador.
    <?php endif; ?>
  </span></div>
</section>
