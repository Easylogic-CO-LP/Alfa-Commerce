<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Formfieldgroups;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $items;
    protected $pagination;
    protected $state;
    public $filterForm;
    public $activeFilters;

    /**
     * Render the form-field groups list: load items, pagination, state and the
     * filter form from the model, build the toolbar, then delegate to the parent.
     *
     * @param   string|null  $tpl  The name of the template file to parse
     *
     * @return  void
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

        $this->filterForm
            ->addControlField('task', '')
            ->addControlField('boxchecked', '0');

        parent::display($tpl);
    }

    /**
     * Build the list toolbar: title, New button, a Change-Status dropdown
     * (publish/unpublish/trash/checkin or empty-trash when viewing trashed),
     * a Back-to-Fields link and the notification badge, gated by permissions.
     *
     * @return  void
     */
    protected function addToolbar()
    {
        $canDo = ContentHelper::getActions('com_alfa');
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_FORMFIELDGROUPS'), 'generic');

        if ($canDo->get('core.create')) {
            $toolbar->addNew('formfieldgroup.add');
        }

        if ($canDo->get('core.edit.state')) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();
            $childBar->publish('formfieldgroups.publish')->listCheck(true);
            $childBar->unpublish('formfieldgroups.unpublish')->listCheck(true);

            if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                $childBar->trash('formfieldgroups.trash')->listCheck(true);
            }

            if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDo->get('core.delete')) {
                // Trashed view → hard-delete selected rows.
                $toolbar->delete('formfieldgroups.delete')
                    ->text('JTOOLBAR_EMPTY_TRASH')
                    ->message('COM_ALFA_FORMFIELDGROUP_DELETE_CONFIRM')
                    ->listCheck(true);
            }

            $childBar->checkin('formfieldgroups.checkin')->listCheck(true);
        }

        // Back to the Fields list — groups are managed from inside the Fields admin area.
        $toolbar->linkButton('back', Text::_('COM_ALFA_TOOLBAR_BACK_TO_FORMFIELDS'))
            ->url('index.php?option=com_alfa&view=formfields')
            ->icon('icon-arrow-left');
        \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::toolbarBadge($toolbar);
    }
}
