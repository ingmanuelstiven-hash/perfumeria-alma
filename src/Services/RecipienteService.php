<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Support\Decimal;
use App\Support\Entrada;

/**
 * Recipientes de esencia (frasco o garrafa física con etiqueta). Las existencias de la
 * esencia (`existencias.cantidad`, en gramos) son la suma de sus recipientes: el saldo de un
 * recipiente solo se mueve con movimientos en `movimientos_esencia`. Aquí, la única entrada
 * es el 'saldo_inicial' al crear (y exige inventario.ajustar + inventario.ver_costos, porque
 * el asiento lleva el costo por gramo). El código de la etiqueta no cambia después de creado.
 *
 * Código autogenerado: 'R-' + id con ceros (R-000045, docs §7). No hay consecutivo de
 * recipientes en `consecutivos` (su ENUM no lo admite sin migración) y el AUTO_INCREMENT de
 * la propia tabla no tiene saltos que importen ni duplicados. Los códigos tecleados que
 * parezcan 'R-<dígitos>' se reservan para esta generación.
 */
final class RecipienteService
{
    public const POR_PAGINA = 20;
    public const ESTADOS = ['activo', 'vacio', 'baja'];

    /**
     * @param array<string,mixed> $filtros esencia_id, estado, q (código/descripción/esencia), pagina
     * @param array<string,true>  $permisos
     * @return array{filas:list<array<string,mixed>>, total:int, pagina:int, por_pagina:int, paginas:int, filtros:array<string,string>}
     */
    public function listar(array $filtros, array $permisos): array
    {
        $esencia = Entrada::id(self::texto($filtros['esencia_id'] ?? '')) ?? 0;
        $estado = self::texto($filtros['estado'] ?? '');
        $estado = in_array($estado, self::ESTADOS, true) ? $estado : '';
        $q = Entrada::recortar(self::texto($filtros['q'] ?? ''), 100);
        $pagina = max(1, (int) preg_replace('/\D/', '', substr(self::texto($filtros['pagina'] ?? '1'), 0, 6)));
        $verInv = isset($permisos['inventario.ver']);

        $where = [];
        $params = [];
        if ($esencia > 0) {
            $where[] = 'a.id = ?';
            $params[] = $esencia;
        }
        if ($estado !== '') {
            $where[] = 'r.estado = ?';
            $params[] = $estado;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(r.codigo LIKE ? OR r.descripcion LIKE ? OR a.nombre LIKE ? OR a.sku LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $from = ' FROM recipientes_esencia r JOIN esencias e ON e.id = r.esencia_id JOIN articulos a ON a.id = e.articulo_id
                  JOIN sucursales s ON s.id = r.sucursal_id' . $w;

        $pdo = Db::pdo();
        $st = $pdo->prepare('SELECT COUNT(*)' . $from);
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $cols = 'r.id, r.codigo, r.descripcion, r.capacidad_g, r.tara_g, r.estado, r.ubicacion, r.sucursal_id, s.nombre AS sucursal,
                 a.id AS articulo_id, a.sku, a.nombre AS esencia' . ($verInv ? ', r.gramos_actuales' : '');
        $st = $pdo->prepare('SELECT ' . $cols . $from . ' ORDER BY a.nombre, r.codigo LIMIT ' . self::POR_PAGINA . ' OFFSET ' . (($pagina - 1) * self::POR_PAGINA));
        $st->execute($params);
        $filas = array_map(static function (array $f): array {
            foreach (['id', 'sucursal_id', 'articulo_id'] as $k) {
                $f[$k] = (int) $f[$k];
            }
            return $f;
        }, $st->fetchAll());
        return [
            'filas' => $filas, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => self::POR_PAGINA, 'paginas' => $paginas,
            'filtros' => ['esencia_id' => $esencia > 0 ? (string) $esencia : '', 'estado' => $estado, 'q' => $q],
        ];
    }

    /** @return array<string,mixed>|null */
    public function obtener(int $id, array $permisos): ?array
    {
        $r = $this->listarUno($id);
        if ($r === null) {
            return null;
        }
        if (!isset($permisos['inventario.ver'])) {
            unset($r['gramos_actuales']);
        }
        return $r;
    }

    /** @return list<array{id:int, nombre:string}> */
    public function sucursalesActivas(): array
    {
        $r = Db::pdo()->query('SELECT id, nombre FROM sucursales WHERE activa = 1 ORDER BY id')->fetchAll();
        return array_map(static fn ($s) => ['id' => (int) $s['id'], 'nombre' => $s['nombre']], $r);
    }

    /**
     * @param array<string,mixed> $in esencia_id (id del ARTÍCULO), sucursal_id, codigo, descripcion, capacidad_g, tara_g,
     *                                 ubicacion, gramos_iniciales, costo_g
     * @param array<string,true>  $permisos
     */
    public function crear(array $actor, array $permisos, array $in): int
    {
        $e = [];
        $g = static fn (string $k): string => self::texto($in[$k] ?? '');
        $articuloId = Entrada::id($g('esencia_id'));
        if ($articuloId === null) {
            $e['esencia_id'] = 'Elija la esencia.';
        }
        $sucursalId = $g('sucursal_id') === '' ? (int) $actor['sucursal_id'] : (Entrada::id($g('sucursal_id')) ?? 0);
        if ($sucursalId < 1) {
            $e['sucursal_id'] = 'Sucursal inválida.';
        }
        $codigo = $g('codigo');
        $auto = $codigo === '';
        if (!$auto) {
            if (!preg_match('/^[!-~]{1,40}$/D', $codigo) || preg_match('/^\d+$/D', $codigo)) {
                $e['codigo'] = 'Código inválido: 1 a 40 caracteres ASCII visibles, sin espacios y no solo dígitos.';
            } elseif (preg_match('/^R-\d+$/Di', $codigo)) {
                $e['codigo'] = "Los códigos 'R-<números>' se reservan para los generados automáticamente.";
            }
        }
        $desc = $g('descripcion');
        if ($desc !== '' && !Entrada::textoValido($desc, 1, 100)) {
            $e['descripcion'] = 'Descripción: máximo 100 caracteres.';
        }
        $ubic = $g('ubicacion');
        if ($ubic !== '' && !Entrada::textoValido($ubic, 1, 60)) {
            $e['ubicacion'] = 'Ubicación: máximo 60 caracteres.';
        }
        [$cap, $tara] = $this->parsearMedidas($g('capacidad_g'), $g('tara_g'), $e);

        $gramos = null;
        $costo = null;
        if ($g('gramos_iniciales') !== '' || $g('costo_g') !== '') {
            if (!isset($permisos['inventario.ajustar']) || !isset($permisos['inventario.ver_costos'])) {
                throw new ErrorNegocio('No tiene permiso para registrar un saldo inicial (requiere ajustar inventario y ver costos).', 403);
            }
            $gramos = Decimal::parsear($g('gramos_iniciales'), 7, 3);
            if ($gramos === null || Decimal::esCero($gramos)) {
                $e['gramos_iniciales'] = 'Gramos iniciales inválidos: mayor que 0, hasta 3 decimales (déjelo vacío si el recipiente está vacío).';
            }
            $costo = Decimal::parsear($g('costo_g'), 8, 6);
            if ($costo === null) {
                $e['costo_g'] = 'Costo por gramo obligatorio con saldo inicial: no negativo, hasta 6 decimales.';
            }
            if ($gramos !== null && $cap !== null && Decimal::comparar($gramos, $cap) > 0) {
                $e['gramos_iniciales'] = 'Los gramos iniciales superan la capacidad del recipiente.';
            }
        }
        if ($e) {
            throw new ErrorNegocio('Revise los datos del recipiente.', 422, $e);
        }

        try {
            return Db::transaction(function (\PDO $pdo) use ($actor, $articuloId, $sucursalId, $codigo, $auto, $desc, $ubic, $cap, $tara, $gramos, $costo): int {
                $st = $pdo->prepare(
                    "SELECT a.id, a.activo, e.id AS esencia_id FROM articulos a JOIN esencias e ON e.articulo_id = a.id
                      WHERE a.id = ? AND a.tipo = 'esencia'"
                );
                $st->execute([$articuloId]);
                $es = $st->fetch();
                if (!$es || (int) $es['activo'] !== 1) {
                    throw new ErrorNegocio('Revise los datos del recipiente.', 422, ['esencia_id' => 'La esencia no existe o está inactiva.']);
                }
                $st = $pdo->prepare('SELECT 1 FROM sucursales WHERE id = ? AND activa = 1');
                $st->execute([$sucursalId]);
                if ($st->fetchColumn() === false) {
                    throw new ErrorNegocio('Revise los datos del recipiente.', 422, ['sucursal_id' => 'La sucursal no existe o está inactiva.']);
                }
                // existencias (bloqueo) -> recipientes, como en docs/BASE_DE_DATOS.md §6.
                InventarioService::bloquearExistencia($pdo, $sucursalId, $articuloId);
                $pdo->prepare(
                    "INSERT INTO recipientes_esencia (sucursal_id, esencia_id, codigo, descripcion, capacidad_g, tara_g, gramos_actuales, estado, ubicacion)
                     VALUES (?, ?, ?, ?, ?, ?, 0, 'vacio', ?)"
                )->execute([$sucursalId, (int) $es['esencia_id'], $auto ? '~' . bin2hex(random_bytes(8)) : $codigo,
                    $desc === '' ? null : $desc, $cap, $tara, $ubic === '' ? null : $ubic]);
                $id = (int) $pdo->lastInsertId();
                if ($auto) {
                    $codigo = 'R-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
                    $pdo->prepare('UPDATE recipientes_esencia SET codigo = ? WHERE id = ?')->execute([$codigo, $id]);
                }
                if ($gramos !== null) {
                    InventarioService::saldoInicialRecipiente($pdo, $actor, $id, (int) $es['esencia_id'], $articuloId, $sucursalId, $gramos, (string) $costo);
                }
                Auditoria::registrar('recipiente.crear', (int) $actor['id'], (int) $actor['sucursal_id'], 'recipientes_esencia', $id, null, [
                    'codigo' => $codigo, 'articulo_id' => $articuloId, 'sucursal_id' => $sucursalId, 'descripcion' => $desc === '' ? null : $desc,
                    'capacidad_g' => $cap, 'tara_g' => $tara, 'ubicacion' => $ubic === '' ? null : $ubic, 'saldo_inicial_g' => $gramos,
                ]);
                return $id;
            }, 3);
        } catch (\PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) === 1062 && str_contains((string) ($ex->errorInfo[2] ?? ''), 'uq_recipientes_codigo')) {
                throw new ErrorNegocio('Ya existe un recipiente con ese código.', 409, ['codigo' => 'Ya existe un recipiente con ese código.']);
            }
            throw $ex;
        }
    }

