<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Summary\SummaryRow;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * THE MONEY SUMMARY: one labelled row each, under the cart lines. It receives
 * prepared rows (CartSummary::rows() on the cart page) and decides and computes
 * nothing: which rows exist, and every figure, arrive ready. Override
 * html/layouts/com_alfa/cart/summary.php in a template to restructure it.
 *
 * Classes the stylesheet reads (media/com_alfa/css/site/cart.css):
 *   .cart-summary  .summary-rows  .summary-row  .summary-<type>
 *   .summary-label  .summary-value  .summary-was  .summary-free  .summary-amount
 * A coded rule's row carries its remove form (task=cart.removeCoupon) inside its label,
 * its button named with the code (COM_ALFA_CART_COUPON_REMOVE, the code as %s).
 *
 * Display data:
 *   rows  SummaryRow[]  the rows, in display order
 *
 * @since  2.2.0
 */

/** @var SummaryRow[] $rows */
$rows = $displayData['rows'] ?? [];

if ($rows === []) {
    return;
}

$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$removeLabel = static fn (string $code): string => $escape(Text::sprintf('COM_ALFA_CART_COUPON_REMOVE', $code));

?>
<section class="cart-summary" aria-label="<?php echo $escape(Text::_('COM_ALFA_CART_SUMMARY_LABEL')); ?>">
    <dl class="summary-rows">
        <?php foreach ($rows as $row): ?>
        <div class="summary-row summary-<?php echo $escape($row->type); ?>">
            <dt class="summary-label">
                <?php echo $escape($row->label); ?>
                <?php if ($row->removeCode !== null): ?>
                <form action="<?php echo Route::_('index.php?option=com_alfa&task=cart.removeCoupon'); ?>"
                      method="post" class="cart-rule-remove-form">
                    <input type="hidden" name="coupon_code" value="<?php echo $escape($row->removeCode); ?>"/>
                    <button type="submit" class="cart-rule-remove"
                            title="<?php echo $removeLabel($row->removeCode); ?>"
                            aria-label="<?php echo $removeLabel($row->removeCode); ?>">&times;</button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
                <?php endif; ?>
            </dt>
            <dd class="summary-value">
                <?php if ($row->was !== null): ?>
                <del class="summary-was"><?php echo $row->was->format(); ?></del>
                <?php endif; ?>
                <?php if ($row->free): ?>
                <strong class="summary-free"><?php echo Text::_('COM_ALFA_FREE_SHIPPING'); ?></strong>
                <?php else: ?>
                <span class="summary-amount"><?php echo $row->reduction ? '−' : ''; ?><?php echo $row->amount->format(); ?></span>
                <?php endif; ?>
            </dd>
        </div>
        <?php endforeach; ?>
    </dl>
</section>
