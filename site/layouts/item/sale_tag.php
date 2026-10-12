<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The sale-tag badge: a flagged discount's customer-facing name, rendered in a surface
 * slot on the catalogue card or the product page. Contributed by SaleTag::contribute()
 * and rendered through the item.surfaces loop. Override at
 * templates/<template>/html/layouts/com_alfa/item/sale_tag.php.
 *
 * Colour + extra class come from the discount and are OPTIONAL: an empty value means
 * "use the layout's own CSS default" (the .sale-tag rule in item.css), so a
 * merchant who sets nothing gets the stock red badge.
 *
 * Targeting hooks, most specific last: `.sale-tag` (shared look) ->
 * `.sale-tag--list` / `.sale-tag--item` (context, so the card and product-page
 * badges are styled apart without depending on an ancestor container) -> the wrapper's
 * `[data-surface="..."]` (per slot) -> the merchant's own `tag_class` (per discount).
 *
 * @var array $displayData ['name' => string, 'context' => string, 'bg' => string,
 *                          'color' => string, 'class' => string, 'discount' => AppliedDiscount]
 */

defined('_JEXEC') or die;

$name = (string) ($displayData['name'] ?? '');

if ($name === '') {
    return;
}

$context = (string) ($displayData['context'] ?? '');
$bg      = trim((string) ($displayData['bg'] ?? ''));
$color   = trim((string) ($displayData['color'] ?? ''));
$class   = trim((string) ($displayData['class'] ?? ''));

$classAttr = 'sale-tag';

if ($context !== '') {
    $classAttr .= ' sale-tag--' . $context;
}

if ($class !== '') {
    $classAttr .= ' ' . $class;
}

$style = '';

if ($bg !== '') {
    $style .= 'background:' . $bg . ';';
}

if ($color !== '') {
    $style .= 'color:' . $color . ';';
}
?>
<span class="<?php echo htmlspecialchars($classAttr, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $style !== '' ? ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span>
