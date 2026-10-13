<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemViewEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces;
use Alfa\Component\Alfa\Administrator\Event\Items\ItemDisplayEvent;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Plugin\ItemFieldsPlugin;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Alfa\Component\Alfa\Site\Helper\SaleTag;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Alfa\Component\Alfa\Site\Service\FieldState\StateBagBuilder;
use Alfa\Component\Alfa\Site\Service\Pricing;
use Alfa\Component\Alfa\Site\Service\ShowOn\InitialState;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\ItemModel as BaseItemModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Alfa model.
 *
 * @since  1.0.0
 */
class ItemModel extends BaseItemModel
{
    /**
     * Model context string.
     *
     * @var string
     */
    protected $_context = 'com_alfa.item';

    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     *
     * @throws Exception
     * @since  1.0.0
     */
    protected function populateState(): void
    {
        $app = Factory::getApplication();

        // Get the item ID from the request
        $id = $app->input->getInt('id', 0);
        $this->setState('item.id', $id);
    }

    /**
     * Method to get an object.
     *
     * @param int|null $pk The id of the object to get.
     *
     * @return mixed Object on success, false on failure.
     *
     * @throws Exception
     * @since  1.0.0
     */
    public function getItem($pk = null): mixed
    {
        $user = $this->getCurrentUser();
        $pk = (int) ($pk ?: $this->getState('item.id'));

        if ($this->_item === null) {
            $this->_item = [];
        }

        if (!isset($this->_item[$pk])) {
            $db = $this->getDatabase();
            $query = $db->getQuery(true);

            $query->select(
                $this->getState(
                    'item.select',
                    ['a.*'],
                ),
            )
                ->from($db->quoteName('#__alfa_items', 'a'))
                ->where($db->quoteName('a.id') . ' = :pk')
                ->bind(':pk', $pk, ParameterType::INTEGER);

            // A restricted product must be PRIVATE, not merely unlisted: without this
            // its own URL still served it to anyone who had the link.
            (new CatalogAccessPolicy())->applyToItems($query, 'a');

            // Published state + active publish window: a draft, scheduled or expired
            // product must 404 by direct URL, exactly as the listing hides it. Empty
            // dates are stored NULL. The moment is the shared UTC clock (see
            // ActiveDateCondition::now()), never the DB's local-time NOW().
            $now = $db->quote(Pricing\ActiveDateCondition::now());

            $query->where($db->quoteName('a.state') . ' = 1')
                ->where('(' . $db->quoteName('a.publish_up') . ' IS NULL OR ' . $db->quoteName('a.publish_up') . ' <= ' . $now . ')')
                ->where('(' . $db->quoteName('a.publish_down') . ' IS NULL OR ' . $db->quoteName('a.publish_down') . ' >= ' . $now . ')');

            // Resolve translatable fields in the active language (current ->
            // default -> '') from the per-language tables. No main-table fallback.
            MultilingualHelper::addMultilingualJoinToQuery(
                query:             $query,
                mainAlias:         'a',
                mainPrimaryColumn: 'id',
                langTableBase:     '#__alfa_items',
                langPrimaryColumn: 'id_item',
                fields:            ['name', 'alias', 'short_desc', 'full_desc', 'meta_title', 'meta_desc', 'stock_low_message', 'stock_zero_message'],
            );

            $db->setQuery($query);
            $data = $db->loadObject();

            if (!$data) {
                throw new Exception(Text::_('COM_ALFA_ERROR_ITEM_NOT_FOUND'), 404);
            }

            // Setting correct stock action settings
            $settings = AlfaHelper::getGeneralSettings();

            if ($data->stock_action == -1) {
                $data->stock_action = $settings->get('stock_action');
                $data->stock_low_message = $settings->get('stock_low_message');
                $data->stock_zero_message = $settings->get('stock_zero_message');
            }

            if (empty($data->stock_low_message)) {
                $data->stock_low_message = $settings->get('stock_low_message');
            }

            if (empty($data->stock_zero_message)) {
                $data->stock_zero_message = $settings->get('stock_zero_message');
            }

            // Get categories
            $categories = $this->getItemCategories($pk);
            $categoryIDs = array_column($categories, 'id');
            $categoryMedia = !empty($categoryIDs)
                ? MediaHelper::getMediaData(origin: 'category', itemIDs: $categoryIDs)
                : [];

            $data->categories = [];

            foreach ($categories as $category) {
                $data->categories[$category['id']] = [
                    'name' => $category['name'],
                    'media' => $categoryMedia[$category['id']] ?? [],
                    'layouts' => json_decode($category['layouts'] ?: '{}', true) ?: [],
                ];
            }

            // Decoded once for the view's layout chain (item -> category -> params).
            $data->layouts = json_decode($data->layouts ?: '{}', true) ?: [];

            // Get manufacturers
            $manufacturers = $this->getItemManufacturers($pk);
            $manufacturerIDs = array_column($manufacturers, 'id');
            $manufacturerMedia = !empty($manufacturerIDs)
                ? MediaHelper::getMediaData(origin: 'manufacturer', itemIDs: $manufacturerIDs)
                : [];

            $data->manufacturers = [];

            foreach ($manufacturers as $manufacturer) {
                $data->manufacturers[$manufacturer['id']] = [
                    'name' => $manufacturer['name'],
                    'media' => $manufacturerMedia[$manufacturer['id']] ?? [],
                ];
            }

            // Get the item's fields, options, combinations and informational specs.
            $fieldData = ItemFieldsHelper::getDataForItem($pk);
            $data->fields = $fieldData->fields;
            $data->options = $fieldData->options;
            $data->combinations = $fieldData->combinations;
            $data->informational = $fieldData->informational;

            // Item gallery: the fallback the variant overlay may replace.
            $data->medias = MediaHelper::getMediaData(origin: 'item', itemIDs: $pk);

            // Resolve the buyer's request selection (combination sel[] + option
            // opt[] + plugin payloads conf[]), price the variant and overwrite the
            // item data: the SAME logic + parser the catalog cards use.
            $request = VariantHelper::requestSelection();

            // Selection SOURCE switch: a re-edit of an existing cart line seeds
            // the INITIAL render from the stored line (ownership-checked in
            // the service). Any live interaction (sel[]/opt[]/conf[] already in
            // the request) takes precedence, so the buyer's on-page changes
            // always win over the stored line. edit_line is a pure server-side
            // pointer; the configuration never rides the URL.
            $editLine = (int) Factory::getApplication()->getInput()->getInt('edit_line', 0);
            $hasRequest = $request['selection'] || $request['optionValueIds'] || $request['payloads'];

            if ($editLine > 0 && !$hasRequest) {
                $lineSelection = CartService::ambient()->lineConfiguration($data, $editLine);

                if ($lineSelection !== null) {
                    $request = $lineSelection;
                } else {
                    $editLine = 0; // absent / not owned -> a plain product page
                }
            }

            if ($editLine > 0) {
                $data->editLine = $editLine; // wrapper: "Update" + hidden edit_line
            }

            VariantHelper::resolveAndPrice(
                $data,
                $request['selection'],
                Pricing\PriceContext::fromSession(),
                new Pricing\PriceCalculator(),
                (int) $this->getState('quantity', 1),
                $request['optionValueIds'],
                $request['payloads'],
            );

            // Per-field payloads exposed for the render: the current-value
            // source a payload plugin seeds from, alongside where the variant/
            // option selection already lives on the resolved item.
            $data->fieldPayloads = $request['payloads'];

            // Sibling-selections snapshot for the field events (HasSelections):
            // a field reacting to ANOTHER field's choice (e.g. the configurator
            // sizing its pages from the chosen "Size" value) reads this instead
            // of re-parsing the request.
            $data->fieldSelections = [
                'sel' => $request['selection'],
                'opt' => $request['optionValueIds'],
            ];

            // Buy-form URLs. Unconditional: every addable item needs a form action,
            // whether or not it has interactive fields. The reload URL inside is the
            // one that stays conditional.
            $this->buildBuyUrls($data);

            // Get payment and shipment methods, for the groups the visitor is
            // priced with: the product page lists what the cart will offer.
            $categoryIDs = empty($data->categories) ? [] : array_keys($data->categories);
            $manufacturerIDs = empty($data->manufacturers) ? [] : array_keys($data->manufacturers);
            $customerGroups = Pricing\PriceContext::customerGroups(customer: $user);

            $data->payment_methods = AlfaHelper::getFilteredMethods(
                categories:    $categoryIDs,
                manufacturers: $manufacturerIDs,
                usergroups:    $customerGroups,
                userId:        $user->id,
                baseTable:     'payment',
            );

            $data->shipment_methods = AlfaHelper::getFilteredMethods(
                categories:    $categoryIDs,
                manufacturers: $manufacturerIDs,
                usergroups:    $customerGroups,
                userId:        $user->id,
                baseTable:     'shipment',
            );

            // Field display surfaces (selectors + cart slot). Opt-out via
            // setState('plugin.layouts', false) for data-only consumers (cart,
            // search autocomplete). Pricing/validation plugins are NOT gated here
            // (they always run via VariantHelper / the cart service).
            if ($this->getState('plugin.layouts', true)) {
                $this->dispatchFieldSurfaces($data);
            }

            $this->_item[$pk] = $data;
        }

        return $this->_item[$pk];
    }

