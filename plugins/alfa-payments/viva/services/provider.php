<?php

defined('_JEXEC') or die;

use Alfa\Plugin\AlfaPayments\Viva\Extension\Viva;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

// ─────────────────────────────────────────────────────────────────────────────
//  Register the Alfa\PhpViva SDK namespace.
//  Pure PSR-4 via spl_autoload_register — no Composer, no external dependencies.
//
//  Covers all sub-namespaces:
//    Alfa\PhpViva\Account\*        → Account/
//    Alfa\PhpViva\SmartCheckout\*  → SmartCheckout/
//    Alfa\PhpViva\Transaction\*    → Transaction/
// ─────────────────────────────────────────────────────────────────────────────
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Alfa\\PhpViva\\')) {
        return;
    }

    $file = \dirname(__DIR__)
        . '/libraries/viva/src/'
        . str_replace('\\', \DIRECTORY_SEPARATOR, substr($class, \strlen('Alfa\\PhpViva\\')))
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
            static function (Container $container): Viva {
                $plugin = new Viva(
                    (array) PluginHelper::getPlugin('alfa-payments', 'viva'),
                );
                $plugin->setApplication(Factory::getApplication());
                return $plugin;
            },
        );
    }
};
