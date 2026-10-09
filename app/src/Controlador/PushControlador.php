<?php
namespace LibertyFin\Controlador;

use LibertyFin\Servicio\Push;
use LibertyFin\Servicio\SesionUnica;

/**
 * Los avisos de escritorio aunque LibertyFin esté cerrado (ver
 * Servicio\Push). El navegador pide la llave, se suscribe, se quita, y al
 * despertar por un aviso pregunta qué mostrar.
 */
final class PushControlador
{
    /** La llave pública del servidor. Sin ella, no hay avisos por fuera. */
    public function llave()
    {
        $ll = Push::llavePublica();
        $this->json($ll ? ['ok' => true, 'llave' => $ll] : ['ok' => false]);
    }

    /** Este navegador avisa a la cuenta de la sesión. */
    public function suscribir()
    {
        if (!$this->token()) $this->json(['ok' => false], 403);
        $cuenta = SesionUnica::cuentaDeSesion();
        $ok = Push::suscribir($cuenta, trim((string)($_POST['endpoint'] ?? '')), SesionUnica::navegador());
        // `quien` le dice al navegador de quién quedó: si mañana entra otra
        // persona aquí, no se le reactivan solos los avisos de esta.
        $this->json(['ok' => $ok, 'quien' => self::quien($cuenta)]);
    }

    public function quitar()
    {
        if (!$this->token()) $this->json(['ok' => false], 403);
        Push::quitar(trim((string)($_POST['endpoint'] ?? '')));
        $this->json(['ok' => true]);
    }

    /**
     * PÚBLICA: la llama el navegador al despertar, aunque no haya sesión.
     * Se identifica con su propia dirección de suscripción, que solo
     * conocen él, el servicio de avisos y nosotros. Devuelve el último
     * aviso para él: quién y de qué ticket, nunca el texto del mensaje.
     */
    public function pendiente()
    {
        $a = Push::aviso(trim((string)($_POST['endpoint'] ?? '')));
        $this->json($a ? ['ok' => true, 'aviso' => $a] : ['ok' => false]);
    }

    /** Una marca corta de la cuenta, sin decir cuál es. */
    public static function quien($cuenta)
    {
        return $cuenta ? substr(hash('sha256', 'lf-push|' . $cuenta), 0, 16) : '';
    }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function json(array $datos, $codigo = 200)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
