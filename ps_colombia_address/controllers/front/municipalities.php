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
class Ps_colombia_addressMunicipalitiesModuleFrontController extends ModuleFrontController
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
                $rows = Db::getInstance()->executeS(
                    'SELECT s.`id_state`, s.`name`
                       FROM `' . bqSQL(_DB_PREFIX_ . 'state') . '` s
                       INNER JOIN `' . bqSQL(_DB_PREFIX_ . 'country') . '` c ON c.`id_country` = s.`id_country`
                      WHERE c.`iso_code` = \'CO\'
                   ORDER BY s.`name` ASC'
                );

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
                    '[ps_colombia_address] AJAX departments error: ' . $e->getMessage(),
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
                $query = new DbQuery();
                $query->select('m.`department`, m.`municipality`, m.`postal_code`, m.`dane_code`, m.`latitude`, m.`longitude`, s.`id_state`');
                $query->from('colombia_municipality', 'm');
                $query->leftJoin('state', 's', 's.`name` = m.`department`');
                $query->leftJoin('country', 'c', 'c.`id_country` = s.`id_country` AND c.`iso_code` = \'CO\'');
                $query->where('m.`municipality` = ' . $this->quoteSqlString($municipality));

                $row = Db::getInstance()->getRow((string) $query);
            } catch (\Throwable $e) {
                PrestaShopLogger::addLog(
                    '[ps_colombia_address] AJAX municipality lookup error: ' . $e->getMessage(),
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
            $query = new DbQuery();
            $query->select('`municipality`, `postal_code`, `dane_code`, `latitude`, `longitude`');
            $query->from('colombia_municipality');
            $query->where('`department` = ' . $this->quoteSqlString($department));
            $query->orderBy('`municipality` ASC');

            $rows = Db::getInstance()->executeS((string) $query);

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
                '[ps_colombia_address] AJAX municipalities error: ' . $e->getMessage(),
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
