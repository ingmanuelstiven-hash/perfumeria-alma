<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Security\LimiteTasa;
use App\Security\Passwords;
use App\Security\PoliticaPassword;

/**
 * Gestión de usuarios (admin) y cambio de contraseña propia.
 *
 * Invariante: siempre queda al menos un administrador activo. Para que dos admins
 * no se degraden/desactiven mutuamente a la vez, toda operación que pueda quitar un
 * admin bloquea primero la fila del rol 'admin' (FOR UPDATE) y después la del
 * usuario afectado: mismo orden que scripts/crear_admin.php (roles -> usuarios).
 */
final class UsuarioService
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listar(): array
    {
        return Db::pdo()->query(
            'SELECT u.id, u.usuario, u.nombre, u.email, r.codigo AS rol, r.nombre AS rol_nombre, u.activo,
                    u.debe_cambiar_password, u.ultimo_login_en, u.bloqueado_hasta,
                    (u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()) AS bloqueado
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.eliminado_en IS NULL
              ORDER BY u.usuario'
        )->fetchAll();
    }

    /** @return list<array{codigo:string, nombre:string}> */
    public function roles(): array
    {
        return Db::pdo()->query('SELECT codigo, nombre FROM roles ORDER BY id')->fetchAll();
    }

    /**
     * Alta por el admin con contraseña TEMPORAL: debe_cambiar_password = 1.
     * @param array<string,mixed> $actor usuario autenticado
     */
    public function crear(array $actor, string $usuario, string $email, string $nombre, string $rol, #[\SensitiveParameter] string $pass): int
    {
        $usuario = trim($usuario);
        $email = mb_strtolower(trim($email), 'UTF-8');
        $nombre = trim($nombre);
        $errores = [];
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $usuario)) {
            $errores[] = 'Usuario inválido: 3 a 50 caracteres (letras sin tilde, números, punto, guion y guion bajo).';
        }
        if ($email !== '' && (strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errores[] = 'Email inválido.';
        }
        if (!mb_check_encoding($nombre, 'UTF-8') || mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 100
            || preg_match('/[\x00-\x1F\x7F]/', $nombre)) {
            $errores[] = 'Nombre inválido: 2 a 100 caracteres, sin caracteres de control.';
        }
        if (!preg_match('/^[a-z_]{1,30}$/', $rol)) {
            $errores[] = 'Rol inválido.';
        }
        $errores = array_merge($errores, PoliticaPassword::validar($pass, $usuario, $email, $nombre, Passwords::esBcrypt()));
        if ($errores) {
            throw new ErrorNegocio('Revise los datos del usuario.', 422, $errores);
        }
        $hash = Passwords::hash($pass);

        try {
            return Db::transaction(function (\PDO $pdo) use ($actor, $usuario, $email, $nombre, $rol, $hash): int {
                $st = $pdo->prepare('SELECT id FROM roles WHERE codigo = ?');
                $st->execute([$rol]);
                $rolId = $st->fetchColumn();
                if ($rolId === false) {
                    throw new ErrorNegocio('Rol inválido.', 422, ['Rol inválido.']);
                }
                $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE usuario = ? OR (email IS NOT NULL AND email = ?) LIMIT 1');
                $st->execute([$usuario, $email === '' ? null : $email]);
                if ($st->fetchColumn()) {
                    throw new ErrorNegocio('Ya existe un usuario con ese nombre de usuario o email.', 409);
                }
                $pdo->prepare(
                    'INSERT INTO usuarios (sucursal_id, rol_id, usuario, email, nombre, password_hash, activo, debe_cambiar_password)
                     VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
                )->execute([(int) $actor['sucursal_id'], (int) $rolId, $usuario, $email === '' ? null : $email, $nombre, $hash]);
                $id = (int) $pdo->lastInsertId();
                Auditoria::registrar('usuario.crear', (int) $actor['id'], (int) $actor['sucursal_id'], 'usuarios', $id, null,
                    ['usuario' => $usuario, 'email' => $email === '' ? null : $email, 'nombre' => $nombre, 'rol' => $rol]);
                return $id;
            });
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ErrorNegocio('Ya existe un usuario con ese nombre de usuario o email.', 409);
            }
            throw $e;
        }
    }

    public function cambiarRol(array $actor, int $id, string $rolNuevo): void
    {
        if (!preg_match('/^[a-z_]{1,30}$/', $rolNuevo)) {
            throw new ErrorNegocio('Rol inválido.', 422);
        }
        if ($id === (int) $actor['id']) {
            throw new ErrorNegocio('No puede cambiar su propio rol.', 422);
        }
        Db::transaction(function (\PDO $pdo) use ($actor, $id, $rolNuevo): void {
            $adminRolId = $this->bloquearRolAdmin($pdo);
            $obj = $this->bloquearUsuario($pdo, $id);
            $st = $pdo->prepare('SELECT id FROM roles WHERE codigo = ?');
            $st->execute([$rolNuevo]);
            $nuevoId = $st->fetchColumn();
            if ($nuevoId === false) {
                throw new ErrorNegocio('Rol inválido.', 422);
            }
            if ((int) $obj['rol_id'] === (int) $nuevoId) {
                return;
            }
            if ((int) $obj['rol_id'] === $adminRolId && (int) $obj['activo'] === 1) {
                $this->exigirOtroAdmin($pdo, $adminRolId, $id);
            }
            $pdo->prepare('UPDATE usuarios SET rol_id = ? WHERE id = ?')->execute([(int) $nuevoId, $id]);
            AuthService::revocarTodo($pdo, $id, 'admin');
            Auditoria::registrar('usuario.cambiar_rol', (int) $actor['id'], (int) $actor['sucursal_id'], 'usuarios', $id,
                ['rol' => $obj['rol']], ['rol' => $rolNuevo]);
        });
    }

    public function cambiarActivo(array $actor, int $id, bool $activo): void
    {
        if ($id === (int) $actor['id']) {
            throw new ErrorNegocio('No puede desactivarse a sí mismo.', 422);
        }
        Db::transaction(function (\PDO $pdo) use ($actor, $id, $activo): void {
            $adminRolId = $this->bloquearRolAdmin($pdo);
            $obj = $this->bloquearUsuario($pdo, $id);
            if ((int) $obj['activo'] === ($activo ? 1 : 0)) {
                return;
            }
            if (!$activo && (int) $obj['rol_id'] === $adminRolId) {
                $this->exigirOtroAdmin($pdo, $adminRolId, $id);
            }
            $pdo->prepare('UPDATE usuarios SET activo = ? WHERE id = ?')->execute([$activo ? 1 : 0, $id]);
            if (!$activo) {
                AuthService::revocarTodo($pdo, $id, 'desactivado');
            }
            Auditoria::registrar($activo ? 'usuario.activar' : 'usuario.desactivar', (int) $actor['id'],
                (int) $actor['sucursal_id'], 'usuarios', $id, ['activo' => (int) $obj['activo']], ['activo' => $activo ? 1 : 0]);
        });
    }

    /** Desbloqueo manual del login de un usuario (antes de que venza el bloqueo temporal). */
    public function desbloquear(array $actor, int $id): void
    {
        Db::transaction(function (\PDO $pdo) use ($actor, $id): void {
            $obj = $this->bloquearUsuario($pdo, $id);
            LimiteTasa::limpiar(LimiteTasa::clave('login_u', mb_strtolower((string) $obj['usuario'], 'UTF-8')));
            $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')->execute([$id]);
            Auditoria::registrar('usuario.desbloquear', (int) $actor['id'], (int) $actor['sucursal_id'], 'usuarios', $id,
                ['bloqueado_hasta' => $obj['bloqueado_hasta'], 'intentos_fallidos' => (int) $obj['intentos_fallidos']], null);
        });
    }

    /** Desbloqueo manual de una IP (login y refresh). */
    public function desbloquearIp(array $actor, string $ip): void
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ErrorNegocio('IP inválida.', 422);
        }
        $ip = AuthService::normalizarIp($ip);
        LimiteTasa::limpiar(LimiteTasa::clave('login_ip', $ip));
        LimiteTasa::limpiar(LimiteTasa::clave('refresh_ip', $ip));
        Auditoria::registrar('seguridad.desbloquear_ip', (int) $actor['id'], (int) $actor['sucursal_id'], null, null, null, ['ip' => $ip]);
    }

    /**
     * Cambio de contraseña propia: exige la actual (con límite de intentos propio),
     * aplica la política, sube token_version, revoca TODAS las sesiones del usuario y
     * abre una sesión nueva para el dispositivo actual (las demás quedan fuera).
     * @return array sesión nueva (ver AuthService::crearSesion)
     */
    public function cambiarPasswordPropia(
        array $actor,
        #[\SensitiveParameter] string $actual,
        #[\SensitiveParameter] string $nueva,
        #[\SensitiveParameter] string $confirmacion,
        ?string $ipBin,
        ?string $ua,
    ): array {
        $uid = (int) $actor['id'];
        $clave = LimiteTasa::clave('pwd_actual', (string) $uid);
        if (LimiteTasa::bloqueo($clave) > 0) {
            throw new ErrorNegocio('Demasiados intentos. Espere unos minutos y vuelva a intentarlo.', 429);
        }
        if (strlen($actual) > Passwords::MAX_BYTES || strlen($nueva) > Passwords::MAX_BYTES * 2) {
            throw new ErrorNegocio('Datos inválidos.', 422);
        }
        if (LimiteTasa::incrementar($clave, 5, 900, 900) > 5) {
            throw new ErrorNegocio('Demasiados intentos. Espere unos minutos y vuelva a intentarlo.', 429);
        }
        $st = Db::pdo()->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        if (!Passwords::verificar($actual, (string) $st->fetchColumn())) {
            Auditoria::registrar('usuario.password_actual_incorrecta', $uid, (int) $actor['sucursal_id'], 'usuarios', $uid);
            throw new ErrorNegocio('La contraseña actual no es correcta.', 422);
        }
        LimiteTasa::limpiar($clave);

        $errores = [];
        if (!hash_equals($nueva, $confirmacion)) {
            $errores[] = 'La confirmación no coincide con la nueva contraseña.';
        }
        if (hash_equals($actual, $nueva)) {
            $errores[] = 'La nueva contraseña debe ser distinta de la actual.';
        }
        $errores = array_merge($errores, PoliticaPassword::validar(
            $nueva, (string) $actor['usuario'], (string) ($actor['email'] ?? ''), (string) $actor['nombre'], Passwords::esBcrypt()
        ));
        if ($errores) {
            throw new ErrorNegocio('La nueva contraseña no cumple la política.', 422, $errores);
        }
        $hash = Passwords::hash($nueva);

        return Db::transaction(function (\PDO $pdo) use ($actor, $uid, $hash, $ipBin, $ua): array {
            $this->bloquearUsuario($pdo, $uid);
            $pdo->prepare(
                'UPDATE usuarios SET password_hash = ?, password_cambiado_en = NOW(), debe_cambiar_password = 0 WHERE id = ?'
            )->execute([$hash, $uid]);
            AuthService::revocarTodo($pdo, $uid, 'cambio_password');
            $st = $pdo->prepare(
                'SELECT u.id, u.token_version, u.sucursal_id, r.codigo AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?'
            );
            $st->execute([$uid]);
            $sesion = $this->auth->crearSesion($pdo, $st->fetch(), $ipBin, $ua);
            Auditoria::registrar('usuario.cambiar_password', $uid, (int) $actor['sucursal_id'], 'usuarios', $uid, null,
                ['sesiones_revocadas' => true]);
            return $sesion;
        });
    }

    private function bloquearRolAdmin(\PDO $pdo): int
    {
        $st = $pdo->prepare("SELECT id FROM roles WHERE codigo = 'admin' FOR UPDATE");
        $st->execute();
        $id = $st->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('No existe el rol admin');
        }
        return (int) $id;
    }

    /** @return array<string,mixed> */
    private function bloquearUsuario(\PDO $pdo, int $id): array
    {
        // Solo se bloquea la fila de usuarios: un JOIN con FOR UPDATE bloquearía también
        // la fila de roles y rompería el orden roles -> usuarios (riesgo de interbloqueo).
        $st = $pdo->prepare(
            'SELECT id, usuario, rol_id, activo, bloqueado_hasta, intentos_fallidos
               FROM usuarios WHERE id = ? AND eliminado_en IS NULL FOR UPDATE'
        );
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) {
            throw new ErrorNegocio('Usuario no encontrado.', 404);
        }
        $st = $pdo->prepare('SELECT codigo FROM roles WHERE id = ?');
        $st->execute([(int) $u['rol_id']]);
        $u['rol'] = (string) $st->fetchColumn();
        return $u;
    }

    private function exigirOtroAdmin(\PDO $pdo, int $adminRolId, int $excluir): void
    {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM usuarios WHERE rol_id = ? AND activo = 1 AND eliminado_en IS NULL AND id <> ?'
        );
        $st->execute([$adminRolId, $excluir]);
        if ((int) $st->fetchColumn() < 1) {
            throw new ErrorNegocio('Debe quedar al menos un administrador activo.', 422);
        }
    }
}
