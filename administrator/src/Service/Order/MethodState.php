<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

use InvalidArgumentException;
use Joomla\CMS\Factory;
use UnexpectedValueException;

\defined('_JEXEC') or die;

/**
 * A payment or shipping method's state, as the methods' lists store it (Joomla's published, unpublished, archived and
 * trashed), or MISSING when no method has the id.
 *
 * For any caller that must know whether a method is in service before it acts on it (a subscriptions extension before
 * a renewal attempt, an extension's own checks), without reading the methods' tables itself:
 *
 *   if (MethodState::of(MethodState::PAYMENT, $methodId)->isPublished()) { ... }
 *
 * @since  2.3.0
 */
enum MethodState
{
    /**
     * The kind of a payment method (#__alfa_payments).
     *
     * @since  2.3.0
     */
    public const PAYMENT = 'payment';

    /**
     * The kind of a shipping method (#__alfa_shipments).
     *
     * @since  2.3.0
     */
    public const SHIPMENT = 'shipment';

    /** In service: offered and usable. */
    case PUBLISHED;

    /** Out of service, kept. */
    case UNPUBLISHED;

    /** Out of service, archived. */
    case ARCHIVED;

    /** In the trash. */
    case TRASHED;

    /** No method of the kind has the id. */
    case MISSING;

    /**
     * The state of one method, read from its list now (no memo: a state changed earlier in the request is read as it
     * is).
     *
     * @api READ ONLY: one row's state; writes nothing.
     *
     * @param string $kind self::PAYMENT or self::SHIPMENT.
     * @param int $methodId The method's id; an id under 1 is MISSING.
     *
     * @throws InvalidArgumentException When the kind is neither.
     * @throws UnexpectedValueException When the stored state is none of Joomla's four (a damaged row).
     *
     * @since  2.3.0
     */
    public static function of(string $kind, int $methodId): self
    {
        $table = match ($kind) {
            self::PAYMENT => '#__alfa_payments',
            self::SHIPMENT => '#__alfa_shipments',
            default => throw new InvalidArgumentException('MethodState::of(): the kind is "' . self::PAYMENT . '" or "' . self::SHIPMENT . '"; got "' . $kind . '".'),
        };

        if ($methodId < 1) {
            return self::MISSING;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $state = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('state'))
                ->from($db->quoteName($table))
                ->where($db->quoteName('id') . ' = ' . $methodId),
        )->loadResult();

        if ($state === null) {
            return self::MISSING;
        }

        return match ((int) $state) {
            1 => self::PUBLISHED,
            0 => self::UNPUBLISHED,
            2 => self::ARCHIVED,
            -2 => self::TRASHED,
            default => throw new UnexpectedValueException('MethodState::of(): the ' . $kind . ' method #' . $methodId . ' stores the state ' . $state . ', none of Joomla\'s four.'),
        };
    }

    /**
     * Whether the method is in service.
     *
     * @since  2.3.0
     */
    public function isPublished(): bool
    {
        return $this === self::PUBLISHED;
    }
}
