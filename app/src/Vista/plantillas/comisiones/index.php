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
  <div class="lf-equipo" data-desde="<?= P::e($desde) ?>" data-hasta="<?= P::e($hasta) ?>">
    <?php if (!$equipo): ?>
      <p style="grid-column:1/-1;padding:26px;text-align:center;color:var(--lf-tinta-4);font-size:13px">
        No hay comisiones devengadas en este periodo.</p>
    <?php endif; ?>
    <?php
    // Rejilla en vez de lista: con seis u ocho colaboradores una lista
    // vertical deja media pantalla en blanco.
    $mayor = 0; foreach ($equipo as $c) $mayor = max($mayor, (float)$c['devengado']);
    foreach ($equipo as $c): $huerfano = (int)$c['sin_dueno'] === 1; ?>
      <div class="lf-pers<?= $huerfano ? ' sin' : '' ?>" role="button" tabindex="0" aria-expanded="false"
           data-id="<?= (int)$c['colaborador_id'] ?>"
           data-nombre="<?= P::e($c['colaborador_nombre']) ?>"
           data-equipo="<?= P::e($c['equipo']) ?>">
        <span class="lf-av <?= $huerfano ? 'gris' : '' ?>">
          <?= $huerfano ? '?' : P::e($iniciales($c['colaborador_nombre'])) ?></span>
        <div style="flex:1;min-width:0">
          <b><?= P::e($c['colaborador_nombre']) ?></b>
          <small><?= P::e($c['area_nombre']) ?> · <?= (int)$c['ventas'] ?> venta<?= $c['ventas']==1?'':'s' ?></small>
          <?php W::avance($mayor > 0 ? $c['devengado'] / $mayor * 100 : 0, $huerfano); ?>
        </div>
        <span class="mn"><?= D::pesos($c['devengado']) ?>
          <?php if ($huerfano): ?><i>no se paga</i><?php endif; ?></span>
        <span class="lf-chev" aria-hidden="true"></span>
      </div>
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

<script>
/* Panel expandible por colaborador. Delegado en document y con guarda: el
   contenido se reemplaza al navegar sin recargar, y el script se vuelve a
   ejecutar; sin la guarda quedarían oyentes duplicados. */
(function () {
  if (window.__lfComDet) return;
  window.__lfComDet = true;

  function panelDe(card) {
    var n = card.nextElementSibling;
    return (n && n.classList.contains('lf-det')) ? n : null;
  }

  function cerrar(card) {
    var p = panelDe(card);
    if (p) p.remove();
    card.classList.remove('abierta');
    card.setAttribute('aria-expanded', 'false');
  }

  function cargar(panel, url) {
    panel.classList.add('espera');
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) {
        if (r.redirected) { location.reload(); throw 0; }  /* sesión vencida */
        if (!r.ok) throw new Error(r.status);
        return r.text();
      })
      .then(function (html) {
        panel.innerHTML = html;
        panel.classList.remove('espera');
      })
      .catch(function (e) {
        if (e === 0) return;
        panel.classList.remove('espera');
        panel.innerHTML = '<p class="lf-det-msj">No se pudo cargar el detalle. ' +
          '<a href="#" data-reintentar>Reintentar</a></p>';
        panel.dataset.url = url;
      });
  }

  function abrir(card) {
    var cont = card.closest('.lf-equipo');
    /* Uno a la vez: con varios abiertos la tarjeta crece y se pierden
       las métricas de arriba. */
    cont.querySelectorAll('.lf-pers.abierta').forEach(cerrar);

    var q = new URLSearchParams({
      desde: cont.dataset.desde, hasta: cont.dataset.hasta,
      nombre: card.dataset.nombre, equipo: card.dataset.equipo, p: 1
    });
    var panel = document.createElement('div');
    panel.className = 'lf-det espera';
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', 'Ventas de ' + card.dataset.nombre);
    panel.innerHTML = '<p class="lf-det-msj">Cargando…</p>';
    card.after(panel);
    card.classList.add('abierta');
    card.setAttribute('aria-expanded', 'true');
    cargar(panel, '/comisiones/colaborador/' + card.dataset.id + '?' + q);
  }

  function alternar(card) { panelDe(card) ? cerrar(card) : abrir(card); }

  document.addEventListener('click', function (ev) {
    var re = ev.target.closest('.lf-det [data-reintentar]');
    if (re) {
      ev.preventDefault();
      var pn = re.closest('.lf-det');
      cargar(pn, pn.dataset.url);
      return;
    }
    var pag = ev.target.closest('.lf-det .page-item a');
    if (pag) {
      ev.preventDefault();
      if (!pag.closest('.page-item.disabled')) cargar(pag.closest('.lf-det'), pag.getAttribute('href'));
      return;
    }
    if (ev.target.closest('.lf-det')) return;          /* enlaces de venta, etc. */
    var card = ev.target.closest('.lf-pers[data-id]');
    if (card) alternar(card);
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.key !== ' ') return;
    var card = ev.target.closest && ev.target.closest('.lf-pers[data-id]');
    if (card && ev.target === card) { ev.preventDefault(); alternar(card); }
  });
})();
</script>
