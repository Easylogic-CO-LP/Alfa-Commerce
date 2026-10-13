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
 * Persist a submitted panel record: fired at the owning plugin (onAlfaPanelSave) from the
 * shared Panel controller's save task, AFTER the CSRF token is validated.
 *
 * The plugin validates and saves through its own service, then declares the outcome on the
 * inherited fluent API: on success `addMessage('...SAVED')->setRedirect($listUrl)`; on
 * invalid input `addMessage($err, 'error')` per error and `setRedirect($editUrl)` back to
 * the form. The record primary key travels as the plain `id`.
 *
 * @since  2.0.0
 */
class PanelSaveEvent extends PanelResultEvent
{
    /**
     * On invalid input, ask the framework to carry the submission back to the form so the
     * user does not lose their edits. The framework stashes and restores it; the plugin
     * never touches user state.
     *
     *
     * @since  2.0.0
     */
    public function keepInput(): static
    {
        $this->arguments['keepInput'] = true;

        return $this;
    }

    /**
     * Whether the framework should re-show the submitted input on the edit screen.
     *
     *
     * @since  2.0.0
     */
    public function shouldKeepInput(): bool
    {
        return (bool) ($this->arguments['keepInput'] ?? false);
    }
}
