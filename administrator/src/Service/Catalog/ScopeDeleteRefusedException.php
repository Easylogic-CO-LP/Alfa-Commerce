<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

/**
 * A delete refused because it would silently widen a surviving rule: a
 * discount or tax left with no scope row on the deleted entity's axis (read by
 * the pricing engine as "applies everywhere"), or a cart rule whose "is NOT one
 * of" condition names a deleted category or manufacturer (its products would
 * leave the exclusion).
 *
 * The message is the merchant's sentence naming the rules; the rules travel
 * with it so a screen or an API can state them its own way.
 *
 * @since  2.0.0
 */
final class ScopeDeleteRefusedException extends DeleteRefusedException
{
    /**
     * @param string $axis 'category', 'place' or 'manufacturer'.
     * @param array $rules The rules the delete would widen, each
     *                     ['kind' => 'discount'|'tax'|'cart_rule', 'id' => int, 'name' => string].
     * @param string $message The translated refusal, safe to print as HTML.
     * @param string $plain The same refusal as text, for the REST answer.
     *
     * @since   2.0.0
     */
    public function __construct(public readonly string $axis, public readonly array $rules, string $message, string $plain)
    {
        parent::__construct($message, $plain);
    }
}
