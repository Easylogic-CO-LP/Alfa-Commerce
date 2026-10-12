<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The "there is nothing here yet, and here is why" panel for the three sections of
 * Pricing & Combinations.
 *
 * Each section is fed by item fields: variations by choice fields, options by the
 * choice fields not serving as a variant dimension, specs by informational ones.
 * With none of them the sections simply rendered nothing; a merchant looking for
 * "Generate combinations" found an empty tab and no explanation. This says what
 * the section is for, why it is empty, and offers the two ways forward: open the
 * item fields (in the action frame, so nothing typed into the product is lost) or
 * be walked through creating one.
 *
 * @var array $displayData ['message' => string  Already-translated explanation,
 *                          'tour'    => bool    Whether the item-fields tour exists,
 *                          'link'    => bool    Whether to offer the item-fields link]
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$message = (string) ($displayData['message'] ?? '');
$tour    = !empty($displayData['tour']);
$link    = $displayData['link'] ?? true;
?>
<div class="tools-empty">
    <p class="tools-hint"><?php echo $message; ?></p>
    <?php if ($link || $tour) : ?>
        <div class="tools-empty-actions">
            <?php if ($link) : ?>
                <a class="btn btn-sm btn-primary"
                   href="<?php echo Route::_('index.php?option=com_alfa&view=itemfields', false); ?>"
                   data-alfa-frame-link
                   data-frame-title="<?php echo $this->escape(Text::_('COM_ALFA_COMBI_EMPTY_OPEN_FIELDS')); ?>">
                    <?php echo Text::_('COM_ALFA_COMBI_EMPTY_OPEN_FIELDS'); ?>
                </a>
            <?php endif; ?>
            <?php if ($tour) : ?>
                <button type="button" class="btn btn-sm btn-secondary button-start-guidedtour"
                        data-gt-uid="com_alfa.item-fields">
                    <?php echo Text::_('COM_ALFA_COMBI_EMPTY_SHOW_ME'); ?>
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
