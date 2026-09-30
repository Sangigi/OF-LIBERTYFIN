<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
$token = $_SESSION['lf_token'];
$d = function ($k) use ($datos) { return P::e($datos[$k] ?? ''); };
?>
<main class="lf-acceso" style="max-width:560px">
  <div class="lf-acceso-marca">
    <span class="g">L</span>
    <div><b>LibertyFin</b><small>Registra tu negocio</small></div>
  </div>

  <?php if ($ok): ?>
    <div class="lf-aviso" style="background:var(--lf-brand-soft);color:var(--lf-brand-2)">
      <?= W::icono('cobro','17px') ?><span><?= P::e($ok) ?></span></div>
    <a class="lf-entrar" href="/login" style="text-decoration:none;display:flex;
       align-items:center;justify-content:center;margin-top:18px">Ir al inicio de sesión</a>
  <?php else: ?>

  <?php if ($error): ?>
    <div class="lf-aviso err"><?= W::icono('alerta','17px') ?><span><?= P::e($error) ?></span></div>
  <?php endif; ?>

  <form method="post" action="/registro" class="lf-acceso-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">

    <div class="campo">
      <label for="ne">Nombre de tu negocio</label>
      <input type="text" id="ne" name="nombre_empresa" value="<?= $d('nombre_empresa') ?>" required autofocus>
    </div>

    <div style="display:flex;gap:14px;flex-wrap:wrap">
      <div class="campo" style="flex:1;min-width:180px">
        <label for="gc">Giro</label>
        <input type="text" id="gc" name="giro_comercial" value="<?= $d('giro_comercial') ?>"
               placeholder="Contabilidad, legal, marketing…">
      </div>
      <div class="campo" style="width:170px">
        <label for="rf">RFC</label>
        <input type="text" id="rf" name="rfc" value="<?= $d('rfc') ?>"
               style="text-transform:uppercase" maxlength="13" placeholder="Opcional">
      </div>
    </div>

    <div class="campo">
      <label for="nc">Tu nombre</label>
      <input type="text" id="nc" name="nombre_contacto" value="<?= $d('nombre_contacto') ?>" required>
    </div>

    <div style="display:flex;gap:14px;flex-wrap:wrap">
      <div class="campo" style="flex:1;min-width:200px">
        <label for="em">Correo</label>
        <input type="email" id="em" name="email_admin" value="<?= $d('email_admin') ?>"
               autocomplete="email" required>
      </div>
      <div class="campo" style="width:170px">
        <label for="te">Teléfono</label>
        <input type="text" id="te" name="telefono" value="<?= $d('telefono') ?>" inputmode="tel">
      </div>
    </div>

    <div class="campo">
      <label for="nd">Código de distribuidor</label>
      <input type="text" id="nd" name="no_distribuidor" value="<?= $d('no_distribuidor') ?>"
             placeholder="Si alguien te invitó, ponlo aquí">
    </div>

    <button class="lf-entrar" type="submit" id="btnEntrar">
      <span class="txt">Enviar solicitud</span><span class="giro" aria-hidden="true"></span>
    </button>
  </form>

  <p class="lf-acceso-pie">
    Revisamos las solicitudes a mano. Cuando tu cuenta esté lista te mandamos
    tu usuario y contraseña por correo.<br>
    ¿Ya tienes cuenta? <a href="/login">Entra aquí</a>.
  </p>
  <?php endif; ?>
</main>

<script>
(function(){
  var f = document.querySelector('.lf-acceso-form'), b = document.getElementById('btnEntrar');
  if (!f || !b) return;
  f.addEventListener('submit', function(){
    if (!f.checkValidity || f.checkValidity()) { b.classList.add('cargando'); b.disabled = true; }
  });
})();
window.addEventListener('pageshow', function(e){
  if (!e.persisted) return;
  var b = document.getElementById('btnEntrar');
  if (b) { b.classList.remove('cargando'); b.disabled = false; }
});
</script>
