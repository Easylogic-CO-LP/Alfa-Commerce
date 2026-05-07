<?php
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Extension\Service\Provider\PluginDispatcher;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\AlfaFields\Tel\Extension\Tel;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin     = new Tel(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('alfa-fields', 'tel')
                );
                $plugin->setApplication(Factory::getApplication());
                $dispatcher->addListener('onBeforeCompileHead', [$plugin, 'onBeforeCompileHead']);

                return $plugin;
            }
        );
    }
};