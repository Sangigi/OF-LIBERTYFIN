<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token   = $_SESSION['lf_token'];
$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';
$v       = $venta;
$total   = (float)$v['total'];
$cobrado = (float)$v['cobrado'];
$saldo   = (float)$v['saldo'];
$gastos  = (float)$v['gastos'];
$avance  = D::pct($cobrado, $total);
$base    = (float)$v['subtotal'] - (float)$v['descuento'];
$neto    = $base - $gastos;
$sumAsig = 0; $sumDev = 0;
foreach ($comisiones as $c) { $sumAsig += (float)$c['asignada']; $sumDev += (float)$c['devengada']; }
$ini = function ($n) { $p = preg_split('/\s+/', trim($n));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : '')); };
?>

<?php if ($nueva): ?>
<div class="alert alert-success" style="margin-bottom:18px">
  <?= W::icono('cobro','18px') ?><span><b>Venta registrada.</b> Folio <?= P::e($v['codigo_venta']) ?>.</span>
</div>
<?php endif; ?>
<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($cobrado) ?></div>
    <div class="stat-label">Cobrado</div>
    <?php W::avance($avance); ?>
    <div class="stat-meta"><?= $avance ?>% de <?= D::pesos($total) ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile <?= $saldo > 0.01 ? 'a' : 'g' ?>"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($saldo) ?></div>
    <div class="stat-label">Saldo</div>
    <div class="stat-meta"><?php W::estado($saldo, $v['estado']); ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('baja','19px') ?></div>
    <div class="stat-value"><?= D::pesos($neto) ?></div>
    <div class="stat-label">Neto comisionable</div>
    <div class="stat-meta">base <?= D::corto($base) ?> − gastos <?= D::corto($gastos) ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::pesos($sumDev) ?></div>
    <div class="stat-label">Comisión devengada</div>
    <?php if ($sumAsig > 0) W::avance(D::pct($sumDev, $sumAsig)); ?>
    <div class="stat-meta">de <?= D::pesos($sumAsig) ?> si liquida</div>
  </div>
</div>

