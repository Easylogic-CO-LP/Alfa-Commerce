<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartPersistenceException;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSource;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use stdClass;
use Throwable;

defined('_JEXEC') or die;

/**
 * Writes to the cart row.
 *
 * The activity stamp; the address pointers; the chosen carrier and payment
 * method, each with its own net and the invalidation a carrier implies;
 * emptying; discarding; and creating the row a first change needs. Every
 * statement is the repository's. After each write the reading is replaced
 * with what the row now says, so a reader never serves a column the write
 * just changed.
 *
 * @since  2.0.0
 */
final class CartRowWriter
{
    /** @since 2.0.0 */
    public function __construct(
        private readonly CartRepository $repository,
        private readonly CartHydrator $hydrator,
        private readonly CartComputation $computation,
        private readonly CartMessaging $messaging,
        private readonly RecognizeKey $keys,
    ) {
    }

    /**
     * Stamp the cart's activity; a cart nobody has is not stamped.
     *
     * @since  2.0.0
     */
    public function touch(): bool
    {
        $cartId = $this->cartId();

        if ($cartId <= 0) {
            return true;
        }

        $now = $this->repository->now();

        try {
            $this->repository->touch($cartId, $now);
        } catch (CartPersistenceException $e) {
            AlfaLog::register();
            Log::add('Cart #' . $cartId . ' activity stamp failed: ' . $e->getLogDetail(), Log::WARNING, 'com_alfa.orders');

            return false;
        }

        $this->hydrator->replace($this->hydrator->current()->withUpdated($now));

        return true;
    }

    /**
     * Point the cart at saved addresses.
     *
     * A storage failure is logged, not spoken: the caller has already answered
     * the shopper.
     *
     * WHERE THE ORDER IS GOING HAS JUST CHANGED, so what was computed for the
     * old destination is dropped and the reading is taken again. Mirroring the
     * pointer onto the row was not enough: the address OBJECT the reading
     * carries is the one loaded for the previous pointer, and the carriage is
     * priced against its postcode while the offered methods are filtered by its
     * country. Re-reading is what makes the next question answer about the
     * address the shopper just chose.
     *
     * @since  2.0.0
     */
    public function pointAtDetails(int $deliveryId, ?int $invoiceId = null): void
    {
        $cartId = $this->cartId();

        if ($cartId <= 0 || $deliveryId <= 0) {
            return;
        }

        try {
            $this->repository->setUserInfoPointers($cartId, $deliveryId, $invoiceId);
        } catch (CartPersistenceException $e) {
            AlfaLog::register();
            Log::add('Cart #' . $cartId . ': could not store the details pointer: ' . $e->getLogDetail(), Log::WARNING, 'com_alfa.orders');

            return;
        }

        $this->hydrator->replace($this->hydrator->current()->withPointers($deliveryId, $invoiceId));
        $this->hydrator->reload();
        $this->computation->forgetMethods();
        $this->touch();
    }

    /** @since 2.0.0 */
    public function chooseShipment(int $shipmentId): bool
    {
        return $this->applyShipment($shipmentId, $this->repository->now());
    }

    /**
     * Write the carrier. A carrier decides which payment methods remain
     * offered and what the carriage costs, so writing one forgets both.
     *
     * @since  2.0.0
     */
    public function applyShipment(int $id, string $at): bool
    {
        if ($this->cartId() <= 0 && !$this->create()) {
            return false;
        }

        try {
            $result = $this->repository->setShipmentMethod($this->cartId(), $id, $at);
        } catch (CartPersistenceException $e) {
            $this->messaging->failed('updateShipment', $this->cartId(), $e, 'COM_ALFA_CART_SHIPMENT_UPDATE_FAILED');

            return false;
        }

        if ($result) {
            $this->hydrator->replace($this->hydrator->current()->withShipment($id)->withUpdated($at));
            $this->computation->forgetMethods();
        }

        return $result;
    }

    /** @since 2.0.0 */
    public function choosePayment(int $paymentId): bool
    {
        return $this->applyPayment($paymentId, $this->repository->now());
    }

    /**
     * Write the payment method. Nothing offered depends on it, so nothing is
     * forgotten.
     *
     * @since  2.0.0
     */
    public function applyPayment(int $id, string $at): bool
    {
        if ($this->cartId() <= 0 && !$this->create()) {
            return false;
        }

        try {
            $result = $this->repository->setPaymentMethod($this->cartId(), $id, $at);
        } catch (CartPersistenceException $e) {
            $this->messaging->failed('updatePayment', $this->cartId(), $e, 'COM_ALFA_CART_PAYMENT_UPDATE_FAILED');

            return false;
        }

        if ($result) {
            $this->hydrator->replace($this->hydrator->current()->withPayment($id)->withUpdated($at));
        }

        return $result;
    }

    /**
     * Take everything out and keep the cart.
     *
     * @since  2.0.0
     */
    public function clear(): bool
    {
        try {
            $this->repository->emptyCart($this->cartId());
        } catch (CartPersistenceException $e) {
            $this->messaging->failed('clearCart', $this->cartId(), $e, 'COM_ALFA_CART_CLEAR_FAILED');

            return false;
        }

        $this->touch();
        $this->hydrator->replace($this->hydrator->current()->withLines([]));
        $this->computation->rules()->forget();

        return true;
    }

