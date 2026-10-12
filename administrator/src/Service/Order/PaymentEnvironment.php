<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\Input\Input;
use Joomla\Registry\Registry;

/**
 * The two credential sets of a payment method, and the one switch between them.
 *
 * Every gateway method stores both sets side by side as flat, prefixed keys
 * (`sandbox_<key>` and `live_<key>`) and one Production switch picks the set a
 * NEW payment uses. Anything that follows an existing payment or stored
 * reference uses the set that payment recorded, never the switch.
 *
 * This is the only reader of the switch and of the prefixed keys: gateway code
 * reads them through its MethodProfile, which reads them here.
 *
 * @since  1.14.0
 */
final class PaymentEnvironment
{
    /** The test set. @since 1.14.0 */
    public const SANDBOX = 'sandbox';

    /** The set that moves real money. @since 1.14.0 */
    public const LIVE = 'live';

    /** The params key of the Production switch. @since 1.14.0 */
    public const SWITCH = 'is_production';

    /** The query parameter every notification address carries to name its set. @since 1.14.0 */
    public const ADDRESS_PARAM = 'environment';

    /**
     * The set a new payment uses.
     *
     * Live only when the saved switch is exactly 1 or '1'; absent, 0 or any
     * other value reads as sandbox, so nothing moves real money by accident.
     *
     * @param array|object|string|null $params The method's params: an array, an object or a JSON string.
     *
     * @return string self::SANDBOX or self::LIVE.
     *
     * @since  1.14.0
     */
    public static function active(array|object|string|null $params): string
    {
        $switch = self::read(params: $params)[self::SWITCH] ?? null;

        return $switch === 1 || $switch === '1' ? self::LIVE : self::SANDBOX;
    }

    /**
     * Whether a new payment uses the live set.
     *
     * @param array|object|string|null $params The method's params.
     *
     *
     * @since  1.14.0
     */
    public static function isProduction(array|object|string|null $params): bool
    {
        return self::active(params: $params) === self::LIVE;
    }

    /**
     * The params key of one credential in one set: key('live', 'api_key') is 'live_api_key'.
     *
     * @param string $environment self::SANDBOX or self::LIVE.
     * @param string $key The unprefixed credential key.
     *
     *
     * @throws InvalidArgumentException On an unknown environment or an empty key.
     *
     * @since  1.14.0
     */
    public static function key(string $environment, string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('A credential key cannot be empty.');
        }

        return self::assert(environment: $environment) . '_' . $key;
    }

    /**
     * ONE set, unprefixed and in the order of $keys: ['merchant_id' => '...', ...].
     *
     * An absent or non-scalar value reads as ''. The other set is never read,
     * so an empty live key stays empty, and nothing is trimmed: the form's
     * filter already did that when the admin saved.
     *
     * @param array|object|string|null $params The method's params.
     * @param string $environment self::SANDBOX or self::LIVE.
     * @param string[] $keys Unprefixed credential keys.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException On an unknown environment or an empty key.
     *
     * @since  1.14.0
     */
    public static function credentials(array|object|string|null $params, string $environment, array $keys): array
    {
        $data = self::read(params: $params);
        $set = [];

        foreach ($keys as $key) {
            $value = $data[self::key(environment: $environment, key: (string) $key)] ?? null;
            $set[$key] = \is_scalar($value) ? (string) $value : '';
        }

        return $set;
    }

    /**
     * A recorded environment, checked: the set a payment or reference was made in.
     *
     * @param string $environment The recorded value.
     *
     * @return string The same value.
     *
     * @throws InvalidArgumentException When it is not self::SANDBOX or self::LIVE.
     *
     * @since  1.14.0
     */
    public static function assert(string $environment): string
    {
        if ($environment !== self::SANDBOX && $environment !== self::LIVE) {
            throw new InvalidArgumentException('A payment environment is sandbox or live, not ' . json_encode($environment, JSON_INVALID_UTF8_SUBSTITUTE) . '.');
        }

        return $environment;
    }

    /**
     * The set a notification address names.
     *
     * The value is compared exactly: an address without it, or with anything
     * but sandbox or live, names no set, and the handler refuses it rather
     * than trying the switch or both sets.
     *
     * @param Input $input The request.
     *
     * @return string|null self::SANDBOX, self::LIVE, or null.
     *
     * @since  1.14.0
     */
    public static function fromAddress(Input $input): ?string
    {
        $named = $input->get(self::ADDRESS_PARAM, null, 'raw');

        return $named === self::SANDBOX || $named === self::LIVE ? $named : null;
    }

    /**
     * The top-level params as an array, read once.
     *
     * @param array|object|string|null $params The method's params.
     *
     *
     * @since  1.14.0
     */
    private static function read(array|object|string|null $params): array
    {
        return \is_array($params) ? $params : (new Registry($params))->toArray();
    }
}
