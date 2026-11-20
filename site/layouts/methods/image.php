<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A payment or shipping method's image, the one the shop set on the method's Image tab: its thumbnail, its alt
 * text the image's own when the shop wrote one on the tile, else the method's title. Draws nothing for a method with
 * no image, so such a method shows as it always has. Pure markup; the box's size cap is the stylesheet's.
 *
 * @var array $displayData ['image' => object|null (a media row as MediaHelper reads it), 'title' => string]
 */

\defined('_JEXEC') or die;

$image = $displayData['image'] ?? null;
$url = \is_object($image) ? (string) ($image->thumbnail_url ?? $image->url ?? '') : '';

if ($url === '') {
    return;
}

$alt = trim((string) ($image->alt ?? '')) !== '' ? (string) $image->alt : (string) ($displayData['title'] ?? '');
?>
<span class="method-image"><img src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($alt, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"></span>
