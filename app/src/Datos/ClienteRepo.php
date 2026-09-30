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
                   COALESCE(MAX(c.area), MAX(v.area_nombre)) AS area
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

    // ─────────────────────────────────────────────────────────────
    // ALTA Y EDICION
    // ─────────────────────────────────────────────────────────────

    public function uno_($id)
    {
        return $this->uno("SELECT * FROM clientes WHERE id = ?", [(int)$id]);
    }

    /**
     * Valida y normaliza lo que llega del formulario.
     * @throws \InvalidArgumentException con un mensaje apto para mostrar
     */
    private function limpiar(array $d, $idActual = null)
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre es obligatorio');
        if (mb_strlen($nombre) > 150) throw new \InvalidArgumentException('El nombre es demasiado largo');

        $rfc = mb_strtoupper(trim($d['rfc'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            throw new \InvalidArgumentException('El RFC no tiene el formato correcto');
        }
        $email = trim($d['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo no es válido');
        }
        // Del teléfono se guarda solo lo que sirve para marcar.
        $tel = preg_replace('/[^0-9+]/', '', (string)($d['telefono'] ?? ''));

        // Mismo nombre dos veces es casi siempre captura duplicada, y fue
        // justo lo que llenó julio de basura.
        $sql = "SELECT id FROM clientes WHERE nombre = ?";
        $p = [$nombre];
        if ($idActual) { $sql .= " AND id <> ?"; $p[] = (int)$idActual; }
        if ($this->valor($sql . " LIMIT 1", $p)) {
            throw new \InvalidArgumentException('Ya existe un cliente con ese nombre');
        }

        return [$nombre, $rfc ?: null, $email ?: null, $tel ?: null,
                trim($d['direccion'] ?? '') ?: null,
                trim($d['area'] ?? '') ?: null];
    }

    /**
     * ¿Existe la columna `area`? Se pregunta una vez y se recuerda.
     *
     * La agrega 12_area_cliente.sql. Mientras no se corra, el sistema
     * funciona igual: simplemente no guarda el área. Preferible a
     * reventar con "Unknown column" en una instalación sin migrar.
     */
    private function tieneArea()
    {
        static $tiene = null;
        if ($tiene !== null) return $tiene;
        try {
            $tiene = (bool)$this->valor("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes'
                  AND COLUMN_NAME = 'area'");
        } catch (\Throwable $e) { $tiene = false; }
        return $tiene;
    }

    public function crear(array $d)
    {
        list($nombre, $rfc, $email, $tel, $dir, $area) = $this->limpiar($d);
        if ($this->tieneArea()) {
            $this->db->prepare("
                INSERT INTO clientes (nombre, rfc, area, email, telefono, direccion, activo, fecha_creacion)
                VALUES (?,?,?,?,?,?,1,NOW())
            ")->execute([$nombre, $rfc, $area, $email, $tel, $dir]);
        } else {
            $this->db->prepare("
                INSERT INTO clientes (nombre, rfc, email, telefono, direccion, activo, fecha_creacion)
                VALUES (?,?,?,?,?,1,NOW())
            ")->execute([$nombre, $rfc, $email, $tel, $dir]);
        }
        return (int)$this->db->lastInsertId();
    }

    public function actualizar($id, array $d)
    {
        list($nombre, $rfc, $email, $tel, $dir, $area) = $this->limpiar($d, $id);
        if ($this->tieneArea()) {
            $this->db->prepare("
                UPDATE clientes SET nombre = ?, rfc = ?, area = ?, email = ?,
                       telefono = ?, direccion = ? WHERE id = ?
            ")->execute([$nombre, $rfc, $area, $email, $tel, $dir, (int)$id]);
        } else {
            $this->db->prepare("
                UPDATE clientes SET nombre = ?, rfc = ?, email = ?, telefono = ?, direccion = ?
                WHERE id = ?
            ")->execute([$nombre, $rfc, $email, $tel, $dir, (int)$id]);
        }
        return (int)$id;
    }

    /** Las áreas que ya se usan, para sugerirlas en el formulario. */
    public function areasUsadas()
    {
        $r = $this->todos("
            SELECT DISTINCT area_nombre AS area FROM ventas
            WHERE area_nombre IS NOT NULL AND area_nombre <> '' ORDER BY area_nombre");
        return array_column($r, 'area');
    }
}
