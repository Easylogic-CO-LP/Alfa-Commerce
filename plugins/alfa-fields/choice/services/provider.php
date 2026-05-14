<?php

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\AlfaFields\Choice\Extension\Choice;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin = new Choice(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('alfa-fields', 'choice'),
                );
                $plugin->setApplication(Factory::getApplication());
                $dispatcher->addListener('onBeforeCompileHead', [$plugin, 'onBeforeCompileHead']);

                return $plugin;
            },
        );
    }
};
