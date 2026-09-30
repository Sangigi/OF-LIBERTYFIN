<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Datos\AutenticacionRepo;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Servicio\Migraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Mantenimiento · para el rol de soporte.
 *
 * Dos cosas: apagar secciones que una empresa no usa, y ver el
 * diagnóstico de su base sin tener que pedir accesos ni abrir un
 * cliente de MySQL.
 *
 * Soporte NO mueve dinero. Puede mirar todo y cambiar qué se ve, pero
 * no cobra, no cancela pagos ni asigna comisiones. Si además pudiera,
 * no habría forma de saber si un descuadre lo causó la empresa o quien
 * vino a ayudar.
 */
final class MantenimientoControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ConfigRepo($db);

        Plantilla::pagina('mantenimiento/index', [
            'titulo'       => 'Mantenimiento',
            'icono'        => 'alerta',
            'subtitulo'    => $_SESSION['empresa_nombre'] ?? '',
            'secciones'    => $repo->secciones(),
            'diagnostico'  => $repo->diagnostico(),
            'integraciones'=> Integraciones::estado(),
            'roles'        => Permisos::ROLES,
            'esquema'      => [
                'actual'   => Migraciones::versionDe($db),
                'ultima'   => Migraciones::VERSION,
                'que_hace' => Migraciones::DESCRIPCIONES,
            ],
            'empresas'     => $this->estadoDeLasEmpresas(),
            'aviso'        => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function secciones()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        try {
            (new ConfigRepo($db))->alternarSeccion($_POST['seccion'] ?? '');
            $this->volver('Sección actualizada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] mantenimiento: ' . $e->getMessage());
            $this->volver('No se pudo cambiar la sección.', 'error');
        }
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /mantenimiento'); exit;
    }

    /**
     * En qué versión de esquema está cada empresa.
     *
     * Solo lee: abre cada base, pregunta su versión y cierra. Si una no
     * responde se reporta como inalcanzable en vez de tumbar la pantalla.
     */
    private function estadoDeLasEmpresas()
    {
        $r = [];
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            foreach ((new AutenticacionRepo($principal))->empresas() as $e) {
                $base = $e['nombre_base_datos'];
                $fila = ['nombre' => $e['nombre_empresa'], 'base' => $base,
                         'activo' => !empty($e['activo']), 'version' => null, 'error' => null];
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
                    $fila['error'] = 'nombre de base inválido';
                } else {
                    try { $fila['version'] = Migraciones::versionDe(Conexion::de($base)); }
                    catch (\Throwable $ex) { $fila['error'] = 'no responde'; }
                }
                $r[] = $fila;
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] estado de empresas: ' . $e->getMessage());
        }
        return $r;
    }

    /** Pone al día todas las bases que estén atrasadas. */
    public function migrar()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        $hechas = 0; $fallaron = 0;
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            foreach ((new AutenticacionRepo($principal))->empresas() as $e) {
                $base = $e['nombre_base_datos'];
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) { $fallaron++; continue; }
                try {
                    $db = Conexion::de($base);
                    if (Migraciones::alDia($db)) continue;
                    $r = Migraciones::aplicar($db);
                    if (Migraciones::alDia($db)) $hechas++; else $fallaron++;
                } catch (\Throwable $ex) {
                    error_log('[LibertyFin] migrar ' . $base . ': ' . $ex->getMessage());
                    $fallaron++;
                }
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] migrar: ' . $e->getMessage());
            $this->volver('No se pudo leer la lista de empresas.', 'error');
        }
        $this->volver($hechas . ' empresa' . ($hechas==1?'':'s') . ' al día'
            . ($fallaron ? ', ' . $fallaron . ' con problema (revisa el log)' : '.'),
            $fallaron ? 'error' : 'ok');
    }
}