    /** Edita descripción, capacidad, tara y ubicación. El saldo y el código no se tocan. */
    public function editar(array $actor, int $id, array $in): void
    {
        $e = [];
        $g = static fn (string $k): string => self::texto($in[$k] ?? '');
        $desc = $g('descripcion');
        if ($desc !== '' && !Entrada::textoValido($desc, 1, 100)) {
            $e['descripcion'] = 'Descripción: máximo 100 caracteres.';
        }
        $ubic = $g('ubicacion');
        if ($ubic !== '' && !Entrada::textoValido($ubic, 1, 60)) {
            $e['ubicacion'] = 'Ubicación: máximo 60 caracteres.';
        }
        [$cap, $tara] = $this->parsearMedidas($g('capacidad_g'), $g('tara_g'), $e);
        if ($e) {
            throw new ErrorNegocio('Revise los datos del recipiente.', 422, $e);
        }
        Db::transaction(function (\PDO $pdo) use ($actor, $id, $desc, $ubic, $cap, $tara): void {
            $r = $this->bloquear($pdo, $id);
            if ($cap !== null && Decimal::comparar($cap, (string) $r['gramos_actuales']) < 0) {
                throw new ErrorNegocio('Revise los datos del recipiente.', 422, ['capacidad_g' => 'La capacidad no puede ser menor que los gramos que contiene.']);
            }
            $antes = ['descripcion' => $r['descripcion'], 'capacidad_g' => $r['capacidad_g'], 'tara_g' => $r['tara_g'], 'ubicacion' => $r['ubicacion']];
            $despues = ['descripcion' => $desc === '' ? null : $desc, 'capacidad_g' => $cap, 'tara_g' => $tara, 'ubicacion' => $ubic === '' ? null : $ubic];
            if ($antes === $despues) {
                return;
            }
            $pdo->prepare('UPDATE recipientes_esencia SET descripcion = ?, capacidad_g = ?, tara_g = ?, ubicacion = ? WHERE id = ?')
                ->execute([$despues['descripcion'], $cap, $tara, $despues['ubicacion'], $id]);
            Auditoria::registrar('recipiente.editar', (int) $actor['id'], (int) $actor['sucursal_id'], 'recipientes_esencia', $id, $antes, $despues);
        }, 3);
    }