    /**
     * The cart ceases to exist: the lines first, then the row. What is left
     * reads as no cart at all.
     *
     * @since  2.0.0
     */
    public function discard(): bool
    {
        $cartId = $this->cartId();

        if ($cartId <= 0) {
            return true;
        }

        if (!$this->clear()) {
            return false;
        }

        try {
            $this->repository->deleteCart($cartId);
        } catch (CartPersistenceException $e) {
            AlfaLog::register();
            Log::add($e->getLogDetail(), Log::ERROR, 'com_alfa.orders');

            return false;
        }

        $current = $this->hydrator->current();
        $this->hydrator->replace(HydratedCart::none($current->identity()->at(0), $current->subject()));

        return true;
    }

    /**
     * Give a guest without a recognition key one, so the cart written next can
     * be found again.
     *
     * @since  2.0.0
     */
    public function mintKeyIfEmpty(): void
    {
        $current = $this->hydrator->current();

        if ((string) $current->identity()->recognizeKey !== '') {
            return;
        }

        $this->hydrator->replace($current->withIdentity($current->identity()->withRecognizeKey($this->keys->mint())));
    }

    /**
     * Create the row a change needs.
     *
     * A LOOK DOES NOT LEAVE A ROW BEHIND: a cart is created only where the
     * caller said a change was coming. A guest gets a recognition key on the
     * way, since a cart nobody can find again is a cart nobody has.
     *
     * @since  2.0.0
     */
    public function create(): bool
    {
        $current = $this->hydrator->current();
        $identity = $current->identity();

        if (!$identity->mode->mayCreate()) {
            AlfaLog::register();
            Log::add(
                'createCart refused: this cart was resolved as ' . $identity->mode->value,
                Log::WARNING,
                'com_alfa.orders',
            );

            return false;
        }

        $owner = $current->subject()->owner((string) $identity->recognizeKey);

        // WHOSE BROWSER IS THIS CART ABOUT?
        //
        // A shopper's own cart is minted FROM the shopper's browser, so a guest
        // who has no key yet is given one and recognised as holding the cart.
        //
        // A cart minted under a mode that does not discover is the back office
        // acting FOR somebody, and the only browser present is the operator's.
        // Inheriting the key that browser presented is what made "new cart" mean
        // the operator's own storefront cart, and made the second walk-in find
        // the first, so such a cart never takes it. A walk-in gets a
        // recognition identity of its own that no browser carries; a member's
        // cart gets none at all, because the account already owns it.
        if (!$identity->mode->discovers()) {
            $identity = $identity->withRecognizeKey(
                $current->subject()->customerId() > 0 ? '' : $this->keys->generate(),
            );
            $current = $current->withIdentity($identity);
            $this->hydrator->replace($current);
            $owner = $current->subject()->owner((string) $identity->recognizeKey);
        } elseif (!$owner->isMember() && $owner->recognizeKey() === '') {
            $identity = $identity->withRecognizeKey($this->keys->mint());
            $current = $current->withIdentity($identity);
            $this->hydrator->replace($current);
            $owner = $current->subject()->owner((string) $identity->recognizeKey);
        }

        $currentDate = Factory::getDate('now', 'UTC');
        $cartObject = new stdClass();
        $cartObject->id_shipment = 0;
        $cartObject->id_payment = 0;
        $cartObject->id_user_info_delivery = 0;
        $cartObject->id_user_info_invoice = 0;
        $cartObject->id_currency = self::currentCurrencyId();
        $cartObject->id_customer = $owner->customerId();
        $cartObject->added = $currentDate->toSql(false);
        $cartObject->updated = $currentDate->toSql(false);
        $cartObject->recognize_key = (string) $identity->recognizeKey;

        // WHO STARTED IT, written once and never from a request. The back office
        // minting a cart for somebody is the operator subject, and nothing else
        // is; a cart a shopper started needs Carts: Edit as well before an
        // operator may change or place it ({@see CartTakeover}).
        $cartObject->source = ($current->subject()->isOperatorPlacement() ? CartSource::Admin : CartSource::Storefront)->value;

        try {
            $cartId = $this->repository->insertCart($cartObject);
        } catch (CartPersistenceException $e) {
            AlfaLog::register();
            Log::add('createCart failed: ' . $e->getLogDetail(), Log::ERROR, 'com_alfa.orders');

            return false;
        }

        $this->hydrator->replace($current->withIdentity($identity->at($cartId)));
        $this->hydrator->reload();

        return true;
    }

    private function cartId(): int
    {
        return (int) $this->hydrator->current()->identity()->cartId;
    }

    /**
     * The currency a new cart is opened in: the session's, else the default.
     */
    private static function currentCurrencyId(): int
    {
        $sessionCurrency = (int) Factory::getApplication()->getSession()->get('alfa.currency_id', 0);

        if ($sessionCurrency > 0) {
            return $sessionCurrency;
        }

        try {
            return (int) Currency::getDefault()->getId();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
