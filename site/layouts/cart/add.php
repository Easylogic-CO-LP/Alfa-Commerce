<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Cart region: DEFAULT MAIN slot. Renders the add-to-cart button + quantity
 * controls, with variant/stock GATING (disabled + reason hint until a complete,
 * in-stock combination). The hints (stock / select options, fill-required) are
 * rows of their own ABOVE the quantity + button row; the wrapper wraps and the
 * hints span its full width. A plugin can replace this MAIN via setCartLayout (e.g.
 * the configurator's "Personalise" link); the wrapper + child slot stay intact.
 * The button's pressed-state labels (data-label-adding / data-label-added) are
 * what addtocart.js shows while the request runs and right after it succeeds;
 * data-label-retry is what it shows when the add got no answer from the cart.
 * data-add-url is the route addtocart.js posts to: the add task in the language
 * of the request that draws the button, so a button a later request draws (a
 * lazily fetched tab, a re-drawn card) carries its own.
 *
 * @var object $displayData The item (carries ->variant and ->cartState).
 */

\defined('_JEXEC') or die;

use \Joomla\CMS\Language\Text;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Router\Route;

$item = $displayData;

// The add task's route in this request's language (never a bare index.php); the router
// memoises the build, so a listing routes it once.
$addUrl = Route::_(
    url: 'index.php?option=com_alfa&task=cart.addToCart&format=json'
        . (Multilanguage::isEnabled() ? '&lang=' . Factory::getApplication()->getLanguage()->getTag() : ''),
    xhtml: false,
);

// The whole cart-button state is the model's ($item->cartState, set in
// VariantHelper::resolve): stock buyability AND the required-fields gate. The layout
// computes nothing; it renders. A plugin-built item that skipped the model carries
// no verdict, so nothing vouches for its stock: the button renders disabled (the
// stock block marker included, so the client never re-enables it) and no stock
// text is shown.
$variant     = $item->variant ?? null;
$hasVariants = $variant && !empty($variant->variantFieldIds);
$state       = $item->cartState ?? null;

$itemSalable = $state !== null && (bool) $state->salable;
$showNotify  = $state !== null && !empty($state->showNotify);

$required           = $state->required ?? null;
$requiredChoiceIds  = $required->choiceIds  ?? [];
$requiredPayloadIds = $required->payloadIds ?? [];
$requiredMissing    = $required->missing    ?? [];
$requiredLabels     = $required->labels     ?? [];
$requiredReady      = $required->ready      ?? true;

// The two "fill required first" strings, assembled here rather than inline in the
// markup: the visible hint (missing choice labels + a "Configuration" entry for any
// payload field) and the client-side data-required-hint the script re-renders live.
$missingLabels     = array_merge($requiredMissing, array_map(static fn ($id) => $requiredLabels[$id] ?? '', $requiredPayloadIds));
$requiredHint      = ($requiredMissing !== [] || $requiredPayloadIds !== [])
    ? htmlspecialchars(Text::sprintf('COM_ALFA_CART_FILL_REQUIRED', implode(', ', $missingLabels)), ENT_QUOTES, 'UTF-8')
    : '';
$requiredHintAttr  = htmlspecialchars(
    Text::sprintf('COM_ALFA_CART_FILL_REQUIRED', implode(', ', array_merge($requiredMissing, $requiredPayloadIds !== [] ? [Text::_('COM_ALFA_CART_CONFIGURATION')] : []))),
    ENT_QUOTES,
    'UTF-8',
);
?>

<div class="item-cart-add">

	<?php if ($showNotify):    //Checking for unavailable stock ?>

        <?php // The notify flow attaches through data-action="cart-notify" once wired;
              // no inline placeholder handler ships to the storefront. ?>
        <button type="button" data-action="cart-notify">
			<?php echo Text::_("COM_ALFA_NOTIFY_ME_BUTTON_TEXT"); ?>
        </button>

	<?php else: ?>

		<?php if (!$itemSalable && $state !== null): ?>
            <span class="item-cart-hint">
				<?php // First row, above the controls (a full-width flex item). A variant
				      // selection that is merely incomplete is not "out of stock": word
				      // the hint from the variant's own completeness. ?>
				<?php echo $hasVariants && !$variant->complete
                    ? Text::_('COM_ALFA_ITEM_FIELD_SELECT_OPTIONS')
                    : Text::_('COM_ALFA_ITEM_FIELD_OUT_OF_STOCK'); ?>
            </span>
		<?php endif; ?>

        <p class="item-cart-required-hint" data-required-hint-box<?php echo $requiredReady && $requiredPayloadIds === [] ? ' hidden' : ''; ?>>
            <?php echo $requiredHint; ?>
        </p>
		<?php echo Layout::render('cart.quantity', $item); //passed data as $displayData in layout ?>

        <button class="item-cart-btn" data-action="cart-add"
                data-add-url="<?php echo htmlspecialchars(string: $addUrl, flags: ENT_QUOTES, encoding: 'UTF-8'); ?>"
                data-label-adding="<?php echo htmlspecialchars(Text::_('COM_ALFA_CART_ADDING'), ENT_QUOTES, 'UTF-8'); ?>"
                data-label-added="<?php echo htmlspecialchars(Text::_('COM_ALFA_CART_ADDED'), ENT_QUOTES, 'UTF-8'); ?>"
                data-label-retry="<?php echo htmlspecialchars(string: Text::_(string: 'COM_ALFA_CART_ADD_RETRY'), flags: ENT_QUOTES, encoding: 'UTF-8'); ?>"
                data-required-hint-prefix="<?php echo htmlspecialchars(Text::sprintf('COM_ALFA_CART_FILL_REQUIRED', ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-required-labels="<?php echo htmlspecialchars(json_encode($requiredLabels), ENT_QUOTES, 'UTF-8'); ?>"
                data-required-fields="<?php echo htmlspecialchars(implode(',', $requiredChoiceIds), ENT_QUOTES, 'UTF-8'); ?>"
                data-required-payload="<?php echo htmlspecialchars(implode(',', $requiredPayloadIds), ENT_QUOTES, 'UTF-8'); ?>"
                data-required-hint="<?php echo $requiredHintAttr; ?>"
                <?php echo $requiredReady ? '' : ' title="' . htmlspecialchars(Text::sprintf('COM_ALFA_CART_FILL_REQUIRED', implode(', ', $requiredMissing)), ENT_QUOTES, 'UTF-8') . '"'; ?>
                <?php // The stock verdict, carried for the client: gateRequired() in
                      // variant.js re-derives `disabled` from the REQUIRED-fields
                      // dimension live, so it must OR this in or it would re-enable a
                      // button the server disabled for stock (a configurator item's
                      // payload gate did exactly that). ?>
                <?php echo $itemSalable ? '' : ' data-stock-blocked="1"'; ?>
                <?php echo $itemSalable && $requiredReady ? '' : ' disabled'; ?>>
			<?php echo Text::_(!empty($item->editLine) ? 'COM_ALFA_CART_UPDATE_CART' : 'COM_ALFA_CART_ADD_TO_CART') ?>
        </button>

	<?php endif; ?>

</div>