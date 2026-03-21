<?php
/**
 * AdminColombiaAddress Controller (Legacy)
 *
 * Back-office controller for module configuration.
 * Uses PrestaShop legacy controller pattern (no Symfony dependency).
 *
 * @author   Custom
 * @license  MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminColombiaAddressController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = false;
        $this->className = 'ColombiaAddress';

        parent::__construct();

        $this->page_header_toolbar_title = $this->trans(
            'Colombia Address Manager',
            array(),
            'Modules.PsColombiaAddress.Admin'
        );
    }

    public function renderForm(): string
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Configuration', array(), 'Admin.Global'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Enable Module', array(), 'Modules.PsColombiaAddress.Admin'),
                        'name' => 'COLOMBIA_ADDRESS_ENABLE',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', array(), 'Admin.Global')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', array(), 'Admin.Global')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Enable Municipality Dropdown', array(), 'Modules.PsColombiaAddress.Admin'),
                        'name' => 'COLOMBIA_ADDRESS_ENABLE_DROPDOWN',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'dropdown_on', 'value' => 1, 'label' => $this->trans('Yes', array(), 'Admin.Global')),
                            array('id' => 'dropdown_off', 'value' => 0, 'label' => $this->trans('No', array(), 'Admin.Global'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Autofill Postal Code', array(), 'Modules.PsColombiaAddress.Admin'),
                        'name' => 'COLOMBIA_ADDRESS_AUTOFILL_POSTAL',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'postal_on', 'value' => 1, 'label' => $this->trans('Yes', array(), 'Admin.Global')),
                            array('id' => 'postal_off', 'value' => 0, 'label' => $this->trans('No', array(), 'Admin.Global'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Logistics Mode', array(), 'Modules.PsColombiaAddress.Admin'),
                        'name' => 'COLOMBIA_ADDRESS_LOGISTICS_MODE',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'logistics_on', 'value' => 1, 'label' => $this->trans('Yes', array(), 'Admin.Global')),
                            array('id' => 'logistics_off', 'value' => 0, 'label' => $this->trans('No', array(), 'Admin.Global'))
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                    'class' => 'btn btn-default pull-right'
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = true;
        $helper->table = $this->table;
        $helper->module = $this->module;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_personalization = false;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitColombiaAddressModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure='.$this->module->name
            . '&tab_module='.$this->module->tab
            . '&module_name='.$this->module->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->language,
            'is_form' => true,
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues(): array
    {
        return array(
            'COLOMBIA_ADDRESS_ENABLE' => (bool) Configuration::get('COLOMBIA_ADDRESS_ENABLE'),
            'COLOMBIA_ADDRESS_AUTOFILL_POSTAL' => (bool) Configuration::get('COLOMBIA_ADDRESS_AUTOFILL_POSTAL'),
            'COLOMBIA_ADDRESS_ENABLE_DROPDOWN' => (bool) Configuration::get('COLOMBIA_ADDRESS_ENABLE_DROPDOWN'),
            'COLOMBIA_ADDRESS_LOGISTICS_MODE' => (bool) Configuration::get('COLOMBIA_ADDRESS_LOGISTICS_MODE'),
        );
    }

    public function postProcess(): void
    {
        if (Tools::isSubmit('submitColombiaAddressModule')) {
            Configuration::updateValue('COLOMBIA_ADDRESS_ENABLE', (int) Tools::getValue('COLOMBIA_ADDRESS_ENABLE'));
            Configuration::updateValue('COLOMBIA_ADDRESS_AUTOFILL_POSTAL', (int) Tools::getValue('COLOMBIA_ADDRESS_AUTOFILL_POSTAL'));
            Configuration::updateValue('COLOMBIA_ADDRESS_ENABLE_DROPDOWN', (int) Tools::getValue('COLOMBIA_ADDRESS_ENABLE_DROPDOWN'));
            Configuration::updateValue('COLOMBIA_ADDRESS_LOGISTICS_MODE', (int) Tools::getValue('COLOMBIA_ADDRESS_LOGISTICS_MODE'));

            $this->confirmations[] = $this->trans('Settings updated successfully.', array(), 'Admin.Notifications.Success');
        }
    }

    public function renderPage(): ?string
    {
        if (Tools::isSubmit('submitColombiaAddressModule')) {
            $this->postProcess();
        }

        return $this->renderForm();
    }
}
