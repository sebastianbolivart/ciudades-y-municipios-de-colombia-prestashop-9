<?php
/**
 * ps_colombia_address Module Bundle
 *
 * Registers the module with Symfony so that services, routes, and
 * Twig resources are loaded automatically by PrestaShop 9.
 *
 * @author   Custom
 * @license  MIT
 */

declare(strict_types=1);

namespace PsColombiaAddress;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ColombiaAddressBundle extends Bundle
{
    /**
     * Get the bundle name. Must match module technical name.
     */
    public function getName(): string
    {
        return 'ps_colombia_address';
    }

    /**
     * Root namespace for this bundle.
     */
    public const ROOT_NAMESPACE = 'PsColombiaAddress';
}
