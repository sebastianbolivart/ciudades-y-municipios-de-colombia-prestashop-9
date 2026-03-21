<?php
/**
 * ColombiaAddressFormModifier
 *
 * Modifies both the back-office and front-office address forms to
 * include a Colombian municipality dropdown, DANE code hidden field,
 * and postal-code syncing — all via PrestaShop hook params.
 *
 * Strategy
 * ─────────
 * 1. Leave the native `city` TextType in place but mark it for
 *    JS-driven hiding so that it continues to store data normally.
 * 2. Add a `colombia_municipality` ChoiceType with one placeholder
 *    entry; real options are loaded at runtime by checkout.js via AJAX.
 * 3. Add a `colombia_dane_code` HiddenType (stores the DANE code).
 * 4. JS syncs the municipality selection → city field value and
 *    autofills postal_code.
 *
 * No core files are modified. Integration is purely through Symfony
 * form builder event listeners supplied in the hook $params.
 *
 * @package  PsColombiaAddress\Form
 * @author   Custom
 * @license  MIT
 */

declare(strict_types=1);

namespace PsColombiaAddress\Form;

use Configuration;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Injected via Symfony DI — see config/services.yml.
 */
final class ColombiaAddressFormModifier
{
    /**
     * Entry-point called from both hook methods on the main module class.
     *
     * Expected $params keys:
     *   form_builder  FormBuilderInterface
     *   data          array  – current address data
     *   id            int    – address ID (0 for new)
     *
     * @param array<string, mixed> $params
     */
    public function modify(array $params): void
    {
        /** @var FormBuilderInterface|null $formBuilder */
        $formBuilder = $params['form_builder'] ?? null;

        if (!$formBuilder instanceof FormBuilderInterface) {
            return;
        }

        $this->ensureStateFieldVisible($formBuilder);
        $this->addMunicipalityField($formBuilder, $params);
        $this->addDaneCodeField($formBuilder);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Add a `colombia_municipality` ChoiceType.
     *
     * The list starts empty (single placeholder choice) because municipalities
     * depend on the selected department and are loaded via AJAX.
     * checkout.js handles the dynamic population and syncs the value back
     * to the native `city` hidden field on form submit.
     *
     * HTML attributes applied here drive the JS behaviour:
     *   data-colombia-municipality  – JS selector hook
     *   data-autofill-postal        – tells JS to autofill postal_code
     *
     * @param FormBuilderInterface $formBuilder
     * @param array<string, mixed> $params
     */
    private function addMunicipalityField(FormBuilderInterface $formBuilder, array $params): void
    {
        $autofillPostal = (bool) Configuration::get('COLOMBIA_ADDRESS_AUTOFILL_POSTAL');

        $formBuilder->add('colombia_municipality', ChoiceType::class, [
            'label'    => false,   // Rendered by the Twig theme with custom label
            'required' => false,   // Native validation still runs on `city`
            'mapped'   => false,   // This field is not part of the Address entity
            'choices'  => [
                '— Seleccione un municipio —' => '',
            ],
            'attr' => [
                'class'                    => 'form-control colombia-municipality-select',
                'data-colombia-municipality' => '1',
                'data-autofill-postal'     => $autofillPostal ? '1' : '0',
            ],
        ]);
    }

    /**
     * Add a `colombia_dane_code` HiddenType.
     *
     * Populated by checkout.js when a municipality is selected.
     * Submitted alongside the form; read back in the after-save hooks
     * to persist into `ps_colombia_address_extra`.
     */
    private function addDaneCodeField(FormBuilderInterface $formBuilder): void
    {
        $formBuilder->add('colombia_dane_code', HiddenType::class, [
            'required' => false,
            'mapped'   => false,
            'attr' => [
                'data-colombia-dane-code' => '1',
            ],
        ]);
    }

    /**
     * Ensure the id_state (department/state) field is visible in the form.
     * In PrestaShop 9, this field may be hidden by the system if the
     * current country doesn't have states. Since we're adding states for
     * Colombia, we need to make sure it's visible.
     *
     * @param FormBuilderInterface $formBuilder
     */
    private function ensureStateFieldVisible(FormBuilderInterface $formBuilder): void
    {
        if ($formBuilder->has('id_state')) {
            $field = $formBuilder->get('id_state');
            $options = $field->getOptions();
            $options['attr']['class'] = (isset($options['attr']['class']) ? $options['attr']['class'] . ' ' : '') . 'form-control';
            $field->setData($field->getData());
        }
    }
}
