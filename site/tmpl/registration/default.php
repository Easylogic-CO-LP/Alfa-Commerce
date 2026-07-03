<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('form.validate');
$wa->useScript('com_alfa.showon');
?>

<div class="com-alfa-registration">

    <h2>
        <?php echo Text::_('COM_ALFA_REGISTRATION_HEADING'); ?>
    </h2>

    <form id="alfa-registration-form" class="form-validate"
        action="<?php echo Route::_('index.php?option=com_alfa&task=registration.register'); ?>" method="POST">

        <?php
        // Stock rendering for every NON-alfa, non-captcha fieldset: core identity
        // fields AND any field injected by other user plugins.
        foreach ($this->form->getFieldsets() as $fieldset):
            if (str_starts_with($fieldset->name, FieldsHelper::FIELDSET_PREFIX)) {
                continue; // alfa group → rendered below through showon
            }
            if ($fieldset->name === 'captcha') {
                continue; // captcha → rendered last
            }
            if (!count($this->form->getFieldset($fieldset->name))) {
                continue;
            }
            ?>
            <fieldset>
                <?php if (!empty($fieldset->label)): ?>
                    <legend><?php echo Text::_($fieldset->label); ?></legend>
                <?php endif; ?>
                <?php echo $this->form->renderFieldset($fieldset->name); ?>
            </fieldset>
        <?php endforeach; ?>

        <?php // Alfa registration fields — alfa pipeline (showon, custom layouts) ?>
        <?php echo FieldsHelper::renderFieldset(form: $this->form, target: 'registration'); ?>

        <?php // Captcha — only when a captcha plugin is enabled ?>
        <?php echo $this->form->renderFieldset('captcha'); ?>

        <button type="submit" class="validate btn btn-primary w-100">
            <?php echo Text::_('COM_ALFA_REGISTRATION_BUTTON_REGISTER'); ?>
        </button>

        <?php echo HTMLHelper::_('form.token'); ?>

    </form>

</div>