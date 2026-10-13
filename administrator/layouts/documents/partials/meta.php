<?php

/**
 * Document meta row: buyer block (fiscal docs) + payment + movement block
 * (delivery notes / combined). Blocks self-hide when their data is absent.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var array $displayData */
$document = $displayData['document'];

$hasBuyer = trim((string) $document->buyer_name . (string) $document->buyer_vat_number) !== '';
$hasMovement = !empty($document->movement_purpose) || !empty($document->carrier_name) || !empty($document->ship_to);

$buyerAddress = json_decode((string) ($document->buyer_address ?? ''), true);
$shipTo = json_decode((string) ($document->ship_to ?? ''), true);

$purposeLabels = [
    '1' => 'COM_ALFA_MOVEMENT_PURPOSE_SALE',
    '5' => 'COM_ALFA_MOVEMENT_PURPOSE_RETURN',
    '6' => 'COM_ALFA_MOVEMENT_PURPOSE_STORAGE',
];
?>
<table class="meta-table">
    <tr>
        <?php if ($hasBuyer) : ?>
            <td>
                <div class="meta-block">
                    <div class="block-title"><?php echo Text::_('COM_ALFA_DOCUMENTS_MODAL_BUYER'); ?></div>
                    <table>
                        <tr>
                            <td class="label"><?php echo Text::_('COM_ALFA_DOCUMENTS_FIELD_BUYER_NAME'); ?></td>
                            <td><strong><?php echo htmlspecialchars((string) $document->buyer_name, ENT_COMPAT, 'UTF-8'); ?></strong></td>
                        </tr>
                        <?php if (!empty($document->buyer_vat_number)) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_VAT'); ?></td>
                                <td><?php echo htmlspecialchars((string) $document->buyer_vat_number, ENT_COMPAT, 'UTF-8'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($document->buyer_tax_office)) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_TAX_OFFICE'); ?></td>
                                <td><?php echo htmlspecialchars((string) $document->buyer_tax_office, ENT_COMPAT, 'UTF-8'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($document->buyer_activity)) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOCUMENTS_FIELD_BUYER_ACTIVITY'); ?></td>
                                <td><?php echo htmlspecialchars((string) $document->buyer_activity, ENT_COMPAT, 'UTF-8'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($buyerAddress['text'])) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_ADDRESS'); ?></td>
                                <td><?php echo nl2br(htmlspecialchars((string) $buyerAddress['text'], ENT_COMPAT, 'UTF-8')); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($document->payment_method_name)) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_PAYMENT'); ?></td>
                                <td><?php echo htmlspecialchars((string) $document->payment_method_name, ENT_COMPAT, 'UTF-8'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </td>
        <?php endif; ?>
        <?php if ($hasMovement) : ?>
            <td>
                <div class="meta-block">
                    <div class="block-title"><?php echo Text::_('COM_ALFA_DOCUMENTS_MODAL_MOVEMENT'); ?></div>
                    <table>
                        <?php if (!empty($document->movement_purpose)) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOCUMENTS_FIELD_MOVEMENT_PURPOSE'); ?></td>
                                <td><?php echo Text::_($purposeLabels[(string) $document->movement_purpose] ?? 'COM_ALFA_MOVEMENT_PURPOSE_SALE'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($document->carrier_name)) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOCUMENTS_FIELD_CARRIER'); ?></td>
                                <td><?php echo htmlspecialchars((string) $document->carrier_name, ENT_COMPAT, 'UTF-8'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($document->vehicle_number)) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOCUMENTS_FIELD_VEHICLE'); ?></td>
                                <td><?php echo htmlspecialchars((string) $document->vehicle_number, ENT_COMPAT, 'UTF-8'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($document->movement_start)) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_MOVEMENT_START'); ?></td>
                                <td><?php echo HTMLHelper::_('date', $document->movement_start, Text::_('DATE_FORMAT_LC5')); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($shipTo['text'])) : ?>
                            <tr>
                                <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_SHIP_TO'); ?></td>
                                <td><?php echo nl2br(htmlspecialchars((string) $shipTo['text'], ENT_COMPAT, 'UTF-8')); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </td>
        <?php endif; ?>
    </tr>
</table>
