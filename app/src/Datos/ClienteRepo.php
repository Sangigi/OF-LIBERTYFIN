<?php
namespace LibertyFin\Datos;

/**
 * Clientes.
 *
 * La pregunta que responde esta pantalla no es "quién me compra más",
 * sino "a quién le hablo primero". Por eso la antigüedad del saldo pesa
 * tanto como el monto: $18,000 parados 60 días son peor noticia que
 * $30,000 abonados la semana pasada.
 */
final class ClienteRepo extends Repo
{
    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    public function resumen($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $r = $this->uno("
            SELECT COUNT(DISTINCT v.cliente_id) AS activos,
                   COUNT(DISTINCT CASE WHEN x.compras >= 2 THEN v.cliente_id END) AS recurrentes
            FROM ventas v
            LEFT JOIN ( SELECT cliente_id, COUNT(*) compras FROM ventas
                        WHERE estado <> 'cancelada' GROUP BY cliente_id ) x
                   ON x.cliente_id = v.cliente_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
              AND v.cliente_id IS NOT NULL
        ", [$a, $b]) ?: [];

        // Nuevo = su primera venta cae dentro del periodo.
        $r['nuevos'] = (int)$this->valor("
            SELECT COUNT(*) FROM (
                SELECT cliente_id, MIN(fecha) primera FROM ventas
                WHERE estado <> 'cancelada' AND cliente_id IS NOT NULL
                GROUP BY cliente_id
            ) p WHERE p.primera >= ? AND p.primera < ?", [$a, $b]);

        $s = $this->uno("
            SELECT COUNT(*) AS cuantos, COALESCE(SUM(saldo),0) AS monto FROM (
                SELECT v.cliente_id, SUM(v.total - COALESCE(pg.cobrado,0)) AS saldo
                FROM ventas v
                LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                            WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
                WHERE v.estado <> 'cancelada' AND v.cliente_id IS NOT NULL
                GROUP BY v.cliente_id
                HAVING saldo > 0.01
            ) x") ?: [];
        $r['con_saldo'] = (int)($s['cuantos'] ?? 0);
        $r['saldo']     = (float)($s['monto'] ?? 0);
        return $r;
    }

    public function listado($desde, $hasta, $buscar = '', $limite = 25, $desfase = 0)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $w = ['v.fecha >= ?', 'v.fecha < ?', "v.estado <> 'cancelada'"];
        $p = [$a, $b];
        if ($buscar !== '') { $w[] = '(c.nombre LIKE ? OR c.telefono LIKE ?)';
                              $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l; }
        return $this->todos("
            SELECT c.id, c.nombre, c.telefono,
                   COUNT(DISTINCT v.id)                    AS compras,
                   COALESCE(SUM(v.total),0)                AS facturado,
                   COALESCE(SUM(pg.cobrado),0)             AS cobrado,
                   COALESCE(SUM(v.total - COALESCE(pg.cobrado,0)),0) AS saldo,
                   MAX(v.fecha)                            AS ultima,
                   MAX(v.area_nombre)                      AS area
            FROM ventas v
            INNER JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE " . implode(' AND ', $w) . "
            GROUP BY c.id, c.nombre, c.telefono
            ORDER BY facturado DESC
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantos($desde, $hasta, $buscar = '')
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $w = ['v.fecha >= ?', 'v.fecha < ?', "v.estado <> 'cancelada'"];
        $p = [$a, $b];
        if ($buscar !== '') { $w[] = '(c.nombre LIKE ? OR c.telefono LIKE ?)';
                              $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l; }
        return (int)$this->valor("
            SELECT COUNT(DISTINCT c.id) FROM ventas v
            INNER JOIN clientes c ON c.id = v.cliente_id
            WHERE " . implode(' AND ', $w), $p);
    }

    public function masFacturan($desde, $hasta, $tope = 5)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT c.nombre, COALESCE(SUM(v.total),0) AS monto
            FROM ventas v
            INNER JOIN clientes c ON c.id = v.cliente_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY c.id, c.nombre
            ORDER BY monto DESC
            LIMIT " . (int)$tope, [$a, $b]);
    }

    /**
     * Antigüedad del saldo, en tramos.
     * Se mide desde el último abono, o desde la venta si nunca hubo uno.
     */
    public function antiguedad()
    {
        return $this->uno("
            SELECT
              COALESCE(SUM(CASE WHEN dias <= 30 THEN saldo END),0)              AS t30,
              COALESCE(SUM(CASE WHEN dias > 30 AND dias <= 60 THEN saldo END),0) AS t60,
              COALESCE(SUM(CASE WHEN dias > 60 THEN saldo END),0)               AS t60mas,
              COUNT(CASE WHEN dias > 60 THEN 1 END)                             AS n60mas,
              COALESCE(SUM(saldo),0)                                            AS total
            FROM (
              SELECT v.total - COALESCE(pg.cobrado,0) AS saldo,
                     DATEDIFF(CURDATE(), COALESCE(pg.ultimo, v.fecha)) AS dias
              FROM ventas v
              LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo
                          FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg
                     ON pg.venta_id = v.id
              WHERE v.estado <> 'cancelada'
                AND v.total - COALESCE(pg.cobrado,0) > 0.01
            ) x") ?: [];
    }
}
