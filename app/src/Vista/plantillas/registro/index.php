<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
$token = $_SESSION['lf_token'];
$d = function ($k) use ($datos) { return P::e($datos[$k] ?? ''); };
?>
<main class="lf-registro">
  <div class="lf-reg-marca">
    <span class="g">L</span>
    <div><b>LibertyFin</b><small>Registra tu negocio</small></div>
  </div>

  <?php if ($ok): ?>
    <div class="lf-reg-listo">
      <span class="ok"><?= W::icono('cobro','30px') ?></span>
      <h2>Solicitud recibida</h2>
      <p><?= P::e($ok) ?></p>
      <div class="pasos-despues">
        <?php foreach ([
          ['Revisamos tus datos',  'Una persona la lee. Tardamos menos de un día hábil.'],
          ['Creamos tu cuenta',    'Te llega tu usuario y tu contraseña por correo.'],
          ['Entras y configuras',  'Tus servicios, tu equipo y tus datos fiscales.'],
        ] as $i => $ps): ?>
          <div class="p">
            <span class="n"><?= $i + 1 ?></span>
            <div><b><?= P::e($ps[0]) ?></b><small><?= P::e($ps[1]) ?></small></div>
          </div>
        <?php endforeach; ?>
      </div>
      <a class="lf-entrar" href="/login">Ir al inicio de sesión</a>
    </div>

  <?php else: ?>
    <?php if ($error): ?>
      <div class="lf-aviso err"><?= W::icono('alerta','17px') ?><span><?= P::e($error) ?></span></div>
    <?php endif; ?>

    <?php
    // Tres pasos como en el sistema anterior, pero sin recargar entre
    // uno y otro: el formulario es corto y partirlo en tres peticiones
    // pierde lo escrito si algo falla en medio.
    $pasos = ['Tu negocio', 'Tus datos', 'Confirmar'];
    ?>
    <div class="lf-reg-pasos" id="regPasos">
      <?php foreach ($pasos as $i => $n): ?>
        <div class="p<?= $i === 0 ? ' on' : '' ?>" data-p="<?= $i ?>">
          <span class="c"><?= $i + 1 ?></span>
          <span class="t"><?= P::e($n) ?></span>
        </div>
        <?php if ($i < count($pasos) - 1): ?><span class="ln"></span><?php endif; ?>
      <?php endforeach; ?>
    </div>

    <form method="post" action="/registro" class="lf-reg-form" id="regForm">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">

      <section class="hoja on" data-hoja="0">
        <h3>Información de tu negocio</h3>
        <div class="campo">
          <label for="ne">Nombre del negocio <i>*</i></label>
          <input type="text" id="ne" name="nombre_empresa" value="<?= $d('nombre_empresa') ?>"
                 placeholder="Como lo conocen tus clientes" required>
        </div>
        <div class="fila">
          <div class="campo">
            <label for="gc">Giro</label>
            <input type="text" id="gc" name="giro_comercial" value="<?= $d('giro_comercial') ?>"
                   placeholder="Contabilidad, legal, marketing…">
          </div>
          <div class="campo estrecho">
            <label for="rf">RFC</label>
            <input type="text" id="rf" name="rfc" value="<?= $d('rfc') ?>"
                   style="text-transform:uppercase" maxlength="13" placeholder="Opcional">
            <small>Lo puedes poner después, en tus datos fiscales.</small>
          </div>
        </div>
        <div class="acc">
          <span></span>
          <button type="button" class="lf-entrar sig" data-ir="1">Continuar</button>
        </div>
      </section>

      <section class="hoja" data-hoja="1">
        <h3>Cómo te contactamos</h3>
        <div class="campo">
          <label for="nc">Tu nombre <i>*</i></label>
          <input type="text" id="nc" name="nombre_contacto" value="<?= $d('nombre_contacto') ?>"
                 placeholder="Quién administra la cuenta" required>
        </div>
        <div class="fila">
          <div class="campo">
            <label for="em">Correo <i>*</i></label>
            <input type="email" id="em" name="email_admin" value="<?= $d('email_admin') ?>"
                   autocomplete="email" placeholder="tu@correo.com" required>
            <small>Aquí te mandamos tu usuario y contraseña.</small>
          </div>
          <div class="campo estrecho">
            <label for="te">Teléfono</label>
            <input type="text" id="te" name="telefono" value="<?= $d('telefono') ?>"
                   inputmode="tel" placeholder="10 dígitos">
          </div>
        </div>
        <div class="campo">
          <label for="nd">Código de distribuidor</label>
          <input type="text" id="nd" name="no_distribuidor" value="<?= $d('no_distribuidor') ?>"
                 placeholder="Solo si alguien te invitó">
        </div>
        <div class="acc">
          <button type="button" class="lf-btn-atras" data-ir="0">Atrás</button>
          <button type="button" class="lf-entrar sig" data-ir="2">Continuar</button>
        </div>
      </section>

      <section class="hoja" data-hoja="2">
        <h3>Revisa antes de enviar</h3>
        <div class="resumen" id="regResumen"></div>
        <p class="nota">
          Revisamos las solicitudes a mano, así que tarda unas horas. Cuando tu
          cuenta esté lista te mandamos tu usuario y tu contraseña al correo que
          pusiste. <b>No pedimos ningún pago para registrarte.</b>
        </p>
        <div class="acc">
          <button type="button" class="lf-btn-atras" data-ir="1">Atrás</button>
          <button class="lf-entrar" type="submit" id="btnEntrar">
            <span class="txt">Enviar solicitud</span><span class="giro" aria-hidden="true"></span>
          </button>
        </div>
      </section>
    </form>

    <p class="lf-acceso-pie">¿Ya tienes cuenta? <a href="/login">Entra aquí</a>.</p>
  <?php endif; ?>
