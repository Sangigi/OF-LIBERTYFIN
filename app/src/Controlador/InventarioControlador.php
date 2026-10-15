<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Inventario;
use LibertyFin\Vista\Plantilla;

/**
 * Inventario: qué hay en cada sucursal (Existencias) y por qué cambió
 * (Movimientos). Solo para las empresas que lo encendieron en Ajustes.
 *
 * Aquí no se "edita" una existencia: se registra una entrada, una salida
 * o un conteo, y el servicio deja el rastro. Ver Servicio\Inventario.
 */
final class InventarioControlador
{
    public function index()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $t   = Peticion::opcion('t', ['existencias', 'movimientos'], 'existencias');

        $sucursales = $inv->sucursales();
        $ids = array_map('intval', array_column($sucursales, 'id'));
        $suc = Peticion::entero('s');
        if (!in_array($suc, $ids, true)) $suc = $inv->sucursalActual();

        $datos = [
            'titulo'      => 'Inventario',
            'icono'       => 'bolsa',
            'subtitulo'   => count($sucursales) > 1 ? $this->nombreDe($sucursales, $suc) : 'Existencias y movimientos',
            'activo'      => $inv->activo(),
            'bloquea'     => $inv->bloquea(),
            'pestana'     => $t,
            'sucursales'  => $sucursales,
            'sucursal'    => $suc,
            'puedeEditar' => Permisos::puede('editar.inventario'),
            'puedeAjustes'=> Permisos::puede('editar.ajustes'),
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
        ];
        unset($_SESSION['lf_aviso']);

        if (!$datos['activo']) {
            Plantilla::pagina('inventario/index', $datos);
            return;
        }

        if ($t === 'movimientos') {
            $f = [
                'desde'    => Peticion::fecha('desde', date('Y-m-d', strtotime('-30 days'))),
                'hasta'    => Peticion::fecha('hasta', date('Y-m-d')),
                'q'        => Peticion::texto('q'),
                'tipo'     => Peticion::opcion('tipo', array_keys(Inventario::TIPOS), ''),
                // En Movimientos se puede ver todas las sucursales juntas.
                'sucursal' => Peticion::texto('s') === '0' ? 0 : $suc,
            ];
            $datos['filtros'] = $f;
            $datos['movimientos'] = $inv->movimientos($f);
        } else {
            $buscar = Peticion::texto('q');
            $bajos  = Peticion::texto('bajos') !== '';
            $datos['buscar']   = $buscar;
            $datos['bajos']    = $bajos;
            $datos['resumen']  = $inv->resumen($suc);
            $datos['listado']  = $inv->listado($suc, $buscar, $bajos);
            // Para el formulario: todos, sin el filtro de la tabla.
            $datos['productos'] = ($buscar === '' && !$bajos) ? $datos['listado'] : $inv->listado($suc);
            $datos['mover']     = Peticion::opcion('mover', ['entrada', 'salida', 'conteo'], '');
            $datos['producto']  = Peticion::entero('producto');
            try {
                $datos['proveedores'] = (new \LibertyFin\Datos\GastoRepo($db))->proveedoresActivos();
            } catch (\Throwable $e) {
                $datos['proveedores'] = [];
            }
        }
        Plantilla::pagina('inventario/index', $datos);
    }

    /** Entrada, salida o conteo. */
    public function mover()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $inv = new Inventario($db);
        $suc = (int)($_POST['sucursal_id'] ?? 0);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error', $suc);
        if (!$inv->activo()) $this->volver('El inventario está apagado (Ajustes → Inventario).', 'error', $suc);

        $tipo = (string)($_POST['tipo'] ?? '');
        try {
            $r = $inv->registrar($tipo, (int)($_POST['producto_id'] ?? 0), $suc, $_POST['cantidad'] ?? 0, $_POST);
            $p = $db->prepare("SELECT nombre, unidad_medida FROM productos WHERE id = ?");
            $p->execute([(int)($_POST['producto_id'] ?? 0)]);
            $p = $p->fetch(\PDO::FETCH_ASSOC) ?: ['nombre' => 'El producto', 'unidad_medida' => ''];
            $queda = Inventario::conUnidad($r['existencia'], $p['unidad_medida']);
            if ($tipo === 'conteo') {
                $msg = abs($r['cambio']) < 0.0005
                    ? 'Conteo anotado: ' . $p['nombre'] . ' cuadra (' . $queda . ').'
                    : 'Conteo anotado: ' . $p['nombre'] . ' queda en ' . $queda . ' ('
                      . ($r['cambio'] > 0 ? '+' : '−') . Inventario::num(abs($r['cambio'])) . ' contra el sistema).';
            } else {
                $msg = ($tipo === 'entrada' ? 'Entrada' : 'Salida') . ' registrada: ' . $p['nombre'] . ' queda en ' . $queda . '.';
            }
            $this->volver($msg, 'ok', $suc);
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error', $suc, $tipo, (int)($_POST['producto_id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[LibertyFin] inventario/mover: ' . $e->getMessage());
            $this->volver('No se pudo registrar el movimiento.', 'error', $suc);
        }
    }

    private function nombreDe(array $sucursales, $id)
    {
        foreach ($sucursales as $s) if ((int)$s['id'] === (int)$id) return $s['nombre'];
        return 'Existencias y movimientos';
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    /** Vuelve a Existencias; si hubo error, con el formulario abierto como estaba. */
    private function volver($texto, $tipo, $suc = 0, $mover = '', $producto = 0)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        $q = array_filter(['s' => (int)$suc ?: null, 'mover' => $mover ?: null, 'producto' => $producto ?: null]);
        header('Location: /inventario' . ($q ? '?' . http_build_query($q) : '')); exit;
    }
}
