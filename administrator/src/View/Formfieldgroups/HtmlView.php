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
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    /**
     * @var string The entity a plugin targets with $context->targets('formfieldgroup').
     * @since  1.8.0
     */
    protected string $alfaEntity = 'formfieldgroup';
    protected $items;
    protected $pagination;
    protected $state;
    public $filterForm;
    public $activeFilters;

    /**
     * Render the form-field groups list: load items, pagination, state and the
     * filter form from the model, build the toolbar, then delegate to the parent.
     *
     * @param string|null $tpl The name of the template file to parse
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
     * @return void
     * @since  1.0.0
     */
    protected function addToolbar()
    {
        // Groups are part of the Fields area, so the formfields permissions govern them.
        $user = $this->getCurrentUser();
        $canCreate = AlfaHelper::can(section: 'formfieldgroups', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'formfieldgroups', action: 'edit', user: $user);
        $canChange = AlfaHelper::can(section: 'formfieldgroups', action: 'edit.state', user: $user);
        $canDelete = AlfaHelper::can(section: 'formfieldgroups', action: 'delete', user: $user);
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_FORMFIELDGROUPS'), 'generic');

        if ($canCreate) {
            $toolbar->addNew('formfieldgroup.add');
        }

        if ($canChange || $canEdit) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if ($canChange) {
                $childBar->publish('formfieldgroups.publish')->listCheck(true);
                $childBar->unpublish('formfieldgroups.unpublish')->listCheck(true);

                if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                    $childBar->trash('formfieldgroups.trash')->listCheck(true);
                }
            }

            // Releasing edit locks is part of editing.
            if ($canEdit) {
                $childBar->checkin('formfieldgroups.checkin')->listCheck(true);
            }
        }

        if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDelete) {
            // Trashed view -> hard-delete selected rows.
            $toolbar->delete('formfieldgroups.delete')
                ->text('JTOOLBAR_EMPTY_TRASH')
                ->message('COM_ALFA_FORMFIELDGROUP_DELETE_CONFIRM')
                ->listCheck(true);
        }

        // Back to the Fields list: groups are managed from inside the Fields admin area.
        $toolbar->linkButton('back', Text::_('COM_ALFA_TOOLBAR_BACK_TO_FORMFIELDS'))
            ->url('index.php?option=com_alfa&view=formfields')
            ->icon('icon-arrow-left');
        TourHelper::toolbarHelp($toolbar, $this->getName());
        \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::toolbarBadge($toolbar);
    }
}
