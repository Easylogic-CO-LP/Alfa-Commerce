<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\BackorderPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryProjectionMismatchException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryReservedFloorException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryTrackingException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitRetirementException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\LocationPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal;
use Alfa\Component\Alfa\Administrator\Service\ItemFields\ItemFieldDisplayResolver;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use RuntimeException;
use Throwable;

/**
 * Admin-side helper for the per-item field layer.
 *
 * There is NO per-item wizard and NO flags: a field's role comes from where
 * the admin USES it in the "Fields & combinations" popup:
 *
 *   Variations    choice fields crossed by the explicit generator
 *                 (#__alfa_item_combinations + _values; depth-locked set)
 *   Options       cart-line modifiers with an optional price delta
 *                 (#__alfa_item_option_values; applied ON TOP of the final
 *                 price, never part of the price-row competition)
 *   Informational the item's descriptive values for specs/filters
 *                 (#__alfa_item_info_values)
 *
 * Eligibility = category scope of the published field definitions; display
 * order = definition ordering, everywhere. Pricing is NEVER value-level:
 * the single source of truth is #__alfa_items_prices (item rows and
 * per-combination rows), options being line modifiers on top.
 *
 * @since  2.0.0
 */
class ItemFieldsAdminHelper
{
    /**
     * The stored columns of a variant's price row that its editors render and
     * write, in one order: what the grid's price cell and the Edit window read,
     * and what the Edit window's rendered state is made of.
     *
     * @since  2.0.0
     */
    private const PRICE_ROW_COLUMNS = [
        'id', 'mode', 'operation', 'value', 'override_value', 'override_type',
        'currency_id', 'country_id', 'usergroup_id', 'user_id',
        'quantity_start', 'quantity_end', 'publish_up', 'publish_down', 'state',
    ];

    /**
     * Field roles in storefront render order: a field's place in the
     * variation -> option -> informational flow. The ShowOn forward-only guard
     * reads these: a rule may gate only on fields with a SMALLER role/rank
     * (shown before it) that are INTERACTIVE (a buyer actually chooses them);
     * informational values are static, never a switch. Mirrors the Site
     * ItemFieldsHelper so picker, badge and storefront agree.
     *
     * @since  2.0.0
     */
    public const ROLE_VARIATION = 0;
    public const ROLE_OPTION = 1;
    public const ROLE_INFORMATIONAL = 2;

    /**
     * Whether a field role is interactive (a buyer chooses it) and so may serve
     * as a ShowOn switch: variation or option, never informational.
     *
     * @param int $role A ROLE_* value.
     *
     *
     * @since  2.0.0
     */
    public static function isInteractiveRole(int $role): bool
    {
        return $role <= self::ROLE_OPTION;
    }

    /**
     * The item fields a combination may override: the CURATED set (a design
     * decision, deliberately not "everything"). Labels, descriptions, types,
     * options and multilingual-ness are NOT repeated anywhere: they derive
     * from each field's definition in forms/item.xml via
     * getCombinationOverrideDescriptors().
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    public const COMBINATION_OVERRIDABLES = [
        'weight', 'width', 'height', 'depth',
        'quantity_min', 'quantity_max', 'quantity_step',
        'stock_action', 'stock_low', 'low_stock_notify', 'manage_stock',
        'backorder', 'min_qty',
        'name', 'short_desc', 'full_desc', 'stock_low_message', 'stock_zero_message',
    ];

    /**
     * How each SCALAR override is coerced on its way into
     * `#__alfa_item_combinations`: column => cast.
     *
     * THE registry, not a second list. saveSingleCombination() used to build a
     * hardcoded `$update` object maintained independently of
     * COMBINATION_OVERRIDABLES, so adding a field to the constant alone rendered
     * a control whose value the persist layer silently discarded: no error, no
     * log, the merchant simply typed into nothing. The two lists are now one:
     * a scalar with no entry here is not offered at all
     * ({@see self::getCombinationOverrideDescriptors()}), and everything offered
     * is written.
     *
     * Multilingual overridables are absent by design: their values live in the
     * sparse `#__alfa_item_combinations_<lang>` tables, written by
     * saveCombinationTranslations().
     *
     * @var array<string, string>
     *
     * @since  2.0.0
     */
    public const COMBINATION_OVERRIDE_CASTS = [
        'weight' => self::CAST_FLOAT,
        'width' => self::CAST_FLOAT,
        'height' => self::CAST_FLOAT,
        'depth' => self::CAST_FLOAT,
        'quantity_min' => self::CAST_QUANTITY_RULE,
        'quantity_max' => self::CAST_QUANTITY_RULE,
        'quantity_step' => self::CAST_QUANTITY_RULE,
        'stock_low' => self::CAST_FLOAT,
        'min_qty' => self::CAST_FLOAT,
        'low_stock_notify' => self::CAST_BOOL,
        'manage_stock' => self::CAST_BOOL,
        'stock_action' => self::CAST_ENUM_STOCK_ACTION,
        'backorder' => self::CAST_ENUM_BACKORDER,
    ];

    /**
     * '' => null, anything else => float. `0` survives: an explicit zero is a
     * decision ("no safety stock here"), not an absent override.
     *
     * @since  2.0.0
     */
    public const CAST_FLOAT = 'float';

    /**
     * A quantity rule (minimum, maximum, step), an exact decimal: '' and an
     * exact zero store NULL, so the combination follows the product's rule;
     * any other value stores its exact canonical form. A posted rule is judged
     * before it is cast ({@see self::changedQuantityRules()}), so a value that
     * is not an exact quantity never reaches the column.
     *
     * @since  2.0.0
     */
    public const CAST_QUANTITY_RULE = 'quantity_rule';

    /**
     * The quantity rules a combination may override, in the order a refusal
     * names them.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    private const QUANTITY_RULE_COLUMNS = ['quantity_min', 'quantity_step', 'quantity_max'];

    /**
     * '' => null, '1' => 1, EVERYTHING ELSE => 0. Only ever for a two-valued
     * switch: routing a string enum through it stores every one of its values
     * as 0, which is exactly how `stock_action` overrides were destroyed.
     *
     * @since  2.0.0
     */
    public const CAST_BOOL = 'bool';

    /**
     * The four-valued visibility enum, including its "from configuration"
     * sentinel ({@see StockVisibilityPolicy::normaliseStoredValue()}).
     *
     * @since  2.0.0
     */
    public const CAST_ENUM_STOCK_ACTION = 'stock_action';

    /**
     * The backorder enum, whose empty string is a STORED decision ("follow the
     * shop") and whose "inherit" is a reserved token
     * ({@see BackorderPolicy::normaliseStoredValue()}).
     *
     * @since  2.0.0
     */
    public const CAST_ENUM_BACKORDER = 'backorder';

    /**
     * The overridables that describe a physical parcel. A VIRTUAL product has
     * none (nothing is boxed, weighed or shipped), so its combinations are not
     * offered these at all: a value typed there would be carried into shipping
     * quotes that never run.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    public const COMBINATION_DIMENSION_FIELDS = ['weight', 'width', 'height', 'depth'];

    /**
     * Request-scoped memo for {@see self::getFieldsPopupData()}: opening the
     * visibility popup renders the field AND its banner, each of which needs the
     * item's fields; without this they'd each run the same query set. Keyed by
     * item + category scope, so it is safe across callers in one request.
     *
     * @var array<string, array>
     *
     * @since  2.0.0
     */
    private static array $fieldsPopupCache = [];

    /**
     * What the stock-tracking switch of the last saveSingleCombination() call
     * did: the inventory service's changeTracking() result, or null when that
     * save changed no unit's tracking. Read through lastTrackingChange().
     *
     * @var array{operation_id: ?string, movements: int, kept_rows: int, kept_on_hand: float}|null
     *
     * @since  2.0.0
     */
    private static ?array $lastTrackingChange = null;

    /**
     * Describe every overridable field from its forms/item.xml definition:
     * translated label/description, type, multilingual flag and (for
     * list/radio fields) the actual options, so the combinations editor
     * renders exactly what the item form defines, never a hardcoded copy.
     *
     * Two things are decided HERE rather than in the layout, because both are
     * questions the popup's raw-HTML renderer cannot answer:
     *
     *  - a scalar with no {@see self::COMBINATION_OVERRIDE_CASTS} entry is
     *    dropped, so a control the persist layer would discard is never drawn;
     *  - a VIRTUAL product's combinations get no dimension controls. The item
     *    form expresses that with `showon="is_virtual:0"`, which the popup
     *    deliberately ignores (core showon.js crashes on missing targets), so
     *    the rule has to be applied to the descriptor list itself.
     *
     * @param bool $virtual Whether the parent item needs no shipping.
     *
     * @return array<string, object> name => descriptor
     *
     * @since  2.0.0
     */
    public static function getCombinationOverrideDescriptors(bool $virtual = false): array
    {
        // Memoised per shape, not globally: the two answers differ.
        static $descriptors = [];

        $shape = $virtual ? 'virtual' : 'physical';

        if (isset($descriptors[$shape])) {
            return $descriptors[$shape];
        }

        $form = new Form('com_alfa.item.overridables');
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_alfa/forms/item.xml', false);

        $built = [];

        foreach (self::COMBINATION_OVERRIDABLES as $name) {
            $field = $form->getField($name);

            if (!$field) {
                continue;
            }

            if ($virtual && \in_array($name, self::COMBINATION_DIMENSION_FIELDS, true)) {
                continue;
            }

            $multilingual = (string) $field->getAttribute('multilingual_table', '') !== '';
            $cast = self::COMBINATION_OVERRIDE_CASTS[$name] ?? '';

            // No cast = no column write. Rendering it would produce a control the
            // merchant fills in and saveSingleCombination() throws away.
            if (!$multilingual && $cast === '') {
                continue;
            }

            $type = strtolower((string) $field->getAttribute('type', 'text'));
            $options = [];

            // The field's own options (list/radio) drive the override select.
            try {
                foreach ($field->options ?? [] as $option) {
                    $options[] = (object) ['value' => (string) $option->value, 'text' => (string) $option->text];
                }
            } catch (Throwable $e) {
                // Field type without options: fine.
            }

            $built[$name] = (object) [
                'name' => $name,
                'label' => Text::_((string) $field->getAttribute('label', $name)),
                'description' => ((string) $field->getAttribute('description', '') !== '')
                    ? Text::_((string) $field->getAttribute('description')) : '',
                'type' => $type,
                'multilingual' => $multilingual,
                'cast' => $cast,
                'options' => $options,
                // What the "inherit the item" option POSTS. Blank normally; a
                // reserved token when the field's own options already spend ''
                // on a meaning of their own.
                'inherit_value' => self::combinationInheritValue(cast: $cast, options: $options),
            ];
        }

        $descriptors[$shape] = $built;

        return $built;
    }

    /**
     * The value a combination's "inherit the item" option must post.
     *
     * Blank is the natural choice and stays the default. It is only wrong when
     * the field's own option list already contains `value=""` with a different
     * meaning (`backorder`'s first option is "Use shop-wide setting"), because
     * the editor prepends its inherit option to that same list and the merchant
     * (and the persist layer) then face two identical blanks meaning two
     * different things.
     *
     * A new enum whose options include '' MUST be given a token here, or its
     * inherit option silently collides again.
     *
     * @param string $cast The field's COMBINATION_OVERRIDE_CASTS entry.
     * @param object[] $options The field's own options.
     *
     * @return string Reserved token, or '' when blank is unambiguous.
     *
     * @since  2.0.0
     */
    protected static function combinationInheritValue(string $cast, array $options): string
    {
        foreach ($options as $option) {
            if ((string) $option->value !== '') {
                continue;
            }

            return match ($cast) {
                self::CAST_ENUM_BACKORDER => BackorderPolicy::INHERIT_TOKEN,
                default => '',
            };
        }

        return '';
    }

    /**
     * Coerce ONE submitted override into the value its column stores.
     *
     * The casts are deliberately not interchangeable: each empty means
     * something different per family, and the one time that was glossed over
     * ({@see self::CAST_BOOL} applied to an enum) every override of that field
     * was destroyed on the next save.
     *
     * @param string $cast A CAST_* constant.
     * @param mixed $value The submitted value.
     *
     * @return float|int|string|null The column value; null = no override.
     *
     * @throws InvalidArgumentException When a quantity rule is not an exact quantity
     *                                  (the save judges every posted rule first).
     *
     * @since  2.0.0
     */
    protected static function castCombinationOverride(string $cast, mixed $value): float|int|string|null
    {
        $raw = trim((string) ($value ?? ''));

        // "Inherit the item" is a reserved token, never a value: the editor cannot
        // post an empty string for it, because `backorder`'s own option list already
        // uses '' to mean "follow the shop". Handled here, once, for EVERY cast;
        // left to the individual casts, CAST_BOOL would silently store it as 0.
        if ($raw === BackorderPolicy::INHERIT_TOKEN) {
            return null;
        }

        return match ($cast) {
            self::CAST_FLOAT => ($raw === '') ? null : (float) $raw,
            self::CAST_QUANTITY_RULE => self::inheritableQuantityRule(value: $raw),
            self::CAST_BOOL => ($raw === '') ? null : (($raw === '1') ? 1 : 0),
            self::CAST_ENUM_STOCK_ACTION => StockVisibilityPolicy::normaliseStoredValue(value: $value),
            self::CAST_ENUM_BACKORDER => BackorderPolicy::normaliseStoredValue(value: $value),
            // Unreachable while the registry and this match are edited together;
            // storing nothing is the only safe answer if they ever drift.
            default => null,
        };
    }

    /**
     * A combination's quantity rule as it is stored: NULL when it follows the
     * product's rule (blank, or exactly zero, which every reader treats as not
     * overridden), otherwise its exact canonical form.
     *
     * @param mixed $value A posted or stored rule.
     *
     *
     * @throws InvalidArgumentException When the value is not an exact quantity.
     *
     * @since  2.0.0
     */
    private static function inheritableQuantityRule(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            return null;
        }

        $rule = QuantityDecimal::canonical(value: $raw);

