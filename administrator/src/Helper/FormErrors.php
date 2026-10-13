<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use RuntimeException;
use Throwable;
use UnexpectedValueException;

defined('_JEXEC') or die;

/**
 * Telling a rejected field from a broken database.
 *
 * A model's error bag holds two unrelated things and the caller usually cannot
 * see the difference:
 *
 *   WHAT THE FORM DECIDED. Joomla CONSTRUCTS these: `new \RuntimeException` for
 *   a required field left empty (FormField::validate), `new
 *   \UnexpectedValueException` for a rule that reported invalid. They carry a
 *   JLIB_FORM_VALIDATE_* sentence naming the field, and the person filling the
 *   form has to keep seeing them or they cannot fix anything.
 *
 *   WHAT WENT WRONG UNDERNEATH. A validation rule that THROWS has its exception
 *   returned UNCHANGED: FormField::validate() catches \Exception and does
 *   `return $e`. So a rule that queries the database and fails arrives in the
 *   same array, wearing its own class and carrying the driver's words.
 *
 * THE TEST IS EXACT CLASS, NOT instanceof. mysqli_sql_exception and
 * ExecutionFailureException both extend \RuntimeException; a subclass is, by
 * construction, not something Joomla built as a validation message. Nothing here
 * reads the text of an exception to decide what it is.
 *
 * @since  2.0.0
 */
final class FormErrors
{
    /**
     * The exception classes Joomla itself constructs for a rejected field.
     *
     * @since  2.0.0
     */
    private const VALIDATION_CLASSES = [RuntimeException::class, UnexpectedValueException::class];

    /**
     * Split an error bag into what the person may read and what only the log may.
     *
     * @param array $errors A model's getErrors(): strings and/or Throwables.
     *
     * @return array{messages: string[], failures: Throwable[]}
     *
     * @since  2.0.0
     */
    public static function partition(array $errors): array
    {
        $messages = [];
        $failures = [];

        foreach ($errors as $error) {
            if (!$error instanceof Throwable) {
                $messages[] = (string) $error;

                continue;
            }

            if (\in_array($error::class, self::VALIDATION_CLASSES, true)) {
                $messages[] = $error->getMessage();

                continue;
            }

            $failures[] = $error;
        }

        return ['messages' => $messages, 'failures' => $failures];
    }

    /**
     * One line describing everything that broke, for a log and nowhere else.
     *
     * @param Throwable[] $failures From partition().
     *
     *
     * @since  2.0.0
     */
    public static function detail(array $failures): string
    {
        return implode('; ', array_map(
            static fn (Throwable $e): string => $e::class . ': ' . $e->getMessage(),
            $failures,
        ));
    }
}
