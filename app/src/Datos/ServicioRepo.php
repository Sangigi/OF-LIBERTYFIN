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

    public function catalogo($desde, $hasta, $buscar = '', $limite = 10, $desfase = 0)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        // Solo DOS fechas: las del subquery de ventas. El WHERE exterior
        // filtra por p.activo, que no lleva marcador.
        $w = ['p.activo = 1']; $p = [$a, $b];
        if ($buscar !== '') { $w[] = '(p.nombre LIKE ? OR p.codigo LIKE ?)';
                              $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l; }
        return $this->todos("
            SELECT p.id, p.codigo, p.nombre, p.imagen,
                   COALESCE(NULLIF(p.subprecio,0), p.precio) AS precio,
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
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
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

    // ─────────────────────────────────────────────────────────────
    // ALTA Y EDICION
    // ─────────────────────────────────────────────────────────────

    public function uno_($id)
    {
        return $this->uno("SELECT * FROM productos WHERE id = ?", [(int)$id]);
    }

    public function categorias()
    {
        return $this->todos("SELECT id, nombre FROM categorias ORDER BY nombre");
    }

    private function limpiar(array $d, $idActual = null)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');

        $codigo = mb_strtoupper(trim($d['codigo'] ?? ''));
        if ($codigo === '') throw new \InvalidArgumentException('El código es obligatorio');

        // Lo barato primero. Buscar duplicados es una consulta a la base;
        // rechazar un precio en cero no cuesta nada. Validar en ese orden
        // evita ir a la base para descubrir algo que ya se sabía.
        $precio = round((float)($d['precio'] ?? 0), 2);
        if ($precio <= 0) throw new \InvalidArgumentException('El precio debe ser mayor a cero');
        $costo = round((float)($d['costo'] ?? 0), 2);
        if ($costo < 0) throw new \InvalidArgumentException('El costo no puede ser negativo');
        if ($costo > $precio) {
            throw new \InvalidArgumentException('El costo no puede ser mayor al precio');
        }

        // El código identifica al servicio en toda la operación: repetirlo
        // hace imposible saber cuál se vendió.
        $sql = "SELECT id FROM productos WHERE codigo = ?";
        $p = [$codigo];
        if ($idActual) { $sql .= " AND id <> ?"; $p[] = (int)$idActual; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ya hay un servicio con el código ' . $codigo);
        }

        $cat = (int)($d['categoria_id'] ?? 0) ?: null;
        return [$codigo, $nombre, trim($d['descripcion'] ?? '') ?: null, $precio, $costo, $cat];
    }

    public function guardarImagen($id, $ruta)
    {
        $this->db->prepare("UPDATE productos SET imagen = ? WHERE id = ?")
                 ->execute([$ruta ?: null, (int)$id]);
        return true;
    }

    public function imagenDe($id)
    {
        return (string)$this->valor("SELECT COALESCE(imagen,'') FROM productos WHERE id = ?", [(int)$id]);
    }

    public function crear(array $d)
    {
        list($codigo, $nombre, $desc, $precio, $costo, $cat) = $this->limpiar($d);
        // `subprecio` es el precio de venta y `precio` queda de respaldo:
        // se guardan iguales para que ambas lecturas den lo mismo.
        $this->db->prepare("
            INSERT INTO productos (codigo, nombre, descripcion, precio, subprecio, costo,
                                   categoria_id, stock, stock_minimo, activo)
            VALUES (?,?,?,?,?,?,?,0,0,1)
        ")->execute([$codigo, $nombre, $desc, $precio, $precio, $costo, $cat]);
        return (int)$this->db->lastInsertId();
    }

    public function actualizar($id, array $d)
    {
        list($codigo, $nombre, $desc, $precio, $costo, $cat) = $this->limpiar($d, $id);
        $this->db->prepare("
            UPDATE productos SET codigo = ?, nombre = ?, descripcion = ?,
                   precio = ?, subprecio = ?, costo = ?, categoria_id = ?,
                   fecha_actualizacion = NOW()
            WHERE id = ?
        ")->execute([$codigo, $nombre, $desc, $precio, $precio, $costo, $cat, (int)$id]);
        return (int)$id;
    }

    /**
     * Activa o desactiva. No se borra nunca: un servicio borrado deja
     * ventas apuntando a un producto que ya no existe.
     */
    public function alternar($id)
    {
        $this->db->prepare("UPDATE productos SET activo = 1 - activo WHERE id = ?")
                 ->execute([(int)$id]);
        return (int)$this->valor("SELECT activo FROM productos WHERE id = ?", [(int)$id]);
    }

    public function cuantos($buscar = '')
    {
        $w = ['p.activo = 1']; $p = [];
        if ($buscar !== '') { $w[] = '(p.nombre LIKE ? OR p.codigo LIKE ?)';
                              $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; }
        return (int)$this->valor("SELECT COUNT(*) FROM productos p WHERE " . implode(' AND ', $w), $p);
    }

    /** Un servicio por su id. */
    public function porId($id)
    {
        return $this->uno("
            SELECT p.*, COALESCE(NULLIF(p.subprecio,0), p.precio) AS precio,
                   c.nombre AS categoria
            FROM productos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE p.id = ?", [(int)$id]);
    }
}
