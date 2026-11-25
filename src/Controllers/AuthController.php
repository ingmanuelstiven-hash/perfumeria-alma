<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Db;
use App\Http\Errores;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\LimiteTasa;
use App\Security\TokenExpirado;
use App\Security\TokenInvalido;
use App\Services\AuthService;
use App\View;

final class AuthController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /login */
    public function formulario(Request $req): Response
    {
        return $this->paginaLogin($req, 200, '', null);
    }

    /** POST /login (form o JSON). CSRF previo a la sesión con sid "anon". */
    public function login(Request $req): Response
    {
        $usuario = $req->input('usuario');
        $pass = $req->input('password');
        $ck = $this->app->cookies($req);

        if (!$this->app->csrf->verificar($req, $ck->nombreCsrf(), Csrf::ANON)) {
            Db::pdo()->prepare(
                'INSERT INTO login_intentos (usuario_texto, usuario_id, ip, exito, motivo, user_agent) VALUES (?, NULL, ?, 0, ?, ?)'
            )->execute([mb_substr(mb_strtolower(trim($usuario), 'UTF-8'), 0, 50, 'UTF-8'), $req->ipBinaria(), 'csrf', $req->userAgent()]);
            if ($req->quiereJson()) {
                return Errores::respuesta($req, 403, 'csrf_invalido');
            }
            return $this->paginaLogin($req, 403, $usuario, 'El formulario caducó. Vuelva a intentarlo.', true);
        }

        $res = $this->app->auth->login($usuario, $pass, $req->ip, $req->ipBinaria(), $req->userAgent());
        unset($pass);

        if ($res['estado'] === 'ok') {
            $sesion = $res['sesion'];
            $u = $res['usuario'];
            $destino = $u['debe_cambiar_password'] ? '/cuenta/password' : '/';
            $resp = $req->quiereJson()
                ? Response::json(['ok' => true, 'usuario' => $u, 'access_exp' => $sesion['access_exp']])
                : Response::redirect($this->app->config->url($destino));
            $this->app->ponerCookiesSesion($req, $resp, $sesion, true);
            return $resp;
        }

        if ($res['estado'] === 'bloqueado') {
            $resp = $req->quiereJson()
                ? Errores::respuesta($req, 429)
                : $this->paginaLogin($req, 429, $usuario, Errores::mensaje(429));
            $resp->headers['Retry-After'] = (string) ($res['reintentar'] ?? 60);
            return $resp;
        }

        // Credenciales: MISMA respuesta para usuario inexistente, clave mala o inactivo.
        if ($req->quiereJson()) {
            return Response::json(['error' => 'credenciales_invalidas', 'mensaje' => AuthService::MENSAJE_CREDENCIALES], 401);
        }
        return $this->paginaLogin($req, 401, $usuario, AuthService::MENSAJE_CREDENCIALES);
    }

    /**
     * POST /auth/refresh. Cookie del refresh con Path=/auth/refresh. CSRF atado a la
     * familia del refresh (sid). Respuestas: 200 rotado; 409 otra pestaña acaba de
     * rotar (reintentar con la cookie nueva); 401 inválido/expirado/reutilizado.
     */
    public function refrescar(Request $req): Response
    {
        $ck = $this->app->cookies($req);
        $kip = LimiteTasa::clave('refresh_ip', AuthService::normalizarIp($req->ip));
        $max = $this->app->config->refreshMaxPorMinuto;
        if (LimiteTasa::bloqueo($kip) > 0 || LimiteTasa::incrementar($kip, $max, 60, 60) > $max) {
            $r = Errores::respuesta($req, 429);
            $r->headers['Retry-After'] = '60';
            return $r;
        }

        $plano = $req->cookie($ck->nombreRefresh());
        $sid = $plano === null ? null : $this->app->auth->familiaDe($plano);
        if ($plano === null || $sid === null) {
            return $this->sesionInvalida($req);
        }
        if (!$this->app->csrf->verificar($req, $ck->nombreCsrf(), $sid)) {
            return Errores::respuesta($req, 403, 'csrf_invalido');
        }

        $res = $this->app->auth->refrescar($plano, $req->ipBinaria(), $req->userAgent());
        if ($res['estado'] === 'ok') {
            $siguiente = self::siguienteSeguro($req->input('siguiente', '/'));
            $resp = $req->quiereJson()
                ? Response::json(['ok' => true, 'access_exp' => $res['sesion']['access_exp']])
                : Response::redirect($this->app->config->url($siguiente));
            $this->app->ponerCookiesSesion($req, $resp, $res['sesion'], false);
            return $resp;
        }
        if ($res['estado'] === 'gracia') {
            if ($req->quiereJson()) {
                return Response::json(['error' => 'refresh_en_curso', 'mensaje' => 'Otra pestaña renovó la sesión; reintente.'], 409);
            }
            $r = Response::html('', 409);
            $token = $this->app->csrfParaVista($req, $r, $sid);
            $r->body = View::render('renovar', [
                'siguiente' => self::siguienteSeguro($req->input('siguiente', '/')), 'auto' => false,
            ], $token);
            return $r;
        }
        return $this->sesionInvalida($req);
    }

    /** POST /logout. Acepta un access expirado con firma válida (para poder cerrar sesión siempre). */
    public function logout(Request $req): Response
    {
        $ck = $this->app->cookies($req);
        $claims = null;
        $jwt = $req->cookie($ck->nombreAcceso());
        if ($jwt !== null) {
            try {
                $claims = $this->app->tokens->validar($jwt);
            } catch (TokenExpirado $e) {
                $claims = $e->claims;
            } catch (TokenInvalido) {
                $claims = null;
            }
        }
        if ($claims !== null) {
            if (!$this->app->csrf->verificar($req, $ck->nombreCsrf(), (string) $claims->sid)) {
                return Errores::respuesta($req, 403, 'csrf_invalido');
            }
            $this->app->auth->logout($claims);
        }
        $resp = $req->quiereJson() ? Response::json(['ok' => true]) : Response::redirect($this->app->config->url('/login'));
        $ck->borrarTodas($resp);
        return $resp;
    }

    private function sesionInvalida(Request $req): Response
    {
        $resp = $req->quiereJson()
            ? Response::json(['error' => 'sesion_invalida', 'mensaje' => Errores::mensaje(401)], 401)
            : Response::redirect($this->app->config->url('/login'));
        $this->app->cookies($req)->borrarTodas($resp);
        return $resp;
    }

    private function paginaLogin(Request $req, int $status, string $usuario, ?string $error, bool $forzarNuevoCsrf = false): Response
    {
        $r = Response::html('', $status);
        if ($forzarNuevoCsrf) {
            $token = $this->app->csrf->generar(Csrf::ANON);
            $this->app->cookies($req)->ponerCsrf($r, $token, 7200);
        } else {
            $token = $this->app->csrfParaVista($req, $r, Csrf::ANON);
        }
        $r->body = View::render('login', ['usuario' => mb_substr($usuario, 0, 50, 'UTF-8'), 'error' => $error], $token);
        return $r;
    }

    /** Solo rutas internas simples: evita redirecciones abiertas (//evil, \\evil, esquemas). */
    public static function siguienteSeguro(string $s): string
    {
        return preg_match('#^/(?!/)[A-Za-z0-9/_-]{0,100}$#', $s) === 1 ? $s : '/';
    }
}
