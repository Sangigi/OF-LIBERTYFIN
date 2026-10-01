<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$h = $hoy;
$qs = function ($c = []) use ($q, $cat, $carrier) {
    $b = array_filter(['q'=>$q, 'cat'=>$cat, 'carrier'=>$carrier]);
    return '?' . http_build_query(array_merge($b, array_filter($c, function($v){ return $v !== null; })));
};
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$cifrado && !$aceptado): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>La conexión con Emida va sin cifrar.</b> El usuario, la clave y el número
    del cliente viajan en claro. Pídele a Emida un endpoint con HTTPS.</span>
</div>
<?php endif; ?>

<?php if ($sandbox): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Modo de pruebas.</b> Nada llega al teléfono ni descuenta saldo real.</span>
</div>
<?php endif; ?>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= $saldo !== null ? D::pesos($saldo) : '—' ?></div>
    <div class="stat-label">Saldo con el proveedor</div>
    <div class="stat-meta">
      <?= $saldo !== null ? 'al momento de consultar' : 'consúltalo para verlo' ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= (int)($h['exitosas'] ?? 0) ?></div>
    <div class="stat-label">Operaciones de hoy</div>
    <div class="stat-meta">de <?= (int)($h['cuantas'] ?? 0) ?> intentos</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::pesos($h['vendido'] ?? 0) ?></div>
    <div class="stat-label">Vendido hoy</div>
    <div class="stat-meta">comisión <?= D::pesos($h['comision'] ?? 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="lf-tile <?= $total ? '' : 'a' ?>"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= number_format($total) ?></div>
    <div class="stat-label">Productos en catálogo</div>
    <div class="stat-meta">
      <?= $actualizado ? 'al ' . date('d/m/y H:i', strtotime($actualizado)) : 'nunca se ha bajado' ?></div>
  </div>
</div>

<?php if (!$total): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-amb) 40%,transparent)">
  <div class="card-body" style="text-align:center;padding:30px">
    <h3 style="font-size:16px;margin-bottom:8px">Falta bajar el catálogo</h3>
    <p style="font-size:13px;color:var(--lf-tinta-3);max-width:470px;margin:0 auto 18px;line-height:1.6">
      Emida no vende "una recarga Telcel de $200": vende el producto <b>5077200</b>.
      Cada compañía y cada monto tienen su propio identificador, y sin el catálogo
      no se puede vender ninguno.
    </p>
    <form method="post" action="/recargas/catalogo">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <button class="btn btn-primary" type="submit">Bajar el catálogo ahora</button>
    </form>
  </div>
