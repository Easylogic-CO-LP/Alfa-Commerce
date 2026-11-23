<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service;

\defined('_JEXEC') or die;

/**
 * Why a write was refused, as a value: a stable code, the sentence staff and API clients read, where in the request it
 * points (a JSON pointer, /lines/0/sku; empty for the request as a whole) and the HTTP status an API answers it with
 * (409 when the shop's data stands in the way, 422 when the request itself is wrong, 404 for a record the caller may
 * not see). A service returns its refusals; the administrator's screens show the sentence, the REST API answers the
 * status with the sentence and the pointer.
 *
 * @since  2.3.0
 */
final class Refusal
{
    /**
     * The shop's data stands in the way: a SKU in use, a figure below what is reserved.
     *
     * @since  2.3.0
     */
    public const CONFLICT = 409;

    /**
     * The request is wrong: a value missing, malformed or naming nothing.
     *
     * @since  2.3.0
     */
    public const INVALID = 422;

    /**
     * A record the caller may not see, or that does not exist.
     *
     * @since  2.3.0
     */
    public const NOT_FOUND = 404;

    /**
     * @param string $code The stable code (sku_in_use, reserved_floor).
     * @param string $sentence What staff and the API client read.
     * @param string $pointer Where in the request: a JSON pointer, or empty for the request as a whole.
     * @param int $status The HTTP status an API answers it with.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly string $code,
        public readonly string $sentence,
        public readonly string $pointer = '',
        public readonly int $status = self::INVALID,
    ) {
    }

    /**
     * The same refusal at another place in the request.
     *
     * @param string $pointer The JSON pointer.
     *
     * @since  2.3.0
     */
    public function at(string $pointer): self
    {
        return new self(code: $this->code, sentence: $this->sentence, pointer: $pointer, status: $this->status);
    }

    /**
     * The sentence led by its place in the request, as an API answer reads it: "lines[1]: ...".
     *
     * @since  2.3.0
     */
    public function placed(): string
    {
        if ($this->pointer === '') {
            return $this->sentence;
        }

        $parts = explode('/', ltrim($this->pointer, '/'));
        $place = (string) array_shift($parts);

        foreach ($parts as $part) {
            $place .= ctype_digit($part) ? '[' . $part . ']' : '.' . $part;
        }

        return $place . ': ' . $this->sentence;
    }
}