    /** Baja lógica (solo vacío) y reactivación. */
    public function cambiarActivo(array $actor, int $id, bool $activo): void
    {
        Db::transaction(function (\PDO $pdo) use ($actor, $id, $activo): void {
            $r = $this->bloquear($pdo, $id);
            if ($activo) {
                if ($r['estado'] !== 'baja') {
                    return;
                }
                $nuevo = Decimal::esCero((string) $r['gramos_actuales']) ? 'vacio' : 'activo';
            } else {
                if ($r['estado'] === 'baja') {
                    return;
                }
                if (!Decimal::esCero((string) $r['gramos_actuales'])) {
                    throw new ErrorNegocio('No se puede dar de baja un recipiente con esencia: vacíelo primero (merma o trasvase).', 409);
                }
                $nuevo = 'baja';
            }
            $pdo->prepare('UPDATE recipientes_esencia SET estado = ? WHERE id = ?')->execute([$nuevo, $id]);
            Auditoria::registrar($activo ? 'recipiente.reactivar' : 'recipiente.baja', (int) $actor['id'], (int) $actor['sucursal_id'],
                'recipientes_esencia', $id, ['estado' => $r['estado']], ['estado' => $nuevo]);
        }, 3);
    }

    // --------------------------------------------------------------- interno

    /** @return array<string,mixed>|null */
    private function listarUno(int $id): ?array
    {
        $st = Db::pdo()->prepare(
            'SELECT r.id, r.codigo, r.descripcion, r.capacidad_g, r.tara_g, r.gramos_actuales, r.estado, r.ubicacion,
                    r.sucursal_id, s.nombre AS sucursal, a.id AS articulo_id, a.sku, a.nombre AS esencia
               FROM recipientes_esencia r JOIN esencias e ON e.id = r.esencia_id JOIN articulos a ON a.id = e.articulo_id
               JOIN sucursales s ON s.id = r.sucursal_id WHERE r.id = ?'
        );
        $st->execute([$id]);
        $f = $st->fetch();
        if (!$f) {
            return null;
        }
        foreach (['id', 'sucursal_id', 'articulo_id'] as $k) {
            $f[$k] = (int) $f[$k];
        }
        return $f;
    }

    /** @return array<string,mixed> */
    private function bloquear(\PDO $pdo, int $id): array
    {
        $st = $pdo->prepare('SELECT * FROM recipientes_esencia WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        return $st->fetch() ?: throw new ErrorNegocio('Recipiente no encontrado.', 404);
    }

    /** @param array<string,string> $e @return array{0:?string, 1:?string} capacidad y tara normalizadas */
    private function parsearMedidas(string $cap, string $tara, array &$e): array
    {
        $c = null;
        $t = null;
        if ($cap !== '') {
            $c = Decimal::parsear($cap, 7, 3);
            if ($c === null || Decimal::esCero($c)) {
                $e['capacidad_g'] = 'Capacidad inválida: mayor que 0, hasta 3 decimales.';
                $c = null;
            }
        }
        if ($tara !== '') {
            $t = Decimal::parsear($tara, 7, 3);
            if ($t === null) {
                $e['tara_g'] = 'Tara inválida: no negativa, hasta 3 decimales.';
            }
        }
        return [$c, $t];
    }

    private static function texto(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
