<?php
    defined('_JEXEC') or die;

    use Joomla\CMS\Extension\PluginInterface;
    use Joomla\CMS\Plugin\PluginHelper;
    use Joomla\DI\Container;
    use Joomla\DI\ServiceProviderInterface;
    use Joomla\Event\DispatcherInterface;
    use Joomla\Plugin\System\GeoLoader\Extension\GeoLoader;

    return new class () implements ServiceProviderInterface {
        public function register(Container $container)
        {
            $container->set(
                PluginInterface::class,
                function (Container $container) {
                    // Fetch required Joomla system core dependencies
                    $dispatcher = $container->get(DispatcherInterface::class);
                    $plugin     = PluginHelper::getPlugin('system', 'geoloader');

                    // Instantiate the main extension class and pass dependencies
                    $extension = new GeoLoader($dispatcher, (array) $plugin);
                    $extension->setApplication($container->get(\Joomla\CMS\Application\CMSApplication::class));

                    return $extension;
                }
            );
        }
    };