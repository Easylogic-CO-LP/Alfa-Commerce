<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * What one price index sync did, for the operator whose save caused it: how many products had their catalogue prices
 * worked out again now, and whether any were left to the background work (a rebuild lock refused, a calculation that
 * failed, a context-wide rewrite past its budget, a failure that was logged), which the catalogue rebuild and the
 * preparation jobs then finish.
 *
 * @since  2.3.0
 */
final class PriceIndexSyncOutcome
{
    /**
     * @param int $products The products whose catalogue prices were worked out again now.
     * @param bool $pending Whether prices were left to the background work.
     *
     * @since  2.3.0
     */
    public function __construct(public readonly int $products, public readonly bool $pending)
    {
    }

    /**
     * The outcome of a sync that could not run: every price is left to the background work.
     *
     * @since  2.3.0
     */
    public static function left(): self
    {
        return new self(products: 0, pending: true);
    }

    /**
     * This outcome and another's as one: the products of both, pending when either is.
     *
     * @since  2.3.0
     */
    public function with(self $other): self
    {
        return new self(products: $this->products + $other->products, pending: $this->pending || $other->pending);
    }

    /**
     * The operator's message: the count worked out again, with the rest following in the background when any was
     * left; nothing worked out now says so and that the background work follows.
     *
     * @since  2.3.0
     */
    public function message(): string
    {
        if ($this->products === 0 && $this->pending) {
            return Text::_('COM_ALFA_PRICE_SYNC_FOLLOWS');
        }

        $text = Text::plural('COM_ALFA_PRICE_SYNC_REPRICED', $this->products);

        return $this->pending ? $text . ' ' . Text::_('COM_ALFA_PRICE_SYNC_REST_FOLLOW') : $text;
    }

    /**
     * The message's type: a warning while prices are still pending, information otherwise.
     *
     * @since  2.3.0
     */
    public function type(): string
    {
        return $this->pending ? 'warning' : 'info';
    }
}
