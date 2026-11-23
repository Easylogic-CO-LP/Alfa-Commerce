<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Background;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\WorkStatus;
use Joomla\CMS\Event\AbstractEvent;

/**
 * One of a plugin's tasks or jobs ended (onAlfaWorkSettled): done, failed, held, resolved by staff or cancelled. It is
 * addressed to the plugin that declared the work, only when it has a public onAlfaWorkSettled method, and is
 * delivered by a task of its own written in the same transaction that settled the work, so it is told once the end is
 * final, even if the server stops in between; it is retried when the method throws, so the method must be safe to
 * repeat. Anything that must outlive the row (which goes 30 days after it ends) is written to the plugin's own tables
 * here.
 *
 * @api HOOK: core calls onAlfaWorkSettled() on the one plugin when its work ends.
 *
 * @since  2.3.0
 */
final class WorkSettledEvent extends AbstractEvent
{
    /**
     * The event's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'onAlfaWorkSettled';

    /**
     * @param WorkStatus $status The task or job as it ended.
     * @param string $reason Staff's reason for Mark resolved or Cancel; '' otherwise.
     *
     * @internal Made by core's runner only.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly WorkStatus $status, private readonly string $reason)
    {
        parent::__construct(self::NAME, []);
    }

    /**
     * The task or job as it ended.
     *
     * @since  2.3.0
     */
    public function getStatus(): WorkStatus
    {
        return $this->status;
    }

    /**
     * Staff's reason when they marked it resolved or cancelled it; '' otherwise.
     *
     * @since  2.3.0
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
