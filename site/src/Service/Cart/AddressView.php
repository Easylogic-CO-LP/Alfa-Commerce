<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Alfa\Component\Alfa\Site\Service\Data\ValueCast;
use Alfa\Component\Alfa\Site\Service\Data\ValueException;
use Alfa\Component\Alfa\Site\Service\Place\PlaceTree;

defined('_JEXEC') or die;

/**
 * One address on the cart (delivery or invoice) read by field.
 *
 * A plugin needs a value the CORE has no opinion about: the postcode a carrier
 * prices by, the phone a courier texts. The core deliberately holds no role for
 * those and hardcodes no column, because they are the plugin's concern; the
 * plugin names the field in its own settings with a `checkoutfield` picker and
 * asks for it here by the id that picker saved.
 *
 * The two values every address is guaranteed to carry have getters of their
 * own: {@see self::getName()} and {@see self::getEmail()}; everything else is
 * asked for by role or by field id.
 *
 * WHAT THE SHOPPER HAS TYPED WINS OVER WHAT WAS SAVED. During checkout the
 * form's values live in user state and the cart may still point at an address
 * row written days ago; quoting against the row would price the wrong
 * destination, and, worse, a shopper who CLEARS a field would silently get
 * the old saved value back. So a pending value is authoritative whenever the
 * field was submitted at all, including when it was submitted empty.
 *
 * @since  2.0.0
 */
final class AddressView
{
    /**
     * @param object|null $row The `#__alfa_user_info` record, or null when the cart names none.
     * @param array $pending What the checkout form currently holds, by column name.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly ?object $row,
        private readonly array $pending = [],
    ) {
    }

    /**
     * Whether the cart points at a saved address at all.
     *
     *
     * @since  2.0.0
     */
    public function exists(): bool
    {
        return $this->row !== null;
    }

    /**
     * The saved record's id, 0 when there is none.
     *
     *
     * @since  2.0.0
     */
    public function id(): int
    {
        return (int) ($this->row->id ?? 0);
    }

    /**
     * A field by the id a `checkoutfield` param saved.
     *
     * The id is resolved to a live column every time, so a field the merchant
     * has since deleted or unpublished answers `missing()` rather than a stale
     * value: the plugin is told to go and fix its settings instead of quoting
     * against nothing.
     *
     * @param int $fieldId The saved `#__alfa_form_fields.id`; 0 = not configured.
     *
     *
     * @since  2.0.0
     */
    public function field(int $fieldId): FieldValue
    {
        $column = FieldsHelper::columnForField($fieldId);

        return $column === null ? FieldValue::missing() : $this->column($column);
    }

    /**
     * One field's value, directly.
     *
     *     $delivery->get(fieldId: $postcodeField)                    the value
     *     $delivery->get(fieldId: $f, default: '00000')              when unanswered
     *     $delivery->get(fieldId: $f, default: 0, filter: 'int')     converted
     *
     * THE SAME VALUE {@see self::field()} GIVES, resolved the same way. Without
     * a filter or a default this returns exactly `field($fieldId)->value()`:
     * pending input first, then the saved row, trimmed, with an unanswered field
     * reading as null. It is a shortcut past the field object, not a second
     * opinion about what the field holds.
     *
     * Reach for {@see self::field()} when you need the OBJECT: whether a live
     * field backs it at all, which column it came from. Reach for this when you
     * need the value, which is most of the time.
     *
     * A DEFAULT ANSWERS AN UNANSWERED FIELD, never a malformed one: a value
     * that is there but will not convert throws, because a shopper who typed
     * something unusable must be told, not quietly given a default.
     *
     * @param int|string $fieldId The checkout field's id; a numeric string from
     *                            a setting is accepted, an alias is not.
     * @param mixed $default Returned when the field is unmapped, deleted or unanswered.
     * @param string|null $filter One of ValueCast::FILTERS, or null for the value as stored.
     *
     *
     * @throws ValueException On a field id that is not a number, or a value that will not convert.
     *
     * @since  2.0.0
     */
    public function get(int|string $fieldId, mixed $default = null, ?string $filter = null): mixed
    {
        $id = self::fieldId($fieldId);

        return ValueCast::apply($this->field($id)->value(), $filter, $default, self::describe($id));
    }

    /**
     * One field's value as a whole number.
     *
     *     $delivery->getInt(fieldId: $placeField, default: null)
     *
     * @param int|string $fieldId The checkout field's id.
     * @param int|null $default Returned when the field is unanswered.
     *
     *
     * @throws ValueException When the answer is not a whole number.
     *
     * @since  2.0.0
     */
    public function getInt(int|string $fieldId, ?int $default = null): ?int
    {
        $id = self::fieldId($fieldId);

        return ValueCast::toInt($this->field($id)->value(), $default, self::describe($id));
    }

    /**
     * One field's value as a string, with its bytes intact.
     *
     *     $delivery->getString(fieldId: $phoneField, default: '')
     *
     * A postcode is '01234' and a phone number is '+30...'; neither survives being
     * treated as a number, so nothing here does that to them.
     *
     * @param int|string $fieldId The checkout field's id.
     * @param string|null $default Returned when the field is unanswered.
     *
     *
     * @since  2.0.0
     */
    public function getString(int|string $fieldId, ?string $default = null): ?string
    {
        $id = self::fieldId($fieldId);

        return ValueCast::toString($this->field($id)->value(), $default, self::describe($id));
    }

