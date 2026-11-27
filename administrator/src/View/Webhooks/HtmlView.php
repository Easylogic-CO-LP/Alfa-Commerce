<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Webhooks;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * The Webhooks list.
 *
 * @since  2.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'webhook';

    /**
     * The webhooks.
     *
     * @var array
     *
     * @since  2.3.0
     */
    protected $items;

    /**
     * The pagination.
     *
     * @var \Joomla\CMS\Pagination\Pagination
     *
     * @since  2.3.0
     */
    protected $pagination;

    /**
     * The model's state.
     *
     * @var \Joomla\Registry\Registry
     *
     * @since  2.3.0
     */
    protected $state;

    /**
     * The filter form.
     *
     * @var \Joomla\CMS\Form\Form
     *
     * @since  2.3.0
     */
    public $filterForm;

    /**
     * The active filters.
     *
     * @var array
     *
     * @since  2.3.0
     */
    public $activeFilters;

    /**
     * Display the list.
     *
     * @param string|null $tpl The template.
     *
     * @since  2.3.0
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
     * The toolbar.
     *
     * @since  2.3.0
     */
    protected function addToolbar()
    {
        $user = $this->getCurrentUser();
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_WEBHOOKS_TITLE'), 'generic');

        if (AlfaHelper::can(section: 'webhooks', action: 'create', user: $user)) {
            $toolbar->addNew('webhook.add');
        }

        if (AlfaHelper::can(section: 'webhooks', action: 'edit.state', user: $user)) {
            $dropdown = $toolbar->dropdownButton('status-group')
                ->text('JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('fas fa-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();
            $childBar->publish('webhooks.publish', 'COM_ALFA_WEBHOOKS_TOOLBAR_ENABLE')->listCheck(true);
            $childBar->unpublish('webhooks.unpublish', 'COM_ALFA_WEBHOOKS_TOOLBAR_PAUSE')->listCheck(true);
        }

        if (AlfaHelper::can(section: 'webhooks', action: 'delete', user: $user)) {
            $toolbar->delete('webhooks.delete')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        // The endpoints a webhook's consumer calls back, and who may call them: the API routes page, for whoever may
        // open Tools.
        if ($user->authorise('alfa.tools', 'com_alfa')) {
            $toolbar->link(Text::_('COM_ALFA_TITLE_APIROUTES'), 'index.php?option=com_alfa&view=apiroutes')
                ->icon('icon-code');
        }

        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
