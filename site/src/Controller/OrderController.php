<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Site\Service\Order\CustomerCancel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * What a customer does with one of their orders from their account: each a POST with the form's token, by the customer
 * logged in, on their own order. The pages themselves are the orders and order views.
 *
 * @since  2.3.0
 */
class OrderController extends BaseController
{
    /**
     * Cancel the order, as its page offers it (CustomerCancel): the order's id and the status the page showed it in.
     * The answer is the order's page with the outcome's sentence; an order that is not the customer's, the list of
     * their orders.
     *
     * @since  2.3.0
     */
    public function cancel(): void
    {
        $this->checkToken();

        $orderId = RequestScalar::int($this->input, 'id', 0);

        if (!$this->loggedIn(orderId: $orderId)) {
            return;
        }

        $verdict = CustomerCancel::cancel(
            orderId: $orderId,
            customerId: (int) $this->app->getIdentity()->id,
            shownStatusId: RequestScalar::int($this->input, 'status', 0),
        );

        $this->app->enqueueMessage(CustomerCancel::sentence($verdict), $verdict === CustomerCancel::CANCELLED ? 'message' : 'warning');
        $this->setRedirect(Route::_(
            $verdict === CustomerCancel::NOT_AVAILABLE
                ? 'index.php?option=com_alfa&view=orders'
                : 'index.php?option=com_alfa&view=order&id=' . $orderId,
            false,
        ));
    }

    /**
     * Whether a customer is logged in; a visitor who is not is sent to log in, and back to the order's page after.
     *
     * @param int $orderId The order the request names.
     *
     * @since  2.3.0
     */
    private function loggedIn(int $orderId): bool
    {
        if (!$this->app->getIdentity()->guest) {
            return true;
        }

        $this->app->enqueueMessage(Text::_('JGLOBAL_YOU_MUST_LOGIN_FIRST'), 'notice');
        $this->setRedirect(Route::_(
            'index.php?option=com_users&view=login&return=' . base64_encode(Uri::root() . 'index.php?option=com_alfa&view=order&id=' . $orderId),
            false,
        ));

        return false;
    }
}
