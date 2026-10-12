<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SimpleXMLElement;

/**
 * Default sort-order picker for one tier of the listing chain
 * (category -> menu item -> global config).
 *
 * The options are READ FROM THE STOREFRONT'S OWN FILTER FORM
 * (components/com_alfa/forms/filter_<view>.xml, field `fullordering`) rather
 * than restated here: the same DRY mount {@see PresetFiltersField} uses. A
 * sort option added for shoppers becomes settable as a default at every tier
 * with no admin-side edit, and a merchant can never store an ordering the
 * visitor's own dropdown cannot express (UrlListModel::parseFullordering()
 * would silently discard it).
 *
 * Attributes:
 *   - view          (required) site view whose filter form supplies the options
 *   - globalparam   see {@see InheritsGlobalParam}
 *   - inheritlabel  see {@see InheritsGlobalParam}
 *
 * @since  1.9.0
 */
class ListOrderingField extends ListField
{
    use InheritsGlobalParam;

    protected $type = 'ListOrdering';

    /**
     * The storefront's sort options, preceded by the inherit option on the
     * tiers that have one.
     *
     * @return object[] The field option objects
     * @since  1.9.0
     */
    protected function getOptions()
    {
        // The scanned options carry SITE language keys; load that catalogue.
        Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_SITE);

        $options = [];

        foreach ($this->sourceOptions() as $option) {
            $options[] = HTMLHelper::_('select.option', (string) $option['value'], Text::_((string) $option));
        }

        if ($this->inherits()) {
            array_unshift($options, $this->inheritOption($options));
        }

        return $options;
    }

    /**
     * The `fullordering` options declared by the view's storefront filter form.
     *
     * @return SimpleXMLElement[] Option nodes, empty when the form has none
     * @since  1.9.0
     */
    private function sourceOptions(): array
    {
        $view = preg_replace('#\W#', '', (string) $this->element['view']);

        if ($view === '') {
            return [];
        }

        $path = JPATH_SITE . '/components/com_alfa/forms/filter_' . $view . '.xml';

        if (!is_file($path)) {
            return [];
        }

        $xml = simplexml_load_file($path);

        if (!$xml) {
            return [];
        }

        return $xml->xpath('//fields[@name="list"]/field[@name="fullordering"]/option') ?: [];
    }
}
