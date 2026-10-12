<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\AddToCartCommand;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\ChargeableQuantity;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceAction;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceCommand;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Joomla\CMS\Language\Text;
use Throwable;

defined('_JEXEC') or die;

/**
 * Writes to the cart's lines.
 *
 * Adding a product (which may merge into a line already carrying the same
 * configuration, or edit one), setting a line's quantity, removing a line,
 * and re-clamping lines that arrived by adoption. Every quantity is the
 * policy's decision; every statement is the repository's; after each write
 * the lines are read again so what is rendered is what was stored.
 *
 * A quantity the policy allows is still refused when the line it would leave
 * has a price and would charge nothing ({@see ChargeableQuantity}): the line
 * is priced as the cart prices its lines before anything is stored, and the
 * refusal names the smallest quantity this cart may hold of it that is
 * charged.
 *
 * @since  1.10.0
 */
final class CartLineWriter
{
    /** @since 1.10.0 */
    public function __construct(
        private readonly CartRepository $repository,
        private readonly CartHydrator $hydrator,
        private readonly CartItemLoader $items,
        private readonly CartLineConfiguration $configuration,
        private readonly LineQuantityClamp $clamp,
        private readonly CartRowWriter $rows,
        private readonly CartMessaging $messaging,
    ) {
    }

