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
use Alfa\Component\Alfa\Administrator\Service\Document\PdfLibraryMissingException;
use Alfa\Component\Alfa\Administrator\Service\Document\PdfRenderer;
use Alfa\Component\Alfa\Site\Helper\PageCache;
use Alfa\Component\Alfa\Site\Service\Order\CustomerCancel;
use Alfa\Component\Alfa\Site\Service\Order\OrderConfirmation;
use Alfa\Component\Alfa\Site\Service\Order\OrderDocuments;
use Alfa\Component\Alfa\Site\Service\Order\Reorder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Throwable;

/**
 * What a customer does with one of their orders: cancel it or buy it again from their account (a POST with the form's
 * token, the customer logged in), download its documents (each link's own token). The pages themselves are the orders and order
 * views, and for a guest the order completed page's keyed address.
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
     * Put the order's lines in the cart again, at today's prices and availability (Reorder): the order's id, the
     * customer logged in, their own order. Lines that cannot go in are named with why, the rest go in; the answer is
     * the cart when anything went in, else the order's page.
     *
     * @since  2.3.0
     */
    public function reorder(): void
    {
        $this->checkToken();

        $orderId = RequestScalar::int($this->input, 'id', 0);

        if (!$this->loggedIn(orderId: $orderId)) {
            return;
        }

        if (OrderConfirmation::access(orderId: $orderId, key: '', viewerId: (int) $this->app->getIdentity()->id) !== OrderConfirmation::GRANTED) {
            $this->app->enqueueMessage(Text::_('COM_ALFA_ORDER_NOT_AVAILABLE'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=orders', false));

            return;
        }

        $outcomes = Reorder::order(orderId: $orderId);

        foreach (Reorder::sentences(outcomes: $outcomes) as [$sentence, $type]) {
            $this->app->enqueueMessage($sentence, $type);
        }

        $any = array_filter($outcomes, static fn (object $outcome): bool => $outcome->added > 0) !== [];
        $this->setRedirect(Route::_($any ? 'index.php?option=com_alfa&view=cart' : 'index.php?option=com_alfa&view=order&id=' . $orderId, false));
    }

    /**
     * Download one of an order's sales documents as a PDF, as the order's page links it (OrderDocuments): the
     * document's id and its token. A customer account's order needs that customer logged in (a visitor who is not is
     * sent to log in and back to this address); a guest's order, the token alone. The answer is never stored, never
     * indexed, and the address never leaves the site in a Referer. A document that cannot be downloaded sends the
     * visitor back with the reason: their orders for a customer, the shop's home page for a guest.
     *
     * @since  2.3.0
     */
    public function document(): void
    {
        $app = $this->app;

        PageCache::excludeResponse();
        $app->allowCache(false);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('Referrer-Policy', 'same-origin', true);

        $documentId = (int) RequestScalar::read($this->input, 'doc', 0, 'uint');
        $viewerId = (int) $app->getIdentity()->id;
        [$verdict, $document] = OrderDocuments::open(
            documentId: $documentId,
            token: (string) RequestScalar::read($this->input, 'token', '', 'alnum'),
            viewerId: $viewerId,
        );

        if ($verdict === OrderDocuments::LOGIN) {
            $app->enqueueMessage(Text::_('COM_ALFA_ORDER_DOCUMENT_LOGIN_REQUIRED'), 'notice');
            $app->redirect(Route::_('index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString()), false));

            return;
        }

        $back = $viewerId > 0 ? Route::_('index.php?option=com_alfa&view=orders', false) : Uri::root();

        if ($verdict !== OrderDocuments::GRANTED || $document === null) {
            $app->enqueueMessage(Text::_('COM_ALFA_ORDER_DOCUMENT_NOT_AVAILABLE'), 'warning');
            $app->redirect($back);

            return;
        }

        try {
            $bytes = PdfRenderer::pdfBytes(documentId: $documentId);
        } catch (Throwable $e) {
            // The reason goes to the log; the customer reads that the document cannot be had now.
            Log::add('Customer document ' . $documentId . ' could not be made into a PDF (' . $e::class . ': ' . $e->getMessage() . ').', $e instanceof PdfLibraryMissingException ? Log::WARNING : Log::ERROR, 'com_alfa.documents');
            $app->enqueueMessage(Text::_('COM_ALFA_ORDER_DOCUMENT_UNAVAILABLE'), 'warning');
            $app->redirect($back);

            return;
        }

        $app->setHeader('Content-Type', 'application/pdf', true);
        $app->setHeader('Content-Disposition', 'inline; filename="' . PdfRenderer::filename($document) . '"', true);
        $app->setHeader('Content-Length', (string) \strlen($bytes), true);
        $app->sendHeaders();

        echo $bytes;

        $app->close();
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
