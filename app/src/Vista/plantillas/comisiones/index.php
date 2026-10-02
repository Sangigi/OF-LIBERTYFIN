<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token   = $_SESSION['lf_token'];
$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';
$total   = (float)($resumen['total'] ?? 0);
$pagar   = (float)($resumen['por_pagar'] ?? 0);
$sin     = (float)($resumen['sin_asignar'] ?? 0);
$iniciales = function ($n) {
    $p = preg_split('/\s+/', trim($n));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : ''));
};

// Conserva el periodo al cambiar de página: sin esto, pasar a la página
// dos devolvería al mes actual y las cifras cambiarían sin aviso.
$qs = function ($x = []) use ($desde, $hasta) {
    return '?' . http_build_query(array_merge(
        array_filter(['desde' => $desde, 'hasta' => $hasta]),
        array_filter($x, function ($v) { return $v !== null; })));
};
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<form class="lf-filtros" method="get">
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::pesos($pagar) ?></div>
    <div class="stat-label">Por pagar</div>
    <?php W::avance(D::pct($pagar, max(0.01,$total))); ?>
    <div class="stat-meta">de <?= D::corto($total) ?> que generó el periodo</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= D::pesos($sin) ?></div>
    <div class="stat-label">Sin dueño</div>
    <?php W::avance(D::pct($sin, max(0.01,$total)), true); ?>
    <div class="stat-meta"><?= (int)($resumen['ventas_pendientes'] ?? 0) ?> ventas sin confirmar quién vendió</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= D::pesos($resumen['por_liberar'] ?? 0) ?></div>
    <div class="stat-label">Por liberar</div>
    <div class="stat-meta">se libera conforme los clientes paguen</div>
  </div>

  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('cliente','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['colaboradores'] ?? 0) ?></div>
    <div class="stat-label">Colaboradores</div>
    <div class="stat-meta">con comisión en el periodo</div>
  </div>
</div>

<?php if ($sin_dueno): ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-amb) 30%,transparent)">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><span>Comisiones sin dueño</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Cuentan para el total, pero no se le pueden pagar a nadie hasta confirmar quién vendió</p></div>
    <span class="badge bg-warning"><?= D::pesos($sin) ?></span>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Venta</th><th>Área</th><th class="text-end">%</th>
        <th class="text-end">Monto</th><th style="width:250px">Asignar a</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sin_dueno as $s): ?>
        <tr>
          <td data-label="Venta">
            <a href="/ventas/<?= (int)$s['venta_id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($s['cliente'] ?: 'Público general') ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($s['codigo_venta']) ?> · <?= date('d M', strtotime($s['fecha'])) ?></span>
          </td>
          <td data-label="Área">
            <span class="badge bg-secondary"><?= P::e($s['area_servicio']) ?></span>
            <?php if ($s['area_nombre'] && $s['area_nombre'] !== $s['area_servicio']): ?>
              <span style="display:block;color:var(--lf-tinta-4);font-size:10.5px;margin-top:3px">
                comisión del equipo <?= P::e($s['area_nombre']) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="%" class="text-end lf-mono"><?= number_format($s['porcentaje'],2) ?>%</td>
          <td data-label="Monto" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($s['monto']) ?></td>
          <td data-label="Asignar a">
            <?php if (!$esAdmin): ?>
              <span style="font-size:12px;color:var(--lf-tinta-4)">Solo un administrador</span>
            <?php elseif (empty($candidatos[$s['area_nombre']])): ?>
              <span style="font-size:12px;color:var(--lf-tinta-4)">
                No hay colaboradores en <?= P::e($s['area_nombre']) ?></span>
            <?php else: ?>
              <form method="post" action="/comisiones/reasignar" style="display:flex;gap:7px">
                <input type="hidden" name="token" value="<?= P::e($token) ?>">
                <input type="hidden" name="renglon" value="<?= (int)$s['renglon'] ?>">
                <select class="form-select form-select-sm" name="colaborador" required>
                  <option value="">Elegir…</option>
                  <?php foreach ($candidatos[$s['area_nombre']] as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= P::e($c['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm" type="submit">Asignar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <header class="card-header">
    <div><span>Por colaborador</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Devengado en el periodo</p></div>
    <span class="badge bg-secondary"><?= count($equipo) ?> con comisión</span>
  </header>
  <?php
  // La rejilla cabe sin scroll hasta doce. Con treinta colaboradores la
  // tarjeta crece tanto que las métricas de arriba se pierden.
  $total_eq = count($equipo);
  $paginas_eq = max(1, (int)ceil($total_eq / $porPag));
  $pag_eq = min($pagina, $paginas_eq);
  $equipo = array_slice($equipo, ($pag_eq - 1) * $porPag, $porPag);
  ?>
  <div class="lf-equipo" id="equipoCards">
    <?php if (!$equipo): ?>
      <p style="grid-column:1/-1;padding:26px;text-align:center;color:var(--lf-tinta-4);font-size:13px">
        No hay comisiones devengadas en este periodo.</p>
    <?php endif; ?>
    <?php
    // Rejilla en vez de lista: con seis u ocho colaboradores una lista
    // vertical deja media pantalla en blanco.
    $mayor = 0; foreach ($equipo as $c) $mayor = max($mayor, (float)$c['devengado']);
    foreach ($equipo as $c): $huerfano = (int)$c['sin_dueno'] === 1; ?>
      <?php /* Clicable: abre la ventana con el detalle de sus comisiones.
               Es un <button> y no un <div> para que funcione con el
               teclado y lo lea un lector de pantalla. */ ?>
      <button type="button" class="lf-pers lf-abre<?= $huerfano ? ' sin' : '' ?>"
              data-quien="<?= P::e($c['nombre'] ?: 'POR ASIGNAR') ?>"
              title="Ver de dónde sale esta comisión">
        <span class="lf-av <?= $huerfano ? 'gris' : '' ?>">
          <?= $huerfano ? '?' : P::e($iniciales($c['colaborador_nombre'])) ?></span>
        <div style="flex:1;min-width:0">
          <b><?= P::e($c['colaborador_nombre']) ?></b>
          <small><?= P::e($c['area_nombre']) ?> ·
            <?= (int)$c['ventas'] ?> <?= $c['ventas']==1 ? 'pago' : 'pagos' ?></small>
          <?php W::avance($mayor > 0 ? $c['devengado'] / $mayor * 100 : 0, $huerfano); ?>
        </div>
        <span class="mn"><?= D::pesos($c['devengado']) ?>
          <?php if ($huerfano): ?><i>no se paga</i><?php endif; ?></span>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($equipo) ?> de <?= $total_eq ?> ·
      total generado <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($total) ?></b></span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pag_eq,'paginas'=>$paginas_eq,
      'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
  </div>
</section>

<div class="lf-tres">
    <?php if ($areas): ?>
    <section class="card">
      <header class="card-header">Por área</header>
      <div class="card-body">
        <?php W::dona(array_map(function($a){
          return ['rotulo'=>$a['area'] ?: 'Sin área','monto'=>$a['monto']]; }, $areas),
          D::corto($total), 'generado'); ?>
      </div>
      <div class="card-footer">
        El total reparte lo generado en el periodo, pagado o no.
      </div>
    </section>
    <?php endif; ?>

    <?php if ($liberacion): ?>
    <section class="card">
      <header class="card-header">
        <div><span>Cuánto se ha liberado</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            De lo asignado, qué parte ya se ganó</p></div>
      </header>
      <div class="card-body">
        <?php foreach ($liberacion as $l):
          $pct = D::pct($l['devengada'], max(0.01, $l['asignada'])); ?>
          <div style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px">
              <b style="font-weight:600"><?= P::e($l['area'] ?: 'Sin área') ?></b>
              <span class="lf-mono" style="color:var(--lf-tinta-3)">
                <?= D::pesos($l['devengada']) ?> de <?= D::pesos($l['asignada']) ?></span>
            </div>
            <?php W::avance($pct, $pct < 99.5); ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer">
        Lo que falta se libera conforme los clientes paguen. Un área muy abajo
        no está vendiendo mal: está esperando cobranza.
      </div>
    </section>
    <?php endif; ?>

    <?php if ($atadas): ?>
    <section class="card">
      <header class="card-header">
        <div><span>Comisión atada</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Las ventas que más comisión retienen sin cobrar</p></div>
      </header>
      <div style="padding:0 10px 8px">
        <?php foreach ($atadas as $a): ?>
          <a class="lf-row" href="/ventas/<?= (int)$a['id'] ?>">
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:13px"><?= P::e($a['cliente'] ?: 'Público general') ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11.5px">
                cobrado <?= D::pesos($a['cobrado']) ?> de <?= D::pesos($a['total']) ?></small>
            </span>
            <b class="lf-mono" style="font-size:13.5px;color:var(--lf-amb)">
              <?= D::pesos($a['pendiente']) ?></b>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="card-footer">
        Cobrar estas ventas es lo que libera esa comisión. Es la lista corta de
        a quién perseguir si el equipo quiere cobrar su parte.
      </div>
    </section>
    <?php endif; ?>
</div>

<?php /* ═══ VENTANAS FLOTANTES ═══ */ ?>
<div id="lfVentanas"></div>

<script>
/* ══════════════════════════════════════════════════════
   VENTANAS DE COLABORADOR
   Se mueven, se estiran, se minimizan y se pueden abrir
   varias a la vez para comparar. No son un modal: un
   modal tapa la pantalla y obliga a cerrarlo para mirar
   otra cosa, y aqui lo util es justo tener dos abiertas.
   ══════════════════════════════════════════════════════ */
(function () {
  var zona = document.getElementById('lfVentanas');
  if (!zona) return;
  var abiertas = {}, zTop = 400, nacidas = 0;

  var periodo = '<?= P::e(http_build_query(['desde' => $desde, 'hasta' => $hasta])) ?>';

  function traerAlFrente(v) { v.style.zIndex = ++zTop; }

  function abrir(quien) {
    if (abiertas[quien]) { traerAlFrente(abiertas[quien]); return; }

    var v = document.createElement('div');
    v.className = 'lf-vent';
    /* Cada una un poco mas abajo que la anterior: apiladas en el mismo
       punto, la segunda esconde a la primera. */
    var d = (nacidas++ % 6) * 26;
    v.style.left = (70 + d) + 'px';
    v.style.top  = (70 + d) + 'px';
    v.style.zIndex = ++zTop;
    v.innerHTML =
      '<header><span class="t">' + quien + '</span>'
      + '<button type="button" class="b" data-min title="Minimizar">–</button>'
      + '<button type="button" class="b" data-cerrar title="Cerrar">&times;</button></header>'
      + '<div class="cont"><div class="cargando"><span class="giro"></span>Cargando…</div></div>'
      + '<div class="asa" data-estirar></div>';
    zona.appendChild(v);
    abiertas[quien] = v;
    v.addEventListener('mousedown', function () { traerAlFrente(v); });

    fetch('/comisiones/colaborador?quien=' + encodeURIComponent(quien) + '&' + periodo,
          { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) { v.querySelector('.cont').innerHTML = html; })
      .catch(function () {
        v.querySelector('.cont').innerHTML =
          '<p class="vacio">No se pudo cargar. Vuelve a intentarlo.</p>';
      });
  }

  function cerrar(v) {
    var q = Object.keys(abiertas).filter(function (k) { return abiertas[k] === v; })[0];
    if (q) delete abiertas[q];
    v.remove();
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('.lf-abre');
    if (b) { abrir(b.dataset.quien); return; }
    var c = ev.target.closest('[data-cerrar]');
    if (c) { cerrar(c.closest('.lf-vent')); return; }
    var m = ev.target.closest('[data-min]');
    if (m) {
      var v = m.closest('.lf-vent');
      v.classList.toggle('min');
      m.textContent = v.classList.contains('min') ? '+' : '–';
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    /* Se cierra la de encima, no todas: cerrar las cinco de un golpe
       obliga a volver a abrirlas. */
    var todas = Array.prototype.slice.call(zona.querySelectorAll('.lf-vent'));
    if (!todas.length) return;
    todas.sort(function (a, b) { return (+b.style.zIndex) - (+a.style.zIndex); });
    cerrar(todas[0]);
  });

  /* ── Mover y estirar ──
     Con eventos de puntero, que funcionan igual con ratón y con dedo. */
  var arrastra = null;
  document.addEventListener('pointerdown', function (ev) {
    var asa = ev.target.closest('[data-estirar]');
    var cab = ev.target.closest('.lf-vent > header');
    if (!asa && !cab) return;
    if (cab && ev.target.closest('.b')) return;   // los botones no mueven

    var v = (asa || cab).closest('.lf-vent');
    traerAlFrente(v);
    var r = v.getBoundingClientRect();
    arrastra = {
      v: v, modo: asa ? 'estirar' : 'mover',
      x: ev.clientX, y: ev.clientY,
      l: r.left, t: r.top, w: r.width, h: r.height
    };
    v.classList.add('moviendo');
    ev.preventDefault();
  });

  document.addEventListener('pointermove', function (ev) {
    if (!arrastra) return;
    var dx = ev.clientX - arrastra.x, dy = ev.clientY - arrastra.y, v = arrastra.v;
    if (arrastra.modo === 'mover') {
      /* No se deja salir por arriba ni por los lados: una ventana
         medio fuera de la pantalla no se puede volver a agarrar. */
      var l = Math.max(8, Math.min(window.innerWidth - 120, arrastra.l + dx));
      var t = Math.max(8, Math.min(window.innerHeight - 60, arrastra.t + dy));
      v.style.left = l + 'px'; v.style.top = t + 'px';
    } else {
      v.style.width  = Math.max(320, arrastra.w + dx) + 'px';
      v.style.height = Math.max(180, arrastra.h + dy) + 'px';
    }
  });

  ['pointerup', 'pointercancel'].forEach(function (e) {
    document.addEventListener(e, function () {
      if (!arrastra) return;
      arrastra.v.classList.remove('moviendo');
      arrastra = null;
    });
  });
})();
</script>
