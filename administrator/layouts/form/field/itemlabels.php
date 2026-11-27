<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A product's Labels tab (ItemlabelsField): a table of the labels the product may show, the New label first and then
 * the shop's predefined labels in their order, each with a tick (shown on this product) and a corner of the image for
 * this product, empty for the label's own. A template overrides this layout at
 * html/layouts/com_alfa/form/field/itemlabels.php.
 *
 * Receives Joomla's field layout data and: rows (key, name, kind, bg, color, corner (the label's own, a language key),
 * defaultShown), corners (value => text), days (the shop's days a product counts as new), value (by key: shown, corner).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$name = (string) $displayData['name'];
$id = (string) $displayData['id'];
$rows = (array) ($displayData['rows'] ?? []);
$corners = (array) ($displayData['corners'] ?? []);
$days = (int) ($displayData['days'] ?? 0);
$value = (array) ($displayData['value'] ?? []);
?>
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
                $key = (string) $row['key'];
                $choice = $value[$key] ?? ['shown' => (int) $row['defaultShown'], 'corner' => ''];
                $swatch = ((string) $row['bg'] !== '' ? 'background:' . $row['bg'] . ';' : '') . ((string) $row['color'] !== '' ? 'color:' . $row['color'] . ';' : '');
                $isNew = $row['kind'] === 'new';
            ?>
            <tr>
                <td>
                    <span class="badge rounded-0<?php echo $swatch === '' ? ' text-bg-dark' : ''; ?>"<?php echo $swatch !== '' ? ' style="' . $esc($swatch) . '"' : ''; ?>><?php echo $esc((string) $row['name']); ?></span>
                    <span class="text-muted small ms-2"><?php echo $isNew ? Text::_('COM_ALFA_ITEM_LABELS_NEW_ROW') : $esc($key); ?></span>
                    <?php if ($isNew && $days <= 0) : ?>
                        <div class="form-text"><?php echo Text::_('COM_ALFA_ITEM_LABELS_NEW_OFF'); ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <input type="checkbox" class="form-check-input" id="<?php echo $esc($id . '_' . $key . '_shown'); ?>"
                           name="<?php echo $esc($name . '[' . $key . '][shown]'); ?>" value="1"<?php echo (int) $choice['shown'] === 1 ? ' checked' : ''; ?>
                           aria-label="<?php echo $esc(Text::_('COM_ALFA_ITEM_LABELS_COL_SHOWN') . ': ' . $row['name']); ?>">
                </td>
                <td>
                    <select class="form-select form-select-sm" id="<?php echo $esc($id . '_' . $key . '_corner'); ?>"
                            name="<?php echo $esc($name . '[' . $key . '][corner]'); ?>"
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
<?php if (\count($rows) === 1) : ?>
    <div class="form-text"><?php echo Text::_('COM_ALFA_ITEM_LABELS_NONE_PREDEFINED'); ?></div>
<?php endif; ?>