    /**
     * Put a product in the cart.
     *
     * THE WHOLE OPERATION IS INSIDE THE BOUNDARY: resolving the buyer's
     * selection, clamping it against stock and storing it all read and write,
     * and a failure in any of them answers with one sentence, never a page of
     * the driver's words.
     *
     * The stock verdict the card and the product page show for the selected
     * unit is asked first, right after the configuration is validated and
     * before anything is prepared: an unsellable unit is refused with the
     * out-of-stock sentence and no upload is stored for it. The kernel ceiling
     * then shapes the quantity of a sellable one.
     *
     * The configuration is first reduced to what the product itself offers
     * ({@see self::asOffered()}): a line is described, priced and ordered from
     * the rows stored for it, so nothing a request merely names is stored.
     * Before that, a composition, a full composition or an upload posted for a
     * field that takes none refuses the add with a sentence naming the field
     * (the invalid-selection sentence for a field the product does not have):
     * a field takes one only when its plugin implements the payload handler
     * ({@see VariantHelper::payloadFields()}).
     *
     * A line is re-configured only when it is this cart's and carries the
     * product being added. A request naming any other line is an ordinary add,
     * so the quantity and configuration decided for one product never land on
     * another product's line.
     *
     * @param AddToCartCommand $command The product, quantity and configuration to add.
     *
     * @return int|false The line it landed on, or false.
     *
     * @since  1.10.0
     */
    public function add(AddToCartCommand $command): int|false
    {
        try {
            $this->rows->mintKeyIfEmpty();

            if ($this->cartId() <= 0 && !$this->rows->create()) {
                return false;
            }

            // Adding to a cart is a purchase decision and must be admissible on
            // the catalogue's own terms: an unavailable product gives the same
            // answer as a missing one, except one withheld only for being out of
            // stock, which is refused as out of stock.
            $item = $this->items->load($command->itemId, requirePublished: true);

            if (!$item) {
                $this->refuseWithheld(itemId: $command->itemId, key: 'COM_ALFA_ERROR_ITEM_NOT_FOUND');

                return false;
            }

            // A composition is taken only by a field whose plugin turns it into
            // the stored value; one posted for any other field refuses the add.
            $refusal = $this->configuration->compositionRefusal(
                item: $item,
                request: $command->selection,
                fullPayloads: $command->fullPayloads,
                files: $command->files,
            );

            if ($refusal !== null) {
                $this->messaging->warn(sentence: $refusal);

                return false;
            }

            // Only what the product itself offers is resolved, validated and stored.
            $command = $this->asOffered(item: $item, command: $command);

            $request = $command->selection;
            $variant = VariantHelper::resolve(
                $item,
                $request['selection'],
                array_values($request['optionValueIds']),
                $request['payloads'],
                $this->hydrator->current()->subject()->priceContext(),
            );
            $combinationId = ($variant && $variant->combinationId > 0) ? $variant->combinationId : null;

            $refusal = $this->configuration->validate($item, $combinationId, $request, (float) $command->quantity);

            if ($refusal !== null) {
                $this->messaging->warn($refusal);

                return false;
            }

            // The shopper was offered what the model's verdict said; a unit that
            // is not sellable is refused before any upload is prepared.
            if (!($item->cartState->salable ?? false)) {
                $this->messaging->warn(sentence: Text::_('COM_ALFA_CART_OUT_OF_STOCK'));

                return false;
            }

            $prepared = $this->configuration->prepare($item, $request, $command->fullPayloads, $command->files);

            if (is_string($prepared)) {
                $this->messaging->warn($prepared);

                return false;
            }

            $request = $prepared;
            $rows = $this->configuration->rowsFor($item, $request);

            // WHICH LINE THIS BECOMES IS DECIDED BEFORE HOW MANY.
            //
            // The stock a request may draw on is cart-wide, so the answer
            // depends on which line the request lands on: an edit and a merge
            // both keep a line that is ALREADY HOLDING some of the same pool,
            // and a quantity decided before the branch is known counts that
            // line against itself. Editing the options of a line of six with
            // ten on the shelf then answers four, and a line sitting at the
            // cap cannot be reconfigured at all.
            //
            // So the branch is settled first and each branch makes exactly one
            // quantity decision, in its own frame: the edit excludes the line
            // it is editing, the merge asks for the resulting line total and
            // excludes the line it is merging into, and a genuinely new line
            // draws on everything the cart already holds. The rules are still
            // the policy's; this decides only what to ask it.
            //
            // An edit re-configures a line of this cart that carries this very
            // product. A line of another cart or of another product is not
            // edited: the request is then an ordinary add, and that line stays
            // as it is.
            $editLineId = $command->editLineId;
            $editedLine = $editLineId > 0
                ? $this->repository->loadLine(lineId: $editLineId, cartId: $this->cartId())
                : null;

            if ($editedLine !== null && (int) $editedLine->id_item === $command->itemId) {
                $quantity = $this->clamp->allowed(
                    $this->cartId(),
                    $item,
                    $combinationId,
                    (int) $command->quantity,
                    $editLineId,
                );

                if ($quantity <= 0) {
                    return false;
                }

                // The line keeps the negotiated price it carries.
                if (!$this->charged(item: $item, combinationId: $combinationId, configuration: $request, quantity: $quantity, price: $this->instructionOf(lineId: $editLineId), lineId: $editLineId)) {
                    return false;
                }

                try {
                    $this->repository->replaceLineConfiguration($editLineId, $combinationId, $quantity, $rows);
                } catch (Throwable $e) {
                    $this->messaging->failed('addToCart (edit line)', $this->cartId(), $e, 'COM_ALFA_CART_ITEM_UPDATE_FAILED');

                    return false;
                }

                $this->rows->touch();
                $this->hydrator->reloadLines();

                return $editLineId;
            }

            $signature = $this->configuration->signature($combinationId, $rows);
            $existingId = $this->configuration->find($this->cartId(), $command->itemId, $signature);

            try {
                if ($existingId > 0) {
                    // A MERGE ASKS TWO QUESTIONS, AND BOTH ARE ABOUT THIS LINE.
                    //
                    // The first is the merchant's: is the quantity being added
                    // one this product is sold in? That shaping is unchanged:
                    // it is what refuses "five more" of something sold in fours
                    // with a minimum of five. The second is the shelf's: may
                    // the line that will exist hold what it will hold?
                    //
                    // What was wrong was the FRAME, not the questions. Both
                    // used to be asked with the destination line counted as
                    // somebody else's draw on the same stock, so a line already
                    // holding six of the ten available was told four were left
                    // - and a request that would have fitted was cut, or
                    // refused for falling under the minimum once cut. Both now
                    // leave the line they are about out of the pool.
                    $existingQuantity = $this->repository->lineQuantity($this->cartId(), $existingId);
                    $added = $this->clamp->allowed(
                        $this->cartId(),
                        $item,
                        $combinationId,
                        (int) $command->quantity,
                        $existingId,
                    );

                    if ($added <= 0) {
                        return false;
                    }

                    $newTotal = $this->clamp->allowed(
                        $this->cartId(),
                        $item,
                        $combinationId,
                        $existingQuantity + $added,
                        $existingId,
                    );

                    // The line that would exist is judged at its total, with
                    // the negotiated price it carries.
                    if ($newTotal !== $existingQuantity && !$this->charged(item: $item, combinationId: $combinationId, configuration: $request, quantity: $newTotal, price: $this->instructionOf(lineId: $existingId), lineId: $existingId)) {
                        return false;
                    }

                    if ($newTotal !== $existingQuantity) {
                        $this->repository->setLineQuantityValue($existingId, $newTotal, $this->cartId());
                    }

                    if ($newTotal <= $existingQuantity) {
                        $this->hydrator->reloadLines();

                        return false;
                    }

                    $lineId = $existingId;
                } else {
                    $quantity = $this->clamp->allowed($this->cartId(), $item, $combinationId, (int) $command->quantity);

                    if ($quantity <= 0) {
                        return false;
                    }

                    if (!$this->charged(item: $item, combinationId: $combinationId, configuration: $request, quantity: $quantity, price: null)) {
                        return false;
                    }

                    $lineId = $this->repository->insertLine(
                        $this->cartId(),
                        $command->itemId,
                        $combinationId,
                        $quantity,
                        $rows,
                    );
                }
            } catch (Throwable $e) {
                $this->messaging->failed('addToCart', $this->cartId(), $e, 'COM_ALFA_CART_ADD_FAILED');

                return false;
            }

            $this->rows->touch();
            $this->hydrator->reloadLines();

            return $lineId;
        } catch (Throwable $e) {
            $this->messaging->failed('addToCart', $this->cartId(), $e, 'COM_ALFA_CART_ADD_FAILED');

            return false;
        }
    }

