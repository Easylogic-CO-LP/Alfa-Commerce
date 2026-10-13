<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CartSurface;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * THE ADD-TO-CART CONFIRMATION CARD: what the shopper sees after pressing Add. Rendered
 * server-side, returned in the add-to-cart JSON and mounted by addtocart.js: the added line
 * with the cart totals, or the buyer messages when the add was rejected. Override
 * html/layouts/com_alfa/cart/added.php in a template to restructure or restyle it (a bar, a
 * modal, a drawer): the script builds no markup and never selects by class.
 *
 * THE CONTRACT WITH THE SCRIPT IS ATTRIBUTES, NEVER STRUCTURE:
 *   [data-cart-added]                 the root: appended to <body>, replaced on every add;
 *                                     without it nothing shows (the add still happened)
 *   data-timeout                      milliseconds before the card closes by itself;
 *                                     0 or absent = stays until closed
 *   data-state                        stamped by the script: `open`, then `closing` while leaving
 *   [data-action="cart-added-close"]  any element that closes the card (Escape always does)
 *
 * WHAT THE STYLESHEET READS (optional, an override may hard-code or drop any of them):
 *   data-placement                    top-left | top-center | top-right | bottom-left |
 *                                     bottom-center | bottom-right (absent = top-right)
 *   data-effect                       slide | fade | zoom | none (absent = fade)
 *   data-outcome                      added | rejected
 *   --cart-added-offset-y / -x        distance from the chosen edges (absent = 16px)
 * Phones (<= 600px) always get a bottom sheet.
 *
 * STYLESHEET: media/com_alfa/css/site/cart-added.css, the `com_alfa.item.addtocart` style asset.
 * It cannot be loaded from here (this layout renders inside the add-to-cart JSON, not a page),
 * so the product and listing templates load it through the `com_alfa.item.addtocart` preset. A
 * template that wants its own look re-declares that style asset (same name, own uri) in its
 * joomla.asset.json: the template registry is read after the component's, so its definition wins.
 *
 * SWITCH: Options -> Cart -> Confirmation card. Off = a successful add carries no card at all (the
 * script does nothing, the drawer still refreshes); a rejected add always carries this card.
 *
 * Display data:
 *   line           object|null  the added cart line (id, quantity, data = the priced item, surfaces)
 *   edited         bool         the line was re-configured from the cart rather than added
 *   quantity       int          the cart's total quantity after the add
 *   grandTotal     Money|null   the cart's grand total after the add
 *   priceSettings  array        price display settings for the `item.price` layout
 *   messages       array        buyer messages [{message, type}] when the add was rejected
 *   placement, effect, offsetY, offsetX, timeout   the Options -> Cart values (timeout in seconds, fractions allowed)
 *
 * @since  2.0.0
 */

$line          = $displayData['line'] ?? null;
$edited        = !empty($displayData['edited']);
$quantity      = (int) ($displayData['quantity'] ?? 0);
$grandTotal    = $displayData['grandTotal'] ?? null;
$priceSettings = $displayData['priceSettings'] ?? [];
$messages      = $displayData['messages'] ?? [];

$placement = htmlspecialchars((string) ($displayData['placement'] ?? 'top-right'), ENT_QUOTES, 'UTF-8');
$effect    = htmlspecialchars((string) ($displayData['effect'] ?? 'slide'), ENT_QUOTES, 'UTF-8');
$offsets   = htmlspecialchars(
    '--cart-added-offset-y: ' . ($displayData['offsetY'] ?? '16px') . '; --cart-added-offset-x: ' . ($displayData['offsetX'] ?? '16px') . ';',
    ENT_QUOTES,
    'UTF-8',
);
$timeout   = (int) round(max(0.0, (float) ($displayData['timeout'] ?? 6)) * 1000);

$closeLabel = htmlspecialchars(Text::_('COM_ALFA_CLOSE'), ENT_QUOTES, 'UTF-8');
$closeIcon  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
?>
<?php if ($line === null || $grandTotal === null): ?>

