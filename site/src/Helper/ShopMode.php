<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Joomla\CMS\Component\ComponentHelper;

defined('_JEXEC') or die;

/**
 * Whether the shop sells online, or shows its catalogue only (2.2.2).
 *
 * One switch in the component Options (Cart, Online sales, `catalogue_only`). It is read from the component's own
 * Options, never from parameters merged with a menu item's, so no menu item switches selling back on. In catalogue-only
 * mode the storefront shows the products with their details, images and stock, and nothing can be bought online: the
 * models assign no price and draw no buy form (each item carries the answer as `$item->sellsOnline`), the price index
 * is not read, the cart and checkout show a sentence, and every storefront cart task and placement is refused. Orders
 * staff take in the administrator or over the REST API, and orders already placed, are not affected. While the shop
 * sells online, a product with no price for the visitor is not in the catalogue at all
 * (ItemsModel::applyPriceIndexJoin()); in catalogue-only mode every product is shown.
 *
 * @since  2.2.2
 */
final class ShopMode
{
    /**
     * Whether the storefront sells online: false in catalogue-only mode.
     *
     * @since  2.2.2
     */
    public static function sellsOnline(): bool
    {
        return (int) ComponentHelper::getParams('com_alfa')->get('catalogue_only', 0) !== 1;
    }
}
