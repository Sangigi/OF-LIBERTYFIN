<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\CajaRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Dinero;
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
            Plantilla::pagina('corte/historial', [
                'titulo'    => 'Corte de caja',
                'icono'     => 'caja',
                'subtitulo' => 'Historial · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz'),
                'pestana'   => 'historial',
                'cortes'    => $repo->historialCompleto($suc, 40),
                'resumen'   => $repo->resumenHistorial($suc),
                'aviso'     => $_SESSION['lf_aviso'] ?? null,
            ]);
            unset($_SESSION['lf_aviso']);
            return;
        }

        $caja = $repo->abierta($usr, $suc);
        $mov = $cobros = [];
        if ($caja) {
            $desde  = $caja['fecha_apertura'] ?? $caja['created_at'] ?? date('Y-m-d 00:00:00');
            $mov    = $repo->movimiento($caja['id'], $desde);
            $cobros = $repo->cobros($caja['id'], $desde, 40);
            $_SESSION['caja_id'] = (int)$caja['id'];
        } else {
            unset($_SESSION['caja_id']);
        }

        Plantilla::pagina('corte/index', [
            'titulo'    => 'Corte de caja',
            'icono'     => 'caja',
            'subtitulo' => $caja ? 'Caja abierta · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz')
                                 : 'Sin caja abierta',
            'pestana'   => 'turno',
            'caja'      => $caja,
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
        $suc  = (int)($_SESSION['sucursal_id'] ?? 0);
        $usr  = (int)($_SESSION['usuario_id'] ?? 0);

        if ($repo->abierta($usr, $suc)) $this->volver('Ya tienes una caja abierta.', 'error');

        $monto = (float)($_POST['monto'] ?? 0);
        if ($monto < 0) $this->volver('El fondo no puede ser negativo.', 'error');

        try {
            $repo->abrir($suc, $usr, $monto, $_POST['nota'] ?? '');
            $this->volver('Caja abierta con un fondo de ' . Dinero::pesos($monto) . '.', 'ok');
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
        $caja = $repo->abierta((int)($_SESSION['usuario_id'] ?? 0), (int)($_SESSION['sucursal_id'] ?? 0));
        if (!$caja) $this->volver('No hay ninguna caja abierta.', 'error');

        $desde = $caja['fecha_apertura'] ?? $caja['created_at'] ?? date('Y-m-d 00:00:00');
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
            unset($_SESSION['caja_id']);
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
