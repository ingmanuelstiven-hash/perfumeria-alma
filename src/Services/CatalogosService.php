<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Support\Entrada;

/**
 * Marcas y categorías (maestros que referencian los artículos). Borrado SOLO lógico (`activa`).
 * Unicidad: uq_marcas_nombre y uq_categorias_slug de BD (error 1062 traducido).
 */
final class CatalogosService
{
    public const TIPOS_CATEGORIA = ['general', 'esencia', 'producto', 'envase', 'insumo'];

    // ---------------------------------------------------------------- marcas

    /** @return list<array{id:int|string, nombre:string, activa:int|string, articulos:int|string}> */
    public function marcas(bool $soloActivas = false): array
    {
        return Db::pdo()->query(
            'SELECT m.id, m.nombre, m.activa,
                    (SELECT COUNT(*) FROM esencias e WHERE e.marca_id = m.id)
                  + (SELECT COUNT(*) FROM productos p WHERE p.marca_id = m.id) AS articulos
               FROM marcas m' . ($soloActivas ? ' WHERE m.activa = 1' : '') . ' ORDER BY m.nombre'
        )->fetchAll();
    }

    public function crearMarca(array $actor, string $nombre): int
    {
        $nombre = trim($nombre);
        $this->validarNombre($nombre, 80, 'marca');
        try {
            return Db::transaction(function (\PDO $pdo) use ($actor, $nombre): int {
                $pdo->prepare('INSERT INTO marcas (nombre, activa) VALUES (?, 1)')->execute([$nombre]);
                $id = (int) $pdo->lastInsertId();
                Auditoria::registrar('marca.crear', (int) $actor['id'], (int) $actor['sucursal_id'], 'marcas', $id, null, ['nombre' => $nombre]);
                return $id;
            });
        } catch (\PDOException $e) {
            throw $this->traducir($e, 'Ya existe una marca con ese nombre.', 'nombre');
        }
    }

    public function renombrarMarca(array $actor, int $id, string $nombre): void
    {
        $nombre = trim($nombre);
        $this->validarNombre($nombre, 80, 'marca');
        try {
            Db::transaction(function (\PDO $pdo) use ($actor, $id, $nombre): void {
                $m = $this->bloquear($pdo, 'marcas', $id);
                if ($m['nombre'] === $nombre) {
                    return;
                }
                $pdo->prepare('UPDATE marcas SET nombre = ? WHERE id = ?')->execute([$nombre, $id]);
                Auditoria::registrar('marca.editar', (int) $actor['id'], (int) $actor['sucursal_id'], 'marcas', $id,
                    ['nombre' => $m['nombre']], ['nombre' => $nombre]);
            });
        } catch (\PDOException $e) {
            throw $this->traducir($e, 'Ya existe una marca con ese nombre.', 'nombre');
        }
    }

    public function activarMarca(array $actor, int $id, bool $activa): void
    {
        $this->cambiarActiva($actor, 'marcas', 'marca', $id, $activa);
    }

    // ------------------------------------------------------------ categorías

    /** @return list<array<string,mixed>> */
    public function categorias(bool $soloActivas = false): array
    {
        return Db::pdo()->query(
            'SELECT c.id, c.padre_id, p.nombre AS padre, c.tipo, c.nombre, c.slug, c.orden, c.activa,
                    (SELECT COUNT(*) FROM articulos a WHERE a.categoria_id = c.id) AS articulos
               FROM categorias c LEFT JOIN categorias p ON p.id = c.padre_id'
            . ($soloActivas ? ' WHERE c.activa = 1' : '') . ' ORDER BY c.tipo, c.orden, c.nombre'
        )->fetchAll();
    }

    /** @param array<string,mixed> $in nombre, tipo, padre_id, orden */
    public function crearCategoria(array $actor, array $in): int
    {
        $d = $this->validarCategoria($in);
        $slug = self::slug($d['nombre']);
        if ($slug === '') {
            throw new ErrorNegocio('Revise los datos de la categoría.', 422, ['nombre' => 'El nombre debe tener letras o números.']);
        }
        try {
            return Db::transaction(function (\PDO $pdo) use ($actor, $d, $slug): int {
                $this->validarPadre($pdo, $d['padre_id'], null);
                $pdo->prepare('INSERT INTO categorias (padre_id, tipo, nombre, slug, orden, activa) VALUES (?, ?, ?, ?, ?, 1)')
                    ->execute([$d['padre_id'], $d['tipo'], $d['nombre'], $slug, $d['orden']]);
                $id = (int) $pdo->lastInsertId();
                Auditoria::registrar('categoria.crear', (int) $actor['id'], (int) $actor['sucursal_id'], 'categorias', $id, null, $d + ['slug' => $slug]);
                return $id;
            });
        } catch (\PDOException $e) {
            throw $this->traducir($e, 'Ya existe una categoría con ese nombre (o uno equivalente sin tildes/mayúsculas).', 'nombre');
        }
    }

