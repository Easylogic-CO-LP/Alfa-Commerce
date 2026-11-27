<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemSections;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * The general product page sections of Options, by key, for a product's section to say which one it replaces:
 * the XML's own options first (the choice of none), then each general section as its title and key. A stored key
 * that is no longer among the general sections stays selectable, named as such, so a save keeps it.
 *
 * @since  2.3.0
 */
class SectionkeysField extends ListField
{
    protected $type = 'Sectionkeys';

    /**
     * The options: the XML's, then the general sections' keys.
     *
     * @return object[]
     *
     * @since  2.3.0
     */
    protected function getOptions()
    {
        $options = parent::getOptions();
        $known = [];

        foreach (ItemSections::general(ComponentHelper::getParams('com_alfa')) as $section) {
            $known[$section['key']] = true;
            $options[] = HTMLHelper::_(
                'select.option',
                $section['key'],
                $section['title'] !== '' ? $section['title'] . ' (' . $section['key'] . ')' : $section['key'],
            );
        }

        $stored = ItemSections::key((string) $this->value);

        if ($stored !== '' && !isset($known[$stored])) {
            $options[] = HTMLHelper::_('select.option', $stored, Text::sprintf('COM_ALFA_ITEM_SECTION_KEY_GONE', $stored));
        }

        return $options;
    }
}
