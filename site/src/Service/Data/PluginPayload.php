<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Data;

use JsonException;

defined('_JEXEC') or die;

/**
 * A stored plugin payload, read.
 *
 * ONE READER FOR EVERY OPAQUE JSON COLUMN THIS SCHEMA KEEPS. Three tables hold
 * one: `#__alfa_order_shipments.plugin_data` (a carrier's operational state),
 * `#__alfa_order_payments.plugin_data` (a gateway's), and
 * `#__alfa_order_item_fields.payload` / `#__alfa_cart_item_fields.payload` (what
 * a field plugin recorded about what the customer configured). They are read
 * through this one class so a developer learns the accessor once instead of the
 * column name three times, and so nobody hand-rolls json_decode again: every
 * hand-rolled one wrote `?: []`, which turns a truncated column into an empty
 * payload and a booked parcel into an unbooked one.
 *
 * READ-ONLY, ON PURPOSE. There is no set(), no save() and no persistence of any
 * kind here. Writing goes through the ledger that owns the row:
 * `->pluginData([...])` and `->removePluginData([...])`, which is what makes
 * merging, validation and locking the persistence layer's business rather than
 * every caller's. It is also what lets an ORDER ITEM FIELD be read with the same
 * class it is snapshotted with: a purchase-time record must never grow a
 * mutation path, and it cannot, because this class has none.
 *
 *     $data = $shipment->pluginData();
 *
 *     $data->has('parcel_id')                              // stored, even as null
 *     $data->getString(key: 'parcel_id', default: '')      // '0077123456', bytes intact
 *     $data->getInt(key: 'installments', default: 1)
 *     $data->getBool(key: 'cancelled', default: false)
 *     $data->get(key: 'events', default: [], filter: 'array')
 *     $data->get(key: 'custom_data')                       // as decoded, unconverted
 *     $data->toArray()                                     // the whole thing
 *
 * THE GETTERS RETURN VALUES, NOT WRAPPERS. There is no ->get(...)->int(): a
 * caller reading a payload wants the number, and one more object between them
 * and it is one more thing to learn for nothing. Conversion is named in the
 * call (getInt(), or get(filter: 'int')) and performed by {@see ValueCast},
 * the same checked conversion an address read goes through.
 *
 * NULL AND ABSENT ARE DIFFERENT, and the difference survives storage. A key
 * written as null is IN the payload: `has()` is true, `value()` is null, and a
 * typed accessor answers with its default. A key nobody wrote is not there:
 * `has()` is false. Clearing a key is a separate operation, `removePluginData()`,
 * precisely so "the carrier told us nothing this time" and "there is no such
 * fact any more" stay distinguishable.
 *
 * @since  1.10.0
 */
final class PluginPayload
{
    /**
     * @param array $data The decoded payload.
     * @param string $source Where it came from, for error messages.
     *
     * @since  1.10.0
     */
    private function __construct(
        private readonly array $data,
        private readonly string $source,
    ) {
    }

    /**
     * Read a payload out of a stored column.
     *
     * AN EMPTY COLUMN IS AN EMPTY PAYLOAD; A BROKEN ONE IS AN ERROR. NULL, ''
     * and a bare 'null' all mean "nothing has been written here yet", which is
     * the ordinary state of a row before its plugin first speaks. Anything else
     * that will not decode to an array (truncated JSON, a stray scalar, a
     * column somebody edited by hand) throws, because carrying on as though
     * the row were blank is how a booked parcel comes to look unbooked.
     *
     * @param string|null $json The column's contents.
     * @param string $source What row this is, for the error message.
     *
     *
     * @throws ValueException When the column holds something that is not a payload.
     *
     * @since  1.10.0
     */
    public static function of(?string $json, string $source = ''): self
    {
        $raw = trim((string) $json);

        if ($raw === '' || strtolower($raw) === 'null') {
            return new self([], $source);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ValueException(sprintf(
                '%s: the stored payload is not valid JSON (%s).',
                $source !== '' ? $source : 'payload',
                $e->getMessage(),
            ), 0, $e);
        }

        if (!\is_array($decoded)) {
            throw new ValueException(sprintf(
                '%s: the stored payload decodes to %s, not to a set of keys.',
                $source !== '' ? $source : 'payload',
                \gettype($decoded),
            ));
        }

        return new self($decoded, $source);
    }

