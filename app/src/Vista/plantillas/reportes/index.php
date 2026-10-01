<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

$margen = $r['cobrado'] > 0 ? round($r['queda'] / $r['cobrado'] * 100, 1) : 0;
$salidas = $r['operacion'] + $r['generales'] + $r['comisiones'];
$qs = http_build_query(['desde'=>$desde,'hasta'=>$hasta]);
$ini = function ($n) { $p = preg_split('/\s+/', trim($n ?: '?'));
    return mb_strtoupper(mb_substr($p[0],0,1) . (isset($p[1]) ? mb_substr($p[1],0,1) : '')); };
?>

<form class="lf-filtros" method="get">
  <input class="form-control form-control-sm" type="date" name="desde" value="<?= P::e($desde) ?>" style="width:auto">
  <input class="form-control form-control-sm" type="date" name="hasta" value="<?= P::e($hasta) ?>" style="width:auto">
  <button class="btn btn-secondary btn-sm" type="submit">Filtrar</button>
  <a class="btn btn-secondary btn-sm" href="/reportes/excel?<?= P::e($qs) ?>" style="margin-left:auto">
    <?= W::icono('baja','15px') ?>Descargar detalle</a>
</form>

<div class="lf-sintesis">
  <div class="lf-frase">
    <div style="flex:1;min-width:230px">
      <span class="lf-tag"><i></i>Resultado del periodo</span>
      <h2>Entraron <em><?= D::pesos($r['cobrado']) ?></em> y
        quedaron <em><?= D::pesos($r['queda']) ?></em>.</h2>
      <p>Lo demás se fue en gastos de operación, gastos generales y comisiones.
         El margen del periodo es del <?= $margen ?>%.</p>
    </div>
    <?php W::marcador(max(0, min(100, $margen)), 'margen'); ?>
  </div>

  <div class="lf-oscura">
    <div class="wm"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm.9 15.3v1.2h-1.7v-1.2c-1.7-.2-3-1.1-3.4-2.7l1.8-.7c.3 1 1.1 1.6 2.4 1.6 1.2 0 1.9-.5 1.9-1.3 0-.8-.6-1.1-2.2-1.5-2-.5-3.5-1.2-3.5-3.1 0-1.6 1.2-2.6 2.9-2.9V5.5h1.7v1.2c1.6.3 2.6 1.2 3 2.6l-1.8.7c-.3-.9-1-1.4-2-1.4-1.1 0-1.8.5-1.8 1.2 0 .8.7 1 2.3 1.4 2 .5 3.4 1.2 3.4 3.2 0 1.6-1.2 2.7-3 2.9z"/></svg></div>
    <div class="hd"><span class="lv"><i></i>Queda</span></div>
    <div class="big"><?= D::pesos($r['queda']) ?></div>
    <div class="sm"><?= $margen ?>% de lo que entró</div>
    <div class="ft">
      <div><small>Entró</small><b><?= D::corto($r['cobrado']) ?></b></div>
      <div><small>Salió</small><b><?= D::corto($salidas) ?></b></div>
    </div>
  </div>
</div>

