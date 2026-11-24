# Plan de desarrollo Scrum: sistema web de la perfumería

> **Cambios (2026-11-20):**
> - **Esquema completo desde el Sprint 0:** `database/migrations/0001_schema.sql` (62 tablas, 9 vistas, triggers de solo inserción) y `database/seeds/0001_seed_base.sql`, ya validados en MariaDB 10.4 de XAMPP. Detalle en `docs/BASE_DE_DATOS.md`.
> - **Autenticación con JWT** por decisión del cliente: access token de 10 min en cookie HttpOnly + refresh opaco rotativo en BD, revocación, CSRF y rate limit. Se añade el rol **contador** (solo lectura). Detalle y riesgos en `docs/SEGURIDAD_AUTH.md`.
> - **Fotos y código de barras para todo ítem de inventario** (esencias, productos, envases, insumos; también recipientes), con subida segura de imágenes y etiquetas imprimibles: entra en el Sprint 1.
> - **POS con lector de código de barras** (USB tipo teclado): entra en el Sprint 3.
> - Carga: Sprint 1 pasa de 21 a 24 pts; Sprint 3 de 24 a 23 pts (entra el escaneo, +2, y la autorización con PIN, 3, pasa al Sprint 7); Sprint 7 de 20 a 23 pts. Release 1 suma 70 pts (antes 68).

Stack fijo: PHP 8.2+ con plantillas renderizadas en servidor, MySQL/MariaDB (InnoDB, utf8mb4) sobre XAMPP en Windows, HTML con Tailwind y htmx/Alpine.js ligero. Equipo: 1 desarrollador + IA. Sprints de 2 semanas.

## 1. Visión y alcance del MVP

**Visión:** un sistema propio para vender, costear y controlar el inventario de esencias y productos, con caja diaria confiable y utilidad mensual real (Utilidad = Ventas − COGS − Gastos).

| Entra en el MVP (Release 1) | Fuera del MVP (releases siguientes o futuro) |
|---|---|
| Login con roles admin/empleado y permisos por acción | Facturación electrónica DIAN (solo campos preparatorios) |
| Esencias por gramos, recipientes, trasvase con merma | WhatsApp Cloud API (solo enlace wa.me) |
| Recetas por presentación (30/50/60/100/120 ml) | Multi-sucursal operativa (solo `sucursal_id` desde el día 1) |
| Productos 1:1 y POS con costo congelado por línea | Modo offline |
| Caja por turnos: apertura, arqueo, cierre | Apartados, bonos, fidelización (Releases 3 y 4) |
| Pagos: efectivo, Nequi, Daviplata, tarjeta (registro manual) | |
| Libro de movimientos de inventario de solo inserción | |
| Ticket térmico 58/80 mm, respaldos mysqldump | |

## 2. Qué necesitamos (requisitos)

