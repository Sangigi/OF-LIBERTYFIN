<?php
use LibertyFin\Vista\Widget as W;
use LibertyFin\Vista\Plantilla as P;

/**
 * La barra de arriba: título, campana y tema.
 *
 * Aquí iba un botón por sección ("Nueva venta", "Nuevo cliente", "Nuevo
 * gasto", "Nuevo producto"). Se quitó por redundante: lo mismo está en el
 * menú lateral, y en cada pantalla su propio botón de alta.
 */
?>
<header class="lf-top">
  <div class="lf-top-tit">
    <h1><?= W::icono($icono, '18px') ?><span><?= P::e($titulo) ?></span></h1>
    <?php if ($subtitulo): ?><p><?= P::e($subtitulo) ?></p><?php endif; ?>
  </div>

  <div class="lf-acc">
    <?php /* La campana de soporte: mensajes de clientes que no has leído.
             La llena assets/js/lf-chat.js; aquí solo va el lugar. */
    if (\LibertyFin\Dominio\Permisos::puede('ver.tickets')): ?>
      <div class="lf-campana" data-lf-campana>
        <button type="button" class="lf-campana-bt" aria-haspopup="true" aria-expanded="false"
                aria-label="Mensajes de clientes sin leer" title="Mensajes de clientes sin leer">
          <?= W::icono('campana', '17px') ?>
          <span class="lf-noti-num" hidden></span>
        </button>
      </div>
    <?php endif; ?>

    <?php /* `data-lf-cuenta`: soporte guarda el tema en su cuenta, no solo aquí. */ ?>
    <button type="button" class="lf-tema" id="lfTema"<?= !empty($_SESSION['plataforma']) ? ' data-lf-cuenta' : '' ?>
            aria-label="Cambiar entre claro y oscuro" title="Cambiar tema">
      <span class="claro"><?= W::icono('luna', '16px') ?></span>
      <span class="oscuro"><?= W::icono('sol', '16px') ?></span>
    </button>
  </div>
</header>

<script>
(function(){
  /* Delegado en document: la navegación sin recarga reemplaza el
     innerHTML de la barra, y un listener puesto directo en el botón
     se perdía en la primera navegación. */
  if (window.lfTemaListo) return;
  window.lfTemaListo = true;
  document.addEventListener('click', function(ev){
    var b = ev.target.closest('#lfTema');
    if (!b) return;
    var raiz = document.documentElement;
    var nuevo = raiz.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    if (window.lfAplicarTema) window.lfAplicarTema(nuevo);
    else raiz.setAttribute('data-theme', nuevo);
    try { localStorage.setItem('lf-tema', nuevo); } catch (e) {}
    // Cuenta de soporte: también en su cuenta, para que la siga a otro equipo.
    if (b.hasAttribute('data-lf-cuenta') && window.fetch) {
      var d = new FormData();
      d.append('token', document.body.getAttribute('data-lf-token') || '');
      d.append('tema', nuevo);
      fetch('/cuenta/apariencia', { method: 'POST', body: d, credentials: 'same-origin',
        headers: { 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(null, function () {});
    }
  });
})();
</script>
