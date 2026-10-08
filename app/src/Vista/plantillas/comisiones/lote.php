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
        <p>Suman <b><?= D::pesos($resultado['monto']) ?></b> al <?= P::e(rtrim(rtrim(number_format($resultado['pct'], 2), '0'), '.')) ?>%.
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
          El porcentaje se aplica sobre la base comisionable de cada venta (sin IVA y sin gastos), igual que al asignar desde la venta</p></div>
    </header>
    <div class="lf-lote-quien">
      <label>Colaborador
        <select class="form-select" name="colaborador" id="loteQuien" required>
          <option value="">Elegir…</option>
          <option value="esp">Al especialista de cada venta</option>
          <?php foreach ($equipo as $nomArea => $gente): ?>
            <optgroup label="<?= P::e($nomArea) ?>">
              <?php foreach ($gente as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= P::e($c['nombre']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Porcentaje
        <span class="lf-lote-pct">
          <input class="form-control" type="number" name="porcentaje" id="lotePct"
                 min="0.01" max="100" step="0.01" inputmode="decimal" placeholder="10" required>
          <i>%</i>
        </span>
      </label>
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
            · con varios productos, la comisión va a cada uno<?= $area !== '' ? ' de ' . P::e($area) : '' ?>, sobre su propia base
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
          $nLin  = count($v['lineas']);
          // Los productos que se comisionarían, con quién ya comisiona en cada uno.
          $obj = [];
          foreach ($v['lineas'] as $l) {
              if (in_array($l['id'], $v['objetivo'], true)) $obj[] = ['c' => $l['con']];
          } ?>
          <tr data-obj="<?= P::e(json_encode($obj)) ?>" data-n="<?= $nLin ?>"
              data-esp="<?= (int)$v['especialista_id'] ?>"
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
                <ul class="lf-lote-prods">
                  <?php foreach ($v['lineas'] as $l): $fuera = !in_array($l['id'], $v['objetivo'], true); ?>
                    <li class="<?= $fuera ? 'fuera' : '' ?>">
                      <span><?= P::e($l['producto']) ?></span>
                      <small><?= P::e($l['area']) ?><?= $fuera ? ' · no entra' : '' ?><?=
                        $l['comisiones'] !== '' ? ' · ' . P::e($l['comisiones']) : '' ?></small>
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
            <td data-label="Estado" class="lf-lote-motivo"><?= $fijo ? P::e($fijo) : 'Lista' ?></td>
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
   Al elegir a quién, cada fila se revisa contra esa elección: si esa
   persona ya tiene comisión en la venta, o si se eligió "al especialista"
   y la venta no tiene, la fila se desmarca y dice por qué. Así lo que se
   ve marcado es lo que de verdad se va a asignar.
   La página se reemplaza al navegar sin recargar y este script vuelve a
   correr: todo se engancha a los elementos de esta página, que son
   nuevos cada vez. */
(function () {
  var form = document.getElementById('loteForm');
  if (!form) return;
  var quien  = document.getElementById('loteQuien');
  var pct    = document.getElementById('lotePct');
  var todas  = document.getElementById('loteTodas');
  var cuenta = document.getElementById('loteCuenta');
  var resum  = document.getElementById('loteResumen');
  var conf   = document.getElementById('loteConf');
  var filas  = [].slice.call(form.querySelectorAll('tbody tr'));
  var confirmado = false;

  /* Los productos de la fila que se comisionan, y de ellos cuántos le
     quedan libres a la persona elegida. */
  function productos(tr) {
    try { return JSON.parse(tr.dataset.obj || '[]'); } catch (e) { return []; }
  }
  function libres(tr) {
    var obj = productos(tr), q = quien.value;
    if (q === 'esp') q = tr.dataset.esp;
    if (!q || q === '0') return obj.length;
    return obj.filter(function (p) {
      return (',' + p.c + ',').indexOf(',' + q + ',') === -1;
    }).length;
  }

  function motivo(tr) {
    if (tr.dataset.fijo) return tr.dataset.fijo;
    var q = quien.value;
    if (q === 'esp') {
      var e = tr.dataset.esp;
      if (!e || e === '0') return 'No tiene especialista';
    }
    if (q && !libres(tr)) {
      return q === 'esp' ? 'Su especialista ya tiene comisión' : 'Ya tiene comisión de esta persona';
    }
    return '';
  }

  /* "Lista", o con varios productos cuántos se van a comisionar. */
  function listo(tr) {
    var n = +tr.dataset.n, l = libres(tr);
    if (n < 2) return 'Lista';
    return 'Lista · ' + (l === n ? l + ' productos' : l + ' de ' + n + ' productos');
  }

  function comisiones() {
    return marcadas().reduce(function (s, tr) { return s + libres(tr); }, 0);
  }

  function marcadas() {
    return filas.filter(function (tr) {
      var c = tr.querySelector('input[type=checkbox]');
      return c && c.checked && !c.disabled;
    });
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
      var nombre = quien.value ? quien.options[quien.selectedIndex].text : '';
      var c = comisiones();
      var ventas = n + (n === 1 ? ' venta' : ' ventas')
        + (quien.value && c !== n ? ' (' + c + ' comisiones)' : '');
      resum.textContent = !n ? 'No hay ventas marcadas.'
        : !quien.value ? n + (n === 1 ? ' venta marcada.' : ' ventas marcadas.') + ' Elige a quién.'
        : !(parseFloat(pct.value) > 0) ? ventas + ' para ' + nombre + '. Falta el porcentaje.'
        : ventas + ' · ' + nombre + ' · ' + pct.value + '%';
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
    });
    contar();
  }

  quien.addEventListener('change', revisar);
  pct.addEventListener('input', contar);
  form.addEventListener('change', function (e) {
    if (e.target === todas) {
      filas.forEach(function (tr) {
        var c = tr.querySelector('input[type=checkbox]');
        if (!c.disabled) c.checked = todas.checked;
      });
    }
    if (e.target.matches('tbody input[type=checkbox], #loteTodas')) contar();
  });

  /* Enter en el porcentaje no manda nada: aquí un envío por accidente
     son decenas de comisiones. */
  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') e.preventDefault();
  });

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
    var nombre = quien.options[quien.selectedIndex].text;
    var c = comisiones();
    var datos = [['Ventas', n]];
    if (c !== n) datos.push(['Comisiones', c + ' (una por producto)']);
    datos = datos.concat([
      ['A quién', nombre],
      ['Porcentaje', pct.value + '%']
    ]);
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

  revisar();
})();
</script>
