<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Panel;

\defined('_JEXEC') or die;

/**
 * Delete selected panel records: fired at the owning plugin (onAlfaPanelDelete) from the
 * shared Panel controller's delete task, AFTER the CSRF token is validated.
 *
 * The plugin removes the given ids through its own service and declares the outcome on the
 * inherited fluent API (message + redirect).
 *
 * @since  2.0.0
 */
class PanelDeleteEvent extends PanelResultEvent
{
    /**
     * The record ids to delete (the list's selected rows).
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public function getIds(): array
    {
        return array_map('intval', (array) ($this->arguments['ids'] ?? []));
    }
}
