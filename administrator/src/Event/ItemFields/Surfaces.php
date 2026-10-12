<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Joomla\CMS\Log\Log;
use LogicException;

defined('_JEXEC') or die;

/**
 * Harvests a render event's surfaces into plain descriptors and AGGREGATES them
 * onto the page's slot map ($item->surfaces[slot] = [descriptor, ...]). A slot is a
 * container: it collects contributions from ANY source (item fields, payment
 * methods, shipment methods) and renders them in insertion order, source-agnostic.
 *
 * Two cross-cutting rules live here:
 *  - BACK-COMPAT: a legacy setLayout() (no explicit surface) becomes the event's
 *    DEFAULT slot, so existing plugins keep working.
 *  - OWNER DEFAULT: a surface's plugin group/element defaults to the contributor's
 *    own plugin (set explicitly only to render another plugin's layout).
 *
 * @since  1.3.0
 */
final class Surfaces
{
    /**
     * Dev-mode contract guard: a surface's selector kind is part of the STATE-GRAPH
     * contract, NOT a UI choice. A VARIATION axis must wire sel[]/item and an OPTION
     * opt[]/price; declaring the wrong one routes the field out of (or into) the
     * combination graph and silently breaks SKU resolution. The combination role is
     * authoritative (variantFieldIds); the selector MUST match it.
     *
     * Fails LOUD in development (JDEBUG -> throw) so the bug is caught at once, and
     * only logs in production (never blank a live storefront). Call it from the
     * item/list dispatch: the surfaces that actually carry a combination graph;
     * fully custom flows with no graph simply pass $isVariantField = false and
     * declare no variant selector, so they never trip it.
     *
     * @param array $surfaces One field's harvested surfaces (slot => descriptor).
     * @param bool $isVariantField Whether the field is a combination dimension (authoritative).
     * @param int $fieldId The field id (for the message).
     * @param string $pluginName The contributing plugin element (for the message).
     *
     *
     * @since  1.3.0
     */
    public static function assertSelectorRole(array $surfaces, bool $isVariantField, int $fieldId, string $pluginName): void
    {
        foreach ($surfaces as $descriptor) {
            $scope = $descriptor['layoutData']['reloadScope'] ?? null;

            // A passive surface (badge, note, 3d widget; no selector) is exempt.
            if ($scope === null) {
                continue;
            }

            $declaredVariant = $scope === SelectorDescriptor::RELOAD_ITEM;

            if ($declaredVariant === $isVariantField) {
                continue;
            }

            $message = sprintf(
                'alfa-item-fields: plugin "%s" declared a %s selector for field %d, but the field %s a combination dimension.'
                . ' The selector type is a state-graph contract (variant->sel[]/item, option->opt[]/price) and MUST match the'
                . ' combination role.',
                $pluginName,
                $declaredVariant ? 'variant' : 'option',
                $fieldId,
                $isVariantField ? 'IS' : 'is NOT',
            );

            if (\defined('JDEBUG') && JDEBUG) {
                throw new LogicException($message);
            }

            Log::add($message, Log::WARNING, 'alfa-item-fields');
        }
    }

    /**
     * Resolve one contributor's event into descriptors keyed by slot.
     *
     * @param object $event An event using HasSurfaces (+ legacy LayoutEvent getters).
     * @param string $ownerGroup The contributor's plugin group (alfa-item-fields|alfa-payments|alfa-shipments).
     * @param string $ownerName The contributor's plugin element (each surface's default owner).
     * @param string $defaultSlot The slot a legacy setLayout() maps to (a *Surface case value).
     *
     * @return array<string, array> slot => render descriptor.
     *
     * @since  1.3.0
     */
    public static function collect(object $event, string $ownerGroup, string $ownerName, string $defaultSlot): array
    {
        $surfaces = $event->getSurfaces();

        // Legacy setLayout() with no explicit surface -> the default slot.
        if (!isset($surfaces[$defaultSlot]) && method_exists($event, 'getLayout') && $event->getLayout() !== '') {
            $surfaces[$defaultSlot] = [
                'layout' => $event->getLayout(),
                'layoutData' => $event->getLayoutData(),
                'layoutPluginType' => $event->getLayoutPluginType(),
                'layoutPluginName' => $event->getLayoutPluginName(),
            ];
        }

        // Default each surface's owner to the contributor's own plugin.
        foreach ($surfaces as &$descriptor) {
            $descriptor['layoutPluginType'] = $descriptor['layoutPluginType'] ?: $ownerGroup;
            $descriptor['layoutPluginName'] = $descriptor['layoutPluginName'] ?: $ownerName;
        }

        return $surfaces;
    }

