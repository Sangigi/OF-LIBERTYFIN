<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>


<?php /* ═══════════ DESPUÉS DE GUARDAR ═══════════ */ if ($resultado): $r = $resultado; ?>

<section class="card" style="margin-bottom:18px">
  <header class="card-header">Se guardó</header>
  <div class="card-body">
    <div class="lf-stats">
      <div class="stat">
        <div class="stat-value"><?= (int)$r['ventas'] ?></div>
        <div class="stat-meta">ventas · <?= P::e(D::pesos($r['total'])) ?></div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= (int)$r['cobros'] ?></div>
        <div class="stat-meta">cobros · <?= P::e(D::pesos($r['cobrado'])) ?></div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= (int)$r['comisiones'] ?></div>
        <div class="stat-meta">comisiones</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= (int)$r['gastos'] ?></div>
        <div class="stat-meta">gastos de operación</div>
      </div>
    </div>

    <?php $c = $r['creados']; if (array_sum($c)): ?>
      <p style="margin-top:16px;font-size:13px;color:var(--lf-tinta-3)">
        Se dieron de alta
        <?php $partes = [];
          foreach (['clientes'=>'cliente','servicios'=>'servicio',
                    'areas'=>'área','colaboradores'=>'colaborador'] as $k => $sing) {
            if ($c[$k]) $partes[] = '<b>' . (int)$c[$k] . '</b> ' . $sing . ($c[$k]==1 ? '' : 's');
          }
          echo implode(', ', $partes); ?>.
      </p>
    <?php endif; ?>

    <?php if ($r['abonos']): ?>
      <p style="margin-top:8px;font-size:13px;color:var(--lf-tinta-3)">
        <b><?= (int)$r['abonos'] ?></b>
        <?= $r['abonos']==1 ? 'renglón era otro cobro' : 'renglones eran otros cobros' ?>
        de una venta que ya estaba: <?= $r['abonos']==1 ? 'entró' : 'entraron' ?>
        como abono con su subfolio, no como venta nueva.
      </p>
    <?php endif; ?>

    <?php if ($r['saltadas']): ?>
      <p style="margin-top:8px;font-size:13px;color:var(--lf-tinta-4)">
        <?= (int)$r['saltadas'] ?> renglones se saltaron: o ya estaban, o traían algún problema.
      </p>
    <?php endif; ?>

    <?php if ($r['fallidas']): ?>
      <div class="alert alert-danger" style="margin-top:14px;display:block">
        <b><?= (int)$r['fallidas'] ?> no se pudieron guardar.</b>
        <?php /* Cada venta va en su propia transacción, así que lo demás
                 sí entró. Volver a subir el mismo archivo reanuda: lo
                 que ya está se salta solo por folio. */ ?>
        Lo demás sí quedó guardado. Puedes volver a subir el mismo archivo:
        lo que ya entró se salta solo.
        <ul style="margin:10px 0 0;padding-left:18px;font-size:12px">
          <?php foreach (array_slice($r['errores'], 0, 10) as $e): ?>
            <li><?= P::e($e) ?></li>
          <?php endforeach; ?>
          <?php if (count($r['errores']) > 10): ?>
            <li>… y <?= count($r['errores']) - 10 ?> más, en la bitácora.</li>
          <?php endif; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn btn-primary btn-sm" href="/ventas">Ver las ventas</a>
      <a class="btn btn-secondary btn-sm" href="/comisiones">Ver las comisiones</a>
      <a class="btn btn-secondary btn-sm" href="/importar">Importar otra hoja</a>
    </div>
  </div>
</section>


<?php /* ═══════════ REVISIÓN, ANTES DE GUARDAR ═══════════ */ elseif ($plan): $p = $plan; ?>

