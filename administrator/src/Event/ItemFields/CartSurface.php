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
 * The render slots a field plugin may target on a CART LINE (onCartItemView).
 * The event IS the cart-line context, so today there is one slot: the field's
 * presentation under the line name. New slots (e.g. an actions area) become new
 * cases only when the cart template grows them.
 *
 * @since  2.0.0
 */
enum CartSurface: string
{
    /** The field's presentation on the cart line (cart-item-fields). The default. */
    case Main = 'main';
}
