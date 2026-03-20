<?php
/**
 * ColombiaAddressController (Admin)
 *
 * Back-office Symfony controller for the ps_colombia_address module.
 *
 * Routes:
 *   GET  /admin/colombia-address            → indexAction      (dashboard + config form)
 *   POST /admin/colombia-address/save       → saveAction       (persist config)
 *   GET  /admin/colombia-address/municipalities → municipalitiesAction  (dataset list)
 *   POST /admin/colombia-address/import     → importAction     (CSV upload & import)
 *
 * Security:
 *   • All routes require ROLE_ADMIN (enforced by PrestaShop Symfony firewall).
 *   • Forms use CSRF tokens (Symfony CsrfTokenManager).
 *   • CSV upload validates MIME type, extension, and max file size.
 *   • All user-supplied data is validated before DB write.
 *
 * @package  PsColombiaAddress\Controller\Admin
 * @author   Custom
 * @license  MIT
 */

declare(strict_types=1);

namespace PsColombiaAddress\Controller\Admin;

use Configuration;
use PrestaShop\PrestaShop\Core\Security\Permission;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PsColombiaAddress\Service\ColombiaAddressService;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * FrameworkBundleAdminController already wires:
 *   - Symfony security (admin must be authenticated).
 *   - Twig rendering helpers (renderTemplate).
 *   - Translator, router, flash messages.
 */
class ColombiaAddressController extends FrameworkBundleAdminController
{
    /** Max allowed CSV upload size (10 MB). */
    private const MAX_UPLOAD_BYTES = 10_485_760;

    /** Allowed MIME types for CSV uploads. */
    private const ALLOWED_MIME_TYPES = ['text/csv', 'text/plain', 'application/csv'];

    // ─── Constructor injection ────────────────────────────────────────────────

    public function __construct(
        private readonly ColombiaAddressService $addressService
    ) {
    }

    // ─── Actions ─────────────────────────────────────────────────────────────

    /**
     * Dashboard: display module configuration form.
     *
     * @Route("/admin/colombia-address", name="admin_colombia_address_index")
     */
    public function indexAction(): Response
    {
        return $this->renderTemplate(
            '@Modules/ps_colombia_address/views/templates/admin/index.html.twig',
            [
                'enableModule'       => (bool) Configuration::get('COLOMBIA_ADDRESS_ENABLE'),
                'autofillPostal'     => (bool) Configuration::get('COLOMBIA_ADDRESS_AUTOFILL_POSTAL'),
                'enableDropdown'     => (bool) Configuration::get('COLOMBIA_ADDRESS_ENABLE_DROPDOWN'),
                'enableAutocomplete' => (bool) Configuration::get('COLOMBIA_ADDRESS_ENABLE_AUTOCOMPLETE'),
                'logisticsMode'      => (bool) Configuration::get('COLOMBIA_ADDRESS_LOGISTICS_MODE'),
                'datasetCount'       => $this->addressService->getMunicipalityCount(),
            ]
        );
    }

    /**
     * Save configuration form POST.
     *
     * @Route("/admin/colombia-address/save", name="admin_colombia_address_save", methods={"POST"})
     */
    public function saveAction(Request $request): RedirectResponse
    {
        // CSRF validation
        $submittedToken = $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('colombia_address_config', $submittedToken)) {
            $this->addFlash('error', $this->trans('Invalid security token.', 'Modules.PsColombiaAddress.Admin'));
            return $this->redirectToRoute('admin_colombia_address_index');
        }

        Configuration::updateValue(
            'COLOMBIA_ADDRESS_ENABLE',
            $request->request->getBoolean('enable') ? '1' : '0'
        );
        Configuration::updateValue(
            'COLOMBIA_ADDRESS_AUTOFILL_POSTAL',
            $request->request->getBoolean('autofill_postal') ? '1' : '0'
        );
        Configuration::updateValue(
            'COLOMBIA_ADDRESS_ENABLE_DROPDOWN',
            $request->request->getBoolean('enable_dropdown') ? '1' : '0'
        );
        Configuration::updateValue(
            'COLOMBIA_ADDRESS_ENABLE_AUTOCOMPLETE',
            $request->request->getBoolean('enable_autocomplete') ? '1' : '0'
        );
        Configuration::updateValue(
            'COLOMBIA_ADDRESS_LOGISTICS_MODE',
            $request->request->getBoolean('logistics_mode') ? '1' : '0'
        );

