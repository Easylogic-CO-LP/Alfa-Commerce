<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Language\Text;

$item = $displayData;

// HTML structure of Quantity Controls
// Ensure that the data-action,data-step,min,max attributes is always included
?>

<div class="item-quantity-controls-wrapper">

    <button
            class="item-quantity-controls decrement"
            data-action="decrement"
            aria-label="<?php echo Text::_('COM_ALFA_QUANTITY_DECREMENT'); ?>"
    >
        -
    </button>

    <input
            class="item-quantity"
            type="number"
            name="quantity"
            aria-label="<?php echo Text::_('COM_ALFA_QUANTITY_LABEL'); ?>"
            value="<?php echo $item->quantity_min ?>"
            min="<?php echo $item->quantity_min ?>"
            max="<?php echo $item->quantity_max; ?>"
            data-step="<?php echo $item->quantity_step ?>"
    />

    <button
            class="item-quantity-controls increment"
            data-action="increment"
            aria-label="<?php echo Text::_('COM_ALFA_QUANTITY_INCREMENT'); ?>"
    >
        +
    </button>

</div>