-- =============================================================================
-- Perfumería - Migración 0002: integridad referencial reforzada y archivos
-- Requiere: 0001_schema.sql aplicado. Destino: MariaDB 10.4+ (probado en
-- 10.4.32 de XAMPP); pensado compatible con MySQL 8.0.19+ (DROP CONSTRAINT).
--
-- Resultado de la auditoría (detalle en docs/BASE_DE_DATOS.md, sección 9):
--   A. refresh_tokens.reemplazado_por: ON DELETE SET NULL. Con RESTRICT la purga
--      de familias expiradas en un solo DELETE puede fallar (InnoDB comprueba la
--      FK fila a fila, no al final de la sentencia).
--   B. Tabla `archivos` para imágenes sueltas y comprobantes (logos de marca y
--      sucursal, imagen de categoría, avatar de usuario, soportes de compras,
--      gastos, pagos a proveedor y pagos de venta). Solo RUTA relativa + metadatos;
--      el binario vive en disco (storage/uploads). Sustituye a gastos.soporte_ruta.
--   C. `imagenes` (galería de artículos): CHECK de ruta estricto (directorio
--      articulos/, reparto ab/cd/ coherente con el nombre, 32 hex aleatorios),
--      miniatura con el mismo nombre base, extensión coherente con el MIME,
--      dimensiones > 0, y la unicidad por hash solo entre imágenes vigentes.
--   D. articulos.codigo_barras (copia del principal) ahora con FK compuesta a
--      codigos_barras (articulo_id, codigo): la copia no puede apuntar a un
--      código inexistente ni a un código de OTRO artículo.
--   E. movimientos_esencia: el recipiente debe ser de la misma esencia y sucursal
--      que dice el movimiento (FK compuesta). recipientes_esencia.codigo no puede
--      ser solo dígitos (no choca nunca con un EAN/UPC en el lector).
--   F. movimientos_inventario: FK a existencias (sucursal, artículo) -> no hay
--      kardex sin fila de saldo que bloquear con FOR UPDATE; lote coherente con
--      artículo y sucursal; CHECK de signo por tipo (como en movimientos_esencia).
--   G. Coherencia de sucursal en caja: turno-caja, movimiento-turno, venta-turno,
--      devolución-turno (FKs compuestas).
--   H. promociones: usos_actuales <= usos_max (el cupo de un cupón no se puede
--      sobrepasar aunque dos cajas lo canjeen a la vez).
--   I. Vistas: v_catalogo_publico expone imagen_id/miniatura (para /media/{id});
--      nuevas v_control_articulos y v_control_existencias_esencia (auditoría).
--
-- Todas las FKs nuevas son RESTRICT (borrado lógico en todo el sistema), salvo A.
-- Las FKs compuestas referencian claves UNIQUE completas (exigido por MySQL 8.4).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';
SET SESSION default_storage_engine = InnoDB;
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- A. Purga segura de refresh tokens
-- =============================================================================
ALTER TABLE refresh_tokens DROP FOREIGN KEY fk_rt_reemplazo;
ALTER TABLE refresh_tokens
  ADD CONSTRAINT fk_rt_reemplazo FOREIGN KEY (reemplazado_por)
      REFERENCES refresh_tokens (id) ON DELETE SET NULL;

