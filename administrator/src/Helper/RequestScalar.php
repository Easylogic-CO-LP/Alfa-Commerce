<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Filter\InputFilter;
use Joomla\Input\Input;

/**
 * One value of a request, read through Joomla's input filters, and never a list where one value is meant.
 *
 * Joomla's filters keep a list a list: getInt() of id[]=5 answers [5], and the typed call it is handed to raises a
 * TypeError whose message, on the storefront's error page, names the server path of the file (2.2.1). Every
 * storefront and REST read of a single value goes through here: a list is refused with a 400 and a neutral sentence
 * ({@see RequestValueRefused}) before any code receives it; an absent value is the default; a single value is what
 * Joomla's filter makes of it.
 *
 * @since  2.2.1
 */
final class RequestScalar
{
    /**
     * A whole number.
     *
     * @param Input $input The request input (or one of its sources: post, get, json).
     * @param string $name The variable.
     * @param int $default When it is absent.
     *
     * @throws RequestValueRefused When the request holds a list there.
     *
     * @since  2.2.1
     */
    public static function int(Input $input, string $name, int $default = 0): int
    {
        return (int) self::read(input: $input, name: $name, default: $default, filter: 'int');
    }

    /**
     * A text, cleaned of markup as Joomla's string filter cleans it.
     *
     * @param Input $input The request input (or one of its sources).
     * @param string $name The variable.
     * @param string $default When it is absent.
     *
     * @throws RequestValueRefused When the request holds a list there.
     *
     * @since  2.2.1
     */
    public static function string(Input $input, string $name, string $default = ''): string
    {
        return (string) self::read(input: $input, name: $name, default: $default, filter: 'string');
    }

    /**
     * A command word: letters, digits, dot, dash and underscore.
     *
     * @param Input $input The request input (or one of its sources).
     * @param string $name The variable.
     * @param string $default When it is absent.
     *
     * @throws RequestValueRefused When the request holds a list there.
     *
     * @since  2.2.1
     */
    public static function cmd(Input $input, string $name, string $default = ''): string
    {
        return (string) self::read(input: $input, name: $name, default: $default, filter: 'cmd');
    }

    /**
     * A yes or no.
     *
     * @param Input $input The request input (or one of its sources).
     * @param string $name The variable.
     * @param bool $default When it is absent.
     *
     * @throws RequestValueRefused When the request holds a list there.
     *
     * @since  2.2.1
     */
    public static function bool(Input $input, string $name, bool $default = false): bool
    {
        return (bool) self::read(input: $input, name: $name, default: $default, filter: 'bool');
    }

    /**
     * One value through the named Joomla filter ('raw' for none).
     *
     * @param Input $input The request input (or one of its sources).
     * @param string $name The variable.
     * @param mixed $default When it is absent.
     * @param string $filter A Joomla input filter.
     *
     * @throws RequestValueRefused When the request holds a list there.
     *
     * @since  2.2.1
     */
    public static function read(Input $input, string $name, mixed $default, string $filter): mixed
    {
        $raw = $input->get($name, null, 'raw');

        if ($raw === null) {
            return $default;
        }

        if (!\is_scalar($raw)) {
            throw new RequestValueRefused($name);
        }

        return $input->get($name, $default, $filter);
    }

    /**
     * One value of a list the request carries on purpose (filter[...], list[...]), through the named Joomla filter.
     *
     * @param array $values The list as read.
     * @param string $key The key.
     * @param string $filter A Joomla input filter.
     * @param string $name The list's name, for the refusal (for example "filter").
     *
     * @return mixed The filtered value, or null when the key is absent.
     *
     * @throws RequestValueRefused When the list holds a list there.
     *
     * @since  2.2.1
     */
    public static function member(array $values, string $key, string $filter, string $name): mixed
    {
        if (!\array_key_exists($key, $values) || $values[$key] === null) {
            return null;
        }

        if (!\is_scalar($values[$key])) {
            throw new RequestValueRefused($name . '[' . $key . ']');
        }

        return InputFilter::getInstance()->clean($values[$key], $filter);
    }
}
