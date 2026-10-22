<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Picker of the shop's own checkout fields, for a plugin setting that has to
 * name one: which box holds the postcode a carrier prices by, the phone a
 * courier texts, the country a locker network delivers to.
 *
 * A plugin cannot know what a shop called its fields, and the core does not
 * decide for it: a postcode is a carrier's concern, so the cart holds no
 * postcode role and hardcodes no column. The plugin names the field here and
 * reads it off the cart. It lives in the component because the need is not one
 * plugin's, and none of them should ship their own copy of this dropdown.
 *
 * IT SAVES THE FIELD'S ID, NOT ITS COLUMN NAME. Renaming a field rewrites the
 * name and ALTERs the column; an id does not move. Read the value back with
 * `$cart->delivery()->field($savedId)`, which resolves the id to a live column
 * every time, so a field the merchant has since deleted or unpublished answers
 * as missing instead of handing back a stale value.
 *
 * The option LABEL is the translated checkout label, so a merchant recognises
 * the field by the words their own customers read.
 *
 * GIVE EVERY OPTIONAL PICKER AN EMPTY OPTION. Without one the first matching
 * field is preselected and silently saved, which is how a carrier plugin's postcode
 * setting came to hold the id of the name field. Declare
 * `<option value="">- Not selected -</option>`; XML options come first and
 * survive. With `required="true"`, leaving it there is refused by Joomla's own
 * validation.
 *
 * NAMING TRAP: the type token must stay one lowercase word.
 * `FormHelper::loadClass` turns `-` and `_` into namespace separators, so a
 * type of `checkout_field` would be hunted for at `...\Checkout\FieldField` and
 * silently fall back to a plain text box, which is exactly how this started:
 * A carrier plugin declared `type="AlfaField"`, no such class existed, and the merchant
 * was handed four free-text boxes to type raw column names into from memory.
 *
 * @since  1.0.0
 */
class CheckoutfieldField extends ListField
{
    use FailClosedOptionsTrait;

    /**
     * Field type id: must match the XML `type` and this file's name.
     *
     * @var string
     * @since  1.0.0
     */
    protected $type = 'checkoutfield';

    /**
     * The checkout sections a field may be narrowed to.
     *
     * Whitelisted because these tokens become COLUMN names in the WHERE clause
     * and a column name cannot be bound as a parameter.
     *
     * @var string[]
     * @since  1.0.0
     */
    private const SECTIONS = ['shipping', 'billing'];

    /**
     * The shop's published checkout fields, narrowed by the XML attributes.
     *
     * When the catalogue cannot be read, the form still renders, with the
     * XML-declared options alone and an error that says so, and the field
     * refuses the save (FailClosedOptionsTrait): saving would clear the stored
     * choice. A picker that fails costs the merchant this one dropdown, not the
     * whole shipment-method edit form, and never the setting.
     *
     *
     * @since  1.0.0
     */
    protected function getOptions(): array
    {
        $options = $this->loadModelRows();

        if ($options === null) {
            $this->reportOptionsNotLoaded();

            return parent::getOptions();
        }

        // XML <option> children (an explicit "- none -" entry, say) come first
        // and survive, exactly as every other house ListField behaves.
        return array_merge(parent::getOptions(), $options);
    }

    /**
     * The checkout fields as options, or null when they could not be read.
     *
     * @return array|null The options, empty when no field matches.
     *
     * @since  2.1.0
     */
    protected function loadModelRows(): ?array
    {
        try {
            return $this->checkoutFields();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Query the published checkout fields and turn them into select options.
     *
     * Mirrors FieldsHelper::getFields() (same table, same group-state rule,
     * same ordering), minus the per-user visibility dimensions, which answer
     * "may THIS customer see the field" and have no meaning when a merchant is
     * configuring a carrier.
     *
     * @return object[]
     *
     * @since  1.0.0
     */
    private function checkoutFields(): array
    {
        // The rows
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.field_name', 'a.type', 'a.role', 'a.params']))
            ->from($db->quoteName('#__alfa_form_fields', 'a'))
            ->leftJoin($db->quoteName('#__alfa_form_field_groups', 'g') . ' ON g.id = a.group_id')
            ->where('a.state = 1')
            // A disabled group hides its fields, except a system field, which
            // is always asked and so is always mappable.
            ->where('(a.group_id = 0 OR g.state IS NULL OR g.state = 1 OR a.is_system = 1)');

        // Labels
        // `name` and `field_label` do NOT exist on #__alfa_form_fields: they
        // live only in #__alfa_form_fields_<lang_tag>, a table created lazily
        // the first time a content language is written. Selecting them from the
        // main table is a fatal "Unknown column"; going through the helper is
        // both table- and column-existence safe and resolves the ACTIVE
        // language with a fallback to the site default.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_form_fields',
            langPrimaryColumn: 'id_formfield',
            fields:            ['name', 'field_label'],
        );

        $this->narrowToSections($query);
        $this->narrowToAttribute($query, 'types', 'a.type');

        $query->order('COALESCE(g.ordering, 0) ASC, a.ordering ASC');

        $db->setQuery($query);
        $rows = $db->loadObjectList() ?: [];

        // The options
        $options = [];

        foreach ($rows as $row) {
            $column = trim((string) $row->field_name);

            if ($column === '') {
                continue;
            }

            $options[] = HTMLHelper::_('select.option', (string) (int) $row->id, $this->label($row));
        }

        return $options;
    }

    /**
     * The words the merchant will recognise this field by.
     *
     * The checkout label is what their customers read, so it is preferred; the
     * administrative name is the next best thing on a shop whose content
     * language was never given the translation rows; the column name is the
     * last resort and is never empty, so the option is always identifiable.
     *
     * @param object $row A #__alfa_form_fields row with the joined translations.
     *
     *
     * @since  1.0.0
     */
    private function label(object $row): string
    {
        foreach ([$row->field_label ?? '', $row->name ?? ''] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return (string) $row->field_name;
    }

    /**
     * Keep only the fields visible in at least one of the wanted sections.
     *
     * A carrier reads the DELIVERY address, so `shipping` and `billing` are
     * both offered by default and a param that only ever means one of them
     * (an invoice-side contact) can say so in the XML.
     *
     * @param \Joomla\Database\QueryInterface $query Modified in place.
     *
     *
     * @since  1.0.0
     */
    private function narrowToSections($query): void
    {
        $wanted = array_values(array_intersect($this->attributeList('sections'), self::SECTIONS));

        if ($wanted === []) {
            $wanted = self::SECTIONS;
        }

        $db = $this->getDatabase();
        $clauses = [];

        foreach ($wanted as $section) {
            $clauses[] = $db->quoteName('a.' . $section) . ' >= ' . FieldsHelper::LEVEL_OPTIONAL;
        }

        $query->where('(' . implode(' OR ', $clauses) . ')');
    }

    private function narrowToAttribute($query, string $attribute, string $column): void
    {
        $values = $this->attributeList($attribute);

        if ($values === []) {
            return;
        }

        $query->whereIn($this->getDatabase()->quoteName($column), $values, ParameterType::STRING);
    }

    /**
     * Read a comma-separated XML attribute as a list of trimmed tokens.
     *
     * @param string $attribute The attribute name.
     *
     * @return string[] Empty when the attribute is absent or blank.
     *
     * @since  1.0.0
     */
    private function attributeList(string $attribute): array
    {
        $raw = trim((string) ($this->element[$attribute] ?? ''));

        if ($raw === '') {
            return [];
        }

        $tokens = array_map('trim', explode(',', $raw));

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }
}
