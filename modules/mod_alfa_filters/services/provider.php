<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_mymodule
 *
 * @copyright   Copyright (C) 2025 Your Name. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\Service\Provider\HelperFactory;
use Joomla\CMS\Extension\Service\Provider\Module;
use Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param Container $container The DI container.
     *
     * @return void
     */
    public function register(Container $container)
    {
        $container->registerServiceProvider(new ModuleDispatcherFactory('\\Alfa\\Module\\AlfaFilters'));
        $container->registerServiceProvider(new HelperFactory('\\Alfa\\Module\\AlfaFilters\\Site\\Helper'));
        $container->registerServiceProvider(new Module());
    }
};
