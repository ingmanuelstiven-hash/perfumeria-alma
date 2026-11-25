# Perfumería

Sistema de gestión para una perfumería, desarrollado en PHP y MySQL/MariaDB sobre XAMPP (Windows).

## Requisitos

- XAMPP con PHP 8.2 (extensiones `pdo_mysql`, `mbstring`, `sodium`, `gd`)
- MariaDB 10.4 o superior
- Composer (incluido como `tools/composer.phar`): `C:\xampp\php\php.exe tools\composer.phar install` crea `vendor/` (`firebase/php-jwt`)
- Apache con `mod_rewrite` y `mod_headers` habilitados (o el servidor embebido de PHP para desarrollo)

## Configuración

1. Copiar `.env.example` a `.env` (fuera de Git) y completar los valores.
2. Generar **dos secretos distintos** (hexadecimal de 64 caracteres = 32 bytes), uno para `JWT_SECRET` y otro para `CSRF_SECRET`:
   ```
   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"
   ```
   Si falta alguno, es más corto, no es hexadecimal o los dos son iguales, la aplicación **no arranca**: responde 500 genérico y deja el motivo en `logs/app-AAAAMMDD.log`.
3. `APP_URL` debe ser la URL exacta de acceso (esquema, host, puerto y ruta base). La app rechaza otros `Host` y valida el `Origin` de los formularios contra ella.
4. `APP_ENV=production` exige `APP_URL` con `https://`; las peticiones por http se redirigen (GET) o se rechazan.
5. Crear el primer administrador: `C:\xampp\php\php.exe scripts\crear_admin.php` (pide la contraseña sin eco).

Variables nuevas del Sprint 1A (ver `.env.example`):

| Variable | Obligatoria | Uso |
|---|---|---|
| `CSRF_SECRET` | sí | HMAC de los tokens CSRF |
| `JWT_SECRET_ANTERIOR` | no | Solo durante una rotación de `JWT_SECRET`: se aceptan unos minutos los tokens firmados con la clave previa |
| `LOGIN_MAX_INTENTOS_IP` | no (20) | Fallos de login por IP en 15 min antes del bloqueo temporal |
| `REFRESH_MAX_POR_MINUTO` | no (60) | Peticiones a `/auth/refresh` por IP y minuto |

Los fallos permitidos por usuario (5) y la duración del bloqueo (15 min) están en la tabla `config` (`login_max_intentos`, `login_bloqueo_minutos`).

## Levantar el entorno local

**Opción A: servidor embebido de PHP** (desarrollo; atiende una petición a la vez):
```
C:\xampp\php\php.exe -S 127.0.0.1:8099 -t public public/index.php
```
con `APP_ENV=local` y `APP_URL=http://127.0.0.1:8099`. Abrir `http://127.0.0.1:8099/login`.

**Opción B: Apache de XAMPP** con un VirtualHost cuyo `DocumentRoot` sea `public/` (recomendado), por ejemplo en `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:
```apache
Listen 127.0.0.1:8080
<VirtualHost 127.0.0.1:8080>
    ServerName localhost
    DocumentRoot "C:/xampp/perfumeria/public"
    <Directory "C:/xampp/perfumeria/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
con `APP_URL=http://localhost:8080`. También funciona bajo una subcarpeta (`Alias /perfumeria "C:/xampp/perfumeria/public"`, `RewriteBase /perfumeria/` en `public/.htaccess` y `APP_URL=http://localhost/perfumeria`): la ruta base de rutas y cookies se toma de `APP_URL`. El `.htaccess` raíz niega el acceso web al resto del proyecto y `public/.htaccess` envía todo a `index.php`.

**Cookies en local.** Con `APP_ENV=local`, petición por http y `APP_URL` de loopback (exactamente `localhost`, `127.0.0.1` o `[::1]`; un nombre como `perfumeria.localhost` no cuenta), las cookies se llaman `at`, `rt` y `csrf` y van **sin** `Secure`, porque algunos navegadores no fijan cookies `Secure`/`__Host-` sobre http. En cualquier otro caso (producción o HTTPS) son `__Host-at`, `__Secure-rt` y `__Host-csrf` con `Secure`. Desde otro equipo de la LAN por IP y sin HTTPS la app responde 403: hay que servir HTTPS (p. ej. `mkcert`). Riesgos: `docs/SEGURIDAD_AUTH.md` §11.

