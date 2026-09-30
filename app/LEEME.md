# LibertyFin · reconstrucción

Rebanada vertical completa: fundación + la pantalla de Ventas conectada a
la base real. Corre contra tus datos tal cual.

## Instalar

1. Sube la carpeta completa **fuera** de `public_html`, por ejemplo en
   `/home/juanc141/libertyfin-app/`
2. Apunta el dominio (o un subdominio de pruebas) a `libertyfin-app/public/`
3. `cp config/config.php.ejemplo config/config.php` y llena las credenciales
4. Entra al sistema viejo para tener sesión, y luego abre `/ventas`

Si no puedes mover el DocumentRoot, sube `public/` como `app2/` dentro de
`public_html` y el resto a un nivel arriba.

## Qué demuestra

| | Antes | Ahora |
|---|---|---|
| `ventas_lista.php` | 2,662 líneas | controlador 69 + vista 143 |
| Regla de IVA | en 4 archivos | `src/Dominio/Iva.php` |
| Consultas de ventas | en 77 lugares | `src/Datos/VentaRepo.php` |
| `.env` alcanzable por URL | sí | imposible: vive fuera de `public/` |
| Filtro de fecha | `DATE(v.fecha)` | rango directo, 5× más rápido |

## La estructura

```
libertyfin-app/
├── public/          ← lo ÚNICO que ve la web
│   ├── index.php    ← punto de entrada único
│   ├── .htaccess    ← todo a index.php + gzip + caché
│   └── assets/css/
├── src/
│   ├── autoload.php
│   ├── Dominio/     ← las reglas. Sin SQL, sin HTML, sin sesión.
│   ├── Datos/       ← lo único que toca la base
│   ├── Http/        ← router y lectura de la petición
│   ├── Vista/       ← plantillas y widgets
│   └── Controlador/
└── config/          ← credenciales, fuera del alcance web
```

## Cómo se agrega una pantalla

Tres archivos, ninguno largo:

1. `src/Datos/XRepo.php` — las consultas
2. `src/Controlador/XControlador.php` — decide qué pedir y qué pasar a la vista
3. `src/Vista/plantillas/x/index.php` — el HTML

Y una línea en `public/index.php`:

```php
$r->get('/clientes', ['LibertyFin\Controlador\ClientesControlador', 'index']);
```

## Pantallas

| Ruta | Estado |
|---|---|
| `/` Panel | listo |
| `/ventas` | listo |
| `/caja` | listo |
| `/clientes` | listo |
| `/comisiones` | listo |
| `/servicios` | listo |
| `/ventas/{id}` detalle | listo |
| `/login` | listo |
| `/corte` corte de caja | listo |
| `/cobranza` | listo |
| `/gastos` | listo |
| `/reportes` | listo |
| `/ajustes` | listo · admin y soporte |
| `/mantenimiento` | listo · solo soporte |
| `/usuarios` | listo · pestaña de Ajustes |
| `/cuenta` | listo |

## Notas del esquema

Dos cosas de la base que no son obvias y cuestan caro si se ignoran:

**`productos` no tiene `sucursal_id`.** La relación va por la tabla
`producto_sucursal (producto_id, sucursal_id, stock, stock_minimo)`, y con
LEFT JOIN: un servicio sin renglón ahí sigue siendo vendible, solo que sin
stock propio. Un INNER JOIN lo desaparecería del catálogo.

**El precio de venta es `subprecio`, no `precio`.** Así lo lee el sistema
anterior. En el código nuevo va como
`COALESCE(NULLIF(p.subprecio,0), p.precio)`, con `precio` de respaldo por si
`subprecio` viene en cero.

## Modo oscuro

El tema se aplica en un script dentro del `<head>`, antes de que el navegador
pinte. Si se esperara al final del documento, la página aparecería en claro y
saltaría a oscuro: ese parpadeo blanco es lo que hace que un modo oscuro se
sienta barato.

Se guarda en `localStorage`. **Nunca se hereda del sistema operativo sin que
el usuario lo pida**: si alguien tiene el celular en oscuro y abre la app por
primera vez, la ve en claro.

El botón muestra el icono de lo que vas a OBTENER, no el del estado actual: en
claro se ve la luna.

