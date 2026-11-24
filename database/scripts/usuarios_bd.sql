-- =============================================================================
-- Perfumería - Usuarios de base de datos con privilegios mínimos
-- NO contiene contraseñas. Las cuentas se crean BLOQUEADAS (ACCOUNT LOCK) y el
-- administrador les pone contraseña a mano, en una sesión interactiva:
--
--   1) Revisión (por defecto solo LISTA lo que haría, no cambia nada):
--        mysql -u root perfumeria < database/scripts/usuarios_bd.sql
--   2) Ejecución real:
--        mysql -u root --init-command="SET @ejecutar=1" perfumeria < database/scripts/usuarios_bd.sql
--   3) Contraseña (en el cliente interactivo `mysql -u root -p`, nunca en un
--      archivo ni en la línea de comandos; luego copiarla al .env fuera del repo):
--        ALTER USER 'perf_app'@'localhost' IDENTIFIED BY '...' ACCOUNT UNLOCK;
--        ALTER USER 'perf_catalogo'@'localhost' IDENTIFIED BY '...' ACCOUNT UNLOCK;
--        ALTER USER 'perf_backup'@'localhost' IDENTIFIED BY '...' ACCOUNT UNLOCK;
--      (o generarla con: SELECT TO_BASE64(RANDOM_BYTES(24));)
--
-- Re-ejecutar el paso 2 tras cada migración que cree tablas (GRANT es idempotente).
-- Política (docs/SEGURIDAD_AUTH.md §3):
--   perf_app      libros de solo inserción: SELECT, INSERT
--                 tablas de tokens/purga:   SELECT, INSERT, UPDATE, DELETE
--                 migraciones y vistas:     SELECT
--                 resto:                    SELECT, INSERT, UPDATE (borrado lógico, sin DELETE)
--   perf_catalogo SELECT solo sobre v_catalogo_publico
--   perf_backup   SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT (mysqldump)
-- Las migraciones se aplican con un usuario administrador, nunca con perf_app.
-- Requiere MariaDB 10.4.2+ / MySQL 5.7.6+ (ACCOUNT LOCK).
-- =============================================================================

DROP PROCEDURE IF EXISTS perf_privilegios_minimos;

DELIMITER //
CREATE PROCEDURE perf_privilegios_minimos(IN p_ejecutar TINYINT)
BEGIN
  DECLARE v_fin INT DEFAULT 0;
  DECLARE v_sql VARCHAR(600);
  DECLARE cur CURSOR FOR SELECT sentencia FROM tmp_perf_grants ORDER BY orden;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;

  DROP TEMPORARY TABLE IF EXISTS tmp_perf_grants;
  CREATE TEMPORARY TABLE tmp_perf_grants (
    orden INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sentencia VARCHAR(600) NOT NULL
  );

  INSERT INTO tmp_perf_grants (sentencia) VALUES
    ('CREATE USER IF NOT EXISTS ''perf_app''@''localhost'' ACCOUNT LOCK'),
    ('CREATE USER IF NOT EXISTS ''perf_catalogo''@''localhost'' ACCOUNT LOCK'),
    ('CREATE USER IF NOT EXISTS ''perf_backup''@''localhost'' ACCOUNT LOCK');

  INSERT INTO tmp_perf_grants (sentencia)
  SELECT CONCAT('GRANT ',
           CASE
             WHEN t.table_type = 'VIEW' OR t.table_name = 'migraciones' THEN 'SELECT'
             WHEN t.table_name IN ('movimientos_esencia','movimientos_inventario','caja_movimientos',
                                   'auditoria','bono_movimientos','fidelizacion_movimientos',
                                   'cliente_consentimientos') THEN 'SELECT, INSERT'
             WHEN t.table_name IN ('refresh_tokens','jwt_revocados','limites_tasa','login_intentos',
                                   'rol_permisos','receta_componentes') THEN 'SELECT, INSERT, UPDATE, DELETE'
             ELSE 'SELECT, INSERT, UPDATE'
           END,
           ' ON `', t.table_schema, '`.`', t.table_name, '` TO ''perf_app''@''localhost''')
    FROM information_schema.tables t
   WHERE t.table_schema = DATABASE()
   ORDER BY t.table_type, t.table_name;

  INSERT INTO tmp_perf_grants (sentencia) VALUES
    (CONCAT('GRANT SELECT ON `', DATABASE(), '`.`v_catalogo_publico` TO ''perf_catalogo''@''localhost''')),
    (CONCAT('GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON `', DATABASE(), '`.* TO ''perf_backup''@''localhost'''));

  IF p_ejecutar = 1 THEN
    OPEN cur;
    bucle: LOOP
      FETCH cur INTO v_sql;
      IF v_fin = 1 THEN LEAVE bucle; END IF;
      SET @perf_sql = v_sql;
      PREPARE st FROM @perf_sql;
      EXECUTE st;
      DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE cur;
  END IF;

  SELECT IF(p_ejecutar = 1, 'EJECUTADO', 'SOLO REVISION (no se cambió nada)') AS modo, sentencia
    FROM tmp_perf_grants ORDER BY orden;
  DROP TEMPORARY TABLE tmp_perf_grants;
END//
DELIMITER ;

CALL perf_privilegios_minimos(IFNULL(@ejecutar, 0));
DROP PROCEDURE perf_privilegios_minimos;
