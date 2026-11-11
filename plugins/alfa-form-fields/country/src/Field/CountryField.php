<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaFormFields\Country\Field;

use Alfa\Plugin\AlfaFormFields\Country\Extension\Country;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;

defined('_JEXEC') or die;

/**
 * The country select: the places the shop offers (Country::prepareDom()),
 * rendered through the plugin's own layout. A saved address may hold a place
 * the shop no longer offers (unpublished since, or left out of the field's
 * places): the select then chooses nothing, and the layout names the saved
 * place and asks the customer to choose again. The place is not offered, so
 * validate="options" refuses it when the form is sent.
 *
 * @since  2.2.0
 */
class CountryField extends ListField
{
    /**
     * The form field type.
     *
     * @var string
     *
     * @since  2.2.0
     */
    protected $type = 'Country';

    /**
     * The layout that renders the select and the message.
     *
     * @var string
     *
     * @since  2.2.0
     */
    protected $layout = 'layouts.country';

    /**
     * The select, with heldPlace (the saved place's label, '' when the place
     * no longer exists) and unavailable (whether the saved place is not
     * offered) for the layout.
     *
     * @return string The field markup.
     *
     * @since  2.2.0
     */
    protected function getInput()
    {
        $data = $this->collectLayoutData();
        $data['options'] = (array) $this->getOptions();
        $data['heldPlace'] = '';
        $data['unavailable'] = false;

        $value = \is_scalar($data['value'] ?? null) ? trim((string) $data['value']) : '';
        $offered = array_map(static fn (object $option): string => (string) $option->value, $data['options']);

        if ((int) $value > 0 && !\in_array($value, $offered, true)) {
            $data['heldPlace'] = Country::placeLabel(placeId: (int) $value);
            $data['unavailable'] = true;
            $data['value'] = '';
        }

        return $this->getRenderer($this->layout)->render($data);
    }

    /**
     * The template's override first, then the plugin's layouts, then Joomla's
     * (template html/layouts, JPATH_ROOT/layouts).
     *
     * @return array The layout paths.
     *
     * @since  2.2.0
     */
    protected function getLayoutPaths()
    {
        $template = Factory::getApplication()->getTemplate();

        return array_merge(
            [
                JPATH_THEMES . '/' . $template . '/html/plg_alfa-form-fields_country',
                JPATH_PLUGINS . '/alfa-form-fields/country/tmpl',
            ],
            parent::getLayoutPaths(),
        );
    }
}
