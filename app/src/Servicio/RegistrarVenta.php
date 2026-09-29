<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use LibertyFin\Dominio\Ticket;
use PDO;

/**
 * Cobra un ticket: crea la venta, sus líneas, el gasto de operación y el
 * primer pago, todo en una transacción.
 *
 * Es el único lugar del sistema que escribe una venta. En el sistema
 * anterior `INSERT INTO ventas` estaba en tres archivos distintos
 * (caja.php, cajapruebas.php y generar_logos_placeholder.php), cada uno
 * con su propia idea de cómo tratar el IVA.
 */
final class RegistrarVenta
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * @return array id y código de la venta creada
     * @throws \InvalidArgumentException si el ticket o el anticipo no son válidos
     */
    public function cobrar(Ticket $ticket, array $ctx)
    {
        if ($ticket->vacio()) {
            throw new \InvalidArgumentException('El ticket está vacío');
        }
        $anticipo = $ticket->validarAnticipo($ctx['anticipo'] ?? 0);
        $t        = $ticket->totales();
        $codigo   = date('YmdHis');

        $this->db->beginTransaction();
        try {
            // ── La venta. `total` es lo VENDIDO, nunca lo cobrado. ──
            $st = $this->db->prepare("
                INSERT INTO ventas
                    (codigo_venta, cliente_id, usuario_id, sucursal_id, caja_id,
                     subtotal, descuento, iva, total, iva_modo,
                     metodo_pago, estado, descripcion, fecha)
                VALUES (?,?,?,?,?, ?,?,?,?,?, ?,'completada',?,NOW())
            ");
            $st->execute([
                $codigo,
                $ctx['cliente_id'] ?: null,
                $ctx['usuario_id'] ?: null,
                $ctx['sucursal_id'] ?: null,
                $ctx['caja_id'] ?: null,
                $t['subtotal'], 0, $t['iva'], $t['total'],
                $t['iva_modo'] === 'sumar' ? 'sumar' : 'incluido',
                $ctx['metodo_pago'] ?? 'efectivo',
                trim($ctx['descripcion'] ?? ''),
            ]);
            $ventaId = (int)$this->db->lastInsertId();

            // ── Las líneas ──
            $sd = $this->db->prepare("
                INSERT INTO venta_detalles
                    (venta_id, producto_id, cantidad, precio_unitario, descuento, subtotal)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($ticket->lineas() as $l) {
                $sd->execute([
                    $ventaId, $l['producto_id'], $l['cantidad'], $l['precio'], $l['descuento'],
                    Dinero::centavos($l['precio'] * $l['cantidad'] - $l['descuento']),
                ]);
            }

            // ── Gasto de operación. Se resta de la utilidad ANTES de comisionar. ──
            if ($t['gastos'] > 0) {
                $this->db->prepare("
                    INSERT INTO gastos (venta_id, concepto, monto, tipo, categoria, fecha)
                    VALUES (?,?,?,'manual','Gasto de operación',NOW())
                ")->execute([$ventaId, $ctx['concepto_gasto'] ?? 'Gastos de operación', $t['gastos']]);
            }

            // ── El primer pago. Solo si de verdad entró dinero. ──
            if ($anticipo > 0) {
                $this->db->prepare("
                    INSERT INTO venta_pagos
                        (venta_id, monto, tipo, metodo_pago, referencia, fecha_pago, cancelado, usuario_id)
                    VALUES (?,?,?,?,?,NOW(),0,?)
                ")->execute([
                    $ventaId, $anticipo, $ticket->tipoPago($anticipo),
                    $ctx['metodo_pago'] ?? 'efectivo',
                    $ctx['referencia'] ?: null,
                    $ctx['usuario_id'] ?: null,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'id'       => $ventaId,
            'codigo'   => $codigo,
            'total'    => $t['total'],
            'cobrado'  => $anticipo,
            'saldo'    => $ticket->saldo($anticipo),
        ];
    }
}
