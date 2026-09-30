<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\AjustesRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

/**
 * Ajustes: sucursales, áreas, colaboradores y categorías en una sección
 * con pestañas.
 *
 * En el sistema anterior eran cuatro pantallas de entre 700 y 1,500
 * líneas. Son tablas chicas que se tocan una vez al mes y siempre juntas:
 * darles una pantalla completa a cada una solo obliga a navegar de más.
 */
final class AjustesControlador
{
    const PESTANAS = [
        'empresa'      => 'Empresa',
        'sucursales'   => 'Sucursales',
        'comisiones'   => 'Áreas y colaboradores',
        'categorias'   => 'Categorías',
        'integraciones'=> 'Integraciones',
    ];

    public function index($pestana = null)
    {
        $this->soloAdmin();
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new AjustesRepo($db);

        $p = Peticion::opcion('t', array_keys(self::PESTANAS), 'empresa');
        $editar = Peticion::entero('editar');

        $datos = [
            'titulo'     => 'Ajustes',
            'icono'      => 'serv',
            'subtitulo'  => self::PESTANAS[$p],
            'pestana'    => $p,
            'pestanas'   => self::PESTANAS,
            'editar'     => $editar,
            'abrir'      => $editar > 0 || Peticion::texto('nuevo') !== '',
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
            'sucursales' => [], 'areas' => [], 'colaboradores' => [], 'categorias' => [],
            'empresa' => null, 'integraciones' => [],
        ];
        if ($p === 'integraciones') {
            $datos['integraciones'] = \LibertyFin\Servicio\Integraciones::estado();
        }
        if ($p === 'empresa') {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            $datos['empresa'] = (new \LibertyFin\Datos\EmpresaRepo($principal))
                                ->uno($_SESSION['empresa_id'] ?? 0);
        }
        if ($p === 'sucursales') $datos['sucursales'] = $repo->sucursales();
        if ($p === 'comisiones') {
            $datos['areas'] = $repo->areas();
            $datos['colaboradores'] = $repo->colaboradores();
        }
        if ($p === 'categorias') $datos['categorias'] = $repo->categorias();

        Plantilla::pagina('ajustes/index', $datos);
        unset($_SESSION['lf_aviso']);
    }

    /** Un solo guardar para las cuatro tablas: la diferencia es el `que`. */
    public function guardar()
    {
        $this->soloAdmin(); $this->token();
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new AjustesRepo($db);
        $que  = $_POST['que'] ?? '';
        $id   = (int)($_POST['id'] ?? 0);

        $mapa = [
            'sucursal'    => ['guardarSucursal',    'sucursales', 'Sucursal'],
            'area'        => ['guardarArea',        'comisiones', 'Área'],
            'colaborador' => ['guardarColaborador', 'comisiones', 'Colaborador'],
            'categoria'   => ['guardarCategoria',   'categorias', 'Categoría'],
        ];
        // La empresa vive en la base principal: no pasa por AjustesRepo.
        if ($que === 'empresa') {
            try {
                $principal = Conexion::de($GLOBALS['lf_bd_principal']);
                (new \LibertyFin\Datos\EmpresaRepo($principal))
                    ->actualizar($_SESSION['empresa_id'] ?? 0, $_POST);
                $_SESSION['empresa_nombre'] = trim($_POST['nombre_empresa'] ?? '');
                $this->volver('empresa', 'Datos de la empresa actualizados.', 'ok');
            } catch (\InvalidArgumentException $e) {
                $this->volver('empresa', $e->getMessage(), 'error');
            } catch (\Throwable $e) {
                error_log('[LibertyFin] ajustes/empresa: ' . $e->getMessage());
                $this->volver('empresa', 'No se pudo guardar.', 'error');
            }
        }
        if (!isset($mapa[$que])) $this->volver('sucursales', 'Petición no válida.', 'error');
        list($metodo, $pestana, $rotulo) = $mapa[$que];

        try {
            $repo->$metodo($id, $_POST);
            $this->volver($pestana, $rotulo . ($id ? ' actualizada.' : ' dada de alta.'), 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($pestana, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ajustes/' . $que . ': ' . $e->getMessage());
            $this->volver($pestana, 'No se pudo guardar.', 'error');
        }
    }

    public function alternar()
    {
        $this->soloAdmin(); $this->token();
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new AjustesRepo($db);
        $que  = $_POST['que'] ?? '';
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($que === 'sucursal')    { $repo->alternarSucursal($id);    $p = 'sucursales'; }
            elseif ($que === 'colaborador') { $repo->alternarColaborador($id); $p = 'comisiones'; }
            else $this->volver('sucursales', 'Petición no válida.', 'error');
            $this->volver($p, 'Estado cambiado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($que === 'sucursal' ? 'sucursales' : 'comisiones', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ajustes/alternar: ' . $e->getMessage());
            $this->volver('sucursales', 'No se pudo cambiar el estado.', 'error');
        }
    }

    private function soloAdmin()
    {
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            http_response_code(403);
            Plantilla::pagina('errores/404', ['titulo'=>'Sin permiso','icono'=>'alerta','subtitulo'=>'']);
            exit;
        }
    }

    private function token()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('sucursales', 'No se pudo verificar el formulario.', 'error');
        }
    }

    private function volver($pestana, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ajustes?t=' . $pestana); exit;
    }
}
