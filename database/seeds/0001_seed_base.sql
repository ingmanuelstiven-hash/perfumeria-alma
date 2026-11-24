-- =============================================================================
-- Perfumería - Seed 0001: datos base (re-ejecutable: ON DUPLICATE KEY UPDATE)
-- Requiere: database/migrations/0001_schema.sql aplicado.
--
-- NO contiene usuarios ni contraseñas. El administrador inicial se crea con:
--     php scripts/crear_admin.php
-- (pendiente de escribir) que pide usuario y contraseña por consola (sin eco),
-- valida la política (mín. 12 caracteres, no en lista de contraseñas comunes),
-- guarda password_hash(PASSWORD_ARGON2ID) con debe_cambiar_password = 0 y
-- registra el evento en auditoria. Nunca se crea un admin con clave por defecto.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Sucursal principal
-- -----------------------------------------------------------------------------
INSERT INTO sucursales (id, codigo, nombre, activa)
VALUES (1, 'PRINCIPAL', 'Sede principal', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- -----------------------------------------------------------------------------
-- Roles
-- -----------------------------------------------------------------------------
INSERT INTO roles (id, codigo, nombre, descripcion, es_sistema) VALUES
 (1, 'admin',    'Administrador', 'Acceso total. Gestiona usuarios, costos, compras y cierres.', 1),
 (2, 'empleado', 'Empleado',      'POS, caja propia, clientes, trasvases y conteos. No ve costos.', 1),
 (3, 'contador', 'Contador',      'Solo lectura de reportes financieros, costos, compras, gastos y auditoría.', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

-- -----------------------------------------------------------------------------
-- Permisos (modulo.accion). Verificados en servidor en cada endpoint.
-- -----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, descripcion) VALUES
 ('usuarios.ver',              'usuarios',     'Ver usuarios'),
 ('usuarios.gestionar',        'usuarios',     'Crear/editar/desactivar usuarios y restablecer contraseñas'),
 ('roles.gestionar',           'usuarios',     'Editar roles y sus permisos'),
 ('sesiones.revocar',          'usuarios',     'Cerrar sesiones de otros usuarios'),
 ('config.gestionar',          'config',       'Editar parámetros del sistema'),
 ('auditoria.ver',             'auditoria',    'Consultar el registro de auditoría'),
 ('respaldos.ejecutar',        'config',       'Lanzar/descargar respaldos'),
 ('articulos.ver',             'articulos',    'Ver artículos (sin costos)'),
 ('articulos.gestionar',       'articulos',    'Crear/editar esencias, productos, envases e insumos'),
 ('articulos.imagenes',        'articulos',    'Subir y ordenar fotos'),
 ('codigos.gestionar',         'articulos',    'Asignar/generar códigos de barras'),
 ('etiquetas.imprimir',        'articulos',    'Imprimir etiquetas con código de barras'),
 ('inventario.ver',            'inventario',   'Ver existencias y kardex (sin costos)'),
 ('inventario.ver_costos',     'inventario',   'Ver costos, costo promedio y márgenes'),
 ('inventario.trasvasar',      'inventario',   'Trasvasar esencia entre recipientes'),
 ('inventario.ajustar',        'inventario',   'Registrar mermas y ajustes manuales'),
 ('recetas.gestionar',         'inventario',   'Editar recetas por presentación'),
 ('conteos.realizar',          'inventario',   'Registrar conteos físicos'),
 ('conteos.aplicar',           'inventario',   'Aplicar diferencias de conteo'),
 ('ventas.crear',              'ventas',       'Vender en el POS'),
 ('ventas.ver',                'ventas',       'Ver ventas propias'),
 ('ventas.ver_todas',          'ventas',       'Ver ventas de todos los usuarios'),
 ('ventas.descuento',          'ventas',       'Aplicar descuento hasta el tope configurado'),
 ('ventas.autorizar_descuento','ventas',       'Autorizar con PIN descuentos sobre el tope'),
 ('ventas.anular',             'ventas',       'Anular ventas'),
 ('precios.gestionar',         'ventas',       'Editar precios y catálogo de venta'),
 ('promociones.gestionar',     'ventas',       'Crear/editar promociones'),
 ('caja.abrir',                'caja',         'Abrir turno de caja'),
 ('caja.cerrar',               'caja',         'Cerrar (arqueo ciego) el propio turno'),
 ('caja.movimientos',          'caja',         'Registrar ingresos/retiros manuales'),
 ('caja.ver_todas',            'caja',         'Ver turnos de todas las cajas'),
 ('caja.revisar',              'caja',         'Revisar/aprobar cierres con diferencia'),
 ('compras.ver',               'compras',      'Ver compras'),
 ('compras.gestionar',         'compras',      'Registrar/anular compras'),
 ('proveedores.gestionar',     'compras',      'Crear/editar proveedores'),
 ('cxp.ver',                   'compras',      'Ver cuentas por pagar'),
 ('cxp.pagar',                 'compras',      'Registrar pagos a proveedores'),
 ('gastos.ver',                'finanzas',     'Ver gastos'),
 ('gastos.gestionar',          'finanzas',     'Registrar/anular gastos'),
 ('reportes.ver',              'finanzas',     'Reportes operativos (ventas, rotación)'),
 ('reportes.financieros',      'finanzas',     'Estado de resultados, márgenes, valor de inventario'),
 ('cierres.ejecutar',          'finanzas',     'Ejecutar el cierre mensual'),
 ('clientes.ver',              'clientes',     'Buscar y ver clientes'),
 ('clientes.gestionar',        'clientes',     'Crear/editar clientes y consentimientos'),
 ('clientes.exportar',         'clientes',     'Exportar datos personales (habeas data)'),
 ('clientes.suprimir',         'clientes',     'Anonimizar cliente (derecho de supresión)'),
 ('fidelizacion.gestionar',    'clientes',     'Editar reglas de fidelización y ajustes'),
 ('fidelizacion.redimir',      'clientes',     'Redimir puntos/sellos en venta'),
 ('bonos.vender',              'pasivos',      'Vender bonos de regalo'),
 ('bonos.redimir',             'pasivos',      'Redimir bonos como medio de pago'),
 ('bonos.anular',              'pasivos',      'Anular bonos'),
 ('apartados.crear',           'pasivos',      'Crear apartados'),
 ('apartados.abonar',          'pasivos',      'Registrar abonos'),
 ('apartados.entregar',        'pasivos',      'Entregar apartado (genera venta)'),
 ('apartados.cancelar',        'pasivos',      'Cancelar/vencer apartados'),
 ('devoluciones.crear',        'devoluciones', 'Registrar devoluciones dentro de la política'),
 ('devoluciones.autorizar',    'devoluciones', 'Autorizar devoluciones fuera de política'),
 ('catalogo.gestionar',        'catalogo',     'Publicar/ocultar ítems del catálogo público')
ON DUPLICATE KEY UPDATE modulo = VALUES(modulo), descripcion = VALUES(descripcion);

-- admin: todos los permisos
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id FROM permisos p;

-- empleado: operación diaria, sin costos, sin anulaciones, sin administración
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 2, p.id FROM permisos p WHERE p.codigo IN (
 'articulos.ver','etiquetas.imprimir',
 'inventario.ver','inventario.trasvasar','conteos.realizar',
 'ventas.crear','ventas.ver','ventas.descuento',
 'caja.abrir','caja.cerrar',
 'clientes.ver','clientes.gestionar','fidelizacion.redimir',
 'bonos.vender','bonos.redimir',
 'apartados.crear','apartados.abonar','apartados.entregar',
 'devoluciones.crear'
);

-- contador: solo lectura (ningún permiso que escriba)
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 3, p.id FROM permisos p WHERE p.codigo IN (
 'articulos.ver','inventario.ver','inventario.ver_costos',
 'ventas.ver','ventas.ver_todas','caja.ver_todas',
 'compras.ver','cxp.ver','gastos.ver',
 'reportes.ver','reportes.financieros','auditoria.ver'
);

-- -----------------------------------------------------------------------------
-- Métodos de pago
-- -----------------------------------------------------------------------------
INSERT INTO metodos_pago (id, codigo, nombre, afecta_efectivo, requiere_referencia, es_pasivo, orden) VALUES
 (1, 'EFECTIVO',        'Efectivo',               1, 0, 0, 1),
 (2, 'NEQUI',           'Nequi',                  0, 1, 0, 2),
 (3, 'DAVIPLATA',       'Daviplata',              0, 1, 0, 3),
 (4, 'TARJETA_DEBITO',  'Tarjeta débito',         0, 1, 0, 4),
 (5, 'TARJETA_CREDITO', 'Tarjeta crédito',        0, 1, 0, 5),
 (6, 'TRANSFERENCIA',   'Transferencia bancaria', 0, 1, 0, 6),
 (7, 'BONO',            'Bono de regalo',         0, 0, 1, 7),
 (8, 'PUNTOS',          'Puntos de fidelización', 0, 0, 1, 8),
 (9, 'APARTADO',        'Abonos de apartado',     0, 0, 1, 9)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), afecta_efectivo = VALUES(afecta_efectivo),
  requiere_referencia = VALUES(requiere_referencia), es_pasivo = VALUES(es_pasivo), orden = VALUES(orden);

