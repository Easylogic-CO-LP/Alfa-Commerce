<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Cleardata;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Maintenance\FactoryReset;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Tools > Clear everything: what would be done (core's tables with their rows, the store of purchased
 * files, the product images kept, each installed Alfa plugin with whether it supports Clear Data), the
 * typed confirmation; and, while Alfa is in maintenance, the state of the run with the actions it
 * allows (Try again and Continue anyway while paused, Run again after a failure).
 *
 * During maintenance this is the one Alfa page that opens. It reads Joomla's tables, the durable state
 * and the database's own catalogue only, never another Alfa table, which may not exist at that moment.
 *
 * @since  2.1.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The maintenance state, or null when Alfa is not in maintenance.
     *
     * @var array<string, mixed>|null
     *
     * @since  2.1.0
     */
    public ?array $state = null;

    /**
     * Whether a run holds the lock now (otherwise a running state is one that ended without a word).
     *
     * @since  2.1.0
     */
    public bool $running = false;

    /**
     * What Clear everything would do (outside maintenance).
     *
     * @var array<string, mixed>|null
     *
     * @since  2.1.0
     */
    public ?array $preview = null;

    /**
     * The outcome of the last Clear everything that finished.
     *
     * @var array<string, mixed>|null
     *
     * @since  2.1.0
     */
    public ?array $last = null;

    /**
     * Render the page.
     *
     * @param string|null $tpl The template name.
     *
     * @since  2.1.0
     */
    public function display($tpl = null): void
    {
        if (!FactoryReset::permitted(user: Factory::getApplication()->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $reset = new FactoryReset();
        $this->state = MaintenanceState::current();
        $this->last = MaintenanceState::lastClear();

        // A run whose request died inside an extension's hook is paused here, that extension failed.
        if ($this->state !== null && !$reset->isRunning() && $reset->recoverStopped(user: Factory::getApplication()->getIdentity())) {
            $this->state = MaintenanceState::current();
        }

        if ($this->state !== null) {
            $this->running = $reset->isRunning();
        } else {
            $this->preview = $reset->preview();
        }

        ToolbarHelper::title(Text::_('COM_ALFA_CLEAR_TITLE'), 'warning');

        if ($this->state === null) {
            ToolbarHelper::back('JTOOLBAR_BACK', 'index.php?option=com_alfa&view=tools');
        }

        parent::display($tpl);
    }
}
