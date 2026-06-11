<?php

/**
 * Multi-select button group layout for alfa-form-fields/choice.
 *
 * Modelled on joomla.form.field.checkboxes — submits as name[]= so the
 * core CheckboxesField parent handles value coercion. We add the
 * data-min-selections / data-max-selections attrs that ChoiceRule
 * (server) and choice.js (client) both consume.
 */

defined('_JEXEC') or die;

extract($displayData);

/**
 * @var string $autocomplete
 * @var bool $autofocus
 * @var string $class
 * @var bool $disabled
 * @var string $id
 * @var string $name           Joomla appends [] for multi values automatically.
 * @var string $onchange
 * @var string $onclick
 * @var bool $readonly
 * @var bool $required
 * @var array $options
 * @var array $checkedOptions
 * @var bool $hasValue
 * @var string $dataAttribute
 *
 * Custom keys:
 * @var string $variant
 * @var string $layoutMode
 * @var int $minSelections
 * @var int $maxSelections
 */

$variant ??= 'solid';
$layoutMode ??= 'vertical';
$minSelections ??= 0;
$maxSelections ??= 0;

$wrapClass = trim('alfa-choice alfa-choice--button alfa-choice--' . $variant . ' alfa-choice--' . $layoutMode . ' ' . $class);

$attribs = ['id="' . $id . '"'];
if (!empty($disabled)) {
    $attribs[] = 'disabled';
}
if (!empty($autofocus)) {
    $attribs[] = 'autofocus';
}
if ($readonly || $disabled) {
    $attribs[] = 'style="pointer-events: none"';
}
if ($minSelections > 0) {
    $attribs[] = 'data-min-selections="' . $minSelections . '"';
}
if ($maxSelections > 0) {
    $attribs[] = 'data-max-selections="' . $maxSelections . '"';
}
if (!empty($dataAttribute)) {
    $attribs[] = $dataAttribute;
}

// Joomla ensures multi-select fields submit as name[]; CheckboxesField does this in setup().
$inputName = $name;
if (!str_ends_with($inputName, '[]')) {
    $inputName .= '[]';
}
?>
<div class="alfa-choice-wrap">
    <fieldset <?php echo implode(' ', $attribs); ?> class="<?php echo $wrapClass; ?>" role="group">
        <?php foreach ($options as $i => $option) :
            $optDisabled = !empty($option->disable) ? 'disabled' : '';
            $checked = in_array((string) $option->value, array_map('strval', (array) $checkedOptions), true) ? 'checked="checked"' : '';
            $oid = $id . $i;
            $ovalue = htmlspecialchars($option->value, ENT_COMPAT, 'UTF-8');
            $optionClass = !empty($option->class) ? $option->class : '';
            $attributes = array_filter([$checked, $optDisabled, $onchange ?? '', $onclick ?? '']);

            if ($required && $i === 0) {
                $attributes[] = 'required';
            }
            ?>
            <input class="alfa-choice__input" type="checkbox"
                   id="<?php echo $oid; ?>"
                   name="<?php echo $inputName; ?>"
                   value="<?php echo $ovalue; ?>"
                   <?php echo implode(' ', $attributes); ?>>
            <label for="<?php echo $oid; ?>" class="alfa-choice__label <?php echo trim($optionClass); ?>">
                <span class="alfa-choice__text"><?php echo $option->text; ?></span>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <small class="alfa-choice__hint" aria-live="polite"></small>
</div>
