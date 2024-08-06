<?php
/**
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\ApiRouter;

/**
 * Web Services adapter for alfa.
 *
 * @since  1.0.1
 */
class PlgWebservicesAlfa extends CMSPlugin
{
	public function onBeforeApiRoute(&$router)
	{
		
		$router->createCRUDRoutes('v1/alfa/manufacturers', 'manufacturers', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/categories', 'categories', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/items', 'items', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/itemsprices', 'itemsprices', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/users', 'users', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/usergroups', 'usergroups', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/customs', 'customs', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/currencies', 'currencies', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/coupons', 'coupons', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/shipments', 'shipments', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/payments', 'payments', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/places', 'places', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/settings', 'settings', ['component' => 'com_alfa']);
		$router->createCRUDRoutes('v1/alfa/orders', 'orders', ['component' => 'com_alfa']);
	}
}
