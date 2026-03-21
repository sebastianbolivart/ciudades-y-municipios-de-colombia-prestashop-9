<?php
/**
 * ps_colombia_address - Colombian Address Manager for PrestaShop 9
 *
 * Replaces the default city text-input with a structured Colombian
 * geographic hierarchy: Country → Department → Municipality → Postal Code.
 *
 * Integration points (no core files are modified):
 *   - actionAddressFormBuilderModifier   (back-office address forms)
 *   - actionCustomerAddressFormBuilderModifier (front-office checkout)
 *   - actionAfterCreateAddress / actionAfterUpdateAddress (persist extra data)
 *   - displayHeader                      (inject JS + config vars)
 *
 * @author  Custom
 * @version 1.0.0
 * @license MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Class Ps_colombia_address
 *
 * Main module entry-point. Handles install / uninstall lifecycle,
 * hook dispatching, and a thin bridge to Symfony services.
 */
class Ps_colombia_address extends Module
{
    // ─── Configuration keys ───────────────────────────────────────────────────

    public const CONFIG_ENABLE             = 'COLOMBIA_ADDRESS_ENABLE';
    public const CONFIG_AUTOFILL_POSTAL    = 'COLOMBIA_ADDRESS_AUTOFILL_POSTAL';
    public const CONFIG_ENABLE_DROPDOWN    = 'COLOMBIA_ADDRESS_ENABLE_DROPDOWN';
    public const CONFIG_ENABLE_AUTOCOMPLETE = 'COLOMBIA_ADDRESS_ENABLE_AUTOCOMPLETE';
    public const CONFIG_LOGISTICS_MODE     = 'COLOMBIA_ADDRESS_LOGISTICS_MODE';

