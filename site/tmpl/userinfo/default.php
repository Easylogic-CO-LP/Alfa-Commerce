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

<div class="com-alfa-userinfo">

    <h2>
        <?php echo Text::_('COM_ALFA_USERINFO_HEADING'); ?>
    </h2>

    <?php if (empty($this->rows)): ?>
        <p>
            <?php echo Text::_('COM_ALFA_USERINFO_EMPTY'); ?>
        </p>
    <?php else: ?>
        <ul class="alfa-userinfo-list">
            <?php foreach ($this->rows as $row): ?>
                <li>
                    <span>
                        <?php
                        $rowArray = (array) $row;
                        $lastElement = end($rowArray);

                        foreach ($row as $r => $value) {

                            if ((string) $r === 'id_user' || (string) $r === 'id') {
                                continue;
                            }

                            $displayDash = ($lastElement == $value) || ($lastElement == null) ? "" : " " . "-" . " ";

                            if (isset($value)) {
                                echo $value . $displayDash;
                            }

                        }

                        ?>
                    </span>
                    <a class="btn btn-sm btn-link"
                        href="<?php echo Route::_('index.php?option=com_alfa&view=userinfo&id=' . (int) $row->id); ?>">
                        <?php echo Text::_('JACTION_EDIT'); ?>
                    </a>
                    <form class="d-inline" method="POST"
                        action="<?php echo Route::_('index.php?option=com_alfa&task=userinfo.delete'); ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
                        <button type="submit" class="btn btn-sm btn-link text-danger">
                            <?php echo Text::_('JACTION_DELETE'); ?>
                        </button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h3>
        <?php echo Text::_($this->editId ? 'COM_ALFA_USERINFO_EDIT' : 'COM_ALFA_USERINFO_ADD'); ?>
    </h3>

    <form id="alfa-userinfo-form" class="form-validate" method="POST"
        action="<?php echo Route::_('index.php?option=com_alfa&task=userinfo.save'); ?>">

        <input type="hidden" name="source_id" value="<?php echo (int) $this->editId; ?>">

        <?php echo FieldsHelper::renderFieldset(form: $this->form, target: 'userinfo'); ?>

        <button type="submit" class="validate btn btn-primary">
            <?php echo Text::_('COM_ALFA_USERINFO_SAVE'); ?>
        </button>
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>

</div>