<?php

defined('_JEXEC') or die;

use Alfa\Plugin\AlfaPayments\Revolut\Extension\Revolut;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

// Register Alfa\PhpRevolut namespace via spl_autoload_register.
// Zero external dependencies — HTTP transport is Joomla\CMS\Http\HttpFactory.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Alfa\\PhpRevolut\\')) {
        return;
    }

    $file = \dirname(__DIR__)
        . '/libraries/revolut/src/'
        . str_replace('\\', \DIRECTORY_SEPARATOR, substr($class, \strlen('Alfa\\PhpRevolut\\')))
        . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

return new class () implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): Revolut {
                $plugin = new Revolut(
                    (array) PluginHelper::getPlugin('alfa-payments', 'revolut'),
                );
                $plugin->setApplication(Factory::getApplication());
                return $plugin;
            }
        );
    }
};
