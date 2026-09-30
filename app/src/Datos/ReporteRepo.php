<?php
namespace LibertyFin\Datos;

/**
 * Reportes.
 *
 * La cifra que importa no es lo vendido ni lo cobrado: es lo que queda
 * después de pagar lo que la venta costó. Al empezar la migración el
 * sistema decía $558,057.60 de "monto total" sumando `ventas.total`, y
 * lo cobrado de verdad eran $289,468.05.
 *
 * Por eso aquí todo parte del COBRADO y se le restan las tres salidas:
 * gastos de operación, gastos generales y comisiones devengadas.
 *
 * Los criterios de fecha NO son iguales para todo, a propósito:
 *   cobrado             -> venta_pagos.fecha_pago  (cuándo entró el dinero)
 *   gastos de operación -> ventas.fecha            (cuelgan de su venta)
 *   gastos generales    -> gastos.fecha            (cuándo se pagó)
 *   comisiones          -> ventas.fecha            (a qué venta pertenecen)
 */
final class ReporteRepo extends Repo
{
    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    /** El resultado del periodo, línea por línea. */
    public function resultado($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);

        $cobrado = (float)$this->valor("
            SELECT COALESCE(SUM(p.monto),0)
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?", [$a, $b]);

        $vendido = (float)$this->valor("
            SELECT COALESCE(SUM(total),0) FROM ventas
            WHERE estado <> 'cancelada' AND fecha >= ? AND fecha < ?", [$a, $b]);

        $operacion = (float)$this->valor("
            SELECT COALESCE(SUM(g.monto),0)
            FROM gastos g INNER JOIN ventas v ON v.id = g.venta_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
              AND g.tipo = 'manual' AND g.categoria <> 'Costo de venta'", [$a, $b]);

        $generales = (float)$this->valor("
            SELECT COALESCE(SUM(monto),0) FROM gastos
            WHERE venta_id IS NULL AND fecha >= ? AND fecha < ?", [$a, $b]);

        $comisiones = (float)$this->valor("
            SELECT COALESCE(SUM(pc.monto),0)
            FROM pago_comisiones pc INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?", [$a, $b]);

        $iva = (float)$this->valor("
            SELECT COALESCE(SUM(iva),0) FROM ventas
            WHERE estado <> 'cancelada' AND fecha >= ? AND fecha < ?", [$a, $b]);

        return [
            'cobrado'    => $cobrado,
            'vendido'    => $vendido,
            'por_cobrar' => max(0, $vendido - $cobrado),
            'iva'        => $iva,
            'operacion'  => $operacion,
            'generales'  => $generales,
            'comisiones' => $comisiones,
            'queda'      => round($cobrado - $operacion - $generales - $comisiones, 2),
        ];
    }

    /** Lo mismo mes a mes, para ver la tendencia. */
    public function porMes($meses = 6)
    {
        $desde = date('Y-m-01', strtotime("-" . ((int)$meses - 1) . " month"));
        $salida = [];
        for ($i = 0; $i < $meses; $i++) {
            $m  = date('Y-m', strtotime("+{$i} month", strtotime($desde)));
            $r  = $this->resultado($m . '-01', date('Y-m-t', strtotime($m . '-01')));
            $r['mes'] = $m;
            $salida[] = $r;
        }
        return $salida;
    }

    public function porArea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(NULLIF(v.area_nombre,''),'Sin área') AS area,
                   COUNT(DISTINCT v.id)        AS ventas,
                   COALESCE(SUM(v.total),0)    AS vendido,
                   COALESCE(SUM(pg.cobrado),0) AS cobrado,
                   COALESCE(SUM(g.gastos),0)   AS gastos,
                   COALESCE(SUM(cm.comision),0) AS comisiones
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) comision FROM pago_comisiones
                        GROUP BY venta_id ) cm ON cm.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY area ORDER BY cobrado DESC", [$a, $b]);
    }

    public function porServicio($desde, $hasta, $tope = 12)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT p.nombre, p.codigo,
                   COUNT(*) AS veces,
                   SUM(vd.subtotal) AS facturado
            FROM venta_detalles vd
            INNER JOIN ventas v    ON v.id = vd.venta_id
            INNER JOIN productos p ON p.id = vd.producto_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY p.id, p.nombre, p.codigo
            ORDER BY facturado DESC LIMIT " . (int)$tope, [$a, $b]);
    }

    public function porMetodo($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(NULLIF(p.metodo_pago,''),'sin método') AS metodo,
                   SUM(p.monto) AS monto, COUNT(*) AS cobros
            FROM venta_pagos p INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            GROUP BY metodo ORDER BY monto DESC", [$a, $b]);
    }

    public function porColaborador($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT pc.colaborador_nombre AS nombre, pc.area_nombre AS area,
                   ROUND(SUM(pc.monto),2) AS devengado,
                   COUNT(DISTINCT pc.venta_id) AS ventas
            FROM pago_comisiones pc INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY pc.colaborador_nombre, pc.area_nombre
            ORDER BY (pc.colaborador_nombre = 'POR ASIGNAR'), devengado DESC", [$a, $b]);
    }

    /** El detalle en crudo, para exportar. */
    public function detalle($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT v.codigo_venta AS folio, DATE(v.fecha) AS fecha,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   COALESCE(NULLIF(v.area_nombre,''),'Sin área') AS area,
                   v.subtotal, v.iva, v.total,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo,
                   COALESCE(g.gastos,0) AS gastos,
                   COALESCE(cm.comision,0) AS comision,
                   v.estado
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) comision FROM pago_comisiones
                        GROUP BY venta_id ) cm ON cm.venta_id = v.id
            WHERE v.fecha >= ? AND v.fecha < ?
            ORDER BY v.fecha", [$a, $b]);
    }
}
