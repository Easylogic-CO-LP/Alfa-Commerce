<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * One cart line's chosen configuration, in words: a list of "field: value" under the line's name.
 *
 * The field and value names arrive already resolved by
 * {@see \Alfa\Component\Alfa\Administrator\Service\ItemFields\ItemFieldDisplayResolver}; nothing here rebuilds a name
 * from an id, queries a field definition, or knows what a Choice or a Subscription is: a field type that wants its own
 * presentation owns it in its own plugin. A value with no name of its own (a customer's typed text) reads as custom.
 *
 * Override html/layouts/com_alfa/cart/line_selection.php in the administrator template to change how a line's
 * configuration reads in the order builder.
 *
 * Display data:
 *   selections  object[]  the line's resolved selections, each with field_name and value_name
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var array{selections?: object[]} $displayData */
$selections = $displayData['selections'] ?? [];

if (!$selections) {
    return;
}
?>
<ul class="list-unstyled small text-muted mb-0 mt-1 line-selection">
    <?php foreach ($selections as $selection) : ?>
        <li>
            <?php echo Text::sprintf(
                'COM_ALFA_CART_DETAIL_SELECTION',
                $this->escape($selection->field_name ?? ''),
                ((string) ($selection->value_name ?? '')) !== ''
                    ? $this->escape($selection->value_name)
                    : Text::_('COM_ALFA_CART_FIELD_CUSTOM'),
            ); ?>
        </li>
    <?php endforeach; ?>
</ul>