    /**
     * Boot each field's plugin and harvest its product-page display surfaces: the
     * surface descriptors onto $field->surfaces (per slot) and the cart MAIN
     * slot replacement / CHILD siblings ($item->cart / $item->cartChildren). The
     * listing card does the same in ItemsModel via onItemListPrepare; this is the
     * product-page (onItemView) twin. A plain field's handler is a no-op.
     *
     * @param object $item The loaded item (carries ->fields).
     *
     * @since  2.0.0
     */
    protected function dispatchFieldSurfaces(object $item): void
    {
        $app = Factory::getApplication();

        $item->cart = [
            'layout' => 'cart.add',
            'layoutData' => [],
            'layoutPluginType' => '',
            'layoutPluginName' => '',
        ];
        $item->cartChildren = [];
        $item->surfaces = [];

        // One input-state bag for the whole product; the PHP RuleEvaluator paints the
        // initial visibility so a hidden field is emitted hidden (no FOUC); the JS
        // engine then owns every live toggle from the identical rule + state.
        $showonBag = StateBagBuilder::fromItem($item);

        foreach ($item->fields as $field) {
            try {
                $plugin = $app->bootPlugin((string) $field->type, 'alfa-item-fields');
            } catch (Throwable $e) {
                continue;
            }

            if (!$plugin instanceof ItemFieldsPlugin) {
                continue;
            }

            $event = new ItemViewEvent('onItemView', [
                'subject' => $item,
                'method' => $field,
                'currentValue' => $item->fieldPayloads[(int) $field->id] ?? null,
                'selections' => $item->fieldSelections ?? [],
            ]);

            $plugin->onItemView($event);

            // Contract guard: the surface's selector kind must match the field's
            // combination role (variant->sel[]/item, option->opt[]/price). Loud in dev.
            Surfaces::assertSelectorRole(
                $event->getSurfaces(),
                \in_array((int) $field->id, $item->variant?->variantFieldIds ?? [], true),
                (int) $field->id,
                (string) $field->type,
            );

            // Cart MAIN slot: last field that sets one wins (REPLACE).
            if ($event->hasCartLayout()) {
                $item->cart = [
                    'layout' => $event->getCartLayout(),
                    'layoutData' => $event->getCartLayoutData(),
                    'layoutPluginType' => $event->getCartLayoutPluginType() ?: 'alfa-item-fields',
                    'layoutPluginName' => $event->getCartLayoutPluginName() ?: (string) $field->type,
                ];
            }

            // Cart CHILD slot: same granular contract as MAIN, but APPEND (sibling).
            if ($event->hasCartChild()) {
                $item->cartChildren[] = [
                    'layout' => $event->getCartChildLayout(),
                    'layoutData' => $event->getCartChildLayoutData(),
                    'layoutPluginType' => $event->getCartChildLayoutPluginType() ?: 'alfa-item-fields',
                    'layoutPluginName' => $event->getCartChildLayoutPluginName() ?: (string) $field->type,
                ];
            }

            // Aggregate this field's surfaces onto the page slot map (insertion
            // order). Legacy setLayout() bridges to BuyForm; showon rides along.
            Surfaces::appendTo(
                $item->surfaces,
                $event,
                'alfa-item-fields',
                (string) $field->type,
                ItemSurface::BuyForm->value,
                Surfaces::showonAttr($field) . InitialState::hiddenAttr($field, $showonBag),
                field: $field,
            );
        }

        // Feature plugins (onItemDisplay): boot-and-call after the field
        // surfaces so their contributions land on the same slot map, owner
        // defaulted to the contributor. The alfa group is already booted by
        // AlfaComponent::boot(), so bootPlugin only returns instances.
        foreach (PluginHelper::getPlugin('alfa') as $alfaPlugin) {
            $plugin = $app->bootPlugin($alfaPlugin->name, 'alfa');

            if (!method_exists($plugin, 'onItemDisplay')) {
                continue;
            }

            $displayEvent = new ItemDisplayEvent('onItemDisplay', ['item' => $item]);
            $plugin->onItemDisplay($displayEvent);

            Surfaces::appendTo(
                $item->surfaces,
                $displayEvent,
                'alfa',
                $alfaPlugin->name,
                ItemSurface::BuyForm->value,
            );
        }

        // Sale-tag badges: a flagged discount's name onto the product-page slots.
        // Appended AFTER the surface reset above and once the price/discounts are
        // known (set by resolveAndPrice before this runs).
        SaleTag::contribute($item, false);
    }

