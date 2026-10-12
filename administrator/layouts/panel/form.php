<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The framework's DEFAULT edit screen: a native adminForm the panel toolbar (Save & Close /
 * Cancel) submits through Joomla.submitform. It renders the form the plugin named on the view
 * event, and wraps it in a single native "General" tab so every panel has the same native tab
 * chrome as com_alfa's own edit screens. All of the form's fieldsets render as sections INSIDE
 * that one tab; a fieldset is NOT a tab. Multiple tabs are a deliberate layout choice: a plugin
 * that wants them ships its OWN edit layout (PanelViewEvent::setLayout), exactly like it ships
 * its own list layout, and lays the tabs out with HTMLHelper::_('uitab.*') as com_alfa's core
 * edit.php files do. The custom layout receives the same $form.
 *
 * @var array $displayData
 */

use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/** @var Form $form */
$form      = $displayData['form'];
$identity  = $displayData['identity'];
$token     = $displayData['token'] ?? '';
$e         = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$fieldsets = $form->getFieldsets();
?>
<form action="index.php" method="post" name="adminForm" id="adminForm" class="form-validate form-horizontal alfa-panel-form" enctype="multipart/form-data">
    <div class="alfa-panel-form__body">
        <?php echo HTMLHelper::_('uitab.startTabSet', 'alfaPanelTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
        <?php echo HTMLHelper::_('uitab.addTab', 'alfaPanelTab', 'general', Text::_('COM_ALFA_PANEL_TAB_GENERAL')); ?>
            <?php if ($fieldsets !== []) : ?>
                <?php foreach ($fieldsets as $fieldset) : ?>
                    <?php echo $form->renderFieldset($fieldset->name); ?>
                <?php endforeach; ?>
            <?php else : ?>
                <?php echo $form->renderFieldset('default'); ?>
            <?php endif; ?>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>
        <?php echo HTMLHelper::_('uitab.endTabSet'); ?>
    </div>

    <?php // Routing + CSRF the panel controller checks; the toolbar fills `task` on submit. ?>
    <input type="hidden" name="option" value="com_alfa">
    <input type="hidden" name="group" value="<?php echo $e($identity->group); ?>">
    <input type="hidden" name="element" value="<?php echo $e($identity->element); ?>">
    <input type="hidden" name="panel" value="<?php echo $e($identity->panel); ?>">
    <input type="hidden" name="task" value="">
    <input type="hidden" name="<?php echo $e($token); ?>" value="1">
</form>
