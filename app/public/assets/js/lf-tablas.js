/* Marca las tablas que de verdad se desbordan (para mostrar «Desliza»). */
(function(){
  function revisar(){
    document.querySelectorAll(".table-responsive.lf-cards").forEach(function(t){
      t.classList.toggle("lf-desborda", t.scrollWidth > t.clientWidth + 2);
      if (!t.nextElementSibling || !t.nextElementSibling.classList.contains("lf-desliza")) {
        var p = document.createElement("p"); p.className = "lf-desliza";
        p.textContent = "Desliza la tabla hacia los lados para ver todas las columnas →";
        t.parentNode.insertBefore(p, t.nextSibling);
      }
    });
  }
  window.addEventListener("resize", revisar);
  document.addEventListener("lf:cargado", revisar);
  document.addEventListener("DOMContentLoaded", revisar);
  setTimeout(revisar, 400);
})();
