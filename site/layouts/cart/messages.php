<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

/**
 * WHAT THE CART SAID ABOUT THE CHANGE JUST MADE ON THE PAGE: a refused quantity
 * and why, a quantity the cart cut to what it can hold. Rendered empty with the
 * cart page's items and with the mini-cart's drawer, and again, filled, in the
 * answer to a quantity change; the cart page's script puts that answer in place
 * of the slot, and the mini-cart's drawer arrives with it already inside. A
 * change that said nothing leaves the slot empty again.
 *
 * Override html/layouts/com_alfa/cart/messages.php in a template to restyle it.
 * THE CONTRACT WITH THE SCRIPT IS ONE ATTRIBUTE: data-cart-messages on the root,
 * which is where the next answer goes. Without it nothing is shown, and the cart
 * still reads as the server answered.
 *
 * Display data:
 *   messages  array  the queued messages [{message, type}]; each message is HTML as the queue holds it
 *
 * @since  2.0.0
 */

$messages = (array) ($displayData['messages'] ?? []);

?>
<div class="cart-messages" data-cart-messages role="status">
    <?php if ($messages): ?>
    <ul class="cart-messages-list">
        <?php foreach ($messages as $message): ?>
        <li data-type="<?php echo htmlspecialchars((string) ($message['type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $message['message'] ?? ''; ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
