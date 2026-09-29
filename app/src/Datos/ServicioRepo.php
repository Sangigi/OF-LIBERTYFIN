<?php
namespace LibertyFin\Datos;

/** Catálogo de servicios y su desempeño. */
final class ServicioRepo extends Repo
{
    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    public function resumen($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $r = ['activos' => (int)$this->valor("SELECT COUNT(*) FROM productos WHERE activo = 1")];

        $r['mas_vendido'] = $this->uno("
            SELECT p.nombre, COUNT(*) AS veces, COALESCE(SUM(vd.subtotal),0) AS ingreso
            FROM venta_detalles vd
            INNER JOIN ventas v    ON v.id = vd.venta_id
            INNER JOIN productos p ON p.id = vd.producto_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY p.id, p.nombre ORDER BY veces DESC LIMIT 1", [$a, $b]);

        $r['area_top'] = $this->uno("
            SELECT COALESCE(NULLIF(v.area_nombre,''),'Sin área') AS area,
                   COALESCE(SUM(pg.cobrado),0) AS cobrado
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY area ORDER BY cobrado DESC LIMIT 1", [$a, $b]);

        $r['sin_ventas'] = (int)$this->valor("
            SELECT COUNT(*) FROM productos p
            WHERE p.activo = 1 AND NOT EXISTS (
                SELECT 1 FROM venta_detalles vd
                INNER JOIN ventas v ON v.id = vd.venta_id
                WHERE vd.producto_id = p.id
                  AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                  AND v.estado <> 'cancelada')");
        return $r;
    }

    public function catalogo($desde, $hasta, $buscar = '', $limite = 40)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        // Solo DOS fechas: las del subquery de ventas. El WHERE exterior
        // filtra por p.activo, que no lleva marcador.
        $w = ['p.activo = 1']; $p = [$a, $b];
        if ($buscar !== '') { $w[] = '(p.nombre LIKE ? OR p.codigo LIKE ?)';
                              $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l; }
        return $this->todos("
            SELECT p.id, p.codigo, p.nombre, p.precio,
                   cat.nombre AS categoria,
                   COALESCE(x.veces,0)   AS ventas,
                   COALESCE(x.ingreso,0) AS ingreso
            FROM productos p
            LEFT JOIN categorias cat ON cat.id = p.categoria_id
            LEFT JOIN (
                SELECT vd.producto_id, COUNT(*) veces, SUM(vd.subtotal) ingreso
                FROM venta_detalles vd
                INNER JOIN ventas v ON v.id = vd.venta_id
                WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
                GROUP BY vd.producto_id
            ) x ON x.producto_id = p.id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY ingreso DESC, p.nombre
            LIMIT " . (int)$limite, $p);
    }

    public function masFacturan($desde, $hasta, $tope = 5)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT p.nombre, SUM(vd.subtotal) AS monto
            FROM venta_detalles vd
            INNER JOIN ventas v    ON v.id = vd.venta_id
            INNER JOIN productos p ON p.id = vd.producto_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY p.id, p.nombre ORDER BY monto DESC LIMIT " . (int)$tope, [$a, $b]);
    }

    /** Cobrado por área. Cobrado, no vendido. */
    public function porArea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(NULLIF(v.area_nombre,''),'Sin área') AS area,
                   COALESCE(SUM(pg.cobrado),0) AS monto
            FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY area HAVING monto > 0 ORDER BY monto DESC", [$a, $b]);
    }
}