    /**
     * Build the buy-form URLs on $data: the add-to-cart endpoint the form posts to, and the
     * canonical SEF reload URL (single 200; the active Itemid keeps the re-render inside the
     * current menu route), empty for an item that has nothing to re-render.
     *
     * @param object $data The item.
     *
     * @since  2.0.0
     */
    protected function buildBuyUrls(object $data): void
    {
        // The form's action, always: without it the buy form posts to the current page and a
        // shopper without JavaScript adds nothing at all.
        $data->addToCartUrl = Route::_('index.php?option=com_alfa&task=cart.addToCart');

        // Empty unless there is something to re-render: the template always prints it as the
        // buy form's data-action, and variant.js reads an empty one as nothing to reload.
        $data->reloadUrl = '';

        if (empty($data->fields)) {
            return;
        }

        $input = Factory::getApplication()->getInput();
        $reloadQuery = 'index.php?option=com_alfa&view=item&tmpl=component&id=' . (int) $data->id;

        if ($categoryId = $input->getInt('catid', 0)) {
            $reloadQuery .= '&category_id=' . $categoryId;
        }

        if ($activeItemid = $input->getInt('Itemid')) {
            $reloadQuery .= '&Itemid=' . $activeItemid;
        }

        // Keep the re-edit pointer across live reloads so the "Update" context
        // survives an on-page selection change (the submit still verifies it).
        if ($editLine = $input->getInt('edit_line', 0)) {
            $reloadQuery .= '&edit_line=' . $editLine;
        }

        $data->reloadUrl = Route::_($reloadQuery, false);
    }

