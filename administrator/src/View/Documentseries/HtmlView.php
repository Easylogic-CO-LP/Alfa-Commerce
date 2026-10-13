<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Documentseries;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Document series list view: reached from the Documents toolbar, not the
 * component menu (one "Documents" menu entry covers the whole subsystem).
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The admin-contract entity of this grid.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'series';

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

        ToolbarHelper::title(Text::_('COM_ALFA_DOCUMENTSERIES_TITLE'), 'generic');

        if (AlfaHelper::can(section: 'documents', action: 'create', user: $user)) {
            $toolbar->addNew('series.add');
        }

        if (AlfaHelper::can(section: 'documents', action: 'edit.state', user: $user)) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();
            $childBar->publish('documentseries.publish')->listCheck(true);
            $childBar->unpublish('documentseries.unpublish')->listCheck(true);
        }

        if (AlfaHelper::can(section: 'documents', action: 'delete', user: $user)) {
            $toolbar->delete('documentseries.delete')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        $toolbar->linkButton('documents')
            ->text('COM_ALFA_DOCUMENTS_TITLE')
            ->url(Route::_('index.php?option=com_alfa&view=documents'))
            ->icon('icon-list');

        $toolbar->linkButton('documenttypes')
            ->text('COM_ALFA_DOCUMENTTYPES_TITLE')
            ->url(Route::_('index.php?option=com_alfa&view=documenttypes'))
            ->icon('icon-tags-2');

        // Options precedes the badge so the template right-aligns the group,
        // matching every other list view.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
