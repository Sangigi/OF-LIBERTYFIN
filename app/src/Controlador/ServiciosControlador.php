<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\ServicioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Promociones;
use LibertyFin\Vista\Plantilla;

final class ServiciosControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        // Las promociones son una pestaña de Productos: hablan de sus precios.
        if (Peticion::opcion('t', ['catalogo', 'promociones'], 'catalogo') === 'promociones') {
            return $this->promociones($db);
        }
        $repo = new ServicioRepo($db);

        $desde  = Peticion::fecha('desde', date('Y-m-01'));
        $hasta  = Peticion::fecha('hasta', date('Y-m-t'));
        $buscar = Peticion::texto('q');
        $editar = Peticion::entero('editar');
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;
        $total  = $repo->cuantos($buscar);

        Plantilla::pagina('servicios/index', [
            'titulo'    => 'Productos',
            'icono'     => 'serv',
            'subtitulo' => Fechas::rotulo($desde, $hasta) . ' · catálogo y desempeño',
            'resumen'   => $repo->resumen($desde, $hasta),
            'catalogo'  => $repo->catalogo($desde, $hasta, $buscar, $porPag, ($pagina-1)*$porPag),
            'pagina'    => $pagina,
            'paginas'   => max(1, (int)ceil($total / $porPag)),
            'total'     => $total,
            'top'       => $repo->masFacturan($desde, $hasta, 5),
            'areas'     => $repo->porArea($desde, $hasta),
            'desde'     => $desde, 'hasta' => $hasta, 'buscar' => $buscar,
            'categorias'=> $repo->categorias(),
            'editando'  => $editar ? $repo->uno_($editar) : null,
            'abrir'     => $editar > 0 || Peticion::texto('nuevo') !== '',
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
            'vigentes'  => $this->cuantasVigentes($db),
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');

        $repo = new ServicioRepo($db);
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($id) {
                // Solo se audita el PRECIO. Corregir una falta de ortografía
                // en el nombre no le interesa a nadie dentro de seis meses;
                // que alguien bajó un servicio de $26,000 a $1, sí.
                $antes = $repo->porId($id);
                $repo->actualizar($id, $_POST);
                $msg = 'Producto actualizado.';
                if ($antes && (float)($antes['precio'] ?? 0) !== (float)($_POST['precio'] ?? 0)) {
                    Auditoria::anota('servicio.precio', $antes['nombre'],
                        $antes['precio'], $_POST['precio'] ?? 0);
                }
            }
            else     { $id = $repo->crear($_POST);     $msg = 'Producto dado de alta.'; }

            // La imagen va aparte de los datos: si falla, el servicio ya
            // quedó guardado y solo se avisa de la imagen.
            $msg .= $this->imagen($repo, $id);
            $this->volver($msg, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar servicio: ' . $e->getMessage());
            $this->volver('No se pudo guardar el producto.', 'error');
        }
    }

    /**
     * Sube, reemplaza o quita la imagen del servicio. Devuelve un texto
     * para añadir al aviso ('' si no hubo nada que hacer).
     */
    private function imagen(ServicioRepo $repo, $id)
    {
        $id = (int)$id;
        if (!$id) return '';
        $anterior = $repo->imagenDe($id);

        if (!empty($_POST['quitar_imagen']) && empty($_FILES['imagen']['name'])) {
            $repo->guardarImagen($id, null);
            \LibertyFin\Servicio\Archivos::borrar($anterior);
            return ' Imagen quitada.';
        }
        if (empty($_FILES['imagen']['name'])) return '';

        try {
            $ruta = \LibertyFin\Servicio\Archivos::imagen($_FILES['imagen'], 'serv');
            $repo->guardarImagen($id, $ruta);
            if ($anterior) \LibertyFin\Servicio\Archivos::borrar($anterior);
            return ' Imagen guardada.';
        } catch (\InvalidArgumentException $e) {
            return ' Pero la imagen no se guardó: ' . $e->getMessage() . '.';
        } catch (\Throwable $e) {
            error_log('[LibertyFin] imagen de servicio: ' . $e->getMessage());
            return ' Pero la imagen no se pudo guardar.';
        }
    }

    /** Activa o desactiva. Nunca se borra: rompería las ventas viejas. */
    public function alternar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');
        try {
            $a = (new ServicioRepo($db))->alternar((int)($_POST['id'] ?? 0));
            Auditoria::anota('servicio.alternar', 'servicio ' . (int)($_POST['id'] ?? 0),
                $a ? 'inactivo' : 'activo', $a ? 'activo' : 'inactivo');
            $this->volver($a ? 'Producto activado.' : 'Producto desactivado. Ya no aparece en Caja.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar servicio: ' . $e->getMessage());
            $this->volver('No se pudo cambiar el estado.', 'error');
        }
    }

    // ════════════════════════════════════════════════════════
    //  PROMOCIONES
    // ════════════════════════════════════════════════════════

    private function promociones($db)
    {
        $srv    = new Promociones($db);
        $editar = Peticion::entero('editar');
        $promos = $srv->listar();
        Plantilla::pagina('servicios/promociones', [
            'titulo'    => 'Productos',
            'icono'     => 'serv',
            'subtitulo' => 'Promociones · se aplican solas en Caja',
            'promos'    => $promos,
            // Para elegir a qué aplica: los productos activos y las áreas.
            'productos' => (new \LibertyFin\Datos\CatalogoRepo($db))->servicios(null, '', '', 3000),
            'categorias'=> (new ServicioRepo($db))->categorias(),
            'editando'  => $editar ? $srv->una($editar) : null,
            'abrir'     => $editar > 0 || Peticion::texto('nueva') !== '',
            'puedeEditar' => \LibertyFin\Dominio\Permisos::puede('editar.servicios'),
            'vigentes'  => count(array_filter($promos, function ($p) {
                return $p['estado']['clave'] === 'vigente'; })),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardarPromocion()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error', '?t=promociones');
        $srv = new Promociones($db);
        $id  = (int)($_POST['id'] ?? 0);
        try {
            $antes = $id ? $srv->una($id) : null;
            $id = $srv->guardar($_POST, $_SESSION['usuario_nombre'] ?? '');
            $p  = $srv->una($id);
            Auditoria::anota('promocion.guardar', $p['nombre'],
                $antes ? Promociones::describir($antes) : null, Promociones::describir($p));
            $this->volver($antes ? 'Promoción actualizada.' : 'Promoción creada. Caja ya la aplica.',
                          'ok', '?t=promociones');
        } catch (\InvalidArgumentException $e) {
            // Se regresa al formulario con lo que estaba editando, no a la lista.
            $this->volver($e->getMessage(), 'error',
                          '?t=promociones&' . ($id ? 'editar=' . $id : 'nueva=1'));
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar promoción: ' . $e->getMessage());
            $this->volver('No se pudo guardar la promoción.', 'error', '?t=promociones');
        }
    }

    public function alternarPromocion()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error', '?t=promociones');
        $srv = new Promociones($db);
        try {
            $id = (int)($_POST['id'] ?? 0);
            $activa = $srv->alternar($id);
            $p = $srv->una($id);
            Auditoria::anota('promocion.estado', $p ? $p['nombre'] : 'promoción ' . $id,
                $activa ? 'pausada' : 'activa', $activa ? 'activa' : 'pausada');
            $this->volver($activa ? 'Promoción reanudada.' : 'Promoción pausada. Caja ya no la aplica.',
                          'ok', '?t=promociones');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar promoción: ' . $e->getMessage());
            $this->volver('No se pudo cambiar la promoción.', 'error', '?t=promociones');
        }
    }

    public function borrarPromocion()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error', '?t=promociones');
        $srv = new Promociones($db);
        try {
            $id = (int)($_POST['id'] ?? 0);
            $p  = $srv->una($id);
            $srv->borrar($id);
            Auditoria::anota('promocion.borrar', $p ? $p['nombre'] : 'promoción ' . $id,
                $p ? Promociones::describir($p) : null, null);
            $this->volver('Promoción borrada.', 'ok', '?t=promociones');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error', '?t=promociones');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] borrar promoción: ' . $e->getMessage());
            $this->volver('No se pudo borrar la promoción.', 'error', '?t=promociones');
        }
    }

    /** Para la pestaña: cuántas se están aplicando hoy. */
    private function cuantasVigentes($db)
    {
        try {
            return count(array_filter((new Promociones($db))->listar(), function ($p) {
                return $p['estado']['clave'] === 'vigente'; }));
        } catch (\Throwable $e) { return 0; }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto, $tipo, $donde = '')
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /servicios' . $donde); exit;
    }
}
