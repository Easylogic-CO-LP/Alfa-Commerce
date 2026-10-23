<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The typed confirmation of an action that destroys data: a label and an input that must hold the phrase exactly. One
 * layout for Clear everything, Clear Data and Remove extension and its data; the server checks the phrase again.
 *
 * @var array $displayData
 *   phrase  string  the phrase to type
 *   id      string  the input's id
 *   name    string  the field's name ("phrase" when absent)
 *   form    string  the id of the form the input belongs to when it stands outside it, as in a toolbar dialog
 *                   ("adminForm"); absent or "" inside its own form
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$raw = (string) $displayData['phrase'];
$phrase = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
// The browser reads pattern as a regular expression, so its syntax characters are escaped and the phrase matches only
// itself. Only those: the browser compiles the pattern with the v flag, which refuses any other escaped character and
// then ignores the whole pattern.
$pattern = htmlspecialchars((string) preg_replace('/[\\\\^$.*+?()[\]{}|\/]/', '\\\\$0', $raw), ENT_QUOTES, 'UTF-8');
$id = htmlspecialchars((string) $displayData['id'], ENT_QUOTES, 'UTF-8');
$name = htmlspecialchars((string) ($displayData['name'] ?? 'phrase'), ENT_QUOTES, 'UTF-8');
$form = htmlspecialchars((string) ($displayData['form'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<label for="<?php echo $id; ?>" class="form-label">
    <?php echo Text::sprintf('COM_ALFA_CLEAR_PHRASE_LABEL', '<code>' . $phrase . '</code>'); ?>
</label>
<input type="text" id="<?php echo $id; ?>" name="<?php echo $name; ?>" class="form-control mb-3" required
       pattern="<?php echo $pattern; ?>" spellcheck="false" autocomplete="off"<?php echo $form !== '' ? ' form="' . $form . '"' : ''; ?>>
