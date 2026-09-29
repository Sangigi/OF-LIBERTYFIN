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
    <div class="alert alert-danger" style="margin-bottom:16px">
      <?= W::icono('alerta','18px') ?><span><?= P::e($error) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($bloqueo > 0): ?>
    <div class="alert alert-warning" style="margin-bottom:16px">
      <?= W::icono('reloj','18px') ?>
      <span>Demasiados intentos. Vuelve a probar en <?= ceil($bloqueo / 60) ?> minutos.</span>
    </div>
  <?php endif; ?>

  <form method="post" action="/login" autocomplete="on">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">

    <label class="form-label" for="usuario">Usuario o correo</label>
    <input class="form-control" type="text" id="usuario" name="usuario"
           value="<?= P::e($usuario) ?>" autocomplete="username"
           <?= $bloqueo > 0 ? 'disabled' : 'autofocus' ?> required>

    <label class="form-label" for="clave" style="margin-top:14px">Contraseña</label>
    <div style="position:relative">
      <input class="form-control" type="password" id="clave" name="clave"
             autocomplete="current-password" style="padding-right:46px"
             <?= $bloqueo > 0 ? 'disabled' : '' ?> required>
      <button type="button" class="lf-ojo" id="ojo" aria-label="Mostrar contraseña">Ver</button>
    </div>

    <button class="btn btn-primary" type="submit" style="width:100%;margin-top:20px;padding:13px"
            <?= $bloqueo > 0 ? 'disabled' : '' ?>>Entrar</button>
  </form>

  <p class="lf-acceso-pie">
    Si olvidaste tu contraseña, pídele a un administrador que te la restablezca.
  </p>
</main>

<script>
(function(){
  var o = document.getElementById('ojo'), c = document.getElementById('clave');
  if (!o || !c) return;
  o.addEventListener('click', function(){
    var ver = c.type === 'password';
    c.type = ver ? 'text' : 'password';
    o.textContent = ver ? 'Ocultar' : 'Ver';
    c.focus();
  });
})();
</script>
