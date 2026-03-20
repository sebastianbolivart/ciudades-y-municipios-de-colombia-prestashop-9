-- ============================================================
-- ps_colombia_address — Uninstall SQL
-- ============================================================
-- Drops only module-owned tables.
-- Native PrestaShop tables (ps_address, ps_country, etc.)
-- are NEVER modified or removed.
-- PREFIX_ is substituted at runtime.
-- ============================================================

DROP TABLE IF EXISTS `PREFIX_colombia_address_extra`;
DROP TABLE IF EXISTS `PREFIX_colombia_municipality`;
