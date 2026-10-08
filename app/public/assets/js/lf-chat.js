/* ══════════════════════════════════════════════════════
   LibertyFin · el chat de soporte

   Tres piezas:

   1. CONVERSACIÓN EN VIVO. Cualquier [data-lf-chat] (la conversación de
      un ticket en Ayuda o en Tickets) pregunta cada pocos segundos si hay
      mensajes nuevos y los agrega, y su formulario envía sin recargar.
      No hay WebSockets: el servidor es PHP clásico, y preguntar cada 4
      segundos con `?desde=` el último que ya se tiene es barato y no
      necesita nada más instalado.

   2. NOVEDADES. Para quien puede abrir reportes (el cliente), cada 20
      segundos se pregunta si soporte le contestó algo que no ha visto: el
      menú "Ayuda" lleva la cuenta y sale un aviso dentro de la plataforma,
      además del correo que ya se manda.

   3. CHAT FLOTANTE. Con la respuesta de soporte se le abre al cliente un
      chat en la esquina, esté en la pantalla que esté. Cambiar de sección
      sin recargar no lo cierra; recargar lo vuelve a abrir.

   Se carga una sola vez desde el layout. Las conversaciones de las
   páginas se ligan con LFChat.enlazar(), que las vistas llaman al final
   (y se vuelve a llamar al cambiar de sección sin recargar).
   ══════════════════════════════════════════════════════ */
