<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Outbound;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Throwable;

/**
 * The outgoing notifications: every change the shop owes or owed a consumer (a webhook, or an extension that tells
 * another system), as one list with its own search, filters, order and pages, the card of who is told above it with
 * the staff actions on each consumer, and Delete for the finished entries checked. At home with the webhooks: the way
 * back leads to the Webhooks list, or to the page the screen was opened from when that page said so (return).
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
    protected string $alfaEntity = 'delivery';

    /**
     * The rows of the page.
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
     * The card of who is told of the shop's changes ({@see OutboundConsumers::cardRows()}).
     *
     * @var array{rows: list<array<string, mixed>>, unreadable: bool}
     *
     * @since  2.3.0
     */
    public array $consumers = ['rows' => [], 'unreadable' => false];

    /**
     * Display the screen.
     *
     * @param string|null $tpl The template.
     *
     * @throws NotAllowed When the user may not use Tools.
     *
     * @since  2.3.0
     */
    public function display($tpl = null)
    {
        if (!Factory::getApplication()->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $model = $this->getModel();
        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->consumers = $this->consumers();

        $this->addToolbar();

        $this->filterForm
            ->addControlField('task', '')
            ->addControlField('boxchecked', '0');

        parent::display($tpl);
    }

    /**
     * The consumers of the shop's changes for the card of who is told; a table that cannot be read costs the card only.
     *
     * @return array{rows: list<array<string, mixed>>, unreadable: bool}
     *
     * @since  2.3.0
     */
    private function consumers(): array
    {
        try {
            return ['rows' => OutboundConsumers::cardRows(), 'unreadable' => false];
        } catch (Throwable $e) {
            Tasks::log(entry: 'Outgoing notifications: the consumers of the shop\'s changes could not be read: ' . $e::class . ': ' . ErrorText::of($e), priority: Log::ERROR);

            return ['rows' => [], 'unreadable' => true];
        }
    }

    /**
     * The toolbar: the way back first, then Delete for the finished entries checked.
     *
     * @since  2.3.0
     */
    protected function addToolbar(): void
    {
        $user = $this->getCurrentUser();
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_OUTBOUND'), 'generic');

        // The way back to the page this screen opened from (return, an internal address), else to the Webhooks list.
        $return = base64_decode((string) RequestScalar::read(Factory::getApplication()->getInput(), 'return', '', 'base64'), true);
        $back = \is_string($return) && $return !== '' && Uri::isInternal($return);
        $toolbar->linkButton('back')
            ->text($back ? 'JTOOLBAR_BACK' : 'COM_ALFA_TITLE_WEBHOOKS')
            ->url($back ? $return : Route::_('index.php?option=com_alfa&view=webhooks'))
            ->icon('icon-arrow-left');

        $toolbar->delete('outbound.delete')
            ->message('COM_ALFA_TASKS_DELETE_CONFIRM')
            ->listCheck(true);

        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
