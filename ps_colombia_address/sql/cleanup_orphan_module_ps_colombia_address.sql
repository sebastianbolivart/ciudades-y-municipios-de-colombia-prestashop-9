-- ============================================================
-- Force total cleanup for ps_colombia_address broken installations
--
-- Target prefix: 7qmfe_
-- Module technical name: ps_colombia_address
--
-- Safe to run multiple times.
-- Use this after failed installs that left module rows, admin tabs,
-- hook bindings, configuration, or module tables behind.
-- ============================================================

START TRANSACTION;

-- 1) Capture possible legacy tab ids in user variables
SET @ps_colombia_tab_id := (
    SELECT id_tab FROM `7qmfe_tab`
    WHERE class_name = 'AdminColombiaAddress' OR module = 'ps_colombia_address'
    LIMIT 1
);

-- 2) Remove module-hook links
DELETE FROM `7qmfe_hook_module`
WHERE id_module IN (
    SELECT id_module FROM `7qmfe_module` WHERE name = 'ps_colombia_address'
);

-- 3) Remove module-shop links (multistore)
DELETE FROM `7qmfe_module_shop`
WHERE id_module IN (
    SELECT id_module FROM `7qmfe_module` WHERE name = 'ps_colombia_address'
);

-- 4) Remove admin tab permissions/access rows if the tab exists
DELETE FROM `7qmfe_access`
WHERE id_tab = @ps_colombia_tab_id;

-- 5) Remove admin tab language rows
DELETE FROM `7qmfe_tab_lang`
WHERE id_tab = @ps_colombia_tab_id;

-- 6) Remove admin tab row
DELETE FROM `7qmfe_tab`
WHERE id_tab = @ps_colombia_tab_id
   OR class_name = 'AdminColombiaAddress'
   OR module = 'ps_colombia_address';

-- 7) Remove module configuration keys
DELETE FROM `7qmfe_configuration`
WHERE name IN (
    'COLOMBIA_ADDRESS_ENABLE',
    'COLOMBIA_ADDRESS_AUTOFILL_POSTAL',
    'COLOMBIA_ADDRESS_ENABLE_DROPDOWN',
    'COLOMBIA_ADDRESS_ENABLE_AUTOCOMPLETE',
    'COLOMBIA_ADDRESS_LOGISTICS_MODE'
)
   OR name LIKE 'COLOMBIA_ADDRESS_%';

-- 8) Remove authorization roles created around the old admin tab, if any
DELETE FROM `7qmfe_authorization_role`
WHERE slug LIKE 'ROLE_MOD_TAB_ADMINCOLOMBIAADDRESS%';

-- 9) Remove module row itself
DELETE FROM `7qmfe_module`
WHERE name = 'ps_colombia_address';

-- 10) Drop leftover data tables
DROP TABLE IF EXISTS `7qmfe_colombia_address_extra`;
DROP TABLE IF EXISTS `7qmfe_colombia_municipality`;

COMMIT;

-- ============================================================
-- Verification queries
-- All values below should be 0 after successful cleanup.
-- ============================================================

SELECT COUNT(*) AS module_rows
FROM `7qmfe_module`
WHERE name = 'ps_colombia_address';

SELECT COUNT(*) AS tab_rows
FROM `7qmfe_tab`
WHERE class_name = 'AdminColombiaAddress' OR module = 'ps_colombia_address';

SELECT COUNT(*) AS config_rows
FROM `7qmfe_configuration`
WHERE name LIKE 'COLOMBIA_ADDRESS_%';

SELECT COUNT(*) AS access_rows
FROM `7qmfe_access`
WHERE id_tab = @ps_colombia_tab_id;
