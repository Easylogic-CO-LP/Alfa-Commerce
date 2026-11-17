<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A payment or shipping method in the product page's methods block (2.2.2), drawn by core in the order every method
 * keeps: its image when the shop set one, its title, the description the shop wrote. What the method's plugin adds
 * follows it in the same block, from the plugin's own layout. Pure markup.
 *
 * @var array $displayData ['method' => object (id, name, description), 'image' => object|null]
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;

$method = $displayData['method'] ?? null;

if (!\is_object($method)) {
    return;
}

$title = (string) ($method->name ?? '');
$description = trim((string) ($method->description ?? ''));
?>
<div class="method-item">
    <span class="method-item-title"><?php echo Layout::render('methods.image', ['image' => $displayData['image'] ?? null, 'title' => $title]); ?><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php if ($description !== '') : ?>
    <p class="method-item-description"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
</div>
