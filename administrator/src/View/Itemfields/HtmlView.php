<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Itemfields;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for the list of product item-field definitions.
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The admin-contract entity a plugin targets with $context->targets('itemfield').
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'itemfield';

    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    /**
     * Display the view
     *
     * @param string $tpl The name of the template file to parse.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function display($tpl = null)
    {
        $model = $this->getModel();

        $this->items = $model->getItems();

        // Per-field global ShowOn rule summaries for the list (server-side, no JS).
        $this->showonSummaries = ItemFieldsAdminHelper::getGlobalShowonSummaries($this->items ?? []);

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
     * @since  2.0.0
     */
    protected function addToolbar()
    {
        $user = $this->getCurrentUser();
        $canCreate = AlfaHelper::can(section: 'itemfields', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'itemfields', action: 'edit', user: $user);
        $canChange = AlfaHelper::can(section: 'itemfields', action: 'edit.state', user: $user);
        $canDelete = AlfaHelper::can(section: 'itemfields', action: 'delete', user: $user);
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ITEMFIELDS'), 'generic');

        if ($canCreate) {
            $toolbar->addNew('itemfield.add');
        }

        // State changes need edit.state; check-in is editing and needs edit.
        if ($canChange || $canEdit) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if ($canChange) {
                $childBar->publish('itemfields.publish')->listCheck(true);
                $childBar->unpublish('itemfields.unpublish')->listCheck(true);
                $childBar->archive('itemfields.archive')->listCheck(true);

                if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                    $childBar->trash('itemfields.trash')->listCheck(true);
                }
            }

            if ($canEdit) {
                $childBar->checkin('itemfields.checkin')->listCheck(true);
            }
        }

        if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDelete) {
            ToolbarHelper::deleteList('COM_ALFA_ITEMFIELD_DELETE_CONFIRM', 'itemfields.delete', 'JTOOLBAR_EMPTY_TRASH');
        }

        // Options are com_config's screen, which asks core.admin itself.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
