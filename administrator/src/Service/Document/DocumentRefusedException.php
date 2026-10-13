<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use RuntimeException;
use Throwable;

/**
 * A document refusal that can also be told to the operator in plain words.
 *
 * The message stays the technical one: logs, every other caller and the suites
 * read it, so nothing it says is lost. The operator's sentence travels beside
 * it as a language key and its values, and is translated only where a screen
 * shows it. That screen escapes it, as it escapes every refusal: the values
 * are catalogue data.
 *
 * @since  2.0.0
 */
final class DocumentRefusedException extends RuntimeException
{
    /**
     * @param string $message The technical refusal, as logged.
     * @param string $key The operator sentence's language key.
     * @param array $values Its values, in placeholder order.
     * @param Throwable|null $previous The cause, if any.
     *
     * @since   2.0.0
     */
    public function __construct(
        string $message,
        private readonly string $key,
        private readonly array $values = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The first refusal in an exception's chain that carries operator words.
     *
     * Walked, not type-checked on the outer exception: the issuer wraps what
     * it refuses in its own "issue failed" exception.
     *
     * @param Throwable $e What was thrown.
     *
     *
     * @since   2.0.0
     */
    public static function in(Throwable $e): ?self
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof self) {
                return $current;
            }
        }

        return null;
    }

    /**
     * The operator's sentence, translated. Not escaped: the screen showing it does.
     *
     *
     * @since   2.0.0
     */
    public function operatorText(): string
    {
        return Text::sprintf($this->key, ...$this->values);
    }

    /**
     * The operator sentence's language key, untranslated.
     *
     *
     * @since   2.0.0
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * The sentence's values, in placeholder order.
     *
     *
     * @since   2.0.0
     */
    public function values(): array
    {
        return $this->values;
    }
}
