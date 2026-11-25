<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Db;
use App\Security\LimiteTasa;
use App\Security\Passwords;
use App\Security\TokenAcceso;

/**
 * Login, refresh rotativo, logout y revocación. Sin dependencias de HTTP: los
 * controladores ponen/borran cookies con lo que devuelve este servicio (y las
 * pruebas de concurrencia lo llaman directamente desde varios procesos).
 *
 * Tiempos (docs/SEGURIDAD_AUTH.md §1):
 *   access 10 min; refresh: inactividad 2 h por eslabón (expira_en), tope absoluto
 *   12 h por familia (sesion_expira_en, no se extiende al rotar); gracia 10 s.
 */
final class AuthService
{
    public const INACTIVIDAD_SEG = 7200;
    public const SESION_MAX_SEG = 43200;
    public const GRACIA_SEG = 10;
    public const VENTANA_LOGIN_SEG = 900;
    public const MENSAJE_CREDENCIALES = 'Usuario o contraseña incorrectos.';

    public function __construct(private readonly Config $config, private readonly TokenAcceso $tokens)
    {
    }

    // ------------------------------------------------------------------ login

    /**
     * @return array{estado:'ok'|'credenciales'|'bloqueado', sesion?:array, usuario?:array, reintentar?:int}
     */
    public function login(string $usuarioTexto, #[\SensitiveParameter] string $pass, string $ipTexto, string $ipBin, ?string $ua): array
    {
        $texto = mb_substr(mb_strtolower(trim($usuarioTexto), 'UTF-8'), 0, 50, 'UTF-8');
        $ipNorm = self::normalizarIp($ipTexto);
        $maxU = $this->paramInt('login_max_intentos', 5, 3, 20);
        $bloqueoSeg = 60 * $this->paramInt('login_bloqueo_minutos', 15, 1, 1440);
        $maxIp = $this->config->loginMaxIntentosIp;
        $ku = LimiteTasa::clave('login_u', $texto);
        $kip = LimiteTasa::clave('login_ip', $ipNorm);

        // 1) Bloqueos vigentes: se responde sin calcular ningún hash (el bloqueo por
        //    usuario va por el TEXTO tecleado, así que existe o no se comporta igual).
        $bu = LimiteTasa::bloqueo($ku);
        $bip = LimiteTasa::bloqueo($kip);
        if ($bu > 0 || $bip > 0) {
            $this->intento($texto, null, $ipBin, false, $bip > 0 ? 'rate_limit' : 'bloqueado', $ua);
            return ['estado' => 'bloqueado', 'reintentar' => max($bu, $bip)];
        }

        // 2) Reservar el intento ANTES de verificar (corta ráfagas en paralelo).
        $cu = LimiteTasa::incrementar($ku, $maxU, self::VENTANA_LOGIN_SEG, $bloqueoSeg);
        $cip = LimiteTasa::incrementar($kip, $maxIp, self::VENTANA_LOGIN_SEG, $bloqueoSeg);
        if ($cu > $maxU || $cip > $maxIp) {
            $this->intento($texto, null, $ipBin, false, $cip > $maxIp ? 'rate_limit' : 'bloqueado', $ua);
            return ['estado' => 'bloqueado', 'reintentar' => max(LimiteTasa::bloqueo($ku), LimiteTasa::bloqueo($kip), 1)];
        }

        // 3) Buscar y verificar SIEMPRE con un hash (real o ficticio): mismo coste.
        $u = null;
        if ($texto !== '' && strlen($pass) <= Passwords::MAX_BYTES) {
            $st = Db::pdo()->prepare(
                'SELECT u.id, u.usuario, u.nombre, u.email, u.password_hash, u.activo, u.eliminado_en,
                        u.token_version, u.sucursal_id, u.rol_id, u.debe_cambiar_password, r.codigo AS rol
                   FROM usuarios u JOIN roles r ON r.id = u.rol_id
                  WHERE u.usuario = ? LIMIT 1'
            );
            $st->execute([$texto]);
            $u = $st->fetch() ?: null;
        }
        if ($u !== null) {
            $passOk = Passwords::verificar($pass, (string) $u['password_hash']);
        } else {
            Passwords::verificarFicticio(substr($pass, 0, Passwords::MAX_BYTES));
            $passOk = false;
        }
        $habilitado = $u !== null && (int) $u['activo'] === 1 && $u['eliminado_en'] === null;

        if ($passOk && $habilitado) {
            $sesion = Db::transaction(function (\PDO $pdo) use ($u, $pass, $ipBin, $ua, $texto): array {
                $nuevoHash = Passwords::necesitaRehash((string) $u['password_hash']) ? Passwords::hash($pass) : null;
                $pdo->prepare(
                    'UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_login_en = NOW(),
                            ultimo_login_ip = ?, password_hash = COALESCE(?, password_hash) WHERE id = ?'
                )->execute([$ipBin, $nuevoHash, (int) $u['id']]);
                $sesion = $this->crearSesion($pdo, $u, $ipBin, $ua);
                $this->intento($texto, (int) $u['id'], $ipBin, true, 'ok', $ua);
                Auditoria::registrar('usuario.login', (int) $u['id'], (int) $u['sucursal_id'], 'usuarios', (int) $u['id'],
                    null, ['rehash' => $nuevoHash !== null]);
                return $sesion;
            });
            LimiteTasa::limpiar($ku);
            LimiteTasa::devolver($kip, $maxIp);
            return ['estado' => 'ok', 'sesion' => $sesion, 'usuario' => self::publico($u)];
        }

        // 4) Fallo: mismo resultado para usuario inexistente, clave mala o inactivo.
        $motivo = $u === null ? 'usuario_inexistente' : (!$passOk ? 'password' : 'inactivo');
        $uid = $u === null ? null : (int) $u['id'];
        $suc = $u === null ? null : (int) $u['sucursal_id'];
        if ($uid !== null) {
            Db::pdo()->prepare(
                'UPDATE usuarios SET intentos_fallidos = ?,
                        bloqueado_hasta = IF(? >= ?, NOW() + INTERVAL ? SECOND, bloqueado_hasta) WHERE id = ?'
            )->execute([min($cu, 65535), $cu, $maxU, $bloqueoSeg, $uid]);
        }
        $this->intento($texto, $uid, $ipBin, false, $motivo, $ua);
        Auditoria::registrar('usuario.login_fallido', $uid, $suc, 'usuarios', $uid, null, ['motivo' => $motivo]);
        if ($cu === $maxU) {
            Auditoria::registrar('usuario.bloqueo', $uid, $suc, 'usuarios', $uid, null,
                ['tipo' => 'usuario', 'intentos' => $cu, 'segundos' => $bloqueoSeg]);
        }
        if ($cip === $maxIp) {
            Auditoria::registrar('seguridad.bloqueo_ip', null, null, null, null, null,
                ['ip' => $ipNorm, 'intentos' => $cip, 'segundos' => $bloqueoSeg]);
        }
        return ['estado' => 'credenciales'];
    }

    // ---------------------------------------------------------------- sesión

    /**
     * Crea una familia nueva (sid) con su primer refresh y un access token.
     * @return array{access:string, access_exp:int, refresh:string, sid:string, sesion_expira:int, usuario_id:int, jti:string}
     */
    public function crearSesion(\PDO $pdo, array $u, ?string $ipBin, ?string $ua): array
    {
        $ahora = time();
        $familia = random_bytes(16);
        $plano = self::nuevoTokenPlano();
        $sesionExpira = $ahora + self::SESION_MAX_SEG;
        $pdo->prepare(
            'INSERT INTO refresh_tokens (usuario_id, familia_id, token_hash, creado_en, expira_en, sesion_expira_en, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $u['id'], $familia, self::hashToken($plano),
            self::fecha($ahora), self::fecha($ahora + self::INACTIVIDAD_SEG), self::fecha($sesionExpira),
            $ipBin, $ua,
        ]);
        $at = $this->tokens->emitir((int) $u['id'], (string) $u['rol'], bin2hex($familia), (int) $u['token_version']);
        return [
            'access' => $at['jwt'], 'access_exp' => $at['exp'], 'jti' => $at['jti'],
            'refresh' => $plano, 'sid' => bin2hex($familia), 'sesion_expira' => $sesionExpira,
            'usuario_id' => (int) $u['id'],
        ];
    }

