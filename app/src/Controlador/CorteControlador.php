<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\CajaRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

final class CorteControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new CajaRepo($db);
        $suc  = (int)($_SESSION['sucursal_id'] ?? 0);
        $usr  = (int)($_SESSION['usuario_id'] ?? 0);

        $p = Peticion::opcion('t', ['turno','historial'], 'turno');
        if ($p === 'historial') {
            $pagina  = max(1, Peticion::entero('p', 1));
            $porPag  = Peticion::POR_PAGINA;
            $totalC  = $repo->cuantosCortes($suc);
            Plantilla::pagina('corte/historial', [
                'titulo'    => 'Corte de caja',
                'icono'     => 'caja',
                'subtitulo' => 'Historial · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz'),
                'pestana'   => 'historial',
                'cortes'    => $repo->historialCompleto($suc, $porPag, ($pagina-1)*$porPag),
                'pagina'    => $pagina,
                'paginas'   => max(1, (int)ceil($totalC / $porPag)),
                'totalC'    => $totalC,
                'resumen'   => $repo->resumenHistorial($suc),
                'aviso'     => $_SESSION['lf_aviso'] ?? null,
            ]);
            unset($_SESSION['lf_aviso']);
            return;
        }

        // El turno sale de la base, no de la sesión: ver Servicio\Caja.
        $caja = \LibertyFin\Servicio\Caja::abierta($db);
        $mov = $cobros = [];
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;
        $totalC = 0;
        if ($caja) {
            $desde  = \LibertyFin\Servicio\Caja::desde($caja);
            $mov    = $repo->movimiento($caja['id'], $desde);
            // Paginados: un turno con cien cobros desplegaba cien
            // renglones y dejaba la columna de al lado minúscula.
            $totalC = $repo->cuantosCobros($caja['id']);
            $cobros = $repo->cobrosDelTurno($caja['id'], $porPag, ($pagina - 1) * $porPag);
        }

        Plantilla::pagina('corte/index', [
            'titulo'    => 'Corte de caja',
            'icono'     => 'caja',
            'subtitulo' => $caja ? 'Caja abierta · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz')
                                 : 'Sin caja abierta',
            'pestana'   => 'turno',
            'caja'      => $caja,
            'pagina'    => $pagina,
            'paginas'   => max(1, (int)ceil($totalC / $porPag)),
            'totalC'    => $totalC,
            'mov'       => $mov,
            'cobros'    => $cobros,
            'historial' => $repo->historial($suc, 8),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function abrir()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');

        $repo = new CajaRepo($db);
        $suc  = \LibertyFin\Servicio\Caja::sucursal();
        $usr  = (int)($_SESSION['usuario_id'] ?? 0);

        if ($ya = $repo->abierta($usr, $suc)) {
            $this->volver('Ya tienes la caja ' . (int)$ya['id'] . ' abierta desde el '
                . date('d/m/Y H:i', strtotime(\LibertyFin\Servicio\Caja::desde($ya)))
                . '. Ciérrala antes de abrir otra.', 'error');
        }

        $monto = (float)($_POST['monto'] ?? 0);
        if ($monto < 0) $this->volver('El fondo no puede ser negativo.', 'error');

        try {
            $id = $repo->abrir($suc, $usr, $monto, $_POST['nota'] ?? '');
            // Se recuerda YA. Antes el turno solo quedaba apuntado
            // después de volver a /corte, y entre una cosa y otra las
            // ventas se guardaban sin caja.
            $nueva = $repo->porId($id);
            if ($nueva) \LibertyFin\Servicio\Caja::recordar($nueva);
            Auditoria::anota('caja.abrir', 'caja ' . $id, null,
                'fondo ' . Dinero::pesos($monto));
            $this->volver('Caja abierta con un fondo de ' . Dinero::pesos($monto)
                . '. Ya puedes cobrar: las ventas entran a este turno.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] abrir caja: ' . $e->getMessage());
            $this->volver('No se pudo abrir la caja.', 'error');
        }
    }

    public function cerrar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');

        $repo = new CajaRepo($db);
        $caja = \LibertyFin\Servicio\Caja::abierta($db);
        if (!$caja) $this->volver('No hay ninguna caja abierta.', 'error');

        $desde = \LibertyFin\Servicio\Caja::desde($caja);
        $mov   = $repo->movimiento($caja['id'], $desde);

        // Lo esperado en el cajón: fondo inicial más lo cobrado EN EFECTIVO.
        // Transferencias y tarjeta no pasan por ahí.
        $esperado = Dinero::centavos((float)$caja['monto_apertura'] + (float)($mov['efectivo'] ?? 0));
        $contado  = (float)($_POST['contado'] ?? 0);
        if ($contado < 0) $this->volver('El conteo no puede ser negativo.', 'error');

        $dif = Dinero::centavos($contado - $esperado);
        if (abs($dif) > 0.009 && trim($_POST['nota'] ?? '') === '') {
            $this->volver('Hay una diferencia de ' . Dinero::pesos($dif)
                . '. Escribe a qué se debe antes de cerrar.', 'error');
        }

        try {
            $repo->cerrar($caja['id'], $contado, $esperado, $_POST['nota'] ?? '');
            Auditoria::anota('caja.cerrar', 'caja ' . $caja['id'],
                'esperado $' . number_format($esperado, 2),
                'contado $' . number_format($contado, 2)
                . (abs($contado - $esperado) > 0.009
                   ? ' · diferencia $' . number_format($contado - $esperado, 2) : ' · cuadró'));
            \LibertyFin\Servicio\Caja::olvidar();
            $this->volver(abs($dif) <= 0.009
                ? 'Caja cerrada. Cuadró exacto.'
                : 'Caja cerrada con una diferencia de ' . Dinero::pesos($dif) . '.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cerrar caja: ' . $e->getMessage());
            $this->volver('No se pudo cerrar la caja.', 'error');
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /corte'); exit;
    }
}