    /**
     * Append one contributor's surfaces onto the page slot map, in insertion
     * order, carrying an optional showon attribute string for the descriptor.
     * An item-field contributor passes its field, so each descriptor carries
     * fieldId + fieldName and a template can pick or skip it
     * ({@see \Alfa\Component\Alfa\Site\Helper\Surface::render()}); any other
     * contributor (payment, shipment, feature plugin) leaves both null.
     *
     * @param array $target The page slot map, by reference (slot => list of descriptors).
     * @param object $event The contributor's event.
     * @param string $ownerGroup The contributor's plugin group.
     * @param string $ownerName The contributor's plugin element.
     * @param string $defaultSlot The legacy-setLayout default slot.
     * @param string $showon The descriptor's showon attribute string ('' = none).
     * @param object|null $field The item field that contributed (null = not an item field).
     *
     *
     * @since  1.3.0
     */
    public static function appendTo(array &$target, object $event, string $ownerGroup, string $ownerName, string $defaultSlot, string $showon = '', ?object $field = null): void
    {
        foreach (self::collect($event, $ownerGroup, $ownerName, $defaultSlot) as $slot => $descriptor) {
            $descriptor['showon'] = $showon;
            $descriptor['fieldId'] = $field !== null ? (int) $field->id : null;
            $descriptor['fieldName'] = $field !== null ? (string) ($field->field_name ?? '') : null;
            $target[$slot][] = $descriptor;
        }
    }

    /**
     * Whether a descriptor renders a buyer input: a surface that declared its
     * selector kind (variantSelector / optionSelector), whose wiring
     * {@see SurfaceBuilder::toDescriptor()} flattens into the layout data.
     *
     * @param array $descriptor One harvested surface descriptor.
     *
     *
     * @since  1.10.0
     */
    public static function declaresSelector(array $descriptor): bool
    {
        $data = $descriptor['layoutData'] ?? null;

        return \is_array($data) && isset($data['reloadScope']);
    }

    /**
     * Build the showon DOM-attribute string for a field: conditional visibility,
     * the same contract as the checkout form. Carried in the descriptor so the
     * surface keeps its field's show/hide behaviour wherever it renders.
     *
     * @param object $field The field definition (field_name, type, params_decoded).
     *
     * @return string The data-showon-* attribute string (always at least name/type).
     *
     * @since  1.3.0
     */
    public static function showonAttr(object $field): string
    {
        $params = $field->params_decoded ?? [];
        $rule = $params['showon'] ?? '';
        $rule = is_array($rule) ? json_encode($rule) : (string) $rule;
        $effect = (string) ($params['showon_effect'] ?? 'hide');

        $attr = ' data-showon-name="' . htmlspecialchars((string) ($field->field_name ?? ''), ENT_QUOTES, 'UTF-8') . '"';
        $attr .= ' data-showon-type="' . htmlspecialchars((string) ($field->type ?? ''), ENT_QUOTES, 'UTF-8') . '"';

        if ($rule !== '' && $rule !== '[]' && $rule !== '{}' && $rule !== 'null') {
            $attr .= " data-showon-rule='" . htmlspecialchars($rule, ENT_QUOTES, 'UTF-8') . "'";

            if ($effect === 'disable') {
                $attr .= ' data-showon-effect="disable"';
            }
        }

        return $attr;
    }
}
