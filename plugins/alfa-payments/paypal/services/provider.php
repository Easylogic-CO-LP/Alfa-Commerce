<?php

/**
 * @package    Alfa Commerce - PayPal Payment Plugin
 * @copyright  (C) 2024-2026 Alfa. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Plugin\AlfaPayments\PayPal\Extension\PayPal;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

// ─────────────────────────────────────────────────────────────────────────────
//  Load the PayPal PHP Server SDK via its bundled Composer autoloader.
//
//  Unlike Klarna (which had zero external deps and used spl_autoload_register),
//  the PayPal SDK depends on apimatic/core, apimatic/unirest-php, and
//  apimatic/core-interfaces — a full HTTP/auth/retry framework that cannot be
//  replaced with Joomla's HttpFactory.
//
//  The vendor/ directory is generated ONCE by the developer with:
//    cd plugins/alfa-payments/paypal/libraries
//    composer install --no-dev --optimize-autoloader
//  and then committed to the repo / shipped inside the plugin zip.
//  The production Joomla server does NOT need Composer installed.
//
//  This single require_once registers all namespaces:
//    PaypalServerSdkLib\*    — the SDK itself (675 files)
//    Core\*                  — apimatic/core (request pipeline)
//    CoreInterfaces\*        — apimatic/core-interfaces
//    Unirest\*               — apimatic/unirest-php (HTTP transport)
// ─────────────────────────────────────────────────────────────────────────────
$vendorAutoload = \dirname(__DIR__) . '/libraries/vendor/autoload.php';

if (!\file_exists($vendorAutoload)) {
    // Vendor directory not found — plugin cannot function.
    // Log clearly so the developer knows what to do.
    \Joomla\CMS\Log\Log::add(
        'PayPal plugin: vendor/autoload.php not found. '
        . 'Run: cd ' . \dirname(__DIR__) . '/libraries && composer install --no-dev --optimize-autoloader',
        \Joomla\CMS\Log\Log::CRITICAL,
        'com_alfa.payments',
    );

    // Return a no-op service provider so Joomla doesn't fatal
    return new class () implements ServiceProviderInterface {
        public function register(Container $container): void
        {
        }
    };
}

require_once $vendorAutoload;

// ─────────────────────────────────────────────────────────────────────────────
//  Register the plugin in Joomla's DI container.
//  Identical pattern to the Standard plugin — static closure, no $this capture.
// ─────────────────────────────────────────────────────────────────────────────
return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PayPal {
                $plugin = new PayPal(
                    (array) PluginHelper::getPlugin('alfa-payments', 'paypal'),
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            },
        );
    }
};
