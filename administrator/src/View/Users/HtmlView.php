<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\View\Users;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a list of Users.
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

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_USERS'), 'person');

        //Add new User Button
        //        if ($canDo->get('core.create')) {
        //            $toolbar->addNew('user.add');
        //        }

        if ($canDo->get('core.admin')) {
            $toolbar->preferences('com_alfa');
        }
    }
}