## Corte de caja

Compara **efectivo contra efectivo**. Transferencias y tarjeta se muestran
aparte porque no pasan por el cajón: sumarlas al corte es la forma más común
de "cuadrar" una caja que en realidad no cuadra.

Si hay diferencia, la nota es obligatoria. Y se guarda **con signo**: positiva
si sobró, negativa si faltó. Guardar el valor absoluto esconde justo lo que
importa saber.

## Asignar comisiones

Se hace desde el detalle de la venta. Eliges colaborador y porcentaje, y ves
en vivo cuánto le tocaría antes de guardar. El porcentaje se sugiere solo con
el que esa persona suele cobrar.

**La base la calcula `Dominio\Comision`, no el formulario.** Esa es toda la
diferencia con `guardar_comision_producto.php` del sistema anterior, que tomaba
`venta_detalles.precio_unitario` tal cual y suponía que venía sin impuesto. Esa
suposición costó tres errores en la migración:

| Venta | Salió | Debía ser |
|---|---|---|
| Leopoldo · Francisco Flores 10% | $672.80 | $580.00 |
| Reyna · base con IVA | $579.98 | $499.98 |
| PW · base con IVA y gastos | $1,478.84 | $999.00 |

Aquí no puede pasar: el precio pasa por `Iva::quitar()` antes de tocar nada.

Reglas que impone el servicio:

- Nadie puede tener dos comisiones en la misma venta
- No se comisiona una venta cancelada
- Si los gastos se comen la utilidad, no deja asignar
- Quitar una comisión la cancela, no la borra, y resincroniza lo devengado

## Alta y edición de clientes y servicios

Un panel plegable arriba de cada listado, hecho con `<details>`: se abre y se
cierra sin una línea de JavaScript. Editar es el mismo formulario con los datos
cargados.

**Clientes.** Valida el RFC con su formato real, el correo, y guarda del
teléfono solo lo que sirve para marcar. Rechaza nombres repetidos: dos clientes
con el mismo nombre son casi siempre captura duplicada, que fue justo lo que
llenó julio de basura.

**Servicios.** El código no se puede repetir: si dos servicios lo comparten, es
imposible saber cuál se vendió. Y el costo no puede superar al precio, porque
eso daría una base comisionable negativa.

Un servicio no se borra nunca, se desactiva. Borrarlo dejaría ventas apuntando
a un producto que ya no existe.

Las validaciones baratas van antes de consultar la base: no tiene sentido ir a
buscar códigos duplicados para terminar rechazando un precio en cero.

## Usuarios y contraseñas

**La contraseña nunca sale del repositorio.** Ningún método la devuelve,
ninguna vista la recibe. Lo único que existe es cambiarla, y siempre pasa por
`password_hash()` con bcrypt.

**Dos caminos distintos, a propósito:**

- Un administrador **restablece** la de otro sin pedir la anterior: es para
  cuando alguien la olvidó.
- Cada quien **cambia la suya** desde `/cuenta`, y ahí sí hay que saber la
  actual. Al cambiarla se cierra la sesión: si alguien la cambió porque
  sospecha que se la sabían, dejar la sesión viva no serviría de nada.

**Las reglas son ocho caracteres y que no sea el nombre de usuario.**
Deliberadamente NO se exige mayúscula, número y símbolo. Esa regla produce
`Empresa2026!` pegado en un post-it, que es peor que una frase larga que la
persona sí recuerda.

**Un usuario no se borra, se desactiva.** Las ventas apuntan a quien las hizo;
borrarlo sería perder el rastro de quién cobró. Y no se puede desactivar al
único administrador activo: nadie podría volver a entrar a configurar.

Al dar de alta, la contraseña se escribe en claro a propósito: la pone el
administrador y se la dice a la persona. Después nadie puede volver a verla,
ni él.

## Cobranza

Contesta "a quién le hablo hoy", no "cuánto me deben". Por eso ordena por
antigüedad del último abono y no por monto: $18,000 parados dos meses son peor
noticia que $30,000 que abonaron ayer.

**Los días se cuentan desde el último abono, no desde la venta.** Una venta de
hace seis meses con un abono ayer está al corriente; una de hace dos meses sin
tocar, no.