    /** El slug no cambia al editar (puede estar en enlaces del catálogo público). */
    public function editarCategoria(array $actor, int $id, array $in): void
    {
        $d = $this->validarCategoria($in);
        Db::transaction(function (\PDO $pdo) use ($actor, $id, $d): void {
            $c = $this->bloquear($pdo, 'categorias', $id);
            $this->validarPadre($pdo, $d['padre_id'], $id);
            if ($d['tipo'] !== 'general' && $d['tipo'] !== $c['tipo']) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM articulos WHERE categoria_id = ? AND tipo <> ?');
                $st->execute([$id, $d['tipo']]);
                if ((int) $st->fetchColumn() > 0) {
                    throw new ErrorNegocio('Hay artículos de otro tipo en esta categoría: no se puede cambiar su tipo.', 422,
                        ['tipo' => 'Hay artículos de otro tipo en esta categoría.']);
                }
            }
            $antes = ['nombre' => $c['nombre'], 'tipo' => $c['tipo'],
                'padre_id' => $c['padre_id'] === null ? null : (int) $c['padre_id'], 'orden' => (int) $c['orden']];
            if ($antes === $d) {
                return;
            }
            $pdo->prepare('UPDATE categorias SET nombre = ?, tipo = ?, padre_id = ?, orden = ? WHERE id = ?')
                ->execute([$d['nombre'], $d['tipo'], $d['padre_id'], $d['orden'], $id]);
            Auditoria::registrar('categoria.editar', (int) $actor['id'], (int) $actor['sucursal_id'], 'categorias', $id, $antes, $d);
        });
    }

    public function activarCategoria(array $actor, int $id, bool $activa): void
    {
        $this->cambiarActiva($actor, 'categorias', 'categoria', $id, $activa);
    }

    /** Minúsculas ASCII con guiones (sin intl/iconv: tabla propia de tildes). */
    public static function slug(string $nombre): string
    {
        $s = strtr(mb_strtolower($nombre, 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ]);
        return mb_substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-'), 0, 90, 'UTF-8');
    }

    // --------------------------------------------------------------- interno

    /** @return array{nombre:string, tipo:string, padre_id:?int, orden:int} */
    private function validarCategoria(array $in): array
    {
        $errores = [];
        $nombre = trim((string) ($in['nombre'] ?? ''));
        if (!Entrada::textoValido($nombre, 2, 80)) {
            $errores['nombre'] = 'Nombre inválido: 2 a 80 caracteres, sin caracteres de control.';
        }
        $tipo = (string) ($in['tipo'] ?? 'general');
        if (!in_array($tipo, self::TIPOS_CATEGORIA, true)) {
            $errores['tipo'] = 'Tipo de categoría inválido.';
        }
        $padreTxt = trim((string) ($in['padre_id'] ?? ''));
        $padre = null;
        if ($padreTxt !== '' && $padreTxt !== '0') {
            $padre = Entrada::id($padreTxt);
            if ($padre === null) {
                $errores['padre_id'] = 'Categoría padre inválida.';
            }
        }
        $ordenTxt = trim((string) ($in['orden'] ?? '0'));
        if ($ordenTxt === '') {
            $ordenTxt = '0';
        }
        if (!preg_match('/^\d{1,5}$/D', $ordenTxt) || (int) $ordenTxt > 65535) {
            $errores['orden'] = 'Orden inválido (0 a 65535).';
        }
        if ($errores) {
            throw new ErrorNegocio('Revise los datos de la categoría.', 422, $errores);
        }
        return ['nombre' => $nombre, 'tipo' => $tipo, 'padre_id' => $padre, 'orden' => (int) $ordenTxt];
    }

    /** El padre debe existir y no puede ser la propia categoría ni un descendiente (sin ciclos). */
    private function validarPadre(\PDO $pdo, ?int $padreId, ?int $propia): void
    {
        $err = static fn (string $m) => new ErrorNegocio('Revise los datos de la categoría.', 422, ['padre_id' => $m]);
        if ($padreId === null) {
            return;
        }
        $st = $pdo->prepare('SELECT padre_id FROM categorias WHERE id = ?');
        $actual = $padreId;
        for ($i = 0; $i < 20 && $actual !== null; $i++) {
            if ($propia !== null && $actual === $propia) {
                throw $err('Una categoría no puede ser su propio ancestro.');
            }
            $st->execute([$actual]);
            $fila = $st->fetch();
            if (!$fila) {
                throw $err('La categoría padre no existe.');
            }
            $actual = $fila['padre_id'] === null ? null : (int) $fila['padre_id'];
        }
        if ($actual !== null) {
            throw $err('Jerarquía demasiado profunda.');
        }
    }

    private function validarNombre(string $nombre, int $max, string $que): void
    {
        if (!Entrada::textoValido($nombre, 2, $max)) {
            throw new ErrorNegocio("Revise los datos de la $que.", 422, ['nombre' => "Nombre inválido: 2 a $max caracteres, sin caracteres de control."]);
        }
    }

    /** @return array<string,mixed> fila bloqueada */
    private function bloquear(\PDO $pdo, string $tabla, int $id): array
    {
        if (!in_array($tabla, ['marcas', 'categorias'], true)) {
            throw new \InvalidArgumentException('Tabla no permitida');
        }
        $st = $pdo->prepare("SELECT * FROM $tabla WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        return $st->fetch() ?: throw new ErrorNegocio('No encontrado.', 404);
    }

    private function cambiarActiva(array $actor, string $tabla, string $nombre, int $id, bool $activa): void
    {
        Db::transaction(function (\PDO $pdo) use ($actor, $tabla, $nombre, $id, $activa): void {
            $fila = $this->bloquear($pdo, $tabla, $id);
            if ((int) $fila['activa'] === ($activa ? 1 : 0)) {
                return;
            }
            $pdo->prepare("UPDATE $tabla SET activa = ? WHERE id = ?")->execute([$activa ? 1 : 0, $id]);
            Auditoria::registrar($nombre . ($activa ? '.activar' : '.desactivar'), (int) $actor['id'], (int) $actor['sucursal_id'],
                $tabla, $id, ['activa' => (int) $fila['activa']], ['activa' => $activa ? 1 : 0]);
        });
    }

    private function traducir(\PDOException $e, string $mensaje, string $campo): \Throwable
    {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return new ErrorNegocio($mensaje, 409, [$campo => $mensaje]);
        }
        return $e;
    }
}