<section class="card" style="margin-bottom:18px">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
    <span>Esto es lo que va a entrar</span>
    <span class="badge bg-secondary lf-mono"><?= P::e($p['archivo']) ?></span>
  </header>
  <div class="card-body">
    <div class="lf-stats">
      <div class="stat">
        <div class="stat-value"><?= (int)$p['listas'] ?></div>
        <div class="stat-meta">ventas · <?= P::e(D::pesos($p['total'])) ?></div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= (int)$p['abonos'] ?></div>
        <div class="stat-meta">abonos a ventas de la misma hoja</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= P::e(D::corto($p['cobrado'])) ?></div>
        <div class="stat-meta">cobrado</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= P::e(D::corto($p['comisiones'])) ?></div>
        <div class="stat-meta">en comisiones</div>
      </div>
    </div>

    <?php $n = $p['nuevos']; if (array_filter($n)): ?>
      <div style="margin-top:16px;padding:12px 14px;border-radius:var(--lf-r);
                  background:var(--lf-vidrio);font-size:13px">
        <b>Se van a dar de alta</b>
        <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:16px">
          <?php foreach (['clientes'=>'Clientes','servicios'=>'Servicios',
                          'areas'=>'Áreas','colaboradores'=>'Colaboradores'] as $k => $rot):
                  if (!$n[$k]) continue; ?>
            <div style="min-width:170px">
              <div style="color:var(--lf-tinta-3);font-size:11.5px;text-transform:uppercase;
                          letter-spacing:.04em"><?= $rot ?> · <?= count($n[$k]) ?></div>
              <div style="font-size:12px;margin-top:3px;color:var(--lf-tinta-2)">
                <?= P::e(implode(', ', array_slice($n[$k], 0, 4)))
                  . (count($n[$k]) > 4 ? ' y ' . (count($n[$k]) - 4) . ' más' : '') ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php /* Los problemas van ARRIBA del botón, no en una pestaña aparte:
             confirmar sin haberlos visto no debería ser posible. */ ?>
    <?php if ($p['malas'] || $p['repetidas'] || $p['sinFolio']): ?>
      <div style="margin-top:14px;font-size:13px;color:var(--lf-tinta-3);
                  display:flex;flex-wrap:wrap;gap:18px">
        <?php if ($p['malas']): ?>
          <span><b style="color:var(--lf-rojo)"><?= (int)$p['malas'] ?></b>
            no se pueden guardar</span>
        <?php endif; ?>
        <?php if ($p['repetidas']): ?>
          <span><b><?= (int)$p['repetidas'] ?></b> ya estaban en el sistema</span>
        <?php endif; ?>
        <?php if ($p['sinFolio']): ?>
          <span><b><?= (int)$p['sinFolio'] ?></b> sin folio (totales de la hoja): se ignoran</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/importar/aplicar" style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <button class="btn btn-primary" type="submit"
              <?= $p['listas'] + $p['abonos'] ? '' : 'disabled' ?>>
        Guardar <?= (int)($p['listas'] + $p['abonos']) ?> renglones
      </button>
      <a class="btn btn-secondary" href="/importar/cancelar">Cancelar</a>
    </form>
    <p style="margin-top:10px;font-size:12px;color:var(--lf-tinta-4)">
      Hasta que des Guardar no se ha escrito nada.
    </p>
  </div>
</section>

<?php /* ── Renglón por renglón ── */ ?>
<section class="card">
  <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
    <span>Detalle</span>
    <div class="lf-pills" style="margin:0">
      <button type="button" class="lf-pill active" data-filtro="">Todos</button>
      <?php if ($p['conAviso']): ?>
        <button type="button" class="lf-pill" data-filtro="aviso">Con aviso · <?= (int)$p['conAviso'] ?></button>
      <?php endif; ?>
      <?php if ($p['malas'] + $p['repetidas']): ?>
        <button type="button" class="lf-pill" data-filtro="problema">Problema · <?= (int)($p['malas']+$p['repetidas']) ?></button>
      <?php endif; ?>
    </div>
  </header>
  <div class="table-responsive">
    <table class="table" id="tablaImport">
      <thead><tr>
        <th>Fila</th><th>Folio</th><th>Fecha</th><th>Cliente</th><th>Servicio</th>
        <th class="text-end">Total</th><th class="text-end">Cobra</th>
        <th class="text-end">Comisión</th><th>Qué pasa</th>
      </tr></thead>
      <tbody>
      <?php foreach ($p['renglones'] as $r):
        $problema = in_array($r['estado'], ['mala','repetida'], true);
        $clase = $problema ? 'problema' : ($r['avisos'] ? 'aviso' : '');
        $com = array_sum(array_column($r['comisiones'] ?? [], 'monto')); ?>
        <tr data-clase="<?= $clase ?>" style="<?= $problema ? 'opacity:.62' : '' ?>">
          <td data-label="Fila" class="lf-mono" style="font-size:11.5px;white-space:nowrap">
            <?= P::e($r['hoja']) ?> · <?= (int)$r['fila'] ?></td>
          <td data-label="Folio" class="lf-mono" style="font-size:11.5px">
            <?= P::e($r['folio'] ?? '—') ?>
            <?php if (($r['tipo'] ?? '') === 'abono'): ?>
              <span class="badge bg-secondary" style="font-size:10px">abono</span>
            <?php endif; ?>
          </td>
          <td data-label="Fecha" class="lf-mono" style="font-size:11.5px">
            <?= $r['fecha'] ?? '' ? date('d/m/Y', strtotime($r['fecha'])) : '—' ?></td>
          <td data-label="Cliente"><?= P::e($r['cliente'] ?? '') ?></td>
          <td data-label="Servicio" style="font-size:12px;color:var(--lf-tinta-3)">
            <?= P::e(($r['area'] ?? '') . ' · ' . ($r['servicio'] ?? '')) ?></td>
          <td data-label="Total" class="text-end">
            <?= ($r['total'] ?? 0) > 0 ? P::e(D::pesos($r['total'])) : '—' ?></td>
          <td data-label="Cobra" class="text-end"><?= P::e(D::pesos($r['anticipo'] ?? 0)) ?></td>
          <td data-label="Comisión" class="text-end">
            <?= $com > 0 ? P::e(D::pesos($com)) : '—' ?></td>
          <td data-label="Qué pasa" style="font-size:11.5px">
            <?php if ($r['estado'] === 'mala'): ?>
              <b style="color:var(--lf-rojo)">No entra.</b>
            <?php elseif ($r['estado'] === 'repetida'): ?>
              <b style="color:var(--lf-tinta-4)">Ya estaba.</b>
            <?php endif; ?>
            <?= P::e(implode(' ', $r['avisos'] ?? [])) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<script>
