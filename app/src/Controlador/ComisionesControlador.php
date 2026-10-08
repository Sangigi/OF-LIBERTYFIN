<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ComisionRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

final class ComisionesControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ComisionRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $sinDueno = $repo->sinDueno();

        // Para cada renglón sin dueño, los candidatos de su área.
        $candidatos = [];
        foreach ($sinDueno as $s) {
            $a = $s['area_nombre'];
            if (!isset($candidatos[$a])) $candidatos[$a] = $repo->colaboradoresDe($a);
        }

        Plantilla::pagina('comisiones/index', [
            'titulo'     => 'Comisiones',
            'icono'      => 'comi',
            'subtitulo'  => Fechas::rotulo($desde, $hasta),
            'resumen'    => $repo->resumen($desde, $hasta),
            'equipo'     => $repo->porColaborador($desde, $hasta),
            'areas'      => $repo->porArea($desde, $hasta),
            'desde'      => $desde,
            'hasta'      => $hasta,
            'pagina'     => max(1, Peticion::entero('p', 1)),
            'porPag'     => 12,
            'liberacion' => $repo->liberacion($desde, $hasta),
            // Cinco y cinco: las tres tarjetas de abajo se ven juntas, y
            // con cantidades distintas una queda enana al lado de otra.
            'atadas'     => $repo->atadas($desde, $hasta, 5),
            'sin_dueno'  => $sinDueno,
            'candidatos' => $candidatos,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /**
     * Fragmento HTML con las ventas de un colaborador. Lo pide el panel
     * expandible de la tarjeta "Por colaborador"; no es una página.
     */
    public function colaborador($id)
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ComisionRepo($db);

        $desde  = Peticion::fecha('desde', date('Y-m-01'));
        $hasta  = Peticion::fecha('hasta', date('Y-m-t'));
        $nombre = Peticion::texto('nombre');
        $equipo = Peticion::texto('equipo');
        $q      = mb_substr(Peticion::texto('q'), 0, 100);

        $d = $repo->detalleColaborador((int)$id, $nombre, $equipo, $desde, $hasta,
                                       Peticion::entero('p', 1), Peticion::POR_PAGINA, $q);

        $base = '/comisiones/colaborador/' . (int)$id;
        $qs   = function ($p) use ($desde, $hasta, $nombre, $equipo, $q) {
            $x = ['desde' => $desde, 'hasta' => $hasta,
                  'nombre' => $nombre, 'equipo' => $equipo, 'p' => $p];
            if ($q !== '') $x['q'] = $q;        // la paginación conserva la búsqueda
            return http_build_query($x);
        };

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        Plantilla::parcial('comisiones/detalle', [
            'd'      => $d,
            'enlace' => function ($n) use ($base, $qs) { return $base . '?' . $qs($n); },
        ]);
    }

    /** Asigna dueño a un renglón que estaba en POR ASIGNAR. */
    public function reasignar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver('Solo un administrador puede asignar comisiones.', 'error');
        }
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }

        try {
            $nombre = (new ComisionRepo($db))->reasignar(
                (int)($_POST['renglon'] ?? 0),
                (int)($_POST['colaborador'] ?? 0)
            );
            \LibertyFin\Servicio\Auditoria::anota('comision.reasignar',
                'renglón ' . (int)($_POST['renglon'] ?? 0),
                'POR ASIGNAR', $nombre);
            $this->volver('Comisión asignada a ' . $nombre . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] reasignar: ' . $e->getMessage());
            $this->volver('No se pudo asignar. Quedó anotado el error.', 'error');
        }
    }

    /**
     * ASIGNAR COMISIONES EN LOTE · la vista previa.
     *
     * Se filtran las ventas (periodo, área, especialista, solo las que no
     * tienen comisión) y se enseñan TODAS antes de asignar nada, cada una
     * con su estado: las que no se pueden salen sin marcar y con el motivo.
     * Comisionar en lote sin ver la lista es la forma más rápida de pagar
     * dos veces o de comisionar la venta equivocada.
     */
    public function lote()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ComisionRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));
        $area  = Peticion::texto('area');
        $esp   = Peticion::texto('esp');                 // '' | 'sin' | id
        $solo  = Peticion::texto('solo', '1') === '1';   // solo ventas sin comisión
        $tope  = 300;

        // El área y "sin comisión" se miran producto por producto, así que
        // se filtran aquí y no en la consulta. Se traen de sobra para que
        // el tope no se coma ventas que sí cumplen.
        $ventas = $repo->paraLote($desde, $hasta,
            $esp === 'sin' ? 'sin' : ((int)$esp > 0 ? (int)$esp : null), 2000);

        $quedan = [];
        foreach ($ventas as $v) {
            $obj = self::objetivo($v['lineas'], $area);
            if ($v['lineas']) {
                if (!$obj) continue;                         // ningún producto del área
                if ($solo && !array_filter($obj, function ($l) { return $l['con'] === ''; })) continue;
            } else {
                // Sin productos: sale, para que se vea por qué no se puede.
                if ($area !== '' && mb_strtolower(trim($v['area'])) !== mb_strtolower($area)) continue;
                if ($solo && trim((string)$v['comisiones']) !== '') continue;
            }
            $v['objetivo'] = array_column($obj, 'id');
            $quedan[] = $v;
        }
        $ventas  = $quedan;
        $cortado = count($ventas) > $tope;
        if ($cortado) $ventas = array_slice($ventas, 0, $tope);

        Plantilla::pagina('comisiones/lote', [
            'titulo'    => 'Comisiones en lote',
            'icono'     => 'comi',
            'subtitulo' => Fechas::rotulo($desde, $hasta) . ' · asignar a varias ventas',
            'ventas'    => $ventas,
            'cortado'   => $cortado,
            'tope'      => $tope,
            'equipo'    => $repo->equipoPorArea(),
            'areas'     => (new \LibertyFin\Datos\CatalogoRepo($db))->areas(),
            'desde'     => $desde, 'hasta' => $hasta, 'area' => $area, 'esp' => $esp,
            'solo'      => $solo,
            'resultado' => $_SESSION['lf_lote'] ?? null,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_lote'], $_SESSION['lf_aviso']);
    }

    /**
     * ASIGNAR COMISIONES EN LOTE · la asignación.
     *
     * Una por una con AsignarComision: las MISMAS reglas que en el detalle de
     * la venta (un solo producto, base comisionable mayor a cero, un mismo
     * colaborador no dos veces). Cada venta va en su propia transacción: si
     * una no se puede, las demás sí quedan, y al final se dice cuáles no y
     * por qué en vez de detenerse en la primera.
     */
    public function asignarLote()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        // De vuelta a la misma lista, con los mismos filtros.
        $volverA = '/comisiones/lote?' . http_build_query([
            'desde' => (string)($_POST['desde'] ?? ''), 'hasta' => (string)($_POST['hasta'] ?? ''),
            'area'  => (string)($_POST['area'] ?? ''),  'esp'   => (string)($_POST['esp'] ?? ''),
            'solo'  => (string)($_POST['solo'] ?? '1'),
        ]);
        $falla = function ($texto) use ($volverA) {
            $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => 'error'];
            header('Location: ' . $volverA); exit;
        };

        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $falla('Solo un administrador puede asignar comisiones.');
        }
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $falla('No se pudo verificar el formulario.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ventas'] ?? [])))));
        $quien = (string)($_POST['colaborador'] ?? '');     // id de colaborador o 'esp'
        $pct   = round((float)($_POST['porcentaje'] ?? 0), 2);

        if (!$ids)                  $falla('Marca al menos una venta.');
        if (count($ids) > 300)      $falla('Son demasiadas ventas de una vez: máximo 300.');
        if ($quien === '')          $falla('Elige a quién se le asigna la comisión.');
        if ($pct <= 0 || $pct > 100) $falla('El porcentaje debe ser mayor a 0 y hasta 100.');

        // Folio y especialista de cada venta, para los mensajes y para el modo
        // "al especialista de cada venta".
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id, codigo_venta, especialista_id FROM ventas WHERE id IN ($marcas)");
        $st->execute($ids);
        $info = [];
        foreach ($st->fetchAll() as $x) $info[(int)$x['id']] = $x;

        // Los productos de cada venta: la comisión va a cada uno (o solo a
        // los del área filtrada), con su propia base.
        $area   = trim((string)($_POST['area'] ?? ''));
        $lineas = (new ComisionRepo($db))->lineasDe($ids);

        $srv = new \LibertyFin\Servicio\AsignarComision($db);
        $hechas = 0; $enVentas = 0; $monto = 0.0; $omitidas = [];
        foreach ($ids as $id) {
            $folio = $info[$id]['codigo_venta'] ?? ('#' . $id);
            if (!isset($info[$id])) { $omitidas[] = [$folio, 'La venta ya no existe']; continue; }

            $col = $quien === 'esp' ? (int)($info[$id]['especialista_id'] ?? 0) : (int)$quien;
            if ($col <= 0) { $omitidas[] = [$folio, 'No tiene especialista']; continue; }

            $todas = $lineas[$id] ?? [];
            if (!$todas) { $omitidas[] = [$folio, 'La venta no tiene productos']; continue; }
            $obj = self::objetivo($todas, $area);
            if (!$obj) { $omitidas[] = [$folio, 'No tiene productos de ' . $area]; continue; }

            // Lo que esa persona ya comisiona no se toca ni se reporta como
            // error: en la lista ya salía como no incluido.
            $libres = array_filter($obj, function ($l) use ($col) {
                return !in_array($col, array_map('intval', explode(',', $l['con'])), true);
            });
            if (!$libres) {
                $omitidas[] = [$folio, $quien === 'esp' ? 'Su especialista ya tiene comisión' : 'Ya tiene comisión de esta persona'];
                continue;
            }

            $varios = count($todas) > 1;
            $alguna = false;
            foreach ($libres as $l) {
                $donde = $varios ? $folio . ' · ' . $l['producto'] : $folio;
                try {
                    $r = $srv->asignar($id, $col, $pct, $l['id']);
                    $hechas++;
                    $alguna = true;
                    $monto += (float)$r['asignada'];
                } catch (\InvalidArgumentException $e) {
                    $omitidas[] = [$donde, $e->getMessage()];
                } catch (\Throwable $e) {
                    error_log('[LibertyFin] comision en lote, venta ' . $id . ' linea ' . $l['id'] . ': ' . $e->getMessage());
                    $omitidas[] = [$donde, 'No se pudo asignar (quedó anotado el error)'];
                }
            }
            if ($alguna) $enVentas++;
        }

        if ($hechas > 0) {
            Auditoria::anota('comision.lote',
                $hechas . ' comisi' . ($hechas === 1 ? 'ón' : 'ones') . ' en ' . $enVentas . ' venta' . ($enVentas === 1 ? '' : 's'),
                null,
                ($quien === 'esp' ? 'especialista de cada venta' : 'colaborador ' . (int)$quien)
                . ' al ' . $pct . '%' . ($area !== '' ? ' · ' . $area : ''));
        }
        $_SESSION['lf_lote'] = ['hechas' => $hechas, 'ventas' => $enVentas, 'monto' => round($monto, 2),
                                'omitidas' => $omitidas, 'pct' => $pct];
        header('Location: ' . $volverA); exit;
    }

    /**
     * Los productos de una venta que se comisionan en lote: los del área
     * elegida, o todos si no se eligió. El área se compara sin distinguir
     * mayúsculas, como en los reportes.
     */
    private static function objetivo(array $lineas, $area)
    {
        if ($area === '') return $lineas;
        $buscada = mb_strtolower(trim($area));
        return array_values(array_filter($lineas, function ($l) use ($buscada) {
            return mb_strtolower(trim((string)$l['area'])) === $buscada;
        }));
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /comisiones'); exit;
    }

}
