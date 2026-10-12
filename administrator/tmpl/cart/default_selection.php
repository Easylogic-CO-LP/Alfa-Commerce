<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * One cart line's chosen configuration, in words.
 *
 * THE ONE SEMANTIC RENDERER. The Summary tab and the Items tab both print
 * through this, from the same read model, so a line's variant and options read
 * identically wherever they appear. The field and value names arrive already
 * resolved by {@see \Alfa\Component\Alfa\Administrator\Service\ItemFields\ItemFieldDisplayResolver}
 * (R.1H); nothing here rebuilds a name from an id, queries a field definition,
 * or knows what a Choice or a Subscription is: a field type that wants its own
 * presentation owns it in its own plugin.
 *
 * The caller sets $this->lineSelections and loads this template.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$selections = $this->lineSelections ?? [];

if (!$selections) {
    return;
}
?>
<ul class="list-unstyled small text-muted mb-0 mt-1 alfa-line-selection">
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
