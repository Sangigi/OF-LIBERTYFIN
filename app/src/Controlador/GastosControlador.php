<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\GastoRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class GastosControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new GastoRepo($db);

        $desde  = Peticion::fecha('desde', date('Y-m-01'));
        $hasta  = Peticion::fecha('hasta', date('Y-m-t'));
        $cat    = Peticion::opcion('cat', GastoRepo::CATEGORIAS, '');
        $buscar = Peticion::texto('q');
        $editar = Peticion::entero('editar');

        Plantilla::pagina('gastos/index', [
            'titulo'    => 'Gastos',
            'icono'     => 'baja',
            'subtitulo' => Fechas::rotulo($desde, $hasta),
            'resumen'   => $repo->resumen($desde, $hasta),
            'gastos'    => $repo->listado($desde, $hasta, $cat, $buscar),
            'categorias'=> $repo->porCategoria($desde, $hasta),
            'editando'  => $editar ? $repo->uno_($editar) : null,
            'abrir'     => $editar > 0 || Peticion::texto('nuevo') !== '',
            'desde'     => $desde, 'hasta' => $hasta, 'cat' => $cat, 'buscar' => $buscar,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token();
        $repo = new GastoRepo($db);
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($id) { $repo->actualizar($id, $_POST); $m = 'Gasto actualizado.'; }
            else {
                $repo->crear($_POST, $_SESSION['usuario_id'] ?? null, $_SESSION['sucursal_id'] ?? null);
                $m = 'Gasto registrado.';
            }
            $this->volver($m, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar gasto: ' . $e->getMessage());
            $this->volver('No se pudo guardar el gasto.', 'error');
        }
    }

    public function borrar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver('Solo un administrador puede borrar un gasto.', 'error');
        }
        $this->token();
        try {
            (new GastoRepo($db))->borrar((int)($_POST['id'] ?? 0));
            $this->volver('Gasto eliminado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] borrar gasto: ' . $e->getMessage());
            $this->volver('No se pudo eliminar el gasto.', 'error');
        }
    }

    private function token()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /gastos'); exit;
    }
}
