<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The builder's Items region: the Order screen's item table, for a live cart.
 *
 * Deliberately the same screen, built the same way as tmpl/order/edit_order_items.php:
 * an "Add item" button opening a popup, one row per line with its configuration
 * printed beneath it, and an Edit action opening that line in the same popup,
 * where quantity, configuration and Remove all live, beside the thing they act
 * on. The popups are Joomla's own modals over a real layout, so a mutation is a
 * form post to a controller task and the page then re-renders; the browser
 * never edits a line, never computes a price and never rebuilds a row.
 *
 * Every figure on this table is read straight off the settled snapshot's own
 * priced line (nothing is computed here), and each line's chosen configuration
 * is printed by the one selection template the Summary tab also uses.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$snapshot = $this->builderSnapshot;
$cartId   = (int) $this->builderCartId;

// The line popup and every line mutation ask carts checkout, and Carts: Edit on
// a cart a storefront shopper created; without them the Edit action is the
// column's only content, so the column is dropped with it.
$columns = $this->canOperate ? 8 : 7;

/** One popup URL, for adding (line 0) or for re-configuring an existing line. */
$popupUrl = static fn (int $lineId): string => Route::_(sprintf(
    'index.php?option=com_alfa&view=cart&layout=builder_line&tmpl=component&cart_id=%d&line_id=%d',
    $cartId,
    $lineId,
));
?>

<div class="row mt-2">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">
                <span class="icon-cube me-2" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_CART_BUILDER_TAB_ITEMS'); ?>
            </h3>

            <?php // The popup and cart.addLine both ask carts checkout, and Carts: Edit
                  // on a shopper's cart. ?>
            <?php if ($this->canOperate) : ?>
                <a href="#addCartLineModal" data-bs-toggle="modal" class="btn btn-success">
                    <span class="icon-plus" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_CART_BUILDER_ADD_ITEM'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($snapshot === null) : ?>
    <div class="alert alert-warning"><?php echo Text::_('COM_ALFA_CART_NOT_FOUND'); ?></div>
