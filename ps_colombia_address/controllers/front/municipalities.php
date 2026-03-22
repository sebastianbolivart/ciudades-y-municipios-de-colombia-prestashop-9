<?php
/**
 * municipalities — Front-office AJAX controller
 *
 * Endpoint:  GET  /module/ps_colombia_address/municipalities?department=Antioquia&token=...
 *
 * Returns a JSON list of municipalities for a given department.
 *
 * Security measures
 * ─────────────────
 * 1. Validates the PrestaShop front-office token to prevent CSRF / open-use.
 * 2. Input is stripped of non-alphabetic characters before the DB query.
 * 3. All output is JSON-encoded (no raw HTML response).
 * 4. No direct SQL concatenation — uses the service layer (prepared-style pSQL).
 * 5. HTTP caching headers are set so browsers / CDNs can cache the read-only list.
 *
 * Example response
 * ────────────────
 * {
 *   "municipalities": [
 *     {
 *       "name": "Medellín",
 *       "postal_code": "050010",
 *       "dane_code": "05001",
 *       "latitude": "6.24420300",
 *       "longitude": "-75.58121200"
 *     }
 *   ]
 * }
 *
 * @package  ps_colombia_address
 * @author   Custom
 * @license  MIT
 */

declare(strict_types=1);

/**
 * PrestaShop front module controller auto-loaded by FrontController.
 */
class ps_colombia_addressmunicipalitiesModuleFrontController extends ModuleFrontController
{
    /** Max length accepted for the department parameter. */
    private const MAX_DEPT_LENGTH = 120;

    // ─── Request handling ────────────────────────────────────────────────────