-- -----------------------------------------------------------------------------
-- Consecutivos de la sucursal principal
-- CODIGO_INTERNO: secuencia para EAN-13 internos con prefijo 200 (rango GS1
-- 20-29 de circulación restringida/uso en tienda) + 9 dígitos + verificador.
-- -----------------------------------------------------------------------------
INSERT INTO consecutivos (sucursal_id, tipo, prefijo, siguiente, relleno) VALUES
 (1, 'VENTA',          'V',   1, 8),
 (1, 'COMPRA',         'C',   1, 6),
 (1, 'DEVOLUCION',     'D',   1, 6),
 (1, 'APARTADO',       'A',   1, 6),
 (1, 'CONTEO',         'K',   1, 4),
 (1, 'BONO',           'B',   1, 6),
 (1, 'RECIBO_CAJA',    'R',   1, 6),
 (1, 'GASTO',          'G',   1, 6),
 (1, 'CODIGO_INTERNO', '200', 1, 9),
 (1, 'SKU',            '',    1, 6)
ON DUPLICATE KEY UPDATE prefijo = prefijo;  -- nunca reinicia un contador existente

-- -----------------------------------------------------------------------------
-- Caja, presentaciones y categorías de gasto
-- -----------------------------------------------------------------------------
INSERT INTO cajas (id, sucursal_id, nombre) VALUES (1, 1, 'Caja 1')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO presentaciones (id, nombre, volumen_ml, precio_base, orden) VALUES
 (1, '30 ml',   30.00, NULL, 1),
 (2, '50 ml',   50.00, NULL, 2),
 (3, '60 ml',   60.00, NULL, 3),
 (4, '100 ml', 100.00, NULL, 4),
 (5, '120 ml', 120.00, NULL, 5)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), orden = VALUES(orden);

