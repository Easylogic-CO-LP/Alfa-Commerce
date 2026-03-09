<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2025 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\View\Formfields;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a list of Items.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
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
     * @since   1.0.1
     */
    protected function addToolbar()
    {
        $canDo = ContentHelper::getActions('com_alfa');
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_FORMFIELDS'), 'generic');

        if ($canDo->get('core.create')) {
            $toolbar->addNew('formfield.add');
        }

        if ($canDo->get('core.edit.state')) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            $childBar->publish('formfields.publish')->listCheck(true);
            $childBar->unpublish('formfields.unpublish')->listCheck(true);
            $childBar->archive('formfields.archive')->listCheck(true);

            if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                $childBar->trash('formfields.trash')->listCheck(true);
            }

            if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDo->get('core.delete')) {
                // If this component does not use state then show a direct delete button as we can not trash
                $toolbar->delete('formfields.delete')
                    ->text('JTOOLBAR_EMPTY_TRASH')
                    ->message('JGLOBAL_CONFIRM_DELETE')
                    ->listCheck(true);
            }

            $childBar->popupButton('batch', 'JTOOLBAR_BATCH')
                ->popupType('inline')
                ->textHeader(Text::_('COM_CONTENT_BATCH_OPTIONS'))
                ->url('#joomla-dialog-batch')
                ->modalWidth('800px')
                ->modalHeight('fit-content')
                ->listCheck(true);

            $childBar->checkin('formfields.checkin')->listCheck(true);
        }

        if ($canDo->get('core.admin')) {
            $toolbar->preferences('com_alfa');
        }
    }
}
