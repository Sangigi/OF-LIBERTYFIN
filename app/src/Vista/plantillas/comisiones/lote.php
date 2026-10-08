<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];

// Por qué una venta no se puede comisionar en lote, sin importar a quién.
// Las que dependen de a quién se elija (ya tiene comisión de esa persona,
// no tiene especialista) las marca el script al cambiar la elección.
$motivoFijo = function ($v) {
    return $v['lineas'] ? '' : 'No tiene productos';
};

$listas  = array_filter($ventas, function ($v) use ($motivoFijo) { return $motivoFijo($v) === ''; });
$volver  = '/comisiones?' . http_build_query(['desde' => $desde, 'hasta' => $hasta]);
$hayVarios = (bool)array_filter($ventas, function ($v) { return count($v['lineas']) > 1; });

// Para el "En" de cada persona: las áreas y los productos que hay en esta
// lista. Así "Ana en Contabilidad, Juan en Legal" sale en una sola pasada.
$alcAreas = []; $alcProds = [];
foreach ($ventas as $v) {
    foreach ($v['lineas'] as $l) {
        $alcAreas[mb_strtolower(trim($l['area']))] = trim($l['area']);
        if ($l['producto_id'] > 0) $alcProds[$l['producto_id']] = $l['producto'];
    }
}
asort($alcAreas); asort($alcProds);
$clave = function ($area) { return mb_strtolower(trim((string)$area)); };
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($resultado): $om = $resultado['omitidas']; ?>
<section class="card lf-lote-res">
  <header class="card-header">
    <div>
      <?php if ($resultado['hechas'] > 0): ?>
        <span><?= (int)$resultado['hechas'] ?> comisi<?= $resultado['hechas'] === 1 ? 'ón asignada' : 'ones asignadas' ?><?php
          if (($resultado['ventas'] ?? $resultado['hechas']) !== $resultado['hechas']): ?>
          en <?= (int)$resultado['ventas'] ?> venta<?= $resultado['ventas'] === 1 ? '' : 's' ?><?php endif; ?></span>
        <p>Suman <b><?= D::pesos($resultado['monto']) ?></b> · <?= P::e($resultado['reparto'] ?? '') ?>.
          Ya se ven en Comisiones y en los reportes.</p>
      <?php else: ?>
        <span>No se asignó ninguna comisión</span>
        <p>Ninguna de las ventas marcadas cumplía las reglas. Abajo está el motivo de cada una.</p>
      <?php endif; ?>
    </div>
    <?php if ($om): ?><span class="badge bg-warning"><?= count($om) ?> sin asignar</span><?php endif; ?>
  </header>
  <?php if ($om): ?>
  <ul class="lf-lote-omit">
    <?php foreach (array_slice($om, 0, 50) as $o): ?>
      <li><b class="lf-mono"><?= P::e($o[0]) ?></b><span><?= P::e($o[1]) ?></span></li>
    <?php endforeach; ?>
    <?php if (count($om) > 50): ?><li class="mas">y <?= count($om) - 50 ?> más</li><?php endif; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<a class="lf-lote-atras" href="<?= P::e($volver) ?>">&larr; Volver a Comisiones</a>