    /**
     * Set how many of one line the shopper wants. Clamped against stock and
     * the product's own rules; zero removes the line and its selections.
     *
     * A LINE WHOSE PRODUCT HAS LEFT THE CATALOGUE IS REMOVE-ONLY. The cart
     * shows it, names it and explains it, and the one thing that may still be
     * done with it is taking it out. So a quantity change is admissible on the
     * same terms an add is (the catalogue's, publication included) and a
     * product that is gone or withdrawn refuses the change rather than
     * accepting it unclamped, which is what happened while the product was
     * merely looked up and the clamp skipped when the lookup found nothing.
     * The line is left exactly as it was, so Remove still works.
     *
     * A LINE WHOSE UNIT IS NOT SELLABLE IS REMOVE-ONLY TOO: sold out, an
     * unpublished, archived or deleted combination, or the bare item of a
     * product with current combinations. Any quantity but zero is refused with
     * the out-of-stock sentence and the line stays as it is, rather than being
     * clamped against the kernel ceiling, which judges stock only and would
     * remove a sold-out line or accept a quantity of an unpublished combination.
     * The same sentence refuses a line whose product the hide-out-of-stock rule
     * keeps off the catalogue, since the cart page shows that line as out of
     * stock.
     *
     * @param int $lineId The line.
     * @param int $quantity The quantity wanted; 0 removes the line.
     *
     * @return bool Whether the change was applied.
     *
     * @since  1.10.0
     */
    public function setQuantity(int $lineId, int $quantity): bool
    {
        $cartId = $this->cartId();

        if ($cartId <= 0 || $lineId <= 0) {
            return false;
        }

        try {
            $line = $this->repository->loadLine($lineId, $cartId);

            if (!$line) {
                return false;
            }

            if ($quantity > 0) {
                $item = $this->items->load((int) $line->id_item, requirePublished: true);

                if (!$item) {
                    $this->refuseWithheld(itemId: (int) $line->id_item, key: 'COM_ALFA_CART_LINE_UNAVAILABLE');

                    return false;
                }

                $comboId = $line->id_combination !== null ? (int) $line->id_combination : null;

                if (!VariantHelper::unitSellable(item: $item, combinationId: $comboId)) {
                    $this->messaging->warn(sentence: Text::_('COM_ALFA_CART_OUT_OF_STOCK'));

                    return false;
                }

                $quantity = $this->clamp->allowed($cartId, $item, $comboId, $quantity, $lineId);

                // A quantity that would leave a priced line charging nothing is
                // refused, and the line stays as it was.
                if ($quantity > 0 && !$this->charged(item: $item, combinationId: $comboId, configuration: $this->configurationOf(item: $item, lineId: $lineId), quantity: $quantity, price: $this->instructionOf(lineId: $lineId), lineId: $lineId)) {
                    return false;
                }
            }

            if ($quantity <= 0) {
                $this->remove($lineId);
            } else {
                $this->repository->setLineQuantityValue($lineId, $quantity, $cartId);
            }

            $this->rows->touch();
            $this->hydrator->reload();
        } catch (Throwable $e) {
            $this->messaging->failed('setLineQuantity', $cartId, $e, 'COM_ALFA_CART_ITEM_UPDATE_FAILED');

            return false;
        }

        return true;
    }