    /**
     * One field's value as a boolean, from the spellings {@see ValueCast::toBool()} accepts.
     *
     *     $delivery->getBool(fieldId: $giftWrapField, default: false)
     *
     * A checkbox stores '1' or '', and the string 'false' does not become true
     * here the way PHP's own cast would.
     *
     * @param int|string $fieldId The checkout field's id.
     * @param bool|null $default Returned when the field is unanswered.
     *
     *
     * @since  2.0.0
     */
    public function getBool(int|string $fieldId, ?bool $default = null): ?bool
    {
        $id = self::fieldId($fieldId);

        return ValueCast::toBool($this->field($id)->value(), $default, self::describe($id));
    }

    /**
     * A field id out of a setting, checked.
     *
     * Settings store the id as a string, so '12' is a field id. 'postcode' is
     * NOT: it is an alias or a label, and resolving it would mean guessing which
     * field a merchant meant: the exact guess that had a carrier plugin reading the
     * customer's NAME as their e-mail address. A merchant who has typed a name
     * into an id setting needs to be told, not accommodated.
     *
     * @param int|string $fieldId What the caller passed.
     *
     *
     * @throws ValueException When it is not a field id.
     *
     * @since  2.0.0
     */
    private static function fieldId(int|string $fieldId): int
    {
        if (\is_int($fieldId)) {
            return $fieldId;
        }

        $trimmed = trim($fieldId);

        if ($trimmed !== '' && ctype_digit(ltrim($trimmed, '-')) && (string) (int) $trimmed === $trimmed) {
            return (int) $trimmed;
        }

        throw new ValueException(sprintf(
            'checkout field id: "%s" is not an id. Settings store the field\'s numeric id; '
            . 'an alias or a label cannot be resolved without guessing which field was meant.',
            $fieldId,
        ));
    }

    /**
     * What to call a field in an error message.
     *
     * @param int $fieldId The field.
     *
     *
     * @since  2.0.0
     */
    private static function describe(int $fieldId): string
    {
        return 'checkout field #' . $fieldId;
    }

    /**
     * Several fields at once, keyed by the id that asked for them.
     *
     * For a plugin whose picker is `multiple="true"`: a carrier that accepts
     * any of three phone fields, a gateway that will take whichever address
     * line is filled. Each answers independently, so a deleted one among live
     * ones is reported as missing rather than sinking the rest.
     *
     * @param int[] $fieldIds The saved `#__alfa_form_fields.id` values.
     *
     * @return array<int, FieldValue>
     *
     * @since  2.0.0
     */
    public function fields(array $fieldIds): array
    {
        $out = [];

        foreach ($fieldIds as $fieldId) {
            $fieldId = (int) $fieldId;

            if ($fieldId > 0) {
                $out[$fieldId] = $this->field($fieldId);
            }
        }

        return $out;
    }

    /**
     * The first of several fields that actually holds a value.
     *
     * The common shape behind a multiple picker: the merchant names the fields
     * that may carry a phone, in order of preference, and the plugin wants the
     * one the shopper filled in.
     *
     * @param int[] $fieldIds The saved ids, in preference order.
     *
     * @return FieldValue The first with a value; the first that exists otherwise; else missing.
     *
     * @since  2.0.0
     */
    public function firstFilled(array $fieldIds): FieldValue
    {
        $fallback = null;

        foreach ($this->fields($fieldIds) as $value) {
            if ($value->value() !== null) {
                return $value;
            }

            $fallback ??= $value;
        }

        return $fallback ?? FieldValue::missing();
    }

    // The two values every address is guaranteed to carry
    //
    // `name` and `email` are SYSTEM roles. The field model keeps them
    // published, holds their delivery-section floor at "required", and forbids
    // renaming their column or changing their type, so every address has both,
    // whatever the merchant called them. A plugin should not have to know a
    // role constant, unwrap a value object, cast it and trim it to read the two
    // things it is always allowed to assume are there.
    //
    // ONE CONTRACT, BOTH GETTERS: a TRIMMED STRING, never null. '' means the
    // address holds no value for it, never "no such field", because the field
    // cannot be absent. Resolution is the same protected role lookup
    // {@see self::role()} performs, so a renamed column follows by itself.
    //
    // VALIDATION IS THE CALLER'S, AT THE POINT IT MATTERS. These report what is
    // there; they never invent a substitute and never refuse. A carrier that
    // cannot book a nameless parcel checks for '' where it books.
    //
    // NOTHING ELSE GETS A GETTER, and that is the line: `phone` and `country`
    // are roles but NOT system ones (a shop may legitimately ask for neither)
    // and everything past them has no role at all. Those keep {@see self::role()}
    // and {@see self::field()}, where absence stays visible and the caller has
    // to decide what it means.
    //
    // NOT THE BUYER. Read off an order these are the DELIVERY RECIPIENT, who
    // may be somebody else entirely: a present, an office, a locker booked for
    // a friend. `$order->getName()` is who paid; `$order->delivery()->getName()`
    // is who the parcel is for. An AddressView reads only the row it was built
    // with and the pending values it was handed, and an order reader hands it
    // none, so a historical read stays historical, with no account profile and
    // no checkout session behind it.

