<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartActivity;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Alfa\Component\Alfa\Administrator\Service\ItemFields\ItemFieldDisplayResolver;
use Alfa\Component\Alfa\Administrator\Service\Order\ItemFieldsSnapshot;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCreator;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\ItemModel;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * One cart, for reading.
 *
 * A READ MODEL, and only that. The Carts screen exists so a merchant can SEE
 * what someone is assembling: the cart is the shopper's own working document,
 * and reaching into it is a separate act with its own ACL and its own audit
 * trail. There is no save(), no delete() and no bind() here, and the one thing
 * that does write cart rows anywhere in this component is CartRepository.
 *
 * It answers a different question from the list, so it asks a different query:
 * the list needs one row per cart and counts, this needs the lines, what was
 * chosen on each of them, and who the cart belongs to.
 *
 * PRICES ARE ABSENT ON PURPOSE. Pricing a cart means hydrating every line
 * through the catalogue and running the pricing engine per line, which is one
 * model boot per row for a screen nobody buys from. What a cart is worth is a
 * question the ORDER answers, once the shopper has decided.
 *
 * @since  1.10.0
 */
class CartModel extends ItemModel
{
    /**
     * @var string The entity a plugin targets with $context->targets('cart').
     * @since  1.10.0
     */
    protected string $alfaEntity = 'cart';

    /**
     * Take the id from the request when the caller did not name one.
     *
     * @param string $ordering Unused.
     * @param string $direction Unused.
     *
     * @return void
     *
     * @since  1.10.0
     */
    protected function populateState($ordering = null, $direction = null)
    {
        // $this->state->set, never $this->getState(): reading the state from
        // inside the method that fills it recurses until the stack dies, and
        // the page answers 500 with nothing in any log.
        $this->state->set('cart.id', (int) Factory::getApplication()->getInput()->getInt('id', 0));
    }

    /**
     * The cart, with its lines and what was chosen on each.
     *
     * @param int|null $pk The cart id; the request's when omitted.
     *
     * @return object|null The cart, or null when there is no such cart.
     *
     * @since  1.10.0
     */
    public function getItem($pk = null)
    {
        // getState(), not $this->state: the state is filled LAZILY, and reading
        // the bag directly means populateState() never runs and the id is always
        // null. (Inside populateState() the opposite holds, and for the same
        // reason: getState() there recurses until the stack dies.)
        $pk = (int) ($pk ?: $this->getState('cart.id'));

        if ($pk <= 0) {
            return null;
        }

        $db = $this->getDatabase();
        $cart = (new CartRepository($db))->loadCart($pk);

        if ($cart === null) {
            return null;
        }

        $cart->lines = $this->lines($pk);
        $cart->line_count = \count($cart->lines);
        $cart->unit_count = array_sum(array_map(static fn ($line) => (int) $line->quantity, $cart->lines));
        $cart->bucket = CartActivity::bucketOf($cart, CartActivity::cutoff());
        $cart->owner = $this->owner($cart);
        $cart->codes = $this->codes($pk);

        return $cart;
    }

    /**
     * The lines, each carrying the product's name and what was chosen on it.
     *
     * @param int $cartId The cart.
     *
     * @return object[]
     *
     * @since  1.10.0
     */
    private function lines(int $cartId): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select([$db->quoteName('l.id'), $db->quoteName('l.id_item'), $db->quoteName('l.id_combination'), $db->quoteName('l.quantity')])
            ->select($db->quoteName('i.sku'))
            ->select($db->quoteName('i.state', 'item_state'))
            ->from($db->quoteName('#__alfa_cart_items', 'l'))
            ->join('LEFT', $db->quoteName('#__alfa_items', 'i') . ' ON i.id = l.id_item')
            ->where($db->quoteName('l.id_cart') . ' = ' . $cartId)
            ->order($db->quoteName('l.id') . ' ASC');

