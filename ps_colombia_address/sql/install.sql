-- ============================================================
-- ps_colombia_address — Install SQL
-- ============================================================
-- Creates two tables:
--   1. PREFIX_colombia_municipality  – full DANE/coordinate dataset
--   2. PREFIX_colombia_address_extra – links an address to its
--      DANE code + coordinates without touching core tables.
--
-- PREFIX_ is substituted at runtime with the store's actual DB prefix.
-- ============================================================

-- Table 1: Municipality master dataset ----------------------

CREATE TABLE IF NOT EXISTS `PREFIX_colombia_municipality` (
    `id_municipality`  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `department`       VARCHAR(120)    NOT NULL,
    `municipality`     VARCHAR(120)    NOT NULL,
    `postal_code`      VARCHAR(20)     NOT NULL DEFAULT '',
    `dane_code`        VARCHAR(10)     NOT NULL DEFAULT '',
    `latitude`         DECIMAL(10, 8)  NOT NULL DEFAULT 0.00000000,
    `longitude`        DECIMAL(11, 8)  NOT NULL DEFAULT 0.00000000,
    PRIMARY KEY (`id_municipality`),
    INDEX `idx_department`  (`department`),
    INDEX `idx_municipality` (`municipality`),
    INDEX `idx_dane_code`   (`dane_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table 2: Per-address extra Colombian fields ----------------

CREATE TABLE IF NOT EXISTS `PREFIX_colombia_address_extra` (
    `id_address`  INT UNSIGNED    NOT NULL,
    `dane_code`   VARCHAR(10)     NOT NULL DEFAULT '',
    `latitude`    DECIMAL(10, 8)  NOT NULL DEFAULT 0.00000000,
    `longitude`   DECIMAL(11, 8)  NOT NULL DEFAULT 0.00000000,
    PRIMARY KEY (`id_address`),
    CONSTRAINT `fk_colombia_address_extra_address`
      FOREIGN KEY (`id_address`)
      REFERENCES `PREFIX_address` (`id_address`)
      ON DELETE CASCADE
      ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
