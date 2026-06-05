<?php

/**
 * Single-select button group layout for alfa-fields/choice.
 *
 * Modelled on joomla.form.field.radio.buttons — same input contract
 * ($options array of HTMLHelperOption-like objects, $value, etc.) but
 * styled by our own alfa-choice-- classes so the field stays Bootstrap-free.
 */

defined('_JEXEC') or die;

extract($displayData);

/**
 * @var string $autocomplete
 * @var bool $autofocus
 * @var string $class
 * @var bool $disabled
 * @var string $id
 * @var string $name
 * @var string $onchange
 * @var string $onclick
 * @var bool $readonly
 * @var bool $required
 * @var string $validate
 * @var string $value
 * @var array $options
 * @var string $dataAttribute
 *
 * Custom keys injected by ButtonField::getLayoutData():
 * @var string $variant      solid | chip | pill | outline
 * @var string $layoutMode   vertical | horizontal | grid
 */

$variant ??= 'solid';
$layoutMode ??= 'vertical';

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
if (!empty($dataAttribute)) {
    $attribs[] = $dataAttribute;
}
?>
<div class="alfa-choice-wrap">
    <fieldset <?php echo implode(' ', $attribs); ?> class="<?php echo $wrapClass; ?>" role="radiogroup">
        <?php foreach ($options as $i => $option) :
            $optDisabled = !empty($option->disable) ? 'disabled' : '';
            $checked = ((string) $option->value === (string) $value) ? 'checked="checked"' : '';
            $oid = $id . $i;
            $ovalue = htmlspecialchars($option->value, ENT_COMPAT, 'UTF-8');
            $optionClass = !empty($option->class) ? $option->class : '';
            $attributes = array_filter([$checked, $optDisabled, $onchange ?? '', $onclick ?? '']);

            if ($required && $i === 0) {
                $attributes[] = 'required';
            }
            ?>
            <input class="alfa-choice__input" type="radio"
                   id="<?php echo $oid; ?>"
                   name="<?php echo $name; ?>"
                   value="<?php echo $ovalue; ?>"
                   <?php echo implode(' ', $attributes); ?>>
            <label for="<?php echo $oid; ?>" class="alfa-choice__label <?php echo trim($optionClass); ?>">
                <span class="alfa-choice__text"><?php echo $option->text; ?></span>
            </label>
        <?php endforeach; ?>
    </fieldset>
</div>
