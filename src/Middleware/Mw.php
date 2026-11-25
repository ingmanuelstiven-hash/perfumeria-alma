<?php

declare(strict_types=1);

namespace App\Middleware;

use App\App;
use App\Http\Errores;
use App\Http\Request;
use App\Http\Response;
use App\Security\TokenExpirado;
use App\Security\TokenInvalido;
use App\Services\Auditoria;
use App\Services\AuthService;
use App\Services\Permisos;
use App\View;

/**
 * Middleware encadenables: callable(Request $req, callable $next): Response.
 * Orden típico: autenticar -> csrf -> permiso('modulo.accion').
 */
final class Mw
{
    /**
     * Lee el access JWT de la cookie, valida firma/claims y comprueba en BD
     * (activo, token_version, rol, jti revocado, familia viva). Deja en la petición:
     * 'usuario' (fila), 'claims' (objeto) y 'permisos' (array<string,true>).
     */
    public static function autenticar(App $app): callable
    {
        return static function (Request $req, callable $next) use ($app): Response {
            $ck = $app->cookies($req);
            $jwt = $req->cookie($ck->nombreAcceso());
            if ($jwt === null) {
                return self::noAutenticado($app, $req, null, false);
            }
            try {
                $claims = $app->tokens->validar($jwt);
            } catch (TokenExpirado $e) {
                return self::noAutenticado($app, $req, $e->claims, false);
            } catch (TokenInvalido) {
                return self::noAutenticado($app, $req, null, true);
            }
            $u = AuthService::verificarSesion($claims);
            if ($u === null) {
                return self::noAutenticado($app, $req, null, true);
            }
            $req->set('usuario', $u);
            $req->set('claims', $claims);
            $req->set('permisos', Permisos::efectivos($u));
            $resp = $next($req);
            $resp->headers['Cache-Control'] = 'no-store';
            return $resp;
        };
    }

    /** CSRF de sesión: doble envío + HMAC atado al sid del access token. */
    public static function csrf(App $app): callable
    {
        return static function (Request $req, callable $next) use ($app): Response {
            $claims = $req->get('claims');
            if (!is_object($claims) || !$app->csrf->verificar($req, $app->cookies($req)->nombreCsrf(), (string) $claims->sid)) {
                return Errores::respuesta($req, 403, 'csrf_invalido');
            }
            return $next($req);
        };
    }

    /** Autorización por acción. Lee permisos de la BD (ya cargados por autenticar). */
    public static function permiso(string $codigo): callable
    {
        if (!preg_match('/^[a-z_]+\.[a-z_]+$/', $codigo)) {
            throw new \InvalidArgumentException('Código de permiso inválido: ' . $codigo);
        }
        return static function (Request $req, callable $next) use ($codigo): Response {
            $permisos = $req->get('permisos', []);
            if (!is_array($permisos) || !isset($permisos[$codigo])) {
                $u = $req->get('usuario');
                Auditoria::registrar('acceso.denegado', is_array($u) ? (int) $u['id'] : null,
                    is_array($u) ? (int) $u['sucursal_id'] : null, null, null, null,
                    ['permiso' => $codigo, 'metodo' => $req->method, 'ruta' => mb_substr($req->path, 0, 100)]);
                return Errores::respuesta($req, 403, 'sin_permiso');
            }
            return $next($req);
        };
    }

    /**
     * Sin sesión válida.
     *  - JSON: 401 {error: sesion_expirada|no_autenticado}; con HX-Trigger para htmx.
     *  - HTML GET con access expirado (firma válida): página mínima que hace POST a
     *    /auth/refresh y vuelve a la página (renovar.js la envía sola; sin JS, botón).
     *  - Resto: GET -> 303 a /login; otros métodos -> 401.
     */
    private static function noAutenticado(App $app, Request $req, ?object $expirado, bool $borrar): Response
    {
        $ck = $app->cookies($req);
        if ($req->quiereJson()) {
            $r = Response::json([
                'error' => $expirado !== null ? 'sesion_expirada' : 'no_autenticado',
                'mensaje' => Errores::mensaje(401),
            ], 401);
            if ($expirado !== null) {
                $r->headers['HX-Trigger'] = 'sesion-expirada';
            }
        } elseif ($expirado !== null && $req->method === 'GET') {
            $r = Response::html('', 401);
            $token = $app->csrfParaVista($req, $r, (string) $expirado->sid);
            $r->body = View::render('renovar', ['siguiente' => $req->path, 'auto' => true], $token);
        } elseif ($req->method === 'GET') {
            $r = Response::redirect($app->config->url('/login'));
        } else {
            $r = Errores::respuesta($req, 401);
        }
        if ($borrar) {
            $ck->borrarTodas($r);
        }
        return $r;
    }
}
