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

        $ver = Peticion::entero('ver');

        // Las dos se calculan UNA vez. Antes esto terminaba con
        //     ['mensajes' => []] + $this->conversacion(...)
        // y el `+` de arreglos conserva la clave de la IZQUIERDA, así que
        // los mensajes llegaban siempre vacíos: el cliente abría su
        // ticket y no veía ninguna respuesta de soporte.
        $abierto  = $this->miTicket($repo, $ver, $emp);
        $mensajes = $abierto ? $this->mensajesVisibles($repo, $ver) : [];

        Plantilla::pagina('ayuda/index', [
            'titulo'     => 'Ayuda',
            'icono'      => 'alerta',
            'subtitulo'  => $_SESSION['empresa_nombre'] ?? '',
            'tickets'    => $emp ? $repo->bandeja(['empresa' => $emp], 30) : [],
            'abierto'    => $abierto,
            'mensajes'   => $mensajes,
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
        try {
            $datos = $_POST;
            // La empresa se toma de la sesión, nunca del formulario.
            $datos['empresa_id'] = (int)($_SESSION['empresa_id'] ?? 0);
            // Y la prioridad la pone soporte al leerlo, no quien reporta.
            $datos['prioridad'] = 'normal';

            $r = (new TicketRepo($this->principal()))->crear(
                $datos, $_SESSION['usuario_id'] ?? 0, $_SESSION['usuario_nombre'] ?? '');
            $this->a('Listo, tu reporte quedó con el folio ' . $r['folio']
                . '. Te avisamos por correo en cuanto lo veamos.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a($e->getMessage(), 'error');
        } catch (\Throwable $e) {
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

    private function principal() { return Conexion::de($GLOBALS['lf_bd_principal'] ?? ''); }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function a($texto, $tipo, $ver = null)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ayuda' . ($ver ? '?ver=' . (int)$ver : '')); exit;
    }
}
