<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Encuentra a un usuario entre las bases de todas las empresas.
 *
 * El sistema anterior abría una conexión a CADA base en cada intento de
 * ingreso: con cinco empresas son cinco conexiones, y un intento fallido
 * las gasta todas.
 *
 * Aquí se mantiene un índice en la base principal que dice en qué empresa
 * vive cada usuario. La primera vez se busca recorriendo; de ahí en
 * adelante es una consulta. Si el índice falla o está desactualizado, se
 * vuelve a recorrer: nunca deja a nadie fuera.
 */
final class AutenticacionRepo
{
    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    /** Crea el índice si no existe. Se llama una vez, al arrancar. */
    public function prepararIndice()
    {
        try {
            $this->principal->exec("
                CREATE TABLE IF NOT EXISTS usuarios_indice (
                    identificador VARCHAR(190) NOT NULL,
                    empresa_id    INT NOT NULL,
                    visto         DATETIME NOT NULL,
                    PRIMARY KEY (identificador),
                    KEY ix_ui_empresa (empresa_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return true;
        } catch (\Throwable $e) {
            // Sin permiso de CREATE seguimos funcionando: solo más lento.
            error_log('[LibertyFin] no se pudo crear usuarios_indice: ' . $e->getMessage());
            return false;
        }
    }

    public function empresas()
    {
        $st = $this->principal->query("
            SELECT id, nombre_empresa, nombre_base_datos, activo, fecha_vencimiento, plan
            FROM empresas");
        return $st->fetchAll();
    }

    /**
     * Busca al usuario. Devuelve ['empresa'=>..., 'usuario'=>...] o null.
     * NO verifica la contraseña: eso es trabajo del servicio.
     */
    public function buscar($identificador)
    {
        $identificador = trim($identificador);
        if ($identificador === '') return null;

        // 1 · Por el índice
        $empresaId = null;
        try {
            $st = $this->principal->prepare(
                "SELECT empresa_id FROM usuarios_indice WHERE identificador = ?");
            $st->execute([mb_strtolower($identificador)]);
            $empresaId = $st->fetchColumn() ?: null;
        } catch (\Throwable $e) { /* sin índice, se recorre */ }

        $empresas = $this->empresas();
        if ($empresaId) {
            foreach ($empresas as $e) {
                if ((int)$e['id'] === (int)$empresaId) {
                    $u = $this->enBase($e['nombre_base_datos'], $identificador);
                    if ($u) return ['empresa' => $e, 'usuario' => $u];
                    break;   // el índice quedó viejo: se recorre
                }
            }
        }

        // 2 · Recorriendo
        foreach ($empresas as $e) {
            $u = $this->enBase($e['nombre_base_datos'], $identificador);
            if ($u) {
                $this->recordar($identificador, $e['id']);
                return ['empresa' => $e, 'usuario' => $u];
            }
        }
        return null;
    }

    private function enBase($base, $identificador)
    {
        // El nombre de la base entra en el DSN, no como parámetro: se valida.
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$base)) {
            error_log('[LibertyFin] nombre de base inválido: ' . $base);
            return null;
        }
        try {
            $db = Conexion::de($base);
            $st = $db->prepare("
                SELECT id, username, password, nombre, rol, sucursal_id, email
                FROM usuarios WHERE username = ? OR email = ? LIMIT 1");
            $st->execute([$identificador, $identificador]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] no se pudo consultar ' . $base . ': ' . $e->getMessage());
            return null;
        }
    }

    private function recordar($identificador, $empresaId)
    {
        try {
            $this->principal->prepare("
                INSERT INTO usuarios_indice (identificador, empresa_id, visto)
                VALUES (?,?,NOW())
                ON DUPLICATE KEY UPDATE empresa_id = VALUES(empresa_id), visto = NOW()
            ")->execute([mb_strtolower(trim($identificador)), (int)$empresaId]);
        } catch (\Throwable $e) { /* el índice es una comodidad, no un requisito */ }
    }

    public function sucursal($base, $sucursalId)
    {
        if (!$sucursalId) return null;
        try {
            $db = Conexion::de($base);
            $st = $db->prepare("SELECT id, nombre, es_matriz FROM sucursales WHERE id = ?");
            $st->execute([(int)$sucursalId]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) { return null; }
    }

    // ── USUARIOS DE PLATAFORMA ──────────────────────────────────
    //
    // Soporte, validación y superadministración NO pertenecen a ninguna
    // empresa. Tenerlos dentro de la base de una —como estaban— provoca
    // tres cosas malas:
    //
    //   · Aparecen en la lista de "Equipo" de esa empresa, que no los
    //     contrató y no debería administrarlos.
    //   · Su administrador puede bloquearlos o cambiarles la contraseña.
    //   · Si esa empresa se da de baja, soporte se queda sin cuentas.
    //
    // Por eso viven en la base principal, en su propia tabla.

    public function asegurarPlataforma()
    {
        try {
            $this->principal->exec("
                CREATE TABLE IF NOT EXISTS usuarios_plataforma (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(60) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    nombre VARCHAR(160) NOT NULL,
                    email VARCHAR(160) NULL,
                    rol VARCHAR(30) NOT NULL DEFAULT 'soporte',
                    activo TINYINT(1) NOT NULL DEFAULT 1,
                    creado_en DATETIME NOT NULL,
                    ultimo_acceso DATETIME NULL,
                    UNIQUE KEY ix_up_user (username),
                    KEY ix_up_activo (activo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] usuarios_plataforma: ' . $e->getMessage());
        }
    }

    /** Busca en la tabla de plataforma. Se consulta ANTES que las empresas. */
    public function usuarioPlataforma($identificador)
    {
        $this->asegurarPlataforma();
        try {
            $st = $this->principal->prepare("
                SELECT * FROM usuarios_plataforma
                WHERE (username = ? OR email = ?) LIMIT 1");
            $st->execute([$identificador, $identificador]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) { return null; }
    }

    public function marcarAccesoPlataforma($id)
    {
        try {
            $this->principal->prepare(
                "UPDATE usuarios_plataforma SET ultimo_acceso = NOW() WHERE id = ?")
                ->execute([(int)$id]);
        } catch (\Throwable $e) { /* no es grave */ }
    }

    public function listaPlataforma()
    {
        $this->asegurarPlataforma();
        try {
            return $this->principal->query("
                SELECT id, username, nombre, email, rol, activo, creado_en, ultimo_acceso
                FROM usuarios_plataforma ORDER BY activo DESC, nombre")->fetchAll();
        } catch (\Throwable $e) { return []; }
    }
}
