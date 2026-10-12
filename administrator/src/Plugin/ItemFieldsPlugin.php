<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ScopedFieldEvent;
use Alfa\Component\Alfa\Administrator\Helper\FieldParams;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

/**
 * Abstract base for alfa-item-fields plugins (product field types).
 *
 * One plugin = one field type (choice, color, configurator, ...). Every field
 * is uniform: it has at least one value, so it can be used as a variation
 * (generator) or an option like any other field. A type's richness comes not
 * from a taxonomy but from how its plugin DECORATES SURFACES, exactly the
 * payments/shipments model:
 *
 *  - Backend  -> ACTIONS. Buttons on a variation row, an option row or the
 *               order line (onGetFieldActions / onExecuteFieldAction). A
 *               button runs an action or opens a popup via a layout. The
 *               event arrives pre-bound to that row's params.
 *  - Frontend -> LAYOUTS. setLayout/setLayoutData on the item page
 *               (onItemView), the listing card (onItemListPrepare, per field
 *               the card draws, with setCartLayout() control of the card's
 *               cart) and the cart line (onCartItemView). A layout can carry
 *               its own buttons/JS. The whole-page onItemsListPrepare runs
 *               after every card is built and has no cart setters.
 *
 * Config homes (one independent row per field, via FieldParams):
 *  - Field-level  params/params.xml -> #__alfa_item_fields.params
 *  - Value-level  params/value.xml  -> #__alfa_item_field_values.params
 *  - Item-level   #__alfa_item_field_overrides
 *  - Variant-level (combination|option) #__alfa_item_combination_field_params
 *  - Order-level  the order-line snapshot (order schema, task #7)
 *
 * These surface hooks are invoked by DIRECT booted-plugin calls: the view/model
 * boot the field's OWN plugin by type ($app->bootPlugin($field->type,
 * 'alfa-item-fields')->onX($fieldEvent)) and pass that field's typed event, so a
 * plugin only ever receives its own event for its own field; there is no
 * cross-group fan-out. (The only method name literally shared with the
 * payments/shipments groups is onItemView; onCartItemView and onCalculateItemPrice
 * are item-fields-only.) getSubscribedEvents() additionally engages the plugin on
 * the global dispatcher for parity; the instanceof guard in each handler is
 * therefore DEFENSIVE: a no-op if a handler is ever reached via a global dispatch
 * carrying a foreign event class.
 *
 * FLUENT API: write one readable line instead of raw SQL:
 *
 *   $this->editItemConfig($itemId, $fieldId)->set('model', 'pencil')->save();
 *   $this->editVariantConfig('combination', $comboId, $fieldId, $itemId)->merge($x)->save();
 *
 * @since  1.3.0
 */
abstract class ItemFieldsPlugin extends Plugin
{
    /**
     * The events this plugin group subscribes to. NOTE: the surface hooks are
     * normally driven by direct booted-plugin calls per field (the view/model
     * boot $field->type and call the method); they are NOT globally dispatched.
     * This subscription engages the plugin on the dispatcher for parity with
     * payments/shipments; the per-handler instanceof guard keeps it safe in the
     * defensive case where a foreign event is dispatched under a shared name
     * (only onItemView is shared across the three groups).
     *
     *
     * @since  1.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onItemView' => 'onItemView',
            'onItemListPrepare' => 'onItemListPrepare',
            'onItemsListPrepare' => 'onItemsListPrepare',
            'onCartItemView' => 'onCartItemView',
            'onGetFieldActions' => 'onGetFieldActions',
            'onExecuteFieldAction' => 'onExecuteFieldAction',
            'onCalculateItemPrice' => 'onCalculateItemPrice',
            'onValidateCartAdd' => 'onValidateCartAdd',
        ];
    }

    //  SURFACE HOOKS: no-op defaults; concrete plugins override

    /**
     * Item page: set a layout to render the field's buyer-facing surface.
     *
     * @param object $event ItemViewEvent (type-guard before acting)
     *
     *
     * @since  1.3.0
     */
    public function onItemView($event): void
    {
    }

    /**
     * Listing card, per (item x field the card draws, Show on listings):
     * render the field's card surfaces and/or decorate this card's cart region
     * via $event->setCartLayout()/setCartLayoutData(): the listing-card
     * counterpart of onItemView. Dispatched only to the field's own plugin; the
     * model fills the layout owner from the field's type. Read context with
     * $event->getItem() / $event->getField(). A card may be drawn twice (a
     * dimension drew no selector), so act through the event only.
     *
     * @param object $event ItemListPrepareEvent (type-guard before acting)
     *
     *
     * @since  1.3.0
     */
    public function onItemListPrepare($event): void
    {
    }

    /**
     * Whole-page hook (the "extra"): read $event->getItems() for cross-item
     * changes. Runs AFTER the per-item loop, on fully built cards (cart
     * included), so it has the last word; it carries no cart setters.
     *
     * @param object $event ItemsListPrepareEvent (type-guard before acting)
     *
     *
     * @since  1.3.0
     */
    public function onItemsListPrepare($event): void
    {
    }

