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
          <select class="form-select" name="carrier" required>
            <option value="">Elegir…</option>
            <option>Telcel</option><option>Movistar</option>
            <option>AT&amp;T</option><option>Unefon</option>
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
        El número se valida con la compañía antes de cobrar. Una recarga no se
        puede cancelar después de enviada: el saldo ya llegó al teléfono.
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
      <form method="post" action="/recargas/consultar">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <button class="btn btn-secondary" type="submit" style="width:100%">
          <?= W::icono('reloj','15px') ?>Consultar saldo</button>
      </form>
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
