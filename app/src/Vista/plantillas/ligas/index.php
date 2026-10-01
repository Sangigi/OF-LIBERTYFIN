<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$c = $cifras;
$qs = function ($x = []) use ($estado, $q) {
    return '?' . http_build_query(array_merge(array_filter(['estado'=>$estado,'q'=>$q]),
        array_filter($x, function($v){ return $v !== null; })));
};
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$listo): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Faltan las credenciales de SPEI</b> en <code>config/integraciones.php</code>.
    Sin ellas no se pueden generar ligas de pago.</span>
</div>
<?php elseif ($sandbox): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Modo de pruebas.</b> Las ligas se generan contra el ambiente de prueba:
    no cobran dinero real.</span>
</div>
<?php endif; ?>

<?php if ($reciente): $r = $reciente; ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-brand) 42%,transparent)">
  <header class="card-header">
    <div><span>Liga lista</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        <?= P::e($r['cliente_nombre'] ?: $r['descripcion']) ?> ·
        <?= D::pesos($r['monto']) ?></p></div>
    <a class="btn btn-secondary btn-sm" href="/ligas">Cerrar</a>
  </header>
  <div class="card-body">
    <div class="lf-formas">
      <?php if ($r['liga']): ?>
        <div class="f">
          <b><?= W::icono('cobro','16px') ?>Tarjeta o pago en línea</b>
          <p>Mándale esta dirección. Ahí elige cómo pagar.</p>
          <div class="copiar">
            <input type="text" readonly value="<?= P::e($r['liga']) ?>" id="cLiga">
            <button type="button" class="btn btn-primary btn-sm" data-copiar="cLiga">Copiar</button>
          </div>
          <a href="<?= P::e($r['liga']) ?>" target="_blank" rel="noopener" class="abrir">Abrirla</a>
        </div>
      <?php endif; ?>

      <?php if ($r['clabe']): ?>
        <div class="f">
          <b><?= W::icono('venta','16px') ?>Transferencia SPEI</b>
          <p>Que transfiera a esta CLABE desde su banco. Se acredita en minutos.</p>
          <div class="copiar">
            <input type="text" readonly value="<?= P::e($r['clabe']) ?>" id="cClabe" class="lf-mono">
            <button type="button" class="btn btn-primary btn-sm" data-copiar="cClabe">Copiar</button>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($r['barras']): ?>
        <div class="f">
          <b><?= W::icono('caja','16px') ?>Efectivo en tiendas</b>
          <p>Con esta referencia paga en OXXO y tiendas participantes.</p>
          <div class="copiar">
            <input type="text" readonly value="<?= P::e($r['barras']) ?>" id="cBarras" class="lf-mono">
            <button type="button" class="btn btn-primary btn-sm" data-copiar="cBarras">Copiar</button>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:16px;padding-top:14px;
              border-top:1px solid var(--lf-linea);line-height:1.6">
      <b>Todavía no entró el dinero.</b> La venta sigue con saldo hasta que el
      proveedor confirme el pago. Dale a <b>Revisar pagos</b> para preguntar, o
      espera a que alguien lo haga.
      <?php if ($r['vence']): ?>
        La liga vence el <?= date('d/m/Y', strtotime($r['vence'])) ?>.
      <?php endif; ?>
    </p>
  </div>
</section>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($c['esperando'] ?? 0) ?></div>
    <div class="stat-label">Esperando pago</div>
    <div class="stat-meta"><?= (int)($c['pendientes'] ?? 0) ?> ligas abiertas</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($c['cobrado_hoy'] ?? 0) ?></div>
    <div class="stat-label">Cobrado hoy por liga</div>
    <div class="stat-meta"><?= (int)($c['pagadas'] ?? 0) ?> pagadas en total</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($c['vencidas'] ?? 0) ?></div>
    <div class="stat-label">Vencidas sin pagar</div>
    <div class="stat-meta">hay que generar otra</div>
  </div>
</div>

<?php if ($listo): ?>
<details class="lf-alta">
  <summary><?= W::icono('mas','16px') ?>Generar una liga de pago</summary>
  <form method="post" action="/ligas/generar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">

    <div style="flex:2;min-width:260px">
      <label class="form-label">¿Para qué venta?</label>
      <select class="form-select" name="venta" id="selVenta">
        <option value="">Un cobro suelto, sin venta</option>
        <?php foreach ($pendientes as $v): ?>
          <option value="<?= (int)$v['id'] ?>" data-saldo="<?= (float)$v['saldo'] ?>">
            <?= P::e($v['cliente']) ?> · <?= P::e($v['codigo_venta']) ?> ·
            debe <?= D::pesos($v['saldo']) ?>
            <?= (int)$v['dias'] > 0 ? ' · ' . (int)$v['dias'] . ' días' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <small style="font-size:11px;color:var(--lf-tinta-4);display:block;margin-top:5px">
        Si eliges una venta, el monto sale de su saldo y no se puede cambiar.
      </small>
    </div>

    <div style="width:170px" id="campoMonto">
      <label class="form-label">Monto</label>
      <input class="form-control lf-mono" type="number" name="monto" step="0.01" min="1"
             placeholder="0.00">
    </div>

    <div style="flex:1;min-width:200px">
      <label class="form-label">Concepto</label>
      <input class="form-control" name="descripcion" maxlength="40"
             placeholder="Lo que verá el cliente">
      <small style="font-size:11px;color:var(--lf-tinta-4);display:block;margin-top:5px">
        Máximo 40 caracteres: es lo que cabe en el recibo del banco.
      </small>
    </div>

    <div style="width:210px">
      <label class="form-label">Cómo puede pagar</label>
      <select class="form-select" name="metodo">
        <?php foreach ($metodos as $k => $m): ?>
          <option value="<?= $k ?>" <?= $k==='todos'?'selected':'' ?>><?= P::e($m[0]) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="width:130px">
      <label class="form-label">Vigencia</label>
      <select class="form-select" name="dias">
        <?php foreach ([1=>'1 día', 3=>'3 días', 7=>'1 semana', 15=>'15 días', 30=>'30 días'] as $d=>$t): ?>
          <option value="<?= $d ?>" <?= $d===3?'selected':'' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button class="btn btn-primary" type="submit">Generar</button>
  </form>
