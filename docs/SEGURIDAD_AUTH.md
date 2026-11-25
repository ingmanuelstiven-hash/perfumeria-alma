# Seguridad y autenticación

Alcance: aplicación PHP 8.2+ renderizada en servidor (htmx/Alpine opcional), expuesta a Internet (catálogo público y acceso remoto al panel). La autenticación usa JWT por decisión del cliente. Este documento explica cómo implementarla de la forma más segura posible y qué riesgos quedan.

## 1. Decisión: JWT de vida corta en cookie + refresh token opaco rotativo

| Pieza | Diseño |
|---|---|
| Access token | JWT **HS256**, vida **10 min**, firmado con `JWT_SECRET` (≥ 32 bytes aleatorios, en `.env`). Claims: `iss`, `aud`, `sub` (usuario_id), `rol`, `sid` (familia de sesión), `tv` (`usuarios.token_version`), `jti` (16 bytes aleatorios), `iat`, `nbf`, `exp`. Cabecera `kid` (huella de la clave) para rotar claves con `JWT_SECRET_ANTERIOR`. *(S1A: `tv` en lugar de `ver` y sin `suc`: la sucursal se lee de la BD en la misma consulta de cada petición, ver §11)* |
| Transporte | Cookie `__Host-at`: `HttpOnly; Secure; SameSite=Strict; Path=/`. **Nunca** en `localStorage`/`sessionStorage` ni en la URL. *(S1A: `Max-Age` = lo que le queda a la sesión, no 600: el JWT sigue durando 10 min, pero así el servidor distingue "expirado" de "sin sesión" y puede ofrecer la renovación y el logout; §11)* |
| Refresh token | 32 bytes aleatorios (`random_bytes`), opaco (no es JWT). En BD solo su `SHA-256` (`refresh_tokens.token_hash`). Cookie `__Secure-rt`: `HttpOnly; Secure; SameSite=Strict; Path=/auth/refresh` *(S1A: solo viaja a ese endpoint)*. Inactividad: 2 h; tope absoluto de la sesión (`sesion_expira_en`): 12 h (un turno) |
| Rotación | Cada uso del refresh lo marca `usado_en`, crea otro en la misma `familia_id` y enlaza `reemplazado_por` |
| Reutilización | Si llega un refresh **ya usado** (fuera de la ventana de gracia de 10 s) se revoca toda la familia (`motivo_revocacion='reutilizacion'`), se registra en `auditoria` y se avisa al admin |
| Revocación | `jwt_revocados(jti)` para cerrar **una** sesión antes de que expire su access token; `usuarios.token_version++` para cerrar **todas** (cambio de contraseña, desactivación, cambio de rol) |
| Librería | `firebase/php-jwt` vía Composer, la versión estable más reciente de la rama 6.x o superior (`composer require firebase/php-jwt`), revisada con `composer audit`. Decodificar siempre con `new Key($secreto, 'HS256')`: el algoritmo lo fija el servidor, nunca la cabecera (evita `alg=none` y la confusión de algoritmos). Margen de reloj (`JWT::$leeway`) de 30 s |

**Por qué HS256 y no EdDSA:** emite y verifica el mismo servidor, así que una clave simétrica de 256 bits es suficiente y más simple. EdDSA (Ed25519 con `sodium`) tiene sentido si otro servicio necesita **verificar** sin poder **firmar**; queda como opción con el mismo diseño.

### Por qué este híbrido (y explicación honesta)
- Un JWT "puro" (sin estado) **no se puede revocar** antes de `exp`. En un POS hay que poder echar en el acto a un empleado despedido. Por eso, en **cada petición**, el middleware hace una consulta por PK: `usuarios.activo`, `token_version` y la existencia del `jti` en `jwt_revocados`. **Con eso la aplicación ya no es sin estado**: en seguridad, este diseño equivale a sesiones de servidor bien hechas (`session_regenerate_id`, cookie HttpOnly), con más piezas que mantener.
- Lo que aporta el JWT aquí: un formato estándar y firmado que servirá si mañana hay una API o una app móvil, claims de rol y sucursal sin consultas adicionales, y un tope duro de 10 minutos si fallara la comprobación en BD.
- Lo que **no** aporta: protección frente a XSS. HttpOnly impide **robar** el token, pero un script inyectado puede **usarlo** desde el navegador de la víctima. La defensa contra XSS sigue siendo escapar la salida y la CSP.
- Por ir en cookie, el navegador lo envía solo: **hace falta CSRF** (sección 4), igual que con sesiones PHP.
- Recomendación: si la prioridad fuera la mínima complejidad, se usarían sesiones PHP nativas. Se respeta la decisión del cliente con este diseño, que cierra los huecos típicos del JWT (revocación, almacenamiento en el navegador, algoritmo).

