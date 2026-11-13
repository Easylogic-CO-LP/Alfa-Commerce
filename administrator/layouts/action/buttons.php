<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * Footer buttons for an action popup, declared on the event via addButton().
 * Each button maps to an AlfaAction behaviour via data-attributes:
 *   - has 'action' -> data-alfa-run  (load that action in THIS popup)
 *   - 'reload'     -> data-alfa-reload (close + refresh)
 *   - otherwise    -> data-alfa-close  (just close)
 *
 * @var array $displayData ['buttons' => array]
 */
$buttons = (array) ($displayData['buttons'] ?? []);

if (!$buttons) {
    return;
}

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// The footer's own stylesheet (media/css/admin/action-page.css): pinned at the bottom of the popup.
Factory::getApplication()->getDocument()->getWebAssetManager()->useStyle('com_alfa.action-page');
?>
<div class="alfa-action-buttons">
    <?php foreach ($buttons as $btn) : ?>
        <?php
        $label = (string) ($btn['label'] ?? Text::_('JCLOSE'));
        $class = (string) ($btn['class'] ?? 'btn-primary');
        $action = $btn['action'] ?? null;

        if ($action) {
            $hook = 'data-alfa-run="' . $esc($action) . '"'
                . ' data-alfa-data="' . $esc(json_encode((object) ($btn['data'] ?? []))) . '"';
        } elseif (!empty($btn['reload'])) {
            $hook = 'data-alfa-reload';
        } else {
            $hook = 'data-alfa-close';
        }
        ?>
        <button type="button" class="btn <?php echo $esc($class); ?>" <?php echo $hook; ?>>
            <?php echo $esc($label); ?>
        </button>
    <?php endforeach; ?>
</div>
