<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The Reach card of a saved discount rule: what was last counted and stored, with its moment, or one button, "Count
 * the reach", which asks the rule's own action (task=discount.reach). The action estimates the work first: a small
 * reach is counted at once, a large one by the rule's job, which the card follows ("being worked out") until the
 * stored reach arrives. The counts are of the rule as last saved, and the card says so: changes typed on the form and
 * not saved are not counted. The answer's markup is rendered on the server (discount.reach_counts). Read only.
 *
 * @var array $displayData
 *   - int        $id     The saved rule.
 *   - array|null $stored The reach as last stored, or null.
 *   - string     $url    The action's address.
 *   - string     $token  The form token's name.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$stored = \is_array($displayData['stored'] ?? null) ? $displayData['stored'] : null;
Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('com_alfa.admin.discount-reach');
?>
<fieldset class="adminform" id="discount-reach" data-discount-reach data-id="<?php echo (int) $displayData['id']; ?>" data-url="<?php echo $e((string) $displayData['url']); ?>"
    data-token="<?php echo $e((string) $displayData['token']); ?>" data-failed="<?php echo $e(Text::_('COM_ALFA_DISCOUNT_REACH_FAILED')); ?>"
    data-counting="<?php echo $e(Text::_('COM_ALFA_DISCOUNT_REACH_COUNTING')); ?>" data-working="<?php echo $e(Text::_('COM_ALFA_DISCOUNT_REACH_WORKING')); ?>">
    <legend><?php echo Text::_('COM_ALFA_DISCOUNT_REACH'); ?></legend>
    <p class="small text-muted mb-2"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_SAVED'); ?></p>
    <div data-reach-body>
        <?php if ($stored !== null) : ?>
            <?php echo LayoutHelper::render('discount.reach_counts', ['reach' => $stored], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
        <?php else : ?>
            <p class="small text-muted mb-2"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_ASK_DESC'); ?></p>
        <?php endif; ?>
    </div>
    <p class="small text-muted mb-2" data-reach-working hidden></p>
    <button type="button" class="btn btn-sm btn-secondary" data-reach-ask><?php echo Text::_($stored !== null ? 'COM_ALFA_DISCOUNT_REACH_AGAIN' : 'COM_ALFA_DISCOUNT_REACH_ASK'); ?></button>
</fieldset>