Las cuatro tarjetas de arriba son filtros: tocas "Más de 60 días" y la lista se
reduce a eso. Y hay dos vistas: por venta, para cobrar; por cliente, para
sentarse a negociar con alguien que debe varias.

## Gastos

Dos clases que NO se mezclan:

- **De operación** · cuelgan de una venta y se restan de la utilidad antes de
  comisionar. Se capturan en Caja o en el detalle de la venta.
- **Generales** · renta, nómina, servicios. No pertenecen a ninguna venta y no
  tocan comisiones. Son los de esta pantalla.

Confundirlos repetiría un error que ya costó caro: un gasto general restado a
una venta le baja la comisión a alguien sin razón. Por eso la pantalla muestra
los dos totales por separado y nunca los suma en la misma cifra.

Solo se pueden borrar los generales. Uno de operación cambia la base de comisión
de su venta, y eso se toca desde el detalle de esa venta.

## La arquitectura no copia la del sistema anterior

El sistema viejo tenía 37 pantallas porque creció sobre la marcha: cada cosa
nueva era un archivo nuevo. Aquí se agrupa por lo que la gente hace, no por
cómo se fue construyendo.

| Sección | Absorbe del sistema anterior |
|---|---|
| Panel | dashboard |
| Caja | caja, checkout |
| Ventas | ventas_lista, detalle, ticket (botón, no pantalla) |
| Cobranza | cuentas_por_cobrar |
| Corte de caja | caja_apertura, caja_cierre, caja_historial, caja_resumen |
| Clientes | clientes, facturar_cliente |
| Comisiones | recalcular_comisiones |
| Gastos | gastos |
| Servicios | productos, promociones |
| Reportes | reportes |
| **Ajustes** | **configuracion, sucursales, comisiones_config, categorias, usuarios** |

**Ajustes** son cuatro pestañas que en el viejo eran cuatro pantallas de entre
700 y 1,500 líneas. Son tablas chicas que se tocan una vez al mes y siempre
juntas: darle pantalla completa a cada una solo obliga a navegar de más.

## Reportes

Contesta la pregunta que el sistema anterior no podía: **cuánto queda de
verdad.**

    Cobrado
      − gastos de operación
      − gastos generales
      − comisiones devengadas
    = queda

Parte del **cobrado**, no de lo facturado. Lo facturado y el saldo pendiente
aparecen abajo, en una nota aparte, con el porqué escrito: sumar lo facturado
como ingreso fue lo que hacía que el sistema mostrara $558,057.60 cuando lo
real eran $289,468.05.

Y el IVA se señala aparte cuando lo hay: no es ingreso, es del SAT.

**Los criterios de fecha no son iguales para todo, a propósito:**

| Concepto | Se mide por |
|---|---|
| Cobrado | `venta_pagos.fecha_pago` — cuándo entró el dinero |
| Gastos de operación | `ventas.fecha` — cuelgan de su venta |
| Gastos generales | `gastos.fecha` — cuándo se pagó |
| Comisiones | `ventas.fecha` — a qué venta pertenecen |

El margen por área es la cifra más útil de la pantalla: un área con mucho
cobrado y margen bajo está trabajando para pagar comisiones.

**El CSV sale con BOM y punto y coma.** Sin eso, Excel en español abre el
archivo con todo en una columna y rompe los acentos. Es un detalle tonto que
hace la diferencia entre un reporte que se usa y uno que no.

## Ticket

Vive en `/ventas/{id}/ticket` y trae su propio CSS: es la única pantalla que se
imprime, y cargar la hoja completa para tacharla al imprimir no tiene sentido.
Ancho de 80 mm, el de las térmicas.

Con `?auto=1` se manda a imprimir solo, para encadenarlo después de cobrar.

## Historial de cortes

Pestaña de Corte de caja. Cuenta cuántos cuadraron y acumula sobrantes y
faltantes por separado, que es lo que revela si alguien se está equivocando
siempre para el mismo lado.

La columna "cobrado" incluye transferencias y tarjeta; la de "contado" es solo
efectivo. Por eso casi nunca coinciden, y no tienen por qué.