    /** Familia (hex) a la que pertenece un refresh, sin bloquear. Para validar el CSRF antes de rotar. */
    public function familiaDe(string $plano): ?string
    {
        if (!self::formatoTokenValido($plano)) {
            return null;
        }
        $st = Db::pdo()->prepare('SELECT familia_id FROM refresh_tokens WHERE token_hash = ?');
        $st->execute([self::hashToken($plano)]);
        $f = $st->fetchColumn();
        return $f === false ? null : bin2hex((string) $f);
    }

    /**
     * Rotación del refresh. Todo en una transacción con FOR UPDATE sobre la fila del
     * token: dos pestañas que llegan a la vez se serializan; la segunda ve el token
     * ya usado (usado_en) y, si fue hace <= GRACIA_SEG, recibe 'gracia' (409) sin
     * revocar nada. Fuera de la gracia es reutilización: se revoca la familia.
     *
     * Orden de bloqueo: solo la fila de refresh_tokens; usuarios se lee sin bloqueo
     * (evita interbloqueos con las operaciones de admin, que bloquean roles->usuarios).
     *
     * @return array{estado:'ok'|'gracia'|'reutilizado'|'expirado'|'invalido', sesion?:array, usuario_id?:int}
     */
    public function refrescar(string $plano, ?string $ipBin, ?string $ua): array
    {
        if (!self::formatoTokenValido($plano)) {
            return ['estado' => 'invalido'];
        }
        return Db::transaction(function (\PDO $pdo) use ($plano, $ipBin, $ua): array {
            $st = $pdo->prepare(
                'SELECT id, usuario_id, familia_id, expira_en, sesion_expira_en, usado_en, revocado_en
                   FROM refresh_tokens WHERE token_hash = ? FOR UPDATE'
            );
            $st->execute([self::hashToken($plano)]);
            $rt = $st->fetch();
            if (!$rt || $rt['revocado_en'] !== null) {
                return ['estado' => 'invalido'];
            }
            $ahora = time();
            $uid = (int) $rt['usuario_id'];

            if ($rt['usado_en'] !== null) {
                $hace = $ahora - (int) strtotime((string) $rt['usado_en']);
                if ($hace <= self::GRACIA_SEG) {
                    return ['estado' => 'gracia', 'usuario_id' => $uid];
                }
                $n = $this->revocarFamilia($pdo, (string) $rt['familia_id'], 'reutilizacion');
                Auditoria::registrar('sesion.refresh_reutilizado', $uid, null, 'refresh_tokens', (int) $rt['id'], null,
                    ['familia' => bin2hex((string) $rt['familia_id']), 'tokens_revocados' => $n, 'segundos_desde_uso' => $hace]);
                return ['estado' => 'reutilizado', 'usuario_id' => $uid];
            }

            $sesionExpira = (int) strtotime((string) $rt['sesion_expira_en']);
            if ((int) strtotime((string) $rt['expira_en']) <= $ahora || $sesionExpira <= $ahora + 5) {
                $this->revocarFamilia($pdo, (string) $rt['familia_id'], 'expirado');
                return ['estado' => 'expirado', 'usuario_id' => $uid];
            }

            $st = $pdo->prepare(
                'SELECT u.id, u.activo, u.eliminado_en, u.token_version, u.sucursal_id, r.codigo AS rol
                   FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?'
            );
            $st->execute([$uid]);
            $u = $st->fetch();
            if (!$u || (int) $u['activo'] !== 1 || $u['eliminado_en'] !== null) {
                $this->revocarFamilia($pdo, (string) $rt['familia_id'], 'desactivado');
                return ['estado' => 'invalido', 'usuario_id' => $uid];
            }

            $nuevo = self::nuevoTokenPlano();
            $expira = min($ahora + self::INACTIVIDAD_SEG, $sesionExpira);
            $pdo->prepare(
                'INSERT INTO refresh_tokens (usuario_id, familia_id, token_hash, creado_en, expira_en, sesion_expira_en, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $uid, $rt['familia_id'], self::hashToken($nuevo),
                self::fecha($ahora), self::fecha($expira), (string) $rt['sesion_expira_en'], $ipBin, $ua,
            ]);
            $nuevoId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE refresh_tokens SET usado_en = ?, reemplazado_por = ? WHERE id = ?')
                ->execute([self::fecha($ahora), $nuevoId, (int) $rt['id']]);

            $sid = bin2hex((string) $rt['familia_id']);
            $at = $this->tokens->emitir($uid, (string) $u['rol'], $sid, (int) $u['token_version']);
            return ['estado' => 'ok', 'usuario_id' => $uid, 'sesion' => [
                'access' => $at['jwt'], 'access_exp' => $at['exp'], 'jti' => $at['jti'],
                'refresh' => $nuevo, 'sid' => $sid, 'sesion_expira' => $sesionExpira, 'usuario_id' => $uid,
            ]];
        }, 2);
    }

    /** Cierra la sesión (familia) del token y revoca su jti hasta su exp. */
    public function logout(object $claims): void
    {
        $uid = (int) $claims->sub;
        $familia = hex2bin((string) $claims->sid);
        Db::transaction(function (\PDO $pdo) use ($claims, $uid, $familia): void {
            $n = $this->revocarFamilia($pdo, (string) $familia, 'logout', $uid);
            if ((int) $claims->exp + TokenAcceso::MARGEN_RELOJ_SEG > time()) {
                $pdo->prepare(
                    'INSERT IGNORE INTO jwt_revocados (jti, usuario_id, expira_en, motivo) VALUES (?, ?, ?, ?)'
                )->execute([hex2bin((string) $claims->jti), $uid, self::fecha((int) $claims->exp + TokenAcceso::MARGEN_RELOJ_SEG), 'logout']);
            }
            Auditoria::registrar('sesion.logout', $uid, null, 'usuarios', $uid, null, ['tokens_revocados' => $n]);
        });
    }

    /**
     * Revocación global de un usuario: token_version++ (invalida todos los access
     * tokens vivos en la siguiente petición) y revoca todas sus familias de refresh.
     * Debe llamarse dentro de una transacción.
     */
    public static function revocarTodo(\PDO $pdo, int $uid, string $motivo): void
    {
        $pdo->prepare('UPDATE usuarios SET token_version = token_version + 1 WHERE id = ?')->execute([$uid]);
        $pdo->prepare(
            'UPDATE refresh_tokens SET revocado_en = NOW(), motivo_revocacion = ? WHERE usuario_id = ? AND revocado_en IS NULL'
        )->execute([$motivo, $uid]);
    }

    private function revocarFamilia(\PDO $pdo, string $familiaBin, string $motivo, ?int $uid = null): int
    {
        $sql = 'UPDATE refresh_tokens SET revocado_en = NOW(), motivo_revocacion = ? WHERE familia_id = ? AND revocado_en IS NULL';
        $args = [$motivo, $familiaBin];
        if ($uid !== null) {
            $sql .= ' AND usuario_id = ?';
            $args[] = $uid;
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    /**
     * Comprobación en BD de cada petición autenticada (una consulta por PK):
     * usuario activo, token_version, rol, jti no revocado y familia viva.
     * @return array<string,mixed>|null
     */
    public static function verificarSesion(object $claims): ?array
    {
        $st = Db::pdo()->prepare(
            'SELECT u.id, u.usuario, u.nombre, u.email, u.activo, u.eliminado_en, u.token_version, u.rol_id,
                    r.codigo AS rol, r.nombre AS rol_nombre, u.sucursal_id, s.nombre AS sucursal, u.debe_cambiar_password,
                    EXISTS(SELECT 1 FROM jwt_revocados j WHERE j.jti = ?) AS jti_revocado,
                    EXISTS(SELECT 1 FROM refresh_tokens t WHERE t.familia_id = ? AND t.usuario_id = u.id
                              AND t.revocado_en IS NULL AND t.sesion_expira_en > NOW()) AS sesion_viva
               FROM usuarios u
               JOIN roles r ON r.id = u.rol_id
               JOIN sucursales s ON s.id = u.sucursal_id
              WHERE u.id = ?'
        );
        $st->execute([hex2bin((string) $claims->jti), hex2bin((string) $claims->sid), (int) $claims->sub]);
        $u = $st->fetch();
        if (!$u
            || (int) $u['activo'] !== 1
            || $u['eliminado_en'] !== null
            || (int) $u['token_version'] !== (int) $claims->tv
            || !hash_equals((string) $u['rol'], (string) $claims->rol)
            || (int) $u['jti_revocado'] === 1
            || (int) $u['sesion_viva'] !== 1) {
            return null;
        }
        unset($u['jti_revocado'], $u['sesion_viva'], $u['eliminado_en']);
        return $u;
    }

    // -------------------------------------------------------------- utilidades

    public static function nuevoTokenPlano(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function formatoTokenValido(string $plano): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $plano) === 1;
    }

    public static function hashToken(string $plano): string
    {
        return hash('sha256', $plano, true);
    }

    public static function normalizarIp(string $ip): string
    {
        $b = @inet_pton($ip);
        return $b === false ? $ip : (string) inet_ntop($b);
    }

    private static function fecha(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }

    private function intento(string $texto, ?int $uid, string $ipBin, bool $exito, string $motivo, ?string $ua): void
    {
        Db::pdo()->prepare(
            'INSERT INTO login_intentos (usuario_texto, usuario_id, ip, exito, motivo, user_agent) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$texto, $uid, $ipBin, $exito ? 1 : 0, $motivo, $ua]);
    }

    /** @var array<string,int> */
    private array $params = [];

    private function paramInt(string $clave, int $def, int $min, int $max): int
    {
        if (!isset($this->params[$clave])) {
            $st = Db::pdo()->prepare('SELECT valor FROM config WHERE sucursal_id IS NULL AND clave = ?');
            $st->execute([$clave]);
            $v = $st->fetchColumn();
            $n = ($v !== false && ctype_digit((string) $v)) ? (int) $v : $def;
            $this->params[$clave] = ($n < $min || $n > $max) ? $def : $n;
        }
        return $this->params[$clave];
    }

    /** @return array{id:int, usuario:string, nombre:string, rol:string, debe_cambiar_password:bool} */
    public static function publico(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'usuario' => (string) $u['usuario'],
            'nombre' => (string) $u['nombre'],
            'rol' => (string) $u['rol'],
            'debe_cambiar_password' => (int) $u['debe_cambiar_password'] === 1,
        ];
    }
}
