<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Locations;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Inventory locations list view.
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'location';

    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    /**
     * Display the view.
     *
     * @param string $tpl Template name.
     *
     * @return void
     *
     * @since  2.0.0
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
     * Add the page title and toolbar.
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function addToolbar()
    {
        $user = $this->getCurrentUser();
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_LOCATIONS_TITLE'), 'generic');

        if (AlfaHelper::can(section: 'locations', action: 'create', user: $user)) {
            $toolbar->addNew('location.add');
        }

        if (AlfaHelper::can(section: 'locations', action: 'edit.state', user: $user)) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();
            // A location is enabled or disabled (the location form's words), not published.
            $childBar->publish('locations.publish', 'COM_ALFA_LOCATIONS_TOOLBAR_ENABLE')->listCheck(true);
            $childBar->unpublish('locations.unpublish', 'COM_ALFA_LOCATIONS_TOOLBAR_DISABLE')->listCheck(true);
        }

        if (AlfaHelper::can(section: 'locations', action: 'delete', user: $user)) {
            $toolbar->delete('locations.delete')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        // Options opens com_config, which itself requires core.admin.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
