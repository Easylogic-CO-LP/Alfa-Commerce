<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use RuntimeException;

/**
 * A PDF was asked for while the dompdf library (lib_dompdf) is not installed.
 *
 * The library is installed separately and is not part of Alfa Commerce, so this is a missing part of the server,
 * not a fault of the document: the REST PDF route and the administrator's PDF answer 503 with the sentence, and a
 * status e-mail sends without the attachment and logs the sentence.
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
        parent::__construct(Text::_('COM_ALFA_DOCUMENT_PDF_LIBRARY_MISSING'));
    }
}
