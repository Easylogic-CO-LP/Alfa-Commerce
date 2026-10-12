<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Cart rules progress: the nudge surface. Renders the engine's
 * distance-to-eligibility structure for the near-miss rules worth showing
 * (already filtered by CartRules::displayableProgress()):
 *
 *   - amount goals   -> milestone bar ("X more to unlock ...")
 *   - group goals    -> checklist rows (✔ / ○ + "add N more")
 *
 * Heterogeneous conditions are never blended into one percentage. Applied
 * rules do not appear here; their totals row is the celebration. Reusable:
 * the cart page and the mini-cart render the same layout.
 *
 * @var array $displayData ['rules' => object[] (pre-filtered engine structure),
 *                          'currency' => \Alfa\Component\Alfa\Site\Service\Pricing\Currency]
 */

use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

$rules    = $displayData['progress'] ?? [];
$currency = $displayData['currency'] ?? null;

if ($rules === [] || $currency === null) {
    return;
}

$money = static fn (float $amount): string => Money::of($amount, $currency)->format();
?>
<div class="cart-rules-progress">
    <?php foreach ($rules as $rule): ?>
        <div class="cart-rule-goal" style="margin:0.8em 0;padding:0.7em 0.9em;border:1px dashed #c7c7c7;border-radius:8px;">
            <div class="cart-rule-goal-title" style="font-weight:600;margin-bottom:0.35em;">
                🔒 <?php echo htmlspecialchars($rule->name, ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <?php // Amount goal -> milestone bar?>
            <?php if ($rule->min_amount !== null && !$rule->min_amount->satisfied):
                // The two amounts the engine compared, in minor units: the bar
                // is full only when nothing remains, and the goal shown is the
                // amount that reaches the rule.
                $current = Money::of((float) $rule->min_amount->current, $currency);
                $target = $current->add(Money::of((float) $rule->min_amount->remaining, $currency));
                $percent = $target->getMinorUnits() > $current->getMinorUnits()
                    ? min(99, intdiv(max(0, $current->getMinorUnits()) * 100, $target->getMinorUnits()))
                    : 100;
                ?>
                <div class="cart-rule-goal-amount">
                    <div style="font-size:0.85em;margin-bottom:0.25em;">
                        <?php echo Text::sprintf('COM_ALFA_CARTRULE_PROGRESS_AMOUNT_REMAINING', $money((float) $rule->min_amount->remaining)); ?>
                    </div>
                    <div class="cart-rule-goal-bar" style="height:8px;border-radius:999px;background:#e8e8e8;overflow:hidden;">
                        <div style="height:100%;width:<?php echo $percent; ?>%;background:#4f7c4f;"></div>
                    </div>
                    <div style="font-size:0.75em;color:#777;margin-top:0.2em;">
                        <?php echo $current->format(); ?> / <?php echo $target->format(); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php // Group goals -> checklist rows?>
            <?php if (!empty($rule->groups)): ?>
                <ul class="cart-rule-goal-checklist" style="list-style:none;margin:0.3em 0 0;padding:0;font-size:0.85em;">
                    <?php foreach ($rule->groups as $i => $group): ?>
                        <li style="margin:0.15em 0;<?php echo $group->satisfied ? 'color:#4f7c4f;' : ''; ?>">
                            <?php echo $group->satisfied ? '✔' : '○'; ?>
                            <?php if ($group->satisfied): ?>
                                <?php echo Text::sprintf('COM_ALFA_CARTRULE_PROGRESS_GROUP_DONE', $i + 1); ?>
                            <?php else: ?>
                                <?php echo Text::plural('COM_ALFA_CARTRULE_PROGRESS_GROUP_ADD', (int) $group->remaining, $i + 1); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