    /**
     * SET OR CLEAR A LINE'S STAFF-NEGOTIATED UNIT PRICE.
     *
     * The write half of the custom price. Authority is NOT asked here; it is
     * asked at the boundary that authorised the act, because a pricing result
     * that depended on who called the writer would be the same mistake as ACL
     * inside the calculator, one layer up.
     *
     * LEAVE SHORT-CIRCUITS BEFORE ANY WRITE, and that is what makes the rule
     * "opening and saving a line must never change its pricing simply because
     * the form was submitted" true by construction rather than by care.
     *
     * The PREVIOUS state is read before the write and returned to the caller,
     * because the audit asks for both states and the old one cannot be
     * reconstructed afterwards.
     *
     * @param int $lineId The line.
     * @param CustomPriceCommand $command Leave, Set(amount) or Clear.
     *
     * @return array{0: bool, 1: float|null, 2: float|null} [succeeded, previous, next]
     *
     * @since  1.11.0
     */
    public function setCustomPrice(int $lineId, CustomPriceCommand $command): array
    {
        $cartId = $this->cartId();

        if ($cartId <= 0 || $lineId <= 0) {
            return [false, null, null];
        }

        // Not a custom-price mutation at all. Nothing is read, nothing is
        // written, and nothing is audited: there is no act to audit.
        if (!$command->mutates()) {
            return [true, null, null];
        }

        try {
            $line = $this->repository->loadLine($lineId, $cartId);

            if (!$line) {
                return [false, null, null];
            }

            $previous = $this->repository->loadLineCustomPrice($lineId, $cartId);

            // Set carries an amount and Clear carries null. The command's own
            // three states are what keep those apart; nothing here asks whether
            // the amount is "empty", because 0.0 is a real negotiated price.
            $next = $command->action === CustomPriceAction::Set ? $command->amount : null;

            // A rate typed by staff is a unit like any other, and a Clear
            // returns the line to its catalogue unit: either way the line must
            // still be charged at the quantity it holds, or nothing is written.
            $item = $this->items->load((int) $line->id_item);

            if ($item && !$this->charged(
                item: $item,
                combinationId: $line->id_combination !== null ? (int) $line->id_combination : null,
                configuration: $this->configurationOf(item: $item, lineId: $lineId),
                quantity: $this->repository->lineQuantity($cartId, $lineId),
                price: $next === null ? null : ExplicitPriceInstruction::perUnitTaxIncl(amount: $next),
                lineId: $lineId,
            )) {
                return [false, null, null];
            }

            $this->repository->setLineCustomPriceValue($lineId, $next, $cartId);

            $this->rows->touch();
            $this->hydrator->reload();
        } catch (Throwable $e) {
            $this->messaging->failed('setLineCustomPrice', $cartId, $e, 'COM_ALFA_CART_ITEM_UPDATE_FAILED');

            return [false, null, null];
        }

        return [true, $previous, $next];
    }

