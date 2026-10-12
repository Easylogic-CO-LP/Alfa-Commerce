<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Value Facet Groups Event (onItemFieldValueFacetGroups)
 *
 * ASKS A FIELD TYPE WHICH FACET GROUPS EACH OF ITS VALUES BELONGS TO. Dispatched by the
 * SITE ItemsModel ONCE PER FIELD, never per value, to that field's own plugin
 * (bootPlugin($field->type)), the same way onItemView and onItemListPrepare are.
 *
 * WHY GROUPS AND NOT RAW METADATA. A filter listing every textual value is unusable once
 * a catalogue has Blue, Blue Metallic, Navy Blue and Blue/Red. Collapsing those into one
 * "Blue" requires knowing that two differently-spelled codes mean the same colour, and
 * that knowledge belongs to whoever stores them. If a type returned raw metadata instead,
 * the component would have to decide that `#0000FF` and `#0000ff` are one thing, which is
 * colour semantics living in the wrong layer. So the type does its own normalisation and
 * reports finished memberships:
 *
 *   public function onItemFieldValueFacetGroups($event): void
 *   {
 *       if (!$event instanceof ValueFacetGroupsEvent) { return; }
 *
 *       foreach ($event->getValues() as $valueId => $value) {
 *           $event->putValueGroups($valueId, [[
 *               'type'       => 'color',
 *               'id'         => '#0000ff',
 *               'label'      => 'Blue',
 *               'layout'     => 'facet-chip',
 *               'layoutData' => ['codes' => ['#0000ff']],
 *           ]]);
 *       }
 *   }
 *
 * A value may belong to ANY number of groups (a Blue/Red value reports two), which is how
 * one product answers both the Blue and the Red filter without being duplicated.
 *
 * THE COMPONENT IS OPAQUE TO `type` AND `id`. It buckets by them, hashes them into a URL
 * token and looks them back up; it never parses, normalises or compares them semantically.
 * A future Brand plugin reporting `type: brand, id: nike` flows through the identical
 * machinery with no change to the component or the filter module.
 *
 * IDENTITY IS DATA, NEVER SQL. A type reports membership; the component builds the WHERE
 * clause, the counts and the pagination from it. That boundary keeps the catalogue query
 * and #__alfa_item_field_value_index owned by core, keeps answers cacheable, and stops one
 * type's fragment from silently breaking another facet's counts: the same split the
 * search engines already use, where a plugin returns a SearchResult and never SQL.
 *
 * RENDERING IS A LAYOUT NAME, NEVER HTML. `layout` + `layoutData` are handed to the
 * existing trusted plugin-layout renderer ({@see \Alfa\Component\Alfa\Site\Helper\Layout}),
 * exactly as the item page and the catalogue card already do it. The filter module keeps
 * ownership of the input, its name, the token and the field id; a type only ever draws the
 * INSIDE of the chip, so no layoutData can reach a state-control attribute.
 *
 * Results live in a plain property rather than event arguments, like the cart layouts on
 * the sibling events, to avoid Joomla's onSet/set magic-argument trap.
 *
 * @since  1.10.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class ValueFacetGroupsEvent extends AbstractImmutableEvent
{
    /**
     * Group memberships per value id, as the type reported them.
     *
     * @var array<int, array<int, array>>
     * @since  1.10.0
     */
    private array $groups = [];

    /**
     * Per-value chip layouts, as the type reported them.
     *
     * @var array<int, array{layout: string, layoutData: array}>
     * @since  1.10.0
     */
    private array $valueLayouts = [];

    /**
     * Whether this FIELD wants its values grouped by default, as the type reported it.
     *
     * @since  1.10.0
     */
    private bool $fieldGrouping = false;

    /**
     * The field definition being asked about (id, type, params, ...).
     *
     *
     * @since  1.10.0
     */
    public function getField(): object
    {
        return $this->getArgument('subject');
    }

    /**
     * The value rows to report on, keyed by value id. Each carries at least `id`, `name`
     * and the raw `params` the type itself wrote.
     *
     * @return array<int, array>
     *
     * @since  1.10.0
     */
    public function getValues(): array
    {
        // 'valueRows', NOT 'values'. Joomla's AbstractEvent::getArgument() treats a
        // get{Ucfirst} method as a value pre-processor and CALLS it, so an argument named
        // `values` would make this accessor invoke itself, recursing until PHP dies with no
        // error output at all. Same trap as the set{Ucfirst} one, on the read side.
        return (array) $this->getArgument('valueRows', []);
    }

    /**
     * Declare which facet groups one value belongs to.
     *
     * Each entry:
     *   type        string  Group namespace, e.g. 'color'. Opaque to the component.
     *   id          string  Stable identity within (field, type). Opaque; ALREADY
     *                       normalised by the type; the component never canonicalises it.
     *   label       string  Display name. Falls back to the id when empty.
     *   layout      string  Optional layout in the plugin's tmpl/ that draws the chip
     *                       interior. Omitted -> the module renders the label as text.
     *   layoutData  array   Optional data for that layout. Reaches the chip interior ONLY;
     *                       it can never influence the input, its name, the token or the
     *                       field id, which the module owns.
     *   ordering    int     Optional sort hint within the field.
     *
     * Called with an empty array, or never called for a value, means "this value belongs to
     * no group", an ordinary state that leaves the value filterable on its own.
     *
     * @param int $valueId The value id.
     * @param array $groups Ordered list of membership descriptors.
     *
     *
     * @since  1.10.0
     */
    public function putValueGroups(int $valueId, array $groups): void
    {
        if (empty($groups)) {
            unset($this->groups[$valueId]);

            return;
        }

        $this->groups[$valueId] = array_values($groups);
    }

    /**
     * Declare how ONE VALUE draws itself when the panel lists it on its own.
     *
     * The group chips are not the only place a value appears: a value with no group, or a
     * panel with grouping switched off, still renders a plain chip. Rendering that is the
     * type's job too; otherwise the component would have to reach into the type's params
     * for an image, which is exactly the coupling this contract exists to remove.
     *
     * Same rules as the group layouts: a layout NAME in the plugin's own tmpl/, never HTML,
     * and its data reaches the chip interior only.
     *
     * @param int $valueId The value id.
     * @param string $layout Layout name, or '' to draw nothing but the label.
     * @param array $data Data for that layout.
     *
     *
     * @since  1.10.0
     */
    public function putValueLayout(int $valueId, string $layout, array $data = []): void
    {
        $layout = trim($layout);

        if ($layout === '') {
            unset($this->valueLayouts[$valueId]);

            return;
        }

        $this->valueLayouts[$valueId] = ['layout' => $layout, 'layoutData' => $data];
    }

    /**
     * Per-value chip layouts the type reported, keyed by value id.
     *
     * @return array<int, array{layout: string, layoutData: array}>
     *
     * @since  1.10.0
     */
    public function getValueLayouts(): array
    {
        return $this->valueLayouts;
    }

    /**
     * Declare whether THIS FIELD should be grouped by default.
     *
     * Whether grouping makes sense is a property of the field, not of a filter module: a
     * colour field with two hundred values almost always wants it, and repeating that
     * decision on every module instance would be a setting waiting to drift. The type
     * reports its own configuration here; nothing downstream may override it.
     *
     * @param bool $grouping True when this field's values should be grouped by default.
     *
     *
     * @since  1.10.0
     */
    public function putFieldGrouping(bool $grouping): void
    {
        $this->fieldGrouping = $grouping;
    }

    /**
     * The field's own grouping default.
     *
     *
     * @since  1.10.0
     */
    public function getFieldGrouping(): bool
    {
        return $this->fieldGrouping;
    }

    /**
     * Everything the type reported, keyed by value id.
     *
     * @return array<int, array<int, array>>
     *
     * @since  1.10.0
     */
    public function getGroups(): array
    {
        return $this->groups;
    }
}
