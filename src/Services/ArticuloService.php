<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Support\Decimal;
use App\Support\Entrada;

/**
 * Catálogo interno de ítems de inventario (Sprint 1B): esencias, productos, envases e insumos.
 *
 * Reglas de oro (docs/BASE_DE_DATOS.md §10, SEGURIDAD_AUTH.md):
 *  - Alta = articulos + subtipo + una fila de `existencias` (saldo 0) por sucursal activa, en
 *    UNA transacción. Con saldo inicial opcional (no esencias), el saldo entra por un
 *    movimiento 'saldo_inicial' en el libro; el CRUD nunca escribe saldos.
 *  - Los costos viven en `existencias` (costo_promedio, ultimo_costo). Solo se LEEN de la BD
 *    cuando el llamador tiene `inventario.ver_costos`: sin él, el SELECT ni los incluye.
 *  - Tipo, SKU y unidad de medida son inmutables en cuanto el ítem tiene historia (movimientos,
 *    saldo, recipientes o documentos que lo referencian).
 *  - Borrado lógico (`activo`); nunca DELETE de artículos.
 */
final class ArticuloService
{
    public const TIPOS = ['esencia', 'producto', 'envase', 'insumo'];
    public const POR_PAGINA = 20;

    public const GENEROS = ['femenino', 'masculino', 'unisex'];
    public const MATERIALES = ['vidrio', 'plastico', 'aluminio', 'otro'];
    public const CIERRES = ['atomizador', 'roll_on', 'tapa', 'gotero', 'otro'];
    public const TIPOS_INSUMO = ['alcohol', 'base', 'fijador', 'caja', 'bolsa', 'etiqueta', 'otro'];

    /** Unidades de medida admitidas por tipo (la esencia es siempre gramos: CHECK ck_articulos_unidad). */
    public const UNIDADES = ['esencia' => ['g'], 'producto' => ['und', 'ml'], 'envase' => ['und'], 'insumo' => ['g', 'ml', 'und']];

    /**
     * Tablas con FK a articulos(id) / esencias(id) que impiden cambiar tipo, SKU o unidad:
     * el ítem ya tiene historia. Excluidas a propósito: los subtipos 1:1, `existencias` (se
     * comprueba por valor) y codigos_barras/imagenes (no dependen del tipo; sprint 1C).
     * tests/casos_1b.php compara esta lista con las FKs reales para detectar tablas nuevas.
     */
    public const REFERENCIAS_ARTICULO = [
        'receta_componentes' => 'articulo_id', 'compra_detalles' => 'articulo_id', 'lotes' => 'articulo_id',
        'movimientos_inventario' => 'articulo_id', 'conteo_detalles' => 'articulo_id', 'promociones' => 'articulo_id',
        'catalogo_items' => 'articulo_id', 'venta_detalles' => 'articulo_id', 'venta_detalle_componentes' => 'articulo_id',
        'apartado_items' => 'articulo_id',
    ];
    public const REFERENCIAS_ESENCIA = [
        'recetas' => 'esencia_id', 'recipientes_esencia' => 'esencia_id', 'movimientos_esencia' => 'esencia_id',
        'catalogo_items' => 'esencia_id', 'venta_detalles' => 'esencia_id', 'apartado_items' => 'esencia_id',
    ];
    /** FKs a articulos(id) que NO bloquean cambios (ver arriba). */
    public const REFERENCIAS_NEUTRAS = ['codigos_barras', 'imagenes', 'esencias', 'envases_vacios', 'insumos', 'productos', 'existencias'];

    private const CAMPOS_COMUNES = ['tipo', 'sku', 'nombre', 'descripcion', 'categoria_id', 'unidad_medida', 'tasa_iva', 'stock_minimo'];
    private const CAMPOS_SUBTIPO = [
        'esencia' => ['inspiracion', 'marca_id', 'genero', 'familia_olfativa', 'densidad_g_ml'],
        'producto' => ['marca_id', 'contenido_ml'],
        'envase' => ['capacidad_ml', 'material', 'tipo_cierre', 'color'],
        'insumo' => ['tipo_insumo'],
    ];

    // ================================================================ lectura

