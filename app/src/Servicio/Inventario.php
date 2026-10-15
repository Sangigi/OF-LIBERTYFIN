<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Inventario: cuántas piezas hay de cada producto en cada sucursal, y por
 * qué cambió cada vez.
 *
 * SOLO PARA QUIEN LO ENCIENDE
 *
 * Nace apagado (Ajustes → Inventario). Una empresa de servicios no tiene
 * existencias y no debe ver nada de esto. Con el módulo encendido, cuenta
 * solo los productos marcados con «Lleva existencias»: en una misma
 * empresa conviven la crema que se vende y el masaje que no se almacena.
 *
 * DÓNDE VIVE
 *
 * La existencia, en `producto_sucursal` (del sistema anterior: una fila
 * por producto y sucursal). `productos.stock` queda como la suma de todas
 * las sucursales, para quien todavía lo lea.
 *
 * NADA SE MUEVE SIN RASTRO
 *
 * Cada cambio deja un renglón en `lf_inventario_movimientos` con cuánto,
 * cuánto quedó, quién y por qué. No hay forma de "editar" una existencia:
 * se registra una entrada, una salida o un conteo. Así, cuando no cuadra,
 * se puede leer la historia en vez de adivinarla.
 */
final class Inventario
{
    /** Los tipos de movimiento, como se leen en pantalla. */
    const TIPOS = [
        'inicial'    => 'Existencia inicial',
        'entrada'    => 'Entrada',
        'salida'     => 'Salida',
        'conteo'     => 'Conteo',
        'venta'      => 'Venta',
        'devolucion' => 'Devolución',
    ];

    /** Unidad => [nombre, abreviatura]. */
    const UNIDADES = [
        'pieza'   => ['Pieza', 'pza'],
        'caja'    => ['Caja', 'caja'],
        'paquete' => ['Paquete', 'paq'],
        'kg'      => ['Kilo', 'kg'],
        'litro'   => ['Litro', 'L'],
        'metro'   => ['Metro', 'm'],
    ];

    /** Las que se venden por fracción: 0.5 kg, 1.25 m. Las demás, por pieza entera. */
    const FRACCION = ['kg', 'litro', 'metro'];

    /** ¿Esta unidad admite fracciones? La del sistema anterior puede venir como «Kg». */
    public static function fraccionable($unidad)
    {
        return in_array(strtolower(trim((string)$unidad)), self::FRACCION, true);
    }

    private $db;
    private $cfg;

    /** @param array|null $cfg  lo de ConfigRepo::inventario(); sin él se lee de la base */
    public function __construct(PDO $db, $cfg = null)
    {
        $this->db  = $db;
        $this->cfg = is_array($cfg) ? $cfg : (new \LibertyFin\Datos\ConfigRepo($db))->inventario();
    }

    public function activo()  { return ($this->cfg['activo'] ?? 'no') === 'si'; }
    public function bloquea() { return ($this->cfg['sin_existencia'] ?? 'avisar') === 'bloquear'; }

    // ════════════════════════════════════════════════════════
    //  LEER
    // ════════════════════════════════════════════════════════