<?php /* ═══════════ 1 · QUÉ VENTAS ═══════════ */ ?>
<section class="card">
  <header class="card-header">
    <div><span><b class="lf-lote-paso">1</b>Qué ventas</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Por fecha de venta, igual que en Comisiones</p></div>
  </header>
  <form class="lf-lote-filtros" method="get" action="/comisiones/lote">
    <label>Desde<input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>"></label>
    <label>Hasta<input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>"></label>
    <label>Área
      <select class="form-select form-select-sm" name="area">
        <option value="">Todas</option>
        <?php foreach ($areas as $a): ?>
          <option value="<?= P::e($a['nombre']) ?>" <?= mb_strtolower($a['nombre']) === mb_strtolower($area) ? 'selected' : '' ?>><?= P::e($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Especialista
      <select class="form-select form-select-sm" name="esp">
        <option value="">Cualquiera</option>
        <option value="sin" <?= $esp === 'sin' ? 'selected' : '' ?>>Sin especialista</option>
        <?php foreach ($equipo as $nomArea => $gente): ?>
          <optgroup label="<?= P::e($nomArea) ?>">
            <?php foreach ($gente as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (string)(int)$c['id'] === $esp ? 'selected' : '' ?>><?= P::e($c['nombre']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="lf-lote-chk">
      <input type="hidden" name="solo" value="0">
      <input type="checkbox" name="solo" value="1" <?= $solo ? 'checked' : '' ?>> Solo las que no tienen comisión
    </label>
    <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
  </form>
</section>

<?php if (!$equipo): ?>
<div class="alert alert-warning">
  <?= W::icono('alerta','18px') ?><span>Todavía no hay colaboradores activos. Dalos de alta en Ajustes para poder asignarles comisiones.</span>
</div>
<?php else: ?>

<form method="post" action="/comisiones/lote" id="loteForm">
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="desde" value="<?= P::e($desde) ?>">
  <input type="hidden" name="hasta" value="<?= P::e($hasta) ?>">
  <input type="hidden" name="area"  value="<?= P::e($area) ?>">
  <input type="hidden" name="esp"   value="<?= P::e($esp) ?>">
  <input type="hidden" name="solo"  value="<?= $solo ? '1' : '0' ?>">

  <?php /* ═══════════ 2 · A QUIÉN Y CUÁNTO ═══════════ */ ?>
  <section class="card">
    <header class="card-header">
      <div><span><b class="lf-lote-paso">2</b>A quién y cuánto</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Cada quien con su porcentaje y en qué productos, sobre la base comisionable de cada uno (sin IVA, sin costo y sin gastos), igual que al asignar desde la venta</p></div>
    </header>
    <div class="lf-lote-quienes" id="loteQuienes">
      <div class="lf-lote-quien" data-fila>
        <label class="quien">Colaborador
          <select class="form-select" name="colaborador[]" required>
            <option value="">Elegir…</option>
            <option value="esp">Al especialista de cada venta</option>
            <?php foreach ($equipo as $nomArea => $gente): ?>
              <optgroup label="<?= P::e($nomArea) ?>">
                <?php foreach ($gente as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"
                          data-pct="<?= isset($sugeridos[$c['id']]) ? P::e(rtrim(rtrim(number_format($sugeridos[$c['id']], 2, '.', ''), '0'), '.')) : '' ?>"><?= P::e($c['nombre']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="pct">Porcentaje
          <span class="lf-lote-pct">
            <input class="form-control" type="number" name="porcentaje[]"
                   min="0.01" max="100" step="0.01" inputmode="decimal" placeholder="10" required>
            <i>%</i>
          </span>
        </label>
        <label class="en">En
          <select class="form-select" name="en[]">
            <option value="">Todos los productos marcados</option>
            <?php if (count($alcAreas) > 1): ?>
              <optgroup label="Solo el área">
                <?php foreach ($alcAreas as $a): ?>
                  <option value="a:<?= P::e($a) ?>"><?= P::e($a) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
            <?php if (count($alcProds) > 1): ?>
              <optgroup label="Solo el producto">
                <?php foreach ($alcProds as $pid => $pn): ?>
                  <option value="p:<?= (int)$pid ?>"><?= P::e($pn) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
        </label>
        <button type="button" class="lf-lote-quitar" data-quitar aria-label="Quitar este colaborador" hidden>&times;</button>
        <?php /* Clave de la fila: con ella cada venta dice a quiénes de aquí lleva. */ ?>
        <input type="hidden" name="fila[]" value="1">
      </div>
    </div>
    <div class="lf-lote-mas">
      <button type="button" class="btn btn-secondary btn-sm" id="loteMas">+ Agregar colaborador</button>
      <span id="loteSuma"></span>
    </div>
  </section>

  <?php /* ═══════════ 3 · REVISAR Y ASIGNAR ═══════════ */ ?>
  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div><span><b class="lf-lote-paso">3</b>Revisa y asigna</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          <?= count($ventas) ?> venta<?= count($ventas) === 1 ? '' : 's' ?> en la búsqueda
          · las que no se pueden salen sin marcar y con el motivo
          <?php if ($hayVarios): ?>
            · con varios productos, marca a cuáles va la comisión (vienen marcados <?= $area !== '' ? 'los de ' . P::e($area) : 'todos' ?>); cada uno sobre su propia base
          <?php endif; ?></p></div>
      <span class="badge bg-secondary" id="loteCuenta"><?= count($listas) ?> marcadas</span>
    </header>

    <?php if ($cortado): ?>
      <p class="lf-lote-tope">Se muestran las primeras <?= (int)$tope ?>. Acorta el periodo o filtra por área para ver las demás.</p>
    <?php endif; ?>

    <?php if (!$ventas): ?>
      <p class="lf-lote-vacio">No hay ventas con esos filtros<?= $solo ? ' que no tengan comisión' : '' ?>.</p>
    <?php else: ?>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover lf-lote-tabla">
        <thead><tr>
          <th style="width:34px"><input type="checkbox" id="loteTodas" aria-label="Marcar todas" checked></th>
          <th>Venta</th><th>Área</th><th>Especialista</th>
          <th class="text-end">Total</th><th>Comisiones</th><th>Estado</th>
        </tr></thead>
        <tbody>
        <?php foreach ($ventas as $v):
          $fijo  = $motivoFijo($v);
          $nLin  = count($v['lineas']); ?>
          <tr data-n="<?= $nLin ?>" data-esp="<?= (int)$v['especialista_id'] ?>"
              <?php /* Con un solo producto no hay qué elegir: quién ya comisiona va aquí. */ ?>
              <?php if ($nLin === 1): $l1 = $v['lineas'][0]; ?>data-con="<?= P::e($l1['con']) ?>"
                data-linea="<?= (int)$l1['id'] ?>" data-area="<?= P::e($clave($l1['area'])) ?>"
                data-pid="<?= (int)$l1['producto_id'] ?>"<?php endif; ?>
              data-fijo="<?= P::e($fijo) ?>" class="<?= $fijo ? 'no' : '' ?>">
            <td data-label="Incluir">
              <input type="checkbox" name="ventas[]" value="<?= (int)$v['id'] ?>"
                     aria-label="Incluir <?= P::e($v['codigo_venta']) ?>"
                     <?= $fijo ? 'disabled' : 'checked' ?>>
            </td>
            <td data-label="Venta">
              <a href="/ventas/<?= (int)$v['id'] ?>" data-modal style="font-weight:600;color:var(--lf-tinta)">
                <?= P::e($v['cliente'] ?: 'Público general') ?></a>
              <span class="lf-lote-sub"><?= P::e($v['codigo_venta']) ?> · <?= date('d/m/Y', strtotime($v['fecha'])) ?>
                <?php if ($nLin === 1): ?> · <?= P::e($v['lineas'][0]['producto']) ?><?php endif; ?></span>
              <?php if ($nLin > 1): ?>
                <?php /* A qué productos va la comisión. Vienen marcados todos, o
                         los del área si se filtró por área; se puede cambiar.
                         El "0" oculto avisa que en esta venta se eligió, aunque
                         se desmarquen todos. */ ?>
                <input type="hidden" name="lineas[<?= (int)$v['id'] ?>][]" value="0">
                <ul class="lf-lote-prods">
                  <?php foreach ($v['lineas'] as $l): $fuera = !in_array($l['id'], $v['objetivo'], true); ?>
                    <li class="<?= $fuera ? 'fuera' : '' ?>">
                      <label>
                        <input type="checkbox" data-prod name="lineas[<?= (int)$v['id'] ?>][]"
                               value="<?= (int)$l['id'] ?>" data-con="<?= P::e($l['con']) ?>"
                               data-area="<?= P::e($clave($l['area'])) ?>" data-pid="<?= (int)$l['producto_id'] ?>"
                               <?= $fuera ? '' : 'checked' ?>>
                        <span><?= P::e($l['producto']) ?>
                          <small><?= P::e($l['area']) ?><?= $fuera ? ' · otra área' : '' ?><?=
                            $l['comisiones'] !== '' ? ' · ' . P::e($l['comisiones']) : '' ?></small></span>
                      </label>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </td>
            <td data-label="Área"><span class="badge bg-secondary"><?= P::e($v['area']) ?></span></td>
            <td data-label="Especialista"><?= $v['especialista'] !== '' ? P::e($v['especialista'])
                : '<span class="lf-lote-sub" style="display:inline">Sin especialista</span>' ?></td>
            <td data-label="Total" class="text-end lf-mono"><?= D::pesos($v['total']) ?></td>
            <td data-label="Comisiones"><?= $v['comisiones'] ? P::e($v['comisiones'])
                : '<span class="lf-lote-sub" style="display:inline">Ninguna</span>' ?></td>
            <td data-label="Estado">
              <div class="lf-lote-estado">
                <span class="lf-lote-motivo"><?= $fijo ? P::e($fijo) : 'Lista' ?></span>
                <?php /* Con dos o más personas, a quiénes lleva esta venta (lo pinta el script). */ ?>
                <div class="lf-lote-para" hidden></div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <footer class="lf-lote-pie">
      <p id="loteResumen">Marca las ventas, elige a quién y el porcentaje.</p>
      <button class="btn btn-primary" type="submit" id="loteOk">Asignar comisiones</button>
    </footer>
    <?php endif; ?>
  </section>
</form>

<div class="lf-modal" id="loteConf" hidden>
  <div class="caja" role="dialog" aria-modal="true" aria-labelledby="loteConfT">
    <header>
      <div>
        <h2 id="loteConfT">¿Asignar estas comisiones?</h2>
        <p>Se asignan una por una con las mismas reglas que desde la venta. Si alguna no se puede, las demás sí quedan.</p>
      </div>
      <button type="button" class="cerrar" data-lote-cerrar aria-label="Cerrar">&times;</button>
    </header>
    <div class="cuerpo"><dl class="lf-conf-datos" id="loteConfD"></dl></div>
    <footer>
      <button type="button" class="btn btn-secondary" data-lote-cerrar>Revisar</button>
      <button type="button" class="btn btn-primary" id="loteConfOk">Sí, asignar</button>
    </footer>
  </div>
</div>
<?php endif; ?>

<script>
/* Asignar en lote.
   Varias personas a la vez, cada una con su porcentaje. Al elegirlas,
   cada fila se revisa contra esa elección: lo que una persona ya
   comisiona no se le vuelve a dar, y "al especialista" no aplica en las
   ventas que no tienen. Si a nadie le queda nada en una venta, se
   desmarca y dice por qué. Así lo que se ve marcado es lo que de verdad
   se va a asignar.
   La página se reemplaza al navegar sin recargar y este script vuelve a
   correr: todo se engancha a los elementos de esta página, que son
   nuevos cada vez. */
(function () {
  var form = document.getElementById('loteForm');
  if (!form) return;
  var caja   = document.getElementById('loteQuienes');
  var mas    = document.getElementById('loteMas');
  var suma   = document.getElementById('loteSuma');
  var todas  = document.getElementById('loteTodas');
  var cuenta = document.getElementById('loteCuenta');
  var resum  = document.getElementById('loteResumen');
  var conf   = document.getElementById('loteConf');
  var filas  = [].slice.call(form.querySelectorAll('tbody tr'));
  var MAX    = 10;
  var confirmado = false;
  // venta:clave -> true cuando a esa persona se le quitó esa venta.
  var apagado = {};
  // Clave de la siguiente fila de "quiénes". No se reutiliza al quitar una
  // fila, para que un chip apagado no pase a otra persona.
  var sig = 1;
  [].forEach.call(document.querySelectorAll('#loteQuienes input[name="fila[]"]'), function (i) {
    sig = Math.max(sig, +i.value || 0);
  });

  /* ── Quiénes ── */
  function renglones() { return [].slice.call(caja.querySelectorAll('[data-fila]')); }

  /* [{q: 'esp' | id, pct, nombre, en, enTexto}] de las filas con alguien
     elegido. `en` es '' (todos), 'a:Área' o 'p:id de producto'. */
  function quienes() {
    return renglones().map(function (r) {
      var s = r.querySelector('select[name="colaborador[]"]'), p = r.querySelector('input'),
          e = r.querySelector('select[name="en[]"]');
      return { q: s.value, pct: parseFloat(p.value),
               k: r.querySelector('input[name="fila[]"]').value,
               nombre: s.value ? s.options[s.selectedIndex].text : '',
               en: e.value, enTexto: e.value ? e.options[e.selectedIndex].text : '' };
    }).filter(function (x) { return x.q; });
  }

  function pintarRenglones() {
    var rs = renglones(), vistos = {}, total = 0, todosEnTodo = true;
    rs.forEach(function (r) {
      var s = r.querySelector('select[name="colaborador[]"]'), e = r.querySelector('select[name="en[]"]');
      var p = parseFloat(r.querySelector('input').value);
      r.querySelector('[data-quitar]').hidden = rs.length < 2;
      // La misma persona en lo mismo dos veces es un error de captura. En
      // cosas distintas sí se vale: Juan 10% en Legal y 5% en Contabilidad.
      var k = s.value + '|' + e.value;
      s.setCustomValidity(s.value && vistos[k] ? 'Ya está en otra fila con lo mismo' : '');
      if (s.value) vistos[k] = true;
      if (e.value) todosEnTodo = false;
      if (p > 0) total += p;
    });
    mas.disabled = rs.length >= MAX;
    total = Math.round(total * 100) / 100;
    // La suma solo dice algo si todos van a todo; si no, se revisa
    // producto por producto en la lista.
    suma.textContent = rs.length > 1 && total && todosEnTodo
      ? 'Suman ' + total + '%' + (total > 100 ? ': no puede pasar de 100%' : '') : '';
    suma.className = todosEnTodo && total > 100 ? 'mal' : '';
  }

  mas.addEventListener('click', function () {
    var rs = renglones();
    if (rs.length >= MAX) return;
    var nuevo = rs[0].cloneNode(true);
    [].forEach.call(nuevo.querySelectorAll('select'), function (s) { s.value = ''; s.setCustomValidity(''); });
    nuevo.querySelector('input').value = '';
    nuevo.querySelector('input[name="fila[]"]').value = String(++sig);
    caja.appendChild(nuevo);
    pintarRenglones();
    revisar();
    nuevo.querySelector('select').focus();
  });

  caja.addEventListener('click', function (e) {
    var b = e.target.closest('[data-quitar]');
    if (!b || renglones().length < 2) return;
    b.closest('[data-fila]').remove();
    pintarRenglones();
    revisar();
  });

  caja.addEventListener('change', function (e) {
    if (e.target.name === 'colaborador[]') {
      // El porcentaje que esa persona suele cobrar, si no hay uno escrito.
      var o = e.target.options[e.target.selectedIndex];
      var p = e.target.closest('[data-fila]').querySelector('input');
      if (o && o.dataset.pct && !p.value) p.value = o.dataset.pct;
    }
    pintarRenglones();
    revisar();
  });
  caja.addEventListener('input', function () { pintarRenglones(); contar(); });

  /* ── Cada venta contra esa elección ── */
  /* Los productos de la venta que se van a comisionar: el único que
     tiene, o los que estén marcados. Cada uno con quién ya comisiona. */
  function productos(tr) {
    var ps = tr.querySelectorAll('input[data-prod]');
    if (!ps.length) {
      return +tr.dataset.n === 1
        ? [{ k: tr.dataset.linea, c: tr.dataset.con || '', a: tr.dataset.area, p: tr.dataset.pid }] : [];
    }
    return [].filter.call(ps, function (p) { return p.checked; }).map(function (p) {
      return { k: p.value, c: p.dataset.con || '', a: p.dataset.area, p: p.dataset.pid };
    });
  }

  /* ¿Este producto es para esta persona? */
  function enAlcance(p, en) {
    if (!en) return true;
    if (en.indexOf('a:') === 0) return p.a === en.slice(2).trim().toLowerCase();
    if (en.indexOf('p:') === 0) return p.p === en.slice(2);
    return false;
  }

  /* Por persona: su id real en esta venta ("esp" se vuelve el
     especialista), sus productos y cuántos de ellos le quedan libres.
     Igual que en el servidor: nadie recibe dos comisiones en el mismo
     producto (la primera fila gana), y en un producto los porcentajes
     de todos no pueden pasar de 100. */
  function ventaId(tr) { return tr.querySelector('input[name="ventas[]"]').value; }

  /* Las filas de "quiénes" que tocan algún producto de esta venta. */
  function aplicables(tr) {
    var ps = productos(tr);
    return quienes().filter(function (x) {
      return ps.some(function (p) { return enAlcance(p, x.en); });
    });
  }

  /* Con dos o más personas para una venta salen los chips, y ahí se puede
     quitar a alguien solo de esa venta. Con una, basta la casilla de la
     venta. */
  function hayPara(tr) { return aplicables(tr).length > 1; }

  function reparto(tr) {
    var ps = productos(tr), usados = {}, suma = {};
    var para = hayPara(tr), vid = ventaId(tr);
    return quienes().map(function (x) {
      var esp = x.q === 'esp';
      var mios = ps.filter(function (p) { return enAlcance(p, x.en); });
      if (!mios.length) return { libres: 0, aplica: false, motivo: '' };
      if (para && apagado[vid + ':' + x.k]) return { libres: 0, aplica: false, quitado: true, motivo: '' };
      var id = esp ? tr.dataset.esp : x.q;
      if (!id || id === '0') return { libres: 0, aplica: true, motivo: 'No tiene especialista' };
      var pasa = false;
      var l = mios.filter(function (p) {
        if ((',' + p.c + ',').indexOf(',' + id + ',') !== -1 || usados[id + ':' + p.k]) return false;
        if ((suma[p.k] || 0) + (x.pct || 0) > 100.001) { pasa = true; return false; }
        return true;
      });
      l.forEach(function (p) { usados[id + ':' + p.k] = true; suma[p.k] = (suma[p.k] || 0) + (x.pct || 0); });
      return { libres: l.length, aplica: true,
               motivo: l.length ? '' : pasa ? 'Los porcentajes pasan de 100% en un producto'
                     : esp ? 'Su especialista ya tiene comisión' : 'Ya tiene comisión de esta persona' };
    });
  }

  function libres(tr) {
    if (!quienes().length) return productos(tr).length;
    return reparto(tr).reduce(function (s, r) { return s + r.libres; }, 0);
  }

  function motivo(tr) {
    if (tr.dataset.fijo) return tr.dataset.fijo;
    if (!productos(tr).length) return 'Marca al menos un producto';
    var rs = reparto(tr);
    if (!rs.length || rs.some(function (r) { return r.libres; })) return '';
    var aplican = rs.filter(function (r) { return r.aplica; });
    if (!aplican.length) {
      return rs.some(function (r) { return r.quitado; })
        ? 'Quitaste a todos de esta venta' : 'Sus productos no son para nadie de la lista';
    }
    if (aplican.length === 1) return aplican[0].motivo;
    return 'Ya tienen comisión' + (aplican.some(function (r) { return r.motivo === 'No tiene especialista'; })
      ? ' (y no tiene especialista)' : '');
  }

  /* "Lista", o cuántas comisiones salen de esta venta. */
  function listo(tr) {
    var l = libres(tr), sel = productos(tr).length;
    if (quienes().length > 1) return 'Lista · ' + l + (l === 1 ? ' comisión' : ' comisiones');
    if (+tr.dataset.n < 2) return 'Lista';
    return 'Lista · ' + (l === sel ? l + (l === 1 ? ' producto' : ' productos')
                                   : l + ' de ' + sel + ' productos');
  }

  function marcadas() {
    return filas.filter(function (tr) {
      var c = tr.querySelector('input[type=checkbox]');
      return c && c.checked && !c.disabled;
    });
  }

  function comisiones() {
    return marcadas().reduce(function (s, tr) { return s + libres(tr); }, 0);
  }

  function describir(qs) {
    return qs.map(function (x) {
      return x.nombre + (x.pct > 0 ? ' ' + x.pct + '%' : '') + (x.en ? ' en ' + x.enTexto : '');
    }).join(' + ');
  }

  function contar() {
    var n = marcadas().length;
    if (cuenta) cuenta.textContent = n + (n === 1 ? ' marcada' : ' marcadas');
    var abiertas = filas.filter(function (tr) { return !tr.querySelector('input').disabled; });
    if (todas) {
      todas.checked = abiertas.length > 0 && n === abiertas.length;
      todas.indeterminate = n > 0 && n < abiertas.length;
      todas.disabled = !abiertas.length;
    }
    if (resum) {
      var qs = quienes(), c = comisiones();
      var faltaPct = renglones().some(function (r) {
        return r.querySelector('select').value && !(parseFloat(r.querySelector('input').value) > 0);
      });
      var ventas = n + (n === 1 ? ' venta' : ' ventas')
        + (qs.length && c !== n ? ' (' + c + (c === 1 ? ' comisión)' : ' comisiones)') : '');
      resum.textContent = !n ? 'No hay ventas marcadas.'
        : !qs.length ? n + (n === 1 ? ' venta marcada.' : ' ventas marcadas.') + ' Elige a quién.'
        : faltaPct ? ventas + ' para ' + describir(qs) + '. Falta el porcentaje.'
        : ventas + ' · ' + describir(qs);
    }
  }

  function revisar() {
    filas.forEach(function (tr) {
      var c = tr.querySelector('input[type=checkbox]');
      var m = motivo(tr);
      var antes = c.disabled;
      c.disabled = !!m;
      if (m) c.checked = false;
      else if (antes && !tr.dataset.fijo) c.checked = true;   // vuelve a quedar disponible
      tr.classList.toggle('no', !!m);
      tr.querySelector('.lf-lote-motivo').textContent = m || listo(tr);
      pintarPara(tr);
    });
    contar();
  }

  /* Los chips de la venta: uno por persona que le toca. Apagado = esa
     persona no va en esta venta. El "-" oculto le dice al servidor que en
     esta venta se eligió, aunque se apaguen todos. */
  function pintarPara(tr) {
    var box = tr.querySelector('.lf-lote-para');
    if (!box) return;
    box.innerHTML = '';
    if (tr.dataset.fijo || !hayPara(tr)) { box.hidden = true; return; }
    box.hidden = false;
    var vid = ventaId(tr);
    var marca = document.createElement('input');
    marca.type = 'hidden'; marca.name = 'para[' + vid + '][]'; marca.value = '-';
    box.appendChild(marca);
    aplicables(tr).forEach(function (x) {
      var l = document.createElement('label');
      var c = document.createElement('input');
      c.type = 'checkbox'; c.name = 'para[' + vid + '][]'; c.value = x.k;
      c.setAttribute('data-para', '');
      c.checked = !apagado[vid + ':' + x.k];
      l.title = c.checked ? 'Quitar de esta venta' : 'Volver a poner en esta venta';
      l.appendChild(c);
      l.appendChild(document.createTextNode(
        (x.q === 'esp' ? 'Especialista' : x.nombre) + (x.en ? ' · ' + x.enTexto : '')));
      box.appendChild(l);
    });
  }

  form.addEventListener('change', function (e) {
    if (e.target === todas) {
      filas.forEach(function (tr) {
        var c = tr.querySelector('input[type=checkbox]');
        if (!c.disabled) c.checked = todas.checked;
      });
    }
    // Quitar o poner a alguien en una venta, o cambiar sus productos,
    // puede cambiar si la venta se puede o no.
    if (e.target.matches('input[data-para]')) {
      apagado[ventaId(e.target.closest('tr')) + ':' + e.target.value] = !e.target.checked;
      revisar();
    } else if (e.target.matches('input[data-prod]')) revisar();
    else if (e.target.matches('tbody input[type=checkbox], #loteTodas')) contar();
  });

  /* Enter en un porcentaje no manda nada: aquí un envío por accidente
     son decenas de comisiones. */
  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') e.preventDefault();
  });

  /* ── Confirmar ── */
  function cerrar() { conf.hidden = true; document.body.classList.remove('lf-modal-abierto'); }
  [].forEach.call(conf.querySelectorAll('[data-lote-cerrar]'), function (b) {
    b.addEventListener('click', cerrar);
  });
  conf.addEventListener('click', function (e) { if (e.target === conf) cerrar(); });
  conf.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });

  form.addEventListener('submit', function (e) {
    if (confirmado) return;                  // ya confirmado: que se envíe
    e.preventDefault();
    var n = marcadas().length;
    if (!n) { alert('Marca al menos una venta.'); return; }
    if (!form.reportValidity()) return;
    var qs = quienes();
    var c = comisiones();
    if (!c) { alert('Con esa elección no queda ninguna comisión por asignar.'); return; }
    var datos = [['Ventas', n]];
    if (c !== n) datos.push(['Comisiones', c + (qs.length > 1 ? '' : ' (una por producto)')]);
    qs.forEach(function (x) { datos.push([x.nombre, x.pct + '%' + (x.en ? ' · solo ' + x.enTexto : '')]); });

    var d = document.getElementById('loteConfD');
    d.innerHTML = '';
    datos.forEach(function (x) {
      var div = document.createElement('div');
      var dt = document.createElement('dt'); dt.textContent = x[0];
      var dd = document.createElement('dd'); dd.textContent = x[1];
      div.appendChild(dt); div.appendChild(dd); d.appendChild(div);
    });
    conf.hidden = false;
    document.body.classList.add('lf-modal-abierto');
    conf.querySelector('[data-lote-cerrar].btn').focus();   // el foco en "Revisar", no en "Sí"
  });

  document.getElementById('loteConfOk').addEventListener('click', function () {
    confirmado = true;
    this.disabled = true;
    this.textContent = 'Asignando…';
    form.submit();
  });

  pintarRenglones();
  revisar();
})();
</script>
