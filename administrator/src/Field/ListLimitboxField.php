<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\LimitboxField;

/**
 * The STOREFRONT items-per-page dropdown (filter_items.xml, list.limit).
 *
 * Core's limitbox with two repairs that only bite once the sizes become
 * merchant-configurable (ItemsModel stamps the resolved `items_limits` chain
 * value onto this field's `limits` attribute):
 *
 *  - Core labels options via Text::_('J' . $value) and only J5..J500 exist,
 *    so a custom size like 12 would render as the literal string "J12".
 *    Missing keys fall back to the number itself; existing translations
 *    still win.
 *  - Core appends its showall 0 AFTER the limits list, so a merchant putting
 *    0 inside the sizes would yield two "All" options. Duplicates collapse.
 *
 * With no sizes configured anywhere, the field behaves byte-identically to
 * core's limitbox (the J5..J500 defaults all resolve normally).
 *
 * @since  2.0.0
 */
class ListLimitboxField extends LimitboxField
{
    // public, not protected: LimitboxField declares $type public and PHP
    // forbids narrowing visibility in a subclass (fatal at compile time).
    public $type = 'ListLimitbox';

    /**
     * Core's options with unknown J-keys degraded to plain numbers and
     * duplicate values collapsed.
     *
     * @return object[] The field option objects
     * @since  2.0.0
     */
    protected function getOptions()
    {
        $options = [];

        foreach (parent::getOptions() as $option) {
            if (isset($options[(string) $option->value])) {
                continue;
            }

            if ($option->text === 'J' . $option->value) {
                $option->text = (string) $option->value;
            }

            $options[(string) $option->value] = $option;
        }

        return array_values($options);
    }
}
