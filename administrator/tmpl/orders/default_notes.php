<?php
/**
 * Orders List — Detail Panel: Notes
 *
 * Renders the notes section inside the detail panel.
 * Shows customer-visible note and internal (admin) note
 * when either is present.
 *
 * Receives per-row data via:
 *   $this->currentItem — order object (reads customer_note, note)
 *
 * @package    Com_Alfa
 * @subpackage Administrator.View.Orders
 * @version    8.0.0
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2026 Easylogic CO LP
 * @license    GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$item = $this->currentItem;
?>
<div class="dp-section">

    <div class="dp-hdr dp-hdr-notes">
        <?php echo Text::_('COM_ALFA_NOTES'); ?>
    </div>

    <?php if (!empty($item->customer_note)) : ?>
        <div class="dp-note-block">
            <strong><?php echo Text::_('COM_ALFA_CUSTOMER_NOTE'); ?>:</strong>
            <span class="text-muted"><?php echo $this->escape($item->customer_note); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($item->note)) : ?>
        <div class="dp-note-block">
            <strong><?php echo Text::_('COM_ALFA_INTERNAL_NOTE'); ?>:</strong>
            <span class="text-muted"><?php echo $this->escape($item->note); ?></span>
        </div>
    <?php endif; ?>

</div>
