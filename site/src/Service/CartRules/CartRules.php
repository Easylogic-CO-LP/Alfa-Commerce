<?php

/**
 * The cart's rules and coupons: the memo, the lifecycle, and the engine call.
 *
 * WHY THIS IS NOT PART OF CartRulesEngine. The engine is a per-call evaluator:
 * it has no constructor, its two private fields (`$codeOutcomes`, `$currency`)
 * are populated at the top of evaluate(), and every caller builds a throwaway
 * instance with `new CartRulesEngine()`. Request-scoped memo state put inside it
 * would either never survive, or would force callers to start sharing an
 * instance, which is a different contract for a class that does not have one
 * today. So the evaluator stays exactly as it is and the state that surrounds it
 * lives here.
 *
 * WHY IT DOES NOT TAKE THE CART. A rules owner that holds the cart it serves
 * can reach anything, and the point of separating it is that it cannot. It is
 * given the four things the rules genuinely need from the cart (the money
 * context, the shipping gross totals, the line snapshot and the stamp) as
 * closures resolved at the moment of use. Closures rather than values because
 * the cart id and the totals are not settled when this object is built: a cart
 * can be discovered, adopted and re-pointed within one request.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\CartRules;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartPersistenceException;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Closure;
use Joomla\CMS\Log\Log;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * @since  1.10.0
 */
final class CartRules
{
    /**
     * The evaluated result for this request, or null before the first ask.
     *
     * @since  1.10.0
     */
    private ?object $result = null;

    /**
     * The codes this cart holds, read once per request.
     *
     * @var string[]|null
     * @since  1.10.0
     */
    private ?array $codes = null;

    /**
     * @param CartRepository $repository Owns the coupon rows, read and written.
     * @param Closure $cartId (): int
     * @param Closure $customer (): User - the customer the money follows.
     * @param Closure $lines (): array - the rule line snapshot.
     * @param Closure $currency (): Currency
     * @param Closure $shippingGross (): float - carrier price, tax incl.
     * @param Closure $shippingGrossExcl (): float - carrier price, tax excl.
     * @param Closure $touch (): void - stamp the cart.
     *
     * @since  1.10.0
     */
    public function __construct(
        private CartRepository $repository,
        private Closure $cartId,
        private Closure $customer,
        private Closure $lines,
        private Closure $currency,
        private Closure $shippingGross,
        private Closure $shippingGrossExcl,
        private Closure $touch,
    ) {
    }

    /**
     * Everything the rules engine concluded about this cart, once per request.
     *
     * @return object The engine's result object.
     *
     * @since  1.10.0
     */
    public function result(): object
    {
        if ($this->result !== null) {
            return $this->result;
        }

        // The carrier's price is read BEFORE the engine runs, so the degraded
        // answer below can still report it. It is computed on a different path
        // (computeShipmentCosts()) that deliberately does not consult the rules
        // engine, so whatever makes rule evaluation fail usually leaves it
        // intact.
        $shippingIncl = 0.0;
        $shippingExcl = 0.0;

        try {
            $shippingIncl = ($this->shippingGross)();
            $shippingExcl = ($this->shippingGrossExcl)();

            $user = ($this->customer)();

            return $this->result = (new CartRulesEngine())->evaluate(
                ($this->lines)(),
                (object) [
                    'userId' => (int) ($user->id ?? 0),
                    // The groups the customer is priced with: a rule limited
                    // to a group reaches whoever that group's prices reach.
                    'userGroups' => PriceContext::customerGroups(customer: $user),
                    'codes' => $this->codes(),
                    // GROSS on purpose: getShipmentTotal() is now net of these
                    // very rules, so passing it would recurse and compound.
                    'shipping_tax_incl' => $shippingIncl,
                    'shipping_tax_excl' => $shippingExcl,
                    // The engine's rounding contract: every discount it emits
                    // is quantized to THIS currency's scale at computation.
                    'currency' => ($this->currency)(),
                ],
            );
        } catch (Throwable $e) {
            Log::add('Cart rules evaluation failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.cartrules');

            // DEGRADE TO THE ENGINE'S OWN NO-RULES ANSWER, not to a smaller
            // object. The hand-built one this replaces carried three of the
            // five keys, and `shipping` (one of the two it dropped) is
            // dereferenced with no guard by getShipmentTotal(),
            // getShipmentTotalExcl() and getShippingBenefit(). Money::of()
            // takes a `float` and getShippingBenefit() returns an `object`, so
            // the missing key did not degrade anything: it raised a TypeError.
            // mod_alfa_cart's template calls two of those three, so a published
            // mini-cart turned every page into a 500 for as long as rule
            // evaluation was failing: the opposite of what this catch is for.
            return $this->result = (new CartRulesEngine())->neutralResult((object) [
                'shipping_tax_incl' => $shippingIncl,
                'shipping_tax_excl' => $shippingExcl,
            ]);
        }
    }

    /**
     * The rules that actually applied.
     *
     *
     * @since  1.10.0
     */
    public function applied(): array
    {
        return $this->result()->applied;
    }

    /**
     * Every rule's progress, satisfied or not.
     *
     *
     * @since  1.10.0
     */
    public function progress(): array
    {
        return $this->result()->progress;
    }

    /**
     * The progress rows worth showing a shopper.
     *
     *
     * @since  1.10.0
     */
    public function displayableProgress(): array
    {
        return array_values(array_filter(
            $this->progress(),
            static fn (object $rule): bool => !$rule->satisfied && ($rule->highlight || $rule->code !== null),
        ));
    }

