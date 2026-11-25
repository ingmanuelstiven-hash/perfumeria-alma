<?php

declare(strict_types=1);

namespace App\Security;

use App\Config;
use App\Http\Request;

/**
 * CSRF: doble envío firmado + comprobación de origen.
 *
 * token = base64url(r) "." base64url(HMAC-SHA256(CSRF_SECRET, "csrf|" sid "|" r)),
 * r = 16 bytes aleatorios; sid = familia de sesión (32 hex) o "anon" antes del login.
 *
 * Validación (métodos no seguros): el token enviado (cabecera X-CSRF-Token o campo
 * _csrf) debe ser idéntico al de la cookie (hash_equals) Y su HMAC debe ser válido
 * para el sid de la sesión actual. Una cookie plantada no sirve sin el secreto, y un
 * token de otra sesión no vale para esta.
 *
 * Se ata al sid (familia) y NO al jti: el jti cambia cada 10 min con el refresh y
 * romper los formularios abiertos en otras pestañas no aporta seguridad.
 */
final class Csrf
{
    public const ANON = 'anon';

    public function __construct(private readonly Config $config)
    {
    }

    public function generar(string $sid): string
    {
        $r = random_bytes(16);
        return self::b64($r) . '.' . self::b64($this->firma($sid, $r));
    }

    public function esValidoPara(?string $token, string $sid): bool
    {
        if ($token === null || strlen($token) > 128 || substr_count($token, '.') !== 1) {
            return false;
        }
        [$a, $b] = explode('.', $token);
        $r = self::deb64($a);
        $mac = self::deb64($b);
        if ($r === null || $mac === null || strlen($r) !== 16) {
            return false;
        }
        return hash_equals($this->firma($sid, $r), $mac);
    }

    /** Token enviado por el cliente (cabecera tiene prioridad sobre el campo). */
    public static function enviado(Request $req): ?string
    {
        $h = $req->header('x-csrf-token');
        if ($h !== null && $h !== '') {
            return $h;
        }
        $f = $req->input('_csrf');
        return $f !== '' ? $f : null;
    }

    /** Doble envío + HMAC para el sid. */
    public function verificar(Request $req, string $cookieCsrf, string $sid): bool
    {
        $cookie = $req->cookie($cookieCsrf);
        $enviado = self::enviado($req);
        if ($cookie === null || $enviado === null || !hash_equals($cookie, $enviado)) {
            return false;
        }
        return $this->esValidoPara($enviado, $sid);
    }

    /**
     * Origen de la petición (métodos no seguros). Orden:
     *  1. Origin presente: debe ser exactamente APP_URL (esquema, host y puerto).
     *  2. Sin Origin: Sec-Fetch-Site debe ser same-origin o none.
     *  3. Sin ninguna de las dos: Referer del mismo origen.
     *  4. Nada: se rechaza (fail closed).
     */
    public static function origenValido(Config $c, Request $req): bool
    {
        $origin = $req->header('origin');
        if ($origin !== null && $origin !== '') {
            return hash_equals($c->origen(), strtolower(rtrim($origin, '/')));
        }
        $sfs = $req->header('sec-fetch-site');
        if ($sfs !== null && $sfs !== '') {
            return in_array(strtolower($sfs), ['same-origin', 'none'], true);
        }
        $ref = $req->header('referer');
        if ($ref !== null && $ref !== '') {
            $p = parse_url($ref);
            if ($p === false || !isset($p['scheme'], $p['host'])) {
                return false;
            }
            $o = strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
            return hash_equals($c->origen(), $o);
        }
        return false;
    }

    private function firma(string $sid, string $r): string
    {
        return hash_hmac('sha256', 'csrf|' . $sid . '|' . $r, $this->config->csrfKey, true);
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function deb64(string $s): ?string
    {
        if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $s)) {
            return null;
        }
        $d = base64_decode(strtr($s, '-_', '+/'), true);
        return $d === false ? null : $d;
    }
}
