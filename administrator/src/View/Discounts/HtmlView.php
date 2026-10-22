<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Discounts;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a list of Discounts.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The entity a plugin targets with $context->targets('discount').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'discount';

    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    /**
     * The currency a discount's fixed amount is shown in: the shop's default. A discount
     * stores a bare per-unit number; the price engine takes it in the visitor's currency,
     * which is the shop's default unless the session names another (the REST ?currency=).
     *
     * @since  2.1.0
     */
    public Currency $amountCurrency;

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
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->amountCurrency = Currency::getDefault();

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
        $canCreate = AlfaHelper::can(section: 'discounts', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'discounts', action: 'edit', user: $user);
        $canChange = AlfaHelper::can(section: 'discounts', action: 'edit.state', user: $user);
        $canDelete = AlfaHelper::can(section: 'discounts', action: 'delete', user: $user);
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_DISCOUNTS'), 'generic');

        if ($canCreate) {
            $toolbar->addNew('discount.add');
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
                $childBar->publish('discounts.publish')->listCheck(true);
                $childBar->unpublish('discounts.unpublish')->listCheck(true);
                $childBar->archive('discounts.archive')->listCheck(true);

                if ($this->state->get('filter.state') != AlfaComponent::CONDITION_TRASHED) {
                    $childBar->trash('discounts.trash')->listCheck(true);
                }
            }

            if ($canEdit) {
                $childBar->checkin('discounts.checkin')->listCheck(true);
            }
        }

        // Emptying the trash is a delete: it needs the delete permission alone, not edit.state.
        if ($this->state->get('filter.state') == AlfaComponent::CONDITION_TRASHED && $canDelete) {
            $toolbar->delete('discounts.delete')
            ->text('JTOOLBAR_EMPTY_TRASH')
            ->message('JGLOBAL_CONFIRM_DELETE')
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
