<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\MVC\Controller\ApiController;

/**
 * The payment methods controller.
 *
 * A payment method is a configuration object: a name, a colour, the
 * catalogue and customer scopes it is offered in. The booked payment rows
 * of an order are a different resource entirely, under
 * v1/alfa/orders/:id/payments.
 *
 * @since  1.0.0
 */
class PaymentmethodsController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type of the item.
     *
     * @var string
     * @since  1.0.0
     */
    protected $contentType = 'paymentmethods';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  1.0.0
     */
    protected $default_view = 'paymentmethods';

    /**
     * Gate this resource on the 'payments' section access.xml already declares.
     *
     *
     * @since  2.0.0
     */
    protected function aclSection(): string
    {
        return 'payments';
    }

    /**
     * Relations the admin model clears when the key is absent from a PATCH.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    protected function preserveOnPatch(): array
    {
        return [
            'categories',
            'manufacturers',
            'places',
            'users',
            'usergroups',
        ];
    }

    /**
     * Payment methods list view amended to add filtering of data
     *
     * @return static A BaseController object to support chaining.
     *
     * @since  2.0.0
     */
    public function displayList()
    {
        $apiFilterInfo = $this->input->get('filter', [], 'array');
        $filter = InputFilter::getInstance();

        if (\array_key_exists('search', $apiFilterInfo)) {
            $this->modelState->set('filter.search', $filter->clean($apiFilterInfo['search'], 'STRING'));
        }

        if (\array_key_exists('state', $apiFilterInfo)) {
            $this->modelState->set('filter.state', $filter->clean($apiFilterInfo['state'], 'INT'));
        }

        $apiListInfo = $this->input->get('list', [], 'array');

        if (\array_key_exists('ordering', $apiListInfo)) {
            $this->modelState->set('list.ordering', $filter->clean($apiListInfo['ordering'], 'STRING'));
        }

        if (\array_key_exists('direction', $apiListInfo)) {
            $this->modelState->set('list.direction', $filter->clean($apiListInfo['direction'], 'STRING'));
        }

        return parent::displayList();
    }
}
