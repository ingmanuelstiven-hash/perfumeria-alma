<?php

declare(strict_types=1);

/**
 * Respaldo de la base de datos con mysqldump. Solo CLI.
 *
 * Uso:
 *   php scripts/backup.php [--conservar=N] [--restaurar-prueba]
 *
 *   (sin opciones)       Vuelca la BD (DB_NAME) a backups/perfumeria-<BD>-AAAAMMDD-HHMMSS.sql.gz,
 *                        verifica el archivo y aplica la retención.
 *   --conservar=N        Cuántos respaldos de ESTA base conservar (por defecto 14, mínimo 1).
 *   --restaurar-prueba   NO crea respaldo: restaura el último respaldo de esta base en una BD
 *                        temporal, compara tablas/vistas/triggers/rutinas/eventos con la
 *                        original y borra la temporal (siempre).
 *
 * IMÁGENES: el respaldo es SOLO de la base de datos. Las fotos y comprobantes viven en
 * storage/uploads (ruta relativa en la BD) y deben respaldarse aparte (copia de esa carpeta).
 *
 * Conexión: DB_HOST, DB_PORT, DB_NAME y el usuario DB_BACKUP_USER/DB_BACKUP_PASS (si faltan,
 * DB_ADMIN_* y luego DB_USER/DB_PASS). La prueba de restauración usa DB_ADMIN_* (necesita
 * CREATE/DROP DATABASE). Carpeta de destino: BACKUP_PATH (por defecto backups/ del proyecto;
 * ya está en .gitignore). Binarios: C:\xampp\mysql\bin, MYSQL_BIN_DIR o el PATH.
 *
 * DECISIONES
 *  - Credenciales: la contraseña va en la variable de entorno MYSQL_PWD del PROCESO HIJO
 *    (proc_open con arreglo de argumentos, sin shell). No aparece en la línea de comandos,
 *    ni en la lista de procesos, ni en archivos. Se prefirió a un archivo de opciones
 *    temporal porque en Windows no se pueden fijar permisos de archivo de forma fiable
 *    desde PHP y un fallo dejaría la clave en disco.
 *  - Opciones del volcado: --single-transaction --routines --triggers --events
 *    --default-character-set=utf8mb4 --hex-blob. --single-transaction da consistencia
 *    sin bloquear tablas InnoDB; los DDL concurrentes durante el volcado no se aíslan.
 *  - gzip hecho desde PHP (zlib) en streaming a un .part que se renombra solo si todo salió
 *    bien: nunca queda un respaldo parcial con el nombre final.
 *  - Verificación: código de salida 0 de mysqldump, archivo no vacío, gzip legible de punta
 *    a punta y última línea "-- Dump completed on" (marca de volcado completo).
 *  - Retención: solo borra archivos regulares de backups/ cuyo nombre coincida EXACTAMENTE
 *    con perfumeria-<ESTA BD>-AAAAMMDD-HHMMSS.sql.gz; ordena por la fecha del nombre.
 *
 * Códigos de salida: 0 ok, 1 argumentos, 3 BD/entorno/binarios, 6 el volcado falló o no
 * pasó la verificación, 7 la restauración de prueba falló o no coincide.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/autoload.php';

use App\CliDb;
use App\Env;

const SALIDA_OK = 0;
const SALIDA_ARGS = 1;
const SALIDA_ERROR = 3;
const SALIDA_VOLCADO = 6;
const SALIDA_RESTAURACION = 7;

const MARCA_FIN = '-- Dump completed on';

function salir(string $mensaje, int $codigo): never
{
    fwrite($codigo === SALIDA_OK ? STDOUT : STDERR, $mensaje . PHP_EOL);
    exit($codigo);
}

function info(string $mensaje): void
{
    fwrite(STDOUT, $mensaje . PHP_EOL);
}

const AYUDA = <<<'TXT'
Uso: php scripts/backup.php [--conservar=N] [--restaurar-prueba]

  (sin opciones)      Respalda la BD a backups/perfumeria-<BD>-AAAAMMDD-HHMMSS.sql.gz
  --conservar=N       Respaldos de esta base a conservar (defecto 14, mínimo 1).
  --restaurar-prueba  Restaura el último respaldo en una BD temporal, compara tablas/vistas/
                      triggers/rutinas/eventos y la borra. No crea respaldo nuevo.

IMPORTANTE: solo respalda la BASE DE DATOS. Las imágenes y comprobantes están en
storage/uploads y se respaldan aparte (copie esa carpeta).

Variables: DB_HOST, DB_PORT, DB_NAME, DB_BACKUP_USER/DB_BACKUP_PASS (o DB_ADMIN_*, o DB_USER/DB_PASS),
BACKUP_PATH (defecto backups/), MYSQL_BIN_DIR. La contraseña viaja por MYSQL_PWD del proceso hijo.
Códigos de salida: 0 ok, 1 argumentos, 3 BD/entorno, 6 volcado inválido, 7 restauración de prueba fallida.
TXT;

/** @return array{conservar: int, restaurar: bool} */
function leerArgumentos(array $argv): array
{
    $r = ['conservar' => 14, 'restaurar' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--ayuda' || $arg === '-h' || $arg === '--help') {
            salir(AYUDA, SALIDA_OK);
        } elseif ($arg === '--restaurar-prueba') {
            $r['restaurar'] = true;
        } elseif (preg_match('/^--conservar=(\d{1,4})$/', $arg, $m) && (int) $m[1] >= 1) {
            $r['conservar'] = (int) $m[1];
        } elseif (preg_match('/^--(pass|password|contrasena|clave)(=|$)/i', $arg)) {
            salir('Las credenciales no se aceptan como argumento: use variables de entorno o .env.', SALIDA_ARGS);
        } else {
            salir("Argumento no reconocido o inválido: {$arg}. Use --ayuda (--conservar=N exige N >= 1).", SALIDA_ARGS);
        }
    }
    return $r;
}