    /** Maximum allowed size for an imported CSV (10 MB). */
    private const MAX_CSV_IMPORT_BYTES = 10_485_760;

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->name            = 'ps_colombia_address';
        $this->tab             = 'administration';
        $this->version         = '1.0.0';
        $this->author          = 'Custom';
        $this->need_instance   = 0;
        $this->bootstrap       = true;

        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => _PS_VERSION_,
        ];

        parent::__construct();

        $this->displayName = $this->trans(
            'Colombia Address Manager',
            [],
            'Modules.PsColombiaAddress.Admin'
        );

        $this->description = $this->trans(
            'Full Colombian geographic hierarchy: departments, municipalities, DANE codes and coordinates.',
            [],
            'Modules.PsColombiaAddress.Admin'
        );
    }

    // ─── Install / Uninstall ──────────────────────────────────────────────────

    /**
     * Install: create tables → import dataset → register hooks → set defaults.
     */
    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->executeSqlFile('sql/install.sql')) {
            $this->_errors[] = 'Could not create database tables.';
            return false;
        }

        if (!$this->importMunicipalitiesCsv(dirname(__FILE__) . '/data/municipios_colombia.csv')) {
            $this->_errors[] = 'Could not import municipalities dataset.';
            return false;
        }

        if (!$this->registerRequiredHooks()) {
            $this->_errors[] = 'Could not register module hooks.';
            return false;
        }

        if (!$this->setDefaultConfiguration()) {
            $this->_errors[] = 'Could not store default configuration.';
            return false;
        }

        // Defensive cleanup for older broken builds that registered an admin tab.
        $this->removeAdminTab();

        return true;
    }

    /**
     * Uninstall: drop module tables, remove config keys and the admin tab.
     * PrestaShop native tables / data are never touched.
     */
    public function uninstall(): bool
    {
        if (!parent::uninstall()) {
            return false;
        }

        $this->executeSqlFile('sql/uninstall.sql');
        $this->clearConfiguration();
        $this->removeAdminTab();

        return true;
    }

    // ─── Hooks ───────────────────────────────────────────────────────────────

    /**
     * Back-office address form modifier.
     * Adds municipality dropdown and DANE/postal hidden fields.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionAddressFormBuilderModifier(array $params): void
    {
        if (!$this->isModuleActive()) {
            return;
        }
        $this->getFormModifier()?->modify($params);
    }

    /**
     * Front-office (checkout) address form modifier.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionCustomerAddressFormBuilderModifier(array $params): void
    {
        if (!$this->isModuleActive()) {
            return;
        }
        $this->getFormModifier()?->modify($params);
    }

    /**
     * Persist extra address data (DANE code, coordinates) after address creation.
     *
     * @param array<string,mixed> $params  Keys: id_address, address (Address object)
     */
    public function hookActionAfterCreateAddress(array $params): void
    {
        if (!$this->isModuleActive()) {
            return;
        }
        $this->saveAddressExtra($params);
    }

    /**
     * Persist extra address data after address update.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionAfterUpdateAddress(array $params): void
    {
        if (!$this->isModuleActive()) {
            return;
        }
        $this->saveAddressExtra($params);
    }

    /**
     * Inject JS assets and runtime configuration in the storefront header.
     * Only loaded on address / checkout pages to keep performance overhead minimal.
     *
     * @param array<string,mixed> $params
     */
    public function hookDisplayHeader(array $params): void
    {
        if (!$this->isModuleActive()) {
            return;
        }

        /** @var string $controller */
        $controller = (string) Tools::getValue('controller');
        $addressControllers = ['address', 'order', 'orderopc', 'checkout'];

        if (!in_array(strtolower($controller), $addressControllers, true)) {
            return;
        }

        $this->context->controller->addJS($this->_path . 'views/js/checkout.js');

        // Token for AJAX request validation.
        $token = Tools::getToken(false);

        Media::addJsDef([
            'colombiaAddressConfig' => [
                'baseUrl'           => $this->context->link->getModuleLink(
                    $this->name,
                    'municipalities',
                    [],
                    true
                ),
                'autofillPostal'    => (bool) Configuration::get(self::CONFIG_AUTOFILL_POSTAL),
                'logisticsMode'     => (bool) Configuration::get(self::CONFIG_LOGISTICS_MODE),
                'enableAutocomplete' => (bool) Configuration::get(self::CONFIG_ENABLE_AUTOCOMPLETE),
                'token'             => $token,
            ],
        ]);
    }

    // ─── Public helpers ──────────────────────────────────────────────────────

    /**
     * Import municipalities from an arbitrary CSV path.
     * Called both from install() and from the admin CSV-import action.
     *
     * Returns the number of rows successfully imported, or -1 on fatal error.
     */
    public function importMunicipalitiesCsv(string $csvPath): int
    {
        if (!is_readable($csvPath)) {
            return -1;
        }

        if (filesize($csvPath) > self::MAX_CSV_IMPORT_BYTES) {
            return -1;
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return -1;
        }

        // Header row validation
        $header = fgetcsv($handle);
        if (!is_array($header) || count($header) < 6) {
            fclose($handle);
            return -1;
        }

        $db       = Db::getInstance();
        $table    = 'colombia_municipality';
        $tableSql = $this->resolveSqlTableName($db, $table);

        // Truncate before a fresh import so the admin "re-import" scenario works.
        $db->execute('TRUNCATE TABLE `' . bqSQL($tableSql) . '`');

        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 6) {
                continue;
            }

            [$dept, $muni, $postal, $dane, $lat, $lon] = $row;

            // Required fields
            $dept = trim((string) $dept);
            $muni = trim((string) $muni);

            if ($dept === '' || $muni === '') {
                continue;
            }

            // Sanitise scalars
            $dept   = substr($dept, 0, 120);
            $muni   = substr($muni, 0, 120);
            $postal = substr(preg_replace('/[^0-9]/', '', trim((string) $postal)), 0, 20);
            $dane   = substr(preg_replace('/[^0-9]/', '', trim((string) $dane)), 0, 10);

            $lat = (float) $lat;
            $lon = (float) $lon;

            // Clamp coordinates to valid ranges
            if ($lat < -90.0 || $lat > 90.0) {
                $lat = 0.0;
            }
            if ($lon < -180.0 || $lon > 180.0) {
                $lon = 0.0;
            }

            if ($this->dbInsertSafe($db, $table, [
                'department'  => pSQL($dept),
                'municipality' => pSQL($muni),
                'postal_code' => pSQL($postal),
                'dane_code'   => pSQL($dane),
                'latitude'    => $lat,
                'longitude'   => $lon,
            ])) {
                ++$imported;
            }
        }

        fclose($handle);

        return $imported;
    }

    /**
     * Back-office module configuration page.
     * Uses the standard module HelperForm pattern for maximum compatibility.
     */
    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitPsColombiaAddressConfig')) {
            $output .= $this->processConfigurationForm();
        }

        return $output . $this->renderConfigurationForm();
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function isModuleActive(): bool
    {
        return (bool) Configuration::get(self::CONFIG_ENABLE);
    }

    /**
     * Execute a SQL file relative to the module root.
     * Substitutes PREFIX_ with the actual DB prefix before running statements.
     */
    private function executeSqlFile(string $relPath): bool
    {
        $file = dirname(__FILE__) . '/' . $relPath;

        if (!is_readable($file)) {
            return false;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            return false;
        }

        $sql        = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static fn(string $s): bool => $s !== ''
        );

        $db = Db::getInstance();
        foreach ($statements as $stmt) {
            if ($db->execute($stmt) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register all hooks required by the module.
     */
    private function registerRequiredHooks(): bool
    {
        $hooks = [
            'actionAddressFormBuilderModifier',
            'actionCustomerAddressFormBuilderModifier',
            'actionAfterCreateAddress',
            'actionAfterUpdateAddress',
            'displayHeader',
        ];

        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Persist sensible default configuration values on install.
     */
    private function setDefaultConfiguration(): bool
    {
        return
            Configuration::updateValue(self::CONFIG_ENABLE, '1')
            && Configuration::updateValue(self::CONFIG_AUTOFILL_POSTAL, '1')
            && Configuration::updateValue(self::CONFIG_ENABLE_DROPDOWN, '1')
            && Configuration::updateValue(self::CONFIG_ENABLE_AUTOCOMPLETE, '0')
            && Configuration::updateValue(self::CONFIG_LOGISTICS_MODE, '1');
    }

    /**
     * Remove all module-owned configuration keys.
     */
    private function clearConfiguration(): void
    {
        $keys = [
            self::CONFIG_ENABLE,
            self::CONFIG_AUTOFILL_POSTAL,
            self::CONFIG_ENABLE_DROPDOWN,
            self::CONFIG_ENABLE_AUTOCOMPLETE,
            self::CONFIG_LOGISTICS_MODE,
        ];
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }
    }

    /**
     * Remove the back-office tab entry.
     */
    private function removeAdminTab(): void
    {
        $tabId = (int) Tab::getIdFromClassName('AdminColombiaAddress');
        if ($tabId > 0) {
            $tab = new Tab($tabId);
            $tab->delete();
        }
    }

    /**
     * Build the form modifier without relying on Symfony service loading.
     *
     * @return \PsColombiaAddress\Form\ColombiaAddressFormModifier|null
     */
    private function getFormModifier(): ?object
    {
        try {
            require_once dirname(__FILE__) . '/src/Form/ColombiaAddressFormModifier.php';

            return new \PsColombiaAddress\Form\ColombiaAddressFormModifier();
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                sprintf('[ps_colombia_address] Form modifier service unavailable: %s', $e->getMessage()),
                2,
                null,
                'Module',
                (int) $this->id
            );

            return null;
        }
    }

    /**
     * Build the address data service without relying on Symfony service loading.
     */
    public function getAddressService(): ?object
    {
        try {
            require_once dirname(__FILE__) . '/src/Service/ColombiaAddressService.php';
            require_once dirname(__FILE__) . '/src/Service/ColombiaAddressServiceFactory.php';

            return \PsColombiaAddress\Service\ColombiaAddressServiceFactory::create();
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                sprintf('[ps_colombia_address] Address service unavailable: %s', $e->getMessage()),
                3,
                null,
                'Module',
                (int) $this->id
            );

            return null;
        }
    }

    /**
     * Process module configuration form submission.
     */
    private function processConfigurationForm(): string
    {
        Configuration::updateValue(self::CONFIG_ENABLE, (int) Tools::getValue(self::CONFIG_ENABLE, 0));
        Configuration::updateValue(self::CONFIG_AUTOFILL_POSTAL, (int) Tools::getValue(self::CONFIG_AUTOFILL_POSTAL, 0));
        Configuration::updateValue(self::CONFIG_ENABLE_DROPDOWN, (int) Tools::getValue(self::CONFIG_ENABLE_DROPDOWN, 0));
        Configuration::updateValue(self::CONFIG_ENABLE_AUTOCOMPLETE, (int) Tools::getValue(self::CONFIG_ENABLE_AUTOCOMPLETE, 0));
        Configuration::updateValue(self::CONFIG_LOGISTICS_MODE, (int) Tools::getValue(self::CONFIG_LOGISTICS_MODE, 0));

        $importMessage = $this->processCsvUpload();

        return $this->displayConfirmation(
            $this->trans('Configuracion guardada correctamente.', [], 'Modules.PsColombiaAddress.Admin')
        ) . $importMessage;
    }

    /**
     * Import municipalities from an uploaded CSV file if present.
     */
    private function processCsvUpload(): string
    {
        if (!isset($_FILES['PS_COLOMBIA_ADDRESS_CSV_FILE'])) {
            return '';
        }

        $file = $_FILES['PS_COLOMBIA_ADDRESS_CSV_FILE'];

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return $this->displayError(
                $this->trans('No se subió ningún archivo válido.', [], 'Modules.PsColombiaAddress.Admin')
            );
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > self::MAX_CSV_IMPORT_BYTES) {
            return $this->displayError(
                $this->trans('El archivo supera el tamaño máximo permitido (10 MB).', [], 'Modules.PsColombiaAddress.Admin')
            );
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = Tools::strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            return $this->displayError(
                $this->trans('Solo se permiten archivos CSV.', [], 'Modules.PsColombiaAddress.Admin')
            );
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return $this->displayError(
                $this->trans('No se subió ningún archivo válido.', [], 'Modules.PsColombiaAddress.Admin')
            );
        }

        $imported = $this->importMunicipalitiesCsv($tmpPath);
        if ($imported < 0) {
            return $this->displayError(
                $this->trans('Importación fallida: estructura CSV inválida.', [], 'Modules.PsColombiaAddress.Admin')
            );
        }

        return $this->displayConfirmation(
            sprintf(
                $this->trans('Dataset importado correctamente (%d municipios).', [], 'Modules.PsColombiaAddress.Admin'),
                $imported
            )
        );
    }

    /**
     * Render module configuration form.
     */
    private function renderConfigurationForm(): string
    {
        $datasetCount = 0;
        $service = $this->getAddressService();
        if ($service !== null && method_exists($service, 'getMunicipalityCount')) {
            $datasetCount = (int) $service->getMunicipalityCount();
        }

        $fields = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Configuracion Colombia Address', [], 'Modules.PsColombiaAddress.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'description' => sprintf(
                    '%s %d',
                    $this->trans('Municipios cargados en dataset:', [], 'Modules.PsColombiaAddress.Admin'),
                    $datasetCount
                ),
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Habilitar modulo', [], 'Modules.PsColombiaAddress.Admin'),
                        'name' => self::CONFIG_ENABLE,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'enable_on', 'value' => 1, 'label' => $this->trans('Si', [], 'Admin.Global')],
                            ['id' => 'enable_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Dropdown de municipio', [], 'Modules.PsColombiaAddress.Admin'),
                        'name' => self::CONFIG_ENABLE_DROPDOWN,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'dropdown_on', 'value' => 1, 'label' => $this->trans('Si', [], 'Admin.Global')],
                            ['id' => 'dropdown_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Autocompletar codigo postal', [], 'Modules.PsColombiaAddress.Admin'),
                        'name' => self::CONFIG_AUTOFILL_POSTAL,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'postal_on', 'value' => 1, 'label' => $this->trans('Si', [], 'Admin.Global')],
                            ['id' => 'postal_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Habilitar autocomplete', [], 'Modules.PsColombiaAddress.Admin'),
                        'name' => self::CONFIG_ENABLE_AUTOCOMPLETE,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'autocomplete_on', 'value' => 1, 'label' => $this->trans('Si', [], 'Admin.Global')],
                            ['id' => 'autocomplete_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Modo logistica', [], 'Modules.PsColombiaAddress.Admin'),
                        'name' => self::CONFIG_LOGISTICS_MODE,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'logistics_on', 'value' => 1, 'label' => $this->trans('Si', [], 'Admin.Global')],
                            ['id' => 'logistics_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                    [
                        'type' => 'file',
                        'label' => $this->trans('Importar dataset CSV', [], 'Modules.PsColombiaAddress.Admin'),
                        'name' => 'PS_COLOMBIA_ADDRESS_CSV_FILE',
                        'desc' => $this->trans(
                            'Sube un CSV con columnas: department, municipality, postal_code, dane_code, latitude, longitude. Si lo subes, reemplaza todo el dataset actual.',
                            [],
                            'Modules.PsColombiaAddress.Admin'
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Guardar', [], 'Admin.Actions'),
                    'name' => 'submitPsColombiaAddressConfig',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPsColombiaAddressConfig';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->name_controller = $this->name;
        $helper->fields_value = [
            self::CONFIG_ENABLE => (int) Configuration::get(self::CONFIG_ENABLE),
            self::CONFIG_AUTOFILL_POSTAL => (int) Configuration::get(self::CONFIG_AUTOFILL_POSTAL),
            self::CONFIG_ENABLE_DROPDOWN => (int) Configuration::get(self::CONFIG_ENABLE_DROPDOWN),
            self::CONFIG_ENABLE_AUTOCOMPLETE => (int) Configuration::get(self::CONFIG_ENABLE_AUTOCOMPLETE),
            self::CONFIG_LOGISTICS_MODE => (int) Configuration::get(self::CONFIG_LOGISTICS_MODE),
        ];

        return $helper->generateForm([$fields]);
    }

    /**
     * Persist extra Colombian address fields to ps_colombia_address_extra.
     * Called from the afterCreate / afterUpdate hooks.
     *
     * @param array<string,mixed> $params
     */
    private function saveAddressExtra(array $params): void
    {
        $idAddress = (int) ($params['id_address'] ?? 0);
        if ($idAddress <= 0) {
            return;
        }

        $daneCode  = pSQL((string) ($params['address']->colombia_dane_code ?? ''));
        $latitude  = (float)  ($params['address']->colombia_latitude  ?? 0.0);
        $longitude = (float)  ($params['address']->colombia_longitude ?? 0.0);

        if ($daneCode === '') {
            return;
        }

        $db       = Db::getInstance();
        $table    = 'colombia_address_extra';
        $tableSql = $this->resolveSqlTableName($db, $table);

        // Upsert: update if exists, insert if not.
        $exists = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . bqSQL($tableSql) . '` WHERE `id_address` = ' . $idAddress
        );

        if ($exists > 0) {
            $this->dbUpdateSafe($db, $table, [
                'dane_code'  => $daneCode,
                'latitude'   => $latitude,
                'longitude'  => $longitude,
            ], '`id_address` = ' . $idAddress);
        } else {
            $this->dbInsertSafe($db, $table, [
                'id_address' => $idAddress,
                'dane_code'  => $daneCode,
                'latitude'   => $latitude,
                'longitude'  => $longitude,
            ]);
        }
    }

    /**
     * Resolve an existing SQL table name for raw queries.
     * Prefers prefixed table, but supports legacy/manual unprefixed tables.
     */
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

    /**
     * Safe insert supporting both prefixed and unprefixed table naming.
     */
    private function dbInsertSafe(Db $db, string $table, array $data): bool
    {
        $prefixed = _DB_PREFIX_ . $table;

        if ($this->tableExists($db, $prefixed)) {
            return (bool) $db->insert($table, $data);
        }

        if ($this->tableExists($db, $table)) {
            // Table exists without prefix; insert directly without auto-prefixing
            return (bool) $db->execute('INSERT INTO `' . bqSQL($table) . '` ' . $this->buildInsertSQL($data));
        }

        return (bool) $db->insert($table, $data);
    }

    /**
     * Safe update supporting both prefixed and unprefixed table naming.
     */
    private function dbUpdateSafe(Db $db, string $table, array $data, string $where): bool
    {
        $prefixed = _DB_PREFIX_ . $table;

        if ($this->tableExists($db, $prefixed)) {
            return (bool) $db->update($table, $data, $where);
        }

        if ($this->tableExists($db, $table)) {
            // Table exists without prefix; update directly without auto-prefixing
            return (bool) $db->execute('UPDATE `' . bqSQL($table) . '` SET ' . $this->buildUpdateSQL($data) . ' WHERE ' . $where);
        }

        return (bool) $db->update($table, $data, $where);
    }

    /**
     * Check if a table exists in current database.
     * Compatible with both MySQL and MariaDB using INFORMATION_SCHEMA.
     */
    private function tableExists(Db $db, string $tableName): bool
    {
        try {
            // Get current database name
            $dbName = (string) $db->getValue('SELECT DATABASE()');
            if (empty($dbName)) {
                return false;
            }

            // Use INFORMATION_SCHEMA (compatible with MySQL 5.7+ and MariaDB 10.1+)
            $sql = 'SELECT 1 FROM INFORMATION_SCHEMA.TABLES'
                 . ' WHERE TABLE_SCHEMA = ' . pSQL($dbName)
                 . ' AND TABLE_NAME = ' . pSQL($tableName)
                 . ' LIMIT 1';

            return (bool) $db->getValue($sql);
        } catch (\Exception $e) {
            // Fallback for ancient MySQL/MariaDB versions
            try {
                $result = $db->getValue('DESCRIBE `' . bqSQL($tableName) . '` LIMIT 1');
                return (bool) $result;
            } catch (\Exception $ex) {
                return false;
            }
        }
    }

    /**
     * Helper: Build SQL SET clause from data array.
     * Compatible with MySQL and MariaDB.
     */
    private function buildUpdateSQL(array $data): string
    {
        $sets = [];
        foreach ($data as $key => $value) {
            $sets[] = '`' . bqSQL($key) . '` = ' . $this->sqlValue($value);
        }

        return implode(', ', $sets);
    }

    /**
     * Helper: Build SQL INSERT VALUES clause from data array.
     * Compatible with MySQL and MariaDB.
     */
    private function buildInsertSQL(array $data): string
    {
        $keys = array_map(function($k) { return '`' . bqSQL($k) . '`'; }, array_keys($data));
        $vals = array_map(function($v) { return $this->sqlValue($v); }, array_values($data));

        return '(' . implode(', ', $keys) . ') VALUES (' . implode(', ', $vals) . ')';
    }

    /**
     * Convert a PHP value to SQL-safe representation.
     * Handles strings, integers, floats, and null.
     * Compatible with both MySQL and MariaDB.
     */
    private function sqlValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Preserve float precision for coordinates (decimal(10,8), decimal(11,8))
            // Works correctly in both MySQL and MariaDB
            return number_format($value, 10, '.', '');
        }

        // String (default) - PrestaShop pSQL() works for both MySQL and MariaDB
        return pSQL((string) $value);
    }
}
