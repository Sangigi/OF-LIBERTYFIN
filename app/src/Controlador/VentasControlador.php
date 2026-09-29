<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Servicio\RegistrarPago;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

/**
 * Ventas.
 *
 * Compárese con ventas_lista.php del sistema anterior: 2,662 líneas con
 * 12 consultas y 320 bloques HTML mezclados. Aquí el controlador decide,
 * el repositorio consulta y la plantilla pinta.
 */
final class VentasControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        // Por defecto, el mes en curso. Nunca "todo": eso fue lo que hacía
        // que el histórico mostrara meses que nadie quería ver.
        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $filtros = [
            'estado' => Peticion::opcion('estado', ['completada','pendiente','cancelada']),
            'buscar' => Peticion::texto('q'),
        ];

        $pagina  = max(1, Peticion::entero('p', 1));
        $porPag  = 25;
        $desfase = ($pagina - 1) * $porPag;

        $resumen = $repo->resumen($desde, $hasta, $filtros);
        $ventas  = $repo->listado($desde, $hasta, $filtros, $porPag, $desfase);
        $total   = $repo->cuantas($desde, $hasta, $filtros);
        $saldos  = $repo->saldosAbiertos(5);
        $meses   = $repo->cobradoPorMes(6);

        Plantilla::pagina('ventas/index', [
            'titulo'   => 'Ventas',
            'icono'    => 'venta',
            'subtitulo'=> Fechas::rotulo($desde, $hasta) . ' · ' . number_format($total) . ' ventas',
            'resumen'  => $resumen,
            'ventas'   => $ventas,
            'saldos'   => $saldos,
            'meses'    => $meses,
            'desde'    => $desde,
            'hasta'    => $hasta,
            'filtros'  => $filtros,
            'pagina'   => $pagina,
            'paginas'  => max(1, (int)ceil($total / $porPag)),
            'total'    => $total,
        ]);
    }


    /** Detalle de una venta: pagos, gastos, IVA y comisiones en una pantalla. */
    public function ver($id)
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        $venta = $repo->detalle($id);
        if (!$venta) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
            return;
        }

        Plantilla::pagina('ventas/detalle', [
            'titulo'     => 'Venta ' . $venta['codigo_venta'],
            'icono'      => 'venta',
            'subtitulo'  => ($venta['cliente'] ?: 'Público general') . ' · '
                          . date('d/m/Y H:i', strtotime($venta['fecha'])),
            'venta'      => $venta,
            'lineas'     => $repo->lineas($id),
            'pagos'      => $repo->pagos($id),
            'gastos'     => $repo->gastos($id),
            'comisiones' => $repo->comisiones($id),
            'nueva'      => Peticion::entero('nueva') === 1,
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** Registra un abono. */
    public function pagar($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            $r = (new RegistrarPago($db))->abonar($id, [
                'monto'      => $_POST['monto'] ?? 0,
                'metodo'     => in_array($_POST['metodo'] ?? '', ['efectivo','transferencia','tarjeta'], true)
                                ? $_POST['metodo'] : 'efectivo',
                'referencia' => trim($_POST['referencia'] ?? ''),
                'fecha'      => Peticion::fecha('fecha', '') ?: ($_POST['fecha'] ?? ''),
                'usuario_id' => $_SESSION['usuario_id'] ?? null,
            ]);
            $this->volver($id, $r['tipo'] === 'liquidacion'
                ? 'Abono registrado. La venta queda liquidada.'
                : 'Abono registrado. Queda un saldo de ' . \LibertyFin\Dominio\Dinero::pesos($r['saldo']) . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] pagar: ' . $e->getMessage());
            $this->volver($id, 'No se pudo registrar el abono.', 'error');
        }
    }

    /** Cancela un pago. Solo admin: mueve dinero ya registrado. */
    public function cancelarPago($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver($id, 'Solo un administrador puede cancelar un pago.', 'error');
        }
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            (new RegistrarPago($db))->cancelar(
                (int)($_POST['pago'] ?? 0), $_POST['motivo'] ?? '', $_SESSION['usuario_id'] ?? null);
            $this->volver($id, 'Pago cancelado. Las comisiones ya se recalcularon.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cancelarPago: ' . $e->getMessage());
            $this->volver($id, 'No se pudo cancelar el pago.', 'error');
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($id, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ventas/' . (int)$id); exit;
    }
}