function carpetaBackups(): string
{
    $ruta = Env::get('BACKUP_PATH', 'backups') ?: 'backups';
    $absoluta = (bool) preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/])~', $ruta);
    $ruta = $absoluta ? $ruta : dirname(__DIR__) . DIRECTORY_SEPARATOR . $ruta;
    if (!is_dir($ruta) && !@mkdir($ruta, 0700, true) && !is_dir($ruta)) {
        salir('No se pudo crear la carpeta de respaldos.', SALIDA_ERROR);
    }
    $real = realpath($ruta);
    if ($real === false || !is_writable($real)) {
        salir('La carpeta de respaldos no existe o no es escribible.', SALIDA_ERROR);
    }
    return $real;
}

function patronArchivo(string $bd): string
{
    return '/^perfumeria-' . preg_quote($bd, '/') . '-(\d{8})-(\d{6})\.sql\.gz$/';
}

/** @return list<string> nombres de archivo de respaldo de esta BD, del más nuevo al más viejo */
function respaldosDe(string $carpeta, string $bd): array
{
    $lista = [];
    foreach (scandir($carpeta) ?: [] as $f) {
        if (preg_match(patronArchivo($bd), $f, $m)
            && is_file($carpeta . DIRECTORY_SEPARATOR . $f) && !is_link($carpeta . DIRECTORY_SEPARATOR . $f)) {
            $lista[$f] = $m[1] . $m[2];
        }
    }
    arsort($lista, SORT_STRING);
    return array_keys($lista);
}

function bin(string $nombre): string
{
    $ruta = CliDb::buscarBinario($nombre);
    if ($ruta === null) {
        salir("No se encontró {$nombre} (busqué en C:\\xampp\\mysql\\bin, MYSQL_BIN_DIR y el PATH).", SALIDA_ERROR);
    }
    return $ruta;
}

