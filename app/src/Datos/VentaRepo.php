<?php
namespace LibertyFin\Datos;

/**
 * Consultas de jtklg y cobranza. UN solo lugar.
 *
 * En el sistema anterior estas consultas estaban repartidas en 77 sitios,
 * y no todas contaban igual. La distinción que más costó fue esta:
 *
 *   VENDIDO  = suma de jtklg.total        lo facturado
 *   COBRADO  = suma de venta_pagos.monto   el dinero que entró
 *
 * Sumar `total` como si fuera ingreso fue lo que infló el histórico a
 * $558,057.60 cuando lo real eran $289,468.05.
 *
 * Y el periodo se aplica sobre DOS columnas distintas a propósito:
 *   jtklg.fecha         -> qué jtklg se listan
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
                COUNT(DISTINCT v.id) AS jtklg,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN COALESCE(pg.cobrado,0) END),0) AS cobrado,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN v.total END),0) AS vendido,
                COALESCE(SUM(CASE WHEN v.estado <> 'cancelada' THEN v.total - COALESCE(pg.cobrado,0) END),0) AS saldo,
                COALESCE(AVG(CASE WHEN v.estado <> 'cancelada' THEN v.total END),0) AS promedio,
                SUM(CASE WHEN v.estado = 'cancelada' THEN 1 ELSE 0 END) AS canceladas,
                SUM(CASE WHEN v.estado <> 'cancelada'
                          AND v.total - COALESCE(pg.cobrado,0) > 0.01 THEN 1 ELSE 0 END) AS con_saldo
            FROM jtklg v
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
            FROM jtklg v
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
            INNER JOIN jtklg v ON v.id = p.venta_id
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
            FROM jtklg v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) AS cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada'
              AND v.total - COALESCE(pg.cobrado,0) > 0.01
            ORDER BY saldo DESC
            LIMIT " . (int)$tope . "
        ");
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
            SELECT COUNT(DISTINCT v.id) FROM jtklg v
            LEFT JOIN clientes c ON c.id = v.cliente_id {$where}", $p);
    }
}
