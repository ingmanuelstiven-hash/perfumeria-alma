-- =============================================================================
-- Perfumería - Seed 0002: parámetros de subida de archivos (re-ejecutable)
-- Requiere: 0002_integridad_y_archivos.sql aplicado.
-- Los límites duros están también en los CHECK de `imagenes` y `archivos`;
-- estos valores son los que usa la app (pueden ser más estrictos, nunca mayores).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO config (sucursal_id, clave, valor, tipo, descripcion) VALUES
 (NULL, 'imagen_miniatura_lado_px', '320',      'int', 'Lado mayor de la miniatura (_mini)'),
 (NULL, 'imagen_max_megapixeles',   '40',       'int', 'Rechazar antes de decodificar si ancho x alto supera este valor (bomba de descompresión)'),
 (NULL, 'imagen_calidad_webp',      '82',       'int', 'Calidad de re-codificación WebP (GD)'),
 (NULL, 'comprobante_max_bytes',    '10485760', 'int', 'Tamaño máximo de un PDF de comprobante (10 MB)'),
 (NULL, 'imagenes_max_por_articulo','10',       'int', 'Fotos vigentes máximas por artículo')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

COMMIT;
