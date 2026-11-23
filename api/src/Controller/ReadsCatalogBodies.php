<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Language\Text;

/**
 * The bodies of an item field's value, a product's option and a product's combination read into the names the catalogue
 * services take, the same way on their own routes and inside a product's write: each part's fields, the refusals of
 * what cannot be read (with their JSON pointers) and the warnings of the keys a part does not take. Uses
 * {@see AnswersFromServices}.
 *
 * @since  2.3.0
 */
trait ReadsCatalogBodies
{
    /**
     * A value's body: code, state, ordering, params and the translations object (the name), in the Values tab's names.
     *
     * @param array $body The value's body.
     * @param string $pointer Where it stands in the request.
     * @param list<string> $alsoTaken Keys the caller reads itself.
     *
     * @return array{0: array<string, mixed>, 1: list<Refusal>, 2: list<array<string, string>>}
     *
     * @since  2.3.0
     */
    protected function valueFields(array $body, string $pointer = '', array $alsoTaken = []): array
    {
        $warnings = $this->unknownKeys(body: $body, keys: array_merge(['code', 'state', 'ordering', 'params', 'translations'], $alsoTaken), what: 'a value', pointer: $pointer);
        $fields = [];
        $refusals = [];

        foreach (['code', 'state', 'ordering'] as $key) {
            if (\array_key_exists($key, $body)) {
                if ($body[$key] !== null && !\is_scalar($body[$key])) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send a value, not a list or an object.', pointer: $pointer . '/' . $key);
                } else {
                    $fields[$key] = $body[$key] === null ? '' : (string) $body[$key];
                }
            }
        }

        if (\array_key_exists('params', $body)) {
            if (!\is_array($body['params']) || ($body['params'] !== [] && array_is_list($body['params']))) {
                $refusals[] = new Refusal(code: 'invalid', sentence: Text::_('COM_ALFA_ITEMFIELD_VALUE_PARAMS_INVALID'), pointer: $pointer . '/params');
            } else {
                $fields['valueparams'] = $body['params'];
            }
        }

        if (\array_key_exists('translations', $body)) {
            [$texts, $textRefusals, $textWarnings] = $this->translationFields(translations: $body['translations'], texts: ['name'], pointer: $pointer . '/translations');
            $fields += $texts;
            array_push($refusals, ...$textRefusals);
            array_push($warnings, ...$textWarnings);
        }

