<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;

/**
 * order.placement_email: the e-mail of an order's placement, for the status it was placed in.
 *
 * The status e-mail of the initial status, as placement has always sent it, as a single of its own: written with the
 * order, sent once the placement's own work after its commit (the gateway hooks) is done, recovered when the request
 * dies. The same outcomes and staff actions as a status e-mail.
 *
 * @since  2.1.4
 */
final class OrderPlacementEmail extends OrderStatusEmail
{
    /**
     * The handler's name.
     *
     * @since  2.1.4
     */
    public const NAME = 'order.placement_email';

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_ORDER_PLACEMENT_EMAIL',
            retries: true,
            needsApplication: true,
            staffAction: TaskPolicy::ACTION_RESEND,
            attention: 'danger',
            sequence: self::SEQUENCE,
        );
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_PLACEMENT_EMAIL');
    }
}
