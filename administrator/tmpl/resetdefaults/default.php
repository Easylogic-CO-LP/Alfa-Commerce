<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Tools > Reset to default: every setting that would change (current value, then default), and
 * the confirmed reset.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Resetdefaults\HtmlView $this
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$show = static function (mixed $value, bool $set = true): string {
    if (!$set || $value === null) {
        return '<em>' . Text::_('COM_ALFA_RESET_NOT_SET') . '</em>';
    }

    $text = \is_array($value) || \is_object($value) ? (string) json_encode($value) : (string) $value;

    return '<code>' . htmlspecialchars($text === '' ? '""' : $text, ENT_QUOTES, 'UTF-8') . '</code>';
};
$options = $this->preview['options'];
$groups = $this->preview['groups'];
?>
<div class="com-alfa-resetdefaults p-3">
    <div class="alert alert-info">
        <h2 class="h4"><?php echo Text::_('COM_ALFA_RESET_INTRO_TITLE'); ?></h2>
        <p class="mb-0"><?php echo Text::_('COM_ALFA_RESET_INTRO_DESC'); ?></p>
    </div>

    <?php if ($options === [] && $groups === []) : ?>
        <p class="alert alert-success"><?php echo Text::_('COM_ALFA_RESET_NOTHING'); ?></p>
    <?php else : ?>
        <?php if ($options !== []) : ?>
            <div class="card mb-3">
                <div class="card-header"><?php echo Text::sprintf('COM_ALFA_RESET_OPTIONS', \count($options)); ?></div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead><tr>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_SETTING'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_CURRENT'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_DEFAULT'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($options as $change) : ?>
                            <tr>
                                <td><?php echo $this->escape($this->labels[$change['key']] ?? $change['key']); ?> <small class="text-muted"><code><?php echo $this->escape($change['key']); ?></code></small></td>
                                <td><?php echo $show($change['current'], $change['current'] !== null); ?></td>
                                <td><?php echo $show($change['default'], $change['default'] !== null); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($groups !== []) : ?>
            <div class="card mb-3">
                <div class="card-header"><?php echo Text::sprintf('COM_ALFA_RESET_GROUPS', \count($groups)); ?></div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead><tr>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_GROUP'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_CURRENT'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_DEFAULT'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($groups as $group) : ?>
                            <tr>
                                <td><?php echo $this->escape($group['title'] !== '' ? $group['title'] : '#' . $group['id']); ?></td>
                                <td><?php echo Text::_($group['current']['enable'] ? 'COM_ALFA_RESET_GROUP_OWN' : 'COM_ALFA_RESET_GROUP_FOLLOWS'); ?><br><?php echo $show($group['current']['display']); ?></td>
                                <td><?php echo Text::_('COM_ALFA_RESET_GROUP_FOLLOWS'); ?><br><?php echo $show($group['default']['display']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post" class="card card-body">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirm" value="1" id="alfa-reset-confirm" required>
                <label class="form-check-label" for="alfa-reset-confirm"><?php echo Text::_('COM_ALFA_RESET_CONFIRM'); ?></label>
            </div>
            <div>
                <button type="submit" class="btn btn-warning">
                    <span class="icon-refresh" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_RESET_BUTTON'); ?>
                </button>
            </div>
            <input type="hidden" name="task" value="resetdefaults.apply">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php endif; ?>
</div>
