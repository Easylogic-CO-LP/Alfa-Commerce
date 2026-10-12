<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Formfields;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ShowonSummary;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a list of Items.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The entity a plugin targets with $context->targets('formfield').
     * @since  1.8.0
     */
    protected string $alfaEntity = 'formfield';

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

        // Per-field global ShowOn rule summaries for the list (server-side, no JS);
        // form-field compare values are entered by hand, so the shared renderer
        // prints them as-is (no value pool).
        $this->showonSummaries = ShowonSummary::globalSummaries($this->items ?? []);

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
        $canCreate = AlfaHelper::can(section: 'formfields', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'formfields', action: 'edit', user: $user);
        $canChange = AlfaHelper::can(section: 'formfields', action: 'edit.state', user: $user);
        $canDelete = AlfaHelper::can(section: 'formfields', action: 'delete', user: $user);
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_FORMFIELDS'), 'generic');

        if ($canCreate) {
            $toolbar->addNew('formfield.add');
        }

        // Groups are managed from inside the Fields area.
        $toolbar->linkButton('groups', Text::_('COM_ALFA_TOOLBAR_MANAGE_GROUPS'))
            ->url('index.php?option=com_alfa&view=formfieldgroups')
            ->icon('icon-folder');

        if ($canChange || $canEdit) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if ($canChange) {
                $childBar->publish('formfields.publish')->listCheck(true);
                $childBar->unpublish('formfields.unpublish')->listCheck(true);
                $childBar->archive('formfields.archive')->listCheck(true);

                if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                    $childBar->trash('formfields.trash')->listCheck(true);
                }
            }

            // Batch regroups the selected fields and check-in releases their locks: both edit them.
            if ($canEdit) {
                $childBar->popupButton('batch', 'JTOOLBAR_BATCH')
                    ->popupType('inline')
                    ->textHeader(Text::_('COM_ALFA_BATCH_OPTIONS'))
                    ->url('#joomla-dialog-batch')
                    ->modalWidth('800px')
                    ->modalHeight('fit-content')
                    ->listCheck(true);

                $childBar->checkin('formfields.checkin')->listCheck(true);
            }
        }

        if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDelete) {
            // Permanent delete: drops matching #__alfa_user_info columns.
            // Use a popup (instead of the default confirm dialog) so we can show
            // a properly styled warning with a red "Delete Permanently" button.
            $toolbar->popupButton('delete-confirm', 'COM_ALFA_FORMFIELD_DELETE_PERMANENT')
                ->popupType('inline')
                ->icon('icon-warning')
                ->buttonClass('btn btn-danger')
                ->textHeader(Text::_('COM_ALFA_FORMFIELD_DELETE_PERMANENT_HEADER'))
                ->url('#joomla-dialog-delete')
                ->modalWidth('600px')
                ->modalHeight('fit-content')
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