## Datos de la empresa

Pestaña de Ajustes. Solo se editan contacto y datos fiscales, con lista blanca
de campos: el plan, el nombre de la base y sus credenciales NO se tocan desde
aquí. Cambiarlos dejaría a la empresa sin poder entrar, y eso es trabajo de
quien administra la plataforma.

## Zona horaria

**PHP y MySQL tienen que estar en la misma zona.** `public/index.php` fija la de
PHP y `Conexion` la de cada sesión de MySQL, con los valores de
`config/config.php`.

Si no coinciden, `date()` arma el folio con la hora de México y `NOW()` guarda
UTC: seis horas de diferencia. Casi siempre da igual, pero una venta de las
18:00 del último día del mes queda registrada en el mes siguiente.

Pasó de verdad. INITME Solutions, folio `20260831180937` — 31 de agosto a las
18:09 — quedó guardada el 1 de septiembre a las 00:09. Eso movió $2,900 de venta
y $825 de comisión al mes equivocado, y era la causa de que el sistema no
cuadrara contra el Excel.

Se pone la zona de la **sesión**, no la del servidor: así funciona aunque el
hosting esté en UTC y no se pueda cambiar.

## Proveedores

Viven dentro de Gastos, como pestaña. No tienen sección propia porque solo
existen para una cosa: saber a quién se le paga. Un proveedor sin gastos
asociados es un dato muerto.

Al registrar un gasto, el campo de proveedor sugiere los que ya existen sin
obligar a elegirlos. Y si se le cambia el nombre a un proveedor, los gastos
anteriores se actualizan solos: si no, quedarían apuntando a un nombre que ya
no existe.

## Roles y permisos

Cinco roles, una sola matriz en `Dominio\Permisos`. El router, el menú y los
controladores leen de ahí, así que no puede pasar lo del sistema anterior: que
el enlace esté escondido pero la URL siga funcionando si alguien la escribe.

| Rol | Para quién |
|---|---|
| **Administrador** | Dueño o gerente. Ve el dinero y configura. |
| **Supervisor** | Coordina la operación y ve reportes. No configura ni toca usuarios. |
| **Cajero** | Cobra, abre y cierra su caja. No ve comisiones de nadie. |
| **Vendedor** | Vende y da seguimiento a sus clientes. |
| **Soporte** | Mantenimiento y diagnóstico. Ve todo, no mueve dinero. |

**Los permisos se nombran por lo que la persona HACE, no por la pantalla.**
`cobrar` es cobrar, exista o no una sección llamada Caja. Mover algo de lugar no
obliga a repensar los permisos.

**Un permiso que no existe se niega.** Escribirlo mal debe cerrar la puerta, no
abrirla.

### Dos decisiones que vale la pena defender

**Soporte ve todo y no mueve nada.** Quien entra a arreglar un problema necesita
mirar, no cobrar. Si además pudiera cobrar, cancelar pagos o asignar comisiones,
no habría forma de saber si un descuadre lo causó la empresa o quien vino a
ayudar.

**Cajero no ve comisiones.** No es desconfianza: el importe que cobra alguien más
no es asunto suyo, y tenerlo a la vista en la pantalla donde atiende clientes es
una fuga de información que nadie pidió.

## Mantenimiento

Solo para soporte. Dos cosas:

**Revisión de la base** · las seis señales que suelen estar detrás de un número
que no cuadra: ventas sin área, fechas desfasadas, cobrado mayor al total,
comisiones sin dueño, ventas sin cliente, cajas sin cerrar. Cada una dice qué
problema concreto causa.

**Secciones apagables** · Cobranza, Corte, Comisiones, Gastos, Reportes y
Recargas se pueden ocultar por empresa. Panel, Caja, Ventas, Clientes y Ajustes
no: sin ellas no se puede trabajar y el usuario pensaría que el sistema se rompió.

Soporte sigue viendo las secciones apagadas, porque para eso entra.

La configuración vive en `sistema_config` dentro de la base de cada empresa.
**Las credenciales no van ahí**: una contraseña en una tabla la ve cualquiera con
acceso a la base, y todo respaldo se la lleva.

## Notas de maqueta

