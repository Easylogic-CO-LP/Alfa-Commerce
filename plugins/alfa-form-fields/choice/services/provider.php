<?php

defined('_JEXEC') or die;

use Alfa\Plugin\AlfaFormFields\Choice\Extension\Choice;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

// Events are auto-wired from Choice::getSubscribedEvents() via SubscriberInterface
// (declared on FieldsPlugin). Do NOT call $dispatcher->addListener() here — that
// pattern is deprecated in Joomla 7.

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Choice(
                    (array) PluginHelper::getPlugin('alfa-form-fields', 'choice'),
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            },
        );
    }
};
