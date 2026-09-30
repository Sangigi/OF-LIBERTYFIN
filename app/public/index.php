<?php
/**
 * Punto de entrada único.
 *
 * Todo pasa por aquí. Nada más en public/ es ejecutable, así que no
 * existe la posibilidad de abrir un .env, un log o un include suelto
 * por URL: no viven en la raíz del sitio.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Router;
use LibertyFin\Servicio\Autenticar;
use LibertyFin\Vista\Plantilla;

$cfg = is_readable($raiz . '/config/config.php')
     ? require $raiz . '/config/config.php'
     : ['bd' => ['host'=>'localhost','usuario'=>'','clave'=>'','principal'=>''], 'depurar' => true];

if (!empty($cfg['depurar'])) { ini_set('display_errors','1'); error_reporting(E_ALL); }

// ── Zona horaria ──
// PHP y MySQL tienen que coincidir. Si no, date() arma el folio con la
// hora de México y NOW() guarda UTC: seis horas de diferencia que, en
// una venta de las 18:00 de fin de mes, la mandan al mes siguiente.
// Pasó de verdad: INITME Solutions, 31 de agosto a las 18:09, quedó
// registrada el 1 de septiembre.
date_default_timezone_set($cfg['zona'] ?? 'America/Mexico_City');
Conexion::configurar($cfg['bd'] + ['zona_sql' => $cfg['zona_sql'] ?? '-06:00']);
Plantilla::base($raiz . '/src/Vista/plantillas');
\LibertyFin\Servicio\Integraciones::cargar($raiz);
$GLOBALS['lf_bd_principal'] = $cfg['bd']['principal'] ?? '';

// ── Cookie de sesión ──
// httponly: el JavaScript no puede leerla, así que un XSS no se lleva la
// sesión. samesite Lax: no viaja desde otro sitio, que es la defensa base
// contra envíos cruzados. secure solo si hay HTTPS, o en local no entra.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// ── Rutas ──
$r = new Router();

// Públicas
$r->get('/login',  ['LibertyFin\Controlador\LoginControlador', 'mostrar']);
$r->post('/login', ['LibertyFin\Controlador\LoginControlador', 'entrar']);
$r->get('/salir',  ['LibertyFin\Controlador\LoginControlador', 'salir']);

// Privadas
$r->get('/',        ['LibertyFin\Controlador\PanelControlador',  'index']);
$r->get('/ventas',  ['LibertyFin\Controlador\VentasControlador', 'index']);
$r->get('/ventas/{id}',               ['LibertyFin\Controlador\VentasControlador', 'ver']);
$r->get('/ventas/{id}/ticket',        ['LibertyFin\Controlador\VentasControlador', 'ticket']);
$r->post('/ventas/{id}/pagar',        ['LibertyFin\Controlador\VentasControlador', 'pagar']);
$r->post('/ventas/{id}/cancelar-pago',['LibertyFin\Controlador\VentasControlador', 'cancelarPago']);
$r->post('/ventas/{id}/comision',        ['LibertyFin\Controlador\VentasControlador', 'asignarComision']);
$r->post('/ventas/{id}/quitar-comision', ['LibertyFin\Controlador\VentasControlador', 'quitarComision']);
$r->get('/caja',          ['LibertyFin\Controlador\CajaControlador', 'index']);
$r->get('/caja/clientes', ['LibertyFin\Controlador\CajaControlador', 'clientes']);
$r->post('/caja/cobrar',  ['LibertyFin\Controlador\CajaControlador', 'cobrar']);
$r->get('/comisiones',            ['LibertyFin\Controlador\ComisionesControlador', 'index']);
$r->post('/comisiones/reasignar', ['LibertyFin\Controlador\ComisionesControlador', 'reasignar']);
$r->get('/clientes',  ['LibertyFin\Controlador\ClientesControlador',  'index']);
$r->get('/cobranza',  ['LibertyFin\Controlador\CobranzaControlador',  'index']);
$r->get('/gastos',          ['LibertyFin\Controlador\GastosControlador', 'index']);
$r->post('/gastos/guardar', ['LibertyFin\Controlador\GastosControlador', 'guardar']);
$r->post('/gastos/borrar',  ['LibertyFin\Controlador\GastosControlador', 'borrar']);
$r->post('/gastos/proveedor',          ['LibertyFin\Controlador\GastosControlador', 'guardarProveedor']);
$r->post('/gastos/proveedor/alternar', ['LibertyFin\Controlador\GastosControlador', 'alternarProveedor']);
$r->get('/servicios', ['LibertyFin\Controlador\ServiciosControlador', 'index']);
$r->post('/clientes/guardar',   ['LibertyFin\Controlador\ClientesControlador',  'guardar']);
$r->post('/servicios/guardar',  ['LibertyFin\Controlador\ServiciosControlador', 'guardar']);
$r->post('/servicios/alternar', ['LibertyFin\Controlador\ServiciosControlador', 'alternar']);
$r->get('/reportes',     ['LibertyFin\Controlador\ReportesControlador', 'index']);
// Recargas: la ruta solo existe si hay credenciales. Sin ellas, 404.
if (\LibertyFin\Servicio\Integraciones::activa('emida')) {
    $r->get('/recargas',          ['LibertyFin\Controlador\RecargasControlador', 'index']);
    $r->post('/recargas/consultar',['LibertyFin\Controlador\RecargasControlador', 'consultar']);
    $r->post('/recargas/vender',  ['LibertyFin\Controlador\RecargasControlador', 'vender']);
}
$r->get('/reportes/csv', ['LibertyFin\Controlador\ReportesControlador', 'csv']);
$r->get('/ajustes',          ['LibertyFin\Controlador\AjustesControlador', 'index']);
$r->post('/ajustes/guardar', ['LibertyFin\Controlador\AjustesControlador', 'guardar']);
$r->post('/ajustes/alternar',['LibertyFin\Controlador\AjustesControlador', 'alternar']);
$r->get('/usuarios',             ['LibertyFin\Controlador\UsuariosControlador', 'index']);
$r->post('/usuarios/guardar',    ['LibertyFin\Controlador\UsuariosControlador', 'guardar']);
$r->post('/usuarios/restablecer',['LibertyFin\Controlador\UsuariosControlador', 'restablecer']);
$r->post('/usuarios/alternar',   ['LibertyFin\Controlador\UsuariosControlador', 'alternar']);
$r->get('/cuenta',               ['LibertyFin\Controlador\UsuariosControlador', 'miCuenta']);
$r->post('/cuenta/clave',        ['LibertyFin\Controlador\UsuariosControlador', 'cambiarClave']);
$r->get('/corte',        ['LibertyFin\Controlador\CorteControlador', 'index']);
$r->post('/corte/abrir', ['LibertyFin\Controlador\CorteControlador', 'abrir']);
$r->post('/corte/cerrar',['LibertyFin\Controlador\CorteControlador', 'cerrar']);

$publicas = ['/login', '/salir'];
$ruta     = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// El portero: una sola línea decide quién pasa, en vez de repetir la
// comprobación al inicio de cada archivo como hacía el sistema anterior.
if (!in_array($ruta, $publicas, true) && !Autenticar::sesionValida()) {
    header('Location: /login'); exit;
}

$hallazgo = $r->despachar($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

if ($hallazgo === null) {
    http_response_code(404);
    Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
    exit;
}

try {
    list($clase, $metodo) = $hallazgo['destino'];
    call_user_func_array([new $clase(), $metodo], $hallazgo['args']);
} catch (Throwable $e) {
    error_log('[LibertyFin] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (!empty($cfg['depurar'])) {
        echo '<pre style="padding:20px;font:13px ui-monospace">'
           . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString()) . '</pre>';
    } else {
        echo 'Ocurrió un error. Ya quedó registrado.';
    }
}