        // The product's name lives in the per-language tables, not on the item
        // row: the same join the Items list uses, so an untranslated product
        // still appears rather than vanishing from the cart.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'i',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_items',
            langPrimaryColumn: 'id_item',
            fields:            ['name'],
        );

        $lines = $db->setQuery($query)->loadObjectList() ?: [];

        if ($lines === []) {
            return [];
        }

        // The selections for every line at once; one statement, not one per
        // line: a cart with twenty lines is still a cart.
        $selections = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('f.id_cart_item'), $db->quoteName('f.id_item_field'), $db->quoteName('f.id_item_field_value')])
                ->select($db->quoteName('f.payload'))
                ->from($db->quoteName('#__alfa_cart_item_fields', 'f'))
                ->whereIn($db->quoteName('f.id_cart_item'), array_map(static fn ($l) => (int) $l->id, $lines))
                ->order($db->quoteName('f.id') . ' ASC'),
        )->loadObjectList() ?: [];

        // Resolve every selection's field/value name through the one resolver
        // the storefront cart uses, so the back office reads the same labels.
        $fieldLabelMaps = ItemFieldDisplayResolver::fieldLabelMaps(
            array_map(static fn ($sel): int => (int) $sel->id_item_field, $selections),
        );
        $valueTextMaps = ItemFieldDisplayResolver::valueTextMaps(
            array_map(static fn ($sel): int => (int) $sel->id_item_field_value, $selections),
        );

        $byLine = [];

        foreach ($selections as $selection) {
            $fieldId = (int) $selection->id_item_field;
            $valueId = $selection->id_item_field_value !== null ? (int) $selection->id_item_field_value : null;
            $selection->field_name = MultilingualHelper::resolveLangMap($fieldLabelMaps[$fieldId] ?? []);
            $selection->value_name = $valueId !== null ? MultilingualHelper::resolveLangMap($valueTextMaps[$valueId] ?? []) : '';
            $byLine[(int) $selection->id_cart_item][] = $selection;
        }

        foreach ($lines as $line) {
            $line->selections = $byLine[(int) $line->id] ?? [];
        }

        return $lines;
    }

    /**
     * Who the cart belongs to, in the terms the screen can show.
     *
     * A member is read from their account. A guest has no account to read, so
     * the identity comes from the details record the cart points at, read
     * through the role registry rather than hardcoded column names, because a
     * merchant may have adopted their own fields.
     *
     * @param object $cart The cart row.
     *
     * @return object name, email and whether they are a member.
     *
     * @since  1.10.0
     */
    private function owner(object $cart): object
    {
        $db = $this->getDatabase();
        $owner = (object) ['is_member' => (int) $cart->id_customer > 0, 'name' => '', 'email' => ''];

        if ($owner->is_member) {
            $account = $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->quoteName('name'), $db->quoteName('email')])
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('id') . ' = ' . (int) $cart->id_customer),
                0,
                1,
            )->loadObject();

            $owner->name = (string) ($account->name ?? '');
            $owner->email = (string) ($account->email ?? '');

            return $owner;
        }

        if ((int) $cart->id_user_info_delivery <= 0) {
            return $owner;
        }

        $nameColumn = RoleRegistry::column(RoleRegistry::NAME);
        $emailColumn = RoleRegistry::column(RoleRegistry::EMAIL);

        if ($nameColumn === null && $emailColumn === null) {
            return $owner;
        }

        $query = $db->getQuery(true)
            ->from($db->quoteName('#__alfa_user_info'))
            ->where($db->quoteName('id') . ' = ' . (int) $cart->id_user_info_delivery);

        $query->select($nameColumn !== null ? $db->quoteName($nameColumn, 'name') : "'' AS " . $db->quoteName('name'));
        $query->select($emailColumn !== null ? $db->quoteName($emailColumn, 'email') : "'' AS " . $db->quoteName('email'));

        $details = $db->setQuery($query, 0, 1)->loadObject();

        $owner->name = (string) ($details->name ?? '');
        $owner->email = (string) ($details->email ?? '');

        return $owner;
    }

    /**
     * The codes the cart is holding.
     *
     * @param int $cartId The cart.
     *
     * @return string[]
     *
     * @since  1.10.0
     */
    private function codes(int $cartId): array
    {
        $db = $this->getDatabase();

        return $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('code'))
                ->from($db->quoteName('#__alfa_cart_coupons'))
                ->where($db->quoteName('id_cart') . ' = ' . $cartId)
                ->order($db->quoteName('code') . ' ASC'),
        )->loadColumn() ?: [];
    }

    /**
     * A product's configuration surfaces, priced for THIS cart's customer.
     *
     * The surfaces (variants, options, item fields, ShowOn) are the
     * storefront's own, built once by the order screen's config builder and
     * reused here unchanged. What is NOT taken from it is the pricing context:
     * the item is re-priced in the cart customer's context, so an option's price
     * is the one this customer pays, never the public's or the operator's. The
     * modal renders from this priced item; the authoritative line price still
     * comes from the add.
     *
     * @param int $itemId The product to configure.
     * @param int $cartId The builder cart, whose customer sets the context.
     *
     * @return object|null The configured, priced item, or null when it is gone.
     *
     * @since  1.10.0
     */
    public function getBuilderItemConfig(int $itemId, int $cartId): ?object
    {
        if ($itemId <= 0 || $cartId <= 0) {
            return null;
        }

        $item = $this->seededItemConfig($itemId);

        if ($item === null) {
            return null;
        }

        // The surfaces were built on the unpriced item; pricing it now, in the
        // cart customer's context, is what every priced surface reads back.
        VariantHelper::resolveAndPrice($item, [], $this->cartPriceContext($cartId), new PriceCalculator());

        return $item;
    }

    /**
     * One EXISTING cart line, prepared for re-configuration in the builder's
     * item popup: the live-cart twin of
     * {@see OrderModel::getOrderItemConfig()}.
     *
     * The line's stored selection is read back out of #__alfa_cart_item_fields
     * and handed to the one seeding path, so the popup opens on exactly what
     * the shopper chose: the same combination selected, the same options
     * ticked, every plugin field re-opened on its own stored payload. Pricing is
     * then applied in the CART CUSTOMER's context, at the line's own quantity,
     * because a per-quantity price is part of what the operator is looking at.
     *
     * @param int $lineId The #__alfa_cart_items row id.
     * @param int $cartId The cart it must belong to.
     *
     * @return object|null ->line and ->item, or null when the line is not this cart's.
     *
     * @since  1.10.0
     */
    public function getBuilderLineConfig(int $lineId, int $cartId): ?object
    {
        if ($lineId <= 0 || $cartId <= 0) {
            return null;
        }

        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    $db->quoteName('id_cart'),
                    $db->quoteName('id_item'),
                    $db->quoteName('quantity'),
                    // THE THIRD SELECT LIST. The repository has two; this popup
                    // runs its own query, and it is the one screen that
                    // REWRITES the line: an operator who cannot see that the
                    // line carries a negotiated price would carry it forward
                    // blind.
                    $db->quoteName('custom_unit_price_tax_incl'),
                ])
                ->from($db->quoteName('#__alfa_cart_items'))
                ->where($db->quoteName('id') . ' = :line')
                ->bind(':line', $lineId, ParameterType::INTEGER),
        );
        $line = $db->loadObject();

        // A line is only editable through the cart it is in. The popup is opened
        // by id, and an id from another cart is simply not this cart's line.
        if (!$line || (int) $line->id_cart !== $cartId) {
            return null;
        }

        $item = $this->seededItemConfig(
            (int) $line->id_item,
            fn (object $configured): array => $this->lineSeed((int) $line->id, $configured),
        );

        if ($item === null) {
            return null;
        }

        $seed = $this->lineSeed((int) $line->id, $item);

        VariantHelper::resolveAndPrice(
            $item,
            $seed['selection'],
            $this->cartPriceContext($cartId),
            new PriceCalculator(),
            max(1, (int) $line->quantity),
            array_values($seed['optionValueIds']),
            $seed['payloads'],
            // PRODUCER 2 OF 2. The popup is a MUTATION surface whose save
            // PRESERVES the custom price, so its quote (cart.builder_quote)
            // shows the line as the cart will charge it, negotiated price included.
            ExplicitPriceInstruction::fromStoredColumn($line->custom_unit_price_tax_incl ?? null),
        );

        $config = new stdClass();
        $config->line = $line;
        $config->item = $item;

        return $config;
    }

    /**
     * What the posted configuration costs THIS cart's customer, at the posted
     * quantity: the builder popup's quote.
     *
     * It reaches the cart line's own pricing call ({@see OrderModel::priceConfigIn()})
     * with the CART's price context, so an option's impact and every catalogue
     * discount are the customer's, not the public's. Editing a line carries that
     * line's stored custom price, which the edit keeps; a new line carries none.
     *
     * @param int $itemId The #__alfa_items row id being configured.
     * @param int $cartId The cart whose customer is being priced for.
     * @param int $lineId The line being edited, or 0 for a new line.
     * @param int $quantity The quantity in the popup's box.
     *
     * @return PriceResult|null The priced line, or null when there is no such item or line.
     *
     * @since  1.10.0
     */
    public function priceLineConfig(int $itemId, int $cartId, int $lineId, int $quantity): ?PriceResult
    {
        if ($itemId <= 0 || $cartId <= 0 || $lineId < 0) {
            return null;
        }

        $price = null;

        if ($lineId > 0) {
            // Both halves of the key: a line of another cart, or a line of
            // another product, is not the line being edited here.
            $line = (new CartRepository(db: $this->getDatabase()))->loadLine(lineId: $lineId, cartId: $cartId);

            if (!$line || (int) $line->id_item !== $itemId) {
                return null;
            }

            $price = ExplicitPriceInstruction::fromStoredColumn($line->custom_unit_price_tax_incl ?? null);
        }

        $order = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Order', 'Administrator', ['ignore_request' => true]);

        return $order->priceConfigIn(itemId: $itemId, context: $this->cartPriceContext($cartId), quantity: $quantity, price: $price);
    }

    /**
     * Who this cart belongs to, and which of their saved addresses it points at.
     *
     * THE CUSTOMER COMES FROM THE CART ROW. Not from the request, not from the
     * snapshot (whose customer is whatever the caller declared), and not from
     * the operator: the row is the authority every other builder endpoint
     * already reads through {@see CartController::customerOf()}, and this
     * screen must agree with it or it would show one customer while pricing
     * another's cart.
     *
     * The addresses are the CUSTOMER's own saved details, read through the one
     * owner ({@see UserInfoHelper}); this model neither stores nor formats an
     * address of its own. The two pointers are read straight off the cart row:
     * the same columns {@see CartRowWriter::pointAtDetails()} writes, because
     * the settled snapshot does not carry them and inventing a second place to
     * remember the selection is how the screen and the engine start disagreeing.
     *
     * @param int $cartId The builder cart.
     * @param User $actor The signed-in operator.
     *
     * @return object|null Customer identity, saved addresses and the current pointers.
     *
     * @since  1.10.0
     */
    public function getBuilderCustomer(int $cartId, User $actor): ?object
    {
        if ($cartId <= 0) {
            return null;
        }

        $row = (new CartRepository($this->getDatabase()))->loadCart($cartId);

        if ($row === null) {
            return null;
        }

        $customerId = (int) ($row->id_customer ?? 0);
        $customer = new User($customerId);

        $context = new stdClass();
        $context->cartId = $cartId;
        $context->customerId = $customerId;
        $context->isGuest = $customerId === 0;
        $context->name = $customerId > 0 ? (string) $customer->name : '';
        $context->email = $customerId > 0 ? (string) $customer->email : '';
        $context->username = $customerId > 0 ? (string) $customer->username : '';
        $context->groups = [];

        // The groups the cart is priced and decided by: the customer's own and
        // every group above them, and the guest group's where the cart has no
        // account.
        foreach (PriceContext::customerGroups(customer: $customer) as $groupId) {
            $context->groups[$groupId] = (string) Access::getGroupTitle($groupId);
        }

        // WHAT THE CART POINTS AT RIGHT NOW. Two independent columns: a cart may
        // deliver to one address and invoice to another, and the builder must
        // never collapse them.
        $context->deliveryId = (int) ($row->id_user_info_delivery ?? 0);
        $context->invoiceId = (int) ($row->id_user_info_invoice ?? 0);
        $context->sameAddress = $context->invoiceId === 0 || $context->invoiceId === $context->deliveryId;

        $context->delivery = $context->deliveryId > 0 ? UserInfoHelper::getRowById(id: $context->deliveryId) : null;
        $context->invoice = $context->invoiceId > 0 ? UserInfoHelper::getRowById(id: $context->invoiceId) : null;

        $context->addresses = $this->savedAddressesFor($customerId);

        // What this customer could pay with instead of money. Read here because
        // it is a fact about the CUSTOMER, and because the builder should take
        // ONE reading of a cart per page rather than a second, unsettled one
        // just to learn a balance.
        $context->credit = null;

        if ($customerId > 0) {
            try {
                $context->credit = CustomerCreditLedger::balance($customerId);
            } catch (Throwable $e) {
                $context->credit = null;
            }
        }

        return $context;
    }

    /**
     * The statuses a new order may LAND in, for the operator to choose from.
     *
     * The list is the merchant's own published statuses, narrowed by the one
     * question {@see OrderCreator::mayCreateInto()} answers: a status that
     * requires payment cannot be satisfied by an order that does not exist yet,
     * unless that status permits the unpaid exception. The rule is asked, not
     * restated: a screen that offered a status creation would refuse is worse
     * than a screen that offers none.
     *
     * @return object[] Published, placeable statuses in the merchant's order.
     *
     * @since  1.10.0
     */
    public function getPlacementStatuses(): array
    {
        $db = $this->getDatabase();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('is_initial')])
                ->from($db->quoteName('#__alfa_orders_statuses'))
                ->where($db->quoteName('state') . ' = 1')
                ->order($db->quoteName('ordering') . ' ASC'),
        )->loadObjectList() ?: [];

        $names = MultilingualHelper::getRecordsByIds(
            $db,
            array_map(static fn ($r): int => (int) $r->id, $rows),
            '#__alfa_orders_statuses',
            'id',
            [],
            '#__alfa_orders_statuses',
            'id_orderstatus',
            ['name'],
        );

        $placeable = [];

        foreach ($rows as $row) {
            if (!OrderCreator::mayCreateInto((int) $row->id)) {
                continue;
            }

            $row->name = $names[(int) $row->id]['name'] ?? ('#' . (int) $row->id);
            $placeable[] = $row;
        }

        return $placeable;
    }

    /**
     * The placement form's two address buckets, for THIS cart's customer.
     *
     * The builder places an order the way the storefront does: by posting the
     * merchant's real checkout fields, delivery in one bucket and invoice in
     * the other. So the form is built the way the Order screen builds its own:
     * two {@see FieldsHelper::prepareForm()} passes with the narrow 'cart.shipping'
     * and 'cart.billing' contexts and the two distinct groups, and never by
     * hand-listing field names, which would go stale the moment a merchant adds
     * a field and would render none of the ShowOn metadata the engine reads.
     *
     * The values are SEEDED FROM WHAT THE CART POINTS AT. That is what makes the
     * operator's address choice survive into the order: placement freezes the
     * typed buckets, not the cart's pointers, so an unseeded form would place
     * the order against whatever was typed instead of the address that was
     * picked and priced.
     *
     * Two identities, deliberately: field VISIBILITY and identity rules are both
     * the customer's, because these are the customer's details, never the
     * operator's groups deciding which fields a customer's order shows.
     *
     * @param int $cartId The builder cart.
     * @param User $actor The signed-in operator.
     *
     * @return Form|null A form carrying 'user_details' and 'user_details_billing'.
     *
     * @since  1.10.0
     */
    public function getCheckoutForm(int $cartId, User $actor): ?Form
    {
        $customer = $this->getBuilderCustomer($cartId, $actor);

        if ($customer === null) {
            return null;
        }

        $form = new Form('com_alfa.cart_checkout');
        $customerId = (int) $customer->customerId;

        // A guest cart has no account whose field visibility could apply, so the
        // fields are prepared for nobody in particular, exactly as the Order
        // screen's CREATE case does.
        $visibilityId = $customerId > 0 ? $customerId : null;

        FieldsHelper::prepareForm(
            'cart.shipping',
            $form,
            null,
            'user_details',
            userId: $visibilityId,
            customerId: $customerId,
        );
        FieldsHelper::prepareForm(
            'cart.billing',
            $form,
            null,
            'user_details_billing',
            group: FieldsHelper::BILLING_FIELDS_KEY,
            userId: $visibilityId,
            customerId: $customerId,
        );

        // The invoice bucket is seeded only when the cart really points somewhere
        // else. An invoice address equal to the delivery one is the SAME thing as
        // no separate invoice, and seeding it would turn 'invoice to the delivery
        // address' into a second stored record at placement.
        FieldsHelper::prefillBucket($form, FieldsHelper::FIELDS_KEY, $customer->delivery);

        if (!$customer->sameAddress) {
            FieldsHelper::prefillBucket($form, FieldsHelper::BILLING_FIELDS_KEY, $customer->invoice);
        }

        return $form;
    }

    /**
     * A customer's saved rows that are actually ADDRESSES, newest first.
     *
     * The rule is the storefront's, applied through the same three owners: the
     * rows come from {@see UserInfoHelper::getRowsByUser()}, the columns that
     * count from {@see FieldsHelper::getFields()} for the role-neutral
     * 'cart.form' context, and the identity columns to ignore from
     * {@see RoleRegistry}. A row holding nothing but the name a registration
     * wrote is an identity, not an address to ship to, and offering it as one is
     * how an order goes out with no street on it.
     *
     * Each row is labelled by the one address->text renderer and carries whether
     * an order already references it, because a frozen row may be pointed at but
     * never edited.
     *
     * @param int $customerId The cart's customer; a guest has no address book.
     *
     * @return object[] The saved addresses, each with ->label and ->frozen.
     *
     * @since  1.10.0
     */
    private function savedAddressesFor(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $roleColumns = array_filter(array_map([RoleRegistry::class, 'column'], RoleRegistry::roles()));
        $addressKeys = array_diff(
            array_column(FieldsHelper::getFields('cart.form'), 'field_name'),
            $roleColumns,
        );

        $addresses = [];

        foreach (UserInfoHelper::getRowsByUser(userId: $customerId) as $row) {
            foreach ($addressKeys as $key) {
                if (trim((string) ($row->$key ?? '')) === '') {
                    continue;
                }

                $row->label = UserInfoHelper::label($row);
                $row->frozen = UserInfoHelper::isUsedInOrders(id: (int) $row->id);
                $addresses[] = $row;

                break;
            }
        }

        return $addresses;
    }

    /**
     * The order model's one configuration-seeding path, reached from here.
     *
     * The builder deliberately does NOT grow its own copy: an item configures
     * one way across this component, and that way lives in
     * {@see OrderModel::getSeededItemConfig()}.
     *
     * @param int $itemId The #__alfa_items row id.
     * @param callable|null $seedFor fn(object $item): array - the stored selection, or null for a fresh configure.
     *
     * @return object|null The hydrated item with its surfaces, or null.
     *
     * @since  1.10.0
     */
    private function seededItemConfig(int $itemId, ?callable $seedFor = null): ?object
    {
        $order = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Order', 'Administrator', ['ignore_request' => true]);

        return $order ? $order->getSeededItemConfig($itemId, $seedFor) : null;
    }

    /**
     * A live cart line's stored selection, in the SAME shape the product page's
     * request parser produces: the cart twin of
     * {@see ItemFieldsSnapshot::lineSelection()}, which reads the order side's
     * frozen rows. The split is the same and comes from the same authority:
     * fields that define combinations are the variant selection, plain values
     * are option values, and anything a plugin stored is a payload.
     *
     * @param int $lineId The #__alfa_cart_items row id.
     * @param object $configured The configured item, whose combinations decide the split.
     *
     * @return array{selection: array, optionValueIds: array, payloads: array}
     *
     * @since  1.10.0
     */
    private function lineSeed(int $lineId, object $configured): array
    {
        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('id_item_field'),
                    $db->quoteName('id_item_field_value'),
                    $db->quoteName('payload'),
                ])
                ->from($db->quoteName('#__alfa_cart_item_fields'))
                ->where($db->quoteName('id_cart_item') . ' = :line')
                ->bind(':line', $lineId, ParameterType::INTEGER)
                ->order($db->quoteName('id') . ' ASC'),
        );

        $variantFieldIds = ItemFieldsSnapshot::variantFieldIds(item: $configured);

        $selection = [];
        $optionValueIds = [];
        $payloads = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $fieldId = (int) $row->id_item_field;

            if ($row->payload !== null) {
                $payloads[$fieldId] = $row->payload;
            } elseif (isset($variantFieldIds[$fieldId])) {
                $selection[$fieldId] = (int) $row->id_item_field_value;
            } else {
                $optionValueIds[$fieldId] = (int) $row->id_item_field_value;
            }
        }

        return [
            'selection' => $selection,
            'optionValueIds' => $optionValueIds,
            'payloads' => $payloads,
        ];
    }

    /**
     * The priced snapshot the builder renders its Items tab from: the SAME
     * authoritative reading the request seam returns, built for the cart's own
     * customer with this operator as actor.
     *
     * SETTLING IS A WRITE, AND THE CALLER SAYS WHETHER IT MAY HAPPEN. Settling
     * reconciles, and reconciliation drops a method that stopped being eligible
     * or takes the only one left, on the cart row. On a cart the operator may
     * look at but not operate, `$settle` is false and the reading runs no
     * reconciliation and writes nothing ({@see CartTakeover::mayOperate()}).
     *
     * @param int $cartId The builder cart.
     * @param User $actor The signed-in operator.
     * @param HtmlDocument|null $page The page being built, when there is one: the
     *                                method plugins' assets are then declared from
     *                                the same opened cart the reading is taken from.
     * @param bool $settle Whether this operator may settle this cart.
     *
     * @return object|null A CartSnapshot, or null when there is no such cart.
     *
     * @since  1.10.0
     */
    public function getBuilderSnapshot(int $cartId, User $actor, ?HtmlDocument $page, bool $settle): ?object
    {
        $customerId = $this->cartCustomerId($cartId);

        if ($customerId === null) {
            return null;
        }

        $editor = CartService::forSubject(CartSubject::operator(new User($customerId), $actor))
            ->editCart($cartId);

        // A LOOK, AND ONLY A LOOK: no reconciliation, so nothing is written to a
        // cart whose shopper still owns it.
        if (!$settle) {
            return $editor->reading();
        }

        return $page === null ? $editor->snapshot() : $editor->snapshotForPage(page: $page);
    }

    /**
     * Whose cart this is: the account, 0 for a guest, or null if there is no
     * such cart. The commercial customer is always the row's, never the caller's.
     *
     * @param int $cartId The cart.
     *
     *
     * @since  1.10.0
     */
    private function cartCustomerId(int $cartId): ?int
    {
        if ($cartId <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $found = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id_customer'))
                ->from($db->quoteName('#__alfa_cart'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $cartId, ParameterType::INTEGER),
        )->loadResult();

        return $found === null ? null : (int) $found;
    }

    /**
     * A price context that prices for a cart's own customer and currency: the
     * same one the live cart prices in, never the operator's or the public's.
     *
     * @param int $cartId The cart.
     *
     *
     * @throws RuntimeException When the cart does not exist or names no currency.
     *
     * @since  1.10.0
     */
    private function cartPriceContext(int $cartId): PriceContext
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $row = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('id_customer'), $db->quoteName('id_currency')])
                ->from($db->quoteName('#__alfa_cart'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $cartId, ParameterType::INTEGER),
        )->loadObject();

        // No substitute context (the no-fallback rule): a cart that does not
        // exist, or names no currency, is refused, as the cart line refuses it
        // (CartSubject::priceContext()).
        if (!$row) {
            throw new RuntimeException(Text::_('COM_ALFA_CART_NOT_FOUND'));
        }

        if ((int) $row->id_currency <= 0) {
            throw new RuntimeException('Cart ' . $cartId . ' names no currency, so it cannot be priced.');
        }

        // Priced as the cart's customer, by the rule the cart line follows; a
        // cart with no account to load (a walk-in guest, or an account since
        // deleted) prices as the anonymous visitor.
        return PriceContext::forIndex(currencyId: (int) $row->id_currency, placeId: 0, usergroupId: 0)
            ->withCustomer(customer: new User(identifier: (int) $row->id_customer));
    }
}