<?php elseif ($snapshot->isEmpty()) : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_ALFA_CART_BUILDER_ITEMS_EMPTY'); ?></div>
<?php else : ?>
    <table class="table table-striped table-bordered align-middle">
        <thead>
            <tr>
                <th><?php echo Text::_('COM_ALFA_CART_TABLE_NAME'); ?></th>
                <th class="text-center"><?php echo Text::_('COM_ALFA_CART_TABLE_QUANTITY'); ?></th>
                <th class="text-end"><?php echo Text::_('COM_ALFA_CART_BUILDER_COL_UNIT_EXCL'); ?></th>
                <th class="text-end"><?php echo Text::_('COM_ALFA_CART_BUILDER_COL_UNIT_INCL'); ?></th>
                <th class="text-end"><?php echo Text::_('COM_ALFA_CART_TABLE_TAX'); ?></th>
                <th class="text-end"><?php echo Text::_('COM_ALFA_CART_TABLE_DISCOUNT'); ?></th>
                <th class="text-end"><?php echo Text::_('COM_ALFA_CART_TABLE_TOTAL'); ?></th>
                <?php if ($this->canOperate) : ?>
                    <th><?php echo Text::_('COM_ALFA_ACTIONS'); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($snapshot->lines as $line) :
            $lineId      = (int) $line->id;
            $modalId     = 'cartLineModal-' . $lineId;
            $unavailable = !empty($line->unavailable);

            // The line's OWN priced result. A stranded line has none, which is
            // exactly why it contributes nothing to any total.
            $price = $line->data->price ?? null;
            $price = \is_object($price) ? $price : null;

            // The em dash is what an UNPRICED line reads: a line whose item left
            // the catalogue has no offer to price, so no money column applies.
            $dash = '<span class="text-muted">&mdash;</span>';

            // The same name, and the same fallback, the Summary tab prints: an
            // item with no name in this language is still a line an operator
            // must be able to see and act on.
            $lineName = (string) ($line->data->name ?? '') !== ''
                ? (string) $line->data->name
                : Text::sprintf('COM_ALFA_CART_DETAIL_ITEM_ID', (int) $line->id_item);

            // The one semantic configuration renderer, from the same read model
            // the Summary tab prints.
            $this->lineSelections = $this->builderSelections[$lineId] ?? [];
            ?>
            <tr class="<?php echo $unavailable ? 'table-warning' : ''; ?>" data-line-id="<?php echo $lineId; ?>">
                <td>
                    <div class="fw-semibold"><?php echo $this->escape($lineName); ?></div>

                    <?php if ($unavailable) : ?>
                        <div class="small mt-1">
                            <span class="badge bg-danger"><?php echo Text::_('COM_ALFA_CART_DETAIL_ITEM_UNAVAILABLE'); ?></span>
                            <span class="text-danger"><?php echo $this->escape($line->unavailable_reason ?? ''); ?></span>
                        </div>
                    <?php endif; ?>
                </td>

                <td class="text-center"><?php echo (int) $line->quantity; ?></td>

                <?php // Each unit by the one unit rule, from the line's own whole amount and
                      // quantity; on a negotiated line the gross unit is the agreed rate. ?>
                <td class="text-end">
                    <?php echo $price !== null ? $this->escape(PriceUnits::line(price: $price, withTax: false)) : $dash; ?>
                </td>

                <td class="text-end">
                    <?php echo $price !== null ? $this->escape(PriceUnits::line(price: $price, withTax: true)) : $dash; ?>
                </td>

                <td class="text-end">
                    <?php echo $price !== null ? $this->escape($price->getTaxTotal()->format()) : $dash; ?>
                </td>

                <td class="text-end">
                    <?php // '-' means priced with no discount; the em dash means
                          // unpriced, where the column does not apply at all. ?>
                    <?php if ($price === null) : ?>
                        <?php echo $dash; ?>
                    <?php elseif ($price->hasDiscount()) : ?>
                        <span class="text-success">&minus;<?php echo $this->escape($price->getSavingsTotal()->format()); ?></span>
                    <?php elseif ($price->hasExplicitPrice()) : ?>
                        <?php // ITS OWN SURFACE, NOT THE DISCOUNT ONE. A negotiated
                              // price is not a promotional adjustment, and the ruling
                              // requires all three concepts to stay distinguishable:
                              // individual promotional adjustments, the price
                              // override, and the aggregate whole-line saving.
                              //
                              // The saving is READ OFF THE RESULT, never computed
                              // here: subtracting two Money objects in a view is
                              // what two standing negative arms forbid, and the
                              // result already carries the floored figure. ?>
                        <span class="badge bg-info-subtle text-info-emphasis">
                            <?php echo $this->escape(Text::_('COM_ALFA_PRICE_CUSTOM_LINE_SAVING')); ?>
                            &minus;<?php echo $this->escape($price->getLineSavingWithTax()->format()); ?>
                        </span>
                    <?php else : ?>
                        -
                    <?php endif; ?>
                </td>

                <td class="text-end fw-semibold">
                    <?php if ($price === null) : ?>
                        <?php echo $dash; ?>
                    <?php else : ?>
                        <?php if ($price->hasDiscount()) : ?>
                            <div class="text-muted text-decoration-line-through small">
                                <?php echo $this->escape($price->getBaseTotalWithTax()->format()); ?>
                            </div>
                        <?php elseif ($price->hasExplicitPrice()) : ?>
                            <?php // The CANONICAL amount struck through (what the
                                  // ordinary pricing would have charged), with the
                                  // operator's figure below it. getReference() is
                                  // that amount; getBaseTotalWithTax() is the
                                  // UNDISCOUNTED one, and on a line that carried a
                                  // catalogue sale before it was negotiated those
                                  // are different numbers. ?>
                            <div class="text-muted text-decoration-line-through small">
                                <?php echo $this->escape($price->getReference()->getTotal()->format()); ?>
                            </div>
                        <?php endif; ?>
                        <?php echo $this->escape($price->getTotal()->format()); ?>
                    <?php endif; ?>
                </td>

                <?php // The builder_line popup (403 otherwise) and the line mutations ask
                      // carts checkout, and Carts: Edit on a shopper's cart. ?>
                <?php if ($this->canOperate) : ?>
                    <td>
                        <div class="btn-group" role="group">
                            <a href="#<?php echo $modalId; ?>" data-bs-toggle="modal" role="button"
                               class="btn btn-sm btn-primary">
                                <span class="icon-edit" aria-hidden="true"></span>
                                <?php echo Text::_('JACTION_EDIT'); ?>
                            </a>
                        </div>

                        <?php echo HTMLHelper::_(
                            'bootstrap.renderModal',
                            $modalId,
                            [
                                'title'      => Text::_('COM_ALFA_CART_BUILDER_LINE_EDIT')
                                    . ' - ' . $this->escape($lineName),
                                'url'        => $popupUrl($lineId),
                                'width'      => '80%',
                                'bodyHeight' => '70vh',
                            ],
                        ); ?>
                    </td>
                <?php endif; ?>
            </tr>

            <?php if ($this->lineSelections !== []) : ?>
                <tr class="alfa-builder-line-config">
                    <td colspan="<?php echo $columns; ?>" class="small text-muted ps-4 py-1">
                        <?php echo $this->loadTemplate('selection'); ?>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="6" class="text-end"><?php echo Text::_('COM_ALFA_CART_BUILDER_GOODS_SUBTOTAL'); ?></th>
                <th class="text-end"><?php echo $this->escape($snapshot->totals->goods->format()); ?></th>
                <?php if ($this->canOperate) : ?>
                    <th></th>
                <?php endif; ?>
            </tr>
        </tfoot>
    </table>
