<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use RuntimeException;
use Throwable;

/**
 * A change of a product's combinations refused because the product's own stock still holds, or open order lines
 * still rely on it: the refusal in two forms, the plain sentence (the message, for an API answer or a log) and the
 * same sentence as markup, where every open order line links to its order, for the screen that shows it in a dialog.
 * @since  2.3.0
 */
final class StockHeldRefusedException extends RuntimeException
{
    /**
     * @param string $message The refusal as plain text.
     * @param string $markup The same refusal as markup, its names escaped and its order lines linked.
     * @param Throwable|null $previous What caused it.
     *
     * @since  2.3.0
     */
    public function __construct(string $message, public readonly string $markup, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