    /**
     * Cart line: set a layout to render the customer's saved configuration.
     *
     * @param object $event CartItemViewEvent (type-guard before acting)
     *
     *
     * @since  1.3.0
     */
    public function onCartItemView($event): void
    {
    }

    /**
     * Satisfies the shared Plugin base's abstract onCartView (the
     * payments/shipments cart-method hook). Item-field plugins do not use it:
     * their cart-line surface is onCartItemView, so it is a no-op here and is
     * NOT subscribed, but it must exist to keep concrete plugins instantiable.
     *
     * @param object $event Cart view event (unused by item fields)
     *
     *
     * @since  1.3.0
     */
    public function onCartView($event): void
    {
    }

    /**
     * Register action buttons for a backend surface (variation/option/order
     * row). Default: no actions.
     *
     * An action changes state unless it is declared ->readOnly(), and a
     * state-changing action answers only a POST: on the action page with a
     * one-time key, on the item-field endpoint (executeFieldAction) as a POST.
     * A button that only opens a popup declares ->readOnly(); an action that is
     * not offered here (a save arm) always changes state.
     *
     * @param object $event GetFieldActionsEvent (type-guard before acting)
     *
     *
     * @since  1.3.0
     */
    public function onGetFieldActions($event): void
    {
    }

    /**
     * Execute a clicked action. Runs ONCE on the action page (view=action).
     * You produce the outcome on the event:
     *
     *   Show a UI - setLayout() + setLayoutData():
     *     $event->setLayout('configure')->setLayoutData([...]);
     *   Rendered on a real page, so its useScript()/useStyle() load (an editor,
     *   a form, a logs table...).
     *
     *   Footer buttons - addButton(): declared here, NOT inside the layout HTML.
     *   A button closes, closes + refreshes, or RUNS another action of this
     *   entity in the SAME popup:
     *     $event->addButton('Cancel')                              // close
     *           ->addButton('Done', ['reload' => true])            // close + refresh
     *           ->addButton('Refund', ['action' => 'do_refund', 'class' => 'btn-danger']);
     *   (A layout can still place its own data-alfa-close / data-alfa-reload /
     *    data-alfa-run buttons inline if it wants custom positioning.)
     *
     *   Report a result - setMessage() (+ setRefresh()):
     *     $event->setMessage('Saved.')->setRefresh(true);
     *   A clean notice is shown with an OK button (no auto-dismiss).
     *
     *   Confirm - just a message + two buttons, no special layout name:
     *     $event->setMessage('Reset this configuration?')
     *           ->addButton('No')
     *           ->addButton('Yes', ['action' => 'do_reset', 'class' => 'btn-danger']);
     *
     *   Wizard (step by step) - each step sets its layout + a button that RUNS
     *   the next action, which loads fresh in the same popup (each step can
     *   save as it goes): step1 -> addButton('Next', ['action' => 'step2']) -> ...
     *
     * Default: polite failure so a stale button never dies silently.
     *
     * @param object $event ExecuteFieldActionEvent (type-guard before acting)
     *
     *
     * @since  1.3.0
     */
    public function onExecuteFieldAction($event): void
    {
        if (method_exists($event, 'setError')) {
            $event->setError('Action "' . $event->getAction() . '" is not implemented by ' . static::class . '.');
        }
    }

    /**
     * Adjust or override the engine price: read $event->getConfig() + the buyer
     * payload and add declarative price lines (folded by PriceCalculator in
     * sortOrder). No-op default.
     *
     * @param object $event CalculateItemPriceEvent
     *
     *
     * @since  1.3.0
     */
    public function onCalculateItemPrice($event): void
    {
    }

    /**
     * Validate the posted configuration, set the snapshot + price impact
     * (dispatch lands with task #7).
     *
     * @param object $event ValidateCartAddEvent (type-guard before acting)
     *
     *
     * @since  1.3.0
     */
    public function onValidateCartAdd($event): void
    {
    }

    //  FLUENT API: one readable line instead of raw SQL

    /**
     * Write the ITEM-level config of a field (fluent builder).
     *
     *   $this->editItemConfig($itemId, $fieldId)->set('model', 'pencil')->save();
     *
     * @param int $itemId The item id.
     * @param int $fieldId The field id.
     *
     * @return FieldParams Builder: chain set()/merge(), finish with save().
     *
     * @since  1.3.0
     */
    protected function editItemConfig(int $itemId, int $fieldId): FieldParams
    {
        return FieldParams::item(itemId: $itemId, fieldId: $fieldId);
    }

    /**
     * Write a VARIANT-row config of a field (combination|option, fluent).
     *
     *   $this->editVariantConfig('combination', $comboId, $fieldId, $itemId)->merge($x)->save();
     *
     * @param string $scope 'combination' or 'option'.
     * @param int $scopeId The combination or option-value id.
     * @param int $fieldId The field id.
     * @param int $itemId The owning item id.
     *
     * @return FieldParams Builder: chain set()/merge(), finish with save().
     *
     * @since  1.3.0
     */
    protected function editVariantConfig(string $scope, int $scopeId, int $fieldId, int $itemId): FieldParams
    {
        return FieldParams::variant(scope: $scope, scopeId: $scopeId, fieldId: $fieldId, itemId: $itemId);
    }

