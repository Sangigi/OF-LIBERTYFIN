<?php
namespace LibertyFin\Datos;

/**
 * Gastos.
 *
 * Hay dos clases y no se mezclan:
 *
 *   DE OPERACION · cuelgan de una venta y se restan de la utilidad antes
 *     de comisionar. Se capturan en Caja o en el detalle de la venta, no
 *     aquí.
 *
 *   GENERALES · renta, nómina, servicios. No pertenecen a ninguna venta y
 *     no tocan comisiones. Son los de esta pantalla.
 *
 * Confundirlos sería repetir el error que ya vimos: un gasto general
 * restado a una venta le baja la comisión a alguien sin razón.
 */
final class GastoRepo extends Repo
{
    const CATEGORIAS = ['Renta','Nómina','Servicios','Insumos','Transporte',
                        'Marketing','Mantenimiento','Impuestos','Otros'];

    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    public function resumen($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $r = $this->uno("
            SELECT COUNT(*) AS cuantos, COALESCE(SUM(monto),0) AS total
            FROM gastos
            WHERE venta_id IS NULL AND fecha >= ? AND fecha < ?", [$a, $b]) ?: [];

        // Los de operación se reportan aparte para que nadie los sume.
        $r['operacion'] = (float)$this->valor("
            SELECT COALESCE(SUM(monto),0) FROM gastos
            WHERE venta_id IS NOT NULL AND fecha >= ? AND fecha < ?", [$a, $b]);
        return $r;
    }

    public function listado($desde, $hasta, $categoria = '', $buscar = '')
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $w = ['g.venta_id IS NULL', 'g.fecha >= ?', 'g.fecha < ?'];
        $p = [$a, $b];
        if ($categoria !== '') { $w[] = 'g.categoria = ?'; $p[] = $categoria; }
        if ($buscar !== '')    { $w[] = '(g.concepto LIKE ? OR g.descripcion LIKE ?)';
                                 $l = '%'.$buscar.'%'; $p[] = $l; $p[] = $l; }
        return $this->todos("
            SELECT g.*, u.nombre AS usuario
            FROM gastos g
            LEFT JOIN usuarios u ON u.id = g.usuario_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY g.fecha DESC, g.id DESC
            LIMIT 200", $p);
    }

    public function porCategoria($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(NULLIF(categoria,''),'Otros') AS categoria,
                   SUM(monto) AS monto, COUNT(*) AS cuantos
            FROM gastos
            WHERE venta_id IS NULL AND fecha >= ? AND fecha < ?
            GROUP BY COALESCE(NULLIF(categoria,''),'Otros')
            ORDER BY monto DESC", [$a, $b]);
    }

    public function uno_($id)
    {
        return $this->uno("SELECT * FROM gastos WHERE id = ? AND venta_id IS NULL", [(int)$id]);
    }

    private function limpiar(array $d)
    {
        $concepto = trim($d['concepto'] ?? '');
        if ($concepto === '') throw new \InvalidArgumentException('El concepto es obligatorio');

        $monto = round((float)($d['monto'] ?? 0), 2);
        if ($monto <= 0) throw new \InvalidArgumentException('El monto debe ser mayor a cero');

        $cat = $d['categoria'] ?? 'Otros';
        if (!in_array($cat, self::CATEGORIAS, true)) $cat = 'Otros';

        $fecha = $d['fecha'] ?? date('Y-m-d');
        $f = \DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$f || $f->format('Y-m-d') !== $fecha) {
            throw new \InvalidArgumentException('La fecha no es válida');
        }
        // Un gasto con fecha futura es siempre un dedazo.
        if (strtotime($fecha) > strtotime('today')) {
            throw new \InvalidArgumentException('No se puede registrar un gasto con fecha futura');
        }

        $metodo = in_array($d['metodo_pago'] ?? '', ['efectivo','transferencia','tarjeta'], true)
                ? $d['metodo_pago'] : 'efectivo';

        return [$concepto, $cat, $monto, $fecha, $metodo,
                trim($d['descripcion'] ?? '') ?: null,
                trim($d['proveedor'] ?? '') ?: null,
                trim($d['numero_referencia'] ?? '') ?: null];
    }

    public function crear(array $d, $usuarioId, $sucursalId)
    {
        list($con, $cat, $monto, $fecha, $met, $desc, $prov, $ref) = $this->limpiar($d);
        $this->db->prepare("
            INSERT INTO gastos (concepto, categoria, monto, tipo, origen, usuario_id,
                                sucursal_id, metodo_pago, descripcion, fecha, proveedor,
                                numero_referencia)
            VALUES (?,?,?,'manual','general',?,?,?,?,?,?,?)
        ")->execute([$con, $cat, $monto, $usuarioId ?: null, $sucursalId ?: null,
                     $met, $desc, $fecha . ' ' . date('H:i:s'), $prov, $ref]);
        return (int)$this->db->lastInsertId();
    }

    public function actualizar($id, array $d)
    {
        if (!$this->uno_($id)) {
            throw new \InvalidArgumentException('Ese gasto no existe o pertenece a una venta');
        }
        list($con, $cat, $monto, $fecha, $met, $desc, $prov, $ref) = $this->limpiar($d);
        $this->db->prepare("
            UPDATE gastos SET concepto = ?, categoria = ?, monto = ?, metodo_pago = ?,
                   descripcion = ?, fecha = ?, proveedor = ?, numero_referencia = ?
            WHERE id = ? AND venta_id IS NULL
        ")->execute([$con, $cat, $monto, $met, $desc,
                     $fecha . ' ' . date('H:i:s'), $prov, $ref, (int)$id]);
        return (int)$id;
    }

    /**
     * Solo se borran los generales. Uno de operación cambia la base de
     * comisión de su venta, y eso no se toca desde aquí.
     */
    public function borrar($id)
    {
        if (!$this->uno_($id)) {
            throw new \InvalidArgumentException(
                'Ese gasto pertenece a una venta. Quítalo desde el detalle de la venta.');
        }
        $this->db->prepare("DELETE FROM gastos WHERE id = ? AND venta_id IS NULL")
                 ->execute([(int)$id]);
        return true;
    }
}
