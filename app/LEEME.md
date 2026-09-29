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

## Lo que falta

- Login propio (hoy toma la sesión del sistema viejo)
- Panel, Caja, Clientes, Comisiones, Servicios
- Detalle de venta
- Protección CSRF en los formularios que escriban