(function () {
  var tabla = document.getElementById('tablaImport');
  if (!tabla) return;
  document.querySelectorAll('[data-filtro]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-filtro]').forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active');
      var f = b.dataset.filtro;
      tabla.querySelectorAll('tbody tr').forEach(function (tr) {
        tr.hidden = f !== '' && tr.dataset.clase !== f;
      });
    });
  });
})();
</script>


<?php /* ═══════════ SUBIR ═══════════ */ else: ?>

<section class="card" style="margin-bottom:18px">
  <header class="card-header">Sube la hoja</header>
  <div class="card-body">
    <?php if (!$puedeXlsx): ?>
      <div class="alert alert-warning" style="display:block;margin-bottom:14px">
        Este servidor no puede abrir archivos <code>.xlsx</code>.
        Abre la hoja en Excel y guárdala como <b>CSV</b>; así sí entra.
      </div>
    <?php endif; ?>

    <form method="post" action="/importar/analizar" enctype="multipart/form-data">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <span class="lf-file">
        <input type="file" name="hoja" id="inpHoja" required
               accept=".xlsx,.xlsm,.csv,.txt,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        <label class="bt" for="inpHoja">Elegir archivo</label>
        <span class="n" data-vacio="Ningún archivo elegido">Ningún archivo elegido</span>
      </span>
      <p style="margin:12px 0 0;font-size:12px;color:var(--lf-tinta-4)">
        Hasta <?= (int)$maxMb ?> MB. Se aceptan <code>.xlsx</code> y <code>.csv</code>.
        Todas las hojas del libro se leen.
      </p>
      <button class="btn btn-primary btn-sm" type="submit" style="margin-top:14px">
        Revisar
      </button>
    </form>
  </div>
</section>

<section class="card">
  <header class="card-header">Qué espera encontrar</header>
  <div class="card-body" style="font-size:13px;color:var(--lf-tinta-2);line-height:1.65">
    <p style="margin:0 0 12px">
      Las columnas se buscan <b>por nombre</b>, no por posición, así que no
      importa el orden ni que una hoja tenga columnas que otra no.
      Tampoco importa que el encabezado no esté en la primera fila.
    </p>

    <div style="display:flex;flex-wrap:wrap;gap:22px;margin-bottom:14px">
      <div>
        <div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;
                    color:var(--lf-tinta-3);margin-bottom:5px">Imprescindibles</div>
        <code style="font-size:12px">FOLIO · CLIENTE · SERVICIO · COSTO TOTAL DE VENTA</code>
      </div>
      <div>
        <div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;
                    color:var(--lf-tinta-3);margin-bottom:5px">Si están, se usan</div>
        <code style="font-size:12px">FECHA · ÁREA · FORMA · MONTO ANTICIPO · RESTANTES ·
          IVA · COM. BANCA · RECIBO PAGO · SOL. GASTOS · CANTIDAD · A COMISIONAR ·
          VENDEDOR</code>
      </div>
    </div>

    <p style="margin:0 0 10px">
      Después de <b>VENDEDOR</b> van los cuatro importes de comisión, en este
      orden: vendedor, gerente, administración y área. Se guardan
      <b>tal como vienen</b> — el porcentaje se deduce del monto, no al revés.
    </p>

    <ul style="margin:0;padding-left:18px">
      <li style="margin-bottom:6px">
        <b>La fecha</b> que vale es la de la columna <code>FECHA</code>, no la que
        lleva el folio dentro. El folio es cuándo se capturó.</li>
      <li style="margin-bottom:6px">
        <b>Un folio repetido sin total</b> es otro cobro de esa misma venta,
        y entra como abono con su subfolio. Si trae su propio total, son dos
        ventas distintas y a la segunda se le pega una letra.</li>
      <li style="margin-bottom:6px">
        <b>Los renglones sin folio</b> —los totales del pie de la hoja— se ignoran.</li>
      <li>
        <b>Subir dos veces el mismo archivo no duplica nada</b>: lo que ya
        entró se reconoce por el folio y se salta.</li>
    </ul>
  </div>
</section>

<?php endif; ?>
