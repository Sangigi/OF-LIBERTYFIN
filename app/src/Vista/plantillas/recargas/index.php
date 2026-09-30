<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$cifrado && !$aceptado): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>La conexión con Emida va sin cifrar.</b> El usuario, la clave y el
    número del cliente viajan en claro por la red. Es el único endpoint que
    entregó el proveedor; en cuanto den uno con HTTPS, se cambia
    <code>wsdl</code> en <code>config/integraciones.php</code>.</span>
</div>
<?php endif; ?>

<?php if ($sandbox): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Modo de pruebas.</b> Las recargas no llegan al teléfono ni descuentan saldo real.
    Se cambia en <code>config/integraciones.php</code>, poniendo <code>'sandbox' =&gt; false</code>.</span>
</div>
<?php endif; ?>

<div class="lf-split">
  <section class="card">
    <header class="card-header">
      <div><span>Nueva recarga</span>
        <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
          Se registra como venta y entra al corte del turno</p></div>
    </header>
    <div class="card-body">
      <form method="post" action="/recargas/vender" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <div style="flex:1;min-width:170px">
          <label class="form-label">Compañía</label>
          <select class="form-select" name="producto" required>
            <option value="">Elegir…</option>
            <?php
            // Los identificadores los da Emida por compañía y monto. Estos
            // son de ejemplo: pídele a tu asesor el catálogo real y
            // cámbialos aquí, o se rechazará con el código 51.
            foreach (['Telcel','Movistar','AT&T','Unefon','Bait','Virgin'] as $c): ?>
              <option value="<?= P::e($c) ?>"><?= P::e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1;min-width:170px">
          <label class="form-label">Número</label>
          <input class="form-control lf-mono" name="numero" inputmode="numeric"
                 pattern="[0-9]{10}" maxlength="10" placeholder="10 dígitos" required>
        </div>
        <div style="width:140px">
          <label class="form-label">Monto</label>
          <input class="form-control lf-mono" type="number" name="monto"
                 step="10" min="10" placeholder="0.00" required>
        </div>
        <button class="btn btn-primary" type="submit">
          <?= W::icono('cobro','15px') ?>Recargar</button>
      </form>
      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:14px;line-height:1.5">
        El número se valida con la compañía <b>antes</b> de cobrar. Cobrar primero
        dejaría el caso donde la recarga falla y hay que devolver efectivo de una
        caja que ya cuadró.<br>
        Una recarga enviada no se puede cancelar: el saldo ya llegó al teléfono.
      </p>
    </div>
  </section>

  <section class="card">
    <header class="card-header">Saldo de la cuenta</header>
    <div class="card-body">
      <p style="font-size:13px;color:var(--lf-tinta-3);margin-bottom:16px">
        Las recargas se pagan de un saldo precargado con el proveedor. Si se
        agota, las ventas se rechazan aunque el cliente ya haya pagado.
      </p>
      <?php if ($saldo !== null): ?>
        <div style="padding:16px;border-radius:var(--lf-r);background:var(--lf-brand-soft);
             margin-bottom:16px;text-align:center">
          <b class="lf-mono" style="font-size:25px;font-weight:700;letter-spacing:-.8px;
             color:var(--lf-brand-2);display:block"><?= P::e($saldo) ?></b>
          <small style="font-size:11.5px;color:var(--lf-tinta-3)">al momento de consultar</small>
        </div>
      <?php endif; ?>
      <form method="post" action="/recargas/consultar">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn btn-secondary" type="submit" style="width:100%">
          <?= W::icono('reloj','15px') ?>Consultar saldo</button>
      </form>
      <form method="post" action="/recargas/probar" style="margin-top:9px">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn btn-secondary" type="submit" style="width:100%">
          <?= W::icono('alerta','15px') ?>Probar la conexión</button>
      </form>

      <?php if ($prueba): ?>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--lf-linea)">
          <?php foreach ($prueba as $x):
            $col = $x['ok'] ? 'var(--lf-brand-2)'
                 : (empty($x['bloquea']) ? 'var(--lf-amb)' : 'var(--lf-rojo)'); ?>
            <div style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;font-size:12px">
              <span style="flex-shrink:0;width:16px;text-align:center;color:<?= $col ?>;font-weight:700">
                <?= $x['ok'] ? '✓' : (empty($x['bloquea']) ? '!' : '×') ?></span>
              <span style="flex:1;min-width:0">
                <b style="font-weight:600;display:block"><?= P::e($x['que']) ?></b>
                <span style="color:var(--lf-tinta-4);font-size:11px;overflow-wrap:anywhere;
                      line-height:1.45"><?= P::e($x['detalle']) ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<section class="card">
  <header class="card-header">Recargas del día</header>
  <div class="card-body">
    <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:20px 0">
      Todavía no hay recargas registradas.
    </p>
  </div>
</section>
