-- ============================================================
-- Force cleanup for orphaned ps_colombia_address module records
-- Use case: module is marked as installed in DB but folder is missing,
-- causing Back Office crash in ModuleControllerRegisterPass.
--
-- Target prefix: 7qmfe_
-- Module technical name: ps_colombia_address
-- ============================================================

START TRANSACTION;

-- 1) Remove module-hook links
DELETE hm
FROM `7qmfe_hook_module` hm
INNER JOIN `7qmfe_module` m ON m.id_module = hm.id_module
WHERE m.name = 'ps_colombia_address';

-- 2) Remove module-shop links (multistore)
DELETE ms
FROM `7qmfe_module_shop` ms
INNER JOIN `7qmfe_module` m ON m.id_module = ms.id_module
WHERE m.name = 'ps_colombia_address';

-- 3) Remove admin tab language rows for module tab
DELETE tl
FROM `7qmfe_tab_lang` tl
INNER JOIN `7qmfe_tab` t ON t.id_tab = tl.id_tab
WHERE t.class_name = 'AdminColombiaAddress' OR t.module = 'ps_colombia_address';

-- 4) Remove admin tab row
DELETE FROM `7qmfe_tab`
WHERE class_name = 'AdminColombiaAddress' OR module = 'ps_colombia_address';

-- 5) Remove module configuration keys
DELETE FROM `7qmfe_configuration`
WHERE name IN (
    'COLOMBIA_ADDRESS_ENABLE',
    'COLOMBIA_ADDRESS_AUTOFILL_POSTAL',
    'COLOMBIA_ADDRESS_ENABLE_DROPDOWN',
    'COLOMBIA_ADDRESS_ENABLE_AUTOCOMPLETE',
    'COLOMBIA_ADDRESS_LOGISTICS_MODE'
);

-- 6) Remove module row itself
DELETE FROM `7qmfe_module`
WHERE name = 'ps_colombia_address';

-- 7) Optional: drop leftover data tables
DROP TABLE IF EXISTS `7qmfe_colombia_address_extra`;
DROP TABLE IF EXISTS `7qmfe_colombia_municipality`;

COMMIT;

-- Verification queries
SELECT COUNT(*) AS module_rows
FROM `7qmfe_module`
WHERE name = 'ps_colombia_address';

SELECT COUNT(*) AS tab_rows
FROM `7qmfe_tab`
WHERE class_name = 'AdminColombiaAddress' OR module = 'ps_colombia_address';

SELECT COUNT(*) AS config_rows
FROM `7qmfe_configuration`
WHERE name LIKE 'COLOMBIA_ADDRESS_%';