<div class="lf-split">
  <div>
    <section class="card">
      <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span>Cobranza</span>
        <span class="badge bg-secondary"><?= count(array_filter($pagos, function($p){ return !$p['cancelado']; })) ?> pagos</span>
      </header>
      <div class="table-responsive lf-cards" style="padding:0 12px 6px">
        <table class="table table-hover">
          <thead><tr>
            <th>Fecha</th><th>Tipo</th><th>Método</th>
            <th class="text-end">Monto</th><th class="text-end">Comisión</th><th></th>
          </tr></thead>
          <tbody>
          <?php if (!$pagos): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:28px">
              Todavía no hay ningún cobro.</td></tr>
          <?php endif; ?>
          <?php foreach ($pagos as $p): $canc = (int)$p['cancelado'] === 1; ?>
            <tr style="<?= $canc ? 'opacity:.5' : '' ?>">
              <td data-label="Fecha" class="lf-mono" style="font-size:12px">
                <?= date('d/m/Y', strtotime($p['fecha_pago'])) ?>
                <?php if ($p['referencia']): ?>
                  <span style="display:block;color:var(--lf-tinta-4);font-size:11px"><?= P::e($p['referencia']) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Tipo">
                <span class="badge <?= $canc ? 'bg-danger' : 'bg-secondary' ?>">
                  <?= $canc ? 'Cancelado' : P::e($p['tipo']) ?></span>
                <?php if ($canc && $p['motivo_cancelacion']): ?>
                  <span style="display:block;color:var(--lf-tinta-4);font-size:11px;margin-top:3px">
                    <?= P::e($p['motivo_cancelacion']) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Método" style="font-size:12.5px"><?= P::e($p['metodo_pago']) ?></td>
              <td data-label="Monto" class="text-end lf-mono" style="font-weight:700">
                <?= $canc ? '<s>' . D::pesos($p['monto']) . '</s>' : D::pesos($p['monto']) ?></td>
              <td data-label="Comisión" class="text-end lf-mono" style="color:var(--lf-tinta-3)">
                <?= $canc ? '—' : D::pesos($p['comision']) ?></td>
              <td style="text-align:right">
                <?php if (!$canc && $esAdmin): ?>
                  <button type="button" class="lf-btn-ghost lf-cancelar" data-pago="<?= (int)$p['id'] ?>"
                          data-monto="<?= P::e(D::pesos($p['monto'])) ?>" title="Cancelar pago">&times;</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($saldo > 0.01 && $v['estado'] !== 'cancelada'): ?>
      <div style="border-top:1px solid var(--lf-linea);padding:16px 20px;background:var(--lf-vidrio)">
        <form method="post" action="/ventas/<?= (int)$v['id'] ?>/pagar"
              style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <div style="flex:1;min-width:130px">
            <label class="form-label">Abono</label>
            <input class="form-control lf-mono" type="number" name="monto" step="0.01" min="0.01"
                   max="<?= $saldo ?>" value="<?= number_format($saldo, 2, '.', '') ?>" required>
          </div>
          <div style="min-width:130px">
            <label class="form-label">Fecha</label>
            <input class="form-control" type="date" name="fecha" value="<?= date('Y-m-d') ?>">
          </div>
          <div style="min-width:130px">
            <label class="form-label">Método</label>
            <select class="form-select" name="metodo">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia" selected>Transferencia</option>
              <option value="tarjeta">Tarjeta</option>
            </select>
          </div>
          <div style="flex:1;min-width:130px">
            <label class="form-label">Referencia</label>
            <input class="form-control" type="text" name="referencia" placeholder="Opcional">
          </div>
          <button class="btn btn-primary" type="submit"><?= W::icono('cobro','15px') ?>Registrar</button>
        </form>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:10px">
          No se puede abonar más del saldo. La comisión se libera en proporción a lo cobrado.</p>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($comisiones): ?>
    <section class="card">
      <header class="card-header">Comisiones</header>
      <div class="table-responsive lf-cards" style="padding:0 12px 6px">
        <table class="table">
          <thead><tr>
            <th>Colaborador</th><th>Área</th><th class="text-end">%</th>
            <th class="text-end">Si liquida</th><th class="text-end">Devengada</th><th class="text-end">Pendiente</th>
          </tr></thead>
          <tbody>
          <?php foreach ($comisiones as $c): $sd = $c['colaborador_nombre'] === 'POR ASIGNAR'; ?>
            <tr style="<?= $sd ? 'opacity:.72' : '' ?>">
              <td data-label="Colaborador">
                <span style="display:flex;align-items:center;gap:9px">
                  <span class="lf-av <?= $sd ? 'gris' : '' ?>" style="width:28px;height:28px;font-size:10px">
                    <?= $sd ? '?' : P::e($ini($c['colaborador_nombre'])) ?></span>
                  <b style="font-weight:600"><?= P::e($c['colaborador_nombre']) ?></b>
                </span>
              </td>
              <td data-label="Área"><span class="badge bg-secondary"><?= P::e($c['area_nombre']) ?></span></td>
              <td data-label="%" class="text-end lf-mono"><?= number_format($c['pct'],2) ?>%</td>
              <td data-label="Si liquida" class="text-end lf-mono" style="color:var(--lf-tinta-3)"><?= D::pesos($c['asignada']) ?></td>
              <td data-label="Devengada" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($c['devengada']) ?></td>
              <td data-label="Pendiente" class="text-end lf-mono" style="color:var(--lf-amb)"><?= D::pesos($c['pendiente']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        Se ha cobrado el <?= $avance ?>%, así que solo se devenga esa parte.
        Lo pendiente se libera conforme el cliente pague.
      </div>
    </section>
    <?php endif; ?>
  </div>

  <div>
    <section class="card">
      <header class="card-header">Concepto</header>
      <div style="padding:0 20px">
        <?php foreach ($lineas as $l): ?>
          <div style="display:flex;gap:10px;padding:11px 0;border-bottom:1px solid var(--lf-linea)">
            <div style="flex:1;min-width:0">
              <b style="display:block;font-size:13px;font-weight:600"><?= P::e($l['producto'] ?: 'Servicio') ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11px">
                <?= (float)$l['cantidad'] ?> × <?= D::pesos($l['precio_unitario']) ?>
                <?= $l['codigo'] ? ' · ' . P::e($l['codigo']) : '' ?></small>
            </div>
            <span class="lf-mono" style="font-weight:700"><?= D::pesos($l['subtotal']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="lf-sumas">
        <div><span>Subtotal</span><span class="lf-mono"><?= D::pesos($v['subtotal']) ?></span></div>
        <?php if ((float)$v['descuento'] > 0): ?>
          <div><span>Descuento</span><span class="lf-mono">−<?= D::pesos($v['descuento']) ?></span></div>
        <?php endif; ?>
        <div><span>IVA<?= (float)$v['iva'] > 0 ? ' (' . (($v['iva_modo'] ?? 'incluido') === 'sumar' ? 'sumado' : 'incluido') . ')' : '' ?></span>
          <span class="lf-mono"><?= D::pesos($v['iva']) ?></span></div>
        <div class="tt"><span>Total</span><span><?= D::pesos($total) ?></span></div>
      </div>
    </section>

    <section class="card">
      <header class="card-header">Datos</header>
      <div class="card-body" style="font-size:13px">
        <?php
        $datos = [
          'Folio'     => $v['codigo_venta'],
          'Cliente'   => $v['cliente'] ?: 'Público general',
          'Teléfono'  => $v['telefono'] ?: null,
          'Vendedor'  => $v['vendedor'] ?: null,
          'Área'      => $v['area_nombre'] ?: 'Sin área',
          'Método'    => $v['metodo_pago'],
          'Fecha'     => date('d/m/Y H:i', strtotime($v['fecha'])),
        ];
        foreach ($datos as $k => $val): if ($val === null) continue; ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0">
            <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
            <b style="font-weight:600;text-align:right"><?= P::e($val) ?></b>
          </div>
        <?php endforeach; ?>
        <?php if ($v['descripcion']): ?>
          <p style="margin-top:12px;padding-top:12px;border-top:1px solid var(--lf-linea);
                    font-size:12.5px;color:var(--lf-tinta-2)"><?= P::e($v['descripcion']) ?></p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($gastos_lista = $gastos): ?>
    <section class="card">
      <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <span>Gastos de operación</span>
        <span class="badge bg-warning"><?= D::pesos($v['gastos']) ?></span>
      </header>
      <div class="card-body" style="font-size:13px">
        <?php foreach ($gastos_lista as $g): ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0">
            <span style="color:var(--lf-tinta-2)"><?= P::e($g['concepto']) ?></span>
            <b class="lf-mono"><?= D::pesos($g['monto']) ?></b>
          </div>
        <?php endforeach; ?>
        <p style="margin-top:10px;padding-top:10px;border-top:1px solid var(--lf-linea);
                  font-size:11.5px;color:var(--lf-tinta-4)">
          Se restan de la utilidad antes de calcular comisiones. El primer pago los absorbe completos.</p>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($esAdmin): ?>
<form method="post" action="/ventas/<?= (int)$v['id'] ?>/cancelar-pago" id="formCancelar" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="pago" id="cancPago">
  <input type="hidden" name="motivo" id="cancMotivo">
</form>
<script>
document.querySelectorAll('.lf-cancelar').forEach(function(b){
  b.addEventListener('click', function(){
    var m = prompt('¿Por qué se cancela el pago de ' + b.dataset.monto + '?\n\nEl motivo queda registrado.');
    if (!m || !m.trim()) return;
    document.getElementById('cancPago').value = b.dataset.pago;
    document.getElementById('cancMotivo').value = m.trim();
    document.getElementById('formCancelar').submit();
  });
});
</script>
<?php endif; ?>
