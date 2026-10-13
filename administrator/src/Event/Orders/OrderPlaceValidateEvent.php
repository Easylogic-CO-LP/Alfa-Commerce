<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Orders;

use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\PlacementEventContext;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

/**
 * Checkout validation event: BOOT-AND-CALL on every feature plugin
 * (`onOrderPlaceValidate`) before the order draft is built. A plugin may VETO
 * the placement with a customer-facing message; the checkout aborts and shows
 * every collected rejection:
 *
 *   public function onOrderPlaceValidate(OrderPlaceValidateEvent $event): void
 *   {
 *       if ($cartNeedsAnAccount && $event->getContext()->customerId === 0) {
 *           $event->reject(Text::_('PLG_..._LOGIN_REQUIRED'));
 *       }
 *   }
 *
 * One shared event travels through all plugins (rejections accumulate); no
 * per-plugin state is produced here, unlike the render events.
 *
 * @since  2.0.0
 */
class OrderPlaceValidateEvent extends AbstractImmutableEvent
{
    /**
     * @var string[] Customer-facing rejection messages.
     * @since  2.0.0
     */
    private array $messages = [];

    /**
     * The cart being converted, as a plugin may see it: its id, its lines and
     * the customer it is for, as values. Nothing here reaches the cart itself,
     * and nothing here can hand a different one back.
     *
     *
     * @since  2.0.0
     */
    public function getContext(): PlacementEventContext
    {
        return $this->arguments['context'];
    }

    /**
     * Veto the placement with a customer-facing message.
     *
     * @param string $message Translated message shown to the customer.
     *
     *
     * @since  2.0.0
     */
    public function reject(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Whether any plugin vetoed the placement.
     *
     *
     * @since  2.0.0
     */
    public function isRejected(): bool
    {
        return $this->messages !== [];
    }

    /**
     * The collected rejection messages.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
