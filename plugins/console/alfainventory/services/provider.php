<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Plugin\Console\Alfainventory\Extension\Alfainventory;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param Container $container The DI container.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function register(Container $container)
    {
        $container->set(
            key: PluginInterface::class,
            value: function (Container $container) {
                $plugin = new Alfainventory(config: (array) PluginHelper::getPlugin(type: 'console', plugin: 'alfainventory'));
                $plugin->setApplication(application: Factory::getApplication());
                $plugin->setDatabase(db: $container->get(resourceName: DatabaseInterface::class));

                return $plugin;
            },
        );
    }
};
