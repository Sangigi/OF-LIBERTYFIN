<?php
use LibertyFin\Vista\Plantilla as P;
$token = $_SESSION['lf_token'];
?>
<main class="lf-entrada">
  <div class="lf-logo">
    <?php
    /**
     * EL LOGOTIPO
     *
     * Pon tu PNG en  public/assets/img/logo-blanco.png  y aparece aquí
     * solo. Mientras no exista, se dibuja el texto: una imagen rota en
     * la pantalla de entrar es peor que no tener imagen, porque es lo
     * primero que ve alguien que todavía no confía en el sistema.
     *
     * Para que se vea bien sobre el verde: fondo transparente, trazo
     * blanco, y al menos 600 px de ancho.
     */
    $logo = __DIR__ . '/../../../public/assets/img/logo-blanco.png';
    ?>
    <?php if (is_file($logo)): ?>
      <img src="/assets/img/logo-blanco.png?v=<?= filemtime($logo) ?>" alt="LibertyFin">
    <?php else: ?>
      <span class="lf-logo-texto">Libertyfin</span>
    <?php endif; ?>
  </div>

  <div class="lf-tarjeta">
    <h1>Iniciar sesión</h1>
    <p class="lf-sub">Sistema de gestión de negocios · Multiempresa</p>

    <?php if ($error): ?>
      <div class="lf-msg err"><?= P::e($error) ?></div>
    <?php endif; ?>

    <?php if ($bloqueo > 0): ?>
      <div class="lf-msg amb">
        Demasiados intentos. Vuelve a probar en <?= ceil($bloqueo / 60) ?> minutos.
      </div>
    <?php endif; ?>

    <form method="post" action="/login" autocomplete="on" id="lfForm">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">

      <label for="usuario">Correo</label>
      <div class="lf-campo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="m3 7 9 6 9-6"/>
        </svg>
        <input type="text" id="usuario" name="usuario" value="<?= P::e($usuario) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               placeholder="tu@correo.com"
               <?= $bloqueo > 0 ? 'disabled' : 'autofocus' ?> required>
      </div>

      <label for="clave">Contraseña</label>
      <div class="lf-campo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="4" y="10.5" width="16" height="10" rx="2.5"/>
          <path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
        </svg>
        <input type="password" id="clave" name="clave" autocomplete="current-password"
               placeholder="••••••••" <?= $bloqueo > 0 ? 'disabled' : '' ?> required>
        <button type="button" id="ojo" class="lf-ojo" aria-label="Mostrar contraseña">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/>
            <circle cx="12" cy="12" r="2.8"/>
          </svg>
        </button>
      </div>

      <a class="lf-olvide" href="/ayuda-acceso">¿No recuerdas tu contraseña?</a>

      <label class="lf-recordar">
        <input type="checkbox" name="recordar" value="1"
               <?= !empty($_COOKIE['lf_recordar']) ? 'checked' : '' ?>>
        <span>Recordar sesión en este dispositivo</span>
      </label>

      <button class="lf-boton" type="submit" id="btnEntrar" <?= $bloqueo > 0 ? 'disabled' : '' ?>>
        <span class="txt">Entrar al sistema</span>
        <svg class="fl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M5 12h13M13 6l6 6-6 6"/>
        </svg>
        <span class="giro" aria-hidden="true"></span>
      </button>
    </form>
  </div>

  <p class="lf-pie-entrada">
    © <?= date('Y') ?> Libertyfin · Todos los derechos reservados
  </p>
</main>

<script>
(function(){
  var o = document.getElementById('ojo'), c = document.getElementById('clave');
  if (o && c) o.addEventListener('click', function(){
    var ver = c.type === 'password';
    c.type = ver ? 'text' : 'password';
    o.classList.toggle('on', ver);
    o.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
    c.focus();
  });

  // El ingreso espera 350 ms a propósito para que un usuario que no
  // existe tarde lo mismo que uno que sí. Sin señal visible, ese
  // silencio se siente como que el clic no funcionó.
  var f = document.getElementById('lfForm'), b = document.getElementById('btnEntrar');
  if (f && b) f.addEventListener('submit', function(){
    if (!f.checkValidity || f.checkValidity()) {
      b.classList.add('cargando'); b.disabled = true;
      setTimeout(function(){ b.classList.remove('cargando'); b.disabled = false; }, 12000);
    }
  });
})();
window.addEventListener('pageshow', function(e){
  if (!e.persisted) return;
  var b = document.getElementById('btnEntrar');
  if (b) { b.classList.remove('cargando'); b.disabled = false; }
});
</script>
