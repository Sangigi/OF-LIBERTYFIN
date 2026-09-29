<?php
namespace LibertyFin\Datos;

/**
 * Consultas de ventas y cobranza. UN solo lugar.
 *
 * En el sistema anterior estas consultas estaban repartidas en 77 sitios,
 * y no todas contaban igual. La distinción que más costó fue esta:
 *
 *   VENDIDO  = suma de ventas.total        lo facturado
 *   COBRADO  = suma de venta_pagos.monto   el dinero que entró
 *
 * Sumar `total` como si fuera ingreso fue lo que infló el histórico a
 * $558,057.60 cuando lo real eran $289,468.05.
 *
 * Y el periodo se aplica sobre DOS columnas distintas a propósito:
 *   ventas.fecha         -> qué ventas se listan
 *   venta_pagos.fecha_pago -> qué cobros cuentan
 * Un abono de octubre sobre una venta de agosto es ingreso de OCTUBRE.
 */
final class VentaRepo extends Repo
{
    /**
     * Filtro de periodo sin envolver la columna en DATE().
     * DATE(v.fecha) >= ? impide usar el índice: medido, 6.3 ms contra 1.2 ms.
     */
    private function rango($desde, $hasta)
    {
        $w = []; $p = [];
        if ($desde) { $w[] = 'v.fecha >= ?'; $p[] = $desde . ' 00:00:00'; }
        if ($hasta) { $w[] = 'v.fecha < ?';  $p[] = date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'; }
        return [$w, $p];
    }

    private function rangoPagos($desde, $hasta)
    {
        $w = 'WHERE cancelado = 0'; $p = [];
        if ($desde) { $w .= ' AND fecha_pago >= ?'; $p[] = $desde; }
        if ($hasta) { $w .= ' AND fecha_pago <= ?'; $p[] = $hasta; }
        return [$w, $p];
    }

    /** Las cifras del periodo: cobrado, vendido, saldo. */
    public function resumen($desde, $hasta, array $filtros = [])
    {
        list($w, $p)   = $this->rango($desde, $hasta);
        list($pw, $pp) = $this->rangoPagos($desde, $hasta);

        if (!empty($filtros['estado'])) { $w[] = 'v.estado = ?'; $p[] = $filtros['estado']; }
        if (!empty($filtros['buscar'])) {
            $w[] = '(v.codigo_venta LIKE ? OR c.nombre LIKE ? OR v.descripcion LIKE ?)';
            $l = '%' . $filtros['buscar'] . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        return $this->uno("
            SELECT
                COUNT(DISTINCT v.id) AS ventas,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN COALESCE(pg.cobrado,0) END),0) AS cobrado,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN v.total END),0) AS vendido,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN v.total - COALESCE(pg.cobrado,0) END),0) AS saldo,
                COALESCE(AVG(CASE WHEN v.estado <> 'cancelada' THEN v.total END),0) AS promedio,
                SUM(CASE WHEN v.estado = 'cancelada' THEN 1 ELSE 0 END) AS canceladas,
                SUM(CASE WHEN v.estado <> 'cancelada'
                          AND v.total - COALESCE(pg.cobrado,0) > 0.01 THEN 1 ELSE 0 END) AS con_saldo
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos {$pw} GROUP BY venta_id )
                   pg ON pg.venta_id = v.id
            {$where}
        ", array_merge($pp, $p)) ?: [];
    }

    /** El listado, con el avance de cobro de cada venta. */
    public function listado($desde, $hasta, array $filtros = [], $limite = 25, $desfase = 0)
    {
        list($w, $p)   = $this->rango($desde, $hasta);
        list($pw, $pp) = $this->rangoPagos($desde, $hasta);

        if (!empty($filtros['estado'])) { $w[] = 'v.estado = ?'; $p[] = $filtros['estado']; }
        if (!empty($filtros['buscar'])) {
            $w[] = '(v.codigo_venta LIKE ? OR c.nombre LIKE ? OR v.descripcion LIKE ?)';
            $l = '%' . $filtros['buscar'] . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        return $this->todos("
            SELECT v.id, v.codigo_venta, v.fecha, v.total, v.estado,
                   v.area_nombre,
                   c.nombre AS cliente,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos {$pw} GROUP BY venta_id )
                   pg ON pg.venta_id = v.id
            {$where}
            ORDER BY v.fecha DESC
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase . "
        ", array_merge($pp, $p));
    }

    /** Cobrado por mes, para la gráfica. Va por fecha_pago, no por fecha de venta. */
    public function cobradoPorMes($meses = 6)
    {
        return $this->todos("
            SELECT DATE_FORMAT(p.fecha_pago, '%Y-%m') AS mes,
                   SUM(p.monto) AS cobrado,
                   COUNT(*)     AS pagos
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= DATE_SUB(CURDATE(), INTERVAL " . (int)$meses . " MONTH)
            GROUP BY DATE_FORMAT(p.fecha_pago, '%Y-%m')
            ORDER BY mes
        ");
    }

    /** Los saldos abiertos más grandes. */
    public function saldosAbiertos($tope = 5)
    {
        return $this->todos("
            SELECT v.id, v.codigo_venta, v.fecha, v.total, v.area_nombre,
                   c.nombre AS cliente,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada'
              AND v.total - COALESCE(pg.cobrado,0) > 0.01
            ORDER BY saldo DESC
            LIMIT " . (int)$tope . "
        ");
    }


    // ─────────────────────────────────────────────────────────────
    // DETALLE DE UNA VENTA
    // ─────────────────────────────────────────────────────────────

    public function detalle($id)
    {
        return $this->uno("
            SELECT v.*,
                   c.nombre AS cliente, c.telefono,
                   u.nombre AS vendedor,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo,
                   COALESCE(g.gastos,0) AS gastos
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN usuarios u ON u.id = v.usuario_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            WHERE v.id = ?", [(int)$id]);
    }

    public function lineas($id)
    {
        return $this->todos("
            SELECT vd.*, p.nombre AS producto, p.codigo
            FROM venta_detalles vd
            LEFT JOIN productos p ON p.id = vd.producto_id
            WHERE vd.venta_id = ?", [(int)$id]);
    }

    /** Los pagos, incluidos los cancelados: el rastro importa. */
    public function pagos($id)
    {
        return $this->todos("
            SELECT p.*, COALESCE(pc.comision,0) AS comision
            FROM venta_pagos p
            LEFT JOIN ( SELECT pago_id, SUM(monto) comision FROM pago_comisiones
                        GROUP BY pago_id ) pc ON pc.pago_id = p.id
            WHERE p.venta_id = ?
            ORDER BY p.fecha_pago, p.id", [(int)$id]);
    }

    public function gastos($id)
    {
        return $this->todos("
            SELECT id, concepto, monto, categoria, fecha
            FROM gastos WHERE venta_id = ?
              AND tipo = 'manual' AND categoria <> 'Costo de venta'
            ORDER BY id", [(int)$id]);
    }

    /**
     * Comisiones de la venta: asignada si liquida, devengada por lo cobrado,
     * y lo que falta liberar. Las tres juntas, que es como se entienden.
     */
    public function comisiones($id)
    {
        return $this->todos("
            SELECT vc.id, vc.colaborador_nombre, vc.area_nombre, vc.porcentaje_regla AS pct,
                   vc.monto_comision AS asignada,
                   COALESCE(pc.devengada,0) AS devengada,
                   vc.monto_comision - COALESCE(pc.devengada,0) AS pendiente
            FROM venta_comisiones vc
            LEFT JOIN ( SELECT venta_comision_id, SUM(monto) devengada
                        FROM pago_comisiones GROUP BY venta_comision_id ) pc
                   ON pc.venta_comision_id = vc.id
            WHERE vc.venta_id = ? AND vc.cancelada = 0
            ORDER BY vc.porcentaje_regla DESC", [(int)$id]);
    }

    public function cuantas($desde, $hasta, array $filtros = [])
    {
        list($w, $p) = $this->rango($desde, $hasta);
        if (!empty($filtros['estado'])) { $w[] = 'v.estado = ?'; $p[] = $filtros['estado']; }
        if (!empty($filtros['buscar'])) {
            $w[] = '(v.codigo_venta LIKE ? OR c.nombre LIKE ? OR v.descripcion LIKE ?)';
            $l = '%' . $filtros['buscar'] . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        return (int)$this->valor("
            SELECT COUNT(DISTINCT v.id) FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id {$where}", $p);
    }
}
