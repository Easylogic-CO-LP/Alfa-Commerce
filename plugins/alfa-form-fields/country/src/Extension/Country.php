<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaFormFields\Country\Extension;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Plugin\FormFieldsPlugin;
use DOMElement;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Country field: a list of the shop's published places (countries and
 * their regions), stored as the #__alfa_places id. In the delivery section
 * it is the delivery place that shipping zones and method scopes are
 * evaluated against.
 *
 * A place is offered when it and every parent place are published (the
 * market rule of CategoryPickerPolicy). A saved address that holds a place
 * no longer offered keeps its label, chooses nothing and asks the customer
 * to choose again (CountryField and its layout); validate="options" refuses
 * the old place when the form is sent.
 *
 * @since  1.0.0
 */
final class Country extends FormFieldsPlugin
{
    /**
     * Build the field as a list of the offered places in tree order, rendered
     * by the plugin's own field class (CountryField).
     *
     * @param PrepareDomEvent $event The field and the fieldset to append to.
     *
     * @return DOMElement|null The field node, or null when the field is not shown.
     *
     * @since  1.0.0
     */
    public function prepareDom(PrepareDomEvent $event): ?DOMElement
    {
        $node = parent::prepareDom($event);

        if ($node === null) {
            return null;
        }

        $params = new Registry($event->getField()->params);
        $allowed = array_values(array_filter(array_map('intval', (array) $params->get('allowed_places', []))));
        $default = (int) $params->get('default_place', 0);

        // The base class forwarded every param as an attribute; these two
        // describe the option set, not the input.
        foreach (['allowed_places', 'default_place'] as $own) {
            if ($node->hasAttribute($own)) {
                $node->removeAttribute($own);
            }
        }

        // Our own field class: Joomla's list, plus the message for a saved place no longer offered.
        $node->setAttribute('type', 'country');
        FormHelper::addFieldPrefix('Alfa\\Plugin\\AlfaFormFields\\Country\\Field');
        $node->setAttribute('filter', 'integer');
        $node->setAttribute('validate', 'options');
        $node->setAttribute('data-alfa-delivery-place', '1');

        $placeholder = $node->ownerDocument->createElement('option');
        $placeholder->setAttribute('value', '');
        $placeholder->appendChild($node->ownerDocument->createTextNode(Text::_('PLG_ALFA_FORM_FIELDS_COUNTRY_SELECT')));
        $node->appendChild($placeholder);

        $offered = [];

        foreach (self::publishedPlaces() as $place) {
            if ($allowed !== [] && !in_array((int) $place->id, $allowed, true)) {
                continue;
            }

            $option = $node->ownerDocument->createElement('option');
            $option->setAttribute('value', (string) $place->id);
            $option->appendChild(
                $node->ownerDocument->createTextNode(str_repeat('- ', (int) $place->depth) . $place->name),
            );
            $node->appendChild($option);
            $offered[(int) $place->id] = true;
        }

        // A preselection is a place the field offers: one that is not offered (unpublished since, or left out of the
        // offered places) preselects nothing, or a new customer would be told the shop no longer delivers there.
        $defaults = $node->getElementsByTagName('default');

        if ($defaults->length > 0) {
            if (!isset($offered[(int) trim($defaults->item(0)->textContent)])) {
                $node->removeChild($defaults->item(0));
            }
        } elseif ($default > 0 && isset($offered[$default])) {
            $node->appendChild($node->ownerDocument->createElement('default', (string) $default));
        }

        return $node;
    }

    /**
     * The published places in tree order (each country followed by its
     * regions, siblings by name), read once per request. A place under a
     * parent place that is not published is not reached (the tree is walked
     * from the countries down), the market rule of CategoryPickerPolicy.
     *
     * @return object[] Rows with id, name, parent_id and depth.
     *
     * @since  1.0.0
     */
    public static function publishedPlaces(): array
    {
        static $places = null;

        if ($places === null) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'name', 'parent_id']))
                    ->from($db->quoteName('#__alfa_places'))
                    ->where($db->quoteName('state') . ' = 1')
                    ->order($db->quoteName('name') . ' ASC'),
            )->loadObjectList() ?: [];

            $places = AlfaHelper::addHierarchyData($rows, 'name', ', ');
        }

        return $places;
    }

    /**
     * The display label of a place: its name, followed by its country for a
     * region ("Larisa, Greece"). An unpublished place still resolves, so a
     * stored address keeps its label.
     *
     * @param int $placeId The #__alfa_places id.
     *
     * @return string The label, or '' when the place does not exist.
     *
     * @since  1.0.0
     */
    public static function placeLabel(int $placeId): string
    {
        static $places = null;

        if ($placeId <= 0) {
            return '';
        }

        if ($places === null) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $places = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'name', 'parent_id']))
                    ->from($db->quoteName('#__alfa_places')),
            )->loadObjectList('id') ?: [];
        }

        $label = [];
        $current = $placeId;

        while ($current > 0 && isset($places[$current]) && count($label) < 5) {
            $label[] = (string) $places[$current]->name;
            $current = (int) $places[$current]->parent_id;
        }

        return implode(', ', $label);
    }
}
