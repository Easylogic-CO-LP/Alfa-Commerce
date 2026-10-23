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

// The value cells wrap a long value inside the table (Alfa's admin stylesheet).
$this->getDocument()->getWebAssetManager()->useStyle('com_alfa.admin');

$escape = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');

// A stored value as words: a list as its items, a set of settings one "setting: value" per line, anything else as it is.
$show = static function (mixed $value, bool $set = true) use ($escape): string {
    if (!$set || $value === null) {
        return '<em>' . Text::_('COM_ALFA_RESET_NOT_SET') . '</em>';
    }

    if (\is_object($value)) {
        $value = (array) $value;
    }

    if (\is_array($value)) {
        if (array_is_list($value)) {
            return '<code>' . $escape(implode(', ', array_map(static fn ($item): string => \is_scalar($item) ? (string) $item : (string) json_encode($item), $value))) . '</code>';
        }

        $lines = [];

        foreach ($value as $key => $item) {
            $lines[] = '<li>' . $escape($key) . ': <code>' . $escape(\is_scalar($item) ? (string) $item : (string) json_encode($item)) . '</code></li>';
        }

        return '<ul class="values">' . implode('', $lines) . '</ul>';
    }

    $text = (string) $value;

    return '<code>' . $escape($text === '' ? '""' : $text) . '</code>';
};

// A group's price display: the settings the reset changes, one "setting: value" per line, named as the customer group
// form names them.
$fields = $this->displayFields;
$display = static function (array $values, array $keys) use ($escape, $fields): string {
    $lines = [];

    foreach ($keys as $key) {
        $field = $fields[$key] ?? null;
        $value = (string) ($values[$key] ?? '');
        $lines[] = '<li>' . $escape($field['label'] ?? $key) . ': ' . $escape($field['options'][$value] ?? $value) . '</li>';
    }

    return $lines === [] ? '' : '<ul class="values">' . implode('', $lines) . '</ul>';
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
                    <table class="table table-sm changes">
                        <thead><tr>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_SETTING'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_CURRENT'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_DEFAULT'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($options as $change) : ?>
                            <tr>
                                <td class="raw-value"><?php echo $this->escape($this->labels[$change['key']] ?? $change['key']); ?> <small class="text-muted"><code><?php echo $this->escape($change['key']); ?></code></small></td>
                                <td class="raw-value"><?php echo $show($change['current'], $change['current'] !== null); ?></td>
                                <td class="raw-value"><?php echo $show($change['default'], $change['default'] !== null); ?></td>
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
                    <table class="table table-sm changes">
                        <thead><tr>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_GROUP'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_CURRENT'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_RESET_DEFAULT'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($groups as $group) : ?>
                            <?php
                            $current = json_decode((string) $group['current']['display'], true);
                            $default = json_decode((string) $group['default']['display'], true);
                            $current = \is_array($current) ? $current : [];
                            $default = \is_array($default) ? $default : [];
                            $changed = array_values(array_filter(
                                array_unique([...array_keys($current), ...array_keys($default)]),
                                static fn ($key): bool => (string) ($current[$key] ?? '') !== (string) ($default[$key] ?? ''),
                            ));
                            ?>
                            <tr>
                                <td class="raw-value"><?php echo $this->escape($group['title'] !== '' ? $group['title'] : '#' . $group['id']); ?></td>
                                <td class="raw-value"><?php echo Text::_($group['current']['enable'] ? 'COM_ALFA_RESET_GROUP_OWN' : 'COM_ALFA_RESET_GROUP_FOLLOWS'); ?><?php echo $display($current, $changed); ?></td>
                                <td class="raw-value"><?php echo Text::_('COM_ALFA_RESET_GROUP_FOLLOWS'); ?><?php echo $display($default, $changed); ?></td>
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