    /** Las sucursales activas, la matriz primero. */
    public function sucursales()
    {
        return $this->todos("
            SELECT id, nombre, COALESCE(es_matriz,0) AS es_matriz FROM sucursales
            WHERE COALESCE(activo,1) = 1
            ORDER BY COALESCE(es_matriz,0) DESC, nombre");
    }

    /** La sucursal de quien opera. Si su usuario no tiene, la matriz. */
    public function sucursalActual()
    {
        $s = (int)($_SESSION['sucursal_id'] ?? 0);
        if ($s) return $s;
        $l = $this->sucursales();
        return $l ? (int)$l[0]['id'] : 0;
    }

    /**
     * Existencia de los productos que llevan inventario, en una sucursal.
     * @return array [producto_id => ['existencia' => float, 'minimo' => float]]
     */
    public function existencias($sucursalId)
    {
        $r = [];
        foreach ($this->todos("
            SELECT p.id, COALESCE(ps.stock,0) AS existencia, COALESCE(ps.stock_minimo,0) AS minimo
            FROM productos p
            LEFT JOIN producto_sucursal ps ON ps.producto_id = p.id AND ps.sucursal_id = ?
            WHERE p.lleva_inventario = 1", [(int)$sucursalId]) as $f) {
            $r[(int)$f['id']] = ['existencia' => (float)$f['existencia'], 'minimo' => (float)$f['minimo']];
        }
        return $r;
    }

    /** Lo de un producto en cada sucursal, para su formulario. */
    public function deProducto($productoId)
    {
        $r = [];
        foreach ($this->sucursales() as $s) {
            $f = $this->uno("SELECT stock, stock_minimo FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?",
                            [(int)$productoId, (int)$s['id']]);
            $r[(int)$s['id']] = [
                'nombre'      => $s['nombre'],
                'existencia'  => $f ? (float)$f['stock'] : 0.0,
                'minimo'      => $f ? (float)$f['stock_minimo'] : 0.0,
                // Sin movimientos y en cero: todavía se puede capturar la
                // existencia inicial. Después, solo entradas y conteos.
                'puedeInicial'=> !$this->tieneMovimientos($productoId, $s['id'])
                                 && (!$f || abs((float)$f['stock']) < 0.0005),
            ];
        }
        return $r;
    }

    /** Los productos con inventario de una sucursal, lo que falta primero. */
    public function listado($sucursalId, $buscar = '', $soloBajos = false)
    {
        $s = (int)$sucursalId;
        $w = ['p.activo = 1', 'p.lleva_inventario = 1'];
        $p = [$s];
        if ($buscar !== '') {
            $w[] = '(p.nombre LIKE ? OR p.codigo LIKE ?)';
            $l = '%' . $buscar . '%'; $p[] = $l; $p[] = $l;
        }
        $estado = "CASE WHEN COALESCE(ps.stock,0) <= 0 THEN 0
                        WHEN COALESCE(ps.stock_minimo,0) > 0 AND COALESCE(ps.stock,0) <= ps.stock_minimo THEN 1
                        ELSE 2 END";
        if ($soloBajos) $w[] = "$estado < 2";
        return $this->todos("
            SELECT p.id, p.codigo, p.nombre, p.unidad_medida, p.costo,
                   COALESCE(ps.stock,0) AS existencia, COALESCE(ps.stock_minimo,0) AS minimo,
                   $estado AS estado
            FROM productos p
            LEFT JOIN producto_sucursal ps ON ps.producto_id = p.id AND ps.sucursal_id = ?
            WHERE " . implode(' AND ', $w) . "
            ORDER BY $estado, p.nombre
            LIMIT 500", $p);
    }

    /** Los números de arriba: cuántos, cuántos faltan y cuánto vale lo que hay. */
    public function resumen($sucursalId)
    {
        $f = $this->uno("
            SELECT COUNT(*) AS productos,
                   SUM(CASE WHEN COALESCE(ps.stock,0) <= 0 THEN 1 ELSE 0 END) AS agotados,
                   SUM(CASE WHEN COALESCE(ps.stock,0) > 0 AND COALESCE(ps.stock_minimo,0) > 0
                             AND ps.stock <= ps.stock_minimo THEN 1 ELSE 0 END) AS bajos,
                   SUM(CASE WHEN COALESCE(ps.stock,0) > 0 THEN ps.stock * COALESCE(p.costo,0) ELSE 0 END) AS valor
            FROM productos p
            LEFT JOIN producto_sucursal ps ON ps.producto_id = p.id AND ps.sucursal_id = ?
            WHERE p.activo = 1 AND p.lleva_inventario = 1", [(int)$sucursalId]);
        return ['productos' => (int)($f['productos'] ?? 0), 'agotados' => (int)($f['agotados'] ?? 0),
                'bajos' => (int)($f['bajos'] ?? 0), 'valor' => round((float)($f['valor'] ?? 0), 2)];
    }

    /** La bitácora, lo más nuevo primero. */
    public function movimientos(array $f = [])
    {
        $w = ['1 = 1']; $p = [];
        if (!empty($f['desde']))    { $w[] = 'm.creado_en >= ?'; $p[] = $f['desde'] . ' 00:00:00'; }
        if (!empty($f['hasta']))    { $w[] = 'm.creado_en < ?';  $p[] = date('Y-m-d', strtotime($f['hasta'] . ' +1 day')) . ' 00:00:00'; }
        if (!empty($f['sucursal'])) { $w[] = 'm.sucursal_id = ?'; $p[] = (int)$f['sucursal']; }
        if (!empty($f['producto'])) { $w[] = 'm.producto_id = ?'; $p[] = (int)$f['producto']; }
        if (!empty($f['tipo']) && isset(self::TIPOS[$f['tipo']])) { $w[] = 'm.tipo = ?'; $p[] = $f['tipo']; }
        if (!empty($f['q'])) {
            $w[] = '(pr.nombre LIKE ? OR pr.codigo LIKE ?)';
            $l = '%' . $f['q'] . '%'; $p[] = $l; $p[] = $l;
        }
        return $this->todos("
            SELECT m.*, pr.nombre AS producto, pr.codigo, pr.unidad_medida,
                   s.nombre AS sucursal, v.codigo_venta
            FROM lf_inventario_movimientos m
            LEFT JOIN productos pr  ON pr.id = m.producto_id
            LEFT JOIN sucursales s  ON s.id = m.sucursal_id
            LEFT JOIN ventas v      ON v.id = m.venta_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY m.creado_en DESC, m.id DESC
            LIMIT " . max(1, min(1000, (int)($f['tope'] ?? 300))), $p);
    }

    // ════════════════════════════════════════════════════════
    //  MOVER
    // ════════════════════════════════════════════════════════

    /**
     * Lo que se lleva una venta. Corre DENTRO de la transacción de la
     * venta: si algo falla aquí, la venta tampoco se guarda.
     *
     * Con «No dejar vender», si algún producto no alcanza no se descuenta
     * nada y se dice cuáles. Con «Avisar», se descuenta aunque quede en
     * negativo: la venta ya pasó, y el negativo dice que falta registrar
     * una entrada.
     *
     * @param array $lineas [['producto_id', 'cantidad'], ...]
     * @return array [producto_id => existencia que quedó]
     */
    public function descontarVenta($ventaId, array $lineas, $sucursalId = null)
    {
        if (!$this->activo()) return [];
        $pide = [];
        foreach ($lineas as $l) {
            $id = (int)($l['producto_id'] ?? $l['id'] ?? 0);
            if ($id > 0) $pide[$id] = ($pide[$id] ?? 0) + (float)($l['cantidad'] ?? 0);
        }
        $llevan = $this->llevan(array_keys($pide));
        if (!$llevan) return [];
        $suc = (int)($sucursalId ?: $this->sucursalActual());

        if ($this->bloquea()) {
            $faltan = [];
            foreach ($llevan as $id => $nombre) {
                $hay = (float)($this->existenciaDe($id, $suc, true) ?? 0);
                if ($hay + 0.0005 < $pide[$id]) $faltan[] = $nombre . ' (quedan ' . self::num(max(0, $hay)) . ')';
            }
            if ($faltan) {
                throw new \InvalidArgumentException('No alcanzan las existencias de ' . implode(', ', $faltan)
                    . '. Quita esas piezas del ticket o registra la entrada en Inventario.');
            }
        }
        $r = [];
        foreach ($llevan as $id => $_) {
            $r[$id] = $this->mover($id, $suc, -$pide[$id], 'venta', ['venta_id' => (int)$ventaId]);
        }
        return $r;
    }

    /**
     * Regresa a las existencias lo que se llevó una venta (al cancelarla).
     * Lee lo que de verdad se descontó, no las líneas de hoy: si el
     * producto dejó de llevar inventario, igual vuelve lo que salió.
     */
    public function devolverVenta($ventaId, $nota = '')
    {
        $netos = $this->todos("
            SELECT producto_id, sucursal_id, SUM(cantidad) AS neto
            FROM lf_inventario_movimientos
            WHERE venta_id = ? AND tipo IN ('venta','devolucion')
            GROUP BY producto_id, sucursal_id", [(int)$ventaId]);
        return $this->enTransaccion(function () use ($netos, $ventaId, $nota) {
            $n = 0;
            foreach ($netos as $f) {
                if ((float)$f['neto'] < -0.0005) {
                    $this->mover($f['producto_id'], $f['sucursal_id'], -(float)$f['neto'], 'devolucion',
                                 ['venta_id' => (int)$ventaId, 'nota' => $nota ?: 'Venta cancelada']);
                    $n++;
                }
            }
            return $n;
        });
    }

    /**
     * Entrada, salida o conteo, desde la pantalla de Inventario.
     *
     *   entrada  llegaron N piezas (de un proveedor, a un costo)
     *   salida   se fueron N piezas sin venderse: dañadas, caducadas, uso
     *            interno. Pide el motivo: es justo lo que luego se pregunta.
     *   conteo   se contaron y hay N. Se mueve la diferencia; si cuadra,
     *            queda anotado que se contó.
     *
     * @return array ['existencia' => lo que quedó, 'cambio' => cuánto se movió]
     */
    public function registrar($tipo, $productoId, $sucursalId, $cantidad, array $d = [])
    {
        if (!in_array($tipo, ['entrada', 'salida', 'conteo'], true)) {
            throw new \InvalidArgumentException('Elige si es entrada, salida o conteo.');
        }
        $cantidad = round((float)str_replace(',', '', (string)$cantidad), 3);
        if ($tipo !== 'conteo' && $cantidad <= 0) throw new \InvalidArgumentException('Escribe cuántas piezas.');
        if ($tipo === 'conteo' && $cantidad < 0)  throw new \InvalidArgumentException('Lo contado no puede ser negativo.');

        $llevan = $this->llevan([(int)$productoId]);
        if (!$llevan) throw new \InvalidArgumentException('Ese producto no lleva existencias. Márcalo en Productos.');
        $suc = (int)$sucursalId;
        if (!$this->uno("SELECT id FROM sucursales WHERE id = ? AND COALESCE(activo,1) = 1", [$suc])) {
            throw new \InvalidArgumentException('Elige la sucursal.');
        }
        $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 255);
        if ($tipo === 'salida' && $nota === '') {
            throw new \InvalidArgumentException('Escribe el motivo de la salida (dañado, caducado, uso interno…).');
        }

        $extra = ['nota' => $nota];
        if ($tipo === 'entrada') {
            $costo = trim((string)($d['costo'] ?? ''));
            if ($costo !== '') {
                $costo = round((float)str_replace(['$', ','], '', $costo), 2);
                if ($costo < 0) throw new \InvalidArgumentException('El costo no puede ser negativo.');
                $extra['costo'] = $costo;
            }
            $prov = (int)($d['proveedor_id'] ?? 0);
            if ($prov) {
                $nom = $this->valor("SELECT nombre FROM proveedores WHERE id = ?", [$prov]);
                if ($nom !== false && $nom !== null) { $extra['proveedor_id'] = $prov; $extra['proveedor'] = $nom; }
            }
        }

        return $this->enTransaccion(function () use ($tipo, $productoId, $suc, $cantidad, $extra) {
            if ($tipo === 'conteo') {
                $hay = (float)($this->existenciaDe($productoId, $suc, true) ?? 0);
                $delta = round($cantidad - $hay, 3);
            } else {
                $delta = $tipo === 'entrada' ? $cantidad : -$cantidad;
            }
            $queda = $this->mover($productoId, $suc, $delta, $tipo, $extra);
            return ['existencia' => $queda, 'cambio' => $delta];
        });
    }

    /**
     * Lo de inventario del formulario de un producto: si lleva
     * existencias, su unidad, el mínimo de cada sucursal y, la primera
     * vez, la existencia inicial.
     */
    public function guardarProducto($productoId, array $d)
    {
        $productoId = (int)$productoId;
        $lleva  = !empty($d['lleva_inventario']) ? 1 : 0;
        $unidad = (string)($d['unidad_medida'] ?? '');
        if (!isset(self::UNIDADES[$unidad])) $unidad = null;   // se respeta la que ya tenía

        return $this->enTransaccion(function () use ($productoId, $lleva, $unidad, $d) {
            $this->db->prepare("UPDATE productos SET lleva_inventario = ?"
                               . ($unidad ? ", unidad_medida = ?" : "") . " WHERE id = ?")
                     ->execute($unidad ? [$lleva, $unidad, $productoId] : [$lleva, $productoId]);
            if (!$lleva) return 0;

            $n = 0;
            $minimos  = (array)($d['minimo'] ?? []);
            $iniciales = (array)($d['inicial'] ?? []);
            foreach ($this->sucursales() as $s) {
                $suc = (int)$s['id'];
                if (isset($minimos[$suc]) && trim((string)$minimos[$suc]) !== '') {
                    $min = max(0, round((float)$minimos[$suc], 3));
                    $hay = $this->existenciaDe($productoId, $suc, true);
                    if ($hay === null) {
                        $this->db->prepare("INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo, activo)
                                            VALUES (?,?,0,?,1)")->execute([$productoId, $suc, $min]);
                    } else {
                        $this->db->prepare("UPDATE producto_sucursal SET stock_minimo = ? WHERE producto_id = ? AND sucursal_id = ?")
                                 ->execute([$min, $productoId, $suc]);
                    }
                }
                $ini = round((float)($iniciales[$suc] ?? 0), 3);
                if ($ini > 0 && !$this->tieneMovimientos($productoId, $suc)
                    && abs((float)($this->existenciaDe($productoId, $suc) ?? 0)) < 0.0005) {
                    $this->mover($productoId, $suc, $ini, 'inicial');
                    $n++;
                }
            }
            return $n;
        });
    }

    /**
     * Mueve la existencia y deja el rastro. NO abre transacción: corre en
     * la de quien llama (la venta, o enTransaccion()).
     *
     * @return float lo que quedó
     */
    private function mover($productoId, $sucursalId, $delta, $tipo, array $extra = [])
    {
        $productoId = (int)$productoId; $sucursalId = (int)$sucursalId;
        if ($sucursalId <= 0) {
            throw new \InvalidArgumentException('No hay sucursal a la cual cargarle la existencia. '
                . 'Da de alta una en Ajustes → Sucursales.');
        }
        $hay = $this->existenciaDe($productoId, $sucursalId, true);
        $queda = round(($hay ?? 0) + $delta, 3);
        if ($hay === null) {
            $this->db->prepare("INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo, activo)
                                VALUES (?,?,?,0,1)")->execute([$productoId, $sucursalId, $queda]);
        } else {
            $this->db->prepare("UPDATE producto_sucursal SET stock = ? WHERE producto_id = ? AND sucursal_id = ?")
                     ->execute([$queda, $productoId, $sucursalId]);
        }
        $this->db->prepare("
            INSERT INTO lf_inventario_movimientos
                (producto_id, sucursal_id, tipo, cantidad, existencia, costo, proveedor_id, proveedor,
                 nota, venta_id, usuario_id, usuario_nombre, creado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$productoId, $sucursalId, $tipo, round($delta, 3), $queda,
                       $extra['costo'] ?? null, $extra['proveedor_id'] ?? null, $extra['proveedor'] ?? null,
                       ($extra['nota'] ?? '') !== '' ? $extra['nota'] : null, $extra['venta_id'] ?? null,
                       $_SESSION['usuario_id'] ?? null, $_SESSION['usuario_nombre'] ?? null]);
        // La suma de todas las sucursales, para quien lea productos.stock.
        $this->db->prepare("UPDATE productos SET stock =
                                (SELECT COALESCE(SUM(stock),0) FROM producto_sucursal WHERE producto_id = ?)
                            WHERE id = ?")->execute([$productoId, $productoId]);
        return $queda;
    }

    // ════════════════════════════════════════════════════════
    //  AYUDANTES
    // ════════════════════════════════════════════════════════

    /** "5", "2.5", "0.125": sin ceros de más. */
    public static function num($n)
    {
        return rtrim(rtrim(number_format((float)$n, 3, '.', ','), '0'), '.');
    }

    /** "5 pza", "2.5 kg". */
    public static function conUnidad($n, $unidad)
    {
        $u = self::abrev($unidad);
        return self::num($n) . ($u !== '' ? ' ' . $u : '');
    }

    /** "pza", "kg"… La del sistema anterior puede venir como «Pieza». */
    public static function abrev($unidad)
    {
        return self::UNIDADES[strtolower(trim((string)$unidad))][1] ?? trim((string)$unidad);
    }

    /** De estos productos, los que llevan inventario: [id => nombre]. */
    private function llevan(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $m = implode(',', array_fill(0, count($ids), '?'));
        $r = [];
        foreach ($this->todos("SELECT id, nombre FROM productos WHERE lleva_inventario = 1 AND id IN ($m) ORDER BY id", $ids) as $f) {
            $r[(int)$f['id']] = $f['nombre'];
        }
        return $r;
    }

    /** La existencia de un producto en una sucursal; null si no tiene fila. */
    private function existenciaDe($productoId, $sucursalId, $paraCambiar = false)
    {
        // FOR UPDATE: dos cajas vendiendo la última pieza al mismo tiempo
        // no deben leer las dos "queda 1".
        $bloqueo = ($paraCambiar && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') ? ' FOR UPDATE' : '';
        $v = $this->valor("SELECT stock FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?" . $bloqueo,
                          [(int)$productoId, (int)$sucursalId]);
        return ($v === false || $v === null) ? null : (float)$v;
    }

    private function tieneMovimientos($productoId, $sucursalId)
    {
        return (bool)$this->valor("SELECT 1 FROM lf_inventario_movimientos WHERE producto_id = ? AND sucursal_id = ? LIMIT 1",
                                  [(int)$productoId, (int)$sucursalId]);
    }

    /** Corre en la transacción que ya haya, o abre una. */
    private function enTransaccion(callable $f)
    {
        if ($this->db->inTransaction()) return $f();
        $this->db->beginTransaction();
        try {
            $r = $f();
            $this->db->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function todos($sql, array $p = [])
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function uno($sql, array $p = [])
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function valor($sql, array $p = [])
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    }
}