-- =============================================================================
-- B. Archivos (imágenes sueltas y comprobantes). Nunca BLOB ni base64.
--    ruta = <categoria>/<ab>/<cd>/<abcd + 28 hex>.<ext>, relativa a UPLOADS_DIR
--    (storage/uploads). El nombre es bin2hex(random_bytes(16)); el nombre
--    original del usuario NUNCA forma parte de la ruta.
-- =============================================================================
CREATE TABLE archivos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  categoria     ENUM('marcas','categorias','usuarios','sucursales','comprobantes')
                CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '= primer directorio de la ruta',
  ruta          VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Relativa a UPLOADS_DIR; nombre aleatorio de 32 hex',
  ruta_miniatura VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Mismo nombre base + _mini (solo imágenes)',
  mime          ENUM('image/webp','image/jpeg','image/png','application/pdf') NOT NULL COMMENT 'Detectado con finfo, no el que envía el navegador',
  hash_sha256   BINARY(32) NOT NULL COMMENT 'Del archivo final guardado',
  bytes         INT UNSIGNED NOT NULL,
  ancho         SMALLINT UNSIGNED NULL COMMENT 'NULL en PDF',
  alto          SMALLINT UNSIGNED NULL COMMENT 'NULL en PDF',
  nombre_original VARCHAR(150) NULL COMMENT 'Solo metadato (descarga de comprobantes). Escapar al mostrar; jamás se usa en rutas',
  alt_texto     VARCHAR(150) NULL,
  subido_por    INT UNSIGNED NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  eliminado_en  DATETIME NULL COMMENT 'Borrado lógico; el archivo físico lo purga un job cuando nada lo referencia',
  PRIMARY KEY (id),
  UNIQUE KEY uq_arch_ruta (ruta),
  KEY ix_arch_hash (hash_sha256),
  KEY ix_arch_categoria_fecha (categoria, creado_en),
  KEY ix_arch_usuario (subido_por),
  CONSTRAINT fk_arch_usuario FOREIGN KEY (subido_por) REFERENCES usuarios (id),
  CONSTRAINT ck_arch_ruta CHECK (ruta REGEXP '^(marcas|categorias|usuarios|sucursales|comprobantes)/([0-9a-f]{2})/([0-9a-f]{2})/\\2\\3[0-9a-f]{28}\\.(webp|jpg|png|pdf)$'),
  CONSTRAINT ck_arch_categoria CHECK (SUBSTRING_INDEX(ruta, '/', 1) = categoria),
  CONSTRAINT ck_arch_ext_mime CHECK (SUBSTRING_INDEX(ruta, '.', -1) =
      CASE mime WHEN 'image/webp' THEN 'webp' WHEN 'image/jpeg' THEN 'jpg'
                WHEN 'image/png' THEN 'png' ELSE 'pdf' END),
  CONSTRAINT ck_arch_miniatura CHECK (ruta_miniatura IS NULL OR (
      mime <> 'application/pdf'
      AND ruta_miniatura REGEXP '^[a-z]+/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}_mini\\.(webp|jpg|png)$'
      AND SUBSTRING_INDEX(ruta_miniatura, '_mini.', 1) = SUBSTRING_INDEX(ruta, '.', 1))),
  CONSTRAINT ck_arch_pdf CHECK (mime <> 'application/pdf' OR categoria = 'comprobantes'),
  CONSTRAINT ck_arch_dim CHECK (mime = 'application/pdf' OR (ancho > 0 AND alto > 0)),
  CONSTRAINT ck_arch_bytes CHECK (bytes > 0 AND bytes <= IF(mime = 'application/pdf', 10485760, 5242880))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Imágenes sueltas y comprobantes: SOLO ruta relativa y metadatos (el binario está en disco)';

ALTER TABLE sucursales
  ADD COLUMN logo_archivo_id INT UNSIGNED NULL COMMENT 'Logo para ticket y catálogo' AFTER encabezado_ticket,
  ADD KEY ix_sucursales_logo (logo_archivo_id),
  ADD CONSTRAINT fk_sucursales_logo FOREIGN KEY (logo_archivo_id) REFERENCES archivos (id);

ALTER TABLE marcas
  ADD COLUMN logo_archivo_id INT UNSIGNED NULL AFTER nombre,
  ADD KEY ix_marcas_logo (logo_archivo_id),
  ADD CONSTRAINT fk_marcas_logo FOREIGN KEY (logo_archivo_id) REFERENCES archivos (id);

