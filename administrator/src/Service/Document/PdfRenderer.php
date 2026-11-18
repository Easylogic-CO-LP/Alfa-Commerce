<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Setup\BundledLibraries;
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
 * library (lib_dompdf), which ships with Alfa Commerce as a Joomla library
 * with the four libraries it needs ({@see BundledLibraries::PDF}); Joomla's
 * autoloader loads their classes. The only disk usage is dompdf's own font
 * cache under Joomla's tmp directory.
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
     * @throws PdfLibraryMissingException When lib_dompdf, or a library it needs, is missing or disabled, asked first
     *                                    ({@see BundledLibraries::unavailable()}): nothing is rendered for a PDF that
     *                                    cannot be made.
     * @throws RuntimeException When the document is missing or rendering fails.
     *
     * @since  2.0.0
     */
    public static function pdfBytes(int $documentId): string
    {
        if (BundledLibraries::unavailable(libraries: BundledLibraries::PDF) !== []) {
            throw new PdfLibraryMissingException();
        }

        $html = DocumentRenderer::render(documentId: $documentId);

        $tmpDir = self::tmpDir();

        $options = new \Dompdf\Options();
        $options->setDefaultFont('DejaVu Sans');
        // dompdf-safe contract (partials/style.php header): no remote assets, ever.
        $options->setIsRemoteEnabled(false);
        $options->setTempDir($tmpDir);
        $options->setFontCache($tmpDir);
        // A font a page declares (@font-face) is written here too, never into the library's own fonts folder, which
        // the file-integrity check covers; dompdf's bundled fonts are still read from the library.
        $options->setFontDir($tmpDir);
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
     * Writable scratch directory for dompdf's temporary files, its font cache
     * and the fonts a page declares (runtime files only, never document data).
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
}