## Rutas del Sprint 1A

| Ruta | Acceso |
|---|---|
| `GET /login`, `POST /login` | público (CSRF previo a la sesión) |
| `POST /auth/refresh` | cookie del refresh + CSRF |
| `POST /logout` | sesión (aunque el access haya expirado) + CSRF |
| `GET /` (panel), `GET /me` (JSON: usuario y permisos) | sesión |
| `GET /cuenta/password`, `POST /cuenta/password` | sesión (+ CSRF) |
| `GET /admin/usuarios` | permiso `usuarios.ver` |
| `POST /admin/usuarios`, `/admin/usuarios/{id}/rol`, `/activo`, `/desbloquear`, `POST /admin/bloqueos/ip` | permiso `usuarios.gestionar` + CSRF |

Las peticiones JSON (`Accept: application/json`) envían el token CSRF en la cabecera `X-CSRF-Token`; los formularios, en el campo `_csrf`.

## Rutas del Sprint 1B (catálogo interno de inventario)

Todas exigen sesión. Las que escriben exigen además CSRF. Lectura y escritura se deciden en el servidor con los permisos reales de la tabla `permisos`; **los costos solo se consultan y se devuelven con `inventario.ver_costos`** (admin y contador; el empleado no los recibe ni en HTML ni en JSON).

| Ruta | Permiso | Notas |
|---|---|---|
| `GET /inventario/articulos` | `articulos.ver` | Filtros `q` (SKU, nombre, marca o categoría), `tipo`, `activo` (`1` por defecto, `0`, `todos`), `pagina` (20 por página). Existencias con `inventario.ver`; costo promedio y último costo con `inventario.ver_costos` |
| `GET /inventario/articulos/nuevo?tipo=` | `articulos.gestionar` | Formulario (esencia, producto, envase, insumo) |
| `POST /inventario/articulos` | `articulos.gestionar` | Alta: crea el subtipo y una fila de `existencias` (saldo 0) por sucursal activa en una transacción. `sku_auto=1` genera `ESE-/PRD-/ENV-/INS-` + consecutivo `SKU`. Saldo inicial opcional (no esencias) con `saldo_inicial` + `costo_unitario`: exige además `inventario.ajustar` e `inventario.ver_costos` y se registra como movimiento `saldo_inicial` |
| `GET /inventario/articulos/{id}` | `articulos.ver` | Detalle, existencias por sucursal y, en esencias, sus recipientes |
| `GET /inventario/articulos/{id}/editar`, `POST /inventario/articulos/{id}` | `articulos.gestionar` | Edición completa (se reemplazan todos los campos). Tipo, SKU y unidad de medida solo cambian si el ítem no tiene historia (movimientos, saldo, recipientes o documentos) |
| `POST /inventario/articulos/{id}/activo` | `articulos.gestionar` | `activo=0|1`: desactivación lógica |
| `GET /inventario/recipientes` | `inventario.ver` | Filtros `q`, `esencia_id`, `estado`, `pagina`. Gramos sí; costos nunca |
| `GET /inventario/recipientes/nuevo`, `POST /inventario/recipientes` | `articulos.gestionar` | Código vacío = `R-000045` autogenerado. Saldo inicial en gramos (`gramos_iniciales` + `costo_g`) con `inventario.ajustar` + `inventario.ver_costos`: movimiento `saldo_inicial` y costo promedio ponderado |
| `GET /inventario/recipientes/{id}/editar`, `POST /inventario/recipientes/{id}`, `POST /inventario/recipientes/{id}/activo` | `articulos.gestionar` | Descripción, capacidad, tara, ubicación; baja (solo si está vacío) y reactivación |
| `GET /admin/marcas`, `GET /admin/categorias` | `articulos.ver` | JSON o HTML |
| `POST /admin/marcas`, `/admin/marcas/{id}` (renombrar), `/admin/marcas/{id}/activo` | `articulos.gestionar` | |
| `POST /admin/categorias`, `/admin/categorias/{id}`, `/admin/categorias/{id}/activo` | `articulos.gestionar` | Jerarquía sin ciclos; el `slug` se genera al crear y no cambia |

Errores: 422 (datos inválidos, con `errores` por campo), 409 (SKU, código o nombre duplicado; cambio no permitido por historia), 403 (sin permiso o CSRF), 404. Fotos y códigos de barras quedan para el Sprint 1C: los artículos pueden existir sin código y `v_control_articulos` los reporta.

