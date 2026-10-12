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
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Country field: a list of the shop's published places (countries and
 * their regions), stored as the #__alfa_places id. In the delivery section
 * it is the delivery place that shipping zones and method scopes are
 * evaluated against.
 *
 * @since  1.0.0
 */
final class Country extends FormFieldsPlugin
{
    /**
     * Build the field as a native list of the published places in tree order.
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

        $node->setAttribute('type', 'list');
        $node->setAttribute('filter', 'integer');
        $node->setAttribute('validate', 'options');
        $node->setAttribute('data-alfa-delivery-place', '1');

        if ($default > 0 && $node->getElementsByTagName('default')->length === 0) {
            $node->appendChild($node->ownerDocument->createElement('default', (string) $default));
        }

        $placeholder = $node->ownerDocument->createElement('option');
        $placeholder->setAttribute('value', '');
        $placeholder->appendChild($node->ownerDocument->createTextNode(Text::_('PLG_ALFA_FORM_FIELDS_COUNTRY_SELECT')));
        $node->appendChild($placeholder);

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
        }

        return $node;
    }

    /**
     * The published places in tree order (each country followed by its
     * regions, siblings by name), read once per request.
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
