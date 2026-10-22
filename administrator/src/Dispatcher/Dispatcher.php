<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Dispatcher;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\DisplayController;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Service\Download\StoreProtection;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\FactoryReset;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use RuntimeException;
use Throwable;

/**
 * ComponentDispatcher class for Com_Alfa, and the door of every administrator screen: a screen
 * opens only for a user holding its own Alfa permission ({@see self::checkAccess()}).
 *
 * @since  1.0.0
 */
class Dispatcher extends ComponentDispatcher
{
    /**
     * Dispatch a controller task. Redirecting the user if appropriate.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function dispatch()
    {
        // Refused before any housekeeping below runs for a request that will not be served.
        // parent::dispatch() asks again; the answer is the same.
        $this->checkAccess();

        // Maintenance (Clear everything): only the Clear everything page and its own requests are
        // served, and none of the housekeeping below runs, since it reads the Alfa tables.
        if (MaintenanceState::active()) {
            if (!$this->isMaintenanceRequest()) {
                if (
                    $this->input->getCmd('format', 'html') === 'html'
                    && FactoryReset::permitted(user: $this->app->getIdentity())
                ) {
                    $this->app->redirect(Route::_('index.php?option=com_alfa&view=cleardata', false));

                    return;
                }

                throw new RuntimeException(Text::_('COM_ALFA_MAINTENANCE_ADMIN'), 503);
            }

            parent::dispatch();

            return;
        }

        // Register the item-fields plugins' getSubscribedEvents maps with the
        // GLOBAL dispatcher (bootPlugin() alone never does), so a field type
        // can also hook ambient events (onBeforeRender, onBeforeCompileHead,
        // ...) on every com_alfa admin page.
        PluginHelper::importPlugin('alfa-item-fields');

        // Override-proof tamper banner: when the last integrity sync recorded a real
        // alarm (modified / bad signature), surface it in the admin chrome on every
        // com_alfa HTML page. Decided here (non-overridable source, signed) and read
        // from the stored verdict (no CDN call -> never blocks), so faking the Tools
        // card or overriding the notification layout still can't hide tampering.
        try {
            if (
                $this->input->getCmd('format', 'html') === 'html'
                && SyncHelper::hasIntegrityAlarm()
            ) {
                // No language string (can't be silenced by a language override) and the
                // text is character-encoded so a plaintext file search for the wording doesn't
                // surface it. Editing it means touching this signed source (which is checked).
                $this->app->enqueueMessage(
                    "\x3c\x73\x74\x72\x6f\x6e\x67\x3e\x53\x65\x63\x75\x72\x69\x74\x79\x20\x63\x6f\x6e\x63\x65\x72\x6e\x3a\x3c\x2f\x73\x74\x72\x6f\x6e\x67\x3e\x20\x74\x68\x69\x73\x20\x41\x6c\x66\x61\x20\x43\x6f\x6d\x6d\x65\x72\x63\x65\x20\x69\x6e\x73\x74\x61\x6c\x6c\x61\x74\x69\x6f\x6e\x20\x64\x6f\x65\x73\x20\x6e\x6f\x74\x20\x6d\x61\x74\x63\x68\x20\x74\x68\x65\x20\x73\x69\x67\x6e\x65\x64\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x72\x65\x6c\x65\x61\x73\x65\x2e\x20\x49\x74\x73\x20\x66\x69\x6c\x65\x73\x20\x68\x61\x76\x65\x20\x62\x65\x65\x6e\x20\x63\x68\x61\x6e\x67\x65\x64\x2e",
                    'error',
                );
            }
        } catch (Throwable) {
            // Never let the tamper banner break the page.
        }

        // Ambient setup tick: serve the CACHED checklist verdict (recompute at most
        // once per TTL), which is also what pushes/clears the `alfa.setup`
        // notification on a real transition. A cache hit costs one read. Admin only:
        // this dispatcher serves the storefront too, and shoppers must never pay for
        // (or trigger) admin housekeeping.
        try {
            if (
                $this->app->isClient('administrator')
                && $this->input->getCmd('format', 'html') === 'html'
            ) {
                SetupHelper::sync();
            }
        } catch (Throwable) {
            // Never let checklist housekeeping break the page.
        }

        // The download store's direct-download protection: said on every administrator page that
        // renders while it is not verified. Reading it probes only when the store's path changed
        // since the last check (StoreProtection::current()); never on the storefront. A task
        // request is left alone, so the page it redirects to says it once.
        try {
            $task = strtolower($this->input->getCmd('task', ''));

            if (
                $this->app->isClient('administrator')
                && $this->input->getCmd('format', 'html') === 'html'
                && ($task === '' || $task === 'display' || str_ends_with($task, '.display'))
            ) {
                $protection = new StoreProtection();
                $result = $protection->current();
                $warning = $protection->warning($result);

                if ($warning !== null) {
                    $this->app->enqueueMessage($warning, $result->state === StoreProtection::STATE_EXPOSED ? 'error' : 'warning');
                }
            }
        } catch (Throwable) {
            // Never let the protection check break the page; the Options show why it failed.
        }

        // Tour grammar supplement (Next on press steps, "Finish without saving") rides
        // with every admin HTML page (a tour runs across pages), but only while the
        // core tours plugin is there to run tours at all.
        try {
            if (
                $this->app->isClient('administrator')
                && $this->input->getCmd('format', 'html') === 'html'
                && PluginHelper::isEnabled('system', 'guidedtours')
            ) {
                TourHelper::attachGrammar($this->app->getDocument());
            }
        } catch (Throwable) {
            // Never let tour polish break the page.
        }

        parent::dispatch();
    }

    /**
     * The controller for this request, and the page it renders, whenever the task it runs is
     * display.
     *
     * Judged by what BaseController::execute() will really run: the task ComponentDispatcher handed
     * over (it writes the task half of controller.task back to the input, and a request without a
     * task runs display), else the controller's default task. When that is display, the view
     * display() renders (the request's, else the controller's own) must be one the user may open,
     * however the request spelled it: a task nobody declared, a controller named without a task,
     * a controller.display.more command.
     *
     * @param string $name Controller name.
     * @param string $client Client, e.g. Administrator.
     * @param array $config Controller config.
     *
     *
     * @throws NotAllowed When the page may not be opened.
     *
     * @since  2.0.0
     */
    public function getController(string $name, string $client = '', array $config = []): BaseController
    {
        $controller = parent::getController($name, $client, $config);

        if (!$this->app->isClient('administrator')) {
            return $controller;
        }

        $task = strtolower($this->input->getCmd('task', 'display'));

        [$taskMap, $defaultView] = (fn (): array => [$this->taskMap, (string) $this->default_view])->call($controller);

        // BaseController::execute(): the task's own method, else the default task.
        if (strtolower((string) ($taskMap[$task] ?? $taskMap['__default'] ?? '')) !== 'display') {
            return $controller;
        }

        // BaseController::display(): the request's view, else the controller's default view.
        $view = $this->input->get('view', $defaultView);

        if (!\is_string($view) || !$this->mayOpen(view: strtolower($view))) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // A record's form is rendered only by the display controller, which refuses an id the edit task
        // did not hold; another controller's display would render it past that check.
        $layout = $this->input->get('layout', '', 'string');

        if (
            !$controller instanceof DisplayController
            && AlfaHelper::isRecordForm(view: strtolower($view), layout: \is_string($layout) ? $layout : '')
            && AlfaHelper::recordId(view: strtolower($view), input: $this->input) > 0
        ) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return $controller;
    }

