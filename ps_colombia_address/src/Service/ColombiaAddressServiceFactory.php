<?php
/**
 * ColombiaAddressServiceFactory
 *
 * Creates ColombiaAddressService instances using the PrestaShop
 * legacy Db façade and the configured DB prefix.
 * Acts as a bridge between Symfony DI and PrestaShop's non-injectable globals.
 *
 * @package  PsColombiaAddress\Service
 * @author   Custom
 * @license  MIT
 */

declare(strict_types=1);

namespace PsColombiaAddress\Service;

/**
 * Static factory consumed by services.yml:
 *   factory: ['PsColombiaAddress\Service\ColombiaAddressServiceFactory', 'create']
 */
final class ColombiaAddressServiceFactory
{
    /**
     * Build and return a fully configured ColombiaAddressService.
     */
    public static function create(): ColombiaAddressService
    {
        return new ColombiaAddressService(
            \Db::getInstance(),
            _DB_PREFIX_
        );
    }
}
