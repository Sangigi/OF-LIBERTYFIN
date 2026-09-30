<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Datos\GastoRepo as G;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token   = $_SESSION['lf_token'];
$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';
$e = $editando;
$qs = function (array $x = []) use ($desde,$hasta,$cat,$buscar) {
    return '?' . http_build_query(array_merge(
        ['desde'=>$desde,'hasta'=>$hasta,'cat'=>$cat,'q'=>$buscar], $x)); };
$totCat = 0; foreach ($categorias as $c) $totCat += (float)$c['monto'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($e ? 'baja' : 'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['concepto']) : 'Registrar un gasto' ?></summary>
  <form method="post" action="/gastos/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:200px">
      <label class="form-label">Concepto</label>
      <input class="form-control" name="concepto" value="<?= P::e($e['concepto'] ?? '') ?>" required>
    </div>
    <div style="width:150px">
      <label class="form-label">Categoría</label>
      <select class="form-select" name="categoria">
        <?php foreach (G::CATEGORIAS as $c): ?>
          <option value="<?= P::e($c) ?>" <?= (isset($e['categoria']) && $e['categoria']===$c)?'selected':'' ?>>
            <?= P::e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="width:130px">
      <label class="form-label">Monto</label>
      <input class="form-control lf-mono" type="number" name="monto" step="0.01" min="0.01"
             value="<?= P::e($e['monto'] ?? '') ?>" required>
    </div>
    <div style="width:150px">
      <label class="form-label">Fecha</label>
      <input class="form-control" type="date" name="fecha" max="<?= date('Y-m-d') ?>"
             value="<?= P::e(isset($e['fecha']) ? date('Y-m-d', strtotime($e['fecha'])) : date('Y-m-d')) ?>">
    </div>
    <div style="width:150px">
      <label class="form-label">Método</label>
      <select class="form-select" name="metodo_pago">
        <?php foreach (['efectivo'=>'Efectivo','transferencia'=>'Transferencia','tarjeta'=>'Tarjeta'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= (isset($e['metodo_pago']) && $e['metodo_pago']===$k)?'selected':'' ?>>
            <?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1;min-width:160px">
      <label class="form-label">Proveedor</label>
      <input class="form-control" name="proveedor" value="<?= P::e($e['proveedor'] ?? '') ?>" placeholder="Opcional">
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e ? 'Guardar' : 'Registrar' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/gastos">Cancelar</a><?php endif; ?>
    </div>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
      Estos son gastos <b>generales</b>: renta, nómina, servicios. No pertenecen a
      ninguna venta y no tocan comisiones. Los gastos de operación de una venta se
      capturan en Caja o en el detalle de esa venta.
    </p>
  </form>
</details>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('baja','19px') ?></div>
    <div class="stat-value"><?= D::corto($resumen['total'] ?? 0) ?></div>
    <div class="stat-label">Gastos generales</div>
    <div class="stat-meta"><?= (int)($resumen['cuantos'] ?? 0) ?> registros en el periodo</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile g"><?= W::icono('venta','19px') ?></div>
    <div class="stat-value"><?= D::corto($resumen['operacion'] ?? 0) ?></div>
    <div class="stat-label">De operación</div>
    <div class="stat-meta">cuelgan de ventas · no se suman aquí</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('pct','19px') ?></div>
    <div class="stat-value"><?= D::corto(($resumen['total'] ?? 0) + ($resumen['operacion'] ?? 0)) ?></div>
    <div class="stat-label">Salida total</div>
    <div class="stat-meta">generales más operación</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile l"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value" style="font-size:17px;letter-spacing:-.3px;font-family:var(--lf-font)">
      <?= P::e($categorias[0]['categoria'] ?? '—') ?></div>
    <div class="stat-label">Categoría que más pesa</div>
    <div class="stat-meta"><?= $categorias ? D::pesos($categorias[0]['monto']) : '—' ?></div>
  </div>
</div>

<form class="lf-filtros" method="get">
  <div class="lf-search">
    <?= W::icono('buscar','15px') ?>
    <input type="search" name="q" value="<?= P::e($buscar) ?>" placeholder="Concepto o nota">
  </div>
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <select class="form-select form-select-sm" name="cat" style="width:auto">
    <option value="">Todas las categorías</option>
    <?php foreach (G::CATEGORIAS as $c): ?>
      <option value="<?= P::e($c) ?>" <?= $cat===$c?'selected':'' ?>><?= P::e($c) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
</form>

<div class="lf-split">
  <section class="card">
    <header class="card-header">Movimientos</header>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover">
        <thead><tr><th>Concepto</th><th>Categoría</th><th>Método</th>
          <th class="text-end">Monto</th><th>Fecha</th><th></th></tr></thead>
        <tbody>
        <?php if (!$gastos): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
            No hay gastos generales en este periodo.</td></tr>
        <?php endif; ?>
        <?php foreach ($gastos as $g): ?>
          <tr>
            <td data-label="Concepto">
              <b style="font-weight:600"><?= P::e($g['concepto']) ?></b>
              <?php if ($g['proveedor'] || $g['descripcion']): ?>
                <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
                  <?= P::e($g['proveedor'] ?: $g['descripcion']) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Categoría"><span class="badge bg-secondary"><?= P::e($g['categoria']) ?></span></td>
            <td data-label="Método" style="font-size:12.5px"><?= P::e($g['metodo_pago']) ?></td>
            <td data-label="Monto" class="text-end lf-mono" style="font-weight:700"><?= D::pesos($g['monto']) ?></td>
            <td data-label="Fecha" class="lf-mono" style="font-size:12px"><?= date('d M', strtotime($g['fecha'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <a class="lf-btn-ghost" href="<?= P::e($qs(['editar'=>$g['id']])) ?>"
                 title="Editar"><?= W::icono('baja','15px') ?></a>
              <?php if ($esAdmin): ?>
                <button type="button" class="lf-btn-ghost lf-borrar-gasto"
                        data-id="<?= (int)$g['id'] ?>" data-con="<?= P::e($g['concepto']) ?>"
                        title="Eliminar">&times;</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between">
      <span><?= count($gastos) ?> movimientos</span>
      <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($resumen['total'] ?? 0) ?></b>
    </div>
  </section>

  <?php if ($categorias): ?>
  <section class="card">
    <header class="card-header">En qué se va</header>
    <div class="card-body">
      <?php W::dona(array_map(function($c){
        return ['rotulo'=>$c['categoria'],'monto'=>$c['monto']]; }, $categorias),
        D::corto($totCat), 'del periodo'); ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php if ($esAdmin): ?>
<form method="post" action="/gastos/borrar" id="formBorrarG" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="id" id="bgId">
</form>
<script>
document.querySelectorAll('.lf-borrar-gasto').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Eliminar "' + b.dataset.con + '"?\n\nNo se puede deshacer.')) return;
    document.getElementById('bgId').value = b.dataset.id;
    document.getElementById('formBorrarG').submit();
  });
});
</script>
<?php endif; ?>
