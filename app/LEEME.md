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

## Lo que falta

- Corte de caja
- Asignar comisiones desde el detalle de venta
- Restablecer contraseña

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