    /**
     * The name on this address.
     *
     * @return string Trimmed; '' when the address carries none.
     *
     * @since  2.0.0
     */
    public function getName(): string
    {
        return trim((string) ($this->role(RoleRegistry::NAME)->value() ?? ''));
    }

    /**
     * The e-mail address on this address.
     *
     * @return string Trimmed; '' when the address carries none.
     *
     * @since  2.0.0
     */
    public function getEmail(): string
    {
        return trim((string) ($this->role(RoleRegistry::EMAIL)->value() ?? ''));
    }

    /**
     * A field by one of the product's own roles: name, email, phone, country.
     *
     * These four the core DOES know about, because it needs them itself: an
     * order cannot be confirmed without an email or delivered without a
     * recipient. Everything else is a plugin's business and goes through
     * {@see self::field()}.
     *
     * @param string $role A RoleRegistry role.
     *
     *
     * @since  2.0.0
     */
    public function role(string $role): FieldValue
    {
        // THE DESTINATION HAS ONE RESOLVER, AND THIS IS IT TOO. The country
        // role used to be looked up by ROLE here while the component looked the
        // same question up by the shop's mapped delivery FIELD, so a hand-made
        // country field, or two of them, could have the offer list filtered
        // against one and the cart API reporting another.
        $column = $role === RoleRegistry::COUNTRY
            ? (string) (FieldsHelper::deliveryPlaceField()->field_name ?? '')
            : (string) RoleRegistry::column($role);

        return $column === '' ? FieldValue::missing() : $this->column($column);
    }

    /**
     * Where this address is, as a place and everything containing it.
     *
     * A zone named for a country has to match an order going to a city inside
     * it, so the chain is the answer and the id never is. Empty when the
     * destination is unknown, which is a real answer, not a zero, and the
     * reason a place-restricted method cannot match it.
     *
     *     $places = $cart->delivery()->places();          // the shop's destination
     *     $places = $cart->delivery()->places($fieldId);  // a field a method names
     *
     * THE ARGUMENT IS A FIELD ID, NOT A PLACE ID. It says WHICH box on the
     * checkout form to read, and the place comes out of that box:
     *
     *   omitted, null, '' or 0   the shop's own destination field
     *   a field id, answered     that value's place and everything containing it
     *   a field id, unanswered   nothing, and never the shop's destination
     *
     * THE LAST ROW IS THE ONE THAT MATTERS. A method that names a field the
     * shopper has not filled, has cleared, or that the merchant has since
     * deleted gets an empty chain, because quietly reverting to the shop would
     * price a parcel against an address the merchant did not choose. Unknown
     * restricts: an empty chain admits only what names no place.
     *
     * AND ONLY SOME FIELDS HOLD A PLACE. A postcode is a string a carrier
     * compares against its bands and a telephone is a string a courier dials;
     * neither is passed here. Read those with {@see self::field()}, which is
     * the same accessor this uses and leaves the value as it found it.
     *
     * The expansion itself is the one shared helper: this decides which value
     * to expand and delegates the geography, so there is one place-tree walk in
     * the product and this is not a second one. The two steps stay available
     * separately for a caller that wants them:
     *
     *     $placeId = $cart->delivery()->field($fieldId)->int();
     *     $places  = $this->placesFor((int) $placeId);
     *
     * @param int|string|null $fieldId The checkout field to read the destination from;
     *                                 omitted or empty means the shop's own. A string is
     *                                 accepted because that is how a saved plugin setting
     *                                 arrives, and '' is how an unset one does.
     *
     * @return int[] Nearest first; empty when the destination is unknown.
     *
     * @since  2.0.0
     */
    public function places(int|string|null $fieldId = null): array
    {
        $fieldId = (int) $fieldId;

        $placeId = $fieldId > 0
            ? $this->field($fieldId)->int()
            : $this->role(RoleRegistry::COUNTRY)->int();

        return PlaceTree::placesFor((int) $placeId);
    }

    /**
     * Read one resolved column, pending value first.
     *
     * `array_key_exists` and not `isset`/`empty`: a field the shopper submitted
     * EMPTY is a submitted field, and its emptiness is the answer. Treating it
     * as absent is what would hand back the saved value they had just deleted.
     *
     * @param string $column The `#__alfa_user_info` column.
     *
     *
     * @since  2.0.0
     */
    private function column(string $column): FieldValue
    {
        if (\array_key_exists($column, $this->pending)) {
            $value = $this->pending[$column];

            return FieldValue::of(\is_scalar($value) ? (string) $value : null, $column);
        }

        if ($this->row === null || !property_exists($this->row, $column)) {
            return FieldValue::of(null, $column);
        }

        $value = $this->row->{$column};

        return FieldValue::of(\is_scalar($value) ? (string) $value : null, $column);
    }
}
