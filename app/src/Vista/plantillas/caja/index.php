<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo'] === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="lf-pos">
  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
      <span>Servicios</span>
      <div class="lf-pag-serv" id="pagServ" hidden>
        <button type="button" id="servAnt" aria-label="Anterior">&lsaquo;</button>
        <span><b id="servPag">1</b> de <b id="servTot">1</b></span>
        <button type="button" id="servSig" aria-label="Siguiente">&rsaquo;</button>
      </div>
      <form class="lf-search" method="get" style="max-width:240px">
        <?= W::icono('buscar','15px') ?>
        <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Nombre o código">
        <?php if ($area): ?><input type="hidden" name="area" value="<?= P::e($area) ?>"><?php endif; ?>
      </form>
    </header>

    <div class="lf-cat-fila">
      <a class="lf-pill <?= $area === '' ? 'active' : '' ?>" href="/caja<?= $buscar ? '?q='.urlencode($buscar) : '' ?>">Todos</a>
      <?php foreach ($areas as $a): ?>
        <a class="lf-pill <?= (string)$area === (string)$a['id'] ? 'active' : '' ?>"
           href="/caja?area=<?= (int)$a['id'] ?><?= $buscar ? '&q='.urlencode($buscar) : '' ?>">
          <?= P::e($a['nombre']) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="lf-grid-serv">
      <?php if (!$servicios): ?>
        <p style="grid-column:1/-1;text-align:center;color:var(--lf-tinta-4);padding:30px;font-size:13px">
          No hay servicios que coincidan.</p>
      <?php endif; ?>
      <?php foreach ($servicios as $s): ?>
        <button type="button" class="lf-serv<?= (float)$s['precio'] <= 0 ? ' sin-precio' : '' ?>"
                data-id="<?= (int)$s['id'] ?>"
                data-nombre="<?= P::e($s['nombre']) ?>"
                data-precio="<?= (float)$s['precio'] ?>">
          <span class="e"><?= W::icono('serv','17px') ?></span>
          <b><?= P::e($s['nombre']) ?></b>
          <small><?= P::e($s['codigo']) ?></small>
          <span class="p"><?= (float)$s['precio'] > 0 ? D::pesos($s['precio']) : 'Precio libre' ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </section>

  <form class="card" method="post" action="/caja/cobrar" id="ticket" style="align-self:start">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="lineas" id="lineas" value="[]">
    <input type="hidden" name="cliente_id" id="cliente_id" value="">

    <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
      <span>Ticket</span>
      <span class="badge bg-secondary" id="conteo">0 conceptos</span>
    </header>

    <div style="padding:0 20px 12px">
      <div class="lf-search">
        <?= W::icono('cliente','15px') ?>
        <input type="text" id="buscaCliente" placeholder="Cliente (opcional)" autocomplete="off">
      </div>
      <div id="sugerencias" class="lf-sugerencias" hidden></div>
    </div>

    <div id="lista"></div>
    <p id="vacio" style="padding:26px 20px;text-align:center;color:var(--lf-tinta-4);font-size:13px">
      Toca un servicio para agregarlo.</p>

    <div class="lf-sumas">
      <div><span>Subtotal</span><span class="lf-mono" id="sSub">$0.00</span></div>
      <div style="align-items:center">
        <span style="display:flex;align-items:center;gap:7px">IVA
          <input class="form-control form-control-sm lf-mono" type="number" name="iva_pct" id="ivaPct"
                 value="0" min="0" max="100" step="0.01" style="width:68px;padding:4px 7px">%
        </span>
        <span class="lf-mono" id="sIva">$0.00</span>
      </div>
      <div><span>Gastos de operación</span>
        <span style="display:flex;align-items:center;gap:6px">−
          <input class="form-control form-control-sm lf-mono" type="number" name="gastos" id="gastos"
                 value="0" min="0" step="0.01" style="width:104px;padding:4px 7px">
        </span></div>
      <div class="tt"><span>Total</span><span id="sTot">$0.00</span></div>
    </div>

    <div class="lf-anticipo">
      <label for="anticipo">Anticipo que se cobra hoy</label>
      <input class="lf-mono" type="number" name="anticipo" id="anticipo" value="0" min="0" step="0.01">
      <p id="msgSaldo">Deja el total para liquidar de una vez.</p>
    </div>

    <div style="padding:0 20px 14px;display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach (['efectivo'=>'Efectivo','transferencia'=>'Transferencia','tarjeta'=>'Tarjeta'] as $k=>$v): ?>
        <label class="lf-pill<?= $k==='efectivo'?' active':'' ?>" style="cursor:pointer">
          <input type="radio" name="metodo" value="<?= $k ?>" <?= $k==='efectivo'?'checked':'' ?> hidden>
          <?= $v ?>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="padding:0 20px 14px">
      <label class="form-label">Descripción de la venta</label>
      <textarea class="form-control lf-desc" name="descripcion" rows="3"
                placeholder="Qué se vendió, condiciones, referencias… (opcional)"></textarea>
    </div>

    <button class="btn btn-primary" type="submit" id="btnCobrar" disabled
            style="width:calc(100% - 40px);margin:0 20px 20px;padding:14px">
      <?= W::icono('cobro','16px') ?><span id="btnTexto">Cobrar</span>
    </button>
  </form>
</div>

