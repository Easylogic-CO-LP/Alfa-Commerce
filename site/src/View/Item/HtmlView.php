<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Item;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces;
use Alfa\Component\Alfa\Administrator\Event\Payments\ItemViewEvent as PaymentsItemViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\ItemViewEvent as ShipmentsItemViewEvent;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\Helper\RecentlyViewed;
use Alfa\Component\Alfa\Site\Helper\SlotResolver;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * View class for a list of Alfa.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    protected $state;

    protected $item;

    protected $form;

    protected $params;

    protected $payment_methods;

    protected $category_id;

    /** @var string Resolved sub-template for the "images" slot */
    protected $imagesTpl = 'images';

    /** @var string Resolved sub-template for the "manufacturers" slot */
    protected $manufacturersTpl = 'manufacturers';

    /** @var string Resolved sub-template for the "categories" slot */
    protected $categoriesTpl = 'categories';

    /**
     * Display the view
     *
     * @param string $tpl Template name
     *
     * @return void
     *
     * @throws Exception
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $user = $app->getIdentity();
        $model = $this->getModel();

        $this->params = $app->getParams('com_alfa');

        $this->category_id = $input->getInt('category_id', 0);

        $this->state = $model->getState();

        $this->item = $model->getItem();

        // A document request is a product view; the buy-form reload (tmpl=component)
        // re-renders the same page and must not count again.
        if ($input->getCmd('tmpl', '') === '' && !empty($this->item->id)) {
            RecentlyViewed::record(itemId: (int) $this->item->id);
        }

        // LOCKED SPEC: the product page is entity-centric; its look NEVER depends
        // on the navigation path (same URL, same rendering, cacheable). Chain on
        // already-loaded data: own values -> DEFAULT category -> menu/global
        // params. An explicit menu/request layout always wins the main slot.
        $own = $this->item->layouts ?? [];
        $cat = $this->item->categories[(int) ($this->item->id_category_default ?? 0)]['layouts'] ?? [];

        if (
            $this->getLayout() === 'default'
            && ($layout = ($own['layout'] ?? '') ?: ($cat['item'] ?? '') ?: $this->params->get('item_layout'))
        ) {
            $this->setLayout($layout);
        }

        $this->imagesTpl = SlotResolver::resolve(
            value: ($own['images'] ?? '') ?: ($cat['images'] ?? '') ?: (string) $this->params->get('item_images_layout', ''),
            view: 'item',
            slot: 'images',
            layout: $this->getLayout(),
        );
        $this->manufacturersTpl = SlotResolver::resolve(
            value: ($own['manufacturers'] ?? '') ?: ($cat['manufacturers'] ?? '') ?: (string) $this->params->get('item_manufacturers_layout', ''),
            view: 'item',
            slot: 'manufacturers',
            layout: $this->getLayout(),
        );
        $this->categoriesTpl = SlotResolver::resolve(
            value: ($own['categories'] ?? '') ?: ($cat['categories'] ?? '') ?: (string) $this->params->get('item_categories_layout', ''),
            view: 'item',
            slot: 'categories',
            layout: $this->getLayout(),
        );

        // Resolve price settings once for all items
        $this->priceSettings = PriceSettings::get();

        /*
         *  Setting up alfa-payments onProductView event call to be used on tmpl.
         */
        $onItemViewEventName = 'onItemView';

        // The product page is the one place where payment/shipment plugins may run
        // (their hooks can issue a redirect, so they stay in the view layer). What
        // they produce is aggregated into the SAME $item->surfaces container as the
        // item fields: one source-agnostic render model, insertion order preserved.
        $this->item->surfaces ??= [];

        foreach ($this->item->payment_methods as &$payment_method) {
            if (!$payment_method->show_on_product) {
                unset($this->item->payment_methods[$payment_method->id]);
                continue;
            }

            $paymentEvent = new PaymentsItemViewEvent($onItemViewEventName, [
                'subject' => $this->item,
                'method' => $payment_method,
            ]);

            // A configured method whose plugin is uninstalled must not fatal the
            // whole product page; dispatch only when the plugin and hook exist.
            $paymentPlugin = $app->bootPlugin($payment_method->type, 'alfa-payments');

            if ($paymentPlugin !== null && method_exists($paymentPlugin, $onItemViewEventName)) {
                $paymentPlugin->{$onItemViewEventName}($paymentEvent);
            }

            if ($paymentEvent->hasRedirect()) {
                $app->redirect(
                    $paymentEvent->getRedirectUrl(),
                    $paymentEvent->getRedirectCode() ?? 303,
                );

                return;
            }

            Surfaces::appendTo(
                target: $this->item->surfaces,
                event: $paymentEvent,
                ownerGroup: 'alfa-payments',
                ownerName: $payment_method->type,
                defaultSlot: ItemSurface::PaymentMethods->value,
            );
        }

        foreach ($this->item->shipment_methods as &$shipment_method) {
            if (!$shipment_method->show_on_product) {
                unset($this->item->shipment_methods[$shipment_method->id]);
                continue;
            }

            $shipment_event = new ShipmentsItemViewEvent($onItemViewEventName, [
                'subject' => $this->item,
                'method' => $shipment_method,
            ]);

            $shipmentPlugin = $app->bootPlugin($shipment_method->type, 'alfa-shipments');

            if ($shipmentPlugin !== null && method_exists($shipmentPlugin, $onItemViewEventName)) {
                $shipmentPlugin->{$onItemViewEventName}($shipment_event);
            }

            if ($shipment_event->hasRedirect()) {
                $app->redirect(
                    $shipment_event->getRedirectUrl(),
                    $shipment_event->getRedirectCode() ?? 303,
                );

                return;
            }

            Surfaces::appendTo(
                target: $this->item->surfaces,
                event: $shipment_event,
                ownerGroup: 'alfa-shipments',
                ownerName: $shipment_method->type,
                defaultSlot: ItemSurface::ShipmentMethods->value,
            );
        }

        // ITEM FIELDS selectors + cart slot are harvested by the model
        // (ItemModel::dispatchFieldSurfaces, gated by the 'plugin.layouts' state),
        // so $this->item->fields[*]->view + ->cart / ->cartChildren are ready.

        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * Prepares the document
     *
     * @return void
     *
     * @throws Exception
     * @since  1.0.0
     */
    protected function _prepareDocument()
    {
        $app = Factory::getApplication();

        // Add Breadcrumbs
        $pathway = $app->getPathway();

        // Use your helper to get the category path
        $categoryPath = CategoryHelper::getCategoryPath($this->category_id ?: $this->item->id_category_default);

        foreach ($categoryPath as $category) {
            $link = Route::_('index.php?option=com_alfa&view=items&category_id=' . $category['id']);
            if (!in_array($category['name'], $pathway->getPathwayNames())) {
                $pathway->addItem($category['name'], $link);
            }
        }

        $breadcrumbTitle = $this->item->name;

        if (!in_array($breadcrumbTitle, $pathway->getPathwayNames())) {
            $pathway->addItem($breadcrumbTitle);
        }

        // META TAGS
        // Meta Title
        $metaTitle = $this->params->get('page_title', '');

        if (!empty($this->item->meta_title)) {
            $metaTitle = $this->item->meta_title;
        } elseif (!empty($this->item->name)) {
            $metaTitle = $this->item->name;
        }

        $siteNameOnpageTitle = $app->get('sitename_pagetitles', 0);
        $siteName = $app->get('sitename');
        if (!empty($siteNameOnpageTitle)) {
            if ($siteNameOnpageTitle == 1) { // Before
                $metaTitle = $siteName . ' - ' . $metaTitle;
            } else { // After
                $metaTitle = $metaTitle . ' - ' . $siteName;
            }
        }

        // Meta Description
        $metaDescription = $this->params->get('menu-meta_description', '');

        if (!empty($this->item->meta_desc)) {
            $metaDescription = $this->item->meta_desc;
        } elseif (!empty($this->item->short_desc)) {
            $metaDescription = AlfaHelper::cleanContent(
                html:$this->item->short_desc,
                removeTags: true,
                removeScripts: true,
                removeIsolatedPunctuation: false,
            );
        }

        $otherMetaData = [];

        if (!empty($this->item->meta_data)) {
            $otherMetaData = json_decode($this->item->meta_data, true) ?: [];
        }

        if ($this->params->get('robots')) {
            $metaRobots = $this->params->get('robots');
        }

        if (!empty($otherMetaData['robots'])) {
            $metaRobots = $otherMetaData['robots'];
        }

        // Set all tags
        $this->getDocument()->setTitle($metaTitle);
        $this->getDocument()->setDescription($metaDescription);

        if (!empty($metaRobots)) {
            $this->getDocument()->setMetadata('robots', $metaRobots);
        }

        // Canonical: the router resolves this product under ANY category path, so
        // every path variant points at the single URL built from its default
        // category, preventing duplicate-content across those variants.
        $canonicalRoute = Route::_(
            'index.php?option=com_alfa&view=item&id=' . (int) $this->item->id
                . '&category_id=' . (int) ($this->item->id_category_default ?? 0),
            false,
        );

        // NOT addHeadLink(): head links are keyed by HREF and the languagefilter's
        // SELF hreflang alternate carries the identical href; last write wins and
        // the canonical vanishes. A custom tag coexists with the alternate.
        $this->getDocument()->addCustomTag(
            '<link href="' . htmlspecialchars(Uri::getInstance()->toString(['scheme', 'host', 'port']) . $canonicalRoute) . '" rel="canonical">',
        );
    }
}
