<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Tickets de soporte.
 *
 * VIVEN EN LA BASE PRINCIPAL, no en la de cada empresa.
 *
 * Un ticket habla DE una empresa, no pertenece A una empresa. Si cada
 * base tuviera los suyos, la bandeja de un agente sería la unión de
 * treinta consultas, no se podría ordenar por antigüedad real, y el
 * reporte de "problemas más frecuentes" sería imposible.
 *
 * Además: cuando una empresa se da de baja, sus tickets son justo lo que
 * hay que conservar para entender por qué se fue.
 */
final class TicketRepo
{
    private $db;
    public function __construct(PDO $principal) { $this->db = $principal; }

    const ESTADOS = [
        'abierto'   => 'Abierto',
        'en_curso'  => 'En curso',
        'esperando' => 'Esperando al cliente',
        'resuelto'  => 'Resuelto',
        'cerrado'   => 'Cerrado',
    ];

    /**
     * Prioridad y su tiempo de respuesta comprometido, en horas.
     *
     * El SLA se mide contra la PRIMERA respuesta, no contra la solución.
     * Prometer una solución en cuatro horas es prometer algo que no se
     * controla; responder en cuatro sí.
     */
    const PRIORIDADES = [
        'critica' => ['Crítica', 2,  'No puede cobrar ni facturar'],
        'alta'    => ['Alta',    8,  'Una función clave no sirve'],
        'normal'  => ['Normal',  24, 'Molesta pero hay cómo seguir'],
        'baja'    => ['Baja',    72, 'Duda o mejora'],
    ];

    const CATEGORIAS = [
        'acceso'      => 'No puedo entrar',
        'cobro'       => 'Problemas al cobrar',
        'cifras'      => 'Números que no cuadran',
        'facturacion' => 'Facturación y CFDI',
        'catalogo'    => 'Servicios y precios',
        'usuarios'    => 'Usuarios y permisos',
        'recargas'    => 'Recargas',
        'otro'        => 'Otro',
    ];

    public function asegurar()
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tickets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                folio VARCHAR(20) NOT NULL,
                empresa_id INT NULL,
                asunto VARCHAR(220) NOT NULL,
                categoria VARCHAR(30) NOT NULL DEFAULT 'otro',
                prioridad VARCHAR(20) NOT NULL DEFAULT 'normal',
                estado VARCHAR(20) NOT NULL DEFAULT 'abierto',
                asignado_a INT NULL,
                asignado_nombre VARCHAR(160) NULL,
                creado_por INT NULL,
                creado_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                primera_respuesta_en DATETIME NULL,
                resuelto_en DATETIME NULL,
                cerrado_en DATETIME NULL,
                UNIQUE KEY ix_t_folio (folio),
                KEY ix_t_estado (estado),
                KEY ix_t_empresa (empresa_id),
                KEY ix_t_asignado (asignado_a)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ticket_mensajes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                cuerpo TEXT NOT NULL,
                interno TINYINT(1) NOT NULL DEFAULT 0,
                adjunto VARCHAR(300) NULL,
                autor_id INT NULL,
                autor_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_tm_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ticket_eventos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                que VARCHAR(40) NOT NULL,
                antes VARCHAR(160) NULL,
                despues VARCHAR(160) NULL,
                quien_id INT NULL,
                quien_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_te_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Crear y responder ───────────────────────────────────────

    public function crear(array $d, $usuarioId, $usuarioNombre)
    {
        $this->asegurar();
        $asunto = trim($d['asunto'] ?? '');
        if (mb_strlen($asunto) < 6) {
            throw new \InvalidArgumentException('Escribe un asunto que diga de qué se trata');
        }
        $cuerpo = trim($d['cuerpo'] ?? '');
        if (mb_strlen($cuerpo) < 10) {
            throw new \InvalidArgumentException('Describe el problema: qué pasó y qué esperaba');
        }
        $prio = $d['prioridad'] ?? 'normal';
        if (!isset(self::PRIORIDADES[$prio])) $prio = 'normal';
        $cat = $d['categoria'] ?? 'otro';
        if (!isset(self::CATEGORIAS[$cat])) $cat = 'otro';

        $folio = 'T' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        $this->db->prepare("
            INSERT INTO tickets (folio, empresa_id, asunto, categoria, prioridad, estado,
                                 creado_por, creado_nombre, creado_en)
            VALUES (?,?,?,?,?, 'abierto', ?,?, NOW())
        ")->execute([$folio, ((int)($d['empresa_id'] ?? 0)) ?: null, $asunto, $cat, $prio,
                     $usuarioId ?: null, $usuarioNombre]);
        $id = (int)$this->db->lastInsertId();