    /**
     * Take one line out of this cart.
     *
     * @param int $lineId The line.
     *
     * @return bool Whether a line of this cart was removed.
     *
     * @since 1.10.0
     */
    public function remove(int $lineId): bool
    {
        return $this->repository->removeLine($this->cartId(), $lineId);
    }

    /**
     * Lines that arrived by adoption are held to this cart's rules: clamped
     * to what may be held, removed when nothing may.
     *
     * On the catalogue's terms, publication included: a line whose product has
     * left the catalogue is passed over untouched rather than re-clamped, so
     * adoption can neither change its quantity nor delete it on the shelf's
     * behalf. It arrives stranded, and stranded lines are the shopper's to
     * remove. A line whose unit is not sellable (sold out, an unpublished,
     * archived or deleted combination, the bare item of a product with current
     * combinations) is passed over the same way: it stays as it is, shown as out
     * of stock.
     *
     * @param object[] $lines The adopted line rows (id, id_item, id_combination, quantity).
     *
     *
     * @since  1.10.0
     */
    public function reclamp(array $lines): void
    {
        foreach ($lines as $line) {
            $item = $this->items->load((int) $line->id_item, requirePublished: true);

            if (!$item) {
                continue;
            }

            if (!VariantHelper::unitSellable(
                item: $item,
                combinationId: $line->id_combination !== null ? (int) $line->id_combination : null,
            )) {
                continue;
            }

            $requested = (int) $line->quantity;
            $allowed = $this->clamp->allowed(
                $this->cartId(),
                $item,
                $line->id_combination !== null ? (int) $line->id_combination : null,
                $requested,
                (int) $line->id,
            );

            if ($allowed === $requested) {
                continue;
            }

            if ($allowed <= 0) {
                $this->remove((int) $line->id);
                $this->rows->touch();

                continue;
            }

            $this->repository->setLineQuantityValue((int) $line->id, $allowed);
        }
    }

    /**
     * The add with its configuration reduced to what the product itself offers.
     *
     * A request names fields and values by id, and a line is described, priced
     * and later ordered from the rows stored for it. So only the product's own
     * configuration goes on: the selection reduced by the one rule that says
     * what a product offers ({@see VariantHelper::offeredSelection()}), and a
     * full composition or an upload only for a field that takes one
     * ({@see VariantHelper::payloadFields()}). Anything else is dropped before
     * the selection is resolved or validated: it chooses no combination,
     * answers no required field and stores no row. The row of a dimension that
     * offers a choice is therefore always the value of the combination resolved
     * from the request, and the row of a field answered by choosing a value the
     * one offered value chosen for it, never a composition. A composition that
     * carries anything for a field that takes none never reaches here: the add
     * was refused for it; what is dropped here for such a field is empty.
     *
     * @param object $item The product, with its fields, options and combinations.
     * @param AddToCartCommand $command The add as it was asked for.
     *
     * @return AddToCartCommand The same add, carrying the product's own configuration only.
     *
     * @since  1.14.0
     */
    private function asOffered(object $item, AddToCartCommand $command): AddToCartCommand
    {
        $taking = VariantHelper::payloadFields(item: $item);

        return new AddToCartCommand(
            itemId:       $command->itemId,
            quantity:     $command->quantity,
            selection:    VariantHelper::offeredSelection(item: $item, request: $command->selection),
            fullPayloads: array_intersect_key($command->fullPayloads, $taking),
            files:        array_intersect_key($command->files, $taking),
            editLineId:   $command->editLineId,
        );
    }

