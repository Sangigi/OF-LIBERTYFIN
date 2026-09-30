<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Comision;
use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Asigna una comisión a una línea de venta.
 *
 * Es el reemplazo de guardar_comision_producto.php, y la diferencia está
 * en una sola línea: la base sale de Dominio\Comision, que ya sabe quitar
 * el IVA. El archivo viejo tomaba venta_detalles.precio_unitario tal cual
 * y suponía que venía sin impuesto.
 *
 * Esa suposición costó tres errores en la migración: Francisco Flores con
 * $672.80 donde le tocaban $580, Reyna comisionando sobre $579.98 en vez
 * de $499.98, y la venta de PW sobre $1,478.84 en vez de $999.
 */
final class AsignarComision
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /** @throws \InvalidArgumentException con un mensaje apto para mostrar */
    public function asignar($ventaId, $colaboradorId, $porcentaje)
    {
        $ventaId = (int)$ventaId;
        $pct     = (float)$porcentaje;
        if ($pct <= 0 || $pct > 100) {
            throw new \InvalidArgumentException('El porcentaje debe estar entre 0 y 100');
        }

        $st = $this->db->prepare("
            SELECT v.id, v.subtotal, v.descuento, v.iva, v.total,
                   COALESCE(v.iva_modo,'incluido') AS iva_modo, v.estado
            FROM ventas v WHERE v.id = ?");
        $st->execute([$ventaId]);
        $venta = $st->fetch();
        if (!$venta) throw new \InvalidArgumentException('La venta no existe');
        if ($venta['estado'] === 'cancelada') {
            throw new \InvalidArgumentException('No se puede comisionar una venta cancelada');
        }

        $st = $this->db->prepare("
            SELECT c.id, c.nombre, a.id AS area_id, a.nombre AS area
            FROM comision_colaboradores c
            INNER JOIN comision_areas a ON a.id = c.area_id
            WHERE c.id = ? AND c.activo = 1");
        $st->execute([(int)$colaboradorId]);
        $col = $st->fetch();
        if (!$col) throw new \InvalidArgumentException('Ese colaborador no existe o está inactivo');

        // Una sola vez por colaborador y venta: dos renglones del mismo
        // nombre en la misma venta es siempre un error de captura.
        $st = $this->db->prepare("
            SELECT COUNT(*) FROM venta_comisiones
            WHERE venta_id = ? AND colaborador_id = ? AND cancelada = 0");
        $st->execute([$ventaId, $col['id']]);
        if ((int)$st->fetchColumn() > 0) {
            throw new \InvalidArgumentException($col['nombre'] . ' ya tiene comisión en esta venta');
        }

        // La línea. Con varios productos habría que elegir; hoy todas las
        // ventas del sistema tienen uno solo, así que se toma el primero
        // y se avisa si hubiera más.
        $st = $this->db->prepare("
            SELECT vd.id, vd.cantidad, vd.precio_unitario, vd.descuento,
                   COALESCE(p.costo,0) AS costo
            FROM venta_detalles vd
            LEFT JOIN productos p ON p.id = vd.producto_id
            WHERE vd.venta_id = ? ORDER BY vd.id");
        $st->execute([$ventaId]);
        $lineas = $st->fetchAll();
        if (!$lineas) throw new \InvalidArgumentException('La venta no tiene productos');
        if (count($lineas) > 1) {
            throw new \InvalidArgumentException(
                'Esta venta tiene ' . count($lineas) . ' productos. Asigna la comisión por producto.');
        }
        $linea = $lineas[0];

        $st = $this->db->prepare("
            SELECT COALESCE(SUM(monto),0) FROM gastos
            WHERE venta_id = ? AND tipo = 'manual' AND categoria <> 'Costo de venta'");
        $st->execute([$ventaId]);
        $gasto = Dinero::centavos($st->fetchColumn());

        // Aquí está todo el asunto: el dominio quita el IVA.
        $com  = Comision::desdeLinea($venta, $linea, $gasto);
        $base = $com->baseAsignada();
        if ($base <= 0) {
            throw new \InvalidArgumentException(
                'Esta venta no deja base comisionable: los gastos se comen la utilidad');
        }

        $iva    = \LibertyFin\Dominio\Iva::desdeVenta($venta);
        $precio = $iva->quitar($linea['precio_unitario']);
        $desc   = $iva->quitar($linea['descuento']);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO venta_comisiones
                    (venta_id, venta_detalle_id, area_id, area_nombre, regla_id, concepto,
                     colaborador_id, colaborador_nombre, porcentaje_regla, porcentaje_reparto,
                     costo_unitario, gasto_operacion, precio_unitario, descuento_linea,
                     cantidad, monto_base, monto_comision, usuario_id)
                VALUES (?,?,?,?,NULL,?,?,?,?,100.00,?,?,?,?,?,?,?,?)
            ")->execute([
                $ventaId, $linea['id'], $col['area_id'], $col['area'], $col['area'],
                $col['id'], $col['nombre'], $pct,
                $linea['costo'], $gasto, $precio, $desc, $linea['cantidad'],
                $base, $com->asignada($pct),
                $_SESSION['usuario_id'] ?? null,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }

        (new SincronizarComisiones($this->db))->paraVenta($ventaId);

        return ['colaborador' => $col['nombre'], 'asignada' => $com->asignada($pct),
                'base' => $base];
    }

    /** Cancelación lógica: deja rastro y resincroniza. */
    public function quitar($comisionId)
    {
        $st = $this->db->prepare("
            SELECT venta_id, colaborador_nombre FROM venta_comisiones
            WHERE id = ? AND cancelada = 0");
        $st->execute([(int)$comisionId]);
        $c = $st->fetch();
        if (!$c) throw new \InvalidArgumentException('Esa comisión no existe o ya estaba cancelada');

        $this->db->prepare("UPDATE venta_comisiones SET cancelada = 1 WHERE id = ?")
                 ->execute([(int)$comisionId]);
        (new SincronizarComisiones($this->db))->paraVenta($c['venta_id']);
        return $c;
    }
}
