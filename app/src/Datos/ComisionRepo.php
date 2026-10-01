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
            SELECT pc.colaborador_id, pc.colaborador_nombre,
                   -- `area_nombre` aquí es el EQUIPO del colaborador, no
                   -- el área del trabajo. Se conserva con su nombre
                   -- propio porque sigue siendo útil saber de qué equipo
                   -- es alguien, pero ya no se llama area a secas.
                   pc.area_nombre AS equipo,
                   -- El ÁREA es la del servicio que se vendió. Un
                   -- colaborador puede comisionar en varias.
                   GROUP_CONCAT(DISTINCT COALESCE((
                       SELECT cat.nombre
                       FROM venta_detalles d
                       LEFT JOIN productos pr    ON pr.id = d.producto_id
                       LEFT JOIN categorias cat  ON cat.id = pr.categoria_id
                       WHERE d.venta_id = v.id AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                       GROUP BY cat.nombre ORDER BY SUM(d.subtotal) DESC LIMIT 1
                   ), NULLIF(v.area_nombre,''), 'Sin área')
                       ORDER BY 1 SEPARATOR ', ') AS area_nombre,
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
            -- EL ÁREA ES LA DEL SERVICIO, NO LA DEL COLABORADOR.
            --
            -- `pago_comisiones.area_nombre` guarda el equipo de quien
            -- comisionó. Agrupar por ahí hacía que una venta de
            -- contabilidad apareciera bajo Administración solo porque
            -- la cerró alguien de ese equipo, y no coincidía con el
            -- control que lleva la oficina.
            SELECT COALESCE((
                       SELECT cat.nombre
                       FROM venta_detalles d
                       LEFT JOIN productos pr    ON pr.id = d.producto_id
                       LEFT JOIN categorias cat  ON cat.id = pr.categoria_id
                       WHERE d.venta_id = v.id AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                       GROUP BY cat.nombre ORDER BY SUM(d.subtotal) DESC LIMIT 1
                   ), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                   ROUND(SUM(pc.monto),2) AS monto
            FROM pago_comisiones pc
            INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.fecha >= ? AND v.fecha < ? AND v.estado <> 'cancelada'
            GROUP BY area
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
                   -- `area_nombre` aqui es el EQUIPO al que pertenecia
                   -- ese renglon de comision. Se conserva porque es con
                   -- lo que se sugiere quien puede tomarla: si la
                   -- comision era del equipo legal, el candidato tiene
                   -- que ser de legal.
                   pc.area_nombre, pc.porcentaje,
                   -- Y el area del SERVICIO, que es lo que la persona
                   -- busca al leer el renglon: de que fue la venta.
                   COALESCE((
                       SELECT cat.nombre
                       FROM venta_detalles d
                       LEFT JOIN productos pr   ON pr.id = d.producto_id
                       LEFT JOIN categorias cat ON cat.id = pr.categoria_id
                       WHERE d.venta_id = v.id AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                       GROUP BY cat.nombre ORDER BY SUM(d.subtotal) DESC LIMIT 1
                   ), NULLIF(v.area_nombre,''), 'Sin area') AS area_servicio,
                   ROUND(pc.monto,2) AS monto
            FROM pago_comisiones pc
            INNER JOIN ventas v   ON v.id = pc.venta_id
            LEFT  JOIN clientes c ON c.id = v.cliente_id
            WHERE pc.colaborador_nombre = ?
            ORDER BY pc.monto DESC
        ", [self::SIN_DUENO]);
    }


    /** Todos los colaboradores activos, agrupados por área. */
    public function catalogo()
    {
        $filas = $this->todos("
            SELECT a.nombre AS area, c.id, c.nombre
            FROM comision_colaboradores c
            INNER JOIN comision_areas a ON a.id = c.area_id
            WHERE c.activo = 1 AND a.activo = 1
            ORDER BY a.nombre, (c.nombre = ?), c.nombre", [self::SIN_DUENO]);
        $g = [];
        foreach ($filas as $f) $g[$f['area']][] = ['id' => $f['id'], 'nombre' => $f['nombre']];
        return $g;
    }

    /**
     * Los porcentajes que ya se han usado, para sugerirlos.
     * Evita tener que recordar que Ventas va al 10% y Legal al 41%.
     */
    public function porcentajesUsados()
    {
        $filas = $this->todos("
            SELECT colaborador_id, porcentaje_regla AS pct, COUNT(*) AS veces
            FROM venta_comisiones WHERE cancelada = 0
            GROUP BY colaborador_id, porcentaje_regla
            ORDER BY colaborador_id, veces DESC");
        $m = [];
        foreach ($filas as $f) {
            if (!isset($m[$f['colaborador_id']])) $m[$f['colaborador_id']] = (float)$f['pct'];
        }
        return $m;
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

    /**
     * Cómo se libera la comisión: devengado contra lo que falta.
     *
     * Rellena la columna junto a la dona, que quedaba corta, y contesta
     * algo que no estaba en ningún lado: cuánta comisión está atada a
     * ventas que el cliente todavía no termina de pagar.
     */
    public function liberacion($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            -- Mismo criterio que el resto: el area del SERVICIO.
            SELECT COALESCE((
                       SELECT cat.nombre
                       FROM venta_detalles d
                       LEFT JOIN productos pr   ON pr.id = d.producto_id
                       LEFT JOIN categorias cat ON cat.id = pr.categoria_id
                       WHERE d.venta_id = v.id AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                       GROUP BY cat.nombre ORDER BY SUM(d.subtotal) DESC LIMIT 1
                   ), NULLIF(v.area_nombre,''), 'Sin area') AS area,
                   ROUND(SUM(vc.monto_comision),2) AS asignada,
                   ROUND(COALESCE(SUM(pc.dev),0),2) AS devengada
            FROM venta_comisiones vc
            INNER JOIN ventas v ON v.id = vc.venta_id
            LEFT  JOIN ( SELECT venta_comision_id, SUM(monto) dev
                         FROM pago_comisiones GROUP BY venta_comision_id ) pc
                   ON pc.venta_comision_id = vc.id
            WHERE vc.cancelada = 0 AND v.estado <> 'cancelada'
              AND v.fecha >= ? AND v.fecha < ?
            GROUP BY area
            ORDER BY asignada DESC", [$a, $b]);
    }

    /** Las ventas que más comisión tienen atada sin liberar. */
    public function atadas($desde, $hasta, $tope = 5)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT v.id, v.codigo_venta, c.nombre AS cliente,
                   ROUND(SUM(vc.monto_comision) - COALESCE(SUM(pc.dev),0), 2) AS pendiente,
                   ROUND(v.total,2) AS total,
                   ROUND(COALESCE(pg.cobrado,0),2) AS cobrado
            FROM venta_comisiones vc
            INNER JOIN ventas v   ON v.id = vc.venta_id
            LEFT  JOIN clientes c ON c.id = v.cliente_id
            LEFT  JOIN ( SELECT venta_comision_id, SUM(monto) dev
                         FROM pago_comisiones GROUP BY venta_comision_id ) pc
                   ON pc.venta_comision_id = vc.id
            LEFT  JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                         WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE vc.cancelada = 0 AND v.estado <> 'cancelada'
              AND v.fecha >= ? AND v.fecha < ?
            GROUP BY v.id, v.codigo_venta, c.nombre, v.total, pg.cobrado
            HAVING pendiente > 0.01
            ORDER BY pendiente DESC
            LIMIT " . (int)$tope, [$a, $b]);
    }

    /**
     * Los colaboradores activos, agrupados por area.
     *
     * Es lo que se ofrece al asignar el especialista de una venta. NO
     * son los usuarios del sistema: un contador puede atender clientes
     * sin tener cuenta para entrar, y un cajero tiene cuenta pero no
     * hace el trabajo. Son listas distintas y confundirlas obliga a
     * crear usuarios falsos para poder asignar.
     *
     * Se llama `equipoPorArea` y no `porArea` porque ese nombre ya era
     * el de las comisiones agrupadas por area. Dos cosas distintas con
     * el mismo nombre acaban en que alguien llama a la equivocada.
     */
    public function equipoPorArea()
    {
        $filas = $this->todos("
            SELECT c.id, c.nombre, a.nombre AS area
            FROM comision_colaboradores c
            INNER JOIN comision_areas a ON a.id = c.area_id
            WHERE c.activo = 1 AND a.activo = 1
            ORDER BY a.nombre, c.nombre");

        $r = [];
        foreach ($filas as $f) $r[$f['area'] ?: 'Sin area'][] = $f;
        return $r;
    }
}
