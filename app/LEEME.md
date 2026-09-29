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

## Lo que falta

- Asignar comisiones desde el detalle de venta
- Restablecer contraseña
- Editar servicios y clientes desde la app

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
