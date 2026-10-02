<?php
/**
 * El contenido de la ventana de un colaborador.
 *
 * Solo el trozo, sin diseno de pagina: lo inserta el JavaScript dentro
 * de la ventana flotante.
 */
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Dominio\Dinero as D;
?>
<div class="lf-vent-cuerpo">
  <?php if (!$filas): ?>
    <p class="vacio">No tiene comisiones en este periodo.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr>
        <th>Folio</th><th>Cliente</th><th>Área</th>
        <th>Pago</th><th>Tipo</th>
        <th class="text-end">Venta</th><th class="text-end">Cobrado</th>
        <th class="text-end">%</th><th class="text-end">Sobre</th>
        <th class="text-end">Comisión</th>
      </tr></thead>
      <tbody>
      <?php foreach ($filas as $f): ?>
        <tr>
          <td data-label="Folio">
            <a href="/ventas/<?= (int)$f['venta_id'] ?>" class="lf-mono"
               style="font-size:11.5px"><?= P::e($f['folio']) ?></a></td>
          <td data-label="Cliente"><?= P::e($f['cliente']) ?></td>
          <td data-label="Área" style="font-size:11.5px;color:var(--lf-tinta-3)">
            <?= P::e($f['area']) ?></td>
          <td data-label="Pago" class="lf-mono" style="font-size:11.5px">
            <?= date('d/m/y', strtotime($f['fecha_pago'])) ?></td>
          <td data-label="Tipo" style="font-size:11.5px">
            <?= P::e(ucfirst((string)$f['tipo_pago'])) ?></td>
          <td data-label="Venta" class="text-end lf-mono"><?= D::pesos($f['total_venta']) ?></td>
          <td data-label="Cobrado" class="text-end lf-mono"><?= D::pesos($f['cobrado']) ?></td>
          <td data-label="%" class="text-end lf-mono"><?= number_format((float)$f['porcentaje'],2) ?>%</td>
          <td data-label="Sobre" class="text-end lf-mono"
              style="color:var(--lf-tinta-4)"><?= number_format((float)$f['proporcion']*100,1) ?>%</td>
          <td data-label="Comisión" class="text-end lf-mono"
              style="font-weight:700;color:var(--lf-brand-2)"><?= D::pesos($f['comision']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="9" style="font-weight:600">
          <?= count($filas) ?> pago<?= count($filas)==1?'':'s' ?></td>
        <td class="text-end lf-mono" style="font-weight:700"><?= D::pesos($total) ?></td>
      </tr></tfoot>
    </table>
    <p class="nota">
      La comisión <b>no es un porcentaje de la venta</b>: es el porcentaje aplicado
      sobre lo que se cobró en ese pago. La columna <b>Sobre</b> dice qué parte de la
      venta entró con ese abono.
    </p>
  <?php endif; ?>
</div>