    /**
     * Listado paginado. Filtros: q (SKU, nombre, marca o categoría), tipo, activo ('1' | '0' | 'todos').
     * Existencias solo con inventario.ver; costos solo con inventario.ver_costos (ni se consultan sin él).
     *
     * @param array<string,mixed> $filtros
     * @param array<string,true>  $permisos
     * @return array{filas:list<array<string,mixed>>, total:int, pagina:int, por_pagina:int, paginas:int, filtros:array<string,string>}
     */
    public function listar(array $filtros, int $sucursalId, array $permisos): array
    {
        $q = Entrada::recortar(self::texto($filtros['q'] ?? ''), 100);
        $tipo = self::texto($filtros['tipo'] ?? '');
        $tipo = in_array($tipo, self::TIPOS, true) ? $tipo : '';
        $activo = self::texto($filtros['activo'] ?? '1');
        $activo = in_array($activo, ['1', '0', 'todos'], true) ? $activo : '1';
        $pagina = max(1, (int) preg_replace('/\D/', '', substr(self::texto($filtros['pagina'] ?? '1'), 0, 6)));

        $verInv = isset($permisos['inventario.ver']);
        $verCostos = isset($permisos['inventario.ver_costos']);

        $select = 'a.id, a.tipo, a.sku, a.nombre, a.unidad_medida, a.tasa_iva, a.stock_minimo, a.activo,
                   c.nombre AS categoria, m.nombre AS marca';
        $from = ' FROM articulos a
                  LEFT JOIN categorias c ON c.id = a.categoria_id
                  LEFT JOIN esencias e ON e.articulo_id = a.id
                  LEFT JOIN productos p ON p.articulo_id = a.id
                  LEFT JOIN marcas m ON m.id = COALESCE(e.marca_id, p.marca_id)';
        $paramsFrom = [];
        if ($verInv || $verCostos) {
            $from .= ' LEFT JOIN existencias x ON x.articulo_id = a.id AND x.sucursal_id = ?';
            $paramsFrom[] = $sucursalId;
            if ($verInv) {
                $select .= ', x.cantidad, x.cantidad_reservada';
            }
            if ($verCostos) {
                $select .= ', x.costo_promedio, x.ultimo_costo';
            }
        }
        $where = [];
        $paramsWhere = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(a.sku LIKE ? OR a.nombre LIKE ? OR m.nombre LIKE ? OR c.nombre LIKE ?)';
            array_push($paramsWhere, $like, $like, $like, $like);
        }
        if ($tipo !== '') {
            $where[] = 'a.tipo = ?';
            $paramsWhere[] = $tipo;
        }
        if ($activo !== 'todos') {
            $where[] = 'a.activo = ?';
            $paramsWhere[] = (int) $activo;
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $pdo = Db::pdo();
        $st = $pdo->prepare('SELECT COUNT(*)' . $from . $w);
        $st->execute(array_merge($paramsFrom, $paramsWhere));
        $total = (int) $st->fetchColumn();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));

        $offset = ($pagina - 1) * self::POR_PAGINA;   // enteros ya validados: se pueden interpolar
        $st = $pdo->prepare('SELECT ' . $select . $from . $w . ' ORDER BY a.nombre, a.id LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset);
        $st->execute(array_merge($paramsFrom, $paramsWhere));
        $filas = array_map(fn (array $f) => $this->salida($f, $verInv, $verCostos), $st->fetchAll());

        return [
            'filas' => $filas, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => self::POR_PAGINA, 'paginas' => $paginas,
            'filtros' => ['q' => $q, 'tipo' => $tipo, 'activo' => $activo],
        ];
    }

    /**
     * Detalle con subtipo y existencias por sucursal (según permisos). null si no existe.
     * @param array<string,true> $permisos
     * @return array<string,mixed>|null
     */
    public function obtener(int $id, array $permisos): ?array
    {
        $fila = $this->cargar(Db::pdo(), $id);
        if ($fila === null) {
            return null;
        }
        $verInv = isset($permisos['inventario.ver']);
        $verCostos = isset($permisos['inventario.ver_costos']);
        $out = $this->salida($fila, false, false) + [
            'creado_en' => $fila['creado_en'], 'actualizado_en' => $fila['actualizado_en'],
        ];
        if ($verInv || $verCostos) {
            $cols = 's.id AS sucursal_id, s.nombre AS sucursal';
            if ($verInv) {
                $cols .= ', x.cantidad, x.cantidad_reservada';
            }
            if ($verCostos) {
                $cols .= ', x.costo_promedio, x.ultimo_costo';
            }
            $st = Db::pdo()->prepare("SELECT $cols FROM existencias x JOIN sucursales s ON s.id = x.sucursal_id WHERE x.articulo_id = ? ORDER BY s.id");
            $st->execute([$id]);
            $out['existencias'] = array_map(static function (array $x) use ($verInv, $verCostos): array {
                $r = ['sucursal_id' => (int) $x['sucursal_id'], 'sucursal' => $x['sucursal']];
                if ($verInv) {
                    $r += ['cantidad' => $x['cantidad'], 'cantidad_reservada' => $x['cantidad_reservada']];
                }
                if ($verCostos) {
                    $r += ['costo_promedio' => $x['costo_promedio'], 'ultimo_costo' => $x['ultimo_costo']];
                }
                return $r;
            }, $st->fetchAll());
        }
        return $out;
    }

    /** @return list<array{id:int, sku:string, nombre:string}> esencias activas (para elegir en recipientes) */
    public function esenciasActivas(): array
    {
        $r = Db::pdo()->query("SELECT id, sku, nombre FROM articulos WHERE tipo = 'esencia' AND activo = 1 ORDER BY nombre, id")->fetchAll();
        return array_map(static fn ($f) => ['id' => (int) $f['id'], 'sku' => $f['sku'], 'nombre' => $f['nombre']], $r);
    }

    /** ¿Tiene historia (impide cambiar tipo, SKU y unidad)? */
    public function tieneHistoria(int $id): bool
    {
        return self::historia(Db::pdo(), $id);
    }

    // ================================================================= altas

    /**
     * @param array<string,mixed> $in      campos del formulario (strings)
     * @param array<string,true>  $permisos permisos del actor (para el saldo inicial)
     * @return int id del artículo
     */
    public function crear(array $actor, array $permisos, array $in): int
    {
        $tipo = self::texto($in['tipo'] ?? '');
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new ErrorNegocio('Revise los datos del artículo.', 422, ['tipo' => 'Tipo de artículo inválido.']);
        }
        [$d, $errores] = $this->parsear($in, $tipo, false);
        [$saldo, $costo, $errSaldo] = $this->parsearSaldo($in, $tipo, $permisos);
        $errores += $errSaldo;
        if ($errores) {
            throw new ErrorNegocio('Revise los datos del artículo.', 422, $errores);
        }

        try {
            return Db::transaction(function (\PDO $pdo) use ($actor, $tipo, $d, $saldo, $costo): int {
                // 1. consecutivos (primer bloqueo del orden global) -> 2. alta -> 3. existencias.
                $sku = $d['sku_auto'] ? SkuService::siguiente($pdo, $tipo) : $d['sku'];
                $this->validarReferencias($pdo, $d, $tipo, null, null);
                $pdo->prepare(
                    'INSERT INTO articulos (tipo, sku, nombre, descripcion, categoria_id, unidad_medida, tasa_iva, stock_minimo, activo, creado_por)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
                )->execute([$tipo, $sku, $d['nombre'], $d['descripcion'], $d['categoria_id'], $d['unidad_medida'],
                    $d['tasa_iva'], $d['stock_minimo'], (int) $actor['id']]);
                $id = (int) $pdo->lastInsertId();
                $this->escribirSubtipo($pdo, $id, $tipo, $d, true);
                $filas = InventarioService::crearFilasExistencias($pdo, $id);
                if ($saldo !== null) {
                    InventarioService::saldoInicialArticulo($pdo, $actor, $id, (int) $actor['sucursal_id'], $saldo, (string) $costo);
                }
                $despues = $this->datosAuditoria($d + ['sku' => $sku, 'tipo' => $tipo], $tipo);
                $despues['existencias_creadas'] = $filas;
                if ($saldo !== null) {
                    $despues['saldo_inicial'] = $saldo;   // el costo queda solo en el libro, no en auditoría
                }
                Auditoria::registrar('articulo.crear', (int) $actor['id'], (int) $actor['sucursal_id'], 'articulos', $id, null, $despues);
                return $id;
            }, 3);
        } catch (\PDOException $e) {
            throw $this->traducir($e, $d['sku_auto']);
        }
    }

    // ============================================================== edición

    /**
     * Edición completa. Tipo, SKU y unidad solo cambian si el ítem no tiene historia.
     * @param array<string,mixed> $in
     */
    public function editar(array $actor, int $id, array $in): void
    {
        try {
            Db::transaction(function (\PDO $pdo) use ($actor, $id, $in): void {
                $actual = $this->bloquear($pdo, $id);
                $antesFila = $this->cargar($pdo, $id) ?? throw new ErrorNegocio('Artículo no encontrado.', 404);
                $tipoActual = (string) $antesFila['tipo'];
                $tipoNuevo = self::texto($in['tipo'] ?? '') ?: $tipoActual;
                if (!in_array($tipoNuevo, self::TIPOS, true)) {
                    throw new ErrorNegocio('Revise los datos del artículo.', 422, ['tipo' => 'Tipo de artículo inválido.']);
                }
                [$d, $errores] = $this->parsear($in, $tipoNuevo, true);
                if ($errores) {
                    throw new ErrorNegocio('Revise los datos del artículo.', 422, $errores);
                }
                $d['sku'] ??= $actual['sku'];

                $cambiaTipo = $tipoNuevo !== $tipoActual;
                $cambiaSku = $d['sku'] !== $actual['sku'];
                $cambiaUnidad = $d['unidad_medida'] !== $actual['unidad_medida'];
                if ($cambiaTipo || $cambiaSku || $cambiaUnidad) {
                    if (self::historia($pdo, $id)) {
                        $campo = $cambiaTipo ? 'tipo' : ($cambiaSku ? 'sku' : 'unidad_medida');
                        $que = ['tipo' => 'el tipo', 'sku' => 'el SKU', 'unidad_medida' => 'la unidad de medida'][$campo];
                        throw new ErrorNegocio("No se puede cambiar $que: el artículo ya tiene movimientos, existencias o documentos asociados.", 409,
                            [$campo => "No se puede cambiar $que de un artículo con historia."]);
                    }
                }
                $this->validarReferencias($pdo, $d, $tipoNuevo, $antesFila['categoria_id'] === null ? null : (int) $antesFila['categoria_id'],
                    $antesFila['marca_id'] === null ? null : (int) $antesFila['marca_id']);

                $antes = $this->datosAuditoria($this->aPlano($antesFila), $tipoActual);
                $despues = $this->datosAuditoria($d + ['tipo' => $tipoNuevo], $tipoNuevo);
                if (json_encode($antes) === json_encode($despues)) {
                    return;
                }
                $pdo->prepare(
                    'UPDATE articulos SET tipo = ?, sku = ?, nombre = ?, descripcion = ?, categoria_id = ?, unidad_medida = ?, tasa_iva = ?, stock_minimo = ?
                      WHERE id = ?'
                )->execute([$tipoNuevo, $d['sku'], $d['nombre'], $d['descripcion'], $d['categoria_id'], $d['unidad_medida'],
                    $d['tasa_iva'], $d['stock_minimo'], $id]);
                if ($cambiaTipo) {
                    // Sin historia (comprobado arriba): se reemplaza el subtipo. Las FKs son la última red.
                    $tabla = ['esencia' => 'esencias', 'producto' => 'productos', 'envase' => 'envases_vacios', 'insumo' => 'insumos'][$tipoActual];
                    $pdo->prepare("DELETE FROM $tabla WHERE articulo_id = ?")->execute([$id]);
                    $this->escribirSubtipo($pdo, $id, $tipoNuevo, $d, true);
                } else {
                    $this->escribirSubtipo($pdo, $id, $tipoNuevo, $d, false);
                }
                Auditoria::registrar('articulo.editar', (int) $actor['id'], (int) $actor['sucursal_id'], 'articulos', $id, $antes, $despues);
            }, 3);
        } catch (\PDOException $e) {
            throw $this->traducir($e, false);
        }
    }

    /** Desactivación / reactivación lógica. No se desactiva con unidades apartadas (reservadas). */
    public function cambiarActivo(array $actor, int $id, bool $activo): void
    {
        Db::transaction(function (\PDO $pdo) use ($actor, $id, $activo): void {
            $a = $this->bloquear($pdo, $id);
            if ((int) $a['activo'] === ($activo ? 1 : 0)) {
                return;
            }
            if (!$activo) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM existencias WHERE articulo_id = ? AND cantidad_reservada <> 0');
                $st->execute([$id]);
                if ((int) $st->fetchColumn() > 0) {
                    throw new ErrorNegocio('No se puede desactivar: hay unidades apartadas (reservadas).', 409);
                }
            }
            $pdo->prepare('UPDATE articulos SET activo = ? WHERE id = ?')->execute([$activo ? 1 : 0, $id]);
            Auditoria::registrar($activo ? 'articulo.activar' : 'articulo.desactivar', (int) $actor['id'], (int) $actor['sucursal_id'],
                'articulos', $id, ['activo' => (int) $a['activo']], ['activo' => $activo ? 1 : 0]);
        }, 3);
    }

    // ============================================================== interno

    /**
     * Bloquea existencias (orden docs §6: existencias antes que el resto) y luego la fila del
     * artículo. Así no hay interbloqueo con una venta, que bloquea existencias y después
     * toma un bloqueo compartido sobre articulos al insertar su movimiento (FK).
     * @return array<string,mixed> fila de articulos
     */
    private function bloquear(\PDO $pdo, int $id): array
    {
        $pdo->prepare('SELECT 1 FROM existencias WHERE articulo_id = ? ORDER BY sucursal_id FOR UPDATE')->execute([$id]);
        $st = $pdo->prepare('SELECT * FROM articulos WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        return $st->fetch() ?: throw new ErrorNegocio('Artículo no encontrado.', 404);
    }

    private static function historia(\PDO $pdo, int $id): bool
    {
        foreach (self::REFERENCIAS_ARTICULO as $tabla => $col) {
            $st = $pdo->prepare("SELECT 1 FROM `$tabla` WHERE `$col` = ? LIMIT 1");
            $st->execute([$id]);
            if ($st->fetchColumn() !== false) {
                return true;
            }
        }
        $st = $pdo->prepare('SELECT 1 FROM existencias WHERE articulo_id = ? AND (cantidad <> 0 OR cantidad_reservada <> 0) LIMIT 1');
        $st->execute([$id]);
        if ($st->fetchColumn() !== false) {
            return true;
        }
        $st = $pdo->prepare('SELECT id FROM esencias WHERE articulo_id = ?');
        $st->execute([$id]);
        $esenciaId = $st->fetchColumn();
        if ($esenciaId !== false) {
            foreach (self::REFERENCIAS_ESENCIA as $tabla => $col) {
                $st = $pdo->prepare("SELECT 1 FROM `$tabla` WHERE `$col` = ? LIMIT 1");
                $st->execute([(int) $esenciaId]);
                if ($st->fetchColumn() !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null artículo con su subtipo aplanado */
    private function cargar(\PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare(
            'SELECT a.id, a.tipo, a.sku, a.nombre, a.descripcion, a.categoria_id, c.nombre AS categoria, a.unidad_medida,
                    a.tasa_iva, a.stock_minimo, a.activo, a.creado_en, a.actualizado_en,
                    e.inspiracion, e.genero, e.familia_olfativa, e.densidad_g_ml,
                    COALESCE(e.marca_id, p.marca_id) AS marca_id, m.nombre AS marca,
                    p.contenido_ml, v.capacidad_ml, v.material, v.tipo_cierre, v.color, i.tipo AS tipo_insumo
               FROM articulos a
               LEFT JOIN categorias c ON c.id = a.categoria_id
               LEFT JOIN esencias e ON e.articulo_id = a.id
               LEFT JOIN productos p ON p.articulo_id = a.id
               LEFT JOIN envases_vacios v ON v.articulo_id = a.id
               LEFT JOIN insumos i ON i.articulo_id = a.id
               LEFT JOIN marcas m ON m.id = COALESCE(e.marca_id, p.marca_id)
              WHERE a.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Forma pública de una fila (HTML y JSON comparten esto). Las columnas de existencias y
     * costos solo salen si el SELECT las trajo, que a su vez depende de los permisos.
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private function salida(array $f, bool $verInv, bool $verCostos): array
    {
        $o = [
            'id' => (int) $f['id'], 'tipo' => $f['tipo'], 'sku' => $f['sku'], 'nombre' => $f['nombre'],
            'categoria' => $f['categoria'] ?? null, 'marca' => $f['marca'] ?? null, 'unidad_medida' => $f['unidad_medida'],
            'tasa_iva' => $f['tasa_iva'], 'stock_minimo' => $f['stock_minimo'], 'activo' => (int) $f['activo'] === 1,
        ];
        foreach (['descripcion', 'categoria_id', 'marca_id'] as $k) {
            if (array_key_exists($k, $f)) {
                $o[$k] = $k === 'descripcion' ? $f[$k] : ($f[$k] === null ? null : (int) $f[$k]);
            }
        }
        foreach (array_merge(...array_values(self::CAMPOS_SUBTIPO)) as $k) {
            if (array_key_exists($k, $f) && $k !== 'marca_id' && $f[$k] !== null) {
                $o[$k] = $f[$k];
            }
        }
        if ($verInv && array_key_exists('cantidad', $f)) {
            $o['cantidad'] = $f['cantidad'];
            $o['cantidad_reservada'] = $f['cantidad_reservada'];
        }
        if ($verCostos && array_key_exists('costo_promedio', $f)) {
            $o['costo_promedio'] = $f['costo_promedio'];
            $o['ultimo_costo'] = $f['ultimo_costo'];
        }
        return $o;
    }

    /** Fila cargada -> forma plana comparable con $d (para auditoría y detección de cambios). */
    private function aPlano(array $f): array
    {
        $f['categoria_id'] = $f['categoria_id'] === null ? null : (int) $f['categoria_id'];
        $f['marca_id'] = $f['marca_id'] === null ? null : (int) $f['marca_id'];
        return $f;
    }

    /** Solo los campos de negocio del tipo (sin costos ni datos sensibles). */
    private function datosAuditoria(array $d, string $tipo): array
    {
        $o = [];
        foreach (array_merge(self::CAMPOS_COMUNES, self::CAMPOS_SUBTIPO[$tipo]) as $k) {
            $o[$k] = $d[$k] ?? null;
        }
        return $o;
    }

    /**
     * Validación pura (sin BD) de los campos del formulario para el tipo dado.
     * @return array{0:array<string,mixed>, 1:array<string,string>} datos normalizados y errores por campo
     */
    private function parsear(array $in, string $tipo, bool $edicion): array
    {
        $e = [];
        $g = static fn (string $k): string => self::texto($in[$k] ?? '');
        $d = ['tipo' => $tipo, 'sku_auto' => false, 'sku' => null];

        if (!$edicion && in_array(strtolower($g('sku_auto')), ['1', 'on', 'true'], true)) {
            $d['sku_auto'] = true;
        } elseif ($g('sku') === '' && $edicion) {
            $d['sku'] = null;   // conserva el actual
        } else {
            $sku = SkuService::normalizar($g('sku'));
            if (!SkuService::formatoValido($sku)) {
                $e['sku'] = 'SKU inválido: 3 a 40 caracteres, solo mayúsculas, dígitos y guion (no puede empezar ni terminar en guion).';
            } else {
                $d['sku'] = $sku;
            }
        }

        $d['nombre'] = $g('nombre');
        if (!Entrada::textoValido($d['nombre'], 2, 150)) {
            $e['nombre'] = 'Nombre inválido: 2 a 150 caracteres, sin caracteres de control.';
        }
        $desc = self::texto($in['descripcion'] ?? '');
        $d['descripcion'] = $desc === '' ? null : $desc;
        if ($desc !== '' && !Entrada::textoValido($desc, 1, 1000, true)) {
            $e['descripcion'] = 'Descripción inválida: máximo 1000 caracteres.';
        }
        $d['categoria_id'] = $this->idOpcional($g('categoria_id'), 'categoria_id', $e, 'Categoría inválida.');

        $uni = $g('unidad_medida');
        $permitidas = self::UNIDADES[$tipo];
        if ($uni === '') {
            $uni = $permitidas[0];
        }
        if (!in_array($uni, $permitidas, true)) {
            $e['unidad_medida'] = 'Unidad no válida para ' . $tipo . ': ' . implode(', ', $permitidas) . '.';
        }
        $d['unidad_medida'] = $uni;

        $iva = Decimal::parsear($g('tasa_iva') === '' ? '19' : $g('tasa_iva'), 3, 2);
        if ($iva === null || Decimal::comparar($iva, '100.00') > 0) {
            $e['tasa_iva'] = 'IVA inválido: porcentaje de 0 a 100 con hasta 2 decimales.';
        }
        $d['tasa_iva'] = $iva ?? '19.00';
        $min = Decimal::parsear($g('stock_minimo') === '' ? '0' : $g('stock_minimo'), 9, 3);
        if ($min === null) {
            $e['stock_minimo'] = 'Stock mínimo inválido: número no negativo con hasta 3 decimales.';
        }
        $d['stock_minimo'] = $min ?? '0.000';

        switch ($tipo) {
            case 'esencia':
                $d['inspiracion'] = $this->textoOpcional($g('inspiracion'), 150, 'inspiracion', $e, 'Inspiración: máximo 150 caracteres.');
                $d['marca_id'] = $this->idOpcional($g('marca_id'), 'marca_id', $e, 'Marca inválida.');
                $gen = $g('genero') ?: 'unisex';
                if (!in_array($gen, self::GENEROS, true)) {
                    $e['genero'] = 'Género inválido.';
                }
                $d['genero'] = $gen;
                $d['familia_olfativa'] = $this->textoOpcional($g('familia_olfativa'), 60, 'familia_olfativa', $e, 'Familia olfativa: máximo 60 caracteres.');
                $den = Decimal::parsear($g('densidad_g_ml') === '' ? '1' : $g('densidad_g_ml'), 2, 4);
                if ($den === null || Decimal::esCero($den)) {
                    $e['densidad_g_ml'] = 'Densidad inválida: mayor que 0, hasta 2 enteros y 4 decimales.';
                }
                $d['densidad_g_ml'] = $den ?? '1.0000';
                break;
            case 'producto':
                $d['marca_id'] = $this->idOpcional($g('marca_id'), 'marca_id', $e, 'Marca inválida.');
                $d['contenido_ml'] = null;
                if ($g('contenido_ml') !== '') {
                    $c = Decimal::parsear($g('contenido_ml'), 6, 2);
                    if ($c === null || Decimal::esCero($c)) {
                        $e['contenido_ml'] = 'Contenido inválido: mayor que 0, hasta 2 decimales.';
                    }
                    $d['contenido_ml'] = $c;
                }
                break;
            case 'envase':
                $cap = Decimal::parsear($g('capacidad_ml'), 6, 2);
                if ($cap === null || Decimal::esCero($cap)) {
                    $e['capacidad_ml'] = 'Capacidad obligatoria: mayor que 0, hasta 2 decimales.';
                }
                $d['capacidad_ml'] = $cap;
                $d['material'] = $g('material') ?: 'vidrio';
                if (!in_array($d['material'], self::MATERIALES, true)) {
                    $e['material'] = 'Material inválido.';
                }
                $d['tipo_cierre'] = $g('tipo_cierre') ?: 'atomizador';
                if (!in_array($d['tipo_cierre'], self::CIERRES, true)) {
                    $e['tipo_cierre'] = 'Tipo de cierre inválido.';
                }
                $d['color'] = $this->textoOpcional($g('color'), 40, 'color', $e, 'Color: máximo 40 caracteres.');
                break;
            case 'insumo':
                $d['tipo_insumo'] = $g('tipo_insumo');
                if (!in_array($d['tipo_insumo'], self::TIPOS_INSUMO, true)) {
                    $e['tipo_insumo'] = 'Tipo de insumo obligatorio.';
                }
                break;
        }
        return [$d, $e];
    }

    /**
     * Saldo inicial opcional (no esencias). Requiere inventario.ajustar e inventario.ver_costos:
     * el asiento exige un costo y quien no puede ver costos no debe fijarlos.
     * @return array{0:?string, 1:?string, 2:array<string,string>} cantidad, costo, errores
     */
    private function parsearSaldo(array $in, string $tipo, array $permisos): array
    {
        $s = self::texto($in['saldo_inicial'] ?? '');
        $c = self::texto($in['costo_unitario'] ?? '');
        if ($s === '' && $c === '') {
            return [null, null, []];
        }
        if ($tipo === 'esencia') {
            return [null, null, ['saldo_inicial' => 'Las esencias se cargan por recipiente (gramos): cree el recipiente con su saldo inicial.']];
        }
        if (!isset($permisos['inventario.ajustar']) || !isset($permisos['inventario.ver_costos'])) {
            throw new ErrorNegocio('No tiene permiso para registrar un saldo inicial (requiere ajustar inventario y ver costos).', 403);
        }
        $e = [];
        $saldo = Decimal::parsear($s, 9, 3);
        if ($saldo === null || Decimal::esCero($saldo)) {
            $e['saldo_inicial'] = 'Saldo inicial inválido: mayor que 0, hasta 3 decimales (déjelo vacío si no hay saldo).';
        }
        $costo = Decimal::parsear($c, 8, 6);
        if ($costo === null) {
            $e['costo_unitario'] = 'Costo unitario obligatorio con saldo inicial: número no negativo, hasta 6 decimales.';
        }
        return [$e ? null : $saldo, $e ? null : $costo, $e];
    }

    /** Categoría y marca deben existir, estar activas (salvo que sean las que ya tenía) y ser coherentes con el tipo. */
    private function validarReferencias(\PDO $pdo, array $d, string $tipo, ?int $categoriaActual, ?int $marcaActual): void
    {
        $e = [];
        if ($d['categoria_id'] !== null) {
            $st = $pdo->prepare('SELECT tipo, activa FROM categorias WHERE id = ?');
            $st->execute([$d['categoria_id']]);
            $c = $st->fetch();
            if (!$c) {
                $e['categoria_id'] = 'La categoría no existe.';
            } elseif ((int) $c['activa'] !== 1 && $d['categoria_id'] !== $categoriaActual) {
                $e['categoria_id'] = 'La categoría está inactiva.';
            } elseif ($c['tipo'] !== 'general' && $c['tipo'] !== $tipo) {
                $e['categoria_id'] = 'La categoría es de otro tipo de artículo.';
            }
        }
        if (($d['marca_id'] ?? null) !== null) {
            $st = $pdo->prepare('SELECT activa FROM marcas WHERE id = ?');
            $st->execute([$d['marca_id']]);
            $m = $st->fetch();
            if (!$m) {
                $e['marca_id'] = 'La marca no existe.';
            } elseif ((int) $m['activa'] !== 1 && $d['marca_id'] !== $marcaActual) {
                $e['marca_id'] = 'La marca está inactiva.';
            }
        }
        if ($e) {
            throw new ErrorNegocio('Revise los datos del artículo.', 422, $e);
        }
    }

    private function escribirSubtipo(\PDO $pdo, int $id, string $tipo, array $d, bool $insertar): void
    {
        switch ($tipo) {
            case 'esencia':
                $p = [$d['inspiracion'], $d['marca_id'], $d['genero'], $d['familia_olfativa'], $d['densidad_g_ml'], $id];
                $sql = $insertar
                    ? 'INSERT INTO esencias (inspiracion, marca_id, genero, familia_olfativa, densidad_g_ml, articulo_id) VALUES (?, ?, ?, ?, ?, ?)'
                    : 'UPDATE esencias SET inspiracion = ?, marca_id = ?, genero = ?, familia_olfativa = ?, densidad_g_ml = ? WHERE articulo_id = ?';
                break;
            case 'producto':
                $p = [$d['marca_id'], $d['contenido_ml'], $id];
                $sql = $insertar
                    ? 'INSERT INTO productos (marca_id, contenido_ml, articulo_id) VALUES (?, ?, ?)'
                    : 'UPDATE productos SET marca_id = ?, contenido_ml = ? WHERE articulo_id = ?';
                break;
            case 'envase':
                $p = [$d['capacidad_ml'], $d['material'], $d['tipo_cierre'], $d['color'], $id];
                $sql = $insertar
                    ? 'INSERT INTO envases_vacios (capacidad_ml, material, tipo_cierre, color, articulo_id) VALUES (?, ?, ?, ?, ?)'
                    : 'UPDATE envases_vacios SET capacidad_ml = ?, material = ?, tipo_cierre = ?, color = ? WHERE articulo_id = ?';
                break;
            default:
                $p = [$d['tipo_insumo'], $id];
                $sql = $insertar
                    ? 'INSERT INTO insumos (tipo, articulo_id) VALUES (?, ?)'
                    : 'UPDATE insumos SET tipo = ? WHERE articulo_id = ?';
        }
        $pdo->prepare($sql)->execute($p);
    }

    private function traducir(\PDOException $e, bool $autogenerado): \Throwable
    {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) ($e->errorInfo[2] ?? ''), 'uq_articulos_sku')) {
            $m = $autogenerado
                ? 'Otro usuario tomó ese SKU al mismo tiempo. Vuelva a intentarlo.'
                : 'Ya existe un artículo con ese SKU.';
            return new ErrorNegocio($m, 409, ['sku' => $m]);
        }
        return $e;
    }

    /** @param array<string,string> $e */
    private function idOpcional(string $v, string $campo, array &$e, string $msg): ?int
    {
        if ($v === '' || $v === '0') {
            return null;
        }
        $id = Entrada::id($v);
        if ($id === null) {
            $e[$campo] = $msg;
        }
        return $id;
    }

    /** @param array<string,string> $e */
    private function textoOpcional(string $v, int $max, string $campo, array &$e, string $msg): ?string
    {
        if ($v === '') {
            return null;
        }
        if (!Entrada::textoValido($v, 1, $max)) {
            $e[$campo] = $msg;
        }
        return $v;
    }

    /** Valor escalar de entrada como texto recortado (los arrays se ignoran). */
    private static function texto(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