/** Últimos $max caracteres de un archivo de texto pequeño (stderr de un hijo). */
function leerCola(string $ruta, int $max = 1500): string
{
    $t = @file_get_contents($ruta);
    return $t === false ? '' : trim(substr($t, -$max));
}

function nulo(): string
{
    return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
}

/**
 * Lee un .sql.gz completo comprobando que es legible.
 * @return array{bytes: int, cola: string}
 */
function inspeccionarGz(string $ruta, ?callable $porTrozo = null): array
{
    $gz = gzopen($ruta, 'rb');
    if ($gz === false) {
        throw new RuntimeException('No se pudo abrir el archivo comprimido.');
    }
    $bytes = 0;
    $cola = '';
    try {
        while (!gzeof($gz)) {
            $t = gzread($gz, 1 << 20);
            if ($t === false) {
                throw new RuntimeException('El gzip está corrupto (error de lectura).');
            }
            $bytes += strlen($t);
            $cola = substr($cola . $t, -1024);
            if ($porTrozo !== null) {
                $porTrozo($t);
            }
        }
    } finally {
        gzclose($gz);
    }
    return ['bytes' => $bytes, 'cola' => $cola];
}

function crearRespaldo(array $cred, string $carpeta, string $mysqldump): string
{
    date_default_timezone_set('America/Bogota');
    $final = $carpeta . DIRECTORY_SEPARATOR . sprintf('perfumeria-%s-%s.sql.gz', $cred['name'], date('Ymd-His'));
    if (file_exists($final)) {
        throw new RuntimeException('Ya existe un respaldo con ese nombre (mismo segundo). Reintente.');
    }
    $parcial = $final . '.part';
    $errArchivo = tempnam(sys_get_temp_dir(), 'bkerr');
    if ($errArchivo === false) {
        throw new RuntimeException('No se pudo crear un archivo temporal.');
    }

    $cmd = [
        $mysqldump,
        '--host=' . $cred['host'], '--port=' . $cred['port'], '--protocol=tcp', '--user=' . $cred['user'],
        '--single-transaction', '--routines', '--triggers', '--events',
        '--default-character-set=utf8mb4', '--hex-blob',
        '--', $cred['name'],
    ];
    $gz = null;
    $proc = null;
    try {
        $proc = proc_open($cmd, [0 => ['file', nulo(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errArchivo, 'w']],
            $pipes, null, CliDb::entornoHijo($cred['pass']));
        if (!is_resource($proc)) {
            throw new RuntimeException('No se pudo iniciar mysqldump.');
        }
        $gz = gzopen($parcial, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('No se pudo crear el archivo de respaldo.');
        }
        @chmod($parcial, 0600);
        $cola = '';
        $crudo = 0;
        while (!feof($pipes[1])) {
            $t = fread($pipes[1], 1 << 16);
            if ($t === false) {
                break;
            }
            if ($t === '') {
                continue;
            }
            $crudo += strlen($t);
            $cola = substr($cola . $t, -1024);
            if (gzwrite($gz, $t) === false) {
                throw new RuntimeException('Error al escribir el respaldo (¿disco lleno?).');
            }
        }
        fclose($pipes[1]);
        gzclose($gz);
        $gz = null;
        $codigo = proc_close($proc);
        $proc = null;
        if ($codigo !== 0) {
            throw new RuntimeException("mysqldump terminó con código {$codigo}: " . (leerCola($errArchivo) ?: '(sin mensaje)'));
        }
        if ($crudo === 0) {
            throw new RuntimeException('mysqldump no produjo salida.');
        }
        if (!str_contains($cola, MARCA_FIN)) {
            throw new RuntimeException("El volcado no termina con la marca '" . MARCA_FIN . "' (incompleto).");
        }
        // Verificación independiente leyendo el .gz ya escrito.
        $v = inspeccionarGz($parcial);
        if ($v['bytes'] !== $crudo || !str_contains($v['cola'], MARCA_FIN)) {
            throw new RuntimeException('La verificación del archivo comprimido falló (tamaño o marca final).');
        }
        if (!rename($parcial, $final)) {
            throw new RuntimeException('No se pudo renombrar el respaldo terminado.');
        }
        info(sprintf('Respaldo creado: %s (%s KiB comprimido, %s KiB de SQL).', basename($final),
            number_format(filesize($final) / 1024, 1), number_format($crudo / 1024, 1)));
        return $final;
    } catch (Throwable $e) {
        if ($gz !== null) {
            @gzclose($gz);
        }
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
        @unlink($parcial);
        throw $e;
    } finally {
        @unlink($errArchivo);
    }
}

/** @return int cuántos se borraron */
function aplicarRetencion(string $carpeta, string $bd, int $conservar): int
{
    $borrados = 0;
    foreach (array_slice(respaldosDe($carpeta, $bd), $conservar) as $f) {
        $ruta = $carpeta . DIRECTORY_SEPARATOR . $f;
        // Defensa extra: debe estar directamente dentro de la carpeta, ser archivo regular y coincidir con el patrón.
        if (!preg_match(patronArchivo($bd), $f) || !is_file($ruta) || is_link($ruta) || realpath(dirname($ruta)) !== $carpeta) {
            continue;
        }
        if (@unlink($ruta)) {
            $borrados++;
            info("Retención: borrado {$f}");
        } else {
            fwrite(STDERR, "Aviso: no se pudo borrar {$f}" . PHP_EOL);
        }
    }
    return $borrados;
}

/** @return array<string,int> */
function contarObjetos(PDO $pdo, string $bd): array
{
    $consultas = [
        'tablas'   => "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
        'vistas'   => 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?',
        'triggers' => 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
        'rutinas'  => 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?',
        'eventos'  => 'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?',
    ];
    $r = [];
    foreach ($consultas as $k => $sql) {
        $st = $pdo->prepare($sql);
        $st->execute([$bd]);
        $r[$k] = (int) $st->fetchColumn();
    }
    return $r;
}

function restaurarPrueba(array $credAdmin, string $carpeta, string $mysql): void
{
    $bd = $credAdmin['name'];
    $ultimo = respaldosDe($carpeta, $bd)[0] ?? null;
    if ($ultimo === null) {
        salir("No hay respaldos de '{$bd}' en {$carpeta}. Ejecute primero: php scripts/backup.php", SALIDA_ERROR);
    }
    $ruta = $carpeta . DIRECTORY_SEPARATOR . $ultimo;
    info("Restaurando {$ultimo} en una base temporal...");

    $pdo = CliDb::pdo($credAdmin, null);
    $temp = 'perfu_rt_' . bin2hex(random_bytes(5));
    if (!CliDb::nombreBdValido($temp) || $temp === $bd || CliDb::existeBd($pdo, $temp)) {
        throw new RuntimeException('Nombre de base temporal inválido o en uso.');
    }
    $creada = false;
    $errArchivo = tempnam(sys_get_temp_dir(), 'rterr');
    $proc = null;
    try {
        // $temp cumple ^[A-Za-z0-9_]+$ (validado arriba): seguro entre acentos graves.
        $pdo->exec("CREATE DATABASE `{$temp}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $creada = true;

        $cmd = [
            $mysql, '--host=' . $credAdmin['host'], '--port=' . $credAdmin['port'], '--protocol=tcp',
            '--user=' . $credAdmin['user'], '--default-character-set=utf8mb4', '--database=' . $temp,
        ];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', nulo(), 'w'], 2 => ['file', $errArchivo, 'w']],
            $pipes, null, CliDb::entornoHijo($credAdmin['pass']));
        if (!is_resource($proc)) {
            throw new RuntimeException('No se pudo iniciar mysql.');
        }
        $roto = false;
        try {
            $vista = inspeccionarGz($ruta, function (string $t) use ($pipes, &$roto): void {
                if (!$roto && @fwrite($pipes[0], $t) === false) {
                    $roto = true;   // mysql terminó antes (error de SQL): se detalla abajo con su stderr
                }
            });
        } finally {
            @fclose($pipes[0]);
        }
        $codigo = proc_close($proc);
        $proc = null;
        if ($codigo !== 0 || $roto) {
            throw new RuntimeException("mysql terminó con código {$codigo} al restaurar: " . (leerCola($errArchivo) ?: '(sin mensaje)'));
        }
        info(sprintf('  restaurados %s KiB de SQL.', number_format($vista['bytes'] / 1024, 1)));

        $origen = contarObjetos($pdo, $bd);
        $copia = contarObjetos($pdo, $temp);
        $diferencias = [];
        foreach ($origen as $k => $v) {
            $ok = $v === $copia[$k];
            info(sprintf('  %-9s original=%d  restaurada=%d  %s', $k, $v, $copia[$k], $ok ? 'OK' : 'DIFERENTE'));
            if (!$ok) {
                $diferencias[] = $k;
            }
        }
        if ($diferencias) {
            throw new RuntimeException('La restauración no coincide con la original en: ' . implode(', ', $diferencias)
                . ' (si la BD cambió después del respaldo, repita el respaldo y la prueba).');
        }
        info('Prueba de restauración correcta.');
    } finally {
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
        if ($errArchivo !== false) {
            @unlink($errArchivo);
        }
        if ($creada) {
            try {
                $pdo->exec("DROP DATABASE IF EXISTS `{$temp}`");
                info("  base temporal {$temp} borrada.");
            } catch (Throwable $e) {
                fwrite(STDERR, "ATENCIÓN: no se pudo borrar la base temporal {$temp}; bórrela a mano." . PHP_EOL);
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Programa principal
// -----------------------------------------------------------------------------
if (!extension_loaded('pdo_mysql') || !extension_loaded('zlib')) {
    salir('Se requieren las extensiones pdo_mysql y zlib.', SALIDA_ERROR);
}

$a = leerArgumentos($argv);

try {
    $credRespaldo = CliDb::credenciales('backup');
    $credAdmin = CliDb::credenciales('admin');
    $bd = $credRespaldo['name'];
    $sinBd = CliDb::pdo($credRespaldo, null);
    if (!CliDb::existeBd($sinBd, $bd)) {
        salir("La base de datos '{$bd}' no existe en el servidor.", SALIDA_ERROR);
    }
    unset($sinBd);
    $carpeta = carpetaBackups();
} catch (RuntimeException $e) {
    salir('Error: ' . $e->getMessage(), SALIDA_ERROR);
}

if ($a['restaurar']) {
    $mysql = bin('mysql');
    try {
        restaurarPrueba($credAdmin, $carpeta, $mysql);
    } catch (RuntimeException | PDOException $e) {
        salir('FALLÓ LA PRUEBA DE RESTAURACIÓN: ' . CliDb::mensajeSeguro($e, [$credAdmin['pass']]), SALIDA_RESTAURACION);
    }
    exit(SALIDA_OK);
}

$mysqldump = bin('mysqldump');
try {
    crearRespaldo($credRespaldo, $carpeta, $mysqldump);
} catch (Throwable $e) {
    salir('FALLÓ EL RESPALDO (no se dejó archivo parcial, ni se aplicó retención): '
        . CliDb::mensajeSeguro($e, [$credRespaldo['pass']]), SALIDA_VOLCADO);
}
$n = aplicarRetencion($carpeta, $bd, $a['conservar']);
info(sprintf('Retención: se conservan los %d más recientes de %s (%d borrados). Recuerde respaldar storage/uploads aparte.',
    $a['conservar'], $bd, $n));
exit(SALIDA_OK);
