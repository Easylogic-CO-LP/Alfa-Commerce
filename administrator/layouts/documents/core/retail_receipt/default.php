<?php

/**
 * Retail receipt: complete standalone page composed from the shared
 * partials. Overridable per template / per series (dompdf-safe rules: partials/style.php header).
 *
 * Helper API (extracted from $displayData, mirroring the email layouts):
 *   $render($id, $data?)  - render a structural partial. Each distinct
 *                           $render gets a show/hide eye toggle in the
 *                           series composer (Templating tab).
 *   $position($name)      - merchant text slot, authored per series and
 *                           language. Calling it here = an editor on the
 *                           series form (discovered via a collector render).
 *   $hasPosition($name)   - true when that slot has authored content.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.3.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Layout\LayoutHelper;

/** @var array $displayData */
$opts = ['component' => 'com_alfa', 'client' => 1];

$render = $displayData['render']
    ?? static fn (string $id, ?array $data = null): string => (string) LayoutHelper::render($id, $data ?? $displayData, null, $opts);
$position = $displayData['position'] ?? static fn (string $name): string => '';
$hasPosition = $displayData['hasPosition'] ?? static fn (string $name): bool => false;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars((string) $displayData['document']->language, ENT_COMPAT, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars((string) $displayData['document']->number_formatted, ENT_COMPAT, 'UTF-8'); ?></title>
    <?php echo LayoutHelper::render('documents.partials.style', $displayData, null, $opts); ?>
</head>
<body>
    <?php echo $render('documents.partials.header'); ?>
    <?php echo $render('documents.partials.meta'); ?>
    <?php if ($hasPosition('intro')) : ?>
        <div class="doc-position doc-position--intro"><?php echo $position('intro'); ?></div>
    <?php endif; ?>
    <?php echo $render('documents.partials.lines'); ?>
    <?php echo $render('documents.partials.taxes_totals'); ?>
    <?php if ($hasPosition('terms')) : ?>
        <div class="doc-position doc-position--terms"><?php echo $position('terms'); ?></div>
    <?php endif; ?>
    <?php if ($hasPosition('footer_text')) : ?>
        <div class="doc-position doc-position--footer-text"><?php echo $position('footer_text'); ?></div>
    <?php endif; ?>
    <?php echo $render('documents.partials.footer'); ?>
    <?php if ($hasPosition('generated_note')) : ?>
        <div class="doc-position doc-position--generated"><?php echo $position('generated_note'); ?></div>
    <?php endif; ?>
</body>
</html>
