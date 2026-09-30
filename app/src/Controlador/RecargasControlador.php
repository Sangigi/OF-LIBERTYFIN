<?php
namespace LibertyFin\Controlador;

use LibertyFin\Servicio\Integraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Recargas telefónicas.
 *
 * La sección solo existe si config/integraciones.php trae credenciales
 * de Emida. Sin ellas, `public/index.php` ni siquiera registra la ruta
 * y el menú no la muestra: una empresa que vende servicios legales no
 * tiene por qué ver "Recargas" y desactivarla a mano.
 *
 * ESTADO: la pantalla y el flujo están armados; las llamadas a la API
 * quedan marcadas y sin implementar hasta que haya una cuenta con la
 * que probarlas. Inventar la integración a ciegas, contra un SOAP que
 * no se puede ejecutar, produce código que parece listo y no lo está.
 */
final class RecargasControlador
{
    public function index()
    {
        $cfg = Integraciones::de('emida');
        Plantilla::pagina('recargas/index', [
            'titulo'    => 'Recargas',
            'icono'     => 'bolsa',
            'subtitulo' => $cfg['sandbox'] ? 'Modo de pruebas' : 'En producción',
            'sandbox'   => !empty($cfg['sandbox']),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** Consulta el saldo o valida un número antes de vender. */
    public function consultar()
    {
        $this->pendiente();
    }

    /** Ejecuta la recarga y la registra como venta. */
    public function vender()
    {
        $this->pendiente();
    }

    private function pendiente()
    {
        $_SESSION['lf_aviso'] = [
            'texto' => 'Falta conectar la API de Emida. La pantalla está lista; '
                     . 'las llamadas se implementan cuando haya cuenta para probarlas.',
            'tipo'  => 'error',
        ];
        header('Location: /recargas'); exit;
    }
}
