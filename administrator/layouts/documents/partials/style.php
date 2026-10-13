<?php

/**
 * Document layouts: shared stylesheet. BINDING dompdf-safe subset.
 *
 * The same HTML feeds the browser print view, the on-demand dompdf PDF
 * and the email attachment. dompdf renders a CSS 2.1 subset; anything
 * outside the rules below silently breaks the PDF while looking fine in
 * the browser, so every layout and override MUST stay inside them:
 *
 *   ALLOWED   <table> for every grid; float / inline-block; widths in
 *             %/mm/pt; ONE inline <style> block (this partial);
 *             font-family "DejaVu Sans" (ships with dompdf, broad
 *             Unicode coverage); page-break-inside: avoid; @page
 *             { margin }; images only as data: URIs or absolute LOCAL
 *             paths.
 *   FORBIDDEN flex / grid / position:sticky (dompdf ignores them);
 *             external CSS/JS/fonts/images (a PDF has no network);
 *             JavaScript; transform (voided marking is a banner, not a
 *             rotated watermark); re-computing ANY amount in a template
 *             - rendering data comes exclusively from the frozen
 *             document entity.
 *
 * Always check the PDF button after design changes. The admin-facing
 * walkthrough (overrides, custom layouts, positions, naming constants)
 * lives in the series Templating tab's help note.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;
?>
<style>
    @page { margin: 14mm 12mm; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 9pt;
        color: #1a1a1a;
        margin: 0;
    }

    table { border-collapse: collapse; width: 100%; }

    .doc-header { margin-bottom: 6mm; }
    .doc-header td { vertical-align: top; }

    .seller-name { font-size: 13pt; font-weight: bold; }
    .seller-details { font-size: 8pt; color: #444; line-height: 1.5; }

    .doc-box {
        border: 1.2pt solid #1a1a1a;
        padding: 3mm 4mm;
        width: 62mm;
    }
    .doc-box .doc-title { font-size: 11pt; font-weight: bold; text-transform: uppercase; }
    .doc-box table td { font-size: 8.5pt; padding: 0.7mm 0; }
    .doc-box .label { color: #555; padding-right: 3mm; white-space: nowrap; }

    .voided-banner {
        border: 1.5pt solid #b30000;
        color: #b30000;
        font-size: 12pt;
        font-weight: bold;
        text-align: center;
        text-transform: uppercase;
        padding: 2.5mm;
        margin-bottom: 5mm;
    }

    .meta-table { margin-bottom: 5mm; }
    .meta-table > tbody > tr > td { vertical-align: top; padding: 0; }
    .meta-block {
        border: 0.5pt solid #999;
        padding: 2.5mm 3mm;
        margin-right: 3mm;
    }
    .meta-block .block-title {
        font-size: 7.5pt;
        font-weight: bold;
        text-transform: uppercase;
        color: #555;
        margin-bottom: 1.5mm;
    }
    .meta-block table td { font-size: 8.5pt; padding: 0.6mm 0; }
    .meta-block .label { color: #555; padding-right: 3mm; white-space: nowrap; }

    .lines-table { margin-bottom: 5mm; }
    .lines-table th {
        background: #efefef;
        border: 0.5pt solid #999;
        font-size: 8pt;
        text-transform: uppercase;
        padding: 1.6mm 2mm;
        text-align: left;
    }
    .lines-table td {
        border: 0.5pt solid #bbb;
        font-size: 8.5pt;
        padding: 1.6mm 2mm;
        vertical-align: top;
    }
    .lines-table tr { page-break-inside: avoid; }
    .num { text-align: right; white-space: nowrap; }
    .ctr { text-align: center; }
    .line-desc { font-size: 7.5pt; color: #666; }

    .bottom-table > tbody > tr > td { vertical-align: top; padding: 0; }

    .taxes-table { width: 92mm; }
    .taxes-table th {
        background: #efefef;
        border: 0.5pt solid #999;
        font-size: 7.5pt;
        text-transform: uppercase;
        padding: 1.3mm 2mm;
        text-align: right;
    }
    .taxes-table td {
        border: 0.5pt solid #bbb;
        font-size: 8.5pt;
        padding: 1.3mm 2mm;
        text-align: right;
    }

    .totals-table { width: 70mm; float: right; page-break-inside: avoid; }
    .totals-table td { font-size: 9pt; padding: 1.4mm 2mm; }
    .totals-table .label { color: #444; }
    .totals-table .grand td {
        border-top: 1.2pt solid #1a1a1a;
        font-size: 11pt;
        font-weight: bold;
        padding-top: 2mm;
    }

    .doc-position { font-size: 8.5pt; margin: 0 0 4mm; clear: both; }
    .doc-position p { margin: 0 0 1mm; }
    .doc-position--terms { margin-top: 4mm; page-break-inside: avoid; }
    .doc-position--footer-text { font-size: 8pt; }
    .doc-position--generated { font-size: 7pt; color: #777; margin: 1mm 0 0; }

    .doc-footer {
        margin-top: 8mm;
        border-top: 0.5pt solid #999;
        padding-top: 3mm;
        font-size: 8pt;
        color: #444;
        page-break-inside: avoid;
    }
    .print-note { margin-bottom: 3mm; }
    .authority-line { font-size: 7pt; color: #777; word-wrap: break-word; }
    .qr-slot { float: right; margin-left: 4mm; }
</style>
