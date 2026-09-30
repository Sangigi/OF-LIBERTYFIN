<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Permisos;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$d = $diagnostico;

// Las señales que de verdad importan, con su umbral.
$señales = [
  ['Ventas sin área',            $d['ventas_sin_area'],      'Los reportes por área salen vacíos'],
  ['Ventas con fecha desfasada', $d['ventas_desfasadas'],    'Pueden caer en el mes equivocado'],
  ['Cobrado mayor al total',     $d['cobrado_mayor_total'],  'Alguien cobró de más'],
  ['Comisiones sin dueño',       $d['comisiones_sin_dueno'], 'Cuentan en el total, no se pagan'],
  ['Ventas sin cliente',         $d['ventas_sin_cliente'],   'No se pueden perseguir en cobranza'],
  ['Cajas abiertas ahora',       $d['cajas_abiertas'],       'Turnos sin cerrar'],
];
$hora_ok = abs(strtotime($d['hora_php']) - strtotime($d['hora_sql'])) <= 60;
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<section class="card">
  <header class="card-header">
    <div><span>Revisión de la base</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Lo que suele estar detrás de un número que no cuadra</p></div>
  </header>
  <div class="lf-equipo" style="padding:4px 20px 18px">
    <?php foreach ($señales as $s): list($n, $v, $porque) = $s; $mal = $v > 0; ?>
      <div class="lf-pers<?= $mal ? '' : ' sin' ?>" style="align-items:flex-start">
        <span class="lf-tile <?= $mal ? 'a' : '' ?>" style="width:34px;height:34px;
              border-radius:11px;margin:0;flex-shrink:0">
          <?= W::icono($mal ? 'alerta' : 'cobro', '16px') ?></span>
        <div style="flex:1;min-width:0">
          <b style="white-space:normal"><?= P::e($n) ?></b>
          <small style="white-space:normal;line-height:1.4"><?= P::e($porque) ?></small>
        </div>
        <span class="mn" style="color:<?= $mal ? 'var(--lf-amb)' : 'var(--lf-tinta-4)' ?>">
          <?= (int)$v ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer">
    Un cero en todas no garantiza que esté bien, pero cualquier número distinto
    de cero explica un problema concreto.
  </div>
</section>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>Secciones</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Apaga las que esta empresa no usa</p></div>
    </header>
    <div style="padding:0 10px 8px">
      <?php foreach ($secciones as $k => $s): ?>
        <div class="lf-row">
          <span style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px"><?= P::e($s['rotulo']) ?></b>
            <small style="color:var(--lf-tinta-4);font-size:11.5px">
              <?= $s['activa'] ? 'Visible en el menú' : 'Oculta para todos' ?></small>
          </span>
          <form method="post" action="/mantenimiento/secciones">
            <input type="hidden" name="token" value="<?= P::e($token) ?>">
            <input type="hidden" name="seccion" value="<?= P::e($k) ?>">
            <button class="btn btn-sm <?= $s['activa'] ? 'btn-secondary' : 'btn-primary' ?>" type="submit">
              <?= $s['activa'] ? 'Apagar' : 'Encender' ?></button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer">
      Panel, Caja, Ventas, Clientes y Ajustes no se pueden apagar: sin ellas no
      se puede trabajar, y el usuario pensaría que el sistema se rompió.
    </div>
  </section>

  <div>
    <section class="card">
      <header class="card-header">Entorno</header>
      <div class="card-body" style="font-size:13px">
        <?php foreach ([
          'PHP'            => $d['php'],
          'MySQL'          => $d['mysql'],
          'Zona de PHP'    => $d['zona_php'],
          'Zona de MySQL'  => $d['zona_sql'],
          'Hora de PHP'    => $d['hora_php'],
          'Hora de MySQL'  => $d['hora_sql'],
        ] as $k => $v): ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0">
            <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
            <b class="lf-mono" style="font-size:12px;text-align:right"><?= P::e($v) ?></b>
          </div>
        <?php endforeach; ?>
        <div style="margin-top:12px;padding:11px 13px;border-radius:var(--lf-r);font-size:12.5px;
             background:<?= $hora_ok ? 'var(--lf-brand-soft)' : 'var(--lf-rojo-soft)' ?>;
             color:<?= $hora_ok ? 'var(--lf-brand-2)' : 'var(--lf-rojo)' ?>">
          <?= $hora_ok
            ? 'PHP y MySQL están a la misma hora.'
            : 'PHP y MySQL NO coinciden. Las ventas de la tarde pueden registrarse al día siguiente.' ?>
        </div>
      </div>
    </section>

    <section class="card">
      <header class="card-header">Integraciones</header>
      <div style="padding:0 10px 8px">
        <?php foreach ($integraciones as $k => $i): ?>
          <div class="lf-row">
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:13px"><?= P::e($i['nombre']) ?></b>
              <small style="color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
                <?= P::e($k) ?></small>
            </span>
            <?php if ($i['activa'] && $i['sandbox']): ?>
              <span class="badge bg-warning">Pruebas</span>
            <?php elseif ($i['activa']): ?>
              <span class="badge bg-success">Activa</span>
            <?php else: ?>
              <span class="badge bg-secondary">Pendiente</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</div>

<section class="card">
  <header class="card-header">
    <div><span>Tamaño de las tablas</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Si la columna de índice sale en cero, esa tabla se recorre completa en cada consulta</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Tabla</th><th class="text-end">Filas</th>
        <th class="text-end">Datos + índice</th><th class="text-end">Solo índice</th></tr></thead>
      <tbody>
      <?php foreach ($d['tablas'] as $t): ?>
        <tr>
          <td data-label="Tabla" class="lf-mono" style="font-size:12.5px"><?= P::e($t['tabla']) ?></td>
          <td data-label="Filas" class="text-end lf-mono"><?= number_format((int)$t['filas']) ?></td>
          <td data-label="Datos + índice" class="text-end lf-mono"><?= number_format((int)$t['kb']) ?> KB</td>
          <td data-label="Solo índice" class="text-end lf-mono"
              style="color:<?= (int)$t['ikb'] === 0 ? 'var(--lf-amb)' : 'var(--lf-tinta-3)' ?>">
            <?= number_format((int)$t['ikb']) ?> KB</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <header class="card-header">Qué puede cada rol</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Rol</th><th>Para quién</th>
        <th class="text-end">Ve</th><th class="text-end">Hace</th></tr></thead>
      <tbody>
      <?php foreach ($roles as $k => $r): $s = Permisos::resumen($k); ?>
        <tr>
          <td data-label="Rol"><b style="font-weight:600"><?= P::e($r['rotulo']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;
                  font-family:var(--lf-mono)"><?= P::e($k) ?></span></td>
          <td data-label="Para quién" style="font-size:12.5px;color:var(--lf-tinta-3)">
            <?= P::e($r['para']) ?></td>
          <td data-label="Ve" class="text-end lf-mono"><?= $s['ve'] ?></td>
          <td data-label="Hace" class="text-end lf-mono"><?= $s['hace'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Soporte ve casi todo y no mueve dinero: no cobra, no cancela pagos ni asigna
    comisiones. Si también pudiera, no habría forma de saber si un descuadre lo
    causó la empresa o quien vino a ayudar.
  </div>
</section>
