<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\AutenticacionRepo;
use LibertyFin\Servicio\Migraciones;
use PDO;

/**
 * Ingreso al sistema.
 *
 * Decisiones, y por qué:
 *
 * · El mensaje de error es el MISMO si el usuario no existe o si la
 *   contraseña está mal. Distinguirlos le regala a quien prueba una lista
 *   de usuarios válidos.
 *
 * · Se espera un tiempo mínimo aunque el usuario no exista. Sin eso, la
 *   diferencia de milisegundos entre "no existe" y "existe pero clave
 *   mala" delata cuáles son reales.
 *
 * · La sesión se regenera al entrar. Si no, quien logre fijar un id de
 *   sesión antes del ingreso se queda dentro después.
 *
 * · Cinco intentos y quince minutos de espera, por sesión.
 */
final class Autenticar
{
    const INTENTOS_MAX = 5;
    const ESPERA       = 900;   // 15 minutos
    const VIDA_SESION  = 28800; // 8 horas

    private $repo;
    public function __construct(PDO $principal) { $this->repo = new AutenticacionRepo($principal); }

    /** Segundos que faltan de castigo, o 0 si puede intentar. */
    public static function bloqueoRestante()
    {
        if (empty($_SESSION['lf_intentos']) || $_SESSION['lf_intentos'] < self::INTENTOS_MAX) return 0;
        $pasado = time() - ($_SESSION['lf_ultimo_intento'] ?? 0);
        if ($pasado >= self::ESPERA) {
            $_SESSION['lf_intentos'] = 0;
            return 0;
        }
        return self::ESPERA - $pasado;
    }

    /** @throws \RuntimeException con un mensaje apto para mostrar */
    public function entrar($identificador, $clave)
    {
        $espera = self::bloqueoRestante();
        if ($espera > 0) {
            throw new \RuntimeException(
                'Demasiados intentos. Vuelve a probar en ' . ceil($espera / 60) . ' minutos.');
        }

        $inicio = microtime(true);
        $hallazgo = $this->repo->buscar($identificador);
        $ok = $hallazgo && password_verify((string)$clave, (string)$hallazgo['usuario']['password']);

        // Mismo tiempo para todos los casos.
        $gastado = microtime(true) - $inicio;
        if ($gastado < 0.35) usleep((int)((0.35 - $gastado) * 1000000));

        if (!$ok) {
            $_SESSION['lf_intentos'] = ($_SESSION['lf_intentos'] ?? 0) + 1;
            $_SESSION['lf_ultimo_intento'] = time();
            $faltan = self::INTENTOS_MAX - $_SESSION['lf_intentos'];
            throw new \RuntimeException('Usuario o contraseña incorrectos.'
                . ($faltan > 0 && $faltan <= 2 ? ' Quedan ' . $faltan . ' intentos.' : ''));
        }

        $empresa = $hallazgo['empresa'];
        $usuario = $hallazgo['usuario'];

        if (!$empresa['activo']) {
            throw new \RuntimeException('La cuenta de ' . $empresa['nombre_empresa'] . ' está inactiva.');
        }
        if (!empty($empresa['fecha_vencimiento'])
            && strtotime($empresa['fecha_vencimiento']) < strtotime('today')) {
            throw new \RuntimeException('La suscripción de ' . $empresa['nombre_empresa']
                . ' venció el ' . date('d/m/Y', strtotime($empresa['fecha_vencimiento'])) . '.');
        }

        $sucursal = $this->repo->sucursal($empresa['nombre_base_datos'], $usuario['sucursal_id']);

        // Id nuevo: lo anterior de esta sesión deja de servir.
        session_regenerate_id(true);
        $_SESSION = [
            'logged_in'        => true,
            'login_time'       => time(),
            'user_agent'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? '',
            'empresa_id'       => (int)$empresa['id'],
            'empresa_db'       => $empresa['nombre_base_datos'],
            'empresa_nombre'   => $empresa['nombre_empresa'],
            'empresa_plan'     => $empresa['plan'] ?? 'prueba',
            'usuario_id'       => (int)$usuario['id'],
            'usuario_nombre'   => $usuario['nombre'] ?: $usuario['username'],
            'usuario_rol'      => $usuario['rol'],
            'sucursal_id'      => $usuario['sucursal_id'] ? (int)$usuario['sucursal_id'] : null,
            'sucursal_nombre'  => $sucursal['nombre'] ?? 'Matriz',
        ];

        // La personalización se lee una vez al entrar y vive en la sesión:
        // el armazón la pinta en cada página y consultarla cada vez sería
        // una consulta más por petición para un dato que casi nunca cambia.
        try {
            $db = \LibertyFin\Datos\Conexion::de($empresa['nombre_base_datos']);

            // La base se pone al día sola al entrar.
            //
            // LibertyFin crea una base por empresa, y el esquema vive como
            // CREATE TABLE dentro de registroEmpresa.php. Sin esto, una
            // empresa creada hoy nace sin las columnas que agregó la última
            // versión, y falla con un error que ninguna otra empresa tiene.
            //
            // En una base al día cuesta una consulta.
            if (!Migraciones::alDia($db)) Migraciones::aplicar($db);

            $cfg = new \LibertyFin\Datos\ConfigRepo($db);
            $color = (string)$cfg->valorDe('marca.color', '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $_SESSION['lf_marca_color'] = $color;
            $logo = (string)$cfg->valorDe('marca.logo', '');
            if ($logo) $_SESSION['lf_marca_logo'] = $logo;
            $ur = new \LibertyFin\Datos\UsuarioRepo($db);
            $foto = $ur->foto($usuario['id']);
            if ($foto) $_SESSION['lf_foto'] = $foto;
            // La guía se muestra una sola vez, en el primer ingreso.
            if (!$ur->vioGuia($usuario['id'])) $_SESSION['lf_mostrar_guia'] = true;
        } catch (\Throwable $e) { /* sin personalización se ve el tema base */ }

        return $_SESSION;
    }

    /**
     * ¿La sesión sigue siendo válida?
     * El navegador tiene que ser el mismo. La IP NO se exige: en México
     * cambia sola al saltar de wifi a datos, y echar a la gente por eso
     * genera más llamadas a soporte que ataques evitados. Se anota y ya.
     */
    public static function sesionValida()
    {
        if (empty($_SESSION['logged_in']) || empty($_SESSION['empresa_db'])) return false;
        if (time() - ($_SESSION['login_time'] ?? 0) > self::VIDA_SESION) return false;
        if (($_SESSION['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) return false;
        if (($_SESSION['ip_address'] ?? '') !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            error_log('[LibertyFin] la IP cambió para el usuario ' . ($_SESSION['usuario_id'] ?? '?'));
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        return true;
    }

    public static function salir()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
