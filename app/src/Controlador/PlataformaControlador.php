<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\AutenticacionRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

/**
 * Cuentas de plataforma y suspensión de empresas · solo superadmin.
 *
 * SOBRE BORRAR: NO SE BORRA NADA, Y NO ES PEREZA
 *
 * Ni siquiera el superadministrador puede eliminar una empresa, un
 * usuario o una venta. Las razones, en orden:
 *
 *  1. Las ventas y las facturas hay que conservarlas cinco años. Borrar
 *     la base de una empresa destruye la única copia de eso.
 *  2. La bitácora existe para contestar "¿quién hizo esto?". Si se puede
 *     borrar, deja de contestarlo justo cuando alguien tiene motivo para
 *     querer que no conteste.
 *  3. Un botón de borrar es irreversible con un solo clic, y el error
 *     que evita —ver un renglón de más— no se parece en tamaño al que
 *     provoca.
 *
 * Lo que sí hay es SUSPENDER: la empresa no entra, sus datos quedan
 * enteros, y se puede deshacer. Eso resuelve todos los casos reales
 * —dejó de pagar, se fue, hay una disputa— sin destruir nada.
 *
 * Si alguna vez hay que borrar de verdad, por una ley de protección de
 * datos, eso es un procedimiento con respaldo previo y dos personas, no
 * un botón.
 */
final class PlataformaControlador
{
    public function index()
    {
        $principal = $this->principal();
        $repo = new AutenticacionRepo($principal);
        $plat = new \LibertyFin\Datos\PlataformaRepo($principal);

        Plantilla::pagina('plataforma/index', [
            'titulo'    => 'Cuentas de plataforma',
            'icono'     => 'cliente',
            'subtitulo' => 'Superadministración',
            'usuarios'  => $repo->listaPlataforma(),
            'empresas'  => $plat->empresas(),
            'roles'     => array_filter(Permisos::rolesQuePuedeAsignar(),
                              function ($r) { return ($r['nivel'] ?? '') === 'plataforma'; }),
            'editando'  => \LibertyFin\Http\Peticion::entero('editar'),
            'global'    => (new \LibertyFin\Datos\AjustesPlataformaRepo($principal))->estado(),
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardarUsuario()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        $repo = new AutenticacionRepo($principal);
        $repo->asegurarPlataforma();

        $id     = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $user   = strtolower(trim($_POST['username'] ?? ''));
        $email  = trim($_POST['email'] ?? '');
        $rol    = $_POST['rol'] ?? '';

        if (mb_strlen($nombre) < 3)  $this->a('Escribe el nombre completo.', 'error');
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $user)) {
            $this->a('El usuario son de 3 a 30 letras, números, punto, guion o guion bajo.', 'error');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->a('El correo no es válido.', 'error');
        }
        // Solo roles de plataforma, y solo los que este rol puede dar.
        if (!Permisos::puedeAsignar($rol) || !Permisos::esPlataforma($rol)) {
            $this->a('Ese rol no se puede crear aquí.', 'error');
        }

        try {
            $st = $principal->prepare(
                "SELECT id FROM usuarios_plataforma WHERE username = ?" . ($id ? " AND id <> ?" : ""));
            $st->execute($id ? [$user, $id] : [$user]);
            if ($st->fetch()) $this->a('Ya existe una cuenta con ese usuario.', 'error');

            if ($id) {
                $principal->prepare("
                    UPDATE usuarios_plataforma SET username=?, nombre=?, email=?, rol=? WHERE id=?
                ")->execute([$user, $nombre, $email ?: null, $rol, $id]);
                Auditoria::anota('usuario.editar', 'plataforma · ' . $nombre, null, $rol, $principal);
                $this->a('Cuenta actualizada.', 'ok');
            }

            $clave = self::clave();
            $principal->prepare("
                INSERT INTO usuarios_plataforma (username, password, nombre, email, rol, activo, creado_en)
                VALUES (?,?,?,?,?,1,NOW())
            ")->execute([$user, password_hash($clave, PASSWORD_DEFAULT), $nombre, $email ?: null, $rol]);
            Auditoria::anota('usuario.crear', 'plataforma · ' . $nombre, null, $rol, $principal);

            $this->a('Cuenta creada. Usuario ' . $user . ' · contraseña ' . $clave
                . ' — anótala, no se vuelve a mostrar.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] usuario plataforma: ' . $e->getMessage());
            $this->a('No se pudo guardar: ' . $e->getMessage(), 'error');
        }
    }

    /** Activa o desactiva una cuenta. Nunca se borra. */
    public function alternarUsuario()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        $id = (int)($_POST['id'] ?? 0);

        if ($id === (int)($_SESSION['usuario_id'] ?? 0)) {
            $this->a('No puedes desactivarte a ti mismo.', 'error');
        }
        try {
            // Y no puede quedar la plataforma sin un superadministrador
            // activo: sería un sistema al que nadie puede entrar a
            // arreglarlo, y arreglarlo requeriría entrar.
            $st = $principal->prepare("SELECT rol, activo FROM usuarios_plataforma WHERE id = ?");
            $st->execute([$id]);
            $u = $st->fetch();
            if (!$u) $this->a('Esa cuenta no existe.', 'error');

            if ($u['rol'] === 'superadmin' && $u['activo']) {
                $n = (int)$principal->query("
                    SELECT COUNT(*) FROM usuarios_plataforma
                    WHERE rol = 'superadmin' AND activo = 1")->fetchColumn();
                if ($n <= 1) {
                    $this->a('Es el único superadministrador activo. '
                           . 'Crea otro antes de desactivar este.', 'error');
                }
            }
            $principal->prepare("UPDATE usuarios_plataforma SET activo = 1 - activo WHERE id = ?")
                      ->execute([$id]);
            Auditoria::anota('usuario.alternar', 'plataforma #' . $id,
                $u['activo'] ? 'activo' : 'inactivo', $u['activo'] ? 'inactivo' : 'activo', $principal);
            $this->a('Cuenta ' . ($u['activo'] ? 'desactivada' : 'activada') . '.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar plataforma: ' . $e->getMessage());
            $this->a('No se pudo cambiar.', 'error');
        }
    }

    public function claveUsuario()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        try {
            $clave = self::clave();
            $principal->prepare("UPDATE usuarios_plataforma SET password = ? WHERE id = ?")
                      ->execute([password_hash($clave, PASSWORD_DEFAULT), (int)($_POST['id'] ?? 0)]);
            Auditoria::anota('usuario.clave', 'plataforma #' . (int)($_POST['id'] ?? 0),
                null, null, $principal);
            $this->a('Contraseña nueva: ' . $clave . ' — anótala, no se vuelve a mostrar.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] clave plataforma: ' . $e->getMessage());
            $this->a('No se pudo restablecer.', 'error');
        }
    }

    /**
     * Suspende o reactiva una empresa.
     *
     * Suspender NO borra: la base queda entera, sus ventas y su bitácora
     * siguen ahí, y se puede deshacer con otro clic. Lo único que cambia
     * es que su gente no entra.
     */
    public function suspenderEmpresa()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        try {
            $id = (int)($_POST['id'] ?? 0);
            $st = $principal->prepare("SELECT nombre_empresa, activo FROM empresas WHERE id = ?");
            $st->execute([$id]);
            $e = $st->fetch();
            if (!$e) $this->a('Esa empresa no existe.', 'error');

            $principal->prepare("UPDATE empresas SET activo = 1 - activo WHERE id = ?")->execute([$id]);
            Auditoria::anota('empresa.datos', $e['nombre_empresa'],
                $e['activo'] ? 'activa' : 'suspendida',
                $e['activo'] ? 'suspendida' : 'activa', $principal);
            $this->a($e['nombre_empresa'] . ' quedó '
                . ($e['activo'] ? 'suspendida. Sus datos siguen enteros.' : 'activa otra vez.'), 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] suspender: ' . $e->getMessage());
            $this->a('No se pudo cambiar.', 'error');
        }
    }

