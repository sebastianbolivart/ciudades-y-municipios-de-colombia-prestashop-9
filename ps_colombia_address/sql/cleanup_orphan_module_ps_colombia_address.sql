-- ============================================================
-- Force total cleanup for ps_colombia_address broken installations
--
-- AUTO-DETECTS TABLE PREFIX from <prefix>configuration.
-- Safe to run multiple times.
-- ============================================================

-- Disable safe-update mode only for this script execution.
SET @old_sql_safe_updates := @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

SET @db_name := DATABASE();

-- 0) Detect PrestaShop prefix from existing configuration table
SET @prefix := (
    SELECT REPLACE(table_name, 'configuration', '')
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND table_name LIKE '%configuration'
    ORDER BY CASE WHEN table_name LIKE 'ps_%' THEN 0 ELSE 1 END, table_name
    LIMIT 1
);

-- If prefix cannot be detected, stop with a visible error
SET @prefix := IFNULL(@prefix, '');
SELECT @prefix AS detected_prefix;

-- 1) Resolve table names dynamically
SET @t_module := CONCAT(@prefix, 'module');
SET @t_hook_module := CONCAT(@prefix, 'hook_module');
SET @t_module_shop := CONCAT(@prefix, 'module_shop');
SET @t_tab := CONCAT(@prefix, 'tab');
SET @t_tab_lang := CONCAT(@prefix, 'tab_lang');
SET @t_access := CONCAT(@prefix, 'access');
SET @t_configuration := CONCAT(@prefix, 'configuration');
SET @t_authorization_role := CONCAT(@prefix, 'authorization_role');
SET @t_address_extra := CONCAT(@prefix, 'colombia_address_extra');
SET @t_municipality := CONCAT(@prefix, 'colombia_municipality');

-- 2) Capture possible legacy tab id (if tab table exists)
SET @has_tab := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_tab
);

SET @sql_get_tab_id := IF(
    @has_tab > 0,
    CONCAT(
        'SELECT @ps_colombia_tab_id := id_tab FROM `', @t_tab,
        '` WHERE class_name = ''AdminColombiaAddress'' OR module = ''ps_colombia_address'' LIMIT 1'
    ),
    'SELECT @ps_colombia_tab_id := NULL'
);
PREPARE stmt_get_tab_id FROM @sql_get_tab_id;
EXECUTE stmt_get_tab_id;
DEALLOCATE PREPARE stmt_get_tab_id;

-- 3) Remove module-hook links
SET @has_hook_module := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_hook_module
);
SET @has_module := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_module
);

SET @sql_hook_module := IF(
    @has_hook_module > 0 AND @has_module > 0,
    CONCAT(
        'DELETE FROM `', @t_hook_module, '` WHERE id_module IN (',
        'SELECT id_module FROM `', @t_module, '` WHERE name = ''ps_colombia_address''',
        ')'
    ),
    'SELECT 1'
);
PREPARE stmt_hook_module FROM @sql_hook_module;
EXECUTE stmt_hook_module;
DEALLOCATE PREPARE stmt_hook_module;

-- 4) Remove module-shop links
SET @has_module_shop := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_module_shop
);
SET @sql_module_shop := IF(
    @has_module_shop > 0 AND @has_module > 0,
    CONCAT(
        'DELETE FROM `', @t_module_shop, '` WHERE id_module IN (',
        'SELECT id_module FROM `', @t_module, '` WHERE name = ''ps_colombia_address''',
        ')'
    ),
    'SELECT 1'
);
PREPARE stmt_module_shop FROM @sql_module_shop;
EXECUTE stmt_module_shop;
DEALLOCATE PREPARE stmt_module_shop;

-- 5) Remove tab-related rows
SET @has_access := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_access
);
SET @has_access_id_tab := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_access AND COLUMN_NAME = 'id_tab'
);
SET @sql_access := IF(
    @has_access > 0 AND @has_access_id_tab > 0,
    CONCAT('DELETE FROM `', @t_access, '` WHERE id_tab = ', IFNULL(@ps_colombia_tab_id, 0)),
    'SELECT 1'
);
PREPARE stmt_access FROM @sql_access;
EXECUTE stmt_access;
DEALLOCATE PREPARE stmt_access;

SET @has_tab_lang := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_tab_lang
);
SET @has_tab_lang_id_tab := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_tab_lang AND COLUMN_NAME = 'id_tab'
);
SET @sql_tab_lang := IF(
    @has_tab_lang > 0 AND @has_tab_lang_id_tab > 0,
    CONCAT('DELETE FROM `', @t_tab_lang, '` WHERE id_tab = ', IFNULL(@ps_colombia_tab_id, 0)),
    'SELECT 1'
);
PREPARE stmt_tab_lang FROM @sql_tab_lang;
EXECUTE stmt_tab_lang;
DEALLOCATE PREPARE stmt_tab_lang;

