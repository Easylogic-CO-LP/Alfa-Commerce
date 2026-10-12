<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The card settings editor of one level (CardlookField::editor): a device switch (
 * every screen, then one button per breakpoint) and the settings in groups. All the
 * markup is here; look-editor.js shows the current device's values in it, hides what
 * has no effect, and writes the level into the hidden input. The controls carry no
 * name, so only that input is submitted.
 *
 * On the module's own level a setting is a form row like any other, with its
 * description and the custom properties behind it, and an empty box shows what the
 * stylesheet falls back to. A tab's editor is folded away until it is wanted, and
 * compact; an empty setting in it follows the module.
 *
 * @var array $displayData id, name, level (module|tab), groups, controls, breakpoints, inherit, value, config
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

extract($displayData);

/**
 * @var string $id
 * @var string $name
 * @var string $level
 * @var array $groups
 * @var array $controls
 * @var array $breakpoints
 * @var string $inherit
 * @var string $value
 * @var array $config
 */

$esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$svg = static fn (string $shape): string => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $shape . '</svg>';
$icons = [
    'desktop' => $svg('<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>'),
    'tablet' => $svg('<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M12 18h.01"/>'),
    'mobile' => $svg('<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M12 18h.01"/>'),
    'lock' => $svg('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
    'unlock' => $svg('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>'),
];
$module = $level === 'module';
$small = $module ? '' : ' form-control-sm';
$smallSelect = $module ? '' : ' form-select-sm';
$lockOn = Text::_('MOD_ALFA_ITEMS_SIDES_LOCK');
$lockOff = Text::_('MOD_ALFA_ITEMS_SIDES_UNLOCK');

// A device button; the script clones the template below for breakpoints added later.
$device = static fn (string $key, string $label, int $max, string $icon): string =>
    '<button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" data-look-device="' . $esc($key) . '" aria-pressed="false"'
    . ($max > 0 ? ' title="' . $esc(sprintf($config['labels']['upTo'], $max)) . '"' : '') . '>'
    . $icon . '<span data-look-device-label>' . $esc($label) . '</span><span class="alfa-look-dot" data-look-dot hidden></span></button>';
$deviceIcons = '<span data-look-icon="desktop" hidden>' . $icons['desktop'] . '</span><span data-look-icon="tablet">' . $icons['tablet'] . '</span><span data-look-icon="mobile" hidden>' . $icons['mobile'] . '</span>';

/** One setting's control. */
$control = static function (string $key, array $setting) use ($esc, $icons, $inherit, $small, $smallSelect, $lockOn, $lockOff): string {
    $label = ' aria-label="' . $esc($setting['label']) . '"';
    $hint = ' data-look-hint="' . $esc($setting['fallback'] !== '' ? $setting['fallback'] : $inherit) . '"';

    if ($setting['type'] === 'choice' || $setting['type'] === 'switch') {
        $out = '<select class="form-select' . $smallSelect . '" data-look-field="' . $esc($key) . '" data-look-kind="' . $setting['type'] . '"' . $label . '>'
            . '<option value="">' . $esc($inherit) . '</option>';

        foreach ($setting['options'] as $option => $text) {
            $out .= '<option value="' . $esc((string) $option) . '">' . $esc($text) . '</option>';
        }

        return $out . '</select>';
    }

    if ($setting['type'] === 'number') {
        return '<input type="number" class="form-control' . $small . '" min="' . (int) $setting['min'] . '" max="' . (int) $setting['max'] . '" step="1"'
            . ' data-look-field="' . $esc($key) . '" data-look-kind="number"' . $label . $hint . '>';
    }

    if ($setting['type'] === 'size') {
        $out = '<div class="input-group' . ($small !== '' ? ' input-group-sm' : '') . ' alfa-look-size" data-look-size="' . $esc($key) . '"' . $hint . '>'
            . '<input type="number" class="form-control" min="0" max="999" step="0.01" data-look-size-number' . $label . '>'
            . '<select class="form-select" data-look-size-unit aria-label="' . $esc(Text::_('MOD_ALFA_ITEMS_LOOK_UNIT')) . '">';

        foreach ($setting['units'] as $unit) {
            $out .= '<option value="' . $esc($unit) . '">' . $esc($unit) . '</option>';
        }

        return $out . '</select></div>';
    }

    if ($setting['type'] === 'text') {
        return '<input type="text" class="form-control' . $small . '" maxlength="' . (int) $setting['max'] . '" autocomplete="off" spellcheck="false"'
            . ' data-look-field="' . $esc($key) . '" data-look-kind="text"' . $label . $hint . '>';
    }

    // Four numbers and their lock: the sides of a box, or its corners. Their boxes are
    // narrow: below the module's level a dash stands for "follows the level above".
    $hint = ' data-look-hint="' . $esc($setting['fallback'] !== '' ? $setting['fallback'] : '-') . '"';
    $edges = $setting['edges'] === 'corners'
        ? ['MOD_ALFA_ITEMS_CORNER_TL', 'MOD_ALFA_ITEMS_CORNER_TR', 'MOD_ALFA_ITEMS_CORNER_BR', 'MOD_ALFA_ITEMS_CORNER_BL']
        : ['MOD_ALFA_ITEMS_SIDE_TOP', 'MOD_ALFA_ITEMS_SIDE_RIGHT', 'MOD_ALFA_ITEMS_SIDE_BOTTOM', 'MOD_ALFA_ITEMS_SIDE_LEFT'];
    $out = '<div class="input-group' . ($small !== '' ? ' input-group-sm' : '') . ' alfa-look-sides" data-look-sides="' . $esc($key) . '"' . $hint . '>'
        . '<button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center active" data-look-lock aria-pressed="true" title="' . $esc($lockOn) . '" data-title-on="' . $esc($lockOn) . '" data-title-off="' . $esc($lockOff) . '">'
        . '<span class="lock-on">' . $icons['lock'] . '</span><span class="lock-off" hidden>' . $icons['unlock'] . '</span><span class="visually-hidden">' . $esc($lockOn) . '</span></button>';

    foreach ($edges as $index => $edge) {
        $out .= '<input type="number" class="form-control" min="' . (int) $setting['min'] . '" max="' . (int) $setting['max'] . '" step="1"'
            . ' title="' . $esc(Text::_($edge)) . '" aria-label="' . $esc($setting['label'] . ': ' . Text::_($edge)) . '" data-look-side="' . $index . '">';
    }

    return $out . '</div>';
};
?>
<div class="alfa-look alfa-look-<?php echo $esc($level); ?>" data-look-editor data-config="<?php echo $esc((string) json_encode($config)); ?>">
    <input type="hidden"<?php echo $name !== '' ? ' name="' . $esc($name) . '"' : ''; ?> id="<?php echo $esc($id); ?>" value="<?php echo $esc($value); ?>" data-look-store>

    <?php if ($level === 'tab') : ?>
    <details class="alfa-look-fold">
        <summary class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2">
            <?php echo $esc(Text::_('MOD_ALFA_ITEMS_LOOK_FOLD_TAB')); ?>
            <span class="badge bg-info" data-look-total hidden></span>
        </summary>
        <p class="form-text"><?php echo $esc(Text::_('MOD_ALFA_ITEMS_LOOK_FOLD_TAB_DESC')); ?></p>
    <?php endif; ?>

    <div class="alfa-look-bar d-flex flex-wrap align-items-center gap-2 mb-2">
        <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo $esc(Text::_('MOD_ALFA_ITEMS_LOOK_DEVICES')); ?>" data-look-devices>
            <button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 active" data-look-device="" aria-pressed="true">
                <?php echo $icons['desktop']; ?><span><?php echo $esc(Text::_('MOD_ALFA_ITEMS_LOOK_DEVICE_ALL')); ?></span><span class="alfa-look-dot" data-look-dot hidden></span>
            </button>
            <?php foreach ($breakpoints as $breakpoint) : ?>
                <?php echo $device($breakpoint['key'], $breakpoint['label'], (int) $breakpoint['max'], $icons[(int) $breakpoint['max'] >= 1024 ? 'desktop' : ((int) $breakpoint['max'] >= 600 ? 'tablet' : 'mobile')]); ?>
            <?php endforeach; ?>
        </div>
        <span class="form-text m-0" data-look-note></span>
    </div>

    <?php foreach ($groups as $group) : ?>
        <details class="alfa-look-group" data-look-group="<?php echo $esc($group); ?>"<?php echo $module ? ' open' : ''; ?>>
            <summary>
                <span class="alfa-look-group-name"><?php echo $esc(Text::_('MOD_ALFA_ITEMS_LOOK_GROUP_' . strtoupper($group))); ?></span>
                <span class="badge bg-info" data-look-count hidden></span>
            </summary>
            <div class="alfa-look-rows">
                <?php foreach (\Alfa\Module\AlfaItems\Site\Helper\CardLook::GROUPS[$group] as $key) : ?>
                    <?php $setting = $controls[$key]; ?>
                    <?php if ($module) : ?>
                        <div class="control-group" data-look-row="<?php echo $esc($key); ?>">
                            <div class="control-label"><label><?php echo $esc($setting['label']); ?></label></div>
                            <div class="controls">
                                <?php echo $control($key, $setting); ?>
                                <div class="hide-aware-inline-help d-none">
                                    <small class="form-text">
                                        <?php echo $esc($setting['description']); ?>
                                        <?php if ($setting['variables'] !== []) : ?>
                                            <?php echo $esc(Text::_('MOD_ALFA_ITEMS_LOOK_VARIABLES')); ?>
                                            <?php echo implode(', ', array_map(static fn (string $variable): string => '<code>' . $esc($variable) . '</code>', $setting['variables'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="row g-2 align-items-center alfa-look-row" data-look-row="<?php echo $esc($key); ?>">
                            <label class="col-sm-4 col-form-label col-form-label-sm" title="<?php echo $esc($setting['description']); ?>"><?php echo $esc($setting['label']); ?></label>
                            <div class="col-sm-8"><?php echo $control($key, $setting); ?></div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>

    <?php if ($level === 'tab') : ?>
    </details>
    <?php endif; ?>

    <template data-look-tpl="device"><?php echo $device('', '', 0, $deviceIcons); ?></template>
</div>
