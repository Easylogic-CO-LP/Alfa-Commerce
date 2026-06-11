<?php

/**
 * Tel input layout for alfa-form-fields/tel.
 *
 * Renders the <input> plus the hint container that tel.js populates. All
 * hint variants are pre-rendered hidden so the translation happens in PHP
 * (via Text::_()) and JS only toggles visibility — no JS-side string table.
 *
 * Mirrors joomla.form.field.text for the input attributes so behaviour is
 * identical to a normal text field except for the wrapping div + hints.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

extract($displayData);

// Load tel-specific assets only when a tel field actually renders. WebAssetManager
// dedupes, so multiple tel fields on a page don't duplicate. A template override
// of this layout can swap or remove these calls without touching the plugin.
$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->getRegistry()->addRegistryFile('media/plg_alfa-form-fields_tel/joomla.asset.json');
$wa->useStyle('plg_alfa-form-fields_tel.intltelinput')
   ->useStyle('plg_alfa-form-fields_tel.tel')
   ->useScript('plg_alfa-form-fields_tel.intltelinput')
   ->useScript('plg_alfa-form-fields_tel.tel');

/** @var string $autocomplete */
/** @var bool $autofocus */
/** @var string $class */
/** @var bool $disabled */
/** @var string $hint placeholder text */
/** @var string $id */
/** @var string $name */
/** @var string $onchange */
/** @var bool $readonly */
/** @var bool $required */
/** @var int $size */
/** @var string $value */
/** @var string $dataAttribute preformatted "data-foo=... data-bar=..." */

$attributes = [
    !empty($class) ? 'class="form-control ' . $class . '"' : 'class="form-control"',
    !empty($size) ? 'size="' . $size . '"' : '',
    $disabled ? 'disabled' : '',
    $readonly ? 'readonly' : '',
    $dataAttribute ?? '',
    strlen($hint ?? '') ? 'placeholder="' . htmlspecialchars($hint, ENT_COMPAT, 'UTF-8') . '"' : '',
    $onchange ? 'onchange="' . $onchange . '"' : '',
    $required ? 'required' : '',
    !empty($autocomplete) ? 'autocomplete="' . $autocomplete . '"' : '',
    $autofocus ? 'autofocus' : '',
];

// Error keys map to JS state — tel.js reads element.querySelector('[data-err="..."]')
// to show the right one. Add/remove rows here without touching JS.
$errors = [
    'invalid' => 'PLG_ALFA_FORM_FIELDS_TEL_ERR_INVALID',
    'too_short' => 'PLG_ALFA_FORM_FIELDS_TEL_ERR_TOO_SHORT',
    'too_long' => 'PLG_ALFA_FORM_FIELDS_TEL_ERR_TOO_LONG',
    'invalid_country' => 'PLG_ALFA_FORM_FIELDS_TEL_ERR_INVALID_COUNTRY',
    'local_only' => 'PLG_ALFA_FORM_FIELDS_TEL_ERR_LOCAL_ONLY',
    'invalid_length' => 'PLG_ALFA_FORM_FIELDS_TEL_ERR_INVALID_LENGTH',
    'not_mobile' => 'PLG_ALFA_FORM_FIELDS_TEL_ERR_NOT_MOBILE',
    'bad_region' => 'PLG_ALFA_FORM_FIELDS_TEL_ERR_BAD_REGION',
];
?>
<div class="alfa-tel">
    <input type="text"
           name="<?php echo $name; ?>"
           id="<?php echo $id; ?>"
           value="<?php echo htmlspecialchars($value, ENT_COMPAT, 'UTF-8'); ?>"
           <?php echo implode(' ', array_filter($attributes)); ?>>

    <div class="alfa-tel__hints" aria-live="polite">
        <?php foreach ($errors as $key => $langKey) : ?>
            <small class="alfa-tel__hint alfa-tel__hint--error"
                   data-err="<?php echo $key; ?>"
                   hidden><?php echo Text::_($langKey); ?></small>
        <?php endforeach; ?>
    </div>
</div>
