<?php

declare(strict_types=1);

namespace App\Security;

use App\Config;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Access token JWT HS256 de 10 minutos (firebase/php-jwt).
 *
 * Claims: iss, aud, sub (id usuario, texto), sid (familia de refresh, 32 hex),
 * rol (código), tv (usuarios.token_version), jti (32 hex), iat, nbf, exp.
 * Cabecera kid = huella de la clave (permite rotar JWT_SECRET con JWT_SECRET_ANTERIOR).
 *
 * El algoritmo lo fija el servidor (Key(..., 'HS256')): alg=none, HS512/RS256 con
 * la misma clave o cualquier cabecera distinta se rechazan en JWT::decode.
 * Los permisos NO van en el token: se leen de la BD en cada petición.
 */
final class TokenAcceso
{
    public const VIDA_SEG = 600;
    public const MARGEN_RELOJ_SEG = 30;

    public function __construct(private readonly Config $config)
    {
    }

    public static function kid(#[\SensitiveParameter] string $clave): string
    {
        return substr(hash('sha256', 'kid|' . $clave), 0, 16);
    }

    /** @return array{jwt:string, jti:string, exp:int} */
    public function emitir(int $usuarioId, string $rol, string $sidHex, int $tokenVersion): array
    {
        $ahora = time();
        $jti = bin2hex(random_bytes(16));
        $exp = $ahora + self::VIDA_SEG;
        $claims = [
            'iss' => $this->config->emisor(),
            'aud' => $this->config->emisor(),
            'sub' => (string) $usuarioId,
            'sid' => $sidHex,
            'rol' => $rol,
            'tv' => $tokenVersion,
            'jti' => $jti,
            'iat' => $ahora,
            'nbf' => $ahora,
            'exp' => $exp,
        ];
        $jwt = JWT::encode($claims, $this->config->jwtKey, 'HS256', self::kid($this->config->jwtKey));
        return ['jwt' => $jwt, 'jti' => $jti, 'exp' => $exp];
    }

    /**
     * @return object claims validados
     * @throws TokenExpirado si la firma es válida pero expiró (lleva los claims)
     * @throws TokenInvalido en cualquier otro caso
     */
    public function validar(string $jwt): object
    {
        if (strlen($jwt) > 2048) {
            throw new TokenInvalido('demasiado largo');
        }
        $claves = [self::kid($this->config->jwtKey) => new Key($this->config->jwtKey, 'HS256')];
        if ($this->config->jwtKeyAnterior !== null) {
            $claves[self::kid($this->config->jwtKeyAnterior)] = new Key($this->config->jwtKeyAnterior, 'HS256');
        }
        JWT::$leeway = self::MARGEN_RELOJ_SEG;
        try {
            $claims = JWT::decode($jwt, $claves);
        } catch (ExpiredException $e) {
            $claims = $e->getPayload();
            if (!$this->claimsBienFormados($claims)) {
                throw new TokenInvalido('claims');
            }
            throw new TokenExpirado($claims);
        } catch (\Throwable $e) {
            // Firma, kid, alg, formato, nbf... El detalle no sale de aquí.
            throw new TokenInvalido(get_class($e));
        }
        if (!$this->claimsBienFormados($claims)) {
            throw new TokenInvalido('claims');
        }
        return $claims;
    }

    private function claimsBienFormados(object $c): bool
    {
        return isset($c->iss, $c->aud, $c->sub, $c->sid, $c->rol, $c->tv, $c->jti, $c->exp)
            && is_string($c->iss) && hash_equals($this->config->emisor(), $c->iss)
            && is_string($c->aud) && hash_equals($this->config->emisor(), $c->aud)
            && is_string($c->sub) && ctype_digit($c->sub) && strlen($c->sub) <= 10
            && is_string($c->sid) && preg_match('/^[0-9a-f]{32}$/', $c->sid) === 1
            && is_string($c->jti) && preg_match('/^[0-9a-f]{32}$/', $c->jti) === 1
            && is_string($c->rol) && preg_match('/^[a-z_]{1,30}$/', $c->rol) === 1
            && is_int($c->tv) && $c->tv >= 1
            && is_int($c->exp);
    }
}
