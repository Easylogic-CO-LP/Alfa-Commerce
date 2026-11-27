<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Webhookdeliveries;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;

/**
 * One webhook's deliveries: the list the Deliveries tab of the webhook's edit screen shows in a frame of its own
 * (tmpl=component, modal=1), because Joomla's filters and pagination post the one form named adminForm, and in the
 * edit screen that form is the webhook's. No toolbar: the frame has none.
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
    protected string $alfaEntity = 'webhookdelivery';

    /**
     * The deliveries.
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
     * The webhook whose deliveries are listed.
     *
     * @since  2.3.0
     */
    protected int $webhookId = 0;

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
        $this->webhookId = (int) $this->state->get('webhook.id', 0);

        // Every page link names the webhook, as the form the filters post does.
        $this->pagination->setAdditionalUrlParam('webhook_id', $this->webhookId);

        $this->filterForm
            ->addControlField('task', '')
            ->addControlField('boxchecked', '0');

        parent::display($tpl);
    }
}
