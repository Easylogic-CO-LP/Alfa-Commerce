<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The Reach card of a saved discount rule: a frame with one button, "Count the reach", which asks the rule's own action
 * (task=discount.reach) for the counts and puts the answer, rendered by discount.reach_counts on the server, in the
 * frame. Nothing is counted while the form renders. Read only.
 *
 * @var array $displayData
 *   - int    $id    The saved rule.
 *   - string $url   The action's address.
 *   - string $token The form token's name.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('com_alfa.admin.discount-reach');
?>
<fieldset class="adminform" id="discount-reach" data-discount-reach data-id="<?php echo (int) $displayData['id']; ?>" data-url="<?php echo $e((string) $displayData['url']); ?>"
    data-token="<?php echo $e((string) $displayData['token']); ?>" data-failed="<?php echo $e(Text::_('COM_ALFA_DISCOUNT_REACH_FAILED')); ?>"
    data-counting="<?php echo $e(Text::_('COM_ALFA_DISCOUNT_REACH_COUNTING')); ?>">
    <legend><?php echo Text::_('COM_ALFA_DISCOUNT_REACH'); ?></legend>
    <div data-reach-body>
        <p class="small text-muted mb-2"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_ASK_DESC'); ?></p>
        <button type="button" class="btn btn-sm btn-secondary" data-reach-ask><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_ASK'); ?></button>
    </div>
</fieldset>
