<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\WebServices\Alfa\Extension;

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Web Services adapter for com_alfa.
 *
 * @since  1.0.0
 */
final class Alfa extends CMSPlugin implements SubscriberInterface
{
    /**
     * Returns an array of events this subscriber will listen to.
     *
     *
     * @since  1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeApiRoute' => 'onBeforeApiRoute',
        ];
    }

    /**
     * Registers the com_alfa API routes.
     *
     * Retired routes (models superseded, endpoints returned 500 since the
     * entity refactors): itemsprices (prices are part of the items payload),
     * customs (superseded by itemfields), coupons (superseded by cartrules),
     * settings (component params; use core v1/config/com_alfa).
     *
     * @param BeforeApiRouteEvent $event The route registration event.
     *
     *
     * @since  1.0.0
     */
    public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
    {
        $router = $event->getRouter();

        // Catalog
        $router->createCRUDRoutes('v1/alfa/items', 'items', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/categories', 'categories', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/manufacturers', 'manufacturers', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/itemfields', 'itemfields', ['component' => 'com_alfa']);

        // Customers
        $router->createCRUDRoutes('v1/alfa/users', 'users', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/usergroups', 'usergroups', ['component' => 'com_alfa']);

        // Commerce configuration. A payment or shipment METHOD is a
        // configuration object, which is why it is named as one: the money
        // and parcel rows an order books live under v1/alfa/orders/:id.
        $router->createCRUDRoutes('v1/alfa/currencies', 'currencies', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/taxes', 'taxes', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/discounts', 'discounts', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/cartrules', 'cartrules', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/places', 'places', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/shipmentmethods', 'shipmentmethods', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/paymentmethods', 'paymentmethods', ['component' => 'com_alfa']);

        // Checkout / registration form definitions
        $router->createCRUDRoutes('v1/alfa/formfields', 'formfields', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/formfieldgroups', 'formfieldgroups', ['component' => 'com_alfa']);

        // Orders
        $router->createCRUDRoutes('v1/alfa/orders', 'orders', ['component' => 'com_alfa']);
        $router->createCRUDRoutes('v1/alfa/orderstatuses', 'orderstatuses', ['component' => 'com_alfa']);

        // Inventory
        $router->createCRUDRoutes('v1/alfa/locations', 'locations', ['component' => 'com_alfa']);

        $defaults = ['component' => 'com_alfa', 'public' => false];

        // Read-only routes. Movements are an append-only ledger (corrections
        // are opposite entries, never edits) and documents are legally
        // immutable once issued; neither may ever gain write routes. A
        // movement has no singular model, so it is a list and nothing else;
        // a document reads as an item too, and voiding one is the only
        // correction it accepts. Document types are a semantics registry:
        // readable list + item, no writes.
        $router->addRoutes([
            // Order operations: verbs on sub-resources, never a fat PATCH of
            // the order. Each one delegates to the service that owns the
            // invariant (stock reconcile, the payment ledger's row lock and
            // money caps, the shipment ledger's parcel quantities) and answers
            // with the fully updated order.
            // Rules carry NO outer parentheses. Route::buildRegexAndVarList()
            // wraps each rule in its own capture group, so '(\d+)' compiles to
            // '((\d+))' (two groups for one variable) and Router's positional
            // read ($matches[$i + 1]) then hands the SECOND variable the first
            // one's value. With '(\d+)' on both, /orders/5/lines/7 resolved
            // lineId to 5 and silently edited the wrong line.
            new Route(['POST'], 'v1/alfa/orders/:id/lines', 'orderops.addLine', ['id' => '\d+'], $defaults),
            new Route(['PATCH'], 'v1/alfa/orders/:id/lines/:lineId', 'orderops.updateLine', ['id' => '\d+', 'lineId' => '\d+'], $defaults),
            new Route(['DELETE'], 'v1/alfa/orders/:id/lines/:lineId', 'orderops.removeLine', ['id' => '\d+', 'lineId' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/orders/:id/payments', 'orderops.addPayment', ['id' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/orders/:id/shipments', 'orderops.addShipment', ['id' => '\d+'], $defaults),
            new Route(['PATCH'], 'v1/alfa/orders/:id/shipments/:shipmentId', 'orderops.updateShipment', ['id' => '\d+', 'shipmentId' => '\d+'], $defaults),

            // Money verbs. A refund is never a status write: it flips the
            // original under compare-and-set, writes the linked audit row,
            // returns store credit and compensates if the row is refused.
            new Route(['POST'], 'v1/alfa/orders/:id/payments/:paymentId/refund', 'orderops.refundPayment', ['id' => '\d+', 'paymentId' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/orders/:id/payments/:paymentId/settle', 'orderops.settlePayment', ['id' => '\d+', 'paymentId' => '\d+'], $defaults),

            // Status transition. Deliberately NOT a PATCH of id_order_status:
            // the fat path always force()s, so it reinstates cancelled orders
            // with no client opt-in.
            new Route(['POST'], 'v1/alfa/orders/:id/status', 'orderops.changeStatus', ['id' => '\d+'], $defaults),

            // Storefront catalogue (the SITE models' view of a product): only
            // published, in-window, access-allowed items, priced and variant
            // resolved. READ ONLY by design; back-office editing is v1/alfa/items.
            new Route(['GET'], 'v1/alfa/catalog/items', 'catalogitems.displayList', [], $defaults),
            new Route(['GET'], 'v1/alfa/catalog/items/:id', 'catalogitems.displayItem', ['id' => '\d+'], $defaults),

            new Route(['GET'], 'v1/alfa/inventorymovements', 'inventorymovements.displayList', [], $defaults),
            new Route(['GET'], 'v1/alfa/documents', 'documents.displayList', [], $defaults),
            new Route(['GET'], 'v1/alfa/documents/:id', 'documents.displayItem', ['id' => '\d+'], $defaults),

            // Rendered output: the thing the customer actually receives.
            new Route(['GET'], 'v1/alfa/documents/:id/pdf', 'documents.pdf', ['id' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/documents/:id/html', 'documents.html', ['id' => '\d+'], $defaults),

            // The ONLY legitimate correction to an immutable document. Not a
            // DELETE: the row and its drawn number both stay.
            new Route(['POST'], 'v1/alfa/documents/:id/void', 'documents.void', ['id' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/documenttypes', 'documenttypes.displayList', [], $defaults),
            new Route(['GET'], 'v1/alfa/documenttypes/:id', 'documenttypes.displayItem', ['id' => '\d+'], $defaults),
        ]);
    }
}
