-- =============================================================================
-- Perfumería - Migración 0001: esquema completo
-- Destino: MariaDB 10.4+ (probado en 10.4.32 de XAMPP). Pensado compatible con
--          MySQL 8.0 (sin acciones referenciales sobre columnas con CHECK o que
--          alimentan columnas generadas), pero solo se ha probado en MariaDB.
-- Motor InnoDB, utf8mb4 / utf8mb4_unicode_ci. Ejecutar sobre una base ya creada:
--   CREATE DATABASE perfumeria CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--
-- Convenciones:
--   * Dinero DECIMAL(14,2); gramos DECIMAL(10,3); costo por gramo/unidad DECIMAL(14,6).
--     Cantidades de artículos (unidades o ml) DECIMAL(12,3). Prohibido FLOAT/DOUBLE.
--   * IDs: TINYINT/SMALLINT para catálogos diminutos, INT UNSIGNED para maestros,
--     BIGINT UNSIGNED para tablas de alto volumen (ventas, movimientos, auditoría).
--   * Fechas DATETIME en hora local de Colombia (la app fija time_zone='-05:00'
--     al conectar). No se usa TIMESTAMP (límite 2038).
--   * IPs en VARBINARY(16) con INET6_ATON()/INET6_NTOA() (IPv4 e IPv6).
--   * Códigos (sku, códigos de barras, hashes) en ASCII: índices más pequeños.
--   * Tablas de movimientos/auditoría: SOLO INSERT (triggers al final + el usuario
--     de BD de la aplicación sin UPDATE/DELETE sobre ellas).
--   * "Un único X activo/principal" se impone con columna generada que vale 1 o
--     NULL + índice UNIQUE (NULL no colisiona en UNIQUE).
--   * Bloqueos: ver docs/BASE_DE_DATOS.md, sección "Orden de bloqueo".
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
SET SESSION default_storage_engine = InnoDB;
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- MÓDULO 0. Control de migraciones
-- =============================================================================
CREATE TABLE migraciones (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  archivo       VARCHAR(150)      NOT NULL,
  checksum_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
  aplicada_en   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_migraciones_archivo (archivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Migraciones aplicadas por scripts/migrate.php';

-- =============================================================================
-- MÓDULO 1. Núcleo: sucursales, configuración, consecutivos
-- =============================================================================
CREATE TABLE sucursales (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  nombre        VARCHAR(100) NOT NULL,
  nit           VARCHAR(20)  NULL,
  direccion     VARCHAR(150) NULL,
  ciudad        VARCHAR(60)  NULL,
  telefono      VARCHAR(20)  NULL,
  whatsapp      VARCHAR(20)  NULL,
  encabezado_ticket VARCHAR(500) NULL,
  activa        TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME    NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sucursales_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE config (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NULL COMMENT 'NULL = valor global',
  ambito        SMALLINT UNSIGNED GENERATED ALWAYS AS (IFNULL(sucursal_id, 0)) STORED,
  clave         VARCHAR(80) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  valor         VARCHAR(1000) NOT NULL,
  tipo          ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
  descripcion   VARCHAR(255) NULL,
  actualizado_por INT UNSIGNED NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_config_ambito_clave (ambito, clave),
  KEY ix_config_sucursal (sucursal_id),
  CONSTRAINT fk_config_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Parámetros: tope de descuento, % merma, IVA por defecto, vigencias. NUNCA secretos (van en .env)';

CREATE TABLE consecutivos (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  tipo          ENUM('VENTA','COMPRA','DEVOLUCION','APARTADO','CONTEO','BONO','RECIBO_CAJA','CODIGO_INTERNO','SKU','GASTO') NOT NULL,
  prefijo       VARCHAR(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  siguiente     BIGINT UNSIGNED NOT NULL DEFAULT 1,
  relleno       TINYINT UNSIGNED NOT NULL DEFAULT 6 COMMENT 'Dígitos con ceros a la izquierda al imprimir',
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_consecutivos_suc_tipo (sucursal_id, tipo),
  CONSTRAINT fk_consecutivos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT ck_consecutivos_siguiente CHECK (siguiente >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Numeración sin huecos: SELECT ... FOR UPDATE dentro de la misma transacción del documento';

-- =============================================================================
-- MÓDULO 2. Seguridad: roles, permisos, usuarios, tokens, intentos, auditoría
-- =============================================================================
CREATE TABLE roles (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(30) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  nombre        VARCHAR(60) NOT NULL,
  descripcion   VARCHAR(255) NULL,
  es_sistema    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'No se puede borrar desde la UI',
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permisos (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(60) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'modulo.accion, ej. ventas.anular',
  modulo        VARCHAR(30) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  descripcion   VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permisos_codigo (codigo),
  KEY ix_permisos_modulo (modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rol_permisos (
  rol_id        TINYINT UNSIGNED NOT NULL,
  permiso_id    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  KEY ix_rol_permisos_permiso (permiso_id),
  CONSTRAINT fk_rp_rol FOREIGN KEY (rol_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  rol_id        TINYINT UNSIGNED NOT NULL,
  usuario       VARCHAR(50)  NOT NULL COMMENT 'Login; único sin distinguir mayúsculas',
  email         VARCHAR(150) NULL,
  nombre        VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL COMMENT 'password_hash() Argon2id o bcrypt; nunca texto plano',
  pin_hash      VARCHAR(255) NULL COMMENT 'PIN de autorización (descuentos/anulaciones), también con password_hash',
  token_version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Claim ver del JWT; incrementar invalida todos los access tokens',
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 1,
  password_cambiado_en DATETIME NULL,
  intentos_fallidos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultimo_login_en DATETIME NULL,
  ultimo_login_ip VARBINARY(16) NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  eliminado_en  DATETIME NULL COMMENT 'Borrado lógico; nunca DELETE (integridad histórica)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_usuario (usuario),
  UNIQUE KEY uq_usuarios_email (email),
  KEY ix_usuarios_rol (rol_id),
  KEY ix_usuarios_sucursal_activo (sucursal_id, activo),
  CONSTRAINT fk_usuarios_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE config
  ADD CONSTRAINT fk_config_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios (id);

CREATE TABLE refresh_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id    INT UNSIGNED NOT NULL,
  familia_id    BINARY(16) NOT NULL COMMENT 'Sesión lógica (claim sid). Reutilización detectada => se revoca toda la familia',
  token_hash    BINARY(32) NOT NULL COMMENT 'SHA-256 del token opaco (32 bytes aleatorios); el token en claro solo viaja en la cookie',
  reemplazado_por BIGINT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME NOT NULL COMMENT 'Expiración por inactividad de este eslabón',
  sesion_expira_en DATETIME NOT NULL COMMENT 'Tope absoluto de la familia (no se extiende al rotar)',
  usado_en      DATETIME NULL COMMENT 'Momento de la rotación',
  revocado_en   DATETIME NULL,
  motivo_revocacion ENUM('logout','reutilizacion','cambio_password','admin','expirado','desactivado') NULL,
  ip            VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rt_token_hash (token_hash),
  KEY ix_rt_familia (familia_id),
  KEY ix_rt_usuario_revocado (usuario_id, revocado_en),
  KEY ix_rt_expira (expira_en),
  CONSTRAINT fk_rt_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_rt_reemplazo FOREIGN KEY (reemplazado_por) REFERENCES refresh_tokens (id),
  CONSTRAINT ck_rt_expira CHECK (expira_en > creado_en AND sesion_expira_en >= expira_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jwt_revocados (
  jti           BINARY(16) NOT NULL COMMENT 'jti (UUID/16 bytes aleatorios) del access token revocado',
  usuario_id    INT UNSIGNED NOT NULL,
  expira_en     DATETIME NOT NULL COMMENT '= exp del token; luego se purga (ya no sería válido)',
  revocado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  motivo        ENUM('logout','reutilizacion','cambio_password','admin','desactivado') NOT NULL,
  PRIMARY KEY (jti),
  KEY ix_jwtr_expira (expira_en),
  KEY ix_jwtr_usuario (usuario_id),
  CONSTRAINT fk_jwtr_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_intentos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_texto VARCHAR(50) NOT NULL COMMENT 'Lo que se tecleó, normalizado (trim+minúsculas)',
  usuario_id    INT UNSIGNED NULL,
  ip            VARBINARY(16) NOT NULL,
  exito         TINYINT(1) NOT NULL,
  motivo        ENUM('ok','password','usuario_inexistente','bloqueado','inactivo','rate_limit','csrf') NOT NULL,
  user_agent    VARCHAR(255) NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_li_ip_fecha (ip, creado_en),
  KEY ix_li_usuario_fecha (usuario_texto, creado_en),
  KEY ix_li_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sin FK a propósito: se registra aunque el usuario no exista. Purga > 90 días';

CREATE TABLE limites_tasa (
  clave         BINARY(32) NOT NULL COMMENT 'SHA-256 de "endpoint|ip" o "endpoint|usuario"',
  ventana_inicio DATETIME NOT NULL,
  contador      INT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  PRIMARY KEY (clave),
  KEY ix_lt_ventana (ventana_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rate limit genérico sin Redis (apto hosting compartido). INSERT ... ON DUPLICATE KEY UPDATE';

CREATE TABLE auditoria (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NULL,
  usuario_id    INT UNSIGNED NULL,
  accion        VARCHAR(60) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'ej. venta.anular, usuario.login',
  entidad       VARCHAR(60) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
  entidad_id    BIGINT UNSIGNED NULL,
  antes         JSON NULL,
  despues       JSON NULL,
  ip            VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  request_id    BINARY(16) NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_aud_entidad (entidad, entidad_id),
  KEY ix_aud_usuario_fecha (usuario_id, creado_en),
  KEY ix_aud_accion_fecha (accion, creado_en),
  KEY ix_aud_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Solo inserción. Sin FKs a propósito: permite archivar/particionar por año en el futuro y no bloquea borrados lógicos';

-- =============================================================================
-- MÓDULO 3. Maestro de artículos: categorías, marcas, proveedores, artículos,
--           códigos de barras, imágenes
-- Decisión: supertipo "articulos" (esencia | producto | envase | insumo) con
-- sku y codigo_barras únicos; imágenes y códigos cuelgan de él con FK real.
-- Descartado: (entidad_tipo, entidad_id) polimórfico, que no admite FK.
-- =============================================================================
CREATE TABLE categorias (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  padre_id      INT UNSIGNED NULL,
  tipo          ENUM('esencia','producto','envase','insumo','general') NOT NULL DEFAULT 'general',
  nombre        VARCHAR(80) NOT NULL,
  slug          VARCHAR(90) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  orden         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  activa        TINYINT(1) NOT NULL DEFAULT 1,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categorias_slug (slug),
  KEY ix_categorias_padre (padre_id),
  KEY ix_categorias_tipo_activa (tipo, activa, orden),
  CONSTRAINT fk_categorias_padre FOREIGN KEY (padre_id) REFERENCES categorias (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marcas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(80) NOT NULL,
  activa        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marcas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE proveedores (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nit           VARCHAR(20) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
  razon_social  VARCHAR(150) NOT NULL,
  contacto      VARCHAR(100) NULL,
  telefono      VARCHAR(20)  NULL,
  email         VARCHAR(150) NULL,
  direccion     VARCHAR(150) NULL,
  ciudad        VARCHAR(60)  NULL,
  plazo_pago_dias SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  notas         VARCHAR(500) NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proveedores_nit (nit),
  KEY ix_proveedores_nombre (razon_social)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE articulos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo          ENUM('esencia','producto','envase','insumo') NOT NULL,
  sku           VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'Interno: ESE-000123, PRD-, ENV-, INS-',
  codigo_barras VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Código principal (copia de codigos_barras con es_principal=1, misma transacción)',
  nombre        VARCHAR(150) NOT NULL,
  descripcion   VARCHAR(1000) NULL,
  categoria_id  INT UNSIGNED NULL,
  unidad_medida ENUM('g','ml','und') NOT NULL,
  tasa_iva      DECIMAL(5,2) NOT NULL DEFAULT 19.00,
  stock_minimo  DECIMAL(12,3) NOT NULL DEFAULT 0,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  creado_por    INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_articulos_sku (sku),
  UNIQUE KEY uq_articulos_codigo_barras (codigo_barras),
  KEY ix_articulos_tipo_activo_nombre (tipo, activo, nombre),
  KEY ix_articulos_categoria (categoria_id),
  FULLTEXT KEY ft_articulos_nombre (nombre, descripcion),
  CONSTRAINT fk_articulos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id),
  CONSTRAINT fk_articulos_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_articulos_iva CHECK (tasa_iva >= 0 AND tasa_iva <= 100),
  CONSTRAINT ck_articulos_stock_min CHECK (stock_minimo >= 0),
  CONSTRAINT ck_articulos_unidad CHECK (tipo <> 'esencia' OR unidad_medida = 'g')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Supertipo de todo ítem inventariable';

CREATE TABLE codigos_barras (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo_id   INT UNSIGNED NOT NULL,
  codigo        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Normalizado: UPC-A se guarda como EAN-13 con 0 inicial',
  simbologia    ENUM('EAN13','EAN8','UPCA','CODE128','INTERNO') NOT NULL,
  origen        ENUM('fabrica','interno') NOT NULL,
  es_principal  TINYINT(1) NOT NULL DEFAULT 0,
  principal_flag TINYINT GENERATED ALWAYS AS (IF(es_principal = 1, 1, NULL)) STORED,
  unidades      DECIMAL(10,3) NOT NULL DEFAULT 1 COMMENT 'Unidades que representa (caja x6 = 6)',
  creado_por    INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cb_codigo (codigo) COMMENT 'Unicidad GLOBAL: un código identifica un solo artículo',
  UNIQUE KEY uq_cb_un_principal (articulo_id, principal_flag),
  CONSTRAINT fk_cb_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_cb_usuario FOREIGN KEY (creado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_cb_ean13 CHECK (simbologia <> 'EAN13' OR codigo REGEXP '^[0-9]{13}$'),
  CONSTRAINT ck_cb_ean8 CHECK (simbologia <> 'EAN8' OR codigo REGEXP '^[0-9]{8}$'),
  CONSTRAINT ck_cb_upca CHECK (simbologia <> 'UPCA' OR codigo REGEXP '^[0-9]{12}$'),
  CONSTRAINT ck_cb_code128 CHECK (codigo REGEXP '^[ -~]{1,64}$'),
  CONSTRAINT ck_cb_unidades CHECK (unidades > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Todos los códigos (principal + alternos). El POS busca SOLO aquí por igualdad exacta';

CREATE TABLE imagenes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo_id   INT UNSIGNED NOT NULL,
  ruta          VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Relativa a storage/uploads, nombre = hex aleatorio; nunca el nombre original',
  ruta_miniatura VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL,
  hash_sha256   BINARY(32) NOT NULL COMMENT 'Del archivo final (re-codificado)',
  mime          ENUM('image/webp','image/jpeg','image/png') NOT NULL,
  ancho         SMALLINT UNSIGNED NOT NULL,
  alto          SMALLINT UNSIGNED NOT NULL,
  bytes         INT UNSIGNED NOT NULL,
  alt_texto     VARCHAR(150) NULL,
  orden         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  es_principal  TINYINT(1) NOT NULL DEFAULT 0,
  eliminado_en  DATETIME NULL,
  principal_flag TINYINT GENERATED ALWAYS AS (IF(es_principal = 1 AND eliminado_en IS NULL, 1, NULL)) STORED,
  subido_por    INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_img_ruta (ruta),
  UNIQUE KEY uq_img_un_principal (articulo_id, principal_flag),
  UNIQUE KEY uq_img_articulo_hash (articulo_id, hash_sha256),
  KEY ix_img_articulo_orden (articulo_id, eliminado_en, orden),
  CONSTRAINT fk_img_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_img_usuario FOREIGN KEY (subido_por) REFERENCES usuarios (id),
  CONSTRAINT ck_img_bytes CHECK (bytes > 0 AND bytes <= 5242880),
  CONSTRAINT ck_img_ruta CHECK (ruta REGEXP '^[a-z0-9/]+\\.(webp|jpg|png)$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Varias fotos por artículo; exactamente una principal vigente (impuesto por UNIQUE)';

-- =============================================================================
-- MÓDULO 4. Subtipos de artículo y existencias
-- =============================================================================
CREATE TABLE esencias (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo_id   INT UNSIGNED NOT NULL,
  inspiracion   VARCHAR(150) NULL COMMENT 'Perfume de referencia',
  marca_id      INT UNSIGNED NULL COMMENT 'Casa del perfume de referencia',
  genero        ENUM('femenino','masculino','unisex') NOT NULL DEFAULT 'unisex',
  familia_olfativa VARCHAR(60) NULL,
  densidad_g_ml DECIMAL(6,4) NOT NULL DEFAULT 1.0000 COMMENT 'Para convertir ml <-> g',
  proveedor_preferido_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_esencias_articulo (articulo_id),
  KEY ix_esencias_genero (genero),
  KEY ix_esencias_marca (marca_id),
  KEY ix_esencias_proveedor (proveedor_preferido_id),
  CONSTRAINT fk_esencias_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_esencias_marca FOREIGN KEY (marca_id) REFERENCES marcas (id),
  CONSTRAINT fk_esencias_proveedor FOREIGN KEY (proveedor_preferido_id) REFERENCES proveedores (id),
  CONSTRAINT ck_esencias_densidad CHECK (densidad_g_ml > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE envases_vacios (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo_id   INT UNSIGNED NOT NULL,
  capacidad_ml  DECIMAL(8,2) NOT NULL,
  material      ENUM('vidrio','plastico','aluminio','otro') NOT NULL DEFAULT 'vidrio',
  tipo_cierre   ENUM('atomizador','roll_on','tapa','gotero','otro') NOT NULL DEFAULT 'atomizador',
  color         VARCHAR(40) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_envases_articulo (articulo_id),
  KEY ix_envases_capacidad (capacidad_ml),
  CONSTRAINT fk_envases_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT ck_envases_capacidad CHECK (capacidad_ml > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE insumos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo_id   INT UNSIGNED NOT NULL,
  tipo          ENUM('alcohol','base','fijador','caja','bolsa','etiqueta','otro') NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_insumos_articulo (articulo_id),
  KEY ix_insumos_tipo (tipo),
  CONSTRAINT fk_insumos_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Alcohol, base, fijador, cajas, bolsas, etiquetas...';

CREATE TABLE productos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo_id   INT UNSIGNED NOT NULL,
  marca_id      INT UNSIGNED NULL,
  contenido_ml  DECIMAL(8,2) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_productos_articulo (articulo_id),
  KEY ix_productos_marca (marca_id),
  CONSTRAINT fk_productos_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_productos_marca FOREIGN KEY (marca_id) REFERENCES marcas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Productos de reventa 1:1 (perfumes originales, cremas, accesorios)';

CREATE TABLE existencias (
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  articulo_id   INT UNSIGNED NOT NULL,
  cantidad      DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Esencias: gramos totales = suma de recipientes',
  cantidad_reservada DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Apartados vigentes',
  costo_promedio DECIMAL(14,6) NOT NULL DEFAULT 0 COMMENT 'Promedio ponderado por unidad de medida (costo/g en esencias)',
  ultimo_costo  DECIMAL(14,6) NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sucursal_id, articulo_id),
  KEY ix_existencias_articulo (articulo_id),
  CONSTRAINT fk_existencias_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_existencias_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT ck_existencias_cantidad CHECK (cantidad >= 0),
  CONSTRAINT ck_existencias_reservada CHECK (cantidad_reservada >= 0 AND cantidad_reservada <= cantidad),
  CONSTRAINT ck_existencias_costo CHECK (costo_promedio >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Saldo vigente (caché transaccional del libro). Se bloquea con FOR UPDATE en orden de articulo_id';

-- =============================================================================
-- MÓDULO 5. Presentaciones y recetas
-- =============================================================================
CREATE TABLE presentaciones (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(30) NOT NULL,
  volumen_ml    DECIMAL(8,2) NOT NULL,
  precio_base   DECIMAL(14,2) NULL COMMENT 'Precio de venta por defecto (IVA incluido); catalogo_items puede sobrescribirlo',
  orden         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  activa        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_presentaciones_volumen (volumen_ml),
  CONSTRAINT ck_presentaciones_volumen CHECK (volumen_ml > 0),
  CONSTRAINT ck_presentaciones_precio CHECK (precio_base IS NULL OR precio_base >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recetas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  presentacion_id TINYINT UNSIGNED NOT NULL,
  esencia_id    INT UNSIGNED NULL COMMENT 'NULL = receta general de la presentación; con valor = excepción para esa esencia',
  esencia_clave INT UNSIGNED GENERATED ALWAYS AS (IFNULL(esencia_id, 0)) STORED,
  tipo          ENUM('normal','refill') NOT NULL DEFAULT 'normal' COMMENT 'refill = sin envase',
  activa        TINYINT(1) NOT NULL DEFAULT 1,
  notas         VARCHAR(255) NULL,
  actualizado_por INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recetas (presentacion_id, esencia_clave, tipo),
  KEY ix_recetas_esencia (esencia_id),
  CONSTRAINT fk_recetas_presentacion FOREIGN KEY (presentacion_id) REFERENCES presentaciones (id),
  CONSTRAINT fk_recetas_esencia FOREIGN KEY (esencia_id) REFERENCES esencias (id),
  CONSTRAINT fk_recetas_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Editar una receta no altera ventas pasadas: el costo queda congelado en venta_detalle_componentes';

CREATE TABLE receta_componentes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receta_id     INT UNSIGNED NOT NULL,
  tipo_componente ENUM('esencia','envase','alcohol','base','insumo') NOT NULL,
  articulo_id   INT UNSIGNED NULL COMMENT 'NULL solo para tipo esencia = "la esencia que se vende"',
  articulo_clave INT UNSIGNED GENERATED ALWAYS AS (IFNULL(articulo_id, 0)) STORED,
  cantidad      DECIMAL(10,3) NOT NULL,
  unidad        ENUM('g','ml','und') NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rc (receta_id, tipo_componente, articulo_clave),
  KEY ix_rc_articulo (articulo_id),
  CONSTRAINT fk_rc_receta FOREIGN KEY (receta_id) REFERENCES recetas (id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT ck_rc_cantidad CHECK (cantidad > 0),
  CONSTRAINT ck_rc_articulo CHECK (tipo_componente = 'esencia' OR articulo_id IS NOT NULL),
  CONSTRAINT ck_rc_unidad_esencia CHECK (tipo_componente <> 'esencia' OR unidad = 'g')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MÓDULO 6. Caja: métodos de pago, cajas, turnos, arqueos, movimientos
-- =============================================================================
CREATE TABLE metodos_pago (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(30) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  nombre        VARCHAR(50) NOT NULL,
  afecta_efectivo TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = entra/sale del cajón',
  requiere_referencia TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Nequi/Daviplata/tarjeta: nº de aprobación',
  es_pasivo     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'BONO, PUNTOS, APARTADO: consume un pasivo, no entra dinero',
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  orden         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mp_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cajas (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  nombre        VARCHAR(50) NOT NULL,
  activa        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cajas_suc_nombre (sucursal_id, nombre),
  CONSTRAINT fk_cajas_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE caja_turnos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  caja_id       SMALLINT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL COMMENT 'Responsable del turno',
  estado        ENUM('abierto','cerrado','revisado') NOT NULL DEFAULT 'abierto',
  abierto_flag  TINYINT GENERATED ALWAYS AS (IF(estado = 'abierto', 1, NULL)) STORED,
  abierto_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  monto_apertura DECIMAL(14,2) NOT NULL,
  cerrado_en    DATETIME NULL,
  cerrado_por   INT UNSIGNED NULL,
  efectivo_esperado DECIMAL(14,2) NULL,
  efectivo_contado  DECIMAL(14,2) NULL,
  diferencia    DECIMAL(14,2) NULL COMMENT 'contado - esperado',
  revisado_por  INT UNSIGNED NULL,
  revisado_en   DATETIME NULL,
  observacion   VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_turno_caja_abierta (caja_id, abierto_flag) COMMENT 'Máximo un turno abierto por caja',
  UNIQUE KEY uq_turno_usuario_abierto (usuario_id, abierto_flag) COMMENT 'Máximo un turno abierto por usuario',
  KEY ix_turnos_suc_fecha (sucursal_id, abierto_en),
  KEY ix_turnos_estado (estado),
  CONSTRAINT fk_turnos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_turnos_caja FOREIGN KEY (caja_id) REFERENCES cajas (id),
  CONSTRAINT fk_turnos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_turnos_cerrado_por FOREIGN KEY (cerrado_por) REFERENCES usuarios (id),
  CONSTRAINT fk_turnos_revisado_por FOREIGN KEY (revisado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_turnos_apertura CHECK (monto_apertura >= 0),
  CONSTRAINT ck_turnos_cierre CHECK (estado = 'abierto' OR (cerrado_en IS NOT NULL AND efectivo_contado IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE caja_arqueos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  turno_id      INT UNSIGNED NOT NULL,
  metodo_pago_id TINYINT UNSIGNED NOT NULL,
  esperado      DECIMAL(14,2) NOT NULL,
  contado       DECIMAL(14,2) NOT NULL,
  diferencia    DECIMAL(14,2) GENERATED ALWAYS AS (contado - esperado) STORED,
  detalle_denominaciones JSON NULL COMMENT 'Billetes/monedas contados (solo efectivo)',
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_arqueo_turno_metodo (turno_id, metodo_pago_id),
  KEY ix_arqueo_metodo (metodo_pago_id),
  CONSTRAINT fk_arqueo_turno FOREIGN KEY (turno_id) REFERENCES caja_turnos (id),
  CONSTRAINT fk_arqueo_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cierre ciego por método de pago';

CREATE TABLE caja_movimientos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  turno_id      INT UNSIGNED NOT NULL,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  tipo          ENUM('apertura','venta','abono_apartado','venta_bono','ingreso','retiro','gasto','pago_proveedor','devolucion','anulacion','ajuste') NOT NULL,
  metodo_pago_id TINYINT UNSIGNED NOT NULL,
  monto         DECIMAL(14,2) NOT NULL COMMENT 'Con signo: + entra, - sale',
  referencia_tipo ENUM('venta','apartado','bono','gasto','cxp_pago','devolucion','manual') NULL,
  referencia_id BIGINT UNSIGNED NULL,
  descripcion   VARCHAR(255) NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  autorizado_por INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_cm_turno_metodo (turno_id, metodo_pago_id),
  KEY ix_cm_referencia (referencia_tipo, referencia_id),
  KEY ix_cm_suc_fecha (sucursal_id, creado_en),
  KEY ix_cm_metodo (metodo_pago_id),
  CONSTRAINT fk_cm_turno FOREIGN KEY (turno_id) REFERENCES caja_turnos (id),
  CONSTRAINT fk_cm_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_cm_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id),
  CONSTRAINT fk_cm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_cm_autoriza FOREIGN KEY (autorizado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_cm_signo CHECK (
    (tipo IN ('apertura','venta','abono_apartado','venta_bono','ingreso') AND monto >= 0) OR
    (tipo IN ('retiro','gasto','pago_proveedor','devolucion','anulacion') AND monto <= 0) OR
    (tipo = 'ajuste'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Libro de caja, solo inserción. Una anulación es un movimiento negativo, nunca un UPDATE';

-- =============================================================================
-- MÓDULO 7. Compras, lotes, recipientes y libros de inventario
-- =============================================================================
CREATE TABLE compras (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  proveedor_id  INT UNSIGNED NOT NULL,
  prefijo       VARCHAR(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  numero        BIGINT UNSIGNED NOT NULL,
  factura_proveedor VARCHAR(40) NULL,
  fecha         DATE NOT NULL,
  estado        ENUM('borrador','recibida','anulada') NOT NULL DEFAULT 'borrador',
  forma_pago    ENUM('contado','credito') NOT NULL,
  subtotal      DECIMAL(14,2) NOT NULL DEFAULT 0,
  descuento     DECIMAL(14,2) NOT NULL DEFAULT 0,
  iva           DECIMAL(14,2) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  usuario_id    INT UNSIGNED NOT NULL,
  recibida_en   DATETIME NULL,
  anulada_por   INT UNSIGNED NULL,
  anulada_en    DATETIME NULL,
  motivo_anulacion VARCHAR(255) NULL,
  observacion   VARCHAR(500) NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_compras_numero (sucursal_id, prefijo, numero),
  UNIQUE KEY uq_compras_factura_prov (proveedor_id, factura_proveedor) COMMENT 'Evita registrar dos veces la misma factura',
  KEY ix_compras_suc_fecha (sucursal_id, fecha),
  KEY ix_compras_estado (estado),
  CONSTRAINT fk_compras_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_compras_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
  CONSTRAINT fk_compras_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_compras_anula FOREIGN KEY (anulada_por) REFERENCES usuarios (id),
  CONSTRAINT ck_compras_montos CHECK (subtotal >= 0 AND descuento >= 0 AND iva >= 0 AND total >= 0),
  CONSTRAINT ck_compras_total CHECK (total = subtotal - descuento + iva)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compra_detalles (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  compra_id     INT UNSIGNED NOT NULL,
  articulo_id   INT UNSIGNED NOT NULL,
  cantidad      DECIMAL(12,3) NOT NULL COMMENT 'g para esencias, ml o und para el resto',
  costo_unitario DECIMAL(14,6) NOT NULL COMMENT 'Sin IVA, después de descuento',
  tasa_iva      DECIMAL(5,2) NOT NULL DEFAULT 0,
  subtotal      DECIMAL(14,2) NOT NULL,
  iva           DECIMAL(14,2) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_cd_compra (compra_id),
  KEY ix_cd_articulo (articulo_id),
  CONSTRAINT fk_cd_compra FOREIGN KEY (compra_id) REFERENCES compras (id),
  CONSTRAINT fk_cd_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT ck_cd_cantidad CHECK (cantidad > 0),
  CONSTRAINT ck_cd_costo CHECK (costo_unitario >= 0),
  CONSTRAINT ck_cd_total CHECK (total = subtotal + iva)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lotes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  articulo_id   INT UNSIGNED NOT NULL,
  proveedor_id  INT UNSIGNED NULL,
  compra_detalle_id BIGINT UNSIGNED NULL,
  codigo_lote   VARCHAR(40) NULL COMMENT 'Lote del fabricante',
  fecha_vencimiento DATE NULL,
  cantidad_inicial DECIMAL(12,3) NOT NULL,
  saldo         DECIMAL(12,3) NOT NULL,
  costo_unitario DECIMAL(14,6) NOT NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_lotes_articulo (sucursal_id, articulo_id, fecha_vencimiento),
  KEY ix_lotes_compra (compra_detalle_id),
  KEY ix_lotes_proveedor (proveedor_id),
  KEY ix_lotes_vencimiento (fecha_vencimiento),
  CONSTRAINT fk_lotes_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_lotes_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_lotes_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
  CONSTRAINT fk_lotes_cd FOREIGN KEY (compra_detalle_id) REFERENCES compra_detalles (id),
  CONSTRAINT ck_lotes_saldo CHECK (cantidad_inicial > 0 AND saldo >= 0 AND saldo <= cantidad_inicial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trazabilidad/vencimientos. El costeo es promedio ponderado (existencias), no por lote';

CREATE TABLE recipientes_esencia (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  esencia_id    INT UNSIGNED NOT NULL,
  codigo        VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Etiqueta física con código de barras (Code128)',
  descripcion   VARCHAR(100) NULL,
  capacidad_g   DECIMAL(10,3) NULL,
  tara_g        DECIMAL(10,3) NULL COMMENT 'Peso del recipiente vacío para pesar en báscula',
  gramos_actuales DECIMAL(10,3) NOT NULL DEFAULT 0,
  lote_id       INT UNSIGNED NULL,
  estado        ENUM('activo','vacio','baja') NOT NULL DEFAULT 'activo',
  ubicacion     VARCHAR(60) NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipientes_codigo (codigo),
  KEY ix_recipientes_esencia (esencia_id, sucursal_id, estado),
  KEY ix_recipientes_sucursal (sucursal_id),
  KEY ix_recipientes_lote (lote_id),
  CONSTRAINT fk_recipientes_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_recipientes_esencia FOREIGN KEY (esencia_id) REFERENCES esencias (id),
  CONSTRAINT fk_recipientes_lote FOREIGN KEY (lote_id) REFERENCES lotes (id),
  CONSTRAINT ck_recipientes_gramos CHECK (gramos_actuales >= 0),
  CONSTRAINT ck_recipientes_capacidad CHECK (capacidad_g IS NULL OR (capacidad_g > 0 AND gramos_actuales <= capacidad_g)),
  CONSTRAINT ck_recipientes_tara CHECK (tara_g IS NULL OR tara_g >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE movimientos_esencia (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  esencia_id    INT UNSIGNED NOT NULL,
  recipiente_id INT UNSIGNED NOT NULL,
  tipo          ENUM('saldo_inicial','compra','trasvase_salida','trasvase_entrada','merma_trasvase','venta','apartado_entrega','devolucion','anulacion_venta','anulacion_compra','ajuste_conteo','merma') NOT NULL,
  gramos        DECIMAL(10,3) NOT NULL COMMENT 'Con signo: + entra, - sale',
  saldo_recipiente DECIMAL(10,3) NOT NULL COMMENT 'Saldo del recipiente tras el movimiento',
  costo_g       DECIMAL(14,6) NOT NULL COMMENT 'Costo por gramo aplicado (promedio vigente o costo de compra)',
  operacion_id  BINARY(16) NULL COMMENT 'Agrupa salida+entrada+merma de un trasvase',
  referencia_tipo ENUM('compra','venta','devolucion','conteo','apartado','trasvase','manual') NULL,
  referencia_id BIGINT UNSIGNED NULL,
  motivo        VARCHAR(255) NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_me_recipiente (recipiente_id, id),
  KEY ix_me_esencia_fecha (esencia_id, sucursal_id, creado_en),
  KEY ix_me_referencia (referencia_tipo, referencia_id),
  KEY ix_me_tipo_fecha (tipo, creado_en),
  KEY ix_me_operacion (operacion_id),
  KEY ix_me_sucursal (sucursal_id),
  CONSTRAINT fk_me_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_me_esencia FOREIGN KEY (esencia_id) REFERENCES esencias (id),
  CONSTRAINT fk_me_recipiente FOREIGN KEY (recipiente_id) REFERENCES recipientes_esencia (id),
  CONSTRAINT fk_me_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT ck_me_gramos CHECK (gramos <> 0),
  CONSTRAINT ck_me_saldo CHECK (saldo_recipiente >= 0),
  CONSTRAINT ck_me_costo CHECK (costo_g >= 0),
  CONSTRAINT ck_me_signo CHECK (
    (tipo IN ('saldo_inicial','compra','trasvase_entrada','devolucion','anulacion_venta') AND gramos > 0) OR
    (tipo IN ('trasvase_salida','merma_trasvase','venta','apartado_entrega','anulacion_compra','merma') AND gramos < 0) OR
    (tipo = 'ajuste_conteo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kardex de esencias por recipiente (gramos). Solo inserción';

CREATE TABLE movimientos_inventario (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  articulo_id   INT UNSIGNED NOT NULL COMMENT 'Productos, envases e insumos (las esencias usan movimientos_esencia)',
  tipo          ENUM('saldo_inicial','compra','venta','consumo_receta','apartado_entrega','devolucion','anulacion_venta','anulacion_compra','ajuste_conteo','merma','reserva','liberacion_reserva') NOT NULL,
  cantidad      DECIMAL(12,3) NOT NULL COMMENT 'Con signo. reserva/liberación no cambian cantidad sino cantidad_reservada',
  saldo_despues DECIMAL(12,3) NOT NULL,
  costo_unitario DECIMAL(14,6) NOT NULL,
  costo_promedio_despues DECIMAL(14,6) NOT NULL,
  lote_id       INT UNSIGNED NULL,
  referencia_tipo ENUM('compra','venta','devolucion','conteo','apartado','manual') NULL,
  referencia_id BIGINT UNSIGNED NULL,
  motivo        VARCHAR(255) NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_mi_articulo_fecha (articulo_id, sucursal_id, creado_en),
  KEY ix_mi_referencia (referencia_tipo, referencia_id),
  KEY ix_mi_tipo_fecha (tipo, creado_en),
  KEY ix_mi_sucursal_fecha (sucursal_id, creado_en),
  KEY ix_mi_lote (lote_id),
  CONSTRAINT fk_mi_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_mi_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_mi_lote FOREIGN KEY (lote_id) REFERENCES lotes (id),
  CONSTRAINT fk_mi_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT ck_mi_cantidad CHECK (cantidad <> 0),
  CONSTRAINT ck_mi_saldo CHECK (saldo_despues >= 0),
  CONSTRAINT ck_mi_costo CHECK (costo_unitario >= 0 AND costo_promedio_despues >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kardex de artículos por unidad/ml. Solo inserción';

-- =============================================================================
-- MÓDULO 8. Cuentas por pagar, gastos y cierre mensual
-- =============================================================================
CREATE TABLE cxp (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  proveedor_id  INT UNSIGNED NOT NULL,
  compra_id     INT UNSIGNED NULL,
  numero_documento VARCHAR(40) NULL,
  fecha_emision DATE NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  monto_original DECIMAL(14,2) NOT NULL,
  saldo         DECIMAL(14,2) NOT NULL,
  estado        ENUM('pendiente','parcial','pagada','anulada') NOT NULL DEFAULT 'pendiente',
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cxp_compra (compra_id),
  KEY ix_cxp_proveedor_estado (proveedor_id, estado),
  KEY ix_cxp_estado_venc (estado, fecha_vencimiento),
  KEY ix_cxp_sucursal (sucursal_id),
  CONSTRAINT fk_cxp_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_cxp_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
  CONSTRAINT fk_cxp_compra FOREIGN KEY (compra_id) REFERENCES compras (id),
  CONSTRAINT ck_cxp_montos CHECK (monto_original > 0 AND saldo >= 0 AND saldo <= monto_original),
  CONSTRAINT ck_cxp_fechas CHECK (fecha_vencimiento >= fecha_emision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cxp_pagos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cxp_id        INT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  monto         DECIMAL(14,2) NOT NULL,
  metodo_pago_id TINYINT UNSIGNED NOT NULL,
  caja_movimiento_id BIGINT UNSIGNED NULL COMMENT 'Si se pagó desde el cajón',
  referencia    VARCHAR(60) NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  anulado_en    DATETIME NULL,
  anulado_por   INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_cxpp_cxp (cxp_id),
  KEY ix_cxpp_fecha (fecha),
  KEY ix_cxpp_metodo (metodo_pago_id),
  KEY ix_cxpp_cm (caja_movimiento_id),
  CONSTRAINT fk_cxpp_cxp FOREIGN KEY (cxp_id) REFERENCES cxp (id),
  CONSTRAINT fk_cxpp_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id),
  CONSTRAINT fk_cxpp_cm FOREIGN KEY (caja_movimiento_id) REFERENCES caja_movimientos (id),
  CONSTRAINT fk_cxpp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_cxpp_anula FOREIGN KEY (anulado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_cxpp_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias_gasto (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(60) NOT NULL,
  activa        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catgasto_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gastos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  categoria_id  SMALLINT UNSIGNED NOT NULL,
  fecha         DATE NOT NULL,
  periodo       MEDIUMINT UNSIGNED GENERATED ALWAYS AS (YEAR(fecha) * 100 + MONTH(fecha)) STORED COMMENT 'AAAAMM',
  concepto      VARCHAR(200) NOT NULL,
  monto         DECIMAL(14,2) NOT NULL,
  metodo_pago_id TINYINT UNSIGNED NOT NULL,
  caja_movimiento_id BIGINT UNSIGNED NULL,
  proveedor_id  INT UNSIGNED NULL,
  soporte_ruta  VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Foto/PDF del soporte en storage/, nombre aleatorio',
  usuario_id    INT UNSIGNED NOT NULL,
  anulado_en    DATETIME NULL,
  anulado_por   INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_gastos_suc_periodo (sucursal_id, periodo, anulado_en),
  KEY ix_gastos_suc_fecha (sucursal_id, fecha),
  KEY ix_gastos_categoria (categoria_id),
  KEY ix_gastos_metodo (metodo_pago_id),
  KEY ix_gastos_cm (caja_movimiento_id),
  KEY ix_gastos_proveedor (proveedor_id),
  CONSTRAINT fk_gastos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_gastos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_gasto (id),
  CONSTRAINT fk_gastos_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id),
  CONSTRAINT fk_gastos_cm FOREIGN KEY (caja_movimiento_id) REFERENCES caja_movimientos (id),
  CONSTRAINT fk_gastos_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
  CONSTRAINT fk_gastos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_gastos_anula FOREIGN KEY (anulado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_gastos_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cierres_mensuales (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  periodo       MEDIUMINT UNSIGNED NOT NULL COMMENT 'AAAAMM',
  ventas_netas  DECIMAL(14,2) NOT NULL COMMENT 'Sin IVA, sin bonos (pasivo), menos devoluciones',
  cogs          DECIMAL(14,2) NOT NULL,
  gastos        DECIMAL(14,2) NOT NULL,
  utilidad      DECIMAL(14,2) NOT NULL,
  cerrado_por   INT UNSIGNED NOT NULL,
  cerrado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cierre_suc_periodo (sucursal_id, periodo),
  CONSTRAINT fk_cierre_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_cierre_usuario FOREIGN KEY (cerrado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_cierre_periodo CHECK (periodo BETWEEN 202001 AND 209912 AND periodo % 100 BETWEEN 1 AND 12),
  CONSTRAINT ck_cierre_utilidad CHECK (utilidad = ventas_netas - cogs - gastos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Período cerrado = la app rechaza documentos con fecha en ese período';

-- =============================================================================
-- MÓDULO 9. Conteos físicos
-- =============================================================================
CREATE TABLE conteos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  numero        BIGINT UNSIGNED NOT NULL,
  tipo          ENUM('total','parcial','ciclico') NOT NULL,
  estado        ENUM('abierto','cerrado','aplicado','anulado') NOT NULL DEFAULT 'abierto',
  iniciado_por  INT UNSIGNED NOT NULL,
  iniciado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aplicado_por  INT UNSIGNED NULL,
  aplicado_en   DATETIME NULL,
  observacion   VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conteos_numero (sucursal_id, numero),
  KEY ix_conteos_estado (estado),
  CONSTRAINT fk_conteos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_conteos_inicia FOREIGN KEY (iniciado_por) REFERENCES usuarios (id),
  CONSTRAINT fk_conteos_aplica FOREIGN KEY (aplicado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conteo_detalles (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conteo_id     INT UNSIGNED NOT NULL,
  articulo_id   INT UNSIGNED NULL COMMENT 'Productos/envases/insumos',
  recipiente_id INT UNSIGNED NULL COMMENT 'Esencias: se pesa cada recipiente',
  cantidad_sistema DECIMAL(12,3) NOT NULL COMMENT 'Foto del saldo al iniciar',
  cantidad_contada DECIMAL(12,3) NULL,
  diferencia    DECIMAL(12,3) GENERATED ALWAYS AS (cantidad_contada - cantidad_sistema) STORED,
  costo_unitario DECIMAL(14,6) NOT NULL,
  motivo        VARCHAR(255) NULL,
  contado_por   INT UNSIGNED NULL,
  contado_en    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cdet_articulo (conteo_id, articulo_id),
  UNIQUE KEY uq_cdet_recipiente (conteo_id, recipiente_id),
  KEY ix_cdet_articulo (articulo_id),
  KEY ix_cdet_recipiente (recipiente_id),
  CONSTRAINT fk_cdet_conteo FOREIGN KEY (conteo_id) REFERENCES conteos (id),
  CONSTRAINT fk_cdet_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_cdet_recipiente FOREIGN KEY (recipiente_id) REFERENCES recipientes_esencia (id),
  CONSTRAINT fk_cdet_usuario FOREIGN KEY (contado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_cdet_xor CHECK ((articulo_id IS NULL) <> (recipiente_id IS NULL)),
  CONSTRAINT ck_cdet_cantidades CHECK (cantidad_sistema >= 0 AND (cantidad_contada IS NULL OR cantidad_contada >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Al aplicar, la diferencia se registra como movimiento ajuste_conteo (nunca se edita el saldo a mano)';

-- =============================================================================
-- MÓDULO 10. Clientes (habeas data, Ley 1581 de 2012) y fidelización (reglas)
-- =============================================================================
CREATE TABLE clientes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL COMMENT 'Sucursal de registro',
  tipo_documento ENUM('CC','CE','NIT','PAS','TI','PPT','OTRO') NULL,
  numero_documento VARCHAR(20) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
  nombres       VARCHAR(80) NOT NULL,
  apellidos     VARCHAR(80) NULL,
  telefono      VARCHAR(20) CHARACTER SET ascii COLLATE ascii_general_ci NULL COMMENT 'E.164 sin espacios, ej. +573001234567',
  email         VARCHAR(150) NULL,
  fecha_nacimiento DATE NULL,
  acepta_tratamiento TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Autorización de tratamiento de datos (obligatoria para guardar datos más allá de la venta)',
  acepta_marketing TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Finalidad separada: promociones por WhatsApp/email',
  consentimiento_version VARCHAR(20) NULL,
  consentimiento_en DATETIME NULL,
  puntos_saldo  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Caché de fidelizacion_movimientos (se bloquea FOR UPDATE al acumular/redimir)',
  sellos_saldo  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  anonimizado_en DATETIME NULL COMMENT 'Supresión: se borran datos personales y se conserva el id para la contabilidad',
  creado_por    INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clientes_documento (tipo_documento, numero_documento),
  KEY ix_clientes_telefono (telefono),
  KEY ix_clientes_email (email),
  KEY ix_clientes_nombre (nombres, apellidos),
  KEY ix_clientes_sucursal (sucursal_id),
  CONSTRAINT fk_clientes_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_clientes_usuario FOREIGN KEY (creado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_clientes_consent CHECK (acepta_tratamiento = 0 OR (consentimiento_version IS NOT NULL AND consentimiento_en IS NOT NULL)),
  CONSTRAINT ck_clientes_marketing CHECK (acepta_marketing = 0 OR acepta_tratamiento = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cliente_consentimientos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id    INT UNSIGNED NOT NULL,
  finalidad     ENUM('tratamiento','marketing') NOT NULL,
  otorgado      TINYINT(1) NOT NULL COMMENT '1 = otorga, 0 = revoca',
  version_texto VARCHAR(20) NOT NULL,
  canal         ENUM('pos','web','whatsapp','papel') NOT NULL,
  ip            VARBINARY(16) NULL,
  usuario_id    INT UNSIGNED NULL COMMENT 'Empleado que lo registró en POS',
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_cc_cliente (cliente_id, finalidad, creado_en),
  KEY ix_cc_usuario (usuario_id),
  CONSTRAINT fk_cc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
  CONSTRAINT fk_cc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Prueba del consentimiento (historial, solo inserción)';

CREATE TABLE fidelizacion_reglas (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NULL COMMENT 'NULL = todas',
  nombre        VARCHAR(80) NOT NULL,
  tipo          ENUM('puntos','sellos') NOT NULL,
  monto_por_punto DECIMAL(14,2) NULL COMMENT 'Puntos: cada X pesos = 1 punto',
  valor_punto   DECIMAL(14,2) NULL COMMENT 'Puntos: valor en pesos al redimir',
  sellos_para_premio TINYINT UNSIGNED NULL,
  vigencia_dias SMALLINT UNSIGNED NULL,
  vigente_desde DATE NOT NULL,
  vigente_hasta DATE NULL,
  activa        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_fr_vigencia (activa, vigente_desde, vigente_hasta),
  KEY ix_fr_sucursal (sucursal_id),
  CONSTRAINT fk_fr_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT ck_fr_tipo CHECK (
    (tipo = 'puntos' AND monto_por_punto > 0 AND valor_punto >= 0) OR
    (tipo = 'sellos' AND sellos_para_premio > 0)),
  CONSTRAINT ck_fr_fechas CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MÓDULO 11. Precios, promociones y catálogo
-- =============================================================================
CREATE TABLE promociones (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NULL,
  nombre        VARCHAR(100) NOT NULL,
  tipo          ENUM('porcentaje','monto_fijo','nxm','precio_especial') NOT NULL,
  valor         DECIMAL(14,2) NOT NULL,
  aplica_a      ENUM('todo','categoria','articulo','presentacion') NOT NULL,
  categoria_id  INT UNSIGNED NULL,
  articulo_id   INT UNSIGNED NULL,
  presentacion_id TINYINT UNSIGNED NULL,
  compra_minima DECIMAL(14,2) NULL,
  codigo_cupon  VARCHAR(30) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
  usos_max      INT UNSIGNED NULL,
  usos_actuales INT UNSIGNED NOT NULL DEFAULT 0,
  acumulable    TINYINT(1) NOT NULL DEFAULT 0,
  prioridad     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  vigente_desde DATETIME NOT NULL,
  vigente_hasta DATETIME NOT NULL,
  activa        TINYINT(1) NOT NULL DEFAULT 1,
  creado_por    INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promo_cupon (codigo_cupon),
  KEY ix_promo_vigencia (activa, vigente_desde, vigente_hasta),
  KEY ix_promo_articulo (articulo_id),
  KEY ix_promo_categoria (categoria_id),
  KEY ix_promo_presentacion (presentacion_id),
  KEY ix_promo_sucursal (sucursal_id),
  CONSTRAINT fk_promo_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_promo_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id),
  CONSTRAINT fk_promo_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_promo_presentacion FOREIGN KEY (presentacion_id) REFERENCES presentaciones (id),
  CONSTRAINT fk_promo_usuario FOREIGN KEY (creado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_promo_valor CHECK (valor >= 0 AND (tipo <> 'porcentaje' OR valor <= 100)),
  CONSTRAINT ck_promo_fechas CHECK (vigente_hasta > vigente_desde),
  CONSTRAINT ck_promo_destino CHECK (
    (aplica_a = 'todo') OR
    (aplica_a = 'categoria' AND categoria_id IS NOT NULL) OR
    (aplica_a = 'articulo' AND articulo_id IS NOT NULL) OR
    (aplica_a = 'presentacion' AND presentacion_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE catalogo_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  tipo          ENUM('producto','preparado') NOT NULL,
  articulo_id   INT UNSIGNED NULL COMMENT 'tipo producto',
  esencia_id    INT UNSIGNED NULL COMMENT 'tipo preparado',
  presentacion_id TINYINT UNSIGNED NULL COMMENT 'tipo preparado',
  clave_unica   VARCHAR(40) CHARACTER SET ascii GENERATED ALWAYS AS (
                  CONCAT(tipo, ':', IFNULL(articulo_id, 0), ':', IFNULL(esencia_id, 0), ':', IFNULL(presentacion_id, 0))) STORED,
  slug          VARCHAR(160) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  nombre_publico VARCHAR(150) NOT NULL,
  descripcion_publica VARCHAR(2000) NULL,
  precio_venta  DECIMAL(14,2) NULL COMMENT 'IVA incluido. NULL en preparado = usar presentaciones.precio_base',
  publicado     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Visible en el catálogo público',
  vendible_pos  TINYINT(1) NOT NULL DEFAULT 1,
  destacado     TINYINT(1) NOT NULL DEFAULT 0,
  orden         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_slug (sucursal_id, slug),
  UNIQUE KEY uq_cat_item (sucursal_id, clave_unica),
  KEY ix_cat_publicado (sucursal_id, publicado, destacado, orden),
  KEY ix_cat_articulo (articulo_id),
  KEY ix_cat_esencia (esencia_id),
  KEY ix_cat_presentacion (presentacion_id),
  CONSTRAINT fk_cat_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_cat_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_cat_esencia FOREIGN KEY (esencia_id) REFERENCES esencias (id),
  CONSTRAINT fk_cat_presentacion FOREIGN KEY (presentacion_id) REFERENCES presentaciones (id),
  CONSTRAINT ck_cat_tipo CHECK (
    (tipo = 'producto'  AND articulo_id IS NOT NULL AND esencia_id IS NULL AND presentacion_id IS NULL AND precio_venta IS NOT NULL) OR
    (tipo = 'preparado' AND articulo_id IS NULL AND esencia_id IS NOT NULL AND presentacion_id IS NOT NULL)),
  CONSTRAINT ck_cat_precio CHECK (precio_venta IS NULL OR precio_venta >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lista de precios de venta (POS) y fuente del catálogo público. Sin columnas de costo';

-- =============================================================================
-- MÓDULO 12. Ventas, bonos y pagos
-- Orden sin ciclos: ventas -> bonos -> venta_detalles -> componentes -> pagos -> bono_movimientos
-- =============================================================================
CREATE TABLE ventas (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  caja_turno_id INT UNSIGNED NOT NULL,
  prefijo       VARCHAR(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  numero        BIGINT UNSIGNED NOT NULL,
  idempotency_key BINARY(16) NOT NULL COMMENT 'Generada por el formulario del POS: evita ventas duplicadas por doble clic/reintento',
  fecha         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cliente_id    INT UNSIGNED NULL,
  usuario_id    INT UNSIGNED NOT NULL COMMENT 'Vendedor',
  origen        ENUM('pos','apartado','catalogo_whatsapp') NOT NULL DEFAULT 'pos',
  estado        ENUM('completada','anulada','devuelta_parcial','devuelta_total') NOT NULL DEFAULT 'completada',
  subtotal      DECIMAL(14,2) NOT NULL COMMENT 'Base gravable (sin IVA), ya con descuentos',
  descuento_total DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Informativo (ya restado)',
  iva_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL,
  costo_total   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'COGS congelado (suma de componentes)',
  descuento_autorizado_por INT UNSIGNED NULL,
  observacion   VARCHAR(500) NULL,
  anulada_en    DATETIME NULL,
  anulada_por   INT UNSIGNED NULL,
  motivo_anulacion VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ventas_numero (sucursal_id, prefijo, numero),
  UNIQUE KEY uq_ventas_idempotencia (idempotency_key),
  KEY ix_ventas_suc_fecha (sucursal_id, fecha, estado),
  KEY ix_ventas_turno (caja_turno_id),
  KEY ix_ventas_cliente_fecha (cliente_id, fecha),
  KEY ix_ventas_usuario_fecha (usuario_id, fecha),
  KEY ix_ventas_estado_fecha (estado, fecha),
  CONSTRAINT fk_ventas_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_ventas_turno FOREIGN KEY (caja_turno_id) REFERENCES caja_turnos (id),
  CONSTRAINT fk_ventas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
  CONSTRAINT fk_ventas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_ventas_autoriza FOREIGN KEY (descuento_autorizado_por) REFERENCES usuarios (id),
  CONSTRAINT fk_ventas_anula FOREIGN KEY (anulada_por) REFERENCES usuarios (id),
  CONSTRAINT ck_ventas_montos CHECK (subtotal >= 0 AND descuento_total >= 0 AND iva_total >= 0 AND costo_total >= 0),
  CONSTRAINT ck_ventas_total CHECK (total = subtotal + iva_total),
  CONSTRAINT ck_ventas_anulacion CHECK (estado <> 'anulada' OR (anulada_en IS NOT NULL AND anulada_por IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='DIAN excluida: solo subtotal/iva/total + consecutivo sin huecos';

CREATE TABLE bonos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  codigo_hash   BINARY(32) NOT NULL COMMENT 'SHA-256 del código (es un título al portador: no se guarda en claro)',
  codigo_ultimos CHAR(4) CHARACTER SET ascii NOT NULL COMMENT 'Últimos 4 para identificarlo en pantalla',
  valor_inicial DECIMAL(14,2) NOT NULL,
  saldo         DECIMAL(14,2) NOT NULL,
  estado        ENUM('activo','agotado','vencido','anulado') NOT NULL DEFAULT 'activo',
  emitido_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vence_en      DATE NOT NULL,
  venta_id      BIGINT UNSIGNED NULL COMMENT 'Venta en la que se vendió (NULL si es por devolución/cortesía)',
  origen        ENUM('venta','devolucion','cortesia') NOT NULL DEFAULT 'venta',
  cliente_id    INT UNSIGNED NULL,
  beneficiario  VARCHAR(100) NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bonos_codigo (codigo_hash),
  KEY ix_bonos_estado_vence (estado, vence_en),
  KEY ix_bonos_venta (venta_id),
  KEY ix_bonos_cliente (cliente_id),
  KEY ix_bonos_sucursal (sucursal_id),
  CONSTRAINT fk_bonos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_bonos_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
  CONSTRAINT fk_bonos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
  CONSTRAINT fk_bonos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT ck_bonos_saldo CHECK (valor_inicial > 0 AND saldo >= 0 AND saldo <= valor_inicial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pasivo hasta su redención';

CREATE TABLE venta_detalles (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id      BIGINT UNSIGNED NOT NULL,
  linea         SMALLINT UNSIGNED NOT NULL,
  tipo          ENUM('producto','preparado','bono') NOT NULL,
  catalogo_item_id INT UNSIGNED NULL,
  articulo_id   INT UNSIGNED NULL COMMENT 'producto',
  esencia_id    INT UNSIGNED NULL COMMENT 'preparado',
  presentacion_id TINYINT UNSIGNED NULL COMMENT 'preparado',
  receta_id     INT UNSIGNED NULL COMMENT 'Receta usada (referencia; el costo real está en componentes)',
  bono_id       INT UNSIGNED NULL COMMENT 'bono vendido',
  promocion_id  INT UNSIGNED NULL,
  descripcion   VARCHAR(150) NOT NULL COMMENT 'Nombre congelado al vender',
  cantidad      DECIMAL(10,3) NOT NULL,
  precio_unitario DECIMAL(14,2) NOT NULL COMMENT 'IVA incluido, antes de descuento',
  descuento     DECIMAL(14,2) NOT NULL DEFAULT 0,
  tasa_iva      DECIMAL(5,2) NOT NULL,
  base          DECIMAL(14,2) NOT NULL,
  iva           DECIMAL(14,2) NOT NULL,
  total         DECIMAL(14,2) NOT NULL COMMENT '= cantidad*precio_unitario - descuento',
  costo_unitario DECIMAL(14,6) NOT NULL DEFAULT 0 COMMENT 'Congelado',
  costo_total   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Congelado (suma de componentes)',
  cantidad_devuelta DECIMAL(10,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vd_linea (venta_id, linea),
  KEY ix_vd_articulo (articulo_id),
  KEY ix_vd_esencia_pres (esencia_id, presentacion_id),
  KEY ix_vd_presentacion (presentacion_id),
  KEY ix_vd_catalogo (catalogo_item_id),
  KEY ix_vd_receta (receta_id),
  KEY ix_vd_bono (bono_id),
  KEY ix_vd_promocion (promocion_id),
  CONSTRAINT fk_vd_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
  CONSTRAINT fk_vd_catalogo FOREIGN KEY (catalogo_item_id) REFERENCES catalogo_items (id),
  CONSTRAINT fk_vd_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_vd_esencia FOREIGN KEY (esencia_id) REFERENCES esencias (id),
  CONSTRAINT fk_vd_presentacion FOREIGN KEY (presentacion_id) REFERENCES presentaciones (id),
  CONSTRAINT fk_vd_receta FOREIGN KEY (receta_id) REFERENCES recetas (id),
  CONSTRAINT fk_vd_bono FOREIGN KEY (bono_id) REFERENCES bonos (id),
  CONSTRAINT fk_vd_promocion FOREIGN KEY (promocion_id) REFERENCES promociones (id),
  CONSTRAINT ck_vd_cantidad CHECK (cantidad > 0 AND cantidad_devuelta >= 0 AND cantidad_devuelta <= cantidad),
  CONSTRAINT ck_vd_montos CHECK (precio_unitario >= 0 AND descuento >= 0 AND base >= 0 AND iva >= 0 AND total >= 0 AND costo_total >= 0),
  CONSTRAINT ck_vd_total CHECK (total = base + iva),
  CONSTRAINT ck_vd_tipo CHECK (
    (tipo = 'producto'  AND articulo_id IS NOT NULL) OR
    (tipo = 'preparado' AND esencia_id IS NOT NULL AND presentacion_id IS NOT NULL) OR
    (tipo = 'bono'      AND bono_id IS NOT NULL AND tasa_iva = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venta_detalle_componentes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_detalle_id BIGINT UNSIGNED NOT NULL,
  tipo_componente ENUM('esencia','envase','alcohol','base','insumo','producto') NOT NULL,
  articulo_id   INT UNSIGNED NOT NULL,
  recipiente_id INT UNSIGNED NULL COMMENT 'Esencia: de qué recipiente salió (puede haber 2 filas si se agotó uno)',
  cantidad      DECIMAL(10,3) NOT NULL,
  unidad        ENUM('g','ml','und') NOT NULL,
  costo_unitario DECIMAL(14,6) NOT NULL COMMENT 'Costo promedio vigente al vender: CONGELADO',
  costo_total   DECIMAL(14,2) NOT NULL,
  movimiento_esencia_id BIGINT UNSIGNED NULL,
  movimiento_inventario_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_vdc_detalle (venta_detalle_id),
  KEY ix_vdc_articulo (articulo_id),
  KEY ix_vdc_recipiente (recipiente_id),
  KEY ix_vdc_me (movimiento_esencia_id),
  KEY ix_vdc_mi (movimiento_inventario_id),
  CONSTRAINT fk_vdc_detalle FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalles (id),
  CONSTRAINT fk_vdc_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_vdc_recipiente FOREIGN KEY (recipiente_id) REFERENCES recipientes_esencia (id),
  CONSTRAINT fk_vdc_me FOREIGN KEY (movimiento_esencia_id) REFERENCES movimientos_esencia (id),
  CONSTRAINT fk_vdc_mi FOREIGN KEY (movimiento_inventario_id) REFERENCES movimientos_inventario (id),
  CONSTRAINT ck_vdc_cantidad CHECK (cantidad > 0 AND costo_unitario >= 0 AND costo_total >= 0),
  CONSTRAINT ck_vdc_esencia CHECK (tipo_componente <> 'esencia' OR (recipiente_id IS NOT NULL AND unidad = 'g'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Costo histórico congelado por componente: base de COGS y de devoluciones';

CREATE TABLE pagos_venta (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id      BIGINT UNSIGNED NOT NULL,
  metodo_pago_id TINYINT UNSIGNED NOT NULL,
  monto         DECIMAL(14,2) NOT NULL COMMENT 'Lo aplicado a la venta',
  recibido      DECIMAL(14,2) NULL COMMENT 'Efectivo entregado por el cliente',
  cambio        DECIMAL(14,2) NULL,
  referencia    VARCHAR(60) NULL COMMENT 'Nº de aprobación Nequi/Daviplata/datáfono',
  ultimos4      CHAR(4) CHARACTER SET ascii NULL COMMENT 'Tarjeta: SOLO últimos 4 dígitos. Nunca PAN completo (PCI DSS)',
  bono_id       INT UNSIGNED NULL,
  caja_movimiento_id BIGINT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_pv_venta (venta_id),
  KEY ix_pv_metodo_fecha (metodo_pago_id, creado_en),
  KEY ix_pv_referencia (metodo_pago_id, referencia) COMMENT 'Detectar comprobantes Nequi repetidos',
  KEY ix_pv_bono (bono_id),
  KEY ix_pv_cm (caja_movimiento_id),
  CONSTRAINT fk_pv_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
  CONSTRAINT fk_pv_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id),
  CONSTRAINT fk_pv_bono FOREIGN KEY (bono_id) REFERENCES bonos (id),
  CONSTRAINT fk_pv_cm FOREIGN KEY (caja_movimiento_id) REFERENCES caja_movimientos (id),
  CONSTRAINT ck_pv_monto CHECK (monto > 0),
  CONSTRAINT ck_pv_efectivo CHECK (recibido IS NULL OR (recibido >= monto AND cambio = recibido - monto)),
  CONSTRAINT ck_pv_ultimos4 CHECK (ultimos4 IS NULL OR ultimos4 REGEXP '^[0-9]{4}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pago mixto: varias filas por venta; la suma = ventas.total (validado en VentaService)';

CREATE TABLE bono_movimientos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bono_id       INT UNSIGNED NOT NULL,
  tipo          ENUM('emision','redencion','reverso','vencimiento','anulacion') NOT NULL,
  monto         DECIMAL(14,2) NOT NULL COMMENT 'Con signo',
  saldo_despues DECIMAL(14,2) NOT NULL,
  venta_id      BIGINT UNSIGNED NULL,
  pago_venta_id BIGINT UNSIGNED NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_bm_bono (bono_id, id),
  KEY ix_bm_venta (venta_id),
  KEY ix_bm_pago (pago_venta_id),
  KEY ix_bm_usuario (usuario_id),
  CONSTRAINT fk_bm_bono FOREIGN KEY (bono_id) REFERENCES bonos (id),
  CONSTRAINT fk_bm_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
  CONSTRAINT fk_bm_pago FOREIGN KEY (pago_venta_id) REFERENCES pagos_venta (id),
  CONSTRAINT fk_bm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT ck_bm_monto CHECK (monto <> 0 AND saldo_despues >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Solo inserción';

-- =============================================================================
-- MÓDULO 13. Apartados (pasivo: el ingreso y el COGS se reconocen al entregar)
-- =============================================================================
CREATE TABLE apartados (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  numero        BIGINT UNSIGNED NOT NULL,
  cliente_id    INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  fecha         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vence_en      DATE NOT NULL,
  estado        ENUM('vigente','entregado','vencido','cancelado') NOT NULL DEFAULT 'vigente',
  total         DECIMAL(14,2) NOT NULL,
  abonado       DECIMAL(14,2) NOT NULL DEFAULT 0,
  saldo         DECIMAL(14,2) GENERATED ALWAYS AS (total - abonado) STORED,
  venta_id      BIGINT UNSIGNED NULL COMMENT 'Venta generada al entregar',
  observacion   VARCHAR(500) NULL,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_apartados_numero (sucursal_id, numero),
  UNIQUE KEY uq_apartados_venta (venta_id),
  KEY ix_apartados_estado_vence (estado, vence_en),
  KEY ix_apartados_cliente (cliente_id),
  KEY ix_apartados_usuario (usuario_id),
  CONSTRAINT fk_apartados_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_apartados_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
  CONSTRAINT fk_apartados_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_apartados_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
  CONSTRAINT ck_apartados_montos CHECK (total > 0 AND abonado >= 0 AND abonado <= total),
  CONSTRAINT ck_apartados_entrega CHECK (estado <> 'entregado' OR (venta_id IS NOT NULL AND abonado = total))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE apartado_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  apartado_id   INT UNSIGNED NOT NULL,
  tipo          ENUM('producto','preparado') NOT NULL,
  catalogo_item_id INT UNSIGNED NULL,
  articulo_id   INT UNSIGNED NULL,
  esencia_id    INT UNSIGNED NULL,
  presentacion_id TINYINT UNSIGNED NULL,
  descripcion   VARCHAR(150) NOT NULL,
  cantidad      DECIMAL(10,3) NOT NULL,
  precio_unitario DECIMAL(14,2) NOT NULL,
  total         DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_ai_apartado (apartado_id),
  KEY ix_ai_articulo (articulo_id),
  KEY ix_ai_esencia (esencia_id),
  KEY ix_ai_presentacion (presentacion_id),
  KEY ix_ai_catalogo (catalogo_item_id),
  CONSTRAINT fk_ai_apartado FOREIGN KEY (apartado_id) REFERENCES apartados (id),
  CONSTRAINT fk_ai_catalogo FOREIGN KEY (catalogo_item_id) REFERENCES catalogo_items (id),
  CONSTRAINT fk_ai_articulo FOREIGN KEY (articulo_id) REFERENCES articulos (id),
  CONSTRAINT fk_ai_esencia FOREIGN KEY (esencia_id) REFERENCES esencias (id),
  CONSTRAINT fk_ai_presentacion FOREIGN KEY (presentacion_id) REFERENCES presentaciones (id),
  CONSTRAINT ck_ai_montos CHECK (cantidad > 0 AND precio_unitario >= 0 AND total >= 0),
  CONSTRAINT ck_ai_tipo CHECK (
    (tipo = 'producto'  AND articulo_id IS NOT NULL) OR
    (tipo = 'preparado' AND esencia_id IS NOT NULL AND presentacion_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Productos: se reservan en existencias.cantidad_reservada. Preparados: se elaboran al entregar';

CREATE TABLE apartado_abonos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  apartado_id   INT UNSIGNED NOT NULL,
  metodo_pago_id TINYINT UNSIGNED NOT NULL,
  monto         DECIMAL(14,2) NOT NULL,
  referencia    VARCHAR(60) NULL,
  caja_movimiento_id BIGINT UNSIGNED NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  anulado_en    DATETIME NULL,
  anulado_por   INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_aa_apartado (apartado_id),
  KEY ix_aa_metodo (metodo_pago_id),
  KEY ix_aa_cm (caja_movimiento_id),
  KEY ix_aa_usuario (usuario_id),
  CONSTRAINT fk_aa_apartado FOREIGN KEY (apartado_id) REFERENCES apartados (id),
  CONSTRAINT fk_aa_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id),
  CONSTRAINT fk_aa_cm FOREIGN KEY (caja_movimiento_id) REFERENCES caja_movimientos (id),
  CONSTRAINT fk_aa_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_aa_anula FOREIGN KEY (anulado_por) REFERENCES usuarios (id),
  CONSTRAINT ck_aa_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Entra dinero a caja pero es pasivo (anticipo), no ingreso';

-- =============================================================================
-- MÓDULO 14. Devoluciones
-- =============================================================================
CREATE TABLE devoluciones (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id   SMALLINT UNSIGNED NOT NULL,
  numero        BIGINT UNSIGNED NOT NULL,
  venta_id      BIGINT UNSIGNED NOT NULL,
  caja_turno_id INT UNSIGNED NOT NULL,
  cliente_id    INT UNSIGNED NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  autorizado_por INT UNSIGNED NULL,
  fecha         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  tipo_reintegro ENUM('efectivo','metodo_original','bono','cambio') NOT NULL,
  bono_id       INT UNSIGNED NULL COMMENT 'Si se reintegra como bono',
  motivo        VARCHAR(255) NOT NULL,
  subtotal      DECIMAL(14,2) NOT NULL,
  iva           DECIMAL(14,2) NOT NULL,
  total         DECIMAL(14,2) NOT NULL,
  costo_total   DECIMAL(14,2) NOT NULL COMMENT 'COGS revertido al costo congelado original',
  PRIMARY KEY (id),
  UNIQUE KEY uq_devoluciones_numero (sucursal_id, numero),
  KEY ix_dev_venta (venta_id),
  KEY ix_dev_suc_fecha (sucursal_id, fecha),
  KEY ix_dev_turno (caja_turno_id),
  KEY ix_dev_cliente (cliente_id),
  KEY ix_dev_bono (bono_id),
  CONSTRAINT fk_dev_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id),
  CONSTRAINT fk_dev_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
  CONSTRAINT fk_dev_turno FOREIGN KEY (caja_turno_id) REFERENCES caja_turnos (id),
  CONSTRAINT fk_dev_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
  CONSTRAINT fk_dev_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_dev_autoriza FOREIGN KEY (autorizado_por) REFERENCES usuarios (id),
  CONSTRAINT fk_dev_bono FOREIGN KEY (bono_id) REFERENCES bonos (id),
  CONSTRAINT ck_dev_montos CHECK (subtotal >= 0 AND iva >= 0 AND total > 0 AND costo_total >= 0 AND total = subtotal + iva),
  CONSTRAINT ck_dev_bono CHECK (tipo_reintegro <> 'bono' OR bono_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devolucion_detalles (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  devolucion_id INT UNSIGNED NOT NULL,
  venta_detalle_id BIGINT UNSIGNED NOT NULL,
  cantidad      DECIMAL(10,3) NOT NULL,
  base          DECIMAL(14,2) NOT NULL,
  iva           DECIMAL(14,2) NOT NULL,
  total         DECIMAL(14,2) NOT NULL,
  costo_total   DECIMAL(14,2) NOT NULL COMMENT 'Proporcional al costo congelado de la línea original',
  reintegra_stock TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = producto abierto/dañado: se registra como merma',
  motivo        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dd_linea (devolucion_id, venta_detalle_id),
  KEY ix_dd_vd (venta_detalle_id),
  CONSTRAINT fk_dd_devolucion FOREIGN KEY (devolucion_id) REFERENCES devoluciones (id),
  CONSTRAINT fk_dd_vd FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalles (id),
  CONSTRAINT ck_dd_montos CHECK (cantidad > 0 AND base >= 0 AND iva >= 0 AND total = base + iva AND costo_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MÓDULO 15. Fidelización (movimientos; después de ventas y devoluciones)
-- =============================================================================
CREATE TABLE fidelizacion_movimientos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id    INT UNSIGNED NOT NULL,
  regla_id      SMALLINT UNSIGNED NULL,
  tipo          ENUM('acumulacion','redencion','vencimiento','ajuste','reverso') NOT NULL,
  unidad        ENUM('puntos','sellos') NOT NULL,
  cantidad      INT NOT NULL COMMENT 'Con signo',
  saldo_despues INT UNSIGNED NOT NULL,
  venta_id      BIGINT UNSIGNED NULL,
  devolucion_id INT UNSIGNED NULL,
  expira_en     DATE NULL,
  usuario_id    INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_fm_cliente (cliente_id, id),
  KEY ix_fm_venta (venta_id),
  KEY ix_fm_devolucion (devolucion_id),
  KEY ix_fm_regla (regla_id),
  KEY ix_fm_expira (expira_en),
  KEY ix_fm_usuario (usuario_id),
  CONSTRAINT fk_fm_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
  CONSTRAINT fk_fm_regla FOREIGN KEY (regla_id) REFERENCES fidelizacion_reglas (id),
  CONSTRAINT fk_fm_venta FOREIGN KEY (venta_id) REFERENCES ventas (id),
  CONSTRAINT fk_fm_devolucion FOREIGN KEY (devolucion_id) REFERENCES devoluciones (id),
  CONSTRAINT fk_fm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT ck_fm_cantidad CHECK (cantidad <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Solo inserción';

-- =============================================================================
-- MÓDULO 16. Protección de libros de solo inserción (defensa en profundidad)
-- Triggers de una sola sentencia (no requieren DELIMITER). Para archivar datos
-- antiguos, un DBA debe eliminar el trigger temporalmente y documentarlo.
-- =============================================================================
CREATE TRIGGER trg_me_no_upd BEFORE UPDATE ON movimientos_esencia FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movimientos_esencia es de solo insercion';
CREATE TRIGGER trg_me_no_del BEFORE DELETE ON movimientos_esencia FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movimientos_esencia es de solo insercion';
CREATE TRIGGER trg_mi_no_upd BEFORE UPDATE ON movimientos_inventario FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movimientos_inventario es de solo insercion';
CREATE TRIGGER trg_mi_no_del BEFORE DELETE ON movimientos_inventario FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movimientos_inventario es de solo insercion';
CREATE TRIGGER trg_cm_no_upd BEFORE UPDATE ON caja_movimientos FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'caja_movimientos es de solo insercion';
CREATE TRIGGER trg_cm_no_del BEFORE DELETE ON caja_movimientos FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'caja_movimientos es de solo insercion';
CREATE TRIGGER trg_aud_no_upd BEFORE UPDATE ON auditoria FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'auditoria es de solo insercion';
CREATE TRIGGER trg_aud_no_del BEFORE DELETE ON auditoria FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'auditoria es de solo insercion';
CREATE TRIGGER trg_bm_no_upd BEFORE UPDATE ON bono_movimientos FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bono_movimientos es de solo insercion';
CREATE TRIGGER trg_bm_no_del BEFORE DELETE ON bono_movimientos FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bono_movimientos es de solo insercion';
CREATE TRIGGER trg_fm_no_upd BEFORE UPDATE ON fidelizacion_movimientos FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fidelizacion_movimientos es de solo insercion';
CREATE TRIGGER trg_fm_no_del BEFORE DELETE ON fidelizacion_movimientos FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fidelizacion_movimientos es de solo insercion';
CREATE TRIGGER trg_ccons_no_upd BEFORE UPDATE ON cliente_consentimientos FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cliente_consentimientos es de solo insercion';
CREATE TRIGGER trg_ccons_no_del BEFORE DELETE ON cliente_consentimientos FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cliente_consentimientos es de solo insercion';

-- =============================================================================
-- MÓDULO 17. Vistas para reportes y catálogo público
-- Las vistas NO materializan nada: dependen de los índices de las tablas base.
-- =============================================================================

-- Catálogo público: SIN costos ni stock exacto (solo disponible sí/no).
-- El usuario de BD del catálogo puede tener SELECT solo sobre esta vista.
CREATE VIEW v_catalogo_publico AS
SELECT ci.id,
       ci.sucursal_id,
       ci.tipo,
       ci.slug,
       ci.nombre_publico,
       ci.descripcion_publica,
       COALESCE(ci.precio_venta, p.precio_base) AS precio_venta,
       ci.destacado,
       ci.orden,
       COALESCE(a.id, ae.id)                    AS articulo_id,
       COALESCE(cat.nombre, cate.nombre)        AS categoria,
       p.nombre                                 AS presentacion,
       e.genero,
       e.familia_olfativa,
       img.ruta                                 AS imagen_principal,
       CASE WHEN ci.tipo = 'producto'
            THEN (COALESCE(ex.cantidad, 0) - COALESCE(ex.cantidad_reservada, 0)) > 0
            ELSE COALESCE(exe.cantidad, 0) > 0 END AS disponible
FROM catalogo_items ci
LEFT JOIN articulos a        ON a.id = ci.articulo_id
LEFT JOIN esencias e         ON e.id = ci.esencia_id
LEFT JOIN articulos ae       ON ae.id = e.articulo_id
LEFT JOIN presentaciones p   ON p.id = ci.presentacion_id
LEFT JOIN categorias cat     ON cat.id = a.categoria_id
LEFT JOIN categorias cate    ON cate.id = ae.categoria_id
LEFT JOIN existencias ex     ON ex.sucursal_id = ci.sucursal_id AND ex.articulo_id = ci.articulo_id
LEFT JOIN existencias exe    ON exe.sucursal_id = ci.sucursal_id AND exe.articulo_id = e.articulo_id
LEFT JOIN imagenes img       ON img.articulo_id = COALESCE(a.id, ae.id) AND img.principal_flag = 1
WHERE ci.publicado = 1
  AND COALESCE(a.activo, ae.activo) = 1
  AND (ci.tipo = 'producto' OR p.activa = 1);

-- Stock actual con alerta de mínimo (incluye esencias en gramos)
CREATE VIEW v_stock_actual AS
SELECT ex.sucursal_id,
       a.id AS articulo_id,
       a.tipo,
       a.sku,
       a.codigo_barras,
       a.nombre,
       a.unidad_medida,
       ex.cantidad,
       ex.cantidad_reservada,
       (ex.cantidad - ex.cantidad_reservada) AS disponible,
       a.stock_minimo,
       ex.costo_promedio,
       ROUND(ex.cantidad * ex.costo_promedio, 2) AS valor_inventario,
       (ex.cantidad - ex.cantidad_reservada) <= a.stock_minimo AS bajo_minimo
FROM existencias ex
JOIN articulos a ON a.id = ex.articulo_id
WHERE a.activo = 1;

-- Ventas por día (excluye anuladas; los bonos vendidos son pasivo, no ingreso)
CREATE VIEW v_ventas_diarias AS
SELECT v.sucursal_id,
       DATE(v.fecha) AS dia,
       COUNT(DISTINCT v.id) AS num_ventas,
       SUM(CASE WHEN vd.tipo <> 'bono' THEN vd.base ELSE 0 END) AS ventas_netas,
       SUM(CASE WHEN vd.tipo <> 'bono' THEN vd.iva  ELSE 0 END) AS iva,
       SUM(vd.costo_total) AS cogs,
       SUM(CASE WHEN vd.tipo <> 'bono' THEN vd.base ELSE 0 END) - SUM(vd.costo_total) AS margen_bruto
FROM ventas v
JOIN venta_detalles vd ON vd.venta_id = v.id
WHERE v.estado <> 'anulada'
GROUP BY v.sucursal_id, DATE(v.fecha);

-- Devoluciones por mes (se restan en el estado de resultados)
CREATE VIEW v_devoluciones_mensuales AS
SELECT d.sucursal_id,
       YEAR(d.fecha) * 100 + MONTH(d.fecha) AS periodo,
       SUM(d.subtotal) AS ventas_devueltas,
       SUM(d.costo_total) AS cogs_revertido
FROM devoluciones d
GROUP BY d.sucursal_id, YEAR(d.fecha) * 100 + MONTH(d.fecha);

-- Utilidad mensual = Ventas netas - COGS - Gastos (devoluciones restadas)
CREATE VIEW v_utilidad_mensual AS
SELECT x.sucursal_id,
       x.periodo,
       SUM(x.ventas) AS ventas_netas,
       SUM(x.cogs)   AS cogs,
       SUM(x.gastos) AS gastos,
       SUM(x.ventas) - SUM(x.cogs) - SUM(x.gastos) AS utilidad
FROM (
  SELECT sucursal_id, YEAR(dia) * 100 + MONTH(dia) AS periodo,
         ventas_netas AS ventas, cogs, 0 AS gastos
  FROM v_ventas_diarias
  UNION ALL
  SELECT sucursal_id, periodo, -ventas_devueltas, -cogs_revertido, 0
  FROM v_devoluciones_mensuales
  UNION ALL
  SELECT sucursal_id, periodo, 0, 0, monto
  FROM gastos WHERE anulado_en IS NULL
) x
GROUP BY x.sucursal_id, x.periodo;

-- Resumen de caja por turno y método (base del arqueo)
CREATE VIEW v_caja_turno_resumen AS
SELECT t.id AS turno_id,
       t.sucursal_id,
       t.caja_id,
       t.usuario_id,
       t.estado,
       mp.codigo AS metodo,
       mp.afecta_efectivo,
       SUM(cm.monto) AS neto,
       SUM(CASE WHEN cm.monto > 0 THEN cm.monto ELSE 0 END) AS entradas,
       SUM(CASE WHEN cm.monto < 0 THEN cm.monto ELSE 0 END) AS salidas,
       COUNT(*) AS num_movimientos
FROM caja_turnos t
JOIN caja_movimientos cm ON cm.turno_id = t.id
JOIN metodos_pago mp     ON mp.id = cm.metodo_pago_id
GROUP BY t.id, t.sucursal_id, t.caja_id, t.usuario_id, t.estado, mp.codigo, mp.afecta_efectivo;

-- Kardex unificado (esencias en g + artículos en und/ml)
CREATE VIEW v_kardex AS
SELECT 'esencia' AS libro, me.id AS movimiento_id, me.sucursal_id, e.articulo_id,
       me.recipiente_id, me.tipo, me.gramos AS cantidad, me.saldo_recipiente AS saldo,
       me.costo_g AS costo_unitario, me.referencia_tipo, me.referencia_id,
       me.usuario_id, me.creado_en
FROM movimientos_esencia me
JOIN esencias e ON e.id = me.esencia_id
UNION ALL
SELECT 'articulo', mi.id, mi.sucursal_id, mi.articulo_id,
       NULL, mi.tipo, mi.cantidad, mi.saldo_despues,
       mi.costo_unitario, mi.referencia_tipo, mi.referencia_id,
       mi.usuario_id, mi.creado_en
FROM movimientos_inventario mi;

-- Cuentas por pagar con días de mora
CREATE VIEW v_cxp_pendientes AS
SELECT c.id, c.sucursal_id, c.proveedor_id, p.razon_social, c.numero_documento,
       c.fecha_emision, c.fecha_vencimiento, c.monto_original, c.saldo, c.estado,
       GREATEST(DATEDIFF(CURDATE(), c.fecha_vencimiento), 0) AS dias_mora
FROM cxp c
JOIN proveedores p ON p.id = c.proveedor_id
WHERE c.estado IN ('pendiente','parcial');

-- Pasivos vigentes (bonos + apartados): dinero recibido que aún no es ingreso
CREATE VIEW v_pasivos AS
SELECT 'bono' AS tipo, b.sucursal_id, b.id, b.saldo AS monto, b.vence_en
FROM bonos b WHERE b.estado = 'activo' AND b.saldo > 0
UNION ALL
SELECT 'apartado', a.sucursal_id, a.id, a.abonado, a.vence_en
FROM apartados a WHERE a.estado = 'vigente' AND a.abonado > 0;

-- Fin de 0001_schema.sql
