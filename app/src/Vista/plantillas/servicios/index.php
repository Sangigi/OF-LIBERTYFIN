<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$mv  = $resumen['mas_vendido'] ?? null;
$at  = $resumen['area_top'] ?? null;
$tot = 0; foreach ($areas as $a) $tot += (float)$a['monto'];
if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = $editando;
$qs = function (array $x = []) use ($desde,$hasta,$buscar) {
    return '?' . http_build_query(array_merge(['desde'=>$desde,'hasta'=>$hasta,'q'=>$buscar], $x)); };
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary>
    <?= W::icono($e ? 'serv' : 'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['nombre']) : 'Dar de alta un servicio' ?>
  </summary>
  <form method="post" action="/servicios/guardar" class="lf-form" enctype="multipart/form-data">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:220px">
      <label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required>
    </div>
    <div style="width:130px">
      <label class="form-label">Código</label>
      <input class="form-control" name="codigo" value="<?= P::e($e['codigo'] ?? '') ?>"
             style="text-transform:uppercase" required>
    </div>
    <div style="flex:1;min-width:150px">
      <label class="form-label">Área</label>
      <select class="form-select" name="categoria_id">
        <option value="">Sin área</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int)$c['id'] ?>"
            <?= (isset($e['categoria_id']) && (int)$e['categoria_id'] === (int)$c['id']) ? 'selected' : '' ?>>
            <?= P::e($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:130px">
      <label class="form-label">Precio</label>
      <input class="form-control lf-mono" type="number" name="precio" step="0.01" min="0.01"
             value="<?= P::e($e['subprecio'] ?? $e['precio'] ?? '') ?>" required>
    </div>
    <div style="width:130px">
      <label class="form-label">Costo</label>
      <input class="form-control lf-mono" type="number" name="costo" step="0.01" min="0"
             value="<?= P::e($e['costo'] ?? '0') ?>">
    </div>
    <div style="flex:1;min-width:230px">
      <label class="form-label">Imagen</label>
      <div class="lf-foto">
        <span class="prev cuadro" id="prevServ" style="width:48px;height:48px;font-size:16px;
              <?= !empty($e['imagen']) ? "background-image:url('".P::e($e['imagen'])."')" : '' ?>">
          <?= !empty($e['imagen']) ? '' : '+' ?></span>
        <div style="flex:1;min-width:0">
          <input type="file" name="imagen" id="inpServ" accept="image/png,image/jpeg,image/webp">
          <?php if (!empty($e['imagen'])): ?>
            <label style="font-size:11px;color:var(--lf-tinta-3);display:flex;
                   align-items:center;gap:6px;margin-top:5px;cursor:pointer">
              <input type="checkbox" name="quitar_imagen" value="1"> Quitar
            </label>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e ? 'Guardar' : 'Dar de alta' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/servicios">Cancelar</a><?php endif; ?>
    </div>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
      El precio es lo que se cobra al cliente. El costo se resta de la utilidad antes
      de calcular comisiones, así que no puede ser mayor al precio.
    </p>
  </form>
</details>

<form class="lf-filtros" method="get">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Servicio o código">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-stats">
  <div class="stat-card"><div class="lf-tile g"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['activos'] ?? 0) ?></div>
    <div class="stat-label">Servicios activos</div>
    <div class="stat-meta">en el catálogo</div></div>

  <div class="stat-card lf-hero"><div class="lf-tile"><?= W::icono('bolsa','19px') ?></div>
    <div class="stat-value" style="font-size:17px;letter-spacing:-.3px;font-family:var(--lf-font)">
      <?= P::e($mv['nombre'] ?? '—') ?></div>
    <div class="stat-label">Más vendido</div>
    <div class="stat-meta"><?= $mv ? (int)$mv['veces'] . ' ventas · ' . D::corto($mv['ingreso']) : 'sin ventas en el periodo' ?></div></div>

  <div class="stat-card"><div class="lf-tile"><?= W::icono('cobro','19px') ?></div>
    <div class="stat-value" style="font-size:17px;letter-spacing:-.3px;font-family:var(--lf-font)">
      <?= P::e($at['area'] ?? '—') ?></div>
    <div class="stat-label">Área con más ingreso</div>
    <div class="stat-meta"><?= $at ? D::pesos($at['cobrado']) . ' cobrados' : '—' ?></div></div>

  <div class="stat-card"><div class="lf-tile a"><?= W::icono('alerta','19px') ?></div>
    <div class="stat-value"><?= (int)($resumen['sin_ventas'] ?? 0) ?></div>
    <div class="stat-label">Sin ventas</div>
    <div class="stat-meta">en los últimos 60 días</div></div>