<?php endif; ?>

<?php // The Add popup: the same layout, opened on no line (line 0). ?>
<?php if ($this->canOperate) : ?>
    <?php echo HTMLHelper::_(
        'bootstrap.renderModal',
        'addCartLineModal',
        [
            'title'      => Text::_('COM_ALFA_CART_BUILDER_LINE_NEW'),
            'url'        => $popupUrl(0),
            'width'      => '80%',
            'bodyHeight' => '70vh',
        ],
    ); ?>
<?php endif; ?>

<?php // Every modal this handler drives is drawn above only for an operator who
      // may work the cart, so the handler itself belongs to that operator too. ?>
<?php if ($this->canOperate) : ?>
<script>
    /**
     * Cart Line Modal Communication Handler
     *
     * Listens for the popup's return page, exactly as the Order screen listens
     * for its own item popup: close the modal it came from, then re-render the
     * page so what is on screen is server-rendered state.
     */
    (function () {
        'use strict';

        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin) {
                return;
            }

            if (!event.data || event.data.messageType !== 'alfa:cart-line-action') {
                return;
            }

            if (event.data.shouldClose) {
                closeModal(parseInt(event.data.lineId, 10) || 0);
            }

            if (event.data.shouldReload) {
                reloadBuilder();
            }
        });

        function closeModal(lineId) {
            // The popup reports the line it was OPENED on, so 0 is the Add popup.
            var modalEl = document.getElementById(lineId === 0 ? 'addCartLineModal' : 'cartLineModal-' + lineId);
            var bsModal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;

            if (bsModal) {
                bsModal.hide();
            }
        }

        function reloadBuilder() {
            // The builder reads its cart from the server on every render, so a
            // re-render IS the refresh: there is no unsaved form state to post
            // back the way the order edit screen has.
            if (window.Alfa && Alfa.loader && typeof Alfa.loader.showThen === 'function') {
                // Painted first, then navigated; see Alfa.loader.showThen().
                Alfa.loader.showThen(function () { window.location.reload(); });

                return;
            }

            window.location.reload();
        }
    }());
</script>
<?php endif; ?>