    /**
     * Read the item-level config of a field.
     *
     * @param int $itemId The item id.
     * @param int $fieldId The field id.
     *
     * @return Registry Empty Registry when nothing is stored.
     *
     * @since  1.3.0
     */
    protected function getItemConfig(int $itemId, int $fieldId): Registry
    {
        return FieldParams::read(scope: FieldParams::SCOPE_ITEM, scopeId: $itemId, fieldId: $fieldId);
    }

    /**
     * Read the EFFECTIVE config of a field at a variant scope: the variant
     * (option/combination) config merged over the item-level base. The read
     * counterpart of editVariantConfig(), so a plugin does $this->getEffectiveConfig(...)
     * instead of the static FieldParams::getEffective(...).
     *
     * @param string $scope FieldParams::SCOPE_OPTION | SCOPE_COMBINATION.
     * @param int $scopeId The option-value or combination id.
     * @param int $fieldId The field id.
     * @param int $itemId The owning item id.
     *
     * @return Registry The merged effective config.
     *
     * @since  1.3.0
     */
    protected function getEffectiveConfig(string $scope, int $scopeId, int $fieldId, int $itemId): Registry
    {
        return FieldParams::getEffective(scope: $scope, scopeId: $scopeId, fieldId: $fieldId, itemId: $itemId);
    }

    /**
     * Read the config a scoped field event targets: the stored config for THIS
     * row+field at THIS scope, straight off the event (variant rows fall back to
     * the item-level default; an 'order' scope reads the item-level config).
     * Serves BOTH GetFieldActionsEvent and ExecuteFieldActionEvent: the event
     * stays a dumb carrier of scope/scopeId/itemId/field, the I/O lives here, so
     * a handler just does $this->getConfig($event).
     *
     * @param ScopedFieldEvent $event The scoped field event (carries the scope).
     *
     * @return Registry Empty Registry when nothing is stored.
     *
     * @since  1.3.0
     */
    protected function getConfig(ScopedFieldEvent $event): Registry
    {
        $scope = $event->getScope();

        if ($scope === FieldParams::SCOPE_ITEM || $scope === 'order') {
            return $this->getItemConfig(itemId: $event->getItemId(), fieldId: (int) $event->getField()->id);
        }

        return $this->getEffectiveConfig(
            scope: $scope,
            scopeId: $event->getScopeId(),
            fieldId: (int) $event->getField()->id,
            itemId: $event->getItemId(),
        );
    }

    /**
     * Persist the config the action targets: the write twin of getConfig(),
     * the save path for a configure popup. Resolves THIS row+field at THIS scope
     * from the event (an 'order' action writes the item-level config); empty
     * config deletes the row. No table knowledge needed in the handler:
     * $this->saveConfig($event, $config).
     *
     * @param ScopedFieldEvent $event The scoped field event (carries the scope).
     * @param array $config The config to store (empty deletes the row).
     *
     * @return bool True on success.
     *
     * @since  1.3.0
     */
    protected function saveConfig(ScopedFieldEvent $event, array $config): bool
    {
        $scope = $event->getScope() === 'order' ? FieldParams::SCOPE_ITEM : $event->getScope();
        $scopeId = $scope === FieldParams::SCOPE_ITEM ? $event->getItemId() : $event->getScopeId();

        return FieldParams::write(
            scope: $scope,
            scopeId: $scopeId,
            fieldId: (int) $event->getField()->id,
            itemId: $event->getItemId(),
            config: $config,
        );
    }

    /**
     * Resolve a layout file of this plugin with template-override support
     * (templates/<tpl>/html/plg_alfa-item-fields_<type>/<layout>.php wins over
     * the plugin's tmpl/<layout>.php).
     *
     * @param string $layout Layout name without extension.
     *
     * @return string Absolute path of the layout file to include.
     *
     * @since  1.3.0
     */
    protected function getLayoutPath(string $layout = 'default'): string
    {
        return PluginHelper::getLayoutPath('alfa-item-fields', $this->_name, $layout);
    }

    /**
     * ALL field-definition ids whose type is this plugin. A field TYPE (the
     * plugin) can have MANY definitions on a product, e.g. two configurators,
     * or "Size" and "Colour" are both the 'choice' type. Never assume one.
     * Cached per request, per plugin type.
     *
     * @return int[] The field-definition ids (empty when none exist yet).
     *
     * @since  1.3.0
     */
    protected function fieldIds(): array
    {
        static $cache = [];

        if (!array_key_exists($this->_name, $cache)) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn('id'))
                    ->from($db->qn('#__alfa_item_fields'))
                    ->where($db->qn('type') . ' = ' . $db->q($this->_name)),
            );

            $cache[$this->_name] = array_map('intval', $db->loadColumn() ?: []);
        }

        return $cache[$this->_name];
    }
}
