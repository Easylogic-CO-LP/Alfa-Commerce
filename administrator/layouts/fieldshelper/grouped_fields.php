<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var string|null              $target Slot name (informational). */
/** @var \Joomla\CMS\Form\FormField[] $static Static fields. */
/** @var array                    $groups Each: ['name','label','description','fields'=>FormField[]] */
extract($displayData);

$showonWrap = static function ($field): string {
    $html = $field->renderField();
    $name = (string) $field->getAttribute('showonname', '');

    if ($name === '') {
        return $html;
    }

    $attr  = ' data-showon-name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
    $attr .= ' data-showon-type="' . htmlspecialchars((string) $field->getAttribute('showontype', ''), ENT_QUOTES, 'UTF-8') . '"';

    $rule = (string) $field->getAttribute('showonrule', '');
    if ($rule !== '') {
        $attr .= " data-showon-rule='" . htmlspecialchars($rule, ENT_QUOTES, 'UTF-8') . "'";
    }

    return '<div' . $attr . '>' . $html . '</div>';
};
?>
<?php foreach ($static as $field): ?>
    <?php echo $showonWrap($field); ?>
<?php endforeach; ?>

<?php foreach ($groups as $group): ?>
    <fieldset class="fields-group" data-group="<?php echo htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8'); ?>">
        <?php if (!empty($group['label'])): ?>
            <legend><?php echo Text::_($group['label']); ?></legend>
        <?php endif; ?>
        <?php if (!empty($group['description'])): ?>
            <p class="description"><?php echo Text::_($group['description']); ?></p>
        <?php endif; ?>
        <?php foreach ($group['fields'] as $field): ?>
            <?php echo $showonWrap($field); ?>
        <?php endforeach; ?>
    </fieldset>
<?php endforeach; ?>