ALTER TABLE categorias
  ADD COLUMN imagen_archivo_id INT UNSIGNED NULL AFTER slug,
  ADD KEY ix_categorias_imagen (imagen_archivo_id),
  ADD CONSTRAINT fk_categorias_imagen FOREIGN KEY (imagen_archivo_id) REFERENCES archivos (id);

ALTER TABLE usuarios
  ADD COLUMN avatar_archivo_id INT UNSIGNED NULL AFTER nombre,
  ADD KEY ix_usuarios_avatar (avatar_archivo_id),
  ADD CONSTRAINT fk_usuarios_avatar FOREIGN KEY (avatar_archivo_id) REFERENCES archivos (id);

ALTER TABLE compras
  ADD COLUMN soporte_archivo_id INT UNSIGNED NULL COMMENT 'Factura del proveedor escaneada (imagen o PDF)' AFTER observacion,
  ADD KEY ix_compras_soporte (soporte_archivo_id),
  ADD CONSTRAINT fk_compras_soporte FOREIGN KEY (soporte_archivo_id) REFERENCES archivos (id);

ALTER TABLE cxp_pagos
  ADD COLUMN soporte_archivo_id INT UNSIGNED NULL COMMENT 'Comprobante de transferencia/recibo' AFTER referencia,
  ADD KEY ix_cxpp_soporte (soporte_archivo_id),
  ADD CONSTRAINT fk_cxpp_soporte FOREIGN KEY (soporte_archivo_id) REFERENCES archivos (id);

ALTER TABLE gastos
  DROP COLUMN soporte_ruta,
  ADD COLUMN soporte_archivo_id INT UNSIGNED NULL COMMENT 'Foto/PDF del soporte' AFTER proveedor_id,
  ADD KEY ix_gastos_soporte (soporte_archivo_id),
  ADD CONSTRAINT fk_gastos_soporte FOREIGN KEY (soporte_archivo_id) REFERENCES archivos (id);

ALTER TABLE pagos_venta
  ADD COLUMN comprobante_archivo_id INT UNSIGNED NULL COMMENT 'Captura del comprobante Nequi/Daviplata/transferencia (opcional)' AFTER ultimos4,
  ADD KEY ix_pv_comprobante (comprobante_archivo_id),
  ADD CONSTRAINT fk_pv_comprobante FOREIGN KEY (comprobante_archivo_id) REFERENCES archivos (id);

-- =============================================================================
-- C. Galería de artículos: rutas estrictas y unicidad por hash entre vigentes
--    ruta = articulos/<ab>/<cd>/<abcd + 28 hex>.<ext>  (48 caracteres + extensión)
-- =============================================================================
ALTER TABLE imagenes DROP CONSTRAINT ck_img_ruta;
ALTER TABLE imagenes DROP INDEX uq_img_articulo_hash;
ALTER TABLE imagenes
  ADD COLUMN vigente_flag TINYINT GENERATED ALWAYS AS (IF(eliminado_en IS NULL, 1, NULL)) STORED AFTER principal_flag,
  ADD UNIQUE KEY uq_img_articulo_hash (articulo_id, hash_sha256, vigente_flag);
ALTER TABLE imagenes
  ADD CONSTRAINT ck_img_ruta CHECK (ruta REGEXP '^articulos/([0-9a-f]{2})/([0-9a-f]{2})/\\1\\2[0-9a-f]{28}\\.(webp|jpg|png)$'),
  ADD CONSTRAINT ck_img_miniatura CHECK (ruta_miniatura IS NULL OR (
      ruta_miniatura REGEXP '^articulos/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}_mini\\.(webp|jpg|png)$'
      AND LEFT(ruta_miniatura, 48) = LEFT(ruta, 48))),
  ADD CONSTRAINT ck_img_ext_mime CHECK (SUBSTRING_INDEX(ruta, '.', -1) =
      CASE mime WHEN 'image/webp' THEN 'webp' WHEN 'image/jpeg' THEN 'jpg' ELSE 'png' END),
  ADD CONSTRAINT ck_img_dim CHECK (ancho > 0 AND alto > 0);

