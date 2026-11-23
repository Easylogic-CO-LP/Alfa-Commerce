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
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceMap;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces;
use Alfa\Component\Alfa\Administrator\Event\Payments\ItemViewEvent as PaymentsItemViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\ItemViewEvent as ShipmentsItemViewEvent;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\PageCache;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\Helper\RecentlyViewed;
use Alfa\Component\Alfa\Site\Helper\SlotResolver;
use Alfa\Component\Alfa\Site\Service\Catalog\RelatedProducts;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Exception;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Throwable;

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

    /**
     * @var int How many columns the images grid (default_images-grid.php) has on a wide screen, 2 to 4: the
     *          item's, else its default category's, else the menu item's or the Options'
     */
    protected int $imagesGridColumns = 2;

    /** @var string Resolved sub-template for the "manufacturers" slot */
    protected $manufacturersTpl = 'manufacturers';

    /** @var string Resolved sub-template for the "categories" slot */
    protected $categoriesTpl = 'categories';

    /** @var string Resolved sub-template for the "related" slot: the product's related products */
    protected string $relatedTpl = 'related';

    /**
     * @var array<int, string> The related products' cards, the listing's own, in the order staff set and by
     *                         product id: only the products the visitor may see, at most the count; empty for none
     */
    protected array $relatedCards = [];

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

        // A PRODUCT PAGE OPENED TO CHANGE A CART LINE (edit_line, linked from every line of the cart) draws that line's
        // choices and the visitor's Update form at an address every visitor can ask: never stored in Joomla's page cache,
        // whatever plugin is enabled. Any edit_line, the visitor's own or not: a plain copy stored at that
        // address would be served to the line's owner, before this view runs, in place of the edit form.
        if (RequestScalar::int($input, 'edit_line', 0) > 0) {
            PageCache::excludeResponse();
        }

        $this->params = $app->getParams('com_alfa');

        $this->category_id = RequestScalar::int($input, 'category_id', 0);

        $this->state = $model->getState();

        $this->item = $model->getItem();

        // A document request is a product view; the buy-form reload (tmpl=component)
        // re-renders the same page and must not count again.
        if (RequestScalar::cmd($input, 'tmpl', '') === '' && !empty($this->item->id)) {
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
        // The grid's columns, through the same chain; the grid draws them as a class, 2 to 4.
        $this->imagesGridColumns = min(4, max(2, (int) (($own['images_grid_columns'] ?? '') ?: ($cat['images_grid_columns'] ?? '') ?: $this->params->get('item_images_grid_columns', 2))));
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

        // THE RELATED PRODUCTS: the part and how many it shows, through the page's chain (the product, its
        // default category, the menu item, the Options; 0 shows none). Not on the buy-form reload (tmpl=component):
        // the part sits after the product's container, which is all a variant change replaces.
        $this->relatedTpl = SlotResolver::resolve(
            value: ($own['related'] ?? '') ?: ($cat['related'] ?? '') ?: (string) $this->params->get('item_related_layout', ''),
            view: 'item',
            slot: 'related',
            layout: $this->getLayout(),
        );
        $relatedCount = self::firstSet([$own['related_count'] ?? '', $cat['related_count'] ?? '', $this->params->get('item_related_count', 4)]);

        if ($relatedCount > 0 && RequestScalar::cmd($input, 'tmpl', '') === '' && !empty($this->item->id)) {
            $this->relatedCards = $this->relatedCards(itemId: (int) $this->item->id, count: $relatedCount);
        }

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
        // Collected here and handed to the item as a read-only map after each method,
        // as the model hands the fields' surfaces.
        $slots = (array) ($this->item->surfaces ?? []);

        // Each method is drawn by core first, in the order every method keeps: its image when the shop set
        // one, its title, its description (the layout methods.item), then what its plugin adds. The images of the
        // methods shown are read in one call per kind.
        $images = [
            'payment' => $this->methodImages(kind: 'payment', methods: (array) $this->item->payment_methods),
            'shipment' => $this->methodImages(kind: 'shipment', methods: (array) $this->item->shipment_methods),
        ];

        foreach ($this->item->payment_methods as &$payment_method) {
            if (!$payment_method->show_on_product) {
                unset($this->item->payment_methods[$payment_method->id]);
                continue;
            }

            $slots[ItemSurface::PaymentMethods->value][] = $this->methodDescriptor(method: $payment_method, image: $images['payment'][(int) $payment_method->id] ?? null);

            $paymentEvent = new PaymentsItemViewEvent($onItemViewEventName, [
                'subject' => $this->item,
                'method' => $payment_method,
            ]);

            // A configured method whose plugin is unavailable, or that fails, must not fatal the whole product page.
            $this->askMethodPlugin(group: 'alfa-payments', type: (string) $payment_method->type, event: $paymentEvent);

            if ($paymentEvent->hasRedirect()) {
                $app->redirect(
                    $paymentEvent->getRedirectUrl(),
                    $paymentEvent->getRedirectCode() ?? 303,
                );

                return;
            }

            $this->appendMethodSurfaces(slots: $slots, event: $paymentEvent, group: 'alfa-payments', type: (string) $payment_method->type);
            $this->item->surfaces = new SurfaceMap($slots);
        }

        foreach ($this->item->shipment_methods as &$shipment_method) {
            if (!$shipment_method->show_on_product) {
                unset($this->item->shipment_methods[$shipment_method->id]);
                continue;
            }

            $slots[ItemSurface::ShipmentMethods->value][] = $this->methodDescriptor(method: $shipment_method, image: $images['shipment'][(int) $shipment_method->id] ?? null);

            $shipment_event = new ShipmentsItemViewEvent($onItemViewEventName, [
                'subject' => $this->item,
                'method' => $shipment_method,
            ]);

            $this->askMethodPlugin(group: 'alfa-shipments', type: (string) $shipment_method->type, event: $shipment_event);

            if ($shipment_event->hasRedirect()) {
                $app->redirect(
                    $shipment_event->getRedirectUrl(),
                    $shipment_event->getRedirectCode() ?? 303,
                );

                return;
            }

            $this->appendMethodSurfaces(slots: $slots, event: $shipment_event, group: 'alfa-shipments', type: (string) $shipment_method->type);
            $this->item->surfaces = new SurfaceMap($slots);
        }

        // ITEM FIELDS selectors + cart slot are harvested by the model
        // (ItemModel::dispatchFieldSurfaces, gated by the 'plugin.layouts' state),
        // so $this->item->fields[*]->view + ->cart / ->cartChildren are ready.

        $this->declareGalleryAssets();

        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * The first value of a chain that is set ('' and null inherit; 0 is a value), as a whole number.
     *
     * @param list<mixed> $chain The levels, strongest first.
     *
     * @since  2.3.0
     */
    private static function firstSet(array $chain): int
    {
        foreach ($chain as $value) {
            if ($value !== null && $value !== '') {
                return max(0, (int) $value);
            }
        }

        return 0;
    }

    /**
     * A product's related products as the listing's cards: the products the visitor may see
     * ({@see RelatedProducts::visible()}), drawn by the listing's own view
     * ({@see \Alfa\Component\Alfa\Site\View\Items\HtmlView::cardsFor()}).
     *
     * @param int $itemId The product.
     * @param int $count How many to show, at least 1.
     *
     * @return array<int, string> The cards by product id, in their order.
     *
     * @since  2.3.0
     */
    private function relatedCards(int $itemId, int $count): array
    {
        $shown = RelatedProducts::visible(itemId: $itemId, count: $count);

        if ($shown === []) {
            return [];
        }

        /** @var \Alfa\Component\Alfa\Site\View\Items\HtmlView $listing */
        $listing = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createView('Items', 'Site', 'Html', ['base_path' => JPATH_SITE . '/components/com_alfa']);
        $listing->setDocument($this->getDocument());

        return $listing->cardsFor(items: $shown);
    }

    /**
     * The images of the methods shown on the product page, by method id: the first media row of each, read in one call.
     *
     * @param string $kind 'payment' or 'shipment'.
     * @param array<object> $methods The methods of that kind.
     *
     * @return array<int, object>
     *
     * @since  2.3.0
     */
    private function methodImages(string $kind, array $methods): array
    {
        $ids = [];

        foreach ($methods as $method) {
            if (!empty($method->show_on_product)) {
                $ids[] = (int) $method->id;
            }
        }

        $images = [];

        foreach ($ids === [] ? [] : (array) MediaHelper::getMediaData(origin: $kind, itemIDs: $ids) as $id => $rows) {
            if (\is_array($rows) && isset($rows[0]) && \is_object($rows[0])) {
                $images[(int) $id] = $rows[0];
            }
        }

        return $images;
    }

    /**
     * A method plugin's parts for the product page, added to the page's slots: each part in the place the plugin
     * asked its event for. When the shop sells nothing online (catalogue-only mode) none of them lands in the buy
     * area: there is no add to cart for them to stand beside, and the model has left that area to plain read-only
     * lists of the product's fields.
     *
     * @param array $slots The page's slots, added to.
     * @param object $event The method's onItemView event, asked.
     * @param string $group The plugin's group.
     * @param string $type The method's plugin.
     *
     * @since  2.3.0
     */
    private function appendMethodSurfaces(array &$slots, object $event, string $group, string $type): void
    {
        $own = [];
        Surfaces::appendTo(target: $own, event: $event, ownerGroup: $group, ownerName: $type);
        $buyArea = [ItemSurface::BuyForm->value, ItemSurface::NearAddToCart->value];

        foreach ($own as $slot => $descriptors) {
            if (!($this->item->sellsOnline ?? true) && \in_array($slot, $buyArea, true)) {
                continue;
            }

            foreach ($descriptors as $descriptor) {
                $slots[$slot][] = $descriptor;
            }
        }
    }

    /**
     * Core's own entry for one method in its slot of the product page: the layout methods.item, drawn before
     * the method's plugin adds anything.
     *
     * @param object $method The method.
     * @param object|null $image Its image, or null.
     *
     * @return array The surface descriptor.
     *
     * @since  2.3.0
     */
    private function methodDescriptor(object $method, ?object $image): array
    {
        return [
            'layout' => 'methods.item',
            'layoutData' => ['method' => $method, 'image' => $image],
            'layoutPluginType' => '',
            'layoutPluginName' => '',
            'showon' => '',
            'fieldId' => null,
            'fieldName' => null,
        ];
    }

    /**
     * Ask one method's plugin for its product page surface (targeted): booted through the plugin helper, its
     * ambient subscriptions engaged through Joomla's own import, the event delivered to its declared handler. A plugin
     * unavailable, without the handler or failing contributes nothing (logged) and the page renders.
     *
     * @param string $group The plugin group.
     * @param string $type The method's plugin element.
     * @param PaymentsItemViewEvent|ShipmentsItemViewEvent $event The event.
     *
     * @since  2.2.0
     */
    private function askMethodPlugin(string $group, string $type, PaymentsItemViewEvent|ShipmentsItemViewEvent $event): void
    {
        $plugins = AlfaPluginHelper::get();

        try {
            $id = PluginId::from($group, $type);
            $plugin = $plugins->bootSingle($id);
        } catch (PluginUnavailable|InvalidArgumentException) {
            return;
        }

        $plugins->importSingle($id);

        if (!$plugins->declares($plugin, $event->getName())) {
            return;
        }

        try {
            $plugins->dispatchTo($plugin, $event);
        } catch (Throwable $e) {
            Log::add('Product page: ' . $id . ' failed in onItemView (' . $e::class . ': ' . ErrorText::of($e) . '); the page renders without it.', Log::ERROR, 'com_alfa');
        }
    }

    /**
     * THE GALLERIES THE PAGE CAN SHOW. A variant can swap its own gallery in
     * (VariantHelper::overlay()), and variant.js re-initialises the swapped
     * container without loading any asset, so the gallery assets are decided
     * once for every gallery the page can show: the item's own and each
     * published combination's own, the combinations' counted in one grouped
     * read. Keen Slider when one of them has two or more images (a thumbnail
     * strip), GLightbox when one has at least one; a page whose galleries all
     * hold one image loads no slider, and a page with no image none of them.
     * Declared here, not in a layout, so every item layout and its overrides
     * get them.
     *
     * @since  2.2.0
     */
    private function declareGalleryAssets(): void
    {
        $counts = [\count(MediaHelper::getMediaData(origin: 'item', itemIDs: (int) $this->item->id))];
        $combinationIds = array_map('intval', array_keys((array) ($this->item->combinations ?? [])));

        if ($combinationIds !== []) {
            foreach (MediaHelper::getMediaData(origin: 'combination', itemIDs: $combinationIds) as $media) {
                $counts[] = \count((array) $media);
            }
        }

        $wa = $this->getDocument()->getWebAssetManager();

        if (max($counts) >= 1) {
            $wa->useStyle('com_alfa.glightbox')->useScript('com_alfa.glightbox');
        }

        if (max($counts) >= 2) {
            $wa->useStyle('com_alfa.keen-slider')->useScript('com_alfa.keen-slider');
        }
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