<div class="com-alfa cart-added" data-cart-added data-outcome="rejected" data-placement="<?php echo $placement; ?>" data-effect="<?php echo $effect; ?>" data-timeout="0" style="<?php echo $offsets; ?>" role="alert" tabindex="-1">
    <div class="cart-added-card">
        <div class="cart-added-header">
            <span class="cart-added-title"><?php echo Text::_('COM_ALFA_CART_ADD_FAILED'); ?></span>
            <button type="button" class="cart-added-close" data-action="cart-added-close" aria-label="<?php echo $closeLabel; ?>"><?php echo $closeIcon; ?></button>
        </div>

        <?php if ($messages): ?>
        <ul class="cart-added-messages">
            <?php foreach ($messages as $message): ?>
            <li data-type="<?php echo htmlspecialchars((string) ($message['type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $message['message'] ?? ''; ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div class="cart-added-actions">
            <button type="button" class="action secondary" data-action="cart-added-close"><?php echo Text::_('COM_ALFA_CLOSE'); ?></button>
        </div>
    </div>
</div>

<?php else: ?>

<?php
$item      = $line->data;
$name      = htmlspecialchars((string) ($item->name ?? ''), ENT_QUOTES, 'UTF-8');
$thumbnail = (string) ($item->medias[0]->thumbnail_url ?? '');
$surfaces  = $line->surfaces[CartSurface::Main->value] ?? [];
?>
<div class="com-alfa cart-added" data-cart-added data-outcome="added" data-placement="<?php echo $placement; ?>" data-effect="<?php echo $effect; ?>" data-timeout="<?php echo $timeout; ?>" style="<?php echo $offsets; ?>" role="status" aria-live="polite" tabindex="-1">
    <div class="cart-added-card">
        <div class="cart-added-header">
            <span class="cart-added-title"><?php echo Text::_($edited ? 'COM_ALFA_CART_UPDATED_TITLE' : 'COM_ALFA_CART_ADDED_TITLE'); ?></span>
            <button type="button" class="cart-added-close" data-action="cart-added-close" aria-label="<?php echo $closeLabel; ?>"><?php echo $closeIcon; ?></button>
        </div>

        <div class="cart-added-line">
            <?php if ($thumbnail !== ''): ?>
            <div class="cart-added-image">
                <img src="<?php echo htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo $name; ?>">
            </div>
            <?php endif; ?>
            <div class="cart-added-details">
                <div class="cart-added-name"><?php echo $name; ?></div>
                <?php if ($surfaces): ?>
                <ul class="cart-added-fields">
                    <?php foreach ($surfaces as $surface): ?>
                    <li><?php echo Layout::render($surface['layout'], $surface['layoutData'], $surface['layoutPluginType'], $surface['layoutPluginName']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <div class="cart-added-meta">
                    <span><?php echo Text::_('COM_ALFA_QUANTITY_LABEL'); ?>: <strong><?php echo (int) $line->quantity; ?></strong></span>
                    <div class="cart-added-price"><?php echo Layout::render('item.price', ['item' => $item, 'settings' => $priceSettings, 'options' => ['show_totals' => true]]); ?></div>
                </div>
            </div>
        </div>

        <div class="cart-added-summary">
            <span><?php echo Text::plural('COM_ALFA_CART_ADDED_ITEMS', $quantity); ?></span>
            <span><?php echo Text::_('COM_ALFA_CART_TOTAL'); ?>: <strong><?php echo $grandTotal->format(); ?></strong></span>
        </div>

        <div class="cart-added-actions">
            <a class="action primary" href="<?php echo Route::_('index.php?option=com_alfa&view=cart'); ?>"><?php echo Text::_('COM_ALFA_CART_VIEW_CART'); ?></a>
            <button type="button" class="action secondary" data-action="cart-added-close"><?php echo Text::_('COM_ALFA_CART_CONTINUE_SHOPPING'); ?></button>
        </div>
    </div>
</div>

<?php endif; ?>
