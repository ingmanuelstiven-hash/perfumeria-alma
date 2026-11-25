<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Credenciales y conexión para los scripts de mantenimiento (migrate.php, backup.php).
 *
 * Esos scripts necesitan más privilegios que la app (DDL, CREATE/DROP DATABASE,
 * LOCK TABLES, TRIGGER...), así que admiten variables propias que, si faltan,
 * caen en las de la app (Env, igual que Db):
 *
 *   migraciones y restauración de prueba:  DB_ADMIN_USER / DB_ADMIN_PASS
 *   respaldo (mysqldump):                  DB_BACKUP_USER / DB_BACKUP_PASS
 *                                          (si faltan, DB_ADMIN_*, y si no, DB_USER/DB_PASS)
 *   comunes:                               DB_HOST, DB_PORT, DB_NAME
 *
 * Nunca se ponen contraseñas en argumentos de procesos: ver CliDb::entornoHijo().
 */
final class CliDb
{
    /**
     * @return array{host: string, port: int, name: string, user: string, pass: string}
     * @throws \RuntimeException con mensaje seguro (sin secretos)
     */
    public static function credenciales(string $rol): array
    {
        $host = Env::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
        $port = Env::get('DB_PORT', '3306') ?? '3306';
        $name = Env::requerida('DB_NAME');

        $user = null;
        $pass = null;
        $prefijos = $rol === 'backup' ? ['DB_BACKUP', 'DB_ADMIN'] : ['DB_ADMIN'];
        foreach ($prefijos as $p) {
            $u = Env::get($p . '_USER');
            if ($u !== null && $u !== '') {
                $user = $u;
                $pass = Env::get($p . '_PASS', '') ?? '';
                break;
            }
        }
        if ($user === null) {
            $user = Env::requerida('DB_USER');
            $pass = Env::get('DB_PASS', '') ?? '';
        }

        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) || !ctype_digit($port)
            || !self::nombreBdValido($name) || str_contains($user, "\0")) {
            throw new \RuntimeException('Configuración de base de datos inválida (DB_HOST, DB_PORT, DB_NAME o usuario).');
        }
        return ['host' => $host, 'port' => (int) $port, 'name' => $name, 'user' => $user, 'pass' => (string) $pass];
    }

    public static function nombreBdValido(string $nombre): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_$]{1,64}$/', $nombre);
    }

    /**
     * Conexión PDO. Con $nombre = null se conecta sin base (para comprobar su
     * existencia o crear/borrar bases temporales).
     *
     * @param array{host: string, port: int, name: string, user: string, pass: string} $c
     */
    public static function pdo(array $c, ?string $nombre): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], Db::CHARSET)
            . ($nombre !== null ? ';dbname=' . $nombre : '');
        try {
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT         => false,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
            $pdo->exec('SET NAMES ' . Db::CHARSET . ' COLLATE ' . Db::COLLATION);
            $pdo->exec("SET SESSION sql_mode = '" . Db::SQL_MODE . "'");
            $pdo->exec("SET SESSION time_zone = '" . Db::TIME_ZONE . "'");
        } catch (PDOException $e) {
            // Sin $previous: su traza contiene usuario y contraseña.
            throw new \RuntimeException('No se pudo conectar al servidor de base de datos: '
                . self::mensajeSeguro($e, [$c['pass']]));
        }
        return $pdo;
    }

    /** ¿Existe la base $nombre en el servidor? (consulta preparada). */
    public static function existeBd(PDO $sinBd, string $nombre): bool
    {
        $st = $sinBd->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([$nombre]);
        return (int) $st->fetchColumn() > 0;
    }

    /** "[código] mensaje" del driver, ocultando secretos. */
    public static function mensajeSeguro(\Throwable $e, #[\SensitiveParameter] array $ocultar = []): string
    {
        $msg = $e->getMessage();
        foreach ($ocultar as $secreto) {
            if ($secreto !== '') {
                $msg = str_replace($secreto, '***', $msg);
            }
        }
        return $msg;
    }

    /**
     * Entorno para un proceso hijo (mysql/mysqldump) con la contraseña en MYSQL_PWD.
     * proc_open reemplaza todo el entorno cuando se le pasa un arreglo, por eso se
     * parte del entorno actual (en Windows hace falta SystemRoot, PATH, etc.).
     * La contraseña no aparece en la línea de comandos ni en archivos.
     *
     * @return array<string,string>
     */
    public static function entornoHijo(#[\SensitiveParameter] string $pass): array
    {
        $env = getenv();
        // Evita que un MYSQL_HOME/MYSQL_PWD heredado altere la conexión.
        unset($env['MYSQL_PWD'], $env['MYSQL_HOME'], $env['MYSQL_TCP_PORT']);
        if ($pass !== '') {
            $env['MYSQL_PWD'] = $pass;
        }
        return $env;
    }

    /** Busca un ejecutable de XAMPP/PATH (mysql, mysqldump, ...). */
    public static function buscarBinario(string $nombre): ?string
    {
        $exe = PHP_OS_FAMILY === 'Windows' ? $nombre . '.exe' : $nombre;
        $candidatos = [];
        $override = Env::get('MYSQL_BIN_DIR');
        if ($override !== null && $override !== '') {
            $candidatos[] = rtrim($override, '\\/');
        }
        $candidatos[] = 'C:\\xampp\\mysql\\bin';
        $candidatos[] = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin';
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $candidatos[] = rtrim($dir, '\\/');
            }
        }
        foreach ($candidatos as $dir) {
            $ruta = $dir . DIRECTORY_SEPARATOR . $exe;
            if (is_file($ruta)) {
                return $ruta;
            }
        }
        return null;
    }
}