        return QuantityDecimal::isZero(a: $rule) ? null : $rule;
    }

    /**
     * The quantity rules a Studio save changes, judged before anything is written.
     *
     * Each posted rule is read as an exact decimal. A blank rule, or a minimum or
     * maximum of exactly zero, follows the product's rule; a rule the combination
     * stores as zero follows it too. A posted rule is changed when it differs
     * exactly from the stored one. When the save changes a rule (or posts one
     * that cannot be read), the combination's resolved rules (its own where it
     * has them, the product's otherwise) must hold: every posted rule a number
     * not below zero with at most 8 decimal places and 16 digits before the
     * point, a step above zero, a minimum not above the maximum, and at least one
     * quantity a cart line can hold. A set the save does not change is not
     * judged, so rules stored before this check never block another edit.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param object $stored The combination's stored row.
     * @param array $row jform[combinations][<id>] from the Studio.
     *
     * @return array<string, ?string> The changed rules: column => the value to
     *                                store, null when it follows the product.
     *
     * @throws RuntimeException With the translated refusal naming the combination.
     *
     * @since  2.0.0
     */
    private static function changedQuantityRules(int $itemId, int $combinationId, object $stored, array $row): array
    {
        $changed = [];
        $unreadable = [];

        foreach (self::QUANTITY_RULE_COLUMNS as $column) {
            if (!\array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];
            $blank = $value === null
                || (\is_string($value) && \in_array(trim($value), ['', BackorderPolicy::INHERIT_TOKEN], true));
            $posted = $blank ? null : QuantityDecimal::parse(value: $value);

            if (!$blank && ($posted === null || QuantityDecimal::isNegative(a: $posted))) {
                $unreadable[] = $column;

                continue;
            }

            // A zero step is not a way to follow the product: it is refused below.
            if ($posted !== null && $column !== 'quantity_step' && QuantityDecimal::isZero(a: $posted)) {
                $posted = null;
            }

            if ($posted !== self::inheritableQuantityRule(value: $stored->{$column} ?? null)) {
                $changed[$column] = $posted;
            }
        }

        if ($changed === [] && $unreadable === []) {
            return [];
        }

        $reason = null;

        if ($unreadable !== []) {
            $descriptors = self::getCombinationOverrideDescriptors();
            $reason = implode(' ', array_map(
                static fn (string $column): string => Text::sprintf(
                    'COM_ALFA_ITEM_QUANTITY_RULE_INVALID',
                    (string) ($descriptors[$column]->label ?? $column),
                ),
                $unreadable,
            ));
        } elseif (($changed['quantity_step'] ?? null) !== null && QuantityDecimal::isZero(a: $changed['quantity_step'])) {
            $reason = Text::_('COM_ALFA_ITEM_QUANTITY_STEP_ZERO');
        } else {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $item = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn(self::QUANTITY_RULE_COLUMNS))
                    ->from($db->qn('#__alfa_items'))
                    ->where($db->qn('id') . ' = ' . $itemId),
            )->loadObject();

            // The rules the combination sells under once saved: its own where it
            // has one, the product's otherwise.
            $resolved = [];

            foreach (self::QUANTITY_RULE_COLUMNS as $column) {
                $resolved[$column] = CartQuantityPolicy::ruleValue(
                    combination: \array_key_exists($column, $changed) ? $changed[$column] : ($stored->{$column} ?? null),
                    item: $item->{$column} ?? null,
                );
            }

            $limited = QuantityDecimal::compare(a: $resolved['quantity_max'], b: QuantityDecimal::ZERO) > 0;

            if ($limited && QuantityDecimal::compare(a: $resolved['quantity_min'], b: $resolved['quantity_max']) > 0) {
                $reason = Text::sprintf(
                    'COM_ALFA_ITEM_QUANTITY_MIN_ABOVE_MAX',
                    QuantityDecimal::display(a: $resolved['quantity_min']),
                    QuantityDecimal::display(a: $resolved['quantity_max']),
                );
            } elseif (CartQuantityPolicy::saleMinimum(
                min: $resolved['quantity_min'],
                step: $resolved['quantity_step'],
                max: $resolved['quantity_max'],
            ) === null) {
                $reason = Text::sprintf(
                    'COM_ALFA_ITEM_QUANTITY_RULES_UNORDERABLE',
                    QuantityDecimal::display(a: $resolved['quantity_min']),
                    QuantityDecimal::display(a: $resolved['quantity_step']),
                    $limited
                        ? QuantityDecimal::display(a: $resolved['quantity_max'])
                        : Text::_('COM_ALFA_ITEM_QUANTITY_NO_MAXIMUM'),
                );
            }
        }

        if ($reason === null) {
            return $changed;
        }

        throw new RuntimeException(message: Text::sprintf(
            'COM_ALFA_COMBI_QUANTITY_RULES_REFUSED',
            self::escapedText(text: InventoryUnitException::labelFor(itemId: $itemId, combinationId: $combinationId)),
            $reason,
        ));
    }

    /**
     * Whether a Studio save changes the combination's backorder action or its
     * safety stock: with its quantity rules, the overrides that decide which
     * quantities can be sold.
     *
     * @param object $stored The combination's stored row.
     * @param object $update The columns the save writes, already cast.
     *
     *
     * @since  2.0.0
     */
    private static function saleOverridesChanged(object $stored, object $update): bool
    {
        if (property_exists($update, 'backorder')
            && $update->backorder !== ($stored->backorder === null ? null : (string) $stored->backorder)
        ) {
            return true;
        }

        if (!property_exists($update, 'min_qty')) {
            return false;
        }

        $before = $stored->min_qty === null ? null : (float) $stored->min_qty;
        $after = $update->min_qty === null ? null : (float) $update->min_qty;

        if ($before === null || $after === null) {
            return $before !== $after;
        }

        return !InventoryAvailabilityService::quantitiesAgree(a: $before, b: $after);
    }

    /**
     * Load everything the "Fields & combinations" popup renders: the
     * item-category-scoped field definitions (current-language names), each
     * with its value pool, the item's informational values and option rows.
     *
     * @param int $itemId The item being edited (0 = new).
     * @param array $categoryIds The item's category ids (field scope).
     *
     * @return array Field descriptor objects.
     *
     * @since  2.0.0
     */
    public static function getFieldsPopupData(int $itemId, array $categoryIds): array
    {
        $cacheKey = $itemId . '|' . implode(',', array_unique(array_map('intval', $categoryIds)));

        if (isset(self::$fieldsPopupCache[$cacheKey])) {
            return self::$fieldsPopupCache[$cacheKey];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $categoryIds = self::expandWithAncestors(categoryIds: array_map('intval', $categoryIds));

        // Published fields, scoping respected: no scoping rows = global. The
        // per-item GROUP order (item_field_overrides.ordering, set by dragging the
        // option/info group headers) is folded into the SAME query via a LEFT JOIN
        // (no extra query) and drives the sort, falling back to the global field
        // ordering when the item never reordered its groups.
        $query = $db->getQuery(true)
            ->select('f.*')
            ->select('ovr.ordering AS item_ordering')
            ->from($db->qn('#__alfa_item_fields', 'f'))
            ->join(
                'LEFT',
                $db->qn('#__alfa_item_field_overrides', 'ovr')
                . ' ON ovr.field_id = f.id AND ovr.item_id = ' . (int) $itemId,
            )
            ->where('f.state = 1')
            ->order('COALESCE(ovr.ordering, f.ordering) ASC, f.ordering ASC');

        $scope = 'NOT EXISTS (SELECT 1 FROM ' . $db->qn('#__alfa_item_field_categories', 'sc')
            . ' WHERE sc.item_field_id = f.id)';

        if ($categoryIds) {
            $scope .= ' OR EXISTS (SELECT 1 FROM ' . $db->qn('#__alfa_item_field_categories', 'sc2')
                . ' WHERE sc2.item_field_id = f.id AND sc2.category_id IN (' . implode(',', $categoryIds) . '))';
        }

        $query->where('(' . $scope . ')');

        $db->setQuery($query);
        $fields = $db->loadObjectList('id') ?: [];

        // Everything this item has already committed to: a dimension of a saved
        // combination, an offered option, a printed spec. Unpublishing a field or
        // re-scoping it away from these categories must NOT blank rows that are
        // already saved: the query above drops such a field and the tab then
        // claims "no item fields" above six live combinations. They are loaded
        // here regardless of state and scope and flagged `retired`, which is what
        // keeps the generator and the value pickers from offering them again.
        $usedValueIds = self::usedValueIds($itemId);
        $retiredIds = $usedValueIds ? array_diff(self::fieldIdsOfValues($usedValueIds), array_keys($fields)) : [];

        if ($retiredIds) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select('f.*')
                    ->select('ovr.ordering AS item_ordering')
                    ->from($db->qn('#__alfa_item_fields', 'f'))
                    ->join(
                        'LEFT',
                        $db->qn('#__alfa_item_field_overrides', 'ovr')
                        . ' ON ovr.field_id = f.id AND ovr.item_id = ' . (int) $itemId,
                    )
                    ->whereIn($db->qn('f.id'), array_map('intval', $retiredIds))
                    ->order('COALESCE(ovr.ordering, f.ordering) ASC, f.ordering ASC'),
            );

            foreach ($db->loadObjectList('id') ?: [] as $id => $field) {
                $field->retired = true;
                $fields[$id] = $field;
            }
        }

        if (!$fields) {
            return self::$fieldsPopupCache[$cacheKey] = [];
        }

        $fieldIds = array_map('intval', array_keys($fields));

        foreach ($fields as $field) {
            $field->retired = !empty($field->retired);
            $field->display_name = $field->field_name;
            $field->params_decoded = is_string($field->params) ? (json_decode($field->params, true) ?: []) : [];
            $field->values = [];
            $field->selected_value_ids = [];
            $field->option_rows = [];
        }

        // Field display names from the one resolver the storefront and the
        // order snapshot also read.
        foreach (ItemFieldDisplayResolver::fieldLabelMaps($fieldIds) as $id => $map) {
            $resolved = MultilingualHelper::resolveLangMap($map);

            if ($resolved !== '' && isset($fields[$id])) {
                $fields[$id]->display_name = $resolved;
            }
        }

        // Value pools.
        $db->setQuery(
            $db->getQuery(true)
                ->select('v.id, v.item_field_id, v.params, v.state')
                ->from($db->qn('#__alfa_item_field_values', 'v'))
                ->whereIn($db->qn('v.item_field_id'), $fieldIds)
                ->where(
                    $usedValueIds
                        ? '(v.state = 1 OR v.id IN (' . implode(',', $usedValueIds) . '))'
                        : 'v.state = 1',
                )
                ->order('v.ordering ASC'),
        );
        $values = $db->loadObjectList('id') ?: [];

        foreach ($values as $value) {
            $value->display_name = '#' . $value->id;
            $value->in_use = in_array((int) $value->id, $usedValueIds, true);
            $value->retired = (int) $value->state !== 1;
        }

        if ($values) {
            foreach (ItemFieldDisplayResolver::valueTextMaps(array_map('intval', array_keys($values))) as $id => $map) {
                $resolved = MultilingualHelper::resolveLangMap($map);

                if ($resolved !== '' && isset($values[$id])) {
                    $values[$id]->display_name = $resolved;
                }
            }
        }

        foreach ($values as $value) {
            $field = $fields[(int) $value->item_field_id];

            // A retired field keeps exactly the values its rows depend on: enough
            // to render what is saved, never enough to build something new.
            if ($field->retired && !$value->in_use) {
                continue;
            }

            $field->values[] = $value;
        }

        if ($itemId > 0) {
            // Informational values of this item, in per-item drag order.
            $db->setQuery(
                $db->getQuery(true)
                    ->select('value_id')
                    ->from($db->qn('#__alfa_item_info_values'))
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->order($db->qn('ordering') . ' ASC'),
            );
            $selected = array_map('intval', $db->loadColumn() ?: []);

            foreach ($selected as $valueId) {
                if (isset($values[$valueId])) {
                    $fields[(int) $values[$valueId]->item_field_id]->selected_value_ids[] = $valueId;
                }
            }

            // Option rows of this item (offered value + optional price delta).
            // The row id is the 'option' scope id for field plugin config.
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn(['id', 'value_id', 'operation', 'amount', 'apply_on']))
                    ->from($db->qn('#__alfa_item_option_values'))
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->order($db->qn('ordering') . ' ASC'),
            );

            foreach ($db->loadObjectList() ?: [] as $optionRow) {
                $valueId = (int) $optionRow->value_id;

                if (isset($values[$valueId])) {
                    $fields[(int) $values[$valueId]->item_field_id]->option_rows[$valueId] = $optionRow;
                }
            }
        }

        return self::$fieldsPopupCache[$cacheKey] = array_values($fields);
    }

    /**
     * Value ids this item has already committed to: every dimension of a saved
     * combination, every offered option row and every printed spec.
     *
     * These are the rows a merchant can see on the item, so they must keep
     * rendering after the field or the value behind them is unpublished or
     * re-scoped. Anything reached only through this list is `retired`: shown,
     * never offered.
     *
     * @param int $itemId The item being edited (0 = new, nothing committed).
     *
     * @return int[] Unique value ids, unordered.
     *
     * @since   2.0.0
     */
    private static function usedValueIds(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            'SELECT cv.' . $db->qn('value_id') . ' FROM ' . $db->qn('#__alfa_item_combination_values', 'cv')
            . ' INNER JOIN ' . $db->qn('#__alfa_item_combinations', 'c')
            . ' ON c.id = cv.combination_id AND c.item_id = ' . $itemId
            . ' UNION SELECT ' . $db->qn('value_id') . ' FROM ' . $db->qn('#__alfa_item_info_values')
            . ' WHERE ' . $db->qn('item_id') . ' = ' . $itemId
            . ' UNION SELECT ' . $db->qn('value_id') . ' FROM ' . $db->qn('#__alfa_item_option_values')
            . ' WHERE ' . $db->qn('item_id') . ' = ' . $itemId,
        );

        return array_map('intval', $db->loadColumn() ?: []);
    }

    /**
     * The fields the given values belong to.
     *
     * @param int[] $valueIds Value ids.
     *
     * @return int[] Field ids, unique, unordered.
     *
     * @since   2.0.0
     */
    private static function fieldIdsOfValues(array $valueIds): array
    {
        if (!$valueIds) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT ' . $db->qn('item_field_id'))
                ->from($db->qn('#__alfa_item_field_values'))
                ->whereIn($db->qn('id'), array_map('intval', $valueIds)),
        );

        return array_map('intval', $db->loadColumn() ?: []);
    }

    /**
     * Expand category ids with all their ancestors (a field scoped to
     * "Clothing" applies to items in "Clothing > Shirts"). Admin-side walk
     * over parent_id; CategoryHelper::getDescendantIds is Site-only.
     *
     * @param array $categoryIds The item's category ids.
     *
     * @return array Unique ids including every ancestor.
     *
     * @since  2.0.0
     */
    protected static function expandWithAncestors(array $categoryIds): array
    {
        $categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));

        if (!$categoryIds) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn(['id', 'parent_id']))
                ->from($db->qn('#__alfa_categories')),
        );
        // Whole rows by id: a column asked of loadAssocList() answers the whole row when the
        // column is NULL, and a NULL parent is a category with no parent.
        $parents = $db->loadAssocList(key: 'id') ?: [];

        $expanded = [];

        foreach ($categoryIds as $categoryId) {
            $guard = 0;

            while ($categoryId > 0 && !isset($expanded[$categoryId]) && $guard++ < 100) {
                $expanded[$categoryId] = true;
                $categoryId = (int) ($parents[$categoryId]['parent_id'] ?? 0);
            }
        }

        return array_map('intval', array_keys($expanded));
    }

    /**
     * Generate combinations EXPLICITLY from a chosen subset of values:
     * the admin picks which values per variant field to
     * cross, presses Generate, and ONLY the missing tuples are created:
     * additive and idempotent, so repeated generation never duplicates and
     * nothing is ever auto-deleted or auto-unpublished. Combinations exist
     * only because the admin asked for them; unwanted ones are deleted from
     * the grid. The payload is validated against the item's variant fields
     * and OFFERED values; unknown ids are silently dropped.
     *
     * Rows are matched by signature (sorted value ids joined by '-') against
     * the item's current combinations; archived ones are not part of the
     * catalogue. Exactly one published combination ends up default_on.
     *
     * Creating combinations moves the product's sellable stock onto them, so a
     * generation that creates anything runs in one transaction that first locks
     * the product's stock records and refuses while the product itself still
     * holds stock (on hand or reserved at any location, an open reservation, a
     * pending stock effect), while an open order line sold on the product
     * itself still has stock to deduct from it, or while its stock figures
     * disagree with its stock records. The refusal names each such order line.
     * Nothing is written off or moved: the operator resolves the
     * product's own stock first. New combination rows carry no stock figures;
     * their stock exists only once the inventory service records some.
     *
     * A wanted signature that an archived combination of the item still
     * carries (a combination deleted while order history named it) brings that
     * same row back: the inventory service publishes it again with its stock
     * records, and its value links and position are written as for a new row.
     * A second row with the same signature is never created.
     *
     * An archived product is kept only for its order history and is refused
     * before any work. The product's state is read again with a lock as the
     * last statement before the commit, so an archive that commits while the
     * generation runs is always seen and the whole generation rolls back.
     *
     * @param int $itemId The item id.
     * @param array $valuesByField [field_id => [value_id, ...]] chosen in the generator panel.
     *
     * @return int Number of combinations created or brought back from the archive.
     *
     * @throws RuntimeException With a merchant-readable message when generation is refused.
     *
     * @since  2.0.0
     */
    public static function generateCombinations(int $itemId, array $valuesByField): int
    {
        if ($itemId <= 0 || !$valuesByField) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // An archived product is kept only for its order history: it takes no new
        // combination, and none of its archived combinations comes back.
        if (!CombinationState::isCurrent(state: self::itemState(itemId: $itemId, lock: false))) {
            throw new RuntimeException(message: Text::_('COM_ALFA_COMBI_GENERATE_ITEM_ARCHIVED'));
        }

        // Eligible dimensions: published, NON-informational fields scoped to
        // the item's categories; no assignment step exists: a field is a
        // variant dimension BECAUSE combinations use it.
        // The payload decides which fields participate, so a Size-only
        // generation is legitimate.
        $eligibleFieldIds = self::getEligibleChoiceFieldIds(itemId: $itemId);

        // Honour the payload's field order (the generator popup lets the admin drag
        // whole fields to set the DIMENSION order) while still restricting to the
        // item's eligible variant fields. array_intersect keeps the FIRST array's
        // order, so the payload (DOM) order leads, not the default field ordering.
        $participating = array_values(array_intersect(
            array_map('intval', array_keys($valuesByField)),
            $eligibleFieldIds,
        ));

        $existing = self::loadCombinationRows(itemId: $itemId);

        if (!$participating) {
            return 0;
        }

        // A field already serving as an OPTION cannot also be a dimension.
        $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT v.item_field_id')
                ->from($db->qn('#__alfa_item_option_values', 'ov'))
                ->join('INNER', $db->qn('#__alfa_item_field_values', 'v') . ' ON v.id = ov.value_id')
                ->where('ov.item_id = ' . $itemId),
        );
        $optionFieldIds = array_map('intval', $db->loadColumn() ?: []);

        if (array_intersect($participating, $optionFieldIds)) {
            throw new RuntimeException(Text::_('COM_ALFA_COMBI_FIELD_IS_OPTION'));
        }

        // DEPTH LOCK: every combination of an item lives on the SAME
        // dimension set. With existing combinations the chosen field set must
        // match it EXACTLY (no Size-only rows next to Size*Color rows); to
        // change dimensions, delete all combinations and generate afresh.
        if ($existing) {
            $existingFieldIds = self::getCombinationFieldIds(itemId: $itemId);
            $chosenFieldIds = $participating;
            sort($existingFieldIds);
            sort($chosenFieldIds);

            if ($existingFieldIds && $chosenFieldIds !== $existingFieldIds) {
                throw new RuntimeException(Text::_('COM_ALFA_COMBI_GENERATE_DEPTH_LOCK'));
            }
        }

        // The full published POOL of each variant field; for variant fields
        // the combinations themselves are the source of truth for what is
        // sellable, so generation picks from the whole
        // pool, not a per-item "offered" subset.
        $db->setQuery(
            $db->getQuery(true)
                ->select('v.id, v.item_field_id')
                ->from($db->qn('#__alfa_item_field_values', 'v'))
                ->whereIn($db->qn('v.item_field_id'), $participating)
                ->where('v.state = 1')
                ->order('v.ordering ASC'),
        );
        $pool = $db->loadObjectList() ?: [];

        $poolPerField = array_fill_keys($participating, []);

        foreach ($pool as $value) {
            $poolPerField[(int) $value->item_field_id][] = (int) $value->id;
        }

        // The chosen subset, validated: only variant fields, only published
        // pool values. Every variant field needs at least one chosen value or
        // the cross-product is meaningless; abort quietly.
        $perField = [];

        foreach ($poolPerField as $fieldId => $poolIds) {
            $chosen = array_map('intval', (array) ($valuesByField[$fieldId] ?? []));
            $perField[$fieldId] = array_values(array_intersect($chosen, $poolIds));

            if (!$perField[$fieldId]) {
                return 0;
            }
        }

        // Cross-product.
        $tuples = [[]];

        foreach ($perField as $valueIds) {
            $next = [];

            foreach ($tuples as $tuple) {
                foreach ($valueIds as $valueId) {
                    $next[] = array_merge($tuple, [$valueId]);
                }
            }

            $tuples = $next;
        }

        $wantedSignatures = [];

        foreach ($tuples as $tuple) {
            $sorted = $tuple;
            sort($sorted);
            $wantedSignatures[implode('-', $sorted)] = $tuple;
        }

        // Inserts: signatures without a row yet (existing ones are untouched
        // - additive generation has no orphan concept).
        $existingBySignature = [];

        foreach ($existing as $combination) {
            $existingBySignature[$combination->signature] = $combination;
        }

        $missing = array_diff_key($wantedSignatures, $existingBySignature);

        if ($missing === []) {
            self::ensureOnePublishedDefault(itemId: $itemId);

            return 0;
        }

        $inventory = new InventoryService();
        $created = 0;

        $db->transactionStart(true);

        try {
            // The product's stock records are locked first (the inventory lock
            // order), so no stock can land on the product itself between the
            // check and the inserts.
            $inventory->lockItemStock(itemId: $itemId);
            $inventory->assertUnitsClear(itemId: $itemId, combinationIds: [0]);
            $inventory->assertProjectionsAgree(itemId: $itemId);

            // Wanted signatures an archived combination of the item still
            // carries: the inventory service brings those rows back (published,
            // with their stock records) instead of a duplicate signature.
            $archived = array_intersect_key(self::loadArchivedBySignature(itemId: $itemId), $missing);

            if ($archived !== []) {
                $inventory->reactivateUnits(
                    itemId: $itemId,
                    combinationIds: array_map(static fn (object $row): int => (int) $row->id, array_values($archived)),
                    context: InventoryContext::ambient(),
                );
            }

            $ordering = count($existing);
            $userId = (int) Factory::getApplication()->getIdentity()?->id;

            foreach ($missing as $signature => $tuple) {
                if (isset($archived[$signature])) {
                    $combinationId = (int) $archived[$signature]->id;

                    // updateObject() takes the row by reference, so it is held in a variable.
                    $reactivated = (object) [
                        'id' => $combinationId,
                        'ordering' => $ordering++,
                        'modified_by' => $userId,
                    ];

                    $db->updateObject(table: '#__alfa_item_combinations', object: $reactivated, key: 'id');

                    // Links are written afresh from the tuple, as for a new row.
                    $db->setQuery(
                        $db->getQuery(true)
                            ->delete($db->qn('#__alfa_item_combination_values'))
                            ->where($db->qn('combination_id') . ' = ' . $combinationId),
                    );
                    $db->execute();
                } else {
                    $row = (object) [
                        'item_id' => $itemId,
                        'signature' => $signature,
                        'sku' => '',
                        'gtin' => '',
                        'mpn' => '',
                        'default_on' => 0,
                        'weight' => null,
                        'width' => null,
                        'height' => null,
                        'depth' => null,
                        'state' => 1,
                        'ordering' => $ordering++,
                        'created_by' => $userId,
                        'modified_by' => 0,
                    ];
                    $db->insertObject('#__alfa_item_combinations', $row, 'id');
                    $combinationId = (int) $row->id;
                }

                foreach ($tuple as $valueId) {
                    $linkRow = (object) [
                        'combination_id' => $combinationId,
                        'value_id' => (int) $valueId,
                    ];
                    $db->insertObject('#__alfa_item_combination_values', $linkRow);
                }

                $created++;
            }

            self::ensureOnePublishedDefault(itemId: $itemId);

            // The last read before the commit, with a lock: an archive of the
            // product that committed while this generation ran is seen here, and
            // the generation is undone whole.
            if (!CombinationState::isCurrent(state: self::itemState(itemId: $itemId, lock: true))) {
                throw new RuntimeException(message: Text::_('COM_ALFA_COMBI_GENERATE_ITEM_ARCHIVED'));
            }

            TransactionState::commitLevel(db: $db);
        } catch (InventoryUnitRetirementException $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            // The generator shows its refusal as plain text, not markup.
            throw new RuntimeException(
                self::stockHeldText(itemId: $itemId, key: 'COM_ALFA_COMBI_GENERATE_PARENT_STOCK', blockers: $e->blockers, markup: false),
                0,
                $e,
            );
        } catch (InventoryProjectionMismatchException $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw new RuntimeException(Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE'), 0, $e);
        } catch (InventoryUnitException $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            // The product was archived before its archived combinations could come back.
            if ($e->reason === InventoryUnitException::ITEM_ARCHIVED) {
                throw new RuntimeException(message: Text::_('COM_ALFA_COMBI_GENERATE_ITEM_ARCHIVED'), previous: $e);
            }

            throw $e;
        } catch (Throwable $e) {
            // After a deadlock the whole transaction is gone, on the server and
            // in the driver's count.
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return $created;
    }

    /**
     * Leave exactly one published combination of the item marked default_on:
     * the current one when there is exactly one, otherwise the first published
     * combination in the variant order (ordering, then id), so combinations
     * that share a position never leave the choice to the database.
     *
     * @param int $itemId The item id.
     *
     *
     * @since  2.0.0
     */
    private static function ensureOnePublishedDefault(int $itemId): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn('id'))
                ->from($db->qn('#__alfa_item_combinations'))
                ->where($db->qn('item_id') . ' = ' . $itemId)
                ->where($db->qn('state') . ' = 1')
                ->where($db->qn('default_on') . ' = 1')
                ->order($db->qn('ordering') . ' ASC, ' . $db->qn('id') . ' ASC'),
        );
        $defaults = array_map('intval', $db->loadColumn() ?: []);

        if (count($defaults) !== 1) {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->qn('#__alfa_item_combinations'))
                    ->set($db->qn('default_on') . ' = 0')
                    ->where($db->qn('item_id') . ' = ' . $itemId),
            );
            $db->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn('id'))
                    ->from($db->qn('#__alfa_item_combinations'))
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->where($db->qn('state') . ' = 1')
                    ->order($db->qn('ordering') . ' ASC, ' . $db->qn('id') . ' ASC')
                    ->setLimit(1),
            );
            $firstId = (int) $db->loadResult();

            if ($firstId > 0) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->qn('#__alfa_item_combinations'))
                        ->set($db->qn('default_on') . ' = 1')
                        ->where($db->qn('id') . ' = ' . $firstId),
                );
                $db->execute();
            }
        }
    }

    /**
     * The item's archived combinations by signature: rows kept only as the
     * stock identity of order history, which generation brings back when the
     * same signature is wanted again.
     *
     * @param int $itemId The item id.
     *
     * @return array<string, object> signature => row {id, signature}.
     *
     * @since  2.0.0
     */
    private static function loadArchivedBySignature(int $itemId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn(['id', 'signature']))
                ->from($db->qn('#__alfa_item_combinations'))
                ->where($db->qn('item_id') . ' = ' . $itemId)
                ->where($db->qn('state') . ' = ' . CombinationState::ARCHIVED),
        );

        $bySignature = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $bySignature[(string) $row->signature] = $row;
        }

        return $bySignature;
    }

    /**
     * A product's catalogue state. The locking read, taken inside a transaction,
     * waits for a concurrent change of the product row to commit and reads what
     * it committed.
     *
     * @param int $itemId The product.
     * @param bool $lock Read with a lock (inside a transaction).
     *
     * @return int|null Null when the product has no row.
     *
     * @since  2.0.0
     */
    private static function itemState(int $itemId, bool $lock): ?int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $state = $db->setQuery(
            'SELECT ' . $db->qn('state')
            . ' FROM ' . $db->qn('#__alfa_items')
            . ' WHERE ' . $db->qn('id') . ' = ' . $itemId
            . ($lock ? ' FOR UPDATE' : ''),
        )->loadResult();

        return $state === null ? null : (int) $state;
    }

    /**
     * The item's category ids (raw assignment, no ancestor expansion).
     *
     * @param int $itemId The item id.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public static function getItemCategoryIds(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn('category_id'))
                ->from($db->qn('#__alfa_items_categories'))
                ->where($db->qn('item_id') . ' = ' . $itemId),
        );

        return array_map('intval', $db->loadColumn() ?: []);
    }

    /**
     * Published, NON-informational fields scoped to the item's categories:
     * the candidate dimensions/options of this item. Every field is uniform
     * (each has at least one value), so eligibility is the category scope
     * alone; no field-type taxonomy.
     *
     * @param int $itemId The item id.
     *
     * @return int[] Field ids in definition order.
     *
     * @since  2.0.0
     */
    public static function getEligibleChoiceFieldIds(int $itemId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $categoryIds = self::expandWithAncestors(
            categoryIds: self::getItemCategoryIds(itemId: $itemId),
        );

        $query = $db->getQuery(true)
            ->select($db->qn('f.id'))
            ->from($db->qn('#__alfa_item_fields', 'f'))
            ->where('f.state = 1')
            ->where('f.informational = 0')
            ->order('f.ordering ASC');

        $scope = 'NOT EXISTS (SELECT 1 FROM ' . $db->qn('#__alfa_item_field_categories', 'sc')
            . ' WHERE sc.item_field_id = f.id)';

        if ($categoryIds) {
            $scope .= ' OR EXISTS (SELECT 1 FROM ' . $db->qn('#__alfa_item_field_categories', 'sc2')
                . ' WHERE sc2.item_field_id = f.id AND sc2.category_id IN (' . implode(',', $categoryIds) . '))';
        }

        $query->where('(' . $scope . ')');
        $db->setQuery($query);

        $ids = array_map('intval', $db->loadColumn() ?: []);

        return $ids;
    }

    /**
     * ADD option values (the picker popup, generator-style): only MISSING
     * rows are inserted, with no delta: additive and idempotent. Deltas are
     * edited inline in the Options list and persist with the item save;
     * removal happens in the list too (deferred, like the variants grid).
     *
     * @param int $itemId The item id.
     * @param array $valueIds Picked pool value ids.
     *
     * @return int Number of option rows created.
     *
     * @since  2.0.0
     */
    public static function addItemOptions(int $itemId, array $valueIds): int
    {
        if ($itemId <= 0 || !$valueIds) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $eligible = array_diff(
            self::getEligibleChoiceFieldIds(itemId: $itemId),
            self::getCombinationFieldIds(itemId: $itemId),
        );

        if (!$eligible) {
            return 0;
        }

        // Validate values: published, of an eligible non-dimension field.
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn('id'))
                ->from($db->qn('#__alfa_item_field_values'))
                ->whereIn($db->qn('id'), array_map('intval', $valueIds))
                ->whereIn($db->qn('item_field_id'), $eligible)
                ->where($db->qn('state') . ' = 1'),
        );
        $validIds = array_map('intval', $db->loadColumn() ?: []);

        if (!$validIds) {
            return 0;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn('value_id'))
                ->from($db->qn('#__alfa_item_option_values'))
                ->where($db->qn('item_id') . ' = ' . $itemId),
        );
        $existing = array_map('intval', $db->loadColumn() ?: []);

        // Insert in the PICKED order (the popup posts values in drag order), so a
        // value dragged before another is created before it; its ordering follows.
        $validSet = array_flip($validIds);
        $existingSet = array_flip($existing);
        $added = 0;

        foreach ($valueIds as $valueId) {
            $valueId = (int) $valueId;

            if ($valueId <= 0 || !isset($validSet[$valueId]) || isset($existingSet[$valueId])) {
                continue;
            }

            $optionRow = (object) [
                'item_id' => $itemId,
                'value_id' => $valueId,
                'operation' => null,
                'amount' => null,
                'ordering' => count($existing) + $added,
            ];
            $db->insertObject('#__alfa_item_option_values', $optionRow);
            $added++;
        }

        return $added;
    }

    /**
     * Persist a field's per-item ShowOn (visibility) rule, MERGED into its existing
     * item-scoped config so a plugin's own keys (e.g. the configurator's areas)
     * survive. An empty / cleared rule drops the key; the field then falls back to
     * its global field-scoped rule, or to always-visible. The frontend reads the
     * merged effective rule via {@see \Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper}.
     *
     * @param int $itemId The owning item id.
     * @param int $fieldId The item-field id.
     * @param string $rule The builder's canonical rule JSON ('' clears it).
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public static function saveItemShowon(int $itemId, int $fieldId, string $rule): bool
    {
        if ($itemId <= 0 || $fieldId <= 0) {
            return false;
        }

        $config = FieldParams::read(scope: FieldParams::SCOPE_ITEM, scopeId: $itemId, fieldId: $fieldId)->toArray();
        $rule = trim($rule);

        if ($rule === '' || $rule === '{}' || $rule === 'null') {
            unset($config['showon']);
        } else {
            $config['showon'] = $rule;
        }

        return FieldParams::write(
            scope: FieldParams::SCOPE_ITEM,
            scopeId: $itemId,
            fieldId: $fieldId,
            itemId: $itemId,
            config: $config,
        );
    }

    /**
     * Assemble the visibility popup's data for ONE field: the field row (used to
     * scope the builder's operands + exclude self) and its stored item-scoped rule.
     * Mirrors {@see self::getFieldsPopupData}; the controller stays thin.
     *
     * @param int $itemId The owning item id.
     * @param int $fieldId The item-field id whose visibility is being edited.
     *
     * @return array|null ['field' => object, 'itemId' => int, 'rule' => string], or null when absent.
     *
     * @since  2.0.0
     */
    public static function getShowonPopupData(int $itemId, int $fieldId): ?array
    {
        $allFields = self::getFieldsPopupData(itemId: $itemId, categoryIds: self::getItemCategoryIds(itemId: $itemId));
        $field = null;

        foreach ($allFields as $candidate) {
            if ((int) $candidate->id === $fieldId) {
                $field = $candidate;

                break;
            }
        }

        if ($field === null) {
            return null;
        }

        $itemRule = (string) FieldParams::read(scope: FieldParams::SCOPE_ITEM, scopeId: $itemId, fieldId: $fieldId)->get('showon', '');
        $globalRule = (string) ($field->params_decoded['showon'] ?? '');
        $isRule = static fn (string $rule): bool => $rule !== '' && $rule !== '{}' && $rule !== 'null' && $rule !== '[]';

        return [
            'field' => $field,
            'itemId' => $itemId,
            'rule' => $itemRule,
            // The global field-definition rule, summarised server-side (read-only on
            // top of the popup). 'overrides' = an item rule is also set, so the label
            // reads "Overrides" rather than "Inherits".
            'inheritedSummary' => $isRule($globalRule) ? ShowonSummary::forItemFields(rule: $globalRule, fields: $allFields) : '',
            'overrides' => $isRule($itemRule) && $isRule($globalRule),
        ];
    }

    /**
     * The ShowOn state of each field for the visibility buttons, in two batched
     * queries (item-scoped overrides global field-scoped):
     *   - 'set'       an item-scoped rule exists here,
     *   - 'inherited' only a global field-definition rule applies,
     *   - 'none'      no rule anywhere.
     *
     *
     * @return array<int, string> field id => 'set' | 'inherited' | 'none'.
     *
     * @since  2.0.0
     */
    /**
     * Per-field GLOBAL (field-definition) ShowOn summaries for the item-fields list,
     * in one batched value-label query. Only fields that carry a rule appear in the
     * result. Operand labels come from the page's own fields (an off-page operand
     * falls back to its machine name).
     *
     * @param object[] $fields The list rows (carry ->id, ->field_name, ->name, ->params).
     *
     * @return array<int, string> field id => human summary.
     *
     * @since  2.0.0
     */
    public static function getGlobalShowonSummaries(array $fields): array
    {
        // Item-field rules compare value-pool ids; gather every id referenced and
        // load its label, then hand the shared renderer the id -> label map. (Form
        // fields enter values by hand and pass none; ShowonSummary::globalSummaries
        // then prints them as-is.)
        $valueIds = [];

        foreach ($fields as $field) {
            $params = \is_string($field->params ?? null) ? (json_decode($field->params, true) ?: []) : [];
            $rule = (string) ($params['showon'] ?? '');

            if ($rule !== '' && $rule !== '{}' && $rule !== 'null' && $rule !== '[]') {
                self::collectRuleValueIds($rule, $valueIds);
            }
        }

        return ShowonSummary::globalSummaries($fields, self::loadValueLabels(array_keys($valueIds)));
    }

    /** Batch-load active-language labels for value ids (id => label, '#id' fallback). */
    private static function loadValueLabels(array $valueIds): array
    {
        $valueIds = array_values(array_unique(array_map('intval', array_filter($valueIds))));

        if (!$valueIds) {
            return [];
        }

        $labels = [];

        foreach ($valueIds as $id) {
            $labels[$id] = '#' . $id;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $tag = strtolower(str_replace('-', '_', Factory::getApplication()->getLanguage()->getTag()));

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->qn('id_item_field_value'), $db->qn('name')])
                    ->from($db->qn('#__alfa_item_field_values_' . $tag))
                    ->whereIn($db->qn('id_item_field_value'), $valueIds),
            );

            foreach ($db->loadObjectList() ?: [] as $row) {
                if ((string) $row->name !== '') {
                    $labels[(int) $row->id_item_field_value] = (string) $row->name;
                }
            }
        } catch (Throwable $e) {
            // Per-language aux table pending resync; keep the '#id' fallbacks.
        }

        return $labels;
    }

    /** Collect the value ids a rule references (for batched label loading). */
    private static function collectRuleValueIds($rule, array &$ids): void
    {
        $rule = \is_array($rule) ? $rule : (json_decode((string) $rule, true) ?: []);

        foreach ($rule['rules'] ?? [] as $child) {
            if (!\is_array($child)) {
                continue;
            }

            if (isset($child['rules'])) {
                self::collectRuleValueIds($child, $ids);

                continue;
            }

            $value = $child['value'] ?? null;

            foreach (\is_array($value) ? $value : [$value] as $one) {
                if (is_numeric($one)) {
                    $ids[(int) $one] = true;
                }
            }
        }
    }

    /**
     * Names of item-field values for the builder, each only for the field its operand names
     * (<field_name>.value), in the active language, whatever the value's publish state. An id of
     * another field, and a value with no name in the active language, are left out.
     *
     * @param array<string, int[]> $idsByOperand Operand => value ids.
     *
     * @return array<string, array<int, string>> Operand => [value id => name].
     *
     * @since  2.0.0
     */
    public static function valueNamesByOperand(array $idsByOperand): array
    {
        if ($idsByOperand === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $tag = strtolower(str_replace('-', '_', Factory::getApplication()->getLanguage()->getTag()));

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->qn('v.id'), $db->qn('f.field_name'), $db->qn('l.name')])
                ->from($db->qn('#__alfa_item_field_values', 'v'))
                ->join('INNER', $db->qn('#__alfa_item_fields', 'f'), $db->qn('f.id') . ' = ' . $db->qn('v.item_field_id'))
                ->join('INNER', $db->qn('#__alfa_item_field_values_' . $tag, 'l'), $db->qn('l.id_item_field_value') . ' = ' . $db->qn('v.id'))
                ->whereIn($db->qn('v.id'), array_values(array_unique(array_merge(...array_values($idsByOperand))))),
        )->loadObjectList() ?: [];

        $names = [];

        foreach ($rows as $row) {
            $operand = (string) $row->field_name . '.value';
            $id = (int) $row->id;

            if ((string) $row->name !== '' && \in_array($id, $idsByOperand[$operand] ?? [], true)) {
                $names[$operand][$id] = (string) $row->name;
            }
        }

        return $names;
    }

    /**
     * Persist the per-item REQUIRED overrides posted by the Variants tab:
     * '' removes the override (inherit the global flag), '0'/'1' pin it.
     *
     * @param int $itemId The item.
     * @param array $payload jform[field_required]: fieldId => ''|'0'|'1'.
     *
     *
     * @since  2.0.0
     */
    public static function saveItemFieldRequired(int $itemId, array $payload): void
    {
        foreach ($payload as $fieldId => $value) {
            $fieldId = (int) $fieldId;
            $value = (string) $value;
            $config = FieldParams::read(FieldParams::SCOPE_ITEM, $itemId, $fieldId)->toArray();

            if ($value === '') {
                if (!\array_key_exists('required', $config)) {
                    continue; // nothing stored, nothing to remove
                }

                unset($config['required']);
            } else {
                $config['required'] = $value === '1' ? '1' : '0';
            }

            FieldParams::write(FieldParams::SCOPE_ITEM, $itemId, $fieldId, $itemId, $config);
        }
    }

    public static function getShowonDisplay(int $itemId, array $fields, array $allFields = [], ?array $presentFieldNames = null, ?array $roleRank = null): array
    {
        if ($itemId <= 0 || !$fields) {
            return [];
        }

        // The full eligible set gives the summary its operand/value labels.
        $labelFields = $allFields ?: $fields;

        // "Present on this item" = a field that actually CONTRIBUTES values here:
        // an option row, an informational pick or a combination (variant) value,
        // exactly the frontend's deriveFields set. A field merely category-eligible
        // but unconfigured on this item is NOT present, so a rule gating on it is
        // dangling: the frontend drops it (the field always shows) and the badge
        // must read "Always shown", never an active "Global".
        //
        // The page already knows this set (option rows, info picks, combination
        // field ids), so it passes it in: no query on the render path. The save
        // controller has no such context and derives it from one combination query.
        $comboValuesByField = null;

        if ($presentFieldNames !== null) {
            $itemFieldNames = array_fill_keys($presentFieldNames, true);
        } else {
            $comboValuesByField = self::getItemCombinationValueIdsByField(itemId: $itemId);
            $itemFieldNames = [];

            foreach ($labelFields as $labelField) {
                $isConfigured = !empty($labelField->option_rows)
                    || !empty($labelField->selected_value_ids)
                    || !empty($comboValuesByField[(int) $labelField->id]);

                if ($isConfigured) {
                    $itemFieldNames[(string) ($labelField->field_name ?? '')] = true;
                }
            }
        }

        // Field role + render-order rank (variation -> option -> informational, then
        // per-item display order) for the ShowOn forward-only guard below. On the
        // render path the page passes it in (no query); the save-back path derives
        // it from the one combination lookup it already makes.
        if ($roleRank === null) {
            $comboValuesByField ??= self::getItemCombinationValueIdsByField(itemId: $itemId);
            $roleRank = self::fieldRoleRank(
                fields: $labelFields,
                comboFieldIdSet: array_fill_keys(array_keys(array_filter($comboValuesByField)), true),
            );
        }

        $itemAll = FieldParams::getItemAll(itemId: $itemId);
        $isRule = static fn (string $rule): bool => $rule !== '' && $rule !== '{}' && $rule !== 'null' && $rule !== '[]';

        $out = [];

        foreach ($fields as $field) {
            $fieldId = (int) $field->id;
            $itemRule = isset($itemAll[$fieldId]) ? (string) $itemAll[$fieldId]->get('showon', '') : '';
            $decoded = $field->params_decoded ?? (\is_string($field->params ?? null) ? (json_decode($field->params, true) ?: []) : []);
            $fieldRule = (string) ($decoded['showon'] ?? '');

            if ($isRule($itemRule)) {
                $state = 'set';
                $effective = $itemRule;
            } elseif ($isRule($fieldRule)) {
                $state = 'inherited';
                $effective = $fieldRule;
            } else {
                $state = 'none';
                $effective = '';
            }

            // Dangling-operand guard (mirrors the frontend resolveEffectiveShowon):
            // a rule gating on a field absent from this item is never applied, so
            // the field is Always shown here; present it as such, not as active.
            if ($effective !== '') {
                foreach (self::ruleOperandFields(rule: $effective) as $operandField) {
                    if (!isset($itemFieldNames[$operandField])) {
                        $state = 'none';
                        $effective = '';

                        break;
                    }
                }
            }

            // Forward-only guard: a still-effective rule may gate only on fields
            // that come BEFORE this one and are interactive (variation/option). A
            // rule reaching a LATER field, or an informational (static) value,
            // cannot be satisfied in the storefront's sequential flow; flag it so
            // the author fixes it (the frontend drops it: the field stays shown).
            if ($effective !== '') {
                $targetRank = $roleRank[(string) ($field->field_name ?? '')]['rank'] ?? PHP_INT_MAX;

                foreach (self::ruleOperandFields(rule: $effective) as $operandField) {
                    $operandMeta = $roleRank[$operandField] ?? null;

                    if ($operandMeta !== null && (!self::isInteractiveRole($operandMeta['role']) || $operandMeta['rank'] >= $targetRank)) {
                        $state = 'invalid';

                        break;
                    }
                }
            }

            $out[$fieldId] = [
                'state' => $state,
                'summary' => $effective !== '' ? ShowonSummary::forItemFields(rule: $effective, fields: $labelFields) : '',
            ];
        }

        return $out;
    }

    /**
     * Role + render-order rank of every field PRESENT on an item, in the exact
     * storefront order: variation (0) -> option (1) -> informational (2), then the
     * per-item display order within each. The ShowOn forward-only guard reads this
     * - a field may gate only on fields with a SMALLER rank (shown before it) that
     * are interactive (role <= 1); informational values are static, never a switch.
     *
     * Mirrors the Site ItemFieldsHelper sort so the picker, the badge and the
     * storefront all agree on "earlier than".
     *
     * @param object[] $fields Eligible fields in per-item display order.
     * @param array $comboFieldIdSet Set (fieldId => true) of combination dimensions.
     *
     * @return array<string, array{role:int, rank:int}> field name => role + rank.
     *
     * @since  2.0.0
     */
    public static function fieldRoleRank(array $fields, array $comboFieldIdSet): array
    {
        $meta = [];

        foreach (array_values($fields) as $position => $field) {
            $name = (string) ($field->field_name ?? '');

            if ($name === '') {
                continue;
            }

            $hasCombo = isset($comboFieldIdSet[(int) $field->id]);
            $hasOption = !empty($field->option_rows);
            $hasInfo = !empty($field->selected_value_ids);

            // Not configured on this item -> not a usable operand, so not ranked.
            if (!$hasCombo && !$hasOption && !$hasInfo) {
                continue;
            }

            $meta[$name] = [
                'role' => $hasCombo ? self::ROLE_VARIATION : ($hasOption ? self::ROLE_OPTION : self::ROLE_INFORMATIONAL),
                'pos' => $position,
            ];
        }

        // Lexicographic (role, per-item position) -> a dense rank, exactly the order
        // the storefront renders the fields in.
        uasort($meta, static fn ($a, $b) => ($a['role'] <=> $b['role']) ?: ($a['pos'] <=> $b['pos']));

        $rank = 0;

        foreach ($meta as &$entry) {
            $entry['rank'] = $rank++;
            unset($entry['pos']);
        }

        return $meta;
    }

    /**
     * The distinct field machine names a ShowOn rule references as operands (the
     * part before the dot in each `name.facet`), walking nested groups. Mirrors
     * the Site ItemFieldsHelper so the badge and the frontend agree on which
     * rules are dangling.
     *
     * @param string|array $rule The stored rule (JSON string or decoded array).
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private static function ruleOperandFields($rule): array
    {
        $rule = \is_array($rule) ? $rule : (json_decode((string) $rule, true) ?: []);
        $names = [];

        self::collectRuleOperands(node: $rule, names: $names);

        return array_keys($names);
    }

    /**
     * Collect operand field names from a rule node into $names (keys).
     *
     * @param array $node A rule group/leaf.
     * @param array $names Accumulator (field name => true), by reference.
     *
     *
     * @since  2.0.0
     */
    private static function collectRuleOperands(array $node, array &$names): void
    {
        foreach ($node['rules'] ?? [] as $child) {
            if (!\is_array($child)) {
                continue;
            }

            if (isset($child['rules'])) {
                self::collectRuleOperands(node: $child, names: $names);

                continue;
            }

            $operand = (string) ($child['operand'] ?? '');

            if ($operand !== '') {
                $names[explode('.', $operand)[0]] = true;
            }
        }
    }

    /**
     * Value ids that appear in THIS item's combinations, grouped by field: the
     * in-item subset of each variant field's pool (a value with no combination is
     * never selectable here, so it is not a usable visibility operand value).
     *
     * @param int $itemId The item id.
     *
     * @return array<int, int[]> field id => value ids.
     *
     * @since  2.0.0
     */
    public static function getItemCombinationValueIdsByField(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select('v.item_field_id, cv.value_id')
                ->from($db->qn('#__alfa_item_combination_values', 'cv'))
                ->join('INNER', $db->qn('#__alfa_item_combinations', 'c') . ' ON c.id = cv.combination_id')
                ->join('INNER', $db->qn('#__alfa_item_field_values', 'v') . ' ON v.id = cv.value_id')
                ->where('c.item_id = ' . $itemId),
        );

        $out = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $out[(int) $row->item_field_id][(int) $row->value_id] = true;
        }

        return array_map('array_keys', $out);
    }

    /**
     * @deprecated 1.3.0 superseded by {@see self::getShowonDisplay()} (state + summary).
     */
    public static function getShowonStateMap(int $itemId, array $fieldIds): array
    {
        $fieldIds = array_values(array_unique(array_map('intval', array_filter($fieldIds))));

        if ($itemId <= 0 || !$fieldIds) {
            return [];
        }

        $itemAll = FieldParams::getItemAll(itemId: $itemId);

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->qn('id'), $db->qn('params')])
                ->from($db->qn('#__alfa_item_fields'))
                ->whereIn($db->qn('id'), $fieldIds),
        );

        $fieldRule = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $params = json_decode((string) $row->params, true) ?: [];
            $fieldRule[(int) $row->id] = (string) ($params['showon'] ?? '');
        }

        $isRule = static fn (string $rule): bool => $rule !== '' && $rule !== '{}' && $rule !== 'null' && $rule !== '[]';

        $out = [];

        foreach ($fieldIds as $fieldId) {
            $itemRule = isset($itemAll[$fieldId]) ? (string) $itemAll[$fieldId]->get('showon', '') : '';

            if ($isRule($itemRule)) {
                $out[$fieldId] = 'set';
            } elseif ($isRule($fieldRule[$fieldId] ?? '')) {
                $out[$fieldId] = 'inherited';
            } else {
                $out[$fieldId] = 'none';
            }
        }

        return $out;
    }

    /**
     * Persist the Options LIST edits with the item save (variants-grid
     * mechanics): update each remaining row's delta, hard-delete the rows
     * removed in the list (deferred via the hidden deleted-ids input).
     *
     * @param int $itemId The item id.
     * @param array $payload jform[options]: [value_id => ['operation' => '+|-|%|', 'amount' => string]].
     * @param string $deletedValueIds Comma list of value ids removed in the list.
     *
     * @return void Nothing of the list is written when a delta is refused; the
     *              refusals are queued as error messages naming each figure.
     *
     * @since  2.0.0
     */
    public static function saveItemOptions(int $itemId, array $payload, string $deletedValueIds): void
    {
        if ($itemId <= 0) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $deleted = array_values(array_filter(array_map('intval', explode(',', $deletedValueIds))));

        // A delta its column cannot hold as it is is refused by name, before
        // anything of the list is written: the column never rounds a delta.
        $refused = [];

        foreach ($payload as $valueId => $delta) {
            $delta = (array) $delta;
            $amount = trim((string) ($delta['amount'] ?? ''));

            if ((int) $valueId <= 0 || in_array((int) $valueId, $deleted, true) || !in_array($delta['operation'] ?? '', ['+', '-', '%', '-%'], true)
                || $amount === '' || !is_numeric($amount)) {
                continue;
            }

            $refused[] = match (\Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal::refusal(value: \Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount::ofNumber($amount))) {
                \Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal::FINER => Text::sprintf('COM_ALFA_ERROR_DECIMAL_TOO_FINE', $amount, \Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal::SCALE),
                \Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal::LARGER => Text::sprintf('COM_ALFA_ERROR_DECIMAL_TOO_LARGE', $amount),
                default => null,
            };
        }

        $refused = array_values(array_filter($refused));

        if ($refused !== []) {
            $app = Factory::getApplication();

            foreach ($refused as $message) {
                $app->enqueueMessage($message, 'error');
            }

            $app->enqueueMessage(Text::_('COM_ALFA_ITEM_OPTIONS_NOT_SAVED'), 'error');

            return;
        }

        if ($deleted) {
            // The removed rows' ids FIRST: their option-scoped field params
            // (configurator areas, subscription terms, ...) go with them, or a
            // later re-add gets a fresh row id and the stale config would sit
            // orphaned forever (and a reused id would resurrect it wrongly).
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn('id'))
                    ->from($db->qn('#__alfa_item_option_values'))
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->whereIn($db->qn('value_id'), $deleted),
            );
            $removedRowIds = array_map('intval', $db->loadColumn() ?: []);

            if ($removedRowIds) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->qn('#__alfa_item_combination_field_params'))
                        ->where($db->qn('scope') . ' = ' . $db->quote('option'))
                        ->whereIn($db->qn('scope_id'), $removedRowIds),
                );
                $db->execute();
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->qn('#__alfa_item_option_values'))
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->whereIn($db->qn('value_id'), $deleted),
            );
            $db->execute();
        }

        // Drag-to-reorder: the rows post in document (dragged) order, so the
        // running position IS the per-item ordering (Sortable is per field, so
        // a field's values keep their relative order regardless of the others).
        $position = 0;

        foreach ($payload as $valueId => $delta) {
            $valueId = (int) $valueId;

            if ($valueId <= 0 || in_array($valueId, $deleted, true)) {
                continue;
            }

            $delta = (array) $delta;
            $operation = in_array($delta['operation'] ?? '', ['+', '-', '%', '-%'], true) ? $delta['operation'] : null;
            $amount = trim((string) ($delta['amount'] ?? ''));
            // A delta is a number; it is handed to its column as its exact
            // decimal text, never a float printed with fourteen digits.
            $hasDelta = $operation !== null && $amount !== '' && is_numeric($amount);

            // Net/gross base for a fixed-amount delta. NULL = follow the global
            // config (resolved at price time); only meaningful while a delta runs.
            $applyOn = in_array($delta['apply_on'] ?? '', ['start', 'final'], true) ? $delta['apply_on'] : null;

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->qn('#__alfa_item_option_values'))
                    ->set($db->qn('operation') . ' = ' . ($hasDelta ? $db->quote($operation) : 'NULL'))
                    ->set($db->qn('amount') . ' = ' . ($hasDelta ? $db->quote(\Alfa\Component\Alfa\Administrator\Filter\DecimalFilter::text($amount)) : 'NULL'))
                    ->set($db->qn('apply_on') . ' = ' . ($hasDelta && $applyOn !== null ? $db->quote($applyOn) : 'NULL'))
                    ->set($db->qn('ordering') . ' = ' . $position++)
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->where($db->qn('value_id') . ' = ' . $valueId),
            );
            $db->execute();
        }
    }

    /**
     * ADD informational values (the picker popup, generator-style): only
     * MISSING rows are inserted: additive and idempotent. Removal happens
     * in the Informational list (deferred, persists with the item save).
     *
     * @param int $itemId The item id.
     * @param array $valueIds Picked pool value ids.
     *
     * @return int Number of rows created.
     *
     * @since  2.0.0
     */
    public static function addItemInfoValues(int $itemId, array $valueIds): int
    {
        if ($itemId <= 0 || !$valueIds) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Validate: published values of published INFORMATIONAL fields.
        $db->setQuery(
            $db->getQuery(true)
                ->select('v.id')
                ->from($db->qn('#__alfa_item_field_values', 'v'))
                ->join('INNER', $db->qn('#__alfa_item_fields', 'f') . ' ON f.id = v.item_field_id')
                ->whereIn($db->qn('v.id'), array_map('intval', $valueIds))
                ->where('v.state = 1')
                ->where('f.state = 1')
                ->where('f.informational = 1'),
        );
        $validIds = array_map('intval', $db->loadColumn() ?: []);

        if (!$validIds) {
            return 0;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn('value_id'))
                ->from($db->qn('#__alfa_item_info_values'))
                ->where($db->qn('item_id') . ' = ' . $itemId),
        );
        $existing = array_map('intval', $db->loadColumn() ?: []);

        // Insert in the PICKED order (the popup posts values in drag order), so the
        // created rows' ordering follows the order you arranged them in the popup.
        $validSet = array_flip($validIds);
        $existingSet = array_flip($existing);
        $added = 0;

        foreach ($valueIds as $valueId) {
            $valueId = (int) $valueId;

            if ($valueId <= 0 || !isset($validSet[$valueId]) || isset($existingSet[$valueId])) {
                continue;
            }

            $infoRow = (object) [
                'item_id' => $itemId,
                'value_id' => $valueId,
                'ordering' => count($existing) + $added,
            ];
            $db->insertObject('#__alfa_item_info_values', $infoRow);
            $added++;
        }

        return $added;
    }

    /**
     * Persist the Informational LIST removals with the item save (variants
     * mechanics: deferred via the hidden deleted-ids input).
     *
     * @param int $itemId The item id.
     * @param string $deletedValueIds Comma list of value ids removed in the list.
     *
     *
     * @since  2.0.0
     */
    public static function deleteItemInfoValues(int $itemId, string $deletedValueIds): void
    {
        $deleted = array_values(array_filter(array_map('intval', explode(',', $deletedValueIds))));

        if ($itemId <= 0 || !$deleted) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->qn('#__alfa_item_info_values'))
                ->where($db->qn('item_id') . ' = ' . $itemId)
                ->whereIn($db->qn('value_id'), $deleted),
        );
        $db->execute();
    }

    /**
     * Persist the Informational LIST drag order with the item save. The list
     * posts a hidden value-id per row (jform[info_values][]) in document order,
     * so the running position IS the per-item ordering, the same position-based
     * model the options + variations lists use (no extra request).
     *
     * @param int $itemId The item id.
     * @param array $orderedValueIds Posted value ids in displayed (dragged) order.
     *
     *
     * @since  2.0.0
     */
    public static function saveItemInfoOrder(int $itemId, array $orderedValueIds): void
    {
        if ($itemId <= 0 || !$orderedValueIds) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $position = 0;

        foreach ($orderedValueIds as $valueId) {
            $valueId = (int) $valueId;

            if ($valueId <= 0) {
                continue;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->qn('#__alfa_item_info_values'))
                    ->set($db->qn('ordering') . ' = ' . $position++)
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->where($db->qn('value_id') . ' = ' . $valueId),
            );
            $db->execute();
        }
    }

    /**
     * Persist the per-item GROUP order: the relative order of the option/info
     * fields, set by dragging their group headers. Each group posts its field id
     * (jform[field_order][]) in document order, so the running position IS the
     * per-item ordering. Compared against the GLOBAL field ordering: when they
     * match, the override is cleared (NULL -> inherit global, tracks future global
     * reorders); a genuine divergence is pinned as a dense snapshot. Only the
     * `ordering` column is touched, so a field's ShowOn override / plugin config
     * in `params` is never disturbed; rows left holding nothing else are removed.
     *
     * @param int $itemId The item id.
     * @param array $orderedFieldIds Posted field ids in displayed (dragged) order.
     *
     *
     * @since  2.0.0
     */
    public static function saveItemFieldOrder(int $itemId, array $orderedFieldIds): void
    {
        if ($itemId <= 0) {
            return;
        }

        $fieldIds = array_values(array_filter(array_map('intval', $orderedFieldIds), static fn ($id) => $id > 0));

        if (!$fieldIds) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->qn('id'), $db->qn('ordering')])
                ->from($db->qn('#__alfa_item_fields'))
                ->whereIn($db->qn('id'), $fieldIds),
        );
        $globalOrder = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $globalOrder[(int) $row->id] = (int) $row->ordering;
        }

        $byGlobal = $fieldIds;
        usort($byGlobal, static fn ($a, $b) => ($globalOrder[$a] ?? 0) <=> ($globalOrder[$b] ?? 0));

        if ($byGlobal === $fieldIds) {
            // Back to the global order -> NULL the per-item ordering (inherit), then
            // drop rows that now hold nothing else (no ShowOn override / config).
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->qn('#__alfa_item_field_overrides'))
                    ->set($db->qn('ordering') . ' = NULL')
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->whereIn($db->qn('field_id'), $fieldIds),
            );
            $db->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->qn('#__alfa_item_field_overrides'))
                    ->where($db->qn('item_id') . ' = ' . $itemId)
                    ->whereIn($db->qn('field_id'), $fieldIds)
                    ->where($db->qn('ordering') . ' IS NULL')
                    ->where('(' . $db->qn('params') . ' IS NULL OR ' . $db->qn('params') . " IN ('', '{}'))"),
            );
            $db->execute();

            return;
        }

        $values = [];
        $position = 0;

        foreach ($fieldIds as $fieldId) {
            $values[] = '(' . $itemId . ', ' . $fieldId . ', ' . $position++ . ')';
        }

        $db->setQuery(
            'INSERT INTO ' . $db->qn('#__alfa_item_field_overrides')
            . ' (' . $db->qn('item_id') . ', ' . $db->qn('field_id') . ', ' . $db->qn('ordering') . ')'
            . ' VALUES ' . implode(', ', $values)
            . ' ON DUPLICATE KEY UPDATE ' . $db->qn('ordering') . ' = VALUES(' . $db->qn('ordering') . ')',
        );
        $db->execute();
    }

    /**
     * Reset the per-item field-order override of the given fields back to the
     * global default: NULL their ordering, then drop override rows that now hold
     * nothing else (no ShowOn / plugin config). Used when the last combination is
     * deleted, so the dimension order no longer lingers on the item.
     *
     * @param int $itemId The item primary key.
     * @param int[] $fieldIds The fields whose per-item ordering to clear.
     *
     * @since  2.0.0
     */
    private static function resetItemFieldOrder(int $itemId, array $fieldIds): void
    {
        $fieldIds = array_values(array_filter(array_map('intval', $fieldIds)));

        if ($itemId <= 0 || !$fieldIds) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->qn('#__alfa_item_field_overrides'))
                ->set($db->qn('ordering') . ' = NULL')
                ->where($db->qn('item_id') . ' = ' . $itemId)
                ->whereIn($db->qn('field_id'), $fieldIds),
        );
        $db->execute();

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->qn('#__alfa_item_field_overrides'))
                ->where($db->qn('item_id') . ' = ' . $itemId)
                ->whereIn($db->qn('field_id'), $fieldIds)
                ->where($db->qn('ordering') . ' IS NULL')
                ->where('(' . $db->qn('params') . ' IS NULL OR ' . $db->qn('params') . " IN ('', '{}'))"),
        );
        $db->execute();
    }

    /**
     * Persist the variant dimension order chosen in the generator popup.
     *
     * The combinations grid orders its dimension columns by the per-item field
     * order (`#__alfa_item_field_overrides.ordering`), because combination rows
     * keep only a sorted value signature; they carry no dimension order of their
     * own. So the chosen variant order is slotted back into the item's full field
     * display order (leaving option / informational fields exactly where they are)
     * and handed to the shared {@see self::saveItemFieldOrder()}.
     *
     * @param int $itemId The item primary key.
     * @param int[] $orderedVariantIds Variant field ids in the chosen (dragged) order.
     *
     * @return bool True when the stored field order actually changed.
     * @since  2.0.0
     */
    public static function applyGeneratorFieldOrder(int $itemId, array $orderedVariantIds): bool
    {
        $orderedVariantIds = array_values(array_filter(array_map('intval', $orderedVariantIds)));

        // Nothing to express with fewer than two dimensions.
        if ($itemId <= 0 || count($orderedVariantIds) < 2) {
            return false;
        }

        // The item's full scoped field order as the grid/popup currently shows it.
        $fullOrder = array_map(
            static fn ($field) => (int) $field->id,
            self::getFieldsPopupData(
                itemId:      $itemId,
                categoryIds: self::getItemCategoryIds(itemId: $itemId),
            ),
        );

        // Keep only chosen ids that actually appear, so slots and queue stay aligned.
        $queue = array_values(array_intersect($orderedVariantIds, $fullOrder));

        if (count($queue) < 2) {
            return false;
        }

        $variantSlots = array_flip($queue);
        $reordered = [];

        // Walk the full order; refill each variant slot, in turn, with the chosen
        // sequence, leaving every non-variant field untouched in its position.
        foreach ($fullOrder as $fieldId) {
            $reordered[] = isset($variantSlots[$fieldId]) ? array_shift($queue) : $fieldId;
        }

        if ($reordered === $fullOrder) {
            return false;
        }

        self::saveItemFieldOrder(itemId: $itemId, orderedFieldIds: $reordered);

        return true;
    }

    /**
     * The DIMENSION SET of the item's existing combinations: the distinct
     * field ids their values belong to, in assignment display order. Drives
     * the generator's depth lock and the popup's role chips.
     *
     * @param int $itemId The item id.
     *
     * @return int[] Field ids; empty when the item has no combinations.
     *
     * @since  2.0.0
     */
    public static function getCombinationFieldIds(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT v.item_field_id, f.ordering')
                ->from($db->qn('#__alfa_item_combination_values', 'cv'))
                ->join('INNER', $db->qn('#__alfa_item_combinations', 'c')
                    . ' ON c.id = cv.combination_id AND c.item_id = ' . $itemId)
                ->join('INNER', $db->qn('#__alfa_item_field_values', 'v') . ' ON v.id = cv.value_id')
                ->join('INNER', $db->qn('#__alfa_item_fields', 'f') . ' ON f.id = v.item_field_id')
                ->order('f.ordering ASC'),
        );

        return array_map('intval', array_column($db->loadAssocList() ?: [], 'item_field_id'));
    }

    /**
     * Load the item's combinations for the grid: every current row plus a human label
     * built from its value names (current language) and an is_orphan flag
     * (a value of the combination is no longer offered by the item).
     *
     * @param int $itemId The item id.
     *
     * @return array Combination row objects ordered by ordering.
     *
     * @since  2.0.0
     */
    public static function getCombinations(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $combinations = self::loadCombinationRows(itemId: $itemId);

        if (!$combinations) {
            return [];
        }

        // Field order follows the PER-ITEM dimension order (set in the generator /
        // grid, stored in #__alfa_item_field_overrides), falling back to the global
        // field ordering (so the variant label reads in the chosen order), then
        // the value ordering.
        $db->setQuery(
            $db->getQuery(true)
                ->select('cv.combination_id, cv.value_id')
                ->from($db->qn('#__alfa_item_combination_values', 'cv'))
                ->join('INNER', $db->qn('#__alfa_item_field_values', 'v') . ' ON v.id = cv.value_id')
                ->join('INNER', $db->qn('#__alfa_item_fields', 'f') . ' ON f.id = v.item_field_id')
                ->join(
                    'LEFT',
                    $db->qn('#__alfa_item_field_overrides', 'ovr')
                    . ' ON ovr.field_id = f.id AND ovr.item_id = ' . $itemId,
                )
                ->whereIn($db->qn('cv.combination_id'), array_map('intval', array_keys($combinations)))
                ->order('COALESCE(ovr.ordering, f.ordering) ASC, f.ordering ASC, v.ordering ASC'),
        );
        $links = $db->loadObjectList() ?: [];

        $valueIds = array_values(array_unique(array_map(static fn ($link) => (int) $link->value_id, $links)));

        $names = [];

        if ($valueIds) {
            $langKey = strtolower(str_replace('-', '_', MultilingualHelper::getCurrentLanguageTag()));

            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->select('id_item_field_value, name')
                        ->from($db->qn('#__alfa_item_field_values_' . $langKey))
                        ->whereIn($db->qn('id_item_field_value'), $valueIds),
                );
                foreach ($db->loadObjectList() ?: [] as $row) {
                    $names[(int) $row->id_item_field_value] = (string) $row->name;
                }
            } catch (Throwable $e) {
                // Lang aux table pending resync; ids remain.
            }
        }

        // Orphan = the combination references a value that is UNPUBLISHED in
        // the pool (combinations are the per-item source of truth, so the
        // only thing that can invalidate one is the pool itself).
        $published = [];

        if ($valueIds) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn('id'))
                    ->from($db->qn('#__alfa_item_field_values'))
                    ->whereIn($db->qn('id'), $valueIds)
                    ->where($db->qn('state') . ' = 1'),
            );
            $published = array_map('intval', $db->loadColumn() ?: []);
        }

        foreach ($combinations as $combination) {
            $combination->value_ids = [];
            $combination->label = '';
            $combination->is_orphan = false;
        }

        foreach ($links as $link) {
            $combination = $combinations[(int) $link->combination_id];
            $valueId = (int) $link->value_id;
            $combination->value_ids[] = $valueId;
            $combination->label .= ($combination->label !== '' ? ' / ' : '') . ($names[$valueId] ?? ('#' . $valueId));

            if (!in_array($valueId, $published, true)) {
                $combination->is_orphan = true;
            }
        }

        $combinationIds = array_map('intval', array_keys($combinations));

        // Price editor rows: full prices (items_prices, combination_id) and
        // adjust deltas (impacts table) merged into ONE editor row list.
        foreach ($combinations as $combination) {
            $combination->price_rows = [];
            $combination->translations = [];
        }

        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->qn('#__alfa_items_prices'))
                ->whereIn($db->qn('combination_id'), $combinationIds)
                ->order($db->qn('id') . ' ASC'),
        );
        foreach ($db->loadAssocList() ?: [] as $priceRow) {
            $priceRow['_mode'] = $priceRow['mode'] ?? 'price';
            $combinations[(int) $priceRow['combination_id']]->price_rows[] = $priceRow;
        }

        // Each combination owns its gallery (#__alfa_media origin=combination,
        // edited in the Studio popup). The grid only needs the counts for chips.
        foreach ($combinations as $combination) {
            $combination->media_count = 0;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn('item_id') . ', COUNT(*) AS media_total')
                ->from($db->qn('#__alfa_media'))
                ->where($db->qn('origin') . ' = ' . $db->quote('combination'))
                ->whereIn($db->qn('item_id'), $combinationIds)
                ->group($db->qn('item_id')),
        );
        foreach ($db->loadObjectList() ?: [] as $mediaCount) {
            $combinations[(int) $mediaCount->item_id]->media_count = (int) $mediaCount->media_total;
        }

        // SPARSE translatable overrides per content language.
        foreach (self::contentLanguageKeys() as $langSuffix) {
            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->select('*')
                        ->from($db->qn('#__alfa_item_combinations_' . $langSuffix))
                        ->whereIn($db->qn('id_item_combination'), $combinationIds),
                );
                foreach ($db->loadAssocList() ?: [] as $translation) {
                    $combinations[(int) $translation['id_item_combination']]->translations[$langSuffix] = $translation;
                }
            } catch (Throwable $e) {
                // Aux table not created yet: overrides simply absent.
            }
        }

        return array_values($combinations);
    }

    /**
     * One combination, fully loaded for the Studio popup (price editor rows,
     * translations, override columns, chips data).
     *
     * @param int $itemId The owning item id.
     * @param int $combinationId The combination id.
     *
     *
     * @since  2.0.0
     */
    public static function getCombination(int $itemId, int $combinationId): ?object
    {
        foreach (self::getCombinations(itemId: $itemId) as $combination) {
            if ((int) $combination->id === $combinationId) {
                return $combination;
            }
        }

        return null;
    }

    /**
     * The translatable per-combination override columns, derived from the
     * item form definitions (fields carrying a multilingual_table attribute).
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    public static function combinationTranslatableFields(): array
    {
        return array_keys(array_filter(
            self::getCombinationOverrideDescriptors(),
            static fn ($descriptor) => $descriptor->multilingual,
        ));
    }

    /**
     * Content language flat-key suffixes (en-GB -> en_gb).
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    public static function contentLanguageKeys(): array
    {
        $keys = [];

        foreach (\Joomla\CMS\Language\LanguageHelper::getLanguages('lang_code') as $code => $language) {
            $keys[] = strtolower(str_replace('-', '_', $code));
        }

        return $keys;
    }

    /**
     * Why the combinations grid would refuse a save, decided before anything is
     * written: the item form runs it before the product row is saved, so a
     * refused grid never leaves a half-saved product behind.
     *
     * The texts are the ones saveCombinations() throws for the same payload.
     * Deleting combinations is refused while the product itself still holds
     * stock, while the product's stock figures disagree with its stock records,
     * and for every deleted combination that still holds stock (on hand or
     * reserved at any location, an open reservation, a pending stock effect).
     * An open order line that still has stock to deduct from the product itself
     * or from a deleted combination refuses it too, and the text names each
     * such line. Order history alone never refuses a deletion: such a
     * combination is archived.
     * A changed stock figure is refused when it is not a number or arrives
     * without the figure it was rendered with, when one figure cannot express
     * the combination's stock, and when the inventory service would refuse the
     * target. A figure on a combination that does not track stock, or no longer
     * exists, is not a refusal: the save reports it as not applied.
     * A price cell whose change would remove the variant's custom discount
     * (switching its full price to an adjustment, or clearing its price) is
     * refused: the custom discount is switched off in the variant's Edit window.
     *
     * @param int $itemId The product.
     * @param array $payload The jform[combinations] array.
     * @param string $deletedCombinationIds Comma list of ids to delete.
     *
     * @return string[] Translated refusal texts; empty when the save may run.
     *
     * @since  2.0.0
     */
    public static function combinationSaveRefusals(int $itemId, array $payload, string $deletedCombinationIds): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $existing = self::loadCombinationRows(itemId: $itemId);
        $deletedIds = self::deletableIds(existing: $existing, deletedCombinationIds: $deletedCombinationIds);
        $inventory = new InventoryService();
        $refusals = [];

        if ($deletedIds !== []) {
            $productStock = self::heldBlockers(blockers: $inventory->retirementBlockers(itemId: $itemId, combinationIds: [0]));

            if ($productStock !== []) {
                $refusals[] = self::stockHeldText(itemId: $itemId, key: 'COM_ALFA_COMBI_CHANGE_PARENT_STOCK', blockers: $productStock, markup: true);
            }

            try {
                $inventory->assertProjectionsAgree(itemId: $itemId);
            } catch (InventoryProjectionMismatchException $e) {
                $refusals[] = Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE');
            }

            array_push($refusals, ...self::deletionBlockedTexts(
                itemId: $itemId,
                blockers: $inventory->retirementBlockers(itemId: $itemId, combinationIds: $deletedIds),
            ));
        }

        foreach (self::gridStockEdits(existing: $existing, payload: $payload, deletedIds: $deletedIds) as $combinationId => $edit) {
            $decision = self::gridStockDecision(
                itemId: $itemId,
                combinationId: $combinationId,
                posted: $edit['posted'],
                baseline: $edit['baseline'],
            );

            if ($decision['action'] === 'refuse') {
                $refusals[] = $decision['text'];
            } elseif ($decision['action'] === 'apply') {
                array_push($refusals, ...self::targetProblemTexts(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    problems: $inventory->validateTargets(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        targets: [$decision['location'] => $decision['target']],
                        assumeTracked: false,
                    ),
                ));
            }
        }

        // Price cells, judged by the rule the save writes them by, on one read of
        // the rows of every changed cell.
        $cells = [];

        foreach ($payload as $combinationId => $row) {
            $combinationId = (int) $combinationId;
            $row = (array) $row;

            if (!isset($existing[$combinationId]) || \in_array($combinationId, $deletedIds, true)) {
                continue;
            }

            $quick = (array) ($row['quick'] ?? []);
            $changes = self::quickCellChanges(
                quick:    $quick,
                baseline: \is_array($row['quick_baseline'] ?? null) ? $row['quick_baseline'] : null,
            );

            if ($changes !== []) {
                $cells[$combinationId] = ['quick' => $quick, 'changes' => $changes];
            }
        }

        $priceRows = $cells === [] ? [] : self::variantPriceRows(combinationIds: array_keys($cells), lock: false);
        $labels = null;

        foreach ($cells as $combinationId => $cell) {
            $plan = self::quickRowPlan(rows: $priceRows[$combinationId] ?? [], quick: $cell['quick'], changes: $cell['changes']);

            if ($plan['action'] === 'refuse') {
                $labels ??= self::combinationLabels(itemId: $itemId);
                $refusals[] = self::keepsDiscountText(label: $labels[$combinationId] ?? ('#' . $combinationId));
            }
        }

        return $refusals;
    }

    /**
     * Persist the combinations GRID, only the quick-edits the rows expose:
     * SKU, name, stock, status, the default radio, the price cell (the single
     * any-context price row, see upsertQuickRow()), and deletions. Everything
     * else (identity, every other price row, a price row's custom discount and
     * Enabled state, overrides, texts, gallery) belongs to the Studio popup
     * and is saved atomically by saveSingleCombination().
     * The grid sees the item's current combinations only; a posted archived
     * one is ignored like a vanished one.
     *
     * One transaction. When the save deletes combinations, changes a stock
     * figure or changes a combination's publication, its first statement locks
     * the product's stock records, the inventory service's lock order, so an
     * order reserving a surviving combination at the same moment waits instead
     * of deadlocking. Then:
     *  - deletions are refused while the product itself holds stock, while its
     *    figures disagree with its stock records, while a deleted
     *    combination still holds stock, or while an open order line still has
     *    stock to deduct from the product itself or from a deleted
     *    combination. Nothing is written off. A deleted
     *    combination that order history names (an order line was sold as it,
     *    or an order line's stock was bound to it) is the stock identity that
     *    history is fulfilled, cancelled and returned against, so the
     *    inventory service archives it: the row, its translations and its
     *    stock records (all at zero) stay, and its value links, prices, cart
     *    lines, gallery and field settings go, as for a deleted one; the save
     *    then warns which combinations were archived. The other deleted
     *    combinations' stock records are retired and their rows deleted.
     *    Only current combinations are deleted: a requested id whose row has
     *    already gone, or is archived, is left alone, stock records included;
     *  - publication changes go through the inventory service, which keeps the
     *    product's available figure in step with its published combinations;
     *  - a changed stock figure converges the combination's stock at its one
     *    location through the inventory service. A figure posted back exactly as
     *    it was rendered moves nothing, so units sold while the grid was open
     *    are never put back;
     *  - a price cell writes only what the operator changed on the page; an edit
     *    that would remove a custom discount is refused.
     * A refusal throws the merchant-readable text of
     * combinationSaveRefusals() and rolls everything back. A figure that cannot
     * apply because the combination does not track stock, or is gone, is
     * reported as a warning once the save has committed.
     *
     * @param int $itemId The saved item id.
     * @param array $payload The jform[combinations] array.
     * @param string $defaultId The chosen default combination id.
     * @param string $deletedCombinationIds Comma list of ids to delete (or archive).
     *
     *
     * @throws RuntimeException When the save is refused; nothing is written.
     *
     * @since  2.0.0
     */
    public static function saveCombinations(int $itemId, array $payload, string $defaultId, string $deletedCombinationIds): void
    {
        if ($itemId <= 0) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $existing = self::loadCombinationRows(itemId: $itemId);

        // DDL commits implicitly, so the variant language tables are made
        // BEFORE the transaction opens.
        self::ensureCombinationLangSchema();

        $deletedIds = self::deletableIds(existing: $existing, deletedCombinationIds: $deletedCombinationIds);
        $stockEdits = self::gridStockEdits(existing: $existing, payload: $payload, deletedIds: $deletedIds);

        // Publication changes of the surviving rows (an unticked box posts nothing).
        $stateChanges = [];

        foreach ($payload as $combinationId => $row) {
            $combinationId = (int) $combinationId;

            if (!isset($existing[$combinationId]) || \in_array($combinationId, $deletedIds, true)) {
                continue;
            }

            $postedState = ((string) (((array) $row)['state'] ?? '') === '1') ? 1 : 0;

            if ($postedState !== (int) $existing[$combinationId]->state) {
                $stateChanges[$combinationId] = $postedState;
            }
        }

        $stockChanged = false;

        foreach ($stockEdits as $edit) {
            $stockChanged = $stockChanged || self::gridFigureChanged(posted: $edit['posted'], baseline: $edit['baseline']);
        }

        $inventory = new InventoryService();
        $warnings = [];

        $db->transactionStart(true);

        try {
            if ($deletedIds !== [] || $stateChanges !== [] || $stockChanged) {
                $inventory->lockItemStock(itemId: $itemId);
            }

            // Dimension set captured BEFORE any deletion, used to reset the per-item
            // field order when this save removes the last combination (handled below).
            $dimensionFieldIds = self::getCombinationFieldIds(itemId: $itemId);

            $archivedIds = [];

            if ($deletedIds !== []) {
                $removal = self::retireDeletedUnits(inventory: $inventory, itemId: $itemId, deletedIds: $deletedIds);
                $archivedIds = $removal['archived'];
                array_push($warnings, ...$removal['warnings']);
            }

            if ($stateChanges !== []) {
                $inventory->changeCombinationState(
                    itemId: $itemId,
                    states: $stateChanges,
                    context: InventoryContext::ambient(),
                );
            }

            $labels = null;

            foreach ($stockEdits as $combinationId => $edit) {
                $reason = self::applyCombinationStockEdit(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    posted: $edit['posted'],
                    baseline: $edit['baseline'],
                );

                if ($reason !== null) {
                    $labels ??= self::combinationLabels(itemId: $itemId);
                    $warnings[] = Text::sprintf(
                        'COM_ALFA_COMBI_STOCK_NOT_APPLIED',
                        self::escapedText(text: $labels[$combinationId] ?? ('#' . $combinationId)),
                        $reason,
                    );
                }
            }

            // Removed combinations, deleted and archived alike, lose their value
            // links, price rows (both modes), cart lines, own gallery and field
            // settings. A deleted one also loses its translations and its row. An
            // archived one keeps both, stops being the default and stays only as
            // the stock identity of its order history. Order snapshots survive by
            // design.
            if ($deletedIds !== []) {
                foreach (
                    [
                        '#__alfa_item_combination_values' => 'combination_id',
                        '#__alfa_items_prices' => 'combination_id',
                    ] as $tableName => $column
                ) {
                    $db->setQuery(
                        $db->getQuery(true)
                            ->delete($db->qn($tableName))
                            ->whereIn($db->qn($column), $deletedIds),
                    );
                    $db->execute();
                }

                // A LIVE CART LINE NAMING THIS VARIANT DIES WITH IT: field rows
                // first, exactly as ItemModel::delete() does when the whole product
                // goes. Left behind, the line points at an id that no longer
                // exists or is no longer sold: the cart re-prices it at the BASE
                // price for a variant the shopper configured, checkout freezes the
                // dead reference into the order line, and the inventory claim
                // targets a unit that cannot take one.
                //
                // A cart is not a claim on stock: the retirement above refuses the
                // delete for reservations, pending stock effects and held stock,
                // and for nothing weaker. A line whose variant is gone or archived
                // cannot be priced or fulfilled, so the LINE goes; this is
                // line-family removal, not a withdrawn selection, and the
                // repository takes the line's field rows with it and stamps the
                // carts that lost one.
                //
                // This runs INSIDE the transaction opened above, so the
                // repository's savepoint-aware envelope nests within it: a failure
                // here rolls back to its own savepoint and throws, the catch below
                // rolls the whole save back, and the combinations and their cart
                // lines stand or fall together.
                (new CartRepository($db))->removeLinesForCombinations($deletedIds);

                MediaHelper::deleteMediaForItems(itemIds: $deletedIds, origin: 'combination');

                // Per-variation field plugin configs of the removed combinations.
                FieldParams::deleteForVariants(scope: FieldParams::SCOPE_COMBINATION, scopeIds: $deletedIds);

                $hardDeletedIds = array_values(array_diff($deletedIds, $archivedIds));

                if ($hardDeletedIds !== []) {
                    MultilingualHelper::deleteMultilingualData(
                        ids:               $hardDeletedIds,
                        primaryColumnName: 'id_item_combination',
                        tableName:         '#__alfa_item_combinations',
                    );

                    $db->setQuery(
                        $db->getQuery(true)
                            ->delete($db->qn('#__alfa_item_combinations'))
                            ->whereIn($db->qn('id'), $hardDeletedIds),
                    );
                    $db->execute();
                }

                if ($archivedIds !== []) {
                    $db->setQuery(
                        $db->getQuery(true)
                            ->update($db->qn('#__alfa_item_combinations'))
                            ->set($db->qn('default_on') . ' = 0')
                            ->where($db->qn('item_id') . ' = ' . $itemId)
                            ->whereIn($db->qn('id'), $archivedIds),
                    );
                    $db->execute();
                }
            }

            // Last combination removed -> the per-item dimension order has no more
            // meaning; reset those fields to the default (global) order so a fresh
            // generation starts clean (mirrors "delete to change the dimension set").
            if ($deletedIds && !array_diff(array_map('intval', array_keys($existing)), $deletedIds)) {
                self::resetItemFieldOrder(itemId: $itemId, fieldIds: $dimensionFieldIds);
            }

            $defaultCombinationId = (int) $defaultId;

            // Name quick-edit baseline: what is stored right now, read once for the
            // whole grid (one query per language) so an untouched name costs no
            // write at all; the aux tables are only opened for real edits.
            $storedNames = self::loadCombinationNames(itemId: $itemId);

            // Drag-to-reorder: the grid rows post in document (dragged) order, so
            // the running position IS the variation ordering (loadCombinationRows
            // reads ORDER BY ordering, so the grid renders back in the saved order).
            $position = 0;

            foreach ($payload as $combinationId => $row) {
                $combinationId = (int) $combinationId;

                if (!isset($existing[$combinationId]) || \in_array($combinationId, $deletedIds, true)) {
                    continue;
                }

                $row = (array) $row;

                // Publication is written by the inventory service above; the stock
                // cell is a command, never a column write.
                $update = (object) [
                    'id' => $combinationId,
                    'sku' => (string) ($row['sku'] ?? ''),
                    'default_on' => ($combinationId === $defaultCombinationId) ? 1 : 0,
                    'ordering' => $position++,
                    'modified_by' => (int) Factory::getApplication()->getIdentity()?->id,
                ];

                $db->updateObject('#__alfa_item_combinations', $update, 'id', true);

                // Quick edit (grid column): the SINGLE any-context price row, a full
                // 'price' OR a +/-/% 'adjust' on the item price, written only where
                // the operator changed it on the page. Conditioned/multiple rows and a
                // row's custom discount belong to the Studio.
                self::upsertQuickRow(
                    itemId:        $itemId,
                    combinationId: $combinationId,
                    quick:         (array) ($row['quick'] ?? []),
                    baseline:      \is_array($row['quick_baseline'] ?? null) ? $row['quick_baseline'] : null,
                );

                // Name quick-edit (grid column): hand the writer ONLY the languages
                // whose name actually changed. It is sparse per column, so the
                // Studio's descriptions and stock messages are untouched either way.
                $nameEdits = [];

                foreach (self::contentLanguageKeys() as $langSuffix) {
                    $key = 'name_' . $langSuffix;

                    if (!\array_key_exists($key, $row)) {
                        continue;
                    }

                    $postedName = trim((string) $row[$key]);

                    if ($postedName !== (string) ($storedNames[$combinationId][$langSuffix] ?? '')) {
                        $nameEdits[$key] = $postedName;
                    }
                }

                if ($nameEdits) {
                    self::saveCombinationTranslations(combinationId: $combinationId, row: $nameEdits);
                }
            }

            // An archived combination is never the default: when it was, the
            // first published combination takes its place.
            if ($archivedIds !== []) {
                self::ensureOnePublishedDefault(itemId: $itemId);
            }

            // What the inventory service announces for this save waits for this commit.
            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        foreach ($warnings as $warning) {
            Factory::getApplication()->enqueueMessage($warning, 'warning');
        }
    }

    /**
     * The inventory half of a grid deletion, inside the caller's transaction
     * and after its stock-record lock. The product's own stock must be clear,
     * its figures must agree with its stock records and every deleted
     * combination must hold no stock, where an open order line that still has
     * stock to deduct from the product itself or from a deleted combination
     * counts as held; each check refuses before anything is
     * written. Then the inventory service archives every deleted combination
     * that order history names (an order line was sold as it, or an order
     * line's stock was bound to it): its row and its stock records, all at
     * zero, stay as the stock identity that history is fulfilled, cancelled
     * and returned against. The other deleted combinations' stock records are
     * retired, all at zero, with no movement.
     *
     * @param InventoryService $inventory The inventory service.
     * @param int $itemId The product.
     * @param int[] $deletedIds Current combinations of the product being deleted.
     *
     * @return array{archived: int[], warnings: string[]} The archived combinations,
     *                                                    and one translated warning for each, naming it escaped for the markup it
     *                                                    is shown in, to show once the save has committed.
     *
     * @throws RuntimeException With the merchant-readable refusal.
     *
     * @since  2.0.0
     */
    private static function retireDeletedUnits(InventoryService $inventory, int $itemId, array $deletedIds): array
    {
        try {
            $inventory->assertUnitsClear(itemId: $itemId, combinationIds: [0]);
        } catch (InventoryUnitRetirementException $e) {
            throw new RuntimeException(
                self::stockHeldText(itemId: $itemId, key: 'COM_ALFA_COMBI_CHANGE_PARENT_STOCK', blockers: $e->blockers, markup: true),
                0,
                $e,
            );
        }

        try {
            $inventory->assertProjectionsAgree(itemId: $itemId);
        } catch (InventoryProjectionMismatchException $e) {
            throw new RuntimeException(Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE'), 0, $e);
        }

        try {
            $inventory->assertUnitsClear(itemId: $itemId, combinationIds: $deletedIds);
        } catch (InventoryUnitRetirementException $e) {
            throw new RuntimeException(
                implode(' ', self::deletionBlockedTexts(itemId: $itemId, blockers: $e->blockers)),
                0,
                $e,
            );
        }

        $history = self::orderHistoryCombinationIds(itemId: $itemId, combinationIds: $deletedIds);
        $archivedIds = array_values(array_intersect($deletedIds, array_keys($history)));
        $retiredIds = array_values(array_diff($deletedIds, $archivedIds));
        $warnings = [];

        if ($archivedIds !== []) {
            // Named while they are still current combinations with value links.
            $labels = self::combinationLabels(itemId: $itemId);

            foreach ($archivedIds as $combinationId) {
                $warnings[] = Text::sprintf(
                    'COM_ALFA_COMBI_ARCHIVED_ORDER_HISTORY',
                    self::escapedText(text: $labels[$combinationId] ?? ('#' . $combinationId)),
                    $history[$combinationId],
                );
            }
        }

        try {
            if ($retiredIds !== []) {
                $inventory->retireUnits(
                    itemId: $itemId,
                    combinationIds: $retiredIds,
                    sourceType: 'catalog_edit',
                    sourceId: $itemId,
                    context: InventoryContext::ambient(),
                );
            }

            if ($archivedIds !== []) {
                $inventory->archiveUnits(
                    itemId: $itemId,
                    combinationIds: $archivedIds,
                    sourceType: 'catalog_edit',
                    sourceId: $itemId,
                    context: InventoryContext::ambient(),
                );
            }
        } catch (InventoryUnitRetirementException $e) {
            throw new RuntimeException(
                implode(' ', self::deletionBlockedTexts(itemId: $itemId, blockers: $e->blockers)),
                0,
                $e,
            );
        }

        return ['archived' => $archivedIds, 'warnings' => $warnings];
    }

    /**
     * Studio per-location breakdown: converge each CHANGED location to its
     * posted figure through the inventory service, in one transaction that
     * first locks the product's stock records.
     *
     * A location counts as changed only when its figure differs from the one
     * the Studio rendered for it ($baseline), compared as the back office prints
     * quantities; an untouched figure moves nothing, so units sold while the
     * Studio was open are never put back. An empty figure is no command. The
     * targets are checked by the inventory service before anything moves, and
     * a refused target refuses the whole breakdown. A figure that would lower
     * the stock at its location below what open orders reserve there, or lower
     * stock already below it, is refused in the inventory service's own
     * sentence, escaped once, also when that reservation is made while the
     * breakdown waits for its lock.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param array $posted location_id => figure as posted.
     * @param array $baseline location_id => figure as rendered.
     *
     * @return string[] Translated warnings for figures that could not apply
     *                  (the combination does not track stock, or is gone).
     *
     * @throws RuntimeException When a figure is not a number, has no rendered
     *                          figure to compare with, or its target is refused.
     *
     * @since  2.0.0
     */
    public static function applyCombinationLocationStock(int $itemId, int $combinationId, array $posted, array $baseline): array
    {
        $changes = self::changedLocationFigures(posted: $posted, baseline: $baseline);

        if ($changes === []) {
            return [];
        }

        $tracks = self::combinationTracks(itemId: $itemId, combinationId: $combinationId);

        if ($tracks !== true) {
            return [self::notAppliedText(itemId: $itemId, combinationId: $combinationId, tracks: $tracks)];
        }

        $inventory = new InventoryService();

        $problems = self::targetProblemTexts(
            itemId: $itemId,
            combinationId: $combinationId,
            problems: $inventory->validateTargets(
                itemId: $itemId,
                combinationId: $combinationId,
                targets: $changes,
                assumeTracked: false,
            ),
        );

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $unapplied = false;

        $db->transactionStart(true);

        try {
            $inventory->lockItemStock(itemId: $itemId);

            foreach ($changes as $locationId => $target) {
                try {
                    $applied = $inventory->adjustTo(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        locationId: $locationId,
                        target: $target,
                        sourceType: 'catalog_edit',
                        sourceId: $itemId,
                        context: InventoryContext::ambient(),
                    );
                } catch (InventoryUnitException $e) {
                    // The combination stopped tracking stock, or went, while waiting for its lock.
                    $applied = null;
                } catch (InventoryReservedFloorException $e) {
                    // Reserved while waiting for its lock: the breakdown rolls back, and the operator reads why.
                    throw new RuntimeException(message: self::escapedText(text: $e->operatorMessage()), previous: $e);
                }

                if ($applied === null) {
                    $unapplied = true;

                    break;
                }
            }

            // The breakdown moves as a whole or not at all: what the locations
            // already converged would announce is discarded with it.
            if ($unapplied) {
                TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('The combination is no longer a stock unit.'));
            } else {
                TransactionState::commitLevel(db: $db);
            }
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        if (!$unapplied) {
            return [];
        }

        return [self::notAppliedText(
            itemId: $itemId,
            combinationId: $combinationId,
            tracks: self::combinationTracks(itemId: $itemId, combinationId: $combinationId),
        )];
    }

    /**
     * Apply one grid stock cell. The cell is a command only when its posted
     * figure differs from the figure it was rendered with ($baseline), compared
     * as the back office prints quantities; the ledger is converged to the
     * posted figure through the inventory service's adjustTo(), never shifted by
     * a difference against a stored column.
     *
     * One figure applies only while it can express the combination's stock: a
     * single enabled location and no stock held anywhere else. The target is
     * checked by the inventory service first and a refusal throws its text. A
     * figure that would lower the stock at the location below what open orders
     * reserve there, or lower stock already below it, is refused in the
     * inventory service's own sentence, escaped once.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param string|null $posted The posted figure; null = no figure posted.
     * @param string|null $baseline The figure the cell was rendered with.
     *
     * @return string|null Null when applied or nothing to do; otherwise the
     *                     translated reason it was not applied.
     *
     * @throws RuntimeException When the figure is refused.
     *
     * @since  2.0.0
     */
    private static function applyCombinationStockEdit(int $itemId, int $combinationId, ?string $posted, ?string $baseline): ?string
    {
        $decision = self::gridStockDecision(
            itemId: $itemId,
            combinationId: $combinationId,
            posted: $posted,
            baseline: $baseline,
        );

        if ($decision['action'] === 'none') {
            return null;
        }

        if ($decision['action'] === 'refuse') {
            throw new RuntimeException($decision['text']);
        }

        if ($decision['action'] === 'skip') {
            return $decision['text'];
        }

        $inventory = new InventoryService();
        $targets = [$decision['location'] => $decision['target']];

        $problems = self::targetProblemTexts(
            itemId: $itemId,
            combinationId: $combinationId,
            problems: $inventory->validateTargets(
                itemId: $itemId,
                combinationId: $combinationId,
                targets: $targets,
                assumeTracked: false,
            ),
        );

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        try {
            $applied = $inventory->adjustTo(
                itemId: $itemId,
                combinationId: $combinationId,
                locationId: $decision['location'],
                target: $decision['target'],
                sourceType: 'catalog_edit',
                sourceId: $itemId,
                context: InventoryContext::ambient(),
            );
        } catch (InventoryUnitException $e) {
            // The combination stopped tracking stock, or went, while waiting for its lock.
            $applied = null;
        } catch (InventoryReservedFloorException $e) {
            // Below what open orders reserve there: the grid save rolls back, and the operator reads why.
            throw new RuntimeException(message: self::escapedText(text: $e->operatorMessage()), previous: $e);
        }

        if ($applied !== null) {
            return null;
        }

        return self::notTrackedReason(
            itemId: $itemId,
            combinationId: $combinationId,
            tracks: self::combinationTracks(itemId: $itemId, combinationId: $combinationId),
        );
    }

    /**
     * What one grid stock cell asks for, decided without writing anything.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param string|null $posted The posted figure; null = no figure posted.
     * @param string|null $baseline The figure the cell was rendered with.
     *
     * @return array{action: string, text: ?string, location: ?int, target: ?float}
     *                                                                              'none': nothing to do; 'refuse': text says why; 'skip': the combination
     *                                                                              does not track stock or is gone, text is the reason; 'apply': converge
     *                                                                              location to target.
     *
     * @since  2.0.0
     */
    private static function gridStockDecision(int $itemId, int $combinationId, ?string $posted, ?string $baseline): array
    {
        $decision = ['action' => 'none', 'text' => null, 'location' => null, 'target' => null];

        if (!self::gridFigureChanged(posted: $posted, baseline: $baseline)) {
            return $decision;
        }

        if ($baseline === null || !is_numeric($posted) || !is_numeric($baseline)) {
            return ['action' => 'refuse', 'text' => Text::_('COM_ALFA_INLINE_STOCK_INVALID')] + $decision;
        }

        $tracks = self::combinationTracks(itemId: $itemId, combinationId: $combinationId);

        if ($tracks !== true) {
            return [
                'action' => 'skip',
                'text' => self::notTrackedReason(itemId: $itemId, combinationId: $combinationId, tracks: $tracks),
            ] + $decision;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $enabledLocationIds = LocationPolicy::enabledIds(db: $db);

        if ($enabledLocationIds === []) {
            return ['action' => 'refuse', 'text' => Text::_('COM_ALFA_INLINE_STOCK_NO_LOCATION')] + $decision;
        }

        $unitRows = self::combinationStockByLocation(itemId: $itemId)[$combinationId] ?? [];
        $locationId = self::gridStockLocation(unitRows: $unitRows, enabledLocationIds: $enabledLocationIds);

        if ($locationId === null) {
            $involved = $enabledLocationIds;

            foreach ($unitRows as $rowLocationId => $unitRow) {
                if (abs((float) $unitRow['physical']) > InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                    $involved[] = (int) $rowLocationId;
                }
            }

            return [
                'action' => 'refuse',
                'text' => Text::sprintf('COM_ALFA_ITEM_STOCK_PLAIN_AMBIGUOUS', \count(array_unique($involved))),
            ] + $decision;
        }

        return ['action' => 'apply', 'text' => null, 'location' => $locationId, 'target' => (float) $posted];
    }

    /**
     * Whether a posted stock figure is a command at all: posted, and not the
     * figure it was rendered with as the back office prints quantities. A
     * figure with a missing or unreadable baseline counts as changed, so it is
     * refused rather than ignored.
     *
     * @param string|null $posted The posted figure; null = none posted.
     * @param string|null $baseline The figure it was rendered with.
     *
     *
     * @since  2.0.0
     */
    private static function gridFigureChanged(?string $posted, ?string $baseline): bool
    {
        if ($posted === null) {
            return false;
        }

        if ($baseline === null || !is_numeric($posted) || !is_numeric($baseline)) {
            return true;
        }

        return InventoryAvailabilityService::formatQuantity(value: (float) $posted)
            !== InventoryAvailabilityService::formatQuantity(value: (float) $baseline);
    }

    /**
     * The location the combinations grid's single stock figure edits, or null
     * when one figure cannot express the combination's stock: there is not
     * exactly one enabled location, or stock is held at any other location.
     * The grid renders an input only when this answers a location, and the
     * grid save applies a figure only at the location it answers.
     *
     * @param array<int, array{physical: float, reserved: float}> $unitRows The combination's stock by location
     *                                                                      ({@see self::combinationStockByLocation()}).
     * @param int[] $enabledLocationIds Enabled location ids.
     *
     *
     * @since  2.0.0
     */
    public static function gridStockLocation(array $unitRows, array $enabledLocationIds): ?int
    {
        if (\count($enabledLocationIds) !== 1) {
            return null;
        }

        $locationId = (int) reset($enabledLocationIds);

        foreach ($unitRows as $rowLocationId => $unitRow) {
            if ((int) $rowLocationId !== $locationId
                && abs((float) $unitRow['physical']) > InventoryAvailabilityService::QUANTITY_TOLERANCE
            ) {
                return null;
            }
        }

        return $locationId;
    }

    /**
     * Every combination stock record of an item, read in one query for the
     * grid: physical and reserved quantity per combination and location.
     *
     * @param int $itemId The item.
     *
     * @return array<int, array<int, array{physical: float, reserved: float}>> [combination_id => [location_id => figures]]
     *
     * @since  2.0.0
     */
    public static function combinationStockByLocation(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn(['combination_id', 'location_id', 'quantity', 'reserved_quantity']))
                ->from($db->qn('#__alfa_inventory_stock'))
                ->where($db->qn('item_id') . ' = ' . $itemId)
                ->where($db->qn('combination_id') . ' > 0'),
        );

        $byCombination = [];

        foreach ($db->loadObjectList() ?: [] as $stockRow) {
            $byCombination[(int) $stockRow->combination_id][(int) $stockRow->location_id] = [
                'physical' => (float) $stockRow->quantity,
                'reserved' => (float) $stockRow->reserved_quantity,
            ];
        }

        return $byCombination;
    }

    /**
     * What the Studio's stock section of one save asks for, and why it would be
     * refused, decided before anything is written.
     *
     * The stock-tracking transition comes from the combination's effective
     * setting before (its stored override, else the product's, else untracked)
     * and after (the posted override, else the product's, else untracked):
     *  - 'on': the product's figures must agree with its stock records, and an
     *    explicit opening figure is required for every location the Studio
     *    rendered (the enabled ones and any holding stock), 0 included; the
     *    openings are the targets and are checked as tracked targets;
     *  - 'off': the product's figures must agree with its stock records and no
     *    open reservation may hold the combination's stock, and no open order
     *    line placed as a stock claim may still rely on the combination (asked
     *    only when no reservation refuses: the switch is refused for one of
     *    the two, as the inventory service refuses it);
     *  - none: every changed per-location figure (different from the figure it
     *    was rendered with) is a target, checked by the inventory service.
     * An opening figure posted without the switch to tracked is refused rather
     * than dropped.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param array $row jform[combinations][<id>] from the Studio.
     * @param array $postedStock jform[combination_inventory_stock]: location => figure.
     * @param array $postedBaseline jform[combination_inventory_stock_baseline]: location => rendered figure.
     * @param array $postedOpening jform[combination_opening_stock]: location => opening figure.
     *
     * @return array{transition: ?string, targets: array<int, float>, refusals: string[]}
     *                                                                                    transition 'on', 'off' or null; the targets the save would apply (the
     *                                                                                    openings for 'on'); translated refusal texts, empty when the save may run.
     *
     * @since  2.0.0
     */
    public static function studioStockPlan(
        int $itemId,
        int $combinationId,
        array $row,
        array $postedStock,
        array $postedBaseline,
        array $postedOpening,
    ): array {
        $plan = ['transition' => null, 'targets' => [], 'refusals' => []];
        $tracking = self::studioTracking(itemId: $itemId, combinationId: $combinationId, row: $row, lock: false);

        // A combination that is not the item's is refused by the save itself.
        if ($tracking === null) {
            return $plan;
        }

        $inventory = new InventoryService();
        $plan['transition'] = $tracking['transition'];

        if ($tracking['transition'] !== null) {
            try {
                $inventory->assertProjectionsAgree(itemId: $itemId);
            } catch (InventoryProjectionMismatchException $e) {
                $plan['refusals'][] = Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE');
            }
        }

        // Opening figures apply only in the save that switches tracking on.
        // Posted without that switch they would change nothing, so the save is
        // refused instead of dropping them: a combination that will not track
        // stock says why; one that already does has no rendered figure to
        // compare them with.
        $openingPosted = array_filter(
            $postedOpening,
            static fn ($figure): bool => !\is_scalar($figure) || trim((string) $figure) !== '',
        ) !== [];

        if ($tracking['transition'] !== 'on' && $openingPosted) {
            $plan['refusals'][] = $tracking['after']
                ? Text::_('COM_ALFA_INLINE_STOCK_INVALID')
                : self::notAppliedText(itemId: $itemId, combinationId: $combinationId, tracks: false);
        }

        if ($tracking['transition'] === 'off') {
            $reservations = $inventory->countActiveReservations(
                itemId: $itemId,
                combinationIds: [$combinationId],
                lock: false,
            );

            if ($reservations > 0) {
                $plan['refusals'][] = Text::sprintf('COM_ALFA_INVENTORY_TRACKING_OFF_RESERVED', $reservations);

                return $plan;
            }

            // Open order lines placed as stock claims rely on the combination's
            // stock whether or not anything is reserved for them. They are asked
            // for only when no reservation refuses, as the inventory service
            // judges the switch: one reason, the reservations first. This read
            // takes no lock; the save judges both again under the service's locks.
            $relying = $inventory->relyingOpenLines(
                itemId: $itemId,
                combinationIds: [$combinationId],
                lock: false,
            );

            if ($relying['lines'] > 0) {
                $plan['refusals'][] = InventoryTrackingException::openLinesText(
                    lines: $relying['lines'],
                    orders: $relying['orders'],
                );
            }

            return $plan;
        }

        if ($tracking['transition'] === 'on') {
            $openings = [];
            $complete = true;

            foreach ((new InventoryAvailabilityService())->locationBreakdown(itemId: $itemId, combinationId: $combinationId) as $location) {
                $openings[(int) $location['location_id']] = null;
            }

            foreach ($postedOpening as $locationId => $figure) {
                $figure = trim((string) $figure);

                if ($figure !== '' || \array_key_exists((int) $locationId, $openings)) {
                    $openings[(int) $locationId] = $figure;
                }
            }

            foreach ($openings as $locationId => $figure) {
                if ($figure === null || $figure === '' || !is_numeric($figure)) {
                    $complete = false;

                    continue;
                }

                $plan['targets'][$locationId] = (float) $figure;
            }

            if (!$complete) {
                $plan['refusals'][] = Text::_('COM_ALFA_INVENTORY_TRACKING_OPENING_REQUIRED');
            }

            if ($plan['targets'] !== []) {
                array_push($plan['refusals'], ...self::targetProblemTexts(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    problems: $inventory->validateTargets(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        targets: $plan['targets'],
                        assumeTracked: true,
                    ),
                ));
            }

            return $plan;
        }

        try {
            $plan['targets'] = self::changedLocationFigures(posted: $postedStock, baseline: $postedBaseline);
        } catch (RuntimeException $e) {
            $plan['refusals'][] = $e->getMessage();

            return $plan;
        }

        if ($plan['targets'] !== [] && $tracking['before']) {
            array_push($plan['refusals'], ...self::targetProblemTexts(
                itemId: $itemId,
                combinationId: $combinationId,
                problems: $inventory->validateTargets(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    targets: $plan['targets'],
                    assumeTracked: false,
                ),
            ));
        }

        return $plan;
    }

    /**
     * What the stock-tracking switch of the last saveSingleCombination() call
     * did, for the caller's warning about stock records kept on a combination
     * that stopped tracking stock.
     *
     * @return array{operation_id: ?string, movements: int, kept_rows: int, kept_on_hand: float}|null
     *                                                                                                The inventory service's changeTracking() result, or null when that
     *                                                                                                save changed no unit's tracking.
     *
     * @since  2.0.0
     */
    public static function lastTrackingChange(): ?array
    {
        return self::$lastTrackingChange;
    }

    /**
     * The per-location figures of a Studio post that differ from the figures
     * the Studio rendered, compared as the back office prints quantities. An
     * empty figure is no command.
     *
     * @param array $posted location_id => figure as posted.
     * @param array $baseline location_id => figure as rendered.
     *
     * @return array<int, float> location_id => target.
     *
     * @throws RuntimeException When a figure is not a number or has no rendered figure.
     *
     * @since  2.0.0
     */
    private static function changedLocationFigures(array $posted, array $baseline): array
    {
        $changes = [];

        foreach ($posted as $locationId => $figure) {
            $locationId = (int) $locationId;
            $figure = trim((string) $figure);

            if ($figure === '') {
                continue;
            }

            $rendered = \array_key_exists($locationId, $baseline) ? trim((string) $baseline[$locationId]) : null;

            if ($locationId <= 0 || $rendered === null || !is_numeric($figure) || !is_numeric($rendered)) {
                throw new RuntimeException(Text::_('COM_ALFA_INLINE_STOCK_INVALID'));
            }

            if (InventoryAvailabilityService::formatQuantity(value: (float) $figure)
                !== InventoryAvailabilityService::formatQuantity(value: (float) $rendered)
            ) {
                $changes[$locationId] = (float) $figure;
            }
        }

        return $changes;
    }

    /**
     * A combination's stock tracking before and after a Studio post.
     *
     * With $lock the stored settings are current reads under row locks, taken
     * in the inventory service's lock order after the caller has locked the
     * item's stock records: every current combination row of the item by id,
     * then the item row. A tracking switch of the item committing meanwhile
     * then waits for the caller's transaction instead of landing between this
     * decision and the caller's write. An archived combination reads as gone.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param array $row jform[combinations][<id>] from the Studio.
     * @param bool $lock Lock and read current rows (inside a transaction).
     *
     * @return array{before: bool, after: bool, value: ?int, transition: ?string}|null
     *                                                                                 value is the override the post stores (0, 1 or null = follow the
     *                                                                                 product); null when the combination is not a current combination of the item.
     *
     * @since  2.0.0
     */
    private static function studioTracking(int $itemId, int $combinationId, array $row, bool $lock): ?array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        if ($lock) {
            // Whole rows by id: a column asked of loadAssocList() answers the whole row when the
            // column is NULL, and NULL is a combination that follows the product.
            $combinationRows = $db->setQuery(
                'SELECT ' . implode(', ', $db->qn(['id', 'manage_stock']))
                . ' FROM ' . $db->qn('#__alfa_item_combinations')
                . ' WHERE ' . $db->qn('item_id') . ' = ' . $itemId
                . ' AND ' . CombinationState::currentSql(column: $db->qn('state'))
                . ' ORDER BY ' . $db->qn('id') . ' FOR UPDATE',
            )->loadAssocList(key: 'id') ?: [];

            $item = $db->setQuery(
                'SELECT ' . $db->qn('manage_stock')
                . ' FROM ' . $db->qn('#__alfa_items')
                . ' WHERE ' . $db->qn('id') . ' = ' . $itemId . ' FOR UPDATE',
            )->loadObject();

            if ($item === null || !\array_key_exists($combinationId, $combinationRows)) {
                return null;
            }

            $stored = $combinationRows[$combinationId]['manage_stock'];
            $storedValue = $stored === null ? null : (int) $stored;
            $itemValue = $item->manage_stock === null ? null : (int) $item->manage_stock;
        } else {
            $stored = $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->qn('c.manage_stock', 'combination_value'), $db->qn('i.manage_stock', 'item_value')])
                    ->from($db->qn('#__alfa_item_combinations', 'c'))
                    ->join('INNER', $db->qn('#__alfa_items', 'i') . ' ON i.id = c.item_id')
                    ->where($db->qn('c.id') . ' = ' . $combinationId)
                    ->where($db->qn('c.item_id') . ' = ' . $itemId)
                    ->where(CombinationState::currentSql(column: $db->qn('c.state'))),
            )->loadObject();

            if ($stored === null) {
                return null;
            }

            $storedValue = $stored->combination_value === null ? null : (int) $stored->combination_value;
            $itemValue = $stored->item_value === null ? null : (int) $stored->item_value;
        }

        $value = \array_key_exists('manage_stock', $row)
            ? self::castCombinationOverride(cast: self::CAST_BOOL, value: $row['manage_stock'])
            : $storedValue;

        $before = ($storedValue ?? $itemValue ?? 0) === 1;
        $after = ((int) ($value ?? $itemValue ?? 0)) === 1;

        return [
            'before' => $before,
            'after' => $after,
            'value' => $value === null ? null : (int) $value,
            'transition' => $before === $after ? null : ($after ? 'on' : 'off'),
        ];
    }

    /**
     * Whether a combination of the item tracks stock: its own setting when it
     * has one, else the product's, else not.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     *
     * @return bool|null Null when the combination is not (or no longer) a
     *                   current combination of the item: gone, archived, or
     *                   its product is archived (every identity of an
     *                   archived product is history only).
     *
     * @since  2.0.0
     */
    private static function combinationTracks(int $itemId, int $combinationId): ?bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $tracks = $db->setQuery(
            $db->getQuery(true)
                ->select('COALESCE(c.manage_stock, i.manage_stock, 0)')
                ->from($db->qn('#__alfa_item_combinations', 'c'))
                ->join('INNER', $db->qn('#__alfa_items', 'i') . ' ON i.id = c.item_id')
                ->where($db->qn('c.id') . ' = ' . $combinationId)
                ->where($db->qn('c.item_id') . ' = ' . $itemId)
                ->where(CombinationState::currentSql(column: $db->qn('c.state')))
                ->where(CombinationState::currentSql(column: $db->qn('i.state'))),
        )->loadResult();

        return $tracks === null ? null : (int) $tracks === 1;
    }

    /**
     * Why a stock figure could not apply to a combination: it does not track
     * stock, its product is archived, it is archived, or it no longer exists.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param bool|null $tracks The combination's tracking; null = it is not a current combination.
     *
     * @return string Translated reason.
     *
     * @since  2.0.0
     */
    private static function notTrackedReason(int $itemId, int $combinationId, ?bool $tracks): string
    {
        if ($tracks !== null) {
            return Text::_('COM_ALFA_COMBI_STOCK_REASON_UNTRACKED');
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $itemState = $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn('state'))
                ->from($db->qn('#__alfa_items'))
                ->where($db->qn('id') . ' = ' . $itemId),
        )->loadResult();

        // An archived product takes no stock figure on any of its identities.
        if ($itemState !== null && !CombinationState::isCurrent(state: $itemState)) {
            return Text::_('COM_ALFA_ITEM_STOCK_REASON_ARCHIVED');
        }

        $archived = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->qn('#__alfa_item_combinations'))
                ->where($db->qn('id') . ' = ' . $combinationId)
                ->where($db->qn('item_id') . ' = ' . $itemId)
                ->where($db->qn('state') . ' = ' . CombinationState::ARCHIVED),
        )->loadResult() > 0;

        return Text::_($archived ? 'COM_ALFA_COMBI_STOCK_REASON_ARCHIVED' : 'COM_ALFA_COMBI_STOCK_REASON_GONE');
    }

    /**
     * The warning for a combination whose stock figure was not applied. Every
     * screen shows it as markup, so the variant's name is escaped here, once.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param bool|null $tracks The combination's tracking; null = it is not a current combination.
     *
     * @return string Translated warning naming the variant (escaped) and the reason.
     *
     * @since  2.0.0
     */
    private static function notAppliedText(int $itemId, int $combinationId, ?bool $tracks): string
    {
        return Text::sprintf(
            'COM_ALFA_COMBI_STOCK_NOT_APPLIED',
            self::escapedText(text: self::combinationLabels(itemId: $itemId)[$combinationId] ?? ('#' . $combinationId)),
            self::notTrackedReason(itemId: $itemId, combinationId: $combinationId, tracks: $tracks),
        );
    }

    /**
     * Translated texts for the inventory service's target problems
     * ({@see InventoryService::validateTargets()}). A target on a combination
     * that does not track stock, no longer exists, or belongs to an archived
     * product is not a refusal here: the save reports it as not applied. A unit
     * whose figures claim stock without stock records, or that is held until its
     * figures are repaired, is named by the figures the problem carries, in the
     * inventory service's own sentence. So is a target that would lower the
     * stock at its location below what open orders reserve there, or lower
     * stock already below it, with the unit and location names escaped once.
     *
     * @param int $itemId The owning item of the unit the problems belong to.
     * @param int $combinationId The combination the problems belong to.
     * @param array $problems list of {location_id, code, stock?, available?, on_hand?, reserved?, requested?}.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private static function targetProblemTexts(int $itemId, int $combinationId, array $problems): array
    {
        if ($problems === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $titles = $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn(['id', 'title']))
                ->from($db->qn('#__alfa_inventory_locations')),
        )->loadAssocList('id', 'title') ?: [];

        $texts = [];

        foreach ($problems as $problem) {
            $problem = (array) $problem;
            $locationId = (int) ($problem['location_id'] ?? 0);
            $location = (string) ($titles[$locationId] ?? ('#' . $locationId));

            $text = match ((string) ($problem['code'] ?? '')) {
                'not_a_location' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_NOT_A_LOCATION', '#' . $locationId),
                'location_disabled' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_LOCATION_DISABLED', $location),
                'negative_floor' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_NEGATIVE', $location),
                // The unit's figures as the inventory service reports them: the
                // ones it shows without stock records, or those it showed when
                // its hold began.
                'ledger_missing', 'integrity_held' => InventoryIntegrityException::refusalText(
                    stock: isset($problem['stock']) ? (float) $problem['stock'] : null,
                    available: isset($problem['available']) ? (float) $problem['available'] : null,
                    held: $problem['code'] === 'integrity_held',
                ),
                // Below what open orders reserve there: the service's own sentence, names escaped once.
                'reserved_floor' => self::escapedText(text: InventoryReservedFloorException::refusalText(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    locationId: $locationId,
                    onHand: (float) $problem['on_hand'],
                    reserved: (float) $problem['reserved'],
                    requested: (float) $problem['requested'],
                )),
                default => null,
            };

            if ($text !== null && !\in_array($text, $texts, true)) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * The inventory service's retirement blockers that actually hold something.
     *
     * @param array $blockers list of {combination_id, rows_not_zero, reserved, active_reservations, pending_effects, open_lines, ...}.
     *
     * @return array The blockers with stock records not at zero, open
     *               reservations, pending stock effects, or open order lines
     *               that still have stock to deduct from the unit.
     *
     * @since  2.0.0
     */
    private static function heldBlockers(array $blockers): array
    {
        return array_values(array_filter(
            array_map(static fn ($blocker): array => (array) $blocker, $blockers),
            static fn (array $blocker): bool => self::holdsStock(blocker: $blocker)
                || (array) ($blocker['open_lines'] ?? []) !== [],
        ));
    }

    /**
     * Whether a retirement blocker holds stock itself: a stock record not at
     * zero, an open reservation or a pending stock effect.
     *
     * @param array $blocker One of the inventory service's retirement blockers.
     *
     *
     * @since  2.0.0
     */
    private static function holdsStock(array $blocker): bool
    {
        return (int) ($blocker['rows_not_zero'] ?? 0) > 0
            || (int) ($blocker['active_reservations'] ?? 0) > 0
            || (int) ($blocker['pending_effects'] ?? 0) > 0;
    }

    /**
     * One message about what still holds the product's own stock, from the
     * inventory service's retirement blockers of that stock: the key's text
     * counting its stock records, reservations and pending stock effects when
     * it holds any, followed by the sentence naming every open order line that
     * still has stock to deduct from it. When only such lines hold it, that
     * sentence stands alone. For a screen that shows it as markup, the names
     * that sentence carries are escaped.
     *
     * @param int $itemId The product.
     * @param string $key COM_ALFA_COMBI_GENERATE_PARENT_STOCK or COM_ALFA_COMBI_CHANGE_PARENT_STOCK.
     * @param array $blockers list of {rows_not_zero, reserved, active_reservations, pending_effects, open_lines, ...}.
     * @param bool $markup The text is shown as markup (true) or as plain text (false).
     *
     * @return string Translated text.
     *
     * @since  2.0.0
     */
    private static function stockHeldText(int $itemId, string $key, array $blockers, bool $markup): string
    {
        $held = self::heldBlockers(blockers: $blockers);
        $rowsNotZero = 0;
        $reserved = 0.0;
        $activeReservations = 0;
        $pendingEffects = 0;
        $holdsStock = false;

        foreach ($held as $blocker) {
            $rowsNotZero += (int) ($blocker['rows_not_zero'] ?? 0);
            $reserved += (float) ($blocker['reserved'] ?? 0);
            $activeReservations += (int) ($blocker['active_reservations'] ?? 0);
            $pendingEffects += (int) ($blocker['pending_effects'] ?? 0);
            $holdsStock = $holdsStock || self::holdsStock(blocker: $blocker);
        }

        $stockText = Text::sprintf(
            $key,
            $rowsNotZero,
            InventoryAvailabilityService::formatQuantity(value: $reserved),
            $activeReservations,
            $pendingEffects,
        );
        $openLinesText = InventoryUnitRetirementException::openLinesText(itemId: $itemId, blockers: $held);

        if ($openLinesText !== null && $markup) {
            $openLinesText = self::escapedText(text: $openLinesText);
        }

        return match (true) {
            $openLinesText === null => $stockText,
            $holdsStock => $stockText . ' ' . $openLinesText,
            default => $openLinesText,
        };
    }

    /**
     * A text that carries names typed in the back office (product names,
     * variant values), escaped once for the markup a refusal or warning is shown in.
     *
     * @param string $text The text.
     *
     *
     * @since  2.0.0
     */
    private static function escapedText(string $text): string
    {
        return htmlspecialchars(string: $text, flags: ENT_QUOTES, encoding: 'UTF-8');
    }

    /**
     * One message per deleted combination that still holds stock or that open
     * order lines still rely on: the stock it holds (records, reservations,
     * pending stock effects) when it holds any, followed by the sentence naming
     * every open order line that still has stock to deduct from it. When only
     * such lines hold it, that sentence stands alone. The variant and product
     * names the texts carry are escaped.
     *
     * @param int $itemId The owning item.
     * @param array $blockers The inventory service's retirement blockers of the deleted combinations.
     *
     * @return string[] Translated texts, one per blocked combination.
     *
     * @since  2.0.0
     */
    private static function deletionBlockedTexts(int $itemId, array $blockers): array
    {
        $held = self::heldBlockers(blockers: $blockers);

        if ($held === []) {
            return [];
        }

        $labels = self::combinationLabels(itemId: $itemId);
        $texts = [];

        foreach ($held as $blocker) {
            $combinationId = (int) ($blocker['combination_id'] ?? 0);

            $stockText = Text::sprintf(
                'COM_ALFA_COMBI_DELETE_STOCK_HELD',
                self::escapedText(text: $labels[$combinationId] ?? ('#' . $combinationId)),
                (int) ($blocker['rows_not_zero'] ?? 0),
                InventoryAvailabilityService::formatQuantity(value: (float) ($blocker['reserved'] ?? 0)),
                (int) ($blocker['active_reservations'] ?? 0),
                (int) ($blocker['pending_effects'] ?? 0),
            );
            $openLinesText = InventoryUnitRetirementException::openLinesText(itemId: $itemId, blockers: [$blocker]);

            $texts[] = match (true) {
                $openLinesText === null => $stockText,
                self::holdsStock(blocker: $blocker) => $stockText . ' ' . self::escapedText(text: $openLinesText),
                default => self::escapedText(text: $openLinesText),
            };
        }

        return $texts;
    }

    /**
     * The combinations among $combinationIds that order history names: an order
     * line of the item was sold as it (lines of every order on record, trashed
     * ones included), or an order line's stock was bound to it by a recorded
     * resolution. Such a combination is the stock identity those lines are
     * fulfilled, cancelled and returned against, so it is archived instead of
     * deleted.
     *
     * Both reads take shared locks inside the caller's transaction, so no order
     * line or resolution naming one of them can be recorded between this answer
     * and the deletion.
     *
     * @param int $itemId The owning item.
     * @param int[] $combinationIds Combinations of the item about to be removed.
     *
     * @return array<int, int> combination_id => number of order lines naming it;
     *                         combinations without order history are absent.
     *
     * @since  2.0.0
     */
    private static function orderHistoryCombinationIds(int $itemId, array $combinationIds): array
    {
        $combinationIds = array_values(array_unique(array_filter(
            array_map('intval', $combinationIds),
            static fn (int $combinationId): bool => $combinationId > 0,
        )));

        if ($itemId <= 0 || $combinationIds === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $idList = implode(',', $combinationIds);
        $lines = [];

        $soldAs = $db->setQuery(
            'SELECT ' . implode(', ', $db->qn(['id', 'id_combination']))
            . ' FROM ' . $db->qn('#__alfa_order_items')
            . ' WHERE ' . $db->qn('id_item') . ' = ' . $itemId
            . ' AND ' . $db->qn('id_combination') . ' IN (' . $idList . ')'
            . ' LOCK IN SHARE MODE',
        )->loadObjectList() ?: [];

        foreach ($soldAs as $line) {
            $lines[(int) $line->id_combination][(int) $line->id] = true;
        }

        $boundTo = $db->setQuery(
            'SELECT ' . implode(', ', $db->qn(['order_item_id', 'resolved_combination_id']))
            . ' FROM ' . $db->qn('#__alfa_inventory_line_resolutions')
            . ' WHERE ' . $db->qn('resolved_item_id') . ' = ' . $itemId
            . ' AND ' . $db->qn('resolved_combination_id') . ' IN (' . $idList . ')'
            . ' LOCK IN SHARE MODE',
        )->loadObjectList() ?: [];

        foreach ($boundTo as $resolution) {
            $lines[(int) $resolution->resolved_combination_id][(int) $resolution->order_item_id] = true;
        }

        return array_map('count', $lines);
    }

    /**
     * The grid labels of an item's combinations (value names in the current
     * language), for messages that name a variant.
     *
     * @param int $itemId The item.
     *
     * @return array<int, string> combination_id => label.
     *
     * @since  2.0.0
     */
    private static function combinationLabels(int $itemId): array
    {
        $labels = [];

        foreach (self::getCombinations(itemId: $itemId) as $combination) {
            $labels[(int) $combination->id] = (string) $combination->label !== ''
                ? (string) $combination->label
                : '#' . (int) $combination->id;
        }

        return $labels;
    }

    /**
     * The requested deletions that name a combination still in the table.
     *
     * @param array $existing The item's combination rows keyed by id.
     * @param string $deletedCombinationIds Comma list of requested ids.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private static function deletableIds(array $existing, string $deletedCombinationIds): array
    {
        $requested = array_values(array_filter(array_map('intval', explode(',', $deletedCombinationIds))));

        return array_values(array_intersect($requested, array_map('intval', array_keys($existing))));
    }

    /**
     * The grid stock cells a payload posts for surviving combinations.
     *
     * @param array $existing The item's combination rows keyed by id.
     * @param array $payload The jform[combinations] array.
     * @param int[] $deletedIds Combinations the save deletes.
     *
     * @return array<int, array{posted: ?string, baseline: ?string}> An empty
     *                                                               figure counts as not posted; an empty baseline as missing.
     *
     * @since  2.0.0
     */
    private static function gridStockEdits(array $existing, array $payload, array $deletedIds): array
    {
        $edits = [];

        foreach ($payload as $combinationId => $row) {
            $combinationId = (int) $combinationId;
            $row = (array) $row;

            if (!isset($existing[$combinationId]) || \in_array($combinationId, $deletedIds, true)
                || !\array_key_exists('stock', $row)
            ) {
                continue;
            }

            $posted = trim((string) $row['stock']);
            $baseline = trim((string) ($row['stock_baseline'] ?? ''));

            $edits[$combinationId] = [
                'posted' => $posted === '' ? null : $posted,
                'baseline' => $baseline === '' ? null : $baseline,
            ];
        }

        return $edits;
    }

    /**
     * Write the grid's price cell of one combination: its single any-context
     * price row, a full price ('price', shown as Set) or a +/-/% adjustment of
     * the item price ('adjust'). A combination with two or more price rows, or
     * with one row bound to a context, a quantity range or a publish window,
     * is the Studio's: the grid never writes it and never adds a row beside it.
     *
     * Only what the operator changed on the page is written
     * ({@see self::quickCellChanges()}): a cell posted back as it was
     * rendered costs no read and no statement, even when the Studio has saved
     * the row since the page was opened. The statement names only the grid
     * columns the change moves ({@see self::quickRowPlan()}). The grid never
     * writes a row's custom discount, its Enabled state, its context, quantity
     * range or publish window: the Studio owns those.
     *
     * An edit that would take a custom discount with it is refused: switching
     * a full price that carries one to an adjustment, or clearing its price.
     * The custom discount is switched off in the variant's Edit window first.
     * The variant's rows are read under a lock that holds until the grid save
     * commits, so a save of the same variant in its Edit window waits for the
     * grid and never lands between the grid's read and its write.
     *
     * @param int $itemId The owning item id.
     * @param int $combinationId The combination id.
     * @param array $quick The posted cell: mode (price|adjust), operation (+|-|%|-%), value, target (start|final).
     * @param array|null $baseline The same fields as the page rendered them; null when none was posted.
     *
     *
     * @throws RuntimeException When the edit would remove a custom discount.
     *
     * @since  2.0.0
     */
    protected static function upsertQuickRow(int $itemId, int $combinationId, array $quick, ?array $baseline): void
    {
        $changes = self::quickCellChanges(quick: $quick, baseline: $baseline);

        if ($changes === []) {
            return;
        }

        $plan = self::quickRowPlan(
            rows:    self::variantPriceRows(combinationIds: [$combinationId], lock: true)[$combinationId] ?? [],
            quick:   $quick,
            changes: $changes,
        );

        if ($plan['action'] === 'refuse') {
            throw new RuntimeException(self::keepsDiscountText(
                label: self::combinationLabels(itemId: $itemId)[$combinationId] ?? ('#' . $combinationId),
            ));
        }

        if ($plan['action'] === 'none') {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        if ($plan['action'] === 'insert') {
            $price = (object) array_merge([
                'item_id' => $itemId,
                'combination_id' => $combinationId,
                'override_value' => null,
                'currency_id' => 0,
                'country_id' => 0,
                'usergroup_id' => 0,
                'user_id' => 0,
                'quantity_start' => null,
                'quantity_end' => null,
                'publish_up' => null,
                'publish_down' => null,
                'state' => 1,
            ], $plan['set']);
            $db->insertObject('#__alfa_items_prices', $price);

            return;
        }

        $query = $plan['action'] === 'delete'
            ? $db->getQuery(true)->delete($db->qn('#__alfa_items_prices'))
            : $db->getQuery(true)->update($db->qn('#__alfa_items_prices'));

        foreach ($plan['set'] as $column => $value) {
            $query->set($db->qn($column) . ' = ' . ($value === null ? 'NULL' : $db->quote($value)));
        }

        $query->where($db->qn('id') . ' = ' . $plan['rowId']);
        $db->setQuery($query)->execute();
    }

    /**
     * The fields of a grid price cell the operator changed on the page: each
     * posted field compared with the value the cell was rendered with. The
     * operation and the net/gross target count only for an adjustment, the
     * cell's only mode that shows them. A value is compared as a number, so a
     * price retyped as its own Final ("100.00000000") is no change. A field the
     * page did not render a value for counts as changed: the payload states it.
     *
     * @param array $quick The posted cell (mode, operation, value, target); empty for a cell the grid locks.
     * @param array|null $baseline The cell as rendered; null when none was posted.
     *
     * @return string[] The changed fields among mode, value, operation, target.
     *
     * @since  2.0.0
     */
    private static function quickCellChanges(array $quick, ?array $baseline): array
    {
        if ($quick === []) {
            return [];
        }

        $baseline ??= [];
        $posted = self::quickCell(cell: $quick);
        $rendered = self::quickCell(cell: $baseline);
        $fields = $posted['mode'] === 'adjust' ? ['mode', 'value', 'operation', 'target'] : ['mode', 'value'];
        $changes = [];

        foreach ($fields as $field) {
            $same = \array_key_exists($field, $baseline) && ($field === 'value'
                ? self::sameQuickValue(a: $posted['value'], b: $rendered['value'])
                : $posted[$field] === $rendered[$field]);

            if (!$same) {
                $changes[] = $field;
            }
        }

        return $changes;
    }

    /**
     * What a changed grid price cell asks of the combination's price rows. One
     * rule for the save and for the refusals the item form checks first.
     *
     * - Two or more rows, or one row bound to a context, a quantity range or a
     *   publish window: nothing (the Studio's).
     * - The value cleared on the page: the any-context row is deleted; refused
     *   when it carries a custom discount.
     * - No row and a price typed on the page: the cell is inserted, enabled.
     * - A row in the other mode: it is set to the cell's mode with the cell's
     *   amounts, which mean something else in the other mode. To an
     *   adjustment it is refused when the row carries a custom discount; to a
     *   full price the operation is cleared and override_type is 'start' again
     *   (an adjustment holds no custom discount; the column held its target).
     * - A row in the same mode: each changed field whose stored value differs.
     *
     * A custom discount is a non-NULL override_value.
     *
     * @param array $rows Every price row of the combination (assoc rows, by id).
     * @param array $quick The posted cell.
     * @param string[] $changes {@see self::quickCellChanges()}.
     *
     * @return array{action: string, rowId: int, set: array<string, mixed>}
     *                                                                      action: none, insert, update, delete or refuse; set: column => value.
     *
     * @since  2.0.0
     */
    private static function quickRowPlan(array $rows, array $quick, array $changes): array
    {
        $none = ['action' => 'none', 'rowId' => 0, 'set' => []];
        $row = \count($rows) === 1 ? reset($rows) : null;

        if ($changes === [] || \count($rows) >= 2 || ($row !== null && !self::isAnyContextPriceRow(row: $row))) {
            return $none;
        }

        $cell = self::quickCell(cell: $quick);
        $hasDiscount = $row !== null && $row['override_value'] !== null;
        $rowId = $row === null ? 0 : (int) $row['id'];
        $wants = [
            'mode' => $cell['mode'],
            'operation' => $cell['mode'] === 'adjust' ? $cell['operation'] : null,
            'value' => (float) $cell['value'],
            'override_type' => $cell['mode'] === 'adjust' ? $cell['target'] : 'start',
        ];

        if ($cell['value'] === '') {
            if ($row === null || !\in_array('value', $changes, true)) {
                return $none;
            }

            return $hasDiscount
                ? ['action' => 'refuse', 'rowId' => $rowId, 'set' => []]
                : ['action' => 'delete', 'rowId' => $rowId, 'set' => []];
        }

        if ($row === null) {
            return \in_array('value', $changes, true)
                ? ['action' => 'insert', 'rowId' => 0, 'set' => $wants]
                : $none;
        }

        $storedMode = (string) $row['mode'] === 'adjust' ? 'adjust' : 'price';

        if ($storedMode !== $cell['mode']) {
            if ($cell['mode'] === 'adjust' && $hasDiscount) {
                return ['action' => 'refuse', 'rowId' => $rowId, 'set' => []];
            }

            $columns = array_keys($wants);
        } else {
            $columns = [];

            foreach (['value' => 'value', 'operation' => 'operation', 'target' => 'override_type'] as $field => $column) {
                if (\in_array($field, $changes, true)) {
                    $columns[] = $column;
                }
            }
        }

        $set = [];

        foreach ($columns as $column) {
            $stored = $row[$column];
            $same = match ($column) {
                'value' => $stored !== null && (float) $stored === $wants['value'],
                'operation' => ($stored ?? ($storedMode === 'adjust' ? '+' : null)) === $wants['operation'],
                default => $stored === $wants[$column],
            };

            if (!$same) {
                $set[$column] = $wants[$column];
            }
        }

        if ($set === []) {
            return $none;
        }

        return ['action' => 'update', 'rowId' => $rowId, 'set' => $set];
    }

    /**
     * A grid price cell's fields, read the way the grid renders and posts them.
     *
     * @param array $cell mode, operation, value, target as posted or rendered.
     *
     * @return array{mode: string, operation: string, value: string, target: string}
     *
     * @since  2.0.0
     */
    private static function quickCell(array $cell): array
    {
        return [
            'mode' => (string) ($cell['mode'] ?? 'adjust') === 'price' ? 'price' : 'adjust',
            'operation' => \in_array($cell['operation'] ?? '', ['+', '-', '%', '-%'], true) ? (string) $cell['operation'] : '+',
            'value' => trim((string) ($cell['value'] ?? '')),
            'target' => \in_array($cell['target'] ?? '', ['start', 'final'], true) ? (string) $cell['target'] : 'start',
        ];
    }

    /**
     * Whether two typed prices are the same price: both empty, or the same number.
     *
     * @param string $a A price as typed or rendered.
     * @param string $b Another.
     *
     *
     * @since  2.0.0
     */
    private static function sameQuickValue(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || !is_numeric($a) || !is_numeric($b)) {
            return $a === $b;
        }

        return (float) $a === (float) $b;
    }

    /**
     * Whether a price row applies in any context: no currency, place, group or
     * customer, no quantity range and no publish window.
     *
     * @param array $row A price row.
     *
     *
     * @since  2.0.0
     */
    private static function isAnyContextPriceRow(array $row): bool
    {
        foreach (['currency_id', 'country_id', 'usergroup_id', 'user_id'] as $column) {
            if ((int) ($row[$column] ?? 0) !== 0) {
                return false;
            }
        }

        foreach (['quantity_start', 'quantity_end', 'publish_up', 'publish_down'] as $column) {
            if (($row[$column] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every price row of these combinations, in one read. Locked, the rows (and
     * the room for a new one) stay locked until the caller's transaction ends:
     * another save of the same variants waits for it.
     *
     * @param int[] $combinationIds The combinations.
     * @param bool $lock Read them FOR UPDATE, inside the caller's transaction.
     *
     * @return array<int, list<array>> combination_id => its rows, by id.
     *
     * @since  2.0.0
     */
    private static function variantPriceRows(array $combinationIds, bool $lock): array
    {
        $combinationIds = array_values(array_filter(array_map('intval', $combinationIds), static fn (int $id): bool => $id > 0));

        if ($combinationIds === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $rows = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->qn(array_merge(['combination_id'], self::PRICE_ROW_COLUMNS)))
                ->from($db->qn('#__alfa_items_prices'))
                ->where($db->qn('combination_id') . ' IN (' . implode(',', $combinationIds) . ')')
                ->order($db->qn('id') . ' ASC') . ($lock ? ' FOR UPDATE' : ''),
        )->loadAssocList() ?: [] as $row) {
            $rows[(int) $row['combination_id']][] = $row;
        }

        return $rows;
    }

    /**
     * The state of a variant's price rows as one digest: every row's id and
     * stored columns, in id order. The Edit window posts the digest of the rows
     * it rendered, and its save compares it with the rows stored at that moment.
     *
     * @param array $rows The variant's price rows, as read from the table.
     *
     *
     * @since  2.0.0
     */
    public static function priceRowsState(array $rows): string
    {
        $state = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $state[(int) ($row['id'] ?? 0)] = array_map(
                static fn (string $column): ?string => isset($row[$column]) ? (string) $row[$column] : null,
                self::PRICE_ROW_COLUMNS,
            );
        }

        ksort($state);

        return md5(json_encode($state));
    }

    /**
     * The refusal of a grid edit that would remove a variant's custom discount.
     *
     * @param string $label The variant's grid label.
     *
     * @return string Translated, the label escaped for the message markup.
     *
     * @since  2.0.0
     */
    private static function keepsDiscountText(string $label): string
    {
        return Text::sprintf('COM_ALFA_COMBI_QUICK_KEEPS_DISCOUNT', self::escapedText(text: $label));
    }

    /**
     * Persist ONE combination's Studio payload atomically; the popup's own
     * save: identity (gtin/mpn), logistics overrides (blank = inherit),
     * sparse translatable texts and the price editor rows. The gallery is
     * saved separately by the controller through MediaHelper (origin
     * 'combination').
     *
     * When the posted stock-tracking override changes whether the combination
     * tracks stock, the inventory service writes that override inside this
     * save's transaction, with the opening stock when tracking goes on
     * ({@see self::studioStockPlan()} builds the openings and every knowable
     * refusal beforehand); the combination row write then leaves the override
     * alone. A refused switch throws its merchant-readable text and nothing of
     * the save is kept; a failure later in the save rolls the switch back too.
     * An override that changes no unit's tracking is an ordinary column write.
     * Whichever it is, a posted override is decided under the inventory
     * service's locks, taken first in the transaction, so a concurrent switch
     * of the item's own tracking cannot change the answer before the write.
     * {@see self::lastTrackingChange()} reports what the switch did.
     *
     * Quantity rules are exact decimals, judged before anything is written
     * ({@see self::changedQuantityRules()}): a refused set throws its text
     * naming the combination. Only a rule the save changes is written, so a
     * stored set the save leaves alone is never rewritten. When the save
     * changes a quantity rule, the backorder action or the safety stock, the
     * inventory service records the sale-rule edit inside the same transaction,
     * which moves the inventory version that cached listings are keyed on.
     *
     * @param int $itemId The owning item id.
     * @param int $combinationId The combination id.
     * @param array $row jform[combinations][<id>] from the popup.
     * @param array $priceRows jform[combination_prices][<id>] from the popup.
     * @param array|null $openingTargets location_id => opening quantity, used when
     *                                   tracking goes on; null when none were given.
     *
     * @return bool False when the combination is not a current combination of
     *              the item (another item's, deleted, or archived); nothing is saved.
     *
     * The window posts the state it rendered the variant's price rows in
     * ($row['price_rows_rendered'], {@see self::priceRowsState()}). When the rows
     * stored now differ from it, they were changed elsewhere since: the save is
     * refused and nothing of it is written. A save that posts no rendered state
     * (a programmatic caller) states its rows as they are.
     *
     * @throws RuntimeException When the quantity rules or the stock-tracking switch are refused, or the
     *                          price rows were changed elsewhere since the window rendered them.
     *
     * @since  2.0.0
     */
    public static function saveSingleCombination(int $itemId, int $combinationId, array $row, array $priceRows, ?array $openingTargets): bool
    {
        self::$lastTrackingChange = null;

        $db = Factory::getContainer()->get('DatabaseDriver');
        $existing = self::loadCombinationRows(itemId: $itemId);

        if (!isset($existing[$combinationId])) {
            return false;
        }

        // Quantity rules are judged before anything is written; a refusal throws.
        $changedRules = self::changedQuantityRules(
            itemId: $itemId,
            combinationId: $combinationId,
            stored: $existing[$combinationId],
            row: $row,
        );

        $update = (object) [
            'id' => $combinationId,
            'sku' => trim((string) ($row['sku'] ?? '')),
            // stock/available_quantity are inventory PROJECTIONS, written
            // only by InventoryService, never here.
            'gtin' => (string) ($row['gtin'] ?? ''),
            'mpn' => (string) ($row['mpn'] ?? ''),
            'modified_by' => (int) Factory::getApplication()->getIdentity()?->id,
        ];

        // Every scalar override, cast by the ONE registry the editor renders from:
        // no parallel column list to fall out of step with it.
        foreach (self::COMBINATION_OVERRIDE_CASTS as $column => $cast) {
            // ABSENT, not empty: a control the editor did not draw (a virtual
            // product has no dimension overrides) must leave its column alone.
            // Clearing one posts '' and is still a submitted decision.
            if (!\array_key_exists($column, $row)) {
                continue;
            }

            // A quantity rule the save does not change keeps its stored value as it is.
            if ($cast === self::CAST_QUANTITY_RULE && !\array_key_exists($column, $changedRules)) {
                continue;
            }

            $update->{$column} = self::castCombinationOverride(cast: $cast, value: $row[$column]);
        }

        $saleRulesChanged = $changedRules !== []
            || self::saleOverridesChanged(stored: $existing[$combinationId], update: $update);

        // "Atomically" per the contract: the combination row, its translations, and
        // its price rows are three multi-table writes: a failure mid-way must not
        // leave a combination whose translations or prices are half-updated. The
        // called helpers resolve the same shared driver, so one transaction covers all.
        // The variant language tables are made BEFORE it opens: DDL commits implicitly.
        self::ensureCombinationLangSchema();

        $trackingChange = null;
        $inventory = new InventoryService();

        $db->transactionStart(true);

        try {
            $tracking = null;

            // A posted stock-tracking override is judged on what is stored now,
            // under locks: the item's stock records first, the inventory
            // service's lock order, then its combination rows and the item row.
            // A switch of the item's own tracking waits for this save instead of
            // landing between the decision and the write.
            if (\array_key_exists('manage_stock', $row)) {
                $inventory->lockItemStock(itemId: $itemId);
                $tracking = self::studioTracking(itemId: $itemId, combinationId: $combinationId, row: $row, lock: true);

                // Deleted or archived before its lock was taken: nothing is saved.
                if ($tracking === null) {
                    TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('The combination is gone or archived.'));

                    return false;
                }
            }

            // The window's price rows must still be the rows it rendered: changed
            // since in another window, in the grid or by another operator, they are
            // not written over. Read under a lock, after the inventory's, so no
            // other save changes them before this one commits.
            if (\is_string($row['price_rows_rendered'] ?? null)) {
                $storedRows = self::variantPriceRows(combinationIds: [$combinationId], lock: true)[$combinationId] ?? [];

                if (self::priceRowsState(rows: $storedRows) !== $row['price_rows_rendered']) {
                    throw new RuntimeException(Text::sprintf(
                        'COM_ALFA_COMBI_STUDIO_CHANGED_ELSEWHERE',
                        self::escapedText(text: self::combinationLabels(itemId: $itemId)[$combinationId] ?? ('#' . $combinationId)),
                    ));
                }
            }

            if ($tracking !== null && $tracking['transition'] !== null) {
                unset($update->manage_stock);

                try {
                    $trackingChange = $inventory->changeTracking(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        value: $tracking['value'],
                        openingTargets: $tracking['transition'] === 'on' ? $openingTargets : null,
                        context: InventoryContext::ambient(),
                    );
                } catch (InventoryTrackingException | InventoryProjectionMismatchException | InventoryIntegrityException | InventoryReservedFloorException $e) {
                    throw new RuntimeException(
                        self::trackingRefusalText(
                            itemId: $itemId,
                            combinationId: $combinationId,
                            refusal: $e,
                        ),
                        0,
                        $e,
                    );
                }
            }

            $db->updateObject('#__alfa_item_combinations', $update, 'id', true);

            // A changed sale rule changes which quantities sell: the inventory
            // version moves with it, so cached listings are rebuilt.
            if ($saleRulesChanged) {
                $inventory->recordSaleRules(itemId: $itemId, context: InventoryContext::ambient());
            }

            self::saveCombinationTranslations(combinationId: $combinationId, row: $row);
            self::syncCombinationPrices(itemId: $itemId, combinationId: $combinationId, rows: $priceRows);

            // What the inventory service announces for this save waits for this commit.
            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            throw $e;
        }

        self::$lastTrackingChange = $trackingChange;

        return true;
    }

    /**
     * The merchant-readable text of a refused stock-tracking switch, as markup
     * (the inventory service's own sentence escaped).
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param Throwable $refusal The inventory service's refusal.
     *
     * @return string Translated text.
     *
     * @since  2.0.0
     */
    private static function trackingRefusalText(int $itemId, int $combinationId, Throwable $refusal): string
    {
        if ($refusal instanceof InventoryProjectionMismatchException) {
            return Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE');
        }

        // The inventory service's own sentence: it tells a held unit, which has
        // stock records, from one whose figures have none behind them. It is
        // shown as markup, so it is escaped here, once.
        if ($refusal instanceof InventoryIntegrityException) {
            return self::escapedText(text: $refusal->operatorMessage());
        }

        // An opening below what open orders reserve: the service's own sentence, escaped once.
        if ($refusal instanceof InventoryReservedFloorException) {
            return self::escapedText(text: $refusal->operatorMessage());
        }

        if ($refusal instanceof InventoryTrackingException) {
            switch ($refusal->reason) {
                case InventoryTrackingException::RESERVED:
                    return Text::sprintf('COM_ALFA_INVENTORY_TRACKING_OFF_RESERVED', $refusal->count);

                case InventoryTrackingException::OPEN_LINES:
                    // Open order lines still rely on the combination: the inventory
                    // service's own sentence, which carries two counts and no name.
                    return InventoryTrackingException::openLinesText(lines: $refusal->count, orders: $refusal->orders);

                case InventoryTrackingException::OPENING_REQUIRED:
                    return Text::_('COM_ALFA_INVENTORY_TRACKING_OPENING_REQUIRED');

                case InventoryTrackingException::VARIANTS_INHERIT:
                    return Text::sprintf('COM_ALFA_ITEM_TRACKING_ON_VARIANTS_INHERIT', $refusal->count);

                case InventoryTrackingException::TARGET:
                    // The refused opening, named the way the pre-save check names it.
                    $texts = self::targetProblemTexts(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        problems: [['location_id' => (int) $refusal->locationId, 'code' => (string) $refusal->targetCode]],
                    );

                    if ($texts !== []) {
                        return implode(' ', $texts);
                    }
            }
        }

        return $refusal->getMessage();
    }

    /**
     * SPARSE translatable overrides, on two axes.
     *
     * Per LANGUAGE: a row exists only when something is overridden, and a row
     * left entirely blank is deleted. Cleared values store as '' rather than
     * NULL: the aux schema types a short field as VARCHAR NOT NULL DEFAULT ''
     * and only the LONGTEXT ones as NULL (MultilingualHelper::columnTypeForField),
     * so '' is the one empty marker every column accepts. Readers agree: the
     * frontend overlay treats '' and NULL alike (`($t[$f] ?? '') !== ''`).
     *
     * Per COLUMN: only the columns the payload actually carries are written.
     * The Studio posts all of them, the grid's name quick-edit posts `name`
     * alone, and a column the editor never drew must keep its stored value.
     * A wholesale delete+reinsert here would let a name-only save wipe the
     * descriptions and stock messages typed in the Studio.
     *
     * @param int $combinationId The combination id.
     * @param array $row The combination payload (name_<lang>, short_desc_<lang> keys).
     *
     *
     * @since  2.0.0
     */
    protected static function saveCombinationTranslations(int $combinationId, array $row): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $fields = self::combinationTranslatableFields();

        self::ensureCombinationLangSchema();

        foreach (self::contentLanguageKeys() as $langSuffix) {
            // ABSENT, not empty: a control the editor never drew leaves its
            // column alone. Clearing one posts '' and is still a decision.
            $posted = [];

            foreach ($fields as $field) {
                $key = $field . '_' . $langSuffix;

                if (\array_key_exists($key, $row)) {
                    $value = trim((string) $row[$key]);

                    // `name` is filter="safehtml" on the item form, but nothing
                    // cleans it here: the payload arrives nested under
                    // combinations[<id>], which mergeValidatedFormData copies
                    // verbatim (it only cleans top-level scalars). This override
                    // REPLACES the product title on the storefront, so it gets
                    // the same cleaning its item-level twin does.
                    if ($field === 'name' && $value !== '') {
                        $value = InputFilter::getInstance(
                            [],
                            [],
                            InputFilter::ONLY_BLOCK_DEFINED_TAGS,
                            InputFilter::ONLY_BLOCK_DEFINED_ATTRIBUTES,
                        )->clean($value, 'html');
                    }

                    $posted[$field] = $value;
                }
            }

            if (!$posted) {
                continue;
            }

            $table = $db->qn('#__alfa_item_combinations_' . $langSuffix);

            // Upsert the posted columns only: id_item_combination is the aux
            // table's PRIMARY KEY, so ON DUPLICATE KEY UPDATE touches nothing
            // else (same shape as MultilingualHelper::upsertLangRow).
            $columns = [$db->qn('id_item_combination')];
            $values = [(string) $combinationId];
            $updates = [];

            foreach ($posted as $field => $value) {
                $columns[] = $db->qn($field);
                $values[] = $db->q($value);
                $updates[] = $db->qn($field) . ' = VALUES(' . $db->qn($field) . ')';
            }

            $db->setQuery(
                'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ')'
                . ' VALUES (' . implode(', ', $values) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            )->execute();

            // Clearing the last override leaves a blank row, which the grid would
            // still count as a text-override chip; drop it, keeping the "row
            // exists only when overridden" contract. Both empty markers are
            // matched: pre-existing rows hold NULL in the LONGTEXT columns.
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($table)
                    ->where($db->qn('id_item_combination') . ' = ' . $combinationId)
                    ->where(array_map(
                        static fn ($field) => '(' . $db->qn($field) . ' IS NULL OR ' . $db->qn($field) . " = '')",
                        $fields,
                    )),
            )->execute();
        }
    }

    /**
     * Idempotent aux-table creation for the combination text overrides:
     * combinations have no form XML for the schema scan to discover, so the
     * fields are declared here.
     *
     * Once per request: the probe costs an information_schema query per content
     * language, and the grid save reaches the writer once per variant row.
     *
     *
     * @since  2.0.0
     */
    private static function ensureCombinationLangSchema(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        MultilingualHelper::ensureLangSchema(
            tableName:         '#__alfa_item_combinations',
            primaryColumnName: 'id_item_combination',
            fields:            self::combinationTranslatableFields(),
        );

        $ensured = true;
    }

    /**
     * The per-language `name` overrides stored for an item's variants, keyed
     * [combinationId][langSuffix]; one query per content language.
     *
     * Read once per grid save so the row loop can skip untouched names: a
     * hundred-variant product must not pay an upsert per row per language for
     * names nobody edited.
     *
     * @param int $itemId The owning item.
     *
     * @return array<int, array<string, string>>
     *
     * @since  2.0.0
     */
    private static function loadCombinationNames(int $itemId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $names = [];

        foreach (self::contentLanguageKeys() as $langSuffix) {
            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->select([$db->qn('t.id_item_combination'), $db->qn('t.name')])
                        ->from($db->qn('#__alfa_item_combinations_' . $langSuffix, 't'))
                        ->join(
                            'INNER',
                            $db->qn('#__alfa_item_combinations', 'c') . ' ON c.id = t.id_item_combination',
                        )
                        ->where($db->qn('c.item_id') . ' = ' . $itemId),
                );

                foreach ($db->loadObjectList() ?: [] as $nameRow) {
                    $names[(int) $nameRow->id_item_combination][$langSuffix] = (string) $nameRow->name;
                }
            } catch (Throwable $e) {
                // Aux table pending creation: nothing is overridden yet.
            }
        }

        return $names;
    }

    /**
     * Persist the combination's price editor rows; ONE table for everything:
     * #__alfa_items_prices, where mode='price' rows are full independent
     * prices and mode='adjust' rows are +/-/% deltas on the computed item
     * price. Both kinds carry the complete dimension set (currency, country,
     * group, user, quantity tiers, schedule). Synced by id within the
     * combination scope.
     *
     * @param int $itemId The owning item id.
     * @param int $combinationId The combination id.
     * @param array $rows Editor rows ({_mode, ...}).
     *
     *
     * @since  2.0.0
     */
    protected static function syncCombinationPrices(int $itemId, int $combinationId, array $rows): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn('id'))
                ->from($db->qn('#__alfa_items_prices'))
                ->where($db->qn('combination_id') . ' = ' . $combinationId),
        );
        $existingIds = array_map('intval', $db->loadColumn() ?: []);

        $incomingIds = array_values(array_filter(array_map(
            static fn ($row) => (int) (((array) $row)['id'] ?? 0),
            $rows,
        )));

        $obsolete = array_diff($existingIds, $incomingIds);

        if ($obsolete) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->qn('#__alfa_items_prices'))
                    ->whereIn($db->qn('id'), $obsolete),
            );
            $db->execute();
        }

        $toSqlDate = static function ($value) {
            $value = trim((string) ($value ?? ''));

            if ($value === '') {
                return null;
            }

            try {
                return Factory::getDate($value)->toSql();
            } catch (Throwable $e) {
                return null;
            }
        };

        foreach ($rows as $row) {
            $row = (array) $row;

            if (trim((string) ($row['value'] ?? '')) === '') {
                continue;
            }

            $mode = ((string) ($row['_mode'] ?? 'price') === 'adjust') ? 'adjust' : 'price';
            $operation = (string) ($row['operation'] ?? '+');

            $price = (object) [
                'id' => (int) ($row['id'] ?? 0),
                'item_id' => $itemId,
                'combination_id' => $combinationId,
                'mode' => $mode,
                'operation' => ($mode === 'adjust' && in_array($operation, ['+', '-', '%', '-%'], true)) ? $operation : null,
                'value' => (float) $row['value'],
                'override_value' => ($mode === 'price' && trim((string) ($row['override_value'] ?? '')) !== '') ? (float) $row['override_value'] : null,
                // For an adjust, override_type carries the net/gross target (a
                // separate POST key); for a price it carries the override pin.
                'override_type' => $mode === 'adjust'
                    ? (in_array($row['adjust_target'] ?? '', ['start', 'final'], true) ? $row['adjust_target'] : 'start')
                    : (in_array($row['override_type'] ?? '', ['start', 'final'], true) ? $row['override_type'] : 'start'),
                'currency_id' => (int) ($row['currency_id'] ?? 0),
                'country_id' => (int) ($row['country_id'] ?? 0),
                'usergroup_id' => (int) ($row['usergroup_id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'quantity_start' => (trim((string) ($row['quantity_start'] ?? '')) === '') ? null : (float) $row['quantity_start'],
                'quantity_end' => (trim((string) ($row['quantity_end'] ?? '')) === '') ? null : (float) $row['quantity_end'],
                'publish_up' => $toSqlDate($row['publish_up'] ?? ''),
                'publish_down' => $toSqlDate($row['publish_down'] ?? ''),
                'state' => ((string) ($row['price_status'] ?? '1') === '1') ? 1 : 0,
            ];

            if ($price->id > 0 && in_array($price->id, $existingIds, true)) {
                $db->updateObject('#__alfa_items_prices', $price, 'id', true);
            } else {
                $price->id = 0;
                $db->insertObject('#__alfa_items_prices', $price);
            }
        }
    }

    /**
     * The item's current combination rows keyed by id. Archived rows (kept only
     * as the stock identity of order history) are not part of the catalogue and
     * never reach the grid, the Studio, the save or the generator.
     *
     * @param int $itemId The item id.
     *
     * @return array<int, object>
     *
     * @since  2.0.0
     */
    protected static function loadCombinationRows(int $itemId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->qn('#__alfa_item_combinations'))
                ->where($db->qn('item_id') . ' = ' . $itemId)
                ->where(CombinationState::currentSql(column: $db->qn('state')))
                ->order($db->qn('ordering') . ' ASC, ' . $db->qn('id') . ' ASC'),
        );

        return $db->loadObjectList('id') ?: [];
    }
}
