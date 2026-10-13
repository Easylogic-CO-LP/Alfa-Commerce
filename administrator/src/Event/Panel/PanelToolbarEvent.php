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

use Alfa\Component\Alfa\Administrator\Panel\PanelIdentity;
use Alfa\Component\Alfa\Administrator\Toolbar\PanelToolbar;
use Joomla\CMS\Event\AbstractEvent;

/**
 * A panel builds its toolbar: fired at the owning plugin (onAlfaPanelToolbar) from the
 * shared Panel view, handing over the fluent {@see PanelToolbar}.
 *
 *   public function onAlfaPanelToolbar(PanelToolbarEvent $event): void
 *   {
 *       $event->getToolbar()->addNew()->delete();
 *   }
 *
 * The view has already set the page title; the plugin only adds buttons, in Alfa's terms,
 * never touching Joomla's ToolbarHelper.
 *
 * @since  2.0.0
 */
class PanelToolbarEvent extends AbstractEvent
{
    /**
     * The fluent toolbar to add buttons to.
     *
     *
     * @since  2.0.0
     */
    public function getToolbar(): PanelToolbar
    {
        return $this->arguments['toolbar'];
    }

    /**
     * Which screen is rendering: 'list' or 'edit'.
     *
     *
     * @since  2.0.0
     */
    public function getScreen(): string
    {
        return $this->arguments['screen'] ?? 'list';
    }

    /**
     * The list model's state (filters and list options, per-panel scoped), so a toolbar
     * handler can shape its buttons by the active view (the notes panel shows Delete only
     * under the Trashed filter). Null on non-list screens: an edit screen has no list state.
     *
     * Read filters as $event->getState()?->get('filter.<name>', '').
     *
     *
     * @since  2.0.0
     */
    public function getState(): ?object
    {
        return $this->arguments['state'] ?? null;
    }

    /**
     * Who this request is for.
     *
     *
     * @since  2.0.0
     */
    public function getIdentity(): PanelIdentity
    {
        return $this->arguments['identity'];
    }
}