    /**
     * Read a payload that is already decoded.
     *
     * For the callers that are handed an array rather than a column: a
     * snapshot assembled in memory, a test. Same reader, same rules; it simply
     * has no decoding left to do.
     *
     * @param array $data The payload.
     * @param string $source What this is, for error messages.
     *
     *
     * @since  1.10.0
     */
    public static function fromArray(array $data, string $source = ''): self
    {
        return new self($data, $source);
    }

    /**
     * Whether the payload carries this key AT ALL, a stored null included.
     *
     * @param string $key The key.
     *
     *
     * @since  1.10.0
     */
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /**
     * One value, as stored, or converted, when you say how.
     *
     *     $data->get(key: 'custom_data')                          as decoded
     *     $data->get(key: 'installments', default: 1)             1 when absent
     *     $data->get(key: 'installments', default: 1, filter: 'int')
     *
     * Without a filter the value comes back exactly as JSON gave it: a string
     * stays a string, an array stays an array. With one it goes through the
     * component's single checked conversion ({@see ValueCast}), which converts
     * or throws, and never guesses.
     *
     * @param string $key The key.
     * @param mixed $default Returned when the key is absent or holds null.
     * @param string|null $filter One of ValueCast::FILTERS, or null for the value as stored.
     *
     *
     * @throws ValueException When a present value will not convert.
     *
     * @since  1.10.0
     */
    public function get(string $key, mixed $default = null, ?string $filter = null): mixed
    {
        return ValueCast::apply($this->raw($key), $filter, $default, $this->describe($key));
    }

    /**
     * One value as a whole number.
     *
     *     $data->getInt(key: 'installments', default: 1)
     *
     * @param string $key The key.
     * @param int|null $default Returned when the key is absent or holds null.
     *
     *
     * @throws ValueException When a present value is not a whole number.
     *
     * @since  1.10.0
     */
    public function getInt(string $key, ?int $default = null): ?int
    {
        return ValueCast::toInt($this->raw($key), $default, $this->describe($key));
    }

    /**
     * One value as a string, with its bytes intact.
     *
     *     $data->getString(key: 'parcel_id', default: '')
     *
     * @param string $key The key.
     * @param string|null $default Returned when the key is absent or holds null.
     *
     *
     * @throws ValueException When a present value will not convert.
     *
     * @since  1.10.0
     */
    public function getString(string $key, ?string $default = null): ?string
    {
        return ValueCast::toString($this->raw($key), $default, $this->describe($key));
    }

    /**
     * One value as a boolean, from the spellings {@see ValueCast::toBool()} accepts.
     *
     *     $data->getBool(key: 'enabled', default: false)
     *
     * @param string $key The key.
     * @param bool|null $default Returned when the key is absent or holds null.
     *
     *
     * @throws ValueException When a present value is not one of the spellings.
     *
     * @since  1.10.0
     */
    public function getBool(string $key, ?bool $default = null): ?bool
    {
        return ValueCast::toBool($this->raw($key), $default, $this->describe($key));
    }

    /**
     * The value behind a key, before any conversion.
     *
     * @param string $key The key.
     *
     * @return mixed Null for a key that is absent AND for one stored as null;
     *               {@see self::has()} is what tells those apart.
     *
     * @since  1.10.0
     */
    private function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * What to call this key in an error message.
     *
     * @param string $key The key.
     *
     *
     * @since  1.10.0
     */
    private function describe(string $key): string
    {
        return ($this->source !== '' ? $this->source : 'payload') . ' "' . $key . '"';
    }

    /**
     * Every key the payload carries.
     *
     * @return string[]
     *
     * @since  1.10.0
     */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->data));
    }

    /**
     * Whether anything has been written here at all.
     *
     *
     * @since  1.10.0
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * The whole payload, as decoded.
     *
     * For the callers that hand the structure on whole: a layout that renders
     * a configurator scene, a view that dumps the carrier's state for an
     * operator. Reading one value is what {@see self::get()} is for.
     *
     *
     * @since  1.10.0
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
