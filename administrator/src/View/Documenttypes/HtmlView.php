<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Documenttypes;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Service\DocumentTypes\DocumentTypeRegistry;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Document types list view: the registry's merchant surface. No "New"
 * button by design: rows come from the installer or package providers;
 * merchants curate presentation and visibility only.
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The admin-contract entity this grid serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'documenttype';

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

        ToolbarHelper::title(Text::_('COM_ALFA_DOCUMENTTYPES_TITLE'), 'generic');

        // Syncing writes type rows, the same permission as documenttypes.syncProviders.
        if (AlfaHelper::can(section: 'documents', action: 'edit', user: $user)) {
            // Registration is deliberately operator-driven (types are DATA that outlives its
            // provider), so the screen owes the operator the hint: when a package is installed
            // but never synced (or was upgraded since), the button says how many are waiting.
            $pending = (new DocumentTypeRegistry())->pendingProviders();
            $label = $pending === []
                ? Text::_('COM_ALFA_DOCUMENTTYPES_SYNC_BUTTON')
                : Text::_('COM_ALFA_DOCUMENTTYPES_SYNC_BUTTON')
                    . ' <span class="badge bg-warning text-dark">' . \count($pending) . '</span>';

            $button = $toolbar->standardButton('sync-providers', $label, 'documenttypes.syncProviders')
                ->icon('icon-refresh')
                ->listCheck(false);

            if ($pending !== []) {
                $button->buttonClass('btn btn-warning');
            }
        }

        if (AlfaHelper::can(section: 'documents', action: 'edit.state', user: $user)) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();
            $childBar->publish('documenttypes.publish')->listCheck(true);
            $childBar->unpublish('documenttypes.unpublish')->listCheck(true);
        }

        if (AlfaHelper::can(section: 'documents', action: 'delete', user: $user)) {
            $toolbar->delete('documenttypes.delete')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        $toolbar->linkButton('documents')
            ->text('COM_ALFA_DOCUMENTS_TITLE')
            ->url(Route::_('index.php?option=com_alfa&view=documents'))
            ->icon('icon-list');

        $toolbar->linkButton('documentseries')
            ->text('COM_ALFA_DOCUMENTSERIES_TITLE')
            ->url(Route::_('index.php?option=com_alfa&view=documentseries'))
            ->icon('icon-cog');

        // Options precedes the badge so the template right-aligns the group,
        // matching every other list view.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
