<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The Reach card of a saved discount rule: how many published products its categories and manufacturers take in
 * (the product list narrowed to them), the products of its manufacturers its categories leave out, and the rules it
 * stacks with on a product, each with the count. Read only; counted from the saved rule by DiscountReach.
 *
 * @var array $displayData
 *   - array $reach What DiscountReach::of() answered.
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Pricing\RulePeriod;
use Joomla\CMS\Language\Text;

$reach = (array) $displayData['reach'];
$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$products = Text::plural('COM_ALFA_DISCOUNT_REACH_PRODUCTS', (int) $reach['reaches']);
?>
<fieldset class="adminform" id="discount-reach">
    <legend><?php echo Text::_('COM_ALFA_DISCOUNT_REACH'); ?></legend>
    <ul class="list-unstyled mb-0 small">
        <li class="mb-2">
            <?php if ((int) $reach['reaches'] > 0) : ?>
                <a href="<?php echo $e((string) $reach['url']); ?>"><?php echo $e($products); ?></a>
            <?php else : ?>
                <?php echo $e($products); ?>
            <?php endif; ?>
            <?php if (!$reach['published']) : ?>
                <span class="d-block text-muted"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_UNPUBLISHED'); ?></span>
            <?php elseif ($reach['period'] === RulePeriod::EXPIRED) : ?>
                <span class="d-block text-muted"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_EXPIRED'); ?></span>
            <?php elseif ($reach['period'] === RulePeriod::UPCOMING) : ?>
                <span class="d-block text-muted"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_UPCOMING'); ?></span>
            <?php endif; ?>
        </li>

        <?php if ($reach['outside'] !== null && (int) $reach['outside'] > 0) : ?>
            <li class="mb-2">
                <a href="<?php echo $e((string) $reach['outside_url']); ?>"><?php echo $e(Text::plural('COM_ALFA_DISCOUNT_REACH_OUTSIDE', (int) $reach['outside'])); ?></a>
            </li>
        <?php endif; ?>

        <?php if ((int) $reach['behavior'] === 0) : ?>
            <li class="mb-2 text-muted"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_ONLY_THIS'); ?></li>
        <?php elseif ($reach['stacks'] === []) : ?>
            <li class="mb-2 text-muted"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_STACKS_NONE'); ?></li>
        <?php else : ?>
            <?php foreach ($reach['stacks'] as $stack) : ?>
                <li class="mb-2">
                    <?php echo $e(Text::plural('COM_ALFA_DISCOUNT_REACH_STACKS', (int) $stack['count'], (string) $stack['name'])); ?>
                    <a href="<?php echo $e((string) $stack['url']); ?>"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_OPEN_RULE'); ?></a>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ((int) $reach['more'] > 0) : ?>
            <li class="mb-2 text-muted"><?php echo $e(Text::sprintf('COM_ALFA_DISCOUNT_REACH_MORE_RULES', (int) $reach['more'], (int) $reach['cap'])); ?></li>
        <?php endif; ?>

        <li class="text-muted"><?php echo Text::_('COM_ALFA_DISCOUNT_REACH_NOTE'); ?></li>
    </ul>
</fieldset>
