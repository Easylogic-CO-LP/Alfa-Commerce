<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One label's badge: a discount's sale tag, the New label, one of the shop's labels or a product's own, all drawn
 * alike (Label). Drawn by item.labels, the stack of a slot's labels. Override at
 * templates/<template>/html/layouts/com_alfa/item/label.php.
 *
 * The colours come from the label and are optional: an empty one leaves the stylesheet's token in charge (item.css,
 * .item-label; a sale tag wears the sale tokens). Targeting hooks, most specific last: .item-label (the look) ->
 * [data-kind="sale" | "new" | "predefined" | "custom"] -> [data-context="list" | "item"] -> [data-label="<key>"] ->
 * the label's own extra class.
 *
 * @var array $displayData ['label' => Label, 'context' => 'list'|'item']
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Catalog\Label;

$label = $displayData['label'] ?? null;

if (!$label instanceof Label || $label->name === '') {
    return;
}

$context = (string) ($displayData['context'] ?? '');
$classes = $label->class !== '' ? 'item-label ' . $label->class : 'item-label';

$style = ($label->bg !== '' ? 'background:' . $label->bg . ';' : '') . ($label->color !== '' ? 'color:' . $label->color . ';' : '');
?>
<span class="<?php echo htmlspecialchars($classes, ENT_QUOTES, 'UTF-8'); ?>" data-kind="<?php echo htmlspecialchars($label->kind, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $context !== '' ? ' data-context="' . htmlspecialchars($context, ENT_QUOTES, 'UTF-8') . '"' : ''; ?> data-label="<?php echo htmlspecialchars($label->key, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $style !== '' ? ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($label->name, ENT_QUOTES, 'UTF-8'); ?></span>
