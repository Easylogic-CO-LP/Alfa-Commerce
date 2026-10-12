<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Orders;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a list of Orders.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'order';

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
        $model = $this->getModel();

        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->orderStatuses = AlfaHelper::getOrderStatuses();
        $this->filteredItemName = $model->getFilteredItemName();
        $this->unsatisfiableWaiting = $model->getUnsatisfiableWaitingLabel();

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
        $toolbar = $this->getDocument()->getToolbar();

        $canCreate = AlfaHelper::can(section: 'orders', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'orders', action: 'edit', user: $user);
        $canEditState = AlfaHelper::can(section: 'orders', action: 'edit.state', user: $user);
        $canDelete = AlfaHelper::can(section: 'orders', action: 'delete', user: $user);

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ORDERS'), 'generic');

        if ($canCreate) {
            $toolbar->addNew('order.add');
        }

        // Orders use a binary active/trashed view (the "show_trashed" filter),
        // so there is no publish/unpublish/archive, just trash, then permanent
        // delete from within the trashed view.
        $isTrashed = ((int) $this->state->get('filter.show_trashed') === 1);

        // The dropdown carries state changes (edit.state) and check-in (edit).
        if ($canEditState || $canEdit) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if ($canEditState) {
                if (!$isTrashed) {
                    $childBar->trash('orders.trash')->listCheck(true);
                } else {
                    // Restore a trashed order back to active (state -2 -> published).
                    $childBar->publish('orders.publish')->text('JTOOLBAR_UNTRASH')->listCheck(true);
                }
            }

            if ($canEdit) {
                $childBar->checkin('orders.checkin')->listCheck(true);
            }
        }

        // Emptying the trash is a delete and needs nothing else.
        if ($isTrashed && $canDelete) {
            $toolbar->delete('orders.delete')
                ->text('JTOOLBAR_EMPTY_TRASH')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        // Options opens com_config, which itself requires core.admin.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());
        \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::toolbarBadge($toolbar);
    }
}
