<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\WebServices\Alfa\Extension;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRegistrar;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Access\Exception\AuthenticationFailed;
use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\ApiRouter;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;
use Throwable;

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
            'onError' => 'onError',
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
            // An order's payments and shipments read as lists of their own, the order read's values, under the order's
            // view permission.
            new Route(['GET'], 'v1/alfa/orders/:id/payments', 'orderpayments.displayList', ['id' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/orders/:id/shipments', 'ordershipments.displayList', ['id' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/orders/:id/lines', 'orderops.addLine', ['id' => '\d+'], $defaults),
            new Route(['PATCH'], 'v1/alfa/orders/:id/lines/:lineId', 'orderops.updateLine', ['id' => '\d+', 'lineId' => '\d+'], $defaults),
            new Route(['DELETE'], 'v1/alfa/orders/:id/lines/:lineId', 'orderops.removeLine', ['id' => '\d+', 'lineId' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/orders/:id/payments', 'orderops.addPayment', ['id' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/orders/:id/shipments', 'orderops.addShipment', ['id' => '\d+'], $defaults),
            new Route(['PATCH'], 'v1/alfa/orders/:id/shipments/:shipmentId', 'orderops.updateShipment', ['id' => '\d+', 'shipmentId' => '\d+'], $defaults),

            // The order screen's changes and removals, with its rules: a payment's method, reference and note; a
            // payment that moved no money removed; a parcel removed, its lines released.
            new Route(['PATCH'], 'v1/alfa/orders/:id/payments/:paymentId', 'orderops.updatePayment', ['id' => '\d+', 'paymentId' => '\d+'], $defaults),
            new Route(['DELETE'], 'v1/alfa/orders/:id/payments/:paymentId', 'orderops.removePayment', ['id' => '\d+', 'paymentId' => '\d+'], $defaults),
            new Route(['DELETE'], 'v1/alfa/orders/:id/shipments/:shipmentId', 'orderops.removeShipment', ['id' => '\d+', 'shipmentId' => '\d+'], $defaults),

            // Money verbs. A refund is never a status write: it flips the
            // original under compare-and-set, writes the linked audit row,
            // returns store credit and compensates if the row is refused.
            new Route(['POST'], 'v1/alfa/orders/:id/payments/:paymentId/refund', 'orderops.refundPayment', ['id' => '\d+', 'paymentId' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/orders/:id/payments/:paymentId/settle', 'orderops.settlePayment', ['id' => '\d+', 'paymentId' => '\d+'], $defaults),

            // Status transition. Deliberately NOT a PATCH of id_order_status:
            // the fat path always force()s, so it reinstates cancelled orders
            // with no client opt-in.
            new Route(['POST'], 'v1/alfa/orders/:id/status', 'orderops.changeStatus', ['id' => '\d+'], $defaults),

            // The prices and stock of many units in one request, each unit written and answered on its own.
            new Route(['PATCH'], 'v1/alfa/items/units', 'items.units', [], $defaults),

            // Storefront catalogue (the SITE models' view of a product): only
            // published, in-window, access-allowed items, priced and variant
            // resolved; the list leaves out a product hidden while it has no
            // salable stock. READ ONLY by design; back-office editing is v1/alfa/items.
            new Route(['GET'], 'v1/alfa/catalog/items', 'catalogitems.displayList', [], $defaults),
            new Route(['GET'], 'v1/alfa/catalog/items/:id', 'catalogitems.displayItem', ['id' => '\d+'], $defaults),

            new Route(['GET'], 'v1/alfa/inventorymovements', 'inventorymovements.displayList', [], $defaults),

            // Stock set or corrected: a command over the stock records, never a movement written by hand (the ledger
            // records what the command did). Its task is edit, the stock editors' permission; the records read only.
            new Route(['POST'], 'v1/alfa/inventory/adjustments', 'inventoryadjustments.edit', [], $defaults),
            new Route(['GET'], 'v1/alfa/inventory/stock', 'inventorystock.displayList', [], $defaults),

            // A product's combinations, each written through the one way a combination is written: the task of every
            // write is edit (the product's edit permission), of every read displayList or displayItem.
            new Route(['GET'], 'v1/alfa/items/:id/combinations', 'combinations.displayList', ['id' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/items/:id/combinations', 'combinations.edit', ['id' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/items/:id/combinations/:combinationId', 'combinations.displayItem', ['id' => '\d+', 'combinationId' => '\d+'], $defaults),
            new Route(['PATCH', 'DELETE'], 'v1/alfa/items/:id/combinations/:combinationId', 'combinations.edit', ['id' => '\d+', 'combinationId' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/combinations', 'combinations.displayList', [], $defaults),

            // An item field's values, and a product's options and specifications, each written through the one way they
            // are written: the task of every write is edit (the field's or the product's edit permission).
            new Route(['GET'], 'v1/alfa/itemfields/:id/values', 'itemfieldvalues.displayList', ['id' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/itemfields/:id/values', 'itemfieldvalues.edit', ['id' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/itemfields/:id/values/:valueId', 'itemfieldvalues.displayItem', ['id' => '\d+', 'valueId' => '\d+'], $defaults),
            new Route(['PATCH', 'DELETE'], 'v1/alfa/itemfields/:id/values/:valueId', 'itemfieldvalues.edit', ['id' => '\d+', 'valueId' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/items/:id/options', 'itemoptions.displayList', ['id' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/items/:id/options', 'itemoptions.edit', ['id' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/items/:id/options/:valueId', 'itemoptions.displayItem', ['id' => '\d+', 'valueId' => '\d+'], $defaults),
            new Route(['PATCH', 'DELETE'], 'v1/alfa/items/:id/options/:valueId', 'itemoptions.edit', ['id' => '\d+', 'valueId' => '\d+'], $defaults),
            new Route(['GET'], 'v1/alfa/items/:id/informational', 'itemoptions.displayList', ['id' => '\d+'], ['part' => 'informational'] + $defaults),
            new Route(['PUT'], 'v1/alfa/items/:id/informational', 'itemoptions.edit', ['id' => '\d+'], ['part' => 'informational'] + $defaults),

            // The light list of stock and prices: one row a product without combinations or a combination, published or
            // not, with its selling price, pages by key. READ ONLY.
            new Route(['GET'], 'v1/alfa/stockprices', 'stockprices.displayList', [], $defaults),
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

            // Tasks: what a save left to the shop's background work, and that work. A task is read by its creator or
            // with the permission of its work, and one the caller may not read answers 404, so its existence is never
            // revealed. The list is Tools' only. Process is the one route whose purpose is the long work: a 2-second
            // tick on that job, then 200, or 202 with Location and Retry-After while it still waits.
            new Route(['GET'], 'v1/alfa/tasks', 'tasks.displayList', [], $defaults),
            new Route(['GET'], 'v1/alfa/tasks/:id', 'tasks.displayItem', ['id' => '\d+'], $defaults),
            new Route(['POST'], 'v1/alfa/tasks/:id/process', 'tasks.process', ['id' => '\d+'], $defaults),
        ]);

        // The routes an alfa plugin adds, under v1/alfa/ext/<element>/, after every core route: the plugin the
        // address names declares them on a router of its own (onAlfaBeforeApiRoute) and core puts the ones that keep
        // its rules here. No core route is ever registered under v1/alfa/ext/.
        ApiRouteRegistrar::registerFor($router);
    }

    /**
     * The errors of an address an extension adds. Joomla's token sign-in throws its AuthenticationFailed without
     * a code: the JSON:API renderer maps it to 401 by its class, the plain JSON renderer answers 500, so it is given the
     * code 401 here. On a route that answers plain JSON (an integrator's dialect), every error core raises (401, 403,
     * 404, 413, 500, 503) is written in the one shape the route's own refusals and the maintenance answer use,
     * {"errors":[{"code":403,"title":"..."}]}, never Joomla's {"error":true,...} and never a trace; an error that
     * carries no HTTP code answers 500 with Joomla's general sentence, its class and message written to
     * com_alfa_api.php first (the request ends here, before Joomla's own handler would log it).
     *
     * @param ErrorEvent $event The application's error.
     *
     * @since  2.2.2
     */
    public function onError(ErrorEvent $event): void
    {
        $app = $event->getApplication();

        if (!$app instanceof ApiApplication) {
            return;
        }

        try {
            $path = trim((string) Factory::getContainer()->get(ApiRouter::class)->getRoutePath(), '/') . '/';
        } catch (Throwable) {
            return;
        }

        if (!str_starts_with($path, ApiRouteRegistrar::PREFIX)) {
            return;
        }

        $error = $event->getError();

        if ($error instanceof AuthenticationFailed && (int) $error->getCode() === 0) {
            $error = new AuthenticationFailed($error->getMessage() !== '' ? $error->getMessage() : 'Forbidden', 401, $error);
            $event->setError($error);
        }

        if ($app->getInput()->getWord('format') !== 'json') {
            return;
        }

        $code = (int) $error->getCode();
        $http = $code >= 400 && $code <= 599;

        // The request ends here, before Joomla's exception handler would log it: an error ExtController did not log
        // itself (no HTTP code, or a 5xx raised outside the extension's handler) is logged now, its class and message.
        if (!$http || ($code >= 500 && !$error instanceof Save)) {
            AlfaLog::register();
            Log::add('An extension\'s address (' . rtrim($path, '/') . ') failed outside its handler: ' . $error::class . ': ' . ErrorText::of($error), Log::ERROR, ApiRouteRegistrar::LOG);
        }

        [$code, $title] = $http ? [$code, ErrorText::of($error)] : [500, Text::_('JERROR_AN_ERROR_HAS_OCCURRED')];
        $body = (string) json_encode(['errors' => [['code' => $code, 'title' => $title]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $app->setHeader('status', (string) $code, true);
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Content-Length', (string) \strlen($body), true);
        $app->sendHeaders();

        echo $body;

        $app->close();
    }
}