</div>

<div class="lf-split">
  <?php if ($top): ?>
  <section class="card">
    <header class="card-header">
      <div><span>Servicios que más facturan</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Del periodo filtrado</p></div>
    </header>
    <div class="card-body">
      <?php W::barrasH(array_map(function($t){
        return ['rotulo'=>$t['nombre'],'monto'=>$t['monto']]; }, $top)); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($areas): ?>
  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
      <div><span>Cobrado por área</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Cobrado, no facturado</p></div>
    </header>
    <div class="card-body">
      <?php W::dona(array_map(function($a){
        return ['rotulo'=>$a['area'],'monto'=>$a['monto']]; }, $areas),
        D::corto($tot), 'cobrado'); ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<section class="card">
  <header class="card-header">Catálogo</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr>
        <th>Servicio</th><th>Código</th><th>Área</th>
        <th class="text-end">Precio</th><th class="text-end">Ventas</th>
        <th class="text-end">Ingreso</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$catalogo): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay servicios que coincidan.</td></tr>
      <?php endif; ?>
      <?php foreach ($catalogo as $s): ?>
        <tr>
          <td data-label="Servicio">
            <span style="display:flex;align-items:center;gap:10px">
              <span class="lf-mini-img"
                    style="<?= !empty($s['imagen']) ? "background-image:url('".P::e($s['imagen'])."')" : '' ?>">
                <?= !empty($s['imagen']) ? '' : W::icono('serv','14px') ?></span>
              <b style="font-weight:600"><?= P::e($s['nombre']) ?></b>
            </span>
          </td>
          <td data-label="Código"><span class="badge bg-secondary"><?= P::e($s['codigo']) ?></span></td>
          <td data-label="Área"><span class="badge bg-secondary"><?= P::e($s['categoria'] ?: 'Sin área') ?></span></td>
          <td data-label="Precio" class="text-end lf-mono"><?= D::pesos($s['precio']) ?></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$s['ventas'] ?></td>
          <td data-label="Ingreso" class="text-end">
            <?php if ((int)$s['ventas'] > 0): ?>
              <b class="lf-mono"><?= D::pesos($s['ingreso']) ?></b>
            <?php else: ?>
              <span class="badge bg-warning">Sin ventas</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="<?= P::e($qs(['editar'=>$s['id']])) ?>"
               title="Editar"><?= W::icono('serv','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-alternar"
                    data-id="<?= (int)$s['id'] ?>" data-nombre="<?= P::e($s['nombre']) ?>"
                    title="Desactivar">&times;</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($catalogo) ?> de <?= number_format($total) ?> servicios</span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
      'enlace'=>function($n) use ($qs){ return $qs(['p'=>$n]); }]); ?>
  </div>
</section>

<form method="post" action="/servicios/alternar" id="formAlternar" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="altId">
</form>
<script>
document.querySelectorAll('.lf-alternar').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Desactivar "' + b.dataset.nombre + '"?\n\n'
      + 'Deja de aparecer en Caja, pero las ventas que ya lo usaron no se tocan.')) return;
    document.getElementById('altId').value = b.dataset.id;
    document.getElementById('formAlternar').submit();
  });
});
</script>

<script>
(function(){
  var i = document.getElementById('inpServ'), p = document.getElementById('prevServ');
  if (!i || !p) return;
  i.addEventListener('change', function(){
    var f = i.files && i.files[0];
    if (!f) return;
    p.style.backgroundImage = "url('" + URL.createObjectURL(f) + "')";
    p.textContent = '';
  });
})();
</script>
