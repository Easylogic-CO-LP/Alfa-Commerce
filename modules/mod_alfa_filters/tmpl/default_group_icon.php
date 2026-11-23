<?php

/**
 * The opener icon of an option group, as the module's setting names it: a chevron, a caret,
 * a plus, or none at all. Drawn in the text's colour and hidden from screen readers: the
 * group's button carries the group's name. Whether it turns while the group is open is the
 * stylesheet's, keyed on the module's own attribute.
 *
 * @var array $groupSettings The module's group settings (icon: chevron|caret|plus|none).
 */

\defined('_JEXEC') or die;

$afIconName = (string) ($groupSettings['icon'] ?? 'chevron');
$afIconPaths = [
    'chevron' => 'M5 9l7 7 7-7',
    'caret' => 'M6 9h12l-6 7z',
    'plus' => 'M12 5v14M5 12h14',
];

if (!isset($afIconPaths[$afIconName])) {
    return;
}
?>
<svg class="af-group-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="<?= $afIconName === 'caret' ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="<?= $afIconPaths[$afIconName] ?>"/></svg>
