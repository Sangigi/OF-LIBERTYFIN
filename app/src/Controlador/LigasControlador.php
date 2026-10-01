<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\LigaRepo;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Servicio\LigaPago;
use LibertyFin\Servicio\RegistrarPago;
use LibertyFin\Vista\Plantilla;

/**
 * Ligas de pago.
 *
 * Una liga pide el dinero; no lo cobra. El abono se aplica cuando el
 * proveedor confirma, y solo entonces entra al corte, a los reportes y
 * a las comisiones.
 */
final class LigasControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new LigaRepo($db);
        $cfg  = Integraciones::de('spei');

        $estado = Peticion::opcion('estado', ['', 'pendientes', 'pagada', 'vencidas'], 'pendientes');
        $buscar = trim(Peticion::texto('q', ''));
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 20;
        $total  = $repo->cuantas($estado, $buscar);

        Plantilla::pagina('ligas/index', [
            'titulo'    => 'Ligas de pago',
            'icono'     => 'cobro',
            'subtitulo' => $cfg && !empty($cfg['sandbox']) ? 'Modo de pruebas' : 'Cobro en línea',
            'ligas'     => $repo->listado($estado, $buscar, $porPag, ($pagina-1)*$porPag),
            'cifras'    => $repo->cifras(),
            'metodos'   => LigaPago::METODOS,
            'listo'     => $cfg !== null && (new LigaPago($cfg))->listo(),
            'sandbox'   => $cfg && !empty($cfg['sandbox']),
            'pendientes'=> (new VentaRepo($db))->conSaldo(30),
            'estado' => $estado, 'q' => $buscar,
            'total' => $total, 'pagina' => $pagina,
            'paginas' => max(1, (int)ceil($total / $porPag)),
            'reciente'  => $_SESSION['lf_liga'] ?? null,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso'], $_SESSION['lf_liga']);
    }

    /** Genera una liga para una venta con saldo, o por un monto suelto. */
    public function generar()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new LigaRepo($db);
        $cfg  = Integraciones::de('spei');
        if (!$cfg) $this->a('Faltan las credenciales de SPEI en config/integraciones.php.', 'error');

        $ventaId = (int)($_POST['venta'] ?? 0);
        $venta   = null;
        $cliente = trim($_POST['cliente'] ?? '');
        $desc    = trim($_POST['descripcion'] ?? '');

        if ($ventaId) {
            $venta = (new VentaRepo($db))->detalle($ventaId);
            if (!$venta) $this->a('Esa venta no existe.', 'error');
            // El monto sale del SALDO de la venta, no del formulario: así
            // no se puede generar una liga por más de lo que debe, ni por
            // una venta que ya se liquidó.
            $monto   = round((float)$venta['saldo'], 2);
            $cliente = $venta['cliente'] ?: 'Público general';
            $desc    = $desc ?: ('Venta ' . $venta['codigo_venta']);
            if ($monto <= 0.01) $this->a('Esa venta ya está liquidada.', 'error');
        } else {
            $monto = round((float)($_POST['monto'] ?? 0), 2);
            if ($monto <= 0) $this->a('Escribe el monto a cobrar.', 'error');
            if ($desc === '') $this->a('Escribe de qué es el cobro.', 'error');
        }

        $metodo = $_POST['metodo'] ?? 'todos';
        $api = new LigaPago($cfg);

        // La referencia lleva la venta dentro cuando la hay: si se
        // reintenta, el proveedor devuelve la MISMA liga en vez de crear
        // otra, que es justo lo que se quiere.
        $semilla = $ventaId
                 ? ('9' . str_pad((string)$ventaId, 7, '0', STR_PAD_LEFT) . date('ymdHi'))
                 : (date('ymdHis') . random_int(100000, 999999));

        $r = $api->generar([
            'monto' => $monto, 'descripcion' => $desc, 'metodo' => $metodo,
            'referencia' => $semilla, 'id' => $semilla,
            'dias' => (int)($_POST['dias'] ?? 0) ?: null,
        ]);
        if (!$r) $this->a('No se generó la liga: ' . $api->error(), 'error');

        try {
            $id = $repo->crear([
                'referencia' => $r['referencia'], 'venta_id' => $ventaId ?: null,
                'cliente' => $cliente, 'monto' => $monto, 'metodo' => $metodo,
                'descripcion' => $desc, 'liga' => $r['liga'], 'clabe' => $r['clabe'],
                'barras' => $r['barras'], 'vence' => $r['vence'], 'pruebas' => $r['pruebas'],
                'usuario_id' => $_SESSION['usuario_id'] ?? null,
                'usuario_nombre' => $_SESSION['usuario_nombre'] ?? null,
            ]);
            Auditoria::anota('pago.registrar', 'liga de pago · ' . $desc,
                null, Dinero::pesos($monto) . ' · ' . $metodo);
            $_SESSION['lf_liga'] = $repo->porId($id);
            $this->a('Liga generada. Compártela con el cliente.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar liga: ' . $e->getMessage());
            $this->a('La liga se generó pero no se pudo guardar: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Pregunta al proveedor si ya pagaron.
     *
     * Y si pagaron, aplica el abono. Es el único lugar donde una liga se
     * convierte en dinero.
     */
    public function revisar()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new LigaRepo($db);
        $cfg  = Integraciones::de('spei');
        if (!$cfg) $this->a('Faltan las credenciales de SPEI.', 'error');

        $api = new LigaPago($cfg);
        $una = (int)($_POST['id'] ?? 0);
        $lista = $una ? array_filter([$repo->porId($una)]) : $repo->porRevisar(25);
        if (!$lista) $this->a('No hay ligas pendientes que revisar.', 'ok');

        $cobradas = 0; $revisadas = 0; $fallos = 0;
        foreach ($lista as $l) {
            if ($l['estado'] === 'pagada') continue;
            $r = $api->estado($l['referencia']);
            $revisadas++;
            if ($r === null) { $fallos++; continue; }

            if (!$r['pagado']) { $repo->marcarRevisada($l['id']); continue; }

            // Pagaron. Ahora sí entra el dinero.
            try {
                $pagoId = null;
                if ($l['venta_id']) {
                    $res = (new RegistrarPago($db))->abonar((int)$l['venta_id'], [
                        'monto'      => $l['monto'],
                        'metodo'     => $l['metodo'] === 'tarjeta' ? 'tarjeta' : 'transferencia',
                        'referencia' => 'Liga ' . $l['referencia'],
                        'fecha'      => date('Y-m-d'),
                        'usuario_id' => $_SESSION['usuario_id'] ?? null,
                    ]);
                    $pagoId = $res['pago_id'] ?? null;
                }
                $repo->marcarPagada($l['id'], $pagoId);
                Auditoria::anota('pago.registrar',
                    'liga cobrada · ' . $l['referencia'], 'pendiente', Dinero::pesos($l['monto']));
                $cobradas++;
            } catch (\Throwable $e) {
                error_log('[LibertyFin] abonar liga ' . $l['referencia'] . ': ' . $e->getMessage());
                $fallos++;
            }
        }

        $this->a($cobradas
            ? $cobradas . ' liga' . ($cobradas==1?'':'s') . ' cobrada'
              . ($cobradas==1?'':'s') . '. El abono ya está aplicado.'
            : $revisadas . ' revisada' . ($revisadas==1?'':'s') . ', ninguna pagada todavía.'
              . ($fallos ? ' ' . $fallos . ' no se pudieron consultar: ' . $api->error() : ''),
            $fallos && !$cobradas ? 'error' : 'ok');
    }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function a($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ligas'); exit;
    }
}
