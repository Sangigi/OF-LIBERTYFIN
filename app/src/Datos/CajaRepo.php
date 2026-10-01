<?php
namespace LibertyFin\Datos;

/**
 * Corte de caja.
 *
 * El corte responde una sola pregunta: ¿el dinero que hay en el cajón es
 * el que debería haber? Por eso lo que se compara es EFECTIVO contra
 * efectivo. Transferencias y tarjeta se muestran aparte porque no pasan
 * por el cajón: sumarlas al corte es la forma más común de "cuadrar" una
 * caja que en realidad no cuadra.
 */
final class CajaRepo extends Repo
{
    /** La caja abierta de este usuario en esta sucursal, si hay. */
    public function abierta($usuarioId, $sucursalId)
    {
        return $this->uno("
            SELECT * FROM caja
            WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierta'
            ORDER BY id DESC LIMIT 1", [(int)$usuarioId, (int)$sucursalId]);
    }

    /** Lo cobrado desde que se abrió la caja, separado por método. */
    public function movimiento($cajaId, $desde)
    {
        return $this->uno("
            SELECT
                COALESCE(SUM(p.monto),0) AS total,
                COALESCE(SUM(CASE WHEN p.metodo_pago = 'efectivo'      THEN p.monto END),0) AS efectivo,
                COALESCE(SUM(CASE WHEN p.metodo_pago = 'transferencia' THEN p.monto END),0) AS transferencia,
                COALESCE(SUM(CASE WHEN p.metodo_pago = 'tarjeta'       THEN p.monto END),0) AS tarjeta,
                COUNT(*) AS cobros,
                COUNT(DISTINCT p.venta_id) AS ventas
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND v.caja_id = ? AND p.fecha_pago >= ?
        ", [(int)$cajaId, $desde]) ?: [];
    }

    public function cobros($cajaId, $desde, $tope = 40)
    {
        return $this->todos("
            SELECT p.id, p.monto, p.tipo, p.metodo_pago, p.fecha_pago,
                   v.codigo_venta, v.id AS venta_id,
                   c.nombre AS cliente
            FROM venta_pagos p
            INNER JOIN ventas v   ON v.id = p.venta_id
            LEFT  JOIN clientes c ON c.id = v.cliente_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND v.caja_id = ? AND p.fecha_pago >= ?
            ORDER BY p.fecha_pago DESC
            LIMIT " . (int)$tope, [(int)$cajaId, $desde]);
    }

    public function abrir($sucursalId, $usuarioId, $monto, $nota = '')
    {
        $this->db->prepare("
            INSERT INTO caja (sucursal_id, usuario_id, monto_apertura, observaciones, estado)
            VALUES (?,?,?,?,'abierta')
        ")->execute([(int)$sucursalId, (int)$usuarioId,
                     \LibertyFin\Dominio\Dinero::centavos($monto), trim($nota)]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Cierra la caja.
     * La diferencia se guarda con signo: positiva si sobró, negativa si
     * faltó. Guardar el valor absoluto esconde justo lo que importa.
     */
    public function cerrar($cajaId, $contado, $esperado, $nota = '')
    {
        $contado  = \LibertyFin\Dominio\Dinero::centavos($contado);
        $esperado = \LibertyFin\Dominio\Dinero::centavos($esperado);
        $this->db->prepare("
            UPDATE caja
            SET estado = 'cerrada', monto_cierre = ?, diferencia = ?,
                fecha_cierre = NOW(),
                observaciones = TRIM(CONCAT(COALESCE(observaciones,''), ' ', ?))
            WHERE id = ? AND estado = 'abierta'
        ")->execute([$contado, \LibertyFin\Dominio\Dinero::centavos($contado - $esperado),
                     trim($nota), (int)$cajaId]);
        return true;
    }

    public function historial($sucursalId, $tope = 10)
    {
        return $this->todos("
            SELECT c.*, u.nombre AS usuario
            FROM caja c
            LEFT JOIN usuarios u ON u.id = c.usuario_id
            WHERE c.sucursal_id = ? AND c.estado = 'cerrada'
            ORDER BY c.id DESC LIMIT " . (int)$tope, [(int)$sucursalId]);
    }

    /** Historial completo, con lo que se movió en cada turno. */
    public function historialCompleto($sucursalId, $limite = 10, $desfase = 0)
    {
        return $this->todos("
            SELECT c.*, u.nombre AS usuario,
                   COALESCE(mv.cobrado,0)  AS cobrado,
                   COALESCE(mv.efectivo,0) AS efectivo,
                   COALESCE(mv.cobros,0)   AS cobros
            FROM caja c
            LEFT JOIN usuarios u ON u.id = c.usuario_id
            LEFT JOIN (
                SELECT v.caja_id,
                       SUM(p.monto) AS cobrado,
                       SUM(CASE WHEN p.metodo_pago = 'efectivo' THEN p.monto END) AS efectivo,
                       COUNT(*) AS cobros
                FROM venta_pagos p
                INNER JOIN ventas v ON v.id = p.venta_id
                WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
                GROUP BY v.caja_id
            ) mv ON mv.caja_id = c.id
            WHERE c.sucursal_id = ?
            ORDER BY c.id DESC
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, [(int)$sucursalId]);
    }

    public function cuantosCortes($sucursalId)
    {
        return (int)$this->valor("SELECT COUNT(*) FROM caja WHERE sucursal_id = ?", [(int)$sucursalId]);
    }

    /** Las cifras del historial: cuántos cuadraron y cuánto se desvió. */
    public function resumenHistorial($sucursalId)
    {
        return $this->uno("
            SELECT COUNT(*) AS cortes,
                   SUM(CASE WHEN ABS(COALESCE(diferencia,0)) <= 0.009 THEN 1 ELSE 0 END) AS cuadrados,
                   COALESCE(SUM(CASE WHEN diferencia > 0 THEN diferencia END),0) AS sobrantes,
                   COALESCE(SUM(CASE WHEN diferencia < 0 THEN -diferencia END),0) AS faltantes
            FROM caja WHERE sucursal_id = ? AND estado = 'cerrada'", [(int)$sucursalId]) ?: [];
    }

    /** Los cobros del turno, paginados. */
    public function cobrosDelTurno($cajaId, $limite = 10, $desfase = 0)
    {
        return $this->todos("
            SELECT p.id, p.monto, p.metodo_pago, p.referencia, p.fecha_pago, p.tipo,
                   v.codigo_venta, COALESCE(c.nombre,'Público general') AS cliente
            FROM venta_pagos p
            INNER JOIN ventas v   ON v.id = p.venta_id
            LEFT  JOIN clientes c ON c.id = v.cliente_id
            WHERE v.caja_id = ? AND p.cancelado = 0 AND v.estado <> 'cancelada'
            ORDER BY p.fecha_pago DESC, p.id DESC
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, [(int)$cajaId]);
    }

    public function cuantosCobros($cajaId)
    {
        return (int)$this->valor("
            SELECT COUNT(*) FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE v.caja_id = ? AND p.cancelado = 0 AND v.estado <> 'cancelada'",
            [(int)$cajaId]);
    }
}