-- =============================================================================
-- D. Copia del código principal en articulos: debe existir y ser del artículo.
--    Alta: INSERT articulos (codigo_barras NULL) -> INSERT codigos_barras
--    (es_principal=1) -> UPDATE articulos SET codigo_barras, en una transacción.
--    Para borrar o corregir un código, primero se cambia la copia.
-- =============================================================================
ALTER TABLE codigos_barras ADD UNIQUE KEY uq_cb_articulo_codigo (articulo_id, codigo);
ALTER TABLE articulos ADD KEY ix_articulos_id_cb (id, codigo_barras);
ALTER TABLE articulos
  ADD CONSTRAINT fk_articulos_cb_principal FOREIGN KEY (id, codigo_barras)
      REFERENCES codigos_barras (articulo_id, codigo);

-- =============================================================================
-- E. Recipientes y kardex de esencias coherentes
-- =============================================================================
ALTER TABLE recipientes_esencia
  ADD UNIQUE KEY uq_recipientes_id_esencia_suc (id, esencia_id, sucursal_id),
  ADD CONSTRAINT ck_recipientes_codigo CHECK (codigo REGEXP '^[!-~]{1,40}$' AND codigo NOT REGEXP '^[0-9]+$');
ALTER TABLE movimientos_esencia
  ADD KEY ix_me_recipiente_esencia_suc (recipiente_id, esencia_id, sucursal_id);
ALTER TABLE movimientos_esencia
  ADD CONSTRAINT fk_me_recipiente_coherente FOREIGN KEY (recipiente_id, esencia_id, sucursal_id)
      REFERENCES recipientes_esencia (id, esencia_id, sucursal_id);

-- =============================================================================
-- F. Kardex de artículos ligado a su fila de existencias y a un lote coherente
-- =============================================================================
ALTER TABLE existencias ADD UNIQUE KEY uq_existencias_articulo_suc (articulo_id, sucursal_id);
ALTER TABLE existencias DROP INDEX ix_existencias_articulo;

ALTER TABLE lotes ADD UNIQUE KEY uq_lotes_id_art_suc (id, articulo_id, sucursal_id);

ALTER TABLE movimientos_inventario
  ADD KEY ix_mi_lote_art_suc (lote_id, articulo_id, sucursal_id);
ALTER TABLE movimientos_inventario DROP INDEX ix_mi_lote;
ALTER TABLE movimientos_inventario
  MODIFY cantidad DECIMAL(12,3) NOT NULL COMMENT 'Con signo. reserva (+) / liberacion_reserva (-) son el delta de existencias.cantidad_reservada; saldo_despues no cambia',
  ADD CONSTRAINT fk_mi_existencia FOREIGN KEY (articulo_id, sucursal_id)
      REFERENCES existencias (articulo_id, sucursal_id),
  ADD CONSTRAINT fk_mi_lote_coherente FOREIGN KEY (lote_id, articulo_id, sucursal_id)
      REFERENCES lotes (id, articulo_id, sucursal_id),
  ADD CONSTRAINT ck_mi_signo CHECK (
    (tipo IN ('saldo_inicial','compra','devolucion','anulacion_venta','reserva') AND cantidad > 0) OR
    (tipo IN ('venta','consumo_receta','apartado_entrega','anulacion_compra','merma','liberacion_reserva') AND cantidad < 0) OR
    (tipo = 'ajuste_conteo'));

-- =============================================================================
-- G. Coherencia de sucursal en caja, ventas y devoluciones
-- =============================================================================
ALTER TABLE cajas ADD UNIQUE KEY uq_cajas_id_suc (id, sucursal_id);
ALTER TABLE caja_turnos
  ADD UNIQUE KEY uq_turnos_id_suc (id, sucursal_id),
  ADD KEY ix_turnos_caja_suc (caja_id, sucursal_id);
