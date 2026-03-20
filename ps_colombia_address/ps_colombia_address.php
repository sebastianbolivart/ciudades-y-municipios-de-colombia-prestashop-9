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

        if (!$this->installAdminTab()) {
            $this->_errors[] = 'Could not install back-office tab.';
            return false;
        }

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

        $db         = Db::getInstance();
        $table      = 'colombia_municipality';
        $tableSql   = _DB_PREFIX_ . $table;

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

            if ($db->insert($table, [
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
     * Back-office module configuration page — redirect to the Symfony route.
     */
    public function getContent(): string
    {
        try {
            /** @var \Symfony\Component\Routing\Router $router */
            $router = $this->get('router');
            Tools::redirectAdmin($router->generate('admin_colombia_address_index'));
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                sprintf('[ps_colombia_address] getContent router error: %s', $e->getMessage()),
                3,
                null,
                'Module',
                (int) $this->id
            );
        }

        return '';
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
     * Add a tab entry in the back-office navigation.
     */
    private function installAdminTab(): bool
    {
        $tab             = new Tab();
        $tab->active     = 1;
        $tab->class_name = 'AdminColombiaAddress';
        $tab->route_name = 'admin_colombia_address_index';
        $tab->id_parent  = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->module     = $this->name;

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Colombia Address';
        }

        return (bool) $tab->add();
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
     * Retrieve the Symfony form-modifier service safely.
     *
     * @return \PsColombiaAddress\Form\ColombiaAddressFormModifier|null
     */
    private function getFormModifier(): ?object
    {
        try {
            return $this->get('ps_colombia_address.form.modifier');
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
        $tableSql = _DB_PREFIX_ . $table;

        // Upsert: update if exists, insert if not.
        $exists = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . bqSQL($tableSql) . '` WHERE `id_address` = ' . $idAddress
        );

        if ($exists > 0) {
            $db->update($table, [
                'dane_code'  => $daneCode,
                'latitude'   => $latitude,
                'longitude'  => $longitude,
            ], '`id_address` = ' . $idAddress);
        } else {
            $db->insert($table, [
                'id_address' => $idAddress,
                'dane_code'  => $daneCode,
                'latitude'   => $latitude,
                'longitude'  => $longitude,
            ]);
        }
    }
}
