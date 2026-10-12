<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Inline multilingual EDITOR: one input per content language, rendered on
 * demand (task=<entity>.inlineLoad) and swapped into the cell by
 * media/com_alfa/js/admin/inline-edit.js. Same .ml-field markup + flags as
 * MultilingualTextField so the inline editor matches the full edit form.
 *
 * @var array $displayData
 *   - languages array<int, array{tag:string,value:string,flag:array{img:?string,sef:string,title:string}}>
 *   - single    bool  Hide flags when only one language is installed.
 */

defined('_JEXEC') or die;

$languages = $displayData['languages'] ?? [];
$single    = !empty($displayData['single']) || \count($languages) <= 1;
?>
<div class="ml-field-stack">
    <?php foreach ($languages as $language) : ?>
        <?php
            $tag   = (string) ($language['tag'] ?? '');
            $value = (string) ($language['value'] ?? '');
            $flag  = $language['flag'] ?? [];
        ?>
        <div class="ml-field-item">
            <input type="text" class="form-control form-control-sm alfa-inline-input"
                   data-name="<?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?>"
                   value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if (!$single) : ?>
                <?php if (!empty($flag['img'])) : ?>
                    <img class="ml-field-flag"
                         src="<?php echo htmlspecialchars((string) $flag['img'], ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?php echo htmlspecialchars((string) ($flag['sef'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                         title="<?php echo htmlspecialchars((string) ($flag['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <?php else : ?>
                    <span class="ml-field-flag ml-field-flag--text"><?php echo htmlspecialchars((string) ($flag['sef'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