## Pruebas

```
C:\xampp\php\php.exe tests\run.php              # crea perfu_s1a_test, levanta php -S en 127.0.0.1:8099, prueba y borra la BD
C:\xampp\php\php.exe tests\run.php --conservar  # no borra la BD al terminar
```
Incluye los casos del Sprint 1A (`tests/casos.php`) y del 1B (`tests/casos_1b.php`; las altas concurrentes usan procesos hijos `tests/aux_articulo_concurrente.php`). Usa `root` sin contraseña de XAMPP por defecto (`TEST_DB_USER` / `TEST_DB_PASS` para otro usuario) y secretos aleatorios por entorno: no necesita `.env` y no toca `sistema_perfu`. Necesita libres los puertos 8099 y 8097.

## Aplicar migraciones

Las migraciones están en `database/migrations/` y los datos iniciales en `database/seeds/`. Las aplica el runner `scripts/migrate.php` (solo CLI) sobre una base ya creada y vacía (utf8mb4 / utf8mb4_unicode_ci); el script no la crea. Necesita un usuario con DDL: defínalo con `DB_ADMIN_USER` / `DB_ADMIN_PASS` (si faltan usa `DB_USER` / `DB_PASS`; el usuario de la app no debe tener DDL).

```
php scripts/migrate.php estado                 # aplicadas / pendientes; error si cambió el SHA-256 de una ya aplicada
php scripts/migrate.php aplicar --dry-run      # muestra qué haría, sin ejecutar
php scripts/migrate.php aplicar --seeds        # aplica las pendientes y luego los seeds
php scripts/migrate.php adoptar --confirmo     # BD cargada a mano con mysql: registra las migraciones sin ejecutarlas
```

- Cada migración se registra en la tabla `migraciones` con su SHA-256. Una migración ya aplicada no se edita: los cambios van en un archivo nuevo (`0003_...sql`).
- MariaDB hace commit implícito en cada DDL: no hay rollback. Si una sentencia falla, el runner se detiene, no registra el archivo e indica cuál falló (número, línea y texto); las anteriores del mismo archivo quedan aplicadas y hay que revisarlas a mano.
- Los seeds son repetibles y no se registran. Algunos restablecen valores de fábrica (p. ej. nombre de la sucursal 1, descripciones de permisos): úselos al instalar o actualizar, no como rutina.
- `--dir=RUTA` usa otra carpeta con `migrations/` y `seeds/` (pruebas).
- Códigos de salida: 0 ok, 1 argumentos, 3 BD/entorno, 4 integridad, 5 fallo SQL.

Resultado esperado tras aplicar todo: 63 tablas y 11 vistas.

## Respaldo de la base de datos

`scripts/backup.php` (solo CLI) vuelca la base con `mysqldump` (busca en `C:\xampp\mysql\bin` o en el PATH) a `backups/perfumeria-<BD>-AAAAMMDD-HHMMSS.sql.gz`. Carpeta ignorada por git; se puede cambiar con `BACKUP_PATH`. El usuario sale de `DB_BACKUP_USER` / `DB_BACKUP_PASS` (si faltan, `DB_ADMIN_*` y luego `DB_USER` / `DB_PASS`); la contraseña viaja por el entorno del proceso hijo, nunca en la línea de comandos.

```
php scripts/backup.php                      # respaldo + verificación + retención (14 por defecto)
php scripts/backup.php --conservar=30       # conserva los 30 más recientes de esta base
php scripts/backup.php --restaurar-prueba   # restaura el último respaldo en una BD temporal, compara
                                            # tablas/vistas/triggers/rutinas/eventos y la borra
```

- **Solo respalda la base de datos.** Las imágenes y comprobantes están en `storage/uploads` y se respaldan aparte (copie esa carpeta).
- La retención solo borra archivos de `backups/` cuyo nombre coincida exactamente con el patrón anterior y sea de la misma base.
- Haga la prueba de restauración de vez en cuando: un respaldo que nunca se restauró no está probado.
- Códigos de salida: 0 ok, 1 argumentos, 3 BD/entorno, 6 volcado inválido, 7 restauración de prueba fallida.

## Seguridad

El archivo `.env` contiene secretos y **nunca debe subirse al repositorio**. Mantenerlo en `.gitignore`.
