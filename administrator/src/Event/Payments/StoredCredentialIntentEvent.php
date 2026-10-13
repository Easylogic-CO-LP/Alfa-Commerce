<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Stored Credential Intent Event
 *
 * Asked by a recurring-capable payment gateway right before it creates its
 * checkout order: does THIS order need a future merchant-initiated charge
 * capability (a stored credential / mandate)?
 *
 * Feature plugins that sell recurring things answer setRequired() when the
 * order qualifies. The gateway only reads the generic answer; it never
 * learns WHAT makes the order recurring, and with no listeners the answer
 * stays false (customer consent for stored credentials is never requested
 * on ordinary purchases).
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Site\Service\Order\Order;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class StoredCredentialIntentEvent extends AbstractImmutableEvent
{
    /**
     * The order about to be paid.
     *
     * @since  2.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->arguments['order']);
    }

    /**
     * Declare that this order needs a stored credential.
     *
     * @since  2.0.0
     */
    public function setRequired(): void
    {
        $this->arguments['required'] = true;
    }

    /**
     * Whether any listener requested a stored credential.
     *
     * @since  2.0.0
     */
    public function isRequired(): bool
    {
        return (bool) ($this->arguments['required'] ?? false);
    }
}
