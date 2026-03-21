<?php
/**
 * ColombiaAddressService
 *
 * Central service for all Colombian address data lookups.
 * Injected via Symfony DI; never instantiated with `new`.
 *
 * All query parameters are validated before hitting the database.
 * Optional in-process caching via PrestaShop CacheCore reduces
 * repeated identical lookups within the same request.
 *
 * @package  PsColombiaAddress\Service
 * @author   Custom
 * @license  MIT
 */

declare(strict_types=1);

namespace PsColombiaAddress\Service;

use Db;
use PrestaShopLogger;

/**
 * Provides read operations over `ps_colombia_municipality` and
 * `ps_colombia_address_extra`.
 */
final class ColombiaAddressService
{
    /** Cache TTL in seconds (5 minutes). */
    private const CACHE_TTL = 300;

    /** Maximum length of department / municipality strings accepted from callers. */
    private const MAX_STRING_LENGTH = 120;

    // ─── Constructor ─────────────────────────────────────────────────────────

    public function __construct(
        private readonly \Db $db,
        private readonly string $tablePrefix
    ) {
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Return all municipalities that belong to the given department.
     *
     * Each row contains: name, postal_code, dane_code, latitude, longitude.
     *
     * @param  string $department  Department name (e.g. "Antioquia").
     * @return array<int, array<string, mixed>>
     */
    public function getMunicipalitiesByDepartment(string $department): array
    {
        $department = $this->sanitizeString($department);

        if ($department === '') {
            return [];
        }

        $cacheKey = 'colombia_municipalities_' . md5($department);

        if (\CacheCore::isSupported()) {
            $cached = \CacheCore::retrieve($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

            $query = new \DbQuery();
            $query->select('`municipality`, `postal_code`, `dane_code`, `latitude`, `longitude`');
            $query->from('colombia_municipality');
            $query->where('`department` = ' . $this->quoteSqlString($department));
            $query->orderBy('`municipality` ASC');

            $rows = $this->db->executeS((string) $query);

        $result = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $result[] = [
                    'name'        => (string) $row['municipality'],
                    'postal_code' => (string) $row['postal_code'],
                    'dane_code'   => (string) $row['dane_code'],
                    'latitude'    => (string) $row['latitude'],
                    'longitude'   => (string) $row['longitude'],
                ];
            }
        }

        if (\CacheCore::isSupported()) {
            \CacheCore::store($cacheKey, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Return the postal code for a given municipality name.
     *
     * @param  string $municipality  Municipality name (e.g. "Medellín").
     * @return string  Postal code, or empty string if not found.
     */
    public function getPostalCodeByMunicipality(string $municipality): string
    {
        $municipality = $this->sanitizeString($municipality);

        if ($municipality === '') {
            return '';
        }

        $query = new \DbQuery();
        $query->select('`postal_code`');
        $query->from('colombia_municipality');
        $query->where('`municipality` = ' . $this->quoteSqlString($municipality));

        $value = $this->db->getValue((string) $query);

        return is_string($value) ? $value : '';
    }

    /**
     * Return the DANE code for a given municipality name.
     *
     * @param  string $municipality
     * @return string  DANE code (5 digits), or empty string if not found.
     */
    public function getDaneCode(string $municipality): string
    {
        $municipality = $this->sanitizeString($municipality);

        if ($municipality === '') {
            return '';
        }

        $query = new \DbQuery();
        $query->select('`dane_code`');
        $query->from('colombia_municipality');
        $query->where('`municipality` = ' . $this->quoteSqlString($municipality));

        $value = $this->db->getValue((string) $query);

        return is_string($value) ? $value : '';
    }

    /**
     * Return the geographic coordinates for a municipality.
     *
     * @param  string $municipality
     * @return array{latitude: string, longitude: string}
     */
    public function getCoordinates(string $municipality): array
    {
        $municipality = $this->sanitizeString($municipality);

        if ($municipality === '') {
            return ['latitude' => '', 'longitude' => ''];
        }

        $query = new \DbQuery();
        $query->select('`latitude`, `longitude`');
        $query->from('colombia_municipality');
        $query->where('`municipality` = ' . $this->quoteSqlString($municipality));

        $row = $this->db->getRow((string) $query);

        if (!is_array($row)) {
            return ['latitude' => '', 'longitude' => ''];
        }

        return [
            'latitude'  => (string) ($row['latitude']  ?? ''),
            'longitude' => (string) ($row['longitude'] ?? ''),
        ];
    }

    /**
     * Return all distinct department names, sorted alphabetically.
     *
     * @return string[]
     */
    public function getDepartments(): array
    {
        $cacheKey = 'colombia_departments_list';

        if (\CacheCore::isSupported()) {
            $cached = \CacheCore::retrieve($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $table = $this->tableName('colombia_municipality');

        $rows = $this->db->executeS(
            'SELECT DISTINCT `department` FROM `' . bqSQL($table) . '` ORDER BY `department` ASC'
        );

        $departments = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $departments[] = (string) $row['department'];
            }
        }

        if (\CacheCore::isSupported()) {
            \CacheCore::store($cacheKey, $departments, self::CACHE_TTL);
        }

        return $departments;
    }

    /**
     * Return the extra Colombian data (DANE code, coordinates) stored for
     * an existing PrestaShop address.
     *
     * @param  int $idAddress
     * @return array{dane_code: string, latitude: string, longitude: string}
     */
    public function getAddressExtra(int $idAddress): array
    {
        if ($idAddress <= 0) {
            return ['dane_code' => '', 'latitude' => '', 'longitude' => ''];
        }

        $table = $this->tableName('colombia_address_extra');

        $row = $this->db->getRow(
            'SELECT `dane_code`, `latitude`, `longitude` FROM `' . bqSQL($table) . '`
              WHERE `id_address` = ' . (int) $idAddress
        );

        if (!is_array($row)) {
            return ['dane_code' => '', 'latitude' => '', 'longitude' => ''];
        }

        return [
            'dane_code' => (string) ($row['dane_code'] ?? ''),
            'latitude'  => (string) ($row['latitude']  ?? ''),
            'longitude' => (string) ($row['longitude'] ?? ''),
        ];
    }

    /**
     * Return the total number of municipalities in the dataset.
     */
    public function getMunicipalityCount(): int
    {
        $table = $this->tableName('colombia_municipality');

        $count = $this->db->getValue(
            'SELECT COUNT(*) FROM `' . bqSQL($table) . '`'
        );

        return (int) $count;
    }

    /**
     * Return the total number of Colombian departments loaded as states.
     */
    public function getDepartmentCount(): int
    {
        $stateTable = $this->tableName('state');
        $countryTable = $this->tableName('country');

        $count = $this->db->getValue(
            'SELECT COUNT(*) FROM `' . bqSQL($stateTable) . '` s '
            . 'INNER JOIN `' . bqSQL($countryTable) . '` c ON c.`id_country` = s.`id_country` '
            . 'WHERE c.`iso_code` = \'CO\''
        );

        return (int) $count;
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Strip all characters not valid in a Colombian department / municipality
     * name and truncate to the maximum accepted length.
     *
     * Allows: letters (including accented), spaces, hyphens, dots, apostrophes.
     */
    private function sanitizeString(string $value): string
    {
        $clean = preg_replace(
            "/[^a-zA-ZáéíóúÁÉÍÓÚñÑüÜàèìòùÀÈÌÒÙ\s\-\.\']/u",
            '',
            trim($value)
        );

        return substr((string) $clean, 0, self::MAX_STRING_LENGTH);
    }

    /**
     * Build a fully-qualified table name using the configured prefix.
     */
    private function tableName(string $base): string
    {
        return $this->tablePrefix . $base;
    }

    private function quoteSqlString(string $value): string
    {
        return '\'' . pSQL($value, true) . '\'';
    }
}
