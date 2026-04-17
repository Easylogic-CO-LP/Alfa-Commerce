<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\View\Usergroups;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

/**
 * View class for a list of Usergroups.
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

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_USERGROUPS'), 'users');

        //Add New Usergroup
        //        if ($canDo->get('core.create')) {
        //            $toolbar->addNew('usergroup.add');
        //        }

        //	    $toolbar->delete('usergroups.delete')
        //		    ->text('JTOOLBAR_EMPTY_TRASH')
        //		    ->message('JGLOBAL_CONFIRM_DELETE')
        //		    ->listCheck(true);

        //	    $toolbar->popupButton('new')
        //		    ->text('New')
        //		    ->icon('icon-plus')
        //		    ->url(Uri::root().'/administrator/index.php?option=com_users&view=group&layout=edit')
        //		    ->iframeWidth(800)
        //		    ->iframeHeight(500)
        //		    ->onclose('window.location.reload();');
        //
        //        if ($canDo->get('core.edit.state')) {
        //            $dropdown = $toolbar->dropdownButton('status-group')
        //                ->text('JTOOLBAR_CHANGE_STATUS')
        //                ->toggleSplit(false)
        //                ->icon('fas fa-ellipsis-h')
        //                ->buttonClass('btn btn-action')
        //                ->listCheck(false);
        //
        //            $childBar = $dropdown->getChildToolbar();
        //
        //            $childBar->publish('usergroups.publish')->listCheck(true);
        //            $childBar->unpublish('usergroups.unpublish')->listCheck(true);
        //            $childBar->archive('usergroups.archive')->listCheck(true);
        //
        //            if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
        //                $childBar->trash('usergroups.trash')->listCheck(true);
        //            }
        //
        //            if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDo->get('core.delete')) {
        //                // If this component does not use state then show a direct delete button as we can not trash
        //                $toolbar->delete('usergroups.delete')
        //                ->text('JTOOLBAR_EMPTY_TRASH')
        //                ->message('JGLOBAL_CONFIRM_DELETE')
        //                ->listCheck(true);
        //            }
        //
        //            $childBar->checkin('usergroups.checkin')->listCheck(true);
        //        }

        if ($canDo->get('core.admin')) {
            $toolbar->preferences('com_alfa');
        }
    }
}
