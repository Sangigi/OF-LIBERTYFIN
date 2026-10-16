<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Servicio\Inventario as I;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$varias = count($sucursales) > 1;
$conU = function ($n, $u) { return I::conUnidad($n, $u); };
$estados = [0 => ['Agotado', 'bg-danger'], 1 => ['Bajo su mínimo', 'bg-warning'], 2 => ['Bien', 'bg-success']];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$activo): /* ═══════════ APAGADO ═══════════ */ ?>
<section class="card lf-inv-apagado">
  <div class="card-body">
    <span class="lf-tile g"><?= W::icono('bolsa','22px') ?></span>
    <h3>El inventario está apagado</h3>
    <p>Sirve para las empresas que venden productos físicos: lleva las existencias de cada sucursal,
      Caja las descuenta al cobrar y avisa cuando algo se está acabando. Si solo vendes servicios,
      déjalo así.</p>
    <?php if ($puedeAjustes): ?>
      <a class="btn btn-primary" href="/ajustes?t=inventario">Encenderlo en Ajustes</a>
    <?php else: ?>
      <p class="pista">Lo enciende un administrador en Ajustes → Inventario.</p>
    <?php endif; ?>
  </div>
</section>
<?php return; endif; ?>

<?php /* ═══════════ PESTAÑAS Y SUCURSAL ═══════════ */ ?>
<div class="lf-inv-cab">
  <div class="lf-pills" role="tablist">
    <a class="lf-pill <?= $pestana === 'existencias' ? 'active' : '' ?>" role="tab"
       href="/inventario?s=<?= (int)$sucursal ?>">Existencias</a>
    <a class="lf-pill <?= $pestana === 'movimientos' ? 'active' : '' ?>" role="tab"
       href="/inventario?t=movimientos&amp;s=<?= (int)$sucursal ?>">Movimientos</a>
    <?php if ($puedeEditar): ?>
    <a class="lf-pill <?= $pestana === 'conteo' ? 'active' : '' ?>" role="tab"
       href="/inventario?t=conteo&amp;s=<?= (int)$sucursal ?>">Conteo general</a>
    <?php endif; ?>
  </div>
  <?php if ($varias && $pestana !== 'movimientos'): ?>
    <div class="lf-segmento" aria-label="Sucursal">
      <?php foreach ($sucursales as $s): ?>
        <a class="lf-inv-suc<?= (int)$s['id'] === (int)$sucursal ? ' on' : '' ?>"
           href="/inventario?<?= $pestana === 'conteo' ? 't=conteo&amp;' : '' ?>s=<?= (int)$s['id'] ?>"><?= P::e($s['nombre']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($pestana === 'existencias'): /* ═══════════ EXISTENCIAS ═══════════ */ ?>

<div class="lf-stats">
  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('bolsa','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['productos']) ?></div>
    <div class="stat-label">Productos con existencias</div>
    <div class="stat-meta">los marcados en Productos</div></div>
  <div class="stat-card"><div class="lf-tile r"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['agotados']) ?></div>
    <div class="stat-label">Agotados</div>
    <div class="stat-meta">en cero o menos</div></div>
  <div class="stat-card"><div class="lf-tile a"><?= W::icono('baja','19px') ?></div>
    <div class="stat-value"><?= number_format($resumen['bajos']) ?></div>
    <div class="stat-label">Bajo su mínimo</div>
    <div class="stat-meta">hay que resurtir</div></div>
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value"><?= D::pesos($resumen['valor']) ?></div>
    <div class="stat-label">Valor a costo</div>
    <div class="stat-meta">lo que hay × su costo</div></div>
</div>

<?php if ($puedeEditar && $productos): $tipoSel = $mover ?: 'entrada'; ?>
<details class="lf-alta" id="invMover" <?= $mover ? 'open' : '' ?>>
  <summary><?= W::icono('mas','16px') ?> Registrar entrada, salida o conteo</summary>
  <form method="post" action="/inventario/mover" class="lf-form lf-inv-form" id="formMover">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if (!$varias): ?><input type="hidden" name="sucursal_id" value="<?= (int)$sucursal ?>"><?php endif; ?>

    <div class="lf-inv-tipos">
      <span class="form-label" id="etTipo">¿Qué pasó?</span>
      <div class="lf-segmento" role="radiogroup" aria-labelledby="etTipo">
        <?php foreach (['entrada' => 'Llegó mercancía', 'salida' => 'Salió sin venderse', 'conteo' => 'Lo conté']
                       + ($varias ? ['traspaso' => 'Pasó a otra sucursal'] : []) as $k => $t): ?>
          <label><input type="radio" name="tipo" value="<?= $k ?>" <?= $tipoSel === $k ? 'checked' : '' ?>><?= P::e($t) ?></label>
        <?php endforeach; ?>
      </div>
      <p class="pista" id="ayudaTipo"></p>
    </div>

    <div style="flex:2;min-width:220px">
      <label class="form-label" for="mProd">Producto</label>
      <select class="form-select" name="producto_id" id="mProd" required>
        <option value="">Elige…</option>
        <?php foreach ($productos as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-hay="<?= P::e(I::num($p['existencia'])) ?>"
                  data-u="<?= P::e(I::abrev($p['unidad_medida'])) ?>"
                  <?= (int)$producto === (int)$p['id'] ? 'selected' : '' ?>>
            <?= P::e($p['nombre']) ?> · hay <?= P::e($conU($p['existencia'], $p['unidad_medida'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($varias): ?>
    <div style="width:170px">
      <label class="form-label" for="mSuc">Sucursal</label>
      <select class="form-select" name="sucursal_id" id="mSuc">
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$sucursal ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($varias): ?>
    <div style="width:170px" data-ver-tipo="traspaso">
      <label class="form-label" for="mDest">A qué sucursal</label>
      <select class="form-select" name="destino_id" id="mDest">
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$sucursal ? 'disabled' : '' ?>><?= P::e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div style="width:140px">
      <label class="form-label" for="mCant" id="lblCant">Cuántas llegaron</label>
      <input class="form-control lf-mono" type="number" name="cantidad" id="mCant" step="0.001" min="0" required>
    </div>
    <div style="width:140px" data-ver-tipo="entrada">
      <label class="form-label" for="mCosto">Costo c/u <small>(opcional)</small></label>
      <input class="form-control lf-mono" type="number" name="costo" id="mCosto" step="0.01" min="0" placeholder="0.00">
    </div>
    <?php if ($proveedores): ?>
    <div style="width:190px" data-ver-tipo="entrada">
      <label class="form-label" for="mProv">Proveedor <small>(opcional)</small></label>
      <select class="form-select" name="proveedor_id" id="mProv">
        <option value="">—</option>
        <?php foreach ($proveedores as $pv): ?>
          <option value="<?= (int)$pv['id'] ?>"><?= P::e($pv['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div style="flex:1;min-width:200px">
      <label class="form-label" for="mNota" id="lblNota">Nota <small>(opcional)</small></label>
      <input class="form-control" name="nota" id="mNota" maxlength="255" placeholder="Factura, remisión…">
    </div>
    <div style="display:flex;flex-direction:row;align-items:center;gap:12px">
      <button class="btn btn-primary" type="submit">Registrar</button>
      <span class="lf-inv-queda" id="mQueda" aria-live="polite"></span>
    </div>
  </form>
</details>
<?php endif; ?>

<form class="lf-filtros" method="get" action="/inventario">
  <input type="hidden" name="s" value="<?= (int)$sucursal ?>">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Producto o código">
  </div>
  <label class="lf-inv-check"><input type="checkbox" name="bajos" value="1" <?= $bajos ? 'checked' : '' ?>
         onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"> Solo lo que falta</label>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover lf-inv-tabla">
      <thead><tr>
        <th>Producto</th><th class="text-end">Hay</th><th class="text-end">Mínimo</th><th>Estado</th>
        <?php if ($puedeEditar): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$listado): ?>
        <tr><td colspan="5" class="lf-inv-vacio">
          <?php if ($buscar !== '' || $bajos): ?>
            Nada coincide con el filtro.
          <?php else: ?>
            Ningún producto lleva existencias todavía.<br>
            En <a href="/servicios">Productos</a>, edita uno y marca <b>«Lleva existencias»</b>.
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($listado as $p): $e = $estados[(int)$p['estado']]; ?>
        <tr>
          <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($p['nombre']) ?></b>
            <small class="lf-inv-cod"><?= P::e($p['codigo']) ?></small></td>
          <td data-label="Hay" class="text-end lf-mono lf-inv-hay<?= (float)$p['existencia'] <= 0 ? ' mal' : '' ?>">
            <?= P::e($conU($p['existencia'], $p['unidad_medida'])) ?></td>
          <td data-label="Mínimo" class="text-end lf-mono"><?= (float)$p['minimo'] > 0 ? P::e(I::num($p['minimo'])) : '–' ?></td>
          <td data-label="Estado"><span class="badge <?= $e[1] ?>"><?= $e[0] ?></span></td>
          <?php if ($puedeEditar): ?>
          <td class="lf-inv-acc">
            <a class="btn btn-secondary btn-sm" href="/inventario?s=<?= (int)$sucursal ?>&amp;mover=entrada&amp;producto=<?= (int)$p['id'] ?>#invMover"><span class="lf-signo">+</span> Entrada</a>
            <a class="btn btn-secondary btn-sm" href="/inventario?s=<?= (int)$sucursal ?>&amp;mover=conteo&amp;producto=<?= (int)$p['id'] ?>#invMover">Contar</a>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><span>
    Caja descuenta las existencias al cobrar<?= $bloquea ? ' y no deja vender más de lo que hay' : '; si no alcanzan, avisa pero cobra' ?>.
    Cada cambio queda en <a href="/inventario?t=movimientos&amp;s=<?= (int)$sucursal ?>">Movimientos</a>.
  </span></div>
</section>

<?php if ($puedeEditar && $productos): ?>
<script>
/* Lo que cambia según «¿Qué pasó?»: etiquetas, campos y lo que quedará. */
(function(){
  var f = document.getElementById('formMover');
  if (!f) return;
  var AYUDA = {
    entrada: 'Compras o devoluciones de proveedor. Suma a lo que hay.',
    salida:  'Dañado, caducado, regalo o uso interno. Resta y pide el motivo.',
    conteo:  'Escribe cuántas hay de verdad: el sistema se ajusta a tu conteo y anota la diferencia.',
    traspaso:'Salen de esta sucursal y entran a la otra, en un solo paso.'
  };
  var CANT = { entrada: 'Cuántas llegaron', salida: 'Cuántas salen', conteo: 'Cuántas hay', traspaso: 'Cuántas pasan' };
  var prod = document.getElementById('mProd'), cant = document.getElementById('mCant'),
      nota = document.getElementById('mNota'), queda = document.getElementById('mQueda');
  function tipo(){ var r = f.querySelector('input[name="tipo"]:checked'); return r ? r.value : 'entrada'; }
  function num(n){ return (Math.round(n * 1000) / 1000).toLocaleString('es-MX', {maximumFractionDigits:3}); }
  function pintar(){
    var t = tipo();
    document.getElementById('ayudaTipo').textContent = AYUDA[t];
    document.getElementById('lblCant').textContent = CANT[t];
    f.querySelectorAll('[data-ver-tipo]').forEach(function(el){ el.hidden = el.dataset.verTipo !== t; });
    document.getElementById('lblNota').innerHTML = t === 'salida' ? 'Motivo' : 'Nota <small>(opcional)</small>';
    nota.required = t === 'salida';
    nota.placeholder = t === 'salida' ? 'Dañado, caducado, uso interno…' : (t === 'conteo' ? 'Inventario mensual…' : 'Factura, remisión…');
    // Lo que va a quedar, antes de registrar.
    var o = prod.options[prod.selectedIndex], c = parseFloat(cant.value);
    if (!o || !o.value || isNaN(c)) { queda.textContent = ''; return; }
    var hay = parseFloat(String(o.dataset.hay).replace(/,/g, '')) || 0, u = o.dataset.u ? ' ' + o.dataset.u : '';
    var fin = t === 'entrada' ? hay + c : ((t === 'salida' || t === 'traspaso') ? hay - c : c);
    queda.textContent = 'Hay ' + num(hay) + u + ' → quedarán ' + num(fin) + u;
    queda.classList.toggle('mal', fin < 0);
  }
  f.addEventListener('change', pintar);
  f.addEventListener('input', pintar);
  pintar();
  if (location.hash === '#invMover' && prod.value) cant.focus();
})();
</script>
<?php endif; ?>

<?php elseif ($pestana === 'conteo'): /* ═══════════ CONTEO GENERAL ═══════════ */ ?>

<section class="card">
  <div class="card-header"><span>
    <h3 class="card-title">Conteo general<?= $varias ? ' · ' . P::e($subtitulo) : '' ?></h3>
    <small class="lf-inv-sub">Cuenta lo que hay en el anaquel y escríbelo. Lo que dejes vacío no se toca.
      Al guardar, el sistema se ajusta a tu conteo y anota cada diferencia en Movimientos.</small>
  </span></div>
  <?php if (!$listado): ?>
    <div class="card-body lf-inv-vacio">Ningún producto lleva existencias todavía.</div>
  <?php else: ?>
  <form method="post" action="/inventario/conteo" id="formConteo">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="sucursal_id" value="<?= (int)$sucursal ?>">
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table lf-inv-tabla lf-conteo">
        <thead><tr><th>Producto</th><th class="text-end">El sistema dice</th><th class="text-end">Contaste</th><th class="text-end">Diferencia</th></tr></thead>
        <tbody>
        <?php foreach ($listado as $p): ?>
          <tr data-hay="<?= P::e(I::num($p['existencia'])) ?>">
            <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($p['nombre']) ?></b>
              <small class="lf-inv-cod"><?= P::e($p['codigo']) ?></small></td>
            <td data-label="Sistema" class="text-end lf-mono"><?= P::e($conU($p['existencia'], $p['unidad_medida'])) ?></td>
            <td data-label="Contaste" class="text-end">
              <input class="form-control form-control-sm lf-mono lf-conteo-in" type="number" step="0.001" min="0"
                     name="contado[<?= (int)$p['id'] ?>]" inputmode="decimal" aria-label="Contaste de <?= P::e($p['nombre']) ?>"></td>
            <td data-label="Diferencia" class="text-end lf-mono lf-conteo-dif">–</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer lf-conteo-pie">
      <input class="form-control" name="nota" maxlength="200" placeholder="Nota (opcional): inventario de fin de mes…" style="max-width:340px">
      <span id="conteoCuenta" class="pista">Nada capturado todavía.</span>
      <button class="btn btn-primary" type="submit">Guardar conteo</button>
    </div>
  </form>
  <script>
  (function(){
    var f = document.getElementById('formConteo');
    if (!f) return;
    function num(n){ return (Math.round(n * 1000) / 1000).toLocaleString('es-MX', {maximumFractionDigits:3}); }
    function pintar(){
      var n = 0, d = 0;
      f.querySelectorAll('tr[data-hay]').forEach(function(tr){
        var i = tr.querySelector('.lf-conteo-in'), c = tr.querySelector('.lf-conteo-dif');
        if (i.value === '') { c.textContent = '–'; c.className = 'text-end lf-mono lf-conteo-dif'; return; }
        var dif = (parseFloat(i.value) || 0) - (parseFloat(String(tr.dataset.hay).replace(/,/g, '')) || 0);
        n++; if (Math.abs(dif) >= 0.0005) d++;
        c.textContent = Math.abs(dif) < 0.0005 ? 'Cuadra' : (dif > 0 ? '+' : '−') + num(Math.abs(dif));
        c.className = 'text-end lf-mono lf-conteo-dif ' + (Math.abs(dif) < 0.0005 ? 'ok' : (dif > 0 ? 'mas' : 'menos'));
      });
      document.getElementById('conteoCuenta').textContent = n
        ? n + (n === 1 ? ' producto contado' : ' productos contados') + (d ? ' · ' + d + ' con diferencia' : ' · todo cuadra')
        : 'Nada capturado todavía.';
    }
    f.addEventListener('input', pintar);
    // Enter pasa al siguiente: se cuenta con el teclado, sin mouse.
    f.addEventListener('keydown', function(e){
      if (e.key !== 'Enter' || !e.target.classList.contains('lf-conteo-in')) return;
      e.preventDefault();
      var todos = [].slice.call(f.querySelectorAll('.lf-conteo-in')), i = todos.indexOf(e.target);
      if (todos[i + 1]) todos[i + 1].focus();
    });
    f.addEventListener('submit', function(e){
      var n = [].filter.call(f.querySelectorAll('.lf-conteo-in'), function(i){ return i.value !== ''; }).length;
      if (!n) { e.preventDefault(); alert('Captura al menos un conteo.'); return; }
      if (!confirm('¿Guardar el conteo de ' + n + (n === 1 ? ' producto' : ' productos') + '? Las existencias se ajustan a lo que contaste.')) e.preventDefault();
    });
  })();
  </script>
  <?php endif; ?>
</section>

<?php else: /* ═══════════ MOVIMIENTOS ═══════════ */ ?>

<form class="lf-filtros" method="get" action="/inventario">
  <input type="hidden" name="t" value="movimientos">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($filtros['q']) ?>" placeholder="Producto o código">
  </div>
  <select class="form-select form-select-sm" name="tipo" style="width:auto" aria-label="Tipo de movimiento">
    <option value="">Todos los movimientos</option>
    <?php foreach (I::TIPOS as $k => $t): ?>
      <option value="<?= $k ?>" <?= $filtros['tipo'] === $k ? 'selected' : '' ?>><?= P::e($t) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($varias): ?>
  <select class="form-select form-select-sm" name="s" style="width:auto" aria-label="Sucursal">
    <option value="0" <?= !$filtros['sucursal'] ? 'selected' : '' ?>>Todas las sucursales</option>
    <?php foreach ($sucursales as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= (int)$filtros['sucursal'] === (int)$s['id'] ? 'selected' : '' ?>><?= P::e($s['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php else: ?><input type="hidden" name="s" value="<?= (int)$sucursal ?>"><?php endif; ?>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($filtros['desde']) ?>" style="width:auto" aria-label="Desde">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($filtros['hasta']) ?>" style="width:auto" aria-label="Hasta">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<section class="card">
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover lf-inv-tabla">
      <thead><tr>
        <th>Cuándo</th><th>Producto</th><th>Movimiento</th>
        <th class="text-end">Cantidad</th><th class="text-end">Quedó</th><th>Detalle</th>
      </tr></thead>
      <tbody>
      <?php if (!$movimientos): ?>
        <tr><td colspan="6" class="lf-inv-vacio">No hay movimientos en estas fechas.</td></tr>
      <?php endif; ?>
      <?php foreach ($movimientos as $m): $c = (float)$m['cantidad']; ?>
        <tr>
          <td data-label="Cuándo" class="lf-inv-fecha"><?= P::e(date('d/m/Y H:i', strtotime($m['creado_en']))) ?>
            <small><?= P::e($m['usuario_nombre'] ?: '—') ?><?= $varias && !$filtros['sucursal'] ? ' · ' . P::e($m['sucursal']) : '' ?></small></td>
          <td data-label="Producto"><b class="lf-inv-nom"><?= P::e($m['producto'] ?: '(ya no existe)') ?></b></td>
          <td data-label="Movimiento"><span class="lf-inv-tipo t-<?= P::e($m['tipo']) ?>"><?= P::e(I::TIPOS[$m['tipo']] ?? $m['tipo']) ?></span></td>
          <td data-label="Cantidad" class="text-end lf-mono lf-inv-cant <?= $c > 0 ? 'mas' : ($c < 0 ? 'menos' : '') ?>">
            <?= $c > 0 ? '+' : ($c < 0 ? '−' : '') ?><?= P::e(I::num(abs($c))) ?></td>
          <td data-label="Quedó" class="text-end lf-mono"><?= P::e($conU($m['existencia'], $m['unidad_medida'])) ?></td>
          <td data-label="Detalle" class="lf-inv-det">
            <?php if ($m['venta_id']): ?>
              <a href="/ventas/<?= (int)$m['venta_id'] ?>">Venta <?= P::e($m['codigo_venta'] ?: '#' . $m['venta_id']) ?></a>
            <?php endif; ?>
            <?php if ($m['proveedor']): ?><span><?= P::e($m['proveedor']) ?></span><?php endif; ?>
            <?php if ($m['costo'] !== null): ?><span><?= D::pesos($m['costo']) ?> c/u</span><?php endif; ?>
            <?php if ($m['nota']): ?><span><?= P::e($m['nota']) ?></span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($movimientos) >= 300): ?>
    <div class="card-footer">Se muestran los 300 más recientes. Acorta las fechas para ver los demás.</div>
  <?php endif; ?>
</section>

<?php endif; ?>
