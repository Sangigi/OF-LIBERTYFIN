<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Servicio\Emida;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Recargas telefónicas.
 *
 * El orden importa y no es el obvio:
 *
 *   1. Se VALIDA el número con el proveedor.
 *   2. Solo si responde bien, se envía la recarga.
 *   3. Y solo si la recarga sale, se registra el cobro.
 *
 * Cobrar primero parece más natural —el cliente ya te dio el dinero— pero
 * deja el caso donde la recarga falla y hay que devolver efectivo de una
 * caja que ya cuadró. Al revés, lo peor que pasa es que no se venda.
 */
final class RecargasControlador
{
    public function index()
    {
        $cfg = Integraciones::de('emida');
        $api = new Emida($cfg);

        Plantilla::pagina('recargas/index', [
            'titulo'    => 'Recargas',
            'icono'     => 'bolsa',
            'subtitulo' => !empty($cfg['sandbox']) ? 'Modo de pruebas' : 'En producción',
            'sandbox'   => !empty($cfg['sandbox']),
            'cifrado'   => $api->cifrado(),
            'aceptado'  => !empty($cfg['acepto_sin_cifrar']),
            'saldo'     => $_SESSION['lf_saldo_emida'] ?? null,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso'], $_SESSION['lf_saldo_emida']);
    }

    /** Consulta el saldo con el proveedor. */
    public function consultar()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');

        $r = (new Emida(Integraciones::de('emida')))->saldo();
        if (!$r['ok']) $this->volver($r['error'], 'error');

        $_SESSION['lf_saldo_emida'] = $r['saldo'];
        $this->volver('Saldo consultado.', 'ok');
    }

    /** Valida, recarga y registra la venta, en ese orden. */
    public function vender()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');

        $numero = preg_replace('/\D/', '', (string)($_POST['numero'] ?? ''));
        if (strlen($numero) !== 10) {
            $this->volver('El número debe tener 10 dígitos.', 'error');
        }
        $producto = trim($_POST['producto'] ?? '');
        if ($producto === '') $this->volver('Elige la compañía.', 'error');

        $monto = round((float)($_POST['monto'] ?? 0), 2);
        if ($monto < 10) $this->volver('El monto mínimo es de $10.', 'error');

        $api = new Emida(Integraciones::de('emida'));

        // 1 · Validar ANTES de cobrar nada.
        $v = $api->validar($numero, $producto);
        if (!$v['ok']) {
            $this->volver('No se pudo validar el número: ' . $v['error'], 'error');
        }

        // 2 · El identificador propio: si la red se cae y se reintenta con
        // el mismo, el proveedor devuelve 294 en vez de recargar dos veces.
        $salesId = date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 4);

        $r = $api->recargar($numero, $producto, $monto, $salesId);

        if (!empty($r['incierta'])) {
            // El peor caso: no se sabe si salió. Nunca se reintenta solo.
            error_log('[LibertyFin] recarga incierta ' . $salesId . ' ' . $numero);
            $this->volver($r['error'], 'error');
        }
        if (!$r['ok']) $this->volver($r['error'], 'error');

        // 3 · Salió. Ahora sí se registra el cobro.
        $this->volver(
            ($r['duplicada'] ? 'Esa recarga ya se había hecho hace unos minutos. '
                             : 'Recarga enviada a ' . $numero . ' por ' . Dinero::pesos($monto) . '. ')
            . ($r['folio'] ? 'Folio ' . $r['folio'] . '.' : ''), 'ok');
    }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /recargas'); exit;
    }
}
