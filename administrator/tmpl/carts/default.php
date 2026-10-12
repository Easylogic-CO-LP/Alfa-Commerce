<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Joomla\CMS\HTML\HTMLHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartActivity;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin');

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));

// Read-only by design: no checkboxes, no tasks, no edit route. A cart is the
// shopper's own working document; this screen is for reading it. Value is
// deliberately absent too: pricing a cart means hydrating every line through
// the catalogue, which is one model boot per row.
//
// Every row here is OPERATIONAL. A converted cart is deleted at placement, so
// this list is never a history of past orders: that history is the Orders
// screen, and the order carries it without needing the cart.
$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);   // END-only folding

// The instant the model counted and filtered with, not a fresh reading of the
// clock. Re-deriving it here is how a row could be badged as neither active nor
// abandoned while the tile above it counted it as one of them.
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=carts'
          . ($this->isModal ? '&tmpl=component&modal=1' : '')); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-primary">
                        <?php echo Text::sprintf('COM_ALFA_CARTS_SUMMARY_ACTIVE', (int) $this->summary['active']); ?>
                    </span>
                    <span class="badge bg-warning text-dark">
                        <?php echo Text::sprintf('COM_ALFA_CARTS_SUMMARY_ABANDONED', (int) $this->summary['abandoned'], (int) $this->abandonedAfterHours); ?>
                    </span>
                    <span class="badge bg-light text-dark border">
                        <?php echo Text::sprintf('COM_ALFA_CARTS_SUMMARY_EMPTY', (int) $this->summary['empty']); ?>
                    </span>
                </div>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                <table class="table table-striped" id="cartList">
                    <caption class="visually-hidden"><?php echo Text::_('COM_ALFA_CARTS_TITLE'); ?></caption>
                    <thead>
                    <tr>
                        <th scope="col" class="w-1 text-center">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th>

                        <th scope="col" class="w-10">
                            <?php echo Text::_('COM_ALFA_CARTS_HEADING_STATUS'); ?>
                        </th>

                        <th scope="col">
                            <?php echo Text::_('COM_ALFA_CARTS_HEADING_CUSTOMER'); ?>
                        </th>

                        <th scope="col" class="w-5 text-center d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_CARTS_HEADING_LINES'); ?>
                        </th>

                        <th scope="col" class="w-5 text-center d-none d-md-table-cell">
                            <?php echo Text::_('COM_ALFA_CARTS_HEADING_UNITS'); ?>
                        </th>

                        <th scope="col" class="w-10 d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_CARTS_HEADING_CREATED', 'a.added', $listDirn, $listOrder); ?>
                        </th>

                        <th scope="col" class="w-10">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_CARTS_HEADING_ACTIVITY', 'a.updated', $listDirn, $listOrder); ?>
                        </th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <?php
                        $hasLines = (int) ($item->line_count ?? 0) > 0;
                        $isMember = (int) ($item->id_customer ?? 0) > 0;
                        $quiet = CartActivity::bucketOf($item, $this->abandonedCutoff) === 'abandoned';
                        ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center">
                                <a href="<?php echo Route::_('index.php?option=com_alfa&view=cart&id=' . (int) $item->id); ?>">
                                    <?php echo (int) $item->id; ?>
                                </a>
                            </td>

                            <td>
                                <?php // A cart that became an order does not exist any more: placement
                                      // deletes it in the same transaction that writes the order. So the
                                      // only states here are the operational ones. ?>
                                <?php if (!$hasLines) : ?>
                                    <span class="badge bg-light text-dark border"><?php echo Text::_('COM_ALFA_CARTS_STATUS_EMPTY'); ?></span>
                                <?php elseif ($quiet) : ?>
                                    <span class="badge bg-warning text-dark"><?php echo Text::_('COM_ALFA_CARTS_STATUS_ABANDONED'); ?></span>
                                <?php else : ?>
                                    <span class="badge bg-primary"><?php echo Text::_('COM_ALFA_CARTS_STATUS_ACTIVE'); ?></span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php // A member is read from the account; a guest from the details record the
                                      // cart points at: the guest has no account, and that is the whole reason
                                      // the pointer had to become real. ?>
                                <?php if ($isMember) : ?>
                                    <div><?php echo $this->escape((string) ($item->account_name ?? '')); ?></div>
                                    <div class="small text-muted"><?php echo $this->escape((string) ($item->account_email ?? '')); ?></div>
                                <?php elseif (trim((string) ($item->guest_name ?? '')) !== '' || trim((string) ($item->guest_email ?? '')) !== '') : ?>
                                    <div>
                                        <?php echo $this->escape((string) ($item->guest_name ?? '')); ?>
                                        <span class="badge bg-light text-dark border"><?php echo Text::_('COM_ALFA_CUSTOMER_KIND_GUEST'); ?></span>
                                    </div>
                                    <div class="small text-muted"><?php echo $this->escape((string) ($item->guest_email ?? '')); ?></div>
                                <?php else : ?>
                                    <span class="text-muted"><?php echo Text::_('COM_ALFA_CARTS_ANONYMOUS'); ?></span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
                                <?php echo (int) ($item->line_count ?? 0); ?>
                            </td>

                            <td class="text-center d-none d-md-table-cell">
                                <?php echo (float) ($item->unit_count ?? 0) == (int) ($item->unit_count ?? 0)
                                    ? (int) ($item->unit_count ?? 0)
                                    : $this->escape((string) $item->unit_count); ?>
                            </td>

                            <td class="d-none d-lg-table-cell">
                                <?php echo HTMLHelper::_('date', $item->added, Text::_('DATE_FORMAT_LC4')); ?>
                            </td>

                            <td>
                                <?php echo HTMLHelper::_('date', $item->updated, Text::_('DATE_FORMAT_LC4')); ?>
                            </td><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <?php echo $this->filterForm->renderControlFields(); ?>

                <?php if ($this->canPurge) : ?>
                    <template id="joomla-dialog-purge"><?php echo $this->loadTemplate('purge_body'); ?></template>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php // START A CART FOR A CUSTOMER: the one moment a cart's customer is
      // chosen, through core Joomla's own user picker. cart.createBuilderCart
      // is the single endpoint in this component permitted to read a customer
      // from a request; every other cart endpoint takes it from the cart row. ?>
<?php if ($this->canBuild && $this->newCartForm !== null) : ?>
    <?php
    ob_start();
    ?>
    <div class="p-3">
        <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post" class="form-validate">
            <?php echo $this->newCartForm->renderField('customer_id'); ?>

            <p class="text-muted small">
                <?php echo Text::_('COM_ALFA_CART_BUILDER_NEW_GUEST_HINT'); ?>
            </p>

            <button type="submit" class="btn btn-primary">
                <span class="icon-cart" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_CART_BUILDER_NEW_SUBMIT'); ?>
            </button>

            <input type="hidden" name="task" value="cart.createBuilderCart">
            <input type="hidden" name="form" value="page">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </div>
    <?php
    $newCartBody = ob_get_clean();

    echo HTMLHelper::_('bootstrap.renderModal', 'newBuilderCartModal', [
        'title'  => Text::_('COM_ALFA_CART_BUILDER_NEW'),
        'width'  => '60%',
        'height' => 'auto',
        'modalWidth' => '60',
        'bodyHeight' => '40',
        'footer' => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
            . Text::_('JCANCEL') . '</button>',
    ], $newCartBody);
    ?>
<?php endif; ?>
