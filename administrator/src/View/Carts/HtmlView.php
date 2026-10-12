<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Carts;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRetention;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\User\User;
use Throwable;

/**
 * Carts: the shopping in progress, and what became of the rest.
 *
 * Read-only in this slice, and the toolbar says so by carrying no action: a
 * cart is the shopper's own working document. Reaching into one is a later
 * step with its own permission and its own audit trail.
 *
 * @since  1.10.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The entity a plugin targets with $context->targets('cart').
     * @since  1.10.0
     */
    protected string $alfaEntity = 'cart';

    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    /**
     * @var array{active: int, abandoned: int, empty: int} The three counts above the list.
     * @since  1.10.0
     */
    protected array $summary = ['active' => 0, 'abandoned' => 0, 'empty' => 0];

    /**
     * @var int Hours of silence after which a cart with lines reads as abandoned.
     * @since  1.10.0
     */
    protected int $abandonedAfterHours = 24;

    /**
     * @var string The instant THIS request measures abandonment from: the same
     *             one the tiles were counted with and the list was filtered by,
     *             so a row's badge cannot disagree with either.
     * @since  1.10.0
     */
    protected string $abandonedCutoff = '';

    /**
     * Display the view.
     *
     * @param string $tpl Template name.
     *
     * @return void
     *
     * @since  1.10.0
     */
    /**
     * @var bool May this operator start a cart on a customer's behalf?
     * @since  1.10.0
     */
    protected bool $canBuild = false;

    /**
     * Whether the toolbar offers the purge: the carts delete permission, since it
     * deletes carts.
     *
     *
     * @since  1.10.0
     */
    protected bool $canPurge = false;

    /**
     * The retention windows in force, for the dialog to say what will go.
     *
     * @var array{empty: int, lines: int, floor: int}
     *
     * @since  1.10.0
     */
    protected array $retentionWindows = ['empty' => 0, 'lines' => 0, 'floor' => 0];

    /**
     * @var \Joomla\CMS\Form\Form|null The customer picker that starts one.
     * @since  1.10.0
     */
    protected $newCartForm = null;

    public function display($tpl = null)
    {
        $model = $this->getModel();

        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->summary = $model->getSummary();
        $this->abandonedAfterHours = $model::ABANDONED_AFTER_HOURS;
        $this->abandonedCutoff = $model->getAbandonedCutoff();

        // THE ONE MOMENT A CART'S CUSTOMER IS CHOSEN. The picker is core
        // Joomla's user field, the same one the Order screen declares, and, as
        // there, the choice is made when the record is minted and never after.
        $this->canBuild = AlfaHelper::can(
            section: 'carts',
            action: 'checkout',
            user: Factory::getApplication()->getIdentity() ?? new User(0),
        );
        $this->newCartForm = $this->canBuild ? $this->newCartForm() : null;

        $this->canPurge = AlfaHelper::can(section: 'carts', action: 'delete', user: $this->getCurrentUser());
        $this->retentionWindows = (new CartRetention())->windows();

        $this->addToolbar();

        $this->filterForm
            ->addControlField('task', '')
            ->addControlField('boxchecked', '0');

        parent::display($tpl);
    }

    /**
     * The one-field form that starts a cart for a customer.
     *
     * No XML of its own beyond the field: the picker is core Joomla's user
     * field, declared exactly as forms/order.xml declares the order's customer,
     * and named for the one endpoint allowed to read a customer from a request.
     *
     * @return \Joomla\CMS\Form\Form|null
     *
     * @since  1.10.0
     */
    protected function newCartForm()
    {
        try {
            return Form::getInstance(
                'com_alfa.cart_new',
                JPATH_ADMINISTRATOR . '/components/com_alfa/forms/cart_new.xml',
                ['file' => true],
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Add the page title and toolbar.
     *
     * @return void
     *
     * @since  1.10.0
     */
    protected function addToolbar()
    {
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_CARTS_TITLE'), 'cart');

        // Offered only to somebody who could then work the cart: the endpoint
        // asks the same permission again, and a control leading to a refusal is
        // worse than no control.
        if ($this->canBuild) {
            $toolbar->popupButton('newBuilderCart', 'COM_ALFA_CART_BUILDER_NEW')
                ->selector('newBuilderCartModal')
                ->icon('icon-new')
                ->listCheck(false);
        }

        // THE END OF THE CART LIFECYCLE, asked for by hand and only by hand:
        // the dialog says what will go and how old it must be, and the task
        // behind it runs one bounded pass. Offered to whoever may delete carts,
        // because that is what it does.
        if ($this->canPurge) {
            $toolbar->popupButton('purge-confirm', 'COM_ALFA_CARTS_PURGE')
                ->popupType('inline')
                ->icon('icon-trash')
                ->buttonClass('btn btn-danger')
                ->textHeader(Text::_('COM_ALFA_CARTS_PURGE_HEADER'))
                ->url('#joomla-dialog-purge')
                ->modalWidth('600px')
                ->modalHeight('fit-content')
                ->listCheck(false);
        }

        // Options opens com_config, which itself requires core.admin.
        if ($this->getCurrentUser()->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
