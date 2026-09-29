<?php
/**
 * Punto de entrada único.
 *
 * Todo pasa por aquí. Nada más en public/ es un archivo PHP ejecutable,
 * así que no existe la posibilidad de abrir .env, un log o un include
 * suelto por URL: ya no viven en la raíz del sitio.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Router;
use LibertyFin\Vista\Plantilla;

$cfg = is_readable($raiz . '/config/config.php')
     ? require $raiz . '/config/config.php'
     : ['bd' => ['host'=>'localhost','usuario'=>'','clave'=>''], 'depurar' => true];

if (!empty($cfg['depurar'])) { ini_set('display_errors','1'); error_reporting(E_ALL); }

Conexion::configurar($cfg['bd']);
Plantilla::base($raiz . '/src/Vista/plantillas');

session_start();

// ── Sesión ──
// Mientras se migra el login, se toma la sesión del sistema anterior.
if (empty($_SESSION['logged_in']) || empty($_SESSION['empresa_db'])) {
    header('Location: /login'); exit;
}

// ── Rutas ──
$r = new Router();
$r->get('/',        ['LibertyFin\Controlador\PanelControlador',  'index']);
$r->get('/ventas',  ['LibertyFin\Controlador\VentasControlador', 'index']);
$r->get('/caja',    ['LibertyFin\Controlador\CajaControlador',   'index']);
$r->get('/caja/clientes', ['LibertyFin\Controlador\CajaControlador', 'clientes']);
$r->post('/caja/cobrar',  ['LibertyFin\Controlador\CajaControlador', 'cobrar']);
$r->get('/comisiones', ['LibertyFin\Controlador\ComisionesControlador', 'index']);
$r->post('/comisiones/reasignar', ['LibertyFin\Controlador\ComisionesControlador', 'reasignar']);

$destino = $r->despachar($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

if ($destino === null) {
    http_response_code(404);
    Plantilla::pagina('errores/404', ['titulo' => 'No encontrada', 'icono' => 'alerta', 'subtitulo' => '']);
    exit;
}

try {
    list($clase, $metodo) = $destino;
    (new $clase())->$metodo();
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
