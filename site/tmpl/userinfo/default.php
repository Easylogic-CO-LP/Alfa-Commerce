<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.general');
$wa->useStyle('com_alfa.userinfo');
$wa->useStyle('com_alfa.validate');
$wa->useScript('com_alfa.validate.engine');
$wa->useScript('com_alfa.showon.engine');
Text::script('COM_ALFA_VALIDATE_FIELD_INVALID');
?>

<div class="com-alfa com-alfa-userinfo">

    <h2 class="userinfo-heading">
        <?php echo Text::_('COM_ALFA_USERINFO_HEADING'); ?>
    </h2>

    <?php if (empty($this->rows)): ?>
        <p class="userinfo-empty">
            <?php echo Text::_('COM_ALFA_USERINFO_EMPTY'); ?>
        </p>
    <?php else: ?>
        <ul class="userinfo-list">
            <?php foreach ($this->rows as $row): ?>
                <li class="userinfo-row<?php echo (int) $this->editId === (int) $row->id ? ' is-editing' : ''; ?>">
                    <span class="userinfo-row-label">
                        <?php echo $this->escape(UserInfoHelper::label($row)); ?>
                    </span>
                    <div class="userinfo-row-actions">
                        <a class="action secondary small"
                            href="<?php echo Route::_('index.php?option=com_alfa&view=userinfo&id=' . (int) $row->id); ?>">
                            <?php echo Text::_('JACTION_EDIT'); ?>
                        </a>
                        <form class="userinfo-row-delete" method="POST"
                            action="<?php echo Route::_('index.php?option=com_alfa&task=userinfo.delete'); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
                            <button type="submit" class="action danger small">
                                <?php echo Text::_('JACTION_DELETE'); ?>
                            </button>
                            <?php echo HTMLHelper::_('form.token'); ?>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <section class="userinfo-form-section">
        <h3 class="userinfo-form-heading">
            <span><?php echo Text::_($this->editId ? 'COM_ALFA_USERINFO_EDIT' : 'COM_ALFA_USERINFO_ADD'); ?></span>
            <?php if ($this->editId) : ?>
                <a class="action secondary small" href="<?php echo Route::_('index.php?option=com_alfa&view=userinfo'); ?>">
                    <?php echo Text::_('COM_ALFA_USERINFO_ADD'); ?>
                </a>
            <?php endif; ?>
        </h3>

        <form id="userinfo-form" class="form-validate" method="POST"
            action="<?php echo Route::_('index.php?option=com_alfa&task=userinfo.save'); ?>">

            <input type="hidden" name="source_id" value="<?php echo (int) $this->editId; ?>">

            <?php echo FieldsHelper::renderFieldset(form: $this->form, target: 'userinfo'); ?>

            <button type="submit" class="action primary">
                <?php echo Text::_('COM_ALFA_USERINFO_SAVE'); ?>
            </button>
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </section>

</div>
