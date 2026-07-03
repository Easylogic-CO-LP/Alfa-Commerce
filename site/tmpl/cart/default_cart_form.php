<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;

// form.validate powers document.formvalidator → the class="form-validate"
// hook on the <form> below. Override this template to drop both at once if
// you want a different validation strategy. Asset id is `form.validate`
// per media/system/joomla.asset.json (not `core.form-validate`).
Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('form.validate');
Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('com_alfa.showon');

$activeFields = FieldsHelper::getFields('cart.form');
$allowedKeys = array_column($activeFields, 'field_name');

// Pre-process: filter out rows with no active field values,
// and strip obsolete columns from the data payload.
$processedRows = [];
foreach ($this->savedRows as $row) {
    $hasAnyValue = false;
    $filteredData = ['id' => $row->id, 'id_user' => $row->id_user];

    foreach ($allowedKeys as $key) {
        $val = isset($row->$key) ? trim((string) $row->$key) : '';
        if ($val !== '')
            $hasAnyValue = true;
        $filteredData[$key] = $row->$key ?? null;
    }

    if (!$hasAnyValue)
        continue;

    $processedRows[] = (object) $filteredData;
}
?>
<form id="alfa-cart-form"
      class="form-validate"
      action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_alfa&task=cart.placeOrder'); ?>"
      method="POST">

    <div class="row">
        <div class="col-md-6">
            <h4><?php echo Text::_('COM_ALFA_FORM_CUSTOMER_DETAILS'); ?></h4>

            <?php if (!empty($processedRows)): ?>
                <div class="alfa-saved-info mb-3">
                    <label for="alfa-saved-info-pick"><?php echo Text::_('COM_ALFA_USERINFO_PICK_SAVED'); ?></label>
                    <select id="alfa-saved-info-pick" class="form-select">
                        <option value=""><?php echo Text::_('JSELECT'); ?></option>
                        <?php foreach ($processedRows as $row): ?>
                            <option value="<?php echo (int) $row->id; ?>"
                                data-values='<?php echo $this->escape(json_encode($row, JSON_UNESCAPED_UNICODE)); ?>'>
                                <?php echo UserInfoHelper::label($row) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <script>
                    document.getElementById('alfa-saved-info-pick')?.addEventListener('change', function () {
                        const opt = this.options[this.selectedIndex];
                        if (!opt || !opt.dataset.values) return;
                        const values = JSON.parse(opt.dataset.values);
                        Object.keys(values).forEach(function (key) {
                            if (key === 'id' || key === 'id_user') return;
                            const el = document.querySelector('[name="cartform[alfa_form_fields][' + key + ']"]');
                            if (el) el.value = values[key];
                        });
                        window.alfaShowOn && window.alfaShowOn.refresh && window.alfaShowOn.refresh();
                    });
                </script>
            <?php endif; ?>

			<?php echo FieldsHelper::renderFieldset($this->form, 'cart'); ?>
			<?php echo $this->form->renderFieldset('captcha'); ?>


        </div>

        <div class="col-md-6">
			<?php if (!empty($this->cart->getPaymentMethods())) : ?>
                <h4><?php echo Text::_('COM_ALFA_FORM_PAYMENT'); ?></h4>
				<?php echo $this->loadTemplate('select_payment'); ?>
			<?php endif; ?>

			<?php if (!empty($this->cart->getShipmentMethods())) : ?>
                <h4><?php echo Text::_('COM_ALFA_FORM_SHIPMENT'); ?></h4>
				<?php echo $this->loadTemplate('select_shipment'); ?>
			<?php endif; ?>
        </div>

    </div>


    <!-- class="validate" is what makes Joomla's formvalidator run isValid(form)
         on click (see media/system/js/fields/validate.js:687). Without it,
         submit goes through unblocked even though setHandler('alfatel') is registered. -->
    <button type="submit"
            class="validate btn btn-primary w-100"
            onclick="if (typeof document.formvalidator !== 'undefined' && !document.formvalidator.isValid(this.form)) return false;"
    >
        <?php echo Text::_('COM_ALFA_BUTTON_PLACE_ORDER'); ?>
    </button>

	<?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
</form>