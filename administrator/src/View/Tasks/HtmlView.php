<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Tasks;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Helper\ToolButton;
use Alfa\Component\Alfa\Administrator\Helper\ToolRegistry;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Model\OutboundModel;
use Alfa\Component\Alfa\Administrator\Model\TasksModel;
use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Throwable;

/**
 * The Background screen: the shop's background work in two tabs, each a list with its own filters, order and pages.
 * The Work tab lists every task and job in its state of work (running now, waiting, needs attention, finished), with
 * what staff may do with each, and follows one job's progress when asked (&job=N). The Outgoing notifications tab
 * lists every change owed to a webhook or an extension, with the card of who is told. The toolbar runs the
 * background work's tools (the actions registered for the background surface) and deletes the finished entries
 * checked. One tab is listed at a time, the one the form's tab field names.
 *
 * @since  2.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The Work tab.
     *
     * @since  2.3.0
     */
    public const TAB_WORK = 'work';

    /**
     * The Outgoing notifications tab.
     *
     * @since  2.3.0
     */
    public const TAB_OUTBOUND = 'outbound';

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
    public const PROGRESS_ID = 'alfa-background-progress';

    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'task';

    /**
     * The tab listed: self::TAB_WORK or self::TAB_OUTBOUND.
     *
     * @since  2.3.0
     */
    public string $tab = self::TAB_WORK;

    /**
     * The rows of the tab listed.
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
     * The card of who is told of the shop's changes ({@see OutboundConsumers::cardRows()}), on the Outgoing
     * notifications tab.
     *
     * @var array{rows: list<array<string, mixed>>, unreadable: bool}
     *
     * @since  2.3.0
     */
    public array $consumers = ['rows' => [], 'unreadable' => false];

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

        // A job to follow opens the Work tab, whatever tab was listed last.
        $this->jobId = RequestScalar::int($app->getInput(), 'job');
        $tab = (string) $app->getUserStateFromRequest('com_alfa.tasks.tab', 'tab', self::TAB_WORK, 'cmd');
        $this->tab = $this->jobId > 0 || $tab !== self::TAB_OUTBOUND ? self::TAB_WORK : self::TAB_OUTBOUND;

        if ($this->tab === self::TAB_OUTBOUND) {
            // The tab's own model stands as the view's default, so nothing reads the Work tab's list for this request.
            $model = $app->bootComponent('com_alfa')->getMVCFactory()->createModel('Outbound', 'Administrator');
            $this->setModel($model, true);
            $this->consumers = $this->consumers();
        } else {
            $model = $this->getModel();
            $this->followJob();
        }

        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->historyUsers = $model instanceof TasksModel ? $model->getHistoryUsers() : [];
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
     * The consumers of the shop's changes for the card of who is told; a table that cannot be read costs the card
     * only.
     *
     * @return array{rows: list<array<string, mixed>>, unreadable: bool}
     *
     * @since  2.3.0
     */
    private function consumers(): array
    {
        try {
            return ['rows' => OutboundConsumers::cardRows(), 'unreadable' => false];
        } catch (Throwable $e) {
            Tasks::log(entry: 'Background: the consumers of the shop\'s changes could not be read: ' . $e::class . ': ' . ErrorText::of($e), priority: Log::ERROR);

            return ['rows' => [], 'unreadable' => true];
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

        // Each registered tool as a button the Tools script runs; its progress and outcome show under the toolbar.
        foreach ($this->actions as $action) {
            if (!$action->isValid()) {
                continue;
            }

            $toolbar->basicButton('alfa-tool-' . str_replace('.', '-', $action->id), ToolButton::label($action))
                ->icon('icon-' . $action->icon)
                ->buttonClass('btn ' . $action->class . ' alfa-tool-btn')
                ->attributes(ToolButton::attributes(action: $action, progress: self::PROGRESS_ID));
        }

        $toolbar->delete($this->tab === self::TAB_OUTBOUND ? 'outbound.delete' : 'tasks.delete')
            ->message('COM_ALFA_TASKS_DELETE_CONFIRM')
            ->listCheck(true);

        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
