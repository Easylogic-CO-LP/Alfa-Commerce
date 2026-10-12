<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentIssuer;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentRenderer;
use Alfa\Component\Alfa\Administrator\Service\Document\PdfRenderer;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use RuntimeException;
use Throwable;

/**
 * Documents list controller.
 *
 * Deliberately NOT an AdminController: documents have no publish/trash/
 * delete lifecycle; the ONLY mutation is void, which flips status while
 * keeping the number (immutability invariant). Everything else is display.
 *
 * @since  1.3.0
 */
class DocumentsController extends BaseController
{
    /**
     * The component this controller routes within.
     *
     * Declared, because BaseController never sets it; only FormController
     * and AdminController do. Unset, every modal redirect below was built as
     * `index.php?option=&view=documents...`, and Joomla answered the void's
     * return, the refused void and Close alike with "View not found" inside
     * the dialog.
     *
     * @var string
     * @since  1.14.0
     */
    protected $option = 'com_alfa';

    /**
     * Void a single issued document. POST, token-checked, gated on the documents
     * edit.state permission: voiding changes a document's status, never its content.
     *
     * Input: id (document), reason (required free text), return (base64 url).
     *
     * A refusal comes back to the form with the reason as the void would have
     * stored it (read as text, so markup is dropped): it is kept
     * under the document's own user-state key and the redirect carries
     * `kept=1`, the one open of the form that reads it back, the pattern the
     * issue and replacement modals follow (since 1.14.0).
     *
     *
     * @since  1.3.0
     */
    public function void(): void
    {
        $this->checkToken();

        $app = $this->app;
        $id = $this->input->getInt('id', 0);
        $stateKey = 'com_alfa.edit.documents.void.' . $id;

        // The form is an iframe modal, so both ends of this task are modal
        // layouts: back to the form when it refuses (which is how the
        // operator SEES the refusal and can correct it) and to the return
        // page, which closes the modal and reloads the page behind it, only
        // once a document was actually voided.
        $formUrl = "index.php?option={$this->option}&view=documents&tmpl=component"
            . "&layout=void&id={$id}";
        $returnUrl = "index.php?option={$this->option}&view=documents&tmpl=component"
            . "&layout=void_return&id={$id}";

        $success = false;

        if (!AlfaHelper::can(section: 'documents', action: 'edit.state', user: $app->getIdentity())) {
            $app->enqueueMessage(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 'error');
            // Back to the form, which asks the same permission and refuses
            // inside the modal, never the Documents list loaded into the
            // dialog (F-35).
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        $reason = trim($this->input->getString('reason', ''));

        // Checked again on the server. The form marks the field required and
        // Joomla's validator refuses an empty submit, but a client-side rule
        // is a convenience and never the guard.
        if ($id <= 0 || $reason === '') {
            $app->enqueueMessage(Text::_('COM_ALFA_DOCUMENTS_VOID_NEEDS_REASON'), 'warning');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        try {
            $document = DocumentIssuer::void(documentId: $id, reason: $reason);
            $success = true;
            $app->setUserState($stateKey, null);
            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_DOCUMENTS_VOIDED_OK', (string) $document->number_formatted),
                'success',
            );
        } catch (Throwable $e) {
            // Kept for the redisplay, so the operator corrects rather than
            // retypes it (F-26). Escaped: the refusal's words are not ours,
            // and the message queue renders HTML (F-14). A refusal the issuer
            // can put in the operator's words is shown in them; its technical
            // message stays the exception's and the log's.
            $app->setUserState($stateKey, ['reason' => $reason]);
            $app->enqueueMessage(htmlspecialchars(DocumentRefusedException::in($e)?->operatorText() ?? $e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->setRedirect(Route::_($success ? $returnUrl : $formUrl . '&kept=1', false));
    }

    /**
     * Close the void modal without voiding anything.
     *
     * reload=0: nothing was written, so the page behind the modal has no
     * reason to re-render. Also forgets a reason a refusal kept for it.
     *
     *
     * @since   1.3.0
     */
    public function cancelVoid(): void
    {
        $id = $this->input->getInt('id', 0);

        $this->app->setUserState('com_alfa.edit.documents.void.' . $id, null);

        $this->setRedirect(Route::_(
            "index.php?option={$this->option}"
            . '&view=documents'
            . '&layout=void_return'
            . '&tmpl=component'
            . "&id={$id}"
            . '&reload=0',
            false,
        ));
    }

    /**
     * Print view: the document's standalone HTML page (browser
     * Print/Save-as-PDF ready; the PDF endpoint feeds the SAME HTML to
     * dompdf). Read-only GET, opened in a new tab.
     *
     *
     * @since  1.3.0
     */
    public function printDocument(): void
    {
        if (!AlfaHelper::can(section: 'documents', action: 'view', user: $this->app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $id = $this->input->getInt('id', 0);

        try {
            $html = DocumentRenderer::render(documentId: $id);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 404);
        }

        // Raw standalone page: bypass the admin template entirely.
        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->sendHeaders();
        echo $html;
        $this->app->close();
    }

    /**
     * Serve the document's PDF, rendered on demand from the frozen entity
     * (nothing is stored). GET, opened in a tab.
     *
     *
     * @since  1.3.0
     */
    public function downloadPdf(): void
    {
        if (!AlfaHelper::can(section: 'documents', action: 'view', user: $this->app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $id = $this->input->getInt('id', 0);

        try {
            $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('doc_type'), $db->quoteName('number_formatted')])
                ->from($db->quoteName('#__alfa_documents'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id);
            $db->setQuery(query: $query);
            $document = $db->loadObject();

            if ($document === null) {
                throw new RuntimeException("Document #{$id} does not exist.", 404);
            }

            $bytes = PdfRenderer::pdfBytes(documentId: $id);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 500);
        }

        $this->app->setHeader('Content-Type', 'application/pdf', true);
        $this->app->setHeader('Content-Disposition', 'inline; filename="' . PdfRenderer::filename($document) . '"', true);
        $this->app->setHeader('Content-Length', (string) \strlen($bytes), true);
        $this->app->sendHeaders();
        echo $bytes;
        $this->app->close();
    }
}
