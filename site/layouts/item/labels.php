<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The labels of one slot of a card or of the product page, as Labels::contribute() grouped them: in the image slot
 * the labels of one corner, which stack one under the other there (data-corner, item.css); in any other slot the
 * slot's labels in a row. Each label is drawn by item.label. Rendered through the item.surfaces loop; override at
 * templates/<template>/html/layouts/com_alfa/item/labels.php.
 *
 * @var array $displayData ['labels' => Label[], 'corner' => string, 'context' => 'list'|'item', 'slot' => string]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;

$labels = (array) ($displayData['labels'] ?? []);

if ($labels === []) {
    return;
}

$corner = (string) ($displayData['corner'] ?? '');
$context = (string) ($displayData['context'] ?? '');
?>
<div class="item-labels"<?php echo $context !== '' ? ' data-context="' . htmlspecialchars($context, ENT_QUOTES, 'UTF-8') . '"' : ''; ?><?php echo $corner !== '' ? ' data-corner="' . htmlspecialchars($corner, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
    <?php foreach ($labels as $label) : ?>
        <?php echo Layout::render('item.label', ['label' => $label, 'context' => $context]); ?>
    <?php endforeach; ?>
</div>
