<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

defined('_JEXEC') or die;

use RuntimeException;

/**
 * A PDF was asked for while the dompdf library (lib_dompdf), or a library it needs, is missing or disabled
 * ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\BundledLibraries::PDF}).
 *
 * The libraries ship with Alfa Commerce, so this happens only when someone removed or disabled one: a missing part of
 * the server, not a fault of the document. The administrator's PDF button answers with the translated sentence
 * (COM_ALFA_DOCUMENT_PDF_LIBRARY_MISSING) as a message on the documents list, the REST PDF route answers 503 with it,
 * and a status e-mail sends without the attachment and logs this exception's own message. That message is fixed
 * English, as the log's lines are, so it reads the same wherever it is raised, whatever language file the request has
 * loaded.
 *
 * @since  2.2.1
 */
final class PdfLibraryMissingException extends RuntimeException
{
    /**
     * @since  2.2.1
     */
    public function __construct()
    {
        parent::__construct('The dompdf library (lib_dompdf), or a library it needs, is missing or disabled: PDF documents need them.');
    }
}