</section>
<?php endif; ?>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>Catálogo</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Elige el producto, no la compañía: cada monto es uno distinto</p></div>
      <form method="post" action="/recargas/catalogo" style="flex-shrink:0">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn btn-secondary btn-sm" type="submit" title="Volver a bajarlo del proveedor">
          <?= W::icono('reloj','14px') ?>Actualizar</button>
      </form>
    </header>

    <form class="lf-filtros" method="get" style="padding:0 20px">
      <div class="lf-search" style="max-width:260px">
        <?= W::icono('buscar','15px') ?>
        <input class="form-control form-control-sm" type="search" name="q"
               value="<?= P::e($q) ?>" placeholder="Nombre o ID del producto">
      </div>
      <select class="form-select form-select-sm" name="cat" style="width:auto">
        <option value="">Toda categoría</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= P::e($c['categoria']) ?>" <?= $cat===$c['categoria']?'selected':'' ?>>
            <?= P::e($c['categoria']) ?> (<?= (int)$c['n'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <select class="form-select form-select-sm" name="carrier" style="width:auto">
        <option value="">Todo proveedor</option>
        <?php foreach ($carriers as $c): ?>
          <option value="<?= P::e($c['carrier']) ?>" <?= $carrier===$c['carrier']?'selected':'' ?>>
            <?= P::e($c['carrier']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
      <?php if ($q || $cat || $carrier): ?>
        <a class="btn btn-secondary btn-sm" href="/recargas">Limpiar</a>
      <?php endif; ?>
    </form>

    <div class="lf-prods">
      <?php if (!$productos && $total): ?>
        <p style="grid-column:1/-1;text-align:center;color:var(--lf-tinta-4);
                  font-size:13px;padding:30px">Ningún producto coincide.</p>
      <?php endif; ?>
      <?php foreach ($productos as $p):
        $variable = (float)$p['monto'] <= 0; ?>
        <button type="button" class="lf-prod<?= $variable ? ' var' : '' ?>"
                data-id="<?= P::e($p['producto_id']) ?>"
                data-n="<?= P::e($p['nombre']) ?>"
                data-monto="<?= (float)$p['monto'] ?>"
                data-min="<?= (float)$p['monto_min'] ?>"
                data-max="<?= (float)$p['monto_max'] ?>"
                data-com="<?= (float)$p['comision'] ?>">
          <b><?= P::e($p['nombre']) ?></b>
          <small><?= P::e($p['carrier'] ?: $p['categoria']) ?></small>
          <span class="m"><?= $variable ? 'Monto variable' : D::pesos($p['monto']) ?></span>
          <?php if ((float)$p['comision'] > 0): ?>
            <span class="c">comisión <?= D::pesos($p['comision']) ?></span>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span><?= count($productos) ?> de <?= number_format($total) ?></span>
      <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
        'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
    </div>
  </section>

  <div>
    <form class="card" method="post" action="/recargas/vender" id="formVenta">
      <header class="card-header">Vender</header>
      <div class="card-body">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <input type="hidden" name="producto" id="prodId">

        <div id="sinProd" style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:24px 0">
          Elige un producto del catálogo.
        </div>

        <div id="conProd" hidden>
          <div style="padding:13px 15px;border-radius:var(--lf-r);background:var(--lf-brand-soft);
               margin-bottom:16px">
            <b id="prodNombre" style="display:block;font-size:13.5px;color:var(--lf-brand-2);
               line-height:1.4"></b>
            <small id="prodId2" style="font-size:11px;color:var(--lf-tinta-4);
               font-family:var(--lf-mono)"></small>
          </div>

          <label class="form-label" for="cuenta">Número o referencia</label>
          <input class="form-control lf-mono" id="cuenta" name="cuenta" inputmode="numeric"
                 placeholder="10 dígitos, o la referencia del recibo" required>
          <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:5px">
            Para recargas, el teléfono. Para pago de servicios, el número de cuenta
            que viene en el recibo.
          </p>

          <div id="campoMonto" style="margin-top:14px" hidden>
            <label class="form-label" for="monto">Monto a pagar</label>
            <input class="form-control lf-mono" id="monto" name="monto" type="number"
                   step="0.01" placeholder="0.00">
            <p id="limites" style="font-size:11px;color:var(--lf-tinta-4);margin-top:5px"></p>
          </div>

          <button class="btn btn-primary" type="submit" style="width:100%;margin-top:18px;padding:13px">
            <?= W::icono('cobro','15px') ?>Aplicar</button>

          <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:13px;line-height:1.55">
            <b>Una vez enviada no se puede cancelar:</b> el saldo ya llegó. Revisa el
            número antes de aplicar.
          </p>
        </div>
      </div>
    </form>

    <section class="card">
      <header class="card-header">El proveedor</header>
      <div class="card-body">
        <form method="post" action="/recargas/consultar">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <button class="btn btn-secondary btn-sm" type="submit" style="width:100%">
            <?= W::icono('reloj','14px') ?>Consultar saldo</button>
        </form>
        <form method="post" action="/recargas/probar" style="margin-top:8px">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <button class="btn btn-secondary btn-sm" type="submit" style="width:100%">
            <?= W::icono('alerta','14px') ?>Probar la conexión</button>
        </form>
        <?php if ($prueba): ?>
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--lf-linea)">
            <?php foreach ($prueba as $x):
              $col = $x['ok'] ? 'var(--lf-brand-2)'
                   : (empty($x['bloquea']) ? 'var(--lf-amb)' : 'var(--lf-rojo)'); ?>
              <div style="display:flex;gap:9px;align-items:flex-start;padding:5px 0;font-size:11.5px">
                <span style="flex-shrink:0;width:14px;color:<?= $col ?>;font-weight:700">
                  <?= $x['ok'] ? '✓' : (empty($x['bloquea']) ? '!' : '×') ?></span>
                <span style="flex:1;min-width:0">
                  <b style="font-weight:600;display:block"><?= P::e($x['que']) ?></b>
                  <span style="color:var(--lf-tinta-4);font-size:10.5px;overflow-wrap:anywhere;
                        line-height:1.45"><?= P::e(mb_substr($x['detalle'], 0, 260)) ?></span>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:12px;line-height:1.5">
          Las operaciones se pagan de un saldo precargado. Si se agota, se rechazan
          aunque el cliente ya te haya pagado.
        </p>
      </div>
    </section>
  </div>
</div>

<section class="card">
  <header class="card-header">
    <div><span>Operaciones de hoy</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Las fallidas también salen: es donde se ve si algo está mal</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Producto</th><th>Cuenta</th><th class="text-end">Monto</th>
        <th>Estado</th><th>Folio</th><th>Hora</th></tr></thead>
      <tbody>
      <?php if (!$transacciones): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:30px">
          Todavía no hay operaciones hoy.</td></tr>
      <?php endif; ?>
      <?php foreach ($transacciones as $t):
        $col = ['exitosa'=>'bg-success','fallida'=>'bg-danger',
                'incierta'=>'bg-warning','pendiente'=>'bg-secondary'][$t['estado']] ?? 'bg-secondary'; ?>
        <tr>
          <td data-label="Producto" style="font-size:12.5px">
            <b style="font-weight:600"><?= P::e($t['producto_nombre']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($t['producto_id']) ?></span></td>
          <td data-label="Cuenta" class="lf-mono" style="font-size:12.5px"><?= P::e($t['cuenta']) ?></td>
          <td data-label="Monto" class="text-end lf-mono"><?= D::pesos($t['monto']) ?></td>
          <td data-label="Estado">
            <span class="badge <?= $col ?>"><?= P::e(ucfirst($t['estado'])) ?></span>
            <?php if ($t['mensaje'] && $t['estado'] !== 'exitosa'): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11px;
                    max-width:230px;line-height:1.4"><?= P::e($t['mensaje']) ?></span>
            <?php endif; ?></td>
          <td data-label="Folio" class="lf-mono" style="font-size:11.5px">
            <?= P::e($t['folio_proveedor'] ?: '—') ?></td>
          <td data-label="Hora" class="lf-mono" style="font-size:12px">
            <?= date('H:i', strtotime($t['creado_en'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Una <b>incierta</b> es la que el proveedor no confirmó a tiempo: pudo haber
    salido. Consulta el saldo antes de reintentarla, nunca la repitas a ciegas.
  </div>
</section>

<script>
(function(){
  var sel = null;
  function elegir(b){
    document.querySelectorAll('.lf-prod').forEach(function(x){ x.classList.remove('on'); });
    b.classList.add('on');
    sel = b;
    document.getElementById('prodId').value = b.dataset.id;
    document.getElementById('prodNombre').textContent = b.dataset.n;
    document.getElementById('prodId2').textContent = 'ID ' + b.dataset.id
      + (parseFloat(b.dataset.com) > 0 ? ' · comisión $' + b.dataset.com : '');
    document.getElementById('sinProd').hidden = true;
    document.getElementById('conProd').hidden = false;

    // El monto solo se pide cuando el producto NO lo trae fijo. Pedirlo
    // siempre invita a escribir uno distinto al del producto, que es lo
    // que Emida rechaza con el código 51.
    var variable = parseFloat(b.dataset.monto) <= 0;
    var campo = document.getElementById('campoMonto'), m = document.getElementById('monto');
    campo.hidden = !variable;
    m.required = variable;
    if (variable) {
      var min = parseFloat(b.dataset.min), max = parseFloat(b.dataset.max), t = [];
      if (min > 0) { m.min = min; t.push('mínimo $' + min.toFixed(2)); }
      if (max > 0) { m.max = max; t.push('máximo $' + max.toFixed(2)); }
      document.getElementById('limites').textContent = t.join(' · ');
      m.value = '';
    }
    document.getElementById('cuenta').focus();
    if (window.innerWidth < 941) {
      document.getElementById('formVenta').scrollIntoView({behavior:'smooth', block:'start'});
    }
  }
  document.querySelectorAll('.lf-prod').forEach(function(b){
    b.addEventListener('click', function(){ elegir(b); });
  });

  document.getElementById('formVenta').addEventListener('submit', function(e){
    if (!sel) { e.preventDefault(); return; }
    var c = document.getElementById('cuenta').value.replace(/\D/g,'');
    if (!confirm('¿Aplicar "' + sel.dataset.n + '" a ' + c + '?\n\n'
      + 'Una vez enviada no se puede cancelar.')) e.preventDefault();
  });
})();
</script>