    /**
     * Whether the request is one Alfa serves while in maintenance: the Clear everything page, or
     * one of its requests (run, Try again, Continue anyway, Run again). Read the way
     * {@see self::checkAccess()} reads the command.
     *
     * @since  2.1.0
     */
    private function isMaintenanceRequest(): bool
    {
        $command = strtolower($this->input->getCmd('task', ''));

        if (str_contains($command, '.')) {
            return \in_array($command, ['cleardata.run', 'cleardata.retry', 'cleardata.proceed', 'cleardata.resume'], true);
        }

        return ($command === '' || $command === 'display')
            && strtolower($this->input->getCmd('view', '')) === 'cleardata'
            && strtolower($this->input->getCmd('controller', '')) === '';
    }

    /**
     * Joomla's door, then Alfa's: entering the component (Joomla's own core.manage), then the
     * screen being opened, by its own permission. A screen this map does not know stays shut.
     *
     * The command is read exactly as ComponentDispatcher::dispatch() reads it, so a page is refused
     * here, before any housekeeping runs; {@see self::getController()} judges it again against the
     * controller that will really run. A task (save, publish, delete, an AJAX endpoint) is refused
     * by the controller and model that run it, which also guards the same work when it arrives
     * through the API or from code.
     *
     * @return void
     *
     * @throws NotAllowed When the user may not open the screen.
     *
     * @since  2.0.0
     */
    protected function checkAccess()
    {
        parent::checkAccess();

        if (!$this->app->isClient('administrator')) {
            return;
        }

        $command = $this->input->getCmd('task', 'display');

        if (str_contains($command, '.')) {
            [$controller, $task] = explode('.', $command);
        } else {
            $controller = $this->input->getCmd('controller', 'display');
            $task = $command;
        }

        if ($task !== '' && strtolower($task) !== 'display') {
            return;
        }

        // A controller's default view is its own name; the display controller's is the dashboard.
        $controller = strtolower($controller);
        $view = strtolower($this->input->getCmd('view', '') ?: ($controller === 'display' ? 'dashboard' : $controller));

        if (!$this->mayOpen(view: $view)) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * May the current user open this screen: {@see AlfaHelper::mayOpenScreen()}, the same answer a
     * plugin's link to the screen is shown by.
     *
     * @param string $view The requested view, lower-cased.
     *
     *
     * @since  2.0.0
     */
    private function mayOpen(string $view): bool
    {
        // Each of these checks its own permission when it renders.
        if (\in_array($view, AlfaHelper::SELF_GUARDED_SCREENS, true)) {
            return true;
        }

        // Read as BaseController::display() reads it: a string, so a template:layout pair survives.
        $layout = $this->input->get('layout', '', 'string');

        return AlfaHelper::mayOpenScreen(
            view: $view,
            layout: \is_string($layout) ? $layout : '',
            id: AlfaHelper::recordId(view: $view, input: $this->input),
            user: $this->app->getIdentity(),
        );
    }
}
