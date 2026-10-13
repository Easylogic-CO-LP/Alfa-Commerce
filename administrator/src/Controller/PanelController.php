<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Panel\PanelDeleteEvent;
use Alfa\Component\Alfa\Administrator\Event\Panel\PanelResultEvent;
use Alfa\Component\Alfa\Administrator\Event\Panel\PanelSaveEvent;
use Alfa\Component\Alfa\Administrator\Event\Panel\PanelTaskEvent;
use Alfa\Component\Alfa\Administrator\Menu\MenuBuilder;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Panel\PanelIdentity;
use Alfa\Component\Alfa\Administrator\Panel\PanelResolver;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use RuntimeException;
use Throwable;

/**
 * The one controller behind every panel: the mutation half of the shared MVC (the display
 * half is {@see \Alfa\Component\Alfa\Administrator\View\Panel\HtmlView}).
 *
 * TASK OWNERSHIP IS FIXED. The framework owns the native verbs (save, delete, add, edit,
 * cancel) and a plugin can NEVER replace them: the app must always have a place to save
 * to. Each native mutation validates the CSRF token itself (native `checkToken()`; this is
 * the whole read/write boundary, no bespoke guard) and then fires ONE event so the owning
 * plugin does its specific work through its own service. A verb the framework does not own
 * is a plugin's OWN task (rebuild, import, toggle, ...), delegated to {@see PanelTaskEvent};
 * an unhandled one is reported as unknown. Plugins add tasks; they do not override them.
 * A plugin task is accepted from a POST alone, unless the panel's menu entry declares it a
 * lookup, which may also come by GET
 * ({@see \Alfa\Component\Alfa\Administrator\Menu\MenuItem::lookups()}).
 *
 * @since  2.0.0
 */
class PanelController extends BaseController
{
    /**
     * The verbs the framework owns, never delegated to a plugin as a custom task.
     *
     * @var string[]
     * @since  2.0.0
     */
    private const NATIVE_TASKS = ['display', 'add', 'edit', 'cancel', 'save', 'apply', 'delete'];

    /**
     * Route a task: native verbs run their fixed method; anything else is the owning
     * plugin's own task.
     *
     * @param string $task The task.
     *
     * @return mixed
     *
     * @since  2.0.0
     */
    public function execute($task)
    {
        $task = (string) $task;

        if ($task !== '' && !\in_array($task, self::NATIVE_TASKS, true)) {
            return $this->pluginTask($task);
        }

        return parent::execute($task);
    }

    /**
     * Open the edit screen for a new record.
     *
     * @since  2.0.0
     */
    public function add(): void
    {
        $this->editScreen(0);
    }

    /**
     * Open the edit screen for the checked record.
     *
     * @since  2.0.0
     */
    public function edit(): void
    {
        $cid = (array) $this->input->get('cid', [], 'array');

        $this->editScreen((int) ($cid[0] ?? 0));
    }

    /**
     * Leave the edit screen without saving.
     *
     * @since  2.0.0
     */
    public function cancel(): void
    {
        $this->setRedirect(Route::_($this->listUrl($this->requireIdentity(false)), false));
    }

    /**
     * Persist a submitted record: the framework's native save, doing its work through the
     * owning plugin.
     *
     * @since  2.0.0
     */
    public function save(): void
    {
        $this->checkToken();

        $identity = $this->requireIdentity();
        $event = new PanelSaveEvent('onAlfaPanelSave', [
            'identity' => $identity,
            'input' => $this->input,
        ]);

        try {
            PanelDispatcher::toOwner($identity, $event, 'onAlfaPanelSave', false);
        } catch (Throwable $e) {
            // A save that threw is never a silent no-op: tell the user, keep the submission so
            // it is not lost, and re-show the edit screen.
            $this->failed($identity, $identity->editUrl($this->input->getInt('id', 0)), true);

            return;
        }

        // On invalid input the plugin asks us to re-show the form: stash the submission so
        // the edit screen can restore it. The framework owns user state; the plugin does not.
        if ($event->shouldKeepInput() && method_exists($this->app, 'setUserState')) {
            $this->app->setUserState($identity->stateKey('form'), $this->input->post->getArray());
        }

        $this->finish($event, $this->listUrl($identity));
    }

    /**
     * Save (an alias kept so a plugin's "apply"-style button routes natively, not as a
     * custom task).
     *
     * @since  2.0.0
     */
    public function apply(): void
    {
        $this->save();
    }

    /**
     * Delete the checked records: the framework's native delete, through the owning plugin.
     *
     * @since  2.0.0
     */
    public function delete(): void
    {
        $this->checkToken();

        $identity = $this->requireIdentity();
        $event = new PanelDeleteEvent('onAlfaPanelDelete', [
            'identity' => $identity,
            'input' => $this->input,
            'ids' => (array) $this->input->get('cid', [], 'array'),
        ]);

        try {
            PanelDispatcher::toOwner($identity, $event, 'onAlfaPanelDelete', false);
        } catch (Throwable $e) {
            $this->failed($identity, $this->listUrl($identity));

            return;
        }

        $this->finish($event, $this->listUrl($identity));
    }

