<?php
namespace LibertyFin\Datos;

/**
 * Las ligas de pago generadas.
 *
 * UNA LIGA NO ES UN PAGO
 *
 * Se guardan aparte de `venta_pagos` a propósito. Un renglón ahí
 * significa "entró dinero" y lo suman el corte, los reportes y las
 * comisiones. Una liga significa "le pedimos al cliente que pague", que
 * es otra cosa y a veces no termina en nada.
 *
 * Cuando el proveedor confirma, se crea el abono de verdad y la liga
 * queda marcada como cobrada. Hasta entonces, la venta tiene saldo.
 */
final class LigaRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_ligas_pago (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    referencia VARCHAR(20) NOT NULL,
                    venta_id INT NULL,
                    cliente_nombre VARCHAR(200) NULL,
                    monto DECIMAL(12,2) NOT NULL,
                    metodo VARCHAR(16) NOT NULL DEFAULT 'todos',
                    descripcion VARCHAR(80) NULL,
                    liga VARCHAR(500) NULL,
                    clabe VARCHAR(30) NULL,
                    barras VARCHAR(80) NULL,
                    estado VARCHAR(16) NOT NULL DEFAULT 'pendiente',
                    vence DATE NULL,
                    pagado_en DATETIME NULL,
                    pago_id INT NULL,
                    pruebas TINYINT(1) NOT NULL DEFAULT 0,
                    usuario_id INT NULL,
                    usuario_nombre VARCHAR(160) NULL,
                    revisado_en DATETIME NULL,
                    creado_en DATETIME NOT NULL,
                    UNIQUE KEY ix_lp_ref (referencia),
                    KEY ix_lp_estado (estado, vence),
                    KEY ix_lp_venta (venta_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_ligas_pago: ' . $e->getMessage());
        }
    }

    public function crear(array $d)
    {
        $this->asegurar();
        $this->db->prepare("
            INSERT INTO lf_ligas_pago
                (referencia, venta_id, cliente_nombre, monto, metodo, descripcion,
                 liga, clabe, barras, estado, vence, pruebas,
                 usuario_id, usuario_nombre, creado_en)
            VALUES (?,?,?,?,?,?,?,?,?, 'pendiente', ?,?,?,?, NOW())
        ")->execute([
            $d['referencia'], $d['venta_id'] ?? null, $d['cliente'] ?? null,
            $d['monto'], $d['metodo'], mb_substr((string)($d['descripcion'] ?? ''), 0, 80),
            $d['liga'] ?? null, $d['clabe'] ?? null, $d['barras'] ?? null,
            $d['vence'] ?? null, !empty($d['pruebas']) ? 1 : 0,
            $d['usuario_id'] ?? null, $d['usuario_nombre'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function porId($id)
    {
        $this->asegurar();
        return $this->uno("SELECT * FROM lf_ligas_pago WHERE id = ?", [(int)$id]);
    }

    public function porReferencia($ref)
    {
        $this->asegurar();
        return $this->uno("SELECT * FROM lf_ligas_pago WHERE referencia = ?", [(string)$ref]);
    }

    /**
     * Marca una liga como cobrada y deja el abono hecho.
     *
     * Las dos cosas van en una transacción: si el abono se crea y la
     * liga no se marca, la siguiente consulta volvería a abonar y la
     * venta quedaría cobrada dos veces.
     */
    public function marcarPagada($id, $pagoId)
    {
        $this->db->prepare("
            UPDATE lf_ligas_pago
            SET estado = 'pagada', pagado_en = NOW(), pago_id = ?, revisado_en = NOW()
            WHERE id = ? AND estado <> 'pagada'")->execute([$pagoId ?: null, (int)$id]);
        return true;
    }

    public function marcarRevisada($id, $estado = null)
    {
        $sets = 'revisado_en = NOW()';
        $p = [];
        if ($estado && $estado !== 'pagada') { $sets .= ', estado = ?'; $p[] = $estado; }
        $p[] = (int)$id;
        $this->db->prepare("UPDATE lf_ligas_pago SET {$sets} WHERE id = ?")->execute($p);
        return true;
    }

    public function listado($estado = '', $buscar = '', $limite = 30, $desfase = 0)
    {
        $this->asegurar();
        $w = []; $p = [];
        if ($estado === 'pendientes') {
            $w[] = "estado = 'pendiente'";
        } elseif ($estado === 'vencidas') {
            $w[] = "estado = 'pendiente' AND vence IS NOT NULL AND vence < CURDATE()";
        } elseif ($estado !== '') {
            $w[] = 'estado = ?'; $p[] = $estado;
        }
        if ($buscar !== '') {
            $w[] = '(referencia LIKE ? OR cliente_nombre LIKE ? OR descripcion LIKE ?)';
            $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        return $this->todos("SELECT * FROM lf_ligas_pago {$where}
            ORDER BY creado_en DESC LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantas($estado = '', $buscar = '')
    {
        $this->asegurar();
        $w = []; $p = [];
        if ($estado === 'pendientes')   $w[] = "estado = 'pendiente'";
        elseif ($estado === 'vencidas') $w[] = "estado = 'pendiente' AND vence IS NOT NULL AND vence < CURDATE()";
        elseif ($estado !== '')       { $w[] = 'estado = ?'; $p[] = $estado; }
        if ($buscar !== '') {
            $w[] = '(referencia LIKE ? OR cliente_nombre LIKE ? OR descripcion LIKE ?)';
            $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        return (int)$this->valor("SELECT COUNT(*) FROM lf_ligas_pago {$where}", $p);
    }

    public function cifras()
    {
        $this->asegurar();
        return $this->uno("
            SELECT COUNT(*) AS total,
                   SUM(estado = 'pendiente') AS pendientes,
                   SUM(estado = 'pagada') AS pagadas,
                   SUM(estado = 'pendiente' AND vence IS NOT NULL AND vence < CURDATE()) AS vencidas,
                   COALESCE(SUM(CASE WHEN estado = 'pendiente' THEN monto END),0) AS esperando,
                   COALESCE(SUM(CASE WHEN estado = 'pagada'
                        AND DATE(pagado_en) = CURDATE() THEN monto END),0) AS cobrado_hoy
            FROM lf_ligas_pago") ?: [];
    }

    /** Las que llevan rato sin revisar, para consultarlas en tanda. */
    public function porRevisar($tope = 25)
    {
        $this->asegurar();
        return $this->todos("
            SELECT * FROM lf_ligas_pago
            WHERE estado = 'pendiente'
              AND (vence IS NULL OR vence >= CURDATE())
              AND (revisado_en IS NULL OR revisado_en < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
            ORDER BY revisado_en IS NOT NULL, creado_en
            LIMIT " . (int)$tope);
    }
}