<section class="card">
  <header class="card-header">
    <div><span>De dónde a dónde</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Parte del dinero que entró, no de lo facturado</p></div>
  </header>
  <div class="card-body">
    <table class="table" style="margin-bottom:0">
      <tbody>
        <?php
        $lineas = [
          ['Cobrado',              $r['cobrado'],      '',  'el dinero que de verdad entró'],
          ['Gastos de operación', -$r['operacion'],    'a', 'cuelgan de una venta y bajan la comisión'],
          ['Gastos generales',    -$r['generales'],    'a', 'renta, nómina, servicios'],
          ['Comisiones',          -$r['comisiones'],   'a', 'devengadas por lo cobrado'],
        ];
        foreach ($lineas as $l): list($n,$v,$t,$nota) = $l; ?>
          <tr>
            <td style="width:44%"><b style="font-weight:600"><?= P::e($n) ?></b>
              <span style="display:block;color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($nota) ?></span></td>
            <td class="text-end lf-mono" style="font-weight:700;font-size:15px;
                color:<?= $t==='a'?'var(--lf-tinta-3)':'var(--lf-brand-2)' ?>">
              <?= ($v<0?'−':'') . D::pesos(abs($v)) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr style="border-top:2px solid var(--lf-linea)">
          <td><b style="font-weight:700;font-size:15px">Queda</b></td>
          <td class="text-end lf-mono" style="font-weight:800;font-size:21px;letter-spacing:-.6px;
              color:<?= $r['queda']>=0?'var(--lf-brand-2)':'var(--lf-rojo)' ?>">
            <?= D::pesos($r['queda']) ?></td>
        </tr>
      </tbody>
    </table>
    <div class="leg" style="border-top:1px solid var(--lf-linea);margin-top:16px;padding-top:14px;
         font-size:12px;color:var(--lf-tinta-3);line-height:1.6">
      Se facturaron <b><?= D::pesos($r['vendido']) ?></b> y quedan
      <b style="color:var(--lf-amb)"><?= D::pesos($r['por_cobrar']) ?></b> sin cobrar.
      Eso no aparece arriba a propósito: facturar no es cobrar, y sumarlo como
      ingreso fue lo que hacía que el sistema mostrara cifras que no existían.
      <?php if ($r['iva'] > 0): ?><br>
      De lo facturado, <b><?= D::pesos($r['iva']) ?></b> son IVA: no es ingreso, es del SAT.
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (count($meses) > 1): ?>
<section class="card">
  <header class="card-header">Los últimos <?= count($meses) ?> meses</header>
  <div class="card-body">
    <?php
    $rot = array_map(function($m){ return date('M Y', strtotime($m['mes'].'-01')); }, $meses);
    W::curvas([
      ['datos' => array_map(function($m){ return $m['cobrado']; }, $meses)],
      ['datos' => array_map(function($m){ return $m['queda'];   }, $meses)],
    ], $rot);
    ?>
    <div style="display:flex;gap:8px;padding-top:8px">
      <?php foreach ($meses as $m): ?>
        <div style="flex:1;text-align:center;min-width:0">
          <b style="display:block;font-size:12px"><?= P::e(date('M', strtotime($m['mes'].'-01'))) ?></b>
          <span class="lf-mono" style="font-size:11px;color:var(--lf-tinta-4)"><?= D::corto($m['cobrado']) ?></span>
          <span class="lf-mono" style="display:block;font-size:10.5px;
                color:<?= $m['queda']>=0?'var(--lf-brand-2)':'var(--lf-rojo)' ?>">
            <?= D::corto($m['queda']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="lf-leyenda">
      <span><i class="lf-dot" style="background:var(--lf-brand)"></i>Cobrado</span>
      <span><i class="lf-dot" style="background:var(--lf-pizarra)"></i>Lo que quedó</span>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($areas): ?>
<section class="card">
  <header class="card-header">Por área</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Área</th><th class="text-end">Ventas</th><th class="text-end">Cobrado</th>
        <th class="text-end">Gastos</th><th class="text-end">Comisiones</th>
        <th class="text-end">Queda</th><th class="text-end">Margen</th></tr></thead>
      <tbody>
      <?php foreach ($areas as $a):
        $q = (float)$a['cobrado'] - (float)$a['gastos'] - (float)$a['comisiones'];
        $mg = $a['cobrado'] > 0 ? round($q / $a['cobrado'] * 100) : 0; ?>
        <tr>
          <td data-label="Área"><b style="font-weight:600"><?= P::e($a['area']) ?></b></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$a['ventas'] ?></td>
          <td data-label="Cobrado" class="text-end lf-mono"><?= D::pesos($a['cobrado']) ?></td>
          <td data-label="Gastos" class="text-end lf-mono" style="color:var(--lf-tinta-3)">
            <?= D::pesos($a['gastos']) ?></td>
          <td data-label="Comisiones" class="text-end lf-mono" style="color:var(--lf-tinta-3)">
            <?= D::pesos($a['comisiones']) ?></td>
          <td data-label="Queda" class="text-end lf-mono" style="font-weight:700;
              color:<?= $q>=0?'var(--lf-tinta)':'var(--lf-rojo)' ?>"><?= D::pesos($q) ?></td>
          <td data-label="Margen" class="text-end">
            <span class="badge <?= $mg>=40?'bg-success':($mg>=15?'bg-warning':'bg-danger') ?>">
              <?= $mg ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    El margen es lo que queda después de gastos y comisiones. Un área con mucho
    cobrado y margen bajo está trabajando para pagar comisiones.
  </div>
</section>
<?php endif; ?>

<div class="lf-split">
  <?php if ($servicios): ?>
  <section class="card">
    <header class="card-header">Servicios que más facturan</header>
    <div class="card-body">
      <?php W::barrasH(array_map(function($s){
        return ['rotulo'=>$s['nombre'],'monto'=>$s['facturado']]; }, $servicios)); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($metodos): ?>
  <section class="card">
    <header class="card-header">
      <div><span>Cómo cobran</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Por método de pago</p></div>
    </header>
    <div class="card-body">
      <?php
      $tm = 0; foreach ($metodos as $m) $tm += (float)$m['monto'];
      W::dona(array_map(function($m){
        return ['rotulo'=>ucfirst($m['metodo']),'monto'=>$m['monto']]; }, $metodos),
        D::corto($tm), 'cobrado');
      ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php if ($colaboradores): ?>
<section class="card" style="margin-top:2px">
  <header class="card-header">
    <div><span>Comisiones por colaborador</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Devengado en el periodo</p></div>
  </header>
  <div style="padding:0 10px 8px">
    <?php foreach ($colaboradores as $c): $sd = $c['nombre'] === 'POR ASIGNAR'; ?>
      <div class="lf-row" style="<?= $sd?'opacity:.72':'' ?>">
        <span class="lf-av <?= $sd?'gris':'' ?>"><?= $sd?'?':P::e($ini($c['nombre'])) ?></span>
        <span style="flex:1;min-width:0">
          <b style="display:block;font-size:13.5px"><?= P::e($c['nombre']) ?></b>
          <small style="color:var(--lf-tinta-4);font-size:11.5px">
            <?= P::e($c['area']) ?> · <?= (int)$c['ventas'] ?> venta<?= $c['ventas']==1?'':'s' ?></small>
        </span>
        <?php if ($sd): ?><span class="badge bg-warning" style="margin-right:8px">No se paga</span><?php endif; ?>
        <b class="lf-mono" style="font-size:14px"><?= D::pesos($c['devengado']) ?></b>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between">
    <span>Total devengado</span>
    <b class="lf-mono" style="color:var(--lf-tinta)"><?= D::pesos($r['comisiones']) ?></b>
  </div>
</section>
<?php endif; ?>

<?php /* ═══ LOS OCHO REPORTES ═══ */ ?>
<?php
/**
 * Los ocho van en la página, y la pestaña solo muestra uno.
 *
 * Antes cada pestaña era un enlace y recargaba: se perdía el lugar en la
 * página y había que esperar. Comparar "por área" con "por colaborador"
 * costaba dos viajes al servidor.
 *
 * Los datos ya estaban consultados de todos modos para el Excel, así que
 * cambiar de pestaña es instantáneo.
 */
$celda = function ($v, $t) {
    if ($v === null || $v === '') return '';
    if ($t === '$') return D::pesos($v);
    if ($t === 'n') return number_format((float)$v);
    if ($t === '%') return number_format((float)$v * (abs($v) <= 1.5 ? 100 : 1), 1) . '%';
    if ($t === 'f') return date('d/m/Y', is_numeric($v) ? (int)$v : strtotime((string)$v));
    return $v;
};
$derecha = function ($t) { return in_array($t, ['$','n','%'], true); };
?>
<section class="card lf-tabs" data-tabs="reportes">
  <header class="card-header">
    <div><span>Reportes del periodo</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Mismo dato en los tres lados: pantalla, Excel e impresión</p></div>
    <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap">
      <a class="btn btn-primary btn-sm"
         href="/reportes/excel?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta]) ?>">
        <?= W::icono('baja','14px') ?>Excel completo</a>
      <button type="button" class="btn btn-secondary btn-sm" id="abrirElegirTipos">
        Imprimir…</button>
    </div>
  </header>

  <?php /* Qué reportes imprimir, cuando son varios */ ?>
  <div class="lf-elegir" id="elegirTipos" hidden
       data-base="/reportes/imprimir?<?= P::e(http_build_query(['desde'=>$desde,'hasta'=>$hasta])) ?>">
    <div class="cab">
      <b><?= W::icono('baja','15px') ?>Qué imprimir</b>
      <span class="todas">
        <button type="button" data-todas="1">Todos</button>
        <button type="button" data-todas="0">Ninguno</button>
      </span>
    </div>
    <div class="ops">
      <?php foreach ($tipos as $k => $t): ?>
        <label>
          <input type="checkbox" value="<?= $k ?>" <?= $tipo===$k?'checked':'' ?>>
          <span><?= P::e($t['rotulo']) ?>
            <small><?= $k === 'desglose'
                       ? $desglose['cuantas'] . ' tablas'
                       : count($reportes[$k]['filas']) ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-primary btn-sm ir" href="#" target="_blank">Imprimir</a>
    <p class="nota">El desglose sale con una tabla por área. Para elegir
      <b>cuáles</b> áreas, hazlo desde su propia pestaña.</p>
  </div>

  <div class="lf-pills" style="padding:4px 20px 14px" role="tablist">
    <?php foreach ($tipos as $k => $t): ?>
      <a class="lf-pill <?= $tipo===$k?'active':'' ?>" role="tab" data-tab="<?= $k ?>"
         aria-selected="<?= $tipo===$k?'true':'false' ?>"
         href="?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>$k]) ?>">
        <?= P::e($t['rotulo']) ?>
        <span class="n"><?= count($reportes[$k]['filas']) ?></span></a>
    <?php endforeach; ?>
  </div>

  <?php /* El desglose no es una tabla: son varias, una por área. */ ?>
  <div data-panel="desglose" <?= $tipo==='desglose' ? '' : 'hidden' ?>>
    <div style="padding:0 20px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <span style="font-size:12.5px;color:var(--lf-tinta-3)">Agrupar por el área</span>
      <div class="lf-pills" style="margin:0">
        <a class="lf-pill <?= $por==='servicio'?'active':'' ?>"
           href="?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'desglose','por'=>'servicio']) ?>">
          del servicio contratado</a>
        <a class="lf-pill <?= $por==='origen'?'active':'' ?>"
           href="?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'desglose','por'=>'origen']) ?>">
          de donde salió la venta</a>
      </div>
    </div>

    <div style="padding:0 20px 14px">
      <p style="font-size:11.5px;color:var(--lf-tinta-4);line-height:1.6;margin:0;
                padding:12px 14px;background:var(--lf-vidrio);border-radius:var(--lf-r)">
        <b>Una venta puede pertenecer a dos áreas a la vez.</b> Si recepción cobró un
        servicio contable, la venta salió de Administración y el trabajo es de
        Contabilidad: las dos cosas son ciertas. Por eso se elige con cuál agrupar en
        vez de que el sistema decida.
        <b>El total no cambia</b> al cambiar la agrupación — es el mismo dinero contado
        de dos maneras.
        <?php if ($por === 'servicio'): ?>
          Agrupando por servicio, contabilidad se parte en personas físicas y morales.
        <?php else: ?>
          Agrupando por origen no se parte contabilidad: ahí la pregunta es de qué
          equipo salió la venta.
        <?php endif; ?>
      </p>
    </div>

    <?php if (!$desglose['tablas']): ?>
      <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:32px">
        No hay ventas en este periodo.</p>
    <?php else: ?>
      <?php /* Elegir qué tablas imprimir. Imprimir las nueve para leer
               una gasta papel y esconde lo que se buscaba. */ ?>
      <div class="lf-elegir" id="elegirAreas"
           data-base="/reportes/imprimir?<?= P::e(http_build_query(
             ['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'desglose','por'=>$por])) ?>">
        <div class="cab">
          <b><?= W::icono('baja','15px') ?>Imprimir</b>
          <span class="todas">
            <button type="button" data-todas="1">Todas</button>
            <button type="button" data-todas="0">Ninguna</button>
          </span>
        </div>
        <div class="ops">
          <?php foreach ($desglose['tablas'] as $tb): ?>
            <label>
              <input type="checkbox" value="<?= P::e($tb['titulo']) ?>" checked>
              <span><?= P::e($tb['titulo']) ?>
                <small><?= count($tb['filas']) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>
        <a class="btn btn-primary btn-sm ir" href="#" target="_blank">
          Imprimir las <?= count($desglose['tablas']) ?></a>
      </div>
    <?php endif; ?>

    <?php foreach ($desglose['tablas'] as $tb): ?>
      <div style="padding:0 12px 20px">
        <h3 style="font-size:14px;font-weight:700;padding:0 8px 9px;margin:0;
                   border-bottom:2px solid var(--lf-brand);display:flex;
                   justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span><?= P::e($tb['titulo']) ?></span>
          <span style="font-size:12px;font-weight:600;color:var(--lf-brand-2);
                       font-family:var(--lf-mono)">
            <?= D::pesos(array_sum(array_column($tb['filas'], 9))) ?>
            <span style="color:var(--lf-tinta-4);font-weight:400">
              · <?= count($tb['filas']) ?> renglones</span></span>
        </h3>
        <div class="table-responsive lf-cards">
          <table class="table">
            <thead><tr>
              <?php foreach ($tb['columnas'] as $c): ?>
                <th<?= $derecha($c[1]) ? ' class="text-end"' : '' ?>><?= P::e($c[0]) ?></th>
              <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($tb['filas'], 0, 40) as $f): ?>
              <tr>
                <?php foreach ($tb['columnas'] as $j => $c): ?>
                  <td data-label="<?= P::e($c[0]) ?>"
                      <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>>
                    <?= P::e($celda($f[$j] ?? null, $c[1])) ?: '–' ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="border-top:2px solid var(--lf-brand)">
              <?php foreach ($tb['columnas'] as $j => $c): ?>
                <td <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>
                    style="font-weight:700"><?= P::e($celda($tb['totales'][$j] ?? '', $c[1])) ?></td>
              <?php endforeach; ?>
            </tr></tfoot>
          </table>
        </div>
        <?php if (count($tb['filas']) > 40): ?>
          <p style="font-size:11.5px;color:var(--lf-tinta-4);padding:8px">
            Se muestran 40 de <?= count($tb['filas']) ?>; el Excel los trae todos.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <span><?= $desglose['cuantas'] ?> tabla<?= $desglose['cuantas']==1?'':'s' ?> ·
        total cobrado <b class="lf-mono" style="color:var(--lf-tinta)">
          <?= D::pesos($desglose['total']) ?></b></span>
      <a class="btn btn-secondary btn-sm" target="_blank"
         href="/reportes/imprimir?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>'desglose']) ?>">
        Imprimir</a>
    </div>
  </div>

  <?php /* Debajo del resumen por colaborador: el porque de cada comision. */ ?>
  <div data-panel="colaborador" <?= $tipo==='colaborador' ? '' : 'hidden' ?>>
    <?php $rep = $reportes['colaborador']; ?>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table">
        <thead><tr>
          <?php foreach ($rep['columnas'] as $c): ?>
            <th<?= $derecha($c[1]) ? ' class="text-end"' : '' ?>><?= P::e($c[0]) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php if (!$rep['filas']): ?>
          <tr><td colspan="<?= count($rep['columnas']) ?>"
              style="text-align:center;color:var(--lf-tinta-4);padding:32px">
            No hay comisiones en este periodo.</td></tr>
        <?php endif; ?>
        <?php foreach ($rep['filas'] as $f): ?>
          <tr>
            <?php foreach ($rep['columnas'] as $j => $c): ?>
              <td data-label="<?= P::e($c[0]) ?>"
                  <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>>
                <?= P::e($celda($f[$j] ?? null, $c[1])) ?: '–' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($rep['filas']): ?>
        <tfoot><tr style="border-top:2px solid var(--lf-brand)">
          <?php foreach ($rep['columnas'] as $j => $c): ?>
            <td <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>
                style="font-weight:700"><?= P::e($celda($rep['totales'][$j] ?? '', $c[1])) ?></td>
          <?php endforeach; ?>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>

    <?php if ($comisiones['tablas']): ?>
      <div style="padding:18px 20px 6px">
        <h3 style="font-size:14px;font-weight:700;margin:0 0 4px">De dónde sale cada comisión</h3>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);line-height:1.6;margin:0 0 4px">
          Cada renglón es un pago. <b>La comisión no es un porcentaje de la venta:
          es el porcentaje aplicado sobre lo que se cobró en ese pago.</b> Por eso un
          anticipo del 25% con comisión del 30% no da el 30% de la venta.
          Las columnas <b>% comisión</b> y <b>Sobre</b> explican el número.
        </p>
      </div>

      <?php foreach ($comisiones['tablas'] as $tb): ?>
        <details class="lf-colab">
          <summary>
            <span class="n"><?= P::e($tb['titulo']) ?></span>
            <span class="p"><?= (int)$tb['pagos'] ?> pago<?= $tb['pagos']==1?'':'s' ?></span>
            <b class="lf-mono"><?= D::pesos($tb['monto']) ?></b>
          </summary>
          <div class="table-responsive lf-cards">
            <table class="table">
              <thead><tr>
                <?php foreach ($tb['columnas'] as $c): ?>
                  <th<?= $derecha($c[1]) ? ' class="text-end"' : '' ?>><?= P::e($c[0]) ?></th>
                <?php endforeach; ?>
              </tr></thead>
              <tbody>
              <?php foreach ($tb['filas'] as $f): ?>
                <tr>
                  <?php foreach ($tb['columnas'] as $j => $c): ?>
                    <td data-label="<?= P::e($c[0]) ?>"
                        <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>>
                      <?= P::e($celda($f[$j] ?? null, $c[1])) ?: '–' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot><tr style="border-top:2px solid var(--lf-brand)">
                <?php foreach ($tb['columnas'] as $j => $c): ?>
                  <td <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>
                      style="font-weight:700"><?= P::e($celda($tb['totales'][$j] ?? '', $c[1])) ?></td>
                <?php endforeach; ?>
              </tr></tfoot>
            </table>
          </div>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <span><?= P::e($rep['nota']) ?></span>
      <a class="btn btn-secondary btn-sm" target="_blank"
         href="/reportes/imprimir?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,
               'tipo'=>'colaborador','detalle'=>1]) ?>">Imprimir con el detalle</a>
    </div>
  </div>

  <?php foreach ($reportes as $k => $rep): if ($k === 'desglose' || $k === 'colaborador') continue; ?>
  <div data-panel="<?= $k ?>" <?= $tipo===$k ? '' : 'hidden' ?>>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table">
        <thead><tr>
          <?php foreach ($rep['columnas'] as $c): ?>
            <th<?= $derecha($c[1]) ? ' class="text-end"' : '' ?>><?= P::e($c[0]) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php if (!$rep['filas']): ?>
          <tr><td colspan="<?= count($rep['columnas']) ?>"
              style="text-align:center;color:var(--lf-tinta-4);padding:32px">
            No hay datos en este periodo. Cambia las fechas de arriba.</td></tr>
        <?php endif; ?>
        <?php foreach (array_slice($rep['filas'], 0, 50) as $f): ?>
          <tr>
            <?php foreach ($rep['columnas'] as $j => $c): ?>
              <td data-label="<?= P::e($c[0]) ?>"
                  <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>>
                <?= P::e($celda($f[$j] ?? null, $c[1])) ?: '–' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($rep['filas']): ?>
        <tfoot><tr style="border-top:2px solid var(--lf-brand)">
          <?php foreach ($rep['columnas'] as $j => $c): ?>
            <td <?= $derecha($c[1]) ? 'class="text-end lf-mono"' : '' ?>
                style="font-weight:700"><?= P::e($celda($rep['totales'][$j] ?? '', $c[1])) ?></td>
          <?php endforeach; ?>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>

    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <span style="flex:1;min-width:220px"><?= P::e($rep['nota']) ?>
        <?php if (count($rep['filas']) > 50): ?>
          <b>Se muestran 50 de <?= count($rep['filas']) ?>; el Excel los trae todos.</b>
        <?php endif; ?></span>
      <a class="btn btn-secondary btn-sm" target="_blank"
         href="/reportes/imprimir?<?= http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>$k]) ?>">
        Imprimir este</a>
    </div>
  </div>
  <?php endforeach; ?>
