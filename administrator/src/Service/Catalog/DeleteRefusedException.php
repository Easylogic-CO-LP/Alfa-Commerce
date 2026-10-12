<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * A delete a model refused by its own rule, with the sentence that says why.
 *
 * Set as the model's error: the back office prints the message, and the REST
 * delete answers 409 with the plain sentence instead of a bare 500 or a 204
 * for a row that is still there.
 *
 * @since  1.14.0
 */
class DeleteRefusedException extends RuntimeException
{
    /**
     * The sentence as text, without the HTML escaping the message carries for
     * the back office, which prints messages as HTML.
     *
     * @since 1.14.0
     */
    public readonly string $plain;

    /**
     * @param string $message The sentence, safe to print as HTML.
     * @param string|null $plain The same sentence as text; null when the message holds no markup to escape.
     *
     * @since   1.14.0
     */
    public function __construct(string $message, ?string $plain = null)
    {
        parent::__construct($message);
        $this->plain = $plain ?? $message;
    }
}
