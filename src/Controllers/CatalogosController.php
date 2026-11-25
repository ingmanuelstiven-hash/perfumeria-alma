<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Services\CatalogosService;
use App\Services\ErrorNegocio;

/** Marcas y categorías: lectura con articulos.ver; escritura con articulos.gestionar + CSRF. */
final class CatalogosController extends ControladorBase
{
    private const MENSAJES = [
        'creado' => 'Creado.', 'editado' => 'Actualizado.', 'activo' => 'Estado actualizado.',
    ];

    private CatalogosService $catalogos;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->catalogos = new CatalogosService();
    }

    // ----------------------------------------------------------------- marcas

    public function marcas(Request $req): Response
    {
        return $this->paginaMarcas($req, 200, self::MENSAJES[(string) ($req->query['m'] ?? '')] ?? null, null, [], []);
    }

    public function crearMarca(Request $req): Response
    {
        return $this->ejecutar($req,
            fn () => ['id' => $this->catalogos->crearMarca($req->get('usuario'), $req->input('nombre'))],
            fn () => '/admin/marcas?m=creado',
            fn (ErrorNegocio $e) => $this->paginaMarcas($req, $e->status, null, $e->getMessage(), $e->errores, ['nombre' => $req->input('nombre')]),
            201);
    }

    public function renombrarMarca(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        return $this->ejecutar($req,
            function () use ($req, $id): array {
                $this->catalogos->renombrarMarca($req->get('usuario'), $id, $req->input('nombre'));
                return ['id' => $id];
            },
            fn () => '/admin/marcas?m=editado',
            fn (ErrorNegocio $e) => $this->paginaMarcas($req, $e->status, null, $e->getMessage(), $e->errores, []));
    }

    public function activarMarca(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        return $this->ejecutar($req,
            function () use ($req, $id): array {
                $this->catalogos->activarMarca($req->get('usuario'), $id, $this->valorActivo($req));
                return ['id' => $id];
            },
            fn () => '/admin/marcas?m=activo',
            fn (ErrorNegocio $e) => $this->paginaMarcas($req, $e->status, null, $e->getMessage(), $e->errores, []));
    }

    private function paginaMarcas(Request $req, int $status, ?string $mensaje, ?string $error, array $errores, array $form): Response
    {
        $marcas = $this->catalogos->marcas();
        if ($req->quiereJson()) {
            return Response::json(['marcas' => array_map(static fn ($m) => [
                'id' => (int) $m['id'], 'nombre' => $m['nombre'], 'activa' => (int) $m['activa'] === 1, 'articulos' => (int) $m['articulos'],
            ], $marcas)]);
        }
        return $this->pagina($req, 'catalogo_marcas', [
            'marcas' => $marcas, 'mensaje' => $mensaje, 'error' => $error, 'errores' => $errores, 'form' => $form,
        ], $status);
    }

    // ------------------------------------------------------------- categorías

    public function categorias(Request $req): Response
    {
        return $this->paginaCategorias($req, 200, self::MENSAJES[(string) ($req->query['m'] ?? '')] ?? null, null, [], []);
    }

    public function crearCategoria(Request $req): Response
    {
        return $this->ejecutar($req,
            fn () => ['id' => $this->catalogos->crearCategoria($req->get('usuario'), $req->body)],
            fn () => '/admin/categorias?m=creado',
            fn (ErrorNegocio $e) => $this->paginaCategorias($req, $e->status, null, $e->getMessage(), $e->errores, $this->formCategoria($req)),
            201);
    }

    public function editarCategoria(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        return $this->ejecutar($req,
            function () use ($req, $id): array {
                $this->catalogos->editarCategoria($req->get('usuario'), $id, $req->body);
                return ['id' => $id];
            },
            fn () => '/admin/categorias?m=editado',
            fn (ErrorNegocio $e) => $this->paginaCategorias($req, $e->status, null, $e->getMessage(), $e->errores, []));
    }

    public function activarCategoria(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        return $this->ejecutar($req,
            function () use ($req, $id): array {
                $this->catalogos->activarCategoria($req->get('usuario'), $id, $this->valorActivo($req));
                return ['id' => $id];
            },
            fn () => '/admin/categorias?m=activo',
            fn (ErrorNegocio $e) => $this->paginaCategorias($req, $e->status, null, $e->getMessage(), $e->errores, []));
    }

    private function paginaCategorias(Request $req, int $status, ?string $mensaje, ?string $error, array $errores, array $form): Response
    {
        $cats = $this->catalogos->categorias();
        if ($req->quiereJson()) {
            return Response::json(['categorias' => array_map(static fn ($c) => [
                'id' => (int) $c['id'], 'padre_id' => $c['padre_id'] === null ? null : (int) $c['padre_id'], 'tipo' => $c['tipo'],
                'nombre' => $c['nombre'], 'slug' => $c['slug'], 'orden' => (int) $c['orden'], 'activa' => (int) $c['activa'] === 1,
                'articulos' => (int) $c['articulos'],
            ], $cats)]);
        }
        return $this->pagina($req, 'catalogo_categorias', [
            'categorias' => $cats, 'tipos' => CatalogosService::TIPOS_CATEGORIA,
            'mensaje' => $mensaje, 'error' => $error, 'errores' => $errores, 'form' => $form,
        ], $status);
    }

    /** @return array<string,string> */
    private function formCategoria(Request $req): array
    {
        return $this->valoresForm($req, ['nombre', 'tipo', 'padre_id', 'orden']);
    }

    private function valorActivo(Request $req): bool
    {
        $v = $req->input('activo');
        if (!in_array($v, ['0', '1'], true)) {
            throw new ErrorNegocio('Valor de activo inválido.', 422);
        }
        return $v === '1';
    }
}
