<?php

/**
 * Document footer: printed note, transmission (MARK/UID) line and
 * the QR slot: populated by the transmission plugin when present, empty
 * slots render nothing.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

/** @var array $displayData */
$document = $displayData['document'];
?>
<div class="doc-footer">
    <?php if (!empty($document->qr_url)) : ?>
        <div class="qr-slot">
            <?php // The transmission plugin swaps qr_url for an embedded data-URI QR image. ?>
            <div style="font-size: 6.5pt; color: #777; max-width: 30mm; word-wrap: break-word;">
                <?php echo htmlspecialchars((string) $document->qr_url, ENT_COMPAT, 'UTF-8'); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($document->print_note)) : ?>
        <div class="print-note"><?php echo nl2br(htmlspecialchars((string) $document->print_note, ENT_COMPAT, 'UTF-8')); ?></div>
    <?php endif; ?>

    <?php if (!empty($document->authority_mark)) : ?>
        <div class="authority-line">
            MARK: <?php echo htmlspecialchars((string) $document->authority_mark, ENT_COMPAT, 'UTF-8'); ?>
            <?php if (!empty($document->authority_uid)) : ?>
                - UID: <?php echo htmlspecialchars((string) $document->authority_uid, ENT_COMPAT, 'UTF-8'); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