| Rubro | Requisito |
|---|---|
| Software | PHP 8.2+ (extensiones pdo_mysql, mbstring, intl), MariaDB 10.6+ o MySQL 8, Apache 2.4 (XAMPP), Git, Composer (opcional: PHPUnit, phpdotenv) |
| Servidor | PC local en tienda con XAMPP (red LAN) o VPS/hosting con PHP 8.2 y acceso SSH; UPS recomendado |
| Hardware | Impresora térmica 58 u 80 mm (USB o red), báscula de precisión 0,01 g, lector de código de barras (opcional) |
| Web | Dominio y HTTPS (Let's Encrypt) para el catálogo público y acceso remoto |
| Respaldos | mysqldump diario programado (Programador de tareas de Windows) + copia externa (nube o USB) con rotación de 30 días |

**Datos iniciales que debe entregar el negocio:**

- [ ] Lista de esencias: nombre, proveedor, gramos en stock por recipiente, costo por gramo
- [ ] Recetas por presentación: gramos de esencia, ml de alcohol/base, envase
- [ ] Productos 1:1: nombre, categoría, costo, precio, stock, código de barras
- [ ] Lista de precios de venta por presentación
- [ ] Proveedores y saldos pendientes
- [ ] Usuarios (nombre, rol) y PIN de admin
- [ ] Gastos fijos mensuales típicos

**Decisiones pendientes del cliente:**

- [ ] Servidor local en tienda o hosting
- [ ] Ancho de ticket (58 u 80 mm) y datos del encabezado
- [ ] Tope de descuento por empleado (%) y reglas de fidelización (sellos o puntos)
- [ ] Porcentaje de merma esperado en trasvase
- [ ] Vigencia de bonos de regalo y plazo máximo de apartados

## 3. Estructura de carpetas y convenciones

```
perfumeria/
  public/            index.php (front controller), assets/, catalogo/
  src/
    Services/        VentaService, StockService, CajaService, CosteoService, CompraService...
    Repositories/    acceso a datos con PDO (consultas preparadas)
    Http/            controladores delgados, middleware (auth, CSRF, permisos)
    Security/        Session, Csrf, Permisos, Auditoria
    Support/         Db (PDO), Money (enteros/DECIMAL como string), Config
  templates/         layouts/, pos/, inventario/, caja/, ticket/ (@media print)
  database/
    migrations/      0001_schema.sql (esquema completo), 0002_... (cambios futuros)
    seeds/           datos de ejemplo y datos reales del negocio
  scripts/           backup.bat, migrate.php
  tests/             Unit/, Integration/ (BD de pruebas)
  docs/
  .env.example       credenciales de BD, APP_ENV, ruta de respaldos
```

Convenciones:
- Solo `public/` expuesto por Apache (DocumentRoot o alias); `.env` fuera del webroot y fuera de Git.
- Migraciones SQL numeradas, nunca se editan una vez aplicadas; tabla `migraciones` registra las ejecutadas.
- Dinero DECIMAL(14,2), gramos DECIMAL(10,3), costo/g DECIMAL(14,6). Prohibido FLOAT/DOUBLE. En PHP, montos como string con bcmath o enteros en centavos.
- Toda operación que toque stock o caja: transacción + `SELECT ... FOR UPDATE` en un único servicio.
- Tablas de movimientos y auditoría: solo INSERT (sin UPDATE/DELETE; el usuario de BD de la app no tiene esos permisos sobre ellas).

## 4. Definition of Ready y Definition of Done

| Definition of Ready (para entrar al sprint) | Definition of Done (para darla por terminada) |
|---|---|
| Historia en formato "Como… quiero… para…" | Criterios de aceptación verificados en la demo |
| Criterios de aceptación escritos y comprobables | Prueba automatizada del flujo transaccional (éxito, rollback y concurrencia cuando aplique) |
| Estimada en puntos (máx. 8; si no, se divide) | Sin FLOAT; tipos DECIMAL correctos en migración |
| Datos de ejemplo disponibles | Solo consultas preparadas (PDO), salida escapada con `htmlspecialchars` |
| Dependencias resueltas (tablas, decisiones del cliente) | CSRF en todo POST; permiso verificado en servidor por acción |
| Riesgo marcado (stock, dinero, seguridad → Opus) | Evento registrado en auditoría (usuario, acción, antes/después, IP) |
| | Migración y seed reproducibles desde cero; commit en Git |
| | Probado en móvil/tablet y ticket impreso si aplica |

## 5. Roadmap

Capacidad realista: **20–24 puntos por sprint** (1 persona + IA). Se recalibra con la velocidad real tras el Sprint 2.

### Sprint 0 — Preparación (1 semana, sin puntos)
Objetivo: entorno, esqueleto y datos. Entregable: repo Git, front controller, **esquema completo `0001_schema.sql` + seed base aplicados con el runner de migraciones** (sin usuarios ni contraseñas en el seed), `scripts/crear_admin.php` (crea el admin inicial pidiendo la contraseña por consola), Composer con `firebase/php-jwt`, `.env` fuera del webroot con `JWT_SECRET`/`CSRF_SECRET` aleatorios, `Db.php` que fija `sql_mode` estricto y `time_zone` en cada conexión, carpeta `storage/uploads/` fuera de `public/`, HTTPS local (`mkcert`) si se accede por la LAN, respaldo funcionando y datos del negocio recopilados.

### Release 1 — MVP / POS usable

| Sprint | Objetivo | Historias clave y criterios | Entregable en Review | Riesgos | Pts |
|---|---|---|---|---|---|
| 1 | Seguridad y catálogo interno | Como admin quiero crear usuarios con rol (admin/empleado/contador) para controlar accesos: login con password_hash Argon2id, JWT de 10 min en cookie `__Host-` HttpOnly+SameSite=Strict, refresh opaco rotativo con detección de reutilización, logout/revocación (jti + token_version), CSRF firmado, rate limit y bloqueo tras 5 intentos, permiso por acción en el servidor (8+3). Como admin quiero registrar esencias, recipientes, productos, envases e insumos con SKU (5). Como admin quiero subir varias fotos por ítem y elegir la principal (finfo, re-codificación a WebP con GD, nombre aleatorio, fuera de `public/`) (3). Como admin quiero asignar o generar códigos de barras (EAN-13 interno con prefijo 200, alternos, Code128 para recipientes) e imprimir etiquetas SVG/HTML (5). | Login JWT con refresh y revocación demostrados, matriz de permisos probada (empleado sin costos), CRUD de ítems con fotos y códigos, hoja de etiquetas impresa y leída con el lector, auditoría visible | Cookies `Secure` sin HTTPS en la LAN; carreras en el refresh con varias pestañas; GD sin WebP en el hosting | 24 |
| 2 | Inventario y recetas | Como admin quiero definir recetas por presentación para descontar componentes automáticamente. Como empleado quiero trasvasar entre recipientes registrando la merma (movimientos de solo inserción, saldo nunca negativo). | Receta de 50 ml descuenta esencia, alcohol y envase; libro de movimientos | Datos de recetas incompletos | 23 |
| 3 | POS y caja | Como empleado quiero vender con pago mixto (efectivo/Nequi/Daviplata) para cobrar rápido (transacción, FOR UPDATE, costo congelado por línea). Como empleado quiero abrir, arquear y cerrar mi turno (diferencias registradas). Como empleado quiero agregar ítems escaneando su código de barras con el lector USB (campo con foco permanente, Enter como fin, búsqueda exacta en `codigos_barras`, esencia escaneada → elegir presentación, aviso si no existe; idempotencia contra doble envío) (2). *(La autorización de descuentos sobre el tope con PIN pasa al Sprint 7; hasta entonces el sistema bloquea descuentos por encima del tope.)* | Venta completa por escaneo con ticket 80 mm impreso y cierre de caja cuadrado | Concurrencia en stock; impresora; lectores configurados sin sufijo Enter | 23 |

### Release 2 — Costeo y compras

| Sprint | Objetivo | Historias clave y criterios | Entregable | Riesgos | Pts |
|---|---|---|---|---|---|
| 4 | Compras y costo promedio | Como admin quiero registrar compras a proveedores para actualizar stock y costo promedio ponderado (recalculo dentro de la transacción). Como admin quiero ver cuentas por pagar y registrar abonos. | Compra recalcula costo/g; saldo de proveedor | Errores de redondeo en costo/g | 21 |
| 5 | Control físico | Como admin quiero hacer conteos físicos y registrar mermas con motivo (ajuste como movimiento, no edición). Como admin quiero alertas de stock mínimo. Como admin quiero anular una venta con reversión de stock y caja. | Conteo con ajuste, panel de alertas, anulación auditada | Anulaciones con caja ya cerrada | 22 |

### Release 3 — Pasivos: apartados, bonos y devoluciones

| Sprint | Objetivo | Historias clave y criterios | Entregable | Riesgos | Pts |
|---|---|---|---|---|---|
| 6 | Apartados y bonos | Como empleado quiero crear apartados con abonos (pasivo; ingreso y COGS solo al entregar; stock reservado). Como empleado quiero vender bonos de regalo con código único (pasivo hasta canje, saldo parcial). | Apartado entregado genera la venta; bono canjeado parcialmente | Confundir abono con ingreso en caja | 23 |
| 7 | Devoluciones y refill | Como empleado quiero procesar devoluciones (reintegro de stock al costo congelado, nota de devolución). Como empleado quiero vender refill reutilizando envase del cliente (receta sin envase). Como admin quiero autorizar con PIN descuentos sobre el tope y devoluciones fuera de política (3, movido desde el Sprint 3). | Devolución revierte stock y caja; venta de refill; autorización con PIN auditada | Políticas de devolución no definidas | 23 |

### Release 4 — Clientes, fidelización y catálogo

| Sprint | Objetivo | Historias clave y criterios | Entregable | Riesgos | Pts |
|---|---|---|---|---|---|
| 8 | Clientes y fidelización | Como empleado quiero registrar clientes con consentimiento de habeas data (fecha, versión del texto). Como cliente quiero acumular sellos/puntos y redimirlos. Como empleado quiero enviar el ticket o promoción por enlace wa.me. | Cliente con consentimiento, puntos acumulados, enlace wa.me | Tratamiento de datos personales | 21 |
| 9 | Catálogo público | Como visitante quiero ver el catálogo y pedir por WhatsApp (solo lectura, vista/consulta separada, sin datos de costo). | Catálogo responsive en dominio con HTTPS | Exposición de datos internos | 20 |

### Release 5 — Pulido y cierre mensual

| Sprint | Objetivo | Historias clave y criterios | Entregable | Riesgos | Pts |
|---|---|---|---|---|---|
| 10 | Cierre mensual y reportes | Como admin quiero registrar gastos y ejecutar el cierre mensual (Ventas − COGS − Gastos; período bloqueado tras cierre). Como admin quiero reportes de ventas, márgenes y rotación. | Estado de resultados del mes cuadrado contra caja | Cifras que no cuadran con la realidad | 22 |
| 11 | Endurecimiento y salida a producción | Revisión de seguridad, prueba de restauración de respaldo, rendimiento, manual de usuario, capacitación. | Sistema en producción con respaldo restaurado probado | Migración de datos reales | 18 |

## 6. Backlog priorizado

| Prioridad | Épica | Release |
|---|---|---|
| 1 | Seguridad, usuarios, permisos y auditoría | R1 |
| 2 | Inventario de esencias, recipientes y trasvase | R1 |
| 3 | Recetas y productos 1:1 | R1 |
| 4 | POS, pagos y ticket térmico | R1 |
| 5 | Caja por turnos | R1 |
| 6 | Compras, proveedores y cuentas por pagar | R2 |
| 7 | Conteos, mermas, alertas y anulaciones | R2 |
| 8 | Apartados y bonos (pasivos) | R3 |
| 9 | Devoluciones y refill | R3 |
| 10 | Clientes, habeas data y fidelización | R4 |
| 11 | Catálogo público | R4 |
| 12 | Gastos, cierre mensual y reportes | R5 |

**Backlog futuro:** facturación electrónica DIAN (ya existen subtotal/IVA/total y numeración por consecutivos; falta CUFE, XML UBL, proveedor tecnológico); WhatsApp Cloud API; multi-sucursal operativa (transferencias entre sedes, reportes consolidados); modo offline del POS; integración con datáfono.

## 7. Ceremonias adaptadas a 1 persona + IA

| Ceremonia | Cuándo | Cómo |
|---|---|---|
| Planning | Lunes día 1, 1 h | Elegir historias hasta la capacidad; dividir en tareas; asignar modelo con `model-router` |
| Daily asincrónico | Cada día, 5 min | Nota en `docs/daily.md`: ayer, hoy, bloqueos; Claude resume pendientes |
| Review | Viernes día 10, 45 min | Demo al dueño del negocio con datos reales; aceptar o rechazar historias |
| Retro | Tras la Review, 20 min | Qué funcionó, qué no, una mejora concreta para el siguiente sprint |

**Uso de `model-router` dentro del sprint:**

| Tipo de tarea | Modelo | Ejemplos |
|---|---|---|
| Simple/rápida | Haiku | Buscar archivos, seeds, textos, CRUD de catálogos sin dinero, estilos Tailwind |
| Normal | Sonnet | Plantillas, controladores, reportes, validaciones de formularios, pruebas unitarias |
| Difícil o de alto riesgo | Opus | Migraciones de BD, VentaService/StockService/CajaService, costeo promedio, pasivos, seguridad, revisión final de cada historia de dinero o stock |

El desarrollador orquesta y verifica; toda historia de Opus requiere revisión humana del diff y de la prueba de concurrencia.

## 8. Riesgos y métricas

| Riesgo | Mitigación |
|---|---|
| Sobreventa por ventas simultáneas | Transacción + FOR UPDATE en StockService; prueba de concurrencia con dos conexiones |
| Errores de redondeo en dinero o gramos | DECIMAL en BD, bcmath en PHP, pruebas con casos límite |
| Datos del negocio incompletos | Recopilarlos en Sprint 0; seeds de ejemplo mientras tanto |
| Pérdida de datos | mysqldump diario + copia externa + restauración probada mensualmente |
| Alcance creciente (scope creep) | Backlog futuro explícito; cambios solo en Planning |
| Dependencia de una sola persona | Documentación en `docs/`, migraciones reproducibles, Git |
| Vulnerabilidades web | CSRF, consultas preparadas, escape de salida, revisión de seguridad en Sprint 11 |

**Métricas:** burndown del sprint (puntos restantes por día, en `docs/burndown.md` o una hoja), velocidad (puntos terminados por sprint, promedio móvil de 3), defectos encontrados tras la Review y diferencias de caja por cierre (debe tender a cero).

## 9. Primer paso inmediato (mañana, Sprint 0)

1. `git init` en `C:\xampp\perfumeria`, `.gitignore` (`.env`, respaldos) y crear la estructura de carpetas de la sección 3.
2. Verificar versión de PHP y MariaDB en XAMPP; crear la base `perfumeria` con utf8mb4 y un usuario de BD dedicado (sin privilegios de UPDATE/DELETE sobre tablas de movimientos y auditoría).
3. Escribir `.env.example`, `src/Support/Db.php` (PDO con `ERRMODE_EXCEPTION` y emulación de preparadas desactivada) y el runner de migraciones.
4. Aplicar la migración `0001_schema.sql` (esquema completo, ya escrito) y el seed `0001_seed_base.sql`; crear el admin con `scripts/crear_admin.php` y el script de respaldo `scripts/backup.bat`.
5. Enviar al negocio la lista de datos iniciales y decisiones pendientes de la sección 2.
