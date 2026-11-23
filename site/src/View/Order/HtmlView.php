<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Site\Helper\PageCache;
use Alfa\Component\Alfa\Site\Helper\ShopMode;
use Alfa\Component\Alfa\Site\Service\Order\CustomerCancel;
use Alfa\Component\Alfa\Site\Service\Order\GatewayOfRecord;
use Alfa\Component\Alfa\Site\Service\Order\OrderConfirmation;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * One order of the customer logged in, from their account: the same order block as the order completed page
 * (layouts/order/detail.php, read by OrderConfirmation), with Pay now while it may be paid again and its gateway's
 * block, then what the customer may do with it. Only an order of their account; any other answers 404 with the page's
 * one line. A visitor who is not logged in is sent to log in and back here. Never stored, never indexed.
 *
 * @since  2.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The order; null when it is not the customer's.
     *
     * @since  2.3.0
     */
    protected ?OrderConfirmation $confirmation = null;

    /**
     * What the gateway drew on the order's page through orderComplete(): one surface descriptor; null when it drew
     * nothing or could not be asked.
     *
     * @since  2.3.0
     */
    protected ?array $gatewayBlock = null;

    /**
     * Whether the order's gateway of record could not be asked: the neutral line, no block.
     *
     * @since  2.3.0
     */
    protected bool $providerUnavailable = false;

    /**
     * The address of Pay now while the order may be paid again; '' otherwise.
     *
     * @since  2.3.0
     */
    protected string $payAgainUrl = '';

    /**
     * Whether the customer may cancel the order (CustomerCancel::verdict()), and if not why.
     *
     * @since  2.3.0
     */
    protected string $cancel = '';

    /**
     * Whether the page offers Order again: while the shop sells online, never in the print view.
     *
     * @since  2.3.0
     */
    protected bool $reorder = false;

    /**
     * The print view (tmpl=component and print=1), which shows the order alone.
     *
     * @since  2.3.0
     */
    protected bool $print = false;

    /**
     * Display the customer's order.
     *
     * @param string|null $tpl The template.
     *
     * @since  2.3.0
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // The customer's own page: never stored, never indexed.
        PageCache::excludeResponse();
        $app->allowCache(false);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->getDocument()->setMetaData('robots', 'noindex, nofollow');

        if ($app->getIdentity()->guest) {
            $app->enqueueMessage(Text::_('JGLOBAL_YOU_MUST_LOGIN_FIRST'), 'notice');
            $app->redirect(Route::_('index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString()), false));

            return;
        }

        $orderId = RequestScalar::int($input, 'id', 0);
        $this->confirmation = $this->getModel()->getOrder($orderId);

        if ($this->confirmation === null) {
            // Rendered, not thrown: the page's one line and status 404, nothing of the order.
            $app->setHeader('status', 404, true);
            $this->setDocumentTitle(Text::_('COM_ALFA_ORDER_NOT_AVAILABLE_HEADING'));

            parent::display($tpl);

            return;
        }

        $this->print = RequestScalar::bool($input, 'print', false);

        if (!$this->print) {
            $this->payAgainUrl = $this->confirmation->payAgainUrl();
            $this->cancel = CustomerCancel::verdict(orderId: $orderId, customerId: (int) $app->getIdentity()->id);
            $this->reorder = ShopMode::sellsOnline();
        }

        // The gateway's block, as the order completed page asks it: its gateway of record alone.
        ['event' => $event, 'unavailable' => $this->providerUnavailable, 'block' => $this->gatewayBlock] = GatewayOfRecord::completeView(order: $this->confirmation);

        if (!$this->providerUnavailable && $event->hasRedirect()) {
            $app->redirect($event->getRedirectUrl(), $event->getRedirectCode() ?? 303);

            return;
        }

        $this->setDocumentTitle(Text::sprintf('COM_ALFA_ORDER_HEADING', $this->confirmation->number));

        parent::display($tpl);
    }
}