INSERT INTO categorias_gasto (nombre) VALUES
 ('Arriendo'),('Servicios públicos'),('Nómina'),('Internet y telefonía'),
 ('Publicidad'),('Transporte'),('Mantenimiento'),('Papelería'),('Impuestos'),('Otros')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- -----------------------------------------------------------------------------
-- Configuración global (valores iniciales; el negocio los ajusta en Sprint 0)
-- -----------------------------------------------------------------------------
INSERT INTO config (sucursal_id, clave, valor, tipo, descripcion) VALUES
 (NULL, 'iva_por_defecto',             '19.00', 'decimal', 'IVA % por defecto para artículos nuevos'),
 (NULL, 'tope_descuento_empleado_pct', '10.00', 'decimal', 'Descuento máximo sin autorización (%)'),
 (NULL, 'merma_trasvase_esperada_pct', '2.00',  'decimal', 'Merma esperada en trasvase (%) para alertas'),
 (NULL, 'bono_vigencia_dias',          '365',   'int',     'Vigencia de bonos de regalo'),
 (NULL, 'apartado_plazo_dias',         '30',    'int',     'Plazo máximo de apartados'),
 (NULL, 'apartado_abono_minimo_pct',   '30.00', 'decimal', 'Abono inicial mínimo (%)'),
 (NULL, 'devolucion_plazo_dias',       '8',     'int',     'Plazo de devolución sin autorización'),
 (NULL, 'habeas_data_version',         '2026-01','string', 'Versión vigente del texto de autorización'),
 (NULL, 'ticket_ancho_mm',             '80',    'int',     '58 u 80'),
 (NULL, 'imagen_max_bytes',            '5242880','int',    'Tamaño máximo de subida (5 MB)'),
 (NULL, 'imagen_max_lado_px',          '1600',  'int',     'Lado mayor tras reescalar'),
 (NULL, 'login_max_intentos',          '5',     'int',     'Intentos fallidos antes de bloqueo temporal'),
 (NULL, 'login_bloqueo_minutos',       '15',    'int',     'Duración del bloqueo temporal')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

COMMIT;
