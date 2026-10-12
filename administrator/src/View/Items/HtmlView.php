<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Items;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\HasAdminEvents;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a list of Items.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    use HasAdminEvents;

    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    /**
     * Display the view
     *
     * @param string $tpl The name of the template file to parse; automatically searches through the template paths.
     *
     * @return void
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        // Assigned, not declared: the trait owns the property (see the Order view).
        $this->alfaEntity = 'item';

        $model = $this->getModel();

        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

        // Add form control fields
        $this->filterForm
            ->addControlField('task', '')
            ->addControlField('boxchecked', '0');

        parent::display($tpl);
    }

    /**
     * Add the page title and toolbar.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function addToolbar()
    {
        $user = $this->getCurrentUser();
        $canCreate = AlfaHelper::can(section: 'items', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'items', action: 'edit', user: $user);
        $canChange = AlfaHelper::can(section: 'items', action: 'edit.state', user: $user);
        $canDelete = AlfaHelper::can(section: 'items', action: 'delete', user: $user);
        $toolbar = $this->getDocument()->getToolbar();

        // Bringing an archived product back is a lifecycle transition, not a state change: it is
        // offered on the Archived filter to those the task itself accepts (core.admin).
        $filterState = $this->state->get(path: 'filter.state');
        $canRestore = is_numeric($filterState) && (int) $filterState === AlfaComponent::CONDITION_ARCHIVED
            && $user->authorise(action: 'core.admin', assetname: 'com_alfa');

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ITEMS'), 'generic');

        if ($canCreate) {
            $toolbar->addNew('item.add');
        }

        // State changes need edit.state; batch and check-in are editing and need edit; the restore
        // needs core.admin.
        if ($canChange || $canEdit || $canRestore) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if ($canChange) {
                $childBar->publish('items.publish')->listCheck(true);
                $childBar->unpublish('items.unpublish')->listCheck(true);
                $childBar->archive('items.archive')->listCheck(true);

                if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                    $childBar->trash('items.trash')->listCheck(true);
                }
            }

            if ($canRestore) {
                $childBar->unarchive(task: 'items.restoreArchived', text: 'COM_ALFA_TOOLBAR_RESTORE_TO_CATALOGUE')->listCheck(true);
            }

            if ($canEdit) {
                $childBar->popupButton('batch', 'JTOOLBAR_BATCH')
                    ->popupType('inline')
                    ->textHeader(Text::_('COM_ALFA_BATCH_OPTIONS'))
                    ->url('#joomla-dialog-batch')
                    ->modalWidth('800px')
                    ->modalHeight('fit-content')
                    ->listCheck(true);

                $childBar->checkin('items.checkin')->listCheck(true);
            }
        }

        // Emptying the trash is a delete, whatever the user may do to states.
        if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDelete) {
            $toolbar->delete('items.delete')
                ->text('JTOOLBAR_EMPTY_TRASH')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        // Options are com_config's screen, which asks core.admin itself; the inventory audit and
        // its repair actions answer to core.admin only.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->linkButton('inventory-audit')
                ->text('COM_ALFA_TOOLBAR_INVENTORY_AUDIT')
                ->url(Route::_(url: 'index.php?option=com_alfa&view=inventoryaudit'))
                ->icon('icon-search');

            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());
        \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::toolbarBadge($toolbar);
    }
}
