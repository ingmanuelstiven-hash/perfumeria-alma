<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Errores;
use App\Http\Request;
use App\Http\Response;
use App\Services\ArticuloService;
use App\Services\ErrorNegocio;
use App\Services\RecipienteService;

/**
 * Recipientes de esencia. Lectura: inventario.ver. Escritura: articulos.gestionar + CSRF.
 * Un saldo inicial exige además inventario.ajustar e inventario.ver_costos (lo comprueba el servicio).
 */
final class RecipientesController extends ControladorBase
{
    private const MENSAJES = ['creado' => 'Recipiente creado.', 'editado' => 'Recipiente actualizado.', 'activo' => 'Estado actualizado.'];
    private const CAMPOS_FORM = ['esencia_id', 'sucursal_id', 'codigo', 'descripcion', 'capacidad_g', 'tara_g', 'ubicacion', 'gramos_iniciales', 'costo_g'];

    private RecipienteService $recipientes;
    private ArticuloService $articulos;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->recipientes = new RecipienteService();
        $this->articulos = new ArticuloService();
    }

    public function listar(Request $req): Response
    {
        $res = $this->recipientes->listar($req->query, $this->permisos($req));
        if ($req->quiereJson()) {
            return Response::json(['recipientes' => $res['filas'], 'pagina' => $res['pagina'], 'por_pagina' => $res['por_pagina'],
                'total' => $res['total'], 'paginas' => $res['paginas']]);
        }
        return $this->pagina($req, 'recipientes_lista', [
            'res' => $res, 'esencias' => $this->articulos->esenciasActivas(),
            'mensaje' => self::MENSAJES[(string) ($req->query['m'] ?? '')] ?? null, 'error' => null,
        ]);
    }

    public function nuevo(Request $req): Response
    {
        $esencia = is_string($req->query['esencia_id'] ?? null) ? $req->query['esencia_id'] : '';
        return $this->formulario($req, 'crear', null, ['esencia_id' => $esencia], null);
    }

    public function crear(Request $req): Response
    {
        return $this->ejecutar($req,
            fn () => ['id' => $this->recipientes->crear($req->get('usuario'), $this->permisos($req), $req->body)],
            fn (array $d) => '/inventario/recipientes?m=creado',
            fn (ErrorNegocio $e) => $this->formulario($req, 'crear', null, $this->valoresForm($req, self::CAMPOS_FORM), $e, $e->status),
            201);
    }

    public function editarForm(Request $req, array $p): Response
    {
        $r = $this->recipientes->obtener($this->idRuta($p), $this->permisos($req));
        if ($r === null) {
            return Errores::respuesta($req, 404);
        }
        $form = [];
        foreach (['descripcion', 'capacidad_g', 'tara_g', 'ubicacion'] as $k) {
            $form[$k] = $r[$k] === null ? '' : (string) $r[$k];
        }
        return $this->formulario($req, 'editar', $r, $form, null);
    }

    public function editar(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        return $this->ejecutar($req,
            function () use ($req, $id): array {
                $this->recipientes->editar($req->get('usuario'), $id, $req->body);
                return ['id' => $id];
            },
            fn () => '/inventario/recipientes?m=editado',
            function (ErrorNegocio $e) use ($req, $id): Response {
                $r = $this->recipientes->obtener($id, $this->permisos($req));
                return $r === null ? Errores::respuesta($req, 404)
                    : $this->formulario($req, 'editar', $r, $this->valoresForm($req, self::CAMPOS_FORM), $e, $e->status);
            });
    }

    public function activo(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        return $this->ejecutar($req,
            function () use ($req, $id): array {
                $v = $req->input('activo');
                if (!in_array($v, ['0', '1'], true)) {
                    throw new ErrorNegocio('Valor de activo inválido.', 422);
                }
                $this->recipientes->cambiarActivo($req->get('usuario'), $id, $v === '1');
                return ['id' => $id];
            },
            fn () => '/inventario/recipientes?m=activo',
            function (ErrorNegocio $e) use ($req): Response {
                $res = $this->recipientes->listar([], $this->permisos($req));
                return $this->pagina($req, 'recipientes_lista', ['res' => $res, 'esencias' => $this->articulos->esenciasActivas(),
                    'mensaje' => null, 'error' => $e->getMessage()], $e->status);
            });
    }

    /** @param array<string,mixed>|null $r recipiente en edición */
    private function formulario(Request $req, string $modo, ?array $r, array $form, ?ErrorNegocio $e, int $status = 200): Response
    {
        $permisos = $this->permisos($req);
        return $this->pagina($req, 'recipiente_form', [
            'modo' => $modo, 'r' => $r, 'form' => $form, 'error' => $e?->getMessage(), 'errores' => $e?->errores ?? [],
            'esencias' => $this->articulos->esenciasActivas(), 'sucursales' => $this->recipientes->sucursalesActivas(),
            'puede_saldo' => isset($permisos['inventario.ajustar']) && isset($permisos['inventario.ver_costos']),
        ], $status);
    }
}
