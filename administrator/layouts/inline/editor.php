<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Inline quick-edit EDITOR chrome: wraps the field-specific inputs with the
 * Save / Cancel action bar. Rendered on demand by <view>.inlineLoad and swapped
 * into the cell by inline-edit.js, which only binds the [data-inline-save] /
 * [data-inline-cancel] triggers. Button labels come from here (Text::_), so no
 * language string is ever fed through JS textContent (which would show entities
 * like "&amp;" literally).
 *
 * @var array $displayData
 *   - inner string  Pre-rendered field inputs HTML (e.g. inline.multilingual).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$inner = (string) ($displayData['inner'] ?? '');
?>
<div class="alfa-inline-editor">
    <?php echo $inner; ?>
    <div class="alfa-inline-actions">
        <button type="button" class="btn btn-sm btn-success" data-inline-save>
            <?php echo Text::_('COM_ALFA_INLINE_SAVE'); ?>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-inline-cancel>
            <?php echo Text::_('COM_ALFA_INLINE_CANCEL'); ?>
        </button>
    </div>
</div>
