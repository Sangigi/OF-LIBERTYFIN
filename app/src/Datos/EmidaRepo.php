<?php
namespace LibertyFin\Datos;

/**
 * El catálogo de Emida, guardado en la base de la empresa.
 *
 * POR QUÉ SE GUARDA Y NO SE PIDE CADA VEZ
 *
 * Son más de mil productos. Pedirlos al proveedor en cada carga de la
 * pantalla de recargas la haría tardar segundos, dependería de que su
 * servidor conteste, y no vendería nada si no contesta.
 *
 * Así que se baja una vez, se guarda, y se refresca cuando alguien lo
 * pide. Los precios de Emida cambian poco; lo que cambia es qué
 * productos están disponibles, y eso tampoco a cada minuto.
 */
final class EmidaRepo extends Repo
{
    public function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_emida_productos (
                    producto_id VARCHAR(30) NOT NULL PRIMARY KEY,
                    nombre VARCHAR(220) NOT NULL,
                    categoria VARCHAR(80) NULL,
                    carrier VARCHAR(80) NULL,
                    comision DECIMAL(10,2) NOT NULL DEFAULT 0,
                    monto DECIMAL(12,2) NOT NULL DEFAULT 0,
                    monto_min DECIMAL(12,2) NOT NULL DEFAULT 0,
                    monto_max DECIMAL(12,2) NOT NULL DEFAULT 0,
                    tipo VARCHAR(12) NOT NULL DEFAULT 'directa',
                    activo TINYINT(1) NOT NULL DEFAULT 1,
                    actualizado DATETIME NULL,
                    KEY ix_ep_cat (categoria),
                    KEY ix_ep_carrier (carrier),
                    KEY ix_ep_activo (activo, categoria)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_emida_transacciones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sales_id VARCHAR(30) NOT NULL,
                    producto_id VARCHAR(30) NOT NULL,
                    producto_nombre VARCHAR(220) NULL,
                    cuenta VARCHAR(40) NOT NULL,
                    monto DECIMAL(12,2) NOT NULL,
                    comision DECIMAL(10,2) NOT NULL DEFAULT 0,
                    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                    folio_proveedor VARCHAR(80) NULL,
                    codigo VARCHAR(10) NULL,
                    h2h VARCHAR(10) NULL,
                    mensaje VARCHAR(300) NULL,
                    venta_id INT NULL,
                    usuario_id INT NULL,
                    usuario_nombre VARCHAR(160) NULL,
                    creado_en DATETIME NOT NULL,
                    UNIQUE KEY ix_et_sales (sales_id),
                    KEY ix_et_fecha (creado_en),
                    KEY ix_et_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] tablas emida: ' . $e->getMessage());
        }
    }

    /**
     * Guarda el catálogo recibido.
     *
     * No borra y reinserta: marca todo como inactivo y revive lo que
     * llegó. Así un producto que el proveedor dejó de ofrecer deja de
     * venderse pero no desaparece, y las transacciones viejas siguen
     * sabiendo qué se vendió.
     */
    public function guardarCatalogo(array $productos)
    {
        $this->asegurar();
        if (!$productos) return 0;

        $this->db->beginTransaction();
        try {
            $this->db->exec("UPDATE lf_emida_productos SET activo = 0");
            $st = $this->db->prepare("
                INSERT INTO lf_emida_productos
                    (producto_id, nombre, categoria, carrier, comision, monto,
                     monto_min, monto_max, tipo, activo, actualizado)
                VALUES (?,?,?,?,?,?,?,?,?,1,NOW())
                ON DUPLICATE KEY UPDATE
                    nombre=VALUES(nombre), categoria=VALUES(categoria), carrier=VALUES(carrier),
                    comision=VALUES(comision), monto=VALUES(monto), monto_min=VALUES(monto_min),
                    monto_max=VALUES(monto_max), tipo=VALUES(tipo), activo=1, actualizado=NOW()");
            $n = 0;
            foreach ($productos as $p) {
                $st->execute([
                    $p['producto_id'], mb_substr($p['nombre'], 0, 220),
                    mb_substr((string)$p['categoria'], 0, 80), mb_substr((string)$p['carrier'], 0, 80),
                    $p['comision'], $p['monto'], $p['monto_min'], $p['monto_max'], $p['tipo'],
                ]);
                $n++;
            }
            $this->db->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function productos($buscar = '', $categoria = '', $carrier = '', $limite = 60, $desfase = 0)
    {
        $this->asegurar();
        $w = ['activo = 1']; $p = [];
        if ($buscar !== '')    { $w[] = '(nombre LIKE ? OR producto_id = ?)';
                                 $p[] = '%'.$buscar.'%'; $p[] = $buscar; }
        if ($categoria !== '') { $w[] = 'categoria = ?'; $p[] = $categoria; }
        if ($carrier !== '')   { $w[] = 'carrier = ?';   $p[] = $carrier; }
        return $this->todos("SELECT * FROM lf_emida_productos WHERE " . implode(' AND ', $w)
            . " ORDER BY categoria, carrier, monto
               LIMIT " . (int)$limite . " OFFSET " . (int)$desfase, $p);
    }

    public function cuantos($buscar = '', $categoria = '', $carrier = '')
    {
        $this->asegurar();
        $w = ['activo = 1']; $p = [];
        if ($buscar !== '')    { $w[] = '(nombre LIKE ? OR producto_id = ?)';
                                 $p[] = '%'.$buscar.'%'; $p[] = $buscar; }
        if ($categoria !== '') { $w[] = 'categoria = ?'; $p[] = $categoria; }
        if ($carrier !== '')   { $w[] = 'carrier = ?';   $p[] = $carrier; }
        return (int)$this->valor("SELECT COUNT(*) FROM lf_emida_productos WHERE "
            . implode(' AND ', $w), $p);
    }

    /** Un producto por su identificador de Emida. */
    public function porId($productoId)
    {
        $this->asegurar();
        return $this->uno("SELECT * FROM lf_emida_productos WHERE producto_id = ? AND activo = 1",
            [(string)$productoId]);
    }

    public function categorias()
    {
        $this->asegurar();
        $r = $this->todos("SELECT categoria, COUNT(*) n FROM lf_emida_productos
            WHERE activo = 1 AND categoria <> '' GROUP BY categoria ORDER BY categoria");
        return $r;
    }

    public function carriers($categoria = '')
    {
        $this->asegurar();
        $w = "activo = 1 AND carrier <> ''"; $p = [];
        if ($categoria !== '') { $w .= " AND categoria = ?"; $p[] = $categoria; }
        return $this->todos("SELECT carrier, COUNT(*) n FROM lf_emida_productos
            WHERE {$w} GROUP BY carrier ORDER BY carrier", $p);
    }

    public function cuandoSeActualizo()
    {
        $this->asegurar();
        return $this->valor("SELECT MAX(actualizado) FROM lf_emida_productos");
    }

    // ── Transacciones ───────────────────────────────────────────

    public function registrar(array $d)
    {
        $this->asegurar();
        $this->db->prepare("
            INSERT INTO lf_emida_transacciones
                (sales_id, producto_id, producto_nombre, cuenta, monto, comision,
                 estado, folio_proveedor, codigo, h2h, mensaje, usuario_id, usuario_nombre, creado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE estado=VALUES(estado), folio_proveedor=VALUES(folio_proveedor),
                                    codigo=VALUES(codigo), h2h=VALUES(h2h), mensaje=VALUES(mensaje)
        ")->execute([
            $d['sales_id'], $d['producto_id'], $d['producto_nombre'] ?? null, $d['cuenta'],
            $d['monto'], $d['comision'] ?? 0, $d['estado'], $d['folio'] ?? null,
            $d['codigo'] ?? null, $d['h2h'] ?? null, mb_substr((string)($d['mensaje'] ?? ''), 0, 300),
            $d['usuario_id'] ?? null, $d['usuario_nombre'] ?? null,
        ]);
        return true;
    }

    public function delDia($fecha = null)
    {
        $this->asegurar();
        $f = $fecha ?: date('Y-m-d');
        return $this->todos("SELECT * FROM lf_emida_transacciones
            WHERE DATE(creado_en) = ? ORDER BY creado_en DESC", [$f]);
    }

    public function cifrasDelDia($fecha = null)
    {
        $this->asegurar();
        $f = $fecha ?: date('Y-m-d');
        return $this->uno("
            SELECT COUNT(*) AS cuantas,
                   SUM(estado = 'exitosa') AS exitosas,
                   COALESCE(SUM(CASE WHEN estado = 'exitosa' THEN monto END),0) AS vendido,
                   COALESCE(SUM(CASE WHEN estado = 'exitosa' THEN comision END),0) AS comision
            FROM lf_emida_transacciones WHERE DATE(creado_en) = ?", [$f]) ?: [];
    }

    /**
     * Lee un catálogo pegado desde la pantalla del proveedor.
     *
     * POR QUÉ EXISTE
     *
     * El intermediario no tiene script de catálogo, y pedírselo a Emida
     * puede tardar. Mientras tanto, su portal sí muestra la tabla y se
     * puede copiar completa. Esto la entiende.
     *
     * Acepta columnas separadas por tabulador —que es lo que sale al
     * copiar de una tabla web— o por coma. Y se salta los renglones que
     * no empiezan con un identificador numérico, que son los títulos y
     * los botones que se copian de más.
     */
    public static function leerPegado($texto)
    {
        // PRIMERO SE JUNTAN LAS LÍNEAS PARTIDAS.
        //
        // En la tabla del proveedor hay nombres de dos renglones:
        //
        //     5094900  Pago INFONAVIT
        //     Creditos y financieras   PAGO SERVICIOS   INFONAVIT  ...
        //
        // Al copiar, eso llega como dos líneas. Un renglón que NO empieza
        // con un identificador es la continuación del anterior, así que se
        // pega en vez de descartarse. Sin esto se perdía uno de cada tres
        // productos, y justo los de pago de servicios.
        $lineas = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$texto) as $cruda) {
            if (trim($cruda) === '') continue;
            if (preg_match('/^\s*\d{4,}\s*[\t,]/', $cruda) || !$lineas) {
                $lineas[] = rtrim($cruda);
            } else {
                $lineas[count($lineas) - 1] .= ' ' . trim($cruda);
            }
        }

        $productos = [];
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;

            $c = strpos($linea, "\t") !== false
               ? preg_split('/\t+/', $linea)
               : str_getcsv($linea);
            $c = array_map('trim', $c);
            if (count($c) < 4) continue;

            $id = preg_replace('/\D/', '', $c[0]);
            if ($id === '' || strlen($id) < 4) continue;   // encabezado o basura

            $num = function ($v) {
                $v = preg_replace('/[^0-9.]/', '', (string)$v);
                return $v === '' ? 0.0 : (float)$v;
            };
            // El nombre puede traer un salto adentro, de los que la tabla
            // parte en dos líneas: se aplana.
            $nombre = preg_replace('/\s+/', ' ', (string)($c[1] ?? ''));

            $monto = $num($c[5] ?? '');
            $min   = $num($c[6] ?? '');
            $max   = $num($c[7] ?? '');
            $tipo  = stripos((string)($c[8] ?? ''), 'consulta') !== false ? 'consulta'
                   : (($min > 0 || $max > 0 || $monto <= 0) ? 'consulta' : 'directa');

            $productos[] = [
                'producto_id' => $id,
                'nombre'      => mb_substr($nombre, 0, 220),
                'categoria'   => mb_substr((string)($c[2] ?? ''), 0, 80),
                'carrier'     => mb_substr((string)($c[3] ?? ''), 0, 80),
                'comision'    => $num($c[4] ?? ''),
                'monto'       => $monto,
                'monto_min'   => $min,
                'monto_max'   => $max,
                'tipo'        => $tipo,
            ];
        }
        return $productos;
    }
}