    /**
     * What the applied rules take off the cart, tax included.
     *
     *
     * @since  1.10.0
     */
    public function total(): Money
    {
        $currency = ($this->currency)();
        $total = Money::zero($currency);

        foreach ($this->applied() as $applied) {
            // Shipping rules are a shipping BENEFIT, already reflected in
            // getShipmentTotal(). Counting them here would subtract them twice.
            if ($applied->discountClass === 'shipping') {
                continue;
            }

            $total = $total->add($applied->discountTaxIncl);
        }

        return $total;
    }

    /**
     * The same, tax excluded.
     *
     *
     * @since  1.10.0
     */
    public function totalExcl(): Money
    {
        $currency = ($this->currency)();
        $total = Money::zero($currency);

        foreach ($this->applied() as $applied) {
            if ($applied->discountClass === 'shipping') {
                continue;
            }

            $total = $total->add($applied->discountTaxExcl);
        }

        return $total;
    }

    /**
     * The codes this cart holds.
     *
     * @return string[]
     *
     * @since  1.10.0
     */
    public function codes(): array
    {
        if ($this->codes !== null) {
            return $this->codes;
        }

        $cartId = (int) ($this->cartId)();

        if ($cartId <= 0) {
            return $this->codes = [];
        }

        try {
            $codes = $this->repository->loadCouponCodes($cartId);
        } catch (Throwable $e) {
            // NEVER throw from here. result() calls this inside its own try,
            // where an exception degrades the whole cart to "no rules", and the
            // mini-cart renders on every page. An unreadable coupon store costs
            // the shopper their discount, not their session.
            AlfaLog::register();
            Log::add('Cart #' . $cartId . ' coupon codes could not be read: ' . $e->getMessage(), Log::WARNING, 'com_alfa.cartrules');

            return $this->codes = [];
        }

        return $this->codes = $codes;
    }

    /**
     * Enter a code, and answer with what it turned out to be.
     *
     * @param string $code The code as typed.
     *
     * @return string A CouponOutcome constant.
     *
     * @since  1.10.0
     */
    public function apply(string $code): string
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return CouponOutcome::NOT_FOUND;
        }

        if (\in_array($code, $this->codes(), true)) {
            // Already entered: report what it is currently doing rather than
            // silently claiming success.
            return $this->result()->code_outcomes[$code] ?? CouponOutcome::APPLIED;
        }

        $codes = array_merge($this->codes(), [$code]);
        $user = ($this->customer)();

        $probe = (new CartRulesEngine())->evaluate(
            ($this->lines)(),
            (object) [
                'userId' => (int) ($user->id ?? 0),
                'userGroups' => PriceContext::customerGroups(customer: $user),
                'codes' => $codes,
                'shipping_tax_incl' => ($this->shippingGross)(),
                'shipping_tax_excl' => ($this->shippingGrossExcl)(),
                'currency' => ($this->currency)(),
            ],
        );

        // The engine now says WHY. A code that is merely out-competed, or whose
        // conditions are not met yet, is a perfectly valid code; only hard
        // rejections refuse storage.
        $outcome = $probe->code_outcomes[$code] ?? CouponOutcome::NOT_FOUND;

        if (!CouponOutcome::isStored($outcome)) {
            return $outcome;
        }

        $cartId = (int) ($this->cartId)();

        if ($cartId <= 0) {
            return $outcome;
        }

        // STAMP BEFORE THE INSERT. The INSERT takes a shared lock on the parent
        // cart row for the foreign key check, so stamping afterwards would ask
        // for an exclusive lock on a row this transaction already holds a
        // shared one on: a lock upgrade, and a deadlock partner.
        ($this->touch)();

        try {
            // A code the cart already holds is not a failure: two tabs entering
            // the same one is an ordinary race, and the row the shopper wanted
            // exists either way. The repository tells them apart, because doing
            // so means reading the driver's own words.
            $this->repository->holdCoupon($cartId, $code);
        } catch (CartPersistenceException $e) {
            AlfaLog::register();
            Log::add(
                'Cart #' . $cartId . ' could not hold coupon "' . $code . '": ' . $e->getLogDetail(),
                Log::WARNING,
                'com_alfa.cartrules',
            );

            // 1452 is the cart being deleted by a concurrent placement between
            // the probe and this write: the shopper has just ordered and has
            // no cart to hold anything. Reported as NOT_FOUND because that is
            // the only outcome meaning "you do not have this code"; a storefront
            // 500 is the one answer that would be wrong.
            return CouponOutcome::NOT_FOUND;
        }

        $this->forget();

        return $outcome;
    }

    /**
     * Drop a code the cart holds.
     *
     * @param string $code The code as typed.
     *
     *
     * @since  1.10.0
     */
    public function remove(string $code): void
    {
        $code = strtoupper(trim($code));
        $cartId = (int) ($this->cartId)();

        if ($code === '' || $cartId <= 0) {
            return;
        }

        try {
            $this->repository->dropCoupon($cartId, $code);
        } catch (CartPersistenceException $e) {
            AlfaLog::register();
            Log::add(
                'Cart #' . $cartId . ' could not drop coupon "' . $code . '": ' . $e->getLogDetail(),
                Log::WARNING,
                'com_alfa.cartrules',
            );

            return;
        }

        ($this->touch)();

        $this->forget();
    }

    /**
     * Forget what was read and concluded, so the next ask recomputes.
     *
     * Emptying a cart invalidates both from outside this object, which is why
     * this is public: the codes are gone with the lines, and a result computed
     * from them describes a cart that no longer exists.
     *
     *
     * @since  1.10.0
     */
    public function forget(): void
    {
        $this->codes = null;
        $this->result = null;
    }
}