### Hosting compartido
| Limitación | Consecuencia / mitigación |
|---|---|
| Sin Redis/Memcached (a veces sin APCu) | Lista de revocación, rate limit y permisos en MySQL (`jwt_revocados`, `limites_tasa`). Son 1–3 consultas por PK por petición, aceptable. Cachear `rol → permisos` en APCu si existe |
| No se controla `httpd.conf` ni `my.ini` | Cabeceras y bloqueos vía `.htaccess`. `sql_mode` estricto y `time_zone` fijados por la app al conectar |
| `.env` dentro de `public_html` | Ponerlo un nivel por encima del webroot (casi todos los paneles lo permiten), con permisos 600. Si no es posible, `.htaccess` con `Require all denied` y un nombre no adivinable. Nunca en Git |
| Sin privilegio `TRIGGER` o `GRANT` granular (algunos cPanel) | Los triggers de solo inserción pueden fallar al migrar. Hay que pedir el privilegio o, si no se concede, dejar la garantía en los servicios y documentarlo |
| Cron limitado | Purga diaria (tokens expirados, `limites_tasa`, `login_intentos` de más de 90 días) con el cron del panel llamando a `php scripts/purgar.php` |
| Vecinos en el mismo servidor | Riesgo de lectura de archivos si el hosting está mal aislado. Rotar `JWT_SECRET` si hay sospecha. Para un negocio con dinero en juego es preferible un VPS pequeño |
| HTTPS | Obligatorio (Let's Encrypt/AutoSSL). Sin HTTPS no funcionan las cookies `Secure` ni los prefijos `__Host-` |

**Red local (XAMPP en la tienda):** los navegadores aceptan cookies `Secure` en `http://localhost`, pero **no** en `http://192.168.x.x`. Si otros equipos o tablets de la tienda acceden por IP, hay que servir HTTPS en la LAN (certificado local con `mkcert` instalado en esos equipos) o usar un nombre `.local` con certificado. No se relajan las cookies.

## 2. Flujos

### 2.1 Login (`POST /login`)
1. Verificar Origin y CSRF previo a la sesión (cookie `__Host-csrf` con `sid="anon"`).
2. Rate limit: 5 intentos / 15 min por **usuario** y 20 / 15 min por **IP** (`limites_tasa`, `login_intentos`). Al superarlo: respuesta 429 genérica y `usuarios.bloqueado_hasta`.
3. Buscar el usuario. Si no existe, ejecutar igual `password_verify` contra un hash ficticio (tiempo constante). Mensaje único: "Usuario o contraseña incorrectos".
4. `password_verify`. Si `password_needs_rehash`, se rehashea (Argon2id; bcrypt con cost 12 si no hay Argon2).
5. Éxito: poner `intentos_fallidos=0`, crear `familia_id` nuevo, insertar el refresh (hash), emitir el access JWT, enviar las cookies `__Host-at`, `__Secure-rt` y una `__Host-csrf` nueva ligada al `sid`. Registrar `login_intentos` y `auditoria`.
6. Si `debe_cambiar_password=1`, el único permiso efectivo es cambiar la contraseña.

### 2.2 Petición autenticada (middleware)
1. Leer `__Host-at`. `JWT::decode` con clave y algoritmo fijos; validar `iss`, `aud`, `nbf`, `exp`.
2. Una consulta: `SELECT u.activo, u.token_version, u.rol_id, u.sucursal_id, (SELECT 1 FROM jwt_revocados WHERE jti=?) ...`. Rechazar si el usuario está inactivo, si `ver` ≠ `token_version` o si el `jti` está revocado.
3. Si el método no es seguro (POST/PUT/PATCH/DELETE): validar CSRF y Origin.
4. Cargar los permisos del rol y comprobar el permiso **de la acción concreta** (`Permisos::exigir('ventas.anular')`). Filtrar los datos por `sucursal_id` del token (contra IDOR).
5. Access token expirado: htmx/fetch recibe `401` con `HX-Trigger: sesion-expirada`; un manejador global hace `POST /auth/refresh` y repite la petición una vez. En una navegación completa, se sirve una página mínima que intenta el refresh y, si falla, redirige a `/login`.

### 2.3 Refresh (`POST /auth/refresh`, con CSRF)
1. `SHA-256(cookie)` y búsqueda por `uq_rt_token_hash` **con `FOR UPDATE`** (serializa pestañas concurrentes).
2. Si no existe, está revocado o ha caducado (`expira_en`/`sesion_expira_en`): 401 y se borran las cookies.
3. Si **ya fue usado**:
   - dentro de los 10 s de gracia (otra pestaña rotó a la vez): 409, el cliente reintenta con la cookie nueva, **sin** revocar;
   - fuera de la gracia: **reutilización**. Se revoca la familia, se meten en `jwt_revocados` los jti activos conocidos, se audita y se responde 401.
4. Si es válido: marcarlo `usado_en`, crear el sucesor (misma familia, `expira_en = ahora + 2 h` pero nunca más allá de `sesion_expira_en`), enlazar `reemplazado_por`, emitir un access token nuevo y rotar el CSRF. Todo en una transacción.

### 2.4 Logout (`POST /logout`, con CSRF)
Revocar la familia (`motivo='logout'`), insertar el `jti` actual en `jwt_revocados` hasta su `exp` y borrar las tres cookies (`Max-Age=0`). Auditar. *(S1A: la familia se toma del `sid` del access token, aceptando uno expirado si su firma es válida, porque la cookie del refresh no llega a `/logout`.)*

### 2.5 Cambio o restablecimiento de contraseña, desactivación, cambio de rol
`token_version++`, revocar **todas** las familias del usuario y auditar. Las sesiones quedan inválidas en la siguiente petición, ya que la versión se comprueba siempre.

### 2.6 PIN de autorización (descuento sobre el tope, anulación)
`pin_hash` con `password_hash`, rate limit propio (3 intentos / 5 min por autorizador), autorización ligada a **una** operación (se guarda en `ventas.descuento_autorizado_por` / `caja_movimientos.autorizado_por`) y auditada. El PIN no sirve para iniciar sesión.

## 3. Roles y permisos

Tres roles en el seed. `contador` se propone porque el negocio suele tener un contador externo: con él puede revisar números sin tocar caja ni inventario y sin datos personales de clientes.

| Módulo / permiso | admin | empleado | contador |
|---|:-:|:-:|:-:|
| usuarios.ver / gestionar, roles.gestionar, sesiones.revocar | ✔ | — | — |
| config.gestionar, respaldos.ejecutar | ✔ | — | — |
| auditoria.ver | ✔ | — | ✔ |
| articulos.ver | ✔ | ✔ | ✔ |
| articulos.gestionar, articulos.imagenes, codigos.gestionar | ✔ | — | — |
| etiquetas.imprimir | ✔ | ✔ | — |
| inventario.ver | ✔ | ✔ | ✔ |
| **inventario.ver_costos** | ✔ | — | ✔ |
| inventario.trasvasar, conteos.realizar | ✔ | ✔ | — |
| inventario.ajustar, conteos.aplicar, recetas.gestionar | ✔ | — | — |
| ventas.crear, ventas.descuento (hasta el tope) | ✔ | ✔ | — |
| ventas.ver (propias) | ✔ | ✔ | ✔ |
| ventas.ver_todas | ✔ | — | ✔ |
| ventas.autorizar_descuento, ventas.anular | ✔ | — | — |
| precios.gestionar, promociones.gestionar, catalogo.gestionar | ✔ | — | — |
| caja.abrir, caja.cerrar (propia) | ✔ | ✔ | — |
| caja.movimientos, caja.revisar | ✔ | — | — |
| caja.ver_todas | ✔ | — | ✔ |
| compras.ver, cxp.ver, gastos.ver | ✔ | — | ✔ |
| compras.gestionar, proveedores.gestionar, cxp.pagar, gastos.gestionar | ✔ | — | — |
| reportes.ver, reportes.financieros | ✔ | — | ✔ |
| cierres.ejecutar | ✔ | — | — |
| clientes.ver, clientes.gestionar, fidelizacion.redimir | ✔ | ✔ | — |
| clientes.exportar, clientes.suprimir, fidelizacion.gestionar | ✔ | — | — |
| bonos.vender, bonos.redimir | ✔ | ✔ | — |
| bonos.anular | ✔ | — | — |
| apartados.crear, abonar, entregar | ✔ | ✔ | — |
| apartados.cancelar | ✔ | — | — |
| devoluciones.crear (dentro de la política) | ✔ | ✔ | — |
| devoluciones.autorizar | ✔ | — | — |

Reglas:
- **Se verifica en el servidor, por acción.** Ocultar un botón no es control de acceso.
- **Filtrado de costos en la respuesta:** los repositorios devuelven DTO sin `costo_*`, `margen` ni `valor_inventario` salvo que el usuario tenga `inventario.ver_costos`. Las plantillas del empleado nunca reciben esos campos, ni siquiera ocultos o en atributos `data-*`. El ticket nunca muestra costos.
- **Catálogo público:** solo lee `v_catalogo_publico` (sin costos ni stock exacto) con un usuario de BD propio.
- Empleado: solo ve sus propias ventas y su turno (`WHERE usuario_id = :sub`).

### Usuarios de base de datos (mínimo privilegio)
```sql
-- Aplicación: sin UPDATE/DELETE sobre libros de solo inserción.
-- MariaDB/MySQL NO permiten "restar" privilegios de tabla a un GRANT a nivel de base,
-- así que NO se da GRANT sobre perfumeria.*: se concede tabla por tabla
-- (lo generará scripts/grants.php recorriendo information_schema):
CREATE USER 'perf_app'@'localhost' IDENTIFIED BY '<desde .env>';
--   libros (movimientos_esencia, movimientos_inventario, caja_movimientos, auditoria,
--           bono_movimientos, fidelizacion_movimientos, cliente_consentimientos):
GRANT SELECT, INSERT ON perfumeria.movimientos_esencia TO 'perf_app'@'localhost';  -- etc.
--   tablas de tokens/limpieza: SELECT, INSERT, UPDATE, DELETE
GRANT SELECT, INSERT, UPDATE, DELETE ON perfumeria.refresh_tokens TO 'perf_app'@'localhost'; -- y jwt_revocados, limites_tasa, login_intentos, rol_permisos, receta_componentes
--   resto de tablas y vistas: SELECT, INSERT, UPDATE (sin DELETE: borrado lógico)
GRANT SELECT, INSERT, UPDATE ON perfumeria.ventas TO 'perf_app'@'localhost';  -- etc.
-- Catálogo público
CREATE USER 'perf_catalogo'@'localhost' IDENTIFIED BY '<desde .env>';
GRANT SELECT ON perfumeria.v_catalogo_publico TO 'perf_catalogo'@'localhost';
-- Respaldos
CREATE USER 'perf_backup'@'localhost' IDENTIFIED BY '<desde .env>';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON perfumeria.* TO 'perf_backup'@'localhost';
```
Las migraciones se ejecutan con un usuario administrador distinto, nunca con `perf_app`. En XAMPP, poner contraseña a `root` y dejar MySQL escuchando solo en `127.0.0.1` (`bind-address`).

## 4. CSRF

Las cookies con `SameSite=Strict` ya bloquean la mayoría de CSRF, pero no cubren subdominios hermanos ni navegadores antiguos. Se añaden dos capas:
1. **Doble envío firmado:** `csrf = base64url(r) . "." . base64url(HMAC-SHA256(CSRF_SECRET, sid . r))` en la cookie `__Host-csrf` (`SameSite=Strict`; *S1A: también HttpOnly, ya que el servidor incrusta el valor en la página y ningún script necesita leer la cookie*). La página lo expone en `<meta name="csrf-token">`; htmx lo envía como cabecera `X-CSRF-Token` (`hx-headers`) y los formularios clásicos en un campo oculto. El servidor exige que coincida con la cookie y que el HMAC sea válido para el `sid` del JWT, comparando con `hash_equals`. Un atacante que logre plantar una cookie no puede generar un token válido para otra sesión.
2. **Origin/Fetch Metadata:** rechazar métodos no seguros si `Origin` no es `APP_URL`, o si `Sec-Fetch-Site` no es `same-origin`/`none`.

Ningún GET cambia estado (tampoco el refresh ni el logout).

## 5. Subida de imágenes

Ruta de proceso (`ImagenService::subir`), todo en el servidor:
1. Permiso `articulos.imagenes` y CSRF.
2. `$_FILES['foto']['error'] === UPLOAD_ERR_OK` y `is_uploaded_file()`. Tamaño ≤ 5 MB (también `upload_max_filesize`/`post_max_size` en php.ini o `.htaccess`).
3. **MIME real** con `finfo_file(FILEINFO_MIME_TYPE)` en lista blanca `image/jpeg`, `image/png`, `image/webp`. La extensión del nombre original solo se compara con esa lista; **nunca se usa el nombre original** para nada.
4. `getimagesize()` **antes** de decodificar: rechazar si ancho × alto > 40 MP (bomba de descompresión) o si el tipo no coincide con el de `finfo`.
5. **Re-codificar con GD:** `imagecreatefromjpeg/png/webp` → reescalar a un lado máximo de 1600 px y crear una miniatura de 320 px → `imagewebp($img, $ruta, 82)`. Así se eliminan EXIF/GPS, payloads políglotas y metadatos. En JPEG se corrige la orientación EXIF antes (si existe `exif_read_data`). Si GD no tiene WebP, guardar JPEG de calidad 85.
6. Nombre: `bin2hex(random_bytes(16)) . '.webp'`, en `storage/uploads/articulos/ab/cd/` (los dos primeros pares del nombre, para no llenar un solo directorio; logos, avatares y comprobantes van en `marcas/`, `usuarios/`, `comprobantes/`, etc. y se registran en `archivos`). En la BD se guarda la ruta **relativa** a `storage/uploads` (p. ej. `articulos/3f/a9/3fa9….webp`). `storage/` está **fuera de `public/`**. `imagenes.hash_sha256` evita duplicados; los CHECK `ck_img_ruta`/`ck_arch_ruta` (migración 0002) solo admiten esa forma exacta: sin `..`, sin nombre original y con extensión coherente con el MIME. Convención completa en BASE_DE_DATOS.md §9.
7. Insertar en `imagenes` (la primera foto pasa a ser principal; cambiar la principal se hace en una transacción que quita la marca a la anterior).
8. Servir con `GET /media/{id}/{grande|mini}`: comprobar que el artículo está publicado (catálogo) o que hay sesión (interno), y responder con `Content-Type` tomado de la BD, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` y `Cache-Control: public, max-age=31536000, immutable`.

Si por rendimiento en hosting compartido se copian las imágenes publicadas a `public/media/`, esa carpeta lleva este `.htaccess`:
```apache
Options -Indexes -ExecCGI
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar
RemoveType .php .phtml .phar
<IfModule mod_php.c>
  php_flag engine off
</IfModule>
<FilesMatch "(?i)\.(php\d?|phtml|phar|pl|py|cgi|sh|htaccess)$">
  Require all denied
</FilesMatch>
<FilesMatch "(?i)^[a-f0-9]{32}(_mini)?\.(webp|jpg|png)$">
  Require all granted
</FilesMatch>
Header set X-Content-Type-Options "nosniff"
```

## 6. Cabeceras HTTP

Enviadas por el front controller (y como respaldo en `public/.htaccess`):
```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; upgrade-insecure-requests
Strict-Transport-Security: max-age=31536000; includeSubDomains     (solo cuando HTTPS ya funciona)
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Cross-Origin-Opener-Policy: same-origin
Cache-Control: no-store            (en páginas autenticadas)
```
Implicaciones de la CSP:
- **Tailwind compilado** a un `.css` local (no el CDN, que inyecta estilos en línea).
- **Alpine:** usar el build `@alpinejs/csp`. El build normal necesita `'unsafe-eval'`, que **no** se permite.
- **htmx:** `htmx.config.allowEval = false`, sin `hx-on`/`hx-vars`. Archivos JS servidos localmente (sin CDN), con versión fijada.
- Sin `<script>` ni `onclick` en línea. Si algún día hacen falta, usar un `nonce` por petición.

PHP: `expose_php=Off`, `display_errors=Off` en producción, errores a un log fuera del webroot, `session.*` irrelevante (no hay sesiones PHP), `allow_url_include=Off`.

## 7. HTTPS, `.env` y secretos

- HTTPS en todo (Let's Encrypt), redirección 301 de HTTP a HTTPS y HSTS cuando esté estable.
- `.env` (fuera del webroot y de Git; `.env.example` sin valores): `DB_*`, `APP_URL`, `JWT_SECRET`, `JWT_SECRET_ANTERIOR` (rotación por `kid`), `CSRF_SECRET`, `APP_ENV`, `BACKUP_*`. Secretos generados con `bin2hex(random_bytes(32))`.
- Rotación de `JWT_SECRET`: cada 6 meses o ante un incidente. Se firma con la clave nueva y se acepta la anterior durante 15 min. Con sospecha de robo: rotar sin periodo de gracia, lo que cierra todas las sesiones.
- Nunca registrar en logs tokens, contraseñas, PIN ni números de tarjeta.

## 8. Respaldos

- `mysqldump --single-transaction --routines --triggers --events --hex-blob` con `perf_backup`, diario (Programador de tareas o cron), **cifrado** (7-Zip AES-256 o `gpg`) antes de salir del equipo.
- Copia externa (nube o USB rotado), retención de 30 días diarios + 12 mensuales. Incluir `storage/uploads/`.
- `.env` y las claves de cifrado **no** van con el respaldo de datos; se guardan aparte (gestor de contraseñas del dueño).
- Prueba de restauración mensual en una base temporal (DoD del Sprint 11).

## 9. Checklist OWASP Top 10 (edición 2025) aplicable

| Riesgo | Control en este sistema |
|---|---|
| A01 Control de acceso roto | Permiso por acción en el servidor; filtro por `sucursal_id` y `usuario_id`; IDs nunca confiables desde el cliente; costos filtrados en el DTO; catálogo con usuario de BD de solo lectura sobre una vista |
| A02 Configuración insegura | Solo `public/` expuesto; `display_errors=Off`; cabeceras de la sección 6; root de MySQL con contraseña y solo en localhost; sin phpMyAdmin expuesto a Internet |
| A03 Cadena de suministro | Composer con `composer.lock` versionado; `composer audit` en cada sprint; JS de terceros copiado localmente con versión fijada; pocas dependencias |
| A04 Fallos criptográficos | HTTPS y HSTS; Argon2id/bcrypt; SHA-256 para tokens y bonos; `random_bytes`; `hash_equals`; sin PAN de tarjeta (CHECK de 4 dígitos) |
| A05 Inyección | PDO con `ATTR_EMULATE_PREPARES=false` y consultas preparadas; nombres de columna o de orden solo de listas blancas; `htmlspecialchars` en toda salida; CSP |
| A06 Diseño inseguro | Transacciones + `FOR UPDATE` con orden fijo; idempotencia de ventas; CHECK de saldos ≥ 0; libros de solo inserción; topes de descuento con PIN |
| A07 Fallos de autenticación | Rate limit y bloqueo; mensaje genérico; tiempo constante; política de contraseña (mín. 12, lista de comunes); rotación de refresh con detección de reutilización; revocación inmediata; TOTP para admin (pendiente, recomendado) |
| A08 Integridad de software/datos | Triggers de solo inserción; checksum de migraciones; JWT firmado con algoritmo fijo; auditoría antes/después |
| A09 Registro y alertas | `auditoria`, `login_intentos`; alerta al admin ante reutilización de refresh, más de 10 fallos de login por hora o diferencias de caja |
| A10 Manejo de condiciones excepcionales | Rollback ante cualquier excepción; reintento ante deadlock; mensajes genéricos al usuario y detalle solo en el log; `sql_mode` estricto (sin truncamientos silenciosos) |

Extra: validación de entrada por esquema (tipos, rangos, longitudes) antes de los servicios, y protección contra SSRF trivial (la app no hace peticiones salientes con URLs del usuario).

## 10. Límites conocidos y riesgos aceptados

1. **JWT con estado:** se consulta la BD en cada petición. No hay beneficio de escalabilidad frente a las sesiones; la ventaja es solo de formato.
2. **XSS = actuar como el usuario** mientras dure la página. La CSP estricta y el escape de salida son la defensa real.
3. **Robo de `JWT_SECRET`** permite falsificar tokens de cualquier rol. Mitigan la comprobación de `token_version`/`activo` en BD (no basta para tokens falsos de usuarios activos), la rotación y los permisos 600. Es el secreto más crítico.
4. **Ventana de gracia de 10 s** en el refresh: un atacante que robe un refresh y lo use a la vez que la víctima podría no detectarse. Aceptado a cambio de no expulsar a quien abre varias pestañas.
5. **HTTP en la LAN** no funciona con este diseño (decisión consciente): requiere HTTPS local.
6. **Sin MFA** en el MVP. Se recomienda TOTP para el rol admin en el Sprint 11.
7. **Hosting compartido:** aislamiento dudoso entre clientes y posible falta del privilegio `TRIGGER`. Se recomienda un VPS.
8. El `sql_mode` global de XAMPP es permisivo: si una conexión olvida fijarlo, MariaDB trunca datos sin error. `Db.php` debe fijarlo siempre y un test debe comprobarlo.
9. Los lectores de código de barras USB son teclados: cualquiera con acceso físico al POS puede "teclear" códigos. Es un riesgo físico, cubierto por la sesión, los permisos y la auditoría.

## 11. Implementación Sprint 1A: diferencias con este documento

Código: `public/index.php` (front controller), `src/App.php` (kernel y rutas), `src/Http/*` (router mínimo propio), `src/Security/*`, `src/Services/{AuthService,UsuarioService,Permisos,Auditoria}.php`, `src/Middleware/Mw.php`. Pruebas: `php tests/run.php` (BD temporal `perfu_s1a_test`).

| Tema | Diseño original | Implementado | Motivo |
|---|---|---|---|
| Rutas | `/auth/login`, `/auth/logout` | `POST /login`, `POST /logout`, `POST /auth/refresh` | Decisión del Sprint 1A. El refresh queda aislado en su propia ruta |
| Cookie del refresh | `Path=/auth/` | `Path=<base>/auth/refresh` | Mínima exposición: solo viaja al endpoint que la usa |
| Cookie del access | `Max-Age=600` | `Max-Age` = resto de la sesión (≤ 12 h); el JWT sigue con `exp` de 10 min | Con 600 s el navegador la borra y el servidor no puede distinguir "expirado" (ofrecer renovación, cerrar la familia en logout) de "sin sesión" |
| Claims | `ver`, `suc` | `tv`, sin `suc` | `sucursal_id` se lee de la BD en la consulta por petición, que ya se hace; un claim más no ahorra nada |
| Revocación de una sesión | `jwt_revocados` | `jwt_revocados` **y** comprobación de que la familia (`sid`) sigue viva en `refresh_tokens` | La BD no guarda qué `jti` emitió cada refresh, así que tras una reutilización no se pueden listar "los jti activos". Comprobar la familia en la misma consulta corta al instante todos los access de esa sesión |
| Cookie CSRF | sin HttpOnly | HttpOnly | El token se incrusta en `<meta>` y en campos ocultos desde el servidor; ningún script lee la cookie |
| Rotación del CSRF | en cada refresh | en login y cambio de contraseña (nuevo `sid`), no en el refresh | El token va atado al `sid`, que no cambia al rotar; rotarlo rompería los formularios abiertos en otras pestañas sin ganar seguridad |
| CSRF del refresh/logout | — | refresh: atado a la familia del refresh (se busca sin bloqueo antes de rotar); logout: al `sid` del access, aunque esté expirado | Esas rutas no siempre tienen un access válido |
| Bloqueo por usuario | por usuario | por el **texto tecleado** normalizado (`limites_tasa`, clave `login_u\|texto`); `usuarios.bloqueado_hasta`/`intentos_fallidos` son un reflejo para el admin | Así un usuario inexistente se bloquea igual que uno real: el 429 no revela si existe |
| Orden del rate limit | contar el fallo después | el intento se **reserva** (incremento atómico) antes de verificar la clave | Una ráfaga en paralelo no puede evaluar más de 5 contraseñas (probado) |
| Límites | 5/usuario y 20/IP en 15 min | igual (`config.login_max_intentos`, `login_bloqueo_minutos`; `LOGIN_MAX_INTENTOS_IP`), y `/auth/refresh` 60/min por IP (`REFRESH_MAX_POR_MINUTO`). Un login correcto no consume cupo de la IP | Una tienda detrás de una sola IP no debe bloquearse por los aciertos de sus empleados |
| Bloqueo vigente | — | se responde 429 **sin** calcular ningún hash | Evita que el bloqueo amplifique un DoS (Argon2id usa 64 MiB por verificación) |
| Secretos | ≥ 32 bytes | hexadecimal de ≥ 64 caracteres, distintos entre sí; si no, la app no arranca | Es la única forma de comprobar la longitud real en bytes |
| HTTPS | obligatorio | producción: `APP_URL` https o no arranca; petición http → 301 (GET) / 400 (resto). `Host` debe ser el de `APP_URL` | — |
| HTTP local | "no se relajan las cookies" | `APP_ENV=local` + petición http + `APP_URL` de loopback (`localhost`, `127.0.0.1`, `[::1]`): cookies `at`/`rt`/`csrf` sin `Secure` ni prefijo. Por IP de LAN sin HTTPS: 403 | Algunos navegadores no fijan `Secure`/`__Host-` en `http://localhost`. En modo seguro **solo** se leen los nombres con prefijo |
| CSP | con `upgrade-insecure-requests` | solo si la petición es HTTPS (igual que HSTS) | Sobre `http://127.0.0.1` el navegador pediría la CSS por https y la página quedaría sin estilos |
| Aviso al admin por reutilización | aviso | solo registro en `auditoria` (`sesion.refresh_reutilizado`) | Notificaciones fuera del alcance de 1A |
| Cambio de contraseña | revoca todo | revoca todo y abre una sesión nueva para el dispositivo actual | El usuario no es expulsado del equipo donde acaba de cambiarla |
| `debe_cambiar_password=1` | único permiso: cambiar la clave | permisos efectivos vacíos; `/` redirige a `/cuenta/password` | — |

**Riesgos de esta implementación (además de §10):**
1. **Modo local sin `Secure`:** en `APP_ENV=local` sobre http, las cookies viajan en claro por la interfaz de loopback y no llevan prefijo. Es aceptable solo porque el tráfico no sale del equipo. Si un servidor de producción se arrancara por error con `APP_ENV=local` y `APP_URL` de loopback, sería accesible solo desde ese mismo equipo (el Host debe coincidir), pero sin las garantías de los prefijos. **No usar `APP_ENV=local` fuera de un equipo de desarrollo.**
2. **Ventana de gracia de 10 s** (§10.4): probada con 4 procesos simultáneos. Responde 409 y no entrega tokens, así que un atacante que use un refresh robado durante la gracia no obtiene nada; si lo usa después, se detecta y se revoca la familia.
3. **IP = `REMOTE_ADDR`:** detrás de un proxy inverso todas las peticiones compartirían IP. Hoy no se confía en `X-Forwarded-For`. Si se añade un proxy, hay que configurar una lista de proxies de confianza.
4. **Tiempo constante:** el hash ficticio usa los parámetros por defecto de Argon2id. Si se cambian los parámetros de `password_hash`, hay que regenerarlo (`src/Security/Passwords.php`). En la prueba, la mediana fue de unos 200 ms en ambos casos.
5. **Detección de la carrera del refresh:** la gracia se mide con `DATETIME` (resolución de 1 s).

## 12. Sprint 1B: autorización y datos de costo

- **Matriz** (códigos reales de `permisos`): `articulos.ver` lee el catálogo; `inventario.ver` añade existencias; `inventario.ver_costos` añade costo promedio y último costo; `articulos.gestionar` crea/edita/desactiva ítems, recipientes, marcas y categorías; un saldo inicial exige además `inventario.ajustar`. Por rol sembrado: admin todo; empleado `articulos.ver` + `inventario.ver` (sin costos, no muta); contador `articulos.ver` + `inventario.ver` + `inventario.ver_costos` (solo lectura).
- **Costos:** `ArticuloService` arma el SELECT según los permisos recibidos; sin `inventario.ver_costos` las columnas de costo no se consultan, así que no pueden aparecer en HTML, JSON ni errores. Las plantillas también condicionan el encabezado. La auditoría de 1B no guarda costos (quedan en el libro de movimientos).
- **Mutaciones:** `autenticar -> csrf -> permiso` en cada ruta POST; entradas de formulario y JSON pasan por la misma validación del servicio; los parámetros no escalares se ignoran.
- **Búsqueda:** consultas preparadas; los comodines `%` y `_` del texto buscado se escapan; filtros fuera de lista se ignoran.
- **Riesgo residual:** la edición reemplaza todos los campos (un cliente JSON que omita `marca_id` la borra). El formulario HTML siempre los envía.
