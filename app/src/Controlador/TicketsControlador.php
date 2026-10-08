<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\PlataformaRepo;
use LibertyFin\Datos\BaseConocimientoRepo;
use LibertyFin\Datos\TicketRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Avisos;
use LibertyFin\Vista\Plantilla;

/**
 * Tickets de soporte.
 *
 * La bandeja ordena por prioridad y luego por antigüedad, no al revés:
 * un ticket crítico de hace diez minutos va antes que uno bajo de hace
 * tres días. Ordenar solo por fecha hace que lo urgente espere su turno.
 */
final class TicketsControlador
{
    public function index()
    {
        $repo = new TicketRepo($this->principal());

        $filtros = [
            'estado'    => Peticion::opcion('estado',
                            array_merge(['activos'], array_keys(TicketRepo::ESTADOS)), 'activos'),
            'prioridad' => Peticion::opcion('prioridad',
                            array_keys(TicketRepo::PRIORIDADES), ''),
            'q'         => trim(Peticion::texto('q', '')),
        ];
        if (!empty($_GET['mios'])) $filtros['asignado'] = $_SESSION['usuario_id'] ?? 0;

        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 20;
        $totalT = count($repo->bandeja($filtros, 500));

        Plantilla::pagina('tickets/index', [
            'titulo'     => 'Tickets',
            'icono'      => 'alerta',
            'subtitulo'  => 'Soporte',
            'tickets'    => $repo->bandeja($filtros, $porPag, ($pagina - 1) * $porPag),
            'cifras'     => $repo->cifras(),
            'categorias' => $repo->porCategoria(),
            'filtros'    => $filtros,
            'mios'       => !empty($_GET['mios']),
            'pagina'     => $pagina,
            'paginas'    => max(1, (int)ceil($totalT / $porPag)),
            'totalT'     => $totalT,
            'empresas'   => (new PlataformaRepo($this->principal()))->empresas(),
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function ver($id)
    {
        $repo = new TicketRepo($this->principal());
        $t = $repo->uno($id);
        if (!$t) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrado','icono'=>'alerta','subtitulo'=>'']);
            return;
        }
        $mensajes = $repo->mensajes($id);
        Plantilla::pagina('tickets/detalle', [
            'titulo'    => $t['folio'],
            'icono'     => 'alerta',
            'subtitulo' => $t['asunto'],
            't'         => $t,
            'mensajes'  => $mensajes,
            // La foto de cada quien: la del cliente sale de su empresa. Aquí
            // dentro el equipo se ve entre sí aunque no la muestre al cliente.
            'fotos'     => $repo->fotos($mensajes, $t['nombre_base_datos'] ?? null, false),
            'eventos'   => $repo->eventos($id),
            // Las plantillas se cargan aquí y no en otra pantalla: una
            // respuesta guardada que hay que ir a buscar a otro lado no se
            // usa, y entonces da igual tenerla.
            'plantillas'=> (new BaseConocimientoRepo($this->principal()))->plantillas(),
            'ayuda'     => (new BaseConocimientoRepo($this->principal()))
                             ->buscar('', 'error', $t['categoria'], 4),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function crear()
    {
        if (!$this->token()) $this->a('/tickets', 'No se pudo verificar el formulario.', 'error');
        $adjunto = null;
        try {
            // La evidencia va con el ticket desde que se abre.
            if (!empty($_FILES['adjunto']['name'])) {
                $adjunto = \LibertyFin\Servicio\Archivos::documento($_FILES['adjunto'], 'ticket');
            }
            $r = (new TicketRepo($this->principal()))->crear(
                $_POST, $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '', $adjunto);
            $this->a('/tickets/' . $r['id'], 'Ticket ' . $r['folio'] . ' creado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            if ($adjunto) \LibertyFin\Servicio\Archivos::borrar($adjunto);
            $this->a('/tickets', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            if ($adjunto) \LibertyFin\Servicio\Archivos::borrar($adjunto);
            error_log('[LibertyFin] ticket crear: ' . $e->getMessage());
            $this->a('/tickets', 'No se pudo crear el ticket.', 'error');
        }
    }

    public function responder($id)
    {
        if (!$this->token()) $this->a('/tickets/' . $id, 'No se pudo verificar el formulario.', 'error');
        try {
            $adjunto = null;
            if (!empty($_FILES['adjunto']['name'])) {
                $adjunto = \LibertyFin\Servicio\Archivos::documento($_FILES['adjunto'], 'ticket');
            }
            $repo = new TicketRepo($this->principal());
            $repo->responder(
                $id, $_POST['cuerpo'] ?? '', !empty($_POST['interno']), $adjunto,
                $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');

            // Una nota interna NO se avisa: el cliente ni siquiera la ve.
            $aviso = '';
            if (empty($_POST['interno'])) {
                $t = $repo->uno($id);
                if ($t && !empty($t['email_admin'])) {
                    $ok = Avisos::ticketRespondido($t['email_admin'], $t['folio'], $t['asunto'],
                        $_POST['cuerpo'] ?? '',
                        (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                            . ($_SERVER['HTTP_HOST'] ?? '') . '/tickets/' . (int)$id);
                    $aviso = $ok ? ' Se le avisó por correo.' : ' El correo no salió.';
                }
            }
            $this->a('/tickets/' . $id, 'Respuesta agregada.' . $aviso, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a('/tickets/' . $id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ticket responder: ' . $e->getMessage());
            $this->a('/tickets/' . $id, 'No se pudo agregar la respuesta.', 'error');
        }
    }

    public function cambiar($id)
    {
        if (!$this->token()) $this->a('/tickets/' . $id, 'No se pudo verificar el formulario.', 'error');
        $repo = new TicketRepo($this->principal());
        try {
            if (!empty($_POST['asignarme'])) {
                $repo->asignar($id, $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '',
                               $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
                $this->a('/tickets/' . $id, 'Te lo asignaste.', 'ok');
            }
            if (!empty($_POST['soltar'])) {
                $repo->asignar($id, null, null,
                               $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
                $this->a('/tickets/' . $id, 'Ticket sin asignar.', 'ok');
            }
            foreach (['estado','prioridad','categoria'] as $campo) {
                if (!empty($_POST[$campo])) {
                    $repo->cambiar($id, $campo, $_POST[$campo],
                                   $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
                }
            }
            $this->a('/tickets/' . $id, 'Ticket actualizado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a('/tickets/' . $id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ticket cambiar: ' . $e->getMessage());
            $this->a('/tickets/' . $id, 'No se pudo actualizar.', 'error');
        }
    }

    /**
     * EL CHAT · los mensajes nuevos de un ticket, para soporte (JSON).
     * Incluye las notas internas: aquí sí se ven.
     */
    public function mensajes($id)
    {
        $repo = new TicketRepo($this->principal());
        $t = $repo->uno($id);
        if (!$t) $this->json(['ok' => false, 'error' => 'Ese ticket no existe.'], 404);
        $nuevos = $repo->mensajesDesde($id, Peticion::entero('desde'), true);
        $fotos  = $nuevos ? $repo->fotos($nuevos, $t['nombre_base_datos'] ?? null, false) : [];
        $this->json([
            'ok'       => true,
            'estado'   => $t['estado'],
            'cerrado'  => $t['estado'] === 'cerrado',
            'mensajes' => array_map(function ($m) use ($fotos) {
                return TicketRepo::aJson($m, $fotos, 'soporte');
            }, $nuevos),
        ]);
    }

    private function json(array $datos, $codigo = 200)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function principal() { return Conexion::de($GLOBALS['lf_bd_principal'] ?? ''); }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function a($ruta, $texto, $tipo)
    {
        // Enviado desde el chat (sin recargar): JSON y sin aviso en la sesión.
        if (($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1') {
            $this->json(['ok' => $tipo === 'ok', 'mensaje' => $texto]);
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . $ruta); exit;
    }
}
