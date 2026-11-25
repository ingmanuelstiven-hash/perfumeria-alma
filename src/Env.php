<?php

declare(strict_types=1);

namespace App;

/**
 * Carga de configuración desde `.env` (raíz del proyecto) sin dependencias.
 *
 * Decisiones:
 *  - Parser propio y mínimo: KEY=VALUE, comentarios con #, comillas simples o
 *    dobles opcionales, prefijo `export ` tolerado. NO interpola variables ni
 *    ejecuta nada (a diferencia de parse_ini_file, que interpreta constantes,
 *    `yes/no/null`, y rompe con ciertos caracteres en contraseñas).
 *  - Las variables reales del entorno (getenv / $_ENV / $_SERVER) tienen
 *    prioridad sobre el archivo: permite inyectar configuración en pruebas y
 *    en producción sin tocar el archivo.
 *  - No se llama a putenv(): no es seguro entre hilos en Apache ZTS (XAMPP)
 *    y expondría los secretos a procesos hijo. Los valores del archivo se
 *    guardan solo en memoria de esta clase.
 */
final class Env
{
    /** @var array<string,string>|null Valores leídos del archivo (null = aún no cargado). */
    private static ?array $valores = null;

    private static ?string $rutaArchivo = null;

    /**
     * Variables que, si existen en el entorno real, indican que la app está
     * configurada por entorno y el .env puede faltar.
     */
    private const VARIABLES_MINIMAS = ['DB_NAME', 'DB_HOST', 'APP_ENV'];

    /** Permite indicar otra ruta (p. ej. en pruebas). Fuerza recarga. */
    public static function usarArchivo(string $ruta): void
    {
        self::$rutaArchivo = $ruta;
        self::$valores = null;
    }

    /**
     * Devuelve el valor de la variable o $default si no está definida.
     * Prioridad: entorno real > .env > $default.
     */
    public static function get(string $clave, ?string $default = null): ?string
    {
        $real = self::desdeEntorno($clave);
        if ($real !== null) {
            return $real;
        }
        self::cargar();
        return self::$valores[$clave] ?? $default;
    }

    /** Igual que get() pero falla si la variable no existe o está vacía. */
    public static function requerida(string $clave): string
    {
        $valor = self::get($clave);
        if ($valor === null || $valor === '') {
            throw new \RuntimeException("Falta la variable de configuración obligatoria {$clave} (.env o entorno).");
        }
        return $valor;
    }

    private static function desdeEntorno(string $clave): ?string
    {
        $v = getenv($clave);
        if ($v !== false) {
            return $v;
        }
        if (isset($_ENV[$clave]) && is_string($_ENV[$clave])) {
            return $_ENV[$clave];
        }
        if (isset($_SERVER[$clave]) && is_string($_SERVER[$clave])) {
            return $_SERVER[$clave];
        }
        return null;
    }

    private static function cargar(): void
    {
        if (self::$valores !== null) {
            return;
        }
        $ruta = self::$rutaArchivo ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($ruta)) {
            foreach (self::VARIABLES_MINIMAS as $k) {
                if (self::desdeEntorno($k) !== null) {
                    self::$valores = [];   // Configurado por entorno: sin archivo.
                    return;
                }
            }
            throw new \RuntimeException(
                'No se encontró el archivo .env en la raíz del proyecto y no hay variables de entorno '
                . 'de configuración (' . implode(', ', self::VARIABLES_MINIMAS) . '). '
                . 'Copie .env.example a .env y complete los valores.'
            );
        }
        if (!is_readable($ruta)) {
            throw new \RuntimeException('El archivo .env existe pero no se puede leer (revise permisos).');
        }

        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new \RuntimeException('No se pudo leer el archivo .env.');
        }
        self::$valores = self::parsear($contenido);
    }

    /**
     * Parser KEY=VALUE. Público para poder probarlo de forma aislada.
     *
     * @return array<string,string>
     */
    public static function parsear(string $contenido): array
    {
        // Quitar BOM UTF-8 (frecuente al editar con el Bloc de notas de Windows).
        if (strncmp($contenido, "\xEF\xBB\xBF", 3) === 0) {
            $contenido = substr($contenido, 3);
        }

        $resultado = [];
        $lineas = preg_split('/\r\n|\n|\r/', $contenido) ?: [];

        foreach ($lineas as $n => $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#') {
                continue;
            }
            if (strncmp($linea, 'export ', 7) === 0) {
                $linea = ltrim(substr($linea, 7));
            }
            $pos = strpos($linea, '=');
            if ($pos === false) {
                throw new \RuntimeException('Línea ' . ($n + 1) . ' de .env inválida: falta "=".');
            }
            $clave = rtrim(substr($linea, 0, $pos));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $clave)) {
                throw new \RuntimeException('Línea ' . ($n + 1) . ' de .env inválida: nombre de variable no permitido.');
            }
            $resultado[$clave] = self::valor(ltrim(substr($linea, $pos + 1)), $n + 1);
        }
        return $resultado;
    }

    private static function valor(string $crudo, int $numLinea): string
    {
        if ($crudo === '') {
            return '';
        }
        $q = $crudo[0];
        if ($q === '"' || $q === "'") {
            $len = strlen($crudo);
            $salida = '';
            for ($i = 1; $i < $len; $i++) {
                $c = $crudo[$i];
                if ($q === '"' && $c === '\\' && $i + 1 < $len) {
                    $sig = $crudo[++$i];
                    $salida .= match ($sig) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        default => $sig,   // \" \\ y cualquier otro: literal
                    };
                    continue;
                }
                if ($c === $q) {
                    $resto = trim(substr($crudo, $i + 1));
                    if ($resto !== '' && $resto[0] !== '#') {
                        throw new \RuntimeException("Línea {$numLinea} de .env inválida: texto tras la comilla de cierre.");
                    }
                    return $salida;
                }
                $salida .= $c;
            }
            throw new \RuntimeException("Línea {$numLinea} de .env inválida: comilla sin cerrar.");
        }
        // Sin comillas: un # precedido de espacio inicia comentario.
        $corte = preg_split('/\s+#/', $crudo, 2);
        return rtrim($corte[0]);
    }
}
