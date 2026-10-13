<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Panel;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Panel\PanelToolbarEvent;
use Alfa\Component\Alfa\Administrator\Event\Panel\PanelViewEvent;
use Alfa\Component\Alfa\Administrator\Menu\MenuBuilder;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Panel\PanelForm;
use Alfa\Component\Alfa\Administrator\Panel\PanelIdentity;
use Alfa\Component\Alfa\Administrator\Panel\PanelResolver;
use Alfa\Component\Alfa\Administrator\Toolbar\PanelToolbar;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use RuntimeException;

/**
 * The one view behind every panel: the page body of an Alfa admin screen.
 *
 * The menu is drawn into Joomla's native sidebar by {@see \Alfa\Component\Alfa\Administrator\Menu\MenuInjector},
 * so this view owns only the page: it sets the title (from the panel's own menu entry),
 * builds the toolbar frame, and hands the middle to the owning plugin: the plugin picks a
 * layout from its OWN directory on {@see PanelViewEvent}, fed the model's paginated items or
 * the record being edited. Two screens share this one view: 'list' and 'edit'.
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The plugin-rendered body of the current screen.
     *
     * @since  2.0.0
     */
    public string $body = '';

    /**
     * The current screen: 'list' or 'edit'.
     *
     * @since  2.0.0
     */
    public string $screen = 'list';

    /**
     * The form the framework loaded for this screen (when the plugin named one), bound to
     * the record, available to a plugin's own edit layout as $form.
     *
     * @since  2.0.0
     */
    public ?Form $form = null;

    /**
     * The list filter form, read by the native searchtools chrome the plugin's list layout
     * renders (it takes $view => this). Null when the panel ships no filter form.
     *
     * @since  2.0.0
     */
    public ?Form $filterForm = null;

    /**
     * Active filters, for the searchtools chrome.
     *
     * @var array<string, mixed>
     * @since  2.0.0
     */
    public array $activeFilters = [];

    /**
     * Total rows, so the searchtools chrome can show its "no results" hint.
     *
     * @since  2.0.0
     */
    public int $total = 0;

    /**
     * @param string|null $tpl Template.
     *
     *
     * @throws RuntimeException 404 unknown panel, 403 not permitted.
     *
     * @since  2.0.0
     */
    public function display($tpl = null): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $user = $app->getIdentity();

        $identity = PanelResolver::fromInput($input);

        if ($identity === null) {
            throw new RuntimeException(Text::_('COM_ALFA_PANEL_UNKNOWN'), 404);
        }

        // The menu is the panel ACL: a panel the user may not see is absent from it.
        $menu = new MenuBuilder();

        if (!$menu->canAccessPanel($input, $user, $identity->group, $identity->element, $identity->panel)) {
            throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        PanelResolver::boot($identity);

        // The screen is an OPEN string the plugin interprets; 'list' and 'edit' are only
        // conventions. A plugin adds 'import', 'wizard', 'preview', ... and simply ignores
        // (sets no layout on) the screens it does not own.
        $this->screen = $input->getCmd('screen', 'list');

        // The list model's state rides on the toolbar event so a handler can shape its
        // buttons by the active filters; populateState is idempotent, so reading it here
        // first changes nothing for the list render below.
        $state = $this->screen === 'list' ? $this->getModel()->getState() : null;

        $this->addToolbar($identity, $menu->build($input, $user), $state);

        $data = $this->screenData($identity);

        // The plugin chooses which of its own layouts renders this screen, and adds its data.
        // It gets the raw input too, so a custom screen can read its own params.
        $viewEvent = new PanelViewEvent('onAlfaPanelView', [
            'screen' => $this->screen,
            'identity' => $identity,
            'input' => $input,
            'data' => $data,
        ]);

        PanelDispatcher::toOwner($identity, $viewEvent, 'onAlfaPanelView');

        $layoutData = array_merge($data, $viewEvent->getLayoutData());

        // A named form is the native path: the framework loads it (Alfa field types, including
        // the multilingual ones, registered) and binds the record.
        if ($viewEvent->getFormName() !== '') {
            $this->form = PanelForm::load($identity, $viewEvent->getFormName(), $layoutData);
        }

        // Then the body is rendered by, in order of preference: the plugin's OWN layout when
        // it set one (handed the same $form to render however it likes), the framework's
        // default edit template when a form was loaded, or nothing.
        if ($viewEvent->getLayout() !== '') {
            $this->body = (new FileLayout($viewEvent->getLayout(), $identity->pluginPath() . '/layouts'))
                ->render(array_merge($layoutData, ['form' => $this->form, 'view' => $this]));
        } elseif ($this->form !== null) {
            $this->body = (new FileLayout('panel.form', JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'))
                ->render([
                    'form' => $this->form,
                    'identity' => $identity,
                    'token' => Session::getFormToken(),
                    'screen' => $this->screen,
                ]);
        }

        parent::display($tpl);
    }

    /**
     * The data the screen's layout receives: the model's paginated list for 'list', or
     * the record id (and any kept submission) for 'edit'.
     *
     * @param PanelIdentity $identity The panel.
     *
     * @return array<string, mixed>
     *
     * @since  2.0.0
     */
    private function screenData(PanelIdentity $identity): array
    {
        // The CSRF token the plugin's layout embeds in its mutation forms, provided by the
        // framework so the plugin never calls Session itself.
        $data = ['identity' => $identity, 'screen' => $this->screen, 'token' => Session::getFormToken()];

        // Only the list screen draws on the native list model (pagination, filters). Every
        // other screen (edit or a plugin's own) gets the record id and any kept
        // submission; the plugin loads whatever else it needs from the input.
        if ($this->screen === 'list') {
            $model = $this->getModel();

            // The searchtools chrome reads these off the view object ($view->filterForm etc.),
            // so keep them on the view as well as in the layout data.
            $this->filterForm = $model->getFilterForm();
            $this->activeFilters = $model->getActiveFilters();

            $data['items'] = $model->getItems();
            $data['pagination'] = $model->getPagination();
            $data['state'] = $model->getState();
            $data['filterForm'] = $this->filterForm;
            $data['activeFilters'] = $this->activeFilters;

            $this->total = (int) ($data['pagination']->total ?? 0);

            return $data;
        }

        $app = Factory::getApplication();
        $data['id'] = $app->getInput()->getInt('id', 0);

        // Restore any submission kept across a failed save, then clear it.
        if (method_exists($app, 'getUserState')) {
            $kept = $app->getUserState($identity->stateKey('form'), null);

            if (\is_array($kept)) {
                $data['form'] = $kept;
            }

            $app->setUserState($identity->stateKey('form'), null);
        }

        return $data;
    }

    /**
     * Set the page title (from the active menu entry) and let the plugin add toolbar buttons.
     *
     * @param PanelIdentity $identity The panel.
     * @param array<int, array{label:string, items:array}> $menu The built menu.
     * @param object|null $state The list state (list screens only).
     *
     *
     * @since  2.0.0
     */
    private function addToolbar(PanelIdentity $identity, array $menu, ?object $state = null): void
    {
        // Every panel screen (list or edit) wears the neutral generic icon: the panel's
        // own identity is the menu item and the title text, not the toolbar glyph.
        ToolbarHelper::title($this->activeTitle($menu), 'generic');

        if ($this->screen === 'edit') {
            // Native edit UX: the CMS sidebar collapses so the form owns the canvas; our
            // injected menu honours the same flag (MenuInjector skips when hidemainmenu is set).
            Factory::getApplication()->getInput()->set('hidemainmenu', true);

            // The component's own admin form assets, so a panel form looks and validates like a
            // native Alfa edit screen (and Save's Joomla.submitform finds the validator).
            $this->getDocument()->getWebAssetManager()
                ->useStyle('com_alfa.admin')
                ->useScript('keepalive')
                ->useScript('form.validate');
        }

        // The plugin speaks FIRST: adding its own buttons and, when its domain is exceptional
        // (a read-only registry, machine-created rows), vetoing natives via suppressNative().
        $bar = $this->getDocument()->getToolbar();
        $toolbar = new PanelToolbar($bar);

        $event = new PanelToolbarEvent('onAlfaPanelToolbar', [
            'toolbar' => $toolbar,
            'screen' => $this->screen,
            'identity' => $identity,
            'state' => $state,
        ]);

        PanelDispatcher::toOwner($identity, $event, 'onAlfaPanelToolbar');

        // Then the framework draws each screen's surviving native verbs, so every panel looks
        // native with no plugin code. Suppression is UX only: the panel controller keeps
        // routing (and CSRF-checking) these tasks whether or not a button points at them.
        $before = \count($bar->getItems());

        if ($this->screen === 'edit') {
            if (!$toolbar->isSuppressed(PanelToolbar::NATIVE_SAVE)) {
                ToolbarHelper::save('panel.save');
            }

            if (!$toolbar->isSuppressed(PanelToolbar::NATIVE_CANCEL)) {
                ToolbarHelper::cancel('panel.cancel');
            }
        } elseif ($this->screen === 'list') {
            // New opens the edit screen; Delete removes the checked rows through the native
            // adminForm the plugin's list layout wraps its table in.
            if (!$toolbar->isSuppressed(PanelToolbar::NATIVE_NEW)) {
                ToolbarHelper::addNew('panel.add');
            }

            if (!$toolbar->isSuppressed(PanelToolbar::NATIVE_DELETE)) {
                // Same confirmation as PanelToolbar::delete() and as every core list: a
                // permanent delete must never be one stray click away.
                ToolbarHelper::deleteList('JGLOBAL_CONFIRM_DELETE', 'panel.delete');
            }
        }

        // Natives render leftmost, exactly as before the event ran first: move the ones just
        // appended in front of any buttons the plugin added during dispatch.
        $items = $bar->getItems();
        $natives = array_splice($items, $before);

        if ($natives !== [] && $items !== []) {
            $bar->setItems([...$natives, ...$items]);
        }
    }

    /**
     * The title of the active menu entry (reused as the page title), or a generic fallback.
     *
     * @param array<int, array{label:string, items:array}> $menu The built menu.
     *
     *
     * @since  2.0.0
     */
    private function activeTitle(array $menu): string
    {
        foreach ($menu as $group) {
            foreach ($group['items'] as $item) {
                if ($item->active) {
                    return Text::_($item->title);
                }
            }
        }

        return Text::_('COM_ALFA');
    }
}
