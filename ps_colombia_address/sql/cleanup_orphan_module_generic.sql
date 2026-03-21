-- ============================================================
-- Force cleanup for orphaned ps_colombia_address module records
-- Generic version: Use PREFIX_ placeholder (substitute at runtime)
-- 
-- Use case: module is marked as installed in DB but folder is missing,
-- causing Back Office crash in ModuleControllerRegisterPass.
--
-- Compatible with both MySQL 5.7+ and MariaDB 10.1+
-- ============================================================

START TRANSACTION;

-- 1) Remove module-hook links
DELETE hm
FROM `PREFIX_hook_module` hm
INNER JOIN `PREFIX_module` m ON m.id_module = hm.id_module
WHERE m.name = 'ps_colombia_address';

-- 2) Remove module-shop links (multistore support)
DELETE ms
FROM `PREFIX_module_shop` ms
INNER JOIN `PREFIX_module` m ON m.id_module = ms.id_module
WHERE m.name = 'ps_colombia_address';

-- 3) Remove admin tab language rows for module tab
DELETE tl
FROM `PREFIX_tab_lang` tl
INNER JOIN `PREFIX_tab` t ON t.id_tab = tl.id_tab
WHERE t.class_name = 'AdminColombiaAddress' OR t.module = 'ps_colombia_address';

-- 4) Remove admin tab row
DELETE FROM `PREFIX_tab`
WHERE class_name = 'AdminColombiaAddress' OR module = 'ps_colombia_address';

-- 5) Remove module configuration keys (all COLOMBIA_ADDRESS_* settings)
DELETE FROM `PREFIX_configuration`
WHERE name IN (
    'COLOMBIA_ADDRESS_ENABLE',
    'COLOMBIA_ADDRESS_AUTOFILL_POSTAL',
    'COLOMBIA_ADDRESS_ENABLE_DROPDOWN',
    'COLOMBIA_ADDRESS_ENABLE_AUTOCOMPLETE',
    'COLOMBIA_ADDRESS_LOGISTICS_MODE'
);

-- 6) Remove module row itself
DELETE FROM `PREFIX_module`
WHERE name = 'ps_colombia_address';

-- 7) Drop leftover data tables (optional, uncomment if needed)
-- DROP TABLE IF EXISTS `PREFIX_colombia_address_extra`;
-- DROP TABLE IF EXISTS `PREFIX_colombia_municipality`;

COMMIT;

-- ============================================================
-- Verification Queries (run these to confirm cleanup)
-- ============================================================

-- Should return 0 rows if cleanup was successful:
SELECT COUNT(*) AS module_rows
FROM `PREFIX_module`
WHERE name = 'ps_colombia_address';

SELECT COUNT(*) AS tab_rows
FROM `PREFIX_tab`
WHERE class_name = 'AdminColombiaAddress' OR module = 'ps_colombia_address';

SELECT COUNT(*) AS config_rows
FROM `PREFIX_configuration`
WHERE name LIKE 'COLOMBIA_ADDRESS_%';
