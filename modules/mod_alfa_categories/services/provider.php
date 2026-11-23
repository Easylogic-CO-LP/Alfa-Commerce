<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\Service\Provider\HelperFactory;
use Joomla\CMS\Extension\Service\Provider\Module;
use Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * The categories module's service provider.
 *
 * @since  2.3.0
 */
return new class () implements ServiceProviderInterface {
    /**
     * Registers the module's dispatcher and helper factories.
     *
     * @param Container $container The DI container.
     *
     * @since  2.3.0
     */
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new ModuleDispatcherFactory('\\Alfa\\Module\\AlfaCategories'));
        $container->registerServiceProvider(new HelperFactory('\\Alfa\\Module\\AlfaCategories\\Site\\Helper'));
        $container->registerServiceProvider(new Module());
    }
};
