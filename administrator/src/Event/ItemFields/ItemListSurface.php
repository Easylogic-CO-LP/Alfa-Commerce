<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

defined('_JEXEC') or die;

/**
 * The render slots a field plugin may target on a LISTING CARD
 * (onItemListPrepare): a compact context with fewer slots than the product
 * page. Each case is a REAL card template position.
 *
 * @since  2.0.0
 */
enum ItemListSurface: string
{
    /** The selector slot inside the card's buy form (item-fields-surfaces). The default. */
    case BuyForm = 'buy-form';

    /** Beside the card title (item-title), e.g. a badge. */
    case Title = 'title';

    /** The card image area (item-image): overlay/badge. */
    case Image = 'image';

    /** Read-only specifications block: informational fields render here, inside
        the card's buy form after the selectors (no input), as on the product page. */
    case Specifications = 'specifications';
}