    /**
     * Refuse a write for a product the catalogue does not offer for sale right
     * now, in the words the storefront uses for it: out of stock when only the
     * hide-out-of-stock rule withholds it (its product page and its cart line
     * say out of stock), otherwise the sentence given.
     *
     * @param int $itemId The product.
     * @param string $key The language key for a product that is missing,
     *                    unpublished, outside its publish window or not visible.
     *
     *
     * @since  1.14.0
     */
    private function refuseWithheld(int $itemId, string $key): void
    {
        if ($this->hydrator->hiddenForStock(itemId: $itemId)) {
            $this->messaging->warn(sentence: Text::_('COM_ALFA_CART_OUT_OF_STOCK'));

            return;
        }

        $this->messaging->error(key: $key);
    }

    /**
     * Whether a line may hold a quantity at its price, said before it is written.
     *
     * A line whose unit is above zero and whose own money settles at zero at
     * that quantity is refused ({@see ChargeableQuantity}): the shopper, or the
     * operator building the cart, is told the smallest quantity at or above it
     * that this cart may hold and that is charged at the price of its own
     * quantity tier, and nothing is stored. The line is priced as the cart
     * prices its lines, with the configuration it would be stored with and the
     * negotiated unit price it would carry.
     *
     * @param object $item The product.
     * @param int|null $combinationId The unit the line would hold.
     * @param array $configuration The line's configuration: selection, optionValueIds, payloads.
     * @param int $quantity The quantity it would hold.
     * @param ExplicitPriceInstruction|null $price The negotiated unit price it would carry, or null.
     * @param int $lineId The line being changed; 0 for a new one.
     *
     * @return bool True when the quantity is charged, or the line is free.
     *
     * @since  1.14.0
     */
    private function charged(object $item, ?int $combinationId, array $configuration, int $quantity, ?ExplicitPriceInstruction $price, int $lineId = 0): bool
    {
        $priced = $this->hydrator->priceAt(
            item: $item,
            selection: $configuration['selection'],
            optionValueIds: $configuration['optionValueIds'],
            payloads: $configuration['payloads'],
            quantity: $quantity,
            instruction: $price,
        );

        // The unit a line is judged by, for this quantity and for every tier
        // the search prices: what the customer pays a piece before discounts,
        // with tax, exactly, so a quantity is refused only when the customer
        // would pay nothing for goods that have a price.
        $unitOf = static fn (PriceResult $result): string => $result->getPayableUnitExact();

        if (!ChargeableQuantity::ofUnit(unit: $unitOf($priced), currency: $priced->getCurrency())->refuses(quantity: $quantity)) {
            return true;
        }

        $this->messaging->warn(sentence: ChargeableQuantity::sentence(smallest: $this->hydrator->smallestCharged(
            item: $item,
            combinationId: $combinationId,
            configuration: $configuration,
            instruction: $price,
            from: $quantity,
            unitOf: $unitOf,
            currency: $priced->getCurrency(),
            excludeLineId: $lineId,
        )));

        return false;
    }

    /**
     * A stored line's configuration, in the shape an add states one.
     *
     * @param object $item The line's product.
     * @param int $lineId The line.
     *
     * @return array{selection: array, optionValueIds: array, payloads: array}
     *
     * @since  1.14.0
     */
    private function configurationOf(object $item, int $lineId): array
    {
        [$selection, $optionValueIds, $payloads] = $this->configuration->of($item, $lineId);

        return ['selection' => $selection, 'optionValueIds' => $optionValueIds, 'payloads' => $payloads];
    }

    /**
     * The negotiated unit price a line of this cart carries, as the cart
     * prices it with; null when it carries none, or is not priced.
     *
     * @param int $lineId The line.
     *
     *
     * @since  1.14.0
     */
    private function instructionOf(int $lineId): ?ExplicitPriceInstruction
    {
        $price = $this->hydrator->current()->line($lineId)?->data->price ?? null;

        return $price instanceof PriceResult ? $price->getExplicitPrice() : null;
    }

    private function cartId(): int
    {
        return (int) $this->hydrator->current()->identity()->cartId;
    }
}