        $this->addFlash(
            'success',
            $this->trans('Configuration saved successfully.', 'Modules.PsColombiaAddress.Admin')
        );

        return $this->redirectToRoute('admin_colombia_address_index');
    }

    /**
     * Municipality dataset manager.
     *
     * @Route("/admin/colombia-address/municipalities", name="admin_colombia_address_municipalities")
     */
    public function municipalitiesAction(Request $request): Response
    {
        // Simple pagination
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = 50;

        $department = trim(
            preg_replace(
                "/[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-]/u",
                '',
                (string) $request->query->get('department', '')
            )
        );

        $municipalities = $department !== ''
            ? $this->addressService->getMunicipalitiesByDepartment($department)
            : [];

        $departments = $this->addressService->getDepartments();

        return $this->renderTemplate(
            '@Modules/ps_colombia_address/views/templates/admin/municipalities.html.twig',
            [
                'departments'    => $departments,
                'municipalities' => $municipalities,
                'selectedDept'   => $department,
                'datasetCount'   => $this->addressService->getMunicipalityCount(),
            ]
        );
    }

    /**
     * Handle CSV file upload and re-import the municipality dataset.
     *
     * @Route("/admin/colombia-address/import", name="admin_colombia_address_import", methods={"POST"})
     */
    public function importAction(Request $request): RedirectResponse
    {
        // CSRF protection
        $submittedToken = $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('colombia_address_import', $submittedToken)) {
            $this->addFlash('error', $this->trans('Invalid security token.', 'Modules.PsColombiaAddress.Admin'));
            return $this->redirectToRoute('admin_colombia_address_municipalities');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('csv_file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->trans('No valid file uploaded.', 'Modules.PsColombiaAddress.Admin'));
            return $this->redirectToRoute('admin_colombia_address_municipalities');
        }

        // Size check
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            $this->addFlash('error', $this->trans('File exceeds maximum allowed size (10 MB).', 'Modules.PsColombiaAddress.Admin'));
            return $this->redirectToRoute('admin_colombia_address_municipalities');
        }

        // Extension / MIME check
        $extension = strtolower($file->getClientOriginalExtension());
        $mime      = strtolower((string) $file->getMimeType());

        if ($extension !== 'csv' || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            $this->addFlash('error', $this->trans('Only CSV files are allowed.', 'Modules.PsColombiaAddress.Admin'));
            return $this->redirectToRoute('admin_colombia_address_municipalities');
        }

        // Move to a safe temp location
        $tmpDir = sys_get_temp_dir();
        $tmpName = 'colombia_import_' . bin2hex(random_bytes(8)) . '.csv';

        try {
            $file->move($tmpDir, $tmpName);
        } catch (FileException $e) {
            $this->addFlash('error', $this->trans('Could not move uploaded file.', 'Modules.PsColombiaAddress.Admin'));
            return $this->redirectToRoute('admin_colombia_address_municipalities');
        }

        $tmpPath = $tmpDir . '/' . $tmpName;

        // Retrieve the module instance to call its import helper.
        /** @var \Ps_colombia_address $module */
        $module   = \Module::getInstanceByName('ps_colombia_address');
        $imported = $module->importMunicipalitiesCsv($tmpPath);

        // Clean up temp file regardless of outcome.
        @unlink($tmpPath);

        if ($imported < 0) {
            $this->addFlash('error', $this->trans('Import failed: invalid CSV structure.', 'Modules.PsColombiaAddress.Admin'));
        } else {
            $this->addFlash(
                'success',
                sprintf(
                    $this->trans('Dataset imported successfully (%d municipalities).', 'Modules.PsColombiaAddress.Admin'),
                    $imported
                )
            );
        }

        return $this->redirectToRoute('admin_colombia_address_municipalities');
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Lightweight CSRF check using Symfony's CsrfTokenManager.
     */
    private function isCsrfTokenValid(string $id, string $token): bool
    {
        return $this->get('security.csrf.token_manager')
            ->isTokenValid(new CsrfToken($id, $token));
    }
}
