<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Tasks;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Helper\ToolButton;
use Alfa\Component\Alfa\Administrator\Helper\ToolRegistry;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Model\OutboundModel;
use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Throwable;

/**
 * The Background screen: the shop's background work as one list with its own filters, order and pages. Every task and
 * job in its state of work (running now, waiting, needs attention, finished), with what staff may do with each; one
 * job's progress followed when asked (&job=N). The toolbar runs the background work's tools (the actions registered
 * for the background surface), deletes the finished entries checked, and leads to the outgoing notifications, which
 * have a screen of their own.
 *
 * @since  2.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The surface whose registered actions are the toolbar's tools.
     *
     * @since  2.3.0
     */
    public const SURFACE = 'background';

    /**
     * The id of the element under the toolbar that shows a tool's progress and outcome.
     *
     * @since  2.3.0
     */
    public const PROGRESS_ID = 'background-progress';

    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'task';

    /**
     * The rows of the page.
     *
     * @var array
     *
     * @since  2.3.0
     */
    protected $items;

    /**
     * The pagination.
     *
     * @var \Joomla\CMS\Pagination\Pagination
     *
     * @since  2.3.0
     */
    protected $pagination;

    /**
     * The model's state.
     *
     * @var \Joomla\Registry\Registry
     *
     * @since  2.3.0
     */
    protected $state;

    /**
     * The filter form.
     *
     * @var \Joomla\CMS\Form\Form
     *
     * @since  2.3.0
     */
    public $filterForm;

    /**
     * The active filters.
     *
     * @var array
     *
     * @since  2.3.0
     */
    public $activeFilters;

    /**
     * The names of the users the listed histories name, id => name.
     *
     * @var array<int, string>
     *
     * @since  2.3.0
     */
    public array $historyUsers = [];

    /**
     * The job the page follows (&job=N), as the progress card shows it, or null.
     *
     * @var array<string, mixed>|null
     *
     * @since  2.3.0
     */
    public ?array $job = null;

    /**
     * The job asked for, 0 for none.
     *
     * @since  2.3.0
     */
    public int $jobId = 0;

    /**
     * Whether &job named a job there is not.
     *
     * @since  2.3.0
     */
    public bool $jobMissing = false;

    /**
     * The actions registered for the background surface, as the toolbar runs them and as the notices above the list
     * read them.
     *
     * @var list<PluginAction>
     *
     * @since  2.3.0
     */
    public array $actions = [];

    /**
     * Display the screen.
     *
     * @param string|null $tpl The template.
     *
     * @throws NotAllowed When the user may not use Tools.
     *
     * @since  2.3.0
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // The outgoing notifications once were this screen's second tab: their old address leads to their own screen,
        // with the filters the address carried.
        if (RequestScalar::cmd($app->getInput(), 'tab') === 'outbound') {
            $filters = array_filter((array) $app->getInput()->get('filter', [], 'array'), 'is_scalar');
            $app->redirect(Route::_('index.php?option=com_alfa&view=outbound' . ($filters === [] ? '' : '&' . http_build_query(['filter' => $filters])), false));
        }

        $this->jobId = RequestScalar::int($app->getInput(), 'job');
        $model = $this->getModel();
        $this->followJob();

        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->historyUsers = $model->getHistoryUsers();
        $this->actions = ToolRegistry::forSurface(self::SURFACE);

        $this->addToolbar();

        $this->filterForm
            ->addControlField('task', '')
            ->addControlField('boxchecked', '0');

        parent::display($tpl);
    }

    /**
     * The job the page follows, read for its progress card; a job that cannot be read is logged and the page says
     * there is no such job.
     *
     * @since  2.3.0
     */
    private function followJob(): void
    {
        if ($this->jobId <= 0) {
            return;
        }

        try {
            $this->job = TaskStaff::job(id: $this->jobId);
        } catch (Throwable $e) {
            Tasks::log(entry: 'Background: job #' . $this->jobId . ' could not be read: ' . $e::class . ': ' . ErrorText::of($e), priority: Log::ERROR);
        }

        $this->jobMissing = $this->job === null;
    }

    /**
     * One split button for the background work's tools, each run by the Tools script with its progress and outcome
     * under the toolbar: its main part runs everything pending (tasks.pending); its list offers each kind on its own
     * (the tasks a save left, the stock movements, the list prices), any other registered tool, and after a divider the
     * clean-up of the completed work, which asks first. A tool the registry does not offer is left out.
     *
     * @param Toolbar $toolbar The toolbar.
     *
     * @since  2.3.0
     */
    private function addRunButton(Toolbar $toolbar): void
    {
        $actions = [];

        foreach ($this->actions as $action) {
            if ($action->isValid()) {
                $actions[$action->id] = $action;
            }
        }

        if (!isset($actions['tasks.pending'])) {
            return;
        }

        $group = $toolbar->dropdownButton('run-group')->toggleSplit(true)->toggleButtonClass('btn ' . $actions['tasks.pending']->class);
        $bar = $group->getChildToolbar();
        $button = function (PluginAction $action, string $label, string $confirm = '') use ($bar): void {
            $attributes = ToolButton::attributes(action: $action, progress: self::PROGRESS_ID);

            if ($confirm !== '') {
                $attributes['data-confirm-message'] = Text::_($confirm);
            }

            $bar->basicButton('tool-' . str_replace('.', '-', $action->id), $label)
                ->icon('icon-' . $action->icon)
                ->buttonClass('btn ' . $action->class . ' tool-btn')
                ->attributes($attributes);
        };

        // The main part, then each kind on its own, in the order the run takes them.
        $button($actions['tasks.pending'], Text::_('COM_ALFA_TASKS_RUN_PENDING'));
        $kinds = ['tasks.run' => 'COM_ALFA_TASKS_RUN_JOBS', 'inventory.effects' => 'COM_ALFA_TASKS_APPLY_STOCK', 'pricing.groupsets' => 'COM_ALFA_TASKS_UPDATE_PRICES'];

        foreach ($kinds as $id => $label) {
            if (isset($actions[$id])) {
                $button($actions[$id], Text::_($label));
            }
        }

        foreach ($actions as $id => $action) {
            if (!isset($kinds[$id]) && $id !== 'tasks.pending' && $id !== 'tasks.prune') {
                $button($action, ToolButton::label($action));
            }
        }

        if (isset($actions['tasks.prune'])) {
            $bar->separatorButton('run-divider');
            $button($actions['tasks.prune'], Text::_('COM_ALFA_TASKS_CLEANUP'), 'COM_ALFA_TASKS_CLEANUP_CONFIRM');
        }
    }

    /**
     * The toolbar: the background work's tools, then Delete for the finished entries checked.
     *
     * @since  2.3.0
     */
    protected function addToolbar(): void
    {
        $user = $this->getCurrentUser();
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_TASKS'), 'generic');

        $this->addRunButton($toolbar);

        $toolbar->delete('tasks.delete')
            ->message('COM_ALFA_TASKS_DELETE_CONFIRM')
            ->listCheck(true);

        // The outgoing notifications, listed on their own screen; its way back is this page.
        $toolbar->link(Text::_('COM_ALFA_OUTBOUND_CARD_TITLE'), AlfaHelper::withReturn('index.php?option=com_alfa&view=outbound'))
            ->icon('icon-out-2');

        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
