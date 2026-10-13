<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use RuntimeException;

/**
 * PdfRenderer: on-demand PDF rendering for sales documents.
 *
 * The frozen document entity is the single source of truth; a PDF is a
 * PRESENTATION of it, rendered fresh on every request: no artifacts are
 * stored, so there is nothing to protect, clean up on deletion, or drift
 * out of sync.
 *
 * The pipeline is the ONE rendering definition: the same HTML
 * DocumentRenderer produces for the print view is fed to the dompdf
 * library (lib_dompdf, libraries/dompdf). The only disk usage is dompdf's
 * own font cache under Joomla's tmp directory.
 *
 * @since  2.0.0
 */
final class PdfRenderer
{
    /**
     * Render a document to PDF bytes.
     *
     * @param int $documentId The document id (any status; voided documents
     *                        render with their voided banner).
     *
     * @return string Raw PDF bytes.
     *
     * @throws RuntimeException When the document is missing, lib_dompdf is
     *                          not installed, or rendering fails.
     *
     * @since  2.0.0
     */
    public static function pdfBytes(int $documentId): string
    {
        $html = DocumentRenderer::render(documentId: $documentId);

        self::loadDompdf();

        $tmpDir = self::tmpDir();

        $options = new \Dompdf\Options();
        $options->setDefaultFont('DejaVu Sans');
        // dompdf-safe contract (partials/style.php header): no remote assets, ever.
        $options->setIsRemoteEnabled(false);
        $options->setTempDir($tmpDir);
        $options->setFontCache($tmpDir);
        $options->setChroot([JPATH_ROOT]);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $bytes = (string) $dompdf->output();

        if ($bytes === '' || !str_starts_with($bytes, '%PDF')) {
            throw new RuntimeException("PdfRenderer: rendering produced no valid PDF for document #{$documentId}.");
        }

        return $bytes;
    }

    /**
     * Download filename for a document's PDF.
     *
     * @param object $document The document row (number_formatted, id).
     *
     * @return string e.g. 'invoice-000123.pdf'.
     *
     * @since  2.0.0
     */
    public static function filename(object $document): string
    {
        $number = preg_replace('/[^A-Za-z0-9_\-]/u', '-', (string) $document->number_formatted)
            ?: (string) $document->id;

        return $document->doc_type . '-' . $number . '.pdf';
    }

    /**
     * Writable scratch directory for dompdf's font cache (runtime cache
     * only, never document data).
     *
     * @return string Absolute directory path.
     *
     * @since  2.0.0
     */
    private static function tmpDir(): string
    {
        $base = (string) Factory::getApplication()->get('tmp_path', JPATH_ROOT . '/tmp');
        $dir = rtrim($base, '/') . '/com_alfa_dompdf';

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("PdfRenderer: cannot create scratch directory {$dir}.");
        }

        return $dir;
    }

    /**
     * Load dompdf from the lib_dompdf Joomla library (libraries/dompdf).
     *
     * The library's own autoload.inc.php registers Dompdf plus every
     * bundled dependency namespace: no namespace-map entry needed.
     *
     * @since  2.0.0
     */
    private static function loadDompdf(): void
    {
        if (class_exists(\Dompdf\Dompdf::class)) {
            return;
        }

        $autoload = JPATH_LIBRARIES . '/dompdf/autoload.inc.php';

        if (!is_file($autoload)) {
            throw new RuntimeException('PdfRenderer: the dompdf library is not installed (install lib_dompdf).');
        }

        require_once $autoload;
    }
}
