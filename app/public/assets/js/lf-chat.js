/* ══════════════════════════════════════════════════════
   LibertyFin · el chat de soporte

   Cuatro piezas:

   1. CONVERSACIÓN EN VIVO. Cualquier [data-lf-chat] (la conversación de
      un ticket en Ayuda o en Tickets) se queda "escuchando": pide con
      `esperar=1` y el servidor no contesta hasta que hay algo nuevo (un
      mensaje, o el otro lado empezó o dejó de escribir), como mucho 20 s.
      En cuanto contesta se vuelve a pedir. Así un mensaje llega en menos
      de un segundo, sin WebSockets ni nada más instalado en el servidor.
      Con la pestaña en segundo plano no se espera: se pregunta cada 10 s.

      Lo que uno envía aparece AL MOMENTO ("Enviando…") y se cambia por el
      mensaje de verdad cuando el servidor lo guarda. Si falla, se queda
      marcado con "Reintentar".

      Además: emojis, pegar una captura con Ctrl+V (o arrastrarla) y la
      vista previa de lo que se va a adjuntar. La imagen se achica (WebP)
      y empieza a subir en cuanto se adjunta, mientras se termina de
      escribir: al dar Enviar ya está arriba y el mensaje sale al momento.

   2. NOVEDADES DEL CLIENTE. Para quien puede abrir reportes, cada 20 s se
      pregunta si soporte le contestó algo que no ha visto: el menú "Ayuda"
      lleva la cuenta y sale un aviso dentro de la plataforma.

   3. CHAT FLOTANTE (cliente). Con la respuesta de soporte se le abre un
      chat en la esquina, esté en la pantalla que esté. Arriba lleva sus
      reportes sin resolver para cambiar de uno a otro sin salir.

   4. NOVEDADES DE SOPORTE. Para quien atiende tickets: una campana arriba
      con los mensajes de clientes que no ha leído, la cuenta en el menú y en el
      título de la pestaña, un aviso con sonido cuando un cliente escribe
      y, con la pestaña en segundo plano, un aviso del escritorio.

   Se carga una sola vez desde el layout. Las conversaciones de las
   páginas se ligan con LFChat.enlazar(), que las vistas llaman al final
   (y se vuelve a llamar al cambiar de sección sin recargar).
   ══════════════════════════════════════════════════════ */