</details>
<script>
(function(){
  // Con una venta elegida, el monto es su saldo y no se toca: generar
  // una liga por más de lo que debe dejaría un sobrepago que hay que
  // devolver a mano.
  var s = document.getElementById('selVenta'),
      c = document.getElementById('campoMonto'),
      m = c.querySelector('input');
  function ver(){
    var o = s.options[s.selectedIndex], saldo = o.dataset.saldo;
    if (saldo) { m.value = parseFloat(saldo).toFixed(2); m.readOnly = true; c.style.opacity = '.6'; }
    else { m.readOnly = false; c.style.opacity = '1'; if (m.value && o.value === '') m.value = m.value; }
  }
  s.addEventListener('change', ver); ver();
})();
</script>
<?php endif; ?>

<div class="lf-pills" style="margin-bottom:14px">
  <?php foreach (['pendientes'=>'Esperando', 'pagada'=>'Pagadas',
                  'vencidas'=>'Vencidas', ''=>'Todas'] as $k=>$t): ?>
    <a class="lf-pill <?= $estado===$k?'active':'' ?>"
       href="<?= P::e($qs(['estado'=>$k ?: null, 'p'=>null])) ?>"><?= $t ?></a>
  <?php endforeach; ?>
</div>

<section class="card">
  <header class="card-header">
    <div><span>Ligas</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Una liga pide el dinero; no lo cobra</p></div>
    <?php if ($listo): ?>
      <form method="post" action="/ligas/revisar" style="flex-shrink:0">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn btn-primary btn-sm" type="submit">
          <?= W::icono('reloj','14px') ?>Revisar pagos</button>
      </form>
    <?php endif; ?>
  </header>

  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Cliente</th><th>Concepto</th><th class="text-end">Monto</th>
        <th>Cómo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php if (!$ligas): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay ligas con ese filtro.</td></tr>
      <?php endif; ?>
      <?php foreach ($ligas as $l):
        $vencida = $l['estado']==='pendiente' && $l['vence'] && strtotime($l['vence']) < strtotime('today'); ?>
        <tr>
          <td data-label="Cliente">
            <b style="font-weight:600"><?= P::e($l['cliente_nombre'] ?: '—') ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($l['referencia']) ?>
              <?php if ($l['pruebas']): ?> · prueba<?php endif; ?></span></td>
          <td data-label="Concepto" style="font-size:12.5px"><?= P::e($l['descripcion']) ?>
            <?php if ($l['venta_id']): ?>
              <a style="display:block;font-size:11px" href="/ventas/<?= (int)$l['venta_id'] ?>">ver la venta</a>
            <?php endif; ?></td>
          <td data-label="Monto" class="text-end lf-mono" style="font-weight:700">
            <?= D::pesos($l['monto']) ?></td>
          <td data-label="Cómo" style="font-size:12px;color:var(--lf-tinta-3)">
            <?= P::e($metodos[$l['metodo']][0] ?? $l['metodo']) ?></td>
          <td data-label="Estado">
            <?php if ($l['estado'] === 'pagada'): ?>
              <span class="badge bg-success">Pagada</span>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
                <?= date('d/m/y H:i', strtotime($l['pagado_en'])) ?></span>
            <?php elseif ($vencida): ?>
              <span class="badge bg-danger">Venció</span>
            <?php else: ?>
              <span class="badge bg-warning">Esperando</span>
              <?php if ($l['vence']): ?>
                <span style="display:block;color:var(--lf-tinta-4);font-size:11px">
                  hasta <?= date('d/m', strtotime($l['vence'])) ?></span>
              <?php endif; ?>
            <?php endif; ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if ($l['liga']): ?>
              <a class="lf-btn-ghost" href="<?= P::e($l['liga']) ?>" target="_blank"
                 rel="noopener" title="Abrir la liga"><?= W::icono('venta','15px') ?></a>
            <?php endif; ?>
            <?php if ($l['estado'] === 'pendiente' && $listo): ?>
              <form method="post" action="/ligas/revisar" style="display:inline">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <button class="lf-btn-ghost" type="submit" title="¿Ya pagó?">
                  <?= W::icono('reloj','15px') ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($ligas) ?> de <?= number_format($total) ?></span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
      'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
  </div>
  <div class="card-footer" style="border-top:none;padding-top:0">
    El abono se aplica cuando el proveedor confirma, no al generar la liga. Si se
    diera por cobrada antes, el corte de caja diría que entró dinero que nadie pagó.
  </div>
</section>

<script>
document.querySelectorAll('[data-copiar]').forEach(function(b){
  b.addEventListener('click', function(){
    var i = document.getElementById(b.dataset.copiar);
    navigator.clipboard.writeText(i.value).then(function(){
      var t = b.textContent; b.textContent = 'Copiado';
      setTimeout(function(){ b.textContent = t; }, 1600);
    }).catch(function(){ i.select(); });
  });
});
</script>
