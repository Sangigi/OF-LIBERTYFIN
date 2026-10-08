<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\TicketRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

/**
 * Ayuda · la puerta de entrada de los tickets.
 *
 * Hasta ahora los tickets existían pero nadie de la empresa podía abrir
 * uno: la única bandeja era la de soporte, y los tickets aparecían por
 * arte de magia. Si la única puerta fuera el correo, media queja se
 * pierde y la otra media llega sin folio ni contexto.
 *
 * Aquí cada quien ve SOLO los tickets de su empresa. Y no puede cambiar
 * estado, prioridad ni asignación: si el cliente pudiera marcar su
 * propio ticket como crítico, en dos semanas todo sería crítico y la
 * prioridad dejaría de significar nada.
 */
final class AyudaControlador
{
    public function index()
    {
        $repo = new TicketRepo($this->principal());
        $emp  = (int)($_SESSION['empresa_id'] ?? 0);

        $ver    = Peticion::entero('ver');
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;

        // Las dos se calculan UNA vez. Antes esto terminaba con
        //     ['mensajes' => []] + $this->conversacion(...)
        // y el `+` de arreglos conserva la clave de la IZQUIERDA, así que
        // los mensajes llegaban siempre vacíos: el cliente abría su
        // ticket y no veía ninguna respuesta de soporte.
        $abierto  = $this->miTicket($repo, $ver, $emp);
        $mensajes = $abierto ? $this->mensajesVisibles($repo, $ver) : [];
        // Quien abrió el ticket lo está viendo: lo de soporte ya no es novedad.
        if ($abierto && $mensajes && $this->esCreador($abierto)) {
            $repo->marcarVistoCliente($ver, max(array_column($mensajes, 'id')));
        }

        Plantilla::pagina('ayuda/index', [
            'titulo'     => 'Ayuda',
            'icono'      => 'alerta',
            'subtitulo'  => $_SESSION['empresa_nombre'] ?? '',
            'tickets'    => $emp ? $repo->bandeja(['empresa' => $emp], $porPag,
                                                  ($pagina-1)*$porPag) : [],
            'pagina'     => $pagina,
            'paginas'    => $emp ? max(1, (int)ceil(
                                count($repo->bandeja(['empresa'=>$emp], 500)) / $porPag)) : 1,
            'abierto'    => $abierto,
            'mensajes'   => $mensajes,
            // La de soporte, solo si esa persona eligió mostrarla.
            'fotos'      => $abierto ? $repo->fotos($mensajes, $_SESSION['empresa_db'] ?? null, true) : [],
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /**
     * Un ticket, solo si es de MI empresa.
     * Sin esta comprobación, cambiar el número en la URL dejaría leer
     * las conversaciones de otros clientes.
     */
    private function miTicket(TicketRepo $repo, $id, $empresaId)
    {
        if (!$id || !$empresaId) return null;
        $t = $repo->uno($id);
        return ($t && (int)$t['empresa_id'] === $empresaId) ? $t : null;
    }

    /**
     * Los mensajes que el cliente SÍ puede ver.
     *
     * Las notas internas quedan fuera: están escritas para el equipo de
     * soporte, con lenguaje y detalles que no son para el cliente.
     */
    private function mensajesVisibles(TicketRepo $repo, $id)
    {
        return array_values(array_filter($repo->mensajes($id), function ($x) {
            return empty($x['interno']);
        }));
    }

    public function crear()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $adjunto = null;
        try {
            $datos = $_POST;
            // La empresa se toma de la sesión, nunca del formulario.
            $datos['empresa_id'] = (int)($_SESSION['empresa_id'] ?? 0);
            // Y la prioridad la pone soporte al leerlo, no quien reporta.
            $datos['prioridad'] = 'normal';

            // La evidencia va con el reporte, no después: una captura junto
            // a la descripción ahorra la primera pregunta de soporte.
            if (!empty($_FILES['adjunto']['name'])) {
                $adjunto = \LibertyFin\Servicio\Archivos::documento($_FILES['adjunto'], 'ticket');
            }

            $r = (new TicketRepo($this->principal()))->crear(
                $datos, $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '', $adjunto);
            $this->a('Listo, tu reporte quedó con el folio ' . $r['folio']
                . '. Te avisamos por correo en cuanto lo veamos.', 'ok');
        } catch (\InvalidArgumentException $e) {
            // Si el reporte no se creó, el archivo subido sobra.
            if ($adjunto) \LibertyFin\Servicio\Archivos::borrar($adjunto);
            $this->a($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            if ($adjunto) \LibertyFin\Servicio\Archivos::borrar($adjunto);
            error_log('[LibertyFin] ayuda/crear: ' . $e->getMessage());
            $this->a('No se pudo enviar el reporte.', 'error');
        }
    }

    public function responder($id)
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $repo = new TicketRepo($this->principal());
        $emp  = (int)($_SESSION['empresa_id'] ?? 0);
        if (!$this->miTicket($repo, $id, $emp)) $this->a('Ese ticket no es tuyo.', 'error');
        try {
            $adjunto = null;
            if (!empty($_FILES['adjunto']['name'])) {
                $adjunto = \LibertyFin\Servicio\Archivos::documento($_FILES['adjunto'], 'ticket');
            }
            // `interno` va en false SIEMPRE: el cliente no escribe notas
            // internas, y dejar que llegue por POST sería regalárselas.
            $repo->responder($id, $_POST['cuerpo'] ?? '', false, $adjunto,
                $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
            $this->a('Respuesta enviada.', 'ok', $id);
        } catch (\InvalidArgumentException $e) {
            $this->a($e->getMessage(), 'error', $id);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ayuda/responder: ' . $e->getMessage());
            $this->a('No se pudo enviar.', 'error', $id);
        }
    }

    /**
     * EL CHAT · los mensajes nuevos de un ticket (JSON).
     *
     * Lo pide la conversación cada pocos segundos con `?desde=` el último
     * que ya tiene. Si quien pregunta es quien abrió el ticket, lo que se
     * le entrega queda como visto.
     */
    public function mensajes($id)
    {
        $repo = new TicketRepo($this->principal());
        $emp  = (int)($_SESSION['empresa_id'] ?? 0);
        $t    = $this->miTicket($repo, (int)$id, $emp);
        if (!$t) $this->json(['ok' => false, 'error' => 'Ese ticket no es tuyo.'], 404);

        $nuevos = $repo->mensajesDesde($id, Peticion::entero('desde'), false);
        $fotos  = $nuevos ? $repo->fotos($nuevos, $_SESSION['empresa_db'] ?? null, true) : [];
        if ($nuevos && $this->esCreador($t)) {
            $repo->marcarVistoCliente($id, max(array_column($nuevos, 'id')));
        }
        $this->json([
            'ok'       => true,
            'estado'   => $t['estado'],
            'cerrado'  => $t['estado'] === 'cerrado',
            'mensajes' => array_map(function ($m) use ($fotos) {
                return TicketRepo::aJson($m, $fotos, 'cliente');
            }, $nuevos),
        ]);
    }

    /**
     * LAS NOVEDADES · lo que soporte le contestó a quien pregunta y todavía
     * no ve (JSON). Lo consulta cada página, cada tanto, para avisarle dentro
     * de la plataforma y abrirle el chat.
     */
    public function novedades()
    {
        $emp = (int)($_SESSION['empresa_id'] ?? 0);
        if (!$emp) $this->json(['ok' => true, 'sin_leer' => 0, 'tickets' => []]);
        $n = (new TicketRepo($this->principal()))
            ->novedadesCliente($emp, (int)($_SESSION['usuario_id'] ?? 0));
        $this->json([
            'ok'       => true,
            'sin_leer' => $n['sin_leer'],
            'tickets'  => array_map(function ($t) {
                $txt = trim(preg_replace('/\s+/u', ' ', (string)$t['cuerpo']));
                return [
                    'id'         => (int)$t['id'],
                    'folio'      => $t['folio'],
                    'asunto'     => $t['asunto'],
                    'mensaje_id' => (int)$t['mensaje_id'],
                    'autor'      => $t['autor'] ?: 'Soporte',
                    'extracto'   => mb_strlen($txt) > 120 ? mb_substr($txt, 0, 117) . '…' : $txt,
                    'nuevos'     => (int)$t['nuevos'],
                ];
            }, $n['tickets']),
        ]);
    }

    /** ¿Quien está viendo es quien abrió el ticket? Ver TicketRepo::esMio. */
    private function esCreador(array $t)
    {
        return (int)($t['creado_por'] ?? 0) === (int)($_SESSION['usuario_id'] ?? 0)
            && in_array($t['creado_tipo'] ?? null, [null, '', 'empresa'], true);
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

    private function a($texto, $tipo, $ver = null)
    {
        // Enviado desde el chat (sin recargar): se contesta en JSON y no se
        // deja aviso en la sesión, que saldría en la siguiente página.
        if (($_SERVER['HTTP_X_LF_JSON'] ?? '') === '1') {
            $this->json(['ok' => $tipo === 'ok', 'mensaje' => $texto]);
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ayuda' . ($ver ? '?ver=' . (int)$ver : '')); exit;
    }
}