(function () {
  if (window.LFChat) return;

  var CADA_OCULTA = 10000;     // pestaña en segundo plano: sin espera, cada 10 s
  var CADA_NOVEDADES = 20000;  // aviso de respuestas
  var CADA_ESCRIBE = 2500;     // "estoy escribiendo", como mucho cada 2.5 s
  var MAX_ARCHIVO = 10485760;  // 10 MB, lo mismo que acepta el servidor
  var TIPOS = /^(image\/(png|jpeg|webp)|application\/pdf)$/;

  var EMOJIS = ('😀 😃 😄 😁 😅 😂 🙂 😉 😊 😍 🤩 😎 🤔 🤨 😐 😕 😟 😢 😭 😤 😡 😴 '
              + '🙏 👍 👎 👌 👏 🙌 💪 🤝 👋 👀 ✅ ❌ ⚠️ ❓ ❗ 💡 🔥 🎉 ❤️ 💯 '
              + '⏳ 📎 📷 🧾 💳 💰 📦 🖨️ 📞 ✉️ 🔒 🚀').split(' ');

  function token() { return document.body.getAttribute('data-lf-token') || ''; }
  function yo()    { return document.body.getAttribute('data-lf-yo') || ''; }

  /* GET o POST que espera JSON. Si el servidor contesta otra cosa (la
     sesión venció, la página de login), devuelve null. */
  function pedir(url, opciones) {
    opciones = opciones || {};
    opciones.credentials = 'same-origin';
    opciones.headers = { 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' };
    return fetch(url, opciones)
      .then(function (r) {
        // La sesión ya no existe (venció, o se cerró desde otro lado) y
        // el servidor mandó a la pantalla de entrar: allá vamos.
        if (r.redirected && /\/login\/?(\?|$)/.test(String(r.url).replace(location.origin, ''))) {
          aEntrar();
          return '';
        }
        return r.text();
      })
      .then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) { return null; }
        if (j && j.sesion_cerrada) aEntrar();
        return j;
      });
  }

  /* La cuenta se abrió en otro dispositivo y esta sesión se cerró (ver
     Servicio\SesionUnica): a la pantalla de entrar, que dice por qué. */
  var saliendo = false;
  function aEntrar() {
    if (saliendo) return;
    saliendo = true;
    location.href = '/login';
  }

  /* POST con archivo. Va por XMLHttpRequest y no por fetch porque fetch
     no dice cuánto se ha subido, y con una imagen pesada eso es justo lo
     que se quiere ver. Igual que `pedir`: null si no contesta JSON. */
  function subir(url, datos, avance, alAbrir) {
    return new Promise(function (listo, fallo) {
      var x = new XMLHttpRequest();
      x.open('POST', url, true);
      x.setRequestHeader('X-LF-Json', '1');
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      if (alAbrir) alAbrir(x);   // para poder cancelarla
      if (x.upload && avance) {
        x.upload.onprogress = function (e) { if (e.lengthComputable && e.total) avance(e.loaded / e.total); };
      }
      x.onload = function () {
        var j = null;
        try { j = JSON.parse(x.responseText); } catch (e) {}
        if (j && j.sesion_cerrada) aEntrar();
        listo(j);
      };
      x.onerror = x.onabort = x.ontimeout = function () { fallo(new Error('red')); };
      x.send(datos);
    });
  }

  /* ── El círculo de carga sobre lo que se está subiendo ──
     Sobre la miniatura: fondo oscuro y un anillo que se llena con el
     porcentaje. Junto a un PDF: el anillo chico. Ya subido, mientras el
     servidor lo guarda, el anillo gira. */
  var VUELTA = 97.39;   // largo del anillo: 2π · 15.5
  function anillo(chica) {
    var c = crear('span', 'lf-carga gira' + (chica ? ' chica' : ''));
    c.setAttribute('role', 'progressbar');
    c.setAttribute('aria-label', 'Subiendo el archivo');
    c.setAttribute('aria-valuemin', '0');
    c.setAttribute('aria-valuemax', '100');
    c.innerHTML = '<svg viewBox="0 0 36 36" aria-hidden="true">'
      + '<circle class="pista" cx="18" cy="18" r="15.5"/><circle class="avance" cx="18" cy="18" r="15.5"/></svg>';
    c.appendChild(crear('small'));
    return c;
  }
  function ponerCarga(nodo) {
    var donde = nodo.querySelector('.adj-img') || nodo.querySelector('.adj');
    if (!donde || donde.querySelector('.lf-carga')) return;
    donde.appendChild(anillo(donde.classList.contains('adj')));
    nodo.classList.add('con-carga');
  }
  function avanceCarga(nodo, p) {
    var c = nodo.querySelector('.lf-carga');
    if (c) avanzar(c, p);
  }
  function avanzar(c, p) {
    var pct = Math.max(0, Math.min(100, Math.round(p * 100)));
    var arco = c.querySelector('.avance');
    if (pct >= 100) {
      // Ya subió: ahora lo guarda el servidor. Gira hasta que llegue.
      c.classList.add('gira');
      arco.style.strokeDashoffset = '';
      c.querySelector('small').textContent = '';
      c.removeAttribute('aria-valuenow');
      return;
    }
    c.classList.remove('gira');
    arco.style.strokeDashoffset = (VUELTA * (1 - pct / 100)).toFixed(2);
    c.querySelector('small').textContent = pct + '%';
    c.setAttribute('aria-valuenow', String(pct));
  }
  function quitarCarga(nodo) {
    var c = nodo.querySelector('.lf-carga');
    if (c) c.remove();
    nodo.classList.remove('con-carga');
  }

  function crear(tag, clase, texto) {
    var n = document.createElement(tag);
    if (clase) n.className = clase;
    if (texto !== undefined && texto !== null) n.textContent = texto;
    return n;
  }

  function boton(clase, texto, titulo) {
    var b = crear('button', clase, texto);
    b.type = 'button';
    if (titulo) { b.title = titulo; b.setAttribute('aria-label', titulo); }
    return b;
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

  function normal(t) { return String(t || '').replace(/\s+/g, ' ').trim(); }
  function esImagen(url) { return /\.(png|jpe?g|webp|gif)(\?|$)/i.test(String(url || '')); }
  function peso(b) { return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1) + ' MB'; }

  /* Un mensaje, con la misma forma que el que pinta el servidor. Una
     imagen adjunta se ve en miniatura; un PDF, como enlace. */
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
      var a;
      if (m.esImagen || esImagen(m.adjunto)) {
        a = crear('a', 'adj-img');
        var img = crear('img');
        img.src = m.adjunto; img.alt = 'Imagen adjunta'; img.loading = 'lazy';
        a.appendChild(img);
      } else {
        a = crear('a', 'adj', lado === 'soporte' ? 'Ver evidencia' : 'Ver archivo');
      }
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

  /* Hasta abajo de la lista; suave cuando llega algo mientras se mira. */
  function bajar(lista, suave) {
    if (suave && lista.scrollTo) lista.scrollTo({ top: lista.scrollHeight, behavior: 'smooth' });
    else lista.scrollTop = lista.scrollHeight;
  }

  /* Quita un elemento con su animación de salida (clase `sale`), no de
     golpe. Si no hay animación (movimiento reducido), se va al momento. */
  function despedir(n, ms) {
    if (!n || !n.parentNode || n._saliendo) return;
    n._saliendo = true;
    n.classList.add('sale');
    setTimeout(function () { if (n.parentNode) n.remove(); }, ms || 260);
  }

  /* "Ana está escribiendo…" con los tres puntos. Va siempre al final de
     la lista; lo nuevo se inserta antes que él. */
  function indicador(lista, nombre) {
    var ind = lista.querySelector('[data-lf-escribe-ind]');
    if (!nombre) {
      // Se va con su animación; sin la marca, lo nuevo ya no se pone antes.
      if (ind) { ind.removeAttribute('data-lf-escribe-ind'); despedir(ind, 200); }
      return;
    }
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

  /* ── El título de la pestaña ──
     "💬" delante: llegó un mensaje con la pestaña en segundo plano.
     "(3)" delante: tickets que esperan respuesta (soporte). Se arma sobre
     el título de la página, que cambia al navegar sin recargar. */
  var marcaChat = false, cuentaTitulo = 0;
  var PREFIJO = /^(💬 )?(\(\d+\+?\) )?/;
  function pintarTitulo() {
    var base = document.title.replace(PREFIJO, '');
    var pre = (marcaChat ? '💬 ' : '')
            + (cuentaTitulo ? '(' + (cuentaTitulo > 9 ? '9+' : cuentaTitulo) + ') ' : '');
    if (document.title !== pre + base) document.title = pre + base;
  }
  function avisoTitulo() { marcaChat = true; pintarTitulo(); }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && marcaChat) { marcaChat = false; pintarTitulo(); }
  });
  document.addEventListener('lf:cargado', pintarTitulo);

  /* ── Emojis ──
     Un botón junto a "Adjuntar" abre la tabla; el emoji va donde está el
     cursor y la tabla se queda abierta para poner varios. */
  var emojiAbierto = null;
  function cerrarEmojis() {
    if (!emojiAbierto) return;
    emojiAbierto.classList.remove('abierto');
    emojiAbierto.querySelector('.lf-emoji-bt').setAttribute('aria-expanded', 'false');
    emojiAbierto = null;
  }
  document.addEventListener('click', function (e) {
    if (emojiAbierto && !emojiAbierto.contains(e.target)) cerrarEmojis();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && emojiAbierto) {
      var ta = emojiAbierto._lfTa;
      cerrarEmojis();
      if (ta) ta.focus();
    }
  });

  function insertar(ta, txt) {
    var a = ta.selectionStart, b = ta.selectionEnd;
    if (typeof a !== 'number' || document.activeElement !== ta && a === 0 && b === 0 && ta.value) {
      // Sin cursor conocido, al final.
      a = b = ta.value.length;
    }
    ta.value = ta.value.slice(0, a) + txt + ta.value.slice(b);
    ta.selectionStart = ta.selectionEnd = a + txt.length;
    ta.focus();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function selectorEmojis(ta) {
    var caja = crear('span', 'lf-emoji');
    caja._lfTa = ta;
    var bt = boton('lf-emoji-bt', '😊', 'Emojis');
    bt.setAttribute('aria-haspopup', 'true');
    bt.setAttribute('aria-expanded', 'false');
    var pop = crear('div', 'lf-emoji-pop');
    pop.setAttribute('role', 'menu');
    EMOJIS.forEach(function (em) {
      if (!em) return;
      var b = boton('', em);
      b.setAttribute('role', 'menuitem');
      b.addEventListener('click', function () { insertar(ta, em); });
      pop.appendChild(b);
    });
    bt.addEventListener('click', function () {
      var abrir = emojiAbierto !== caja;
      cerrarEmojis();
      if (!abrir) return;
      caja.classList.add('abierto');
      bt.setAttribute('aria-expanded', 'true');
      emojiAbierto = caja;
      // Que no se salga de la pantalla (en el celular el botón puede
      // quedar a media fila): se recorre lo que haga falta.
      pop.style.left = '';
      var r = pop.getBoundingClientRect(), ancho = document.documentElement.clientWidth;
      var mover = 0;
      if (r.right > ancho - 8) mover = (ancho - 8) - r.right;
      if (r.left + mover < 8) mover = 8 - r.left;
      if (mover) pop.style.left = mover + 'px';
    });
    caja.appendChild(bt);
    caja.appendChild(pop);
    return caja;
  }

  /* ── Adjuntos: elegir, pegar (Ctrl+V) o arrastrar ──
     Lo pegado no pasa por el <input type=file> (no en todos los
     navegadores se puede escribir en él): se guarda en el formulario y se
     agrega al enviar. La vista previa muestra lo que se va a mandar. */
  function etiqueta(form, texto) {
    var n = form.querySelector('.lf-file .n');
    if (!n) return;
    n.textContent = texto || n.getAttribute('data-vacio') || '';
    n.classList.toggle('hay', !!texto);
  }

  function vistaPrevia(form, archivo, nombre) {
    var v = form._lfVista;
    if (!v) return;
    if (v._url) { URL.revokeObjectURL(v._url); v._url = null; }
    v.innerHTML = '';
    if (!archivo) { v.hidden = true; return; }
    // La miniatura va en su cajita: encima se pinta el avance de la subida.
    var mini = crear('span', 'mini');
    if (/^image\//.test(archivo.type)) {
      v._url = URL.createObjectURL(archivo);
      var img = crear('img'); img.src = v._url; img.alt = '';
      mini.appendChild(img);
    } else {
      mini.appendChild(crear('span', 'doc', 'PDF'));
    }
    v.appendChild(mini);
    var txt = crear('span', 'txt');
    txt.appendChild(crear('b', '', nombre));
    var tam = crear('small', '', peso(archivo.size));
    tam.setAttribute('data-peso', peso(archivo.size));
    txt.appendChild(tam);
    v.appendChild(txt);
    var x = boton('x', '×', 'Quitar el adjunto');
    x.addEventListener('click', function () { quitarAdjunto(form); });
    v.appendChild(x);
    v.classList.remove('lista');
    v.hidden = false;
  }

  /* Quita lo adjunto. Si se estaba subiendo por adelantado, se cancela. */
  function quitarAdjunto(form) {
    var adj = form._lfAdj;
    if (adj && adj.subida && !adj.subida.listo && adj.subida.xhr) {
      try { adj.subida.xhr.abort(); } catch (e) {}
    }
    form._lfAdj = null;
    form._lfTurno = (form._lfTurno || 0) + 1;   // lo que se esté preparando ya no vale
    form._lfPreparando = false;
    var inp = form.querySelector('input[type=file]');
    if (inp && inp.value) {
      form._lfSilencio = true; inp.value = '';
      inp.dispatchEvent(new Event('change', { bubbles: true }));
      form._lfSilencio = false;
    }
    vistaPrevia(form, null);
    etiqueta(form, '');
  }

  /* ── SE SUBE EN CUANTO SE ADJUNTA ──
     Mientras la persona termina de escribir, la imagen ya va subiendo. Al
     dar Enviar el mensaje solo lleva su clave y sale al instante. Si esta
     subida falla, no pasa nada: el archivo se manda con el mensaje, como
     antes. */
  function urlAdjunto(form) {
    var u = form.getAttribute('action') || '';
    return /\/responder$/.test(u) ? u.replace(/\/responder$/, '/adjunto') : '';
  }

  function presubir(form) {
    var adj = form._lfAdj, url = urlAdjunto(form);
    if (!adj || !url || !window.XMLHttpRequest || !window.Promise
        || !window.FormData || !FormData.prototype.set) return;
    var s = { avance: 0, clave: '', listo: false, fallo: false, oyentes: [], xhr: null };
    adj.subida = s;
    var fd = new FormData();
    fd.append('token', (form.querySelector('input[name=token]') || {}).value || token());
    fd.append('adjunto', adj.blob, adj.nombre);
    function contar() { s.oyentes.forEach(function (f) { f(s); }); }
    s.promesa = subir(url, fd, function (p) { s.avance = p; contar(); }, function (x) { s.xhr = x; })
      .then(function (j) {
        if (j && j.ok && j.clave) { s.clave = j.clave; s.listo = true; s.avance = 1; }
        else s.fallo = true;
        contar();
        return s;
      }, function () { s.fallo = true; contar(); return s; });
    s.oyentes.push(function () { avanceVista(form, s); });
    avanceVista(form, s);
  }

  /* El avance en la vista previa: el anillo sobre la miniatura y el
     porcentaje en el texto; al terminar, "lista para enviar". */
  function avanceVista(form, s) {
    var v = form._lfVista;
    if (!v || v.hidden || !form._lfAdj || form._lfAdj.subida !== s) return;
    var mini = v.querySelector('.mini'), tam = v.querySelector('.txt small');
    var c = mini && mini.querySelector('.lf-carga');
    var base = tam ? tam.getAttribute('data-peso') : '';
    if (s.listo || s.fallo) {
      if (c) c.remove();
      if (tam) tam.textContent = base + (s.listo ? ' · lista para enviar' : '');
      v.classList.toggle('lista', s.listo);
      return;
    }
    if (!c && mini) { c = anillo(false); mini.appendChild(c); }
    if (c) avanzar(c, s.avance);
    if (tam) tam.textContent = base + ' · subiendo ' + Math.round(s.avance * 100) + '%';
  }

  /* Si se dio Enviar mientras la imagen se preparaba, ahora sí. */
  function enviarSiEsperaba(form) {
    if (!form._lfEnviarLuego) return;
    form._lfEnviarLuego = false;
    if (form.requestSubmit) form.requestSubmit();
    else form.dispatchEvent(new Event('submit', { cancelable: true }));
  }

  function fechaArchivo() {
    var d = new Date(), z = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + z(d.getMonth() + 1) + z(d.getDate()) + '-' + z(d.getHours()) + z(d.getMinutes()) + z(d.getSeconds());
  }

  /* LAS IMÁGENES SE ACHICAN ANTES DE SUBIR. Una captura en PNG pesa de 2
     a 5 MB; en WebP, del mismo tamaño en pantalla y con el texto igual de
     nítido, unos 300 KB: sube diez veces más rápido. Lo que ya es ligero
     se manda tal cual. Si el navegador no sabe hacer WebP, JPEG. Y si el
     resultado no pesa menos, se queda el original. */
  var LIGERO = 358400;   // 350 KB
  function aligerar(blob, listo) {
    if (blob.size <= LIGERO || !/^image\/(png|jpeg|webp)$/.test(blob.type) || !window.createImageBitmap) { listo(blob); return; }
    createImageBitmap(blob).then(function (bmp) {
      var max = 2560, k = Math.min(1, max / Math.max(bmp.width, bmp.height));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(bmp.width * k)); c.height = Math.max(1, Math.round(bmp.height * k));
      var g = c.getContext('2d');
      g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
      g.drawImage(bmp, 0, 0, c.width, c.height);
      if (bmp.close) bmp.close();
      c.toBlob(function (w) {
        if (w && w.type === 'image/webp' && w.size < blob.size) { listo(w); return; }
        c.toBlob(function (j) { listo(j && j.size < blob.size ? j : blob); }, 'image/jpeg', 0.86);
      }, 'image/webp', 0.86);
    }, function () { listo(blob); });
  }

  /* Pegado, arrastrado o elegido: todo pasa por aquí. Se achica, se
     enseña y empieza a subir de una vez. */
  function ponerArchivo(form, blob, deDonde) {
    if (!blob) return;
    if (!TIPOS.test(blob.type)) {
      avisoEn(form, 'Solo se pueden adjuntar imágenes (JPG, PNG, WebP) o PDF.');
      if (deDonde === 'elegido') quitarAdjunto(form);   // que no se mande de todos modos
      return;
    }
    var err = form.querySelector('[data-lf-error]'); if (err) err.hidden = true;
    quitarAdjunto(form);                       // lo de antes se reemplaza
    var turno = form._lfTurno;
    form._lfPreparando = true;
    aligerar(blob, function (b) {
      if (form._lfTurno !== turno) return;     // mientras tanto se quitó o se eligió otro
      form._lfPreparando = false;
      if (b.size > MAX_ARCHIVO) {
        avisoEn(form, 'El archivo pesa ' + peso(b.size) + '; el máximo es 10 MB.');
        form._lfEnviarLuego = false;
        return;
      }
      var ext = b.type === 'application/pdf' ? 'pdf' : b.type === 'image/jpeg' ? 'jpg' : b.type.split('/')[1];
      var nombre = (deDonde === 'pegado' ? 'captura-' + fechaArchivo() : (blob.name || 'archivo').replace(/\.[^.]+$/, '')) + '.' + ext;
      form._lfAdj = { blob: b, nombre: nombre, subida: null };
      vistaPrevia(form, b, nombre);
      etiqueta(form, nombre);
      presubir(form);
      if (form._lfEnviarLuego) { enviarSiEsperaba(form); return; }
      var ta = form.querySelector('textarea'); if (ta) ta.focus();
    });
  }

  function mejorar(form, zona) {
    if (form._lfMejorado) return;
    form._lfMejorado = true;
    // La validación la hace el chat: una captura sola, sin texto, también vale.
    form.noValidate = true;
    var ta  = form.querySelector('textarea');
    var pie = form.querySelector('.pie, .lf-resp-pie');
    var inp = form.querySelector('input[type=file]');

    if (ta && pie) pie.insertBefore(selectorEmojis(ta), pie.querySelector('.lf-file') || pie.firstChild);

    var vista = crear('div', 'lf-adj-vista');
    vista.hidden = true;
    if (pie) pie.parentNode.insertBefore(vista, pie); else form.appendChild(vista);
    form._lfVista = vista;

    // Lo elegido con el botón también se achica y sube por adelantado.
    // (Cancelar el diálogo no quita lo que ya estaba adjunto.)
    if (inp) inp.addEventListener('change', function () {
      if (form._lfSilencio) return;
      var f = inp.files && inp.files[0];
      if (f) ponerArchivo(form, f, 'elegido');
    });

    // La zona (el chat flotante) se queda al cambiar de reporte y el
    // formulario no: se escucha una sola vez y se usa el formulario de ahora.
    zona._lfForm = form;
    if (zona._lfZona) return;
    zona._lfZona = true;

    // Ctrl+V con una captura. Si lo copiado también trae texto (una celda
    // de Excel trae las dos cosas), se pega el texto, como siempre.
    zona.addEventListener('paste', function (e) {
      var cd = e.clipboardData;
      if (!cd || !cd.items || (cd.getData && cd.getData('text/plain'))) return;
      for (var i = 0; i < cd.items.length; i++) {
        var it = cd.items[i];
        if (it.kind === 'file' && /^image\//.test(it.type)) {
          var f = it.getAsFile();
          if (f) { e.preventDefault(); ponerArchivo(zona._lfForm, f, 'pegado'); return; }
        }
      }
    });

    // Arrastrar un archivo encima.
    var arrastres = 0;
    function conArchivos(e) { return e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') !== -1; }
    zona.addEventListener('dragenter', function (e) { if (!conArchivos(e)) return; arrastres++; zona.classList.add('lf-soltar'); });
    zona.addEventListener('dragleave', function () { if (--arrastres <= 0) { arrastres = 0; zona.classList.remove('lf-soltar'); } });
    zona.addEventListener('dragover',  function (e) { if (conArchivos(e)) e.preventDefault(); });
    zona.addEventListener('drop', function (e) {
      if (!conArchivos(e)) return;
      e.preventDefault();
      arrastres = 0; zona.classList.remove('lf-soltar');
      var f = e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) ponerArchivo(zona._lfForm, f, 'arrastrado');
    });
  }

  /* ══ 1 · Una conversación en vivo ══ */
  var alEnviar = [];   // quién más quiere saber que se envió algo (las novedades)

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
    var activo = true, reloj = null, ctrl = null, fallos = 0, rapidas = 0;
    var conoce = '', avisado = 0, cola = [], enviando = false;

    function viva() { return document.body.contains(raiz); }

    /* Mientras se teclea, se le avisa al otro lado (como mucho cada
       2.5 s). Una nota interna no: el cliente no debe saber que se
       escribe algo que no va a ver. */
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
      pedir(urlEscribe, { method: 'POST', body: fd }).then(null, function () {});
    }

    /* Lo enviado que todavía no confirma el servidor. */
    function pendientes() { return lista.querySelectorAll('.lf-msj.enviando'); }

    /* ¿Cuál de lo que se está enviando es este mensaje que ya llegó? Por
       el id que contestó el servidor; si llegó antes que la respuesta,
       por el texto. */
    function pendienteDe(m) {
      var ps = pendientes(), i, t = normal(m.cuerpo);
      for (i = 0; i < ps.length; i++) if (+ps[i].getAttribute('data-espera') === m.id) return ps[i];
      for (i = 0; i < ps.length; i++) if (!ps[i].hasAttribute('data-espera') && ps[i]._lfTexto === t) return ps[i];
      return null;
    }

    function soltar(nodo) {
      if (nodo && nodo._lfUrl) { URL.revokeObjectURL(nodo._lfUrl); nodo._lfUrl = null; }
    }

    /* Lo que contestó el servidor. Devuelve si cambió algo. */
    function recibir(j) {
      var cambio = false;
      var nuevos = (j.mensajes || []).filter(function (m) { return m.id > ultimo; });
      var abajo = flota || lista.scrollHeight - lista.scrollTop - lista.clientHeight < 80;
      if (nuevos.length) {
        cambio = true;
        var vacio = raiz.querySelector('[data-lf-vacio]');
        if (vacio) vacio.hidden = true;
        nuevos.forEach(function (m) {
          var nodo = pintar(m, lado);
          var p = m.mio ? pendienteDe(m) : null;
          if (p) {
            // El "Enviando…" se vuelve el mensaje de verdad, sin saltar.
            nodo.classList.remove('nuevo');
            // La imagen del servidor tarda un momento en bajar: mientras,
            // se sigue viendo la que ya estaba, sin parpadeo.
            var img = nodo.querySelector('.adj-img img');
            if (img && p._lfUrl) {
              var local = p._lfUrl, remota = img.src, pre = new Image();
              p._lfUrl = null;
              img.src = local;
              pre.onload = pre.onerror = function () { img.src = remota; URL.revokeObjectURL(local); };
              pre.src = remota;
            }
            lista.replaceChild(nodo, p);
            soltar(p);
          } else {
            // Lo de los demás va antes de lo mío que sigue enviándose.
            lista.insertBefore(nodo, pendientes()[0] || lista.querySelector('[data-lf-escribe-ind]'));
          }
          ultimo = Math.max(ultimo, m.id);
        });
        if (document.hidden && nuevos.some(function (m) { return !m.mio; })) avisoTitulo();
      }
      // El otro lado está tecleando: los tres puntos.
      var esc = j.escribiendo || '';
      if (esc !== conoce) { conoce = esc; cambio = true; }
      indicador(lista, esc || null);
      if (abajo && (nuevos.length || esc)) bajar(lista, true);
      if (j.cerrado && form && !form.hidden) {
        cambio = true;
        form.hidden = true;
        if (!raiz.querySelector('[data-lf-cerrado]')) {
          var c = crear('p', 'lf-chat-cerrado', 'Este reporte está cerrado.');
          c.setAttribute('data-lf-cerrado', '');
          (flota ? raiz : lista).appendChild(c);
        }
      }
      return cambio;
    }

    function programar(ms) {
      if (!activo) return;
      clearTimeout(reloj);
      reloj = setTimeout(ciclo, ms);
    }

    /* La vuelta de escucha: pide, espera la respuesta (hasta 20 s si hay
       nada nuevo) y vuelve a pedir. */
    function ciclo() {
      reloj = null;
      if (!activo) return;
      if (!viva()) { parar(); return; }
      if (ctrl) return;                       // ya hay una en curso
      var oculto = document.hidden;
      var q = 'desde=' + ultimo + (oculto ? '' : '&esperar=1&escribe=' + encodeURIComponent(conoce));
      var mio = window.AbortController ? new AbortController() : {};
      ctrl = mio;
      var t0 = Date.now();
      pedir(url + (url.indexOf('?') === -1 ? '?' : '&') + q, mio.signal ? { signal: mio.signal } : {}).then(function (j) {
        if (ctrl === mio) ctrl = null;
        if (!activo) return;
        if (!j) { parar(); return; }          // la sesión venció: no insistir
        fallos = 0;
        var cambio = j.ok ? recibir(j) : false;
        // Si algo entre medio corta la espera y contesta al instante sin
        // novedades, no se martilla al servidor.
        rapidas = (!oculto && !cambio && Date.now() - t0 < 700) ? rapidas + 1 : 0;
        programar(document.hidden ? CADA_OCULTA : (rapidas > 3 ? 3000 : 60));
      }, function (e) {
        if (ctrl === mio) ctrl = null;
        if (!activo || (e && e.name === 'AbortError')) return;
        fallos++;
        programar(Math.min(30000, 1500 * fallos));
      });
    }

    function alVolver() {
      // Volvió a la pestaña: se escucha de nuevo al momento.
      if (!document.hidden && activo && !ctrl) { clearTimeout(reloj); ciclo(); }
    }

    function parar() {
      activo = false;
      clearTimeout(reloj); reloj = null;
      if (ctrl && ctrl.abort) ctrl.abort();
      ctrl = null;
      document.removeEventListener('visibilitychange', alVolver);
    }

    /* ── Enviar: aparece al momento y se manda en orden ── */
    function siguiente() {
      if (enviando || !cola.length) return;
      enviando = true;
      var item = cola[0], s = item.subida;
      // ¿La imagen sigue subiendo desde que se adjuntó? El globo sigue
      // ese mismo avance; el mensaje sale en cuanto termine.
      if (s && !s.listo && !s.fallo) {
        avanceCarga(item.nodo, s.avance);
        s.oyentes.push(function () { if (!s.listo && !s.fallo) avanceCarga(item.nodo, s.avance); });
      }
      (s ? s.promesa : Promise.resolve(null)).then(function () {
        var fd = item.fd;
        // Ya está en el servidor: el mensaje solo lleva su clave.
        if (s && s.listo) {
          fd.set('adjunto_previo', s.clave);
          avanceCarga(item.nodo, 1);
          return pedir(form.action, { method: 'POST', body: fd });
        }
        // Si no, el archivo va con el mensaje, viendo el avance.
        if (item.blob) {
          if (fd.delete) fd.delete('adjunto_previo');
          fd.set('adjunto', item.blob, item.nombre);
        }
        return item.conArchivo && window.XMLHttpRequest
          ? subir(form.action, fd, function (p) { avanceCarga(item.nodo, p); })
          : pedir(form.action, { method: 'POST', body: fd });
      }).then(function (j) {
        enviando = false; cola.shift();
        if (j && j.ok) {
          alEnviar.forEach(function (f) { f(); });
          if (item.nodo.parentNode && item.nodo.classList.contains('enviando')) {
            // Si el mensaje ya llegó por la escucha, sobra el "Enviando…".
            if (!j.id || j.id <= ultimo) { soltar(item.nodo); item.nodo.remove(); }
            else item.nodo.setAttribute('data-espera', j.id);
          }
        } else {
          fallo(item, (j && (j.mensaje || j.error)) || 'No se pudo enviar.');
        }
        siguiente();
      }, function () {
        enviando = false; cola.shift();
        fallo(item, 'Sin conexión. Revisa tu internet.');
        siguiente();
      });
    }

    function fallo(item, texto) {
      var n = item.nodo;
      quitarCarga(n);
      n.classList.remove('enviando');
      n.classList.add('fallo');
      var f = n.querySelector('.fecha'); if (f) f.textContent = 'No se envió';
      var barra = crear('div', 'lf-reintento');
      barra.appendChild(crear('small', '', texto));
      var otra = boton('', 'Reintentar');
      var tirar = boton('', 'Descartar');
      otra.addEventListener('click', function () {
        barra.remove();
        n.classList.remove('fallo'); n.classList.add('enviando');
        if (f) f.textContent = 'Enviando…';
        if (item.conArchivo) ponerCarga(n);
        // Al reintentar, el archivo va con el mensaje: lo subido por
        // adelantado pudo haberse perdido (sesión nueva, por ejemplo).
        if (item.blob) item.subida = null;
        cola.push(item); siguiente();
      });
      tirar.addEventListener('click', function () { soltar(n); despedir(n, 260); });
      barra.appendChild(otra); barra.appendChild(tirar);
      n.querySelector('.cuerpo').appendChild(barra);
    }

    if (form && !form._lfChat) {
      form._lfChat = true;
      mejorar(form, flota ? raiz : form);
      form.addEventListener('input', function (e) {
        if (e.target.tagName === 'TEXTAREA') tecleando();
      });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        // La imagen todavía se está achicando (una fracción de segundo):
        // se envía en cuanto quede, sin perderla.
        if (form._lfPreparando) { form._lfEnviarLuego = true; return; }
        var ta  = form.querySelector('textarea');
        var texto = ta ? ta.value.trim() : '';
        var adj = form._lfAdj;
        var inp = form.querySelector('input[type=file]');
        var elegido = !adj && inp && inp.files && inp.files[0];
        if (!texto && !adj && !elegido) { if (ta) ta.focus(); return; }
        var err = form.querySelector('[data-lf-error]');
        if (err) err.hidden = true;

        // El archivo no va en este FormData: o ya se subió (va su clave)
        // o se agrega al momento de mandarlo (ver siguiente()).
        var fd = new FormData(form);
        var item = { fd: fd, blob: null, nombre: '', subida: null };
        if (adj) {
          if (fd.set && fd.delete) {
            fd.delete('adjunto');
            item.blob = adj.blob; item.nombre = adj.nombre; item.subida = adj.subida || null;
          } else {
            fd.append('adjunto', adj.blob, adj.nombre);
          }
        }
        var interno = form.querySelector('input[name=interno]');

        // Al momento en la lista, como "Enviando…".
        var archivo = adj ? adj.blob : elegido;
        var urlLocal = archivo ? URL.createObjectURL(archivo) : '';
        var nombre = yo();
        var nodo = pintar({
          id: 0, mio: true, tipo: '', foto: '',
          autor: lado === 'cliente' ? 'Tú' : (nombre || 'Tú'),
          inicial: (nombre.trim().charAt(0) || '?').toUpperCase(),
          interno: !!(interno && interno.checked),
          cuerpo: texto || 'Adjuntó un archivo.',
          adjunto: urlLocal, esImagen: !!(archivo && /^image\//.test(archivo.type)),
          fecha: 'Enviando…'
        }, lado);
        nodo.classList.add('enviando');
        nodo._lfTexto = normal(texto || 'Adjuntó un archivo.');
        nodo._lfUrl = urlLocal;
        if (archivo) ponerCarga(nodo);
        var vacio = raiz.querySelector('[data-lf-vacio]');
        if (vacio) vacio.hidden = true;
        lista.insertBefore(nodo, lista.querySelector('[data-lf-escribe-ind]'));
        bajar(lista, true);

        // El formulario queda listo para el siguiente, sin esperar.
        if (ta) ta.value = '';
        avisado = 0;
        form._lfAdj = null;
        if (inp && inp.value) { form._lfSilencio = true; inp.value = ''; inp.dispatchEvent(new Event('change', { bubbles: true })); form._lfSilencio = false; }
        vistaPrevia(form, null);
        etiqueta(form, '');
        if (interno) interno.checked = false;
        cerrarEmojis();
        if (ta) ta.focus();

        item.nodo = nodo;
        item.conArchivo = !!archivo;
        cola.push(item);
        siguiente();
      });
      /* En el chat flotante Enter envía y Shift+Enter hace salto de línea. */
      if (form.hasAttribute('data-lf-enter')) {
        form.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.target.tagName === 'TEXTAREA') {
            e.preventDefault();
            if (form.requestSubmit) form.requestSubmit();
            else form.dispatchEvent(new Event('submit', { cancelable: true }));
          }
        });
      }
    }

    if (flota) bajar(lista, false);
    document.addEventListener('visibilitychange', alVolver);
    ciclo();
    raiz._lfChat = { parar: parar };
    return raiz._lfChat;
  }

  function enlazar(ambito) {
    [].forEach.call((ambito || document).querySelectorAll('[data-lf-chat]'), conversacion);
  }

  /* ══ Piezas compartidas por las novedades ══ */
  function leer(k)      { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function guardar(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function sesionLeer(k)  { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function sesionGuardar(k, v) { try { if (v === null) sessionStorage.removeItem(k); else sessionStorage.setItem(k, v); } catch (e) {} }

  /* La cuenta en un enlace del menú (el lateral y la barra del celular;
     no en los botones "Volver" que apuntan al mismo lado). */
  function marcarEnlace(href, n, uno, varios) {
    var s = 'a[href="' + href + '"]';
    [].forEach.call(document.querySelectorAll('.lf-nav ' + s + ', .lf-dedo ' + s), function (a) {
      var b = a.querySelector('.lf-noti-num');
      if (!n) { if (b) b.remove(); return; }
      if (!b) { b = crear('span', 'lf-noti-num'); a.appendChild(b); }
      var t = n > 9 ? '9+' : String(n);
      if (b.textContent !== t) {
        b.textContent = t;
        b.classList.remove('pop'); void b.offsetWidth; b.classList.add('pop');
      }
      b.title = n === 1 ? uno : n + ' ' + varios;
    });
  }

  /* Ir a una página por el mismo camino que un clic (la navegación sin
     recargar lo toma si está). */
  function ir(href) {
    var a = crear('a'); a.href = href; a.hidden = true; a.setAttribute('data-parcial', '');
    document.body.appendChild(a); a.click(); a.remove();
  }

  /* El aviso dentro de la plataforma. */
  function avisar(titulo, linea, alVer) {
    despedir(document.querySelector('.lf-toast-chat'), 200);
    var caja = crear('div', 'lf-toast-chat');
    caja.setAttribute('role', 'status');
    var ico = crear('span', 'ico');
    ico.innerHTML = ICONO_CHAT;
    caja.appendChild(ico);
    var txt = crear('div', 'txt');
    txt.appendChild(crear('b', '', titulo));
    txt.appendChild(crear('small', '', linea));
    caja.appendChild(txt);
    var ver = boton('btn btn-primary btn-sm', 'Ver');
    ver.addEventListener('click', function () { despedir(caja, 200); alVer(); });
    var x = boton('x', '×', 'Cerrar aviso');
    x.addEventListener('click', function () { despedir(caja, 200); });
    caja.appendChild(ver); caja.appendChild(x);
    document.body.appendChild(caja);
    setTimeout(function () { despedir(caja, 320); }, 9000);
  }

  var ICONO_CHAT = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" '
    + 'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M12 3.5c4.9 0 8.5 3.3 8.5 7.6s-3.6 7.6-8.5 7.6c-1 0-2-.1-2.9-.4L5 20l1.1-3.6C4.5 15 3.5 13.2 3.5 11.1 3.5 6.8 7.1 3.5 12 3.5z"/>'
    + '<path d="M8.6 11.2h.01M12 11.2h.01M15.4 11.2h.01" stroke-width="2.6"/></svg>';

  /* ══ 2 y 3 · Novedades y chat flotante (el cliente) ══ */
  var panel = null;
  var burbuja = null, ultimoActivo = null, pendiente = null, sinLeerAhora = 0;
  var activos = [];          // sus reportes sin resolver, para cambiar entre ellos
  var borradores = {};       // lo que iba escribiendo en cada uno
  var revisarCliente = function () {};

  function enPaginaDe(id) {
    if (location.pathname !== '/ayuda') return false;
    var m = /[?&]ver=(\d+)/.exec(location.search);
    return !!m && +m[1] === +id;
  }

  /* ── La burbuja: vuelve a abrir el chat después de cerrarlo ──
     Se ve mientras el chat está cerrado y hay un reporte vivo. Lleva la
     cuenta de lo que soporte escribió y no se ha leído, y al pasar el
     cursor se abre en una pastilla que dice qué es. */
  function pintarBurbuja(sinLeer) {
    if (typeof sinLeer === 'number') sinLeerAhora = sinLeer;
    var hay = !!(pendiente || ultimoActivo);
    if (!burbuja && hay) {
      burbuja = crear('button', 'lf-chat-burbuja oculta');
      burbuja.type = 'button';
      burbuja.setAttribute('aria-label', 'Abrir el chat con soporte');
      var ico = crear('span', 'ico');
      ico.innerHTML = ICONO_CHAT;
      burbuja.appendChild(ico);
      burbuja.appendChild(crear('span', 'lbl', 'Soporte'));
      burbuja.appendChild(crear('span', 'lf-noti-num'));
      burbuja.addEventListener('click', function () {
        var t = pendiente || ultimoActivo;
        if (t) abrirChat(t);
      });
      document.body.appendChild(burbuja);
      // Un cuadro después, para que la entrada se anime.
      requestAnimationFrame(function () { requestAnimationFrame(function () { pintarBurbuja(); }); });
      return;
    }
    if (!burbuja) return;
    // Se esconde (con animación) mientras el chat está abierto.
    burbuja.classList.toggle('oculta', !hay || !!panel);
    burbuja.classList.toggle('con-nuevos', sinLeerAhora > 0);
    var num = burbuja.querySelector('.lf-noti-num');
    var texto = sinLeerAhora > 9 ? '9+' : String(sinLeerAhora || '');
    if (num.textContent !== texto) {
      num.textContent = texto;
      // El número "salta" al cambiar, para que se note.
      num.classList.remove('pop'); void num.offsetWidth; if (sinLeerAhora) num.classList.add('pop');
    }
    num.hidden = !sinLeerAhora;
  }

  /* Las pestañas de arriba del chat: sus reportes sin resolver. Solo
     salen si hay más de uno al cual cambiar. */
  function pintarPestanas() {
    if (!panel) return;
    var tira = panel.querySelector('.lf-chatf-tickets');
    var actual = +panel.getAttribute('data-ticket');
    var lista = activos.slice();
    if (lista.length && !lista.some(function (a) { return a.id === actual; })) {
      lista.unshift({ id: actual, folio: panel._lfFolio || '', asunto: panel._lfAsunto || '', sin_leer: 0 });
    }
    tira.hidden = lista.length < 2;
    tira.innerHTML = '';
    lista.forEach(function (a) {
      var b = boton('pes' + (a.id === actual ? ' on' : ''), null, a.asunto || a.folio);
      b.setAttribute('aria-current', a.id === actual ? 'true' : 'false');
      b.appendChild(crear('b', '', a.folio || ('#' + a.id)));
      b.appendChild(crear('span', 'as', a.asunto || ''));
      if (a.sin_leer && a.id !== actual) b.appendChild(crear('span', 'lf-noti-num', a.sin_leer > 9 ? '9+' : a.sin_leer));
      b.addEventListener('click', function () { if (a.id !== actual) abrirChat(a); });
      tira.appendChild(b);
    });
    var on = tira.querySelector('.on');
    if (on && on.scrollIntoView && !tira.hidden) on.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  function cerrarChat() {
    if (panel) {
      var p = panel;
      panel = null;
      if (p._lfChat) p._lfChat.parar();
      despedir(p, 240);
    }
    sesionGuardar('lf_chat_abierto', null);
    pintarBurbuja();
  }

  function crearPanel() {
    panel = crear('div', 'lf-chatf');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Chat con soporte');
    panel.setAttribute('data-lf-lado', 'cliente');
    panel.setAttribute('data-lf-flota', '');

    var cab = crear('header');
    var tit = crear('div', 'tit');
    tit.appendChild(crear('b', '', 'Soporte LibertyFin'));
    tit.appendChild(crear('small'));
    cab.appendChild(tit);
    var irA = crear('a', 'bt ir', '↗'); irA.title = 'Ver el reporte completo';
    var min = boton('bt', '–', 'Minimizar');
    var x = boton('bt', '×', 'Cerrar');
    var p = panel;
    min.addEventListener('click', function () { p.classList.toggle('min'); });
    x.addEventListener('click', cerrarChat);
    cab.addEventListener('click', function (e) {
      if (p.classList.contains('min') && !e.target.closest('.bt')) p.classList.remove('min');
    });
    cab.appendChild(irA); cab.appendChild(min); cab.appendChild(x);
    panel.appendChild(cab);

    var tira = crear('div', 'lf-chatf-tickets');
    tira.setAttribute('role', 'tablist');
    tira.setAttribute('aria-label', 'Tus reportes abiertos');
    tira.hidden = true;
    panel.appendChild(tira);

    document.body.appendChild(panel);
  }

  /* El cuerpo del chat para un reporte: la lista y el formulario. Al
     cambiar de reporte se reemplaza solo esto; el panel se queda. */
  function armarCuerpo(t) {
    var lista = crear('div', 'lista');
    lista.setAttribute('data-lf-lista', '');
    var vacio = crear('p', 'lf-chat-vacio', 'Cargando la conversación…');
    vacio.setAttribute('data-lf-vacio', '');
    lista.appendChild(vacio);

    var form = crear('form');
    form.method = 'post';
    form.action = '/ayuda/' + (+t.id) + '/responder';
    form.enctype = 'multipart/form-data';
    form.setAttribute('data-lf-enviar', '');
    form.setAttribute('data-lf-enter', '');
    var tk = crear('input'); tk.type = 'hidden'; tk.name = 'token'; tk.value = token();
    var ta = crear('textarea'); ta.name = 'cuerpo'; ta.rows = 2;
    ta.placeholder = 'Escribe tu respuesta… (puedes pegar una captura)';
    ta.value = borradores[t.id] || '';
    ta.addEventListener('input', function () { borradores[t.id] = ta.value; });
    var pie = crear('div', 'pie');
    var fid = 'lfChatAdj' + t.id;
    var lf = crear('span', 'lf-file');
    var inp = crear('input'); inp.type = 'file'; inp.name = 'adjunto'; inp.id = fid;
    inp.accept = 'image/png,image/jpeg,image/webp,application/pdf';
    var lab = crear('label', 'bt', 'Adjuntar'); lab.htmlFor = fid; lab.title = 'Adjuntar captura o archivo';
    var nom = crear('span', 'n', 'o pega con Ctrl+V'); nom.setAttribute('data-vacio', 'o pega con Ctrl+V');
    lf.appendChild(inp); lf.appendChild(lab); lf.appendChild(nom);
    var env = crear('button', 'btn btn-primary btn-sm', 'Enviar'); env.type = 'submit';
    pie.appendChild(lf); pie.appendChild(env);
    form.appendChild(tk); form.appendChild(ta); form.appendChild(pie);
    form.addEventListener('submit', function () { borradores[t.id] = ''; });
    return { lista: lista, form: form, ta: ta };
  }

  function abrirChat(t) {
    if (panel && +panel.getAttribute('data-ticket') === +t.id) {
      panel.classList.remove('min');
      var ta0 = panel.querySelector('textarea'); if (ta0) ta0.focus();
      return;
    }
    if (!panel) crearPanel();
    else {
      // Cambiar de reporte: se deja de escuchar el anterior.
      if (panel._lfChat) panel._lfChat.parar();
      panel._lfChat = null;
      [].forEach.call(panel.querySelectorAll('.lista, form, [data-lf-cerrado]'), function (n) { n.remove(); });
      panel.classList.remove('min');
    }
    sesionGuardar('lf_chat_abierto', JSON.stringify({ id: t.id, folio: t.folio || '', asunto: t.asunto || '' }));

    panel.setAttribute('data-ticket', t.id);
    panel.setAttribute('data-lf-chat', '/ayuda/' + (+t.id) + '/mensajes');
    panel.setAttribute('data-lf-escribe', '/ayuda/' + (+t.id) + '/escribiendo');
    panel._lfFolio = t.folio || '';
    panel._lfAsunto = t.asunto || '';
    panel.querySelector('header small').textContent = (t.folio ? t.folio + ' · ' : '') + (t.asunto || '');
    panel.querySelector('header .ir').href = '/ayuda?ver=' + (+t.id);

    var c = armarCuerpo(t);
    panel.appendChild(c.lista);
    panel.appendChild(c.form);
    pintarPestanas();
    pintarBurbuja();            // la burbuja se va mientras el chat está abierto
    conversacion(panel);
    c.ta.focus({ preventScroll: true });
  }

  function clienteNovedades() {
    if (document.body.getAttribute('data-lf-ayuda') !== '1' || !window.fetch) return;

    function revisar() {
      if (document.hidden) return;
      pedir('/ayuda/novedades').then(function (j) {
        if (!j || !j.ok) return;
        marcarEnlace('/ayuda', j.sin_leer, '1 respuesta de soporte sin leer', 'respuestas de soporte sin leer');
        activos = j.activos || [];
        ultimoActivo = j.activo || null;
        pendiente = (j.tickets && j.tickets[0]) || null;
        pintarBurbuja(j.sin_leer);
        pintarPestanas();
        if (!j.tickets || !j.tickets.length) return;
        var t = j.tickets[0];
        // En la página de ese ticket, o con su chat abierto, la
        // conversación ya se actualiza sola.
        if (enPaginaDe(t.id) || (panel && +panel.getAttribute('data-ticket') === t.id)) return;
        // Cada mensaje se avisa UNA vez, aunque se recargue la página.
        var clave = 'lf_chat_avisado_' + t.id;
        if ((+leer(clave) || 0) >= t.mensaje_id) return;
        guardar(clave, t.mensaje_id);
        avisar(t.autor + ' te respondió', t.folio + ' · ' + t.extracto, function () { abrirChat(t); });
        // Si ya tiene otro reporte abierto en el chat, no se le cambia de
        // golpe: el aviso y la pestaña marcada bastan.
        if (!panel) abrirChat(t);
      }, function () {});
    }
    revisarCliente = revisar;
    alEnviar.push(function () { setTimeout(revisar, 800); });

    // Si estaba abierto antes de recargar, se vuelve a abrir.
    var antes = sesionLeer('lf_chat_abierto');
    if (antes) { try { abrirChat(JSON.parse(antes)); } catch (e) {} }

    setTimeout(revisar, 1200);
    setInterval(revisar, CADA_NOVEDADES);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) revisar(); });
  }

  /* ══ 4 · Novedades de soporte ══
     Los mensajes de clientes que quien atiende NO ha leído, en sus
     tickets y en los que nadie ha tomado. En cuanto abre el ticket deja
     de salir (aunque no haya contestado todavía); si el cliente vuelve a
     escribir, sale otra vez. Llegan por cinco lados, para que no se
     escape ninguno:

       · la CAMPANA de arriba, con la lista (quién, de qué empresa, hace
         cuánto, si es nuevo o nadie lo ha tomado);
       · la cuenta en el menú "Tickets" y en el título de la pestaña;
       · un aviso dentro de la plataforma y un sonido corto;
       · un aviso del ESCRITORIO si la pestaña está en segundo plano (hay
         que permitirlo una vez, desde la campana).

     Se pregunta cada 15 s; en segundo plano cada 30 s, que es justo
     cuando el aviso del escritorio sirve. Cada mensaje se avisa UNA vez;
     la primera vez en un navegador no se avisa lo viejo: para eso está
     la lista. */
  var CADA_SOPORTE = 15000, CADA_SOPORTE_OCULTA = 30000;
  var campana = null;                   // lo último que contestó el servidor
  var revisarSoporte = function () {};

  function soporteNovedades() {
    if (document.body.getAttribute('data-lf-soporte') !== '1' || !window.fetch) return;
    var reloj = null, vivo = true;

    function revisar() {
      if (!vivo) return;
      clearTimeout(reloj);
      pedir('/tickets/novedades').then(function (j) {
        if (!j) { vivo = false; return; }          // la sesión venció: no insistir
        if (j.ok) recibirSoporte(j);
        reloj = setTimeout(revisar, document.hidden ? CADA_SOPORTE_OCULTA : CADA_SOPORTE);
      }, function () {
        reloj = setTimeout(revisar, CADA_SOPORTE_OCULTA);
      });
    }
    revisarSoporte = revisar;
    alEnviar.push(function () { setTimeout(revisar, 800); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) revisar(); });
    // La barra de arriba se cambia al navegar: la campana nueva llega vacía.
    // Y si se entró a un ticket, ese ya se leyó: fuera de la lista, y se
    // pregunta de nuevo para tener la cuenta exacta.
    document.addEventListener('lf:cargado', function () {
      var m = /^\/tickets\/(\d+)$/.exec(location.pathname);
      if (m) { yaLeido(m[1]); setTimeout(revisar, 600); }
      pintarCampana();
    });
    var aqui = /^\/tickets\/(\d+)$/.exec(location.pathname);
    if (aqui) yaLeido(aqui[1]);
    setTimeout(revisar, 900);
  }

  function recibirSoporte(j) {
    // El ticket que tienes abierto ya lo estás leyendo: no cuenta, aunque
    // el servidor todavía no haya alcanzado a marcarlo.
    var antes = (j.tickets || []).length;
    j.tickets = (j.tickets || []).filter(function (t) { return !viendo(t.id); });
    j.esperando = Math.max(0, (+j.esperando || 0) - (antes - j.tickets.length));
    campana = j;
    contarCampana();

    var primera = !leer('lf_sop_inicio');
    guardar('lf_sop_inicio', '1');
    var nuevos = (j.tickets || []).filter(function (t) {
      return (+leer('lf_sop_avisado_' + t.id) || 0) < t.mensaje_id;
    });
    nuevos.forEach(function (t) { guardar('lf_sop_avisado_' + t.id, t.mensaje_id); });
    // DENTRO DEL CHAT DE ESE TICKET NO SE AVISA: ni aviso, ni sonido, ni
    // escritorio. Lo que escribe el cliente ya aparece en la conversación.
    // Solo si la pestaña está en segundo plano (no lo está mirando).
    nuevos = nuevos.filter(function (t) { return !viendo(t.id); });
    if (primera || !nuevos.length) return;

    var t = nuevos[0], mas = nuevos.length - 1;
    var titulo = t.autor + (t.empresa ? ' (' + t.empresa + ')' : '')
               + (t.nuevo ? ' abrió un ticket' : ' respondió');
    var linea = t.folio + ' · ' + t.extracto + (mas > 0 ? '  ·  y ' + mas + ' más' : '');
    var abrir = function () { ir('/tickets/' + t.id); };
    if (document.hidden || !document.hasFocus()) escritorio(titulo, linea, t.id, abrir);
    if (!document.hidden) avisar(titulo, linea, abrir);
    sonar();
  }

  /* La cuenta de lo no leído: en el menú, en el título y en la campana. */
  function contarCampana() {
    var n = campana ? (+campana.esperando || 0) : 0;
    marcarEnlace('/tickets', n, '1 ticket con mensajes sin leer', 'tickets con mensajes sin leer');
    cuentaTitulo = n; pintarTitulo();
    pintarCampana();
  }

  /* Ya lo abriste: sale de la lista al momento, sin esperar a la
     siguiente revisión (el servidor lo marca como leído al abrirlo). */
  function yaLeido(id) {
    if (!campana || !campana.tickets) return;
    var antes = campana.tickets.length;
    campana.tickets = campana.tickets.filter(function (t) { return +t.id !== +id; });
    if (campana.tickets.length < antes) {
      campana.esperando = Math.max(0, (+campana.esperando || 0) - 1);
      contarCampana();
    }
  }

  /* "hace 3 min", con los segundos que mide la base. */
  function hace(seg) {
    seg = +seg || 0;
    if (seg < 60) return 'hace un momento';
    var m = Math.floor(seg / 60);
    if (m < 60) return 'hace ' + m + ' min';
    var h = Math.floor(m / 60);
    if (h < 24) return 'hace ' + h + ' h';
    var d = Math.floor(h / 24);
    return d === 1 ? 'hace 1 día' : 'hace ' + d + ' días';
  }

  /* ── La campana ──
     Vive en la barra de arriba (topbar.php la pone solo para soporte).
     El número es cuántos esperan respuesta; el punto que late, que hay
     algo que no se ha visto desde la última vez que se abrió. */
  /* ¿Está viendo ese ticket ahora mismo? Lo que escriba ahí el cliente ya
     lo tiene enfrente en el chat: no es "algo sin ver". */
  function viendo(id) {
    return !document.hidden && location.pathname === '/tickets/' + id;
  }
  function sinVer(t, visto) { return t.mensaje_id > visto && !viendo(t.id); }

  function pintarCampana() {
    var j = campana;
    if (!j) return;
    var n = +j.esperando || 0;
    var visto = +leer('lf_sop_campana_vista') || 0;
    var maximo = (j.tickets || []).reduce(function (a, t) { return sinVer(t, visto) ? Math.max(a, t.mensaje_id) : a; }, 0);
    [].forEach.call(document.querySelectorAll('[data-lf-campana]'), function (c) {
      var num = c.querySelector('.lf-noti-num');
      var txt = n > 9 ? '9+' : String(n || '');
      if (num.textContent !== txt) {
        num.textContent = txt;
        num.classList.remove('pop'); void num.offsetWidth; if (n) num.classList.add('pop');
      }
      num.hidden = !n;
      c.classList.toggle('con-nuevos', maximo > visto);
      var pop = c.querySelector('.lf-campana-pop');
      if (pop && !pop.hidden) llenarCampana(pop);
    });
  }

  function llenarCampana(pop) {
    var j = campana || { esperando: 0, tickets: [] };
    var visto = +leer('lf_sop_campana_vista') || 0;
    pop.innerHTML = '';

    var cab = crear('header');
    cab.appendChild(crear('b', '', 'Mensajes sin leer'));
    if (+j.esperando) cab.appendChild(crear('span', 'cuantos', String(j.esperando)));
    pop.appendChild(cab);

    var lista = crear('div', 'lista');
    if (!(j.tickets || []).length) {
      var nada = crear('div', 'nada');
      nada.appendChild(crear('span', 'ico', '✓'));
      nada.appendChild(crear('b', '', 'Estás al día'));
      nada.appendChild(crear('small', '', 'No hay mensajes de clientes sin leer.'));
      lista.appendChild(nada);
    }
    (j.tickets || []).forEach(function (t) {
      var a = crear('a', 'it' + (sinVer(t, visto) ? ' sin-ver' : '') + (viendo(t.id) ? ' aqui' : ''));
      a.href = '/tickets/' + (+t.id);
      a.setAttribute('data-parcial', '');
      a.appendChild(crear('span', 'pri pri-' + (t.prioridad || 'normal')));
      var tx = crear('span', 'tx');
      var l1 = crear('span', 'l1');
      l1.appendChild(crear('b', '', t.folio));
      if (t.empresa) l1.appendChild(crear('span', 'emp', t.empresa));
      tx.appendChild(l1);
      tx.appendChild(crear('span', 'as', t.asunto || ''));
      tx.appendChild(crear('small', '', (t.autor ? t.autor + ': ' : '') + (t.extracto || '')));
      a.appendChild(tx);
      var meta = crear('span', 'meta');
      meta.appendChild(crear('small', '', hace(t.hace)));
      if (t.nuevo) meta.appendChild(crear('span', 'tag nuevo', 'Nuevo'));
      else if (t.libre) meta.appendChild(crear('span', 'tag', 'Sin asignar'));
      a.appendChild(meta);
      lista.appendChild(a);
    });
    pop.appendChild(lista);

    // El pie: ir a la bandeja y cómo avisar.
    var pie = crear('footer');
    var todos = crear('a', 'todos', 'Ver la bandeja');
    todos.href = '/tickets'; todos.setAttribute('data-parcial', '');
    pie.appendChild(todos);

    var op = crear('div', 'ops');
    var son = crear('label', 'op');
    var chk = crear('input'); chk.type = 'checkbox'; chk.checked = leer('lf_sop_sonido') !== '0';
    chk.addEventListener('change', function () { guardar('lf_sop_sonido', chk.checked ? '1' : '0'); if (chk.checked) sonar(true); });
    son.appendChild(chk); son.appendChild(document.createTextNode(' Sonido'));
    op.appendChild(son);

    if (window.Notification) {
      if (Notification.permission === 'granted') {
        var esc = crear('label', 'op');
        var ce = crear('input'); ce.type = 'checkbox'; ce.checked = leer('lf_sop_escritorio') !== '0';
        ce.addEventListener('change', function () { guardar('lf_sop_escritorio', ce.checked ? '1' : '0'); });
        esc.appendChild(ce); esc.appendChild(document.createTextNode(' Escritorio'));
        op.appendChild(esc);
      } else if (Notification.permission === 'default') {
        var pedirPermiso = boton('permiso', 'Activar avisos del escritorio');
        pedirPermiso.addEventListener('click', function (e) {
          e.stopPropagation();
          var r = Notification.requestPermission(function () { llenarCampana(pop); });
          if (r && r.then) r.then(function () { llenarCampana(pop); });
        });
        op.appendChild(pedirPermiso);
      } else {
        op.appendChild(crear('small', 'bloq', 'Avisos del escritorio bloqueados en el navegador'));
      }
    }
    pie.appendChild(op);
    pop.appendChild(pie);
  }

  function abrirCampana(c) {
    var pop = c.querySelector('.lf-campana-pop');
    if (!pop) {
      pop = crear('div', 'lf-campana-pop');
      pop.setAttribute('role', 'dialog');
      pop.setAttribute('aria-label', 'Mensajes de clientes sin leer');
      c.appendChild(pop);
    }
    pop.hidden = false;
    c.classList.add('abierta');
    c.querySelector('.lf-campana-bt').setAttribute('aria-expanded', 'true');
    llenarCampana(pop);
    // Lo que se ve aquí ya se vio: el punto deja de latir.
    var maximo = ((campana && campana.tickets) || []).reduce(function (a, t) { return Math.max(a, t.mensaje_id); }, 0);
    if (maximo) guardar('lf_sop_campana_vista', maximo);
    c.classList.remove('con-nuevos');
    revisarSoporte();     // y se trae lo más reciente
  }

  function cerrarCampana() {
    [].forEach.call(document.querySelectorAll('[data-lf-campana].abierta'), function (c) {
      c.classList.remove('abierta');
      var pop = c.querySelector('.lf-campana-pop'); if (pop) pop.hidden = true;
      c.querySelector('.lf-campana-bt').setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function (e) {
    var bt = e.target.closest && e.target.closest('.lf-campana-bt');
    if (bt) {
      var c = bt.closest('[data-lf-campana]');
      if (c.classList.contains('abierta')) cerrarCampana(); else abrirCampana(c);
      return;
    }
    // Un clic en un ticket de la lista navega y cierra; fuera, cierra.
    var dentro = e.target.closest && e.target.closest('.lf-campana-pop');
    var it = dentro && e.target.closest('a.it');
    if (it) {
      var m = /\/tickets\/(\d+)/.exec(it.getAttribute('href') || '');
      if (m) yaLeido(m[1]);
    }
    if (!dentro || e.target.closest('a')) cerrarCampana();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrarCampana(); });

  /* ── El sonido ──
     Dos notas cortas y suaves, hechas en el navegador (sin archivo). Los
     navegadores no dejan sonar nada hasta que la persona toca la página
     una vez: el primer clic deja listo el audio. */
  var audio = null;
  function prepararAudio() {
    if (audio) { if (audio.state === 'suspended' && audio.resume) audio.resume(); return; }
    var C = window.AudioContext || window.webkitAudioContext;
    if (!C) return;
    try { audio = new C(); } catch (e) { audio = null; }
  }
  document.addEventListener('pointerdown', prepararAudio, true);
  document.addEventListener('keydown', prepararAudio, true);

  function sonar(siempre) {
    if (!siempre && leer('lf_sop_sonido') === '0') return;
    if (!audio || audio.state !== 'running') return;
    try {
      var t0 = audio.currentTime;
      [[880, 0], [1318.5, 0.13]].forEach(function (nota) {
        var o = audio.createOscillator(), g = audio.createGain();
        o.type = 'sine';
        o.frequency.value = nota[0];
        g.gain.setValueAtTime(0.0001, t0 + nota[1]);
        g.gain.exponentialRampToValueAtTime(0.09, t0 + nota[1] + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + nota[1] + 0.32);
        o.connect(g); g.connect(audio.destination);
        o.start(t0 + nota[1]); o.stop(t0 + nota[1] + 0.36);
      });
    } catch (e) {}
  }

  /* ── El aviso del escritorio ──
     Solo si la pestaña no está a la vista (si lo está, basta el de la
     plataforma) y si se permitió. Al darle clic, abre el ticket. */
  function escritorio(titulo, linea, id, abrir) {
    if (!window.Notification || Notification.permission !== 'granted') return;
    if (leer('lf_sop_escritorio') === '0') return;
    try {
      var n = new Notification(titulo, { body: linea, tag: 'lf-ticket-' + id });
      n.onclick = function () { window.focus(); abrir(); n.close(); };
      setTimeout(function () { n.close(); }, 20000);
    } catch (e) {}
  }

  /* Cualquier botón [data-lf-abrir-chat='{"id":…}'] abre ese reporte en el
     chat flotante (por ejemplo, desde la página del reporte). */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-lf-abrir-chat]');
    if (!b) return;
    e.preventDefault();
    try { abrirChat(JSON.parse(b.getAttribute('data-lf-abrir-chat'))); } catch (err) {}
  });

  /* ══ 5 · ¿Sigue abierta esta sesión? ══
     Si la cuenta se abrió en otro dispositivo (ver Servicio\SesionUnica),
     esta pestaña se entera SOLA: al volver a ella y cada 15 s mientras
     está a la vista. Sin esto solo se enteraba al dar el siguiente clic,
     y mientras tanto parecía que no la habían sacado. */
  function pulsoSesion() {
    if (!window.fetch) return;
    var ultimo = 0;
    function revisar() {
      if (document.hidden || saliendo) return;
      if (Date.now() - ultimo < 3000) return;   // volver y enfocar llegan juntos
      ultimo = Date.now();
      pedir('/sesion/pulso').then(null, function () {});
    }
    setInterval(revisar, 15000);
    document.addEventListener('visibilitychange', revisar);
    window.addEventListener('focus', revisar);
  }

  window.LFChat = { enlazar: enlazar, abrir: abrirChat, revisar: function () { revisarCliente(); } };

  function arrancar() { enlazar(document); clienteNovedades(); soporteNovedades(); pulsoSesion(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
  else arrancar();
})();
