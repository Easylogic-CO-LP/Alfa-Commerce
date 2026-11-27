<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Labels;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\HasAdminEvents;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for the list of the shop's labels.
 *
 * @since  2.3.0
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
     * @since  2.3.0
     */
    public function display($tpl = null)
    {
        // Assigned, not declared: the trait owns the property.
        $this->alfaEntity = 'label';

        $model = $this->getModel();

        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

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
     * @since  2.3.0
     */
    protected function addToolbar()
    {
        $user = $this->getCurrentUser();
        $canCreate = AlfaHelper::can(section: 'labels', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'labels', action: 'edit', user: $user);
        $canChange = AlfaHelper::can(section: 'labels', action: 'edit.state', user: $user);
        $canDelete = AlfaHelper::can(section: 'labels', action: 'delete', user: $user);
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_LABELS'), 'tags');

        if ($canCreate) {
            $toolbar->addNew('label.add');
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
                $childBar->publish('labels.publish')->listCheck(true);
                $childBar->unpublish('labels.unpublish')->listCheck(true);
                $childBar->archive('labels.archive')->listCheck(true);

                if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                    $childBar->trash('labels.trash')->listCheck(true);
                }
            }

            if ($canEdit) {
                $childBar->checkin('labels.checkin')->listCheck(true);
            }
        }

        // Emptying the trash is a delete, whatever the user may do to states.
        if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDelete) {
            $toolbar->delete('labels.delete')
                ->text('JTOOLBAR_EMPTY_TRASH')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        // Options are com_config's screen, which asks core.admin itself.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());
        NotificationHelper::toolbarBadge($toolbar);
    }
}
