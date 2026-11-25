<?php

declare(strict_types=1);

namespace App;

/**
 * Vistas PHP simples. Regla: TODA salida dinámica pasa por $v->e() (htmlspecialchars
 * con ENT_QUOTES|ENT_SUBSTITUTE, UTF-8), también dentro de atributos.
 * Las plantillas reciben $d (datos) y $v (esta clase); no se usa extract().
 */
final class View
{
    private function __construct(private readonly string $basePath, private readonly string $csrf)
    {
    }

    /** @param array<string,mixed> $d */
    public static function render(string $plantilla, array $d = [], string $csrf = ''): string
    {
        if (!preg_match('/^[a-z_]+$/', $plantilla)) {
            throw new \InvalidArgumentException('Plantilla inválida');
        }
        $basePath = '';
        try {
            $basePath = Config::cargar()->basePath;
        } catch (\Throwable) {
            // Página de error con configuración inválida: rutas relativas a la raíz.
        }
        $v = new self($basePath, $csrf);
        $archivo = __DIR__ . '/Views/' . $plantilla . '.php';
        ob_start();
        try {
            (static function (string $__archivo, array $d, View $v): void {
                require $__archivo;
            })($archivo, $d, $v);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    public function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** URL interna escapada para atributo. */
    public function url(string $ruta): string
    {
        return $this->e($this->basePath . $ruta);
    }

    public function csrf(): string
    {
        return $this->e($this->csrf);
    }

    public function campoCsrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->csrf() . '">';
    }
}
