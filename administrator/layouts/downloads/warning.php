<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The download store's security warning, the same wherever it is said: the message of the
 * administrator pages and of the installation, the dashboard, the product's Downloads panel and the
 * Options. The warning in plain words; the nginx rule behind a "Server rule" line, in a details
 * element (no script); then where to act, the Downloads tab of the Options, which holds the store's
 * path and the switch of this check. Rendered by
 * {@see \Alfa\Component\Alfa\Administrator\Service\Download\StoreProtection::warning()}.
 *
 * The tab is named by the address's fragment: Joomla's tabs open the one whose id is the fragment,
 * and the Options give each tab the name of its fieldset. The address is built by the
 * administrator's router by name, so it is right in the installer and on the command line too.
 *
 * @var array $displayData  [
 *     'text'     => string: the warning, translated, its values escaped,
 *     'rule'     => string: the nginx rule that refuses the store,
 *     'offerOff' => bool: offer to turn the check off (not while it is off),
 * ]
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$downloadsTab = Route::link('administrator', 'index.php?option=com_config&view=component&component=com_alfa#downloads');
?>
<p class="mb-2"><?php echo $displayData['text']; ?></p>
<details class="mb-2">
    <summary><?php echo Text::_('COM_ALFA_DOWNLOADS_PROTECTION_SERVER_RULE'); ?></summary>
    <p class="mb-1"><?php echo Text::_('COM_ALFA_DOWNLOADS_PROTECTION_SERVER_RULE_DESC'); ?></p>
    <code><?php echo htmlspecialchars((string) $displayData['rule'], ENT_QUOTES, 'UTF-8'); ?></code>
</details>
<ul class="list-inline mb-2">
    <li class="list-inline-item"><a href="<?php echo $downloadsTab; ?>"><?php echo Text::_('COM_ALFA_DOWNLOADS_PROTECTION_MOVE'); ?></a></li>
    <?php if (!empty($displayData['offerOff'])) : ?>
        <li class="list-inline-item"><a href="<?php echo $downloadsTab; ?>"><?php echo Text::_('COM_ALFA_DOWNLOADS_PROTECTION_TURN_OFF'); ?></a></li>
    <?php endif; ?>
</ul>