ALTER TABLE caja_turnos
  ADD CONSTRAINT fk_turnos_caja_suc FOREIGN KEY (caja_id, sucursal_id) REFERENCES cajas (id, sucursal_id);

ALTER TABLE caja_movimientos ADD KEY ix_cm_turno_suc (turno_id, sucursal_id);
ALTER TABLE caja_movimientos
  ADD CONSTRAINT fk_cm_turno_suc FOREIGN KEY (turno_id, sucursal_id) REFERENCES caja_turnos (id, sucursal_id);

ALTER TABLE ventas ADD KEY ix_ventas_turno_suc (caja_turno_id, sucursal_id);
ALTER TABLE ventas DROP INDEX ix_ventas_turno;
ALTER TABLE ventas
  ADD CONSTRAINT fk_ventas_turno_suc FOREIGN KEY (caja_turno_id, sucursal_id) REFERENCES caja_turnos (id, sucursal_id);

ALTER TABLE devoluciones ADD KEY ix_dev_turno_suc (caja_turno_id, sucursal_id);
ALTER TABLE devoluciones DROP INDEX ix_dev_turno;
ALTER TABLE devoluciones
  ADD CONSTRAINT fk_dev_turno_suc FOREIGN KEY (caja_turno_id, sucursal_id) REFERENCES caja_turnos (id, sucursal_id);

-- =============================================================================
-- H. Cupo de promociones / cupones
-- =============================================================================
ALTER TABLE promociones
  ADD CONSTRAINT ck_promo_usos CHECK (usos_max IS NULL OR usos_actuales <= usos_max);

-- =============================================================================
-- I. Vistas
-- =============================================================================
CREATE OR REPLACE VIEW v_catalogo_publico AS
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
            ELSE COALESCE(exe.cantidad, 0) > 0 END AS disponible,
       img.id                                   AS imagen_id,
       img.ruta_miniatura                       AS imagen_miniatura,
       img.alt_texto                            AS imagen_alt
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

-- Control: todo artículo debe tener código principal sincronizado y foto principal
CREATE OR REPLACE VIEW v_control_articulos AS
SELECT a.id AS articulo_id,
       a.tipo,
       a.sku,
       a.nombre,
       a.activo,
       a.codigo_barras,
       cb.codigo AS codigo_principal,
       (cb.id IS NULL) AS sin_codigo_principal,
       NOT (a.codigo_barras <=> cb.codigo) AS codigo_desincronizado,
       (img.id IS NULL) AS sin_imagen_principal,
       (SELECT COUNT(*) FROM imagenes i2
         WHERE i2.articulo_id = a.id AND i2.eliminado_en IS NULL) AS num_imagenes
FROM articulos a
LEFT JOIN codigos_barras cb ON cb.articulo_id = a.id AND cb.principal_flag = 1
LEFT JOIN imagenes img      ON img.articulo_id = a.id AND img.principal_flag = 1;

-- Control: gramos en existencias = suma de gramos en recipientes (por sucursal)
CREATE OR REPLACE VIEW v_control_existencias_esencia AS
SELECT ex.sucursal_id,
       e.id AS esencia_id,
       a.id AS articulo_id,
       a.sku,
       a.nombre,
       ex.cantidad AS gramos_existencias,
       COALESCE(r.gramos, 0) AS gramos_recipientes,
       ex.cantidad - COALESCE(r.gramos, 0) AS diferencia,
       (ex.cantidad <> COALESCE(r.gramos, 0)) AS descuadrado
FROM esencias e
JOIN articulos a    ON a.id = e.articulo_id
JOIN existencias ex ON ex.articulo_id = a.id
LEFT JOIN (SELECT esencia_id, sucursal_id, SUM(gramos_actuales) AS gramos
             FROM recipientes_esencia
            GROUP BY esencia_id, sucursal_id) r
       ON r.esencia_id = e.id AND r.sucursal_id = ex.sucursal_id;

-- Fin de 0002_integridad_y_archivos.sql
