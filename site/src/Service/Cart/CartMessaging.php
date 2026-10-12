<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartPersistenceException;
use Alfa\Component\Alfa\Administrator\Service\Cart\QuantityDecision;
use Alfa\Component\Alfa\Administrator\Service\Cart\QuantityReason;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Throwable;

defined('_JEXEC') or die;

/**
 * What the cart says, and to whom.
 *
 * Two audiences: the shopper, who reads a stable, translated sentence on the
 * message queue and never an engine's words; and the log, which gets the
 * operation, the cart and the failure's own detail. Every owner's net speaks
 * through here so the sentence and the log line are identical wherever the
 * failure happened.
 *
 * @since  1.10.0
 */
final class CartMessaging
{
    /** @since 1.10.0 */
    public function __construct(private readonly CMSApplicationInterface $app)
    {
    }

    /**
     * An operation failed: log its detail, tell the shopper the sentence.
     *
     * @param string $operation What was being done.
     * @param int $cartId Which cart.
     * @param Throwable $e What went wrong.
     * @param string $key The language key of the shopper's sentence.
     * @param string $category The log category.
     *
     * @since  1.10.0
     */
    public function failed(string $operation, int $cartId, Throwable $e, string $key, string $category = 'com_alfa.orders'): void
    {
        AlfaLog::register();
        Log::add(
            $operation . ' failed for cart #' . $cartId . ': '
            . ($e instanceof CartPersistenceException ? $e->getLogDetail() : $e->getMessage()),
            Log::ERROR,
            $category,
        );

        $this->app->enqueueMessage(Text::_($key), 'error');
    }

    /**
     * What a quantity decision means to the shopper, in its order.
     *
     * A refusal the merchant's rules alone cause names the smallest sale and
     * the step; a refusal the shelf causes says the product is out of stock; a
     * cut to what the shelf holds is a notice; every other correction is
     * silent. One decision speaks at most one refusal.
     *
     * @param QuantityDecision $decision The quantity policy's decision.
     *
     *
     * @since  1.10.0
     */
    public function announce(QuantityDecision $decision): void
    {
        foreach ($decision->reasons as $reason) {
            [$sentence, $type] = match ($reason) {
                QuantityReason::NotASaleQuantity => [
                    Text::sprintf('COM_ALFA_CART_QUANTITY_SALE_STEP', $decision->saleMinimum, $decision->saleIncrement),
                    'warning',
                ],
                QuantityReason::StockExhausted => [Text::_(string: 'COM_ALFA_CART_OUT_OF_STOCK'), 'warning'],
                QuantityReason::ClampedToStock => [Text::_(string: 'COM_ALFA_CART_QUANTITY_CLAMPED'), 'notice'],
                QuantityReason::BelowMinimum => [Text::_(string: 'COM_ALFA_CART_OUT_OF_STOCK'), 'warning'],
                default => [null, null],
            };

            if ($sentence !== null) {
                $this->app->enqueueMessage(msg: $sentence, type: $type);
            }
        }
    }

    /**
     * A sentence already in the shopper's language, as a warning.
     *
     * @since  1.10.0
     */
    public function warn(string $sentence): void
    {
        $this->app->enqueueMessage($sentence, 'warning');
    }

    /**
     * A language key, as an error.
     *
     * @since  1.10.0
     */
    public function error(string $key): void
    {
        $this->app->enqueueMessage(Text::_($key), 'error');
    }
}
