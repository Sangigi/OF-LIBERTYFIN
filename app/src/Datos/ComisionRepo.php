<?php
namespace LibertyFin\Datos;

/**
 * Comisiones.
 *
 * Todo sale de `pago_comisiones`, que guarda lo DEVENGADO: lo que ya se
 * ganó en proporción a lo cobrado. `venta_comisiones` guarda lo asignado
 * si el cliente liquidara todo, que es otra cosa y no se paga.
 *
 * Dos métricas, no una:
 *   TOTAL      todo lo que la venta generó, tenga dueño o no
 *   POR PAGAR  solo lo que se le puede depositar a una persona
 *
 * La diferencia son los renglones a nombre de POR ASIGNAR: el Excel los
 * cuenta porque la venta sí los generó, pero nadie confirmó quién vendió.
 */
final class ComisionRepo extends Repo
{
    const SIN_DUENO = 'POR ASIGNAR';

    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    public function resumen($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $r = $this->uno("
            SELECT
                COALESCE(SUM(pc.monto),0) AS total,
                COALESCE(SUM(CASE WHEN pc.colaborador_nombre <> ? THEN pc.monto END),0) AS por_pagar,
                COALESCE(SUM(CASE WHEN pc.colaborador_nombre  = ? THEN pc.monto END),0) AS sin_asignar,
                COUNT(DISTINCT CASE WHEN pc.colaborador_nombre = ? THEN pc.venta_id END) AS ventas_pendientes,
                COUNT(DISTINCT CASE WHEN pc.colaborador_nombre <> ? THEN pc.colaborador_id END) AS colaboradores
            FROM pago_comisiones pc
            INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
        ", [self::SIN_DUENO, self::SIN_DUENO, self::SIN_DUENO, self::SIN_DUENO, $a, $b]) ?: [];

        // Lo que falta liberar: asignado si liquidan, menos lo ya devengado.
        $r['por_liberar'] = (float)$this->valor("
            SELECT COALESCE(SUM(vc.monto_comision),0) - COALESCE((
                SELECT SUM(pc.monto) FROM pago_comisiones pc
                INNER JOIN ventas v2 ON v2.id = pc.venta_id
                WHERE v2.fecha >= ? AND v2.fecha < ? AND v2.estado <> 'cancelada'
            ),0)
            FROM venta_comisiones vc
            INNER JOIN ventas v ON v.id = vc.venta_id
            WHERE vc.cancelada = 0 AND v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
        ", [$a, $b, $a, $b]);
        if ($r['por_liberar'] < 0) $r['por_liberar'] = 0;
        return $r;
    }

    public function porColaborador($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT pc.colaborador_id, pc.colaborador_nombre, pc.area_nombre,
                   ROUND(SUM(pc.monto),2) AS devengado,
                   COUNT(DISTINCT pc.venta_id) AS ventas,
                   (pc.colaborador_nombre = ?) AS sin_dueno
            FROM pago_comisiones pc
            INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY pc.colaborador_id, pc.colaborador_nombre, pc.area_nombre
            ORDER BY sin_dueno ASC, devengado DESC
        ", [self::SIN_DUENO, $a, $b]);
    }

    public function porArea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT pc.area_nombre AS area, ROUND(SUM(pc.monto),2) AS monto
            FROM pago_comisiones pc
            INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY pc.area_nombre
            ORDER BY monto DESC
        ", [$a, $b]);
    }

    /** El detalle de lo que no tiene dueño. Esta es la lista que se lleva a preguntar. */
    public function sinDueno()
    {
        return $this->todos("
            SELECT pc.venta_comision_id AS renglon,
                   v.id AS venta_id, v.codigo_venta, v.fecha,
                   c.nombre AS cliente,
                   pc.area_nombre, pc.porcentaje,
                   ROUND(pc.monto,2) AS monto
            FROM pago_comisiones pc
            INNER JOIN ventas v   ON v.id = pc.venta_id
            LEFT  JOIN clientes c ON c.id = v.cliente_id
            WHERE pc.colaborador_nombre = ?
            ORDER BY pc.monto DESC
        ", [self::SIN_DUENO]);
    }

    /** Candidatos para reasignar un renglón sin dueño. */
    public function colaboradoresDe($area)
    {
        return $this->todos("
            SELECT c.id, c.nombre
            FROM comision_colaboradores c
            INNER JOIN comision_areas a ON a.id = c.area_id
            WHERE c.activo = 1 AND a.nombre = ? AND c.nombre <> ?
            ORDER BY c.nombre
        ", [$area, self::SIN_DUENO]);
    }

    /**
     * Reasigna un renglón. Solo cambia el dueño: el monto, la base y el
     * devengado ya están calculados y no se recalculan.
     */
    public function reasignar($renglon, $colaboradorId)
    {
        $col = $this->uno("SELECT id, nombre FROM comision_colaboradores WHERE id = ? AND activo = 1",
                          [(int)$colaboradorId]);
        if (!$col) throw new \InvalidArgumentException('Ese colaborador no existe');

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE venta_comisiones SET colaborador_id = ?, colaborador_nombre = ?
                WHERE id = ? AND cancelada = 0
            ")->execute([$col['id'], $col['nombre'], (int)$renglon]);

            $this->db->prepare("
                UPDATE pago_comisiones SET colaborador_id = ?, colaborador_nombre = ?
                WHERE venta_comision_id = ?
            ")->execute([$col['id'], $col['nombre'], (int)$renglon]);

            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        return $col['nombre'];
    }
}