**La app NO carga Bootstrap.** `libertyfin.css` se escribió como capa encima de
él y aquí se sostiene solo, así que la sección 23 del archivo trae lo que
Bootstrap aportaba: el reset de listas, la paginación en flex, el botón como
inline-flex.

Si alguien vuelve a meter Bootstrap, esa sección se puede recortar. Mientras no
esté, no se toca.

**La paginación es un parcial único**, `parciales/paginacion.php`. Diez filas por
página, definidas en `Peticion::POR_PAGINA`. Con veinticinco la tabla crecía
tanto que la columna de al lado quedaba corta.

**Columnas de igual altura**: `.lf-split` con `align-items:stretch` y las
tarjetas creciendo hasta llenar su columna.

**El marcador del panel dice "al corriente", no "salud".** Mide el porcentaje
cobrado castigado por la parte del saldo que lleva más de 30 días sin abono, y
la pantalla ahora lo explica debajo. El nombre viejo no decía nada.

**El precio del servicio es editable en Caja.** Antes se releía de la base "para
que nadie se cobre un servicio de $26,000 en $1", pero estos servicios se
cotizan por caso y el catálogo tiene varios en cero a propósito. Ahora se acepta
el precio capturado, se valida que no sea negativo ni absurdo, y queda
registrado con el usuario que lo puso: el control es el rastro, no el candado.
Un ticket que suma cero sí se rechaza, porque eso casi siempre es un dedazo.

**El área del cliente necesita `12_area_cliente.sql`.** Hasta que se corra, el
sistema funciona igual y simplemente no guarda ese campo: `ClienteRepo` pregunta
una vez si la columna existe. Preferible a reventar con "Unknown column" en una
instalación sin migrar.

## Integraciones

Siete servicios externos, todos con el mismo patrón: **las credenciales viven en
`config/integraciones.php`**, fuera de `public/` y fuera del repositorio.

| Servicio | Para qué |
|---|---|
| Emida | recargas telefónicas |
| Facturapi | timbrado CFDI |
| SPEI | ligas de pago por transferencia |
| Domiciliación | cargos recurrentes |
| PayPal | ligas de pago con tarjeta |
| SMTP | correos |
| cPanel | alta de bases para empresas nuevas |

**Una integración sin credenciales queda apagada sola.** Su sección no aparece en
el menú y sus rutas ni siquiera se registran: responden 404. No hay que
desactivar nada a mano, y una empresa que vende servicios legales no ve
"Recargas" en su menú.

La pestaña de Integraciones en Ajustes dice cuáles están listas y qué campo falta
en cada una. **Nunca muestra las credenciales**, ni siquiera parcialmente.

Por qué existe este archivo: en el sistema anterior las de Emida estaban escritas
dentro de `EmidaServicios/inicio.php`, en claro. Un archivo de código con
contraseñas dentro termina en el repositorio, en el respaldo y en el correo de
quien lo compartió. **Esas credenciales hay que rotarlas.**

## Recargas

La pantalla y el flujo están armados. Las llamadas a la API de Emida quedan
marcadas y sin implementar hasta que haya cuenta con la que probarlas: escribir
una integración SOAP a ciegas, sin poder ejecutarla una sola vez, produce código
que parece listo y no lo está.

Cuando llegue la cuenta, lo que falta es `RecargasControlador::consultar()` y
`::vender()`. Todo lo demás —pantalla, validación, ruta condicionada, registro
como venta— ya está.

## Lo que queda fuera, y por qué

**Promociones.** No es una tabla, son cinco: `promociones`,
`promociones_aplicables`, `promociones_sucursales`, `promociones_combo` y
`promociones_obtener`. Es un motor de promociones de tienda — combos, 2x1,
"compra X y llévate Y" — segmentado por categoría y sucursal.

Esta empresa vende servicios con precio negociado por cliente, y en toda la
migración no apareció una sola venta con promoción aplicada. Escribir en esas
cinco tablas sin entender el motor completo es la forma rápida de romper algo.
Queda pendiente de decidir si se usa.

Aviso sobre Emida: los proxies del sistema anterior llaman a
`http://104.248.179.142` **sin cifrar**. Si por ahí viajan credenciales o datos
de transacción, van en claro. Si se reactiva, que sea sobre HTTPS.

