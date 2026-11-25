<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Conexión PDO única por proceso (perezosa) con la configuración de sesión que
 * exige el esquema (docs/BASE_DE_DATOS.md y docs/SEGURIDAD_AUTH.md):
 *
 *  - utf8mb4 / utf8mb4_unicode_ci (charset en el DSN + SET NAMES con collation,
 *    porque el DSN solo fija el charset y la collation por defecto del servidor
 *    para utf8mb4 en MariaDB 10.4 es utf8mb4_general_ci).
 *  - sql_mode estricto y time_zone='-05:00' (Colombia, sin horario de verano)
 *    fijados por la app en CADA conexión: no se controla my.ini en hosting.
 *    Se usa el desplazamiento numérico y no 'America/Bogota' porque las tablas
 *    de zonas horarias de MySQL no vienen cargadas en XAMPP ni en muchos hostings.
 *  - Preparadas reales (EMULATE_PREPARES=false), sin multi-sentencias.
 *
 * Errores: el detalle (código y mensaje del driver) va a error_log; al llamador
 * se le lanza una excepción genérica SIN encadenar la PDOException original,
 * porque su traza incluye los argumentos del constructor (usuario y contraseña).
 *
 * Variables: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS (Env).
 */
final class Db
{
    public const SQL_MODE = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,'
        . 'NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
    public const TIME_ZONE = '-05:00';
    public const CHARSET = 'utf8mb4';
    public const COLLATION = 'utf8mb4_unicode_ci';

    /** Códigos MySQL/MariaDB reintentables: deadlock y lock wait timeout. */
    private const ERRORES_REINTENTABLES = [1213, 1205];

    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::conectar();
        }
        return self::$pdo;
    }

    /** Conexión ya abierta o null (no conecta): para limpiar tras un error. */
    public static function pdoActiva(): ?PDO
    {
        return self::$pdo;
    }

    /** Cierra la conexión del proceso (útil en scripts largos y pruebas). */
    public static function cerrar(): void
    {
        self::$pdo = null;
    }

    private static function conectar(): PDO
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::requerida('DB_NAME');
        $user = Env::requerida('DB_USER');
        $pass = Env::get('DB_PASS', '') ?? '';

        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', (string) $host)
            || !ctype_digit((string) $port)
            || !preg_match('/^[A-Za-z0-9_$]{1,64}$/', $name)) {
            throw new \RuntimeException('Configuración de base de datos inválida (DB_HOST, DB_PORT o DB_NAME).');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, (int) $port, $name, self::CHARSET);

        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $opciones);
            // Sentencias fijas (sin datos externos): exec es seguro aquí.
            $pdo->exec('SET NAMES ' . self::CHARSET . ' COLLATE ' . self::COLLATION);
            $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
            $pdo->exec("SET SESSION time_zone = '" . self::TIME_ZONE . "'");
        } catch (PDOException $e) {
            self::registrar('Fallo al conectar a la base de datos', $e, [$pass]);
            unset($pass);
            // Sin $previous a propósito: la traza de $e contiene las credenciales.
            throw new \RuntimeException('No se pudo conectar a la base de datos. Revise el registro de errores del servidor.');
        }
        unset($pass);
        return $pdo;
    }

    /**
     * Ejecuta $fn dentro de una transacción: commit si termina bien, rollback y
     * relanzar si lanza cualquier Throwable. Devuelve lo que devuelva $fn.
     *
     * - Si ya hay una transacción abierta, $fn participa en ella (no se anida:
     *   MariaDB no tiene transacciones anidadas y un BEGIN implícito haría commit
     *   de la exterior). El commit/rollback queda en manos de la transacción externa.
     * - $reintentos > 0 reintenta ante deadlock (1213) o lock wait timeout (1205).
     *   Solo usarlo si $fn no tiene efectos fuera de la BD (correo, archivos...).
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn, int $reintentos = 0): mixed
    {
        $pdo = self::pdo();

        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }

        $intento = 0;
        while (true) {
            $pdo->beginTransaction();
            try {
                $resultado = $fn($pdo);
                $pdo->commit();
                return $resultado;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (\Throwable $r) {
                        self::registrar('Fallo al hacer rollback', $r);
                    }
                }
                if ($intento < $reintentos && self::esReintentable($e)) {
                    $intento++;
                    usleep(random_int(20_000, 100_000) * $intento); // espera con jitter
                    continue;
                }
                throw $e;
            }
        }
    }

    private static function esReintentable(\Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $codigoDriver = (int) ($e->errorInfo[1] ?? 0);
        return in_array($codigoDriver, self::ERRORES_REINTENTABLES, true) || $e->getCode() === '40001';
    }

    /**
     * Registra el error sin secretos: solo clase, código y mensaje (sin traza,
     * que contiene argumentos). Se ocultan además los valores sensibles dados.
     *
     * @param list<string> $ocultar
     */
    private static function registrar(string $contexto, \Throwable $e, #[\SensitiveParameter] array $ocultar = []): void
    {
        $mensaje = $e->getMessage();
        foreach ($ocultar as $secreto) {
            if ($secreto !== '') {
                $mensaje = str_replace($secreto, '***', $mensaje);
            }
        }
        error_log(sprintf('[Db] %s: %s (código %s): %s', $contexto, get_class($e), (string) $e->getCode(), $mensaje));
    }
}
