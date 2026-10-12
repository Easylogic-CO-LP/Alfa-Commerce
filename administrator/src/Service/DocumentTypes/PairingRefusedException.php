<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\DocumentTypes;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * A pairing the registry's rule refuses: what a credit type corrects.
 *
 * The message is the package author's, naming aliases, as a provider sync
 * reports it. The reason and the named types let a screen say the same in
 * the merchant's words.
 *
 * @since  1.14.0
 */
final class PairingRefusedException extends RuntimeException
{
    /** @var string The type is not a credit type, so it corrects nothing. @since 1.14.0 */
    public const NOT_CREDIT = 'not_credit';

    /** @var string A named type is not a registered sales type. @since 1.14.0 */
    public const NOT_SALES = 'not_sales';

    /** @var string The pairing does not fit its column. @since 1.14.0 */
    public const TOO_LONG = 'too_long';

    /**
     * @param string $reason One of the constants above.
     * @param string[] $named The values the refusal is about (the not-sales ones).
     * @param string $message The package author's message.
     *
     * @since   1.14.0
     */
    public function __construct(public readonly string $reason, public readonly array $named, string $message)
    {
        parent::__construct($message);
    }
}