    /**
     * Get item categories
     *
     * @param int $pk Item ID
     *
     *
     * @since  1.0.0
     */
    protected function getItemCategories(int $pk): array
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select($db->quoteName(['c.id', 'c.layouts']))
            ->from($db->quoteName('#__alfa_categories', 'c'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_items_categories', 'ic'),
                $db->quoteName('c.id') . ' = ' . $db->quoteName('ic.category_id'),
            )
            ->where($db->quoteName('ic.item_id') . ' = :pk')
            ->where($db->quoteName('c.state') . ' = 1')
            ->bind(':pk', $pk, ParameterType::INTEGER);

        // A restricted category must not be named here either. Category restriction
        // deliberately does not hide the products inside it, so a public product can
        // legitimately sit under one, and this strip would then publish its name,
        // link and image to everyone who can see that product.
        (new CatalogAccessPolicy())->applyToCategories($query, 'c');

        // Resolve the category name in the active language from the per-language tables.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'c',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_categories',
            langPrimaryColumn: 'id_category',
            fields:            ['name'],
        );

        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /**
     * Get item manufacturers
     *
     * @param int $pk Item ID
     *
     *
     * @since  1.0.0
     */
    protected function getItemManufacturers(int $pk): array
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select($db->quoteName('m.id'))
            ->from($db->quoteName('#__alfa_manufacturers', 'm'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_items_manufacturers', 'im'),
                $db->quoteName('m.id') . ' = ' . $db->quoteName('im.manufacturer_id'),
            )
            ->where($db->quoteName('im.item_id') . ' = :pk')
            ->bind(':pk', $pk, ParameterType::INTEGER);

        // Resolve the manufacturer name in the active language from the lang tables.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'm',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_manufacturers',
            langPrimaryColumn: 'id_manufacturer',
            fields:            ['name'],
        );

        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }
}