<script>
(function(){
  var lineas = [];
  var $ = function(id){ return document.getElementById(id); };
  var pesos = function(n){ return '$' + (Math.round(n*100)/100).toLocaleString('es-MX',
                     {minimumFractionDigits:2, maximumFractionDigits:2}); };

  function pintar(){
    var lista = $('lista'); lista.innerHTML = '';
    lineas.forEach(function(l, i){
      var d = document.createElement('div');
      d.className = 'lf-linea';
      // El precio es editable: muchos servicios se cotizan por caso y el
      // catálogo los tiene en cero a propósito.
      d.innerHTML = '<div style="flex:1;min-width:0"><b>' + l.nombre + '</b>'
        + '<small>cantidad ' + l.cantidad + '</small></div>'
        + '<input class="lf-precio lf-mono" type="number" step="0.01" min="0" '
        +   'data-i="' + i + '" value="' + l.precio.toFixed(2) + '" aria-label="Precio">'
        + '<button type="button" class="lf-quitar" data-i="' + i + '" aria-label="Quitar">&times;</button>';
      lista.appendChild(d);
    });
    $('vacio').hidden = lineas.length > 0;
    $('conteo').textContent = lineas.length + ' concepto' + (lineas.length===1?'':'s');
    $('lineas').value = JSON.stringify(lineas.map(function(l){
      return {id:l.id, cantidad:l.cantidad, precio:l.precio}; }));
    calcular();
  }

  function calcular(){
    var cap = lineas.reduce(function(a,l){ return a + l.precio*l.cantidad; }, 0);
    var pct = parseFloat($('ivaPct').value) || 0;
    // IVA incluido: el total es lo capturado y el impuesto se extrae.
    var base = pct > 0 ? cap / (1 + pct/100) : cap;
    var iva  = cap - base;
    $('sSub').textContent = pesos(base);
    $('sIva').textContent = pesos(iva);
    $('sTot').textContent = pesos(cap);

    var ant = parseFloat($('anticipo').value) || 0;
    if (ant > cap) { $('anticipo').value = cap.toFixed(2); ant = cap; }
    var saldo = cap - ant;
    $('msgSaldo').innerHTML = saldo <= 0.009
      ? 'Se liquida completa. La comisión se libera toda.'
      : 'Queda un saldo de <b class="lf-mono" style="color:var(--lf-amb)">' + pesos(saldo)
        + '</b>. La comisión se libera conforme el cliente pague.';
    $('btnTexto').textContent = ant > 0 ? 'Cobrar ' + pesos(ant) : 'Registrar sin cobro';
    $('btnCobrar').disabled = lineas.length === 0;
  }

  // Paginación de la rejilla: 21 por página, sin recargar. Con el catálogo
  // completo a la vista la columna crece tanto que el ticket queda perdido
  // al fondo de la pantalla.
  (function(){
    var POR_PAG = 21;
    var tarjetas = Array.prototype.slice.call(document.querySelectorAll('.lf-serv'));
    var totalPag = Math.ceil(tarjetas.length / POR_PAG) || 1;
    var actual = 1;
    var caja = $('pagServ');
    if (totalPag <= 1) { if (caja) caja.hidden = true; return; }
    caja.hidden = false;
    $('servTot').textContent = totalPag;
    function pinta(){
      tarjetas.forEach(function(t, i){
        t.style.display = (i >= (actual-1)*POR_PAG && i < actual*POR_PAG) ? '' : 'none';
      });
      $('servPag').textContent = actual;
      $('servAnt').disabled = actual === 1;
      $('servSig').disabled = actual === totalPag;
    }
    $('servAnt').addEventListener('click', function(){ if (actual>1){ actual--; pinta(); } });
    $('servSig').addEventListener('click', function(){ if (actual<totalPag){ actual++; pinta(); } });
    pinta();
  })();

  document.querySelectorAll('.lf-serv').forEach(function(b){
    b.addEventListener('click', function(){
      var id = +b.dataset.id;
      var y = lineas.filter(function(l){ return l.id === id; })[0];
      if (y) y.cantidad++;
      else lineas.push({id:id, nombre:b.dataset.nombre, precio:+b.dataset.precio, cantidad:1});
      pintar();
    });
  });

  $('lista').addEventListener('click', function(e){
    var b = e.target.closest('.lf-quitar');
    if (!b) return;
    lineas.splice(+b.dataset.i, 1);
    pintar();
  });

  // Cambiar el precio no repinta la lista: perdería el foco a media escritura.
  $('lista').addEventListener('input', function(e){
    var i = e.target.closest('.lf-precio');
    if (!i) return;
    lineas[+i.dataset.i].precio = Math.max(0, parseFloat(i.value) || 0);
    calcular();
  });

  ['ivaPct','anticipo','gastos'].forEach(function(id){
    $(id).addEventListener('input', calcular);
  });

  document.querySelectorAll('input[name="metodo"]').forEach(function(r){
    r.addEventListener('change', function(){
      document.querySelectorAll('input[name="metodo"]').forEach(function(o){
        o.parentNode.classList.toggle('active', o.checked); });
    });
  });

  // Cliente
  var t = null;
  $('buscaCliente').addEventListener('input', function(){
    var q = this.value.trim();
    $('cliente_id').value = '';
    clearTimeout(t);
    if (q.length < 2) { $('sugerencias').hidden = true; return; }
    t = setTimeout(function(){
      fetch('/caja/clientes?q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(cs){
          var s = $('sugerencias');
          s.innerHTML = '';
          cs.forEach(function(c){
            var d = document.createElement('button');
            d.type = 'button';
            d.innerHTML = '<b>' + c.nombre + '</b><small>' + (c.compras>0
              ? c.compras + ' compra' + (c.compras==1?'':'s') : 'Cliente nuevo') + '</small>';
            d.addEventListener('click', function(){
              $('cliente_id').value = c.id;
              $('buscaCliente').value = c.nombre;
              s.hidden = true;
            });
            s.appendChild(d);
          });
          s.hidden = cs.length === 0;
        });
    }, 220);
  });

  pintar();
})();
</script>
