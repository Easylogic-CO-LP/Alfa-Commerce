<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A product's Labels tab (ItemlabelsField): a table of the shop's labels in their order, each with a tick (this product
 * shows it) and a corner of the image for this product, empty for the label's own. An unpublished label is offered too,
 * marked, so products are ticked before its day. A template overrides this layout at
 * html/layouts/com_alfa/form/field/itemlabels.php.
 *
 * Receives Joomla's field layout data and: rows (id, name, bg, color, corner (the label's own, a language key),
 * published), corners (value => text), value (by label id: on, corner).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$name = (string) $displayData['name'];
$id = (string) $displayData['id'];
$rows = (array) ($displayData['rows'] ?? []);
$corners = (array) ($displayData['corners'] ?? []);
$value = (array) ($displayData['value'] ?? []);
?>
<?php if ($rows === []) : ?>
    <div class="form-text"><?php echo Text::sprintf('COM_ALFA_ITEM_LABELS_NONE_YET', Route::_('index.php?option=com_alfa&view=labels')); ?></div>
<?php else : ?>
<table class="table table-sm align-middle" id="<?php echo $esc($id); ?>">
    <thead>
        <tr>
            <th scope="col"><?php echo Text::_('COM_ALFA_ITEM_LABELS_COL_LABEL'); ?></th>
            <th scope="col" class="text-center"><?php echo Text::_('COM_ALFA_ITEM_LABELS_COL_SHOWN'); ?></th>
            <th scope="col"><?php echo Text::_('COM_ALFA_ITEM_LABELS_COL_CORNER'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row) : ?>
            <?php
                $labelId = (int) $row['id'];
                $choice = $value[$labelId] ?? ['on' => 0, 'corner' => ''];
                $swatch = ((string) $row['bg'] !== '' ? 'background:' . $row['bg'] . ';' : '') . ((string) $row['color'] !== '' ? 'color:' . $row['color'] . ';' : '');
            ?>
            <tr>
                <td>
                    <span class="badge rounded-0<?php echo $swatch === '' ? ' text-bg-dark' : ''; ?>"<?php echo $swatch !== '' ? ' style="' . $esc($swatch) . '"' : ''; ?>><?php echo $esc((string) $row['name']); ?></span>
                    <?php if (!$row['published']) : ?>
                        <span class="text-muted small ms-2"><?php echo Text::_('JUNPUBLISHED'); ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <input type="checkbox" class="form-check-input" id="<?php echo $esc($id . '_' . $labelId . '_on'); ?>"
                           name="<?php echo $esc($name . '[' . $labelId . '][on]'); ?>" value="1"<?php echo (int) $choice['on'] === 1 ? ' checked' : ''; ?>
                           aria-label="<?php echo $esc(Text::_('COM_ALFA_ITEM_LABELS_COL_SHOWN') . ': ' . $row['name']); ?>">
                </td>
                <td>
                    <select class="form-select form-select-sm" id="<?php echo $esc($id . '_' . $labelId . '_corner'); ?>"
                            name="<?php echo $esc($name . '[' . $labelId . '][corner]'); ?>"
                            aria-label="<?php echo $esc(Text::_('COM_ALFA_ITEM_LABELS_COL_CORNER') . ': ' . $row['name']); ?>">
                        <option value=""><?php echo $esc(Text::sprintf('COM_ALFA_ITEM_LABELS_CORNER_AS_LABEL', Text::_((string) $row['corner']))); ?></option>
                        <?php foreach ($corners as $cornerValue => $cornerText) : ?>
                            <option value="<?php echo $esc((string) $cornerValue); ?>"<?php echo (string) $choice['corner'] === (string) $cornerValue ? ' selected' : ''; ?>><?php echo $esc((string) $cornerText); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