SET @sql_tab := IF(
    @has_tab > 0,
    CONCAT(
        'DELETE FROM `', @t_tab, '` WHERE ',
        'class_name = ''AdminColombiaAddress'' OR module = ''ps_colombia_address''',
        IF(@ps_colombia_tab_id IS NULL, '', CONCAT(' OR id_tab = ', @ps_colombia_tab_id))
    ),
    'SELECT 1'
);
PREPARE stmt_tab FROM @sql_tab;
EXECUTE stmt_tab;
DEALLOCATE PREPARE stmt_tab;

-- 6) Remove module configuration keys
SET @has_configuration := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_configuration
);
SET @sql_configuration := IF(
    @has_configuration > 0,
    CONCAT(
        'DELETE FROM `', @t_configuration, '` WHERE ',
        'name IN (''COLOMBIA_ADDRESS_ENABLE'',''COLOMBIA_ADDRESS_AUTOFILL_POSTAL'',''COLOMBIA_ADDRESS_ENABLE_DROPDOWN'',''COLOMBIA_ADDRESS_ENABLE_AUTOCOMPLETE'',''COLOMBIA_ADDRESS_LOGISTICS_MODE'') ',
        'OR name LIKE ''COLOMBIA_ADDRESS_%''' 
    ),
    'SELECT 1'
);
PREPARE stmt_configuration FROM @sql_configuration;
EXECUTE stmt_configuration;
DEALLOCATE PREPARE stmt_configuration;

-- 7) Remove authorization roles (if table exists)
SET @has_authorization_role := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = @t_authorization_role
);
SET @sql_authorization_role := IF(
    @has_authorization_role > 0,
    CONCAT('DELETE FROM `', @t_authorization_role, '` WHERE slug LIKE ''ROLE_MOD_TAB_ADMINCOLOMBIAADDRESS%'''),
    'SELECT 1'
);
PREPARE stmt_authorization_role FROM @sql_authorization_role;
EXECUTE stmt_authorization_role;
DEALLOCATE PREPARE stmt_authorization_role;

-- 8) Remove module row
SET @sql_module := IF(
    @has_module > 0,
    CONCAT('DELETE FROM `', @t_module, '` WHERE name = ''ps_colombia_address'''),
    'SELECT 1'
);
PREPARE stmt_module FROM @sql_module;
EXECUTE stmt_module;
DEALLOCATE PREPARE stmt_module;

-- 9) Drop leftover module tables (safe even if missing)
SET @sql_drop_extra := CONCAT('DROP TABLE IF EXISTS `', @t_address_extra, '`');
PREPARE stmt_drop_extra FROM @sql_drop_extra;
EXECUTE stmt_drop_extra;
DEALLOCATE PREPARE stmt_drop_extra;

SET @sql_drop_muni := CONCAT('DROP TABLE IF EXISTS `', @t_municipality, '`');
PREPARE stmt_drop_muni FROM @sql_drop_muni;
EXECUTE stmt_drop_muni;
DEALLOCATE PREPARE stmt_drop_muni;

-- ============================================================
-- Verification queries (all should be 0 after successful cleanup)
-- ============================================================

SET @sql_verify_module := IF(
    @has_module > 0,
    CONCAT('SELECT COUNT(*) AS module_rows FROM `', @t_module, '` WHERE name = ''ps_colombia_address'''),
    'SELECT 0 AS module_rows'
);
PREPARE stmt_verify_module FROM @sql_verify_module;
EXECUTE stmt_verify_module;
DEALLOCATE PREPARE stmt_verify_module;

SET @sql_verify_tab := IF(
    @has_tab > 0,
    CONCAT('SELECT COUNT(*) AS tab_rows FROM `', @t_tab, '` WHERE class_name = ''AdminColombiaAddress'' OR module = ''ps_colombia_address'''),
    'SELECT 0 AS tab_rows'
);
PREPARE stmt_verify_tab FROM @sql_verify_tab;
EXECUTE stmt_verify_tab;
DEALLOCATE PREPARE stmt_verify_tab;

SET @sql_verify_config := IF(
    @has_configuration > 0,
    CONCAT('SELECT COUNT(*) AS config_rows FROM `', @t_configuration, '` WHERE name LIKE ''COLOMBIA_ADDRESS_%'''),
    'SELECT 0 AS config_rows'
);
PREPARE stmt_verify_config FROM @sql_verify_config;
EXECUTE stmt_verify_config;
DEALLOCATE PREPARE stmt_verify_config;

-- Restore previous safe-update mode.
SET SQL_SAFE_UPDATES = @old_sql_safe_updates;