        return [$fields, $refusals, $warnings];
    }

    /**
     * An option's price change: operation, amount and apply_on as sent (null for one not sent or sent null).
     *
     * @param array $body The option's body.
     * @param string $pointer Where it stands in the request.
     *
     * @return array{0: array<string, ?string>, 1: list<Refusal>} The parts sent, and the refusals.
     *
     * @since  2.3.0
     */
    protected function optionDelta(array $body, string $pointer = ''): array
    {
        $delta = [];
        $refusals = [];

        foreach (['operation', 'amount', 'apply_on'] as $key) {
            if (\array_key_exists($key, $body)) {
                if ($body[$key] !== null && !\is_string($body[$key]) && !\is_int($body[$key]) && !\is_float($body[$key])) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send text, or null for none.', pointer: $pointer . '/' . $key);
                } else {
                    $delta[$key] = $body[$key] === null || $body[$key] === '' ? null : (string) $body[$key];
                }
            }
        }

        return [$delta, $refusals];
    }

    /**
     * A combination's fields in the variant window's names (sku, gtin, mpn, state, manage_stock, default, the overrides
     * and the names) and its price rows (null when the body sends none).
     *
     * @param array $body The combination's body.
     * @param string $pointer Where it stands in the request.
     *
     * @return array{0: array<string, mixed>, 1: list<array>|null, 2: list<Refusal>, 3: list<array<string, string>>}
     *
     * @since  2.3.0
     */
    protected function combinationFields(array $body, string $pointer = ''): array
    {
        $fields = [];
        $refusals = [];
        $warnings = [];

        foreach (['sku', 'gtin', 'mpn'] as $key) {
            if (\array_key_exists($key, $body)) {
                if (!\is_string($body[$key]) || mb_strlen($body[$key]) > 255 || preg_match('/[\x00-\x1F\x7F]/', $body[$key]) === 1) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send text of at most 255 characters, without control characters.', pointer: $pointer . '/' . $key);
                } else {
                    $fields[$key] = trim($body[$key]);
                }
            }
        }

        foreach (['state' => [0, 1], 'manage_stock' => [0, 1, null]] as $key => $allowed) {
            if (\array_key_exists($key, $body)) {
                if (!\in_array($body[$key], $allowed, true)) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send ' . implode(' or ', array_map(static fn ($v): string => json_encode($v), $allowed)) . '.', pointer: $pointer . '/' . $key);
                } else {
                    $fields[$key] = $body[$key] === null ? '' : (string) $body[$key];
                }
            }
        }

        if (\array_key_exists('default', $body)) {
            if ($body['default'] !== true) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send true to make this the combination the product opens on; another one is made so by its own write.', pointer: $pointer . '/default');
            } else {
                $fields['default'] = true;
            }
        }

        foreach ((array) ($body['overrides'] ?? []) as $key => $value) {
            if (!\array_key_exists((string) $key, ItemFieldsAdminHelper::COMBINATION_OVERRIDE_CASTS) || $key === 'manage_stock') {
                $warnings[] = ['code' => 'not_a_field', 'title' => 'overrides.' . $key . ' is not an override of a combination; it was left out.', 'pointer' => $pointer . '/overrides/' . $key];
            } elseif ($value !== null && !\is_scalar($value)) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send a value, or null to inherit the product\'s.', pointer: $pointer . '/overrides/' . $key);
            } else {
                $fields[(string) $key] = $value === null ? '' : (string) $value;
            }
        }

        if (\array_key_exists('translations', $body)) {
            [$texts, $textRefusals, $textWarnings] = $this->translationFields(translations: $body['translations'], texts: ['name'], pointer: $pointer . '/translations');
            $fields += $texts;
            array_push($refusals, ...$textRefusals);
            array_push($warnings, ...$textWarnings);
        }

        $prices = null;

        if (\array_key_exists('prices', $body)) {
            if (!\is_array($body['prices']) || !array_is_list($body['prices'])) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send a list of price rows, [] for none.', pointer: $pointer . '/prices');
            } else {
                $prices = array_map(static function ($row): array {
                    $row = (array) $row;
                    $mode = ($row['mode'] ?? 'price') === 'adjust' ? 'adjust' : 'price';

                    return ['_mode' => $mode, 'price_status' => (string) ($row['state'] ?? '1'),
                        $mode === 'adjust' ? 'adjust_target' : 'override_type' => $row['override_type'] ?? 'start'] + $row;
                }, $body['prices']);
            }
        }

        return [$fields, $prices, $refusals, $warnings];
    }

    /**
     * Stock lines, [{"location_id": 1, "quantity": "5"}], each a location and its figure, in the order sent.
     *
     * @param mixed $lines The lines sent.
     * @param string $pointer Where they stand in the request.
     *
     * @return array{0: list<array{location_id: int, quantity: string}>, 1: list<Refusal>}
     *
     * @since  2.3.0
     */
    protected function stockFigures(mixed $lines, string $pointer): array
    {
        $figures = [];
        $refusals = [];

        foreach (\is_array($lines) ? $lines : [$lines] as $n => $line) {
            $location = \is_array($line) ? (int) ($line['location_id'] ?? 0) : 0;
            $quantity = \is_array($line) ? ($line['quantity'] ?? null) : null;

            if ($location <= 0 || !(\is_string($quantity) || \is_int($quantity) || \is_float($quantity)) || !is_numeric((string) $quantity)) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send {"location_id": 1, "quantity": "5"}.', pointer: $pointer . '/' . $n);

                continue;
            }

            $figures[] = ['location_id' => $location, 'quantity' => (string) $quantity];
        }

        return [$figures, $refusals];
    }
}