(function () {
  if (window.LFChat) return;

  var CADA_CHAT = 3000;        // conversación abierta
  var CADA_NOVEDADES = 20000;  // aviso de respuestas
  var CADA_ESCRIBE = 3000;     // "estoy escribiendo", como mucho cada 3 s

  function token() { return document.body.getAttribute('data-lf-token') || ''; }

  /* GET o POST que espera JSON. Si el servidor contesta otra cosa (la
     sesión venció, la página de login), devuelve null. */
  function pedir(url, opciones) {
    opciones = opciones || {};
    opciones.credentials = 'same-origin';
    opciones.headers = { 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' };
    return fetch(url, opciones)
      .then(function (r) { return r.text(); })
      .then(function (t) { try { return JSON.parse(t); } catch (e) { return null; } });
  }

  function crear(tag, clase, texto) {
    var n = document.createElement(tag);
    if (clase) n.className = clase;
    if (texto !== undefined && texto !== null) n.textContent = texto;
    return n;
  }

  /* El texto va como TEXTO, nunca como HTML: lo escribió una persona. */
  function parrafo(texto) {
    var p = crear('p');
    String(texto).split('\n').forEach(function (linea, i) {
      if (i) p.appendChild(document.createElement('br'));
      p.appendChild(document.createTextNode(linea));
    });
    return p;
  }

  /* Un mensaje, con la misma forma que el que pinta el servidor. */
  function pintar(m, lado) {
    var caja = crear('div', 'lf-msj nuevo' + (m.interno ? ' interno' : '') + (m.mio ? ' mio' : ''));
    caja.setAttribute('data-id', m.id);
    var av = crear('span', 'lf-av' + ((m.interno || (lado === 'cliente' && m.mio)) ? ' gris' : '')
                          + (m.foto ? ' con-foto' : ''), m.inicial);
    if (m.foto) av.style.backgroundImage = "url('" + String(m.foto).replace(/'/g, '%27') + "')";
    caja.appendChild(av);

    var cuerpo = crear('div', 'cuerpo');
    var cab = crear('div', 'cab');
    cab.appendChild(crear('b', '', m.autor));
    if (m.interno) cab.appendChild(crear('span', 'badge bg-secondary', 'Nota interna'));
    if (lado === 'soporte' && m.tipo === 'empresa') cab.appendChild(crear('span', 'badge bg-secondary', 'Cliente'));
    cab.appendChild(crear('span', 'fecha', m.fecha));
    cuerpo.appendChild(cab);
    cuerpo.appendChild(parrafo(m.cuerpo));
    if (m.adjunto) {
      var a = crear('a', 'adj', lado === 'soporte' ? 'Ver evidencia' : 'Ver archivo');
      a.href = m.adjunto; a.target = '_blank'; a.rel = 'noopener';
      cuerpo.appendChild(a);
    }
    caja.appendChild(cuerpo);
    return caja;
  }

  function avisoEn(form, texto) {
    var p = form.querySelector('[data-lf-error]');
    if (!p) { p = crear('p', 'lf-chat-error'); p.setAttribute('data-lf-error', ''); form.appendChild(p); }
    p.textContent = texto;
    p.hidden = false;
  }

  /* "Ana está escribiendo…" con los tres puntos. Va siempre al final de
     la lista; lo nuevo se inserta antes que él. */
  function indicador(lista, nombre) {
    var ind = lista.querySelector('[data-lf-escribe-ind]');
    if (!nombre) { if (ind) ind.remove(); return; }
    if (!ind) {
      ind = crear('div', 'lf-escribe');
      ind.setAttribute('data-lf-escribe-ind', '');
      ind.setAttribute('aria-live', 'polite');
      var pts = crear('span', 'puntos');
      pts.appendChild(crear('i')); pts.appendChild(crear('i')); pts.appendChild(crear('i'));
      ind.appendChild(pts);
      ind.appendChild(crear('small'));
      lista.appendChild(ind);
    }
    ind.querySelector('small').textContent = nombre + ' está escribiendo…';
  }

  /* ══ 1 · Una conversación en vivo ══ */
  function conversacion(raiz) {
    if (raiz._lfChat) return raiz._lfChat;
    var url   = raiz.getAttribute('data-lf-chat');
    var urlEscribe = raiz.getAttribute('data-lf-escribe');
    var lado  = raiz.getAttribute('data-lf-lado') || 'cliente';
    var flota = raiz.hasAttribute('data-lf-flota');
    var lista = raiz.querySelector('[data-lf-lista]') || raiz;
    var sel   = raiz.getAttribute('data-lf-form');
    var form  = sel ? document.querySelector(sel) : raiz.querySelector('form[data-lf-enviar]');
    var ultimo = +raiz.getAttribute('data-lf-ultimo') || 0;
    var reloj = null, ocupado = false, avisado = 0;

    function viva() { return document.body.contains(raiz); }

    /* Mientras se teclea, se le avisa al otro lado (como mucho cada 3 s).
       Una nota interna no: el cliente no debe saber que se escribe algo
       que no va a ver. */
    function tecleando() {
      if (!urlEscribe || !form) return;
      var ta = form.querySelector('textarea');
      if (!ta || !ta.value.trim()) return;
      var interno = form.querySelector('input[name=interno]');
      if (interno && interno.checked) return;
      var ahora = Date.now();
      if (ahora - avisado < CADA_ESCRIBE) return;
      avisado = ahora;
      var fd = new FormData();
      fd.append('token', (form.querySelector('input[name=token]') || {}).value || token());
      pedir(urlEscribe, { method: 'POST', body: fd }).catch(function () {});
    }

    function traer() {
      if (!viva()) { parar(); return; }
      if (ocupado || document.hidden) return;
      ocupado = true;
      pedir(url + (url.indexOf('?') === -1 ? '?' : '&') + 'desde=' + ultimo).then(function (j) {
        ocupado = false;
        if (!j) { parar(); return; }          // la sesión venció: no insistir
        if (!j.ok) return;
        var nuevos = (j.mensajes || []).filter(function (m) { return m.id > ultimo; });
        var abajo = flota || lista.scrollHeight - lista.scrollTop - lista.clientHeight < 80;
        if (nuevos.length) {
          var vacio = raiz.querySelector('[data-lf-vacio]');
          if (vacio) vacio.hidden = true;
          var ind = lista.querySelector('[data-lf-escribe-ind]');
          nuevos.forEach(function (m) { lista.insertBefore(pintar(m, lado), ind); ultimo = m.id; });
        }
        // El otro lado está tecleando: los tres puntos.
        indicador(lista, j.escribiendo || null);
        if (abajo && (nuevos.length || j.escribiendo)) lista.scrollTop = lista.scrollHeight;
        if (j.cerrado && form) {
          form.hidden = true;
          if (!raiz.querySelector('[data-lf-cerrado]')) {
            var c = crear('p', 'lf-chat-cerrado', 'Este reporte está cerrado.');
            c.setAttribute('data-lf-cerrado', '');
            (flota ? raiz : lista).appendChild(c);
          }
        }
      }).catch(function () { ocupado = false; });
    }

    function parar() { if (reloj) clearInterval(reloj); reloj = null; }

    if (form && !form._lfChat) {
      form._lfChat = true;
      form.addEventListener('input', function (e) {
        if (e.target.tagName === 'TEXTAREA') tecleando();
      });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var ta  = form.querySelector('textarea');
        var btn = form.querySelector('[type=submit]');
        if (!ta || !ta.value.trim()) { if (ta) ta.focus(); return; }
        if (btn) { btn.disabled = true; btn.setAttribute('data-antes', btn.textContent); btn.textContent = 'Enviando…'; }
        var err = form.querySelector('[data-lf-error]');
        if (err) err.hidden = true;
        pedir(form.action, { method: 'POST', body: new FormData(form) }).then(function (j) {
          if (btn) { btn.disabled = false; btn.textContent = btn.getAttribute('data-antes') || 'Enviar'; }
          if (j && j.ok) {
            ta.value = '';
            avisado = 0;
            var f = form.querySelector('input[type=file]');
            if (f) { f.value = ''; f.dispatchEvent(new Event('change', { bubbles: true })); }
            var interno = form.querySelector('input[name=interno]');
            if (interno) interno.checked = false;
            traer();
            ta.focus();
          } else {
            avisoEn(form, (j && (j.mensaje || j.error)) || 'No se pudo enviar. Revisa tu conexión.');
          }
        }).catch(function () {
          if (btn) { btn.disabled = false; btn.textContent = btn.getAttribute('data-antes') || 'Enviar'; }
          avisoEn(form, 'No se pudo enviar. Revisa tu conexión.');
        });
      });
      /* En el chat flotante Enter envía y Shift+Enter hace salto de línea. */
      if (form.hasAttribute('data-lf-enter')) {
        form.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey && e.target.tagName === 'TEXTAREA') {
            e.preventDefault();
            if (form.requestSubmit) form.requestSubmit();
            else form.dispatchEvent(new Event('submit', { cancelable: true }));
          }
        });
      }
    }

    if (flota) lista.scrollTop = lista.scrollHeight;
    traer();
    reloj = setInterval(traer, CADA_CHAT);
    raiz._lfChat = { traer: traer, parar: parar };
    return raiz._lfChat;
  }

  function enlazar(ambito) {
    [].forEach.call((ambito || document).querySelectorAll('[data-lf-chat]'), conversacion);
  }

  /* ══ 2 y 3 · Novedades y chat flotante (solo el cliente) ══ */
  var panel = null;

  function leer(k)      { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function guardar(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function sesionLeer(k)  { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function sesionGuardar(k, v) { try { if (v === null) sessionStorage.removeItem(k); else sessionStorage.setItem(k, v); } catch (e) {} }

  function enPaginaDe(id) {
    if (location.pathname !== '/ayuda') return false;
    var m = /[?&]ver=(\d+)/.exec(location.search);
    return !!m && +m[1] === +id;
  }

  /* La cuenta en el menú "Ayuda". */
  function marcarMenu(n) {
    [].forEach.call(document.querySelectorAll('a[href="/ayuda"]'), function (a) {
      var b = a.querySelector('.lf-noti-num');
      if (!n) { if (b) b.remove(); return; }
      if (!b) { b = crear('span', 'lf-noti-num'); a.appendChild(b); }
      b.textContent = n > 9 ? '9+' : n;
      b.title = n === 1 ? '1 respuesta de soporte sin leer' : n + ' respuestas de soporte sin leer';
    });
  }

  /* El aviso dentro de la plataforma. */
  function avisar(t) {
    var viejo = document.querySelector('.lf-toast-chat');
    if (viejo) viejo.remove();
    var caja = crear('div', 'lf-toast-chat');
    caja.setAttribute('role', 'status');
    var txt = crear('div', 'txt');
    txt.appendChild(crear('b', '', t.autor + ' te respondió'));
    txt.appendChild(crear('small', '', t.folio + ' · ' + t.extracto));
    caja.appendChild(txt);
    var ver = crear('button', 'btn btn-primary btn-sm', 'Ver');
    ver.type = 'button';
    ver.addEventListener('click', function () { caja.remove(); abrirChat(t); });
    var x = crear('button', 'x', '×');
    x.type = 'button'; x.setAttribute('aria-label', 'Cerrar aviso');
    x.addEventListener('click', function () { caja.remove(); });
    caja.appendChild(ver); caja.appendChild(x);
    document.body.appendChild(caja);
    setTimeout(function () { if (caja.parentNode) caja.classList.add('va'); }, 9000);
    setTimeout(function () { if (caja.parentNode) caja.remove(); }, 9600);
  }

  /* ── La burbuja: vuelve a abrir el chat después de cerrarlo ──
     Se ve mientras el chat está cerrado y hay un reporte vivo. Lleva la
     cuenta de lo que soporte escribió y no se ha leído. */
  var burbuja = null, ultimoActivo = null, pendiente = null;

  function pintarBurbuja(sinLeer) {
    var hay = !!(pendiente || ultimoActivo);
    if (!hay || panel) { if (burbuja) burbuja.hidden = true; return; }
    if (!burbuja) {
      burbuja = crear('button', 'lf-chat-burbuja');
      burbuja.type = 'button';
      burbuja.setAttribute('aria-label', 'Abrir el chat con soporte');
      burbuja.title = 'Chat con soporte';
      burbuja.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.4c-.6.5-1.3 0-1.3-.6V16A2.5 2.5 0 0 1 4 13.5z"/></svg>';
      burbuja.appendChild(crear('span', 'lf-noti-num'));
      burbuja.addEventListener('click', function () {
        var t = pendiente || ultimoActivo;
        if (t) abrirChat(t);
      });
      document.body.appendChild(burbuja);
    }
    burbuja.hidden = false;
    var num = burbuja.querySelector('.lf-noti-num');
    num.hidden = !sinLeer;
    num.textContent = sinLeer > 9 ? '9+' : (sinLeer || '');
  }

  function cerrarChat() {
    if (panel) { if (panel._lfChat) panel._lfChat.parar(); panel.remove(); panel = null; }
    sesionGuardar('lf_chat_abierto', null);
    pintarBurbuja(0);
  }

  function abrirChat(t) {
    if (panel && +panel.getAttribute('data-ticket') === +t.id) {
      panel.classList.remove('min');
      var ta0 = panel.querySelector('textarea'); if (ta0) ta0.focus();
      return;
    }
    if (panel) { if (panel._lfChat) panel._lfChat.parar(); panel.remove(); panel = null; }
    if (burbuja) burbuja.hidden = true;
    sesionGuardar('lf_chat_abierto', JSON.stringify({ id: t.id, folio: t.folio || '', asunto: t.asunto || '' }));

    panel = crear('div', 'lf-chatf');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Chat con soporte');
    panel.setAttribute('data-ticket', t.id);
    panel.setAttribute('data-lf-chat', '/ayuda/' + (+t.id) + '/mensajes');
    panel.setAttribute('data-lf-escribe', '/ayuda/' + (+t.id) + '/escribiendo');
    panel.setAttribute('data-lf-lado', 'cliente');
    panel.setAttribute('data-lf-flota', '');

    var cab = crear('header');
    var tit = crear('div', 'tit');
    tit.appendChild(crear('b', '', 'Soporte LibertyFin'));
    tit.appendChild(crear('small', '', (t.folio ? t.folio + ' · ' : '') + (t.asunto || '')));
    cab.appendChild(tit);
    var ir = crear('a', 'bt', '↗');
    ir.href = '/ayuda?ver=' + (+t.id); ir.title = 'Ver el reporte completo';
    var min = crear('button', 'bt', '–'); min.type = 'button'; min.title = 'Minimizar';
    var x = crear('button', 'bt', '×'); x.type = 'button'; x.title = 'Cerrar';
    min.addEventListener('click', function () { panel.classList.toggle('min'); });
    x.addEventListener('click', cerrarChat);
    cab.addEventListener('click', function (e) {
      if (panel.classList.contains('min') && !e.target.closest('.bt')) panel.classList.remove('min');
    });
    cab.appendChild(ir); cab.appendChild(min); cab.appendChild(x);
    panel.appendChild(cab);

    var lista = crear('div', 'lista');
    lista.setAttribute('data-lf-lista', '');
    var vacio = crear('p', 'lf-chat-vacio', 'Cargando la conversación…');
    vacio.setAttribute('data-lf-vacio', '');
    lista.appendChild(vacio);
    panel.appendChild(lista);

    var form = crear('form');
    form.method = 'post';
    form.action = '/ayuda/' + (+t.id) + '/responder';
    form.enctype = 'multipart/form-data';
    form.setAttribute('data-lf-enviar', '');
    form.setAttribute('data-lf-enter', '');
    var tk = crear('input'); tk.type = 'hidden'; tk.name = 'token'; tk.value = token();
    var ta = crear('textarea'); ta.name = 'cuerpo'; ta.rows = 2; ta.placeholder = 'Escribe tu respuesta…';
    var pie = crear('div', 'pie');
    var fid = 'lfChatAdj' + t.id;
    var lf = crear('span', 'lf-file');
    var inp = crear('input'); inp.type = 'file'; inp.name = 'adjunto'; inp.id = fid;
    inp.accept = 'image/png,image/jpeg,image/webp,application/pdf';
    var lab = crear('label', 'bt', 'Adjuntar'); lab.htmlFor = fid; lab.title = 'Adjuntar captura o archivo';
    var nom = crear('span', 'n', ''); nom.setAttribute('data-vacio', '');
    lf.appendChild(inp); lf.appendChild(lab); lf.appendChild(nom);
    var env = crear('button', 'btn btn-primary btn-sm', 'Enviar'); env.type = 'submit';
    pie.appendChild(lf); pie.appendChild(env);
    form.appendChild(tk); form.appendChild(ta); form.appendChild(pie);
    panel.appendChild(form);

    document.body.appendChild(panel);
    conversacion(panel);
    ta.focus();
  }

  function clienteNovedades() {
    if (document.body.getAttribute('data-lf-ayuda') !== '1' || !window.fetch) return;

    function revisar() {
      if (document.hidden) return;
      pedir('/ayuda/novedades').then(function (j) {
        if (!j || !j.ok) return;
        marcarMenu(j.sin_leer);
        ultimoActivo = j.activo || null;
        pendiente = (j.tickets && j.tickets[0]) || null;
        pintarBurbuja(j.sin_leer);
        if (!j.tickets || !j.tickets.length) return;
        var t = j.tickets[0];
        // En la página de ese ticket la conversación ya se actualiza sola.
        if (enPaginaDe(t.id)) return;
        // Cada mensaje se avisa UNA vez, aunque se recargue la página.
        var clave = 'lf_chat_avisado_' + t.id;
        if ((+leer(clave) || 0) >= t.mensaje_id) return;
        guardar(clave, t.mensaje_id);
        avisar(t);
        abrirChat(t);
      }).catch(function () {});
    }

    // Si estaba abierto antes de recargar, se vuelve a abrir.
    var antes = sesionLeer('lf_chat_abierto');
    if (antes) { try { abrirChat(JSON.parse(antes)); } catch (e) {} }

    setTimeout(revisar, 1500);
    setInterval(revisar, CADA_NOVEDADES);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) revisar(); });
  }

  /* Cualquier botón [data-lf-abrir-chat='{"id":…}'] abre ese reporte en el
     chat flotante (por ejemplo, desde la página del reporte). */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-lf-abrir-chat]');
    if (!b) return;
    e.preventDefault();
    try { abrirChat(JSON.parse(b.getAttribute('data-lf-abrir-chat'))); } catch (err) {}
  });

  window.LFChat = { enlazar: enlazar, abrir: abrirChat };

  function arrancar() { enlazar(document); clienteNovedades(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
  else arrancar();
})();