</section>

<script>
/* ══════════════════════════════════════════════════════
   ELEGIR QUÉ IMPRIMIR
   Las casillas arman la dirección. El botón siempre dice
   cuántas van, porque "Imprimir" a secas no deja claro si
   respeta lo marcado.
   ══════════════════════════════════════════════════════ */
(function () {
  document.querySelectorAll('.lf-elegir').forEach(function (caja) {
    var ir    = caja.querySelector('.ir');
    var base  = caja.dataset.base;
    var esAreas = caja.id === 'elegirAreas';

    function refrescar() {
      var marcadas = Array.prototype.filter
        .call(caja.querySelectorAll('input[type=checkbox]'), function (c) { return c.checked; })
        .map(function (c) { return c.value; });
      var total = caja.querySelectorAll('input[type=checkbox]').length;

      if (!marcadas.length) {
        ir.classList.add('apagado');
        ir.removeAttribute('href');
        ir.textContent = 'Elige al menos una';
        return;
      }
      ir.classList.remove('apagado');

      /* Si están todas, no se manda el filtro: la dirección queda
         corta y se puede compartir sin arrastrar una lista enorme. */
      var url = base;
      if (marcadas.length < total) {
        url += '&' + (esAreas ? 'areas=' : 'tipos=')
             + marcadas.map(encodeURIComponent).join(esAreas ? '|' : ',');
      } else if (!esAreas) {
        url += '&todos=1';
      }
      ir.href = url;
      ir.textContent = marcadas.length === total
        ? ('Imprimir ' + (esAreas ? 'las ' : 'los ') + total)
        : ('Imprimir ' + marcadas.length + ' de ' + total);
    }

    caja.addEventListener('change', refrescar);
    caja.querySelectorAll('[data-todas]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.dataset.todas === '1';
        caja.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = v; });
        refrescar();
      });
    });
    refrescar();
  });

  var abrir = document.getElementById('abrirElegirTipos');
  var caja  = document.getElementById('elegirTipos');
  if (abrir && caja) {
    abrir.addEventListener('click', function () {
      caja.hidden = !caja.hidden;
      if (!caja.hidden) caja.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  }
})();
</script>
