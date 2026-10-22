<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Cartrules;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Cart rules list view.
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The entity a plugin targets with $context->targets('cartrule').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'cartrule';

    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    /**
     * The currency a rule's fixed amount is shown in: the shop's default. A rule stores
     * a bare number; the engine takes it in the cart's currency, and a cart opens in
     * the shop's default unless its session names another (the REST ?currency=).
     *
     * @since  2.1.0
     */
    public Currency $amountCurrency;

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
        $this->amountCurrency = Currency::getDefault();

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
        $canEdit = AlfaHelper::can(section: 'cartrules', action: 'edit', user: $user);
        $canChange = AlfaHelper::can(section: 'cartrules', action: 'edit.state', user: $user);
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_CARTRULES'), 'generic');

        if (AlfaHelper::can(section: 'cartrules', action: 'create', user: $user)) {
            $toolbar->addNew('cartrule.add');
        }

        // Check-in is part of editing, so the status menu opens for either permission.
        if ($canChange || $canEdit) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if ($canChange) {
                $childBar->publish('cartrules.publish')->listCheck(true);
                $childBar->unpublish('cartrules.unpublish')->listCheck(true);
            }

            if ($canEdit) {
                $childBar->checkin('cartrules.checkin')->listCheck(true);
            }
        }

        if (AlfaHelper::can(section: 'cartrules', action: 'delete', user: $user)) {
            $toolbar->delete('cartrules.delete')
                ->text('JTOOLBAR_DELETE')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        // Options opens com_config, which itself requires core.admin.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());
    }
}