    /**
     * A plugin's own task (unknown to the framework): CSRF-checked, held to a POST unless
     * its panel declares it a lookup, delegated, and reported if no plugin claims it.
     *
     * @param string $task The task verb.
     *
     * @since  2.0.0
     */
    private function pluginTask(string $task): void
    {
        // 'request', not 'post': a plugin's lookups (a picker searching as the merchant
        // types) are GETs from a widget that can only GET. The token still has to travel.
        $this->checkToken('request');

        $identity = $this->requireIdentity();

        // Every task its panel does not declare a lookup changes data, so it is accepted from
        // a form's POST alone: a link or a prefetch carrying the token changes nothing. The
        // owner is not asked.
        if ($this->input->getMethod() !== 'POST' && !\in_array($task, $this->lookups($identity), true)) {
            $this->app->enqueueMessage(Text::_('COM_ALFA_PANEL_TASK_POST_ONLY'), 'error');
            $this->setRedirect(Route::_($this->listUrl($identity), false));
            $this->redirect();

            return;
        }

        $event = new PanelTaskEvent('onAlfaPanelTask', [
            'identity' => $identity,
            'input' => $this->input,
            'task' => $task,
        ]);

        try {
            PanelDispatcher::toOwner($identity, $event, 'onAlfaPanelTask', false);
        } catch (Throwable $e) {
            $this->failed($identity, $this->listUrl($identity));

            return;
        }

        if (!$event->isHandled()) {
            $this->app->enqueueMessage(Text::sprintf('COM_ALFA_PANEL_UNKNOWN_TASK', $task), 'error');
            $event->setRedirect($this->listUrl($identity));
        }

        $this->finish($event, $this->listUrl($identity));
    }

    /**
     * Enqueue a mutation's messages and follow its redirect (or the panel's list).
     *
     * @param PanelResultEvent $event The completed mutation.
     * @param string $default The fallback redirect (the panel's list).
     *
     * @since  2.0.0
     */
    private function finish(PanelResultEvent $event, string $default): void
    {
        // A well-behaved mutation always reports: a message and/or a redirect. Silence means
        // the owner did nothing (a missing handler, a no-op), so say so rather than bounce to
        // the list looking like success.
        if ($event->getMessages() === [] && $event->getRedirect() === '') {
            $this->app->enqueueMessage(Text::_('COM_ALFA_PANEL_ACTION_FAILED'), 'error');
        }

        foreach ($event->getMessages() as $message) {
            $this->app->enqueueMessage($message['text'], $message['type']);
        }

        $target = $event->getRedirect() !== '' ? $event->getRedirect() : $default;

        $this->setRedirect(Route::_($target, false));
        $this->redirect();
    }

    /**
     * Report a mutation that threw; the framework never lets a failed save, delete or task
     * look like success. Enqueue an error, optionally keep the submission (save), and send the
     * user back where they were.
     *
     * @param PanelIdentity $identity The panel.
     * @param string $back The URL to return to (edit for save, list otherwise).
     * @param bool $keepInput Whether to stash the POST so the edit form restores it.
     *
     * @since  2.0.0
     */
    private function failed(PanelIdentity $identity, string $back, bool $keepInput = false): void
    {
        $this->app->enqueueMessage(Text::_('COM_ALFA_PANEL_ACTION_FAILED'), 'error');

        if ($keepInput && method_exists($this->app, 'setUserState')) {
            $this->app->setUserState($identity->stateKey('form'), $this->input->post->getArray());
        }

        $this->setRedirect(Route::_($back, false));
        $this->redirect();
    }

    /**
     * Send the browser to the edit screen (a display navigation, so no token needed).
     *
     * @param int $id The record id (0 for a new record).
     *
     * @since  2.0.0
     */
    private function editScreen(int $id): void
    {
        $identity = $this->requireIdentity(false);

        $url = $this->panelUrl($identity) . '&screen=edit&id=' . $id;

        $this->setRedirect(Route::_($url, false));
    }

    /**
     * Resolve and authorise the panel, or fail. Mutations pass true (they must be a live
     * panel the user may manage); navigation passes false only to skip nothing; both
     * still require identity + component access.
     *
     * @param bool $strict Reserved for future per-panel checks; both paths gate on access today.
     *
     *
     * @throws RuntimeException 404 unknown panel, 403 not permitted.
     *
     * @since  2.0.0
     */
    private function requireIdentity(bool $strict = true): PanelIdentity
    {
        $identity = PanelResolver::fromInput($this->input);

        if ($identity === null) {
            throw new RuntimeException(Text::_('COM_ALFA_PANEL_UNKNOWN'), 404);
        }

        // The menu is the panel ACL: if the user cannot see this panel, they cannot act on it.
        if (!(new MenuBuilder())->canAccessPanel($this->input, $this->app->getIdentity(), $identity->group, $identity->element, $identity->panel)) {
            throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return $identity;
    }

    /**
     * The tasks a panel declares as lookups on its menu entry, the only ones it accepts by GET.
     *
     * @param PanelIdentity $identity The panel.
     *
     * @return string[]
     * @since  2.0.0
     */
    private function lookups(PanelIdentity $identity): array
    {
        return (new MenuBuilder())->panelLookups(
            input: $this->input,
            user: $this->app->getIdentity(),
            group: $identity->group,
            element: $identity->element,
            panel: $identity->panel,
        );
    }

    /**
     * The list URL for a panel.
     *
     * @param PanelIdentity $identity The panel.
     *
     * @since  2.0.0
     */
    private function listUrl(PanelIdentity $identity): string
    {
        return $this->panelUrl($identity);
    }

    /**
     * The base panel URL (list screen) for an identity.
     *
     * @param PanelIdentity $identity The panel.
     *
     * @since  2.0.0
     */
    private function panelUrl(PanelIdentity $identity): string
    {
        return 'index.php?option=com_alfa&view=panel'
            . '&group=' . rawurlencode($identity->group)
            . '&element=' . rawurlencode($identity->element)
            . '&panel=' . rawurlencode($identity->panel);
    }
}
