<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright   (C) 2019 Open Source Matters, Inc. <https://www.joomla.org>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\WebServices\Alfa\Extension;

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Web Services adapter for com_contact.
 *
 * @since  4.0.0
 */
final class Alfa extends CMSPlugin implements SubscriberInterface
{
    /**
     * Returns an array of events this subscriber will listen to.
     *
     *
     * @since   5.1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeApiRoute' => 'onBeforeApiRoute',
        ];
    }

    public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
    {
        $router = $event->getRouter();

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
        $router->createCRUDRoutes('v1/alfa/orderstatuses', 'orderstatuses', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/taxes', 'taxes', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/discounts', 'discounts', ['component' => 'com_alfa']);
    }
}
