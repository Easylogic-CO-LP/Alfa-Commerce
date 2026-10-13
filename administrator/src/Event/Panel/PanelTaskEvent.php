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
 * A panel's OWN task: fired at the owning plugin (onAlfaPanelTask) when the shared Panel
 * controller receives a task it does not itself implement, so a plugin adds any verb it
 * likes (rebuild, import, export, toggle, ...) without shipping a controller.
 *
 *   public function onAlfaPanelTask(PanelTaskEvent $event): void
 *   {
 *       if ($event->getTask() !== 'toggle') {
 *           return;                       // not mine; leave it unhandled
 *       }
 *       $this->service->toggle($event->getInput()->getInt('id'));
 *       $event->markHandled()->setRedirect($event->getInput()->getCmd('return', ''));
 *   }
 *
 * The controller has already validated the CSRF token, and a task reaches this event by GET
 * only when its panel's menu entry declares it a lookup
 * ({@see \Alfa\Component\Alfa\Administrator\Menu\MenuItem::lookups()}); every other task
 * comes from a form's POST. A task no plugin marks handled is treated as unknown.
 *
 * @since  2.0.0
 */
class PanelTaskEvent extends PanelResultEvent
{
    /**
     * The task verb, without the controller prefix (e.g. 'rebuild', 'toggle').
     *
     *
     * @since  2.0.0
     */
    public function getTask(): string
    {
        return $this->arguments['task'] ?? '';
    }
}
