<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The topics a webhook is told of: Joomla's checkboxes, one under the other, each with the line that says when the
 * change is told (the topic's own _DESC key, next to the name OutboundTopics::label() gives). Receives Joomla's
 * checkboxes layout data.
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Joomla\CMS\Language\Text;

extract($displayData);
?>
<fieldset id="<?php echo $id; ?>" class="<?php echo trim($class . ' checkboxes alfa-topics'); ?>"
    <?php echo $required ? 'required' : ''; ?>
    <?php echo $autofocus ? 'autofocus' : ''; ?>
    <?php echo $dataAttribute; ?>>
    <legend class="visually-hidden"><?php echo $label; ?></legend>

    <?php foreach ($options as $i => $option) : ?>
        <?php
        $topic = (string) $option->value;
        // With no stored value yet, the option's own default state decides.
        $checked = in_array($topic, $checkedOptions, true) || (!$hasValue && !empty($option->checked));
        $oid = $id . $i;
        ?>
        <div class="form-check">
            <input type="checkbox" id="<?php echo $oid; ?>" name="<?php echo $name; ?>"
                   value="<?php echo htmlspecialchars($topic, ENT_COMPAT, 'UTF-8'); ?>"
                   class="form-check-input<?php echo !empty($option->class) ? ' ' . $option->class : ''; ?>"
                   aria-describedby="<?php echo $oid; ?>-desc"
                   <?php echo $checked ? 'checked' : ''; ?>
                   <?php echo !empty($option->disable) || $disabled ? 'disabled' : ''; ?>>
            <label for="<?php echo $oid; ?>" class="form-check-label"><?php echo $option->text; ?></label>
            <div id="<?php echo $oid; ?>-desc" class="form-text"><?php echo Text::_(OutboundTopics::label(topic: $topic) . '_DESC'); ?></div>
        </div>
    <?php endforeach; ?>
</fieldset>
