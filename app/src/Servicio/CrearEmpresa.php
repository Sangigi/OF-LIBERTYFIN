<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use PDO;

/**
 * Alta de una empresa nueva.
 *
 * CÓMO ERA ANTES, Y POR QUÉ CAMBIA
 *
 * `registroEmpresa.php` era una página PÚBLICA, sin sesión, que creaba
 * bases de datos llamando a la API de cPanel con el token escrito dentro
 * del archivo. Cualquiera que diera con esa URL podía crear bases en el
 * hosting hasta llenarlo.
 *
 * Aquí el alta sigue siendo autoservicio —es lo que el negocio necesita—
 * pero con tres cosas que antes no había:
 *
 *   · Las credenciales de cPanel viven en config/integraciones.php.
 *   · Sin ellas, el registro simplemente no existe: la ruta da 404.
 *   · Se guarda como SOLICITUD, no como empresa activa. La base se crea
 *     cuando alguien de LibertyFin la aprueba.
 *
 * Ese último punto es el que de verdad protege: una solicitud cuesta un
 * renglón en una tabla; una base de datos cuesta espacio, un usuario de
 * MySQL y una entrada en el respaldo.
 */
final class CrearEmpresa
{
    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    /** Se crea sola la primera vez. */
    private function asegurar()
    {
        $this->principal->exec("
            CREATE TABLE IF NOT EXISTS solicitudes_empresa (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre_empresa VARCHAR(200) NOT NULL,
                giro_comercial VARCHAR(200) NULL,
                rfc VARCHAR(20) NULL,
                nombre_contacto VARCHAR(200) NOT NULL,
                email_admin VARCHAR(160) NOT NULL,
                telefono VARCHAR(30) NULL,
                no_distribuidor VARCHAR(50) NULL,
                plan VARCHAR(40) NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                motivo_rechazo VARCHAR(400) NULL,
                empresa_id INT NULL,
                ip VARCHAR(45) NULL,
                creada_en DATETIME NOT NULL,
                resuelta_en DATETIME NULL,
                resuelta_por INT NULL,
                KEY ix_se_estado (estado),
                KEY ix_se_email (email_admin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** @throws \InvalidArgumentException con un mensaje apto para mostrar */
    public function solicitar(array $d, $ip)
    {
        $this->asegurar();

        $nombre = trim($d['nombre_empresa'] ?? '');
        if (mb_strlen($nombre) < 3) {
            throw new \InvalidArgumentException('Escribe el nombre de tu negocio');
        }
        $contacto = trim($d['nombre_contacto'] ?? '');
        if (mb_strlen($contacto) < 3) {
            throw new \InvalidArgumentException('Escribe tu nombre');
        }
        $email = trim($d['email_admin'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo no es válido');
        }
        $rfc = mb_strtoupper(trim($d['rfc'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            throw new \InvalidArgumentException('El RFC no tiene el formato correcto');
        }

        // Un correo, una solicitud pendiente. Sin esto, recargar el
        // formulario llena la tabla de duplicados y quien revisa no sabe
        // cuál atender.
        $st = $this->principal->prepare("
            SELECT COUNT(*) FROM solicitudes_empresa
            WHERE email_admin = ? AND estado = 'pendiente'");
        $st->execute([$email]);
        if ((int)$st->fetchColumn() > 0) {
            throw new \InvalidArgumentException(
                'Ya hay una solicitud en revisión con ese correo. Te avisamos en cuanto esté lista.');
        }

        // Y un tope por IP: una página pública sin tope es una invitación.
        $st = $this->principal->prepare("
            SELECT COUNT(*) FROM solicitudes_empresa
            WHERE ip = ? AND creada_en > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 3) {
            throw new \InvalidArgumentException('Demasiadas solicitudes. Inténtalo más tarde.');
        }

        $this->principal->prepare("
            INSERT INTO solicitudes_empresa
                (nombre_empresa, giro_comercial, rfc, nombre_contacto, email_admin,
                 telefono, no_distribuidor, plan, estado, ip, creada_en)
            VALUES (?,?,?,?,?,?,?,?, 'pendiente', ?, NOW())
        ")->execute([
            $nombre, trim($d['giro_comercial'] ?? '') ?: null, $rfc ?: null,
            $contacto, $email,
            preg_replace('/[^0-9+]/', '', (string)($d['telefono'] ?? '')) ?: null,
            trim($d['no_distribuidor'] ?? '') ?: null,
            trim($d['plan'] ?? '') ?: 'prueba',
            $ip,
        ]);
        return (int)$this->principal->lastInsertId();
    }

    public function pendientes()
    {
        $this->asegurar();
        $st = $this->principal->query("
            SELECT *, TIMESTAMPDIFF(HOUR, creada_en, NOW()) AS horas
            FROM solicitudes_empresa WHERE estado = 'pendiente'
            ORDER BY creada_en");
        return $st->fetchAll();
    }

    /**
     * Aprueba una solicitud: crea la base, carga el esquema, corre las
     * migraciones y deja al administrador listo para entrar.
     *
     * Devuelve la contraseña generada UNA sola vez. No se guarda en claro
     * en ningún lado: se muestra a quien aprueba para que la entregue.
     */
    public function aprobar($id, $raiz, $revisorId)
    {
        $this->asegurar();
        $cp = Integraciones::de('cpanel');
        if (!$cp) {
            throw new \InvalidArgumentException(
                'Faltan las credenciales de cPanel en config/integraciones.php: '
                . 'sin ellas no se puede crear la base de la empresa');
        }

        $st = $this->principal->prepare("SELECT * FROM solicitudes_empresa WHERE id = ?");
        $st->execute([(int)$id]);
        $s = $st->fetch();
        if (!$s) throw new \InvalidArgumentException('Esa solicitud no existe');
        if ($s['estado'] !== 'pendiente') throw new \InvalidArgumentException('Ya estaba resuelta');

        $esquema = $raiz . '/config/esquema.sql';
        if (!is_readable($esquema)) {
            throw new \RuntimeException('No se encuentra config/esquema.sql');
        }

        // El nombre de la base sale del nombre del negocio, limpio y corto.
        // cPanel le antepone el usuario de la cuenta, así que el prefijo no
        // se escribe aquí.
        $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $s['nombre_empresa']));
        $sufijo = substr($slug, 0, 8) . substr(bin2hex(random_bytes(3)), 0, 4);
        $base = $cp['usuario'] . '_' . $sufijo;

        $creada = self::crearBaseEnCpanel($cp, $sufijo);
        if (!$creada['ok']) {
            throw new \RuntimeException('cPanel no creó la base: ' . $creada['error']);
        }

        // Cargar el esquema y ponerlo al día.
        $db = Conexion::de($base);
        $sql = file_get_contents($esquema);
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $sent) {
            if (strpos($sent, '--') === 0 || $sent === '') continue;
            try { $db->exec($sent); }
            catch (\Throwable $e) { error_log('[LibertyFin] esquema: ' . $e->getMessage()); }
        }
        Migraciones::aplicar($db);

        // Sucursal matriz y administrador.
        $db->exec("INSERT INTO sucursales (nombre, es_matriz, activo, fecha_creacion)
                   VALUES ('Matriz', 1, 1, NOW())");
        $sucursal = (int)$db->lastInsertId();

        $usuario = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $s['email_admin'])[0]));
        $usuario = substr($usuario ?: 'admin', 0, 30);
        $clave   = self::clave();
        $db->prepare("
            INSERT INTO usuarios (username, password, nombre, email, rol, sucursal_id)
            VALUES (?,?,?,?, 'admin', ?)
        ")->execute([$usuario, password_hash($clave, PASSWORD_DEFAULT),
                     $s['nombre_contacto'], $s['email_admin'], $sucursal]);

        // Y el renglón en empresas, ya activa.
        $this->principal->prepare("
            INSERT INTO empresas
                (nombre_empresa, giro_comercial, rfc, no_distribuidor, telefono,
                 nombre_contacto, email_admin, usuario_admin, nombre_base_datos,
                 activo, plan, fecha_vencimiento)
            VALUES (?,?,?,?,?,?,?,?,?, 1, ?, DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        ")->execute([
            $s['nombre_empresa'], $s['giro_comercial'], $s['rfc'], $s['no_distribuidor'],
            $s['telefono'], $s['nombre_contacto'], $s['email_admin'], $usuario, $base,
            $s['plan'] ?: 'prueba',
        ]);
        $empresaId = (int)$this->principal->lastInsertId();

        $this->principal->prepare("
            UPDATE solicitudes_empresa
            SET estado = 'aprobada', empresa_id = ?, resuelta_en = NOW(), resuelta_por = ?
            WHERE id = ?")->execute([$empresaId, $revisorId ?: null, (int)$id]);

        return ['base' => $base, 'usuario' => $usuario, 'clave' => $clave,
                'empresa_id' => $empresaId, 'email' => $s['email_admin'],
                'contacto' => $s['nombre_contacto'], 'empresa' => $s['nombre_empresa']];
    }

    public function rechazar($id, $motivo, $revisorId)
    {
        $this->asegurar();
        $motivo = trim((string)$motivo);
        if (mb_strlen($motivo) < 10) {
            throw new \InvalidArgumentException(
                'Escribe por qué se rechaza: es lo único que el solicitante va a recibir');
        }
        $st = $this->principal->prepare("SELECT * FROM solicitudes_empresa WHERE id = ?");
        $st->execute([(int)$id]);
        $sol = $st->fetch();

        $this->principal->prepare("
            UPDATE solicitudes_empresa
            SET estado = 'rechazada', motivo_rechazo = ?, resuelta_en = NOW(), resuelta_por = ?
            WHERE id = ? AND estado = 'pendiente'")->execute([$motivo, $revisorId ?: null, (int)$id]);

        // Se devuelve la solicitud para poder avisarle a quien la mandó.
        return $sol ?: true;
    }

    /**
     * Una contraseña que se pueda dictar por teléfono.
     *
     * Sin l, I, 1, O ni 0: en una llamada nadie distingue esos, y la
     * primera experiencia con el sistema no puede ser "no puedo entrar".
     */
    private static function clave($largo = 12)
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $r = '';
        for ($i = 0; $i < $largo; $i++) $r .= $abc[random_int(0, strlen($abc) - 1)];
        return $r;
    }

    /** Llama a la API de cPanel para crear la base y darle permisos. */
    private static function crearBaseEnCpanel(array $cp, $sufijo)
    {
        $llamar = function ($modulo, $funcion, $params) use ($cp) {
            $url = 'https://' . $cp['host'] . ':2083/execute/' . $modulo . '/' . $funcion
                 . '?' . http_build_query($params);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $cp['usuario'] . ':' . $cp['token']],
                CURLOPT_TIMEOUT => 30,
                // El certificado SÍ se verifica. El sistema anterior lo
                // desactivaba, y eso convierte HTTPS en decoración.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $r = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($r === false) return ['ok' => false, 'error' => $err];
            $j = json_decode($r, true);
            return ['ok' => !empty($j['status']), 'error' => $j['errors'][0] ?? 'respuesta inesperada'];
        };

        $r = $llamar('Mysql', 'create_database', ['name' => $cp['usuario'] . '_' . $sufijo]);
        if (!$r['ok']) return $r;
        return $llamar('Mysql', 'set_privileges_on_database', [
            'user'       => $cp['usuario'] . '_' . ($cp['usuario_bd'] ?? $cp['usuario']),
            'database'   => $cp['usuario'] . '_' . $sufijo,
            'privileges' => 'ALL PRIVILEGES',
        ]);
    }
}
