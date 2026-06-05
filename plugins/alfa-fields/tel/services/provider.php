<?php

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\AlfaFields\Tel\Extension\Tel;

// Events are auto-wired from Tel::getSubscribedEvents() (inherited empty default
// from FieldsPlugin via SubscriberInterface). Do NOT pass a DispatcherInterface
// to the constructor — that pattern is E_USER_DEPRECATED in Joomla 5+ and removed
// in Joomla 7. See libraries/src/Plugin/CMSPlugin.php:133.

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Tel(
                    (array) PluginHelper::getPlugin('alfa-fields', 'tel'),
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            },
        );
    }
};