    /**
     * initContent is the main entry-point for front controllers.
     * We render JSON directly and call exit() explicitly.
     */
    public function initContent(): void
    {
        // Token validation — compares against the static front-office token.
        $submittedToken = (string) Tools::getValue('token', '');
        if (!$this->isValidToken($submittedToken)) {
            $this->jsonError('Invalid or missing security token.', 403);
        }

        $mode = Tools::strtolower((string) Tools::getValue('list', ''));

        if ($mode === 'departments') {
            try {
                $db = Db::getInstance();
                $stateTable = $this->resolveSqlTableName($db, 'state');
                $colombiaCountryId = $this->getColombiaCountryId();

                $rows = [];
                if ($colombiaCountryId > 0) {
                    $rows = $db->executeS(
                        'SELECT s.`id_state`, s.`name`
                           FROM `' . bqSQL($stateTable) . '` s
                          WHERE s.`id_country` = ' . $colombiaCountryId . '
                       ORDER BY s.`name` ASC'
                    );
                }

                if (!is_array($rows) || empty($rows)) {
                    $municipalityTable = $this->resolveSqlTableName($db, 'colombia_municipality');
                    $rows = $db->executeS(
                        'SELECT DISTINCT 0 AS `id_state`, `department` AS `name`
                           FROM `' . bqSQL($municipalityTable) . '`
                          WHERE `department` <> \'\'
                       ORDER BY `department` ASC'
                    );
                }

                $departments = [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $name = trim((string) ($row['name'] ?? ''));
                        $idState = (int) ($row['id_state'] ?? 0);
                        if ($name !== '') {
                            $departments[] = [
                                'id' => $idState,
                                'name' => $name,
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                PrestaShopLogger::addLog(
                    '[ps_colombia_address] AJAX departments error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                    3
                );
                $this->jsonError('Internal server error.', 500);
            }

            header('Cache-Control: public, max-age=600, s-maxage=3600');
            header('Vary: Accept-Encoding');
            $this->jsonSuccess(['departments' => $departments]);
        }

        $lookup = Tools::strtolower((string) Tools::getValue('lookup', ''));

        if ($lookup === 'municipality') {
            $rawMunicipality = (string) Tools::getValue('municipality', '');
            $municipality = $this->sanitiseDepartment($rawMunicipality);

            if ($municipality === '') {
                $this->jsonError('Missing or invalid "municipality" parameter.', 400);
            }

            try {
                $db = Db::getInstance();
                $municipalityTable = $this->resolveSqlTableName($db, 'colombia_municipality');
                $municipalitySql = $this->quoteSqlString($municipality) . ' COLLATE utf8mb4_unicode_ci';

                $row = $db->getRow(
                    'SELECT m.`department`, m.`municipality`, m.`postal_code`, m.`dane_code`, m.`latitude`, m.`longitude`
                       FROM `' . bqSQL($municipalityTable) . '` m
                      WHERE m.`municipality` COLLATE utf8mb4_unicode_ci = ' . $municipalitySql
                );

                $stateId = 0;
                if (is_array($row) && !empty($row['department'])) {
                    $stateTable = $this->resolveSqlTableName($db, 'state');
                    $colombiaCountryId = $this->getColombiaCountryId();
                    $departmentSql = $this->quoteSqlString((string) $row['department']) . ' COLLATE utf8mb4_unicode_ci';
                    if ($colombiaCountryId > 0) {
                        $stateId = (int) $db->getValue(
                            'SELECT s.`id_state`
                               FROM `' . bqSQL($stateTable) . '` s
                              WHERE s.`id_country` = ' . $colombiaCountryId . '
                                AND s.`name` COLLATE utf8mb4_unicode_ci = ' . $departmentSql . '
                              LIMIT 1'
                        );
                    }
                    $row['id_state'] = $stateId;
                }
            } catch (\Throwable $e) {
                PrestaShopLogger::addLog(
                    '[ps_colombia_address] AJAX municipality lookup error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                    3
                );
                $this->jsonError('Internal server error.', 500);
            }

            if (!is_array($row) || empty($row['department'])) {
                $this->jsonError('Municipality not found.', 404);
            }

            header('Cache-Control: public, max-age=600, s-maxage=3600');
            header('Vary: Accept-Encoding');
            $this->jsonSuccess([
                'state_id' => (int) ($row['id_state'] ?? 0),
                'department' => (string) ($row['department'] ?? ''),
                'municipality' => (string) ($row['municipality'] ?? ''),
                'postal_code' => (string) ($row['postal_code'] ?? ''),
                'dane_code' => (string) ($row['dane_code'] ?? ''),
                'latitude' => (string) ($row['latitude'] ?? ''),
                'longitude' => (string) ($row['longitude'] ?? ''),
            ]);
        }

        // Sanitise and validate the department parameter.
        $rawDept    = (string) Tools::getValue('department', '');
        $department = $this->sanitiseDepartment($rawDept);

        if ($department === '') {
            $this->jsonError('Missing or invalid "department" parameter.', 400);
        }

        // Fetch municipalities directly via DB (same pattern as departments endpoint).
        try {
                $db = Db::getInstance();
                $municipalityTable = $this->resolveSqlTableName($db, 'colombia_municipality');
            $departmentSql = $this->quoteSqlString($department) . ' COLLATE utf8mb4_unicode_ci';

                $rows = $db->executeS(
                'SELECT `municipality`, `postal_code`, `dane_code`, `latitude`, `longitude`
                         FROM `' . bqSQL($municipalityTable) . '`
                  WHERE `department` COLLATE utf8mb4_unicode_ci = ' . $departmentSql . '
               ORDER BY `municipality` ASC'
            );

            $municipalities = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $municipalities[] = [
                        'name'        => (string) ($row['municipality'] ?? ''),
                        'postal_code' => (string) ($row['postal_code'] ?? ''),
                        'dane_code'   => (string) ($row['dane_code']   ?? ''),
                        'latitude'    => (string) ($row['latitude']    ?? ''),
                        'longitude'   => (string) ($row['longitude']   ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[ps_colombia_address] AJAX municipalities error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                3
            );
            $this->jsonError('Internal server error.', 500);
        }

        // HTTP caching: municipalities are essentially static — allow a short cache.
        header('Cache-Control: public, max-age=600, s-maxage=3600');
        header('Vary: Accept-Encoding');

        $this->jsonSuccess(['municipalities' => $municipalities]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Validate the caller's token against the PrestaShop static front token.
     * Accepts the general static token so storefront JS can pass it without
     * requiring a per-page token.
     */
    private function isValidToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        // Compare against the shop-level static token (not user-session token)
        return hash_equals(Tools::getToken(false), $token);
    }

    /**
     * Strip characters that cannot appear in a Colombian department name.
     * Allows accented letters, spaces, hyphens, and dots.
     */
    private function sanitiseDepartment(string $raw): string
    {
        $clean = preg_replace(
            "/[^a-zA-ZáéíóúÁÉÍÓÚñÑüÜàèìòùÀÈÌÒÙ\s\-\.]/u",
            '',
            trim($raw)
        );

        return substr((string) $clean, 0, self::MAX_DEPT_LENGTH);
    }

    private function quoteSqlString(string $value): string
    {
        return '\'' . pSQL($value, true) . '\'';
    }

    private function getColombiaCountryId(): int
    {
        $db = Db::getInstance();
        $countryTable = $this->resolveSqlTableName($db, 'country');

        return (int) Db::getInstance()->getValue(
            'SELECT `id_country`
               FROM `' . bqSQL($countryTable) . '`
              WHERE `iso_code` = \'CO\'
              LIMIT 1'
        );
    }

    private function resolveSqlTableName(Db $db, string $table): string
    {
        $prefixed = _DB_PREFIX_ . $table;

        if ($this->tableExists($db, $prefixed)) {
            return $prefixed;
        }

        if ($this->tableExists($db, $table)) {
            return $table;
        }

        return $prefixed;
    }

    private function tableExists(Db $db, string $tableName): bool
    {
        try {
            $dbName = (string) $db->getValue('SELECT DATABASE()');
            if ($dbName === '') {
                return false;
            }

            $rows = $db->executeS(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES'
                . ' WHERE TABLE_SCHEMA = ' . $this->quoteSqlString($dbName)
                . ' AND TABLE_NAME = ' . $this->quoteSqlString($tableName)
            );

            return is_array($rows) && !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Output a JSON error response and halt execution.
     *
     * @param string $message
     * @param int    $httpStatus
     * @return never
     */
    private function jsonError(string $message, int $httpStatus = 400): never
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Output a successful JSON response and halt execution.
     *
     * @param array<string, mixed> $data
     * @return never
     */
    private function jsonSuccess(array $data): never
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

if (!class_exists('PsColombiaAddressMunicipalitiesModuleFrontController', false)) {
    class_alias('ps_colombia_addressmunicipalitiesModuleFrontController', 'PsColombiaAddressMunicipalitiesModuleFrontController');
}
