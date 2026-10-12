<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A single figure with a heading. Offered to be reused (core's own metric cards
 * name it exactly as a plugin's would), never imposed: a card that wants another
 * shape names another layout.
 *
 * @var array $displayData One resolved card; data: ['value' => string]
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$value = (string) ($displayData['data']['value'] ?? '');
$url   = (string) ($displayData['url'] ?? '');
$icon  = (string) ($displayData['icon'] ?? '');

/** Our own glyphs: no icon font, no external asset. */
$paths = [
    'receipt' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2Z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
    'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'coins'   => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
    'box'     => '<path d="m12 3 8 4v10l-8 4-8-4V7Z"/><path d="m4 7 8 4 8-4M12 11v10"/>',
    'list'    => '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
];

$tag = $url !== '' ? 'a' : 'span';
?>
<<?php echo $tag; ?> class="alfa-card-metric"<?php if ($url !== '') : ?> href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
    <span class="alfa-card-head">
        <?php if (isset($paths[$icon])) : ?>
            <span class="alfa-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $paths[$icon]; ?></svg>
            </span>
        <?php endif; ?>
        <span class="alfa-card-title"><?php echo Text::_($displayData['title']); ?></span>
    </span>

    <strong class="alfa-card-value"><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></strong>

    <?php if (($displayData['subtitle'] ?? '') !== '') : ?>
        <span class="alfa-card-sub"><?php echo Text::_($displayData['subtitle']); ?></span>
    <?php endif; ?>
</<?php echo $tag; ?>>
