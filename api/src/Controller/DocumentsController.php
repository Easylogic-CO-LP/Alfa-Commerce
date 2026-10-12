<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Document\DocumentIssuer;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentRenderer;
use Alfa\Component\Alfa\Administrator\Service\Document\PdfRenderer;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * The Documents controller
 *
 * @since  1.9.0
 */
class DocumentsController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type of the item.
     *
     * @var string
     * @since  1.0.0
     */
    protected $contentType = 'documents';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  1.0.0
     */
    protected $default_view = 'documents';

    /**
     * Documents list view amended to add filtering of data
     *
     * @return static A BaseController object to support chaining.
     *
     * @since  1.9.0
     */
    public function displayList()
    {
        $apiFilterInfo = $this->input->get('filter', [], 'array');
        $filter = InputFilter::getInstance();

        if (\array_key_exists('search', $apiFilterInfo)) {
            $this->modelState->set('filter.search', $filter->clean($apiFilterInfo['search'], 'STRING'));
        }

        if (\array_key_exists('doc_type', $apiFilterInfo)) {
            $this->modelState->set('filter.doc_type', $filter->clean($apiFilterInfo['doc_type'], 'CMD'));
        }

        if (\array_key_exists('status', $apiFilterInfo)) {
            $this->modelState->set('filter.status', $filter->clean($apiFilterInfo['status'], 'INT'));
        }

        if (\array_key_exists('id_order', $apiFilterInfo)) {
            $this->modelState->set('filter.id_order', $filter->clean($apiFilterInfo['id_order'], 'INT'));
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

    /**
     * Void an issued document.
     *
     * POST v1/alfa/documents/:id/void  {reason}
     *
     * A document is legally immutable, so this is the ONLY correction: the
     * row stays, its number stays drawn, and it is marked void with a reason.
     * Voiding refills the type's quantity pool, which means the goods become
     * issuable again.
     *
     * Gated on the edit.state permission, the same one the administrator's void
     * asks: it changes the document's status and nothing else.
     *
     * @return static
     *
     * @since  1.9.1
     */
    public function void()
    {
        $this->assertAuthorised('edit.state');

        $documentId = $this->routeInt('id');

        if ($documentId <= 0) {
            throw new ResourceNotFound('Document not found', 404);
        }

        $body = $this->body();
        $reason = trim((string) ($body['reason'] ?? ''));

        if ($reason === '') {
            throw new Save('reason is required to void a document', 400);
        }

        if (mb_strlen($reason) > 500) {
            throw new Save('reason must be 500 characters or fewer', 400);
        }

        try {
            DocumentIssuer::void($documentId, $reason, (int) $this->app->getIdentity()->id);
        } catch (RuntimeException $e) {
            // A refusal the issuer can put in the operator's words is answered
            // in them; any other keeps the issuer's own.
            throw new Save(DocumentRefusedException::in($e)?->operatorText() ?? $e->getMessage(), 409, $e);
        }

        return $this->displayItem($documentId);
    }

    /**
     * The rendered document as HTML.
     *
     * GET v1/alfa/documents/:id/html
     *
     *
     * @since  1.9.1
     */
    public function html(): void
    {
        $this->assertAuthorised('view');

        $document = $this->requireDocument();
        $html = DocumentRenderer::render((int) $document->id);

        if (trim($html) === '') {
            throw new Save(
                'The document layout produced no output; check that its layout exists for this document type',
                500,
            );
        }

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->sendHeaders();

        echo $html;

        $this->app->close();
    }

    /**
     * The rendered document as a PDF.
     *
     * GET v1/alfa/documents/:id/pdf
     *
     *
     * @since  1.9.1
     */
    public function pdf(): void
    {
        $this->assertAuthorised('view');

        $document = $this->requireDocument();

        try {
            $bytes = PdfRenderer::pdfBytes((int) $document->id);
        } catch (Throwable $e) {
            throw new Save('The document could not be rendered: ' . $e->getMessage(), 500);
        }

        $this->app->setHeader('Content-Type', 'application/pdf', true);
        $this->app->setHeader(
            'Content-Disposition',
            'inline; filename="' . PdfRenderer::filename($document) . '"',
            true,
        );
        $this->app->setHeader('Content-Length', (string) \strlen($bytes), true);
        $this->app->sendHeaders();

        echo $bytes;

        $this->app->close();
    }

    //  Helpers

    /**
     * The document named by the route, or a 404.
     *
     * PdfRenderer::filename() needs the row, and both renderers would
     * otherwise fail deep inside on a missing id.
     *
     *
     * @since  1.9.1
     */
    private function requireDocument(): object
    {
        $documentId = $this->routeInt('id');

        if ($documentId <= 0) {
            throw new ResourceNotFound('Document not found', 404);
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_documents'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $documentId, ParameterType::INTEGER),
        )->loadObject();

        if (!$row) {
            throw new ResourceNotFound('Document not found', 404);
        }

        return $row;
    }

    /**
     * The JSON request body, raw body first so a query string cannot shadow it.
     *
     *
     * @since  1.9.1
     */
    private function body(): array
    {
        $decoded = json_decode((string) $this->input->json->getRaw(), true);

        return \is_array($decoded) ? $decoded : (array) $this->input->get('data', [], 'array');
    }

    /**
     * Read a route variable from the bag the router wrote it to.
     *
     * ApiApplication::route() uses $input->post on POST and the main input
     * otherwise; the main input is $_REQUEST-backed, so falling back between
     * them would let a query string shadow the path.
     *
     * @param string $name Route variable name.
     *
     *
     * @since  1.9.1
     */
    private function routeInt(string $name): int
    {
        $bag = strtoupper((string) $this->input->getMethod()) === 'POST'
            ? $this->input->post
            : $this->input;

        return (int) $bag->getInt($name, 0);
    }
}
