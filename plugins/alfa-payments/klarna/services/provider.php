<?php

/**
 * @package    Alfa Commerce - Klarna Payment Plugin
 * @copyright  (C) 2024-2026 Alfa. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Plugin\AlfaPayments\Klarna\Extension\Klarna;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

// ─────────────────────────────────────────────────────────────────────────────
//  Register the Klarna PHP SDK namespace — pure PSR-4, no JLoader, no Composer.
//
//  Joomla 4+ uses a Composer-based primary autoloader. JLoader still exists
//  in Joomla 5/6 as a backward-compat alias but is NOT the main autoloader
//  and should not be used in new code. Alfa Commerce itself uses zero JLoader
//  calls. We follow the same pattern: plain spl_autoload_register().
//
//  Namespace:  Alfa\PhpKlarna\*
//  Root path:  plugins/alfa-payments/klarna/libraries/klarna/src/
//
//  After this closure is registered, any class like:
//    Alfa\PhpKlarna\Actions\ManageOrders
//  resolves to:
//    .../libraries/klarna/src/Actions/ManageOrders.php
// ─────────────────────────────────────────────────────────────────────────────
spl_autoload_register(static function (string $class): void {
    // Only handle our SDK namespace — let everything else fall through
    if (!str_starts_with($class, 'Alfa\\PhpKlarna\\')) {
        return;
    }

    $file = \dirname(__DIR__)
        . '/libraries/klarna/src/'
        . str_replace('\\', \DIRECTORY_SEPARATOR, substr($class, \strlen('Alfa\\PhpKlarna\\')))
        . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): Klarna {
                $plugin = new Klarna(
                    (array) PluginHelper::getPlugin('alfa-payments', 'klarna'),
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            },
        );
    }
};
