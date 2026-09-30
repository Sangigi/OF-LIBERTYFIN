<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
$token = $_SESSION['lf_token'];
?>
<main class="lf-acceso">
  <div class="lf-acceso-marca">
    <span class="g">L</span>
    <div><b>LibertyFin</b><small>Entra a tu cuenta</small></div>
  </div>

  <?php if ($error): ?>
    <div class="lf-aviso err"><?= W::icono('alerta','17px') ?><span><?= P::e($error) ?></span></div>
  <?php endif; ?>

  <?php if ($bloqueo > 0): ?>
    <div class="lf-aviso amb"><?= W::icono('reloj','17px') ?>
      <span>Demasiados intentos. Vuelve a probar en <?= ceil($bloqueo / 60) ?> minutos.</span></div>
  <?php endif; ?>

  <form method="post" action="/login" autocomplete="on" class="lf-acceso-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">

    <div class="campo">
      <label for="usuario">Usuario o correo</label>
      <input type="text" id="usuario" name="usuario" value="<?= P::e($usuario) ?>"
             autocomplete="username" autocapitalize="none" spellcheck="false"
             <?= $bloqueo > 0 ? 'disabled' : 'autofocus' ?> required>
    </div>

    <div class="campo">
      <label for="clave">Contraseña</label>
      <div class="con-ojo">
        <input type="password" id="clave" name="clave" autocomplete="current-password"
               <?= $bloqueo > 0 ? 'disabled' : '' ?> required>
        <button type="button" id="ojo" aria-label="Mostrar contraseña" title="Mostrar">
          <?= W::icono('buscar','15px') ?>
        </button>
      </div>
    </div>

    <button class="lf-entrar" type="submit" id="btnEntrar" <?= $bloqueo > 0 ? 'disabled' : '' ?>>
      <span class="txt">Entrar</span>
      <span class="giro" aria-hidden="true"></span>
    </button>
  </form>

  <p class="lf-acceso-pie">
    ¿Olvidaste tu contraseña? Pídele a un administrador que te la restablezca.
  </p>
</main>

<script>
(function(){
  // Al enviar, el botón pasa a girar. El ingreso hace varias consultas
  // y espera 350 ms a propósito para que un usuario que no existe tarde
  // lo mismo que uno que sí: sin señal visible, ese silencio se siente
  // como que el clic no funcionó y la gente vuelve a picarle.
  var f = document.querySelector('.lf-acceso-form'), b = document.getElementById('btnEntrar');
  if (f && b) f.addEventListener('submit', function(){
    if (!f.checkValidity || f.checkValidity()) {
      b.classList.add('cargando');
      b.disabled = true;
      // Si el navegador vuelve atrás con la página en caché, el botón
      // seguiría girando para siempre.
      setTimeout(function(){ b.classList.remove('cargando'); b.disabled = false; }, 12000);
    }
  });
})();

window.addEventListener('pageshow', function(e){
  if (!e.persisted) return;
  var b = document.getElementById('btnEntrar');
  if (b) { b.classList.remove('cargando'); b.disabled = false; }
});

(function(){
  var o = document.getElementById('ojo'), c = document.getElementById('clave');
  if (!o || !c) return;
  o.addEventListener('click', function(){
    var ver = c.type === 'password';
    c.type = ver ? 'text' : 'password';
    o.classList.toggle('on', ver);
    o.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
    c.focus();
  });
})();
</script>