        $this->db->prepare("
            INSERT INTO ticket_mensajes (ticket_id, cuerpo, interno, autor_id, autor_nombre, creado_en)
            VALUES (?,?,0,?,?,NOW())
        ")->execute([$id, $cuerpo, $usuarioId ?: null, $usuarioNombre]);

        $this->evento($id, 'creado', null, $folio, $usuarioId, $usuarioNombre);
        return ['id' => $id, 'folio' => $folio];
    }

    public function responder($ticketId, $cuerpo, $interno, $adjunto, $usuarioId, $usuarioNombre)
    {
        $cuerpo = trim((string)$cuerpo);
        if ($cuerpo === '') throw new \InvalidArgumentException('Escribe la respuesta');

        $t = $this->uno($ticketId);
        if (!$t) throw new \InvalidArgumentException('Ese ticket no existe');

        $this->db->prepare("
            INSERT INTO ticket_mensajes (ticket_id, cuerpo, interno, adjunto,
                                         autor_id, autor_nombre, creado_en)
            VALUES (?,?,?,?,?,?,NOW())
        ")->execute([(int)$ticketId, $cuerpo, $interno ? 1 : 0, $adjunto ?: null,
                     $usuarioId ?: null, $usuarioNombre]);

        // Una nota interna NO cuenta como primera respuesta: el cliente no
        // la ve, así que para él nadie le ha contestado todavía.
        if (!$interno && empty($t['primera_respuesta_en'])) {
            $this->db->prepare("UPDATE tickets SET primera_respuesta_en = NOW() WHERE id = ?")
                     ->execute([(int)$ticketId]);
        }
        if (!$interno && $t['estado'] === 'abierto') {
            $this->cambiar($ticketId, 'estado', 'en_curso', $usuarioId, $usuarioNombre);
        }
        return true;
    }

    // ── Cambios de estado ───────────────────────────────────────

    public function cambiar($ticketId, $campo, $valor, $usuarioId, $usuarioNombre)
    {
        $permitidos = [
            'estado'    => self::ESTADOS,
            'prioridad' => array_map(function ($p) { return $p[0]; }, self::PRIORIDADES),
            'categoria' => self::CATEGORIAS,
        ];
        if (!isset($permitidos[$campo])) throw new \InvalidArgumentException('Campo no válido');
        if (!isset($permitidos[$campo][$valor])) throw new \InvalidArgumentException('Valor no válido');

        $t = $this->uno($ticketId);
        if (!$t) throw new \InvalidArgumentException('Ese ticket no existe');
        if ($t[$campo] === $valor) return true;

        $extra = '';
        if ($campo === 'estado' && $valor === 'resuelto') $extra = ', resuelto_en = NOW()';
        if ($campo === 'estado' && $valor === 'cerrado')  $extra = ', cerrado_en = NOW()';
        // Reabrir limpia las marcas: si no, el tiempo de resolución
        // quedaría contado desde la primera vez y saldría falso.
        if ($campo === 'estado' && in_array($valor, ['abierto','en_curso','esperando'], true)) {
            $extra = ', resuelto_en = NULL, cerrado_en = NULL';
        }

        $this->db->prepare("UPDATE tickets SET `$campo` = ? {$extra} WHERE id = ?")
                 ->execute([$valor, (int)$ticketId]);
        $this->evento($ticketId, $campo, $t[$campo], $valor, $usuarioId, $usuarioNombre);
        return true;
    }

    public function asignar($ticketId, $aId, $aNombre, $usuarioId, $usuarioNombre)
    {
        $t = $this->uno($ticketId);
        if (!$t) throw new \InvalidArgumentException('Ese ticket no existe');
        $this->db->prepare("UPDATE tickets SET asignado_a = ?, asignado_nombre = ? WHERE id = ?")
                 ->execute([$aId ?: null, $aNombre ?: null, (int)$ticketId]);
        $this->evento($ticketId, 'asignado', $t['asignado_nombre'], $aNombre,
                      $usuarioId, $usuarioNombre);
        return true;
    }

    private function evento($ticketId, $que, $antes, $despues, $quienId, $quienNombre)
    {
        $this->db->prepare("
            INSERT INTO ticket_eventos (ticket_id, que, antes, despues, quien_id, quien_nombre, creado_en)
            VALUES (?,?,?,?,?,?,NOW())
        ")->execute([(int)$ticketId, $que, $antes, $despues, $quienId ?: null, $quienNombre]);
    }

    // ── Leer ────────────────────────────────────────────────────

    public function uno($id)
    {
        $this->asegurar();
        $st = $this->db->prepare("
            SELECT t.*, e.nombre_empresa, e.email_admin, e.nombre_base_datos
            FROM tickets t
            LEFT JOIN empresas e ON e.id = t.empresa_id
            WHERE t.id = ?");
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    public function mensajes($ticketId)
    {
        $st = $this->db->prepare("
            SELECT * FROM ticket_mensajes WHERE ticket_id = ? ORDER BY creado_en, id");
        $st->execute([(int)$ticketId]);
        return $st->fetchAll();
    }

    public function eventos($ticketId)
    {
        $st = $this->db->prepare("
            SELECT * FROM ticket_eventos WHERE ticket_id = ? ORDER BY creado_en DESC, id DESC");
        $st->execute([(int)$ticketId]);
        return $st->fetchAll();
    }

    /**
     * La bandeja.
     *
     * `vencido` compara contra la PRIMERA respuesta si ya la hubo, y
     * contra ahora si todavía no: un ticket de hace tres días que se
     * contestó en una hora cumplió, aunque siga abierto.
     */
    public function bandeja($filtros = [], $limite = 40, $desfase = 0)
    {
        $this->asegurar();
        $w = []; $p = [];
        if (!empty($filtros['estado'])) {
            if ($filtros['estado'] === 'activos') {
                $w[] = "t.estado IN ('abierto','en_curso','esperando')";
            } else { $w[] = 't.estado = ?'; $p[] = $filtros['estado']; }
        }
        if (!empty($filtros['prioridad'])) { $w[] = 't.prioridad = ?'; $p[] = $filtros['prioridad']; }
        if (!empty($filtros['empresa']))   { $w[] = 't.empresa_id = ?'; $p[] = (int)$filtros['empresa']; }
        if (!empty($filtros['asignado']))  { $w[] = 't.asignado_a = ?'; $p[] = (int)$filtros['asignado']; }
        if (!empty($filtros['q'])) {
            $w[] = '(t.asunto LIKE ? OR t.folio LIKE ? OR e.nombre_empresa LIKE ?)';
            $l = '%' . $filtros['q'] . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $st = $this->db->prepare("
            SELECT t.*, e.nombre_empresa,
                   TIMESTAMPDIFF(MINUTE, t.creado_en,
                       COALESCE(t.primera_respuesta_en, NOW())) AS min_respuesta,
                   (SELECT COUNT(*) FROM ticket_mensajes m WHERE m.ticket_id = t.id) AS mensajes
            FROM tickets t
            LEFT JOIN empresas e ON e.id = t.empresa_id
            {$where}
            ORDER BY FIELD(t.prioridad,'critica','alta','normal','baja'), t.creado_en
            LIMIT " . (int)$limite . " OFFSET " . (int)$desfase);
        $st->execute($p);
        return $st->fetchAll();
    }

    /** ¿Se pasó del tiempo comprometido? */
    public static function vencido(array $t)
    {
        $horas = self::PRIORIDADES[$t['prioridad']][1] ?? 24;
        return (int)$t['min_respuesta'] > $horas * 60;
    }

    public function cifras()
    {
        $this->asegurar();
        $f = $this->db->query("
            SELECT COUNT(*) total,
                   SUM(estado IN ('abierto','en_curso','esperando')) activos,
                   SUM(estado = 'abierto') sin_tocar,
                   SUM(estado = 'resuelto') resueltos,
                   SUM(prioridad = 'critica' AND estado IN ('abierto','en_curso','esperando')) criticos,
                   AVG(CASE WHEN primera_respuesta_en IS NOT NULL
                       THEN TIMESTAMPDIFF(MINUTE, creado_en, primera_respuesta_en) END) min_respuesta,
                   AVG(CASE WHEN resuelto_en IS NOT NULL
                       THEN TIMESTAMPDIFF(HOUR, creado_en, resuelto_en) END) horas_resolucion
            FROM tickets")->fetch();
        return $f ?: [];
    }

    /** De qué se queja la gente. Ordena por lo que más se repite. */
    public function porCategoria()
    {
        $this->asegurar();
        return $this->db->query("
            SELECT categoria, COUNT(*) cuantos,
                   SUM(estado IN ('abierto','en_curso','esperando')) abiertos,
                   AVG(CASE WHEN resuelto_en IS NOT NULL
                       THEN TIMESTAMPDIFF(HOUR, creado_en, resuelto_en) END) horas
            FROM tickets GROUP BY categoria ORDER BY cuantos DESC")->fetchAll();
    }
}
