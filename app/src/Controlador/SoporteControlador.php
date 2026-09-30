<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\PlataformaRepo;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Migraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Panel de soporte · todas las empresas.
 *
 * Es de LECTURA salvo dos acciones: restablecer una contraseña y
 * activar o desactivar una cuenta. Nada más.
 *
 * No se pueden cambiar roles administrativos desde aquí a propósito: si
 * soporte pudiera volver admin a cualquiera, bastaría comprometer una
 * cuenta de soporte para tener acceso total a todos los clientes. El
 * límite no es desconfianza en la persona, es el tamaño del daño si esa
 * cuenta se pierde.
 */
final class SoporteControlador
{
    public function index()
    {
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $repo = new PlataformaRepo($principal);

        $buscar = trim(Peticion::texto('q', ''));
        $lista  = $repo->empresas($buscar);

        // El pulso se pide solo si son pocas: abrir cien bases para pintar
        // una lista es cambiar una pantalla lenta por una inservible.
        $conPulso = count($lista) <= 40;
        foreach ($lista as &$e) {
            $e['pulso'] = $conPulso ? $repo->pulso($e['nombre_base_datos']) : null;
        }
        unset($e);

        Plantilla::pagina('soporte/index', [
            'titulo'    => 'Empresas',
            'icono'     => 'cliente',
            'subtitulo' => count($lista) . ' registradas',
            'empresas'  => $lista,
            'resumen'   => $repo->resumen(),
            'buscar'    => $buscar,
            'conPulso'  => $conPulso,
            'ultima'    => Migraciones::VERSION,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function ficha($id)
    {
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        $f = (new PlataformaRepo($principal))->ficha($id);
        if (!$f) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
            return;
        }
        Plantilla::pagina('soporte/ficha', [
            'titulo'    => $f['empresa']['nombre_empresa'],
            'icono'     => 'cliente',
            'subtitulo' => 'Ficha técnica',
            'f'         => $f,
            'ultima'    => Migraciones::VERSION,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /**
     * Restablece la contraseña de un usuario de cualquier empresa.
     * La nueva se muestra una vez y no se guarda en claro.
     */
    public function restablecer()
    {
        if (!$this->token()) $this->volver(null, 'No se pudo verificar el formulario.', 'error');
        $base = $_POST['base'] ?? '';
        $emp  = (int)($_POST['empresa'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            $this->volver($emp, 'Base no válida.', 'error');
        }
        try {
            $repo  = new UsuarioRepo(Conexion::de($base));
            $clave = $repo->restablecerGenerando((int)($_POST['id'] ?? 0));
            $this->volver($emp, 'Contraseña nueva: ' . $clave
                . ' — anótala, no se vuelve a mostrar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($emp, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] soporte/restablecer: ' . $e->getMessage());
            $this->volver($emp, 'No se pudo restablecer.', 'error');
        }
    }

    public function alternar()
    {
        if (!$this->token()) $this->volver(null, 'No se pudo verificar el formulario.', 'error');
        $base = $_POST['base'] ?? '';
        $emp  = (int)($_POST['empresa'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            $this->volver($emp, 'Base no válida.', 'error');
        }
        try {
            // El segundo argumento es quién lo hace, para que el repo
            // impida que alguien se desactive a sí mismo. Soporte no
            // pertenece a esa empresa, así que va en cero: ningún usuario
            // de ahí puede coincidir.
            (new UsuarioRepo(Conexion::de($base)))->alternar((int)($_POST['id'] ?? 0), 0);
            $this->volver($emp, 'Estado de la cuenta cambiado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($emp, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] soporte/alternar: ' . $e->getMessage());
            $this->volver($emp, 'No se pudo cambiar.', 'error');
        }
    }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($empresaId, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . ($empresaId ? '/soporte/' . (int)$empresaId : '/soporte'));
        exit;
    }
}
