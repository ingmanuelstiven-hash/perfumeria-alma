<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Errores;
use App\Http\Request;
use App\Http\Response;
use App\Services\ArticuloService;
use App\Services\CatalogosService;
use App\Services\ErrorNegocio;
use App\Services\RecipienteService;

/**
 * Catálogo interno de ítems (esencias, productos, envases, insumos).
 * Lectura: articulos.ver. Escritura: articulos.gestionar + CSRF. Costos: inventario.ver_costos.
 */
final class ArticulosController extends ControladorBase
{
    private const MENSAJES = [
        'creado' => 'Artículo creado. Su inventario quedó en cero en cada sucursal activa.',
        'editado' => 'Artículo actualizado.',
        'activo' => 'Estado actualizado.',
    ];

    private const CAMPOS_FORM = [
        'tipo', 'sku', 'sku_auto', 'nombre', 'descripcion', 'categoria_id', 'unidad_medida', 'tasa_iva', 'stock_minimo',
        'inspiracion', 'marca_id', 'genero', 'familia_olfativa', 'densidad_g_ml', 'contenido_ml',
        'capacidad_ml', 'material', 'tipo_cierre', 'color', 'tipo_insumo', 'saldo_inicial', 'costo_unitario',
    ];

    private ArticuloService $articulos;
    private CatalogosService $catalogos;
    private RecipienteService $recipientes;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->articulos = new ArticuloService();
        $this->catalogos = new CatalogosService();
        $this->recipientes = new RecipienteService();
    }

    public function listar(Request $req): Response
    {
        $u = $req->get('usuario');
        $res = $this->articulos->listar($req->query, (int) $u['sucursal_id'], $this->permisos($req));
        if ($req->quiereJson()) {
            return Response::json([
                'articulos' => $res['filas'], 'pagina' => $res['pagina'], 'por_pagina' => $res['por_pagina'],
                'total' => $res['total'], 'paginas' => $res['paginas'],
            ]);
        }
        return $this->pagina($req, 'articulos_lista', [
            'res' => $res, 'mensaje' => self::MENSAJES[(string) ($req->query['m'] ?? '')] ?? null,
        ]);
    }

    /** GET /inventario/articulos/nuevo?tipo=... (sin tipo: elegir). */
    public function nuevo(Request $req): Response
    {
        $tipo = is_string($req->query['tipo'] ?? null) ? $req->query['tipo'] : '';
        if (!in_array($tipo, ArticuloService::TIPOS, true)) {
            $tipo = '';
        }
        return $this->formulario($req, 'crear', $tipo, null, [], null, []);
    }

    public function crear(Request $req): Response
    {
        return $this->ejecutar(
            $req,
            fn () => ['id' => $this->articulos->crear($req->get('usuario'), $this->permisos($req), $req->body)],
            fn (array $d) => '/inventario/articulos/' . (int) $d['id'] . '?m=creado',
            fn (ErrorNegocio $e) => $this->formulario($req, 'crear', $req->input('tipo'), null, $this->valoresForm($req, self::CAMPOS_FORM), $e, [], $e->status),
            201,
        );
    }

    public function ver(Request $req, array $p): Response
    {
        $a = $this->articulos->obtener($this->idRuta($p), $this->permisos($req));
        if ($a === null) {
            return Errores::respuesta($req, 404);
        }
        $recip = null;
        if ($a['tipo'] === 'esencia' && isset($this->permisos($req)['inventario.ver'])) {
            $recip = $this->recipientes->listar(['esencia_id' => (string) $a['id']], $this->permisos($req))['filas'];
        }
        if ($req->quiereJson()) {
            return Response::json(['articulo' => $a] + ($recip !== null ? ['recipientes' => $recip] : []));
        }
        return $this->pagina($req, 'articulo_detalle', [
            'a' => $a, 'recipientes' => $recip, 'mensaje' => self::MENSAJES[(string) ($req->query['m'] ?? '')] ?? null,
            'error' => null, 'errores' => [],
        ]);
    }

    public function editarForm(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        $a = $this->articulos->obtener($id, $this->permisos($req));
        if ($a === null) {
            return Errores::respuesta($req, 404);
        }
        $sinHistoria = !$this->articulos->tieneHistoria($id);
        $tipo = (string) $a['tipo'];
        $pedido = is_string($req->query['tipo'] ?? null) ? $req->query['tipo'] : '';
        if ($sinHistoria && in_array($pedido, ArticuloService::TIPOS, true)) {
            $tipo = $pedido;
        }
        $form = $this->aFormulario($a);
        $form['tipo'] = $tipo;
        if ($tipo !== $a['tipo']) {
            $form['unidad_medida'] = '';   // la del tipo anterior puede no valer para el nuevo
        }
        return $this->formulario($req, 'editar', $tipo, $a, $form, null, [], 200, $sinHistoria);
    }

    public function editar(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        return $this->ejecutar(
            $req,
            function () use ($req, $id): array {
                $this->articulos->editar($req->get('usuario'), $id, $req->body);
                return ['id' => $id];
            },
            fn () => '/inventario/articulos/' . $id . '?m=editado',
            function (ErrorNegocio $e) use ($req, $id): Response {
                $a = $this->articulos->obtener($id, $this->permisos($req));
                if ($a === null) {
                    return Errores::respuesta($req, 404);
                }
                return $this->formulario($req, 'editar', $req->input('tipo') ?: (string) $a['tipo'], $a,
                    $this->valoresForm($req, self::CAMPOS_FORM), $e, [], $e->status, !$this->articulos->tieneHistoria($id));
            },
        );
    }

    public function activo(Request $req, array $p): Response
    {
        $id = $this->idRuta($p);
        return $this->ejecutar(
            $req,
            function () use ($req, $id): array {
                $valor = $req->input('activo');
                if (!in_array($valor, ['0', '1'], true)) {
                    throw new ErrorNegocio('Valor de activo inválido.', 422);
                }
                $this->articulos->cambiarActivo($req->get('usuario'), $id, $valor === '1');
                return ['id' => $id, 'activo' => $valor === '1'];
            },
            fn () => '/inventario/articulos/' . $id . '?m=activo',
            function (ErrorNegocio $e) use ($req, $id): Response {
                $a = $this->articulos->obtener($id, $this->permisos($req));
                if ($a === null) {
                    return Errores::respuesta($req, 404);
                }
                return $this->pagina($req, 'articulo_detalle', ['a' => $a, 'recipientes' => null, 'mensaje' => null,
                    'error' => $e->getMessage(), 'errores' => $e->errores], $e->status);
            },
        );
    }

    // --------------------------------------------------------------- interno

    /**
     * @param array<string,string> $form valores a mostrar
     * @param array<string,mixed>|null $a artículo en edición
     */
    private function formulario(Request $req, string $modo, string $tipo, ?array $a, array $form, ?ErrorNegocio $e, array $extra, int $status = 200, bool $sinHistoria = false): Response
    {
        $tipo = in_array($tipo, ArticuloService::TIPOS, true) ? $tipo : '';
        $permisos = $this->permisos($req);
        return $this->pagina($req, 'articulo_form', [
            'modo' => $modo, 'tipo' => $tipo, 'a' => $a, 'form' => $form,
            'error' => $e?->getMessage(), 'errores' => $e?->errores ?? [],
            'categorias' => array_values(array_filter($this->catalogos->categorias(true), fn ($c) => $tipo === '' || $c['tipo'] === 'general' || $c['tipo'] === $tipo)),
            'marcas' => $this->catalogos->marcas(true),
            'puede_saldo' => isset($permisos['inventario.ajustar']) && isset($permisos['inventario.ver_costos']),
            'sin_historia' => $sinHistoria,
            'unidades' => $tipo === '' ? [] : ArticuloService::UNIDADES[$tipo],
        ] + $extra, $status);
    }

    /** Artículo (detalle) -> valores de formulario en texto. @return array<string,string> */
    private function aFormulario(array $a): array
    {
        $f = [];
        foreach (self::CAMPOS_FORM as $k) {
            if ($k === 'saldo_inicial' || $k === 'costo_unitario' || $k === 'sku_auto') {
                continue;
            }
            $v = $a[$k] ?? null;
            $f[$k] = $v === null ? '' : (string) $v;
        }
        return $f;
    }
}
