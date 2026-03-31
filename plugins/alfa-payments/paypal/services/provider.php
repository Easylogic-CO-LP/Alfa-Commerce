<?php

	/**
	 * @package    Alfa Commerce
	 * @author     Agamemnon Fakas <info@easylogic.gr>
	 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
	 * @license    GNU General Public License version 3 or later; see LICENSE
	 */

	\defined('_JEXEC') or die;

	use Joomla\CMS\Extension\PluginInterface;
	use Joomla\CMS\Factory;
	use Joomla\CMS\Plugin\PluginHelper;
	use Joomla\DI\Container;
	use Joomla\DI\ServiceProviderInterface;

	// Φορτώνουμε το σωστό namespace
	use Alfa\Plugin\AlfaPayments\Paypal\Extension\Paypal;

	// Φορτώνουμε το Autoloader του Composer για το PayPal SDK
	require_once \dirname(__DIR__) . '/libraries/vendor/autoload.php';

	return new class () implements ServiceProviderInterface {
		public function register(Container $container)
		{
			$container->set(
				PluginInterface::class,
				function (Container $container) {
					$plugin = new Paypal(
						(array) PluginHelper::getPlugin('alfa-payments', 'paypal')
					);

					$plugin->setApplication(Factory::getApplication());

					return $plugin;
				}
			);
		}
	};