</main>

<script>
(function(){
  var form = document.getElementById('regForm');
  if (!form) return;
  var hojas = form.querySelectorAll('.hoja'),
      pasos = document.querySelectorAll('#regPasos .p'),
      res   = document.getElementById('regResumen');

  function ir(n){
    hojas.forEach(function(h, i){ h.classList.toggle('on', i === +n); });
    pasos.forEach(function(p, i){
      p.classList.toggle('on',   i === +n);
      p.classList.toggle('hecho', i < +n);
    });
    if (+n === 2) resumir();
    // Al cambiar de hoja se sube: con el teclado abierto en el móvil,
    // quedarse a media pantalla hace pensar que no pasó nada.
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function resumir(){
    var campos = [
      ['Negocio',      'nombre_empresa'], ['Giro', 'giro_comercial'],
      ['RFC',          'rfc'],            ['Contacto', 'nombre_contacto'],
      ['Correo',       'email_admin'],    ['Teléfono', 'telefono'],
      ['Distribuidor', 'no_distribuidor'],
    ];
    res.innerHTML = campos.map(function(c){
      var el = form.elements[c[1]], v = el ? el.value.trim() : '';
      // Los vacíos se muestran igual, como "sin poner": esconderlos
      // haría creer que se olvidó un campo que era opcional.
      return '<div class="r"><span>' + c[0] + '</span><b' + (v ? '' : ' class="vacio"') + '>'
           + (v ? v.replace(/[<>&]/g, '') : 'sin poner') + '</b></div>';
    }).join('');
  }

  form.querySelectorAll('[data-ir]').forEach(function(b){
    b.addEventListener('click', function(){
      // Al avanzar se validan SOLO los campos de la hoja actual: el
      // navegador validaría todo el formulario y marcaría campos que
      // todavía no se ven.
      if (b.classList.contains('sig')) {
        var actual = form.querySelector('.hoja.on'), ok = true;
        actual.querySelectorAll('input[required]').forEach(function(i){
          if (!i.checkValidity()) { i.reportValidity(); ok = false; }
        });
        if (!ok) return;
      }
      ir(b.dataset.ir);
    });
  });

  form.addEventListener('submit', function(){
    var b = document.getElementById('btnEntrar');
    if (b && (!form.checkValidity || form.checkValidity())) {
      b.classList.add('cargando'); b.disabled = true;
    }
  });
})();
window.addEventListener('pageshow', function(e){
  if (!e.persisted) return;
  var b = document.getElementById('btnEntrar');
  if (b) { b.classList.remove('cargando'); b.disabled = false; }
});
</script>
