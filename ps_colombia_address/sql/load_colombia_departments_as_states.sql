-- ============================================================
-- Load Colombia departments into PrestaShop states table
--
-- - Auto-detects table prefix from <prefix>configuration
-- - Updates existing rows by iso_code
-- - Inserts missing rows
-- - Safe to run multiple times (idempotent)
--
-- Result: Department selector (id_state) is available for Colombia,
-- which is required by ps_colombia_address to load municipalities.
-- ============================================================

SET @old_sql_safe_updates := @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

SET @db_name := DATABASE();

-- Detect PrestaShop prefix from configuration table
SET @prefix := (
    SELECT REPLACE(table_name, 'configuration', '')
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND table_name LIKE '%configuration'
    ORDER BY CASE WHEN table_name LIKE 'ps_%' THEN 0 ELSE 1 END, table_name
    LIMIT 1
);
SET @prefix := IFNULL(@prefix, '');

SELECT @prefix AS detected_prefix;

SET @t_country := CONCAT(@prefix, 'country');
SET @t_state   := CONCAT(@prefix, 'state');

-- Validate required tables exist
SET @has_country := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_country
);
SET @has_state := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_state
);

SET @sql_guard := IF(
  @has_country > 0 AND @has_state > 0,
  'SELECT ''OK'' AS precheck',
  'SELECT ''ERROR: country/state table not found'' AS precheck'
);
PREPARE stmt_guard FROM @sql_guard;
EXECUTE stmt_guard;
DEALLOCATE PREPARE stmt_guard;

-- Resolve Colombia country id and zone id
SET @sql_country := CONCAT(
  'SELECT @id_country_co := id_country, @id_zone_co := id_zone ',
  'FROM `', @t_country, '` WHERE iso_code = ''CO'' LIMIT 1'
);
PREPARE stmt_country FROM @sql_country;
EXECUTE stmt_country;
DEALLOCATE PREPARE stmt_country;

SELECT @id_country_co AS id_country_co, @id_zone_co AS id_zone_co;

-- Temporary source dataset (33 departments including Bogotá D.C.)
DROP TEMPORARY TABLE IF EXISTS tmp_colombia_departments;
CREATE TEMPORARY TABLE tmp_colombia_departments (
  name VARCHAR(120) NOT NULL,
  iso_code VARCHAR(7) NOT NULL,
  PRIMARY KEY (iso_code)
) ENGINE=Memory;

INSERT INTO tmp_colombia_departments (name, iso_code) VALUES
('Amazonas', 'AMA'),
('Antioquia', 'ANT'),
('Arauca', 'ARA'),
('Atlántico', 'ATL'),
('Bolívar', 'BOL'),
('Boyacá', 'BOY'),
('Caldas', 'CAL'),
('Caquetá', 'CAQ'),
('Casanare', 'CAS'),
('Cauca', 'CAU'),
('Cesar', 'CES'),
('Chocó', 'CHO'),
('Córdoba', 'COR'),
('Cundinamarca', 'CUN'),
('Guainía', 'GUA'),
('Guaviare', 'GUV'),
('Huila', 'HUI'),
('La Guajira', 'LAG'),
('Magdalena', 'MAG'),
('Meta', 'MET'),
('Nariño', 'NAR'),
('Norte de Santander', 'NSA'),
('Putumayo', 'PUT'),
('Quindío', 'QUI'),
('Risaralda', 'RIS'),
('San Andrés y Providencia', 'SAP'),
('Santander', 'SAN'),
('Sucre', 'SUC'),
('Tolima', 'TOL'),
('Valle del Cauca', 'VAC'),
('Vaupés', 'VAU'),
('Vichada', 'VID'),
('Bogotá D.C.', 'BOG');

-- Update existing Colombia states by iso_code
SET @sql_update := CONCAT(
  'UPDATE `', @t_state, '` s ',
  'INNER JOIN tmp_colombia_departments d ON s.iso_code = d.iso_code ',
  'SET s.name = d.name, s.active = 1, s.id_zone = ', IFNULL(@id_zone_co, 0), ' ',
  'WHERE s.id_country = ', IFNULL(@id_country_co, 0)
);
PREPARE stmt_update FROM @sql_update;
EXECUTE stmt_update;
DEALLOCATE PREPARE stmt_update;

-- Insert missing states
SET @sql_insert := CONCAT(
  'INSERT INTO `', @t_state, '` (id_country, id_zone, name, iso_code, active) ',
  'SELECT ', IFNULL(@id_country_co, 0), ', ', IFNULL(@id_zone_co, 0), ', d.name, d.iso_code, 1 ',
  'FROM tmp_colombia_departments d ',
  'LEFT JOIN `', @t_state, '` s ',
  'ON s.id_country = ', IFNULL(@id_country_co, 0), ' AND s.iso_code = d.iso_code ',
  'WHERE s.id_state IS NULL'
);
PREPARE stmt_insert FROM @sql_insert;
EXECUTE stmt_insert;
DEALLOCATE PREPARE stmt_insert;

-- Verification
SET @sql_verify := CONCAT(
  'SELECT COUNT(*) AS colombia_states_loaded ',
  'FROM `', @t_state, '` ',
  'WHERE id_country = ', IFNULL(@id_country_co, 0)
);
PREPARE stmt_verify FROM @sql_verify;
EXECUTE stmt_verify;
DEALLOCATE PREPARE stmt_verify;

DROP TEMPORARY TABLE IF EXISTS tmp_colombia_departments;

SET SQL_SAFE_UPDATES = @old_sql_safe_updates;
