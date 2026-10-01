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
            'metodos'   => (new \LibertyFin\Datos\ConfigRepo($db))->metodosDisponibles(),
            // Cobrar con liga solo aparece si hay con qué generarla.
            'ligas'     => \LibertyFin\Servicio\Integraciones::activa('spei')
                         && (new \LibertyFin\Datos\ConfigRepo($db))->seccionActiva('ligas'),
            'formasLiga'=> \LibertyFin\Servicio\LigaPago::METODOS,
            'ligaLista' => $_SESSION['lf_liga'] ?? null,
            'area'      => $area,
            'buscar'    => $buscar,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso'], $_SESSION['lf_liga']);
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

        // El precio SÍ puede venir del formulario: estos servicios se cotizan
        // por caso y el catálogo tiene varios en cero a propósito.
        //
        // Lo que se relee de la base es el NOMBRE y el COSTO, y se valida que
        // el producto exista y esté activo. El precio se acepta, se limpia y
        // queda registrado en venta_detalles junto con el usuario que lo
        // capturó: aquí el control es el rastro, no el candado.
        //
        // Si el formulario no manda precio, se usa el del catálogo.
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

            $precio = isset($l['precio']) ? round((float)$l['precio'], 2) : (float)$p['precio'];
            if ($precio < 0) $precio = 0;
            if ($precio > 9999999) {
                $this->volver('Ese precio no parece correcto. Revísalo.', 'error');
            }
            $ticket->agregar($id, $p['nombre'], $precio,
                max(1, (float)($l['cantidad'] ?? 1)), 0, $p['costo'] ?? 0);
        }

        // Un ticket entero en cero casi siempre es un dedazo, no una cortesía.
        if ($ticket->subtotalCapturado() <= 0) {
            $this->volver('El ticket suma cero. Pon el precio de cada servicio antes de cobrar.', 'error');
        }
        $ticket->gastosOperacion((float)($_POST['gastos'] ?? 0));

        try {
            $r = (new RegistrarVenta($db))->cobrar($ticket, [
                'cliente_id'     => (int)($_POST['cliente_id'] ?? 0) ?: null,
                'usuario_id'     => $_SESSION['usuario_id'] ?? null,
                'sucursal_id'    => $_SESSION['sucursal_id'] ?? null,
                'caja_id'        => $_SESSION['caja_id'] ?? null,
                'anticipo'       => (float)($_POST['anticipo'] ?? 0),
                'metodo_pago'     => in_array($_POST['metodo'] ?? '',
                                    (new \LibertyFin\Datos\ConfigRepo($db))->metodosDisponibles(), true)
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

    /**
     * Genera la liga para una venta recién creada.
     *
     * Se llama después de registrar la venta, no antes: si la liga
     * fallara y la venta no existiera, el cliente se iría sin nada y sin
     * rastro de lo que se intentó cobrarle.
     */
    private function conLiga($db, array $r, $forma)
    {
        $venta = (new \LibertyFin\Datos\VentaRepo($db))->detalle($r['id']);
        if (!$venta) $this->volver('La venta se registró pero no se pudo leer.', 'error');

        $saldo = round((float)$venta['saldo'], 2);
        if ($saldo <= 0.01) {
            $this->volver('Venta registrada y liquidada. No hizo falta la liga.', 'ok');
        }

        $api = new \LibertyFin\Servicio\LigaPago(\LibertyFin\Servicio\Integraciones::de('spei'));
        $semilla = '9' . str_pad((string)$r['id'], 7, '0', STR_PAD_LEFT) . date('ymdHi');

        $g = $api->generar([
            'monto' => $saldo, 'metodo' => $forma,
            'descripcion' => 'Venta ' . $venta['codigo_venta'],
            'referencia' => $semilla, 'id' => $semilla,
        ]);
        if (!$g) {
            // La venta SÍ quedó. Se dice qué pasó y dónde seguir, en vez
            // de dejar creer que no se registró nada.
            $this->volver('Venta ' . $venta['codigo_venta'] . ' registrada con saldo, pero '
                . 'la liga no se generó: ' . $api->error()
                . ' Puedes intentarlo de nuevo desde Ligas de pago.', 'error');
        }

        try {
            $id = (new \LibertyFin\Datos\LigaRepo($db))->crear([
                'referencia' => $g['referencia'], 'venta_id' => (int)$r['id'],
                'cliente' => $venta['cliente'] ?: 'Público general', 'monto' => $saldo,
                'metodo' => $forma, 'descripcion' => 'Venta ' . $venta['codigo_venta'],
                'liga' => $g['liga'], 'clabe' => $g['clabe'], 'barras' => $g['barras'],
                'vence' => $g['vence'], 'pruebas' => $g['pruebas'],
                'usuario_id' => $_SESSION['usuario_id'] ?? null,
                'usuario_nombre' => $_SESSION['usuario_nombre'] ?? null,
            ]);
            // Se vuelve a CAJA, no a Ligas. El cajero tiene al cliente
            // enfrente: mandarlo a otra pantalla lo obliga a volver a
            // empezar para la siguiente venta.
            $_SESSION['lf_liga'] = (new \LibertyFin\Datos\LigaRepo($db))->porId($id);
            $_SESSION['lf_aviso'] = ['texto' =>
                'Venta ' . $venta['codigo_venta'] . ' registrada. Muéstrale el código o '
                . 'mándale la liga; el abono entra cuando pague.', 'tipo' => 'ok'];
            header('Location: /caja'); exit;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] liga en caja: ' . $e->getMessage());
            $this->volver('Venta registrada, pero la liga no se guardó: ' . $e->getMessage(), 'error');
        }
    }
}
