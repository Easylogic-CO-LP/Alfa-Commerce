<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Places;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a list of Places.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The entity a plugin targets with $context->targets('place').
     * @since  1.8.0
     */
    protected string $alfaEntity = 'place';
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
        $canCreate = AlfaHelper::can(section: 'places', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'places', action: 'edit', user: $user);
        $canChange = AlfaHelper::can(section: 'places', action: 'edit.state', user: $user);
        $canDelete = AlfaHelper::can(section: 'places', action: 'delete', user: $user);
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_PLACES'), 'generic');

        if ($canCreate) {
            $toolbar->addNew('place.add');
        }

        // Check-in is part of editing, so the status menu opens for either permission.
        if ($canChange || $canEdit) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if ($canChange) {
                $childBar->publish('places.publish')->listCheck(true);
                $childBar->unpublish('places.unpublish')->listCheck(true);
                $childBar->archive('places.archive')->listCheck(true);

                if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                    $childBar->trash('places.trash')->listCheck(true);
                }
            }

            if ($canEdit) {
                $childBar->checkin('places.checkin')->listCheck(true);
            }
        }

        // Emptying the trash is a delete: it needs the delete permission alone, not edit.state.
        if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDelete) {
            $toolbar->delete('places.delete')
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
