<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\CMS\User\User;

/**
 * Who writes a dispute: a member of staff (the order screen, the REST API) or a payment plugin from its provider's word.
 *
 * Core's own door to the disputes ledger names it explicitly ({@see DisputeLedger::for()}, {@see DisputeLedger::load()}):
 * nothing about who wrote is read from the request. A payment plugin never names one: its one way in is its base class's
 * dispute(), which writes as that plugin.
 *
 * @since  2.3.0
 */
final class DisputeWriter
{
    /**
     * A member of staff.
     *
     * @since  2.3.0
     */
    public const STAFF = 'staff';

    /**
     * A payment plugin, from its provider's word.
     *
     * @since  2.3.0
     */
    public const GATEWAY = 'gateway';

    /**
     * @param string $kind self::STAFF or self::GATEWAY.
     * @param User|null $user The member of staff; null for a gateway.
     * @param string $element The payment plugin's element; '' for staff.
     *
     * @since  2.3.0
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?User $user,
        public readonly string $element,
    ) {
    }

    /**
     * A signed-in member of staff.
     *
     * @param User $user The user acting on the order screen or through the REST API.
     *
     * @throws InvalidArgumentException For a guest: a dispute is never written by nobody.
     *
     * @since  2.3.0
     */
    public static function staff(User $user): self
    {
        if ((int) $user->id <= 0 || $user->guest) {
            throw new InvalidArgumentException('A dispute is written by a signed-in member of staff, never by a guest.');
        }

        return new self(kind: self::STAFF, user: $user, element: '');
    }

    /**
     * A payment plugin: the one gateway that may write the disputes of its own payments.
     *
     * @param string $element The plugin's element, as the disputed payment records it.
     *
     * @throws InvalidArgumentException For an empty element.
     *
     * @since  2.3.0
     */
    public static function gateway(string $element): self
    {
        if (trim($element) === '') {
            throw new InvalidArgumentException('A gateway writer names its plugin.');
        }

        return new self(kind: self::GATEWAY, user: null, element: $element);
    }

    /**
     * Whether a member of staff writes.
     *
     * @since  2.3.0
     */
    public function isStaff(): bool
    {
        return $this->kind === self::STAFF;
    }

    /**
     * Whether a payment plugin writes.
     *
     * @since  2.3.0
     */
    public function isGateway(): bool
    {
        return $this->kind === self::GATEWAY;
    }

    /**
     * The user id the record and the timeline name: the member of staff, 0 for a gateway.
     *
     * @since  2.3.0
     */
    public function userId(): int
    {
        return $this->user === null ? 0 : (int) $this->user->id;
    }
}
