<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/**
 * The country select of alfa-form-fields/country (CountryField): Joomla's list
 * layout, then, when the saved place is no longer offered, the place's label
 * and the message asking the customer to choose again. A template overrides it
 * in html/plg_alfa-form-fields_country/layouts/country.php.
 *
 * @var array $displayData The list layout's data, plus heldPlace (string) and unavailable (bool).
 */

echo LayoutHelper::render('joomla.form.field.list', $displayData);

if (empty($displayData['unavailable'])) {
    return;
}

$id = (string) ($displayData['id'] ?? '');
$heldPlace = (string) ($displayData['heldPlace'] ?? '');
?>
<div class="form-text" id="<?php echo htmlspecialchars($id . '-unavailable', ENT_QUOTES, 'UTF-8'); ?>" data-alfa-country-unavailable>
    <?php if ($heldPlace !== '') : ?>
        <strong data-alfa-country-held><?php echo htmlspecialchars($heldPlace, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong><br>
    <?php endif; ?>
    <?php echo Text::_('PLG_ALFA_FORM_FIELDS_COUNTRY_CHOOSE_AGAIN'); ?>
</div>