    /** Sin l, I, 1, O ni 0: estas claves se dictan por teléfono. */
    private static function clave($largo = 14)
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $r = '';
        for ($i = 0; $i < $largo; $i++) $r .= $abc[random_int(0, strlen($abc) - 1)];
        return $r;
    }

    private function principal() { return Conexion::de($GLOBALS['lf_bd_principal'] ?? ''); }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function a($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /plataforma'); exit;
    }

    /**
     * Apaga o enciende algo para TODAS las empresas.
     *
     * Apagar exige un motivo porque lo van a ver los afectados. Sin él,
     * lo único que sabrían es que una función desapareció.
     */
    public function alternarGlobal()
    {
        if (!$this->token()) $this->a('No se pudo verificar el formulario.', 'error');
        $principal = $this->principal();
        $clave = $_POST['clave'] ?? '';

        $validas = [];
        foreach (\LibertyFin\Datos\AjustesPlataformaRepo::SECCIONES as $k => $_) $validas[] = 'seccion.' . $k;
        foreach (\LibertyFin\Datos\AjustesPlataformaRepo::METODOS as $k => $_)   $validas[] = 'metodo.' . $k;
        if (!in_array($clave, $validas, true)) $this->a('Eso no se puede apagar.', 'error');

        try {
            $ahora = (new \LibertyFin\Datos\AjustesPlataformaRepo($principal))->alternar(
                $clave, $_POST['nota'] ?? '', $_SESSION['usuario_nombre'] ?? '');
            // La sesión de quien lo cambió se actualiza de inmediato; las
            // demás lo toman al volver a entrar.
            $_SESSION['lf_global'][$clave] = $ahora;
            Auditoria::anota('seccion.alternar', 'global · ' . $clave,
                $ahora ? 'apagado' : 'encendido', $ahora ? 'encendido' : 'apagado', $principal);
            $this->a($ahora
                ? 'Encendido para todas las empresas.'
                : 'Apagado para todas. Las que lo tenían encendido dejan de verlo al recargar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->a($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] global: ' . $e->getMessage());
            $this->a('No se pudo cambiar.', 'error');
        }
    }
}
