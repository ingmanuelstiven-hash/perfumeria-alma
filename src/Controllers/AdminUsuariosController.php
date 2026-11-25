<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Services\ErrorNegocio;
use App\View;

/** Gestión mínima de usuarios (rol admin: usuarios.ver / usuarios.gestionar). */
final class AdminUsuariosController
{
    private const MENSAJES = [
        'creado' => 'Usuario creado. Deberá cambiar la contraseña temporal al entrar.',
        'rol' => 'Rol actualizado. Las sesiones de ese usuario se cerraron.',
        'activo' => 'Estado actualizado.',
        'desbloqueado' => 'Usuario desbloqueado.',
        'ip' => 'IP desbloqueada.',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function listar(Request $req): Response
    {
        if ($req->quiereJson()) {
            return Response::json(['usuarios' => array_map(self::fila(...), $this->app->usuarios->listar())]);
        }
        return $this->pagina($req, 200, self::MENSAJES[(string) ($req->query['m'] ?? '')] ?? null, null, []);
    }

    public function crear(Request $req): Response
    {
        return $this->ejecutar($req, 'creado', function () use ($req): array {
            $id = $this->app->usuarios->crear(
                $req->get('usuario'),
                $req->input('usuario'),
                $req->input('email'),
                $req->input('nombre'),
                $req->input('rol'),
                $req->input('password'),
            );
            return ['id' => $id];
        }, 201);
    }

    public function rol(Request $req, array $p): Response
    {
        return $this->ejecutar($req, 'rol', function () use ($req, $p): array {
            $this->app->usuarios->cambiarRol($req->get('usuario'), (int) $p['id'], $req->input('rol'));
            return [];
        });
    }

    public function activo(Request $req, array $p): Response
    {
        return $this->ejecutar($req, 'activo', function () use ($req, $p): array {
            $valor = $req->input('activo');
            if (!in_array($valor, ['0', '1'], true)) {
                throw new ErrorNegocio('Valor de activo inválido.', 422);
            }
            $this->app->usuarios->cambiarActivo($req->get('usuario'), (int) $p['id'], $valor === '1');
            return [];
        });
    }

    public function desbloquear(Request $req, array $p): Response
    {
        return $this->ejecutar($req, 'desbloqueado', function () use ($req, $p): array {
            $this->app->usuarios->desbloquear($req->get('usuario'), (int) $p['id']);
            return [];
        });
    }

    public function desbloquearIp(Request $req): Response
    {
        return $this->ejecutar($req, 'ip', function () use ($req): array {
            $this->app->usuarios->desbloquearIp($req->get('usuario'), $req->input('ip'));
            return [];
        });
    }

    private function ejecutar(Request $req, string $codigoOk, callable $fn, int $statusOk = 200): Response
    {
        try {
            $datos = $fn();
        } catch (ErrorNegocio $e) {
            if ($req->quiereJson()) {
                return Response::json(['error' => 'operacion_rechazada', 'mensaje' => $e->getMessage(), 'errores' => $e->errores], $e->status);
            }
            return $this->pagina($req, $e->status, null, $e->getMessage(), $e->errores);
        }
        if ($req->quiereJson()) {
            return Response::json(['ok' => true] + $datos, $statusOk);
        }
        return Response::redirect($this->app->config->url('/admin/usuarios?m=' . $codigoOk));
    }

    /** @param list<string> $errores */
    private function pagina(Request $req, int $status, ?string $mensaje, ?string $error, array $errores): Response
    {
        $r = Response::html('', $status);
        $token = $this->app->csrfParaVista($req, $r, (string) $req->get('claims')->sid);
        $permisos = $req->get('permisos', []);
        $r->body = View::render('admin_usuarios', [
            'usuario' => $req->get('usuario'),
            'usuarios' => $this->app->usuarios->listar(),
            'roles' => $this->app->usuarios->roles(),
            'puede_gestionar' => isset($permisos['usuarios.gestionar']),
            'mensaje' => $mensaje,
            'error' => $error,
            'errores' => $errores,
            'form' => [
                'usuario' => mb_substr($req->input('usuario'), 0, 50, 'UTF-8'),
                'email' => mb_substr($req->input('email'), 0, 150, 'UTF-8'),
                'nombre' => mb_substr($req->input('nombre'), 0, 100, 'UTF-8'),
            ],
        ], $token);
        return $r;
    }

    private static function fila(array $u): array
    {
        return [
            'id' => (int) $u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'email' => $u['email'],
            'rol' => $u['rol'], 'activo' => (int) $u['activo'] === 1, 'bloqueado' => (int) $u['bloqueado'] === 1,
            'debe_cambiar_password' => (int) $u['debe_cambiar_password'] === 1, 'ultimo_login_en' => $u['ultimo_login_en'],
        ];
    }
}
