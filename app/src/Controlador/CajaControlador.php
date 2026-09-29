<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\CatalogoRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Iva;
use LibertyFin\Dominio\Ticket;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\RegistrarVenta;
use LibertyFin\Vista\Plantilla;

final class CajaControlador
{
    public function index()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $cat = new CatalogoRepo($db);

        $area   = Peticion::texto('area');
        $buscar = Peticion::texto('q');
        $suc    = $_SESSION['sucursal_id'] ?? null;

        Plantilla::pagina('caja/index', [
            'titulo'    => 'Caja',
            'icono'     => 'caja',
            'subtitulo' => 'Venta nueva · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz'),
            'servicios' => $cat->servicios($suc, $area, $buscar),
            'areas'     => $cat->areas(),
            'area'      => $area,
            'buscar'    => $buscar,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** Búsqueda de clientes para el ticket. Devuelve JSON. */
    public function clientes()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode((new CatalogoRepo($db))->buscarClientes(Peticion::texto('q')));
    }

    /** Cobra el ticket. */
    public function cobrar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        if (!$this->tokenValido()) {
            $this->volver('No se pudo verificar el formulario. Intenta de nuevo.', 'error');
        }

        $lineas = json_decode($_POST['lineas'] ?? '[]', true);
        if (!is_array($lineas) || !$lineas) {
            $this->volver('El ticket está vacío.', 'error');
        }

        // Los precios NO se toman del formulario: se releen de la base.
        // Si vinieran del navegador, cualquiera podría cobrarse lo que quisiera.
        $ids = array_map(function ($l) { return (int)($l['id'] ?? 0); }, $lineas);
        $ids = array_values(array_filter(array_unique($ids)));
        if (!$ids) $this->volver('El ticket está vacío.', 'error');

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        // El precio de venta es `subprecio`. Releerlo mal aquí sería peor que
        // no releerlo: cobraría el importe equivocado con toda confianza.
        $st = $db->prepare("
            SELECT id, nombre, COALESCE(NULLIF(subprecio,0), precio) AS precio, costo
            FROM productos WHERE id IN ($marcas) AND activo = 1");
        $st->execute($ids);
        $reales = [];
        foreach ($st->fetchAll() as $p) $reales[(int)$p['id']] = $p;

        $pct  = max(0, min(100, (float)($_POST['iva_pct'] ?? 0)));
        $modo = ($_POST['iva_modo'] ?? 'incluido') === 'sumar' ? Iva::SUMAR : Iva::INCLUIDO;
        $ticket = new Ticket(new Iva($pct, $modo));

        foreach ($lineas as $l) {
            $id = (int)($l['id'] ?? 0);
            if (!isset($reales[$id])) continue;
            $p = $reales[$id];
            $ticket->agregar($id, $p['nombre'], $p['precio'],
                max(1, (float)($l['cantidad'] ?? 1)), 0, $p['costo'] ?? 0);
        }
        $ticket->gastosOperacion((float)($_POST['gastos'] ?? 0));

        try {
            $r = (new RegistrarVenta($db))->cobrar($ticket, [
                'cliente_id'     => (int)($_POST['cliente_id'] ?? 0) ?: null,
                'usuario_id'     => $_SESSION['usuario_id'] ?? null,
                'sucursal_id'    => $_SESSION['sucursal_id'] ?? null,
                'caja_id'        => $_SESSION['caja_id'] ?? null,
                'anticipo'       => (float)($_POST['anticipo'] ?? 0),
                'metodo_pago'    => in_array($_POST['metodo'] ?? '', ['efectivo','transferencia','tarjeta'], true)
                                    ? $_POST['metodo'] : 'efectivo',
                'referencia'     => trim($_POST['referencia'] ?? ''),
                'descripcion'    => trim($_POST['descripcion'] ?? ''),
                'concepto_gasto' => trim($_POST['concepto_gasto'] ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cobrar: ' . $e->getMessage());
            $this->volver('No se pudo registrar la venta. Quedó anotado el error.', 'error');
        }

        header('Location: /ventas/' . $r['id'] . '?nueva=1');
        exit;
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token'])
            && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($mensaje, $tipo = 'error')
    {
        $_SESSION['lf_aviso'] = ['texto' => $mensaje, 'tipo' => $tipo];
        header('Location: /caja'); exit;
    }
}