**Inventario.** Solo tiene sentido si llegan a vender producto físico. Hoy el
catálogo son servicios y el stock nunca se mueve.

**Facturación CFDI.** Necesita definir el PAC y el flujo de timbrado.

La capa SaaS (empresas, planes, suscripciones, distribuidores) es otro sistema
y va al final.

- Comisión por producto cuando la venta tiene varios

## Ingreso

Ya no depende del sistema anterior: tiene su propio login.

**El índice de usuarios.** El login viejo abría una conexión a la base de
CADA empresa buscando al usuario; con cinco empresas son cinco conexiones
por intento, incluso fallido. Aquí se guarda en la base principal una tabla
`usuarios_indice` que dice en qué empresa vive cada quien: la primera vez se
busca recorriendo, de ahí en adelante es una consulta. Si el índice queda
viejo o no se pudo crear, vuelve a recorrer y nadie se queda fuera.

**Mismo mensaje y mismo tiempo** si el usuario no existe o si la contraseña
está mal. Distinguirlos regala una lista de usuarios válidos, y la diferencia
de milisegundos también.

**Cinco intentos y quince minutos** de espera.

**La sesión se regenera al entrar**, y la cookie va `httponly` y `samesite=Lax`.

**La IP no echa a nadie.** En México cambia sola al saltar de wifi a datos;
se anota en el log y se sigue. El navegador sí tiene que ser el mismo.

**Un solo portero.** `public/index.php` decide en una línea quién pasa, en vez
de repetir la comprobación al inicio de cada archivo.

## El detalle de venta

Es donde aterriza todo lo que se corrigió a mano durante la migración:
pagos, gastos, IVA y comisiones en una sola pantalla.

Dos reglas que el sistema anterior no respetaba siempre:

**No se puede abonar más que el saldo.** `RegistrarPago::abonar()` lo impide.
Eso fue lo que dejó a Izol Nieto con un pago de $864.69 sobre una venta de
$745.42.

**Después de tocar un pago se resincronizan las comisiones, siempre.**
`SincronizarComisiones` recalcula desde el dominio, no desde una copia de la
fórmula. Cancelar un pago devuelve las comisiones a su sitio solo.

Los pagos cancelados no se borran: quedan tachados, con su motivo y la fecha.

## Las dos métricas de comisión

La pantalla de Comisiones separa dos cifras que no son la misma:

- **Total generado** · todo lo que la venta produjo, tenga dueño o no
- **Por pagar** · solo lo que se le puede depositar a una persona

La diferencia son los renglones a nombre de **POR ASIGNAR**: el Excel los
cuenta porque la venta sí los generó, pero nadie confirmó quién vendió.

Cuando aparece el nombre, se asigna desde la misma pantalla con un desplegable.
Solo cambia el dueño: el monto, la base y el devengado ya están calculados y
no se recalculan. Requiere rol de administrador.

## Seguridad de la caja

Tres cosas que el `caja.php` viejo no hacía:

**Los precios se releen de la base.** El formulario solo manda `{id, cantidad}`.
Si el precio viniera del navegador, cualquiera con las herramientas de
desarrollo podría cobrarse un servicio de $26,000 en $1.

**Token contra envíos falsificados.** Sin el token de sesión, el POST se rechaza.

**El anticipo no puede superar el total.** `Ticket::validarAnticipo()` lo impide.
Eso es exactamente lo que dejó a Izol Nieto con un pago de $864.69 sobre una
venta de $745.42.

## Nota sobre el puntaje de salud del panel

No es el porcentaje cobrado. Son dos cosas: cuánto se ha cobrado y qué tan
parado está lo que falta. Una empresa al 70% cobrado con todo fresco está
mejor que otra al 85% con la mitad sin moverse hace tres meses.

    salud = %cobrado x (1 - rancio x 0.35)

donde `rancio` es la fracción del saldo que lleva más de 30 días sin un solo
abono. El factor 0.35 es una decisión, no una verdad: castiga el saldo viejo
sin que domine la cifra. Si quieres otro peso, está en
`PanelControlador::salud()`, una sola línea.
